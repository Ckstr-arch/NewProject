#!/usr/bin/env python3
"""Optional live UI acceptance test for the plain PHP/MySQL backend.

Requires Python Playwright + Chromium only for QA, not for running the app.
The backend must be seeded with the documented DEVELOPMENT ONLY accounts.
Creates uniquely named test records and removes them with the admin account.
  python3 tests/php-backend-browser.py --base-url http://localhost:5500
"""
import argparse
import json
import uuid
from datetime import date, timedelta
from playwright.sync_api import sync_playwright, expect

parser = argparse.ArgumentParser()
parser.add_argument('--base-url', default='http://localhost:5500')
parser.add_argument('--browser', default='/usr/bin/chromium')
args = parser.parse_args()
base = args.base_url.rstrip('/')
checks = []
marker = 'UI QA ' + uuid.uuid4().hex[:8]
booking_date = (date.today() + timedelta(days=365 + int(marker[-4:],16) % 500)).isoformat()

with sync_playwright() as p:
    browser = p.chromium.launch(executable_path=args.browser, headless=True, args=['--no-sandbox'])
    context = browser.new_context(viewport={'width': 1440, 'height': 1000})
    page = context.new_page()
    errors = []
    page.on('pageerror', lambda error: errors.append(str(error)))

    def passed(message):
        checks.append(message)
        print('PASS', message, flush=True)

    def api(method, *params):
        return page.evaluate("""async ({method,params}) => {
          const {api}=await import('/js/services/api.js');
          return await api[method](...params);
        }""", {'method': method, 'params': list(params)})

    def login(email, password):
        page.goto(base + '/#/login')
        form = page.locator('#login-form')
        expect(form).to_be_visible()
        form.locator('[name="email"]').fill(email)
        form.locator('[name="password"]').fill(password)
        form.locator('[type="submit"]').click()
        expect(page.locator('main h1')).to_have_text('Dashboard')
        expect(page.locator('.metric-card')).to_have_count(4)

    def route(name, title):
        page.evaluate('(name) => location.hash = `#/${name}`', name)
        expect(page.locator('main h1')).to_have_text(title)
        expect(page.locator('#resource-content [role="alert"]')).to_have_count(0)

    def logout():
        page.locator('#logout').click()
        page.locator('dialog[open] [data-confirm]').click()
        expect(page.locator('#login-form')).to_be_visible()

    def save():
        dialog = page.locator('dialog[open]')
        dialog.locator('[type="submit"]').click()
        expect(dialog).to_have_count(0)

    login('student@nsbm.ac.lk', 'password123')
    passed('student login loads database-derived dashboard')
    page.reload()
    expect(page.locator('main h1')).to_have_text('Dashboard')
    passed('JWT session is restored after reload')
    expect(page.locator('a[data-route="memberships"]')).to_have_count(0)
    route('memberships', 'Page unavailable')
    passed('demo-only module is hidden and blocked in live mode')
    route('clubs', 'Sports clubs')
    expect(page.locator('#add-club')).to_have_count(0)
    expect(page.locator('.club-card').first).to_be_visible()
    page.locator('#club-search').fill('Basketball')
    expect(page.locator('.club-card')).to_have_count(1)
    expect(page.locator('.club-card h2')).to_contain_text('Basketball')
    passed('student reads and searches actual clubs without admin controls')
    route('bookings', 'My bookings')
    page.locator('#new-booking').click()
    dialog = page.locator('dialog[open]')
    dialog.locator('[name="facility"]').select_option('Tennis Court')
    for name, value in {'sport':'Tennis','booking_date':booking_date,'start_time':'07:00','end_time':'08:00','notes':marker}.items():
        dialog.locator(f'[name="{name}"]').fill(value)
    save()
    page.locator('#resource-filter [name="search"]').fill(marker)
    expect(page.locator('tbody')).to_contain_text(marker)
    booking = api('listBookings', {'search':marker})['data']['items'][0]
    assert booking['status'] == 'pending'
    passed('student creates a persistent pending booking')
    page.reload()
    expect(page.locator('main h1')).to_have_text('My bookings')
    page.locator('#resource-filter [name="search"]').fill(marker)
    expect(page.locator('tbody')).to_contain_text(marker)
    passed('booking remains in MySQL after page refresh')
    page.locator('#new-booking').click()
    dialog = page.locator('dialog[open]')
    dialog.locator('[name="facility"]').select_option('Tennis Court')
    for name,value in {'sport':'Tennis','booking_date':booking_date,'start_time':'07:30','end_time':'08:30','notes':marker+' conflict'}.items():
        dialog.locator(f'[name="{name}"]').fill(value)
    dialog.locator('[type="submit"]').click()
    expect(dialog.locator('.form-error')).to_contain_text('already booked')
    dialog.locator('.modal-close').click()
    passed('overlapping booking is rejected and shown in the form')
    page.locator('tr').filter(has_text=marker).locator('[data-cancel]').click()
    page.locator('dialog[open] [data-confirm]').click()
    expect(page.locator('tbody')).to_contain_text('cancelled')
    passed('student cancels their own booking')
    logout()
    login('admin@nsbm.ac.lk','admin123')
    before = api('metrics')['data']['clubs']
    route('clubs','Sports clubs')
    page.locator('#add-club').click()
    dialog = page.locator('dialog[open]')
    for name,value in {'name':marker,'sport':'Athletics','president':'QA Student','member_count':'12','description':'Live PHP browser acceptance test'}.items():
        dialog.locator(f'[name="{name}"]').fill(value)
    save()
    page.locator('#club-search').fill(marker)
    expect(page.locator('.club-card')).to_have_count(1)
    assert api('metrics')['data']['clubs'] == before+1
    passed('sports admin creates a club and dashboard metrics update')
    page.locator('[data-edit]').click()
    dialog = page.locator('dialog[open]')
    expect(dialog.locator('[name="president"]')).to_have_value('QA Student')
    dialog.locator('[name="name"]').fill(marker+' updated')
    save()
    expect(page.locator('.club-card h2')).to_have_text(marker+' updated')
    passed('sports admin edits the persisted club through the UI')
    page.locator('[data-delete]').click()
    page.locator('dialog[open] [data-confirm]').click()
    expect(page.locator('.empty-state')).to_contain_text('No records found')
    assert api('metrics')['data']['clubs'] == before
    api('deleteBooking',booking['id'])
    passed('sports admin deletes the club and test booking; metrics update again')
    route('schedules','Training & schedules')
    page.locator('#new-schedule').click()
    dialog = page.locator('dialog[open]')
    for name,value in {'sport':'Athletics','event_name':marker+' training','event_date':booking_date,'start_time':'10:00','end_time':'11:00','location':'Main Ground','teams':'Campus runners','description':'Browser schedule QA'}.items():
        dialog.locator(f'[name="{name}"]').fill(value)
    save()
    page.locator('#resource-filter [name="search"]').fill(marker)
    expect(page.locator('tbody')).to_contain_text(marker+' training')
    page.locator('[data-edit]').click()
    dialog = page.locator('dialog[open]')
    expect(dialog.locator('[name="event_name"]')).to_have_value(marker+' training')
    dialog.locator('[name="event_name"]').fill(marker+' training updated')
    save()
    expect(page.locator('tbody')).to_contain_text(marker+' training updated')
    page.locator('[data-delete]').click()
    page.locator('dialog[open] [data-confirm]').click()
    expect(page.locator('.empty-state')).to_contain_text('No records found')
    passed('schedule create, edit, and delete forms persist exact API fields')
    route('tournaments','Tournaments')
    page.locator('#add-tournament').click()
    dialog = page.locator('dialog[open]')
    for name,value in {'name':marker+' cup','sport':'Athletics','start_date':booking_date,'end_date':booking_date,'format':'League','max_teams':'8','registered_teams':'2','description':'Browser tournament QA'}.items():
        dialog.locator(f'[name="{name}"]').fill(value)
    save()
    page.locator('#resource-filter [name="search"]').fill(marker)
    expect(page.locator('.tournament-card')).to_have_count(1)
    page.locator('[data-edit]').click()
    dialog = page.locator('dialog[open]')
    expect(dialog.locator('[name="registered_teams"]')).to_have_value('2')
    dialog.locator('[name="status"]').select_option('upcoming')
    save()
    expect(page.locator('.tournament-card')).to_contain_text('upcoming')
    page.locator('[data-delete]').click()
    page.locator('dialog[open] [data-confirm]').click()
    expect(page.locator('.empty-state')).to_contain_text('No records found')
    passed('tournament create, edit, and delete forms persist exact API fields')
    route('bookings','Facilities & bookings')
    page.locator('#new-booking').click()
    dialog = page.locator('dialog[open]')
    dialog.locator('[name="facility"]').select_option('Gymnasium')
    for name,value in {'sport':'Fitness','booking_date':booking_date,'start_time':'09:00','end_time':'10:00','notes':marker+' admin'}.items():
        dialog.locator(f'[name="{name}"]').fill(value)
    dialog.locator('[name="status"]').select_option('confirmed')
    save()
    page.locator('#resource-filter [name="search"]').fill(marker)
    expect(page.locator('tbody')).to_contain_text(marker+' admin')
    page.locator('tr').filter(has_text=marker+' admin').locator('[data-edit]').click()
    dialog = page.locator('dialog[open]')
    expect(dialog.locator('[name="status"]')).to_have_value('confirmed')
    dialog.locator('[name="notes"]').fill(marker+' admin updated')
    save()
    expect(page.locator('tbody')).to_contain_text(marker+' admin updated')
    page.locator('tr').filter(has_text=marker+' admin updated').locator('[data-delete]').click()
    page.locator('dialog[open] [data-confirm]').click()
    expect(page.locator('.empty-state')).to_contain_text('No records found')
    passed('administrator booking create, edit, and delete forms persist exact API fields')
    for name,title in [('schedules','Training & schedules'),('tournaments','Tournaments'),('bookings','Facilities & bookings')]:
        route(name,title)
        expect(page.locator('#resource-content')).not_to_have_attribute('aria-busy','true')
    passed('all required live resource views load')
    page.set_viewport_size({'width':375,'height':900})
    for name,title in [('dashboard','Dashboard'),('clubs','Sports clubs'),('schedules','Training & schedules'),('tournaments','Tournaments'),('bookings','Facilities & bookings')]:
        route(name,title)
        assert page.evaluate('document.documentElement.scrollWidth <= innerWidth + 1'), name
    passed('required live views fit a mobile viewport')
    page.set_viewport_size({'width':1440,'height':1000})
    logout()
    result=page.evaluate("""async () => {
      const {CONFIG}=await import('/js/config.js');
      return (await fetch(CONFIG.API_BASE_URL+'/clubs')).status;
    }""")
    assert result == 401
    assert page.evaluate("sessionStorage.getItem('sportshub-access-token')") is None
    passed('logout discards JWT and protected API rejects unauthenticated access')
    assert not errors, errors
    print(json.dumps({'passed':len(checks),'checks':checks},indent=2))
    context.close()
    browser.close()
