"""Authenticated local browser/API journeys; no mocked transport or authentication."""
import datetime
import json
import subprocess
import sys
import uuid
from pathlib import Path
from playwright.sync_api import sync_playwright, expect, TimeoutError as BrowserTimeout

ROOT = Path(__file__).resolve().parents[2]
OUT = ROOT / 'output/failed-delivery-recovery'
URL = 'http://127.0.0.1:8027'
CAMERA_ARGS = ['--use-fake-device-for-media-stream', '--use-fake-ui-for-media-stream']
MFA = '--mfa' in sys.argv[1:] or '--batch' in sys.argv[1:]
CASES = [value for value in sys.argv[1:] if value != '--mfa']
RESULT_FILE = OUT / ('browser-mfa-results.json' if MFA else 'browser-results.json')
sys.stdout.reconfigure(encoding='utf-8')
PHOTO = OUT / 'synthetic-handoff.png'
if not PHOTO.exists():
    import struct, zlib
    def chunk(kind, value):
        return struct.pack('!I', len(value)) + kind + value + struct.pack('!I', zlib.crc32(kind + value))
    rows = b''.join(b'\x00' + bytes([225 if (y // 40) % 2 else 180, 210, 225]) * 640 for y in range(480))
    PHOTO.write_bytes(b'\x89PNG\r\n\x1a\n' + chunk(b'IHDR', struct.pack('!2I5B', 640, 480, 8, 2, 0, 0, 0)) + chunk(b'IDAT', zlib.compress(rows)) + chunk(b'IEND', b''))



def post(page, path, data=None, file_field=None):
    result = page.evaluate("""async ({path,data,fileField,image}) => {
      let body = JSON.stringify(data || {});
      const headers = {'Accept':'application/json', 'X-Requested-With':'XMLHttpRequest',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content};
      if(fileField) {
        body = new FormData(); Object.entries(data || {}).forEach(([k,v])=>body.append(k,String(v)));
        const blob = await (await fetch(image)).blob(); body.append(fileField,blob,'synthetic-handoff.png');
      } else headers['Content-Type']='application/json';
      const r = await fetch(path,{method:'POST',headers,body,credentials:'same-origin'});
      const text=await r.text(); let value; try {value=JSON.parse(text)} catch {value={message:'Non-JSON response'}};
      return {status:r.status,value};
    }""", {'path': path, 'data': data, 'fileField': file_field,
             'image': 'data:image/png;base64,' + __import__('base64').b64encode(PHOTO.read_bytes()).decode()})
    return result


def ok(result):
    assert 200 <= result['status'] < 300, result
    return result['value']


def login(browser, actor):
    page = browser.new_page(timezone_id='Asia/Manila', viewport={'width': 1440, 'height': 1000})
    response = page.goto(URL + '/login', wait_until='domcontentloaded', timeout=90000)
    assert response.headers.get('x-recovery-test-isolation') == 'local-mariadb'
    try:
        page.wait_for_load_state('networkidle', timeout=1000)
    except BrowserTimeout:
        pass
    page.locator('input[name=email]').fill(actor['email'])
    page.locator('input[name=password]').fill('Local-Recovery-Test-72!')
    page.get_by_role('button', name='Sign in', exact=True).click()
    if MFA:
        page.wait_for_url('**/erp/mfa/challenge', wait_until='domcontentloaded', timeout=60000)
        expect(page.get_by_role('heading', name='Two-Factor Authentication', exact=True)).to_be_visible()
        assert post(page, '/erp/mfa/challenge', {'code': 'invalid'})['status'] == 422
        import base64, hashlib, hmac, struct, time
        digest = hmac.new(base64.b32decode('JBSWY3DPEHPK3PXP'), struct.pack('>Q', int(time.time()) // 30), hashlib.sha1).digest()
        offset = digest[-1] & 15
        code = str((struct.unpack('>I', digest[offset:offset + 4])[0] & 0x7fffffff) % 1000000).zfill(6)
        for index, digit in enumerate(code):
            page.get_by_label('Digit ' + str(index + 1), exact=True).fill(digit)
        page.get_by_role('button', name='Verify', exact=True).click()
    page.wait_for_url('**/erp/time-in', wait_until='domcontentloaded', timeout=60000)
    clock = page.get_by_role('button', name='Clock In', exact=True)
    expect(clock).to_be_enabled(timeout=30000)
    clock.click()
    camera = page.get_by_role('dialog', name='Photo required to clock in', exact=True)
    expect(camera).to_be_visible(timeout=30000)
    camera.get_by_role('button', name='Open Camera', exact=True).click()
    page.wait_for_function("document.querySelector('video[aria-label=\"Live camera preview\"]')?.readyState >= 2")
    camera.get_by_role('button', name='Take Photo', exact=True).click()
    expect(camera.get_by_alt_text('Clock-in photo preview')).to_be_visible()
    with page.expect_response(lambda r: '/api/staff/attendance/check-in' in r.url and r.request.method == 'POST') as response:
        camera.get_by_role('button', name='Clock In', exact=True).click()
    assert response.value.status == 200, response.value.status
    return page


def navigate(page, path):
    page.goto(URL + path, wait_until='domcontentloaded', timeout=60000)
    page.wait_for_function('document.querySelector("#app")?.children.length > 0')
    try:
        page.wait_for_load_state('networkidle', timeout=1000)
    except BrowserTimeout:
        pass  # Periodic status polling can keep the serial local PHP server busy; action locators still wait for actual UI.


if __name__ == '__main__':
    if '--batch' in sys.argv:
        from failed_delivery_batch import run
        run()
    else:
        results = json.loads(RESULT_FILE.read_text()) if CASES and RESULT_FILE.exists() else []
        purposes = CASES or ['retail_delivery', 'repair_pickup', 'repair_return', 'refund_return']
        with sync_playwright() as p:
            browser = p.chromium.launch(headless=True, args=CAMERA_ARGS)
            for case in purposes:
                purpose, _, variant = case.partition('@')
                run = subprocess.run(['php', 'tests/Browser/failed_delivery_fixture.php', purpose, *([] if MFA else ['--unenrolled'])], cwd=ROOT, capture_output=True, text=True, timeout=90)
                assert run.returncode == 0, run.stderr
                try:
                    fixture = json.loads(run.stdout)
                except ValueError:
                    raise AssertionError(run.stdout[:600])
                pages = {key: login(browser, actor) for key, actor in fixture['actors'].items()}
                a, b, dispatch = pages['a'], pages['a' if variant == 'same' else 'b'], pages['dispatcher']
                leg = fixture['leg_id']
                if fixture['collection']:
                    ok(post(a, f'/api/logistics/legs/{leg}/arrivals', {'arrival_type': 'pickup'}))
                failure = {'delivery_assignment_id': fixture['assignment_id'], 'idempotency_key': str(uuid.uuid4()),
                           'attempt_type': 'pickup' if fixture['collection'] else 'delivery',
                           'reason_code': 'customer_unavailable' if fixture['collection'] else 'other', 'notes': 'Synthetic failed visit'}
                ok(post(a, f'/api/logistics/legs/{leg}/report-issue', failure, 'proof_file'))
                navigate(dispatch, '/erp/logistics/shipments')
                dispatch.get_by_role('button', name='Open delivery', exact=True).click()
                retry = dispatch.get_by_role('button' if fixture['collection'] else 'radio', name='Reschedule Pickup' if fixture['collection'] else 'Arrange another delivery attempt', exact=True)
                expect(retry).to_be_visible()
                retry.click()
                dialog = dispatch.get_by_role('dialog', name='Arrange another visit') if fixture['collection'] else dispatch.get_by_role('form', name='Retry schedule and rider')
                day = (datetime.datetime.now(datetime.timezone(datetime.timedelta(hours=8))) + datetime.timedelta(days=3)).date().isoformat()
                dialog.get_by_label('Retry date').fill(day)
                dialog.get_by_label('Retry rider').select_option(str(fixture['actors']['a' if variant == 'same' else 'b']['rider_id']))
                if variant == 'via_shop': dialog.get_by_label('Handoff route').select_option('via_shop')
                if variant == 'appointment':
                    dialog.get_by_label('Customer confirmed this date and window').check()
                    dialog.get_by_label('Customer agreement note').fill('Synthetic customer-confirmed appointment')
                dialog.get_by_label('Resolution reason').fill('Authorized synthetic internal planning retry')
                with dispatch.expect_response(lambda r: f'/legs/{leg}/resolve/retry' in r.url) as response:
                    dialog.get_by_role('button', name='Authorize retry').click()
                assert response.value.status == 200, response.value.json()
                expect(dialog).not_to_be_visible()
                ok(post(b, f'/api/logistics/legs/{leg}/accept'))
                handoff = 'not_required_before_customer_collection'
                if not fixture['collection'] and variant != 'same':
                    assert post(b, f'/api/logistics/legs/{leg}/in-transit')['status'] == 422
                    navigate(b, '/erp/logistics/deliveries')
                    expect(b.get_by_role('button', name='Start retry', exact=True)).to_be_disabled()
                    navigate(a, '/erp/logistics/deliveries')
                    panel = a.get_by_role('region', name='Package handoffs')
                    panel.get_by_label('Handoff photo').set_input_files(str(PHOTO))
                    with a.expect_response(lambda r: '/custody-transfers/' in r.url and r.url.endswith('/release')) as response:
                        panel.get_by_role('button', name='Confirm release').click()
                    assert response.value.status == 200
                    assert post(b, f'/api/logistics/legs/{leg}/in-transit')['status'] == 422
                    if variant == 'via_shop':
                        for action in ['receipt', 'release']:
                            navigate(dispatch, '/erp/logistics/shipments')
                            shop_panel = dispatch.get_by_role('region', name='Package handoffs')
                            shop_panel.get_by_label('Handoff photo').set_input_files(str(PHOTO))
                            endpoint = 'receive' if action == 'receipt' else 'release'
                            with dispatch.expect_response(lambda r: '/custody-transfers/' in r.url and r.url.endswith('/' + endpoint)) as shop_response:
                                shop_panel.get_by_role('button', name='Confirm ' + action).click()
                            assert shop_response.value.status == 200
                            assert post(b, f'/api/logistics/legs/{leg}/in-transit')['status'] == 422
                    navigate(b, '/erp/logistics/deliveries')
                    panel = b.get_by_role('region', name='Package handoffs')
                    panel.get_by_label('Handoff photo').set_input_files(str(PHOTO))
                    with b.expect_response(lambda r: '/custody-transfers/' in r.url and r.url.endswith('/receive')) as response:
                        panel.get_by_role('button', name='Confirm receipt').click()
                    assert response.value.status == 200
                    navigate(b, '/erp/logistics/deliveries')
                    if variant == 'appointment':
                        expect(b.get_by_role('button', name='Start retry', exact=True)).to_be_disabled()
                        assert post(b, f'/api/logistics/legs/{leg}/in-transit')['status'] == 422
                        results.append({'purpose': purpose, 'case': case, 'leg_id': leg, 'customer_appointment': 'ui_and_api_blocked_after_receipt'})
                        RESULT_FILE.write_text(json.dumps(results, indent=2))
                        for page in pages.values(): page.context.close()
                        print(case, 'appointment respected', flush=True)
                        continue
                    expect(b.get_by_role('button', name='Start retry', exact=True)).to_be_enabled()
                    b.screenshot(path=str(OUT / f'{purpose}-handoff-complete.png'), full_page=True)
                    handoff = 'release_and_receipt_confirmed_in_ui'
                elif fixture['collection']:
                    ok(post(b, f'/api/logistics/legs/{leg}/picked-up'))
                else:
                    navigate(b, '/erp/logistics/deliveries')
                    expect(b.get_by_role('button', name='Start retry', exact=True)).to_be_enabled()
                    handoff = 'same_confirmed_holder_keeps_custody'
                ok(post(b, f'/api/logistics/legs/{leg}/in-transit'))
                if variant == 'repeat_failure':
                    page_data = b.evaluate('''async () => (await (await fetch('/api/logistics/shipments', {headers:{'Accept':'application/json'}})).json())''')
                    # The active assignment comes from the authorized shipment response, never the old failed execution.
                    shipments = page_data.get('data', page_data.get('shipments', {}).get('data', []))
                    current_leg = next(item for shipment in shipments for item in shipment['legs'] if item['id'] == leg)
                    current = max(current_leg['assignments'], key=lambda item: item['id'])
                    ok(post(b, f'/api/logistics/legs/{leg}/report-issue', {'delivery_assignment_id': current['id'], 'idempotency_key': str(uuid.uuid4()), 'reason_code': 'other', 'notes': 'Second synthetic failed visit'}, 'proof_file'))
                    results.append({'purpose': purpose, 'case': case, 'leg_id': leg, 'retry_outcome': 'another_distinct_failure_saved'})
                    RESULT_FILE.write_text(json.dumps(results, indent=2))
                    for page in pages.values(): page.context.close()
                    print(case, 'repeated failure saved', flush=True)
                    continue
                ok(post(b, f'/api/logistics/legs/{leg}/arrivals', {'arrival_type': 'dropoff', 'exception_reason': 'pin_incorrect', 'exception_notes': 'Synthetic fixture destination has no map pin.'}))
                handoff_type = 'receive' if purpose == 'refund_return' else 'delivery'
                proof = ok(post(b, f'/api/logistics/legs/{leg}/proof', {'handoff_type': handoff_type, 'proof_type': 'photo', 'idempotency_key': str(uuid.uuid4())}, 'proof_file'))['proof']
                if purpose == 'refund_return':
                    ok(post(b, f'/api/logistics/legs/{leg}/return-proofs/{proof["id"]}/handoff'))
                    completed = ok(post(dispatch, f'/api/logistics/legs/{leg}/return-proofs/{proof["id"]}/receipt'))
                else:
                    completed = ok(post(dispatch, f'/api/logistics/proofs/{proof["id"]}/approve'))
                assert completed.get('leg', {}).get('status') == 'delivered', completed
                results.append({'purpose': purpose, 'case': case, 'leg_id': leg, 'login': 'normal_password_totp' if MFA else 'normal_password_unenrolled_account', 'attendance': 'normal_clock_in',
                                'failure_saved': True, 'retry_dialog': 'date_and_new_rider', 'handoff': handoff,
                                'early_planning_start': 'allowed', 'completion_status': completed.get('leg', {}).get('status')})
                RESULT_FILE.write_text(json.dumps(results, indent=2), encoding='utf-8')
                print(case, 'completed', flush=True)
                for page in pages.values():
                    page.context.close()
            browser.close()
