# Frontend guide

The application uses browser ES modules and requires no build tool. `index.html`
loads `js/app.js`, which restores a bearer session and starts hash navigation.
`store.js` holds the current user, server permissions, theme, and route. Theme
preference persists in localStorage; JWTs use tab-scoped sessionStorage.

## Request flow

A live view calls the centralized `api` service, which validates resource IDs,
selects `live-endpoints.js`, and calls `http.js`. The transport sends JSON and
`Authorization: Bearer TOKEN`; it uses no session cookies or CSRF handshake.
Nested backend error details become field errors. A 401 clears authentication,
while network/server failures remain visible without substituting demo data.

```javascript
const { data } = await api.listClubs({page: 1, limit: 10, search: 'Cricket'});
// data.items is the page, data.pages is the total page count.
await api.updateClub(id, {
  name: 'Cricket Club', sport: 'Cricket', president: 'Student Name',
  member_count: 20, status: 'active', description: 'University cricket'
});
```

`router.js` uses `views/live/dashboard.js` and `views/live/resources.js` in live
mode. The latter shares loading, search/filter/sort/pagination, CRUD dialogs, and
safe rendering across clubs, schedules, tournaments, and bookings. It converts
numeric form values before requests and waits for successful API responses
before refreshing data. Date and time fields use the API's separate local
calendar/time strings, without converting them to UTC timestamps.

`views/live/permissions.js` checks server-returned permissions, including wildcard
permissions. Club managers get controls for their assigned clubs. Students get
booking creation/cancellation controls; administrative edits require permission.
PHP independently enforces every operation. Never derive authorization from a
hidden button or trust a role sent by the browser.

## Shared UI and safe rendering

`components/navbar.js` supplies responsive navigation and logout; `modal.js`
provides native dialogs, confirmations, Escape, and focus restoration. `table.js`
provides fields, field errors, safe table cells, and pagination. Always escape
API values or use textContent. Never interpolate raw descriptions, names, or
error messages into HTML.

## Standalone demo

`API_MODE: 'mock'` explicitly selects the old `endpoints.js` and `mock-api.js`
models and original view modules. These have different demo roles and camelCase
models. They do not define the PHP API. The historical demo-only contract is in
[API_CONTRACT.md](API_CONTRACT.md). The current live contract is documented in
[PHP_BACKEND_INTEGRATION.md](PHP_BACKEND_INTEGRATION.md) and the backend README.

## Extending a live endpoint

1. Define its payload, response, and server authorization first.
2. Add its method/path to `live-endpoints.js`.
3. Call the centralized service from a live view.
4. Escape returned data, show structured errors, and test persisted behavior.

The frontend must never receive MySQL credentials or construct SQL.
