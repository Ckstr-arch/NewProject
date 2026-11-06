> **Standalone demo cases only.** The current PHP/MySQL live test checklist is [TEST_CHECKLIST.md](../docs/TEST_CHECKLIST.md); run `php-backend-browser.py` for live acceptance.

# Manual test cases

Run the frontend over HTTP and use an isolated browser profile with disposable mock data. Each case lists expected behavior, not an assertion that it has already been executed. Record browser/version, viewport, API mode, date and pass/fail evidence for each run. Use public demo identities from [README](../docs/README.md). Never include production cookies/passwords or private student records in screenshots.

## Authentication and navigation

| ID | Steps | Expected result |
|---|---|---|
| A01 | Open the app with clean site storage | Login is shown; no protected content flashes; Demo Mode is visible |
| A02 | Submit empty login, then malformed email, then wrong password | Native/inline validation or authentication feedback; no session |
| A03 | Login as student and reload | Student dashboard returns after session bootstrap |
| A04 | Navigate clubs → schedules → browser back → forward | Matching route/title/selected navigation each time |
| A05 | As student enter `#/reports` directly | Access denied; no report data or administrative controls |
| A06 | Logout, then enter `#/clubs` | Login shown; session does not restore after reload |
| A07 | Login as admin; open reports and each management view | Admin controls/queues/reports are available |
| A08 | Enter an unknown hash | Not-found state with a working route back |

## Clubs, membership and schedule

| ID | Steps | Expected result |
|---|---|---|
| C01 | Search a known club; choose its sport; clear filters; change sort/page | API-derived list/count changes without duplicates or stale entries |
| C02 | Admin creates a uniquely named club with capacity 2 | Dialog validates, submit locks during save, new club is visible |
| C03 | Edit that club's description/capacity, reopen detail, reload | Saved values persist, text remains escaped |
| C04 | Student applies to the new club twice | First request appears pending; duplicate is a 409 without another row |
| C05 | Admin approves request; student reloads personal memberships | Status is approved and dashboard membership count reflects it |
| C06 | Submit another pending application and reject as admin | Rejected state persists; non-pending transition is refused |
| C07 | Try deleting a seeded club with linked records | Conflict explains dependency; list is unchanged |
| C08 | Create an unreferenced club; cancel deletion; then confirm deletion | Cancel preserves row; confirmed API success removes it |
| C09 | Admin opens schedule form with end before start | Clear validation; no saved record |
| C10 | Create, edit and delete a future training schedule | Dates/location/type update and deletion waits for confirmation/API |
| C11 | Filter schedules by sport/type | Only matching records display; no-match shows an empty state |

## Tournaments and results

| ID | Steps | Expected result |
|---|---|---|
| T01 | Admin creates a tournament with deadline before start and end after start | Created tournament appears with zero registrations |
| T02 | Use invalid capacity/deadline/date fields | Labeled validation feedback; entered values retained |
| T03 | Student registers, then tries duplicate registration | First registration persists; duplicate is refused |
| T04 | Attempt registration after deadline or at capacity using disposable fixture | Conflict; registration total unchanged |
| T05 | Admin edits tournament; attempts capacity below existing registration count | Valid edits persist; invalid capacity conflicts |
| T06 | Create a scheduled fixture between two distinct teams | Fixture appears without fabricated scores |
| T07 | Enter completed score 3–1, then view tournament standings | Winning side gains 3 points; played/won/lost counts match |
| T08 | Enter a draw, then filter results by sport/tournament | One point per draw and selected competition only |
| T09 | Enter only one score or the same team twice | Validation refuses incomplete/invalid result |
| T10 | Delete a referenced tournament, then a new empty tournament | Dependency conflict preserves first; second deletes after confirmation |

## Bookings and histories

Choose future dates when executing these cases; do not reuse a date that has passed.

| ID | Steps | Expected result |
|---|---|---|
| B01 | Student chooses a facility and a valid two-hour future interval | Pending request appears in personal bookings |
| B02 | Request same resource with overlapping interval | 409 conflict, no duplicate reservation |
| B03 | Request same resource starting exactly when previous booking ends | Adjacent request is accepted |
| B04 | Submit past start, end before start, >4-hour duration, empty purpose | Inline validation and no mutation |
| B05 | Admin approves one pending request and rejects another | Status persists in queue and student history |
| B06 | Student cancels own future approved/pending request | Status becomes cancelled; interval no longer blocks |
| B07 | Directly attempt to approve as student or cancel another user's booking | API returns forbidden even when bypassing hidden UI |
| B08 | Request equipment, then filter by resource type/status | Equipment and facility types remain distinguishable |
| B09 | Inspect student participation and tournament registration history | Only current user's records; meaningful empty state if none |
| B10 | Open admin reports after approval/results mutations | Counts match current stored data, not cached frontend constants |

## Settings, reliability and accessibility

| ID | Steps | Expected result |
|---|---|---|
| U01 | Edit profile name/faculty/notifications, save and reload | Permitted fields persist; role/email/student ID are not editable |
| U02 | Switch theme, navigate, reload | Preference persists; both themes retain readable controls |
| U03 | Open health in mock mode | Clearly says simulation, not a real PHP/MySQL connection |
| U04 | Reset demo data, cancel confirmation, then confirm | Cancel preserves edits; confirm restores seed and clears demo login |
| U05 | Repeat all views at 375/768/1024/1440px and 320px | Drawer, cards, forms and contained table scroll work; no unintended page overflow |
| U06 | Open drawer/dialog by keyboard; press Escape and inspect focus | Visible focus, keyboard operation, dialog closes and restores trigger focus |
| U07 | Save profile/club text containing `<img src=x onerror=alert(1)>` | Text is escaped; no script execution or unexpected network request |
| U08 | Rapidly navigate while a request is loading | Latest route wins; old response does not replace current view |
| U09 | Slow/fail a mutation request with DevTools or a controlled service fixture | Submit disables; useful error; no optimistic permanent mutation |
| U10 | Return 422 with a field error from controlled backend | Error appears by associated field; values retained |
| U11 | Return 401/403/500/503, malformed JSON and empty 204 from controlled backend | Session expiry/forbidden/service errors or successful deletion normalized correctly |
| U12 | Trigger a request timeout and inspect Network panel | Clear timeout; no automatic repeated POST |
| U13 | Enable reduced-motion preference | Functional UI remains without unnecessary animation |

## Live integration follow-up

After the PHP team implements the contract, run these against a disposable live deployment:

1. Switch only API_MODE and API_BASE_URL; verify no demo fallback on connection failure.
2. Inspect anonymous session, login rotation, secure cookies, CSRF header and logout invalidation.
3. Try missing CSRF and another role's operations directly; verify server-side rejection.
4. Repeat C01–B10 and verify database changes and prepared-statement behavior.
5. Race capacity/booking requests from independent sessions; confirm transaction safety.
6. Stop database connectivity; `/system/health` must fail and Settings must not show connected.
7. Verify production HTTPS and exact credentialed CORS policy if cross-origin.

These tests require a real backend; simulated fetch checks cannot mark them passed.
