export default function SidebarWidget() {
  return (
    <div className="mx-auto mb-10 w-full max-w-60 rounded-2xl bg-gray-50 px-4 py-5 text-center dark:bg-white/[0.03]">
      <h3 className="mb-2 font-semibold text-gray-900 dark:text-white">
        SoleSpace workspace
      </h3>
      <p className="mb-4 text-gray-500 text-theme-sm dark:text-gray-400">
        Keep daily operations, attendance, and service work in one place.
      </p>
      <a
        href="/"
        className="flex min-h-11 items-center justify-center rounded-lg bg-brand-500 p-3 font-medium text-white text-theme-sm hover:bg-brand-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 focus-visible:ring-offset-2"
      >
        Open SoleSpace
      </a>
    </div>
  );
}
