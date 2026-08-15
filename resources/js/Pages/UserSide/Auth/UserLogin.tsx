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

type AuthContext = 'user' | 'shop_owner';

type LoginPageProps = {
  csrf_token?: string;
  flash?: { success?: string };
  initialAuthContext?: AuthContext;
};

const loginTargets = {
  user: '/user/login',
  shop_owner: '/shop-owner/login',
} as const;

const authContextOptions: Array<{ value: AuthContext; label: string }> = [
  { value: 'user', label: 'Customer / Staff' },
  { value: 'shop_owner', label: 'Shop Owner' },
];

export default function UserLogin() {
  const {
    csrf_token,
    flash = {},
    initialAuthContext,
  } = usePage<LoginPageProps>().props;
  const [authContext, setAuthContext] = useState<AuthContext>(
    initialAuthContext === 'shop_owner' ? 'shop_owner' : 'user',
  );

  const [formData, setFormData] = useState({
    email: '',
    password: '',
    rememberMe: false,
  });

  const [errors, setErrors] = useState<FormErrors>({});
  const [isLoading, setIsLoading] = useState(false);
  const [showPassword, setShowPassword] = useState(false);
  const authInputClasses = 'userside-auth-input h-12 rounded-xl !border-gray-200 !bg-[#f8fafc] !text-[13px] !text-gray-800 placeholder:!text-gray-400 shadow-none focus:!border-gray-300 focus:!ring-gray-200/70';

  // Show flash success message (e.g. after accepting invitation)
  useEffect(() => {
    if (flash.success) {
      Swal.fire({
        icon: 'success',
        title: 'Account Activated',
        text: 'Your account is ready to use. Sign in now to get started.',
        confirmButtonText: 'Sign in now',
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
    const normalizedEmail = formData.email.trim();

    if (!normalizedEmail) newErrors.email = 'Enter your email address.';
    else if (!/\S+@\S+\.\S+/.test(normalizedEmail)) newErrors.email = 'Enter a valid email address.';
    if (!formData.password) newErrors.password = 'Enter your password.';

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

    router.post(loginTargets[authContext], {
      email: formData.email.trim(),
      password: formData.password,
      remember: formData.rememberMe,
    }, {
      onSuccess: (page: any) => {
        const redirectUrl = String(page?.url || '');
        const isTwoFactorChallenge = redirectUrl.includes('/shop-owner/two-factor');

        if (isTwoFactorChallenge) {
          return;
        }

        Swal.fire({
          icon: 'success',
          title: 'Signed In',
          text: 'Welcome back to SoleSpace!',
          showConfirmButton: false,
          timer: 1800,
          timerProgressBar: true,
        });
        // Let Inertia handle the redirect - it will preserve the session properly
        // The server already sends the correct redirect URL
      },
      onError: (errors) => {
        setIsLoading(false);
        setErrors(errors as FormErrors);
        
        Swal.fire({
          icon: 'error',
          title: 'Sign-in Failed',
          text: errors.email || errors.password || 'Email or password is incorrect. Please try again.',
          iconColor: '#e36a5d',
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
      <style>{`
        input[type="password"]::-webkit-reveal-password-button,
        input[type="password"]::-webkit-credentials-auto-fill-button {
          display: none;
        }
        input[type="password"]::-ms-reveal {
          display: none;
        }
      `}</style>
      <div className="userside-auth-page min-h-screen bg-[radial-gradient(circle_at_top,#eef2f7_0%,#f7f9fc_45%,#ffffff_100%)] md:bg-white font-outfit antialiased">
        <Navigation />

      <div className="max-w-480 mx-auto px-4 sm:px-6 lg:px-12 pt-50 sm:pt-24 lg:pt-32 pb-16 sm:pb-24">
        <div className="text-center mb-10 sm:mb-10 lg:mb-12">
          <h1 className="userside-auth-title text-[42px] leading-[1.02] sm:text-4xl lg:text-6xl font-bold text-gray-900 mb-4 sm:mb-5 tracking-tight">
            SIGN IN
          </h1>
          <p className="userside-auth-subtitle text-[18px] sm:text-lg lg:text-xl text-gray-600 max-w-[320px] sm:max-w-2xl mx-auto leading-snug font-light">
            Glad to see you again. Sign in to continue.
          </p>
        </div>

        <div className="max-w-92.5 sm:max-w-lg mx-auto mt-5 sm:mt-0">
          <div className="userside-auth-card bg-white rounded-[20px] sm:rounded-2xl border border-gray-100 shadow-[0_14px_32px_-20px_rgba(15,23,42,0.35)] p-5 sm:p-8">
            <div role="tablist" aria-label="Sign-in account type" className="mb-6 grid grid-cols-2 gap-1 rounded-xl bg-gray-100 p-1">
              {authContextOptions.map(({ value, label }) => (
                <button
                  key={value}
                  type="button"
                  role="tab"
                  aria-selected={authContext === value}
                  onClick={() => setAuthContext(value)}
                  className={`min-h-11 rounded-lg px-3 py-2 text-xs font-semibold transition-colors sm:text-sm ${
                    authContext === value
                      ? 'bg-white text-gray-900 shadow-sm'
                      : 'text-gray-500 hover:text-gray-900'
                  }`}
                >
                  {label}
                </button>
              ))}
            </div>
            <Form onSubmit={handleSubmit} className="space-y-5 sm:space-y-6">
              <div className="relative">
                <Label htmlFor="email" className="text-[12px] font-medium text-gray-700 mb-1.5">Email</Label>
                <div className="relative">
                  <MailIcon className="absolute left-3.5 top-1/2 transform -translate-y-1/2 w-4 h-4 text-gray-400 pointer-events-none" />
                  <input
                    type="email"
                    id="email"
                    name="email"
                    placeholder="Enter your email"
                    value={formData.email}
                    onChange={handleInputChange}
                    className={`w-full pl-10 ${authInputClasses} ${errors.email ? 'border-red-500' : ''}`}
                  />
                </div>
                {errors.email && <p className="mt-1 text-sm text-red-600">{errors.email}</p>}
              </div>

              <div className="relative">
                <Label htmlFor="password" className="text-[12px] font-medium text-gray-700 mb-1.5">Password</Label>
                <div className="relative">
                  <LockIcon className="absolute left-3.5 top-1/2 transform -translate-y-1/2 w-4 h-4 text-gray-400 pointer-events-none" />
                  <input
                    type={showPassword ? 'text' : 'password'}
                    id="password"
                    name="password"
                    placeholder="Enter your password"
                    value={formData.password}
                    onChange={handleInputChange}
                    className={`w-full pl-10 pr-11 ${authInputClasses} ${errors.password ? 'border-red-500' : ''}`}
                    autoComplete="current-password"
                  />
                  <button
                    type="button"
                    onClick={() => setShowPassword((prev) => !prev)}
                    className="absolute right-3.5 top-1/2 -translate-y-1/2 text-gray-400 transition-colors hover:text-gray-600 p-1"
                    aria-label={showPassword ? 'Hide password' : 'Show password'}
                  >
                    {showPassword ? (
                      <svg className="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                        <path d="M17.94 17.94A10.94 10.94 0 0112 20C7 20 2.73 16.89 1 12c.92-2.6 2.68-4.8 5-6.32" />
                        <path d="M10.58 10.58A3 3 0 0012 15a3 3 0 002.42-4.42" />
                        <path d="M1 1l22 22" />
                        <path d="M9.88 4.24A10.94 10.94 0 0112 4c5 0 9.27 3.11 11 8a10.94 10.94 0 01-4.19 5.19" />
                      </svg>
                    ) : (
                      <svg className="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                        <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8S1 12 1 12z" />
                        <circle cx="12" cy="12" r="3" />
                      </svg>
                    )}
                  </button>
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
                <Link href={route('password.request')} className="userside-auth-link text-[12px] text-[#0e2f60] hover:text-[#133c7b] transition-colors">
                  Forgot password?
                </Link>
              </div>

              <button
                type="submit"
                disabled={isLoading}
                className="userside-auth-primary w-full rounded-xl px-10 py-3.5 bg-black text-white font-semibold uppercase tracking-[0.2em] text-xs sm:text-sm hover:bg-black/85 transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
              >
                {isLoading ? 'Signing in...' : 'Sign in'}
              </button>
            </Form>

            <div className="mt-6 text-center space-y-2">
              <p className="text-[13px] text-gray-600">
                Don't have an account?{' '}
                <Link
                  href={route("register")}
                  className="userside-auth-link text-black hover:text-black/80 font-semibold uppercase tracking-[0.15em] text-[12px] transition-colors"
                >
                  Create account
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
