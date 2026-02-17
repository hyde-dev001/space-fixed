import React, { useState, useRef, useEffect } from 'react';

declare global {
  interface Window {
    THREE: any;
  }
}

const Virtual3DShowroom: React.FC<{
  modelUrl: string;
  productName: string;
  onClose?: () => void;
}> = ({ modelUrl, productName, onClose }) => {
  const containerRef = useRef<HTMLDivElement>(null);
  const sceneRef = useRef<any>(null);
  const rendererRef = useRef<any>(null);
  const modelRef = useRef<any>(null);
  const cameraRef = useRef<any>(null);
  const animationFrameRef = useRef<number | null>(null);
  const cameraTargetRef = useRef({ x: 0, y: 0, z: 0 });
  const cameraDistanceRef = useRef(3);
  const cameraDistanceRangeRef = useRef({ min: 1, max: 8 });
  
  const [isDragging, setIsDragging] = useState(false);
  const [rotation, setRotation] = useState({ x: 0, y: 0 });
  const [isLoading, setIsLoading] = useState(true);
  const [loadError, setLoadError] = useState<string | null>(null);
  
  const dragStartRef = useRef({ x: 0, y: 0 });
  const rotationVelocityRef = useRef({ x: 0, y: 0 });
  const targetRotationRef = useRef({ x: 0, y: 0 });
  const clamp = (value: number, min: number, max: number) => Math.max(min, Math.min(max, value));

  const updateCameraDistance = (nextDistance: number) => {
    const camera = cameraRef.current;
    if (!camera) return;

    const target = cameraTargetRef.current;
    const dx = camera.position.x - target.x;
    const dy = camera.position.y - target.y;
    const dz = camera.position.z - target.z;
    const length = Math.sqrt(dx * dx + dy * dy + dz * dz) || 1;

    const nx = dx / length;
    const ny = dy / length;
    const nz = dz / length;

    camera.position.set(
      target.x + nx * nextDistance,
      target.y + ny * nextDistance,
      target.z + nz * nextDistance
    );
    camera.lookAt(target.x, target.y, target.z);
    cameraDistanceRef.current = nextDistance;
  };

  const zoomByFactor = (factor: number) => {
    const { min, max } = cameraDistanceRangeRef.current;
    const nextDistance = clamp(cameraDistanceRef.current * factor, min, max);
    updateCameraDistance(nextDistance);
  };

  useEffect(() => {
    let cleanupScene: (() => void) | undefined;
    let isCancelled = false;

    // Load Three.js from CDN for simple setup
    const loadThreeJS = async () => {
      try {
        // Check if Three is already loaded
        if (!window.THREE) {
          // Create script tag for Three.js
          const threeScript = document.createElement('script');
          threeScript.src = 'https://cdnjs.cloudflare.com/ajax/libs/three.js/r128/three.min.js';
          threeScript.async = true;
          
          await new Promise((resolve, reject) => {
            threeScript.onload = resolve;
            threeScript.onerror = reject;
            document.head.appendChild(threeScript);
          });

          // Load GLTFLoader
          const loaderScript = document.createElement('script');
          loaderScript.src = 'https://cdn.jsdelivr.net/npm/three@0.128.0/examples/js/loaders/GLTFLoader.js';
          loaderScript.async = true;
          
          await new Promise((resolve, reject) => {
            loaderScript.onload = resolve;
            loaderScript.onerror = reject;
            document.head.appendChild(loaderScript);
          });
        }

        if (isCancelled) return;

        cleanupScene = initializeScene();
      } catch (error) {
        if (isCancelled) return;
        console.error('Error loading Three.js:', error);
        setLoadError('Failed to load 3D viewer libraries');
        setIsLoading(false);
      }
    };

    const initializeScene = () => {
      if (!containerRef.current || !window.THREE) return;

      const THREE = window.THREE;
      
      // Scene setup
      const scene = new THREE.Scene();
      scene.background = new THREE.Color(0xf3f4f6);
      sceneRef.current = scene;

      // Camera setup
      const camera = new THREE.PerspectiveCamera(
        75,
        containerRef.current.clientWidth / containerRef.current.clientHeight,
        0.1,
        1000
      );
      camera.position.z = 3;
      cameraRef.current = camera;

      // Renderer setup
      const renderer = new THREE.WebGLRenderer({ 
        antialias: true, 
        alpha: true,
        powerPreference: 'high-performance'
      });
      renderer.setSize(containerRef.current.clientWidth, containerRef.current.clientHeight);
      renderer.setPixelRatio(window.devicePixelRatio);
      renderer.shadowMap.enabled = true;
      containerRef.current.appendChild(renderer.domElement);
      rendererRef.current = renderer;

      // Lighting
      const ambientLight = new THREE.AmbientLight(0xffffff, 0.6);
      scene.add(ambientLight);

      const directionalLight = new THREE.DirectionalLight(0xffffff, 0.8);
      directionalLight.position.set(5, 5, 5);
      directionalLight.castShadow = true;
      directionalLight.shadow.mapSize.width = 2048;
      directionalLight.shadow.mapSize.height = 2048;
      scene.add(directionalLight);

      const fillLight = new THREE.DirectionalLight(0xffffff, 0.3);
      fillLight.position.set(-5, 0, -3);
      scene.add(fillLight);

      // Load 3D Model
      const loader = new (window.THREE as any).GLTFLoader();
      const loadTimeout = window.setTimeout(() => {
        setLoadError('Model loading timed out. Please check file path/network and try again.');
        setIsLoading(false);
      }, 20000);

      loader.load(
        modelUrl,
        (gltf: any) => {
          window.clearTimeout(loadTimeout);
          const model = gltf.scene;
          const modelGroup = new THREE.Group();
          modelGroup.add(model);
          
          // Center and scale the model
          const box = new THREE.Box3().setFromObject(model);
          const center = box.getCenter(new THREE.Vector3());
          const size = box.getSize(new THREE.Vector3());
          const maxDim = Math.max(size.x, size.y, size.z) || 1;
          const scale = 1.8 / maxDim;
          
          model.position.set(-center.x, -center.y, -center.z);
          model.scale.setScalar(1);
          modelGroup.scale.setScalar(scale);
          modelGroup.rotation.order = 'YXZ';

          const fittedBox = new THREE.Box3().setFromObject(modelGroup);
          const sphere = fittedBox.getBoundingSphere(new THREE.Sphere());
          const fov = camera.fov * (Math.PI / 180);
          const cameraDistance = (sphere.radius / Math.sin(fov / 2)) * 1.25;

          camera.position.set(0, sphere.center.y + sphere.radius * 0.12, cameraDistance);
          camera.near = Math.max(0.01, cameraDistance / 100);
          camera.far = Math.max(1000, cameraDistance * 100);
          camera.lookAt(sphere.center);
          camera.updateProjectionMatrix();

          cameraTargetRef.current = {
            x: sphere.center.x,
            y: sphere.center.y,
            z: sphere.center.z,
          };
          cameraDistanceRef.current = cameraDistance;
          cameraDistanceRangeRef.current = {
            min: Math.max(0.5, sphere.radius * 1.05),
            max: Math.max(cameraDistance * 3, sphere.radius * 6),
          };
          
          // Enable shadows
          model.traverse((child: any) => {
            if (child.isMesh) {
              child.castShadow = true;
              child.receiveShadow = true;
            }
          });

          scene.add(modelGroup);
          modelRef.current = modelGroup;
          setIsLoading(false);
        },
        (progress: any) => {
          console.log((progress.loaded / progress.total) * 100 + '% loaded');
        },
        (error: any) => {
          window.clearTimeout(loadTimeout);
          console.error('Error loading model:', error);
          setLoadError('Failed to load 3D model. Make sure the GLB/GLTF file URL is correct.');
          setIsLoading(false);
        }
      );

      // Animation loop with inertia
      const animate = () => {
        animationFrameRef.current = requestAnimationFrame(animate);

        if (modelRef.current) {
          // Apply inertia
          if (!isDragging) {
            rotationVelocityRef.current.x *= 0.95;
            rotationVelocityRef.current.y *= 0.95;
            
            targetRotationRef.current.x += rotationVelocityRef.current.x;
            targetRotationRef.current.y += rotationVelocityRef.current.y;
          }

          // Smooth rotation
          modelRef.current.rotation.y += (targetRotationRef.current.y - modelRef.current.rotation.y) * 0.1;
          modelRef.current.rotation.x += (targetRotationRef.current.x - modelRef.current.rotation.x) * 0.1;
        }

        renderer.render(scene, camera);
      };

      animate();

      // Handle window resize
      const handleResize = () => {
        if (!containerRef.current || !rendererRef.current || !cameraRef.current) return;

        const width = containerRef.current.clientWidth;
        const height = containerRef.current.clientHeight;

        cameraRef.current.aspect = width / height;
        cameraRef.current.updateProjectionMatrix();
        rendererRef.current.setSize(width, height);
      };

      window.addEventListener('resize', handleResize);

      return () => {
        window.clearTimeout(loadTimeout);
        window.removeEventListener('resize', handleResize);
        if (animationFrameRef.current !== null) {
          cancelAnimationFrame(animationFrameRef.current);
          animationFrameRef.current = null;
        }
        renderer.dispose();
        if (containerRef.current && renderer.domElement.parentNode === containerRef.current) {
          containerRef.current.removeChild(renderer.domElement);
        }
      };
    };

    loadThreeJS();

    return () => {
      isCancelled = true;
      if (cleanupScene) {
        cleanupScene();
      }
    };
  }, [modelUrl]);

  const handleMouseDown = (e: React.MouseEvent) => {
    setIsDragging(true);
    dragStartRef.current = { x: e.clientX, y: e.clientY };
  };

  const handleMouseMove = (e: React.MouseEvent) => {
    if (!isDragging || !modelRef.current) return;

    const deltaX = e.clientX - dragStartRef.current.x;
    const deltaY = e.clientY - dragStartRef.current.y;

    // Convert pixel movement to rotation
    const rotationSpeed = 0.01;
    rotationVelocityRef.current.y = deltaX * rotationSpeed;
    rotationVelocityRef.current.x = deltaY * rotationSpeed * 0.5;

    targetRotationRef.current.y += deltaX * rotationSpeed;
    targetRotationRef.current.x += deltaY * rotationSpeed * 0.5;

    dragStartRef.current = { x: e.clientX, y: e.clientY };
  };

  const handleMouseUp = () => {
    setIsDragging(false);
  };

  const handleTouchStart = (e: React.TouchEvent) => {
    setIsDragging(true);
    const touch = e.touches[0];
    dragStartRef.current = { x: touch.clientX, y: touch.clientY };
  };

  const handleTouchMove = (e: React.TouchEvent) => {
    if (!isDragging || !modelRef.current) return;

    const touch = e.touches[0];
    const deltaX = touch.clientX - dragStartRef.current.x;
    const deltaY = touch.clientY - dragStartRef.current.y;

    const rotationSpeed = 0.01;
    rotationVelocityRef.current.y = deltaX * rotationSpeed;
    rotationVelocityRef.current.x = deltaY * rotationSpeed * 0.5;

    targetRotationRef.current.y += deltaX * rotationSpeed;
    targetRotationRef.current.x += deltaY * rotationSpeed * 0.5;

    dragStartRef.current = { x: touch.clientX, y: touch.clientY };
  };

  const handleTouchEnd = () => {
    setIsDragging(false);
  };

  const handleWheel = (e: React.WheelEvent<HTMLDivElement>) => {
    e.preventDefault();
    zoomByFactor(e.deltaY > 0 ? 1.08 : 0.92);
  };

  const resetRotation = () => {
    targetRotationRef.current = { x: 0, y: 0 };
    rotationVelocityRef.current = { x: 0, y: 0 };
    if (modelRef.current) {
      modelRef.current.rotation.set(0, 0, 0);
    }
  };

  const rotationDegrees = Math.round((targetRotationRef.current.y * 180) / Math.PI);

  return (
    <div
      className="fixed inset-0 z-50 bg-black/80 flex items-center justify-center"
      onClick={(e) => {
        if (e.target === e.currentTarget && onClose) {
          onClose();
        }
      }}
    >
      <div className="bg-white rounded-lg shadow-2xl w-[90%] h-[90vh] max-w-5xl flex flex-col">
        {/* Header */}
        <div className="flex items-center justify-between px-6 py-4 border-b border-gray-200">
          <div>
            <h2 className="text-2xl font-bold text-black">{productName}</h2>
            <p className="text-sm text-gray-600 mt-1">
              {isLoading ? 'Loading 3D Model...' : '3D Interactive View - Drag to rotate'}
            </p>
          </div>
          <button
            onClick={onClose}
            className="text-gray-500 hover:text-gray-700 transition-colors"
            aria-label="Close showroom"
          >
            <svg className="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </div>

        {/* Main Display Area */}
        <div className="flex-1 flex items-center justify-center overflow-hidden bg-linear-to-br from-gray-100 to-gray-50 relative">
          <div
            ref={containerRef}
            className={`w-full h-full ${isDragging ? 'cursor-grabbing' : 'cursor-grab'}`}
            onMouseDown={handleMouseDown}
            onMouseMove={handleMouseMove}
            onMouseUp={handleMouseUp}
            onMouseLeave={handleMouseUp}
            onWheel={handleWheel}
            onTouchStart={handleTouchStart}
            onTouchMove={handleTouchMove}
            onTouchEnd={handleTouchEnd}
          />

          {loadError && (
            <div className="text-center p-8">
              <svg className="mx-auto h-16 w-16 text-red-500 mb-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 8v4m0 4v.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
              </svg>
              <p className="text-red-600 font-medium mb-2">Error Loading Model</p>
              <p className="text-gray-600 text-sm max-w-sm">{loadError}</p>
            </div>
          )}

          {isLoading && !loadError && (
            <div className="text-center absolute inset-0 z-10 flex flex-col items-center justify-center bg-linear-to-br from-gray-100/95 to-gray-50/95">
              <div className="inline-block">
                <div className="w-16 h-16 border-4 border-gray-300 border-t-black rounded-full animate-spin mb-4"></div>
              </div>
              <p className="text-gray-600 font-medium">Loading 3D Model...</p>
              <p className="text-sm text-gray-500 mt-2">This may take a few moments</p>
            </div>
          )}

          {!isLoading && !loadError && (
            <>
              {/* 3D Controls Overlay */}
              <div className="absolute top-6 left-6 bg-black/70 text-white px-3 py-2 rounded-lg text-sm font-medium">
                {Math.abs(rotationDegrees)}°
              </div>

              {/* Rotation Hint */}
              {!isDragging && targetRotationRef.current.y === 0 && (
                <div className="absolute top-6 right-6 bg-blue-500/90 text-white px-4 py-2 rounded-lg text-xs font-medium flex items-center gap-2 animate-pulse">
                  <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M7 16V4m0 0L3 8m0 0l4 4m10-4v12m0 0l4-4m0 0l-4-4" />
                  </svg>
                  Drag to rotate
                </div>
              )}

              {/* Reset Button */}
              <button
                onClick={resetRotation}
                className="absolute bottom-6 right-6 bg-black hover:bg-gray-800 text-white px-4 py-2 rounded-lg text-sm font-medium transition-colors"
                title="Reset rotation"
              >
                Reset
              </button>

              <div className="absolute bottom-6 left-6 flex gap-2">
                <button
                  onClick={() => zoomByFactor(0.9)}
                  className="bg-black hover:bg-gray-800 text-white w-10 h-10 rounded-lg text-xl leading-none font-medium transition-colors"
                  title="Zoom in"
                  aria-label="Zoom in"
                >
                  +
                </button>
                <button
                  onClick={() => zoomByFactor(1.1)}
                  className="bg-black hover:bg-gray-800 text-white w-10 h-10 rounded-lg text-xl leading-none font-medium transition-colors"
                  title="Zoom out"
                  aria-label="Zoom out"
                >
                  −
                </button>
              </div>
            </>
          )}
        </div>
      </div>
    </div>
  );
};

export default Virtual3DShowroom;
