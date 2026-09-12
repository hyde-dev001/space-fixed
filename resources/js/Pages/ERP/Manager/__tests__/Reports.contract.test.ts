import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

const source = readFileSync(resolve('resources/js/Pages/ERP/Manager/Reports.tsx'), 'utf8');

describe('Manager Reports page contract', () => {
  it('uses review semantics when no delivery workflow exists', () => {
    expect(source).toContain('Mark as reviewed');
    expect(source).toContain('/review');
    expect(source).toContain('reports_reviewed');
    expect(source).not.toContain('Generate & Send');
    expect(source).not.toContain('sent to the shop owner');
  });

  it('keeps CRM complaints outside the Manager report picker', () => {
    expect(source).not.toContain('Customer Complaints');
    expect(source).not.toContain('complaints');
  });

  it('protects report mutations with the shared CSRF request helper', () => {
    expect(source).toContain('import { fetchWithCsrf } from "@/utils/fetch-with-csrf";');
    expect(source).toContain('fetchWithCsrf("/api/manager/reports/generate"');
    expect(source).toContain('fetchWithCsrf(`/api/manager/reports/${reviewTarget.id}/review`');
  });

  it('uses dense available-report cards with black icons and accessible icon-only table actions', () => {
    expect(source).toContain('flex-[1_1_240px]');
    expect(source).toContain('text-gray-950 dark:text-white');
    expect(source).toContain('aria-label={`Mark ${report.report_title} as reviewed`}');
    expect(source).toContain('aria-label={`Download ${report.report_title}`}');
    expect(source).toContain('<CheckIcon className="h-4 w-4" />');
    expect(source).toContain('<DownloadIcon className="h-4 w-4" />');
    expect(source).not.toContain('>Mark as reviewed</button>');
    expect(source).not.toContain('>Download</button>');
  });
});
