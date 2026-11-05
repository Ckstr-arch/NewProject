# Integration and acceptance checklist

Use this checklist for acceptance; unchecked items are procedures, not claims that a test passed. The executable API tests use Node only as optional reviewer tooling, with no npm packages or build step:

```bash
cd nsbm-sportshub-frontend
node tests/api-tests.mjs
```

The service author executed this suite during implementation: **93 checks passed** using simulated browser storage and HTTP responses. This checks mock workflows and HTTP handling, not a live PHP/MySQL backend. Current browser and service results are recorded below. Record actual commands and counts from the current run; do not infer success from generated files.

## Executed verification in this cloud task

| Check | Result | Scope |
|---|---|---|
| `node tests/api-tests.mjs` | 93 passed | Mock data/workflows/authorization/validation and simulated HTTP success/error/CSRF/timeout responses |
| `python3 tests/browser-smoke.py --browser /usr/bin/chromium` | 42 passed | Actual Chromium UI workflows, CRUD, approvals, profile/session/theme persistence, history, safe text rendering, dialogs/drawer and all nine views at 320, 375, 768, 1024 and 1440px in both themes |
| `python3 tests/live-browser-smoke.py --browser /usr/bin/chromium` | 13 passed | Actual live-adapter wiring against intercepted synthetic API responses: cookies, CSRF rotation, login/logout, 401/403/422/503, empty 204, malformed response, network failure and timeout; no mock fallback |
| All 24 JavaScript modules | Passed | Node syntax checks and relative module import target checks; actual browser routes also loaded without uncaught JavaScript errors |
| Architectural scan | Passed | All ten views communicate through api; no direct fetch/localStorage/mock imports in views |
| Documentation links | Passed | Relative Markdown file targets exist; no external-link or anchor validation claim |

Start the static server from the frontend directory before browser suites. Browser tests are optional developer tooling and require Python Playwright plus Chromium already installed; they introduce no npm/build/runtime dependency. Use `--base-url` and `--browser` to select another local server or executable. Tests create isolated browser contexts and synthetic records; the demo reset applies only to those test contexts. Tests use no real student information.

Remaining unrun checks: real PHP/MySQL integration, deployed HTTPS/CORS/cookie enforcement, other browser engines, human assistive-technology/contrast certification and real backend concurrency. The proposed PHP contract must still be implemented and agreed with the backend team. The simulated HTTP tests do not certify a server implementation.

## Architecture

- [ ] All ES modules import without console errors over HTTP.
- [ ] No view calls fetch or directly reads/mutates mock records.
- [ ] Switching only API_MODE selects the correct adapter.
- [ ] Changing only API_BASE_URL redirects live calls.
- [ ] Mock and live calls share signatures and response envelopes.
- [ ] Live failures never fall back to seeded/demo records.

## Authentication and permissions

- [ ] Mock student and admin credentials work; invalid credentials fail.
- [ ] Reload restores a simulated session; logout removes it.
- [ ] Protected content does not flash before session bootstrap.
- [ ] Anonymous protected route returns to login.
- [ ] Student direct navigation to reports is denied.
- [ ] Student controls do not include admin actions.
- [ ] API role/ownership checks reject direct unauthorized calls.
- [ ] Live 401 clears session state and redirects appropriately.
- [ ] Demo passwords/tokens are absent from persisted records.

## CRUD and workflows

- [ ] Search, sport/type/status filters, sorting and pagination change results.
- [ ] Admin creates, reads, edits and deletes a new unreferenced club.
- [ ] Dependent club/tournament deletion returns a useful conflict.
- [ ] Admin manages schedules and tournaments with valid date ranges.
- [ ] Student submits membership; admin approves/rejects pending application.
- [ ] Duplicate membership and full-club approval are rejected.
- [ ] Student tournament registration respects duplicates, deadline and capacity.
- [ ] Admin creates fixture and records result; standings update.
- [ ] Student requests a facility and equipment separately.
- [ ] Pending/approved overlaps fail; adjacent intervals succeed.
- [ ] Admin approves/rejects bookings; owners cancel eligible future bookings.
- [ ] Student participation and personal histories are correctly scoped.
- [ ] Admin reports and dashboard refresh from API records.
- [ ] Profile updates persist permitted fields; email/role/student ID remain immutable.
- [ ] Notification preference stores a setting without claiming delivery.
- [ ] Reset demo data requires confirmation and clears demo session/edits.

## Reliability and security

- [ ] 204 without a body is treated as successful deletion.
- [ ] 400/401/403/404/409/422/500/503 show appropriate UI feedback.
- [ ] 422 maps field messages to labeled inputs and preserves entered values.
- [ ] Network failure, timeout, malformed response and server HTML are normalized.
- [ ] Failed non-idempotent requests are not retried automatically.
- [ ] Invalid/missing CSRF blocks writes; refreshed tokens are used.
- [ ] Submit is disabled while saving and restored on failure.
- [ ] Untrusted labels/descriptions/error text render as text, not executable HTML.
- [ ] Rejected writes do not permanently mutate records/UI.
- [ ] localStorage unavailable/full is handled with a visible actionable error.
- [ ] Settings health labels mock simulation accurately.

## Browser layout and accessibility

- [ ] Check 375px, 768px, 1024px and 1440px widths, plus 320px for overflow.
- [ ] Check both themes for readable text, borders, controls and charts.
- [ ] Check drawer toggle, selected route, Escape and focus behavior.
- [ ] Check back/forward navigation and page title updates.
- [ ] Check all forms with keyboard only and visible focus.
- [ ] Check dialog initial focus, Escape, confirmation and focus restoration.
- [ ] Check labels, inline error associations and status announcements.
- [ ] Check tables scroll within their container without page-wide overflow.
- [ ] Check loading, empty, access-denied, not-found and API-error states.
- [ ] Check reduced-motion preference.

## Live backend acceptance — separate verification required

No PHP/MySQL backend is implemented by this frontend task. Mock or mocked-fetch tests cannot check these items:

- [ ] Real session cookie lifecycle, secure attributes and CSRF enforcement.
- [ ] Real prepared SQL, password hashing and server-side role/ownership checks.
- [ ] Database transactions under concurrent capacity/overlap contention.
- [ ] Every documented endpoint's JSON/status semantics.
- [ ] Health actually probes MySQL and fails when connectivity fails.
- [ ] Same-origin production HTTPS hosting or exact credentialed CORS setup.
- [ ] Deployed-host browser behavior and target browsers/assistive technology.

Record failed/skipped/unrun checks separately, with their reason and environment. Do not call live integration verified until these operations run against the actual backend.
