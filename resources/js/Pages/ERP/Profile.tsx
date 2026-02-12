import { useState } from "react";
import { Head, useForm, usePage } from "@inertiajs/react";
import { router } from "@inertiajs/react";
import Swal from "sweetalert2";
import AppLayoutERP from "../../layout/AppLayout_ERP";

interface PageProps {
    user: {
        id: number;
        name: string;
        email: string;
        role: string;
        first_name?: string;
        last_name?: string;
        phone?: string;
        // bio removed
        job_title?: string;
        country?: string;
        city?: string;
        postal_code?: string;
        tax_id?: string;
    };
    requiresPasswordChange: boolean;
}

export default function Profile({ user, requiresPasswordChange }: PageProps) {
    const { flash } = usePage().props as any;
    
    const { data, setData, post, processing, errors, reset } = useForm({
        current_password: "",
        password: "",
        password_confirmation: "",
    });

    const {
        data: personalData, 
        setData: setPersonalData, 
        processing: personalProcessing, 
        post: postPersonal 
    } = useForm({
        first_name: user.first_name || user.name?.split(' ')[0] || "",
        last_name: user.last_name || user.name?.split(' ').slice(1).join(' ') || "",
        email: user.email || "",
        phone: user.phone || "",
        // bio removed
        job_title: user.job_title || user.role || "",
    });

    // Password strength validation
    const validatePassword = (pwd: string) => {
        return {
            hasMinLength: pwd.length >= 8,
            hasUppercase: /[A-Z]/.test(pwd),
            hasLowercase: /[a-z]/.test(pwd),
            hasNumber: /[0-9]/.test(pwd),
        };
    };

    const passwordStrength = validatePassword(data.password);
    const isPasswordStrong = Object.values(passwordStrength).every(v => v);

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        
        post(route("erp.password.update"), {
            preserveScroll: true,
            onSuccess: () => {
                reset();
                // Determine destination based on role
                const role = user?.role?.toUpperCase();
                const isHR = role === "HR";
                const isFinance = role === "FINANCE_STAFF" || role === "FINANCE_MANAGER";
                const isCRM = role === "CRM";
                const isManager = role === "MANAGER";
                const isStaff = role === "STAFF";

                const confirmText = isHR
                    ? "Go to HR Dashboard"
                    : isFinance
                    ? "Go to Finance Dashboard"
                    : isCRM
                    ? "Go to CRM Dashboard"
                    : isManager
                    ? "Go to Manager Dashboard"
                    : isStaff
                    ? "Go to Staff Dashboard"
                    : "Continue";

                const destination = isHR
                    ? route('erp.hr')
                    : isFinance
                    ? route('finance.index')
                    : isCRM
                    ? route('crm.dashboard')
                    : isManager
                    ? route('erp.manager.dashboard')
                    : isStaff
                    ? route('erp.staff.dashboard')
                    : route('erp.profile');

                // Show success alert and redirect to the correct module
                Swal.fire({
                    title: "Success!",
                    text: "Your password has been changed successfully.",
                    icon: "success",
                    confirmButtonText: confirmText,
                    confirmButtonColor: "#2563eb",
                    allowOutsideClick: false,
                    allowEscapeKey: false,
                }).then(() => {
                    router.visit(destination);
                });
            },
            onError: () => {
                // Show error alert if something goes wrong
                Swal.fire({
                    title: "Error",
                    text: "Failed to update password. Please check your current password and try again.",
                    icon: "error",
                    confirmButtonText: "OK",
                    confirmButtonColor: "#ef4444",
                });
            },
        });
    };

    return (
        <AppLayoutERP>
            <Head title="Profile - Solespace ERP" />
            
            <div className="py-8 px-6 lg:px-8">
                {/* Profile Card */}
                <div className="bg-white dark:bg-gray-900 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-8 mb-8">
                    <div className="flex items-start">
                        <div className="flex items-center gap-6">
                            <div className="w-24 h-24 rounded-full bg-blue-100 dark:bg-blue-900/30 flex items-center justify-center flex-shrink-0">
                                <svg className="w-12 h-12 text-blue-600 dark:text-blue-400" fill="currentColor" viewBox="0 0 24 24">
                                    <path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z" />
                                </svg>
                            </div>
                            <div>
                                <h2 className="text-2xl font-bold text-gray-900 dark:text-white mb-1">
                                    {personalData.first_name} {personalData.last_name}
                                </h2>
                                <p className="text-gray-600 dark:text-gray-400 mb-1">
                                    {personalData.job_title}
                                </p>
                                {user.city && user.country && (
                                    <p className="text-gray-500 dark:text-gray-500 text-sm">
                                        {user.city}, {user.country}
                                    </p>
                                )}
                            </div>
                        </div>
                    </div>
                </div>

                {/* Personal Information Card */}
                <div className="bg-white dark:bg-gray-900 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden mb-8">
                    <div className="px-8 py-6 border-b border-gray-200 dark:border-gray-700">
                        <h3 className="text-xl font-bold text-gray-900 dark:text-white">
                            Personal Information
                        </h3>
                    </div>
                    <div className="px-8 py-6">
                        <div className="grid grid-cols-2 gap-8">
                            <div>
                                <label className="text-sm font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">First Name</label>
                                <p className="text-gray-900 dark:text-white font-medium mt-1">{personalData.first_name}</p>
                            </div>
                            <div>
                                <label className="text-sm font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Last Name</label>
                                <p className="text-gray-900 dark:text-white font-medium mt-1">{personalData.last_name}</p>
                            </div>
                            <div>
                                <label className="text-sm font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Email address</label>
                                <p className="text-gray-900 dark:text-white font-medium mt-1">{personalData.email}</p>
                            </div>
                            <div>
                                <label className="text-sm font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Phone</label>
                                <p className="text-gray-900 dark:text-white font-medium mt-1">{personalData.phone || "N/A"}</p>
                            </div>
                            {/* Bio removed */}
                        </div>
                    </div>
                </div>

                {/* Change Password Card */}
                <div className="bg-white dark:bg-gray-900 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden">
                    <div className="px-8 py-6 border-b border-gray-200 dark:border-gray-700">
                        <h3 className="text-xl font-bold text-gray-900 dark:text-white">
                            Change Password
                        </h3>
                    </div>
                    <div className="px-8 py-6">
                        {requiresPasswordChange && (
                            <div className="mb-6 p-4 bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-700 rounded-lg">
                                <p className="text-sm font-medium text-yellow-800 dark:text-yellow-200">
                                    ⚠️ You are required to change your password on first login.
                                </p>
                            </div>
                        )}

                        <form onSubmit={handleSubmit} className="space-y-6">
                            <div>
                                <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                    Current Password
                                </label>
                                <input
                                    type="password"
                                    value={data.current_password}
                                    onChange={(e) =>
                                        setData("current_password", e.target.value)
                                    }
                                    className={`w-full px-4 py-2 border rounded-lg shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-800 dark:border-gray-700 dark:text-white ${
                                        errors.current_password
                                            ? "border-red-500"
                                            : "border-gray-300 dark:border-gray-600"
                                    }`}
                                    disabled={processing}
                                />
                                {errors.current_password && (
                                    <p className="mt-1 text-sm text-red-600 dark:text-red-400">
                                        {errors.current_password}
                                    </p>
                                )}
                            </div>

                            <div>
                                <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                    New Password
                                </label>
                                <input
                                    type="password"
                                    value={data.password}
                                    onChange={(e) =>
                                        setData("password", e.target.value)
                                    }
                                    className={`w-full px-4 py-2 border rounded-lg shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-800 dark:border-gray-700 dark:text-white ${
                                        errors.password
                                            ? "border-red-500"
                                            : "border-gray-300 dark:border-gray-600"
                                    }`}
                                    disabled={processing}
                                />
                                {errors.password && (
                                    <p className="mt-1 text-sm text-red-600 dark:text-red-400">
                                        {errors.password}
                                    </p>
                                )}
                            </div>

                            <div>
                                <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                    Confirm Password
                                </label>
                                <input
                                    type="password"
                                    value={data.password_confirmation}
                                    onChange={(e) =>
                                        setData("password_confirmation", e.target.value)
                                    }
                                    className="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-800 dark:text-white"
                                    disabled={processing}
                                />
                            </div>

                            <div className="pt-4">
                                <button
                                    type="submit"
                                    disabled={processing}
                                    className="w-full bg-blue-600 hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed text-white font-medium py-3 px-4 rounded-lg transition"
                                >
                                    {processing ? "Updating..." : "Update Password"}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </AppLayoutERP>
    );
}
