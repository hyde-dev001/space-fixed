import { beforeEach, describe, expect, it, vi } from 'vitest';
import {
  createRegistrationScreeningError,
  detectRegistrationDocumentRejection,
  screenRegistrationDocumentSide,
  screenRegistrationDocumentSideFromFile,
  screenRegistrationSubmission,
} from './registrationDocumentScreening';
import {
  fingerprintRegistrationImage,
  readRegistrationId,
} from './registrationOcr';
import type { RegistrationDocumentSide, RegistrationOcrResult } from './registrationOcr';

vi.mock('./registrationOcr', async (importOriginal) => {
  const actual = await importOriginal<typeof import('./registrationOcr')>();

  return {
    ...actual,
    fingerprintRegistrationImage: vi.fn(),
    readRegistrationId: vi.fn(),
  };
});

const mockedFingerprintRegistrationImage = vi.mocked(fingerprintRegistrationImage);
const mockedReadRegistrationId = vi.mocked(readRegistrationId);

const ocr = (text: string, confidence = 0.94, qrDetected = false): RegistrationOcrResult => ({
  text,
  confidence,
  qrDetected,
});

const driverFrontText = 'REPUBLIC OF THE PHILIPPINES LAND TRANSPORTATION OFFICE LTO DRIVER LICENSE NAME JUAN DELA CRUZ DATE OF BIRTH 01/02/1990 LICENSE NO A1234567 EXP 01/02/2030';

describe('registration document plausibility screening', () => {
  beforeEach(() => {
    mockedFingerprintRegistrationImage.mockResolvedValue({ exact: 'fingerprint', perceptual: null });
    mockedReadRegistrationId.mockResolvedValue(ocr('DOCUMENT EVIDENCE'));
  });

  it.each([
    ['national_id', 'front', 'digital_image', true, false],
    ['umid', 'front', 'physical_card', true, false],
    ['drivers_license', 'front', 'physical_card', true, false],
    ['passport', 'biodata', 'physical_card', true, false],
    ['national_id', 'back', 'digital_image', false, true],
    ['drivers_license', 'back', 'physical_card', false, false],
  ] as const)(
    'routes %s %s through the expected supplemental OCR crops',
    async (documentType, side, format, includeIdentityTextPasses, includeNationalIdBackCrops) => {
      const file = new File(['image'], `${documentType}-${side}.jpg`, { type: 'image/jpeg' });

      await screenRegistrationDocumentSideFromFile(documentType, side, file, undefined, format);

      expect(mockedReadRegistrationId).toHaveBeenLastCalledWith(file, undefined, {
        includeIdentityTextPasses,
        includeNationalIdBackCrops,
      });
    },
  );

  it('requires Philippine/LTO and driver-specific evidence on a license front', () => {
    const result = screenRegistrationDocumentSide(
      'drivers_license',
      'front',
      ocr(driverFrontText),
    );

    expect(result.outcome).toBe('plausible');
    expect(result.detectedDocumentFamily).toBe('drivers_license');
    expect(result.detectedAnchorKeys).toEqual(expect.arrayContaining(['lto_issuer', 'driver_license', 'philippine_issuer']));
  });

  it('rejects a foreign driver license even when generic fields are present', () => {
    const result = screenRegistrationDocumentSide(
      'drivers_license',
      'front',
      ocr('HAWAII DRIVER LICENSE NAME JUAN DELA CRUZ DATE OF BIRTH 01/02/1990 EXP 01/02/2030 ADDRESS HONOLULU'),
    );

    expect(result.outcome).toBe('reject_upload');
    expect(result.validationNotes).toContain('foreign_document');
  });

  it('queues generic driver-license text without Philippine issuer evidence', () => {
    const result = screenRegistrationDocumentSide(
      'drivers_license',
      'front',
      ocr('DRIVER LICENSE NAME JUAN DELA CRUZ DATE OF BIRTH 01/02/1990 EXP 01/02/2030 ADDRESS MANILA'),
    );

    expect(result.outcome).toBe('uncertain');
    expect(result.validationNotes).toContain('weak_driver_anchors');
  });

  it('routes plausible but incomplete driver evidence to manual review', () => {
    const front = screenRegistrationDocumentSide(
      'drivers_license',
      'front',
      ocr('DRIVER LICENSE NAME JUAN DELA CRUZ DATE OF BIRTH 01/02/1990 EXP 01/02/2030 ADDRESS MANILA'),
    );
    const back = screenRegistrationDocumentSide(
      'drivers_license',
      'back',
      ocr('DL CODE A B RESTRICTION CONDITIONS'),
    );

    expect(front.outcome).toBe('uncertain');

    const result = screenRegistrationSubmission(
      'drivers_license',
      { front, back },
      'none',
      'physical_card',
      'matched',
    );

    expect(result.outcome).toBe('manual_review_required');
    expect(result.failureSide).toBe('front');
  });

  it('does not expose an uncertain side as a hard rejection to legacy callers', () => {
    expect(
      detectRegistrationDocumentRejection(
        'drivers_license',
        'DRIVER LICENSE NAME JUAN DELA CRUZ DATE OF BIRTH 01/02/1990 EXP 01/02/2030 ADDRESS MANILA',
      ),
    ).toBeNull();
  });

  it('does not require front identity fields on a driver-license back', () => {
    const result = screenRegistrationDocumentSide(
      'drivers_license',
      'back',
      ocr('DL CODE A B RESTRICTION CONDITIONS', 0.22),
    );

    expect(result.outcome).toBe('plausible');
    expect(result.detectedAnchorKeys).toContain('driver_back_structure');
    expect(result.confidenceBand).toBe('low');
  });

  it('accepts National ID front and a low-text back with document-specific structure', () => {
    const front = screenRegistrationDocumentSide(
      'national_id',
      'front',
      ocr('PHILIPPINE IDENTIFICATION CARD PHILSYS REPUBLIC OF THE PHILIPPINES NAME JUAN DELA CRUZ DATE OF BIRTH 01/02/1990 PCN 1234567890123456'),
    );
    const back = screenRegistrationDocumentSide(
      'national_id',
      'back',
      ocr('PHILSYS QR', 0.18, true),
    );

    expect(front.outcome).toBe('plausible');
    expect(back.outcome).toBe('plausible');
    expect(back.detectedAnchorKeys).toContain('qr_supplemental');
  });

  it('accepts digital National ID front and back screens in digital mode', () => {
    const front = screenRegistrationDocumentSide(
      'national_id',
      'front',
      ocr('PHILIPPINE IDENTIFICATION SYSTEM EPHILID REPUBLIC OF THE PHILIPPINES FULL NAME JUAN DELA CRUZ DATE OF BIRTH 01/02/1990 PHILSYS CARD NUMBER 1234567890123456 DIGITAL ID NUMBER ABC123 SHOW BACK ID'),
      null,
      'digital_image',
    );
    const back = screenRegistrationDocumentSide(
      'national_id',
      'back',
      ocr('SEX FEMALE BLOOD TYPE O+ MARITAL STATUS SINGLE PLACE OF BIRTH TONDO MANILA', 0.22),
      null,
      'digital_image',
    );
    const result = screenRegistrationSubmission(
      'national_id',
      { front, back },
      'none',
      'digital_image',
    );

    expect(front.outcome).toBe('plausible');
    expect(back.outcome).toBe('plausible');
    expect(result.outcome).toBe('manual_review_required');
  });

  it('queues a selected National ID for review when OCR reports a conflicting UMID family', () => {
    const front = screenRegistrationDocumentSide(
      'national_id',
      'front',
      ocr('UMID SSS REPUBLIC OF THE PHILIPPINES NAME JUAN DATE OF BIRTH 01/02/1990 PCN 1234-5678-9101-1213 ADDRESS MANILA'),
      null,
      'digital_image',
    );
    const back = screenRegistrationDocumentSide(
      'national_id',
      'back',
      ocr('SEX MALE BLOOD TYPE O MARITAL STATUS SINGLE PLACE OF BIRTH MANILA', 0.22, true),
      null,
      'digital_image',
    );

    const result = screenRegistrationSubmission(
      'national_id',
      { front, back },
      'none',
      'digital_image',
      'matched',
    );

    expect(front.outcome).toBe('uncertain');
    expect(front.validationNotes).toContain('document_family_conflict');
    expect(result.outcome).toBe('manual_review_required');
  });

  it('accepts a National ID digital back screen when the format remains at its default', () => {
    const result = screenRegistrationDocumentSide(
      'national_id',
      'back',
      ocr('KASARIAN SEX URI NG DUGO BLOOD TYPE KALAGAYANG SIBIL MARITAL STATUS LUGAR NG KAPANGANAKAN PLACE OF BIRTH', 0.27),
    );

    expect(result.outcome).toBe('plausible');
    expect(result.detectedDocumentFamily).toBe('national_id');
  });

  it('accepts an official digital National ID front screenshot when its document evidence is sufficient', () => {
    const result = screenRegistrationDocumentSide(
      'national_id',
      'front',
      ocr('SCREENSHOT EPHIL ID PHILIPPINE IDENTIFICATION SYSTEM REPUBLICA NG PILIPINAS FULL NAME JUAN DELA CRUZ DATE OF BIRTH 01/02/1990 PCN 1234567890123456 DIGITAL ID NUMBER ABC123 SHOW BACK ID'),
      null,
      'digital_image',
    );

    expect(result.outcome).toBe('plausible');
    expect(result.detectedDocumentFamily).toBe('national_id');
  });

  it.each([
    [
      'English labels',
      'PHILIPPINE IDENTIFICATION SYSTEM REPUBLIC OF THE PHILIPPINES PHILIPPINE NATIONAL ID LAST NAME DELA CRUZ GIVEN NAMES JUANA DATE OF BIRTH 01/02/1990 PCN 1234-5678-9101-1213 ADDRESS MANILA',
    ],
    [
      'Filipino labels',
      'REPUBLIKA NG PILIPINAS PAMBANSANG PAGKAKAKILANLAN PHILIPPINE NATIONAL ID APELYIDO DELA CRUZ MGA PANGALAN JUANA PETSA NG KAPANGANAKAN 01/02/1990 PCN 1234-5678-9101-1213 TIRAHAN MANILA',
    ],
  ])('accepts a strongly anchored digital National ID screenshot when %s are readable without QR or digital markers', (_variant, text) => {
    const result = screenRegistrationDocumentSide(
      'national_id',
      'front',
      ocr(text, 0.48, false),
      null,
      'digital_image',
    );

    expect(result.outcome).toBe('plausible');
    expect(result.detectedDocumentFamily).toBe('national_id');
    expect(result.validationNotes).toEqual([]);
  });

  it('prefers strong National ID evidence over SSS microprint noise', () => {
    const result = screenRegistrationDocumentSide(
      'national_id',
      'front',
      ocr('REPUBLIKA NG PILIPINAS PHILIPPINE NATIONAL ID SSS LAST NAME DELA CRUZ GIVEN NAMES JUAN DATE OF BIRTH 01/02/1990 PCN 1234-5678-9101-1213 ADDRESS MANILA', 0.48),
      null,
      'digital_image',
    );

    expect(result.outcome).toBe('plausible');
    expect(result.detectedDocumentFamily).toBe('national_id');
  });

  it('prefers strong National ID evidence over a stray passport OCR word', () => {
    const result = screenRegistrationDocumentSide(
      'national_id',
      'front',
      ocr('PASSPORT REPUBLIKA NG PILIPINAS PHILIPPINE NATIONAL ID LAST NAME DELA CRUZ GIVEN NAMES JUAN DATE OF BIRTH 01/02/1990 ADDRESS MANILA', 0.35),
      null,
      'digital_image',
    );

    expect(result.outcome).toBe('plausible');
    expect(result.detectedDocumentFamily).toBe('national_id');
  });

  it('accepts a digital National ID back screen when OCR reads Filipino field labels', () => {
    const result = screenRegistrationDocumentSide(
      'national_id',
      'back',
      ocr('KASARIAN SEX FEMALE URI NG DUGO UNKNOWN KALAGAYANG SIBIL SINGLE LUGAR NG KAPANGANAKAN CITY OF MANILA', 0.22),
      null,
      'digital_image',
    );

    expect(result.outcome).toBe('plausible');
    expect(result.detectedDocumentFamily).toBe('national_id');
  });

  it('accepts field-backed National ID evidence when OCR misses the long title and QR detection', () => {
    const result = screenRegistrationDocumentSide(
      'national_id',
      'front',
      ocr('REPUBLIKA NG PILIPINAS APELYIDO PARAGAS MGA PANGALAN JOHN DANIEL PETSA NG KAPANGANAKAN JANUARY 01 2004 DIGITAL ID NUMBER QPB550 TIRAHAN BLK 10 LOT 29 CAVITE', 0.42, false),
      null,
      'digital_image',
    );

    expect(result.outcome).toBe('plausible');
    expect(result.detectedDocumentFamily).toBe('national_id');
    expect(result.detectedAnchorKeys).toContain('identity_fields');
  });

  it('queues Philippine issuer text and personal fields without National ID number evidence', () => {
    const result = screenRegistrationDocumentSide(
      'national_id',
      'front',
      ocr('REPUBLIKA NG PILIPINAS LAST NAME PARAGAS GIVEN NAMES JOHN DANIEL DATE OF BIRTH JANUARY 01 2004 ADDRESS CAVITE', 0.42, false),
      null,
      'digital_image',
    );

    expect(result.outcome).toBe('uncertain');
    expect(result.validationNotes).toContain('weak_philsys_anchors');
  });
  it('accepts a QR-backed National ID when OCR reads the Philippine issuer and identity fields but corrupts the long document title', () => {
    const result = screenRegistrationDocumentSide(
      'national_id',
      'front',
      ocr('REPUBLIKA NG PILIPINAS APELYIDO BATO MGA PANGALAN FRANSZINE MIGUEL PETSA NG KAPANGANAKAN DECEMBER 8 2003 TIRAHAN LAS PINAS', 0.42, true),
      null,
      'digital_image',
    );

    expect(result.outcome).toBe('plausible');
    expect(result.detectedDocumentFamily).toBe('national_id');
    expect(result.detectedAnchorKeys).toContain('qr_supplemental');
  });
  it('does not accept a digital marker or QR without National ID and Philippine issuer evidence', () => {
    const result = screenRegistrationDocumentSide(
      'national_id',
      'front',
      ocr('DIGITAL ID NUMBER ABC123 SHOW BACK ID QR', 0.48, true),
      null,
      'digital_image',
    );

    expect(result.outcome).toBe('uncertain');
    expect(result.validationNotes).toContain('weak_philsys_anchors');
  });

  it('queues digital National ID text without Philippine issuer evidence', () => {
    const result = screenRegistrationDocumentSide(
      'national_id',
      'front',
      ocr('PHILIPPINE IDENTIFICATION SYSTEM FULL NAME JUAN DELA CRUZ DATE OF BIRTH 01/02/1990 PCN 1234567890123456 ADDRESS MANILA', 0.48, false),
      null,
      'digital_image',
    );

    expect(result.outcome).toBe('uncertain');
    expect(result.validationNotes).toContain('weak_philsys_anchors');
  });

  it('accepts a complete digital National ID front when OCR wraps the digital label', () => {
    const result = screenRegistrationDocumentSide(
      'national_id',
      'front',
      ocr(`
        REPUBLIKA NG PILIPINAS
        PAMBANSANG PAGKAKAKILANLAN
        PHILIPPINE NATIONAL ID
        LAST NAME DELA CRUZ
        GIVEN NAMES JUAN
        DATE OF BIRTH 01/02/1990
        DIGITAL
        ID
        NUMBER ABC123
        ADDRESS MANILA
      `),
      null,
      'digital_image',
    );

    expect(result.outcome).toBe('plausible');
    expect(result.detectedAnchorKeys).toContain('digital_document');
  });

  it('accepts a digital National ID front when QR detection supplements missed screen text', () => {
    const result = screenRegistrationDocumentSide(
      'national_id',
      'front',
      ocr('REPUBLIKA NG PILIPINAS PAMBANSANG PAGKAKAKILANLAN PHILIPPINE NATIONAL ID LAST NAME DELA CRUZ GIVEN NAMES JUAN DATE OF BIRTH 01/02/1990 ADDRESS MANILA', 0.84, true),
      null,
      'digital_image',
    );

    expect(result.outcome).toBe('plausible');
    expect(result.detectedAnchorKeys).toContain('digital_document');
  });

  it('accepts physical National ID evidence in the unified National ID mode', () => {
    const result = screenRegistrationDocumentSide(
      'national_id',
      'front',
      ocr('PHILIPPINE IDENTIFICATION CARD PHILSYS REPUBLIC OF THE PHILIPPINES NAME JUAN DELA CRUZ DATE OF BIRTH 01/02/1990 PCN 1234567890123456 ADDRESS MANILA'),
      null,
      'digital_image',
    );

    expect(result.outcome).toBe('plausible');
    expect(result.detectedDocumentFamily).toBe('national_id');
  });

  it('rejects clear digital National ID markers when physical card mode is selected', () => {
    const front = screenRegistrationDocumentSide(
      'national_id',
      'front',
      ocr('PHILIPPINE IDENTIFICATION SYSTEM REPUBLIC OF THE PHILIPPINES FULL NAME JUAN DELA CRUZ DATE OF BIRTH 01/02/1990 PHILSYS CARD NUMBER 1234567890123456 DIGITAL ID NUMBER ABC123 SHOW BACK ID'),
      null,
      'physical_card',
    );
    const back = screenRegistrationDocumentSide(
      'national_id',
      'back',
      ocr('SEX FEMALE BLOOD TYPE O+ MARITAL STATUS SINGLE PLACE OF BIRTH TONDO MANILA SHOW FRONT ID ENLARGE QR', 0.22, true),
      null,
      'physical_card',
    );

    expect(front.outcome).toBe('reject_upload');
    expect(front.validationNotes).toContain('digital_national_id_in_physical_mode');
    expect(back.outcome).toBe('reject_upload');
    expect(back.validationNotes).toContain('digital_national_id_in_physical_mode');
  });

  it('accepts a National ID back when the QR is detectable and the PSA document marker is readable', () => {
    const result = screenRegistrationDocumentSide(
      'national_id',
      'back',
      ocr('PSA.GOV.PH REPUBLIC OF THE PHILIPPINES', 0.18, true),
    );

    expect(result.outcome).toBe('plausible');
    expect(result.detectedDocumentFamily).toBe('national_id');
  });

  it('accepts the physical National ID back when OCR reads PSA Office and card serial evidence', () => {
    const result = screenRegistrationDocumentSide(
      'national_id',
      'back',
      ocr('PSA OFFICE CARD SERIAL NUMBER', 0.22),
    );

    expect(result.outcome).toBe('plausible');
    expect(result.detectedDocumentFamily).toBe('national_id');
  });

  it('accepts the Philippine National ID specimen wording on both physical sides', () => {
    const front = screenRegistrationDocumentSide(
      'national_id',
      'front',
      ocr('REPUBLIKA NG PILIPINAS PAMBANSANG PAGKAKAKILANLAN PHILIPPINE IDENTIFICATION CARD PCN 1234-5678-9101-1213 APELYIDO LAST NAME DELA CRUZ MGA PANGALAN GIVEN NAMES JUANA PETSA NG KAPANGANAKAN DATE OF BIRTH JANUARY 01 1990 TIRAHAN ADDRESS MANILA'),
    );
    const back = screenRegistrationDocumentSide(
      'national_id',
      'back',
      ocr('ARAW NG KAPANGANAKAN DATE OF BIRTH 14 JUNE 2019 KASARIAN SEX FEMALE URI NG DUGO BLOOD TYPE O KALAGAYANG SIBIL MARITAL STATUS SINGLE LUGAR NG KAPANGANAKAN PLACE OF BIRTH QUEZON CITY PSA OFFICE WWW.PSA.GOV.PH CARD SERIAL NUMBER 0000010123456', 0.22),
    );
    const result = screenRegistrationSubmission('national_id', { front, back });

    expect(front.outcome).toBe('plausible');
    expect(back.outcome).toBe('plausible');
    expect(result.outcome).toBe('manual_review_required');
  });

  it('still requires a back for a physical National ID submission', () => {
    const front = screenRegistrationDocumentSide(
      'national_id',
      'front',
      ocr('PHILIPPINE IDENTIFICATION CARD PHILSYS REPUBLIC OF THE PHILIPPINES NAME JUAN DELA CRUZ DATE OF BIRTH 01/02/1990 PCN 1234567890123456'),
    );
    const result = screenRegistrationSubmission('national_id', { front });

    expect(front.outcome).toBe('plausible');
    expect(result.outcome).toBe('reject_upload');
    expect(result.failureSide).toBe('back');
  });

  it('accepts a UMID front-only submission and rejects an unexpected back', () => {
    const front = screenRegistrationDocumentSide(
      'umid',
      'front',
      ocr('UMID SSS NAME JUAN DELA CRUZ DATE OF BIRTH 01/02/1990'),
    );
    const back = screenRegistrationDocumentSide(
      'umid',
      'back',
      ocr('UMID', 0.12),
    );
    const frontOnly = screenRegistrationSubmission('umid', { front });
    const withBack = screenRegistrationSubmission('umid', { front, back });

    expect(front.outcome).toBe('plausible');
    expect(back.outcome).toBe('reject_upload');
    expect(frontOnly.outcome).toBe('screening_passed');
    expect(withBack.outcome).toBe('reject_upload');
    expect(withBack.message).toMatch(/required sides/i);
  });

  it('accepts a genuine UMID front when OCR reads CRN and government anchors instead of UMID', () => {
    const front = screenRegistrationDocumentSide(
      'umid',
      'front',
      ocr('REPUBLIC OF THE PHILIPPINES SSS GSIS PHILHEALTH PAG-IBIG COMMON REFERENCE NUMBER 1234-5678901-2 SURNAME DELA CRUZ GIVEN NAME JUAN DATE OF BIRTH 01/02/1990'),
    );

    expect(front.outcome).toBe('plausible');
    expect(front.detectedAnchorKeys).toContain('umid_document');
    expect(front.detectedAnchorKeys).toContain('philippine_issuer');
  });

  it.each([
    [
      'national_id',
      'front',
      'DIGITAL NATIONAL ID MAY BE ACCESSED VIA THE EGOVPH MOBILE APP PHILIPPINE IDENTIFICATION SYSTEM REPUBLIC OF THE PHILIPPINES NAME JUAN DELA CRUZ DATE OF BIRTH 01/02/1990 PCN 1234567890123456',
    ],
    [
      'drivers_license',
      'front',
      `${driverFrontText} THIS GUIDE SHOWS THE DOCUMENT FEATURES`,
    ],
    [
      'passport',
      'biodata',
      'FOR ILLUSTRATION PURPOSES REPUBLIC OF THE PHILIPPINES PASSPORT\nP<PHLDELA<ROSA<<JUAN<<<<<<<<<<<<<<<<<<<<\nP1234567<1PHL9001022M3001020<<<<<<<<<<<<<<08',
    ],
    [
      'umid',
      'front',
      'UMID SSS NAME JUAN DELA CRUZ DATE OF BIRTH 01/02/1990 THIS GUIDE SHOWS THE DOCUMENT FEATURES',
    ],
  ])('rejects educational or annotated %s material', (documentType, side, text) => {
    const result = screenRegistrationDocumentSide(
      documentType,
      side as RegistrationDocumentSide,
      ocr(text),
      null,
      documentType === 'national_id' ? 'digital_image' : 'physical_card',
    );

    expect(result.outcome).toBe('reject_upload');
    expect(result.validationNotes).toContain('obvious_non_document');
  });

  it('requires Philippine passport indicators and a plausible MRZ on biodata', () => {
    const result = screenRegistrationDocumentSide(
      'passport',
      'biodata',
      ocr('REPUBLIC OF THE PHILIPPINES PASSPORT JUAN DELA CRUZ\nP<PHLDELA<ROSA<<JUAN<<<<<<<<<<<<<<<<<<<<\nP1234567<1PHL9001022M3001020<<<<<<<<<<<<<<08'),
    );

    expect(result.outcome).toBe('plausible');
    expect(result.detectedAnchorKeys).toEqual(expect.arrayContaining(['passport_document', 'philippine_issuer', 'mrz_structure']));
  });

  it('queues a passport without credible MRZ/document evidence', () => {
    const result = screenRegistrationDocumentSide(
      'passport',
      'biodata',
      ocr('PASSPORT REPUBLIC OF THE PHILIPPINES JUAN DELA CRUZ 01/02/1990'),
    );

    expect(result.outcome).toBe('uncertain');
    expect(result.validationNotes).toContain('weak_mrz_structure');
  });

  it('rejects random, generic, novelty, and wrong-family images', () => {
    expect(screenRegistrationDocumentSide('national_id', 'front', ocr('JUAN DELA CRUZ 01/02/1990')).outcome).toBe('reject_upload');
    expect(screenRegistrationDocumentSide('national_id', 'front', ocr('BIKINI BOTTOM DRIVER LICENSE SPONGEBOB')).outcome).toBe('reject_upload');
    expect(screenRegistrationDocumentSide('national_id', 'front', ocr('PASSPORT REPUBLIC OF THE PHILIPPINES')).outcome).toBe('reject_upload');
    expect(screenRegistrationDocumentSide('national_id', 'front', ocr('WEBSITE SCREENSHOT ORDER NUMBER 123')).outcome).toBe('reject_upload');
  });

  it('returns a retry-safe screening error separately from rejection', () => {
    const result = createRegistrationScreeningError('drivers_license', 'front');

    expect(result.outcome).toBe('screening_error');
    expect(result.validationNotes[0]).toBe('screening_error:drivers_license');
  });

  it('rejects a duplicate pair before evaluating the document result', () => {
    const front = screenRegistrationDocumentSide('drivers_license', 'front', ocr(driverFrontText));
    const back = screenRegistrationDocumentSide('drivers_license', 'back', ocr('DL CODE A B RESTRICTION'));
    const result = screenRegistrationSubmission('drivers_license', { front, back }, 'near');

    expect(result.outcome).toBe('reject_upload');
    expect(result.duplicateKind).toBe('near');
    expect(result.message).toMatch(/same/i);
  });

  it('rejects an unexpected back side for a passport submission', () => {
    const biodata = screenRegistrationDocumentSide(
      'passport',
      'biodata',
      ocr('REPUBLIC OF THE PHILIPPINES PASSPORT\nP<PHLDELA<ROSA<<JUAN<<<<<<<<<<<<<<<<<<<<\nP1234567<1PHL9001022M3001020<<<<<<<<<<<<<<08'),
    );
    const result = screenRegistrationSubmission('passport', {
      biodata,
      back: biodata,
    });

    expect(result.outcome).toBe('reject_upload');
    expect(result.message).toMatch(/biodata page only/i);
  });

  it('rejects obvious cross-family front/back mismatches', () => {
    const front = screenRegistrationDocumentSide('national_id', 'front', ocr('PHILIPPINE IDENTIFICATION CARD PHILSYS NAME JUAN DATE OF BIRTH 01/02/1990'));
    const back = screenRegistrationDocumentSide('national_id', 'back', ocr('UMID SSS'));
    const result = screenRegistrationSubmission('national_id', { front, back });

    expect(front.outcome).toBe('plausible');
    expect(back.outcome).toBe('reject_upload');
    expect(result.outcome).toBe('reject_upload');
  });

  it('maps a successful card pair to screening_passed', () => {
    const front = screenRegistrationDocumentSide('drivers_license', 'front', ocr(driverFrontText));
    const back = screenRegistrationDocumentSide('drivers_license', 'back', ocr('DL CODE A B RESTRICTION CONDITIONS'));
    const result = screenRegistrationSubmission('drivers_license', { front, back });

    expect(result.outcome).toBe('screening_passed');
  });

  it('returns the safe compatibility rejection message for single-side callers', () => {
    const rejection = detectRegistrationDocumentRejection('national_id', 'JUAN DELA CRUZ 01/02/1990');

    expect(rejection?.reason).toBe('insufficient_document_evidence');
    expect(rejection?.message).toMatch(/selected ID type/i);
  });
});
