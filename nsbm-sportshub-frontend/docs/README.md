# NSBM SportsHub frontend

Responsive HTML/CSS/native ES6 frontend for the plain PHP/MySQL backend in
[`backend/`](../../backend/README.md). There is no frontend build step or Node/npm
runtime dependency. Work remains strictly in `Ckstr-arch/NewProject`.

## Run the updated application

1. Follow [`backend/README.md`](../../backend/README.md) to create/import the MySQL
   database, configure PHP, and start the API at `http://localhost:8000/api/v1`.
2. Serve `nsbm-sportshub-frontend/` over HTTP at an origin allowed by backend CORS,
   for example `http://localhost:5500`. Do not open `index.html` through `file:`.
3. Sign in using a development account from the backend README.

`js/config.js` defaults to:

```javascript
API_MODE: 'live',
API_BASE_URL: 'http://localhost:8000/api/v1'
```

Change the API URL for your host or XAMPP directory. See
[PHP backend integration](PHP_BACKEND_INTEGRATION.md) for exact contracts,
Apache/XAMPP URLs, token handling, and the optional live browser test.

The live application provides dashboard metrics, clubs, schedules, tournaments,
and facility bookings. Students, club managers, sports administrators, and super
administrators receive controls based on permissions returned by the server.
All persisted records and authorization decisions belong to PHP/MySQL.

## Explicit standalone demo

Set `API_MODE: 'mock'` to explore the original local simulator. Only this mode
provides memberships, tournament registrations, results/standings, reports, and
editable profiles. These modules are outside the current backend specification
and are hidden and blocked in live mode.

| Demo role | Email | Public simulator password |
|---|---|---|
| Student | `student@example.test` | `Student123!` |
| Admin | `admin@example.test` | `Admin123!` |

These are simulator accounts, distinct from the PHP development accounts. Demo
records and simulated identity remain in local browser storage. Demo mode is
visibly labeled and does not prove backend security or connectivity.

## Files and verification

- `js/services/api.js` selects the adapter and validates resource IDs.
- `js/services/live-endpoints.js` defines the current PHP routes.
- `js/services/http.js` sends bearer tokens and handles JSON/error envelopes.
- `js/views/live/` renders the current dashboard and four resource families.
- `js/router.js`, `store.js`, and `components/` manage navigation and shared UI.
- [Frontend guide](FRONTEND_GUIDE.md) explains the module flow.
- `tests/php-backend-browser.py` exercises the live workflow against seeded PHP/MySQL.
- Older mock tests and demo documents are labeled as historical simulator scope.

No registration, password recovery, coach role, user management, or image upload
workflow is part of the current backend specification.
