import * as THREE from 'three';
import { RoomEnvironment } from 'three/addons/environments/RoomEnvironment.js';
import { Reflector } from 'three/addons/objects/Reflector.js';
import { RectAreaLightUniformsLib } from 'three/addons/lights/RectAreaLightUniformsLib.js';
import { RoundedBoxGeometry } from 'three/addons/geometries/RoundedBoxGeometry.js';
import { getShowroomLayout, SHOWROOM_BOUNDS } from './showroomLayout';

RectAreaLightUniformsLib.init();

interface ShowroomPromptSprite {
	sprite: THREE.Sprite;
	texture: THREE.CanvasTexture;
	material: THREE.SpriteMaterial;
}

export interface ShowroomWallArt {
	left: string | null;
	right: string | null;
}

type WallArtSide = keyof ShowroomWallArt;

const roundedRect = (
	context: CanvasRenderingContext2D,
	x: number,
	y: number,
	width: number,
	height: number,
	radius: number,
) => {
	const safeRadius = Math.min(radius, width / 2, height / 2);
	context.beginPath();
	context.moveTo(x + safeRadius, y);
	context.lineTo(x + width - safeRadius, y);
	context.quadraticCurveTo(x + width, y, x + width, y + safeRadius);
	context.lineTo(x + width, y + height - safeRadius);
	context.quadraticCurveTo(x + width, y + height, x + width - safeRadius, y + height);
	context.lineTo(x + safeRadius, y + height);
	context.quadraticCurveTo(x, y + height, x, y + height - safeRadius);
	context.lineTo(x, y + safeRadius);
	context.quadraticCurveTo(x, y, x + safeRadius, y);
	context.closePath();
};

export const createShowroomPromptSprite = (keyLabel: string, action = ''): ShowroomPromptSprite => {
	const canvas = document.createElement('canvas');
	canvas.width = 640;
	canvas.height = 176;
	const context = canvas.getContext('2d')!;

	context.shadowColor = 'rgba(0, 0, 0, 0.36)';
	context.shadowBlur = 20;
	context.shadowOffsetY = 10;
	context.fillStyle = 'rgba(22, 20, 18, 0.96)';
	roundedRect(context, 16, 16, 608, 144, 24);
	context.fill();
	context.shadowColor = 'transparent';
	context.strokeStyle = 'rgba(205, 174, 125, 0.88)';
	context.lineWidth = 4;
	context.stroke();

	if (action) {
		context.fillStyle = 'rgba(111, 84, 58, 0.94)';
		roundedRect(context, 34, 34, 142, 108, 18);
		context.fill();
		context.strokeStyle = 'rgba(238, 229, 212, 0.34)';
		context.lineWidth = 2;
		context.stroke();
		context.fillStyle = '#f1e8d9';
		context.font = '700 62px Arial';
		context.textAlign = 'center';
		context.textBaseline = 'middle';
		context.fillText(keyLabel.toUpperCase(), 105, 89);
		context.fillStyle = '#f1e8d9';
		context.font = '700 48px Arial';
		context.fillText(action.toUpperCase(), 397, 91);
	} else {
		context.fillStyle = '#f1e8d9';
		context.font = '700 54px Arial';
		context.textAlign = 'center';
		context.textBaseline = 'middle';
		context.fillText(keyLabel.toUpperCase(), 320, 90);
	}

	const texture = new THREE.CanvasTexture(canvas);
	texture.colorSpace = THREE.SRGBColorSpace;
	const material = new THREE.SpriteMaterial({
		map: texture,
		transparent: true,
		depthTest: true,
		depthWrite: false,
		opacity: 0.96,
	});
	const sprite = new THREE.Sprite(material);
	sprite.renderOrder = 2;

	return { sprite, texture, material };
};

/** Physical fixtures and their product positions share one layout in both lighting modes. */
export function createShowroomScene(
	renderer: THREE.WebGLRenderer,
	capacity: number,
	night: boolean,
	lowPower: boolean,
	productCount: number,
	shopName = '',
	enableSlotEditing = false,
	wallArt: ShowroomWallArt = { left: null, right: null },
) {
	const scene = new THREE.Scene();
	const layout = getShowroomLayout(capacity);
	const textures: THREE.Texture[] = [];
	const geometries = new Set<THREE.BufferGeometry>();
	const materials = new Set<THREE.Material>();
	const lights: THREE.Light[] = [];
	scene.background = new THREE.Color(night ? '#191b20' : '#d8d3c9');
	scene.fog = new THREE.Fog(scene.background, 48, 100);
	const environment = new RoomEnvironment();
	const pmrem = new THREE.PMREMGenerator(renderer);
	const environmentTarget = pmrem.fromScene(environment, 0.04);
	scene.environment = environmentTarget.texture;
	scene.environmentIntensity = night ? 0.12 : 0.32;
	environment.dispose();
	pmrem.dispose();

	let disposed = false;
	let finishMaterials: () => void;
	const ready = new Promise<void>(resolve => { finishMaterials = resolve; });
	const manager = new THREE.LoadingManager(() => finishMaterials());
	const loader = new THREE.TextureLoader(manager);
	const wallArtTextures = new Map<WallArtSide, THREE.Texture>();
	const wallArtMaterials = new Map<WallArtSide, THREE.MeshBasicMaterial>();
	const wallArtImageGeometry = new THREE.PlaneGeometry(10.4, 5.85);
	geometries.add(wallArtImageGeometry);

	const createWallArtPlaceholder = (side: WallArtSide) => {
		const canvas = document.createElement('canvas');
		canvas.width = 1024;
		canvas.height = 576;
		const context = canvas.getContext('2d')!;
		context.fillStyle = '#171411';
		context.fillRect(0, 0, canvas.width, canvas.height);
		context.strokeStyle = '#b89a6b';
		context.lineWidth = 10;
		context.strokeRect(24, 24, canvas.width - 48, canvas.height - 48);
		context.textAlign = 'center';
		context.fillStyle = '#f1e8d9';
		context.font = '700 44px Arial';
		context.fillText(`${side.toUpperCase()} WALL`, canvas.width / 2, 250);
		context.fillStyle = '#c6ad7f';
		context.font = '24px Arial';
		context.fillText('UPLOAD YOUR STORY', canvas.width / 2, 312);
		const texture = new THREE.CanvasTexture(canvas);
		texture.colorSpace = THREE.SRGBColorSpace;
		textures.push(texture);
		return texture;
	};

	const createWallArtTexture = (side: WallArtSide, url: string | null) => {
		const previous = wallArtTextures.get(side);
		if (previous) previous.dispose();
		if (!url) {
			const placeholder = createWallArtPlaceholder(side);
			wallArtTextures.set(side, placeholder);
			return placeholder;
		}

		const texture = loader.load(url, (loaded) => {
			if (disposed) {
				loaded.dispose();
				return;
			}
			const image = loaded.image as HTMLImageElement;
			const canvas = document.createElement('canvas');
			canvas.width = lowPower ? 768 : 1024;
			canvas.height = Math.round(canvas.width * 9 / 16);
			const context = canvas.getContext('2d');
			if (context && image.width && image.height) {
				const scale = Math.max(canvas.width / image.width, canvas.height / image.height);
				const width = image.width * scale;
				const height = image.height * scale;
				context.drawImage(image, (canvas.width - width) / 2, (canvas.height - height) / 2, width, height);
				loaded.image = canvas;
				loaded.needsUpdate = true;
			}
		});
		texture.colorSpace = THREE.SRGBColorSpace;
		texture.minFilter = THREE.LinearMipmapLinearFilter;
		texture.magFilter = THREE.LinearFilter;
		texture.anisotropy = Math.min(renderer.capabilities.getMaxAnisotropy(), lowPower ? 4 : 8);
		texture.generateMipmaps = true;
		textures.push(texture);
		wallArtTextures.set(side, texture);
		return texture;
	};

	const loadMap = (name: string, color = false, repeatX = 1, repeatY = 1) => {
		const texture = loader.load(`/images/SHOWROOM/materials/${name}.jpg`, loaded => {
			if (disposed) loaded.dispose();
		});
		texture.colorSpace = color ? THREE.SRGBColorSpace : THREE.NoColorSpace;
		texture.wrapS = texture.wrapT = THREE.RepeatWrapping;
		texture.repeat.set(repeatX, repeatY);
		texture.anisotropy = Math.min(renderer.capabilities.getMaxAnisotropy(), lowPower ? 4 : 8);
		textures.push(texture);
		return texture;
	};
	const woodMap = loadMap('wood-diff', true);
	const woodNormal = loadMap('wood-nor_gl');
	const woodRoughness = loadMap('wood-rough');
	const plasterMap = loadMap('plaster-diff', true, 2, 2);
	const plasterNormal = loadMap('plaster-nor_gl', false, 2, 2);
	const plasterRoughness = loadMap('plaster-rough', false, 2, 2);
	const floorMap = loadMap('floor-diff', true, 5.5, 6.25);
	const floorNormal = loadMap('floor-nor_gl', false, 5.5, 6.25);
	const floorRoughness = loadMap('floor-rough', false, 5.5, 6.25);
	const material = (parameters: THREE.MeshStandardMaterialParameters) => {
		const result = new THREE.MeshStandardMaterial(parameters);
		materials.add(result);
		return result;
	};
	const stone = material({ color: '#c5b9a4', roughness: 0.36, map: plasterMap, normalMap: plasterNormal, normalScale: new THREE.Vector2(0.1, 0.1) });
	const plaster = material({ color: '#aaa397', roughness: 0.94, map: plasterMap, normalMap: plasterNormal, roughnessMap: plasterRoughness, normalScale: new THREE.Vector2(0.3, 0.3) });
	const walnut = material({ color: '#78614a', roughness: 0.55, map: woodMap, normalMap: woodNormal, roughnessMap: woodRoughness, normalScale: new THREE.Vector2(0.15, 0.15) });
	const black = material({ color: '#20211f', roughness: 0.48, metalness: 0.48 });
	const brass = material({ color: '#a18a61', roughness: 0.38, metalness: 0.8 });
	const recess = material({ color: '#292b28', roughness: 0.92 });
	const linen = material({ color: '#b4a68e', roughness: 1, normalMap: plasterNormal, normalScale: new THREE.Vector2(0.2, 0.2) });
	const rug = material({ color: '#414c46', roughness: 1, normalMap: plasterNormal, normalScale: new THREE.Vector2(0.6, 0.6) });
	const glow = material({ color: '#fff0d3', emissive: '#ffe0ae', emissiveIntensity: night ? 3 : 1.8, roughness: 0.5 });
	const glass = material({ color: '#bac5bf', transparent: true, opacity: 0.12, roughness: 0.12, metalness: 0.15, depthWrite: false });

	const boxGeometry = new THREE.BoxGeometry(1, 1, 1);
	geometries.add(boxGeometry);
	const batches = new Map<string, { material: THREE.Material; shadow: boolean; matrices: THREE.Matrix4[] }>();
	const transform = new THREE.Object3D();
	const box = (mat: THREE.Material, x: number, y: number, z: number, width: number, height: number, depth: number, rotation = 0, shadow = true) => {
		transform.position.set(x, y, z);
		transform.rotation.set(0, rotation, 0);
		transform.scale.set(width, height, depth);
		transform.updateMatrix();
		const key = `${mat.id}:${shadow}`;
		const batch = batches.get(key) ?? { material: mat, shadow, matrices: [] };
		batch.matrices.push(transform.matrix.clone());
		batches.set(key, batch);
	};
	const mesh = (geometry: THREE.BufferGeometry, mat: THREE.Material, x: number, y: number, z: number) => {
		geometries.add(geometry);
		materials.add(mat);
		const object = new THREE.Mesh(geometry, mat);
		object.position.set(x, y, z);
		object.castShadow = true;
		object.receiveShadow = true;
		scene.add(object);
		return object;
	};
	const wallArtCenter: [number, number, number] = [0, 3.45, 23.62];
	const wallArtPositions: Record<WallArtSide, number> = { left: -12.5, right: 12.5 };
	for (const key of ['left', 'right'] as const) {
		const x = wallArtPositions[key];
		box(black, x, wallArtCenter[1], 23.8, 10.8, 6.25, 0.32, 0, false);
		box(brass, x, wallArtCenter[1] + 3.02, wallArtCenter[2], 10.85, 0.14, 0.18, 0, false);
		box(brass, x, wallArtCenter[1] - 3.02, wallArtCenter[2], 10.85, 0.14, 0.18, 0, false);
		box(brass, x - 5.36, wallArtCenter[1], wallArtCenter[2], 0.14, 6.18, 0.18, 0, false);
		box(brass, x + 5.36, wallArtCenter[1], wallArtCenter[2], 0.14, 6.18, 0.18, 0, false);
		const imageMaterial = new THREE.MeshBasicMaterial({
			map: createWallArtTexture(key, wallArt[key]),
			side: THREE.DoubleSide,
		});
		materials.add(imageMaterial);
		wallArtMaterials.set(key, imageMaterial);
		const image = new THREE.Mesh(wallArtImageGeometry, imageMaterial);
		image.position.set(x, wallArtCenter[1], wallArtCenter[2]);
		image.rotation.y = Math.PI;
		image.castShadow = false;
		image.receiveShadow = false;
		scene.add(image);
	}
	const sign = (title: string, subtitle: string, x: number, y: number, z: number, width: number, rotation = 0) => {
		const canvas = document.createElement('canvas');
		canvas.width = 1024;
		canvas.height = 256;
		const ctx = canvas.getContext('2d')!;
		ctx.fillStyle = '#252623';
		ctx.fillRect(0, 0, 1024, 256);
		ctx.textAlign = 'center';
		ctx.fillStyle = '#eee5d4';
		ctx.font = '500 62px Arial';
		ctx.fillText(title, 512, 115);
		ctx.fillStyle = '#c6ad7f';
		ctx.font = '24px Arial';
		ctx.fillText(subtitle, 512, 177);
		const texture = new THREE.CanvasTexture(canvas);
		texture.colorSpace = THREE.SRGBColorSpace;
		textures.push(texture);
		const panel = mesh(new THREE.PlaneGeometry(width, width / 4), new THREE.MeshBasicMaterial({ map: texture }), x, y, z);
		panel.rotation.y = rotation;
		panel.castShadow = false;
	};
	const seatPromptSprites: Array<{ key: string; sprite: THREE.Sprite; baseY: number }> = [];

	// A lower coffered ceiling frames the galleries instead of reading as a warehouse roof.
	const floorMaterial = material({ color: '#c9c6be', map: floorMap, normalMap: floorNormal, roughnessMap: floorRoughness, roughness: 0.48, normalScale: new THREE.Vector2(0.2, 0.2) });
	const floor = mesh(new THREE.PlaneGeometry(44, 50), floorMaterial, 0, -0.008, -1);
	floor.rotation.x = -Math.PI / 2;
	floor.castShadow = false;
	box(plaster, 0, 3.3, SHOWROOM_BOUNDS.back, 44, 6.6, 0.3, 0, false);
	box(plaster, 0, 3.3, SHOWROOM_BOUNDS.front, 44, 6.6, 0.3, 0, false);
	for (const side of [-1, 1]) {
		box(plaster, side * 22, 3.3, -1, 0.3, 6.6, 50, 0, false);
		box(walnut, side * 21.78, 0.22, -1, 0.12, 0.4, 49.5);
		for (const z of [-21, -10, 2, 14]) {
			box(plaster, side * 15, 3.1, z, 0.42, 6.2, 0.42);
			box(brass, side * 15, 0.35, z, 0.46, 0.7, 0.46);
		}
	}
	box(plaster, 0, 6.7, -1, 44, 0.2, 50, 0, false);
	box(recess, 0, 6.57, -1, 10, 0.1, 47, 0, false);
	for (const x of [-5, 5]) {
		box(walnut, x, 6.35, -1, 0.16, 0.5, 47, 0, false);
		box(glow, x * 0.98, 6.53, -1, 0.045, 0.04, 46, 0, false);
	}
	for (const z of [-20, -6, 8]) {
		box(walnut, 0, 6.39, z, 10, 0.4, 0.22, 0, false);
	}
	for (const x of [-3.5, 3.5]) box(brass, x, 0.006, 2, 0.025, 0.009, 35);
	box(black, 0, 3.2, 23.8, 11, 6.4, 0.12);
	box(walnut, 0, 5.5, -25.65, 28, 1.7, 0.25);
	const displayShopName = shopName.trim().slice(0, 36) || 'The Gallery';
	sign(displayShopName, 'THE SNEAKER GALLERY', 0, 5.55, -25.45, 7);
	sign('WELCOME TO ' + displayShopName, 'EXPLORE / DISCOVER / COLLECT', 0, 4.8, 23.7, 8, Math.PI);
	sign('01 / MAIN GALLERY', 'CURATED EVERYDAY ICONS', -21.7, 5.65, 2, 5, Math.PI / 2);
	sign('02 / THE ARCHIVE', 'CRAFT / CULTURE / COLLECTORS', 21.7, 5.65, -8, 5, -Math.PI / 2);

	for (const [bayIndex, bay] of layout.bays.entries()) {
		const cabinet = bayIndex >= 14 ? black : bayIndex % 3 === 0 ? stone : walnut;
		const sin = Math.sin(bay.rotationY), cos = Math.cos(bay.rotationY);
		const part = (mat: THREE.Material, x: number, y: number, z: number, w: number, h: number, d: number) =>
			box(mat, bay.x + cos * x + sin * z, y, bay.z - sin * x + cos * z, w, h, d, bay.rotationY);
		part(bayIndex < 7 ? plaster : recess, 0, 2.45, -0.57, 5.6, 4.8, 0.16);
		part(cabinet, 0, 0.3, 0, 5.6, 0.6, 1.4);
		for (const x of [-2.75, 0, 2.75]) part(cabinet, x, 2.55, 0, 0.12, 4.5, 1.4);
		part(cabinet, 0, 4.85, 0, 5.6, 0.18, 1.4);
		for (let row = 0; row < 3; row++) {
			const y = 0.8 + row * 1.2;
			part(bayIndex >= 14 ? walnut : stone, 0, y, 0, 5.4, 0.1, 1.3);
			part(brass, 0, y, 0.66, 5.4, 0.055, 0.025);
			part(glow, 0, y + 1.06, -0.4, 5.2, 0.035, 0.06);
		}
	}
	for (const island of layout.islands) {
		box(black, island.x, 0.12, island.z, 6.1, 0.24, 2.9);
		box(island.x < 0 ? walnut : black, island.x, 0.65, island.z, 6.4, 0.9, 3.2);
		mesh(new RoundedBoxGeometry(6.6, 0.2, 3.4, 3, 0.09), stone, island.x, 1.1, island.z);
		box(glow, island.x, 0.25, island.z + 1.61, 6.2, 0.035, 0.03);
		box(glow, island.x, 0.25, island.z - 1.61, 6.2, 0.035, 0.03);
		box(glass, island.x, 1.55, island.z, 6.3, 0.7, 0.025);
		for (let x = -3; x <= 3; x += 0.25) box(walnut, island.x + x, 0.65, island.z + 1.61, 0.025, 0.65, 0.035);
	}
	for (const feature of layout.features) {
		mesh(new RoundedBoxGeometry(1.9, feature.height, 1.6, 2, 0.07), stone, feature.x, feature.height / 2, feature.z);
		box(brass, feature.x, 0.08, feature.z, 1.94, 0.1, 1.64);
	}
	sign('THE EDIT', 'SIX PERSPECTIVES. ONE OBSESSION.', 0, 4.1, -20.5, 6);
	// Open wood screen behind the hero collection adds depth without closing the rear gallery.
	for (let x = -8; x <= 8; x += 0.4) box(walnut, x, 2.5, -20.8, 0.08, 5, 0.16);

	const leafMaterial = material({ color: '#3d5140', roughness: 0.85 });
	const leafGeometry = new THREE.SphereGeometry(1, 8, 6);
	for (const [loungeIndex, lounge] of layout.lounges.entries()) {
		const ring = mesh(new THREE.TorusGeometry(2, 0.045, 8, 64), glow, lounge.x, 4.9, lounge.z);
		ring.rotation.x = Math.PI / 2;
		ring.castShadow = false;
		for (const x of [-1.4, 1.4]) box(black, lounge.x + x, 5.72, lounge.z, 0.02, 1.65, 0.02, 0, false);
		box(rug, lounge.x, 0.02, lounge.z, 7.2, 0.035, 6);
		box(walnut, lounge.x, 0.24, lounge.z + 1.8, 5.6, 0.25, 1.8);
		for (const x of [-1.8, 0, 1.8]) {
			mesh(new RoundedBoxGeometry(1.76, 0.4, 1.55, 3, 0.12), linen, lounge.x + x, 0.62, lounge.z + 1.7);
			mesh(new RoundedBoxGeometry(1.76, 1, 0.38, 3, 0.12), linen, lounge.x + x, 1.05, lounge.z + 2.4);
		}
		for (const side of [-1, 1]) mesh(new RoundedBoxGeometry(0.3, 0.72, 1.7, 3, 0.1), linen, lounge.x + side * 2.7, 0.84, lounge.z + 1.8);
		mesh(new THREE.CylinderGeometry(1.15, 1.15, 0.12, 32), stone, lounge.x, 0.73, lounge.z - 0.4);
		mesh(new THREE.CylinderGeometry(0.65, 0.85, 0.65, 24), walnut, lounge.x, 0.36, lounge.z - 0.4);
		const plantX = lounge.x + Math.sign(lounge.x) * 3.5;
		mesh(new THREE.CylinderGeometry(0.6, 0.45, 1, 24), stone, plantX, 0.5, lounge.z);
		mesh(new THREE.CylinderGeometry(0.04, 0.08, 2, 8), walnut, plantX, 1.8, lounge.z);
		for (let i = 0; i < 12; i++) {
			const angle = i * 2.4;
			const leaf = mesh(leafGeometry, leafMaterial, plantX + Math.sin(angle) * 0.45, 1.65 + i * 0.12, lounge.z + Math.cos(angle) * 0.45);
			leaf.scale.set(0.22, 0.1, 0.72);
			leaf.rotation.set(0.4, angle, 0.3);
		}
		const seatPrompt = createShowroomPromptSprite('E', 'PLAY');
		seatPrompt.sprite.position.set(lounge.x, 2.35, lounge.z + 1.55);
		seatPrompt.sprite.scale.set(2.25, 0.62, 1);
		seatPrompt.sprite.visible = false;
		scene.add(seatPrompt.sprite);
		textures.push(seatPrompt.texture);
		materials.add(seatPrompt.material);
		seatPromptSprites.push({
			key: layout.seats[loungeIndex]?.key ?? `lounge-seat-${loungeIndex}`,
			sprite: seatPrompt.sprite,
			baseY: seatPrompt.sprite.position.y,
		});
	}

	// Broad soft sources light merchandise; the darker ceiling keeps attention at eye level.
	const ambient = new THREE.HemisphereLight('#d9e0e9', '#3b3025', night ? 0.16 : 0.4);
	scene.add(ambient);
	lights.push(ambient);
	const key = new THREE.DirectionalLight('#ffe9c9', night ? 0.22 : 1.25);
	key.position.set(-8, 14, 16);
	key.target.position.set(0, 0, -5);
	key.castShadow = true;
	key.shadow.mapSize.set(lowPower ? 1024 : 2048, lowPower ? 1024 : 2048);
	Object.assign(key.shadow.camera, { left: -26, right: 26, top: 30, bottom: -30, near: 0.5, far: 65 });
	key.shadow.normalBias = 0.035;
	key.shadow.bias = -0.0002;
	scene.add(key, key.target);
	lights.push(key);
	for (const x of [-18.5, 18.5]) {
		const area = new THREE.RectAreaLight('#ffe8ca', night ? 2.5 : 3.5, 41, 3);
		area.position.set(x, 3.5, -1);
		area.lookAt(x < 0 ? -22 : 22, 2.4, -1);
		scene.add(area);
		lights.push(area);
	}
	for (const zone of [...layout.islands, { x: 0, z: -18 }]) {
		const spot = new THREE.SpotLight('#ffe3ba', night ? 110 : 160, 18, Math.PI / 4, 0.95, 2);
		spot.position.set(zone.x, 6.05, zone.z);
		spot.target.position.set(zone.x, 0.8, zone.z);
		scene.add(spot, spot.target);
		lights.push(spot);
	}
	for (const lounge of layout.lounges) {
		const area = new THREE.RectAreaLight('#ffe1b8', night ? 3 : 4, 3, 3);
		area.position.set(lounge.x, 4.8, lounge.z);
		area.lookAt(lounge.x, 0, lounge.z);
		scene.add(area);
		lights.push(area);
	}
	for (const x of [-18, -8, 8, 18]) {
		box(black, x, 6.3, -1, 0.07, 0.08, 46, 0, false);
		for (let z = -22; z <= 20; z += 6) {
			box(black, x, 6.1, z, 0.22, 0.32, 0.3, 0, false);
			box(glow, x, 5.93, z, 0.18, 0.02, 0.22, 0, false);
		}
	}
	for (const { material: mat, shadow, matrices } of batches.values()) {
		const instances = new THREE.InstancedMesh(boxGeometry, mat, matrices.length);
		matrices.forEach((matrix, i) => instances.setMatrixAt(i, matrix));
		instances.castShadow = shadow && mat !== glow && mat !== glass;
		instances.receiveShadow = mat !== glow;
		instances.computeBoundingSphere();
		scene.add(instances);
	}

	// ponytail: one low-resolution floor reflection; broader screen-space reflections need a device budget.
	const reflectionShader = {
		...Reflector.ReflectorShader,
		fragmentShader: Reflector.ReflectorShader.fragmentShader
			.replace('vec4 base = texture2DProj( tDiffuse, vUv );', `
				vec2 uv = vUv.xy / vUv.w;
				vec4 base = texture2D(tDiffuse, uv) * 0.4;
				base += texture2D(tDiffuse, uv + vec2(0.0025, 0.0)) * 0.15;
				base += texture2D(tDiffuse, uv - vec2(0.0025, 0.0)) * 0.15;
				base += texture2D(tDiffuse, uv + vec2(0.0, 0.0025)) * 0.15;
				base += texture2D(tDiffuse, uv - vec2(0.0, 0.0025)) * 0.15;
			`)
			.replace('vec4( blendOverlay( base.rgb, color ), 1.0 )', 'vec4( base.rgb, 0.14 )'),
	};
	const reflectionGeometry = new THREE.PlaneGeometry(44, 50);
	geometries.add(reflectionGeometry);
	const reflection = new Reflector(reflectionGeometry, { textureWidth: lowPower ? 256 : 512, textureHeight: lowPower ? 256 : 512, multisample: 0, clipBias: 0.003, shader: reflectionShader });
	reflection.rotation.x = -Math.PI / 2;
	reflection.position.set(0, -0.005, -1);
	reflection.material.transparent = true;
	reflection.material.depthWrite = false;
	scene.add(reflection);

	const shadowCanvas = document.createElement('canvas');
	shadowCanvas.width = shadowCanvas.height = 64;
	const ctx = shadowCanvas.getContext('2d')!;
	const gradient = ctx.createRadialGradient(32, 32, 2, 32, 32, 32);
	gradient.addColorStop(0, 'rgba(0,0,0,0.4)');
	gradient.addColorStop(1, 'rgba(0,0,0,0)');
	ctx.fillStyle = gradient;
	ctx.fillRect(0, 0, 64, 64);
	const shadowTexture = new THREE.CanvasTexture(shadowCanvas);
	textures.push(shadowTexture);
	const contactMaterial = new THREE.MeshBasicMaterial({ map: shadowTexture, transparent: true, depthWrite: false });
	const contactGeometry = new THREE.PlaneGeometry(1.75, 0.85);
	materials.add(contactMaterial);
	geometries.add(contactGeometry);
	const occupiedSlots = layout.slots.slice(0, productCount);
	const contacts = new THREE.InstancedMesh(contactGeometry, contactMaterial, occupiedSlots.length);
	occupiedSlots.forEach((slot, index) => {
		transform.position.set(slot.position[0], slot.position[1] - 0.52, slot.position[2]);
		transform.rotation.set(-Math.PI / 2, 0, slot.rotationY);
		transform.scale.set(1, 1, 1);
		transform.updateMatrix();
		contacts.setMatrixAt(index, transform.matrix);
	});
	contacts.computeBoundingSphere();
	scene.add(contacts);
	const slotTargets: THREE.Mesh[] = [];
	if (enableSlotEditing) {
		const targetGeometry = new THREE.PlaneGeometry(1.9, 1.25);
		const targetMaterial = new THREE.MeshBasicMaterial({
			transparent: true,
			opacity: 0,
			depthWrite: false,
			side: THREE.DoubleSide,
		});
		geometries.add(targetGeometry);
		materials.add(targetMaterial);
		for (const slot of layout.slots) {
			const target = new THREE.Mesh(targetGeometry, targetMaterial.clone());
			materials.add(target.material);
			target.position.set(...slot.position);
			target.rotation.y = slot.rotationY;
			target.userData.slotKey = slot.key;
			scene.add(target);
			slotTargets.push(target);
		}
	}
	return {
		scene,
		layout,
		ready,
		slotTargets,
		seatPromptSprites,
		updateWallArt: (next: ShowroomWallArt) => {
			for (const side of ['left', 'right'] as const) {
				const material = wallArtMaterials.get(side);
				if (!material) continue;
				const texture = createWallArtTexture(side, next[side]);
				material.map = texture;
				material.needsUpdate = true;
			}
		},
		dispose: () => {
			disposed = true;
			reflection.dispose();
			scene.traverse(object => { if (object instanceof THREE.InstancedMesh) object.dispose(); });
			geometries.forEach(geometry => geometry.dispose());
			materials.forEach(mat => mat.dispose());
			textures.forEach(texture => texture.dispose());
			lights.forEach(light => { if ('dispose' in light && typeof light.dispose === 'function') light.dispose(); });
			environmentTarget.dispose();
		},
	};
}
