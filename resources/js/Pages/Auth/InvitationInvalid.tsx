import React from 'react';
import { Head } from '@inertiajs/react';

interface Props {
    error: string;
}

export default function InvitationInvalid({ error }: Props) {
    return (
        <>
            <Head title="Invalid Invitation" />

            <div className="relative min-h-screen flex items-center justify-center overflow-hidden bg-white dark:bg-gray-900 px-4 py-10">
                <div className="pointer-events-none absolute inset-0">
                    <div className="absolute -top-28 -left-20 h-80 w-80 rounded-full bg-blue-100/70 blur-3xl dark:bg-blue-900/20" />
                    <div className="absolute -bottom-28 -right-20 h-96 w-96 rounded-full bg-indigo-100/70 blur-3xl dark:bg-indigo-900/20" />
                    <div className="absolute inset-0 bg-[radial-gradient(circle_at_top,rgba(15,23,42,0.04),transparent_60%)] dark:bg-[radial-gradient(circle_at_top,rgba(148,163,184,0.08),transparent_60%)]" />
                </div>

                <div className="relative max-w-md w-full rounded-3xl border border-gray-200/80 bg-white/95 dark:bg-gray-800/95 dark:border-gray-700 shadow-2xl backdrop-blur-xl p-8 text-center">
                    {/* Error Icon */}
                    <div className="w-16 h-16 bg-red-100 dark:bg-red-900/40 rounded-full flex items-center justify-center mx-auto mb-5 shadow-lg ring-1 ring-red-200/70 dark:ring-red-700/40">
                        <svg className="w-10 h-10 text-red-600 dark:text-red-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                    </div>

                    <div className="mb-6">
                        <h2 className="text-4xl font-extrabold tracking-tight text-gray-900 dark:text-white leading-none">SoleSpace</h2>
                        <p className="mt-2 text-sm text-gray-500 dark:text-gray-400">Official invitation page</p>
                        <div className="mt-4 flex items-center gap-3">
                            <span className="h-px flex-1 bg-gray-200 dark:bg-gray-700" />
                            <span className="h-px flex-1 bg-gray-200 dark:bg-gray-700" />
                        </div>
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
                    <div className="bg-gray-50 dark:bg-gray-700/50 border border-gray-200 dark:border-gray-600 rounded-xl p-4 mb-6 text-left">
                        <h3 className="font-semibold text-gray-900 dark:text-gray-100 mb-2 text-sm">
                            What to do:
                        </h3>
                        <ul className="text-sm text-gray-700 dark:text-gray-300 space-y-1.5">
                            <li>• Check if you copied the complete link</li>
                            <li>• Request a new invitation from your manager</li>
                            <li>• Contact your shop owner for assistance</li>
                        </ul>
                    </div>

                    {/* Action Button */}
                    <a
                        href="/login"
                        className="inline-block w-full bg-black hover:bg-gray-900 dark:bg-white dark:text-black dark:hover:bg-gray-200 text-white font-semibold py-3 rounded-lg transition-colors"
                    >
                        Go to Login Page
                    </a>
                </div>
            </div>
        </>
    );
}
