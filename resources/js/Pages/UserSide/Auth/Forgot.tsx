import { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import Navigation from '../Shared/Navigation';
import Form from '../../../components/form/Form';
import Label from '../../../components/form/Label';
import Input from '../../../components/form/input/InputField';
import { MailIcon } from '../../../icons/index';

interface FormErrors {
	email?: string;
}

export default function Forgot() {
	const [email, setEmail] = useState('');
	const [errors, setErrors] = useState<FormErrors>({});
	const [isLoading, setIsLoading] = useState(false);

	const validateForm = (): boolean => {
		const newErrors: FormErrors = {};

		if (!email.trim()) {
			newErrors.email = 'Email is required';
		} else if (!/\S+@\S+\.\S+/.test(email)) {
			newErrors.email = 'Email is invalid';
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
			onError: (err) => {
				setErrors({
					email: (err.email as string) || 'Unable to send OTP. Please try again.',
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
							FORGOT PASSWORD
						</h1>
						<p className="text-xl text-gray-600 max-w-2xl mx-auto leading-relaxed font-light">
							Enter your account email and we will send you a reset link.
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
											className={`pl-10 ${errors.email ? 'border-red-500' : ''}`}
										/>
									</div>
									{errors.email && <p className="mt-1 text-sm text-red-600">{errors.email}</p>}
								</div>

								<button
									type="submit"
									disabled={isLoading}
									className="w-full px-10 py-4 bg-black text-white font-semibold uppercase tracking-wider text-sm hover:bg-black/80 transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
								>
									{isLoading ? 'Sending...' : 'Send OTP'}
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
