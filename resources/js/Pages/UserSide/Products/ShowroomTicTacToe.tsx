import React, { useEffect, useRef, useState } from 'react';
import {
	applyMove,
	getRandomBotMove,
	getWinner,
	isDraw,
	type TicTacToeBoard,
} from './showroomTicTacToe';

interface ShowroomTicTacToeProps {
	open: boolean;
	onStandUp: () => void;
	onClose: () => void;
}

const EMPTY_BOARD: TicTacToeBoard = [null, null, null, null, null, null, null, null, null];

const ShowroomTicTacToe: React.FC<ShowroomTicTacToeProps> = ({ open, onStandUp, onClose }) => {
	const [board, setBoard] = useState<TicTacToeBoard>(EMPTY_BOARD);
	const [turn, setTurn] = useState<'X' | 'O'>('X');
	const [result, setResult] = useState<'X' | 'O' | 'draw' | null>(null);
	const botTimeoutRef = useRef<number | null>(null);

	const clearBotTimeout = () => {
		if (botTimeoutRef.current !== null) {
			window.clearTimeout(botTimeoutRef.current);
			botTimeoutRef.current = null;
		}
	};

	const resetGame = () => {
		clearBotTimeout();
		setBoard([...EMPTY_BOARD] as TicTacToeBoard);
		setTurn('X');
		setResult(null);
	};

	useEffect(() => {
		if (!open) {
			clearBotTimeout();
			return;
		}

		return clearBotTimeout;
	}, [open]);

	useEffect(() => () => clearBotTimeout(), []);

	const play = (index: number) => {
		if (!open || turn !== 'X' || result || board[index] !== null) return;
		const next = applyMove(board, index, 'X');
		const winner = getWinner(next);
		const draw = isDraw(next);
		setBoard(next);
		if (winner || draw) {
			setResult(winner ?? 'draw');
			return;
		}
		setTurn('O');
	};

	useEffect(() => {
		if (!open || turn !== 'O' || result) return;
		clearBotTimeout();
		botTimeoutRef.current = window.setTimeout(() => {
			const botMove = getRandomBotMove(board);
			if (botMove === null) {
				setResult('draw');
				return;
			}
			const next = applyMove(board, botMove, 'O');
			const winner = getWinner(next);
			setBoard(next);
			if (winner || isDraw(next)) {
				setResult(winner ?? 'draw');
				return;
			}
			setTurn('X');
		}, 350);

		return clearBotTimeout;
	}, [board, open, result, turn]);

	if (!open) return null;

	const status = result === 'draw'
		? 'Draw game.'
		: result
			? `${result === 'X' ? 'You win' : 'Bot wins'}!`
			: turn === 'X' ? 'Your turn (X).' : 'Bot is thinking…';

	return (
		<div className="pointer-events-auto absolute inset-0 z-50 flex items-center justify-center bg-stone-950/45 p-4">
			<section
				role="dialog"
				aria-modal="true"
				aria-labelledby="showroom-xox-title"
				className="w-full max-w-sm rounded-2xl border border-stone-700 bg-stone-950 p-4 text-stone-100 shadow-2xl"
			>
				<div className="flex items-start justify-between gap-3">
					<div>
						<p className="text-xs font-semibold uppercase tracking-[0.2em] text-stone-400">Lounge table</p>
						<h2 id="showroom-xox-title" className="mt-1 text-xl font-semibold">XOX with the showroom bot</h2>
					</div>
					<button type="button" onClick={onClose} className="min-h-11 min-w-11 rounded-lg border border-stone-700 px-3 text-sm hover:bg-stone-800" aria-label="Close XOX game">Close</button>
				</div>
				<p className="mt-3 text-sm text-stone-300" aria-live="polite">{status}</p>
				<div className="mt-4 grid grid-cols-3 gap-2" role="grid" aria-label="XOX board">
					{board.map((cell, index) => (
						<button
							key={index}
							type="button"
							onClick={() => play(index)}
							disabled={turn !== 'X' || Boolean(result) || cell !== null}
							aria-label={`XOX square ${index + 1}${cell ? `, ${cell}` : ''}`}
							className="min-h-14 rounded-xl border border-stone-700 bg-stone-900 text-3xl font-semibold text-amber-200 transition hover:bg-stone-800 disabled:cursor-not-allowed disabled:opacity-70"
						>
							{cell}
						</button>
					))}
				</div>
				<div className="mt-4 flex flex-wrap justify-end gap-2">
					<button type="button" onClick={resetGame} className="min-h-11 rounded-lg border border-stone-700 px-3 text-sm hover:bg-stone-800">New game</button>
					<button type="button" onClick={onStandUp} className="min-h-11 rounded-lg bg-amber-200 px-3 text-sm font-semibold text-stone-950 hover:bg-amber-100">Stand up</button>
				</div>
			</section>
		</div>
	);
};

export default ShowroomTicTacToe;
