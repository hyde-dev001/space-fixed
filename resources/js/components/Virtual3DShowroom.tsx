import React, { useEffect, useMemo, useRef, useState } from 'react';

const DEFAULT_FRAME_INDICES = Array.from({ length: 48 }, (_, index) => index + 1).filter((index) => index !== 41);
const getDefaultFrameUrl = (index: number) =>
  `/images/360/golf-shoe-360-product-photography-1500w-${String(index).padStart(3, '0')}-removebg-preview.png`;

const Virtual3DShowroom: React.FC<{
  productName: string;
  onClose?: () => void;
  embedded?: boolean;
  embeddedHeightClass?: string;
  frameUrls?: string[];
  showHeader?: boolean;
}> = ({ productName, onClose, embedded = false, embeddedHeightClass = 'h-[calc(100vh-72px)]', frameUrls, showHeader = true }) => {
  const frames = useMemo(
    () => (frameUrls && frameUrls.length > 0
      ? frameUrls
      : DEFAULT_FRAME_INDICES.map((index) => getDefaultFrameUrl(index))),
    [frameUrls]
  );

  const [currentFrame, setCurrentFrame] = useState(0);
  const [isDragging, setIsDragging] = useState(false);
  const dragStartXRef = useRef(0);
  const accumulatedDeltaRef = useRef(0);
  const pendingDeltaRef = useRef(0);
  const rafIdRef = useRef<number | null>(null);
  const currentFrameRef = useRef(0);
  const imageRef = useRef<HTMLImageElement | null>(null);
  const zoomRef = useRef(1);
  const containerRef = useRef<HTMLDivElement | null>(null);

  useEffect(() => {
    currentFrameRef.current = currentFrame;
  }, [currentFrame]);

  useEffect(() => {
    // Reset to first frame when the selected frame set changes.
    setCurrentFrame(0);
    currentFrameRef.current = 0;
    accumulatedDeltaRef.current = 0;
    pendingDeltaRef.current = 0;
    zoomRef.current = 1;
    if (imageRef.current) {
      imageRef.current.style.transform = 'scale(1)';
      imageRef.current.style.transformOrigin = 'center center';
    }
  }, [frames]);

  useEffect(() => {
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
  }, [frames]);

  useEffect(() => {
    return () => {
      if (rafIdRef.current !== null) {
        cancelAnimationFrame(rafIdRef.current);
        rafIdRef.current = null;
      }
    };
  }, []);

  const processPendingDelta = () => {
    rafIdRef.current = null;
    accumulatedDeltaRef.current += pendingDeltaRef.current;
    pendingDeltaRef.current = 0;

    const stepPx = 8;
    let nextFrame = currentFrameRef.current;

    while (Math.abs(accumulatedDeltaRef.current) >= stepPx) {
      const direction = accumulatedDeltaRef.current > 0 ? 1 : -1;
      nextFrame = (nextFrame + direction + frames.length) % frames.length;
      accumulatedDeltaRef.current -= direction * stepPx;
    }

    if (nextFrame !== currentFrameRef.current) {
      currentFrameRef.current = nextFrame;
      setCurrentFrame(nextFrame);
    }
  };

  const scheduleDeltaProcessing = () => {
    if (rafIdRef.current !== null) return;
    rafIdRef.current = requestAnimationFrame(processPendingDelta);
  };

  const moveByDelta = (deltaX: number) => {
    pendingDeltaRef.current += deltaX;
    scheduleDeltaProcessing();
  };

  const handleMouseDown = (event: React.MouseEvent<HTMLDivElement>) => {
    setIsDragging(true);
    dragStartXRef.current = event.clientX;
  };

  const handleMouseMove = (event: React.MouseEvent<HTMLDivElement>) => {
    if (!isDragging) return;
    const deltaX = event.clientX - dragStartXRef.current;
    dragStartXRef.current = event.clientX;
    moveByDelta(deltaX);
  };

  const handleMouseUp = () => {
    setIsDragging(false);
  };

  const handleTouchStart = (event: React.TouchEvent<HTMLDivElement>) => {
    setIsDragging(true);
    dragStartXRef.current = event.touches[0].clientX;
  };

  const handleTouchMove = (event: React.TouchEvent<HTMLDivElement>) => {
    if (!isDragging) return;
    const touchX = event.touches[0].clientX;
    const deltaX = touchX - dragStartXRef.current;
    dragStartXRef.current = touchX;
    moveByDelta(deltaX);
  };

  const handleTouchEnd = () => {
    setIsDragging(false);
  };

  const resetView = () => {
    setCurrentFrame(0);
    currentFrameRef.current = 0;
    accumulatedDeltaRef.current = 0;
    pendingDeltaRef.current = 0;
    zoomRef.current = 1;
    if (imageRef.current) {
      imageRef.current.style.transform = 'scale(1)';
      imageRef.current.style.transformOrigin = 'center center';
    }
  };

  const handleWheelZoom = (deltaY: number) => {
    if (!imageRef.current) return;
    const zoomSpeed = 0.0015;
    const nextZoom = Math.max(1, Math.min(4, zoomRef.current - deltaY * zoomSpeed));
    if (Math.abs(nextZoom - zoomRef.current) < 0.001) return;
    zoomRef.current = nextZoom;
    imageRef.current.style.transform = `scale(${nextZoom})`;
    imageRef.current.style.transformOrigin = 'center center';
  };

  useEffect(() => {
    const container = containerRef.current;
    if (!container) return;
    const preventScroll = (e: TouchEvent) => {
      e.preventDefault();
    };
    container.addEventListener('touchmove', preventScroll, { passive: false });
    return () => {
      container.removeEventListener('touchmove', preventScroll);
    };
  }, []);

  return (
    <div
      className={embedded ? 'w-full' : 'fixed inset-0 z-50 bg-black/80 flex items-center justify-center'}
      onClick={(event) => {
        if (embedded) return;
        if (event.target === event.currentTarget && onClose) {
          onClose();
        }
      }}
    >
      <div className={embedded ? `bg-white w-full ${embeddedHeightClass} flex flex-col` : 'bg-white w-full h-full xl:rounded-lg xl:shadow-2xl xl:w-[90%] xl:h-[90vh] xl:max-w-5xl flex flex-col'}>
        {showHeader ? (
          <div className="flex items-center justify-between px-6 py-4 border-b border-gray-200">
            <div>
              <h2 className="text-2xl font-bold text-black">{productName}</h2>
              <p className="text-sm text-gray-600 mt-1">360 Interactive View - Drag to rotate</p>
            </div>
            {!embedded && (
              <button
                onClick={onClose}
                className="text-gray-500 hover:text-gray-700 transition-colors"
                aria-label="Close showroom"
              >
                <svg className="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                </svg>
              </button>
            )}
          </div>
        ) : null}

        <div
          ref={containerRef}
          className={`flex-1 flex items-center justify-center overflow-hidden bg-white relative ${isDragging ? 'cursor-grabbing' : 'cursor-grab'}`}
          onWheel={(event) => {
            event.preventDefault();
            event.stopPropagation();
            handleWheelZoom(event.deltaY);
          }}
          onMouseDown={handleMouseDown}
          onMouseMove={handleMouseMove}
          onMouseUp={handleMouseUp}
          onMouseLeave={handleMouseUp}
          onTouchStart={handleTouchStart}
          onTouchMove={handleTouchMove}
          onTouchEnd={handleTouchEnd}
        >
          <img
            ref={imageRef}
            src={frames[currentFrame]}
            alt={`${productName} 360 frame ${currentFrame + 1}`}
            className="w-full h-full object-contain select-none pointer-events-none"
            draggable={false}
          />

          <button
            onClick={resetView}
            className="absolute bottom-6 right-6 bg-black hover:bg-gray-800 text-white px-4 py-2 rounded-lg text-sm font-medium transition-colors"
            title="Reset view"
            type="button"
          >
            Reset
          </button>
        </div>
      </div>
    </div>
  );
};

export default Virtual3DShowroom;
