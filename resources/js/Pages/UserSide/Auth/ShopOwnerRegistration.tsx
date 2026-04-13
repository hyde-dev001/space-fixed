import { useEffect, useRef, useState } from "react";
import { Head, router } from "@inertiajs/react";
import { route } from 'ziggy-js';
import Swal from '@/Pages/UserSide/Shared/UserModal';
import 'leaflet/dist/leaflet.css';
import Navigation from "../Shared/Navigation";
import ComponentCard from "../../../components/common/ComponentCard";
import Label from "../../../components/form/Label";
import Input from "../../../components/form/input/InputField";
import Select from "../../../components/form/Select";
import Radio from "../../../components/form/input/Radio";
import DropzoneComponent from "../../../components/form/form-elements/DropZone";

const CAVITE_CENTER = {
  lat: '14.28140000',
  lng: '120.86850000',
};

const CAVITE_BOUNDS = {
  minLat: 14.05,
  maxLat: 14.52,
  minLng: 120.55,
  maxLng: 121.05,
};

const CAVITE_CITIES = [
  'Alfonso',
  'Amadeo',
  'Bacoor',
  'Carmona',
  'Cavite City',
  'Dasmariñas',
  'General Emilio Aguinaldo',
  'General Mariano Alvarez',
  'General Trias',
  'Imus',
  'Indang',
  'Kawit',
  'Magallanes',
  'Maragondon',
  'Mendez',
  'Naic',
  'Noveleta',
  'Rosario',
  'Silang',
  'Tagaytay',
  'Tanza',
  'Ternate',
  'Trece Martires',
];

const CAVITE_ADDRESS_KEYWORDS = ['cavite', ...CAVITE_CITIES].map((entry) => entry.toLowerCase());
const MAX_ADDITIONAL_DOCUMENTS = 8;
const EMAIL_REGEX = /^\S+@\S+\.\S+$/;
const PHONE_REGEX = /^\d{11}$/;

const escapeHtml = (value: string) => (
  value
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;')
);

const inferCaviteCity = (text: string) => {
  const normalized = text.toLowerCase();
  return CAVITE_CITIES.find((city) => normalized.includes(city.toLowerCase())) ?? '';
};

const isWithinCaviteBounds = (lat: number, lng: number) => (
  lat >= CAVITE_BOUNDS.minLat
  && lat <= CAVITE_BOUNDS.maxLat
  && lng >= CAVITE_BOUNDS.minLng
  && lng <= CAVITE_BOUNDS.maxLng
);

export default function ShopOwnerRegistration() {
  type AdditionalDocument = { id: number; file: File | null; fileName: string };

  const [currentStep, setCurrentStep] = useState(1);
  const [formData, setFormData] = useState({
    firstName: "",
    lastName: "",
    email: "",
    phone: "",
    businessName: "",
    businessAddress: "",
    postalCode: "",
    businessType: "",
    registrationType: "individual",
  });
  const [selectedCity, setSelectedCity] = useState("");


  const [uploadedDocuments, setUploadedDocuments] = useState({
    dti: { file: null as File | null, fileName: '' },
    mayors_permit: { file: null as File | null, fileName: '' },
    bir: { file: null as File | null, fileName: '' },
    valid_id: { file: null as File | null, fileName: '' },
  });
  const [additionalDocuments, setAdditionalDocuments] = useState<AdditionalDocument[]>([]);
  const nextAdditionalDocId = useRef(1);

  const [isSubmitting, setIsSubmitting] = useState(false);
  const [showSuccessModal, setShowSuccessModal] = useState(false);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [emailVerificationCode, setEmailVerificationCode] = useState('');
  const [emailVerificationSent, setEmailVerificationSent] = useState(false);
  const [emailVerified, setEmailVerified] = useState(false);
  const [emailVerificationMessage, setEmailVerificationMessage] = useState('');
  const [isSendingEmailCode, setIsSendingEmailCode] = useState(false);
  const [isVerifyingEmailCode, setIsVerifyingEmailCode] = useState(false);

  // Geofence state
  const [geoLat, setGeoLat] = useState(CAVITE_CENTER.lat);
  const [geoLng, setGeoLng] = useState(CAVITE_CENTER.lng);
  const [geoAddress, setGeoAddress] = useState('');
  const [geoRadius, setGeoRadius] = useState<number>(90);
  const [gettingGPS, setGettingGPS] = useState(false);
  const [savingAddress, setSavingAddress] = useState(false);
  const [geoError, setGeoError] = useState('');
  const mapRef = useRef<HTMLDivElement>(null);
  const leafletMapRef = useRef<any>(null);
  const markerRef = useRef<any>(null);
  const circleRef = useRef<any>(null);

  const businessTypeOptions = [
    { value: "retail", label: "Retail" },
    { value: "repair", label: "Repair" },
    { value: "both (retail & repair)", label: "both (retail & repair)" },
  ];

  const handleInputChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const { name, value } = e.target;

    if (name === 'email') {
      const normalizedEmail = value.trim().toLowerCase();
      setFormData(prev => ({ ...prev, email: normalizedEmail }));

      setEmailVerificationCode('');
      setEmailVerificationSent(false);
      setEmailVerified(false);
      setEmailVerificationMessage('');

      setErrors(prev => ({
        ...prev,
        email: '',
        email_verification: '',
      }));
      return;
    }

    if (name === 'phone') {
      const digitsOnly = value.replace(/\D/g, '').slice(0, 11);
      setFormData(prev => ({ ...prev, phone: digitsOnly }));

      if (errors.phone) {
        setErrors(prev => ({ ...prev, phone: '' }));
      }
      return;
    }

    if (name === 'postalCode') {
      const numericValue = value.replace(/\D/g, '');
      setFormData(prev => ({ ...prev, postalCode: numericValue }));

      if (errors.postal_code) {
        setErrors(prev => ({ ...prev, postal_code: '' }));
      }
      return;
    }

    const errorKeyMap: Record<string, string> = {
      firstName: 'first_name',
      lastName: 'last_name',
      businessName: 'business_name',
      businessAddress: 'business_address',
      businessType: 'business_type',
      registrationType: 'registration_type',
    };

    setFormData(prev => ({ ...prev, [name]: value }));
    if (name === 'businessAddress') {
      setSelectedCity(inferCaviteCity(value));
    }
    // Clear error when user starts typing
    const resolvedErrorKey = errorKeyMap[name] ?? name;
    if (errors[resolvedErrorKey]) {
      setErrors(prev => ({ ...prev, [resolvedErrorKey]: '' }));
    }

  };

  const handleSelectChange = (value: string) => {
    setFormData(prev => ({ ...prev, businessType: value }));
    // Clear error when user selects a value
    if (errors.business_type) {
      setErrors(prev => ({ ...prev, business_type: '' }));
    }
  };

  const handleRegistrationTypeChange = (value: string) => {
    setFormData(prev => ({ ...prev, registrationType: value }));

    if (errors.registration_type) {
      setErrors(prev => ({ ...prev, registration_type: '' }));
    }
  };

  const handleAddAdditionalDocument = () => {
    if (additionalDocuments.length >= MAX_ADDITIONAL_DOCUMENTS) {
      Swal.fire({
        icon: 'info',
        title: 'Limit Reached',
        text: `You can add up to ${MAX_ADDITIONAL_DOCUMENTS} other supporting documents.`,
        confirmButtonColor: '#3085d6',
      });
      return;
    }

    const newId = nextAdditionalDocId.current;
    nextAdditionalDocId.current += 1;
    setAdditionalDocuments((prev) => [...prev, { id: newId, file: null, fileName: '' }]);
  };

  const handleAdditionalDocumentDrop = (id: number, files: File[]) => {
    if (!files || files.length === 0) {
      return;
    }

    const file = files[0];
    setAdditionalDocuments((prev) => prev.map((doc) => (
      doc.id === id
        ? { ...doc, file, fileName: file.name }
        : doc
    )));

    Swal.fire({
      icon: 'info',
      title: 'File Attached',
      html: `<p><strong>${file.name}</strong> was added to <strong>Other Supporting Documents</strong>.</p><p class="text-sm text-gray-600">You can add more documents if needed.</p>`,
      confirmButtonText: 'OK',
      confirmButtonColor: '#3085d6',
    });
  };

  const handleRemoveAdditionalDocument = (id: number) => {
    setAdditionalDocuments((prev) => prev.filter((doc) => doc.id !== id));
  };

  const getCaviteLocationState = () => {
    const lat = parseFloat(geoLat);
    const lng = parseFloat(geoLng);

    if (Number.isFinite(lat) && Number.isFinite(lng)) {
      if (isWithinCaviteBounds(lat, lng)) {
        return { allowed: true, message: '' };
      }

      return {
        allowed: false,
        message: 'Shop pin must be placed within Cavite to continue registration.',
      };
    }

    const addressSource = `${formData.businessAddress} ${selectedCity} Cavite`.trim().toLowerCase();
    if (!addressSource) {
      return {
        allowed: false,
        message: 'Set your shop pin within Cavite or enter a Cavite address.',
      };
    }

    const hasCaviteKeyword = CAVITE_ADDRESS_KEYWORDS.some((keyword) => addressSource.includes(keyword));

    return {
      allowed: hasCaviteKeyword,
      message: hasCaviteKeyword ? '' : 'Address must resolve to Cavite.',
    };
  };

  const caviteLocationState = getCaviteLocationState();

  const hasValidGeoCoordinates = () => {
    const lat = parseFloat(geoLat);
    const lng = parseFloat(geoLng);
    return Number.isFinite(lat) && Number.isFinite(lng);
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

      const initLat = parseFloat(geoLat) || parseFloat(CAVITE_CENTER.lat);
      const initLng = parseFloat(geoLng) || parseFloat(CAVITE_CENTER.lng);

      const map = L.map(mapRef.current).setView([initLat, initLng], 16);
      L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '© OpenStreetMap contributors',
      }).addTo(map);

      const marker = L.marker([initLat, initLng], { draggable: true }).addTo(map);
      const circle = L.circle([initLat, initLng], {
        radius: geoRadius,
        color: '#2563eb',
        fillOpacity: 0.08,
      }).addTo(map);

      marker.on('dragend', () => {
        const pos = marker.getLatLng();
        setGeoLat(pos.lat.toFixed(8));
        setGeoLng(pos.lng.toFixed(8));
        circle.setLatLng(pos);
      });

      map.on('click', (e: any) => {
        marker.setLatLng(e.latlng);
        circle.setLatLng(e.latlng);
        setGeoLat(e.latlng.lat.toFixed(8));
        setGeoLng(e.latlng.lng.toFixed(8));
      });

      leafletMapRef.current = map;
      markerRef.current = marker;
      circleRef.current = circle;
      window.setTimeout(() => map.invalidateSize(), 0);
    });

    return () => {
      cancelled = true;
      if (leafletMapRef.current) {
        leafletMapRef.current.remove();
      }
      leafletMapRef.current = null;
      markerRef.current = null;
      circleRef.current = null;
    };
  }, [currentStep]);

  useEffect(() => {
    if (circleRef.current) {
      circleRef.current.setRadius(geoRadius);
    }
  }, [geoRadius]);

  useEffect(() => {
    const lat = parseFloat(geoLat);
    const lng = parseFloat(geoLng);
    if (!Number.isFinite(lat) || !Number.isFinite(lng)) return;
    if (!leafletMapRef.current || !markerRef.current || !circleRef.current) return;

    leafletMapRef.current.setView([lat, lng], 16);
    markerRef.current.setLatLng([lat, lng]);
    circleRef.current.setLatLng([lat, lng]);
  }, [geoLat, geoLng]);

  const handleUseMyGPS = () => {
    if (!navigator.geolocation) {
      setGeoError('Geolocation is not supported by your browser.');
      return;
    }

    setGettingGPS(true);
    setGeoError('');

    navigator.geolocation.getCurrentPosition(
      async (pos) => {
        const lat = pos.coords.latitude.toFixed(8);
        const lng = pos.coords.longitude.toFixed(8);
        setGeoLat(lat);
        setGeoLng(lng);

        try {
          const res = await fetch(
            `https://nominatim.openstreetmap.org/reverse?lat=${lat}&lon=${lng}&format=json`,
            { headers: { 'User-Agent': 'SoleSpace/1.0' } },
          );
          const data = await res.json();
          if (data.display_name) {
            setGeoAddress(data.display_name);
            setFormData(prev => ({ ...prev, businessAddress: data.display_name }));
            setSelectedCity(inferCaviteCity(data.display_name));
          }
        } catch {
          // Keep coordinates even if reverse geocoding fails.
        }

        setGettingGPS(false);
      },
      () => {
        setGeoError('Could not get your location. Please allow location access.');
        setGettingGPS(false);
      },
      { enableHighAccuracy: true },
    );
  };

  const handleSaveAddress = async () => {
    const lat = parseFloat(geoLat);
    const lng = parseFloat(geoLng);
    if (!Number.isFinite(lat) || !Number.isFinite(lng)) return;

    setSavingAddress(true);
    setGeoError('');

    try {
      const res = await fetch(
        `https://nominatim.openstreetmap.org/reverse?lat=${lat}&lon=${lng}&format=json`,
        { headers: { 'User-Agent': 'SoleSpace/1.0' } },
      );
      const data = await res.json();
      if (data.display_name) {
        setGeoAddress(data.display_name);
        setFormData(prev => ({ ...prev, businessAddress: data.display_name }));
        setSelectedCity(inferCaviteCity(data.display_name));
      } else {
        setGeoError('Could not find an address for this location. Please type the address manually.');
      }
    } catch {
      setGeoError('Failed to fetch address. Please type the address manually.');
    } finally {
      setSavingAddress(false);
    }
  };

  const getStepValidationErrors = (step: number): Record<string, string> => {
    const stepErrors: Record<string, string> = {};

    if (step === 1) {
      const firstName = formData.firstName.trim();
      const lastName = formData.lastName.trim();
      const email = formData.email.trim().toLowerCase();
      const phone = formData.phone.trim();

      if (!firstName) {
        stepErrors.first_name = 'Please enter your first name.';
      } else if (firstName.length < 2) {
        stepErrors.first_name = 'First name must be at least 2 characters.';
      }

      if (!lastName) {
        stepErrors.last_name = 'Please enter your last name.';
      } else if (lastName.length < 2) {
        stepErrors.last_name = 'Last name must be at least 2 characters.';
      }

      if (!email) {
        stepErrors.email = 'Please enter your email address.';
      } else if (!EMAIL_REGEX.test(email)) {
        stepErrors.email = 'Please enter a valid email address (example: name@email.com).';
      }

      if (!phone) {
        stepErrors.phone = 'Please enter your phone number.';
      } else if (!PHONE_REGEX.test(phone)) {
        stepErrors.phone = 'Phone number must be exactly 11 digits (example: 09171234567).';
      }

      const hasValidEmail = Boolean(email) && EMAIL_REGEX.test(email);
      if (hasValidEmail) {
        if (!emailVerificationSent) {
          stepErrors.email_verification = 'Click Send Code, then check your email for the 6-digit verification code.';
        } else if (!emailVerified) {
          stepErrors.email_verification = 'Please enter and verify the 6-digit code sent to your email before continuing.';
        }
      }
    }

    if (step === 2) {
      if (!formData.businessName.trim()) {
        stepErrors.business_name = 'Please enter your shop name.';
      }

      if (!formData.businessAddress.trim()) {
        stepErrors.business_address = 'Please enter your shop address.';
      }

      if (!formData.businessType) {
        stepErrors.business_type = 'Please select your shop type (Retail, Repair, or Both).';
      }

      if (!hasValidGeoCoordinates()) {
        stepErrors.shop_latitude = 'Please place your shop pin on the map or use GPS to set your exact location.';
      } else if (!caviteLocationState.allowed) {
        stepErrors.business_address = caviteLocationState.message || 'Only shops located within Cavite can register.';
      }
    }

    if (step === 3) {
      if (!uploadedDocuments.dti.file) {
        stepErrors.dti_registration = 'Upload your Shop Registration document (DTI or SEC).';
      }

      if (!uploadedDocuments.mayors_permit.file) {
        stepErrors.mayors_permit = "Upload your Mayor's Permit or Shop Permit.";
      }

      if (!uploadedDocuments.bir.file) {
        stepErrors.bir_certificate = 'Upload your BIR Certificate of Registration (COR).';
      }

      if (!uploadedDocuments.valid_id.file) {
        stepErrors.valid_id = 'Upload a valid government-issued ID of the owner.';
      }
    }

    return stepErrors;
  };

  const getAllValidationErrors = (): Record<string, string> => ({
    ...getStepValidationErrors(1),
    ...getStepValidationErrors(2),
    ...getStepValidationErrors(3),
  });

  const getFirstInvalidStep = (validationErrors: Record<string, string>): number => {
    const stepOneKeys = ['first_name', 'last_name', 'email', 'phone', 'email_verification'];
    const stepTwoKeys = ['business_name', 'business_address', 'business_type', 'shop_latitude', 'shop_longitude'];
    const stepThreeKeys = ['dti_registration', 'mayors_permit', 'bir_certificate', 'valid_id'];

    if (stepOneKeys.some((key) => Boolean(validationErrors[key]))) {
      return 1;
    }

    if (stepTwoKeys.some((key) => Boolean(validationErrors[key]))) {
      return 2;
    }

    if (stepThreeKeys.some((key) => Boolean(validationErrors[key]))) {
      return 3;
    }

    return 4;
  };

  const showValidationModal = (title: string, validationErrors: Record<string, string>) => {
    const uniqueMessages = Array.from(new Set(Object.values(validationErrors).filter(Boolean)));
    const listItems = uniqueMessages
      .map((message) => `<li style="margin-bottom:6px;">${escapeHtml(message)}</li>`)
      .join('');

    Swal.fire({
      icon: 'error',
      title,
      html: `<div style="text-align:left;"><p style="margin-bottom:8px;">Please check the following:</p><ul style="padding-left:18px; margin:0;">${listItems}</ul></div>`,
      confirmButtonColor: '#3085d6',
    });
  };

  const validateForm = () => {
    const validationErrors = getAllValidationErrors();

    return {
      valid: Object.keys(validationErrors).length === 0,
      errors: validationErrors,
      firstInvalidStep: getFirstInvalidStep(validationErrors),
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

  const handleSendEmailVerificationCode = async () => {
    const trimmedEmail = formData.email.trim().toLowerCase();

    if (!trimmedEmail) {
      setErrors(prev => ({ ...prev, email: 'Please enter your email address.' }));
      return;
    }

    if (!EMAIL_REGEX.test(trimmedEmail)) {
      setErrors(prev => ({ ...prev, email: 'Please enter a valid email address (example: name@email.com).' }));
      return;
    }

    const availability = await checkEmailAvailability(trimmedEmail);
    if (!availability.available) {
      const message = availability.message || 'This email is already registered';
      setErrors(prev => ({ ...prev, email: message }));
      setEmailVerified(false);
      setEmailVerificationSent(false);
      setEmailVerificationMessage('');
      return;
    }

    setIsSendingEmailCode(true);
    setEmailVerificationMessage('');

    try {
      const response = await fetch('/shop-owner/email-verification/send-code', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
          Accept: 'application/json',
        },
        body: JSON.stringify({ email: trimmedEmail }),
      });

      const data = await response.json().catch(() => ({}));

      if (!response.ok) {
        const message = (data?.message as string) || 'Unable to send verification code.';
        setErrors(prev => ({ ...prev, email_verification: message }));
        return;
      }

      if (Boolean(data?.already_verified)) {
        setEmailVerificationSent(true);
        setEmailVerified(true);
        setEmailVerificationCode('');
        setErrors(prev => ({ ...prev, email_verification: '', email: '' }));
        setEmailVerificationMessage((data?.message as string) || 'Email is already verified. You can proceed to the next step.');

        Swal.fire({
          icon: 'success',
          title: 'Email already verified',
          text: 'Your email is already verified. You can continue registration.',
          confirmButtonColor: '#3085d6',
        });
        return;
      }

      setEmailVerificationSent(true);
      setEmailVerified(false);
      setEmailVerificationCode('');
      setErrors(prev => ({ ...prev, email_verification: '', email: '' }));
      setEmailVerificationMessage((data?.message as string) || 'Verification code sent to your email.');

      Swal.fire({
        icon: 'success',
        title: 'Verification code sent',
        text: 'Check your email inbox and enter the 6-digit code below.',
        confirmButtonColor: '#3085d6',
      });
    } catch {
      setErrors(prev => ({ ...prev, email_verification: 'Unable to send verification code right now.' }));
    } finally {
      setIsSendingEmailCode(false);
    }
  };

  const handleVerifyEmailCode = async () => {
    const trimmedEmail = formData.email.trim().toLowerCase();
    const trimmedCode = emailVerificationCode.trim();

    if (!emailVerificationSent) {
      setErrors(prev => ({ ...prev, email_verification: 'Request a verification code first.' }));
      return;
    }

    if (!/^\d{6}$/.test(trimmedCode)) {
      setErrors(prev => ({ ...prev, email_verification: 'Enter the 6-digit verification code.' }));
      return;
    }

    setIsVerifyingEmailCode(true);

    try {
      const response = await fetch('/shop-owner/email-verification/verify-code', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
          Accept: 'application/json',
        },
        body: JSON.stringify({ email: trimmedEmail, otp: trimmedCode }),
      });

      const data = await response.json().catch(() => ({}));

      if (!response.ok) {
        const message = (data?.message as string) || 'Verification failed. Please try again.';
        setEmailVerified(false);
        setErrors(prev => ({ ...prev, email_verification: message }));
        return;
      }

      setEmailVerified(true);
      setErrors(prev => ({ ...prev, email_verification: '' }));
      setEmailVerificationMessage((data?.message as string) || 'Email verified successfully.');

      Swal.fire({
        icon: 'success',
        title: 'Email verified',
        text: 'You can now continue to the next step.',
        confirmButtonColor: '#3085d6',
      });
    } catch {
      setErrors(prev => ({ ...prev, email_verification: 'Unable to verify code right now. Please try again.' }));
      setEmailVerified(false);
    } finally {
      setIsVerifyingEmailCode(false);
    }
  };

  const handleNext = async () => {
    const stepErrors = getStepValidationErrors(currentStep);
    if (Object.keys(stepErrors).length > 0) {
      setErrors((prev) => ({ ...prev, ...stepErrors }));
      showValidationModal('Please fix the highlighted fields', stepErrors);
      return;
    }

    if (currentStep === 1) {
      const trimmedEmail = formData.email.trim();
      const result = await checkEmailAvailability(trimmedEmail);

      if (!result.available) {
        const message = result.message || 'This email is already registered';
        const emailError = { email: message };
        setErrors(prev => ({ ...prev, ...emailError }));
        showValidationModal('Email not available', emailError);
        return;
      }
    }

    setCurrentStep(currentStep + 1);
  };

  const handlePrev = () => {
    setCurrentStep(currentStep - 1);
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();

    if (!emailVerified) {
      setCurrentStep(1);
      setErrors(prev => ({
        ...prev,
        email_verification: 'Please verify your email first before submitting your registration.',
      }));
      Swal.fire({
        icon: 'error',
        title: 'Email verification required',
        text: 'Verify your email in Step 1 before submitting your registration.',
        confirmButtonColor: '#3085d6',
      });
      return;
    }

    const validation = validateForm();
    if (!validation.valid) {
      setErrors((prev) => ({ ...prev, ...validation.errors }));
      setCurrentStep(validation.firstInvalidStep);
      showValidationModal('Please complete the required details', validation.errors);
      return;
    }

    if (!caviteLocationState.allowed) {
      Swal.fire({
        icon: 'error',
        title: 'Outside Cavite',
        text: caviteLocationState.message || 'Only Cavite shop locations can register.',
        confirmButtonColor: '#3085d6',
      });
      return;
    }

    const result = await Swal.fire({
      title: 'Confirm Submission',
      text: 'Are you sure you want to submit your registration? All documents will be reviewed within 3-7 business days.',
      icon: 'question',
      showCancelButton: true,
      confirmButtonText: 'Yes, Submit',
      cancelButtonText: 'Cancel',
    });

    if (result.isConfirmed) {
      setIsSubmitting(true);

      try {
        // Prepare form data for submission
        const submitData = new FormData();
        
        // Add basic fields
        submitData.append('first_name', formData.firstName);
        submitData.append('last_name', formData.lastName);
        submitData.append('email', formData.email);
        submitData.append('phone', formData.phone);
        submitData.append('business_name', formData.businessName);
        submitData.append('business_address', formData.businessAddress);
        submitData.append('postal_code', formData.postalCode);
        submitData.append('business_type', formData.businessType);
        submitData.append('registration_type', formData.registrationType);
        // Only enable attendance geofence for company accounts (they have staff to clock in)
        const isIndividual = formData.registrationType === 'individual';
        submitData.append('attendance_geofence_enabled', isIndividual ? '0' : '1');
        submitData.append('shop_latitude', geoLat);
        submitData.append('shop_longitude', geoLng);
        submitData.append('shop_address', geoAddress || formData.businessAddress);
        if (!isIndividual) {
          submitData.append('shop_geofence_radius', String(geoRadius));
        }

        // Operating hours removed — nothing to append for operating hours

        // Add document files
        if (uploadedDocuments.dti.file) {
          submitData.append('dti_registration', uploadedDocuments.dti.file);
        }
        if (uploadedDocuments.mayors_permit.file) {
          submitData.append('mayors_permit', uploadedDocuments.mayors_permit.file);
        }
        if (uploadedDocuments.bir.file) {
          submitData.append('bir_certificate', uploadedDocuments.bir.file);
        }
        if (uploadedDocuments.valid_id.file) {
          submitData.append('valid_id', uploadedDocuments.valid_id.file);
        }
        additionalDocuments.forEach((doc) => {
          if (doc.file) {
            submitData.append('other_documents[]', doc.file);
          }
        });

        // Submit to backend
        router.post(route('shop-owner.register'), submitData, {
          forceFormData: true,
          onSuccess: () => {
            setIsSubmitting(false);
            Swal.fire({
              icon: 'success',
              title: 'Registration Submitted!',
              html: `
                <p>Thank you for registering.</p>
                <p class="mt-3">Your application is now under review.</p>
                <p class="text-sm text-gray-600 mt-3">You will receive status updates through email.</p>
              `,
              confirmButtonText: 'OK',
              confirmButtonColor: '#3085d6',
            }).then(() => {
              setShowSuccessModal(true);
            });
          },
          onError: (backendErrors) => {
            setIsSubmitting(false);
            // Map backend errors to state
            const mapped: Record<string, string> = {};
            Object.entries(backendErrors || {}).forEach(([key, val]) => {
              mapped[key] = Array.isArray(val) ? val[0] : String(val);
            });
            setErrors(mapped);

            const firstInvalidStep = getFirstInvalidStep(mapped);
            setCurrentStep(firstInvalidStep);
            showValidationModal('Registration failed. Please review your details.', mapped);
          },
        });
      } catch (error) {
        setIsSubmitting(false);
        Swal.fire({
          icon: 'error',
          title: 'Error',
          text: 'An unexpected error occurred. Please try again.',
          confirmButtonColor: '#3085d6',
        });
      }
    }
  };

  const requiredUploadCount = [
    uploadedDocuments.dti.file,
    uploadedDocuments.mayors_permit.file,
    uploadedDocuments.bir.file,
    uploadedDocuments.valid_id.file,
  ].filter(Boolean).length;
  const additionalUploadCount = additionalDocuments.filter((doc) => !!doc.file).length;
  const hasAdditionalDocuments = additionalDocuments.length > 0;
  const hasReachedAdditionalLimit = additionalDocuments.length >= MAX_ADDITIONAL_DOCUMENTS;
  const registrationSteps = [
    { id: 1, label: 'Personal Info', shortLabel: 'Personal' },
    { id: 2, label: 'Shop Info', shortLabel: 'Shop' },
    { id: 3, label: 'Documents', shortLabel: 'Docs' },
    { id: 4, label: 'Review & Submit', shortLabel: 'Review' },
  ];

  return (
    <>
      <Head title="Shop Owner Registration" />
      <div className="min-h-screen bg-gradient-to-br from-slate-50 to-slate-100">
        <Navigation />
        <div className="max-w-6xl mx-auto px-4 lg:px-8 pt-24 pb-8 md:pt-28 md:pb-12 lg:pt-32">
          {/* Header Section */}
          <div className="text-center mb-8 md:mb-10 lg:mb-12 px-1">
            <h1 className="text-3xl sm:text-4xl lg:text-5xl font-bold text-gray-900 mb-3 md:mb-4 tracking-tight leading-tight">
              Shop Owner Registration
            </h1>
            <p className="text-base sm:text-lg text-gray-600 max-w-2xl mx-auto mb-1.5 md:mb-2">
              Join our platform and reach more customers
            </p>
            <p className="text-xs sm:text-sm text-gray-500">
              Complete your registration to start selling products and services
            </p>
          </div>

          {/* Progress Indicator */}
          <div className="mb-6 md:mb-8 bg-white rounded-2xl shadow-sm border border-gray-200 p-4 md:p-5 lg:p-6">
            <div className="grid grid-cols-2 gap-2 md:gap-3 lg:hidden">
              {registrationSteps.map((stepItem) => (
                <div
                  key={stepItem.id}
                  className={`rounded-xl border px-3 py-2.5 ${
                    currentStep >= stepItem.id
                      ? 'border-blue-200 bg-blue-50'
                      : 'border-gray-200 bg-gray-50'
                  }`}
                >
                  <div className="flex items-center gap-2">
                    <div className={`flex h-7 w-7 items-center justify-center rounded-full text-xs font-semibold ${
                      currentStep >= stepItem.id ? 'bg-blue-600 text-white' : 'bg-gray-300 text-gray-600'
                    }`}>
                      {stepItem.id}
                    </div>
                    <span className={`text-xs font-semibold leading-tight ${
                      currentStep >= stepItem.id ? 'text-gray-900' : 'text-gray-500'
                    }`}>
                      {stepItem.shortLabel}
                    </span>
                  </div>
                </div>
              ))}
            </div>

            <div className="hidden lg:flex lg:items-center lg:justify-between">
              {registrationSteps.map((stepItem, index) => (
                <div key={stepItem.id} className="flex items-center">
                  <div className="flex items-center space-x-2">
                    <div className={`flex items-center justify-center w-8 h-8 rounded-full text-sm font-semibold ${
                      currentStep >= stepItem.id ? 'bg-blue-600 text-white' : 'bg-gray-300 text-gray-600'
                    }`}>
                      {stepItem.id}
                    </div>
                    <span className={`text-sm font-medium ${
                      currentStep >= stepItem.id ? 'text-gray-900' : 'text-gray-500'
                    }`}>
                      {stepItem.label}
                    </span>
                  </div>
                  {index < registrationSteps.length - 1 && (
                    <div className={`w-14 xl:w-20 mx-4 h-1 rounded-full ${
                      currentStep > stepItem.id ? 'bg-blue-600' : 'bg-gray-200'
                    }`}></div>
                  )}
                </div>
              ))}
            </div>
          </div>

          <div className="space-y-6">
            {currentStep === 1 && (
              <ComponentCard title="Personal Information">
                <div className="grid grid-cols-1 gap-6 md:grid-cols-2">
                  <div>
                    <Label htmlFor="firstName">First Name</Label>
                    <Input
                      type="text"
                      id="firstName"
                      name="firstName"
                      value={formData.firstName}
                      onChange={handleInputChange}
                      placeholder="Enter first name"
                      className={errors.first_name ? 'border-red-500' : ''}
                    />
                    {errors.first_name && <p className="mt-1 text-sm text-red-600">{errors.first_name}</p>}
                  </div>
                  <div>
                    <Label htmlFor="lastName">Last Name</Label>
                    <Input
                      type="text"
                      id="lastName"
                      name="lastName"
                      value={formData.lastName}
                      onChange={handleInputChange}
                      placeholder="Enter last name"
                      className={errors.last_name ? 'border-red-500' : ''}
                    />
                    {errors.last_name && <p className="mt-1 text-sm text-red-600">{errors.last_name}</p>}
                  </div>
                  <div>
                    <Label htmlFor="email">Email</Label>
                    <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
                      <div className="w-full">
                        <Input
                          type="email"
                          id="email"
                          name="email"
                          value={formData.email}
                          onChange={handleInputChange}
                          placeholder="Enter email address"
                          className={errors.email ? 'border-red-500' : ''}
                        />
                      </div>
                      <button
                        type="button"
                        onClick={handleSendEmailVerificationCode}
                        disabled={isSendingEmailCode || emailVerified || !formData.email.trim()}
                        className="w-full sm:w-auto whitespace-nowrap px-4 py-2 border border-blue-600 text-blue-600 font-semibold text-sm hover:bg-blue-50 transition-colors disabled:opacity-50"
                      >
                        {emailVerified ? 'Email Verified' : (isSendingEmailCode ? 'Sending...' : (emailVerificationSent ? 'Resend Code' : 'Send Code'))}
                      </button>
                    </div>
                    {errors.email && <p className="mt-1 text-sm text-red-600">{errors.email}</p>}
                    {emailVerificationMessage && (
                      <p className={`mt-1 text-sm ${emailVerified ? 'text-green-600' : 'text-blue-600'}`}>
                        {emailVerificationMessage}
                      </p>
                    )}
                    {emailVerificationSent && (
                      <div className="mt-3 flex flex-col gap-2 sm:flex-row sm:items-center">
                        <Input
                          type="text"
                          id="emailVerificationCode"
                          name="emailVerificationCode"
                          value={emailVerificationCode}
                          onChange={(e) => setEmailVerificationCode(e.target.value.replace(/\D/g, '').slice(0, 6))}
                          placeholder="Enter 6-digit code"
                          className={errors.email_verification ? 'border-red-500' : ''}
                        />
                        <button
                          type="button"
                          onClick={handleVerifyEmailCode}
                          disabled={isVerifyingEmailCode || emailVerificationCode.trim().length !== 6 || emailVerified}
                          className="w-full sm:w-auto whitespace-nowrap px-4 py-2 border border-green-600 text-green-700 font-semibold text-sm hover:bg-green-50 transition-colors disabled:opacity-50"
                        >
                          {emailVerified ? 'Verified' : (isVerifyingEmailCode ? 'Verifying...' : 'Verify Email')}
                        </button>
                      </div>
                    )}
                    {errors.email_verification && <p className="mt-1 text-sm text-red-600">{errors.email_verification}</p>}
                  </div>
                  <div>
                    <Label htmlFor="phone">Phone Number</Label>
                    <Input
                      type="tel"
                      id="phone"
                      name="phone"
                      value={formData.phone}
                      onChange={handleInputChange}
                      inputMode="numeric"
                      pattern="[0-9]*"
                      maxLength={11}
                      placeholder="Enter phone number"
                      className={errors.phone ? 'border-red-500' : ''}
                    />
                    {errors.phone && <p className="mt-1 text-sm text-red-600">{errors.phone}</p>}
                  </div>
                </div>
                <div className="flex justify-end pt-4">
                  <button
                    type="button"
                    onClick={handleNext}
                    className="w-full sm:w-auto px-6 py-3 bg-black text-white font-semibold uppercase tracking-wider text-sm hover:bg-black/80 transition-colors"
                  >
                    Next
                  </button>
                </div>
              </ComponentCard>
            )}

            {currentStep === 2 && (
              <ComponentCard title="Shop Information">
                <div className="space-y-6">
                  <div className="grid grid-cols-1 gap-6 md:grid-cols-2">
                    <div>
                      <Label htmlFor="businessName">Shop Name</Label>
                      <Input
                        type="text"
                        id="businessName"
                        name="businessName"
                        value={formData.businessName}
                        onChange={handleInputChange}
                        placeholder="Enter shop name"
                        className={errors.business_name ? 'border-red-500' : ''}
                      />
                      {errors.business_name && <p className="mt-1 text-sm text-red-600">{errors.business_name}</p>}
                    </div>
                    <div>
                      <Label htmlFor="businessAddress">Shop Address</Label>
                      <div className="mt-1 flex flex-col gap-2 sm:flex-row sm:items-stretch">
                        <div className="flex-1">
                          <Input
                            type="text"
                            id="businessAddress"
                            name="businessAddress"
                            value={formData.businessAddress}
                            onChange={handleInputChange}
                            placeholder="Enter shop address"
                            className={`w-full ${errors.business_address ? 'border-red-500' : ''}`}
                          />
                        </div>
                        <button
                          type="button"
                          onClick={handleUseMyGPS}
                          disabled={gettingGPS}
                          className="shrink-0 px-4 py-2 border border-blue-600 text-blue-600 font-semibold text-sm hover:bg-blue-50 transition-colors disabled:opacity-50 whitespace-nowrap"
                        >
                          {gettingGPS ? 'Getting GPS...' : 'Use My GPS'}
                        </button>
                      </div>
                      {errors.business_address && <p className="mt-1 text-sm text-red-600">{errors.business_address}</p>}
                    </div>
                    <div className="md:col-span-2">
                      <Label htmlFor="postalCode">Postal Code / ZIP Code</Label>
                      <Input
                        type="text"
                        id="postalCode"
                        name="postalCode"
                        value={formData.postalCode}
                        onChange={handleInputChange}
                        inputMode="numeric"
                        pattern="[0-9]*"
                        placeholder="Enter postal or ZIP code"
                        className={errors.postal_code ? 'border-red-500' : ''}
                      />
                      {errors.postal_code && <p className="mt-1 text-sm text-red-600">{errors.postal_code}</p>}
                    </div>
                  </div>
                  <div className="grid grid-cols-1 gap-6 md:grid-cols-2">
                    <div>
                      <Label htmlFor="province">Province</Label>
                      <Input
                        type="text"
                        id="province"
                        name="province"
                        value="Cavite"
                        disabled
                        className="w-full bg-gray-100 text-gray-600"
                      />
                      <p className="mt-1 text-xs text-gray-500">Registration is limited to shops within Cavite.</p>
                    </div>
                    <div>
                      <Label htmlFor="caviteCity">City / Municipality (optional)</Label>
                      <select
                        id="caviteCity"
                        name="caviteCity"
                        aria-label="Cavite city or municipality"
                        title="Cavite city or municipality"
                        value={selectedCity}
                        onChange={(e) => setSelectedCity(e.target.value)}
                        className="h-11 w-full appearance-none rounded-lg border border-gray-300 bg-transparent px-4 py-2.5 pr-11 text-sm text-gray-800 shadow-theme-xs focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10"
                      >
                        <option value="">Select Cavite city/municipality</option>
                        {CAVITE_CITIES.map((city) => (
                          <option key={city} value={city}>{city}</option>
                        ))}
                      </select>
                      <p className="mt-1 text-xs text-gray-500">Use this to confirm the shop is within a Cavite locality.</p>
                    </div>
                  </div>
                  <div>
                    <Label>Shop Type</Label>
                    <Select
                      options={businessTypeOptions}
                      placeholder="Select shop type"
                      onChange={handleSelectChange}
                    />
                    {errors.business_type && <p className="mt-1 text-sm text-red-600">{errors.business_type}</p>}
                  </div>

                  <div>
                    <Label>Registration Type</Label>
                    <div className="flex flex-wrap items-center gap-8">
                      <Radio
                        id="individual"
                        name="registrationType"
                        value="individual"
                        checked={formData.registrationType === "individual"}
                        onChange={handleRegistrationTypeChange}
                        label="Registered as Individual"
                      />
                      <Radio
                        id="company"
                        name="registrationType"
                        value="company"
                        checked={formData.registrationType === "company"}
                        onChange={handleRegistrationTypeChange}
                        label="Registered as Business"
                      />
                    </div>
                  </div>

                  <div className="rounded-xl border border-gray-200 bg-white p-4 md:p-5 space-y-5">
                    {!caviteLocationState.allowed && (
                      <p className="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-700">
                        <span className="font-semibold">Cavite-only policy:</span> {caviteLocationState.message}
                      </p>
                    )}

                    {geoAddress && (
                      <p className="rounded-lg border border-green-200 bg-green-50 px-3 py-2 text-xs text-green-800">
                        <span className="font-semibold">Detected address:</span> {geoAddress}. If this is wrong, drag the pin on the map.
                      </p>
                    )}

                    <div>
                      <p className="mb-2 text-sm font-medium text-gray-700">
                        Shop Location <span className="font-normal text-gray-500">(drag pin or click map to adjust)</span>
                      </p>
                      <div ref={mapRef} className="h-64 sm:h-72 w-full rounded-xl border border-gray-200 overflow-hidden z-0" />
                      <div className="mt-3 flex justify-end">
                        <button
                          type="button"
                          onClick={handleSaveAddress}
                          disabled={savingAddress}
                          className="w-full sm:w-auto px-4 py-2 border border-blue-600 text-blue-600 font-semibold text-sm hover:bg-blue-50 transition-colors disabled:opacity-50 whitespace-nowrap rounded"
                        >
                          {savingAddress ? 'Saving...' : 'Save Location'}
                        </button>
                      </div>
                    </div>

                    {geoError && <p className="text-sm text-red-600">{geoError}</p>}
                    {errors.shop_latitude && <p className="text-sm text-red-600">{errors.shop_latitude}</p>}
                    {errors.shop_longitude && <p className="text-sm text-red-600">{errors.shop_longitude}</p>}
                  </div>
                </div>
                <div className="flex flex-col-reverse gap-3 sm:flex-row sm:justify-between pt-4">
                  <button
                    type="button"
                    onClick={handlePrev}
                    className="w-full sm:w-auto px-6 py-3 bg-gray-200 text-gray-700 font-semibold uppercase tracking-wider text-sm hover:bg-gray-300 transition-colors"
                  >
                    Previous
                  </button>
                  <button
                    type="button"
                    onClick={handleNext}
                    disabled={!caviteLocationState.allowed}
                    className="w-full sm:w-auto px-6 py-3 bg-black text-white font-semibold uppercase tracking-wider text-sm hover:bg-black/80 transition-colors"
                  >
                    Next
                  </button>
                </div>
              </ComponentCard>
            )}

            {currentStep === 3 && (
              <ComponentCard title="Document Upload">
                <div className="space-y-4">
                  <Label>Shop Permits & Credentials</Label>
                  <p className="text-sm text-gray-600">
                    Upload your shop license, permits, or other relevant credentials for verification.
                  </p>
                  <div className="mb-6">
                    <h4 className="text-lg font-semibold text-gray-800 mb-4">Document Submission Instructions</h4>
                    <p className="text-sm text-gray-600 mb-4">
                      Please upload clear photos of the following documents:
                    </p>
                    <ul className="list-disc list-inside text-sm text-gray-600 mb-4 space-y-1">
                      <li>Shop Registration (DTI/SEC)</li>
                      <li>Mayor's Permit / Shop Permit</li>
                      <li>BIR Certificate of Registration (COR)</li>
                    </ul>
                    <p className="text-sm font-semibold text-gray-800 mb-2">Guidelines for your photos:</p>
                    <ul className="list-disc list-inside text-sm text-gray-600 space-y-1">
                      <li>Take a photo of the entire document, ensure all text and details are visible.</li>
                      <li>Do not cut any part of the document.</li>
                      <li>Make sure the photo is clear and in focus, no blurry or dark areas.</li>
                      <li>Only submit image files: JPG or PNG (no WebP, SVG, PDF, or other formats).</li>
                      <li>Avoid shadows or glare that can hide details.</li>
                      <li>Ensure all edges of the document are visible in the photo.</li>
                      <li>If the document is large, take a single photo that captures the full page, not multiple cropped images.</li>
                      <li>No edits or filters—the document must be authentic and readable.</li>
                    </ul>
                  </div>
                  <div className="rounded-xl border border-gray-200 bg-white p-4 md:p-5">
                    <div className="mb-4 flex flex-wrap items-center justify-between gap-2">
                      <h4 className="text-sm font-semibold text-gray-900">Required Documents</h4>
                      <span className="rounded-full bg-blue-50 px-3 py-1 text-xs font-semibold text-blue-700">
                        {requiredUploadCount} / 4 uploaded
                      </span>
                    </div>
                    <p className="mb-4 text-xs text-gray-500">
                      Complete all required uploads before proceeding to the review step.
                    </p>
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                      <Label>Shop Registration (DTI) {uploadedDocuments.dti.file && <span className="text-green-600 font-bold ml-2">✓ Uploaded</span>}</Label>
                      <DropzoneComponent
                        onDrop={(files) => {
                          if (files && files.length > 0) {
                            const file = files[0];
                            setUploadedDocuments(prev => ({
                              ...prev,
                              dti: { file: file, fileName: file.name }
                            }));

                            Swal.fire({
                              icon: 'info',
                              title: 'File Attached',
                              html: `<p><strong>${file.name}</strong> was added to <strong>Shop Registration (DTI)</strong>.</p><p class="text-sm text-gray-600">Please ensure the correct document is uploaded in this section.</p>`,
                              confirmButtonText: 'OK',
                              confirmButtonColor: '#3085d6',
                            });

                            // Clear error when file is uploaded
                            if (errors.dti_registration) {
                              setErrors(prev => ({ ...prev, dti_registration: '' }));
                            }
                          }
                        }}
                        isUploaded={!!uploadedDocuments.dti.file}
                        fileName={uploadedDocuments.dti.fileName}
                      />
                      {uploadedDocuments.dti.file && (
                        <p className="mt-2 text-sm text-green-600 font-semibold flex items-center">
                          <svg className="w-4 h-4 mr-1" fill="currentColor" viewBox="0 0 20 20">
                            <path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clipRule="evenodd" />
                          </svg>
                          Document uploaded successfully
                        </p>
                      )}
                      {errors.dti_registration && <p className="mt-1 text-sm text-red-600">{errors.dti_registration}</p>}
                    </div>
                    <div>
                      <Label>Mayor's Permit / Shop Permit {uploadedDocuments.mayors_permit.file && <span className="text-green-600 font-bold ml-2">✓ Uploaded</span>}</Label>
                      <DropzoneComponent
                        onDrop={(files) => {
                          if (files && files.length > 0) {
                            const file = files[0];
                            setUploadedDocuments(prev => ({
                              ...prev,
                              mayors_permit: { file: file, fileName: file.name }
                            }));
                            Swal.fire({
                              icon: 'info',
                              title: 'File Attached',
                              html: `<p><strong>${file.name}</strong> was added to <strong>Mayor's Permit / Shop Permit</strong>.</p><p class="text-sm text-gray-600">Please ensure the correct document is uploaded in this section.</p>`,
                              confirmButtonText: 'OK',
                              confirmButtonColor: '#3085d6',
                            });

                            // Clear error when file is uploaded
                            if (errors.mayors_permit) {
                              setErrors(prev => ({ ...prev, mayors_permit: '' }));
                            }
                          }
                        }}
                        isUploaded={!!uploadedDocuments.mayors_permit.file}
                        fileName={uploadedDocuments.mayors_permit.fileName}
                      />
                      {uploadedDocuments.mayors_permit.file && (
                        <p className="mt-2 text-sm text-green-600 font-semibold flex items-center">
                          <svg className="w-4 h-4 mr-1" fill="currentColor" viewBox="0 0 20 20">
                            <path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clipRule="evenodd" />
                          </svg>
                          Document uploaded successfully
                        </p>
                      )}
                      {errors.mayors_permit && <p className="mt-1 text-sm text-red-600">{errors.mayors_permit}</p>}
                    </div>
                    <div>
                      <Label>BIR Certificate of Registration (COR) {uploadedDocuments.bir.file && <span className="text-green-600 font-bold ml-2">✓ Uploaded</span>}</Label>
                      <DropzoneComponent
                        onDrop={(files) => {
                          if (files && files.length > 0) {
                            const file = files[0];
                            setUploadedDocuments(prev => ({
                              ...prev,
                              bir: { file: file, fileName: file.name }
                            }));
                            Swal.fire({
                              icon: 'info',
                              title: 'File Attached',
                              html: `<p><strong>${file.name}</strong> was added to <strong>BIR Certificate of Registration (COR)</strong>.</p><p class="text-sm text-gray-600">Please ensure the correct document is uploaded in this section.</p>`,
                              confirmButtonText: 'OK',
                              confirmButtonColor: '#3085d6',
                            });

                            // Clear error when file is uploaded
                            if (errors.bir_certificate) {
                              setErrors(prev => ({ ...prev, bir_certificate: '' }));
                            }
                          }
                        }}
                        isUploaded={!!uploadedDocuments.bir.file}
                        fileName={uploadedDocuments.bir.fileName}
                      />
                      {uploadedDocuments.bir.file && (
                        <p className="mt-2 text-sm text-green-600 font-semibold flex items-center">
                          <svg className="w-4 h-4 mr-1" fill="currentColor" viewBox="0 0 20 20">
                            <path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clipRule="evenodd" />
                          </svg>
                          Document uploaded successfully
                        </p>
                      )}
                      {errors.bir_certificate && <p className="mt-1 text-sm text-red-600">{errors.bir_certificate}</p>}
                    </div>
                    <div>
                      <Label>Valid ID of Owner {uploadedDocuments.valid_id.file && <span className="text-green-600 font-bold ml-2">✓ Uploaded</span>}</Label>
                      <DropzoneComponent
                        onDrop={(files) => {
                          if (files && files.length > 0) {
                            const file = files[0];
                            setUploadedDocuments(prev => ({
                              ...prev,
                              valid_id: { file: file, fileName: file.name }
                            }));
                            Swal.fire({
                              icon: 'info',
                              title: 'File Attached',
                              html: `<p><strong>${file.name}</strong> was added to <strong>Valid ID of Owner</strong>.</p><p class="text-sm text-gray-600">Please ensure the correct document is uploaded in this section.</p>`,
                              confirmButtonText: 'OK',
                              confirmButtonColor: '#3085d6',
                            });

                            // Clear error when file is uploaded
                            if (errors.valid_id) {
                              setErrors(prev => ({ ...prev, valid_id: '' }));
                            }
                          }
                        }}
                        isUploaded={!!uploadedDocuments.valid_id.file}
                        fileName={uploadedDocuments.valid_id.fileName}
                      />
                      {uploadedDocuments.valid_id.file && (
                        <p className="mt-2 text-sm text-green-600 font-semibold flex items-center">
                          <svg className="w-4 h-4 mr-1" fill="currentColor" viewBox="0 0 20 20">
                            <path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clipRule="evenodd" />
                          </svg>
                          Document uploaded successfully
                        </p>
                      )}
                      {errors.valid_id && <p className="mt-1 text-sm text-red-600">{errors.valid_id}</p>}
                    </div>
                    </div>
                  </div>

                  <div className="rounded-xl border border-dashed border-blue-200 bg-blue-50/40 p-4 md:p-5">
                    <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                      <div>
                        <h4 className="text-sm font-semibold text-gray-900">Other Supporting Documents (Optional)</h4>
                        <p className="mt-1 text-xs text-gray-600">
                          Add extra proof files like lease contracts, permits, or other shop-related documents.
                        </p>
                      </div>
                      <button
                        type="button"
                        onClick={handleAddAdditionalDocument}
                        disabled={hasReachedAdditionalLimit}
                        className="inline-flex items-center justify-center rounded-md border border-blue-600 px-4 py-2 text-sm font-semibold text-blue-700 transition-colors hover:bg-blue-100 disabled:cursor-not-allowed disabled:border-gray-300 disabled:text-gray-400 disabled:hover:bg-transparent"
                      >
                        + Others
                      </button>
                    </div>

                    {hasAdditionalDocuments ? (
                      <>
                        <p className="mt-4 text-xs font-medium text-blue-700">
                          {additionalUploadCount} of {additionalDocuments.length} optional document(s) uploaded
                        </p>
                        <div className="mt-3 grid grid-cols-1 gap-4 md:grid-cols-2">
                          {additionalDocuments.map((doc, index) => (
                            <div key={doc.id} className="rounded-lg border border-gray-200 bg-white p-3">
                              <div className="mb-2 flex items-start justify-between gap-3">
                                <div>
                                  <p className="text-sm font-semibold text-gray-900">Supporting Document #{index + 1}</p>
                                  <p className="text-xs text-gray-500">Optional upload</p>
                                </div>
                                <button
                                  type="button"
                                  onClick={() => handleRemoveAdditionalDocument(doc.id)}
                                  className="inline-flex items-center rounded-md border border-red-200 px-2.5 py-1 text-xs font-semibold text-red-600 transition-colors hover:bg-red-50"
                                >
                                  Remove
                                </button>
                              </div>
                              <DropzoneComponent
                                onDrop={(files) => handleAdditionalDocumentDrop(doc.id, files)}
                                isUploaded={!!doc.file}
                                fileName={doc.fileName}
                              />
                              {doc.file && (
                                <p className="mt-2 text-sm text-green-600 font-semibold flex items-center">
                                  <svg className="w-4 h-4 mr-1" fill="currentColor" viewBox="0 0 20 20">
                                    <path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clipRule="evenodd" />
                                  </svg>
                                  Document uploaded successfully
                                </p>
                              )}
                            </div>
                          ))}
                        </div>
                      </>
                    ) : (
                      <div className="mt-4 rounded-lg border border-blue-100 bg-white p-4 text-center">
                        <p className="text-sm font-medium text-gray-700">No optional document added yet.</p>
                        <p className="mt-1 text-xs text-gray-500">Use the Others button when you want to attach extra proof files.</p>
                      </div>
                    )}

                    {hasReachedAdditionalLimit && (
                      <p className="mt-3 text-xs text-amber-700">
                        You reached the maximum of {MAX_ADDITIONAL_DOCUMENTS} optional documents.
                      </p>
                    )}
                  </div>
                </div>
                <div className="flex flex-col-reverse gap-3 sm:flex-row sm:justify-between pt-4">
                  <button
                    type="button"
                    onClick={handlePrev}
                    className="w-full sm:w-auto px-6 py-3 bg-gray-200 text-gray-700 font-semibold uppercase tracking-wider text-sm hover:bg-gray-300 transition-colors"
                  >
                    Previous
                  </button>
                  <button
                    type="button"
                    onClick={handleNext}
                    className="w-full sm:w-auto px-6 py-3 bg-black text-white font-semibold uppercase tracking-wider text-sm hover:bg-black/80 transition-colors"
                  >
                    Next
                  </button>
                </div>
              </ComponentCard>
            )}

            {currentStep === 4 && (
              <>
                {/* Review Timeline */}
                <div className="bg-blue-50 border border-blue-200 rounded-lg p-4 md:p-6">
                  <div className="flex flex-col sm:flex-row gap-4">
                    <div className="flex-shrink-0">
                      <svg className="w-6 h-6 text-blue-600" fill="currentColor" viewBox="0 0 20 20">
                        <path fillRule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clipRule="evenodd" />
                      </svg>
                    </div>
                    <div>
                      <h4 className="font-semibold text-gray-900 mb-2">Review Timeline</h4>
                      <ul className="text-sm text-gray-700 space-y-1">
                        <li>• Review period: 3 to 7 business days</li>
                        <li>• Our team verifies all documents and shop details</li>
                        <li>• You'll receive status updates via email</li>
                      </ul>
                    </div>
                  </div>
                </div>

                {/* Submit Button Section */}
                <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-5 md:p-8">
                  <div className="flex flex-col gap-4 lg:flex-row lg:justify-between lg:items-center">
                    <button
                      type="button"
                      onClick={handlePrev}
                      className="w-full lg:w-auto px-6 py-3 bg-gray-200 text-gray-700 font-semibold uppercase tracking-wider text-sm hover:bg-gray-300 transition-colors"
                    >
                      Previous
                    </button>
                    <div className="text-center">
                      <h3 className="text-lg font-semibold text-gray-900 mb-1">Ready to Submit?</h3>
                      <p className="text-sm text-gray-600">
                        Review all information before submitting your application for approval.
                      </p>
                      {!caviteLocationState.allowed && (
                        <p className="mt-2 text-sm font-medium text-red-600">
                          {caviteLocationState.message}
                        </p>
                      )}
                    </div>
                    <button
                      type="submit"
                      onClick={handleSubmit}
                      disabled={isSubmitting || !caviteLocationState.allowed}
                      className="w-full lg:w-auto px-6 py-3 bg-black text-white font-semibold uppercase tracking-wider text-sm hover:bg-black/80 transition-colors disabled:cursor-not-allowed disabled:opacity-60"
                    >
                      Submit Registration
                    </button>
                  </div>
                </div>
              </>
            )}
          </div>
        </div>
      </div>

      {/* Success Modal */}
      {showSuccessModal && (
        <>
          {/* Backdrop */}
          <div className="fixed inset-0 bg-black/70 backdrop-blur-sm z-50" />

          {/* Modal */}
          <div className="fixed inset-0 flex items-center justify-center z-50 p-4">
            <div className="bg-white rounded-2xl shadow-2xl max-w-lg w-full mx-4">
              {/* Header Section */}
              <div className="px-5 sm:px-8 pt-6 sm:pt-8 pb-5 sm:pb-6 text-center">
                <div className="flex justify-center mb-4">
                  <div className="w-16 h-16 bg-green-50 rounded-full flex items-center justify-center">
                    <svg className="w-8 h-8 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" />
                    </svg>
                  </div>
                </div>
                <h2 className="text-xl sm:text-2xl font-bold text-gray-900 mb-2">
                  Documents submitted successfully
                </h2>
                <p className="text-sm sm:text-base text-gray-600">
                  Your registration is now under review
                </p>
              </div>

              {/* Divider */}
              <div className="border-t border-gray-100"></div>

              {/* Content Section */}
              <div className="px-5 sm:px-8 py-5 sm:py-6 space-y-6">
                {/* Review Info Block */}
                <div className="bg-blue-50 rounded-lg p-4 border border-blue-100">
                  <div className="flex items-center space-x-2">
                    <svg className="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <span className="text-sm font-medium text-blue-900">Review time: 3–7 business days</span>
                  </div>
                </div>

                {/* What Happens Next */}
                <div>
                  <h3 className="text-lg font-semibold text-gray-900 mb-4">What happens next</h3>
                  <div className="space-y-3">
                    <div className="flex items-start space-x-3">
                      <div className="flex-shrink-0 w-6 h-6 bg-blue-100 rounded-full flex items-center justify-center">
                        <span className="text-xs font-semibold text-blue-600">1</span>
                      </div>
                      <p className="text-sm text-gray-700">Our team checks document clarity and completeness</p>
                    </div>
                    <div className="flex items-start space-x-3">
                      <div className="flex-shrink-0 w-6 h-6 bg-blue-100 rounded-full flex items-center justify-center">
                        <span className="text-xs font-semibold text-blue-600">2</span>
                      </div>
                      <p className="text-sm text-gray-700">We verify shop registration details</p>
                    </div>
                    <div className="flex items-start space-x-3">
                      <div className="flex-shrink-0 w-6 h-6 bg-blue-100 rounded-full flex items-center justify-center">
                        <span className="text-xs font-semibold text-blue-600">3</span>
                      </div>
                      <p className="text-sm text-gray-700">You receive a status update through your email</p>
                    </div>
                  </div>
                </div>

                {/* Submitted Documents */}
                <div className="bg-gray-50 rounded-lg p-4 border border-gray-200">
                  <h4 className="text-sm font-semibold text-gray-900 mb-3">Submitted Documents</h4>
                  <div className="space-y-2">
                    <div className="flex items-center space-x-2">
                      <svg className="w-4 h-4 text-green-600" fill="currentColor" viewBox="0 0 20 20">
                        <path fillRule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clipRule="evenodd" />
                      </svg>
                      <span className="text-sm text-gray-700">Shop Registration (DTI)</span>
                    </div>
                    <div className="flex items-center space-x-2">
                      <svg className="w-4 h-4 text-green-600" fill="currentColor" viewBox="0 0 20 20">
                        <path fillRule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clipRule="evenodd" />
                      </svg>
                      <span className="text-sm text-gray-700">Mayor's Permit / Shop Permit</span>
                    </div>
                    <div className="flex items-center space-x-2">
                      <svg className="w-4 h-4 text-green-600" fill="currentColor" viewBox="0 0 20 20">
                        <path fillRule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clipRule="evenodd" />
                      </svg>
                      <span className="text-sm text-gray-700">BIR Certificate of Registration</span>
                    </div>
                    <div className="flex items-center space-x-2">
                      <svg className="w-4 h-4 text-green-600" fill="currentColor" viewBox="0 0 20 20">
                        <path fillRule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clipRule="evenodd" />
                      </svg>
                      <span className="text-sm text-gray-700">Valid ID of Owner</span>
                    </div>
                  </div>
                </div>
              </div>

              {/* Footer */}
              <div className="border-t border-gray-100 px-5 sm:px-8 py-5 sm:py-6">
                <button
                  onClick={() => {
                    setShowSuccessModal(false);
                    router.visit(route('services'));
                  }}
                  className="w-full bg-blue-600 text-white font-semibold py-3 px-6 rounded-lg hover:bg-blue-700 transition-colors duration-200"
                >
                  Got it
                </button>
              </div>
            </div>
          </div>
        </>
      )}
    </>
  );
}
