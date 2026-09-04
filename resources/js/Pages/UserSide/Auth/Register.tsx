import { useEffect, useRef, useState } from 'react';
import 'leaflet/dist/leaflet.css';
import { Head, router } from '@inertiajs/react';
import Swal from '@/Pages/UserSide/Shared/UserModal';
import Navigation from '../Shared/Navigation';
import Label from '../../../components/form/Label';
import Input from '../../../components/form/input/InputField';
import DropzoneComponent from '../../../components/form/form-elements/DropZone';
import { MailIcon, LockIcon, UserIcon } from '../../../icons';
import { parsePhilippineAddress, type RegistrationAddress } from './registrationAddress';
import { getPasswordRequirementState, isPasswordValid } from './passwordRequirements';
import { compareRegistrationImageFingerprints, type RegistrationDocumentSide, type RegistrationDuplicateKind } from './registrationOcr';
import {
  screenRegistrationDocumentSideFromFile,
  screenRegistrationSubmission,
  type RegistrationDocumentSideResult,
  type RegistrationNameMatchOutcome,
  type RegistrationNationalIdFormat,
  type RegistrationSubmissionResult,
} from './registrationDocumentScreening';
import { GPS_POSITION_OPTIONS, getCurrentPositionWithFallback } from '@/utils/geolocation';
import { getFreshCsrfToken } from '@/utils/csrf';
import { matchRegistrationName } from './registrationNameMatch';

type FormErrors = Record<string, string>;

const EMAIL_REGEX = /^\S+@\S+\.\S+$/;
const PHONE_REGEX = /^\d{11}$/;
const MAX_VALID_ID_SIZE_BYTES = 5 * 1024 * 1024;
const CUSTOMER_VALID_ID_ACCEPT = {
  'image/jpeg': ['.jpg', '.jpeg'],
  'image/png': ['.png'],
  'image/webp': ['.webp'],
};
const CUSTOMER_VALID_ID_ALLOWED_EXTENSIONS = new Set(['jpg', 'jpeg', 'png', 'webp']);
const CUSTOMER_VALID_ID_ALLOWED_MIME_TYPES = new Set(['image/jpeg', 'image/png', 'image/webp']);
const NATIONAL_ID_UPLOAD_GUIDANCE = 'Upload clear front and back images of your physical National ID or the official Digital National ID screens. Landscape orientation is required; rotate portrait screenshots before uploading.';
const PHILIPPINES_CENTER: [number, number] = [12.8797, 121.774];
const DOCUMENT_TYPE_OPTIONS = [
  {
    value: 'national_id',
    label: 'National ID',
    guidance: NATIONAL_ID_UPLOAD_GUIDANCE,
    slots: ['front', 'back'] as RegistrationDocumentSide[],
  },
  {
    value: 'drivers_license',
    label: "Driver's License",
    guidance: "Physical LTO driver's license: upload a clear front and back image. Replace screenshots or unclear images with a readable photo of the ID.",
    slots: ['front', 'back'] as RegistrationDocumentSide[],
  },
  {
    value: 'passport',
    label: 'Passport',
    guidance: 'Upload the passport biodata page only, including the complete machine-readable zone (MRZ).',
    slots: ['biodata'] as RegistrationDocumentSide[],
  },
  {
    value: 'umid',
    label: 'UMID',
    guidance: 'Upload one clear landscape image of the complete UMID front. The back is not required. Automated screening checks document plausibility; it does not verify chips, holograms, or other authenticity features.',
    slots: ['front'] as RegistrationDocumentSide[],
  },
] as const;

const ADDRESS_LOOKUP_TIMEOUT_MS = 15_000;

class AddressLookupError extends Error {
  constructor(
    message: string,
    public readonly status?: number,
    public readonly retryAfterSeconds?: number,
  ) {
    super(message);
    this.name = 'AddressLookupError';
  }
}

const fetchAddressJson = async (url: string): Promise<unknown> => {
  let attempt = 0;

  while (true) {
    const controller = new AbortController();
    const timeoutId = window.setTimeout(() => controller.abort(), ADDRESS_LOOKUP_TIMEOUT_MS);

    try {
      const response = await fetch(url, {
        headers: { Accept: 'application/json' },
        signal: controller.signal,
      });

      if (response.ok) return response.json();

      const payload = await response.json().catch(() => null) as { message?: unknown } | null;
      const retryAfterHeader = response.headers.get('Retry-After');
      const retryAfterSeconds = retryAfterHeader && /^\d+$/.test(retryAfterHeader)
        ? Number(retryAfterHeader)
        : undefined;
      const message = typeof payload?.message === 'string'
        ? payload.message
        : 'Address lookup failed.';
      const error = new AddressLookupError(message, response.status, retryAfterSeconds);

      if (response.status === 429 && attempt === 0 && retryAfterSeconds !== undefined && retryAfterSeconds <= 2) {
        attempt += 1;
        await new Promise(resolve => window.setTimeout(resolve, Math.max(1, retryAfterSeconds) * 1000));
        continue;
      }

      throw error;
    } catch (error) {
      if (error instanceof AddressLookupError) throw error;
      if (error instanceof DOMException && error.name === 'AbortError') {
        throw new AddressLookupError('Address lookup timed out.');
      }

      throw error;
    } finally {
      window.clearTimeout(timeoutId);
    }
  }
};

const addressLookupErrorMessage = (error: unknown, fallback: string): string => {
  if (error instanceof AddressLookupError && error.status === 429) {
    if (error.retryAfterSeconds && error.retryAfterSeconds > 0) {
      return `Address lookup is temporarily limited. Please wait ${error.retryAfterSeconds} seconds or use address search.`;
    }

    return 'Address lookup is temporarily busy. Please try again or use address search.';
  }

  if (error instanceof AddressLookupError && error.message === 'Address lookup timed out.') {
    return 'Address lookup took too long. Please try again or use address search.';
  }

  return fallback;
};

const geolocationErrorMessage = (error: unknown): string => {
  if (error && typeof error === 'object') {
    const code = (error as { code?: unknown }).code;

    if (code === 1) return 'Location access was denied. Allow location access in your browser settings, then try again.';
    if (code === 2) return 'Your browser could not determine your location. Turn on device location or use address search.';
    if (code === 3) return 'Location detection timed out. Please try again or use address search.';
  }

  if (error instanceof Error && /timed out/i.test(error.message)) {
    return 'Location detection timed out. Please try again or use address search.';
  }

  return 'Could not get your location. Please allow location access or use address search.';
};

type RegistrationOcrStatus = 'idle' | 'loading' | 'recognizing' | 'ready' | 'rejected' | 'error';
type RegistrationSideStatus = RegistrationOcrStatus;
type RegistrationSideMap<T> = Partial<Record<RegistrationDocumentSide, T>>;

const escapeHtml = (value: string) => (
  value
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;')
);

const showDocumentScreeningModal = (
  slot: RegistrationDocumentSide,
  outcome: RegistrationDocumentSideResult['outcome'],
  duplicate = false,
): void => {
  const text = duplicate
    ? 'The front and back images appear to be the same. Please upload the back side of your ID.'
    : outcome === 'screening_error'
      ? 'We couldn\'t check this image right now. Please try again or select another image.'
      : slot === 'back'
        ? 'The back image does not appear to match the selected ID. Please upload the back side of your valid ID.'
        : 'This image does not appear to match the selected ID type. Please upload a clear image of your valid Philippine ID.';

  void Swal.fire({
    icon: 'error',
    title: duplicate ? 'Duplicate ID images' : outcome === 'screening_error' ? 'Unable to check ID' : 'Invalid ID image',
    text,
    confirmButtonColor: '#000000',
  });
};

const DocumentScreeningOverlay = ({
  side,
  status,
}: {
  side?: RegistrationDocumentSide;
  status?: RegistrationOcrStatus;
}) => {
  if (!side || (status !== 'loading' && status !== 'recognizing')) return null;

  const sideLabel = side === 'biodata' ? 'passport biodata page' : side + ' image';
  const isRecognizing = status === 'recognizing';
  const title = isRecognizing ? 'Validating ID' : 'Preparing image';
  const description = isRecognizing
    ? 'Securely comparing the ' + sideLabel + ' with your selected ID type.'
    : 'Preparing the ' + sideLabel + ' for secure validation.';

  return (
    <div
      className="fixed inset-0 z-[120] flex min-h-dvh items-center justify-center overflow-y-auto bg-black/40 px-4 py-6 sm:px-6"
    >
      <div
        className="w-full max-w-[400px] rounded-2xl border border-gray-200 bg-white p-6 font-outfit sm:p-8"
        role="dialog"
        aria-modal="true"
        aria-labelledby="registration-screening-title"
        data-testid="registration-screening-overlay"
      >
        <p className="text-[11px] font-semibold uppercase tracking-[0.18em] text-gray-500">ID verification</p>
        <h2 id="registration-screening-title" className="mt-3 text-[26px] font-semibold leading-tight tracking-tight text-gray-900">{title}</h2>
        <p className="mt-2 text-sm leading-6 text-gray-600">{description}</p>

        <div className="relative mt-7">
          <span className="absolute left-3 top-6 h-8 w-px bg-gray-200" aria-hidden="true" />
          <ol aria-label="Validation progress" className="relative space-y-5">
            <li
              className="relative flex items-center gap-3"
              aria-current={isRecognizing ? undefined : 'step'}
            >
              <span className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-gray-900 text-[11px] text-white" aria-hidden="true">
                {isRecognizing ? <span aria-hidden="true">&#10003;</span> : <span className="h-3.5 w-3.5 animate-spin rounded-full border-2 border-gray-400 border-t-white motion-reduce:animate-none" />}
              </span>
              <span className="text-sm font-medium text-gray-900">Image uploaded</span>
              <span className="ml-auto text-[11px] font-semibold uppercase tracking-[0.12em] text-gray-500">{isRecognizing ? 'Complete' : 'In progress'}</span>
            </li>
            <li
              className="relative flex items-center gap-3"
              aria-current={isRecognizing ? 'step' : undefined}
            >
              <span className={`flex h-6 w-6 shrink-0 items-center justify-center rounded-full border text-[11px] font-semibold ${isRecognizing ? 'border-gray-900 bg-gray-900 text-white' : 'border-gray-200 bg-gray-50 text-gray-400'}`} aria-hidden="true">
                {isRecognizing ? <span className="h-3.5 w-3.5 animate-spin rounded-full border-2 border-gray-400 border-t-white motion-reduce:animate-none" /> : '02'}
              </span>
              <span className={`text-sm font-medium ${isRecognizing ? 'text-gray-900' : 'text-gray-500'}`}>Secure validation</span>
              {isRecognizing && <span className="ml-auto text-[11px] font-semibold uppercase tracking-[0.12em] text-gray-500">In progress</span>}
            </li>
          </ol>
        </div>

        <div className="mt-7 border-t border-gray-100 pt-4" role="status" aria-live="polite" aria-atomic="true">
          <p className="text-sm font-medium text-gray-900">{isRecognizing ? 'Checking your document' : 'Getting your image ready'}</p>
          <p className="mt-1 text-xs leading-5 text-gray-500">This usually takes a few seconds. Please keep this page open.</p>
          <p className="mt-3 text-xs leading-5 text-gray-500">Your image is processed securely and is only used to screen the selected document type.</p>
        </div>
      </div>
    </div>
  );
};

export default function Register() {
  const [currentStep, setCurrentStep] = useState(1);
  const [validIdPreview, setValidIdPreview] = useState('');
  const [validIdBackPreview, setValidIdBackPreview] = useState('');
  const [formData, setFormData] = useState({
    firstName: '',
    lastName: '',
    email: '',
    phone: '',
    age: '',
    address: '',
    password: '',
    confirmPassword: '',
    documentType: '',
    validId: null as File | null,
    validIdBack: null as File | null,
    termsAccepted: false,
  });

  const [errors, setErrors] = useState<Record<string, string | undefined>>({});
  const [isLoading, setIsLoading] = useState(false);
  const [addressLocation, setAddressLocation] = useState<RegistrationAddress | null>(null);
  const [addressSearch, setAddressSearch] = useState('');
  const [geoError, setGeoError] = useState('');
  const [isSearching, setIsSearching] = useState(false);
  const [gettingGPS, setGettingGPS] = useState(false);
  const [sideStatuses, setSideStatuses] = useState<RegistrationSideMap<RegistrationSideStatus>>({});
  const [sideResults, setSideResults] = useState<RegistrationSideMap<RegistrationDocumentSideResult>>({});
  const [duplicateKind, setDuplicateKind] = useState<RegistrationDuplicateKind>('none');
  const mapRef = useRef<HTMLDivElement>(null);
  const leafletMapRef = useRef<any>(null);
  const markerRef = useRef<any>(null);
  const locationRequestRef = useRef(0);
  const screeningRequestRef = useRef<RegistrationSideMap<number>>({});
  const screeningGenerationRef = useRef(0);
  const duplicateAlertRef = useRef<RegistrationDuplicateKind>('none');
  const authInputClasses = 'userside-auth-input h-12 rounded-xl !border-gray-200 !bg-[#f8fafc] !text-[13px] !text-gray-800 placeholder:!text-gray-400 shadow-none focus:!border-gray-300 focus:!ring-gray-200/70';
  const selectedDocumentOption = DOCUMENT_TYPE_OPTIONS.find(option => option.value === formData.documentType);
  const requiredSlots: RegistrationDocumentSide[] = selectedDocumentOption?.slots ?? [];
  const requiresBack = requiredSlots.includes('back');
  const isPassport = selectedDocumentOption?.value === 'passport';
  const nationalIdFormat: RegistrationNationalIdFormat = formData.documentType === 'national_id'
    ? 'digital_image'
    : 'physical_card';
  const nameEvidenceSlot: RegistrationDocumentSide = isPassport ? 'biodata' : 'front';
  const nameEvidenceSide = sideResults[nameEvidenceSlot];
  const nameMatchResult = nameEvidenceSide?.outcome === 'plausible'
    ? matchRegistrationName(formData.firstName, formData.lastName, nameEvidenceSide.ocrText)
    : null;

  const screeningDecision = (): RegistrationSubmissionResult => screenRegistrationSubmission(
    formData.documentType,
    sideResults,
    duplicateKind,
    nationalIdFormat,
    nameMatchResult as RegistrationNameMatchOutcome,
  );

  const documentScreeningMessage = (() => {
    const decision = screeningDecision();
    const checkingSlot = requiredSlots.find(slot => {
      const status = sideStatuses[slot];
      return status === 'loading' || status === 'recognizing';
    });

    if (checkingSlot) return '';

    if (duplicateKind !== 'none') return '';
    if (decision.outcome === 'screening_error') return '';

    const rejectedSlot = requiredSlots.find(slot => sideStatuses[slot] === 'rejected');
    if (rejectedSlot) return '';

    const readySlots = requiredSlots.filter(slot => sideStatuses[slot] === 'ready');
    if (readySlots.length === requiredSlots.length && requiredSlots.length > 0) {
      if (decision.outcome === 'manual_review_required') {
        return 'ID images received. We\'ll review your identity before transaction access is enabled.';
      }

      return isPassport
        ? 'Passport biodata page ready. We\'ll check it before submission.'
        : 'Front and back images ready. We\'ll check your ID before submission.';
    }

    if (readySlots.includes('front') && requiresBack && !readySlots.includes('back')) {
      return 'Front image ready. Upload the back image to continue.';
    }

    return 'We\'ll check your ID image before submission to make sure it matches the selected document type.';
  })();

  useEffect(() => {
    return () => {
      if (validIdPreview) {
        URL.revokeObjectURL(validIdPreview);
      }
    };
  }, [validIdPreview]);

  useEffect(() => {
    return () => {
      if (validIdBackPreview) {
        URL.revokeObjectURL(validIdBackPreview);
      }
    };
  }, [validIdBackPreview]);

  useEffect(() => {
    if (!requiresBack) {
      setDuplicateKind('none');
      duplicateAlertRef.current = 'none';
      return;
    }

    const frontFingerprint = sideResults.front?.imageFingerprint;
    const backFingerprint = sideResults.back?.imageFingerprint;
    const nextDuplicateKind = frontFingerprint && backFingerprint
      ? compareRegistrationImageFingerprints(frontFingerprint, backFingerprint)
      : 'none';

    if (nextDuplicateKind !== 'none' && duplicateAlertRef.current === 'none') {
      showDocumentScreeningModal('back', 'reject_upload', true);
    }
    duplicateAlertRef.current = nextDuplicateKind;
    setDuplicateKind(nextDuplicateKind);
    setErrors(previous => {
      const duplicateMessage = 'The front and back images appear to be the same. Please upload the back side of your ID.';
      if (nextDuplicateKind !== 'none' && previous.validIdBack !== duplicateMessage) {
        return { ...previous, validIdBack: duplicateMessage };
      }
      if (nextDuplicateKind === 'none' && previous.validIdBack === duplicateMessage) {
        return { ...previous, validIdBack: undefined };
      }
      return previous;
    });
  }, [requiresBack, sideResults]);

  const getStepValidationErrors = (step: number): FormErrors => {
    const newErrors: FormErrors = {};

    if (step === 1) {
      const firstName = formData.firstName.trim();
      const lastName = formData.lastName.trim();
      const email = formData.email.trim();
      const phone = formData.phone.trim();

      if (!firstName) {
        newErrors.firstName = 'Please enter your first name.';
      } else if (firstName.length < 2) {
        newErrors.firstName = 'First name must be at least 2 characters.';
      }

      if (!lastName) {
        newErrors.lastName = 'Please enter your last name.';
      } else if (lastName.length < 2) {
        newErrors.lastName = 'Last name must be at least 2 characters.';
      }

      if (!email) {
        newErrors.email = 'Please enter your email address.';
      } else if (!EMAIL_REGEX.test(email)) {
        newErrors.email = 'Please enter a valid email address (example: name@email.com).';
      }

      if (!phone) {
        newErrors.phone = 'Please enter your phone number.';
      } else if (!PHONE_REGEX.test(phone)) {
        newErrors.phone = 'Phone number must be exactly 11 digits (example: 09171234567).';
      }
    }

    if (step === 2) {
      const age = formData.age.trim();
      const ageNumber = Number(age);

      if (!age) {
        newErrors.age = 'Please enter your age.';
      } else if (!Number.isInteger(ageNumber)) {
        newErrors.age = 'Age must be a whole number.';
      } else if (ageNumber < 18) {
        newErrors.age = 'You must be at least 18 years old to register.';
      } else if (ageNumber > 120) {
        newErrors.age = 'Please enter a valid age (120 or below).';
      }

      if (!formData.address.trim()) {
        newErrors.address = 'Please enter your address.';
      }
      if (!addressLocation) {
        newErrors.addressLocation = 'Please select a complete Philippine address using search, GPS, or the map.';
      }

      if (!formData.password) {
        newErrors.password = 'Please enter a password.';
      } else if (formData.password.length < 8) {
        newErrors.password = 'Password must be at least 8 characters.';
      } else if (!isPasswordValid(formData.password)) {
        newErrors.password = 'Password must include uppercase, lowercase, and at least one number.';
      }

      if (!formData.confirmPassword) {
        newErrors.confirmPassword = 'Please confirm your password.';
      } else if (formData.password !== formData.confirmPassword) {
        newErrors.confirmPassword = 'Passwords do not match.';
      }
    }

    if (step === 3) {
      if (!formData.documentType) {
        newErrors.documentType = 'Please select the type of ID you are uploading.';
      }

      if (requiredSlots.includes('front') && !formData.validId) {
        newErrors.validId = errors.validId || 'Please upload a supported government-issued ID (JPG, JPEG, PNG, or WEBP up to 5MB).';
      }

      if (requiredSlots.includes('biodata') && !formData.validId) {
        newErrors.validId = errors.validId || 'Please upload the passport biodata page (JPG, JPEG, PNG, or WEBP up to 5MB).';
      }

      if (requiredSlots.includes('back') && !formData.validIdBack) {
        newErrors.validIdBack = errors.validIdBack || 'Please upload a clear back image of the selected ID.';
      }

      const decision = screeningDecision();
      if (decision.outcome === 'screening_error') {
        newErrors.ocr = decision.message || 'We couldn\'t check this image right now. Please try again or select another image.';
      } else if (decision.outcome === 'reject_upload' && requiredSlots.every(slot => sideResults[slot])) {
        const errorKey = decision.failureSide === 'back' ? 'validIdBack' : 'validId';
        newErrors[errorKey] = decision.message || 'This image does not appear to match the selected ID type. Please upload a clear image of your valid Philippine ID.';
      }

      if (!formData.termsAccepted) {
        newErrors.termsAccepted = 'Please accept the terms and conditions before creating your account.';
      }
    }

    return newErrors;
  };

  const getFirstInvalidStep = (validationErrors: FormErrors): number => {
    if (validationErrors.firstName || validationErrors.lastName || validationErrors.email || validationErrors.phone) {
      return 1;
    }

    if (validationErrors.age || validationErrors.address || validationErrors.addressLocation || validationErrors.password || validationErrors.confirmPassword) {
      return 2;
    }

    if (validationErrors.documentType || validationErrors.validId || validationErrors.validIdBack || validationErrors.ocr || validationErrors.termsAccepted) {
      return 3;
    }

    return 1;
  };

  const showValidationModal = (title: string, validationErrors: FormErrors) => {
    const uniqueMessages = Array.from(new Set(Object.values(validationErrors).filter(Boolean)));
    const listItems = uniqueMessages
      .map((message) => `<li style="margin-bottom:6px;">${escapeHtml(message)}</li>`)
      .join('');

    Swal.fire({
      icon: 'error',
      title,
      html: `<div style="text-align:left;"><p style="margin-bottom:8px;">Please check the following:</p><ul style="padding-left:18px; margin:0;">${listItems}</ul></div>`,
      confirmButtonColor: '#000000',
    });
  };

  const validateForm = (): { isValid: boolean; errors: FormErrors; firstInvalidStep: number } => {
    const newErrors: FormErrors = {
      ...getStepValidationErrors(1),
      ...getStepValidationErrors(2),
      ...getStepValidationErrors(3),
    };

    return {
      isValid: Object.keys(newErrors).length === 0,
      errors: newErrors,
      firstInvalidStep: getFirstInvalidStep(newErrors),
    };
  };

  const checkEmailAvailability = async (email: string): Promise<{ available: boolean; message?: string }> => {
    try {
      const response = await fetch(`/auth/check-email-availability?email=${encodeURIComponent(email)}`, {
        method: 'GET',
        headers: {
          Accept: 'application/json',
        },
      });

      const data = await response.json().catch(() => ({}));
      return {
        available: Boolean(data?.available),
        message: typeof data?.message === 'string' ? data.message : undefined,
      };
    } catch {
      return {
        available: false,
        message: 'Unable to verify email right now. Please try again.',
      };
    }
  };

  const checkPhoneAvailability = async (phone: string): Promise<{ available: boolean; message?: string }> => {
    try {
      const response = await fetch(`/auth/check-phone-availability?phone=${encodeURIComponent(phone)}`, {
        method: 'GET',
        headers: {
          Accept: 'application/json',
        },
      });

      const data = await response.json().catch(() => ({}));
      return {
        available: Boolean(data?.available),
        message: typeof data?.message === 'string' ? data.message : undefined,
      };
    } catch {
      return {
        available: false,
        message: 'Unable to verify this phone number right now. Please try again.',
      };
    }
  };

  const applyLocationResult = (result: any) => {
    const location = parsePhilippineAddress(result);
    if (!location) {
      setAddressLocation(null);
      setGeoError('Choose a complete address within the Philippines, including barangay and city.');
      return false;
    }

    setAddressLocation(location);
    setAddressSearch(location.displayName);
    setFormData(prev => ({ ...prev, address: location.displayName || prev.address }));
    setErrors(prev => ({ ...prev, address: undefined, addressLocation: undefined }));
    setGeoError('');
    leafletMapRef.current?.setView([location.latitude, location.longitude], 16);
    markerRef.current?.setLatLng([location.latitude, location.longitude]);
    return true;
  };

  const reverseGeocode = async (latitude: number, longitude: number, requestId: number) => {
    const result = await fetchAddressJson(
      `/api/address/geocode?latitude=${latitude}&longitude=${longitude}`,
    );
    return requestId === locationRequestRef.current && applyLocationResult(result);
  };

  useEffect(() => {
    if (currentStep !== 2 || !mapRef.current) return;

    let cancelled = false;
    import('leaflet').then((L) => {
      if (cancelled || !mapRef.current) return;

      delete (L.Icon.Default.prototype as any)._getIconUrl;
      L.Icon.Default.mergeOptions({
        iconRetinaUrl: 'https://unpkg.com/leaflet@1.9.4/dist/images/marker-icon-2x.png',
        iconUrl: 'https://unpkg.com/leaflet@1.9.4/dist/images/marker-icon.png',
        shadowUrl: 'https://unpkg.com/leaflet@1.9.4/dist/images/marker-shadow.png',
      });

      const initial: [number, number] = addressLocation
        ? [addressLocation.latitude, addressLocation.longitude]
        : PHILIPPINES_CENTER;
      const map = L.map(mapRef.current).setView(initial, addressLocation ? 16 : 5);
      L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '© OpenStreetMap contributors',
      }).addTo(map);
      const marker = L.marker(initial, { draggable: true }).addTo(map);

      const updateFromPin = async (latitude: number, longitude: number) => {
        const requestId = ++locationRequestRef.current;
        marker.setLatLng([latitude, longitude]);
        setAddressLocation(null);
        setGeoError('');
        try {
          await reverseGeocode(latitude, longitude, requestId);
        } catch {
          if (requestId === locationRequestRef.current) {
            setGeoError('Could not identify this location. Please try again.');
          }
        }
      };

      marker.on('dragend', () => {
        const position = marker.getLatLng();
        void updateFromPin(position.lat, position.lng);
      });
      map.on('click', (event: any) => void updateFromPin(event.latlng.lat, event.latlng.lng));

      leafletMapRef.current = map;
      markerRef.current = marker;
      window.setTimeout(() => map.invalidateSize(), 0);
    });

    return () => {
      cancelled = true;
      leafletMapRef.current?.remove();
      leafletMapRef.current = null;
      markerRef.current = null;
    };
  }, [currentStep]);

  const handleAddressSearch = async () => {
    const query = addressSearch.trim();
    if (!query) {
      setGeoError('Enter an address to search.');
      return;
    }

    setIsSearching(true);
    const requestId = ++locationRequestRef.current;
    setAddressLocation(null);
    setGeoError('');
    try {
      const response = await fetchAddressJson(
        `/api/address/geocode?q=${encodeURIComponent(query)}`,
      );
      const [result] = Array.isArray(response) ? response : [];
      if (requestId !== locationRequestRef.current) return;
      if (!result) setGeoError('No Philippine address found. Try a more specific search.');
      else applyLocationResult(result);
    } catch (error) {
      if (requestId === locationRequestRef.current) {
        setGeoError(addressLookupErrorMessage(error, 'Address search is unavailable. Please try again.'));
      }
    } finally {
      setIsSearching(false);
    }
  };

  const handleUseMyGPS = async () => {
    const localHosts = new Set(['localhost', '127.0.0.1', '::1']);
    if (typeof window !== 'undefined' && !window.isSecureContext && !localHosts.has(window.location.hostname)) {
      setGeoError('GPS requires a secure HTTPS connection. Please use address search instead.');
      return;
    }

    if (!navigator.geolocation) {
      setGeoError('Geolocation is not supported by your browser.');
      return;
    }

    setGettingGPS(true);
    const requestId = ++locationRequestRef.current;
    setAddressLocation(null);
    setGeoError('');
    try {
      const { coords } = await getCurrentPositionWithFallback(GPS_POSITION_OPTIONS);
      if (requestId !== locationRequestRef.current) return;

      try {
        await reverseGeocode(coords.latitude, coords.longitude, requestId);
      } catch (error) {
        if (requestId === locationRequestRef.current) {
          setGeoError(addressLookupErrorMessage(error, 'Could not identify your GPS address. Please try searching instead.'));
        }
      }
    } catch (error) {
      if (requestId === locationRequestRef.current) {
        setGeoError(geolocationErrorMessage(error));
      }
    } finally {
      if (requestId === locationRequestRef.current) {
        setGettingGPS(false);
      }
    }
  };

  const handleNext = async () => {
    const stepErrors = getStepValidationErrors(currentStep);
    if (Object.keys(stepErrors).length > 0) {
      setErrors(prev => ({ ...prev, ...stepErrors }));
      showValidationModal('Please fix the highlighted fields', stepErrors);
      return;
    }

    if (currentStep === 1) {
      const trimmedEmail = formData.email.trim();
      const normalizedPhone = formData.phone.trim();
      const [emailResult, phoneResult] = await Promise.all([
        checkEmailAvailability(trimmedEmail),
        checkPhoneAvailability(normalizedPhone),
      ]);
      const availabilityErrors: FormErrors = {};

      if (!emailResult.available) {
        availabilityErrors.email = emailResult.message || 'This email is already registered';
      }
      if (!phoneResult.available) {
        availabilityErrors.phone = phoneResult.message || 'This phone number is already registered. Try another number or sign in instead.';
      }

      if (Object.keys(availabilityErrors).length > 0) {
        setErrors(prev => ({ ...prev, ...availabilityErrors }));
        showValidationModal('Registration details not available', availabilityErrors);
        return;
      }
    }

    setCurrentStep(currentStep + 1);
  };

  const handlePrev = () => {
    setCurrentStep(currentStep - 1);
  };

  const handleInputChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const { name, value } = e.target;

    if (name === 'phone') {
      const digitsOnly = value.replace(/\D/g, '').slice(0, 11);
      setFormData(prev => ({
        ...prev,
        phone: digitsOnly
      }));

      if (errors.phone) {
        setErrors(prev => ({ ...prev, phone: undefined }));
      }
      return;
    }

    setFormData(prev => ({
      ...prev,
      [name]: value
    }));
    // Clear error when user starts typing
    if (errors[name]) {
      setErrors(prev => ({ ...prev, [name]: undefined }));
    }
  };

  const resetDocumentUploads = (updates: Partial<Pick<typeof formData, 'documentType'>> = {}) => {
    screeningGenerationRef.current += 1;
    screeningRequestRef.current = {};
    setFormData(prev => ({
      ...prev,
      ...updates,
      validId: null,
      validIdBack: null,
    }));
    setSideStatuses({});
    setSideResults({});
    setDuplicateKind('none');
    duplicateAlertRef.current = 'none';
    setErrors(prev => ({
      ...prev,
      documentType: undefined,
      validId: undefined,
      validIdBack: undefined,
      ocr: undefined,
    }));
    setValidIdPreview((prevPreview) => {
      if (prevPreview) URL.revokeObjectURL(prevPreview);
      return '';
    });
    setValidIdBackPreview((prevPreview) => {
      if (prevPreview) URL.revokeObjectURL(prevPreview);
      return '';
    });
  };

  const handleDocumentTypeChange = (e: React.ChangeEvent<HTMLSelectElement>) => {
    resetDocumentUploads({
      documentType: e.target.value,
    });
  };

  const fileValidationError = (file: File): string | null => {
    const extension = file.name.split('.').pop()?.toLowerCase() ?? '';
    const hasAllowedExtension = CUSTOMER_VALID_ID_ALLOWED_EXTENSIONS.has(extension);
    const mimeType = String(file.type || '').toLowerCase();
    const hasAllowedMimeType = mimeType === '' || CUSTOMER_VALID_ID_ALLOWED_MIME_TYPES.has(mimeType);

    if (!hasAllowedExtension || !hasAllowedMimeType) {
      return 'Valid ID must be JPG, JPEG, PNG, or WEBP only.';
    }

    if (file.size > MAX_VALID_ID_SIZE_BYTES) {
      return 'Valid ID must be 5MB or smaller (JPG, JPEG, PNG, or WEBP).';
    }

    return null;
  };

  const showInvalidFileModal = (file: File, message: string): void => {
    const isTooLarge = file.size > MAX_VALID_ID_SIZE_BYTES;

    void Swal.fire({
      icon: 'error',
      title: isTooLarge ? 'File too large' : 'Invalid file type',
      ...(isTooLarge
        ? { text: message }
        : { html: `<p><strong>${escapeHtml(file.name)}</strong> is not allowed.</p><p class="text-sm text-gray-600">Only JPG, JPEG, PNG, and WEBP image files are accepted for Valid ID.</p>` }),
      confirmButtonColor: '#000000',
    });
  };

  const handleDocumentSideDrop = (slot: RegistrationDocumentSide, acceptedFiles: File[]): void => {
    const file = acceptedFiles[0];
    if (!file) return;

    const validationError = fileValidationError(file);
    if (validationError) {
      setErrors(prev => ({
        ...prev,
        [slot === 'back' ? 'validIdBack' : 'validId']: validationError,
      }));
      showInvalidFileModal(file, validationError);
      return;
    }

    const requestId = (screeningRequestRef.current[slot] ?? 0) + 1;
    const generation = screeningGenerationRef.current;
    screeningRequestRef.current[slot] = requestId;
    const errorKey = slot === 'back' ? 'validIdBack' : 'validId';

    setFormData(prev => ({
      ...prev,
      ...(slot === 'back' ? { validIdBack: file } : { validId: file }),
    }));
    setSideStatuses(prev => ({ ...prev, [slot]: 'loading' }));
    setSideResults(prev => {
      const next = { ...prev };
      delete next[slot];
      return next;
    });
    setDuplicateKind('none');
    duplicateAlertRef.current = 'none';
    setErrors(prev => ({ ...prev, [errorKey]: undefined, ocr: undefined }));

    if (slot === 'back') {
      setValidIdBackPreview((prevPreview) => {
        if (prevPreview) URL.revokeObjectURL(prevPreview);
        return URL.createObjectURL(file);
      });
    } else {
      setValidIdPreview((prevPreview) => {
        if (prevPreview) URL.revokeObjectURL(prevPreview);
        return URL.createObjectURL(file);
      });
    }

    void screenRegistrationDocumentSideFromFile(
      formData.documentType,
      slot,
      file,
      stage => {
        if (generation === screeningGenerationRef.current && requestId === screeningRequestRef.current[slot]) {
          setSideStatuses(prev => ({ ...prev, [slot]: stage }));
        }
      },
      nationalIdFormat,
    ).then((result) => {
      if (generation !== screeningGenerationRef.current || requestId !== screeningRequestRef.current[slot]) return;

      setSideResults(prev => ({ ...prev, [slot]: result }));
      setSideStatuses(prev => ({
        ...prev,
        [slot]: result.outcome === 'plausible'
          ? 'ready'
          : result.outcome === 'screening_error' ? 'error' : 'rejected',
      }));
      setErrors(prev => ({
        ...prev,
        [errorKey]: result.outcome === 'plausible' ? undefined : result.outcome === 'screening_error'
          ? 'We couldn\'t check this image right now. Please try again or select another image.'
          : slot === 'back'
            ? 'The back image does not appear to match the selected ID. Please upload the back side of your valid ID.'
            : 'This image does not appear to match the selected ID type. Please upload a clear image of your valid Philippine ID.',
        ocr: result.outcome === 'screening_error'
          ? 'We couldn\'t check this image right now. Please try again or select another image.'
          : undefined,
      }));

      if (result.outcome !== 'plausible') {
        showDocumentScreeningModal(slot, result.outcome);
      }
    });
  };

  const handleFileDrop = (acceptedFiles: File[]): void => handleDocumentSideDrop(isPassport ? 'biodata' : 'front', acceptedFiles);

  const handleBackFileDrop = (acceptedFiles: File[]): void => handleDocumentSideDrop('back', acceptedFiles);

  const handleCheckboxChange = async (e: React.ChangeEvent<HTMLInputElement>) => {
    const { name, checked } = e.target;
    if (name === 'termsAccepted' && checked) {
      const result = await Swal.fire({
        title: 'TERMS AND CONDITIONS',
        html: `
          <div class="terms-modal">
            <div class="terms-modal__icon" aria-hidden="true">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                <rect x="8" y="3" width="8" height="4" rx="1"></rect>
                <path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"></path>
                <path d="M9 12h6"></path>
                <path d="M9 16h6"></path>
              </svg>
            </div>
            <p class="terms-modal__intro">
              Please read these terms before creating your account.
            </p>

            <div class="terms-modal__scroll">
              <h3>1. Acceptance of Terms</h3>
              <p>
                By continuing registration, you confirm that you have read, understood, and agreed to these Terms and Conditions and our account verification requirements.
              </p>

              <h3>2. Information We Request</h3>
              <p>
                We ask for your basic personal details and a supported government-issued ID for document screening, account security, and marketplace protection.
              </p>

              <h3>3. Security and Anti-Fraud Policy</h3>
              <p>
                Screening helps us detect and prevent scam accounts, impersonation, and unauthorized activity. Uploads that do not plausibly match the selected ID type must be replaced before registration.
              </p>

              <h3>4. Accuracy of Information</h3>
              <p>
                You agree to provide true, complete, and updated information. Submitting misleading details or invalid IDs is a violation of platform policy.
              </p>

              <h3>5. Data Protection</h3>
              <p>
                Your data is processed for security, compliance, and account verification purposes. We apply reasonable safeguards to protect submitted information.
              </p>

              <h3>6. User Responsibility</h3>
              <p>
                You are responsible for keeping your account credentials confidential and for all activities under your account.
              </p>

              <h3>7. Agreement</h3>
              <p>
                Choosing <strong>Accept</strong> means you agree to these terms and consent to document screening as part of account security.
              </p>
            </div>
            <p class="terms-modal__hint">Scroll to the bottom to enable the Accept button.</p>
          </div>
        `,
        showCancelButton: true,
        confirmButtonText: 'Accept',
        cancelButtonText: 'Decline',
        allowOutsideClick: false,
        allowEscapeKey: true,
        didOpen: () => {
          const confirmButton = Swal.getConfirmButton();
          const scrollBox = document.querySelector('.terms-modal__scroll') as HTMLElement | null;

          if (!confirmButton || !scrollBox) return;

          confirmButton.disabled = true;

          const unlockWhenAtBottom = () => {
            const threshold = 8;
            const reachedBottom = scrollBox.scrollTop + scrollBox.clientHeight >= scrollBox.scrollHeight - threshold;
            confirmButton.disabled = !reachedBottom;
          };

          scrollBox.addEventListener('scroll', unlockWhenAtBottom, { passive: true });
          unlockWhenAtBottom();
        },
        customClass: {
          popup: 'user-terms-modal-popup',
          title: 'user-terms-modal-title',
          htmlContainer: 'user-terms-modal-content',
          actions: 'user-terms-modal-actions',
          confirmButton: 'user-terms-modal-accept',
          cancelButton: 'user-terms-modal-decline',
        },
      });

      setFormData(prev => ({
        ...prev,
        [name]: result.isConfirmed,
      }));

      if (result.isConfirmed && errors[name]) {
        setErrors(prev => ({ ...prev, [name]: undefined }));
      }
      return;
    }

    setFormData(prev => ({
      ...prev,
      [name]: checked
    }));

    // Clear error when checkbox is checked
    if (checked && errors[name]) {
      setErrors(prev => ({ ...prev, [name]: undefined }));
    }
  };

  const mapBackendErrorsToFrontend = (backendErrors: Record<string, unknown>): FormErrors => {
    const keyMap: Record<string, string> = {
      first_name: 'firstName',
      last_name: 'lastName',
      password_confirmation: 'confirmPassword',
      valid_id: 'validId',
      valid_id_back: 'validIdBack',
      document_type: 'documentType',
      national_id_format: 'ocr',
      screening_metadata: 'ocr',
      ocr_text: 'ocr',
      ocr_confidence: 'ocr',
      address_region: 'addressLocation',
      address_province: 'addressLocation',
      address_city: 'addressLocation',
      address_barangay: 'addressLocation',
      address_latitude: 'addressLocation',
      address_longitude: 'addressLocation',
    };

    const mapped: FormErrors = {};
    Object.entries(backendErrors || {}).forEach(([key, value]) => {
      const targetKey = keyMap[key] || key;
      mapped[targetKey] = Array.isArray(value) ? String(value[0] ?? '') : String(value ?? '');
    });

    return mapped;
  };

  const handleRegisterClick = async () => {
    // Ensure we're on the last step
    if (currentStep !== 3) {
      return;
    }

    const validation = validateForm();
    if (!validation.isValid) {
      setErrors(validation.errors);
      setCurrentStep(validation.firstInvalidStep);
      showValidationModal('Please review your details', validation.errors);
      return;
    }

    setIsLoading(true);
    try {
      const payload = new FormData();
      payload.append('first_name', formData.firstName);
      payload.append('last_name', formData.lastName);
      payload.append('email', formData.email.trim());
      payload.append('phone', formData.phone);
      payload.append('age', formData.age);
      payload.append('address', formData.address);
      payload.append('address_region', addressLocation!.region);
      payload.append('address_province', addressLocation!.province);
      payload.append('address_city', addressLocation!.city);
      payload.append('address_barangay', addressLocation!.barangay);
      payload.append('address_postal_code', addressLocation!.postalCode);
      payload.append('address_latitude', String(addressLocation!.latitude));
      payload.append('address_longitude', String(addressLocation!.longitude));
      payload.append('password', formData.password);
      payload.append('password_confirmation', formData.confirmPassword);
      payload.append('document_type', formData.documentType);
      payload.append('national_id_format', nationalIdFormat);
      const screeningMetadata = {
        document_type: formData.documentType,
        national_id_format: nationalIdFormat,
        outcome: screeningDecision().outcome,
        duplicate_kind: duplicateKind,
        name_match: nameMatchResult === 'matched',
        sides: Object.fromEntries(
          requiredSlots
            .map(slot => [slot, sideResults[slot]])
            .filter((entry): entry is [string, RegistrationDocumentSideResult] => entry[1] !== undefined)
            .map(([slot, result]) => [slot, {
              side: result.side,
              outcome: result.outcome,
              detected_document_family: result.detectedDocumentFamily,
              detected_anchor_keys: result.detectedAnchorKeys,
              confidence_band: result.confidenceBand,
              qr_detected: result.qrDetected,
              fingerprint: result.fingerprint,
            }]),
        ),
      };
      payload.append('screening_metadata', JSON.stringify(screeningMetadata));
      if (formData.validId) payload.append('valid_id', formData.validId);
      if (formData.validIdBack) payload.append('valid_id_back', formData.validIdBack);

      let csrfToken = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content?.trim() ?? '';

      // The form can stay open while the session cookie changes or expires.
      // Refresh the token immediately before this state-changing request so
      // the token and the session cookie always come from the same session.
      try {
        csrfToken = await getFreshCsrfToken();
      } catch {
        // Keep the page token as a fallback. Laravel still validates it server-side.
      }

      if (csrfToken) payload.append('_token', csrfToken);

      await router.post('/user/register', payload, {
        forceFormData: true,
        preserveScroll: false,
        headers: csrfToken ? { 'X-CSRF-TOKEN': csrfToken } : {},
        onError: (backendErrors) => {
          const mapped = mapBackendErrorsToFrontend(backendErrors as Record<string, unknown>);
          setErrors(mapped);

          const firstInvalidStep = getFirstInvalidStep(mapped);
          setCurrentStep(firstInvalidStep);
          showValidationModal('Registration failed. Please review your details.', mapped);
        },
      });
    } catch (error) {
      console.error('Registration error:', error);
      Swal.fire({
        icon: 'error',
        title: 'Registration failed',
        text: 'Something went wrong. Please try again.',
      });
    } finally {
      setIsLoading(false);
    }
  };

  const passwordRequirementState = getPasswordRequirementState(formData.password);

  const handleSubmit = (e: React.FormEvent<HTMLFormElement>) => {
    e.preventDefault();
    // Prevent any accidental form submission via Enter key
    return false;
  };

  const screeningSlot = requiredSlots.find(slot => (
    sideStatuses[slot] === 'loading' || sideStatuses[slot] === 'recognizing'
  ));
  const screeningStatus = screeningSlot ? sideStatuses[screeningSlot] : undefined;
  const isCheckingDocument = screeningSlot !== undefined;
  const screeningOutcome = screeningDecision().outcome;
  const isDocumentScreeningBlocked = requiredSlots.length === 0
    || !['screening_passed', 'manual_review_required'].includes(screeningOutcome)
    || requiredSlots.some(slot => {
      const file = slot === 'back' ? formData.validIdBack : formData.validId;
      const status = sideStatuses[slot];

      return !file
        || !sideResults[slot]
        || status === 'loading'
        || status === 'recognizing'
        || status === 'error'
        || status === 'rejected';
    })
    || duplicateKind !== 'none';

  return (
    <>
      <Head title="Register" />
      <div className="userside-auth-page min-h-screen bg-[radial-gradient(circle_at_top,#eef2f7_0%,#f7f9fc_45%,#ffffff_100%)] md:bg-white font-outfit antialiased">
        <Navigation />
        <DocumentScreeningOverlay side={screeningSlot} status={screeningStatus} />

      <div className="max-w-480 mx-auto px-4 sm:px-6 lg:px-12 pt-16 sm:pt-20 lg:pt-28 pb-16 sm:pb-24">
        <div className="text-center mt-20 sm:mt-0 mb-7 sm:mb-10 lg:mb-12">
          <h1 className="userside-auth-title text-[34px] leading-[1.05] sm:text-4xl lg:text-6xl font-bold text-gray-900 mb-3 sm:mb-5 tracking-tight">
            CREATE ACCOUNT
          </h1>
          <p className="userside-auth-subtitle text-[15px] sm:text-lg lg:text-xl text-gray-600 max-w-sm sm:max-w-2xl mx-auto leading-relaxed font-light">
            Please fill in your details to create an account.
          </p>
        </div>

        <div className={`max-w-92.5 mx-auto ${currentStep === 3 ? 'sm:max-w-2xl' : 'sm:max-w-lg'}`}>
          <div className="userside-auth-card bg-white rounded-[20px] sm:rounded-2xl border border-gray-100 shadow-[0_14px_32px_-20px_rgba(15,23,42,0.35)] p-5 sm:p-8">
            <div className="mb-4 text-center">
              <p className="text-[11px] uppercase tracking-[0.18em] text-gray-500">Step {currentStep} of 3</p>
            </div>

            <form onSubmit={handleSubmit} className="space-y-5 sm:space-y-6">
              {currentStep === 1 && (
                <>
                  <div className="relative">
                    <Label htmlFor="firstName" className="text-[12px] font-medium text-gray-700 mb-1.5">First Name</Label>
                    <div className="relative">
                      <UserIcon className="absolute left-3.5 top-1/2 transform -translate-y-1/2 w-4 h-4 text-gray-400" />
                      <Input
                        type="text"
                        id="firstName"
                        name="firstName"
                        placeholder="Enter your first name"
                        value={formData.firstName}
                        onChange={handleInputChange}
                        className={`pl-10 ${authInputClasses} ${errors.firstName ? 'border-red-500' : ''}`}
                      />
                    </div>
                    {errors.firstName && <p className="mt-1 text-sm text-red-600">{errors.firstName}</p>}
                  </div>

                  <div className="relative">
                    <Label htmlFor="lastName" className="text-[12px] font-medium text-gray-700 mb-1.5">Last Name</Label>
                    <div className="relative">
                      <UserIcon className="absolute left-3.5 top-1/2 transform -translate-y-1/2 w-4 h-4 text-gray-400" />
                      <Input
                        type="text"
                        id="lastName"
                        name="lastName"
                        placeholder="Enter your last name"
                        value={formData.lastName}
                        onChange={handleInputChange}
                        className={`pl-10 ${authInputClasses} ${errors.lastName ? 'border-red-500' : ''}`}
                      />
                    </div>
                    {errors.lastName && <p className="mt-1 text-sm text-red-600">{errors.lastName}</p>}
                  </div>

                  <div className="relative">
                    <Label htmlFor="email" className="text-[12px] font-medium text-gray-700 mb-1.5">Email</Label>
                    <div className="relative">
                      <MailIcon className="absolute left-3.5 top-1/2 transform -translate-y-1/2 w-4 h-4 text-gray-400" />
                      <Input
                        type="email"
                        id="email"
                        name="email"
                        placeholder="Enter your email address"
                        value={formData.email}
                        onChange={handleInputChange}
                        className={`pl-10 ${authInputClasses} ${errors.email ? 'border-red-500' : ''}`}
                      />
                    </div>
                    {errors.email && <p className="mt-1 text-sm text-red-600">{errors.email}</p>}
                  </div>

                  <div className="relative">
                    <Label htmlFor="phone" className="text-[12px] font-medium text-gray-700 mb-1.5">Phone Number</Label>
                    <div className="relative">
                      <UserIcon className="absolute left-3.5 top-1/2 transform -translate-y-1/2 w-4 h-4 text-gray-400" />
                      <Input
                        type="tel"
                        id="phone"
                        name="phone"
                        placeholder="Enter your phone number"
                        value={formData.phone}
                        onChange={handleInputChange}
                        inputMode="numeric"
                        pattern="[0-9]*"
                        maxLength={11}
                        className={`pl-10 ${authInputClasses} ${errors.phone ? 'border-red-500' : ''}`}
                      />
                    </div>
                    {errors.phone && <p className="mt-1 text-sm text-red-600">{errors.phone}</p>}
                  </div>
                </>
              )}

              {currentStep === 2 && (
                <>
                  <div className="relative">
                    <Label htmlFor="age" className="text-[12px] font-medium text-gray-700 mb-1.5">Age</Label>
                    <div className="relative">
                      <UserIcon className="absolute left-3.5 top-1/2 transform -translate-y-1/2 w-4 h-4 text-gray-400" />
                      <Input
                        type="number"
                        id="age"
                        name="age"
                        placeholder="Enter your age"
                        value={formData.age}
                        onChange={handleInputChange}
                        className={`pl-10 ${authInputClasses} ${errors.age ? 'border-red-500' : ''}`}
                      />
                    </div>
                    {errors.age && <p className="mt-1 text-sm text-red-600">{errors.age}</p>}
                  </div>

                  <div className="relative">
                    <Label htmlFor="address" className="text-[12px] font-medium text-gray-700 mb-1.5">Address</Label>
                    <div className="relative">
                      <UserIcon className="absolute left-3.5 top-1/2 transform -translate-y-1/2 w-4 h-4 text-gray-400" />
                      <Input
                        type="text"
                        id="address"
                        name="address"
                        placeholder="Enter your address"
                        value={formData.address}
                        onChange={handleInputChange}
                        className={`pl-10 ${authInputClasses} ${errors.address ? 'border-red-500' : ''}`}
                      />
                    </div>
                    {errors.address && <p className="mt-1 text-sm text-red-600">{errors.address}</p>}
                    <div className="mt-3 space-y-2">
                      <Label htmlFor="addressSearch" className="text-[12px] font-medium text-gray-700">Search Address</Label>
                      <div className="flex flex-col gap-2 sm:flex-row">
                        <input
                          id="addressSearch"
                          value={addressSearch}
                          onChange={(event) => setAddressSearch(event.target.value)}
                          onKeyDown={(event) => {
                            if (event.key === 'Enter') {
                              event.preventDefault();
                              void handleAddressSearch();
                            }
                          }}
                          placeholder="e.g. 123 Rizal St, Makati"
                          className="userside-auth-input h-10 min-w-0 flex-1 rounded-xl border border-gray-200 bg-[#f8fafc] px-3 text-[12px] text-gray-800 outline-none focus:border-gray-300 focus:ring-2 focus:ring-gray-200/70"
                        />
                        <button
                          type="button"
                          onClick={() => void handleAddressSearch()}
                          disabled={isSearching}
                          className="userside-auth-primary h-10 rounded-xl bg-black px-4 text-[12px] font-semibold text-white transition hover:bg-black/85 disabled:opacity-60"
                        >
                          {isSearching ? 'Searching...' : 'Search'}
                        </button>
                        <button
                          type="button"
                          onClick={handleUseMyGPS}
                          disabled={gettingGPS}
                          className="userside-auth-primary h-10 rounded-xl bg-black px-3 text-[12px] font-semibold text-white transition hover:bg-black/85 disabled:opacity-60"
                        >
                          {gettingGPS ? 'Locating...' : 'Use My GPS'}
                        </button>
                      </div>
                      <p className="text-[11px] text-gray-500">Drag the pin or click the map to adjust.</p>
                      <div ref={mapRef} className="h-44 w-full overflow-hidden rounded-xl border border-gray-200" />
                      {(geoError || errors.addressLocation) && (
                        <p className="text-xs text-red-600">{geoError || errors.addressLocation}</p>
                      )}
                    </div>
                  </div>

                  <div className="group relative z-20">
                    <Label htmlFor="password" className="text-[12px] font-medium text-gray-700 mb-1.5">Password</Label>
                    <div className="relative">
                      <LockIcon className="absolute left-3.5 top-1/2 transform -translate-y-1/2 w-4 h-4 text-gray-400" />
                      <Input
                        type="password"
                        id="password"
                        name="password"
                        placeholder="Enter your password"
                        value={formData.password}
                        onChange={handleInputChange}
                        aria-describedby="password-requirements"
                        className={`pl-10 ${authInputClasses} ${errors.password ? 'border-red-500' : ''}`}
                      />
                    </div>
                    <div
                      id="password-requirements"
                      role="group"
                      aria-label="Password requirements"
                      className="pointer-events-none absolute left-0 right-0 top-full z-30 mt-2 translate-y-1 rounded-2xl border border-gray-200 bg-white p-4 opacity-0 shadow-xl transition duration-200 ease-out group-hover:pointer-events-auto group-hover:translate-y-0 group-hover:opacity-100 dark:border-gray-700 dark:bg-gray-900"
                    >
                      <p className="text-xs font-semibold uppercase tracking-[0.14em] text-gray-500 dark:text-gray-400">Password requirements</p>
                      <ul className="mt-3 space-y-2">
                        {passwordRequirementState.map(({ key, label, met }) => (
                          <li key={key} className={`flex items-center gap-2 text-sm ${met ? 'text-emerald-700 dark:text-emerald-300' : 'text-gray-500 dark:text-gray-400'}`}>
                            <span aria-hidden="true" className={`flex h-5 w-5 shrink-0 items-center justify-center rounded-full text-xs font-bold ${met ? 'bg-emerald-100 dark:bg-emerald-900/40' : 'bg-gray-100 dark:bg-gray-800'}`}>
                              {met ? '✓' : '—'}
                            </span>
                            <span>{label}</span>
                            <span className="sr-only">{met ? 'met' : 'not met'}</span>
                          </li>
                        ))}
                      </ul>
                    </div>
                    {errors.password && <p className="mt-1 text-sm text-red-600">{errors.password}</p>}
                  </div>

                  <div className="relative">
                    <Label htmlFor="confirmPassword" className="text-[12px] font-medium text-gray-700 mb-1.5">Confirm Password</Label>
                    <div className="relative">
                      <LockIcon className="absolute left-3.5 top-1/2 transform -translate-y-1/2 w-4 h-4 text-gray-400" />
                      <Input
                        type="password"
                        id="confirmPassword"
                        name="confirmPassword"
                        placeholder="Confirm your password"
                        value={formData.confirmPassword}
                        onChange={handleInputChange}
                        className={`pl-10 ${authInputClasses} ${errors.confirmPassword ? 'border-red-500' : ''}`}
                      />
                    </div>
                    {errors.confirmPassword && <p className="mt-1 text-sm text-red-600">{errors.confirmPassword}</p>}
                  </div>
                </>
              )}

              {currentStep === 3 && (
                <>
                  <div>
                    <Label htmlFor="documentType" className="text-[12px] font-medium text-gray-700 mb-1.5">ID Type</Label>
                    <select
                      id="documentType"
                      name="documentType"
                      value={formData.documentType}
                      onChange={handleDocumentTypeChange}
                      aria-invalid={!!errors.documentType}
                      className={`w-full ${authInputClasses} ${errors.documentType ? 'border-red-500' : ''}`}
                    >
                      <option value="">Select the type of ID</option>
                      {DOCUMENT_TYPE_OPTIONS.map((option) => (
                        <option key={option.value} value={option.value}>{option.label}</option>
                      ))}
                    </select>
                    {errors.documentType && <p className="mt-1 text-sm text-red-600">{errors.documentType}</p>}
                    {selectedDocumentOption && (
                      <p className="mt-2 text-[12px] leading-5 text-gray-600">
                        {selectedDocumentOption.guidance}
                      </p>
                    )}
                  </div>

                  <div className="rounded-2xl border border-gray-200 bg-white p-4 sm:p-5" data-testid="registration-id-upload-section">
                    <div className="flex flex-col gap-1">
                      <p className="text-[12px] font-semibold uppercase tracking-[0.08em] text-gray-800">Upload valid ID</p>
                      <p className="text-[12px] leading-5 text-gray-600">
                        {requiresBack
                          ? formData.documentType === 'national_id'
                            ? NATIONAL_ID_UPLOAD_GUIDANCE
                            : 'Upload a clear front and back image of the same ID.'
                          : isPassport
                            ? 'Upload the passport biodata page, including the complete machine-readable zone.'
                            : 'Select an ID type, then upload the required image.'}
                      </p>
                    </div>

                    <p className="mt-4 text-[11px] font-semibold uppercase tracking-[0.12em] text-gray-500">Valid ID photos</p>
                    <div
                      className={requiresBack ? 'mt-2 grid gap-3 md:grid-cols-2' : 'mt-2 grid gap-3 md:grid-cols-1'}
                      data-testid="registration-id-photo-grid"
                    >
                      <div className="min-w-0 [&>label]:sr-only">
                        <div className="mb-2 flex min-h-6 items-center justify-between gap-2">
                          <p className="text-[12px] font-semibold uppercase tracking-[0.06em] text-gray-700">
                            {isPassport ? 'Passport biodata page' : 'Front of ID'}
                          </p>
                          {sideStatuses[isPassport ? 'biodata' : 'front'] === 'ready' && (
                            <span className="shrink-0 text-[11px] font-semibold text-green-700" aria-label={`${isPassport ? 'Passport biodata page' : 'Front image'} ready`}>&#10003; Ready</span>
                          )}
                        </div>
                        <Label htmlFor="validId">
                          {isPassport ? 'Passport Biodata Page' : 'Front of Valid ID'}
                          {formData.validId && <span className="text-green-600 font-bold ml-2">&#10003; Uploaded</span>}
                        </Label>
                        <DropzoneComponent
                          key={formData.validId ? 'uploaded' : 'empty'}
                          inputId="validId"
                          inputAriaLabel="ID file"
                          onDrop={handleFileDrop}
                          accept={CUSTOMER_VALID_ID_ACCEPT}
                          compact
                          uploadLabel={isPassport ? 'Upload biodata page' : 'Upload front'}
                          onInvalidFiles={(invalidFiles) => {
                            if (invalidFiles.length > 0) {
                              const typeError = 'Valid ID must be JPG, JPEG, PNG, or WEBP only.';
                              setErrors(prev => ({ ...prev, validId: typeError }));
                              showInvalidFileModal(invalidFiles[0], typeError);
                            }
                          }}
                          isUploaded={!!formData.validId}
                          fileName={formData.validId?.name}
                          previewUrl={validIdPreview || undefined}
                          previewAlt="Valid ID preview"
                        />
                      </div>

                      {requiresBack && (
                        <div className="min-w-0 [&>label]:sr-only">
                          <div className="mb-2 flex min-h-6 items-center justify-between gap-2">
                            <p className="text-[12px] font-semibold uppercase tracking-[0.06em] text-gray-700">Back of ID</p>
                            {sideStatuses.back === 'ready' && (
                              <span className="shrink-0 text-[11px] font-semibold text-green-700" aria-label="Back image ready">&#10003; Ready</span>
                            )}
                          </div>
                          <Label htmlFor="validIdBack">
                            Back of Valid ID
                            {formData.validIdBack && <span className="text-green-600 font-bold ml-2">&#10003; Uploaded</span>}
                          </Label>
                          <DropzoneComponent
                            key={formData.validIdBack ? 'uploaded' : 'empty'}
                            inputId="validIdBack"
                            inputAriaLabel="ID back file"
                            onDrop={handleBackFileDrop}
                            accept={CUSTOMER_VALID_ID_ACCEPT}
                            compact
                            uploadLabel="Upload back"
                            onInvalidFiles={(invalidFiles) => {
                              if (invalidFiles.length > 0) {
                                const typeError = 'The ID back image must be JPG, JPEG, PNG, or WEBP only.';
                                setErrors(prev => ({ ...prev, validIdBack: typeError }));
                                showInvalidFileModal(invalidFiles[0], typeError);
                              }
                            }}
                            isUploaded={!!formData.validIdBack}
                            fileName={formData.validIdBack?.name}
                            previewUrl={validIdBackPreview || undefined}
                            previewAlt="Back of valid ID preview"
                          />
                        </div>
                      )}
                    </div>
                    <div className="mt-3 rounded-xl border border-gray-200 bg-gray-50 px-3 py-2.5">
                      <p className="text-[12px] font-semibold uppercase tracking-[0.08em] text-gray-700">Why we ask for a valid ID</p>
                      <p className="mt-1 text-[12px] leading-5 text-gray-600">
                        We require a government-issued ID to help prevent fake accounts, fraud, and abuse. Your ID is stored privately, and access by authorized personnel is audited.
                      </p>
                      <p className="mt-1 text-[12px] leading-5 text-gray-600">
                        Automated screening checks whether the document matches the selected ID type but does not prove authenticity.
                      </p>
                      <p className="mt-1 text-[12px] leading-5 text-gray-600">
                        Supported formats: JPG, JPEG, PNG, and WEBP. Maximum size: 5MB.
                      </p>
                      {documentScreeningMessage && (
                        <p
                          className="mt-3 border-l-2 border-gray-300 pl-3 text-[12px] leading-5 text-gray-700"
                          role="status"
                          aria-live="polite"
                          data-testid="registration-id-note"
                        >
                          <span className="font-semibold uppercase tracking-[0.08em]">Note: </span>
                          {documentScreeningMessage}
                        </p>
                      )}
                    </div>
                  </div>

                  <div className="flex items-center">
                    <input
                      type="checkbox"
                      id="termsAccepted"
                      name="termsAccepted"
                      checked={formData.termsAccepted}
                      onChange={handleCheckboxChange}
                      className="h-4 w-4 text-black focus:ring-black/30 border-gray-300 rounded"
                    />
                    <label htmlFor="termsAccepted" className="ml-2 block text-[12px] text-gray-700">
                      Accept to the terms and conditions
                    </label>
                  </div>
                  {errors.termsAccepted && <p className="mt-1 text-sm text-red-600">{errors.termsAccepted}</p>}
                </>
              )}

              <div className="flex flex-col sm:flex-row justify-between gap-3 pt-2 sm:pt-4">
                {currentStep > 1 && (
                  <button
                    type="button"
                    onClick={handlePrev}
                    className="userside-auth-secondary w-full sm:w-auto rounded-xl px-6 py-3 bg-gray-100 text-gray-700 font-semibold uppercase tracking-[0.16em] text-xs sm:text-sm hover:bg-gray-200 transition-colors"
                  >
                    Previous
                  </button>
                )}
                {currentStep < 3 ? (
                  <button
                    type="button"
                    onClick={handleNext}
                    className="userside-auth-primary w-full sm:w-auto rounded-xl px-6 py-3 bg-black text-white font-semibold uppercase tracking-[0.16em] text-xs sm:text-sm hover:bg-black/85 transition-colors sm:ml-auto"
                  >
                    Next
                  </button>
                ) : (
                  <button
                    type="button"
                    onClick={handleRegisterClick}
                    disabled={isLoading || isDocumentScreeningBlocked}
                    className="userside-auth-primary w-full sm:w-auto rounded-xl px-6 py-3 bg-black text-white font-semibold uppercase tracking-[0.16em] text-xs sm:text-sm hover:bg-black/85 transition-colors disabled:opacity-50 disabled:cursor-not-allowed sm:ml-auto"
                  >
                    {isLoading ? 'Creating account...' : isCheckingDocument ? 'Validating ID...' : 'Create account'}
                  </button>
                )}
              </div>
            </form>

            <div className="mt-6 text-center">
              <p className="text-[13px] text-gray-600">
                Already have an account?{' '}
                <a
                  href={route("login")}
                  className="userside-auth-link text-black hover:text-black/80 font-semibold uppercase tracking-[0.15em] text-[12px] transition-colors"
                >
                  Sign in
                </a>
              </p>
            </div>
          </div>
        </div>
      </div>
      </div>
    </>
  );
}
