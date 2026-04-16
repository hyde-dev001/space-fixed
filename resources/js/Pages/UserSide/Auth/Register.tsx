import { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import Swal from '@/Pages/UserSide/Shared/UserModal';
import Navigation from '../Shared/Navigation';
import Label from '../../../components/form/Label';
import Input from '../../../components/form/input/InputField';
import DropzoneComponent from '../../../components/form/form-elements/DropZone';
import { MailIcon, LockIcon, UserIcon } from '../../../icons';

type FormErrors = Record<string, string>;

const EMAIL_REGEX = /^\S+@\S+\.\S+$/;
const PHONE_REGEX = /^\d{11}$/;
const MAX_VALID_ID_SIZE_BYTES = 5 * 1024 * 1024;
const CUSTOMER_VALID_ID_ACCEPT = {
  'image/jpeg': ['.jpg', '.jpeg'],
  'image/png': ['.png'],
};
const CUSTOMER_VALID_ID_ALLOWED_EXTENSIONS = new Set(['jpg', 'jpeg', 'png']);
const CUSTOMER_VALID_ID_ALLOWED_MIME_TYPES = new Set(['image/jpeg', 'image/png']);

const escapeHtml = (value: string) => (
  value
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;')
);

export default function Register() {
  const [currentStep, setCurrentStep] = useState(1);
  const [formData, setFormData] = useState({
    firstName: '',
    lastName: '',
    email: '',
    phone: '',
    age: '',
    address: '',
    password: '',
    confirmPassword: '',
    validId: null as File | null,
    termsAccepted: false,
  });

  const [errors, setErrors] = useState<Record<string, string | undefined>>({});
  const [isLoading, setIsLoading] = useState(false);
  const authInputClasses = 'h-12 rounded-xl !border-gray-200 !bg-[#f8fafc] !text-[13px] !text-gray-800 placeholder:!text-gray-400 shadow-none focus:!border-gray-300 focus:!ring-gray-200/70 dark:!border-gray-200 dark:!bg-[#f8fafc] dark:!text-gray-800 dark:placeholder:!text-gray-400 dark:focus:!border-gray-300 dark:focus:!ring-gray-200/70';

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

      if (!formData.password) {
        newErrors.password = 'Please enter a password.';
      } else if (formData.password.length < 8) {
        newErrors.password = 'Password must be at least 8 characters.';
      } else if (!/[A-Z]/.test(formData.password) || !/[a-z]/.test(formData.password) || !/[0-9]/.test(formData.password)) {
        newErrors.password = 'Password must include uppercase, lowercase, and at least one number.';
      }

      if (!formData.confirmPassword) {
        newErrors.confirmPassword = 'Please confirm your password.';
      } else if (formData.password !== formData.confirmPassword) {
        newErrors.confirmPassword = 'Passwords do not match.';
      }
    }

    if (step === 3) {
      if (!formData.validId) {
        newErrors.validId = 'Please upload a valid government-issued ID (JPG, JPEG, or PNG up to 5MB).';
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

    if (validationErrors.age || validationErrors.address || validationErrors.password || validationErrors.confirmPassword) {
      return 2;
    }

    if (validationErrors.validId || validationErrors.termsAccepted) {
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

  const handleNext = async () => {
    const stepErrors = getStepValidationErrors(currentStep);
    if (Object.keys(stepErrors).length > 0) {
      setErrors(prev => ({ ...prev, ...stepErrors }));
      showValidationModal('Please fix the highlighted fields', stepErrors);
      return;
    }

    if (currentStep === 1) {
      const trimmedEmail = formData.email.trim();
      const result = await checkEmailAvailability(trimmedEmail);

      if (!result.available) {
        const message = result.message || 'This email is already registered';
        const emailError: FormErrors = { email: message };
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

  const handleFileDrop = (acceptedFiles: File[]) => {
    if (acceptedFiles.length > 0) {
      const file = acceptedFiles[0];

      const extension = file.name.split('.').pop()?.toLowerCase() ?? '';
      const hasAllowedExtension = CUSTOMER_VALID_ID_ALLOWED_EXTENSIONS.has(extension);
      const mimeType = String(file.type || '').toLowerCase();
      const hasAllowedMimeType = mimeType === '' || CUSTOMER_VALID_ID_ALLOWED_MIME_TYPES.has(mimeType);

      if (!hasAllowedExtension || !hasAllowedMimeType) {
        const typeError = 'Valid ID must be JPG, JPEG, or PNG only.';
        setErrors(prev => ({ ...prev, validId: typeError }));
        Swal.fire({
          icon: 'error',
          title: 'Invalid file type',
          html: `<p><strong>${escapeHtml(file.name)}</strong> is not allowed.</p><p class="text-sm text-gray-600">Only JPG, JPEG, and PNG image files are accepted for Valid ID.</p>`,
          confirmButtonColor: '#000000',
        });
        return;
      }

      if (file.size > MAX_VALID_ID_SIZE_BYTES) {
        const sizeError = 'Valid ID must be 5MB or smaller (JPG, JPEG, or PNG).';
        setErrors(prev => ({ ...prev, validId: sizeError }));
        Swal.fire({
          icon: 'error',
          title: 'File too large',
          text: sizeError,
          confirmButtonColor: '#000000',
        });
        return;
      }

      setFormData(prev => ({
        ...prev,
        validId: file
      }));
      
      // Show success notification
      Swal.fire({
        icon: 'info',
        title: 'File Attached',
        html: '<p><strong>' + file.name + '</strong> was added to <strong>Valid ID</strong>.</p><p class="text-sm text-gray-600">Please ensure the correct document is uploaded.</p>',
        confirmButtonText: 'OK',
      }).then(() => {
      });
      
      // Clear error when file is uploaded
      if (errors.validId) {
        setErrors(prev => ({ ...prev, validId: undefined }));
      }
    }
  };

  const handleCheckboxChange = async (e: React.ChangeEvent<HTMLInputElement>) => {
    const { name, checked } = e.target;
    if (name === 'termsAccepted' && checked) {
      const result = await Swal.fire({
        title: 'TERMS OF SERVICE',
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
                By continuing registration, you confirm that you have read, understood, and agreed to these Terms of Service and our account verification requirements.
              </p>

              <h3>2. Information We Request</h3>
              <p>
                We ask for your basic personal details and a valid government-issued ID to verify identity, protect legitimate users, and maintain a secure marketplace.
              </p>

              <h3>3. Security and Anti-Fraud Policy</h3>
              <p>
                Verification helps us detect and prevent scam accounts, impersonation, and unauthorized activity. Accounts with suspicious or false documents may be restricted or removed.
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
                Choosing <strong>Accept</strong> means you agree to these terms and consent to identity verification as part of account security.
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
      payload.append('password', formData.password);
      payload.append('password_confirmation', formData.confirmPassword);
      if (formData.validId) payload.append('valid_id', formData.validId);

      await router.post('/user/register', payload, {
        forceFormData: true,
        preserveScroll: false,
        onSuccess: () => {
          Swal.fire({
            icon: 'success',
            title: 'Registration successful',
            html: `
              <p>Thank you for registering!</p>
              <p class="mt-3">We've sent a verification email to:</p>
              <p class="font-semibold text-blue-600 mt-2">${formData.email}</p>
              <p class="text-sm text-gray-600 mt-3">Check your inbox and click the verification link to finish setup.</p>
            `,
            confirmButtonText: 'OK',
            confirmButtonColor: '#000000',
          });
          // Router will automatically handle redirect to verification.notice
        },
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

  const handleSubmit = (e: React.FormEvent<HTMLFormElement>) => {
    e.preventDefault();
    // Prevent any accidental form submission via Enter key
    return false;
  };

  return (
    <>
      <Head title="Register" />
      <div className="min-h-screen bg-[radial-gradient(circle_at_top,#eef2f7_0%,#f7f9fc_45%,#ffffff_100%)] md:bg-white font-outfit antialiased">
        <Navigation />

      <div className="max-w-480 mx-auto px-4 sm:px-6 lg:px-12 pt-16 sm:pt-20 lg:pt-28 pb-16 sm:pb-24">
        <div className="text-center mt-20 sm:mt-0 mb-7 sm:mb-10 lg:mb-12">
          <h1 className="text-[34px] leading-[1.05] sm:text-4xl lg:text-6xl font-bold text-gray-900 mb-3 sm:mb-5 tracking-tight">
            CREATE ACCOUNT
          </h1>
          <p className="text-[15px] sm:text-lg lg:text-xl text-gray-600 max-w-sm sm:max-w-2xl mx-auto leading-relaxed font-light">
            Please fill in your details to create an account.
          </p>
        </div>

        <div className="max-w-92.5 sm:max-w-lg mx-auto">
          <div className="bg-white rounded-[20px] sm:rounded-2xl border border-gray-100 shadow-[0_14px_32px_-20px_rgba(15,23,42,0.35)] p-5 sm:p-8">
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
                  </div>

                  <div className="relative">
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
                        className={`pl-10 ${authInputClasses} ${errors.password ? 'border-red-500' : ''}`}
                      />
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
                    <Label htmlFor="validId">Valid ID {formData.validId && <span className="text-green-600 font-bold ml-2">✓ Uploaded</span>}</Label>
                    <DropzoneComponent
                      onDrop={handleFileDrop}
                      accept={CUSTOMER_VALID_ID_ACCEPT}
                      onInvalidFiles={(invalidFiles) => {
                        if (invalidFiles.length > 0) {
                          const typeError = 'Valid ID must be JPG, JPEG, or PNG only.';
                          setErrors(prev => ({ ...prev, validId: typeError }));
                          Swal.fire({
                            icon: 'error',
                            title: 'Invalid file type',
                            html: `<p><strong>${escapeHtml(invalidFiles[0].name)}</strong> is not allowed.</p><p class="text-sm text-gray-600">Only JPG, JPEG, and PNG image files are accepted for Valid ID.</p>`,
                            confirmButtonColor: '#000000',
                          });
                        }
                      }}
                      isUploaded={!!formData.validId}
                      fileName={formData.validId?.name}
                    />
                    <div className="mt-3 rounded-xl border border-gray-200 bg-gray-50 px-3 py-2.5">
                      <p className="text-[12px] font-semibold uppercase tracking-[0.08em] text-gray-700">Why we ask for a valid ID</p>
                      <p className="mt-1 text-[12px] leading-5 text-gray-600">
                        We use one government-issued ID to confirm that each account belongs to a real person and to help prevent scam or fake accounts.
                      </p>
                    </div>
                    {formData.validId && (
                      <p className="mt-2 text-sm text-green-600 font-semibold flex items-center">
                        <svg className="w-4 h-4 mr-1" fill="currentColor" viewBox="0 0 20 20">
                          <path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clipRule="evenodd" />
                        </svg>
                        File uploaded: {formData.validId.name}
                      </p>
                    )}
                    {errors.validId && <p className="mt-1 text-sm text-red-600">{errors.validId}</p>}
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
                    className="w-full sm:w-auto rounded-xl px-6 py-3 bg-gray-100 text-gray-700 font-semibold uppercase tracking-[0.16em] text-xs sm:text-sm hover:bg-gray-200 transition-colors"
                  >
                    Previous
                  </button>
                )}
                {currentStep < 3 ? (
                  <button
                    type="button"
                    onClick={handleNext}
                    className="w-full sm:w-auto rounded-xl px-6 py-3 bg-black text-white font-semibold uppercase tracking-[0.16em] text-xs sm:text-sm hover:bg-black/85 transition-colors sm:ml-auto"
                  >
                    Next
                  </button>
                ) : (
                  <button
                    type="button"
                    onClick={handleRegisterClick}
                    disabled={isLoading}
                    className="w-full sm:w-auto rounded-xl px-6 py-3 bg-black text-white font-semibold uppercase tracking-[0.16em] text-xs sm:text-sm hover:bg-black/85 transition-colors disabled:opacity-50 disabled:cursor-not-allowed sm:ml-auto"
                  >
                    {isLoading ? 'Creating account...' : 'Create account'}
                  </button>
                )}
              </div>
            </form>

            <div className="mt-6 text-center">
              <p className="text-[13px] text-gray-600">
                Already have an account?{' '}
                <a
                  href={route("login")}
                  className="text-black hover:text-black/80 font-semibold uppercase tracking-[0.15em] text-[12px] transition-colors"
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
