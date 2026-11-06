#!/usr/bin/env python3
"""Optional browser acceptance runner. No application build/dependency installation.

Start `python3 -m http.server 8080` from the frontend root, then run with a
Python interpreter containing Playwright and a Chromium executable:
  python3 tests/browser-smoke.py --browser /usr/bin/chromium
Uses isolated browser storage; resets ONLY this browser's demo workspace.
"""
import argparse
import json
import re
from pathlib import Path
from datetime import datetime, timedelta
from playwright.sync_api import sync_playwright, expect

parser = argparse.ArgumentParser()
parser.add_argument('--base-url', default='http://127.0.0.1:8080')
parser.add_argument('--browser', default='/usr/bin/chromium')
args = parser.parse_args()
BASE = args.base_url.rstrip('/')
CHECKS = []


def passed(description):
    CHECKS.append(description)
    print('PASS', description, flush=True)


def stamp(days, hour=12):
    return (datetime.now() + timedelta(days=days)).replace(hour=hour, minute=0, second=0, microsecond=0).strftime('%Y-%m-%dT%H:%M')


with sync_playwright() as p:
    browser = p.chromium.launch(executable_path=args.browser, headless=True, args=['--no-sandbox'])
    context = browser.new_context(viewport={'width': 1440, 'height': 1000}, reduced_motion='reduce')
    frontend_root = Path(__file__).resolve().parents[1]
    demo_config = (frontend_root / 'js/config.js').read_text().replace("API_MODE: 'live'", "API_MODE: 'mock'")
    context.route('**/js/config.js', lambda route: route.fulfill(status=200, content_type='application/javascript', body=demo_config))
    page = context.new_page()
    failures = []
    page.on('pageerror', lambda error: failures.append(str(error)))

    def api(method, *params):
        return page.evaluate("""async ({method, params}) => {
          const {api}=await import('/js/services/api.js');
          return await api[method](...params);
        }""", {'method': method, 'params': list(params)})

    def route(name, title=None):
        page.evaluate('(name) => location.hash = `#/${name}`', name)
        if title:
            expect(page.locator('main h1')).to_have_text(title)
        expect(page.locator('main [role="alert"]')).to_have_count(0)

    def login(role):
        page.goto(BASE + '/#/login')
        form = page.locator('#login-form')
        expect(form).to_be_visible()
        form.locator('input[type="email"]').fill(role + '@example.test')
        form.locator('input[type="password"]').fill('Admin123!' if role == 'admin' else 'Student123!')
        form.locator('button[type="submit"]').click()
        expect(page).to_have_url(BASE + '/#/dashboard')
        expect(page.locator('main h1')).to_have_text('Dashboard')
        expect(page.locator('.metric-card')).to_have_count(4)
        passed(role + ' can sign in through the form')

    def logout():
        route('settings', 'Settings')
        page.locator('#settings-logout').click()
        expect(page.locator('#login-form')).to_be_visible()

    def save_modal():
        dialog = page.locator('dialog[open]')
        dialog.locator('button[type="submit"]').click()
        expect(dialog).to_have_count(0)

    def confirm():
        page.locator('dialog[open] [data-confirm]').click()
        expect(page.locator('dialog[open]')).to_have_count(0)

    page.goto(BASE + '/#/clubs')
    expect(page.locator('#login-form')).to_be_visible()
    passed('anonymous protected route redirects to login before rendering content')
    expect(page.get_by_text('Demo Mode', exact=False).first).to_be_visible()
    passed('demo mode is clearly identified')

    # Validate errors using a real failed submission.
    form = page.locator('#login-form')
    form.locator('input[type="email"]').fill('admin@example.test')
    form.locator('input[type="password"]').fill('wrong-password')
    form.locator('button[type="submit"]').click()
    expect(form.locator('[role="alert"]')).to_be_visible()
    passed('incorrect credentials display an authentication error')
    login('admin')
    page.reload()
    expect(page.locator('main h1')).to_have_text('Dashboard')
    expect(page.locator('.metric-card')).to_have_count(4)
    expect(page).to_have_url(BASE + '/#/dashboard')
    passed('admin session is restored after reload')

    # Browser history and document titles must follow native hash navigation.
    route('clubs', 'Sports clubs')
    expect(page).to_have_title(re.compile(r'^Clubs · NSBM SportsHub$'))
    route('schedules', 'Training & schedules')
    expect(page).to_have_title(re.compile(r'^Schedules · NSBM SportsHub$'))
    page.go_back()
    expect(page.locator('main h1')).to_have_text('Sports clubs')
    expect(page).to_have_title(re.compile(r'^Clubs · NSBM SportsHub$'))
    expect(page.locator('a[data-route="clubs"]')).to_have_attribute('aria-current','page')
    page.go_forward()
    expect(page.locator('main h1')).to_have_text('Training & schedules')
    expect(page).to_have_title(re.compile(r'^Schedules · NSBM SportsHub$'))
    passed('browser back/forward restores views, active navigation and page titles')
    before_skip = page.url
    page.locator('.skip-link').focus()
    page.locator('.skip-link').click()
    expect(page.locator('main')).to_be_focused()
    assert page.url == before_skip
    passed('skip link focuses main content without changing the route hash')

    # Exercise admin create/read/update/delete entirely through visible forms.
    route('clubs', 'Sports clubs')
    page.locator('#add-club').click()
    dialog = page.locator('dialog[open]')
    for name, value in {'name':'QA Campus Runners','sport':'Athletics','description':'A synthetic running club for acceptance checks.','coach':'QA Coach','location':'QA Track','capacity':'35'}.items():
        dialog.locator(f'[name="{name}"]').fill(value)
    save_modal()
    page.locator('#club-search').fill('QA Campus Runners')
    expect(page.locator('.club-card')).to_have_count(1)
    expect(page.locator('.club-card h2')).to_have_text('QA Campus Runners')
    passed('admin creates a club and API-backed search finds it')
    page.locator('[data-edit]').click()
    dialog = page.locator('dialog[open]')
    expect(dialog.locator('[name="capacity"]')).to_have_value('35')
    dialog.locator('[name="name"]').fill('QA Campus Runners Updated')
    save_modal()
    expect(page.locator('.club-card h2')).to_have_text('QA Campus Runners Updated')
    passed('club edit prepopulates fields and persists changes')
    page.locator('[data-details]').click()
    expect(page.locator('dialog[open]')).to_contain_text('QA Coach')
    page.keyboard.press('Escape')
    expect(page.locator('dialog[open]')).to_have_count(0)
    expect(page.locator('[data-details]')).to_be_focused()
    passed('club details dialog supports Escape and restores focus')
    page.locator('[data-delete]').click()
    page.locator('[data-cancel]').click()
    expect(page.locator('.club-card')).to_have_count(1)
    page.locator('[data-delete]').click()
    confirm()
    expect(page.locator('.empty-state')).to_contain_text('No clubs found')
    passed('delete cancellation preserves data and confirmation removes the club')
    page.locator('#club-search').fill('')
    expect(page.locator('.club-card')).to_have_count(6)
    first_club = page.locator('.club-card h2').first.inner_text()
    page.locator('[data-page="2"]').click()
    expect(page.locator('.club-card h2').first).not_to_have_text(first_club)
    passed('club pagination changes the visible records')
    page.locator('#club-sport').select_option(label='Cricket')
    expect(page.locator('.club-card')).to_have_count(1)
    expect(page.locator('.sport-chip').first).to_have_text('Cricket')
    passed('club sport filter changes API-backed results')

    # Create untrusted strings through the same UI used for normal club records.
    # The mock must preserve plain text; rendering must never interpret it as HTML.
    page.locator('#add-club').click()
    dialog = page.locator('dialog[open]')
    xss_name = 'QA XSS <img src=x onerror=window.__xss=true>'
    xss_description = '<img src=x onerror=window.__xss=true> Plain description'
    for name, value in {'name':xss_name,'sport':'Athletics','description':xss_description,'coach':'QA Coach','location':'QA Track','capacity':'10'}.items():
        dialog.locator(f'[name="{name}"]').fill(value)
    save_modal()
    page.locator('#club-search').fill('QA XSS')
    page.locator('#club-sport').select_option('')
    expect(page.locator('.club-card')).to_have_count(1)
    expect(page.locator('.club-card h2')).to_have_text(xss_name)
    expect(page.locator('.club-description')).to_have_text(xss_description)
    expect(page.locator('.club-card img')).to_have_count(0)
    assert page.evaluate('window.__xss === true') is False
    xss_record = api('listClubs', {'search':'QA XSS'})['data'][0]
    assert isinstance(xss_record['id'],int) and xss_record['id'] > 0
    page.locator('[data-details]').click()
    expect(page.locator('dialog[open] h2')).to_have_text(xss_name)
    expect(page.locator('dialog[open]')).to_contain_text(xss_description)
    expect(page.locator('dialog[open] img')).to_have_count(0)
    assert page.evaluate('window.__xss === true') is False
    page.keyboard.press('Escape')
    page.locator('[data-delete]').click()
    confirm()
    expect(page.locator('.empty-state')).to_contain_text('No clubs found')
    passed('untrusted club names and descriptions render as plain text with ordinary numeric IDs')

    route('schedules', 'Training & schedules')
    page.locator('#add-schedule').click()
    dialog = page.locator('dialog[open]')
    dialog.locator('[name="title"]').fill('QA Morning Session')
    dialog.locator('[name="location"]').fill('QA Training Ground')
    dialog.locator('[name="startsAt"]').fill(stamp(8, 9))
    dialog.locator('[name="endsAt"]').fill(stamp(8, 10))
    save_modal()
    page.locator('#schedule-search').fill('QA Morning Session')
    expect(page.locator('tbody tr')).to_have_count(1)
    page.locator('[data-edit]').click()
    page.locator('dialog[open] [name="title"]').fill('QA Evening Session')
    save_modal()
    page.locator('#schedule-search').fill('QA Evening Session')
    expect(page.locator('tbody')).to_contain_text('QA Evening Session')
    page.locator('[data-delete]').click()
    confirm()
    expect(page.locator('.empty-state')).to_be_visible()
    passed('schedule create/update/delete works through the UI')

    route('tournaments', 'Tournaments')
    page.locator('#add-tournament').click()
    dialog = page.locator('dialog[open]')
    for name, value in {'name':'QA Friendly Cup','sport':'Cricket','description':'Synthetic acceptance tournament.','location':'QA Oval','capacity':'20','registrationDeadline':stamp(12),'startsAt':stamp(14),'endsAt':stamp(15)}.items():
        dialog.locator(f'[name="{name}"]').fill(value)
    save_modal()
    page.locator('#tournament-search').fill('QA Friendly Cup')
    expect(page.locator('.tournament-card')).to_have_count(1)
    page.locator('[data-edit]').click()
    page.locator('dialog[open] [name="location"]').fill('QA Updated Oval')
    save_modal()
    expect(page.locator('.tournament-card')).to_contain_text('QA Updated Oval')
    passed('tournament create and update works through the UI')
    tournament_id = api('listTournaments', {'search':'QA Friendly Cup'})['data'][0]['id']

    route('results', 'Results & standings')
    page.locator('#add-match').click()
    dialog = page.locator('dialog[open]')
    dialog.locator('[name="tournamentId"]').select_option(str(tournament_id))
    dialog.locator('[name="startsAt"]').fill(stamp(14))
    dialog.locator('[name="homeTeam"]').fill('QA North')
    dialog.locator('[name="awayTeam"]').fill('QA South')
    dialog.locator('[name="status"]').select_option('completed')
    dialog.locator('[name="homeScore"]').fill('3')
    dialog.locator('[name="awayScore"]').fill('1')
    save_modal()
    page.locator('#match-search').fill('QA North')
    expect(page.locator('#match-content tbody')).to_contain_text('3 — 1')
    page.locator('#match-tournament').select_option(str(tournament_id))
    expect(page.locator('#standings-content tbody')).to_contain_text('QA North')
    standings = api('standings', tournament_id)['data']
    assert next(row for row in standings if row['team']=='QA North')['points']==3
    page.locator('#match-content [data-edit]').click()
    dialog = page.locator('dialog[open]')
    dialog.locator('[name="homeScore"]').fill('2')
    dialog.locator('[name="awayScore"]').fill('2')
    save_modal()
    expect(page.locator('#match-content tbody')).to_contain_text('2 — 2')
    passed('admin records and edits results; standings use persisted match scores')

    route('reports', 'Reports')
    for report in ['memberships','participation','tournaments','bookings']:
        page.locator(f'[data-report="{report}"]').click()
        expect(page.locator('#report-content h2')).to_have_text(report.capitalize() + ' overview')
        expect(page.locator('#report-content table')).to_have_count(1)
    passed('admin reports render API data for all four report categories')
    logout()
    login('student')
    route('reports', 'Access denied')
    expect(page.locator('main')).to_contain_text('Reports are available')
    passed('student cannot access admin reports by direct hash URL')

    route('clubs', 'Sports clubs')
    expect(page.locator('#add-club')).to_have_count(0)
    expect(page.locator('[data-edit]')).to_have_count(0)
    passed('student interface hides administrative controls')
    join = page.locator('[data-join]').first
    club_id = int(join.get_attribute('data-join'))
    join.click()
    expect(page.locator(f'[data-join="{club_id}"]')).to_have_count(0)
    route('memberships', 'My memberships')
    mine = api('myMemberships', {'limit':100})['data']
    membership_id = next(row for row in mine if row['clubId']==club_id)['id']
    expect(page.locator('main')).to_contain_text('Participation history')
    passed('student can apply to a club and view membership and participation history')

    route('tournaments', 'Tournaments')
    page.locator('#tournament-search').fill('QA Friendly Cup')
    expect(page.locator('.tournament-card')).to_have_count(1)
    page.locator(f'[data-register="{tournament_id}"]').click()
    expect(page.locator('.tournament-card')).to_contain_text('Registered')
    passed('student tournament registration persists and updates the card')

    route('bookings', 'My bookings')
    page.locator('#new-booking').click()
    dialog = page.locator('dialog[open]')
    option = dialog.locator('[name="facilityId"] option').nth(1).get_attribute('value')
    dialog.locator('[name="facilityId"]').select_option(option)
    dialog.locator('[name="purpose"]').fill('QA Student Practice')
    dialog.locator('[name="startsAt"]').fill(stamp(20, 15))
    dialog.locator('[name="endsAt"]').fill(stamp(20, 16))
    save_modal()
    page.locator('#booking-filter input[name="search"]').fill('QA Student Practice')
    page.locator('#booking-filter button[type="submit"]').click()
    expect(page.locator('tbody')).to_contain_text('QA Student Practice')
    booking_id = next(row for row in api('myBookings', {'limit':100})['data'] if row['purpose']=='QA Student Practice')['id']
    passed('student booking request is created and visible in booking history')

    # Profile and theme persistence use actual UI changes.
    route('settings', 'Settings')
    page.locator('#profile-form [name="name"]').fill('QA Student Profile')
    page.locator('#profile-form [name="faculty"]').fill('School of Computing')
    page.locator('#profile-form button[type="submit"]').click()
    expect(page.locator('.toast-region')).to_contain_text('Profile updated')
    page.locator('button[data-theme="dark"]').click()
    expect(page.locator('html')).to_have_attribute('data-theme','dark')
    page.reload()
    expect(page.locator('#profile-form [name="name"]')).to_have_value('QA Student Profile')
    expect(page.locator('html')).to_have_attribute('data-theme','dark')
    expect(page.locator('#connection-result')).to_contain_text('Demo simulator available')
    passed('profile and theme survive reload, connectivity is labelled as a simulator')

    logout()
    login('admin')
    route('memberships', 'Membership requests')
    # Review the request created above; select the pending filter if necessary.
    page.locator('#membership-filter select[name="status"]').select_option('pending')
    page.locator('#membership-filter button[type="submit"]').click()
    page.locator(f'[data-id="{membership_id}"][data-status="approved"]').click()
    confirm()
    expect(page.locator(f'[data-id="{membership_id}"][data-status="approved"]')).to_have_count(0)
    assert next(row for row in api('listMemberships', {'limit':100})['data'] if row['id']==membership_id)['status']=='approved'
    passed('admin approves a real student membership request')
    route('bookings', 'Booking requests')
    page.locator('#booking-filter input[name="search"]').fill('QA Student Practice')
    page.locator('#booking-filter button[type="submit"]').click()
    page.locator(f'[data-id="{booking_id}"][data-status="approved"]').click()
    confirm()
    expect(page.locator('tbody')).to_contain_text('approved')
    passed('admin approves a real student facility booking')

    # Responsive behavior, every required route, both themes.
    expected_titles = {'dashboard': 'Dashboard', 'clubs':'Sports clubs','memberships':'Membership requests','schedules':'Training & schedules','tournaments':'Tournaments','results':'Results & standings','bookings':'Booking requests','reports':'Reports','settings':'Settings'}
    for theme in ['light','dark']:
        page.evaluate("async theme => (await import('/js/store.js')).store.setTheme(theme)", theme)
        expect(page.locator('html')).to_have_attribute('data-theme',theme)
        for width in [320,375,768,1024,1440]:
            page.set_viewport_size({'width':width,'height':900})
            for name, title in expected_titles.items():
                route(name,title)
                expect(page.locator('main h1')).to_be_visible()
                expect(page.locator('html')).to_have_attribute('data-theme',theme)
                dimensions = page.evaluate('({scroll:document.documentElement.scrollWidth,width:innerWidth})')
                assert dimensions['scroll'] <= dimensions['width'] + 1, (name,theme,width,dimensions)
            passed(f'all protected views render without page overflow at {width}px in {theme} theme')

    # Optional artifacts are written outside the checkout for visual review.
    artifacts = Path('/tmp/sportshub-qa')
    artifacts.mkdir(parents=True,exist_ok=True)
    page.evaluate("async () => (await import('/js/store.js')).store.setTheme('light')")
    for width, suffix in [(1440,'desktop'),(375,'mobile')]:
        page.set_viewport_size({'width':width,'height':1000 if width==1440 else 900})
        for name, title in [('clubs','Sports clubs'),('tournaments','Tournaments')]:
            route(name,title)
            expect(page.locator('.club-card' if name=='clubs' else '.tournament-card').first).to_be_visible()
            page.screenshot(path=str(artifacts / f'{name}-{suffix}.png'),full_page=True)

    page.set_viewport_size({'width':375,'height':900})
    # Flexible accessible selector; mobile controls retain a visible navigation name.
    toggle = page.locator('#menu-toggle')
    if toggle.count() == 0:
        toggle = page.get_by_role('button', name='Open navigation', exact=True)
    toggle.click()
    expect(page.locator('aside')).to_be_visible()
    page.keyboard.press('Escape')
    expect(toggle).to_be_focused()
    passed('mobile navigation opens, closes with Escape and restores focus')

    route('settings','Settings')
    page.locator('#reset-demo').click()
    confirm()
    expect(page.locator('#login-form')).to_be_visible()
    login('student')
    assert api('profile')['data']['name'] != 'QA Student Profile'
    assert not any(row['purpose']=='QA Student Practice' for row in api('myBookings',{'limit':100})['data'])
    passed('documented demo reset clears edits and simulated session')
    assert not failures, failures
    passed('no uncaught browser JavaScript errors')
    print(json.dumps({'passed':len(CHECKS),'checks':CHECKS},indent=2))
    context.close()
    browser.close()
