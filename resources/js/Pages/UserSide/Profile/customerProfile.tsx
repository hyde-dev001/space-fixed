import React, { useEffect, useState } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import Navigation from '../Shared/Navigation';
import Swal from '../Shared/UserModal';
import { useBadgeCounts } from '../../../hooks/useBadgeCounts';
import CustomerFooter from '../../../components/common/CustomerFooter';

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
	orderStatusCount?: number;
	repairStatusCount?: number;
};

const CustomerProfile: React.FC = () => {
	const page = usePage<PageProps>();
	const {
		user,
		flash,
		errors,
		orderStatusCount = 0,
		repairStatusCount = 0,
	} = page.props;
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
		if (!isEditingPersonal) {
			setCurrentPassword('');
			setNewPassword('');
			setConfirmPassword('');
		}
	}, [isEditingPersonal]);

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
				Swal.fire({
					icon: 'success',
					title: 'Photo uploaded!',
					text: 'Your profile picture has been updated successfully.',
					confirmButtonColor: '#111827',
				});
			},
			onError: (errors) => {
				setIsSubmitting(false);
				setPhotoFile(null);
				const errorMsg = errors.profile_photo || 'Failed to upload photo. Please try again.';
				Swal.fire({
					icon: 'error',
					title: 'Photo upload failed',
					text: errorMsg,
					confirmButtonColor: '#111827',
				});
			},
		});
	};

	const savePersonalEdit = async () => {
		const result = await Swal.fire({
			title: 'Save changes?',
			text: 'Are you sure you want to save your personal information?',
			icon: 'question',
			showCancelButton: true,
			confirmButtonText: 'Save',
			cancelButtonText: 'Cancel',
			confirmButtonColor: '#111827',
		});

		if (!result.isConfirmed) return;

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

	const saveAddressEdit = async () => {
		const result = await Swal.fire({
			title: 'Save changes?',
			text: 'Are you sure you want to save your address?',
			icon: 'question',
			showCancelButton: true,
			confirmButtonText: 'Save',
			cancelButtonText: 'Cancel',
			confirmButtonColor: '#111827',
		});

		if (!result.isConfirmed) return;

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
			Swal.fire({
				icon: 'warning',
				title: 'Missing fields',
				text: 'Please fill in all password fields.',
				confirmButtonColor: '#111827',
			});
			return;
		}
		if (newPassword !== confirmPassword) {
			Swal.fire({
				icon: 'error',
				title: 'Password mismatch',
				text: 'New password and confirmation do not match.',
				confirmButtonColor: '#111827',
			});
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
				Swal.fire({
					icon: 'success',
					title: 'Password updated',
					text: 'Your password has been updated successfully.',
					confirmButtonColor: '#111827',
				});
			},
			onError: (errors) => {
				setIsSubmitting(false);
				if (errors.current_password) {
					Swal.fire({
						icon: 'error',
						title: 'Password update failed',
						text: errors.current_password,
						confirmButtonColor: '#111827',
					});
				} else {
					Swal.fire({
						icon: 'error',
						title: 'Password update failed',
						text: 'Please check your input and try again.',
						confirmButtonColor: '#111827',
					});
				}
			},
		});
	};

	const fullName = `${profileData.firstName} ${profileData.lastName}`.trim();
	const displayName = fullName || 'Customer Name';
	const displayEmail = profileData.email || user.email;
	const displayPhone = profileData.phone || 'No phone';
	const displayAddress = profileData.address || 'No address';
	const profileInitial = (profileData.firstName || displayName || 'M').charAt(0).toUpperCase();
	const authUser = (page.props as any)?.auth?.user;
	const isAuthenticated = Boolean(authUser && !authUser.shop_owner_id);
	const initialChatIconCount = Number((page.props as any)?.chatIconCount ?? 0);
	const liveBadgeCounts = useBadgeCounts(isAuthenticated, {
		chatIconCount: initialChatIconCount,
	});
	const chatIconCount = isAuthenticated
		? liveBadgeCounts.chatIconCount
		: initialChatIconCount;
	const currentPath = page.url.split('?')[0];
	type TileIcon = 'pay' | 'ship' | 'receive' | 'rate' | 'pending' | 'accepted' | 'progress' | 'completed';
	const purchaseTiles: Array<{ label: string; icon: TileIcon; href: string }> = [
		{ label: 'To Pay', icon: 'pay', href: '/my-orders?tab=pending' },
		{ label: 'To Ship', icon: 'ship', href: '/my-orders?tab=shipped' },
		{ label: 'To Receive', icon: 'receive', href: '/my-orders?tab=shipped' },
		{ label: 'To Rate', icon: 'rate', href: '/my-orders?tab=completed' },
	];
	const repairTiles: Array<{ label: string; icon: TileIcon; href: string }> = [
		{ label: 'Pending', icon: 'pending', href: '/my-repairs?tab=pending' },
		{ label: 'Accepted', icon: 'accepted', href: '/my-repairs?tab=pending' },
		{ label: 'In Progress', icon: 'progress', href: '/my-repairs?tab=in_progress' },
		{ label: 'Completed', icon: 'completed', href: '/my-repairs?tab=completed' },
	];

	const renderTileIcon = (icon: TileIcon) => {
		switch (icon) {
			case 'pay':
				return <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.8} d="M4 9h16M6 6h12a2 2 0 012 2v8a2 2 0 01-2 2H6a2 2 0 01-2-2V8a2 2 0 012-2zm2 7h4" />;
			case 'ship':
				return <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.8} d="M3 10.5l9-6 9 6m-2 1.5v6.5L12 22l-7-3.5V12m14 0l-7 3.5L5 12" />;
			case 'receive':
				return <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.8} d="M7 12h8m0 0l-2.5-2.5M15 12l-2.5 2.5M4 7h5l2-2h2l2 2h5v10a2 2 0 01-2 2H6a2 2 0 01-2-2V7z" />;
			case 'rate':
				return <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.8} d="M12 4l2.2 4.5 5 .7-3.6 3.5.9 5-4.5-2.4L7.5 18l.9-5L4.8 9.2l5-.7L12 4z" />;
			case 'pending':
				return <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.8} d="M12 7v5l3 2m5-2a8 8 0 11-16 0 8 8 0 0116 0z" />;
			case 'accepted':
				return <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.8} d="M7 12.5l3.2 3.2L17 9" />;
			case 'progress':
				return <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.8} d="M15 6l3 3-3 3M9 18l-3-3 3-3M18 9H9m6 6h-9" />;
			case 'completed':
				return <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.8} d="M12 6a5 5 0 015 5c0 3-5 7-5 7s-5-4-5-7a5 5 0 015-5zm0 3.2a1.8 1.8 0 100 3.6 1.8 1.8 0 000-3.6z" />;
			default:
				return null;
		}
	};
	const activeMobileTab =
		currentPath === '/'
			? 'home'
			: currentPath.startsWith('/products')
				? 'products'
				: currentPath.startsWith('/customer-profile')
					? 'me'
					: currentPath.startsWith('/messages')
						? 'inbox'
						: currentPath.startsWith('/repair')
							? 'repair'
							: '';
	const mobileNavItemClasses = (isActive: boolean) =>
		`group relative flex flex-col items-center justify-center gap-1 rounded-lg px-1 py-0.5 transition-all duration-300 ${
			isActive ? 'text-[#16233b]' : 'text-gray-600 hover:text-[#16233b]'
		}`;
	const mobileNavIconClasses = (isActive: boolean) =>
		`h-5 w-5 transition-all duration-300 ${isActive ? 'scale-110' : 'scale-100'}`;
	const mobileNavLabelClasses = (isActive: boolean) =>
		`transition-all duration-300 ${isActive ? 'font-semibold' : 'font-normal'}`;

	// Show flash messages
	useEffect(() => {
		if (flash?.success) {
			Swal.fire({
				icon: 'success',
				title: 'Success',
				text: flash.success,
				confirmButtonColor: '#111827',
			});
		}
		if (flash?.error) {
			Swal.fire({
				icon: 'error',
				title: 'Error',
				text: flash.error,
				confirmButtonColor: '#111827',
			});
		}
	}, [flash]);

	return (
		<div className="min-h-screen bg-gray-50">
			<Head title="Edit Profile" />
			<Navigation />
			<div className="mx-auto max-w-[480px] px-4 pb-24 pt-24 text-[#1b2940] md:max-w-none md:w-full md:px-6 md:text-base xl:max-w-[1800px] xl:px-10 xl:pb-12 xl:pt-32">
				<div className="space-y-4 md:flex md:min-h-[calc(100vh-11.5rem)] md:flex-col md:space-y-5 xl:hidden">
					<div className="rounded-[28px] bg-linear-to-br from-[#1e3050] via-[#16233b] to-[#0d1a2c] px-4 py-4 text-white shadow-[0_24px_45px_-28px_rgba(15,23,42,0.55)] md:px-5 md:py-5">
						<div className="flex items-start justify-between gap-3">
							<span className="inline-flex rounded-full bg-white/14 px-3 py-1 text-[10px] font-semibold uppercase tracking-[0.22em] text-white/95 md:text-[11px]">
								My Account
							</span>
							<div className="flex items-center gap-2">
								<a href="/checkout" title="Open cart" aria-label="Open cart" className="inline-flex h-9 w-9 items-center justify-center rounded-full border border-white/20 bg-white/10 text-white">
									<svg className="h-4.5 w-4.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
										<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 4h2l2.2 10.2a2 2 0 001.96 1.58h7.68a2 2 0 001.95-1.56L21 7H8" />
										<circle cx="10" cy="19" r="1.5" strokeWidth={2} />
										<circle cx="17" cy="19" r="1.5" strokeWidth={2} />
									</svg>
								</a>
								<a href="/messages" title="Open messages" aria-label="Open messages" className="relative inline-flex h-9 w-9 items-center justify-center rounded-full border border-white/20 bg-white/10 text-white">
									<svg className="h-4.5 w-4.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
										<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M7 8h10M7 12h6m-8 7l3.5-2H19a3 3 0 003-3V7a3 3 0 00-3-3H5a3 3 0 00-3 3v7a3 3 0 003 3h1l1 2z" />
									</svg>
									{chatIconCount > 0 && (
										<span className="absolute -right-1 -top-1 inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-red-500 px-1.5 text-[10px] font-bold leading-none text-white ring-2 ring-[#16233b]">
											{chatIconCount > 99 ? '99+' : chatIconCount}
										</span>
									)}
								</a>
							</div>
						</div>
						<div className="mt-4 flex items-center gap-3.5 md:gap-4">
							<div className="relative">
                                <div className="flex h-14 w-14 shrink-0 items-center justify-center rounded-full border border-white/35 bg-gray-950 dark:bg-white/12 text-lg font-semibold uppercase text-white md:h-16 md:w-16 md:text-xl">
									{photoPreview ? (
										<img src={photoPreview} alt="Profile" className="h-full w-full rounded-full object-cover" />
									) : (
										profileInitial
									)}
								</div>
								<label className="absolute -bottom-1 -right-1 inline-flex h-7 w-7 cursor-pointer items-center justify-center rounded-full border border-white/50 bg-white/20 text-white backdrop-blur-sm transition hover:bg-white/30 md:h-8 md:w-8 disabled:cursor-not-allowed disabled:opacity-60">
									<span className="sr-only">Upload profile picture</span>
									<svg className="h-3.5 w-3.5 md:h-4 md:w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
										<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 7a2 2 0 012-2h2l1.1-1.5A2 2 0 0110.7 3h2.6a2 2 0 011.6.5L16 5h2a2 2 0 012 2v9a2 2 0 01-2 2H6a2 2 0 01-2-2V7z" />
										<circle cx="12" cy="12" r="3" strokeWidth={2} />
									</svg>
									<input type="file" accept="image/*" onChange={handlePhotoChange} className="hidden" disabled={isSubmitting} />
								</label>
							</div>
							<div className="min-w-0">
								<h1 className="truncate text-[1.15rem] font-semibold leading-tight text-white md:text-[1.45rem]">{displayName}</h1>
								<p className="truncate text-xs text-white/82 md:text-sm">{displayEmail}</p>
								<div className="mt-2 inline-flex rounded-full bg-white/14 px-3 py-1 text-[10px] font-medium text-white/92 md:text-[11px]">
									SoleSpace Member
								</div>
								<p className="mt-2 text-[10px] text-white/85 md:text-xs">
									{isSubmitting ? 'Uploading photo...' : 'Tap camera icon to change profile photo'}
								</p>
							</div>
						</div>
					</div>

					<div className="rounded-[28px] border border-[#dfe4ea] bg-white px-4 py-4 shadow-[0_14px_28px_-24px_rgba(15,23,42,0.6)] md:px-6 md:py-6">
						<div className="mb-4 flex items-center justify-between">
							<h2 className="text-[1.02rem] font-semibold text-[#16233b] md:text-[1.3rem]">My Purchases</h2>
							<a href="/my-orders" className="text-xs font-medium text-[#2e3f5c] hover:text-[#16233b] md:text-base">View History</a>
						</div>
						<div className="grid grid-cols-4 gap-2.5 md:gap-3">
							{purchaseTiles.map((tile) => (
								<Link key={tile.label} href={tile.href} className="rounded-[14px] border border-[#e6e9ef] bg-[#f8f8f9] px-1.5 py-3 text-center transition-colors hover:bg-[#f2f5f9] md:py-4">
									<div className="mx-auto flex h-6 w-6 items-center justify-center rounded-full bg-[#eef1f6] text-[#667287] ring-1 ring-[#d8dee8] md:h-8 md:w-8">
										<svg className="h-3.5 w-3.5 md:h-4 md:w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
											{renderTileIcon(tile.icon)}
										</svg>
									</div>
									<div className="mt-2.5 text-[11px] font-medium tracking-[0.01em] text-[#2a3953] md:text-sm md:font-semibold">{tile.label}</div>
								</Link>
							))}
						</div>
					</div>

					<div className="rounded-[28px] border border-[#dfe4ea] bg-white px-4 py-4 shadow-[0_14px_28px_-24px_rgba(15,23,42,0.6)] md:px-6 md:py-6">
						<div className="mb-4 flex items-center justify-between">
							<h2 className="text-[1.02rem] font-semibold text-[#16233b] md:text-[1.3rem]">Repairs</h2>
							<a href="/repair-services" className="text-xs font-medium text-[#2e3f5c] hover:text-[#16233b] md:text-base">View History</a>
						</div>
						<div className="grid grid-cols-4 gap-2.5 md:gap-3">
							{repairTiles.map((tile) => (
								<Link key={tile.label} href={tile.href} className="rounded-[14px] border border-[#e6e9ef] bg-[#f8f8f9] px-1.5 py-3 text-center transition-colors hover:bg-[#f2f5f9] md:py-4">
									<div className="mx-auto flex h-6 w-6 items-center justify-center rounded-full bg-[#eef1f6] text-[#667287] ring-1 ring-[#d8dee8] md:h-8 md:w-8">
										<svg className="h-3.5 w-3.5 md:h-4 md:w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
											{renderTileIcon(tile.icon)}
										</svg>
									</div>
									<div className="mt-2.5 text-[11px] font-medium tracking-[0.01em] text-[#2a3953] md:text-sm md:font-semibold">{tile.label}</div>
								</Link>
							))}
						</div>
					</div>

					<div className="rounded-[28px] border border-[#dfe4ea] bg-white px-4 py-4 shadow-[0_14px_28px_-24px_rgba(15,23,42,0.6)] md:flex-1 md:px-6 md:py-6">
						<div className="mb-4 flex items-center justify-between">
							<h2 className="text-[1.02rem] font-semibold text-[#16233b] md:text-[1.3rem]">Profile Settings</h2>
							{isEditingPersonal ? (
								<div className="flex items-center gap-2">
									<button type="button" className="rounded-full border border-gray-200 px-3 py-1.5 text-xs font-medium text-[#4a5870] md:text-sm" onClick={cancelPersonalEdit}>Cancel</button>
									<button type="button" className="rounded-full bg-[#16233b] px-3 py-1.5 text-xs font-medium text-white md:text-sm" onClick={savePersonalEdit} disabled={isSubmitting}>{isSubmitting ? 'Saving...' : 'Save'}</button>
								</div>
							) : (
								<button type="button" className="text-xs font-medium text-[#4a5870] hover:text-[#16233b] md:text-sm" onClick={startPersonalEdit}>Edit</button>
							)}
						</div>

						{isEditingPersonal ? (
							<div className="space-y-3">
								<input type="text" value={profileData.firstName} onChange={(e) => updateProfileField('firstName', e.target.value)} placeholder="Enter first name" title="First name" className="w-full rounded-2xl border border-gray-200 px-3 py-3 text-sm text-gray-900 focus:border-black focus:outline-none md:text-base" />
								<input type="text" value={profileData.lastName} onChange={(e) => updateProfileField('lastName', e.target.value)} placeholder="Enter last name" title="Last name" className="w-full rounded-2xl border border-gray-200 px-3 py-3 text-sm text-gray-900 focus:border-black focus:outline-none md:text-base" />
								<input type="text" value={profileData.phone} onChange={(e) => updateProfileField('phone', e.target.value)} placeholder="Enter phone number" title="Phone" className="w-full rounded-2xl border border-gray-200 px-3 py-3 text-sm text-gray-900 focus:border-black focus:outline-none md:text-base" />
								<input type="text" value={profileData.address} onChange={(e) => updateProfileField('address', e.target.value)} placeholder="Enter address" title="Address" className="w-full rounded-2xl border border-gray-200 px-3 py-3 text-sm text-gray-900 focus:border-black focus:outline-none md:text-base" />
							</div>
						) : (
							<div className="space-y-3 border-t border-gray-100 pt-1 md:space-y-4">
								<div>
									<p className="text-[10px] uppercase tracking-[0.18em] text-[#5f6d87] md:text-xs">First Name</p>
									<p className="mt-1 text-sm font-medium text-[#16233b] md:text-base">{profileData.firstName || 'Not set'}</p>
								</div>
								<div>
									<p className="text-[10px] uppercase tracking-[0.18em] text-[#5f6d87] md:text-xs">Last Name</p>
									<p className="mt-1 text-sm font-medium text-[#16233b] md:text-base">{profileData.lastName || 'Not set'}</p>
								</div>
								<div>
									<p className="text-[10px] uppercase tracking-[0.18em] text-[#5f6d87] md:text-xs">Phone</p>
									<p className="mt-1 text-sm font-medium text-[#16233b] md:text-base">{displayPhone}</p>
								</div>
								<div>
									<p className="text-[10px] uppercase tracking-[0.18em] text-[#5f6d87] md:text-xs">Address</p>
									<p className="mt-1 text-sm font-medium text-[#16233b] md:text-base">{displayAddress}</p>
								</div>
							</div>
						)}
					</div>

					<div className="rounded-[28px] border border-gray-200 bg-white px-4 py-4 shadow-sm">
					<h2 className="mb-4 text-[1.02rem] font-semibold text-gray-900">Change Password</h2>
					<form onSubmit={handlePasswordSubmit} className="space-y-3">
						<input type="password" value={currentPassword} onChange={(e) => setCurrentPassword(e.target.value)} placeholder="Enter current password" title="Current password" className="w-full rounded-2xl border border-gray-200 px-3 py-3 text-sm text-gray-900 focus:border-black focus:outline-none" />
						<input type="password" value={newPassword} onChange={(e) => setNewPassword(e.target.value)} placeholder="Enter new password" title="New password" className="w-full rounded-2xl border border-gray-200 px-3 py-3 text-sm text-gray-900 focus:border-black focus:outline-none" />
						<input type="password" value={confirmPassword} onChange={(e) => setConfirmPassword(e.target.value)} placeholder="Confirm new password" title="Confirm new password" className="w-full rounded-2xl border border-gray-200 px-3 py-3 text-sm text-gray-900 focus:border-black focus:outline-none" />
						<button type="submit" className="inline-flex w-full items-center justify-center rounded-full bg-[#16233b] px-4 py-3 text-sm font-medium text-white" disabled={isSubmitting}>
							{isSubmitting ? 'Updating...' : 'Update password'}
						</button>
					</form>
					</div>
				</div>

				<div className="hidden xl:block rounded-3xl border border-gray-200 bg-white p-8 shadow-sm lg:p-10">
					<h2 className="mb-6 text-lg font-semibold text-gray-900">Profile</h2>

					<div className="rounded-2xl border border-gray-200 bg-white px-6 py-6 lg:px-8">
						<div className="flex flex-col gap-6 lg:flex-row lg:items-center lg:justify-between">
							<div className="flex items-center gap-5">
								<div className="flex h-16 w-16 items-center justify-center overflow-hidden rounded-full border border-gray-200 bg-gray-100">
									{photoPreview ? (
										<img src={photoPreview} alt="Profile" className="h-full w-full object-cover" />
									) : (
										<span className="text-xs text-gray-400">No photo</span>
									)}
								</div>
								<div>
									<h3 className="text-lg font-semibold text-gray-900">{displayName}</h3>
									<p className="mt-1 text-sm text-gray-500">{displayEmail}</p>
								</div>
							</div>
							<div className="flex flex-wrap items-center gap-3">
								<label className="inline-flex cursor-pointer items-center gap-2 rounded-full border border-gray-200 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50">
									<span>{isSubmitting ? 'Uploading...' : 'Change Photo'}</span>
									<input type="file" accept="image/*" onChange={handlePhotoChange} className="hidden" disabled={isSubmitting} />
								</label>
							</div>
						</div>
					</div>

					<div className="mt-8 rounded-2xl border border-gray-200 bg-white px-6 py-6 lg:px-8">
						<div className="flex items-center justify-between">
							<h3 className="text-base font-semibold text-gray-900">Personal Information</h3>
							{isEditingPersonal ? (
								<div className="flex items-center gap-2">
									<button type="button" className="inline-flex items-center gap-2 rounded-full border border-gray-200 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50" onClick={cancelPersonalEdit}>Cancel</button>
									<button type="button" className="inline-flex items-center gap-2 rounded-full border border-gray-900 bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-black disabled:opacity-50" onClick={savePersonalEdit} disabled={isSubmitting}>{isSubmitting ? 'Saving...' : 'Save'}</button>
								</div>
							) : (
								<button type="button" className="inline-flex items-center gap-2 rounded-full border border-gray-200 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50" onClick={startPersonalEdit} disabled={isEditingPersonal}>Edit</button>
							)}
						</div>
						<div className="mt-6 grid grid-cols-1 gap-x-12 gap-y-6 text-sm md:grid-cols-2">
							<div>
								<p className="text-gray-400">First Name</p>
								{isEditingPersonal ? (
									<input type="text" value={profileData.firstName} onChange={(e) => updateProfileField('firstName', e.target.value)} placeholder="Enter first name" title="First name" className="mt-2 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm text-gray-900 focus:border-black focus:outline-none" />
								) : (
									<p className="mt-1 font-medium text-gray-900">{profileData.firstName}</p>
								)}
							</div>
							<div>
								<p className="text-gray-400">Last Name</p>
								{isEditingPersonal ? (
									<input type="text" value={profileData.lastName} onChange={(e) => updateProfileField('lastName', e.target.value)} placeholder="Enter last name" title="Last name" className="mt-2 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm text-gray-900 focus:border-black focus:outline-none" />
								) : (
									<p className="mt-1 font-medium text-gray-900">{profileData.lastName}</p>
								)}
							</div>
							<div>
								<p className="text-gray-400">Email address</p>
								<p className="mt-1 font-medium text-gray-900">{displayEmail}</p>
							</div>
							<div>
								<p className="text-gray-400">Phone</p>
								{isEditingPersonal ? (
									<input type="text" value={profileData.phone} onChange={(e) => updateProfileField('phone', e.target.value)} placeholder="Enter phone number" title="Phone" className="mt-2 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm text-gray-900 focus:border-black focus:outline-none" />
								) : (
									<p className="mt-1 font-medium text-gray-900">{displayPhone}</p>
								)}
							</div>
							<div className="md:col-span-2">
								<p className="text-gray-400">Address</p>
								{isEditingPersonal ? (
									<input type="text" value={profileData.address} onChange={(e) => updateProfileField('address', e.target.value)} placeholder="Enter address" title="Address" className="mt-2 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm text-gray-900 focus:border-black focus:outline-none" />
								) : (
									<p className="mt-1 font-medium text-gray-900">{displayAddress}</p>
								)}
							</div>
						</div>
					</div>

					<div className="mt-8 rounded-2xl border border-gray-200 bg-white px-6 py-6 lg:px-8">
					<h3 className="text-base font-semibold text-gray-900">Change Password</h3>
					<form onSubmit={handlePasswordSubmit} className="mt-6 grid grid-cols-1 gap-6 text-sm md:grid-cols-2">
						<div className="md:col-span-2">
							<label className="text-gray-400">Current password</label>
							<input type="password" value={currentPassword} onChange={(e) => setCurrentPassword(e.target.value)} placeholder="Enter current password" title="Current password" className="mt-2 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm text-gray-900 focus:border-black focus:outline-none" />
						</div>
						<div>
							<label className="text-gray-400">New password</label>
							<input type="password" value={newPassword} onChange={(e) => setNewPassword(e.target.value)} placeholder="Enter new password" title="New password" className="mt-2 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm text-gray-900 focus:border-black focus:outline-none" />
						</div>
						<div>
							<label className="text-gray-400">Confirm new password</label>
							<input type="password" value={confirmPassword} onChange={(e) => setConfirmPassword(e.target.value)} placeholder="Confirm new password" title="Confirm new password" className="mt-2 w-full rounded-lg border border-gray-200 px-3 py-2 text-sm text-gray-900 focus:border-black focus:outline-none" />
						</div>
						<div className="md:col-span-2">
							<button type="submit" className="inline-flex items-center gap-2 rounded-full border border-gray-900 bg-gray-900 px-5 py-2 text-sm font-medium text-white hover:bg-black disabled:opacity-50" disabled={isSubmitting}>{isSubmitting ? 'Updating...' : 'Update password'}</button>
						</div>
					</form>
					</div>
				</div>
			</div>

			<CustomerFooter className="mt-24" />

			<div className="fixed bottom-0 left-0 right-0 z-40 border-t border-gray-200 bg-white xl:hidden">
				<div className="mx-auto grid max-w-[480px] grid-cols-5 px-2 py-2 text-[11px] text-gray-600 md:max-w-none md:px-4">
					<a href="/" className={mobileNavItemClasses(activeMobileTab === 'home')}>
						<span className={`absolute -top-2 h-0.5 w-6 rounded-full bg-[#16233b] transition-all duration-300 ${activeMobileTab === 'home' ? 'opacity-100 scale-x-100' : 'opacity-0 scale-x-0'}`} />
						<svg className={mobileNavIconClasses(activeMobileTab === 'home')} fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 10.5l9-7 9 7V20a1 1 0 01-1 1h-5v-6H9v6H4a1 1 0 01-1-1v-9.5z" /></svg>
						<span className={mobileNavLabelClasses(activeMobileTab === 'home')}>Home</span>
					</a>
					<a href="/products" className={mobileNavItemClasses(activeMobileTab === 'products')}>
						<span className={`absolute -top-2 h-0.5 w-6 rounded-full bg-[#16233b] transition-all duration-300 ${activeMobileTab === 'products' ? 'opacity-100 scale-x-100' : 'opacity-0 scale-x-0'}`} />
						<svg className={mobileNavIconClasses(activeMobileTab === 'products')} fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M3 15h4l2.2-3.2a1 1 0 01.82-.43H14l3.2 2.4a2 2 0 001.2.4H21v3H3v-2z" /><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 15h.01M17 15h.01" /></svg>
						<span className={mobileNavLabelClasses(activeMobileTab === 'products')}>Products</span>
					</a>
					<a href="/repair-services" className={mobileNavItemClasses(activeMobileTab === 'repair')}>
						<span className={`absolute -top-2 h-0.5 w-6 rounded-full bg-[#16233b] transition-all duration-300 ${activeMobileTab === 'repair' ? 'opacity-100 scale-x-100' : 'opacity-0 scale-x-0'}`} />
						<svg className={mobileNavIconClasses(activeMobileTab === 'repair')} fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M14.7 6.3a4 4 0 01-5.4 5.4l-5.2 5.2a1 1 0 000 1.4l1.3 1.3a1 1 0 001.4 0l5.2-5.2a4 4 0 005.4-5.4l-2.1 2.1-2.3-.5-.5-2.3 2.2-2.1z" /></svg>
						<span className={mobileNavLabelClasses(activeMobileTab === 'repair')}>Repair</span>
					</a>
					<a href="/messages" className={mobileNavItemClasses(activeMobileTab === 'inbox')}>
						<span className={`absolute -top-2 h-0.5 w-6 rounded-full bg-[#16233b] transition-all duration-300 ${activeMobileTab === 'inbox' ? 'opacity-100 scale-x-100' : 'opacity-0 scale-x-0'}`} />
						<div className="relative">
							<svg className={mobileNavIconClasses(activeMobileTab === 'inbox')} fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M7 8h10M7 12h6m-8 7l3.5-2H19a3 3 0 003-3V7a3 3 0 00-3-3H5a3 3 0 00-3 3v7a3 3 0 003 3h1l1 2z" /></svg>
							{chatIconCount > 0 && (
								<span className="absolute -right-2 -top-1.5 inline-flex h-4.5 min-w-4.5 items-center justify-center rounded-full bg-red-500 px-1 text-[10px] font-bold leading-none text-white ring-2 ring-white">
									{chatIconCount > 99 ? '99+' : chatIconCount}
								</span>
							)}
						</div>
						<span className={mobileNavLabelClasses(activeMobileTab === 'inbox')}>Inbox</span>
					</a>
					<a href="/customer-profile" className={mobileNavItemClasses(activeMobileTab === 'me')}>
						<span className={`absolute -top-2 h-0.5 w-6 rounded-full bg-[#16233b] transition-all duration-300 ${activeMobileTab === 'me' ? 'opacity-100 scale-x-100' : 'opacity-0 scale-x-0'}`} />
						<svg className={mobileNavIconClasses(activeMobileTab === 'me')} fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" /></svg>
						<span className={mobileNavLabelClasses(activeMobileTab === 'me')}>Me</span>
					</a>
				</div>
			</div>
		</div>
	);
};

export default CustomerProfile;
