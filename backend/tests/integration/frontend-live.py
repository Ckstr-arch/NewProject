#!/usr/bin/env python3
"""Real browser acceptance against the PHP/MySQL development server.

From the repository root, start the backend with FRONTEND_API_MODE=live, migrate
and explicitly seed a dedicated DEVELOPMENT database, then run:
    python3 backend/tests/integration/frontend-live.py --browser /usr/bin/chromium

Requires optional Python Playwright and Chromium as reviewer tooling. No route
interception, synthetic HTTP responses, frontend file edits or mock reset occur.
All UI API traffic goes through the existing live adapter to the real PHP server.
This runner writes synthetic records tagged "Backend QA <unique run ID>". Records
with protected history (membership, registration and match fixtures) are retained
for inspection because the agreed API intentionally has no destructive reset or
match/registration/membership deletion endpoints. Other CRUD fixtures are removed;
the seeded student's edited profile/preferences are restored.
"""
import argparse
import json
import re
import uuid
from datetime import datetime, timedelta, timezone
from pathlib import Path

from playwright.sync_api import sync_playwright, expect

parser = argparse.ArgumentParser()
parser.add_argument('--base-url', default='http://localhost:8081')
parser.add_argument('--browser', default='/usr/bin/chromium')
args = parser.parse_args()
BASE = args.base_url.rstrip('/')
RUN = uuid.uuid4().hex[:8]
PREFIX = 'Backend QA ' + RUN
ARTIFACTS = Path('/tmp/sportshub-backend-qa')
ARTIFACTS.mkdir(parents=True, exist_ok=True)
CHECKS = []


def passed(description):
    CHECKS.append(description)
    print('PASS ' + description, flush=True)


def stamp(days, hour=12):
    local = datetime.now(timezone(timedelta(hours=5, minutes=30))) + timedelta(days=days)
    return local.replace(hour=hour, minute=0, second=0, microsecond=0).strftime('%Y-%m-%dT%H:%M')


with sync_playwright() as playwright:
    browser = playwright.chromium.launch(executable_path=args.browser, headless=True, args=['--no-sandbox'])
    context = browser.new_context(viewport={'width': 1440, 'height': 1000}, timezone_id='Asia/Colombo', reduced_motion='reduce')
    page = context.new_page()
    page.set_default_timeout(15000)
    js_errors = []
    responses = []
    page.on('pageerror', lambda error: js_errors.append(str(error)))
    page.on('response', lambda response: responses.append((response.request.method, response.url, response.status)) if '/api/v1/' in response.url else None)

    def api(method, *params):
        # Invoke the actual existing application API; its live adapter performs Fetch.
        # This does not replace transport or server responses.
        return page.evaluate("""async ({method, params}) => {
          const {api} = await import('/js/services/api.js');
          return await api[method](...params);
        }""", {'method': method, 'params': list(params)})

    def route(name, title):
        page.evaluate('(name) => location.hash = `#/${name}`', name)
        expect(page.locator('main h1')).to_have_text(title)
        expect(page.locator('main [role="alert"]:visible')).to_have_count(0)
        expect(page.get_by_text('Demo Mode', exact=False)).to_have_count(0)

    def login(role):
        page.goto(BASE + '/#/login')
        form = page.locator('#login-form')
        expect(form).to_be_visible()
        form.locator('[name="email"]').fill(role + '@example.test')
        form.locator('[name="password"]').fill('Admin123!' if role == 'admin' else 'Student123!')
        form.locator('[type="submit"]').click()
        expect(page.locator('main h1')).to_have_text('Dashboard')
        expect(page.locator('.metric-card')).to_have_count(4)
        assert api('session')['data']['user']['role'] == role
        passed(role + ' signs in through the existing live frontend')

    def logout():
        route('settings', 'Settings')
        page.locator('#settings-logout').click()
        expect(page.locator('#login-form')).to_be_visible()
        assert api('session')['data']['user'] is None

    def save_modal():
        dialog = page.locator('dialog[open]')
        dialog.locator('button[type="submit"]').click()
        expect(dialog).to_have_count(0)

    def confirm():
        page.locator('dialog[open] [data-confirm]').click()
        expect(page.locator('dialog[open]')).to_have_count(0)

    def create_club(name):
        route('clubs', 'Sports clubs')
        page.locator('#add-club').click()
        dialog = page.locator('dialog[open]')
        for key, value in {'name': name, 'sport': 'Athletics', 'description': 'Synthetic PHP/MySQL browser acceptance club.', 'coach': 'Backend QA Coach', 'location': 'Backend QA Track', 'capacity': '20'}.items():
            dialog.locator(f'[name="{key}"]').fill(value)
        save_modal()
        page.locator('#club-search').fill(name)
        expect(page.locator('.club-card')).to_have_count(1)
        expect(page.locator('.club-card h2')).to_have_text(name)
        return api('listClubs', {'search': name, 'limit': 100})['data'][0]['id']

    def create_tournament(name):
        route('tournaments', 'Tournaments')
        page.locator('#add-tournament').click()
        dialog = page.locator('dialog[open]')
        values = {'name': name, 'sport': 'Athletics', 'description': 'Synthetic PHP/MySQL browser acceptance tournament.', 'location': 'Backend QA Oval', 'capacity': '20', 'registrationDeadline': stamp(33), 'startsAt': stamp(35), 'endsAt': stamp(36)}
        for key, value in values.items():
            dialog.locator(f'[name="{key}"]').fill(value)
        save_modal()
        page.locator('#tournament-search').fill(name)
        expect(page.locator('.tournament-card')).to_have_count(1)
        expect(page.locator('.tournament-card h2')).to_have_text(name)
        return api('listTournaments', {'search': name, 'limit': 100})['data'][0]['id']

    try:
        health = context.request.get(BASE + '/api/v1/system/health')
        assert health.status == 200
        payload = health.json()
        assert payload['success'] is True
        assert payload['data'] == {'status': 'ok', 'mode': 'live', 'database': 'connected'}
        config = context.request.get(BASE + '/js/config.js').text()
        assert re.search(r"API_MODE:\s*['\"]live['\"]", config), 'Start backend server with FRONTEND_API_MODE=live.'
        passed('real PHP health probes MySQL and the served frontend selects the live adapter')

        page.goto(BASE + '/#/clubs')
        expect(page.locator('#login-form')).to_be_visible()
        expect(page.get_by_text('Demo Mode', exact=False)).to_have_count(0)
        expect(page.locator('[data-role]')).to_have_count(0)
        passed('anonymous route guard displays live login without demo credentials or protected content')
        form = page.locator('#login-form')
        form.locator('[name="email"]').fill('admin@example.test')
        form.locator('[name="password"]').fill('incorrect-public-test-password')
        form.locator('[type="submit"]').click()
        expect(form.locator('[role="alert"]')).to_contain_text(re.compile('incorrect|expired', re.I))
        passed('invalid real PHP login remains on the form with an authentication error')

        login('admin')
        page.reload()
        expect(page.locator('main h1')).to_have_text('Dashboard')
        expect(page.locator('.metric-card')).to_have_count(4)
        assert api('profile')['data']['role'] == 'admin'
        passed('PHP session cookie restores the administrator after reload')

        ephemeral_club = create_club(PREFIX + ' CRUD Club')
        page.locator('[data-edit]').click()
        page.locator('dialog[open] [name="location"]').fill('Backend QA Updated Track')
        save_modal()
        expect(page.locator('.club-card')).to_contain_text('Backend QA Updated Track')
        page.locator('[data-details]').click()
        expect(page.locator('dialog[open]')).to_contain_text('Backend QA Coach')
        page.keyboard.press('Escape')
        page.locator('[data-delete]').click()
        confirm()
        expect(page.locator('.empty-state')).to_contain_text('No clubs found')
        assert api('listClubs', {'search': PREFIX + ' CRUD Club'})['pagination']['total'] == 0
        passed('administrator club create/read/update/delete persists in real MySQL')

        club_name = PREFIX + ' Community Club'
        club_id = create_club(club_name)
        route('schedules', 'Training & schedules')
        page.locator('#add-schedule').click()
        dialog = page.locator('dialog[open]')
        dialog.locator('[name="clubId"]').select_option(str(club_id))
        dialog.locator('[name="title"]').fill(PREFIX + ' Training')
        dialog.locator('[name="location"]').fill('Backend QA Ground')
        dialog.locator('[name="startsAt"]').fill(stamp(30, 9))
        dialog.locator('[name="endsAt"]').fill(stamp(30, 10))
        save_modal()
        page.locator('#schedule-search').fill(PREFIX + ' Training')
        expect(page.locator('tbody tr')).to_have_count(1)
        page.locator('[data-edit]').click()
        page.locator('dialog[open] [name="location"]').fill('Backend QA Updated Ground')
        save_modal()
        expect(page.locator('tbody')).to_contain_text('Backend QA Updated Ground')
        page.locator('[data-delete]').click()
        confirm()
        expect(page.locator('.empty-state')).to_be_visible()
        passed('schedule create/read/update/delete uses real foreign keys and UTC date responses')

        create_tournament(PREFIX + ' Empty Cup')
        page.locator('[data-edit]').click()
        page.locator('dialog[open] [name="location"]').fill('Backend QA Updated Oval')
        save_modal()
        expect(page.locator('.tournament-card')).to_contain_text('Backend QA Updated Oval')
        page.locator('[data-details]').click()
        expect(page.locator('dialog[open]')).to_contain_text('Backend QA Updated Oval')
        page.keyboard.press('Escape')
        page.locator('[data-delete]').click()
        confirm()
        expect(page.locator('.empty-state')).to_contain_text('No tournaments found')
        passed('administrator tournament create/read/update/delete is integrated with PHP')

        tournament_name = PREFIX + ' Integration Cup'
        tournament_id = create_tournament(tournament_name)
        route('results', 'Results & standings')
        page.locator('#add-match').click()
        dialog = page.locator('dialog[open]')
        dialog.locator('[name="tournamentId"]').select_option(str(tournament_id))
        dialog.locator('[name="startsAt"]').fill(stamp(35))
        dialog.locator('[name="homeTeam"]').fill(PREFIX + ' North')
        dialog.locator('[name="awayTeam"]').fill(PREFIX + ' South')
        dialog.locator('[name="status"]').select_option('completed')
        dialog.locator('[name="homeScore"]').fill('3')
        dialog.locator('[name="awayScore"]').fill('1')
        save_modal()
        page.locator('#match-search').fill(PREFIX + ' North')
        expect(page.locator('#match-content tbody')).to_contain_text('3 — 1')
        page.locator('#match-tournament').select_option(str(tournament_id))
        expect(page.locator('#standings-content tbody')).to_contain_text(PREFIX + ' North')
        assert next(row for row in api('standings', tournament_id)['data'] if row['team'] == PREFIX + ' North')['points'] == 3
        page.locator('#match-content [data-edit]').click()
        dialog = page.locator('dialog[open]')
        dialog.locator('[name="homeScore"]').fill('2')
        dialog.locator('[name="awayScore"]').fill('2')
        save_modal()
        expect(page.locator('#match-content tbody')).to_contain_text('2 — 2')
        assert all(row['points'] == 1 for row in api('standings', tournament_id)['data'])
        passed('match result creation/editing recalculates standings from real database scores')

        route('reports', 'Reports')
        for report in ['memberships', 'participation', 'tournaments', 'bookings']:
            page.locator(f'[data-report="{report}"]').click()
            expect(page.locator('#report-content h2')).to_have_text(report.capitalize() + ' overview')
            expect(page.locator('#report-content table')).to_have_count(1)
        passed('all four administrator reports render real database aggregates')
        logout()
        login('student')
        route('reports', 'Access denied')
        expect(page.locator('main')).to_contain_text('Reports are available')
        route('clubs', 'Sports clubs')
        expect(page.locator('#add-club')).to_have_count(0)
        page.locator('#club-search').fill(club_name)
        expect(page.locator('.club-card')).to_have_count(1)
        page.locator(f'[data-join="{club_id}"]').click()
        expect(page.locator(f'[data-join="{club_id}"]')).to_have_count(0)
        route('memberships', 'My memberships')
        page.locator('#membership-filter [name="search"]').fill(club_name)
        page.locator('#membership-filter [type="submit"]').click()
        expect(page.locator('main .card').first.locator('tbody')).to_contain_text('pending')
        membership_id = next(row for row in api('myMemberships', {'limit': 100})['data'] if row['clubId'] == club_id)['id']
        passed('student role hides admin controls, denies Reports and persists a membership application')

        route('tournaments', 'Tournaments')
        page.locator('#tournament-search').fill(tournament_name)
        expect(page.locator('.tournament-card')).to_have_count(1)
        page.locator(f'[data-register="{tournament_id}"]').click()
        expect(page.locator('.tournament-card')).to_contain_text('Registered')
        route('memberships', 'My memberships')
        expect(page.locator('main')).to_contain_text(tournament_name)
        assert any(row['event'] == tournament_name for row in api('participation', {'limit': 100})['data'])
        passed('student tournament registration appears in real personal participation history')

        route('bookings', 'My bookings')
        page.locator('#new-booking').click()
        dialog = page.locator('dialog[open]')
        resources = api('listFacilities', {'limit': 100})['data']
        resource_id = next(row['id'] for row in resources if row['type'] == 'facility')
        booking_day = 50 + int(RUN, 16) % 180
        dialog.locator('[name="facilityId"]').select_option(str(resource_id))
        dialog.locator('[name="purpose"]').fill(PREFIX + ' Facility Practice')
        dialog.locator('[name="startsAt"]').fill(stamp(booking_day, 15))
        dialog.locator('[name="endsAt"]').fill(stamp(booking_day, 16))
        save_modal()
        page.locator('#booking-filter [name="search"]').fill(PREFIX + ' Facility Practice')
        page.locator('#booking-filter [type="submit"]').click()
        expect(page.locator('tbody')).to_contain_text(PREFIX + ' Facility Practice')
        booking_id = next(row['id'] for row in api('myBookings', {'limit': 100})['data'] if row['purpose'] == PREFIX + ' Facility Practice')
        page.reload()
        expect(page.locator('main h1')).to_have_text('My bookings')
        assert next(row for row in api('myBookings', {'limit': 100})['data'] if row['id'] == booking_id)['status'] == 'pending'
        passed('student facility request survives reload with real MySQL persistence')

        # Equipment is a separate selectable resource type, using the same real API.
        page.locator('#new-booking').click()
        dialog = page.locator('dialog[open]')
        equipment_id = next(row['id'] for row in resources if row['type'] == 'equipment')
        dialog.locator('[name="facilityId"]').select_option(str(equipment_id))
        dialog.locator('[name="purpose"]').fill(PREFIX + ' Equipment Practice')
        dialog.locator('[name="startsAt"]').fill(stamp(booking_day + 1, 15))
        dialog.locator('[name="endsAt"]').fill(stamp(booking_day + 1, 16))
        save_modal()
        page.locator('#booking-filter [name="search"]').fill(PREFIX + ' Equipment Practice')
        page.locator('#booking-filter [type="submit"]').click()
        expect(page.locator('tbody')).to_contain_text('equipment')
        equipment_booking = next(row for row in api('myBookings', {'limit': 100})['data'] if row['purpose'] == PREFIX + ' Equipment Practice')
        assert equipment_booking['type'] == 'equipment'
        page.locator(f'[data-id="{equipment_booking["id"]}"][data-status="cancelled"]').click()
        confirm()
        expect(page.locator('tbody')).to_contain_text('cancelled')
        passed('equipment requests remain distinguishable from facilities and support own cancellation')

        route('settings', 'Settings')
        original_profile = api('profile')['data']
        page.locator('#profile-form [name="name"]').fill(PREFIX + ' Student')
        page.locator('#profile-form [name="faculty"]').fill('Backend QA Computing')
        page.locator('#profile-form [type="submit"]').click()
        expect(page.locator('.toast-region')).to_contain_text('Profile updated')
        page.locator('button[data-theme="dark"]').click()
        page.reload()
        expect(page.locator('#profile-form [name="name"]')).to_have_value(PREFIX + ' Student')
        expect(page.locator('html')).to_have_attribute('data-theme', 'dark')
        expect(page.locator('#connection-result')).to_contain_text('Backend reports healthy')
        expect(page.locator('#connection-result')).to_contain_text('connected')
        assert api('health')['data']['database'] == 'connected'
        page.locator('#profile-form [name="name"]').fill(original_profile['name'])
        page.locator('#profile-form [name="faculty"]').fill(original_profile['faculty'] or '')
        page.locator('#profile-form [type="submit"]').click()
        expect(page.locator('#profile-form [type="submit"]')).to_be_enabled()
        assert api('profile')['data']['name'] == original_profile['name']
        passed('profile persists in MySQL, theme survives reload and Settings reports actual database health')

        logout()
        login('admin')
        route('memberships', 'Membership requests')
        page.locator('#membership-filter [name="search"]').fill(club_name)
        page.locator('#membership-filter [type="submit"]').click()
        page.locator(f'[data-id="{membership_id}"][data-status="approved"]').click()
        confirm()
        expect(page.locator('tbody')).to_contain_text('approved')
        assert next(row for row in api('listMemberships', {'limit': 100})['data'] if row['id'] == membership_id)['status'] == 'approved'
        route('bookings', 'Booking requests')
        page.locator('#booking-filter [name="search"]').fill(PREFIX + ' Facility Practice')
        page.locator('#booking-filter [type="submit"]').click()
        page.locator(f'[data-id="{booking_id}"][data-status="approved"]').click()
        confirm()
        expect(page.locator('tbody')).to_contain_text('approved')
        assert next(row for row in api('listBookings', {'limit': 100})['data'] if row['id'] == booking_id)['status'] == 'approved'
        passed('administrator approves the student membership and facility booking through the real API')

        titles = {'dashboard': 'Dashboard', 'clubs': 'Sports clubs', 'memberships': 'Membership requests', 'schedules': 'Training & schedules', 'tournaments': 'Tournaments', 'results': 'Results & standings', 'bookings': 'Booking requests', 'reports': 'Reports', 'settings': 'Settings'}
        for name, title in titles.items():
            route(name, title)
        page.set_viewport_size({'width': 375, 'height': 900})
        route('dashboard', 'Dashboard')
        assert page.evaluate('document.documentElement.scrollWidth <= innerWidth + 1')
        page.screenshot(path=str(ARTIFACTS / 'live-dashboard-mobile.png'), full_page=True)
        page.set_viewport_size({'width': 1440, 'height': 1000})
        route('dashboard', 'Dashboard')
        page.screenshot(path=str(ARTIFACTS / 'live-dashboard-desktop.png'), full_page=True)
        passed('all nine protected views render in live mode and the dashboard remains responsive')

        logout()
        login('student')
        route('bookings', 'My bookings')
        page.locator('#booking-filter [name="search"]').fill(PREFIX + ' Facility Practice')
        page.locator('#booking-filter [type="submit"]').click()
        page.locator(f'[data-id="{booking_id}"][data-status="cancelled"]').click()
        confirm()
        expect(page.locator('tbody')).to_contain_text('cancelled')
        assert next(row for row in api('myBookings', {'limit': 100})['data'] if row['id'] == booking_id)['status'] == 'cancelled'
        passed('own future booking cancellation persists and releases the reservation')
        logout()
        page.goto(BASE + '/#/settings')
        expect(page.locator('#login-form')).to_be_visible()
        passed('logout invalidates the real PHP session and restores anonymous route guards')

        assert page.evaluate("localStorage.getItem('nsbm-sportshub-demo-v1')") is None
        assert responses and any('/api/v1/bookings' in url for _, url, _ in responses)
        assert not [response for response in responses if response[2] >= 500], responses
        assert not js_errors, js_errors
        passed('no mock storage, HTTP server errors or uncaught JavaScript errors occurred')
        print(json.dumps({'passed': len(CHECKS), 'apiResponses': len(responses), 'runId': RUN, 'retainedSyntheticClubId': club_id, 'retainedSyntheticTournamentId': tournament_id, 'checks': CHECKS}, indent=2))
    except Exception:
        page.screenshot(path=str(ARTIFACTS / 'failure.png'), full_page=True)
        raise
    finally:
        context.close()
        browser.close()
