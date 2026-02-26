import React from 'react';
import { Head } from '@inertiajs/react';

interface Props {
    error: string;
}

export default function InvitationInvalid({ error }: Props) {
    return (
        <>
            <Head title="Invalid Invitation" />
            
            <div className="min-h-screen flex items-center justify-center bg-gradient-to-br from-red-50 to-orange-100 dark:from-gray-900 dark:to-gray-800 px-4">
                <div className="max-w-md w-full bg-white dark:bg-gray-800 rounded-2xl shadow-2xl p-8 text-center">
                    {/* Error Icon */}
                    <div className="w-16 h-16 bg-red-100 dark:bg-red-900/30 rounded-full flex items-center justify-center mx-auto mb-4">
                        <svg className="w-10 h-10 text-red-600 dark:text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                    </div>

                    {/* Title */}
                    <h1 className="text-3xl font-bold text-gray-900 dark:text-white mb-4">
                        Invalid Invitation Link
                    </h1>

                    {/* Message */}
                    <p className="text-gray-600 dark:text-gray-400 mb-6">
                        {error || 'This invitation link is not valid or has been removed.'}
                    </p>

                    {/* Suggestions */}
                    <div className="bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-800 rounded-lg p-4 mb-6 text-left">
                        <h3 className="font-semibold text-yellow-900 dark:text-yellow-100 mb-2 text-sm">
                            What to do:
                        </h3>
                        <ul className="text-sm text-yellow-800 dark:text-yellow-200 space-y-1">
                            <li>• Check if you copied the complete link</li>
                            <li>• Request a new invitation from your manager</li>
                            <li>• Contact your shop owner for assistance</li>
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
