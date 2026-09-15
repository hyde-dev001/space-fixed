import { describe, expect, it } from 'vitest';
import { getShowroomLayout, canWalkTo, getNearbyShowroomSeat, SHOWROOM_ENTRANCE } from './showroomLayout';

describe('connected flagship layout', () => {
	it.each([0, 48, 60, 84, 85, 100, 150])('retains %i usable product positions', (capacity) => {
		const layout = getShowroomLayout(capacity);
		expect(layout.slots).toHaveLength(capacity);
		expect(new Set(layout.slots.map(slot => slot.position.join(','))).size).toBe(capacity);
	});

	it('caps capacity and keeps assignments stable as a plan grows', () => {
		expect(getShowroomLayout(500).slots).toHaveLength(150);
		expect(getShowroomLayout(-1).slots).toHaveLength(0);
		expect(getShowroomLayout(NaN).slots).toHaveLength(60);
		expect(getShowroomLayout(60).slots).toEqual(getShowroomLayout(150).slots.slice(0, 60));
		const slots = getShowroomLayout(150).slots;
		expect(slots.filter(slot => slot.kind === 'feature')).toHaveLength(6);
		expect(slots.filter(slot => slot.kind === 'island')).toHaveLength(24);
		expect(slots.filter(slot => slot.kind === 'wall')).toHaveLength(120);
	});

	it('connects the entrance to a viewing position in front of every slot', () => {
		const layout = getShowroomLayout(150);
		expect(canWalkTo(SHOWROOM_ENTRANCE[0], SHOWROOM_ENTRANCE[2], layout.colliders)).toBe(true);
		const visited = new Set<string>();
		const queue = [[0, 21]];
		for (let i = 0; i < queue.length; i++) {
			const [x, z] = queue[i];
			const key = `${x},${z}`;
			if (visited.has(key) || !canWalkTo(x, z, layout.colliders)) continue;
			visited.add(key);
			queue.push([x + 1, z], [x - 1, z], [x, z + 1], [x, z - 1]);
		}
		for (const slot of layout.slots) {
			const x = slot.position[0] + Math.sin(slot.rotationY) * 2;
			const z = slot.position[2] + Math.cos(slot.rotationY) * 2;
			expect(canWalkTo(x, z, layout.colliders)).toBe(true);
			expect(visited.has(`${Math.round(x)},${Math.round(z)}`)).toBe(true);
		}
		expect(canWalkTo(8, 7, layout.colliders)).toBe(false);
		expect(canWalkTo(23, 0, layout.colliders)).toBe(false);
	});

	it('keeps slot keys stable as capacity grows', () => {
		const sixty = getShowroomLayout(60).slots;
		const full = getShowroomLayout(150).slots;
		expect(sixty.map(slot => slot.key)).toEqual(full.slice(0, 60).map(slot => slot.key));
		expect(new Set(full.map(slot => slot.key)).size).toBe(150);
		expect(full[0].key).toBe('slot-0');
		expect(full[149].key).toBe('slot-149');
	});

	it('exposes walkable couch interaction points', () => {
		const layout = getShowroomLayout(60);
		expect(layout.seats).toHaveLength(layout.lounges.length);
		for (const seat of layout.seats) {
			expect(canWalkTo(seat.interactionPosition[0], seat.interactionPosition[1], layout.colliders)).toBe(true);
			expect(getNearbyShowroomSeat(
				seat.interactionPosition[0],
				seat.interactionPosition[1],
				layout.seats,
				1.5,
			)?.key).toBe(seat.key);
		}
	});

	it('seats the camera facing the game table', () => {
		const layout = getShowroomLayout(60);
		layout.seats.forEach((seat, index) => {
			const lounge = layout.lounges[index];
			expect(seat.cameraPosition[0]).toBe(lounge.x);
			expect(seat.cameraPosition[1]).toBeGreaterThan(3);
			expect(seat.cameraPosition[2]).toBeGreaterThan(lounge.z);
			expect(seat.lookAt).toEqual([lounge.x, 0.72, lounge.z - 0.4]);
		});
	});
});
