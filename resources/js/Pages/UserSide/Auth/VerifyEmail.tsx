import { useEffect, useState } from 'react';
import { Head, router } from '@inertiajs/react';
import { route } from 'ziggy-js';
import Navigation from '../Shared/Navigation';

interface Props {
  status?: 'verified' | 'invalid' | 'expired' | 'already-verified';
  message?: string;
}

export default function VerifyEmail({ status, message }: Props) {
  const [countdown, setCountdown] = useState(5);
  const [isRedirecting, setIsRedirecting] = useState(false);

  useEffect(() => {
    // Auto redirect to login after successful verification
    if (status === 'verified' && countdown > 0) {
      const timer = setTimeout(() => {
        setCountdown(countdown - 1);
      }, 1000);
      return () => clearTimeout(timer);
    } else if (status === 'verified' && countdown === 0) {
      setIsRedirecting(true);
      router.visit(route('login'));
    }
  }, [countdown, status]);

  const handleGoToLogin = () => {
    setIsRedirecting(true);
    router.visit(route('login'));
  };

  const handleResendEmail = () => {
    router.visit(route('verification.notice'));
  };

  const renderIcon = () => {
    switch (status) {
      case 'verified':
      case 'already-verified':
        return (
          <div className="w-20 h-20 bg-green-50 rounded-full flex items-center justify-center">
            <svg className="w-10 h-10 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
          </div>
        );
      case 'invalid':
      case 'expired':
        return (
          <div className="w-20 h-20 bg-red-50 rounded-full flex items-center justify-center">
            <svg className="w-10 h-10 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
          </div>
        );
      default:
        return (
          <div className="w-20 h-20 bg-gray-50 rounded-full flex items-center justify-center">
            <svg className="w-10 h-10 text-gray-600 animate-spin" fill="none" viewBox="0 0 24 24">
              <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"></circle>
              <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
          </div>
        );
    }
  };

  const renderContent = () => {
    switch (status) {
      case 'verified':
        return (
          <>
            <h1 className="text-3xl lg:text-4xl font-bold text-gray-900 mb-4 tracking-tight">
              Email Verified Successfully!
            </h1>
            <p className="text-lg text-gray-600 leading-relaxed mb-6">
              Your email address has been verified. You can now access all features of your account.
            </p>

            <div className="bg-green-50 border border-green-200 rounded-lg p-6 mb-6">
              <div className="flex items-start space-x-3">
                <svg className="w-5 h-5 text-green-600 flex-shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                  <path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clipRule="evenodd" />
                </svg>
                <div className="flex-1">
                  <h3 className="text-sm font-semibold text-green-900 mb-1">Account Activated</h3>
                  <p className="text-sm text-green-800">
                    You will be redirected to the login page in <span className="font-bold">{countdown}</span> seconds...
                  </p>
                </div>
              </div>
            </div>

            <button
              type="button"
              onClick={handleGoToLogin}
              disabled={isRedirecting}
              className="w-full px-6 py-3 bg-black text-white font-semibold uppercase tracking-wider text-sm hover:bg-black/80 transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
            >
              {isRedirecting ? (
                <span className="flex items-center justify-center space-x-2">
                  <svg className="animate-spin h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"></circle>
                    <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                  </svg>
                  <span>Redirecting...</span>
                </span>
              ) : (
                'Go to Login Now'
              )}
            </button>
          </>
        );

      case 'already-verified':
        return (
          <>
            <h1 className="text-3xl lg:text-4xl font-bold text-gray-900 mb-4 tracking-tight">
              Already Verified
            </h1>
            <p className="text-lg text-gray-600 leading-relaxed mb-6">
              Your email address is already verified. You can login to your account.
            </p>

            <div className="bg-blue-50 border border-blue-200 rounded-lg p-6 mb-6">
              <div className="flex items-start space-x-3">
                <svg className="w-5 h-5 text-blue-600 flex-shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                  <path fillRule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clipRule="evenodd" />
                </svg>
                <div className="flex-1">
                  <p className="text-sm text-blue-800">
                    This email has already been verified. Please proceed to login.
                  </p>
                </div>
              </div>
            </div>

            <button
              type="button"
              onClick={handleGoToLogin}
              className="w-full px-6 py-3 bg-black text-white font-semibold uppercase tracking-wider text-sm hover:bg-black/80 transition-colors"
            >
              Go to Login
            </button>
          </>
        );

      case 'expired':
        return (
          <>
            <h1 className="text-3xl lg:text-4xl font-bold text-gray-900 mb-4 tracking-tight">
              Verification Link Expired
            </h1>
            <p className="text-lg text-gray-600 leading-relaxed mb-6">
              This verification link has expired. Please request a new one.
            </p>

            <div className="bg-yellow-50 border border-yellow-200 rounded-lg p-6 mb-6">
              <div className="flex items-start space-x-3">
                <svg className="w-5 h-5 text-yellow-600 flex-shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                  <path fillRule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clipRule="evenodd" />
                </svg>
                <div className="flex-1">
                  <h3 className="text-sm font-semibold text-yellow-900 mb-1">Link No Longer Valid</h3>
                  <p className="text-sm text-yellow-800">
                    For security reasons, verification links expire after a certain time. Request a new link to continue.
                  </p>
                </div>
              </div>
            </div>

            <div className="space-y-3">
              <button
                type="button"
                onClick={handleResendEmail}
                className="w-full px-6 py-3 bg-black text-white font-semibold uppercase tracking-wider text-sm hover:bg-black/80 transition-colors"
              >
                Request New Verification Link
              </button>
              <button
                type="button"
                onClick={handleGoToLogin}
                className="w-full px-6 py-3 bg-gray-100 text-gray-700 font-semibold uppercase tracking-wider text-sm hover:bg-gray-200 transition-colors"
              >
                Go to Login
              </button>
            </div>
          </>
        );

      case 'invalid':
        return (
          <>
            <h1 className="text-3xl lg:text-4xl font-bold text-gray-900 mb-4 tracking-tight">
              Invalid Verification Link
            </h1>
            <p className="text-lg text-gray-600 leading-relaxed mb-6">
              {message || 'This verification link is invalid or has already been used.'}
            </p>

            <div className="bg-red-50 border border-red-200 rounded-lg p-6 mb-6">
              <div className="flex items-start space-x-3">
                <svg className="w-5 h-5 text-red-600 flex-shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                  <path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clipRule="evenodd" />
                </svg>
                <div className="flex-1">
                  <h3 className="text-sm font-semibold text-red-900 mb-1">Verification Failed</h3>
                  <p className="text-sm text-red-800">
                    The verification link may have been copied incorrectly or has already been used.
                  </p>
                </div>
              </div>
            </div>

            <div className="space-y-3">
              <button
                type="button"
                onClick={handleResendEmail}
                className="w-full px-6 py-3 bg-black text-white font-semibold uppercase tracking-wider text-sm hover:bg-black/80 transition-colors"
              >
                Request New Verification Link
              </button>
              <button
                type="button"
                onClick={handleGoToLogin}
                className="w-full px-6 py-3 bg-gray-100 text-gray-700 font-semibold uppercase tracking-wider text-sm hover:bg-gray-200 transition-colors"
              >
                Go to Login
              </button>
            </div>
          </>
        );

      default:
        return (
          <>
            <h1 className="text-3xl lg:text-4xl font-bold text-gray-900 mb-4 tracking-tight">
              Verifying Your Email...
            </h1>
            <p className="text-lg text-gray-600 leading-relaxed">
              Please wait while we verify your email address.
            </p>
          </>
        );
    }
  };

  return (
    <>
      <Head title="Email Verification" />
      <div className="min-h-screen bg-white font-outfit antialiased">
        <Navigation />

        <div className="max-w-[1920px] mx-auto px-6 lg:px-12 py-24">
          <div className="max-w-2xl mx-auto">
            {/* Main Card */}
            <div className="bg-white rounded-2xl shadow-xl p-8 border border-gray-100">
              {/* Icon */}
              <div className="flex justify-center mb-6">
                {renderIcon()}
              </div>

              {/* Content */}
              <div className="text-center">
                {renderContent()}
              </div>

              {/* Help Text */}
              <div className="mt-6 pt-6 border-t border-gray-200">
                <p className="text-xs text-gray-500 text-center">
                  Need help? Contact our support team at support@solespace.com
                </p>
              </div>
            </div>
          </div>
        </div>
      </div>
    </>
  );
}
