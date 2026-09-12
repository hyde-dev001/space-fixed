import { useEffect, useMemo, useRef, useState } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import Navigation from '../Shared/Navigation';
import Form from '../../../components/form/Form';
import Swal from '@/Pages/UserSide/Shared/UserModal';

const OTP_LENGTH = 6;
const TIMER_SECONDS = 30;

export default function Otp() {
	const page = usePage();
	const props = page.props as { email?: string; status?: string; errors?: Record<string, string> };

	const emailFromQuery = typeof window !== 'undefined'
		? new URLSearchParams(window.location.search).get('email') || ''
		: '';

	const email = props.email || emailFromQuery;
	const status = props.status;

	const [digits, setDigits] = useState<string[]>(Array(OTP_LENGTH).fill(''));
	const [errors, setErrors] = useState<string>('');
	const [isLoading, setIsLoading] = useState(false);
	const [secondsLeft, setSecondsLeft] = useState(TIMER_SECONDS);
	const [isResending, setIsResending] = useState(false);

	const inputRefs = useRef<Array<HTMLInputElement | null>>([]);

	const otpValue = useMemo(() => digits.join(''), [digits]);

	useEffect(() => {
		if (!email) {
			router.visit(route('password.request'));
		}
	}, [email]);

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
		if (!email) {
			setErrors('Reset session missing. Please start again from the reset password step.');
			return;
		}

		setIsResending(true);

		router.post(route('password.otp.resend'), {
			email,
		}, {
			preserveScroll: true,
			onSuccess: () => {
				setSecondsLeft(TIMER_SECONDS);
				setDigits(Array(OTP_LENGTH).fill(''));
				inputRefs.current[0]?.focus();
				Swal.fire({
					icon: 'success',
					title: 'Code sent',
					text: 'A new verification code has been sent to your email.',
					confirmButtonColor: '#000000',
				});
			},
			onError: (err) => {
				setErrors((err.otp as string) || (err.email as string) || 'Unable to resend the code. Please try again.');
			},
			onFinish: () => {
				setIsResending(false);
			},
		});
	};

	const handleSubmit = (e: React.FormEvent<HTMLFormElement>) => {
		e.preventDefault();

		if (!email) {
			setErrors('Reset session missing. Please start again from the reset password step.');
			return;
		}

		if (otpValue.length !== OTP_LENGTH) {
			setErrors('Please enter the complete 6-digit code.');
			return;
		}

		setIsLoading(true);
		router.post(route('password.otp.verify'), {
			email,
			otp: otpValue,
		}, {
			onSuccess: () => {
				Swal.fire({
					icon: 'success',
					title: 'Code verified',
					text: 'You can now set a new password.',
					confirmButtonColor: '#000000',
				});
			},
			onError: (err) => {
				const message = (err.otp as string) || 'Invalid verification code. Please try again.';
				setErrors(message);
				Swal.fire({
					icon: 'error',
					title: 'Code verification failed',
					text: message,
					confirmButtonColor: '#000000',
				});
				setIsLoading(false);
			},
			onFinish: () => {
				setIsLoading(false);
			},
		});
	};

	return (
		<>
			<Head title="Verify Reset Code" />

			<div className="userside-auth-page min-h-screen bg-white font-outfit antialiased">
				<Navigation />

				<div className="max-w-480 mx-auto px-6 lg:px-12 py-24">
					<div className="text-center mb-12">
						<h1 className="userside-auth-title text-4xl lg:text-6xl font-bold text-gray-900 mb-6 tracking-tight">
							VERIFY CODE
						</h1>
						<p className="userside-auth-subtitle text-xl text-gray-600 max-w-2xl mx-auto leading-relaxed font-light">
							We sent a 6-digit verification code to {email || 'your email'}. Enter it to continue.
						</p>
						{status === 'otp-sent' && (
							<p className="text-sm text-green-700 mt-4">Code sent. Please check your inbox and spam folder.</p>
						)}
					</div>

					<div className="max-w-lg mx-auto">
						<div className="userside-auth-card bg-white rounded-2xl shadow-xl p-8">
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
												autoComplete="one-time-code"
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
									Didn't get a code?{' '}
									<button
										type="button"
										disabled={secondsLeft > 0 || isResending}
										onClick={handleResend}
										className="userside-auth-link font-semibold text-black hover:text-black/80 disabled:text-gray-400 disabled:cursor-not-allowed"
									>
										{secondsLeft > 0 ? `Resend in ${formatTime(secondsLeft)}` : (isResending ? 'Resending...' : 'Resend code')}
									</button>
								</div>

								<button
									type="submit"
									disabled={isLoading}
									className="userside-auth-primary w-full px-10 py-4 bg-black text-white font-semibold uppercase tracking-wider text-sm hover:bg-black/80 transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
								>
									{isLoading ? 'Verifying code...' : 'Verify code'}
								</button>
							</Form>

							<div className="mt-6 text-center space-y-2">
								<p className="text-gray-600">
									Wrong email?{' '}
									<Link
										href={route('password.request')}
										className="userside-auth-link text-black hover:text-black/80 font-semibold uppercase tracking-wider text-sm transition-colors"
									>
										Back to reset password
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
