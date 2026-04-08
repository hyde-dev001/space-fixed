import { Head, usePage } from "@inertiajs/react";
import { useEffect, useMemo, useState } from "react";
import AppLayoutERP from "../../../layout/AppLayout_ERP";
import { PosMode, resolveAllowedModes } from "./posModeResolver";

type PageProps = {
  auth?: {
    user?: {
      shop_owner?: {
        business_type?: string | null;
      } | null;
    } | null;
    shop_owner?: {
      business_type?: string | null;
    } | null;
  };
};

const normalizeBusinessType = (props: PageProps): string => {
  return String(
    props?.auth?.user?.shop_owner?.business_type
      ?? props?.auth?.shop_owner?.business_type
      ?? "retail",
  ).toLowerCase();
};

export default function CashierPOS() {
  const { props } = usePage<PageProps>();

  const businessType = normalizeBusinessType(props);
  const allowedModes = useMemo(() => resolveAllowedModes(businessType), [businessType]);
  const [mode, setMode] = useState<PosMode>(allowedModes[0]);

  useEffect(() => {
    if (!allowedModes.includes(mode)) {
      setMode(allowedModes[0]);
    }
  }, [allowedModes, mode]);

  return (
    <AppLayoutERP>
      <Head title="Cashier POS" />

      <div className="space-y-4 p-4 md:p-6">
        <h1 className="text-2xl font-bold text-slate-900">Point of Sale</h1>

        <div className="flex flex-wrap gap-2" role="tablist" aria-label="POS Mode">
          {allowedModes.includes("repair") && (
            <button
              type="button"
              role="tab"
              aria-selected={mode === "repair"}
              onClick={() => setMode("repair")}
              className={`rounded-lg px-3 py-2 text-sm font-medium ${
                mode === "repair" ? "bg-slate-900 text-white" : "bg-slate-100 text-slate-700"
              }`}
            >
              Repair Mode
            </button>
          )}

          {allowedModes.includes("retail") && (
            <button
              type="button"
              role="tab"
              aria-selected={mode === "retail"}
              onClick={() => setMode("retail")}
              className={`rounded-lg px-3 py-2 text-sm font-medium ${
                mode === "retail" ? "bg-slate-900 text-white" : "bg-slate-100 text-slate-700"
              }`}
            >
              Retail Mode
            </button>
          )}
        </div>

        {mode === "repair" ? (
          <section data-testid="repair-pos-mode" className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <h2 className="text-lg font-semibold text-slate-900">Repair POS</h2>
            <p className="mt-1 text-sm text-slate-500">Use this mode for repair payments and repair order settlements.</p>
          </section>
        ) : (
          <section data-testid="retail-pos-mode" className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <h2 className="text-lg font-semibold text-slate-900">Retail POS</h2>
            <p className="mt-1 text-sm text-slate-500">Use this mode for walk-in retail sales and receipts.</p>
          </section>
        )}
      </div>
    </AppLayoutERP>
  );
}
