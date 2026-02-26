import React, { useState, FormEvent } from 'react';
import { Head, useForm } from '@inertiajs/react';

interface Props {
    token: string;
    email: string;
    name: string;
    expires_at: string;
}

export default function AcceptInvitation({ token, email, name, expires_at }: Props) {
    const { data, setData, post, processing, errors } = useForm({
        password: '',
        password_confirmation: '',
    });

    const [showPassword, setShowPassword] = useState(false);
    const [showConfirmPassword, setShowConfirmPassword] = useState(false);

    const handleSubmit = (e: FormEvent) => {
        e.preventDefault();
        post(`/accept-invitation/${token}`);
    };

    const expiresDate = new Date(expires_at).toLocaleString();

    const passwordRequirements = [
        { met: data.password.length >= 8, text: 'At least 8 characters' },
        { met: /[A-Z]/.test(data.password), text: 'One uppercase letter (A-Z)' },
        { met: /[a-z]/.test(data.password), text: 'One lowercase letter (a-z)' },
        { met: /\d/.test(data.password), text: 'One number (0-9)' },
        { met: /[@$!%*?&]/.test(data.password), text: 'One special character (@$!%*?&)' },
    ];

    return (
        <>
            <Head title="Accept Invitation" />
            
            <div className="min-h-screen flex items-center justify-center bg-gradient-to-br from-blue-50 to-indigo-100 dark:from-gray-900 dark:to-gray-800 px-4">
                <div className="max-w-md w-full bg-white dark:bg-gray-800 rounded-2xl shadow-2xl p-8">
                    {/* Header */}
                    <div className="text-center mb-8">
                        <div className="w-16 h-16 bg-blue-600 rounded-full flex items-center justify-center mx-auto mb-4">
                            <svg className="w-10 h-10 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z" />
                            </svg>
                        </div>
                        <h1 className="text-3xl font-bold text-gray-900 dark:text-white mb-2">
                            Welcome to SoleSpace!
                        </h1>
                        <p className="text-gray-600 dark:text-gray-400">
                            Set up your account password
                        </p>
                    </div>

                    {/* User Info */}
                    <div className="bg-blue-50 dark:bg-blue-900/20 rounded-lg p-4 mb-6">
                        <p className="text-sm text-gray-600 dark:text-gray-400 mb-1">Account for:</p>
                        <p className="font-semibold text-gray-900 dark:text-white">{name}</p>
                        <p className="text-sm text-gray-600 dark:text-gray-400">{email}</p>
                    </div>

                    {/* Form */}
                    <form onSubmit={handleSubmit} className="space-y-6">
                        {/* Password */}
                        <div>
                            <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                Create Password <span className="text-red-500">*</span>
                            </label>
                            <div className="relative">
                                <input
                                    type={showPassword ? 'text' : 'password'}
                                    value={data.password}
                                    onChange={(e) => setData('password', e.target.value)}
                                    className="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white"
                                    placeholder="Enter your password"
                                    autoComplete="new-password"
                                />
                                <button
                                    type="button"
                                    onClick={() => setShowPassword(!showPassword)}
                                    className="absolute right-3 top-3 text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300"
                                >
                                    {showPassword ? (
                                        <svg className="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21" />
                                        </svg>
                                    ) : (
                                        <svg className="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                        </svg>
                                    )}
                                </button>
                            </div>
                            {errors.password && (
                                <p className="text-red-500 text-sm mt-1">{errors.password}</p>
                            )}
                        </div>

                        {/* Confirm Password */}
                        <div>
                            <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                Confirm Password <span className="text-red-500">*</span>
                            </label>
                            <div className="relative">
                                <input
                                    type={showConfirmPassword ? 'text' : 'password'}
                                    value={data.password_confirmation}
                                    onChange={(e) => setData('password_confirmation', e.target.value)}
                                    className="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white"
                                    placeholder="Confirm your password"
                                    autoComplete="new-password"
                                />
                                <button
                                    type="button"
                                    onClick={() => setShowConfirmPassword(!showConfirmPassword)}
                                    className="absolute right-3 top-3 text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300"
                                >
                                    {showConfirmPassword ? (
                                        <svg className="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21" />
                                        </svg>
                                    ) : (
                                        <svg className="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                        </svg>
                                    )}
                                </button>
                            </div>
                        </div>

                        {/* Password Requirements */}
                        <div className="bg-gray-50 dark:bg-gray-700 rounded-lg p-4">
                            <p className="text-xs font-semibold text-gray-700 dark:text-gray-300 mb-2">Password must contain:</p>
                            <ul className="space-y-1">
                                {passwordRequirements.map((req, index) => (
                                    <li key={index} className={`text-xs flex items-center ${req.met ? 'text-green-600 dark:text-green-400' : 'text-gray-600 dark:text-gray-400'}`}>
                                        <span className="mr-2">{req.met ? '✓' : '○'}</span>
                                        {req.text}
                                    </li>
                                ))}
                            </ul>
                        </div>

                        {/* Submit Button */}
                        <button
                            type="submit"
                            disabled={processing}
                            className="w-full bg-blue-600 hover:bg-blue-700 text-white font-semibold py-3 rounded-lg transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
                        >
                            {processing ? 'Setting up...' : 'Activate Account'}
                        </button>
                    </form>

                    {/* Expiry Warning */}
                    <div className="mt-6 text-center">
                        <p className="text-xs text-gray-500 dark:text-gray-400">
                            ⏰ This invitation expires on {expiresDate}
                        </p>
                    </div>
                </div>
            </div>
        </>
    );
}
