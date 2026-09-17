import { useEffect, useRef } from "react";
import { SidebarProvider, useSidebar } from "../context/SidebarContext";
import type { OwnerShellMetadata } from "../types/ownerShell";
import CanonicalOwnerHeader from "./CanonicalOwnerHeader";
import CanonicalOwnerSidebar from "./CanonicalOwnerSidebar";
import Backdrop from "./Backdrop";

interface CanonicalOwnerLayoutProps {
  children: React.ReactNode;
  metadata: OwnerShellMetadata;
  fullBleed?: boolean;
  hideHeader?: boolean;
}

const CanonicalOwnerLayoutContent: React.FC<CanonicalOwnerLayoutProps> = ({
  children,
  metadata,
  fullBleed,
  hideHeader,
}) => {
  const { isExpanded, isHovered, isMobileOpen } = useSidebar();
  const menuButtonRef = useRef<HTMLButtonElement>(null);
  const wasMobileOpen = useRef(false);

  useEffect(() => {
    if (wasMobileOpen.current && !isMobileOpen) {
      menuButtonRef.current?.focus();
    }
    wasMobileOpen.current = isMobileOpen;
  }, [isMobileOpen]);

  return (
    <div data-testid="canonical-owner-frame" className="canonical-owner-theme erp-theme min-h-screen bg-gray-50 text-gray-900 motion-reduce:transition-none dark:bg-gray-950 dark:text-gray-100 xl:flex">
      <div>
        <CanonicalOwnerSidebar metadata={metadata} />
        <Backdrop />
      </div>
      <div
        className={`flex-1 bg-gray-50 transition-all duration-300 ease-in-out motion-reduce:transition-none dark:bg-gray-950 ${isExpanded || isHovered ? "xl:ml-[290px]" : "xl:ml-[90px]"} ${isMobileOpen ? "ml-0" : ""}`}
      >
        <CanonicalOwnerHeader menuButtonRef={menuButtonRef} hideHeader={hideHeader} />
        <main className={fullBleed ? "p-0 m-0 max-w-none" : "p-4 mx-auto max-w-(--breakpoint-2xl) md:p-6"}>
          {children}
        </main>
      </div>
    </div>
  );
};

const CanonicalOwnerLayout: React.FC<CanonicalOwnerLayoutProps> = (props) => (
  <SidebarProvider>
    <CanonicalOwnerLayoutContent {...props} />
  </SidebarProvider>
);

export default CanonicalOwnerLayout;
