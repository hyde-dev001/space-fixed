"""Authenticated UI regressions. Existing environment guards require disposable loopback MariaDB."""
import datetime,json,re,subprocess,sys
from pathlib import Path
from playwright.sync_api import sync_playwright,expect
import failed_delivery_recovery as base
import failed_delivery_batch as batch
base.MFA=True
expect.set_options(timeout=30000)
OUT=base.OUT/'ux-improvements/browser';OUT.mkdir(parents=True,exist_ok=True)
(OUT/'batch-interruption').mkdir(exist_ok=True)
batch.OUT=OUT
RESULTS=[]

def capture(page,name,widths=(1440,768,390)):
    for width in widths:
        page.set_viewport_size({'width':width,'height':1000 if width==1440 else 1024 if width in [768,1024] else 844})
        page.evaluate('window.scrollTo(0,0)');page.wait_for_timeout(150)
        page.screenshot(path=str(OUT/f'{name}-{width}.png'),full_page=True)
        assert page.evaluate('document.documentElement.scrollWidth<=innerWidth+1'),(name,width,'page overflow')
    (OUT/f'{name}.txt').write_text(page.locator('body').inner_text(),encoding='utf-8')
    page.set_viewport_size({'width':1440,'height':1000})

def fixture(purpose,mode='single',stage='mid'):
    r=subprocess.run(['php','-d','extension=gd','tests/Browser/logistics_usability_fixture.php',purpose,mode,stage],cwd=base.ROOT,capture_output=True,text=True,timeout=90)
    assert r.returncode==0,r.stderr[:500]
    return json.loads(r.stdout)

def customer(browser,f):
    p=browser.new_page(viewport={'width':390,'height':844},timezone_id='Asia/Manila')
    base.navigate(p,'/login');p.locator('[name=email]').fill(f['customer']['email']);p.locator('[name=password]').fill('Local-Recovery-Test-72!')
    p.get_by_role('button',name='Sign in',exact=True).click();p.wait_for_url(lambda url:'/login' not in url and '/mfa' not in url,timeout=60000)
    base.navigate(p,f'/tracking/shipments/{f["shipment_id"]}');return p

def calendar(page,label):
    value=page.get_by_label(label,exact=True).input_value();day=datetime.date.fromisoformat(value)
    page.get_by_role('button',name=f'Open {label.lower()} picker',exact=True).click()
    popup=page.get_by_role('dialog',name=f'{label} calendar',exact=True);expect(popup).to_be_visible()
    page.keyboard.press('Escape');expect(popup).not_to_be_visible()
    page.get_by_role('button',name=f'Open {label.lower()} picker',exact=True).click()
    popup.get_by_role('button',name=f'Select {day:%B} {day.day}, {day.year}',exact=True).click()

def failed_visit(p,f):
    p.context.grant_permissions(['geolocation']);p.context.set_geolocation({'latitude':14.45,'longitude':120.98,'accuracy':5})
    base.navigate(p,'/erp/logistics/deliveries')
    collect=f['collection'];name="I've arrived for pickup" if collect else "I've arrived"
    with p.expect_response(lambda r:f'/legs/{f["leg_id"]}/arrivals' in r.url) as r:p.get_by_role('button',name=name,exact=True).click()
    assert 200<=r.value.status<300,r.value.text()[:300]
    p.get_by_role('button',name='Failed pickup' if collect else 'Report issue',exact=True).click()
    reason=p.get_by_label('Failed pickup reason' if collect else 'Issue reason',exact=True)
    if reason.evaluate('(e)=>e.tagName')=='SELECT':reason.select_option('other')
    else:
        reason.click();p.get_by_role('option',name=re.compile('Other')).click()
    p.get_by_label('Failed pickup notes' if collect else 'Issue notes',exact=True).fill('Synthetic unsuccessful visit for usability regression.')
    p.get_by_label('Failed pickup photo' if collect else 'Issue photo',exact=True).set_input_files(str(base.PHOTO))
    label='Submit failed pickup' if collect else 'Submit issue'
    batch.confirmed_action(p,label,f'/legs/{f["leg_id"]}/report-issue',label,201)

def single(browser,purpose,new_rider):
    f=fixture(purpose);(OUT/f'{purpose}-fixture.json').write_text(json.dumps(f),encoding='utf-8')
    a=base.login(browser,f['actors']['a']);d=base.login(browser,f['actors']['dispatcher']);rider=base.login(browser,f['actors']['b']) if new_rider else a
    c=customer(browser,f)
    failed_visit(a,f)
    base.navigate(d,'/erp/logistics/shipments');d.get_by_role('button',name='Open delivery',exact=True).click()
    d.get_by_role('button',name='Reschedule Pickup' if f['collection'] else 'Retry delivery',exact=True).click()
    calendar(d,'Retry date');dialog=d.get_by_role('dialog',name='Arrange another visit')
    dialog.get_by_label('Retry rider',exact=True).select_option(str(f['actors']['b' if new_rider else 'a']['rider_id']))
    dialog.get_by_label('Resolution reason').fill('Authorized synthetic retry after failed visit.')
    capture(d,purpose+'-retry-arrangement')
    with d.expect_response(lambda r:f'/legs/{f["leg_id"]}/resolve/retry' in r.url) as response:dialog.get_by_role('button',name='Authorize retry',exact=True).click()
    assert response.value.status==200,response.value.text()[:500]
    base.navigate(rider,'/erp/logistics/deliveries');expect(rider.get_by_role('button',name='Accept delivery',exact=True)).to_be_visible()
    capture(rider,purpose+'-retry-offer')
    batch.confirmed_action(rider,'Accept delivery',f'/legs/{f["leg_id"]}/accept')
    if not f['collection'] and new_rider:
        expect(rider.get_by_role('button',name='Start retry',exact=True)).to_be_disabled()
        batch.handoff(a,f['leg_id'],'release');batch.handoff(rider,f['leg_id'],'receive')
    if not f['collection']:
        base.navigate(rider,'/erp/logistics/deliveries');batch.confirmed_action(rider,'Start retry',f'/legs/{f["leg_id"]}/in-transit')
    batch.complete_route(rider,d,f)
    base.navigate(c,f'/tracking/shipments/{f["shipment_id"]}');expect(c.get_by_text('Completed',exact=True)).to_be_visible()
    capture(c,purpose+'-customer-completed',widths=(360,390,768,1440))
    RESULTS.append({'case':purpose,'ui_retry_acceptance':True,'ui_completion':True,'new_rider':new_rider})
    for p in set([a,d,rider,c]):p.context.close()
    print(purpose,'UI retry accepted and completed',flush=True)

def batch_flow(browser,purpose,route):
    f=fixture(purpose,'batch','mid');(OUT/f'{purpose}-{route}-fixture.json').write_text(json.dumps(f),encoding='utf-8');a,b,d=[base.login(browser,f['actors'][x]) for x in ['a','b','dispatcher']];c=customer(browser,f)
    base.navigate(a,'/erp/logistics/deliveries');incident=batch.report(a,f['batch_id'])
    expect(c.get_by_role('status',name='Delivery recovery')).to_be_visible(timeout=30000)
    capture(c,'customer-delayed-'+route)
    base.navigate(d,f'/erp/logistics/batches?batch={f["batch_id"]}&incident={incident["id"]}');d.get_by_role('button',name='Resolve interruption',exact=True).click()
    dialog=d.get_by_role('dialog',name='Resolve batch interruption');dialog.get_by_label('Recovery decision').select_option('reassign')
    dialog.get_by_label('Replacement rider',exact=True).select_option(str(f['actors']['b']['rider_id']));calendar(d,'Recovery date')
    dialog.get_by_label('Parcel handoff route').select_option(route);dialog.get_by_label('Decision reason').fill('Authorized synthetic recovery with confirmed parcel handoffs.')
    capture(d,'dispatcher-recovery-'+route)
    with d.expect_response(lambda r:f'/incidents/{incident["id"]}/resolve' in r.url) as response:dialog.get_by_role('button',name='Save recovery decision',exact=True).click()
    assert response.value.status==200,response.value.text()[:500]
    incident=response.value.json()['incident'];base.navigate(b,'/erp/logistics/deliveries');batch.confirmed_action(b,'Accept batch',f'/batches/{incident["recovery_batch_id"]}/accept')
    expect(b.get_by_role('button',name='Start batch',exact=True)).to_be_disabled();capture(b,'replacement-awaiting-'+route)
    base.navigate(a,'/erp/logistics/deliveries');panel=a.get_by_role('region',name='Package handoffs');card=panel.locator(':scope > div').filter(has_text=re.compile(rf'^Delivery #{f["leg_id"]}:'))
    card.get_by_label('Handoff photo').set_input_files({'name':'bad.png','mimeType':'image/png','buffer':b'not an image'})
    with a.expect_response(lambda r:'/custody-transfers/' in r.url and r.url.endswith('/release')) as response:card.get_by_role('button',name='Confirm release',exact=True).click()
    assert response.value.status==422;expect(card.get_by_role('alert')).to_contain_text('image');capture(a,'handoff-error-'+route)
    for index,leg in enumerate(f['affected_ids']):
        batch.handoff(a,leg,'release')
        if route=='via_shop':batch.handoff(d,leg,'receive',True);batch.handoff(d,leg,'release',True)
        batch.handoff(b,leg,'receive')
        if index==0:
            expect(b.get_by_role('button',name='Start batch',exact=True)).to_be_disabled();capture(b,'partial-handoffs-'+route,widths=(390,))
    base.navigate(b,'/erp/logistics/deliveries');batch.confirmed_action(b,'Start batch',f'/batches/{incident["recovery_batch_id"]}/start');batch.complete_route(b,d,f)
    base.navigate(c,'/notifications');notice=c.locator('[id^=notification-]').filter(has_text='Delivery Route Interrupted');expect(notice).to_have_count(1)
    expect(c.get_by_text('Action Required',exact=True)).not_to_be_visible();capture(c,'customer-notices-'+route,widths=(390,))
    c.set_viewport_size({'width':390,'height':844})
    link=notice.get_by_role('link',name='Delivery Route Interrupted',exact=True)
    c.get_by_role('textbox',name='Search notifications',exact=True).focus()
    for _ in range(80):
        if link.evaluate('(e)=>e===document.activeElement'): break
        c.keyboard.press('Tab')
    else: raise AssertionError('Delivery notice was not reachable by Tab')
    c.keyboard.press('Enter');c.wait_for_url('**/tracking/shipments/**')
    RESULTS.append({'case':'batch '+purpose+' '+route,'partial_blocked':True,'invalid_image_explained':True,'customer_keyboard_link':True,'persisted':batch.persisted(f)})
    for p in [a,b,d,c]:p.context.close()
    print(purpose,route,'UI batch completed',flush=True)

def run():
    modes=sys.argv[1:] or ['retail_delivery','repair_pickup','repair_return','refund_return','direct','via_shop']
    with sync_playwright() as rt:
        browser=rt.chromium.launch(headless=True)
        try:
            for mode in modes:
                if mode in ['direct','via_shop']:batch_flow(browser,'retail_delivery' if mode=='direct' else 'repair_return',mode)
                else:single(browser,mode,mode!='repair_return')
                (OUT/'results.json').write_text(json.dumps({'engine':browser.version,'cases':RESULTS},indent=2),encoding='utf-8')
        except Exception:
            for i,context in enumerate(browser.contexts):
                for j,p in enumerate(context.pages):
                    p.screenshot(path=str(OUT/f'failure-{i}-{j}.png'),full_page=True)
                    (OUT/f'failure-{i}-{j}.txt').write_text(p.locator('body').inner_text(),encoding='utf-8')
            raise
        finally:browser.close()
if __name__=='__main__':run()
