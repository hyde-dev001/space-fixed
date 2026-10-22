"""Local, synthetic-only OCR form regression. Requires Python Playwright and a
disposable Laravel app configured for the local SMTP capture port below.
No OTP, email body, or real document content is written to the report.
"""

import argparse
import base64
import email
import hashlib
import json
import pathlib
import queue
import re
import socketserver
import threading
import time
from urllib.parse import urlsplit

from playwright.sync_api import sync_playwright, TimeoutError as PlaywrightTimeoutError


ROOT = pathlib.Path(__file__).resolve().parents[2]
SLOTS = ['business_registration', 'mayors_permit', 'bir_certificate', 'valid_id']
CAPTURE = queue.Queue()
SMTP_EVENTS = []


class SmtpCapture(socketserver.StreamRequestHandler):
    def handle(self):
        self.wfile.write(b'220 localhost synthetic-test-capture\r\n')
        while line := self.rfile.readline(8192):
            command = line.upper()
            SMTP_EVENTS.append(command.split(b' ', 1)[0].decode('ascii', errors='replace').strip())
            if command.startswith((b'EHLO', b'HELO')):
                self.wfile.write(b'250 localhost\r\n')
            elif command.startswith(b'DATA'):
                self.wfile.write(b'354 End with dot\r\n')
                body = bytearray()
                while (part := self.rfile.readline(8192)) != b'.\r\n':
                    if not part or len(body) > 100000:
                        return
                    body.extend(part)
                message = email.message_from_bytes(bytes(body))
                content = '\n'.join((part.get_payload(decode=True) or b'').decode('utf-8', errors='replace') for part in message.walk() if not part.is_multipart())
                match = re.search(r'code is (\d{6})', content)
                SMTP_EVENTS.append({'bytes': len(body), 'has_code': bool(match)})
                if match:
                    CAPTURE.put(match.group(1))
                self.wfile.write(b'250 Captured locally\r\n')
            elif command.startswith(b'QUIT'):
                self.wfile.write(b'221 Bye\r\n')
                break
            else:
                self.wfile.write(b'250 OK\r\n')


# Read-only observation of the mounted development React component. It neither
# writes state nor substitutes the reader, classifier, worker, or form handler.
OBSERVE = """async () => {
  const app = document.querySelector('#app');
  const key = Object.keys(app).find(k => k.startsWith('__reactContainer$'));
  const root = app[key]?.stateNode?.current;
  const stack = root ? [root] : [];
  let component;
  while (stack.length) {
    const node = stack.pop();
    if (node.type?.name === 'ShopOwnerRegistration') { component = node; break; }
    if (node.child) stack.push(node.child);
    if (node.sibling) stack.push(node.sibling);
  }
  if (!component) return { error: 'registration component not found' };
  const values = [];
  for (let hook = component.memoizedState; hook; hook = hook.next) values.push(hook.memoizedState);
  const maps = values.map(value => value?.current).filter(value => value instanceof Map);
  const evidence = maps.find(map => [...map.values()].some(value => value?.passes || value?.entry));
  const states = values.find(value => value && ['business_registration', 'mayors_permit', 'bir_certificate', 'valid_id'].some(slot => value[slot]?.status));
  const statusProps = [];
  function visit(node) {
    if (!node) return;
    if (node.type?.name === 'ShopOwnerOcrStatus') statusProps.push(node.memoizedProps.state);
    for (let child=node.child; child; child=child.sibling) visit(child);
  }
  visit(component);
  const renderedStates = Object.fromEntries(['business_registration','mayors_permit','bir_certificate','valid_id'].map((slot,index)=>[slot,statusProps[index]]).filter(([,state])=>state));
  const summaries = {};
  for (const [slot, item] of evidence ?? []) {
    summaries[slot] = {
      execution: item.passes ? 'completed' : 'technical_failure',
      passes: item.passes?.map(pass => ({characters: pass.text.length, lines: pass.lines.length,
        confidence: pass.confidence, image_activity: pass.image_activity, document_layout: pass.document_layout})),
    };
  }
  for (const [slot, state] of Object.entries(statusProps.length ? renderedStates : (states ?? {}))) {
    summaries[slot] = {...summaries[slot], status: state.status, relevance: state.relevance,
      score: state.score, family: state.entry?.detected_family,
      checks: state.entry?.checks, reasons: state.entry?.reason_codes};
  }
  const selections=maps.find(map=>[...map.values()].some(value=>value?.file instanceof File));
  for(const [slot,selection] of selections ?? []) {
    const digest=await crypto.subtle.digest('SHA-256',await selection.file.arrayBuffer());
    summaries[slot]={...summaries[slot],file_name:selection.file.name,file_size:selection.file.size,
      file_sha256:Array.from(new Uint8Array(digest)).map(n=>n.toString(16).padStart(2,'0')).join('')};
  }
  return summaries;
}"""


def make_fixtures(page, target):
    target.mkdir(parents=True, exist_ok=True)
    for source in (ROOT / 'tests/Fixtures/ShopOwnerOcr/images').glob('*.svg'):
        png = page.evaluate("""async svg => {
          const img = new Image(); img.src = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(svg);
          await img.decode(); const c = document.createElement('canvas'); c.width=img.width; c.height=img.height;
          c.getContext('2d').drawImage(img,0,0); return c.toDataURL('image/png').split(',')[1];
        }""", source.read_text())
        (target / (source.stem + '.png')).write_bytes(base64.b64decode(png))
    dti=(ROOT/'tests/Fixtures/ShopOwnerOcr/images/dti-registration.svg').read_text()
    variants={
        'dti-conflict':dti.replace('Sample Shoe House','Other Shoe House'),
        'dti-uncertain':dti.replace('Registered Business Name: Sample Shoe House','Registered Business Name:').replace('Proprietor: Mira Sample','Proprietor:'),
        'dti-poor-photo':dti,
    }
    for name,svg in variants.items():
        png=page.evaluate("""async ({svg,blur})=>{const image=new Image();image.src='data:image/svg+xml;charset=utf-8,'+encodeURIComponent(svg);await image.decode();const c=document.createElement('canvas');c.width=image.width;c.height=image.height;const x=c.getContext('2d');x.fillStyle='#fff';x.fillRect(0,0,c.width,c.height);if(blur)x.filter='blur(2px)';x.drawImage(image,0,0);return c.toDataURL('image/png').split(',')[1];}""",{'svg':svg,'blur':name=='dti-poor-photo'})
        (target/(name+'.png')).write_bytes(base64.b64decode(png))
    for kind in ['selfie', 'cartoon-meme', 'blank', 'random-photograph', 'unrelated-text']:
        png = page.evaluate("""kind => {
          const c=document.createElement('canvas'); c.width=1000;c.height=1400; const x=c.getContext('2d');
          x.fillStyle='#fff';x.fillRect(0,0,c.width,c.height);
          if(kind==='selfie'||kind==='cartoon-meme') {
            const g=x.createLinearGradient(0,0,1000,1400);g.addColorStop(0,'#afb3c2');g.addColorStop(1,'#6c756f');
            x.fillStyle=g;x.fillRect(0,0,1000,1400);
            x.fillStyle='#89918a';for(let y=60;y<1400;y+=180)x.fillRect(0,y,1000,12);
            x.fillStyle='#273b68';x.beginPath();x.ellipse(500,1390,450,430,0,0,7);x.fill();
            x.fillStyle='#c79572';x.fillRect(400,900,200,270);
            x.fillStyle='#d5a685';x.beginPath();x.ellipse(500,620,295,410,0,0,7);x.fill();
            x.fillStyle='#3e2e2a';x.beginPath();x.ellipse(500,320,290,160,0,Math.PI,2*Math.PI);x.fill();
            x.fillStyle='#eee';for(const cx of [390,610]){x.beginPath();x.ellipse(cx,570,62,24,0,0,7);x.fill();}
            x.fillStyle='#49392f';for(const cx of [390,610]){x.beginPath();x.arc(cx,570,19,0,7);x.fill();}
            x.strokeStyle='#996c55';x.lineWidth=12;x.beginPath();x.moveTo(490,630);x.lineTo(465,735);x.lineTo(525,740);x.stroke();
            x.strokeStyle='#814f48';x.beginPath();x.ellipse(500,830,85,20,0,0,Math.PI);x.stroke();
            if(kind==='cartoon-meme'){x.fillStyle='#fff';x.fillRect(20,20,960,125);x.fillRect(20,1200,960,150);x.fillStyle='#111';x.font='bold 42px Arial';x.fillText('WHEN THE SHOES ARE ON SALE',40,95);x.fillText('BUT YOU FORGOT YOUR WALLET',35,1290);}
          } else if(kind==='random-photograph') {
            const g=x.createLinearGradient(0,0,0,1400);g.addColorStop(0,'#70ace0');g.addColorStop(.5,'#dfedf3');g.addColorStop(.51,'#6e9b48');g.addColorStop(1,'#244530');x.fillStyle=g;x.fillRect(0,0,1000,1400);
            for(let i=0;i<10;i++){x.fillStyle=i%2?'#4b6d55':'#608171';x.beginPath();x.moveTo(i*150-200,900);x.lineTo(i*150+80,350+(i%3)*70);x.lineTo(i*150+300,900);x.fill();}
            x.fillStyle='#c7b78b';x.beginPath();x.moveTo(440,1400);x.lineTo(520,800);x.lineTo(570,800);x.lineTo(750,1400);x.fill();
          } else if(kind==='unrelated-text') {
            x.fillStyle='#111';x.font='bold 48px Arial';x.fillText('WEEKEND SHOPPING LIST',70,180);x.font='36px Arial';['Buy bread and apples','Pick up the blue shoes','Water the garden','Walk in the park'].forEach((t,i)=>x.fillText(t,70,350+i*130));
          }
          return c.toDataURL('image/png').split(',')[1];
        }""", kind)
        (target / (kind + '.png')).write_bytes(base64.b64decode(png))


def dismiss(page, wait_loading=False):
    # Wait out a closing alert before interacting with a newly opened one.
    deadline = time.monotonic() + (15 if wait_loading else 5)
    while page.locator('.swal2-container:visible').count():
        if page.locator('.shop-owner-ocr-loading:visible').count():
            if not wait_loading:
                return
            assert time.monotonic() < deadline, 'Settled scan left its loading dialog open'
            page.wait_for_timeout(100)
            continue
        try:
            page.locator('.swal2-confirm:visible').click(timeout=500)
        except PlaywrightTimeoutError:
            if time.monotonic() >= deadline:
                raise
        page.wait_for_timeout(100)


def register_details(page):
    dismiss(page)
    for field, value in {'firstName': 'Mira', 'lastName': 'Sample', 'age': '30',
                         'email': f'ocr-browser-{time.time_ns()}@gmail.com', 'phone': '09171234567'}.items():
        page.locator('#' + field).fill(value)
    with page.expect_response(lambda response: response.url.endswith('/shop-owner/email-verification/send-code')) as sent:
        page.get_by_role('button', name='Send Code', exact=True).click()
    print(json.dumps({'otp_url': sent.value.url, 'otp_send_status': sent.value.status, 'otp_request_succeeded': sent.value.json().get('success'), 'already_verified': sent.value.json().get('already_verified')}), flush=True)
    try:
        code = CAPTURE.get(timeout=15)
    except queue.Empty:
        raise RuntimeError(f'Local SMTP capture did not receive OTP; protocol summary: {SMTP_EVENTS}') from None
    dismiss(page)
    page.locator('#emailVerificationCode').fill(code)
    with page.expect_response(lambda response: response.url.endswith('/shop-owner/email-verification/verify-code')) as verified:
        page.get_by_role('button', name='Verify Email', exact=True).click()
    assert verified.value.json().get('success'), 'Normal local OTP verification failed.'
    dismiss(page)
    page.get_by_role('button', name='Use My GPS', exact=True).click()
    page.wait_for_function("document.querySelector('#addressBarangay')?.value.length > 0", timeout=45000)
    page.get_by_role('button', name='Next', exact=True).click()
    page.locator('#businessName').wait_for()
    page.locator('#businessName').fill('Sample Shoe House')
    page.locator('#businessAddress').fill('123 Example Street, Barangay Sample, Imus, Cavite')
    page.locator('#postalCode').fill('4103')
    page.get_by_role('combobox').filter(has_text='Select shop type').click()
    page.get_by_role('option', name='Retail', exact=True).click()
    with page.expect_response(lambda response:'/api/address/geocode?' in response.url) as shop_lookup:
        page.get_by_role('button', name='Use My GPS', exact=True).click()
    assert shop_lookup.value.ok, 'Normal shop reverse geocoding failed.'
    shop_lookup.value.json()
    page.wait_for_function("document.querySelector('#businessAddress')?.value !== '123 Example Street, Barangay Sample, Imus, Cavite'")
    page.locator('#businessAddress').fill('123 Example Street, Barangay Sample, Imus, Cavite')
    page.get_by_role('button', name='Next', exact=True).click()
    page.wait_for_timeout(200)
    if page.locator('.swal2-html-container:visible').count():
        dismiss(page)
    page.get_by_role('heading',name='Document Upload',exact=True).wait_for()
    return page.locator('input,select,button').evaluate_all('(els)=>els.map(e=>({tag:e.tagName,type:e.type,id:e.id,text:e.textContent.trim().slice(0,70),value:e.value}))')


def upload(page, index, path, name=None):
    files=page.locator('.dropzone input[type=file]')
    payload=str(path) if name is None else {'name':name,'mimeType':'image/png','buffer':path.read_bytes()}
    # A normal react-dropzone chooser clears the native value on click. Do the
    # same before direct set_input_files so reselecting the same file fires change.
    files.nth(index).set_input_files([])
    files.nth(index).set_input_files(payload)
    selected=files.nth(index).evaluate('(element)=>({name:element.files[0]?.name,size:element.files[0]?.size})')
    dismiss(page)
    assert selected['name']==(name or path.name) and selected['size']==path.stat().st_size
    return selected


def wait_scans(page):
    deadline=time.monotonic()+180
    while time.monotonic()<deadline:
        summary=page.evaluate(OBSERVE)
        if len(summary)==4 and all(item.get('status') not in [None,'queued','loading','recognizing'] for item in summary.values()):
            return summary
        page.wait_for_timeout(150)
    raise AssertionError('Required scans did not settle: '+json.dumps(page.evaluate(OBSERVE)))


def complete_metadata(page):
    page.locator('#business_registration_expiration_mode').click()
    page.locator('[role=listbox]:visible').get_by_role('option',name='Has an expiration date',exact=True).click()
    page.locator('#business_registration_issued_on').fill('2025-01-15')
    page.locator('#business_registration_expiration_date').fill('2030-01-15')
    page.locator('#mayors_permit_issued_on').fill('2026-01-10')
    page.locator('#mayors_permit_expiration_date').fill('2027-12-31')


def review(page):
    dismiss(page, wait_loading=True)
    page.get_by_role('button',name='Next',exact=True).click()
    if not page.locator('#shopOwnerTermsAccepted').is_checked():
        page.locator('#shopOwnerTermsAccepted').click()
        page.locator('.terms-modal__scroll').evaluate('(element)=>{element.scrollTop=element.scrollHeight;element.dispatchEvent(new Event("scroll"));}')
        page.get_by_role('button',name='Accept',exact=True).click()
        page.wait_for_function("document.querySelector('#shopOwnerTermsAccepted')?.checked")


def baseline_form(page, target):
    register_details(page)
    images=target/'images'
    for index,name in enumerate(['selfie','mayors-permit-imus-layout','bir-form-2303','national-id-filipino']):
        upload(page,index,images/(name+'.png'))
    summary=wait_scans(page)
    (target/'baseline-scans.json').write_text(json.dumps(summary,indent=2))
    complete_metadata(page)
    review(page)
    button=page.get_by_role('button',name='Submit Registration',exact=True)
    enabled=button.is_enabled()
    handler='blocked'
    if enabled:
        button.click()
        page.get_by_role('heading',name='Confirm Submission',exact=True).wait_for()
        handler='allowed_confirmation'
        page.get_by_role('button',name='Cancel',exact=True).click()
    report={'case':'synthetic-selfie-before-fix','slots':summary,'submit_enabled':enabled,'handler':handler}
    (target/'baseline-form.json').write_text(json.dumps(report,indent=2))
    page.screenshot(path=str(target/'baseline-form.png'),full_page=True)
    print(json.dumps(report),flush=True)


def capture_transport(page):
    # Observe the real handler's final transport boundary. OCR, relevance, UI,
    # CSRF retrieval and all form validation remain real. No registration writes
    # or external notifications are needed for the case matrix.
    page.evaluate("""async ()=>{
      const url=performance.getEntriesByType('resource').map(e=>e.name).find(url=>url.includes('/@inertiajs_react.js'));
      if(!url)throw Error('Inertia module not found');
      const {router}=await import(url);window.__ocrPosts=[];
      router.post=(url,data,options)=>{
        const record={endpoint:new URL(url,location.origin).pathname,ready:false};window.__ocrPosts.push(record);
        Promise.all(['business_registration','mayors_permit','bir_certificate','valid_id'].map(async slot=>{
          const file=data.get(slot);if(!(file instanceof File))return {slot,retained:true};
          const digest=await crypto.subtle.digest('SHA-256',await file.arrayBuffer());
          return {slot,name:file.name,size:file.size,sha256:Array.from(new Uint8Array(digest)).map(n=>n.toString(16).padStart(2,'0')).join('')};
        })).then(files=>{record.files=files;record.has_screening=Boolean(data.get('ocr_screening'));record.ready=true;options.onError?.({mayors_permit:'Synthetic browser transport captured; no application was written.'});});
      };
    }""")


def attempt_submission(page):
    if page.locator('.shop-owner-ocr-loading:visible').count():
        # The final button cannot be reached through the loading overlay. Unit
        # tests separately exercise the pending guard inside the submit handler.
        assert page.get_by_role('button', name='Submit Registration', exact=True).count() == 0
        return {'submit_enabled': False, 'handler': 'not_reached_loading_dialog', 'payload': None}
    selected=page.evaluate(OBSERVE)
    original_hashes=[selected[slot]['file_sha256'] for slot in SLOTS]
    review(page)
    button=page.get_by_role('button',name='Submit Registration',exact=True)
    enabled=button.is_enabled()
    before=page.evaluate('window.__ocrPosts.length')
    if enabled:
        button.click()
        page.get_by_role('heading',name='Confirm Submission',exact=True).wait_for()
        page.get_by_role('button',name='Yes, Submit',exact=True).click()
        page.wait_for_function('(before)=>window.__ocrPosts.length>before && window.__ocrPosts.at(-1).ready',arg=before)
        dismiss(page)
    else:
        # Explicitly exercise the actual React handler independently of the
        # disabled DOM button; do not alter its guard or manufacture evidence.
        button.evaluate("""element=>{const key=Object.keys(element).find(key=>key.startsWith('__reactProps$'));element[key].onClick({preventDefault(){}});}""")
        page.get_by_role('heading',name='Document Upload',exact=True).wait_for()
    sent=page.evaluate('window.__ocrPosts.length')>before
    if sent:
        payload=page.evaluate('window.__ocrPosts.at(-1)')
        assert [file['sha256'] for file in payload['files']]==original_hashes, 'Submission changed or mixed the original files'
    return {'submit_enabled':enabled,'handler':'transport_dispatched' if sent else 'stopped',
            'payload':page.evaluate('window.__ocrPosts.at(-1)') if sent else None}


def verify_form(page,target):
    register_details(page)
    capture_transport(page)
    images=target/'images'
    report={'fixture_source':'repository synthetic text documents and procedural portrait/cartoon/landscape; no real person or private document',
            'transport':'actual handler observed at Inertia post; synthetic transport intercepted before backend writes', 'cases':[]}

    def record(name,summary,outcome,expected_block,slot='business_registration'):
        item={'case':name,'upload_accepted':True,'slots':summary,**outcome}
        report['cases'].append(item)
        (target/'browser-results.json').write_text(json.dumps(report,indent=2))
        print(json.dumps({'case':name,'status':summary.get(slot,{}).get('status'),'family':summary.get(slot,{}).get('family'),
                          'relevance':summary.get(slot,{}).get('relevance'),'score':summary.get(slot,{}).get('score'),
                          'button_enabled':outcome['submit_enabled'],'handler':outcome['handler']}),flush=True)
        assert outcome['submit_enabled'] != expected_block, name+': incorrect button state'
        assert (outcome['handler'] in ['stopped', 'not_reached_loading_dialog']) == expected_block, name+': incorrect handler gate'
        if outcome.get('payload'):
            for file in outcome['payload']['files']:
                assert not file.get('retained'), 'Fresh registration lost an original file'
        if name in ['selfie','blocked-worker-asset']:
            page.screenshot(path=str(target/(name+'-form.png')),full_page=True)
        return item

    complete_metadata(page)
    for index,name in enumerate(['dti-registration','mayors-permit-imus-layout','bir-form-2303','national-id-filipino']):
        upload(page,index,images/(name+'.png'))
    # Hold actual worker delivery so a fast machine cannot finish before the
    # pending button and handler are observed. Release it for real recognition.
    network=page.context.new_cdp_session(page)
    network.send('Network.enable')
    network.send('Network.emulateNetworkConditions',{'offline':False,'latency':3000,'downloadThroughput':-1,'uploadThroughput':-1})
    upload(page,3,images/'national-id-filipino.png',name='pending-national-id.png')
    pending=page.evaluate(OBSERVE)
    assert any(item.get('status') in ['queued','loading','recognizing'] for item in pending.values()), 'Pending scan was not observed'
    record('pending-required-scans',pending,attempt_submission(page),True,'valid_id')
    network.send('Network.emulateNetworkConditions',{'offline':False,'latency':0,'downloadThroughput':-1,'uploadThroughput':-1})
    network.detach()
    summary=wait_scans(page)
    for slot,family in [('business_registration','dti_registration'),('mayors_permit','mayors_permit'),('bir_certificate','bir_certificate'),('valid_id','national_id')]:
        assert summary[slot]['family']==family,(slot,summary[slot])
    record('correct-dti-permit-bir-national-id',summary,attempt_submission(page),False)

    # Original preview bytes and slot identity, independently from OCR.
    previews=page.locator('.dropzone img').evaluate_all("""async images=>Promise.all(images.map(async image=>({
      alt:image.alt,fit:getComputedStyle(image).objectFit,width:image.naturalWidth,height:image.naturalHeight,
      sha256:Array.from(new Uint8Array(await crypto.subtle.digest('SHA-256',await(await fetch(image.src)).arrayBuffer()))).map(n=>n.toString(16).padStart(2,'0')).join('')
    })))""")
    expected=[hashlib.sha256((images/(name+'.png')).read_bytes()).hexdigest() for name in ['dti-registration','mayors-permit-imus-layout','bir-form-2303','national-id-filipino']]
    assert [item['sha256'] for item in previews]==expected
    assert all(item['fit']=='contain' for item in previews)
    report['independent_original_previews']=previews

    for name in ['selfie','cartoon-meme','blank','random-photograph','unrelated-text']:
        upload(page,0,images/(name+'.png'))
        summary=wait_scans(page)
        assert summary['business_registration']['execution']=='completed'
        assert summary['business_registration']['relevance']=='clearly_irrelevant'
        record(name,summary,attempt_submission(page),True)
        if name=='selfie':page.screenshot(path=str(target/'selfie-replacement-required.png'),full_page=True)

    upload(page,0,images/'national-id-filipino.png')
    summary=wait_scans(page)
    assert summary['business_registration']['relevance']=='incompatible_document_type'
    record('national-id-in-dti-slot',summary,attempt_submission(page),True)

    upload(page,0,images/'dti-registration.png')
    upload(page,1,images/'dti-registration.png')
    summary=wait_scans(page)
    assert summary['mayors_permit']['relevance']=='incompatible_document_type'
    record('same-dti-file-in-dti-and-permit-slots',summary,attempt_submission(page),True,'mayors_permit')
    upload(page,1,images/'mayors-permit-demo-layout.png')
    summary=wait_scans(page)
    assert summary['mayors_permit']['family']=='mayors_permit'
    record('alternate-lgu-permit-layout',summary,attempt_submission(page),False,'mayors_permit')

    authority=page.get_by_role('switch',name='Business registration authority')
    authority.click()
    upload(page,0,images/'sec-registration.png')
    summary=wait_scans(page)
    assert summary['business_registration']['family']=='sec_registration'
    record('correct-sec',summary,attempt_submission(page),False)
    authority=page.get_by_role('switch',name='Business registration authority');authority.click()

    for name in ['dti-conflict','dti-uncertain','dti-poor-photo']:
        upload(page,0,images/(name+'.png'))
        summary=wait_scans(page)
        if name=='dti-conflict':assert summary['business_registration']['score']==0
        if name=='dti-uncertain':assert summary['business_registration']['score']==50
        record(name,summary,attempt_submission(page),False)

    for name,family in [('drivers-license','drivers_license'),('passport','passport'),('umid','umid'),('unsupported-government-id',None)]:
        upload(page,3,images/(name+'.png'))
        summary=wait_scans(page)
        assert summary['valid_id']['family']==family
        record(name,summary,attempt_submission(page),False,'valid_id')

    upload(page,0,images/'dti-registration.png',name='A'*240+'.png')
    wait_scans(page)
    for width in [1440,390]:
        page.set_viewport_size({'width':width,'height':1000})
        metrics=page.locator('.dropzone').nth(0).evaluate("""element=>{const p=element.querySelector('.dz-message > p');return {card:element.getBoundingClientRect().width,text:p.getBoundingClientRect().width,ellipsis:getComputedStyle(p).textOverflow,scroll:p.scrollWidth,client:p.clientWidth};}""")
        assert metrics['text']<=metrics['card'] and metrics['ellipsis']=='ellipsis'
        report.setdefault('long_filename_layout',[]).append({'viewport':width,**metrics})
        page.screenshot(path=str(target/f'filename-{width}.png'),full_page=True)
    page.set_viewport_size({'width':1440,'height':1000})
    upload(page,0,images/'dti-registration.png',name='data:image/png;base64,AAA.png')
    wait_scans(page)
    assert page.locator('.dropzone').nth(0).get_by_text('Selected document',exact=True).count()==1
    assert page.get_by_text('data:image/png;base64,AAA.png',exact=True).count()==0

    # Real active work is replaced/removed, with deliberately delayed worker
    # delivery for deterministic lifecycle timing. Recognition is not mocked.
    def slow_worker(route):
        time.sleep(0.75)
        route.continue_()
    page.route('**/ocr/**/worker.min.js',slow_worker)
    upload(page,0,images/'dti-registration.png')
    old=page.evaluate(OBSERVE)['business_registration']
    assert old['status'] in ['loading','queued','recognizing'], 'Replacement did not occur during an active scan'
    upload(page,0,images/'selfie.png')
    summary=wait_scans(page)
    assert summary['business_registration']['relevance']=='clearly_irrelevant' and summary['business_registration']['family'] is None
    record('replacement-during-active-scan',summary,attempt_submission(page),True)
    upload(page,0,images/'dti-registration.png')
    old=page.evaluate(OBSERVE)['business_registration']
    assert old['status'] in ['loading','queued','recognizing'], 'Removal did not occur during an active scan'
    # The loader covers this control while busy; exercise its real callback
    # independently to verify removal/cancellation without modifying state.
    page.get_by_role('button',name='Remove selected Shop Registration file',exact=True,include_hidden=True).evaluate("""element=>{const key=Object.keys(element).find(key=>key.startsWith('__reactProps$'));element[key].onClick();}""")
    page.wait_for_timeout(1200)
    assert 'business_registration' not in page.evaluate(OBSERVE)
    assert page.locator('.dropzone').nth(0).locator('img').count()==0
    page.get_by_role('button',name='Next',exact=True).click()
    page.locator('.swal2-container:visible').wait_for()
    assert page.get_by_role('button',name='Submit Registration',exact=True).count()==0
    report['removal_during_active_scan']={'old_status':old['status'],'old_evidence_discarded':True,'submit_button':'not reachable; original required-file validation stops Next','handler_dispatched':False}
    dismiss(page)
    page.unroute('**/ocr/**/worker.min.js',slow_worker)
    upload(page,0,images/'dti-registration.png')
    wait_scans(page)

    # A genuine missing worker asset must retain the nonblocking fallback.
    page.route('**/ocr/**/worker.min.js',lambda route:route.abort())
    upload(page,0,images/'dti-registration.png')
    summary=wait_scans(page)
    assert summary['business_registration']['execution']=='technical_failure'
    assert summary['business_registration']['relevance']=='technical_failure'
    record('blocked-worker-asset',summary,attempt_submission(page),False)
    page.unroute('**/ocr/**/worker.min.js')
    (target/'browser-results.json').write_text(json.dumps(report,indent=2))
    return report


def main():
    parser=argparse.ArgumentParser()
    parser.add_argument('--url',default='http://127.0.0.1:8017/shop-owner-register')
    parser.add_argument('--output',default='output/ocr-relevance-fix')
    parser.add_argument('--mode',choices=['baseline','verify'],default='verify')
    args=parser.parse_args()
    if not args.url.startswith('http://127.0.0.1:'):
        parser.error('Use an isolated loopback test app only.')
    target=ROOT/args.output
    target.mkdir(parents=True,exist_ok=True)
    smtp=socketserver.ThreadingTCPServer(('127.0.0.1',1025),SmtpCapture)
    threading.Thread(target=smtp.serve_forever,daemon=True).start()
    try:
        with sync_playwright() as p:
            browser=p.chromium.launch(headless=True)
            context=browser.new_context(geolocation={'latitude':14.4296,'longitude':120.9367},permissions=['geolocation'],viewport={'width':1440,'height':1000})
            page=context.new_page()
            asset_responses=[]
            external_ocr=[]
            runtime_errors=[]
            hmr_reloads=[]
            page.on('response',lambda response:asset_responses.append({'path':urlsplit(response.url).path,'status':response.status,'mime':response.headers.get('content-type'),'encoding':response.headers.get('content-encoding')}) if '/ocr/' in urlsplit(response.url).path else None)
            page.on('request',lambda request:external_ocr.append(request.url) if re.search(r'tesseract|traineddata|\.wasm|worker\.min\.js',request.url,re.I) and urlsplit(request.url).hostname not in ['127.0.0.1','localhost'] else None)
            page.on('pageerror',lambda error:runtime_errors.append(str(error)[:250]))
            def hmr_message(payload):
                try:
                    event=json.loads(payload)
                    if event.get('type')=='full-reload':hmr_reloads.append(event)
                except (ValueError,TypeError):
                    pass
            page.on('websocket',lambda socket:socket.on('framereceived',hmr_message))
            make_fixtures(page,target/'images')
            response=page.goto(args.url,wait_until='networkidle')
            assert response.headers.get('x-ocr-test-isolation') == 'local-sqlite-smtp', 'Not the isolated test app; refusing to request OTP or submit.'
            try:
                if args.mode=='baseline':baseline_form(page,target)
                else:
                    report=verify_form(page,target)
                    report.update({'browser':browser.version,'asset_responses':asset_responses,'external_ocr_requests':external_ocr,'runtime_errors':runtime_errors,'hmr_reloads':hmr_reloads})
                    assert not external_ocr, 'External OCR resource requested'
                    (target/'browser-results.json').write_text(json.dumps(report,indent=2))
            except Exception:
                print(json.dumps({'failure_url_path':urlsplit(page.url).path}),flush=True)
                page.screenshot(path=str(target/'failure.png'),full_page=True)
                (target/'failure-state.json').write_text(json.dumps(page.evaluate(OBSERVE),indent=2))
                raise
            browser.close()
    finally:
        smtp.shutdown();smtp.server_close()


if __name__=='__main__':
    main()
