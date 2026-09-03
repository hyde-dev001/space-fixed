import React from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import IdentityReviewQueue from '../Index';

const { fetchMock, getMock, reloadMock, swalFireMock } = vi.hoisted(() => ({
	fetchMock: vi.fn(),
	getMock: vi.fn(),
	reloadMock: vi.fn(),
	swalFireMock: vi.fn(),
}));

vi.stubGlobal('fetch', fetchMock);

vi.mock('@inertiajs/react', () => ({
	Head: ({ children }: { children?: React.ReactNode }) => <>{children}</>,
	Link: ({ children, href, ...props }: { children?: React.ReactNode; href?: string; className?: string }) => <a href={href} {...props}>{children}</a>,
	router: { get: getMock, reload: reloadMock },
}));

vi.mock('sweetalert2', () => ({
	default: { fire: swalFireMock },
}));

vi.mock('../../../../layout/AppLayout', () => ({
	default: ({ children }: { children?: React.ReactNode }) => <div>{children}</div>,
}));

const review = (overrides: Record<string, unknown> = {}) => ({
	id: 1,
	user_id: 20,
	customer: { id: 20, name: 'John Daniel Paragas', email: 'john@example.test' },
	document_type: 'national_id',
	screening_status: 'manual_review_required',
	screening_label: 'Needs review',
	review_status: 'pending',
	failure_reason: 'name_unreadable_or_mismatch',
	rejection_reason: null,
	rejection_notes: null,
	inspected_at: '2026-09-03T09:00:00.000Z',
	reviewed_at: null,
	submitted_at: '2026-09-03T08:00:00.000Z',
	front_url: '/documents/front.jpg',
	back_url: '/documents/back.jpg',
	...overrides,
});

const props = (rows = [review(), review({
	id: 2,
	user_id: 21,
	customer: { id: 21, name: 'Jane Customer', email: 'jane@example.test' },
	inspected_at: null,
})]) => ({
	reviews: {
		data: rows,
		current_page: 1,
		last_page: 1,
		from: rows.length ? 1 : null,
		to: rows.length,
		total: rows.length,
	},
	stats: { total: rows.length, pending: rows.length, approved: 0, rejected: 0, screening_passed: 0, needs_review: rows.length },
	filters: { q: null, screening: 'all', status: 'pending' },
});

beforeEach(() => {
	fetchMock.mockReset();
	getMock.mockReset();
	reloadMock.mockReset();
	swalFireMock.mockReset();
	swalFireMock.mockResolvedValue({ isConfirmed: true });
	fetchMock.mockResolvedValue({
		ok: true,
		json: async () => ({ identity_verification: { inspected_at: '2026-09-03T09:00:00.000Z' } }),
	});
});

describe('identity review queue controls', () => {
	it('uses the Super Admin primary styling and selects every reviewed row on the page', () => {
		render(<IdentityReviewQueue {...props()} />);

		expect(screen.getByRole('button', { name: 'Apply filters' })).toHaveClass('bg-gray-900');
		expect(screen.getByRole('button', { name: 'Approve 0 reviewed' })).toHaveClass('bg-gray-900');

		const selectAll = screen.getByRole('checkbox', { name: 'Select all reviewed submissions' });
		fireEvent.click(selectAll);

		expect(screen.getByRole('checkbox', { name: 'Select John Daniel Paragas' })).toBeChecked();
		expect(screen.getByRole('checkbox', { name: 'Select Jane Customer' })).not.toBeChecked();
		expect(screen.getByRole('button', { name: 'Approve 1 reviewed' })).toBeEnabled();
	});

	it('refreshes the queue after a pending record is inspected', async () => {
		render(<IdentityReviewQueue {...props([review({ inspected_at: null })])} />);

		fireEvent.click(screen.getByRole('button', { name: 'Open review' }));

		await waitFor(() => expect(fetchMock).toHaveBeenCalledWith(
			'/admin/users/20/identity-verifications/1/inspect',
			expect.objectContaining({ method: 'POST' }),
		));
		await waitFor(() => expect(reloadMock).toHaveBeenCalledWith({
			only: ['reviews'],
			preserveState: true,
			preserveScroll: true,
		}));
	});

	it('opens submitted ID images in a higher-layer preview modal', () => {
		render(<IdentityReviewQueue {...props([review()])} />);

		fireEvent.click(screen.getByRole('button', { name: 'Open review' }));
		fireEvent.click(screen.getByRole('button', { name: 'View submitted ID front' }));

		expect(screen.getByRole('dialog', { name: 'Submitted ID front preview' })).toHaveClass('z-[100001]');
		expect(screen.getByRole('img', { name: 'Submitted ID front preview' })).toHaveAttribute('src', '/documents/front.jpg');
	});

	it('keeps the review dialog above the shared header layer', () => {
		render(<IdentityReviewQueue {...props([review()])} />);

		fireEvent.click(screen.getByRole('button', { name: 'Open review' }));

		expect(screen.getByRole('dialog', { name: 'Identity review' })).toHaveClass('z-[100000]');
	});
});
