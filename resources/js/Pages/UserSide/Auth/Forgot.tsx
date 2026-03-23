import { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import Navigation from '../Shared/Navigation';
import Form from '../../../components/form/Form';
import Label from '../../../components/form/Label';
import Input from '../../../components/form/input/InputField';
import { MailIcon } from '../../../icons/index';
import Swal from '@/Pages/UserSide/Shared/UserModal';

interface FormErrors {
	email?: string;
}

export default function Forgot() {
	const [email, setEmail] = useState('');
	const [errors, setErrors] = useState<FormErrors>({});
	const [isLoading, setIsLoading] = useState(false);
	const authInputClasses = 'h-12 rounded-xl !border-gray-200 !bg-[#f8fafc] !text-[13px] !text-gray-800 placeholder:!text-gray-400 shadow-none focus:!border-gray-300 focus:!ring-gray-200/70 dark:!border-gray-200 dark:!bg-[#f8fafc] dark:!text-gray-800 dark:placeholder:!text-gray-400 dark:focus:!border-gray-300 dark:focus:!ring-gray-200/70';

	const validateForm = (): boolean => {
		const newErrors: FormErrors = {};

		if (!email.trim()) {
			newErrors.email = 'Enter your email address.';
		} else if (!/\S+@\S+\.\S+/.test(email)) {
			newErrors.email = 'Enter a valid email address.';
		}

		setErrors(newErrors);
		return Object.keys(newErrors).length === 0;
	};

	const handleSubmit = async (e: React.FormEvent<HTMLFormElement>) => {
		e.preventDefault();

		if (!validateForm()) return;

		setIsLoading(true);
		router.post(route('password.otp.send'), {
			email: email.trim(),
		}, {
			onSuccess: () => {
				Swal.fire({
					icon: 'success',
					title: 'Code sent',
					text: 'Check your email for the verification code.',
					confirmButtonColor: '#000000',
				});
			},
			onError: (err) => {
				setErrors({
					email: (err.email as string) || 'Unable to send the code. Please try again.',
				});
				Swal.fire({
					icon: 'error',
					title: 'Unable to send code',
					text: (err.email as string) || 'Please verify your email and try again.',
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
			<Head title="Forgot Password" />

			<div className="min-h-screen bg-white font-outfit antialiased">
				<Navigation />

				<div className="max-w-480 mx-auto px-6 lg:px-12 py-24">
					<div className="text-center mb-12">
						<h1 className="text-4xl lg:text-6xl font-bold text-gray-900 mb-6 tracking-tight">
							RESET PASSWORD
						</h1>
						<p className="text-xl text-gray-600 max-w-2xl mx-auto leading-relaxed font-light">
							Enter your account email and we’ll send a 6-digit verification code.
						</p>
					</div>

					<div className="max-w-lg mx-auto">
						<div className="bg-white rounded-2xl shadow-xl p-8">
							<Form onSubmit={handleSubmit} className="space-y-6">
								<div className="relative">
									<Label htmlFor="email">Email</Label>
									<div className="relative">
										<MailIcon className="absolute left-3 top-1/2 transform -translate-y-1/2 w-5 h-5 text-gray-400" />
										<Input
											type="email"
											id="email"
											name="email"
											placeholder="Enter your email address"
											value={email}
											onChange={(e: React.ChangeEvent<HTMLInputElement>) => {
												setEmail(e.target.value);
												if (errors.email) {
													setErrors({ email: undefined });
												}
											}}
											className={`pl-10 ${authInputClasses} ${errors.email ? 'border-red-500' : ''}`}
										/>
									</div>
									{errors.email && <p className="mt-1 text-sm text-red-600">{errors.email}</p>}
								</div>

								<button
									type="submit"
									disabled={isLoading}
									className="w-full px-10 py-4 bg-black text-white font-semibold uppercase tracking-wider text-sm hover:bg-black/80 transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
								>
									{isLoading ? 'Sending code...' : 'Send code'}
								</button>
							</Form>

							<div className="mt-6 text-center space-y-2">
								<p className="text-gray-600">
									Remembered your password?{' '}
									<Link
										href={route('user.login.form')}
										className="text-black hover:text-black/80 font-semibold uppercase tracking-wider text-sm transition-colors"
									>
										Sign in
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
