import type { ChangeEvent } from "react";
import type { SupplierPaymentDestinationType, SupplierRecipientType } from "@/types/procurement";

export interface SupplierPaymentProfileFormState {
	recipient_type: SupplierRecipientType;
	business_name: string;
	given_name: string;
	surname: string;
	recipient_country: string;
	recipient_province_state: string;
	recipient_city: string;
	recipient_street_line_1: string;
	recipient_street_line_2: string;
	recipient_postal_code: string;
	destination_type: SupplierPaymentDestinationType;
	wallet_provider: string;
	bank_name: string;
	bank_code: string;
	account_name: string;
	account_number: string;
	account_identifier: string;
}

interface Props {
	form: SupplierPaymentProfileFormState;
	onChange: (event: ChangeEvent<HTMLInputElement | HTMLSelectElement>) => void;
	idPrefix: string;
	showAccount: boolean;
	onToggleAccount: () => void;
	keepSavedAccount?: boolean;
}

const inputClass = "w-full rounded-lg border border-gray-300 bg-white px-4 py-2 text-gray-900 dark:border-gray-600 dark:bg-gray-800 dark:text-white";
const labelClass = "mb-2 block text-sm font-medium text-gray-700 dark:text-gray-300";

export default function SupplierPaymentProfileFields({ form, onChange, idPrefix, showAccount, onToggleAccount, keepSavedAccount = false }: Props) {
	const id = (name: string) => `${idPrefix}-payment-${name}`;
	const wallet = form.destination_type === "e_wallet";
	const field = (name: keyof SupplierPaymentProfileFormState, label: string, required = true) => (
		<div>
			<label htmlFor={id(name)} className={labelClass}>{label}</label>
			<input id={id(name)} aria-label={label} name={name} value={form[name]} onChange={onChange} required={required} className={inputClass} />
		</div>
	);

	return <div className="space-y-3">
		<div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
			<div>
				<label htmlFor={id("recipient_type")} className={labelClass}>Recipient Type</label>
				<select id={id("recipient_type")} aria-label="Recipient Type" name="recipient_type" value={form.recipient_type} onChange={onChange} className={inputClass}>
					<option value="business">Business</option><option value="individual">Individual</option>
				</select>
			</div>
			{form.recipient_type === "business" ? field("business_name", "Business Name") : <>{field("given_name", "Given Name")}{field("surname", "Surname")}</>}
		</div>
		<div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
			{field("recipient_country", "Country Code")}{field("recipient_province_state", "Province / State")}
			{field("recipient_city", "Recipient City")}{field("recipient_postal_code", "Postal Code")}
			<div className="sm:col-span-2">{field("recipient_street_line_1", "Street Address")}</div>
			<div className="sm:col-span-2">{field("recipient_street_line_2", "Address Line 2", false)}</div>
		</div>
		<div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
			<div><label htmlFor={id("destination_type")} className={labelClass}>Destination Type</label><select id={id("destination_type")} aria-label="Destination Type" name="destination_type" value={form.destination_type} onChange={onChange} className={inputClass}><option value="bank_account">Bank Account</option><option value="e_wallet">E-wallet</option></select></div>
			{field(wallet ? "wallet_provider" : "bank_name", wallet ? "Wallet Provider" : "Bank Name")}
			{!wallet && field("bank_code", "Bank Code")}
			{field("account_name", "Account Name")}
		</div>
		<div><label htmlFor={id("account")} className={labelClass}>{wallet ? "Mobile / Account Number" : "Account Number"}</label><div className="flex gap-2"><input id={id("account")} aria-label={wallet ? "Mobile / Account Number" : "Account Number"} name={wallet ? "account_identifier" : "account_number"} value={wallet ? form.account_identifier : form.account_number} onChange={onChange} type={showAccount ? "text" : "password"} autoComplete="off" placeholder={keepSavedAccount ? "Leave blank to keep the saved account" : undefined} className={`${inputClass} min-w-0 flex-1`} /><button type="button" onClick={onToggleAccount} aria-label={showAccount ? "Hide account number" : "Show account number"} title={showAccount ? "Hide account number" : "Show account number"} aria-pressed={showAccount} className="inline-flex min-h-11 min-w-11 items-center justify-center rounded-lg border border-gray-300"><svg className="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={1.8} aria-hidden="true"><path strokeLinecap="round" strokeLinejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.27 2.943 9.542 7-1.272 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" /><circle cx="12" cy="12" r="3" />{showAccount && <path strokeLinecap="round" d="m4 4 16 16" />}</svg></button></div></div>
	</div>;
}
