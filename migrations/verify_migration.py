#!/usr/bin/env python3
"""
verify_migration.py -- Health check: does a generated data.sql actually match
the raw Airtable export it came from?

Run it after generate_sql_*.py (migrate.ps1 does this automatically) and
before importing. It re-derives every mapped row straight from the export
JSON using the generator's own COLUMNS definitions, parses the data.sql back
into values, and compares cell by cell. Nothing is written anywhere.

    python verify_migration.py --export-dir <export> --data-sql <data.sql>

What it checks, and what a failure means:

  1. Row counts        every Airtable record must land as exactly one SQL row
                       (per table). A shortfall means the generator dropped
                       records.
  2. Cell values       for every mapped column of every row: the value the
                       generator's extractor produces from the raw record must
                       equal the value written into data.sql. Catches renamed
                       Airtable fields (which would silently become NULL),
                       wrong extractor types, ordering bugs, escaping bugs.
  3. Field landing     every Airtable field is either stored as a column, an
                       attachment, or a link (junction), or listed as a
                       deliberate omission. Computed fields (formula, rollup,
                       lookup...) are expected to be omitted. A plain field
                       that is not stored anywhere is reported, because that
                       is exactly what a rename looks like from this side.
  4. Link integrity    for every configured link field: every linked record ID
                       must exist in the target table's export. A dangling ID
                       is silently skipped by gen_junction().
  5. Junction counts   the number of distinct link pairs in the export must
                       equal the number of rows written to the junction table.
                       Catches a wrong id_map target (links that go nowhere).
  6. Attachments       rows in the `attachments` table must equal the number
                       of attachments in the export; failed downloads and
                       missing local files are reported.
  7. Schema columns    every column the data.sql INSERTs mention must exist in
                       the checked-in schema (sql/<base>/schema.sql plus, for
                       Users & Projects, separate.sql). data.sql that would
                       fail to import fails here first.

Exit code 0 = every check passed (warnings are informational), 1 = at least
one failure. Output is plain text; read FAIL lines first.

The table/column mapping is NOT duplicated here: the script imports the
generator module and reads its COLUMNS lists, extracting each Airtable field
name from the extractor closure. Rename a field inside the generator and the
verifier follows automatically. It also reads _base_schema.json (Airtable's
own metadata) to resolve link targets -- so junction checks compare against
Airtable's view, not the generator's.
"""

import argparse
import importlib.util
import json
import re
import sys
from pathlib import Path

SCRIPT_DIR = Path(__file__).resolve().parent
REPO_ROOT = SCRIPT_DIR.parent

# Field types that are computed by Airtable or live elsewhere by convention
# and are expected NOT to be a stored column.
COMPUTED_TYPES = {
    "formula", "rollup", "multipleLookupValues", "multipleSelects",
    "autoNumber", "createdTime", "lastModifiedTime", "createdBy",
    "lastModifiedBy", "count", "button", "barcode",
}


def attr(name):
    return ("attr", name)


def literal(name, mode):
    return ("literal", name, mode)


def norm_col(name):
    """Column names may be backtick-quoted (MySQL reserved words like `procedure`)."""
    return name.strip().strip("`")


# ============================================================================
# Base configuration
#
# tables:      Airtable table display name -> (sql table, COLUMNS attr in the generator)
# attachments: sql table -> (Airtable table, field list). Each field is either
#              attr("SOMETHING_ATTACHMENTS") -- expand a generator constant --
#              or literal("Field Name", "all"|"one"), where "one" matches
#              gen_reimbursement_receipts (first attachment per field only).
# junctions:   sql junction table, source Airtable table, fields it resolves.
#              Each field is (airtable field name, extra key) where the extra
#              key distinguishes roles/variants inside one junction. mode
#              "value" stores the raw selected value instead of a record link.
#              "known" downgrades a failure to a documented warning.
# ============================================================================

BASES = {
    "users-projects": {
        "generator": "generate_sql_users_projects.py",
        "schema": ["sql/portal/schema.sql", "sql/portal/separate.sql"],
        "tables": {
            "Employees": ("employees", "EMPLOYEES_COLUMNS"),
            "New Employee Data": ("new_employee_data", "NEW_EMPLOYEE_DATA_COLUMNS"),
            "Clients": ("clients", "CLIENTS_COLUMNS"),
            "Activity Code/s": ("activity_codes", "ACTIVITY_CODES_COLUMNS"),
            "Earn Codes": ("earn_codes", "EARN_CODES_COLUMNS"),
            "Project Scope Source - T1 Processes": ("project_scope_t1_processes", "T1_COLUMNS"),
            "Project Scope Source - T2 Procedures": ("project_scope_t2_procedures", "T2_COLUMNS"),
            "Project Scope Source - T3 Activities": ("project_scope_t3_activities", "T3_COLUMNS"),
            "Project Scope Source - T4 Tasks": ("project_scope_t4_tasks", "T4_COLUMNS"),
            "Project Scopes": ("project_scopes", "PROJECT_SCOPES_COLUMNS"),
            "Project/s": ("projects", "PROJECTS_COLUMNS"),
            "Warnings": ("warnings", "WARNINGS_COLUMNS"),
            "Reimbursements": ("reimbursements", "REIMBURSEMENTS_COLUMNS"),
            "Job Type Allowed Activities": ("job_type_allowed_activities", "JOB_TYPE_ALLOWED_ACTIVITIES_COLUMNS"),
            "Achievements and Milestones": ("achievements_milestones", "ACHIEVEMENTS_MILESTONES_COLUMNS"),
            "BIM Form": ("bim_form", "BIM_FORM_COLUMNS"),
            "Project Action History": ("project_action_history", "PROJECT_ACTION_HISTORY_COLUMNS"),
            "Request": ("requests", "REQUESTS_COLUMNS"),
            "User Reports": ("user_reports", "USER_REPORTS_COLUMNS"),
            "User Reports - Project Scope Progress": ("user_reports_scope_progress", "USER_REPORTS_SCOPE_PROGRESS_COLUMNS"),
        },
        "attachments": {
            "employees": ("Employees", [attr("EMPLOYEES_ATTACHMENTS")]),
            "new_employee_data": ("New Employee Data", [attr("NEW_EMPLOYEE_DATA_ATTACHMENTS")]),
            "clients": ("Clients", [attr("CLIENTS_ATTACHMENTS")]),
            "project_scope_t1_processes": ("Project Scope Source - T1 Processes", [attr("T1_ATTACHMENTS")]),
            "project_scope_t2_procedures": ("Project Scope Source - T2 Procedures", [attr("T2_ATTACHMENTS")]),
            "project_scope_t3_activities": ("Project Scope Source - T3 Activities", [attr("T3_ATTACHMENTS")]),
            "achievements_milestones": ("Achievements and Milestones", [attr("ACHIEVEMENTS_ATTACHMENTS")]),
            "bim_form": ("BIM Form", [attr("BIM_FORM_ATTACHMENTS")]),
            "reimbursements": ("Reimbursements", [
                literal("Receipts (If Applicable)", "one"),
                literal("Reimbursement Receipt", "one"),
            ]),
        },
        "junctions": [
            ("t1_processes_t2_procedures", "Project Scope Source - T1 Processes", [("Table 2", None)], {}),
            ("t3_activities_t4_tasks", "Project Scope Source - T3 Activities", [("T4 - Tasks", None)], {}),
            ("t4_task_dependencies", "Project Scope Source - T4 Tasks", [("Dependencies", None)], {}),
            ("project_scopes_t3_activities", "Project Scopes", [("Scope - T3 Activities", None)], {}),
            ("project_scopes_t4_tasks", "Project Scopes", [("Scope - T4 Tasks", None)], {}),
            ("project_scopes_assigned_to", "Project Scopes", [("AssignedTo", None)], {}),
            ("projects_clients", "Project/s", [("Client", None)], {}),
            ("projects_project_scopes", "Project/s", [("Project Scopes", None)], {}),
            ("projects_activity_scope", "Project/s", [("Activity Scope", None)], {
                "known": "schema FK + generator use project_scope_t3_activities, Airtable links to Activity Code/s (NOTE.md)",
                "known_sql": "projects_activity_scope",
            }),
            ("employees_projects", "Project/s", [("PM ID", "pm"), ("Project Members", "member"), ("Support Members", "support")], {}),
            ("employees_warnings", "Warnings", [("Employee", None)], {}),
            ("employees_reimbursements", "Reimbursements", [("Reimburse To", None)], {}),
            ("projects_action_history", "Project Action History", [("Project", None)], {}),
            ("projects_requests", "Request", [("Project", None)], {}),
            ("requests_activity_codes", "Request", [("Activity Scope", None)], {}),
            ("requests_earn_codes", "Request", [("Earn Code", None)], {}),
            ("employees_user_reports", "User Reports", [("Employee No.", None)], {}),
            ("projects_user_reports", "User Reports", [("Project", None)], {}),
            ("user_reports_activity_codes", "User Reports", [("Activity", None)], {}),
            ("user_reports_earn_codes", "User Reports", [("Earn Code", None)], {}),
            ("user_report_dependencies", "User Reports", [("Dependencies", None)], {}),
            ("projects_user_reports_scope_progress", "User Reports - Project Scope Progress", [("Project", None)], {}),
            ("user_reports_scope_progress_t3_activities", "User Reports - Project Scope Progress", [("T3 Activity", None)], {}),
            ("user_reports_scope_progress_t4_tasks", "User Reports - Project Scope Progress", [("T4 - Tasks", None)], {}),
            ("bim_form_elements_included", "BIM Form", [("Elements Included in Model", None)], {"mode": "value"}),
        ],
    },
    "transaction-tracker": {
        "generator": "generate_sql_transaction_tracker.py",
        "schema": ["sql/transaction_tracker/schema.sql"],
        "tables": {
            "Accounts": ("accounts", "ACCOUNTS_COLUMNS"),
            "Budget Code": ("budget_codes", "BUDGET_CODES_COLUMNS"),
            "Payor/Payee": ("payor_payee", "PAYOR_PAYEE_COLUMNS"),
            "Envelopes - Budget": ("envelopes_budget", "ENVELOPES_COLUMNS"),
            "Transactions": ("transactions", "TRANSACTIONS_COLUMNS"),
        },
        "attachments": {
            "accounts": ("Accounts", [attr("ACCOUNTS_ATTACHMENTS")]),
            "budget_codes": ("Budget Code", [attr("BUDGET_CODES_ATTACHMENTS")]),
            "transactions": ("Transactions", [attr("TRANSACTIONS_ATTACHMENTS")]),
        },
        "junctions": [
            ("envelopes_accounts", "Envelopes - Budget", [("Account", None)], {}),
            ("envelopes_budget_codes", "Envelopes - Budget", [("Budget Code", None)], {}),
            ("transactions_accounts", "Transactions", [("Account", None)], {}),
            ("transactions_envelopes", "Transactions", [("Envelope", None)], {}),
            ("transactions_budget_codes", "Transactions", [("Budget Code", None)], {}),
            ("transactions_transfer_source_accounts", "Transactions", [("Transfer Source Account", None)], {}),
            ("transactions_transfer_destination_accounts", "Transactions", [("Transfer Destination Account", None)], {}),
        ],
    },
    "project-estimator": {
        "generator": "generate_sql_project_estimator.py",
        "schema": ["sql/project_estimator/schema.sql"],
        "tables": {
            "Grid view - Active Status": ("employees", "EMPLOYEES_COLUMNS"),
            "User Reports": ("user_reports", "USER_REPORTS_COLUMNS"),
            "Projects": ("projects", "PROJECTS_COLUMNS"),
            "Activity Codes": ("activity_codes", "ACTIVITY_CODES_COLUMNS"),
            "Earn Codes": ("earn_codes", "EARN_CODES_COLUMNS"),
            "Grid view 2": ("earn_codes_v2", "EARN_CODES_V2_COLUMNS"),
            "Accounts": ("accounts", "ACCOUNTS_COLUMNS"),
            "Project Estimates": ("project_estimates", "PROJECT_ESTIMATES_COLUMNS"),
            "Holidays": ("holidays", "HOLIDAYS_COLUMNS"),
            "Bug Reports": ("bug_reports", "BUG_REPORTS_COLUMNS"),
        },
        "attachments": {
            "employees": ("Grid view - Active Status", [attr("EMPLOYEES_ATTACHMENTS")]),
            "project_estimates": ("Project Estimates", [attr("PROJECT_ESTIMATES_ATTACHMENTS")]),
        },
        "junctions": [
            ("employees_projects", "Projects", [("PM ID", "pm"), ("Project Members", "member"), ("Support Members", "support")], {}),
            ("employees_user_reports", "User Reports", [("Employee No.", None)], {}),
            ("projects_user_reports", "User Reports", [("Project", None)], {}),
            ("user_reports_activity_codes", "User Reports", [("Activity", None)], {}),
            ("user_reports_earn_codes", "User Reports", [("Earn Code", None)], {}),
            ("activity_codes_projects", "Activity Codes", [("Project/s", None)], {}),
            ("holidays_earn_codes_v2", "Holidays", [
                ("Earn Code", "base"), ("Earn Code OT", "ot"),
                ("Earn Code ND", "nd"), ("Earn Code ND + OT", "nd_ot"),
            ], {}),
        ],
    },
}


# ============================================================================
# Small parsing helpers
# ============================================================================

def parse_paren_list(text, i):
    """i points at '('; returns (items, index after ')'). Items are top-level comma splits."""
    assert text[i] == "("
    depth = 0
    start = i + 1
    items = []
    j = i
    while j < len(text):
        c = text[j]
        if c == "(":
            depth += 1
            if depth == 1:
                start = j + 1
        elif c == ")":
            depth -= 1
            if depth == 0:
                items.append(text[start:j].strip())
                return [x for x in items if x], j + 1
        elif c == "," and depth == 1:
            items.append(text[start:j].strip())
            start = j + 1
        j += 1
    raise ValueError("unbalanced parenthesis")


def parse_string(text, i):
    assert text[i] == "'"
    i += 1
    buf = []
    while i < len(text):
        c = text[i]
        if c == "\\":
            buf.append(text[i + 1])
            i += 2
            continue
        if c == "'":
            if i + 1 < len(text) and text[i + 1] == "'":
                buf.append("'")
                i += 2
                continue
            return "".join(buf), i + 1
        buf.append(c)
        i += 1
    raise ValueError("unterminated string literal")


def skip_trivia(text, i):
    """Skips whitespace and SQL comments (-- line, /* block */) -- legal anywhere spaces are."""
    while i < len(text):
        c = text[i]
        if c in " \t\r\n":
            i += 1
            continue
        if c == "-" and text.startswith("--", i):
            newline = text.find("\n", i)
            i = len(text) if newline == -1 else newline + 1
            continue
        if c == "/" and text.startswith("/*", i):
            end = text.find("*/", i + 2)
            i = len(text) if end == -1 else end + 2
            continue
        return i
    return i


def parse_value(text, i):
    i = skip_trivia(text, i)
    if i >= len(text):
        raise ValueError("unexpected end of file")
    c = text[i]
    if c == "'":
        return parse_string(text, i)
    for word, val in (("NULL", None), ("TRUE", True), ("FALSE", False)):
        if text.startswith(word, i):
            return val, i + len(word)
    j = i
    while j < len(text) and text[j] in "0123456789+-.eE":
        j += 1
    if j > i:
        raw = text[i:j]
        try:
            return (int(raw), j) if re.fullmatch(r"[+-]?\d+", raw) else (float(raw), j)
        except ValueError:
            pass
    raise ValueError(f"unexpected value near: {text[i:i+40]!r}")


def skip_to_semicolon(text, i):
    """Skips an INSERT ... SELECT ... statement up to and including the next unquoted ';'."""
    while i < len(text):
        i = skip_trivia(text, i)
        if i >= len(text):
            return i
        c = text[i]
        if c == "'":
            _, i = parse_string(text, i)
            continue
        if c == ";":
            return i + 1
        i += 1
    return i


def parse_data_sql(text):
    """Returns {sql_table: {"shapes": [{"cols": [...], "rows": [[values]], "select_only": bool}]}}

    One table usually has a single shape; hand-written files (separate.sql) may
    insert into the same table with different column lists, so each distinct
    column list is kept as its own shape.
    """
    tables = {}
    i = 0
    while True:
        pos = text.find("INSERT INTO", i)
        if pos == -1:
            return tables
        j = skip_trivia(text, pos + len("INSERT INTO"))
        k = j
        while k < len(text) and (text[k].isalnum() or text[k] == "_"):
            k += 1
        table = text[j:k]
        k = skip_trivia(text, k)
        if not table or k >= len(text) or text[k] != "(":
            i = pos + 1
            continue
        cols, k = parse_paren_list(text, k)
        k = skip_trivia(text, k)
        word = text[k:k + 6].upper()
        entry = tables.setdefault(table, {"shapes": []})
        if word == "SELECT":
            entry["shapes"].append({"cols": cols, "rows": [], "select_only": True})
            i = skip_to_semicolon(text, k)
            continue
        if word != "VALUES":
            i = pos + 1
            continue
        k += 6
        rows = []
        while True:
            k = skip_trivia(text, k)
            if k >= len(text):
                raise ValueError(f"{table}: VALUES never terminated")
            c = text[k]
            if c == ";":
                i = k + 1
                break
            if c == ",":
                k += 1
                continue
            if c != "(":
                raise ValueError(f"{table}: expected '(' near: {text[k:k+40]!r}")
            k += 1
            row = []
            while True:
                value, k = parse_value(text, k)
                row.append(value)
                k = skip_trivia(text, k)
                c = text[k] if k < len(text) else ""
                if c == ",":
                    k += 1
                    continue
                if c == ")":
                    k += 1
                    break
                raise ValueError(f"{table}: bad tuple near: {text[k:k+40]!r}")
            rows.append(row)
        shape = next((s for s in entry["shapes"] if s["cols"] == cols and not s["select_only"]), None)
        if shape is None:
            shape = {"cols": cols, "rows": [], "select_only": False}
            entry["shapes"].append(shape)
        shape["rows"].extend(rows)


def value_shapes(parsed, table):
    entry = parsed.get(table)
    if entry is None:
        return []
    return [s for s in entry["shapes"] if not s["select_only"]]


def parse_schema_columns(text):
    """Returns {table: set(column names)} from CREATE TABLE statements."""
    result = {}
    for m in re.finditer(r"CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?(\w+)`?\s*\(", text, re.I):
        table = m.group(1)
        # balanced scan of the body
        i = m.end() - 1
        depth = 0
        start = i + 1
        j = i
        while j < len(text):
            if text[j] == "(":
                depth += 1
            elif text[j] == ")":
                depth -= 1
                if depth == 0:
                    break
            j += 1
        body = text[start:j]
        cols = set()
        for line in body.splitlines():
            line = line.strip().strip(",")
            if not line:
                continue
            token = re.match(r"`?(\w+)`?", line)
            if token and token.group(1).upper() not in (
                    "PRIMARY", "FOREIGN", "UNIQUE", "KEY", "INDEX", "CONSTRAINT", "CHECK"):
                cols.add(token.group(1))
        result[table] = cols
    return result


# ============================================================================
# Generator introspection
# ============================================================================

def load_generator(base_cfg):
    path = SCRIPT_DIR / "generate" / base_cfg["generator"]
    spec = importlib.util.spec_from_file_location("gen_module", path)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def extractor_field_name(fn):
    """The Airtable field name an extractor factory closed over (text('X') -> 'X')."""
    for cell in (getattr(fn, "__closure__", None) or []):
        try:
            value = cell.cell_contents
        except ValueError:
            continue
        if isinstance(value, str):
            return value
    return None


def extract_pairs(columns):
    """COLUMNS list -> [(sql column, airtable field name, extractor fn)]"""
    pairs = []
    for sql_col, fn in columns:
        field = extractor_field_name(fn)
        if field is None:
            raise ValueError(
                f"cannot read the Airtable field name for column '{sql_col}' -- "
                "its extractor is not one of the text()/select()/... factories")
        pairs.append((sql_col, field, fn))
    return pairs


def expand_attachment_fields(module, items):
    fields = []
    for item in items:
        if item[0] == "attr":
            for name in getattr(module, item[1]):
                fields.append((name, "all"))
        else:
            fields.append((item[1], item[2]))
    return fields


def cells_match(expected, actual):
    if expected is None or actual is None:
        return expected is None and actual is None
    if isinstance(expected, bool):
        return isinstance(actual, bool) and expected == actual
    if isinstance(expected, (int, float)):
        return (isinstance(actual, (int, float)) and not isinstance(actual, bool)
                and float(expected) == float(actual))
    return str(expected) == str(actual)


# ============================================================================
# Checks
# ============================================================================

class Report:
    def __init__(self):
        self.failures = 0
        self.warnings = 0
        self.known = 0

    def fail(self, message):
        self.failures += 1
        print(f"  FAIL  {message}")

    def warn(self, message):
        self.warnings += 1
        print(f"  warn  {message}")

    def known_issue(self, message, note):
        self.known += 1
        print(f"  KNOWN {message}")
        print(f"        -> {note}")

    def pass_line(self, message):
        print(f"  ok    {message}")


def check_tables(cfg, export, parsed, module, report):
    print("-- tables: row counts and every mapped cell --------------------------------")
    configured_links = {(source, field_name)
                        for _sql, source, fields, _opt in cfg["junctions"]
                        for field_name, _extra in fields}
    for airtable_table, (sql_table, cols_attr) in sorted(cfg["tables"].items()):
        records = export["records"].get(airtable_table)
        if records is None:
            report.fail(f"{sql_table}: no export file/found for Airtable table '{airtable_table}'")
            continue
        pairs = extract_pairs(getattr(module, cols_attr))
        shapes = value_shapes(parsed, sql_table)
        if not records and not shapes:
            report.pass_line(f"{sql_table}: 0 records, no INSERT (nothing to write)")
            continue
        if len(shapes) != 1:
            report.fail(f"{sql_table}: expected exactly one INSERT shape in data.sql, found {len(shapes)}")
            continue
        shape = shapes[0]
        if [norm_col(c) for c in shape["cols"]] != ["airtable_record_id"] + [norm_col(c) for c, _, _ in pairs]:
            report.fail(f"{sql_table}: INSERT columns do not match the generator's COLUMNS list")
        if len(shape["rows"]) != len(records):
            report.fail(f"{sql_table}: Airtable {len(records)} records -> data.sql {len(shape['rows'])} rows")
            continue
        index = {norm_col(c): i for i, c in enumerate(shape["cols"])}
        mismatches = []
        for rec, row in zip(records, shape["rows"]):
            if row[index["airtable_record_id"]] != rec["id"]:
                mismatches.append((rec["id"], "airtable_record_id", rec["id"], row[index["airtable_record_id"]]))
                continue
            fields = rec.get("fields", {})
            for sql_col, _field, fn in pairs:
                expected = fn(fields)
                actual = row[index[norm_col(sql_col)]]
                if not cells_match(expected, actual):
                    mismatches.append((rec["id"], sql_col, expected, actual))
        if mismatches:
            report.fail(f"{sql_table}: {len(mismatches)} of {len(records) * (len(pairs) + 1)} cells differ")
            for rec_id, col, expected, actual in mismatches[:3]:
                report.warn(f"    {sql_table}.{col} [{rec_id}]: export {expected!r} -> data.sql {actual!r}")
        else:
            report.pass_line(f"{sql_table}: {len(records)} rows, {len(pairs)} columns, all values match")

    print("-- tables: Airtable fields that are not stored as a column ----------------")
    for airtable_table, (sql_table, cols_attr) in sorted(cfg["tables"].items()):
        records = export["records"].get(airtable_table)
        table_schema = export["schema"].get(airtable_table)
        if records is None or table_schema is None:
            continue
        mapped = {field for _, field, _ in extract_pairs(getattr(module, cols_attr))}
        att_fields = set()
        if sql_table in cfg.get("attachments", {}):
            att_table, att_items = cfg["attachments"][sql_table]
            if att_table == airtable_table:
                att_fields = {name for name, _ in expand_attachment_fields(module, att_items)}
        unmapped = []
        links = []
        for field in table_schema:
            if field["name"] in mapped or field["name"] in att_fields:
                continue
            if field["type"] in COMPUTED_TYPES:
                continue
            if field["type"] == "multipleRecordLinks":
                if (airtable_table, field["name"]) not in configured_links:
                    links.append(field["name"])
                continue
            unmapped.append(f"{field['name']} ({field['type']})")
        if unmapped:
            report.warn(f"{airtable_table}: {len(unmapped)} field(s) not stored as a column -- "
                        f"confirm each is intentional: {', '.join(unmapped[:6])}"
                        + (" ..." if len(unmapped) > 6 else ""))
        if links:
            print(f"  info  {airtable_table}: link fields without a junction: {', '.join(links)}")


def check_junctions(cfg, export, parsed, report):
    print("-- links: orphaned record IDs and junction row counts ----------------------")
    for sql_table, source, fields, options in cfg["junctions"]:
        records = export["records"].get(source)
        if records is None:
            report.fail(f"{sql_table}: source table '{source}' not in the export")
            continue
        mode = options.get("mode", "link")
        seen = set()
        orphans = []
        for rec in records:
            row = export["row_index"][source].get(rec["id"])
            if row is None:
                continue
            values = rec.get("fields", {})
            for field_name, extra in fields:
                for item in (values.get(field_name) or []):
                    if mode == "link":
                        target_table = export["link_targets"].get((source, field_name))
                        if target_table is None:
                            orphans.append(f"{field_name}: no linkedTableId in _base_schema.json")
                            continue
                        target_row = export["row_index"][target_table].get(item)
                        if target_row is None:
                            orphans.append(f"{field_name}: {item} not in '{target_table}'")
                            continue
                        key = (row, target_row, extra)
                    else:
                        key = (row, item, extra)
                    seen.add(key)
        expected = len(seen)
        actual = sum(len(s["rows"]) for s in value_shapes(parsed, sql_table))
        if orphans:
            report.fail(f"{sql_table}: {len(orphans)} dangling link(s), e.g. {orphans[0]}")
        if expected != actual:
            message = f"{sql_table}: export has {expected} distinct link row(s), data.sql has {actual}"
            if "known" in options:
                report.known_issue(message, options["known"])
            else:
                report.fail(message)
        elif not orphans:
            report.pass_line(f"{sql_table}: {expected} link rows match")


def check_attachments(cfg, export, parsed, module, export_dir, report):
    print("-- attachments -------------------------------------------------------------")
    shapes = value_shapes(parsed, "attachments")
    att_rows = [row for shape in shapes for row in shape["rows"]]

    def count_and_nulls(sql_table):
        counted = nulls = 0
        for shape in shapes:
            index = {norm_col(c): i for i, c in enumerate(shape["cols"])}
            table_i, url_i = index.get("table_name"), index.get("file_url")
            for row in shape["rows"]:
                if table_i is None or row[table_i] != sql_table:
                    continue
                counted += 1
                if url_i is not None and row[url_i] is None:
                    nulls += 1
        return counted, nulls

    for sql_table, (airtable_table, items) in sorted(cfg.get("attachments", {}).items()):
        records = export["records"].get(airtable_table)
        if records is None:
            report.fail(f"{sql_table}: attachment source '{airtable_table}' not in the export")
            continue
        fields = expand_attachment_fields(module, items)
        expected = 0
        for rec in records:
            values = rec.get("fields", {})
            for field_name, mode in fields:
                atts = values.get(field_name)
                if isinstance(atts, list) and atts:
                    expected += 1 if mode == "one" else len(atts)
        actual, _nulls = count_and_nulls(sql_table)
        if expected != actual:
            report.fail(f"attachments[{sql_table}]: export has {expected}, data.sql has {actual}")
        else:
            report.pass_line(f"attachments[{sql_table}]: {expected} rows match")

    if att_rows:
        nulls = sum(count_and_nulls(sql_table)[1] for sql_table, _ in cfg.get("attachments", {}).items())
        if nulls:
            report.warn(f"attachments: {nulls} row(s) have a NULL file_url "
                        "(fetch --no-images or the upload step did not cover them)")

    manifest_path = export_dir / "attachments_manifest.json"
    if not manifest_path.exists():
        if att_rows:
            report.warn("no attachments_manifest.json -- local attachment files cannot be checked")
        return
    manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
    failed = [m for m in manifest if m.get("status") == "failed"]
    if failed:
        report.fail(f"attachments: {len(failed)} download(s) failed, e.g. "
                    f"{failed[0]['table']}/{failed[0]['filename']}")
    missing = [m for m in manifest
               if m.get("local_path") and not (export_dir / m["local_path"]).exists()]
    if missing:
        report.warn(f"attachments: {len(missing)} downloaded file(s) missing on disk, e.g. {missing[0]['local_path']}")
    if not failed and not missing:
        report.pass_line(f"attachments manifest: {len(manifest)} file(s), all downloaded and present")


def check_schema(cfg, parsed, report):
    print("-- schema: every column data.sql writes must exist ---------------------------")
    schema_text = ""
    for relative in cfg["schema"]:
        path = REPO_ROOT / relative
        if path.exists():
            schema_text += path.read_text(encoding="utf-8")
        else:
            report.warn(f"schema file not found, skipping: {path}")
    columns = parse_schema_columns(schema_text)
    if not columns:
        report.warn("no CREATE TABLE statements found in the schema files")
        return
    for table, entry in sorted(parsed.items()):
        for shape in entry["shapes"]:
            if shape["select_only"]:
                continue
            if table not in columns:
                report.warn(f"{table}: INSERTs a table that is not in the schema files")
                continue
            unknown = [c for c in shape["cols"] if norm_col(c) not in columns[table]]
            if unknown:
                report.fail(f"{table}: data.sql writes column(s) the schema does not have: {', '.join(unknown)}")
            else:
                report.pass_line(f"{table}: {len(shape['cols'])} columns exist in the schema")


# ============================================================================
# Export loading
# ============================================================================

def load_export(export_dir):
    schema_path = export_dir / "_base_schema.json"
    if not schema_path.exists():
        sys.exit(f"ERROR: {schema_path} not found -- is this a fetch_airtable.py export directory?")
    raw_tables = json.loads(schema_path.read_text(encoding="utf-8"))
    schema = {}
    link_targets = {}
    id_to_name = {t["id"]: t["name"] for t in raw_tables}
    for table in raw_tables:
        schema[table["name"]] = table["fields"]
        for field in table["fields"]:
            if field["type"] == "multipleRecordLinks":
                target = field.get("options", {}).get("linkedTableId")
                link_targets[(table["name"], field["name"])] = id_to_name.get(target)

    records = {}
    row_index = {name: {} for name in schema}
    for path in sorted(export_dir.glob("*.json")):
        if path.name in ("_base_schema.json", "attachments_manifest.json", "cloudinary_uploads.json"):
            continue
        data = json.loads(path.read_text(encoding="utf-8"))
        if not isinstance(data, dict) or "records" not in data or "table_name" not in data:
            continue
        records[data["table_name"]] = data["records"]
        for i, rec in enumerate(data["records"], start=1):
            row_index.setdefault(data["table_name"], {})[rec["id"]] = i
    return {"schema": schema, "records": records, "row_index": row_index, "link_targets": link_targets}


def detect_base(cfg_bases, export):
    scores = {}
    for name, cfg in cfg_bases.items():
        wanted = set(cfg["tables"])
        present = sum(1 for t in wanted if t in export["records"])
        scores[name] = present / len(wanted)
    best = max(scores, key=scores.get)
    if scores[best] < 1.0:
        listing = ", ".join(f"{k}={v:.0%}" for k, v in sorted(scores.items()))
        sys.exit(f"ERROR: cannot tell which base this export is ({listing}). Pass --base explicitly.")
    return best


# ============================================================================
# Main
# ============================================================================

def main():
    parser = argparse.ArgumentParser(description=__doc__.splitlines()[1])
    parser.add_argument("--export-dir", required=True, help="Directory produced by fetch_airtable.py")
    parser.add_argument("--data-sql", required=True, help="data.sql produced by generate_sql_*.py")
    parser.add_argument("--base", choices=sorted(BASES), default=None,
                        help="Which base the export belongs to (default: auto-detect)")
    args = parser.parse_args()

    export_dir = Path(args.export_dir).resolve()
    data_sql_path = Path(args.data_sql).resolve()
    if not data_sql_path.exists():
        sys.exit(f"ERROR: {data_sql_path} not found")

    export = load_export(export_dir)
    base = args.base or detect_base(BASES, export)
    cfg = BASES[base]
    module = load_generator(cfg)

    print(f"verify_migration: base '{base}'")
    print(f"  export  : {export_dir}")
    print(f"  data.sql: {data_sql_path}")
    print()

    parsed = parse_data_sql(data_sql_path.read_text(encoding="utf-8"))

    report = Report()
    check_tables(cfg, export, parsed, module, report)
    check_junctions(cfg, export, parsed, report)
    check_attachments(cfg, export, parsed, module, export_dir, report)
    check_schema(cfg, parsed, report)

    print()
    verdict = "PASS" if report.failures == 0 else "FAIL"
    print(f"RESULT: {verdict} -- {report.failures} failure(s), {report.warnings} warning(s)"
          + (f", {report.known} known issue(s)" if report.known else ""))
    return 1 if report.failures else 0


if __name__ == "__main__":
    sys.exit(main())
