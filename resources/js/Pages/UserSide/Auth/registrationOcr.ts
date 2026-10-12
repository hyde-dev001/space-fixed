import { createWorker, PSM } from 'tesseract.js';

export type RegistrationDocumentSide = 'front' | 'back' | 'biodata';

export type RegistrationOcrStage = 'loading' | 'recognizing';

export type RegistrationOcrResult = {
  text: string;
  confidence: number;
  qrDetected: boolean;
};

export type RegistrationOcrOptions = {
  includeIdentityTextPasses?: boolean;
  includeNationalIdBackCrops?: boolean;
};

export type RegistrationImageFingerprint = {
  exact: string;
  perceptual: string | null;
};

export type RegistrationDuplicateKind = 'none' | 'exact' | 'near';

const OCR_TIMEOUT_MS = 60_000;
const OCR_TARGET_MAX_DIMENSION = 1_800;
const OCR_MAX_UPSCALE = 3;
const FINGERPRINT_SIZE = 32;
const PERCEPTUAL_GRID_SIZE = 8;
const NEAR_DUPLICATE_AVERAGE_DELTA = 1.5;
// Prevent a shared background from hiding a meaningful local difference in
// the ID/card area of two otherwise similarly framed photos.
const NEAR_DUPLICATE_MAX_BLOCK_DELTA = 2;

const normalizeOcrText = (value: string): string => value
  .replace(/\r\n?/g, '\n')
  .split('\n')
  .map(line => line.replace(/\s+/g, ' ').trim())
  .filter(Boolean)
  .join('\n');

type PreparedOcrImages = {
  primary: Blob | File;
  identitySource: Blob | File;
  identityNameSource: Blob | null;
  supplemental: Blob[];
};

type ImageBounds = {
  left: number;
  top: number;
  width: number;
  height: number;
};

const NATIONAL_ID_BACK_CROPS = [
  { left: 0, top: 0.08, width: 0.62, height: 0.52 },
  { left: 0, top: 0.38, width: 0.68, height: 0.52 },
] as const;

// Keep the name pass focused enough to enlarge small ID text, while leaving
// the full-image and card-region passes available for layouts such as
// passports and driver's licenses.
const IDENTITY_NAME_CROP = { left: 0.12, top: 0.12, width: 0.76, height: 0.58 } as const;

const canvasToJpeg = (canvas: HTMLCanvasElement): Promise<Blob | null> => (
  new Promise(resolve => canvas.toBlob(resolve, 'image/jpeg', 0.92))
);


const largestContiguousRange = (matches: boolean[]): [number, number] | null => {
  let bestStart = -1;
  let bestEnd = -1;
  let currentStart = -1;

  for (let index = 0; index <= matches.length; index += 1) {
    if (matches[index] === true) {
      if (currentStart < 0) currentStart = index;
      continue;
    }

    if (currentStart >= 0 && index - currentStart > bestEnd - bestStart) {
      bestStart = currentStart;
      bestEnd = index;
    }
    currentStart = -1;
  }

  return bestStart >= 0 ? [bestStart, bestEnd] : null;
};

const detectDocumentRegion = (
  context: CanvasRenderingContext2D,
  width: number,
  height: number,
): ImageBounds | null => {
  if (typeof context.getImageData !== 'function') return null;

  const pixels = context.getImageData(0, 0, width, height).data;
  const step = Math.max(1, Math.floor(Math.min(width, height) / 300));
  const sampledColumns = Math.ceil(width / step);
  const rowMatches: boolean[] = [];

  for (let y = 0; y < height; y += step) {
    let bright = 0;
    for (let x = 0; x < width; x += step) {
      const offset = (y * width + x) * 4;
      const luminance = pixels[offset] * 0.2126
        + pixels[offset + 1] * 0.7152
        + pixels[offset + 2] * 0.0722;
      if (luminance >= 180) bright += 1;
    }
    rowMatches.push(bright / sampledColumns >= 0.35);
  }

  const rowRange = largestContiguousRange(rowMatches);
  if (!rowRange) return null;
  const top = rowRange[0] * step;
  const bottom = Math.min(height, rowRange[1] * step);
  if (bottom - top < height * 0.25) return null;

  const sampledRows = Math.max(1, Math.ceil((bottom - top) / step));
  const columnMatches: boolean[] = [];
  for (let x = 0; x < width; x += step) {
    let bright = 0;
    for (let y = top; y < bottom; y += step) {
      const offset = (y * width + x) * 4;
      const luminance = pixels[offset] * 0.2126
        + pixels[offset + 1] * 0.7152
        + pixels[offset + 2] * 0.0722;
      if (luminance >= 180) bright += 1;
    }
    columnMatches.push(bright / sampledRows >= 0.45);
  }

  const columnRange = largestContiguousRange(columnMatches);
  if (!columnRange) return null;
  const left = columnRange[0] * step;
  const right = Math.min(width, columnRange[1] * step);
  const regionWidth = right - left;
  const regionHeight = bottom - top;
  const areaRatio = (regionWidth * regionHeight) / (width * height);
  const aspectRatio = regionWidth / regionHeight;

  if (regionWidth < width * 0.35
    || regionHeight < height * 0.25
    || areaRatio < 0.12
    || areaRatio > 0.9
    || aspectRatio < 1.2
    || aspectRatio > 2.2) {
    return null;
  }

  return { left, top, width: regionWidth, height: regionHeight };
};
const prepareOcrImages = async (

  file: File,
  includeIdentityTextPasses: boolean,
  includeNationalIdBackCrops: boolean,
): Promise<PreparedOcrImages> => {
  const createImageBitmapFunction = globalThis.createImageBitmap;
  if (typeof document === 'undefined' || typeof createImageBitmapFunction !== 'function') {
    return { primary: file, identitySource: file, identityNameSource: null, supplemental: [] };
  }

  try {
    const bitmap = await createImageBitmapFunction(file, { imageOrientation: 'from-image' });
    const canvas = document.createElement('canvas');
    const scale = Math.max(
      1,
      Math.min(OCR_MAX_UPSCALE, OCR_TARGET_MAX_DIMENSION / Math.max(bitmap.width, bitmap.height)),
    );
    canvas.width = Math.round(bitmap.width * scale);
    canvas.height = Math.round(bitmap.height * scale);
    const context = canvas.getContext('2d');

    if (!context) {
      bitmap.close();
      return { primary: file, identitySource: file, identityNameSource: null, supplemental: [] };
    }

    context.drawImage(bitmap, 0, 0, canvas.width, canvas.height);
    bitmap.close();

    const orientedBlob = await canvasToJpeg(canvas);
    if (!orientedBlob) return { primary: file, identitySource: file, identityNameSource: null, supplemental: [] };

    let identitySource: Blob | File = orientedBlob;
    let identityNameSource: Blob | null = null;
    if (includeIdentityTextPasses) {
      const documentRegion = detectDocumentRegion(context, canvas.width, canvas.height);
      if (documentRegion) {
        const regionCanvas = document.createElement('canvas');
        const regionScale = Math.max(
          1,
          Math.min(OCR_MAX_UPSCALE, OCR_TARGET_MAX_DIMENSION / Math.max(documentRegion.width, documentRegion.height)),
        );
        regionCanvas.width = Math.round(documentRegion.width * regionScale);
        regionCanvas.height = Math.round(documentRegion.height * regionScale);
        const regionContext = regionCanvas.getContext('2d');
        if (regionContext) {
          regionContext.drawImage(
            canvas,
            documentRegion.left,
            documentRegion.top,
            documentRegion.width,
            documentRegion.height,
            0,
            0,
            regionCanvas.width,
            regionCanvas.height,
          );
          identitySource = await canvasToJpeg(regionCanvas) ?? orientedBlob;
        }
      }

      const sourceBounds = documentRegion ?? {
        left: 0,
        top: 0,
        width: canvas.width,
        height: canvas.height,
      };
      const nameLeft = Math.max(0, Math.round(sourceBounds.left + sourceBounds.width * IDENTITY_NAME_CROP.left));
      const nameTop = Math.max(0, Math.round(sourceBounds.top + sourceBounds.height * IDENTITY_NAME_CROP.top));
      const nameWidth = Math.max(1, Math.round(sourceBounds.width * IDENTITY_NAME_CROP.width));
      const nameHeight = Math.max(1, Math.round(sourceBounds.height * IDENTITY_NAME_CROP.height));
      const nameCanvas = document.createElement('canvas');

      if (nameCanvas) {
        const nameScale = Math.max(
          1,
          Math.min(OCR_MAX_UPSCALE, OCR_TARGET_MAX_DIMENSION / Math.max(nameWidth, nameHeight)),
        );
        nameCanvas.width = Math.round(nameWidth * nameScale);
        nameCanvas.height = Math.round(nameHeight * nameScale);
        const nameContext = nameCanvas.getContext('2d');

        if (nameContext) {
          nameContext.drawImage(
            canvas,
            nameLeft,
            nameTop,
            nameWidth,
            nameHeight,
            0,
            0,
            nameCanvas.width,
            nameCanvas.height,
          );
          identityNameSource = await canvasToJpeg(nameCanvas);
        }
      }
    }

    const supplemental: Blob[] = [];
    const crops = includeNationalIdBackCrops ? NATIONAL_ID_BACK_CROPS : [];

    for (const crop of crops) {
      const sourceLeft = Math.max(0, Math.round(canvas.width * crop.left));
      const sourceWidth = Math.max(1, Math.round(canvas.width * crop.width));
      const sourceHeight = Math.max(1, Math.round(canvas.height * crop.height));
      const sourceTop = Math.max(0, Math.round(canvas.height * crop.top));
      const cropCanvas = document.createElement('canvas');
      cropCanvas.width = sourceWidth * 2;
      cropCanvas.height = sourceHeight * 2;
      const cropContext = cropCanvas.getContext('2d');

      if (!cropContext) continue;

      cropContext.drawImage(
        canvas,
        sourceLeft,
        sourceTop,
        sourceWidth,
        sourceHeight,
        0,
        0,
        cropCanvas.width,
        cropCanvas.height,
      );
      const cropBlob = await canvasToJpeg(cropCanvas);
      if (cropBlob) supplemental.push(cropBlob);
    }

    return { primary: orientedBlob, identitySource, identityNameSource, supplemental };
  } catch {
    return { primary: file, identitySource: file, identityNameSource: null, supplemental: [] };
  }
};

type BarcodeDetectorLike = new (options?: { formats?: string[] }) => {
  detect(source: Blob): Promise<unknown[]>;
};

const detectQrCode = async (file: File): Promise<boolean> => {
  const BarcodeDetector = (globalThis as typeof globalThis & {
    BarcodeDetector?: BarcodeDetectorLike;
  }).BarcodeDetector;

  if (!BarcodeDetector) return false;

  try {
    const detector = new BarcodeDetector({ formats: ['qr_code'] });
    const results = await detector.detect(file);
    return Array.isArray(results) && results.length > 0;
  } catch {
    return false;
  }
};

async function withTimeout<T>(promise: Promise<T>, message: string): Promise<T> {
  let timeoutId: ReturnType<typeof setTimeout> | undefined;

  try {
    return await Promise.race([
      promise,
      new Promise<T>((_, reject) => {
        timeoutId = setTimeout(() => reject(new Error(message)), OCR_TIMEOUT_MS);
      }),
    ]);
  } finally {
    if (timeoutId !== undefined) clearTimeout(timeoutId);
  }
}

const digestBytes = async (bytes: Uint8Array): Promise<string> => {
  const subtle = globalThis.crypto?.subtle;

  if (subtle) {
    const digest = await subtle.digest('SHA-256', bytes);
    return Array.from(new Uint8Array(digest), byte => byte.toString(16).padStart(2, '0')).join('');
  }

  // This fallback is only for browsers without Web Crypto. It remains
  // deterministic for the client-side UX check; it is not a server trust
  // boundary and is never used as authoritative evidence.
  let hash = 2166136261;
  for (const byte of bytes) {
    hash ^= byte;
    hash = Math.imul(hash, 16777619);
  }

  return `fnv1a-${(hash >>> 0).toString(16).padStart(8, '0')}`;
};

const readFileBytes = async (file: File): Promise<ArrayBuffer> => {
  if (typeof file.arrayBuffer === 'function') return file.arrayBuffer();

  if (typeof FileReader !== 'undefined') {
    return new Promise<ArrayBuffer>((resolve, reject) => {
      const reader = new FileReader();
      reader.onload = () => {
        if (reader.result instanceof ArrayBuffer) resolve(reader.result);
        else reject(new Error('Image bytes could not be read.'));
      };
      reader.onerror = () => reject(reader.error ?? new Error('Image bytes could not be read.'));
      reader.readAsArrayBuffer(file);
    });
  }

  return new Response(file).arrayBuffer();
};

const imageDataToPerceptualSignature = (imageData: ImageData): string => {
  const blockWidth = imageData.width / PERCEPTUAL_GRID_SIZE;
  const blockHeight = imageData.height / PERCEPTUAL_GRID_SIZE;
  const values: number[] = [];

  for (let row = 0; row < PERCEPTUAL_GRID_SIZE; row += 1) {
    for (let column = 0; column < PERCEPTUAL_GRID_SIZE; column += 1) {
      let total = 0;
      let count = 0;
      const startX = Math.floor(column * blockWidth);
      const endX = Math.max(startX + 1, Math.floor((column + 1) * blockWidth));
      const startY = Math.floor(row * blockHeight);
      const endY = Math.max(startY + 1, Math.floor((row + 1) * blockHeight));

      for (let y = startY; y < endY; y += 1) {
        for (let x = startX; x < endX; x += 1) {
          const index = (y * imageData.width + x) * 4;
          total += (imageData.data[index] * 299
            + imageData.data[index + 1] * 587
            + imageData.data[index + 2] * 114) / 1000;
          count += 1;
        }
      }

      values.push(Math.round((total / Math.max(count, 1)) / 16));
    }
  }

  return values.map(value => value.toString(16)).join('');
};

const rasterizeForFingerprint = async (file: File): Promise<{
  perceptual: string;
} | null> => {
  if (typeof document === 'undefined' || typeof ImageData === 'undefined') return null;

  try {
    const createImageBitmapFunction = globalThis.createImageBitmap;
    if (typeof createImageBitmapFunction !== 'function') return null;

    const bitmap = await createImageBitmapFunction(file, {
      imageOrientation: 'from-image',
    } as ImageBitmapOptions);
    const canvas = document.createElement('canvas');
    canvas.width = FINGERPRINT_SIZE;
    canvas.height = FINGERPRINT_SIZE;
    const context = canvas.getContext('2d', { willReadFrequently: true });

    if (!context) {
      bitmap.close();
      return null;
    }

    context.drawImage(bitmap, 0, 0, FINGERPRINT_SIZE, FINGERPRINT_SIZE);
    bitmap.close();
    const imageData = context.getImageData(0, 0, FINGERPRINT_SIZE, FINGERPRINT_SIZE);

    return {
      perceptual: imageDataToPerceptualSignature(imageData),
    };
  } catch {
    return null;
  }
};

const perceptualDifference = (left: string, right: string): number | null => {
  if (!left || left.length !== right.length) return null;

  const total = left.split('').reduce((sum, value, index) => (
    sum + Math.abs(parseInt(value, 16) - parseInt(right[index], 16))
  ), 0);

  return total / left.length;
};

const perceptualMaximumDifference = (left: string, right: string): number | null => {
  if (!left || left.length !== right.length) return null;

  return left.split('').reduce((maximum, value, index) => (
    Math.max(maximum, Math.abs(parseInt(value, 16) - parseInt(right[index], 16)))
  ), 0);
};

export const compareRegistrationImageFingerprints = (
  front: RegistrationImageFingerprint,
  back: RegistrationImageFingerprint,
): RegistrationDuplicateKind => {
  if (front.exact === back.exact) return 'exact';

  const difference = front.perceptual && back.perceptual
    ? perceptualDifference(front.perceptual, back.perceptual)
    : null;
  const maximumDifference = front.perceptual && back.perceptual
    ? perceptualMaximumDifference(front.perceptual, back.perceptual)
    : null;

  return difference !== null
    && difference <= NEAR_DUPLICATE_AVERAGE_DELTA
    && maximumDifference !== null
    && maximumDifference <= NEAR_DUPLICATE_MAX_BLOCK_DELTA
    ? 'near'
    : 'none';
};

export const areRegistrationImageFingerprintsEqual = (
  front: RegistrationImageFingerprint,
  back: RegistrationImageFingerprint,
): boolean => compareRegistrationImageFingerprints(front, back) !== 'none';

export async function fingerprintRegistrationImage(file: File): Promise<RegistrationImageFingerprint> {
  const exact = await digestBytes(new Uint8Array(await readFileBytes(file)));
  const rasterized = await rasterizeForFingerprint(file);

  return {
    exact,
    perceptual: rasterized?.perceptual ?? null,
  };
}

export async function readRegistrationId(
  file: File,
  onStage?: (stage: RegistrationOcrStage) => void,
  options: RegistrationOcrOptions = {},
): Promise<RegistrationOcrResult> {
  onStage?.('loading');
  const workerPromise = createWorker('eng');
  let abandonWorker = false;
  let worker: Awaited<ReturnType<typeof createWorker>> | null = null;

  void workerPromise.then(
    (createdWorker) => {
      if (abandonWorker) void createdWorker.terminate().catch(() => undefined);
    },
    () => undefined,
  );

  try {
    worker = await withTimeout(workerPromise, 'OCR initialization timed out');
    onStage?.('recognizing');
    const preparedImages = await prepareOcrImages(
      file,
      options.includeIdentityTextPasses === true,
      options.includeNationalIdBackCrops === true,
    );
    const results = [await withTimeout(worker.recognize(preparedImages.primary), 'OCR processing timed out')];
    const firstText = typeof results[0].data?.text === 'string' ? normalizeOcrText(results[0].data.text) : '';
    const firstConfidence = typeof results[0].data?.confidence === 'number'
      && Number.isFinite(results[0].data.confidence)
      && results[0].data.confidence >= 0
      && results[0].data.confidence <= 100
      && firstText !== ''
      ? results[0].data.confidence / 100
      : 0;

    if (options.includeIdentityTextPasses === true) {
      await withTimeout(
        worker.setParameters({ tessedit_pageseg_mode: PSM.SINGLE_BLOCK }),
        'OCR configuration timed out',
      );
      results.push(await withTimeout(worker.recognize(preparedImages.identitySource), 'OCR processing timed out'));

      if (preparedImages.identityNameSource) {
        results.push(await withTimeout(worker.recognize(preparedImages.identityNameSource), 'OCR processing timed out'));
      }

      await withTimeout(
        worker.setParameters({ tessedit_pageseg_mode: PSM.SPARSE_TEXT }),
        'OCR configuration timed out',
      );
      results.push(await withTimeout(worker.recognize(preparedImages.identitySource), 'OCR processing timed out'));
    } else {
      const shouldUseSupplementalOcr = preparedImages.supplemental.length > 0
        && (firstConfidence < 0.65 || firstText.length < 900);

      if (shouldUseSupplementalOcr) {
        for (const supplementalImage of preparedImages.supplemental) {
          results.push(await withTimeout(worker.recognize(supplementalImage), 'OCR processing timed out'));
        }
      }
    }

    const text = results
      .map(result => typeof result.data?.text === 'string' ? normalizeOcrText(result.data.text) : '')
      .filter(Boolean)
      .join('\n');
    const confidence = results.reduce((highest, result) => {
      const resultText = typeof result.data?.text === 'string' ? normalizeOcrText(result.data.text) : '';
      const resultConfidence = typeof result.data?.confidence === 'number'
        && Number.isFinite(result.data.confidence)
        && result.data.confidence >= 0
        && result.data.confidence <= 100
        && resultText !== ''
        ? result.data.confidence / 100
        : 0;

      return Math.max(highest, resultConfidence);
    }, 0);
    let qrDetected = false;

    try {
      qrDetected = await withTimeout(detectQrCode(preparedImages.primary), 'QR detection timed out');
    } catch {
      qrDetected = false;
    }

    return { text, confidence, qrDetected };
  } catch (error) {
    if (worker === null) abandonWorker = true;
    throw error;
  } finally {
    if (worker !== null) await worker.terminate().catch(() => undefined);
  }
}
