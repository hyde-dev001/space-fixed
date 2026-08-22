import AppLayoutERP from '@/layout/AppLayout_ERP';
import { logisticsApi } from '@/services/logisticsApi';
import type {
  RiderDeliveryIssue,
  RiderDeliveryPageData,
  RiderDeliveryTab,
  RiderDeliveryWorkItem,
  TrackingShipmentLeg,
} from '@/types/logistics';
import { workflowFeedback } from '@/utils/workflowFeedback';
import { GPS_POSITION_OPTIONS, getCurrentPositionWithTimeout } from '@/utils/geolocation';
import { Head, router, usePage } from '@inertiajs/react';
import axios from 'axios';
import { FormEvent, type ReactNode, useEffect, useRef, useState } from 'react';
import {
  arrivalStatusText,
  completedProgress,
  deliveryContact,
  deliveryStatusLabel,
  nextActionableDelivery,
  orderedDeliveries,
  riderResolutionInstruction,
} from './riderDeliveryPresentation';

type ActionConfirmation = Parameters<typeof workflowFeedback.confirm>[0];
type ActionRunner = (
  key: string,
  action: () => Promise<unknown>,
  confirmation?: ActionConfirmation,
  onError?: (error: unknown) => boolean,
) => void;

const arrivalReasons = [
  ['gps_inaccurate', 'GPS location is inaccurate'],
  ['pin_incorrect', 'Shop or customer pin is incorrect'],
  ['alternate_meeting_point', 'Met at another location'],
  ['access_restriction', 'Road or access restriction'],
  ['safety_concern', 'Safety concern'],
  ['other', 'Other'],
] as const;

const arrivalResultLabel = (value: unknown): string | null => ({
  outside_geofence: 'Outside geofence',
  low_accuracy: 'Low GPS accuracy',
  location_unavailable: 'Location unavailable',
}[String(value ?? '')] ?? null);

const isCompactViewport = () =>
  typeof window !== 'undefined' && window.innerWidth < 1280;

type PickerOption = readonly [string, string];

function CompactModalPicker({
  pickerId,
  label,
  value,
  onChange,
  options,
  placeholder,
  className = '',
}: {
  pickerId: string;
  label: string;
  value: string;
  onChange: (value: string) => void;
  options: readonly PickerOption[];
  placeholder: string;
  className?: string;
}) {
  const [isOpen, setIsOpen] = useState(false);
  const pickerRef = useRef<HTMLDivElement>(null);
  const closeButtonRef = useRef<HTMLButtonElement>(null);
  const modalIsOpen = isOpen && isCompactViewport();
  const listboxId = `${pickerId}-options`;
  const dialogId = `${pickerId}-dialog`;
  const pickerOptions: readonly PickerOption[] = [['', placeholder] as const, ...options];
  const selectedLabel = pickerOptions.find(([option]) => option === value)?.[1] ?? placeholder;

  useEffect(() => {
    if (!modalIsOpen) return;

    const previouslyFocused = document.activeElement as HTMLElement | null;
    const previousOverflow = document.body.style.overflow;

    const closeOnOutsidePress = (event: PointerEvent) => {
      if (!pickerRef.current?.contains(event.target as Node)) {
        setIsOpen(false);
      }
    };
    const closeOnEscape = (event: KeyboardEvent) => {
      if (event.key === 'Escape') setIsOpen(false);
    };
    const closeOnResize = () => {
      if (!isCompactViewport()) setIsOpen(false);
    };

    document.addEventListener('pointerdown', closeOnOutsidePress);
    document.addEventListener('keydown', closeOnEscape);
    window.addEventListener('resize', closeOnResize);
    document.body.style.overflow = 'hidden';
    closeButtonRef.current?.focus();

    return () => {
      document.removeEventListener('pointerdown', closeOnOutsidePress);
      document.removeEventListener('keydown', closeOnEscape);
      window.removeEventListener('resize', closeOnResize);
      document.body.style.overflow = previousOverflow;
      if (previouslyFocused && document.contains(previouslyFocused)) previouslyFocused.focus();
    };
  }, [modalIsOpen]);

  const choose = (nextValue: string) => {
    onChange(nextValue);
    setIsOpen(false);
  };

  return (
    <div ref={pickerRef} className="relative mt-1">
      <select
        id={pickerId}
        aria-label={label}
        aria-controls={modalIsOpen ? dialogId : undefined}
        aria-expanded={modalIsOpen}
        value={value}
        onChange={(event) => choose(event.target.value)}
        onPointerDown={(event) => {
          if (!isCompactViewport()) return;
          event.preventDefault();
          event.stopPropagation();
          setIsOpen(true);
        }}
        onKeyDown={(event) => {
          if (!isCompactViewport()) return;
          if (event.key === 'Escape') {
            event.preventDefault();
            setIsOpen(false);
          } else if (event.key === 'Enter' || event.key === ' ' || event.key === 'ArrowDown') {
            event.preventDefault();
            setIsOpen(true);
          }
        }}
        className={`min-h-12 w-full appearance-none rounded-2xl border border-slate-300 bg-white px-4 pr-11 text-base text-slate-950 transition-colors focus:outline-none focus:ring-2 focus:ring-slate-950 focus:ring-offset-2 dark:border-slate-700 dark:bg-slate-950 dark:text-white xl:min-h-11 xl:appearance-auto xl:rounded-xl xl:px-3 xl:pr-3 xl:text-sm ${className}`}
      >
        {pickerOptions.map(([option, optionLabel]) => (
          <option key={option || 'empty'} value={option}>{optionLabel}</option>
        ))}
      </select>
      <svg
        aria-hidden="true"
        className="pointer-events-none absolute right-4 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-950 xl:hidden dark:text-white"
        viewBox="0 0 20 20"
        fill="none"
      >
        <path d="m5 7.5 5 5 5-5" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" />
      </svg>
      {modalIsOpen && (
        <div
          className="fixed inset-0 z-[100001] flex items-center justify-center bg-slate-950/60 p-4 backdrop-blur-[2px]"
          onPointerDown={() => setIsOpen(false)}
        >
          <div
            id={dialogId}
            role="dialog"
            aria-modal="true"
            aria-labelledby={`${dialogId}-title`}
            onPointerDown={(event) => event.stopPropagation()}
            className="flex max-h-[calc(100dvh-2rem)] w-full max-w-md flex-col overflow-hidden rounded-3xl border border-slate-200 bg-white text-slate-950 shadow-2xl dark:border-slate-700 dark:bg-slate-950 dark:text-white"
          >
            <header className="flex items-start justify-between gap-4 border-b border-slate-200 p-5 dark:border-slate-700">
              <div>
                <p className="text-[11px] font-bold uppercase tracking-[0.16em] text-slate-500 dark:text-slate-400">
                  Choose one
                </p>
                <h2 id={`${dialogId}-title`} className="mt-1 text-lg font-extrabold tracking-tight">
                  {label}
                </h2>
                <p className="mt-1 text-sm text-slate-600 dark:text-slate-300">
                  {selectedLabel === placeholder ? 'Select an option to continue.' : `Selected: ${selectedLabel}`}
                </p>
              </div>
              <button
                ref={closeButtonRef}
                type="button"
                aria-label={`Close ${label} picker`}
                onClick={() => setIsOpen(false)}
                className="inline-flex min-h-11 min-w-11 shrink-0 touch-manipulation items-center justify-center rounded-full border border-slate-300 text-slate-950 transition-colors hover:bg-slate-100 focus:outline-none focus:ring-2 focus:ring-slate-950 focus:ring-offset-2 dark:border-slate-700 dark:text-white dark:hover:bg-slate-900"
              >
                <svg aria-hidden="true" className="h-5 w-5" viewBox="0 0 20 20" fill="none">
                  <path d="m5 5 10 10M15 5 5 15" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" />
                </svg>
              </button>
            </header>
            <div className="min-h-0 overflow-y-auto p-3">
              <div
                id={listboxId}
                role="listbox"
                aria-label={`${label} options`}
                className="space-y-1"
              >
                {pickerOptions.map(([option, optionLabel]) => (
                  <button
                    key={option || 'empty-option'}
                    type="button"
                    role="option"
                    aria-selected={value === option}
                    onClick={() => choose(option)}
                    className={`min-h-12 w-full touch-manipulation rounded-2xl border px-4 text-left text-sm font-semibold transition-colors focus:outline-none focus:ring-2 focus:ring-slate-950 focus:ring-inset dark:focus:ring-white ${
                      value === option
                        ? 'border-slate-950 bg-slate-950 text-white dark:border-white dark:bg-white dark:text-slate-950'
                        : 'border-slate-200 bg-white text-slate-950 hover:bg-slate-100 dark:border-slate-700 dark:bg-slate-950 dark:text-white dark:hover:bg-slate-900'
                    }`}
                  >
                    <span className="flex items-center justify-between gap-3">
                      <span>{optionLabel}</span>
                      {value === option && (
                        <svg aria-hidden="true" className="h-4 w-4 shrink-0" viewBox="0 0 20 20" fill="none">
                          <path d="m4 10 4 4 8-8" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" />
                        </svg>
                      )}
                    </span>
                  </button>
                ))}
              </div>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}

function DeliveryActionModal({
  modalId,
  open,
  title,
  description,
  onClose,
  children,
}: {
  modalId: string;
  open: boolean;
  title: string;
  description: string;
  onClose: () => void;
  children: ReactNode;
}) {
  const closeButtonRef = useRef<HTMLButtonElement>(null);
  const onCloseRef = useRef(onClose);
  onCloseRef.current = onClose;

  useEffect(() => {
    if (!open) return;

    const previouslyFocused = document.activeElement as HTMLElement | null;
    const previousOverflow = document.body.style.overflow;
    const closeOnEscape = (event: KeyboardEvent) => {
      if (event.key === 'Escape') onCloseRef.current();
    };

    document.addEventListener('keydown', closeOnEscape);
    document.body.style.overflow = 'hidden';
    closeButtonRef.current?.focus();

    return () => {
      document.removeEventListener('keydown', closeOnEscape);
      document.body.style.overflow = previousOverflow;
      if (previouslyFocused && document.contains(previouslyFocused)) previouslyFocused.focus();
    };
  }, [open]);

  if (!open) return null;

  return (
    <div
      className="fixed inset-0 z-[100000] flex items-center justify-center bg-slate-950/60 p-4 backdrop-blur-[2px] sm:p-6"
      onPointerDown={(event) => {
        if (event.target === event.currentTarget) onClose();
      }}
    >
      <div
        role="dialog"
        aria-modal="true"
        aria-labelledby={`${modalId}-title`}
        aria-describedby={`${modalId}-description`}
        className="flex max-h-[calc(100dvh-2rem)] w-full max-w-2xl flex-col overflow-hidden rounded-3xl border border-slate-200 bg-white text-slate-950 shadow-2xl dark:border-slate-700 dark:bg-slate-950 dark:text-white sm:max-h-[calc(100dvh-3rem)]"
      >
        <header className="flex items-start justify-between gap-4 border-b border-slate-200 p-5 dark:border-slate-700 sm:p-6">
          <div className="min-w-0">
            <p className="text-[11px] font-bold uppercase tracking-[0.16em] text-slate-500 dark:text-slate-400">
              Delivery action
            </p>
            <h2 id={`${modalId}-title`} className="mt-1 text-xl font-extrabold tracking-tight sm:text-2xl">
              {title}
            </h2>
            <p id={`${modalId}-description`} className="mt-1 text-sm leading-5 text-slate-600 dark:text-slate-300">
              {description}
            </p>
          </div>
          <button
            ref={closeButtonRef}
            type="button"
            aria-label={`Close ${title}`}
            onClick={onClose}
            className="inline-flex min-h-11 min-w-11 shrink-0 touch-manipulation items-center justify-center rounded-2xl border border-slate-300 text-slate-950 transition-colors hover:bg-slate-100 focus:outline-none focus:ring-2 focus:ring-slate-950 focus:ring-offset-2 dark:border-slate-700 dark:text-white dark:hover:bg-slate-900"
          >
            <svg aria-hidden="true" className="h-5 w-5" viewBox="0 0 20 20" fill="none">
              <path d="m5 5 10 10M15 5 5 15" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" />
            </svg>
          </button>
        </header>
        <div className="min-h-0 overflow-y-auto p-4 sm:p-6">
          {children}
        </div>
      </div>
    </div>
  );
}

function DeliveryPhotoUpload({
  inputId,
  inputLabel,
  label,
  file,
  onChange,
  required = false,
  accept = 'image/*',
  capture,
}: {
  inputId: string;
  inputLabel: string;
  label: string;
  file: File | null;
  onChange: (file: File | null) => void;
  required?: boolean;
  accept?: string;
  capture?: 'environment';
}) {
  const inputRef = useRef<HTMLInputElement>(null);
  const [previewUrl, setPreviewUrl] = useState<string | null>(null);

  useEffect(() => {
    if (!file || typeof URL === 'undefined' || typeof URL.createObjectURL !== 'function') {
      setPreviewUrl(null);
      return;
    }

    const nextPreviewUrl = URL.createObjectURL(file);
    setPreviewUrl(nextPreviewUrl);

    return () => {
      if (typeof URL.revokeObjectURL === 'function') URL.revokeObjectURL(nextPreviewUrl);
    };
  }, [file]);

  const openFilePicker = () => inputRef.current?.click();
  const removeFile = () => {
    if (inputRef.current) inputRef.current.value = '';
    onChange(null);
  };

  return (
    <div className="space-y-2">
      <div className="flex items-start justify-between gap-3">
        <div className="min-w-0">
          <p className="text-sm font-semibold text-slate-950 dark:text-white">
            {label}{' '}
            <span className="font-medium text-slate-500 dark:text-slate-400">
              · {required ? 'Required' : 'Optional'}
            </span>
          </p>
          <p className="mt-1 text-xs leading-5 text-slate-500 dark:text-slate-400">
            {required ? 'Add a clear photo to continue.' : 'Add a photo if it helps explain the issue.'}
          </p>
        </div>
        {file && (
          <span className="shrink-0 rounded-full bg-slate-100 px-2.5 py-1 text-[11px] font-bold text-slate-700 dark:bg-slate-800 dark:text-slate-200">
            Ready
          </span>
        )}
      </div>

      <input
        ref={inputRef}
        id={inputId}
        type="file"
        required={required}
        accept={accept}
        capture={capture}
        aria-label={inputLabel}
        onChange={(event) => onChange(event.target.files?.[0] ?? null)}
        className="sr-only"
      />

      {file ? (
        <div className="flex items-center gap-3 rounded-2xl border border-slate-200 bg-slate-50 p-3 dark:border-slate-700 dark:bg-slate-900">
          <div className="h-16 w-16 shrink-0 overflow-hidden rounded-xl border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-950">
            {previewUrl ? (
              <img
                src={previewUrl}
                alt={`${label} preview`}
                className="h-full w-full object-cover"
              />
            ) : (
              <div className="flex h-full items-center justify-center px-2 text-center text-[10px] font-bold uppercase tracking-wide text-slate-500">
                Image ready
              </div>
            )}
          </div>
          <div className="min-w-0 flex-1">
            <p className="truncate text-sm font-semibold text-slate-950 dark:text-white">{file.name}</p>
            <button
              type="button"
              onClick={openFilePicker}
              className="mt-1 inline-flex min-h-11 items-center rounded-lg px-1 text-xs font-bold text-slate-700 underline decoration-slate-400 underline-offset-2 transition-colors hover:text-slate-950 focus:outline-none focus:ring-2 focus:ring-slate-950 focus:ring-offset-2 dark:text-slate-200 dark:hover:text-white dark:focus:ring-white"
            >
              Replace photo
            </button>
          </div>
          <button
            type="button"
            aria-label={`Remove ${inputLabel}`}
            title="Remove photo"
            onClick={removeFile}
            className="inline-flex min-h-11 min-w-11 shrink-0 items-center justify-center rounded-xl border border-slate-300 bg-white text-slate-700 transition-colors hover:bg-slate-100 hover:text-slate-950 focus:outline-none focus:ring-2 focus:ring-slate-950 focus:ring-offset-2 dark:border-slate-600 dark:bg-slate-950 dark:text-slate-200 dark:hover:bg-slate-800 dark:hover:text-white dark:focus:ring-white"
          >
            <svg aria-hidden="true" className="h-5 w-5" viewBox="0 0 24 24" fill="none">
              <path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7l1-3h4l1 3" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" />
            </svg>
          </button>
        </div>
      ) : (
        <div className="flex flex-col items-start gap-3 rounded-2xl border border-dashed border-slate-300 bg-slate-50 p-4 sm:flex-row sm:items-center dark:border-slate-700 dark:bg-slate-900">
          <div className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-700 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-200">
            <svg aria-hidden="true" className="h-5 w-5" viewBox="0 0 24 24" fill="none">
              <path d="M4 16.5V19a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-2.5M12 4v11m0-11L8.5 7.5M12 4l3.5 3.5" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" />
            </svg>
          </div>
          <div className="min-w-0 flex-1">
            <p className="text-sm font-semibold text-slate-950 dark:text-white">No photo selected</p>
            <p className="mt-1 text-xs leading-5 text-slate-500 dark:text-slate-400">
              Upload a clear photo from your device or camera.
            </p>
          </div>
          <button
            type="button"
            onClick={openFilePicker}
            className="inline-flex min-h-11 w-full items-center justify-center rounded-xl bg-slate-950 px-4 text-sm font-bold text-white transition-colors hover:bg-slate-800 focus:outline-none focus:ring-2 focus:ring-slate-950 focus:ring-offset-2 sm:w-auto dark:bg-white dark:text-slate-950 dark:hover:bg-slate-200 dark:focus:ring-white"
          >
            Upload photo
          </button>
        </div>
      )}
    </div>
  );
}

const photoIssueReasons = new Set([
  'recipient_unavailable',
  'wrong_or_incomplete_address',
  'recipient_refused',
  'item_damaged',
]);

const noteIssueReasons = new Set([
  'unsafe_location',
  'vehicle_or_delivery_problem',
  'other',
]);

const deliveryIssueReasons = [
  ['recipient_unavailable', 'Recipient unavailable'],
  ['wrong_or_incomplete_address', 'Wrong or incomplete address'],
  ['recipient_refused', 'Recipient refused'],
  ['item_damaged', 'Item damaged'],
  ['unsafe_location', 'Unsafe location'],
  ['vehicle_or_delivery_problem', 'Vehicle or delivery problem'],
  ['other', 'Other'],
] as const;

const pickupIssueReasons = [
  ['customer_unavailable', 'Customer unavailable / not home'],
  ['customer_requested_reschedule', 'Customer requested reschedule'],
  ['customer_refused_pickup', 'Customer refused pickup'],
  ['item_not_ready', 'Item not ready or unavailable'],
  ['wrong_address_or_pin', 'Wrong address or map pin'],
  ['unsafe_or_inaccessible_location', 'Unsafe or inaccessible location'],
  ['vehicle_or_rider_problem', 'Vehicle or rider problem'],
  ['other', 'Other'],
] as const;

const incidentTypes = [
  ['damaged', 'Parcel damaged'],
  ['lost', 'Parcel lost'],
  ['vehicle_problem', 'Vehicle or route problem'],
  ['customer_dispute', 'Customer dispute'],
  ['other', 'Other incident'],
] as const;

const currentPosition = () => {
  if (!navigator.geolocation) {
    return Promise.reject(new Error('Location is unavailable on this device.'));
  }
  return getCurrentPositionWithTimeout({ ...GPS_POSITION_OPTIONS, timeout: 10_000 });
};

const needsArrivalReason = (error: unknown) => {
  const response = (error as {
    response?: { status?: number; data?: { errors?: Record<string, string[]> } };
  })?.response;

  return Boolean(
    (response?.status === 422 && response.data?.errors?.exception_reason) ||
    (typeof error === 'object' && error !== null && 'code' in error) ||
    (error instanceof Error && [
      'Location is unavailable on this device.',
      'Location request timed out.',
    ].includes(error.message)),
  );
};

const tabLabels: Record<RiderDeliveryTab, string> = {
  upcoming: 'Upcoming',
  history: 'History',
  issues: 'Issues',
  all: 'All',
};

const itemTitle = (item: RiderDeliveryWorkItem) =>
  item.kind === 'batch' ? `Batch #${item.id}` : `Single delivery #${item.id}`;

const deliveryCount = (item: RiderDeliveryWorkItem) =>
  `${item.deliveries.length} ${item.deliveries.length === 1 ? 'delivery' : 'deliveries'}`;

const scheduleText = (item: RiderDeliveryWorkItem) => {
  const date = item.delivery_date ? String(item.delivery_date).split('T')[0] : 'Not scheduled';
  const window = item.delivery_window
    ? item.delivery_window.replace(/\b\w/g, (letter) => letter.toUpperCase())
    : null;

  return window ? `${date} · ${window}` : date;
};

const retryDateText = (value?: string | null) => value
  ? new Intl.DateTimeFormat('en-PH', { dateStyle: 'medium', timeZone: 'UTC' }).format(
      new Date(`${value.slice(0, 10)}T00:00:00Z`),
    )
  : 'Not scheduled';

function StatusChip({ status, label }: { status: string; label?: string }) {
  const symbol = ['delivered', 'completed'].includes(status)
    ? '✓'
    : ['cancelled', 'declined', 'delivery_attempted'].includes(status)
      ? '!'
      : '•';

  return (
    <span className="inline-flex min-h-7 items-center gap-1.5 rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-700 dark:bg-slate-700 dark:text-slate-100">
      <span aria-hidden="true">{symbol}</span>
      {label ?? deliveryStatusLabel(status)}
    </span>
  );
}

function ResolutionNotice({ delivery }: { delivery: TrackingShipmentLeg }) {
  const instruction = riderResolutionInstruction(delivery);
  if (!instruction) return null;

  return (
    <p
      role="status"
      className="mt-2 rounded-xl border border-amber-300 bg-amber-50 p-3 text-sm font-semibold text-amber-950 dark:border-amber-800 dark:bg-amber-950/30 dark:text-amber-100"
    >
      <span aria-hidden="true">!</span> {instruction}
    </p>
  );
}

function DeliveryContact({ delivery }: { delivery: TrackingShipmentLeg }) {
  const contact = deliveryContact(delivery);

  return (
    <div className="space-y-3">
      <div>
        <p className="text-base font-bold text-slate-950 dark:text-white">
          {contact.name || 'Customer details unavailable'}
        </p>
        <p className="mt-1 text-sm leading-5 text-slate-600 dark:text-slate-300">
          {contact.address || 'Address not provided'}
        </p>
        {contact.instructions && (
          <p className="mt-2 rounded-lg bg-amber-50 p-3 text-sm text-amber-950 dark:bg-amber-950/40 dark:text-amber-100">
            <strong>Instruction:</strong> {contact.instructions}
          </p>
        )}
      </div>

      <div className="grid grid-cols-2 gap-3 xl:gap-2">
        {contact.phone ? (
          <a
            href={`tel:${contact.phone}`}
            className="inline-flex min-h-12 items-center justify-center rounded-xl border border-slate-300 px-3 text-sm font-semibold text-slate-800 transition-colors focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 dark:border-slate-600 dark:text-slate-100 xl:min-h-11"
          >
            Call
          </a>
        ) : (
          <span className="inline-flex min-h-12 items-center justify-center rounded-xl border border-slate-200 px-3 text-sm text-slate-400 xl:min-h-11">
            No phone
          </span>
        )}
        {contact.address ? (
          <a
            href={`https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(contact.address)}`}
            target="_blank"
            rel="noreferrer"
            className="inline-flex min-h-12 items-center justify-center rounded-xl border border-slate-300 px-3 text-sm font-semibold text-slate-950 transition-colors hover:bg-slate-100 focus:outline-none focus:ring-2 focus:ring-slate-950 focus:ring-offset-2 dark:border-slate-600 dark:text-white dark:hover:bg-slate-800 dark:focus:ring-white xl:min-h-11"
          >
            Directions
          </a>
        ) : (
          <span className="inline-flex min-h-12 items-center justify-center rounded-xl border border-slate-200 px-3 text-sm text-slate-400 xl:min-h-11">
            No address
          </span>
        )}
      </div>
    </div>
  );
}

function DeliverySequence({ item }: { item: RiderDeliveryWorkItem }) {
  return (
    <ol data-testid="delivery-sequence" className="mt-4 space-y-2 border-t border-slate-200 pt-4 dark:border-slate-700">
      {orderedDeliveries(item.deliveries).map((delivery, index) => {
        const contact = deliveryContact(delivery);
        const sequence = delivery.stop_sequence ?? index + 1;
        const symbol = delivery.status === 'delivered' ? '✓' : delivery.status === 'delivery_attempted' ? '!' : sequence;

        return (
          <li key={delivery.id} className="flex gap-3 rounded-xl bg-slate-50 p-3 dark:bg-slate-800">
            <span className="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-white text-sm font-bold text-slate-700 ring-1 ring-slate-200 dark:bg-slate-900 dark:text-slate-100 dark:ring-slate-700">
              {symbol}
            </span>
            <div className="min-w-0 flex-1">
              <div className="flex flex-wrap items-center justify-between gap-2">
                <p className="font-semibold text-slate-950 dark:text-white">
                  Delivery #{delivery.id} · Stop {sequence}
                </p>
                <StatusChip status={delivery.status} />
              </div>
              <ResolutionNotice delivery={delivery} />
              <p className="mt-1 truncate text-sm text-slate-600 dark:text-slate-300">
                {contact.name || 'Customer'} · {contact.address || 'Address unavailable'}
              </p>
            </div>
          </li>
        );
      })}
    </ol>
  );
}

function DeliveryActions({
  item,
  delivery,
  locked,
  online,
  pendingAction,
  canRecordProof,
  today,
  runAction,
}: {
  item: RiderDeliveryWorkItem;
  delivery?: TrackingShipmentLeg;
  locked: boolean;
  online: boolean;
  pendingAction: string | null;
  canRecordProof: boolean;
  today: string;
  runAction: ActionRunner;
}) {
  const [proofFile, setProofFile] = useState<File | null>(null);
  const [showIssue, setShowIssue] = useState(false);
  const [issueReason, setIssueReason] = useState('');
  const [issueNotes, setIssueNotes] = useState('');
  const [issueFile, setIssueFile] = useState<File | null>(null);
  const [showIncident, setShowIncident] = useState(false);
  const [incidentType, setIncidentType] = useState('');
  const [incidentNotes, setIncidentNotes] = useState('');
  const [incidentFile, setIncidentFile] = useState<File | null>(null);
  const [showArrivalReason, setShowArrivalReason] = useState(false);
  const [arrivalResult, setArrivalResult] = useState<string | null>(null);
  const [arrivalReason, setArrivalReason] = useState('');
  const [arrivalNotes, setArrivalNotes] = useState('');
  const arrivalEvidence = useRef<Record<string, unknown> | null>(null);
  const issueKeys = useRef<Record<string, string>>({});
  const proofKeys = useRef<Record<string, string>>({});
  const mutationDisabled = locked || !online || pendingAction !== null;
  const buttonClass =
    'min-h-12 w-full touch-manipulation rounded-xl bg-blue-600 px-4 text-sm font-bold text-white transition-colors focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 xl:min-h-11';
  const compactPrimaryButtonClass =
    'min-h-12 w-full touch-manipulation rounded-2xl bg-slate-950 px-4 text-sm font-bold text-white transition-colors focus:outline-none focus:ring-2 focus:ring-slate-950 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 dark:bg-white dark:text-slate-950 xl:min-h-11 xl:rounded-xl';
  const compactSecondaryButtonClass =
    'min-h-12 w-full touch-manipulation rounded-2xl border border-slate-300 bg-white px-4 text-sm font-bold text-slate-950 transition-colors focus:outline-none focus:ring-2 focus:ring-slate-950 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 dark:border-slate-700 dark:bg-slate-950 dark:text-white xl:min-h-11 xl:rounded-xl';

  if (item.kind === 'batch' && item.status === 'accepted') {
    const key = `batch-start:${item.id}`;

    return (
      <button
        type="button"
        disabled={mutationDisabled || pendingAction === key}
        onClick={() =>
          runAction(key, () => logisticsApi.startBatch(item.id), {
            title: `Start batch #${item.id}?`,
            text: 'This will begin the batch and make its first stop active.',
            confirmButtonText: 'Start batch',
          })
        }
        className={buttonClass}
      >
        Start batch
      </button>
    );
  }

  if (!delivery) return null;
  const isReturnToShop = delivery.leg_type === 'return_to_shop';
  const isStagedRetry = delivery.status === 'needs_resolution' && delivery.resolution_type === 'retry';
  const isRepairPickup = delivery.shipment?.purpose === 'repair_pickup'
    && ['assigned', 'pickup_scheduled'].includes(delivery.status);
  const requiresIssuePhoto = isRepairPickup || photoIssueReasons.has(issueReason);
  const requiresIssueNotes = isRepairPickup
    ? issueReason === 'other'
    : noteIssueReasons.has(issueReason);
  const assignment = delivery.assignments?.find(({ status }) =>
    ['assigned', 'accepted'].includes(status),
  );
  const rejectedProofId = delivery.proofs
    .filter(({ review_status }) => review_status === 'rejected')
    .at(-1)?.id ?? 'initial';
  const proofIdempotencyKey = (handoffType: string) => {
    const requestKey = `${handoffType}:${delivery.id}:${assignment?.id ?? 'none'}:${rejectedProofId}`;

    return proofKeys.current[requestKey]
      ?? (proofKeys.current[requestKey] = crypto.randomUUID());
  };
  const issueKey = `${isRepairPickup ? 'pickup-issue' : 'issue'}:${delivery.id}`;
  const issueRequestKey = `${isRepairPickup ? 'pickup' : 'delivery'}:${delivery.id}:${assignment?.id ?? 'none'}`;
  const issueOptions = isRepairPickup ? pickupIssueReasons : deliveryIssueReasons;
  const deliveryReference =
    item.kind === 'batch'
      ? `stop ${delivery.stop_sequence ?? delivery.id} in batch #${item.id}`
      : `delivery #${delivery.id}`;

  if (isStagedRetry) {
    const scheduledDate = delivery.scheduled_delivery_date?.slice(0, 10) ?? null;
    const retryDue = Boolean(scheduledDate && scheduledDate <= today);
    const key = `delivery-retry:${delivery.id}`;

    return (
      <div className="space-y-3 rounded-xl border border-amber-300 bg-amber-50 p-3 dark:border-amber-800 dark:bg-amber-950/30">
        <p className="text-sm font-semibold text-amber-950 dark:text-amber-100">
          Retry scheduled for {retryDateText(scheduledDate)}
        </p>
        <button
          type="button"
          disabled={mutationDisabled || !retryDue || pendingAction === key}
          onClick={() => runAction(key, () => logisticsApi.markInTransit(delivery.id), {
            title: `Start retry for ${deliveryReference}?`,
            text: 'This starts the scheduled delivery retry without recording another pickup.',
            confirmButtonText: 'Start retry',
          })}
          className={buttonClass}
        >
          Start retry
        </button>
        {!retryDue && (
          <p role="status" className="text-center text-xs font-semibold text-amber-800 dark:text-amber-200">
            Retry is unavailable until the scheduled date.
          </p>
        )}
      </div>
    );
  }
  const isCustodyHold = delivery.status === 'needs_resolution' && delivery.resolution_type === null;
  if (isCustodyHold) {
    return (
      <div className="space-y-2 rounded-xl border border-amber-300 bg-amber-50 p-3 dark:border-amber-800 dark:bg-amber-950/30">
        <p className="text-sm font-semibold text-amber-950 dark:text-amber-100">
          Delivery attempt limit reached
        </p>
        <p className="text-sm text-amber-900 dark:text-amber-200">
          Keep the parcel secured. A dispatcher must choose the next resolution before you can start other work.
        </p>
      </div>
    );
  }
  const submitIssue = () => {
    if (
      !issueReason ||
      !assignment ||
      (requiresIssuePhoto && !issueFile) ||
      (requiresIssueNotes && !issueNotes.trim())
    ) return;
    const form = new FormData();
    const idempotencyKey = issueKeys.current[issueRequestKey]
      ?? (issueKeys.current[issueRequestKey] = crypto.randomUUID());
    form.append('attempt_type', isRepairPickup ? 'pickup' : 'delivery');
    form.append('delivery_assignment_id', String(assignment.id));
    form.append('idempotency_key', idempotencyKey);
    form.append('reason_code', issueReason);
    if (issueNotes.trim()) form.append('notes', issueNotes.trim());
    if (issueFile) form.append('proof_file', issueFile);
    runAction(
      issueKey,
      () => logisticsApi.reportIssue(delivery.id, form),
      {
        title: isRepairPickup
          ? `Submit failed pickup for ${deliveryReference}?`
          : `Submit issue for ${deliveryReference}?`,
        text: isRepairPickup
          ? 'The dispatcher will choose whether to reschedule or cancel this pickup.'
          : 'This records a failed delivery attempt for dispatcher review.',
        confirmButtonText: isRepairPickup ? 'Submit failed pickup' : 'Submit issue',
      },
    );
  };
  const submitIncident = () => {
    if (!incidentType || !incidentNotes.trim() || !incidentFile) return;
    const form = new FormData();
    form.append('type', incidentType);
    form.append('notes', incidentNotes.trim());
    form.append('photo_files[]', incidentFile);
    runAction(
      `incident:${delivery.id}`,
      () => logisticsApi.reportIncident(delivery.id, form),
      {
        title: `Report incident for ${deliveryReference}?`,
        text: 'The dispatcher will review this incident and choose the next resolution.',
        confirmButtonText: 'Report incident',
      },
    );
  };
  const issuePanel = (
    <DeliveryActionModal
      modalId={`delivery-issue-${delivery.id}`}
      open={showIssue}
      title={isRepairPickup ? 'Failed pickup' : 'Report issue'}
      description={isRepairPickup
        ? 'Record why the pickup could not be completed.'
        : 'Share the delivery issue for dispatcher review.'}
      onClose={() => setShowIssue(false)}
    >
      <div
        aria-label={isRepairPickup ? 'Failed pickup details' : 'Delivery issue details'}
        className="space-y-4 rounded-2xl border border-slate-200 bg-white p-4 text-slate-950 dark:border-slate-700 dark:bg-slate-950 dark:text-white sm:p-5"
      >
        <label className="block text-sm font-semibold">
          {isRepairPickup ? 'Failed pickup reason' : 'Issue reason'}
          <CompactModalPicker
            pickerId={`${isRepairPickup ? 'failed-pickup' : 'delivery-issue'}-reason-${delivery.id}`}
            label={isRepairPickup ? 'Failed pickup reason' : 'Issue reason'}
            value={issueReason}
            onChange={setIssueReason}
            options={issueOptions}
            placeholder="Choose a reason"
          />
        </label>
        <label className="block text-sm font-semibold">
          {isRepairPickup ? 'Failed pickup notes' : 'Notes'} {requiresIssueNotes ? '(required)' : '(optional)'}
          <textarea
            aria-label={isRepairPickup ? 'Failed pickup notes' : 'Issue notes'}
            required={requiresIssueNotes}
            value={issueNotes}
            onChange={(event) => setIssueNotes(event.target.value)}
            className="mt-2 min-h-24 w-full rounded-2xl border border-slate-300 bg-white p-4 text-base text-slate-950 focus:outline-none focus:ring-2 focus:ring-slate-950 focus:ring-offset-2 dark:border-slate-700 dark:bg-slate-950 dark:text-white"
          />
        </label>
        <DeliveryPhotoUpload
          inputId={`${isRepairPickup ? 'failed-pickup' : 'delivery-issue'}-photo-${delivery.id}`}
          inputLabel={isRepairPickup ? 'Failed pickup photo' : 'Issue photo'}
          label={isRepairPickup ? 'Failed pickup photo' : 'Issue photo'}
          file={issueFile}
          onChange={setIssueFile}
          required={requiresIssuePhoto}
          capture={isRepairPickup ? 'environment' : undefined}
        />
        {!online && (
          <p role="status" className="text-center text-sm font-semibold text-slate-700 dark:text-slate-300">
            Retry after reconnect
          </p>
        )}
        <button
          type="button"
          disabled={
            mutationDisabled ||
            !assignment ||
            !issueReason ||
            (requiresIssuePhoto && !issueFile) ||
            (requiresIssueNotes && !issueNotes.trim()) ||
            pendingAction === issueKey
          }
          onClick={submitIssue}
          className={compactPrimaryButtonClass}
        >
          {isRepairPickup ? 'Submit failed pickup' : 'Submit issue'}
        </button>
      </div>
    </DeliveryActionModal>
  );
  const incidentPanel = (
    <DeliveryActionModal
      modalId={`delivery-incident-${delivery.id}`}
      open={showIncident}
      title="Report incident"
      description="Add the details and evidence the dispatcher needs to review this incident."
      onClose={() => setShowIncident(false)}
    >
      <div
        aria-label="Delivery incident details"
        className="space-y-4 rounded-2xl border border-slate-200 bg-white p-4 text-slate-950 dark:border-slate-700 dark:bg-slate-950 dark:text-white sm:p-5"
      >
        <label className="block text-sm font-semibold">
          Incident type
          <CompactModalPicker
            pickerId={`delivery-incident-type-${delivery.id}`}
            label="Incident type"
            value={incidentType}
            onChange={setIncidentType}
            options={incidentTypes}
            placeholder="Choose an incident"
          />
        </label>
        <label className="block text-sm font-semibold">
          What happened?
          <textarea
            aria-label="Incident notes"
            value={incidentNotes}
            onChange={(event) => setIncidentNotes(event.target.value)}
            className="mt-2 min-h-24 w-full rounded-2xl border border-slate-300 bg-white p-4 text-base text-slate-950 focus:outline-none focus:ring-2 focus:ring-slate-950 focus:ring-offset-2 dark:border-slate-700 dark:bg-slate-950 dark:text-white"
          />
        </label>
        <DeliveryPhotoUpload
          inputId={`delivery-incident-photo-${delivery.id}`}
          inputLabel="Incident evidence photo"
          label="Evidence photo"
          file={incidentFile}
          onChange={setIncidentFile}
          required
          accept="image/jpeg,image/png,image/webp"
        />
        <button
          type="button"
          disabled={mutationDisabled || !incidentType || !incidentNotes.trim() || !incidentFile}
          onClick={submitIncident}
          className={compactPrimaryButtonClass}
        >
          Submit incident
        </button>
      </div>
    </DeliveryActionModal>
  );
  const arrivalPhase = ['assigned', 'pickup_scheduled'].includes(delivery.status)
    ? 'pickup'
    : delivery.status === 'in_transit'
      ? 'dropoff'
      : null;
  const arrival = arrivalPhase ? delivery.arrivals?.[arrivalPhase] : undefined;
  const arrivalKey = `arrival:${delivery.id}`;
  const recordArrival = () => {
    if (!arrivalPhase) return;
    setArrivalResult(null);
    runAction(
      arrivalKey,
      async () => {
        const position = await currentPosition();
        const payload = {
          arrival_type: arrivalPhase,
          latitude: position.coords.latitude,
          longitude: position.coords.longitude,
          accuracy_m: position.coords.accuracy,
          captured_at: new Date(position.timestamp).toISOString(),
        };
        arrivalEvidence.current = payload;

        return logisticsApi.arrive(delivery.id, payload);
      },
      undefined,
      (error) => {
        if (!needsArrivalReason(error)) return false;
        const response = (error as {
          response?: { data?: { errors?: Record<string, string[]> } };
        })?.response;
        setArrivalResult(arrivalResultLabel(response?.data?.errors?.arrival_result?.[0]));
        setShowArrivalReason(true);
        return true;
      },
    );
  };
  const submitArrivalReason = () => {
    if (!arrivalPhase || !arrivalReason || (arrivalReason === 'other' && !arrivalNotes.trim())) {
      return;
    }
    runAction(arrivalKey, () => logisticsApi.arrive(delivery.id, {
      ...(arrivalEvidence.current ?? {
        arrival_type: arrivalPhase,
        latitude: null,
        longitude: null,
        accuracy_m: null,
        captured_at: null,
      }),
      exception_reason: arrivalReason,
      exception_notes: arrivalNotes.trim() || null,
    }));
  };
  const arrivalControl = arrivalPhase && !arrival ? (
    <div className="space-y-3">
      <button
        type="button"
        disabled={mutationDisabled || pendingAction === arrivalKey}
        onClick={recordArrival}
        className={buttonClass}
      >
        I've arrived
      </button>
      {!online && (
        <p role="status" className="text-center text-sm font-semibold text-amber-700 dark:text-amber-300">
          Retry after reconnect
        </p>
      )}
      {showArrivalReason && (
        <div className="space-y-3 rounded-xl border border-amber-300 bg-amber-50 p-3 dark:border-amber-800 dark:bg-amber-950/30">
          <p className="text-sm text-amber-950 dark:text-amber-100">
            {arrivalResult
              ? `${arrivalResult}. Choose a reason to continue.`
              : 'Location could not be verified. Choose a reason to continue.'}
          </p>
          <label className="block text-sm font-semibold">
            Arrival reason
            <CompactModalPicker
              pickerId={`arrival-reason-${delivery.id}`}
              label="Arrival reason"
              value={arrivalReason}
              onChange={setArrivalReason}
              options={arrivalReasons}
              placeholder="Choose a reason"
              className="xl:border-amber-300 xl:focus:ring-amber-500 xl:dark:border-amber-800 xl:dark:bg-slate-900"
            />
          </label>
          <label className="block text-sm font-semibold">
            Arrival notes {arrivalReason === 'other' ? '(required)' : '(optional)'}
            <textarea
              aria-label="Arrival notes"
              value={arrivalNotes}
              onChange={(event) => setArrivalNotes(event.target.value)}
              className="mt-1 min-h-20 w-full rounded-xl border border-amber-300 bg-white p-3 dark:bg-slate-900"
            />
          </label>
          <button
            type="button"
            disabled={
              mutationDisabled ||
              !arrivalReason ||
              (arrivalReason === 'other' && !arrivalNotes.trim())
            }
            onClick={submitArrivalReason}
            className={buttonClass}
          >
            Continue with reason
          </button>
        </div>
      )}
    </div>
  ) : null;
  const arrivalSummary = arrival ? (
    <p
      role="status"
      aria-live="polite"
      className="rounded-xl bg-slate-50 p-3 text-sm font-semibold text-slate-800 dark:bg-slate-800 dark:text-slate-100"
    >
      <span aria-hidden="true">{arrival.result === 'verified' ? '✓' : '!'}</span>{' '}
      {arrivalStatusText(arrival)}
    </p>
  ) : null;

  if (['assigned', 'pickup_scheduled'].includes(delivery.status)) {
    if (!arrival) return arrivalControl;
    const key = `pickup:${delivery.id}`;
    const pickupProof = delivery.proofs
      ?.filter(({ handoff_type }) => handoff_type === 'pickup')
      .at(-1);

    return (
      <div className="space-y-3">
        {arrivalSummary}
        <button
          type="button"
          disabled={mutationDisabled || pendingAction === key}
          onClick={() =>
            runAction(
              key,
              () =>
                pickupProof
                  ? logisticsApi.confirmPickup(delivery.id, pickupProof.id)
                  : logisticsApi.markPickedUp(delivery.id),
              {
                title: `Confirm pickup for ${deliveryReference}?`,
                text: 'This confirms that the parcel is now in your custody.',
                confirmButtonText: 'Confirm pickup',
              },
            )
          }
          className={buttonClass}
        >
          Confirm pickup
        </button>
        {isRepairPickup && (
          <>
            <button
              type="button"
              disabled={mutationDisabled}
              onClick={() => setShowIssue((current) => !current)}
              className={compactSecondaryButtonClass}
            >
              Failed pickup
            </button>
            {issuePanel}
          </>
        )}
      </div>
    );
  }

  if (delivery.status === 'picked_up') {
    const key = `delivery-start:${delivery.id}`;
    const actionLabel = isReturnToShop ? 'Start return to shop' : 'Start delivery';

    return (
      <button
        type="button"
        disabled={mutationDisabled || pendingAction === key}
        onClick={() =>
          runAction(
            key,
            () =>
              item.kind === 'batch'
                ? logisticsApi.outForDelivery(delivery.id)
                : logisticsApi.markInTransit(delivery.id),
            {
              title: `${actionLabel} for ${deliveryReference}?`,
              text: isReturnToShop
                ? 'This marks the parcel as on the way back to the shop.'
                : 'This marks the parcel as on the way to its destination.',
              confirmButtonText: actionLabel,
            },
          )
        }
        className={buttonClass}
      >
        {actionLabel}
      </button>
    );
  }

  if (isReturnToShop && delivery.status === 'in_transit') {
    const returnProof = delivery.proofs
      ?.filter(({ handoff_type }) => handoff_type === 'receive')
      .at(-1);
    if (returnProof?.review_status === 'rider_confirmed') {
      return (
        <p role="status" className="rounded-xl bg-amber-50 p-3 text-sm font-semibold text-amber-900 dark:bg-amber-950/30 dark:text-amber-200">
          Return handed to shop · waiting for dispatcher confirmation
        </p>
      );
    }

    const key = `return-handoff:${delivery.id}`;
    const submitReturnHandoff = () => {
      if (!proofFile && !returnProof) return;
      runAction(
        key,
        async () => {
          let proofId = returnProof?.id;
          if (!proofId) {
            const form = new FormData();
            form.append('handoff_type', 'receive');
            form.append('proof_type', 'photo');
            form.append('proof_file', proofFile!);
            form.append('idempotency_key', proofIdempotencyKey('receive'));
            proofId = (await logisticsApi.recordProof(delivery.id, form)).data.proof.id;
          }

          return logisticsApi.confirmReturnHandoff(delivery.id, proofId);
        },
        {
          title: `Confirm return handoff for ${deliveryReference}?`,
          text: returnProof
            ? 'Confirm that the parcel was handed to shop staff.'
            : `${proofFile?.name} will be attached to the shop handoff.`,
          confirmButtonText: 'Confirm return handoff',
        },
      );
    };

    return (
      <div className="space-y-3 rounded-xl bg-blue-50 p-3 dark:bg-blue-950/30">
        <div>
          <p className="text-sm font-semibold text-blue-950 dark:text-blue-100">Return handoff</p>
          <p className="text-xs text-blue-700 dark:text-blue-300">
            At the shop, upload a clear photo of the parcel handoff. The dispatcher will confirm receipt.
          </p>
        </div>
        {!returnProof && (
          <DeliveryPhotoUpload
            inputId={`return-handoff-photo-${delivery.id}`}
            inputLabel="Return handoff photo"
            label="Handoff photo"
            file={proofFile}
            onChange={setProofFile}
          />
        )}
        <button
          type="button"
          disabled={mutationDisabled || (!proofFile && !returnProof) || pendingAction === key}
          onClick={submitReturnHandoff}
          className={buttonClass}
        >
          Confirm return handoff
        </button>
      </div>
    );
  }

  if (delivery.status !== 'in_transit') return null;
  if (!arrival) {
    return (
      <div className="space-y-3">
        {arrivalControl}
        <button
          type="button"
          disabled={mutationDisabled}
          onClick={() => setShowIncident((current) => !current)}
          className={compactSecondaryButtonClass}
        >
          Report incident
        </button>
        {incidentPanel}
      </div>
    );
  }

  const proofKey = `proof:${delivery.id}`;

  const submitProof = () => {
    if (!proofFile) return;
    const form = new FormData();
    form.append('handoff_type', 'delivery');
    form.append('proof_type', 'photo');
    form.append('proof_file', proofFile);
    form.append('idempotency_key', proofIdempotencyKey('delivery'));
    runAction(
      proofKey,
      () =>
        axios.post(`/api/logistics/legs/${delivery.id}/proof`, form, {
          headers: { 'Content-Type': 'multipart/form-data' },
        }),
      {
        title: `Submit proof for ${deliveryReference}?`,
        text: `${proofFile.name} will be attached to this delivery.`,
        confirmButtonText: 'Submit proof',
      },
    );
  };

  return (
    <div className="space-y-3">
      {arrivalSummary}
      {canRecordProof && (
        <div className="space-y-2 rounded-xl bg-slate-50 p-3 dark:bg-slate-800">
          <DeliveryPhotoUpload
            inputId={`delivery-proof-photo-${delivery.id}`}
            inputLabel="Delivery proof"
            label="Delivery proof"
            file={proofFile}
            onChange={setProofFile}
          />
          <button
            type="button"
            disabled={mutationDisabled || !proofFile || pendingAction === proofKey}
            onClick={submitProof}
            className={buttonClass}
          >
            Submit delivery proof
          </button>
        </div>
      )}

      <button
        type="button"
        disabled={mutationDisabled}
        onClick={() => setShowIssue((current) => !current)}
        className={compactSecondaryButtonClass}
      >
        Report issue
      </button>

      <button
        type="button"
        disabled={mutationDisabled}
        onClick={() => setShowIncident((current) => !current)}
        className={compactSecondaryButtonClass}
      >
        Report incident
      </button>

      {issuePanel}
      {incidentPanel}
    </div>
  );
}

function CurrentDeliveryCard({
  item,
  showSequence,
  onToggleSequence,
  locked,
  online,
  pendingAction,
  canRecordProof,
  today,
  runAction,
}: {
  item: RiderDeliveryWorkItem | null;
  showSequence: boolean;
  onToggleSequence: () => void;
  locked: boolean;
  online: boolean;
  pendingAction: string | null;
  canRecordProof: boolean;
  today: string;
  runAction: ActionRunner;
}) {
  if (!item) {
    return (
      <section aria-labelledby="current-delivery-heading">
        <h2 id="current-delivery-heading" className="mb-4 text-center text-lg font-bold text-slate-950 dark:text-white xl:mb-3 xl:text-left">
          Current delivery
        </h2>
        <div className="rounded-2xl border border-dashed border-slate-300 bg-white p-5 text-center dark:border-slate-700 dark:bg-slate-900">
          <p className="font-semibold text-slate-800 dark:text-slate-100">No delivery in progress</p>
          <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">Your next scheduled assignment appears below.</p>
        </div>
      </section>
    );
  }

  const progress = completedProgress(item.deliveries);
  const actionable = nextActionableDelivery(item.deliveries);
  const isReturnToShop = actionable?.leg_type === 'return_to_shop';

  return (
    <section aria-labelledby="current-delivery-heading">
      <h2 id="current-delivery-heading" className="mb-4 text-center text-lg font-bold text-slate-950 dark:text-white xl:mb-3 xl:text-left">
        Current delivery
      </h2>
      <article className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-700 dark:bg-slate-900">
        <header className="space-y-4 border-b border-slate-200 p-5 dark:border-slate-700 xl:p-4">
          <div className="flex flex-col items-start gap-3 xl:flex-row xl:justify-between">
            <div>
              <p className="text-xs font-bold uppercase tracking-wide text-blue-700 dark:text-blue-300">
                {item.business_label}
              </p>
              <h3 className="mt-1 text-xl font-extrabold text-slate-950 dark:text-white">
                {isReturnToShop ? `Return to shop #${actionable.id}` : itemTitle(item)}
              </h3>
            </div>
            <StatusChip status={item.status} label={isReturnToShop ? 'Return to shop' : undefined} />
          </div>

          <div>
            <div className="mb-2 flex items-center justify-between gap-3 text-sm">
              <span className="font-semibold text-slate-700 dark:text-slate-200">
                {progress.completed} of {progress.total} completed
              </span>
              <span className="text-slate-500 dark:text-slate-400">{progress.percent}%</span>
            </div>
            <div
              role="progressbar"
              aria-label={`${item.kind === 'batch' ? 'Batch' : 'Delivery'} progress: ${progress.completed} of ${progress.total} delivered`}
              aria-valuenow={progress.percent}
              aria-valuemin={0}
              aria-valuemax={100}
              className="h-2 overflow-hidden rounded-full bg-slate-200 dark:bg-slate-700"
            >
              <div className="h-full rounded-full bg-blue-600" style={{ width: `${progress.percent}%` }} />
            </div>
          </div>
        </header>

        <div className="p-5 xl:p-4">
          {actionable ? (
            <>
              <p className="mb-3 text-sm font-bold text-blue-700 dark:text-blue-300">
                Current delivery · {actionable.stop_sequence ?? 1} of {item.deliveries.length}
              </p>
              <ResolutionNotice delivery={actionable} />
              <DeliveryContact delivery={actionable} />
              <div className="mt-4">
                <DeliveryActions
                  key={actionable.id}
                  item={item}
                  delivery={actionable}
                  locked={locked}
                  online={online}
                  pendingAction={pendingAction}
                  canRecordProof={canRecordProof}
                  today={today}
                  runAction={runAction}
                />
              </div>
            </>
          ) : (
            <div className="rounded-xl bg-slate-50 p-4 dark:bg-slate-800">
              <p className="font-semibold text-slate-900 dark:text-white">
                {item.deliveries.some(({ status }) => status === 'awaiting_proof_approval')
                  ? 'Waiting for proof approval'
                  : 'No rider action needed right now'}
              </p>
              <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">
                This page will update when the delivery is ready to continue.
              </p>
            </div>
          )}

          {item.kind === 'batch' && item.deliveries.length > 0 && (
            <>
              <button
                type="button"
                onClick={onToggleSequence}
                className="mt-5 min-h-12 w-full touch-manipulation rounded-xl border border-slate-300 px-4 text-sm font-bold text-slate-800 transition-colors focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 dark:border-slate-600 dark:text-slate-100 xl:mt-4 xl:min-h-11"
              >
                {showSequence ? 'Hide delivery sequence' : `View all ${item.deliveries.length} deliveries`}
              </button>
              {showSequence && <DeliverySequence item={item} />}
            </>
          )}
        </div>
      </article>
    </section>
  );
}

function UpNextCard({
  item,
  locked,
  online,
  pendingAction,
  canRecordProof,
  today,
  runAction,
}: {
  item: RiderDeliveryWorkItem | null;
  locked: boolean;
  online: boolean;
  pendingAction: string | null;
  canRecordProof: boolean;
  today: string;
  runAction: ActionRunner;
}) {
  const [showDetails, setShowDetails] = useState(false);
  if (!item) return null;
  const actionable = nextActionableDelivery(item.deliveries);

  return (
    <section aria-label="Up next">
      <h2 className="mb-4 text-center text-lg font-bold text-slate-950 dark:text-white xl:mb-3 xl:text-left">Up next</h2>
      <article className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900 xl:p-4">
        <div className="flex flex-col items-start gap-3 xl:flex-row xl:justify-between">
          <div>
            <p className="text-xs font-bold uppercase tracking-wide text-slate-500">{item.business_label}</p>
            <h3 className="mt-1 font-bold text-slate-950 dark:text-white">{itemTitle(item)}</h3>
            <p className="mt-1 text-sm text-slate-600 dark:text-slate-300">
              {scheduleText(item)} · {deliveryCount(item)}
            </p>
          </div>
          <StatusChip status={item.status} />
        </div>
        <div className="mt-5 xl:mt-4">
          <DeliveryActions
            key={`${item.key}:${actionable?.id ?? 'none'}`}
            item={item}
            delivery={actionable}
            locked={locked}
            online={online}
            pendingAction={pendingAction}
            canRecordProof={canRecordProof}
            today={today}
            runAction={runAction}
          />
        </div>
        <button
          type="button"
          onClick={() => setShowDetails((current) => !current)}
          className="mt-5 min-h-12 w-full touch-manipulation rounded-xl border border-blue-300 px-4 text-sm font-bold text-blue-700 transition-colors focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 dark:border-blue-700 dark:text-blue-300 xl:mt-4 xl:min-h-11"
        >
          {showDetails ? 'Hide details' : 'View details'}
        </button>
        {showDetails && actionable && (
          <div className="mt-4 border-t border-slate-200 pt-4 dark:border-slate-700">
            <DeliveryContact delivery={actionable} />
          </div>
        )}
      </article>
    </section>
  );
}

function OfferCard({
  item,
  online,
  pendingAction,
  runAction,
}: {
  item: RiderDeliveryWorkItem;
  online: boolean;
  pendingAction: string | null;
  runAction: ActionRunner;
}) {
  const [declining, setDeclining] = useState(false);
  const [reason, setReason] = useState('');
  const isBatch = item.kind === 'batch';
  const offerLabel = isBatch ? 'batch' : 'delivery';
  const acceptKey = `offer-accept:${item.key}`;
  const declineKey = `offer-decline:${item.key}`;

  return (
    <article className="rounded-2xl border border-amber-300 bg-amber-50 p-5 dark:border-amber-800 dark:bg-amber-950/30 xl:p-4">
      <div className="flex flex-col items-start gap-3 xl:flex-row xl:justify-between">
        <div>
          <p className="text-xs font-bold uppercase tracking-wide text-amber-800 dark:text-amber-200">New assignment</p>
          <h3 className="mt-1 font-bold text-slate-950 dark:text-white">{itemTitle(item)}</h3>
          <p className="mt-1 text-sm text-slate-700 dark:text-slate-200">
            {scheduleText(item)} · {deliveryCount(item)}
          </p>
        </div>
        <StatusChip status={item.status} label={`New ${offerLabel} offer`} />
      </div>
      <div className="mt-5 grid gap-3 xl:mt-4 xl:grid-cols-2 xl:gap-2">
        <button
          type="button"
          disabled={!online || pendingAction !== null}
          onClick={() =>
            runAction(acceptKey, () => isBatch
              ? logisticsApi.acceptBatch(item.id)
              : logisticsApi.acceptLeg(item.id), {
              title: `Accept ${offerLabel} #${item.id}?`,
              text: 'This assignment will be added to your delivery work.',
              confirmButtonText: `Accept ${offerLabel}`,
            })
          }
          className="min-h-12 touch-manipulation rounded-xl bg-blue-600 px-4 text-sm font-bold text-white transition-colors focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 disabled:opacity-50 xl:min-h-11"
        >
          Accept {offerLabel}
        </button>
        <button
          type="button"
          disabled={!online}
          onClick={() => setDeclining((current) => !current)}
          className="min-h-12 touch-manipulation rounded-xl border border-amber-500 px-4 text-sm font-bold text-amber-900 transition-colors focus:outline-none focus:ring-2 focus:ring-amber-500 focus:ring-offset-2 disabled:opacity-50 dark:text-amber-100 xl:min-h-11"
        >
          Decline {offerLabel}
        </button>
      </div>
      {declining && (
        <div className="mt-3 space-y-2">
          <label className="block text-sm font-semibold text-amber-950 dark:text-amber-100">
            Decline reason
            <input
              type="text"
              aria-label="Decline reason"
              value={reason}
              onChange={(event) => setReason(event.target.value)}
              className="mt-1 min-h-11 w-full rounded-xl border border-amber-300 bg-white px-3 dark:bg-slate-900"
            />
          </label>
          <button
            type="button"
            disabled={!online || !reason.trim() || pendingAction !== null}
            onClick={() =>
              runAction(declineKey, () => isBatch
                ? logisticsApi.rejectBatch(item.id, reason.trim())
                : logisticsApi.rejectLeg(item.id, reason.trim()))
            }
            className="min-h-11 w-full rounded-xl bg-amber-700 px-4 text-sm font-bold text-white disabled:opacity-50"
          >
            Confirm decline
          </button>
        </div>
      )}
    </article>
  );
}

function OfferRegion({
  offers,
  online,
  pendingAction,
  runAction,
}: {
  offers: RiderDeliveryWorkItem[];
  online: boolean;
  pendingAction: string | null;
  runAction: ActionRunner;
}) {
  if (!offers.length) return null;

  return (
    <section aria-label="New assignment offers" className="space-y-4 xl:space-y-3">
      <OfferCard item={offers[0]} online={online} pendingAction={pendingAction} runAction={runAction} />
      {offers.length > 1 && (
        <details className="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-900 xl:p-3">
          <summary className="min-h-11 cursor-pointer py-2 text-sm font-bold text-slate-800 dark:text-slate-100">
            {offers.length - 1} more {offers.length === 2 ? 'offer' : 'offers'}
          </summary>
          <div className="mt-3 space-y-3">
            {offers.slice(1).map((offer) => (
              <OfferCard
                key={offer.key}
                item={offer}
                online={online}
                pendingAction={pendingAction}
                runAction={runAction}
              />
            ))}
          </div>
        </details>
      )}
    </section>
  );
}

function CompactListItem({ item }: { item: RiderDeliveryWorkItem | RiderDeliveryIssue }) {
  if (item.item_type === 'issue') {
    return (
      <article className="rounded-xl border border-amber-300 bg-white p-5 dark:border-amber-800 dark:bg-slate-900 xl:p-4">
        <div className="flex flex-col items-start gap-3 xl:flex-row xl:justify-between">
          <div>
            <p className="font-bold text-slate-950 dark:text-white">Issue · Delivery #{item.delivery_id}</p>
            <p className="mt-1 text-sm text-slate-600 dark:text-slate-300">{item.parent_key.replace(':', ' #')}</p>
          </div>
          <StatusChip status="delivery_attempted" />
        </div>
      </article>
    );
  }

  return (
    <details className="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-700 dark:bg-slate-900 xl:p-4">
      <summary className="min-h-12 cursor-pointer list-none xl:min-h-11">
        <div className="flex flex-col items-start gap-3 xl:flex-row xl:justify-between">
          <div>
            <p className="font-bold text-slate-950 dark:text-white">{itemTitle(item)}</p>
            <p className="mt-1 text-sm text-slate-600 dark:text-slate-300">
              {item.business_label} · {scheduleText(item)} · {deliveryCount(item)}
            </p>
          </div>
          <StatusChip status={item.status} />
        </div>
      </summary>
      <div className="mt-3 border-t border-slate-200 pt-3 dark:border-slate-700">
        <DeliverySequence item={item} />
      </div>
    </details>
  );
}

function DeliveryLists({
  deliveryData,
  navigate,
}: {
  deliveryData: RiderDeliveryPageData;
  navigate: (patch: Partial<RiderDeliveryPageData['filters']>, page?: number) => void;
}) {
  const [search, setSearch] = useState(deliveryData.filters.search);
  useEffect(() => setSearch(deliveryData.filters.search), [deliveryData.filters.search]);

  const emptyMessage = {
    upcoming: 'No upcoming deliveries',
    history: 'No delivery history',
    issues: 'No unresolved delivery issues',
    all: 'No deliveries found',
  }[deliveryData.filters.tab];

  return (
    <section aria-labelledby="delivery-list-heading" className="space-y-5 xl:space-y-4">
      <h2 id="delivery-list-heading" className="text-center text-lg font-bold text-slate-950 dark:text-white xl:text-left">
        Deliveries
      </h2>
      <div className="-mx-1 flex touch-pan-x justify-center gap-2 overflow-x-auto px-1 pb-2 xl:justify-start xl:gap-1 xl:pb-1" aria-label="Delivery lists">
        {(Object.keys(tabLabels) as RiderDeliveryTab[]).map((tab) => (
          <button
            key={tab}
            type="button"
            aria-current={deliveryData.filters.tab === tab ? 'page' : undefined}
            onClick={() => navigate({ tab })}
            className={`min-h-12 shrink-0 touch-manipulation rounded-full border px-4 text-sm font-bold transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-400 focus-visible:ring-offset-2 xl:min-h-11 xl:rounded-xl ${
              deliveryData.filters.tab === tab
                ? 'border-slate-300 bg-slate-100 text-slate-950 dark:border-slate-600 dark:bg-slate-800 dark:text-white'
                : 'border-slate-200 bg-white text-slate-600 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300'
            }`}
          >
            {tabLabels[tab]}
          </button>
        ))}
      </div>

      <div className="grid gap-4 xl:grid-cols-3 xl:gap-3">
        <label className="text-sm font-semibold text-slate-700 dark:text-slate-200">
          Business type
          <select
            aria-label="Business type"
            value={deliveryData.filters.business}
            onChange={(event) =>
              navigate({
                business: event.target.value as RiderDeliveryPageData['filters']['business'],
              })
            }
            className="mt-2 min-h-12 w-full rounded-xl border border-slate-300 bg-white px-4 text-base transition-colors focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 dark:border-slate-700 dark:bg-slate-900 xl:mt-1 xl:min-h-11 xl:px-3 xl:text-sm"
          >
            <option value="all">All businesses</option>
            <option value="retail">Retail</option>
            <option value="repair">Repair</option>
          </select>
        </label>
        <label className="text-sm font-semibold text-slate-700 dark:text-slate-200">
          Time
          <select
            aria-label="Delivery time"
            value={deliveryData.filters.window}
            onChange={(event) =>
              navigate({
                window: event.target.value as RiderDeliveryPageData['filters']['window'],
              })
            }
            className="mt-2 min-h-12 w-full rounded-xl border border-slate-300 bg-white px-4 text-base transition-colors focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 dark:border-slate-700 dark:bg-slate-900 xl:mt-1 xl:min-h-11 xl:px-3 xl:text-sm"
          >
            <option value="all">All time</option>
            <option value="today">Today</option>
            <option value="week">This week</option>
          </select>
        </label>
        <form
          role="search"
          onSubmit={(event: FormEvent) => {
            event.preventDefault();
            navigate({ search });
          }}
          className="space-y-2"
        >
          <label className="text-sm font-semibold text-slate-700 dark:text-slate-200">
            Search
            <input
              type="search"
              aria-label="Search deliveries"
              value={search}
              onChange={(event) => setSearch(event.target.value)}
              placeholder="Delivery, customer, address"
              className="mt-2 min-h-12 w-full rounded-xl border border-slate-300 bg-white px-4 text-base transition-colors focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 dark:border-slate-700 dark:bg-slate-900 xl:mt-1 xl:min-h-11 xl:px-3 xl:text-sm"
            />
          </label>
          <button type="submit" className="sr-only">Search deliveries</button>
        </form>
      </div>

      <button
        type="button"
        onClick={() =>
          navigate({ tab: 'upcoming', business: 'all', window: 'all', search: '' })
        }
        className="min-h-12 w-full touch-manipulation rounded-xl border border-slate-300 px-4 text-sm font-semibold text-slate-700 transition-colors focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 dark:border-slate-700 dark:text-slate-200 xl:min-h-11 xl:w-auto"
      >
        Clear filters
      </button>

      <div className="space-y-4 xl:space-y-3">
        {deliveryData.list.data.length ? (
          deliveryData.list.data.map((item) => <CompactListItem key={item.key} item={item} />)
        ) : (
          <div className="rounded-xl border border-dashed border-slate-300 bg-white p-5 text-center text-sm text-slate-500 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-400">
            {emptyMessage}
          </div>
        )}
      </div>

      {deliveryData.list.links.some(({ url }) => url) && (
        <nav aria-label="Delivery pages" className="flex flex-wrap justify-center gap-3 xl:gap-2">
          {deliveryData.list.links.map((link) => {
            if (!link.url) return null;
            const label = link.label.replace(/&laquo;|&raquo;/g, '').trim();
            const page = Number(new URL(link.url, window.location.origin).searchParams.get('page') ?? 1);

            return (
              <button
                key={`${link.label}:${page}`}
                type="button"
                aria-label={`Page ${label}`}
                aria-current={link.active ? 'page' : undefined}
                onClick={() => navigate({}, page)}
                className="min-h-12 min-w-12 touch-manipulation rounded-full border border-slate-300 px-3 text-sm font-semibold transition-colors focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 dark:border-slate-700 xl:min-h-11 xl:min-w-11 xl:rounded-xl"
              >
                {label}
              </button>
            );
          })}
        </nav>
      )}
    </section>
  );
}

export default function MyDeliveries() {
  const { deliveryData, canRecordProof, today = new Date().toISOString().slice(0, 10) } = usePage<{
    deliveryData: RiderDeliveryPageData;
    canRecordProof: boolean;
    maxDeliveryAttempts: number;
    today?: string;
  }>().props;
  const [showSequence, setShowSequence] = useState(false);
  const [online, setOnline] = useState(() => typeof navigator === 'undefined' || navigator.onLine);
  const [pendingAction, setPendingAction] = useState<string | null>(null);
  const actionInFlight = useRef(false);
  const [actionError, setActionError] = useState<string | null>(null);
  const [lastSynced, setLastSynced] = useState(() =>
    new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }),
  );

  useEffect(() => {
    const connected = () => setOnline(true);
    const disconnected = () => setOnline(false);
    window.addEventListener('online', connected);
    window.addEventListener('offline', disconnected);

    return () => {
      window.removeEventListener('online', connected);
      window.removeEventListener('offline', disconnected);
    };
  }, []);

  const finishAction = () => {
    actionInFlight.current = false;
    setPendingAction(null);
  };
  const reloadDeliveryData = (onFinish: () => void = finishAction) => {
    router.reload({
      only: ['deliveryData'],
      onFinish: () => {
        setLastSynced(new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }));
        onFinish();
      },
    });
  };
  const refreshDeliveries = () => {
    if (!online || actionInFlight.current) return;
    actionInFlight.current = true;
    setPendingAction('refresh');
    setActionError(null);
    reloadDeliveryData();
  };

  const runAction: ActionRunner = (key, action, confirmation, onError) => {
    if (!online || actionInFlight.current) return;
    actionInFlight.current = true;
    setPendingAction(key);
    setActionError(null);

    void (async () => {
      try {
        if (confirmation && !(await workflowFeedback.confirm(confirmation)).isConfirmed) {
          finishAction();
          return;
        }

        await action();
        reloadDeliveryData();
      } catch (error: any) {
        if (onError?.(error)) {
          finishAction();
          return;
        }
        const errors = error.response?.data?.errors;
        const message = errors
          ? Object.values(errors).flat().join(' ')
          : error.response?.data?.message ?? 'Unable to update this delivery.';
        const stale = error.response?.status === 409
          || (error.response?.status === 422
            && /stale|changed|no longer|not your current|refresh my deliveries/i.test(message));
        if (stale) {
          setActionError(`${message} Delivery list refreshed.`);
          reloadDeliveryData();
          return;
        }
        setActionError(message);
        finishAction();
      }
    })();
  };

  const navigate = (
    patch: Partial<RiderDeliveryPageData['filters']>,
    page = 1,
  ) => {
    router.get(
      '/erp/logistics/deliveries',
      { ...deliveryData.filters, ...patch, page },
      { preserveScroll: true, preserveState: true },
    );
  };

  return (
    <AppLayoutERP>
      <Head title="My Deliveries" />
      <div className="mx-auto w-full max-w-xl space-y-8 pb-[calc(2rem+env(safe-area-inset-bottom))] md:max-w-3xl xl:max-w-3xl xl:space-y-6 xl:pb-10">
        <header className="text-center xl:text-left">
          <h1 className="text-2xl font-extrabold text-slate-950 dark:text-white">My Deliveries</h1>
          <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">
            See what needs your attention now.
          </p>
          <div className="mt-4 flex flex-col items-center gap-3 xl:mt-2 xl:flex-row xl:items-center xl:justify-between xl:gap-2">
            <p
              role="status"
              aria-live="polite"
              className={`text-xs font-semibold ${
                online ? 'text-emerald-700 dark:text-emerald-300' : 'text-amber-700 dark:text-amber-300'
              }`}
            >
              <span aria-hidden="true">{online ? '●' : '!'}</span>{' '}
              {online ? 'Online' : 'Offline'} · Last sync {lastSynced}
            </p>
            <button
              type="button"
              disabled={!online || pendingAction !== null}
              onClick={refreshDeliveries}
              className="min-h-12 w-full touch-manipulation rounded-xl border border-slate-300 px-3 text-xs font-bold text-slate-700 transition-colors focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 disabled:opacity-50 dark:border-slate-700 dark:text-slate-200 xl:min-h-11 xl:w-auto"
            >
              Refresh deliveries
            </button>
          </div>
        </header>

        {deliveryData.has_active_conflict && (
          <div role="alert" className="rounded-xl border border-red-300 bg-red-50 p-5 text-sm text-red-900 dark:border-red-800 dark:bg-red-950/40 dark:text-red-100 xl:p-4">
            <strong>More than one active delivery was found.</strong>{' '}
            Continue only the Current delivery shown below. Conflicting assignments are blocked until dispatch resolves them.
          </div>
        )}

        <CurrentDeliveryCard
          item={deliveryData.current}
          showSequence={showSequence}
          onToggleSequence={() => setShowSequence((current) => !current)}
          locked={false}
          online={online}
          pendingAction={pendingAction}
          canRecordProof={canRecordProof}
          today={today}
          runAction={runAction}
        />
        <OfferRegion
          offers={deliveryData.offers}
          online={online}
          pendingAction={pendingAction}
          runAction={runAction}
        />
        <UpNextCard
          item={deliveryData.up_next}
          locked={deliveryData.has_active_conflict || Boolean(deliveryData.current)}
          online={online}
          pendingAction={pendingAction}
          canRecordProof={canRecordProof}
          today={today}
          runAction={runAction}
        />
        {actionError && (
          <div role="alert" className="rounded-xl border border-red-300 bg-red-50 p-4 text-sm text-red-900">
            {actionError}
          </div>
        )}
        <DeliveryLists deliveryData={deliveryData} navigate={navigate} />
      </div>
    </AppLayoutERP>
  );
}
