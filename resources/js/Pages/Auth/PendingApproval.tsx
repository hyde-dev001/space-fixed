import { Head, Link } from '@inertiajs/react';
import { useState, useEffect } from 'react';
import Navigation from '../UserSide/Shared/Navigation';

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

const REVIEW_WINDOW_DAYS = 7;

const reviewTimeline = [
    {
        title: 'Document Verification',
        window: 'Day 1-2',
        description: 'Validation of submitted business requirements and profile details.'
    },
    {
        title: 'Admin Review',
        window: 'Day 2-4',
        description: 'SoleSpace admin confirms compliance and checks storefront readiness.'
    },
    {
        title: 'Final Decision',
        window: 'Day 4-7',
        description: 'Approval result is finalized and queued for notification.'
    },
    {
        title: 'Email Notification',
        window: 'Within 24h after decision',
        description: 'You will receive approval status and next-step instructions in email.'
    }
];

export default function PendingApproval({ shopOwner }: PendingApprovalProps) {
    const [daysSinceSubmission, setDaysSinceSubmission] = useState(1);

    useEffect(() => {
        const submissionDate = new Date(shopOwner.created_at);
        if (Number.isNaN(submissionDate.getTime())) {
            setDaysSinceSubmission(1);
            return;
        }

        const today = new Date();
        const diffTime = today.getTime() - submissionDate.getTime();
        const diffDays = Math.floor(diffTime / (1000 * 60 * 60 * 24)) + 1;
        setDaysSinceSubmission(Math.max(1, diffDays));
    }, [shopOwner.created_at]);

    const getStatusContent = () => {
        if (shopOwner.status === 'rejected') {
            return {
                icon: (
                    <div className="relative flex h-10 w-10 items-center justify-center rounded-full border border-slate-300 bg-white text-slate-900">
                        <svg className="h-8 w-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </div>
                ),
                title: 'Application Rejected',
                subtitle: 'Your current application was not approved. You can review the reason below and contact support.',
                badgeClass: 'bg-slate-100 text-slate-700 border-slate-300'
            };
        }

        if (shopOwner.status === 'approved') {
            return {
                icon: (
                    <div className="relative flex h-10 w-10 items-center justify-center rounded-full border border-slate-300 bg-white text-slate-900">
                        <svg className="h-8 w-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                    </div>
                ),
                title: 'Application Approved',
                subtitle: 'Congratulations! Your shop owner application has been approved.',
                badgeClass: 'bg-slate-100 text-slate-700 border-slate-300'
            };
        }

        return {
            icon: (
                <div className="flex h-10 w-10 items-center justify-center rounded-full border border-slate-300 bg-white">
                    <span className="h-5 w-5 animate-spin rounded-full border-2 border-slate-700 border-t-transparent" />
                </div>
            ),
            title: 'Application Under Review',
            subtitle: 'Thank you for registering! Our team is reviewing your application.',
            badgeClass: 'bg-slate-100 text-slate-700 border-slate-300'
        };
    };

    const status = getStatusContent();
    const estimatedDaysRemaining = Math.max(0, REVIEW_WINDOW_DAYS - daysSinceSubmission);
    const progressPercent = Math.min((daysSinceSubmission / REVIEW_WINDOW_DAYS) * 100, 100);
    const progressWidthClass =
        progressPercent >= 100
            ? 'w-full'
            : progressPercent >= 86
            ? 'w-[86%]'
            : progressPercent >= 72
            ? 'w-[72%]'
            : progressPercent >= 58
            ? 'w-[58%]'
            : progressPercent >= 44
            ? 'w-[44%]'
            : progressPercent >= 30
            ? 'w-[30%]'
            : progressPercent >= 16
            ? 'w-[16%]'
            : 'w-[10%]';

    const statusLabel =
        shopOwner.status === 'approved'
            ? 'Approved'
            : shopOwner.status === 'rejected'
            ? 'Rejected'
            : 'Pending Review';

    const submittedAt = new Date(shopOwner.created_at).toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'long',
        day: 'numeric'
    });

    const getStepState = (index: number) => {
        if (shopOwner.status === 'approved') {
            return 'completed';
        }

        if (shopOwner.status === 'rejected') {
            return index <= 2 ? 'completed' : 'pending';
        }

        const dayMilestones = [2, 4, 7, 8];
        if (daysSinceSubmission >= dayMilestones[index]) {
            return 'completed';
        }

        if (index === 0 || daysSinceSubmission >= dayMilestones[index - 1]) {
            return 'active';
        }

        return 'pending';
    };

    return (
        <>
            <Head title="Application Status" />
            <div className="h-screen overflow-y-auto no-scrollbar bg-white">
                <Navigation />
                <div className="mx-auto max-w-3xl px-4 pb-8 pt-24 sm:px-6 sm:pt-28 lg:px-8 lg:pb-10 lg:pt-32">
                    <div className="relative overflow-hidden rounded-3xl border border-slate-200/70 bg-white/90 p-5 shadow-[0_30px_75px_-50px_rgba(15,23,42,0.45)] backdrop-blur-sm sm:p-6 lg:p-7">
                        <div className="relative space-y-4">
                            <div className="rounded-2xl border border-slate-200/80 bg-white/75 p-4 sm:p-5">
                                <div className="relative mb-5">
                                    <span className={`absolute right-0 top-0 inline-flex items-center rounded-full border px-3 py-1 text-xs font-semibold ${status.badgeClass}`}>
                                        {statusLabel}
                                    </span>

                                    <div className="flex items-start gap-4 pr-28 sm:pr-32">
                                        {status.icon}
                                        <div>
                                            <h1 className="text-2xl font-bold leading-tight text-slate-900 sm:text-3xl">{status.title}</h1>
                                            <p className="mt-2 max-w-2xl text-sm text-slate-600 sm:text-base">{status.subtitle}</p>
                                        </div>
                                    </div>
                                </div>

                                <div className="grid grid-cols-1 gap-3 rounded-xl border border-slate-200 bg-slate-50/90 p-4 text-sm sm:grid-cols-2">
                                    <div>
                                        <p className="text-xs uppercase tracking-[0.12em] text-slate-500">Business</p>
                                        <p className="mt-1 font-semibold text-slate-900">{shopOwner.business_name}</p>
                                    </div>
                                    <div>
                                        <p className="text-xs uppercase tracking-[0.12em] text-slate-500">Email</p>
                                        <p className="mt-1 font-semibold text-slate-900">{shopOwner.email}</p>
                                    </div>
                                    <div>
                                        <p className="text-xs uppercase tracking-[0.12em] text-slate-500">Submitted</p>
                                        <p className="mt-1 font-semibold text-slate-900">{submittedAt}</p>
                                    </div>
                                    <div>
                                        <p className="text-xs uppercase tracking-[0.12em] text-slate-500">Review Window</p>
                                        <p className="mt-1 font-semibold text-slate-900">3 to 7 business days</p>
                                    </div>
                                </div>

                                {shopOwner.status === 'pending' && (
                                    <div className="mt-4 rounded-xl border border-slate-300 bg-slate-50 p-4">
                                        <div className="mb-2 flex items-center justify-between text-xs font-semibold uppercase tracking-[0.12em] text-slate-600">
                                            <span>Review Progress</span>
                                            <span className="text-slate-800">Day {Math.min(daysSinceSubmission, REVIEW_WINDOW_DAYS)} of {REVIEW_WINDOW_DAYS}</span>
                                        </div>
                                        <div className="relative h-2.5 overflow-hidden rounded-full bg-slate-200">
                                            <div className={`h-full rounded-full bg-slate-900 transition-all duration-700 ${progressWidthClass}`} />
                                        </div>
                                        <p className="mt-2 text-sm text-slate-600">
                                            {estimatedDaysRemaining > 0
                                                ? `Estimated ${estimatedDaysRemaining} day${estimatedDaysRemaining > 1 ? 's' : ''} remaining before final decision.`
                                                : 'Final decision should be available soon. Please monitor your email inbox.'}
                                        </p>
                                    </div>
                                )}

                                {shopOwner.status === 'approved' && (
                                    <div className="mt-4 rounded-xl border border-slate-300 bg-slate-50 p-4 text-sm text-slate-700">
                                        <p className="font-semibold text-slate-900">Next step: Set your password</p>
                                        <p className="mt-1">
                                            An email was sent to <strong>{shopOwner.email}</strong> with your password setup link.
                                            The link expires in 48 hours.
                                        </p>
                                    </div>
                                )}

                                {shopOwner.status === 'rejected' && shopOwner.rejection_reason && (
                                    <div className="mt-4 rounded-xl border border-slate-300 bg-slate-50 p-4 text-sm text-slate-700">
                                        <p className="font-semibold text-slate-900">Reason for rejection</p>
                                        <p className="mt-1">{shopOwner.rejection_reason}</p>
                                    </div>
                                )}

                                {shopOwner.email_verified_at && (
                                    <div className="mt-4 inline-flex items-center rounded-full border border-slate-300 bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-700">
                                        <svg className="mr-1.5 h-4 w-4" fill="currentColor" viewBox="0 0 20 20">
                                            <path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clipRule="evenodd" />
                                        </svg>
                                        Email verified
                                    </div>
                                )}
                            </div>

                            <aside className="rounded-2xl border border-slate-200 bg-slate-50/80 p-4 sm:p-5">
                                <h2 className="text-sm font-semibold uppercase tracking-[0.14em] text-slate-700">Approval Timeline</h2>
                                <p className="mt-1 text-sm text-slate-600">
                                    Most SoleSpace applications are approved within 3 to 7 business days.
                                </p>

                                <div className="mt-4 space-y-3">
                                    {reviewTimeline.map((step, index) => {
                                        const state = getStepState(index);
                                        const bulletClass =
                                            state === 'completed'
                                                ? 'bg-slate-900 text-white'
                                                : state === 'active'
                                                ? 'bg-slate-700 text-white animate-pulse'
                                                : 'bg-slate-200 text-slate-600';

                                        return (
                                            <div key={step.title} className="flex gap-3 rounded-xl border border-slate-200 bg-white p-3">
                                                <div className={`mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-xs font-bold ${bulletClass}`}>
                                                    {index + 1}
                                                </div>
                                                <div>
                                                    <p className="text-sm font-semibold text-slate-900">{step.title}</p>
                                                    <p className="text-xs font-medium uppercase tracking-[0.08em] text-slate-700">{step.window}</p>
                                                    <p className="mt-1 text-xs text-slate-600">{step.description}</p>
                                                </div>
                                            </div>
                                        );
                                    })}
                                </div>

                                <div className="mt-4 rounded-xl border border-slate-300 bg-slate-100 p-3 text-xs text-slate-700">
                                    Keep your inbox and spam folder checked daily. Approval updates are sent via email only.
                                </div>

                                <div className="mt-4 flex flex-col gap-2 sm:flex-row lg:flex-col">
                                    <Link
                                        href="/"
                                        className="inline-flex w-full items-center justify-center rounded-xl bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-slate-700"
                                    >
                                        Back to Home
                                    </Link>
                                </div>
                            </aside>
                        </div>
                    </div>
                </div>
            </div>
        </>
    );
}
