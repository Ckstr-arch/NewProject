# Current PHP/MySQL API contract

The authoritative backend contract and complete cURL examples are in
[`backend/README.md`](../../backend/README.md). The browser uses the same paths
through `js/services/live-endpoints.js`, re-exported by `endpoints.js`.

API base: `http://localhost:8000/api/v1`.

| Methods | Path | Purpose |
|---|---|---|
| POST | `/auth/login` | Email/password login; returns access_token, token_type, user, permissions |
| GET | `/auth/session` | Current authenticated user and permissions |
| POST | `/auth/logout` | Acknowledge logout; client discards the stateless JWT |
| GET | `/dashboard/metrics` | Actual database counts, weekly/recent activity, upcoming schedule list |
| GET, POST | `/clubs` | List or create clubs |
| GET, PUT, DELETE | `/clubs/{id}` | Read, update, or delete a club |
| GET, POST | `/schedules` | List or create schedules |
| GET, PUT, DELETE | `/schedules/{id}` | Read, update, or delete a schedule |
| GET, POST | `/tournaments` | List or create tournaments |
| GET, PUT, DELETE | `/tournaments/{id}` | Read, update, or delete a tournament |
| GET, POST | `/bookings` | List or create facility bookings |
| GET, PUT, DELETE | `/bookings/{id}` | Read, update, cancel, or delete a booking |
| GET | `/system/health` | Public MySQL connectivity check |

Except login/health, requests require `Authorization: Bearer TOKEN` from login.
No PHP cookie or CSRF handshake is used. Roles are `student`, `club_manager`,
`sports_admin`, and `super_admin`. PHP resolves the current active account and
permissions; browser roles and ownership fields are never authoritative.

Success: `{ "data": {} }`. Lists:
`{ "data": { "items": [], "page": 1, "limit": 10, "total": 0, "pages": 0 } }`.
Errors: `{ "error": { "code": "VALIDATION_ERROR", "message": "...", "details": {} } }`.
Created records return 201; deletes consistently return an empty 204. Backend
errors use meaningful HTTP codes such as 400/401/403/404/409/422/500/503.

Resource fields use snake_case exactly as specified:

- Clubs: `name`, `sport`, `president`, `description`, `member_count`, `status`,
  `logo_url`; `manager_id` identifies an assigned club manager.
- Schedules: `sport`, `event_name`, `event_date`, `start_time`, `end_time`,
  `location`, `teams`, `status`, `description`.
- Tournaments: `name`, `sport`, `start_date`, `end_date`, `format`, `max_teams`,
  `registered_teams`, `status`, `description`.
- Bookings: `facility`, `booked_by`, `sport`, `booking_date`, `start_time`,
  `end_time`, `status`, `notes`.

Lists accept `page`, `limit`, `search`, `status`, `sort`, and `order`; sport/date/
location/facility filters depend on resource. See the backend README for exact
whitelists, validation, ownership, and role permissions. Maximum page size is100.
Booking overlaps return 409 `BOOKING_CONFLICT`; cancelled bookings do not reserve
a slot. A student cancels an owned pending/confirmed booking with
`PUT /bookings/{id}` and `{ "status": "cancelled" }`.

The dashboard uses `upcoming_events_count` for the numeric count and
`upcoming_events` for its list, resolving the duplicate key in the prompt example.

See [PHP backend integration](PHP_BACKEND_INTEGRATION.md) for browser setup.
The separate [demo contract](DEMO_API_CONTRACT.md) describes local simulation
only and does not request or define additional PHP endpoints.
