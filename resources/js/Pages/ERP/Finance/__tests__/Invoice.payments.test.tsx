import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

describe('Finance invoice payment caller', () => {
  it('records payments through the append-only endpoint with a request key', () => {
    const source = readFileSync(resolve(__dirname, '../Invoice.tsx'), 'utf8');

    expect(source).toContain('/payments');
    expect(source).toContain('idempotency_key');
    expect(source).toContain('Record Payment');
    expect(source).toContain('/mark-sent');
    expect(source).toContain('Mark as sent');
    expect(source).not.toContain('/send');
    expect(source).not.toContain("api.post(`/api/finance/invoices/${invoiceId}/mark-paid`");
  });
});
