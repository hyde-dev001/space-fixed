import { act, renderHook, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { ShopOwnerOcrContext } from '../shopOwnerDocumentRecognition';
import type { ShopOwnerOcrPass } from '../registrationOcr';
import { RegistrationOcrError } from '../registrationOcr';
import { useShopOwnerOcr } from '../useShopOwnerOcr';

const readDocument = vi.hoisted(() => vi.fn());

vi.mock('../registrationOcr', async (importOriginal) => ({
  ...(await importOriginal<typeof import('../registrationOcr')>()),
  readShopOwnerDocument: readDocument,
}));

const CONTEXT: ShopOwnerOcrContext = {
  firstName: 'Mira',
  lastName: 'Sample',
  businessName: 'Sample Shoe House',
  businessAddress: '123 Example Street, Barangay Sample, Imus, Cavite',
  registrationType: 'company',
  referenceDay: '2026-10-21',
  metadata: {
    business_registration: { issuedOn: '2025-01-15', expirationMode: 'dated', expiresOn: '2030-01-15' },
    mayors_permit: { issuedOn: '2026-01-10', expirationMode: 'dated', expiresOn: '2027-12-31' },
  },
};

const OPTIONS = {
  assets: { workerPath: '/ocr/worker.min.js', corePath: '/ocr/core/', langPath: '/ocr/lang/' },
  context: CONTEXT,
  selectedDocumentTypes: {
    business_registration: 'dti_registration',
    mayors_permit: 'mayors_permit',
  } as const,
};

function deferred<T>() {
  let resolve!: (value: T) => void;
  let reject!: (reason: unknown) => void;
  const promise = new Promise<T>((res, rej) => { resolve = res; reject = rej; });
  return { promise, resolve, reject };
}

function file(name: string): File {
  return new File(['synthetic image bytes'], name, { type: 'image/png' });
}

function dtiPass(): ShopOwnerOcrPass {
  const lines = [
    'Department of Trade and Industry',
    'Certificate of Business Name Registration',
    'Registered Business Name: Sample Shoe House',
    'Proprietor: Mira Sample',
    'Registration No: DTI-SYN-1200',
    'Date Issued: January 15, 2025',
    'Valid Until: January 15, 2030',
  ];
  return {
    id: 'full_image',
    text: lines.join('\n'),
    confidence: 0.96,
    image_width: 1200,
    image_height: 1800,
    lines: lines.map((text, index) => {
      const y0 = 40 + index * 34;
      const words = text.split(/\s+/);
      return {
        text,
        confidence: 0.96,
        bbox: { x0: 40, y0, x1: 900, y1: y0 + 24 },
        words: words.map((word, wordIndex) => ({
          text: word,
          confidence: 0.96,
          bbox: { x0: 40 + wordIndex * 40, y0, x1: 70 + wordIndex * 40, y1: y0 + 24 },
          pass_id: 'full_image' as const,
        })),
      };
    }),
  };
}

describe('useShopOwnerOcr', () => {
  beforeEach(() => { readDocument.mockReset(); });

  it('processes one file at a time and scans only the latest queued selection for each slot', async () => {
    const first = deferred<{ passes: ShopOwnerOcrPass[] }>();
    const second = deferred<{ passes: ShopOwnerOcrPass[] }>();
    let activeReaders = 0;
    let maxActiveReaders = 0;
    readDocument
      .mockImplementationOnce(() => {
        activeReaders += 1;
        maxActiveReaders = Math.max(maxActiveReaders, activeReaders);
        return first.promise.finally(() => { activeReaders -= 1; });
      })
      .mockImplementationOnce(() => {
        activeReaders += 1;
        maxActiveReaders = Math.max(maxActiveReaders, activeReaders);
        return second.promise.finally(() => { activeReaders -= 1; });
      });
    const { result } = renderHook(() => useShopOwnerOcr(OPTIONS));

    act(() => result.current.selectFile('business_registration', file('first.png')));
    await waitFor(() => expect(readDocument).toHaveBeenCalledTimes(1));
    act(() => {
      result.current.selectFile('mayors_permit', file('older-queued.png'));
      result.current.selectFile('mayors_permit', file('latest-queued.png'));
    });
    expect(readDocument).toHaveBeenCalledTimes(1);

    await act(async () => first.resolve({ passes: [] }));
    await waitFor(() => expect(readDocument).toHaveBeenCalledTimes(2));
    expect(readDocument.mock.calls[1][0].name).toBe('latest-queued.png');
    await act(async () => second.resolve({ passes: [] }));

    expect(maxActiveReaders).toBe(1);
  });

  it('aborts a superseded active selection and ignores its late result', async () => {
    const oldRead = deferred<{ passes: ShopOwnerOcrPass[] }>();
    const newRead = deferred<{ passes: ShopOwnerOcrPass[] }>();
    readDocument
      .mockImplementationOnce((_file: File, options: { signal: AbortSignal }) => new Promise((resolve, reject) => {
        options.signal.addEventListener('abort', () => {
          reject(new RegistrationOcrError('scan_unfinished', Promise.resolve()));
          oldRead.resolve({ passes: [] });
        }, { once: true });
        void oldRead.promise.then(resolve);
      }))
      .mockReturnValueOnce(newRead.promise);
    const { result } = renderHook(() => useShopOwnerOcr(OPTIONS));

    act(() => result.current.selectFile('business_registration', file('old.png')));
    await waitFor(() => expect(readDocument).toHaveBeenCalledTimes(1));
    const oldSignal = readDocument.mock.calls[0][1].signal as AbortSignal;
    act(() => result.current.selectFile('business_registration', file('new.png')));

    expect(oldSignal.aborted).toBe(true);
    await waitFor(() => expect(readDocument).toHaveBeenCalledTimes(2));
    expect(readDocument.mock.calls[1][0].name).toBe('new.png');
    await act(async () => newRead.resolve({ passes: [] }));
    expect(result.current.states.business_registration?.entry?.reason_codes).toContain('ocr_failed');
  });

  it('recalculates retained localized evidence when form context changes without rereading the image', async () => {
    readDocument.mockResolvedValue({ passes: [dtiPass()] });
    const { result, rerender } = renderHook(
      ({ context }: { context: ShopOwnerOcrContext }) => useShopOwnerOcr({ ...OPTIONS, context }),
      { initialProps: { context: CONTEXT } },
    );

    act(() => result.current.selectFile('business_registration', file('registration.png')));
    await waitFor(() => expect(result.current.states.business_registration?.status).toBe('complete'));
    expect(result.current.states.business_registration?.score).toBe(100);
    expect(readDocument).toHaveBeenCalledTimes(1);

    rerender({ context: { ...CONTEXT, businessName: 'Different Registered Shop' } });

    expect(result.current.states.business_registration?.status).toBe('review');
    expect(result.current.states.business_registration?.score).toBe(0);
    expect(readDocument).toHaveBeenCalledTimes(1);
  });

  it('omits an unfinished scan from an immutable submission snapshot', async () => {
    const secondRead = deferred<{ passes: ShopOwnerOcrPass[] }>();
    readDocument.mockResolvedValueOnce({ passes: [dtiPass()] }).mockReturnValueOnce(secondRead.promise);
    const { result } = renderHook(() => useShopOwnerOcr(OPTIONS));

    act(() => result.current.selectFile('business_registration', file('complete.png')));
    await waitFor(() => expect(result.current.states.business_registration?.status).toBe('complete'));
    const snapshot = result.current.submissionSnapshot();
    expect(snapshot).not.toBeNull();
    expect(snapshot).not.toContain('complete.png');

    act(() => result.current.selectFile('mayors_permit', file('scanning.png')));
    await waitFor(() => expect(readDocument).toHaveBeenCalledTimes(2));
    expect(result.current.submissionSnapshot()).toBe(snapshot);
    await act(async () => secondRead.resolve({ passes: [] }));
    expect(snapshot).not.toContain('mayors_permit');
    expect(result.current.submissionSnapshot()).toContain('mayors_permit');
  });

  it('records safe unable-to-assess evidence when local assets are unavailable', () => {
    const { result } = renderHook(() => useShopOwnerOcr({ ...OPTIONS, assets: null }));

    act(() => result.current.selectFile('business_registration', file('registration.png')));

    expect(readDocument).not.toHaveBeenCalled();
    expect(result.current.states.business_registration?.status).toBe('unable');
    expect(result.current.states.business_registration?.entry?.reason_codes).toContain('ocr_unavailable');
    expect(result.current.submissionSnapshot()).toContain('unable_to_assess');
  });

  it('keeps worker timeouts as a nonblocking unable-to-assess report', async () => {
    readDocument.mockRejectedValueOnce(new RegistrationOcrError('ocr_timeout', Promise.resolve()));
    const { result } = renderHook(() => useShopOwnerOcr(OPTIONS));

    act(() => result.current.selectFile('business_registration', file('timeout.png')));
    await waitFor(() => expect(result.current.states.business_registration?.status).toBe('unable'));

    expect(result.current.states.business_registration?.reasonCode).toBe('ocr_timeout');
    expect(result.current.submissionSnapshot()).toContain('ocr_timeout');
  });

  it('blocks queued scans when worker cleanup cannot be confirmed', async () => {
    const cleanup = deferred<void>();
    let failRead!: (error: unknown) => void;
    readDocument.mockImplementationOnce(() => new Promise((_resolve, reject) => { failRead = reject; }));
    const { result } = renderHook(() => useShopOwnerOcr(OPTIONS));

    act(() => result.current.selectFile('business_registration', file('slow.png')));
    await waitFor(() => expect(readDocument).toHaveBeenCalledTimes(1));
    act(() => result.current.selectFile('mayors_permit', file('queued.png')));
    await act(async () => failRead(new RegistrationOcrError('ocr_failed', cleanup.promise)));

    await waitFor(() => expect(result.current.states.mayors_permit?.status).toBe('unable'), { timeout: 3_000 });
    expect(readDocument).toHaveBeenCalledTimes(1);
    expect(result.current.states.mayors_permit?.entry?.reason_codes).toContain('ocr_unavailable');
  });

  it('aborts active work on removal and unmount', async () => {
    const signals: AbortSignal[] = [];
    readDocument.mockImplementation((_file: File, options?: { signal: AbortSignal }) => new Promise((_resolve, reject) => {
      if (!options) return;
      signals.push(options.signal);
      options?.signal?.addEventListener('abort', () => {
        reject(new RegistrationOcrError('scan_unfinished', Promise.resolve()));
      }, { once: true });
    }));
    const { result, unmount } = renderHook(() => useShopOwnerOcr(OPTIONS));

    act(() => result.current.selectFile('business_registration', file('remove.png')));
    await waitFor(() => expect(readDocument).toHaveBeenCalledTimes(1));
    const removeSignal = signals[0];
    act(() => result.current.removeFile('business_registration'));
    expect(removeSignal.aborted).toBe(true);
    expect(result.current.states.business_registration).toBeUndefined();

    act(() => result.current.selectFile('business_registration', file('unmount.png')));
    await waitFor(() => expect(readDocument).toHaveBeenCalledTimes(2));
    const unmountSignal = signals[1];
    unmount();
    expect(unmountSignal.aborted).toBe(true);
  });
});
