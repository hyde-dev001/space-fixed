import type { OwnerAttentionItem, OwnerAttentionSourceType } from "../../types/ownerActionCenter";

interface OwnerAttentionListProps {
  items: OwnerAttentionItem[];
}

const sourceLabels: Record<OwnerAttentionSourceType, string> = {
  order_refund: "Order Refund",
  repair_refund: "Repair Refund",
  expense: "Expense",
  purchase_request: "Purchase Request",
};

const currencyFormatter = new Intl.NumberFormat("en-PH", {
  style: "currency",
  currency: "PHP",
  minimumFractionDigits: 2,
});

const titleCase = (value: string): string => value.charAt(0).toUpperCase() + value.slice(1);

const shortDate = (value: string): string => value.slice(0, 10);

const formatExposure = (value: number | null): string => {
  if (value === null) {
    return "Exposure not comparable";
  }

  return currencyFormatter.format(value);
};

export default function OwnerAttentionList({ items }: OwnerAttentionListProps) {
  if (items.length === 0) {
    return null;
  }

  return (
    <ol aria-label="Owner decisions" className="divide-y divide-gray-200 dark:divide-gray-800">
      {items.map((item) => (
        <li key={item.attention_key} className="py-4 first:pt-0 last:pb-0">
          <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between sm:gap-6">
            <div className="min-w-0 space-y-1">
              <div className="flex flex-wrap items-center gap-2 text-xs font-medium text-gray-500 dark:text-gray-400">
                <span className="rounded-full bg-blue-50 px-2.5 py-1 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300">
                  {sourceLabels[item.source_type]}
                </span>
                <span>Priority: {titleCase(item.priority_tier)}</span>
                <span>Exposure: {formatExposure(item.comparable_monetary_exposure)}</span>
              </div>
              <h3 className="text-sm font-semibold text-gray-800 dark:text-white/90">{item.title}</h3>
              <p className="text-sm text-gray-600 dark:text-gray-300">{item.concise_summary}</p>
              <p className="text-xs text-gray-500 dark:text-gray-400">
                {item.urgency_at ? "Due" : "Actionable since"}{" "}
                <time dateTime={item.urgency_at ?? item.actionable_since}>
                  {shortDate(item.urgency_at ?? item.actionable_since)}
                </time>
              </p>
            </div>
            <a
              href={item.destination_url}
              className="inline-flex shrink-0 items-center text-sm font-semibold text-blue-600 underline-offset-4 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2 dark:text-blue-400 dark:focus-visible:ring-offset-gray-900"
            >
              Open workflow
            </a>
          </div>
        </li>
      ))}
    </ol>
  );
}
