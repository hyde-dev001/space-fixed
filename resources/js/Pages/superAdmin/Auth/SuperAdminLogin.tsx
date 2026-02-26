import React, { useState } from 'react';
import { router, usePage, Link } from '@inertiajs/react';
import Swal from 'sweetalert2';
import { Head } from '@inertiajs/react';

interface FormData {
  email: string;
  password: string;
  remember: boolean;
}

interface PageProps {
  errors: {
    email?: string;
    password?: string;
  };
}

/**
 * SuperAdmin Login Component
 * 
 * Secure login page for super administrator accounts
 * Separate from regular user authentication
 * 
 * Features:
 * - Email and password authentication
 * - Remember me functionality
 * - Form validation
 * - Error handling with SweetAlert
 * - CSRF protection (automatic with Inertia)
 * 
 * Security:
 * - Password field is masked
 * - Form uses POST method
 * - CSRF token included automatically
 * - Rate limiting on backend
 */
export default function SuperAdminLogin() {
  const { errors } = usePage<PageProps>().props;
  const [formData, setFormData] = useState<FormData>({
    email: '',
    password: '',
    remember: false,
  });
  const [isSubmitting, setIsSubmitting] = useState(false);

  /**
   * Handle input changes
   * Updates form state as user types
   */
  const handleInputChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const { name, value, type, checked } = e.target;
    setFormData(prev => ({
      ...prev,
      [name]: type === 'checkbox' ? checked : value,
    }));
  };

  /**
   * Handle form submission
   * Validates and submits login credentials
   */
  const handleSubmit = (e: React.FormEvent<HTMLFormElement>) => {
    e.preventDefault();

    // Client-side validation
    if (!formData.email || !formData.password) {
      Swal.fire({
        icon: 'error',
        title: 'Missing Credentials',
        text: 'Please enter both email and password.',
      });
      return;
    }

    setIsSubmitting(true);

    // Submit login request via Inertia
    router.post('/admin/login', formData, {
      preserveScroll: true,
      onSuccess: () => {
        // Login successful - Inertia will redirect
        setIsSubmitting(false);
      },
      onError: (errors) => {
        // Login failed - Show error message
        setIsSubmitting(false);
        
        const errorMessage = errors.email || errors.password || 'Login failed. Please try again.';
        
        Swal.fire({
          icon: 'error',
          title: 'Login Failed',
          text: errorMessage,
        });
      },
    });
  };

  return (
    <>
      <Head title="Super Admin Login" />
      <div className="min-h-screen bg-gray-50 flex items-center justify-center px-4">
        {/* Login Card */}
        <div className="max-w-md w-full">
          {/* Header */}
          <div className="text-center mb-8">
            <div className="inline-block p-4 bg-blue-600 rounded-full mb-4">
              <svg
                className="w-12 h-12 text-white"
                fill="none"
                viewBox="0 0 24 24"
                stroke="currentColor"
              >
                <path
                  strokeLinecap="round"
                  strokeLinejoin="round"
                  strokeWidth={2}
                  d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"
                />
              </svg>
            </div>
            <h1 className="text-3xl font-bold text-black mb-2">
              Super Admin Login
            </h1>
            <p className="text-gray-600">
              Secure Access For Solespace Dashboard
            </p>
          </div>

          {/* Login Form */}
          <div className="bg-white rounded-2xl shadow-2xl p-8">
            <form onSubmit={handleSubmit} className="space-y-6">
              {/* Email Input */}
              <div>
                <label
                  htmlFor="email"
                  className="block text-sm font-semibold text-gray-700 mb-2"
                >
                  Email Address
                </label>
                <input
                  type="email"
                  id="email"
                  name="email"
                  value={formData.email}
                  onChange={handleInputChange}
                  className="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-colors"
                  placeholder="admin@thesis.com"
                  required
                />
                {errors?.email && (
                  <p className="mt-1 text-sm text-red-600">{errors.email}</p>
                )}
              </div>

              {/* Password Input */}
              <div>
                <label
                  htmlFor="password"
                  className="block text-sm font-semibold text-gray-700 mb-2"
                >
                  Password
                </label>
                <input
                  type="password"
                  id="password"
                  name="password"
                  value={formData.password}
                  onChange={handleInputChange}
                  className="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-colors"
                  placeholder="Enter your password"
                  required
                />
                {errors?.password && (
                  <p className="mt-1 text-sm text-red-600">{errors.password}</p>
                )}
              </div>

              {/* Remember Me Checkbox */}
              <div className="flex items-center">
                <input
                  type="checkbox"
                  id="remember"
                  name="remember"
                  checked={formData.remember}
                  onChange={handleInputChange}
                  className="w-4 h-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500"
                />
                <label
                  htmlFor="remember"
                  className="ml-2 block text-sm text-gray-700"
                >
                  Remember me for 30 days
                </label>
              </div>

              {/* Submit Button */}
              <button
                type="submit"
                disabled={isSubmitting}
                className={`w-full py-3 px-4 rounded-lg font-semibold text-white transition-all duration-200 ${
                  isSubmitting
                    ? 'bg-gray-400 cursor-not-allowed'
                    : 'bg-blue-600 hover:bg-blue-700 hover:shadow-lg transform hover:-translate-y-0.5'
                }`}
              >
                {isSubmitting ? (
                  <span className="flex items-center justify-center">
                    <svg
                      className="animate-spin -ml-1 mr-3 h-5 w-5 text-white"
                      xmlns="http://www.w3.org/2000/svg"
                      fill="none"
                      viewBox="0 0 24 24"
                    >
                      <circle
                        className="opacity-25"
                        cx="12"
                        cy="12"
                        r="10"
                        stroke="currentColor"
                        strokeWidth="4"
                      />
                      <path
                        className="opacity-75"
                        fill="currentColor"
                        d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"
                      />
                    </svg>
                    Logging in...
                  </span>
                ) : (
                  'Login to Admin Panel'
                )}
              </button>
            </form>

            {/* Security Notice */}
            <div className="mt-6 pt-6 border-t border-gray-200">
              <p className="text-xs text-center text-gray-500">
                🔒 This is a secure admin area. All login attempts are logged.
              </p>
            </div>
          </div>

         
          {/* Back to Home Link */}
          <div className="mt-6 text-center">
            <Link
              href="/"
              className="text-sm text-gray-600 hover:text-gray-900 transition-colors"
            >
              ← Back to homepage
            </Link>
          </div>
        </div>
      </div>
    </>
  );
}
