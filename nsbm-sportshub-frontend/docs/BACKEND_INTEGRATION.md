# Connect a PHP/MySQL backend

The compatible PHP/MySQL backend is now implemented in [`backend/`](../../backend/README.md). For tested same-origin startup commands and current evidence, use [FRONTEND_CONNECTION.md](../../backend/docs/FRONTEND_CONNECTION.md) and [TEST_RESULTS.md](../../backend/docs/TEST_RESULTS.md). This contract guide remains useful for understanding the protocol.

The frontend and both adapters follow [API_CONTRACT.md](API_CONTRACT.md). Implement that contract before switching the default mock mode. No existing PHP application is assumed to be compatible merely because endpoint names look similar.

## 1. Implement and serve the backend

Provision a PHP runtime with PDO MySQL and a supported MySQL server. Use private server-side configuration outside the document root for credentials. Implement every endpoint in `js/services/endpoints.js`, returning the documented camelCase fields and envelopes. The [database guide](DATABASE_GUIDE.md) proposes relationships but is not executable database code.

Serve the frontend directory as static assets and route API requests to PHP. Prefer a shared HTTPS origin such as `https://sportshub.example.edu/` for static files and `/api/v1/` for PHP. Hash navigation does not require rewriting frontend paths; the server receives the path before `#` only. Do not expose source configuration, SQL exports or private logs under the web root.

## 2. Set the base URL and adapter

In `js/config.js`, set `API_BASE_URL` to the backend prefix, e.g. `/api/v1`, then set `API_MODE` to `live`. No view needs editing. The API client appends paths from `endpoints.js`; do not duplicate prefixes in PHP routes or view files. Use a trusted HTTPS backend in production.

## 3. Establish cookie sessions before writes

`GET /auth/session` must be available anonymously. Start an anonymous PHP session and return a CSRF token alongside `user:null`. On an authenticated request, return the sanitized User record. The frontend waits for this bootstrap before showing protected views.

The client uses `fetch` with `credentials:'include'`. The backend must use server-managed PHP sessions. Return only profile fields; never a password hash or session ID in JSON. Regenerate the PHP session ID after successful login. Hash passwords with PHP's supported password-hashing API (Argon2id when available, otherwise a reviewed supported default), verify with `password_verify`, and rate-limit login attempts. The public mock passwords are not production account requirements.

Production cookies should be `HttpOnly`, `Secure` and appropriately `SameSite` scoped. Set narrowly scoped cookie/domain settings and server-side expiry. Destroy server session state and expire cookies on logout.

## 4. Implement the CSRF handshake

Session response:

```json
{"success":true,"data":{"user":null,"csrfToken":"opaque-unpredictable-token"},"message":"Session ready"}
```

For every POST/PUT/PATCH/DELETE, read `X-CSRF-Token` and compare it in constant time with the session's token. Validate Origin where appropriate. This includes login and logout. Successful login rotates the session ID and returns a new CSRF token:

```json
{"success":true,"data":{"user":{"id":2,"name":"Student","email":"student@example.edu","role":"student","studentId":"ST-002","faculty":"Computing","notifications":true},"csrfToken":"rotated-token"}}
```

Return 403 for absent/mismatched CSRF. Do not silently accept the request or rely on hidden buttons. CSRF acquisition/renewal is isolated in the service layer. The frontend does not automatically retry writes after rejection; a user explicitly reboots/reloads the session before retrying. No protection is claimed until the PHP implementation enforces this contract.

## 5. Match JSON and response semantics

PHP should read JSON from `php://input`, validate the decoded object and send an appropriate `Content-Type: application/json; charset=utf-8` for JSON responses. For example, a student membership request body is:

```json
{"clubId":1}
```

Derive the student ID and role from the server session, then return 201:

```json
{"success":true,"data":{"id":41,"userId":2,"userName":"Student","clubId":1,"clubName":"Cricket Club","status":"pending","createdAt":"2026-11-05T09:00:00.000Z"},"message":"Application submitted"}
```

A field error returns HTTP 422, for example:

```json
{"success":false,"message":"Validation failed","errors":{"clubId":["Select an existing club"]}}
```

A successful DELETE may use 204 with an empty body. Do not emit PHP notices or HTML before/after JSON; malformed responses are shown as service errors. Dates must have an explicit timezone and serialize to ISO 8601 UTC; display formatting belongs to the frontend.

## 6. Enforce authorization and business rules

For every request verify authentication, role and ownership. Students may never choose `userId` or grant themselves admin status. Whitelist permitted mutation fields; reject unknown fields. Use prepared PDO statements, schema constraints and transaction-safe duplicate/capacity/overlap checks. Lock the relevant club/tournament/resource parent row so simultaneous requests cannot bypass limits.

Check that admin CRUD preserves references, that students see only their histories, that profile updates cannot change email/role/student ID, and that reports are admin-only. Return 403 for forbidden actions even when the caller manually invokes the endpoint outside the UI.

## 7. Test one endpoint family at a time

1. Confirm `/auth/session` returns valid JSON with cookie/CSRF behavior.
2. Verify student and admin login, bootstrap after reload, and logout.
3. Check list pagination, filters and detail shapes against the contract.
4. Exercise create/update/delete through admin dialogs and verify database rows.
5. Exercise membership request/approval, tournament registration, booking review and results.
6. Verify dashboard counts and reports change after successful writes.
7. Test duplicate, capacity, overlap, invalid date, foreign-role, unauthenticated and malformed-body requests directly against PHP.
8. Disable the backend or database temporarily in a disposable test environment; the UI must show an error and never demo data.
9. Confirm `/system/health` actually performs a database connectivity probe. A static `status:"ok"` response is insufficient.

## 8. CORS if separate origins are unavoidable

A shared origin avoids much of the cookie and CORS complexity. If using a different API origin, set `API_BASE_URL` to its absolute URL and configure the API to allow only the exact frontend origin, not `*`, with `Access-Control-Allow-Credentials:true`. Handle OPTIONS preflights for JSON and `X-CSRF-Token` and allow required methods/headers. Return `Vary: Origin` when varying origins.

Cross-site cookies need browser-compatible `SameSite=None; Secure` settings; third-party cookie restrictions can still prevent sessions. CORS is not authorization or CSRF protection. Test the actual hosting origins in target browsers; do not disable browser security to make integration pass.

## 9. Deploy and record evidence

Use HTTPS, restricted database grants, private backups, secure logs and production error handling. Avoid exposing raw SQL errors or stack traces. Confirm MIME types for `.js`, preserve ES module paths and serve current config without stale caching. Check 375px, 768px, 1024px and 1440px layouts and keyboard behavior on the deployed host.

Record live backend versions, tested endpoints, response status and remaining defects in the acceptance record. Mock tests validate frontend workflows; they do not verify PHP/MySQL or a public deployment.
