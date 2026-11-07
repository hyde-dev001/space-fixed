import type { ChangeEvent } from "react";
import type { SupplierPaymentDestinationType, SupplierPayoutChannelOption } from "@/types/procurement";

export interface SupplierPaymentDestinationFormState {
	destination_type: SupplierPaymentDestinationType;
	wallet_provider: string;
	bank_name: string;
	bank_code: string;
	account_name: string;
	account_number: string;
	account_identifier: string;
}

interface Props {
	form: SupplierPaymentDestinationFormState;
	onChange: (event: ChangeEvent<HTMLInputElement | HTMLSelectElement>) => void;
	idPrefix: string;
	showAccount: boolean;
	onToggleAccount: () => void;
	keepSavedAccount?: boolean;
	banks?: SupplierPayoutChannelOption[];
	wallets?: SupplierPayoutChannelOption[];
	optionsLoading?: boolean;
	optionsError?: string | null;
}

const inputClass = "w-full rounded-lg border border-gray-300 bg-white px-4 py-2 text-gray-900 dark:border-gray-600 dark:bg-gray-800 dark:text-white";
const labelClass = "mb-2 block text-sm font-medium text-gray-700 dark:text-gray-300";

export default function SupplierPaymentProfileFields({
	form,
	onChange,
	idPrefix,
	showAccount,
	onToggleAccount,
	keepSavedAccount = false,
	banks = [],
	wallets = [],
	optionsLoading = false,
	optionsError = null,
}: Props) {
	const id = (name: string) => `${idPrefix}-payment-${name}`;
	const wallet = form.destination_type === "e_wallet";
	const options = wallet ? wallets : banks;
	const optionField = wallet ? "wallet_provider" : "bank_name";
	const optionLabel = wallet ? "Wallet Provider" : "Bank";
	const selectedOption = form[optionField];
	const field = (name: keyof SupplierPaymentDestinationFormState, label: string, required = true) => (
		<div>
			<label htmlFor={id(name)} className={labelClass}>{label}</label>
			<input id={id(name)} aria-label={label} name={name} value={form[name]} onChange={onChange} required={required} className={inputClass} />
		</div>
	);

	return (
		<fieldset aria-labelledby={`${idPrefix}-payment-profile-title`} className="space-y-3">
			<legend id={`${idPrefix}-payment-profile-title`} className="text-sm font-semibold text-gray-900 dark:text-white">Payment Profile</legend>
			<p className="text-xs text-gray-500 dark:text-gray-400">Where should the supplier receive the money?</p>

			<div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
				<div>
					<label htmlFor={id("destination_type")} className={labelClass}>Destination Type</label>
					<select id={id("destination_type")} aria-label="Destination Type" name="destination_type" value={form.destination_type} onChange={onChange} className={inputClass}>
						<option value="bank_account">Bank Account</option>
						<option value="e_wallet">E-wallet</option>
					</select>
				</div>
				<div>
					<label htmlFor={id(optionField)} className={labelClass}>{optionLabel}</label>
					<select
						id={id(optionField)}
						aria-label={optionLabel}
						name={optionField}
						value={selectedOption}
						onChange={onChange}
						required
						disabled={optionsLoading && options.length === 0}
						className={inputClass}
					>
						<option value="">
							{optionsLoading ? `Loading Xendit ${wallet ? "wallets" : "banks"}...` : `Select ${optionLabel.toLowerCase()}`}
						</option>
						{selectedOption && !options.some((option) => option.channel_name === selectedOption) && (
							<option value={selectedOption}>{selectedOption} (saved)</option>
						)}
						{options.map((option) => (
							<option key={option.channel_code} value={option.channel_name}>
								{option.channel_name} ({option.channel_code})
							</option>
						))}
					</select>
					{optionsError && <p className="mt-1 text-xs text-red-600 dark:text-red-400">{optionsError}</p>}
				</div>
				{!wallet && field("bank_code", "Bank Code / SWIFT")}
				{field("account_name", "Account Holder Name")}
			</div>

			{!wallet && <p className="text-xs text-gray-500 dark:text-gray-400">Use the supplier bank's SWIFT/BIC routing value for Xendit v3. The selected bank channel code is only used to identify the supported bank.</p>}

			<div>
				<label htmlFor={id("account")} className={labelClass}>{wallet ? "Mobile / Account Number" : "Account Number"}</label>
				<div className="flex gap-2">
					<input
						id={id("account")}
						aria-label={wallet ? "Mobile / Account Number" : "Account Number"}
						name={wallet ? "account_identifier" : "account_number"}
						value={wallet ? form.account_identifier : form.account_number}
						onChange={onChange}
						type={showAccount ? "text" : "password"}
						autoComplete="off"
						placeholder={keepSavedAccount ? "Leave blank to keep the saved account" : undefined}
						className={`${inputClass} min-w-0 flex-1`}
					/>
					<button type="button" onClick={onToggleAccount} aria-label={showAccount ? "Hide account number" : "Show account number"} title={showAccount ? "Hide account number" : "Show account number"} aria-pressed={showAccount} className="inline-flex min-h-11 min-w-11 items-center justify-center rounded-lg border border-gray-300">
						<svg className="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={1.8} aria-hidden="true">
							<path strokeLinecap="round" strokeLinejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.27 2.943 9.542 7-1.272 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
							<circle cx="12" cy="12" r="3" />
							{showAccount && <path strokeLinecap="round" d="m4 4 16 16" />}
						</svg>
					</button>
				</div>
			</div>
		</fieldset>
	);
}
