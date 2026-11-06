# Authentication, sessions and authorization

Live mode uses native PHP sessions. The browser stores a server-issued session cookie, while PHP stores the authenticated identity and CSRF state on the server. The frontend's user profile is display state, not proof of identity. A forged role in JavaScript or a manually constructed request still faces PHP authorization.

## Session lifecycle

1. `GET /api/v1/auth/session` works anonymously. PHP starts or resumes a session and returns a sanitized user or `null`, plus a cryptographically random CSRF token.
2. The live HTTP adapter retains that token in memory. The browser retains the cookie according to its cookie settings; no session ID is returned in JSON.
3. Login posts JSON email/password with the session cookie and `X-CSRF-Token`. PHP validates the body, rate limit and token, loads the account, and verifies its hash with `password_verify()`.
4. A successful login regenerates the session identifier and rotates the CSRF token. The returned user has only `id`, `name`, `email`, `role`, `studentId`, `faculty` and `notifications`.
5. Each protected request checks the server session and current account. Both absolute time since login and idle time since the last request are bounded by `SESSION_TTL` (default 7200 seconds); the browser cannot extend its own authority by changing the frontend store.
6. Logout requires authentication and CSRF. PHP invalidates authenticated state and the old cookie, then establishes anonymous state with a fresh token for the documented response.

```mermaid
sequenceDiagram
    participant Browser
    participant PHP
    participant MySQL
    Browser->>PHP: GET auth/session
    PHP-->>Browser: Anonymous user + CSRF token + session cookie
    Browser->>PHP: POST auth/login, JSON, cookie, CSRF header
    PHP->>MySQL: Load account and enforce login limit
    PHP->>PHP: Verify password; rotate session and CSRF
    PHP-->>Browser: Sanitized user + new token
    Browser->>PHP: Protected request with session cookie
    PHP-->>Browser: Authorized data or 401/403
```

A session cookie is not a password and should never be logged or pasted into an issue. The backend does not use bearer-token authentication, external identity providers or production localStorage tokens.

## Password hashing

The database stores a `password_hash`, never the original password. PHP uses Argon2id when available and a supported native fallback otherwise. `password_verify()` checks a supplied password against the stored hash. Never compare a plaintext database column or copy a mock password into production.

## Private account provisioning

The explicit development seed creates synthetic accounts only when `APP_ENV=development`. Production starts with a fresh, unseeded schema. Provision an administrator through the private CLI, from the repository root in a trusted Bash terminal:

```bash
read -r -p 'Administrator email: ' USER_EMAIL
read -r -p 'Administrator name: ' USER_NAME
read -r -s -p 'Administrator password (12–128 bytes): ' USER_PASSWORD
export USER_EMAIL USER_NAME USER_PASSWORD
export USER_ROLE=admin
php backend/database/create-user.php --confirm-create
unset USER_EMAIL USER_NAME USER_PASSWORD USER_ROLE
```

This avoids a password in shell history/command arguments. Keep the terminal/process environment private. The CLI requires an explicit creation flag and refuses to overwrite an existing account. Argon2id accepts the documented 12–128-byte range; if the installed PHP falls back to bcrypt, the provisioner refuses passwords over 72 bytes or containing NUL bytes to avoid silent bcrypt truncation. Optional `USER_STUDENT_ID` and `USER_FACULTY` are useful when privately provisioning a student instead; set `USER_ROLE=student`. Clear any temporary variables afterward. There is no public signup or password-reset endpoint in this contract.

## CSRF protection

A cookie can be attached by a browser automatically. CSRF protection prevents a different website from making an authenticated write merely by causing that browser to submit a request. The frontend must obtain the token through the session handshake and send it in `X-CSRF-Token` for every POST, PUT, PATCH and DELETE, including login/logout.

PHP compares the supplied token against the session-bound token using a constant-time comparison. Missing or incorrect tokens return 403. If an Origin header is supplied, it must exactly match configured `APP_URL` (with its trailing slash removed); an untrusted origin is rejected even when a token is present. Tokens rotate when authentication state changes. The token is held in `http.js` memory rather than localStorage; a reload reacquires it through `auth/session`.

The UI does not silently retry writes. After a 403, check role/ownership first; if the security session is stale, reload/rebootstrap and submit explicitly. A 401 clears the HTTP client's in-memory CSRF token; a previously authenticated user returns to login. Before each user-initiated login attempt, the existing login form calls `await api.session()` to reacquire current anonymous state, then submits one login POST. This also lets a wrong-password attempt be followed by a corrected password in the same form; it does not automatically retry the failed POST. Do not fix 403 errors by disabling CSRF checks.

## Role and ownership checks

| Operation | Server rule |
|---|---|
| Clubs/schedules/tournaments/results reads | Authenticated student or administrator |
| Club/schedule/tournament CRUD and match writes | Administrator |
| Membership application / tournament registration / new booking | Student identity derived from session |
| Membership approval or rejection | Administrator; pending state and capacity checked |
| Booking review | Administrator; valid transition and reservation rules checked |
| Student booking cancellation | Own future pending/approved booking only |
| Personal histories/profile | Current server-authenticated user only |
| Administrative reports | Administrator |
| Anonymous session and health | Public; health still performs an actual database probe |

Bodies cannot choose `userId` or role. Profile PATCH accepts only `name`, nullable `faculty` and boolean `notifications`; `id`, `email`, `studentId`, role and password-hash fields are rejected. A missing/expired login is 401; a signed-in user lacking permission is 403. A valid role does not override state/capacity conflicts, which return 409.

## Login throttling across workers

`login_attempts` stores server-side counters and window timestamps in MySQL. Attempts are coordinated transactionally, so several PHP workers do not each receive an independent in-memory limit. The configured local defaults are `LOGIN_LIMIT=8` and `LOGIN_WINDOW=900` seconds. A normalized-email account bucket uses that limit, and a client-address bucket uses `max(50, 10 × LOGIN_LIMIT)` to cover attempts that rotate email addresses. The buckets are locked in a consistent order. An exhausted bucket returns 429 and Retry-After; a completed window permits another attempt according to the fixed-window rule. Successful login clears its account bucket, while the address bucket remains until its window expires.

Buckets use hashed keys rather than a plaintext password or copied session token. Do not treat this as a complete abuse-prevention service: a production reverse proxy and operational monitoring can apply additional appropriate limits. Trust a proxy's client-address headers only when the deployment explicitly validates that proxy; arbitrary request headers are not reliable identity.

## Cookies and deployment

`SESSION_NAME` distinguishes this application's cookie. The implementation sets a session cookie with path `/api/v1`, `HttpOnly` and `SameSite=Lax`; it has no persistent cookie lifetime. Production requires a Secure cookie and an HTTPS `APP_URL`; startup refuses unsafe production settings with 503. Local HTTP development uses `SESSION_SECURE=false`; production must use HTTPS and secure settings. PHP stores sessions in ignored `backend/var/sessions/`, created with private permissions. Give the PHP service suitable private write access, never a world-writable public directory.

Prefer the frontend and API on the same origin. Permissive CORS is not enabled by default. CORS, CSRF and authentication solve different problems; none replaces the others. See [deployment](DEPLOYMENT.md) and [frontend connection](FRONTEND_CONNECTION.md).

## Error and logging boundaries

Incorrect login returns a generic message without revealing whether an email exists. API responses never include password hashes, session identifiers, SQL statements, database hostnames or stack traces. Private diagnostic logs should record sufficient error categories/context for operators without dumping request bodies, credentials or entire user records.

Security claims require actual test evidence: consult [TEST_RESULTS.md](TEST_RESULTS.md) for session fixation, rotation, expiry, ownership, rate-limit and other checks executed on the current implementation. A deployment's HTTPS and cookie behavior must also be tested on that real host.
