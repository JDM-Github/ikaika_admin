# Weekly Wins & Plans -> Teams (PARKED 2026-10-17)

A weekly reply each person posts in the **"Team Culture and Wins"** Teams channel: their
**WIN (last 7 days)** and **PLANS (next 7 days)**. Parked until the supervisor approves the Microsoft
permission; nothing is built yet.

## The idea

1. **WINS (last 7 days)** -- the system reads that person's own daily-report remarks for the window and
   the AI turns them into 3-6 bullets. The data already exists (`user_reports.remarks` per day), so the
   person does nothing here.
2. **PLANS (next 7 days)** -- nothing stores intent anywhere, so the person **types it** (the AI may
   offer a draft from the week's work, but they confirm).
3. **Post it** into the right week's thread in Teams, once per person, as a **reply**.

## How the Teams side works (and its limits)

- A **thread is a top-level channel message** ("Weekly Wins & Plans Thread -- Week N of YYYY"); replies
  hang under it. So we reply to the **top-level message of the target week** -- the newest for "this
  week", the 2nd newest for "last week" (`GET .../messages` returns top-level messages only, replies
  are a separate endpoint, so "never a reply" is free).
- **Finding** that message needs either the app reading the channel, or someone pasting the thread link.
- **Re-running** (the person wants to change their post): we save the reply id we posted and **update
  that reply** instead of posting a second one.
- **Author cannot be the person.** Mail lets an app set `From:` to a person; **Teams does not** let an
  app post *as* a user -- an app-only message is always authored by the app. So the reply shows as
  "IKAIKA Portal" and must name the person in its text. Only the channel-email route posts from the
  person, and email-to-channel can only start a new top-level post, never reply in a thread.

## What the supervisor / IT must approve

Application permissions (server-side, no user signed in -- same model as `Mail.Send`), on the **same
app** (`f995e827-5f8b-49ee-8cd5-b05b99017558`, tenant `70c0daac-...`):

| Permission | Type | Why |
| --- | --- | --- |
| `ChannelMessage.Send` | Application | post the reply; also update the reply we posted |
| `ChannelMessage.Read.All` | Application | find this week's thread |

Narrower alternative if the broad read is refused: **RSC** `ChannelMessage.Read.Group` +
`ChannelMessage.Send.Group` in a small Teams app package, consented by the **team owner** on install.

Plus one non-Azure step: **install the app into the "Team Culture and Wins" team** (app-only channel
posting is refused without it -- the step mail did not need).

**Why not the signed-in user's token:** the portal signs in with MSAL for **identity only**
(`openid profile email`); `PortalAuthController::loginMicrosoft` takes the id_token, matches the
employee and issues the portal's own session. It never holds a token that can act as the person, and a
Friday-3PM run has no user present anyway. Delegated `Mail.Send` is the "requires a signed-in user"
version the supervisor meant; it is not usable here.

## Ids (decoded from the channel link the user shared)

| Key | Value |
| --- | --- |
| Tenant | `70c0daac-fc8d-46de-b3eb-db0ce575ae83` (same as `PORTAL_AZURE_TENANT_ID`) |
| Team (groupId) | `ca6041f7-3aca-47b7-8d4d-2b557d23830f` |
| Channel | `19:fdea6506be114a75b78e855e50bd1aa6@thread.tacv2` |
| Channel name | Team Culture and Wins |

The channel link gives the destination but **not** a message id, and a deep link grants no access.

## Config it would add

`config/services.php` (same shape as `services.ollama`), plus the app secret the admin does not have
today -- Graph credentials currently live only in the WordPress server's `wp-config.php`
(`IKAIKA_GRAPH_*`):

```
TEAMS_TENANT_ID=70c0daac-fc8d-46de-b3eb-db0ce575ae83
TEAMS_CLIENT_ID=f995e827-5f8b-49ee-8cd5-b05b99017558
TEAMS_CLIENT_SECRET=<same app secret>
TEAMS_TEAM_ID=ca6041f7-3aca-47b7-8d4d-2b557d23830f
TEAMS_CHANNEL_ID=19:fdea6506be114a75b78e855e50bd1aa6@thread.tacv2
```

## Steps when it resumes

1. **Verify first, read-only** (5 minutes, once consent + install are in place):
   - `GET /teams/{teamId}/channels/{channelId}` -> name should be "Team Culture and Wins" (403 = app not
     installed in the team yet).
   - `GET /teams/{teamId}/channels/{channelId}/messages` -> see the weekly threads and their `subject`.
   - Post a throwaway reply to an old week, update it, delete it -> confirms send + edit.
2. **Admin**: `PortalTeams` (token + `messages()` + `findWeekThread()` + `reply()`/`updateReply()`),
   `services.teams`, and endpoints `POST reports/weekly-update` (build the digest from the week's
   remarks + typed plans) and `POST reports/weekly-update/post` (reply, storing the reply id per person
   per week). Tests with `Http::fake`. Optional scheduled command for the Friday 3 PM run.
3. **Portal**: a small "Weekly Update" surface -- auto WINS, a PLANS box, **Generate -> preview -> Post
   to Teams**, plus Copy. Reuses `ClipboardManager`; the AI call reuses the `PortalRemarkFormatter`
   pattern and `services.ollama`.

## Open questions

1. Find method: `ChannelMessage.Read.All` (broad) vs RSC (per-team) vs "paste the thread link" (no read
   permission at all).
2. Week numbering: is the kickoff's "Week 42 of 2026" the **ISO** week? Matching the thread relies on
   generating the same label (or falling back to the Nth newest message).
3. Trigger: Friday 3 PM automatic, or a button (or both).
4. One reply per person (assumed) vs one combined team reply.
5. Daily reminders already always produce `What`/`Changed`; should `Wins` become **mandatory** in the
   Polish prompt (`remark-formatter.md`) so the weekly digest has wins to draw on? (Recommended.)

## Related

- `remark-formatter.md` (ikaika_admin context): the model, `services.ollama`, the Polish button, and
  the prompt that feeds the daily remarks this digest reads.
- `docs/plan/emails.md`: how the portal's mail leaves (the same app-only Graph model).
