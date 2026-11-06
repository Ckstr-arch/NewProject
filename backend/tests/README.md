# API integration tests

These tests exercise the running PHP API and its real MySQL database. Python 3 is used only as an optional test client; the application requires PHP and MySQL.

1. Configure a separate development database and import `database/schema.sql` and `database/seed.sql`.
2. Start the API as described in the backend README. For a genuine concurrent booking test, use Apache or PHP's development server with multiple workers on a supported Unix system.
3. Run from the repository root:

```sh
python3 backend/tests/integration.py --base-url http://127.0.0.1:8000/api/v1
```

The suite uses the four documented development accounts. It creates uniquely named clubs, schedules, tournaments and far-future reservations; it deletes these records through the API in a `finally` block. It does not import/reset the schema or edit existing users and seed records. Use a development instance, since real writes and two failed login attempts occur.

Coverage includes authentication, session identity, tampered tokens, current roles, permissions, ownership, JSON envelopes, HTTP status codes, security headers, CORS, private-path protection, all four CRUD resources, Unicode, filters, search, pagination, whitelisted sorting, malformed input, real dashboard metrics, facility validation, reservation conflicts, adjacency, cancellation, booking updates and concurrent overlapping creates.

The client never follows HTTP redirects, so Apache directory redirects cannot hide a changed request method or incorrect endpoint URL. It also verifies that a password containing a NUL byte is rejected before bcrypt verification.

For additional signed-token tests, write the **test instance's** JWT secret to a private file outside the repository and add `--jwt-secret-file /private/test-jwt-secret`. The secret is read as data and never printed. These cases cover expired/future tokens, algorithm substitution and a validly signed token whose claimed role disagrees with the database role.

`--origin` selects an allowed origin when testing a different CORS configuration. Its default is `http://localhost:5500`.

`--rate-limit` additionally triggers login throttling at the end. Run this option only on a disposable instance: its IP limiter may block all subsequent logins from the test client for 15 minutes. Reset `login_attempts` only in that isolated test database before further browser/login testing, or wait for the documented expiry.

The API suite does not replace a browser integration check. Follow [the manual workflow](../docs/API_TEST_CHECKLIST.md) to verify the frontend against the running API.
