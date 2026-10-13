"""Render captioned demo videos: python scripts/generate-payment-tutorials.py.

Requires Python Playwright and its Chromium/FFmpeg (browser testing tools).
Uses fictional data and local HTML only; never contacts payment providers.
"""

import html
import tempfile
from contextlib import ExitStack
from pathlib import Path

from playwright.sync_api import sync_playwright


ROOT = Path(__file__).resolve().parents[1]
OUTPUT = ROOT / "public/guides/payment-setup"

# Each scene: title, Tagalog instruction, mock page address, fields, button.
SCENES = {
    "paymongo": [
        ("1. Gumawa ng account", "Buksan ang dashboard.paymongo.com/signup. Ilagay ang email na ginagamit mo at gumawa ng password. Basahin at tanggapin ang Terms at Privacy Policy. Optional ang referral code.", "dashboard.paymongo.com/signup", [("Email address", "owner@example.com"), ("Password", "••••••••••••"), ("Terms of Use / Privacy Policy", "Tinanggap matapos basahin")], "Sign up"),
        ("2. I-verify ang email", "Buksan ang email mula sa PayMongo. Ilagay ang 6-digit code na ipinadala nila. Sample code lang ang nasa demo na ito.", "PayMongo / Verify email", [("Email verification code", "123456")], "Verify email"),
        ("3. I-verify ang pagkakakilanlan", "Sundin ang camera check at pagkuha ng larawan ng valid ID. Ilagay ang personal details na tugma sa ID mo. Sa tunay na website lang gawin ito.", "PayMongo / Identity verification", [("Camera check at valid government ID", "Sundin ang instructions sa PayMongo"), ("Buong pangalan / kapanganakan / nationality", "Mga detalye na nasa ID mo"), ("Address / contact / trabaho / pinagmulan ng pera", "Totoong impormasyon mo")], "Submit verification"),
        ("4. Kumpletuhin ang negosyo", "Para sa mga serbisyong kailangan ng business verification, sagutan ang detalye ng shop. Ihanda ang registration, TIN at address. Sundin ang requirements ng PayMongo bago gumamit ng totoong bayad.", "PayMongo / Business verification", [("Business name / description", "Pangalan at ginagawa ng shop"), ("Industry / business size / monthly transactions", "Piliin ang tugma sa negosyo mo"), ("Business address / TIN / requested documents", "Mga totoong detalye at dokumento")], "Submit business details"),
        ("5. Hanapin ang Secret Key", "Sa PayMongo dashboard, buksan ang Settings, Product Settings, tapos Developers. Hanapin ang API Keys. Ang Secret Key ay pribadong code para maikonekta ang account.", "PayMongo / Settings / Product Settings / Developers", [("API Keys", "Secret Test Key at Secret Live Key"), ("Public Key", "Hindi ito ang ilalagay sa SoleSpace")], "API Keys"),
        ("6. Piliin ang tamang code", "sk_test_ ang simula para sa practice. sk_live_ para sa totoong bayad. Kopyahin ang Secret Key na tugma sa gagamitin mo. Huwag ibahagi ang buong code sa iba.", "PayMongo / API Keys", [("Secret Test Key - practice", "sk_test_DEMO_ONLY_NOT_VALID"), ("Secret Live Key - totoong bayad", "sk_live_DEMO_ONLY_NOT_VALID")], "Copy Secret Test Key"),
        ("7. Ilagay sa SoleSpace", "Bumalik sa Shop Settings. Sa Bayad ng customer (PayMongo), i-paste ang Secret Key. Pindutin ang I-save ang code. Test key lang ang ginagamit sa demo.", "SoleSpace / Shop Settings / Profile", [("PayMongo code (Secret Key)", "sk_test_DEMO_ONLY_NOT_VALID")], "I-save ang code"),
        ("8. Naka-save na ang code", "Makikita ang mensaheng naka-save na ang PayMongo code. Para sa totoong bayad, kailangan ang live key at mga payment method na pinayagan ng PayMongo. Ang pag-save ng code ay hindi approval ng account.", "SoleSpace / Bayad ng customer (PayMongo)", [("Status", "Naka-save na ang PayMongo code"), ("Bago tumanggap ng totoong bayad", "Kumpletuhin ang provider requirements")], "Tapos na ang gabay"),
    ],
    "xendit": [
        ("1. Gumawa ng Xendit account", "Buksan ang dashboard.xendit.co/register. Piliin ang Philippines kung hinihingi. Ilagay ang email, password at mga basic detail na hinihingi ng form. Sundin ang kasalukuyang website.", "dashboard.xendit.co/register", [("Country / region", "Philippines"), ("Email address", "owner@example.com"), ("Password", "••••••••••••")], "Sign up"),
        ("2. Email at business details", "I-verify ang email gamit ang ipinadalang instructions. Sagutan ang mga tanong tungkol sa negosyo, gamit ang totoong pangalan at contact details ng shop.", "Xendit / Account setup", [("Email verification", "Sundin ang email mula sa Xendit"), ("Business name / type / contact details", "Mga detalye ng shop mo")], "Continue"),
        ("3. Practice o totoong pera?", "Test Mode ay practice lang. Para makapagpadala ng totoong pera, piliin ang Verify Business at isumite ang mga hinihinging business documents, ID at proof of business. Hintayin ang approval ng Xendit.", "Xendit / Dashboard", [("Test Mode", "Practice - walang totoong pera"), ("Verify Business", "Registration, ID at proof of business"), ("Live Mode", "Para sa verified business at totoong pera")], "Test Mode"),
        ("4. Gumawa ng Secret API Key", "Buksan ang Settings, Developers, tapos API Keys. Gumawa ng Secret API Key. Ito ang pribadong code na ikokonekta sa SoleSpace. Gamitin ang parehong Test o Live mode sa dalawang website.", "Xendit / Settings / Developers / API Keys", [("Mode", "Test Mode - practice"), ("Key name", "SoleSpace supplier payments")], "Generate Secret API Key"),
        ("5. Piliin ang pahintulot", "Payagan ang Money-Out Read para matingnan ang balanse, at Money-Out Write para makapagpadala ng bayad sa supplier. Piliin lang ang kailangan ng koneksyon na ito.", "Xendit / Secret API Key permissions", [("Money-Out Read", "Pinayagan - pagtingin ng balanse"), ("Money-Out Write", "Pinayagan - pagpadala ng bayad")], "Generate key"),
        ("6. Kopyahin ang Secret API Key", "Kopyahin at itago nang ligtas ang code habang ipinapakita ito. Hindi na ito makikita ulit kapag isinara ang window. Demo code lang ang nasa video.", "Xendit / New Secret API Key", [("Secret API Key", "XENDIT_DEMO_ONLY_NOT_VALID")], "Copy key"),
        ("7. Ilagay ang link ng SoleSpace", "Sa SoleSpace settings, kopyahin ang link na nasa Xendit section. Sa Xendit, buksan ang Settings, Developers, Webhooks. I-paste ang link sa PAYOUT at i-save. Gamitin ang link ng sarili mong SoleSpace site.", "Xendit / Settings / Developers / Webhooks", [("PAYOUT URL", "https://solespace.shop/api/webhooks/xendit/payout")], "Save"),
        ("8. Kunin ang pangalawang code", "Sa Webhooks page, buksan ang Webhook Verification Token. Maaaring hingin ang password mo. Kopyahin ang token mula sa Xendit. Huwag gumawa ng sariling code: dapat tugma ito sa ipinapadala ng Xendit.", "Xendit / Webhooks / Webhook Verification Token", [("Webhook Verification Token", "TOKEN_DEMO_ONLY_NOT_VALID")], "Copy token"),
        ("9. Ikonekta sa SoleSpace", "Sa Bayad sa supplier (Xendit), piliin ang Practice lang kung Test ang ginamit sa Xendit. I-paste ang Secret API Key at Webhook Verification Token. Pindutin ang Ikonekta ang Xendit.", "SoleSpace / Shop Settings / Profile", [("Paggagamitan", "Practice lang (Test)"), ("Xendit code (Secret API Key)", "XENDIT_DEMO_ONLY_NOT_VALID"), ("Code para sa update ng bayad", "TOKEN_DEMO_ONLY_NOT_VALID")], "Ikonekta ang Xendit"),
        ("10. Tingnan ang koneksyon", "Kapag nakakonekta na, pindutin ang Tingnan kung nakakonekta. Balanse lang ang titingnan; walang perang ipapadala sa check na ito. PayMongo ang para sa customer; Xendit ang para sa supplier.", "SoleSpace / Bayad sa supplier (Xendit)", [("Status", "Nakakonekta ang Xendit - Practice lang"), ("Para sa totoong supplier payment", "Approved Live Mode at sapat na balanse")], "Tingnan kung nakakonekta"),
    ],
}

PAGE = """<!doctype html><html lang="fil"><meta charset="utf-8">
<style>
*{box-sizing:border-box}body{margin:0;background:#f8fafc;color:#0f172a;font:22px/1.5 Arial,sans-serif}
header{height:90px;padding:22px 40px;background:#0f172a;color:white;display:flex;justify-content:space-between;align-items:center}
header small{font-size:16px;color:#cbd5e1}.layout{display:grid;grid-template-columns:440px 1fr;gap:32px;padding:32px 40px;height:542px}
h1{font-size:30px;line-height:1.2;margin:12px 0 24px}p{font-size:23px;margin:0}.step{font-size:16px;color:#475569}
.browser{border:1px solid #cbd5e1;background:white;border-radius:16px;overflow:hidden;padding-bottom:24px}
.address{padding:16px 24px;background:#e2e8f0;font-size:17px;overflow-wrap:anywhere}.form{padding:20px}
label{display:block;font-size:16px;color:#334155;margin-bottom:6px}input{width:100%;height:48px;padding:12px;border:1px solid #94a3b8;border-radius:8px;font:18px Arial;background:#fff;color:#0f172a;margin-bottom:14px}
button{padding:14px 22px;background:#0f172a;color:white;border:0;border-radius:8px;font:bold 18px Arial;max-width:100%}
.focus{outline:3px solid #2563eb;outline-offset:3px}.clicked{background:#166534}
footer{height:88px;padding:14px 40px;border-top:1px solid #cbd5e1;font-size:17px;color:#334155;background:white}
#cursor{position:fixed;left:100px;top:100px;width:28px;height:40px;z-index:9;pointer-events:none;filter:drop-shadow(0 2px 2px #64748b);transition:left .6s ease,top .6s ease}
</style><header><strong id="brand"></strong><small>DEMO SCREENS • Sample details lang • Walang totoong transaksyon</small></header>
<main class="layout"><section><div class="step" id="step"></div><h1 id="title"></h1><p id="instruction"></p></section>
<section class="browser"><div class="address" id="address"></div><div class="form" id="form"></div></section></main>
<footer>Tagalog na gabay • I-pause kung kailangan. Maaaring mag-iba ang hitsura ng provider website.<br>Pribado ang password, Secret Key at token. Sa sariling account mo lang kunin ang mga ito.</footer>
<svg id="cursor" viewBox="0 0 28 40"><path d="M2 2 L2 29 L9 23 L15 37 L21 34 L15 21 L25 20 Z" fill="white" stroke="#0f172a" stroke-width="2"/></svg>
<script>
function showScene(s){
 document.querySelector('#brand').textContent=s.brand+' / Account setup';
 for(const key of ['title','instruction','address','step'])document.getElementById(key).textContent=s[key];
 document.getElementById('form').innerHTML=s.fields.map((f,i)=>'<label for="field'+i+'">'+f.label+'</label><input id="field'+i+'" readonly>').join('')+'<button id="action">'+s.button+'</button>';
}
function point(id){
 document.querySelectorAll('.focus').forEach(e=>e.classList.remove('focus'));
 const e=document.getElementById(id);e.classList.add('focus');const r=e.getBoundingClientRect();
 const cursor=document.getElementById('cursor');cursor.style.left=(r.right-32)+'px';cursor.style.top=(r.top+r.height/2)+'px';
}
</script></html>"""


def render(browser, provider, scenes):
    transcript = "DEMO SCREENS - Sample details lang. Walang audio o totoong transaksyon.\n\n"
    transcript += "\n\n".join(f"{title}\n{instruction}" for title, instruction, _, _, _ in scenes)
    (OUTPUT / f"{provider}.txt").write_text(transcript + "\n", encoding="utf-8")
    with tempfile.TemporaryDirectory() as recordings, ExitStack() as cleanup:
        context = browser.new_context(viewport={"width": 1280, "height": 720},
                                      record_video_dir=recordings,
                                      record_video_size={"width": 1280, "height": 720})
        cleanup.callback(context.close)
        page = context.new_page()
        page.set_content(PAGE)
        video = page.video
        for number, (title, instruction, address, fields, button) in enumerate(scenes, 1):
            page.evaluate("showScene", {
                "brand": "PayMongo" if provider == "paymongo" else "Xendit",
                "title": title, "instruction": instruction, "address": address,
                "step": f"HAKBANG {number} / {len(scenes)}",
                "fields": [{"label": html.escape(label)} for label, _ in fields],
                "button": html.escape(button),
            })
            assert page.locator(".browser").evaluate("e=>e.scrollHeight<=e.clientHeight"), title
            assert page.locator("footer").bounding_box()["y"] == 632
            page.wait_for_timeout(800)
            for index, (_, value) in enumerate(fields):
                page.evaluate("point", f"field{index}")
                page.wait_for_timeout(650)
                page.locator(f"#field{index}").evaluate("(e,v)=>e.value=v", value)
                page.wait_for_timeout(1100)
            page.evaluate("point", "action")
            page.wait_for_timeout(700)
            page.locator("#action").evaluate("e=>e.classList.add('clicked')")
            # Reading time scales with the caption; users can also pause.
            page.wait_for_timeout(max(6000, len(instruction.split()) * 230))
            if number == 1:
                page.screenshot(path=str(OUTPUT / f"{provider}-preview.png"))
        context.close()
        video.save_as(str(OUTPUT / f"{provider}.webm"))
        assert (OUTPUT / f"{provider}.webm").stat().st_size > 10000
        print(f"Rendered {provider}: {len(scenes)} scenes", flush=True)


if __name__ == "__main__":
    OUTPUT.mkdir(parents=True, exist_ok=True)
    with sync_playwright() as playwright:
        browser = playwright.chromium.launch(headless=True)
        try:
            for provider, scenes in SCENES.items():
                render(browser, provider, scenes)
        finally:
            browser.close()
