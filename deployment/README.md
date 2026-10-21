# Deploying the admin (Laravel API) to Bluehost

Target: the **Test Server** WordPress site on Bluehost. The API and the portal live in subfolders of it. Staging (`ikaikabim.com/staging/central-api`, `~/apps/central-platform`) is not touched.

| What | URL |
| --- | --- |
| Admin workspace (backend UI) | `https://test.ikaikabim.com/central-api/` |
| Health check | `https://test.ikaikabim.com/central-api/api/staging/portal/health` |
| Portal (deployed from `ikaika-portal`) | `https://test.ikaikabim.com/portal` |

## Day to day

From the repo root in PowerShell. Each is a toolkit command (`.\script.ps1 <command> -Help` says what it does) over the script in this folder, which still runs on its own as `deployment\<script>.ps1`:

| Task | Command |
| --- | --- |
| **Deploy everything** (settings, admin, then portal) | `.\script.ps1 deploy/test-server` |
| Deploy the admin | `.\script.ps1 deploy/admin` |
| Send your local secret settings to the server | `.\script.ps1 deploy/push-env` (`-Only JIRA_API_TOKEN` for one key) |
| Copy your local databases to the Test Server (**replaces its data**) | `.\script.ps1 deploy/copy-databases` |
| Copy just one database | `.\script.ps1 deploy/copy-databases -Only portal` (or `core`, `tracker`, `estimator`) |
| **Reset the Test Server databases, then deploy everything** (wipes every table, loads your local databases, backs up the old data on the server) | `.\script.ps1 deploy/test-server -ResetDatabases` (asks for RESET; `-Yes` skips the question) |
| Check a server's migrations and health | `.\script.ps1 deploy/check-health` (`-Target production`) |
| Type the SSH passphrase once for the session | `.\script.ps1 deploy/ssh-key` |
| Pull Airtable into SQL, check it, adopt it, reset local | `.\script.ps1 airtable/migrate-all`, `airtable/verify`, `airtable/copy-to-sql`, `airtable/reset-local` |
| Change a server setting | see [Changing a setting](#changing-a-setting) |

Without `deploy/ssh-key` each command asks for the SSH key passphrase twice, once for the upload and once for the server step; see also [Optional: passphrase once](#optional-passphrase-once).

**Running unattended** (a hook, CI, an agent): set `IP_SCRIPTS_NO_GUI=1`, `IP_SCRIPTS_NO_PROMPT=1` and `IP_SCRIPTS_NO_UPDATE=1`. Nothing then waits on a question: a command that would ask - a database reset, anything on production - needs `-Yes` or stops with exit 1 saying so, SSH must already answer without a passphrase (`deploy/ssh-key` first, or it stops), the Airtable commands need `migrations\.token.private.txt` or `IP_AIRTABLE_TOKEN`, and `devtools/console`, a window, refuses. On GitHub, **Maintenance** (`.github/workflows/maintenance.yml`) runs a health check or pushes the settings this way.

**Or use the window:** `.\script.ps1 devtools/console`, or double-click `devtools\Ikaika Console.bat` (repo root). It runs these and the `migrations/` actions one at a time with live output and exit codes — including "Reset server + load local (clean)", which is `copy-databases.ps1 -Wipe`, and "Deploy all + DB reset", which is `deploy-all.ps1 -ResetDatabases`.

A **Production** section (type `PROD` to confirm) adds "Load Airtable export -> prod DBs" (`import-airtable-to-prod.ps1 -Yes`) and "Deploy all prod" (`deploy-all-prod.ps1 -Force`). Production is served at **portal.ikaikabim.com/v2** (the API at `.../central-api`). Note the DB action loads the **Airtable export** in `migrations/outputs/` and **drops + recreates** the production databases — it does not push your local databases (that is Test Server only).

**Deploy order:** admin first, then the portal (`ikaika-portal\deployment\deploy.ps1`), because the portal calls the admin. `deploy-all.ps1` does exactly that, after sending the settings (`-SkipSettings` leaves them out, `-SkipBuild` reuses the last builds). It expects the portal repo next to this one (`ikaika-portal`, or `ikaika_portal_frontend_rewrite`); `-PortalRepo` points the script elsewhere.

**Reset (`-ResetDatabases`)**: for when the Test Server's schema has drifted from what works locally -- a table, column or permission rule that only ever existed as hand-run SQL. It asks you to type RESET, then runs `copy-databases.ps1 -Wipe`: each server database is backed up to `backups/databases-import-<stamp>/` (the newest two kept), every table and view is dropped, and your local dump is loaded, migrations table included, so the deploy's `migrate` then only runs what is newer than your local database. Test Server only: these scripts never reach production.

**What `deploy.ps1` does:**
1. Runs `build.ps1`: builds CSS and fonts for the subfolder and packs `out/central-platform.zip`.
2. Uploads the zip and the server scripts.
3. On the server, `deploy-admin.sh` unpacks the zip, runs `composer install`, dumps the default, portal and tracker databases to `backups/databases-<stamp>/` (a failed dump stops the deploy before anything changes), and swaps in the new release.
4. It then migrates, applies the tracker patches (`sql/transaction_tracker/patches/*.sql`, safe to rerun), adds the every-minute scheduler cron if it is missing (it also works the mail queue: `QUEUE_CONNECTION=database` is written into the release's `.env`, and without the cron queued email never leaves), republishes the public folder, and prunes old backups.
5. **Migration health checks** fail the deploy, printing the exact rollback commands (code, public folder, and each database dump): `migrate:status --pending` must show nothing left, and `/health` must answer 200 (the portal database reachable). `deploy.ps1` then stops with "Deploy failed on the server".

Expected last lines:

```text
{"product":"portal","health":{"ok":true,"database":"eoxvhumy_ts_portal","error":null}}
Done. Previous release kept in /home2/eoxvhumy/apps/test-server/backups
```

## Where things live on the server

Everything for the Test Server sits in one folder. Nothing is loose in the home directory.

```text
/home2/eoxvhumy/apps/test-server/
├── app/        the running admin: Laravel code and vendor/ (Composer installs it here on each deploy)
├── .env        admin settings (deploy copies it into app/.env)
├── db.env      database credentials, generated by create-databases.sh, never typed
├── scripts/    server scripts, refreshed on every deploy
├── upload/     zips and dumps land here and are deleted after each run
└── backups/    the two previous admin and portal releases, pruned automatically

/home2/eoxvhumy/public_html/website_a85fbf18/
├── central-api/   public part of the admin: index.php, .htaccess, build/ (no code, no vendor)
└── portal/        the portal web build
```

Databases: `eoxvhumy_ts_core`, `eoxvhumy_ts_portal`, `eoxvhumy_ts_tracker`, `eoxvhumy_ts_estimator`, all owned by the user `eoxvhumy_ts`. The portal's holiday calendar reads the estimator database.

## What is in this folder

| File | Runs on | Does |
| --- | --- | --- |
| `deploy-all.ps1` | your PC | settings, admin, then portal in one go |
| `deploy.ps1` | your PC | build, upload, deploy the admin |
| `push-env.ps1` | your PC | sends the secret settings from your local `.env`, replacing the server's lines, then applies them |
| `copy-databases.ps1` | your PC | dump local databases, upload, create any missing database, import |
| `build.ps1` | your PC | builds `out/central-platform.zip` only |
| `dump-local-databases.ps1` | your PC | dumps local databases to `out/db/*.sql` only |
| `server/deploy-admin.sh` | server | install, swap, migrate, tracker patches, scheduler cron, publish, prune |
| `server/merge-env.sh` | server | replaces the keys `push-env.ps1` sends in `test-server/.env`, then runs `apply-env.sh` |
| `server/create-databases.sh` | server | creates the databases and user through cPanel `uapi`; safe to rerun |
| `server/import-databases.sh` | server | loads the uploaded dumps, then deletes them |
| `server/setup-env.sh` | server, once | builds `test-server/.env` from staging's |
| `server/apply-env.sh` | server | applies a `.env` edit to the running admin |
| `server/tidy-server.sh` | server, once | moved the first-day layout into `apps/test-server`; kept only for reference |
| `server/.htaccess` | (in the zip) | stock Laravel rewrite rules; the repo's `public/` has none |
| `server/env.test-server.example` | (reference) | every `.env` key the server needs |

`out/` is gitignored.

## The shared folder (Dropbox)

One Dropbox folder, shared by link, holds what a new PC or a workflow needs and the repo must not: the link is the `SHARED_FILES_URL` GitHub secret and `share.folder` in `ikaika_scripts.env`. Both portal repos use the same folder and the same `SHARED_FILES_PASSWORD`.

| Upload | Made by | Holds |
| --- | --- | --- |
| `portal-backend.7z` | `.\script.ps1 secrets -Seal` here | `.env`, `debug.env`, `release.env`, the Bluehost SSH key and `production-settings.env`, AES-256 with the file names hidden |
| `portal-frontend.7z` | `.\script.ps1 secrets -Seal` in the portal | the portal's `.env`, deploy configs, SSH key, Android keystore and Windows certificate with their passwords |
| `android-release.keystore`, `android-debug.keystore`, `windows-release.pfx`, `windows-debug.pfx`, `linux-*.asc(.rev)` | `.\script.ps1 certificates -Collect` in the portal, into its `share-upload/` | each checked on arrival against the `.sha256` committed beside its path; useless without its password, which is only in the archive |

**Never in it:** database dumps (`deployment/out/db`) and Airtable exports (`migrations/outputs`, `export_*`), which hold real people's data; this PC's state (`.servers.json`, `.sql-applied.json`, `sql/status.txt`, `.ikaika-install`); a server's own `.env` or `db.env`; `.local.actions-secret.env`; and any loose private key - the archive carries the SSH key.

After changing what is sealed (`.ikaika_scripts_configs/secrets.json`), run `secrets -Seal` again and replace the archive in the folder.

---

## Changing a setting

Edit the server copy, then apply it to the running admin:

```powershell
ssh -t bluehost "nano ~/apps/test-server/.env"
ssh bluehost "bash ~/apps/test-server/scripts/apply-env.sh"
```

`apply-env.sh` rebuilds `app/.env` from `.env` plus `db.env` and refreshes Laravel's config cache. It ends with `Applied. Mailer: ...`. Don't copy `.env` into `app/` by hand, because that would drop the database lines.

**Email** goes through the WordPress bridge on ikaikabim.com (`MAIL_MAILER=wprelay`), the same as local. That route arrives in Outlook without the "can't verify sender" banner.

**Secret settings from your local `.env`:** `push-env.ps1` (also the first step of `deploy-all.ps1`) sends these keys and replaces any old line for each, so nothing is ever duplicated: `CLOUDINARY_CLOUD_NAME`, `CLOUDINARY_API_KEY`, `CLOUDINARY_API_SECRET`, `BITBUCKET_TOKEN`, `BITBUCKET_WEBHOOK_SECRET`, `JIRA_BASE_URL`, `JIRA_EMAIL`, `JIRA_API_TOKEN`, `JIRA_PROJECT_KEY`, `JIRA_CLOUD_ID`, `JIRA_WEBHOOK_SECRET`, `JIRA_ISSUE_TYPE_BUG`, `JIRA_ISSUE_TYPE_FEATURE`, `JIRA_ISSUE_TYPE_FEEDBACK`, `MAIL_RELAY_URL`, `MAIL_RELAY_KEY`, `PORTAL_ID_LOGIN_PASSWORD`. Values go over SSH and are never printed; a key empty in your local `.env` is skipped, so it never blanks the server's. `MAIL_MAILER` is left out on purpose (the server keeps its own). A deploy alone never copies your local `.env`.

- Cloudinary (email images, reimbursement receipts): until its keys are on the server, uploads answer "Image upload is not configured." / "Receipt upload is not configured.".
- ID-number sign-in (Settings > Advanced switch) also needs `PORTAL_ID_LOGIN_PASSWORD`; until it is on the server, every ID sign-in is refused.
- Airtable stays empty on the Test Server so test data is never synced into the real base.

To see which settings the server has (names only, no values):

```powershell
ssh bluehost 'for k in CLOUDINARY_CLOUD_NAME CLOUDINARY_API_KEY CLOUDINARY_API_SECRET BITBUCKET_TOKEN BITBUCKET_WEBHOOK_SECRET JIRA_BASE_URL JIRA_EMAIL JIRA_API_TOKEN JIRA_PROJECT_KEY JIRA_CLOUD_ID JIRA_WEBHOOK_SECRET MAIL_RELAY_URL MAIL_RELAY_KEY PORTAL_ID_LOGIN_PASSWORD; do grep -q "^$k=." ~/apps/test-server/.env && echo "$k set" || echo "$k EMPTY"; done'
```

**Tracker tables** (e.g. Finance / Accounts' `account_fund_transfers`): the tracker database is the transaction tracker's own, so no Laravel migration reaches it. Its changes live in `sql/transaction_tracker/patches/` and every admin deploy runs each one, so they must be safe to rerun (`CREATE TABLE IF NOT EXISTS`, ...). A new one: add a dated file there; fresh installs also need it in `schema.sql`. A missing table shows as "We could not load the accounts." or a similar load error.

## Bitbucket code activity (MIS projects)

MIS projects count commits and lines from the Bitbucket repositories linked on their Add/Edit Project form. Each commit counts on its own day in Manila time, so a Daily or Late Report for a date shows that date's work. Three things make it run on the Test Server.

**1. Two settings**, sent by `push-env.ps1` from your local `.env` (see "Changing a setting"):

```text
BITBUCKET_TOKEN=          an Atlassian API token with only read:repository:bitbucket
BITBUCKET_WEBHOOK_SECRET= any long random string, also typed into each webhook below
```

Make the token under a dedicated read-only Bitbucket user if you can: it reads everything its account can. Without a secret, every webhook delivery is refused.

**2. A push webhook on each linked repository.** Bitbucket → the repository → Repository settings → Webhooks → Add webhook:

| Field | Value |
| --- | --- |
| URL | `https://test.ikaikabim.com/central-api/api/staging/bitbucket/webhook` |
| Secret | the `BITBUCKET_WEBHOOK_SECRET` value |
| Triggers | Repository push |

A push syncs that repository at once. "View requests" on the webhook shows each delivery: `202` is good, `401` means the secret differs.

**3. The scheduler cron**, so the hourly sync runs (it catches a missed webhook, picks up a newly linked repository and finishes counting a long history). Every admin deploy adds it if it is missing, so there is nothing to do by hand; cPanel → Cron Jobs lists it as an every-minute `... artisan schedule:run` line. Without the cron or a webhook nothing syncs by itself: linking a repository on a project does not start a sync.

**Check it:**

```powershell
ssh bluehost "cd ~/apps/test-server/app && php artisan portal:bitbucket-preview ikaika-web/ikaika_portal_frontend_rewrite"
ssh bluehost "cd ~/apps/test-server/app && php artisan portal:bitbucket-sync"
```

The preview shows one day's commits and saves nothing; the sync prints `<repository>: N new commits, N counted` per linked repository. A first sync of a long history takes a few minutes.

## Jira (Issues & Requests)

Every issue or request people send from the portal's corner button becomes a Jira issue: as it comes in (MIS can turn that off on the Issues & Requests page), sent by hand, or with every report not in Jira yet at once (**Send to Jira** in the page's header). Its status and kind then go both ways: a change on the Jira board comes back by webhook, every fifteen minutes or on **Refresh**, and MIS changing them in the portal moves the card or changes its work type first. Fixed in stays the portal's unless the issue has a fix version.

A cancelled report says why (2026-10-21): won't fix, or a duplicate of another report. Either way its issue moves to Cancelled; a duplicate is also labelled `duplicate` and linked to its original's issue ("IPS-8 duplicates IPS-3"), when the original is in Jira too. Reopened in the portal, the link and the label come off again; reopened on the board, they stay there for whoever moved it.

A report can be archived out of the way or deleted to its deleter's Recycle Bin (2026-10-21). Either one moves an open report's issue to Cancelled first, and refuses when Jira does not take it; a Fixed or Cancelled one's issue stays as it is. Archived and deleted reports are not sent or read back until they are back on the page.

Production and the Test Server share one project (2026-10-21), because what production's people report is fixed on staging. Staging's issues carry a second label, `staging`, and say "(staging report #N)" where production's say "(report #N)"; each server follows only the reports sent on it.

**1. A service account and its token.** In Atlassian, a dedicated user (not a Jira admin) with an API token (id.atlassian.com → Security → API tokens). On each project it needs **Browse projects**, **Create issues**, **Create attachments**, **Edit issues** (a kind changed in the portal changes the work type, and a duplicate is labelled), **Transition issues** (a status changed in the portal moves the card) and **Link issues** (a duplicate is linked to its original). The site needs issue linking on, with its standard **Duplicate** link type. Every issue the portal makes is reported by this account, so nobody's name is on an anonymous report.

**2. The settings.** In your local `.env` (the Test Server's):

```text
JIRA_BASE_URL=        https://<site>.atlassian.net
JIRA_EMAIL=           the service account's email
JIRA_API_TOKEN=       its API token
JIRA_PROJECT_KEY=     the shared project's key, the same on both servers
JIRA_CLOUD_ID=        only for a scoped token: the site's cloudId (portal:jira-check prints it)
JIRA_WEBHOOK_SECRET=  any long random string, also typed into the webhook below
```

**Classic or scoped token.** A classic token ("Create API token") works against the site as it is. A scoped one ("Create API token with scopes": `read:jira-user`, `read:jira-work`, `write:jira-work`) is refused there with a 401 and works only through Atlassian's gateway, which `JIRA_CLOUD_ID` turns on; the links people open stay on the site.

Optional: `JIRA_ISSUE_TYPE_BUG`, `JIRA_ISSUE_TYPE_FEATURE`, `JIRA_ISSUE_TYPE_FEEDBACK` (default Bug, Story, Task; a type the project lacks falls back to Task). Both servers must file each kind as the same type, so push-env sends these too.

`push-env.ps1` sends them to the Test Server with the rest. Production's come from `.ikaika_scripts_configs/production-settings.env` (`.\script.ps1 deploy/push-env -Target production`): the same site, account, token, project and issue types, and its own webhook secret.

The Test Server runs on copies of production's database. Those copied reports stay production's: each report records the server it was sent on, and staging never sends one again, reads it back or changes its issue. The page marks them "Another server's".

**3. Check it, and get the webhook to register:**

```powershell
ssh bluehost "cd ~/apps/test-server/app && php artisan portal:jira-check"
```

It names the account, the project, the issue type each kind becomes and the link type duplicates use, and prints the webhook. A Jira admin adds it once per server in Jira settings → System → WebHooks:

| Field | Value |
| --- | --- |
| URL | what `portal:jira-check` prints, e.g. `https://test.ikaikabim.com/central-api/api/staging/jira/webhook` |
| Secret | the `JIRA_WEBHOOK_SECRET` value |
| Events | Issue updated, Issue deleted |
| JQL | what `portal:jira-check` prints: on production `project = <KEY> AND labels = ikaika-portal`, on the Test Server `project = <KEY> AND labels = ikaika-portal AND labels = staging` |

**4. The scheduler** sends what failed or waited and reads every linked issue back, in case a delivery was lost. It rides the same every-minute cron as everything else; to run it by hand:

```powershell
ssh bluehost "cd ~/apps/test-server/app && php artisan portal:jira-sync"
```

It prints `Sent N, failed N, read back N, gone from Jira N.` and fails when Jira refuses the account or does not answer.

### Moving the Test Server onto the shared project

Once, in this order, so the old code never meets the shared project:

1. Turn staging's **Send new ones to Jira** off on its Issues & Requests page.
2. Deploy the backend with `.\script.ps1 deploy/admin` (not `deploy/test-server`, which pushes the settings first). `portal:jira-check` on the Test Server should say `Files as: staging`.
3. Set `JIRA_PROJECT_KEY` (and any `JIRA_ISSUE_TYPE_*` production uses) in your local `.env`, push them with `.\script.ps1 deploy/push-env -Only JIRA_PROJECT_KEY`, and run `portal:jira-check` again.
4. A Jira admin changes the Test Server's webhook JQL to the one `portal:jira-check` prints. Then turn **Send new ones to Jira** back on.

The Test Server's reports from before keep their links into the old sandbox project, which stay as they are; archive the sandbox when nothing in it is needed.

### Testing against a Jira space from your PC

**Where the credentials go:** the `JIRA_*` lines in this repo's local `.env` (gitignored, so they never leave your PC; `.env.example` lists them). Point them at a sandbox project if you can; in the shared one, a PC's issues carry the label `development` and say "(development report #N)", so they are easy to find and delete:

```text
JIRA_BASE_URL=https://<site>.atlassian.net     the site, https, no path
JIRA_EMAIL=you-or-the-service-account@...       the Atlassian account the token belongs to
JIRA_API_TOKEN=...                              id.atlassian.com → Security → Create and manage API tokens
JIRA_PROJECT_KEY=...                            the sandbox project's key, e.g. IKPS
JIRA_CLOUD_ID=                                  only for a scoped token (see Classic or scoped token above)
JIRA_WEBHOOK_SECRET=                            leave empty locally: no webhook can reach your PC
```

Your own account's token works for a first try, but every issue it makes is reported by you; use the service account's to see what the servers will send. Run `php artisan config:clear` after editing `.env` if you ever cached the config. The tests never see these values: `phpunit.xml` blanks every `JIRA_*`.

**Screenshots need Cloudinary too.** A report's screenshots go to Cloudinary first and to Jira from there, so with `CLOUDINARY_CLOUD_NAME`, `CLOUDINARY_API_KEY` and `CLOUDINARY_API_SECRET` empty in your `.env` a report with one is refused ("Cloudinary is not set up") and only text-only reports go through. Use the same values the Test Server has.

**Then:**

1. `php artisan portal:jira-check` — the account, the project and the issue type each kind becomes. A `401` is the email or token (a scoped token without `JIRA_CLOUD_ID`, which the check then prints), a `404` the project key.
2. File a report from the corner button (local `QUEUE_CONNECTION=sync`, so it goes to Jira during the submit), or press **Send to Jira** on its sheet in Issues & Requests.
3. Move the issue in Jira, then `php artisan portal:jira-sync` to read it back — locally that stands in for the webhook.

Empty the `JIRA_*` lines again when you are done, so a local report does not quietly become a sandbox issue.

## Roll back

```powershell
ssh bluehost "ls ~/apps/test-server/backups"
```

Pick the `admin-app-<stamp>` and `admin-public-<stamp>` pair, then:

```bash
cd ~/apps/test-server
mv app app-failed && mv backups/admin-app-<stamp> app
rm -rf ~/public_html/website_a85fbf18/central-api
mv backups/admin-public-<stamp> ~/public_html/website_a85fbf18/central-api
```

If the failed release ran migrations, restore the dumps the deploy took just before it (a failed check prints these lines with the real paths):

```bash
gunzip -c ~/apps/test-server/backups/databases-<stamp>/<database>.sql.gz | mysql -u <user> -p <database>
```

`migrate:rollback` also works for a migration with a sound `down()`; read it first.

---

## Setting up from scratch

This was done once on 2026-10-05. Repeat it only for a new server or a second test site.

### 1. SSH access (once per PC)

1. Create a key. Save the passphrase in your password manager:

   ```powershell
   ssh-keygen -t rsa -b 4096 -f $HOME\.ssh\bluehost -C "you@ikaikabim.com bluehost"
   Get-Content $HOME\.ssh\bluehost.pub | Set-Clipboard
   ```

2. In cPanel, go to Security → SSH Access → Manage SSH Keys → **Import Key**. Paste into **Public Key** only, and leave Private Key and Passphrase empty. Click Import, then **Manage → Authorize**.
3. Add the alias:

   ```powershell
   Add-Content $HOME\.ssh\config "`nHost bluehost`n    HostName eox.vhu.mybluehost.me`n    User eoxvhumy`n    IdentityFile ~/.ssh/bluehost`n    IdentitiesOnly yes`n"
   ```

4. Test it. `ssh bluehost "echo CONNECTED"` should print `CONNECTED`.

#### Optional: passphrase once

In an **Administrator** PowerShell, once:

```powershell
Get-Service ssh-agent | Set-Service -StartupType Automatic; Start-Service ssh-agent
```

Then in any PowerShell, once per login:

```powershell
ssh-add $HOME\.ssh\bluehost
```

### 2. Server folder and settings

```powershell
ssh bluehost "mkdir -p ~/apps/test-server/scripts ~/apps/test-server/upload"
scp deployment\server\setup-env.sh bluehost:~/apps/test-server/scripts/
ssh bluehost "bash ~/apps/test-server/scripts/setup-env.sh"
```

This copies staging's `.env`, which has the real `APP_KEY` and JWT secret, then sets the Test Server paths and adds the missing keys. Add the mail lines as shown in [Changing a setting](#changing-a-setting).

### 3. Databases

```powershell
powershell -ExecutionPolicy Bypass -File deployment\copy-databases.ps1
```

This creates the four databases and the user, with a random password written straight into `db.env`, then loads your local data. Expect table counts of about core 13, portal 69, tracker 13 and estimator 18.

### 4. Azure

Go to Azure portal → App registrations → the portal app (`f995e827-...`) → Authentication → **Single-page application**, and add `https://test.ikaikabim.com/portal`. Without it, Microsoft sign-in fails.

### 5. Deploy

Run `deploy.ps1` here, then the portal's `deploy.ps1`.

## Troubleshooting

| Symptom | Cause / fix |
| --- | --- |
| Health returns WordPress HTML | The public folder is missing or has no `.htaccess`: `ssh bluehost "ls -a ~/public_html/website_a85fbf18/central-api"` |
| 500 with an empty page | `ssh bluehost "tail -n 50 ~/apps/test-server/app/storage/logs/laravel.log"` |
| `The environment file is invalid` | A line in `test-server/.env` is broken (two keys on one line). Rerun `setup-env.sh`, which repairs it |
| Workspace fonts 404 (`/build/...`) | The zip was built without `build.ps1` |
| `uapi ... failed` | cPanel refused (name taken, quota). The error says which |
| `Access denied` during import | `db.env` doesn't match the `eoxvhumy_ts` user any more. Delete the user in cPanel and `db.env`, then rerun `copy-databases.ps1` |
| Microsoft login: redirect mismatch | Azure and `PORTAL_AZURE_REDIRECT_URI` must both be exactly the portal URL |
| `Bitbucket HTTP 401` from the sync | `BITBUCKET_TOKEN` is wrong, expired (they last a year at most) or lacks `read:repository:bitbucket` |
| Webhook deliveries answer `401` | The webhook's secret and `BITBUCKET_WEBHOOK_SECRET` differ, or the setting was not applied |
| Issues & Requests says Jira is "not connected" | One of `JIRA_BASE_URL`, `JIRA_EMAIL`, `JIRA_API_TOKEN`, `JIRA_PROJECT_KEY` is empty on that server; `portal:jira-check` names it |
| `Jira HTTP 401` | The token is wrong or expired, `JIRA_EMAIL` is not the token's owner, or it is a scoped token and `JIRA_CLOUD_ID` is empty |
| `Jira HTTP 404: You cannot create issues in this project` on a report | Jira did not take the account at all and answered as for an anonymous visitor; `portal:jira-check` shows the `401` behind it |
| `Jira HTTP 403` or `404` on the project | The service account lacks Browse projects / Create issues / Create attachments on it, or `JIRA_PROJECT_KEY` names another project |
| `Jira HTTP 400` naming a field | The project requires a field the portal does not fill (a required custom field); make it optional for these issue types |
| Cancelling a duplicate says `Jira HTTP 404` or `403` | The service account lacks **Link issues** on the project, or issue linking is off on the site; `portal:jira-check` says whether the Duplicate link type is there |
| Cancelling a duplicate says `Jira HTTP 400` naming `labels` | The project's edit screen has no Labels field, so the issue cannot be labelled; add the field to the screen |
| Jira webhook deliveries answer `401` | The webhook's secret and `JIRA_WEBHOOK_SECRET` differ, or the setting was not applied |
