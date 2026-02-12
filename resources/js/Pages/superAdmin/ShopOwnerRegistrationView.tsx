import React, { useState } from "react";
import { Head, Link, router } from "@inertiajs/react";
import AppLayout from "../../layout/AppLayout";
import Swal from 'sweetalert2';
import Button from "../../components/ui/button/Button";

// Icon Components
const CheckCircleIcon = ({ className = "" }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
  </svg>
);

const EyeIcon = ({ className = "" }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
  </svg>
);

const UserIcon = ({ className = "" }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
  </svg>
);

const CalenderIcon = ({ className = "" }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
  </svg>
);

const DocsIcon = ({ className = "" }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
  </svg>
);

const TimeIcon = ({ className = "" }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
  </svg>
);

const AlertIcon = ({ className = "" }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
  </svg>
);

const InfoIcon = ({ className = "" }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
  </svg>
);

const ArrowUpIcon = ({ className = "" }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 10l7-7m0 0l7 7m-7-7v18" />
  </svg>
);

const ArrowDownIcon = ({ className = "" }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 14l-7 7m0 0l-7-7m7 7V3" />
  </svg>
);

interface OperatingHours {
  day: string;
  open: string;
  close: string;
}

interface Registration {
  id: number;
  firstName: string;
  lastName: string;
  email: string;
  phone: string;
  businessName: string;
  businessAddress: string;
  businessType: string;
  serviceType: string;
  operatingHours: OperatingHours[];
  documentUrls: string[];
  status: "pending" | "approved" | "rejected";
  createdAt: string;
}

interface MetricData {
  title: string;
  value: number;
  change: number;
  changeType: 'increase' | 'decrease';
  icon: React.ComponentType<{ className?: string }>;
  color: 'success' | 'error' | 'warning' | 'info';
  description: string;
}

// Professional Metric Card Component
const MetricCard: React.FC<MetricData> = ({
  title,
  value,
  change,
  changeType,
  icon: Icon,
  color,
  description
}) => {
  const getColorClasses = () => {
    switch (color) {
      case 'success': return 'from-green-500 to-emerald-600';
      case 'error': return 'from-red-500 to-rose-600';
      case 'warning': return 'from-yellow-500 to-orange-600';
      case 'info': return 'from-blue-500 to-indigo-600';
      default: return 'from-gray-500 to-gray-600';
    }
  };

  return (
    <div className="group relative overflow-hidden rounded-2xl border border-gray-200 bg-white p-6 shadow-sm transition-all duration-500 hover:shadow-xl hover:border-gray-300 hover:-translate-y-1 dark:border-gray-800 dark:bg-white/[0.03] dark:hover:border-gray-700">
      {/* Animated background gradient */}
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
            {value.toLocaleString()}
          </h3>
          <p className="text-xs text-gray-500 dark:text-gray-400">
            {description}
          </p>
        </div>
      </div>
    </div>
  );
};

export default function ShopOwnerRegistrationView({ registrations = [] }: { registrations?: Registration[] }) {
  console.log('Registrations received:', registrations);
  const [expandedCardId, setExpandedCardId] = useState<number | null>(null);
  const [expandedDocuments, setExpandedDocuments] = useState<Set<number>>(new Set());
  const [registrationsState, setRegistrationsState] = useState<Registration[]>(registrations);
  const [searchTerm, setSearchTerm] = useState<string>('');
  const [filterStatus, setFilterStatus] = useState<'pending' | 'approved' | 'rejected'>('pending');
  const [isViewModalOpen, setIsViewModalOpen] = useState(false);
  const [selectedRegistration, setSelectedRegistration] = useState<Registration | null>(null);
  const [isRejectModalOpen, setIsRejectModalOpen] = useState(false);
  const [rejectionReason, setRejectionReason] = useState('');
  const [registrationToReject, setRegistrationToReject] = useState<Registration | null>(null);
  const [lightboxUrl, setLightboxUrl] = useState<string | null>(null);
  const [currentPage, setCurrentPage] = useState(1);
  const [itemsPerPage] = useState(7);

  const handleApprove = async (id: number) => {
    const result = await Swal.fire({
      title: 'Approve Registration?',
      text: 'Are you sure you want to approve this shop owner registration?',
      icon: 'question',
      showCancelButton: true,
      confirmButtonColor: '#10b981',
      cancelButtonColor: '#6b7280',
      confirmButtonText: 'Yes, approve',
      cancelButtonText: 'Cancel'
    });

    if (result.isConfirmed) {
      router.post(`/superAdmin/shop-owner-registration/${id}/approve`, {}, {
        onSuccess: () => {
          setRegistrationsState(prev =>
            prev.map(reg => reg.id === id ? { ...reg, status: "approved" as const } : reg)
          );
          Swal.fire({
            icon: 'success',
            title: 'Approved!',
            text: 'Shop owner registration has been approved successfully.',
            timer: 2000,
            showConfirmButton: false
          });
        },
        onError: (errors) => {
          console.error('Approval error:', errors);
          Swal.fire({
            icon: 'error',
            title: 'Error',
            text: 'Failed to approve registration. Please try again.'
          });
        }
      });
    }
  };

  const handleReject = (registration: Registration) => {
    setRegistrationToReject(registration);
    setRejectionReason('');
    setIsRejectModalOpen(true);
  };

  const handleConfirmReject = async () => {
    if (!registrationToReject) return;

    // Close the modal first to avoid z-index conflicts
    setIsRejectModalOpen(false);

    const result = await Swal.fire({
      title: 'Confirm Rejection',
      text: `Are you sure you want to reject ${registrationToReject.businessName}'s application?`,
      icon: 'warning',
      showCancelButton: true,
      confirmButtonColor: '#ef4444',
      cancelButtonColor: '#6b7280',
      confirmButtonText: 'Yes, reject',
      cancelButtonText: 'Cancel',
      customClass: {
        popup: 'swal2-popup-custom'
      }
    });

    if (result.isConfirmed) {
      router.post(`/superAdmin/shop-owner-registration/${registrationToReject.id}/reject`,
        {
          rejection_reason: rejectionReason
        },
        {
          onSuccess: () => {
            setRegistrationsState(prev =>
              prev.map(reg => reg.id === registrationToReject.id ? { ...reg, status: "rejected" as const } : reg)
            );
            setRegistrationToReject(null);
            setRejectionReason('');
            Swal.fire({
              icon: 'success',
              title: 'Rejected!',
              text: 'Application has been rejected successfully.',
              timer: 2000,
              showConfirmButton: false,
              customClass: {
                popup: 'swal2-popup-custom'
              }
            });
          },
          onError: (errors) => {
            console.error('Rejection error:', errors);
            Swal.fire({
              icon: 'error',
              title: 'Error',
              text: 'Failed to reject application. Please try again.',
              customClass: {
                popup: 'swal2-popup-custom'
              }
            });
          }
        }
      );
    } else {
      // If user cancels, reopen the modal
      setIsRejectModalOpen(true);
    }
  };

  // Filter registrations based on status and search term
  const filteredRegistrations = registrationsState.filter(reg => {
    const statusMatch = reg.status === filterStatus;
    const searchMatch = searchTerm === '' ||
      reg.businessName.toLowerCase().includes(searchTerm.toLowerCase()) ||
      reg.email.toLowerCase().includes(searchTerm.toLowerCase()) ||
      `${reg.firstName} ${reg.lastName}`.toLowerCase().includes(searchTerm.toLowerCase());
    return statusMatch && searchMatch;
  });

  // Pagination calculations
  const totalPages = Math.ceil(filteredRegistrations.length / itemsPerPage);
  const startIndex = (currentPage - 1) * itemsPerPage;
  const endIndex = startIndex + itemsPerPage;
  const paginatedRegistrations = filteredRegistrations.slice(startIndex, endIndex);

  // Reset to page 1 when filters change
  React.useEffect(() => {
    setCurrentPage(1);
  }, [searchTerm, filterStatus]);

  return (
    <AppLayout>
      <Head title="Shop Owner Registration Management" />
      <div className="min-h-screen bg-gray-50 dark:bg-gray-900 p-6">
        <div className="max-w-7xl mx-auto">
          {/* Header */}
          <div className="flex justify-between items-start mb-8">
            <div>
              <h1 className="text-3xl font-bold text-gray-900 dark:text-white mb-2">
                Shop Owner Registration Management
              </h1>
              <p className="text-gray-600 dark:text-gray-400">
                Manage shop owner registrations, approvals, and verifications
              </p>
            </div>
          </div>

          {/* Stats Cards */}
          <div className="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
            <MetricCard
              title="Total Applications"
              value={registrationsState.length}
              change={12}
              changeType="increase"
              icon={UserIcon}
              color="info"
              description="Total shop owner applications"
            />
            <MetricCard
              title="Pending Reviews"
              value={registrationsState.filter(r => r.status === 'pending').length}
              change={5}
              changeType="decrease"
              icon={TimeIcon}
              color="warning"
              description="Awaiting approval"
            />
            <MetricCard
              title="Approved"
              value={registrationsState.filter(r => r.status === 'approved').length}
              change={8}
              changeType="increase"
              icon={CheckCircleIcon}
              color="success"
              description="Successfully approved"
            />
            <MetricCard
              title="Rejected"
              value={registrationsState.filter(r => r.status === 'rejected').length}
              change={2}
              changeType="increase"
              icon={AlertIcon}
              color="error"
              description="Application rejected"
            />
          </div>

          {/* Filters */}
          <div className="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-6 mb-8">
            <div className="flex flex-wrap gap-4">
              <div>
                <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                  Search Applications
                </label>
                <input
                  type="text"
                  value={searchTerm}
                  onChange={(e) => setSearchTerm(e.target.value)}
                  placeholder="Search by business name or email..."
                  aria-label="Search Applications"
                  className="px-9 py-2 border border-gray-300 dark:border-gray-600 rounded-md focus:ring-2 focus:ring-blue-500 focus:border-transparent dark:bg-gray-700 dark:text-white"
                />
              </div>

              <div>
                <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                  Filter by Status
                </label>
                <select
                  value={filterStatus}
                  onChange={(e) => setFilterStatus(e.target.value as 'pending' | 'approved' | 'rejected')}
                  aria-label="Filter by Status"
                  className="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md focus:ring-2 focus:ring-blue-500 focus:border-transparent dark:bg-gray-700 dark:text-white"
                >
                  <option value="pending">Pending</option>
                  <option value="approved">Approved</option>
                  <option value="rejected">Rejected</option>
                </select>
              </div>
            </div>
          </div>

          {/* Registrations Table */}
          <div className="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden">
            <div className="p-6 border-b border-gray-200 dark:border-gray-700">
              <h3 className="text-lg font-semibold text-gray-900 dark:text-white">
                Shop Owner Applications ({filteredRegistrations.length})
              </h3>
            </div>
            <div className="overflow-auto">
              <table className="w-full">
                <thead className="bg-gray-50 dark:bg-gray-700">
                  <tr>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Business Name</th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Owner</th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Business Type</th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Status</th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Created</th>
                    <th className="px-6 py-4 text-center text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Actions</th>
                  </tr>
                </thead>
                <tbody className="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                  {paginatedRegistrations.map((reg) => (
                    <tr key={reg.id}>
                      <td className="px-6 py-4 whitespace-nowrap">
                        <div className="flex items-center">
                          <div className="flex-shrink-0 h-10 w-10">
                            <div className="h-10 w-10 rounded-full bg-blue-100 dark:bg-blue-900 flex items-center justify-center">
                              <DocsIcon className="h-6 w-6 text-blue-600 dark:text-blue-400" />
                            </div>
                          </div>
                          <div className="ml-4">
                            <div className="text-sm font-medium text-gray-900 dark:text-white">{reg.businessName}</div>
                            <div className="text-sm text-gray-500 dark:text-gray-400">{reg.email}</div>
                          </div>
                        </div>
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap">
                        <div className="text-sm text-gray-900 dark:text-white">{reg.firstName} {reg.lastName}</div>
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap">
                        <span className="inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200">
                          {reg.businessType}
                        </span>
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap">
                        <span className={`inline-flex px-2 py-1 text-xs font-semibold rounded-full ${
                          reg.status === 'pending' ? 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200' :
                          reg.status === 'approved' ? 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200' :
                          'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200'
                        }`}>
                          {reg.status}
                        </span>
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                        {new Date(reg.createdAt).toLocaleDateString()}
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap text-center text-sm font-medium">
                        <div className="flex justify-center space-x-2">
                          <button
                            onClick={() => {
                              setSelectedRegistration(reg);
                              setIsViewModalOpen(true);
                            }}
                            className="text-blue-600 hover:text-blue-900 dark:text-blue-400 dark:hover:text-blue-300"
                            title="View Details"
                          >
                            <EyeIcon className="h-5 w-5" />
                          </button>
                          {/* Action icons for approve/reject were removed per request. */}
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
              {filteredRegistrations.length === 0 && (
                <div className="text-center py-12">
                  <CheckCircleIcon className="mx-auto h-12 w-12 text-gray-400 mb-4" />
                  <h3 className="text-lg font-medium text-gray-900 dark:text-white mb-2">No applications found</h3>
                  <p className="text-gray-600 dark:text-gray-400">
                    There are no {filterStatus} shop owner registrations to review.
                  </p>
                </div>
              )}
            </div>

            {/* Pagination */}
            {filteredRegistrations.length > 0 && (
              <div className="px-6 py-4 border-t border-gray-200 dark:border-gray-700">
                <div className="flex items-center justify-between">
                  <div className="text-sm text-gray-700 dark:text-gray-300">
                    Showing <span className="font-medium">{startIndex + 1}</span> to{" "}
                    <span className="font-medium">{Math.min(endIndex, filteredRegistrations.length)}</span> of{" "}
                    <span className="font-medium">{filteredRegistrations.length}</span>
                  </div>
                  <div className="flex items-center gap-2">
                    <button
                      onClick={() => setCurrentPage((prev) => Math.max(prev - 1, 1))}
                      disabled={currentPage === 1}
                      className="p-2 rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
                      title="Previous page"
                    >
                      <svg className="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7" />
                      </svg>
                    </button>

                    {Array.from({ length: totalPages }, (_, i) => i + 1).map((page) => {
                      // Show first page, last page, current page, and pages around current
                      if (
                        page === 1 ||
                        page === totalPages ||
                        (page >= currentPage - 1 && page <= currentPage + 1)
                      ) {
                        return (
                          <button
                            key={page}
                            onClick={() => setCurrentPage(page)}
                            className={`min-w-[40px] h-10 px-3 rounded-lg font-medium transition-colors ${
                              currentPage === page
                                ? "bg-blue-600 text-white"
                                : "border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800"
                            }`}
                          >
                            {page}
                          </button>
                        );
                      } else if (page === currentPage - 2 || page === currentPage + 2) {
                        return (
                          <span key={page} className="px-2 text-gray-500 dark:text-gray-400">
                            ...
                          </span>
                        );
                      }
                      return null;
                    })}

                    <button
                      onClick={() => setCurrentPage((prev) => Math.min(prev + 1, totalPages))}
                      disabled={currentPage === totalPages}
                      className="p-2 rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
                      title="Next page"
                    >
                      <svg className="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" />
                      </svg>
                    </button>
                  </div>
                </div>
              </div>
            )}
          </div>

          {/* View Details Modal */}
          {isViewModalOpen && selectedRegistration && (
            <div className="fixed inset-0 z-[999999] bg-black/60 backdrop-blur-sm flex items-center justify-center px-4 py-8">
              <div className="relative bg-white dark:bg-gray-800 rounded-2xl shadow-2xl max-w-4xl w-full max-h-[90vh] overflow-y-auto">
                <div className="sticky top-0 bg-white dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700 px-6 py-4 flex justify-between items-center">
                  <h3 className="text-xl font-semibold text-gray-900 dark:text-white">
                    {selectedRegistration.businessName} - Registration Details
                  </h3>
                  <button
                    onClick={() => setIsViewModalOpen(false)}
                    className="text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300 text-2xl leading-none font-light"
                    aria-label="Close modal"
                  >
                    ×
                  </button>
                </div>
                <div className="p-6">
                  <div className="space-y-6">
                    <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                      {/* Left Column */}
                      <div className="space-y-6">
                        {/* Personal Information */}
                        <div>
                          <h4 className="text-lg font-medium text-gray-900 dark:text-white mb-3 flex items-center">
                            <UserIcon className="w-5 h-5 mr-2" />
                            Personal Information
                          </h4>
                          <div className="space-y-3 bg-gray-50 dark:bg-gray-700 p-4 rounded-lg">
                            <div>
                              <label className="block text-xs font-semibold text-gray-600 dark:text-gray-400 uppercase">First Name</label>
                              <p className="text-sm text-gray-900 dark:text-white">{selectedRegistration.firstName}</p>
                            </div>
                            <div>
                              <label className="block text-xs font-semibold text-gray-600 dark:text-gray-400 uppercase">Last Name</label>
                              <p className="text-sm text-gray-900 dark:text-white">{selectedRegistration.lastName}</p>
                            </div>
                            <div>
                              <label className="block text-xs font-semibold text-gray-600 dark:text-gray-400 uppercase">Email</label>
                              <p className="text-sm text-gray-900 dark:text-white">{selectedRegistration.email}</p>
                            </div>
                            <div>
                              <label className="block text-xs font-semibold text-gray-600 dark:text-gray-400 uppercase">Phone</label>
                              <p className="text-sm text-gray-900 dark:text-white">{selectedRegistration.phone}</p>
                            </div>
                          </div>
                        </div>

                        {/* Business Information */}
                        <div>
                          <h4 className="text-lg font-medium text-gray-900 dark:text-white mb-3 flex items-center">
                            <DocsIcon className="w-5 h-5 mr-2" />
                            Business Information
                          </h4>
                          <div className="space-y-3 bg-gray-50 dark:bg-gray-700 p-4 rounded-lg">
                            <div>
                              <label className="block text-xs font-semibold text-gray-600 dark:text-gray-400 uppercase">Business Name</label>
                              <p className="text-sm text-gray-900 dark:text-white">{selectedRegistration.businessName}</p>
                            </div>
                            <div>
                              <label className="block text-xs font-semibold text-gray-600 dark:text-gray-400 uppercase">Business Type</label>
                              <p className="text-sm text-gray-900 dark:text-white">{selectedRegistration.businessType}</p>
                            </div>
                            <div>
                              <label className="block text-xs font-semibold text-gray-600 dark:text-gray-400 uppercase">Address</label>
                              <p className="text-sm text-gray-900 dark:text-white">{selectedRegistration.businessAddress}</p>
                            </div>
                          </div>
                        </div>
                      </div>

                      {/* Right Column - Documents */}
                      <div>
                        <h4 className="text-lg font-medium text-gray-900 dark:text-white mb-3 flex items-center">
                          <DocsIcon className="w-5 h-5 mr-2" />
                          Submitted Documents
                        </h4>
                        <div className="space-y-3 bg-gray-50 dark:bg-gray-700 p-4 rounded-lg max-h-96 overflow-y-auto">
                          {selectedRegistration.documentUrls && selectedRegistration.documentUrls.length > 0 ? (
                            selectedRegistration.documentUrls.map((url, index) => (
                              <div key={index} className="border border-gray-200 dark:border-gray-600 rounded-lg p-3 bg-white dark:bg-gray-800">
                                <div className="flex items-center justify-between mb-2">
                                  <div className="flex items-center space-x-2">
                                    <div className="w-8 h-8 bg-blue-100 dark:bg-blue-900 rounded flex items-center justify-center">
                                      <DocsIcon className="w-4 h-4 text-blue-600 dark:text-blue-400" />
                                    </div>
                                    <div>
                                      <p className="text-xs font-semibold text-gray-900 dark:text-white">
                                        Document {index + 1}
                                      </p>
                                      <p className="text-xs text-gray-500 dark:text-gray-400">
                                        {['BIR Registration', 'Valid ID', 'Business Permit', 'Tax Certificate'][index % 4]}
                                      </p>
                                    </div>
                                  </div>
                                  <button
                                    onClick={() => {
                                      const newExpanded = new Set(expandedDocuments);
                                      if (newExpanded.has(index)) {
                                        newExpanded.delete(index);
                                      } else {
                                        newExpanded.add(index);
                                      }
                                      setExpandedDocuments(newExpanded);
                                    }}
                                    className="text-blue-600 dark:text-blue-400 hover:text-blue-800 dark:hover:text-blue-300 text-xs font-medium"
                                  >
                                    {expandedDocuments.has(index) ? 'Hide' : 'View'}
                                  </button>
                                </div>
                                {expandedDocuments.has(index) && (
                                  <div className="mt-2 pt-2 border-t border-gray-200 dark:border-gray-600">
                                    <div className="bg-gray-100 dark:bg-gray-900 rounded border border-gray-300 dark:border-gray-600 flex items-center justify-center" style={{ minHeight: '200px' }}>
                                      <img
                                        src={url}
                                        alt={`Document ${index + 1}`}
                                        className="max-w-full max-h-96 rounded cursor-pointer hover:opacity-95"
                                        onClick={() => setLightboxUrl(url)}
                                        onError={(e) => {
                                          e.currentTarget.style.display = 'none';
                                          const parent = e.currentTarget.parentElement;
                                          if (parent) parent.innerHTML = '<p class="text-sm text-gray-500 dark:text-gray-400 text-center">Unable to load image</p>';
                                        }}
                                      />
                                    </div>
                                  </div>
                                )}
                              </div>
                            ))
                          ) : (
                            <p className="text-sm text-gray-500 dark:text-gray-400 text-center py-4">
                              No documents uploaded
                            </p>
                          )}
                        </div>
                      </div>
                    </div>

                    {/* Operating Hours removed per request */}

                    {/* Action Buttons */}
                    <div className="pt-4 border-t border-gray-200 dark:border-gray-700">
                      <div className="flex justify-end gap-3">
                        {selectedRegistration.status === 'pending' && (
                          <>
                            <Button
                              onClick={() => {
                                handleReject(selectedRegistration);
                                setIsViewModalOpen(false);
                              }}
                              className="bg-red-600 hover:bg-red-700 text-white"
                            >
                              <AlertIcon className="h-4 w-4 mr-2" />
                              Reject
                            </Button>
                            <Button
                              onClick={() => {
                                handleApprove(selectedRegistration.id);
                                setIsViewModalOpen(false);
                              }}
                              className="bg-green-600 hover:bg-green-700 text-white"
                            >
                              <CheckCircleIcon className="h-4 w-4 mr-2" />
                              Approve
                            </Button>
                          </>
                        )}
                        <Button variant="outline" onClick={() => setIsViewModalOpen(false)}>
                          Close
                        </Button>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          )}

          {/* Reject Modal */}
          {isRejectModalOpen && registrationToReject && (
            <div className="fixed inset-0 z-[999999] bg-black/60 backdrop-blur-sm flex items-center justify-center px-4 py-8">
              <div className="relative bg-white dark:bg-gray-800 rounded-2xl shadow-2xl max-w-md w-full">
                <div className="sticky top-0 bg-white dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700 px-6 py-4 flex justify-between items-center">
                  <h3 className="text-xl font-semibold text-gray-900 dark:text-white">
                    Reject Application
                  </h3>
                  <button
                    onClick={() => setIsRejectModalOpen(false)}
                    className="text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300 text-2xl leading-none font-light"
                    aria-label="Close modal"
                  >
                    ×
                  </button>
                </div>
                <div className="p-6">
                  <div className="space-y-4">
                    <div>
                      <p className="text-sm text-gray-600 dark:text-gray-400 mb-4">
                        Please provide a reason for rejecting <strong>{registrationToReject.businessName}</strong>'s application:
                      </p>

                      <div className="space-y-3">
                        <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                          Select a reason or enter custom reason:
                        </label>

                        <div className="space-y-2">
                          {[
                            "Incomplete documentation",
                            "Invalid business information",
                            "Business type not eligible",
                            "Duplicate application",
                            "Verification failed",
                            "Other"
                          ].map((reason) => (
                            <label key={reason} className="flex items-center space-x-3 cursor-pointer">
                              <input
                                type="radio"
                                name="rejectionReason"
                                value={reason}
                                checked={rejectionReason === reason}
                                onChange={(e) => setRejectionReason(e.target.value)}
                                className="h-4 w-4 text-red-600 focus:ring-red-500 border-gray-300 dark:border-gray-600"
                              />
                              <span className="text-sm text-gray-700 dark:text-gray-300">{reason}</span>
                            </label>
                          ))}
                        </div>

                        <div className="mt-4">
                          <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                            Additional Details (Optional)
                          </label>
                          <textarea
                            value={rejectionReason.startsWith('Incomplete documentation') ||
                                   rejectionReason.startsWith('Invalid business information') ||
                                   rejectionReason.startsWith('Business type not eligible') ||
                                   rejectionReason.startsWith('Duplicate application') ||
                                   rejectionReason.startsWith('Verification failed') ||
                                   rejectionReason === 'Other' ? '' : rejectionReason}
                            onChange={(e) => {
                              if (e.target.value) {
                                setRejectionReason(e.target.value);
                              }
                            }}
                            placeholder="Enter additional details or custom reason..."
                            rows={3}
                            className="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md focus:ring-2 focus:ring-red-500 focus:border-transparent dark:bg-gray-700 dark:text-white resize-none"
                          />
                        </div>
                      </div>
                    </div>
                    <div className="flex justify-end gap-3 pt-4">
                      <Button
                        variant="secondary"
                        onClick={() => setIsRejectModalOpen(false)}
                      >
                        Cancel
                      </Button>
                      <Button
                        onClick={handleConfirmReject}
                        className="bg-red-600 hover:bg-red-700 text-white"
                        disabled={!rejectionReason.trim()}
                      >
                        <AlertIcon className="h-4 w-4 mr-2" />
                        Reject Application
                      </Button>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          )}
        </div>
      </div>

      {/* Lightbox modal for viewing documents */}
      {lightboxUrl && (
        <div
          className="fixed inset-0 z-[9999999] flex items-center justify-center bg-black/70 p-4"
          role="dialog"
          aria-modal="true"
          onClick={() => setLightboxUrl(null)}
        >
          <div className="relative max-w-[90%] max-h-[90%]">
            <button
              onClick={() => setLightboxUrl(null)}
              className="absolute top-2 right-2 z-10 bg-white/90 rounded-full p-2 shadow hover:bg-white"
              aria-label="Close preview"
            >
              ×
            </button>
            <img
              src={lightboxUrl}
              alt="Document preview"
              className="max-w-full max-h-[90vh] rounded shadow-lg mx-auto"
              onClick={(e) => e.stopPropagation()}
            />
          </div>
        </div>
      )}

    </AppLayout>
  );
}
