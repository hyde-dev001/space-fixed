import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

const pageSource = readFileSync(
	resolve('resources/js/Pages/UserSide/Profile/VirtualShowroomPage.tsx'),
	'utf8',
);
const showroomSource = readFileSync(
	resolve('resources/js/Pages/UserSide/Products/VirtualShowroom.tsx'),
	'utf8',
);

describe('standalone virtual showroom', () => {
	it('does not mount shared customer navigation', () => {
		expect(pageSource).not.toContain("import Navigation from '../Shared/Navigation';");
		expect(pageSource).not.toContain('{!isFocusMode && <Navigation />}');
		expect(pageSource).toContain('Back to Shop Profile');
	});

	it('uses dynamic viewport height for the standalone layout', () => {
		expect(pageSource).toContain('className="h-dvh overflow-hidden bg-white"');
		expect(pageSource).toContain('<main className="h-dvh">');
		expect(showroomSource).toContain("? 'h-dvh w-full bg-white'");
		expect(showroomSource).toContain("isStandalonePage ? 'h-dvh min-h-0'");
	});

	it('declares movement cleanup before starting the render loop', () => {
		const movementCleanup = showroomSource.indexOf('const clearMovementKeys = () => {');
		const firstRenderLoopInvocation = showroomSource.indexOf('\n\t\tanimate();');

		expect(movementCleanup).toBeGreaterThan(-1);
		expect(firstRenderLoopInvocation).toBeGreaterThan(-1);
		expect(movementCleanup).toBeLessThan(firstRenderLoopInvocation);
	});

	it('separates standalone controls on portrait screens', () => {
		expect(pageSource).toContain('className="fixed left-3 top-3 z-50 sm:left-20 sm:top-4"');
		expect(showroomSource).toContain('absolute left-3 top-16 z-20');
		expect(showroomSource).toContain('sm:left-auto sm:right-3 sm:top-3');
	});
});
