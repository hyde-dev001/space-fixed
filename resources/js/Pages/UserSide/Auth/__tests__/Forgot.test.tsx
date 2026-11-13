import type { ReactNode } from 'react';
import { act, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const { routerOnMock, swalFireMock } = vi.hoisted(() => ({
	routerOnMock: vi.fn(() => vi.fn()),
	swalFireMock: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({
	Head: () => null,
	Link: ({ children, href, ...props }: { children?: ReactNode; href?: string; [key: string]: unknown }) => (
		<a href={href} {...props}>{children}</a>
	),
	router: {
		on: routerOnMock,
	},
}));

vi.mock('../../Shared/Navigation', () => ({ default: () => null }));
vi.mock('@/icons/index', () => ({ MailIcon: () => <span aria-hidden="true" /> }));
vi.mock('@/Pages/UserSide/Shared/UserModal', () => ({
	default: { fire: swalFireMock },
}));

import Forgot from '../Forgot';

beforeEach(() => {
	routerOnMock.mockClear();
	swalFireMock.mockReset();
	(globalThis as { route?: (name: string) => string }).route = (name: string) => ({
		'password.otp.send': '/forgot-password/otp',
		'user.login.form': '/login',
	})[name] ?? `/${name}`;
});

describe('forgot password rate limiting', () => {
	it('shows a SweetAlert and prevents the default Inertia error page for 429 responses', () => {
		render(<Forgot />);

		const invalidHandler = routerOnMock.mock.calls.find(([eventName]) => eventName === 'invalid')?.[1] as
			((event: CustomEvent) => void) | undefined;
		const preventDefault = vi.fn();

		expect(invalidHandler).toEqual(expect.any(Function));
		act(() => {
			invalidHandler?.({
				detail: { response: { status: 429 } },
				preventDefault,
			} as unknown as CustomEvent);
		});

		expect(preventDefault).toHaveBeenCalledOnce();
		expect(swalFireMock).toHaveBeenCalledWith(expect.objectContaining({
			icon: 'warning',
			title: 'Too many requests',
		}));
		expect(screen.getByRole('button', { name: /send code/i })).toBeInTheDocument();
	});
});
