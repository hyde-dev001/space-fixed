"""Synthetic, authenticated recovery checks; retains the original QA report statuses."""
import datetime
import json
import subprocess
import sys
import uuid
from pathlib import Path
from playwright.sync_api import sync_playwright, expect
from failed_delivery_recovery import login, navigate, PHOTO, CAMERA_ARGS

ROOT = Path(__file__).resolve().parents[2]
OUT = ROOT / 'output/parcel-incident-browser'
OUT.mkdir(exist_ok=True)
CASES = {'PD-01': ('damaged', 'dismissed'), 'PD-02': ('damaged', 'via_shop'), 'PD-03': ('damaged', 'return_required'),
         'PL-01': ('lost', 'dismissed'), 'PL-02': ('lost', 'retry'), 'PL-03': ('lost', 'return_required'), 'PL-04': ('lost', 'loss_confirmed'),
         'VR-01': ('vehicle_problem', 'dismissed'), 'VR-02': ('vehicle_problem', 'retry'), 'VR-03': ('vehicle_problem', 'via_shop'),
         'VR-04': ('vehicle_problem', 'return_required'), 'CD-01': ('customer_dispute', 'dismissed'),
         'CD-02': ('customer_dispute', 'retry'), 'CD-03': ('customer_dispute', 'via_shop'), 'CD-04': ('customer_dispute', 'return_required')}
LOG = []


def fixture(*args):
    result = subprocess.run(['php', '-d', 'extension=gd', 'tests/Browser/parcel_incident_fixture.php', *args], cwd=ROOT, capture_output=True, text=True, timeout=90)
    assert result.returncode == 0, result.stderr
    return json.loads(result.stdout)


def request(page, path, data=None, photo=None, method='POST'):
    page.wait_for_load_state('domcontentloaded')
    headers = {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': page.locator('meta[name="csrf-token"]').get_attribute('content')}
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


def inspect(staff, incident, item):
    return action(staff, incident, 'inspection', {'items': [{'reference': f'order-item:{item}', 'quantity': 1, 'condition': 'safe'}]}, 'photo')


def handoff(rider, staff, dispatcher, incident, outbound=False):
    row = recovery(dispatcher, incident)
    transfer = next(item for item in row['transfers'] if item['status'] == 'requested')
    ok(request(staff if outbound else rider, f'/api/logistics/custody-transfers/{transfer["id"]}/release', {}, 'photo'))
    ok(request(rider if outbound else staff, f'/api/logistics/custody-transfers/{transfer["id"]}/receive', {}, 'photo'))


if __name__ == '__main__':
    assert '--mfa' in sys.argv, 'Normal password, MFA and Time In are required for this browser run.'
    state = fixture('--setup')
    (OUT / 'fixture.json').write_text(json.dumps(state), encoding='utf-8')
    selected = [case for case in sys.argv[1:] if case in CASES] or list(CASES)
    results = []
    with sync_playwright() as playwright:
        browser = playwright.chromium.launch(headless=True, args=CAMERA_ARGS)
        pages = {}
        for name, actor in state['actors'].items():
            if name == 'b': continue
            print('Authenticating', name, flush=True)
            pages[name] = login(browser, actor)
            navigate(pages[name], '/erp/staff/job-orders' if name == 'staff' else '/erp/logistics/shipments' if name == 'dispatcher' else '/erp/logistics/deliveries')
        rider, dispatcher, staff = pages['a'], pages['dispatcher'], pages['staff']
        for case in selected:
            data = fixture(str(OUT / 'fixture.json'))
            leg, item = data['leg_id'], data['item_id']
            kind, route = CASES[case]
            ok(request(rider, f'/api/logistics/legs/{leg}/in-transit'))
            incident = ok(request(rider, f'/api/logistics/legs/{leg}/incidents', {'type': kind, 'notes': f'Synthetic {case} actual incident report'}, 'photo_files[0]'))['incident']['id']
            findings = {'obstacle_resolved': True, 'safe_for_transport': True, 'safe_for_delivery': True, 'condition_verified': True,
                        'recovered_verified': True, 'dispute_resolved': True, 'customer_agreement': 'Synthetic customer accepted the safe arrangement',
                        'items': [{'reference': f'order-item:{item}', 'quantity': 1, 'condition': 'Verified safe parcel'}]}
            resolution = 'retry' if route == 'via_shop' else route
            payload = {'resolution': resolution, 'note': f'Synthetic {case} investigated decision', 'request_key': str(uuid.uuid4()), 'expected_revision': 0, 'findings': findings}
            if case in ['PD-01', 'PL-01', 'CD-01']:
                blocked = request(dispatcher, f'/api/logistics/incidents/{incident}/resolve', {**payload, 'findings': {'safe_for_transport': True}})
                assert blocked['status'] == 422, blocked
            if resolution == 'retry':
                payload.update({'reason': payload['note'], 'delivery_date': datetime.datetime.now(datetime.timezone(datetime.timedelta(hours=8))).date().isoformat(),
                                'delivery_window': 'morning', 'expected_assignment_id': data['assignment_id'], 'rider_profile_id': state['actors']['a']['rider_id'],
                                'handoff_route': 'via_shop' if route == 'via_shop' else 'direct'})
            ok(request(dispatcher, f'/api/logistics/incidents/{incident}/resolve', payload, 'evidence_files[0]' if resolution == 'loss_confirmed' else None))
            if resolution == 'loss_confirmed':
                closed = action(dispatcher, incident, 'close', {'outcome': 'confirmed_loss'})
                assert closed['operational_status'] == 'resolved' and not closed['complete']
                results.append({'case': case, 'execution': 'operational_loss_closed_investigation_pending', 'incident': incident})
            else:
                if resolution == 'retry':
                    ok(request(rider, f'/api/logistics/legs/{leg}/accept'))
                if route == 'via_shop':
                    handoff(rider, staff, dispatcher, incident)
                    inspect(staff, incident, item)
                    action(staff, incident, 'disposition', {'action': 'ready_for_retry'})
                    action(dispatcher, incident, 'disposition', {'action': 'authorize_retry_handoff'})
                    handoff(rider, staff, dispatcher, incident, outbound=True)
                if resolution == 'return_required':
                    row = recovery(dispatcher, incident)
                    returned = row['return_leg_id']
                    ok(request(rider, f'/api/logistics/legs/{returned}/in-transit'))
                    proof = ok(request(rider, f'/api/logistics/legs/{returned}/proof', {'handoff_type': 'receive', 'proof_type': 'photo', 'idempotency_key': str(uuid.uuid4())}, 'proof_file'))['proof']
                    ok(request(rider, f'/api/logistics/legs/{returned}/return-proofs/{proof["id"]}/handoff'))
                    action(staff, incident, 'receipt', {'proof_id': proof['id']}, 'photo')
                    inspect(staff, incident, item)
                    navigate(staff, '/erp/staff/job-orders')
                    region = staff.get_by_role('region', name='Returned parcels requiring action', exact=True)
                    expect(region.get_by_text(f'Incident #{incident}', exact=False)).to_be_visible(timeout=30000)
                    for width in [390, 768, 1440]:
                        staff.set_viewport_size({'width': width, 'height': 1000})
                        # Let the shared layout's 300 ms sidebar transition finish before measuring.
                        staff.wait_for_timeout(400)
                        assert region.bounding_box()['width'] >= min(width - 48, 800), 'Recovery region is squeezed by the layout'
                        assert staff.evaluate('document.documentElement.scrollWidth') <= width + 1, 'Horizontal overflow'
                        staff.screenshot(path=str(OUT / f'{case}-staff-{width}.png'), full_page=True)
                    action(dispatcher, incident, 'disposition', {'action': 'verify_readiness', 'findings': {key: value for key, value in findings.items() if key not in ['items', 'safe_for_transport']}})
                    row = action(dispatcher, incident, 'disposition', {'action': 'redeliver_original',
                        'delivery_date': datetime.datetime.now(datetime.timezone(datetime.timedelta(hours=8))).date().isoformat(), 'delivery_window': 'morning', 'rider_profile_id': state['actors']['a']['rider_id']})
                    leg = row['source_outcome']['leg_id']
                    ok(request(rider, f'/api/logistics/legs/{leg}/accept'))
                    # The new movement retains original custody evidence; inspect its own handoff projection.
                    transfer = next(t for t in recovery(dispatcher, incident)['transfers'] if t['shipment_leg_id'] == leg)
                    ok(request(staff, f'/api/logistics/custody-transfers/{transfer["id"]}/release', {}, 'photo'))
                    ok(request(rider, f'/api/logistics/custody-transfers/{transfer["id"]}/receive', {}, 'photo'))
                    ok(request(rider, f'/api/logistics/legs/{leg}/picked-up'))
                proof = finish(rider, dispatcher, leg)
                if resolution != 'dismissed': action(dispatcher, incident, 'close', {'outcome': 'delivery_completed'})
                results.append({'case': case, 'execution': 'approved_delivery_completed', 'incident': incident, 'execution_leg': leg, 'proof': proof})
            (OUT / 'results.json').write_text(json.dumps(results, indent=2), encoding='utf-8')
            print(case, results[-1]['execution'], flush=True)
        for page in pages.values(): page.context.close()
        browser.close()
