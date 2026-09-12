import { Head, useForm } from '@inertiajs/react';
import { useState } from 'react';
import Navigation from '../UserSide/Shared/Navigation';

interface SetupPasswordProps {
    email: string;
    token: string;
    shopOwner: {
        business_name: string;
        first_name: string;
    };
}

export default function SetupPassword({ email, token, shopOwner }: SetupPasswordProps) {
    const [showPassword, setShowPassword] = useState(false);
    const [showConfirmPassword, setShowConfirmPassword] = useState(false);
    
    const { data, setData, post, processing, errors } = useForm({
        email: email,
        token: token,
        password: '',
        password_confirmation: '',
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        post(route('shop-owner.password.setup.store'));
    };

    const passwordRequirements = [
        { met: data.password.length >= 8, text: 'At least 8 characters' },
        { met: /[A-Z]/.test(data.password) && /[a-z]/.test(data.password), text: 'Mixed case letters (A-z)' },
        { met: /[0-9]/.test(data.password), text: 'At least one number (0-9)' },
        { met: /[^a-zA-Z0-9]/.test(data.password), text: 'At least one symbol (!@#$%^&*)' },
    ];

    return (
        <>
            <Head title="Set Up Your Password" />
            <style>{`
                .password-input::-ms-reveal,
                .password-input::-ms-clear {
                    display: none;
                }

                .password-input::-webkit-credentials-auto-fill-button,
                .password-input::-webkit-textfield-decoration-container {
                    visibility: hidden;
                    pointer-events: none;
                    position: absolute;
                    right: 0;
                }
            `}</style>

            <div className="userside-auth-page min-h-screen bg-white">
                <Navigation />

                <div className="relative overflow-hidden px-4 py-14 sm:py-20">
                    <div className="pointer-events-none absolute inset-0">
                        <div className="absolute -top-28 -left-24 h-80 w-80 rounded-full bg-gray-100 blur-3xl" />
                        <div className="absolute -bottom-28 -right-24 h-96 w-96 rounded-full bg-gray-100 blur-3xl" />
                    </div>

                    <div className="userside-auth-card relative max-w-md mx-auto rounded-3xl border border-gray-200 bg-white shadow-2xl p-8">
                        <div className="text-center mb-8">
                            <div className="w-16 h-16 bg-black rounded-full flex items-center justify-center mx-auto mb-4 shadow-lg">
                                <svg className="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                                </svg>
                            </div>

                            <h1 className="userside-auth-title text-3xl font-bold text-gray-900 mb-2">
                                Welcome, {shopOwner.first_name}!
                            </h1>
                            <p className="userside-auth-subtitle text-gray-600">Set up your password for</p>
                            <p className="font-semibold text-black mt-1">{shopOwner.business_name}</p>
                        </div>

                        <div className="bg-gray-50 border border-gray-200 rounded-xl p-4 mb-6">
                            <div className="flex items-start">
                                <svg className="w-5 h-5 text-black mt-0.5 mr-2" fill="currentColor" viewBox="0 0 20 20">
                                    <path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clipRule="evenodd" />
                                </svg>
                                <div>
                                    <p className="text-sm font-semibold text-black">
                                        Your application has been approved!
                                    </p>
                                    <p className="text-sm text-gray-700 mt-1">
                                        Create a strong password to access your shop dashboard.
                                    </p>
                                </div>
                            </div>
                        </div>

                        <form onSubmit={submit} className="space-y-6">
                            <div>
                                <label htmlFor="password" className="block text-sm font-medium text-gray-700 mb-2">
                                    Password
                                </label>
                                <div className="relative">
                                    <input
                                        id="password"
                                        type={showPassword ? 'text' : 'password'}
                                        value={data.password}
                                        onChange={(e) => setData('password', e.target.value)}
                                        className={`userside-auth-input password-input w-full px-4 py-3 pr-12 border rounded-lg appearance-none focus:ring-2 focus:ring-black focus:border-black ${
                                            errors.password ? 'border-black' : 'border-gray-300'
                                        }`}
                                        placeholder="Enter your password"
                                        autoComplete="new-password"
                                        required
                                    />
                                    <button
                                        type="button"
                                        onClick={() => setShowPassword(!showPassword)}
                                        className="absolute right-3 top-1/2 -translate-y-1/2 text-gray-500 hover:text-black"
                                        aria-label={showPassword ? 'Hide password' : 'Show password'}
                                    >
                                        {showPassword ? (
                                            <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21" />
                                            </svg>
                                        ) : (
                                            <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                            </svg>
                                        )}
                                    </button>
                                </div>
                                {errors.password && (
                                    <p className="mt-1 text-sm text-black">{errors.password}</p>
                                )}
                            </div>

                            <div>
                                <label htmlFor="password_confirmation" className="block text-sm font-medium text-gray-700 mb-2">
                                    Confirm Password
                                </label>
                                <div className="relative">
                                    <input
                                        id="password_confirmation"
                                        type={showConfirmPassword ? 'text' : 'password'}
                                        value={data.password_confirmation}
                                        onChange={(e) => setData('password_confirmation', e.target.value)}
                                        className={`userside-auth-input password-input w-full px-4 py-3 pr-12 border rounded-lg appearance-none focus:ring-2 focus:ring-black focus:border-black ${
                                            errors.password_confirmation ? 'border-black' : 'border-gray-300'
                                        }`}
                                        placeholder="Confirm your password"
                                        autoComplete="new-password"
                                        required
                                    />
                                    <button
                                        type="button"
                                        onClick={() => setShowConfirmPassword(!showConfirmPassword)}
                                        className="absolute right-3 top-1/2 -translate-y-1/2 text-gray-500 hover:text-black"
                                        aria-label={showConfirmPassword ? 'Hide confirm password' : 'Show confirm password'}
                                    >
                                        {showConfirmPassword ? (
                                            <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21" />
                                            </svg>
                                        ) : (
                                            <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                            </svg>
                                        )}
                                    </button>
                                </div>
                                {errors.password_confirmation && (
                                    <p className="mt-1 text-sm text-black">{errors.password_confirmation}</p>
                                )}
                            </div>

                            <div className="bg-gray-50 rounded-xl p-4 border border-gray-200">
                                <p className="text-xs font-semibold text-gray-700 mb-2">Password must contain:</p>
                                <ul className="space-y-1">
                                    {passwordRequirements.map((requirement, index) => (
                                        <li
                                            key={index}
                                            className={`text-xs flex items-center ${requirement.met ? 'text-black' : 'text-gray-500'}`}
                                        >
                                            <span className="mr-2">{requirement.met ? '●' : '○'}</span>
                                            {requirement.text}
                                        </li>
                                    ))}
                                </ul>
                            </div>

                            <button
                                type="submit"
                                disabled={processing}
                                className="userside-auth-primary w-full bg-black hover:bg-gray-900 text-white font-semibold py-3 px-6 rounded-lg transition-colors duration-200 disabled:opacity-50 disabled:cursor-not-allowed"
                            >
                                {processing ? (
                                    <span className="flex items-center justify-center">
                                        <svg className="animate-spin -ml-1 mr-3 h-5 w-5 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                            <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"></circle>
                                            <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                        </svg>
                                        Setting up...
                                    </span>
                                ) : (
                                    'Complete Setup'
                                )}
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </>
    );
}
