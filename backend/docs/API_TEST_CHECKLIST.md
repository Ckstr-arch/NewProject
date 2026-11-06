# API and frontend integration checklist

Run this on a dedicated development database. Start MySQL, import `database/schema.sql` and `database/seed.sql`, configure the backend, and start it with `php -S localhost:8000 router.php`. Serve the frontend over HTTP and select live mode. Use the development accounts documented in [the backend README](../README.md).

Record the browser URL, API base URL, PHP/MySQL versions, and date of the test. Check both the visible result and the browser Network panel's method, URL, status, and JSON body. This checklist describes expected results; it is not a claim that a deployment has passed them.

## Required end-to-end workflow

- [ ] `GET /api/v1/system/health` returns `200`, `status: operational`, and `database: connected` from a real MySQL connection.
- [ ] Open the frontend and sign in as `student@nsbm.ac.lk` / `password123`. Login returns a Bearer token, a student user, and permissions without password fields.
- [ ] Refresh the page. `GET /auth/session` restores identity using the token; it does not grant admin controls.
- [ ] Load the dashboard. Counts and activity load from `/dashboard/metrics`.
- [ ] Load clubs, search for Football, and change pages. Returned items and pagination agree with MySQL data.
- [ ] Open schedules and tournaments; use their filters and sorting. No demo records replace failed live requests.
- [ ] Create a booking for a free future facility interval. It persists as the student's own pending booking.
- [ ] Refresh the page and confirm the booking remains present.
- [ ] Create another booking for the same facility/date with an overlapping interval. The API rejects it with `409 BOOKING_CONFLICT`, and the form displays a useful error.
- [ ] Create an adjacent interval with start equal to the previous interval's end. It succeeds.
- [ ] Cancel the student's pending booking. Its status becomes `cancelled`, freeing that interval.
- [ ] Sign out and sign in as `admin@nsbm.ac.lk` / `admin123`.
- [ ] Note the dashboard club count, then create a uniquely named club. The API returns `201`, the club appears after reload, and the database-derived count increases.
- [ ] Edit the club and verify the response, list, and subsequent reload contain the update.
- [ ] Delete the temporary club. The API returns `200` with `data.deleted: true`; the club disappears and the count returns to its earlier value.
- [ ] Create, edit, and delete a temporary schedule and tournament using their full validated fields.
- [ ] As admin, view all bookings, confirm a pending booking, and verify changes persist after refresh.
- [ ] Sign out. The browser discards its token and a request without Authorization returns `401`.
- [ ] Confirm the UI does not describe stateless logout as server-side token revocation.

## Authentication and authorization

- [ ] Wrong password and unknown email both return `401` with the same generic invalid-login message.
- [ ] A protected endpoint without a token returns `401` JSON rather than an HTML page.
- [ ] Altered, expired, malformed, and unsigned JWTs are rejected with `401`.
- [ ] A disabled user cannot continue using an otherwise valid token.
- [ ] Database role changes affect subsequent authorization; request-supplied `role`, `permissions`, or `user_id` cannot elevate privileges.
- [ ] A student cannot create/update/delete clubs, schedules, or tournaments and receives `403`.
- [ ] A club manager can create a club, update that managed club, and create/update schedules; cannot update another manager's club or delete clubs.
- [ ] A club manager cannot assign someone else as the owner of a managed club through the request.
- [ ] Sports and super administrators can perform their documented resource operations.
- [ ] Repeated invalid logins eventually produce `429` and `Retry-After`. Run this last on an isolated test instance, since it temporarily blocks logins from the test IP.

## CRUD, filtering, and validation

- [ ] All four resource collections return `{data:{items,page,limit,total,pages}}`; empty collections report zero total/pages.
- [ ] Search is case-insensitive and accepts apostrophes without SQL errors.
- [ ] Valid status, sport, location, facility, and date filters select the expected rows.
- [ ] Sorting accepts documented field names and `asc`/`desc`; SQL expressions and unknown fields are rejected.
- [ ] Pagination cannot use a page below 1 or exceed the 100-item limit.
- [ ] Missing required fields produce `422` with field-specific details.
- [ ] Negative/non-integer member counts or team counts are rejected.
- [ ] Tournament end dates before start dates, zero capacity, and registered teams above capacity are rejected.
- [ ] Invalid calendar dates, impossible times, and end times before/equal to start times are rejected.
- [ ] Unknown enum values and invalid facilities are rejected.
- [ ] Unknown IDs produce `404`, and invalid IDs are rejected without SQL errors.
- [ ] Malformed JSON produces `400`; unsupported methods produce `405` with an `Allow` header.
- [ ] Duplicate club names produce a conflict response without exposing SQL.
- [ ] All successful deletions use the same `200` and `data.deleted: true` response convention.

## Booking ownership and concurrent writes

- [ ] Student-supplied `booked_by` cannot create a booking belonging to a different user.
- [ ] A student cannot create a confirmed/completed booking or approve their own pending booking.
- [ ] A student sees only their own bookings and cannot read another user's booking by ID.
- [ ] Owner cancellation accepts only a pending/confirmed booking; changing its facility, owner, date, or times is forbidden.
- [ ] Students cannot delete bookings; administrators can manage bookings on behalf of active users.
- [ ] Same-facility/date overlaps are rejected for both creation and updates, excluding the updated record itself.
- [ ] Identical intervals on different facilities or dates can coexist.
- [ ] Cancelled bookings do not block availability; other non-cancelled bookings do.
- [ ] Two concurrent requests for the same free interval result in exactly one creation and one `409`, with only one active row persisted. Use multiple PHP workers or Apache for this test; a single-worker development server serializes HTTP requests before they reach MySQL.
- [ ] A failed update leaves the original booking unchanged.

## Hosting, responses, and persistence

- [ ] Allowed-origin OPTIONS preflight succeeds without authentication and permits Authorization/Content-Type and documented methods.
- [ ] An unconfigured origin does not receive authenticated CORS access; no wildcard origin is used.
- [ ] Responses include JSON content type and the documented security headers.
- [ ] `/config`, `/core`, `/database`, private `.env`/local configuration, tests, and SQL files are inaccessible through the web server.
- [ ] Apache/XAMPP subdirectory URLs and Authorization forwarding work with the configured `APP_URL`.
- [ ] MySQL outage makes health return `503`, without exposing credentials, filesystem paths, SQL, or stack traces.
- [ ] Restoring MySQL makes health operational again and previously created records remain present.
- [ ] Re-importing seed data does not duplicate seed rows or reset an existing development user's password.
- [ ] Returned text containing HTML syntax renders as text in the frontend.
- [ ] Demo-only modules are unavailable in live mode; no missing API endpoint is represented as a working control.

For repeatable API checks, see [`../tests/README.md`](../tests/README.md).
