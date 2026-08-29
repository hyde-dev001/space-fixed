import React, { useEffect, useMemo, useState } from 'react';
import axios from 'axios';
import { AlertTriangle, Check, CheckCircle2, FileText, Lock, Upload, X } from 'lucide-react';
import Swal from 'sweetalert2';
import { router } from '@inertiajs/react';
import type { ShopModuleKey, ShopModuleStates } from '../../../../types/shopModules';

export type BusinessScalingTransition = {
  key: string;
  label: string;
  requested_registration_type: 'individual' | 'company';
  requested_business_type: 'retail' | 'repair' | 'both';
};

export type BusinessScalingEvidence = {
  key: string;
  title: string;
  description: string;
  required: boolean;
  existing_document_id?: number | null;
  existing_status?: string | null;
};

export type BusinessScalingModuleCatalogEntry = {
  key: ShopModuleKey;
  label: string;
  registration_types: string[];
  business_types: string[];
};

export type BusinessScalingRequestSummary = {
  id: number;
  status: 'pending' | 'approved' | 'rejected' | 'superseded';
  current_registration_type: string;
  current_business_type: string;
  requested_registration_type: string;
  requested_business_type: string;
  decision_reason: string | null;
  submitted_at: string | null;
  reviewed_at: string | null;
  documents: Array<{ document_type: string; status?: string | null; source_status?: string | null }>;
};

export type BusinessScalingPayload = {
  current: {
    registration_type: string;
    business_type: string;
  };
  available_account_transitions: BusinessScalingTransition[];
  available_capability_transitions: BusinessScalingTransition[];
  available_combined_transitions?: BusinessScalingTransition[];
  pending_request: BusinessScalingRequestSummary | null;
  latest_terminal_request: BusinessScalingRequestSummary | null;
  required_evidence: BusinessScalingEvidence[];
  module_catalog?: BusinessScalingModuleCatalogEntry[];
  modules: ShopModuleStates;
};

type BusinessScalingSettingsProps = {
  businessScaling: BusinessScalingPayload;
};

const MODULE_LABELS: Record<ShopModuleKey, string> = {
  retail_operations: 'Retail operations',
  repair_operations: 'Repair operations',
  hr_employees: 'HR and employees',
  finance: 'Finance',
  crm: 'Customer management',
  inventory: 'Inventory',
  procurement: 'Procurement',
  logistics: 'Logistics',
};

const MODULE_ORDER: ShopModuleKey[] = [
  'retail_operations',
  'repair_operations',
  'hr_employees',
  'finance',
  'crm',
  'inventory',
  'procurement',
  'logistics',
];

const formatState = (value: string | null | undefined): string => {
  const normalized = String(value ?? '').replace(/[_-]+/g, ' ').trim();
  if (!normalized) return 'Unknown';

  return normalized
    .split(' ')
    .map((part) => part.charAt(0).toUpperCase() + part.slice(1).toLowerCase())
    .join(' ');
};

const formatDate = (value: string | null | undefined): string | null => {
  if (!value) return null;
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return null;
  return date.toLocaleDateString();
};

const isAllowedDocument = (file: File): boolean => {
  const allowedTypes = ['application/pdf', 'image/jpeg', 'image/png'];
  const extension = file.name.split('.').pop()?.toLowerCase();
  return allowedTypes.includes(file.type) || ['pdf', 'jpg', 'jpeg', 'png'].includes(extension ?? '');
};

const isModuleStates = (value: unknown): value is ShopModuleStates => (
  Boolean(value) && typeof value === 'object' && MODULE_ORDER.every((key) => {
    const state = (value as Record<string, unknown>)[key];
    return Boolean(state)
      && typeof state === 'object'
      && typeof (state as { eligible?: unknown }).eligible === 'boolean'
      && typeof (state as { enabled?: unknown }).enabled === 'boolean'
      && typeof (state as { accessible?: unknown }).accessible === 'boolean';
  })
);

const requestStatusLabel: Record<BusinessScalingRequestSummary['status'], string> = {
  pending: 'Pending review',
  approved: 'Approved',
  rejected: 'Rejected',
  superseded: 'Superseded',
};

const BusinessScalingSettings: React.FC<BusinessScalingSettingsProps> = ({ businessScaling }) => {
  const transitions = useMemo(
    () => [
      ...businessScaling.available_account_transitions,
      ...businessScaling.available_capability_transitions,
      ...(businessScaling.available_combined_transitions ?? []),
    ],
    [
      businessScaling.available_account_transitions,
      businessScaling.available_capability_transitions,
      businessScaling.available_combined_transitions,
    ],
  );
  const [selectedTransitionKey, setSelectedTransitionKey] = useState(transitions[0]?.key ?? '');
  const [isTransitionDropdownOpen, setIsTransitionDropdownOpen] = useState(false);
  const [files, setFiles] = useState<Record<string, File | null>>({});
  const [reuseDocumentIds, setReuseDocumentIds] = useState<Record<string, number>>(() => (
    Object.fromEntries(
      businessScaling.required_evidence
        .filter((evidence) => evidence.existing_document_id && evidence.existing_status === 'approved')
        .map((evidence) => [evidence.key, evidence.existing_document_id as number]),
    )
  ));
  const [requestErrors, setRequestErrors] = useState<Record<string, string>>({});
  const [submitError, setSubmitError] = useState<string | null>(null);
  const [submitSuccess, setSubmitSuccess] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const [moduleStates, setModuleStates] = useState<ShopModuleStates>(businessScaling.modules);
  const [updatingModule, setUpdatingModule] = useState<ShopModuleKey | null>(null);
  const [moduleError, setModuleError] = useState<string | null>(null);

  useEffect(() => {
    setModuleStates(businessScaling.modules);
  }, [businessScaling.modules]);

  useEffect(() => {
    if (selectedTransitionKey && transitions.some((transition) => transition.key === selectedTransitionKey)) return;
    setSelectedTransitionKey(transitions[0]?.key ?? '');
  }, [selectedTransitionKey, transitions]);

  const selectedTransition = transitions.find((transition) => transition.key === selectedTransitionKey) ?? null;
  const hasPendingRequest = businessScaling.pending_request !== null;
  const currentLabel = `${formatState(businessScaling.current.registration_type)} ${formatState(businessScaling.current.business_type)}`;

  const setFile = (evidenceKey: string, file: File | null) => {
    setRequestErrors((previous) => {
      const next = { ...previous };
      delete next[evidenceKey];
      return next;
    });
    setReuseDocumentIds((previous) => {
      const next = { ...previous };
      delete next[evidenceKey];
      return next;
    });

    if (!file) {
      setFiles((previous) => ({ ...previous, [evidenceKey]: null }));
      return;
    }

    if (!isAllowedDocument(file)) {
      setFiles((previous) => ({ ...previous, [evidenceKey]: null }));
      setRequestErrors((previous) => ({ ...previous, [evidenceKey]: 'Use a PDF, JPG, JPEG, or PNG file.' }));
      void Swal.fire({ icon: 'error', title: 'Invalid document', text: 'Use a PDF, JPG, JPEG, or PNG file.', confirmButtonColor: '#111827' });
      return;
    }

    if (file.size > 10 * 1024 * 1024) {
      setFiles((previous) => ({ ...previous, [evidenceKey]: null }));
      setRequestErrors((previous) => ({ ...previous, [evidenceKey]: 'Each document must be 10 MB or smaller.' }));
      void Swal.fire({ icon: 'error', title: 'File is too large', text: 'Each document must be 10 MB or smaller.', confirmButtonColor: '#111827' });
      return;
    }

    setFiles((previous) => ({ ...previous, [evidenceKey]: file }));
    const evidence = businessScaling.required_evidence.find((item) => item.key === evidenceKey);
    void Swal.fire({
      icon: 'info',
      title: 'File Attached',
      text: `${file.name} was added to ${evidence?.title ?? 'the required evidence'}.`,
      confirmButtonText: 'OK',
      confirmButtonColor: '#111827',
    });
  };

  const toggleReuseDocument = (evidence: BusinessScalingEvidence) => {
    if (!evidence.existing_document_id || evidence.existing_status !== 'approved') return;

    setFiles((previous) => ({ ...previous, [evidence.key]: null }));
    setReuseDocumentIds((previous) => {
      if (previous[evidence.key]) {
        const next = { ...previous };
        delete next[evidence.key];
        return next;
      }

      return { ...previous, [evidence.key]: evidence.existing_document_id as number };
    });
    setRequestErrors((previous) => {
      const next = { ...previous };
      delete next[evidence.key];
      return next;
    });
  };

  const handleSubmit = (event: React.FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (submitting || hasPendingRequest || !selectedTransition) return;

    const nextErrors: Record<string, string> = {};
    businessScaling.required_evidence.forEach((evidence) => {
      if (evidence.required && !files[evidence.key] && !reuseDocumentIds[evidence.key]) {
        nextErrors[evidence.key] = 'This document is required.';
      }
    });

    if (Object.keys(nextErrors).length > 0) {
      setRequestErrors(nextErrors);
      setSubmitError('Add each required document before submitting your request.');
      return;
    }

    const payload = new FormData();
    payload.append('requested_registration_type', selectedTransition.requested_registration_type);
    payload.append('requested_business_type', selectedTransition.requested_business_type);
    Object.entries(files).forEach(([key, file]) => {
      if (file) payload.append(`documents[${key}]`, file);
    });
    Object.entries(reuseDocumentIds).forEach(([key, id]) => {
      payload.append(`reuse_document_ids[${key}]`, String(id));
    });

    setSubmitting(true);
    setSubmitError(null);
    setSubmitSuccess(null);
    router.post('/shop-owner/settings/business-upgrade', payload, {
      forceFormData: true,
      preserveScroll: true,
      onSuccess: () => {
        setSubmitSuccess('Your request was submitted for review.');
        setSubmitting(false);
      },
      onError: (errors) => {
        const normalizedErrors = Object.fromEntries(
          Object.entries(errors ?? {}).map(([key, value]) => [
            key.replace(/^(documents|reuse_document_ids)\./, ''),
            Array.isArray(value) ? value[0] : String(value),
          ]),
        );
        setRequestErrors(normalizedErrors);
        setSubmitError('Please correct the highlighted fields and try again.');
        setSubmitting(false);
      },
      onFinish: () => setSubmitting(false),
    });
  };

  const handleModuleToggle = async (moduleKey: ShopModuleKey) => {
    const current = moduleStates[moduleKey];
    if (!current?.eligible || updatingModule) return;

    const enabled = !current.enabled;
    if (!enabled) {
      const result = await Swal.fire({
        icon: 'warning',
        title: `Disable ${MODULE_LABELS[moduleKey]}?`,
        text: 'Active navigation will be hidden until you enable it again.',
        showCancelButton: true,
        confirmButtonText: 'Disable module',
        cancelButtonText: 'Cancel',
        confirmButtonColor: '#111827',
        cancelButtonColor: '#6b7280',
      });
      if (!result.isConfirmed) return;
    }

    const previous = moduleStates;
    setUpdatingModule(moduleKey);
    setModuleError(null);

    try {
      const response = await axios.patch(`/shop-owner/settings/modules/${moduleKey}`, { enabled });
      const returnedStates = response?.data?.states;
      if (isModuleStates(returnedStates)) {
        setModuleStates(returnedStates);
      } else {
        setModuleStates(previous);
        setModuleError('The module setting response was incomplete. Your last confirmed state was kept.');
      }
    } catch (error: unknown) {
      setModuleStates(previous);
      const response = typeof error === 'object' && error !== null && 'response' in error
        ? (error as { response?: unknown }).response
        : null;
      const responseData = typeof response === 'object' && response !== null && 'data' in response
        ? (response as { data?: unknown }).data
        : null;
      const message = typeof responseData === 'object' && responseData !== null && 'message' in responseData
        ? (responseData as { message?: unknown }).message
        : null;
      setModuleError(typeof message === 'string' ? message : 'The module setting could not be updated.');
    } finally {
      setUpdatingModule(null);
    }
  };

  return (
    <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900" aria-labelledby="business-scaling-heading">
      <div className="flex flex-wrap items-start justify-between gap-4">
        <div>
          <h2 id="business-scaling-heading" className="text-xl font-semibold text-slate-900 dark:text-white">Business scaling</h2>
          <p className="mt-1 max-w-2xl text-sm leading-6 text-slate-600 dark:text-slate-300">
            Request a business account or capability upgrade, then manage the modules available to your team.
          </p>
        </div>
        <span className="rounded-full border border-slate-200 bg-slate-50 px-3 py-1 text-sm font-medium text-slate-700 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-200">
          Current: {currentLabel}
        </span>
      </div>

      {businessScaling.latest_terminal_request ? (
        <div
          className={businessScaling.latest_terminal_request.status === 'approved'
            ? 'mt-4 flex items-start gap-3 rounded-xl border border-green-200 bg-green-50 p-4 text-sm text-green-900'
            : 'mt-4 flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900'}
          role="status"
        >
          {businessScaling.latest_terminal_request.status === 'approved'
            ? <CheckCircle2 className="mt-0.5 h-5 w-5 shrink-0" aria-hidden="true" />
            : <AlertTriangle className="mt-0.5 h-5 w-5 shrink-0" aria-hidden="true" />}
          <div>
            <p className="font-semibold">{requestStatusLabel[businessScaling.latest_terminal_request.status]} request</p>
            {businessScaling.latest_terminal_request.decision_reason ? <p className="mt-1">{businessScaling.latest_terminal_request.decision_reason}</p> : null}
            {formatDate(businessScaling.latest_terminal_request.reviewed_at) ? (
              <p className="mt-1 text-xs opacity-80">Reviewed {formatDate(businessScaling.latest_terminal_request.reviewed_at)}</p>
            ) : null}
          </div>
        </div>
      ) : null}

      {businessScaling.pending_request ? (
        <div className="mt-4 flex items-start gap-3 rounded-xl border border-blue-200 bg-blue-50 p-4 text-sm text-blue-900" role="status">
          <CheckCircle2 className="mt-0.5 h-5 w-5 shrink-0" aria-hidden="true" />
          <div>
            <p className="font-semibold">Pending review</p>
            <p className="mt-1">Your request to move to {formatState(businessScaling.pending_request.requested_registration_type)} {formatState(businessScaling.pending_request.requested_business_type)} is being reviewed.</p>
          </div>
        </div>
      ) : null}

      {transitions.length > 0 && !hasPendingRequest ? (
        <form className="mt-5 space-y-5" onSubmit={handleSubmit}>
          <div>
            <label htmlFor="upgrade-choice" className="text-sm font-semibold text-slate-900 dark:text-white">Upgrade choice</label>
            <div className="relative mt-2">
              <button
                id="upgrade-choice"
                type="button"
                aria-label="Upgrade choice"
                aria-haspopup="listbox"
                aria-expanded={isTransitionDropdownOpen}
                onClick={() => setIsTransitionDropdownOpen((open) => !open)}
                className="flex min-h-11 w-full items-center justify-between rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-left text-sm text-slate-900 outline-none transition hover:border-slate-500 hover:bg-slate-50 focus:border-slate-500 focus:ring-2 focus:ring-slate-200 dark:border-slate-600 dark:bg-slate-800 dark:text-white dark:hover:border-slate-400 dark:hover:bg-slate-700"
              >
                <span>{selectedTransition?.label ?? 'Select an upgrade'}</span>
                <span aria-hidden="true" className={`text-slate-500 transition-transform ${isTransitionDropdownOpen ? 'rotate-180' : ''}`}>▾</span>
              </button>
              {isTransitionDropdownOpen && (
                <div role="listbox" aria-label="Upgrade choices" className="absolute left-0 right-0 top-full z-50 mt-1 overflow-hidden rounded-lg border border-slate-300 bg-white p-1 shadow-lg dark:border-slate-600 dark:bg-slate-800">
                  {transitions.map((transition) => (
                    <button
                      key={transition.key}
                      type="button"
                      role="option"
                      aria-selected={selectedTransitionKey === transition.key}
                      onClick={() => {
                        setSelectedTransitionKey(transition.key);
                        setIsTransitionDropdownOpen(false);
                      }}
                      className={`w-full rounded-md px-3 py-2 text-left text-sm transition-colors ${selectedTransitionKey === transition.key ? 'bg-slate-900 font-semibold text-white dark:bg-white dark:text-slate-900' : 'text-slate-700 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-200 dark:hover:bg-slate-700 dark:hover:text-white'}`}
                    >
                      {transition.label}
                    </button>
                  ))}
                </div>
              )}
            </div>
            {selectedTransition ? <p className="mt-2 text-xs text-slate-500 dark:text-slate-400">Target: {formatState(selectedTransition.requested_registration_type)} {formatState(selectedTransition.requested_business_type)}</p> : null}
          </div>

          <fieldset>
            <legend className="text-sm font-semibold text-slate-900 dark:text-white">Required evidence</legend>
            <p className="mt-1 text-xs text-slate-500 dark:text-slate-400">Upload PDF, JPG, JPEG, or PNG files up to 10 MB each. Approved documents can be reused.</p>
            <div className="mt-3 grid gap-3 sm:grid-cols-2">
              {businessScaling.required_evidence.map((evidence) => {
                const selectedFile = files[evidence.key];
                const hasReuse = Boolean(reuseDocumentIds[evidence.key]);
                return (
                  <div key={evidence.key} className="rounded-xl border border-slate-200 bg-slate-50/70 p-4 dark:border-slate-700 dark:bg-slate-800/70">
                    <div className="flex items-start gap-2">
                      <FileText className="mt-0.5 h-4 w-4 shrink-0 text-slate-600 dark:text-slate-300" aria-hidden="true" />
                      <div className="min-w-0">
                        <p className="text-sm font-semibold text-slate-900 dark:text-white">{evidence.title}{evidence.required ? <span className="ml-1 text-red-600" aria-label="required">*</span> : null}</p>
                        <p className="mt-1 text-xs leading-5 text-slate-600 dark:text-slate-300">{evidence.description}</p>
                      </div>
                    </div>
                    <label className="mt-3 flex min-h-11 cursor-pointer items-center justify-center gap-2 rounded-lg border border-dashed border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-700 transition hover:border-slate-500 dark:border-slate-600 dark:bg-slate-900 dark:text-slate-200">
                      <Upload className="h-4 w-4" aria-hidden="true" />
                      {selectedFile ? selectedFile.name : 'Choose document'}
                      <input type="file" className="sr-only" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" onChange={(event) => setFile(evidence.key, event.target.files?.[0] ?? null)} />
                    </label>
                    {evidence.existing_document_id && evidence.existing_status === 'approved' ? (
                      <label className="mt-2 flex cursor-pointer items-center gap-2 text-xs text-slate-700 dark:text-slate-200">
                        <input type="checkbox" checked={hasReuse} onChange={() => toggleReuseDocument(evidence)} />
                        Use existing approved document
                      </label>
                    ) : null}
                    {hasReuse ? <p className="mt-2 text-xs font-medium text-green-700 dark:text-green-400">Approved document selected.</p> : null}
                    {requestErrors[evidence.key] ? <p className="mt-2 text-xs font-medium text-red-700" role="alert">{requestErrors[evidence.key]}</p> : null}
                  </div>
                );
              })}
            </div>
          </fieldset>

          {submitError ? <p className="flex items-center gap-2 text-sm font-medium text-red-700" role="alert"><X className="h-4 w-4" aria-hidden="true" />{submitError}</p> : null}
          {submitSuccess ? <p className="flex items-center gap-2 text-sm font-medium text-green-700" role="status"><Check className="h-4 w-4" aria-hidden="true" />{submitSuccess}</p> : null}
          <button type="submit" disabled={submitting || !selectedTransition} className="inline-flex min-h-11 items-center justify-center rounded-lg bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-slate-700 focus:outline-none focus:ring-2 focus:ring-slate-400 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 dark:bg-white dark:text-slate-900 dark:hover:bg-slate-200">
            {submitting ? 'Submitting…' : 'Submit upgrade request'}
          </button>
        </form>
      ) : null}

      <div className="mt-7 border-t border-slate-200 pt-5 dark:border-slate-700">
        <div className="flex items-start gap-3">
          <Lock className="mt-0.5 h-5 w-5 shrink-0 text-slate-600 dark:text-slate-300" aria-hidden="true" />
          <div>
            <h3 className="text-base font-semibold text-slate-900 dark:text-white">Business modules</h3>
            <p className="mt-1 text-sm text-slate-600 dark:text-slate-300">Module switches affect navigation and route access for this shop. Existing employee permissions are still required.</p>
          </div>
        </div>
        {moduleError ? <p className="mt-3 text-sm font-medium text-red-700" role="alert">{moduleError}</p> : null}
        <div className="mt-4 grid gap-3 sm:grid-cols-2">
          {MODULE_ORDER.map((moduleKey) => {
            const state = moduleStates[moduleKey];
            if (!state) return null;
            const label = businessScaling.module_catalog?.find((module) => module.key === moduleKey)?.label
              ?? MODULE_LABELS[moduleKey];
            return (
              <div key={moduleKey} className="flex items-start justify-between gap-3 rounded-xl border border-slate-200 p-4 dark:border-slate-700">
                <div className="min-w-0">
                  <p className="text-sm font-semibold text-slate-900 dark:text-white">{label}</p>
                  <p className="mt-1 text-xs text-slate-600 dark:text-slate-300">
                    {!state.eligible ? (state.reason || 'This shop is not eligible for this module.') : state.enabled ? 'Enabled for this shop.' : 'Disabled for this shop.'}
                  </p>
                </div>
                <button
                  type="button"
                  aria-label={`Toggle ${label}`}
                  aria-pressed={state.enabled}
                  disabled={!state.eligible || updatingModule !== null}
                  onClick={() => handleModuleToggle(moduleKey)}
                  className={`relative mt-0.5 inline-flex h-7 w-12 shrink-0 items-center rounded-full border-2 border-transparent transition focus:outline-none focus:ring-2 focus:ring-slate-400 focus:ring-offset-2 ${state.enabled ? 'bg-slate-900 dark:bg-white' : 'bg-slate-300 dark:bg-slate-600'} disabled:cursor-not-allowed disabled:opacity-50`}
                >
                  <span className={`inline-block h-5 w-5 transform rounded-full bg-white shadow transition dark:bg-slate-900 ${state.enabled ? 'translate-x-5' : 'translate-x-0'}`} />
                </button>
              </div>
            );
          })}
        </div>
      </div>
    </section>
  );
};

export default BusinessScalingSettings;
