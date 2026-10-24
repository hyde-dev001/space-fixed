import json
from pathlib import Path
import failed_delivery_finance as finance
from logistics_usability import capture,OUT
from playwright.sync_api import expect
finance.OUT=OUT

def observe(page,label):
    if label=='finance-refund-details':
        dialog=page.get_by_role('dialog',name='Refund request details',exact=True);expect(dialog).to_be_visible()
        for _ in range(18):
            page.keyboard.press('Tab');assert page.evaluate('!!document.activeElement.closest("[role=dialog]")')
        capture(page,label)
        page.keyboard.press('Escape');expect(dialog).not_to_be_visible()
        assert page.evaluate('document.activeElement.title')=='View Details'
        page.keyboard.press('Enter');expect(dialog).to_be_visible()
    else: capture(page,label)
    if label=='finance-execution':
        def pending(route):
            expect(page.get_by_role('button',name='Close execute modal')).to_be_disabled()
            page.keyboard.press('Escape');expect(page.get_by_role('dialog',name='Execute refund payout')).to_be_visible()
            capture(page,'finance-execution-saving',widths=(390,));route.continue_()
        page.route('**/api/finance/repair-refunds/*/execute',pending)
finance.run(observe=observe,compact_inspection=True)
