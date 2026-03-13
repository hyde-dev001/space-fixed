import { SidebarProvider, useSidebar } from "../context/SidebarContext";
import AppHeader_shopOwner from "./AppHeader_shopOwner";
import Backdrop from "./Backdrop";
import AppSidebar_shopOwner from "./AppSidebar_shopOwner";
import { ReactNode } from "react";

interface AppLayoutShopOwnerProps {
  children: ReactNode;
  fullBleed?: boolean;
}

const LayoutContent: React.FC<{ children: ReactNode; fullBleed?: boolean }> = ({ children, fullBleed }) => {
  const { isExpanded, isHovered, isMobileOpen } = useSidebar();

  return (
    <div className="min-h-screen xl:flex">
      <div>
        <AppSidebar_shopOwner />
        <Backdrop />
      </div>
      <div
        className={`flex-1 transition-all duration-300 ease-in-out ${isExpanded || isHovered ? "lg:ml-72.5" : "lg:ml-22.5"
          } ${isMobileOpen ? "ml-0" : ""}`}
      >
        <AppHeader_shopOwner />
        <div className={fullBleed ? "p-0 m-0 max-w-none" : "p-4 mx-auto max-w-(--breakpoint-2xl) md:p-6"}>
          {children}
        </div>
      </div>
    </div>
  );
};

const AppLayoutShopOwner: React.FC<AppLayoutShopOwnerProps> = ({ children, fullBleed }) => {
  return (
    <SidebarProvider>
      <LayoutContent fullBleed={fullBleed}>{children}</LayoutContent>
    </SidebarProvider>
  );
};

export default AppLayoutShopOwner;
