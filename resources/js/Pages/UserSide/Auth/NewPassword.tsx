import { useState } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import Navigation from '../Shared/Navigation';
import Form from '../../../components/form/Form';
import Label from '../../../components/form/Label';
import Input from '../../../components/form/input/InputField';
import { LockIcon } from '../../../icons/index';
import Swal from '@/Pages/UserSide/Shared/UserModal';

interface FormErrors {
	password?: string;
	confirmPassword?: string;
}

export default function NewPassword() {
	const page = usePage();
	const props = page.props as { email?: string };

	const emailFromQuery = typeof window !== 'undefined'
		? new URLSearchParams(window.location.search).get('email') || ''
		: '';

	const email = props.email || emailFromQuery;

	const [formData, setFormData] = useState({
		password: '',
		confirmPassword: '',
	});
	const [errors, setErrors] = useState<FormErrors>({});
	const [isLoading, setIsLoading] = useState(false);

	const validateForm = (): boolean => {
		const newErrors: FormErrors = {};

		if (!formData.password) {
			newErrors.password = 'Password is required';
		} else if (formData.password.length < 8) {
			newErrors.password = 'Password must be at least 8 characters';
		}

		if (!formData.confirmPassword) {
			newErrors.confirmPassword = 'Please confirm your password';
		} else if (formData.password !== formData.confirmPassword) {
			newErrors.confirmPassword = 'Passwords do not match';
		}

		setErrors(newErrors);
		return Object.keys(newErrors).length === 0;
	};

	const handleInputChange = (e: React.ChangeEvent<HTMLInputElement>) => {
		const { name, value } = e.target;
		setFormData((prev) => ({ ...prev, [name]: value }));
		if (errors[name as keyof FormErrors]) {
			setErrors((prev) => ({ ...prev, [name]: undefined }));
		}
	};

	const handleSubmit = (e: React.FormEvent<HTMLFormElement>) => {
		e.preventDefault();

		if (!validateForm()) return;

		setIsLoading(true);
		setTimeout(() => {
			setIsLoading(false);
			Swal.fire({
				icon: 'success',
				title: 'Password Updated',
				text: 'Your password has been reset successfully.',
				confirmButtonColor: '#000000',
			}).then(() => {
				router.visit(route('user.login.form'));
			});
		}, 900);
	};

	return (
		<>
			<Head title="Create New Password" />

			<div className="min-h-screen bg-white font-outfit antialiased">
				<Navigation />

				<div className="max-w-480 mx-auto px-6 lg:px-12 py-24">
					<div className="text-center mb-12">
						<h1 className="text-4xl lg:text-6xl font-bold text-gray-900 mb-6 tracking-tight">
							CREATE NEW PASSWORD
						</h1>
						<p className="text-xl text-gray-600 max-w-2xl mx-auto leading-relaxed font-light">
							Set a new password for {email || 'your account'}.
						</p>
					</div>

					<div className="max-w-lg mx-auto">
						<div className="bg-white rounded-2xl shadow-xl p-8">
							<Form onSubmit={handleSubmit} className="space-y-6">
								<div className="relative">
									<Label htmlFor="password">New Password</Label>
									<div className="relative">
										<LockIcon className="absolute left-3 top-1/2 transform -translate-y-1/2 w-5 h-5 text-gray-400" />
										<Input
											type="password"
											id="password"
											name="password"
											placeholder="Enter your new password"
											value={formData.password}
											onChange={handleInputChange}
											className={`pl-10 ${errors.password ? 'border-red-500' : ''}`}
										/>
									</div>
									{errors.password && <p className="mt-1 text-sm text-red-600">{errors.password}</p>}
								</div>

								<div className="relative">
									<Label htmlFor="confirmPassword">Confirm Password</Label>
									<div className="relative">
										<LockIcon className="absolute left-3 top-1/2 transform -translate-y-1/2 w-5 h-5 text-gray-400" />
										<Input
											type="password"
											id="confirmPassword"
											name="confirmPassword"
											placeholder="Confirm your new password"
											value={formData.confirmPassword}
											onChange={handleInputChange}
											className={`pl-10 ${errors.confirmPassword ? 'border-red-500' : ''}`}
										/>
									</div>
									{errors.confirmPassword && <p className="mt-1 text-sm text-red-600">{errors.confirmPassword}</p>}
								</div>

								<button
									type="submit"
									disabled={isLoading}
									className="w-full px-10 py-4 bg-black text-white font-semibold uppercase tracking-wider text-sm hover:bg-black/80 transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
								>
									{isLoading ? 'Updating...' : 'Update Password'}
								</button>
							</Form>

							<div className="mt-6 text-center space-y-2">
								<p className="text-gray-600">
									Need to verify OTP again?{' '}
									<Link
										href={route('password.otp', { email })}
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
