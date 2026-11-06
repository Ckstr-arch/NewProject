# NSBM SportsHub

University sports club management with an existing HTML/CSS/vanilla JavaScript frontend and a plain PHP/PDO/MySQL backend. Students browse clubs, apply for membership, register for tournaments, request facilities/equipment and track participation; administrators manage sports operations, approvals, schedules, results and reports.

- **[Run the complete application](backend/docs/DATABASE_SETUP.md)** — install PHP/MySQL, configure a fresh database, migrate and start the same-origin local server.
- **[Backend guide](backend/docs/README.md)** — implementation, authentication, integration, deployment and test evidence.
- **[Frontend guide](nsbm-sportshub-frontend/docs/README.md)** — existing native-module frontend and standalone mock mode.
- **[Agreed API contract](nsbm-sportshub-frontend/docs/API_CONTRACT.md)** — all 43 method/path definitions and JSON schemas.
- **[Test results and limits](backend/docs/TEST_RESULTS.md)** — actual validation, distinct from a live public deployment.

## Local application

After following the database setup guide, run these commands from this repository root:

```bash
php backend/database/migrate.php
php backend/database/seed.php --confirm-development
FRONTEND_API_MODE=live php -S localhost:8081 -t backend/public backend/router.php
```

Open `http://localhost:8081` locally. Both frontend assets and `/api/v1/*` requests use the same origin. `FRONTEND_API_MODE=live` changes the configuration response served by the development router, preserving the frontend's tracked default mock configuration. Do not use the Python static server for PHP API requests.

The explicit development seed is optional and must never be run against production. Production uses a PHP-compatible web server, HTTPS, private configuration and separate database privileges; see [deployment](backend/docs/DEPLOYMENT.md).

## Implementation and shared changes

The backend adds the executable MySQL schema, explicit migration/development seed, private account provisioner, PHP API/services, native-session security and isolated validation under `backend/`. See [the complete backend source tree](backend/README.md#complete-backend-source-tree).

The essential shared frontend fix is in [the existing login form](nsbm-sportshub-frontend/js/views/login.js): every user-initiated attempt first awaits `api.session()`, allowing a corrected password after a 401 without replaying a failed POST automatically. Frontend regression tests and guide/architecture status links were updated. The 43-method wire contract and default mock configuration are preserved.

Local validation passed **385 PHP/MySQL checks**, **12 genuine contention checks** and **23 real frontend browser checks** on PHP 8.4.27/MySQL 8.4.13; existing frontend suites passed 93 Node, 42 mock-browser and 14 synthetic-live checks. [Test results](backend/docs/TEST_RESULTS.md) record commands and scope.

## Repository layout

```text
NewProject/
├── nsbm-sportshub-frontend/   # Existing frontend, mock/live adapters and contract
├── backend/
│   ├── public/              # Backend public entry point only
│   ├── src/                 # PHP core, controllers, SQL mapper and services
│   ├── database/            # Schema, migration runner and development seed
│   ├── tests/               # Isolated database/API/concurrency/integration checks
│   ├── docs/                # Backend learning and operating guides
│   ├── .env.example         # Non-secret configuration template
│   └── router.php           # Same-origin local development routing
└── README.md
```

Backend work is based on the reviewed frontend commit `2c11d37`, on a separate `feat/sportshub-backend` branch. The official university assignment PDF is not available in this repository, so independent verification against that PDF is not claimed. No public deployment is implied by these source files.
