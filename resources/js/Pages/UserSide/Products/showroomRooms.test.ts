import { describe, expect, it } from 'vitest';
import { getShowroomRooms } from './showroomRooms';

describe('getShowroomRooms', () => {
	it.each([
		[84, [{ start: 0, count: 84 }]],
		[85, [{ start: 0, count: 43 }, { start: 43, count: 42 }]],
		[100, [{ start: 0, count: 50 }, { start: 50, count: 50 }]],
		[150, [{ start: 0, count: 75 }, { start: 75, count: 75 }]],
	])('splits %i slots', (capacity, expected) => {
		expect(getShowroomRooms(capacity as number)).toEqual(expected);
	});
});
