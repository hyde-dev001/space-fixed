import { createWorker, PSM } from 'tesseract.js';
import { afterEach, describe, expect, it, vi } from 'vitest';
import {
  areRegistrationImageFingerprintsEqual,
  compareRegistrationImageFingerprints,
  fingerprintRegistrationImage,
  readRegistrationId,
} from './registrationOcr';

vi.mock('tesseract.js', () => ({
  createWorker: vi.fn(),
  PSM: { SINGLE_BLOCK: '6', SPARSE_TEXT: '11' },
}));

const mockedCreateWorker = vi.mocked(createWorker);

describe('registration OCR and image fingerprint helpers', () => {
  afterEach(() => {
    vi.resetAllMocks();
    vi.unstubAllGlobals();
  });

  it('reads text with the English worker and normalizes confidence', async () => {
    const recognize = vi.fn().mockResolvedValue({
      data: {
        text: '  PASSPORT\n  ',
        confidence: 94,
      },
    });
    const terminate = vi.fn().mockResolvedValue(undefined);
    mockedCreateWorker.mockResolvedValue({ recognize, terminate } as never);
    const stages: string[] = [];

    await expect(
      readRegistrationId(
        new File(['image'], 'passport.png', { type: 'image/png' }),
        stage => stages.push(stage),
      ),
    ).resolves.toEqual({
      text: 'PASSPORT',
      confidence: 0.94,
      qrDetected: false,
    });

    expect(mockedCreateWorker).toHaveBeenCalledWith('eng');
    expect(recognize).toHaveBeenCalledTimes(1);
    expect(terminate).toHaveBeenCalledTimes(1);
    expect(stages).toEqual(['loading', 'recognizing']);
  });

  it('keeps an empty OCR result distinct from a runtime failure', async () => {
    const terminate = vi.fn().mockResolvedValue(undefined);
    mockedCreateWorker.mockResolvedValue({
      recognize: vi.fn().mockResolvedValue({
        data: { text: '   ', confidence: 72 },
      }),
      terminate,
    } as never);

    await expect(
      readRegistrationId(new File(['image'], 'blank.png', { type: 'image/png' })),
    ).resolves.toEqual({
      text: '',
      confidence: 0,
      qrDetected: false,
    });
    expect(terminate).toHaveBeenCalledTimes(1);
  });

  it('preserves machine-readable zone line boundaries for screening', async () => {
    const terminate = vi.fn().mockResolvedValue(undefined);
    mockedCreateWorker.mockResolvedValue({
      recognize: vi.fn().mockResolvedValue({
        data: {
          text: 'P<PHLDELA<ROSA<<JUAN<<<<<<<<<<<<<<<<<<<<<<<<\nP1234567<1PHL9001022M3001020<<<<<<<<<<<<<<08',
          confidence: 92,
        },
      }),
      terminate,
    } as never);

    await expect(
      readRegistrationId(new File(['image'], 'passport.png', { type: 'image/png' })),
    ).resolves.toMatchObject({
      text: 'P<PHLDELA<ROSA<<JUAN<<<<<<<<<<<<<<<<<<<<<<<<\nP1234567<1PHL9001022M3001020<<<<<<<<<<<<<<08',
      qrDetected: false,
    });
  });

  it('reports QR presence without returning decoded QR contents', async () => {
    const terminate = vi.fn().mockResolvedValue(undefined);
    const detect = vi.fn().mockResolvedValue([{}]);
    vi.stubGlobal('BarcodeDetector', vi.fn(() => ({ detect })));
    mockedCreateWorker.mockResolvedValue({
      recognize: vi.fn().mockResolvedValue({
        data: { text: 'PHILSYS NATIONAL ID JUAN DELA CRUZ', confidence: 92 },
      }),
      terminate,
    } as never);

    await expect(
      readRegistrationId(new File(['image'], 'national-id.png', { type: 'image/png' })),
    ).resolves.toEqual({
      text: 'PHILSYS NATIONAL ID JUAN DELA CRUZ',
      confidence: 0.92,
      qrDetected: true,
    });
    expect(detect).toHaveBeenCalledTimes(1);
  });

  it('adds bounded oriented back crops when the initial National ID OCR is weak', async () => {
    const recognize = vi.fn()
      .mockResolvedValueOnce({ data: { text: 'SEX MARITAL STATUS', confidence: 20 } })
      .mockResolvedValueOnce({ data: { text: 'BLOOD TYPE', confidence: 30 } })
      .mockResolvedValueOnce({ data: { text: 'PLACE OF BIRTH', confidence: 25 } });
    const terminate = vi.fn().mockResolvedValue(undefined);
    mockedCreateWorker.mockResolvedValue({ recognize, terminate } as never);

    const bitmap = { width: 1200, height: 800, close: vi.fn() };
    const drawImage = vi.fn();
    const canvas = {
      width: 0,
      height: 0,
      getContext: vi.fn(() => ({ drawImage })),
      toBlob: vi.fn((callback: BlobCallback) => callback(new Blob(['cropped-image'], { type: 'image/jpeg' }))),
    };
    vi.stubGlobal('createImageBitmap', vi.fn().mockResolvedValue(bitmap));
    vi.spyOn(document, 'createElement').mockReturnValue(canvas as never);

    await expect(
      readRegistrationId(
        new File(['image'], 'national-id-back.jpg', { type: 'image/jpeg' }),
        undefined,
        { includeNationalIdBackCrops: true },
      ),
    ).resolves.toMatchObject({
      text: 'SEX MARITAL STATUS\nBLOOD TYPE\nPLACE OF BIRTH',
      confidence: 0.3,
    });

    expect(recognize).toHaveBeenCalledTimes(3);
    expect(drawImage).toHaveBeenCalledTimes(3);
    expect(bitmap.close).toHaveBeenCalledTimes(1);
  });

  it('uses the full image for identity text passes when no smaller card region is detected', async () => {
    const recognize = vi.fn()
      .mockResolvedValueOnce({ data: { text: 'LAST NAME DATE OF BIRTH', confidence: 20 } })
      .mockResolvedValueOnce({ data: { text: 'PHILIPPINE NATIONAL ID REPUBLIKA NG PILIPINAS', confidence: 35 } })
      .mockResolvedValueOnce({ data: { text: 'GIVEN NAMES ADDRESS DIGITAL ID NUMBER', confidence: 30 } })
      .mockResolvedValueOnce({ data: { text: '', confidence: 0 } });
    const terminate = vi.fn().mockResolvedValue(undefined);
    const setParameters = vi.fn().mockResolvedValue(undefined);
    mockedCreateWorker.mockResolvedValue({ recognize, setParameters, terminate } as never);

    const bitmap = { width: 1600, height: 1100, close: vi.fn() };
    const drawImage = vi.fn();
    const canvas = {
      width: 0,
      height: 0,
      getContext: vi.fn(() => ({ drawImage })),
      toBlob: vi.fn((callback: BlobCallback) => callback(new Blob(['cropped-image'], { type: 'image/jpeg' }))),
    };
    vi.stubGlobal('createImageBitmap', vi.fn().mockResolvedValue(bitmap));
    vi.spyOn(document, 'createElement').mockReturnValue(canvas as never);

    await expect(
      readRegistrationId(
        new File(['image'], 'digital-national-id-front.jpg', { type: 'image/jpeg' }),
        undefined,
        { includeIdentityTextPasses: true },
      ),
    ).resolves.toMatchObject({
      text: 'LAST NAME DATE OF BIRTH\nPHILIPPINE NATIONAL ID REPUBLIKA NG PILIPINAS\nGIVEN NAMES ADDRESS DIGITAL ID NUMBER',
      confidence: 0.35,
    });

    expect(recognize).toHaveBeenCalledTimes(4);
    expect(setParameters.mock.calls).toEqual([
      [{ tessedit_pageseg_mode: PSM.SINGLE_BLOCK }],
      [{ tessedit_pageseg_mode: PSM.SPARSE_TEXT }],
    ]);
    expect(recognize.mock.calls[1][0]).toBe(recognize.mock.calls[3][0]);
    expect(recognize.mock.calls[2][0]).not.toBe(recognize.mock.calls[1][0]);
    expect(drawImage).toHaveBeenCalledTimes(2);
    expect(bitmap.close).toHaveBeenCalledTimes(1);
  });

  it('uses a dynamically detected card region for supplemental identity text passes', async () => {
    const recognize = vi.fn().mockResolvedValue({ data: { text: 'ID TEXT', confidence: 70 } });
    const setParameters = vi.fn().mockResolvedValue(undefined);
    const terminate = vi.fn().mockResolvedValue(undefined);
    mockedCreateWorker.mockResolvedValue({ recognize, setParameters, terminate } as never);

    const width = 300;
    const height = 210;
    const pixels = new Uint8ClampedArray(width * height * 4);
    for (let y = 45; y < 165; y += 1) {
      for (let x = 60; x < 240; x += 1) {
        const offset = (y * width + x) * 4;
        pixels[offset] = 235;
        pixels[offset + 1] = 235;
        pixels[offset + 2] = 235;
        pixels[offset + 3] = 255;
      }
    }

    const primaryBlob = new Blob(['full-image'], { type: 'image/jpeg' });
    const cardBlob = new Blob(['detected-card'], { type: 'image/jpeg' });
    const primaryDrawImage = vi.fn();
    const cardDrawImage = vi.fn();
    const primaryCanvas = {
      width: 0,
      height: 0,
      getContext: vi.fn(() => ({
        drawImage: primaryDrawImage,
        getImageData: vi.fn(() => ({ data: pixels })),
      })),
      toBlob: vi.fn((callback: BlobCallback) => callback(primaryBlob)),
    };
    const cardCanvas = {
      width: 0,
      height: 0,
      getContext: vi.fn(() => ({ drawImage: cardDrawImage })),
      toBlob: vi.fn((callback: BlobCallback) => callback(cardBlob)),
    };
    const bitmap = { width: 100, height: 70, close: vi.fn() };
    vi.stubGlobal('createImageBitmap', vi.fn().mockResolvedValue(bitmap));
    vi.spyOn(document, 'createElement')
      .mockReturnValueOnce(primaryCanvas as never)
      .mockReturnValueOnce(cardCanvas as never);

    await readRegistrationId(
      new File(['image'], 'photo-of-monitor.jpg', { type: 'image/jpeg' }),
      undefined,
      { includeIdentityTextPasses: true },
    );

    expect(recognize).toHaveBeenCalledTimes(3);
    expect(recognize.mock.calls[0][0]).toBe(primaryBlob);
    expect(recognize.mock.calls[1][0]).toBe(cardBlob);
    expect(recognize.mock.calls[2][0]).toBe(cardBlob);
    expect(cardDrawImage).toHaveBeenCalledTimes(1);
  });
  it('always reads identity-name crops when full-image OCR is long and high-confidence', async () => {
    const noisyBackgroundText = 'PHILIPPINE STATISTICS AUTHORITY '.repeat(40);
    const recognize = vi.fn()
      .mockResolvedValueOnce({ data: { text: noisyBackgroundText, confidence: 92 } })
      .mockResolvedValueOnce({ data: { text: 'LAST NAME BATO', confidence: 70 } })
      .mockResolvedValueOnce({ data: { text: 'GIVEN NAMES FRANSZINE MIGUEL', confidence: 72 } })
      .mockResolvedValueOnce({ data: { text: '', confidence: 0 } });
    const setParameters = vi.fn().mockResolvedValue(undefined);
    const terminate = vi.fn().mockResolvedValue(undefined);
    mockedCreateWorker.mockResolvedValue({ recognize, setParameters, terminate } as never);

    const bitmap = { width: 1600, height: 1100, close: vi.fn() };
    const canvas = {
      width: 0,
      height: 0,
      getContext: vi.fn(() => ({ drawImage: vi.fn() })),
      toBlob: vi.fn((callback: BlobCallback) => callback(new Blob(['image'], { type: 'image/jpeg' }))),
    };
    vi.stubGlobal('createImageBitmap', vi.fn().mockResolvedValue(bitmap));
    vi.spyOn(document, 'createElement').mockReturnValue(canvas as never);

    const result = await readRegistrationId(
      new File(['image'], 'national-id-front.jpg', { type: 'image/jpeg' }),
      undefined,
      { includeIdentityTextPasses: true },
    );

    expect(recognize).toHaveBeenCalledTimes(4);
    expect(setParameters.mock.calls).toEqual([
      [{ tessedit_pageseg_mode: PSM.SINGLE_BLOCK }],
      [{ tessedit_pageseg_mode: PSM.SPARSE_TEXT }],
    ]);
    expect(result.text).toContain('LAST NAME BATO');
    expect(result.text).toContain('GIVEN NAMES FRANSZINE MIGUEL');
  });

  it('runs a dedicated upscaled name-field pass for small identity text', async () => {
    const recognize = vi.fn()
      .mockResolvedValueOnce({ data: { text: 'PHILIPPINE NATIONAL ID', confidence: 82 } })
      .mockResolvedValueOnce({ data: { text: 'REPUBLIKA NG PILIPINAS', confidence: 68 } })
      .mockResolvedValueOnce({ data: { text: 'LAST NAME BATO GIVEN NAMES FRANSZINE MIGUEL', confidence: 74 } })
      .mockResolvedValueOnce({ data: { text: 'DATE OF BIRTH ADDRESS', confidence: 61 } });
    const setParameters = vi.fn().mockResolvedValue(undefined);
    const terminate = vi.fn().mockResolvedValue(undefined);
    mockedCreateWorker.mockResolvedValue({ recognize, setParameters, terminate } as never);

    const primaryBlob = new Blob(['full-image'], { type: 'image/jpeg' });
    const nameBlob = new Blob(['name-crop'], { type: 'image/jpeg' });
    const bitmap = { width: 600, height: 400, close: vi.fn() };
    const primaryCanvas = {
      width: 0,
      height: 0,
      getContext: vi.fn(() => ({ drawImage: vi.fn() })),
      toBlob: vi.fn((callback: BlobCallback) => callback(primaryBlob)),
    };
    const nameCanvas = {
      width: 0,
      height: 0,
      getContext: vi.fn(() => ({ drawImage: vi.fn() })),
      toBlob: vi.fn((callback: BlobCallback) => callback(nameBlob)),
    };
    vi.stubGlobal('createImageBitmap', vi.fn().mockResolvedValue(bitmap));
    vi.spyOn(document, 'createElement')
      .mockReturnValueOnce(primaryCanvas as never)
      .mockReturnValueOnce(nameCanvas as never);

    const result = await readRegistrationId(
      new File(['image'], 'small-national-id.jpg', { type: 'image/jpeg' }),
      undefined,
      { includeIdentityTextPasses: true },
    );

    expect(recognize).toHaveBeenCalledTimes(4);
    expect(recognize.mock.calls[2][0]).toBe(nameBlob);
    expect(result.text).toContain('LAST NAME BATO GIVEN NAMES FRANSZINE MIGUEL');
    expect(nameCanvas.width).toBeGreaterThan(0);
    expect(nameCanvas.height).toBeGreaterThan(0);
  });
  it('upscales small ID screenshots before OCR', async () => {
    const terminate = vi.fn().mockResolvedValue(undefined);
    mockedCreateWorker.mockResolvedValue({
      recognize: vi.fn().mockResolvedValue({
        data: { text: 'PHILIPPINE NATIONAL ID', confidence: 80 },
      }),
      terminate,
    } as never);

    const bitmap = { width: 600, height: 400, close: vi.fn() };
    const canvas = {
      width: 0,
      height: 0,
      getContext: vi.fn(() => ({ drawImage: vi.fn() })),
      toBlob: vi.fn((callback: BlobCallback) => callback(new Blob(['upscaled-image'], { type: 'image/jpeg' }))),
    };
    vi.stubGlobal('createImageBitmap', vi.fn().mockResolvedValue(bitmap));
    vi.spyOn(document, 'createElement').mockReturnValue(canvas as never);

    await readRegistrationId(new File(['image'], 'national-id.png', { type: 'image/png' }));

    expect(canvas.width).toBe(1800);
    expect(canvas.height).toBe(1200);
  });

  it('terminates the worker and propagates OCR failures for screening_error handling', async () => {
    const terminate = vi.fn().mockResolvedValue(undefined);
    mockedCreateWorker.mockResolvedValue({
      recognize: vi.fn().mockRejectedValue(new Error('OCR failed')),
      terminate,
    } as never);

    await expect(
      readRegistrationId(new File(['image'], 'id.png', { type: 'image/png' })),
    ).rejects.toThrow('OCR failed');
    expect(terminate).toHaveBeenCalledTimes(1);
  });

  it('propagates worker initialization failures without creating an unhandled rejection', async () => {
    mockedCreateWorker.mockRejectedValue(new Error('OCR worker unavailable'));

    await expect(
      readRegistrationId(new File(['image'], 'id.png', { type: 'image/png' })),
    ).rejects.toThrow('OCR worker unavailable');
  });

  it('detects the same bytes when filenames differ', async () => {
    const front = await fingerprintRegistrationImage(
      new File(['same-image'], 'front.png', { type: 'image/png' }),
    );
    const back = await fingerprintRegistrationImage(
      new File(['same-image'], 'renamed-back.png', { type: 'image/png' }),
    );

    expect(compareRegistrationImageFingerprints(front, back)).toBe('exact');
    expect(areRegistrationImageFingerprintsEqual(front, back)).toBe(true);
  });

  it('does not mark different image bytes as duplicate', async () => {
    const front = await fingerprintRegistrationImage(
      new File(['front-image'], 'front.png', { type: 'image/png' }),
    );
    const back = await fingerprintRegistrationImage(
      new File(['back-image'], 'back.png', { type: 'image/png' }),
    );

    expect(compareRegistrationImageFingerprints(front, back)).toBe('none');
    expect(areRegistrationImageFingerprintsEqual(front, back)).toBe(false);
  });

  it('keeps exact fingerprints tied to original bytes when a perceptual raster is available', async () => {
    const bitmap = { width: 1200, height: 800, close: vi.fn() };
    const pixels = new Uint8ClampedArray(32 * 32 * 4);
    const canvas = {
      width: 0,
      height: 0,
      getContext: vi.fn(() => ({
        drawImage: vi.fn(),
        getImageData: vi.fn(() => ({ width: 32, height: 32, data: pixels })),
      })),
    };

    vi.stubGlobal('ImageData', class {});
    vi.stubGlobal('createImageBitmap', vi.fn().mockResolvedValue(bitmap));
    vi.spyOn(document, 'createElement').mockReturnValue(canvas as never);

    const front = await fingerprintRegistrationImage(
      new File(['front-image'], 'front.png', { type: 'image/png' }),
    );
    const back = await fingerprintRegistrationImage(
      new File(['back-image'], 'back.png', { type: 'image/png' }),
    );

    expect(front.exact).not.toBe(back.exact);
    expect(front.perceptual).toBe(back.perceptual);
  });

  it('detects near duplicates from normalized perceptual signatures', () => {
    expect(compareRegistrationImageFingerprints(
      { exact: 'front', perceptual: '0'.repeat(64) },
      { exact: 'resaved-front', perceptual: '1'.repeat(64) },
    )).toBe('near');
  });

  it('does not mark different card content on the same background as a near duplicate', () => {
    const frontSignature = '0'.repeat(64);
    const backSignature = `${'0'.repeat(28)}${'8'.repeat(8)}${'0'.repeat(28)}`;

    expect(compareRegistrationImageFingerprints(
      { exact: 'front', perceptual: frontSignature },
      { exact: 'back', perceptual: backSignature },
    )).toBe('none');
  });
});
