import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const { createWorkerMock, psm } = vi.hoisted(() => ({
  createWorkerMock: vi.fn(),
  psm: { SINGLE_BLOCK: '6', SPARSE_TEXT: '11' },
}));

vi.mock('tesseract.js', () => ({ createWorker: createWorkerMock, PSM: psm }));

import { createWorker } from 'tesseract.js';
import { readShopOwnerDocument, RegistrationOcrError } from './registrationOcr';

const bbox = { x0: 10, y0: 20, x1: 80, y1: 40 };
const recognized = {
  data: {
    text: ' BUSINESS   NAME\nSAMPLE SHOE HOUSE ',
    confidence: 91,
    blocks: [{
      paragraphs: [{
        lines: [{
          text: 'BUSINESS NAME',
          confidence: 93,
          bbox,
          words: [
            { text: 'BUSINESS', confidence: 92, bbox },
            { text: 'NAME', confidence: 94, bbox },
          ],
        }],
      }],
    }],
  },
};

function worker(result = recognized) {
  return {
    recognize: vi.fn().mockResolvedValue(result),
    setParameters: vi.fn().mockResolvedValue({}),
    terminate: vi.fn().mockResolvedValue({}),
  };
}

function deferred<T>() {
  let resolve!: (value: T) => void;
  let reject!: (reason?: unknown) => void;
  const promise = new Promise<T>((res, rej) => {
    resolve = res;
    reject = rej;
  });
  return { promise, resolve, reject };
}

const file = () => new File(['original bytes'], 'synthetic.png', { type: 'image/png' });
const paths = {
  workerPath: '/app/ocr/bundle/worker.min.js',
  corePath: '/app/ocr/bundle/core/',
  langPath: '/app/ocr/bundle/lang/',
};

describe('shop owner OCR reader', () => {
  beforeEach(() => {
    vi.useRealTimers();
    createWorkerMock.mockReset();
    delete (globalThis as typeof globalThis & { createImageBitmap?: unknown }).createImageBitmap;
  });

  afterEach(() => {
    vi.useRealTimers();
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
  });

  function stubImage(bitmap = { width: 1200, height: 800, close: vi.fn() }) {
    const originalCreateElement = document.createElement.bind(document);
    const drawImage = vi.fn();
    const canvas = {
      width: 0,
      height: 0,
      getContext: () => ({ drawImage }),
      toBlob: (callback: BlobCallback) => callback(new Blob(['bounded'], { type: 'image/jpeg' })),
    } as unknown as HTMLCanvasElement;
    vi.stubGlobal('createImageBitmap', vi.fn().mockResolvedValue(bitmap));
    vi.spyOn(document, 'createElement').mockImplementation(((tag: string, options?: ElementCreationOptions) => (
      tag === 'canvas' ? canvas : originalCreateElement(tag, options)
    )) as typeof document.createElement);
    return { bitmap, canvas, drawImage };
  }

  it('uses local assets and preserves pass-local word confidence and position', async () => {
    stubImage();
    const ocrWorker = worker();
    createWorkerMock.mockResolvedValue(ocrWorker);

    const evidence = await readShopOwnerDocument(file(), paths);

    expect(createWorker).toHaveBeenCalledWith('eng', undefined, expect.objectContaining({
      ...paths,
      workerBlobURL: false,
      gzip: true,
    }));
    expect(ocrWorker.recognize).toHaveBeenCalledWith(expect.any(Blob), undefined, { text: true, blocks: true });
    expect(evidence.passes[0]).toMatchObject({
      id: 'full_image',
      text: 'BUSINESS NAME\nSAMPLE SHOE HOUSE',
      confidence: 0.91,
      lines: [{
        text: 'BUSINESS NAME',
        confidence: 0.93,
        bbox,
        words: [
          { text: 'BUSINESS', confidence: 0.92, bbox, pass_id: 'full_image' },
          { text: 'NAME', confidence: 0.94, bbox, pass_id: 'full_image' },
        ],
      }],
    });
    expect(ocrWorker.terminate).toHaveBeenCalledOnce();
  });

  it('caps the working image at 1,800 pixels and releases image resources', async () => {
    const { bitmap, canvas, drawImage } = stubImage({ width: 6000, height: 4000, close: vi.fn() });
    const ocrWorker = worker();
    createWorkerMock.mockResolvedValue(ocrWorker);

    await readShopOwnerDocument(file(), paths);

    expect(drawImage).toHaveBeenCalledWith(bitmap, 0, 0, 1800, 1200);
    expect(bitmap.close).toHaveBeenCalledOnce();
    expect(canvas.width).toBe(0);
    expect(canvas.height).toBe(0);
    expect(ocrWorker.recognize.mock.calls[0][0]).toBeInstanceOf(Blob);
  });

  it('reports unavailable when safe image preparation is unsupported', async () => {
    vi.stubGlobal('createImageBitmap', undefined);

    await expect(readShopOwnerDocument(file(), paths)).rejects.toMatchObject({ reasonCode: 'ocr_unavailable' });
    expect(createWorker).not.toHaveBeenCalled();
  });

  it('applies the total deadline during image conversion', async () => {
    vi.useFakeTimers();
    const bitmap = { width: 1200, height: 800, close: vi.fn() };
    const canvas = {
      width: 0,
      height: 0,
      getContext: () => ({ drawImage: vi.fn() }),
      toBlob: vi.fn(),
    } as unknown as HTMLCanvasElement;
    vi.stubGlobal('createImageBitmap', vi.fn().mockResolvedValue(bitmap));
    const originalCreateElement = document.createElement.bind(document);
    vi.spyOn(document, 'createElement').mockImplementation(((tag: string, options?: ElementCreationOptions) => (
      tag === 'canvas' ? canvas : originalCreateElement(tag, options)
    )) as typeof document.createElement);

    const read = readShopOwnerDocument(file(), paths).catch((error: unknown) => error as RegistrationOcrError);
    await vi.waitFor(() => expect(canvas.toBlob).toHaveBeenCalledOnce());
    await vi.advanceTimersByTimeAsync(60_000);
    const error = await read;

    expect(error).toMatchObject({ reasonCode: 'ocr_timeout' });
    expect(canvas.width).toBe(0);
    expect(bitmap.close).toHaveBeenCalledOnce();
    expect(createWorker).not.toHaveBeenCalled();
  });

  it('terminates a worker that resolves after startup timed out', async () => {
    vi.useFakeTimers();
    stubImage();
    const startup = deferred<ReturnType<typeof worker>>();
    const ocrWorker = worker();
    createWorkerMock.mockReturnValue(startup.promise);
    const read = readShopOwnerDocument(file(), paths).catch((error: unknown) => error as RegistrationOcrError);

    await vi.waitFor(() => expect(createWorker).toHaveBeenCalledOnce());
    await vi.advanceTimersByTimeAsync(60_000);
    const error = await read;
    let cleanupComplete = false;
    void error.cleanup.then(() => { cleanupComplete = true; });
    await Promise.resolve();
    expect(error.reasonCode).toBe('ocr_timeout');
    expect(cleanupComplete).toBe(false);

    startup.resolve(ocrWorker);
    await error.cleanup;
    expect(ocrWorker.terminate).toHaveBeenCalledOnce();
    expect(cleanupComplete).toBe(true);
  });

  it('enforces one total deadline and does not retry recognition', async () => {
    vi.useFakeTimers();
    stubImage();
    const recognition = deferred<typeof recognized>();
    const ocrWorker = worker();
    ocrWorker.recognize.mockReturnValue(recognition.promise);
    createWorkerMock.mockResolvedValue(ocrWorker);
    const read = readShopOwnerDocument(file(), paths).catch((error: unknown) => error as RegistrationOcrError);

    await vi.waitFor(() => expect(ocrWorker.recognize).toHaveBeenCalledOnce());
    await vi.advanceTimersByTimeAsync(60_000);
    const error = await read;

    expect(error.reasonCode).toBe('ocr_timeout');
    expect(ocrWorker.recognize).toHaveBeenCalledOnce();
    expect(ocrWorker.terminate).toHaveBeenCalledOnce();
    await error.cleanup;
  });

  it('cancels recognition and reports cleanup failure', async () => {
    stubImage();
    const recognition = deferred<typeof recognized>();
    const ocrWorker = worker();
    ocrWorker.recognize.mockReturnValue(recognition.promise);
    ocrWorker.terminate.mockRejectedValue(new Error('termination failed'));
    createWorkerMock.mockResolvedValue(ocrWorker);
    const controller = new AbortController();
    const read = readShopOwnerDocument(file(), { ...paths, signal: controller.signal })
      .catch((error: unknown) => error as RegistrationOcrError);

    await vi.waitFor(() => expect(ocrWorker.recognize).toHaveBeenCalledOnce());
    controller.abort();
    const error = await read;

    expect(error.reasonCode).toBe('scan_unfinished');
    await expect(error.cleanup).rejects.toThrow('termination failed');
  });
});
