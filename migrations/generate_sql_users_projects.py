#!/usr/bin/env python3
"""
generate_sql_users_projects.py -- Transforms a raw Airtable export (produced by
fetch_airtable.py) into a data.sql file that loads into sql/portal/schema.sql
(the MySQL "Users & Projects" schema -- works for both the TEST base and the
PRODUCTION base, since they share identical table/field structure).

--------------------------------------------------------------------------
USAGE
--------------------------------------------------------------------------
  # 1. First run fetch_airtable.py to produce the raw export:
  python fetch_airtable.py --base-id appXXXXXXXXXXXXXX --output-dir ./export

  # 2. Then transform it into SQL:
  python generate_sql_users_projects.py --export-dir ./export --output data.sql

--------------------------------------------------------------------------
FIELD MAPPING
--------------------------------------------------------------------------
Every column below is pulled by the Airtable field's display NAME, verified
against a real export's export_dir/_base_schema.json (the public API keys
fields by name, not the field ID the old in-chat connector used). If a field
gets renamed in the Airtable UI, re-run inspect_schema.py against a fresh
export and update the COLUMNS list for the affected table below.

--------------------------------------------------------------------------
VALUE CONVERSIONS (why the output differs from a naive dump)
--------------------------------------------------------------------------
- Percent fields: Airtable's API returns a 0-1 fraction; converted to a 0-100
  number to match the schema's "_pct" columns.
- Checkbox fields: Airtable omits the key entirely when unchecked; treated as
  FALSE rather than NULL, since "unchecked" is known, not unknown.
- dateTime / createdTime / lastModifiedTime fields: Airtable returns
  '2026-08-06T09:00:43.000Z'. MySQL's strict mode rejects the literal T/Z, so
  these are rewritten to '2026-08-06 09:00:43' at generation time.
- String escaping: MySQL treats backslash as an escape character by default,
  so a Windows path like 'H:\\My Drive\\Project' would silently lose its
  backslashes on import. Backslashes are doubled before quotes are escaped.
- Junction rows are de-duplicated per table (some Airtable relationships are
  mirrored across more than one link field) so a composite PRIMARY KEY never
  collides within a single INSERT.

--------------------------------------------------------------------------
ATTACHMENTS
--------------------------------------------------------------------------
Unlike the placeholder 'image.png' filenames used in earlier hand-built
exports, this script writes REAL data into the `attachments` table:
  - file_name: the original filename from Airtable
  - file_url:  the LOCAL path (relative to your export dir, forward slashes)
               where fetch_airtable.py already downloaded the actual file --
               NOT the original Airtable URL, which will have expired.
If you move the attachments/ folder, update file_url accordingly, or better,
point it at wherever your own image-hosting plan ends up serving these from.
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
    """employees_projects carries a role, sourced from three separate link fields on Project/s."""
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


def gen_bim_form_elements(records, id_map):
    """bim_form_elements_included stores the chosen option strings directly, not a record link."""
    seen = set()
    rows = []
    for r in records:
        bim_id = id_map.get(r["id"])
        if bim_id is None:
            continue
        for element in (r.get("fields", {}).get("Elements Included in Model") or []):
            key = (bim_id, element)
            if key not in seen:
                seen.add(key)
                rows.append([bim_id, element])
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


def gen_reimbursement_receipts(records, id_map, manifest, cloudinary_cache):
    """The live app (PortalReimbursementRequests::RECEIPT_FIELD) reads exactly one
    receipt per reimbursement row under field_name 'receipts' -- not the raw Airtable
    field names, and not one row per attachment field. "Receipts (If Applicable)" wins
    over "Reimbursement Receipt" when both are set, since that's the field actually
    shown to members in the historical data."""
    rows = []
    for r in records:
        sid = id_map.get(r["id"])
        if sid is None:
            continue
        f = r.get("fields", {})
        chosen = None
        chosen_field = None
        for field_name in ("Receipts (If Applicable)", "Reimbursement Receipt"):
            atts = f.get(field_name)
            if isinstance(atts, list) and atts:
                chosen, chosen_field = atts[0], field_name
                break
        if chosen is None:
            continue
        file_url = resolve_file_url(chosen, r["id"], chosen_field, manifest, cloudinary_cache)
        rows.append([
            "reimbursements", sid, "receipts",
            file_url, chosen.get("filename"), chosen.get("size"), chosen.get("type"),
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
# Per-table column definitions
# ("`procedure`" is backtick-quoted where used -- MySQL reserved word)
# ============================================================================

EMPLOYEES_COLUMNS = [
    ("id_no", text("ID No.")),
    ("first_name", text("First Name")),
    ("last_name", text("Last Name")),
    ("middle_name", text("Middle Name")),
    ("job_title", select("Job Title")),
    ("nickname", text("Nickname")),
    ("email", text("Email")),
    ("start_date", text("Start Date")),
    ("end_date", text("End Date")),
    ("status", select("Status")),
    ("employment_status", select("Employment Status")),
    ("date_of_birth", text("Date of Birth")),
    ("blood_type", select("Blood Type")),
    ("address", text("Address")),
    ("personal_email", text("Personal E-Mail")),
    ("tax_identification_no", text("Tax Identification No.")),
    ("philhealth_no", text("PhilHealth No.")),
    ("sss_no", text("SSS No.")),
    ("hdmf_no", text("HDMF No.")),
    ("phone_number", text("Phone Number")),
    ("sl_credits", number("SL Credits")),
    ("remaining_sl", number("Remaining SL")),
    ("vl_credits", number("VL Credits")),
    ("remaining_vl", number("Remaining VL")),
    ("profile_folder_url", text("Profile Folder")),
    ("role", select("Role")),
    ("department", select("Department")),
    ("location", select("Location")),
    ("emergency_contact_person_name", text("Emergency Contact Person Name")),
    ("emergency_contact_number", text("Emergency Contact Number")),
    ("emergency_contact_address", text("Emergency Contact Address")),
    ("bank_name", select("Bank Name")),
    ("bank_account_number", text("Bank Account Number")),
    ("bank_swift_code", text("Bank SWIFT Code")),
    ("role_level", select("Role Level")),
]
EMPLOYEES_ATTACHMENTS = ["Photo", "QR Code", "Tax Identification", "PhilHealth ID", "SS ID",
                          "HDMF ID", "Bank Certificate", "Employment Agreement"]

NEW_EMPLOYEE_DATA_COLUMNS = [
    ("id_no", text("ID No.")),
    ("first_name", text("First Name")),
    ("last_name", text("Last Name")),
    ("middle_name", text("Middle Name")),
    ("job_title", select("Job Title")),
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
    ("sl_credits", number("SL Credits")),
    ("remaining_sl", number("Remaining SL")),
    ("vl_credits", number("VL Credits")),
    ("remaining_vl", number("Remaining VL")),
    ("profile_folder_url", text("Profile Folder")),
    ("role", select("Role")),
    ("department", select("Department")),
    ("emergency_contact_person_name", text("Emergency Contact Person Name")),
    ("emergency_contact_number", text("Emergency Contact Number")),
    ("emergency_contact_address", text("Emergency Contact Address")),
    ("bank_name", select("Bank Name")),
    ("bank_account_number", text("Bank Account Number")),
    ("bank_swift_code", text("Bank SWIFT Code")),
    ("data_approval", select("Data Approval")),
]
NEW_EMPLOYEE_DATA_ATTACHMENTS = ["Photo", "QR Code", "Tax Identification", "PhilHealth ID",
                                  "SS ID", "HDMF ID", "Bank Certificate"]

CLIENTS_COLUMNS = [
    ("name", text("Name")),
    ("client_id", text("ClientID")),
    ("notes", text("Notes")),
    ("assignee_email", collaborator("Assignee")),
    ("status", select("Status")),
]
CLIENTS_ATTACHMENTS = ["Attachments"]

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

T1_COLUMNS = [
    ("wbs_code", text("WBS Code")),
    ("process_index", text("Process Index")),
    ("level", select("Level")),
    ("type", select("Type")),
    ("name", text("Name")),
    ("process_wbs", text("Process WBS")),
    ("sop_reference", text("SOP Reference")),
    ("time_trackable", checkbox("Time Trackable")),
    ("department", text("Department")),
    ("assignee_email", collaborator("Assignee")),
    ("status", select("Status")),
]
T1_ATTACHMENTS = ["Attachments"]

T2_COLUMNS = [
    ("wbs_code", text("WBS Code")),
    ("sequence_index", number("Sequence Index")),
    ("level", select("Level")),
    ("type", select("Type")),
    ("name", text("Name")),
    ("process", text("Process")),
    ("activity_wbs", text("Activity WBS")),
    ("sop_reference", text("SOP Reference")),
    ("time_trackable", checkbox("Time Trackable")),
    ("department", text("Department")),
    ("assignee_email", collaborator("Assignee")),
    ("status", select("Status")),
]
T2_ATTACHMENTS = ["Attachments"]

T3_COLUMNS = [
    ("wbs_code", text("WBS Code")),
    ("activity_index", text("Activity Index")),
    ("level", select("Level")),
    ("type", select("Type")),
    ("name", text("Name")),
    ("procedure_wbs", text("Procedure WBS")),
    ("`procedure`", text("Procedure")),
    ("process_wbs", text("Process WBS")),
    ("process", text("Process")),
    ("sop_reference", text("SOP Reference")),
    ("time_trackable", checkbox("Time Trackable")),
    ("department", text("Department")),
    ("assignee_email", collaborator("Assignee")),
    ("status", select("Status")),
]
T3_ATTACHMENTS = ["Attachments"]

T4_COLUMNS = [
    ("wbs_code", text("WBS Code")),
    ("task_index", text("Task Index")),
    ("task_name", text("Task Name")),
    ("task_notes", text("Task Notes")),
    ("task", text("Task")),
    ("activity_name", text("Activity Name")),
    ("activity", text("Activity")),
    ("procedure_name", text("Procedure Name")),
    ("`procedure`", text("Procedure")),
    ("description", text("Description")),
    ("assigned_to_email", collaborator("Assigned To")),
    ("start_date", text("Start Date")),
    ("due_date", text("Due Date")),
    ("time_spent_hrs", number("Time Spent (hrs)")),
    ("status", select("Status")),
    ("priority", select("Priority")),
]

PROJECT_SCOPES_COLUMNS = [
    ("id_no", number("IDNo.")),
    ("progress_pct", percent("Progress")),
]

# project_number has no matching Airtable field -- left out, same as the historical data.sql
PROJECTS_COLUMNS = [
    ("project_name", text("Project Name")),
    ("department", select("Department")),
    ("is_renamed_with_acc_number", checkbox("Is the ACC project renamed with ACC latest project number?")),
    ("is_ledger_moved_to_for_submission",
     checkbox("Is the project ledger (MS Teams Planner) moved and updated to For Submission (completed status)?")),
    ("is_ledger_details_updated", checkbox("Are the details in the project ledger (MS Teams Planner) updated?")),
    ("status", select("Status")),
    ("progress_pct", percent("Progress")),
    ("type_of_job", select("Type of Job")),
    ("area_sqft", number("Area (in S.F.)")),
    ("lod", select("LOD")),
    ("ar_lod", select("AR LOD")),
    ("st_lod", select("ST LOD")),
    ("md_lod", select("MD LOD")),
    ("el_lod", select("EL LOD")),
    ("pl_lod", select("PL LOD")),
    ("fp_lod", select("FP LOD")),
    ("estimated_num_elements", text("Estimated # of Elements")),
    ("num_elements", number("# of Elements")),
    ("file_size_mb", number("File Size (MB)")),
    ("scan_file_size_gb", text("Scan File Size (GB)")),
    ("date_done", when("Date (Done)")),
    ("date_closed", when("Date Closed")),
    ("due_date", text("Due Date")),
    ("forma_link", text("Forma Link")),
    ("msteams_project_link", text("MSTeams Project Link")),
    ("ms_planner_link", text("MS Planner Link")),
    ("panoramic_link", text("Panoramic Link")),
    ("ms_loop_link", text("MS Loop Link")),
    ("google_drive_folder_path", text("Google Drive Folder Path")),
    ("project_lead_email", text("Project Lead Email")),
    ("total_elements", number("totalElements")),
    ("project_creation_status", select("ProjectCreationStatus")),
    ("project_creation_approver", text("ProjectCreationApprover")),
    ("project_approver_email", text("Project Approver Email")),
    ("project_creation_approver_remarks", text("ProjectCreationApproverRemarks")),
    ("close_project_requester", text("Close Project Requester")),
    ("done_project_requester", text("Done Project Requester")),
    ("date_modified", when("Date Modified")),
]

WARNINGS_COLUMNS = [
    ("warning_date", text("Date")),
    ("description", text("Description")),
    ("status", select("Status")),
    ("violation_type", select("Violation Type")),
    ("date_created", text("Date Created")),
]

REIMBURSEMENTS_COLUMNS = [
    ("reimb_date", text("Date")),
    ("item", text("Item")),
    ("cost", number("Cost")),
    ("qty", number("QTY")),
    ("purpose", text("Purpose")),
    ("employee_name_input",
     text('Employee Name (Input your name so you can filter the IDs in "Reimburse To" field)')),
    ("team", select("Team")),
    ("status", select("Status")),
    ("approver_remarks", text("Approver Remarks")),
    ("date_created", text("Date Created")),
]
# Receipt attachments are handled by gen_reimbursement_receipts(), not the generic
# attachments() helper -- see its docstring for why.

JOB_TYPE_ALLOWED_ACTIVITIES_COLUMNS = [
    ("job_type", text("Job Type")),
    ("activity_code_id_initials", text("Activity Code ID Initials")),
]

ACHIEVEMENTS_MILESTONES_COLUMNS = [
    ("achievement_date", text("Date")),
    ("project_name", text("Project Name")),
    ("description", text("Description")),
    ("links", text("Links")),
]
ACHIEVEMENTS_ATTACHMENTS = ["Attachments"]

BIM_FORM_COLUMNS = [
    ("project_number", text("Project Number")),
    ("project_name", text("Project Name")),
    ("project_address", text("Project Address")),
    ("project_size_sqft", number("Project Size (sq. ft)")),
    ("assignee_email", collaborator("Assignee")),
    ("status", select("Status")),
    ("attachment_summary", ai("Attachment Summary")),
    ("no_of_floors", number("No. of Floors")),
    ("client_name", text("Client Name")),
    ("poc_name", text("Point of Contact (POC)")),
    ("poc_phone", text("POC Phone #")),
    ("purpose_of_scan_to_bim", select("Purpose of Scan to BIM Model")),
    ("arch_lod", select("ARCH LOD")),
    ("struct_lod", select("STRUCT LOD")),
    ("mech_lod", select("MECH LOD")),
    ("elect_lod", select("ELECT LOD")),
    ("plumb_lod", select("PLUMB LOD")),
    ("fp_lod", select("FP LOD")),
    ("client_provides_point_cloud", select("Client will provide point cloud scan")),
    ("point_cloud_scan_format", select("Point Cloud Scan Format")),
    ("client_has_existing_cad_rvt", select("Client has existing CAD and RVT File")),
    ("special_requests", select("Special Requests")),
    ("insertion_point_strategy", select("Insertion Point Strategy")),
    ("preferred_workset", select("Preffered Workset")),  # sic -- typo in the Airtable field itself
    ("form_assigned_by", text("Form Assigned By")),
    ("filled_up_by", text("Filled Up By")),
]
BIM_FORM_ATTACHMENTS = ["Attachments"]

PROJECT_ACTION_HISTORY_COLUMNS = [
    ("name", number("Name")),
    ("action_name", text("ActionName")),
    ("created_by", text("CreatedBy")),
    ("remarks", text("Remarks")),
    ("created_at", when("Created")),
]

REQUESTS_COLUMNS = [
    ("request_date", text("Date")),
    ("name", text("Name")),
    ("no_of_hours", number("No. of Hours")),
    ("original_work_day", text("Original Work Day")),
    ("offset_work_day", text("Offset Work Day")),
    ("offset_hrs", number("Offset Hrs")),
    ("reason", text("Reason")),
    ("status", select("Status")),
    ("approver_remarks", text("Approver Remarks")),
    ("date_created", text("Date Created")),
    ("linked_report_ids", text("Linked Report IDs")),
    ("type", text("Type")),
    ("category", select("Category")),
]

USER_REPORTS_COLUMNS = [
    ("report_date", text("Date")),
    ("hours_rendered", number("Hours Rendered")),
    ("change_in_elements", number("Change in Elements (+/-)")),
    ("progress_per_activity_pct", percent("Progress per Activity")),
    ("remarks", text("Remarks")),
    ("late_submission", select("LateSubmission")),
    ("late_submission_approval", select("LateSubmission Approval")),
    ("date_created", text("Date Created")),
    ("approval", select("Approval")),
    ("approver_remarks", text("Approver Remarks")),
    ("actual_work_date", text("Actual Work Date")),
    ("is_support", select("IsSupport")),
    ("end_date", text("End date")),
    ("project_test_summary", text("Project Test summary")),
    ("project_test_summary_2", text("Project Test summary 2")),
    ("duration", number("Duration")),
]

USER_REPORTS_SCOPE_PROGRESS_COLUMNS = [
    ("report_date", text("Date")),
    ("scope_progress_pct", percent("Scope Progress")),
    ("reported_by", text("ReportedBy")),
]


# ============================================================================
# Main
# ============================================================================

def main():
    parser = argparse.ArgumentParser(
        description="Transform a raw Airtable export into SQL for sql/portal/schema.sql")
    parser.add_argument("--export-dir", required=True, help="Directory produced by fetch_airtable.py")
    parser.add_argument("--output", required=True, help="Output .sql file path")
    parser.add_argument("--database", default="test_portal_database",
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

    employees_records, employees_id_map = table("Employees", "Employees", "employees", EMPLOYEES_COLUMNS)
    attachments("employees", employees_records, employees_id_map, EMPLOYEES_ATTACHMENTS)

    ned_records, ned_id_map = table("New Employee Data", "New Employee Data",
                                     "new_employee_data", NEW_EMPLOYEE_DATA_COLUMNS)
    attachments("new_employee_data", ned_records, ned_id_map, NEW_EMPLOYEE_DATA_ATTACHMENTS)

    clients_records, clients_id_map = table("Clients", "Clients", "clients", CLIENTS_COLUMNS)
    attachments("clients", clients_records, clients_id_map, CLIENTS_ATTACHMENTS)

    _, activity_id_map = table("Activity Codes", "Activity Code_s", "activity_codes", ACTIVITY_CODES_COLUMNS)
    _, earn_id_map = table("Earn Codes", "Earn Codes", "earn_codes", EARN_CODES_COLUMNS)

    t1_records, t1_id_map = table("Project Scope Source T1 (Processes)",
                                   "Project Scope Source - T1 Processes",
                                   "project_scope_t1_processes", T1_COLUMNS)
    attachments("project_scope_t1_processes", t1_records, t1_id_map, T1_ATTACHMENTS)

    t2_records, t2_id_map = table("Project Scope Source T2 (Procedures)",
                                   "Project Scope Source - T2 Procedures",
                                   "project_scope_t2_procedures", T2_COLUMNS)
    attachments("project_scope_t2_procedures", t2_records, t2_id_map, T2_ATTACHMENTS)
    write_insert(out, "t1_processes_t2_procedures", ["t1_process_id", "t2_procedure_id"],
                 gen_junction(t1_records, t1_id_map, "Table 2", t2_id_map))

    t3_records, t3_id_map = table("Project Scope Source T3 (Activities)",
                                   "Project Scope Source - T3 Activities",
                                   "project_scope_t3_activities", T3_COLUMNS)
    attachments("project_scope_t3_activities", t3_records, t3_id_map, T3_ATTACHMENTS)

    t4_records, t4_id_map = table("Project Scope Source T4 (Tasks)",
                                   "Project Scope Source - T4 Tasks",
                                   "project_scope_t4_tasks", T4_COLUMNS)
    write_insert(out, "t3_activities_t4_tasks", ["t3_activity_id", "t4_task_id"],
                 gen_junction(t3_records, t3_id_map, "T4 - Tasks", t4_id_map))
    write_insert(out, "t4_task_dependencies", ["task_id", "depends_on_task_id"],
                 gen_junction(t4_records, t4_id_map, "Dependencies", t4_id_map))

    scopes_records, scopes_id_map = table("Project Scopes", "Project Scopes",
                                           "project_scopes", PROJECT_SCOPES_COLUMNS)
    write_insert(out, "project_scopes_t3_activities", ["project_scope_id", "t3_activity_id"],
                 gen_junction(scopes_records, scopes_id_map, "Scope - T3 Activities", t3_id_map))
    write_insert(out, "project_scopes_t4_tasks", ["project_scope_id", "t4_task_id"],
                 gen_junction(scopes_records, scopes_id_map, "Scope - T4 Tasks", t4_id_map))
    write_insert(out, "project_scopes_assigned_to", ["project_scope_id", "employee_id"],
                 gen_junction(scopes_records, scopes_id_map, "AssignedTo", employees_id_map))

    project_records, projects_id_map = table("Projects", "Project_s", "projects", PROJECTS_COLUMNS)
    write_insert(out, "projects_clients", ["project_id", "client_id"],
                 gen_junction(project_records, projects_id_map, "Client", clients_id_map))
    write_insert(out, "projects_project_scopes", ["project_id", "project_scope_id"],
                 gen_junction(project_records, projects_id_map, "Project Scopes", scopes_id_map))
    write_insert(out, "projects_activity_scope", ["project_id", "activity_id"],
                 gen_junction(project_records, projects_id_map, "Activity Scope", t3_id_map))
    write_insert(out, "employees_projects", ["employee_id", "project_id", "role_on_project"],
                 gen_employees_projects(project_records, employees_id_map, projects_id_map))

    warnings_records, warnings_id_map = table("Warnings", "Warnings", "warnings", WARNINGS_COLUMNS)
    write_insert(out, "employees_warnings", ["employee_id", "warning_id"],
                 swap(gen_junction(warnings_records, warnings_id_map, "Employee", employees_id_map)))

    reimb_records, reimb_id_map = table("Reimbursements", "Reimbursements",
                                         "reimbursements", REIMBURSEMENTS_COLUMNS)
    write_insert(out, "attachments", ATTACHMENT_COLUMNS,
                 gen_reimbursement_receipts(reimb_records, reimb_id_map, manifest, cloudinary_cache))
    write_insert(out, "employees_reimbursements", ["employee_id", "reimbursement_id"],
                 swap(gen_junction(reimb_records, reimb_id_map, "Reimburse To", employees_id_map)))

    table("Job Type Allowed Activities", "Job Type Allowed Activities",
          "job_type_allowed_activities", JOB_TYPE_ALLOWED_ACTIVITIES_COLUMNS)

    ach_records, ach_id_map = table("Achievements and Milestones", "Achievements and Milestones",
                                     "achievements_milestones", ACHIEVEMENTS_MILESTONES_COLUMNS)
    attachments("achievements_milestones", ach_records, ach_id_map, ACHIEVEMENTS_ATTACHMENTS)

    bim_records, bim_id_map = table("BIM Form", "BIM Form", "bim_form", BIM_FORM_COLUMNS)
    attachments("bim_form", bim_records, bim_id_map, BIM_FORM_ATTACHMENTS)
    write_insert(out, "bim_form_elements_included", ["bim_form_id", "element_name"],
                 gen_bim_form_elements(bim_records, bim_id_map))

    action_records, action_id_map = table("Project Action History", "Project Action History",
                                           "project_action_history", PROJECT_ACTION_HISTORY_COLUMNS)
    write_insert(out, "projects_action_history", ["project_id", "project_action_id"],
                 swap(gen_junction(action_records, action_id_map, "Project", projects_id_map)))

    req_records, req_id_map = table("Requests", "Request", "requests", REQUESTS_COLUMNS)
    write_insert(out, "projects_requests", ["project_id", "request_id"],
                 swap(gen_junction(req_records, req_id_map, "Project", projects_id_map)))
    write_insert(out, "requests_activity_codes", ["request_id", "activity_code_id"],
                 gen_junction(req_records, req_id_map, "Activity Scope", activity_id_map))
    write_insert(out, "requests_earn_codes", ["request_id", "earn_code_id"],
                 gen_junction(req_records, req_id_map, "Earn Code", earn_id_map))

    ur_records, ur_id_map = table("User Reports", "User Reports", "user_reports", USER_REPORTS_COLUMNS)
    write_insert(out, "employees_user_reports", ["employee_id", "user_report_id"],
                 swap(gen_junction(ur_records, ur_id_map, "Employee No.", employees_id_map)))
    write_insert(out, "projects_user_reports", ["project_id", "user_report_id"],
                 swap(gen_junction(ur_records, ur_id_map, "Project", projects_id_map)))
    write_insert(out, "user_reports_activity_codes", ["user_report_id", "activity_code_id"],
                 gen_junction(ur_records, ur_id_map, "Activity", activity_id_map))
    write_insert(out, "user_reports_earn_codes", ["user_report_id", "earn_code_id"],
                 gen_junction(ur_records, ur_id_map, "Earn Code", earn_id_map))
    write_insert(out, "user_report_dependencies", ["user_report_id", "depends_on_report_id"],
                 gen_junction(ur_records, ur_id_map, "Dependencies", ur_id_map))

    usp_records, usp_id_map = table("User Reports - Project Scope Progress",
                                     "User Reports - Project Scope Progress",
                                     "user_reports_scope_progress", USER_REPORTS_SCOPE_PROGRESS_COLUMNS)
    write_insert(out, "projects_user_reports_scope_progress",
                 ["project_id", "user_reports_scope_progress_id"],
                 swap(gen_junction(usp_records, usp_id_map, "Project", projects_id_map)))
    write_insert(out, "user_reports_scope_progress_t3_activities",
                 ["user_reports_scope_progress_id", "t3_activity_id"],
                 gen_junction(usp_records, usp_id_map, "T3 Activity", t3_id_map))
    write_insert(out, "user_reports_scope_progress_t4_tasks",
                 ["user_reports_scope_progress_id", "t4_task_id"],
                 gen_junction(usp_records, usp_id_map, "T4 - Tasks", t4_id_map))

    out.append("COMMIT;")

    header = [
        "-- " + "=" * 76,
        f"-- {Path(args.output).name} -- generated by generate_sql_users_projects.py",
        f"-- Source export: {export_dir}",
        f"-- Run this AFTER sql/portal/schema.sql has created the `{args.database}` database.",
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
