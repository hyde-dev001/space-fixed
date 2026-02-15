import React, { useEffect, useState } from 'react';
import { Head } from '@inertiajs/react';
import Navigation from './Navigation';

type ProfileData = {
	firstName: string;
	lastName: string;
	email: string;
	phone: string;
	bio: string;
	country: string;
	cityState: string;
	postalCode: string;
	taxId: string;
};

const CustomerProfile: React.FC = () => {
	const [profileData, setProfileData] = useState<ProfileData>({
		firstName: 'Musharof',
		lastName: 'Chowdhury',
		email: 'randomuser@pimjo.com',
		phone: '+09 363 398 46',
		bio: 'Team Manager',
		country: 'United States',
		cityState: 'Arizona, United States.',
		postalCode: 'ERT 2489',
		taxId: 'AS45683884',
	});
	const [photoFile, setPhotoFile] = useState<File | null>(null);
	const [photoPreview, setPhotoPreview] = useState<string | null>(null);
	const [isEditingPersonal, setIsEditingPersonal] = useState(false);
	const [isEditingAddress, setIsEditingAddress] = useState(false);
	const [personalSnapshot, setPersonalSnapshot] = useState<ProfileData | null>(null);
	const [addressSnapshot, setAddressSnapshot] = useState<ProfileData | null>(null);
	const [currentPassword, setCurrentPassword] = useState('');
	const [newPassword, setNewPassword] = useState('');
	const [confirmPassword, setConfirmPassword] = useState('');

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
		if (isEditingAddress) return;
		setPersonalSnapshot(profileData);
		setIsEditingPersonal(true);
	};

	const cancelPersonalEdit = () => {
		if (personalSnapshot) setProfileData(personalSnapshot);
		setPersonalSnapshot(null);
		setIsEditingPersonal(false);
	};

	const savePersonalEdit = () => {
		setPersonalSnapshot(null);
		setIsEditingPersonal(false);
	};

	const startAddressEdit = () => {
		if (isEditingPersonal) return;
		setAddressSnapshot(profileData);
		setIsEditingAddress(true);
	};

	const cancelAddressEdit = () => {
		if (addressSnapshot) setProfileData(addressSnapshot);
		setAddressSnapshot(null);
		setIsEditingAddress(false);
	};

	const saveAddressEdit = () => {
		setAddressSnapshot(null);
		setIsEditingAddress(false);
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
		window.alert('Password change is local only (frontend only).');
		setCurrentPassword('');
		setNewPassword('');
		setConfirmPassword('');
	};

	const fullName = `${profileData.firstName} ${profileData.lastName}`.trim();
	const displayName = fullName || 'Customer Name';
	const displayRole = profileData.bio || 'Team Manager';
	const displayLocation = profileData.cityState || 'Arizona, United States.';
	const displayEmail = profileData.email || 'randomuser@pimjo.com';
	const displayPhone = profileData.phone || '+09 363 398 46';

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
									<p className="text-sm text-gray-500 mt-1">{displayRole} | {displayLocation}</p>
								</div>
							</div>
							<div className="flex flex-wrap items-center gap-3">
								<label className="inline-flex items-center gap-2 rounded-full border border-gray-200 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 cursor-pointer">
									<span>Edit</span>
									<input
										type="file"
										accept="image/*"
										onChange={(e) => setPhotoFile(e.target.files?.[0] || null)}
										className="hidden"
									/>
								</label>
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
										className="inline-flex items-center gap-2 rounded-full border border-gray-900 bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-black"
										onClick={savePersonalEdit}
									>
										Save
									</button>
								</div>
							) : (
								<button
									type="button"
									className="inline-flex items-center gap-2 rounded-full border border-gray-200 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
									onClick={startPersonalEdit}
									disabled={isEditingAddress}
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
								{isEditingPersonal ? (
									<input
										type="email"
										value={profileData.email}
										onChange={(e) => updateProfileField('email', e.target.value)}
										className="mt-2 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm text-gray-900 focus:border-black focus:outline-none"
									/>
								) : (
									<p className="text-gray-900 font-medium mt-1">{displayEmail}</p>
								)}
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
										value={profileData.cityState}
										onChange={(e) => updateProfileField('cityState', e.target.value)}
										className="mt-2 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm text-gray-900 focus:border-black focus:outline-none"
									/>
								) : (
									<p className="text-gray-900 font-medium mt-1">{displayLocation}</p>
								)}
							</div>
							<div className="md:col-span-2">
								<p className="text-gray-400">Bio</p>
								{isEditingPersonal ? (
									<input
										type="text"
										value={profileData.bio}
										onChange={(e) => updateProfileField('bio', e.target.value)}
										className="mt-2 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm text-gray-900 focus:border-black focus:outline-none"
									/>
								) : (
									<p className="text-gray-900 font-medium mt-1">{displayRole}</p>
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
									className="inline-flex items-center gap-2 rounded-full border border-gray-900 bg-gray-900 px-5 py-2 text-sm font-medium text-white hover:bg-black"
								>
									Update password
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
