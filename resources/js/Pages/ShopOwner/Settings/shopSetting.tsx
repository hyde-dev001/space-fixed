																																								import React, { useEffect, useRef, useState } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import axios from 'axios';
import 'leaflet/dist/leaflet.css';
import { AlertTriangle, Building2, CalendarDays, Check, CheckCircle2, ChevronDown, Eye, EyeOff, MapPin, Pencil, Settings, Store, Trash2, User, Wrench } from 'lucide-react';
																																								import UserSwal from '../../UserSide/Shared/UserModal';
import BusinessScalingSettings, { type BusinessScalingPayload } from './components/BusinessScalingSettings';
import BusinessDocumentCompliance, { type ComplianceSlot } from './components/BusinessDocumentCompliance';
import { requiredPolicySectionKeys } from '../../../utils/policySectionResolver';
import type { PolicySectionKey, ShopPolicyEditorStateResponse, ShopPolicySections } from '../../../types/shopPolicy';
import { GPS_POSITION_OPTIONS } from '../../../utils/geolocation';

type ApprovalSetting = {
	enabled: boolean;
	limit: number | null;
};

type ApprovalPages = {
	refund_approval: ApprovalSetting;
	price_approval: ApprovalSetting;
	purchase_request_approval: ApprovalSetting;
	repair_reject_approval: ApprovalSetting;
};

type ShopSettingsPayload = {
	registration_type: string;
	business_type: string;
	can_manage_staff: boolean;
	max_locations: number | null;
	business_name: string;
	approval_pages: ApprovalPages;
	business_scaling: BusinessScalingPayload;
	pay_cycle: 'monthly' | 'semi_monthly';
	pay_day_first: number;
	pay_day_second: number;
	required_documents: Array<{
		key: string;
		title: string;
		description: string;
		status: string;
		is_uploaded: boolean;
		is_image: boolean;
		file_url: string | null;
	}>;
	document_compliance: ComplianceSlot[];
	repair_payment_policy: 'deposit_50' | 'full_upfront';
	repair_workload_limit: number;
	order_refund_deadline_days: number;
	two_factor_email_enabled: boolean;
	has_paymongo_key: boolean;
	// Geofence
	attendance_geofence_enabled: boolean;
	shop_latitude: number | null;
	shop_longitude: number | null;
	shop_address: string | null;
	shop_geofence_radius: number;
		premium: {
		eligible: boolean;
		status: 'pending' | 'active' | 'expired' | 'cancelled' | 'failed' | null;
		has_active: boolean;
			auto_renew: boolean | null;
			auto_renew_status: string | null;
		plan_name: string | null;
		plan_code: string | null;
		showroom_slot_limit: number | null;
		starts_at: string | null;
		ends_at: string | null;
	};
};

type ShopSettingsPageProps = {
	shop_settings: ShopSettingsPayload;
};

type ApprovalItemConfig = {
	key: keyof ApprovalPages;
	title: string;
	description: string;
	helper: string;
};

const ToggleSwitch: React.FC<{
	enabled: boolean;
	onChange: (enabled: boolean) => void;
	disabled?: boolean;
	ariaLabel: string;
}> = ({ enabled, onChange, disabled = false, ariaLabel }) => {
	return (
		<button
			type="button"
			onClick={() => onChange(!enabled)}
			disabled={disabled}
			aria-label={ariaLabel}
			title={ariaLabel}
			className={`relative inline-flex h-6 w-11 shrink-0 rounded-full border-2 border-transparent transition-colors duration-200 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 ${
				enabled ? 'bg-blue-600' : 'bg-gray-300'
			} ${disabled ? 'cursor-not-allowed opacity-50' : 'cursor-pointer'}`}
		>
			<span
				className={`pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow transition duration-200 ${
					enabled ? 'translate-x-5' : 'translate-x-0'
				}`}
			/>
		</button>
	);
};

const APPROVAL_ITEMS: ApprovalItemConfig[] = [
	{
		key: 'refund_approval',
		title: 'Refund Approval',
		description: 'Require approval for customer refunds above your configured amount.',
		helper: 'When enabled, refunds at or above this amount must be approved before processing.',
	},
	{
		key: 'price_approval',
		title: 'Price Approvals',
		description: 'Require approval for all staff-initiated price changes.',
		helper: 'When enabled, every staff-initiated price change requires owner approval. No amount limit is applied.',
	},
	{
		key: 'purchase_request_approval',
		title: 'Purchase Request Approval',
		description: 'Require approval for purchase requests that exceed your threshold.',
		helper: 'When disabled, owner approval is skipped and finance checks continue to apply.',
	},
];

const REPAIR_REQUEST_LIMIT_KEY = 'repair_request_limit';
const DEFAULT_REPAIR_REQUEST_LIMIT = 20;

const formatOrdinalDay = (day: number): string => {
	const mod10 = day % 10;
	const mod100 = day % 100;
	if (mod10 === 1 && mod100 !== 11) return `${day}st of the month`;
	if (mod10 === 2 && mod100 !== 12) return `${day}nd of the month`;
	if (mod10 === 3 && mod100 !== 13) return `${day}rd of the month`;
	return `${day}th of the month`;
};

const FIRST_PAYOUT_DAY_OPTIONS = Array.from({ length: 30 }, (_, index) => index + 1);
const SECOND_PAYOUT_DAY_OPTIONS = Array.from({ length: 31 }, (_, index) => index + 1);

const POLICY_SECTION_LABELS: Record<string, { title: string; helper: string }> = {
	refund_payment_terms: {
		title: 'Refund and Payment Terms',
		helper: 'Describe refund eligibility, settlement windows, and payment handling rules.',
	},
	repair_service_terms: {
		title: 'Repair Service Terms',
		helper: 'Define timelines, workmanship scope, and return-flow responsibilities.',
	},
	retail_terms: {
		title: 'Retail Terms',
		helper: 'Specify stock confirmation, shipping expectations, and retail refund windows.',
	},
};

const CUSTOM_POLICY_SECTION_PREFIX_LEGACY = 'custom_terms_';
const CUSTOM_POLICY_SECTION_PREFIX_RETAIL = 'custom_terms_retail_';
const CUSTOM_POLICY_SECTION_PREFIX_REPAIR = 'custom_terms_repair_';
const POLICY_SECTION_TITLE_META_PREFIX = '__section_title__';
const POLICY_SECTION_KEY_META_PREFIX = '__section_key__';
const POLICY_SECTION_CUSTOM_CLAUSES_META_PREFIX = '__section_custom_clauses__';
const POLICY_SECTION_DELETED_META_PREFIX = '__section_deleted__';

const getPolicySectionTitleMetaKey = (sectionKey: string): string => `${POLICY_SECTION_TITLE_META_PREFIX}${sectionKey}`;
const getPolicySectionKeyMetaKey = (sectionKey: string): string => `${POLICY_SECTION_KEY_META_PREFIX}${sectionKey}`;
const getPolicySectionCustomClausesMetaKey = (sectionKey: string): string => `${POLICY_SECTION_CUSTOM_CLAUSES_META_PREFIX}${sectionKey}`;
const getPolicySectionDeletedMetaKey = (sectionKey: string): string => `${POLICY_SECTION_DELETED_META_PREFIX}${sectionKey}`;

const isLegacyCustomPolicySectionKey = (sectionKey: string): boolean => (
	sectionKey.startsWith(CUSTOM_POLICY_SECTION_PREFIX_LEGACY)
		&& !sectionKey.startsWith(CUSTOM_POLICY_SECTION_PREFIX_RETAIL)
		&& !sectionKey.startsWith(CUSTOM_POLICY_SECTION_PREFIX_REPAIR)
);

const isRetailScopedCustomSectionKey = (sectionKey: string): boolean => (
	sectionKey.startsWith(CUSTOM_POLICY_SECTION_PREFIX_RETAIL)
		|| isLegacyCustomPolicySectionKey(sectionKey)
);

const isRepairScopedCustomSectionKey = (sectionKey: string): boolean => (
	sectionKey.startsWith(CUSTOM_POLICY_SECTION_PREFIX_REPAIR)
);

const isCustomPolicySectionKey = (sectionKey: string): boolean => (
	isRetailScopedCustomSectionKey(sectionKey) || isRepairScopedCustomSectionKey(sectionKey)
);

const getCustomPolicySectionDisplayNumber = (sectionKey: string): string => {
	if (sectionKey.startsWith(CUSTOM_POLICY_SECTION_PREFIX_RETAIL)) {
		return sectionKey.replace(CUSTOM_POLICY_SECTION_PREFIX_RETAIL, '') || '?';
	}

	if (sectionKey.startsWith(CUSTOM_POLICY_SECTION_PREFIX_REPAIR)) {
		return sectionKey.replace(CUSTOM_POLICY_SECTION_PREFIX_REPAIR, '') || '?';
	}

	if (isLegacyCustomPolicySectionKey(sectionKey)) {
		return sectionKey.replace(CUSTOM_POLICY_SECTION_PREFIX_LEGACY, '') || '?';
	}

	return '?';
};

type PolicyClauseTemplate = {
	id: string;
	title: string;
	body: string;
};

type PolicyCustomClause = {
	id: string;
	title: string;
	body: string;
};

type PolicySectionComposerState = {
	templates: Array<PolicyClauseTemplate & { checked: boolean }>;
	customClauses: PolicyCustomClause[];
	showOtherInput: boolean;
	otherTitle: string;
	otherBody: string;
};

const POLICY_SECTION_TEMPLATE_LIBRARY: Record<PolicySectionKey, PolicyClauseTemplate[]> = {
	refund_payment_terms: [],
	repair_service_terms: [],
	retail_terms: [],
};

const composePolicySectionText = (clauses: PolicyCustomClause[]): string => {
	const normalizedClauses = clauses
		.map((clause) => ({
			title: String(clause.title ?? '').trim(),
			body: String(clause.body ?? '').trim(),
		}))
		.filter((clause) => clause.title.length > 0 && clause.body.length > 0);

	return normalizedClauses
		.map((clause, index) => `${index + 1}. ${clause.title}\n${clause.body}`)
		.join('\n\n');
};

const isGenericCustomClauseTitle = (value: string): boolean => {
	const normalized = String(value || '')
		.toLowerCase()
		.replace(/[^a-z0-9]/g, '');

	return normalized === 'others'
		|| normalized === 'customclause'
		|| normalized === 'otherscustomclause';
};

const deriveCustomClauseTitleFromBody = (body: string, index: number): string => {
	const firstLine = String(body || '')
		.split('\n')
		.map((line) => line.trim())
		.find((line) => line.length > 0);

	if (!firstLine) return `Custom Clause ${index + 1}`;
	return firstLine.slice(0, 60);
};

const createPolicySectionComposerState = (sectionKey: string): PolicySectionComposerState => {
	const templates = (POLICY_SECTION_TEMPLATE_LIBRARY[sectionKey as PolicySectionKey] ?? []).map((template) => ({
		...template,
		checked: false,
	}));

	return {
		templates,
		customClauses: [],
		showOtherInput: false,
		otherTitle: '',
		otherBody: '',
	};
};

const normalizePolicyTextForMatch = (value: string): string => (
	String(value || '')
		.toLowerCase()
		.replace(/\s+/g, ' ')
		.replace(/[^a-z0-9\s]/g, '')
		.trim()
);

const createPolicySectionComposerStateFromSavedText = (
	sectionKey: string,
	sectionText: string,
): PolicySectionComposerState => {
	const normalizedSectionText = normalizePolicyTextForMatch(sectionText);

	const templates = (POLICY_SECTION_TEMPLATE_LIBRARY[sectionKey as PolicySectionKey] ?? []).map((template) => {
		const normalizedTitle = normalizePolicyTextForMatch(template.title);
		const normalizedBody = normalizePolicyTextForMatch(template.body);
		const isChecked = normalizedSectionText.length > 0
			&& ((normalizedBody.length > 0 && normalizedSectionText.includes(normalizedBody))
				|| (normalizedTitle.length > 0 && normalizedSectionText.includes(normalizedTitle)));

		return {
			...template,
			checked: isChecked,
		};
	});

	return {
		templates,
		customClauses: [],
		showOtherInput: false,
		otherTitle: '',
		otherBody: '',
	};
};

const createPolicyComposerRecord = (
	keys: string[],
	sourceSections?: ShopPolicySections,
): Record<string, PolicySectionComposerState> => {
	return keys.reduce<Record<string, PolicySectionComposerState>>((acc, key) => {
		const sectionText = String(sourceSections?.[key] ?? '');
		acc[key] = sectionText.trim().length > 0
			? createPolicySectionComposerStateFromSavedText(key, sectionText)
			: createPolicySectionComposerState(key);
		return acc;
	}, {});
};

const readRepairRequestLimit = (): number => {
	if (typeof window === 'undefined') return DEFAULT_REPAIR_REQUEST_LIMIT;
	const raw = window.localStorage.getItem(REPAIR_REQUEST_LIMIT_KEY);
	const parsed = Number(raw);
	if (!Number.isFinite(parsed) || parsed < 1) return DEFAULT_REPAIR_REQUEST_LIMIT;
	return Math.floor(parsed);
};

const ShopSetting: React.FC = () => {
	const { shop_settings } = usePage<ShopSettingsPageProps>().props;
	const normalizedRegistrationType = String(shop_settings.registration_type ?? '')
		.trim()
		.toLowerCase()
		.replace(/[-\s]+/g, '_');
	const normalizedBusinessType = String(shop_settings.business_type ?? '').toLowerCase().trim();
	const isBothSignal = normalizedBusinessType === 'both' || normalizedBusinessType.includes('both');
	const hasRepairSignal = isBothSignal || normalizedBusinessType.includes('repair') || normalizedBusinessType.includes('service');
	const hasRetailSignal = isBothSignal || normalizedBusinessType.includes('retail') || normalizedBusinessType.includes('shoe') || normalizedBusinessType.includes('product');
	const isIndividual = normalizedRegistrationType === 'individual'
		|| normalizedRegistrationType.startsWith('individual_')
		|| normalizedRegistrationType.endsWith('_individual');
	const LAST_SHOP_OWNER_PAGE_KEY = 'shop_owner_last_sidebar_page';
	const [saveSuccess, setSaveSuccess] = useState(false);
	const [processing, setProcessing] = useState(false);
	const [errors, setErrors] = useState<Record<string, string>>({});
	const [approvalPages, setApprovalPages] = useState<ApprovalPages>(shop_settings.approval_pages);
	const [repairPaymentPolicy, setRepairPaymentPolicy] = useState<'deposit_50' | 'full_upfront'>(
		shop_settings.repair_payment_policy ?? 'deposit_50',
	);
	const [payCycle, setPayCycle] = useState<'monthly' | 'semi_monthly'>(shop_settings.pay_cycle ?? 'monthly');
	const initialPayDayFirst = Math.min(Math.max(shop_settings.pay_day_first ?? 15, 1), 30);
	const initialPayDaySecond = Math.min(Math.max(shop_settings.pay_day_second ?? 30, initialPayDayFirst + 1), 31);
	const [payDayFirst, setPayDayFirst] = useState<number>(initialPayDayFirst);
	const [payDaySecond, setPayDaySecond] = useState<number>(initialPayDaySecond);
	const [activePayoutPicker, setActivePayoutPicker] = useState<'first' | 'second' | null>(null);
	const [savingPayrollCutoff, setSavingPayrollCutoff] = useState(false);
	const [payrollCutoffSuccess, setPayrollCutoffSuccess] = useState(false);
	const [payrollCutoffError, setPayrollCutoffError] = useState<string | null>(null);

	// PayMongo key state
	const [hasPaymongoKey, setHasPaymongoKey] = useState(shop_settings.has_paymongo_key ?? false);
	const [keyInput, setKeyInput] = useState('');
	const [showKey, setShowKey] = useState(false);
	const [savingKey, setSavingKey] = useState(false);
	const [keySuccess, setKeySuccess] = useState(false);
	const [keyError, setKeyError] = useState<string | null>(null);
	const [removingKey, setRemovingKey] = useState(false);
	const [showRevokeConfirm, setShowRevokeConfirm] = useState(false);
	const [autoRenewalEnabled, setAutoRenewalEnabled] = useState(
		Boolean(shop_settings.premium?.auto_renew ?? shop_settings.premium?.has_active ?? false),
	);
	const [savingAutoRenewal, setSavingAutoRenewal] = useState(false);
	const [autoRenewalError, setAutoRenewalError] = useState<string | null>(null);
	const [autoRenewalSuccess, setAutoRenewalSuccess] = useState(false);
	const [twoFactorEmailEnabled, setTwoFactorEmailEnabled] = useState(Boolean(shop_settings.two_factor_email_enabled));
	const [savingTwoFactor, setSavingTwoFactor] = useState(false);
	const [twoFactorError, setTwoFactorError] = useState<string | null>(null);
	const [twoFactorSuccess, setTwoFactorSuccess] = useState(false);
	const [policySections, setPolicySections] = useState<ShopPolicySections>({});
	const [activePublishedPolicySections, setActivePublishedPolicySections] = useState<ShopPolicySections>({});
	const [defaultPolicySections, setDefaultPolicySections] = useState<ShopPolicySections>({});
	const [policyVersionNumber, setPolicyVersionNumber] = useState<number | null>(null);
	const [loadingPolicyState, setLoadingPolicyState] = useState(false);
	const [policyError, setPolicyError] = useState<string | null>(null);
	const [policySuccess, setPolicySuccess] = useState<string | null>(null);
	const [savingPolicyDraft, setSavingPolicyDraft] = useState(false);
	const [publishingPolicy, setPublishingPolicy] = useState(false);
	const showWideRepairPaymentPolicy = isIndividual && hasRepairSignal;
	const showWideApprovalLimits = !isIndividual && hasRetailSignal && !hasRepairSignal;
	const requiredSectionKeys = requiredPolicySectionKeys(shop_settings.business_type);
	const [deletedBasePolicySectionKeys, setDeletedBasePolicySectionKeys] = useState<string[]>([]);
	const activeRequiredSectionKeys = requiredSectionKeys.filter((key) => !deletedBasePolicySectionKeys.includes(key));
	const [retailCustomPolicySectionKeys, setRetailCustomPolicySectionKeys] = useState<string[]>([]);
	const [repairCustomPolicySectionKeys, setRepairCustomPolicySectionKeys] = useState<string[]>([]);
	const [policyComposerState, setPolicyComposerState] = useState<Record<string, PolicySectionComposerState>>(
		() => createPolicyComposerRecord(requiredSectionKeys),
	);
	const [policySectionTitleOverrides, setPolicySectionTitleOverrides] = useState<Record<string, string>>({});
	const [policyBusinessView, setPolicyBusinessView] = useState<'retail' | 'repair'>(hasRetailSignal ? 'retail' : 'repair');
	const [activePolicySectionKey, setActivePolicySectionKey] = useState<string | null>(requiredSectionKeys[0] ?? null);
	const commonPolicySectionKeys = activeRequiredSectionKeys.filter((key) => key !== 'repair_service_terms' && key !== 'retail_terms');
	const retailPolicySectionKeys = activeRequiredSectionKeys.filter((key) => key === 'retail_terms');
	const repairPolicySectionKeys = activeRequiredSectionKeys.filter((key) => key === 'repair_service_terms');
	const scopedPolicySectionKeys = isBothSignal
		? (policyBusinessView === 'retail' ? retailPolicySectionKeys : repairPolicySectionKeys)
		: activeRequiredSectionKeys.filter((key) => key === 'retail_terms' || key === 'repair_service_terms');
	const activeCustomPolicySectionKeys = isBothSignal
		? (policyBusinessView === 'retail' ? retailCustomPolicySectionKeys : repairCustomPolicySectionKeys)
		: hasRetailSignal
			? retailCustomPolicySectionKeys
			: repairCustomPolicySectionKeys;
	const baseVisiblePolicySectionKeys = isBothSignal
		? [...commonPolicySectionKeys, ...scopedPolicySectionKeys]
		: activeRequiredSectionKeys;
	const visiblePolicySectionKeys = [...baseVisiblePolicySectionKeys, ...activeCustomPolicySectionKeys];

	// Repair workload limit state — server prop is source of truth, localStorage is a cache
	const serverLimit = shop_settings.repair_workload_limit ?? 20;
	const [repairRequestLimit, setRepairRequestLimit] = useState<number>(serverLimit);
	const [limitInputValue, setLimitInputValue] = useState<string>(String(serverLimit));
	const [limitInputError, setLimitInputError] = useState<string | null>(null);
	const [limitSaveSuccess, setLimitSaveSuccess] = useState(false);
	const [orderRefundDeadlineDays, setOrderRefundDeadlineDays] = useState<number>(shop_settings.order_refund_deadline_days ?? 7);
	const [refundDeadlineInputValue, setRefundDeadlineInputValue] = useState<string>(String(shop_settings.order_refund_deadline_days ?? 7));
	const [refundDeadlineInputError, setRefundDeadlineInputError] = useState<string | null>(null);
	const [refundDeadlineSaveSuccess, setRefundDeadlineSaveSuccess] = useState(false);

	// Geofence state
	const [geofenceEnabled, setGeofenceEnabled] = useState(shop_settings.attendance_geofence_enabled ?? false);
	const [geoLat, setGeoLat] = useState<string>(shop_settings.shop_latitude?.toString() ?? '');
	const [geoLng, setGeoLng] = useState<string>(shop_settings.shop_longitude?.toString() ?? '');
	const [geoAddress, setGeoAddress] = useState(shop_settings.shop_address ?? '');
	const [geoRadius, setGeoRadius] = useState<number>(shop_settings.shop_geofence_radius ?? 100);
	const [savingGeo, setSavingGeo] = useState(false);
	const [geoSuccess, setGeoSuccess] = useState(false);
	const [geoError, setGeoError] = useState<string | null>(null);
	const [gettingGPS, setGettingGPS] = useState(false);
	const [addressSearch, setAddressSearch] = useState('');
	const [addressResults, setAddressResults] = useState<Array<{display_name: string; lat: string; lon: string}>>([]);
	const [searchingAddress, setSearchingAddress] = useState(false);
	const mapRef = useRef<HTMLDivElement>(null);
	const leafletMapRef = useRef<any>(null);
	const markerRef = useRef<any>(null);
	const circleRef = useRef<any>(null);

	const reverseGeocode = async (lat: string | number, lng: string | number): Promise<string | null> => {
		try {
			const res = await fetch(
				`/api/address/geocode?latitude=${lat}&longitude=${lng}`,
			);
			if (!res.ok) throw new Error('Address lookup failed');
			const data = await res.json();
			return typeof data?.display_name === 'string' && data.display_name.trim() !== '' ? data.display_name : null;
		} catch {
			return null;
		}
	};

	const savePaymongoKey = async () => {
		if (!keyInput.trim()) return;
		setSavingKey(true);
		setKeyError(null);
		setKeySuccess(false);
		try {
			const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
			await axios.post(
				'/shop-owner/settings/paymongo-key',
				{ paymongo_secret_key: keyInput.trim() },
				{ headers: { 'X-CSRF-TOKEN': csrfToken || '' } },
			);
			setHasPaymongoKey(true);
			setKeyInput('');
			setKeySuccess(true);
			window.setTimeout(() => setKeySuccess(false), 3000);
		} catch (err: any) {
			setKeyError(err?.response?.data?.message || 'Failed to save key. Please try again.');
		} finally {
			setSavingKey(false);
		}
	};

	const removePaymongoKey = async () => {
		setRemovingKey(true);
		setKeyError(null);
		try {
			const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
			await axios.delete('/shop-owner/settings/paymongo-key', {
				headers: { 'X-CSRF-TOKEN': csrfToken || '' },
			});
			setHasPaymongoKey(false);
			setShowRevokeConfirm(false);
			setKeyInput('');
		} catch {
			setKeyError('Failed to remove key. Please try again.');
			setShowRevokeConfirm(false);
		} finally {
			setRemovingKey(false);
		}
	};

	const handleToggleAutoRenewal = async (enabled: boolean) => {
		if (savingAutoRenewal) return;

		const previous = autoRenewalEnabled;
		setAutoRenewalEnabled(enabled);
		setSavingAutoRenewal(true);
		setAutoRenewalError(null);
		setAutoRenewalSuccess(false);

		try {
			const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
			const response = await axios.patch(
				'/api/shop-owner/premium/auto-renew',
				{ enabled },
				{ headers: { 'X-CSRF-TOKEN': csrfToken || '' } },
			);

			const persistedValue = Boolean(response?.data?.subscription?.auto_renew ?? enabled);
			setAutoRenewalEnabled(persistedValue);
			setAutoRenewalSuccess(true);
			window.setTimeout(() => setAutoRenewalSuccess(false), 2200);
		} catch (err: any) {
			setAutoRenewalEnabled(previous);
			setAutoRenewalError(err?.response?.data?.message || 'Failed to update auto renewal setting.');
		} finally {
			setSavingAutoRenewal(false);
		}
	};

	const handleToggleTwoFactorEmail = (enabled: boolean) => {
		if (savingTwoFactor) return;

		const previous = twoFactorEmailEnabled;
		setTwoFactorEmailEnabled(enabled);
		setSavingTwoFactor(true);
		setTwoFactorError(null);
		setTwoFactorSuccess(false);

		router.put(
			'/shop-owner/settings',
			{ two_factor_email_enabled: enabled },
			{
				preserveScroll: true,
				onSuccess: () => {
					setTwoFactorSuccess(true);
					window.setTimeout(() => setTwoFactorSuccess(false), 2200);
				},
				onError: (pageErrors) => {
					setTwoFactorEmailEnabled(previous);
					const errors = pageErrors as Record<string, string | undefined>;
					setTwoFactorError(errors.two_factor_email_enabled || 'Failed to update two-factor authentication setting.');
				},
				onFinish: () => {
					setSavingTwoFactor(false);
				},
			},
		);
	};

	const accountLabel = isIndividual ? 'Individual Account' : 'Business Account';
	const isRepairOnlyShop = hasRepairSignal && !hasRetailSignal;
	const businessTypeLabel = hasRetailSignal && hasRepairSignal
		? 'Retail & Repair'
		: hasRepairSignal
			? 'Repair Services'
			: 'Retail Shop';
	const premiumStatus = shop_settings.premium?.status;
	const premiumIsActive = Boolean(shop_settings.premium?.has_active);
	const premiumIsEligible = Boolean(shop_settings.premium?.eligible);
	const autoRenewalToggleDisabled = savingAutoRenewal || !premiumIsActive;
	const formatPremiumDate = (value: string | null) => {
		if (!value) return null;
		const date = new Date(value);
		if (Number.isNaN(date.getTime())) return null;
		return date.toLocaleDateString(undefined, {
			year: 'numeric',
			month: 'long',
			day: 'numeric',
		});
	};
	const formatNextBillingDate = (startValue: string | null) => {
		if (!startValue) return null;
		const start = new Date(startValue);
		if (Number.isNaN(start.getTime())) return null;

		const nextBilling = new Date(start);
		const now = new Date();
		while (nextBilling <= now) {
			nextBilling.setMonth(nextBilling.getMonth() + 1);
		}

		return nextBilling.toLocaleDateString(undefined, {
			year: 'numeric',
			month: 'long',
			day: 'numeric',
		});
	};
	const premiumEndsAt = formatPremiumDate(shop_settings.premium?.ends_at ?? null);
	const premiumStartsAt = formatPremiumDate(shop_settings.premium?.starts_at ?? null);
	const premiumNextBillingAt = premiumIsActive
		? (premiumEndsAt || formatNextBillingDate(shop_settings.premium?.starts_at ?? null))
		: null;
	const premiumBadgeClass = premiumIsActive
		? 'border-green-200 bg-green-50 text-green-700'
		: premiumIsEligible
				? 'border-gray-300 bg-gray-100 text-gray-700'
				: 'border-red-200 bg-red-50 text-red-700';
	const premiumBadgeLabel = premiumIsActive
		? 'Premium Active'
		: premiumIsEligible
				? 'Premium Inactive'
				: 'Not Eligible';
	const showPremiumBadge = premiumIsActive || premiumIsEligible;

	useEffect(() => {
		setAutoRenewalEnabled(Boolean(shop_settings.premium?.auto_renew ?? shop_settings.premium?.has_active ?? false));
	}, [shop_settings.premium?.auto_renew, shop_settings.premium?.has_active]);

	useEffect(() => {
		setRetailCustomPolicySectionKeys((prev) => {
			const next = prev.filter((key) => isRetailScopedCustomSectionKey(key));
			return next.length === prev.length ? prev : next;
		});

		setRepairCustomPolicySectionKeys((prev) => {
			const next = prev.filter((key) => isRepairScopedCustomSectionKey(key));
			return next.length === prev.length ? prev : next;
		});
	}, []);

	useEffect(() => {
		setTwoFactorEmailEnabled(Boolean(shop_settings.two_factor_email_enabled));
	}, [shop_settings.two_factor_email_enabled]);

	const normalizePolicySections = (source: ShopPolicySections | null | undefined): ShopPolicySections => {
		const normalized: ShopPolicySections = {};

		Object.entries(source ?? {}).forEach(([key, value]) => {
			if (isCustomPolicySectionKey(key)) {
				normalized[key] = String(value ?? '');
			}
		});

		return normalized;
	};

	const extractPolicySectionTitleOverrides = (source: ShopPolicySections | null | undefined): Record<string, string> => {
		const overrides: Record<string, string> = {};

		Object.entries(source ?? {}).forEach(([key, value]) => {
			if (!key.startsWith(POLICY_SECTION_TITLE_META_PREFIX)) return;

			const sectionKey = key.slice(POLICY_SECTION_TITLE_META_PREFIX.length);
			const title = String(value ?? '').trim();
			if (!sectionKey || title.length === 0) return;

			overrides[sectionKey] = title;
		});

		return overrides;
	};

	const extractPolicyCustomSectionKeysFromMeta = (source: ShopPolicySections | null | undefined): string[] => (
		Object.keys(source ?? {})
			.filter((key) => key.startsWith(POLICY_SECTION_KEY_META_PREFIX))
			.map((key) => key.slice(POLICY_SECTION_KEY_META_PREFIX.length))
			.filter((sectionKey) => sectionKey.length > 0 && isCustomPolicySectionKey(sectionKey))
	);

	const extractDeletedBasePolicySectionKeys = (source: ShopPolicySections | null | undefined): string[] => (
		Object.entries(source ?? {})
			.filter(([key, value]) => {
				if (!key.startsWith(POLICY_SECTION_DELETED_META_PREFIX)) return false;
				const marker = String(value ?? '').trim().toLowerCase();
				return marker === '1' || marker === 'true' || marker === 'yes';
			})
			.map(([key]) => key.slice(POLICY_SECTION_DELETED_META_PREFIX.length))
			.filter((sectionKey) => requiredSectionKeys.includes(sectionKey))
	);

	const extractPolicySectionCustomClauses = (source: ShopPolicySections | null | undefined): Record<string, PolicyCustomClause[]> => {
		const customClausesBySection: Record<string, PolicyCustomClause[]> = {};

		Object.entries(source ?? {}).forEach(([key, value]) => {
			if (!key.startsWith(POLICY_SECTION_CUSTOM_CLAUSES_META_PREFIX)) return;

			const sectionKey = key.slice(POLICY_SECTION_CUSTOM_CLAUSES_META_PREFIX.length);
			if (!sectionKey) return;

			const rawValue = String(value ?? '').trim();
			if (!rawValue) return;

			try {
				const parsed = JSON.parse(rawValue);
				if (!Array.isArray(parsed)) return;

				const normalizedCustomClauses = parsed
					.map((clause, index) => {
						const body = String(clause?.body ?? '').trim();
						if (!body) return null;

						const rawTitle = String(clause?.title ?? '').trim();
						const title = !rawTitle || isGenericCustomClauseTitle(rawTitle)
							? deriveCustomClauseTitleFromBody(body, index)
							: rawTitle;

						const clauseId = String(clause?.id ?? '').trim() || `custom-${sectionKey}-${index + 1}`;
						return {
							id: clauseId,
							title,
							body,
						};
					})
					.filter((clause): clause is PolicyCustomClause => clause !== null);

				if (normalizedCustomClauses.length > 0) {
					customClausesBySection[sectionKey] = normalizedCustomClauses;
				}
			} catch {
				// Ignore malformed metadata to avoid blocking policy editor load.
			}
		});

		return customClausesBySection;
	};

	const buildPolicySectionsPayload = (sections: ShopPolicySections): ShopPolicySections => {
		const payload: ShopPolicySections = { ...sections };

		Object.entries(policySectionTitleOverrides).forEach(([sectionKey, title]) => {
			const normalizedTitle = String(title ?? '').trim();
			if (!normalizedTitle) return;

			payload[getPolicySectionTitleMetaKey(sectionKey)] = normalizedTitle;
		});

		const allCustomSectionKeys = Array.from(new Set([
			...retailCustomPolicySectionKeys,
			...repairCustomPolicySectionKeys,
		]));

		allCustomSectionKeys.forEach((sectionKey) => {
			payload[sectionKey] = String(payload[sectionKey] ?? '');
			payload[getPolicySectionKeyMetaKey(sectionKey)] = '1';
		});

		deletedBasePolicySectionKeys.forEach((sectionKey) => {
			payload[getPolicySectionDeletedMetaKey(sectionKey)] = '1';
			delete payload[sectionKey];
			delete payload[getPolicySectionTitleMetaKey(sectionKey)];
			delete payload[getPolicySectionCustomClausesMetaKey(sectionKey)];
		});

		const allComposerSectionKeys = Array.from(new Set([
			...requiredSectionKeys,
			...retailCustomPolicySectionKeys,
			...repairCustomPolicySectionKeys,
		]));

		allComposerSectionKeys.forEach((sectionKey) => {
			const normalizedCustomClauses = (policyComposerState[sectionKey]?.customClauses ?? [])
				.map((clause, index) => {
					const body = String(clause.body ?? '').trim();
					if (!body) return null;

					const rawTitle = String(clause.title ?? '').trim();
					const title = !rawTitle || isGenericCustomClauseTitle(rawTitle)
						? deriveCustomClauseTitleFromBody(body, index)
						: rawTitle;

					const clauseId = String(clause.id ?? '').trim() || `custom-${sectionKey}-${index + 1}`;
					return {
						id: clauseId,
						title,
						body,
					};
				})
				.filter((clause): clause is PolicyCustomClause => clause !== null);

			if (normalizedCustomClauses.length === 0) return;
			payload[getPolicySectionCustomClausesMetaKey(sectionKey)] = JSON.stringify(normalizedCustomClauses);
		});

		return payload;
	};

	useEffect(() => {
		const composerSectionKeys = [...activeRequiredSectionKeys, ...retailCustomPolicySectionKeys, ...repairCustomPolicySectionKeys];

		setPolicyComposerState((prev) => {
			const next = { ...prev };
			composerSectionKeys.forEach((key) => {
				if (!next[key]) {
					next[key] = createPolicySectionComposerState(key);
				}
			});

			Object.keys(next).forEach((key) => {
				if (!composerSectionKeys.includes(key)) {
					delete next[key];
				}
			});

			return next;
		});
	}, [activeRequiredSectionKeys.join('|'), retailCustomPolicySectionKeys.join('|'), repairCustomPolicySectionKeys.join('|')]);

	useEffect(() => {
		if (visiblePolicySectionKeys.length === 0) {
			setActivePolicySectionKey(null);
			return;
		}

		if (activePolicySectionKey !== null && !visiblePolicySectionKeys.includes(activePolicySectionKey)) {
			setActivePolicySectionKey(visiblePolicySectionKeys[0]);
		}
	}, [visiblePolicySectionKeys.join('|'), activePolicySectionKey]);

	const loadPolicyEditorState = async () => {
		setLoadingPolicyState(true);
		setPolicyError(null);

		try {
			const response = await axios.get<ShopPolicyEditorStateResponse>('/shop-owner/settings/policies');
			const payload = response.data?.data;
			const normalizedActiveSections = normalizePolicySections(payload?.active?.policy_sections_json ?? payload?.default_sections ?? {});
			const normalizedDefaultSections = normalizePolicySections(payload?.default_sections ?? {});
			const selectedSections = payload?.draft?.policy_sections_json
				?? payload?.active?.policy_sections_json
				?? {};
			const normalizedDeletedBaseSectionKeys = extractDeletedBasePolicySectionKeys(selectedSections);
			const normalizedSections = normalizePolicySections(selectedSections);
			const normalizedTitleOverrides = extractPolicySectionTitleOverrides(selectedSections);
			const normalizedCustomClausesBySection = extractPolicySectionCustomClauses(selectedSections);
			const customSectionKeysFromSections = Object.keys(normalizedSections)
				.filter((key) => !requiredSectionKeys.includes(key) && isCustomPolicySectionKey(key));
			const customSectionKeysFromTitleMeta = Object.keys(normalizedTitleOverrides)
				.filter((key) => !requiredSectionKeys.includes(key) && isCustomPolicySectionKey(key));
			const customSectionKeysFromClausesMeta = Object.keys(normalizedCustomClausesBySection)
				.filter((key) => !requiredSectionKeys.includes(key) && isCustomPolicySectionKey(key));
			const customSectionKeysFromKeyMeta = extractPolicyCustomSectionKeysFromMeta(selectedSections)
				.filter((key) => !requiredSectionKeys.includes(key));
			const allLoadedCustomSectionKeys = Array.from(new Set([
				...customSectionKeysFromSections,
				...customSectionKeysFromTitleMeta,
				...customSectionKeysFromClausesMeta,
				...customSectionKeysFromKeyMeta,
			]));
			const loadedRetailCustomSectionKeys = allLoadedCustomSectionKeys
				.filter((key) => isRetailScopedCustomSectionKey(key))
				.sort((a, b) => a.localeCompare(b, undefined, { numeric: true }));
			const loadedRepairCustomSectionKeys = allLoadedCustomSectionKeys
				.filter((key) => isRepairScopedCustomSectionKey(key))
				.sort((a, b) => a.localeCompare(b, undefined, { numeric: true }));

			const normalizedRetailCustomSectionKeys = hasRetailSignal
				? loadedRetailCustomSectionKeys
				: loadedRetailCustomSectionKeys.filter((key) => !isLegacyCustomPolicySectionKey(key));
			const normalizedRepairCustomSectionKeys = (!hasRetailSignal && hasRepairSignal)
				? Array.from(new Set([
					...loadedRepairCustomSectionKeys,
					...loadedRetailCustomSectionKeys.filter((key) => isLegacyCustomPolicySectionKey(key)),
				]))
				: loadedRepairCustomSectionKeys;
			const sectionsWithDetectedCustomKeys: ShopPolicySections = { ...normalizedSections };
			[...normalizedRetailCustomSectionKeys, ...normalizedRepairCustomSectionKeys].forEach((sectionKey) => {
				if (!Object.prototype.hasOwnProperty.call(sectionsWithDetectedCustomKeys, sectionKey)) {
					sectionsWithDetectedCustomKeys[sectionKey] = '';
				}
			});
			normalizedDeletedBaseSectionKeys.forEach((sectionKey) => {
				delete sectionsWithDetectedCustomKeys[sectionKey];
			});

			const filteredTitleOverrides = Object.entries(normalizedTitleOverrides).reduce<Record<string, string>>((acc, [sectionKey, title]) => {
				if (normalizedDeletedBaseSectionKeys.includes(sectionKey)) return acc;
				acc[sectionKey] = title;
				return acc;
			}, {});

			setPolicySections(sectionsWithDetectedCustomKeys);
			setActivePublishedPolicySections(normalizedActiveSections);
			setDefaultPolicySections(normalizedDefaultSections);
			setDeletedBasePolicySectionKeys(normalizedDeletedBaseSectionKeys);
			setRetailCustomPolicySectionKeys(normalizedRetailCustomSectionKeys);
			setRepairCustomPolicySectionKeys(normalizedRepairCustomSectionKeys);
			setPolicySectionTitleOverrides(filteredTitleOverrides);
			const nextComposerState = createPolicyComposerRecord([
				...requiredSectionKeys.filter((key) => !normalizedDeletedBaseSectionKeys.includes(key)),
				...normalizedRetailCustomSectionKeys,
				...normalizedRepairCustomSectionKeys,
			], sectionsWithDetectedCustomKeys);

			Object.entries(normalizedCustomClausesBySection).forEach(([sectionKey, customClauses]) => {
				const existingState = nextComposerState[sectionKey] ?? createPolicySectionComposerState(sectionKey);
				nextComposerState[sectionKey] = {
					...existingState,
					customClauses,
				};
			});

			setPolicyComposerState(nextComposerState);
			setPolicyVersionNumber(payload?.draft?.version_number ?? payload?.active?.version_number ?? null);
		} catch (err: any) {
			setPolicyError(err?.response?.data?.message || 'Failed to load policy editor state.');
		} finally {
			setLoadingPolicyState(false);
		}
	};

	useEffect(() => {
		void loadPolicyEditorState();
	}, []);

	const updatePolicySection = (key: string, value: string) => {
		setPolicySections((prev) => ({
			...prev,
			[key]: value,
		}));
		if (policyError) setPolicyError(null);
		if (policySuccess) setPolicySuccess(null);
	};

	const updateComposerSection = (
		sectionKey: string,
		updater: (state: PolicySectionComposerState) => PolicySectionComposerState,
	) => {
		setPolicyComposerState((prev) => {
			const current = prev[sectionKey] ?? createPolicySectionComposerState(sectionKey);
			return {
				...prev,
				[sectionKey]: updater(current),
			};
		});

		if (policyError) setPolicyError(null);
		if (policySuccess) setPolicySuccess(null);
	};

	const toggleComposerTemplate = (sectionKey: string, templateId: string, checked: boolean) => {
		updateComposerSection(sectionKey, (state) => ({
			...state,
			templates: state.templates.map((template) => (
				template.id === templateId ? { ...template, checked } : template
			)),
		}));
	};

	const updateComposerTemplateField = (sectionKey: string, templateId: string, field: 'title' | 'body', value: string) => {
		updateComposerSection(sectionKey, (state) => ({
			...state,
			templates: state.templates.map((template) => (
				template.id === templateId ? { ...template, [field]: value } : template
			)),
		}));
	};

	const toggleOthersInput = (sectionKey: string, checked: boolean) => {
		updateComposerSection(sectionKey, (state) => ({
			...state,
			showOtherInput: checked,
			otherTitle: checked ? state.otherTitle : '',
			otherBody: checked ? state.otherBody : '',
		}));
	};

	const updateOtherDraftField = (sectionKey: string, field: 'otherTitle' | 'otherBody', value: string) => {
		updateComposerSection(sectionKey, (state) => ({
			...state,
			[field]: value,
		}));
	};

	const addOtherClause = (sectionKey: string) => {
		const sectionComposer = policyComposerState[sectionKey] ?? createPolicySectionComposerState(sectionKey);
		const title = sectionComposer.otherTitle.trim();
		const body = sectionComposer.otherBody.trim();

		if (!title || !body) {
			setPolicyError('Please complete both title and details for the custom clause.');
			void UserSwal.fire({
				icon: 'warning',
				title: 'Missing Section Input',
				text: 'Please complete both clause title and details before adding a custom clause.',
				confirmButtonText: 'OK',
			});
			return;
		}

		if (isGenericCustomClauseTitle(title)) {
			setPolicyError('Please enter a specific clause title.');
			void UserSwal.fire({
				icon: 'warning',
				title: 'Clause Title Required',
				text: 'Use a specific clause title. Generic titles like "Others" are not allowed.',
				confirmButtonText: 'OK',
			});
			return;
		}

		updateComposerSection(sectionKey, (state) => ({
			...state,
			customClauses: [
				...state.customClauses,
				{
					id: `custom-${Date.now()}`,
					title,
					body,
				},
			],
			showOtherInput: false,
			otherTitle: '',
			otherBody: '',
		}));

		setPolicyError(null);
		setPolicySuccess('Custom clause saved.');
		void UserSwal.fire({
			icon: 'success',
			title: 'Custom Clause Saved',
			text: 'Your custom clause has been added to this section.',
			confirmButtonText: 'OK',
		});
	};

	const updateCustomClauseField = (sectionKey: string, clauseId: string, field: 'title' | 'body', value: string) => {
		updateComposerSection(sectionKey, (state) => ({
			...state,
			customClauses: state.customClauses.map((clause) => (
				clause.id === clauseId ? { ...clause, [field]: value } : clause
			)),
		}));
	};

	const removeCustomClause = async (sectionKey: string, clauseId: string) => {
		const result = await UserSwal.fire({
			icon: 'warning',
			title: 'Remove Custom Clause?',
			text: 'This clause will be removed from your builder list.',
			showCancelButton: true,
			confirmButtonText: 'Remove',
			cancelButtonText: 'Cancel',
		});

		if (!result.isConfirmed) return;

		updateComposerSection(sectionKey, (state) => ({
			...state,
			customClauses: state.customClauses.filter((clause) => clause.id !== clauseId),
		}));

		setPolicySuccess('Custom clause removed.');
		setPolicyError(null);
	};

	const setAllTemplateSelection = (sectionKey: string, checked: boolean) => {
		updateComposerSection(sectionKey, (state) => ({
			...state,
			templates: state.templates.map((template) => ({ ...template, checked })),
		}));
	};

	const resetComposerBuilder = (sectionKey: string) => {
		updateComposerSection(sectionKey, () => createPolicySectionComposerState(sectionKey));
	};

	const clearPolicySectionEditor = (sectionKey: string) => {
		setPolicySections((prev) => ({
			...prev,
			[sectionKey]: '',
		}));

		if (policyError) setPolicyError(null);
		if (policySuccess) setPolicySuccess(null);
	};

	const getDefaultPolicySectionMeta = (sectionKey: string): { title: string; helper: string } => {
		const knownSection = POLICY_SECTION_LABELS[sectionKey];
		if (knownSection) return knownSection;

		if (isCustomPolicySectionKey(sectionKey)) {
			const sectionNumber = getCustomPolicySectionDisplayNumber(sectionKey);
			return {
				title: `Additional Terms ${sectionNumber}`,
				helper: 'Create another terms section for extra policies and custom rules.',
			};
		}

		return {
			title: sectionKey,
			helper: 'Provide policy text for this section.',
		};
	};

	const getPolicySectionMeta = (sectionKey: string): { title: string; helper: string } => {
		const baseMeta = getDefaultPolicySectionMeta(sectionKey);
		const overrideTitle = String(policySectionTitleOverrides[sectionKey] ?? '').trim();

		if (!overrideTitle) {
			return baseMeta;
		}

		return {
			title: overrideTitle,
			helper: baseMeta.helper,
		};
	};

	const editPolicySectionName = async (sectionKey: string) => {
		const currentTitle = getPolicySectionMeta(sectionKey).title;
		const defaultTitle = getDefaultPolicySectionMeta(sectionKey).title;

		const result = await UserSwal.fire({
			title: 'Edit Section Name',
			input: 'text',
			inputLabel: 'Section name',
			inputValue: currentTitle,
			showCancelButton: true,
			confirmButtonText: 'Save',
			cancelButtonText: 'Cancel',
			inputValidator: (value) => {
				if (!String(value ?? '').trim()) {
					return 'Section name is required.';
				}

				return undefined;
			},
		});

		if (!result.isConfirmed) return;

		const nextTitle = String(result.value ?? '').trim();

		setPolicySectionTitleOverrides((prev) => {
			const next = { ...prev };

			if (!nextTitle || nextTitle === defaultTitle) {
				delete next[sectionKey];
			} else {
				next[sectionKey] = nextTitle;
			}

			return next;
		});

		setPolicyError(null);
		setPolicySuccess('Section name updated.');
	};

	const addCustomPolicySection = () => {
		const targetScope: 'retail' | 'repair' = isBothSignal
			? policyBusinessView
			: hasRetailSignal
				? 'retail'
				: 'repair';

		const targetPrefix = targetScope === 'retail'
			? CUSTOM_POLICY_SECTION_PREFIX_RETAIL
			: CUSTOM_POLICY_SECTION_PREFIX_REPAIR;

		const targetKeys = targetScope === 'retail'
			? retailCustomPolicySectionKeys
			: repairCustomPolicySectionKeys;

		let nextIndex = 1;
		let nextKey = `${targetPrefix}${nextIndex}`;
		while (targetKeys.includes(nextKey)) {
			nextIndex += 1;
			nextKey = `${targetPrefix}${nextIndex}`;
		}

		if (targetScope === 'retail') {
			setRetailCustomPolicySectionKeys((prev) => [...prev, nextKey]);
		} else {
			setRepairCustomPolicySectionKeys((prev) => [...prev, nextKey]);
		}

		setPolicySections((prev) => ({ ...prev, [nextKey]: '' }));
		setPolicyComposerState((prev) => ({ ...prev, [nextKey]: createPolicySectionComposerState(nextKey) }));
		setActivePolicySectionKey(nextKey);
		setPolicyError(null);
		setPolicySuccess(`${targetScope === 'retail' ? 'Retail' : 'Repair'} additional terms dropdown added.`);
	};

	const removeCustomPolicySection = async (sectionKey: string) => {
		const result = await UserSwal.fire({
			icon: 'warning',
			title: 'Remove Additional Section?',
			text: 'This extra dropdown section and its text will be removed.',
			showCancelButton: true,
			confirmButtonText: 'Remove',
			cancelButtonText: 'Cancel',
		});

		if (!result.isConfirmed) return;

		setRetailCustomPolicySectionKeys((prev) => prev.filter((key) => key !== sectionKey));
		setRepairCustomPolicySectionKeys((prev) => prev.filter((key) => key !== sectionKey));
		setPolicySections((prev) => {
			const next = { ...prev };
			delete next[sectionKey];
			return next;
		});
		setPolicyComposerState((prev) => {
			const next = { ...prev };
			delete next[sectionKey];
			return next;
		});
		setPolicySectionTitleOverrides((prev) => {
			const next = { ...prev };
			delete next[sectionKey];
			return next;
		});

		if (activePolicySectionKey === sectionKey) {
			setActivePolicySectionKey(null);
		}

		setPolicyError(null);
		setPolicySuccess(`${isRetailScopedCustomSectionKey(sectionKey) ? 'Retail' : 'Repair'} additional terms dropdown removed.`);
	};

	const removeBasePolicySection = async (sectionKey: string) => {
		const result = await UserSwal.fire({
			icon: 'warning',
			title: 'Remove Section?',
			text: 'This will remove the entire section from this policy version.',
			showCancelButton: true,
			confirmButtonText: 'Remove',
			cancelButtonText: 'Cancel',
		});

		if (!result.isConfirmed) return;

		setDeletedBasePolicySectionKeys((prev) => (prev.includes(sectionKey) ? prev : [...prev, sectionKey]));
		setPolicySections((prev) => {
			const next = { ...prev };
			delete next[sectionKey];
			return next;
		});

		setPolicyComposerState((prev) => {
			const next = { ...prev };
			delete next[sectionKey];
			return next;
		});

		setPolicySectionTitleOverrides((prev) => {
			const next = { ...prev };
			delete next[sectionKey];
			return next;
		});

		if (activePolicySectionKey === sectionKey) {
			setActivePolicySectionKey(null);
		}

		setPolicyError(null);
		setPolicySuccess('Section removed.');
	};

	const deletePolicySection = async (sectionKey: string) => {
		if (isCustomPolicySectionKey(sectionKey)) {
			await removeCustomPolicySection(sectionKey);
			return;
		}

		await removeBasePolicySection(sectionKey);
	};


	const buildSectionTextFromComposerSelection = (sectionKey: string): string => {
		const sectionComposer = policyComposerState[sectionKey] ?? createPolicySectionComposerState(sectionKey);
		const selectedTemplateClauses: PolicyCustomClause[] = sectionComposer.templates
			.filter((template) => template.checked)
			.map((template) => ({
				id: template.id,
				title: template.title,
				body: template.body,
			}));

		const selectedClauses = [...selectedTemplateClauses, ...sectionComposer.customClauses];
		return composePolicySectionText(selectedClauses);
	};

	const applyComposerSelectionsToEmptySections = (baseSections: ShopPolicySections): { sections: ShopPolicySections; usedComposerAutofill: boolean } => {
		const nextSections: ShopPolicySections = { ...baseSections };
		let usedComposerAutofill = false;

		const sectionKeys = Array.from(new Set([
			...requiredSectionKeys,
			...retailCustomPolicySectionKeys,
			...repairCustomPolicySectionKeys,
		]));

		sectionKeys.forEach((sectionKey) => {
			const currentValue = String(nextSections[sectionKey] ?? '').trim();
			if (currentValue.length > 0) return;

			const composedText = buildSectionTextFromComposerSelection(sectionKey).trim();
			if (!composedText) return;

			nextSections[sectionKey] = composedText;
			usedComposerAutofill = true;
		});

		return {
			sections: nextSections,
			usedComposerAutofill,
		};
	};

	const applyComposerSelectionToEditor = (sectionKey: string, mode: 'replace' | 'append') => {
		const sectionComposer = policyComposerState[sectionKey] ?? createPolicySectionComposerState(sectionKey);
		const selectedTemplateClauses: PolicyCustomClause[] = sectionComposer.templates
			.filter((template) => template.checked)
			.map((template) => ({
				id: template.id,
				title: template.title,
				body: template.body,
			}));
		const selectedClauses = [...selectedTemplateClauses, ...sectionComposer.customClauses];
		const composedText = composePolicySectionText(selectedClauses);

		if (!composedText.trim()) {
			setPolicyError('Select at least one predefined clause or add an Others clause before applying.');
			void UserSwal.fire({
				icon: 'warning',
				title: 'Missing Section Input',
				text: 'Select at least one clause or add an Others clause before applying to the editor.',
				confirmButtonText: 'OK',
			});
			return;
		}

		setPolicySections((prev) => {
			const currentValue = String(prev[sectionKey] ?? '').trim();
			const nextValue = mode === 'append' && currentValue
				? `${currentValue}\n\n${composedText}`
				: composedText;

			return {
				...prev,
				[sectionKey]: nextValue,
			};
		});

		setPolicySuccess(mode === 'append'
			? 'Selected clauses appended to the section editor.'
			: 'Selected clauses applied to the section editor.');
		setPolicyError(null);
	};

	const validateSectionInputsBeforeSave = async (): Promise<boolean> => {
		const sectionWithPendingOthers = visiblePolicySectionKeys.find((sectionKey) => {
			const sectionComposer = policyComposerState[sectionKey] ?? createPolicySectionComposerState(sectionKey);
			if (!sectionComposer.showOtherInput) return false;

			return !sectionComposer.otherTitle.trim() || !sectionComposer.otherBody.trim();
		});

		if (sectionWithPendingOthers) {
			setActivePolicySectionKey(sectionWithPendingOthers);
			setPolicyError('Please complete the Others clause fields in the active section before saving.');
			await UserSwal.fire({
				icon: 'warning',
				title: 'Missing Section Input',
				text: 'Please complete the Others clause title and details in the section before saving.',
				confirmButtonText: 'OK',
			});
			return false;
		}

		const sectionWithIncompleteCustomClause = visiblePolicySectionKeys.find((sectionKey) => {
			const sectionComposer = policyComposerState[sectionKey] ?? createPolicySectionComposerState(sectionKey);
			return sectionComposer.customClauses.some((clause) => !String(clause.title ?? '').trim() || !String(clause.body ?? '').trim());
		});

		if (sectionWithIncompleteCustomClause) {
			setActivePolicySectionKey(sectionWithIncompleteCustomClause);
			setPolicyError('Please complete all custom clause fields in the active section before saving.');
			await UserSwal.fire({
				icon: 'warning',
				title: 'Missing Section Input',
				text: 'Please complete all custom clause title and details fields before saving.',
				confirmButtonText: 'OK',
			});
			return false;
		}

		return true;
	};

	const savePolicyDraft = async () => {
		const sectionsForSave: ShopPolicySections = { ...policySections };
		const isValid = await validateSectionInputsBeforeSave();
		if (!isValid) return;

		setSavingPolicyDraft(true);
		setPolicyError(null);
		setPolicySuccess(null);

		try {
			const response = await axios.put('/shop-owner/settings/policies/draft', {
				policy_sections_json: buildPolicySectionsPayload(sectionsForSave),
			});

			setPolicyVersionNumber(response.data?.data?.version_number ?? policyVersionNumber);
			setPolicySuccess('Policy draft saved.');
		} catch (err: any) {
			setPolicyError(err?.response?.data?.message || 'Failed to save policy draft.');
		} finally {
			setSavingPolicyDraft(false);
		}
	};

	const publishPolicyVersion = async () => {
		const sectionsForPublish: ShopPolicySections = { ...policySections };
		const isValid = await validateSectionInputsBeforeSave();
		if (!isValid) return;

		const publishConfirmation = await UserSwal.fire({
			icon: 'question',
			title: 'Save Policy Now?',
			text: 'This will publish the latest policy and make it active immediately for users.',
			showCancelButton: true,
			confirmButtonText: 'Yes, Save',
			cancelButtonText: 'Cancel',
		});

		if (!publishConfirmation.isConfirmed) return;

		setPublishingPolicy(true);
		setPolicyError(null);
		setPolicySuccess(null);

		try {
			const draftResponse = await axios.put('/shop-owner/settings/policies/draft', {
				policy_sections_json: buildPolicySectionsPayload(sectionsForPublish),
			});

			setPolicyVersionNumber(draftResponse.data?.data?.version_number ?? policyVersionNumber);

			const response = await axios.post('/shop-owner/settings/policies/publish');
			setPolicyVersionNumber(response.data?.data?.version_number ?? policyVersionNumber);
			setPolicySuccess('Policy published and active immediately.');
			await loadPolicyEditorState();
		} catch (err: any) {
			const serverMessage = err?.response?.data?.message;
			if (typeof serverMessage === 'string' && serverMessage.includes('No query results for model')) {
				setPolicyError('No draft policy found to publish. Save a draft first, then publish.');
			} else {
				setPolicyError(serverMessage || 'Failed to publish policy version.');
			}
		} finally {
			setPublishingPolicy(false);
		}
	};

	const accountFeatures: Array<{ label: string; enabled: boolean }> = [
		{ label: 'Staff Management', enabled: shop_settings.can_manage_staff },
		{ label: 'Shop Profile Management', enabled: true },
		{ label: 'Shop Notification Settings', enabled: true },
		{ label: 'Email OTP Two-Factor Login', enabled: twoFactorEmailEnabled },
		{ label: 'Approval Limit Controls', enabled: true },
		{ label: 'Audit Logs Access', enabled: true },
		{ label: 'Retail Order Workflows', enabled: hasRetailSignal },
		{ label: 'Repair Service Workflows', enabled: hasRepairSignal },
		{ label: 'Staff & Access Control', enabled: shop_settings.can_manage_staff },
	];

	// ── Geofence helpers ──────────────────────────────────────────────────
	useEffect(() => {
		if (!mapRef.current) return;
		if (leafletMapRef.current) return; // already initialised

		import('leaflet').then((L) => {
			// Fix default marker icons broken by webpack
			delete (L.Icon.Default.prototype as any)._getIconUrl;
			L.Icon.Default.mergeOptions({
				iconRetinaUrl: 'https://unpkg.com/leaflet@1.9.4/dist/images/marker-icon-2x.png',
				iconUrl: 'https://unpkg.com/leaflet@1.9.4/dist/images/marker-icon.png',
				shadowUrl: 'https://unpkg.com/leaflet@1.9.4/dist/images/marker-shadow.png',
			});

			const initLat = parseFloat(geoLat) || 14.5995;
			const initLng = parseFloat(geoLng) || 120.9842;

			const map = L.map(mapRef.current!).setView([initLat, initLng], 16);
			L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
				attribution: '© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
			}).addTo(map);

			const marker = L.marker([initLat, initLng], { draggable: true }).addTo(map);
			const circle = L.circle([initLat, initLng], { radius: geoRadius, color: '#2563eb', fillOpacity: 0.08 }).addTo(map);

			marker.on('dragend', async () => {
				const pos = marker.getLatLng();
				const lat = pos.lat.toFixed(8);
				const lng = pos.lng.toFixed(8);
				setGeoLat(lat);
				setGeoLng(lng);
				circle.setLatLng(pos);
				const detectedAddress = await reverseGeocode(lat, lng);
				if (detectedAddress) {
					setGeoAddress(detectedAddress);
					setAddressSearch(detectedAddress);
				}
			});

			map.on('click', async (e: any) => {
				marker.setLatLng(e.latlng);
				circle.setLatLng(e.latlng);
				const lat = e.latlng.lat.toFixed(8);
				const lng = e.latlng.lng.toFixed(8);
				setGeoLat(lat);
				setGeoLng(lng);
				const detectedAddress = await reverseGeocode(lat, lng);
				if (detectedAddress) {
					setGeoAddress(detectedAddress);
					setAddressSearch(detectedAddress);
				}
			});

			leafletMapRef.current = map;
			markerRef.current = marker;
			circleRef.current = circle;
		});
	}, []); // run once on mount — mapRef.current is stable

	// Keep circle radius in sync when slider changes
	useEffect(() => {
		if (circleRef.current) circleRef.current.setRadius(geoRadius);
	}, [geoRadius]);

	// Pan map + update marker when lat/lng state changes externally (GPS / search)
	useEffect(() => {
		const lat = parseFloat(geoLat);
		const lng = parseFloat(geoLng);
		if (!isNaN(lat) && !isNaN(lng) && leafletMapRef.current && markerRef.current && circleRef.current) {
			leafletMapRef.current.setView([lat, lng], 16);
			markerRef.current.setLatLng([lat, lng]);
			circleRef.current.setLatLng([lat, lng]);
		}
	}, [geoLat, geoLng]);

	const handleUseMyGPS = () => {
		if (!navigator.geolocation) {
			setGeoError('Geolocation is not supported by your browser.');
			return;
		}
		setGettingGPS(true);
		setGeoError(null);
		navigator.geolocation.getCurrentPosition(
			async (pos) => {
				try {
					const lat = pos.coords.latitude.toFixed(8);
					const lng = pos.coords.longitude.toFixed(8);
					setGeoLat(lat);
					setGeoLng(lng);

					const detectedAddress = await reverseGeocode(lat, lng);
					if (detectedAddress) {
						setGeoAddress(detectedAddress);
						setAddressSearch(detectedAddress);
					}
				} catch {
					setGeoError('Could not identify your GPS address. Please try searching instead.');
				} finally {
					setGettingGPS(false);
				}
			},
			() => {
				setGeoError('Could not get your location. Please allow location access.');
				setGettingGPS(false);
			},
			GPS_POSITION_OPTIONS,
		);
	};

	const handleAddressSearch = async () => {
		if (!addressSearch.trim()) return;
		setSearchingAddress(true);
		setAddressResults([]);
		try {
			const res = await fetch(
				`/api/address/geocode?q=${encodeURIComponent(addressSearch)}&limit=5`,
			);
			if (!res.ok) throw new Error('Address search failed');
			const data = await res.json();
			setAddressResults(data);
		} catch {
			setGeoError('Address search failed. Please try again.');
		} finally {
			setSearchingAddress(false);
		}
	};

	const handleSelectAddress = (result: { display_name: string; lat: string; lon: string }) => {
		setGeoLat(parseFloat(result.lat).toFixed(8));
		setGeoLng(parseFloat(result.lon).toFixed(8));
		setGeoAddress(result.display_name);
		setAddressSearch(result.display_name);
		setAddressResults([]);
	};

	const handleSaveRepairRequestLimit = () => {
		const parsed = Number(limitInputValue);
		if (!Number.isFinite(parsed) || parsed < 1 || parsed > 500) {
			setLimitInputError('Please enter a limit from 1 to 500.');
			return;
		}
		const newLimit = Math.floor(parsed);
		setLimitInputError(null);

		// Save to backend via the existing settings PUT route
		router.put(
			'/shop-owner/settings',
			{ approval_pages: approvalPages, repair_payment_policy: repairPaymentPolicy, repair_workload_limit: newLimit },
			{
				preserveScroll: true,
				onSuccess: () => {
					setRepairRequestLimit(newLimit);
					// Also cache locally so repair pages can read it without an extra API call
					window.localStorage.setItem(REPAIR_REQUEST_LIMIT_KEY, String(newLimit));
					setLimitSaveSuccess(true);
					window.setTimeout(() => setLimitSaveSuccess(false), 2200);
				},
				onError: () => {
					setLimitInputError('Failed to save. Please try again.');
				},
			},
		);
	};

	const handleSaveOrderRefundDeadlineDays = () => {
		const parsed = Number(refundDeadlineInputValue);
		if (!Number.isFinite(parsed) || parsed < 1 || parsed > 30) {
			setRefundDeadlineInputError('Please enter a refund deadline from 1 to 30 days.');
			return;
		}

		const newDays = Math.floor(parsed);
		setRefundDeadlineInputError(null);

		router.put(
			'/shop-owner/settings',
			{
				approval_pages: approvalPages,
				repair_payment_policy: repairPaymentPolicy,
				order_refund_deadline_days: newDays,
			},
			{
				preserveScroll: true,
				onSuccess: () => {
					setOrderRefundDeadlineDays(newDays);
					setRefundDeadlineSaveSuccess(true);
					window.setTimeout(() => setRefundDeadlineSaveSuccess(false), 2200);
				},
				onError: (pageErrors) => {
					const pageValidationErrors = pageErrors as Record<string, string | undefined>;
					setRefundDeadlineInputError(pageValidationErrors.order_refund_deadline_days || 'Failed to save refund deadline. Please try again.');
				},
			},
		);
	};

	const handleSavePayrollCutoff = () => {
		if (payCycle === 'monthly') {
			setPayrollCutoffError(null);
			setSavingPayrollCutoff(true);

			router.put(
				'/shop-owner/settings',
				{ pay_cycle: 'monthly' },
				{
					preserveScroll: true,
					onSuccess: () => {
						setPayrollCutoffSuccess(true);
						window.setTimeout(() => setPayrollCutoffSuccess(false), 2200);
					},
					onError: (pageErrors) => {
						const errors = pageErrors as Record<string, string | undefined>;
						setPayrollCutoffError(errors.pay_cycle || 'Failed to save payroll cycle settings. Please try again.');
					},
					onFinish: () => {
						setSavingPayrollCutoff(false);
					},
				},
			);

			return;
		}

		const firstDay = Math.floor(Number(payDayFirst));
		const secondDay = Math.floor(Number(payDaySecond));

		if (!Number.isFinite(firstDay) || !Number.isFinite(secondDay) || firstDay < 1 || firstDay > 31 || secondDay < 1 || secondDay > 31) {
			setPayrollCutoffError('Please enter valid payout days from 1 to 31.');
			return;
		}

		if (secondDay <= firstDay) {
			setPayrollCutoffError('Second payout day must be greater than first payout day.');
			return;
		}

		setPayrollCutoffError(null);
		setSavingPayrollCutoff(true);

		router.put(
			'/shop-owner/settings',
			{
				pay_cycle: payCycle,
				pay_day_first: firstDay,
				pay_day_second: secondDay,
			},
			{
				preserveScroll: true,
				onSuccess: () => {
					setPayDayFirst(firstDay);
					setPayDaySecond(secondDay);
					setPayrollCutoffSuccess(true);
					window.setTimeout(() => setPayrollCutoffSuccess(false), 2200);
				},
				onError: (pageErrors) => {
					const errors = pageErrors as Record<string, string | undefined>;
					setPayrollCutoffError(
						errors.pay_day_second
							|| errors.pay_day_first
							|| errors.pay_cycle
							|| 'Failed to save payroll cutoff settings. Please try again.'
					);
				},
				onFinish: () => {
					setSavingPayrollCutoff(false);
				},
			},
		);
	};

	const closePayoutDayPicker = () => {
		setActivePayoutPicker(null);
	};

	const openPayoutDayPicker = (target: 'first' | 'second') => {
		setActivePayoutPicker(target);
		if (payrollCutoffError) setPayrollCutoffError(null);
	};

	const isSelectingFirstPayoutDay = activePayoutPicker === 'first';
	const payoutPickerOptions = isSelectingFirstPayoutDay
		? FIRST_PAYOUT_DAY_OPTIONS
		: SECOND_PAYOUT_DAY_OPTIONS.filter((day) => day > payDayFirst);
	const payoutPickerSelectedDay = isSelectingFirstPayoutDay ? payDayFirst : payDaySecond;
	const payoutPickerTitle = isSelectingFirstPayoutDay ? 'Select first payout day' : 'Select second payout day';
	const payoutPickerHint = isSelectingFirstPayoutDay
		? 'Choose the day when your first semi-monthly payout is released.'
		: `Second payout must be after ${formatOrdinalDay(payDayFirst)}.`;

	const handleSelectPayoutDay = (day: number) => {
		if (isSelectingFirstPayoutDay) {
			setPayDayFirst(day);
			if (payDaySecond <= day) {
				setPayDaySecond(Math.min(day + 1, 31));
			}
		} else {
			setPayDaySecond(day);
		}

		if (payrollCutoffError) setPayrollCutoffError(null);
		closePayoutDayPicker();
	};

	const saveGeofence = async () => {
		setSavingGeo(true);
		setGeoError(null);
		setGeoSuccess(false);
		try {
			const parsedLat = geoLat ? parseFloat(geoLat) : null;
			const parsedLng = geoLng ? parseFloat(geoLng) : null;
			const hasCoordinates = Number.isFinite(parsedLat) && Number.isFinite(parsedLng);

			let resolvedAddress = geoAddress?.trim() || addressSearch.trim() || '';
			if (hasCoordinates) {
				const detectedAddress = await reverseGeocode(parsedLat as number, parsedLng as number);
				if (detectedAddress) {
					resolvedAddress = detectedAddress;
					setGeoAddress(detectedAddress);
					setAddressSearch(detectedAddress);
				} else if (!resolvedAddress) {
					resolvedAddress = `${(parsedLat as number).toFixed(8)}, ${(parsedLng as number).toFixed(8)}`;
					setGeoAddress(resolvedAddress);
					setAddressSearch(resolvedAddress);
				}
			}

			const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
			await axios.post(
				'/shop-owner/settings/geofence',
				{
					attendance_geofence_enabled: isIndividual ? false : geofenceEnabled,
					shop_latitude: hasCoordinates ? (parsedLat as number) : null,
					shop_longitude: hasCoordinates ? (parsedLng as number) : null,
					shop_address: resolvedAddress || null,
					shop_geofence_radius: geoRadius,
				},
				{ headers: { 'X-CSRF-TOKEN': csrfToken || '' } },
			);
			setGeoSuccess(true);
			window.setTimeout(() => setGeoSuccess(false), 3000);
		} catch (err: any) {
			setGeoError(err?.response?.data?.message || 'Failed to save geofence settings.');
		} finally {
			setSavingGeo(false);
		}
	};
	// ── End Geofence helpers ───────────────────────────────────────────────

	const saveSettings = (nextApprovalPages: ApprovalPages, nextPolicy?: 'deposit_50' | 'full_upfront') => {
		setProcessing(true);
		setErrors({});

		router.put(
			'/shop-owner/settings',
			{ approval_pages: nextApprovalPages, repair_payment_policy: nextPolicy ?? repairPaymentPolicy },
			{
				preserveScroll: true,
				onSuccess: () => {
					setSaveSuccess(true);
					window.setTimeout(() => setSaveSuccess(false), 2200);
				},
				onError: (pageErrors) => {
					setErrors(pageErrors as Record<string, string>);
				},
				onFinish: () => setProcessing(false),
			},
		);
	};

	const handleToggle = (key: keyof ApprovalPages, enabled: boolean) => {
		const nextApprovalPages: ApprovalPages = {
			...approvalPages,
			[key]: {
				...approvalPages[key],
				enabled,
				limit: enabled ? approvalPages[key].limit : null,
			},
		};

		setApprovalPages(nextApprovalPages);
		saveSettings(nextApprovalPages);
	};

	const handleLimitChange = (key: keyof ApprovalPages, value: string) => {
		const parsed = Number(value);
		const nextLimit = value.trim() === '' || Number.isNaN(parsed) ? null : parsed;

		setApprovalPages((prev) => ({
			...prev,
			[key]: {
				...prev[key],
				limit: nextLimit,
			},
		}));
	};

	const handleSaveLimit = (key: keyof ApprovalPages) => {
		saveSettings({ ...approvalPages, [key]: { ...approvalPages[key] } });
	};

	useEffect(() => {
		if (typeof window === 'undefined') return;
		if (!document.referrer) return;

		try {
			const ref = new URL(document.referrer);
			if (ref.origin !== window.location.origin) return;

			const refPath = `${ref.pathname}${ref.search}`;
			const isShopOwnerPath = ref.pathname.startsWith('/shop-owner/');
			const blockedPaths = ['/shop-owner/settings', '/shop-owner/premium-benefits', '/payment'];
			const isBlocked = blockedPaths.some((path) => ref.pathname.startsWith(path));

			if (isShopOwnerPath && !isBlocked) {
				window.sessionStorage.setItem(LAST_SHOP_OWNER_PAGE_KEY, refPath);
			}
		} catch {
			// Ignore invalid referrer URLs.
		}
	}, []);

	useEffect(() => {
		if (!activePayoutPicker || typeof document === 'undefined') return;

		const previousOverflow = document.body.style.overflow;
		document.body.style.overflow = 'hidden';

		const handleKeyDown = (event: KeyboardEvent) => {
			if (event.key === 'Escape') {
				closePayoutDayPicker();
			}
		};

		window.addEventListener('keydown', handleKeyDown);

		return () => {
			document.body.style.overflow = previousOverflow;
			window.removeEventListener('keydown', handleKeyDown);
		};
	}, [activePayoutPicker]);

	useEffect(() => {
		if (payCycle !== 'semi_monthly' && activePayoutPicker) {
			setActivePayoutPicker(null);
		}
	}, [payCycle, activePayoutPicker]);

	const handleBackFromSettings = () => {
		if (typeof window === 'undefined') {
			router.get('/shop-owner/dashboard');
			return;
		}

		const lastSidebarPage = window.sessionStorage.getItem(LAST_SHOP_OWNER_PAGE_KEY);
		if (lastSidebarPage) {
			router.get(lastSidebarPage);
			return;
		}

		router.get('/shop-owner/dashboard');
	};

	const togglePolicySectionExpansion = (sectionKey: string) => {
		setActivePolicySectionKey((prev) => (prev === sectionKey ? null : sectionKey));
	};

	return (
		<>
			<Head title="Shop Settings" />

			<div className="min-h-screen bg-slate-50">
				<div className="mx-auto max-w-6xl px-4 py-6 sm:px-6 lg:px-8">
					<div className="mb-6 flex items-center justify-between gap-3">
						<button
							type="button"
							onClick={handleBackFromSettings}
							className="inline-flex items-center rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 transition hover:bg-gray-50 hover:text-gray-900"
						>
							Back
						</button>
						{saveSuccess && (
							<div className="inline-flex items-center gap-2 rounded-lg border border-green-200 bg-green-50 px-3 py-2 text-sm font-medium text-green-800">
								<Check size={16} className="text-green-600" />
								Settings saved
							</div>
						)}
					</div>

					<div className="mb-6 rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
						<div className="mb-2 flex items-center gap-3">
							<Settings size={28} className="text-gray-700" />
							<h1 className="text-3xl font-bold text-gray-900">Shop Settings</h1>
						</div>
						<p className="text-sm text-gray-600">Manage payments, approvals, attendance geofence, compliance documents, and repair workflows from one place.</p>
					</div>

					<div className="grid grid-cols-1 gap-6 lg:grid-cols-12">

					<div className="relative overflow-hidden rounded-2xl border border-gray-300 bg-white p-5 shadow-sm lg:col-span-12 lg:order-1">
						<div className="pointer-events-none absolute -right-16 -top-20 h-56 w-56 rounded-full bg-black/5 blur-3xl" />
						<div className="pointer-events-none absolute -bottom-20 -left-16 h-56 w-56 rounded-full bg-gray-300/30 blur-3xl" />

						<div className="relative flex items-start gap-3">
							<div className="rounded-xl border border-gray-300 bg-gray-100 p-2.5">
								{isIndividual ? <User size={18} className="text-gray-800" /> : <Building2 size={18} className="text-gray-800" />}
							</div>
							<div className="min-w-0 flex-1">
								<div className="mb-1 flex items-start justify-between gap-3">
									<div className="flex flex-wrap items-center gap-2">
										<h3 className="text-lg font-semibold text-gray-900">{accountLabel}</h3>
										<span className="inline-flex items-center gap-1 rounded-full border border-gray-300 bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-700">
											{shop_settings.business_type === 'repair' ? <Wrench size={12} /> : <Store size={12} />}
											{businessTypeLabel}
										</span>
										{showPremiumBadge && (
											<span className={`inline-flex items-center rounded-full border px-2 py-0.5 text-[11px] font-semibold ${premiumBadgeClass}`}>
												{premiumBadgeLabel}
											</span>
										)}
									</div>
									{premiumIsEligible && (
										<div className="ml-auto flex shrink-0 items-center gap-2 rounded-lg border border-gray-200 bg-gray-50 px-2.5 py-1.5">
											<span className="text-xs font-medium text-gray-700">Auto Renewal</span>
											<ToggleSwitch
												enabled={autoRenewalEnabled}
												onChange={handleToggleAutoRenewal}
												disabled={autoRenewalToggleDisabled}
												ariaLabel="Toggle auto renewal subscription"
											/>
										</div>
									)}
								</div>
								<p className="truncate text-sm text-gray-700">{shop_settings.business_name || 'Business'}</p>

								{premiumIsEligible && (
									<div className="mt-3 rounded-xl border border-gray-200 bg-gray-50 px-3 py-3 text-sm text-gray-700">
										<p className="font-semibold text-gray-900">
											{shop_settings.premium.plan_name || 'No active premium plan'}
										</p>
										<p className="mt-1 text-xs text-gray-600">
											{premiumIsActive
												? (premiumNextBillingAt
													? `Next billing on ${premiumNextBillingAt}`
													: autoRenewalEnabled
														? 'Your subscription will automatically renew until cancelled.'
														: 'Auto renewal is turned off. Your subscription will end at the current billing period.')
												: 'Upgrade to unlock the virtual showroom and image-sequence uploads.'}
										</p>
										{shop_settings.premium.showroom_slot_limit ? (
											<p className="mt-1 text-xs text-gray-600">
												Showroom slots: {shop_settings.premium.showroom_slot_limit}
												{premiumStartsAt ? ` • Started ${premiumStartsAt}` : ''}
												{premiumNextBillingAt ? ` • Next billing ${premiumNextBillingAt}` : ''}
											</p>
										) : null}
										{premiumIsEligible && !premiumIsActive ? (
											<p className="mt-2 text-xs text-amber-700">Auto renewal can be changed once your subscription is active.</p>
										) : null}
										{autoRenewalError ? (
											<p className="mt-2 text-xs text-red-600">{autoRenewalError}</p>
										) : null}
										{autoRenewalSuccess ? (
											<p className="mt-2 flex items-center gap-1 text-xs font-medium text-green-700">
												<Check size={13} /> Auto renewal preference saved.
											</p>
										) : null}
									</div>
								)}

									<div className="mt-3 rounded-xl border border-gray-200 bg-gray-50 px-3 py-3 text-sm text-gray-700">
										<div className="flex items-start justify-between gap-3">
											<div className="min-w-0">
												<p className="font-semibold text-gray-900">Email OTP Two-Factor Login</p>
												<p className="mt-1 text-xs text-gray-600">Require a one-time code from email after entering password.</p>
											</div>
											<ToggleSwitch
												enabled={twoFactorEmailEnabled}
												onChange={handleToggleTwoFactorEmail}
												disabled={savingTwoFactor}
												ariaLabel="Toggle email OTP two-factor login"
											/>
										</div>
										{twoFactorError ? <p className="mt-2 text-xs text-red-600">{twoFactorError}</p> : null}
										{twoFactorSuccess ? (
											<p className="mt-2 flex items-center gap-1 text-xs font-medium text-green-700">
												<Check size={13} /> Two-factor login setting saved.
											</p>
										) : null}
									</div>

								<div className="mt-3 grid grid-cols-1 gap-2 sm:grid-cols-2">
									{accountFeatures.map((feature) => (
										<div key={feature.label} className="flex items-center gap-2 rounded-lg border border-gray-300 bg-gray-50 px-2.5 py-2 text-sm text-gray-800">
											<span className={`inline-block h-2.5 w-2.5 rounded-full ${feature.enabled ? 'bg-gray-900' : 'bg-gray-400'}`} />
											<span>{feature.label}</span>
										</div>
									))}
								</div>
								{premiumIsEligible && (
										<div className="mt-4 border-t border-gray-200 pt-4">
											{!premiumIsActive ? (
												<p className="mb-3 text-center text-sm text-gray-600">
													Unlock premium benefits: virtual showroom access, more display slots, horizontal product viewing, and image-sequence uploads.
												</p>
											) : null}
											<button
												type="button"
												onClick={() => router.get('/shop-owner/premium-benefits')}
												className="inline-flex w-full items-center justify-center rounded-xl border border-gray-900 bg-gray-900 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-black focus:outline-none focus:ring-2 focus:ring-gray-400 focus:ring-offset-2"
											>
												{premiumIsActive ? 'View Premium Benefits' : 'Upgrade Premium Benefits'}
											</button>
										</div>
									)}
								</div>
								</div>
							</div>

					<div className="lg:col-span-12 lg:order-3">
						<BusinessScalingSettings businessScaling={shop_settings.business_scaling} />
					</div>

					<div className="lg:col-span-12 lg:order-7">
						<BusinessDocumentCompliance documents={shop_settings.document_compliance} />
					</div>

					<div className="rounded-2xl border border-gray-200 bg-white shadow-sm lg:col-span-12 lg:order-2">
						<div className="border-b border-gray-200 p-6">
							<div className="flex flex-wrap items-start justify-between gap-3">
								<div>
									<h2 className="text-xl font-semibold text-gray-900">Terms and Conditions Policy</h2>
									<p className="mt-1 text-sm text-gray-600">
										Create a versioned terms policy used during repair and payment acceptance for your shop.
									</p>
								</div>

								{isBothSignal && (
									<div className="inline-flex rounded-lg border border-slate-300 bg-white p-1">
										<button
											type="button"
											onClick={() => {
												setPolicyBusinessView('retail');
												setActivePolicySectionKey((previous) => {
													const nextVisibleKeys = [
														...commonPolicySectionKeys,
														...retailPolicySectionKeys,
														...retailCustomPolicySectionKeys,
													];

													if (previous && nextVisibleKeys.includes(previous)) {
														return previous;
													}

													return nextVisibleKeys[0] ?? null;
												});
											}}
											className={`rounded-md px-3 py-1.5 text-xs font-semibold transition ${policyBusinessView === 'retail' ? 'bg-slate-900 text-white' : 'text-slate-700 hover:bg-slate-100'}`}
										>
											Retail Terms
										</button>
										<button
											type="button"
											onClick={() => {
												setPolicyBusinessView('repair');
												setActivePolicySectionKey((previous) => {
													const nextVisibleKeys = [
														...commonPolicySectionKeys,
														...repairPolicySectionKeys,
														...repairCustomPolicySectionKeys,
													];

													if (previous && nextVisibleKeys.includes(previous)) {
														return previous;
													}

													return nextVisibleKeys[0] ?? null;
												});
											}}
											className={`rounded-md px-3 py-1.5 text-xs font-semibold transition ${policyBusinessView === 'repair' ? 'bg-slate-900 text-white' : 'text-slate-700 hover:bg-slate-100'}`}
										>
											Repair Terms
										</button>
									</div>
								)}
							</div>
						</div>
						<div className="space-y-4 p-6">
							{loadingPolicyState ? (
								<p className="rounded-lg border border-blue-100 bg-blue-50 px-3 py-2 text-sm text-blue-800">Loading policy sections...</p>
							) : (
								<div className="space-y-4">
									{visiblePolicySectionKeys.map((key, index) => {
										const sectionMeta = getPolicySectionMeta(key);
										const isCustomSection = isCustomPolicySectionKey(key);
										const sectionComposer = policyComposerState[key] ?? createPolicySectionComposerState(key);
										const selectedTemplateCount = sectionComposer.templates.filter((template) => template.checked).length;
										const customClauseCount = sectionComposer.customClauses.length;
										const selectedClauseCount = selectedTemplateCount + customClauseCount;
										const editorValue = String(policySections[key] ?? '');
										const hasEditorText = editorValue.trim().length > 0;
										const previewText = hasEditorText
											? `${editorValue.replace(/\s+/g, ' ').trim().slice(0, 140)}${editorValue.replace(/\s+/g, ' ').trim().length > 140 ? '...' : ''}`
											: 'No final text yet. Choose clauses and apply to editor.';
										const editorWordCount = editorValue.trim().length === 0 ? 0 : editorValue.trim().split(/\s+/).length;
										const isExpanded = activePolicySectionKey === key;

										return (
											<div key={key} className={`overflow-hidden rounded-xl border bg-white ${isExpanded ? 'border-blue-300 shadow-sm' : 'border-slate-200'}`}>
												<div className="w-full px-4 py-3 text-left">
													<div className="flex flex-wrap items-center justify-between gap-2">
														<button
															type="button"
															onClick={() => togglePolicySectionExpansion(key)}
															className="flex min-w-0 flex-1 items-center gap-2 rounded-md px-0.5 py-0.5 text-left transition hover:bg-slate-50"
														>
															<span className="inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-slate-900 text-[11px] font-bold text-white">{index + 1}</span>
															<div className="min-w-0">
																<p className="truncate text-sm font-semibold text-slate-900">{sectionMeta.title}</p>
																<p className="mt-0.5 text-xs text-slate-600">{sectionMeta.helper}</p>
															</div>
														</button>
														<div className="flex items-center gap-1.5">
															<span className="rounded-full border border-blue-200 bg-blue-50 px-2 py-0.5 text-[11px] font-semibold text-blue-700">{selectedClauseCount} selected</span>
															<span className={`rounded-full border px-2 py-0.5 text-[11px] font-semibold ${hasEditorText ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-slate-200 bg-slate-50 text-slate-600'}`}>
																{hasEditorText ? 'Ready' : 'Needs text'}
															</span>
															<button
																type="button"
																onClick={() => {
																	void editPolicySectionName(key);
																}}
																className="inline-flex h-6 w-6 items-center justify-center rounded-md text-slate-500 transition hover:bg-slate-100 hover:text-slate-700"
																aria-label="Edit section name"
																title="Edit section name"
															>
																<Pencil size={14} />
															</button>
															<button
																type="button"
																onClick={() => {
																	void deletePolicySection(key);
																}}
																className="inline-flex h-6 w-6 items-center justify-center rounded-md text-red-600 transition hover:bg-red-50 hover:text-red-700"
																aria-label={isCustomSection ? 'Remove additional section' : 'Delete section content'}
																title={isCustomSection ? 'Remove additional section' : 'Delete section content'}
															>
																<Trash2 size={14} />
															</button>
															<button
																type="button"
																onClick={() => {
																	togglePolicySectionExpansion(key);
																}}
																className="inline-flex h-6 w-6 items-center justify-center rounded-md text-slate-500 transition hover:bg-slate-100"
																aria-label={isExpanded ? 'Collapse section' : 'Expand section'}
																title={isExpanded ? 'Collapse section' : 'Expand section'}
															>
																<ChevronDown size={16} className={`transition-transform ${isExpanded ? 'rotate-180' : ''}`} />
															</button>
														</div>
													</div>
													{!isExpanded && (
														<button
															type="button"
															onClick={() => togglePolicySectionExpansion(key)}
															className="mt-2 w-full rounded-md px-0.5 py-0.5 text-left text-xs text-slate-600 transition hover:bg-slate-50"
														>
															{previewText}
														</button>
													)}
												</div>

												{isExpanded && (
													<div id={`policy-section-body-${key}`} className="border-t border-slate-200 px-4 pb-4 pt-3">

												{sectionComposer.templates.length > 0 && (
													<div className="rounded-lg border border-slate-200 bg-slate-50 p-3">
														<div className="flex flex-wrap items-center justify-between gap-2">
															<p className="text-xs font-semibold uppercase tracking-wide text-slate-700">Predefined Clauses</p>
															<div className="flex flex-wrap items-center gap-1.5">
																<button
																	type="button"
																	onClick={() => setAllTemplateSelection(key, true)}
																	className="rounded-md border border-slate-300 bg-white px-2.5 py-1 text-[11px] font-semibold text-slate-700 transition hover:bg-slate-100"
																>
																	Select All
																</button>
																<button
																	type="button"
																	onClick={() => setAllTemplateSelection(key, false)}
																	className="rounded-md border border-slate-300 bg-white px-2.5 py-1 text-[11px] font-semibold text-slate-700 transition hover:bg-slate-100"
																>
																	Clear
																</button>
															</div>
														</div>
														<div className="mt-2 space-y-2">
															{sectionComposer.templates.map((template) => (
																<div key={`${key}-${template.id}`} className="rounded-md border border-slate-200 bg-white p-3">
																	<label className="flex cursor-pointer items-center gap-2 text-sm font-medium text-slate-800">
																		<input
																			type="checkbox"
																			checked={template.checked}
																			onChange={(event) => toggleComposerTemplate(key, template.id, event.target.checked)}
																			className="h-4 w-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500"
																		/>
																		{template.title}
																	</label>

																	{template.checked && (
																		<div className="mt-2 space-y-2">
																			<input
																				type="text"
																				value={template.title}
																				onChange={(event) => updateComposerTemplateField(key, template.id, 'title', event.target.value)}
																				placeholder="Clause title"
																				className="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-xs text-slate-900 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
																			/>
																			<textarea
																				value={template.body}
																				onChange={(event) => updateComposerTemplateField(key, template.id, 'body', event.target.value)}
																				rows={3}
																				title="Clause details"
																				placeholder="Edit predefined clause details"
																				className="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-xs text-slate-900 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
																			/>
																		</div>
																	)}
																</div>
															))}
														</div>
													</div>
												)}

												{sectionComposer.customClauses.length > 0 && (
													<div className="mt-3 space-y-2">
															<p className="text-xs font-semibold uppercase tracking-wide text-slate-600">Custom Clauses ({sectionComposer.customClauses.length})</p>
															{sectionComposer.customClauses.map((clause, clauseIndex) => (
															<div key={clause.id} className="rounded-md border border-slate-200 bg-slate-50 p-3">
																	<div className="mb-2 flex items-center justify-between">
																		<p className="text-xs font-semibold text-slate-600">Custom #{clauseIndex + 1}</p>
																	<button
																		type="button"
																		onClick={() => {
																			void removeCustomClause(key, clause.id);
																		}}
																		className="inline-flex h-7 w-7 items-center justify-center rounded-md text-red-600 transition hover:bg-red-50 hover:text-red-700"
																		aria-label="Remove custom clause"
																		title="Remove custom clause"
																	>
																		<Trash2 size={14} />
																	</button>
																</div>
																<input
																	type="text"
																	value={clause.title}
																	onChange={(event) => updateCustomClauseField(key, clause.id, 'title', event.target.value)}
																	placeholder="Custom clause title"
																	className="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-xs text-slate-900 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
																/>
																<textarea
																	value={clause.body}
																	onChange={(event) => updateCustomClauseField(key, clause.id, 'body', event.target.value)}
																	rows={3}
																	title="Custom clause details"
																	placeholder="Describe your custom clause"
																	className="mt-2 w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-xs text-slate-900 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
																/>
															</div>
														))}
													</div>
												)}

												<div className="mt-3 rounded-lg border border-dashed border-slate-300 bg-slate-50 p-3">
													<label className="flex cursor-pointer items-center gap-2 text-sm font-semibold text-slate-800">
														<input
															type="checkbox"
															checked={sectionComposer.showOtherInput}
															onChange={(event) => toggleOthersInput(key, event.target.checked)}
															className="h-4 w-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500"
														/>
															Add Custom Clause
													</label>

													{sectionComposer.showOtherInput && (
														<div className="mt-2 space-y-2">
															<input
																type="text"
																value={sectionComposer.otherTitle}
																onChange={(event) => updateOtherDraftField(key, 'otherTitle', event.target.value)}
																placeholder="Clause title (ex. Delivery Exceptions)"
																className="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-xs text-slate-900 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
															/>
															<textarea
																value={sectionComposer.otherBody}
																onChange={(event) => updateOtherDraftField(key, 'otherBody', event.target.value)}
																rows={3}
																placeholder="Write your custom clause details here."
																className="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-xs text-slate-900 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
															/>
															<button
																type="button"
																onClick={() => addOtherClause(key)}
																className="w-fit rounded-md bg-slate-900 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-black"
															>
																Save
															</button>
														</div>
													)}
												</div>

												<div className="mt-3 flex flex-wrap items-center gap-2">
													<button
														type="button"
														onClick={() => applyComposerSelectionToEditor(key, 'replace')}
														disabled={selectedClauseCount === 0}
														className="rounded-md bg-blue-600 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-50"
													>
														Use Selected Clauses
													</button>
													<button
														type="button"
														onClick={() => resetComposerBuilder(key)}
														className="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 transition hover:bg-slate-100"
													>
														Reset Builder
													</button>
												</div>

												<div className="mt-3">
													<div className="mb-1.5 flex flex-wrap items-center justify-between gap-2">
														<label htmlFor={`policy-section-${key}`} className="block text-xs font-semibold uppercase tracking-wide text-slate-600">
															Final Section Text (Editable)
														</label>
														<div className="flex flex-wrap items-center gap-2">
															<span className="text-[11px] font-medium text-slate-500">{editorWordCount} words</span>
															<button
																type="button"
																onClick={() => clearPolicySectionEditor(key)}
																className="rounded-md border border-slate-300 bg-white px-2.5 py-1 text-[11px] font-semibold text-slate-700 transition hover:bg-slate-100"
															>
																Clear Text
															</button>
														</div>
													</div>
													<textarea
														id={`policy-section-${key}`}
														value={String(policySections[key] ?? '')}
														onChange={(event) => updatePolicySection(key, event.target.value)}
														rows={6}
														className="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
													/>
												</div>
												</div>
											)}
											</div>
										);
									})}
								</div>
							)}

							{policyError ? <p className="text-sm text-red-600">{policyError}</p> : null}
							{policySuccess ? <p className="text-sm font-medium text-green-700">{policySuccess}</p> : null}

							<div className="flex flex-wrap items-center gap-3 border-t border-gray-200 pt-2">
								<button
									type="button"
									onClick={() => {
										void savePolicyDraft();
									}}
									disabled={savingPolicyDraft || publishingPolicy}
									className="rounded-lg bg-gray-900 px-4 py-2 text-sm font-semibold text-white transition hover:bg-black disabled:cursor-not-allowed disabled:opacity-60"
								>
									{savingPolicyDraft ? 'Saving Draft...' : 'Save Draft'}
								</button>
								<button
									type="button"
									onClick={addCustomPolicySection}
									disabled={savingPolicyDraft || publishingPolicy}
									className="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-800 transition hover:bg-gray-100 disabled:cursor-not-allowed disabled:opacity-60"
								>
									Add Section
								</button>
								<button
									type="button"
									onClick={() => {
										void publishPolicyVersion();
									}}
									disabled={publishingPolicy || savingPolicyDraft}
									className="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-800 transition hover:bg-gray-100 disabled:cursor-not-allowed disabled:opacity-60"
								>
									{publishingPolicy ? 'Saving...' : 'Save'}
								</button>
							</div>
						</div>
					</div>

					{/* Repair Payment Policy */}
					{hasRepairSignal && (
						<div className={`rounded-2xl border border-gray-200 bg-white shadow-sm lg:order-3 ${showWideRepairPaymentPolicy ? 'lg:col-span-12' : 'lg:col-span-5'}`}>
							<div className="border-b border-gray-200 p-6">
								<h2 className="text-xl font-semibold text-gray-900">Repair Payment Policy</h2>
								<p className="mt-1 text-sm text-gray-600">
									Choose how customers pay for repair services. Applies to all new repair requests.
								</p>
							</div>
							<div className="space-y-6 p-6">
								<div className="space-y-3">
								{([
									{
										value: 'deposit_50' as const,
										label: '50% Deposit + 50% at Pickup',
										description: 'Customer pays half upfront to confirm the job, then the remaining half when collecting their shoes.',
									},
									{
										value: 'full_upfront' as const,
										label: 'Full Payment Upfront',
										description: 'Customer pays the full amount before the shoes are dropped off or collected.',
									},
								] as const).map((option) => (
									<label
										key={option.value}
										className={`flex cursor-pointer items-start gap-3 rounded-lg border-2 p-4 transition-colors ${
											repairPaymentPolicy === option.value
												? 'border-gray-900 bg-gray-50'
												: 'border-gray-200 hover:border-gray-400'
										}`}
									>
										<input
											type="radio"
											name="repair_payment_policy"
											value={option.value}
											checked={repairPaymentPolicy === option.value}
											disabled={processing}
											onChange={() => {
												setRepairPaymentPolicy(option.value);
												saveSettings(approvalPages, option.value);
											}}
											className="mt-0.5 accent-gray-900"
										/>
										<div>
											<p className="text-sm font-semibold text-gray-900">{option.label}</p>
											<p className="mt-0.5 text-xs text-gray-500">{option.description}</p>
										</div>
									</label>
								))}
								</div>

								<div className="border-t border-gray-200 pt-6">
									<h3 className="text-lg font-semibold text-gray-900">Repair Workload Limit</h3>
									<p className="mt-1 text-sm text-gray-600">
										Set the maximum number of active repair job orders that can be handled at one time.
									</p>
									<div className="mt-4 rounded-lg border border-blue-100 bg-blue-50/40 p-4">
										<p className="mb-3 text-sm text-gray-700">
											When the active repair count reaches this limit, new requests will be flagged. Enter a value between 1 and 500.
										</p>
										<div className="flex flex-col gap-2 sm:flex-row sm:items-center">
											<input
												type="number"
												min={1}
												max={500}
												step={1}
												value={limitInputValue}
												onChange={(e) => {
													setLimitInputValue(e.target.value);
													if (limitInputError) setLimitInputError(null);
												}}
												placeholder="20"
												className="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 sm:max-w-xs"
											/>
											<button
												type="button"
												onClick={handleSaveRepairRequestLimit}
												className="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-blue-700"
											>
												Save Limit
											</button>
										</div>
										{limitInputError && <p className="mt-2 text-xs text-red-600">{limitInputError}</p>}
										{limitSaveSuccess && (
											<p className="mt-2 flex items-center gap-1 text-xs font-medium text-green-700">
												<Check size={13} /> Workload limit saved.
											</p>
										)}
									</div>
									<p className="mt-3 text-xs text-gray-500">
										Current limit: <span className="font-semibold text-gray-700">{repairRequestLimit}</span> active repair orders.
									</p>
								</div>

							</div>
						</div>
					)}

					{(hasRetailSignal || hasRepairSignal) && (
						<div className="rounded-2xl border border-gray-200 bg-white shadow-sm lg:col-span-12 lg:order-3">
							<div className="border-b border-gray-200 p-6">
								<h2 className="text-xl font-semibold text-gray-900">Refund Deadline</h2>
								<p className="mt-1 text-sm text-gray-600">
									Set how many days customers can request a refund after transaction creation.
								</p>
							</div>
							<div className="p-6">
								<div className="rounded-lg border border-blue-100 bg-blue-50/40 p-4">
									<p className="mb-3 text-sm text-gray-700">
										Allowed range is 1 to 30 days. This applies to new refund requests for your shop.
									</p>
									<div className="flex flex-col gap-2 sm:flex-row sm:items-center">
										<label className="sr-only" htmlFor="order-refund-deadline-days">Refund deadline in days</label>
										<div className="flex w-full items-center rounded-lg border border-gray-300 bg-white px-3 sm:max-w-xs">
											<input
												id="order-refund-deadline-days"
												type="number"
												min={1}
												max={30}
												step={1}
												title="Refund deadline in days"
												placeholder="7"
												value={refundDeadlineInputValue}
												onChange={(e) => {
													setRefundDeadlineInputValue(e.target.value);
													if (refundDeadlineInputError) setRefundDeadlineInputError(null);
												}}
												className="w-full border-0 px-1 py-2 text-sm text-gray-900 focus:outline-none focus:ring-0"
											/>
											<span className="text-sm font-medium text-gray-500">days</span>
										</div>
										<button
											type="button"
											onClick={handleSaveOrderRefundDeadlineDays}
											className="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-blue-700"
										>
											Save Refund Deadline
										</button>
									</div>
									{refundDeadlineInputError && <p className="mt-2 text-xs text-red-600">{refundDeadlineInputError}</p>}
									{refundDeadlineSaveSuccess && (
										<p className="mt-2 flex items-center gap-1 text-xs font-medium text-green-700">
											<Check size={13} /> Refund deadline saved.
										</p>
									)}
								</div>
								<p className="mt-3 text-xs text-gray-500">
									Current refund deadline: <span className="font-semibold text-gray-700">{orderRefundDeadlineDays}</span> day(s).
								</p>
							</div>
						</div>
					)}

					{/* PayMongo Payment Gateway Key */}
					{!isIndividual && (
						<div className="rounded-2xl border border-gray-200 bg-white shadow-sm lg:col-span-12 lg:order-4">
							<div className="border-b border-gray-200 p-6">
								<h2 className="text-xl font-semibold text-gray-900">
									{payCycle === 'monthly' ? 'Payroll Cycle' : 'Payroll Cutoff'}
								</h2>
								<p className="mt-1 text-sm text-gray-600">
									Choose monthly payroll or semi-monthly cutoff.
								</p>
							</div>
							<div className={`grid grid-cols-1 gap-4 p-6 ${payCycle === 'semi_monthly' ? 'md:grid-cols-3' : 'md:grid-cols-1'}`}>
								<div>
									<label className="mb-1.5 block text-sm font-medium text-gray-700">Pay Cycle</label>
									<select
										value={payCycle}
										onChange={(e) => {
											setPayCycle(e.target.value as 'monthly' | 'semi_monthly');
											if (payrollCutoffError) setPayrollCutoffError(null);
										}}
										title="Payroll pay cycle"
										className="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
									>
										<option value="monthly">Monthly</option>
										<option value="semi_monthly">Semi-monthly</option>
									</select>
								</div>
								{payCycle === 'semi_monthly' && (
									<>
										<div>
											<label className="mb-1.5 block text-sm font-medium text-gray-700">First Payout Day</label>
											<button
												type="button"
												onClick={() => openPayoutDayPicker('first')}
												className="flex w-full items-center justify-between rounded-lg border border-gray-300 bg-white px-3 py-2 text-left text-sm text-gray-900 transition hover:border-blue-400 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
												title="Open first payroll payout day picker"
											>
												<span className="inline-flex items-center gap-2">
													<CalendarDays size={15} className="text-gray-400" />
													{formatOrdinalDay(payDayFirst)}
												</span>
												<ChevronDown size={16} className="text-gray-500" />
											</button>
											<p className="mt-1.5 text-xs text-gray-500">Tap to browse days in a focused modal picker.</p>
										</div>
										<div>
											<label className="mb-1.5 block text-sm font-medium text-gray-700">Second Payout Day</label>
											<button
												type="button"
												onClick={() => openPayoutDayPicker('second')}
												className="flex w-full items-center justify-between rounded-lg border border-gray-300 bg-white px-3 py-2 text-left text-sm text-gray-900 transition hover:border-blue-400 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
												title="Open second payroll payout day picker"
											>
												<span className="inline-flex items-center gap-2">
													<CalendarDays size={15} className="text-gray-400" />
													{formatOrdinalDay(payDaySecond)}
												</span>
												<ChevronDown size={16} className="text-gray-500" />
											</button>
											<p className="mt-1.5 text-xs text-gray-500">Only days after the first payout are available.</p>
										</div>
									</>
								)}
							</div>
							<div className="border-t border-gray-200 px-6 py-4">
								<p className="mb-3 text-xs text-gray-500">
									{payCycle === 'semi_monthly'
										? <>
												Example setup: first payout day <span className="font-semibold">15th of the month</span>, second payout day <span className="font-semibold">30th of the month</span>.
										</>
										: 'Monthly cycle uses one payroll period per month.'}
								</p>
								{payrollCutoffError && <p className="mb-2 text-xs text-red-600">{payrollCutoffError}</p>}
								{payrollCutoffSuccess && (
									<p className="mb-2 flex items-center gap-1 text-xs font-medium text-green-700">
										<Check size={13} /> Payroll settings saved.
									</p>
								)}
								<button
									type="button"
									onClick={handleSavePayrollCutoff}
									disabled={savingPayrollCutoff}
									className="rounded-lg bg-gray-900 px-4 py-2 text-sm font-semibold text-white transition hover:bg-black disabled:cursor-not-allowed disabled:opacity-60"
								>
									{savingPayrollCutoff ? 'Saving…' : 'Save Payroll Settings'}
								</button>
							</div>
						</div>
					)}

					{/* PayMongo Payment Gateway Key */}
					<div className="rounded-2xl border border-gray-200 bg-white shadow-sm lg:col-span-12 lg:order-5">
						<div className="border-b border-gray-200 p-6">
							<h2 className="text-xl font-semibold text-gray-900">Payment Gateway (PayMongo)</h2>
							<p className="mt-1 text-sm text-gray-600">
								Enter your PayMongo secret key so customer payments go directly into your account.
							</p>
						</div>
						<div className="p-6 space-y-4">
							<div className="rounded-lg border border-gray-200 bg-white p-4">
								<p className="text-sm font-semibold text-gray-900">What is PayMongo?</p>
								<p className="mt-1 text-sm text-gray-700">
									PayMongo is an online payment platform that lets your shop accept digital payments securely.
								</p>
								<p className="mt-3 text-xs font-semibold uppercase tracking-wide text-gray-900">How to get your key</p>
								<ol className="mt-1 list-decimal space-y-1 pl-5 text-sm text-gray-700">
									<li>Create or log in to your PayMongo account.</li>
									<li>Open Dashboard - Developers - API Keys.</li>
									<li>Copy your Secret Key (starts with <code className="rounded bg-gray-100 px-1 py-0.5 font-mono text-xs">sk_</code>).</li>
									<li>Paste it below, then click Save Key.</li>
								</ol>
								<a
									href="https://www.paymongo.com/"
									target="_blank"
									rel="noopener noreferrer"
									className="mt-3 inline-flex text-sm font-semibold text-blue-600 underline underline-offset-2 hover:text-blue-800"
								>
									Visit PayMongo
								</a>
							</div>

							{/* Warning banner if no key configured */}
							{!hasPaymongoKey && (
								<div className="flex items-start gap-3 rounded-lg border border-amber-200 bg-amber-50 p-4">
									<AlertTriangle size={18} className="mt-0.5 shrink-0 text-amber-600" />
									<div>
										<p className="text-sm font-semibold text-amber-800">Payment not configured</p>
										<p className="mt-0.5 text-sm text-amber-700">
											You haven&apos;t added your PayMongo secret key yet. Customers will not be able to pay online
											until you set this up. Add your key below to start accepting payments directly into your account.
										</p>
									</div>
								</div>
							)}

							{/* Configured confirmation */}
							{hasPaymongoKey && (
							<div className="rounded-lg border border-green-200 bg-green-50 px-4 py-3">
								<div className="flex items-center justify-between gap-3">
									<div className="flex items-center gap-2">
										<CheckCircle2 size={16} className="shrink-0 text-green-600" />
										<span className="text-sm font-medium text-green-800">PayMongo key is configured — payments go directly to your account.</span>
									</div>
									{!showRevokeConfirm && (
										<button
											type="button"
											onClick={() => setShowRevokeConfirm(true)}
											className="inline-flex shrink-0 items-center justify-center text-red-600 hover:text-red-700"
											aria-label="Remove key"
											title="Remove key"
										>
											<Trash2 size={14} />
										</button>
									)}
								</div>
								{showRevokeConfirm && (
									<div className="mt-3 flex items-center gap-3 border-t border-green-200 pt-3">
										<AlertTriangle size={15} className="shrink-0 text-red-500" />
										<p className="flex-1 text-xs text-red-700">Removing this key will <strong>disable all online payments</strong> for your shop immediately. Are you sure?</p>
										<button
											type="button"
											onClick={removePaymongoKey}
											disabled={removingKey}
											className="shrink-0 rounded-md bg-red-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-red-700 disabled:opacity-50"
										>
											{removingKey ? 'Removing…' : 'Yes, remove'}
										</button>
										<button
											type="button"
											onClick={() => setShowRevokeConfirm(false)}
											className="shrink-0 rounded-md border border-gray-300 bg-white px-3 py-1.5 text-xs font-semibold text-gray-700 hover:bg-gray-50"
										>
											Cancel
										</button>
									</div>
								)}
							</div>
						)}

						{/* Key input */}
							<div>
								<label className="mb-1.5 block text-sm font-medium text-gray-700">
									{hasPaymongoKey ? 'Update Secret Key' : 'Enter Secret Key'}
								</label>
								<p className="mb-2 text-xs text-gray-500">
									Found in your PayMongo Dashboard → Developers → API Keys. Use the <strong>Secret key</strong> (starts with <code className="rounded bg-gray-100 px-1 py-0.5 font-mono text-xs">sk_</code>).
								</p>
								<div className="flex items-center gap-2">
									<div className="relative flex-1">
										<input
											type={showKey ? 'text' : 'password'}
											placeholder="sk_live_xxxxxxxxxxxxxxxxxxxx"
											value={keyInput}
											onChange={(e) => setKeyInput(e.target.value)}
											className="w-full rounded-lg border border-gray-300 bg-white px-3 py-2.5 pr-10 font-mono text-sm text-gray-900 focus:border-gray-500 focus:outline-none focus:ring-1 focus:ring-gray-500"
										/>
										<button
											type="button"
											onClick={() => setShowKey((v) => !v)}
											className="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600"
											aria-label={showKey ? 'Hide key' : 'Show key'}
										>
											{showKey ? <EyeOff size={16} /> : <Eye size={16} />}
										</button>
									</div>
									<button
										type="button"
										onClick={savePaymongoKey}
										disabled={savingKey || !keyInput.trim()}
										className="shrink-0 rounded-lg bg-gray-900 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-black disabled:cursor-not-allowed disabled:opacity-50"
									>
										{savingKey ? 'Saving…' : 'Save Key'}
									</button>
								</div>
								{keyError && <p className="mt-2 text-xs text-red-600">{keyError}</p>}
								{keySuccess && (
									<p className="mt-2 flex items-center gap-1 text-xs font-medium text-green-700">
										<Check size={13} /> Key saved successfully.
									</p>
								)}
							</div>
						</div>
					</div>

					{/* Shop Location / Attendance Geofence */}
					<div className="rounded-2xl border border-gray-200 bg-white shadow-sm lg:col-span-12 lg:order-6">
						<div className="border-b border-gray-200 p-6">
							<div className="flex items-start justify-between gap-4">
								<div>
									<div className="flex items-center gap-2 mb-1">
										<MapPin size={18} className="text-blue-600" />
										<h2 className="text-xl font-semibold text-gray-900">
											{isIndividual ? 'Shop Location' : 'Attendance Geofence'}
										</h2>
									</div>
									<p className="text-sm text-gray-600">
										{isIndividual
											? 'Set your shop\'s location so customers can discover your shop when browsing nearby listings.'
											: 'When enabled, employees can only clock in when they are within the allowed distance from your shop.'}
									</p>
								</div>
								{!isIndividual && (
									<ToggleSwitch
										enabled={geofenceEnabled}
										onChange={setGeofenceEnabled}
										ariaLabel={geofenceEnabled ? 'Disable geofence' : 'Enable geofence'}
									/>
								)}
							</div>
						</div>

						<div className="p-6 space-y-5">
							{/* Address search */}
							<div>
								<label className="mb-1.5 block text-sm font-medium text-gray-700">Search Address</label>
								<div className="flex gap-2">
									<input
										type="text"
										value={addressSearch}
										onChange={(e) => setAddressSearch(e.target.value)}
										onKeyDown={(e) => e.key === 'Enter' && handleAddressSearch()}
										placeholder="e.g. 123 Rizal St, Makati"
										className="flex-1 rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-900 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
									/>
									<button
										type="button"
										onClick={handleAddressSearch}
										disabled={searchingAddress}
										className="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700 disabled:opacity-50"
									>
										{searchingAddress ? 'Searching…' : 'Search'}
									</button>
									<button
										type="button"
										onClick={handleUseMyGPS}
										disabled={gettingGPS}
										className="flex items-center gap-1.5 rounded-lg border border-blue-600 px-3 py-2 text-sm font-semibold text-blue-600 hover:bg-blue-50 disabled:opacity-50"
										title="Set coordinates from your current GPS location"
									>
										<MapPin size={14} />
										{gettingGPS ? 'Getting GPS…' : 'Use My GPS'}
									</button>
								</div>
								{/* GPS detected address confirmation */}
								{geoAddress && geoLat && (
									<p className="mt-2 flex items-start gap-1.5 rounded-lg border border-green-200 bg-green-50 px-3 py-2 text-xs text-green-800">
										<MapPin size={12} className="mt-0.5 shrink-0 text-green-600" />
										<span><span className="font-semibold">Detected address:</span> {geoAddress}. If this is wrong, drag the pin or search for the correct address.</span>
									</p>
								)}
								{/* Address search results */}
								{addressResults.length > 0 && (
									<div className="mt-1 rounded-lg border border-gray-200 bg-white shadow-lg overflow-hidden">
										{addressResults.map((r, i) => (
											<button
												key={i}
												type="button"
												onClick={() => handleSelectAddress(r)}
												className="w-full px-4 py-2.5 text-left text-sm text-gray-800 hover:bg-blue-50 border-b border-gray-100 last:border-b-0"
											>
												{r.display_name}
											</button>
										))}
									</div>
								)}
							</div>

							{/* Leaflet map */}
							<div>
								<p className="mb-1.5 text-sm font-medium text-gray-700">Shop Location <span className="font-normal text-gray-400">(drag pin or click map to adjust)</span></p>
								<div ref={mapRef} className="h-72 w-full rounded-xl border border-gray-200 overflow-hidden z-0" />
							</div>

							{/* Radius slider — company only */}
							{!isIndividual && (
							<div>
								<div className="flex items-center justify-between mb-2">
									<label className="text-sm font-medium text-gray-700">Allowed Radius</label>
									<span className="text-sm font-semibold text-blue-600">{geoRadius} m</span>
								</div>
								<input
									type="range"
									min={10}
									max={500}
									step={10}
									value={geoRadius}
									onChange={(e) => setGeoRadius(Number(e.target.value))}
									aria-label="Allowed geofence radius in meters"
									title="Allowed geofence radius in meters"
									className="w-full accent-blue-600"
								/>
								<div className="flex justify-between text-xs text-gray-400 mt-1">
									<span>10 m</span><span>50 m</span><span>100 m</span><span>200 m</span><span>500 m</span>
								</div>
								<p className="mt-2 text-xs text-gray-500">
									Employees must be within this distance from the pin to clock in. The blue circle on the map shows the boundary.
								</p>
							</div>
							)}

							{geoError && (
								<div className="flex items-center gap-2 rounded-lg border border-red-200 bg-red-50 p-3">
									<AlertTriangle size={14} className="text-red-500 shrink-0" />
									<p className="text-xs text-red-700">{geoError}</p>
								</div>
							)}
							{geoSuccess && (
								<p className="flex items-center gap-1 text-xs font-medium text-green-700">
									<Check size={13} /> {isIndividual ? 'Location saved.' : 'Geofence settings saved.'}
								</p>
							)}

							<button
								type="button"
								onClick={saveGeofence}
								disabled={savingGeo}
								className="rounded-lg bg-gray-900 px-5 py-2.5 text-sm font-semibold text-white hover:bg-black disabled:opacity-50"
							>
								{savingGeo ? 'Saving…' : isIndividual ? 'Save Location' : 'Save Geofence Settings'}
							</button>
						</div>
					</div>

					{!isIndividual && (
					<div className={`rounded-2xl border border-gray-200 bg-white shadow-sm lg:order-2 ${showWideApprovalLimits ? 'lg:col-span-12' : 'lg:col-span-7'}`}>
						<div className="border-b border-gray-200 p-6">
							<h2 className="text-xl font-semibold text-gray-900">Approval Limits</h2>
							<p className="mt-1 text-sm text-gray-600">Enable approvals per workflow and define the minimum amount that requires owner action.</p>
						</div>

						<div className="divide-y divide-gray-100 p-6">
							{APPROVAL_ITEMS.map((item) => {
								const itemData = approvalPages[item.key];
								const errorKey = `approval_pages.${item.key}.limit`;
								const isPriceApproval = item.key === 'price_approval';

								return (
									<div key={item.key} className="py-4 first:pt-0 last:pb-0">
										<div className="flex items-start justify-between gap-4">
											<div>
												<h3 className="text-base font-semibold text-gray-900">{item.title}</h3>
												<p className="mt-1 text-sm text-gray-600">{item.description}</p>
											</div>
											<ToggleSwitch
												enabled={itemData.enabled}
												disabled={processing}
												onChange={(enabled) => handleToggle(item.key, enabled)}
												ariaLabel={`${itemData.enabled ? 'Disable' : 'Enable'} ${item.title}`}
											/>
										</div>

										{itemData.enabled && !isPriceApproval && (
											<div className="mt-4 rounded-lg border border-blue-100 bg-blue-50/40 p-4">
												<p className="mb-3 text-sm text-gray-700">{item.helper}</p>
												{!isPriceApproval && (
													<>
														<div className="flex flex-col gap-2 sm:flex-row sm:items-center">
															<div className="flex w-full items-center rounded-lg border border-gray-300 bg-white px-3 sm:max-w-xs">
																<span className="text-sm font-medium text-gray-500">PHP</span>
																<input
																	type="number"
																	min={0}
																	step="0.01"
																	value={itemData.limit ?? ''}
																	onChange={(event) => handleLimitChange(item.key, event.target.value)}
																	aria-label={`${item.title} limit in PHP`}
																	title={`${item.title} limit in PHP`}
																	placeholder="5000.00"
																	className="w-full border-0 px-3 py-2 text-sm text-gray-900 focus:outline-none focus:ring-0"
																/>
															</div>
															<button
																type="button"
																onClick={() => handleSaveLimit(item.key)}
																disabled={processing}
																className="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-60"
															>
																{processing ? 'Saving...' : 'Save Limit'}
															</button>
														</div>
														{errors[errorKey] && <p className="mt-2 text-xs text-red-600">{errors[errorKey]}</p>}
													</>
												)}
											</div>
										)}
									</div>
								);
							})}
						</div>
					</div>
					)}
				</div>
				</div>

				{activePayoutPicker && (
					<div className="fixed inset-0 z-50 flex items-end justify-center bg-gray-900/50 p-4 sm:items-center">
						<button
							type="button"
							onClick={closePayoutDayPicker}
							className="absolute inset-0"
							aria-label="Close payout day picker"
						/>
						<div
							role="dialog"
							aria-modal="true"
							aria-label={payoutPickerTitle}
							className="relative w-full max-w-xl rounded-2xl border border-gray-200 bg-white p-5 shadow-xl"
						>
							<div className="mb-4 flex items-start justify-between gap-3">
								<div>
									<p className="text-base font-semibold text-gray-900">{payoutPickerTitle}</p>
									<p className="mt-1 text-sm text-gray-600">{payoutPickerHint}</p>
								</div>
								<button
									type="button"
									onClick={closePayoutDayPicker}
									className="rounded-md border border-gray-300 bg-white px-2.5 py-1 text-xs font-semibold text-gray-600 transition hover:bg-gray-50"
								>
									Close
								</button>
							</div>

							<div className="grid max-h-80 grid-cols-2 gap-2 overflow-y-auto pr-1 sm:grid-cols-3">
								{payoutPickerOptions.map((day) => {
									const isActive = payoutPickerSelectedDay === day;

									return (
										<button
											key={`payout-day-${activePayoutPicker}-${day}`}
											type="button"
											onClick={() => handleSelectPayoutDay(day)}
											className={`rounded-xl border px-3 py-2 text-left text-sm transition ${
												isActive
													? 'border-blue-600 bg-blue-50 text-blue-700'
													: 'border-gray-200 bg-white text-gray-700 hover:border-blue-300 hover:bg-blue-50/40'
											}`}
										>
											<div className="flex items-center justify-between gap-2">
												<span>{formatOrdinalDay(day)}</span>
												{isActive && <Check size={14} />}
											</div>
										</button>
									);
								})}
							</div>
						</div>
					</div>
				)}
			</div>
		</>
	);
};

export default ShopSetting;
