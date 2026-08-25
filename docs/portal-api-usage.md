# Ikaika Portal API — Usage Guide

Base URL:

```
https://ikaikabim.com/wp-json/api/v2/...
```

Every WordPress REST route is served under `/wp-json/` — this is not optional or configurable per-plugin, it's how WP core mounts `register_rest_route()` output. Forgetting it (e.g. hitting `.../api/v2/pm/projects` instead of `.../wp-json/api/v2/pm/projects`) means the request never reaches WordPress's router at all, and whatever the host/Apache does with the unrecognized path takes over instead — which can look like an unrelated 403/500 with no connection to anything in this plugin's code.

All 43 routes are registered under the single namespace `api/v2` in `routes - portal-ikaikabim.php`. WordPress must be running with pretty permalinks enabled for `/wp-json/...` to resolve.

<!-- toc -->

## Contents

- [Read first: what lives outside this repo](#read-first-what-lives-outside-this-repo)
- [Response envelope](#response-envelope)
- [Authentication](#authentication)
- [Errors & status codes](#errors--status-codes)
- [Request body conventions](#request-body-conventions)
  - [Style A — `payload` via `get_param`](#style-a--payload-via-get_param)
  - [Style B — raw JSON body, `payload` key](#style-b--raw-json-body-payload-key)
  - [Style C — raw JSON body, `recordIds` key](#style-c--raw-json-body-recordids-key)
  - [Record shape inside `payload`](#record-shape-inside-payload)
- [Attachment payloads — three different shapes](#attachment-payloads--three-different-shapes)
- [Filtering — the `filter` param is JSON-decoded](#filtering--the-filter-param-is-json-decoded)
- [No row limit: every list endpoint returns the whole table](#no-row-limit-every-list-endpoint-returns-the-whole-table)
- [Endpoint summary](#endpoint-summary)
- [Client foundation](#client-foundation)
  - [React Native](#react-native)
  - [Postman](#postman)
- [Endpoint reference](#endpoint-reference)
  - [CURRENT USER](#current-user)
  - [TIME MONITORING](#time-monitoring)
  - [PROJECT MANAGEMENT](#project-management)
  - [PURCHASING](#purchasing)
  - [REIMBURSEMENTS](#reimbursements)
- [Known inconsistencies](#known-inconsistencies)

<!-- /toc -->

## Read first: what lives outside this repo

This repository contains exactly two files — the route/handler file and the Airtable service file. Three things these routes depend on are **defined elsewhere on the server** and cannot be described from this codebase:

| Symbol | Used by | What is unknown from here |
|---|---|---|
| `validateCookieSession()` | all 43 `permission_callback`s | how a session is established, how long it lasts, how it is revoked |
| `getCurrentUser()` | `GET /currentUser` | the entire response shape of that endpoint |
| `patchAirtableRecords()`, `deleteAirtableRecords()` | 5 handlers | their exact return shape; the routes read `['records']` and `['response']` from them |

The 5 handlers calling the external global helpers are `patchUserReports`, `deleteUserReports`, `REDACTED_AIRTABLE_PAT`, `deleteUserReportScopeProgress` and `REDACTED_AIRTABLE_PAT`. Note that `AirtableService` in this repo *also* has methods named `patchAirtableRecords`/`deleteAirtableRecords`, but those take 2 arguments and are called on an instance — the route handlers call 4-argument **global** functions of the same name. They are not the same code.

**There is no versioning, deprecation, rate-limiting or maintenance-mode machinery in this API.** The estimator API has all four; this one has none. Do not write client code that expects `426`, `429` or `503` from these routes — nothing here emits them.

## Response envelope

Handlers return the same JSON shape:

```json
{
  "success": true,
  "data": { },
  "message": "..."
}
```

On failure, `success` is `false` and an `error` key carries the underlying reason (usually raw Airtable or cURL error text). Check `success`, not just the HTTP status code.

**`data` is not always present on success.** Several write endpoints return only `success` and `message`:

| Returns `data` on success | Returns `message` only |
|---|---|
| every `GET`, plus `POST /pm/reports`, `POST /pm/createProject`, `POST /pm/modifyProject`, `POST /pm/deleteProject`, the three `/pm/*ProjectAssignedScope*` writes, `POST /pm/userReportScopeProgress`, `POST /pm/updateProjectActionHistory`, and the `PATCH`/`DELETE` pairs on `/pm/reports` and `/pm/userReportScopeProgress` | `POST /tm/workoffset`, `POST /tm/overtime`, `POST /management/purchasing/accounts`, `POST /management/purchasing/envelopes`, `POST /management/purchasing/transactions`, `POST /reimbursements`, `PATCH /reimbursements/{id}` |

Do not read `data` on the right-hand column — it is absent, not empty. Re-fetch the matching list endpoint if you need the created record back.

## Authentication

**Cookie/session, not bearer tokens.** Every one of the 43 routes has an identical `permission_callback`:

```php
'permission_callback' => function (WP_REST_Request $request) {
    if (validateCookieSession()) {
        return true;
    }
    return false;
}
```

`validateCookieSession()` is defined outside this repository, on the server. What is observable from here:

1. There is **no** `Authorization` header handling, no `Bearer` parsing, and no API-key check anywhere in these two files.
2. There is **no login endpoint in this namespace.** A session must already exist before any of these routes will answer. Whatever issues it is out of scope for this document.
3. The callback returns a bare `false` on failure, never a `WP_Error`, so WordPress generates its own rejection — see [Errors & status codes](#errors--status-codes).

**For a React Native client this matters.** Cookie auth assumes a cookie jar that the platform populates and replays. React Native's `fetch` does use the native cookie store (`CookieManager` on Android, `NSHTTPCookieStorage` on iOS), so a session cookie set by an earlier same-host request is normally sent automatically — but this is far less predictable than an explicit header, and behaviour differs across iOS, Android and Expo Go. Verify cookie replay on both platforms early rather than assuming it. If it proves unreliable, that is a server-side change (a token path) and not something a client workaround can fix.

## Errors & status codes

Failures come back in **two different shapes** — the client must handle both.

**1. Handler error (our envelope)** — the request reached the endpoint and it rejected it. Always has `success: false`:
```json
{ "success": false, "message": "Failed to fetch projects!", "error": "Airtable API returned HTTP status 422 with body: ..." }
```

**2. Permission error (WordPress)** — blocked by the `permission_callback` *before* the handler ran. This is WordPress's own shape and has **no `success` key**:
```json
{ "code": "rest_forbidden", "message": "Sorry, you are not allowed to do that.", "data": { "status": 401 } }
```

| Status | Shape | Meaning | Client action |
|---|---|---|---|
| `200` | `success: true` | OK | use `data` if the endpoint returns it |
| `200` | `success: true` + `attachment_errors` | **only `POST /management/purchasing/transactions`** — transactions created, some receipts failed | show a partial-success warning, offer receipt re-upload |
| `400` | `success: false` | missing/invalid input — `Invalid JSON body`, `Missing payload`, `Missing record IDs`, `Missing record ID`, or a duplicate name on the two purchasing creates | fix request, show `message` |
| `401` | `code: rest_forbidden` | no valid session (not logged in) | route to login |
| `403` | `code: rest_forbidden` | logged into WordPress, but `validateCookieSession()` still said no | show a permissions error; do **not** loop back to login |
| `404` | `code: rest_no_route` | wrong URL/method, missing `/wp-json/`, or the plugin is not deployed | check URL + deployment |
| `500` | `success: false` (+ `error`) | **any** Airtable/upstream failure, and any "record not found after create" case | retry/report, show a generic error |

**The `401` vs `403` split is WordPress's, not this plugin's.** Because the callback returns bare `false`, WP core builds the error using `rest_authorization_required_code()`, which is `401` when no user is logged in and `403` when one is. Handle both as "not authorized"; only `401` means "send them to login".

**There is no `502`.** Upstream Airtable failures — unreachable, bad formula, a 422 from Airtable, a cURL error — all surface as **`500`** with the raw upstream text in `error`. If you are porting client code from the estimator API, which uses `502` for exactly this case, that branch will never fire here.

**`error` contains raw upstream text.** Airtable's response body is passed through verbatim (`"Airtable API returned HTTP status 422 with body: {...}"`). Useful in logs; do not render it to end users.

## Request body conventions

There are **three different ways** these handlers read a request body, and which one applies is per-endpoint. This is the single most common source of "why is my request a 400".

### Style A — `payload` via `get_param`

```php
$payload = $request->get_param('payload');
```

`get_param()` searches query string, JSON body and form body, so `payload` may be sent as a JSON body key. **There is no validation** — a missing `payload` is passed to Airtable as `null` and comes back as a `500`, not a `400`.

Used by: `POST /tm/workoffset`, `POST /tm/overtime`, `POST /pm/reports`, all `/pm/create*`, `/pm/modify*`, `/pm/deleteProject`, `/pm/deleteProjectAssignedScope`, `POST /pm/userReportScopeProgress`, `POST /pm/updateProjectActionHistory`, both purchasing `POST`s, and `POST /reimbursements`.

### Style B — raw JSON body, `payload` key

```php
$body = json_decode($request->get_body(), true);
if (!$body) { return 400 'Invalid JSON body'; }
$payload = $body['payload'] ?? null;
if (empty($payload)) { return 400 'Missing payload'; }
```

These **do** validate and return a real `400`. The body must be JSON — a form-encoded body fails with `Invalid JSON body`.

Used by: `PATCH /pm/reports`, `PATCH /pm/userReportScopeProgress`, `PATCH /reimbursements/{id}`.

### Style C — raw JSON body, `recordIds` key

```php
$recordIds = $body['recordIds'] ?? null;
if (empty($recordIds)) { return 400 'Missing record IDs'; }
```

Used by: `DELETE /pm/reports`, `DELETE /pm/userReportScopeProgress`.

This is **not** how the other deletes work — `POST /pm/deleteProject` and `POST /pm/deleteProjectAssignedScope` use Style A with a `payload` key instead.

### Record shape inside `payload`

For creates, `payload` is handed to `createAirtableRecords()`, which chunks it 10 at a time and wraps anything lacking a `fields` key:

```php
$chunks = array_chunk($records, 10);
return isset($rec['fields']) ? $rec : ['fields' => $rec];
```

**Send an array of records.** This is the shape the code is written for, and the only one that works for more than one record:

```json
{ "payload": [ { "fields": { "Name": "A" } }, { "fields": { "Name": "B" } } ] }
```

A single unwrapped record object also works — `array_chunk` on the associative array yields one chunk whose single element is the inner fields map, which then gets re-wrapped correctly:

```json
{ "payload": { "fields": { "Name": "A" } } }
```

Two endpoints **require** that single-record form because they index into it directly: `POST /management/purchasing/accounts` reads `payload.fields['Account Name']`, and both it and `POST /reimbursements` read `result[0].id` to attach a file afterwards.

Chunking is automatic and transparent — sending 25 records issues 3 Airtable requests. There is no cross-chunk rollback: if chunk 2 fails, chunk 1's records already exist and you still get a `500`.

## Attachment payloads — three different shapes

Three endpoints accept files, and **each expects a different structure**. Note especially that one uses `base64` where the others use `base64Content`.

**1. `POST /management/purchasing/accounts` and `POST /reimbursements`** — the standard `uploadAttachments()` shape. Either wrapped in `fields` or bare; keys are Airtable field names, values are arrays of files:

```json
{
  "attachment_payload": {
    "fields": {
      "QR Code": [
        { "filename": "qr.png", "contentType": "image/png", "base64Content": "iVBORw0..." }
      ]
    }
  }
}
```

**2. `POST /management/purchasing/transactions`** — an array, one entry per receipt, indexed against the transactions sent in `payload`:

```json
{
  "attachment_payload": [
    {
      "transactionIndex": 0,
      "receiptData": { "filename": "r.jpg", "contentType": "image/jpeg", "base64Content": "/9j/4AA..." }
    }
  ]
}
```

`transactionIndex` is the position in the `payload` array. An index with no matching created transaction is reported in `attachment_errors`, not thrown. Entries with empty `receiptData` are skipped silently.

**3. `PATCH /reimbursements/{id}`** — a single object, and the base64 key is **`base64`**, not `base64Content`:

```json
{
  "attachment_payload": {
    "fieldName": "Receipt",
    "file": { "filename": "r.jpg", "contentType": "image/jpeg", "base64": "/9j/4AA..." }
  }
}
```

**Failure behaviour differs too.** For shapes 1 and 3 a failed upload is a `500` — and for shape 1 the text record has *already been created*, so a naive retry duplicates it. For shape 2 a failed upload is a `200` with `attachment_errors` populated.

A file object with no base64 content is dropped silently by `uploadAttachments()`. If *every* file is dropped, the helper returns `No Files Uploaded!`, which the route reports as a `500`.

## Filtering — the `filter` param is JSON-decoded

Three endpoints take an optional `filter` query param: `GET /pm/reports`, `GET /pm/offset` and `GET /pm/projectscopes`. The handler does this:

```php
$filter = $request->get_param('filter');
if ($filter) { $filter = json_decode($filter, true); }
```

The decoded value is then passed to Airtable as `filterByFormula`.

**A bare formula string does not work.** `json_decode("{Status}='Active'", true)` is invalid JSON and returns `null`, so the filter is silently dropped and you get **the entire table back with a `200`** — no error, no warning, just the wrong result set.

The value must be a **JSON-encoded string**, i.e. the formula wrapped in double quotes:

```
GET /pm/reports?filter="{Status}='Active'"          <- works (URL-encode it)
GET /pm/reports?filter={Status}='Active'            <- silently returns everything
```

In practice: `JSON.stringify(formula)`, then let the URL builder encode it.

```js
const filtered = await api.get('/pm/reports', { filter: JSON.stringify("{Status}='Active'") });
```

Field names in `filterByFormula` are case-sensitive and must match the Airtable table exactly. A misspelled field makes Airtable return a `422`, which surfaces here as a `500`.

**`GET /pm/projectscopes` applies the same filter to all four scope tables** (T1 processes, T2 procedures, T3 activities, T4 tasks). A formula referencing a field that exists in only one of them fails the whole request, because any one of the four failing returns early.

## No row limit: every list endpoint returns the whole table

Unlike the estimator API, there is **no `limit` parameter anywhere in this namespace**. `fetchAirtableRecords()` loops on Airtable's `offset` cursor until the table is exhausted:

```php
do { ... $offset = $response['offset'] ?? null; } while ($offset);
```

Every `GET` walks the full table — 100 records per upstream request, serially, with no cap and no timeout guard. A large table means a slow response and a large payload, and `GET /pm/projectscopes` does this **four times in one request**.

There is no caching either. Plan for these calls to be slow, fetch them once and hold them client-side, and keep them off a screen's render path.

## Endpoint summary

All paths are relative to `https://ikaikabim.com/wp-json/api/v2`. Every route requires a valid session (see [Authentication](#authentication)); the Auth column is omitted because it is uniform.

| Method | Path | Body / Params | Body style |
|---|---|---|---|
| GET | `/currentUser` | — | — |
| GET | `/tm/useraccounts` | — | — |
| POST | `/tm/workoffset` | `payload` | A |
| POST | `/tm/overtime` | `payload` | A |
| GET | `/tm/earncodes` | — | — |
| GET | `/tm/calendarearncodes` | — | — |
| GET | `/tm/activitycodes` | — | — |
| GET | `/tm/holidays` | — | — |
| GET | `/tm/userwarnings` | — | — |
| GET | `/pm/projects` | — | — |
| GET | `/pm/reports` | `filter` (JSON-encoded formula, optional) | — |
| POST | `/pm/reports` | `payload` | A |
| PATCH | `/pm/reports` | `payload` | B |
| DELETE | `/pm/reports` | `recordIds` | C |
| GET | `/pm/offset` | `filter` (optional) | — |
| GET | `/pm/projectscopes` | `filter` (optional) — returns `t1`/`t2`/`t3`/`t4` | — |
| GET | `/pm/project-assigned-scopes` | — | — |
| GET | `/pm/clients` | — | — |
| GET | `/pm/projectAllowedActivities` | — | — |
| GET | `/pm/getFieldsOfSpecificType` | `tableName`, `fieldType` | — |
| POST | `/pm/createProject` | `payload` | A |
| POST | `/pm/modifyProject` | `payload` (records with `id` + `fields`) | A |
| POST | `/pm/deleteProject` | `payload` (record id(s)) | A |
| POST | `/pm/createProjectAssignedScope` | `payload` | A |
| POST | `/pm/modifyProjectAssignedScope` | `payload` | A |
| POST | `/pm/deleteProjectAssignedScope` | `payload` (record id(s)) | A |
| GET | `/pm/userReportScopeProgress` | — | — |
| POST | `/pm/userReportScopeProgress` | `payload` | A |
| PATCH | `/pm/userReportScopeProgress` | `payload` | B |
| DELETE | `/pm/userReportScopeProgress` | `recordIds` | C |
| GET | `/pm/projectActionHistory` | — | — |
| POST | `/pm/updateProjectActionHistory` | `payload` — creates, does not update | A |
| POST | `/management/purchasing/accounts` | `payload` (single record), `attachment_payload` (shape 1) | A |
| GET | `/management/purchasing/accounts` | — | — |
| GET | `/management/purchasing/transactions` | — | — |
| POST | `/management/purchasing/transactions` | `payload` (array), `attachment_payload` (shape 2) | A |
| GET | `/management/purchasing/transactiontypes` | — — options of the `Type` field | — |
| GET | `/management/purchasing/envelopes` | — | — |
| POST | `/management/purchasing/envelopes` | `payload` (single or array) | A |
| GET | `/management/purchasing/envelopegroups` | — — options of the `Group` field | — |
| POST | `/reimbursements` | `payload` (single record), `attachment_payload` (shape 1) | A |
| PATCH | `/reimbursements/{id}` | `payload.fields`, `attachment_payload` (shape 3) | B |
| GET | `/reimbursements` | — | — |

Body style refers to [Request body conventions](#request-body-conventions): **A** = `payload` via `get_param` (no validation), **B** = raw JSON body with `payload` (validated), **C** = raw JSON body with `recordIds` (validated).

## Client foundation

Set this up once; every per-endpoint example below reuses it.

### React Native

```js
// api.js
const BASE_URL = 'https://ikaikabim.com/wp-json/api/v2';

export class ApiError extends Error {
  constructor(message, status, body) {
    super(message);
    this.status = status;
    this.body = body;
    this.isAuth = body?.code === 'rest_forbidden';
    this.needsLogin = this.isAuth && status === 401;
  }
}

async function request(path, { method = 'GET', body, query } = {}) {
  let url = BASE_URL + path;
  if (query) {
    const qs = Object.entries(query)
      .filter(([, v]) => v !== undefined && v !== null)
      .map(([k, v]) => `${encodeURIComponent(k)}=${encodeURIComponent(v)}`)
      .join('&');
    if (qs) url += `?${qs}`;
  }

  const res = await fetch(url, {
    method,
    credentials: 'include', // replays the WordPress session cookie
    headers: body ? { 'Content-Type': 'application/json' } : undefined,
    body: body ? JSON.stringify(body) : undefined,
  });

  const json = await res.json().catch(() => null);

  // WordPress permission failure - no `success` key
  if (json?.code === 'rest_forbidden') {
    throw new ApiError('Not authorized', res.status, json);
  }
  // handler envelope
  if (!json || json.success !== true) {
    throw new ApiError(json?.message ?? 'Request failed', res.status, json);
  }
  return json;
}

export const api = {
  get:   (path, query) => request(path, { query }),
  post:  (path, body)  => request(path, { method: 'POST', body }),
  patch: (path, body)  => request(path, { method: 'PATCH', body }),
  del:   (path, body)  => request(path, { method: 'DELETE', body }),
};
```

Three things this client deliberately handles:

- **It throws on `success: false` even at `200`.** Duplicate-name rejections arrive as `400 success:false`, but the envelope is the reliable signal, not the status.
- **It separates `rest_forbidden` from handler errors,** and only sets `needsLogin` on a `401`. A `403` means the session exists but was refused — bouncing to login would loop.
- **It does not assume `data` exists.** Callers read `res.data` only on endpoints that return it (see [Response envelope](#response-envelope)).

Use `ApiError.body.error` for logging only — it is raw Airtable text.

### Postman

Create an environment with:

```
base_url = https://ikaikabim.com/wp-json/api/v2
```

Because auth is cookie-based, sign in to the WordPress site once in Postman's built-in browser (or import the session cookie into the cookie jar for the `ikaikabim.com` domain). Postman replays jar cookies automatically after that. Requests below use `{{base_url}}`.

## Endpoint reference

---

### CURRENT USER

#### GET `/currentUser` — the signed-in user

**The handler for this route is not in this repository.** Its callback is registered as the bare string `'getCurrentUser'` — unlike every other route, which registers `__NAMESPACE__ . '\\handlerName'` — so it resolves to a global function defined elsewhere on the server. Its response shape cannot be documented from this codebase.

```
GET {{base_url}}/currentUser
```

```js
const me = await api.get('/currentUser');
```

Treat the shape as unknown until confirmed against the running server.

---

### TIME MONITORING

#### GET `/tm/useraccounts` — user accounts

Returns every record in the users table.

```
GET {{base_url}}/tm/useraccounts
```

```js
const res = await api.get('/tm/useraccounts');
const users = res.data; // [{ id, createdTime, fields: {...} }, ...]
```

On failure: `500 { success: false, message: 'Failed to fetch user accounts!', error }`.

#### GET `/tm/earncodes` — earn codes

#### GET `/tm/calendarearncodes` — calendar earn codes

#### GET `/tm/activitycodes` — activity codes

#### GET `/tm/holidays` — company holidays

#### GET `/tm/userwarnings` — user warnings

These five behave identically: no parameters, whole table returned as `data`, `500` with a `Failed to fetch ...!` message on any upstream failure.

```
GET {{base_url}}/tm/earncodes
GET {{base_url}}/tm/calendarearncodes
GET {{base_url}}/tm/activitycodes
GET {{base_url}}/tm/holidays
GET {{base_url}}/tm/userwarnings
```

```js
const [earnCodes, activityCodes, holidays] = await Promise.all([
  api.get('/tm/earncodes'),
  api.get('/tm/activitycodes'),
  api.get('/tm/holidays'),
]);
```

`/tm/earncodes` and `/tm/calendarearncodes` read **different tables in different bases** despite the similar names — they are not interchangeable. `/tm/userwarnings` returns the message `Failed to fetch earncodes!` on error; that string is a copy-paste bug, not a sign you hit the wrong route.

Use these to resolve linked-record IDs. A report's `Activity` field holds something like `["rec1Om7..."]`; build an `id -> fields.Name` map client-side:

```js
const byId = Object.fromEntries(activityCodes.data.map(r => [r.id, r.fields.Name]));
```

#### POST `/tm/workoffset` — create a work offset record

#### POST `/tm/overtime` — create an overtime request

Both write to the **same requests table** and differ only in their response message. Body style A.

```
POST {{base_url}}/tm/workoffset
POST {{base_url}}/tm/overtime
```

```js
await api.post('/tm/workoffset', {
  payload: [
    { fields: { /* Airtable field names */ } },
  ],
});
```

Returns `200 { success: true, message: 'New Work Offset Record Created!' }` — **no `data`**. If you need the created record, re-fetch `GET /pm/offset`.

**No input validation.** Omitting `payload` does not give you a `400`; it reaches Airtable as `null` and returns `500 'Failed to Upload Work Offset Record!'`.

---

### PROJECT MANAGEMENT

#### GET `/pm/projects` — projects

```
GET {{base_url}}/pm/projects
```

```js
const projects = (await api.get('/pm/projects')).data;
```

#### GET `/pm/clients` — project clients

#### GET `/pm/project-assigned-scopes` — scopes assigned to projects

#### GET `/pm/projectAllowedActivities` — allowed activities per project

#### GET `/pm/projectActionHistory` — project action history

#### GET `/pm/userReportScopeProgress` — scope progress on user reports

No parameters, whole table in `data`, `500` on upstream failure. Note `/pm/project-assigned-scopes` is the only hyphenated path in the namespace; the others use camelCase.

```
GET {{base_url}}/pm/clients
GET {{base_url}}/pm/project-assigned-scopes
GET {{base_url}}/pm/projectAllowedActivities
GET {{base_url}}/pm/projectActionHistory
GET {{base_url}}/pm/userReportScopeProgress
```

`GET /pm/project-assigned-scopes` returns the message `Failed to fetch project allowed activities!` on error — another copy-paste artifact; you are on the right route.

#### GET `/pm/reports` — user reports

Optional `filter`. **Must be a JSON-encoded formula string** — see [Filtering](#filtering--the-filter-param-is-json-decoded), because an unencoded one silently returns the whole table.

```
GET {{base_url}}/pm/reports
GET {{base_url}}/pm/reports?filter="{Status}='Active'"
```

```js
const all = await api.get('/pm/reports');

const filtered = await api.get('/pm/reports', {
  filter: JSON.stringify("{Status}='Active'"),
});
```

| Case | Status | Response |
|---|---|---|
| No records match | `200` | `{ success: true, data: [] }` — not an error |
| `filter` is not valid JSON | `200` | **entire table**, filter silently ignored |
| Formula references an unknown field | `500` | `{ success: false, message: 'Failed to fetch user reports!', error: '...422...' }` |

#### GET `/pm/offset` — user offset records

Same filter semantics as `/pm/reports`. Reads the requests table — the one `POST /tm/workoffset` and `POST /tm/overtime` write to.

```
GET {{base_url}}/pm/offset?filter="{Type}='Overtime'"
```

#### GET `/pm/projectscopes` — the four-tier scope tree

Returns four tables in one response, keyed by tier:

```json
{
  "success": true,
  "data": {
    "t1": [ /* processes */ ],
    "t2": [ /* procedures */ ],
    "t3": [ /* activities */ ],
    "t4": [ /* tasks */ ]
  }
}
```

```js
const { data } = await api.get('/pm/projectscopes');
const { t1, t2, t3, t4 } = data;
```

The optional `filter` is applied to **all four tables**. A formula naming a field that exists in only one tier fails the entire request with a `500` — the handler checks each fetch in order (t1, t2, t3, t4) and returns on the first failure, so `message` tells you which tier broke.

This is the heaviest endpoint in the API: four full table walks, each paginating until exhausted. Fetch it once and cache it.

#### GET `/pm/getFieldsOfSpecificType` — schema introspection

Query params `tableName` and `fieldType`. Returns fields of that type from the users-and-projects base.

```
GET {{base_url}}/pm/getFieldsOfSpecificType?tableName=Projects&fieldType=multipleAttachments
```

```js
const res = await api.get('/pm/getFieldsOfSpecificType', {
  tableName: 'Projects',
  fieldType: 'multipleAttachments',
});
```

`fieldType` values are Airtable's own type strings (`singleSelect`, `multipleAttachments`, `formula`, …). Neither parameter is validated — omitting them yields a `500`, not a `400`. The `data` here wraps the helper's whole result object rather than a bare list, so inspect it before binding to it.

#### POST `/pm/reports` — create user reports

Body style A. Returns `data` with the created records.

```
POST {{base_url}}/pm/reports
```

```js
const res = await api.post('/pm/reports', {
  payload: [
    { fields: { /* ... */ } },
  ],
});
const created = res.data; // [{ id, fields, createdTime }]
```

#### PATCH `/pm/reports` — update user reports

Body style **B** — raw JSON body, validated. `payload` may be a single record or an array; each needs an `id` and a `fields` object.

```
PATCH {{base_url}}/pm/reports
```

```js
await api.patch('/pm/reports', {
  payload: [
    { id: 'recXXXXXXXXXXXXXX', fields: { Status: 'Approved' } },
  ],
});
```

| Case | Status | Response |
|---|---|---|
| Body is not valid JSON | `400` | `{ success: false, message: 'Invalid JSON body' }` |
| `payload` missing or empty | `400` | `{ success: false, message: 'Missing payload' }` |
| Upstream failure | `500` | `{ success: false, message: 'Failed to update user reports', error }` |
| OK | `200` | `{ success: true, data: [...], message: 'User reports updated successfully' }` |

This handler calls the external global `patchAirtableRecords()` and reads `['records']` off its result — see [what lives outside this repo](#read-first-what-lives-outside-this-repo).

#### DELETE `/pm/reports` — delete user reports

Body style **C** — the key is `recordIds`, not `payload`.

```
DELETE {{base_url}}/pm/reports
```

```js
await api.del('/pm/reports', {
  recordIds: ['recXXXXXXXXXXXXXX', 'recYYYYYYYYYYYYYY'],
});
```

| Case | Status | Response |
|---|---|---|
| Body is not valid JSON | `400` | `{ success: false, message: 'Invalid JSON body' }` |
| `recordIds` missing or empty | `400` | `{ success: false, message: 'Missing record IDs' }` |
| Upstream failure | `500` | `{ success: false, message: 'Failed to delete user reports', error }` |
| OK | `200` | `{ success: true, data: [...], message: 'User reports deleted successfully' }` |

#### POST `/pm/createProject` — create a project

#### POST `/pm/modifyProject` — update a project

#### POST `/pm/deleteProject` — delete a project

All three are `POST` with body style A, including delete. `modifyProject` takes records with `id` + `fields`; `deleteProject` takes record id(s).

```
POST {{base_url}}/pm/createProject
POST {{base_url}}/pm/modifyProject
POST {{base_url}}/pm/deleteProject
```

```js
const created = await api.post('/pm/createProject', {
  payload: [{ fields: { Name: 'New Project' } }],
});

await api.post('/pm/modifyProject', {
  payload: [{ id: 'recXXXXXXXXXXXXXX', fields: { Status: 'Closed' } }],
});

await api.post('/pm/deleteProject', {
  payload: ['recXXXXXXXXXXXXXX'],
});
```

`modifyProject` returns the messages `Failed to patch user report!` / `User Report successfully patched!` — wrong nouns, copied from the reports handler. You are on the right route; the project was patched.

#### POST `/pm/createProjectAssignedScope` — assign a scope

#### POST `/pm/modifyProjectAssignedScope` — update an assigned scope

#### POST `/pm/deleteProjectAssignedScope` — remove an assigned scope

Same pattern as the project trio: all `POST`, all body style A, all returning `data` plus a message. Note the route paths are **singular** (`...Scope`) while the handler functions are plural.

```js
await api.post('/pm/createProjectAssignedScope', {
  payload: [{ fields: { /* ... */ } }],
});
```

#### POST `/pm/userReportScopeProgress` — create scope progress

Body style A, returns `data`.

#### PATCH `/pm/userReportScopeProgress` — update scope progress

Body style **B**, identical contract to `PATCH /pm/reports` (`Invalid JSON body` / `Missing payload` / `500` / `200`).

```js
await api.patch('/pm/userReportScopeProgress', {
  payload: [{ id: 'recXXXXXXXXXXXXXX', fields: { Progress: 50 } }],
});
```

#### DELETE `/pm/userReportScopeProgress` — delete scope progress

Body style **C** (`recordIds`). On success the message reads `User reports deleted successfully` — copied from the reports handler; the scope-progress records were deleted.

#### POST `/pm/updateProjectActionHistory` — append to action history

**This creates records; it does not update them.** Despite the name, the handler calls `uploadRecords()`, the same create path as every other `POST`. There is no update path for action history in this API.

```js
await api.post('/pm/updateProjectActionHistory', {
  payload: [{ fields: { /* ... */ } }],
});
```

---

### PURCHASING

#### GET `/management/purchasing/accounts` — purchasing accounts

#### GET `/management/purchasing/transactions` — transactions

#### GET `/management/purchasing/envelopes` — envelopes

No parameters, whole table in `data`. `GET /management/purchasing/envelopes` returns the error message `Failed to fetch transactions data!` — copy-paste again.

#### GET `/management/purchasing/transactiontypes` — options of the `Type` field

#### GET `/management/purchasing/envelopegroups` — options of the `Group` field

These two do **not** return records. They read the Airtable field schema and return the select options as `data`:

```
GET {{base_url}}/management/purchasing/transactiontypes
GET {{base_url}}/management/purchasing/envelopegroups
```

```js
const types = (await api.get('/management/purchasing/transactiontypes')).data;
// -> the possible values of the Type field, for a picker
```

Use them to populate dropdowns instead of hardcoding option lists. Both return `500 'Failed to fetch field values data!'` on failure.

#### POST `/management/purchasing/accounts` — create a purchasing account

Body style A, **single-record `payload`** plus an `attachment_payload` in [shape 1](#attachment-payloads--three-different-shapes). The handler reads `payload.fields['Account Name']` directly, so an array will not work here.

The sequence is: fetch all existing accounts → reject on a duplicate `Account Name` → create the record → upload the QR code attachment.

```js
await api.post('/management/purchasing/accounts', {
  payload: { fields: { 'Account Name': 'Ops Card' } },
  attachment_payload: {
    fields: {
      'QR Code': [
        { filename: 'qr.png', contentType: 'image/png', base64Content: qrBase64 },
      ],
    },
  },
});
```

| Case | Status | Response |
|---|---|---|
| `Account Name` already exists | `400` | `{ success: false, message: 'Account Already Exists!' }` |
| Existing-accounts fetch failed | `500` | `{ success: false, message: 'Failed to fetch existing accounts!', error }` |
| Create failed | `500` | `{ success: false, message: 'Failed to Upload new Purchasing Account!', error }` |
| Created but no id came back | `500` | `{ success: false, message: 'New Account not found!', error, data }` |
| Attachment failed | `500` | `{ success: false, message: 'Failed to Upload Account QR Code!', error }` |
| OK | `200` | `{ success: true, message: 'New Purchasing Account Created!' }` — no `data` |

**The duplicate check is a full table scan and is not atomic.** Two concurrent creates with the same name can both pass it.

**An attachment failure leaves the account already created.** The `500` is raised after the record exists, so a blind retry hits `Account Already Exists!` on the second attempt. Treat that specific `400`-after-`500` as "record exists, attachment missing" and repair the attachment rather than recreating.

#### POST `/management/purchasing/envelopes` — create envelopes

Body style A. Accepts a single record **or** an array — the handler normalizes both — and duplicate-checks every one against the existing `Envelope Name` values.

```js
await api.post('/management/purchasing/envelopes', {
  payload: [
    { fields: { 'Envelope Name': 'Fuel' } },
    { fields: { 'Envelope Name': 'Tools' } },
  ],
});
```

| Case | Status | Response |
|---|---|---|
| One duplicate | `400` | `{ success: false, message: 'Envelope Already Exists: Fuel', duplicates: ['Fuel'] }` |
| Several duplicates | `400` | `{ success: false, message: 'Envelopes Already Exist: Fuel, Tools', duplicates: [...] }` |
| OK | `200` | `{ success: true, message: 'New Envelopes Created!' }` — no `data` |

The `duplicates` array is a **top-level key, not inside `data`**. Records with no `Envelope Name` skip the duplicate check entirely and are created.

**It is all-or-nothing:** one duplicate in a batch of ten rejects the whole batch, and nothing is created. Filter client-side against `GET /management/purchasing/envelopes` first if you are creating in bulk.

#### POST `/management/purchasing/transactions` — create transactions with receipts

Body style A. `payload` is the array of transactions; `attachment_payload` is [shape 2](#attachment-payloads--three-different-shapes), linking receipts to transactions by array index.

```js
const res = await api.post('/management/purchasing/transactions', {
  payload: [
    { fields: { Amount: 42.5, Type: 'Fuel' } },
    { fields: { Amount: 13.0, Type: 'Tools' } },
  ],
  attachment_payload: [
    { transactionIndex: 0, receiptData: { filename: 'r0.jpg', contentType: 'image/jpeg', base64Content: b0 } },
    { transactionIndex: 1, receiptData: { filename: 'r1.jpg', contentType: 'image/jpeg', base64Content: b1 } },
  ],
});

if (res.attachment_errors) {
  // transactions exist; these receipts did not attach
}
```

**This is the only endpoint in the API with partial success.** Receipt failures do not fail the request:

| Case | Status | Response |
|---|---|---|
| Transaction create failed | `500` | `{ success: false, message: 'Failed to Upload new Transaction Data!', error }` |
| Created but nothing came back | `500` | `{ success: false, message: 'New Transactions not found!', error, data }` |
| Some receipts failed | `200` | `{ success: true, message: 'Transactions created but some receipts failed to upload', attachment_errors: [...] }` |
| OK | `200` | `{ success: true, message: 'New Transactions Created!' }` |

`attachment_errors` is a **top-level array of strings**, not inside `data`. A client that only checks `success` will silently drop receipt failures — check for the key explicitly.

Attachments are uploaded **one at a time in a loop**, each its own upstream request. Ten receipts means ten sequential uploads on top of the create; size the client timeout accordingly.

---

### REIMBURSEMENTS

#### POST `/reimbursements` — submit a reimbursement request

Body style A, **single-record `payload`** (the handler reads `result[0].id`), plus `attachment_payload` in [shape 1](#attachment-payloads--three-different-shapes).

```js
await api.post('/reimbursements', {
  payload: { fields: { /* ... */ } },
  attachment_payload: {
    fields: {
      Receipt: [
        { filename: 'r.jpg', contentType: 'image/jpeg', base64Content: b64 },
      ],
    },
  },
});
```

| Case | Status | Response |
|---|---|---|
| Create failed | `500` | `{ success: false, message: 'Failed to upload initial data!', error }` |
| Created but no id | `500` | `{ success: false, message: 'reimbursement record not found!', error, data }` |
| Attachment failed | `500` | `{ success: false, message: 'Failed to Upload Reimbursement Receipts!', error }` |
| OK | `200` | `{ success: true, message: 'reimbursement request submitted!' }` — no `data` |

**No duplicate check and no rollback.** Unlike the purchasing account create, nothing guards against submitting the same request twice, and an attachment failure still leaves the record created. Retrying a failed submission creates a second record.

#### PATCH `/reimbursements/{id}` — update status and/or receipt

The only route with a URL path parameter: `/reimbursements/(?P<id>[a-zA-Z0-9]+)`. Body style **B**, and the attachment uses [shape 3](#attachment-payloads--three-different-shapes) with the key `base64`, not `base64Content`.

Both parts are optional and independent — send `payload.fields` to change fields, `attachment_payload` to add a receipt, or both.

```
PATCH {{base_url}}/reimbursements/recXXXXXXXXXXXXXX
```

```js
// status only
await api.patch('/reimbursements/recXXXXXXXXXXXXXX', {
  payload: { fields: { Status: 'Approved' } },
});

// receipt only - note `base64`, not `base64Content`
await api.patch('/reimbursements/recXXXXXXXXXXXXXX', {
  attachment_payload: {
    fieldName: 'Receipt',
    file: { filename: 'r.jpg', contentType: 'image/jpeg', base64: b64 },
  },
});
```

| Case | Status | Response |
|---|---|---|
| Empty `id` in the path | `400` | `{ success: false, message: 'Missing record ID' }` |
| Body is not valid JSON | `400` | `{ success: false, message: 'Invalid JSON body' }` |
| Field update failed | `500` | `{ success: false, message: 'Failed to update reimbursement status', error }` |
| Receipt upload failed | `500` | `{ success: false, message: 'Failed to upload reimbursement receipt', error }` |
| OK | `200` | `{ success: true, message: 'Reimbursement updated successfully' }` — no `data` |

**Sending neither `payload.fields` nor `attachment_payload` returns `200 success: true` having done nothing.** A valid-but-empty JSON body such as `{"foo":1}` is a silent no-op success. Do not treat a `200` here as proof a change landed — re-fetch `GET /reimbursements` to confirm.

The `id` pattern is `[a-zA-Z0-9]+`, so an id with any other character produces `404 rest_no_route` rather than a handled error.

#### GET `/reimbursements` — all reimbursement records

```
GET {{base_url}}/reimbursements
```

```js
const records = (await api.get('/reimbursements')).data;
```

Returns the whole table with no filter parameter — there is no way to request only one user's reimbursements from the server. Filter client-side.

---

## Known inconsistencies

These are observations about the current implementation, recorded so client authors are not surprised. None are client-fixable.

**Contract-level, worth planning around:**

1. **Three request-body conventions across 43 routes** — see [Request body conventions](#request-body-conventions). Deletes are split between `recordIds` (reports, scope progress) and `payload` (projects, assigned scopes), and between `DELETE` and `POST`.
2. **Three attachment shapes**, one of which uses `base64` where the others use `base64Content`.
3. **Style A endpoints do not validate input** — a missing `payload` yields `500`, not `400`. Style B and C do validate.
4. **`filter` is `json_decode`d**, so an unencoded formula silently returns the entire table with a `200`.
5. **No `limit` on any list endpoint**, and no caching — every `GET` walks the full table.
6. **Upstream failures are `500`, never `502`**, and `error` carries raw upstream text.
7. **`PATCH /reimbursements/{id}` can succeed having done nothing** when neither optional part is sent.
8. **No rollback across multi-step writes.** `POST /management/purchasing/accounts` and `POST /reimbursements` create the record, then attach the file; an attachment failure returns `500` with the record already created.
9. **Duplicate checks are full table scans and are not atomic** (purchasing accounts, envelopes), so concurrent creates can both pass.

**Cosmetic, but they mislead when debugging:**

10. Copy-pasted messages name the wrong entity: `/tm/userwarnings` says `Failed to fetch earncodes!`, `/pm/project-assigned-scopes` says `Failed to fetch project allowed activities!`, `/pm/modifyProject` says `... user report ...`, `DELETE /pm/userReportScopeProgress` says `User reports deleted successfully`, and `GET /management/purchasing/envelopes` says `Failed to fetch transactions data!`. In every case the route is doing the right work under the wrong label.
11. `POST /pm/updateProjectActionHistory` creates records rather than updating them.
12. Path naming is mixed: `project-assigned-scopes` (hyphen) against `projectAllowedActivities` and `userReportScopeProgress` (camelCase); `/pm/createProject` (verb in path) against `POST /pm/reports` (verb in method).
13. `GET /management/purchasing/envelopes` and `POST /management/purchasing/envelopes` reference two different global variables that hold the same table id.
14. `createTransactions()` contains a live `error_log('UPLOAD RESULT: ...')` marked `// DELETE LATER`, which writes the full upload result to the PHP error log on every call.
