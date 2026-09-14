import React, { useEffect, useRef, useState } from 'react';
import {
	applyMove,
	getBestBotMove,
	getWinner,
	getWinningLine,
	isDraw,
	type TicTacToeBoard,
} from './showroomTicTacToeRules';

interface ShowroomTicTacToeProps {
	open: boolean;
	onStandUp: () => void;
	onClose: () => void;
}

type RoundResult = 'X' | 'O' | 'draw';

interface SessionScore {
	wins: number;
	losses: number;
	draws: number;
}

const EMPTY_BOARD: TicTacToeBoard = [null, null, null, null, null, null, null, null, null];
const ROUND_TRANSITION_MS = 1650;

const createEmptyBoard = (): TicTacToeBoard => [...EMPTY_BOARD] as TicTacToeBoard;

const ShowroomTicTacToe: React.FC<ShowroomTicTacToeProps> = ({ open, onStandUp, onClose }) => {
	const [board, setBoard] = useState<TicTacToeBoard>(createEmptyBoard);
	const [turn, setTurn] = useState<'X' | 'O'>('X');
	const [result, setResult] = useState<RoundResult | null>(null);
	const [winningLine, setWinningLine] = useState<number[]>([]);
	const [sessionScore, setSessionScore] = useState<SessionScore>({ wins: 0, losses: 0, draws: 0 });
	const botTimeoutRef = useRef<number | null>(null);
	const roundTimeoutRef = useRef<number | null>(null);

	const clearBotTimeout = () => {
		if (botTimeoutRef.current !== null) {
			window.clearTimeout(botTimeoutRef.current);
			botTimeoutRef.current = null;
		}
	};

	const clearRoundTimeout = () => {
		if (roundTimeoutRef.current !== null) {
			window.clearTimeout(roundTimeoutRef.current);
			roundTimeoutRef.current = null;
		}
	};

	const finishRound = (nextBoard: TicTacToeBoard, nextResult: RoundResult) => {
		setBoard(nextBoard);
		setWinningLine(getWinningLine(nextBoard));
		setResult(nextResult);
		setSessionScore((previous) => {
			if (nextResult === 'X') return { ...previous, wins: previous.wins + 1 };
			if (nextResult === 'O') return { ...previous, losses: previous.losses + 1 };
			return { ...previous, draws: previous.draws + 1 };
		});
	};

	const startNextRound = () => {
		clearRoundTimeout();
		setBoard(createEmptyBoard());
		setWinningLine([]);
		setResult(null);
		setTurn('X');
	};

	useEffect(() => {
		if (!open) {
			clearBotTimeout();
			clearRoundTimeout();
			return;
		}

		return () => {
			clearBotTimeout();
			clearRoundTimeout();
		};
	}, [open]);

	useEffect(() => () => {
		clearBotTimeout();
		clearRoundTimeout();
	}, []);

	const play = (index: number) => {
		if (!open || turn !== 'X' || result || board[index] !== null) return;

		const next = applyMove(board, index, 'X');
		const winner = getWinner(next);
		if (winner || isDraw(next)) {
			finishRound(next, winner ?? 'draw');
			return;
		}

		setBoard(next);
		setTurn('O');
	};

	useEffect(() => {
		if (!open || turn !== 'O' || result) return;

		clearBotTimeout();
		botTimeoutRef.current = window.setTimeout(() => {
			const botMove = getBestBotMove(board);
			if (botMove === null) {
				finishRound(board, 'draw');
				return;
			}

			const next = applyMove(board, botMove, 'O');
			const winner = getWinner(next);
			if (winner || isDraw(next)) {
				finishRound(next, winner ?? 'draw');
				return;
			}

			setBoard(next);
			setTurn('X');
		}, 500);

		return clearBotTimeout;
	}, [board, open, result, turn]);

	useEffect(() => {
		if (!open || !result) return;

		clearRoundTimeout();
		roundTimeoutRef.current = window.setTimeout(startNextRound, ROUND_TRANSITION_MS);

		return clearRoundTimeout;
	}, [open, result]);

	if (!open) return null;

	const resultLabel = result === 'X' ? 'YOU WIN' : result === 'O' ? 'BOT WINS' : result === 'draw' ? 'DRAW' : null;
	const status = resultLabel
		? `${resultLabel}. Next round starts automatically.`
		: turn === 'X' ? 'Your turn — choose a square.' : 'The showroom bot is thinking…';

	return (
		<>
			<style>{`
				@keyframes xox-result-enter {
					0% { opacity: 0; transform: translateY(14px) scale(0.82); }
					55% { opacity: 1; transform: translateY(-4px) scale(1.04); }
					100% { opacity: 1; transform: translateY(0) scale(1); }
				}

				@keyframes xox-result-pulse {
					0%, 100% { box-shadow: 0 0 0 0 rgba(225, 190, 127, 0); }
					50% { box-shadow: 0 0 0 12px rgba(225, 190, 127, 0.08); }
				}

				@keyframes xox-winning-cell {
					0%, 100% { transform: scale(1); }
					50% { transform: scale(1.035); }
				}

				.xox-result-enter { animation: xox-result-enter 320ms cubic-bezier(.2,.8,.2,1) both; }
				.xox-result-pulse { animation: xox-result-pulse 1200ms ease-in-out infinite; }
				.xox-winning-cell { animation: xox-winning-cell 900ms ease-in-out infinite; }
				@media (prefers-reduced-motion: reduce) {
					.xox-result-enter, .xox-result-pulse, .xox-winning-cell { animation: none; }
				}
			`}</style>
			<div
				className="pointer-events-auto absolute inset-0 z-50 flex items-center justify-center bg-[#120f0c]/65 p-4 backdrop-blur-[2px]"
				onPointerDown={(event) => event.stopPropagation()}
				onPointerMove={(event) => event.stopPropagation()}
				onPointerUp={(event) => event.stopPropagation()}
				onPointerCancel={(event) => event.stopPropagation()}
			>
				<section
					role="dialog"
					aria-modal="true"
					aria-labelledby="showroom-xox-title"
					className="relative w-full max-w-2xl overflow-hidden rounded-[1.4rem] border border-[#8e6d4b] bg-[#1a1714] text-[#f2e8d8] shadow-[0_28px_90px_rgba(0,0,0,0.55)]"
				>
					<div className="pointer-events-none absolute inset-x-0 top-0 h-1 bg-gradient-to-r from-transparent via-[#d7b77d] to-transparent opacity-90" />
					<div className="flex items-start justify-between gap-4 border-b border-[#46392d] px-5 py-4 sm:px-6">
						<div>
							<p className="text-[10px] font-semibold uppercase tracking-[0.28em] text-[#bfa47f]">Lounge table / XOX session</p>
							<h2 id="showroom-xox-title" className="mt-1 text-xl font-semibold tracking-tight sm:text-2xl">Play against the showroom bot</h2>
						</div>
						<button
							type="button"
							onClick={onClose}
							className="min-h-11 min-w-11 rounded-lg border border-[#705943] px-3 text-sm font-medium text-[#f2e8d8] transition-colors hover:bg-[#2a231d] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#e4c486]"
							aria-label="Close XOX game"
						>
							Close
						</button>
					</div>

					<div className="grid gap-5 p-5 sm:p-6 lg:grid-cols-[minmax(0,1fr)_150px]">
						<div>
							<div className="flex items-center justify-between gap-3">
								<p className="text-sm text-[#d8c7b0]" aria-live="polite">{status}</p>
								<span className="rounded-full border border-[#594836] bg-[#211c18] px-3 py-1 text-[10px] font-semibold uppercase tracking-[0.18em] text-[#bfa47f]">Continuous rounds</span>
							</div>

							<div className="relative mt-4 rounded-2xl border border-[#5b4634] bg-[#100e0c] p-3 shadow-inner shadow-black/40 sm:p-4" role="grid" aria-label="XOX board">
								<div className="grid grid-cols-3 gap-2 sm:gap-3">
									{board.map((cell, index) => {
										const isWinningCell = winningLine.includes(index);
										return (
											<button
												key={index}
												type="button"
												onClick={() => play(index)}
												disabled={turn !== 'X' || Boolean(result) || cell !== null}
												aria-label={`XOX square ${index + 1}${cell ? `, ${cell}` : ''}`}
												className={`xox-cell min-h-20 rounded-xl border text-4xl font-semibold transition sm:min-h-24 sm:text-5xl ${
													isWinningCell
														? 'xox-winning-cell border-[#e4c486] bg-[#6b5239] shadow-[0_0_30px_rgba(228,196,134,0.24)]'
														: 'border-[#49382b] bg-[#211b17] hover:border-[#806244] hover:bg-[#2a221c]'
												} ${cell === 'X' ? 'text-[#f2e8d8]' : 'text-[#e2bc72]'} disabled:cursor-not-allowed disabled:opacity-80 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#e4c486]`}
											>
												<span className="drop-shadow-[0_3px_8px_rgba(0,0,0,0.55)]">{cell}</span>
											</button>
										);
									})}
								</div>

								{resultLabel && (
									<div className="pointer-events-none absolute inset-0 flex items-center justify-center p-5">
										<div className="xox-result-enter xox-result-pulse rounded-2xl border border-[#e4c486] bg-[#201a15]/95 px-7 py-4 text-center shadow-[0_18px_45px_rgba(0,0,0,0.42)]">
											<p className="text-[10px] font-semibold uppercase tracking-[0.3em] text-[#c9a86f]">Round complete</p>
											<p className={`mt-1 text-3xl font-bold tracking-[0.08em] ${result === 'X' ? 'text-[#f7eddc]' : result === 'O' ? 'text-[#db8d71]' : 'text-[#e2bc72]'}`}>{resultLabel}</p>
										</div>
									</div>
								)}
							</div>

							<p className="mt-3 text-xs text-[#8f7c68]">Rounds continue automatically while you remain seated.</p>
						</div>

						<aside className="rounded-2xl border border-[#554332] bg-[#211b17] p-4 shadow-inner shadow-black/25" aria-label="Session score">
							<p className="text-[10px] font-semibold uppercase tracking-[0.25em] text-[#bfa47f]">Session score</p>
							<div className="mt-4 space-y-3">
								<div className="flex items-center justify-between border-b border-[#443429] pb-3"><span className="text-sm text-[#eadfce]">You</span><strong className="text-2xl text-[#f2e8d8]">{sessionScore.wins}</strong></div>
								<div className="flex items-center justify-between border-b border-[#443429] pb-3"><span className="text-sm text-[#c58d7e]">Bot</span><strong className="text-2xl text-[#db8d71]">{sessionScore.losses}</strong></div>
								<div className="flex items-center justify-between"><span className="text-sm text-[#d9b975]">Draws</span><strong className="text-2xl text-[#e2bc72]">{sessionScore.draws}</strong></div>
							</div>
							<div className="mt-5 rounded-xl border border-[#584634] bg-[#181411] px-3 py-2 text-center text-[10px] uppercase tracking-[0.18em] text-[#8f7c68]">Stand up to end session</div>
						</aside>
					</div>

					<div className="flex justify-end border-t border-[#46392d] px-5 py-4 sm:px-6">
						<button type="button" onClick={onStandUp} className="min-h-11 rounded-lg bg-[#e4c486] px-5 text-sm font-semibold text-[#1c1712] transition-colors hover:bg-[#f0d59b] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#e4c486]">Stand up</button>
					</div>
				</section>
			</div>
		</>
	);
};

export default ShowroomTicTacToe;
