export interface ShowroomSlot {
	key: string;
	position: [number, number, number];
	rotationY: number;
	kind: 'feature' | 'island' | 'wall';
}

export interface ShowroomSeat {
	key: string;
	interactionPosition: [number, number];
	cameraPosition: [number, number, number];
	lookAt: [number, number, number];
}

export interface ShowroomCollider { x: number; z: number; width: number; depth: number }

export const SHOWROOM_BOUNDS = { halfWidth: 22, back: -26, front: 24 };
export const SHOWROOM_ENTRANCE = [3.5, 2.65, 21] as const;

export const canWalkTo = (x: number, z: number, colliders: ShowroomCollider[]) => {
	const radius = 0.4;
	return x > -21.4 && x < 21.4 && z > -25.4 && z < 23.4
		&& !colliders.some(c => Math.abs(x - c.x) < c.width / 2 + radius && Math.abs(z - c.z) < c.depth / 2 + radius);
};

export const getShowroomLayout = (capacity: number) => {
	const slots: ShowroomSlot[] = [];
	const colliders: ShowroomCollider[] = [];
	const bays: Array<{ x: number; z: number; rotationY: number }> = [];
	const islands = [-8, 8].flatMap(x => [-7, 7].map(z => ({ x, z })));
	const features = Array.from({ length: 6 }, (_, i) => ({ x: (i - 2.5) * 2.5, z: -18, height: 1.1 + (i % 3) * 0.15 }));
	const lounges = [{ x: -6, z: 13 }, { x: 11, z: -14 }];
	const pushSlot = (
		position: [number, number, number],
		rotationY: number,
		kind: ShowroomSlot['kind'],
	) => {
		slots.push({ key: 'slot-' + slots.length, position, rotationY, kind });
	};

	for (const feature of features) {
		pushSlot([feature.x, feature.height + 0.525, feature.z], 0, 'feature');
		colliders.push({ ...feature, width: 1.9, depth: 1.6 });
	}
	for (const island of islands) {
		colliders.push({ ...island, width: 6.6, depth: 3.4 });
		for (const side of [1, -1]) {
			for (let col = 0; col < 3; col++) {
				pushSlot([island.x + (col - 1) * 2.1, 1.725, island.z + side * 1.1], side === 1 ? 0 : Math.PI, 'island');
			}
		}
	}
	for (const side of [-1, 1]) {
		for (let i = 0; i < 7; i++) bays.push({ x: side * 20.8, z: -19 + i * 6, rotationY: side === -1 ? Math.PI / 2 : -Math.PI / 2 });
	}
	for (const x of [-16, -9.6, -3.2, 3.2, 9.6, 16]) bays.push({ x, z: -24.8, rotationY: 0 });
	// Each bay contributes six positions; fixtures exist even when the shop has fewer products.
	for (const bay of bays) {
		const sin = Math.sin(bay.rotationY);
		const cos = Math.cos(bay.rotationY);
		colliders.push({ x: bay.x, z: bay.z, width: Math.abs(cos) * 5.6 + Math.abs(sin) * 1.4, depth: Math.abs(sin) * 5.6 + Math.abs(cos) * 1.4 });
		for (let row = 0; row < 3; row++) {
			for (const offset of [-1.35, 1.35]) {
				pushSlot([bay.x + cos * offset + sin * 0.25, 1.375 + row * 1.2, bay.z - sin * offset + cos * 0.25], bay.rotationY, 'wall');
			}
		}
	}
	const seats: ShowroomSeat[] = lounges.map((lounge, index) => {
		const side = lounge.x < 0 ? 1 : -1;
		return {
			key: 'lounge-seat-' + index,
			interactionPosition: [lounge.x + side * 3.25, lounge.z + 1.8],
			cameraPosition: [lounge.x + side * 3.5, 2.05, lounge.z + 1.8],
			lookAt: [lounge.x, 1.25, lounge.z + 1.8],
		};
	});
	for (const lounge of lounges) {
		colliders.push({ x: lounge.x, z: lounge.z + 1.8, width: 5.6, depth: 1.8 });
		colliders.push({ x: lounge.x, z: lounge.z - 0.4, width: 2.3, depth: 1.8 });
		colliders.push({ x: lounge.x + Math.sign(lounge.x) * 3.5, z: lounge.z, width: 1.3, depth: 1.3 });
	}
	colliders.push({ x: 0, z: -20.8, width: 16.2, depth: 0.16 });
	for (const x of [-15, 15]) for (const z of [-21, -10, 2, 14]) colliders.push({ x, z, width: 0.46, depth: 0.46 });
	const limit = Number.isFinite(capacity) ? Math.max(0, Math.min(150, Math.floor(capacity))) : 60;
	return { slots: slots.slice(0, limit), colliders, bays, islands, features, lounges, seats };
};

export const getNearbyShowroomSeat = (
	x: number,
	z: number,
	seats: readonly ShowroomSeat[],
	radius = 2.4,
) => seats.find((seat) => {
	const dx = x - seat.interactionPosition[0];
	const dz = z - seat.interactionPosition[1];
	return dx * dx + dz * dz <= radius * radius;
}) ?? null;
