import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import * as THREE from 'three';
import { canWalkTo, getNearbyShowroomSeat, getShowroomLayout, SHOWROOM_ENTRANCE } from './showroomLayout';
import { resolveShowroomPlacements, swapShowroomPlacements, type ShowroomPlacement } from './showroomPlacement';
import { createShowroomPromptSprite, createShowroomScene } from './showroomScene';
import ShowroomTicTacToe, { type ShowroomTicTacToeHandle } from './ShowroomTicTacToe';
import type { TicTacToeBoard } from './showroomTicTacToeRules';
import { fetchWithCsrf } from '@/utils/fetch-with-csrf';

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
	shopName?: string;
	showroomPlacements?: ShowroomPlacement[];
	canEditShowroom?: boolean;
	showroomSetupRequired?: boolean;
}

interface ShoeViewSet {
	id: number;
	name: string;
	slug?: string;
	brand?: string;
	stock: number;
	frames: string[];
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

const MAX_SHOWROOM_SLOTS = 150;
const JOYSTICK_RADIUS_PX = 62;
const JOYSTICK_DEADZONE = 0.16;

const drawTableBoard = (
	texture: THREE.CanvasTexture,
	board: TicTacToeBoard,
	winningLine: number[],
	result: 'X' | 'O' | 'draw' | null,
) => {
	const canvas = texture.image as HTMLCanvasElement;
	const context = canvas.getContext('2d');
	if (!context) return;
	context.fillStyle = '#1a1511';
	context.fillRect(0, 0, 768, 768);
	context.fillStyle = '#2a2119';
	context.fillRect(30, 30, 708, 708);
	context.strokeStyle = '#d4ae73';
	context.lineWidth = 10;
	context.strokeRect(30, 30, 708, 708);
	for (let line = 1; line < 3; line++) {
		const position = 30 + line * 236;
		context.beginPath();
		context.moveTo(position, 30);
		context.lineTo(position, 738);
		context.moveTo(30, position);
		context.lineTo(738, position);
		context.stroke();
	}
	board.forEach((mark, index) => {
		const x = 148 + (index % 3) * 236;
		const y = 148 + Math.floor(index / 3) * 236;
		if (winningLine.includes(index)) {
			context.fillStyle = '#5f482b';
			context.fillRect(x - 109, y - 109, 218, 218);
		}
		if (!mark) return;
		context.fillStyle = mark === 'X' ? '#f3e9d8' : '#d4ae73';
		context.font = 'bold 162px sans-serif';
		context.textAlign = 'center';
		context.textBaseline = 'middle';
		context.fillText(mark, x, y + 8);
	});
	if (result) {
		context.fillStyle = 'rgba(12, 10, 8, 0.88)';
		context.fillRect(100, 315, 568, 138);
		context.fillStyle = '#f0d39b';
		context.font = 'bold 64px sans-serif';
		context.textAlign = 'center';
		context.fillText(result === 'X' ? 'YOU WIN' : result === 'O' ? 'BOT WINS' : 'DRAW', 384, 390);
	}
	texture.needsUpdate = true;
};

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
	shopName = '',
	showroomPlacements = [],
	canEditShowroom = false,
	showroomSetupRequired = false,
}) => {
	const mountRef = useRef<HTMLDivElement | null>(null);
	const viewportRef = useRef<HTMLDivElement | null>(null);
	const currentIndexRef = useRef(0);
	const dragStartXRef = useRef(0);
	const dragStartYRef = useRef(0);
	const cameraYawRef = useRef(-0.3);
	const targetCameraYawRef = useRef(-0.3);
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
	const slotTargetsRef = useRef<THREE.Mesh[]>([]);
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
	const placementAssignmentsRef = useRef<ShowroomPlacement[]>([]);
	const activePlacementTargetRef = useRef<string | null>(null);
	const carriedPlacementRef = useRef<ShowroomPlacement | null>(null);
	const selectedPlacementProductIdRef = useRef<number | null>(null);
	const pendingPlacementProductIdRef = useRef<number | null>(null);
	const layoutRef = useRef<ReturnType<typeof getShowroomLayout> | null>(null);
	const nearbySeatKeyRef = useRef<string | null>(null);
	const seatedSeatKeyRef = useRef<string | null>(null);
	const savedWalkingPositionRef = useRef<THREE.Vector3 | null>(null);
	const clearMovementRef = useRef<() => void>(() => undefined);
	const seatPromptSpritesRef = useRef<Array<{ key: string; sprite: THREE.Sprite; baseY: number }>>([]);
	const xoxGameRef = useRef<ShowroomTicTacToeHandle | null>(null);
	const xoxBoardsRef = useRef<THREE.Mesh[]>([]);
	const xoxTextureRef = useRef<THREE.CanvasTexture | null>(null);
	const xoxVisualRef = useRef<{ board: TicTacToeBoard; winningLine: number[]; result: 'X' | 'O' | 'draw' | null }>({
		board: [null, null, null, null, null, null, null, null, null], winningLine: [], result: null,
	});
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
	const [savedPlacements, setSavedPlacements] = useState<ShowroomPlacement[]>(showroomPlacements);
	const [isEditMode, setIsEditMode] = useState(false);
	const [highlightedSlotKey, setHighlightedSlotKey] = useState<string | null>(null);
	const [selectedProductId, setSelectedProductId] = useState<number | null>(null);
	const [selectedTargetSlotKey, setSelectedTargetSlotKey] = useState('');
	const [placementSaveStatus, setPlacementSaveStatus] = useState<'idle' | 'saving' | 'saved' | 'error'>('idle');
	const [carriedPlacement, setCarriedPlacement] = useState<ShowroomPlacement | null>(null);
	const [nearbySeatKey, setNearbySeatKey] = useState<string | null>(null);
	const [seatedSeatKey, setSeatedSeatKey] = useState<string | null>(null);
	const [isGameOpen, setIsGameOpen] = useState(false);
	const [gameSessionKey, setGameSessionKey] = useState(0);
	const updateTableBoard = useCallback((board: TicTacToeBoard, winningLine: number[], result: 'X' | 'O' | 'draw' | null) => {
		xoxVisualRef.current = { board, winningLine, result };
		if (xoxTextureRef.current) drawTableBoard(xoxTextureRef.current, board, winningLine, result);
	}, []);
	const walkingPositionRef = useRef<THREE.Vector3 | null>(null);
	const parsedSlotLimit = Number(showroomSlotLimit);
	const showroomDisplayCapacity = Number.isFinite(parsedSlotLimit)
		? Math.max(0, Math.min(Math.floor(parsedSlotLimit), MAX_SHOWROOM_SLOTS))
		: 60;

	const allShoes = useMemo<ShoeViewSet[]>(() => {
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
				};
			})
			.filter((shoe) => shoe.frames.length > 0);
	}, [products]);
	const shoes = useMemo(
		() => allShoes.slice(0, showroomDisplayCapacity),
		[allShoes, showroomDisplayCapacity],
	);
	const showroomSlots = useMemo(
		() => getShowroomLayout(showroomDisplayCapacity).slots,
		[showroomDisplayCapacity],
	);
	const placementAssignments = useMemo(
		() => resolveShowroomPlacements(
			shoes.map(shoe => ({ id: shoe.id })),
			showroomSlots,
			savedPlacements,
		),
		[shoes, showroomSlots, savedPlacements],
	);

	useEffect(() => {
		seatPromptSpritesRef.current.forEach(({ key, sprite }) => {
			sprite.visible = !isSceneLoading
				&& !isEditMode
				&& seatedSeatKey === null
				&& key === nearbySeatKey;
		});
	}, [isEditMode, isSceneLoading, nearbySeatKey, seatedSeatKey]);

	useEffect(() => {
		placementAssignmentsRef.current = placementAssignments;
	}, [placementAssignments]);

	useEffect(() => {
		slotTargetsRef.current.forEach((target) => {
			const material = target.material as THREE.MeshBasicMaterial;
			const active = highlightedSlotKey !== null && target.userData.slotKey === highlightedSlotKey;
			material.opacity = active ? 0.2 : 0;
			material.color.set(active ? '#f4d08a' : '#ffffff');
		});
	}, [highlightedSlotKey]);

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

	const highlightSlotTarget = (slotKey: string | null) => {
		activePlacementTargetRef.current = slotKey;
		slotTargetsRef.current.forEach((target) => {
			const material = target.material as THREE.MeshBasicMaterial;
			material.opacity = slotKey && target.userData.slotKey === slotKey ? 0.2 : 0;
			material.color.set(slotKey && target.userData.slotKey === slotKey ? '#f4d08a' : '#ffffff');
		});
		setHighlightedSlotKey(slotKey);
	};

	const savePlacement = async (productId: number, sourceSlotKey: string, targetSlotKey: string) => {
		if (sourceSlotKey === targetSlotKey || pendingPlacementProductIdRef.current !== null) return;

		const previousAssignments = placementAssignmentsRef.current.map(assignment => ({ ...assignment }));
		const optimisticAssignments = swapShowroomPlacements(previousAssignments, productId, sourceSlotKey, targetSlotKey);
		pendingPlacementProductIdRef.current = productId;
		setSavedPlacements(optimisticAssignments);
		setPlacementSaveStatus('saving');

		try {
			const response = await fetchWithCsrf('/api/showroom/placements', {
				method: 'PUT',
				headers: {
					Accept: 'application/json',
					'Content-Type': 'application/json',
				},
				body: JSON.stringify({
					product_id: productId,
					from_slot_key: sourceSlotKey,
					to_slot_key: targetSlotKey,
				}),
			});
			const payload = await response.json().catch(() => null) as { placements?: unknown } | null;
			if (!response.ok || !Array.isArray(payload?.placements)) {
				throw new Error('Unable to save showroom placement.');
			}
			const canonical = payload.placements.flatMap((placement) => {
				if (typeof placement !== 'object' || placement === null) return [];
				const row = placement as { product_id?: unknown; slot_key?: unknown };
				return typeof row.product_id === 'number' && typeof row.slot_key === 'string'
					? [{ productId: row.product_id, slotKey: row.slot_key }]
					: [];
			});
			setSavedPlacements(canonical);
			setPlacementSaveStatus('saved');
		} catch {
			setSavedPlacements(previousAssignments);
			setPlacementSaveStatus('error');
		} finally {
			pendingPlacementProductIdRef.current = null;
		}
	};

	const nearbyPlacementSlot = () => {
		const camera = cameraRef.current;
		if (!camera) return null;
		const forward = new THREE.Vector3();
		camera.getWorldDirection(forward);
		let best: { key: string; score: number } | null = null;
		for (const target of slotTargetsRef.current) {
			const offset = target.position.clone().sub(camera.position);
			const distance = offset.length();
			if (distance > 4.2 || distance < 0.3) continue;
			const alignment = offset.normalize().dot(forward);
			if (alignment < 0.55) continue;
			const score = (1 - alignment) * 4 + distance * 0.12;
			if (!best || score < best.score) best = { key: target.userData.slotKey as string, score };
		}
		return best?.key ?? null;
	};

	const handlePlacementKey = () => {
		if (!canEditShowroom || pendingPlacementProductIdRef.current !== null) return false;
		const carried = carriedPlacementRef.current;
		if (carried) {
			const targetSlotKey = nearbyPlacementSlot();
			if (!targetSlotKey) return true;
			carriedPlacementRef.current = null;
			setCarriedPlacement(null);
			highlightSlotTarget(null);
			void savePlacement(carried.productId, carried.slotKey, targetSlotKey);
			return true;
		}
		const selected = placementAssignmentsRef.current.find(
			(assignment) => assignment.productId === selectedPlacementProductIdRef.current,
		);
		const card = shelfCardPickablesRef.current.find(
			(item) => item.userData.productId === selected?.productId,
		);
		const camera = cameraRef.current;
		if (!selected || !card || !camera || camera.position.distanceTo(card.position) > 4.2) return false;
		carriedPlacementRef.current = selected;
		setCarriedPlacement(selected);
		setPlacementSaveStatus('idle');
		return true;
	};

	const sitOnSeat = (seatKey: string) => {
		const seat = layoutRef.current?.seats.find(item => item.key === seatKey);
		const camera = cameraRef.current;
		if (!seat || !camera || seatedSeatKeyRef.current !== null) return;

		savedWalkingPositionRef.current = walkingPositionRef.current?.clone() ?? camera.position.clone();
		seatedSeatKeyRef.current = seat.key;
		nearbySeatKeyRef.current = null;
		setSeatedSeatKey(seat.key);
		setNearbySeatKey(null);
		clearMovementRef.current();
		camera.position.set(...seat.cameraPosition);
		camera.lookAt(...seat.lookAt);
		setIsGameOpen(true);
	};

	const standUp = () => {
		const camera = cameraRef.current;
		const walkingPosition = savedWalkingPositionRef.current?.clone();
		seatedSeatKeyRef.current = null;
		nearbySeatKeyRef.current = null;
		setSeatedSeatKey(null);
		setNearbySeatKey(null);
		setIsGameOpen(false);
		setGameSessionKey(previous => previous + 1);
		clearMovementRef.current();
		if (walkingPosition) {
			walkingPositionRef.current = walkingPosition;
			camera?.position.copy(walkingPosition);
		}
		savedWalkingPositionRef.current = null;
	};

	const closeGame = () => setIsGameOpen(false);

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
		const renderer = new THREE.WebGLRenderer({ antialias: true, alpha: false, powerPreference: 'high-performance' });
		renderer.setPixelRatio(Math.min(window.devicePixelRatio, lowPowerMode ? 1.25 : 2));
		renderer.setSize(container.clientWidth, container.clientHeight);
		renderer.outputColorSpace = THREE.SRGBColorSpace;
		renderer.toneMapping = THREE.ACESFilmicToneMapping;
		renderer.toneMappingExposure = isNightMode ? 1.05 : 1.1;
		renderer.shadowMap.enabled = true;
		renderer.shadowMap.type = THREE.PCFSoftShadowMap;
		const showroom = createShowroomScene(
			renderer,
			showroomDisplayCapacity,
			isNightMode,
			lowPowerMode,
			shoes.length,
			shopName,
			canEditShowroom,
		);
		const { scene, layout } = showroom;
		layoutRef.current = layout;
		seatPromptSpritesRef.current = showroom.seatPromptSprites;
		slotTargetsRef.current = showroom.slotTargets;
		const boardCanvas = document.createElement('canvas');
		boardCanvas.width = 768;
		boardCanvas.height = 768;
		const boardTexture = new THREE.CanvasTexture(boardCanvas);
		boardTexture.colorSpace = THREE.SRGBColorSpace;
		xoxTextureRef.current = boardTexture;
		const boardGeometry = new THREE.PlaneGeometry(2.12, 2.12);
		const boardMaterial = new THREE.MeshBasicMaterial({ map: boardTexture, side: THREE.DoubleSide });
		xoxBoardsRef.current = layout.seats.map((seat, index) => {
			const lounge = layout.lounges[index];
			const board = new THREE.Mesh(boardGeometry, boardMaterial);
			board.position.set(lounge.x, 0.84, lounge.z - 0.4);
			board.rotation.x = -Math.PI / 2;
			board.userData.seatKey = seat.key;
			scene.add(board);
			return board;
		});
		drawTableBoard(boardTexture, xoxVisualRef.current.board, xoxVisualRef.current.winningLine, xoxVisualRef.current.result);
		container.replaceChildren(renderer.domElement);
		renderer.domElement.setAttribute('aria-label', 'Walkable SoleSpace sneaker showroom');

		const camera = new THREE.PerspectiveCamera(62, container.clientWidth / container.clientHeight, 0.1, 120);
		camera.position.copy(walkingPositionRef.current ?? new THREE.Vector3(...SHOWROOM_ENTRANCE));
		cameraRef.current = camera;
		const cameraBaseY = SHOWROOM_ENTRANCE[1];
		const cameraPosition = camera.position.clone();
		const keyState = { forward: false, backward: false, left: false, right: false };
		const movementDirection = new THREE.Vector3();
		const movementForward = new THREE.Vector3();
		const movementRight = new THREE.Vector3();
		const movementVelocity = new THREE.Vector3();
		const walkSpeed = 4.8;
		const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

		const loader = new THREE.TextureLoader();
		const textureCache = new Map<string, THREE.Texture>();
		const maxAnisotropy = renderer.capabilities.getMaxAnisotropy();
		const allFrameUrls = Array.from(new Set(
			shoes.map((shoe) => shoe.frames[0]).filter(Boolean),
		));
		const pendingFrameUrls = new Set(allFrameUrls);
		const readyFrameUrls = new Set<string>();
		let materialsReady = false;
		const finishLoading = () => {
			if (!isDisposed && materialsReady && readyFrameUrls.size >= pendingFrameUrls.size) setIsSceneLoading(false);
		};
		void showroom.ready.then(() => {
			if (isDisposed) return;
			materialsReady = true;
			renderer.shadowMap.needsUpdate = true;
			finishLoading();
		});

		const markFrameReady = (url: string) => {
			if (isDisposed) return;
			if (!pendingFrameUrls.has(url) || readyFrameUrls.has(url)) return;
			readyFrameUrls.add(url);
			finishLoading();
		};

		if (pendingFrameUrls.size === 0) {
			finishLoading();
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

			const texture = loader.load(url, (loaded) => {
				if (isDisposed) { loaded.dispose(); return; }
				const image = loaded.image as HTMLImageElement;
				const canvas = document.createElement('canvas');
				canvas.width = lowPowerMode ? 384 : 512;
				canvas.height = Math.round(canvas.width * 1.05 / 1.65);
				const context = canvas.getContext('2d');
				if (context && image.width && image.height) {
					const scale = Math.min(canvas.width / image.width, canvas.height / image.height);
					const width = image.width * scale;
					const height = image.height * scale;
					context.drawImage(image, (canvas.width - width) / 2, canvas.height - height, width, height);
					loaded.image = canvas;
					loaded.needsUpdate = true;
				}
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
		const shoePromptSprites: Array<{
			shoeIdx: number;
			sprite: THREE.Sprite;
			texture: THREE.CanvasTexture;
			material: THREE.SpriteMaterial;
			card: THREE.Mesh;
		}> = [];

		const pickupLookVector = new THREE.Vector3();
		const pickupTargetPosition = new THREE.Vector3();
		const pickupCurveOffset = new THREE.Vector3();
		const slotDefinitions = new Map(layout.slots.map((slot) => [slot.key, {
			position: new THREE.Vector3(...slot.position),
			rotationY: slot.rotationY,
		}]));
		const cardGeometry = new THREE.PlaneGeometry(1.65, 1.05);

		placementAssignments.forEach((assignment) => {
			const shoeIdx = shoes.findIndex(shoe => shoe.id === assignment.productId);
			const slot = slotDefinitions.get(assignment.slotKey);
			if (shoeIdx < 0 || !slot) return;
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

			const card = new THREE.Mesh(cardGeometry, material);
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
			card.userData.productId = shoes[shoeIdx].id;
			card.userData.slotKey = assignment.slotKey;
			card.castShadow = false;
			scene.add(card);
			card.visible = !hiddenShelfShoeIndicesRef.current.has(shoeIdx);
			const clickPrompt = createShowroomPromptSprite(canEditShowroom ? 'CLICK + E' : 'CLICK');
			clickPrompt.sprite.position.set(card.position.x, card.position.y + 0.88, card.position.z);
			clickPrompt.sprite.scale.set(1.08, 0.3, 1);
			scene.add(clickPrompt.sprite);
			shoePromptSprites.push({
				shoeIdx,
				sprite: clickPrompt.sprite,
				texture: clickPrompt.texture,
				material: clickPrompt.material,
				card,
			});

			shelfCardMaterials.push(material);
			shelfCards.push(card);
		});
		shelfCardPickablesRef.current = shelfCards;

		const clock = new THREE.Clock();
		let rafId = 0;


		const clearMovementKeys = () => {
			keyState.forward = false;
			keyState.backward = false;
			keyState.left = false;
			keyState.right = false;
			clearJoystickVector();
			movementVelocity.set(0, 0, 0);
		};
		clearMovementRef.current = clearMovementKeys;
		const updatePromptSprites = (activePickupShoeIdx: number | null) => {
			const promptTime = performance.now() * 0.004;
			showroom.seatPromptSprites.forEach(({ sprite, baseY }, index) => {
				sprite.position.y = baseY + (reducedMotion ? 0 : Math.sin(promptTime + index) * 0.07);
			});
			shoePromptSprites.forEach(({ shoeIdx, sprite, card }) => {
				sprite.visible = card.visible && activePickupShoeIdx !== shoeIdx && carriedPlacementRef.current?.productId !== card.userData.productId;
				sprite.position.x = card.position.x;
				sprite.position.z = card.position.z;
				sprite.position.y = card.position.y + 0.88 + (reducedMotion ? 0 : Math.sin(promptTime + shoeIdx) * 0.025);
			});
		};

		const animate = () => {
			const delta = Math.min(clock.getDelta(), 0.05);
			const isModalOpen = focusedShoeIndexRef.current !== null;
			updatePromptSprites(pickupAnimationRef.current?.shoeIdx ?? null);

			if (isModalOpen) {
				clearMovementKeys();
				renderer.render(scene, camera);
				rafId = requestAnimationFrame(animate);
				return;
			}

			if (seatedSeatKeyRef.current !== null) {
				clearMovementKeys();
				const seat = layout.seats.find(item => item.key === seatedSeatKeyRef.current);
				if (seat) {
					camera.position.set(...seat.cameraPosition);
					camera.lookAt(...seat.lookAt);
				}
				renderer.render(scene, camera);
				rafId = requestAnimationFrame(animate);
				return;
			}

			if (!document.hasFocus() || isPickupAnimatingRef.current) {
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

			if (movementDirection.lengthSq() > 1) movementDirection.normalize();
			movementVelocity.lerp(movementDirection.multiplyScalar(walkSpeed), reducedMotion ? 1 : 1 - Math.exp(-12 * delta));
			const nextX = cameraPosition.x + movementVelocity.x * delta;
			const nextZ = cameraPosition.z + movementVelocity.z * delta;
			if (canWalkTo(nextX, cameraPosition.z, layout.colliders)) cameraPosition.x = nextX;
			if (canWalkTo(cameraPosition.x, nextZ, layout.colliders)) cameraPosition.z = nextZ;
			cameraPosition.y = cameraBaseY;
			camera.position.copy(cameraPosition);
			walkingPositionRef.current = cameraPosition;
			const nearbySeat = getNearbyShowroomSeat(cameraPosition.x, cameraPosition.z, layout.seats);
			const nextNearbySeatKey = nearbySeat?.key ?? null;
			if (nearbySeatKeyRef.current !== nextNearbySeatKey) {
				nearbySeatKeyRef.current = nextNearbySeatKey;
				setNearbySeatKey(nextNearbySeatKey);
			}

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


					selectedCard.position.lerpVectors(basePosition, pickupTargetPosition, eased).add(pickupCurveOffset);
					selectedCard.rotation.x += ((((selectedCard.userData.baseRotationX as number) ?? 0) - 0.1 - 0.16 * eased - selectedCard.rotation.x) * 0.16);
					selectedCard.rotation.y += ((Math.atan2(camera.position.x - selectedCard.position.x, camera.position.z - selectedCard.position.z) - selectedCard.rotation.y) * 0.18);
					selectedCard.rotation.z += (((0.05 * (1 - eased)) - selectedCard.rotation.z) * 0.14);
					selectedCard.scale.setScalar(1 + 0.52 * eased);

					const selectedMaterial = selectedCard.material as THREE.MeshBasicMaterial;
					selectedMaterial.opacity = 1;
				}

				if (t >= 1) {
					const resolvedFrameIdx = 0;
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
			const carried = carriedPlacementRef.current;
			if (carried) {
				const targetSlotKey = nearbyPlacementSlot();
				if (targetSlotKey !== activePlacementTargetRef.current) highlightSlotTarget(targetSlotKey);
				const card = shelfCards.find((item) => item.userData.productId === carried.productId);
				if (card) {
					const direction = new THREE.Vector3();
					camera.getWorldDirection(direction);
					card.position.copy(camera.position).addScaledVector(direction, 2.1);
					card.position.y -= 0.35;
					card.quaternion.copy(camera.quaternion);
				}
			}


			renderer.render(scene, camera);
			rafId = requestAnimationFrame(animate);
		};

		animate();
		renderer.shadowMap.autoUpdate = false;

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
			if (event.key.toLowerCase() === 'e' && !event.repeat && seatedSeatKeyRef.current === null && handlePlacementKey()) {
				event.preventDefault();
				return;
			}
			if (event.key.toLowerCase() === 'escape' && seatedSeatKeyRef.current !== null) {
				event.preventDefault();
				standUp();
				return;
			}
			if (
				event.key.toLowerCase() === 'e'
				&& nearbySeatKeyRef.current
				&& seatedSeatKeyRef.current === null
				&& focusedShoeIndexRef.current === null
			) {
				event.preventDefault();
				sitOnSeat(nearbySeatKeyRef.current);
				return;
			}
			if (seatedSeatKeyRef.current !== null) return;

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
			seatPromptSpritesRef.current = [];
			slotTargetsRef.current = [];
			xoxBoardsRef.current = [];
			xoxTextureRef.current = null;
			layoutRef.current = null;
			clearMovementRef.current = () => undefined;
			window.removeEventListener('resize', handleResize);
			window.removeEventListener('keydown', handleKeyDown);
			window.removeEventListener('keyup', handleKeyUp);
			window.removeEventListener('blur', clearMovementKeys);
			document.removeEventListener('visibilitychange', clearMovementKeys);
			cancelAnimationFrame(rafId);

			showroom.dispose();
			boardGeometry.dispose();
			boardMaterial.dispose();
			boardTexture.dispose();
			cardGeometry.dispose();
			shelfCardMaterials.forEach((material) => material.dispose());
			shoePromptSprites.forEach(({ sprite, material, texture }) => {
				scene.remove(sprite);
				material.dispose();
				texture.dispose();
			});

			textureCache.forEach((texture) => texture.dispose());

			renderer.dispose();
			if (renderer.domElement && container.contains(renderer.domElement)) {
				container.removeChild(renderer.domElement);
			}
		};
	}, [shoes, placementAssignments, shopName, canEditShowroom, isNightMode, showroomDisplayCapacity, isTouchScreenDevice]);

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
		if (seatedSeatKeyRef.current !== null) {
			dragStartXRef.current = clientX;
			dragStartYRef.current = clientY;
			pointerMoveDistanceRef.current = 0;
			isDraggingRef.current = true;
			return;
		}
		dragStartXRef.current = clientX;
		dragStartYRef.current = clientY;
		pointerMoveDistanceRef.current = 0;
		isDraggingRef.current = true;
		setIsDragging(true);
	};

	const handlePointerMove = (clientX: number, clientY: number) => {
		if (isPickupAnimatingRef.current) return;
		if (!isDraggingRef.current) return;
		if (seatedSeatKeyRef.current !== null) {
			pointerMoveDistanceRef.current += Math.abs(clientX - dragStartXRef.current) + Math.abs(clientY - dragStartYRef.current);
			dragStartXRef.current = clientX;
			dragStartYRef.current = clientY;
			return;
		}

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
		if (seatedSeatKeyRef.current !== null) {
			if (pointerMoveDistanceRef.current < 8 && typeof clientX === 'number' && typeof clientY === 'number') {
				const container = mountRef.current;
				const camera = cameraRef.current;
				if (container && camera) {
					const rect = container.getBoundingClientRect();
					pointerVectorRef.current.set(((clientX - rect.left) / rect.width) * 2 - 1, -((clientY - rect.top) / rect.height) * 2 + 1);
					raycasterRef.current.setFromCamera(pointerVectorRef.current, camera);
					const board = xoxBoardsRef.current.find((item) => item.userData.seatKey === seatedSeatKeyRef.current);
					const hit = board && raycasterRef.current.intersectObject(board, false)[0];
					if (hit?.uv) {
						const column = Math.min(2, Math.floor(hit.uv.x * 3));
						const row = Math.min(2, Math.floor((1 - hit.uv.y) * 3));
						xoxGameRef.current?.playAt(row * 3 + column);
					}
				}
			}
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
				if (canEditShowroom) {
					const productId = shoes[pickedShoeIdx]?.id;
					if (productId) {
						selectedPlacementProductIdRef.current = productId;
						setSelectedProductId(productId);
						setPlacementSaveStatus('idle');
					}
					isDraggingRef.current = false;
					setIsDragging(false);
					pointerMoveDistanceRef.current = 0;
					return;
				}
				shoes[pickedShoeIdx]?.frames.forEach((frameSrc) => {
					void ensureFocusedFrameReady(frameSrc);
				});
				setFocusedFrameSrc(null);
				setIsFocusedImageVisible(false);
				pickupAnimationRef.current = {
					shoeIdx: pickedShoeIdx,
					startTimeMs: performance.now(),
					durationMs: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 1 : 650,
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
	const displayShopName = shopName.trim() || 'The Gallery';
	const selectedAssignment = placementAssignments.find(assignment => assignment.productId === selectedProductId)
		?? placementAssignments[0];
	const selectedSourceSlotKey = selectedAssignment?.slotKey ?? '';
	const selectedMoveTargetSlotKey = selectedTargetSlotKey || selectedSourceSlotKey;

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

				@media (prefers-reduced-motion: reduce) {
					.focused-swipe-arrow-left, .focused-swipe-arrow-right { animation: none; }
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
						<p className="text-sm text-gray-500">Click and drag to look around and view top or bottom angles.</p>
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
				className={`relative ${isStandalonePage ? 'h-dvh min-h-0' : 'h-[calc(100vh-180px)] min-h-170'} w-full touch-none overflow-hidden ${isStandalonePage ? '' : 'border-y border-gray-200'} bg-slate-200 ${isPickupAnimating ? 'cursor-progress' : isDragging ? 'cursor-grabbing' : focusedShoeIndex !== null ? 'cursor-ew-resize' : isEditMode ? 'cursor-move' : 'cursor-default'}`}
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
				{!isSceneLoading && focusedShoeIndex === null && (
					<div className="pointer-events-none absolute left-1/2 top-24 z-20 -translate-x-1/2 rounded-md border border-white/20 bg-stone-950/85 px-4 py-2 text-center text-xs text-stone-100 sm:top-4">
						<p className="font-medium tracking-[0.16em]">{displayShopName} / THE GALLERY</p>
						<p className="mt-1 text-stone-300">{shoes.length} on display / {showroomDisplayCapacity} positions</p>
					</div>
				)}
				{showSwipeHint && focusedShoeIndex === null && !isSceneLoading && (
					<p className="pointer-events-none absolute bottom-44 left-1/2 z-20 max-w-[50%] -translate-x-1/2 rounded-md bg-stone-950/85 px-4 py-2 text-center text-xs text-stone-100 sm:bottom-6">
						{isTouchScreenDevice ? 'Joystick to walk / drag to look' : 'Click and drag to look / WASD to walk'}<br />Select a sneaker to inspect
					</p>
				)}
				{showLandscapeTip && shouldShowMobileJoystick && (
					<div className="landscape-tip pointer-events-none absolute left-1/2 top-36 z-40 w-[min(92%,420px)] -translate-x-1/2 rounded-xl border border-amber-200 bg-amber-50/95 px-3 py-2 text-center text-xs font-medium text-amber-900 shadow-md sm:top-16">
						Rotate your device to landscape for best showroom walking experience.
					</div>
				)}

				{nearbySeatKey && seatedSeatKey === null && !isEditMode && !isSceneLoading && (
					<div className="pointer-events-none absolute bottom-28 left-1/2 z-30 -translate-x-1/2 rounded-md border border-amber-200/60 bg-stone-950/90 px-4 py-2 text-center text-sm text-stone-100 shadow-lg sm:bottom-6">
						<p className="font-semibold">Press E to sit on the couch</p>
						<p className="mt-1 text-xs text-stone-300">Play a quick XOX game against the showroom bot.</p>
					</div>
				)}
				{canEditShowroom && seatedSeatKey === null && !isSceneLoading && (
					<div className="pointer-events-none absolute bottom-6 left-4 z-30 max-w-[min(85vw,320px)] rounded-lg border border-amber-200/60 bg-stone-950/90 px-4 py-3 text-xs text-stone-100 shadow-lg">
						<p className="font-semibold text-amber-200">{carriedPlacement ? 'Shoe picked up' : selectedProductId ? 'Shoe selected' : 'Arrange your shelves'}</p>
						<p className="mt-1">{carriedPlacement ? 'Walk to any shelf, aim at a position, then press E to place.' : selectedProductId ? 'Walk near the shoe and press E to pick it up.' : 'Click a shoe, walk near it, then press E to pick it up.'}</p>
						{placementSaveStatus === 'saving' && <p className="mt-1 text-amber-200">Saving placement…</p>}
						{placementSaveStatus === 'saved' && <p className="mt-1 text-emerald-300">Placement saved.</p>}
						{placementSaveStatus === 'error' && <p className="mt-1 text-red-300">Could not save placement. Try again.</p>}
					</div>
				)}
				{showroomSetupRequired && !isSceneLoading && (
					<p className="pointer-events-none absolute bottom-5 left-4 z-30 max-w-sm rounded-lg border border-amber-300 bg-stone-950/95 px-4 py-3 text-xs text-amber-100 shadow-lg">
						Showroom editing needs the latest database migration. Ask the deployer to run <code>php artisan migrate --force</code>.
					</p>
				)}
				{seatedSeatKey !== null && !isGameOpen && (
					<div className="pointer-events-auto absolute bottom-6 left-1/2 z-30 flex -translate-x-1/2 flex-wrap items-center justify-center gap-2 rounded-md border border-white/20 bg-stone-950/90 px-3 py-2 text-center text-xs text-stone-100 shadow-lg">
						<span className="mr-1">You&apos;re seated in the lounge.</span>
						<button type="button" onClick={() => setIsGameOpen(true)} className="min-h-11 rounded-md bg-amber-200 px-3 font-semibold text-stone-950 hover:bg-amber-100">Open XOX</button>
						<button type="button" onClick={standUp} className="min-h-11 rounded-md border border-stone-600 px-3 hover:bg-stone-800">Stand up</button>
					</div>
				)}

				{isStandalonePage && canEditShowroom && isEditMode && (
					<div className="pointer-events-auto absolute right-3 top-16 z-30 w-[min(92vw,320px)] rounded-xl border border-stone-700 bg-stone-950/95 p-3 text-stone-100 shadow-xl sm:right-4 sm:top-16">
						<p className="text-xs font-semibold uppercase tracking-[0.16em] text-stone-400">Shelf editor</p>
						<p className="mt-1 text-xs text-stone-300">Click a shoe, press E to pick up, then walk to a shelf and press E to place. You can also use these controls.</p>
						<label className="mt-3 block text-xs font-medium text-stone-300" htmlFor="showroom-product-select">Shoe</label>
						<select
							id="showroom-product-select"
							value={selectedAssignment?.productId ?? ''}
							onChange={(event) => {
								const productId = Number(event.target.value);
								setSelectedProductId(Number.isFinite(productId) ? productId : null);
								setSelectedTargetSlotKey('');
							}}
							className="mt-1 min-h-11 w-full rounded-md border border-stone-600 bg-stone-900 px-3 text-sm text-stone-100"
						>
							{placementAssignments.map((assignment) => {
								const shoe = shoes.find(item => item.id === assignment.productId);
								return <option key={assignment.productId} value={assignment.productId}>{shoe?.name ?? `Shoe ${assignment.productId}`} ({assignment.slotKey})</option>;
							})}
						</select>
						<label className="mt-3 block text-xs font-medium text-stone-300" htmlFor="showroom-slot-select">Move to shelf</label>
						<select
							id="showroom-slot-select"
							value={selectedMoveTargetSlotKey}
							onChange={(event) => setSelectedTargetSlotKey(event.target.value)}
							className="mt-1 min-h-11 w-full rounded-md border border-stone-600 bg-stone-900 px-3 text-sm text-stone-100"
						>
							{showroomSlots.map((slot) => <option key={slot.key} value={slot.key}>{slot.key}</option>)}
						</select>
						<button
							type="button"
							disabled={!selectedAssignment || !selectedMoveTargetSlotKey || selectedMoveTargetSlotKey === selectedSourceSlotKey || placementSaveStatus === 'saving'}
							onClick={() => {
								if (selectedAssignment && selectedMoveTargetSlotKey) {
									void savePlacement(selectedAssignment.productId, selectedSourceSlotKey, selectedMoveTargetSlotKey);
								}
							}}
							className="mt-3 min-h-11 w-full rounded-md bg-amber-200 px-3 text-sm font-semibold text-stone-950 hover:bg-amber-100 disabled:cursor-not-allowed disabled:opacity-50"
						>
							{placementSaveStatus === 'saving' ? 'Saving…' : 'Move shoe'}
						</button>
						{placementSaveStatus !== 'idle' && <p className={`mt-2 text-xs ${placementSaveStatus === 'error' ? 'text-red-300' : placementSaveStatus === 'saved' ? 'text-emerald-300' : 'text-stone-300'}`}>{placementSaveStatus === 'error' ? 'Could not save. Try again.' : placementSaveStatus === 'saved' ? 'Saved automatically.' : 'Saving arrangement…'}</p>}
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
						<div className="pointer-events-auto absolute right-3 top-3 z-20 flex flex-wrap justify-end gap-2 sm:right-4">
							<button
								type="button"
								onPointerDown={(event) => event.stopPropagation()}
								onPointerMove={(event) => event.stopPropagation()}
								onPointerUp={(event) => event.stopPropagation()}
								onClick={(event) => {
									event.stopPropagation();
									setIsNightMode((prev) => !prev);
								}}
								className="min-h-11 rounded-md border border-white/20 bg-stone-950/85 px-4 py-2 text-sm font-medium text-stone-100 shadow-sm hover:bg-stone-800"
							>
								{isNightMode ? 'Day Mode' : 'Night Mode'}
							</button>
							{canEditShowroom && (
								<button
									type="button"
									onPointerDown={(event) => event.stopPropagation()}
									onPointerMove={(event) => event.stopPropagation()}
									onPointerUp={(event) => event.stopPropagation()}
									onClick={(event) => {
										event.stopPropagation();
										setIsEditMode((prev) => !prev);
									}}
									className={`min-h-11 rounded-md border px-4 py-2 text-sm font-medium shadow-sm ${isEditMode ? 'border-amber-200 bg-amber-200 text-stone-950 hover:bg-amber-100' : 'border-white/20 bg-stone-950/85 text-stone-100 hover:bg-stone-800'}`}
								>
									{isEditMode ? 'Done editing' : 'Edit showroom'}
								</button>
							)}
						</div>
					</>
				)}

				{isSceneLoading && (
					<div className="pointer-events-none absolute inset-0 z-50 flex items-center justify-center bg-slate-950/80 backdrop-blur-sm">
						<div className="flex flex-col items-center gap-4 text-center text-white">
							<div className="h-14 w-14 animate-spin rounded-full border-4 border-white/25 border-t-white" />
							<div>
								<p className="text-base font-semibold">Preparing showroom</p>
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

				<ShowroomTicTacToe
					key={gameSessionKey}
					ref={xoxGameRef}
					open={isGameOpen && seatedSeatKey !== null}
					onStandUp={standUp}
					onClose={closeGame}
					onBoardChange={updateTableBoard}
				/>
			</div>

			{!isStandalonePage && (
				<div className="mt-4 px-4 text-xs text-gray-500 md:px-8">Walk with WASD or the joystick. Click and drag to look around. Select a shoe to inspect its uploaded views.</div>
			)}
		</section>
		</>
	);
};

export default VirtualShowroom;
