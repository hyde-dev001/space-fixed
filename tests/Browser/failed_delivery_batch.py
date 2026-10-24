"""Batch interruption journeys using the existing guarded HTTP/MariaDB app and real MFA."""
import datetime
import json
import re
import subprocess
import sys
from playwright.sync_api import sync_playwright, expect
from failed_delivery_recovery import ROOT, OUT, URL, PHOTO, login, navigate, post, ok

expect.set_options(timeout=30000)

RESULTS = OUT / ('batch-interruption/browser-completion-results.json' if any(value.endswith('_complete') for value in sys.argv) else 'batch-interruption/browser-results.json')


def confirmed_action(page, label, endpoint, popup_label=None, status=200):
    page.get_by_role('button', name=label, exact=True).click()
    popup = page.locator('.swal2-popup')
    expect(popup).to_be_visible()
    with page.expect_response(lambda response: endpoint in response.url and response.request.method == 'POST') as response:
        popup.get_by_role('button', name=popup_label or label, exact=True).click()
    assert response.value.status == status, (endpoint, response.value.status, response.value.text()[:500])
    return response.value.json()


def complete_route(rider, dispatcher, fixture):
    rider.context.grant_permissions(['geolocation'])
    rider.context.set_geolocation({'latitude': 14.45, 'longitude': 120.98, 'accuracy': 5})
    for leg_id in fixture['affected_ids']:
        navigate(rider, '/erp/logistics/deliveries')
        if rider.get_by_role('button', name='Confirm pickup', exact=True).count():
            pickup_arrival = rider.get_by_role('button', name="I've arrived for pickup", exact=True)
            if pickup_arrival.count():
                with rider.expect_response(lambda response: f'/legs/{leg_id}/arrivals' in response.url) as response:
                    pickup_arrival.click()
                assert 200 <= response.value.status < 300, response.value.text()[:500]
            confirmed_action(rider, 'Confirm pickup', f'/legs/{leg_id}/picked-up')
            navigate(rider, '/erp/logistics/deliveries')
        start = 'Start return to shop' if fixture['purpose'] == 'refund_return' else 'Start delivery'
        if rider.get_by_role('button', name=start, exact=True).count():
            confirmed_action(rider, start, f'/legs/{leg_id}/')
            navigate(rider, '/erp/logistics/deliveries')
        if fixture['purpose'] == 'refund_return':
            rider.get_by_label('Return handoff photo', exact=True).set_input_files(str(PHOTO))
            confirmed_action(rider, 'Confirm return handoff', '/handoff')
        else:
            with rider.expect_response(lambda response: f'/legs/{leg_id}/arrivals' in response.url) as response:
                rider.get_by_role('button', name="I've arrived", exact=True).click()
            assert 200 <= response.value.status < 300, response.value.text()[:500]
            expect(rider.get_by_label('Delivery proof', exact=True)).to_be_attached(timeout=30000)
            rider.get_by_label('Delivery proof', exact=True).set_input_files(str(PHOTO))
            confirmed_action(rider, 'Submit delivery proof', f'/legs/{leg_id}/proof', 'Submit proof', 201)
        navigate(dispatcher, '/erp/logistics/shipments')
        named_delivery = dispatcher.get_by_role('button', name=f'Open delivery for Shipment {fixture["shipment_numbers"][str(leg_id)]}', exact=True)
        (named_delivery if named_delivery.count() else dispatcher.get_by_role('button', name='Open delivery', exact=True)).click()
        if fixture['purpose'] == 'refund_return':
            confirmed_action(dispatcher, 'Confirm return received', '/receipt', 'Confirm')
        else:
            confirmed_action(dispatcher, 'Confirm delivery', '/approve', 'Confirm')
    assert persisted(fixture)['affected_delivered']


def report(page, batch_id):
    page.get_by_role('button', name='Report vehicle breakdown', exact=True).click()
    dialog = page.get_by_role('dialog', name='Report vehicle breakdown', exact=True)
    dialog.get_by_label('Breakdown details').fill('Synthetic vehicle interruption.')
    dialog.get_by_label('Vehicle evidence').set_input_files(str(PHOTO))
    with page.expect_response(lambda response: f'/batches/{batch_id}/incidents' in response.url) as response:
        dialog.get_by_role('button', name='Hold remaining stops').click()
    assert response.value.status == 201, response.value.text()[:500]
    incident = response.value.json()['incident']
    expect(dialog).not_to_be_visible(timeout=20000)
    expect(page.get_by_text('Batch interrupted', exact=True)).to_be_visible()
    return incident


def decide(page, fixture, incident, choice, route='direct'):
    navigate(page, f'/erp/logistics/batches?batch={fixture["batch_id"]}&incident={incident["id"]}')
    page.get_by_role('button', name='Resolve interruption', exact=True).click()
    dialog = page.get_by_role('dialog', name='Resolve batch interruption', exact=True)
    dialog.get_by_label('Recovery decision').select_option(choice)
    if choice == 'reassign':
        dialog.get_by_label(re.compile(r'^Replacement rider')).select_option(str(fixture['actors']['b']['rider_id']))
        dialog.get_by_label('Parcel handoff route').select_option(route)
        for agreement in dialog.get_by_label(re.compile(r'^Customer agreement for')).all():
            agreement.fill('Synthetic customer agreed to the current local test window.')
    dialog.get_by_label('Decision reason').fill('Authorized synthetic vehicle recovery.')
    with page.expect_response(lambda response: f'/incidents/{incident["id"]}/resolve' in response.url) as response:
        dialog.get_by_role('button', name='Save recovery decision').click()
    assert response.value.status == 200, response.value.text()[:600]
    value = response.value.json()['incident']
    expect(dialog).not_to_be_visible(timeout=20000)
    return value


def handoff(page, leg_id, action, dispatcher=False):
    navigate(page, '/erp/logistics/batches' if dispatcher else '/erp/logistics/deliveries')
    panel = page.get_by_role('region', name='Package handoffs', exact=True)
    card = panel.locator(':scope > div').filter(has_text=re.compile(rf'^Delivery #{leg_id}:'))
    expect(card).to_have_count(1)
    card.get_by_label('Handoff photo').set_input_files(str(PHOTO))
    with page.expect_response(lambda response: '/custody-transfers/' in response.url and response.url.endswith('/' + action)) as response:
        card.get_by_role('button', name='Confirm release' if action == 'release' else 'Confirm receipt', exact=True).click()
    assert response.value.status == 200, response.value.text()[:500]
    return response.value.json()['transfer']


def persisted(fixture):
    path = OUT / 'batch-interruption/current-fixture.json'
    path.write_text(json.dumps(fixture), encoding='utf-8')
    result = subprocess.run(['php', 'tests/Browser/failed_delivery_batch_records.php', str(path)], cwd=ROOT,
                            capture_output=True, text=True, timeout=30)
    assert result.returncode == 0, (result.stdout[:800], result.stderr[:800])
    return json.loads(result.stdout)


def toggle_fixture_module(fixture, value):
    path = OUT / 'batch-interruption/module-fixture.json'
    path.write_text(json.dumps(fixture), encoding='utf-8')
    result = subprocess.run(['php', 'tests/Browser/failed_delivery_batch_worker.php', 'toggle', str(path), value],
        cwd=ROOT, capture_output=True, text=True, timeout=30)
    assert result.returncode == 0 and json.loads(result.stdout) == {'module': value}, result.stdout[:500]


def run():
    cases = [value for value in sys.argv[1:] if value not in ['--batch', '--mfa']]
    cases = cases or ['retail_delivery@before_same', 'retail_delivery@mid_same', 'retail_delivery@last_direct',
        'retail_delivery@mid_direct', 'retail_delivery@mid_shop', 'retail_delivery@safekeeping',
        'retail_delivery@repeat', 'retail_delivery@appointment', 'repair_pickup@before_same',
        'repair_pickup@mid_direct', 'repair_return@mid_shop', 'refund_return@mid_direct']
    results = json.loads(RESULTS.read_text(encoding='utf-8')) if cases and RESULTS.exists() else []
    with sync_playwright() as runtime:
        browser = runtime.chromium.launch(headless=True)
        for case in cases:
            purpose, variant = case.split('@')
            stage = 'before' if variant.startswith('before') else 'last' if variant.startswith('last') else 'appointment' if variant == 'appointment' else 'mid'
            result = subprocess.run(['php', 'tests/Browser/failed_delivery_fixture.php', '--batch', purpose, stage], cwd=ROOT,
                                    capture_output=True, text=True, timeout=90)
            assert result.returncode == 0, result.stderr[:1000]
            fixture = json.loads(result.stdout)
            pages = {name: login(browser, actor) for name, actor in fixture['actors'].items()}
            a, b, dispatcher = pages['a'], pages['b'], pages['dispatcher']
            errors = []
            for page in pages.values():
                page.on('pageerror', lambda error: errors.append(str(error)))
            navigate(a, '/erp/logistics/deliveries')
            incident = report(a, fixture['batch_id'])
            duplicate = ok(post(a, f'/api/logistics/batches/{fixture["batch_id"]}/incidents',
                {'request_key': incident['request_key'], 'notes': 'Synthetic vehicle interruption.'}, 'photo_files[]'))
            assert duplicate['incident']['id'] == incident['id']
            blocked = post(a, f'/api/logistics/legs/{fixture["leg_id"]}/in-transit')
            assert blocked['status'] == 422 and 'batch_interruption' in blocked['value'].get('errors', {}), blocked
            partial_blocked = False
            if '_same' in variant or variant == 'repeat':
                incident = decide(dispatcher, fixture, incident, 'resume')
                if stage == 'before':
                    expect(a.get_by_role('button', name='Start batch', exact=True)).to_be_enabled(timeout=30000)
                    confirmed_action(a, 'Start batch', f'/batches/{fixture["batch_id"]}/start')
                else:
                    navigate(a, '/erp/logistics/deliveries')
                    expect(a.get_by_role('button', name='Report vehicle breakdown', exact=True)).to_be_visible()
                if variant == 'repeat':
                    incident = report(a, fixture['batch_id'])
                    incident = decide(dispatcher, fixture, incident, 'resume')
                outcome = 'same_rider_continued'
            elif variant == 'safekeeping':
                incident = decide(dispatcher, fixture, incident, 'return_to_shop')
                for leg_id in fixture['affected_ids']:
                    handoff(a, leg_id, 'release')
                    handoff(dispatcher, leg_id, 'receive', dispatcher=True)
                state = persisted(fixture)
                assert state['batch_status'] == 'interrupted' and state['all_at_shop'] and state['pending_transfers'] == 0, state
                outcome = 'safekeeping_held_without_financial_changes'
            else:
                route = 'via_shop' if '_shop' in variant else 'direct'
                incident = decide(dispatcher, fixture, incident, 'reassign', route)
                recovery_id = incident['recovery_batch_id']
                navigate(a, '/erp/logistics/deliveries')
                expect(a.get_by_role('progressbar', name=f'Batch progress: {len(fixture["completed_ids"])} of {len(fixture["leg_ids"])} delivered', exact=True)).to_be_visible()
                navigate(b, '/erp/logistics/deliveries')
                confirmed_action(b, 'Accept batch', f'/batches/{recovery_id}/accept')
                for index, leg_id in enumerate(fixture['affected_ids']):
                    transfer = handoff(a, leg_id, 'release')
                    wrong = post(a, f'/api/logistics/custody-transfers/{transfer["id"]}/receive', {}, 'photo')
                    assert wrong['status'] == 403, wrong
                    if route == 'via_shop':
                        handoff(dispatcher, leg_id, 'receive', dispatcher=True)
                        handoff(dispatcher, leg_id, 'release', dispatcher=True)
                    handoff(b, leg_id, 'receive')
                    navigate(b, '/erp/logistics/deliveries')
                    if index < len(fixture['affected_ids']) - 1:
                        expect(b.get_by_role('button', name='Start batch', exact=True)).to_be_disabled()
                        assert post(b, f'/api/logistics/batches/{recovery_id}/start')['status'] == 422
                        partial_blocked = True
                if variant == 'off':
                    # Environment control on the guarded disposable fixture, not an owner-login/UI claim.
                    toggle_fixture_module(fixture, 'off')
                    off_result = post(b, f'/api/logistics/batches/{recovery_id}/start')
                    assert off_result['status'] in [403, 422], off_result
                    toggle_fixture_module(fixture, 'on')
                    on_result = post(b, f'/api/logistics/batches/{recovery_id}/start')
                    assert on_result['status'] == 422 and 'batch_interruption' in on_result['value'].get('errors', {}), on_result
                    incident = decide(dispatcher, fixture, incident, 'authorize_replacement')
                    navigate(b, '/erp/logistics/deliveries')
                expect(b.get_by_role('button', name='Start batch', exact=True)).to_be_enabled()
                confirmed_action(b, 'Start batch', f'/batches/{recovery_id}/start')
                outcome = 'replacement_route_started_after_all_confirmed_handoffs'
            if variant.endswith('_complete'):
                complete_route(a if '_same' in variant else b, dispatcher, fixture)
                outcome += '_then_all_stops_completed_through_ui'
            state = persisted(fixture)
            assert state['attempts'] == 0 and state['completed_preserved'] and state['refunds_unchanged'], state
            assert not errors, errors
            # Screenshots contain only this disposable fixture's synthetic identities/evidence.
            a.screenshot(path=str(OUT / f'batch-interruption/{purpose}-{variant}.png'), full_page=True)
            results = [row for row in results if row['case'] != case]
            results.append({'case': case, 'batch_id': fixture['batch_id'], 'leg_ids': fixture['leg_ids'],
                'login': 'normal_password_totp', 'attendance': 'normal_clock_in', 'new_actions': 'real_ui_and_transport',
                'stale_request': 'backend_stopped', 'partial_handoffs_blocked': partial_blocked, 'outcome': outcome,
                'state': state, 'page_errors': errors})
            RESULTS.write_text(json.dumps(results, indent=2), encoding='utf-8')
            print(case + ' passed', flush=True)
            for page in pages.values():
                page.context.close()
        browser.close()
