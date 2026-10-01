# Location and accountability — plan

Status: **step 2 built (2026-10-01); steps 1, 3 and 4 planned.** Company policy: every action in the portal
must be traceable to the person who did it. A significant ("ground-breaking") action must not
happen without that person confirming it is them.

## What already holds today

- Every write needs a signed-in session. The server knows who made it from the token, not from
  anything the page sends.
- Every write is recorded twice: in All Logs (`logs`, with IP, user agent and location if sent)
  and in All Actions (`core.actions`, with the actor's id and id number).
- Developer mode already asks for the developer's own password before it turns on. That is the
  pattern step 4 reuses.

## The problem found (2026-10-01, Playwright)

Before every write, the portal asks the device for its location (`LocationManager.actionLocation`).
Two things can go wrong:

- **No timeout.** If the browser's permission prompt is never answered, the save shows
  "Saving..." forever. A slow GPS fix can hold it up too.
- **Location is optional.** A user who blocks location still writes; the row just has no
  location, or only the network (IP) guess.

## What browsers allow (and do not)

- A page **cannot** show the permission prompt again once the user clicked **Block**. Chrome,
  Edge, Safari and Firefox all prevent it on purpose. Only the user can undo it, from the lock
  icon next to the address, or from site settings.
- A page **can** read the current state (`navigator.permissions.query({ name: 'geolocation' })`:
  granted, denied or prompt), and **listen for changes**. The moment the user switches it back
  on, the page knows without a reload.
- In the phone app, a "Turn on" button can open the app's own settings
  (`Linking.openSettings()`), and Android may ask again unless "Don't ask again" was ticked.
- Location comes from the user's device, so it **can be faked**. It is context for an action,
  not proof. Identity (step 4) is the real accountability.

## The plan

### 1. Location modal on every write

When a write starts and location is not granted, a modal stops it:

| Permission | The modal shows | When it is allowed |
|---|---|---|
| Not asked yet (`prompt`) | Why location is needed (company policy, recorded with each action) and **Turn on** | **Turn on** opens the browser's real prompt; on Allow the action continues by itself |
| Blocked (`denied`) | Short steps for this browser: click the lock icon, set Location to Allow | The page watches the permission; it closes and the action continues as soon as it flips |
| Allowed (`granted`) | Nothing | The action goes straight through |

- Browsing stays open, so nobody lands on a blank screen. Only writes wait.
- **Cancel** abandons the action. Nothing is sent.
- Phone app: **Turn on** opens the app's settings page.
- The modal names the policy in one line, so the notice the Data Privacy Act asks for is shown
  where the data is collected.

### 2. Location timeout with a fallback (built 2026-10-01)

- Built in `LocationManager` (portal): the device read gets 8 seconds; a prompt still open is
  shared by the next action instead of asked again. Checked with Playwright: a save with the prompt
  never answered went through, logged with source `ip`.
- A device fix gets about 8 seconds. After that the write uses the network location (the IP
  lookup the portal already has) and carries `X-Portal-Location-Source: ip`.
- A save can then never hang on location again.

### 3. The server refuses writes without a location

- A write (POST, PATCH, PUT, DELETE) without `X-Portal-Location` is refused with 428 and a
  message the modal understands.
- Exempt: sign in, sign out, and Developer mode's own switch.
- Without this, the modal is only a request: anyone calling the API directly would skip it.
- `?` Whether IP-only location is good enough, or a device fix is required. Recommended: accept
  IP when the device fix times out, but still require the permission itself.

### 4. Confirm it is you before a significant action

Before a significant action, the portal asks the person to confirm their identity again:
- their password, as Developer mode does;
- or a fresh Microsoft sign-in, for accounts that use it.

- The confirmation is short-lived: one confirmation covers that action, or a few minutes, not
  the whole session.
- The server checks it, not only the page: the action's endpoint refuses without a fresh
  confirmation token.
- It is recorded on the action's log row ("confirmed with password"), so All Logs shows the
  person explicitly confirmed it.
- `?` **Which actions count.** Candidates, to decide:
  - deleting a project, report or request; restoring from the Recycle Bin;
  - changing someone's role, or giving or limiting a power on the Access page;
  - approving or rejecting requests and reimbursements; charging an envelope;
  - sending an email to everyone;
  - turning Developer mode on (already done).
- `?` Microsoft accounts with no portal password: re-confirm through Microsoft sign-in
  (`prompt=login`), or set a portal PIN once.

## Order of work

1. Timeout and fallback (step 2). Small, and it fixes the hang on its own.
2. Location modal (step 1), behind one shared helper every write already goes through
   (`ApiManager.locationHeaders`), so screens do not change one by one.
3. Server refusal (step 3), once every client write sends a location.
4. Identity confirmation (step 4), after the list of significant actions is decided.

## Verification

- A write with permission `prompt`: the modal opens, Allow continues the same action once.
- A write with permission `denied`: the modal shows the steps; flipping the setting closes it
  and the action goes through.
- No device fix within the timeout: the write goes through with source `ip`.
- A direct API write without a location header: 428; sign in still works.
- A significant action without confirmation: refused by the server; with a fresh confirmation:
  accepted and logged as confirmed; a stale one: refused.
- Playwright: grant and deny geolocation per test (`context.grantPermissions`), since the
  automation browser never answers the real prompt.
