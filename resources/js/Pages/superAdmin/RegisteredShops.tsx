import React, { useState } from 'react';
import { createPortal } from 'react-dom';
import { router, usePage } from '@inertiajs/react';
import Swal from 'sweetalert2';
import AppLayout from '../../layout/AppLayout';

// Portal to render overlays above layout/nav stacking contexts
const ModalPortal: React.FC<{ children: React.ReactNode }> = ({ children }) => {
  if (typeof document === 'undefined') return null;
  return createPortal(children, document.body);
};

// Icon Components
const StoreIcon = ({ className }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z" />
  </svg>
);

const SearchIcon = ({ className }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
  </svg>
);

const FilterIcon = ({ className }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z" />
  </svg>
);

const EyeIcon = ({ className }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
  </svg>
);

const BanIcon = ({ className }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636" />
  </svg>
);

const TrashIcon = ({ className }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
  </svg>
);

const CheckCircleIcon = ({ className }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
  </svg>
);

const AlertIcon = ({ className }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
  </svg>
);

const ArrowUpIcon = ({ className }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 10l7-7m0 0l7 7m-7-7v18" />
  </svg>
);

const ArrowDownIcon = ({ className }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 14l-7 7m0 0l-7-7m7 7V3" />
  </svg>
);

// Professional Metric Card Component
const MetricCard = ({ title, value, change, changeType, icon: Icon, color, description }) => {
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

function RegisteredShops({ shops, stats }) {
  const { props } = usePage();
  const auth = (props as any).auth;
  const isSuperAdmin = auth?.user?.role === 'super_admin';
  
  const [searchQuery, setSearchQuery] = useState('');
  const [filterType, setFilterType] = useState('all');
  const [filterStatus, setFilterStatus] = useState('all');
  const [selectedShop, setSelectedShop] = useState(null);
  const [isSuspendModalOpen, setIsSuspendModalOpen] = useState(false);
  const [shopToSuspend, setShopToSuspend] = useState(null);
  const [selectedSuspensionReason, setSelectedSuspensionReason] = useState('');
  const [otherReasonText, setOtherReasonText] = useState('');
  const [expandedDocuments, setExpandedDocuments] = useState(new Set());
  const [currentPage, setCurrentPage] = useState(1);
  const [itemsPerPage] = useState(7);

  // Filter shops based on search and filters
  const filteredShops = shops.filter(shop => {
    const matchesSearch = 
      shop.business_name?.toLowerCase().includes(searchQuery.toLowerCase()) ||
      shop.email?.toLowerCase().includes(searchQuery.toLowerCase()) ||
      shop.first_name?.toLowerCase().includes(searchQuery.toLowerCase()) ||
      shop.last_name?.toLowerCase().includes(searchQuery.toLowerCase());

    const matchesType = filterType === 'all' || shop.business_type === filterType;
    const matchesStatus = filterStatus === 'all' || shop.status === filterStatus;

    return matchesSearch && matchesType && matchesStatus;
  });

  // Pagination calculations
  const totalPages = Math.ceil(filteredShops.length / itemsPerPage);
  const startIndex = (currentPage - 1) * itemsPerPage;
  const endIndex = startIndex + itemsPerPage;
  const paginatedShops = filteredShops.slice(startIndex, endIndex);

  // Reset to page 1 when filters change
  React.useEffect(() => {
    setCurrentPage(1);
  }, [searchQuery, filterType, filterStatus]);

  const handleViewDetails = (shop) => {
    setSelectedShop(shop);
  };

  const handleSuspendShop = (shopId, shopName) => {
    const shop = shops.find(s => s.id === shopId);
    setShopToSuspend(shop);
    setIsSuspendModalOpen(true);
    setSelectedSuspensionReason('');
    setOtherReasonText('');
  };

  const confirmSuspendShop = () => {
    let reason = '';
    
    if (selectedSuspensionReason === 'Other') {
      reason = otherReasonText.trim();
      if (!reason) {
        Swal.fire({
          icon: 'error',
          title: 'Error',
          text: 'Please specify the reason for suspension',
          confirmButtonColor: '#ef4444',
        });
        return;
      }
    } else {
      reason = selectedSuspensionReason;
    }

    if (!reason) {
      Swal.fire({
        icon: 'error',
        title: 'Error',
        text: 'Please select a reason for suspension',
        confirmButtonColor: '#ef4444',
      });
      return;
    }

    router.post(`/admin/shops/${shopToSuspend.id}/suspend`, {
      suspension_reason: reason
    }, {
      onSuccess: () => {
        setIsSuspendModalOpen(false);
        setShopToSuspend(null);
        setSelectedSuspensionReason('');
        setOtherReasonText('');
        Swal.fire({
          title: 'Suspended!',
          text: 'Shop has been suspended successfully.',
          icon: 'success',
          confirmButtonColor: '#10b981',
        });
      },
      onError: () => {
        Swal.fire({
          title: 'Error',
          text: 'Failed to suspend shop. Please try again.',
          icon: 'error',
          confirmButtonColor: '#ef4444',
        });
      },
    });
  };

  const handleActivateShop = (shopId, shopName) => {
    Swal.fire({
      title: 'Activate Shop?',
      text: `Are you sure you want to activate "${shopName}"?`,
      icon: 'question',
      showCancelButton: true,
      confirmButtonColor: '#10b981',
      cancelButtonColor: '#6b7280',
      confirmButtonText: 'Yes, activate',
      cancelButtonText: 'Cancel',
    }).then((result) => {
      if (result.isConfirmed) {
        router.post(`/admin/shops/${shopId}/activate`, {}, {
          onSuccess: () => {
            Swal.fire({
              title: 'Activated!',
              text: 'Shop has been activated successfully.',
              icon: 'success',
              confirmButtonColor: '#10b981',
            });
          },
          onError: () => {
            Swal.fire({
              title: 'Error',
              text: 'Failed to activate shop. Please try again.',
              icon: 'error',
              confirmButtonColor: '#ef4444',
            });
          },
        });
      }
    });
  };

  const handleDeleteShop = (shopId, shopName) => {
    Swal.fire({
      title: 'Delete Shop?',
      html: `Are you sure you want to permanently delete "<strong>${shopName}</strong>"?<br><br><span class="text-red-600">This action cannot be undone!</span>`,
      icon: 'warning',
      showCancelButton: true,
      confirmButtonColor: '#ef4444',
      cancelButtonColor: '#6b7280',
      confirmButtonText: 'Yes, delete permanently',
      cancelButtonText: 'Cancel',
    }).then((result) => {
      if (result.isConfirmed) {
        router.delete(`/admin/shops/${shopId}`, {
          onSuccess: () => {
            Swal.fire({
              title: 'Deleted!',
              text: 'Shop has been permanently deleted.',
              icon: 'success',
              confirmButtonColor: '#10b981',
            });
          },
          onError: () => {
            Swal.fire({
              title: 'Error',
              text: 'Failed to delete shop. Please try again.',
              icon: 'error',
              confirmButtonColor: '#ef4444',
            });
          },
        });
      }
    });
  };

  const getStatusBadge = (status) => {
    const badges = {
      approved: 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300',
      suspended: 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-300',
      pending: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-300',
    };
    return badges[status] || badges.pending;
  };

  const getTypeBadge = (type) => {
    const badges = {
      retail: 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-300',
      repair: 'bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-300',
      both: 'bg-indigo-100 text-indigo-800 dark:bg-indigo-900 dark:text-indigo-300',
    };
    return badges[type] || badges.retail;
  };

  return (
    <AppLayout>
      <div className="space-y-8 p-6 md:p-8">
        {/* Header Section */}
        <div className="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
          <div>
            <h1 className="text-3xl font-bold text-gray-900 dark:text-white">Registered Shops</h1>
            <p className="text-gray-600 dark:text-gray-400 mt-2">
              Manage all active and suspended shop accounts
            </p>
          </div>
        </div>

        {/* Stats Cards */}
        <div className="grid grid-cols-1 md:grid-cols-4 gap-6">
          <MetricCard
            title="Total Shops"
            value={stats?.total || 0}
            change={12}
            changeType="increase"
            icon={StoreIcon}
            color="info"
            description="Total registered shops"
          />
          <MetricCard
            title="Active Shops"
            value={stats?.active || 0}
            change={8}
            changeType="increase"
            icon={CheckCircleIcon}
            color="success"
            description="Currently operational"
          />
          <MetricCard
            title="Suspended"
            value={stats?.suspended || 0}
            change={-5}
            changeType="decrease"
            icon={BanIcon}
            color="error"
            description="Temporarily suspended"
          />
          <MetricCard
            title="This Month"
            value={stats?.thisMonth || 0}
            change={15}
            changeType="increase"
            icon={StoreIcon}
            color="warning"
            description="New registrations"
          />
        </div>

        {/* Search and Filter Section */}
        <div className="bg-white dark:bg-gray-800 rounded-xl p-6 border border-gray-200 dark:border-gray-700">
          <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
            {/* Search */}
            <div className="relative">
              <SearchIcon className="absolute left-3 top-1/2 transform -translate-y-1/2 w-5 h-5 text-gray-400" />
              <input
                type="text"
                placeholder="Search by shop name, owner, or email..."
                value={searchQuery}
                onChange={(e) => setSearchQuery(e.target.value)}
                className="w-full pl-10 pr-4 py-2.5 bg-gray-50 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 focus:border-transparent text-gray-900 dark:text-white"
              />
            </div>

            {/* Business Type Filter */}
            <div className="relative">
              <FilterIcon className="absolute left-3 top-1/2 transform -translate-y-1/2 w-5 h-5 text-gray-400" />
              <select
                value={filterType}
                onChange={(e) => setFilterType(e.target.value)}
                className="w-full pl-10 pr-4 py-2.5 bg-gray-50 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 focus:border-transparent text-gray-900 dark:text-white appearance-none"
              >
                <option value="all">All Business Types</option>
                <option value="retail">Retail Only</option>
                <option value="repair">Repair Only</option>
                <option value="both">Both (Retail & Repair)</option>
              </select>
            </div>

            {/* Status Filter */}
            <div className="relative">
              <FilterIcon className="absolute left-3 top-1/2 transform -translate-y-1/2 w-5 h-5 text-gray-400" />
              <select
                value={filterStatus}
                onChange={(e) => setFilterStatus(e.target.value)}
                className="w-full pl-10 pr-4 py-2.5 bg-gray-50 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 focus:border-transparent text-gray-900 dark:text-white appearance-none"
              >
                <option value="all">All Status</option>
                <option value="approved">Active</option>
                <option value="suspended">Suspended</option>
              </select>
            </div>
          </div>

          <div className="mt-4 flex items-center justify-between text-sm">
            <p className="text-gray-600 dark:text-gray-400">
              Showing {filteredShops.length} of {shops.length} shops
            </p>
            {(searchQuery || filterType !== 'all' || filterStatus !== 'all') && (
              <button
                onClick={() => {
                  setSearchQuery('');
                  setFilterType('all');
                  setFilterStatus('all');
                }}
                className="text-blue-600 dark:text-blue-400 hover:underline"
              >
                Clear filters
              </button>
            )}
          </div>
        </div>

        {/* Shops Table */}
        <div className="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
          <div className="overflow-x-auto">
            <table className="w-full">
              <thead className="bg-gray-50 dark:bg-gray-900/50">
                <tr>
                  <th className="px-6 py-4 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                    Shop Details
                  </th>
                  <th className="px-6 py-4 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                    Owner
                  </th>
                  <th className="px-6 py-4 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                    Contact
                  </th>
                  <th className="px-6 py-4 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                    Type
                  </th>
                  <th className="px-6 py-4 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                    Status
                  </th>
                  <th className="px-6 py-4 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                    Registered
                  </th>
                  <th className="px-6 py-4 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                    Actions
                  </th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-200 dark:divide-gray-700">
                {paginatedShops.length === 0 ? (
                  <tr>
                    <td colSpan="7" className="px-6 py-12 text-center">
                      <StoreIcon className="w-12 h-12 mx-auto text-gray-400 mb-4" />
                      <p className="text-gray-500 dark:text-gray-400">
                        {searchQuery || filterType !== 'all' || filterStatus !== 'all'
                          ? 'No shops found matching your filters'
                          : 'No registered shops yet'}
                      </p>
                    </td>
                  </tr>
                ) : (
                  paginatedShops.map((shop) => (
                    <tr key={shop.id} className="hover:bg-gray-50 dark:hover:bg-gray-700/50 transition-colors">
                      <td className="px-6 py-4">
                        <div>
                          <p className="font-semibold text-gray-900 dark:text-white">{shop.business_name}</p>
                          <p className="text-sm text-gray-500 dark:text-gray-400">{shop.business_address}</p>
                        </div>
                      </td>
                      <td className="px-6 py-4">
                        <p className="text-sm text-gray-900 dark:text-white">
                          {shop.first_name} {shop.last_name}
                        </p>
                      </td>
                      <td className="px-6 py-4">
                        <div className="text-sm">
                          <p className="text-gray-900 dark:text-white">{shop.email}</p>
                          <p className="text-gray-500 dark:text-gray-400">{shop.contact_number}</p>
                        </div>
                      </td>
                      <td className="px-6 py-4">
                        <span className={`inline-flex px-2.5 py-1 rounded-full text-xs font-medium ${getTypeBadge(shop.business_type)}`}>
                          {shop.business_type === 'both' ? 'Retail & Repair' : shop.business_type}
                        </span>
                      </td>
                      <td className="px-6 py-4">
                        <span className={`inline-flex px-2.5 py-1 rounded-full text-xs font-medium ${getStatusBadge(shop.status)}`}>
                          {shop.status === 'approved' ? 'Active' : shop.status}
                        </span>
                      </td>
                      <td className="px-6 py-4 text-sm text-gray-500 dark:text-gray-400">
                        {new Date(shop.created_at).toLocaleDateString()}
                      </td>
                      <td className="px-6 py-4 text-left">
                        <div className="flex gap-1 items-center">
                          <button
                            onClick={() => handleViewDetails(shop)}
                            className="inline-flex items-center justify-center w-8 h-8 rounded-md text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300 transition-colors"
                            title="View Details"
                          >
                            <EyeIcon className="h-5 w-5" />
                          </button>
                          {shop.status === 'approved' ? (
                            <button
                              onClick={() => handleSuspendShop(shop.id, shop.business_name)}
                              className="inline-flex items-center justify-center w-8 h-8 rounded-md text-orange-600 hover:text-orange-800 dark:text-orange-400 dark:hover:text-orange-300 transition-colors"
                              title="Suspend Shop"
                            >
                              <AlertIcon className="h-5 w-5" />
                            </button>
                          ) : (
                            <button
                              onClick={() => handleActivateShop(shop.id, shop.business_name)}
                              className="inline-flex items-center justify-center w-8 h-8 rounded-md text-green-600 hover:text-green-800 dark:text-green-400 dark:hover:text-green-300 transition-colors"
                              title="Activate Shop"
                            >
                              <CheckCircleIcon className="h-5 w-5" />
                            </button>
                          )}
                          {isSuperAdmin && (
                            <button
                              onClick={() => handleDeleteShop(shop.id, shop.business_name)}
                              className="inline-flex items-center justify-center w-8 h-8 rounded-md text-red-600 hover:text-red-800 dark:text-red-400 dark:hover:text-red-300 transition-colors"
                              title="Delete Shop (Super Admin Only)"
                            >
                              <TrashIcon className="h-5 w-5" />
                            </button>
                          )}
                        </div>
                      </td>
                    </tr>
                  ))
                )}
              </tbody>
            </table>
          </div>

          {/* Pagination */}
          {filteredShops.length > 0 && (
            <div className="px-6 py-4 border-t border-gray-200 dark:border-gray-700">
              <div className="flex items-center justify-between">
                <div className="text-sm text-gray-700 dark:text-gray-300">
                  Showing <span className="font-medium">{startIndex + 1}</span> to{" "}
                  <span className="font-medium">{Math.min(endIndex, filteredShops.length)}</span> of{" "}
                  <span className="font-medium">{filteredShops.length}</span>
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

        {/* Details Modal - Matching ShopOwnerRegistrationView */}
        {selectedShop && (
          <div className="fixed inset-0 z-[999999] bg-black/60 backdrop-blur-sm flex items-center justify-center px-4 py-8">
            <div className="relative bg-white dark:bg-gray-800 rounded-2xl shadow-2xl max-w-4xl w-full max-h-[90vh] overflow-y-auto">
              <div className="sticky top-0 bg-white dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700 px-6 py-4 flex justify-between items-center">
                <h3 className="text-xl font-semibold text-gray-900 dark:text-white">
                  {selectedShop.business_name} - Registration Details
                </h3>
                <button
                  onClick={() => setSelectedShop(null)}
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
                      {/* Shop Information */}
                      <div>
                        <h4 className="text-lg font-medium text-gray-900 dark:text-white mb-3">
                          Shop Information
                        </h4>
                        <div className="space-y-3 bg-gray-50 dark:bg-gray-700 p-4 rounded-lg">
                          <div>
                            <label className="block text-xs font-semibold text-gray-600 dark:text-gray-400 uppercase">Business Name</label>
                            <p className="text-sm text-gray-900 dark:text-white">{selectedShop.business_name}</p>
                          </div>
                          <div>
                            <label className="block text-xs font-semibold text-gray-600 dark:text-gray-400 uppercase">Business Type</label>
                            <p className="text-sm text-gray-900 dark:text-white capitalize">{selectedShop.business_type === 'both' ? 'Retail & Repair' : selectedShop.business_type}</p>
                          </div>
                          <div>
                            <label className="block text-xs font-semibold text-gray-600 dark:text-gray-400 uppercase">Address</label>
                            <p className="text-sm text-gray-900 dark:text-white">{selectedShop.business_address}</p>
                          </div>
                          <div>
                            <label className="block text-xs font-semibold text-gray-600 dark:text-gray-400 uppercase">Status</label>
                            <p className="text-sm mt-1">
                              <span className={`inline-flex px-3 py-1 rounded-full text-xs font-medium ${getStatusBadge(selectedShop.status)}`}>
                                {selectedShop.status === 'approved' ? 'Active' : selectedShop.status}
                              </span>
                            </p>
                          </div>
                        </div>
                      </div>

                      {/* Owner Information */}
                      <div>
                        <h4 className="text-lg font-medium text-gray-900 dark:text-white mb-3">
                          Owner Information
                        </h4>
                        <div className="space-y-3 bg-gray-50 dark:bg-gray-700 p-4 rounded-lg">
                          <div>
                            <label className="block text-xs font-semibold text-gray-600 dark:text-gray-400 uppercase">Owner Name</label>
                            <p className="text-sm text-gray-900 dark:text-white">{selectedShop.first_name} {selectedShop.last_name}</p>
                          </div>
                          <div>
                            <label className="block text-xs font-semibold text-gray-600 dark:text-gray-400 uppercase">Email</label>
                            <p className="text-sm text-gray-900 dark:text-white">{selectedShop.email}</p>
                          </div>
                          <div>
                            <label className="block text-xs font-semibold text-gray-600 dark:text-gray-400 uppercase">Phone</label>
                            <p className="text-sm text-gray-900 dark:text-white">{selectedShop.contact_number}</p>
                          </div>
                          <div>
                            <label className="block text-xs font-semibold text-gray-600 dark:text-gray-400 uppercase">Registered On</label>
                            <p className="text-sm text-gray-900 dark:text-white">
                              {new Date(selectedShop.created_at).toLocaleDateString('en-US', { 
                                year: 'numeric', 
                                month: 'long', 
                                day: 'numeric' 
                              })}
                            </p>
                          </div>
                        </div>
                      </div>

                      {/* Suspension Reason */}
                      {selectedShop.status === 'suspended' && selectedShop.suspension_reason && (
                        <div>
                          <h4 className="text-lg font-medium text-red-600 dark:text-red-400 mb-3">
                            Suspension Reason
                          </h4>
                          <div className="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 p-4 rounded-lg">
                            <p className="text-sm text-gray-900 dark:text-white">{selectedShop.suspension_reason}</p>
                          </div>
                        </div>
                      )}
                    </div>

                    {/* Right Column - Documents */}
                    <div>
                      <h4 className="text-lg font-medium text-gray-900 dark:text-white mb-3">
                        Submitted Documents
                      </h4>
                      <div className="space-y-3 bg-gray-50 dark:bg-gray-700 p-4 rounded-lg max-h-96 overflow-y-auto">
                        {selectedShop.documentUrls && selectedShop.documentUrls.length > 0 ? (
                          selectedShop.documentUrls.map((url, index) => (
                            <div key={index} className="border border-gray-200 dark:border-gray-600 rounded-lg p-3 bg-white dark:bg-gray-800">
                              <div className="flex items-center justify-between mb-2">
                                <div className="flex items-center space-x-2">
                                  <div className="w-8 h-8 bg-blue-100 dark:bg-blue-900 rounded flex items-center justify-center">
                                    <svg className="w-4 h-4 text-blue-600 dark:text-blue-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z" />
                                    </svg>
                                  </div>
                                  <div>
                                    <p className="text-xs font-semibold text-gray-900 dark:text-white">
                                      Document {index + 1}
                                    </p>
                                    <p className="text-xs text-gray-500 dark:text-gray-400">
                                      {url.split('/').pop()}
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
                                      className="max-w-full max-h-96 rounded"
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
                </div>

                {/* Operating Hours */}
                {selectedShop.operating_hours && selectedShop.operating_hours.length > 0 && (
                  <div>
                    <h4 className="text-lg font-medium text-gray-900 dark:text-white mb-3">
                      Operating Hours
                    </h4>
                    <div className="grid grid-cols-2 md:grid-cols-4 gap-2 bg-gray-50 dark:bg-gray-700 p-4 rounded-lg">
                      {selectedShop.operating_hours.map((hours, index) => (
                        <div key={index} className="bg-white dark:bg-gray-800 p-2 rounded text-center border border-gray-200 dark:border-gray-600">
                          <p className="text-xs font-semibold text-gray-600 dark:text-gray-400">{hours.day}</p>
                          <p className={`text-xs font-medium mt-1 ${
                            hours.open === "Closed"
                              ? "text-red-600 dark:text-red-400"
                              : "text-green-600 dark:text-green-400"
                          }`}>
                            {hours.open === "Closed" ? "Closed" : `${hours.open} - ${hours.close}`}
                          </p>
                        </div>
                      ))}
                    </div>
                  </div>
                )}
              </div>
            </div>
          </div>
        )}

        {/* Suspend Shop Modal */}
        {isSuspendModalOpen && shopToSuspend && (
          <ModalPortal>
            <div className="fixed inset-0 z-[999999] bg-black/60 backdrop-blur-sm flex items-center justify-center p-4">
              <div className="bg-white dark:bg-gray-800 rounded-2xl shadow-2xl max-w-2xl w-full">
                <div className="border-b border-gray-200 dark:border-gray-700 px-6 py-4 flex justify-between items-center">
                <h3 className="text-xl font-semibold text-gray-900 dark:text-white">
                  Suspend Shop
                </h3>
                <button
                  onClick={() => {
                    setIsSuspendModalOpen(false);
                    setShopToSuspend(null);
                    setSelectedSuspensionReason('');
                    setOtherReasonText('');
                  }}
                  className="text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300 text-2xl leading-none font-light"
                  aria-label="Close modal"
                >
                  ×
                </button>
              </div>
              
              <div className="p-6">
                <div className="mb-6">
                  <p className="text-gray-700 dark:text-gray-300 mb-2">
                    Are you sure you want to suspend <strong className="text-gray-900 dark:text-white">"{shopToSuspend.business_name}"</strong>?
                  </p>
                  <p className="text-sm text-red-600 dark:text-red-400">
                    They will not be able to access their account.
                  </p>
                </div>

                <div className="space-y-4">
                  <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">
                    Select reason for suspension:
                  </label>
                  
                  <div className="space-y-2">
                    {[
                      'Violation of Terms of Service',
                      'Fraudulent Activity',
                      'Customer Complaints',
                      'Inappropriate Content',
                      'Non-compliance with Policies',
                      'Other'
                    ].map((reason) => (
                      <label
                        key={reason}
                        className="flex items-start cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700 p-3 rounded-lg border border-gray-200 dark:border-gray-700 transition-colors"
                      >
                        <input
                          type="checkbox"
                          checked={selectedSuspensionReason === reason}
                          onChange={(e) => {
                            if (e.target.checked) {
                              setSelectedSuspensionReason(reason);
                              if (reason !== 'Other') {
                                setOtherReasonText('');
                              }
                            } else {
                              setSelectedSuspensionReason('');
                              setOtherReasonText('');
                            }
                          }}
                          className="mt-1 mr-3 h-4 w-4 text-red-600 focus:ring-red-500 border-gray-300 rounded"
                        />
                        <span className="text-sm text-gray-700 dark:text-gray-300">
                          {reason === 'Other' ? (
                            <span className="font-medium">{reason} (Please specify)</span>
                          ) : (
                            reason
                          )}
                        </span>
                      </label>
                    ))}
                  </div>

                  {selectedSuspensionReason === 'Other' && (
                    <div className="mt-4">
                      <textarea
                        value={otherReasonText}
                        onChange={(e) => setOtherReasonText(e.target.value)}
                        className="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-red-500 focus:border-transparent resize-none bg-white dark:bg-gray-700 text-gray-900 dark:text-white"
                        rows={4}
                        placeholder="Enter other reason..."
                      />
                    </div>
                  )}
                </div>

                <div className="flex gap-3 mt-6 pt-4 border-t border-gray-200 dark:border-gray-700">
                  <button
                    onClick={() => {
                      setIsSuspendModalOpen(false);
                      setShopToSuspend(null);
                      setSelectedSuspensionReason('');
                      setOtherReasonText('');
                    }}
                    className="flex-1 px-4 py-2.5 bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-300 dark:hover:bg-gray-600 font-medium transition-colors"
                  >
                    Cancel
                  </button>
                  <button
                    onClick={confirmSuspendShop}
                    className="flex-1 px-4 py-2.5 bg-red-600 text-white rounded-lg hover:bg-red-700 font-medium transition-colors"
                  >
                    Yes, suspend
                  </button>
                </div>
              </div>
            </div>
            </div>
          </ModalPortal>
        )}
      </div>
    </AppLayout>
  );
}

export default RegisteredShops;
