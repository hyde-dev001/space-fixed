import { describe, expect, it } from 'vitest';
import {
	applyMove,
	getBestBotMove,
	getMediumBotMove,
	getRandomBotMove,
	getWinner,
	getWinningLine,
	isDraw,
	type TicTacToeBoard,
} from './showroomTicTacToeRules';

describe('showroom XOX rules', () => {
	it('detects rows, columns, and diagonals', () => {
		expect(getWinner(['X', 'X', 'X', null, null, null, null, null, null])).toBe('X');
		expect(getWinner(['O', null, null, 'O', null, null, 'O', null, null])).toBe('O');
		expect(getWinner(['X', null, null, null, 'X', null, null, null, 'X'])).toBe('X');
	});

	it('rejects occupied moves and reports a full draw', () => {
		const board: TicTacToeBoard = ['X', 'O', 'X', 'X', 'O', 'O', 'O', 'X', 'X'];
		expect(applyMove(board, 0, 'O')).toEqual(board);
		expect(isDraw(board)).toBe(true);
		expect(getWinner(board)).toBeNull();
	});

	it('chooses only empty squares', () => {
		const board: TicTacToeBoard = ['X', null, 'O', null, 'X', null, null, 'O', null];
		const move = getRandomBotMove(board, () => 0.99);
		expect([1, 3, 5, 6, 8]).toContain(move);
		expect(board[move!]).toBeNull();
	});

	it('uses the strongest move and reports the winning line', () => {
		const winningBoard: TicTacToeBoard = ['O', 'O', null, 'X', 'X', null, null, null, null];
		const blockingBoard: TicTacToeBoard = ['X', 'X', null, 'O', null, null, null, null, null];

		expect(getBestBotMove(winningBoard, () => 0.5)).toBe(2);
		expect(getBestBotMove(blockingBoard, () => 0.5)).toBe(2);
		expect(getWinningLine(['X', null, null, null, 'X', null, null, null, 'X'])).toEqual([0, 4, 8]);
	});

	it('medium bot takes wins and blocks immediate losses', () => {
		expect(getMediumBotMove(['O', 'O', null, 'X', null, null, null, null, null])).toBe(2);
		expect(getMediumBotMove(['X', 'X', null, 'O', null, null, null, null, null])).toBe(2);
	});
});
