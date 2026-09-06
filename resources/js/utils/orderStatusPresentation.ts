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
    badgeClass: "text-gray-900 dark:text-gray-100",
  },
  processing: {
    label: "Processing",
    badgeClass: "text-gray-900 dark:text-gray-100",
  },
  shipped: {
    label: "Shipped",
    badgeClass: "text-gray-900 dark:text-gray-100",
  },
  delivered: {
    label: "Delivered",
    badgeClass: "text-gray-900 dark:text-gray-100",
  },
  completed: {
    label: "Completed",
    badgeClass: "text-gray-900 dark:text-gray-100",
  },
  cancelled: {
    label: "Cancelled",
    badgeClass: "text-gray-900 dark:text-gray-100",
  },
  refund: {
    label: "Refund",
    badgeClass: "text-gray-900 dark:text-gray-100",
  },
};

const FALLBACK_PRESENTATION: OrderStatusPresentation = {
  label: "Unknown",
  badgeClass: "text-gray-900 dark:text-gray-100",
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
