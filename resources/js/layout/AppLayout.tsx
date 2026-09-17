import { useSidebar } from "../context/SidebarContext";
import { usePage } from "@inertiajs/react";
import { ReactNode } from "react";
import AppHeader from "./AppHeader";
import Backdrop from "./Backdrop";
import AppSidebar from "./AppSidebar";

interface AppLayoutProps {
  children: ReactNode;
}

const LayoutContent: React.FC<{ children: ReactNode }> = ({ children }) => {
  const { isExpanded, isHovered, isMobileOpen } = useSidebar();
  const page = usePage();
  const isSuperAdminPage = typeof page.component === "string" && page.component.startsWith("superAdmin/");

  return (
    <div className="erp-theme super-admin-shell min-h-screen xl:flex bg-white text-gray-900 dark:bg-gray-950 dark:text-gray-100">
      <div>
        <AppSidebar />
        <Backdrop />
      </div>
      <div
        className={`flex-1 transition-all duration-300 ease-in-out bg-white dark:bg-gray-950 ${
          isExpanded || isHovered ? "lg:ml-[290px]" : "lg:ml-[90px]"
        } ${isMobileOpen ? "ml-0" : ""}`}
      >
        <AppHeader />
        <div className="p-4 mx-auto max-w-(--breakpoint-2xl) md:p-6 backoffice-content-shell">
          <div key={page.url} className={isSuperAdminPage ? "backoffice-page-enter" : undefined}>
            {children}
          </div>
        </div>
      </div>
    </div>
  );
};

const AppLayout: React.FC<AppLayoutProps> = ({ children }) => {
  return <LayoutContent>{children}</LayoutContent>;
};

export default AppLayout;
