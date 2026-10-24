"""Real Staff/Finance clicks on guarded disposable MariaDB; no provider payouts."""
import json, re, subprocess
from playwright.sync_api import sync_playwright, expect
import failed_delivery_recovery as base
from failed_delivery_batch import report, decide, handoff, complete_route, confirmed_action
base.MFA = True
ROOT, OUT = base.ROOT, base.OUT / 'release-readiness'

def close_notice(page):
    popup=page.locator('.swal2-popup')
    if popup.is_visible():
        popup.get_by_role('button',name='OK',exact=True).click()

def run(observe=None, compact_inspection=False):
    result=subprocess.run(['php','-d','extension=gd','tests/Browser/failed_delivery_finance_fixture.php'],cwd=ROOT,capture_output=True,text=True,timeout=90)
    assert result.returncode==0,(result.stdout[:500],result.stderr[:500])
    fixture=json.loads(result.stdout)
    print('Synthetic fixture created.',flush=True)
    (OUT/'finance-fixture.json').write_text(json.dumps(fixture),encoding='utf-8')
    with sync_playwright() as runtime:
        browser=runtime.chromium.launch(headless=True,args=base.CAMERA_ARGS)
        pages={name:base.login(browser,actor) for name,actor in fixture['actors'].items()}
        print('Five normal password/MFA/attendance logins completed.',flush=True)
        a,b,dispatch,staff,finance=[pages[name] for name in ['a','b','dispatcher','staff','finance']]
        try:
            base.navigate(a,'/erp/logistics/deliveries')
            incident=report(a,fixture['batch_id'])
            incident=decide(dispatch,fixture,incident,'reassign')
            base.navigate(b,'/erp/logistics/deliveries')
            confirmed_action(b,'Accept batch',f'/batches/{incident["recovery_batch_id"]}/accept')
            for leg in fixture['affected_ids']:
                handoff(a,leg,'release'); handoff(b,leg,'receive')
            base.navigate(b,'/erp/logistics/deliveries')
            confirmed_action(b,'Start batch',f'/batches/{incident["recovery_batch_id"]}/start')
            complete_route(b,dispatch,fixture)
            print('Batch recovered and return parcels received.',flush=True)
            base.navigate(staff,'/erp/staff/job-orders')
            staff.get_by_role('button',name=re.compile(r'^Refund \(')).click()
            for line in fixture['inspection']:
                if observe: observe(staff,'staff-return-queue')
                if compact_inspection: staff.set_viewport_size({'width':390,'height':844})
                row=staff.locator('tr').filter(has_text=f'Synthetic inspection shoe {fixture["inspection"].index(line)}')
                if compact_inspection: row=staff.get_by_role('region',name='Compact job orders').locator('article').filter(has_text=f'Synthetic inspection shoe {fixture["inspection"].index(line)}')
                button=row.get_by_role('button',name='Inspect returned items' if compact_inspection else 'Confirm returned item received',exact=True)
                expect(row).to_have_count(1)
                button.click()
                popup=staff.locator('.swal2-popup')
                popup.locator('#return-disp-0').select_option(line['disposition'])
                if observe: observe(staff,'staff-inspection-'+line['disposition'])
                with staff.expect_response(lambda r:'/confirm-return-received' in r.url and r.request.method=='POST') as response:
                    popup.get_by_role('button',name='Save inspection and confirm return',exact=True).click()
                assert response.value.status==200,response.value.text()[:700]
                close_notice(staff)
                base.navigate(staff,'/erp/staff/job-orders')
                staff.get_by_role('button',name=re.compile(r'^Refund \(')).click()
            print('Recovered return inspected: resellable and damaged.',flush=True)
            base.navigate(finance,'/finance?section=refund-approvals')
            assert base.post(staff,f'/api/finance/repair-refunds/{fixture["payout_id"]}/approve')['status']==403
            early=base.post(finance,f'/api/finance/repair-refunds/{fixture["payout_id"]}/execute',{'execution_mode':'manual','execution_channel':'manual_cash','execution_reference':'SYNTHETIC-NOT-A-REAL-PAYMENT'},'execution_proof_images[]')
            assert early['status']==422,early
            row=finance.locator('tr').filter(has_text=f'POS-LOCAL-{fixture["shop_id"]}')
            expect(row).to_have_count(1)
            if observe: observe(finance,'finance-refund-queue')
            row.get_by_title('View Details',exact=True).click()
            if observe: observe(finance,'finance-refund-details')
            finance.get_by_role('button',name='Approve',exact=True).click()
            popup=finance.locator('.swal2-popup')
            with finance.expect_response(lambda r:f'/repair-refunds/{fixture["payout_id"]}/approve' in r.url) as response:
                popup.get_by_role('button',name='Approve',exact=True).click()
            assert response.value.status==200,response.value.text()[:700]
            close_notice(finance)
            base.navigate(finance,'/finance?section=refund-approvals')
            finance.locator('tr').filter(has_text=f'POS-LOCAL-{fixture["shop_id"]}').get_by_title('View Details',exact=True).click()
            finance.get_by_role('button',name='Execute Manual Payout',exact=True).click()
            if observe: observe(finance,'finance-execution')
            finance.get_by_placeholder('Reference / auth code',exact=True).fill('SYNTHETIC-NOT-A-REAL-PAYMENT')
            finance.locator('#finance-execution-proof-images').set_input_files(str(base.PHOTO))
            with finance.expect_response(lambda r:f'/repair-refunds/{fixture["payout_id"]}/execute' in r.url) as response:
                finance.get_by_role('button',name='Execute',exact=True).click()
            assert response.value.status==200,response.value.text()[:700]
            close_notice(finance)
            base.navigate(a,'/erp/logistics/cod-collections')
            if observe: observe(a,'rider-cod')
            a.get_by_role('checkbox').check()
            remittance=confirmed_action(a,re.compile(r'^Submit \D100\.00$'),'/cod/remittances','Submit remittance',201)['remittance']
            base.navigate(finance,'/finance/cod-remittances')
            if observe: observe(finance,'finance-cod')
            confirmed_action(finance,'Confirm physical cash',f'/cod-remittances/{remittance["id"]}/confirm','Confirm cash')
            verified=subprocess.run(['php','tests/Browser/failed_delivery_finance_fixture.php','--verify',str(OUT/'finance-fixture.json')],cwd=ROOT,capture_output=True,text=True,timeout=30)
            assert verified.returncode==0,(verified.stdout[:500],verified.stderr[:800])
            staff.screenshot(path=str(OUT/'staff-inspected-returns.png'),full_page=True)
            finance.screenshot(path=str(OUT/'finance-cod-confirmed.png'),full_page=True)
            (OUT/'finance-browser-results.json').write_text(json.dumps({'engine':browser.version,'normal_mfa_actors':list(pages),'batch_takeover_return_receipt':'passed','staff_finance_denied':True,'unapproved_payout_denied':True,'persisted':json.loads(verified.stdout)},indent=2),encoding='utf-8')
        except Exception:
            for name,page in pages.items():
                page.screenshot(path=str(OUT/f'finance-{name}-failure.png'),full_page=True)
                (OUT/f'finance-{name}-failure.txt').write_text(page.locator('body').inner_text(),encoding='utf-8')
            raise
        finally: browser.close()
if __name__=='__main__': run()
