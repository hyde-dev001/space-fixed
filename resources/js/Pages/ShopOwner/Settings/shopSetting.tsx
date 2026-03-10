import React, { useEffect, useRef, useState } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import axios from 'axios';
import 'leaflet/dist/leaflet.css';
import { AlertTriangle, Building2, Check, CheckCircle2, Eye, EyeOff, FileText, MapPin, Settings, Store, Trash2, User, Wrench } from 'lucide-react';

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
	required_documents: Array<{
		key: string;
		title: string;
		description: string;
		status: string;
		is_uploaded: boolean;
		is_image: boolean;
		file_url: string | null;
	}>;
	repair_payment_policy: 'deposit_50' | 'full_upfront' | 'pay_after';
	repair_workload_limit: number;
	has_paymongo_key: boolean;
	// Geofence
	attendance_geofence_enabled: boolean;
	shop_latitude: number | null;
	shop_longitude: number | null;
	shop_address: string | null;
	shop_geofence_radius: number;
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
		description: 'Require approval for staff-initiated price changes above your limit.',
		helper: 'Set the amount where pricing changes move into your approval queue.',
	},
	{
		key: 'purchase_request_approval',
		title: 'Purchase Request Approval',
		description: 'Require approval for purchase requests that exceed your threshold.',
		helper: 'High-value procurement requests will require owner approval when enabled.',
	},
	{
		key: 'repair_reject_approval',
		title: 'Repair Reject Approval',
		description: 'Require approval before high-value repair rejections are finalized.',
		helper: 'Use this to review expensive rejected repairs before they are closed.',
	},
];

const REPAIR_REQUEST_LIMIT_KEY = 'repair_request_limit';
const DEFAULT_REPAIR_REQUEST_LIMIT = 20;

const readRepairRequestLimit = (): number => {
	if (typeof window === 'undefined') return DEFAULT_REPAIR_REQUEST_LIMIT;
	const raw = window.localStorage.getItem(REPAIR_REQUEST_LIMIT_KEY);
	const parsed = Number(raw);
	if (!Number.isFinite(parsed) || parsed < 1) return DEFAULT_REPAIR_REQUEST_LIMIT;
	return Math.floor(parsed);
};

const ShopSetting: React.FC = () => {
	const { shop_settings } = usePage<ShopSettingsPageProps>().props;
	const isIndividual = shop_settings.registration_type === 'individual';
	const LAST_SHOP_OWNER_PAGE_KEY = 'shop_owner_last_sidebar_page';
	const [saveSuccess, setSaveSuccess] = useState(false);
	const [processing, setProcessing] = useState(false);
	const [errors, setErrors] = useState<Record<string, string>>({});
	const [approvalPages, setApprovalPages] = useState<ApprovalPages>(shop_settings.approval_pages);
	const [repairPaymentPolicy, setRepairPaymentPolicy] = useState<'deposit_50' | 'full_upfront' | 'pay_after'>(
		shop_settings.repair_payment_policy ?? 'deposit_50',
	);

	// PayMongo key state
	const [hasPaymongoKey, setHasPaymongoKey] = useState(shop_settings.has_paymongo_key ?? false);
	const [keyInput, setKeyInput] = useState('');
	const [showKey, setShowKey] = useState(false);
	const [savingKey, setSavingKey] = useState(false);
	const [keySuccess, setKeySuccess] = useState(false);
	const [keyError, setKeyError] = useState<string | null>(null);
	const [removingKey, setRemovingKey] = useState(false);
	const [showRevokeConfirm, setShowRevokeConfirm] = useState(false);

	// Repair workload limit state — server prop is source of truth, localStorage is a cache
	const serverLimit = shop_settings.repair_workload_limit ?? 20;
	const [repairRequestLimit, setRepairRequestLimit] = useState<number>(serverLimit);
	const [limitInputValue, setLimitInputValue] = useState<string>(String(serverLimit));
	const [limitInputError, setLimitInputError] = useState<string | null>(null);
	const [limitSaveSuccess, setLimitSaveSuccess] = useState(false);

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

	const accountLabel = isIndividual ? 'Individual Account' : 'Business Account';
	const businessTypeLabel =
		shop_settings.business_type === 'retail'
			? 'Retail Shop'
			: shop_settings.business_type === 'repair'
				? 'Repair Services'
				: 'Retail & Repair';

	const accountFeatures: Array<{ label: string; enabled: boolean }> = [
		{ label: 'Staff Management', enabled: shop_settings.can_manage_staff },
		{ label: 'Shop Profile Management', enabled: true },
		{ label: 'Shop Notification Settings', enabled: true },
		{ label: 'Approval Limit Controls', enabled: true },
		{ label: 'Audit Logs Access', enabled: true },
		{ label: 'Retail Order Workflows', enabled: shop_settings.business_type === 'retail' || shop_settings.business_type === 'both' },
		{ label: 'Repair Service Workflows', enabled: shop_settings.business_type === 'repair' || shop_settings.business_type === 'both' },
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

			marker.on('dragend', () => {
				const pos = marker.getLatLng();
				setGeoLat(pos.lat.toFixed(8));
				setGeoLng(pos.lng.toFixed(8));
				circle.setLatLng(pos);
			});

			map.on('click', (e: any) => {
				marker.setLatLng(e.latlng);
				circle.setLatLng(e.latlng);
				setGeoLat(e.latlng.lat.toFixed(8));
				setGeoLng(e.latlng.lng.toFixed(8));
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
				const lat = pos.coords.latitude.toFixed(8);
				const lng = pos.coords.longitude.toFixed(8);
				setGeoLat(lat);
				setGeoLng(lng);

				// Reverse geocode so the user can see & verify the detected address
				try {
					const res = await fetch(
						`https://nominatim.openstreetmap.org/reverse?lat=${lat}&lon=${lng}&format=json`,
						{ headers: { 'User-Agent': 'SoleSpace/1.0' } },
					);
					const data = await res.json();
					if (data.display_name) {
						setGeoAddress(data.display_name);
						setAddressSearch(data.display_name);
					}
				} catch {
					// reverse geocode failed — coords still set, address just stays blank
				}

				setGettingGPS(false);
			},
			() => {
				setGeoError('Could not get your location. Please allow location access.');
				setGettingGPS(false);
			},
			{ enableHighAccuracy: true },
		);
	};

	const handleAddressSearch = async () => {
		if (!addressSearch.trim()) return;
		setSearchingAddress(true);
		setAddressResults([]);
		try {
			const res = await fetch(
				`https://nominatim.openstreetmap.org/search?q=${encodeURIComponent(addressSearch)}&format=json&limit=5&countrycodes=ph`,
				{ headers: { 'User-Agent': 'SoleSpace/1.0' } },
			);
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

	const saveGeofence = async () => {
		setSavingGeo(true);
		setGeoError(null);
		setGeoSuccess(false);
		try {
			const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
			await axios.post(
				'/shop-owner/settings/geofence',
				{
					attendance_geofence_enabled: isIndividual ? false : geofenceEnabled,
					shop_latitude: geoLat ? parseFloat(geoLat) : null,
					shop_longitude: geoLng ? parseFloat(geoLng) : null,
					shop_address: geoAddress || null,
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

	const saveSettings = (nextApprovalPages: ApprovalPages, nextPolicy?: 'deposit_50' | 'full_upfront' | 'pay_after') => {
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
								<div className="mb-1 flex flex-wrap items-center gap-2">
									<h3 className="text-lg font-semibold text-gray-900">{accountLabel}</h3>
									<span className="inline-flex items-center gap-1 rounded-full border border-gray-300 bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-700">
										{shop_settings.business_type === 'repair' ? <Wrench size={12} /> : <Store size={12} />}
										{businessTypeLabel}
									</span>
									<span className="inline-flex items-center rounded-full border border-gray-300 bg-gray-100 px-2 py-0.5 text-[11px] font-semibold text-gray-700">
										Premium Active
									</span>
								</div>
								<p className="truncate text-sm text-gray-700">{shop_settings.business_name || 'Business'}</p>

								<div className="mt-3 grid grid-cols-1 gap-2 sm:grid-cols-2">
									{accountFeatures.map((feature) => (
										<div key={feature.label} className="flex items-center gap-2 rounded-lg border border-gray-300 bg-gray-50 px-2.5 py-2 text-sm text-gray-800">
											<span className={`inline-block h-2.5 w-2.5 rounded-full ${feature.enabled ? 'bg-gray-900' : 'bg-gray-400'}`} />
											<span>{feature.label}</span>
										</div>
									))}
								</div>
								<div className="mt-4 border-t border-gray-200 pt-4">
									<p className="mb-3 text-center text-sm text-gray-600">
										Unlock premium benefits: virtual showroom access, more display slots, horizontal product viewing, and image-sequence uploads.
									</p>
									<button
										type="button"
										onClick={() => router.get('/shop-owner/premium-benefits')}
										className="inline-flex w-full items-center justify-center rounded-xl border border-gray-900 bg-gray-900 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-black focus:outline-none focus:ring-2 focus:ring-gray-400 focus:ring-offset-2"
									>
										Upgrade Premium Benefits
									</button>
								</div>
							</div>
						</div>
					</div>

					<div className="rounded-2xl border border-gray-200 bg-white shadow-sm lg:col-span-12 lg:order-7">
						<div className="border-b border-gray-200 p-5">
							<h2 className="text-xl font-semibold text-gray-900">Required Business Documents</h2>
							<p className="mt-1 text-sm text-gray-600">
								Please provide these 4 documents for shop verification and compliance.
							</p>
						</div>

						<div className="grid grid-cols-1 gap-4 p-5 md:grid-cols-2">
							{shop_settings.required_documents.map((doc) => {
								const statusLabel =
									doc.status === 'approved'
										? 'Approved'
										: doc.status === 'pending'
											? 'Pending Review'
											: doc.status === 'rejected'
												? 'Rejected'
												: 'Not Uploaded';

								const statusClass =
									doc.status === 'approved'
										? 'border-green-200 bg-green-50 text-green-700'
										: doc.status === 'pending'
											? 'border-amber-200 bg-amber-50 text-amber-700'
											: doc.status === 'rejected'
												? 'border-red-200 bg-red-50 text-red-700'
												: 'border-gray-200 bg-gray-50 text-gray-600';

								return (
									<div key={doc.key} className="rounded-xl border border-gray-200 bg-gray-50/40 p-4">
										<div className="mb-3 flex items-start justify-between gap-2">
											<div className="pr-2">
												<h3 className="text-sm font-semibold text-gray-900">{doc.title}</h3>
												<p className="mt-1 text-xs text-gray-600">{doc.description}</p>
											</div>
											<span className={`inline-flex shrink-0 rounded-full border px-2 py-0.5 text-[11px] font-semibold ${statusClass}`}>
												{statusLabel}
											</span>
										</div>

										<div className="overflow-hidden rounded-lg border border-gray-200 bg-white">
											{doc.file_url && doc.is_image ? (
												<img src={doc.file_url} alt={doc.title} className="h-36 w-full object-cover" />
											) : (
												<div className="flex h-36 w-full flex-col items-center justify-center gap-2 bg-gray-100 text-gray-500">
													<FileText size={22} />
													<span className="text-xs font-medium">{doc.file_url ? 'Document Uploaded' : 'No Document Uploaded'}</span>
												</div>
											)}
										</div>

										{doc.file_url && (
											<a
												href={doc.file_url}
												target="_blank"
												rel="noreferrer"
												className="mt-3 inline-flex text-xs font-semibold text-gray-800 underline underline-offset-2 hover:text-black"
											>
												View uploaded file
											</a>
										)}
									</div>
								);
							})}
						</div>
					</div>

					{/* Repair Payment Policy */}
					{(shop_settings.business_type === 'repair' || shop_settings.business_type === 'both') && (
						<div className="rounded-2xl border border-gray-200 bg-white shadow-sm lg:col-span-5 lg:order-3">
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
									{
										value: 'pay_after' as const,
										label: 'Pay After Repair (COD / In-store)',
										description: 'No online payment before the repair. The customer pays in full when picking up their shoes.',
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

					{/* PayMongo Payment Gateway Key */}
					<div className="rounded-2xl border border-gray-200 bg-white shadow-sm lg:col-span-12 lg:order-4">
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

							{/* Coordinates */}
							<div className="grid grid-cols-2 gap-3">
								<div>
									<label className="mb-1 block text-xs font-medium text-gray-600">Latitude</label>
									<input
										type="number"
										step="0.00000001"
										value={geoLat}
										onChange={(e) => setGeoLat(e.target.value)}
										className="w-full rounded-lg border border-gray-300 px-3 py-2 font-mono text-sm focus:border-blue-500 focus:outline-none"
										placeholder="14.55470000"
									/>
								</div>
								<div>
									<label className="mb-1 block text-xs font-medium text-gray-600">Longitude</label>
									<input
										type="number"
										step="0.00000001"
										value={geoLng}
										onChange={(e) => setGeoLng(e.target.value)}
										className="w-full rounded-lg border border-gray-300 px-3 py-2 font-mono text-sm focus:border-blue-500 focus:outline-none"
										placeholder="120.98420000"
									/>
								</div>
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

					<div className="rounded-2xl border border-gray-200 bg-white shadow-sm lg:col-span-7 lg:order-2">
						<div className="border-b border-gray-200 p-6">
							<h2 className="text-xl font-semibold text-gray-900">Approval Limits</h2>
							<p className="mt-1 text-sm text-gray-600">Enable approvals per workflow and define the minimum amount that requires owner action.</p>
						</div>

						<div className="divide-y divide-gray-100 p-6">
							{APPROVAL_ITEMS.map((item) => {
								const itemData = approvalPages[item.key];
								const errorKey = `approval_pages.${item.key}.limit`;

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

										{itemData.enabled && (
											<div className="mt-4 rounded-lg border border-blue-100 bg-blue-50/40 p-4">
												<p className="mb-3 text-sm text-gray-700">{item.helper}</p>
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
											</div>
										)}
									</div>
								);
							})}
						</div>
					</div>
				</div>
				</div>
			</div>
		</>
	);
};

export default ShopSetting;
