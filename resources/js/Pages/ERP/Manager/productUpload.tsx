import { useState } from "react";
import { Head, usePage } from "@inertiajs/react";
import AppLayoutERP from "../../../layout/AppLayout_ERP";
import ErrorModal from "../../../components/common/ErrorModal";

export default function ProductsPage() {
  const [error, setError] = useState<string | null>(null);
  const { auth } = usePage().props as any;
  const userRole = auth?.user?.role;

  if (userRole !== "STAFF" && userRole !== "MANAGER") {
    return (
      <AppLayoutERP>
        <div className="max-w-xl mx-auto mt-24 text-center p-8 bg-white dark:bg-gray-900 rounded-xl shadow">
          <h2 className="text-2xl font-bold mb-2 text-red-600">Access Denied</h2>
          <p className="text-gray-700 dark:text-gray-300">You do not have permission to view the Staff module.</p>
        </div>
      </AppLayoutERP>
    );
  }

  return (
    <AppLayoutERP>
      <Head title="Products - Solespace ERP" />
      {error && <ErrorModal message={error} onClose={() => setError(null)} />}
      <div className="p-6">
        <div className="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-2xl p-8 shadow-sm">
          <h1 className="text-2xl font-semibold mb-2">Products</h1>
          <p className="text-gray-600 dark:text-gray-400">This is the Products page placeholder.</p>
        </div>
      </div>
    </AppLayoutERP>
  );
}
