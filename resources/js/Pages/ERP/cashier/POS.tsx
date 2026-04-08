import { Head, usePage } from "@inertiajs/react";
import { FormEvent, useEffect, useMemo, useState } from "react";
import axios from "axios";
import Swal from "sweetalert2";
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
  const [isSubmittingRepair, setIsSubmittingRepair] = useState(false);
  const [repairResult, setRepairResult] = useState<{ transactionNo: string; transactionId: number } | null>(null);

  const [walkInName, setWalkInName] = useState("");
  const [walkInPhone, setWalkInPhone] = useState("");
  const [walkInEmail, setWalkInEmail] = useState("");
  const [manualRepairSubtotal, setManualRepairSubtotal] = useState<string>("");
  const [manualServiceSummary, setManualServiceSummary] = useState("");
  const [manualPaymentPolicy, setManualPaymentPolicy] = useState<"deposit_50" | "full_upfront">("deposit_50");
  const [tenderType, setTenderType] = useState<"cash" | "paymongo_card" | "paymongo_wallet">("cash");
  const [providerReference, setProviderReference] = useState("");
  const [paidAmount, setPaidAmount] = useState<string>("");

  useEffect(() => {
    if (!allowedModes.includes(mode)) {
      setMode(allowedModes[0]);
    }
  }, [allowedModes, mode]);

  const parsedSubtotal = Number(manualRepairSubtotal || 0);
  const expectedDue = useMemo(() => {
    if (!Number.isFinite(parsedSubtotal) || parsedSubtotal <= 0) {
      return 0;
    }

    const due = manualPaymentPolicy === "full_upfront"
      ? parsedSubtotal
      : parsedSubtotal * 0.5;

    return Number(due.toFixed(2));
  }, [manualPaymentPolicy, parsedSubtotal]);

  const nextDueType = manualPaymentPolicy === "full_upfront" ? "full" : "deposit";
  const requiresReference = tenderType === "paymongo_card" || tenderType === "paymongo_wallet";

  const handleRepairCheckout = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();

    const normalizedName = walkInName.trim();
    const normalizedSummary = manualServiceSummary.trim();
    const parsedPaidAmount = Number(paidAmount || 0);

    if (!normalizedName) {
      await Swal.fire({
        icon: "warning",
        title: "Walk-in name required",
        text: "Please enter the customer name for this walk-in repair checkout.",
        confirmButtonColor: "#111827",
      });
      return;
    }

    if (!Number.isFinite(parsedSubtotal) || parsedSubtotal <= 0) {
      await Swal.fire({
        icon: "warning",
        title: "Subtotal required",
        text: "Please enter a valid repair subtotal.",
        confirmButtonColor: "#111827",
      });
      return;
    }

    if (!Number.isFinite(parsedPaidAmount) || parsedPaidAmount <= 0) {
      await Swal.fire({
        icon: "warning",
        title: "Payment amount required",
        text: "Please enter the payment amount to collect.",
        confirmButtonColor: "#111827",
      });
      return;
    }

    if (parsedPaidAmount.toFixed(2) !== expectedDue.toFixed(2)) {
      await Swal.fire({
        icon: "warning",
        title: "Amount mismatch",
        text: `Payment amount must equal the expected due amount (${expectedDue.toFixed(2)}).`,
        confirmButtonColor: "#111827",
      });
      return;
    }

    if (requiresReference && providerReference.trim() === "") {
      await Swal.fire({
        icon: "warning",
        title: "Reference required",
        text: "Provider reference is required for non-cash payments.",
        confirmButtonColor: "#111827",
      });
      return;
    }

    const idempotencyKey = `cashier-walkin-repair-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`;

    const payload = {
      due_type: nextDueType,
      idempotency_key: idempotencyKey,
      customer_type: "walk_in",
      walk_in_name: normalizedName,
      walk_in_phone: walkInPhone.trim() || null,
      walk_in_email: walkInEmail.trim() || null,
      manual_repair_subtotal: Number(parsedSubtotal.toFixed(2)),
      manual_service_summary: normalizedSummary || "Walk-in service from cashier POS.",
      manual_payment_policy: manualPaymentPolicy,
      payment_lines: [
        {
          tender_type: tenderType,
          amount: Number(parsedPaidAmount.toFixed(2)),
          provider_reference: requiresReference ? providerReference.trim() : null,
        },
      ],
    };

    setIsSubmittingRepair(true);

    try {
      const response = await axios.post("/api/repair-pos/checkout", payload, {
        withCredentials: true,
      });

      const transactionNo = String(response?.data?.transaction_no || "");
      const transactionId = Number(response?.data?.transaction_id || 0);

      setRepairResult({ transactionNo, transactionId });
      setWalkInName("");
      setWalkInPhone("");
      setWalkInEmail("");
      setManualRepairSubtotal("");
      setManualServiceSummary("");
      setProviderReference("");
      setPaidAmount("");

      await Swal.fire({
        icon: "success",
        title: "Repair checkout complete",
        text: transactionNo
          ? `Transaction ${transactionNo} has been recorded successfully.`
          : "Repair checkout has been recorded successfully.",
        confirmButtonColor: "#111827",
      });
    } catch (error: any) {
      const message = error?.response?.data?.message
        || error?.response?.data?.errors?.payment_lines?.[0]
        || "Failed to process repair checkout.";

      await Swal.fire({
        icon: "error",
        title: "Checkout failed",
        text: String(message),
        confirmButtonColor: "#111827",
      });
    } finally {
      setIsSubmittingRepair(false);
    }
  };

  return (
    <AppLayoutERP>
      <Head title="Cashier POS" />

      <div className="space-y-4 p-4 md:p-6">
        <h1 className="text-2xl font-bold text-slate-900">Point of Sale</h1>

        <div className="flex flex-wrap gap-2" aria-label="POS Mode">
          {allowedModes.includes("repair") && (
            <button
              type="button"
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
            <p className="mt-1 text-sm text-slate-500">Process walk-in repair checkout and record payment in the repair POS ledger.</p>

            <form className="mt-4 grid gap-4 md:grid-cols-2" onSubmit={handleRepairCheckout}>
              <label className="space-y-1 text-sm text-slate-700">
                <span className="font-medium">Walk-in customer name</span>
                <input
                  type="text"
                  value={walkInName}
                  onChange={(event) => setWalkInName(event.target.value)}
                  className="w-full rounded-lg border border-slate-300 px-3 py-2"
                  placeholder="Customer full name"
                  required
                />
              </label>

              <label className="space-y-1 text-sm text-slate-700">
                <span className="font-medium">Phone (optional)</span>
                <input
                  type="text"
                  value={walkInPhone}
                  onChange={(event) => setWalkInPhone(event.target.value)}
                  className="w-full rounded-lg border border-slate-300 px-3 py-2"
                  placeholder="09XXXXXXXXX"
                />
              </label>

              <label className="space-y-1 text-sm text-slate-700">
                <span className="font-medium">Email (optional)</span>
                <input
                  type="email"
                  value={walkInEmail}
                  onChange={(event) => setWalkInEmail(event.target.value)}
                  className="w-full rounded-lg border border-slate-300 px-3 py-2"
                  placeholder="customer@email.com"
                />
              </label>

              <label className="space-y-1 text-sm text-slate-700">
                <span className="font-medium">Payment policy</span>
                <select
                  value={manualPaymentPolicy}
                  onChange={(event) => setManualPaymentPolicy(event.target.value as "deposit_50" | "full_upfront")}
                  className="w-full rounded-lg border border-slate-300 px-3 py-2"
                >
                  <option value="deposit_50">Deposit 50%</option>
                  <option value="full_upfront">Full upfront</option>
                </select>
              </label>

              <label className="space-y-1 text-sm text-slate-700">
                <span className="font-medium">Repair subtotal</span>
                <input
                  type="number"
                  min="0.01"
                  step="0.01"
                  value={manualRepairSubtotal}
                  onChange={(event) => setManualRepairSubtotal(event.target.value)}
                  className="w-full rounded-lg border border-slate-300 px-3 py-2"
                  placeholder="0.00"
                  required
                />
              </label>

              <label className="space-y-1 text-sm text-slate-700 md:col-span-2">
                <span className="font-medium">Service summary</span>
                <textarea
                  value={manualServiceSummary}
                  onChange={(event) => setManualServiceSummary(event.target.value)}
                  className="w-full rounded-lg border border-slate-300 px-3 py-2"
                  rows={3}
                  placeholder="Describe services performed"
                />
              </label>

              <label className="space-y-1 text-sm text-slate-700">
                <span className="font-medium">Tender type</span>
                <select
                  value={tenderType}
                  onChange={(event) => setTenderType(event.target.value as "cash" | "paymongo_card" | "paymongo_wallet")}
                  className="w-full rounded-lg border border-slate-300 px-3 py-2"
                >
                  <option value="cash">Cash</option>
                  <option value="paymongo_card">Card</option>
                  <option value="paymongo_wallet">Wallet</option>
                </select>
              </label>

              <label className="space-y-1 text-sm text-slate-700">
                <span className="font-medium">Amount to collect</span>
                <input
                  type="number"
                  min="0.01"
                  step="0.01"
                  value={paidAmount}
                  onChange={(event) => setPaidAmount(event.target.value)}
                  className="w-full rounded-lg border border-slate-300 px-3 py-2"
                  placeholder={expectedDue > 0 ? expectedDue.toFixed(2) : "0.00"}
                  required
                />
              </label>

              {requiresReference && (
                <label className="space-y-1 text-sm text-slate-700 md:col-span-2">
                  <span className="font-medium">Provider reference</span>
                  <input
                    type="text"
                    value={providerReference}
                    onChange={(event) => setProviderReference(event.target.value)}
                    className="w-full rounded-lg border border-slate-300 px-3 py-2"
                    placeholder="Reference from wallet/card provider"
                    required
                  />
                </label>
              )}

              <div className="md:col-span-2 rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm text-slate-700">
                <p>Due type: <span className="font-semibold uppercase">{nextDueType}</span></p>
                <p>Expected due amount: <span className="font-semibold">{expectedDue.toFixed(2)}</span></p>
              </div>

              {repairResult && (
                <div className="md:col-span-2 rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-700">
                  Last transaction: <span className="font-semibold">{repairResult.transactionNo || `#${repairResult.transactionId}`}</span>
                </div>
              )}

              <div className="md:col-span-2 flex justify-end">
                <button
                  type="submit"
                  disabled={isSubmittingRepair}
                  className="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:opacity-60"
                >
                  {isSubmittingRepair ? "Processing..." : "Process walk-in repair checkout"}
                </button>
              </div>
            </form>
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
