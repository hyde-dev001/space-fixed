import { Head } from "@inertiajs/react";
import AppLayoutERP from "../../../layout/AppLayout_ERP";

export default function Leads() {
  return (
    <AppLayoutERP>
      <Head title="Leads - Solespace ERP" />
      <div className="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-2xl p-8 shadow-sm">
        <h2 className="text-2xl font-semibold text-gray-900 dark:text-white mb-4">Leads</h2>
        <p className="text-gray-600 dark:text-gray-400">CRM leads feature coming soon...</p>
      </div>
    </AppLayoutERP>
  );
}
