# Test record and reproducible validation

This file distinguishes executed evidence from implementation scope and hosting instructions. The automated checks below target the actual PHP API and genuine MySQL, while the existing frontend mock suites target a separate simulator. Source inspection, a config value and a static page are not a deployed backend result.

## Current execution record

Validated on **6 November 2026 (Asia/Colombo)** in the Linux cloud environment using Docker-hosted **PHP 8.4.27** and genuine **MySQL 8.4.13**. Chromium and optional Python/Node test tooling ran the browser/frontend checks. These are local implementation results, not public hosting results.

| Validation | Actual result |
|---|---|
| PHP source syntax | All **35 PHP files** passed lint, including routing, CLI and tests |
| Fresh schema and repeatability | Passed in the disposable schema: all 13 tables, constraints, migration rerun and explicit seed rerun; rows/passwords preserved |
| Real HTTP API/database suite | **385 checks passed**, exercising all **43 existing API methods** |
| Actual competing database connections | **12 checks passed**, with independent PHP/PDO workers and observed MySQL row-lock waits |
| Existing frontend Node suite | **93 checks passed**; simulated storage/HTTP, separate from PHP/MySQL evidence |
| Existing frontend mock browser suite | **42 checks passed**; standalone simulator regression coverage |
| Existing frontend synthetic live-adapter browser suite | **14 checks passed**, including failed-login recovery; intercepted API, separate from real backend evidence |
| Existing frontend against real PHP/MySQL | **23 browser checks passed**, observing **210 actual API responses**, login plus all nine protected views, no HTTP 5xx and no uncaught JavaScript errors |
| Real database-unavailable behavior | Passed in HTTP suite: unavailable connection returned safe **503**, without private connection/SQL details |
| Session expiry, roles, ownership, CSRF and throttling | Passed within the 385 HTTP/database checks |
| Real health after validation | `status: "ok"`, `mode: "live"`, `database: "connected"` |
| macOS Homebrew installation | Instructions supplied; not executed in this Linux environment |
| MariaDB | Not a supported/verified substitute; runtime/migration/test guards reject it |
| Public Apache/Nginx/HTTPS deployment | Not deployed or verified during this implementation |
| Independent official assignment PDF review | Not possible: no official PDF is present in this repository |

The actual container-backed backend commands, from the repository root, were:

```bash
backend/var/local/manage exec -T -e APP_ENV=development -e DB_NAME=sportshub_backend_test app php tests/run.php --confirm-test-reset
backend/var/local/manage exec -T -e APP_ENV=development -e DB_NAME=sportshub_backend_test app php tests/integration/concurrency.php --confirm-test-reset
```

`backend/var/local/manage` is ignored task-local infrastructure, not a committed application dependency. Native PHP/MySQL reproduction is described below. The real browser command was:

```bash
python3 backend/tests/integration/frontend-live.py --browser /usr/bin/chromium
```

Frontend regression entry points, from `nsbm-sportshub-frontend/` with the standalone static server for the browser suites, were:

```bash
node tests/api-tests.mjs
python3 tests/browser-smoke.py --browser /usr/bin/chromium
python3 tests/live-browser-smoke.py --browser /usr/bin/chromium
```

These are current rerun results. The three frontend-only suites use simulated data/transport and do not establish database behavior; the separate real PHP/MySQL suites do.

## Disposable database guard

Tests deliberately rebuild tables. They require all of these conditions before any reset: CLI execution, explicit `--confirm-test-reset`, process `APP_ENV=development`, process `DB_NAME=sportshub_backend_test`, and an actual selected MySQL schema with that exact literal name. Genuine MySQL 8.0.16+ is verified; MariaDB is rejected. The test guard runs before loading application `.env`, so an ordinary development configuration alone does not authorize a reset.

Create that separate disposable schema and a database-specific test account using a private administrator client. Keep the actual test password out of source/history/logs. The account needs DDL/DML within that schema for resets, not global privileges. Never point tests at `sportshub`, another application's database, production or valued student data. A confirmation flag only authorizes the literal disposable test schema, not a reset of any other installation.

From the repository root, supply the test account through private process settings. For example, in Bash:

```bash
read -r -p 'Disposable test database user: ' DB_USER
read -r -s -p 'Disposable test database password: ' DB_PASSWORD
export DB_USER DB_PASSWORD
export APP_ENV=development DB_NAME=sportshub_backend_test
export DB_HOST=127.0.0.1 DB_PORT=3306
php backend/tests/run.php --confirm-test-reset
```

Keep this private test environment only until the selected checks finish, then unset it as shown below (or unset it immediately if you stop after the first suite). Use the configured test server port only when it is free. The test runner refuses to stop an unrelated service. Runtime output/private test logs belong under ignored `backend/var/`, not Git. After a successful API/database run has created synthetic test fixtures, run the concurrency checks with the same explicit test process environment:

```bash
php backend/tests/integration/concurrency.php --confirm-test-reset
unset DB_USER DB_PASSWORD APP_ENV DB_NAME DB_HOST DB_PORT
```

Run suites sequentially against this shared disposable schema. The concurrency suite does not rebuild it; the confirmation flag enforces the same literal-schema guard before adding synthetic fixtures. It needs test-only observation access to `performance_schema.data_locks` and `performance_schema.data_lock_waits` so it can prove the second connection is actually waiting on the held parent-row lock. Grant read access to those two observation tables only to the disposable test account, never widen the runtime application's privileges to satisfy a test. The actual real-browser command and current results are recorded above; see the workflow details below.

## What the API/database runner checks

The real database/HTTP runner covers clean migration and all required tables, InnoDB/foreign-key/unique/CHECK constraints, repeated migrate/seed preserving records/passwords, and hashed development credentials. It starts an isolated local PHP process and exercises session cookies, anonymous CSRF acquisition, login/session/token rotation, malformed inputs, role/ownership restrictions, profile allowlists and rate limits.

Resource cases include full CRUD where the contract permits it; strict types/unknown fields; dates and joins; pagination/search/sort/filter allowlists; duplicate/rejected/resubmitted membership states; full capacities; tournament deadlines/registration projection; score/standings changes; protected historical dependencies; booking intervals/statuses/ownership; reports/dashboard; logout/expiry and actual database-backed health. The completed run recorded above verifies the endpoint inventory against the existing frontend source.

Concurrency verification must use independent database connections/processes with fresh decisions after the shared parent-row lock, rather than sequentially calling a service twice. Verify last-slot membership approval, last-slot tournament registration and identical active booking intervals, plus valid adjacent intervals. Record actual contention and postconditions so a test cannot appear to pass merely because the second operation ran long after the first.

## Frontend and browser evidence

The real runner, `tests/integration/frontend-live.py`, used the existing frontend served by `backend/router.php` with `FRONTEND_API_MODE=live` and a dedicated seeded development database. Its browser requests used the actual live adapter and PHP/MySQL: no API route interception or synthetic HTTP responses. It visited login and all nine protected views, including both student/admin roles, and exercised persisted CRUD, membership/booking review, registration, results/standings, profile/preferences, reload restoration, logout and health.

The runner checked the live dashboard at 375px and 1440px widths and wrote local screenshots under `/tmp/sportshub-backend-qa`. This does not claim an exhaustive device/accessibility audit or hosted-browser compatibility matrix. It retained uniquely tagged synthetic records whose history cannot be deleted through the agreed API, and restored the edited seed profile/preferences. Run it only against a dedicated development dataset; it writes real records and is not a production smoke test.

Real integration exposed a login recovery defect: a wrong password returned 401, clearing the HTTP client's in-memory CSRF token, so another attempt in the same form lacked a token. The shared login form now calls `await api.session()` before each user-initiated login submission. A wrong-then-correct login passed against the actual backend and in the synthetic live-adapter regression suite. The change acquires current CSRF state; it does not automatically replay a failed POST or change the API contract.

The original frontend's Node/mock/synthetic-live suites remain separate optional testing tools. Their passing counts are reported above alongside their transport limitations. Backend HTTP outage tests establish actual database failure behavior; synthetic live-adapter checks independently establish how the frontend displays simulated service/network failures.

## Limits of the result

Do not describe the backend as publicly deployed without a real hosting URL and host-specific verification. The Homebrew/Apache/Nginx guides remain instructions until exercised on those systems. No official university assignment PDF is available here, so the available frontend contract/project documentation are the reference, with explicit contract resolutions in [README.md](README.md#scope-and-contract-resolutions).
