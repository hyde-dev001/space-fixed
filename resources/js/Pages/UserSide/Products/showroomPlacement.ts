import type { ShowroomSlot } from './showroomLayout';

export interface ShowroomPlacement {
	productId: number;
	slotKey: string;
}

export interface ShowroomProductIdentity {
	id: number;
}

export const resolveShowroomPlacements = (
	products: readonly ShowroomProductIdentity[],
	slots: readonly ShowroomSlot[],
	saved: readonly ShowroomPlacement[],
): ShowroomPlacement[] => {
	const productIds = new Set(products.map(product => product.id));
	const slotOrder = new Map(slots.map((slot, index) => [slot.key, index]));
	const usedProducts = new Set<number>();
	const usedSlots = new Set<string>();
	const resolved: ShowroomPlacement[] = [];

	for (const placement of saved) {
		if (
			!productIds.has(placement.productId)
			|| !slotOrder.has(placement.slotKey)
			|| usedProducts.has(placement.productId)
			|| usedSlots.has(placement.slotKey)
		) continue;
		usedProducts.add(placement.productId);
		usedSlots.add(placement.slotKey);
		resolved.push({ ...placement });
	}

	const freeSlots = slots.filter(slot => !usedSlots.has(slot.key));
	let freeSlotCursor = 0;
	for (const product of products) {
		if (usedProducts.has(product.id)) continue;
		const slot = freeSlots[freeSlotCursor++];
		if (!slot) break;
		usedProducts.add(product.id);
		usedSlots.add(slot.key);
		resolved.push({ productId: product.id, slotKey: slot.key });
	}

	return resolved.sort((a, b) => slotOrder.get(a.slotKey)! - slotOrder.get(b.slotKey)!);
};

export const swapShowroomPlacements = (
	assignments: readonly ShowroomPlacement[],
	productId: number,
	sourceSlotKey: string,
	targetSlotKey: string,
): ShowroomPlacement[] => {
	if (sourceSlotKey === targetSlotKey) return assignments.map(assignment => ({ ...assignment }));
	const target = assignments.find(assignment => assignment.slotKey === targetSlotKey);
	return assignments.map(assignment => {
		if (assignment.productId === productId) return { ...assignment, slotKey: targetSlotKey };
		if (target && assignment.productId === target.productId) return { ...assignment, slotKey: sourceSlotKey };
		return { ...assignment };
	});
};
