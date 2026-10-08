# Shared Pages — guest read-only links (plan)

Status: **planned, not built (2026-10-08).** Every decision below is the user's answer or direction
of 2026-10-08; nothing is enforced, seeded or coded yet. Build order, tests and deployment are at
the end.

## 1. What this is

A new page under **Manage** — **Manage / Shared Pages** — where a **developer** creates a *share*:
a guest link (QR + code) that opens chosen portal pages **read-only** for someone with no portal
account. The guest signs in with a generated password, plus a one-time code emailed to a bound
address when one is set. The portal opens in **guest mode**: only the granted pages in the nav, no
writes, no profile, no notifications.

Nothing here is an employee. A guest holds no role, no department and no personal rows.

## 2. The experience

### 2.1 Developer creates a share (Manage / Shared Pages, Developer mode only)

1. **New shared page** sheet:
   - **Label** — what the developer calls it ("Truebeck walkthrough").
   - **Email** — optional; binding it adds the one-time code at every guest sign-in.
   - **Expiry** — a preset (1 day / 7 days / 30 days) or a custom date, or cleared to never
     expire. Default **7 days**.
   - **Rate cap** — requests per minute for this share, default **60**.
   - **Pages** — every page in the catalog, **all off by default**. A page we have not verified
     for guests shows disabled with **"Not ready"**.
2. **Create** → the server generates the `code` and a 12-character `password` and the page shows
   a **result card**:
   - the **QR** (encodes the link),
   - the **link** (`<portal-base>/share/<code>`) with a Copy action,
   - the **password**, shown **once**, with a Copy action,
   - a line saying whether it was emailed to the bound address.
3. The row's actions: **QR & link**, **Edit**, **Regenerate password**, **Revoke**.
   - Revoke is immediate: a live guest session is refused on its next request.
   - Regenerate shows a new password once, and kills live sessions too.

The list is a `DataTableShared`: Label, Pages (count and names), Email, Expires, Last used,
Actions. No tabs, so no `stateKey`.

### 2.2 Guest opens the link

1. `<portal-base>/share/<code>` — handled **before the login gate**:
   - an unknown or revoked code: "This link is no longer active."
   - otherwise: the share's **label** and a **password** field.
2. Right password →
   - **no email bound**: straight into the portal as a guest;
   - **email bound**: "We mailed a 6-digit code to a•••@example.com", a code field, and a
     Resend action (60-second cooldown).
3. The portal loads as a guest: only the granted pages, a **"Read-only guest access"** hint, and
   a **Guest** chip with **Sign out** where the profile chip is.
4. The guest session lasts until the share **expires or is revoked**; it survives reloads and
   return visits (see 3.6).

## 3. Decisions (2026-10-08, with the defaults filled in)

| # | Decision |
|---|---|
| 1 | A share grants **whole pages**, picked from the catalog, **all off by default**. Every page is listed; unverified pages are disabled with "Not ready" until we clear them. |
| 2 | The **password is auto-generated**, shown once (create and regenerate), and **emailed** to the bound email when one is set. |
| 3 | The guest sees the **normal portal shell**, only the granted pages. A Guest chip replaces the profile; notifications, Settings and the personal Access page are gone; every write control hides. |
| 4 | **Email binding is optional per share; when bound, every sign-in needs a mailed OTP.** |
| 5 | The page lives at **Manage / Shared Pages**, rule `manage.shares`, `min_tier = developer` (Developer mode only for now, like References and Backup before it). |
| 6 | The guest session **persists until the share expires** — the guest token is stored on the device (localStorage on web). This is the one deliberate exception to "a token never goes in PreferencesStorage". |
| 7 | **Expiry** is chosen per share, default **7 days**, revocable immediately. |
| 8 | **Rate cap**: requests per minute per share, default **60**; plus a sign-in attempt cap and OTP limits regardless. |

Defaults the user has not objected to (change them here, not in code):

- password: 12 characters from an unambiguous alphabet (no `0/O/1/l/I`);
- OTP: 6 digits, valid 10 minutes, 5 tries, resend after 60 seconds, stored hashed;
- sign-in attempts: 5 per 10 minutes per share + IP, then 429;
- one visit row per successful sign-in (share, time, IP);
- code: 24 random hex characters (96 bits).

## 4. Data (`ikaika_admin/sql/portal/separate.sql`)

```sql
-- ============================================================================
-- SHARED PAGES - guest read-only links, made on Manage / Shared Pages
-- A developer picks pages; the server issues a link (/share/<code>) and a
-- password. The guest signs in with the password, plus a code emailed when a
-- bound email is set, and browses those pages read-only. Existing databases:
-- run the CREATEs, the catalog row and the seed.
-- ============================================================================

CREATE TABLE IF NOT EXISTS shared_pages (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    label           VARCHAR(100) NOT NULL,
    code            CHAR(24)     NOT NULL,
    password_hash   VARCHAR(255) NOT NULL,
    email           VARCHAR(255) NULL,
    expires_at      TIMESTAMP    NULL,           -- NULL = never, only when cleared
    rate_per_minute SMALLINT     NOT NULL DEFAULT 60,
    revoked_at      TIMESTAMP    NULL,
    created_by      INT          NOT NULL,       -- employees.id, the developer
    created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used_at    TIMESTAMP    NULL,
    UNIQUE KEY shared_pages_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS shared_page_rules (
    share_id INT          NOT NULL,
    rule_key VARCHAR(100) NOT NULL,
    PRIMARY KEY (share_id, rule_key),
    FOREIGN KEY (share_id) REFERENCES shared_pages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS shared_page_otps (
    share_id   INT          NOT NULL PRIMARY KEY,
    code_hash  VARCHAR(255) NOT NULL,
    attempts   TINYINT      NOT NULL DEFAULT 0,
    issued_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP    NOT NULL,
    FOREIGN KEY (share_id) REFERENCES shared_pages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS shared_page_visits (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    share_id   INT NOT NULL,
    visited_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ip         VARCHAR(45) NULL,
    FOREIGN KEY (share_id) REFERENCES shared_pages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Which pages a share may grant. Every page-level rule is seeded with is_ready 0;
-- a page is flipped to 1 only after a guest check is run against it (section 7).
-- The first verified set is seeded 1 below.
CREATE TABLE IF NOT EXISTS shared_page_shareable (
    rule_key VARCHAR(100) NOT NULL PRIMARY KEY,
    is_ready TINYINT(1)   NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO shared_page_shareable (rule_key, is_ready) VALUES
    ('home', 0),
    ('projects.view', 1),
    ('projects.track', 1),
    ('projects.create', 0),
    ('projects.manager', 0),
    ('projects.references', 0),
    ('reports.daily', 0),
    ('reports.late', 0),
    ('reports.submitted', 0),
    ('reports.submitted.edit', 0),
    ('requests.own', 0),
    ('requests.leave', 0),
    ('requests.overtime', 0),
    ('requests.offset', 0),
    ('requests.reimbursement', 0),
    ('requests.own.edit', 0),
    ('user.logs', 0),
    ('user.recycle', 0),
    ('calendar', 1),
    ('manage.requests', 0),
    ('manage.reports', 0),
    ('manage.users', 0),
    ('finance.overview', 1),
    ('finance.transactions', 1),
    ('finance.envelopes', 1),
    ('finance.accounts', 1),
    ('administration.recycle', 0),
    ('administration.logs', 0),
    ('administration.actions', 0),
    ('administration.missing', 0),
    ('administration.emails', 0),
    ('email', 0),
    ('email.library', 0),
    ('administration.backup', 0);

-- The page's own power: developers only, in Developer mode. Existing databases:
-- add the row (nothing else to move).
INSERT INTO access_permissions
    (permission_key, parent_key, label, description, supports_reach, can_toggle, min_tier, sort_order)
VALUES
    ('manage.shares', 'manage', 'Shared pages',
     'Create read-only guest links to portal pages: pick the pages, hand out the link and its password.',
     0, 1, 'developer', 860);
```

Notes:

- `sql/portal/schema.sql` is never edited; portal-owned tables live here (run `reset.ps1` locally,
  apply the same DDL to the test server separately, see section 11).
- The seed list is the page-level rules — the same keys `RouteRuleConstant.enforced` maps on the
  portal side, plus `home`. A new page-level rule must be added here too.
- `is_ready` is the only gate between the toggle and a save; the developer sees the page, the
  server refuses to store a grant for a page still at 0.

## 5. Server (`ikaika_admin`)

### 5.1 Files

| File | Job |
|---|---|
| `app/Support/Portal/PortalSharedPages.php` | The whole process: list, create, edit, revoke, regenerate, grant rows; `access()` and `verify()`; OTP issue/check; rate and attempt checks; visit log; the panel payload (shares + the shareable page list). |
| `app/Http/Controllers/Api/Portal/Manage/SharedPagesController.php` | `index`, `store`, `update`, `destroy`, `regenerate`, `qr`. |
| `app/Http/Controllers/Api/Portal/ShareController.php` | Public: `info`, `access`, `verify`, `resend`. |
| `routes/portal/manage.php` | `manage/shared-pages*` behind `portal.rule:manage.shares`. |
| `routes/portal/share.php` (new, loaded where `routes/portal/auth.php` is) | Public `share/*` behind `throttle:portal-share`. |
| `app/Http/Middleware/AuthenticatePortalJwt.php` | Guest branch (5.3). |
| `app/Support/Portal/PortalRules.php` | Guest override (5.4). |
| `app/Providers/AppServiceProvider.php` | `portal-share` and `portal-share-auth` limiters beside the existing `portal-*` ones. |
| `composer.json` | `bacon/bacon-qr-code` for the QR SVG. |

### 5.2 Endpoints

Private (`portal.rule:manage.shares`, `throttle:portal-manage-*` like the rest of Manage):

```text
GET    manage/shared-pages               -> { shares: [...], pages: [{key, label, isReady}] }
POST   manage/shared-pages               { label, email?, expiresInDays?|expiresAt?, ratePerMinute, rules[] }
                                         -> { share, password }        // password shown once
PATCH  manage/shared-pages/{id}          { label, email, expiresInDays?|expiresAt?, ratePerMinute, rules[] }
DELETE manage/shared-pages/{id}          -> revoked
POST   manage/shared-pages/{id}/password -> { password }               // new one, once
GET    manage/shared-pages/{id}/qr       -> { svg }                    // the link, as SVG text
```

Public:

```text
GET  share/{code}          -> { label, hasEmail }            // never the email itself
POST share/{code}/access   { password } -> { otpRequired: true } | { token, expiresAt, label, rules }
POST share/{code}/verify   { password, otp? } -> { token, expiresAt, label, rules }
POST share/{code}/resend   -> { sent: true }                 // 60-second cooldown
```

- A wrong code/password always answers the same, generic refusal (no code probing).
- `access` with no bound email does the `verify` work directly and returns the token.
- The password is never echoed by `GET`; only `store` and `regenerate` return it, once.
- `expiresAt` in the response is the guest token's expiry (the earlier of the share's and the
  JWT TTL).

### 5.3 Guest auth

- `PortalJwt::issue` gains no changes; a guest token is just different claims:

```json
{ "guest": true, "share": 12, "rules": ["projects.view", "finance.envelopes"],
  "label": "Truebeck walkthrough", "sub": null, "exp": 0 }
```

  `exp` is `min(now + jwt_ttl, share.expires_at)`; a share with no expiry uses the JWT TTL and is
  re-validated on every request anyway.

- `AuthenticatePortalJwt`:
  - parses the token as today;
  - when `payload.guest === true`: loads the share by `payload.share`; refuses (401) when it is
    missing, `revoked_at` is set or `expires_at` has passed; throws the **GET-only** rule away
    otherwise: `$request->isMethod('GET')` or 403 "Guest access is read-only.";
  - sets `portalShare` (the share row) and a **synthetic unsaved `Employee`** as `portalEmployee`,
    so services that read `$actor->getKey()` / `department` / `email` get nulls rather than
    crashing. `getKey()` is null, so person-scoped reads come back empty (section 7).
  - applies the share's own rate cap: a `RateLimiter` hit keyed `share:{id}` with
    `Limit::perMinute($share->rate_per_minute)`; 429 past it.
  - updates `last_used_at` at most once a minute.

- `RequirePortalRule` needs no change: `PortalRules::require` answers for a guest (5.4).
- `RequirePortalOwnRule` and every write route are unreachable for a guest — the GET-only refusal
  fires first.
- A visit row is written once per successful `verify`.

### 5.4 `PortalRules` guest override

When the request carries `portalShare`, `active`, `activeInDefault`, `holds`, `reach`, `listed`
and `people` answer from the share instead of the employee:

- `active` → every granted key at reach `all` (the share's own list; nothing else);
- `holds(key)` → the key is granted;
- `reach(key)` → `all` for a granted key, `null` otherwise;
- `listed`/`people` → everyone (guests get the whole set the page shows at all-reach);
- the developer-mode and own-switch paths are skipped.

This is the single point that lets every page be shared; the pages themselves are unchanged.

### 5.5 Rate, attempts and OTP

- `portal-share` (public routes): 30 requests/minute per IP, beside `portal-auth`'s 10.
- `portal-share-auth`: 5 access/verify attempts per 10 minutes per share + IP.
- OTP: `random_int`, hashed with the password hasher; 10 minutes; 5 tries; a new one replaces the
  old and resets the tries; resend 60 seconds apart.
- The share's own `rate_per_minute` is enforced on guest requests (5.3), not on the sign-in
  endpoints.

### 5.6 Emails

Both go through the portal email layer (`PortalEmailMessages`, the same bridge as every other
portal mail; log mode still works):

- **The password**, once at create/regenerate, when an email is bound: the label, the link, the
  password, the expiry.
- **The OTP**, at every guest sign-in when an email is bound.

Neither email ever contains anything about the account or the pages beyond the label.

### 5.7 QR

`GET manage/shared-pages/{id}/qr` builds the link and renders an SVG with
`BaconQrCode\Renderer\Image\SvgImageBackEnd` (the `bacon/bacon-qr-code` package, no GD needed).
The portal draws the SVG with `react-native-svg`'s `SvgXml`, so the portal gains no package.

## 6. Portal (`ikaika-portal`)

### 6.1 Wiring

| Piece | Where |
|---|---|
| `ShellRoute.sharedPages` (under Manage, transient false) | `src/core/enums/layouts/shell.layout.enum.ts`, shell constant/sidebar |
| Rule map `sharedPages -> 'manage.shares'` | `src/core/constants/route-rule.constant.ts` |
| Nav, landing, breadcrumb | `shell.layout.tsx` / `shell.layout.controller.ts` |
| Bootstrap + root layout screen | `bootstrap.ts`, `root.layout.tsx` |
| Screen / controller / constant | `src/screens/administration.shared-pages.screen.tsx`, `src/core/controllers/screens/shared-pages.screen.controller.ts`, `src/core/constants/screens/shared-pages.screen.constant.ts` |
| Model / endpoints | `src/apis/models/shared-page.model.ts`, `src/apis/endpoint/manage/shared-pages/{get,post,patch,delete}/` |
| QR widget | `src/components/widgets/qr.widget.tsx` |
| Guest screen / controller / constant | `src/screens/share-access.screen.tsx`, `src/core/controllers/screens/share-access.screen.controller.ts`, `src/core/constants/screens/share-access.screen.constant.ts` |
| Guest endpoints | `src/apis/endpoint/share/{get,post}/` |
| Session + state | `authentication.service.ts`, `authentication.state.ts`, `session.model.ts`, `users.model.ts` |

### 6.2 Manage / Shared Pages

- `PageHeaderSharedBar` with an `ActionSharedButton shape="pill"` **New shared page** (the
  References Add pattern).
- `DataTableShared` (no tabs): Label, Pages (`3 pages` + names in the row details), Email,
  Expires, Last used; trailing Actions cell with **QR & link**, **Edit**, **Regenerate
  password**, **Revoke** — `rowActions` with the eye for the QR/details sheet.
- **New / Edit sheet** (`SlideSheetSharedOverlay`): `TextSharedField` label, `TextSharedField`
  email, expiry select (1 day / 7 days / 30 days / Custom / Never) with a `CalendarPickerSharedField`
  when custom, `TextSharedField` rate (numeric), and the page list — a `PowerSharedList`-shaped
  list with `SwitchSharedButton` per page, **all off**, unready rows dimmed with a "Not ready"
  pill and the switch disabled. Save is disabled until something changed.
- **Result sheet** after create/regenerate: `QrWidget` (SVG), the link in a copyable field with
  a Copy action (`expo-clipboard` is not installed — use the existing export/copy path or a
  read-only `TextSharedField` plus a Copy button backed by `navigator.clipboard` on web), the
  password shown once with a warning that it will not be shown again, and the emailed note.
- Toasts follow the house rules: a loading toast on save, settling into success.

### 6.3 Guest sign-in

- `RootLayout` gets a public branch ahead of the login gate: on web, read
  `ShellRoutePathUtility.withoutBase(window.location.pathname)`; a path matching
  `/share/<code>` renders `ShareAccessScreen` instead of `LoginScreen` (native never sees this
  route; the QR is a web link).
- `ShareAccessScreen`: the label, a password field, and the code step when the answer says
  `otpRequired`; errors inline; a Resend action with its cooldown; the same brand chrome as the
  login screen.
- On success it calls `AuthenticationService.enterAsGuest({token, expiresAt, label, rules})` and
  the app lands as a guest.

### 6.4 Guest session

- `SessionModel`/`UserModel` gain a guest shape: the user is a synthetic `Guest` (name from the
  share label, no email, no roles) with a `rules` map built from the share's keys at reach `all`,
  so `AuthenticationState.holdsRule` hides everything else.
- `AuthenticationState` gains `isGuest`.
- The guest session is stored in `PreferencesStorage` under `guest.session` — **the documented
  exception** to rule 21, because the whole point is a link that keeps working. It carries the
  token, its expiry and the share label; it is cleared on sign-out and when the expiry passes.
- `AuthenticationService.restoreSession()` restores it and **re-validates** with
  `GET share/session` (the guest token): a revoked or expired share signs the guest straight out.
- Sign-out clears the guest session and returns to the share screen (or the login screen when
  there is no code in the URL).

### 6.5 Guest shell

- `ShellLayoutController.navigationFor` gains a guest branch: keep only routes whose rule is in
  the share's rules — `Access` (personal, unruled), `Settings` and the profile menu are dropped
  even though they have no rule. The bottom nav reuses the filtered tree.
- `landingRoute` is the first granted page in sidebar order.
- The banner shows a **Guest** chip: the share label, and a Sign out action. The notification
  bell is not rendered for a guest (and its API is never called — the guest token is not an
  employee).
- A slim **"Read-only guest access"** hint sits under the breadcrumb or in the banner.
- The holidays load in `RootLayout` is skipped for a guest (it needs the `calendar` rule).
- Writes need no work: no write rule is ever granted, so every `canEdit`/`canDelete`/`canExport`
  getter already hides its control, and the server refuses non-GET regardless.

## 7. Guest semantics and per-page readiness

A guest has **no person**. With `PortalRules` answering at reach `all` (5.4):

- **Reach-based pages read everything** the page would show to an all-reach viewer: View
  Projects, Track Projects, all Finance pages, All Logs / All Actions, Missing Reports, Event
  Calendar, the Manage queues.
- **Person-scoped pages read empty or zero**: Home's "your month", User Requests, Submitted
  Reports, your Recycle Bin, User Logs, and anything keyed on `$actor->getKey()`. They open, they
  are just empty. That is the accepted behaviour until we decide what "all" should mean for a
  person-scoped page.
- **Writes are hidden and refused**, always.

Readiness checklist per page before flipping `shared_page_shareable.is_ready` to 1:

1. `GET` works with a guest token (no 500 from a null actor).
2. The rows are what an all-reach viewer would see, or an honest empty state.
3. No write control is visible.
4. No GET writes a log row keyed to the actor in a way that breaks (e.g. Users' "Show employee
   information" VIEW log) — if it does, either fix it or leave the page not ready.
5. Quick portal screen test with a guest session.

First set to verify (seeded 1 in section 4; lower any that fail): `projects.view`,
`projects.track`, `finance.overview`, `finance.transactions`, `finance.envelopes`,
`finance.accounts`, `calendar`. Then the reach-based admin pages, then the rest.

## 8. Security

- The password and the OTP are stored **hashed**; the plain password exists only in the create /
  regenerate response (and the email, when bound).
- The code is 96 random bits; a wrong code and a wrong password answer identically.
- Guests are **GET-only** at the middleware, not by convention; every write route is behind the
  refusal.
- Every guest request re-checks `revoked_at` and `expires_at`, so revoke and expiry are instant
  even for a stored session.
- `manage.shares` has `min_tier = developer`, so only Developer mode holds it, and no gift or
  limit can reach it (the developer-tools rule).
- Emails carry the label, the link, the password/OTP — never employee data.
- The public endpoints are throttled, and the share's own request cap is enforced on its token.
- Visits are logged (share, time, IP) so the developer can see use; there is no per-page audit of
  a read-only guest.
- The `guest.session` entry in PreferencesStorage is the one deliberate exception to rule 21;
  it is scoped to a share, carries its expiry, and dies on sign-out or revoke.

## 9. Tests

**Admin (`php artisan test`)**

- `tests/Feature/PortalSharedPagesTest.php`
  - the page is developer-only: a member/admin token gets 403.
  - create returns a password once and stores only the hash; the email goes out when bound.
  - edit changes label/email/expiry/rate/rules; a rule not in `shared_page_shareable` (or not
    ready) is refused.
  - regenerate returns a new password and invalidates sessions; revoke kills a live token on the
    next request.
  - the QR returns SVG.
- `tests/Feature/PortalShareAccessTest.php`
  - `access` with a wrong password is refused generically; the attempt cap answers 429.
  - with an email bound: `access` answers `otpRequired` and mails a code; a wrong/expired/too-many
    code is refused; `verify` returns a token; with no email, `access` returns the token directly.
  - a guest token: GET on a granted page works, GET on an ungranted page is 403, **any non-GET is
    403**, the share's rate cap answers 429, `last_used_at` and a visit row are written.
  - `revoked_at` and `expires_at` end a live session.

**Portal (`npm run typecheck`, `npm run lint`, `npm test`)**

- controller tests for create/edit/revoke/regenerate and for the sign-in flow (password, OTP,
  errors, cooldown);
- a screen test for the page (table, toggles all off, "Not ready" disabled, result card) and for
  `ShareAccessScreen`;
- a shell test that a guest session keeps only the granted routes in the nav, hides Access /
  Settings / the bell, shows the Guest chip, and that sign-out clears the stored session;
- an authentication test that a stored guest session restores, and is dropped when the share is
  revoked (the `share/session` stub answers 401).

**Manual, once built**

- Create a share in Developer mode, open the link in a private window, sign in, walk the granted
  pages, try a write, revoke from the panel, confirm the guest is thrown out on the next press.

## 10. Build order

1. **Server core**: section 4 SQL, `PortalSharedPages`, `ShareController`, guest branch in
   `AuthenticatePortalJwt`, `PortalRules` guest override, limiters, QR, emails. Tests green.
2. **Manage / Shared Pages**: wiring (route, rule, nav, bootstrap), model + endpoints, controller,
   screen, QR widget. Tests green.
3. **Guest sign-in + shell**: public route, `ShareAccessScreen`, `enterAsGuest`, `isGuest`, guest
   nav filtering, persistence + restore + revalidate. Tests green.
4. **Readiness pass**: run the checklist on the first set, flip `is_ready`, then walk the rest
   page by page. Each pass is its own small commit.

Definition of done: a developer can make a share, the guest can sign in (with and without OTP),
sees exactly the granted pages, cannot write, and revoking ends it immediately — with the tests
above green and the page visual-checked in the live app.

## 11. Deployment

- **Admin**: `composer require bacon/bacon-qr-code`; `composer.lock` changes, `vendor/` is
  gitignored so the server needs `composer install` at deploy. Run the section 4 DDL and seed on
  each existing database (local `reset.ps1` recreates it; the test server gets the same
  statements, as References did).
- **Portal**: no new package; the usual web export/deploy.
- The share link is built with `ShellRoutePathUtility.withBase('/share/<code>')`, so it carries
  the subfolder base (`/website_a85fbf18/portal`) automatically.

## 12. Open / parked

- What a **person-scoped** page should show a guest (`all` instead of empty) is undecided; today
  it reads empty.
- Whether the guest chip should also offer a "Pages you can see" list. Parked.
- Whether a share may have **no expiry** at all — allowed, but it is the weakest setting.
- The per-page readiness list will grow; a page-level rule added to the catalog but missing from
  `shared_page_shareable` simply never appears in the toggles.
- If another developer tool needs the same guest mechanism (e.g. a kiosk), the token and the
  shell branch are reusable; the share object is not.

## 13. References

- Power system: `FINE_GRAINED_RULES.md`, `../ikaika-portal/power-rules.md`,
  `../ikaika-portal/developer-mode.md`.
- A page plan in this folder: `view-projects-plan.md` (shape and tone).
- Server: `app/Support/Portal/PortalRules.php`, `app/Support/Portal/PortalJwt.php`,
  `app/Http/Middleware/AuthenticatePortalJwt.php`, `app/Http/Middleware/RequirePortalRule.php`,
  `app/Providers/AppServiceProvider.php` (the `portal-*` limiters),
  `app/Support/Portal/PortalEmailMessages.php` (mail), `app/Support/Portal/PortalReferences.php`
  (a Manage page's support class to copy).
- Portal: `src/core/constants/route-rule.constant.ts`,
  `src/core/controllers/layouts/shell.layout.controller.ts`, `src/layouts/root.layout.tsx`,
  `src/screens/administration.references.screen.tsx` (page shape),
  `src/components/shared/tables/data-table.shared.table.tsx`,
  `src/components/shared/lists/power.shared.list.tsx` + `switch.shared.button.tsx` (toggles),
  `src/core/storages/preferences.storage.ts` / `secure.storage.ts` (session storage),
  `react-native-svg` (`SvgXml`) for the QR.
