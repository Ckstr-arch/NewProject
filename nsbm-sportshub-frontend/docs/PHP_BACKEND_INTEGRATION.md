# PHP/MySQL live integration

The frontend defaults to **live** mode and `http://localhost:8000/api/v1` in
[`js/config.js`](../js/config.js). The required backend has no Node, npm,
Composer, or Docker dependency. See [`backend/README.md`](../../backend/README.md)
for database imports, PHP/Apache configuration, the development credentials,
permissions, and deployment instructions.

After configuring and seeding the backend, serve this folder at a configured
CORS origin such as `http://localhost:5500`. Opening `index.html` with a `file:`
URL is unsupported. You can use Apache/XAMPP, a static web server, or an editor's
static server. For XAMPP under `htdocs/nsbm-sportshub`, set `API_BASE_URL` to
`http://localhost/nsbm-sportshub/backend/api/v1` and configure that frontend
origin in the backend CORS settings.

## Live contract

The service calls `/auth/login`, `/auth/session`, `/auth/logout`,
`/dashboard/metrics`, `/clubs`, `/schedules`, `/tournaments`, `/bookings`, and
`/system/health`. Each resource supports list, get by ID, create, update, and
delete through the paths in `js/services/live-endpoints.js`.

Payloads use the specification's **snake_case** field names. Collections return
`{ "data": { "items": [], "page": 1, "limit": 10, "total": 0, "pages": 0 } }`.
Resource responses use `{ "data": {} }`, and errors use
`{ "error": { "code": "...", "message": "...", "details": {} } }`.
The form helper renders validation details beside matching fields. No live
request falls back to simulated records.

Login returns `access_token`, `token_type`, `user`, and `permissions`. The JWT
is kept in tab-scoped `sessionStorage` and sent as `Authorization: Bearer TOKEN`.
Reload restores the account from `/auth/session`. A 401 clears the local token
and sign-in state. Logout discards the local JWT; the stateless server does not
revoke already issued copies. Treat every injected script as a token-access
risk: render untrusted text safely and deploy over HTTPS.

The frontend hides controls using the permissions returned by the server.
Student users can create pending bookings for themselves and cancel their own
pending/confirmed bookings. Sports administrators can create, edit, and delete
all four resources, including bookings for another user ID. Club managers can
edit clubs assigned to their `manager_id`; the backend remains the authorization
boundary. The six controlled facilities match the backend specification.

The dashboard uses `upcoming_events_count` for the numeric metric and
`upcoming_events` for the event list, avoiding the specification example's
duplicate JSON key.

## Standalone demo boundary

Setting `API_MODE: 'mock'` explicitly enables the original standalone demo,
which uses its original demo accounts and simulated models. Memberships,
results/standings, reports, and editable profile settings belong to that demo;
they are outside this backend specification. Their navigation and direct routes
are unavailable in live mode. There are no live buttons that invoke these
unsupported endpoints. The theme toggle remains available in both modes.

The separately labeled `DEMO_API_CONTRACT.md` describes this explicit demo.
`API_CONTRACT.md`, `backend/README.md`, and this document define the current live integration.

## Browser acceptance

For development QA, Python Playwright and Chromium can run the complete live
workflow (these are **test tools**, not application dependencies):

```sh
python3 tests/php-backend-browser.py --base-url http://localhost:5500
```

The test needs the documented development student/admin accounts and creates
uniquely named records. It checks login, reload, club search, booking persistence,
overlap rejection, cancellation, admin club/schedule/tournament/booking CRUD forms, metrics, required views,
mobile layout, logout, and an unauthenticated protected request. It removes its
club and booking after success. Run only against a development database.
