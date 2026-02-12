import type { ComponentType } from 'react';
import { Head, usePage } from '@inertiajs/react';
import AppLayoutERP from '../../../layout/AppLayout_ERP';
import React, { useState, useEffect } from 'react';
import Chart from "react-apexcharts";
import { ApexOptions } from "apexcharts";
import { useManagerStats } from '../../../hooks/useManagerApi';
import Swal from 'sweetalert2';

interface ManagerDashboardStats {
    totalSales: number;
    totalRepairs: number;
    pendingJobOrders: number;
    salesChange?: number;
    activeStaff?: number;
    pendingApprovals?: number;
    approvalSummary?: {
        expenses: {
            count: number;
            total_amount: number;
        };
        leave_requests: {
            count: number;
            details: Array<{
                id: number;
                leave_type: string;
                start_date: string;
                end_date: string;
                no_of_days: number;
                reason: string;
                created_at: string;
                employee_id: number;
                employee_name: string;
                employee_email: string;
                employee_position: string;
                days_pending: number;
            }>;
        };
    };
    recentActivities?: Array<{
        id: number;
        user_name: string;
        action: string;
        entity_type: string;
        entity_id: number;
        description: string;
        timestamp: string;
        time_ago: string;
    }>;
}

interface PendingLeave {
    id: number;
    employee: {
        id: number;
        name: string;
        email: string;
        position: string;
    };
    leave_type: string;
    leave_type_label: string;
    start_date: string;
    end_date: string;
    no_of_days: number;
    reason: string;
    created_at: string;
    days_pending: number;
}

type MetricColor = 'success' | 'error' | 'warning' | 'info';
type ChangeType = 'increase' | 'decrease';

// Icon Components
const ArrowUpIcon = ({ className }: { className?: string }) => (
    <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 10l7-7m0 0l7 7m-7-7v18" />
    </svg>
);

const ArrowDownIcon = ({ className }: { className?: string }) => (
    <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 14l-7 7m0 0l-7-7m7 7V3" />
    </svg>
);

const DollarIconSvg = ({ className }: { className?: string }) => (
    <svg className={className} fill="currentColor" viewBox="0 0 24 24">
        <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 18c-4.41 0-8-3.59-8-8s3.59-8 8-8 8 3.59 8 8-3.59 8-8 8zm3.5-9c.83 0 1.5-.67 1.5-1.5S16.33 8 15.5 8 14 8.67 14 9.5s.67 1.5 1.5 1.5zm-7 0c.83 0 1.5-.67 1.5-1.5S9.33 8 8.5 8 7 8.67 7 9.5 7.67 11 8.5 11zm3.5 6.5c2.33 0 4.31-1.46 5.11-3.5H6.89c.8 2.04 2.78 3.5 5.11 3.5z" />
    </svg>
);

const TaskIconSvg = ({ className }: { className?: string }) => (
    <svg className={className} fill="currentColor" viewBox="0 0 24 24">
        <path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01" />
    </svg>
);

const AlertIconSvg = ({ className }: { className?: string }) => (
    <svg className={className} fill="currentColor" viewBox="0 0 24 24">
        <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z" />
    </svg>
);

const MoreDotIcon = ({ className = "" }) => (
    <svg className={className} width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
        <path fillRule="evenodd" clipRule="evenodd" d="M10.2441 6C10.2441 5.0335 11.0276 4.25 11.9941 4.25H12.0041C12.9706 4.25 13.7541 5.0335 13.7541 6C13.7541 6.9665 12.9706 7.75 12.0041 7.75H11.9941C11.0276 7.75 10.2441 6.9665 10.2441 6ZM10.2441 18C10.2441 17.0335 11.0276 16.25 11.9941 16.25H12.0041C12.9706 16.25 13.7541 17.0335 13.7541 18C13.7541 18.9665 12.9706 19.75 12.0041 19.75H11.9941C11.0276 19.75 10.2441 18.9665 10.2441 18ZM11.9941 10.25C11.0276 10.25 10.2441 11.0335 10.2441 12C10.2441 12.9665 11.0276 13.75 11.9941 13.75H12.0041C12.9706 13.75 13.7541 12.9665 13.7541 12C13.7541 11.0335 12.9706 10.25 12.0041 10.25H11.9941Z" fill="currentColor" />
    </svg>
);

interface MetricCardProps {
    title: string;
    value: number | string;
    change: number;
    changeType: ChangeType;
    icon: ComponentType<{ className?: string }>;
    color: MetricColor;
    description: string;
}

const MetricCard = ({
    title,
    value,
    change,
    changeType,
    icon: Icon,
    color,
    description,
}: MetricCardProps) => {
    const [animatedValue, setAnimatedValue] = useState(0);

    useEffect(() => {
        if (typeof value === 'number') {
            const duration = 2000;
            const steps = 60;
            const increment = value / steps;
            let current = 0;

            const timer = setInterval(() => {
                current += increment;
                if (current >= value) {
                    setAnimatedValue(value);
                    clearInterval(timer);
                } else {
                    setAnimatedValue(Math.floor(current));
                }
            }, duration / steps);

            return () => clearInterval(timer);
        }
    }, [value]);

    const getColorClasses = () => {
        switch (color) {
            case 'success': return 'from-green-500 to-emerald-600';
            case 'error': return 'from-red-500 to-rose-600';
            case 'warning': return 'from-yellow-500 to-orange-600';
            case 'info': return 'from-blue-500 to-indigo-600';
            default: return 'from-gray-500 to-gray-600';
        }
    };

    const displayValue = typeof value === 'number' ? animatedValue.toLocaleString() : value;

    return (
        <div className="group relative overflow-hidden rounded-2xl border border-gray-200 bg-white p-6 shadow-sm transition-all duration-500 hover:shadow-xl hover:border-gray-300 hover:-translate-y-1 dark:border-gray-800 dark:bg-white/[0.03] dark:hover:border-gray-700">
            <div className={`absolute inset-0 bg-gradient-to-br ${getColorClasses()} opacity-0 transition-opacity duration-500 group-hover:opacity-5`} />

            <div className="relative">
                <div className="flex items-center justify-between mb-4">
                    <div className={`flex items-center justify-center w-14 h-14 bg-gradient-to-br ${getColorClasses()} rounded-2xl shadow-lg transition-all duration-300 group-hover:scale-110 group-hover:rotate-6`}>
                        <Icon className="text-white size-7 drop-shadow-sm" />
                    </div>

                    <div className={`flex items-center gap-1 px-3 py-1 rounded-full text-xs font-semibold transition-all duration-300 ${
                        changeType === 'increase'
                            ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400'
                            : 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400'
                    }`}>
                        {changeType === 'increase' ? <ArrowUpIcon className="size-3" /> : <ArrowDownIcon className="size-3" />}
                        {Math.abs(change)}%
                    </div>
                </div>

                <div className="space-y-2">
                    <p className="text-sm font-medium text-gray-600 dark:text-gray-400">
                        {title}
                    </p>
                    <h3 className="text-3xl font-bold text-gray-900 dark:text-white transition-colors duration-300">
                        {displayValue}
                    </h3>
                    <p className="text-xs text-gray-500 dark:text-gray-400">
                        {description}
                    </p>
                </div>
            </div>
        </div>
    );
};

// Daily/Monthly Performance Chart Component
const PerformanceChart: React.FC = () => {
    const [chartView, setChartView] = useState<'daily' | 'monthly'>('daily');

    const dailyOptions: ApexOptions = {
        colors: ["#3170c4", "#f59e0b"],
        chart: {
            fontFamily: "Outfit, sans-serif",
            type: "bar",
            height: 350,
            toolbar: {
                show: false,
            },
            sparkline: {
                enabled: false,
            },
        },
        plotOptions: {
            bar: {
                columnWidth: "50%",
                borderRadiusApplication: "end",
                borderRadius: 6,
            },
        },
        states: {
            hover: {
                filter: {
                    type: "darken",
                    value: 0.15,
                },
            },
            active: {
                filter: {
                    type: "darken",
                    value: 0.15,
                },
            },
        },
        xaxis: {
            axisBorder: {
                show: false,
            },
            axisTicks: {
                show: false,
            },
            labels: {
                style: {
                    colors: "#6B7280",
                    fontSize: "13px",
                    fontWeight: 500,
                },
            },
            categories: ["Mon", "Tue", "Wed", "Thu", "Fri", "Sat", "Sun"],
        },
        yaxis: {
            labels: {
                style: {
                    colors: "#6B7280",
                    fontSize: "13px",
                    fontWeight: 500,
                },
            },
        },
        grid: {
            yaxis: {
                lines: {
                    show: true,
                },
            },
        },
        fill: {
            opacity: 1,
        },
        legend: {
            show: true,
            position: "top",
            fontSize: 13,
        },
        tooltip: {
            x: {
                show: true,
            },
            y: {
                formatter: (val: number) => `$${val}k`,
            },
        },
    };

    const monthlOptions: ApexOptions = {
        colors: ["#3170c4", "#f59e0b"],
        chart: {
            fontFamily: "Outfit, sans-serif",
            type: "bar",
            height: 350,
            toolbar: {
                show: false,
            },
            sparkline: {
                enabled: false,
            },
        },
        plotOptions: {
            bar: {
                columnWidth: "50%",
                borderRadiusApplication: "end",
                borderRadius: 6,
            },
        },
        states: {
            hover: {
                filter: {
                    type: "darken",
                    value: 0.15,
                },
            },
            active: {
                filter: {
                    type: "darken",
                    value: 0.15,
                },
            },
        },
        xaxis: {
            axisBorder: {
                show: false,
            },
            axisTicks: {
                show: false,
            },
            labels: {
                style: {
                    colors: "#6B7280",
                    fontSize: "13px",
                    fontWeight: 500,
                },
            },
            categories: ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"],
        },
        yaxis: {
            labels: {
                style: {
                    colors: "#6B7280",
                    fontSize: "13px",
                    fontWeight: 500,
                },
            },
        },
        grid: {
            yaxis: {
                lines: {
                    show: true,
                },
            },
        },
        fill: {
            opacity: 1,
        },
        legend: {
            show: true,
            position: "top",
            fontSize: 13,
        },
        tooltip: {
            x: {
                show: true,
            },
            y: {
                formatter: (val: number) => `$${val}k`,
            },
        },
    };

    const dailySeries = [
        {
            name: "Sales",
            data: [12, 18, 15, 22, 28, 25, 30],
        },
        {
            name: "Repairs",
            data: [8, 12, 10, 14, 16, 14, 18],
        },
    ];

    const monthlySeries = [
        {
            name: "Sales",
            data: [65, 72, 78, 85, 92, 88, 95, 102, 98, 108, 115, 120],
        },
        {
            name: "Repairs",
            data: [45, 52, 48, 58, 65, 62, 70, 78, 75, 85, 92, 100],
        },
    ];

    return (
        <div className="overflow-hidden rounded-2xl border border-gray-200 bg-white px-5 pt-5 dark:border-gray-800 dark:bg-white/[0.03] sm:px-6 sm:pt-6">
            <div className="flex items-center justify-between mb-6">
                <div>
                    <h3 className="text-lg font-semibold text-gray-800 dark:text-white/90">
                        Daily / Monthly Performance
                    </h3>
                    <p className="text-sm text-gray-500 dark:text-gray-400 mt-1">
                        Sales and repairs performance overview
                    </p>
                </div>
                <div className="flex gap-2">
                    <button
                        onClick={() => setChartView('daily')}
                        className={`px-4 py-2 rounded-lg text-sm font-medium transition-all ${
                            chartView === 'daily'
                                ? 'bg-blue-500 text-white'
                                : 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-700'
                        }`}
                    >
                        Daily
                    </button>
                    <button
                        onClick={() => setChartView('monthly')}
                        className={`px-4 py-2 rounded-lg text-sm font-medium transition-all ${
                            chartView === 'monthly'
                                ? 'bg-blue-500 text-white'
                                : 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-700'
                        }`}
                    >
                        Monthly
                    </button>
                </div>
            </div>

            <div className="max-w-full overflow-x-auto custom-scrollbar">
                <div className="-ml-5 min-w-[650px] xl:min-w-full pl-2">
                    {chartView === 'daily' ? (
                        <Chart options={dailyOptions} series={dailySeries} type="bar" height={350} />
                    ) : (
                        <Chart options={monthlOptions} series={monthlySeries} type="bar" height={350} />
                    )}
                </div>
            </div>
        </div>
    );
};

// Leave Approval Widget Component
const LeaveApprovalWidget: React.FC = () => {
    const [pendingLeaves, setPendingLeaves] = useState<PendingLeave[]>([]);
    const [loading, setLoading] = useState(true);
    const [processingId, setProcessingId] = useState<number | null>(null);

    useEffect(() => {
        fetchPendingLeaves();
    }, []);

    const fetchPendingLeaves = async () => {
        try {
            setLoading(true);
            const response = await fetch('/api/leave/pending/all', {
                credentials: 'include',
            });
            
            if (!response.ok) throw new Error('Failed to fetch pending leaves');
            
            const data = await response.json();
            setPendingLeaves(data.requests || []);
        } catch (error) {
            console.error('Error fetching pending leaves:', error);
        } finally {
            setLoading(false);
        }
    };

    const handleApprove = async (leaveId: number, employeeName: string) => {
        const result = await Swal.fire({
            title: 'Approve Leave Request?',
            html: `Approve leave request for <strong>${employeeName}</strong>?`,
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#10b981',
            cancelButtonColor: '#6b7280',
            confirmButtonText: 'Yes, approve',
        });

        if (result.isConfirmed) {
            try {
                setProcessingId(leaveId);
                const response = await fetch(`/api/leave/${leaveId}/approve`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                    },
                    credentials: 'include',
                });

                if (!response.ok) throw new Error('Failed to approve leave');

                Swal.fire({
                    title: 'Approved!',
                    text: 'Leave request has been approved',
                    icon: 'success',
                    timer: 2000,
                });

                fetchPendingLeaves();
            } catch (error) {
                Swal.fire('Error', 'Failed to approve leave request', 'error');
            } finally {
                setProcessingId(null);
            }
        }
    };

    const handleReject = async (leaveId: number, employeeName: string) => {
        const result = await Swal.fire({
            title: 'Reject Leave Request?',
            html: `Reject leave request for <strong>${employeeName}</strong>?<br><br>Please provide a reason:`,
            input: 'textarea',
            inputPlaceholder: 'Enter rejection reason...',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#ef4444',
            cancelButtonColor: '#6b7280',
            confirmButtonText: 'Yes, reject',
            inputValidator: (value) => {
                if (!value) {
                    return 'You must provide a reason for rejection';
                }
            },
        });

        if (result.isConfirmed && result.value) {
            try {
                setProcessingId(leaveId);
                const response = await fetch(`/api/leave/${leaveId}/reject`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                    },
                    credentials: 'include',
                    body: JSON.stringify({ rejection_reason: result.value }),
                });

                if (!response.ok) throw new Error('Failed to reject leave');

                Swal.fire({
                    title: 'Rejected',
                    text: 'Leave request has been rejected',
                    icon: 'success',
                    timer: 2000,
                });

                fetchPendingLeaves();
            } catch (error) {
                Swal.fire('Error', 'Failed to reject leave request', 'error');
            } finally {
                setProcessingId(null);
            }
        }
    };

    const formatDate = (dateString: string) => {
        return new Date(dateString).toLocaleDateString('en-US', {
            month: 'short',
            day: 'numeric',
            year: 'numeric',
        });
    };

    return (
        <div className="bg-white dark:bg-white/[0.03] border border-gray-200 dark:border-gray-800 rounded-2xl shadow-sm overflow-hidden">
            <div className="px-6 py-4 border-b border-gray-200 dark:border-gray-700 flex items-center justify-between">
                <div>
                    <h2 className="text-lg font-semibold text-gray-900 dark:text-white">Pending Leave Requests</h2>
                    <p className="text-sm text-gray-600 dark:text-gray-400 mt-0.5">
                        {pendingLeaves.length} request{pendingLeaves.length !== 1 ? 's' : ''} awaiting approval
                    </p>
                </div>
                {pendingLeaves.length > 0 && (
                    <span className="inline-flex items-center px-3 py-1 text-xs font-medium bg-orange-100 text-orange-800 dark:bg-orange-900/30 dark:text-orange-400 rounded-full">
                        {pendingLeaves.length} Pending
                    </span>
                )}
            </div>
            
            <div className="divide-y divide-gray-200 dark:divide-gray-700 max-h-96 overflow-y-auto">
                {loading ? (
                    <div className="flex items-center justify-center py-12">
                        <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600"></div>
                    </div>
                ) : pendingLeaves.length === 0 ? (
                    <div className="text-center py-12">
                        <svg className="mx-auto h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        <p className="mt-2 text-gray-600 dark:text-gray-400">No pending leave requests</p>
                    </div>
                ) : (
                    pendingLeaves.map((leave) => (
                        <div key={leave.id} className="p-4 hover:bg-gray-50 dark:hover:bg-gray-900/30 transition-colors">
                            <div className="flex items-start justify-between gap-4">
                                <div className="flex-1 min-w-0">
                                    <div className="flex items-center gap-2 mb-1">
                                        <h3 className="text-sm font-semibold text-gray-900 dark:text-white truncate">
                                            {leave.employee.name}
                                        </h3>
                                        <span className="inline-flex items-center px-2 py-0.5 text-xs font-medium bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400 rounded">
                                            {leave.leave_type_label}
                                        </span>
                                    </div>
                                    <p className="text-xs text-gray-500 dark:text-gray-400 mb-2">
                                        {leave.employee.position}
                                    </p>
                                    <div className="flex items-center gap-4 text-xs text-gray-600 dark:text-gray-400 mb-2">
                                        <span className="flex items-center gap-1">
                                            <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                            </svg>
                                            {formatDate(leave.start_date)} - {formatDate(leave.end_date)}
                                        </span>
                                        <span className="font-medium">{leave.no_of_days} day{leave.no_of_days !== 1 ? 's' : ''}</span>
                                    </div>
                                    <p className="text-xs text-gray-600 dark:text-gray-300 line-clamp-2">
                                        {leave.reason}
                                    </p>
                                    {leave.days_pending > 2 && (
                                        <p className="text-xs text-orange-600 dark:text-orange-400 mt-1">
                                            ⚠️ Pending for {leave.days_pending} days
                                        </p>
                                    )}
                                </div>
                                <div className="flex flex-col gap-2 flex-shrink-0">
                                    <button
                                        onClick={() => handleApprove(leave.id, leave.employee.name)}
                                        disabled={processingId === leave.id}
                                        className="px-3 py-1.5 bg-green-600 text-white text-xs font-medium rounded-lg hover:bg-green-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
                                    >
                                        {processingId === leave.id ? 'Processing...' : 'Approve'}
                                    </button>
                                    <button
                                        onClick={() => handleReject(leave.id, leave.employee.name)}
                                        disabled={processingId === leave.id}
                                        className="px-3 py-1.5 bg-red-600 text-white text-xs font-medium rounded-lg hover:bg-red-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
                                    >
                                        Reject
                                    </button>
                                </div>
                            </div>
                        </div>
                    ))
                )}
            </div>
        </div>
    );
};

// Approval Summary Widget
interface ApprovalSummaryProps {
    approvalSummary?: ManagerDashboardStats['approvalSummary'];
}

const ApprovalSummaryWidget = ({ approvalSummary }: ApprovalSummaryProps) => {
    if (!approvalSummary) return null;

    const { expenses, leave_requests } = approvalSummary;
    const totalPending = expenses.count + leave_requests.count;

    if (totalPending === 0) {
        return (
            <div className="bg-white dark:bg-white/[0.03] border border-gray-200 dark:border-gray-800 rounded-2xl shadow-sm overflow-hidden">
                <div className="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                    <h2 className="text-lg font-semibold text-gray-900 dark:text-white">Approval Summary</h2>
                </div>
                <div className="p-6 text-center text-gray-500 dark:text-gray-400">
                    <svg className="mx-auto h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <p className="mt-2">All approvals are up to date!</p>
                </div>
            </div>
        );
    }

    return (
        <div className="bg-white dark:bg-white/[0.03] border border-gray-200 dark:border-gray-800 rounded-2xl shadow-sm overflow-hidden">
            <div className="px-6 py-4 border-b border-gray-200 dark:border-gray-700 flex items-center justify-between">
                <div>
                    <h2 className="text-lg font-semibold text-gray-900 dark:text-white">Approval Summary</h2>
                    <p className="text-sm text-gray-600 dark:text-gray-400 mt-0.5">
                        {totalPending} item{totalPending !== 1 ? 's' : ''} awaiting approval
                    </p>
                </div>
                <span className="inline-flex items-center px-3 py-1 text-xs font-medium bg-orange-100 text-orange-800 dark:bg-orange-900/30 dark:text-orange-400 rounded-full">
                    {totalPending} Pending
                </span>
            </div>

            <div className="p-6 space-y-4">
                {/* Expense Approvals */}
                {expenses.count > 0 && (
                    <div className="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg p-4">
                        <div className="flex items-center justify-between mb-2">
                            <h3 className="text-sm font-semibold text-blue-900 dark:text-blue-200">Expense Approvals</h3>
                            <span className="text-xs font-medium text-blue-700 dark:text-blue-300">{expenses.count} pending</span>
                        </div>
                        <div className="flex items-baseline gap-2">
                            <span className="text-2xl font-bold text-blue-900 dark:text-blue-100">
                                ₱{expenses.total_amount.toLocaleString()}
                            </span>
                            <span className="text-sm text-blue-700 dark:text-blue-300">total amount</span>
                        </div>
                        <a 
                            href="/erp/finance/expenses" 
                            className="mt-3 inline-flex items-center text-sm font-medium text-blue-700 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300"
                        >
                            Review expenses →
                        </a>
                    </div>
                )}

                {/* Leave Request Approvals */}
                {leave_requests.count > 0 && (
                    <div className="bg-purple-50 dark:bg-purple-900/20 border border-purple-200 dark:border-purple-800 rounded-lg p-4">
                        <div className="flex items-center justify-between mb-3">
                            <h3 className="text-sm font-semibold text-purple-900 dark:text-purple-200">Leave Requests</h3>
                            <span className="text-xs font-medium text-purple-700 dark:text-purple-300">{leave_requests.count} pending</span>
                        </div>
                        
                        {leave_requests.details.slice(0, 3).map((leave) => (
                            <div key={leave.id} className="mb-3 last:mb-0 pb-3 last:pb-0 border-b last:border-b-0 border-purple-200 dark:border-purple-800">
                                <div className="flex items-start justify-between">
                                    <div className="flex-1 min-w-0">
                                        <p className="text-sm font-medium text-purple-900 dark:text-purple-100 truncate">
                                            {leave.employee_name}
                                        </p>
                                        <p className="text-xs text-purple-700 dark:text-purple-300 mt-0.5">
                                            {leave.leave_type} • {leave.no_of_days} day{leave.no_of_days !== 1 ? 's' : ''}
                                        </p>
                                        <p className="text-xs text-purple-600 dark:text-purple-400 mt-1">
                                            {new Date(leave.start_date).toLocaleDateString()} - {new Date(leave.end_date).toLocaleDateString()}
                                        </p>
                                    </div>
                                    <span className="ml-2 text-xs text-purple-600 dark:text-purple-400 whitespace-nowrap">
                                        {leave.days_pending} day{leave.days_pending !== 1 ? 's' : ''} ago
                                    </span>
                                </div>
                            </div>
                        ))}
                        
                        {leave_requests.count > 3 && (
                            <p className="text-xs text-purple-600 dark:text-purple-400 mt-2">
                                + {leave_requests.count - 3} more request{leave_requests.count - 3 !== 1 ? 's' : ''}
                            </p>
                        )}
                    </div>
                )}
            </div>
        </div>
    );
};

// Recent Activities Widget
interface RecentActivitiesProps {
    activities?: ManagerDashboardStats['recentActivities'];
}

const RecentActivitiesWidget = ({ activities }: RecentActivitiesProps) => {
    if (!activities || activities.length === 0) {
        return (
            <div className="bg-white dark:bg-white/[0.03] border border-gray-200 dark:border-gray-800 rounded-2xl shadow-sm overflow-hidden">
                <div className="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                    <h2 className="text-lg font-semibold text-gray-900 dark:text-white">Recent Activities</h2>
                </div>
                <div className="p-6 text-center text-gray-500 dark:text-gray-400">
                    <p>No recent activities</p>
                </div>
            </div>
        );
    }

    const getActionColor = (action: string) => {
        switch (action.toLowerCase()) {
            case 'created': return 'text-blue-600 bg-blue-100 dark:bg-blue-900/30 dark:text-blue-400';
            case 'updated': return 'text-yellow-600 bg-yellow-100 dark:bg-yellow-900/30 dark:text-yellow-400';
            case 'approved': return 'text-green-600 bg-green-100 dark:bg-green-900/30 dark:text-green-400';
            case 'rejected': return 'text-red-600 bg-red-100 dark:bg-red-900/30 dark:text-red-400';
            case 'posted': return 'text-purple-600 bg-purple-100 dark:bg-purple-900/30 dark:text-purple-400';
            default: return 'text-gray-600 bg-gray-100 dark:bg-gray-800 dark:text-gray-400';
        }
    };

    const getActionIcon = (action: string) => {
        switch (action.toLowerCase()) {
            case 'created':
                return <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4v16m8-8H4" />;
            case 'updated':
                return <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />;
            case 'approved':
                return <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />;
            case 'rejected':
                return <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z" />;
            case 'posted':
                return <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" />;
            default:
                return <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />;
        }
    };

    return (
        <div className="bg-white dark:bg-white/[0.03] border border-gray-200 dark:border-gray-800 rounded-2xl shadow-sm overflow-hidden">
            <div className="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                <h2 className="text-lg font-semibold text-gray-900 dark:text-white">Recent Activities</h2>
                <p className="text-sm text-gray-600 dark:text-gray-400 mt-0.5">
                    Latest actions from your team
                </p>
            </div>

            <div className="p-6">
                <div className="flow-root">
                    <ul role="list" className="-mb-8">
                        {activities.map((activity, activityIdx) => (
                            <li key={activity.id}>
                                <div className="relative pb-8">
                                    {activityIdx !== activities.length - 1 ? (
                                        <span
                                            className="absolute top-5 left-5 -ml-px h-full w-0.5 bg-gray-200 dark:bg-gray-700"
                                            aria-hidden="true"
                                        />
                                    ) : null}
                                    <div className="relative flex items-start space-x-3">
                                        <div className="relative">
                                            <div className={`h-10 w-10 rounded-full flex items-center justify-center ring-8 ring-white dark:ring-gray-900 ${getActionColor(activity.action)}`}>
                                                <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                    {getActionIcon(activity.action)}
                                                </svg>
                                            </div>
                                        </div>
                                        <div className="min-w-0 flex-1">
                                            <div>
                                                <div className="text-sm">
                                                    <span className="font-medium text-gray-900 dark:text-white">
                                                        {activity.user_name}
                                                    </span>
                                                </div>
                                                <p className="mt-0.5 text-sm text-gray-600 dark:text-gray-400">
                                                    {activity.description}
                                                </p>
                                                <p className="mt-0.5 text-xs text-gray-500 dark:text-gray-500">
                                                    {activity.time_ago}
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </li>
                        ))}
                    </ul>
                </div>
            </div>
        </div>
    );
};

export default function ManagerDashboard() {
    const { auth } = usePage().props as any;
    const { data: statsData, isLoading, error, isError } = useManagerStats();
    const [clockedIn, setClockedIn] = useState(false);
    const [loginTime, setLoginTime] = useState<string | null>(null);
    const [logoutTime, setLogoutTime] = useState<string | null>(null);
    const [lunchBreakTime, setLunchBreakTime] = useState<string | null>(null);
    const [showAttendanceModal, setShowAttendanceModal] = useState(false);

    const handleClockIn = () => {
        const now = new Date();
        const timeString = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
        setLoginTime(timeString);
        setClockedIn(true);
        setLogoutTime(null);
        setLunchBreakTime(null);
    };

    const handleLunchBreak = () => {
        if (!clockedIn) return;
        const now = new Date();
        const timeString = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
        setLunchBreakTime(timeString);
    };

    const handleClockOut = () => {
        const now = new Date();
        const timeString = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
        setLogoutTime(timeString);
        setClockedIn(false);
    };

    const computeTotalHours = (timeIn: string | null, timeOut: string | null): string => {
        if (!timeIn || !timeOut) return '--';
        try {
            const timeInDate = new Date(`1970-01-01 ${timeIn}`);
            const timeOutDate = new Date(`1970-01-01 ${timeOut}`);
            const diff = timeOutDate.getTime() - timeInDate.getTime();
            const hours = Math.floor(diff / (1000 * 60 * 60));
            const minutes = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60));
            return `${hours}h ${minutes}m`;
        } catch {
            return '--';
        }
    };

    // Show loading state
    if (isLoading) {
        return (
            <AppLayoutERP>
                <Head title="Manager Dashboard - Solespace ERP" />
                <div className="py-8 px-6">
                    <div className="flex items-center justify-center min-h-[400px]">
                        <div className="text-center">
                            <div className="inline-block animate-spin rounded-full h-12 w-12 border-4 border-blue-500 border-t-transparent"></div>
                            <p className="mt-4 text-gray-600 dark:text-gray-400">Loading dashboard...</p>
                        </div>
                    </div>
                </div>
            </AppLayoutERP>
        );
    }

    // Show error state
    if (isError || !statsData) {
        return (
            <AppLayoutERP>
                <Head title="Manager Dashboard - Solespace ERP" />
                <div className="py-8 px-6">
                    <div className="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg p-6">
                        <div className="flex items-start">
                            <div className="flex-shrink-0">
                                <svg className="h-6 w-6 text-red-600 dark:text-red-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                            </div>
                            <div className="ml-3">
                                <h3 className="text-sm font-medium text-red-800 dark:text-red-200">
                                    Failed to Load Dashboard
                                </h3>
                                <div className="mt-2 text-sm text-red-700 dark:text-red-300">
                                    <p>{error?.message || 'Unable to fetch dashboard statistics. Please try refreshing the page.'}</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </AppLayoutERP>
        );
    }

    const stats: ManagerDashboardStats = {
        totalSales: statsData.totalSales,
        totalRepairs: statsData.totalRepairs,
        pendingJobOrders: statsData.pendingJobOrders,
        salesChange: statsData.salesChange,
        activeStaff: statsData.activeStaff,
        pendingApprovals: statsData.pendingApprovals,
    };

    const metrics: MetricCardProps[] = [
        {
            title: 'Total Sales',
            value: `₱${stats.totalSales.toLocaleString()}`,
            change: stats.salesChange || 0,
            changeType: (stats.salesChange || 0) >= 0 ? 'increase' : 'decrease',
            icon: DollarIconSvg,
            color: 'success',
            description: 'Total posted sales revenue',
        },
        {
            title: 'Total Repairs',
            value: stats.totalRepairs,
            change: 8,
            changeType: 'increase',
            icon: TaskIconSvg,
            color: 'info',
            description: 'Completed repair jobs',
        },
        {
            title: 'Pending Job Orders',
            value: stats.pendingJobOrders,
            change: 5,
            changeType: stats.pendingJobOrders > 50 ? 'increase' : 'decrease',
            icon: AlertIconSvg,
            color: 'warning',
            description: 'Jobs awaiting action',
        },
    ];

    return (
        <AppLayoutERP>
            <Head title="Manager Dashboard - Solespace ERP" />

            <div className="py-8 px-6">
                {/* Header */}
                <div className="mb-8">
                    <div className="flex items-center justify-between">
                        <div>
                            <h1 className="text-3xl font-bold text-gray-900 dark:text-white mb-2">
                                Manager Dashboard
                            </h1>
                            <p className="text-gray-600 dark:text-gray-400">
                                Hello, {auth?.user?.name}! Here's your management overview.
                            </p>
                        </div>
                        <div className="text-sm text-gray-500 dark:text-gray-400">
                            <div className="flex items-center gap-2">
                                <div className="w-2 h-2 bg-green-500 rounded-full animate-pulse"></div>
                                <span>Live data • Auto-refresh every 30s</span>
                            </div>
                            {statsData?.lastUpdated && (
                                <p className="text-xs mt-1">
                                    Last updated: {new Date(statsData.lastUpdated).toLocaleTimeString()}
                                </p>
                            )}
                        </div>
                    </div>
                </div>

                {/* Metrics */}
                <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 mb-8">
                    {metrics.map((metric) => (
                        <MetricCard
                            key={metric.title}
                            title={metric.title}
                            value={metric.value}
                            change={metric.change}
                            changeType={metric.changeType}
                            icon={metric.icon}
                            color={metric.color}
                            description={metric.description}
                        />
                    ))}
                </div>

                {/* Performance Chart */}
                <div className="mb-8">
                    <PerformanceChart />
                </div>

                {/* (Removed) Pending Leave Requests, Approval Summary, and Recent Activities */}

                {/* Summary Section */}
                <div className="bg-white dark:bg-white/[0.03] border border-gray-200 dark:border-gray-800 rounded-2xl shadow-sm overflow-hidden">
                    <div className="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                        <h2 className="text-lg font-semibold text-gray-900 dark:text-white">Key Metrics Summary</h2>
                    </div>
                    <div className="p-6 space-y-4">
                        {stats.activeStaff !== undefined && (
                            <>
                                <div className="flex items-center justify-between">
                                    <span className="text-gray-600 dark:text-gray-400">Active Staff</span>
                                    <span className="text-lg font-bold text-gray-900 dark:text-white">{stats.activeStaff}</span>
                                </div>
                                <div className="h-px bg-gray-200 dark:bg-gray-700" />
                            </>
                        )}
                        {stats.pendingApprovals !== undefined && (
                            <>
                                <div className="flex items-center justify-between">
                                    <span className="text-gray-600 dark:text-gray-400">Pending Approvals</span>
                                    <span className={`text-lg font-bold ${stats.pendingApprovals > 0 ? 'text-orange-600' : 'text-green-600'}`}>
                                        {stats.pendingApprovals}
                                    </span>
                                </div>
                                <div className="h-px bg-gray-200 dark:bg-gray-700" />
                            </>
                        )}
                        <div className="flex items-center justify-between">
                            <span className="text-gray-600 dark:text-gray-400">Total Orders</span>
                            <span className="text-lg font-bold text-gray-900 dark:text-white">
                                {stats.totalRepairs + stats.pendingJobOrders}
                            </span>
                        </div>
                        <div className="h-px bg-gray-200 dark:bg-gray-700" />
                        <div className="flex items-center justify-between">
                            <span className="text-gray-600 dark:text-gray-400">Completion Rate</span>
                            <span className="text-lg font-bold text-green-600">
                                {stats.totalRepairs > 0 
                                    ? `${((stats.totalRepairs / (stats.totalRepairs + stats.pendingJobOrders)) * 100).toFixed(1)}%`
                                    : '0%'
                                }
                            </span>
                        </div>
                    </div>
                </div>

                {/* Attendance History Modal */}
                {showAttendanceModal && (
                    <div 
                        className="fixed inset-0 bg-black/50 backdrop-blur-md flex items-center justify-center p-4 z-[99999]"
                        style={{ backdropFilter: 'blur(10px)' }}
                        onClick={() => setShowAttendanceModal(false)}
                    >
                        <div 
                            className="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl max-w-5xl w-full h-[80vh] overflow-hidden flex flex-col"
                            onClick={(e) => e.stopPropagation()}
                        >
                            {/* Modal Header */}
                            <div className="px-6 py-4 border-b border-gray-200 dark:border-gray-700 flex items-center justify-between">
                                <h2 className="text-xl font-bold text-gray-900 dark:text-white">Attendance History</h2>
                                <button
                                    onClick={() => setShowAttendanceModal(false)}
                                    className="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors"
                                >
                                    <svg className="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                                    </svg>
                                </button>
                            </div>

                            {/* Modal Body */}
                            <div className="flex-1 overflow-y-auto p-6">
                                <div className="bg-white dark:bg-gray-800 rounded-lg overflow-hidden border border-gray-200 dark:border-gray-700">
                                    <table className="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                                        <thead className="bg-gray-50 dark:bg-gray-900">
                                            <tr>
                                                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Date</th>
                                                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Time In</th>
                                                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Lunch Break</th>
                                                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Time Out</th>
                                                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Total Hours</th>
                                                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Status</th>
                                            </tr>
                                        </thead>
                                        <tbody className="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                                            {/* Sample data */}
                                            <tr>
                                                <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">Feb 4, 2026</td>
                                                <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">{loginTime || '--'}</td>
                                                <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">{lunchBreakTime || '--'}</td>
                                                <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">{logoutTime || '--'}</td>
                                                <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">{computeTotalHours(loginTime, logoutTime)}</td>
                                                <td className="px-6 py-4 whitespace-nowrap">
                                                    <span className={`px-2 py-1 text-xs font-semibold rounded-full ${
                                                        clockedIn 
                                                            ? 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400' 
                                                            : logoutTime 
                                                            ? 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300'
                                                            : 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-400'
                                                    }`}>
                                                        {clockedIn ? 'Active' : logoutTime ? 'Completed' : 'Pending'}
                                                    </span>
                                                </td>
                                            </tr>
                                            {/* Previous records can go here */}
                                            <tr>
                                                <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">Feb 3, 2026</td>
                                                <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">08:00:00 AM</td>
                                                <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">12:00:00 PM</td>
                                                <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">05:00:00 PM</td>
                                                <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">9h 0m</td>
                                                <td className="px-6 py-4 whitespace-nowrap">
                                                    <span className="px-2 py-1 text-xs font-semibold rounded-full bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300">
                                                        Completed
                                                    </span>
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            {/* Modal Footer */}
                            <div className="px-6 py-4 border-t border-gray-200 dark:border-gray-700 flex justify-end gap-3">
                                <button
                                    onClick={() => {
                                        // Export functionality
                                        const data = `Date,Time In,Lunch Break,Time Out,Total Hours,Status\nFeb 4, 2026,${loginTime || '--'},${lunchBreakTime || '--'},${logoutTime || '--'},${computeTotalHours(loginTime, logoutTime)},${clockedIn ? 'Active' : logoutTime ? 'Completed' : 'Pending'}\nFeb 3, 2026,08:00:00 AM,12:00:00 PM,05:00:00 PM,9h 0m,Completed`;
                                        const blob = new Blob([data], { type: 'text/csv' });
                                        const url = window.URL.createObjectURL(blob);
                                        const a = document.createElement('a');
                                        a.href = url;
                                        a.download = `attendance-${new Date().getTime()}.csv`;
                                        a.click();
                                    }}
                                    className="px-4 py-2 bg-green-500 hover:bg-green-600 text-white rounded-lg font-medium transition-all duration-200 text-sm flex items-center gap-2 active:scale-95"
                                >
                                    <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                                    </svg>
                                    Export
                                </button>
                                <button
                                    onClick={() => setShowAttendanceModal(false)}
                                    className="px-4 py-2 bg-gray-200 hover:bg-gray-300 dark:bg-gray-700 dark:hover:bg-gray-600 text-gray-900 dark:text-white rounded-lg font-medium transition-all duration-200 text-sm"
                                >
                                    Close
                                </button>
                            </div>
                        </div>
                    </div>
                )}
            </div>
        </AppLayoutERP>
    );
}
