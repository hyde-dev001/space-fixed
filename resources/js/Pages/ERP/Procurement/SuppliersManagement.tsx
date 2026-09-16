import { Head, usePage } from "@inertiajs/react";
import { useEffect, useMemo, useRef, useState } from "react";
import Swal from "sweetalert2";
import axios from "axios";
import AppLayoutERP from "../../../layout/AppLayout_ERP";
import IconButton from "../../../components/ui/icon-button/IconButton";
import { supplierApi, type Supplier } from "@/services/procurementApi";
import type {
	SupplierPaymentDestinationType,
	SupplierPaymentProfile,
	SupplierPayoutChannelOptions,
	SupplierPayoutCountryOption,
	SupplierRecipientType,
	UpsertSupplierPaymentProfilePayload,
} from "@/types/procurement";
import SupplierPaymentProfileFields, { type SupplierPaymentDestinationFormState } from "./components/SupplierPaymentProfileFields";
import { erpUrl } from "@/utils/erpCapabilities";
import { withSweetAlertSemantic } from "@/utils/semanticSweetAlert";

const PAYMENT_TERMS = ["Net 7", "Net 15", "Net 30", "Net 45", "Net 60"] as const;


const PencilIcon = ({ className }: { className?: string }) => (
	<svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
		<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" />
	</svg>
);

const ArchiveBoxIcon = ({ className }: { className?: string }) => (
	<svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
		<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M20 13V7a2 2 0 00-2-2h-3V3H9v2H6a2 2 0 00-2 2v6m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0H4m5 4h6" />
	</svg>
);

const RestoreIcon = ({ className }: { className?: string }) => (
	<svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
		<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 10h7V3" />
		<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 10a9 9 0 109 9" />
	</svg>
);

const ChevronLeftIcon = ({ className }: { className?: string }) => (
	<svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
		<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7" />
	</svg>
);

const ChevronRightIcon = ({ className }: { className?: string }) => (
	<svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
		<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" />
	</svg>
);

const paymentProfileStatusPresentation = (status?: string | null) => {
	if (status === "verified") return ["Payment Verified", "bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400"];
	if (status === "unverified") return ["Payment Unverified", "bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400"];
	if (status === "disabled") return ["Payment Disabled", "bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400"];
	return ["Payment Not Set", "bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400"];
};

interface FormState {
	recipient_type: SupplierRecipientType;
	business_name: string;
	given_name: string;
	surname: string;
	contact_person: string;
	email: string;
	phone: string;
	address: string;
	address_line_2: string;
	city: string;
	province_state: string;
	postal_code: string;
	country: string;
	payment_terms: string;
	lead_time_days: string;
	products_supplied: string;
	notes: string;
}

const initialFormState: FormState = {
	recipient_type: "business",
	business_name: "",
	given_name: "",
	surname: "",
	contact_person: "",
	email: "",
	phone: "",
	address: "",
	address_line_2: "",
	city: "",
	province_state: "",
	postal_code: "",
	country: "Philippines",
	payment_terms: "",
	lead_time_days: "",
	products_supplied: "",
	notes: "",
};

const initialPaymentProfileFormState: SupplierPaymentDestinationFormState = {
	destination_type: "bank_account",
	wallet_provider: "",
	bank_name: "",
	bank_code: "",
	account_name: "",
	account_number: "",
	account_identifier: "",
};

const DEFAULT_PAYOUT_CHANNEL_OPTIONS: SupplierPayoutChannelOptions = {
	countries: [{ code: "PH", name: "Philippines", currency: "PHP" }],
	banks: [],
	e_wallets: [],
};

const supplierDisplayName = (form: FormState): string => form.recipient_type === "business"
	? form.business_name.trim()
	: [form.given_name.trim(), form.surname.trim()].filter(Boolean).join(" ");

const splitIndividualName = (name: string): Pick<FormState, "given_name" | "surname"> => {
	const parts = name.trim().split(/\s+/).filter(Boolean);
	return { given_name: parts.shift() || "", surname: parts.join(" ") };
};

const displayRecipientCountry = (country?: string | null): string => {
	const value = country?.trim() || "Philippines";
	return value.toUpperCase() === "PH" ? "Philippines" : value;
};

const recipientCountryCode = (country: string, countries: SupplierPayoutCountryOption[]): string => {
	const value = country.trim();
	const match = countries.find((option) =>
		option.name.toLowerCase() === value.toLowerCase() || option.code.toUpperCase() === value.toUpperCase(),
	);
	return match?.code.toUpperCase() || "";
};

const buildPaymentProfilePayload = (
	form: FormState,
	destination: SupplierPaymentDestinationFormState,
	countries: SupplierPayoutCountryOption[],
): UpsertSupplierPaymentProfilePayload => ({
	recipient_type: form.recipient_type,
	...(form.recipient_type === "business" ? { business_name: form.business_name } : { given_name: form.given_name, surname: form.surname }),
	recipient_country: recipientCountryCode(form.country, countries),
	recipient_province_state: form.province_state,
	recipient_city: form.city,
	recipient_street_line_1: form.address,
	recipient_street_line_2: form.address_line_2 || undefined,
	recipient_postal_code: form.postal_code,
	destination_type: destination.destination_type,
	...(destination.destination_type === "e_wallet"
		? { wallet_provider: destination.wallet_provider, account_name: destination.account_name, account_identifier: destination.account_identifier || undefined }
		: { bank_name: destination.bank_name, bank_code: destination.bank_code, account_name: destination.account_name, account_number: destination.account_number || undefined }),
});

const hasPaymentDestinationInput = (destination: SupplierPaymentDestinationFormState): boolean => [
	destination.wallet_provider,
	destination.bank_name,
	destination.bank_code,
	destination.account_name,
	destination.account_number,
	destination.account_identifier,
].some((value) => value.trim() !== "");

const missingRecipientDetail = (form: FormState): string | null => {
	const identityMissing = form.recipient_type === "business"
		? !form.business_name.trim() ? "Business Name" : null
		: !form.given_name.trim() ? "Given Name" : !form.surname.trim() ? "Surname" : null;
	if (identityMissing) return identityMissing;
	if (!form.address.trim()) return "Street Address";
	if (!form.city.trim()) return "City / Municipality";
	if (!form.province_state.trim()) return "Province / State";
	if (!form.postal_code.trim()) return "Postal Code";
	if (!form.country.trim()) return "Country";
	return null;
};

interface SupplierFormFieldsProps {
	formData: FormState;
	onChange: (event: React.ChangeEvent<HTMLInputElement | HTMLSelectElement>) => void;
	idPrefix: string;
	countries: SupplierPayoutCountryOption[];
}

const supplierFieldClass = "w-full rounded-lg border border-gray-300 bg-white px-4 py-2 text-gray-900 dark:border-gray-600 dark:bg-gray-800 dark:text-white";
const supplierLabelClass = "mb-2 block text-sm font-medium text-gray-700 dark:text-gray-300";

const SupplierFormFields = ({ formData, onChange, idPrefix, countries }: SupplierFormFieldsProps) => {
	const countryIsAvailable = countries.some((country) => country.name === formData.country);
	const fieldId = (name: string) => `${idPrefix}-supplier-${name}`;
	const input = (name: keyof FormState, label: string, required = false, type = "text") => (
		<div>
			<label htmlFor={fieldId(name)} className={supplierLabelClass}>{label}{required ? " *" : ""}</label>
			<input id={fieldId(name)} aria-label={label} type={type} name={name} value={formData[name]} onChange={onChange} required={required} className={supplierFieldClass} />
		</div>
	);

	return (
		<fieldset aria-labelledby={`${idPrefix}-supplier-details-title`} className="space-y-3">
			<legend id={`${idPrefix}-supplier-details-title`} className="text-sm font-semibold text-gray-900 dark:text-white">Supplier Details</legend>
			<p className="text-xs text-gray-500 dark:text-gray-400">Who is the supplier? These details are also used for the supplier payout recipient.</p>

			<div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
				<div>
					<label htmlFor={fieldId("recipient_type")} className={supplierLabelClass}>Recipient Type</label>
					<select id={fieldId("recipient_type")} aria-label="Recipient Type" name="recipient_type" value={formData.recipient_type} onChange={onChange} className={supplierFieldClass}>
						<option value="business">Business</option>
						<option value="individual">Individual</option>
					</select>
				</div>
				{formData.recipient_type === "business" ? (
					input("business_name", "Business Name", true)
				) : (
					<>
						{input("given_name", "Given Name", true)}
						{input("surname", "Surname", true)}
					</>
				)}
			</div>

			<div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
				<div className="sm:col-span-2">{input("address", "Street Address", true)}</div>
				<div className="sm:col-span-2">{input("address_line_2", "Address Line 2")}</div>
				{input("city", "City / Municipality", true)}
				{input("province_state", "Province / State", true)}
				{input("postal_code", "Postal Code", true)}
				<div>
					<label htmlFor={fieldId("country")} className={supplierLabelClass}>Country *</label>
					<select id={fieldId("country")} aria-label="Country" name="country" value={formData.country} onChange={onChange} required className={supplierFieldClass}>
						<option value="">Select country</option>
						{countries.map((country) => (
							<option key={country.code} value={country.name}>{country.name} ({country.code} · {country.currency})</option>
						))}
						{formData.country && !countryIsAvailable && <option value={formData.country}>{formData.country} (saved; verify)</option>}
					</select>
				</div>
			</div>

			<div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
				<div>
					<label htmlFor={fieldId("payment-terms")} className={supplierLabelClass}>Payment Terms</label>
					<select id={fieldId("payment-terms")} name="payment_terms" value={formData.payment_terms} onChange={onChange} className={supplierFieldClass}>
						<option value="">Use procurement default</option>
						{PAYMENT_TERMS.map((terms) => <option key={terms} value={terms}>{terms}</option>)}
					</select>
				</div>
				{input("lead_time_days", "Lead Time (days)", false, "number")}
			</div>

			<div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
				<div className="sm:col-span-2">
					<label htmlFor={fieldId("products-supplied")} className={supplierLabelClass}>Products Supplied</label>
					<input id={fieldId("products-supplied")} type="text" name="products_supplied" value={formData.products_supplied} onChange={onChange} placeholder="e.g., running shoes, laces" className={supplierFieldClass} />
				</div>
				{input("contact_person", "Contact Person")}
				{input("email", "Email", false, "email")}
				{input("phone", "Phone", false, "tel")}
				{input("notes", "Notes")}
			</div>
		</fieldset>
	);
};

export default function SuppliersManagement() {
	const { initialData, auth, erpCapabilities } = usePage().props as any;
	const ownerMode = auth?.erpActor?.ownerMode === true;
	const [suppliers, setSuppliers] = useState<Supplier[]>(initialData?.data ?? []);
	const [loading, setLoading] = useState(false);
	const [showArchived, setShowArchived] = useState(false);
	const [searchQuery, setSearchQuery] = useState("");
	const [currentPage, setCurrentPage] = useState(1);
	const [isModalOpen, setIsModalOpen] = useState(false);
	const [formData, setFormData] = useState<FormState>(initialFormState);
	const [viewingSupplier, setViewingSupplier] = useState<Supplier | null>(null);
	const [editingSupplier, setEditingSupplier] = useState<Supplier | null>(null);
	const [paymentProfile, setPaymentProfile] = useState<SupplierPaymentProfile | null>(null);
	const [paymentProfileForm, setPaymentProfileForm] = useState<SupplierPaymentDestinationFormState>(initialPaymentProfileFormState);
	const [paymentProfileLoading, setPaymentProfileLoading] = useState(false);
	const [showPaymentAccount, setShowPaymentAccount] = useState(false);
	const [payoutOptions, setPayoutOptions] = useState<SupplierPayoutChannelOptions>(DEFAULT_PAYOUT_CHANNEL_OPTIONS);
	const [payoutOptionsLoading, setPayoutOptionsLoading] = useState(false);
	const [payoutOptionsError, setPayoutOptionsError] = useState<string | null>(null);
	const payoutOptionsRequested = useRef(false);

	const getApiErrorMessage = (error: unknown, fallback: string) => {
		if (!axios.isAxiosError(error)) return fallback;

		const responseData = error.response?.data as {
			message?: string;
			errors?: Record<string, string[]>;
		} | undefined;
		const status = error.response?.status;
		if (!status || status >= 500) return fallback;

		const firstValidationError = responseData?.errors
			? Object.values(responseData.errors).flat()[0]
			: undefined;

		return firstValidationError || responseData?.message || fallback;
	};

	const loadPayoutOptions = async () => {
		if (payoutOptionsRequested.current) return;

		payoutOptionsRequested.current = true;
		setPayoutOptionsLoading(true);
		setPayoutOptionsError(null);
		try {
			const channelsUrl = erpUrl(erpCapabilities, "GET:procurement.suppliers.payment-channels");
			const response = await supplierApi.getPaymentChannels(channelsUrl ?? undefined);
			setPayoutOptions({
				countries: response.countries?.length ? response.countries : DEFAULT_PAYOUT_CHANNEL_OPTIONS.countries,
				banks: response.banks ?? [],
				e_wallets: response.e_wallets ?? [],
			});
		} catch (error) {
			payoutOptionsRequested.current = false;
			setPayoutOptionsError(getApiErrorMessage(error, "Xendit supported payout channels could not be loaded. Connect Xendit and try again."));
		} finally {
			setPayoutOptionsLoading(false);
		}
	};

	const fetchSuppliers = async () => {
		setLoading(true);
		try {
			const suppliersUrl = erpUrl(erpCapabilities, "GET:procurement.suppliers.index");
			if (ownerMode && !suppliersUrl) return;

			const response = await supplierApi.getAll(
				{ page: 1, per_page: 100, archived: showArchived },
				suppliersUrl ?? undefined,
			);
			setSuppliers(response.data || []);
		} catch (error) {
			console.error("Failed to fetch suppliers:", error);
			await Swal.fire("Error", "Failed to load suppliers", "error");
		} finally {
			setLoading(false);
		}
	};

	useEffect(() => {
		void fetchSuppliers();
	}, [showArchived]);

	const filteredData = useMemo(() => {
		if (!suppliers || !Array.isArray(suppliers)) return [];
		const query = searchQuery.trim().toLowerCase();
		if (!query) return suppliers;

		return suppliers.filter((supplier) =>
			supplier.name.toLowerCase().includes(query) ||
			supplier.email?.toLowerCase().includes(query) ||
			supplier.contact_person?.toLowerCase().includes(query) ||
			supplier.phone?.toLowerCase().includes(query) ||
			supplier.notes?.toLowerCase().includes(query)
		);
	}, [searchQuery, suppliers]);

	const itemsPerPage = 8;
	const totalPages = Math.max(1, Math.ceil((filteredData?.length || 0) / itemsPerPage));
	const startIndex = (currentPage - 1) * itemsPerPage;
	const paginatedItems = filteredData?.slice(startIndex, startIndex + itemsPerPage) || [];

	const handleView = (supplier: Supplier) => {
		setViewingSupplier(supplier);
	};

	const loadPaymentProfile = async (supplier: Supplier) => {
		setPaymentProfileLoading(true);
		try {
			const profile = await supplierApi.getPaymentProfile(supplier.id);
			setPaymentProfile(profile);
			const nameParts = splitIndividualName(supplier.name);
			setFormData((current) => ({
				...current,
				recipient_type: profile?.recipient_type || current.recipient_type,
				business_name: profile?.business_name || current.business_name,
				given_name: profile?.given_name || nameParts.given_name,
				surname: profile?.surname || nameParts.surname,
				address: profile?.recipient_street_line_1 || current.address,
				address_line_2: profile?.recipient_street_line_2 || current.address_line_2,
				city: profile?.recipient_city || current.city,
				province_state: profile?.recipient_province_state || current.province_state,
				postal_code: profile?.recipient_postal_code || current.postal_code,
				country: displayRecipientCountry(profile?.recipient_country || current.country),
			}));
			setPaymentProfileForm({
				destination_type: (profile?.destination_type as SupplierPaymentDestinationType) || "bank_account",
				wallet_provider: profile?.wallet_provider || "",
				bank_name: profile?.bank_name || "",
				bank_code: profile?.bank_code || "",
				account_name: profile?.account_name || "",
				account_number: "",
				account_identifier: "",
			});
		} catch (error) {
			console.error("Failed to load supplier payment profile:", error);
			setPaymentProfile(null);
			setPaymentProfileForm(initialPaymentProfileFormState);
			setShowPaymentAccount(false);
		} finally {
			setPaymentProfileLoading(false);
		}
	};

	const closeEditModal = () => {
		setEditingSupplier(null);
		setFormData(initialFormState);
			setPaymentProfile(null);
			setPaymentProfileForm(initialPaymentProfileFormState);
			setShowPaymentAccount(false);
	};

	const handleEdit = (supplier: Supplier) => {
		if (ownerMode) return;

		void loadPayoutOptions();
		setEditingSupplier(supplier);
		setPaymentProfile(null);
		setPaymentProfileForm(initialPaymentProfileFormState);
		void loadPaymentProfile(supplier);
		setFormData({
			recipient_type: "business",
			business_name: supplier.name,
			given_name: "",
			surname: "",
			contact_person: supplier.contact_person || "",
			email: supplier.email || "",
			phone: supplier.phone || "",
			address: supplier.address || "",
			address_line_2: "",
			city: supplier.city || "",
			province_state: "",
			postal_code: "",
			country: displayRecipientCountry(supplier.country),
			payment_terms: PAYMENT_TERMS.includes(supplier.payment_terms as typeof PAYMENT_TERMS[number])
				? supplier.payment_terms
				: "",
			lead_time_days: supplier.lead_time_days?.toString() || "",
			products_supplied: supplier.products_supplied || "",
			notes: supplier.notes || "",
		});
	};

	const handleArchive = async (supplierId: number) => {
		if (ownerMode) return;

		const result = await Swal.fire(withSweetAlertSemantic({
			title: "Archive Supplier?",
			text: "Are you sure you want to archive this supplier? You can restore it later if needed.",
			icon: "warning",
			showCancelButton: true,
			confirmButtonColor: "#dc2626",
			cancelButtonColor: "#6b7280",
			confirmButtonText: "Archive",
			cancelButtonText: "Cancel",
		}, "danger"));

		if (!result.isConfirmed) return;

		try {
			await supplierApi.delete(supplierId);
			await Swal.fire({
				title: "Archived!",
				text: "Supplier has been archived successfully.",
				icon: "success",
				timer: 1500,
			});
			await fetchSuppliers();
		} catch (error) {
			console.error("Failed to archive supplier:", error);
			await Swal.fire("Error", "Failed to archive supplier", "error");
		}
	};

	const handleRestore = async (supplierId: number) => {
		if (ownerMode) return;

		const result = await Swal.fire(withSweetAlertSemantic({
			title: "Restore Supplier?",
			text: "Are you sure you want to restore this supplier to active records?",
			icon: "question",
			showCancelButton: true,
			confirmButtonColor: "#2563eb",
			cancelButtonColor: "#6b7280",
			confirmButtonText: "Restore",
			cancelButtonText: "Cancel",
		}, "success"));

		if (!result.isConfirmed) return;

		try {
			await supplierApi.restore(supplierId);
			await Swal.fire({
				title: "Restored!",
				text: "Supplier has been restored successfully.",
				icon: "success",
				timer: 1500,
			});
			await fetchSuppliers();
		} catch (error) {
			console.error("Failed to restore supplier:", error);
			await Swal.fire("Error", "Failed to restore supplier", "error");
		}
	};

	const handleSaveEdit = async () => {
		if (ownerMode) return;

		const displayName = supplierDisplayName(formData);
		if (!displayName) {
			await Swal.fire("Warning", formData.recipient_type === "business" ? "Please fill required field (Business Name)" : "Please fill Given Name and Surname", "warning");
			return;
		}

		if (formData.email.trim() && !/^\S+@\S+\.\S+$/.test(formData.email.trim())) {
			await Swal.fire("Warning", "Please enter a valid email address", "warning");
			return;
		}

		if (!editingSupplier) return;

		const shouldSyncPaymentProfile = paymentProfile !== null || hasPaymentDestinationInput(paymentProfileForm);
		if (shouldSyncPaymentProfile) {
			const missingField = missingRecipientDetail(formData);
			if (missingField) {
				await Swal.fire("Warning", `Please complete Supplier Details before saving the payment profile (${missingField}).`, "warning");
				return;
			}
		}

		try {
			await supplierApi.update(editingSupplier.id, {
				name: displayName,
				contact_person: formData.contact_person,
				email: formData.email,
				phone: formData.phone,
				address: formData.address,
				city: formData.city,
				country: formData.country,
				payment_terms: formData.payment_terms as Supplier["payment_terms"],
				lead_time_days: formData.lead_time_days ? Number(formData.lead_time_days) : undefined,
				products_supplied: formData.products_supplied,
				notes: formData.notes,
			});
			if (shouldSyncPaymentProfile) {
				await supplierApi.upsertPaymentProfile(editingSupplier.id, buildPaymentProfilePayload(formData, paymentProfileForm, payoutOptions.countries));
			}

			await Swal.fire("Success", "Supplier updated successfully", "success");
			closeEditModal();
			await fetchSuppliers();
		} catch (error) {
			console.error("Failed to update supplier");
			await Swal.fire("Error", getApiErrorMessage(error, "Failed to update supplier"), "error");
		}
	};

	const handleOpenModal = () => {
		if (ownerMode) return;

		void loadPayoutOptions();
			setFormData(initialFormState);
			setPaymentProfileForm(initialPaymentProfileFormState);
			setShowPaymentAccount(false);
			setIsModalOpen(true);
	};

	const handleCloseModal = () => {
			setIsModalOpen(false);
			setFormData(initialFormState);
			setPaymentProfileForm(initialPaymentProfileFormState);
			setShowPaymentAccount(false);
	};

	const handleFormChange = (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement>) => {
		const { name, value } = e.target;
		setFormData((prev) => name === "recipient_type"
			? {
				...prev,
				recipient_type: value as SupplierRecipientType,
				business_name: "",
				given_name: "",
				surname: "",
			}
			: {
				...prev,
				[name]: name === "phone" ? value.replace(/\D/g, "").slice(0, 11) : value,
			});
	};

	const handlePaymentProfileChange = (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement>) => {
			const { name, value } = e.target;
			if (name === "destination_type") {
				setPaymentProfileForm((prev) => ({
					...prev,
					destination_type: value as SupplierPaymentDestinationType,
					wallet_provider: "", bank_name: "", bank_code: "", account_number: "", account_identifier: "",
				}));
				return;
			}

			if (name === "bank_name") {
				const selectedBank = payoutOptions.banks.find((bank) => bank.channel_name === value);
				setPaymentProfileForm((prev) => ({
					...prev,
					bank_name: value,
					bank_code: selectedBank?.channel_code === "PH_BDO" ? "BNORPHMM" : "",
				}));
				return;
			}

			setPaymentProfileForm((prev) => ({ ...prev, [name]: value }));
	};

	const handleAddSupplier = async () => {
		if (ownerMode) return;

		const displayName = supplierDisplayName(formData);
		if (!displayName) {
			await Swal.fire("Warning", formData.recipient_type === "business" ? "Please fill required field (Business Name)" : "Please fill Given Name and Surname", "warning");
			return;
		}

		if (formData.email.trim() && !/^\S+@\S+\.\S+$/.test(formData.email.trim())) {
			await Swal.fire("Warning", "Please enter a valid email address", "warning");
			return;
		}

		const hasPaymentProfileInput = hasPaymentDestinationInput(paymentProfileForm);
		if (hasPaymentProfileInput) {
			const missingField = missingRecipientDetail(formData);
			if (missingField) {
				await Swal.fire("Warning", `Please complete Supplier Details before saving the payment profile (${missingField}).`, "warning");
				return;
			}
		}

		try {
				const paymentProfilePayload: UpsertSupplierPaymentProfilePayload | undefined = hasPaymentProfileInput
					? buildPaymentProfilePayload(formData, paymentProfileForm, payoutOptions.countries)
					: undefined;
				await supplierApi.create({
					name: displayName,
				contact_person: formData.contact_person,
				email: formData.email,
				phone: formData.phone,
				address: formData.address,
				city: formData.city,
				country: formData.country,
				payment_terms: formData.payment_terms as Supplier["payment_terms"],
				lead_time_days: formData.lead_time_days ? Number(formData.lead_time_days) : undefined,
					products_supplied: formData.products_supplied,
					notes: formData.notes,
					payment_profile: paymentProfilePayload,
				});

			await Swal.fire("Success", "Supplier created successfully", "success");
			handleCloseModal();
			await fetchSuppliers();
		} catch (error) {
			console.error("Failed to create supplier:", error);
			await Swal.fire("Error", getApiErrorMessage(error, "Failed to create supplier"), "error");
		}
	};

	const isAnyModalOpen = isModalOpen || !!viewingSupplier || !!editingSupplier;
	return (
		<AppLayoutERP hideHeader={isAnyModalOpen}>
			<Head title="Suppliers Management - Solespace" />
			<div className="p-6 space-y-6">
				<div className="flex flex-col items-end lg:flex-row lg:items-center lg:justify-end gap-4">
					<h1 className="sr-only">Suppliers Management</h1>
					<div className="flex items-center gap-2">
						<button
							type="button"
							onClick={() => {
								setShowArchived((prev) => !prev);
								setCurrentPage(1);
							}}
							className={`px-4 py-2 rounded-lg font-medium transition-colors whitespace-nowrap border ${
								showArchived
									? "border-blue-200 dark:border-blue-700 bg-blue-50 dark:bg-blue-900/20 text-blue-700 dark:text-blue-300"
									: "border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-300"
							}`}
						>
							{showArchived ? "Show Active" : "Show Archived"}
						</button>

						{!ownerMode && !showArchived && (
							<button
								onClick={handleOpenModal}
								className="px-4 py-2 bg-blue-600 hover:bg-blue-700 dark:bg-blue-600 dark:hover:bg-blue-700 text-white rounded-lg font-medium transition-colors whitespace-nowrap"
							>
								+ Add Supplier
							</button>
						)}
					</div>
				</div>

				<div className="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-2xl p-6 shadow-sm">
					<div className="mb-4">
						<h2 className="text-lg font-semibold">Suppliers Table</h2>
						<p className="text-sm text-gray-500">Supplier name, contact info, and purchase history</p>
					</div>

					<div className="mb-4 flex flex-col sm:flex-row gap-3">
						<div className="flex-1">
							<input
								type="text"
								placeholder="Search by supplier name, contact, or notes..."
								value={searchQuery}
								onChange={(event) => {
									setSearchQuery(event.target.value);
									setCurrentPage(1);
								}}
								className="w-full px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-500 focus:outline-none focus:border-blue-500 dark:focus:border-blue-400"
							/>
						</div>
					</div>

					<div className="overflow-x-auto">
						<table className="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
							<thead className="bg-gray-50 dark:bg-gray-800/50">
								<tr>
									<th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Supplier name</th>
									<th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Contact info</th>
								<th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Notes</th>
									<th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500">Purchase history</th>
									<th className="px-4 py-3 text-center text-xs font-semibold uppercase tracking-wider text-gray-500">Action</th>
								</tr>
							</thead>
							<tbody className="divide-y divide-gray-200 dark:divide-gray-700">
								{paginatedItems.length > 0 ? (
									paginatedItems.map((supplier) => (
										<tr key={supplier.id} className="hover:bg-gray-50 dark:hover:bg-gray-800/30 transition-colors">
										<td className="px-4 py-3">
											<p className="text-sm font-medium text-gray-900 dark:text-white">{supplier.name}</p>
											<div className="mt-1 flex flex-wrap gap-1">
												<span className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ${supplier.is_active ? "bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400" : "bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400"}`}>
													{supplier.is_active ? "Active" : "Inactive"}
												</span>
												{(() => {
													const [label, className] = paymentProfileStatusPresentation(supplier.payment_profile_status);
													return <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ${className}`}>{label}</span>;
												})()}
											</div>
										</td>
										<td className="px-4 py-3 text-sm text-gray-700 dark:text-gray-300">
											<p>{supplier.email}</p>
											{supplier.phone && <p className="text-xs text-gray-500 dark:text-gray-400 mt-0.5">{supplier.phone}</p>}
										</td>
										<td className="px-4 py-3 text-sm text-gray-500 dark:text-gray-400 max-w-xs truncate italic" title={supplier.notes}>{supplier.notes || "—"}</td>
										<td className="px-4 py-3 text-sm">
											<span className="font-semibold text-gray-900 dark:text-white">
												{supplier.purchase_order_count} {supplier.purchase_order_count === 1 ? "order" : "orders"}
											</span>
											{supplier.last_order_date && <p className="text-xs text-gray-400 mt-0.5">Last: {supplier.last_order_date}</p>}
										</td>
											<td className="px-4 py-3 text-center">
												<div className="flex items-center justify-center gap-2">
													<IconButton
														variant="neutral"
														onClick={() => handleView(supplier)}
														title="View supplier details"
														label={`View details for ${supplier.name}`}
													>
														<svg className="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2} aria-hidden="true">
															<path strokeLinecap="round" strokeLinejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.27 2.943 9.542 7-1.272 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
															<circle cx="12" cy="12" r="3" />
														</svg>
													</IconButton>
														{ownerMode ? null : showArchived ? (
														<IconButton
															variant="success"
															onClick={() => handleRestore(supplier.id)}
															title="Restore supplier"
															label={`Restore ${supplier.name}`}
														>
															<RestoreIcon className="w-5 h-5" />
														</IconButton>
													) : (
														<>
															<IconButton
																variant="neutral"
																onClick={() => handleEdit(supplier)}
																title="Edit supplier"
																label={`Edit ${supplier.name}`}
															>
																<PencilIcon className="w-5 h-5" />
															</IconButton>
															<IconButton
																variant="danger"
																onClick={() => handleArchive(supplier.id)}
																title="Archive supplier"
																label={`Archive ${supplier.name}`}
															>
																<ArchiveBoxIcon className="w-5 h-5" />
															</IconButton>
														</>
													)}
												</div>
											</td>
										</tr>
									))
								) : (
									<tr>
										<td colSpan={5} className="px-4 py-10 text-center text-sm text-gray-500">
											{showArchived ? "No archived supplier records found." : "No supplier records found."}
										</td>
									</tr>
								)}
							</tbody>
						</table>
					</div>

					<div className="mt-4 flex items-center justify-between">
						<p className="text-sm text-gray-500">
							Showing {(filteredData?.length || 0) === 0 ? 0 : startIndex + 1} to {Math.min(startIndex + itemsPerPage, (filteredData?.length || 0))} of {(filteredData?.length || 0)} suppliers
						</p>
						<div className="flex gap-2">
							<button
								type="button"
								onClick={() => setCurrentPage((prev) => Math.max(prev - 1, 1))}
								disabled={currentPage === 1}
								className="p-2 rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
								title="Previous page"
							>
								<ChevronLeftIcon className="w-5 h-5" />
							</button>
							<button
								type="button"
								onClick={() => setCurrentPage((prev) => Math.min(prev + 1, totalPages))}
								disabled={currentPage === totalPages}
								className="p-2 rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
								title="Next page"
							>
								<ChevronRightIcon className="w-5 h-5" />
							</button>
						</div>
					</div>
				</div>
			</div>

			{/* Add Supplier Modal */}
			{isModalOpen && (
				<div className="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto px-4 py-6 sm:py-8">
					<button type="button" aria-label="Close add supplier modal" className="absolute inset-0 bg-black/50 erp-modal-backdrop" onClick={handleCloseModal} />
					<div role="dialog" aria-modal="true" aria-labelledby="add-supplier-title" className="relative flex max-h-[calc(100dvh-2rem)] w-full max-w-5xl flex-col overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-xl dark:border-gray-800 dark:bg-gray-900 sm:max-h-[calc(100dvh-3rem)]">
						<div className="flex shrink-0 items-center justify-between border-b border-gray-200 px-5 py-4 dark:border-gray-800 sm:px-6">
							<h2 id="add-supplier-title" className="text-xl font-semibold text-gray-900 dark:text-white">Add New Supplier</h2>
							<button
								type="button"
								aria-label="Close add supplier modal"
								onClick={handleCloseModal}
								className="flex size-11 items-center justify-center rounded-lg text-2xl leading-none text-gray-500 transition-colors hover:bg-gray-100 hover:text-gray-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 dark:text-gray-400 dark:hover:bg-gray-800 dark:hover:text-gray-200"
							>
								×
							</button>
						</div>

						<div className="min-h-0 flex-1 overflow-y-auto p-5 space-y-5 sm:p-6">
							<SupplierFormFields formData={formData} onChange={handleFormChange} idPrefix="add" countries={payoutOptions.countries} />

							<div className="rounded-xl border border-blue-200 bg-blue-50/60 p-3 space-y-2 dark:border-blue-900/60 dark:bg-blue-950/20 sm:col-span-2">
								<p className="text-xs text-gray-500 dark:text-gray-400">Optional: save a payout destination for later Finance payment review.</p>
								<SupplierPaymentProfileFields form={paymentProfileForm} onChange={handlePaymentProfileChange} idPrefix="add" showAccount={showPaymentAccount} onToggleAccount={() => setShowPaymentAccount((visible) => !visible)} banks={payoutOptions.banks} wallets={payoutOptions.e_wallets} optionsLoading={payoutOptionsLoading} optionsError={payoutOptionsError} />
							</div>

						</div>

						<div className="flex shrink-0 gap-3 border-t border-gray-200 bg-gray-50 px-5 py-4 dark:border-gray-800 dark:bg-gray-800/50 sm:px-6">
							<button
								type="button"
								onClick={handleCloseModal}
								className="min-h-11 flex-1 rounded-lg border border-gray-300 bg-white px-4 py-2 font-medium text-gray-700 transition-colors hover:bg-gray-100 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-300 dark:hover:bg-gray-800"
							>
								Cancel
							</button>
							<button
								type="button"
								onClick={handleAddSupplier}
								className="min-h-11 flex-1 rounded-lg bg-blue-600 px-4 py-2 font-medium text-white transition-colors hover:bg-blue-700 dark:bg-blue-600 dark:hover:bg-blue-700"
							>
								Add Supplier
							</button>
						</div>
					</div>
				</div>
			)}

			{/* View Supplier Modal */}
			{viewingSupplier && (
				<div className="fixed inset-0 z-50 flex items-center justify-center px-4 py-6 sm:py-8">
					<button type="button" aria-label="Close view supplier modal" className="absolute inset-0 bg-black/50 erp-modal-backdrop" onClick={() => setViewingSupplier(null)} />
					<div role="dialog" aria-modal="true" aria-labelledby="view-supplier-title" className="relative flex max-h-[calc(100dvh-2rem)] w-full max-w-3xl flex-col overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-xl dark:border-gray-800 dark:bg-gray-900 sm:max-h-[calc(100dvh-3rem)]">
						<div className="flex shrink-0 items-center justify-between border-b border-gray-200 px-5 py-3 dark:border-gray-800">
							<h2 id="view-supplier-title" className="text-xl font-semibold text-gray-900 dark:text-white">Supplier Details</h2>
							<button
								onClick={() => setViewingSupplier(null)}
								className="text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 text-2xl leading-none"
							>
								×
							</button>
						</div>

						<div className="min-h-0 flex-1 space-y-3 overflow-y-auto p-5">
							<div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
								<div className="rounded-xl border border-gray-200 bg-gray-50 p-3 dark:border-gray-800 dark:bg-gray-800/40">
									<p className="text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">Supplier Name</p>
									<p className="text-base font-semibold text-gray-900 dark:text-white">{viewingSupplier.name}</p>
								</div>
								<div className="rounded-xl border border-gray-200 bg-gray-50 p-3 dark:border-gray-800 dark:bg-gray-800/40">
									<p className="text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">Status</p>
									<span className={`inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold ${viewingSupplier.is_active ? "bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400" : "bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400"}`}>
										{viewingSupplier.is_active ? "Active" : "Inactive"}
									</span>
								</div>
							</div>

							{viewingSupplier.contact_person && (
								<div className="rounded-xl border border-gray-200 bg-gray-50 p-3 dark:border-gray-800 dark:bg-gray-800/40">
									<p className="text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">Contact Person</p>
									<p className="text-base font-semibold text-gray-900 dark:text-white">{viewingSupplier.contact_person}</p>
								</div>
							)}

							<div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
								<div className="rounded-xl border border-gray-200 bg-gray-50 p-3 dark:border-gray-800 dark:bg-gray-800/40">
									<p className="text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">Email</p>
									<p className="text-base font-semibold text-gray-900 dark:text-white break-all">{viewingSupplier.email || "—"}</p>
								</div>
								<div className="rounded-xl border border-gray-200 bg-gray-50 p-3 dark:border-gray-800 dark:bg-gray-800/40">
									<p className="text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">Phone</p>
									<p className="text-base font-semibold text-gray-900 dark:text-white">{viewingSupplier.phone || "—"}</p>
								</div>
							</div>

			{viewingSupplier.address && (
				<div className="rounded-xl border border-gray-200 bg-gray-50 p-3 dark:border-gray-800 dark:bg-gray-800/40">
					<p className="text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">Address</p>
					<p className="text-base font-semibold text-gray-900 dark:text-white">{viewingSupplier.address}</p>
				</div>
			)}

			<div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
				<div className="rounded-xl border border-gray-200 bg-gray-50 p-3 dark:border-gray-800 dark:bg-gray-800/40">
					<p className="text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">City / Country</p>
					<p className="text-base font-semibold text-gray-900 dark:text-white">{[viewingSupplier.city, viewingSupplier.country].filter(Boolean).join(", ") || "—"}</p>
				</div>
				<div className="rounded-xl border border-gray-200 bg-gray-50 p-3 dark:border-gray-800 dark:bg-gray-800/40">
					<p className="text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">Payment Terms</p>
					<p className="text-base font-semibold text-gray-900 dark:text-white">{viewingSupplier.payment_terms || "Use procurement default"}</p>
				</div>
				<div className="rounded-xl border border-gray-200 bg-gray-50 p-3 dark:border-gray-800 dark:bg-gray-800/40">
					<p className="text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">Lead Time</p>
					<p className="text-base font-semibold text-gray-900 dark:text-white">{viewingSupplier.lead_time_days ?? "—"}{viewingSupplier.lead_time_days !== undefined ? " days" : ""}</p>
				</div>
				<div className="rounded-xl border border-gray-200 bg-gray-50 p-3 dark:border-gray-800 dark:bg-gray-800/40">
					<p className="text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">Products Supplied</p>
					<p className="text-sm text-gray-700 dark:text-gray-300">{viewingSupplier.products_supplied || "—"}</p>
				</div>
			</div>

{viewingSupplier.notes && (
							<div className="rounded-xl border border-gray-200 bg-gray-50 p-3 dark:border-gray-800 dark:bg-gray-800/40">
								<p className="text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">Notes</p>
								<p className="text-sm text-gray-700 dark:text-gray-300 whitespace-pre-wrap">{viewingSupplier.notes}</p>
							</div>
						)}

							<div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
								<div className="rounded-xl border border-gray-200 bg-gray-50 p-3 dark:border-gray-800 dark:bg-gray-800/40">
									<p className="text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">Total Purchase Orders</p>
									<p className="text-base font-semibold text-gray-900 dark:text-white">{viewingSupplier.purchase_order_count} {viewingSupplier.purchase_order_count === 1 ? "order" : "orders"}</p>
								</div>
								<div className="rounded-xl border border-gray-200 bg-gray-50 p-3 dark:border-gray-800 dark:bg-gray-800/40">
									<p className="text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">Last Order Date</p>
									<p className="text-base font-semibold text-gray-900 dark:text-white">{viewingSupplier.last_order_date || "No orders yet"}</p>
								</div>
							</div>
						</div>

						<div className="flex shrink-0 gap-3 border-t border-gray-200 bg-gray-50 px-5 py-3 dark:border-gray-800 dark:bg-gray-800/50">
							<button
								onClick={() => setViewingSupplier(null)}
								className="flex-1 px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-300 font-medium hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors"
							>
								Close
							</button>
						</div>
					</div>
				</div>
			)}

			{/* Edit Supplier Modal */}
			{editingSupplier && (
				<div className="fixed inset-0 z-50 flex items-center justify-center px-4 py-6 sm:py-8">
					<button type="button" aria-label="Close edit supplier modal" className="absolute inset-0 bg-black/50 erp-modal-backdrop" onClick={closeEditModal} />
					<div role="dialog" aria-modal="true" aria-labelledby="edit-supplier-title" className="relative flex max-h-[calc(100dvh-2rem)] w-full max-w-5xl flex-col overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-xl dark:border-gray-800 dark:bg-gray-900 sm:max-h-[calc(100dvh-3rem)]">
						<div className="flex shrink-0 items-center justify-between border-b border-gray-200 px-5 py-3 dark:border-gray-800">
							<h2 id="edit-supplier-title" className="text-xl font-semibold text-gray-900 dark:text-white">Edit Supplier</h2>
							<button
								onClick={closeEditModal}
								className="text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 text-2xl leading-none"
							>
								×
							</button>
						</div>

						<div className="min-h-0 flex-1 space-y-5 overflow-y-auto px-5 py-3">
							<SupplierFormFields formData={formData} onChange={handleFormChange} idPrefix="edit" countries={payoutOptions.countries} />

						<div className="rounded-xl border border-blue-200 bg-blue-50/60 p-3 space-y-2 dark:border-blue-900/60 dark:bg-blue-950/20">
							<div>
								<p className="text-xs text-gray-500 dark:text-gray-400">
									{paymentProfile ? `Status: ${paymentProfile.status}. Account: ${paymentProfile.masked_account_identifier || paymentProfile.masked_account_number || "—"}` : "No payment profile saved yet."}
								</p>
							</div>

							{paymentProfileLoading ? (
								<p className="text-sm text-gray-500 dark:text-gray-400">Loading payment profile…</p>
							) : (
								<>
									<SupplierPaymentProfileFields form={paymentProfileForm} onChange={handlePaymentProfileChange} idPrefix="edit" showAccount={showPaymentAccount} onToggleAccount={() => setShowPaymentAccount((visible) => !visible)} keepSavedAccount={!!paymentProfile} banks={payoutOptions.banks} wallets={payoutOptions.e_wallets} optionsLoading={payoutOptionsLoading} optionsError={payoutOptionsError} />
									<p className="text-xs text-gray-500 dark:text-gray-400">
										{paymentProfile?.status === "disabled"
											? "Disabled by Finance. Replace the destination here; Finance must verify it before it can be used for a supplier payment."
											: "Changing destination details returns the profile to unverified for Finance review."}
									</p>
								</>
							)}
						</div>
						</div>

						<div className="flex shrink-0 gap-3 border-t border-gray-200 bg-gray-50 px-5 py-3 dark:border-gray-800 dark:bg-gray-800/50">
							<button
								onClick={closeEditModal}
								className="flex-1 px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-300 font-medium hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors"
							>
								Cancel
							</button>
							<button
								onClick={handleSaveEdit}
								className="flex-1 px-4 py-2 rounded-lg bg-blue-600 hover:bg-blue-700 dark:bg-blue-600 dark:hover:bg-blue-700 text-white font-medium transition-colors"
							>
								Save Changes
							</button>
						</div>
					</div>
				</div>
			)}
		</AppLayoutERP>
	);
}
