# Core database — actions and recycle

Shared log and trash for every product. The tables live in the **core** database (`ikaika_platform` locally, `eoxvhumy_ikaika_platform` on Bluehost), not in portal or estimator.

`product` on every row is the type: `portal` or `project-estimator` (catalog keys). A portal delete and an estimator delete can share the same `recycle_key` (for example a date) without colliding.

## Tables

| Table | Role |
|---|---|
| `actions` | Every write that changed a product database. Sync Airtable (or anything else) from here. |
| `recycle` | Snapshot of a deleted record, keyed so a later **add** of the same thing drops the trash. |
| `settings` | Per-product knobs. `recycle.retain_days` (default 30) sets `recycle.purges_at`. |

## When you change a product database

**If the code writes (INSERT / UPDATE / DELETE), it must also write core.** Reads do not.

| What happened | Call |
|---|---|
| New record | `CoreLedger::recordAdd` — also deletes recycle for that `product` + `recycle_key` |
| Existing record changed | `CoreLedger::recordEdit` |
| Record removed | `CoreLedger::recycleDeleted` — snapshot into recycle **and** an action of type `delete` |
| Restored from recycle | Re-insert the live record, then `recordAdd` — logs `add` and drops the recycle row. The original `delete` action stays. |

Helper: `app/Support/Core/CoreLedger.php`. Keys: `app/Support/Core/CoreRecycleKey.php`.

Skip this and Airtable (and restore) will miss the change.

## Recycle key

Knowable from the live record, not a random id.

Portal submitted report: `{employeeId}:{YYYY-MM-DD}-{kind}`  
Example: employee 12 deletes the daily report for 24 August 2026 → `12:2026-08-24-daily` with `product = portal`.

If that member later **adds** a daily report on the same day, `recordAdd` removes that recycle row. Restoring the old one would overwrite the new filing, so restore is refused while a live report occupies that key.

Estimator keys stay under `product = project-estimator`. Do not reuse portal key helpers there.

## Action JSON (`parameters`)

Readable object, no tokens, passwords, bank, tax, or identity documents.

```json
{
  "database_target": "portal.user_reports",
  "product": "portal",
  "action_type": "delete",
  "resource": "reports.submitted",
  "record_id": "2026-08-24-daily",
  "recycle_key": "12:2026-08-24-daily",
  "actor_id": 12,
  "lines": []
}
```

`action_type` is `add` | `edit` | `delete`. `synced_at` stays null until a sync job marks the row.

## Import

```bash
mysql -u root -proot --default-character-set=utf8mb4 < sql/core/schema.sql
```

Existing Bluehost schema: `php artisan migrate --force` (creates the tables on the `core` connection if they are missing). Do not run `DROP DATABASE`.
