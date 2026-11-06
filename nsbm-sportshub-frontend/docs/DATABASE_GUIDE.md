# Current MySQL schema

The executable schema and seed data are
[`backend/database/schema.sql`](../../backend/database/schema.sql) and
[`backend/database/seed.sql`](../../backend/database/seed.sql). Import them into
MySQL8+ as documented by [`backend/README.md`](../../backend/README.md).

The core tables are `users`, `clubs`, `schedules`, `tournaments`, and `bookings`.
`bookings.booked_by` references `users.id`, and `clubs.manager_id` optionally
references a club manager. A controlled `facilities` table supplies six facility
rows; booking transactions lock a facility row before checking overlapping
intervals. `login_attempts` supports login throttling.

Tables use InnoDB, utf8mb4/utf8mb4_unicode_ci, foreign keys, useful indexes,
constraints, and creation/update timestamps. API payloads use the same
snake_case resource fields. Password hashes and database configuration never
belong in frontend code or API user responses.

This backend specification does not implement memberships, tournament
registrations, match/standing reports, or participation tables. Those records
exist only in the standalone browser demo. Its simulated models do not prescribe
additional MySQL tables or PHP endpoints.

Booking writes check `existing.start_time < requested.end_time` and
`existing.end_time > requested.start_time` for the same facility/date, excluding
cancelled rows and excluding the current ID during update. The facility lock
serializes concurrent checks/writes; adjacent time intervals remain valid.
