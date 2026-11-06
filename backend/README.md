# NSBM SportsHub PHP/MySQL API

This backend implements authentication, JWT Bearer authorization, role permissions, MySQL/PDO persistence, clubs/schedules/tournaments/bookings CRUD, transactional booking conflict detection, database-derived dashboard metrics, health checks, validation, pagination, search, sorting, CORS, and development seed data. It uses plain PHP without a framework or Composer dependency.

Verified with real MySQL on PHP 8.4 and Apache/PHP 8.2, including the complete browser workflow. See [the verification record](docs/TEST_RESULTS.md) for results and scope.

```text
Vanilla JS frontend → Fetch/JSON → PHP REST API → PDO → MySQL
```

## Requirements

- PHP 8.2 or later, with PDO and `pdo_mysql`; JSON, hash, and OpenSSL support should be enabled in a standard PHP installation.
- MySQL 8.0.16 or later, so database `CHECK` constraints are enforced.
- Apache with `mod_rewrite` and permitted `.htaccess` overrides for hosting, or PHP's built-in server for local development.

Node.js, npm, Composer, and Docker are not required. A hosting control panel and phpMyAdmin are sufficient to configure and import the database without a command line.

## Database setup

Use a new database for this schema. It is not a migration for an earlier SportsHub backend.

1. Create a database named `nsbm_sportshub`, using `utf8mb4` and `utf8mb4_unicode_ci`.
2. Select that database in phpMyAdmin and import [`database/schema.sql`](database/schema.sql).
3. For development, import [`database/seed.sql`](database/seed.sql).
4. Create a database user with access only to this application's database and configure it below.

Equivalent MySQL client commands, run from `backend/`:

```sh
mysql -u root -p -e "CREATE DATABASE nsbm_sportshub CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -p nsbm_sportshub < database/schema.sql
mysql -u root -p nsbm_sportshub < database/seed.sql
```

The schema defines `users`, `clubs`, `schedules`, `tournaments`, and `bookings`, plus the six controlled `facilities` and a small `login_attempts` table. Tables use InnoDB, foreign keys, indexes, and timestamps. `clubs.manager_id` links a managed club to its user; `bookings.booked_by` references `users.id`.

The seed supplies four development roles, 15 sports clubs, 12 schedules, five university tournaments, and six facility reservations. Initial event dates are relative to import time; re-importing keeps the original dates and any existing edits. Password hashes were generated with PHP 8.4.27 using `password_hash(..., PASSWORD_DEFAULT)`; see the seed file's provenance comments. Re-importing development seeds does not create duplicate seed rows or reset existing passwords. Do not import development users on a production database.

The optional `php database/seed-passwords.php` helper generates fresh PHP password hashes for the development seed. Its `--write` option updates hashes in the SQL seed file; it does not change passwords already stored in an imported database.

## Configuration

Copy [`.env.example`](.env.example) to `.env` and edit it locally:

```dotenv
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=nsbm_sportshub
DB_USER=sportshub
DB_PASSWORD=your-local-database-password
APP_ENV=development
APP_URL=http://localhost:8000
JWT_SECRET=replace-with-a-random-secret-of-at-least-32-bytes
JWT_TTL=3600
CORS_ORIGINS=http://localhost:5500,http://127.0.0.1:5500
```

Generate a random JWT secret using your hosting panel's secure password generator, or locally:

```sh
php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
```

Paste the output into your private configuration. Do not use the example value. Keep `.env`, database passwords, and JWT secrets out of Git and frontend JavaScript.

Configuration precedence is process environment variables, then `config/config.local.php`, then `.env`, then defaults. On hosting without environment variables, either use `.env` or create the ignored `config/config.local.php` returning an array:

```php
<?php
declare(strict_types=1);
return [
    'DB_HOST' => 'localhost',
    'DB_PORT' => 3306,
    'DB_NAME' => 'your_hosting_database_name',
    'DB_USER' => 'your_hosting_database_user',
    'DB_PASSWORD' => 'your_private_database_password',
    'APP_ENV' => 'production',
    'APP_URL' => 'https://your-domain.example/backend',
    'JWT_SECRET' => 'replace-with-a-generated-random-secret',
    'JWT_TTL' => 3600,
    'CORS_ORIGINS' => 'https://your-frontend.example',
];
```

`APP_URL` is the externally visible backend root, without `/api/v1`. It determines the URL prefix and JWT issuer. Changing it or `JWT_SECRET` makes tokens issued under the previous configuration invalid. `CORS_ORIGINS` is a comma-separated list of complete origins, without URL paths. Use HTTPS and `APP_ENV=production` on hosting.

## Run locally

Start MySQL, import the schema and development seed, and configure the backend. Then:

```sh
cd backend
php -S localhost:8000 router.php
```

The API base is **`http://localhost:8000/api/v1/`**. PHP's built-in server needs `router.php`; it does not read Apache `.htaccess` files.

In another terminal:

```sh
cd nsbm-sportshub-frontend
php -S localhost:5500
```

Open `http://localhost:5500`. In `nsbm-sportshub-frontend/js/config.js`, use live mode and set `API_BASE_URL` to `http://localhost:8000/api/v1`. The live frontend stores the Bearer token on the client and sends it through the centralized HTTP service. Do not open the HTML through a `file://` URL.

Live routes cover the dashboard, clubs, schedules, tournaments, and bookings. Earlier demo-only memberships, results, reports, and profile-editing modules are not exposed as live backend features. Live requests never fall back to demo records.

## Apache, XAMPP, WAMP, and Laragon

An XAMPP example layout is:

```text
htdocs/
└── nsbm-sportshub/
    ├── backend/
    └── nsbm-sportshub-frontend/
```

1. Start Apache and MySQL. Enable `pdo_mysql` in the PHP configuration used by Apache.
2. Import the database through phpMyAdmin and configure `.env` or `config/config.local.php`.
3. Set `APP_URL=http://localhost/nsbm-sportshub/backend`.
4. Set frontend `API_BASE_URL` to `http://localhost/nsbm-sportshub/backend/api/v1`.
5. Include `http://localhost` in `CORS_ORIGINS` when serving the frontend from that origin.
6. Open `http://localhost/nsbm-sportshub/nsbm-sportshub-frontend/`.

The included `.htaccess` forwards extensionless API URLs to `index.php`, preserves Apache's Authorization header, and denies access to configuration, core code, SQL, tests, and private files. Apache must have `mod_rewrite` enabled and allow the supplied rewrite/access rules. WAMP/Laragon and ordinary Apache PHP hosting use the same arrangement with their document roots and URLs.

Keep the supplied `DirectorySlash Off` and `RewriteOptions AllowNoSlash` rules: collection URLs share names with endpoint directories and must preserve POST methods and request bodies without trailing-slash redirects. Standard source permissions are 755 for directories and 644 for files; private configuration should remain readable only by the hosting account/PHP process.

Prefer keeping configuration, core code, and SQL outside the public document root when your host allows it; adjust the entry point's include paths if relocating them. Do not deploy SQL backups or private configuration to an unprotected public directory. For servers that ignore `.htaccess`, equivalent routing and access restrictions must be configured before deployment. The PHP development router also blocks private paths.

## Development credentials

**DEVELOPMENT ONLY.** These are deliberately public example credentials from `database/seed.sql`.

| Role | Email | Password |
| --- | --- | --- |
| `student` | `student@nsbm.ac.lk` | `password123` |
| `club_manager` | `clubmanager@nsbm.ac.lk` | `password123` |
| `sports_admin` | `admin@nsbm.ac.lk` | `admin123` |
| `super_admin` | `superadmin@nsbm.ac.lk` | `admin123` |

Passwords are verified with `password_verify()`. No password hashes are returned by the API. There is no public registration or role-change endpoint in this version; role assignment is an administrative database operation, not a client-supplied login field.

## Authentication and permissions

Send `Authorization: Bearer TOKEN` on every protected request. Login issues an HS256 JWT with a one-hour default lifetime; `JWT_TTL` is bounded between 60 and 86,400 seconds. The API checks its signature, algorithm, issuer, audience, claims, and expiry, then reloads the active user's current role from MySQL. A role claimed by a request or an old token cannot override the database role. Disabled users lose access even if their token has not expired.

| Role | Allowed operations |
| --- | --- |
| Student | Read dashboard, clubs, schedules, and tournaments; read own bookings; create own pending bookings; cancel own pending/confirmed bookings. |
| Club manager | Student access, plus create clubs, update clubs they manage, and create/update schedules. No club deletion or tournament writes. |
| Sports administrator | Read dashboard and all resources; create, update, delete, and manage bookings on behalf of users. |
| Super administrator | All defined permissions. |

The login and session responses include the effective `permissions` list. The frontend uses it to present actions; the backend independently enforces it. Manager-created clubs are assigned to that manager. Only administrators can assign a different manager.

Logout is **stateless**. `POST /auth/logout` returns `logged_out: true` and `token_revoked: false`; the client must discard its stored token. A copied JWT remains usable until expiry unless the user is disabled or the signing secret changes. This endpoint does not claim to blacklist a token.

Basic login throttling tracks hashed normalized email and remote IP independently. Five failed attempts within 15 minutes trigger `429 Too Many Requests` (`RATE_LIMITED`) with `Retry-After`; see the implementation in `core/auth.php` for the small database-backed limiter. Do not trust arbitrary forwarded IP headers unless your deployment's proxy configuration is controlled.

## Response and request conventions

All API request bodies use `Content-Type: application/json`. Field names use `snake_case`.

Single-resource success:

```json
{"data":{"id":1,"name":"Basketball Club"}}
```

Collection success:

```json
{"data":{"items":[],"page":1,"limit":10,"total":0,"pages":0}}
```

Errors use an appropriate HTTP status and a structured envelope:

```json
{"error":{"code":"VALIDATION_ERROR","message":"Validation failed.","details":{"member_count":"Member count must be a non-negative integer."}}}
```

Creation returns `201`; reads and updates return `200`; successful resource deletion consistently returns `200` with `{"data":{"deleted":true}}`. CORS preflight uses `204` without a body. Dates are `YYYY-MM-DD`; times are `HH:MM` or `HH:MM:SS`, within one day, with end after start. Database timestamps accompany stored resources. Treat returned text as text, not trusted HTML.

Shared errors:

| Status | Meaning |
| --- | --- |
| `400` | Malformed JSON or malformed request. |
| `401` | Missing, malformed, expired, or invalid Bearer token; invalid login. |
| `403` | Authenticated user lacks permission, ownership, or an allowed origin. |
| `404` | Unknown route or missing resource. |
| `405` | Unsupported method; the `Allow` header lists supported methods. |
| `409` | A uniqueness, booking overlap, or resource-state conflict. |
| `413` | JSON request body exceeds 64 KiB. |
| `415` | Unsupported request body content type. |
| `422` | Invalid fields, filters, dates, times, IDs, or pagination. |
| `429` | Login rate limit reached. |
| `500` | Unexpected failure, with a generic message and no stack trace or SQL. |
| `503` | Database/service unavailable. |

Configured origins receive CORS responses for `GET`, `POST`, `PUT`, `DELETE`, and `OPTIONS`, with `Content-Type` and `Authorization` headers permitted. Authenticated production requests never use wildcard origins. `OPTIONS` does not require a token. Security headers include `X-Content-Type-Options: nosniff`, `X-Frame-Options: SAMEORIGIN`, and `Referrer-Policy: strict-origin-when-cross-origin`.

## Endpoint reference

Every URL below is relative to `/api/v1`. A dash in a request/query column means none. Resource operations also return the shared errors above.

### Authentication, dashboard, and health

| Method and URL | Authentication | Body / query | Success | Additional errors |
| --- | --- | --- | --- | --- |
| `POST /auth/login` | Public | `{"email":"student@nsbm.ac.lk","password":"password123"}` | `200`, `{data:{access_token,token_type:"Bearer",user,permissions}}` | `401` invalid email/password; `422` invalid fields; `429` throttled. |
| `GET /auth/session` | Bearer | — | `200`, `{data:{user,permissions}}` | `401` invalid/expired token or inactive user. |
| `POST /auth/logout` | Bearer | — | `200`, `{data:{logged_out:true,token_revoked:false}}` | `401` invalid token. |
| `GET /dashboard/metrics` | `dashboard.read` | — | `200`, metrics described below. | Database failures use `503` or a generic server error. |
| `GET /system/health` | Public | — | `200`, `{data:{status:"operational",database:"connected",version:"1.0.0",latency_ms:12}}` | `503` when the database probe fails. |

`user` contains `id`, `student_id`, `name`, `email`, `role`, and `avatar_initials`; private authentication fields are excluded. Incorrect passwords and unknown emails use the same login error message.

Metrics return `active_members` (active user accounts), `clubs` (active clubs), `upcoming_events_count` (future scheduled/live schedules), and `bookings` (all booking records for administrators, own records otherwise). `weekly_activity` counts non-cancelled schedules for each of the last seven UTC dates; `recent_activity` lists recently created resources, with bookings scoped by ownership; `upcoming_events` lists the next six matching schedules. These counts and lists are calculated from MySQL. Dashboard date boundaries use UTC. The specification used `upcoming_events` twice in one example; the count is named `upcoming_events_count` and the list `upcoming_events` to produce unambiguous JSON.

### Collection query parameters

Every resource list supports `page`, `limit`, `search`, `sort`, and `order`. Page starts at 1; the default limit is 10 and requests above 100 are clamped to 100. `order` accepts `asc` or `desc`. Search is case-insensitive; SQL values are bound parameters and sort names map only to known columns. Unknown query parameters return `422`.

| Collection | Additional filters | Date meaning |
| --- | --- | --- |
| `/clubs` | `status`, `sport` | — |
| `/schedules` | `sport`, `status`, `location`, `date` | Exact `event_date`. |
| `/tournaments` | `sport`, `status`, `date` | Date within the tournament's inclusive start/end dates. |
| `/bookings` | `facility`, `status`, `date` | Exact `booking_date`; non-administrators only receive their own bookings. |

All lists may sort by `id`, `created_at`, or `updated_at`. The following additional names are whitelisted in `core/resources.php`; arbitrary SQL expressions are rejected.

| Collection | Additional sortable fields | Default sort |
| --- | --- | --- |
| `/clubs` | `name`, `sport`, `president`, `member_count`, `status` | `name` |
| `/schedules` | `sport`, `event_name`, `event_date`, `start_time`, `end_time`, `location`, `status` | `event_date` |
| `/tournaments` | `name`, `sport`, `start_date`, `end_date`, `format`, `max_teams`, `registered_teams`, `status` | `start_date` |
| `/bookings` | `facility`, `booked_by`, `sport`, `booking_date`, `start_time`, `end_time`, `status` | `booking_date` |

### Clubs

| Method and URL | Permission | Body / query | Success | Additional errors |
| --- | --- | --- | --- | --- |
| `GET /clubs` | `clubs.read` | Collection query and club filters. | `200`, paginated club list. | `422` invalid query. |
| `GET /clubs/{id}` | `clubs.read` | Positive integer ID; no body. | `200`, club. | `404` missing club. |
| `POST /clubs` | `clubs.create` | Club body below. | `201`, created club. | `409` duplicate name; `422` invalid fields or manager. |
| `PUT /clubs/{id}` | `clubs.update` | Complete club body. | `200`, updated club. | `403` manager does not own club; `404`; `409`; `422`. |
| `DELETE /clubs/{id}` | `clubs.delete` | ID; no body. | `200`, `{data:{deleted:true}}`. | `403` non-admin; `404`. |

```json
{"name":"Basketball Club","sport":"Basketball","president":"Student Name","member_count":42,"status":"active","description":"University basketball club","logo_url":null}
```

`name`, `sport`, and `president` are required; `member_count` is a non-negative integer and `status` is `active` or `inactive`. Defaults are `member_count: 0`, `status: "active"`, `description: ""`, and `logo_url: null`. A non-null `logo_url` must be an absolute HTTP/HTTPS URL. Administrators may use `manager_id` to assign an active club manager; managers cannot reassign ownership through their request body. Updates require the same required fields and retain the manager assignment if `manager_id` is omitted.

### Schedules

| Method and URL | Permission | Body / query | Success | Additional errors |
| --- | --- | --- | --- | --- |
| `GET /schedules` | `schedules.read` | Collection query and schedule filters. | `200`, paginated schedule list. | `422` invalid query. |
| `GET /schedules/{id}` | `schedules.read` | ID; no body. | `200`, schedule. | `404`. |
| `POST /schedules` | `schedules.create` | Schedule body below. | `201`, created schedule. | `422` invalid fields/times. |
| `PUT /schedules/{id}` | `schedules.update` | Complete schedule body. | `200`, updated schedule. | `404`; `422`. |
| `DELETE /schedules/{id}` | `schedules.delete` | ID; no body. | `200`, `{data:{deleted:true}}`. | `403` manager/student; `404`. |

```json
{"sport":"Football","event_name":"Faculty team training","event_date":"2026-11-10","start_time":"15:00","end_time":"17:00","location":"Main Ground","teams":"Computing faculty","status":"scheduled","description":"Evening training"}
```

Require `sport`, `event_name`, `event_date`, `start_time`, `end_time`, `location`, and `status`. Status is `scheduled`, `live`, `completed`, or `cancelled`. End time must be after start time on the same date. `teams` is plain text. Optional `teams` and `description` default to empty strings, including when omitted from a full update.

### Tournaments

| Method and URL | Permission | Body / query | Success | Additional errors |
| --- | --- | --- | --- | --- |
| `GET /tournaments` | `tournaments.read` | Collection query and tournament filters. | `200`, paginated tournament list. | `422` invalid query. |
| `GET /tournaments/{id}` | `tournaments.read` | ID; no body. | `200`, tournament. | `404`. |
| `POST /tournaments` | `tournaments.create` | Tournament body below. | `201`, created tournament. | `422` dates, team counts, or fields. |
| `PUT /tournaments/{id}` | `tournaments.update` | Complete tournament body. | `200`, updated tournament. | `404`; `422`. |
| `DELETE /tournaments/{id}` | `tournaments.delete` | ID; no body. | `200`, `{data:{deleted:true}}`. | `403` manager/student; `404`. |

```json
{"name":"Interfaculty Football Cup","sport":"Football","start_date":"2026-11-20","end_date":"2026-11-22","format":"Knockout","max_teams":16,"registered_teams":4,"status":"registration_open","description":"University faculty teams"}
```

Require `name`, `sport`, `start_date`, `end_date`, `format`, and positive integer `max_teams`. End date must be on or after start date. `registered_teams` is non-negative and cannot exceed `max_teams`. Status is `registration_open`, `upcoming`, `ongoing`, `completed`, or `cancelled`. Defaults are `registered_teams: 0`, `status: "registration_open"`, and `description: ""`. This API manages tournament records; it does not provide a separate team-registration endpoint.

### Bookings

| Method and URL | Permission | Body / query | Success | Additional errors |
| --- | --- | --- | --- | --- |
| `GET /bookings` | `bookings.read` | Collection query and booking filters. | `200`, paginated list; own records for student/manager, all for admins. | `422` invalid query. |
| `GET /bookings/{id}` | `bookings.read` plus ownership/admin | ID; no body. | `200`, booking. | `403` another user's booking; `404`. |
| `POST /bookings` | `bookings.create` | Booking body below. | `201`, created booking. | `409 BOOKING_CONFLICT`; `422` invalid facility/user/times. |
| `PUT /bookings/{id}` | Admin update or owner cancellation | Any editable booking fields for admin, validated against the merged record; exactly `{"status":"cancelled"}` for owner cancellation. | `200`, updated booking. | `403` unauthorized edit; `404`; `409` overlap/state; `422`. |
| `DELETE /bookings/{id}` | `bookings.delete` | ID; no body. | `200`, `{data:{deleted:true}}`. | `403` non-admin; `404`. |

Student/manager request:

```json
{"facility":"Main Ground","sport":"Football","booking_date":"2026-11-10","start_time":"15:00","end_time":"17:00","notes":"Training session"}
```

Require `facility`, `sport`, `booking_date`, `start_time`, and `end_time`. Defaults are authenticated `booked_by`, `status: "pending"`, and `notes: ""`. An admin request may set `booked_by` to an active user's ID and `status` to `pending`, `confirmed`, `cancelled`, or `completed`. Student/manager bookings are always owned by the authenticated user and begin as `pending`; explicitly requesting another owner or a non-pending initial status returns `403`. They may cancel only their own pending/confirmed booking and cannot edit its other fields, approve it, or delete it.

Allowed facilities are exactly `Main Ground`, `Indoor Court`, `Gymnasium`, `Swimming Pool`, `Tennis Court`, and `Badminton Court`. Invalid facility names are rejected. Booking end time must be after start time within the same day.

Creating or updating a non-cancelled booking locks the relevant controlled facility row within a transaction, then checks all other non-cancelled bookings for the same facility and date:

```text
existing.start_time < requested.end_time
AND existing.end_time > requested.start_time
```

Updates exclude their own ID. Adjacent intervals are allowed. Cancelled bookings do not reserve a slot. Overlaps return:

```json
{"error":{"code":"BOOKING_CONFLICT","message":"The selected facility is already booked during this time.","details":{"facility":"Main Ground","date":"2026-11-10"}}}
```

## cURL workflow

Commands below assume a local API. Replace `TOKEN`, resource IDs, and example dates with your actual values. Keep tokens out of shared logs.

```sh
# Public connectivity probe.
curl -i http://localhost:8000/api/v1/system/health

# Login; copy data.access_token from the JSON response.
curl -i -X POST http://localhost:8000/api/v1/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"email":"student@nsbm.ac.lk","password":"password123"}'

# Confirm identity and permissions.
curl -i http://localhost:8000/api/v1/auth/session \
  -H 'Authorization: Bearer TOKEN'

# Search and paginate clubs.
curl -i 'http://localhost:8000/api/v1/clubs?page=1&limit=10&search=football&status=active&sort=name&order=asc' \
  -H 'Authorization: Bearer TOKEN'

# Create an own pending reservation.
curl -i -X POST http://localhost:8000/api/v1/bookings \
  -H 'Authorization: Bearer TOKEN' -H 'Content-Type: application/json' \
  -d '{"facility":"Main Ground","sport":"Football","booking_date":"2026-11-10","start_time":"15:00","end_time":"17:00","notes":"Training session"}'

# Cancel the created booking; replace 1 with its returned ID.
curl -i -X PUT http://localhost:8000/api/v1/bookings/1 \
  -H 'Authorization: Bearer TOKEN' -H 'Content-Type: application/json' \
  -d '{"status":"cancelled"}'

# Logout, then discard the token in the client.
curl -i -X POST http://localhost:8000/api/v1/auth/logout \
  -H 'Authorization: Bearer TOKEN'
```

Use an administrator token for writes below:

```sh
curl -i -X POST http://localhost:8000/api/v1/clubs \
  -H 'Authorization: Bearer TOKEN' -H 'Content-Type: application/json' \
  -d '{"name":"Development Test Club","sport":"Football","president":"Test President","member_count":0,"status":"active","description":"Temporary test record"}'

# Replace 16 with the new club's returned ID.
curl -i -X PUT http://localhost:8000/api/v1/clubs/16 \
  -H 'Authorization: Bearer TOKEN' -H 'Content-Type: application/json' \
  -d '{"name":"Development Test Club","sport":"Football","president":"Updated President","member_count":1,"status":"active","description":"Updated test record"}'

curl -i -X DELETE http://localhost:8000/api/v1/clubs/16 \
  -H 'Authorization: Bearer TOKEN'

curl -i http://localhost:8000/api/v1/dashboard/metrics \
  -H 'Authorization: Bearer TOKEN'
```

## Verification and troubleshooting

Run the API suite against a dedicated, seeded development database with PHP and MySQL running:

```sh
python3 backend/tests/integration.py --base-url http://127.0.0.1:8000/api/v1
```

Python is an optional test tool, not an application runtime dependency. The suite creates and removes temporary records. See [`tests/README.md`](tests/README.md) for concurrency and optional JWT/rate-limit tests; see the [manual frontend/API checklist](docs/API_TEST_CHECKLIST.md) for the full browser workflow.

- `404` on all extensionless Apache routes: verify `mod_rewrite`, `.htaccess` permissions, and `APP_URL`'s subdirectory prefix.
- `401` with an apparently present token: verify Apache passes Authorization, compare the frontend API URL with `APP_URL`, and log in again after changing the JWT secret or issuer.
- Browser CORS rejection: configure the exact frontend origin, including protocol and port. `localhost` and `127.0.0.1` are different origins.
- `503` health response: check the database service, database name, account access, and `pdo_mysql`. Client responses intentionally omit credentials and raw SQL diagnostics.
- `429` login response: wait for `Retry-After`; avoid testing repeated invalid logins against shared users or a production deployment.
- Unexpected `409` reservation: inspect the same facility/date's non-cancelled intervals. Both pending and confirmed reservations block overlapping times.

PHP owns authorization and business rules, uses prepared statements, and sends generic unexpected-error responses. Frontend checks are only for usability. No database credentials or SQL are sent to the browser.
