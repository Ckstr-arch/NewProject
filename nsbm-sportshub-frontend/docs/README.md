# NSBM SportsHub frontend

A responsive university sports management frontend built with HTML, CSS and vanilla JavaScript ES modules. Students browse clubs, apply for memberships, register for tournaments, request resources and track participation. Administrators manage clubs, schedules, tournaments, fixtures, results and approval queues, and read reports.

This implementation lives strictly in `Ckstr-arch/NewProject`, under `nsbm-sportshub-frontend/`. It does not require or modify the separately selected backup repository. No PHP backend, MySQL implementation, framework, npm package or JavaScript build step is included.

## Run locally

From the repository root:

```bash
cd nsbm-sportshub-frontend
python3 -m http.server 8080
```

Open `http://localhost:8080` in a browser. Use HTTP: native module imports and requests are not reliably supported when opening `index.html` through `file://`. Python is development serving tooling, not an application runtime requirement; an ordinary static server or PHP-compatible web host can serve these files.

Default mock-mode logins:

| Role | Email | Public demo password |
|---|---|---|
| Student | `student@example.test` | `Student123!` |
| Admin | `admin@example.test` | `Admin123!` |

These accounts simulate workflows only. Mock authentication is not secure, and no password or production authentication token is persisted in localStorage. Demo records and simulated session identity are stored locally under the configured demo key. Settings offers a confirmed reset to restore seeded demo data. Reset affects this browser origin only and removes local demo edits.

## Mock and live modes

Edit only `js/config.js`:

```javascript
export const CONFIG = Object.freeze({
  API_MODE: 'live',
  API_BASE_URL: '/api/v1',
  REQUEST_TIMEOUT: 10000,
  APP_NAME: 'NSBM SportsHub',
  MOCK_STORAGE_KEY: 'nsbm-sportshub-demo-v1',
  MOCK_LATENCY: 120
});
```

Switching `API_MODE` selects the adapter; changing `API_BASE_URL` changes backend deployment location. Views do not change. Mock mode requires neither PHP nor MySQL and visibly labels demo data. Live mode requires a backend implementing [the contract](API_CONTRACT.md), uses PHP session cookies and CSRF headers, and reports errors instead of substituting demo records. A working demo does not establish live integration.

## Directory map

```text
nsbm-sportshub-frontend/
├── css/
│   └── styles.css
├── docs/
│   ├── diagrams/
│   │   ├── architecture.dot
│   │   └── architecture.svg
│   ├── ARCHITECTURE.md
│   ├── API_CONTRACT.md
│   ├── BACKEND_INTEGRATION.md
│   ├── DATABASE_GUIDE.md
│   ├── FRONTEND_GUIDE.md
│   ├── README.md
│   └── TEST_CHECKLIST.md
├── js/
│   ├── components/
│   │   ├── modal.js
│   │   ├── navbar.js
│   │   ├── table.js
│   │   └── toast.js
│   ├── services/
│   │   ├── api.js
│   │   ├── endpoints.js
│   │   ├── http.js
│   │   └── mock-api.js
│   ├── utils/
│   │   ├── format.js
│   │   └── validation.js
│   ├── views/
│   │   ├── bookings.js
│   │   ├── clubs.js
│   │   ├── dashboard.js
│   │   ├── login.js
│   │   ├── memberships.js
│   │   ├── reports.js
│   │   ├── results.js
│   │   ├── schedules.js
│   │   ├── settings.js
│   │   └── tournaments.js
│   ├── app.js
│   ├── config.js
│   ├── router.js
│   └── store.js
├── tests/
│   ├── api-tests.mjs
│   ├── browser-smoke.py
│   ├── live-browser-smoke.py
│   └── manual-test-cases.md
└── index.html
```

## Main workflows

- Search, sport filters, sorting and pagination load records through the API.
- Admin create/edit/delete dialogs wait for successful API responses before updating views.
- Students apply to clubs; admins approve/reject pending applications.
- Students register for tournaments within deadline/capacity; admins manage fixtures and scores.
- Students request facilities or equipment; resource conflicts are rejected; admins review bookings.
- Role-aware dashboard, student histories and admin reports use adapter-provided records.
- Settings updates permitted profile fields, theme and notification preferences, checks backend health and supports logout.
- Hash routes support browser back/forward, session restoration and admin route guards.
- Accessible labels, keyboard dialogs, focus states and responsive tables support mobile and desktop.

## Read next

- [Architecture diagrams](ARCHITECTURE.md): system overview, module map, sessions/CSRF, request flow and storage boundaries.
- [API contract](API_CONTRACT.md): endpoint roles, schemas, JSON, errors and CSRF.
- [Backend integration](BACKEND_INTEGRATION.md): PHP session setup and deployment checklist.
- [Database guide](DATABASE_GUIDE.md): proposed normalized MySQL design.
- [Frontend guide](FRONTEND_GUIDE.md): module responsibilities and change examples.
- [Test checklist](TEST_CHECKLIST.md) and [manual cases](../tests/manual-test-cases.md): verification scope and procedures.

The runtime does not send email/push notifications, implement registration/password recovery, or host a production backend. Those capabilities need separately agreed backend endpoints.

## Verification

The implementation passed 93 API/HTTP checks, 42 mock-mode browser acceptance checkpoints and 13 synthetic live-mode browser checks. All application views were tested at 320–1440px in both themes. See [the test record](TEST_CHECKLIST.md) for commands and precise limits. No real PHP/MySQL integration is claimed.
