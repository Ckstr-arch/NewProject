# Standalone demo service reference

This document applies **only** to `API_MODE: 'mock'`. It describes the original
browser-local simulator implemented in `js/services/mock-api.js`. It does not
define PHP routes, required backend tables, authentication, or deployment.
The current live API is documented in [API_CONTRACT.md](API_CONTRACT.md).

Demo accounts are student/admin with example.test emails, shown by the login
screen. Mock authentication is intentionally a simulator: anyone can edit their
own browser storage. The mode is visibly labeled Demo Mode and never certifies
server security or MySQL persistence.

`js/services/endpoints.js` retains the demo endpoint map for these simulated
operations:

- Anonymous/authenticated session, login, logout, dashboard metrics/activity.
- Clubs, membership applications, and administrator membership review.
- Schedules, tournaments, student registrations, fixtures, and standings.
- Facilities/equipment, booking requests/review, and participation histories.
- Administrator reports, profile preferences, and confirmed local demo reset.

Demo records use camelCase fields (for example `clubId`, `startsAt`, `capacity`,
and `facilityId`) and `{success,data,pagination}` simulator envelopes. These
models intentionally differ from the current snake_case PHP API and its
`{data:{items,page,limit,total,pages}}` collections. Only the demo's original
view modules consume them; live views live in `js/views/live/`.

The simulator seeds records relative to initialization and stores edits in
localStorage under `CONFIG.MOCK_STORAGE_KEY`. The reset operation affects only
this browser origin. No demo operation reaches MySQL or falls back from a failed
live HTTP request.

Optional Python browser acceptance for this explicit simulator is in
`tests/browser-smoke.py`; it intercepts config in its isolated browser context
to select mock mode without changing the checkout's live default.
