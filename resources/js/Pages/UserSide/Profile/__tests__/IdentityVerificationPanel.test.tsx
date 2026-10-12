import type { ReactNode } from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

const mocks = vi.hoisted(() => ({
	compareRegistrationImageFingerprints: vi.fn(),
	screenRegistrationDocumentSideFromFile: vi.fn(),
	screenRegistrationSubmission: vi.fn(() => ({
		documentType: 'drivers_license',
		outcome: 'reject_upload',
		duplicateKind: 'none',
	})),
}));

vi.mock('@inertiajs/react', () => ({ router: { post: vi.fn() } }));
vi.mock('@/components/form/Select', () => ({
	default: ({ children, ...props }: { children?: ReactNode; [key: string]: unknown }) => (
		<select {...props}>{children}</select>
	),
}));
vi.mock('@/Pages/UserSide/Shared/UserModal', () => ({ default: { fire: vi.fn() } }));
vi.mock('../../Auth/registrationOcr', () => ({
	compareRegistrationImageFingerprints: mocks.compareRegistrationImageFingerprints,
}));
vi.mock('../../Auth/registrationDocumentScreening', () => ({
	screenRegistrationDocumentSideFromFile: mocks.screenRegistrationDocumentSideFromFile,
	screenRegistrationSubmission: mocks.screenRegistrationSubmission,
}));

import IdentityVerificationPanel from '../IdentityVerificationPanel';

describe('IdentityVerificationPanel', () => {
	it('shows Invalid picture instead of the internal rejection code', async () => {
		mocks.screenRegistrationDocumentSideFromFile.mockResolvedValue({
			side: 'front',
			outcome: 'reject_upload',
			ocrText: '',
			ocrConfidence: 0,
			detectedDocumentFamily: 'drivers_license',
			detectedAnchorKeys: [],
			confidenceBand: 'low',
			qrDetected: false,
			fingerprint: null,
			imageFingerprint: null,
			validationNotes: ['missing_philippine_driver_anchors'],
		});

		render(
			<IdentityVerificationPanel
				firstName="Juan"
				lastName="Dela Cruz"
				identityVerification={{
					status: 'rejected',
					can_resubmit: true,
					current: {
						id: 1,
						document_type: 'drivers_license',
						screening_status: 'rejected',
						review_status: 'rejected',
						front_url: null,
						back_url: null,
					},
					history: [],
				}}
			/>,
		);

		fireEvent.click(screen.getByRole('button', { name: 'Resubmit valid ID' }));
		const input = document.querySelector('input[type="file"]') as HTMLInputElement;
		fireEvent.change(input, {
			target: { files: [new File(['invalid'], 'school-id.jpg', { type: 'image/jpeg' })] },
		});

		await waitFor(() => expect(screen.getByText('Invalid picture', { exact: true })).toBeInTheDocument());
		expect(screen.queryByText('missing_philippine_driver_anchors', { exact: true })).not.toBeInTheDocument();
	});
});
