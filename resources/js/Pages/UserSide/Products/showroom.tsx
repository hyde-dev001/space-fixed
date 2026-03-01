import React, { useEffect, useMemo, useState } from 'react';
import { Head, usePage } from '@inertiajs/react';
import Navigation from '../Shared/Navigation';
import Virtual3DShowroom from '../../../components/Virtual3DShowroom';

type ShowroomProduct = {
	id: number;
	name: string;
	shop_name?: string | null;
	slug: string;
	price: string | number;
	image: string | null;
};

const getFrameUrl = (index: number) => `/images/360/golf-shoe-360-product-photography-1500w-${String(index).padStart(3, '0')}.jpg`;

const ShowroomPage: React.FC = () => {
	const { products } = usePage().props as { products?: ShowroomProduct[] };
	const shoeProducts = Array.isArray(products) ? products : [];
	const [activeProduct, setActiveProduct] = useState<ShowroomProduct | null>(null);
	const [frameTick, setFrameTick] = useState(0);

	useEffect(() => {
		const preloaders = Array.from({ length: 48 }, (_, index) => {
			const image = new Image();
			image.decoding = 'async';
			image.loading = 'eager';
			image.src = getFrameUrl(index + 1);
			return image;
		});

		let animationFrameId = 0;
		let previousFrame = -1;
		const framesPerSecond = 20;
		const animate = (timestamp: number) => {
			const nextFrame = Math.floor((timestamp / 1000) * framesPerSecond) % 48;
			if (nextFrame !== previousFrame) {
				previousFrame = nextFrame;
				setFrameTick(nextFrame);
			}
			animationFrameId = window.requestAnimationFrame(animate);
		};

		animationFrameId = window.requestAnimationFrame(animate);

		return () => {
			window.cancelAnimationFrame(animationFrameId);
			preloaders.forEach((image) => {
				image.src = '';
			});
		};
	}, []);

	const shelfSlots = useMemo(() => {
		const slots = [...shoeProducts];
		while (slots.length < 12) {
			slots.push({
				id: -slots.length,
				name: '',
								shop_name: '',
				slug: '',
				price: '',
				image: null,
			});
		}
		return slots.slice(0, 12);
	}, [shoeProducts]);

	return (
		<>
			<Head title="Showroom" />
			<div className="min-h-screen bg-white">
				<Navigation />

				<div className="w-full px-2 lg:px-4 py-8 lg:py-10">
					<div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 sm:gap-5">
						{shelfSlots.map((product, index) => {
							const isPlaceholder = product.id < 0;
							const slotFrame = ((frameTick + index * 4) % 48) + 1;
							const slotImage = getFrameUrl(slotFrame);

							return (
								<div
									key={`${product.id}-${product.slug}`}
									className={`group h-55 bg-white border border-black rounded-lg p-3 flex flex-col items-center justify-between relative overflow-hidden transition-all duration-200 ${
										isPlaceholder ? '' : 'cursor-pointer hover:shadow-lg hover:-translate-y-1'
									}`}
									onClick={() => !isPlaceholder && setActiveProduct(product)}
								>
									{!isPlaceholder && (
										<div className="pointer-events-none absolute inset-0 bg-black/0 transition-colors duration-200 group-hover:bg-black/20" />
									)}

									{!isPlaceholder && product.shop_name && (
										<p className="absolute top-2 left-3 z-10 text-xs font-semibold text-black truncate max-w-[75%]">
											{product.shop_name}
										</p>
									)}

									<img
										src={slotImage}
										alt={product.name || `3D shoe ${index + 1}`}
										className="w-full h-40 object-contain pt-3"
									/>

									{!isPlaceholder && product.name && (
										<div className="w-full text-center">
											<p className="text-sm font-semibold text-black truncate">{product.name}</p>
										</div>
									)}
								</div>
							);
						})}
					</div>
				</div>

				{activeProduct && (
					<Virtual3DShowroom
						productName={activeProduct.name}
						onClose={() => setActiveProduct(null)}
					/>
				)}
			</div>
		</>
	);
};

export default ShowroomPage;
