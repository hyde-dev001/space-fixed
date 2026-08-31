import type { OwnerApprovalHistoryItem } from "../../types/ownerActionCenter";

interface OwnerApprovalHistoryListProps {
  items: OwnerApprovalHistoryItem[];
  onReview: (item: OwnerApprovalHistoryItem) => void;
  selectedAttentionKey?: string | null;
}

const sourceLabels: Record<OwnerApprovalHistoryItem["coverage_source"], string> = {
  refunds: "Refunds",
  prices: "Price changes",
  payslips: "Payslips",
  salary_changes: "Salary adjustments",
  purchase_requests: "Purchase requests",
  suspensions: "Suspension requests",
  terminations: "Termination requests",
  rehires: "Rehire requests",
  expenses: "Expenses",
  repair_rejections: "Repair rejections",
  compliance: "Compliance",
  logistics: "Logistics",
};

const formatAmount = (amount: number | null): string | null => (
  amount === null ? null : `PHP ${amount.toLocaleString("en-PH", { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
);

const formatDate = (value: string): string => {
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? value : date.toLocaleString("en-PH", { dateStyle: "medium", timeStyle: "short" });
};

export default function OwnerApprovalHistoryList({ items, onReview, selectedAttentionKey = null }: OwnerApprovalHistoryListProps) {
  if (items.length === 0) return null;

  return (
    <ul aria-label="Owner approval history" className="divide-y divide-gray-200 overflow-hidden rounded-xl border border-gray-200 dark:divide-gray-800 dark:border-gray-800">
      {items.map((item) => {
        const amount = formatAmount(item.comparable_monetary_exposure);
        const selected = item.attention_key === selectedAttentionKey;

        return (
          <li key={item.attention_key} className={selected ? "bg-blue-50/70 dark:bg-blue-900/10" : "bg-white dark:bg-white/[0.02]"}>
            <div className="flex flex-col gap-4 p-4 sm:flex-row sm:items-center sm:justify-between sm:p-5">
              <div className="min-w-0">
                <div className="flex flex-wrap items-center gap-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                  <span>{sourceLabels[item.coverage_source]}</span>
                  <span aria-hidden="true">&middot;</span>
                  <span className={item.status === "approved" ? "text-emerald-700 dark:text-emerald-300" : "text-rose-700 dark:text-rose-300"}>
                    {item.status === "approved" ? "Approved" : "Rejected"}
                  </span>
                </div>
                <h3 className="mt-1 truncate text-base font-semibold text-gray-900 dark:text-white">{item.title}</h3>
                <p className="mt-1 text-sm text-gray-600 dark:text-gray-300">{item.concise_summary}</p>
                <div className="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-xs text-gray-500 dark:text-gray-400">
                  <span>Decided {formatDate(item.decision_at)}</span>
                  {amount && <span>{amount}</span>}
                  {item.reviewed_by && <span>By {item.reviewed_by}</span>}
                </div>
                {item.comments && <p className="mt-2 text-xs text-gray-500 dark:text-gray-400">Note: {item.comments}</p>}
              </div>
              <button
                type="button"
                aria-label={`View ${item.title} approval details`}
                data-attention-key={item.attention_key}
                onClick={() => onReview(item)}
                className="inline-flex min-h-11 min-w-11 shrink-0 items-center justify-center self-start rounded-lg border border-gray-300 text-blue-600 transition hover:bg-blue-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2 dark:border-gray-700 dark:text-blue-300 dark:hover:bg-blue-900/20"
              >
                <svg aria-hidden="true" viewBox="0 0 24 24" className="h-5 w-5 fill-none stroke-current" strokeWidth="1.8">
                  <path strokeLinecap="round" strokeLinejoin="round" d="M2.5 12s3.4-6 9.5-6 9.5 6 9.5 6-3.4 6-9.5 6-9.5-6-9.5-6Z" />
                  <circle cx="12" cy="12" r="2.5" />
                </svg>
                <span className="sr-only">View details</span>
              </button>
            </div>
          </li>
        );
      })}
    </ul>
  );
}
