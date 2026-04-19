import React from 'react';
import { Head } from '@inertiajs/react';
import Navigation from '../Shared/Navigation';

const apkDownloadPath = '/apk/download';
const apkScanDownloadPath = '/apk/scan-download';
const phonePreviewImage = '/apk%20image/image.png';
const viteEnv = (import.meta as unknown as { env?: Record<string, string | undefined> }).env;
const configuredApkBaseUrl = String(viteEnv?.VITE_APK_BASE_URL || '').trim();
const configuredApkFileUrl = String(viteEnv?.VITE_APK_URL || '').trim();

const stripTrailingSlash = (value: string) => value.replace(/\/+$/, '');
const isAbsoluteHttpUrl = (value: string) => /^https?:\/\//i.test(value);

const ApkPage: React.FC = () => {
	const runtimeOrigin = typeof window !== 'undefined' ? stripTrailingSlash(window.location.origin) : '';
	const apkBaseUrl = runtimeOrigin || (configuredApkBaseUrl ? stripTrailingSlash(configuredApkBaseUrl) : '');
	const fullApkUrl =
		configuredApkFileUrl && isAbsoluteHttpUrl(configuredApkFileUrl)
			? configuredApkFileUrl
			: `${apkBaseUrl}${apkDownloadPath}`;
	const fullScanDownloadUrl = `${apkBaseUrl}${apkScanDownloadPath}`;
	const qrCodeUrl =
		`https://api.qrserver.com/v1/create-qr-code/?size=220x220&data=${encodeURIComponent(fullScanDownloadUrl)}`;

	return (
		<>
			<Head title="Download App - SoleSpace" />

			<div className="min-h-screen bg-white text-[#171717] font-outfit antialiased">
				<Navigation />

				<main className="grid w-full gap-8 px-6 pb-10 pt-24 md:px-10 lg:grid-cols-[minmax(0,0.9fr)_minmax(0,1.1fr)] lg:items-start lg:gap-5 lg:pt-30 lg:pl-16 lg:pr-0 xl:pl-24">
					<section className="mx-auto w-full max-w-2xl translate-x-4 animate-[fadeUp_700ms_ease-out_120ms_forwards] pt-7 opacity-0 sm:translate-x-8 lg:translate-x-16 lg:justify-self-center lg:pt-14 xl:translate-x-24">
						<h1 className="text-6xl font-black uppercase leading-[0.95] text-[#121212] sm:text-7xl lg:text-7xl">
							Download SoleSpace App
						</h1>
						<p className="mt-4 max-w-xl text-xl leading-8 text-[#555] sm:text-2xl">
							Install SoleSpace on your Android device to browse drops faster, track repairs in real time, and manage orders in one place.
						</p>

						<div className="mt-4 flex items-center gap-5">
							<div className="space-y-2.5">
								<a
									href={fullApkUrl}
									download
									className="group flex w-64 items-center gap-3.5 rounded-xl bg-black px-5 py-3 text-white transition-transform duration-300 hover:-translate-y-0.5"
								>
									<span className="text-2xl">▶</span>
									<span>
										<span className="block text-xs uppercase tracking-[0.2em] text-white/70">Android</span>
										<span className="block text-base font-semibold leading-none">Download APK</span>
									</span>
								</a>

								<a
									href={fullApkUrl}
									target="_blank"
									rel="noreferrer"
									className="group flex w-64 items-center gap-3.5 rounded-xl bg-[#131313] px-5 py-3 text-white transition-transform duration-300 hover:-translate-y-0.5"
								>
									<span className="text-2xl">⬇</span>
									<span>
										<span className="block text-xs uppercase tracking-[0.2em] text-white/70">Direct File</span>
										<span className="block whitespace-nowrap text-base font-semibold leading-none">solespace-release.apk</span>
									</span>
								</a>
							</div>

							<div className="-mt-1 self-center rounded-2xl border border-[#d3d7e2] bg-white p-3 shadow-[0_16px_40px_-20px_rgba(0,0,0,0.35)]">
								<img
									src={qrCodeUrl}
									alt="Scan QR code to download SoleSpace APK"
									className="h-36 w-36 rounded-md"
								/>
							</div>
						</div>
					</section>

					<section className="relative top-6 h-112 w-full animate-[fadeUp_750ms_ease-out_240ms_forwards] justify-self-end overflow-visible opacity-0 sm:h-124 lg:top-8 lg:-mr-8 xl:-mr-14">
						<div className="absolute -right-2 top-8 h-84 w-4xl rounded-l-[220px] bg-[#f2a17d]" />

						<div className="absolute right-58 top-39 h-17 w-8 rounded-l-full bg-[#f2a17d]" />
						<div className="absolute right-54 top-47 h-15 w-8 rounded-l-full bg-[#f2a17d]" />
						<div className="absolute right-50 top-55 h-13 w-8 rounded-l-full bg-[#f2a17d]" />

						<div className="absolute right-96 top-0 z-20 h-140 w-76 animate-[floatPhone_4s_ease-in-out_infinite] rounded-[48px] border-[6px] border-black bg-[#f2f2f2] shadow-[0_24px_50px_-24px_rgba(19,23,66,0.6)] lg:right-112 xl:right-128">
							<div className="mx-auto mt-3 h-3 w-20 rounded-full bg-black" />
							<div className="mx-auto mt-3 h-120 w-60 overflow-hidden rounded-4xl bg-[#f7f7f7]">
								<img
									src={phonePreviewImage}
									alt="SoleSpace app preview"
									className="h-full w-full object-contain"
								/>
							</div>
						</div>

						<div className="absolute -right-2 bottom-4 h-20 w-4xl rounded-l-[44px] bg-[#2e2f78]" />
					</section>
				</main>
			</div>

			<style>{`
				@keyframes fadeUp {
					from {
						opacity: 0;
						transform: translateY(18px);
					}
					to {
						opacity: 1;
						transform: translateY(0);
					}
				}

				@keyframes floatPhone {
					0%,
					100% {
						transform: translateY(0px);
					}
					50% {
						transform: translateY(-6px);
					}
				}
			`}</style>
		</>
	);
};

export default ApkPage;
