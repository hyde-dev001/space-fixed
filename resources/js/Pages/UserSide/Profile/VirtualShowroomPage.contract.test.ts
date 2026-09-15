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
const sceneSource = readFileSync(
	resolve('resources/js/Pages/UserSide/Products/showroomScene.ts'),
	'utf8',
);
const xoxSource = readFileSync(
	resolve('resources/js/Pages/UserSide/Products/ShowroomTicTacToe.tsx'),
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
		expect(pageSource).toContain('max-w-[calc(100vw-8.5rem)]');
		expect(showroomSource).toContain('absolute right-3 top-3');
		expect(showroomSource).toContain('absolute left-1/2 top-24');
		expect(showroomSource).toContain('absolute left-1/2 top-36');
	});

	it('keeps mobile controls available across touch-capable browsers', () => {
		expect(showroomSource).toContain("const hasTouchEvent = 'ontouchstart' in window;");
		expect(showroomSource).toContain('MOBILE_TABLET_USER_AGENT');
		expect(showroomSource).toContain("typeof window.matchMedia === 'function'");
		expect(showroomSource).toContain('const shouldShowMobileJoystick = isTouchScreenDevice');
		expect(showroomSource).toContain('ref={viewportRef}');
		expect(showroomSource).toContain('const container = viewportRef.current;');
	});

	it('passes showroom identity and editing capability through the page', () => {
		expect(pageSource).toContain('showroom_placements');
		expect(pageSource).toContain('can_edit_showroom');
		expect(pageSource).toContain('shopName={shop.name}');
		expect(showroomSource).toContain('shopName');
		expect(showroomSource).toContain('canEditShowroom');
	});

	it('keeps scene branding dynamic and exposes edit targets', () => {
		expect(sceneSource).toContain('enableSlotEditing');
		expect(sceneSource).toContain('slotTargets');
		expect(sceneSource).toContain('displayShopName');
		expect(sceneSource).not.toContain("sign('SOLESPACE'");
	});

	it('uses drag-look and E placement with world-space interaction cues', () => {
		expect(showroomSource).toContain('createShowroomPromptSprite');
		expect(showroomSource).toContain('targetCameraYawRef.current += deltaX * sensitivity');
		expect(showroomSource).toContain('handlePlacementKey()');
		expect(showroomSource).toContain('pickShoeAtViewCenter');
		expect(showroomSource).toContain('nearbyPlacementSlot()');
		expect(showroomSource).toContain('const walkSpeed = 7.2;');
		expect(showroomSource).toContain('const sensitivity = 0.007;');
		expect(showroomSource).toContain('Click and drag to look around');
		expect(sceneSource).toContain('seatPromptSprites');
		expect(sceneSource).toContain("createShowroomPromptSprite('E', 'PLAY'");
		expect(showroomSource).toContain("createShowroomPromptSprite(canEditShowroom ? 'E TO PICK' : 'CLICK')");
		expect(sceneSource).not.toContain('lounge.x + 0.2, 0.82');
	});

	it('keeps XOX as a themed continuous scored session', () => {
		expect(xoxSource).toContain('getBestBotMove');
		expect(xoxSource).toContain('sessionScore');
		expect(xoxSource).toContain('xox-result');
		expect(xoxSource).not.toContain('New game');
		expect(xoxSource).not.toContain('onClose: () => void;');
		expect(xoxSource).not.toContain('aria-label="Close XOX game"');
		expect(showroomSource).toContain('drawTableBoard');
		expect(showroomSource).toContain('xoxGameRef.current?.playAt');
		expect(showroomSource).not.toContain("You&apos;re seated in the lounge.");
		expect(showroomSource).not.toContain('Open XOX');
		expect(showroomSource).not.toContain('const closeGame');
		expect(showroomSource).not.toContain('onClose={closeGame}');
	});

	it('keeps placement feedback in the top-right without rebuilding the scene', () => {
		expect(showroomSource).not.toContain("isEditMode ? 'Done editing' : 'Edit showroom'");
		expect(showroomSource).not.toContain('absolute bottom-6 left-4 z-30 max-w-[min(85vw,320px)]');
		expect(showroomSource).toContain('absolute right-3 top-16 z-30 max-w-[min(85vw,320px)]');
		expect(showroomSource).not.toContain('}, [shoes, placementAssignments, shopName');
		expect(showroomSource).toContain('let renderedPlacementAssignments');
		expect(showroomSource).toContain('card.position.x +=');
	});
});
