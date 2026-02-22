import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import Navigation from '../UserSide/Navigation';

interface VerifyEmailProps {
    email?: string;
    status?: string;
}

export default function VerifyEmail({ email, status }: VerifyEmailProps) {
    const [verificationStatus, setVerificationStatus] = useState<string | null>(status || null);
    const { post, processing } = useForm({});

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        post(route('verification.send'), {
            onSuccess: () => setVerificationStatus('verification-link-sent'),
        });
    };

    return (
        <>
            <Head title="Email Verification" />
            <div className="min-h-screen bg-gradient-to-b from-gray-50 to-white">
                <Navigation />
                
                <div className="max-w-md mx-auto px-4 py-24">
                    <div className="bg-white rounded-2xl shadow-xl p-8 border border-gray-200">
                        {/* Icon */}
                        <div className="flex justify-center mb-6">
                            <div className="w-20 h-20 bg-gradient-to-br from-blue-500 to-blue-600 rounded-full flex items-center justify-center shadow-lg">
                                <svg className="w-10 h-10 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                                </svg>
                            </div>
                        </div>

                        {/* Title */}
                        <h1 className="text-3xl font-bold text-gray-900 text-center mb-3">
                            Verify Your Email
                        </h1>
                        
                        {/* Description */}
                        <p className="text-gray-600 text-center mb-4">
                            Thanks for signing up! Before getting started, could you verify your email address by clicking on the link we just emailed to you?
                        </p>
                        
                        {email && (
                            <div className="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-6">
                                <p className="text-sm text-gray-600 text-center mb-1">
                                    Verification email sent to:
                                </p>
                                <p className="text-blue-600 font-semibold text-center break-all">
                                    {email}
                                </p>
                            </div>
                        )}

                        <p className="text-sm text-gray-600 text-center mb-8">
                            If you didn't receive the email, we'll gladly send you another.
                        </p>

                        {/* Success Message */}
                        {verificationStatus === 'verification-link-sent' && (
                            <div className="mb-6 bg-green-50 border border-green-200 rounded-lg p-4 animate-fade-in">
                                <div className="flex items-center">
                                    <svg className="w-5 h-5 text-green-600 mr-2" fill="currentColor" viewBox="0 0 20 20">
                                        <path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clipRule="evenodd" />
                                    </svg>
                                    <p className="text-sm text-green-800 font-medium">
                                        A new verification link has been sent to your email address!
                                    </p>
                                </div>
                            </div>
                        )}

                        {/* Resend Form */}
                        <form onSubmit={submit} className="space-y-4">
                            <button
                                type="submit"
                                disabled={processing}
                                className="w-full bg-gradient-to-r from-blue-600 to-blue-700 text-white font-semibold py-3 px-6 rounded-lg hover:from-blue-700 hover:to-blue-800 transition-all duration-200 disabled:opacity-50 disabled:cursor-not-allowed shadow-md hover:shadow-lg transform hover:-translate-y-0.5"
                            >
                                {processing ? (
                                    <span className="flex items-center justify-center">
                                        <svg className="animate-spin -ml-1 mr-3 h-5 w-5 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                            <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"></circle>
                                            <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                        </svg>
                                        Sending...
                                    </span>
                                ) : (
                                    <span className="flex items-center justify-center">
                                        <svg className="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                                        </svg>
                                        Resend Verification Email
                                    </span>
                                )}
                            </button>
                        </form>

                        {/* Divider */}
                        <div className="relative my-6">
                            <div className="absolute inset-0 flex items-center">
                                <div className="w-full border-t border-gray-200"></div>
                            </div>
                            <div className="relative flex justify-center text-sm">
                                <span className="px-2 bg-white text-gray-500">or</span>
                            </div>
                        </div>

                        {/* Back to Login */}
                        <Link
                            href="/login"
                            className="block text-center text-sm text-gray-600 hover:text-gray-900 transition-colors font-medium"
                        >
                            <span className="flex items-center justify-center">
                                <svg className="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                                </svg>
                                Back to Login
                            </span>
                        </Link>
                    </div>

                    {/* Help Text */}
                    <div className="mt-6 bg-gradient-to-r from-gray-50 to-blue-50 rounded-lg p-6 border border-gray-200">
                        <h3 className="font-semibold text-gray-900 mb-2 flex items-center">
                            <svg className="w-5 h-5 text-blue-600 mr-2" fill="currentColor" viewBox="0 0 20 20">
                                <path fillRule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clipRule="evenodd" />
                            </svg>
                            Didn't receive the email?
                        </h3>
                        <ul className="text-sm text-gray-600 space-y-1 ml-7">
                            <li>• Check your spam or junk folder</li>
                            <li>• Make sure you entered the correct email address</li>
                            <li>• Click the resend button above to get a new link</li>
                            <li>• The verification link expires in 60 minutes</li>
                        </ul>
                    </div>
                </div>
            </div>
        </>
    );
}
