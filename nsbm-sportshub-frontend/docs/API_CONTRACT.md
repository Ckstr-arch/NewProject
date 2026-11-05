# SportsHub API contract v1

This is the proposed contract for a separately implemented PHP/MySQL backend. No backend is included. All paths in `js/services/endpoints.js` are relative to `CONFIG.API_BASE_URL` (default `/api/v1`). Dates are ISO 8601 UTC strings (`2026-11-06T09:00:00.000Z`); IDs are positive integers; text is plain text. JSON requests and responses use UTF-8 and camelCase fields.

## Envelopes and method interface

Success: `{ "success": true, "data": {}, "message": "Saved" }`. List: `{ "success": true, "data": [], "pagination": { "page": 1, "limit": 10, "total": 0, "totalPages": 0 } }`. Error: `{ "success": false, "message": "Validation failed", "errors": { "name": ["This field is required"] } }`. A deletion may return empty HTTP 204; the client normalizes this to `{success:true,data:null}`. Both adapters expose `request(method, path, body, query)` and the public `api` exposes identical named functions in both modes. List functions take query; create functions take body; get/delete/standings/register functions take id; update/status functions take (id, body); login/updateProfile take body; remaining functions take no arguments. Report methods take query.

Lists accept `page=1`, `limit=10` (1–100), `search` (case-insensitive text), `sport`, `status`, `type`, `sort` (resource field), `order=asc|desc`. Unknown filters must not broaden authorization. `total` is the filtered total before pagination. Zero items has `totalPages:0`. Full schemas, roles, endpoint details and examples follow.

## Authentication and CSRF

Live authentication uses PHP session cookies, `credentials: include`, never localStorage tokens. `GET /auth/session` is public and returns `{user:null,csrfToken:"opaque"}` for anonymous users, or `{user:User,csrfToken:"opaque"}`. Acquire it before login or other writes. Send `X-CSRF-Token` on every state-changing request including login/logout. Login returns `{user:User,csrfToken:"rotated-token"}`. Logout invalidates the session and returns `{user:null,csrfToken:"new-anonymous-token"}`. `403` denotes invalid CSRF/forbidden role, and must not be retried automatically; rebootstrap the session explicitly before retrying manually. A `401` clears frontend user state. Mock sessions are development simulations and offer no security.

## Core records (required unless explicitly nullable)

- User: `id:number, name:string (2–80), email:string, role:admin|student, studentId:string|null, faculty:string|null, notifications:boolean`. Passwords appear only in login requests and are never persisted by the frontend.
- Club: `id, name:string (2–100), sport:string (2–60), description:string (1–1000), coach:string (2–100), location:string (2–100), capacity:integer (1–1000)`.
- Membership: `id, userId, userName:string, clubId, clubName:string, status:pending|approved|rejected, createdAt:ISO timestamp`.
- Schedule: `id, clubId, clubName:string, sport:string, title:string (2–100), type:training|match|event, startsAt:ISO timestamp, endsAt:ISO timestamp, location:string (2–100)`; end strictly after start.
- Tournament: `id, name:string (2–100), sport:string, description:string, location:string, startsAt:ISO timestamp, endsAt:ISO timestamp, registrationDeadline:ISO timestamp, capacity:integer (1–1000), registrationCount:integer`; deadline before start.
- Registration: `id, userId, tournamentId, tournamentName:string, createdAt:ISO timestamp`.
- Match: `id, tournamentId, tournamentName:string, sport:string, homeTeam:string, awayTeam:string, startsAt:ISO timestamp, homeScore:integer|null, awayScore:integer|null, status:scheduled|completed`; scores 0–999 and both required only when completed. Teams must differ.
- Standing: `team:string, played:integer, won:integer, drawn:integer, lost:integer, points:integer`; completed wins earn 3, draws 1, losses 0. Sort by points descending then name.
- Facility: `id, name:string, type:facility|equipment, location:string`.
- Booking: `id, userId, userName:string, facilityId, facilityName:string, type:facility|equipment, startsAt:ISO timestamp, endsAt:ISO timestamp, purpose:string (3–300), status:pending|approved|rejected|cancelled, createdAt:ISO timestamp`. New request start must be in the future; end after start; maximum duration 4 hours. Pending/approved overlaps for the same resource are conflicts. Adjacent intervals are allowed.
- Participation: `id, userId, event:string, sport:string, date:ISO timestamp, status:attended|registered`.

Create/update bodies contain editable fields only, never id/userId/userName/counts/derived names. The server derives identity from session and relationship labels from foreign keys. PUT requires all editable fields. Unknown mutation fields yield 422. PATCH accepts only documented fields.

## Shared rules for the endpoint reference

Every endpoint below returns the envelope above. `User`, `Club`, and other capitalized names refer to the complete record schemas above, not arbitrary dictionaries. Every `id` path parameter must be a positive integer. Malformed identifiers yield 400; missing records yield 404. Unless an endpoint is marked **public**, requests require a valid session (401 otherwise). Role restrictions are enforced by PHP (403 otherwise), not merely by hidden buttons. All writes require the CSRF header (403 if missing/invalid).

Use 200 for successful reads/updates/session actions, 201 for creates, 204 for deletion, 400 for malformed JSON/query/path, 401 for invalid/expired authentication, 403 for role/ownership/CSRF failures, 404 for absent resources, 409 for duplicates/state/capacity/overlap/dependency conflicts, 422 for field validation, 500 for unexpected server errors and 503 when a required database/service is unavailable. The `errors` object maps field names to arrays of human-readable messages on 422; do not send SQL, stack traces, session identifiers or secrets. Example: `{"success":false,"message":"Validation failed","errors":{"endsAt":["End must be after start"]}}`. Common 400/401/403/500/503 cases apply to every relevant endpoint even when omitted from a row's additional-error list.

`L` below means the shared list queries: `page`, `limit`, `search`, `sport`, `status`, `type`, `sort`, `order`. Only fields belonging to the resource may be used for sorting; invalid sort/order/filter values return 422. Non-applicable filters should be omitted. Resource-specific `clubId`, `tournamentId` and `facilityId` relationship filters are also supported where present. Pagination applies to list endpoints, including personal histories and report rows. Detail/mutation endpoints have no query parameters. No request body is sent for GET or DELETE, or when a row says `none`.

### Authentication

| Method and path | Public / role | Request body and example | Success data / status | Additional errors |
|---|---|---|---|---|
| `GET /auth/session` | Public | None; no query | `{ "user": null, "csrfToken": "opaque" }` or `{ "user": User, "csrfToken": "opaque" }`, 200 | 503 if session storage unavailable |
| `POST /auth/login` | Public, with anonymous CSRF | `email:string`, `password:string`; `{"email":"student@example.test","password":"Student123!"}` | `{"user":User,"csrfToken":"rotated-token"}`, 200 | 401 incorrect credentials; 422 invalid email/empty password; recommended 429 rate limit |
| `POST /auth/logout` | Student/admin | None | `{"user":null,"csrfToken":"new-anonymous-token"}`, 200 | 401 expired session; frontend also clears local user state on any 401 |

A complete anonymous response is `{"success":true,"data":{"user":null,"csrfToken":"opaque"},"message":"Session ready"}`. Login must rotate the PHP session ID and CSRF token. Logout must invalidate session state and expire its cookie. The sample credentials are public mock-only examples; do not create these accounts in production.

### Dashboard

| Method and path | Role | Query/body | Success data / status | Additional errors |
|---|---|---|---|---|
| `GET /dashboard/metrics` | Student/admin | None | `{"activeClubs":12,"memberships":3,"upcomingEvents":18,"pendingBookings":2}`, 200 | No resource-specific errors |
| `GET /dashboard/activity` | Student/admin | None | `[{"id":1,"title":"Membership requested","description":"Cricket Club application","createdAt":"2026-11-05T09:00:00.000Z"}]`, 200 | No resource-specific errors |
| `GET /dashboard/upcoming` | Student/admin | None | Array of Schedule records ordered by `startsAt` ascending, 200 | No resource-specific errors |

Metrics are computed from stored records on every request. `activeClubs` counts clubs; students see their own approved memberships and pending bookings, admins see university totals/queues. `upcomingEvents` counts future schedules. Activity must be scoped to the current user for students and operational actions for admins. Upcoming shows future schedules. All three use `{success:true,data:...}`, not hard-coded frontend totals.

### Clubs

Club write body example (all six fields required for POST/PUT):

```json
{"name":"Cricket Club","sport":"Cricket","description":"Practice and compete with the university team.","coach":"Club Coach","location":"University Oval","capacity":40}
```

| Method and path | Role | Query / request | Success data / status | Additional errors |
|---|---|---|---|---|
| `GET /clubs` | Student/admin | L; e.g. `?search=cricket&sport=Cricket&page=1&limit=10&sort=name&order=asc` | Club[] in list envelope, 200 | 422 invalid filters |
| `GET /clubs/{id}` | Student/admin | `id`; no body | Club, 200 | 404 absent club |
| `POST /clubs` | Admin | Club write body above | Created Club, 201 | 422 invalid capacity/text |
| `PUT /clubs/{id}` | Admin | `id` and complete Club write body above | Updated Club, 200 | 404; 422 invalid fields |
| `DELETE /clubs/{id}` | Admin | `id`; no body | Empty 204 | 404; 409 if memberships/schedules depend on it |

Example detail: `{"success":true,"data":{"id":1,"name":"Cricket Club","sport":"Cricket","description":"Practice and compete with the university team.","coach":"Club Coach","location":"University Oval","capacity":40}}`. Return the same complete shape after create/update.

### Memberships

| Method and path | Role | Query / request body and example | Success data / status | Additional errors |
|---|---|---|---|---|
| `GET /memberships` | Admin | L, typically `?status=pending`; no body | Membership[] in list envelope, 200 | 422 invalid status |
| `POST /memberships` | Student | `clubId:integer`; `{"clubId":1}` | New Membership with `status:"pending"`, 201 | 404 club; 409 duplicate existing application; 422 clubId |
| `PATCH /memberships/{id}/status` | Admin | `status:approved|rejected`; `{"status":"approved"}` | Updated Membership, 200 | 404; 409 non-pending request/full club; 422 invalid status |
| `GET /users/me/memberships` | Student/admin | L; no body | Current user's Membership[] in list envelope, 200 | No resource-specific errors |

Example: `{"success":true,"data":{"id":1,"userId":2,"userName":"Demo Student","clubId":1,"clubName":"Cricket Club","status":"pending","createdAt":"2026-11-05T09:00:00.000Z"},"message":"Application submitted"}`. Never accept a client-supplied `userId`, role or approval status. A rejected application may be resubmitted: reuse its existing membership ID, set status back to pending and refresh createdAt rather than creating a duplicate user/club pair. The POST response is 201 for a new application and 200 for a resubmission. Approval must atomically check capacity and transition only a pending request. This contract has no membership withdrawal endpoint; do not invent a cancel UI.

### Schedules

Schedule write example (POST/PUT require every editable field):

```json
{"clubId":1,"title":"Evening training","type":"training","startsAt":"2026-12-06T10:00:00.000Z","endsAt":"2026-12-06T12:00:00.000Z","location":"University Oval"}
```

| Method and path | Role | Query / request | Success data / status | Additional errors |
|---|---|---|---|---|
| `GET /schedules` | Student/admin | L, including sport/type; no body | Schedule[] in list envelope, 200 | 422 invalid filters |
| `POST /schedules` | Admin | Schedule write body above | Created Schedule, 201 | 404 club; 422 type/date/text |
| `PUT /schedules/{id}` | Admin | `id` and complete Schedule write body | Updated Schedule, 200 | 404 schedule/club; 422 date/type/text |
| `DELETE /schedules/{id}` | Admin | `id`; no body | Empty 204 | 404 |

Response adds `id`, `clubName` and `sport` derived from the club, for example `{"success":true,"data":{"id":1,"clubId":1,"clubName":"Cricket Club","sport":"Cricket","title":"Evening training","type":"training","startsAt":"2026-12-06T10:00:00.000Z","endsAt":"2026-12-06T12:00:00.000Z","location":"University Oval"}}`.

### Tournaments and registration

Tournament write example (POST/PUT require every editable field):

```json
{"name":"Interfaculty Cricket Cup","sport":"Cricket","description":"University knockout competition.","location":"University Oval","startsAt":"2026-12-10T03:30:00.000Z","endsAt":"2026-12-12T12:30:00.000Z","registrationDeadline":"2026-12-08T18:30:00.000Z","capacity":64}
```

| Method and path | Role | Query / request | Success data / status | Additional errors |
|---|---|---|---|---|
| `GET /tournaments` | Student/admin | L, e.g. `?sport=Cricket&sort=startsAt`; no body | Tournament[] in list envelope, 200 | 422 invalid filters |
| `GET /tournaments/{id}` | Student/admin | `id`; no body | Tournament, 200 | 404 |
| `POST /tournaments` | Admin | Tournament write body above | Created Tournament, 201 | 422 dates/text/capacity |
| `PUT /tournaments/{id}` | Admin | `id` and complete Tournament write body | Updated Tournament, 200 | 404; 409 capacity below existing registrations; 422 dates/text/capacity |
| `DELETE /tournaments/{id}` | Admin | `id`; no body | Empty 204 | 404; 409 if registrations/matches depend on it |
| `POST /tournaments/{id}/registrations` | Student | `id`; no body | Registration, 201 | 404 tournament; 409 duplicate/full/deadline passed |
| `GET /users/me/tournament-registrations` | Student/admin | L; no body | Registration[] in list envelope, 200 | No resource-specific errors |

Tournament responses add `id` and computed `registrationCount`. Example registration: `{"success":true,"data":{"id":1,"userId":2,"tournamentId":1,"tournamentName":"Interfaculty Cricket Cup","createdAt":"2026-11-05T09:00:00.000Z"},"message":"Registered"}`. Check registration deadline, duplicate identity and remaining capacity atomically on the server. No registration cancellation endpoint is part of this version.

### Fixtures, match results and standings

Match write examples:

```json
{"tournamentId":1,"homeTeam":"Computing","awayTeam":"Business","startsAt":"2026-12-10T04:00:00.000Z","homeScore":null,"awayScore":null,"status":"scheduled"}
```

```json
{"tournamentId":1,"homeTeam":"Computing","awayTeam":"Business","startsAt":"2026-12-10T04:00:00.000Z","homeScore":3,"awayScore":1,"status":"completed"}
```

| Method and path | Role | Query / request | Success data / status | Additional errors |
|---|---|---|---|---|
| `GET /matches` | Student/admin | L, plus `tournamentId` when requesting one competition; no body | Match[] in list envelope, 200 | 422 invalid tournament/filter |
| `POST /matches` | Admin | Complete Match write body above | Created Match, 201 | 404 tournament; 422 teams/scores/status/date |
| `PUT /matches/{id}` | Admin | `id` and complete Match write body | Updated Match, 200 | 404 match/tournament; 422 teams/scores/status/date |
| `GET /tournaments/{id}/standings` | Student/admin | `id`; no body | Standing[], 200 | 404 tournament |

Match responses add `id`, `tournamentName` and `sport`. Example standings: `{"success":true,"data":[{"team":"Computing","played":1,"won":1,"drawn":0,"lost":0,"points":3},{"team":"Business","played":1,"won":0,"drawn":0,"lost":1,"points":0}]}`. Scheduled fixtures contribute no points. This is a generic university league table, not sport-specific tie-break rules. Match deletion is intentionally absent from the proposed endpoint set.

### Facilities, equipment and bookings

Booking request example:

```json
{"facilityId":1,"startsAt":"2026-12-06T08:30:00.000Z","endsAt":"2026-12-06T10:30:00.000Z","purpose":"Faculty team practice"}
```

| Method and path | Role | Query / request | Success data / status | Additional errors |
|---|---|---|---|---|
| `GET /facilities` | Student/admin | L, `type=facility|equipment`; no body | Facility[] in list envelope, 200 | 422 invalid type |
| `GET /bookings` | Admin | L; no body | Booking[] in list envelope, 200 | 422 invalid filters |
| `POST /bookings` | Student | Booking request body above | New Booking, `status:"pending"`, 201 | 404 resource; 409 overlapping reservation; 422 dates/duration/purpose |
| `PATCH /bookings/{id}/status` | Admin or owning student | `status:approved|rejected|cancelled`; `{"status":"approved"}` | Updated Booking, 200 | 404; 403 other student's booking or student approval; 409 invalid transition/overlap; 422 invalid status |
| `GET /users/me/bookings` | Student/admin | L; no body | Current user's Booking[] in list envelope, 200 | No resource-specific errors |

Example: `{"success":true,"data":{"id":1,"userId":2,"userName":"Demo Student","facilityId":1,"facilityName":"Indoor Court","type":"facility","startsAt":"2026-12-06T08:30:00.000Z","endsAt":"2026-12-06T10:30:00.000Z","purpose":"Faculty team practice","status":"pending","createdAt":"2026-11-05T09:00:00.000Z"}}`.

Admins may approve, reject or cancel pending/approved requests. Students may cancel their own future pending/approved requests; rejected/cancelled states are terminal. The backend must lock/check resource intervals during request and approval. Two intervals overlap when `newStart < existingEnd && newEnd > existingStart`; pending and approved reservations block, rejected/cancelled do not. Bookings reserve one resource unit. Inventory quantities and recurring bookings are outside this contract.

### Participation, reports, profile and health

| Method and path | Role | Query / request body and example | Success data / status | Additional errors |
|---|---|---|---|---|
| `GET /users/me/participation` | Student/admin | L; no body | Participation[] in list envelope, 200 | No resource-specific errors |
| `GET /reports/memberships` | Admin | L; no body | `[{"clubName":"Cricket Club","pending":2,"approved":30,"rejected":1}]` in list envelope, 200 | No resource-specific errors |
| `GET /reports/participation` | Admin | L; no body | `[{"sport":"Cricket","attended":18,"registered":24}]` in list envelope, 200 | No resource-specific errors |
| `GET /reports/tournaments` | Admin | L; no body | `[{"name":"Interfaculty Cricket Cup","registrations":24,"capacity":64}]` in list envelope, 200 | No resource-specific errors |
| `GET /reports/bookings` | Admin | L; no body | `[{"facilityName":"Indoor Court","pending":2,"approved":8,"rejected":1,"cancelled":1}]` in list envelope, 200 | No resource-specific errors |
| `GET /users/me` | Student/admin | None | User, 200 | No resource-specific errors |
| `PATCH /users/me` | Student/admin | Any of `name:string (2–80)`, `faculty:string|null`, `notifications:boolean`; `{"name":"A Student","faculty":"Computing","notifications":true}` | Complete updated User, 200 | 422 invalid/unknown fields; role/email/studentId immutable |
| `GET /system/health` | Public | None | `{"status":"ok","mode":"live","database":"connected"}`, 200 | 503 database/service unavailable; must not report healthy on failed DB probe |

Example participation response: `{"success":true,"data":[{"id":1,"userId":2,"event":"Evening training","sport":"Cricket","date":"2026-11-04T10:00:00.000Z","status":"attended"}],"pagination":{"page":1,"limit":10,"total":1,"totalPages":1}}`. Report numeric counts are nonnegative integers and never expose passwords or private authentication fields. Notification preferences are stored preferences only; no email or push-delivery service is implemented. Mock health explicitly returns `mode:"mock",database:"simulated"`; this is not evidence of PHP/MySQL connectivity.

## Backend implementation boundaries

This frontend does not implement or certify PHP endpoints. The PHP team must independently enforce input schemas, foreign keys, role/ownership, uniqueness, transactions, capacity and overlap checks. Live mode never falls back to demo records. Additional restrictions that change this contract require coordinated changes to the contract, both adapters and corresponding views/tests. See [backend integration](BACKEND_INTEGRATION.md) for deployment and security requirements.
