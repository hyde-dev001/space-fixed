import { Head, Link } from '@inertiajs/react';
import { useState, useEffect } from 'react';
import Navigation from '../UserSide/Navigation';

interface ShopOwner {
    email: string;
    business_name: string;
    status: 'pending' | 'approved' | 'rejected';
    email_verified_at: string | null;
    created_at: string;
    rejection_reason?: string;
}

interface PendingApprovalProps {
    shopOwner: ShopOwner;
}

export default function PendingApproval({ shopOwner }: PendingApprovalProps) {
    const [daysSinceSubmission, setDaysSinceSubmission] = useState(0);

    useEffect(() => {
        const submissionDate = new Date(shopOwner.created_at);
        const today = new Date();
        const diffTime = Math.abs(today.getTime() - submissionDate.getTime());
        const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
        setDaysSinceSubmission(diffDays);
    }, [shopOwner.created_at]);

    const getStatusContent = () => {
        if (shopOwner.status === 'rejected') {
            return {
                icon: (
                    <div className="w-24 h-24 bg-red-100 rounded-full flex items-center justify-center mb-6">
                        <svg className="w-12 h-12 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </div>
                ),
                title: 'Application Rejected',
                subtitle: 'Unfortunately, your shop owner application was not approved.',
                color: 'red',
                bgColor: 'bg-red-50',
                borderColor: 'border-red-200',
                textColor: 'text-red-800'
            };
        }

        if (shopOwner.status === 'approved') {
            return {
                icon: (
                    <div className="w-24 h-24 bg-green-100 rounded-full flex items-center justify-center mb-6">
                        <svg className="w-12 h-12 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                    </div>
                ),
                title: '🎉 Application Approved!',
                subtitle: 'Congratulations! Your shop owner application has been approved.',
                color: 'green',
                bgColor: 'bg-green-50',
                borderColor: 'border-green-200',
                textColor: 'text-green-800'
            };
        }

        return {
            icon: (
                <div className="w-24 h-24 bg-blue-100 rounded-full flex items-center justify-center mb-6 animate-pulse">
                    <svg className="w-12 h-12 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                </div>
            ),
            title: 'Application Under Review',
            subtitle: 'Thank you for registering! Our team is reviewing your application.',
            color: 'blue',
            bgColor: 'bg-blue-50',
            borderColor: 'border-blue-200',
            textColor: 'text-blue-800'
        };
    };

    const status = getStatusContent();
    const estimatedDaysRemaining = Math.max(0, 7 - daysSinceSubmission);

    return (
        <>
            <Head title="Application Status" />
            <div className="min-h-screen bg-gradient-to-b from-gray-50 to-white">
                <Navigation />
                
                <div className="max-w-4xl mx-auto px-4 py-24">
                    {/* Main Status Card */}
                    <div className="bg-white rounded-2xl shadow-xl p-8 md:p-12 border border-gray-200 text-center">
                        {status.icon}
                        
                        <h1 className="text-3xl md:text-4xl font-bold text-gray-900 mb-3">
                            {status.title}
                        </h1>
                        <p className="text-lg text-gray-600 mb-8">
                            {status.subtitle}
                        </p>

                        {/* Business Info */}
                        <div className="bg-gray-50 rounded-lg p-6 mb-8">
                            <div className="grid grid-cols-1 md:grid-cols-2 gap-4 text-left">
                                <div>
                                    <p className="text-sm text-gray-600 mb-1">Business Name</p>
                                    <p className="font-semibold text-gray-900">{shopOwner.business_name}</p>
                                </div>
                                <div>
                                    <p className="text-sm text-gray-600 mb-1">Email</p>
                                    <p className="font-semibold text-gray-900">{shopOwner.email}</p>
                                </div>
                                <div>
                                    <p className="text-sm text-gray-600 mb-1">Submitted</p>
                                    <p className="font-semibold text-gray-900">
                                        {new Date(shopOwner.created_at).toLocaleDateString('en-US', {
                                            year: 'numeric',
                                            month: 'long',
                                            day: 'numeric'
                                        })}
                                    </p>
                                </div>
                                <div>
                                    <p className="text-sm text-gray-600 mb-1">Status</p>
                                    <span className={`inline-flex items-center px-3 py-1 rounded-full text-sm font-semibold ${
                                        shopOwner.status === 'rejected' 
                                            ? 'bg-red-100 text-red-800'
                                            : 'bg-yellow-100 text-yellow-800'
                                    }`}>
                                        {shopOwner.status === 'rejected' ? (
                                            <>
                                                <svg className="w-4 h-4 mr-1" fill="currentColor" viewBox="0 0 20 20">
                                                    <path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clipRule="evenodd" />
                                                </svg>
                                                Rejected
                                            </>
                                        ) : (
                                            <>
                                                <svg className="w-4 h-4 mr-1 animate-spin" fill="none" viewBox="0 0 24 24">
                                                    <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"></circle>
                                                    <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                                </svg>
                                                Pending Review
                                            </>
                                        )}
                                    </span>
                                </div>
                            </div>
                        </div>

                        {shopOwner.status === 'rejected' && shopOwner.rejection_reason && (
                            <div className="bg-red-50 border border-red-200 rounded-lg p-6 mb-8 text-left">
                                <div className="flex items-start">
                                    <svg className="w-6 h-6 text-red-600 mt-0.5 mr-3 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                        <path fillRule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clipRule="evenodd" />
                                    </svg>
                                    <div>
                                        <h3 className="font-semibold text-red-900 mb-2">Reason for Rejection</h3>
                                        <p className="text-red-800">{shopOwner.rejection_reason}</p>
                                    </div>
                                </div>
                            </div>
                        )}

                        {shopOwner.status === 'approved' && (
                            <div className="bg-green-50 border border-green-200 rounded-lg p-6 mb-8 text-left">
                                <div className="flex items-start">
                                    <svg className="w-6 h-6 text-green-600 mt-0.5 mr-3 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                        <path fillRule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clipRule="evenodd" />
                                    </svg>
                                    <div className="flex-1">
                                        <h3 className="font-semibold text-green-900 mb-2">📧 Check Your Email</h3>
                                        <p className="text-green-800 mb-3">
                                            We've sent an email to <strong>{shopOwner.email}</strong> with a link to set up your password.
                                        </p>
                                        <p className="text-green-700 text-sm">
                                            The link will expire in 48 hours. If you don't see the email, please check your spam folder.
                                        </p>
                                    </div>
                                </div>
                            </div>
                        )}

                        {shopOwner.status === 'pending' && (
                            <>
                                {/* Progress Timeline */}
                                <div className="mb-8">
                                    <div className="flex items-center justify-between mb-2">
                                        <span className="text-sm font-medium text-gray-700">Review Progress</span>
                                        <span className="text-sm font-medium text-blue-600">
                                            Day {daysSinceSubmission} of 7
                                        </span>
                                    </div>
                                    <div className="w-full bg-gray-200 rounded-full h-3">
                                        <div 
                                            className="bg-gradient-to-r from-blue-500 to-blue-600 h-3 rounded-full transition-all duration-500"
                                            style={{ width: `${Math.min((daysSinceSubmission / 7) * 100, 100)}%` }}
                                        ></div>
                                    </div>
                                    <p className="text-sm text-gray-600 mt-2">
                                        {estimatedDaysRemaining > 0 
                                            ? `Estimated ${estimatedDaysRemaining} day${estimatedDaysRemaining !== 1 ? 's' : ''} remaining`
                                            : 'Review should be complete soon'
                                        }
                                    </p>
                                </div>

                                {/* What Happens Next */}
                                <div className="bg-gradient-to-r from-blue-50 to-indigo-50 border border-blue-200 rounded-lg p-6 text-left">
                                    <h3 className="font-semibold text-gray-900 mb-4 flex items-center">
                                        <svg className="w-5 h-5 text-blue-600 mr-2" fill="currentColor" viewBox="0 0 20 20">
                                            <path fillRule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clipRule="evenodd" />
                                        </svg>
                                        What Happens Next
                                    </h3>
                                    <div className="space-y-3">
                                        <div className="flex items-start">
                                            <div className="flex-shrink-0 w-6 h-6 bg-blue-600 rounded-full flex items-center justify-center text-white text-xs font-bold mr-3 mt-0.5">
                                                1
                                            </div>
                                            <div>
                                                <p className="font-medium text-gray-900">Document Verification</p>
                                                <p className="text-sm text-gray-600">Our team is reviewing your submitted business documents</p>
                                            </div>
                                        </div>
                                        <div className="flex items-start">
                                            <div className="flex-shrink-0 w-6 h-6 bg-blue-600 rounded-full flex items-center justify-center text-white text-xs font-bold mr-3 mt-0.5">
                                                2
                                            </div>
                                            <div>
                                                <p className="font-medium text-gray-900">Admin Review</p>
                                                <p className="text-sm text-gray-600">Super admin will approve or request additional information</p>
                                            </div>
                                        </div>
                                        <div className="flex items-start">
                                            <div className="flex-shrink-0 w-6 h-6 bg-blue-600 rounded-full flex items-center justify-center text-white text-xs font-bold mr-3 mt-0.5">
                                                3
                                            </div>
                                            <div>
                                                <p className="font-medium text-gray-900">Email Notification</p>
                                                <p className="text-sm text-gray-600">You'll receive an email when your application is approved</p>
                                            </div>
                                        </div>
                                        <div className="flex items-start">
                                            <div className="flex-shrink-0 w-6 h-6 bg-blue-600 rounded-full flex items-center justify-center text-white text-xs font-bold mr-3 mt-0.5">
                                                4
                                            </div>
                                            <div>
                                                <p className="font-medium text-gray-900">Set Your Password</p>
                                                <p className="text-sm text-gray-600">Create your password and start managing your shop</p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </>
                        )}

                        {/* Email Verification Status */}
                        {shopOwner.email_verified_at && (
                            <div className="mt-6 flex items-center justify-center text-sm text-green-600">
                                <svg className="w-5 h-5 mr-2" fill="currentColor" viewBox="0 0 20 20">
                                    <path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clipRule="evenodd" />
                                </svg>
                                Email verified
                            </div>
                        )}
                    </div>

                    {/* Help Section */}
                    <div className="mt-8 bg-white rounded-lg p-6 border border-gray-200">
                        <h3 className="font-semibold text-gray-900 mb-4 flex items-center">
                            <svg className="w-5 h-5 text-gray-600 mr-2" fill="currentColor" viewBox="0 0 20 20">
                                <path fillRule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-8-3a1 1 0 00-.867.5 1 1 0 11-1.731-1A3 3 0 0113 8a3.001 3.001 0 01-2 2.83V11a1 1 0 11-2 0v-1a1 1 0 011-1 1 1 0 100-2zm0 8a1 1 0 100-2 1 1 0 000 2z" clipRule="evenodd" />
                            </svg>
                            Need Help?
                        </h3>
                        <div className="space-y-2 text-sm text-gray-600">
                            <p>• Review timeline: Applications are typically reviewed within 3-7 business days</p>
                            <p>• You'll receive an email notification once your application is reviewed</p>
                            <p>• Make sure to check your spam folder for our emails</p>
                            {shopOwner.status === 'rejected' && (
                                <p className="text-red-600 font-medium">
                                    • If you believe this was an error, please contact support
                                </p>
                            )}
                        </div>
                        
                        <div className="mt-6 flex gap-3">
                            <Link
                                href="/"
                                className="flex-1 text-center bg-gray-100 hover:bg-gray-200 text-gray-800 font-semibold py-3 px-6 rounded-lg transition-colors"
                            >
                                Back to Home
                            </Link>
                            {shopOwner.status === 'rejected' && (
                                <a
                                    href="mailto:support@solespace.com"
                                    className="flex-1 text-center bg-blue-600 hover:bg-blue-700 text-white font-semibold py-3 px-6 rounded-lg transition-colors"
                                >
                                    Contact Support
                                </a>
                            )}
                        </div>
                    </div>
                </div>
            </div>
        </>
    );
}
