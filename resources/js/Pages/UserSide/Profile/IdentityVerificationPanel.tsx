import React, { useEffect, useMemo, useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import Swal from '../Shared/UserModal';
import {
	compareRegistrationImageFingerprints,
	type RegistrationDocumentSide,
	type RegistrationDuplicateKind,
} from '../Auth/registrationOcr';
import {
	screenRegistrationDocumentSideFromFile,
	screenRegistrationSubmission,
	type RegistrationDocumentSideResult,
	type RegistrationNameMatchOutcome,
	type RegistrationNationalIdFormat,
} from '../Auth/registrationDocumentScreening';
import { matchRegistrationName } from '../Auth/registrationNameMatch';

export type CustomerIdentityRecord = {
	id: number;
	document_type: string | null;
	screening_status: string;
	review_status: string;
	failure_reason?: string | null;
	rejection_reason?: string | null;
	rejection_notes?: string | null;
	submitted_at?: string | null;
	reviewed_at?: string | null;
	front_url: string | null;
	back_url: string | null;
};

export type CustomerIdentityVerification = {
	status: string;
	current: CustomerIdentityRecord;
	can_resubmit: boolean;
	history: CustomerIdentityRecord[];
};

type Props = {
	identityVerification: CustomerIdentityVerification | null;
	firstName: string;
	lastName: string;
};

type UploadState = {
	files: Partial<Record<RegistrationDocumentSide, File>>;
	results: Partial<Record<RegistrationDocumentSide, RegistrationDocumentSideResult>>;
	statuses: Partial<Record<RegistrationDocumentSide, 'idle' | 'loading' | 'recognizing' | 'ready' | 'rejected' | 'error'>>;
};

const DOCUMENT_OPTIONS: Array<{
	value: string;
	label: string;
	guidance: string;
	slots: RegistrationDocumentSide[];
}> = [
	{
		value: 'national_id',
		label: 'National ID',
		guidance: 'Upload clear front and back images of your physical National ID or official Digital National ID screens.',
		slots: ['front', 'back'],
	},
	{
		value: 'drivers_license',
		label: "Driver's License",
		guidance: "Upload a clear front and back image of a Philippine LTO driver's license.",
		slots: ['front', 'back'],
	},
	{
		value: 'passport',
		label: 'Philippine Passport',
		guidance: 'Upload the passport biodata page with the complete machine-readable zone (MRZ).',
		slots: ['biodata'],
	},
	{
		value: 'umid',
		label: 'UMID',
		guidance: 'Upload one clear landscape image of the complete UMID front.',
		slots: ['front'],
	},
];

const ACCEPT = 'image/jpeg,image/png,image/webp';
const MAX_FILE_SIZE = 5 * 1024 * 1024;
const REJECTION_LABELS: Record<string, string> = {
	id_unreadable: 'ID was unreadable',
	wrong_document: 'Wrong document submitted',
	incomplete_details: 'ID details were incomplete',
	suspected_altered: 'Document needs additional authenticity review',
	front_back_mismatch: 'Front and back images did not match',
	other: 'Other reviewer reason',
};

const statusLabel = (value: string): string => (
	value.replaceAll('_', ' ').replace(/\b\w/g, character => character.toUpperCase())
);

const screeningLabel = (value: string): string => {
	if (value === 'automated_check_passed') return 'Passed';
	if (value === 'manual_review_required') return 'Needs human review';
	return statusLabel(value);
};

const dateLabel = (value?: string | null): string => {
	if (!value) return 'N/A';
	const date = new Date(value);
	return Number.isNaN(date.getTime()) ? value : date.toLocaleString();
};

const emptyUploadState = (): UploadState => ({
	files: {},
	results: {},
	statuses: {},
});

const fileError = (file: File): string | null => {
	if (file.size > MAX_FILE_SIZE) return 'Each ID image must be 5MB or smaller.';
	if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) {
		return 'Use a JPG, PNG, or WEBP image.';
	}
	return null;
};

const IdentityImage = ({ url, label }: { url: string | null; label: string }) => (
	<div className="overflow-hidden rounded-xl border border-gray-200 bg-gray-50">
		<div className="border-b border-gray-200 px-3 py-2 text-xs font-semibold uppercase tracking-wide text-gray-500">{label}</div>
		{url ? (
			<img src={url} alt={label} className="h-40 w-full object-contain p-2" />
		) : (
			<div className="flex h-40 items-center justify-center px-3 text-center text-xs text-gray-400">No image submitted</div>
		)}
	</div>
);

const IdentityStatus = ({ status }: { status: string }) => {
	const approved = status === 'approved';
	const rejected = status === 'rejected';
	const statusClass = approved
		? 'bg-emerald-100 text-emerald-700'
		: rejected
			? 'bg-red-100 text-red-700'
			: 'bg-amber-100 text-amber-700';

	return (
		<span className={'inline-flex rounded-full px-3 py-1 text-xs font-semibold ' + statusClass}>
			{statusLabel(status)}
		</span>
	);
};

const IdentityResubmission = ({ firstName, lastName, current }: {
	firstName: string;
	lastName: string;
	current: CustomerIdentityRecord;
}) => {
	const [documentType, setDocumentType] = useState(current.document_type || 'passport');
	const [upload, setUpload] = useState<UploadState>(emptyUploadState);
	const [duplicateKind, setDuplicateKind] = useState<RegistrationDuplicateKind>('none');
	const [isSubmitting, setIsSubmitting] = useState(false);
	const [error, setError] = useState('');
	const requests = useRef<Partial<Record<RegistrationDocumentSide, number>>>({});
	const option = DOCUMENT_OPTIONS.find(item => item.value === documentType) || DOCUMENT_OPTIONS[2];
	const requiredSlots = option.slots;
	const nationalIdFormat: RegistrationNationalIdFormat = documentType === 'national_id'
		? 'digital_image'
		: 'physical_card';
	const nameSlot: RegistrationDocumentSide = documentType === 'passport' ? 'biodata' : 'front';
	const nameResult = upload.results[nameSlot];
	const nameMatch: RegistrationNameMatchOutcome = nameResult?.outcome === 'plausible'
		? matchRegistrationName(firstName, lastName, nameResult.ocrText)
		: null;
	const decision = useMemo(() => screenRegistrationSubmission(
		documentType,
		upload.results,
		duplicateKind,
		nationalIdFormat,
		nameMatch,
	), [documentType, upload.results, duplicateKind, nationalIdFormat, nameMatch]);
	const isChecking = requiredSlots.some(slot => ['loading', 'recognizing'].includes(upload.statuses[slot] || ''));
	const isReady = requiredSlots.every(slot => upload.results[slot]?.outcome === 'plausible')
		&& !isChecking
		&& duplicateKind === 'none';

	useEffect(() => {
		if (!requiredSlots.includes('back')) {
			setDuplicateKind('none');
			return;
		}

		const front = upload.results.front?.imageFingerprint;
		const back = upload.results.back?.imageFingerprint;
		setDuplicateKind(front && back ? compareRegistrationImageFingerprints(front, back) : 'none');
	}, [requiredSlots, upload.results]);

	const reset = (nextDocumentType: string) => {
		setDocumentType(nextDocumentType);
		setUpload(emptyUploadState());
		setDuplicateKind('none');
		setError('');
		requests.current = {};
	};

	const handleFile = (slot: RegistrationDocumentSide, file: File | undefined) => {
		if (!file) return;
		const validationError = fileError(file);
		if (validationError) {
			setError(validationError);
			return;
		}

		const requestId = (requests.current[slot] || 0) + 1;
		requests.current[slot] = requestId;
		setError('');
		setUpload(previous => ({
			...previous,
			files: { ...previous.files, [slot]: file },
			results: Object.fromEntries(Object.entries(previous.results).filter(([key]) => key !== slot)),
			statuses: { ...previous.statuses, [slot]: 'loading' },
		}));

		void screenRegistrationDocumentSideFromFile(
			documentType,
			slot,
			file,
			stage => {
				if (requests.current[slot] === requestId) {
					setUpload(previous => ({ ...previous, statuses: { ...previous.statuses, [slot]: stage } }));
				}
			},
			nationalIdFormat,
		).then(result => {
			if (requests.current[slot] !== requestId) return;
			setUpload(previous => ({
				...previous,
				results: { ...previous.results, [slot]: result },
				statuses: {
					...previous.statuses,
					[slot]: result.outcome === 'plausible'
						? 'ready'
						: result.outcome === 'screening_error' ? 'error' : 'rejected',
				},
			}));
		});
	};

	const submit = () => {
		if (!isReady || !['screening_passed', 'manual_review_required'].includes(decision.outcome)) {
			setError(decision.message || 'Complete the image check before submitting.');
			return;
		}

		const primaryFile = upload.files.front || upload.files.biodata;
		if (!primaryFile) {
			setError('Upload the required ID image.');
			return;
		}

		const screeningMetadata = {
			document_type: documentType,
			national_id_format: nationalIdFormat,
			outcome: decision.outcome,
			duplicate_kind: duplicateKind,
			name_match: nameMatch === 'matched',
			sides: Object.fromEntries(requiredSlots.map(slot => {
				const result = upload.results[slot];
				return [slot, result ? {
					side: result.side,
					outcome: result.outcome,
					detected_document_family: result.detectedDocumentFamily,
					detected_anchor_keys: result.detectedAnchorKeys,
					confidence_band: result.confidenceBand,
					qr_detected: result.qrDetected,
					fingerprint: result.fingerprint,
				} : null];
			})),
		};

		const payload = new FormData();
		payload.append('document_type', documentType);
		payload.append('national_id_format', nationalIdFormat);
		payload.append('screening_metadata', JSON.stringify(screeningMetadata));
		payload.append('valid_id', primaryFile);
		if (upload.files.back) payload.append('valid_id_back', upload.files.back);

		setIsSubmitting(true);
		router.post('/customer-profile/identity-verifications/resubmit', payload, {
			forceFormData: true,
			preserveScroll: true,
			onSuccess: () => {
				setUpload(emptyUploadState());
				setError('');
				void Swal.fire({
					icon: 'success',
					title: 'ID submitted',
					text: 'Your replacement ID is pending admin review.',
					confirmButtonColor: '#16233b',
				});
			},
			onError: errors => {
				const message = errors.screening_metadata || errors.valid_id || errors.valid_id_back || 'The ID could not be submitted. Please try again.';
				setError(message);
				void Swal.fire({
					icon: 'error',
					title: 'Submission failed',
					text: message,
					confirmButtonColor: '#16233b',
				});
			},
			onFinish: () => setIsSubmitting(false),
		});
	};

	return (
		<div className="mt-4 space-y-4 rounded-2xl border border-red-200 bg-red-50/40 p-4">
			<div>
				<h4 className="text-sm font-semibold text-gray-900">Resubmit a replacement ID</h4>
				<p className="mt-1 text-xs leading-5 text-gray-600">
					The image check only screens for obvious problems. An admin still reviews every submission.
				</p>
			</div>
			<label className="block text-xs font-semibold text-gray-600">
				Document type
				<select
					value={documentType}
					onChange={event => reset(event.target.value)}
					className="mt-1 w-full rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm font-normal text-gray-900 focus:border-gray-500 focus:outline-none"
					disabled={isSubmitting}
				>
					{DOCUMENT_OPTIONS.map(item => <option key={item.value} value={item.value}>{item.label}</option>)}
				</select>
			</label>
			<p className="text-xs text-gray-500">{option.guidance}</p>
			<div className="grid gap-3 sm:grid-cols-2">
				{requiredSlots.map(slot => {
					const status = upload.statuses[slot] || 'idle';
					const result = upload.results[slot];
					const slotLabel = slot === 'biodata'
						? 'Passport biodata page'
						: slot.charAt(0).toUpperCase() + slot.slice(1) + ' image';
					return (
						<label key={slot} className="cursor-pointer rounded-xl border border-dashed border-gray-300 bg-white p-3 text-sm text-gray-700 hover:border-gray-500">
							<span className="block font-semibold">{slotLabel}</span>
							<span className="mt-1 block text-xs text-gray-500">
								{upload.files[slot]?.name || 'Choose image'}
							</span>
							<span className={'mt-2 block text-xs ' + (
								status === 'ready' ? 'text-emerald-600' : status === 'rejected' || status === 'error' ? 'text-red-600' : 'text-gray-500'
							)}>
								{status === 'loading' || status === 'recognizing'
									? 'Checking image (' + status + ')...'
									: result?.outcome === 'plausible' ? 'Image ready for review'
										: result?.validationNotes?.[0] || 'Not checked'}
							</span>
							<input
								type="file"
								accept={ACCEPT}
								className="sr-only"
								onChange={event => handleFile(slot, event.target.files?.[0])}
								disabled={isSubmitting}
							/>
						</label>
					);
				})}
			</div>
			{duplicateKind !== 'none' && <p className="text-xs text-red-600">The front and back images appear to be the same. Upload the actual back side.</p>}
			{isReady && decision.outcome === 'manual_review_required' && (
				<p className="text-xs text-amber-700">The image is acceptable but will need human review because the screening is uncertain.</p>
			)}
			{error && <p className="text-sm text-red-600">{error}</p>}
			<button
				type="button"
				onClick={submit}
				disabled={isSubmitting || !isReady}
				className="inline-flex items-center justify-center rounded-full bg-[#16233b] px-4 py-2.5 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:opacity-50"
			>
				{isSubmitting ? 'Submitting...' : 'Submit replacement ID'}
			</button>
		</div>
	);
};

const IdentityVerificationPanel: React.FC<Props> = ({ identityVerification, firstName, lastName }) => {
	const [showResubmission, setShowResubmission] = useState(false);

	if (!identityVerification) {
		return (
			<section className="rounded-2xl border border-gray-200 bg-white p-5">
				<h3 className="text-base font-semibold text-gray-900">Identity verification</h3>
				<p className="mt-2 text-sm text-gray-500">No identity document has been submitted yet.</p>
			</section>
		);
	}

	const current = identityVerification.current;

	return (
		<section className="rounded-2xl border border-gray-200 bg-white p-5">
			<div className="flex flex-wrap items-start justify-between gap-3">
				<div>
					<h3 className="text-base font-semibold text-gray-900">Identity verification</h3>
					<p className="mt-1 text-xs text-gray-500">Automated screening and admin approval are separate decisions.</p>
				</div>
				<IdentityStatus status={identityVerification.status} />
			</div>

			<div className="mt-4 grid gap-3 sm:grid-cols-2">
				<div className="rounded-xl bg-gray-50 p-3">
					<p className="text-xs uppercase tracking-wide text-gray-400">Document</p>
					<p className="mt-1 text-sm font-medium text-gray-900">{current.document_type ? statusLabel(current.document_type) : 'Not specified'}</p>
				</div>
				<div className="rounded-xl bg-gray-50 p-3">
					<p className="text-xs uppercase tracking-wide text-gray-400">Automated screening</p>
					<p className="mt-1 text-sm font-medium text-gray-900">{screeningLabel(current.screening_status)}</p>
				</div>
				<div className="rounded-xl bg-gray-50 p-3">
					<p className="text-xs uppercase tracking-wide text-gray-400">Admin review</p>
					<p className="mt-1 text-sm font-medium text-gray-900">{statusLabel(current.review_status)}</p>
				</div>
				<div className="rounded-xl bg-gray-50 p-3">
					<p className="text-xs uppercase tracking-wide text-gray-400">Submitted</p>
					<p className="mt-1 text-sm font-medium text-gray-900">{dateLabel(current.submitted_at)}</p>
				</div>
			</div>

			{current.review_status === 'rejected' && (
				<div className="mt-4 rounded-xl border border-red-200 bg-red-50 p-3 text-sm text-red-800">
					<p className="font-semibold">Why this was rejected</p>
					<p className="mt-1">{REJECTION_LABELS[current.rejection_reason || ''] || 'The submitted ID needs correction.'}</p>
					{current.rejection_notes && <p className="mt-1 text-sm">{current.rejection_notes}</p>}
				</div>
			)}

			<div className="mt-4 grid gap-3 sm:grid-cols-2">
				<IdentityImage url={current.front_url} label={current.document_type === 'passport' ? 'Submitted document' : 'Front image'} />
				{current.back_url && <IdentityImage url={current.back_url} label="Back image" />}
			</div>

			{identityVerification.can_resubmit && (
				<>
					<button
						type="button"
						onClick={() => setShowResubmission(previous => !previous)}
						className="mt-4 inline-flex rounded-full border border-[#16233b] px-4 py-2 text-sm font-semibold text-[#16233b] hover:bg-gray-50"
					>
						{showResubmission ? 'Hide resubmission form' : 'Resubmit valid ID'}
					</button>
					{showResubmission && <IdentityResubmission firstName={firstName} lastName={lastName} current={current} />}
				</>
			)}

			{identityVerification.history.length > 1 && (
				<details className="mt-5 border-t border-gray-100 pt-4">
					<summary className="cursor-pointer text-sm font-semibold text-gray-700">Previous submissions</summary>
					<div className="mt-3 space-y-2">
						{identityVerification.history.slice(1).map(record => (
							<div key={record.id} className="flex flex-wrap items-center justify-between gap-2 rounded-lg bg-gray-50 px-3 py-2 text-xs">
								<span className="text-gray-600">{statusLabel(record.document_type || 'identity document')} - {dateLabel(record.submitted_at)}</span>
								<span className="font-semibold text-gray-700">{statusLabel(record.review_status)}</span>
							</div>
						))}
					</div>
				</details>
			)}
		</section>
	);
};

export default IdentityVerificationPanel;
