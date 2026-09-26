import React from 'react';
import { Head } from '@inertiajs/react';

interface Props {
    email: string;
}

export default function InvitationAlreadyAccepted({ email }: Props) {
    return (
        <>
            <Head title="Invitation Already Accepted" />

            <div className="userside-auth-page userside-auth-pattern relative flex min-h-screen items-center justify-center px-4 py-6 font-outfit antialiased sm:py-8">

                <div className="relative w-full max-w-xl rounded-3xl border border-gray-200/80 bg-white/95 p-6 text-center shadow-2xl backdrop-blur-xl dark:border-gray-700 dark:bg-gray-800/95 sm:p-7">

                    <div className="mb-4">
                        <h2 className="text-4xl font-extrabold tracking-tight text-gray-900 dark:text-white leading-none">SoleSpace</h2>
                        <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">Official invitation page</p>
                        <div className="mt-3 flex items-center gap-3">
                            <span className="h-px flex-1 bg-gray-200 dark:bg-gray-700" />
                            <span className="h-px flex-1 bg-gray-200 dark:bg-gray-700" />
                        </div>
                    </div>

                    {/* Title */}
                    <h1 className="mb-2 text-3xl font-bold text-gray-900 dark:text-white">
                        Already Activated
                    </h1>

                    {/* Message */}
                    <p className="mx-auto mb-4 max-w-xl text-gray-600 dark:text-gray-400">
                        This account has already been activated. You can log in using your password.
                    </p>

                    <div className="mb-4 space-y-3 text-left">
                        {/* Account Info */}
                        <div className="flex flex-wrap items-baseline gap-x-2 gap-y-1 rounded-xl border border-gray-200 bg-gray-50 px-4 py-3 dark:border-gray-700 dark:bg-gray-700/60">
                            <p className="text-sm text-gray-600 dark:text-gray-400">Your account email:</p>
                            <p className="break-words font-semibold text-gray-900 dark:text-white">{email}</p>
                        </div>

                        {/* Instructions */}
                        <div className="rounded-xl border border-gray-200 bg-gray-50 px-4 py-3 dark:border-gray-600 dark:bg-gray-700/50">
                            <h3 className="mb-2 text-sm font-semibold text-gray-900 dark:text-gray-100">
                                Need help?
                            </h3>
                            <ul className="space-y-1.5 text-sm text-gray-700 dark:text-gray-300">
                                <li>• If you forgot your password, use the "Forgot Password" link</li>
                                <li>• If you're having trouble logging in, contact your manager</li>
                                <li>• Make sure you're using the correct email address</li>
                            </ul>
                        </div>

                    </div>

                    {/* Action Buttons */}
                    <div className="grid gap-3 sm:grid-cols-2">
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
