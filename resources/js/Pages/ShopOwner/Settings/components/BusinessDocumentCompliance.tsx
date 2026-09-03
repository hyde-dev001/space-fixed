import { router } from '@inertiajs/react';
import { useState } from 'react';
import { CheckCircle2, FileText, History, Upload } from 'lucide-react';

export type ComplianceValidity = 'valid' | 'valid_no_expiration' | 'expiring_soon' | 'expired' | 'metadata_unverified';

export type ComplianceDocument = {
	id: number;
	document_type: string;
	logical_slot: string;
	version_number: number | null;
	status: string;
	issued_on: string | null;
	expiration_mode: 'dated' | 'none' | null;
	expires_on: string | null;
	validity: ComplianceValidity;
	legacy_label: string | null;
	url: string;
};

export type ComplianceSlot = {
	logical_slot: string;
	title: string;
	current: ComplianceDocument | null;
	pending: ComplianceDocument | null;
	history: ComplianceDocument[];
};

export const validityLabel = (validity: ComplianceValidity): string => ({
	valid: 'Valid',
	valid_no_expiration: 'Valid · no expiration',
	expiring_soon: 'Expiring soon',
	expired: 'Expired',
	metadata_unverified: 'Metadata unverified',
}[validity]);

export const canRenewComplianceDocument = (slot: ComplianceSlot): boolean => Boolean(
	slot.current && !slot.pending && slot.current.status === 'approved',
);

export const initialRenewalDocumentType = (documentType: string): string => (
	documentType === 'dti_registration' || documentType === 'sec_registration'
		? documentType
		: ''
);

type Props = {
	documents: ComplianceSlot[];
};

type FormState = {
	file: File | null;
	documentType: string;
	issuedOn: string;
	expirationMode: 'dated' | 'none';
	expiresOn: string;
};

const initialForm = (document: ComplianceDocument): FormState => ({
	file: null,
	documentType: initialRenewalDocumentType(document.document_type),
	issuedOn: document.issued_on ?? '',
	expirationMode: document.expiration_mode === 'dated' ? 'dated' : 'none',
	expiresOn: document.expires_on ?? '',
});

export default function BusinessDocumentCompliance({ documents }: Props) {
	const [activeSlot, setActiveSlot] = useState<string | null>(null);
	const [form, setForm] = useState<FormState | null>(null);
	const [errors, setErrors] = useState<Record<string, string>>({});
	const [submitting, setSubmitting] = useState(false);
	const [submitted, setSubmitted] = useState<string | null>(null);

	const beginRenewal = (slot: ComplianceSlot) => {
		if (!slot.current || !canRenewComplianceDocument(slot)) return;

		setActiveSlot(slot.logical_slot);
		setForm(initialForm(slot.current));
		setErrors({});
		setSubmitted(null);
	};

	const submitRenewal = (slot: ComplianceSlot) => {
		if (!form || !slot.current || !canRenewComplianceDocument(slot) || submitting) return;

		if (!form.file) {
			setErrors({ file: 'Choose a JPG, JPEG, or PNG file.' });
			return;
		}

		if (slot.logical_slot === 'mayors_permit' && form.expirationMode !== 'dated') {
			setErrors({ expiration_mode: "Mayor's Permit must have a dated expiration." });
			return;
		}

		if (slot.logical_slot === 'business_registration'
			&& !['dti_registration', 'sec_registration'].includes(form.documentType)) {
			setErrors({ document_type: 'Choose whether this renewal is DTI or SEC.' });
			return;
		}

		if (form.expirationMode === 'dated' && !form.expiresOn) {
			setErrors({ expires_on: 'Enter an expiration date.' });
			return;
		}

		const payload = new FormData();
		payload.append('file', form.file);
		payload.append('document_type', form.documentType);
		payload.append('logical_slot', slot.logical_slot);
		payload.append('issued_on', form.issuedOn);
		payload.append('expiration_mode', form.expirationMode);
		payload.append('expires_on', form.expirationMode === 'dated' ? form.expiresOn : '');
		payload.append('submission_key', crypto.randomUUID());

		setSubmitting(true);
		setErrors({});
		router.post(`/shop-owner/compliance-documents/${slot.current.id}/renewals`, payload, {
			forceFormData: true,
			onSuccess: () => {
				setSubmitted(slot.logical_slot);
				setActiveSlot(null);
				setForm(null);
				router.reload({ only: ['shop_settings'] });
			},
			onError: (serverErrors) => {
				const normalized: Record<string, string> = {};
				Object.entries(serverErrors ?? {}).forEach(([key, value]) => {
					normalized[key] = Array.isArray(value) ? String(value[0]) : String(value);
				});
				setErrors(normalized);
			},
			onFinish: () => setSubmitting(false),
		});
	};

	return (
		<section className="rounded-2xl border border-gray-200 bg-white shadow-sm" aria-labelledby="business-document-compliance-heading">
			<div className="border-b border-gray-200 p-5">
				<h2 id="business-document-compliance-heading" className="text-xl font-semibold text-gray-900">Business Document Compliance</h2>
				<p className="mt-1 text-sm text-gray-600">Approved versions stay unchanged. A renewal creates a new version for review.</p>
			</div>
			<div className="grid grid-cols-1 gap-4 p-5 md:grid-cols-2">
				{documents.map((slot) => {
					const current = slot.current;
					const isActive = activeSlot === slot.logical_slot && form !== null;

					return (
						<article key={slot.logical_slot} className="rounded-xl border border-gray-200 bg-gray-50/40 p-4">
							<div className="flex items-start justify-between gap-3">
								<div>
									<h3 className="text-sm font-semibold text-gray-900">{slot.title}</h3>
									{current ? (
										<p className="mt-1 text-xs text-gray-600">Version {current.version_number ?? 'legacy'} · {validityLabel(current.validity)}</p>
									) : (
										<p className="mt-1 text-xs text-gray-600">No approved version</p>
									)}
								</div>
								{current && current.validity !== 'metadata_unverified' && <CheckCircle2 className="h-5 w-5 text-emerald-600" aria-hidden="true" />}
							</div>

							{current && (
								<div className="mt-3 space-y-1 text-xs text-gray-700">
									<p>Issued: {current.issued_on || 'Not provided'}</p>
									<p>{current.expiration_mode === 'dated' ? `Expires: ${current.expires_on || 'Not provided'}` : 'No expiration declared'}</p>
									{current.legacy_label && <p className="font-medium text-amber-700">{current.legacy_label}</p>}
									<a href={current.url} target="_blank" rel="noreferrer" className="inline-flex items-center gap-1 font-semibold underline underline-offset-2"> <FileText className="h-3.5 w-3.5" /> View current document</a>
								</div>
							)}

							{slot.pending && <p className="mt-3 rounded-md bg-amber-50 px-3 py-2 text-xs font-medium text-amber-800">Renewal pending review (version {slot.pending.version_number ?? 'new'}).</p>}

							{slot.history.length > 1 && (
								<details className="mt-3 text-xs text-gray-700">
									<summary className="inline-flex cursor-pointer items-center gap-1 font-semibold"><History className="h-3.5 w-3.5" /> View history</summary>
									<ul className="mt-2 space-y-1 pl-5">
										{slot.history.map((historyDocument) => <li key={historyDocument.id}>Version {historyDocument.version_number ?? 'legacy'} · {historyDocument.status} · <a href={historyDocument.url} target="_blank" rel="noreferrer" className="underline">Open</a></li>)}
									</ul>
								</details>
							)}

							{canRenewComplianceDocument(slot) && !isActive && (
								<button type="button" onClick={() => beginRenewal(slot)} className="mt-4 inline-flex items-center gap-2 rounded-lg bg-gray-900 px-3 py-2 text-xs font-semibold text-white hover:bg-black">
									<Upload className="h-4 w-4" /> Renew document
								</button>
							)}

							{isActive && form && current && (
								<div className="mt-4 space-y-3 border-t border-gray-200 pt-4">
									<label className="block text-xs font-semibold text-gray-700">Replacement file<input type="file" accept=".jpg,.jpeg,.png,image/jpeg,image/png" onChange={(event) => setForm({ ...form, file: event.target.files?.[0] ?? null })} className="mt-1 block w-full text-xs" /></label>
									{slot.logical_slot === 'business_registration' && <label className="block text-xs font-semibold text-gray-700">Registration authority<select value={form.documentType} onChange={(event) => setForm({ ...form, documentType: event.target.value })} className="mt-1 block w-full rounded-md border border-gray-300 px-2 py-1.5 text-sm"><option value="" disabled>Select issuing authority</option><option value="dti_registration">DTI</option><option value="sec_registration">SEC</option></select></label>}
									<label className="block text-xs font-semibold text-gray-700">Issue date<input type="date" value={form.issuedOn} onChange={(event) => setForm({ ...form, issuedOn: event.target.value })} className="mt-1 block w-full rounded-md border border-gray-300 px-2 py-1.5 text-sm" /></label>
									<label className="block text-xs font-semibold text-gray-700">Expiration<select value={form.expirationMode} onChange={(event) => setForm({ ...form, expirationMode: event.target.value as 'dated' | 'none', expiresOn: event.target.value === 'none' ? '' : form.expiresOn })} className="mt-1 block w-full rounded-md border border-gray-300 px-2 py-1.5 text-sm"><option value="dated">Dated</option><option value="none">No expiration</option></select></label>
									{form.expirationMode === 'dated' && <label className="block text-xs font-semibold text-gray-700">Expiration date<input type="date" value={form.expiresOn} onChange={(event) => setForm({ ...form, expiresOn: event.target.value })} className="mt-1 block w-full rounded-md border border-gray-300 px-2 py-1.5 text-sm" /></label>}
									{Object.entries(errors).map(([key, message]) => <p key={key} className="text-xs text-red-700" role="alert">{message}</p>)}
									<div className="flex gap-2"><button type="button" onClick={() => submitRenewal(slot)} disabled={submitting} className="rounded-lg bg-gray-900 px-3 py-2 text-xs font-semibold text-white transition hover:bg-black disabled:cursor-not-allowed disabled:opacity-50">{submitting ? 'Submitting…' : 'Submit renewal'}</button><button type="button" onClick={() => { setActiveSlot(null); setForm(null); setErrors({}); }} disabled={submitting} className="rounded-lg border border-gray-300 px-3 py-2 text-xs font-semibold text-gray-700 transition hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50">Cancel</button></div>
								</div>
							)}
							{submitted === slot.logical_slot && <p className="mt-3 text-xs font-medium text-emerald-700" role="status">Renewal submitted for review.</p>}
						</article>
					);
				})}
			</div>
		</section>
	);
}
