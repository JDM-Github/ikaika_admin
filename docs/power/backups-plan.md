# All Backups: plan

Status: **parked until Airtable is retired** (user, 2026-09-29; expected before 2027). Written
2026-09-29. Nothing below is built yet.

While parked, the page is visible only in Developer mode and does nothing:

- every `administration.backup*` rule has `min_tier` `developer`, so no gift or limit reaches it
  (`separate.sql`, and migration `make_portal_backup_rules_developer_only`);
- the portal enforces the route (`RouteRuleConstant`);
- Create, Download and Restore are disabled under a "Backups are parked" notice.

With Airtable gone, section 9.3 (and the changed-only gap) falls away and restore gets simpler.

Lines marked `?` are open decisions. Each one carries a recommendation, so a "yes" is enough to
settle it. Section 14 lists them all in one place.

## 1. Where the page stands today

All Backups (Administration / All Backups) is a mock. It looks finished, but nothing behind it is real.

| Part | Today |
|---|---|
| The list | Six rows from a stand-in file (`ikaika-portal/src/apis/fixtures/backups.fixture.ts`). The endpoint it would call, `GET /backups`, does not exist on the server. |
| Create backup | Adds a row to memory for this session only. Nothing is written anywhere, and the notice says so. |
| Download | Shows "Nothing to download yet." |
| Restore | Shows "Restore is not wired up." |
| The four figures | Counted from the stand-in rows. |
| Access rules | The catalog already holds `administration.backup` (open the page), `.create`, `.download`, `.restore` and `.export` (download the list). Only the developer preset has them; admins and executives are left out on purpose (`sql/portal/separate.sql`). The page itself is not enforced yet: it still opens by role, and it is on the "Left" list in the power rules. |
| Server | No backup code, table, command or schedule exists. |

## 2. Why this page needs a plan first

- **Every backup is a full copy of the most sensitive data we hold.** The `employees` table carries
  bank name, account and SWIFT numbers, TIN, SSS, PhilHealth and HDMF numbers, date of birth, blood
  type, home address, personal email, phone and emergency contacts. A backup file that leaks is the
  same as all of that leaking.
- **Restore overwrites live data**, and that data feeds Airtable. Four synced tables send only the
  fields that changed (`requests`, `reimbursements`, `user_reports`, `employees`), so a restore that
  bypasses the ledger would leave Airtable silently wrong.
- **The schema has 80 `ON DELETE CASCADE` foreign keys** (54 in `schema.sql`, 26 in `separate.sql`).
  A careless delete during a restore can wipe rows far outside what was meant to be restored.
- **The server swaps release folders on every deploy** (`INSTALLATION.md`, steps 8 and 13). A backup
  kept inside the app's `storage/` would be left behind in the old release and deleted at cleanup.

## 3. What backups are for, and what they are not

Backups fill one gap: getting a **whole database back to a moment in time**. The portal already has
other safety nets, and backups do not replace them:

| Safety net | What it covers | Limit |
|---|---|---|
| Recycle bin (`core.recycle`) | One deleted record at a time, for 30 days | Not edits, not bulk damage |
| Action ledger (`core.actions`) | Who changed what, and when | A record, not a way back |
| Airtable | A mirror of four tables, plus Airtable's own snapshots | Not the other 60 tables |
| Bluehost account backups | Whatever the hosting plan includes | `?` Unknown: check what the plan covers and how far back it goes |
| **Backups (this plan)** | Every table, at chosen moments, restorable from the page | Files are not included (see below) |

**Not covered by this plan** (each needs its own answer, and none is urgent):

- Uploaded files. Receipts and other attachments live on Cloudinary (184 of 184 local attachment links
  point there). Profile folders live on SharePoint. A backup holds only their links.
- The Airtable bases, the WordPress site, the project estimator database (disabled), and the code
  (git already holds it).
- Secrets in `.env`. Keep those in the company password manager.

## 4. What a snapshot is

### 4.1 Which databases

| Database | Connection | Tables | Local size | In a snapshot | Restorable |
|---|---|---|---|---|---|
| Portal | `portal` | 64 | ~5.4 MB | Yes | Yes, by group (4.2) |
| Finance tracker | `tracker` | 13 | ~0.5 MB | Yes | Yes, as one group |
| Core platform | `core` | 13 | ~0.4 MB | `actions`, `recycle`, `workspace_views`, `settings` | **Never** (4.4) |
| Project estimator | `project_estimator` | - | - | No (disabled) | - |

Sizes are from the local database, which holds test data (20 employees). `?` Check the real sizes on
the server before settling retention. Even at ten times the size, a snapshot stays small.

### 4.2 What "Covers" means: table groups

The list's "Covers" column becomes a real choice: **Everything**, or one or more groups. Each group
is a fixed list of tables in one config file (`config/backups.php`), and a test fails if a table in
the database belongs to no group, so a new table can never be silently missed.

Proposed groups. `?` Confirm the table placement; it follows the table names.

| Group | Tables |
|---|---|
| People | `employees`, `employee_hierarchy`, `new_employee_data`, `warnings`, `employees_warnings`, `achievements_milestones` |
| Projects | `projects`, `clients`, `projects_clients`, `employees_projects`, `project_scopes`, `project_scopes_assigned_to`, `project_scopes_t3_activities`, `project_scopes_t4_tasks`, `project_scope_t1_processes`, `project_scope_t2_procedures`, `project_scope_t3_activities`, `project_scope_t4_tasks`, `t1_processes_t2_procedures`, `t3_activities_t4_tasks`, `t4_task_dependencies`, `projects_project_scopes`, `projects_activity_scope`, `employees_project_scopes`, `project_action_history`, `projects_action_history`, `bim_form`, `bim_form_elements_included` |
| Reports | `user_reports`, `employees_user_reports`, `projects_user_reports`, `user_reports_activity_codes`, `user_reports_earn_codes`, `user_report_dependencies`, `user_reports_scope_progress`, `user_reports_scope_progress_t3_activities`, `user_reports_scope_progress_t4_tasks`, `projects_user_reports_scope_progress` |
| Requests | `requests`, `projects_requests`, `requests_activity_codes`, `requests_earn_codes`, `reimbursements`, `employees_reimbursements` |
| Codes | `activity_codes`, `earn_codes`, `job_type_allowed_activities` |
| Attachments | `attachments` (the links only) |
| Calendar | `calendar_events`, `calendar_event_members`, `calendar_event_departments` |
| Email | `email_blocks` (templates and footers). The outbox, `email_messages`, is history: backed up, never restored |
| Access rules | `access_presets`, `access_personal`, `access_grants`, `access_limits`, `access_toggles`, `access_self_reach`, `access_lists`. Opt-in on restore only, never part of an Everything restore unless ticked, because an old copy can take powers away |
| Finance | every `tracker` table |
| History | `logs`, `notifications`, `email_messages`. Backed up for the record, **never restored** |
| Never restored | `access_permissions` (the catalog belongs to `separate.sql` and migrations), `developer_modes` (live session state) |

### 4.3 The file

- **Format**: one zip per snapshot, holding a `manifest.json` and one JSON-lines file per table.
  - The manifest holds: snapshot id, kind, covers, who took it, when (UTC and Manila time), app
    version, the migrations applied (the schema signature), every table's columns, row counts, and a
    SHA-256 of each table file.
  - JSON rows, not an SQL dump, because a restore then never *executes* anything read from the file:
    it reads values and writes them through the query builder. A tampered file cannot run SQL.
  - For disaster recovery (the server is gone), a command turns a snapshot into a plain `.sql` file
    that phpMyAdmin or the `mysql` client can load (8.1).
- **Taken without `mysqldump`.** Bluehost shared hosting often blocks shell commands, so PHP reads the
  rows itself, table by table, streaming to the zip.
- **Consistent**: each database is read inside one `START TRANSACTION WITH CONSISTENT SNAPSHOT`, so
  every table in it comes from the same instant. Across databases the instants differ by a second or
  two; that is acceptable and the manifest says so.
- **Encrypted at rest** with its own key, `PORTAL_BACKUP_KEY` (libsodium secretstream, or OpenSSL
  AES-256-GCM if sodium is missing on the server).
  - Not `APP_KEY`: that key also signs sessions and the JWT fallback, and rotating it would make
    every backup unreadable.
  - `?` Keep a copy of the backup key in the company password manager. **If the key is lost, every
    backup is lost with it.**
- **Checked**: the SHA-256 of the whole archive is stored with its row, and is re-checked before any
  download or restore. A mismatch refuses the action and marks the snapshot damaged.

### 4.4 Why core is never restored

The core database holds the ledger that feeds Airtable, the recycle bin, and (new) the backup list
itself. Restoring it would:

- mark actions that already reached Airtable as unsent, so they would be sent twice;
- bring back recycle entries that were restored or purged;
- erase the record of every snapshot taken after that one.

Core is copied into snapshots for investigation only.

## 5. Where snapshots are kept

- **On the server, outside the app and outside `public_html`**: a folder such as
  `/home2/eoxvhumy/backups/central-platform/`, set by `PORTAL_BACKUP_PATH`.
  - It survives deploys, because it is not inside a release folder.
  - The server refuses to start backups if the path is missing, not writable, or inside
    `public_html`.
- **The list lives in the core database** (`core.backups`, section 10.1), not in portal. A portal
  restore therefore never erases the record of newer snapshots.
- **An off-site copy**, `?` recommended. A copy on the same server dies with the server or the hosting
  account. Options, best first:
  1. Microsoft 365 (SharePoint or OneDrive) through Microsoft Graph. The company already has an Azure
     app for sign-in, but this needs an M365 admin to grant file access (`Sites.Selected`).
  2. Cloudinary as a private raw file. It is already set up, and the file is encrypted, so Cloudinary
     only ever sees ciphertext.
  3. Someone downloads a snapshot by hand every week. It is free, but easy to forget.
- **Retention**, `?` recommended:
  - automatic daily snapshots kept 14 days;
  - the Sunday one kept 12 weeks;
  - the first of the month kept 12 months;
  - manual snapshots kept until deleted;
  - pre-restore snapshots kept 90 days.

  Pruning runs after every scheduled snapshot and never deletes the newest good one.
- **Disk space**: `?` check the account's disk quota. At today's size, a year of the schedule above
  is well under 100 MB.

## 6. Scheduled snapshots

- A command, `php artisan portal:backup --scheduled`, takes an Everything snapshot and prunes.
- **Run by a daily Bluehost cron job**, at 02:00 Manila time, calling the command directly. The old
  portal already runs PHP cron scripts on this account (`old_portal/scripts/`), so cron is available.
  - `?` The exact PHP binary path, and the server's own timezone (cron runs in server time).
  - Using Laravel's scheduler instead needs an every-minute cron, which some shared plans limit.
- **When one fails**: the row shows Failed with a plain reason. Developers get a bell notice, and the
  MIS manager gets an email through the existing mail bridge.
- **Freshness**: the page shows "Last good backup" and turns it into a warning when the newest good
  snapshot is more than 36 hours old. A silent cron failure then shows up the next time someone looks.
- One backup or restore runs at a time, under a lock. A scheduled run that finds the lock taken waits
  and retries once, then records a skipped run.

## 7. Manual snapshots (Create backup)

- A small dialog with two fields:
  - **Covers**: Everything, or tick groups;
  - **Note**, optional, up to 200 characters, e.g. "before the September import".
- It runs inside the request. At this size it takes seconds; the time limit is raised for that
  request only. It is refused while another backup or restore is running.
- Throttled, e.g. 10 per hour per person.
- Logged in All Logs as "You took a backup (Everything)". It changes no product data, so nothing goes
  to the ledger or Airtable.

## 8. Download

- **Who**: `administration.backup.download`, and Developer mode must be on, as it is for global email
  images. `?` Confirm the Developer mode requirement.
- **What they get**: a password-protected zip (AES-256).
  - The person types a password in the dialog, or the page generates one and shows it once.
  - The server decrypts its own copy and repacks it under that password, so the file on a laptop is
    never plain.
  - PHP's `ZipArchive` supports AES zip encryption. `?` Check that the server's libzip does too; the
    zip extension is on locally.
- **Optional masked copy**, `?` recommended for later: the same data with the sensitive employee
  columns (section 2) blanked, for handing to someone who is debugging and should not see pay or
  government ids.
- **How it arrives**: as the response body, through the API client with a binary response type, then
  saved with the portal's existing `FileExportManager`. There is no link with a token in the URL, so
  nothing lands in browser history or server logs.
- **Every download is logged**, and the MIS manager and developers get a bell notice ("A snapshot
  from 9/29 was downloaded"). Downloads are rare, so each one should be visible.
- Throttled, e.g. 5 per hour per person.

### 8.1 Disaster recovery (the server is gone)

- `php artisan portal:backup-sql <file> --key=...` turns a downloaded snapshot into a plain `.sql`
  file, run on any machine with the code.
- Rebuild order on a new server: `schema.sql`, then `separate.sql`, then migrations, then the snapshot
  `.sql`, which holds data only.
- This is the one path that runs SQL from a file, and it runs by hand, off the portal.

## 9. Restore

This is the dangerous part. Every rule below is there because skipping it loses data.

### 9.1 The flow

1. **Choose** a snapshot and what to restore: Everything, or groups. Access rules only when ticked;
   History and core never.
2. **Preview (a dry run)**: the server reads the snapshot and compares it with the live tables,
   changing nothing. Per group and per table it shows how many rows it would **add**, **change** and
   **remove**, and it lists every warning (9.4).
3. **Confirm** by typing the snapshot's date (e.g. `2026-09-29`). The button stays disabled until the
   typed date matches.
4. **Safety snapshot**: before touching anything, the server takes an automatic "Before restore"
   snapshot of everything, and the restore stops if that fails. A mistaken restore is undone by
   restoring the safety snapshot.
5. **Freeze writes**: every portal write route answers 503 "The portal is being restored, try again
   in a minute", and a notice shows in the shell for everyone. Reads keep working.
6. **Apply** per database, in one transaction each (9.2).
7. **Record**: ledger actions for synced tables (9.3), a log entry, a bell notice to developers and the
   MIS manager, and a restore row with the counts.
8. **Clear caches**: bump `PortalCache`, flush the application cache, then lift the freeze.

### 9.2 How rows are applied

- **By primary key, as a comparison**:
  - rows missing from the live table are inserted;
  - rows that differ are updated;
  - rows that exist only live are deleted.

  This keeps untouched rows untouched, and it produces the exact list of changes the ledger needs.
- **Only `INSERT`, `UPDATE` and `DELETE`, never `DROP` or `TRUNCATE`.** DDL commits on its own in
  MySQL, so any DDL would break the one-transaction guarantee. With DML only, InnoDB rolls back
  everything if one row fails.
- **Foreign keys stay on.** Deletes run children before parents and inserts parents before children,
  in an order worked out from the schema.
  - Before applying, the preview checks every cascade the deletes would trigger. A restore that
    would cascade into a table **outside** the chosen groups is refused, with the table and count
    named. Example: restoring only Projects would remove a newer project, and the cascade would wipe
    its reports.
  - An Everything restore cannot hit this.
- **Schema changes since the snapshot**: the manifest's schema signature is compared with today's.
  - A column added since then gets its default, and the preview says so.
  - A column or table removed or renamed since then refuses the restore, naming it.
  - A table that did not exist then is left alone.
- `?` **Rows a restore removes**: recommended to rely on the safety snapshot rather than putting each
  removed row in the recycle bin, which could mean thousands of bin entries.

### 9.3 Airtable

SQL is the source of truth, and Airtable is kept in line through the ledger. A restore must speak
the ledger's language:

- For every row it adds, changes or removes in a synced table, the restore writes the same ledger
  action a normal add, edit or delete of that record writes. The parameters are marked
  `{"source": "backup-restore", "backupId": ...}`. The next `portal:airtable-sync --send` then brings
  Airtable back in line: patches, creates and deletes.
- Ledger rows and data rows are written together through `CoreLedger::within`, so a restore can never
  land unlogged. Actions are marked `as_developer`, since restore needs Developer mode.
- **A known gap**: the four changed-only tables (`requests`, `reimbursements`, `user_reports`,
  `employees`) cannot rebuild a record that was deleted in Airtable itself. Today's sync reports those
  as blocked. The preview lists every restored row whose Airtable record is gone, so someone can fix it
  in Airtable by hand.
- **Before a restore**, run the sync (or confirm it has nothing pending), so the only unsent actions
  afterwards are the restore's own.
- `?` **Is anyone still editing Airtable directly?** If so, both a restore and every normal sync
  overwrite those edits. It is not new, but a restore makes it bigger.

### 9.4 Warnings the preview must show

- Anyone whose role, `is_developer`, status or department would change. A restore can take access
  away mid-session, including from the person restoring.
  - Recommended: refuse a restore that would remove the restoring person's own developer flag.
- Any Airtable record that is gone (9.3).
- Cascades into other groups (9.2); these block, not just warn.
- Added or removed columns since the snapshot (9.2).
- Pending, unsent ledger actions.
- Snapshots older than 90 days: an extra line saying how old it is.

### 9.5 Who may restore

- `administration.backup.restore`, and Developer mode on.
- `?` **A second person's approval?** Recommended not for the first version. Instead, notify every
  developer, the MIS manager and the executives by bell and email the moment a restore starts and
  ends. Add approval later if the notices feel too thin.
- Throttled to one restore at a time, under the same lock as backups.
- There is also a command-line restore with `--dry-run`, for when the portal itself cannot load.

## 10. Server design (ikaika_admin)

### 10.1 Tables

These are core tables, so they go through a core migration plus `sql/core/schema.sql`, not
`separate.sql` (backend conventions).

`core.backups`

| Column | Meaning |
|---|---|
| `id`, `uuid` | The uuid names the file; the id is never exposed in a path |
| `kind` | `scheduled`, `manual`, `pre_restore` |
| `covers` | JSON list of groups, or `["everything"]` |
| `note` | Optional, 200 chars |
| `status` | `running`, `ready`, `failed`, `damaged`, `pruned` |
| `file_name`, `size_bytes`, `sha256` | The encrypted archive |
| `schema_signature` | A hash of the migrations and columns at the time |
| `row_counts` | JSON: table to rows |
| `created_by`, `created_by_id_no`, `as_developer` | Null for scheduled runs; named like All Logs names a developer |
| `error` | A plain reason when failed, with no paths or secrets |
| `offsite_at` | When the off-site copy landed |
| `created_at`, `finished_at` | |

`core.backup_restores`

| Column | Meaning |
|---|---|
| `id`, `backup_id`, `safety_backup_id` | What was restored, and the snapshot taken just before |
| `covers`, `counts` | Groups chosen; JSON of add, change and remove per table |
| `restored_by`, `restored_by_id_no` | |
| `status`, `error`, `started_at`, `finished_at` | |

### 10.2 Code

| Piece | Job |
|---|---|
| `config/backups.php` | Path, key name, retention, the groups (4.2), what is never restored |
| `PortalBackupCatalog` | Groups to tables, and the test that every table has a group |
| `PortalBackupVault` | The folder, encryption, checksums, refusing a bad path |
| `PortalBackupWriter` | Consistent reads, streaming rows to the zip, the manifest |
| `PortalBackupRestorer` | Preview, the cascade check, apply, ledger rows |
| `PortalBackups` | The service the controller calls: list, create, download, preview, restore, audit, notices |
| `BackupsController` | The HTTP layer |
| `PortalRestoreFreeze` middleware | The 503 on writes during a restore |
| Commands | `portal:backup {--scheduled} {--covers=}`, `portal:backup-prune`, `portal:backup-verify`, `portal:backup-restore {id} {--dry-run}`, `portal:backup-sql {file}` |

### 10.3 Routes

These go in the portal routes under `administration`, each behind its rule and a throttle:

| Route | Rule |
|---|---|
| `GET administration/backups` (list, figures, last good backup) | `administration.backup` |
| `GET administration/backups/{uuid}` (manifest: counts per table) | `administration.backup` |
| `POST administration/backups` (create) | `administration.backup.create` |
| `POST administration/backups/{uuid}/download` (password in the body) | `administration.backup.download` + Developer mode |
| `POST administration/backups/{uuid}/restore-preview` | `administration.backup.restore` + Developer mode |
| `POST administration/backups/{uuid}/restore` (typed date in the body) | `administration.backup.restore` + Developer mode |
| `DELETE administration/backups/{uuid}` | `?` a new `administration.backup.delete`, or leave deleting to retention only |

### 10.4 Environment

- `PORTAL_BACKUP_PATH`: the folder (5).
- `PORTAL_BACKUP_KEY`: the key (4.3). Never logged, never shown.
- The off-site settings, once one is chosen.

Add them to `INSTALLATION.md`'s environment section, with a deploy step that checks the folder
exists.

## 11. Portal design (ikaika-portal)

- **Go live**:
  - delete `src/apis/fixtures/backups.fixture.ts` and its `source` seam in `bootstrap.ts`;
  - move the endpoint to `apis/endpoint/administration/backups/{get,post,delete}/`, one file per
    route in 10.3.
- **Model**:
  - `createdAt` becomes a full timestamp, since the page should show the time, not just the date;
  - add `kind`, `status`, `covers` (a list), `note`, and the developer naming rule;
  - the size stays a byte count.
- **Figures**: Snapshots, Scheduled, Manual and Total size stay. Add **Last good backup**, with its
  36-hour warning (6).
- **Create**: a dialog with Covers (multi-select, Everything by default) and Note.
- **Row actions**:
  - **Download** opens a password dialog;
  - **Restore** opens a sheet: groups, preview table, warnings, typed-date confirm, progress, result;
  - a row click opens a detail sheet with the manifest counts per group and table.
- **Rows** show Running, Failed and Damaged with their reason.
- **Freeze notice**: a shell notice for everyone while a restore runs.
- **Binary download**: `ApiManager` gains a binary response option; `FileExportManager.save` writes
  the zip.
- **Rules**:
  - add the backup routes to `RouteRuleConstant.enforced` and "All Backups" to `enforcedPageNames`;
  - the controller gets `canCreate`, `canDownload`, `canRestore` and `canExport` from `holdsRule`;
  - Export goes through `DataTableShared canExport`.

## 12. Who holds what

Today the developer preset holds all five rules, and nobody else has any. Recommended, `?`:

| Rule | Who | Developer mode needed |
|---|---|---|
| Open the page, Export the list | Developers, plus the MIS manager (Nathaniel, 220202-0002) as a personal default | No |
| Create backups | Same | No |
| Download backups | Developers only | Yes |
| Restore backups | Developers only | Yes |
| Delete a snapshot (if added) | Developers only | Yes |

Executives keep the current exclusion (housekeeping pages). They get the restore notices (9.5)
instead of the buttons.

## 13. Order of work

Each phase ends with the usual checks: pint, `php artisan test`, typecheck, lint, the portal tests,
graphify, and the leak checks in 13.1.

**Phase 0: settle and verify** (no code)
- Answer the `?` items in section 14.
- Check on the server:
  - PHP extensions: `zip` with AES, `sodium`, `zlib`;
  - cron is available, and the PHP binary path;
  - a writable folder outside the release;
  - the disk quota and the server's timezone;
  - what Bluehost's own backups cover;
  - the real table sizes.

**Phase 1: real snapshots**
- `core.backups`, catalog, vault, writer.
- List, detail, manual create, download.
- Rule enforcement for the page, create, download and export.
- The fixture removed.
- Done when a snapshot taken on the page downloads, opens with its password, and its row counts match
  the live tables.

**Phase 2: schedule and safety**
- The command, cron, retention and pruning.
- Failure notices, "Last good backup", verify, and the off-site copy.
- Done when a week of daily runs is listed and the oldest are pruned as configured.

**Phase 3: restore**
- Preview, the cascade check, safety snapshot, freeze, apply, ledger actions, cache clear.
- `core.backup_restores`, the CLI restore and `portal:backup-sql`.
- Done when a restore of Everything puts a copy database back exactly, and the next Airtable dry run
  lists exactly the rows that changed.
- Rehearse it on a copy of production data before anyone uses it on the live server.

**Phase 4: optional**
- The masked download, and a second person's approval for restore.

### 13.1 Test rules for this work

- Tests point `PORTAL_BACKUP_PATH` at a temporary folder and delete it afterwards. They never touch
  the real backup folder.
- Restore tests transact `portal`, `core` and `tracker`, so no ledger row reaches Airtable. Check
  `core.actions` max id and portal `logs` before and after every run.
- The cascade check, the schema-drift refusal, the typed-date refusal, a checksum mismatch, the freeze
  and "never restore core" each get a test.
- One test runs a snapshot and restore round trip and compares every table row by row.

## 14. Open decisions

Each has a recommendation; a "yes" settles it.

1. **Bluehost's own backups** (3): find out what they cover. Nothing is decided without it.
2. **Group placement** (4.2): confirm the proposed groups.
3. **Backup key in the password manager** (4.3): yes.
4. **Off-site copy** (5): Microsoft 365 through Graph if an M365 admin will grant `Sites.Selected`;
   otherwise encrypted files on Cloudinary.
5. **Retention** (5): 14 daily, 12 weekly, 12 monthly; manual kept; pre-restore 90 days.
6. **Schedule** (6): daily at 02:00 Manila, by a direct cron line.
7. **Developer mode for download and restore** (8, 9.5): yes.
8. **Masked download** (8): later, in Phase 4.
9. **Removed rows** (9.2): rely on the safety snapshot, not the recycle bin.
10. **Direct Airtable edits** (9.3): confirm nobody edits the four synced tables in Airtable.
11. **Second-person approval for restore** (9.5): not in the first version; notify everyone instead.
12. **Deleting a snapshot by hand** (10.3): leave it to retention at first; add
    `administration.backup.delete` only if someone asks.
13. **Who holds the rules** (12): as in the table.

## 15. Related

- The power rules in general, and the checklist to enforce a page: `../ikaika-portal` context,
  `power-rules.md`; `FINE_GRAINED_RULES.md` in this repo.
- The deploy steps: `INSTALLATION.md` (environment, sections 5 to 13).
- The ledger and sync: `app/Support/Core/CoreLedger.php`, `app/Support/Airtable/PortalAirtableSync.php`,
  `AirtableSyncMap.php` (the changed-only tables).
- **Handoff**: Missing Reports and the raw table API belong to the other developer. Backups include
  their tables like any other, but this work changes none of their code. The raw table API's
  exposure of personal data is unrelated to this plan, and stands as documented in
  `FINE_GRAINED_RULES.md`.
