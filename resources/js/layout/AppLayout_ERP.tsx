import { SidebarProvider, useSidebar } from "../context/SidebarContext";
import AppHeader_ERP from "./AppHeader_ERP";
import Backdrop from "./Backdrop";
import AppSidebar_ERP from "./AppSidebar_ERP";
import CanonicalOwnerLayout from "./CanonicalOwnerLayout";
import OwnerModuleTabs, { type OwnerModuleTabLink } from "../components/owner-shell/OwnerModuleTabs";
import { isOwnerModeErpContext, readCanonicalOwnerShell } from "./ownerShellMetadata";
import { usePage } from "@inertiajs/react";
import { ReactNode } from "react";

interface AppLayoutERPProps {
  children: ReactNode;
  hideHeader?: boolean;
  fullBleed?: boolean;
}

type OwnerActiveModule = {
  label: string;
  overview: OwnerModuleTabLink;
  pages: OwnerModuleTabLink[];
};

const readOwnerActiveModule = (value: unknown): OwnerActiveModule | null => {
  if (typeof value !== "object" || value === null || Array.isArray(value)) {
    return null;
  }

  const module = value as Record<string, unknown>;
  if (typeof module.label !== "string" || typeof module.overview !== "object" || module.overview === null || !Array.isArray(module.pages)) {
    return null;
  }

  const overview = module.overview as Record<string, unknown>;
  if (typeof overview.label !== "string" || typeof overview.url !== "string") {
    return null;
  }

  if (!module.pages.every((page) => (
    typeof page === "object" && page !== null && !Array.isArray(page)
      && typeof (page as Record<string, unknown>).label === "string"
      && typeof (page as Record<string, unknown>).url === "string"
  ))) {
    return null;
  }

  return {
    label: module.label,
    overview: { label: overview.label, url: overview.url },
    pages: module.pages.map((page) => {
      const tab = page as Record<string, unknown>;

      return { label: tab.label as string, url: tab.url as string };
    }),
  };
};

const LayoutContent: React.FC<{ children: ReactNode; hideHeader?: boolean; fullBleed?: boolean }> = ({ children, hideHeader, fullBleed }) => {
  const { isExpanded, isHovered, isMobileOpen } = useSidebar();
  const page = usePage();

  return (
    <div className="erp-theme min-h-screen xl:flex bg-white text-gray-900 dark:bg-gray-950 dark:text-gray-100">
      <div>
        <AppSidebar_ERP />
        <Backdrop />
      </div>
      <div
        className={`flex-1 transition-all duration-300 ease-in-out bg-white dark:bg-gray-950 ${
          isExpanded || isHovered ? "xl:ml-[290px]" : "xl:ml-[90px]"
        } ${isMobileOpen ? "ml-0" : ""}`}
      >
        {!hideHeader && <AppHeader_ERP />}
        <div className={fullBleed ? "p-0 m-0 max-w-none" : "p-4 mx-auto max-w-(--breakpoint-2xl) md:p-6"}>
          <div key={page.component} className="backoffice-page-enter">
            {children}
          </div>
        </div>
      </div>
    </div>
  );
};

const AppLayoutERP: React.FC<AppLayoutERPProps> = ({ children, hideHeader, fullBleed }) => {
  const page = usePage();
  const pageProps = page.props as Record<string, unknown>;
  const ownerShell = readCanonicalOwnerShell(pageProps.ownerShell);
  const ownerMode = isOwnerModeErpContext(pageProps);
  const auth = typeof pageProps.auth === "object" && pageProps.auth !== null
    ? pageProps.auth as Record<string, unknown>
    : {};
  const user = typeof auth.user === "object" && auth.user !== null
    ? auth.user as Record<string, unknown>
    : null;
  const attendance = typeof pageProps.employeeAttendance === "object" && pageProps.employeeAttendance !== null
    ? pageProps.employeeAttendance as Record<string, unknown>
    : null;
  const isEmployee = user?.shop_owner_id !== null
    && user?.shop_owner_id !== undefined
    && attendance?.is_employee === true;
  const currentPath = page.url.split("?")[0];
  const isTimeInPage = currentPath === "/erp/time-in" || currentPath === "/erp/staff/attendance";
  const showEmployeeReadOnlyNotice = !ownerMode && isEmployee && attendance?.is_clocked_in !== true && !isTimeInPage;
  const activeModule = ownerMode ? readOwnerActiveModule(pageProps.activeModule) : null;
  const content = (
    <>
      {activeModule && (
        <OwnerModuleTabs
          moduleLabel={activeModule.label}
          links={[activeModule.overview, ...activeModule.pages]}
          currentUrl={page.url}
        />
      )}
      {showEmployeeReadOnlyNotice && (
        <div
          role="status"
          className="mb-4 flex flex-col gap-2 rounded-2xl border border-gray-200 bg-gray-50 px-4 py-3 text-sm text-gray-700 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300 sm:flex-row sm:items-center sm:justify-between"
        >
          <span>Read-only mode. Clock in before processing business actions.</span>
          <a
            className="font-semibold text-gray-900 underline underline-offset-4 dark:text-white"
            href="/erp/time-in"
          >
            Go to Time In
          </a>
        </div>
      )}
      {children}
    </>
  );

  if (ownerShell && ownerMode) {
    return (
      <CanonicalOwnerLayout metadata={ownerShell} fullBleed={fullBleed} hideHeader={hideHeader}>
        {content}
      </CanonicalOwnerLayout>
    );
  }

  return (
    <SidebarProvider>
      <LayoutContent hideHeader={hideHeader} fullBleed={fullBleed}>
        {content}
      </LayoutContent>
    </SidebarProvider>
  );
};

export default AppLayoutERP;
