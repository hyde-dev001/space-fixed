export type OrderStatus =
  | "pending"
  | "processing"
  | "shipped"
  | "delivered"
  | "completed"
  | "cancelled"
  | "refund";

export type OrderAction = "processing" | "shipped" | "completed";

export type OrderStatusPresentation = {
  label: string;
  badgeClass: string;
};

export const ORDER_STATUS_PRESENTATION: Record<OrderStatus, OrderStatusPresentation> = {
  pending: {
    label: "Pending",
    badgeClass: "bg-amber-50 text-amber-700 ring-1 ring-inset ring-amber-200 dark:bg-amber-900/20 dark:text-amber-300 dark:ring-amber-700/40",
  },
  processing: {
    label: "Processing",
    badgeClass: "bg-blue-50 text-blue-700 ring-1 ring-inset ring-blue-200 dark:bg-blue-900/20 dark:text-blue-300 dark:ring-blue-700/40",
  },
  shipped: {
    label: "Shipped",
    badgeClass: "bg-indigo-50 text-indigo-700 ring-1 ring-inset ring-indigo-200 dark:bg-indigo-900/20 dark:text-indigo-300 dark:ring-indigo-700/40",
  },
  delivered: {
    label: "Delivered",
    badgeClass: "bg-emerald-50 text-emerald-700 ring-1 ring-inset ring-emerald-200 dark:bg-emerald-900/20 dark:text-emerald-300 dark:ring-emerald-700/40",
  },
  completed: {
    label: "Completed",
    badgeClass: "bg-green-50 text-green-700 ring-1 ring-inset ring-green-200 dark:bg-green-900/20 dark:text-green-300 dark:ring-green-700/40",
  },
  cancelled: {
    label: "Cancelled",
    badgeClass: "bg-rose-50 text-rose-700 ring-1 ring-inset ring-rose-200 dark:bg-rose-900/20 dark:text-rose-300 dark:ring-rose-700/40",
  },
  refund: {
    label: "Refund",
    badgeClass: "bg-orange-50 text-orange-700 ring-1 ring-inset ring-orange-200 dark:bg-orange-900/20 dark:text-orange-300 dark:ring-orange-700/40",
  },
};

const FALLBACK_PRESENTATION: OrderStatusPresentation = {
  label: "Unknown",
  badgeClass: "bg-gray-100 text-gray-800 ring-1 ring-inset ring-gray-200",
};

export const getOrderStatusPresentation = (status: string): OrderStatusPresentation =>
  ORDER_STATUS_PRESENTATION[status.trim().toLowerCase() as OrderStatus] || FALLBACK_PRESENTATION;

export const parseOrderActions = (value: unknown): OrderAction[] =>
  Array.isArray(value)
    ? value.filter(
        (action): action is OrderAction =>
          action === "processing" || action === "shipped" || action === "completed",
      )
    : [];
