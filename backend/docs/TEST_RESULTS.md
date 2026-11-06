# Verification record — 6 November 2026

The application was exercised against a new, isolated MySQL 8.4.13 database.
Existing databases and the archived files from the previous backend were not used.

| Verification | Result |
| --- | --- |
| PHP 8.4.27 built-in server, HTTP suite including signed JWT negatives, concurrent bookings and login throttling | 779 assertions passed |
| Apache 2.4.68 / PHP 8.2.35, XAMPP-style `/nsbm-sportshub/backend/api/v1` prefix, final HTTP suite | 736 assertions passed |
| Isolated JWT and permission checks | 33 passed |
| Real browser → PHP → MySQL workflow | 17 checkpoints passed |
| Synthetic browser transport checks | 5 passed |
| Explicit standalone demo smoke checks | 4 passed |
| Missing schedule status and explicit-null validation cases | 8 passed |
| PHP syntax checks | All 27 PHP files passed on PHP 8.2 and PHP 8.4 |

The HTTP runs used different options. The first included the optional login
rate-limit test. The final Apache run also included the added null-byte password
regression and disabled automatic redirects in the HTTP test client.

The Apache test exposed a trailing-slash redirect for collection URLs that
could change POST into GET. The supplied `.htaccess` now uses `DirectorySlash
Off` and `RewriteOptions AllowNoSlash`; collection writes reach the router
without that redirect. The final Apache suite includes all four resources,
authentication, permissions, ownership, validation, pagination/search/sorting,
CORS, private-file protection, and concurrent overlapping reservations.

The browser run exercised student sign-in/reload, club search, persistent
bookings, overlap rejection, cancellation, admin club changes and dashboard
metrics, schedule/tournament/admin-booking create/edit/delete forms, mobile
layout, logout, and an unauthenticated protected request. No uncaught JavaScript
errors occurred.

The SQL schema and seed imported successfully, including a second seed import.
After API and browser test cleanup, the database still contained exactly four
users, 15 clubs, 12 schedules, five tournaments, six bookings, and six controlled
facilities. Test-created records were removed. The health handler was also
tested with an unavailable database: it returned HTTP 503 with a generic JSON
error and no connection details.

Local containers supplied PHP/MySQL/Apache for these checks because the cloud
host lacked native PHP and MySQL. They are QA infrastructure outside this
repository; Docker is not an application or hosting requirement.

Repeatable commands and manual procedures are in [the test guide](../tests/README.md),
[the acceptance checklist](API_TEST_CHECKLIST.md), and
[the frontend verification record](../../nsbm-sportshub-frontend/docs/TEST_CHECKLIST.md).
Actual hosting HTTPS settings, credentials, and origins must be configured for
the target deployment.
