import React from 'react';
import { Head } from '@inertiajs/react';

interface Props {
    email: string;
}

export default function InvitationAlreadyAccepted({ email }: Props) {
    return (
        <>
            <Head title="Invitation Already Accepted" />
            
            <div className="min-h-screen flex items-center justify-center bg-gradient-to-br from-green-50 to-blue-100 dark:from-gray-900 dark:to-gray-800 px-4">
                <div className="max-w-md w-full bg-white dark:bg-gray-800 rounded-2xl shadow-2xl p-8 text-center">
                    {/* Success Icon */}
                    <div className="w-16 h-16 bg-green-100 dark:bg-green-900/30 rounded-full flex items-center justify-center mx-auto mb-4">
                        <svg className="w-10 h-10 text-green-600 dark:text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                    </div>

                    {/* Title */}
                    <h1 className="text-3xl font-bold text-gray-900 dark:text-white mb-4">
                        Already Activated
                    </h1>

                    {/* Message */}
                    <p className="text-gray-600 dark:text-gray-400 mb-6">
                        This account has already been activated. You can log in using your password.
                    </p>

                    {/* Account Info */}
                    <div className="bg-blue-50 dark:bg-blue-900/20 rounded-lg p-4 mb-6">
                        <p className="text-sm text-gray-600 dark:text-gray-400 mb-1">Your account email:</p>
                        <p className="font-semibold text-gray-900 dark:text-white">{email}</p>
                    </div>

                    {/* Instructions */}
                    <div className="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg p-4 mb-6 text-left">
                        <h3 className="font-semibold text-blue-900 dark:text-blue-100 mb-2 text-sm">
                            Need help?
                        </h3>
                        <ul className="text-sm text-blue-800 dark:text-blue-200 space-y-1">
                            <li>• If you forgot your password, use the "Forgot Password" link</li>
                            <li>• If you're having trouble logging in, contact your manager</li>
                            <li>• Make sure you're using the correct email address</li>
                        </ul>
                    </div>

                    {/* Action Buttons */}
                    <div className="space-y-3">
                        <a
                            href="/login"
                            className="block w-full bg-blue-600 hover:bg-blue-700 text-white font-semibold py-3 rounded-lg transition-colors"
                        >
                            Go to Login
                        </a>
                        <a
                            href="/forgot-password"
                            className="block w-full bg-gray-100 hover:bg-gray-200 dark:bg-gray-700 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-300 font-semibold py-3 rounded-lg transition-colors"
                        >
                            Forgot Password?
                        </a>
                    </div>
                </div>
            </div>
        </>
    );
}
