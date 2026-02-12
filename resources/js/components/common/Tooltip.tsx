import React, { useState } from "react";

interface TooltipProps {
  content: string;
  children: React.ReactNode;
  position?: "top" | "bottom" | "left" | "right";
  delay?: number;
}

const Tooltip: React.FC<TooltipProps> = ({ content, children, position = "top", delay = 0 }) => {
  const [isVisible, setIsVisible] = useState(false);
  const [showTimeout, setShowTimeout] = useState<NodeJS.Timeout | null>(null);

  const handleMouseEnter = () => {
    const timeout = setTimeout(() => setIsVisible(true), delay);
    setShowTimeout(timeout);
  };

  const handleMouseLeave = () => {
    if (showTimeout) clearTimeout(showTimeout);
    setIsVisible(false);
  };

  const getPositionClasses = () => {
    switch (position) {
      case "bottom":
        return "bottom-full mb-2 left-1/2 transform -translate-x-1/2";
      case "left":
        return "right-full mr-2 top-1/2 transform -translate-y-1/2";
      case "right":
        return "left-full ml-2 top-1/2 transform -translate-y-1/2";
      case "top":
      default:
        return "top-full mt-2 left-1/2 transform -translate-x-1/2";
    }
  };

  return (
    <div className="relative inline-block" onMouseEnter={handleMouseEnter} onMouseLeave={handleMouseLeave}>
      {children}
      {isVisible && (
        <div
          className={`absolute ${getPositionClasses()} z-40 px-3 py-2 bg-gray-900 dark:bg-white text-white dark:text-gray-900 text-sm rounded-lg whitespace-nowrap shadow-lg pointer-events-none`}
          role="tooltip"
          aria-hidden={!isVisible}
        >
          {content}
          <div className="absolute w-2 h-2 bg-gray-900 dark:bg-white transform -translate-x-1/2 left-1/2 -bottom-1"></div>
        </div>
      )}
    </div>
  );
};

export default Tooltip;
