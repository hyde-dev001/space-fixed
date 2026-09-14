export type Mark = 'X' | 'O';
export type Cell = Mark | null;
export type TicTacToeBoard = [Cell, Cell, Cell, Cell, Cell, Cell, Cell, Cell, Cell];

const LINES = [
	[0, 1, 2], [3, 4, 5], [6, 7, 8],
	[0, 3, 6], [1, 4, 7], [2, 5, 8],
	[0, 4, 8], [2, 4, 6],
] as const;

export const applyMove = (board: TicTacToeBoard, index: number, mark: Mark): TicTacToeBoard => {
	if (index < 0 || index > 8 || board[index] !== null) return board;
	const next = [...board] as TicTacToeBoard;
	next[index] = mark;
	return next;
};

export const getWinner = (board: TicTacToeBoard): Mark | null => {
	for (const [a, b, c] of LINES) {
		if (board[a] && board[a] === board[b] && board[a] === board[c]) return board[a];
	}
	return null;
};

export const isDraw = (board: TicTacToeBoard): boolean =>
	getWinner(board) === null && board.every(cell => cell !== null);

export const getRandomBotMove = (
	board: TicTacToeBoard,
	random: () => number = Math.random,
): number | null => {
	const empty = board.flatMap((cell, index) => cell === null ? [index] : []);
	if (empty.length === 0) return null;
	return empty[Math.min(empty.length - 1, Math.floor(random() * empty.length))];
};
