import React, { useEffect, useMemo, useRef, useState } from 'react';
import * as THREE from 'three';
import { getShowroomRooms } from './showroomRooms';

interface Product {
	id: number;
	name: string;
	slug?: string;
	brand?: string;
	stock_quantity: number;
	main_image?: string | null;
	hover_image?: string | null;
	gallery_images?: string[];
	showroom_360_frames?: string[];
}

interface VirtualShowroomProps {
	products: Product[];
	isStandalonePage?: boolean;
	onFocusModeChange?: (isFocusMode: boolean) => void;
	showroomSlotLimit?: number | null;
	showroomPlanCode?: string | null;
	showroomPlanName?: string | null;
}

interface ShoeViewSet {
	id: number;
	name: string;
	slug?: string;
	brand?: string;
	stock: number;
	frames: string[];
	previewFrames: string[];
}

interface ShoePickupAnimation {
	shoeIdx: number;
	startTimeMs: number;
	durationMs: number;
}

interface PendingFocusOpen {
	shoeIdx: number;
	frameIdx: number;
	frameSrc: string | null;
	ready: boolean;
}

const getUniqueFrames = (frames: Array<string | null | undefined>): string[] => {
	return Array.from(
		new Set(
			frames.filter((frame): frame is string => Boolean(frame && frame.trim())),
		),
	);
};

const buildProductFrames = (product: Product): string[] => {
	const showroomFrames = getUniqueFrames(product.showroom_360_frames ?? []);
	if (showroomFrames.length > 0) {
		return showroomFrames;
	}

	return getUniqueFrames([
		product.main_image,
		product.hover_image,
		...(product.gallery_images ?? []),
	]);
};

const buildPreviewFrames = (frames: string[], maxPreviewFrames: number): string[] => {
	if (frames.length <= maxPreviewFrames) {
		return frames;
	}

	const sampledFrames: string[] = [];
	const step = frames.length / maxPreviewFrames;
	for (let index = 0; index < maxPreviewFrames; index += 1) {
		const sampledIndex = Math.min(frames.length - 1, Math.floor(index * step));
		const frameSrc = frames[sampledIndex];
		if (frameSrc && sampledFrames[sampledFrames.length - 1] !== frameSrc) {
			sampledFrames.push(frameSrc);
		}
	}

	return getUniqueFrames(sampledFrames);
};

const MAX_SHOWROOM_SLOTS = 150;
const JOYSTICK_RADIUS_PX = 62;
const JOYSTICK_DEADZONE = 0.16;

interface JoystickVector {
	x: number;
	y: number;
	active: boolean;
}

const isLandscapeViewport = () => {
	if (typeof window === 'undefined') return false;
	return window.innerWidth > window.innerHeight;
};

const MOBILE_TABLET_USER_AGENT = /Android|iPhone|iPad|iPod|Mobile|Tablet/i;

const isTouchLikeDevice = () => {
	if (typeof window === 'undefined') return false;

	const hasTouchPoints = typeof navigator !== 'undefined' && navigator.maxTouchPoints > 0;
	const hasTouchEvent = 'ontouchstart' in window;
	const coarsePointerMatches = typeof window.matchMedia === 'function' && window.matchMedia('(pointer: coarse)').matches;
	const mobileTabletUserAgent = typeof navigator !== 'undefined' && MOBILE_TABLET_USER_AGENT.test(navigator.userAgent);

	return hasTouchPoints || hasTouchEvent || coarsePointerMatches || mobileTabletUserAgent || window.innerWidth <= 1024;
};

const VirtualShowroom: React.FC<VirtualShowroomProps> = ({
	products,
	isStandalonePage = false,
	onFocusModeChange,
	showroomSlotLimit,
}) => {
	const mountRef = useRef<HTMLDivElement | null>(null);
	const viewportRef = useRef<HTMLDivElement | null>(null);
	const currentIndexRef = useRef(0);
	const dragStartXRef = useRef(0);
	const dragStartYRef = useRef(0);
	const cameraYawRef = useRef(0);
	const targetCameraYawRef = useRef(0);
	const cameraPitchRef = useRef(0);
	const targetCameraPitchRef = useRef(0);
	const isDraggingRef = useRef(false);
	const activePointerIdRef = useRef<number | null>(null);
	const pointerMoveDistanceRef = useRef(0);
	const swipeHintTimerRef = useRef<number | null>(null);
	const showSwipeHintRef = useRef(false);
	const focusedDragStartXRef = useRef(0);
	const focusedIsDraggingRef = useRef(false);
	const focusedPointerIdRef = useRef<number | null>(null);
	const focusedPointerMoveDistanceRef = useRef(0);
	const focusedAccumulatedDeltaRef = useRef(0);
	const focusedPendingDeltaRef = useRef(0);
	const focusedRafIdRef = useRef<number | null>(null);
	const focusedImageRef = useRef<HTMLImageElement | null>(null);
	const focusedZoomRef = useRef(1);
	const cameraRef = useRef<THREE.PerspectiveCamera | null>(null);
	const shelfCardPickablesRef = useRef<THREE.Mesh[]>([]);
	const raycasterRef = useRef(new THREE.Raycaster());
	const pointerVectorRef = useRef(new THREE.Vector2());
	const focusedShoeIndexRef = useRef<number | null>(null);
	const focusedFrameOffsetRef = useRef(0);
	const focusedFrameIndexRef = useRef(-1);
	const pickupAnimationRef = useRef<ShoePickupAnimation | null>(null);
	const isPickupAnimatingRef = useRef(false);
	const pendingFocusOpenRef = useRef<PendingFocusOpen | null>(null);
	const hiddenShelfShoeIndicesRef = useRef(new Set<number>());
	const loadedFocusedFramesRef = useRef(new Set<string>());
	const focusedFramePromiseCacheRef = useRef(new Map<string, Promise<void>>());
	const joystickPointerIdRef = useRef<number | null>(null);
	const joystickThumbRef = useRef<HTMLDivElement | null>(null);
	const joystickVectorRef = useRef<JoystickVector>({ x: 0, y: 0, active: false });
	const immersiveModeAttemptedRef = useRef(false);
	const appEnteredFullscreenRef = useRef(false);
	const [currentIndex, setCurrentIndex] = useState(0);
	const [isDragging, setIsDragging] = useState(false);
	const [isSceneLoading, setIsSceneLoading] = useState(true);
	const [isNightMode, setIsNightMode] = useState(false);
	const [showSwipeHint, setShowSwipeHint] = useState(false);
	const [focusedShoeIndex, setFocusedShoeIndex] = useState<number | null>(null);
	const [isFocusedDragging, setIsFocusedDragging] = useState(false);
	const [isPickupAnimating, setIsPickupAnimating] = useState(false);
	const [showFocusedHint, setShowFocusedHint] = useState(true);
	const [focusedFrameSrc, setFocusedFrameSrc] = useState<string | null>(null);
	const [isFocusedImageVisible, setIsFocusedImageVisible] = useState(false);
	const [isTouchScreenDevice, setIsTouchScreenDevice] = useState(() => {
		return isTouchLikeDevice();
	});
	const [showLandscapeTip, setShowLandscapeTip] = useState(false);
	const [joystickUiVector, setJoystickUiVector] = useState({ x: 0, y: 0 });
	const [activeRoomIndex, setActiveRoomIndex] = useState(0);
	const [isRoomSwitching, setIsRoomSwitching] = useState(false);
	const lightsOn = isNightMode;
	const parsedSlotLimit = Number(showroomSlotLimit);
	const showroomDisplayCapacity = Number.isFinite(parsedSlotLimit)
		? Math.max(0, Math.min(Math.floor(parsedSlotLimit), MAX_SHOWROOM_SLOTS))
		: 60;

	const allShoes = useMemo<ShoeViewSet[]>(() => {
		const useReducedPreview = isTouchScreenDevice;
		const maxPreviewFrames = 10;
		return products
			.map((product) => {
				const frames = buildProductFrames(product);
				return {
					id: product.id,
					name: product.name,
					slug: product.slug,
					brand: product.brand,
					stock: product.stock_quantity,
					frames,
					previewFrames: useReducedPreview ? buildPreviewFrames(frames, maxPreviewFrames) : frames,
				};
			})
			.filter((shoe) => shoe.frames.length > 0);
	}, [products, isTouchScreenDevice]);
	const rooms = useMemo(() => getShowroomRooms(showroomDisplayCapacity), [showroomDisplayCapacity]);
	const activeRoom = rooms[activeRoomIndex] ?? rooms[0];
	const shoes = useMemo(
		() => allShoes.slice(activeRoom.start, activeRoom.start + activeRoom.count),
		[allShoes, activeRoom.start, activeRoom.count],
	);

	useEffect(() => {
		setActiveRoomIndex((index) => Math.min(index, rooms.length - 1));
	}, [rooms.length]);

	const switchRoom = (index: number) => {
		setIsRoomSwitching(true);
		setFocusedShoeIndex(null);
		setCurrentIndex(0);
		setIsSceneLoading(true);
		setActiveRoomIndex(index);
	};

	useEffect(() => {
		if (!isRoomSwitching || isSceneLoading) return;
		const timer = window.setTimeout(() => setIsRoomSwitching(false), 400);
		return () => window.clearTimeout(timer);
	}, [isRoomSwitching, isSceneLoading]);

	useEffect(() => {
		if (shoes.length === 0) return;
		if (currentIndex > shoes.length - 1) {
			setCurrentIndex(0);
		}
	}, [currentIndex, shoes.length]);

	useEffect(() => {
		currentIndexRef.current = currentIndex;
	}, [currentIndex]);

	useEffect(() => {
		showSwipeHintRef.current = showSwipeHint;
	}, [showSwipeHint]);

	useEffect(() => {
		focusedShoeIndexRef.current = focusedShoeIndex;
	}, [focusedShoeIndex]);

	useEffect(() => {
		onFocusModeChange?.(focusedShoeIndex !== null);
	}, [focusedShoeIndex, onFocusModeChange]);

	useEffect(() => {
		if (typeof window === 'undefined') return;

		const coarsePointerQuery = typeof window.matchMedia === 'function'
			? window.matchMedia('(pointer: coarse)')
			: null;
		const updateTouchDevice = () => {
			setIsTouchScreenDevice(isTouchLikeDevice());
		};

		const hideLandscapeTipInLandscape = () => {
			if (isLandscapeViewport()) {
				setShowLandscapeTip(false);
			}
		};

		updateTouchDevice();
		hideLandscapeTipInLandscape();

		window.addEventListener('resize', updateTouchDevice);
		window.addEventListener('resize', hideLandscapeTipInLandscape);
		window.addEventListener('orientationchange', hideLandscapeTipInLandscape);
		if (coarsePointerQuery && typeof coarsePointerQuery.addEventListener === 'function') {
			coarsePointerQuery.addEventListener('change', updateTouchDevice);
		} else if (coarsePointerQuery) {
			coarsePointerQuery.addListener(updateTouchDevice);
		}

		return () => {
			window.removeEventListener('resize', updateTouchDevice);
			window.removeEventListener('resize', hideLandscapeTipInLandscape);
			window.removeEventListener('orientationchange', hideLandscapeTipInLandscape);
			if (coarsePointerQuery && typeof coarsePointerQuery.removeEventListener === 'function') {
				coarsePointerQuery.removeEventListener('change', updateTouchDevice);
			} else if (coarsePointerQuery) {
				coarsePointerQuery.removeListener(updateTouchDevice);
			}
		};
	}, []);

	useEffect(() => {
		if (focusedShoeIndex === null) {
			focusedIsDraggingRef.current = false;
			focusedPointerIdRef.current = null;
			focusedPointerMoveDistanceRef.current = 0;
			focusedAccumulatedDeltaRef.current = 0;
			focusedPendingDeltaRef.current = 0;
			if (focusedRafIdRef.current !== null) {
				cancelAnimationFrame(focusedRafIdRef.current);
				focusedRafIdRef.current = null;
			}
			focusedZoomRef.current = 1;
			if (focusedImageRef.current) {
				focusedImageRef.current.style.transform = 'scale(1)';
			}
			setIsFocusedDragging(false);
			return;
		}

		if (focusedFrameIndexRef.current < 0) {
			focusedFrameIndexRef.current = 0;
		}
		focusedFrameOffsetRef.current = focusedFrameIndexRef.current;
		focusedPointerMoveDistanceRef.current = 0;
		focusedAccumulatedDeltaRef.current = 0;
		focusedPendingDeltaRef.current = 0;
		if (focusedRafIdRef.current !== null) {
			cancelAnimationFrame(focusedRafIdRef.current);
			focusedRafIdRef.current = null;
		}
		focusedZoomRef.current = 1;
		if (focusedImageRef.current) {
			focusedImageRef.current.style.transform = 'scale(1)';
		}
		setShowFocusedHint(true);
	}, [focusedShoeIndex]);

	const setShelfShoeHidden = (shoeIdx: number, hidden: boolean) => {
		if (hidden) {
			hiddenShelfShoeIndicesRef.current.add(shoeIdx);
		} else {
			hiddenShelfShoeIndicesRef.current.delete(shoeIdx);
		}

		shelfCardPickablesRef.current.forEach((card) => {
			if ((card.userData.shoeIdx as number) === shoeIdx) {
				card.visible = !hidden;
			}
		});
	};

	useEffect(() => {
		if (focusedShoeIndex === null) return;
		const frames = shoes[focusedShoeIndex]?.frames;
		if (!frames || frames.length === 0) return;

		const preloaders = frames.map((src) => {
			const image = new Image();
			image.decoding = 'async';
			image.loading = 'eager';
			image.onload = () => {
				loadedFocusedFramesRef.current.add(src);
			};
			image.onerror = () => {
				loadedFocusedFramesRef.current.add(src);
			};
			image.src = src;
			if (image.complete) {
				loadedFocusedFramesRef.current.add(src);
			}
			return image;
		});

		return () => {
			preloaders.forEach((image) => {
				image.src = '';
			});
		};
	}, [focusedShoeIndex, shoes]);

	const ensureFocusedFrameReady = (url: string | null | undefined) => {
		if (!url) {
			return Promise.resolve();
		}

		if (loadedFocusedFramesRef.current.has(url)) {
			return Promise.resolve();
		}

		const cachedPromise = focusedFramePromiseCacheRef.current.get(url);
		if (cachedPromise) {
			return cachedPromise;
		}

		const promise = new Promise<void>((resolve) => {
			const image = new Image();
			image.decoding = 'async';
			image.loading = 'eager';

			let finalized = false;
			const finalize = () => {
				if (finalized) return;
				finalized = true;
				loadedFocusedFramesRef.current.add(url);
				focusedFramePromiseCacheRef.current.delete(url);
				resolve();
			};

			image.onload = finalize;
			image.onerror = finalize;
			image.src = url;

			if (image.complete) {
				finalize();
			}
		});

		focusedFramePromiseCacheRef.current.set(url, promise);
		return promise;
	};

	useEffect(() => {
		if (focusedShoeIndex === null || !focusedFrameSrc) {
			setIsFocusedImageVisible(false);
			return;
		}

		if (focusedImageRef.current?.complete) {
			setIsFocusedImageVisible(true);
		}
	}, [focusedShoeIndex, focusedFrameSrc]);

	const closeFocusedModal = () => {
		const currentFocusedShoeIdx = focusedShoeIndexRef.current;
		if (currentFocusedShoeIdx !== null) {
			setShelfShoeHidden(currentFocusedShoeIdx, false);
		}
		setFocusedShoeIndex(null);
		setFocusedFrameSrc(null);
		setIsFocusedImageVisible(false);
		pickupAnimationRef.current = null;
		pendingFocusOpenRef.current = null;
		isPickupAnimatingRef.current = false;
		setIsPickupAnimating(false);
		focusedIsDraggingRef.current = false;
		focusedPointerIdRef.current = null;
		setIsFocusedDragging(false);
		focusedFrameOffsetRef.current = 0;
		focusedFrameIndexRef.current = -1;
		focusedAccumulatedDeltaRef.current = 0;
		focusedPendingDeltaRef.current = 0;
		if (focusedRafIdRef.current !== null) {
			cancelAnimationFrame(focusedRafIdRef.current);
			focusedRafIdRef.current = null;
		}
		focusedZoomRef.current = 1;
	};

	const clearJoystickVector = () => {
		joystickVectorRef.current = { x: 0, y: 0, active: false };
		setJoystickUiVector({ x: 0, y: 0 });
	};

	const requestMobileLandscape = async () => {
		if (!isTouchScreenDevice || immersiveModeAttemptedRef.current) {
			return;
		}

		immersiveModeAttemptedRef.current = true;
		const container = viewportRef.current;
		const canUseFullscreen = typeof document !== 'undefined' && typeof container?.requestFullscreen === 'function';

		if (canUseFullscreen && !document.fullscreenElement) {
			try {
				await container.requestFullscreen();
				appEnteredFullscreenRef.current = true;
			} catch {
				appEnteredFullscreenRef.current = false;
			}
		}

		const orientation = (screen as Screen & {
			orientation?: {
				lock?: (lockType: string) => Promise<void>;
				unlock?: () => void;
			};
		}).orientation;

		if (orientation?.lock) {
			try {
				await orientation.lock('landscape');
				setShowLandscapeTip(false);
				return;
			} catch {
				setShowLandscapeTip(!isLandscapeViewport());
				return;
			}
		}

		setShowLandscapeTip(!isLandscapeViewport());
	};

	const exitMobileImmersiveMode = async () => {
		const orientation = (screen as Screen & {
			orientation?: {
				unlock?: () => void;
			};
		}).orientation;

		try {
			orientation?.unlock?.();
		} catch {
			// Ignore unlock errors because this API can be restricted by browser policies.
		}

		if (appEnteredFullscreenRef.current && document.fullscreenElement && document.exitFullscreen) {
			try {
				await document.exitFullscreen();
			} catch {
				// Ignore exit errors and allow natural browser fullscreen state.
			}
		}

		appEnteredFullscreenRef.current = false;
	};

	const setJoystickByClientPosition = (clientX: number, clientY: number, container: HTMLDivElement) => {
		const rect = container.getBoundingClientRect();
		const centerX = rect.left + rect.width / 2;
		const centerY = rect.top + rect.height / 2;
		const deltaX = clientX - centerX;
		const deltaY = clientY - centerY;
		const distance = Math.hypot(deltaX, deltaY);
		const clampedDistance = Math.min(distance, JOYSTICK_RADIUS_PX);
		const angle = Math.atan2(deltaY, deltaX);
		const clampedX = Math.cos(angle) * clampedDistance;
		const clampedY = Math.sin(angle) * clampedDistance;

		const normalizedX = clampedX / JOYSTICK_RADIUS_PX;
		const normalizedY = clampedY / JOYSTICK_RADIUS_PX;
		const magnitude = Math.hypot(normalizedX, normalizedY);

		if (magnitude < JOYSTICK_DEADZONE) {
			clearJoystickVector();
			joystickVectorRef.current.active = true;
			return;
		}

		joystickVectorRef.current = {
			x: normalizedX,
			y: normalizedY,
			active: true,
		};
		setJoystickUiVector({ x: clampedX, y: clampedY });
	};

	useEffect(() => {
		if (!joystickThumbRef.current) return;
		joystickThumbRef.current.style.transform = `translate(calc(-50% + ${joystickUiVector.x}px), calc(-50% + ${joystickUiVector.y}px))`;
	}, [joystickUiVector]);

	useEffect(() => {
		return () => {
			void exitMobileImmersiveMode();
		};
	}, []);

	const resetFocusedView = () => {
		focusedFrameOffsetRef.current = 0;
		focusedFrameIndexRef.current = 0;
		focusedAccumulatedDeltaRef.current = 0;
		focusedPendingDeltaRef.current = 0;
		if (focusedRafIdRef.current !== null) {
			cancelAnimationFrame(focusedRafIdRef.current);
			focusedRafIdRef.current = null;
		}
		focusedZoomRef.current = 1;
		if (focusedShoeIndexRef.current !== null) {
			const frames = shoes[focusedShoeIndexRef.current]?.frames;
			const firstFrame = frames?.[0];
			if (focusedImageRef.current && firstFrame) {
				setFocusedFrameSrc(firstFrame);
				setIsFocusedImageVisible(false);
				focusedImageRef.current.src = firstFrame;
				focusedImageRef.current.style.transform = 'scale(1)';
			}
		}
	};

	const handleFocusedWheelZoom = (deltaY: number) => {
		if (!focusedImageRef.current) return;
		const zoomSpeed = 0.0015;
		const nextZoom = Math.max(1, Math.min(4, focusedZoomRef.current - deltaY * zoomSpeed));
		if (Math.abs(nextZoom - focusedZoomRef.current) < 0.001) return;
		focusedZoomRef.current = nextZoom;
		focusedImageRef.current.style.transform = `scale(${nextZoom})`;
	};

	const processFocusedPendingDelta = () => {
		focusedRafIdRef.current = null;
		if (focusedShoeIndexRef.current === null) return;
		const frames = shoes[focusedShoeIndexRef.current]?.frames;
		if (!frames || frames.length === 0) return;

		focusedAccumulatedDeltaRef.current += focusedPendingDeltaRef.current;
		focusedPendingDeltaRef.current = 0;

		const stepPx = 7;
		let nextFrame = focusedFrameIndexRef.current >= 0 ? focusedFrameIndexRef.current : 0;

		while (Math.abs(focusedAccumulatedDeltaRef.current) >= stepPx) {
			const direction = focusedAccumulatedDeltaRef.current > 0 ? 1 : -1;
			nextFrame = ((nextFrame + direction) % frames.length + frames.length) % frames.length;
			focusedAccumulatedDeltaRef.current -= direction * stepPx;
		}

		if (nextFrame !== focusedFrameIndexRef.current) {
			focusedFrameIndexRef.current = nextFrame;
			focusedFrameOffsetRef.current = nextFrame;
			if (focusedImageRef.current) {
				focusedImageRef.current.src = frames[nextFrame];
			}
		}
	};

	const rotateFocusedFrameByDelta = (deltaX: number) => {
		focusedPendingDeltaRef.current += deltaX;
		if (focusedRafIdRef.current === null) {
			focusedRafIdRef.current = requestAnimationFrame(processFocusedPendingDelta);
		}
	};

	const startFocusedDrag = (pointerId: number, clientX: number, target: HTMLDivElement) => {
		focusedPointerIdRef.current = pointerId;
		focusedDragStartXRef.current = clientX;
		focusedPointerMoveDistanceRef.current = 0;
		focusedIsDraggingRef.current = true;
		setIsFocusedDragging(true);
		target.setPointerCapture(pointerId);
	};

	const moveFocusedDrag = (pointerId: number, clientX: number) => {
		if (focusedPointerIdRef.current !== pointerId || !focusedIsDraggingRef.current) return;
		const deltaX = clientX - focusedDragStartXRef.current;
		focusedDragStartXRef.current = clientX;
		focusedPointerMoveDistanceRef.current += Math.abs(deltaX);
		if (focusedPointerMoveDistanceRef.current > 12) {
			setShowFocusedHint(false);
		}
		rotateFocusedFrameByDelta(deltaX);
	};

	const endFocusedDrag = (pointerId: number, target: HTMLDivElement) => {
		if (focusedPointerIdRef.current !== pointerId) return;
		if (target.hasPointerCapture(pointerId)) {
			target.releasePointerCapture(pointerId);
		}
		focusedPointerIdRef.current = null;
		focusedIsDraggingRef.current = false;
		setIsFocusedDragging(false);
	};

	const pickShoeAtPointer = (clientX: number, clientY: number) => {
		const container = mountRef.current;
		const camera = cameraRef.current;
		if (!container || !camera || shelfCardPickablesRef.current.length === 0) return null;

		const rect = container.getBoundingClientRect();
		pointerVectorRef.current.x = ((clientX - rect.left) / rect.width) * 2 - 1;
		pointerVectorRef.current.y = -((clientY - rect.top) / rect.height) * 2 + 1;

		raycasterRef.current.setFromCamera(pointerVectorRef.current, camera);
		const intersects = raycasterRef.current.intersectObjects(shelfCardPickablesRef.current, false);
		if (intersects.length === 0) return null;

		const shoeIdx = intersects[0].object.userData.shoeIdx as number | undefined;
		if (typeof shoeIdx !== 'number') return null;
		return shoeIdx;
	};

	useEffect(() => {
		if (swipeHintTimerRef.current !== null) {
			window.clearTimeout(swipeHintTimerRef.current);
			swipeHintTimerRef.current = null;
		}

		if (isSceneLoading) {
			setShowSwipeHint(false);
			return;
		}

		setShowSwipeHint(true);
		swipeHintTimerRef.current = window.setTimeout(() => {
			setShowSwipeHint(false);
			swipeHintTimerRef.current = null;
		}, 7000);

		return () => {
			if (swipeHintTimerRef.current !== null) {
				window.clearTimeout(swipeHintTimerRef.current);
				swipeHintTimerRef.current = null;
			}
		};
	}, [isSceneLoading]);

	useEffect(() => {
		const container = mountRef.current;
		if (!container) return;
		setIsSceneLoading(true);
		let isDisposed = false;
		const lowPowerMode = isTouchScreenDevice;
		const sceneColor = isNightMode ? '#0b1020' : '#e2e8f0';
		const fogFar = isNightMode ? 45 : 70;
		const wallMainColor = '#e7e3da';
		const wallSideColor = '#ece8df';
		const floorColor = '#d6d1c8';
		const ceilingColor = '#efebe2';
		const shelfColor = '#8b6a4a';
		const ambientIntensity = isNightMode ? 0.14 : 1.45;
		const keyLightIntensity = isNightMode ? 0.34 : 1.6;
		const rimLightIntensity = isNightMode ? 0.22 : 1.05;
		const fixtureEmissiveIntensity = lightsOn ? 1.9 : 0;
		const fixturePointIntensity = lightsOn ? 1.15 : 0;
		const fixtureSpotIntensity = lightsOn ? 3.1 : 0;

		const scene = new THREE.Scene();
		scene.background = new THREE.Color(sceneColor);
		scene.fog = new THREE.Fog(sceneColor, 25, fogFar);
		const compactRoom = showroomDisplayCapacity <= 48;
		const premiumRoom = showroomDisplayCapacity >= 84;
		const roomHalfWidth = compactRoom ? 9.8 : (premiumRoom ? 15.5 : 12);
		const roomBackZ = compactRoom ? -13.8 : (premiumRoom ? -23.5 : -18);
		const roomFrontZ = compactRoom ? 7.8 : (premiumRoom ? 17.5 : 12);
		const roomCenterZ = (roomBackZ + roomFrontZ) / 2;
		const wallWidth = compactRoom ? 34 : (premiumRoom ? 54 : 42);
		const sideWallWidth = compactRoom ? 30 : (premiumRoom ? 48 : 38);
		const wallHeight = compactRoom ? 13.2 : (premiumRoom ? 16.5 : 15);
		const wallCenterY = compactRoom ? 6.4 : (premiumRoom ? 7.8 : 7.2);
		const ceilingY = compactRoom ? 12.8 : (premiumRoom ? 15.8 : 14.6);
		const ceilingDepth = compactRoom ? 22 : (premiumRoom ? 42 : 30);

		const camera = new THREE.PerspectiveCamera(66, container.clientWidth / container.clientHeight, 0.1, 200);
		camera.position.set(0, compactRoom ? 2.9 : (premiumRoom ? 3.45 : 3.2), compactRoom ? -2.3 : (premiumRoom ? -5.2 : -3));
		cameraRef.current = camera;
		const cameraBaseY = camera.position.y;
		const cameraPosition = new THREE.Vector3(camera.position.x, camera.position.y, camera.position.z);
		const keyState = {
			forward: false,
			backward: false,
			left: false,
			right: false,
		};
		const movementDirection = new THREE.Vector3();
		const movementForward = new THREE.Vector3();
		const movementRight = new THREE.Vector3();
		const walkSpeed = compactRoom ? 6.0 : (premiumRoom ? 7.2 : 6.6);
		const movementPadding = compactRoom ? 2.1 : (premiumRoom ? 2.7 : 2.35);
		const minWalkX = -roomHalfWidth + movementPadding;
		const maxWalkX = roomHalfWidth - movementPadding;
		const minWalkZ = roomBackZ + movementPadding;
		const maxWalkZ = roomFrontZ - movementPadding;

		const renderer = new THREE.WebGLRenderer({ antialias: true, alpha: false, powerPreference: 'high-performance' });
		renderer.setPixelRatio(Math.min(window.devicePixelRatio, lowPowerMode ? 1.35 : 3));
		renderer.setSize(container.clientWidth, container.clientHeight);
		renderer.outputColorSpace = THREE.SRGBColorSpace;
		renderer.toneMapping = THREE.ACESFilmicToneMapping;
		renderer.toneMappingExposure = 1.22;
		renderer.shadowMap.enabled = false;
		renderer.shadowMap.type = THREE.PCFSoftShadowMap;
		container.innerHTML = '';
		container.appendChild(renderer.domElement);

		const ambientLight = new THREE.AmbientLight('#ffffff', ambientIntensity);
		scene.add(ambientLight);

		const fillLight = new THREE.HemisphereLight(
			isNightMode ? '#9fb8ff' : '#fff8e6',
			isNightMode ? '#2b3038' : '#d7dce4',
			isNightMode ? 0.42 : 0.58,
		);
		scene.add(fillLight);

		const keyLight = new THREE.DirectionalLight('#ffffff', keyLightIntensity);
		keyLight.position.set(5, 9, 10);
		keyLight.castShadow = false;
		keyLight.shadow.mapSize.width = 2048;
		keyLight.shadow.mapSize.height = 2048;
		keyLight.shadow.camera.near = 0.5;
		keyLight.shadow.camera.far = 48;
		keyLight.shadow.camera.left = -22;
		keyLight.shadow.camera.right = 22;
		keyLight.shadow.camera.top = 22;
		keyLight.shadow.camera.bottom = -22;
		keyLight.shadow.bias = -0.0002;
		keyLight.shadow.normalBias = 0.02;
		scene.add(keyLight);

		const rimLight = new THREE.DirectionalLight('#cbd5e1', rimLightIntensity);
		rimLight.position.set(-10, 7, -6);
		scene.add(rimLight);

		const floor = new THREE.Mesh(
			new THREE.PlaneGeometry(120, 120),
			new THREE.MeshStandardMaterial({ color: floorColor, roughness: 0.95, metalness: 0.04 }),
		);
		floor.rotation.x = -Math.PI / 2;
		floor.position.y = 0;
		floor.receiveShadow = false;
		scene.add(floor);

		const backWall = new THREE.Mesh(
			new THREE.PlaneGeometry(wallWidth, wallHeight),
			new THREE.MeshStandardMaterial({ color: wallMainColor, roughness: 0.95, metalness: 0.05 }),
		);
		backWall.position.set(0, wallCenterY, roomBackZ);
		backWall.receiveShadow = false;
		scene.add(backWall);

		const frontWall = new THREE.Mesh(
			new THREE.PlaneGeometry(wallWidth, wallHeight),
			new THREE.MeshStandardMaterial({ color: wallMainColor, roughness: 0.95, metalness: 0.05 }),
		);
		frontWall.position.set(0, wallCenterY, roomFrontZ);
		frontWall.rotation.y = Math.PI;
		frontWall.receiveShadow = false;
		scene.add(frontWall);

		const leftWall = new THREE.Mesh(
			new THREE.PlaneGeometry(sideWallWidth, wallHeight),
			new THREE.MeshStandardMaterial({ color: wallSideColor, roughness: 0.95, metalness: 0.05 }),
		);
		leftWall.position.set(-roomHalfWidth, wallCenterY, roomCenterZ);
		leftWall.rotation.y = Math.PI / 2;
		leftWall.receiveShadow = false;
		scene.add(leftWall);

		const rightWall = leftWall.clone();
		rightWall.position.x = roomHalfWidth;
		rightWall.rotation.y = -Math.PI / 2;
		rightWall.receiveShadow = false;
		scene.add(rightWall);

		const ceiling = new THREE.Mesh(
			new THREE.PlaneGeometry(wallWidth, ceilingDepth),
			new THREE.MeshStandardMaterial({ color: ceilingColor, roughness: 0.9, metalness: 0.03, side: THREE.DoubleSide }),
		);
		ceiling.rotation.x = Math.PI / 2;
		ceiling.position.set(0, ceilingY, roomCenterZ);
		ceiling.receiveShadow = false;
		scene.add(ceiling);

		const shelfMaterial = new THREE.MeshPhysicalMaterial({
			color: '#8f6745',
			roughness: 0.58,
			metalness: 0.06,
			clearcoat: 0.3,
			clearcoatRoughness: 0.42,
		});
		const shelfEdgeMaterial = new THREE.MeshStandardMaterial({ color: '#b88758', roughness: 0.35, metalness: 0.16 });
		const shelfMeshes: THREE.Mesh[] = [];
		const isBasicLayout = compactRoom;
		const isPremiumLayout = showroomDisplayCapacity >= 84;
		const sideSlotsPerWall = isBasicLayout ? 4 : (isPremiumLayout ? 10 : 6);
		const depthSlotsPerWall = 4;
		const includeCenterSlots = false;
		const spacingOnWall = (index: number, total: number, min: number, max: number) => {
			if (total <= 1) return (min + max) / 2;
			return min + (index / (total - 1)) * (max - min);
		};
		const wallSlotGap = compactRoom ? 3.95 : (premiumRoom ? 4.15 : 4.05);
		const centeredWallOffset = (index: number, total: number, gap: number) => {
			if (total <= 1) return 0;
			const start = -((total - 1) * gap) / 2;
			return start + index * gap;
		};
		const sideInset = compactRoom ? 2.2 : (premiumRoom ? 3.2 : 2.6);
		const depthInset = compactRoom ? 2.35 : (premiumRoom ? 3.4 : 2.6);
		const depthWallInsetZ = compactRoom ? 1.35 : (premiumRoom ? 2.1 : 1.6);
		const sideShelfX = roomHalfWidth - sideInset;
		const sideCardX = sideShelfX - (premiumRoom ? 0.28 : 0.35);
		const sideWallCenterZ = roomCenterZ;
		const sideWallZMin = sideWallCenterZ + centeredWallOffset(0, sideSlotsPerWall, wallSlotGap);
		const sideWallZMax = sideWallCenterZ + centeredWallOffset(sideSlotsPerWall - 1, sideSlotsPerWall, wallSlotGap);
		const depthWallCenterX = 0;
		const depthShelfBackZ = roomBackZ + depthWallInsetZ;
		const depthShelfFrontZ = roomFrontZ - depthWallInsetZ;
		const depthCardBackZ = depthShelfBackZ + 0.55;
		const depthCardFrontZ = depthShelfFrontZ - 0.55;
		const shelfDefinitions: Array<{
			position: THREE.Vector3;
			width: number;
		}> = [];

		for (const side of [-1, 1]) {
			for (let level = 0; level < 3; level += 1) {
				for (let i = 0; i < sideSlotsPerWall; i += 1) {
					shelfDefinitions.push({
						position: new THREE.Vector3(
							side * sideShelfX,
							1.2 + level * 1.85,
							sideWallCenterZ + centeredWallOffset(i, sideSlotsPerWall, wallSlotGap),
						),
						width: 3.2,
					});
				}
			}
		}

		for (const depthSide of [-1, 1]) {
			for (let level = 0; level < 3; level += 1) {
				for (let i = 0; i < depthSlotsPerWall; i += 1) {
					shelfDefinitions.push({
						position: new THREE.Vector3(
							depthWallCenterX + centeredWallOffset(i, depthSlotsPerWall, wallSlotGap),
							1.2 + level * 1.85,
							depthSide === -1 ? depthShelfBackZ : depthShelfFrontZ,
						),
						width: 2.8,
					});
				}
			}
		}

		if (includeCenterSlots) {
			const centerZMin = sideWallZMin + 0.35;
			const centerZMax = sideWallZMax - 0.35;
			for (const centerSide of [-1, 1]) {
				for (let level = 0; level < 3; level += 1) {
					for (let i = 0; i < 4; i += 1) {
						shelfDefinitions.push({
							position: new THREE.Vector3(
								centerSide * 1.85,
								1.2 + level * 1.85,
								spacingOnWall(i, 4, centerZMin, centerZMax),
							),
							width: 2.45,
						});
					}
				}
			}
		}

		shelfDefinitions.slice(0, showroomDisplayCapacity).forEach((definition) => {
			const shelf = new THREE.Mesh(new THREE.BoxGeometry(definition.width, 0.16, 1.25), shelfMaterial);
			shelf.position.copy(definition.position);
			shelf.castShadow = false;
			shelf.receiveShadow = false;
			scene.add(shelf);
			shelfMeshes.push(shelf);

			const frontTrim = new THREE.Mesh(new THREE.BoxGeometry(definition.width, 0.07, 0.08), shelfEdgeMaterial);
			frontTrim.position.copy(definition.position);
			frontTrim.position.y += 0.03;
			frontTrim.position.z += 0.58;
			scene.add(frontTrim);
			shelfMeshes.push(frontTrim);

			const backTrim = frontTrim.clone();
			backTrim.position.z = definition.position.z - 0.58;
			scene.add(backTrim);
			shelfMeshes.push(backTrim);
		});

		const swipeGuideGroup = new THREE.Group();
		swipeGuideGroup.visible = false;
		scene.add(swipeGuideGroup);

		const arrowShape = new THREE.Shape();
		arrowShape.moveTo(-0.62, 0);
		arrowShape.lineTo(-0.36, 0.24);
		arrowShape.lineTo(-0.22, 0.12);
		arrowShape.lineTo(0.58, 0.12);
		arrowShape.lineTo(0.58, -0.12);
		arrowShape.lineTo(-0.22, -0.12);
		arrowShape.lineTo(-0.36, -0.24);
		arrowShape.lineTo(-0.62, 0);

		const guideArrowGeometry = new THREE.ShapeGeometry(arrowShape);
		const guideLabelGeometry = new THREE.PlaneGeometry(1.2, 0.22);

		const guideMaterial = new THREE.MeshBasicMaterial({
			color: isNightMode ? '#e5e7eb' : '#6b7280',
			transparent: true,
			opacity: 0.92,
			depthTest: false,
			side: THREE.DoubleSide,
		});

		const guideLabelTextures: THREE.Texture[] = [];
		const createGuideLabelTexture = (text: string) => {
			const canvas = document.createElement('canvas');
			canvas.width = 512;
			canvas.height = 128;
			const ctx = canvas.getContext('2d');
			if (!ctx) return null;

			ctx.clearRect(0, 0, canvas.width, canvas.height);
			ctx.fillStyle = isNightMode ? '#e5e7eb' : '#4b5563';
			ctx.font = 'bold 56px Arial';
			ctx.textAlign = 'center';
			ctx.textBaseline = 'middle';
			ctx.fillText(text, canvas.width / 2, canvas.height / 2);

			const texture = new THREE.CanvasTexture(canvas);
			texture.colorSpace = THREE.SRGBColorSpace;
			texture.needsUpdate = true;
			guideLabelTextures.push(texture);
			return texture;
		};

		const leftLabelMaterial = new THREE.MeshBasicMaterial({
			map: createGuideLabelTexture('swipe left') || undefined,
			transparent: true,
			opacity: 0.9,
			depthTest: false,
			side: THREE.DoubleSide,
		});
		const rightLabelMaterial = new THREE.MeshBasicMaterial({
			map: createGuideLabelTexture('swipe right') || undefined,
			transparent: true,
			opacity: 0.9,
			depthTest: false,
			side: THREE.DoubleSide,
		});

		const leftArrowMesh = new THREE.Mesh(guideArrowGeometry, guideMaterial);
		leftArrowMesh.position.set(-1.08, 0, 0);
		swipeGuideGroup.add(leftArrowMesh);

		const rightArrowMesh = new THREE.Mesh(guideArrowGeometry, guideMaterial);
		rightArrowMesh.scale.x = -1;
		rightArrowMesh.position.set(1.08, 0, 0);
		swipeGuideGroup.add(rightArrowMesh);

		const leftLabelMesh = new THREE.Mesh(guideLabelGeometry, leftLabelMaterial);
		leftLabelMesh.position.set(-1.08, -0.43, 0);
		swipeGuideGroup.add(leftLabelMesh);

		const rightLabelMesh = new THREE.Mesh(guideLabelGeometry, rightLabelMaterial);
		rightLabelMesh.position.set(1.08, -0.43, 0);
		swipeGuideGroup.add(rightLabelMesh);

		const cameraForward = new THREE.Vector3();

		const runwayWidth = compactRoom ? 7.8 : (premiumRoom ? 14.2 : 10);
		const runwayDepth = compactRoom ? 5.8 : (premiumRoom ? 11.2 : 8);
		const runway = new THREE.Mesh(
			new THREE.BoxGeometry(runwayWidth, 0.25, runwayDepth),
			new THREE.MeshStandardMaterial({ color: isNightMode ? '#8a939f' : '#d9dee4', roughness: 0.4, metalness: 0.2 }),
		);
		runway.position.set(0, 0.13, roomCenterZ);
		runway.receiveShadow = true;
		runway.castShadow = true;
		scene.add(runway);

		const decorMeshes: THREE.Mesh[] = [];
		const decorMaterials: THREE.Material[] = [];
		const decorTextures: THREE.Texture[] = [];
		const loungeAccentLights: THREE.PointLight[] = [];

		const createPosterTexture = (title: string, subtitle: string, accentColor: string) => {
			const canvas = document.createElement('canvas');
			canvas.width = 1024;
			canvas.height = 768;
			const ctx = canvas.getContext('2d');
			if (!ctx) return null;

			ctx.fillStyle = '#111827';
			ctx.fillRect(0, 0, canvas.width, canvas.height);

			ctx.fillStyle = accentColor;
			ctx.fillRect(0, 0, canvas.width, 18);
			ctx.fillRect(0, canvas.height - 18, canvas.width, 18);

			ctx.fillStyle = '#f8fafc';
			ctx.font = 'bold 82px Arial';
			ctx.textAlign = 'center';
			ctx.textBaseline = 'middle';
			ctx.fillText(title, canvas.width / 2, canvas.height * 0.43);

			ctx.fillStyle = '#cbd5e1';
			ctx.font = '42px Arial';
			ctx.fillText(subtitle, canvas.width / 2, canvas.height * 0.62);

			const texture = new THREE.CanvasTexture(canvas);
			texture.colorSpace = THREE.SRGBColorSpace;
			texture.needsUpdate = true;
			decorTextures.push(texture);
			return texture;
		};

		const posterFrameMaterial = new THREE.MeshStandardMaterial({ color: '#111827', roughness: 0.6, metalness: 0.15 });
		const posterPanelMaterialLeft = new THREE.MeshBasicMaterial({
			map: createPosterTexture('NEW ARRIVALS', 'RUN FASTER • TRAIN SMARTER', '#38bdf8') || undefined,
			color: '#ffffff',
			side: THREE.DoubleSide,
		});
		const posterPanelMaterialBack = new THREE.MeshBasicMaterial({
			map: createPosterTexture('SNEAKER STORAGE', 'STEP INTO EXCELLENCE', '#f59e0b') || undefined,
			color: '#ffffff',
			side: THREE.DoubleSide,
		});
		const posterPanelMaterialRight = new THREE.MeshBasicMaterial({
			map: createPosterTexture('LIMITED DROP', 'EXCLUSIVE COLORS IN-STORE', '#22c55e') || undefined,
			color: '#ffffff',
			side: THREE.DoubleSide,
		});
		decorMaterials.push(posterFrameMaterial, posterPanelMaterialLeft, posterPanelMaterialBack, posterPanelMaterialRight);

		const createPoster = (position: THREE.Vector3, rotationY: number, panelMaterial: THREE.Material) => {
			const frame = new THREE.Mesh(new THREE.BoxGeometry(4.15, 2.7, 0.12), posterFrameMaterial);
			frame.position.copy(position);
			frame.rotation.y = rotationY;
			scene.add(frame);
			decorMeshes.push(frame);

			const panel = new THREE.Mesh(new THREE.PlaneGeometry(3.85, 2.35), panelMaterial);
			panel.position.copy(position);
			if (rotationY === 0) panel.position.z += 0.07;
			if (Math.abs(rotationY - Math.PI) < 0.0001) panel.position.z -= 0.07;
			if (Math.abs(rotationY - Math.PI / 2) < 0.0001) panel.position.x += 0.07;
			if (Math.abs(rotationY + Math.PI / 2) < 0.0001) panel.position.x -= 0.07;
			panel.rotation.y = rotationY;
			scene.add(panel);
			decorMeshes.push(panel);
		};

		createPoster(new THREE.Vector3(0, compactRoom ? 7.6 : (premiumRoom ? 9.2 : 8.9), roomBackZ + 0.3), 0, posterPanelMaterialBack);

		const decorScale = compactRoom ? 0.86 : (premiumRoom ? 1.2 : 1);
		const decorDensity = lowPowerMode ? 1 : (compactRoom ? 1 : (premiumRoom ? 3 : 2));

		const sofaFabricMaterial = new THREE.MeshStandardMaterial({
			color: isNightMode ? '#d6d2c8' : '#e7e1d6',
			roughness: 0.9,
			metalness: 0.03,
		});
		const sofaBaseMaterial = new THREE.MeshStandardMaterial({
			color: isNightMode ? '#6b4f35' : '#8f6745',
			roughness: 0.64,
			metalness: 0.04,
		});
		const sofaLegMaterial = new THREE.MeshStandardMaterial({
			color: isNightMode ? '#5d432d' : '#7a5637',
			roughness: 0.34,
			metalness: 0.34,
		});
		const coffeeTableTopMaterial = new THREE.MeshStandardMaterial({
			color: isNightMode ? '#b8a78f' : '#c79b66',
			roughness: 0.52,
			metalness: 0.08,
		});
		const coffeeTableLegMaterial = new THREE.MeshStandardMaterial({
			color: isNightMode ? '#6b4f35' : '#8f6745',
			roughness: 0.42,
			metalness: 0.26,
		});
		const centerPotMaterial = new THREE.MeshStandardMaterial({
			color: isNightMode ? '#7b5a40' : '#a88462',
			roughness: 0.58,
			metalness: 0.2,
		});
		const centerStemMaterial = new THREE.MeshStandardMaterial({
			color: isNightMode ? '#6b4b32' : '#886143',
			roughness: 0.78,
			metalness: 0.02,
		});
		const centerLeafMaterial = new THREE.MeshStandardMaterial({
			color: isNightMode ? '#5fcf96' : '#3ca26f',
			roughness: 0.86,
			metalness: 0.02,
		});
		const pillowMaterial = new THREE.MeshStandardMaterial({
			color: isNightMode ? '#a9adb5' : '#c9ced6',
			roughness: 0.78,
			metalness: 0.02,
		});
		const lampPoleMaterial = new THREE.MeshStandardMaterial({
			color: isNightMode ? '#6b4f35' : '#8f6745',
			roughness: 0.36,
			metalness: 0.34,
		});
		const lampShadeMaterial = new THREE.MeshStandardMaterial({
			color: isNightMode ? '#f5ebd8' : '#fff4df',
			roughness: 0.62,
			metalness: 0.03,
			emissive: '#ffe6b3',
			emissiveIntensity: lightsOn ? 0.38 : 0.05,
		});
		const lampDiffuserMaterial = new THREE.MeshStandardMaterial({
			color: isNightMode ? '#fff2d1' : '#fff8e7',
			roughness: 0.3,
			metalness: 0.02,
			emissive: '#ffe8b3',
			emissiveIntensity: lightsOn ? 0.75 : 0.08,
		});
		decorMaterials.push(
			sofaFabricMaterial,
			sofaBaseMaterial,
			sofaLegMaterial,
			coffeeTableTopMaterial,
			coffeeTableLegMaterial,
			centerPotMaterial,
			centerStemMaterial,
			centerLeafMaterial,
			pillowMaterial,
			lampPoleMaterial,
			lampShadeMaterial,
			lampDiffuserMaterial,
		);

		const loungeCenterZ = roomCenterZ + (compactRoom ? 0.15 : 0.35);
		const loungeScale = compactRoom ? 1.08 : (premiumRoom ? 1.44 : 1.26);
		const localToWorld = (origin: THREE.Vector3, local: THREE.Vector3, rotationY: number) => {
			return local.clone().applyAxisAngle(new THREE.Vector3(0, 1, 0), rotationY).add(origin);
		};

		const createSofa = (x: number, z: number, rotationY: number, scaleMultiplier = 1) => {
			const sofaScale = decorScale * scaleMultiplier;
			const origin = new THREE.Vector3(x, 0.45 * sofaScale, z);

			const base = new THREE.Mesh(
				new THREE.BoxGeometry(2.55 * sofaScale, 0.44 * sofaScale, 1.08 * sofaScale),
				sofaBaseMaterial,
			);
			base.position.copy(origin);
			base.rotation.y = rotationY;
			scene.add(base);
			decorMeshes.push(base);

			const seat = new THREE.Mesh(
				new THREE.BoxGeometry(2.35 * sofaScale, 0.22 * sofaScale, 0.94 * sofaScale),
				sofaFabricMaterial,
			);
			seat.position.copy(origin);
			seat.position.y += 0.21 * sofaScale;
			seat.rotation.y = rotationY;
			scene.add(seat);
			decorMeshes.push(seat);

			const back = new THREE.Mesh(
				new THREE.BoxGeometry(2.35 * sofaScale, 0.7 * sofaScale, 0.18 * sofaScale),
				sofaFabricMaterial,
			);
			back.position.copy(localToWorld(origin, new THREE.Vector3(0, 0.5 * sofaScale, -0.39 * sofaScale), rotationY));
			back.rotation.y = rotationY;
			scene.add(back);
			decorMeshes.push(back);

			const leftArm = new THREE.Mesh(
				new THREE.BoxGeometry(0.18 * sofaScale, 0.52 * sofaScale, 0.94 * sofaScale),
				sofaFabricMaterial,
			);
			leftArm.position.copy(localToWorld(origin, new THREE.Vector3(-1.08 * sofaScale, 0.28 * sofaScale, 0), rotationY));
			leftArm.rotation.y = rotationY;
			scene.add(leftArm);
			decorMeshes.push(leftArm);

			const rightArm = leftArm.clone();
			rightArm.position.copy(localToWorld(origin, new THREE.Vector3(1.08 * sofaScale, 0.28 * sofaScale, 0), rotationY));
			scene.add(rightArm);
			decorMeshes.push(rightArm);

			[
				new THREE.Vector3(-0.95 * sofaScale, -0.24 * sofaScale, -0.32 * sofaScale),
				new THREE.Vector3(0.95 * sofaScale, -0.24 * sofaScale, -0.32 * sofaScale),
				new THREE.Vector3(-0.95 * sofaScale, -0.24 * sofaScale, 0.32 * sofaScale),
				new THREE.Vector3(0.95 * sofaScale, -0.24 * sofaScale, 0.32 * sofaScale),
			].forEach((offset) => {
				const leg = new THREE.Mesh(
					new THREE.BoxGeometry(0.08 * sofaScale, 0.26 * sofaScale, 0.08 * sofaScale),
					sofaLegMaterial,
				);
				leg.position.copy(localToWorld(origin, offset, rotationY));
				scene.add(leg);
				decorMeshes.push(leg);
			});

			[
				new THREE.Vector3(-0.52 * sofaScale, 0.33 * sofaScale, 0),
				new THREE.Vector3(0.52 * sofaScale, 0.33 * sofaScale, 0),
			].forEach((offset) => {
				const pillow = new THREE.Mesh(
					new THREE.BoxGeometry(0.44 * sofaScale, 0.2 * sofaScale, 0.34 * sofaScale),
					pillowMaterial,
				);
				pillow.position.copy(localToWorld(origin, offset, rotationY));
				pillow.rotation.y = rotationY;
				scene.add(pillow);
				decorMeshes.push(pillow);
			});
		};

		const coffeeTableTop = new THREE.Mesh(
			new THREE.CylinderGeometry(1.34 * decorScale, 1.45 * decorScale, 0.1 * decorScale, 40),
			coffeeTableTopMaterial,
		);
		coffeeTableTop.position.set(0, 0.74 * decorScale, loungeCenterZ);
		scene.add(coffeeTableTop);
		decorMeshes.push(coffeeTableTop);

		const coffeeTableLegOffsets = [
			new THREE.Vector3(0.86 * decorScale, 0.45 * decorScale, loungeCenterZ + 0.3 * decorScale),
			new THREE.Vector3(-0.86 * decorScale, 0.45 * decorScale, loungeCenterZ + 0.3 * decorScale),
			new THREE.Vector3(0.86 * decorScale, 0.45 * decorScale, loungeCenterZ - 0.3 * decorScale),
			new THREE.Vector3(-0.86 * decorScale, 0.45 * decorScale, loungeCenterZ - 0.3 * decorScale),
		];
		coffeeTableLegOffsets.forEach((legPos) => {
			const leg = new THREE.Mesh(
				new THREE.BoxGeometry(0.12 * decorScale, 0.36 * decorScale, 0.12 * decorScale),
				coffeeTableLegMaterial,
			);
			leg.position.set(legPos.x, legPos.y, legPos.z);
			scene.add(leg);
			decorMeshes.push(leg);
		});

		createSofa(0, loungeCenterZ - (compactRoom ? 2.25 : (premiumRoom ? 4.1 : 3.05)), 0, loungeScale);
		createSofa(0, loungeCenterZ + (compactRoom ? 2.25 : (premiumRoom ? 4.1 : 3.05)), Math.PI, loungeScale);
		if (decorDensity >= 2) {
			createSofa(-(compactRoom ? 3.35 : (premiumRoom ? 5.35 : 4.25)), loungeCenterZ, Math.PI / 2, loungeScale * 0.9);
			createSofa((compactRoom ? 3.35 : (premiumRoom ? 5.35 : 4.25)), loungeCenterZ, -Math.PI / 2, loungeScale * 0.9);
		}

		const centerpiecePot = new THREE.Mesh(
			new THREE.CylinderGeometry(0.28 * decorScale, 0.34 * decorScale, 0.3 * decorScale, 20),
			centerPotMaterial,
		);
		centerpiecePot.position.set(0, 0.7 * decorScale, loungeCenterZ);
		scene.add(centerpiecePot);
		decorMeshes.push(centerpiecePot);

		[
			new THREE.Vector3(0, 0.9 * decorScale, loungeCenterZ),
			new THREE.Vector3(0.12 * decorScale, 0.92 * decorScale, loungeCenterZ + 0.08 * decorScale),
			new THREE.Vector3(-0.12 * decorScale, 0.92 * decorScale, loungeCenterZ - 0.08 * decorScale),
			new THREE.Vector3(0.1 * decorScale, 0.88 * decorScale, loungeCenterZ - 0.14 * decorScale),
			new THREE.Vector3(-0.1 * decorScale, 0.88 * decorScale, loungeCenterZ + 0.14 * decorScale),
		].forEach((stemBase) => {
			const plantStem = new THREE.Mesh(
				new THREE.CylinderGeometry(0.015 * decorScale, 0.02 * decorScale, 0.22 * decorScale, 10),
				centerStemMaterial,
			);
			plantStem.position.set(stemBase.x, stemBase.y, stemBase.z);
			scene.add(plantStem);
			decorMeshes.push(plantStem);

			const leafCenter = stemBase.clone();
			leafCenter.y += 0.14 * decorScale;
			const leafCluster = new THREE.Mesh(
				new THREE.SphereGeometry(0.09 * decorScale, 12, 12),
				centerLeafMaterial,
			);
			leafCluster.position.set(leafCenter.x, leafCenter.y, leafCenter.z);
			scene.add(leafCluster);
			decorMeshes.push(leafCluster);
		});

		const lampCanopy = new THREE.Mesh(
			new THREE.CylinderGeometry(0.18 * decorScale, 0.22 * decorScale, 0.08 * decorScale, 20),
			lampPoleMaterial,
		);
		lampCanopy.position.set(0, ceilingY - 0.28, loungeCenterZ);
		scene.add(lampCanopy);
		decorMeshes.push(lampCanopy);

		const lampPole = new THREE.Mesh(
			new THREE.CylinderGeometry(0.03 * decorScale, 0.03 * decorScale, (compactRoom ? 2 : 2.35) * decorScale, 14),
			lampPoleMaterial,
		);
		lampPole.position.set(0, ceilingY - (compactRoom ? 1.3 : 1.55) * decorScale, loungeCenterZ);
		scene.add(lampPole);
		decorMeshes.push(lampPole);

		const lampShade = new THREE.Mesh(
			new THREE.CylinderGeometry(0.44 * decorScale, 0.34 * decorScale, 0.4 * decorScale, 24, 1, true),
			lampShadeMaterial,
		);
		lampShade.position.set(0, ceilingY - (compactRoom ? 2.3 : 2.7) * decorScale, loungeCenterZ);
		scene.add(lampShade);
		decorMeshes.push(lampShade);

		const lampDiffuser = new THREE.Mesh(
			new THREE.SphereGeometry(0.22 * decorScale, 16, 16, 0, Math.PI * 2, 0, Math.PI / 2),
			lampDiffuserMaterial,
		);
		lampDiffuser.position.set(0, lampShade.position.y - 0.18 * decorScale, loungeCenterZ);
		scene.add(lampDiffuser);
		decorMeshes.push(lampDiffuser);

		const lampBulb = new THREE.Mesh(
			new THREE.SphereGeometry(0.085 * decorScale, 14, 14),
			new THREE.MeshStandardMaterial({
				color: '#fff7d6',
				emissive: '#ffe8b3',
				emissiveIntensity: lightsOn ? 0.95 : 0.06,
				roughness: 0.28,
				metalness: 0.02,
			}),
		);
		lampBulb.position.set(0, lampDiffuser.position.y - 0.06 * decorScale, loungeCenterZ);
		scene.add(lampBulb);
		decorMeshes.push(lampBulb);
		decorMaterials.push(lampBulb.material as THREE.Material);

		const loungeLampLight = new THREE.PointLight('#ffe8b3', lightsOn ? 1.7 : 0, compactRoom ? 8 : 11, 2);
		loungeLampLight.position.set(0, lampBulb.position.y - 0.04 * decorScale, loungeCenterZ);
		scene.add(loungeLampLight);
		loungeAccentLights.push(loungeLampLight);

		const fixtureMaterial = new THREE.MeshStandardMaterial({
			color: lightsOn ? '#2f343b' : '#7b818a',
			roughness: 0.2,
			metalness: 0.2,
			emissive: '#ffe8b3',
			emissiveIntensity: fixtureEmissiveIntensity,
		});
		const fixtureMeshes: THREE.Mesh[] = [];
		const fixturePointLights: THREE.PointLight[] = [];
		const fixtureSpotLights: THREE.SpotLight[] = [];
		const fixtureTargets: THREE.Object3D[] = [];

		const createWallFixture = (position: THREE.Vector3, rotationY: number, targetPosition: THREE.Vector3) => {
			const fixture = new THREE.Mesh(new THREE.BoxGeometry(0.78, 1.02, 0.54), fixtureMaterial);
			fixture.position.copy(position);
			fixture.rotation.y = rotationY;
			scene.add(fixture);
			fixtureMeshes.push(fixture);

			const fixtureLens = new THREE.Mesh(
				new THREE.CylinderGeometry(0.18, 0.18, 0.06, 20),
				new THREE.MeshStandardMaterial({
					color: '#fef3c7',
					emissive: '#ffe8b3',
					emissiveIntensity: lightsOn ? 1.4 : 0,
					roughness: 0.18,
					metalness: 0.05,
				}),
			);
			fixtureLens.rotation.x = Math.PI / 2;
			fixtureLens.position.set(0, -0.48, rotationY === 0 ? 0.245 : rotationY === Math.PI ? -0.245 : 0);
			if (Math.abs(rotationY - Math.PI / 2) < 0.0001 || Math.abs(rotationY + Math.PI / 2) < 0.0001) {
				fixtureLens.rotation.z = Math.PI / 2;
				fixtureLens.position.set(rotationY > 0 ? 0.245 : -0.245, -0.48, 0);
			}
			fixture.add(fixtureLens);
			fixtureMeshes.push(fixtureLens);

			const light = new THREE.PointLight('#ffe8b3', fixturePointIntensity, 14, 2);
			light.position.copy(position);
			if (rotationY === 0) light.position.z += 0.35;
			if (Math.abs(rotationY - Math.PI) < 0.0001) light.position.z -= 0.35;
			if (Math.abs(rotationY - Math.PI / 2) < 0.0001) light.position.x += 0.35;
			if (Math.abs(rotationY + Math.PI / 2) < 0.0001) light.position.x -= 0.35;
			light.position.y -= 0.3;
			scene.add(light);
			fixturePointLights.push(light);

			const target = new THREE.Object3D();
			target.position.copy(targetPosition);
			scene.add(target);
			fixtureTargets.push(target);

			const spot = new THREE.SpotLight('#ffe8b3', fixtureSpotIntensity, 24, Math.PI / 8, 0.45, 1.35);
			spot.position.copy(position);
			spot.position.y -= 0.3;
			spot.target = target;
			scene.add(spot);
			fixtureSpotLights.push(spot);
		};

		const sideFixtureX = roomHalfWidth - 0.45;
		const sideFixtureTargetX = compactRoom ? 4.9 : (premiumRoom ? 8.6 : 6.4);
		const sideFixtureZ = [
			spacingOnWall(0, 3, sideWallZMin, sideWallZMax),
			spacingOnWall(1, 3, sideWallZMin, sideWallZMax),
			spacingOnWall(2, 3, sideWallZMin, sideWallZMax),
		];
		sideFixtureZ.forEach((z) => {
			createWallFixture(new THREE.Vector3(-sideFixtureX, 10.7, z), -Math.PI / 2, new THREE.Vector3(-sideFixtureTargetX, 4.2, z));
			createWallFixture(new THREE.Vector3(sideFixtureX, 10.7, z), Math.PI / 2, new THREE.Vector3(sideFixtureTargetX, 4.2, z));
		});
		const backFixtureZ = roomBackZ + 0.45;
		const frontFixtureZ = roomFrontZ - 0.45;
		const frontBackFixtureX = compactRoom ? 3.9 : (premiumRoom ? 5.8 : 4.4);
		const backTargetZ = compactRoom ? roomBackZ + 2.9 : (premiumRoom ? roomBackZ + 3.8 : -11.0);
		const frontTargetZ = compactRoom ? roomFrontZ - 2.9 : (premiumRoom ? roomFrontZ - 3.8 : 4.6);
		const frontBackTargetX = premiumRoom ? 5.6 : 4.2;
		createWallFixture(new THREE.Vector3(-frontBackFixtureX, 10.8, backFixtureZ), 0, new THREE.Vector3(-frontBackTargetX, 4.3, backTargetZ));
		createWallFixture(new THREE.Vector3(frontBackFixtureX, 10.8, backFixtureZ), 0, new THREE.Vector3(frontBackTargetX, 4.3, backTargetZ));
		createWallFixture(new THREE.Vector3(-frontBackFixtureX, 10.8, frontFixtureZ), Math.PI, new THREE.Vector3(-frontBackTargetX, 4.3, frontTargetZ));
		createWallFixture(new THREE.Vector3(frontBackFixtureX, 10.8, frontFixtureZ), Math.PI, new THREE.Vector3(frontBackTargetX, 4.3, frontTargetZ));

		const ceilingFixture = new THREE.Mesh(new THREE.BoxGeometry(0.9, 0.9, 0.55), fixtureMaterial);
		ceilingFixture.position.set(0, compactRoom ? 12.35 : (premiumRoom ? 15.35 : 14.15), compactRoom ? roomCenterZ + 0.2 : (premiumRoom ? roomCenterZ + 0.4 : -2.8));
		scene.add(ceilingFixture);
		fixtureMeshes.push(ceilingFixture);

		const ceilingLens = new THREE.Mesh(
			new THREE.CylinderGeometry(0.19, 0.19, 0.06, 20),
			new THREE.MeshStandardMaterial({
				color: '#fef3c7',
				emissive: '#ffe8b3',
				emissiveIntensity: lightsOn ? 1.45 : 0,
				roughness: 0.2,
				metalness: 0.05,
			}),
		);
		ceilingLens.position.set(0, -0.44, 0);
		ceilingLens.rotation.x = Math.PI / 2;
		ceilingFixture.add(ceilingLens);
		fixtureMeshes.push(ceilingLens);

		const ceilingTarget = new THREE.Object3D();
		ceilingTarget.position.set(0, 3.6, roomCenterZ);
		scene.add(ceilingTarget);
		fixtureTargets.push(ceilingTarget);

		const ceilingLight = new THREE.PointLight('#ffe8b3', lightsOn ? 1.05 : 0, compactRoom ? 12 : (premiumRoom ? 18 : 15), 2);
		ceilingLight.position.set(0, compactRoom ? 11.95 : (premiumRoom ? 14.95 : 13.75), compactRoom ? roomCenterZ + 0.2 : (premiumRoom ? roomCenterZ + 0.4 : -2.8));
		scene.add(ceilingLight);
		fixturePointLights.push(ceilingLight);

		const ceilingSpot = new THREE.SpotLight('#ffe8b3', lightsOn ? 2.7 : 0, compactRoom ? 20 : (premiumRoom ? 30 : 26), Math.PI / 8, 0.42, 1.35);
		ceilingSpot.position.set(0, compactRoom ? 11.95 : (premiumRoom ? 14.95 : 13.75), compactRoom ? roomCenterZ + 0.2 : (premiumRoom ? roomCenterZ + 0.4 : -2.8));
		ceilingSpot.target = ceilingTarget;
		scene.add(ceilingSpot);
		fixtureSpotLights.push(ceilingSpot);

		const platformSpotTarget = new THREE.Object3D();
		platformSpotTarget.position.set(0, 0.65, loungeCenterZ);
		scene.add(platformSpotTarget);
		fixtureTargets.push(platformSpotTarget);

		const platformSpotLight = new THREE.SpotLight(
			'#ffe8b3',
			lightsOn ? (compactRoom ? 4.8 : 6.2) : 0,
			compactRoom ? 16 : (premiumRoom ? 26 : 21),
			Math.PI / 4,
			0.34,
			1.05,
		);
		platformSpotLight.position.set(0, ceilingY - 0.55, loungeCenterZ);
		platformSpotLight.target = platformSpotTarget;
		scene.add(platformSpotLight);
		fixtureSpotLights.push(platformSpotLight);

		const platformFillLight = new THREE.PointLight(
			'#fff2d1',
			lightsOn ? (compactRoom ? 0.95 : 1.35) : 0,
			compactRoom ? 10 : (premiumRoom ? 16 : 13),
			2,
		);
		platformFillLight.position.set(0, compactRoom ? 2.45 : 2.85, loungeCenterZ);
		scene.add(platformFillLight);
		fixturePointLights.push(platformFillLight);

		const loader = new THREE.TextureLoader();
		const textureCache = new Map<string, THREE.Texture>();
		const maxAnisotropy = renderer.capabilities.getMaxAnisotropy();
		const allFrameUrls = Array.from(new Set(
			shoes.flatMap((shoe) => (lowPowerMode ? shoe.previewFrames : shoe.frames)).filter(Boolean),
		));
		const pendingFrameUrls = new Set(allFrameUrls);
		const readyFrameUrls = new Set<string>();

		const markFrameReady = (url: string) => {
			if (isDisposed) return;
			if (!pendingFrameUrls.has(url) || readyFrameUrls.has(url)) return;
			readyFrameUrls.add(url);
			if (readyFrameUrls.size >= pendingFrameUrls.size) {
				setIsSceneLoading(false);
			}
		};

		if (pendingFrameUrls.size === 0) {
			setIsSceneLoading(false);
		}

		const getTexture = (url: string) => {
			const cached = textureCache.get(url);
			if (cached) {
				const image = cached.image as HTMLImageElement | HTMLCanvasElement | undefined;
				if (image) {
					const isImageLoaded = image instanceof HTMLImageElement ? image.complete : image.width > 0;
					if (isImageLoaded) {
						markFrameReady(url);
					}
				}
				return cached;
			}

			const texture = loader.load(url, () => {
				markFrameReady(url);
			}, undefined, () => {
				markFrameReady(url);
			});
			texture.colorSpace = THREE.SRGBColorSpace;
			texture.minFilter = THREE.LinearMipmapLinearFilter;
			texture.magFilter = THREE.LinearFilter;
			texture.anisotropy = Math.min(maxAnisotropy, lowPowerMode ? 4 : 16);
			texture.generateMipmaps = true;
			textureCache.set(url, texture);
			return texture;
		};

		allFrameUrls.forEach((url) => {
			getTexture(url);
		});

		const shelfCardMaterials: THREE.MeshBasicMaterial[] = [];
		const shelfCards: THREE.Mesh[] = [];

		const animatedShelfCards: Array<{
			material: THREE.MeshBasicMaterial;
			shoeIdx: number;
			frameOffset: number;
			speed: number;
			lastFrameIdx: number;
		}> = [];
		const pickupLookVector = new THREE.Vector3();
		const pickupTargetPosition = new THREE.Vector3();
		const pickupCurveOffset = new THREE.Vector3();
		const slotDefinitions: Array<{
			position: THREE.Vector3;
			rotationY: number;
			cardWidth: number;
			cardHeight: number;
			frameOffsetSeed: number;
		}> = [];

		for (const side of [-1, 1]) {
			for (let level = 0; level < 3; level += 1) {
				for (let i = 0; i < sideSlotsPerWall; i += 1) {
					slotDefinitions.push({
						position: new THREE.Vector3(
							side * sideCardX,
							1.95 + level * 1.85,
							sideWallCenterZ + centeredWallOffset(i, sideSlotsPerWall, wallSlotGap),
						),
						rotationY: side === -1 ? Math.PI / 2 : -Math.PI / 2,
						cardWidth: 2.0,
						cardHeight: 1.25,
						frameOffsetSeed: i * 5 + level * 9 + (side === -1 ? 0 : 13),
					});
				}
			}
		}

		for (const depthSide of [-1, 1]) {
			for (let level = 0; level < 3; level += 1) {
				for (let i = 0; i < depthSlotsPerWall; i += 1) {
					slotDefinitions.push({
						position: new THREE.Vector3(
							depthWallCenterX + centeredWallOffset(i, depthSlotsPerWall, wallSlotGap),
							1.95 + level * 1.85,
							depthSide === -1 ? depthCardBackZ : depthCardFrontZ,
						),
						rotationY: depthSide === -1 ? 0 : Math.PI,
						cardWidth: 1.85,
						cardHeight: 1.12,
						frameOffsetSeed: i * 7 + level * 11 + (depthSide === -1 ? 0 : 17),
					});
				}
			}
		}

		if (includeCenterSlots) {
			const centerZMin = sideWallZMin + 0.35;
			const centerZMax = sideWallZMax - 0.35;
			for (const centerSide of [-1, 1]) {
				for (let level = 0; level < 3; level += 1) {
					for (let i = 0; i < 4; i += 1) {
						slotDefinitions.push({
							position: new THREE.Vector3(
								centerSide * 1.85,
								1.95 + level * 1.85,
								spacingOnWall(i, 4, centerZMin, centerZMax),
							),
							rotationY: centerSide === -1 ? Math.PI / 2 : -Math.PI / 2,
							cardWidth: 1.75,
							cardHeight: 1.08,
							frameOffsetSeed: i * 11 + level * 19 + (centerSide === -1 ? 23 : 41),
						});
					}
				}
			}
		}

		const renderableSlotCount = Math.min(shoes.length, lowPowerMode ? Math.min(showroomDisplayCapacity, 36) : showroomDisplayCapacity, slotDefinitions.length);

		slotDefinitions.slice(0, renderableSlotCount).forEach((slot, shoeIdx) => {
			const frameIdx = 0;
			const frames = shoes[shoeIdx]?.frames ?? [];
			if (frames.length === 0) {
				return;
			}

			const material = new THREE.MeshBasicMaterial({
				map: getTexture(frames[frameIdx]),
				transparent: true,
				alphaTest: 0.02,
				depthWrite: false,
				side: THREE.DoubleSide,
			});

			const card = new THREE.Mesh(new THREE.PlaneGeometry(slot.cardWidth, slot.cardHeight), material);
			const basePosition = slot.position.clone();
			card.position.copy(basePosition);
			card.rotation.y = slot.rotationY;
			const insetOffset = 0.08;
			card.position.x -= Math.sin(card.rotation.y) * insetOffset;
			card.position.z -= Math.cos(card.rotation.y) * insetOffset;
			card.userData.basePosition = card.position.clone();
			card.userData.baseRotationX = card.rotation.x;
			card.userData.baseRotationY = card.rotation.y;
			card.userData.baseRotationZ = card.rotation.z;
			card.userData.baseScaleX = card.scale.x;
			card.userData.baseScaleY = card.scale.y;
			card.userData.baseScaleZ = card.scale.z;
			card.userData.baseY = basePosition.y;
			card.userData.shoeIdx = shoeIdx;
			card.castShadow = false;
			scene.add(card);
			card.visible = !hiddenShelfShoeIndicesRef.current.has(shoeIdx);

			shelfCardMaterials.push(material);
			shelfCards.push(card);
			animatedShelfCards.push({
				material,
				shoeIdx,
				frameOffset: slot.frameOffsetSeed % frames.length,
				speed: 20,
				lastFrameIdx: frameIdx,
			});
		});
		shelfCardPickablesRef.current = shelfCards;

		const focusBackdropGeometry = new THREE.PlaneGeometry(6.8, 4.2);
		const focusCardGeometry = new THREE.PlaneGeometry(4.8, 3.2);
		const focusBackdropMaterial = new THREE.MeshBasicMaterial({
			color: '#0f172a',
			transparent: true,
			opacity: 0.4,
			depthTest: false,
			side: THREE.DoubleSide,
		});
		const focusCardMaterial = new THREE.MeshBasicMaterial({
			transparent: true,
			alphaTest: 0.02,
			depthWrite: false,
			depthTest: false,
			side: THREE.DoubleSide,
		});

		const focusGroup = new THREE.Group();
		focusGroup.visible = false;
		const focusBackdrop = new THREE.Mesh(focusBackdropGeometry, focusBackdropMaterial);
		focusBackdrop.position.set(0, 0, -0.04);
		focusGroup.add(focusBackdrop);

		const focusCard = new THREE.Mesh(focusCardGeometry, focusCardMaterial);
		focusGroup.add(focusCard);
		scene.add(focusGroup);

		const clock = new THREE.Clock();
		let rafId = 0;
		let previewFrameTick = 0;

		const clearMovementKeys = () => {
			keyState.forward = false;
			keyState.backward = false;
			keyState.left = false;
			keyState.right = false;
			clearJoystickVector();
		};

		const animate = () => {
			const delta = Math.min(clock.getDelta(), 0.05);
			const elapsed = clock.elapsedTime;
			const isModalOpen = focusedShoeIndexRef.current !== null;

			if (isModalOpen) {
				clearMovementKeys();
				swipeGuideGroup.visible = false;
				focusGroup.visible = false;
				renderer.render(scene, camera);
				rafId = requestAnimationFrame(animate);
				return;
			}

			if (!document.hasFocus()) {
				clearMovementKeys();
			}

			cameraYawRef.current += (targetCameraYawRef.current - cameraYawRef.current) * 0.18;
			cameraPitchRef.current += (targetCameraPitchRef.current - cameraPitchRef.current) * 0.18;

			movementDirection.set(0, 0, 0);
			const movementYaw = cameraYawRef.current;
			movementForward.set(Math.sin(movementYaw), 0, -Math.cos(movementYaw));
			movementRight.set(Math.cos(movementYaw), 0, Math.sin(movementYaw));

			if (keyState.forward) movementDirection.add(movementForward);
			if (keyState.backward) movementDirection.sub(movementForward);
			if (keyState.right) movementDirection.add(movementRight);
			if (keyState.left) movementDirection.sub(movementRight);

			const joystick = joystickVectorRef.current;
			if (joystick.active) {
				if (joystick.y < -JOYSTICK_DEADZONE) {
					movementDirection.addScaledVector(movementForward, Math.min(1, -joystick.y));
				}
				if (joystick.y > JOYSTICK_DEADZONE) {
					movementDirection.addScaledVector(movementForward, -Math.min(1, joystick.y));
				}
				if (joystick.x > JOYSTICK_DEADZONE) {
					movementDirection.addScaledVector(movementRight, Math.min(1, joystick.x));
				}
				if (joystick.x < -JOYSTICK_DEADZONE) {
					movementDirection.addScaledVector(movementRight, -Math.min(1, -joystick.x));
				}
			}

			if (movementDirection.lengthSq() > 0) {
				movementDirection.normalize();
				cameraPosition.x += movementDirection.x * walkSpeed * delta;
				cameraPosition.z += movementDirection.z * walkSpeed * delta;
			}

			cameraPosition.x = Math.max(minWalkX, Math.min(maxWalkX, cameraPosition.x));
			cameraPosition.z = Math.max(minWalkZ, Math.min(maxWalkZ, cameraPosition.z));
			cameraPosition.y = cameraBaseY;
			camera.position.copy(cameraPosition);

			const lookDistance = 14;
			const lookX = camera.position.x + Math.sin(cameraYawRef.current) * Math.cos(cameraPitchRef.current) * lookDistance;
			const lookY = camera.position.y + Math.sin(cameraPitchRef.current) * lookDistance;
			const lookZ = camera.position.z - Math.cos(cameraYawRef.current) * Math.cos(cameraPitchRef.current) * lookDistance;
			camera.lookAt(lookX, lookY, lookZ);

			const pickupAnimation = pickupAnimationRef.current;
			const activePickupShoeIdx = pickupAnimation?.shoeIdx ?? null;

			if (pickupAnimation) {
				const elapsedMs = performance.now() - pickupAnimation.startTimeMs;
				const t = Math.max(0, Math.min(1, elapsedMs / pickupAnimation.durationMs));
				const eased = 1 - Math.pow(1 - t, 4);
				const selectedCard = shelfCards.find((card) => (card.userData.shoeIdx as number) === pickupAnimation.shoeIdx);
				const activeEntry = animatedShelfCards.find((entry) => entry.shoeIdx === pickupAnimation.shoeIdx);

				if (selectedCard) {
					const basePosition = (selectedCard.userData.basePosition as THREE.Vector3 | undefined) ?? selectedCard.position;
					camera.getWorldDirection(pickupLookVector);
					pickupTargetPosition.copy(camera.position).add(pickupLookVector.multiplyScalar(2.55));
					pickupTargetPosition.y -= 0.18;

					pickupCurveOffset.set(
						Math.sin(t * Math.PI) * 0.12,
						Math.sin(t * Math.PI * 0.9) * 0.2,
						(1 - eased) * -0.2,
					);

					if (activeEntry) {
						const frames = shoes[activeEntry.shoeIdx]?.frames;
						if (frames && frames.length > 0) {
							const spinFrameIdx = Math.floor((elapsedMs / 26) + activeEntry.frameOffset) % frames.length;
							if (spinFrameIdx !== activeEntry.lastFrameIdx) {
								activeEntry.lastFrameIdx = spinFrameIdx;
								activeEntry.material.map = getTexture(frames[spinFrameIdx]);
								activeEntry.material.needsUpdate = true;
							}
						}
					}

					selectedCard.position.lerpVectors(basePosition, pickupTargetPosition, eased).add(pickupCurveOffset);
					selectedCard.rotation.x += ((((selectedCard.userData.baseRotationX as number) ?? 0) - 0.1 - 0.16 * eased - selectedCard.rotation.x) * 0.16);
					selectedCard.rotation.y += ((Math.atan2(camera.position.x - selectedCard.position.x, camera.position.z - selectedCard.position.z) - selectedCard.rotation.y) * 0.18);
					selectedCard.rotation.z += (((0.05 * (1 - eased)) - selectedCard.rotation.z) * 0.14);
					selectedCard.scale.setScalar(1 + 0.52 * eased);

					const selectedMaterial = selectedCard.material as THREE.MeshBasicMaterial;
					selectedMaterial.opacity = 1;
				}

				if (t >= 1) {
					const resolvedFrameIdx = activeEntry?.lastFrameIdx ?? 0;
					const resolvedFrameSrc = shoes[pickupAnimation.shoeIdx]?.frames[resolvedFrameIdx]
						?? shoes[pickupAnimation.shoeIdx]?.frames[0]
						?? null;

					if (
						!pendingFocusOpenRef.current
						|| pendingFocusOpenRef.current.shoeIdx !== pickupAnimation.shoeIdx
						|| pendingFocusOpenRef.current.frameIdx !== resolvedFrameIdx
					) {
						pendingFocusOpenRef.current = {
							shoeIdx: pickupAnimation.shoeIdx,
							frameIdx: resolvedFrameIdx,
							frameSrc: resolvedFrameSrc,
							ready: !resolvedFrameSrc,
						};

						void ensureFocusedFrameReady(resolvedFrameSrc).then(() => {
							if (
								pendingFocusOpenRef.current
								&& pendingFocusOpenRef.current.shoeIdx === pickupAnimation.shoeIdx
								&& pendingFocusOpenRef.current.frameIdx === resolvedFrameIdx
							) {
								pendingFocusOpenRef.current.ready = true;
							}
						});
					}

					if (!pendingFocusOpenRef.current?.ready) {
					// Shoe is held at camera — rotate it slowly while waiting for image load
					if (selectedCard) {
						selectedCard.rotation.y += 0.018;
					}
						rafId = requestAnimationFrame(animate);
						return;
					}

					if (selectedCard) {
						const basePosition = selectedCard.userData.basePosition as THREE.Vector3 | undefined;
						if (basePosition) {
							selectedCard.position.copy(basePosition);
						}
						selectedCard.rotation.x = (selectedCard.userData.baseRotationX as number) ?? 0;
						selectedCard.rotation.y = (selectedCard.userData.baseRotationY as number) ?? selectedCard.rotation.y;
						selectedCard.rotation.z = (selectedCard.userData.baseRotationZ as number) ?? 0;
						selectedCard.scale.set(
							(selectedCard.userData.baseScaleX as number) ?? 1,
							(selectedCard.userData.baseScaleY as number) ?? 1,
							(selectedCard.userData.baseScaleZ as number) ?? 1,
						);
						const selectedMaterial = selectedCard.material as THREE.MeshBasicMaterial;
						selectedMaterial.opacity = 1;
						focusedFrameIndexRef.current = resolvedFrameIdx;
						focusedFrameOffsetRef.current = resolvedFrameIdx;
					}

					const readyFocusState = pendingFocusOpenRef.current;
					setShelfShoeHidden(pickupAnimation.shoeIdx, true);
					pickupAnimationRef.current = null;
					pendingFocusOpenRef.current = null;
					isPickupAnimatingRef.current = false;
					setIsPickupAnimating(false);
					setFocusedFrameSrc(readyFocusState?.frameSrc ?? resolvedFrameSrc);
					setIsFocusedImageVisible(false);
					setFocusedShoeIndex(pickupAnimation.shoeIdx);
				}
			}

			shelfCards.forEach((card) => {
				if (activePickupShoeIdx !== null && (card.userData.shoeIdx as number) === activePickupShoeIdx) {
					return;
				}
				const baseY = (card.userData.baseY as number) ?? card.position.y;
				card.position.y += (baseY - card.position.y) * 0.08;
				card.rotation.x += ((((card.userData.baseRotationX as number) ?? 0) - card.rotation.x) * 0.18);
				card.rotation.y += ((((card.userData.baseRotationY as number) ?? 0) - card.rotation.y) * 0.18);
				card.rotation.z += ((((card.userData.baseRotationZ as number) ?? 0) - card.rotation.z) * 0.18);
				const baseScaleX = (card.userData.baseScaleX as number) ?? 1;
				const baseScaleY = (card.userData.baseScaleY as number) ?? 1;
				const baseScaleZ = (card.userData.baseScaleZ as number) ?? 1;
				card.scale.x += (baseScaleX - card.scale.x) * 0.18;
				card.scale.y += (baseScaleY - card.scale.y) * 0.18;
				card.scale.z += (baseScaleZ - card.scale.z) * 0.18;
			});

			const shouldAnimateShelfPreview = !lowPowerMode || (previewFrameTick++ % 2 === 0);
			if (shouldAnimateShelfPreview) {
				animatedShelfCards.forEach((entry) => {
				if (activePickupShoeIdx !== null && entry.shoeIdx === activePickupShoeIdx) return;

				const frames = shoes[entry.shoeIdx]?.frames;
				if (!frames || frames.length === 0) return;

				const nextFrameIdx = Math.floor(elapsed * entry.speed + entry.frameOffset) % frames.length;
				if (nextFrameIdx === entry.lastFrameIdx) return;

				entry.lastFrameIdx = nextFrameIdx;
				entry.material.map = getTexture(frames[nextFrameIdx]);
				entry.material.needsUpdate = true;
				});
			}

			swipeGuideGroup.visible = showSwipeHintRef.current;
			if (swipeGuideGroup.visible) {
				const horizontalPulse = Math.sin(elapsed * 4.8) * 0.2;
				leftArrowMesh.position.x = -1.08 - horizontalPulse;
				rightArrowMesh.position.x = 1.08 + horizontalPulse;
				leftLabelMesh.position.x = -1.08 - horizontalPulse;
				rightLabelMesh.position.x = 1.08 + horizontalPulse;

				const opacityPulse = 0.6 + (Math.sin(elapsed * 7.2) + 1) * 0.17;
				guideMaterial.opacity = opacityPulse;
				leftLabelMaterial.opacity = Math.max(0.72, opacityPulse - 0.08);
				rightLabelMaterial.opacity = Math.max(0.72, opacityPulse - 0.08);

				camera.getWorldDirection(cameraForward);
				swipeGuideGroup.position.copy(camera.position).add(cameraForward.multiplyScalar(3.3));
				swipeGuideGroup.position.y -= 1.12;
				swipeGuideGroup.quaternion.copy(camera.quaternion);
			}

			focusGroup.visible = false;

			renderer.render(scene, camera);
			rafId = requestAnimationFrame(animate);
		};

		animate();

		const handleResize = () => {
			if (!mountRef.current) return;
			const width = mountRef.current.clientWidth;
			const height = mountRef.current.clientHeight;
			camera.aspect = width / height;
			camera.updateProjectionMatrix();
			renderer.setSize(width, height);
		};

		const isTypingTarget = (target: EventTarget | null) => {
			if (!(target instanceof HTMLElement)) return false;
			const tagName = target.tagName;
			return tagName === 'INPUT' || tagName === 'TEXTAREA' || tagName === 'SELECT' || target.isContentEditable;
		};

		const handleKeyDown = (event: KeyboardEvent) => {
			if (focusedShoeIndexRef.current !== null || isTypingTarget(event.target)) {
				return;
			}

			switch (event.key.toLowerCase()) {
				case 'w':
					keyState.forward = true;
					event.preventDefault();
					break;
				case 'a':
					keyState.left = true;
					event.preventDefault();
					break;
				case 's':
					keyState.backward = true;
					event.preventDefault();
					break;
				case 'd':
					keyState.right = true;
					event.preventDefault();
					break;
				default:
					break;
			}

			if (showSwipeHintRef.current && (keyState.forward || keyState.backward || keyState.left || keyState.right)) {
				setShowSwipeHint(false);
			}
		};

		const handleKeyUp = (event: KeyboardEvent) => {
			switch (event.key.toLowerCase()) {
				case 'w':
					keyState.forward = false;
					break;
				case 'a':
					keyState.left = false;
					break;
				case 's':
					keyState.backward = false;
					break;
				case 'd':
					keyState.right = false;
					break;
				default:
					break;
			}
		};

		window.addEventListener('resize', handleResize);
		window.addEventListener('keydown', handleKeyDown);
		window.addEventListener('keyup', handleKeyUp);
		window.addEventListener('blur', clearMovementKeys);
		document.addEventListener('visibilitychange', clearMovementKeys);

		return () => {
			isDisposed = true;
			clearJoystickVector();
			hiddenShelfShoeIndicesRef.current.clear();
			pickupAnimationRef.current = null;
			pendingFocusOpenRef.current = null;
			isPickupAnimatingRef.current = false;
			if (focusedRafIdRef.current !== null) {
				cancelAnimationFrame(focusedRafIdRef.current);
				focusedRafIdRef.current = null;
			}
			cameraRef.current = null;
			shelfCardPickablesRef.current = [];
			window.removeEventListener('resize', handleResize);
			window.removeEventListener('keydown', handleKeyDown);
			window.removeEventListener('keyup', handleKeyUp);
			window.removeEventListener('blur', clearMovementKeys);
			document.removeEventListener('visibilitychange', clearMovementKeys);
			cancelAnimationFrame(rafId);

			floor.geometry.dispose();
			(floor.material as THREE.Material).dispose();
			backWall.geometry.dispose();
			(backWall.material as THREE.Material).dispose();
			frontWall.geometry.dispose();
			(frontWall.material as THREE.Material).dispose();
			leftWall.geometry.dispose();
			(leftWall.material as THREE.Material).dispose();
			rightWall.geometry.dispose();
			(rightWall.material as THREE.Material).dispose();
			ceiling.geometry.dispose();
			(ceiling.material as THREE.Material).dispose();
			runway.geometry.dispose();
			(runway.material as THREE.Material).dispose();
			shelfMaterial.dispose();
			shelfEdgeMaterial.dispose();
			shelfMeshes.forEach((mesh) => mesh.geometry.dispose());
			fixtureMeshes.forEach((mesh) => mesh.geometry.dispose());
			fixtureMaterial.dispose();
			shelfCards.forEach((card) => (card.geometry as THREE.BufferGeometry).dispose());
			shelfCardMaterials.forEach((material) => material.dispose());
			decorMeshes.forEach((mesh) => mesh.geometry.dispose());
			Array.from(new Set(decorMaterials)).forEach((material) => material.dispose());
			decorTextures.forEach((texture) => texture.dispose());
			guideArrowGeometry.dispose();
			guideLabelGeometry.dispose();
			guideMaterial.dispose();
			leftLabelMaterial.dispose();
			rightLabelMaterial.dispose();
			guideLabelTextures.forEach((texture) => texture.dispose());
			focusBackdropGeometry.dispose();
			focusCardGeometry.dispose();
			focusBackdropMaterial.dispose();
			focusCardMaterial.dispose();
			scene.remove(focusGroup);
			scene.remove(swipeGuideGroup);
			loungeAccentLights.forEach((light) => light.dispose());
			fixturePointLights.forEach((light) => light.dispose());
			fixtureSpotLights.forEach((light) => light.dispose());
			fixtureTargets.forEach((target) => scene.remove(target));

			textureCache.forEach((texture) => texture.dispose());

			renderer.dispose();
			if (renderer.domElement && container.contains(renderer.domElement)) {
				container.removeChild(renderer.domElement);
			}
		};
	}, [shoes, isNightMode, lightsOn, showroomDisplayCapacity]);

	const goToPreviousShoe = () => {
		if (shoes.length === 0) return;
		setCurrentIndex((prev) => (prev === 0 ? shoes.length - 1 : prev - 1));
	};

	const goToNextShoe = () => {
		if (shoes.length === 0) return;
		setCurrentIndex((prev) => (prev + 1) % shoes.length);
	};

	const handlePointerDown = (clientX: number, clientY: number) => {
		if (isPickupAnimatingRef.current) return;
		void requestMobileLandscape();
		dragStartXRef.current = clientX;
		dragStartYRef.current = clientY;
		pointerMoveDistanceRef.current = 0;
		isDraggingRef.current = true;
		setIsDragging(true);
	};

	const handlePointerMove = (clientX: number, clientY: number) => {
		if (isPickupAnimatingRef.current) return;
		if (!isDraggingRef.current) return;

		const deltaX = clientX - dragStartXRef.current;
		const deltaY = clientY - dragStartYRef.current;
		pointerMoveDistanceRef.current += Math.abs(deltaX) + Math.abs(deltaY);
		dragStartXRef.current = clientX;
		dragStartYRef.current = clientY;
		if (pointerMoveDistanceRef.current > 14 && showSwipeHintRef.current) {
			setShowSwipeHint(false);
		}

		if (focusedShoeIndexRef.current !== null) {
			focusedFrameOffsetRef.current += deltaX * 0.35;
			return;
		}

		const sensitivity = 0.004;
		targetCameraYawRef.current += deltaX * sensitivity;
		targetCameraPitchRef.current -= deltaY * sensitivity;
		targetCameraPitchRef.current = Math.max(-1.05, Math.min(1.05, targetCameraPitchRef.current));
	};

	const handlePointerUp = (clientX?: number, clientY?: number) => {
		if (isPickupAnimatingRef.current) {
			isDraggingRef.current = false;
			setIsDragging(false);
			pointerMoveDistanceRef.current = 0;
			return;
		}

		if (
			pointerMoveDistanceRef.current < 8 &&
			focusedShoeIndexRef.current === null &&
			typeof clientX === 'number' &&
			typeof clientY === 'number'
		) {
			const pickedShoeIdx = pickShoeAtPointer(clientX, clientY);
			if (pickedShoeIdx !== null) {
				shoes[pickedShoeIdx]?.frames.forEach((frameSrc) => {
					void ensureFocusedFrameReady(frameSrc);
				});
				setFocusedFrameSrc(null);
				setIsFocusedImageVisible(false);
				pickupAnimationRef.current = {
					shoeIdx: pickedShoeIdx,
					startTimeMs: performance.now(),
					durationMs: 1180,
				};
				pendingFocusOpenRef.current = null;
				isPickupAnimatingRef.current = true;
				setIsPickupAnimating(true);
				setShowSwipeHint(false);
			}
		}

		isDraggingRef.current = false;
		setIsDragging(false);
		pointerMoveDistanceRef.current = 0;
	};

	const activeShoe = shoes[currentIndex] ?? null;
	const focusedShoe = focusedShoeIndex !== null ? shoes[focusedShoeIndex] : null;
	const shouldShowMobileJoystick = isTouchScreenDevice && focusedShoeIndex === null;

	return (
		<>
			<style>{`
				@keyframes focused-swipe-arrow-left {
					0%, 100% { transform: translate3d(0, 0, 0); opacity: 0.72; }
					50% { transform: translate3d(-16px, 0, 0); opacity: 1; }
				}

				@keyframes focused-swipe-arrow-right {
					0%, 100% { transform: translate3d(0, 0, 0); opacity: 0.72; }
					50% { transform: translate3d(16px, 0, 0); opacity: 1; }
				}

				.focused-swipe-arrow-left {
					animation: focused-swipe-arrow-left 1.35s ease-in-out infinite;
				}

				.focused-swipe-arrow-right {
					animation: focused-swipe-arrow-right 1.35s ease-in-out infinite;
				}

				@keyframes landscape-tip-fade {
					0% { opacity: 0; transform: translateY(-8px); }
					100% { opacity: 1; transform: translateY(0); }
				}

				.landscape-tip {
					animation: landscape-tip-fade 220ms ease-out;
				}
			`}</style>
		<section className={isStandalonePage
			? 'h-dvh w-full bg-white'
			: 'relative left-1/2 right-1/2 -mx-[50vw] w-screen border-y border-gray-200 bg-white py-4 md:py-6'}>
			{!isStandalonePage && (
				<div className="mb-4 flex flex-col gap-2 px-4 md:flex-row md:items-center md:justify-between md:px-8">
					<div>
						<h3 className="text-xl font-semibold text-gray-900">Virtual Showroom</h3>
						<p className="text-sm text-gray-500">Click and drag to orbit 360° and view top or bottom angles.</p>
						<p className="text-xs text-gray-500">Walk controls: W forward, A left, S backward, D right.</p>
						<p className="text-xs text-gray-500">Display capacity: {showroomDisplayCapacity} shoe slots</p>
					</div>
					<div className="flex flex-wrap items-center gap-2">
						<button
							type="button"
							onClick={() => setIsNightMode((prev) => !prev)}
							className="rounded-md border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50"
						>
							{isNightMode ? 'Day Mode' : 'Night Mode'}
						</button>
						<button
							type="button"
							onClick={goToPreviousShoe}
							className="rounded-md border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50"
						>
							Prev
						</button>
						<button
							type="button"
							onClick={goToNextShoe}
							className="rounded-md border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50"
						>
							Next
						</button>
					</div>
				</div>
			)}

			<div
				ref={viewportRef}
				className={`relative ${isStandalonePage ? 'h-dvh min-h-0' : 'h-[calc(100vh-180px)] min-h-170'} w-full touch-none overflow-hidden ${isStandalonePage ? '' : 'border-y border-gray-200'} bg-slate-200 ${isPickupAnimating ? 'cursor-progress' : isDragging ? 'cursor-grabbing' : focusedShoeIndex !== null ? 'cursor-ew-resize' : 'cursor-grab'}`}
				onPointerDown={(event) => {
					if (activePointerIdRef.current !== null) return;
					activePointerIdRef.current = event.pointerId;
					event.currentTarget.setPointerCapture(event.pointerId);
					handlePointerDown(event.clientX, event.clientY);
				}}
				onPointerMove={(event) => {
					if (activePointerIdRef.current !== event.pointerId) return;
					handlePointerMove(event.clientX, event.clientY);
				}}
				onPointerUp={(event) => {
					if (activePointerIdRef.current !== event.pointerId) return;
					event.currentTarget.releasePointerCapture(event.pointerId);
					activePointerIdRef.current = null;
					handlePointerUp(event.clientX, event.clientY);
				}}
				onPointerCancel={(event) => {
					if (activePointerIdRef.current !== event.pointerId) return;
					event.currentTarget.releasePointerCapture(event.pointerId);
					activePointerIdRef.current = null;
					handlePointerUp();
				}}
				onPointerLeave={(event) => {
					if (activePointerIdRef.current !== null && activePointerIdRef.current === event.pointerId) {
						handlePointerMove(event.clientX, event.clientY);
					}
				}}
			>
				<div ref={mountRef} className="h-full w-full" />
				{rooms.length === 2 && !isSceneLoading && focusedShoeIndex === null && (
					<button
						type="button"
						onPointerDown={(event) => event.stopPropagation()}
						onPointerMove={(event) => event.stopPropagation()}
						onPointerUp={(event) => event.stopPropagation()}
						onClick={(event) => {
							event.stopPropagation();
							switchRoom(activeRoomIndex === 0 ? 1 : 0);
						}}
						className="pointer-events-auto absolute bottom-6 right-6 z-20 flex h-28 w-20 flex-col items-center justify-center rounded-t-[2rem] border-4 border-amber-900 bg-amber-700 text-center text-xs font-bold text-white shadow-2xl hover:bg-amber-600"
						aria-label={activeRoomIndex === 0 ? 'Enter room 2' : 'Return to room 1'}
					>
						<span className="mb-2 text-2xl">🚪</span>
						{activeRoomIndex === 0 ? 'Next room' : 'Previous room'}
					</button>
				)}
				{rooms.length === 2 && (
					<div className="pointer-events-none absolute left-1/2 top-24 z-20 max-w-[calc(100%-1.5rem)] -translate-x-1/2 rounded-full bg-black/70 px-3 py-1.5 text-center text-[11px] font-semibold leading-4 text-white sm:top-3 sm:max-w-none sm:px-4 sm:py-2 sm:text-xs">
						Room {activeRoomIndex + 1} of 2 · Slots {activeRoom.start + 1}–{activeRoom.start + activeRoom.count}
					</div>
				)}

				{showLandscapeTip && shouldShowMobileJoystick && (
					<div className="landscape-tip pointer-events-none absolute left-1/2 top-36 z-40 w-[min(92%,420px)] -translate-x-1/2 rounded-xl border border-amber-200 bg-amber-50/95 px-3 py-2 text-center text-xs font-medium text-amber-900 shadow-md sm:top-16">
						Rotate your device to landscape for best showroom walking experience.
					</div>
				)}

				{shouldShowMobileJoystick && (
					<div
						className="showroom-joystick pointer-events-auto absolute bottom-6 left-4 z-40 h-32 w-32 select-none touch-none rounded-full border border-white/70 bg-slate-900/30 backdrop-blur-sm"
						onPointerDown={(event) => {
							event.preventDefault();
							event.stopPropagation();
							void requestMobileLandscape();
							joystickPointerIdRef.current = event.pointerId;
							event.currentTarget.setPointerCapture(event.pointerId);
							setJoystickByClientPosition(event.clientX, event.clientY, event.currentTarget);
						}}
						onPointerMove={(event) => {
							if (joystickPointerIdRef.current !== event.pointerId) return;
							event.preventDefault();
							event.stopPropagation();
							setJoystickByClientPosition(event.clientX, event.clientY, event.currentTarget);
						}}
						onPointerUp={(event) => {
							if (joystickPointerIdRef.current !== event.pointerId) return;
							event.preventDefault();
							event.stopPropagation();
							if (event.currentTarget.hasPointerCapture(event.pointerId)) {
								event.currentTarget.releasePointerCapture(event.pointerId);
							}
							joystickPointerIdRef.current = null;
							clearJoystickVector();
						}}
						onPointerCancel={(event) => {
							if (joystickPointerIdRef.current !== event.pointerId) return;
							event.preventDefault();
							event.stopPropagation();
							if (event.currentTarget.hasPointerCapture(event.pointerId)) {
								event.currentTarget.releasePointerCapture(event.pointerId);
							}
							joystickPointerIdRef.current = null;
							clearJoystickVector();
						}}
					>
						<div className="absolute inset-3 rounded-full border border-white/45" />
						<div
							ref={joystickThumbRef}
							className="pointer-events-none absolute left-1/2 top-1/2 h-11 w-11 rounded-full border border-white/80 bg-white/75 shadow"
						/>
						<div className="pointer-events-none absolute bottom-2 left-1/2 -translate-x-1/2 text-[10px] font-semibold uppercase tracking-wider text-white/90">
							Walk
						</div>
					</div>
				)}

				{focusedShoeIndex !== null && focusedShoe && (
					<div
						className="absolute inset-0 z-30 opacity-100"
						onPointerDown={(event) => {
							if (event.target === event.currentTarget) {
								closeFocusedModal();
							}
						}}
					>
						<div
							className={`relative flex h-full w-full touch-none items-center justify-center overflow-hidden ${isFocusedDragging ? 'cursor-grabbing' : 'cursor-grab'}`}
							onPointerDown={(event) => {
								event.stopPropagation();
							}}
						>
							<div
								className="relative flex h-full w-full items-center justify-center overflow-hidden"
								onWheel={(event) => {
									event.preventDefault();
									event.stopPropagation();
									handleFocusedWheelZoom(event.deltaY);
								}}
								onPointerDown={(event) => {
									const targetElement = event.target as HTMLElement;
									if (targetElement.closest('button')) {
										return;
									}
									event.stopPropagation();
									event.preventDefault();
									startFocusedDrag(event.pointerId, event.clientX, event.currentTarget);
								}}
								onPointerMove={(event) => {
									event.stopPropagation();
									moveFocusedDrag(event.pointerId, event.clientX);
								}}
								onPointerUp={(event) => {
									event.stopPropagation();
									endFocusedDrag(event.pointerId, event.currentTarget);
								}}
								onPointerCancel={(event) => {
									event.stopPropagation();
									endFocusedDrag(event.pointerId, event.currentTarget);
								}}
								onPointerLeave={(event) => {
									event.stopPropagation();
									moveFocusedDrag(event.pointerId, event.clientX);
								}}
							>
								{focusedFrameSrc && (
									<img
										ref={focusedImageRef}
										src={focusedFrameSrc}
										alt={`${focusedShoe.name} 360 view`}
										className={`pointer-events-none max-h-full max-w-full select-none object-contain transition-all duration-500 ease-out ${isFocusedImageVisible ? 'scale-100 opacity-100' : 'scale-[0.94] opacity-0'}`}
										draggable={false}
										loading="eager"
										decoding="async"
										onLoad={() => setIsFocusedImageVisible(true)}
									/>
								)}

								<div className={`pointer-events-none absolute inset-0 z-10 flex items-center justify-between px-4 transition-opacity duration-500 md:px-10 ${showFocusedHint ? 'opacity-100' : 'opacity-0'}`}>
									<div
										className="focused-swipe-arrow-left flex flex-col items-center gap-2"
									>
										<svg viewBox="0 0 220 72" className="h-12 w-36 drop-shadow-[0_10px_28px_rgba(15,23,42,0.42)] md:h-16 md:w-52" aria-hidden="true">
											<polygon points="72,0 0,36 72,72 72,48 220,48 220,24 72,24" fill="rgba(30,41,59,0.78)" />
										</svg>
										<span className="rounded-full bg-slate-800/70 px-3 py-0.5 text-xs font-bold tracking-widest text-white shadow md:text-sm">swipe left</span>
									</div>
									<div
										className="focused-swipe-arrow-right flex flex-col items-center gap-2"
									>
										<svg viewBox="0 0 220 72" className="h-12 w-36 drop-shadow-[0_10px_28px_rgba(15,23,42,0.42)] md:h-16 md:w-52" aria-hidden="true">
											<polygon points="148,0 220,36 148,72 148,48 0,48 0,24 148,24" fill="rgba(30,41,59,0.78)" />
										</svg>
										<span className="rounded-full bg-slate-800/70 px-3 py-0.5 text-xs font-bold tracking-widest text-white shadow md:text-sm">swipe right</span>
									</div>
								</div>

								<button
									type="button"
									onPointerDown={(event) => event.stopPropagation()}
									onPointerMove={(event) => event.stopPropagation()}
									onPointerUp={(event) => event.stopPropagation()}
									onClick={(event) => {
										event.stopPropagation();
										closeFocusedModal();
									}}
									className="absolute left-4 top-4 z-10 rounded-full bg-black/75 p-2.5 text-white shadow-lg transition-colors hover:bg-black"
									aria-label="Close showroom"
								>
									<svg className="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
										<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
									</svg>
								</button>

								<button
									type="button"
									onPointerDown={(event) => event.stopPropagation()}
									onPointerMove={(event) => event.stopPropagation()}
									onPointerUp={(event) => event.stopPropagation()}
									onClick={(event) => {
										event.stopPropagation();
										if (focusedShoe?.slug) {
											window.location.assign(`/products/${focusedShoe.slug}`);
											return;
										}
										if (focusedShoe?.id) {
											window.location.assign(`/products/${focusedShoe.id}`);
											return;
										}
										window.location.assign('/products');
									}}
									className="absolute bottom-6 right-6 rounded-lg bg-black px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-gray-800"
									title="View products"
								>
									View Product
								</button>
							</div>
						</div>
					</div>
				)}

				{isStandalonePage && (
					<>
						<button
							type="button"
							onPointerDown={(event) => event.stopPropagation()}
							onPointerMove={(event) => event.stopPropagation()}
							onPointerUp={(event) => event.stopPropagation()}
							onClick={(event) => {
								event.stopPropagation();
								setIsNightMode((prev) => !prev);
							}}
							className="pointer-events-auto absolute right-3 top-3 z-20 rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50"
						>
							{isNightMode ? 'Day Mode' : 'Night Mode'}
						</button>
					</>
				)}

				{(isSceneLoading || isRoomSwitching) && (
					<div className="pointer-events-none absolute inset-0 z-50 flex items-center justify-center bg-slate-950/80 backdrop-blur-sm">
						<div className="flex flex-col items-center gap-4 text-center text-white">
							<div className="h-14 w-14 animate-spin rounded-full border-4 border-white/25 border-t-white" />
							<div>
								<p className="text-base font-semibold">{isRoomSwitching ? `Entering Room ${activeRoomIndex + 1}` : 'Preparing showroom'}</p>
								<p className="mt-1 text-xs text-white/65">Loading products and display shelves…</p>
							</div>
						</div>
					</div>
				)}

				{!isStandalonePage && activeShoe && (
					<div className="pointer-events-none absolute bottom-3 left-3 rounded-md bg-white/85 px-3 py-2 text-xs text-gray-700 shadow-sm">
						<p className="font-semibold text-gray-900">{activeShoe.name}</p>
						<p>{activeShoe.brand || 'SoleSpace'} • {activeShoe.stock > 0 ? `${activeShoe.stock} in stock` : 'Out of stock'}</p>
						<p className="text-[10px] text-gray-500">{showroomDisplayCapacity} display slots</p>
						<p className="text-[10px] text-gray-500">Using this shop&apos;s uploaded showroom and product images.</p>
					</div>
				)}

				{!isStandalonePage && shoes.length === 0 && (
					<div className="pointer-events-none absolute bottom-3 left-3 rounded-md bg-white/90 px-3 py-2 text-xs text-gray-700 shadow-sm">
						<p className="font-semibold text-gray-900">Virtual showroom is active</p>
						<p className="text-[10px] text-gray-500">{showroomDisplayCapacity} display slots</p>
						<p>Upload product images to display items on shelves.</p>
					</div>
				)}
			</div>

			{!isStandalonePage && (
				<div className="mt-4 px-4 text-xs text-gray-500 md:px-8">Swipe horizontally and vertically for full 360 shelf view from the center POV.</div>
			)}
		</section>
		</>
	);
};

export default VirtualShowroom;
