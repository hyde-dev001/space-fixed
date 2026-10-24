import { beforeEach, describe, expect, it, vi } from 'vitest';

const sweetAlertFire = vi.hoisted(() => vi.fn().mockResolvedValue({ isConfirmed: false }));

vi.mock('sweetalert2', () => ({
  default: {
    fire: sweetAlertFire,
  },
}));

import { workflowFeedback } from '../workflowFeedback';

describe('workflowFeedback ERP dialog defaults', () => {
  beforeEach(() => {
    sweetAlertFire.mockClear();
  });

  it('uses neutral button defaults and stable ERP class hooks', async () => {
    await workflowFeedback.warning('Warning', 'Review this action');

    expect(sweetAlertFire).toHaveBeenCalledWith(
      expect.objectContaining({
        confirmButtonColor: '#111111',
        cancelButtonColor: '#f3f4f6',
        reverseButtons: true,
        customClass: expect.objectContaining({
          popup: 'erp-swal2-popup',
          confirmButton: 'erp-swal2-confirm',
          cancelButton: 'erp-swal2-cancel',
        }),
      }),
    );
  });

  it('preserves caller button and custom-class overrides', async () => {
    await workflowFeedback.confirm({
      title: 'Confirm',
      confirmButtonColor: '#222222',
      customClass: {
        popup: 'caller-popup',
      },
    });

    expect(sweetAlertFire).toHaveBeenCalledWith(
      expect.objectContaining({
        confirmButtonColor: '#222222',
        customClass: expect.objectContaining({
          popup: 'caller-popup',
          confirmButton: 'erp-swal2-confirm',
          cancelButton: 'erp-swal2-cancel',
        }),
      }),
    );
  });
});
