export const getShowroomRooms = (capacity: number) => {
	const safeCapacity = Math.min(150, Math.max(1, Math.trunc(capacity)));
	if (safeCapacity <= 84) return [{ start: 0, count: safeCapacity }];

	const firstRoom = Math.ceil(safeCapacity / 2);
	return [
		{ start: 0, count: firstRoom },
		{ start: firstRoom, count: safeCapacity - firstRoom },
	];
};
