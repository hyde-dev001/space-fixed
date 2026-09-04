import React, { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import { AlertTriangle, CheckCircle2, Clock3, Files, ScanSearch, XCircle } from 'lucide-react';
import Swal from 'sweetalert2';
import AppLayout from '../../../layout/AppLayout';

type Review = {
	id: number;
	user_id: number;
	customer: {
		id: number;
		name: string;
		email: string;
	};
	document_type: string | null;
	screening_status: string;
	screening_label: string;
	review_status: string;
	failure_reason?: string | null;
	rejection_reason?: string | null;
	rejection_notes?: string | null;
	inspected_at?: string | null;
	reviewed_at?: string | null;
	submitted_at?: string | null;
	front_url: string | null;
	back_url: string | null;
};

type Props = {
	reviews: {
		data: Review[];
		current_page: number;
		last_page: number;
		from: number | null;
		to: number | null;
		total: number;
	};
	stats: {
		total: number;
		pending: number;
		approved: number;
		rejected: number;
		screening_passed: number;
		needs_review: number;
	};
	filters: {
		q?: string | null;
		screening?: string;
		status?: string;
	};
};

type ImagePreview = {
	url: string;
	alt: string;
};

const REJECTION_REASONS: Array<[string, string]> = [
	['id_unreadable', 'ID is unreadable'],
	['wrong_document', 'Wrong document submitted'],
	['incomplete_details', 'ID details are incomplete'],
	['suspected_altered', 'Suspected altered/fake document'],
	['front_back_mismatch', 'Front/back do not match'],
	['other', 'Other'],
];

const label = (value: string | null | undefined): string => (
	(value || 'N/A').replaceAll('_', ' ').replace(/\b\w/g, character => character.toUpperCase())
);

const dateLabel = (value?: string | null): string => {
	if (!value) return 'N/A';
	const date = new Date(value);
	return Number.isNaN(date.getTime()) ? value : date.toLocaleString();
};

const statusClass = (status: string): string => {
	if (status === 'approved') return 'bg-emerald-100 text-emerald-700';
	if (status === 'rejected') return 'bg-red-100 text-red-700';
	if (status === 'manual_review_required') return 'bg-orange-100 text-orange-700';
	return 'bg-amber-100 text-amber-700';
};

type MetricTone = 'info' | 'warning' | 'success' | 'danger';

type ReviewMetric = {
	title: string;
	value: number;
	description: string;
	icon: React.ComponentType<{ className?: string }>;
	tone: MetricTone;
};

const metricToneClasses: Record<MetricTone, { gradient: string }> = {
	info: { gradient: 'from-blue-500 to-indigo-600' },
	warning: { gradient: 'from-yellow-500 to-orange-600' },
	success: { gradient: 'from-green-500 to-emerald-600' },
	danger: { gradient: 'from-red-500 to-rose-600' },
};

const ReviewMetricCard: React.FC<ReviewMetric> = ({ title, value, description, icon: Icon, tone }) => {
	const colors = metricToneClasses[tone];
	return (
		<div className="group relative overflow-hidden rounded-2xl border border-gray-200 bg-white p-6 shadow-sm transition-all duration-300 hover:-translate-y-0.5 hover:border-gray-300 hover:shadow-md dark:border-gray-800 dark:bg-gray-800">
			<div className={'absolute inset-0 bg-gradient-to-br ' + colors.gradient + ' opacity-0 transition-opacity duration-300 group-hover:opacity-5'} />
			<div className="relative">
				<div className="flex items-center justify-between gap-3">
					<div className={'flex h-12 w-12 items-center justify-center rounded-2xl bg-gradient-to-br ' + colors.gradient + ' shadow-lg'}>
						<Icon className="h-6 w-6 text-white" />
					</div>
				</div>
				<div className="mt-5 space-y-1">
					<p className="text-sm font-medium text-gray-600 dark:text-gray-400">{title}</p>
					<p className="text-3xl font-bold text-gray-900 dark:text-white">{value.toLocaleString()}</p>
					<p className="text-xs text-gray-500 dark:text-gray-400">{description}</p>
				</div>
			</div>
		</div>
	);
};

const readJson = async (response: Response): Promise<Record<string, any>> => {
	const payload = await response.json().catch(() => null);
	if (!payload || typeof payload !== 'object') {
		throw new Error('The identity review operation could not be completed.');
	}
	if (!response.ok) {
		const message = typeof payload.message === 'string'
			? payload.message
			: Object.values(payload.errors || {}).flat().join(' ');
		throw new Error(message || 'The identity review operation could not be completed.');
	}
	return payload;
};

const postJson = async (url: string, body: Record<string, unknown> = {}) => {
	const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
	const response = await fetch(url, {
		method: 'POST',
		credentials: 'same-origin',
		headers: {
			Accept: 'application/json',
			'Content-Type': 'application/json',
			'X-CSRF-TOKEN': csrf,
			'X-Requested-With': 'XMLHttpRequest',
		},
		body: JSON.stringify(body),
	});
	return readJson(response);
};

const IdentityReviewQueue: React.FC<Props> = ({ reviews, stats, filters }) => {
	const [search, setSearch] = useState(filters.q || '');
	const [selected, setSelected] = useState<Review | null>(null);
	const [selectedIds, setSelectedIds] = useState<number[]>([]);
	const [busyId, setBusyId] = useState<number | null>(null);
	const [isBulkBusy, setIsBulkBusy] = useState(false);
	const [imagePreview, setImagePreview] = useState<ImagePreview | null>(null);
	const eligibleIds = reviews.data
		.filter(review => review.review_status === 'pending' && Boolean(review.inspected_at))
		.map(review => review.id);
	const allEligibleSelected = eligibleIds.length > 0 && eligibleIds.every(id => selectedIds.includes(id));

	const metricCards: ReviewMetric[] = [
		{ title: 'Pending', value: stats.pending, description: 'Awaiting human review', icon: Clock3, tone: 'warning' },
		{ title: 'Screening passed', value: stats.screening_passed, description: 'Plausibility signal', icon: ScanSearch, tone: 'info' },
		{ title: 'Needs review', value: stats.needs_review, description: 'Requires reviewer attention', icon: AlertTriangle, tone: 'warning' },
		{ title: 'Approved', value: stats.approved, description: 'Full customer access', icon: CheckCircle2, tone: 'success' },
		{ title: 'Rejected', value: stats.rejected, description: 'Available for resubmission', icon: XCircle, tone: 'danger' },
		{ title: 'All submissions', value: stats.total, description: 'All submitted IDs', icon: Files, tone: 'info' },
	];

	const applyFilters = (event?: React.FormEvent) => {
		event?.preventDefault();
		router.get('/admin/identity-verification-reviews', {
			q: search || undefined,
			screening: filters.screening || 'all',
			status: filters.status || 'pending',
		}, {
			preserveState: true,
			preserveScroll: true,
			replace: true,
		});
	};

	const reloadQueue = () => {
		router.get('/admin/identity-verification-reviews', {
			q: filters.q || undefined,
			screening: filters.screening || 'all',
			status: filters.status || 'pending',
			page: reviews.current_page,
		}, {
			preserveState: true,
			preserveScroll: true,
			replace: true,
		});
	};

	const openReview = async (review: Review) => {
		setSelected(review);
		if (review.review_status !== 'pending' || review.inspected_at) return;

		try {
			setBusyId(review.id);
			const payload = await postJson('/admin/users/' + review.user_id + '/identity-verifications/' + review.id + '/inspect');
			const inspectedAt = payload.identity_verification?.inspected_at || new Date().toISOString();
			setSelected(previous => previous?.id === review.id ? { ...previous, inspected_at: inspectedAt } : previous);
			router.reload({ only: ['reviews'], preserveState: true, preserveScroll: true });
		} catch (error) {
			const message = error instanceof Error ? error.message : 'The review could not be opened.';
			void Swal.fire({ icon: 'error', title: 'Unable to open review', text: message, confirmButtonColor: '#16233b' });
		} finally {
			setBusyId(null);
		}
	};

	const confirmApprove = async (review: Review) => {
		const confirmation = await Swal.fire({
			title: 'Approve identity verification?',
			text: 'This records the human review and enables transaction access for this customer.',
			icon: 'question',
			showCancelButton: true,
			confirmButtonText: 'Approve',
			cancelButtonText: 'Cancel',
			confirmButtonColor: '#111827',
		});
		if (!confirmation.isConfirmed) return;

		try {
			setBusyId(review.id);
			await postJson('/admin/users/' + review.user_id + '/identity-verifications/' + review.id + '/approve');
			setSelected(null);
			setImagePreview(null);
			await Swal.fire({ icon: 'success', title: 'Review approved', text: 'The customer now has transaction access.', timer: 1800, showConfirmButton: false });
			reloadQueue();
		} catch (error) {
			const message = error instanceof Error ? error.message : 'The approval could not be completed.';
			void Swal.fire({ icon: 'error', title: 'Approval failed', text: message, confirmButtonColor: '#16233b' });
		} finally {
			setBusyId(null);
		}
	};

	const confirmReject = async (review: Review) => {
		const result = await Swal.fire({
			title: 'Reject identity verification',
			html: [
				'<label for="identity-rejection-reason" style="display:block;text-align:left;margin-bottom:6px;font-weight:600">Reason</label>',
				'<select id="identity-rejection-reason" class="swal2-select" style="width:100%;margin:0 0 14px">',
				'<option value="">Select a reason</option>',
				REJECTION_REASONS.map(([value, text]) => '<option value="' + value + '">' + text + '</option>').join(''),
				'</select>',
				'<label for="identity-rejection-notes" style="display:block;text-align:left;margin-bottom:6px;font-weight:600">Notes</label>',
				'<textarea id="identity-rejection-notes" class="swal2-textarea" style="width:100%;margin:0" rows="3" placeholder="Add helpful detail for the customer"></textarea>',
			].join(''),
			focusConfirm: false,
			showCancelButton: true,
			confirmButtonText: 'Reject verification',
			cancelButtonText: 'Cancel',
			confirmButtonColor: '#dc2626',
			preConfirm: () => {
				const reason = (document.getElementById('identity-rejection-reason') as HTMLSelectElement | null)?.value || '';
				const notes = (document.getElementById('identity-rejection-notes') as HTMLTextAreaElement | null)?.value.trim() || '';
				if (!reason) {
					Swal.showValidationMessage('Select a rejection reason.');
					return null;
				}
				if (reason === 'other' && !notes) {
					Swal.showValidationMessage('Add a note when selecting Other.');
					return null;
				}
				return { rejection_reason: reason, rejection_notes: notes };
			},
		});
		if (!result.isConfirmed || !result.value) return;

		try {
			setBusyId(review.id);
			await postJson('/admin/users/' + review.user_id + '/identity-verifications/' + review.id + '/reject', result.value);
			setSelected(null);
			setImagePreview(null);
			await Swal.fire({ icon: 'success', title: 'Review rejected', text: 'The customer can see the reason and resubmit a new ID.', timer: 1800, showConfirmButton: false });
			reloadQueue();
		} catch (error) {
			const message = error instanceof Error ? error.message : 'The rejection could not be completed.';
			void Swal.fire({ icon: 'error', title: 'Rejection failed', text: message, confirmButtonColor: '#16233b' });
		} finally {
			setBusyId(null);
		}
	};

	const toggleSelected = (review: Review) => {
		setSelectedIds(previous => previous.includes(review.id)
			? previous.filter(id => id !== review.id)
			: [...previous, review.id]);
	};

	const toggleAllEligible = () => {
		setSelectedIds(previous => allEligibleSelected
			? previous.filter(id => !eligibleIds.includes(id))
			: Array.from(new Set([...previous, ...eligibleIds])));
	};

	const confirmBulkApprove = async () => {
		if (selectedIds.length === 0) return;
		const confirmation = await Swal.fire({
			title: 'Approve ' + selectedIds.length + ' reviewed records?',
			text: 'Only records that a reviewer has opened are eligible for this action.',
			icon: 'question',
			showCancelButton: true,
			confirmButtonText: 'Approve reviewed records',
			cancelButtonText: 'Cancel',
			confirmButtonColor: '#111827',
		});
		if (!confirmation.isConfirmed) return;

		try {
			setIsBulkBusy(true);
			await postJson('/admin/identity-verification-reviews/bulk-approve', { verification_ids: selectedIds });
			setSelectedIds([]);
			await Swal.fire({ icon: 'success', title: 'Bulk approval complete', text: 'All selected reviewed records were approved.', timer: 1800, showConfirmButton: false });
			reloadQueue();
		} catch (error) {
			const message = error instanceof Error ? error.message : 'The bulk approval could not be completed.';
			void Swal.fire({ icon: 'error', title: 'Bulk approval failed', text: message, confirmButtonColor: '#16233b' });
		} finally {
			setIsBulkBusy(false);
		}
	};

	return (
		<AppLayout>
			<Head title="Identity Verification Reviews" />
			<div className="min-h-screen bg-gray-50 p-6 dark:bg-gray-900">
				<div className="mx-auto max-w-7xl space-y-6">
					<div className="flex flex-wrap items-start justify-between gap-4">
						<div>
							<p className="text-sm font-semibold uppercase tracking-wide text-gray-500">Admin review queue</p>
							<h1 className="mt-1 text-3xl font-bold text-gray-900 dark:text-white">Identity Verification Reviews</h1>
							<p className="mt-2 max-w-2xl text-sm text-gray-600 dark:text-gray-300">Every submission needs a human decision. Automated screening is only a plausibility signal.</p>
						</div>
						<Link href="/admin/users" className="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">User Management</Link>
					</div>

					<div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
						{metricCards.map(metric => <ReviewMetricCard key={metric.title} {...metric} />)}
					</div>

					<form onSubmit={applyFilters} className="flex flex-wrap items-end gap-3 rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
						<label className="min-w-60 flex-1 text-xs font-semibold uppercase tracking-wide text-gray-500">
							Search customer
							<input value={search} onChange={event => setSearch(event.target.value)} placeholder="Name, email, or phone" className="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm font-normal text-gray-900 focus:border-gray-500 focus:outline-none" />
						</label>
						<label className="text-xs font-semibold uppercase tracking-wide text-gray-500">
							Screening
							<select value={filters.screening || 'all'} onChange={event => router.get('/admin/identity-verification-reviews', { q: filters.q || undefined, screening: event.target.value, status: filters.status || 'pending' }, { preserveState: true, preserveScroll: true, replace: true })} className="mt-1 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-normal text-gray-900">
								<option value="all">All screening</option>
								<option value="passed">Screening passed</option>
								<option value="needs_review">Needs review</option>
							</select>
						</label>
						<label className="text-xs font-semibold uppercase tracking-wide text-gray-500">
							Human review
							<select value={filters.status || 'pending'} onChange={event => router.get('/admin/identity-verification-reviews', { q: filters.q || undefined, screening: filters.screening || 'all', status: event.target.value }, { preserveState: true, preserveScroll: true, replace: true })} className="mt-1 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-normal text-gray-900">
								<option value="pending">Pending</option>
								<option value="approved">Approved</option>
								<option value="rejected">Rejected</option>
								<option value="all">All statuses</option>
							</select>
						</label>
						<button type="submit" className="rounded-lg bg-gray-900 px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-gray-800 focus:outline-none focus:ring-2 focus:ring-gray-500">Apply filters</button>
					</form>

					<div className="flex flex-wrap items-center justify-between gap-3">
						<p className="text-sm text-gray-600">Showing {reviews.from || 0} - {reviews.to || 0} of {reviews.total} submissions</p>
						<button type="button" onClick={confirmBulkApprove} disabled={isBulkBusy || selectedIds.length === 0} className="rounded-lg bg-gray-900 px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-gray-800 focus:outline-none focus:ring-2 focus:ring-gray-500 disabled:cursor-not-allowed disabled:opacity-50">
							{isBulkBusy ? 'Approving...' : 'Approve ' + selectedIds.length + ' reviewed'}
						</button>
					</div>

					<div className="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
						<div className="overflow-x-auto">
							<table className="min-w-full divide-y divide-gray-200">
								<thead className="bg-gray-50">
									<tr>
										<th className="w-12 px-4 py-3">
											<input type="checkbox" aria-label="Select all reviewed submissions" checked={allEligibleSelected} onChange={toggleAllEligible} disabled={eligibleIds.length === 0 || isBulkBusy} className="h-4 w-4 rounded border-gray-300 text-gray-900 focus:ring-gray-500 disabled:cursor-not-allowed" />
										</th>
										{['Customer', 'Automated screening', 'Submitted', 'Human review', 'Action'].map(title => <th key={title} className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">{title}</th>)}
									</tr>
								</thead>
								<tbody className="divide-y divide-gray-100">
									{reviews.data.length === 0 && (
										<tr><td colSpan={6} className="px-4 py-12 text-center text-sm text-gray-500">No identity submissions match these filters.</td></tr>
									)}
									{reviews.data.map(review => {
										const eligible = review.review_status === 'pending' && Boolean(review.inspected_at);
										return (
											<tr key={review.id} className="align-top hover:bg-gray-50">
												<td className="px-4 py-4">
													<input type="checkbox" aria-label={'Select ' + review.customer.name} checked={selectedIds.includes(review.id)} onChange={() => toggleSelected(review)} disabled={!eligible || isBulkBusy} className="h-4 w-4 rounded border-gray-300 text-gray-900 focus:ring-gray-500 disabled:cursor-not-allowed" />
												</td>
												<td className="px-4 py-4">
													<p className="font-semibold text-gray-900">{review.customer.name}</p>
													<p className="mt-1 text-xs text-gray-500">{review.customer.email}</p>
													<p className="mt-1 text-xs text-gray-500">{label(review.document_type)}</p>
												</td>
												<td className="px-4 py-4">
													<span className={'inline-flex rounded-full px-2.5 py-1 text-xs font-semibold ' + statusClass(review.screening_status)}>{review.screening_label}</span>
												</td>
												<td className="px-4 py-4 text-sm text-gray-600">{dateLabel(review.submitted_at)}</td>
												<td className="px-4 py-4">
													<span className={'inline-flex rounded-full px-2.5 py-1 text-xs font-semibold ' + statusClass(review.review_status)}>{label(review.review_status)}</span>
													{review.inspected_at && <p className="mt-1 text-xs text-emerald-600">Inspected {dateLabel(review.inspected_at)}</p>}
												</td>
												<td className="px-4 py-4">
													<button type="button" onClick={() => openReview(review)} disabled={busyId === review.id} className="rounded-lg border border-gray-300 px-3 py-2 text-xs font-semibold text-gray-900 transition-colors hover:border-gray-900 hover:bg-gray-900 hover:text-white focus:outline-none focus:ring-2 focus:ring-gray-500 disabled:opacity-50">
														{busyId === review.id ? 'Opening...' : 'Open review'}
													</button>
												</td>
											</tr>
										);
									})}
								</tbody>
							</table>
						</div>
					</div>

					{reviews.last_page > 1 && (
						<div className="flex items-center justify-between text-sm text-gray-600">
							<span>Page {reviews.current_page} of {reviews.last_page}</span>
							<div className="flex gap-2">
								{reviews.current_page > 1 && <button type="button" onClick={() => router.get('/admin/identity-verification-reviews', { ...filters, page: reviews.current_page - 1 }, { preserveState: true, preserveScroll: true, replace: true })} className="rounded-lg border border-gray-300 bg-white px-3 py-2 hover:bg-gray-50">Previous</button>}
								{reviews.current_page < reviews.last_page && <button type="button" onClick={() => router.get('/admin/identity-verification-reviews', { ...filters, page: reviews.current_page + 1 }, { preserveState: true, preserveScroll: true, replace: true })} className="rounded-lg border border-gray-300 bg-white px-3 py-2 hover:bg-gray-50">Next</button>}
							</div>
						</div>
					)}
				</div>
			</div>

			{selected && (
				<div className="fixed inset-0 z-[100000] flex items-center justify-center bg-black/60 p-4 pointer-events-auto" role="dialog" aria-modal="true" aria-label="Identity review">
					<div className="max-h-[90vh] w-full max-w-4xl overflow-y-auto rounded-2xl bg-white p-6 shadow-2xl">
						<div className="flex items-start justify-between gap-4">
							<div>
								<p className="text-xs font-semibold uppercase tracking-wide text-gray-500">Customer information</p>
								<h2 className="mt-1 text-2xl font-bold text-gray-900">{selected.customer.name}</h2>
								<p className="mt-1 text-sm text-gray-500">{selected.customer.email}</p>
							</div>
							<button type="button" onClick={() => { setSelected(null); setImagePreview(null); }} className="rounded-full px-3 py-1 text-2xl leading-none text-gray-500 hover:bg-gray-100" aria-label="Close review">Close</button>
						</div>

						<div className="mt-6 grid gap-4 lg:grid-cols-[1fr_2fr]">
							<div className="space-y-3">
								<div className="rounded-xl bg-gray-50 p-4">
									<p className="text-xs font-semibold uppercase tracking-wide text-gray-500">Automated screening</p>
									<p className="mt-2 text-sm font-semibold text-gray-900">{selected.screening_label}</p>
									<p className="mt-1 text-xs text-gray-500">Document: {label(selected.document_type)}</p>
									{selected.failure_reason && <p className="mt-1 text-xs text-gray-500">Flag: {label(selected.failure_reason)}</p>}
								</div>
								<div className="rounded-xl bg-gray-50 p-4">
									<p className="text-xs font-semibold uppercase tracking-wide text-gray-500">Human review</p>
									<p className="mt-2 text-sm font-semibold text-gray-900">{label(selected.review_status)}</p>
									<p className="mt-1 text-xs text-gray-500">{selected.inspected_at ? 'Opened by a reviewer.' : 'Opening this record marks it inspected.'}</p>
								</div>
								{selected.review_status === 'rejected' && (
									<div className="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800">
										<p className="font-semibold">Rejection reason</p>
										<p className="mt-1">{label(selected.rejection_reason)}</p>
										{selected.rejection_notes && <p className="mt-1">{selected.rejection_notes}</p>}
									</div>
								)}
							</div>
							<div className="grid gap-4 sm:grid-cols-2">
								{selected.front_url && (
									<button type="button" aria-label="View submitted ID front" title="View full image" onClick={() => setImagePreview({ url: selected.front_url || '', alt: 'Submitted ID front' })} className="group block w-full cursor-zoom-in rounded-xl border border-gray-200 bg-gray-50 p-2 text-left transition-colors hover:border-gray-900 focus:outline-none focus:ring-2 focus:ring-gray-900">
										<img src={selected.front_url} alt="Submitted ID front" className="max-h-[26rem] w-full rounded-xl object-contain transition-transform duration-200 group-hover:scale-[1.02]" />
									</button>
								)}
								{selected.back_url && (
									<button type="button" aria-label="View submitted ID back" title="View full image" onClick={() => setImagePreview({ url: selected.back_url || '', alt: 'Submitted ID back' })} className="group block w-full cursor-zoom-in rounded-xl border border-gray-200 bg-gray-50 p-2 text-left transition-colors hover:border-gray-900 focus:outline-none focus:ring-2 focus:ring-gray-900">
										<img src={selected.back_url} alt="Submitted ID back" className="max-h-[26rem] w-full rounded-xl object-contain transition-transform duration-200 group-hover:scale-[1.02]" />
									</button>
								)}
							</div>
						</div>

						{selected.review_status === 'pending' && (
							<div className="mt-6 flex flex-wrap justify-end gap-3 border-t border-gray-200 pt-4">
								<button type="button" onClick={() => confirmReject(selected)} disabled={busyId === selected.id} className="rounded-lg bg-red-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-red-700 disabled:opacity-50">Reject</button>
								<button type="button" onClick={() => confirmApprove(selected)} disabled={busyId === selected.id} className="rounded-lg bg-gray-900 px-4 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-gray-800 focus:outline-none focus:ring-2 focus:ring-gray-500 disabled:opacity-50">Approve</button>
							</div>
						)}
					</div>
				</div>
			)}
			{imagePreview && (
				<div className="fixed inset-0 z-[100001] flex items-center justify-center bg-black/80 p-4" role="dialog" aria-modal="true" aria-label={imagePreview.alt + ' preview'} onClick={() => setImagePreview(null)}>
					<div className="relative flex max-h-[95vh] max-w-[95vw] items-center justify-center rounded-2xl bg-white p-3 shadow-2xl" onClick={event => event.stopPropagation()}>
						<img src={imagePreview.url} alt={imagePreview.alt + ' preview'} className="max-h-[88vh] max-w-[90vw] rounded-xl object-contain" />
						<button type="button" onClick={() => setImagePreview(null)} className="absolute right-5 top-5 rounded-lg bg-gray-900 px-3 py-2 text-sm font-semibold text-white hover:bg-gray-800 focus:outline-none focus:ring-2 focus:ring-gray-500" aria-label="Close image preview">Close</button>
					</div>
				</div>
			)}
		</AppLayout>
	);
};

export default IdentityReviewQueue;
