# Frontend guide

The app uses browser ES modules, not a framework or build step. Read one view and trace its API call through the service layer to understand the architecture. Serve it over HTTP as described in [README.md](README.md).

## Initialization and routing

`index.html` loads `js/app.js` as a module. Initialization applies the stored theme, calls `api.session()` and restores the user before starting protected navigation. The shell does not show protected content while this request is pending. Live bootstrap errors remain visible; the app does not substitute a mock session.

`router.js` maps `#/dashboard`, `#/clubs` and the other hashes to dynamically imported view modules. Browser back/forward changes the hash and renders the route. An anonymous user is sent to login. Reports are admin-only; direct navigation by a student displays access denied. Unknown hashes get a useful not-found view. Route changes update the title, selected navigation and focus, and avoid letting a slow earlier render replace the latest route.

A view exports an asynchronous function:

```javascript
export async function render(container) {
  const response = await api.listClubs({ page: 1, limit: 10 });
  // Render escaped data and attach listeners to elements inside container.
}
```

A view may return a cleanup function for listeners/timers it owns. Keep temporary filters and form values local to that view.

## Shared state

`store.js` holds current user, route, theme, notifications and shared loading state. `store.get()` returns a shallow snapshot, `store.set({...})` updates shared fields, and `store.subscribe(listener)` returns an unsubscribe function. Theme changes go through `store.setTheme('dark')` or `'light'`, which updates the document theme and stores the preference. Production session secrets do not belong in this store or localStorage.

```javascript
const user = store.get().user;
if (user.role === 'admin') {
  // Show an administrative control; PHP must still authorize its request.
}
```

## One API interface

Views import `api` from `services/api.js`. They never import mock records or call `fetch` directly. `endpoints.js` maps each public method to one HTTP method and relative path. `api.js` fills ID parameters and sends the request to the adapter selected by `CONFIG.API_MODE`.

```javascript
const { data, pagination } = await api.listClubs({
  search: 'Cricket', page: 1, limit: 10, sort: 'name', order: 'asc'
});
const saved = await api.updateClub(1, editableClubFields);
await api.deleteClub(1);
```

List methods take an optional query object, creates take a body, updates/status methods take `(id, body)`, and detail/delete/standings/registration methods take an ID. Read the endpoint map and contract before calling a method. Refresh affected data after successful writes; do not optimistically delete records before confirmation from the API.

### Mock adapter

`mock-api.js` simulates latency, stores demo records using the configured localStorage key and applies role/validation/workflow rules. Seeded IDs are stable and dates are relative to the initial seed. Normal navigation reads those records through API requests. Settings can call `api.resetDemo()` after confirmation; reset clears demo records and simulated login so the next session starts from seed data. This operation is unavailable in live mode.

Mock sessions are a user-experience simulator; browser data can be edited by anyone using the browser. Never use mock authorization as evidence of server security. Demo passwords are matched in code only, not persisted with records.

### Live HTTP adapter

`http.js` joins the configured base URL with relative paths, serializes JSON, includes cookies and the in-memory CSRF token, and sets a timeout with AbortController. It normalizes empty 204 responses, HTTP errors, invalid responses, network failures and timeouts. HTTP 401 emits `sportshub:unauthorized`; the app clears its user and returns to login. HTTP 403 remains a clear forbidden/CSRF error. Errors carry `status` and an `errors` field map for forms. Writes are never automatically retried.

The CSRF token comes from session/login/logout response data. The login view refreshes `api.session()` before each explicit sign-in attempt, so a corrected password can be submitted after a prior 401 without retrying the failed POST automatically. A rejected token requires a fresh session handshake/reload and an explicit user retry. See [backend integration](BACKEND_INTEGRATION.md).

## Rendering and reusable UI

- `components/navbar.js` renders the responsive shell and role-aware navigation.
- `components/modal.js` uses native dialogs for forms and confirmations, including Escape and focus restoration.
- `components/toast.js` announces operation feedback.
- `components/table.js` supplies escaped table cells, pagination, shared fields and form submission helpers.
- `utils/format.js` formats/escapes display values; `utils/validation.js` provides common input checks.

Use textContent or the escaping helper for API-provided text. Only compose HTML from trusted static templates and escaped values. Never insert descriptions, names or error messages into HTML without escaping them. Use associated labels, visible focus and semantic buttons/links; do not use a clickable `<div>` for an action.

## Add an endpoint

1. Agree its role, body, response schema, errors and status codes with the backend team in `API_CONTRACT.md`.
2. Add one mapping in `services/endpoints.js`, for example `getClub: ['GET', '/clubs/:id']`.
3. Implement the same request route in the mock adapter. The live adapter already uses the map; the PHP backend must implement the actual endpoint.
4. Call `api.getClub(id)` from the view. Do not put its URL in the view.
5. Add service tests for role checks, success, invalid input and failure cases, then exercise the UI.

The flat method signatures intentionally cover the current resource families. If a future endpoint requires unusual upload/download behavior, define its interface explicitly rather than bypassing the centralized service.

## Add a view

1. Add `js/views/example.js` with `export async function render(container)`.
2. Add its dynamic import to the `routes` map in `router.js`.
3. Add a navigation item if needed, with its allowed role.
4. Add the same role restriction in the router and enforce it in PHP.
5. Load through the API, render loading/error/empty states, and bind accessible controls.
6. Test direct navigation, reload, back/forward and a slow response during navigation.

## Forms and validation

Use shared `field`/`bindForm` helpers where practical. Convert FormData strings to the numeric/boolean/ISO values specified by the contract before submission. Native required/type/min/max constraints improve feedback; cross-field checks must verify time ordering, deadline and duration. Show field messages from a 422 error next to the corresponding input. Disable submit while saving, keep values on failure and refresh only after a successful API response.

A confirmation dialog precedes deletion/reset. An API error must leave the record visible. Toast feedback complements inline errors; it does not replace a labeled field error. The server remains authoritative for role, ownership, duplicate/capacity checks and conflicts.

## Debugging

| Symptom | Check |
|---|---|
| Modules fail to load | Use HTTP; inspect relative imports, filename case and JavaScript MIME type |
| Only live mode fails | Verify API_BASE_URL and inspect actual network status/JSON; do not enable mock fallback |
| Login succeeds but reload logs out | Inspect cookie domain, SameSite/Secure, credentials and `/auth/session` |
| A write returns 403 | Check role/ownership and CSRF session handshake; reload and retry manually |
| Form returns 422 | Compare sent types/field names against the contract and inspect normalized field errors |
| Empty DELETE breaks | Verify it is truly 204 and that PHP emitted no stray text |
| Old mock records conflict | Confirm seed/schema version and use Settings reset only if local edits are disposable |
| Wrong counts after mutation | Refresh API-derived lists/metrics; do not patch independent hard-coded totals |
| Data appears as executable HTML | Fix escaping immediately and add an XSS rendering regression test |

Browser DevTools can inspect network requests and storage. Do not paste session cookies, private user data or credentials into shared issue reports.
