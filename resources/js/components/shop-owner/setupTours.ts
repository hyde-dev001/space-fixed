export type TourStep = {
  target: string;
  title: string;
  description: string;
  action: "click" | "view";
  route?: string;
};

const account = (destination: "profile" | "settings"): TourStep[] => [
  { target: "owner-account-menu", title: "Open your account menu", description: "Click your account picture to find your shop controls.", action: "click" },
  { target: destination === "profile" ? "owner-profile-link" : "owner-settings-link", title: destination === "profile" ? "Open Shop Profile" : "Open Business Settings", description: "Click this real menu item to continue.", action: "click" },
];

const setting = (section: "operations" | "payments-approvals" | "policies-compliance", target: string, title: string, description: string): TourStep[] => [
  ...account("settings"),
  { target: `settings-nav-${section}`, title: `Open ${section === "payments-approvals" ? "Payments & Approvals" : section === "operations" ? "Operations" : "Policies & Compliance"}`, description: "Click this settings section.", action: "click", route: `/shop-owner/settings/${section}` },
  { target, title, description, action: "view", route: `/shop-owner/settings/${section}` },
];

export const setupTours: Record<string, TourStep[]> = {
  profile_photos: [...account("profile"), { target: "shop-profile-photos", title: "Add shop pictures", description: "Use the profile and cover picture controls, then save your changes.", action: "view", route: "/shop-owner/shop-profile" }],
  operating_hours: [...account("profile"), { target: "shop-profile-edit", title: "Edit your profile", description: "Click Edit Profile to change your shop hours.", action: "click", route: "/shop-owner/shop-profile" }, { target: "shop-hours-open-modal", title: "Open the hours editor", description: "Click Set Time in Modal.", action: "click", route: "/shop-owner/shop-profile" }, { target: "shop-hours-modal", title: "Set operating hours", description: "Choose a valid opening and closing time, apply the hours, then save profile changes. The guide checks the saved hours.", action: "view", route: "/shop-owner/shop-profile" }],
  paymongo: setting("payments-approvals", "paymongo-configure", "Configure PayMongo", "Enter and save your secret key here. Keep it private."),
  refund_deadline: setting("operations", "refund-deadline", "Review the refund deadline", "Choose a valid deadline and save it here."),
  repair_payment_policy: setting("operations", "repair-payment-policy", "Review repair payments", "Repair orders use the current full upfront payment policy."),
  terms_policy: setting("policies-compliance", "terms-policy", "Publish your policy", "Complete the applicable policy sections and publish a version."),
  payroll_cutoff: setting("operations", "payroll-cutoff", "Set payroll dates", "Choose the payroll cycle and payout day or days, then save."),
  attendance_geofence: setting("operations", "attendance-geofence", "Set attendance geofence", "Choose your shop location and radius, enable geofencing, then save."),
  xendit: setting("payments-approvals", "xendit-payouts", "Connect supplier payouts", "Add your Xendit payout credentials and verify the connection."),
  cod: setting("operations", "cod-settings", "Set up Cash on Delivery", "After Shop-owned Logistics has an active dispatcher and rider, enable COD and save the merchandise limit."),
  first_employee: [{ target: "employee-access", title: "Add an employee", description: "Click the real Add Employee button.", action: "click", route: "/shop-owner/erp/hr/user-access-control" }, { target: "employee-form", title: "Create the employee account", description: "Enter employee details and an appropriate role, then save. The guide checks for a linked account.", action: "view", route: "/shop-owner/erp/hr/user-access-control" }],
  articles: [
    { target: "sidebar-articles", title: "Open Articles", description: "Click Articles in the sidebar. You can return here for help at any time.", action: "click" },
    { target: "articles-categories", title: "Browse by category", description: "Choose a category to narrow the guides.", action: "view", route: "/shop-owner/erp/articles" },
    { target: "articles-results", title: "Read an article", description: "Open a guide here, or choose a recommended read above when available.", action: "view", route: "/shop-owner/erp/articles" },
  ],
  customer_preview: [...account("profile"), { target: "customer-preview", title: "Preview your shop", description: "Open the public shop page to see what customers see.", action: "click", route: "/shop-owner/shop-profile" }],
};
