import { useState, useEffect } from 'react';
import { Head, router } from '@inertiajs/react';
import { route } from 'ziggy-js';
import Swal from '@/Pages/UserSide/Shared/UserModal';
import Navigation from '../Shared/Navigation';

interface Props {
  status?: string;
  email?: string;
}

export default function VerificationNotice({ status, email }: Props) {
  const [isResending, setIsResending] = useState(false);
  const [countdown, setCountdown] = useState(0);

  useEffect(() => {
    if (countdown > 0) {
      const timer = setTimeout(() => setCountdown(countdown - 1), 1000);
      return () => clearTimeout(timer);
    }
  }, [countdown]);

  const handleResendEmail = async () => {
    if (countdown > 0) return;

    setIsResending(true);

    router.post(route('verification.send'), {}, {
      preserveScroll: true,
      onSuccess: () => {
        setIsResending(false);
        setCountdown(60); // 60 second cooldown
        Swal.fire({
          icon: 'success',
          title: 'Email Sent!',
          text: 'Verification link has been sent to your email address.',
          confirmButtonColor: '#000000',
          timer: 3000,
        });
      },
      onError: () => {
        setIsResending(false);
        Swal.fire({
          icon: 'error',
          title: 'Failed to Send',
          text: 'Unable to send verification email. Please try again later.',
          confirmButtonColor: '#000000',
        });
      },
    });
  };

  const handleGoToLogin = () => {
    router.visit(route('login'));
  };

  return (
    <>
      <Head title="Verify Your Email" />
      <div className="min-h-screen bg-white font-outfit antialiased">
        <Navigation />

        <div className="max-w-[1920px] mx-auto px-6 lg:px-12 py-24">
          <div className="max-w-2xl mx-auto">
            {/* Main Card */}
            <div className="bg-white rounded-2xl shadow-xl p-8 border border-gray-100">
              {/* Icon */}
              <div className="flex justify-center mb-6">
                <div className="w-20 h-20 bg-blue-50 rounded-full flex items-center justify-center">
                  <svg className="w-10 h-10 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                  </svg>
                </div>
              </div>

              {/* Title & Description */}
              <div className="text-center mb-8">
                <h1 className="text-3xl lg:text-4xl font-bold text-gray-900 mb-4 tracking-tight">
                  Verify Your Email Address
                </h1>
                <p className="text-lg text-gray-600 leading-relaxed">
                  Thanks for signing up! Before getting started, please verify your email address.
                </p>
              </div>

              {/* Email Display */}
              {email && (
                <div className="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-6">
                  <div className="flex items-center space-x-3">
                    <svg className="w-5 h-5 text-blue-600 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                      <path d="M2.003 5.884L10 9.882l7.997-3.998A2 2 0 0016 4H4a2 2 0 00-1.997 1.884z" />
                      <path d="M18 8.118l-8 4-8-4V14a2 2 0 002 2h12a2 2 0 002-2V8.118z" />
                    </svg>
                    <div className="flex-1 min-w-0">
                      <p className="text-sm text-gray-600">Verification email sent to:</p>
                      <p className="text-base font-semibold text-blue-700 truncate">{email}</p>
                    </div>
                  </div>
                </div>
              )}

              {/* Status Message */}
              {status === 'verification-link-sent' && (
                <div className="bg-green-50 border border-green-200 rounded-lg p-4 mb-6">
                  <div className="flex items-start space-x-3">
                    <svg className="w-5 h-5 text-green-600 flex-shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                      <path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clipRule="evenodd" />
                    </svg>
                    <p className="text-sm text-green-800">
                      A new verification link has been sent to your email address.
                    </p>
                  </div>
                </div>
              )}

              {/* Instructions */}
              <div className="bg-gray-50 border border-gray-200 rounded-lg p-6 mb-6">
                <h3 className="text-lg font-semibold text-gray-900 mb-3">What to do next:</h3>
                <ol className="space-y-3 text-sm text-gray-700">
                  <li className="flex items-start space-x-3">
                    <span className="flex-shrink-0 w-6 h-6 bg-black text-white rounded-full flex items-center justify-center text-xs font-bold">1</span>
                    <span className="flex-1 pt-0.5">Check your email inbox (and spam folder, just in case)</span>
                  </li>
                  <li className="flex items-start space-x-3">
                    <span className="flex-shrink-0 w-6 h-6 bg-black text-white rounded-full flex items-center justify-center text-xs font-bold">2</span>
                    <span className="flex-1 pt-0.5">Click on the verification link we sent you</span>
                  </li>
                  <li className="flex items-start space-x-3">
                    <span className="flex-shrink-0 w-6 h-6 bg-black text-white rounded-full flex items-center justify-center text-xs font-bold">3</span>
                    <span className="flex-1 pt-0.5">You'll be redirected to login and start shopping!</span>
                  </li>
                </ol>
              </div>

              {/* Actions */}
              <div className="space-y-4">
                {/* Resend Button */}
                <button
                  type="button"
                  onClick={handleResendEmail}
                  disabled={isResending || countdown > 0}
                  className="w-full px-6 py-3 bg-black text-white font-semibold uppercase tracking-wider text-sm hover:bg-black/80 transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
                >
                  {isResending ? (
                    <span className="flex items-center justify-center space-x-2">
                      <svg className="animate-spin h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"></circle>
                        <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                      </svg>
                      <span>Sending...</span>
                    </span>
                  ) : countdown > 0 ? (
                    `Resend Email (${countdown}s)`
                  ) : (
                    'Resend Verification Email'
                  )}
                </button>

                {/* Back to Login */}
                <button
                  type="button"
                  onClick={handleGoToLogin}
                  className="w-full px-6 py-3 bg-gray-100 text-gray-700 font-semibold uppercase tracking-wider text-sm hover:bg-gray-200 transition-colors"
                >
                  Back to Login
                </button>
              </div>

              {/* Help Text */}
              <div className="mt-6 pt-6 border-t border-gray-200">
                <p className="text-sm text-gray-600 text-center">
                  Didn't receive the email?{' '}
                  <button
                    type="button"
                    onClick={handleResendEmail}
                    disabled={countdown > 0}
                    className="text-black font-semibold hover:text-black/80 transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
                  >
                    Click here to resend
                  </button>
                </p>
                <p className="text-xs text-gray-500 text-center mt-3">
                  Need help? Contact our support team at support@solespace.com
                </p>
              </div>
            </div>

            {/* Additional Info */}
            <div className="mt-6 text-center">
              <p className="text-sm text-gray-600">
                <svg className="inline-block w-4 h-4 mr-1 text-gray-400" fill="currentColor" viewBox="0 0 20 20">
                  <path fillRule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clipRule="evenodd" />
                </svg>
                This keeps your account secure and helps us prevent spam.
              </p>
            </div>
          </div>
        </div>
      </div>
    </>
  );
}
