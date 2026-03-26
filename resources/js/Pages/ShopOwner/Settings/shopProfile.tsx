import React, { useEffect, useState, useRef } from "react";
import { Head, usePage, router } from "@inertiajs/react";
import { createPortal } from "react-dom";
import Swal from "sweetalert2";
import AppLayoutShopOwner from "../../../layout/AppLayout_shopOwner";

type ShopOwner = {
  id?: number;
  first_name?: string;
  last_name?: string;
  name?: string;
  business_name?: string;
  established_year?: number | null;
  email?: string;
  phone?: string;
  bio?: string;
  country?: string;
  city_state?: string;
  postal_code?: string;
  profile_photo?: string | null;
  cover_photo?: string | null;
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

const formatTimeTo12Hour = (timeValue?: string | null): string => {
  if (!timeValue) return "Not set";

  const trimmed = String(timeValue).trim();
  const hhmmOrHhmmss = trimmed.match(/^(\d{1,2}):(\d{2})(?::\d{2})?$/);

  if (!hhmmOrHhmmss) return trimmed;

  const hour = Number(hhmmOrHhmmss[1]);
  const minute = hhmmOrHhmmss[2];

  if (hour < 0 || hour > 23) return trimmed;

  const period = hour >= 12 ? "PM" : "AM";
  const hour12 = hour % 12 || 12;

  return `${hour12}:${minute} ${period}`;
};

const normalizeTimeToHhmm = (timeValue?: string | null): string => {
  if (!timeValue) return "";

  const trimmed = String(timeValue).trim();
  const hhmmOrHhmmss = trimmed.match(/^(\d{1,2}):(\d{2})(?::\d{2})?$/);

  if (!hhmmOrHhmmss) return "";

  const hours = Number(hhmmOrHhmmss[1]);
  const minutes = Number(hhmmOrHhmmss[2]);

  if (hours < 0 || hours > 23 || minutes < 0 || minutes > 59) return "";

  return `${String(hours).padStart(2, "0")}:${String(minutes).padStart(2, "0")}`;
};

const normalizeOperatingHours = (hours: OperatingHours): OperatingHours => {
  const normalized: OperatingHours = {};

  (
    [
      "monday",
      "tuesday",
      "wednesday",
      "thursday",
      "friday",
      "saturday",
      "sunday",
    ] as const
  ).forEach((day) => {
    const openKey = `${day}_open` as keyof OperatingHours;
    const closeKey = `${day}_close` as keyof OperatingHours;
    normalized[openKey] = normalizeTimeToHhmm(hours[openKey] || "");
    normalized[closeKey] = normalizeTimeToHhmm(hours[closeKey] || "");
  });

  return normalized;
};

const OPERATING_HOUR_ROWS = [
  { day: "Monday", openKey: "monday_open", closeKey: "monday_close" },
  { day: "Tuesday", openKey: "tuesday_open", closeKey: "tuesday_close" },
  { day: "Wednesday", openKey: "wednesday_open", closeKey: "wednesday_close" },
  { day: "Thursday", openKey: "thursday_open", closeKey: "thursday_close" },
  { day: "Friday", openKey: "friday_open", closeKey: "friday_close" },
  { day: "Saturday", openKey: "saturday_open", closeKey: "saturday_close" },
  { day: "Sunday", openKey: "sunday_open", closeKey: "sunday_close" },
] as const;

const validateOperatingHours = (hours: OperatingHours): string | null => {
  const normalizedHours = normalizeOperatingHours(hours);

  for (const row of OPERATING_HOUR_ROWS) {
    const open = normalizedHours[row.openKey as keyof OperatingHours];
    const close = normalizedHours[row.closeKey as keyof OperatingHours];

    if (open && close && open >= close) {
      return `${row.day}: Opening time must be before closing time`;
    }
  }

  return null;
};

const OperatingHoursModal: React.FC<{
  isOpen: boolean;
  initialHours: OperatingHours;
  onClose: () => void;
  onSave: (hours: OperatingHours) => void;
}> = ({ isOpen, initialHours, onClose, onSave }) => {
  const [draftHours, setDraftHours] = useState<OperatingHours>(normalizeOperatingHours(initialHours));

  useEffect(() => {
    if (isOpen) {
      setDraftHours(normalizeOperatingHours(initialHours));
    }
  }, [isOpen, initialHours]);

  const handleTimeChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const { name, value } = e.target;
    setDraftHours((prev) => ({ ...prev, [name]: value }));
  };

  const handleCopyMonday = () => {
    const mondayOpen = draftHours.monday_open;
    const mondayClose = draftHours.monday_close;

    if (!mondayOpen && !mondayClose) {
      Swal.fire({
        title: "Set Monday first",
        text: "Please set Monday hours before copying.",
        icon: "info",
        confirmButtonColor: "#2563eb",
      });
      return;
    }

    setDraftHours((prev) => {
      const next = { ...prev };
      ["tuesday", "wednesday", "thursday", "friday", "saturday", "sunday"].forEach((day) => {
        next[`${day}_open` as keyof OperatingHours] = mondayOpen || "";
        next[`${day}_close` as keyof OperatingHours] = mondayClose || "";
      });
      return next;
    });
  };

  const handleSave = () => {
    const error = validateOperatingHours(draftHours);
    if (error) {
      Swal.fire({
        title: "Invalid Time",
        text: error,
        icon: "error",
        confirmButtonColor: "#dc2626",
      });
      return;
    }

    onSave(normalizeOperatingHours(draftHours));
    onClose();
  };

  if (!isOpen) return null;

  return createPortal(
    <div className="fixed inset-0 z-60 bg-black/60 backdrop-blur-sm flex items-center justify-center p-4">
      <div className="w-full max-w-2xl max-h-[90vh] overflow-y-auto rounded-2xl bg-white dark:bg-gray-900 shadow-2xl">
        <div className="sticky top-0 bg-white dark:bg-gray-900 border-b border-gray-200 dark:border-gray-800 px-6 py-4 flex items-center justify-between">
          <h3 className="text-xl font-bold text-gray-900 dark:text-white">Set Operating Hours</h3>
          <button
            type="button"
            onClick={onClose}
            className="rounded-md px-2 py-1 text-gray-500 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800"
          >
            ✕
          </button>
        </div>

        <div className="p-6 space-y-4">
          <div className="flex items-center justify-between">
            <p className="text-sm text-gray-600 dark:text-gray-300">
              Set your opening and closing hours per day.
            </p>
            <button
              type="button"
              onClick={handleCopyMonday}
              className="text-xs font-semibold text-blue-600 dark:text-blue-400 hover:underline"
            >
              Copy Monday to All Days
            </button>
          </div>

          <div className="space-y-3">
            {OPERATING_HOUR_ROWS.map(({ day, openKey, closeKey }) => (
              <div key={day} className="grid grid-cols-1 sm:grid-cols-12 gap-3 items-center rounded-xl border border-gray-200 dark:border-gray-700 p-3">
                <label className="sm:col-span-3 text-sm font-semibold text-gray-800 dark:text-gray-100">
                  {day}
                </label>
                <input
                  type="time"
                  name={openKey}
                  value={draftHours[openKey as keyof OperatingHours] || ""}
                  onChange={handleTimeChange}
                  aria-label={`${day} opening time`}
                  title={`${day} opening time`}
                  className="sm:col-span-3 w-full px-3 py-2 border border-gray-300 dark:border-gray-700 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500"
                />
                <input
                  type="time"
                  name={closeKey}
                  value={draftHours[closeKey as keyof OperatingHours] || ""}
                  onChange={handleTimeChange}
                  aria-label={`${day} closing time`}
                  title={`${day} closing time`}
                  className="sm:col-span-3 w-full px-3 py-2 border border-gray-300 dark:border-gray-700 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500"
                />
                <button
                  type="button"
                  onClick={() => {
                    setDraftHours((prev) => ({
                      ...prev,
                      [openKey]: "",
                      [closeKey]: "",
                    }));
                  }}
                  className="sm:col-span-3 rounded-lg border border-red-200 px-3 py-2 text-sm font-semibold text-red-600 hover:bg-red-50 dark:border-red-700 dark:text-red-400 dark:hover:bg-red-900/20"
                >
                  Mark Closed
                </button>
              </div>
            ))}
          </div>
        </div>

        <div className="sticky bottom-0 bg-white dark:bg-gray-900 border-t border-gray-200 dark:border-gray-800 px-6 py-4 flex justify-end gap-3">
          <button
            type="button"
            onClick={onClose}
            className="rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-900 hover:bg-gray-50 dark:border-gray-700 dark:text-white dark:hover:bg-gray-700"
          >
            Cancel
          </button>
          <button
            type="button"
            onClick={handleSave}
            className="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700"
          >
            Apply Hours
          </button>
        </div>
      </div>
    </div>,
    document.body
  );
};

const InfoField: React.FC<{ label: string; value?: string | number | null; icon?: React.ReactNode }> = ({
  label,
  value,
  icon,
}) => (
  <div className="group">
    <div className="flex items-center gap-2 mb-2">
      {icon && <div className="text-gray-400 dark:text-gray-500 shrink-0">{icon}</div>}
      <span className="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">
        {label}
      </span>
    </div>
    <p className="text-base font-medium text-gray-900 dark:text-white pl-6 wrap-break-word">
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
    established_year: shopOwner?.established_year ? String(shopOwner.established_year) : "",
    email: shopOwner?.email || "",
    phone: shopOwner?.phone || "",
    bio: shopOwner?.bio || "",
    country: shopOwner?.country || "",
    city_state: shopOwner?.city_state || "",
    postal_code: shopOwner?.postal_code || "",
  });
  const [isHoursModalOpen, setIsHoursModalOpen] = useState(false);

  const [localOperatingHours, setLocalOperatingHours] = useState<OperatingHours>(
    normalizeOperatingHours(operatingHours)
  );

  const [isSubmitting, setIsSubmitting] = useState(false);

  const handleChange = (e: React.ChangeEvent<HTMLInputElement | HTMLTextAreaElement>) => {
    const { name, value } = e.target;
    setFormData((prev) => ({ ...prev, [name]: value }));
  };

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    const normalizedHours = normalizeOperatingHours(localOperatingHours);
    const hoursError = validateOperatingHours(normalizedHours);
    if (hoursError) {
      Swal.fire({
        title: "Invalid Time",
        text: hoursError,
        icon: "error",
        confirmButtonText: "OK",
        confirmButtonColor: "#dc2626",
      });
      return;
    }
    
    setIsSubmitting(true);

    // Submit to backend using Inertia router
    router.post('/shop-owner/shop-profile', {
      ...formData,
      ...normalizedHours,
    }, {
      preserveScroll: true,
      onSuccess: () => {
        setIsSubmitting(false);
        onOperatingHoursChange(normalizedHours);
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
      established_year: shopOwner?.established_year ? String(shopOwner.established_year) : "",
      email: shopOwner?.email || "",
      phone: shopOwner?.phone || "",
      bio: shopOwner?.bio || "",
      country: shopOwner?.country || "",
      city_state: shopOwner?.city_state || "",
      postal_code: shopOwner?.postal_code || "",
    });
    setLocalOperatingHours(normalizeOperatingHours(operatingHours));
    onClose();
  };

  if (!isOpen) return null;

  return createPortal(
    <div className="fixed inset-0 z-50 bg-black/60 backdrop-blur-sm flex items-center justify-center p-4">
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
                    Established Year
                  </label>
                  <input
                    type="number"
                    name="established_year"
                    value={formData.established_year}
                    onChange={handleChange}
                    min={1900}
                    max={new Date().getFullYear()}
                    className="w-full px-3 py-2 border border-gray-300 dark:border-gray-700 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500"
                    placeholder="e.g. 2024"
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
                  onClick={() => setIsHoursModalOpen(true)}
                  className="rounded-lg bg-blue-600 px-3 py-2 text-xs font-semibold text-white hover:bg-blue-700"
                >
                  Set Time in Modal
                </button>
              </div>
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                {OPERATING_HOUR_ROWS.map(({ day, openKey, closeKey }) => {
                  const openTime = localOperatingHours[openKey as keyof OperatingHours];
                  const closeTime = localOperatingHours[closeKey as keyof OperatingHours];
                  const isClosed = !openTime || !closeTime;

                  return (
                    <div key={day} className="rounded-xl border border-gray-200 dark:border-gray-700 p-3">
                      <p className="text-sm font-semibold text-gray-900 dark:text-white">{day}</p>
                      <p className="mt-1 text-sm text-gray-600 dark:text-gray-300">
                        {isClosed ? "Closed" : `${formatTimeTo12Hour(openTime)} - ${formatTimeTo12Hour(closeTime)}`}
                      </p>
                    </div>
                  );
                })}
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

        <OperatingHoursModal
          isOpen={isHoursModalOpen}
          initialHours={localOperatingHours}
          onClose={() => setIsHoursModalOpen(false)}
          onSave={(hours) => setLocalOperatingHours(hours)}
        />
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
  const [coverPhoto, setCoverPhoto] = useState<string | null>(
    shopOwner?.cover_photo ? `/storage/${shopOwner.cover_photo}` : null
  );
  const [isUploadingPhoto, setIsUploadingPhoto] = useState(false);
  const [isUploadingCoverPhoto, setIsUploadingCoverPhoto] = useState(false);
  const [isEditModalOpen, setIsEditModalOpen] = useState(false);
  const profilePhotoInputRef = useRef<HTMLInputElement>(null);
  const coverPhotoInputRef = useRef<HTMLInputElement>(null);

  // Operating hours state - Load from backend
  const [operatingHours, setOperatingHours] = useState<OperatingHours>({
    monday_open: normalizeTimeToHhmm(shopOwner?.monday_open),
    monday_close: normalizeTimeToHhmm(shopOwner?.monday_close),
    tuesday_open: normalizeTimeToHhmm(shopOwner?.tuesday_open),
    tuesday_close: normalizeTimeToHhmm(shopOwner?.tuesday_close),
    wednesday_open: normalizeTimeToHhmm(shopOwner?.wednesday_open),
    wednesday_close: normalizeTimeToHhmm(shopOwner?.wednesday_close),
    thursday_open: normalizeTimeToHhmm(shopOwner?.thursday_open),
    thursday_close: normalizeTimeToHhmm(shopOwner?.thursday_close),
    friday_open: normalizeTimeToHhmm(shopOwner?.friday_open),
    friday_close: normalizeTimeToHhmm(shopOwner?.friday_close),
    saturday_open: normalizeTimeToHhmm(shopOwner?.saturday_open),
    saturday_close: normalizeTimeToHhmm(shopOwner?.saturday_close),
    sunday_open: normalizeTimeToHhmm(shopOwner?.sunday_open),
    sunday_close: normalizeTimeToHhmm(shopOwner?.sunday_close),
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
          'X-Requested-With': 'XMLHttpRequest',
        },
        body: formData,
      });

      if (!response.ok) {
        const text = await response.text();
        console.error('Response:', text);
        let backendMessage = '';
        try {
          const parsed = JSON.parse(text);
          backendMessage = parsed?.message || '';
        } catch {
          backendMessage = '';
        }
        throw new Error(backendMessage || `Upload failed: ${response.status} ${response.statusText}`);
      }

      const data = await response.json();

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
      if (profilePhotoInputRef.current) {
        profilePhotoInputRef.current.value = '';
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

  const handleCoverPhotoUpload = async (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (!file) return;

    setIsUploadingCoverPhoto(true);
    try {
      const formData = new FormData();
      formData.append('cover_photo', file);

      const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');

      const response = await fetch('/api/shop-owner/upload-profile-photo', {
        method: 'POST',
        credentials: 'include',
        headers: {
          'X-CSRF-TOKEN': csrfToken || '',
          'X-Requested-With': 'XMLHttpRequest',
        },
        body: formData,
      });

      if (!response.ok) {
        const text = await response.text();
        console.error('Response:', text);
        throw new Error(`Upload failed: ${response.status} ${response.statusText}`);
      }

      const data = await response.json();
      setCoverPhoto(`/storage/${data.cover_photo}`);

      Swal.fire({
        title: 'Success!',
        text: 'Cover photo uploaded successfully.',
        icon: 'success',
        confirmButtonText: 'OK',
        confirmButtonColor: '#2563eb',
      });

      if (coverPhotoInputRef.current) {
        coverPhotoInputRef.current.value = '';
      }
    } catch (error: any) {
      console.error('Error uploading cover photo:', error);
      Swal.fire({
        title: 'Error!',
        text: error.message || 'Failed to upload cover photo.',
        icon: 'error',
        confirmButtonText: 'OK',
        confirmButtonColor: '#dc2626',
      });
    } finally {
      setIsUploadingCoverPhoto(false);
    }
  };

  return (
    <AppLayoutShopOwner hideHeader={isEditModalOpen}>
      <Head title="Shop Profile - Shop Owner" />
      <div className="min-h-screen bg-gray-50 dark:bg-gray-900 dark:bg-opacity-50">
        <div className="max-w-9xl mx-auto px-0 sm:px-4 lg:px-8 py-0 sm:py-6 lg:py-8">
          <div className="lg:hidden">
            <div className="sticky top-0 z-20 border-b border-gray-200 bg-white/95 px-3 py-3 backdrop-blur-sm">
              <div className="flex items-center gap-2">
                <button type="button" className="p-2 text-gray-700" aria-label="Back">
                  <svg className="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7" />
                  </svg>
                </button>
                <div className="flex h-10 flex-1 items-center gap-2 rounded-xl bg-gray-100 px-3 text-sm text-gray-500">
                  <svg className="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M21 21l-4.35-4.35M11 18a7 7 0 100-14 7 7 0 000 14z" />
                  </svg>
                  Search in shop
                </div>
                <button type="button" className="p-2 text-gray-700" aria-label="Menu">
                  <svg className="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 12h.01M12 12h.01M19 12h.01" />
                  </svg>
                </button>
              </div>
            </div>

            <div className="relative h-36 overflow-hidden bg-linear-to-b from-indigo-900 via-indigo-800 to-indigo-700">
              {coverPhoto && (
                <img src={coverPhoto} alt={`${displayName} cover`} className="h-full w-full object-cover" />
              )}
            </div>

            <div className="relative -mt-12 px-3">
              <div className="rounded-2xl border border-gray-200 bg-white p-4 shadow-md">
                <div className="flex items-start gap-3">
                  <div className="relative flex h-16 w-16 shrink-0 items-center justify-center overflow-hidden rounded-full border-2 border-white bg-blue-100 shadow-sm">
                    {profilePhoto ? (
                      <img src={profilePhoto} alt={displayName} className="h-full w-full object-cover" />
                    ) : (
                      <span className="text-2xl font-bold text-blue-600">{displayName?.slice(0, 1) || "S"}</span>
                    )}
                  </div>
                  <div className="min-w-0 flex-1">
                    <h2 className="truncate text-xl font-semibold text-gray-900">{displayName}</h2>
                    <div className="mt-1 flex items-center gap-2 text-sm text-gray-600">
                      <span className="text-amber-500">★</span>
                      <span>4.8</span>
                      <span>|</span>
                      <span>Shop Owner</span>
                    </div>
                  </div>
                </div>

                <div className="mt-4 grid grid-cols-2 gap-2">
                  <button
                    onClick={() => setIsEditModalOpen(true)}
                    className="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-900"
                  >
                    Edit Profile
                  </button>
                  <button
                    onClick={() => profilePhotoInputRef.current?.click()}
                    disabled={isUploadingPhoto}
                    className="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-900 disabled:opacity-50"
                  >
                    {isUploadingPhoto ? "Uploading..." : "Upload Photo"}
                  </button>
                  <button
                    onClick={() => coverPhotoInputRef.current?.click()}
                    disabled={isUploadingCoverPhoto}
                    className="col-span-2 inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-900 disabled:opacity-50"
                  >
                    {isUploadingCoverPhoto ? "Uploading cover..." : "Upload Cover Photo"}
                  </button>
                </div>
                <input
                  ref={profilePhotoInputRef}
                  type="file"
                  accept="image/*"
                  onChange={handlePhotoUpload}
                  aria-label="Upload profile photo"
                  title="Upload profile photo"
                  className="hidden"
                  disabled={isUploadingPhoto}
                />
                <input
                  ref={coverPhotoInputRef}
                  type="file"
                  accept="image/*"
                  onChange={handleCoverPhotoUpload}
                  aria-label="Upload cover photo"
                  title="Upload cover photo"
                  className="hidden"
                  disabled={isUploadingCoverPhoto}
                />
              </div>
            </div>

            <div className="px-3 pt-3">
              <div className="rounded-2xl border border-gray-200 bg-white p-1 shadow-sm">
                <div className="flex items-center gap-1 overflow-x-auto text-sm">
                  <button className="whitespace-nowrap rounded-lg bg-orange-50 px-4 py-2 font-semibold text-orange-600">Shop</button>
                  <button className="relative whitespace-nowrap rounded-lg px-4 py-2 text-gray-700">
                    Products
                    <span className="ml-1 rounded-md bg-orange-500 px-1.5 py-0.5 text-[10px] font-semibold text-white">New</span>
                  </button>
                  <button className="whitespace-nowrap rounded-lg px-4 py-2 text-gray-700">Reviews</button>
                  <button className="whitespace-nowrap rounded-lg px-4 py-2 text-gray-700">Categories</button>
                </div>
              </div>
            </div>

            <div className="space-y-3 px-3 py-3 pb-6">
              <div className="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm">
                <h3 className="text-lg font-semibold text-gray-900">Shop Details</h3>
                <div className="mt-3 inline-flex rounded-full bg-gray-100 px-3 py-1 text-xs font-medium tracking-wide text-gray-700">
                  RETAIL & REPAIR
                </div>
                <div className="mt-3 text-sm text-gray-700">
                  <p className="text-[11px] font-semibold uppercase tracking-wider text-gray-500">Bio</p>
                  <p className="mt-1 whitespace-pre-wrap">{shopOwner?.bio || "Not set"}</p>
                </div>
              </div>

              <div className="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm">
                <h3 className="text-lg font-semibold text-gray-900">Contact</h3>
                <div className="mt-3 space-y-3 text-sm text-gray-700">
                  <div>
                    <p className="text-[11px] font-semibold uppercase tracking-wider text-gray-500">Location</p>
                    <p>{shopOwner?.city_state || "Not set"}{shopOwner?.country ? `, ${shopOwner.country}` : ""}</p>
                  </div>
                  <div>
                    <p className="text-[11px] font-semibold uppercase tracking-wider text-gray-500">Phone</p>
                    <p>{shopOwner?.phone || "Not set"}</p>
                  </div>
                  <div>
                    <p className="text-[11px] font-semibold uppercase tracking-wider text-gray-500">Email</p>
                    <p>{shopOwner?.email || "Not set"}</p>
                  </div>
                </div>
              </div>

              <div className="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm">
                <h3 className="text-lg font-semibold text-gray-900">Operating Hours</h3>
                <div className="mt-3 space-y-2">
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
                      <div key={day} className="flex items-center justify-between rounded-xl bg-gray-50 px-3 py-2">
                        <div>
                          <p className="text-sm font-semibold text-gray-900">{day}</p>
                          <p className="text-xs text-gray-600">
                            {openTime ? formatTimeTo12Hour(openTime) : "Not set"} - {closeTime ? formatTimeTo12Hour(closeTime) : "Not set"}
                          </p>
                        </div>
                        <span className={`inline-flex rounded-full px-2.5 py-1 text-xs font-semibold ${isClosed ? 'bg-red-100 text-red-700' : 'bg-green-100 text-green-700'}`}>
                          {isClosed ? 'Closed' : 'Open'}
                        </span>
                      </div>
                    );
                  })}
                </div>
              </div>
            </div>
          </div>

          <div className="hidden lg:block">
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
              <div className="relative h-56 w-full overflow-hidden bg-slate-200">
                {coverPhoto && (
                  <img
                    src={coverPhoto}
                    alt={`${displayName} cover`}
                    className="h-full w-full object-cover"
                  />
                )}
                <div className="absolute inset-0 bg-linear-to-t from-black/45 via-black/20 to-black/10" />
                <button
                  type="button"
                  onClick={() => coverPhotoInputRef.current?.click()}
                  disabled={isUploadingCoverPhoto}
                  aria-label="Upload cover photo"
                  title={isUploadingCoverPhoto ? 'Uploading cover...' : 'Upload cover photo'}
                  className="absolute right-5 top-5 inline-flex h-10 w-10 items-center justify-center rounded-xl border border-white/70 bg-black/35 text-white backdrop-blur-sm transition-all hover:bg-black/50 disabled:opacity-50"
                >
                  {isUploadingCoverPhoto ? (
                    <svg className="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none">
                      <circle cx="12" cy="12" r="9" stroke="currentColor" strokeOpacity="0.35" strokeWidth="2" />
                      <path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" strokeWidth="2" strokeLinecap="round" />
                    </svg>
                  ) : (
                    <svg className="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z" />
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 13a3 3 0 11-6 0 3 3 0 016 0z" />
                    </svg>
                  )}
                </button>
              </div>

              <div className="relative px-8 pb-8 pt-0">
                <div className="-mt-12 flex flex-col gap-5 sm:flex-row sm:items-end sm:justify-between">
                  <div className="flex items-end gap-4">
                    <div className="relative">
                      <div className="flex h-28 w-28 items-center justify-center overflow-hidden rounded-2xl border-4 border-white bg-blue-100 shadow-lg dark:border-gray-800 dark:bg-blue-900 dark:bg-opacity-30">
                        {profilePhoto ? (
                          <img
                            src={profilePhoto}
                            alt={displayName}
                            className="h-full w-full object-cover"
                          />
                        ) : (
                          <span className="text-4xl font-bold text-blue-600 dark:text-blue-400">
                            {displayName?.slice(0, 1) || 'S'}
                          </span>
                        )}
                      </div>
                      <button
                        onClick={() => profilePhotoInputRef.current?.click()}
                        disabled={isUploadingPhoto}
                        className="absolute -bottom-2 -right-2 p-2 bg-white dark:bg-gray-800 rounded-lg shadow-lg border-2 border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-700 transition-all disabled:opacity-50 disabled:cursor-not-allowed"
                        title="Upload photo"
                      >
                        <svg className="w-4 h-4 text-gray-600 dark:text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z" />
                          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 13a3 3 0 11-6 0 3 3 0 016 0z" />
                        </svg>
                      </button>
                    </div>

                    <div>
                      <h2 className="text-3xl font-bold text-gray-900 dark:text-white">{displayName}</h2>
                      <p className="mt-1 text-sm text-gray-600 dark:text-gray-400">Shop Owner</p>
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
                      label="Established"
                      value={shopOwner?.established_year ? `Est. ${shopOwner.established_year}` : null}
                      icon={
                        <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M8 7V3m8 4V3m-9 8h10m-13 9h16a2 2 0 002-2V7a2 2 0 00-2-2H4a2 2 0 00-2 2v11a2 2 0 002 2z" />
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
                  <div className="min-w-0 lg:col-span-2">
                    <InfoField
                      label="Bio"
                      value={shopOwner?.bio}
                      icon={
                        <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 6h16M4 12h16M4 18h10" />
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
                                {openTime ? formatTimeTo12Hour(openTime) : <span className="text-gray-400 italic">Not set</span>}
                              </span>
                            </td>
                            <td className="py-4 px-4">
                              <span className="text-gray-700 dark:text-gray-300">
                                {closeTime ? formatTimeTo12Hour(closeTime) : <span className="text-gray-400 italic">Not set</span>}
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
