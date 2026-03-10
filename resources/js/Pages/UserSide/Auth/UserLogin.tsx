import { useState, useEffect } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import Navigation from '../Shared/Navigation';
import Form from '../../../components/form/Form';
import Label from '../../../components/form/Label';
import Input from '../../../components/form/input/InputField';
import { MailIcon, LockIcon } from '../../../icons/index';
import Swal from 'sweetalert2';

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
      <div className="min-h-screen bg-white font-outfit antialiased">
        <Navigation />

      <div className="max-w-[1920px] mx-auto px-6 lg:px-12 py-24">
        <div className="text-center mb-12">
          <h1 className="text-4xl lg:text-6xl font-bold text-gray-900 mb-6 tracking-tight">
            USER SIGN IN
          </h1>
          <p className="text-xl text-gray-600 max-w-2xl mx-auto leading-relaxed font-light">
            Glad to see you again. Sign in to continue.
          </p>
        </div>

        <div className="max-w-lg mx-auto">
          <div className="bg-white rounded-2xl shadow-xl p-8">
            <Form onSubmit={handleSubmit} className="space-y-6">
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

              <div className="flex items-center justify-between">
                <div className="flex items-center">
                  <input
                    type="checkbox"
                    id="rememberMe"
                    name="rememberMe"
                    checked={formData.rememberMe}
                    onChange={handleInputChange}
                    className="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded"
                  />
                  <label htmlFor="rememberMe" className="ml-2 block text-sm text-gray-900">
                    Remember me
                  </label>
                </div>
                <Link href={route('password.request')} className="text-sm text-blue-600 hover:text-blue-500">
                  Forgot password?
                </Link>
              </div>

              <button
                type="submit"
                disabled={isLoading}
                className="w-full px-10 py-4 bg-black text-white font-semibold uppercase tracking-wider text-sm hover:bg-black/80 transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
              >
                {isLoading ? 'Signing In...' : 'Sign In'}
              </button>
            </Form>

            <div className="mt-6 text-center space-y-2">
              <p className="text-gray-600">
                Don't have an account?{' '}
                <Link
                  href={route("register")}
                  className="text-black hover:text-black/80 font-semibold uppercase tracking-wider text-sm transition-colors"
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
