import React from 'react';
import { Head } from '@inertiajs/react';

interface Props {
    email: string;
    expired_at: string;
}

export default function InvitationExpired({ email, expired_at }: Props) {
    const expiredDate = new Date(expired_at).toLocaleString();

    return (
        <>
            <Head title="Invitation Expired" />
            
            <div className="min-h-screen flex items-center justify-center bg-gradient-to-br from-orange-50 to-red-100 dark:from-gray-900 dark:to-gray-800 px-4">
                <div className="max-w-md w-full bg-white dark:bg-gray-800 rounded-2xl shadow-2xl p-8 text-center">
                    {/* Warning Icon */}
                    <div className="w-16 h-16 bg-orange-100 dark:bg-orange-900/30 rounded-full flex items-center justify-center mx-auto mb-4">
                        <svg className="w-10 h-10 text-orange-600 dark:text-orange-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                    </div>

                    {/* Title */}
                    <h1 className="text-3xl font-bold text-gray-900 dark:text-white mb-4">
                        Invitation Expired
                    </h1>

                    {/* Message */}
                    <p className="text-gray-600 dark:text-gray-400 mb-2">
                        This invitation link expired on:
                    </p>
                    <p className="text-lg font-semibold text-gray-900 dark:text-white mb-6">
                        {expiredDate}
                    </p>

                    {/* Account Info */}
                    <div className="bg-blue-50 dark:bg-blue-900/20 rounded-lg p-4 mb-6">
                        <p className="text-sm text-gray-600 dark:text-gray-400 mb-1">Account email:</p>
                        <p className="font-semibold text-gray-900 dark:text-white">{email}</p>
                    </div>

                    {/* Instructions */}
                    <div className="bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-800 rounded-lg p-4 mb-6 text-left">
                        <h3 className="font-semibold text-yellow-900 dark:text-yellow-100 mb-2 text-sm">
                            To get a new invitation:
                        </h3>
                        <ul className="text-sm text-yellow-800 dark:text-yellow-200 space-y-1">
                            <li>• Contact your manager or HR department</li>
                            <li>• Request them to regenerate your invitation link</li>
                            <li>• They can do this from the employee management page</li>
                        </ul>
                    </div>

                    {/* Action Button */}
                    <a
                        href="/login"
                        className="inline-block w-full bg-blue-600 hover:bg-blue-700 text-white font-semibold py-3 rounded-lg transition-colors"
                    >
                        Go to Login Page
                    </a>
                </div>
            </div>
        </>
    );
}
