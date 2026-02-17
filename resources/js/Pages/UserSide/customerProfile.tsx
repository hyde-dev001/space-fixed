import React, { useEffect, useState } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import Navigation from './Navigation';

type ProfileData = {
	firstName: string;
	lastName: string;
	email: string;
	phone: string;
	address: string;
};

type PageProps = {
	user: {
		id: number;
		first_name: string;
		last_name: string;
		name: string;
		email: string;
		phone: string | null;
		address: string | null;
		profile_photo_url: string | null;
	};
	flash?: {
		success?: string;
		error?: string;
	};
	errors?: Record<string, string>;
};

const CustomerProfile: React.FC = () => {
	const { user, flash, errors } = usePage<PageProps>().props;
	const [profileData, setProfileData] = useState<ProfileData>({
		firstName: user.first_name || '',
		lastName: user.last_name || '',
		email: user.email || '',
		phone: user.phone || '',
		address: user.address || '',
	});
	const [photoFile, setPhotoFile] = useState<File | null>(null);
	const [photoPreview, setPhotoPreview] = useState<string | null>(user.profile_photo_url);
	const [isEditingPersonal, setIsEditingPersonal] = useState(false);
	const [personalSnapshot, setPersonalSnapshot] = useState<ProfileData | null>(null);
	const [currentPassword, setCurrentPassword] = useState('');
	const [newPassword, setNewPassword] = useState('');
	const [confirmPassword, setConfirmPassword] = useState('');
	const [isSubmitting, setIsSubmitting] = useState(false);

	useEffect(() => {
		if (!photoFile) return;
		const previewUrl = URL.createObjectURL(photoFile);
		setPhotoPreview(previewUrl);
		return () => URL.revokeObjectURL(previewUrl);
	}, [photoFile]);

	const updateProfileField = (field: keyof ProfileData, value: string) => {
		setProfileData((prev) => ({ ...prev, [field]: value }));
	};

	const startPersonalEdit = () => {
		setPersonalSnapshot(profileData);
		setIsEditingPersonal(true);
	};

	const cancelPersonalEdit = () => {
		if (personalSnapshot) setProfileData(personalSnapshot);
		setPersonalSnapshot(null);
		setIsEditingPersonal(false);
	};

	const handlePhotoChange = (e: React.ChangeEvent<HTMLInputElement>) => {
		const file = e.target.files?.[0];
		if (!file) return;
		
		setPhotoFile(file);
		
		// Auto-upload the photo immediately
		setIsSubmitting(true);
		const formData = new FormData();
		formData.append('first_name', profileData.firstName);
		formData.append('last_name', profileData.lastName);
		formData.append('phone', profileData.phone);
		formData.append('address', profileData.address);
		formData.append('profile_photo', file);

		router.post('/customer-profile', formData, {
			preserveScroll: true,
			onSuccess: () => {
				setIsSubmitting(false);
				window.alert('Profile photo updated successfully!');
			},
			onError: (errors) => {
				setIsSubmitting(false);
				setPhotoFile(null);
				const errorMsg = errors.profile_photo || 'Failed to upload photo. Please try again.';
				window.alert(errorMsg);
			},
		});
	};

	const savePersonalEdit = () => {
		setIsSubmitting(true);
		const formData = new FormData();
		formData.append('first_name', profileData.firstName);
		formData.append('last_name', profileData.lastName);
		formData.append('phone', profileData.phone);
		formData.append('address', profileData.address);

		router.post('/customer-profile', formData, {
			preserveScroll: true,
			onSuccess: () => {
				setPersonalSnapshot(null);
				setIsEditingPersonal(false);
				setIsSubmitting(false);
			},
			onError: () => {
				setIsSubmitting(false);
			},
		});
	};

	const startAddressEdit = () => {
		if (isEditingPersonal) return;
		setPersonalSnapshot(profileData);
		setIsEditingPersonal(true);
	};

	const cancelAddressEdit = () => {
		if (personalSnapshot) setProfileData(personalSnapshot);
		setPersonalSnapshot(null);
		setIsEditingPersonal(false);
	};

	const saveAddressEdit = () => {
		setIsSubmitting(true);
		const formData = new FormData();
		formData.append('first_name', profileData.firstName);
		formData.append('last_name', profileData.lastName);
		formData.append('phone', profileData.phone);
		formData.append('address', profileData.address);

		router.post('/customer-profile', formData, {
			preserveScroll: true,
			onSuccess: () => {
				setPersonalSnapshot(null);
				setIsEditingPersonal(false);
				setIsSubmitting(false);
			},
			onError: () => {
				setIsSubmitting(false);
			},
		});
	};

	const handlePasswordSubmit = (event: React.FormEvent) => {
		event.preventDefault();
		if (!currentPassword || !newPassword || !confirmPassword) {
			window.alert('Please fill in all password fields.');
			return;
		}
		if (newPassword !== confirmPassword) {
			window.alert('New password and confirmation do not match.');
			return;
		}

		setIsSubmitting(true);
		router.post('/customer-profile/password', {
			current_password: currentPassword,
			password: newPassword,
			password_confirmation: confirmPassword,
		}, {
			preserveScroll: true,
			onSuccess: () => {
				setCurrentPassword('');
				setNewPassword('');
				setConfirmPassword('');
				setIsSubmitting(false);
				window.alert('Password updated successfully!');
			},
			onError: (errors) => {
				setIsSubmitting(false);
				if (errors.current_password) {
					window.alert(errors.current_password);
				} else {
					window.alert('Password update failed. Please check your input.');
				}
			},
		});
	};

	const fullName = `${profileData.firstName} ${profileData.lastName}`.trim();
	const displayName = fullName || 'Customer Name';
	const displayEmail = profileData.email || user.email;
	const displayPhone = profileData.phone || 'No phone';
	const displayAddress = profileData.address || 'No address';

	// Show flash messages
	useEffect(() => {
		if (flash?.success) {
			window.alert(flash.success);
		}
		if (flash?.error) {
			window.alert(flash.error);
		}
	}, [flash]);

	return (
		<div className="min-h-screen bg-gray-50">
			<Head title="Edit Profile" />
			<Navigation />
			<div className="max-w-[1800px] mx-auto px-4 lg:px-10 py-12">
				<div className="rounded-3xl border border-gray-200 bg-white p-8 lg:p-10 shadow-sm">
					<h2 className="text-lg font-semibold text-gray-900 mb-6">Profile</h2>

					<div className="rounded-2xl border border-gray-200 bg-white px-6 py-6 lg:px-8">
						<div className="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-6">
							<div className="flex items-center gap-5">
								<div className="h-16 w-16 rounded-full border border-gray-200 overflow-hidden bg-gray-100 flex items-center justify-center">
									{photoPreview ? (
										<img src={photoPreview} alt="Profile" className="w-full h-full object-cover" />
									) : (
										<span className="text-xs text-gray-400">No photo</span>
									)}
								</div>
								<div>
									<h3 className="text-lg font-semibold text-gray-900">{displayName}</h3>
									<p className="text-sm text-gray-500 mt-1">{displayEmail}</p>
								</div>
							</div>
							<div className="flex flex-wrap items-center gap-3">
								<label className="inline-flex items-center gap-2 rounded-full border border-gray-200 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 cursor-pointer disabled:opacity-50">
									<span>{isSubmitting ? 'Uploading...' : 'Change Photo'}</span>
									<input
										type="file"
										accept="image/*"
										onChange={handlePhotoChange}
										className="hidden"
										disabled={isSubmitting}
									/>
								</label>
								{photoPreview && photoPreview !== user.profile_photo_url && (
									<span className="text-xs text-green-600">Photo uploaded!</span>
								)}
							</div>
						</div>
					</div>

					<div className="mt-8 rounded-2xl border border-gray-200 bg-white px-6 py-6 lg:px-8">
						<div className="flex items-center justify-between">
							<h3 className="text-base font-semibold text-gray-900">Personal Information</h3>
							{isEditingPersonal ? (
								<div className="flex items-center gap-2">
									<button
										type="button"
										className="inline-flex items-center gap-2 rounded-full border border-gray-200 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
										onClick={cancelPersonalEdit}
									>
										Cancel
									</button>
									<button
										type="button"
										className="inline-flex items-center gap-2 rounded-full border border-gray-900 bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-black disabled:opacity-50"
										onClick={savePersonalEdit}
										disabled={isSubmitting}
									>
										{isSubmitting ? 'Saving...' : 'Save'}
									</button>
								</div>
							) : (
								<button
									type="button"
									className="inline-flex items-center gap-2 rounded-full border border-gray-200 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
									onClick={startPersonalEdit}
									disabled={isEditingPersonal}
								>
									Edit
								</button>
							)}
						</div>
						<div className="mt-6 grid grid-cols-1 md:grid-cols-2 gap-y-6 gap-x-12 text-sm">
							<div>
								<p className="text-gray-400">First Name</p>
								{isEditingPersonal ? (
									<input
										type="text"
										value={profileData.firstName}
										onChange={(e) => updateProfileField('firstName', e.target.value)}
										className="mt-2 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm text-gray-900 focus:border-black focus:outline-none"
									/>
								) : (
									<p className="text-gray-900 font-medium mt-1">{profileData.firstName}</p>
								)}
							</div>
							<div>
								<p className="text-gray-400">Last Name</p>
								{isEditingPersonal ? (
									<input
										type="text"
										value={profileData.lastName}
										onChange={(e) => updateProfileField('lastName', e.target.value)}
										className="mt-2 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm text-gray-900 focus:border-black focus:outline-none"
									/>
								) : (
									<p className="text-gray-900 font-medium mt-1">{profileData.lastName}</p>
								)}
							</div>
							<div>
								<p className="text-gray-400">Email address</p>
								<p className="text-gray-900 font-medium mt-1">{displayEmail}</p>
							</div>
							<div>
								<p className="text-gray-400">Phone</p>
								{isEditingPersonal ? (
									<input
										type="text"
										value={profileData.phone}
										onChange={(e) => updateProfileField('phone', e.target.value)}
										className="mt-2 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm text-gray-900 focus:border-black focus:outline-none"
									/>
								) : (
									<p className="text-gray-900 font-medium mt-1">{displayPhone}</p>
								)}
							</div>
							<div className="md:col-span-2">
								<p className="text-gray-400">Address</p>
								{isEditingPersonal ? (
									<input
										type="text"
										value={profileData.address}
										onChange={(e) => updateProfileField('address', e.target.value)}
										className="mt-2 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm text-gray-900 focus:border-black focus:outline-none"
									/>
								) : (
									<p className="text-gray-900 font-medium mt-1">{displayAddress}</p>
								)}
							</div>
						</div>
					</div>

					<div className="mt-8 rounded-2xl border border-gray-200 bg-white px-6 py-6 lg:px-8">
						<h3 className="text-base font-semibold text-gray-900">Change Password</h3>
						<form onSubmit={handlePasswordSubmit} className="mt-6 grid grid-cols-1 md:grid-cols-2 gap-6 text-sm">
							<div className="md:col-span-2">
								<label className="text-gray-400">Current password</label>
								<input
									type="password"
									value={currentPassword}
									onChange={(e) => setCurrentPassword(e.target.value)}
									className="mt-2 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm text-gray-900 focus:border-black focus:outline-none"
								/>
							</div>
							<div>
								<label className="text-gray-400">New password</label>
								<input
									type="password"
									value={newPassword}
									onChange={(e) => setNewPassword(e.target.value)}
									className="mt-2 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm text-gray-900 focus:border-black focus:outline-none"
								/>
							</div>
							<div>
								<label className="text-gray-400">Confirm new password</label>
								<input
									type="password"
									value={confirmPassword}
									onChange={(e) => setConfirmPassword(e.target.value)}
									className="mt-2 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm text-gray-900 focus:border-black focus:outline-none"
								/>
							</div>
							<div className="md:col-span-2">
								<button
									type="submit"
									className="inline-flex items-center gap-2 rounded-full border border-gray-900 bg-gray-900 px-5 py-2 text-sm font-medium text-white hover:bg-black disabled:opacity-50"
									disabled={isSubmitting}
								>
									{isSubmitting ? 'Updating...' : 'Update password'}
								</button>
							</div>
						</form>
					</div>
				</div>
			</div>
		</div>
	);
};

export default CustomerProfile;
