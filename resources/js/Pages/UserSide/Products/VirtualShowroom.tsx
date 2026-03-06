import React, { useEffect, useMemo, useRef, useState } from 'react';
import * as THREE from '../../../../../node_modules/three/build/three.module.js';

interface Product {
	id: number;
	name: string;
	slug?: string;
	brand?: string;
	stock_quantity: number;
	main_image: string;
	hover_image?: string | null;
	gallery_images?: string[];
}

interface VirtualShowroomProps {
	products: Product[];
	isStandalonePage?: boolean;
}

const SEQUENCE_SOURCES = [
	{
		name: 'Golf Shoe 360',
		brand: 'SoleSpace',
		frameCount: 48,
		buildFrameUrl: (index: number) => `/images/360/golf-shoe-360-product-photography-1500w-${String(index).padStart(3, '0')}.jpg`,
	},
	{
		name: 'Tennis Shoe 360',
		brand: 'SoleSpace',
		frameCount: 72,
		buildFrameUrl: (index: number) => `/images/tennis%20360/360-product-photography-tennis-shoe-${String(index).padStart(3, '0')}.jpg`,
	},
] as const;

interface ShoeViewSet {
	id: number;
	name: string;
	slug?: string;
	brand?: string;
	stock: number;
	frames: string[];
}

const VirtualShowroom: React.FC<VirtualShowroomProps> = ({ products, isStandalonePage = false }) => {
	const mountRef = useRef<HTMLDivElement | null>(null);
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
	const [currentIndex, setCurrentIndex] = useState(0);
	const [isDragging, setIsDragging] = useState(false);
	const [isSceneLoading, setIsSceneLoading] = useState(true);
	const [isNightMode, setIsNightMode] = useState(false);
	const [showSwipeHint, setShowSwipeHint] = useState(false);
	const [focusedShoeIndex, setFocusedShoeIndex] = useState<number | null>(null);
	const [isFocusedDragging, setIsFocusedDragging] = useState(false);
	const [showFocusedHint, setShowFocusedHint] = useState(true);
	const lightsOn = isNightMode;

	const shoes = useMemo<ShoeViewSet[]>(() => {
		const totalShoes = Math.max(products.length, SEQUENCE_SOURCES.length);

		return Array.from({ length: totalShoes }, (_, index) => {
			const product = products[index];
			const source = SEQUENCE_SOURCES[index % SEQUENCE_SOURCES.length];
			const frameCount = source.frameCount;

			return {
				id: product?.id ?? index + 1,
				name: product?.name || source.name,
				slug: product?.slug,
				brand: product?.brand || source.brand,
				stock: product?.stock_quantity ?? 20,
				frames: Array.from({ length: frameCount }, (_, frameIndex) => source.buildFrameUrl(frameIndex + 1)),
			};
		});
	}, [products]);

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
		if (focusedShoeIndex === null) {
			focusedIsDraggingRef.current = false;
			focusedPointerIdRef.current = null;
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

		focusedFrameOffsetRef.current = 0;
		focusedFrameIndexRef.current = 0;
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
		setShowFocusedHint(false);
	}, [focusedShoeIndex]);

	useEffect(() => {
		if (focusedShoeIndex === null) return;
		const frames = shoes[focusedShoeIndex]?.frames;
		if (!frames || frames.length === 0) return;

		const preloaders = frames.map((src) => {
			const image = new Image();
			image.decoding = 'async';
			image.loading = 'eager';
			image.src = src;
			return image;
		});

		return () => {
			preloaders.forEach((image) => {
				image.src = '';
			});
		};
	}, [focusedShoeIndex, shoes]);

	const closeFocusedModal = () => {
		setFocusedShoeIndex(null);
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
		focusedIsDraggingRef.current = true;
		setIsFocusedDragging(true);
		setShowFocusedHint(false);
		target.setPointerCapture(pointerId);
	};

	const moveFocusedDrag = (pointerId: number, clientX: number) => {
		if (focusedPointerIdRef.current !== pointerId || !focusedIsDraggingRef.current) return;
		const deltaX = clientX - focusedDragStartXRef.current;
		focusedDragStartXRef.current = clientX;
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
		if (!container || shoes.length === 0) return;
		setIsSceneLoading(true);
		let isDisposed = false;
		const sceneColor = isNightMode ? '#0b1020' : '#e2e8f0';
		const fogFar = isNightMode ? 45 : 70;
		const wallMainColor = '#e7e3da';
		const wallSideColor = '#ece8df';
		const floorColor = '#d6d1c8';
		const ceilingColor = '#efebe2';
		const shelfColor = '#8b6a4a';
		const ambientIntensity = isNightMode ? 0.06 : 1.45;
		const keyLightIntensity = isNightMode ? 0.16 : 1.6;
		const rimLightIntensity = isNightMode ? 0.12 : 1.05;
		const fixtureEmissiveIntensity = lightsOn ? 1.9 : 0;
		const fixturePointIntensity = lightsOn ? 0.95 : 0;
		const fixtureSpotIntensity = lightsOn ? 2.4 : 0;

		const scene = new THREE.Scene();
		scene.background = new THREE.Color(sceneColor);
		scene.fog = new THREE.Fog(sceneColor, 25, fogFar);

		const camera = new THREE.PerspectiveCamera(66, container.clientWidth / container.clientHeight, 0.1, 200);
		camera.position.set(0, 3.2, -3);
		cameraRef.current = camera;

		const renderer = new THREE.WebGLRenderer({ antialias: true, alpha: false });
		renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2.5));
		renderer.setSize(container.clientWidth, container.clientHeight);
		renderer.outputColorSpace = THREE.SRGBColorSpace;
		renderer.toneMapping = THREE.ACESFilmicToneMapping;
		renderer.toneMappingExposure = 1.15;
		renderer.shadowMap.enabled = false;
		renderer.shadowMap.type = THREE.PCFSoftShadowMap;
		container.innerHTML = '';
		container.appendChild(renderer.domElement);

		const ambientLight = new THREE.AmbientLight('#ffffff', ambientIntensity);
		scene.add(ambientLight);

		const keyLight = new THREE.DirectionalLight('#ffffff', keyLightIntensity);
		keyLight.position.set(5, 9, 10);
		keyLight.castShadow = false;
		keyLight.shadow.mapSize.width = 2048;
		keyLight.shadow.mapSize.height = 2048;
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
			new THREE.PlaneGeometry(42, 15),
			new THREE.MeshStandardMaterial({ color: wallMainColor, roughness: 0.95, metalness: 0.05 }),
		);
		backWall.position.set(0, 7.2, -18);
		scene.add(backWall);

		const frontWall = new THREE.Mesh(
			new THREE.PlaneGeometry(42, 15),
			new THREE.MeshStandardMaterial({ color: wallMainColor, roughness: 0.95, metalness: 0.05 }),
		);
		frontWall.position.set(0, 7.2, 12);
		frontWall.rotation.y = Math.PI;
		scene.add(frontWall);

		const leftWall = new THREE.Mesh(
			new THREE.PlaneGeometry(38, 15),
			new THREE.MeshStandardMaterial({ color: wallSideColor, roughness: 0.95, metalness: 0.05 }),
		);
		leftWall.position.set(-12, 7.2, -1.5);
		leftWall.rotation.y = Math.PI / 2;
		scene.add(leftWall);

		const rightWall = leftWall.clone();
		rightWall.position.x = 12;
		rightWall.rotation.y = -Math.PI / 2;
		scene.add(rightWall);

		const ceiling = new THREE.Mesh(
			new THREE.PlaneGeometry(42, 30),
			new THREE.MeshStandardMaterial({ color: ceilingColor, roughness: 0.9, metalness: 0.03, side: THREE.DoubleSide }),
		);
		ceiling.rotation.x = Math.PI / 2;
		ceiling.position.set(0, 14.6, -3);
		scene.add(ceiling);

		const shelfMaterial = new THREE.MeshStandardMaterial({ color: shelfColor, roughness: 0.9, metalness: 0.04 });
		const shelfMeshes: THREE.Mesh[] = [];
		const sideShelfStartZ = -14.25;
		const sideShelfGapZ = 4.5;
		for (const side of [-1, 1]) {
			for (let level = 0; level < 3; level += 1) {
				for (let i = 0; i < 6; i += 1) {
					const shelf = new THREE.Mesh(new THREE.BoxGeometry(3.2, 0.16, 1.25), shelfMaterial);
					const shelfZ = sideShelfStartZ + i * sideShelfGapZ;
					shelf.position.set(side * 9.4, 1.2 + level * 1.85, shelfZ);
					shelf.castShadow = true;
					shelf.receiveShadow = true;
					scene.add(shelf);
					shelfMeshes.push(shelf);
				}
			}
		}

		for (const depthSide of [-1, 1]) {
			for (let level = 0; level < 3; level += 1) {
				for (let i = 0; i < 4; i += 1) {
					const shelf = new THREE.Mesh(new THREE.BoxGeometry(2.8, 0.16, 1.25), shelfMaterial);
					shelf.position.set(-6 + i * 4, 1.2 + level * 1.85, depthSide === -1 ? -16.4 : 10.4);
					shelf.castShadow = true;
					shelf.receiveShadow = true;
					scene.add(shelf);
					shelfMeshes.push(shelf);
				}
			}
		}

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

		const runway = new THREE.Mesh(
			new THREE.BoxGeometry(10, 0.25, 8),
			new THREE.MeshStandardMaterial({ color: isNightMode ? '#7d8695' : '#f8fafc', roughness: 0.4, metalness: 0.2 }),
		);
		runway.position.set(0, 0.13, -3);
		runway.receiveShadow = true;
		runway.castShadow = true;
		scene.add(runway);

		const decorMeshes: THREE.Mesh[] = [];
		const decorMaterials: THREE.Material[] = [];
		const decorTextures: THREE.Texture[] = [];

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

		createPoster(new THREE.Vector3(0, 8.9, -17.7), 0, posterPanelMaterialBack);

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

		createWallFixture(new THREE.Vector3(-11.55, 10.7, -10.2), -Math.PI / 2, new THREE.Vector3(-6.4, 4.2, -10.2));
		createWallFixture(new THREE.Vector3(-11.55, 10.7, -2.2), -Math.PI / 2, new THREE.Vector3(-6.4, 4.2, -2.2));
		createWallFixture(new THREE.Vector3(-11.55, 10.7, 5.8), -Math.PI / 2, new THREE.Vector3(-6.4, 4.2, 5.8));
		createWallFixture(new THREE.Vector3(11.55, 10.7, -10.2), Math.PI / 2, new THREE.Vector3(6.4, 4.2, -10.2));
		createWallFixture(new THREE.Vector3(11.55, 10.7, -2.2), Math.PI / 2, new THREE.Vector3(6.4, 4.2, -2.2));
		createWallFixture(new THREE.Vector3(11.55, 10.7, 5.8), Math.PI / 2, new THREE.Vector3(6.4, 4.2, 5.8));
		createWallFixture(new THREE.Vector3(-4.4, 10.8, -17.55), 0, new THREE.Vector3(-4.2, 4.3, -11.0));
		createWallFixture(new THREE.Vector3(4.4, 10.8, -17.55), 0, new THREE.Vector3(4.2, 4.3, -11.0));
		createWallFixture(new THREE.Vector3(-4.4, 10.8, 11.55), Math.PI, new THREE.Vector3(-4.2, 4.3, 4.6));
		createWallFixture(new THREE.Vector3(4.4, 10.8, 11.55), Math.PI, new THREE.Vector3(4.2, 4.3, 4.6));

		const ceilingFixture = new THREE.Mesh(new THREE.BoxGeometry(0.9, 0.9, 0.55), fixtureMaterial);
		ceilingFixture.position.set(0, 14.15, -2.8);
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
		ceilingTarget.position.set(0, 3.6, -3.0);
		scene.add(ceilingTarget);
		fixtureTargets.push(ceilingTarget);

		const ceilingLight = new THREE.PointLight('#ffe8b3', lightsOn ? 1.05 : 0, 15, 2);
		ceilingLight.position.set(0, 13.75, -2.8);
		scene.add(ceilingLight);
		fixturePointLights.push(ceilingLight);

		const ceilingSpot = new THREE.SpotLight('#ffe8b3', lightsOn ? 2.7 : 0, 26, Math.PI / 8, 0.42, 1.35);
		ceilingSpot.position.set(0, 13.75, -2.8);
		ceilingSpot.target = ceilingTarget;
		scene.add(ceilingSpot);
		fixtureSpotLights.push(ceilingSpot);

		const loader = new THREE.TextureLoader();
		const textureCache = new Map<string, THREE.Texture>();
		const maxAnisotropy = renderer.capabilities.getMaxAnisotropy();
		const allFrameUrls = Array.from(new Set(shoes.flatMap((shoe) => shoe.frames).filter(Boolean)));
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
			texture.minFilter = THREE.LinearFilter;
			texture.magFilter = THREE.LinearFilter;
			texture.anisotropy = Math.min(maxAnisotropy, 16);
			texture.generateMipmaps = false;
			textureCache.set(url, texture);
			return texture;
		};

		allFrameUrls.forEach((url) => {
			getTexture(url);
		});

		const shelfCardMaterials: THREE.MeshBasicMaterial[] = [];
		const shelfCards: THREE.Mesh[] = [];
		const shoeCaseMeshes: THREE.Mesh[] = [];
		const shoeCaseMaterials: THREE.Material[] = [];
		const shoeCaseFrameMaterial = new THREE.MeshStandardMaterial({ color: '#1f2937', roughness: 0.45, metalness: 0.2 });
		const shoeCaseBackMaterial = new THREE.MeshStandardMaterial({
			color: '#111827',
			transparent: true,
			opacity: 0.48,
			roughness: 0.72,
			metalness: 0.08,
		});
		const shoeCaseFrontMaterial = new THREE.MeshStandardMaterial({
			color: '#94a3b8',
			transparent: true,
			opacity: 0.16,
			roughness: 0.06,
			metalness: 0.42,
		});
		shoeCaseMaterials.push(shoeCaseFrameMaterial, shoeCaseBackMaterial, shoeCaseFrontMaterial);

		const createShoeCase = (position: THREE.Vector3, rotationY: number, cardWidth: number, cardHeight: number) => {
			const caseDepth = 0.2;
			const caseWidth = cardWidth + 0.22;
			const caseHeight = cardHeight + 0.2;

			const caseGroup = new THREE.Group();
			caseGroup.position.copy(position);
			caseGroup.position.y += 0.08;
			caseGroup.rotation.y = rotationY;

			const backPanel = new THREE.Mesh(new THREE.PlaneGeometry(caseWidth, caseHeight), shoeCaseBackMaterial);
			backPanel.position.set(0, 0, -caseDepth * 0.5);
			caseGroup.add(backPanel);
			shoeCaseMeshes.push(backPanel);

			const frontPanel = new THREE.Mesh(new THREE.PlaneGeometry(caseWidth * 0.98, caseHeight * 0.96), shoeCaseFrontMaterial);
			frontPanel.position.set(0, 0, caseDepth * 0.5);
			caseGroup.add(frontPanel);
			shoeCaseMeshes.push(frontPanel);

			const frameTop = new THREE.Mesh(new THREE.BoxGeometry(caseWidth, 0.04, caseDepth), shoeCaseFrameMaterial);
			frameTop.position.set(0, caseHeight * 0.5, 0);
			caseGroup.add(frameTop);
			shoeCaseMeshes.push(frameTop);

			const frameBottom = new THREE.Mesh(new THREE.BoxGeometry(caseWidth, 0.04, caseDepth), shoeCaseFrameMaterial);
			frameBottom.position.set(0, -caseHeight * 0.5, 0);
			caseGroup.add(frameBottom);
			shoeCaseMeshes.push(frameBottom);

			const frameLeft = new THREE.Mesh(new THREE.BoxGeometry(0.04, caseHeight, caseDepth), shoeCaseFrameMaterial);
			frameLeft.position.set(-caseWidth * 0.5, 0, 0);
			caseGroup.add(frameLeft);
			shoeCaseMeshes.push(frameLeft);

			const frameRight = new THREE.Mesh(new THREE.BoxGeometry(0.04, caseHeight, caseDepth), shoeCaseFrameMaterial);
			frameRight.position.set(caseWidth * 0.5, 0, 0);
			caseGroup.add(frameRight);
			shoeCaseMeshes.push(frameRight);

			scene.add(caseGroup);
		};

		const animatedShelfCards: Array<{
			material: THREE.MeshBasicMaterial;
			shoeIdx: number;
			frameOffset: number;
			speed: number;
			lastFrameIdx: number;
		}> = [];
		for (const side of [-1, 1]) {
			for (let level = 0; level < 3; level += 1) {
				for (let i = 0; i < 6; i += 1) {
					const shoeIdx = (i + level * 2 + (side === -1 ? 0 : 1)) % shoes.length;
					const frameIdx = 0;

					const material = new THREE.MeshBasicMaterial({
						map: getTexture(shoes[shoeIdx].frames[frameIdx]),
						transparent: true,
						alphaTest: 0.02,
						depthWrite: false,
						side: THREE.DoubleSide,
					});

					const card = new THREE.Mesh(new THREE.PlaneGeometry(2.0, 1.25), material);
					const cardY = 1.95 + level * 1.85;
					const cardZ = sideShelfStartZ + i * sideShelfGapZ;
					card.position.set(side * 9.05, cardY, cardZ);
					card.rotation.y = side === -1 ? Math.PI / 2 : -Math.PI / 2;
					createShoeCase(card.position.clone(), card.rotation.y, 2.0, 1.25);
					const insetOffset = 0.08;
					card.position.x -= Math.sin(card.rotation.y) * insetOffset;
					card.position.z -= Math.cos(card.rotation.y) * insetOffset;
					card.userData.baseY = cardY;
					card.userData.shoeIdx = shoeIdx;
					card.castShadow = false;
					scene.add(card);

					shelfCardMaterials.push(material);
					shelfCards.push(card);
					animatedShelfCards.push({
						material,
						shoeIdx,
						frameOffset: (i * 5 + level * 9 + (side === -1 ? 0 : 13)) % shoes[shoeIdx].frames.length,
						speed: 20,
						lastFrameIdx: frameIdx,
					});
				}
			}
		}

		for (const depthSide of [-1, 1]) {
			for (let level = 0; level < 3; level += 1) {
				for (let i = 0; i < 4; i += 1) {
					const shoeIdx = (i + level * 2 + (depthSide === -1 ? 0 : 1)) % shoes.length;
					const material = new THREE.MeshBasicMaterial({
						map: getTexture(shoes[shoeIdx].frames[0]),
						transparent: true,
						alphaTest: 0.02,
						depthWrite: false,
						side: THREE.DoubleSide,
					});

					const card = new THREE.Mesh(new THREE.PlaneGeometry(1.85, 1.12), material);
					const cardY = 1.95 + level * 1.85;
					card.position.set(-6 + i * 4, cardY, depthSide === -1 ? -15.85 : 9.85);
					card.rotation.y = depthSide === -1 ? 0 : Math.PI;
					createShoeCase(card.position.clone(), card.rotation.y, 1.85, 1.12);
					const insetOffset = 0.08;
					card.position.x -= Math.sin(card.rotation.y) * insetOffset;
					card.position.z -= Math.cos(card.rotation.y) * insetOffset;
					card.userData.baseY = cardY;
					card.userData.shoeIdx = shoeIdx;
					card.castShadow = false;
					scene.add(card);

					shelfCardMaterials.push(material);
					shelfCards.push(card);
					animatedShelfCards.push({
						material,
						shoeIdx,
						frameOffset: (i * 7 + level * 11 + (depthSide === -1 ? 0 : 17)) % shoes[shoeIdx].frames.length,
						speed: 20,
						lastFrameIdx: 0,
					});
				}
			}
		}
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

		const animate = () => {
			const elapsed = clock.getElapsedTime();
			const isModalOpen = focusedShoeIndexRef.current !== null;

			if (isModalOpen) {
				swipeGuideGroup.visible = false;
				focusGroup.visible = false;
				rafId = requestAnimationFrame(animate);
				return;
			}

			camera.position.x = 0;
			camera.position.z = -3;
			camera.position.y = 3.2;

			cameraYawRef.current += (targetCameraYawRef.current - cameraYawRef.current) * 0.18;
			cameraPitchRef.current += (targetCameraPitchRef.current - cameraPitchRef.current) * 0.18;
			const lookDistance = 14;
			const lookX = Math.sin(cameraYawRef.current) * Math.cos(cameraPitchRef.current) * lookDistance;
			const lookY = camera.position.y + Math.sin(cameraPitchRef.current) * lookDistance;
			const lookZ = camera.position.z - Math.cos(cameraYawRef.current) * Math.cos(cameraPitchRef.current) * lookDistance;
			camera.lookAt(lookX, lookY, lookZ);

			shelfCards.forEach((card) => {
				const baseY = (card.userData.baseY as number) ?? card.position.y;
				card.position.y += (baseY - card.position.y) * 0.08;
			});

			animatedShelfCards.forEach((entry) => {
				const frames = shoes[entry.shoeIdx]?.frames;
				if (!frames || frames.length === 0) return;

				const nextFrameIdx = Math.floor(elapsed * entry.speed + entry.frameOffset) % frames.length;
				if (nextFrameIdx === entry.lastFrameIdx) return;

				entry.lastFrameIdx = nextFrameIdx;
				entry.material.map = getTexture(frames[nextFrameIdx]);
				entry.material.needsUpdate = true;
			});

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

		window.addEventListener('resize', handleResize);

		return () => {
			isDisposed = true;
			if (focusedRafIdRef.current !== null) {
				cancelAnimationFrame(focusedRafIdRef.current);
				focusedRafIdRef.current = null;
			}
			cameraRef.current = null;
			shelfCardPickablesRef.current = [];
			window.removeEventListener('resize', handleResize);
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
			shelfMeshes.forEach((mesh) => mesh.geometry.dispose());
			fixtureMeshes.forEach((mesh) => mesh.geometry.dispose());
			fixtureMaterial.dispose();
			shelfCards.forEach((card) => (card.geometry as THREE.BufferGeometry).dispose());
			shelfCardMaterials.forEach((material) => material.dispose());
			shoeCaseMeshes.forEach((mesh) => mesh.geometry.dispose());
			shoeCaseMaterials.forEach((material) => material.dispose());
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
			fixturePointLights.forEach((light) => light.dispose());
			fixtureSpotLights.forEach((light) => light.dispose());
			fixtureTargets.forEach((target) => scene.remove(target));

			textureCache.forEach((texture) => texture.dispose());

			renderer.dispose();
			if (renderer.domElement && container.contains(renderer.domElement)) {
				container.removeChild(renderer.domElement);
			}
		};
	}, [shoes, isNightMode, lightsOn]);

	const goToPreviousShoe = () => {
		if (shoes.length === 0) return;
		setCurrentIndex((prev) => (prev === 0 ? shoes.length - 1 : prev - 1));
	};

	const goToNextShoe = () => {
		if (shoes.length === 0) return;
		setCurrentIndex((prev) => (prev + 1) % shoes.length);
	};

	const handlePointerDown = (clientX: number, clientY: number) => {
		dragStartXRef.current = clientX;
		dragStartYRef.current = clientY;
		pointerMoveDistanceRef.current = 0;
		isDraggingRef.current = true;
		setIsDragging(true);
		setShowSwipeHint(false);
	};

	const handlePointerMove = (clientX: number, clientY: number) => {
		if (!isDraggingRef.current) return;

		const deltaX = clientX - dragStartXRef.current;
		const deltaY = clientY - dragStartYRef.current;
		pointerMoveDistanceRef.current += Math.abs(deltaX) + Math.abs(deltaY);
		dragStartXRef.current = clientX;
		dragStartYRef.current = clientY;

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
		if (
			pointerMoveDistanceRef.current < 8 &&
			focusedShoeIndexRef.current === null &&
			typeof clientX === 'number' &&
			typeof clientY === 'number'
		) {
			const pickedShoeIdx = pickShoeAtPointer(clientX, clientY);
			if (pickedShoeIdx !== null) {
				focusedFrameOffsetRef.current = 0;
				focusedFrameIndexRef.current = -1;
				setFocusedShoeIndex(pickedShoeIdx);
			}
		}

		isDraggingRef.current = false;
		setIsDragging(false);
		pointerMoveDistanceRef.current = 0;
	};

	if (shoes.length === 0) {
		return (
			<div className="rounded-xl border border-gray-200 bg-gray-50 p-8 text-center">
				<p className="text-sm text-gray-600">No product images are available for the virtual showroom yet.</p>
			</div>
		);
	}

	const activeShoe = shoes[currentIndex];
	const focusedShoe = focusedShoeIndex !== null ? shoes[focusedShoeIndex] : null;
	const focusedInitialFrameSrc =
		focusedShoe && focusedShoe.frames.length > 0
			? focusedShoe.frames[Math.max(0, Math.min(focusedShoe.frames.length - 1, focusedFrameIndexRef.current >= 0 ? focusedFrameIndexRef.current : 0))]
			: null;

	return (
		<section className={isStandalonePage
			? 'h-screen w-screen bg-white'
			: 'relative left-1/2 right-1/2 -mx-[50vw] w-screen border-y border-gray-200 bg-white py-4 md:py-6'}>
			{!isStandalonePage && (
				<div className="mb-4 flex flex-col gap-2 px-4 md:flex-row md:items-center md:justify-between md:px-8">
					<div>
						<h3 className="text-xl font-semibold text-gray-900">Virtual Showroom</h3>
						<p className="text-sm text-gray-500">Swipe to orbit 360° and view top or bottom angles.</p>
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
				className={`relative ${isStandalonePage ? 'h-screen min-h-0' : 'h-[calc(100vh-180px)] min-h-170'} w-full touch-none overflow-hidden ${isStandalonePage ? '' : 'border-y border-gray-200'} bg-slate-200 ${isDragging ? 'cursor-grabbing' : focusedShoeIndex !== null ? 'cursor-ew-resize' : 'cursor-grab'}`}
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

				{focusedShoeIndex !== null && focusedShoe && (
					<div
						className="absolute inset-0 z-30 flex items-center justify-center bg-black/80 p-4"
						onPointerDown={(event) => {
							if (event.target === event.currentTarget) {
								closeFocusedModal();
							}
						}}
					>
						<div
							className="flex h-[88vh] w-full max-w-5xl flex-col overflow-hidden rounded-lg bg-white shadow-2xl"
							onPointerDown={(event) => {
								event.stopPropagation();
							}}
						>
							<div className="flex items-center justify-between border-b border-gray-200 px-6 py-4">
								<div>
									<h2 className="text-2xl font-bold text-black">{focusedShoe.name}</h2>
									<p className="mt-1 text-sm text-gray-600">360 Interactive View - Drag to rotate</p>
								</div>
								<button
									type="button"
									onClick={closeFocusedModal}
									className="text-gray-500 transition-colors hover:text-gray-700"
									aria-label="Close showroom"
								>
									<svg className="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
										<path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
									</svg>
								</button>
							</div>

							<div
								className={`relative flex flex-1 touch-none items-center justify-center overflow-hidden bg-white ${isFocusedDragging ? 'cursor-grabbing' : 'cursor-grab'}`}
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
								{focusedInitialFrameSrc && (
									<img
										ref={focusedImageRef}
										src={focusedInitialFrameSrc}
										alt={`${focusedShoe.name} 360 view`}
										className="pointer-events-none h-full w-full select-none object-contain"
										draggable={false}
										loading="eager"
										decoding="async"
									/>
								)}

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
					<button
						type="button"
						onPointerDown={(event) => event.stopPropagation()}
						onPointerMove={(event) => event.stopPropagation()}
						onPointerUp={(event) => event.stopPropagation()}
						onClick={(event) => {
							event.stopPropagation();
							setIsNightMode((prev) => !prev);
						}}
						className="pointer-events-auto absolute right-3 top-3 z-20 rounded-md border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50"
					>
						{isNightMode ? 'Day Mode' : 'Night Mode'}
					</button>
				)}

				{isSceneLoading && (
					<div className="pointer-events-none absolute inset-0 z-10 flex items-center justify-center bg-black/65">
						<div className="h-14 w-14 animate-spin rounded-full border-4 border-white/35 border-t-white" />
					</div>
				)}

				{!isStandalonePage && (
					<div className="pointer-events-none absolute bottom-3 left-3 rounded-md bg-white/85 px-3 py-2 text-xs text-gray-700 shadow-sm">
						<p className="font-semibold text-gray-900">{activeShoe.name}</p>
						<p>{activeShoe.brand || 'SoleSpace'} • {activeShoe.stock > 0 ? `${activeShoe.stock} in stock` : 'Out of stock'}</p>
						<p className="text-[10px] text-gray-500">Using 360 image sequence frames from your folder.</p>
					</div>
				)}
			</div>

			{!isStandalonePage && (
				<div className="mt-4 px-4 text-xs text-gray-500 md:px-8">Swipe horizontally and vertically for full 360 shelf view from the center POV.</div>
			)}
		</section>
	);
};

export default VirtualShowroom;
