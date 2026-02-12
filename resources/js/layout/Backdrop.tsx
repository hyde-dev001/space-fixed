import React from 'react';
import { useSidebar } from '../context/SidebarContext';

const Backdrop: React.FC = () => {
  const { isMobileOpen, toggleMobileMenu } = useSidebar();

  if (!isMobileOpen) {
    return null;
  }

  return (
    <div
      className="fixed inset-0 bg-black/50 z-30 md:hidden"
      onClick={() => toggleMobileMenu()}
    />
  );
};

export default Backdrop;
