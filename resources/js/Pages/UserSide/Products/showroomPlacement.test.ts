import { describe, expect, it } from 'vitest';
import { getShowroomLayout } from './showroomLayout';
import { resolveShowroomPlacements, swapShowroomPlacements } from './showroomPlacement';

describe('showroom placement mapping', () => {
	const slots = getShowroomLayout(4).slots;
	const products = [{ id: 10 }, { id: 20 }, { id: 30 }];

	it('uses valid saved assignments and fills the remaining products', () => {
		expect(resolveShowroomPlacements(products, slots, [
			{ productId: 30, slotKey: 'slot-2' },
			{ productId: 999, slotKey: 'slot-1' },
			{ productId: 20, slotKey: 'unknown' },
		])).toEqual([
			{ productId: 10, slotKey: 'slot-0' },
			{ productId: 20, slotKey: 'slot-1' },
			{ productId: 30, slotKey: 'slot-2' },
		]);
	});

	it('swaps occupants on an occupied drop and moves into an empty slot', () => {
		const assignments = [
			{ productId: 10, slotKey: 'slot-0' },
			{ productId: 20, slotKey: 'slot-1' },
		];
		expect(swapShowroomPlacements(assignments, 10, 'slot-0', 'slot-1')).toEqual([
			{ productId: 10, slotKey: 'slot-1' },
			{ productId: 20, slotKey: 'slot-0' },
		]);
		expect(swapShowroomPlacements(assignments, 10, 'slot-0', 'slot-3')).toEqual([
			{ productId: 10, slotKey: 'slot-3' },
			{ productId: 20, slotKey: 'slot-1' },
		]);
	});

	it('keeps every eligible product visible after a saved reorder', () => {
		const result = resolveShowroomPlacements(
			[{ id: 10 }, { id: 20 }, { id: 30 }],
			getShowroomLayout(3).slots,
			[{ productId: 30, slotKey: 'slot-0' }],
		);
		expect(result).toEqual([
			{ productId: 30, slotKey: 'slot-0' },
			{ productId: 10, slotKey: 'slot-1' },
			{ productId: 20, slotKey: 'slot-2' },
		]);
		expect(result.map(item => item.productId).sort()).toEqual([10, 20, 30]);
	});
});
