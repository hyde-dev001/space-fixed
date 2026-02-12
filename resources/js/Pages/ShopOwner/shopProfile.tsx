import React, { useState, useRef } from "react";
import { Head, usePage, router } from "@inertiajs/react";
import { createPortal } from "react-dom";
import Swal from "sweetalert2";
import AppLayoutShopOwner from "../../layout/AppLayout_shopOwner";

type ShopOwner = {
  id?: number;
  first_name?: string;
  last_name?: string;
  name?: string;
  business_name?: string;
  email?: string;
  phone?: string;
  bio?: string;
  country?: string;
  city_state?: string;
  postal_code?: string;
  tax_id?: string;
  profile_photo?: string | null;
  monday_open?: string;
  monday_close?: string;
  tuesday_open?: string;
  tuesday_close?: string;
  wednesday_open?: string;
  wednesday_close?: string;
  thursday_open?: string;
  thursday_close?: string;
  friday_open?: string;
  friday_close?: string;
  saturday_open?: string;
  saturday_close?: string;
  sunday_open?: string;
  sunday_close?: string;
};

type OperatingHours = {
  monday_open?: string;
  monday_close?: string;
  tuesday_open?: string;
  tuesday_close?: string;
  wednesday_open?: string;
  wednesday_close?: string;
  thursday_open?: string;
  thursday_close?: string;
  friday_open?: string;
  friday_close?: string;
  saturday_open?: string;
  saturday_close?: string;
  sunday_open?: string;
  sunday_close?: string;
};

const InfoField: React.FC<{ label: string; value?: string | number | null; icon?: React.ReactNode }> = ({
  label,
  value,
  icon,
}) => (
  <div className="group">
    <div className="flex items-center gap-2 mb-2">
      {icon && <div className="text-gray-400 dark:text-gray-500 flex-shrink-0">{icon}</div>}
      <span className="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">
        {label}
      </span>
    </div>
    <p className="text-base font-medium text-gray-900 dark:text-white pl-6 break-words">
      {value || <span className="text-gray-400 dark:text-gray-500 italic">Not set</span>}
    </p>
  </div>
);

const EditProfileModal: React.FC<{
  isOpen: boolean;
  onClose: () => void;
  shopOwner: ShopOwner | null;
  operatingHours: OperatingHours;
  onOperatingHoursChange: (hours: OperatingHours) => void;
}> = ({ isOpen, onClose, shopOwner, operatingHours, onOperatingHoursChange }) => {
  const [formData, setFormData] = useState({
    business_name: shopOwner?.business_name || shopOwner?.name || "",
    email: shopOwner?.email || "",
    phone: shopOwner?.phone || "",
    bio: shopOwner?.bio || "",
    country: shopOwner?.country || "",
    city_state: shopOwner?.city_state || "",
    postal_code: shopOwner?.postal_code || "",
    tax_id: shopOwner?.tax_id || "",
  });

  const [localOperatingHours, setLocalOperatingHours] = useState<OperatingHours>(operatingHours);

  const handleOperatingHoursChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const { name, value } = e.target;
    setLocalOperatingHours((prev) => ({ ...prev, [name]: value }));
  };

  const [isSubmitting, setIsSubmitting] = useState(false);

  const handleChange = (e: React.ChangeEvent<HTMLInputElement | HTMLTextAreaElement>) => {
    const { name, value } = e.target;
    setFormData((prev) => ({ ...prev, [name]: value }));
  };

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    
    // Validate opening < closing for each day
    const days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
    for (const day of days) {
      const open = localOperatingHours[`${day}_open` as keyof OperatingHours];
      const close = localOperatingHours[`${day}_close` as keyof OperatingHours];
      
      if (open && close && open >= close) {
        Swal.fire({
          title: 'Invalid Time',
          text: `${day.charAt(0).toUpperCase() + day.slice(1)}: Opening time must be before closing time`,
          icon: 'error',
          confirmButtonText: 'OK',
          confirmButtonColor: '#dc2626',
        });
        return;
      }
    }
    
    setIsSubmitting(true);

    // Submit to backend using Inertia router
    router.post('/shop-owner/shop-profile', {
      ...formData,
      ...localOperatingHours,
    }, {
      preserveScroll: true,
      onSuccess: () => {
        setIsSubmitting(false);
        onOperatingHoursChange(localOperatingHours);
        Swal.fire({
          title: "Success!",
          text: "Profile updated successfully.",
          icon: "success",
          confirmButtonText: "OK",
          confirmButtonColor: "#2563eb",
        }).then(() => {
          onClose();
          router.reload({ only: ['shop_owner'] });
        });
      },
      onError: (errors) => {
        setIsSubmitting(false);
        console.error('Update errors:', errors);
        const errorMessages = Object.entries(errors)
          .map(([field, message]) => `<strong>${field.replace(/_/g, ' ')}:</strong> ${message}`)
          .join('<br>');
        
        Swal.fire({
          title: "Validation Error!",
          html: errorMessages || "Failed to update profile.",
          icon: "error",
          confirmButtonText: "OK",
          confirmButtonColor: "#dc2626",
        });
      }
    });
  };

  const handleCancel = () => {
    setFormData({
      business_name: shopOwner?.business_name || shopOwner?.name || "",
      email: shopOwner?.email || "",
      phone: shopOwner?.phone || "",
      bio: shopOwner?.bio || "",
      country: shopOwner?.country || "",
      city_state: shopOwner?.city_state || "",
      postal_code: shopOwner?.postal_code || "",
      tax_id: shopOwner?.tax_id || "",
    });
    setLocalOperatingHours(operatingHours);
    onClose();
  };

  if (!isOpen) return null;

  return createPortal(
    <div className="fixed inset-0 z-[999999] bg-black/60 backdrop-blur-sm flex items-center justify-center p-4">
      <div className="w-full max-w-3xl max-h-[90vh] overflow-y-auto rounded-2xl bg-white dark:bg-gray-900 shadow-2xl">
        <form onSubmit={handleSubmit}>
          {/* Modal Header */}
          <div className="sticky top-0 bg-white dark:bg-gray-900 border-b border-gray-200 dark:border-gray-800 px-6 py-4">
            <h2 className="text-2xl font-bold text-gray-900 dark:text-white">
              Edit Profile
            </h2>
          </div>

          {/* Modal Body */}
          <div className="p-6 space-y-6">
            {/* Personal Information Section */}
            <div>
              <h3 className="text-lg font-semibold text-gray-900 dark:text-white mb-4">
                Personal Information
              </h3>
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div className="sm:col-span-2">
                  <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                    Shop Name
                  </label>
                  <input
                    type="text"
                    name="business_name"
                    value={formData.business_name}
                    onChange={handleChange}
                    className="w-full px-3 py-2 border border-gray-300 dark:border-gray-700 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500"
                    placeholder="Enter shop name"
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                    Email Address
                  </label>
                  <input
                    type="email"
                    name="email"
                    value={formData.email}
                    onChange={handleChange}
                    className="w-full px-3 py-2 border border-gray-300 dark:border-gray-700 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500"
                    placeholder="Enter email"
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                    Phone
                  </label>
                  <input
                    type="text"
                    name="phone"
                    value={formData.phone}
                    onChange={handleChange}
                    className="w-full px-3 py-2 border border-gray-300 dark:border-gray-700 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500"
                    placeholder="Enter phone number"
                  />
                </div>
                <div className="sm:col-span-2">
                  <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                    Bio
                  </label>
                  <textarea
                    name="bio"
                    value={formData.bio}
                    onChange={handleChange}
                    rows={3}
                    className="w-full px-3 py-2 border border-gray-300 dark:border-gray-700 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500"
                    placeholder="Tell us about your shop"
                  />
                </div>
              </div>
            </div>

            {/* Address Section */}
            <div>
              <h3 className="text-lg font-semibold text-gray-900 dark:text-white mb-4">
                Address
              </h3>
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                  <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                    Country
                  </label>
                  <input
                    type="text"
                    name="country"
                    value={formData.country}
                    onChange={handleChange}
                    className="w-full px-3 py-2 border border-gray-300 dark:border-gray-700 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500"
                    placeholder="Enter country"
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                    City/State
                  </label>
                  <input
                    type="text"
                    name="city_state"
                    value={formData.city_state}
                    onChange={handleChange}
                    className="w-full px-3 py-2 border border-gray-300 dark:border-gray-700 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500"
                    placeholder="Enter city/state"
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                    Postal Code
                  </label>
                  <input
                    type="text"
                    name="postal_code"
                    value={formData.postal_code}
                    onChange={handleChange}
                    className="w-full px-3 py-2 border border-gray-300 dark:border-gray-700 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500"
                    placeholder="Enter postal code"
                  />
                </div>
                <div>
                  <label className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                    TAX ID
                  </label>
                  <input
                    type="text"
                    name="tax_id"
                    value={formData.tax_id}
                    onChange={handleChange}
                    className="w-full px-3 py-2 border border-gray-300 dark:border-gray-700 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500"
                    placeholder="Enter TAX ID"
                  />
                </div>
              </div>
            </div>

            {/* Operating Hours Section */}
            <div>
              <div className="flex items-center justify-between mb-4">
                <h3 className="text-lg font-semibold text-gray-900 dark:text-white">
                  Operating Hours
                </h3>
                <button
                  type="button"
                  onClick={() => {
                    const mondayOpen = localOperatingHours.monday_open;
                    const mondayClose = localOperatingHours.monday_close;
                    if (mondayOpen || mondayClose) {
                      const allDays = ['tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
                      const newHours = { ...localOperatingHours };
                      allDays.forEach(day => {
                        newHours[`${day}_open` as keyof OperatingHours] = mondayOpen;
                        newHours[`${day}_close` as keyof OperatingHours] = mondayClose;
                      });
                      setLocalOperatingHours(newHours);
                      Swal.fire({
                        title: 'Success!',
                        text: 'Monday hours copied to all other days',
                        icon: 'success',
                        timer: 1500,
                        showConfirmButton: false,
                      });
                    }
                  }}
                  className="text-xs text-blue-600 dark:text-blue-400 hover:underline"
                >
                  Copy Monday to All Days
                </button>
              </div>
              <div className="grid grid-cols-1 gap-4">
                {[
                  { day: 'Monday', openKey: 'monday_open', closeKey: 'monday_close' },
                  { day: 'Tuesday', openKey: 'tuesday_open', closeKey: 'tuesday_close' },
                  { day: 'Wednesday', openKey: 'wednesday_open', closeKey: 'wednesday_close' },
                  { day: 'Thursday', openKey: 'thursday_open', closeKey: 'thursday_close' },
                  { day: 'Friday', openKey: 'friday_open', closeKey: 'friday_close' },
                  { day: 'Saturday', openKey: 'saturday_open', closeKey: 'saturday_close' },
                  { day: 'Sunday', openKey: 'sunday_open', closeKey: 'sunday_close' },
                ].map(({ day, openKey, closeKey }) => (
                  <div key={day} className="grid grid-cols-1 sm:grid-cols-4 gap-3 items-center">
                    <label className="text-sm font-medium text-gray-700 dark:text-gray-300">
                      {day}
                    </label>
                    <div>
                      <input
                        type="time"
                        name={openKey}
                        value={localOperatingHours[openKey as keyof OperatingHours] || ''}
                        onChange={handleOperatingHoursChange}
                        className="w-full px-3 py-2 border border-gray-300 dark:border-gray-700 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500"
                        placeholder="Opening"
                      />
                    </div>
                    <div>
                      <input
                        type="time"
                        name={closeKey}
                        value={localOperatingHours[closeKey as keyof OperatingHours] || ''}
                        onChange={handleOperatingHoursChange}
                        className="w-full px-3 py-2 border border-gray-300 dark:border-gray-700 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500"
                        placeholder="Closing"
                      />
                    </div>
                    <button
                      type="button"
                      onClick={() => {
                        setLocalOperatingHours(prev => ({
                          ...prev,
                          [openKey]: '',
                          [closeKey]: ''
                        }));
                      }}
                      className="inline-flex items-center justify-center w-8 h-8 rounded-md text-red-600 dark:text-red-400 focus:outline-none focus:ring-2 focus:ring-offset-1 focus:ring-red-500"
                      aria-label={`Set ${day} closed`}
                      title="Set Closed"
                    >
                      <svg xmlns="http://www.w3.org/2000/svg" className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                        <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" />
                      </svg>
                    </button>
                  </div>
                ))}
              </div>
            </div>
          </div>

          {/* Modal Footer */}
          <div className="sticky bottom-0 bg-white dark:bg-gray-900 border-t border-gray-200 dark:border-gray-800 px-6 py-4 flex justify-end gap-3">
            <button
              type="button"
              onClick={handleCancel}
              disabled={isSubmitting}
              className="rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-900 transition-all hover:bg-gray-50 dark:border-gray-700 dark:text-white dark:hover:bg-gray-700 disabled:opacity-50"
            >
              Cancel
            </button>
            <button
              type="submit"
              disabled={isSubmitting}
              className="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white transition-all hover:bg-blue-700 disabled:opacity-50 dark:bg-blue-500 dark:hover:bg-blue-600"
            >
              {isSubmitting ? "Saving..." : "Save Changes"}
            </button>
          </div>
        </form>
      </div>
    </div>,
    document.body
  );
};

const ShopProfile: React.FC = () => {
  const pageProps = usePage().props as any;
  const shopOwner: ShopOwner | null =
    pageProps.shop_owner || pageProps.auth?.shop_owner || null;

  const displayName =
    shopOwner?.business_name || shopOwner?.name || "Your shop";

  const [profilePhoto, setProfilePhoto] = useState<string | null>(
    shopOwner?.profile_photo ? `/storage/${shopOwner.profile_photo}` : null
  );
  const [isUploadingPhoto, setIsUploadingPhoto] = useState(false);
  const [isEditModalOpen, setIsEditModalOpen] = useState(false);
  const fileInputRef = useRef<HTMLInputElement>(null);

  // Operating hours state - Load from backend
  const [operatingHours, setOperatingHours] = useState<OperatingHours>({
    monday_open: shopOwner?.monday_open || '',
    monday_close: shopOwner?.monday_close || '',
    tuesday_open: shopOwner?.tuesday_open || '',
    tuesday_close: shopOwner?.tuesday_close || '',
    wednesday_open: shopOwner?.wednesday_open || '',
    wednesday_close: shopOwner?.wednesday_close || '',
    thursday_open: shopOwner?.thursday_open || '',
    thursday_close: shopOwner?.thursday_close || '',
    friday_open: shopOwner?.friday_open || '',
    friday_close: shopOwner?.friday_close || '',
    saturday_open: shopOwner?.saturday_open || '',
    saturday_close: shopOwner?.saturday_close || '',
    sunday_open: shopOwner?.sunday_open || '',
    sunday_close: shopOwner?.sunday_close || '',
  });

  const handlePhotoUpload = async (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (!file) return;

    setIsUploadingPhoto(true);
    try {
      const formData = new FormData();
      formData.append('profile_photo', file);

      const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');

      const response = await fetch('/api/shop-owner/upload-profile-photo', {
        method: 'POST',
        credentials: 'include',
        headers: {
          'X-CSRF-TOKEN': csrfToken || '',
        },
        body: formData,
      });

      const data = await response.json();

      if (!response.ok) {
        throw new Error(data.message || 'Failed to upload photo');
      }

      // Update the local state with the new photo URL
      setProfilePhoto(`/storage/${data.profile_photo}`);

      Swal.fire({
        title: 'Success!',
        text: 'Profile photo uploaded successfully.',
        icon: 'success',
        confirmButtonText: 'OK',
        confirmButtonColor: '#2563eb',
      });

      // Reset file input
      if (fileInputRef.current) {
        fileInputRef.current.value = '';
      }
    } catch (error: any) {
      console.error('Error uploading photo:', error);
      Swal.fire({
        title: 'Error!',
        text: error.message || 'Failed to upload profile photo.',
        icon: 'error',
        confirmButtonText: 'OK',
        confirmButtonColor: '#dc2626',
      });
    } finally {
      setIsUploadingPhoto(false);
    }
  };

  return (
    <AppLayoutShopOwner>
      <Head title="Shop Profile - Shop Owner" />
      <div className="min-h-screen bg-gray-50 dark:bg-gray-900 dark:bg-opacity-50">
        <div className="max-w-9xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
          {/* Page Header */}
          <div className="mb-8">
            <h1 className="text-3xl font-bold text-gray-900 dark:text-white mb-2">
              Profile Settings
            </h1>
            <p className="text-gray-600 dark:text-gray-400">
              Manage your shop profile and personal information
            </p>
          </div>

          {/* Profile Header Card */}
          <div className="bg-white dark:bg-gray-800 dark:bg-opacity-50 rounded-2xl shadow-lg border border-gray-200 dark:border-gray-700 dark:border-opacity-50 overflow-hidden mb-6">
            <div className="px-8 py-8">
              <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-6">
                <div className="flex items-center gap-6">
                  <div className="relative group">
                    <div className="flex h-24 w-24 items-center justify-center rounded-2xl bg-blue-100 dark:bg-blue-900 dark:bg-opacity-30 shadow-lg overflow-hidden">
                      {profilePhoto ? (
                        <img
                          src={profilePhoto}
                          alt={displayName}
                          className="w-full h-full object-cover"
                        />
                      ) : (
                        <span className="text-4xl font-bold text-blue-600 dark:text-blue-400">
                          {displayName?.slice(0, 1) || "S"}
                        </span>
                      )}
                    </div>
                    <button
                      onClick={() => fileInputRef.current?.click()}
                      disabled={isUploadingPhoto}
                      className="absolute bottom-0 right-0 p-2 bg-white dark:bg-gray-800 rounded-lg shadow-lg border-2 border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-700 transition-all disabled:opacity-50 disabled:cursor-not-allowed"
                      title="Upload photo"
                    >
                      <svg className="w-4 h-4 text-gray-600 dark:text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z" />
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 13a3 3 0 11-6 0 3 3 0 016 0z" />
                      </svg>
                    </button>
                    <input
                      ref={fileInputRef}
                      type="file"
                      accept="image/*"
                      onChange={handlePhotoUpload}
                      className="hidden"
                      disabled={isUploadingPhoto}
                    />
                  </div>
                  <div>
                    <h2 className="text-2xl font-bold text-gray-900 dark:text-white mb-1">
                      {displayName}
                    </h2>
                    <p className="text-sm text-gray-600 dark:text-gray-400 flex items-center gap-2">
                      <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M21 13.255A23.931 23.931 0 0112 15c-3.183 0-6.22-.62-9-1.745M16 6V4a2 2 0 00-2-2h-4a2 2 0 00-2 2v2m4 6h.01M5 20h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                      </svg>
                      Shop Owner
                    </p>
                  </div>
                </div>
                <button
                  onClick={() => setIsEditModalOpen(true)}
                  className="inline-flex items-center justify-center gap-2 rounded-xl bg-blue-600 hover:bg-blue-700 px-6 py-3 text-sm font-semibold text-white shadow-md hover:shadow-lg transition-all duration-200 dark:bg-blue-500 dark:hover:bg-blue-600"
                >
                  <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                    <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                    <path d="M18.5 2.5 a2.121 2.121 0 0 1 3 3 L12 15 l-4 1 l1-4 l9.5-9.5z"></path>
                  </svg>
                  Edit Profile
                </button>
              </div>
            </div>
          </div>

          {/* Personal Information */}
          <div className="bg-white dark:bg-gray-800 dark:bg-opacity-50 rounded-2xl shadow-lg border border-gray-200 dark:border-gray-700 dark:border-opacity-50 overflow-hidden mb-6">
            <div className="border-b border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 dark:bg-opacity-80 px-6 py-4">
              <div className="flex items-center gap-3">
                <div className="p-2 bg-blue-100 dark:bg-blue-900 dark:bg-opacity-30 rounded-lg">
                  <svg className="w-5 h-5 text-blue-600 dark:text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                  </svg>
                </div>
                <h3 className="text-lg font-bold text-gray-900 dark:text-white">
                  Personal Information
                </h3>
              </div>
            </div>
            <div className="p-6">
              <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
                <div className="min-w-0">
                  <InfoField 
                    label="Shop Name" 
                    value={shopOwner?.business_name || shopOwner?.name}
                    icon={
                      <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                      </svg>
                    }
                  />
                </div>
                <div className="min-w-0">
                  <InfoField 
                    label="Email address" 
                    value={shopOwner?.email}
                    icon={
                      <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                      </svg>
                    }
                  />
                </div>
                <div className="min-w-0">
                  <InfoField 
                    label="Phone" 
                    value={shopOwner?.phone}
                    icon={
                      <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" />
                      </svg>
                    }
                  />
                </div>
                <div className="lg:col-span-2 min-w-0">
                  <InfoField 
                    label="Bio" 
                    value={shopOwner?.bio || `${displayName} - Shop Owner`}
                    icon={
                      <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 6h16M4 12h16m-7 6h7" />
                      </svg>
                    }
                  />
                </div>
              </div>
            </div>
          </div>

          {/* Address */}
          <div className="bg-white dark:bg-gray-800 dark:bg-opacity-50 rounded-2xl shadow-lg border border-gray-200 dark:border-gray-700 dark:border-opacity-50 overflow-hidden mb-6">
            <div className="border-b border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 dark:bg-opacity-80 px-6 py-4">
              <div className="flex items-center gap-3">
                <div className="p-2 bg-green-100 dark:bg-green-900 dark:bg-opacity-30 rounded-lg">
                  <svg className="w-5 h-5 text-green-600 dark:text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" />
                  </svg>
                </div>
                <h3 className="text-lg font-bold text-gray-900 dark:text-white">
                  Address Information
                </h3>
              </div>
            </div>
            <div className="p-6">
              <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
                <div className="min-w-0">
                  <InfoField 
                    label="Country" 
                    value={shopOwner?.country}
                    icon={
                      <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                      </svg>
                    }
                  />
                </div>
                <div className="min-w-0">
                  <InfoField 
                    label="City/State" 
                    value={shopOwner?.city_state}
                    icon={
                      <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                      </svg>
                    }
                  />
                </div>
                <div className="min-w-0">
                  <InfoField 
                    label="Postal Code" 
                    value={shopOwner?.postal_code}
                    icon={
                      <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                      </svg>
                    }
                  />
                </div>
                <div className="min-w-0">
                  <InfoField 
                    label="TAX ID" 
                    value={shopOwner?.tax_id}
                    icon={
                      <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                      </svg>
                    }
                  />
                </div>
              </div>
            </div>
          </div>

          {/* Operating Hours */}
          <div className="bg-white dark:bg-gray-800 dark:bg-opacity-50 rounded-2xl shadow-lg border border-gray-200 dark:border-gray-700 dark:border-opacity-50 overflow-hidden">
            <div className="border-b border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 dark:bg-opacity-80 px-6 py-4">
              <div className="flex items-center gap-3">
                <div className="p-2 bg-purple-100 dark:bg-purple-900 dark:bg-opacity-30 rounded-lg">
                  <svg className="w-5 h-5 text-purple-600 dark:text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                  </svg>
                </div>
                <h3 className="text-lg font-bold text-gray-900 dark:text-white">
                  Operating Hours
                </h3>
              </div>
            </div>
            <div className="p-6">
              <div className="overflow-x-auto">
                <table className="w-full">
                  <thead>
                    <tr className="border-b border-gray-200 dark:border-gray-700">
                      <th className="text-left py-3 px-4 text-sm font-semibold text-gray-700 dark:text-gray-300">
                        Day
                      </th>
                      <th className="text-left py-3 px-4 text-sm font-semibold text-gray-700 dark:text-gray-300">
                        Opening Time
                      </th>
                      <th className="text-left py-3 px-4 text-sm font-semibold text-gray-700 dark:text-gray-300">
                        Closing Time
                      </th>
                      <th className="text-left py-3 px-4 text-sm font-semibold text-gray-700 dark:text-gray-300">
                        Status
                      </th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-gray-200 dark:divide-gray-700">
                    {[
                      { day: 'Monday', openKey: 'monday_open', closeKey: 'monday_close' },
                      { day: 'Tuesday', openKey: 'tuesday_open', closeKey: 'tuesday_close' },
                      { day: 'Wednesday', openKey: 'wednesday_open', closeKey: 'wednesday_close' },
                      { day: 'Thursday', openKey: 'thursday_open', closeKey: 'thursday_close' },
                      { day: 'Friday', openKey: 'friday_open', closeKey: 'friday_close' },
                      { day: 'Saturday', openKey: 'saturday_open', closeKey: 'saturday_close' },
                      { day: 'Sunday', openKey: 'sunday_open', closeKey: 'sunday_close' },
                    ].map(({ day, openKey, closeKey }) => {
                      const openTime = operatingHours[openKey as keyof OperatingHours];
                      const closeTime = operatingHours[closeKey as keyof OperatingHours];
                      const isClosed = !openTime || !closeTime;
                      
                      return (
                        <tr key={day} className="hover:bg-gray-50 dark:hover:bg-gray-800 dark:hover:bg-opacity-50 transition-colors">
                          <td className="py-4 px-4">
                            <span className="font-medium text-gray-900 dark:text-white">
                              {day}
                            </span>
                          </td>
                          <td className="py-4 px-4">
                            <span className="text-gray-700 dark:text-gray-300">
                              {openTime || <span className="text-gray-400 italic">Not set</span>}
                            </span>
                          </td>
                          <td className="py-4 px-4">
                            <span className="text-gray-700 dark:text-gray-300">
                              {closeTime || <span className="text-gray-400 italic">Not set</span>}
                            </span>
                          </td>
                          <td className="py-4 px-4">
                            {isClosed ? (
                              <span className="inline-flex items-center gap-2 px-3 py-1 rounded-full text-xs font-medium bg-red-100 dark:bg-red-900 dark:bg-opacity-30 text-red-700 dark:text-red-400">
                                <svg className="w-3 h-3" fill="currentColor" viewBox="0 0 20 20">
                                  <path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clipRule="evenodd" />
                                </svg>
                                Closed
                              </span>
                            ) : (
                              <span className="inline-flex items-center gap-2 px-3 py-1 rounded-full text-xs font-medium bg-green-100 dark:bg-green-900 dark:bg-opacity-30 text-green-700 dark:text-green-400">
                                <svg className="w-3 h-3" fill="currentColor" viewBox="0 0 20 20">
                                  <path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clipRule="evenodd" />
                                </svg>
                                Open
                              </span>
                            )}
                          </td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>
            </div>
          </div>

          {/* Edit Modal */}
          <EditProfileModal
            isOpen={isEditModalOpen}
            onClose={() => setIsEditModalOpen(false)}
            shopOwner={shopOwner}
            operatingHours={operatingHours}
            onOperatingHoursChange={setOperatingHours}
          />
        </div>
      </div>
    </AppLayoutShopOwner>
  );
};

export default ShopProfile;
