# Current integration and acceptance record

The current live backend is plain PHP/PDO/MySQL with JWT bearer authentication.
It is tested separately from the optional browser-local demo. No Node/npm,
Composer, or Docker installation is required to run the application.

## Executed on 6 November 2026

| Check | Result | Scope |
|---|---|---|
| `python3 tests/php-backend-browser.py --base-url http://localhost:5500` | 17 checkpoints passed | Real frontend → PHP → MySQL: student login/reload, club search, booking persistence/reload/conflict/cancellation, admin club CRUD/metrics, schedule/tournament/admin-booking CRUD forms, required views, mobile layout, local JWT removal and unauthenticated401 |
| `python3 tests/live-browser-smoke.py --base-url http://localhost:5500` | 5 checks passed | Synthetic transport: token storage, bearer/no-cookie policy, query encoding, nested422 details, logout token removal. No database-connectivity claim |
| Explicit mock-mode smoke | 4 checks passed | In isolated Chromium with config intercepted to mock: visible demo mode, original admin login, original clubs, demo navigation. Live default unchanged |

Backend HTTP/security/concurrency tests and Apache hosting checks are recorded in
[`backend/README.md`](../../backend/README.md) and backend test documentation.

Browser testing uses Python Playwright and Chromium as optional QA tools only.
The real browser test requires a disposable, seeded development database and the
configured frontend CORS origin. It creates uniquely named temporary resources
and removes them on successful completion. An interrupted run may require
removing only its `UI QA <unique suffix>` records from that development database.

The 17-checkpoint run includes schedule, tournament, and administrator booking
create/edit/delete through their visible forms. All records created by that run
were removed, and no uncaught JavaScript errors occurred.

## Repeat the live acceptance workflow

- Start/import MySQL, configure PHP and JWT secret, and start the API.
- Serve the frontend at an allowed HTTP origin; confirm live mode is selected.
- Sign in as the development student; reload and confirm session restoration.
- Read/search clubs, then create a pending facility booking.
- Reload to confirm persistence; create an overlap and verify409feedback.
- Cancel the owned booking and confirm its cancelled status persists.
- Sign in as the sports administrator and create/edit/delete a club.
- Confirm dashboard metrics change after writes.
- Exercise schedule, tournament, and administrative booking forms.
- Check mobile navigation/layout and safely rendered server text.
- Log out, confirm local JWT removal, and verify unauthenticated protected401.

Deployment-specific HTTPS, actual hosting credentials/CORS origins, other browser
engines, and human assistive-technology review remain deployment/manual checks.
A browser-local simulator or synthetic fetch response cannot certify these.

## Standalone demo tooling

`tests/browser-smoke.py` is the original demo acceptance suite. It intercepts
config in an isolated browser context to explicitly select mock mode, without
modifying the live checkout configuration. `tests/manual-test-cases.md` is scoped
to that demo. The superseded Node/cookie/CSRF test file was removed; current
transport QA uses the Python browser test above.
