import { describe, expect, it } from 'vitest';
import { withSweetAlertSemantic } from '../semanticSweetAlert';

describe('withSweetAlertSemantic', () => {
  it('adds semantic classes while preserving caller-provided classes and options', () => {
    const result = withSweetAlertSemantic(
      {
        title: 'Delete campaign',
        customClass: {
          popup: 'custom-popup',
          confirmButton: ['custom-confirm'],
        },
      },
      'danger',
    );

    expect(result.title).toBe('Delete campaign');
    expect(result.customClass?.popup).toContain('custom-popup');
    expect(result.customClass?.popup).toContain('erp-swal2-danger');
    expect(result.customClass?.confirmButton).toContain('custom-confirm');
    expect(result.customClass?.confirmButton).toContain('erp-swal2-danger');
  });

  it('marks sign-out confirmations separately from destructive actions', () => {
    const result = withSweetAlertSemantic({ title: 'Sign out?' }, 'signout');

    expect(result.customClass?.popup).toContain('erp-swal2-signout');
    expect(result.customClass?.confirmButton).toContain('erp-swal2-signout');
    expect(result.customClass?.cancelButton).toContain('erp-swal2-signout');
  });
});
