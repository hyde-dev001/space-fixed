/**
 * Upgrade Banner Component
 * 
 * Displays a prominent banner encouraging Individual shop owners
 * to upgrade to a Company account to unlock additional features
 */

import { Link } from '@inertiajs/react';

interface UpgradeBannerProps {
    feature?: string;
    className?: string;
}

export default function UpgradeBanner({ feature, className = '' }: UpgradeBannerProps) {
    const defaultFeature = feature || 'This feature';

    return (
        <div className={`bg-gradient-to-r from-blue-50 to-indigo-50 border-2 border-blue-200 rounded-xl p-6 shadow-lg ${className}`}>
            <div className="flex items-start gap-4">
                {/* Icon */}
                <div className="flex-shrink-0">
                    <div className="w-12 h-12 bg-gradient-to-br from-blue-500 to-indigo-600 rounded-full flex items-center justify-center shadow-md">
                        <span className="text-2xl">⬆️</span>
                    </div>
                </div>

                {/* Content */}
                <div className="flex-1">
                    <h3 className="text-xl font-bold text-gray-900 mb-2">
                        Upgrade to Company Account
                    </h3>
                    <p className="text-gray-700 mb-4">
                        {defaultFeature} is available exclusively for Company accounts. 
                        Upgrade today to unlock powerful features for growing businesses.
                    </p>

                    {/* Benefits Grid */}
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-3 mb-4">
                        <div className="flex items-start gap-2">
                            <svg className="w-5 h-5 text-green-600 mt-0.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                <path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clipRule="evenodd" />
                            </svg>
                            <div>
                                <p className="text-sm font-semibold text-gray-900">Unlimited Staff Members</p>
                                <p className="text-xs text-gray-600">Hire and manage employees</p>
                            </div>
                        </div>

                        <div className="flex items-start gap-2">
                            <svg className="w-5 h-5 text-green-600 mt-0.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                <path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clipRule="evenodd" />
                            </svg>
                            <div>
                                <p className="text-sm font-semibold text-gray-900">Multiple Locations</p>
                                <p className="text-xs text-gray-600">Expand to multiple branches</p>
                            </div>
                        </div>

                        <div className="flex items-start gap-2">
                            <svg className="w-5 h-5 text-green-600 mt-0.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                <path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clipRule="evenodd" />
                            </svg>
                            <div>
                                <p className="text-sm font-semibold text-gray-900">Advanced Permissions</p>
                                <p className="text-xs text-gray-600">Role-based access control</p>
                            </div>
                        </div>

                        <div className="flex items-start gap-2">
                            <svg className="w-5 h-5 text-green-600 mt-0.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                <path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clipRule="evenodd" />
                            </svg>
                            <div>
                                <p className="text-sm font-semibold text-gray-900">Staff Performance Analytics</p>
                                <p className="text-xs text-gray-600">Track team productivity</p>
                            </div>
                        </div>
                    </div>

                    {/* CTA Buttons */}
                    <div className="flex gap-3">
                        <Link
                            href="/shop-owner/upgrade"
                            className="inline-flex items-center px-6 py-3 bg-gradient-to-r from-blue-600 to-indigo-600 text-white font-semibold rounded-lg hover:from-blue-700 hover:to-indigo-700 transition-all shadow-md hover:shadow-lg transform hover:-translate-y-0.5"
                        >
                            <span>Upgrade Now</span>
                            <svg className="w-5 h-5 ml-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M13 7l5 5m0 0l-5 5m5-5H6" />
                            </svg>
                        </Link>
                        <Link
                            href="/shop-owner/upgrade/compare"
                            className="inline-flex items-center px-6 py-3 bg-white text-blue-600 font-semibold rounded-lg hover:bg-gray-50 transition-colors border-2 border-blue-200"
                        >
                            Compare Plans
                        </Link>
                    </div>
                </div>
            </div>
        </div>
    );
}
