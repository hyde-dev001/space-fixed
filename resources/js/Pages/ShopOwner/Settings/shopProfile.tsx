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
  business_address?: string;
  shop_address?: string;
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

const resolveStorageUrl = (value?: string | null): string | null => {
  if (!value) return null;

  if (/^(?:https?:|data:|blob:|\/)/i.test(value)) {
    return value;
  }

  return `/storage/${value}`;
};

const getFeedbackColors = () => {
  const isDarkMode = typeof document !== "undefined" && document.documentElement.classList.contains("dark");

  return {
    primary: isDarkMode ? "#2563eb" : "#111111",
    danger: isDarkMode ? "#dc2626" : "#111111",
    cancel: "#6b7280",
  };
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
        confirmButtonColor: getFeedbackColors().primary,
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
        confirmButtonColor: getFeedbackColors().danger,
      });
      return;
    }

    onSave(normalizeOperatingHours(draftHours));
    onClose();
  };

  if (!isOpen) return null;

  return createPortal(
    <div className="fixed inset-0 z-60 flex items-center justify-center bg-black/60 p-4 backdrop-blur-sm">
      <div className="w-full max-w-2xl max-h-[90vh] overflow-y-auto rounded-2xl border border-gray-200 bg-white shadow-2xl dark:border-gray-800 dark:bg-gray-900">
        <div className="sticky top-0 flex items-center justify-between border-b border-gray-200 bg-white px-6 py-4 dark:border-gray-800 dark:bg-gray-900">
          <h3 className="text-xl font-bold text-gray-900 dark:text-white">Set Operating Hours</h3>
          <button
            type="button"
            onClick={onClose}
            aria-label="Close operating hours"
            className="inline-flex min-h-11 min-w-11 items-center justify-center rounded-xl text-gray-600 transition-colors hover:bg-gray-100 hover:text-gray-950 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-950 dark:text-gray-300 dark:hover:bg-gray-800 dark:hover:text-white dark:focus-visible:ring-blue-500"
          >
            <svg className="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
            </svg>
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
              className="min-h-11 rounded-xl border border-gray-300 bg-white px-3 py-2 text-xs font-semibold text-gray-900 transition-colors hover:bg-gray-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-950 dark:border-gray-700 dark:bg-gray-800 dark:text-white dark:hover:bg-gray-700 dark:focus-visible:ring-blue-500"
            >
              Copy Monday to All Days
            </button>
          </div>

          <div className="space-y-3">
            {OPERATING_HOUR_ROWS.map(({ day, openKey, closeKey }) => (
              <div key={day} className="grid grid-cols-1 items-center gap-3 rounded-xl border border-gray-200 bg-gray-50/70 p-3 dark:border-gray-700 dark:bg-gray-800/50 sm:grid-cols-12">
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
                  className="w-full rounded-xl border border-gray-300 bg-white px-3 py-2 text-gray-900 outline-none transition focus:border-gray-950 focus:ring-2 focus:ring-gray-950/10 dark:border-gray-700 dark:bg-gray-800 dark:text-white dark:focus:border-blue-500 dark:focus:ring-blue-500/20 sm:col-span-3"
                />
                <input
                  type="time"
                  name={closeKey}
                  value={draftHours[closeKey as keyof OperatingHours] || ""}
                  onChange={handleTimeChange}
                  aria-label={`${day} closing time`}
                  title={`${day} closing time`}
                  className="w-full rounded-xl border border-gray-300 bg-white px-3 py-2 text-gray-900 outline-none transition focus:border-gray-950 focus:ring-2 focus:ring-gray-950/10 dark:border-gray-700 dark:bg-gray-800 dark:text-white dark:focus:border-blue-500 dark:focus:ring-blue-500/20 sm:col-span-3"
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
                  className="min-h-11 rounded-xl border border-gray-300 bg-white px-3 py-2 text-sm font-semibold text-gray-900 transition-colors hover:bg-gray-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-950 dark:border-red-700 dark:bg-gray-900 dark:text-red-400 dark:hover:bg-red-900/20 dark:focus-visible:ring-red-500 sm:col-span-3"
                >
                  Mark Closed
                </button>
              </div>
            ))}
          </div>
        </div>

        <div className="sticky bottom-0 flex justify-end gap-3 border-t border-gray-200 bg-white px-6 py-4 dark:border-gray-800 dark:bg-gray-900">
          <button
            type="button"
            onClick={onClose}
            className="min-h-11 rounded-xl border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-900 transition-colors hover:bg-gray-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-950 dark:border-gray-700 dark:bg-gray-900 dark:text-white dark:hover:bg-gray-700 dark:focus-visible:ring-blue-500"
          >
            Cancel
          </button>
          <button
            type="button"
            onClick={handleSave}
            className="min-h-11 rounded-xl bg-black px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-gray-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-950 focus-visible:ring-offset-2 dark:bg-blue-600 dark:hover:bg-blue-700 dark:focus-visible:ring-blue-500"
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
  <div className="group rounded-xl border border-gray-200 bg-gray-50/70 p-4 dark:border-gray-700 dark:bg-gray-800/50">
    <div className="flex items-center gap-2">
      {icon && <div className="shrink-0 text-gray-600 dark:text-gray-400">{icon}</div>}
      <span className="text-xs font-semibold uppercase tracking-[0.12em] text-gray-500 dark:text-gray-400">
        {label}
      </span>
    </div>
    <p className="mt-2 break-words text-sm font-semibold text-gray-950 dark:text-white">
      {value || <span className="text-gray-400 dark:text-gray-500 italic">Not set</span>}
    </p>
  </div>
);

const SectionHeader: React.FC<{
  title: string;
  description: string;
  icon: React.ReactNode;
  darkIconClassName?: string;
}> = ({ title, description, icon, darkIconClassName = "dark:bg-gray-700 dark:text-gray-100" }) => (
  <div className="flex items-start gap-3 border-b border-gray-200 px-6 py-5 dark:border-gray-700">
    <div className={`flex h-10 w-10 shrink-0 items-center justify-center rounded-xl border border-gray-200 bg-gray-100 text-gray-950 ${darkIconClassName} dark:border-gray-700`}>
      {icon}
    </div>
    <div className="min-w-0">
      <h2 className="text-lg font-bold text-gray-950 dark:text-white">{title}</h2>
      <p className="mt-1 text-sm text-gray-600 dark:text-gray-400">{description}</p>
    </div>
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
        confirmButtonColor: getFeedbackColors().danger,
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
          confirmButtonColor: getFeedbackColors().primary,
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
          confirmButtonColor: getFeedbackColors().danger,
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
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4 backdrop-blur-sm">
      <div className="w-full max-w-3xl max-h-[90vh] overflow-y-auto rounded-2xl border border-gray-200 bg-white shadow-2xl dark:border-gray-800 dark:bg-gray-900">
        <form onSubmit={handleSubmit}>
          {/* Modal Header */}
          <div className="sticky top-0 border-b border-gray-200 bg-white px-6 py-4 dark:border-gray-800 dark:bg-gray-900">
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
                  <label htmlFor="shop-profile-business-name" className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                    Shop Name
                  </label>
                  <input
                    id="shop-profile-business-name"
                    type="text"
                    name="business_name"
                    value={formData.business_name}
                    onChange={handleChange}
                    className="w-full rounded-xl border border-gray-300 bg-white px-3 py-2 text-gray-900 outline-none transition focus:border-gray-950 focus:ring-2 focus:ring-gray-950/10 dark:border-gray-700 dark:bg-gray-800 dark:text-white dark:focus:border-blue-500 dark:focus:ring-blue-500/20"
                    placeholder="Enter shop name"
                  />
                </div>
                <div>
                  <label htmlFor="shop-profile-established-year" className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                    Established Year
                  </label>
                  <input
                    id="shop-profile-established-year"
                    type="number"
                    name="established_year"
                    value={formData.established_year}
                    onChange={handleChange}
                    min={1900}
                    max={new Date().getFullYear()}
                    className="w-full rounded-xl border border-gray-300 bg-white px-3 py-2 text-gray-900 outline-none transition focus:border-gray-950 focus:ring-2 focus:ring-gray-950/10 dark:border-gray-700 dark:bg-gray-800 dark:text-white dark:focus:border-blue-500 dark:focus:ring-blue-500/20"
                    placeholder="e.g. 2024"
                  />
                </div>
                <div>
                  <label htmlFor="shop-profile-email" className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                    Email Address
                  </label>
                  <input
                    id="shop-profile-email"
                    type="email"
                    name="email"
                    value={formData.email}
                    onChange={handleChange}
                    className="w-full rounded-xl border border-gray-300 bg-white px-3 py-2 text-gray-900 outline-none transition focus:border-gray-950 focus:ring-2 focus:ring-gray-950/10 dark:border-gray-700 dark:bg-gray-800 dark:text-white dark:focus:border-blue-500 dark:focus:ring-blue-500/20"
                    placeholder="Enter email"
                  />
                </div>
                <div>
                  <label htmlFor="shop-profile-phone" className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                    Phone
                  </label>
                  <input
                    id="shop-profile-phone"
                    type="text"
                    name="phone"
                    value={formData.phone}
                    onChange={handleChange}
                    className="w-full rounded-xl border border-gray-300 bg-white px-3 py-2 text-gray-900 outline-none transition focus:border-gray-950 focus:ring-2 focus:ring-gray-950/10 dark:border-gray-700 dark:bg-gray-800 dark:text-white dark:focus:border-blue-500 dark:focus:ring-blue-500/20"
                    placeholder="Enter phone number"
                  />
                </div>
                <div className="sm:col-span-2">
                  <label htmlFor="shop-profile-bio" className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                    Bio
                  </label>
                  <textarea
                    id="shop-profile-bio"
                    name="bio"
                    value={formData.bio}
                    onChange={handleChange}
                    rows={3}
                    className="w-full rounded-xl border border-gray-300 bg-white px-3 py-2 text-gray-900 outline-none transition focus:border-gray-950 focus:ring-2 focus:ring-gray-950/10 dark:border-gray-700 dark:bg-gray-800 dark:text-white dark:focus:border-blue-500 dark:focus:ring-blue-500/20"
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
                  <label htmlFor="shop-profile-country" className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                    Country
                  </label>
                  <input
                    id="shop-profile-country"
                    type="text"
                    name="country"
                    value={formData.country}
                    onChange={handleChange}
                    className="w-full rounded-xl border border-gray-300 bg-white px-3 py-2 text-gray-900 outline-none transition focus:border-gray-950 focus:ring-2 focus:ring-gray-950/10 dark:border-gray-700 dark:bg-gray-800 dark:text-white dark:focus:border-blue-500 dark:focus:ring-blue-500/20"
                    placeholder="Enter country"
                  />
                </div>
                <div>
                  <label htmlFor="shop-profile-city-state" className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                    City/State
                  </label>
                  <input
                    id="shop-profile-city-state"
                    type="text"
                    name="city_state"
                    value={formData.city_state}
                    onChange={handleChange}
                    className="w-full rounded-xl border border-gray-300 bg-white px-3 py-2 text-gray-900 outline-none transition focus:border-gray-950 focus:ring-2 focus:ring-gray-950/10 dark:border-gray-700 dark:bg-gray-800 dark:text-white dark:focus:border-blue-500 dark:focus:ring-blue-500/20"
                    placeholder="Enter city/state"
                  />
                </div>
                <div>
                  <label htmlFor="shop-profile-postal-code" className="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                    Postal Code
                  </label>
                  <input
                    id="shop-profile-postal-code"
                    type="text"
                    name="postal_code"
                    value={formData.postal_code}
                    onChange={handleChange}
                    className="w-full rounded-xl border border-gray-300 bg-white px-3 py-2 text-gray-900 outline-none transition focus:border-gray-950 focus:ring-2 focus:ring-gray-950/10 dark:border-gray-700 dark:bg-gray-800 dark:text-white dark:focus:border-blue-500 dark:focus:ring-blue-500/20"
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
                  className="min-h-11 rounded-xl bg-black px-3 py-2 text-xs font-semibold text-white transition-colors hover:bg-gray-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-950 dark:bg-blue-600 dark:hover:bg-blue-700 dark:focus-visible:ring-blue-500"
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
                    <div key={day} className="rounded-xl border border-gray-200 bg-gray-50/70 p-3 dark:border-gray-700 dark:bg-gray-800/50">
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
          <div className="sticky bottom-0 flex justify-end gap-3 border-t border-gray-200 bg-white px-6 py-4 dark:border-gray-800 dark:bg-gray-900">
            <button
              type="button"
              onClick={handleCancel}
              disabled={isSubmitting}
              className="min-h-11 rounded-xl border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-900 transition-colors hover:bg-gray-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-950 dark:border-gray-700 dark:bg-gray-900 dark:text-white dark:hover:bg-gray-700 dark:focus-visible:ring-blue-500 disabled:opacity-50"
            >
              Cancel
            </button>
            <button
              type="submit"
              disabled={isSubmitting}
              className="min-h-11 rounded-xl bg-black px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-gray-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-950 focus-visible:ring-offset-2 dark:bg-blue-600 dark:hover:bg-blue-700 dark:focus-visible:ring-blue-500 disabled:opacity-50"
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
  const resolvedAddress = shopOwner?.shop_address || shopOwner?.business_address || '';
  const resolvedCityState = shopOwner?.city_state || resolvedAddress;

  const displayName =
    shopOwner?.business_name || shopOwner?.name || "Your shop";

  const [profilePhoto, setProfilePhoto] = useState<string | null>(
    resolveStorageUrl(shopOwner?.profile_photo)
  );
  const [coverPhoto, setCoverPhoto] = useState<string | null>(
    resolveStorageUrl(shopOwner?.cover_photo)
  );
  const [isUploadingPhoto, setIsUploadingPhoto] = useState(false);
  const [isUploadingCoverPhoto, setIsUploadingCoverPhoto] = useState(false);
  const [isEditModalOpen, setIsEditModalOpen] = useState(false);
  const [currentPassword, setCurrentPassword] = useState("");
  const [newPassword, setNewPassword] = useState("");
  const [confirmPassword, setConfirmPassword] = useState("");
  const [isPasswordSubmitting, setIsPasswordSubmitting] = useState(false);
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

  const handlePasswordSubmit = (event: React.FormEvent) => {
    event.preventDefault();

    if (!currentPassword || !newPassword || !confirmPassword) {
      Swal.fire({
        title: "Missing fields",
        text: "Please fill in all password fields.",
        icon: "warning",
        confirmButtonColor: getFeedbackColors().primary,
      });
      return;
    }

    if (newPassword !== confirmPassword) {
      Swal.fire({
        title: "Password mismatch",
        text: "New password and confirmation do not match.",
        icon: "error",
        confirmButtonColor: getFeedbackColors().danger,
      });
      return;
    }

    setIsPasswordSubmitting(true);
    router.post('/shop-owner/shop-profile/password', {
      current_password: currentPassword,
      password: newPassword,
      password_confirmation: confirmPassword,
    }, {
      preserveScroll: true,
      onSuccess: () => {
        setCurrentPassword("");
        setNewPassword("");
        setConfirmPassword("");
        setIsPasswordSubmitting(false);
        Swal.fire({
          title: "Password updated",
          text: "Your password has been updated successfully.",
          icon: "success",
          confirmButtonColor: getFeedbackColors().primary,
        });
      },
      onError: (errors: any) => {
        setIsPasswordSubmitting(false);
        const message = errors?.current_password || errors?.password || "Please check your input and try again.";

        Swal.fire({
          title: "Password update failed",
          text: message,
          icon: "error",
          confirmButtonColor: getFeedbackColors().danger,
        });
      },
    });
  };

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
      setProfilePhoto(resolveStorageUrl(data.profile_photo));

      Swal.fire({
        title: 'Success!',
        text: 'Profile photo uploaded successfully.',
        icon: 'success',
        confirmButtonText: 'OK',
        confirmButtonColor: getFeedbackColors().primary,
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
        confirmButtonColor: getFeedbackColors().danger,
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
      setCoverPhoto(resolveStorageUrl(data.cover_photo));

      Swal.fire({
        title: 'Success!',
        text: 'Cover photo uploaded successfully.',
        icon: 'success',
        confirmButtonText: 'OK',
        confirmButtonColor: getFeedbackColors().primary,
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
        confirmButtonColor: getFeedbackColors().danger,
      });
    } finally {
      setIsUploadingCoverPhoto(false);
    }
  };

  const handleRemoveProfilePhoto = async () => {
    if (!profilePhoto || isUploadingPhoto) return;

    const result = await Swal.fire({
      title: 'Remove profile photo?',
      text: 'This will remove your current profile picture.',
      icon: 'warning',
      showCancelButton: true,
      confirmButtonText: 'Remove',
      cancelButtonText: 'Cancel',
      confirmButtonColor: getFeedbackColors().danger,
      cancelButtonColor: getFeedbackColors().cancel,
    });

    if (!result.isConfirmed) return;

    setIsUploadingPhoto(true);
    try {
      const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');

      const response = await fetch('/api/shop-owner/profile-photo', {
        method: 'DELETE',
        credentials: 'include',
        headers: {
          'X-CSRF-TOKEN': csrfToken || '',
          'X-Requested-With': 'XMLHttpRequest',
        },
      });

      if (!response.ok) {
        const text = await response.text();
        let backendMessage = '';
        try {
          const parsed = JSON.parse(text);
          backendMessage = parsed?.message || '';
        } catch {
          backendMessage = '';
        }
        throw new Error(backendMessage || `Remove failed: ${response.status} ${response.statusText}`);
      }

      setProfilePhoto(null);

      Swal.fire({
        title: 'Removed!',
        text: 'Profile photo removed successfully.',
        icon: 'success',
        confirmButtonColor: getFeedbackColors().primary,
      });
    } catch (error: any) {
      console.error('Error removing profile photo:', error);
      Swal.fire({
        title: 'Error!',
        text: error.message || 'Failed to remove profile photo.',
        icon: 'error',
        confirmButtonColor: getFeedbackColors().danger,
      });
    } finally {
      setIsUploadingPhoto(false);
    }
  };

  const handleRemoveCoverPhoto = async () => {
    if (!coverPhoto || isUploadingCoverPhoto) return;

    const result = await Swal.fire({
      title: 'Remove cover photo?',
      text: 'This will remove your current cover picture.',
      icon: 'warning',
      showCancelButton: true,
      confirmButtonText: 'Remove',
      cancelButtonText: 'Cancel',
      confirmButtonColor: getFeedbackColors().danger,
      cancelButtonColor: getFeedbackColors().cancel,
    });

    if (!result.isConfirmed) return;

    setIsUploadingCoverPhoto(true);
    try {
      const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');

      const response = await fetch('/api/shop-owner/cover-photo', {
        method: 'DELETE',
        credentials: 'include',
        headers: {
          'X-CSRF-TOKEN': csrfToken || '',
          'X-Requested-With': 'XMLHttpRequest',
        },
      });

      if (!response.ok) {
        const text = await response.text();
        let backendMessage = '';
        try {
          const parsed = JSON.parse(text);
          backendMessage = parsed?.message || '';
        } catch {
          backendMessage = '';
        }
        throw new Error(backendMessage || `Remove failed: ${response.status} ${response.statusText}`);
      }

      setCoverPhoto(null);

      Swal.fire({
        title: 'Removed!',
        text: 'Cover photo removed successfully.',
        icon: 'success',
        confirmButtonColor: getFeedbackColors().primary,
      });
    } catch (error: any) {
      console.error('Error removing cover photo:', error);
      Swal.fire({
        title: 'Error!',
        text: error.message || 'Failed to remove cover photo.',
        icon: 'error',
        confirmButtonColor: getFeedbackColors().danger,
      });
    } finally {
      setIsUploadingCoverPhoto(false);
    }
  };

  return (
    <AppLayoutShopOwner hideHeader={isEditModalOpen}>
      <Head title="Shop Profile - Shop Owner" />
      <div className="min-h-screen bg-gray-50 dark:bg-gray-900 dark:bg-opacity-50">
        <main className="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
          <div className="mb-6 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div>
              <p className="text-xs font-bold uppercase tracking-[0.18em] text-gray-500 dark:text-gray-400">
                Account settings
              </p>
              <h1 className="mt-2 text-3xl font-bold tracking-tight text-gray-950 dark:text-white">
                Shop Profile
              </h1>
              <p className="mt-2 max-w-2xl text-sm text-gray-600 dark:text-gray-300">
                Keep your shop identity, contact details, and operating hours up to date.
              </p>
            </div>
            <span className="inline-flex w-fit items-center rounded-full border border-gray-300 bg-white px-3 py-1.5 text-xs font-semibold text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
              Shop Owner
            </span>
          </div>

          <section className="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:border-opacity-50 dark:bg-gray-800 dark:bg-opacity-50">
            <div className="relative h-48 overflow-hidden bg-gray-200 sm:h-64 lg:h-72 dark:bg-gray-800">
              {coverPhoto ? (
                <img
                  src={coverPhoto}
                  alt={displayName + " cover photo"}
                  className="h-full w-full object-cover"
                />
              ) : (
                <div className="h-full w-full bg-[linear-gradient(135deg,#e5e7eb_25%,#d1d5db_25%,#d1d5db_50%,#e5e7eb_50%,#e5e7eb_75%,#d1d5db_75%)] bg-size-[32px_32px] dark:bg-[linear-gradient(135deg,#374151_25%,#1f2937_25%,#1f2937_50%,#374151_50%,#374151_75%,#1f2937_75%)]" />
              )}
              <div className="absolute inset-0 bg-linear-to-t from-black/60 via-black/10 to-transparent" />
              <div className="absolute right-4 top-4 flex gap-2 sm:right-6 sm:top-6">
                <button
                  type="button"
                  onClick={handleRemoveCoverPhoto}
                  disabled={isUploadingCoverPhoto || !coverPhoto}
                  aria-label="Remove cover photo"
                  title="Remove cover photo"
                  className="inline-flex h-11 w-11 items-center justify-center rounded-xl border border-white/80 bg-black/45 text-white shadow-sm backdrop-blur-sm transition-colors hover:bg-black/70 focus:outline-none focus-visible:ring-2 focus-visible:ring-white disabled:cursor-not-allowed disabled:opacity-40 dark:border-red-300/70 dark:bg-red-600/80 dark:hover:bg-red-700 dark:focus-visible:ring-red-400"
                >
                  <svg className="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6M9 7V4a1 1 0 011-1h4a1 1 0 011 1v3M4 7h16" />
                  </svg>
                </button>
                <button
                  type="button"
                  onClick={() => coverPhotoInputRef.current?.click()}
                  disabled={isUploadingCoverPhoto}
                  aria-label="Upload cover photo"
                  title={isUploadingCoverPhoto ? "Uploading cover photo" : "Upload cover photo"}
                  aria-busy={isUploadingCoverPhoto}
                  className="inline-flex h-11 w-11 items-center justify-center rounded-xl border border-white/80 bg-black/45 text-white shadow-sm backdrop-blur-sm transition-colors hover:bg-black/70 focus:outline-none focus-visible:ring-2 focus-visible:ring-white disabled:cursor-not-allowed disabled:opacity-50 dark:focus-visible:ring-blue-400"
                >
                  {isUploadingCoverPhoto ? (
                    <svg className="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                      <circle cx="12" cy="12" r="9" stroke="currentColor" strokeOpacity="0.35" strokeWidth="2" />
                      <path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" strokeWidth="2" strokeLinecap="round" />
                    </svg>
                  ) : (
                    <svg className="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z" />
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 13a3 3 0 11-6 0 3 3 0 016 0z" />
                    </svg>
                  )}
                </button>
              </div>
            </div>

            <div className="relative px-5 pb-6 sm:px-8 sm:pb-8">
              <div className="-mt-14 flex flex-col gap-5 sm:-mt-16 lg:flex-row lg:items-end lg:justify-between">
                <div className="flex min-w-0 flex-col gap-4 sm:flex-row sm:items-end">
                  <div className="relative w-fit shrink-0">
                    <div className="flex h-28 w-28 items-center justify-center overflow-hidden rounded-2xl border-4 border-white bg-gray-950 text-white shadow-lg dark:border-gray-900 dark:bg-blue-900/30 dark:text-blue-300 sm:h-32 sm:w-32">
                      {profilePhoto ? (
                        <img
                          src={profilePhoto}
                          alt={displayName + " profile photo"}
                          className="h-full w-full object-cover"
                        />
                      ) : (
                        <span className="text-4xl font-bold">{displayName.slice(0, 1) || "S"}</span>
                      )}
                    </div>
                    <button
                      type="button"
                      onClick={handleRemoveProfilePhoto}
                      disabled={isUploadingPhoto || !profilePhoto}
                      title="Remove profile photo"
                      aria-label="Remove profile photo"
                      className="absolute -bottom-2 -left-2 inline-flex h-11 w-11 items-center justify-center rounded-xl border border-gray-300 bg-white text-gray-950 shadow-md transition-colors hover:bg-gray-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-950 disabled:cursor-not-allowed disabled:opacity-40 dark:border-red-700 dark:bg-gray-900 dark:text-red-400 dark:hover:bg-red-900/20 dark:focus-visible:ring-red-500"
                    >
                      <svg className="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6M9 7V4a1 1 0 011-1h4a1 1 0 011 1v3M4 7h16" />
                      </svg>
                    </button>
                    <button
                      type="button"
                      onClick={() => profilePhotoInputRef.current?.click()}
                      disabled={isUploadingPhoto}
                      title={isUploadingPhoto ? "Uploading profile photo" : "Upload profile photo"}
                      aria-label="Upload profile photo"
                      aria-busy={isUploadingPhoto}
                      className="absolute -bottom-2 -right-2 inline-flex h-11 w-11 items-center justify-center rounded-xl border border-gray-300 bg-white text-gray-950 shadow-md transition-colors hover:bg-gray-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-950 disabled:cursor-not-allowed disabled:opacity-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700 dark:focus-visible:ring-blue-500"
                    >
                      {isUploadingPhoto ? (
                        <svg className="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                          <circle cx="12" cy="12" r="9" stroke="currentColor" strokeOpacity="0.35" strokeWidth="2" />
                          <path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" strokeWidth="2" strokeLinecap="round" />
                        </svg>
                      ) : (
                        <svg className="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z" />
                          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 13a3 3 0 11-6 0 3 3 0 016 0z" />
                        </svg>
                      )}
                    </button>
                  </div>

                  <div className="min-w-0 pb-1">
                    <h2 className="truncate text-2xl font-bold text-gray-950 dark:text-white sm:text-3xl">{displayName}</h2>
                    <div className="mt-2 flex flex-wrap items-center gap-x-3 gap-y-2 text-sm text-gray-600 dark:text-gray-300">
                      <span className="inline-flex items-center gap-1.5">
                        <svg className="h-4 w-4 text-gray-500 dark:text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 21a9 9 0 100-18 9 9 0 000 18zm0-11a3 3 0 100-6 3 3 0 000 6zm0 0c-3.314 0-6 1.343-6 3v2h12v-2c0-1.657-2.686-3-6-3z" />
                        </svg>
                        Shop Owner
                      </span>
                      <span className="hidden text-gray-300 sm:inline dark:text-gray-600">•</span>
                      <span className="inline-flex items-center gap-1.5">
                        <svg className="h-4 w-4 text-gray-500 dark:text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 21s8-4.438 8-10a8 8 0 10-16 0c0 5.562 8 10 8 10z" />
                          <circle cx="12" cy="11" r="2.5" />
                        </svg>
                        {[resolvedCityState, shopOwner?.country].filter(Boolean).join(", ") || "Location not set"}
                      </span>
                    </div>
                  </div>
                </div>

                <div className="flex flex-col gap-2 sm:flex-row lg:pb-1">
                  <button
                    type="button"
                    onClick={() => setIsEditModalOpen(true)}
                    className="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl bg-black px-5 py-3 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-gray-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-950 focus-visible:ring-offset-2 dark:bg-blue-600 dark:hover:bg-blue-700 dark:focus-visible:ring-blue-500"
                  >
                    <svg className="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7" />
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z" />
                    </svg>
                    Edit profile
                  </button>
                  <button
                    type="button"
                    onClick={() => profilePhotoInputRef.current?.click()}
                    disabled={isUploadingPhoto}
                    className="inline-flex min-h-11 items-center justify-center rounded-xl border border-gray-300 bg-white px-5 py-3 text-sm font-semibold text-gray-900 transition-colors hover:bg-gray-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-950 disabled:cursor-not-allowed disabled:opacity-50 dark:border-gray-700 dark:bg-gray-900 dark:text-white dark:hover:bg-gray-800 dark:focus-visible:ring-blue-500"
                  >
                    {isUploadingPhoto ? "Uploading..." : "Upload profile photo"}
                  </button>
                </div>
              </div>
            </div>

            <input
              ref={profilePhotoInputRef}
              type="file"
              accept="image/jpeg,image/png,image/jpg,image/gif"
              onChange={handlePhotoUpload}
              aria-label="Upload profile photo"
              title="Upload profile photo"
              className="hidden"
              disabled={isUploadingPhoto}
            />
            <input
              ref={coverPhotoInputRef}
              type="file"
              accept="image/jpeg,image/png,image/jpg,image/webp"
              onChange={handleCoverPhotoUpload}
              aria-label="Upload cover photo"
              title="Upload cover photo"
              className="hidden"
              disabled={isUploadingCoverPhoto}
            />
          </section>

          <nav className="mt-5 overflow-x-auto rounded-xl border border-gray-200 bg-white p-1 shadow-sm dark:border-gray-700 dark:border-opacity-50 dark:bg-gray-800 dark:bg-opacity-50" aria-label="Shop profile sections">
            <div className="flex min-w-max items-center gap-1">
              <a href="#overview" className="rounded-lg bg-gray-950 px-4 py-2.5 text-sm font-semibold text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-950 focus-visible:ring-offset-2 dark:bg-blue-600 dark:focus-visible:ring-blue-500">
                Overview
              </a>
              <a href="#address" className="rounded-lg px-4 py-2.5 text-sm font-semibold text-gray-700 transition-colors hover:bg-gray-100 hover:text-gray-950 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-950 dark:text-gray-300 dark:hover:bg-gray-800 dark:hover:text-white dark:focus-visible:ring-blue-500">
                Address
              </a>
              <a href="#hours" className="rounded-lg px-4 py-2.5 text-sm font-semibold text-gray-700 transition-colors hover:bg-gray-100 hover:text-gray-950 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-950 dark:text-gray-300 dark:hover:bg-gray-800 dark:hover:text-white dark:focus-visible:ring-blue-500">
                Hours
              </a>
              <a href="#security" className="rounded-lg px-4 py-2.5 text-sm font-semibold text-gray-700 transition-colors hover:bg-gray-100 hover:text-gray-950 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-950 dark:text-gray-300 dark:hover:bg-gray-800 dark:hover:text-white dark:focus-visible:ring-blue-500">
                Security
              </a>
            </div>
          </nav>

          <div className="mt-5 grid gap-5 lg:grid-cols-[minmax(0,1.3fr)_minmax(20rem,0.7fr)]">
            <div className="space-y-5">
              <section id="overview" className="scroll-mt-6 overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:border-opacity-50 dark:bg-gray-800 dark:bg-opacity-50">
                <SectionHeader
                  title="Personal information"
                  description="The public identity and contact details for your shop."
                  icon={
                    <svg className="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                    </svg>
                  }
                />
                <div className="grid gap-3 p-5 sm:grid-cols-2 sm:p-6">
                  <InfoField
                    label="Shop name"
                    value={shopOwner?.business_name || shopOwner?.name}
                    icon={
                      <svg className="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                      </svg>
                    }
                  />
                  <InfoField
                    label="Email address"
                    value={shopOwner?.email}
                    icon={
                      <svg className="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5v10a2 2 0 002 2h14" />
                      </svg>
                    }
                  />
                  <InfoField
                    label="Established"
                    value={shopOwner?.established_year ? "Est. " + shopOwner.established_year : null}
                    icon={
                      <svg className="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M8 7V3m8 4V3m-9 8h10m-13 9h16a2 2 0 002-2V7a2 2 0 00-2-2H4a2 2 0 00-2 2v11a2 2 0 002 2z" />
                      </svg>
                    }
                  />
                  <InfoField
                    label="Phone"
                    value={shopOwner?.phone}
                    icon={
                      <svg className="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a2 2 0 012 2v3a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" />
                      </svg>
                    }
                  />
                  <div className="sm:col-span-2">
                    <InfoField
                      label="Bio"
                      value={shopOwner?.bio}
                      icon={
                        <svg className="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                          <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 6h16M4 12h16M4 18h10" />
                        </svg>
                      }
                    />
                  </div>
                </div>
              </section>

              <section id="address" className="scroll-mt-6 overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:border-opacity-50 dark:bg-gray-800 dark:bg-opacity-50">
                <SectionHeader
                  title="Address information"
                  description="The location customers and staff associate with this shop."
                  icon={
                    <svg className="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" />
                    </svg>
                  }
                />
                <div className="grid gap-3 p-5 sm:grid-cols-2 sm:p-6">
                  <InfoField
                    label="Address"
                    value={resolvedAddress}
                    icon={
                      <svg className="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" />
                      </svg>
                    }
                  />
                  <InfoField
                    label="Country"
                    value={shopOwner?.country}
                    icon={
                      <svg className="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                      </svg>
                    }
                  />
                  <InfoField
                    label="City/State"
                    value={resolvedCityState}
                    icon={
                      <svg className="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                      </svg>
                    }
                  />
                  <InfoField
                    label="Postal code"
                    value={shopOwner?.postal_code}
                    icon={
                      <svg className="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5v10a2 2 0 002 2h14" />
                      </svg>
                    }
                  />
                </div>
              </section>
            </div>

            <div className="space-y-5">
              <section id="hours" className="scroll-mt-6 overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:border-opacity-50 dark:bg-gray-800 dark:bg-opacity-50">
                <SectionHeader
                  title="Operating hours"
                  description="Your weekly schedule as shown to customers."
                  darkIconClassName="dark:bg-purple-900/30 dark:text-purple-300"
                  icon={
                    <svg className="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                  }
                />
                <div className="space-y-2 p-5 sm:p-6">
                  {OPERATING_HOUR_ROWS.map(({ day, openKey, closeKey }) => {
                    const openTime = operatingHours[openKey as keyof OperatingHours];
                    const closeTime = operatingHours[closeKey as keyof OperatingHours];
                    const isClosed = !openTime || !closeTime;

                    return (
                      <div key={day} className="flex items-center justify-between gap-3 rounded-xl border border-gray-200 bg-gray-50/70 px-4 py-3 dark:border-gray-700 dark:bg-gray-800/50">
                        <div className="min-w-0">
                          <p className="text-sm font-semibold text-gray-950 dark:text-white">{day}</p>
                          <p className="mt-1 text-xs text-gray-600 dark:text-gray-300">
                            {isClosed ? "Not set" : formatTimeTo12Hour(openTime) + " - " + formatTimeTo12Hour(closeTime)}
                          </p>
                        </div>
                        <span className={isClosed
                          ? "inline-flex shrink-0 rounded-full bg-gray-200 px-2.5 py-1 text-xs font-semibold text-gray-700 dark:bg-red-900/30 dark:text-red-300"
                          : "inline-flex shrink-0 rounded-full bg-gray-950 px-2.5 py-1 text-xs font-semibold text-white dark:bg-green-900/30 dark:text-green-300"}>
                          {isClosed ? "Closed" : "Open"}
                        </span>
                      </div>
                    );
                  })}
                </div>
              </section>

              <section id="security" className="scroll-mt-6 overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:border-opacity-50 dark:bg-gray-800 dark:bg-opacity-50">
                <SectionHeader
                  title="Security"
                  description="Update the password used to access your shop account."
                  icon={
                    <svg className="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 15v2m-6 4h12a2 2 0 002-2v-5a2 2 0 00-2-2H6a2 2 0 00-2 2v5a2 2 0 002 2zm10-9V7a4 4 0 00-8 0v3h8z" />
                    </svg>
                  }
                />
                <div className="border-b border-gray-200 px-5 py-4 dark:border-gray-800 sm:px-6">
                  <h3 className="text-base font-semibold text-gray-950 dark:text-white">Change password</h3>
                  <p className="mt-1 text-sm text-gray-600 dark:text-gray-400">Use a strong password you do not reuse elsewhere.</p>
                </div>
                <form onSubmit={handlePasswordSubmit} className="space-y-4 p-5 sm:p-6">
                  <div>
                    <label htmlFor="shop-profile-current-password" className="mb-1.5 block text-sm font-semibold text-gray-800 dark:text-gray-200">
                      Current password
                    </label>
                    <input
                      id="shop-profile-current-password"
                      type="password"
                      value={currentPassword}
                      onChange={(e) => setCurrentPassword(e.target.value)}
                      autoComplete="current-password"
                      className="w-full rounded-xl border border-gray-300 bg-white px-3 py-3 text-sm text-gray-950 outline-none transition focus:border-gray-950 focus:ring-2 focus:ring-gray-950/10 dark:border-gray-700 dark:bg-gray-800 dark:text-white dark:focus:border-blue-500 dark:focus:ring-blue-500/20"
                    />
                  </div>
                  <div>
                    <label htmlFor="shop-profile-new-password" className="mb-1.5 block text-sm font-semibold text-gray-800 dark:text-gray-200">
                      New password
                    </label>
                    <input
                      id="shop-profile-new-password"
                      type="password"
                      value={newPassword}
                      onChange={(e) => setNewPassword(e.target.value)}
                      autoComplete="new-password"
                      className="w-full rounded-xl border border-gray-300 bg-white px-3 py-3 text-sm text-gray-950 outline-none transition focus:border-gray-950 focus:ring-2 focus:ring-gray-950/10 dark:border-gray-700 dark:bg-gray-800 dark:text-white dark:focus:border-blue-500 dark:focus:ring-blue-500/20"
                    />
                  </div>
                  <div>
                    <label htmlFor="shop-profile-confirm-password" className="mb-1.5 block text-sm font-semibold text-gray-800 dark:text-gray-200">
                      Confirm new password
                    </label>
                    <input
                      id="shop-profile-confirm-password"
                      type="password"
                      value={confirmPassword}
                      onChange={(e) => setConfirmPassword(e.target.value)}
                      autoComplete="new-password"
                      className="w-full rounded-xl border border-gray-300 bg-white px-3 py-3 text-sm text-gray-950 outline-none transition focus:border-gray-950 focus:ring-2 focus:ring-gray-950/10 dark:border-gray-700 dark:bg-gray-800 dark:text-white dark:focus:border-blue-500 dark:focus:ring-blue-500/20"
                    />
                  </div>
                  <button
                    type="submit"
                    disabled={isPasswordSubmitting}
                    className="inline-flex min-h-11 w-full items-center justify-center rounded-xl bg-black px-4 py-3 text-sm font-semibold text-white transition-colors hover:bg-gray-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-950 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 dark:bg-blue-600 dark:hover:bg-blue-700 dark:focus-visible:ring-blue-500"
                  >
                    {isPasswordSubmitting ? "Updating..." : "Update password"}
                  </button>
                </form>
              </section>
            </div>
          </div>

          <div className="mt-5 flex flex-col gap-3 rounded-2xl border border-dashed border-gray-300 bg-gray-50/80 px-5 py-4 text-sm text-gray-600 dark:border-gray-700 dark:bg-gray-800/50 dark:text-gray-300 sm:flex-row sm:items-center sm:justify-between sm:px-6">
            <span>Profile photos support JPG, PNG, GIF, and WebP cover images.</span>
            <button
              type="button"
              onClick={() => setIsEditModalOpen(true)}
              className="inline-flex min-h-11 w-fit items-center justify-center rounded-xl border border-gray-300 bg-white px-4 py-2.5 text-sm font-semibold text-gray-900 transition-colors hover:bg-gray-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-950 dark:border-gray-700 dark:bg-gray-900 dark:text-white dark:hover:bg-gray-800 dark:focus-visible:ring-blue-500"
            >
              Edit details
            </button>
          </div>

          <EditProfileModal
            isOpen={isEditModalOpen}
            onClose={() => setIsEditModalOpen(false)}
            shopOwner={shopOwner}
            operatingHours={operatingHours}
            onOperatingHoursChange={setOperatingHours}
          />
        </main>
      </div>
    </AppLayoutShopOwner>
  );
};

export default ShopProfile;
