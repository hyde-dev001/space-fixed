import { useEffect, useMemo, useRef, useState } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import Navigation from '../Shared/Navigation';
import Form from '../../../components/form/Form';
import Swal from 'sweetalert2';

const OTP_LENGTH = 6;
const TIMER_SECONDS = 30;

export default function Otp() {
	const page = usePage();
	const props = page.props as { email?: string };

	const emailFromQuery = typeof window !== 'undefined'
		? new URLSearchParams(window.location.search).get('email') || ''
		: '';

	const email = props.email || emailFromQuery;

	const [digits, setDigits] = useState<string[]>(Array(OTP_LENGTH).fill(''));
	const [errors, setErrors] = useState<string>('');
	const [isLoading, setIsLoading] = useState(false);
	const [secondsLeft, setSecondsLeft] = useState(TIMER_SECONDS);
	const [isResending, setIsResending] = useState(false);

	const inputRefs = useRef<Array<HTMLInputElement | null>>([]);

	const otpValue = useMemo(() => digits.join(''), [digits]);

	useEffect(() => {
		if (secondsLeft <= 0) return;

		const interval = window.setInterval(() => {
			setSecondsLeft((prev) => (prev > 0 ? prev - 1 : 0));
		}, 1000);

		return () => window.clearInterval(interval);
	}, [secondsLeft]);

	const handleDigitChange = (index: number, rawValue: string) => {
		const value = rawValue.replace(/\D/g, '').slice(-1);

		setDigits((prev) => {
			const next = [...prev];
			next[index] = value;
			return next;
		});

		if (errors) setErrors('');

		if (value && index < OTP_LENGTH - 1) {
			inputRefs.current[index + 1]?.focus();
		}
	};

	const handleKeyDown = (index: number, e: React.KeyboardEvent<HTMLInputElement>) => {
		if (e.key === 'Backspace' && !digits[index] && index > 0) {
			inputRefs.current[index - 1]?.focus();
		}
	};

	const handlePaste = (e: React.ClipboardEvent<HTMLInputElement>) => {
		e.preventDefault();
		const pasted = e.clipboardData.getData('text').replace(/\D/g, '').slice(0, OTP_LENGTH);
		if (!pasted) return;

		const next = Array(OTP_LENGTH).fill('').map((_, i) => pasted[i] || '');
		setDigits(next);
		setErrors('');

		const lastFilledIndex = Math.min(pasted.length, OTP_LENGTH) - 1;
		if (lastFilledIndex >= 0) {
			inputRefs.current[lastFilledIndex]?.focus();
		}
	};

	const formatTime = (seconds: number): string => {
		const mins = Math.floor(seconds / 60);
		const secs = seconds % 60;
		return `${String(mins).padStart(2, '0')}:${String(secs).padStart(2, '0')}`;
	};

	const handleResend = () => {
		if (secondsLeft > 0) return;

		setIsResending(true);
		setTimeout(() => {
			setIsResending(false);
			setSecondsLeft(TIMER_SECONDS);
			setDigits(Array(OTP_LENGTH).fill(''));
			inputRefs.current[0]?.focus();
			Swal.fire({
				icon: 'success',
				title: 'OTP Sent',
				text: 'A new OTP has been sent to your email.',
				confirmButtonColor: '#000000',
			});
		}, 700);
	};

	const handleSubmit = (e: React.FormEvent<HTMLFormElement>) => {
		e.preventDefault();

		if (otpValue.length !== OTP_LENGTH) {
			setErrors('Please enter the complete 6-digit OTP.');
			return;
		}

		setIsLoading(true);
		setTimeout(() => {
			router.visit(route('password.new', { email }));
		}, 800);
	};

	return (
		<>
			<Head title="Reset Password OTP" />

			<div className="min-h-screen bg-white font-outfit antialiased">
				<Navigation />

				<div className="max-w-480 mx-auto px-6 lg:px-12 py-24">
					<div className="text-center mb-12">
						<h1 className="text-4xl lg:text-6xl font-bold text-gray-900 mb-6 tracking-tight">
							RESET YOUR PASSWORD
						</h1>
						<p className="text-xl text-gray-600 max-w-2xl mx-auto leading-relaxed font-light">
							A 6 digit email OTP was sent to {email || 'your email'}. Enter that code here to proceed.
						</p>
					</div>

					<div className="max-w-lg mx-auto">
						<div className="bg-white rounded-2xl shadow-xl p-8">
							<Form onSubmit={handleSubmit} className="space-y-6" autoComplete="off">
								<div>
									<label htmlFor="otp-0" className="block text-sm font-medium text-gray-900 mb-3">
										Verification code
									</label>
									<div className="grid grid-cols-6 gap-2 sm:gap-3">
										{digits.map((digit, index) => (
											<input
												key={`otp-${index}`}
												id={`otp-${index}`}
												name={`otp-${index}`}
												ref={(el) => {
													inputRefs.current[index] = el;
												}}
												type="text"
												inputMode="numeric"
												autoComplete={index === 0 ? 'one-time-code' : 'off'}
												autoCorrect="off"
												spellCheck={false}
												maxLength={1}
												value={digit}
												onChange={(e) => handleDigitChange(index, e.target.value)}
												onKeyDown={(e) => handleKeyDown(index, e)}
												onPaste={handlePaste}
												className="h-12 sm:h-14 rounded-lg border border-gray-300 text-center text-lg font-semibold text-gray-900 focus:border-black focus:ring-2 focus:ring-black/10 outline-none transition"
												aria-label={`OTP digit ${index + 1}`}
											/>
										))}
									</div>
									{errors && <p className="mt-2 text-sm text-red-600">{errors}</p>}
								</div>

								<div className="text-sm text-gray-600 text-center">
									Didn't get OTP?{' '}
									<button
										type="button"
										disabled={secondsLeft > 0 || isResending}
										onClick={handleResend}
										className="font-semibold text-black hover:text-black/80 disabled:text-gray-400 disabled:cursor-not-allowed"
									>
										{secondsLeft > 0 ? `resend OTP in ${formatTime(secondsLeft)}` : (isResending ? 'Sending...' : 'Resend OTP')}
									</button>
								</div>

								<button
									type="submit"
									disabled={isLoading}
									className="w-full px-10 py-4 bg-black text-white font-semibold uppercase tracking-wider text-sm hover:bg-black/80 transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
								>
									{isLoading ? 'Verifying...' : 'Verify OTP'}
								</button>
							</Form>

							<div className="mt-6 text-center space-y-2">
								<p className="text-gray-600">
									Wrong email?{' '}
									<Link
										href={route('password.request')}
										className="text-black hover:text-black/80 font-semibold uppercase tracking-wider text-sm transition-colors"
									>
										Go back
									</Link>
								</p>
							</div>
						</div>
					</div>
				</div>
			</div>
		</>
	);
}
