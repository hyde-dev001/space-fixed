export type Mark = 'X' | 'O';
export type Cell = Mark | null;
export type TicTacToeBoard = [Cell, Cell, Cell, Cell, Cell, Cell, Cell, Cell, Cell];

const LINES = [
	[0, 1, 2], [3, 4, 5], [6, 7, 8],
	[0, 3, 6], [1, 4, 7], [2, 5, 8],
	[0, 4, 8], [2, 4, 6],
] as const;

export const getWinningLine = (board: TicTacToeBoard): number[] => {
	for (const line of LINES) {
		const [a, b, c] = line;
		if (board[a] && board[a] === board[b] && board[a] === board[c]) {
			return [...line];
		}
	}

	return [];
};

export const applyMove = (board: TicTacToeBoard, index: number, mark: Mark): TicTacToeBoard => {
	if (index < 0 || index > 8 || board[index] !== null) return board;
	const next = [...board] as TicTacToeBoard;
	next[index] = mark;
	return next;
};

export const getWinner = (board: TicTacToeBoard): Mark | null => {
	const winningLine = getWinningLine(board);
	return winningLine.length > 0 ? board[winningLine[0]] : null;
};

export const isDraw = (board: TicTacToeBoard): boolean =>
	getWinner(board) === null && board.every(cell => cell !== null);

const getEmptyCells = (board: TicTacToeBoard): number[] =>
	board.flatMap((cell, index) => cell === null ? [index] : []);

const minimax = (board: TicTacToeBoard, turn: Mark, depth: number): number => {
	const winner = getWinner(board);
	if (winner === 'O') return 10 - depth;
	if (winner === 'X') return depth - 10;
	if (isDraw(board)) return 0;

	const scores = getEmptyCells(board).map((index) =>
		minimax(applyMove(board, index, turn), turn === 'O' ? 'X' : 'O', depth + 1),
	);

	return turn === 'O' ? Math.max(...scores) : Math.min(...scores);
};

export const getBestBotMove = (
	board: TicTacToeBoard,
	random: () => number = Math.random,
): number | null => {
	const empty = getEmptyCells(board);
	if (empty.length === 0) return null;

	const scoredMoves = empty.map((index) => ({
		index,
		score: minimax(applyMove(board, index, 'O'), 'X', 0),
	}));
	const bestScore = Math.max(...scoredMoves.map(move => move.score));
	const bestMoves = scoredMoves.filter(move => move.score === bestScore);
	const randomIndex = Math.min(bestMoves.length - 1, Math.max(0, Math.floor(random() * bestMoves.length)));

	return bestMoves[randomIndex].index;
};

export const getRandomBotMove = (
	board: TicTacToeBoard,
	random: () => number = Math.random,
): number | null => {
	const empty = getEmptyCells(board);
	if (empty.length === 0) return null;
	return empty[Math.min(empty.length - 1, Math.floor(random() * empty.length))];
};
