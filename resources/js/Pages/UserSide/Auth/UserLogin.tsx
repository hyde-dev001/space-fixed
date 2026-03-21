import { useState, useEffect } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import Navigation from '../Shared/Navigation';
import Form from '../../../components/form/Form';
import Label from '../../../components/form/Label';
import Input from '../../../components/form/input/InputField';
import { MailIcon, LockIcon } from '../../../icons/index';
import Swal from '@/Pages/UserSide/Shared/UserModal';

interface FormErrors {
  email?: string;
  password?: string;
}

export default function UserLogin() {
  const { csrf_token } = usePage().props as any;
  const flash = (usePage().props as any).flash || {};

  const [formData, setFormData] = useState({
    email: '',
    password: '',
    rememberMe: false,
  });

  const [errors, setErrors] = useState<FormErrors>({});
  const [isLoading, setIsLoading] = useState(false);
  const authInputClasses = 'h-12 rounded-xl !border-gray-200 !bg-[#f8fafc] !text-[13px] !text-gray-800 placeholder:!text-gray-400 shadow-none focus:!border-gray-300 focus:!ring-gray-200/70 dark:!border-gray-200 dark:!bg-[#f8fafc] dark:!text-gray-800 dark:placeholder:!text-gray-400 dark:focus:!border-gray-300 dark:focus:!ring-gray-200/70';

  // Show flash success message (e.g. after accepting invitation)
  useEffect(() => {
    if (flash.success) {
      Swal.fire({
        icon: 'success',
        title: '🎉 Account Activated!',
        text: flash.success,
        confirmButtonColor: '#000000',
        confirmButtonText: 'Log In Now',
      });
    }
  }, [flash.success]);

  // Ensure CSRF token is set in headers for manual fetch if needed
  useEffect(() => {
    // Set CSRF token in meta tag if it's not already there
    let csrfMeta = document.querySelector('meta[name="csrf-token"]');
    if (!csrfMeta && csrf_token) {
      csrfMeta = document.createElement('meta');
      csrfMeta.setAttribute('name', 'csrf-token');
      csrfMeta.setAttribute('content', csrf_token);
      document.head.appendChild(csrfMeta);
    }
  }, [csrf_token]);

  const validateForm = (): boolean => {
    const newErrors: FormErrors = {};

    if (!formData.email.trim()) newErrors.email = 'Email is required';
    else if (!/\S+@\S+\.\S+/.test(formData.email)) newErrors.email = 'Email is invalid';
    if (!formData.password) newErrors.password = 'Password is required';

    setErrors(newErrors);
    return Object.keys(newErrors).length === 0;
  };

  const handleInputChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const { name, value, type, checked } = e.target;
    setFormData(prev => ({
      ...prev,
      [name]: type === 'checkbox' ? checked : value
    }));
    // Clear error when user starts typing
    if (errors[name as keyof FormErrors]) {
      setErrors(prev => ({ ...prev, [name]: undefined }));
    }
  };

  const handleSubmit = (e: React.FormEvent<HTMLFormElement>) => {
    e.preventDefault();
    if (!validateForm()) return;

    setIsLoading(true);

    router.post('/user/login', {
      email: formData.email,
      password: formData.password,
    }, {
      onSuccess: (page: any) => {
        Swal.fire({
          icon: 'success',
          title: 'Login Successful!',
          text: 'Welcome back!',
          confirmButtonColor: '#000000',
          timer: 1500,
          showConfirmButton: false,
        });
        // Let Inertia handle the redirect - it will preserve the session properly
        // The server already sends the correct redirect URL
      },
      onError: (errors) => {
        setIsLoading(false);
        setErrors(errors as FormErrors);
        
        Swal.fire({
          icon: 'error',
          title: 'Login Failed',
          text: errors.email || errors.password || 'Invalid credentials. Please try again.',
          confirmButtonColor: '#ef4444',
        });
      },
      onFinish: () => {
        setIsLoading(false);
      },
    });
  };

  return (
    <>
      <Head title="User Sign In" />
      <div className="min-h-screen bg-[radial-gradient(circle_at_top,#eef2f7_0%,#f7f9fc_45%,#ffffff_100%)] md:bg-white font-outfit antialiased">
        <Navigation />

      <div className="max-w-480 mx-auto px-4 sm:px-6 lg:px-12 pt-50 sm:pt-24 lg:pt-32 pb-16 sm:pb-24">
        <div className="text-center mb-10 sm:mb-10 lg:mb-12">
          <h1 className="text-[42px] leading-[1.02] sm:text-4xl lg:text-6xl font-bold text-gray-900 mb-4 sm:mb-5 tracking-tight">
            USER SIGN IN
          </h1>
          <p className="text-[18px] sm:text-lg lg:text-xl text-gray-600 max-w-[320px] sm:max-w-2xl mx-auto leading-snug font-light">
            Glad to see you again. Sign in to continue.
          </p>
        </div>

        <div className="max-w-92.5 sm:max-w-lg mx-auto mt-5 sm:mt-0">
          <div className="bg-white rounded-[20px] sm:rounded-2xl border border-gray-100 shadow-[0_14px_32px_-20px_rgba(15,23,42,0.35)] p-5 sm:p-8">
            <Form onSubmit={handleSubmit} className="space-y-5 sm:space-y-6">
              <div className="relative">
                <Label htmlFor="email" className="text-[12px] font-medium text-gray-700 mb-1.5">Email</Label>
                <div className="relative">
                  <MailIcon className="absolute left-3.5 top-1/2 transform -translate-y-1/2 w-4 h-4 text-gray-400" />
                  <Input
                    type="email"
                    id="email"
                    name="email"
                    placeholder="Enter your email"
                    value={formData.email}
                    onChange={handleInputChange}
                    className={`pl-10 ${authInputClasses} ${errors.email ? 'border-red-500' : ''}`}
                  />
                </div>
                {errors.email && <p className="mt-1 text-sm text-red-600">{errors.email}</p>}
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

              <div className="flex items-center justify-between gap-3">
                <div className="flex items-center">
                  <input
                    type="checkbox"
                    id="rememberMe"
                    name="rememberMe"
                    checked={formData.rememberMe}
                    onChange={handleInputChange}
                    className="h-4 w-4 text-black focus:ring-black/30 border-gray-300 rounded"
                  />
                  <label htmlFor="rememberMe" className="ml-2 block text-[12px] text-gray-700">
                    Remember me
                  </label>
                </div>
                <Link href={route('password.request')} className="text-[12px] text-[#0e2f60] hover:text-[#133c7b] transition-colors">
                  Forgot password?
                </Link>
              </div>

              <button
                type="submit"
                disabled={isLoading}
                className="w-full rounded-xl px-10 py-3.5 bg-black text-white font-semibold uppercase tracking-[0.2em] text-xs sm:text-sm hover:bg-black/85 transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
              >
                {isLoading ? 'Signing In...' : 'Sign In'}
              </button>
            </Form>

            <div className="mt-6 text-center space-y-2">
              <p className="text-[13px] text-gray-600">
                Don't have an account?{' '}
                <Link
                  href={route("register")}
                  className="text-black hover:text-black/80 font-semibold uppercase tracking-[0.15em] text-[12px] transition-colors"
                >
                  Register here
                </Link>
              </p>
            </div>
          </div>
        </div>
      </div>
      </div>
    </>
  );
}
