import React from 'react';
import { Head } from '@inertiajs/react';

interface Props {
    email: string;
}

export default function InvitationAlreadyAccepted({ email }: Props) {
    return (
        <>
            <Head title="Invitation Already Accepted" />

            <div className="relative min-h-screen flex items-center justify-center overflow-hidden bg-white dark:bg-gray-900 px-4 py-10">
                <div className="pointer-events-none absolute inset-0">
                    <div className="absolute -top-28 -left-20 h-80 w-80 rounded-full bg-blue-100/70 blur-3xl dark:bg-blue-900/20" />
                    <div className="absolute -bottom-28 -right-20 h-96 w-96 rounded-full bg-indigo-100/70 blur-3xl dark:bg-indigo-900/20" />
                    <div className="absolute inset-0 bg-[radial-gradient(circle_at_top,rgba(15,23,42,0.04),transparent_60%)] dark:bg-[radial-gradient(circle_at_top,rgba(148,163,184,0.08),transparent_60%)]" />
                </div>

                <div className="relative max-w-md w-full rounded-3xl border border-gray-200/80 bg-white/95 dark:bg-gray-800/95 dark:border-gray-700 shadow-2xl backdrop-blur-xl p-8 text-center">
                    {/* Success Icon */}
                    <div className="w-16 h-16 bg-emerald-100 dark:bg-emerald-900/40 rounded-full flex items-center justify-center mx-auto mb-5 shadow-lg ring-1 ring-emerald-200/70 dark:ring-emerald-700/40">
                        <svg className="w-10 h-10 text-emerald-600 dark:text-emerald-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
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
                        Already Activated
                    </h1>

                    {/* Message */}
                    <p className="text-gray-600 dark:text-gray-400 mb-6">
                        This account has already been activated. You can log in using your password.
                    </p>

                    {/* Account Info */}
                    <div className="bg-gray-50 dark:bg-gray-700/60 rounded-xl p-4 mb-6 border border-gray-200 dark:border-gray-700">
                        <p className="text-sm text-gray-600 dark:text-gray-400 mb-1">Your account email:</p>
                        <p className="font-semibold text-gray-900 dark:text-white">{email}</p>
                    </div>

                    {/* Instructions */}
                    <div className="bg-gray-50 dark:bg-gray-700/50 border border-gray-200 dark:border-gray-600 rounded-xl p-4 mb-6 text-left">
                        <h3 className="font-semibold text-gray-900 dark:text-gray-100 mb-2 text-sm">
                            Need help?
                        </h3>
                        <ul className="text-sm text-gray-700 dark:text-gray-300 space-y-1.5">
                            <li>• If you forgot your password, use the "Forgot Password" link</li>
                            <li>• If you're having trouble logging in, contact your manager</li>
                            <li>• Make sure you're using the correct email address</li>
                        </ul>
                    </div>

                    {/* Action Buttons */}
                    <div className="space-y-3">
                        <a
                            href="/login"
                            className="block w-full bg-black hover:bg-gray-900 dark:bg-white dark:text-black dark:hover:bg-gray-200 text-white font-semibold py-3 rounded-lg transition-colors"
                        >
                            Go to Login
                        </a>
                        <a
                            href="/forgot-password"
                            className="block w-full bg-gray-100 hover:bg-gray-200 dark:bg-gray-700 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-300 font-semibold py-3 rounded-lg transition-colors border border-gray-200 dark:border-gray-600"
                        >
                            Forgot Password?
                        </a>
                    </div>
                </div>
            </div>
        </>
    );
}
