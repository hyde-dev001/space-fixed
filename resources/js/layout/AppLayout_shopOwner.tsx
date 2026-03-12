import { SidebarProvider, useSidebar } from "../context/SidebarContext";
import AppHeader_shopOwner from "./AppHeader_shopOwner";
import Backdrop from "./Backdrop";
import AppSidebar_shopOwner from "./AppSidebar_shopOwner";
import { ReactNode } from "react";

interface AppLayoutShopOwnerProps {
  children: ReactNode;
}

const LayoutContent: React.FC<{ children: ReactNode }> = ({ children }) => {
  const { isExpanded, isHovered, isMobileOpen } = useSidebar();

  return (
    <div className="min-h-screen xl:flex">
      <div>
        <AppSidebar_shopOwner />
        <Backdrop />
      </div>
      <div
        className={`flex-1 transition-all duration-300 ease-in-out ${isExpanded || isHovered ? "lg:ml-[290px]" : "lg:ml-[90px]"
          } ${isMobileOpen ? "ml-0" : ""}`}
      >
        <AppHeader_shopOwner />
        <div className="p-4 mx-auto max-w-(--breakpoint-2xl) md:p-6">
          {children}
        </div>
      </div>
    </div>
  );
};

const AppLayoutShopOwner: React.FC<AppLayoutShopOwnerProps> = ({ children }) => {
  return (
    <SidebarProvider>
      <LayoutContent>{children}</LayoutContent>
    </SidebarProvider>
  );
};

export default AppLayoutShopOwner;
