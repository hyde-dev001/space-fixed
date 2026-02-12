import { SidebarProvider, useSidebar } from "../context/SidebarContext";
import AppHeader_ERP from "./AppHeader_ERP";
import Backdrop from "./Backdrop";
import AppSidebar_ERP from "./AppSidebar_ERP";
import { ReactNode } from "react";

interface AppLayoutERPProps {
  children: ReactNode;
}

const LayoutContent: React.FC<{ children: ReactNode }> = ({ children }) => {
  const { isExpanded, isHovered, isMobileOpen } = useSidebar();

  return (
    <div className="min-h-screen xl:flex bg-gray-50 text-gray-900 dark:bg-gray-950 dark:text-gray-100">
      <div>
        <AppSidebar_ERP />
        <Backdrop />
      </div>
      <div
        className={`flex-1 transition-all duration-300 ease-in-out bg-gray-50 dark:bg-gray-950 ${
          isExpanded || isHovered ? "lg:ml-[290px]" : "lg:ml-[90px]"
        } ${isMobileOpen ? "ml-0" : ""}`}
      >
        <AppHeader_ERP />
        <div className="p-4 mx-auto max-w-(--breakpoint-2xl) md:p-6">
          {children}
        </div>
      </div>
    </div>
  );
};

const AppLayoutERP: React.FC<AppLayoutERPProps> = ({ children }) => {
  return (
    <SidebarProvider>
      <LayoutContent>{children}</LayoutContent>
    </SidebarProvider>
  );
};

export default AppLayoutERP;
