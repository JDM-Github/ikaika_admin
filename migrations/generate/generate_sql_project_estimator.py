#!/usr/bin/env python3
"""
generate_sql_project_estimator.py -- Transforms a raw Airtable export (produced
by fetch_airtable.py) into a data.sql file that loads into
sql/project_estimator/schema.sql (the MySQL "Project Estimator" schema,
database test_estimator_database).

--------------------------------------------------------------------------
USAGE
--------------------------------------------------------------------------
  # 1. First run fetch_airtable.py to produce the raw export:
  python fetch_airtable.py --base-id appgh0Mki2uHDLAQY --output-dir ./export

  # 2. Then transform it into SQL:
  python generate_sql_project_estimator.py --export-dir ./export --output data.sql

  Or just run migrate.ps1 -Target project-estimator -Produce both.

--------------------------------------------------------------------------
FIELD MAPPING
--------------------------------------------------------------------------
Every column below is pulled by the Airtable field's display NAME, verified
against a real export's export_dir/_base_schema.json (the public API keys
fields by name, not by field ID). If a field gets renamed in the Airtable UI,
re-run inspect_schema.py against a fresh export and update the COLUMNS list
for the affected table below. verify_migration.py catches the silent case
(a renamed field starts landing as NULL everywhere).

Notes specific to this base:
- Employees lives in the Airtable table "Grid view - Active Status"
  (tbltDzOjQKkh7IsQf) -- the export file on disk is
  "Grid view - Active Status.json", and the table() call below must match it.
- "Earn Codes" (tbl4UQN80qdFBWZUt) and "Grid view 2" (tblFPgnet8MP7mwBQ) are
  two genuinely different tables (see schema.sql design note #2). Holidays
  links into Grid view 2, so it generates earn_codes_v2.
- Projects "Label" (links to User Reports) has no junction in the checked-in
  schema -- dropped, as are Projects' createdBy/lastModifiedBy links.
- The Projects <-> Activity Codes relationship is read from the Activity
  Codes side ("Project/s"), which is what activity_codes_projects holds.
- Employees "Zip Code" has no column in the checked-in schema -- dropped.
- Project Estimates "Attachment Summary" (aiText) has no column in the
  checked-in schema -- dropped.

--------------------------------------------------------------------------
VALUE CONVERSIONS
--------------------------------------------------------------------------
Same conventions as generate_sql_users_projects.py: percent fields are
multiplied by 100 to match the "_pct" columns, unchecked checkboxes become
FALSE rather than NULL, dateTime values are rewritten from '...T...Z' to
'... ...' for MySQL, backslashes are doubled, and quotes are escaped.
"""

import argparse
import json
import re
from pathlib import Path


# ============================================================================
# Value helpers -- one per Airtable field type that needs normalizing
# ============================================================================

def esc(v):
    if v is None:
        return "NULL"
    if isinstance(v, bool):
        return "TRUE" if v else "FALSE"
    if isinstance(v, (int, float)):
        return str(v)
    s = str(v).replace("\\", "\\\\").replace("'", "''")
    return f"'{s}'"


def sel(v):
    # singleSelect: the public API returns a plain string, not {"name": ...}
    if isinstance(v, str):
        return v
    if isinstance(v, dict):
        return v.get("name")
    return None


def num(v):
    return v if isinstance(v, (int, float)) else None


def pct(v):
    # percent fields come back as a 0-1 fraction -- match the "_pct" columns' 0-100 convention
    if not isinstance(v, (int, float)):
        return None
    return round(v * 100, 2)


def chk(f, name):
    # unchecked checkboxes are simply absent from the record -- that means False, not NULL
    return bool(f.get(name, False))


def dt(v):
    # dateTime/createdTime/lastModifiedTime: '...T...Z' -> MySQL-safe '... ...'
    if not isinstance(v, str):
        return None
    if "T" in v:
        return v.split(".")[0].replace("T", " ").rstrip("Z")
    return v


def collab_email(v):
    return v.get("email") if isinstance(v, dict) else None


def ai_text(v):
    # aiText fields can come back as an {"state": "error", ...} object when the AI generation failed
    return v if isinstance(v, str) else None


def snake(name):
    return re.sub(r"[^a-z0-9]+", "_", name.lower()).strip("_")


# Column extractor factories -- each takes an Airtable field display name and
# returns fn(fields_dict) -> sql value, used to build the per-table COLUMNS lists.
def text(name):
    return lambda f: f.get(name)


def select(name):
    return lambda f: sel(f.get(name))


def number(name):
    return lambda f: num(f.get(name))


def percent(name):
    return lambda f: pct(f.get(name))


def checkbox(name):
    return lambda f: chk(f, name)


def when(name):
    return lambda f: dt(f.get(name))


def collaborator(name):
    return lambda f: collab_email(f.get(name))


def ai(name):
    return lambda f: ai_text(f.get(name))


# ============================================================================
# Export loading
# ============================================================================

def load_table(export_dir, table_name):
    path = export_dir / f"{table_name}.json"
    if not path.exists():
        print(f"  WARNING: {path} not found, skipping this table")
        return []
    with open(path, encoding="utf-8") as f:
        data = json.load(f)
    return data["records"]


def load_manifest(export_dir):
    path = export_dir / "attachments_manifest.json"
    if not path.exists():
        return {}
    with open(path, encoding="utf-8") as f:
        entries = json.load(f)
    idx = {}
    for e in entries:
        idx.setdefault((e["record_id"], e["field"]), []).append(e)
    return idx


# ============================================================================
# Generic table + junction builders
# ============================================================================

def gen_table(export_dir, airtable_table, columns):
    """columns: list of (sql_column_name, extractor) where extractor(fields) -> value."""
    records = load_table(export_dir, airtable_table)
    id_map = {}
    cols = ["airtable_record_id"] + [c for c, _ in columns]
    rows = []
    for i, r in enumerate(records, start=1):
        id_map[r["id"]] = i
        f = r.get("fields", {})
        rows.append([r["id"]] + [extractor(f) for _, extractor in columns])
    return records, id_map, cols, rows


def gen_junction(records, id_map_self, field_name, id_map_target):
    """Resolves a multipleRecordLinks field into (self_id, target_id) pairs, de-duplicated."""
    seen = set()
    rows = []
    for r in records:
        self_id = id_map_self.get(r["id"])
        if self_id is None:
            continue
        for target_rec_id in (r.get("fields", {}).get(field_name) or []):
            target_id = id_map_target.get(target_rec_id)
            if target_id is None:
                continue
            pair = (self_id, target_id)
            if pair not in seen:
                seen.add(pair)
                rows.append(list(pair))
    return rows


def swap(pairs):
    return [[b, a] for a, b in pairs]


def gen_employees_projects(project_records, employees_id_map, projects_id_map):
    """employees_projects carries a role, sourced from three separate link fields on Projects."""
    role_fields = [("PM ID", "pm"), ("Project Members", "member"), ("Support Members", "support")]
    seen = set()
    rows = []
    for r in project_records:
        project_id = projects_id_map.get(r["id"])
        if project_id is None:
            continue
        f = r.get("fields", {})
        for field_name, role in role_fields:
            for emp_rec_id in (f.get(field_name) or []):
                employee_id = employees_id_map.get(emp_rec_id)
                if employee_id is None:
                    continue
                key = (employee_id, project_id, role)
                if key not in seen:
                    seen.add(key)
                    rows.append([employee_id, project_id, role])
    return rows


def gen_holiday_earn_codes(holiday_records, holidays_id_map, earn_v2_id_map):
    """holidays_earn_codes_v2 carries a variant: the four separate link fields on Holidays."""
    variant_fields = [
        ("Earn Code", "base"),
        ("Earn Code OT", "ot"),
        ("Earn Code ND", "nd"),
        ("Earn Code ND + OT", "nd_ot"),
    ]
    seen = set()
    rows = []
    for r in holiday_records:
        holiday_id = holidays_id_map.get(r["id"])
        if holiday_id is None:
            continue
        f = r.get("fields", {})
        for field_name, variant in variant_fields:
            for earn_rec_id in (f.get(field_name) or []):
                earn_id = earn_v2_id_map.get(earn_rec_id)
                if earn_id is None:
                    continue
                key = (holiday_id, earn_id, variant)
                if key not in seen:
                    seen.add(key)
                    rows.append([holiday_id, earn_id, variant])
    return rows


ATTACHMENT_COLUMNS = ["table_name", "record_id", "field_name", "file_url", "file_name",
                       "file_size_bytes", "mime_type"]


def resolve_file_url(att, record_id, field_name, manifest, cloudinary_cache):
    """Prefers a real Cloudinary URL (from upload_attachments_to_cloudinary.py's cache,
    keyed by Airtable's stable attachment id) and falls back to the local downloaded
    path so the generator still works standalone without a Cloudinary pass."""
    att_id = att.get("id")
    cached = cloudinary_cache.get(att_id) if att_id else None
    if cached:
        return cached["secure_url"]
    local_entries = manifest.get((record_id, field_name), [])
    match = next((e for e in local_entries if e["filename"] == att.get("filename")), None)
    return match["local_path"].replace("\\", "/") if match else None


def gen_attachments_for_table(records, table_sql_name, id_map, manifest, cloudinary_cache, attachment_field_names):
    rows = []
    for r in records:
        sid = id_map.get(r["id"])
        if sid is None:
            continue
        f = r.get("fields", {})
        for field_name in attachment_field_names:
            atts = f.get(field_name)
            if not isinstance(atts, list):
                continue
            for att in atts:
                file_url = resolve_file_url(att, r["id"], field_name, manifest, cloudinary_cache)
                rows.append([
                    table_sql_name, sid, snake(field_name),
                    file_url, att.get("filename"), att.get("size"), att.get("type"),
                ])
    return rows


def write_insert(out, table, cols, rows, chunk=500):
    if not rows:
        return
    out.append(f"-- {table} ({len(rows)} rows)")
    for start in range(0, len(rows), chunk):
        batch = rows[start:start + chunk]
        out.append(f"INSERT INTO {table} ({', '.join(cols)}) VALUES")
        out.append(",\n".join("(" + ", ".join(esc(v) for v in row) + ")" for row in batch) + ";")
    out.append("")


# ============================================================================
# Per-table column definitions (order follows sql/project_estimator/schema.sql)
# ============================================================================

EMPLOYEES_COLUMNS = [
    ("id_no", text("ID No.")),
    ("first_name", text("First Name")),
    ("last_name", text("Last Name")),
    ("middle_name", text("Middle Name")),
    ("job_title", select("Job Title")),
    ("role_level", select("Role Level")),
    ("nickname", text("Nickname")),
    ("email", text("Email")),
    ("start_date", text("Start Date")),
    ("end_date", text("End Date")),
    ("status", select("Status")),
    ("employment_status", select("Employment Status")),
    ("date_of_birth", text("Date of Birth")),
    ("address", text("Address")),
    ("personal_email", text("Personal E-Mail")),
    ("tax_identification_no", text("Tax Identification No.")),
    ("philhealth_no", text("PhilHealth No.")),
    ("sss_no", text("SSS No.")),
    ("hdmf_no", text("HDMF No.")),
    ("phone_number", text("Phone Number")),
    ("emergency_contact_person_name", text("Emergency Contact Person Name")),
    ("emergency_contact_number", text("Emergency Contact Number")),
    ("emergency_contact_address", text("Emergency Contact Address")),
    ("bank_name", select("Bank Name")),
    ("bank_account_number", text("Bank Account Number")),
    ("bank_swift_code", text("Bank SWIFT Code")),
    ("sl_credits", number("SL Credits")),
    ("remaining_sl", number("Remaining SL")),
    ("vl_credits", number("VL Credits")),
    ("remaining_vl", number("Remaining VL")),
    ("profile_folder_url", text("Profile Folder")),
    ("role", select("Role")),
    ("department", select("Department")),
    ("location", select("Location")),
    ("third_month_evaluation", text("3rd Month Evaluation")),
    ("fifth_month_evaluation", text("5th Month Evaluation")),
]
EMPLOYEES_ATTACHMENTS = ["Photo", "QR Code", "Employment Agreement", "Tax Identification",
                         "PhilHealth ID", "SS ID", "HDMF ID", "Bank Certificate",
                         "Job Offer", "National ID"]

USER_REPORTS_COLUMNS = [
    ("report_date", text("Date")),
    ("name", text("Name")),
    ("client", text("Client")),
    ("hours_rendered", number("Hours Rendered")),
    ("change_in_elements", number("Change in Elements (+/-)")),
    ("hrs_computed", number("*Hrs.")),
    ("elements_per_8_hours", number("Elements per 8 Hours")),
    ("progress_per_activity_pct", percent("Progress per Activity")),
    ("remarks", text("Remarks")),
    ("late_submission", select("LateSubmission")),
    ("late_submission_approval", select("LateSubmission Approval")),
    ("date_created", text("Date Created")),
    ("approval", select("Approval")),
    ("approver_remarks", text("Approver Remarks")),
    ("actual_work_date", text("Actual Work Date")),
    ("is_support", select("IsSupport")),
    ("hours_multiplier", text("Hours Multiplier")),
]

PROJECTS_COLUMNS = [
    ("project_name", text("Project Name")),
    ("client_name", text("Client")),
    ("department", select("Department")),
    ("is_renamed_with_acc_number", checkbox("Is the ACC project renamed with ACC latest project number?")),
    ("is_ledger_moved_to_for_submission", checkbox("Is the project ledger (MS Teams Planner) moved and updated to For Submission (completed status)?")),
    ("is_ledger_details_updated", checkbox("Are the details in the project ledger (MS Teams Planner) updated?")),
    ("status", select("Status")),
    ("progress_pct", percent("Progress")),
    ("type_of_job", select("Type of Job")),
    ("area_sqft", number("Area (in S.F.)")),
    ("lod", select("LOD")),
    ("ave_lod", text("Ave LOD")),
    ("ar_lod", select("AR LOD")),
    ("st_lod", select("ST LOD")),
    ("md_lod", select("MD LOD")),
    ("el_lod", select("EL LOD")),
    ("pl_lod", select("PL LOD")),
    ("fp_lod", select("FP LOD")),
    ("estimated_num_elements", text("Estimated # of Elements")),
    ("productivity_kpi", text("Productivity KPI")),
    ("total_noe", number("Total NoE")),
    ("ar_noe", number("AR NoE")),
    ("st_noe", number("ST NoE")),
    ("el_noe", number("EL NoE")),
    ("fp_noe", number("FP NoE")),
    ("md_noe", number("MD NoE")),
    ("mp_noe", number("MP NoE")),
    ("pl_noe", number("PL NoE")),
    ("mc_noe", number("MC NoE")),
    ("qc_noe", number("QC NoE")),
    ("total_noh", number("Total NoH")),
    ("tot_hrs", number("Tot Hrs")),
    ("tot_hrs_internal", number("Tot Hrs (internal)")),
    ("total_noh_ot", number("Total NoH OT")),
    ("ar_hrs", number("AR Hrs")),
    ("st_hrs", number("ST Hrs")),
    ("el_hrs", number("EL Hrs")),
    ("el_hrs_copy", number("EL Hrs copy")),
    ("fp_hrs", number("FP Hrs")),
    ("mp_hrs", number("MP Hrs")),
    ("pl_hrs", number("PL Hrs")),
    ("mc_hrs", number("MC Hrs")),
    ("md_hrs", number("MD Hrs")),
    ("qc_hrs", number("QC Hrs")),
    ("ds_hrs", number("DS Hrs")),
    ("num_elements", number("# of Elements")),
    ("file_size_mb", number("File Size (MB)")),
    ("scan_file_size_gb", text("Scan File Size (GB)")),
    ("date_started", text("Date Started")),
    ("date_done", when("Date (Done)")),
    ("date_closed", when("Date Closed")),
    ("due_date", text("Due Date")),
    ("acc_project_link", text("ACC Project Link")),
    ("msteams_project_link", text("MSTeams Project Link")),
    ("ms_planner_link", text("MS Planner Link")),
    ("panoramic_link", text("Panoramic Link")),
    ("google_drive_folder_path", text("Google Drive Folder Path")),
    ("pm_name", text("PM Name")),
    ("project_member_names", text("Project Member Names")),
    ("support_member_name", text("Support Member Name")),
    ("total_elements", number("totalElements")),
    ("creator_name", text("creatorName")),
    ("last_modified_by_name", text("lastModifiedByName")),
    ("date_modified", when("Date Modified")),
    ("project_creation_status", select("ProjectCreationStatus")),
    ("project_creation_approver", text("ProjectCreationApprover")),
    ("project_approver_email", text("Project Approver Email")),
    ("project_creation_approver_remarks", text("ProjectCreationApproverRemarks")),
    ("last_report_date", text("Last Report Date")),
    ("close_project_requester", text("Close Project Requester")),
    ("done_project_requester", text("Done Project Requester")),
    ("project_lead_email", text("Project Lead Email")),
    ("ms_loop_link", text("MS Loop Link")),
    ("budgeted_hrs", number("Budgeted Hrs")),
    ("project_efficiency", number("Project Efficiency")),
]

ACTIVITY_CODES_COLUMNS = [
    ("name", text("Name")),
    ("id_no", text("IdNo")),
    ("department", select("Department")),
]

EARN_CODES_COLUMNS = [
    ("description", text("Description")),
    ("did_not_work", number("Did Not Work")),
    ("did_work", number("Did Work")),
]

EARN_CODES_V2_COLUMNS = [
    ("description", text("Description")),
    ("did_not_work", number("Did Not Work")),
    ("did_work", number("Did Work")),
]

ACCOUNTS_COLUMNS = [
    ("airtable_id_no", number("ID")),
    ("name", text("Name")),
    ("username", text("Username")),
    ("password_hash", text("Password")),
    ("invite_code", text("InviteCode")),
    ("invited_by", text("InvitedBy")),
]

PROJECT_ESTIMATES_COLUMNS = [
    ("name", text("Name")),
    ("notes", text("Notes")),
    ("assignee_email", collaborator("Assignee")),
    ("status", select("Status")),
    ("scopes", text("Scopes")),
    ("scope", select("Scope")),
    ("date_modified_iso", text("Date Modified")),
    ("project_start", text("Project Start")),
    ("project_end", text("Project End")),
    ("member_trades", text("Member Trades")),
    ("member_emails", text("Member Emails")),
    ("booking_schedule", text("Booking Schedule")),
]
PROJECT_ESTIMATES_ATTACHMENTS = ["Attachments"]

HOLIDAYS_COLUMNS = [
    ("holiday_date", text("Date")),
    ("name", text("Name")),
    ("special_holiday_type", select("Special Holiday Type")),
]

BUG_REPORTS_COLUMNS = [
    ("title", text("Title")),
    ("description", text("Description")),
    ("severity", select("Severity")),
    ("status", select("Status")),
    ("reporter", text("Reporter")),
    ("client_version", text("Client Version")),
    ("api_version", text("API Version")),
    ("device", text("Device")),
]


# ============================================================================
# Main
# ============================================================================

def main():
    parser = argparse.ArgumentParser(
        description="Transform a raw Airtable export into SQL for sql/project_estimator/schema.sql")
    parser.add_argument("--export-dir", required=True, help="Directory produced by fetch_airtable.py")
    parser.add_argument("--output", required=True, help="Output .sql file path")
    parser.add_argument("--database", default="test_estimator_database",
                         help="Database name for the USE statement (must match schema.sql)")
    parser.add_argument("--cloudinary-cache", default=None,
                         help="Cache produced by upload_attachments_to_cloudinary.py. When given, "
                              "attachment file_url values are real Cloudinary URLs instead of local "
                              "paths (falls back to local paths for anything not yet uploaded).")
    args = parser.parse_args()

    export_dir = Path(args.export_dir)
    manifest = load_manifest(export_dir)

    cloudinary_cache = {}
    if args.cloudinary_cache:
        cache_path = Path(args.cloudinary_cache)
        if cache_path.exists():
            with open(cache_path, encoding="utf-8") as f:
                cloudinary_cache = json.load(f)
            print(f"Loaded {len(cloudinary_cache)} Cloudinary URLs from {cache_path}")
        else:
            print(f"WARNING: --cloudinary-cache {cache_path} not found; attachments will use local paths")

    out = []
    summary = []

    def table(label, airtable_table, sql_table, columns):
        records, id_map, cols, rows = gen_table(export_dir, airtable_table, columns)
        summary.append((label, len(records)))
        print(f"Processing {label} ({len(records)})...")
        write_insert(out, sql_table, cols, rows)
        return records, id_map

    def attachments(sql_table, records, id_map, field_names):
        rows = gen_attachments_for_table(records, sql_table, id_map, manifest, cloudinary_cache, field_names)
        write_insert(out, "attachments", ATTACHMENT_COLUMNS, rows)

    out.append(f"USE {args.database};\n")
    out.append("START TRANSACTION;\n")

    # Employees is the Airtable table literally named "Grid view - Active Status";
    # the second argument must match the export filename on disk.
    employees_records, employees_id_map = table(
        "Employees", "Grid view - Active Status", "employees", EMPLOYEES_COLUMNS)
    attachments("employees", employees_records, employees_id_map, EMPLOYEES_ATTACHMENTS)

    activity_records, activity_id_map = table(
        "Activity Codes", "Activity Codes", "activity_codes", ACTIVITY_CODES_COLUMNS)

    _, earn_id_map = table("Earn Codes", "Earn Codes", "earn_codes", EARN_CODES_COLUMNS)

    _, earn_v2_id_map = table("Earn Codes V2 (Grid view 2)", "Grid view 2",
                              "earn_codes_v2", EARN_CODES_V2_COLUMNS)

    table("Accounts", "Accounts", "accounts", ACCOUNTS_COLUMNS)

    estimates_records, estimates_id_map = table(
        "Project Estimates", "Project Estimates", "project_estimates", PROJECT_ESTIMATES_COLUMNS)
    attachments("project_estimates", estimates_records, estimates_id_map,
                PROJECT_ESTIMATES_ATTACHMENTS)

    holidays_records, holidays_id_map = table(
        "Holidays", "Holidays", "holidays", HOLIDAYS_COLUMNS)

    table("Bug Reports", "Bug Reports", "bug_reports", BUG_REPORTS_COLUMNS)

    project_records, projects_id_map = table("Projects", "Projects", "projects", PROJECTS_COLUMNS)
    write_insert(out, "employees_projects", ["employee_id", "project_id", "role_on_project"],
                 gen_employees_projects(project_records, employees_id_map, projects_id_map))

    ur_records, ur_id_map = table("User Reports", "User Reports", "user_reports",
                                  USER_REPORTS_COLUMNS)
    write_insert(out, "employees_user_reports", ["employee_id", "user_report_id"],
                 swap(gen_junction(ur_records, ur_id_map, "Employee No.", employees_id_map)))
    write_insert(out, "projects_user_reports", ["project_id", "user_report_id"],
                 swap(gen_junction(ur_records, ur_id_map, "Project", projects_id_map)))
    write_insert(out, "user_reports_activity_codes", ["user_report_id", "activity_code_id"],
                 gen_junction(ur_records, ur_id_map, "Activity", activity_id_map))
    write_insert(out, "user_reports_earn_codes", ["user_report_id", "earn_code_id"],
                 gen_junction(ur_records, ur_id_map, "Earn Code", earn_id_map))
    write_insert(out, "activity_codes_projects", ["activity_code_id", "project_id"],
                 gen_junction(activity_records, activity_id_map, "Project/s", projects_id_map))
    write_insert(out, "holidays_earn_codes_v2", ["holiday_id", "earn_code_v2_id", "variant"],
                 gen_holiday_earn_codes(holidays_records, holidays_id_map, earn_v2_id_map))

    out.append("COMMIT;")

    header = [
        "-- " + "=" * 76,
        f"-- {Path(args.output).name} -- generated by generate_sql_project_estimator.py",
        f"-- Source export: {export_dir}",
        f"-- Run this AFTER sql/project_estimator/schema.sql has created the `{args.database}` database.",
        "--",
    ]
    for label, count in summary:
        header.append(f"--   {label} ".ljust(45, ".") + f" {count}")
    header.append("-- " + "=" * 76)
    header.append("")

    with open(args.output, "w", encoding="utf-8") as f:
        f.write("\n".join(header + out))

    print(f"\nWrote {args.output}")


if __name__ == "__main__":
    main()
