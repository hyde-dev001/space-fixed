import { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import Swal from 'sweetalert2';
import Navigation from './Navigation';
import Label from '../../components/form/Label';
import Input from '../../components/form/input/InputField';
import DropzoneComponent from '../../components/form/form-elements/DropZone';
import { MailIcon, LockIcon, UserIcon } from '../../icons';

type FormErrors = Record<string, string>;

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

  const validateStep = (step: number): boolean => {
    const newErrors: FormErrors = {};

    if (step === 1) {
      if (!formData.firstName.trim()) newErrors.firstName = 'First name is required';
      if (!formData.lastName.trim()) newErrors.lastName = 'Last name is required';
      if (!formData.email.trim()) newErrors.email = 'Email is required';
      else if (!/\S+@\S+\.\S+/.test(formData.email)) newErrors.email = 'Email is invalid';
      if (!formData.phone.trim()) newErrors.phone = 'Phone number is required';
    } else if (step === 2) {
      if (!formData.age.trim()) newErrors.age = 'Age is required';
      else if (Number(formData.age) < 18) newErrors.age = 'You must be at least 18';
      if (!formData.address.trim()) newErrors.address = 'Address is required';
      if (!formData.password) newErrors.password = 'Password is required';
      else if (formData.password.length < 8) newErrors.password = 'Password must be at least 8 characters';
      else if (!/[A-Z]/.test(formData.password) || !/[a-z]/.test(formData.password) || !/[0-9]/.test(formData.password)) {
        newErrors.password = 'Include uppercase, lowercase, and a number';
      }
      if (!formData.confirmPassword) newErrors.confirmPassword = 'Confirm password is required';
      else if (formData.password !== formData.confirmPassword) newErrors.confirmPassword = 'Passwords do not match';
    } else if (step === 3) {
      if (!formData.validId) newErrors.validId = 'Valid ID upload is required';
      if (!formData.termsAccepted) newErrors.termsAccepted = 'You must accept the terms and conditions';
    }

    setErrors(newErrors);
    return Object.keys(newErrors).length === 0;
  };

  const validateForm = (): boolean => {
    const newErrors: FormErrors = {};

    if (!formData.firstName.trim()) newErrors.firstName = 'First name is required';
    if (!formData.lastName.trim()) newErrors.lastName = 'Last name is required';
    if (!formData.email.trim()) newErrors.email = 'Email is required';
    else if (!/\S+@\S+\.\S+/.test(formData.email)) newErrors.email = 'Email is invalid';
    if (!formData.phone.trim()) newErrors.phone = 'Phone number is required';
    if (!formData.age.trim()) newErrors.age = 'Age is required';
    else if (Number(formData.age) < 18) newErrors.age = 'You must be at least 18';
    if (!formData.address.trim()) newErrors.address = 'Address is required';
    if (!formData.password) newErrors.password = 'Password is required';
    else if (formData.password.length < 8) newErrors.password = 'Password must be at least 8 characters';
    else if (!/[A-Z]/.test(formData.password) || !/[a-z]/.test(formData.password) || !/[0-9]/.test(formData.password)) {
      newErrors.password = 'Include uppercase, lowercase, and a number';
    }
    if (!formData.confirmPassword) newErrors.confirmPassword = 'Confirm password is required';
    else if (formData.password !== formData.confirmPassword) newErrors.confirmPassword = 'Passwords do not match';
    if (!formData.validId) newErrors.validId = 'Valid ID upload is required';
    if (!formData.termsAccepted) newErrors.termsAccepted = 'You must accept the terms and conditions';

    setErrors(newErrors);
    return Object.keys(newErrors).length === 0;
  };

  const handleNext = () => {
    if (validateStep(currentStep)) {
      setCurrentStep(currentStep + 1);
    }
  };

  const handlePrev = () => {
    setCurrentStep(currentStep - 1);
  };

  const handleInputChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const { name, value } = e.target;
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
    console.log('Files dropped in Register:', acceptedFiles);
    if (acceptedFiles.length > 0) {
      const file = acceptedFiles[0];
      console.log('File name:', file.name);
      setFormData(prev => ({
        ...prev,
        validId: file
      }));
      
      console.log('About to show Swal');
      // Show success notification
      Swal.fire({
        icon: 'info',
        title: 'File Attached',
        html: '<p><strong>' + file.name + '</strong> was added to <strong>Valid ID</strong>.</p><p class="text-sm text-gray-600">Please ensure the correct document is uploaded.</p>',
        confirmButtonText: 'OK',
      }).then(() => {
        console.log('Swal closed');
      });
      
      // Clear error when file is uploaded
      if (errors.validId) {
        setErrors(prev => ({ ...prev, validId: undefined }));
      }
    }
  };

  const handleCheckboxChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const { name, checked } = e.target;
    setFormData(prev => ({
      ...prev,
      [name]: checked
    }));
    // Clear error when checkbox is checked
    if (errors[name]) {
      setErrors(prev => ({ ...prev, [name]: undefined }));
    }
  };

  const handleSubmit = async (e: React.FormEvent<HTMLFormElement>) => {
    e.preventDefault();
    if (!validateForm()) return;

    setIsLoading(true);
    try {
      const payload = new FormData();
      payload.append('first_name', formData.firstName);
      payload.append('last_name', formData.lastName);
      payload.append('email', formData.email);
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
            title: 'Registration successful!',
            text: 'You can now sign in with your credentials.',
            confirmButtonColor: '#000000',
            timer: 2000,
            showConfirmButton: true,
          }).then(() => {
            // Use Inertia router for navigation
            router.visit('/login');
          });
        },
        onError: (backendErrors) => {
          const mapped: Record<string, string> = {};
          Object.entries(backendErrors || {}).forEach(([key, val]) => {
            mapped[key] = Array.isArray(val) ? val[0] : String(val);
          });
          setErrors(mapped);
          Swal.fire({
            icon: 'error',
            title: 'Registration failed',
            text: 'Please review the form and try again.',
          });
        },
      });
    } catch (error) {
      console.error('Registration error:', error);
      Swal.fire({
        icon: 'error',
        title: 'Registration failed',
        text: 'Please try again.',
      });
    } finally {
      setIsLoading(false);
    }
  };

  return (
    <>
      <Head title="Register" />
      <div className="min-h-screen bg-white font-outfit antialiased">
        <Navigation />

      <div className="max-w-[1920px] mx-auto px-6 lg:px-12 py-24">
        <div className="text-center mb-12">
          <h1 className="text-4xl lg:text-6xl font-bold text-gray-900 mb-6 tracking-tight">
            REGISTER ACCOUNT
          </h1>
          <p className="text-xl text-gray-600 max-w-2xl mx-auto leading-relaxed font-light">
            Please fill in your details to create an account.
          </p>
        </div>

        <div className="max-w-lg mx-auto">
          <div className="bg-white rounded-2xl shadow-xl p-8">
            <form onSubmit={handleSubmit} className="space-y-6">
              {currentStep === 1 && (
                <>
                  <div className="relative">
                    <Label htmlFor="firstName">First Name</Label>
                    <div className="relative">
                      <UserIcon className="absolute left-3 top-1/2 transform -translate-y-1/2 w-5 h-5 text-gray-400" />
                      <Input
                        type="text"
                        id="firstName"
                        name="firstName"
                        placeholder="Enter your first name"
                        value={formData.firstName}
                        onChange={handleInputChange}
                        className={`pl-10 ${errors.firstName ? 'border-red-500' : ''}`}
                      />
                    </div>
                    {errors.firstName && <p className="mt-1 text-sm text-red-600">{errors.firstName}</p>}
                  </div>

                  <div className="relative">
                    <Label htmlFor="lastName">Last Name</Label>
                    <div className="relative">
                      <UserIcon className="absolute left-3 top-1/2 transform -translate-y-1/2 w-5 h-5 text-gray-400" />
                      <Input
                        type="text"
                        id="lastName"
                        name="lastName"
                        placeholder="Enter your last name"
                        value={formData.lastName}
                        onChange={handleInputChange}
                        className={`pl-10 ${errors.lastName ? 'border-red-500' : ''}`}
                      />
                    </div>
                    {errors.lastName && <p className="mt-1 text-sm text-red-600">{errors.lastName}</p>}
                  </div>

                  <div className="relative">
                    <Label htmlFor="email">Email</Label>
                    <div className="relative">
                      <MailIcon className="absolute left-3 top-1/2 transform -translate-y-1/2 w-5 h-5 text-gray-400" />
                      <Input
                        type="email"
                        id="email"
                        name="email"
                        placeholder="Enter your email address"
                        value={formData.email}
                        onChange={handleInputChange}
                        className={`pl-10 ${errors.email ? 'border-red-500' : ''}`}
                      />
                    </div>
                    {errors.email && <p className="mt-1 text-sm text-red-600">{errors.email}</p>}
                  </div>

                  <div className="relative">
                    <Label htmlFor="phone">Phone Number</Label>
                    <div className="relative">
                      <UserIcon className="absolute left-3 top-1/2 transform -translate-y-1/2 w-5 h-5 text-gray-400" />
                      <Input
                        type="tel"
                        id="phone"
                        name="phone"
                        placeholder="Enter your phone number"
                        value={formData.phone}
                        onChange={handleInputChange}
                        className={`pl-10 ${errors.phone ? 'border-red-500' : ''}`}
                      />
                    </div>
                    {errors.phone && <p className="mt-1 text-sm text-red-600">{errors.phone}</p>}
                  </div>
                </>
              )}

              {currentStep === 2 && (
                <>
                  <div className="relative">
                    <Label htmlFor="age">Age</Label>
                    <div className="relative">
                      <UserIcon className="absolute left-3 top-1/2 transform -translate-y-1/2 w-5 h-5 text-gray-400" />
                      <Input
                        type="number"
                        id="age"
                        name="age"
                        placeholder="Enter your age"
                        value={formData.age}
                        onChange={handleInputChange}
                        className={`pl-10 ${errors.age ? 'border-red-500' : ''}`}
                      />
                    </div>
                    {errors.age && <p className="mt-1 text-sm text-red-600">{errors.age}</p>}
                  </div>

                  <div className="relative">
                    <Label htmlFor="address">Address</Label>
                    <div className="relative">
                      <UserIcon className="absolute left-3 top-1/2 transform -translate-y-1/2 w-5 h-5 text-gray-400" />
                      <Input
                        type="text"
                        id="address"
                        name="address"
                        placeholder="Enter your address"
                        value={formData.address}
                        onChange={handleInputChange}
                        className={`pl-10 ${errors.address ? 'border-red-500' : ''}`}
                      />
                    </div>
                    {errors.address && <p className="mt-1 text-sm text-red-600">{errors.address}</p>}
                  </div>

                  <div className="relative">
                    <Label htmlFor="password">Password</Label>
                    <div className="relative">
                      <LockIcon className="absolute left-3 top-1/2 transform -translate-y-1/2 w-5 h-5 text-gray-400" />
                      <Input
                        type="password"
                        id="password"
                        name="password"
                        placeholder="Enter your password"
                        value={formData.password}
                        onChange={handleInputChange}
                        className={`pl-10 ${errors.password ? 'border-red-500' : ''}`}
                      />
                    </div>
                    {errors.password && <p className="mt-1 text-sm text-red-600">{errors.password}</p>}
                  </div>

                  <div className="relative">
                    <Label htmlFor="confirmPassword">Confirm Password</Label>
                    <div className="relative">
                      <LockIcon className="absolute left-3 top-1/2 transform -translate-y-1/2 w-5 h-5 text-gray-400" />
                      <Input
                        type="password"
                        id="confirmPassword"
                        name="confirmPassword"
                        placeholder="Confirm your password"
                        value={formData.confirmPassword}
                        onChange={handleInputChange}
                        className={`pl-10 ${errors.confirmPassword ? 'border-red-500' : ''}`}
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
                      isUploaded={!!formData.validId}
                      fileName={formData.validId?.name}
                    />
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
                      className="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded"
                    />
                    <label htmlFor="termsAccepted" className="ml-2 block text-sm text-gray-900">
                      Accept to the terms and conditions
                    </label>
                  </div>
                  {errors.termsAccepted && <p className="mt-1 text-sm text-red-600">{errors.termsAccepted}</p>}
                </>
              )}

              <div className="flex justify-between pt-4">
                {currentStep > 1 && (
                  <button
                    type="button"
                    onClick={handlePrev}
                    className="px-6 py-3 bg-gray-200 text-gray-700 font-semibold uppercase tracking-wider text-sm hover:bg-gray-300 transition-colors"
                  >
                    Previous
                  </button>
                )}
                {currentStep < 3 ? (
                  <button
                    type="button"
                    onClick={handleNext}
                    className="px-6 py-3 bg-black text-white font-semibold uppercase tracking-wider text-sm hover:bg-black/80 transition-colors ml-auto"
                  >
                    Next
                  </button>
                ) : (
                  <button
                    type="submit"
                    disabled={isLoading}
                    className="px-6 py-3 bg-black text-white font-semibold uppercase tracking-wider text-sm hover:bg-black/80 transition-colors disabled:opacity-50 disabled:cursor-not-allowed ml-auto"
                  >
                    {isLoading ? 'Creating Account...' : 'Register'}
                  </button>
                )}
              </div>
            </form>

            <div className="mt-6 text-center">
              <p className="text-gray-600">
                Already have an account?{' '}
                <a
                  href={route("login")}
                  className="text-black hover:text-black/80 font-semibold uppercase tracking-wider text-sm transition-colors"
                >
                  Sign in here
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
