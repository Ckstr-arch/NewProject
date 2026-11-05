#!/usr/bin/env python3
"""Browser checks for LIVE adapter wiring against an intercepted synthetic API.

This does not test a real PHP/MySQL backend. No npm/build tools are required.
Serve the frontend on port 8080, then run with Python Playwright and Chromium:
    python3 tests/live-browser-smoke.py --browser /usr/bin/chromium
All sessions, cookies and localStorage belong to isolated test browser contexts.
The config response is intercepted in memory; application files stay unchanged.
"""
import argparse
import json
from pathlib import Path
from urllib.parse import urlparse
from playwright.sync_api import sync_playwright, expect

parser = argparse.ArgumentParser()
parser.add_argument('--base-url', default='http://localhost:8080')
parser.add_argument('--browser', default='/usr/bin/chromium')
args = parser.parse_args()
BASE = args.base_url.rstrip('/')
ROOT = Path(__file__).resolve().parents[1]
CONFIG = (ROOT / 'js/config.js').read_text().replace("API_MODE: 'mock'", "API_MODE: 'live'")
CHECKS = []
USER = {'id': 101, 'name': 'Synthetic Student', 'email': 'synthetic@example.test',
        'role': 'student', 'studentId': 'SYNTHETIC-101', 'faculty': 'Computing',
        'notifications': True}


def passed(description):
    CHECKS.append(description)
    print('PASS', description, flush=True)


def success(data):
    return {'success': True, 'data': data, 'message': 'Synthetic API response'}


def listing(data=None):
    data = data or []
    return {'success': True, 'data': data,
            'pagination': {'page': 1, 'limit': 10, 'total': len(data), 'totalPages': 1 if data else 0}}


def configure(context, config=CONFIG):
    context.route('**/js/config.js', lambda route: route.fulfill(
        status=200, content_type='text/javascript', body=config))
    context.add_init_script("""
      window.liveRequestOptions = [];
      const originalFetch = window.fetch.bind(window);
      window.fetch = (url, options = {}) => {
        if (String(url).includes('/api/v1/')) window.liveRequestOptions.push({
          method: options.method, credentials: options.credentials,
          url: String(url), csrf: options.headers?.['X-CSRF-Token'] || null
        });
        return originalFetch(url, options);
      };
    """)


with sync_playwright() as playwright:
    browser = playwright.chromium.launch(executable_path=args.browser,
                                         headless=True, args=['--no-sandbox'])
    context = browser.new_context(viewport={'width': 1440, 'height': 1000})
    configure(context)
    state = {'signed_in': False, 'token': 'anonymous-csrf-1', 'sessions': 0,
             'profile_failure': None, 'profile_read_401': False, 'health_503': False,
             'login_requests': [], 'profile_requests': [], 'unhandled': []}

    def fixture(route):
        request = route.request
        path = urlparse(request.url).path.removeprefix('/api/v1')
        method = request.method
        status, headers = 200, {}
        if path == '/auth/session':
            state['sessions'] += 1
            headers['Set-Cookie'] = 'sportshub_test_session=synthetic; HttpOnly; SameSite=Lax; Path=/'
            response = success({'user': USER if state['signed_in'] else None,
                                'csrfToken': state['token']})
        elif path == '/auth/login':
            state['login_requests'].append({'headers': request.all_headers(), 'body': request.post_data_json})
            state['signed_in'], state['token'] = True, 'authenticated-csrf'
            response = success({'user': USER, 'csrfToken': state['token']})
        elif path == '/dashboard/metrics':
            response = success({'activeClubs': 3, 'memberships': 2, 'upcomingEvents': 1, 'pendingBookings': 0})
        elif path in ['/dashboard/activity', '/dashboard/upcoming']:
            response = success([])
        elif path == '/users/me/participation':
            response = listing()
        elif path == '/users/me' and method == 'GET':
            if state['profile_read_401']:
                state['profile_read_401'] = False
                state['signed_in'], state['token'] = False, 'anonymous-csrf-2'
                status, response = 401, {'success': False, 'message': 'Synthetic session expired'}
            else:
                response = success(USER)
        elif path == '/users/me' and method == 'PATCH':
            state['profile_requests'].append(request.all_headers())
            failure = state['profile_failure']
            if failure == 422:
                status, response = 422, {'success': False, 'message': 'Validation failed',
                                        'errors': {'name': ['Use your university display name.']}}
            elif failure == 403:
                status, response = 403, {'success': False, 'message': 'Internal permission details hidden'}
            else:
                response = success({**USER, **request.post_data_json})
        elif path == '/system/health':
            if state['health_503']:
                status, response = 503, {'success': False, 'message': 'SQLSTATE private internal stack'}
            else:
                response = success({'status': 'ok', 'database': 'synthetic fixture, not MySQL'})
        elif path == '/clubs/99999' and method == 'DELETE':
            route.fulfill(status=204, body='')
            return
        elif path == '/auth/logout':
            state['signed_in'], state['token'] = False, 'anonymous-csrf-logout'
            response = success({'user': None, 'csrfToken': state['token']})
        else:
            state['unhandled'].append(f'{method} {path}')
            status, response = 404, {'success': False, 'message': 'No synthetic fixture for this endpoint'}
        route.fulfill(status=status, headers={**headers, 'Content-Type': 'application/json'},
                      body=json.dumps(response))

    context.route('**/api/v1/**', fixture)
    page = context.new_page()
    errors = []
    page.on('pageerror', lambda error: errors.append(str(error)))
    page.goto(BASE)
    expect(page.locator('#login-form')).to_be_visible()
    assert state['sessions'] >= 2, 'Bootstrap and login must acquire anonymous CSRF session'
    assert page.locator('.demo-box, .demo-account, .mode-pill').count() == 0
    assert 'Student123!' not in page.locator('body').inner_text()
    assert not page.evaluate("Object.keys(localStorage).some(key => key.includes('demo'))")
    passed('Live session bootstrap renders login without demo credentials, indicator, or mock records')

    def login():
        page.get_by_label('University email').fill('synthetic@example.test')
        page.get_by_label('Password', exact=True).fill('SyntheticBrowserOnly!')
        page.locator('#login-form button[type=submit]').click()
        expect(page.locator('main h1')).to_have_text('Dashboard')

    login()
    request = state['login_requests'][-1]
    assert request['headers']['x-csrf-token'] == 'anonymous-csrf-1'
    assert request['headers']['content-type'].startswith('application/json')
    assert request['headers']['accept'] == 'application/json'
    assert 'sportshub_test_session=synthetic' in request['headers'].get('cookie', '')
    assert request['body']['email'] == 'synthetic@example.test'
    assert all(r['credentials'] == 'include' for r in page.evaluate('window.liveRequestOptions'))
    passed('Live login sends JSON, anonymous CSRF token, cookie, and credentials include')
    assert page.locator('.demo-box').count() == 0
    expect(page.locator('.mode-pill')).to_contain_text('Live API mode')
    assert 'Demo Mode' not in page.locator('body').inner_text()
    passed('Authenticated live dashboard uses fixture metrics with no demo-mode banner')

    page.evaluate("location.hash = '#/settings'")
    expect(page.locator('main h1')).to_have_text('Settings')
    expect(page.locator('#connection-result')).to_contain_text('Backend reports healthy')
    assert page.locator('#reset-demo').count() == 0
    state['profile_failure'] = 422
    page.get_by_label('Full name').fill('Synthetic Changed')
    page.locator('#profile-form button[type=submit]').click()
    expect(page.locator('[data-error-for=name]')).to_have_text('Use your university display name.')
    expect(page.get_by_label('Full name')).to_have_attribute('aria-invalid', 'true')
    assert state['profile_requests'][-1]['x-csrf-token'] == 'authenticated-csrf'
    passed('Live 422 profile response renders field error and uses rotated authenticated CSRF')

    state['profile_failure'] = 403
    page.locator('#profile-form button[type=submit]').click()
    expect(page.locator('#profile-form .form-error')).to_contain_text('Access denied')
    assert 'Internal permission details hidden' not in page.locator('body').inner_text()
    expect(page.locator('#profile-form button[type=submit]')).to_be_enabled()
    passed('Live 403 gives clear access-denied feedback and restores form controls')

    deleted = page.evaluate("""async () => {
      const {api} = await import('/js/services/api.js');
      return await api.deleteClub(99999);
    }""")
    assert deleted['success'] is True and deleted['data'] is None
    passed('Live adapter accepts an empty 204 deletion response without JSON parsing errors')

    state['health_503'] = True
    page.locator('#check-connection').click()
    expect(page.locator('#connection-result')).to_contain_text('The backend is unavailable')
    assert 'SQLSTATE' not in page.locator('body').inner_text()
    passed('Live health 503 reports unavailable backend without claiming connectivity or exposing internals')

    previous_sessions = state['sessions']
    state['profile_read_401'] = True
    page.evaluate("location.hash = '#/dashboard'")
    expect(page.locator('main h1')).to_have_text('Dashboard')
    page.evaluate("location.hash = '#/settings'")
    expect(page.locator('#login-form')).to_be_visible()
    assert state['sessions'] > previous_sessions
    login()
    assert state['login_requests'][-1]['headers']['x-csrf-token'] == 'anonymous-csrf-2'
    passed('Live 401 clears protected session, redirects to login, reacquires CSRF, and permits reauthentication')

    page.evaluate("location.hash = '#/settings'")
    expect(page.locator('main h1')).to_have_text('Settings')
    page.locator('#settings-logout').click()
    expect(page.locator('#login-form')).to_be_visible()
    passed('Live logout returns to anonymous login')
    assert not errors, errors
    assert not state['unhandled'], state['unhandled']
    context.close()

    def failure_bootstrap(description, handler, expected, config=CONFIG, cleanup=None):
        failure_context = browser.new_context()
        configure(failure_context, config)
        failure_context.route('**/api/v1/**', handler)
        failure_page = failure_context.new_page()
        failure_page.goto(BASE)
        expect(failure_page.locator('.boot-screen')).to_contain_text(expected)
        assert failure_page.locator('#login-form, .demo-box').count() == 0
        assert not failure_page.evaluate("Object.keys(localStorage).some(key => key.includes('demo'))")
        expect(failure_page.locator('.boot-screen')).to_contain_text('No demo data was substituted')
        passed(description)
        if cleanup:
            cleanup()
        failure_context.close()

    failure_bootstrap('Live network failure blocks bootstrap clearly without falling back to mocks',
                      lambda route: route.abort('connectionfailed'), 'Unable to reach the backend')
    failure_bootstrap('Unavailable live bootstrap sanitizes 503 errors and offers a retry',
                      lambda route: route.fulfill(status=503, content_type='application/json',
                          body=json.dumps({'success': False, 'message': 'Private SQL stack trace'})),
                      'The backend is unavailable')
    failure_bootstrap('Malformed live server response blocks bootstrap instead of substituting demo data',
                      lambda route: route.fulfill(status=200, content_type='text/html', body='<h1>Not JSON</h1>'),
                      'The server returned an invalid response')
    pending_timeout_routes = []
    failure_bootstrap('Live request timeout aborts bootstrap with clear feedback and no mock fallback',
                      lambda route: pending_timeout_routes.append(route), 'The request timed out',
                      CONFIG.replace('REQUEST_TIMEOUT: 10000', 'REQUEST_TIMEOUT: 100'),
                      cleanup=lambda: [route.abort() for route in pending_timeout_routes])
    browser.close()

print(f'\n{len(CHECKS)} synthetic live-browser checks passed. No PHP/MySQL backend was tested.')
