# Connect the existing frontend to PHP

Use the existing `nsbm-sportshub-frontend/` application. Its `api.js` selects either the mock adapter or live HTTP adapter, and `endpoints.js` centralizes the 43 routes. Live mode calls PHP/MySQL; it never falls back to local demonstration records after a network/server error.

## Start one local origin

First complete [database setup](DATABASE_SETUP.md), including private `backend/.env`, migration and optional explicit development seed. From the repository root run:

```bash
FRONTEND_API_MODE=live php -S localhost:8081 -t backend/public backend/router.php
```

Open `http://localhost:8081/`. Keep `APP_URL=http://localhost:8081` in the private backend configuration so origin validation agrees with the browser URL. Use that hostname consistently rather than switching between `localhost` and `127.0.0.1` during a session. The PHP development server is local tooling; use a production PHP web server for hosting.

The development router maps `/` and allowed static `/js/` and `/css/` paths to the existing frontend, and routes `/api/v1/*` through `backend/public/index.php`. It does not expose `.env`, SQL files, tests, source PHP or private logs. `-t backend/public` keeps the backend document root narrow; the explicit router supplies the frontend mapping.

`FRONTEND_API_MODE=live` changes only the configuration response served by this router. The tracked `js/config.js` stays at its default `API_MODE: 'mock'`. This lets you exercise the actual existing UI in live mode without rewriting its views or deleting the separate simulator. Inspect the served `/js/config.js` and browser Network panel if you are unsure which mode is active.

A Python static server can run the frontend's standalone mock mode, but cannot execute this PHP API. A second origin/port creates extra cookie and CORS requirements; one local PHP server is the intended integration path.

## Production configuration

For static production hosting, explicitly set these existing frontend configuration values in the deployment copy:

```js
API_MODE: 'live',
API_BASE_URL: '/api/v1',
```

Do not duplicate `/api/v1` in `endpoints.js` or change individual view calls. The production web server must serve those frontend files and PHP on the same HTTPS origin; [DEPLOYMENT.md](DEPLOYMENT.md) shows the document-root and alias arrangement. `FRONTEND_API_MODE` is a feature of the development router, not automatic Apache/Nginx configuration injection.

Serve native JavaScript modules with a correct JavaScript MIME type and preserve their relative paths. Avoid stale caching for the deployment's configuration file. Hash routes such as `/#/clubs` remain browser-side; PHP receives `/`, not the fragment.

## Understand the client flow

The application starts with `GET /api/v1/auth/session`. The response contains the current sanitized user or `null`, plus a CSRF token; PHP sends a session cookie. The HTTP client uses `credentials: 'include'` and keeps the token in memory. It adds `X-CSRF-Token` to every write, including login and logout, and accepts rotated tokens returned by successful authentication changes.

A failed login returns 401 and clears the HTTP client's in-memory token. Before every user-initiated login submission, the existing login form now awaits `api.session()` and then sends one login POST. This verified integration fix lets a corrected password succeed after a wrong-password attempt in the same form. It does not automatically replay failed POSTs or alter the wire contract.

For a student applying to a club, the existing view calls the centralized API method. The HTTP layer serializes this body:

```json
{"clubId":1}
```

PHP reads JSON from `php://input` through `Request::body()`, validates only the allowed field, obtains the user from the server session and stores the application. A successful newly created response has this shape:

```json
{
  "success":true,
  "data":{
    "id":41,"userId":2,"userName":"Synthetic Student",
    "clubId":1,"clubName":"Cricket Club","status":"pending",
    "createdAt":"2026-11-06T09:00:00.000Z"
  },
  "message":"Membership request sent"
}
```

The example timestamp/IDs illustrate the contract; they are not a fixed live record. A rejected-membership resubmission returns the existing relationship with 200 instead of creating a duplicate. Lists include a sibling `pagination` object. DELETE returns 204 with no JSON body. Dates travel with a timezone and are returned in UTC; display formatting stays in the frontend.

Validation uses HTTP 422 with `errors` keyed by the same field names as forms. Missing login returns 401; authorization/CSRF failure returns 403. No PHP notice, HTML error page or database exception should surround the JSON. `http.js` sanitizes server/network errors and does not treat a failure as permission to switch adapters.

## Exercise the real workflows

Use the development-only login details in [database setup](DATABASE_SETUP.md#5-seed-synthetic-development-data-explicitly), or privately provision accounts without seeding. Verify the following using the real UI and API/database evidence:

1. Sign in as student, reload to restore the session, then sign out. Sign in as administrator separately.
2. Use admin dialogs to create/update/delete an unreferenced club, schedule and tournament; verify persisted data after reload.
3. Apply to a club as a student, review the pending request as admin, and inspect the student's own membership history.
4. Register for an eligible tournament, inspect the registration/history, then enter results as admin and inspect standings.
5. Request a facility/equipment interval; review it as admin and test a student's own eligible cancellation.
6. Change the permitted profile/preferences, reload, and inspect dashboard counts and admin reports after writes.
7. Check duplicate, full-capacity, overlap, invalid-body, foreign-role and foreign-owner rejections directly against PHP, beyond the controls that the UI hides.

MySQL data and frontend mock localStorage are separate. Browser mock records are not migrated into the database. The notification setting stores a preference and does not imply an email service.

## Verify health and diagnose errors

Open `http://localhost:8081/api/v1/system/health`. A successful response includes:

```json
{"success":true,"data":{"status":"ok","mode":"live","database":"connected"},"message":"Backend healthy"}
```

The message text may change; the data/status fields are the contract. PHP actually probes MySQL. A failed connection returns 503, not a hardcoded healthy result. A rendered frontend page alone does not establish database readiness.

| Symptom | What to check |
|---|---|
| UI still displays simulator data | Served `API_MODE`, the router environment, URL and stale browser cache |
| Requests go to an unexpected host/path | Served `API_BASE_URL`; use one `/api/v1` prefix and the exact intended origin |
| Network error or invalid HTML response | PHP process/port, rewrite rules, asset/API aliases and private server logs |
| 401 after inactivity | Server expiry; sign in again using the anonymous bootstrap; do not inject a user into the store |
| 403 on a write | Session cookie, current token, exact Origin and role/ownership; rebootstrap and explicitly resubmit if stale |
| 422 | Form field names/types/date ranges; use the returned field-level errors |
| 409 | Current workflow state, duplicate, deadline, capacity, dependent history or active interval overlap |
| Health 503 | MySQL service, genuine engine/version, private DB configuration and account grants |

The client does not automatically retry writes after CSRF/session failure, which avoids replaying a transaction unexpectedly. Do not disable security controls to clear an error.

## If separate origins become necessary

This implementation's supported default is same origin, with no permissive CORS. A future separate frontend origin requires an explicit reviewed deployment change: exact allowlisted Origin, credentials enabled, allowed methods/headers and OPTIONS preflights, `Vary: Origin`, and browser-compatible secure cookie settings. Wildcard origins cannot safely supply cookie credentials. Test actual hosting origins and browser cookie restrictions; changing only `API_BASE_URL` is insufficient.

The completed real browser run passed 23 checks across login and all nine protected views, observing 210 actual API responses without HTTP 5xx or uncaught JavaScript errors. See [TEST_RESULTS.md](TEST_RESULTS.md) for the command, environment and limits. Mock tests, configuration text and an unexercised hosting guide do not prove a deployed PHP/MySQL application.
