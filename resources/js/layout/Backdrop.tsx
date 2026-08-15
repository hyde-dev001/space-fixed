import React from 'react';
import { useSidebar } from '../context/SidebarContext';

const Backdrop: React.FC = () => {
  const { isMobileOpen, toggleMobileSidebar } = useSidebar();

  if (!isMobileOpen) {
    return null;
  }

  return (
    <div
      data-testid="sidebar-backdrop"
      role="button"
      tabIndex={0}
      aria-label="Close sidebar"
      className="fixed inset-0 z-30 bg-black/50 motion-reduce:transition-none md:hidden"
      onClick={toggleMobileSidebar}
      onKeyDown={(event) => {
        if (event.key === "Enter" || event.key === " " || event.key === "Escape") {
          event.preventDefault();
          toggleMobileSidebar();
        }
      }}
    />
  );
};

export default Backdrop;
