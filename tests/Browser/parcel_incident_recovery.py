"""Synthetic, authenticated recovery checks; retains the original QA report statuses."""
import datetime
import json
import subprocess
import sys
import uuid
from pathlib import Path
from playwright.sync_api import sync_playwright, expect, Error as BrowserError
from failed_delivery_recovery import login, navigate, PHOTO, CAMERA_ARGS

ROOT = Path(__file__).resolve().parents[2]
OUT = ROOT / 'output/parcel-incident-browser'
OUT.mkdir(exist_ok=True)
LOG = []


def fixture(*args):
    result = subprocess.run(['php', '-d', 'extension=gd', 'tests/Browser/parcel_incident_fixture.php', *args], cwd=ROOT, capture_output=True, text=True, timeout=90)
    assert result.returncode == 0, result.stderr
    return json.loads(result.stdout)


def request(page, path, data=None, photo=None, method='POST'):
    page.wait_for_load_state('domcontentloaded')
    meta = page.locator('meta[name="csrf-token"]')
    if meta.count(): page.parcel_csrf = meta.get_attribute('content')
    headers = {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': page.parcel_csrf}
    options = {'method': method, 'headers': headers, 'timeout': 90000}
    if photo:
        fields = {}
        def append(key, value):
            if isinstance(value, (dict, list)):
                for name, entry in (value.items() if isinstance(value, dict) else enumerate(value)):
                    append(f'{key}[{name}]', entry)
            elif value is not None:
                fields[key] = ('1' if value else '0') if isinstance(value, bool) else str(value)
        for key, value in (data or {}).items(): append(key, value)
        fields[photo] = {'name': 'synthetic-parcel.png', 'mimeType': 'image/png', 'buffer': PHOTO.read_bytes()}
        options['multipart'] = fields
    elif method != 'GET':
        options['data'] = data or {}
    # Shares the normally authenticated browser cookie jar; survives unrelated SPA navigation.
    result = page.request.fetch('http://127.0.0.1:8027' + path, **options)
    try: value = result.json()
    except ValueError: value = {'message': result.text()[:300]}
    response = {'status': result.status, 'value': value}
    LOG.append({'method': method, 'path': path, 'status': response['status'], 'response': response['value']})
    (OUT / 'requests.json').write_text(json.dumps(LOG, indent=2), encoding='utf-8')
    return response


def ok(response):
    assert 200 <= response['status'] < 300, response
    return response['value']


def recovery(page, incident):
    return next(row for row in ok(request(page, '/api/logistics/parcel-recovery', method='GET'))['recoveries'] if row['incident_id'] == incident)


def action(page, incident, stage, facts, photo=None):
    row = recovery(page, incident)
    return ok(request(page, f'/api/logistics/parcel-recovery/{incident}/{stage}', {'request_key': str(uuid.uuid4()), 'expected_revision': row['revision'], 'notes': 'Synthetic verified recovery evidence and arrangement', **facts}, photo))['recovery']


def finish(rider, dispatcher, leg):
    ok(request(rider, f'/api/logistics/legs/{leg}/in-transit'))
    ok(request(rider, f'/api/logistics/legs/{leg}/arrivals', {'arrival_type': 'dropoff', 'exception_reason': 'pin_incorrect', 'exception_notes': 'Synthetic destination has no physical map pin.'}))
    proof = ok(request(rider, f'/api/logistics/legs/{leg}/proof', {'handoff_type': 'delivery', 'proof_type': 'photo', 'idempotency_key': str(uuid.uuid4())}, 'proof_file'))['proof']
    result = ok(request(dispatcher, f'/api/logistics/proofs/{proof["id"]}/approve'))
    assert result['leg']['status'] == 'delivered', result
    return proof['id']


def amendment_journeys(rider, dispatcher, staff, state):
    import re
    def refreshed(page, path):
        for other in [rider, dispatcher, staff]:
            if other is page: continue
            meta = other.locator('meta[name="csrf-token"]')
            if meta.count(): other.parcel_csrf = meta.get_attribute('content')
            for attempt in range(3):
                try:
                    other.goto('about:blank', wait_until='commit')
                    break
                except BrowserError as error:
                    if attempt == 2 or 'interrupted by another navigation' not in str(error): raise
        navigate(page, path)

    def responsive(page, name):
        for width in [390, 768, 1440]:
            page.set_viewport_size({'width': width, 'height': 1000})
            page.wait_for_timeout(350)  # Existing sidebar width transition is 300 ms.
            assert page.evaluate('document.documentElement.scrollWidth') <= width + 1, name
            page.screenshot(path=str(OUT / f'dead-ends-{name}-{width}.png'), full_page=True)

    def review_staff_return(order):
        button = staff.get_by_role('button', name='Review return', exact=True).first
        button.click()
        dialog = staff.get_by_role('dialog', name='Review return', exact=True)
        dialog.get_by_role('button', name='Cancel', exact=True).focus()
        staff.keyboard.press('Escape')
        expect(dialog).to_have_count(0)
        expect(button).to_be_focused()
        button.click()
        dialog.get_by_label('Return notes', exact=True).fill('Synthetic actual receipt and normal Staff return review')
        dialog.get_by_label('Return photograph', exact=True).set_input_files(str(PHOTO))
        for field in dialog.locator('input[id^="return-qty-"]').all(): field.fill('1')
        for field in dialog.locator('select[id^="return-disp-"]').all(): field.select_option('resellable')
        with staff.expect_response(lambda response: f'/api/staff/orders/{order}/confirm-return-received' in response.url and response.request.method == 'POST') as saved:
            dialog.get_by_role('button', name='Save return review', exact=True).click()
        assert saved.value.status == 200, saved.value.text()
        result = saved.value.json()['recovery']
        staff.get_by_role('button', name='OK', exact=True).click()
        expect(staff.locator('.swal2-container')).to_have_count(0)
        expect(staff.get_by_role('button', name='Review return', exact=True)).to_have_count(0, timeout=30000)
        for label in ['Returned items inspected', 'Physical return handled; linked work remains', 'Complete inspected item handling', 'Submit refund eligibility review']:
            expect(staff.get_by_text(label, exact=True)).to_have_count(0)
        return result

    results = []
    for branch in ['pre_final_continue', 'post_final_return', 'ordinary_return']:
        data = fixture(str(OUT / 'fixture.json'))
        leg = data['leg_id']
        ok(request(rider, f'/api/logistics/legs/{leg}/in-transit'))
        ordinary = branch == 'ordinary_return'
        parent = ok(request(rider, f'/api/logistics/legs/{leg}/incidents',
            {'type': 'vehicle_problem' if ordinary else 'lost', 'notes': 'Synthetic incident requiring a real continuation'},
            'photo_files[0]' if ordinary else None))['incident']['id']
        if branch == 'post_final_return':
            for actor, stage, revision in [(dispatcher, 'acknowledge', 0), (rider, 'confirm', 1), (dispatcher, 'approve', 2)]:
                ok(request(actor, f'/api/logistics/incidents/{parent}/loss', {'action': stage, 'request_key': str(uuid.uuid4()), 'expected_revision': revision, 'notes': 'Synthetic legitimate loss review'}))
        if not ordinary:
            refreshed(rider, '/erp/logistics/deliveries')
            rider.get_by_role('button', name='History', exact=True).click()
            loss = rider.get_by_role('region', name=f'Loss incident {parent}', exact=True, include_hidden=True)
            expect(loss).to_have_count(1, timeout=60000)
            details = loss.locator('xpath=ancestor::details[1]')
            if details.count() and details.get_attribute('open') is None: details.locator('summary').click()
            loss.get_by_role('button', name='Report Recovery', exact=True).click()
            expect(loss.get_by_label('Actual custody')).to_have_count(0)
            loss.get_by_label('Reason or recovery details').fill('Found the synthetic parcel at the route location')
            loss.get_by_label('Recovery photograph', exact=True).set_input_files(str(PHOTO))
            loss.get_by_role('checkbox').check()
            if branch == 'pre_final_continue': responsive(rider, 'rider-recovery')
            with rider.expect_response(lambda response: f'/incidents/{parent}/recoveries' in response.url and response.request.method == 'POST') as reported:
                loss.get_by_role('button', name='Submit', exact=True).click()
            assert reported.value.status == 201, reported.value.text()
            child = reported.value.json()['incident']['id']
        else:
            child = parent
        refreshed(dispatcher, '/erp/logistics/shipments?status=needs_resolution')
        dispatcher.get_by_role('button', name=re.compile('^(Open delivery|Review incident)( for Shipment .*)?$')).first.click()
        expect(dispatcher.get_by_text('Verified investigation findings', exact=True)).to_have_count(0)
        if ordinary:
            dispatcher.get_by_role('radio', name='Return parcel to shop', exact=True).check()
            dispatcher.get_by_label('Decision reason').fill('Return the parcel for actual shop receiving and inspection')
            with dispatcher.expect_response(lambda response: f'/incidents/{parent}/resolve' in response.url and response.request.method == 'POST') as authorized:
                dispatcher.get_by_role('button', name='Authorize return', exact=True).click()
        else:
            expect(dispatcher.get_by_role('region', name=f'Loss incident {child}', exact=True)).to_have_count(0)
            loss = dispatcher.get_by_role('region', name=f'Loss incident {parent}', exact=True)
            loss.get_by_role('button', name='Authorize continued delivery' if branch == 'pre_final_continue' else 'Authorize return to shop', exact=True).click()
            loss.get_by_label('Reason or recovery details').fill('Authorize the recovered parcel under actual handoff safeguards')
            loss.get_by_label('Operational date').fill(datetime.datetime.now(datetime.timezone(datetime.timedelta(hours=8))).date().isoformat())
            if branch == 'pre_final_continue': loss.get_by_label('Customer arrangement').fill('Customer agreed to continuation with the same original parcel')
            loss.get_by_role('checkbox').check()
            with dispatcher.expect_response(lambda response: f'/incidents/{child}/recovery-review' in response.url and response.request.method == 'POST') as authorized:
                loss.get_by_role('button', name='Submit', exact=True).click()
        assert authorized.value.status == 200, authorized.value.text()
        if not ordinary:
            expect(loss.get_by_role('button', name='Authorize continued delivery' if branch == 'pre_final_continue' else 'Authorize return to shop', exact=True)).to_have_count(0, timeout=60000)
        if branch == 'pre_final_continue': responsive(dispatcher, 'dispatcher-recovery')
        if branch == 'pre_final_continue':
            ok(request(rider, f'/api/logistics/legs/{leg}/accept'))
            finish(rider, dispatcher, leg)
        else:
            row = recovery(staff, child)
            returned = row['return_leg_id']
            if branch == 'post_final_return':
                ok(request(rider, f'/api/logistics/legs/{returned}/accept'))
                # Report photographs never establish custody. This is the normal, assignment-bound pickup confirmation.
                pickup = ok(request(rider, f'/api/logistics/legs/{returned}/proof', {'handoff_type': 'pickup', 'proof_type': 'photo', 'idempotency_key': str(uuid.uuid4())}, 'proof_file'))['proof']
                ok(request(rider, f'/api/logistics/legs/{returned}/pickup-proofs/{pickup["id"]}/confirm'))
            ok(request(rider, f'/api/logistics/legs/{returned}/in-transit'))
            proof = ok(request(rider, f'/api/logistics/legs/{returned}/proof', {'handoff_type': 'receive', 'proof_type': 'photo', 'idempotency_key': str(uuid.uuid4())}, 'proof_file'))['proof']
            ok(request(rider, f'/api/logistics/legs/{returned}/return-proofs/{proof["id"]}/handoff'))
            refreshed(staff, '/erp/staff/job-orders')
            staff.get_by_role('button', name='All Orders', exact=False).click()
            staff.get_by_placeholder('Search...', exact=True).fill(str(data['order_id']))
            staff.set_viewport_size({'width': 390, 'height': 1000})
            expect(staff.get_by_role('region', name='Return receipt and disposition')).to_have_count(0)
            expect(staff.get_by_text('Refresh parcel recovery', exact=True)).to_have_count(0)
            result = review_staff_return(data['order_id'])
            assert result['operational_status'] == 'resolved'
            for width in [390, 768, 1440]:
                staff.set_viewport_size({'width': width, 'height': 1000})
                assert staff.evaluate('document.documentElement.scrollWidth') <= width + 1
                staff.screenshot(path=str(OUT / f'dead-ends-{branch}-{width}.png'), full_page=True)
            if ordinary:
                assert result['source_outcome']['refund_id']
        results.append({'branch': branch, 'parent': parent, 'recovery': child, 'outcome': 'verified', 'no_obsolete_controls': True, 'keyboard_receipt_forms': branch != 'pre_final_continue'})
        (OUT / 'dead-end-results.json').write_text(json.dumps(results, indent=2), encoding='utf-8')
        print('Dead-end journey passed:', branch, flush=True)


if __name__ == '__main__':
    if '--help' in sys.argv:
        print('Usage: python tests/Browser/parcel_incident_recovery.py --mfa --amendment (guarded disposable loopback only)')
        raise SystemExit(0)
    assert '--amendment' in sys.argv, 'Use --mfa --amendment for the current workflow; the historical 15-case results are retained separately.'
    assert '--mfa' in sys.argv, 'Normal password, MFA and Time In are required for this browser run.'
    state = fixture('--setup')
    (OUT / 'fixture.json').write_text(json.dumps(state), encoding='utf-8')
    with sync_playwright() as playwright:
        browser = playwright.chromium.launch(headless=True, args=CAMERA_ARGS)
        pages = {}
        for name, actor in state['actors'].items():
            if name == 'b': continue
            print('Authenticating', name, flush=True)
            pages[name] = login(browser, actor)
            pages[name].on('pageerror', lambda error, role=name: print('Browser page error:', role, str(error), flush=True))
            if '--amendment' in sys.argv:
                pages[name].parcel_csrf = pages[name].locator('meta[name="csrf-token"]').get_attribute('content')
                pages[name].goto('about:blank')
            else:
                navigate(pages[name], '/erp/staff/job-orders' if name == 'staff' else '/erp/logistics/shipments' if name == 'dispatcher' else '/erp/logistics/deliveries')
        rider, dispatcher, staff = pages['a'], pages['dispatcher'], pages['staff']
        if '--amendment' in sys.argv:
            try:
                amendment_journeys(rider, dispatcher, staff, state)
            except Exception:
                for name, page in pages.items():
                    page.screenshot(path=str(OUT / f'amendment-error-{name}.png'), full_page=True)
                    (OUT / f'amendment-error-{name}.txt').write_text(page.locator('body').inner_text(), encoding='utf-8')
                raise
            browser.close()
            sys.exit(0)
