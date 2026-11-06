# NSBM SportsHub PHP/MySQL backend

Plain PHP with PDO, native sessions and MySQL, integrated with the existing vanilla JavaScript frontend. Start with [the backend guide](docs/README.md), then follow [database setup](docs/DATABASE_SETUP.md) and [frontend connection](docs/FRONTEND_CONNECTION.md).

From the repository root, after creating a private `backend/.env` and a fresh database:

```bash
php backend/database/migrate.php
php backend/database/seed.php --confirm-development
FRONTEND_API_MODE=live php -S localhost:8081 -t backend/public backend/router.php
```

The seed is optional, requires exact development mode, and creates synthetic accounts with public development passwords. Never run it on a production database. The PHP development server is local tooling, not a production web server.

Open `http://localhost:8081` locally. API requests use `/api/v1`; for example, `/api/v1/system/health` performs a real database probe. The runtime live-mode setting changes the served frontend configuration without editing the tracked frontend views or config.

Validated locally with PHP 8.4.27 and genuine MySQL 8.4.13: 385 HTTP/database checks across all 43 API methods, 12 competing-connection checks and 23 real frontend browser checks. See [the test record](docs/TEST_RESULTS.md) for commands, frontend regression results and unrun hosting checks.

## Complete backend source tree

```text
backend/
├── .env.example
├── .gitignore
├── README.md
├── router.php
├── public/
│   ├── .htaccess
│   └── index.php
├── src/
│   ├── bootstrap.php
│   ├── routes.php
│   ├── Config/
│   │   └── Database.php
│   ├── Controllers/
│   │   ├── AuthController.php
│   │   ├── BookingController.php
│   │   ├── ClubController.php
│   │   ├── DashboardController.php
│   │   ├── HealthController.php
│   │   ├── MatchController.php
│   │   ├── MembershipController.php
│   │   ├── ReportController.php
│   │   ├── ScheduleController.php
│   │   ├── TournamentController.php
│   │   └── UserController.php
│   ├── Core/
│   │   ├── ApiException.php
│   │   ├── Auth.php
│   │   ├── Csrf.php
│   │   ├── ListQuery.php
│   │   ├── Request.php
│   │   ├── Response.php
│   │   ├── Router.php
│   │   └── Validator.php
│   ├── Models/
│   │   └── Records.php
│   └── Services/
│       ├── BookingService.php
│       ├── MembershipService.php
│       ├── StandingsService.php
│       └── TournamentService.php
├── database/
│   ├── create-user.php
│   ├── migrate.php
│   ├── reset-development.md
│   ├── schema.sql
│   └── seed.php
├── docs/
│   ├── README.md
│   ├── API_IMPLEMENTATION.md
│   ├── AUTHENTICATION.md
│   ├── DATABASE_SETUP.md
│   ├── DEPLOYMENT.md
│   ├── FRONTEND_CONNECTION.md
│   └── TEST_RESULTS.md
├── tests/
│   ├── README.md
│   ├── bootstrap.php
│   ├── run.php
│   └── integration/
│       ├── concurrency.php
│       └── frontend-live.py
└── var/
    └── .gitkeep
```

Private `.env`, generated `var/sessions/`, logs and task-local `var/local/` helpers are ignored runtime files. The tree above lists the backend source; these local files are created as needed and stay outside the public document root. The application runs with native PHP/MySQL without the task-local container helper.

## Shared repository changes

The root README links both application parts. The existing frontend login form awaits `api.session()` before each user-initiated login attempt to recover CSRF state after a failed login; its regression is covered in the synthetic and real browser suites. Frontend guide/architecture/status documentation now links the implemented backend. The API contract's 43 method/path definitions and schemas are preserved; the tracked default configuration remains mock mode.

No public deployment is implied by this local validation.
