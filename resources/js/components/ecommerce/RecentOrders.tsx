import {
  Table,
  TableBody,
  TableCell,
  TableHeader,
  TableRow,
} from "../ui/table";
import Badge from "../ui/badge/Badge";

// Define the TypeScript interface for order items
interface OrderItem {
  id: number;
  product_id: number;
  quantity: number;
  price: number;
  product?: {
    id: number;
    name: string;
    images: string | null;
  };
}

// Define the TypeScript interface for orders
interface Order {
  id: number;
  order_number: string;
  customer_name: string;
  customer_email: string;
  total_amount: number;
  status: string;
  created_at: string;
  items_count?: number;
  order_items?: OrderItem[];
}

interface RecentOrdersProps {
  orders?: Order[];
}

export default function RecentOrders({ orders = [] }: RecentOrdersProps) {
  const FALLBACK_PRODUCT_IMAGE = '/images/product/product-01.jpg';

  // Format currency
  const formatCurrency = (amount: number) => {
    return new Intl.NumberFormat('en-PH', {
      style: 'currency',
      currency: 'PHP',
      minimumFractionDigits: 2
    }).format(amount);
  };

  // Format date
  const formatDate = (dateString: string) => {
    return new Date(dateString).toLocaleDateString('en-PH', {
      year: 'numeric',
      month: 'short',
      day: 'numeric'
    });
  };

  // Get status color
  const getStatusColor = (status: string) => {
    switch (status.toLowerCase()) {
      case 'completed':
      case 'delivered':
        return 'success';
      case 'pending':
      case 'processing':
        return 'warning';
      case 'shipped':
        return 'info';
      case 'cancelled':
      case 'canceled':
        return 'error';
      case 'refund':
      case 'refunded':
        return 'error';
      case 'partially_refunded':
        return 'warning';
      default:
        return 'default';
    }
  };

  // Capitalize first letter
  const formatStatusLabel = (status: string) => {
    const normalized = status.toLowerCase();
    if (normalized === 'refund' || normalized === 'refunded') return 'Refunded';
    if (normalized === 'partially_refunded') return 'Partially Refunded';
    return status.charAt(0).toUpperCase() + status.slice(1).toLowerCase();
  };

  // Get first product image
  const normalizeImageUrl = (path: string) => {
    const trimmed = path.trim();

    if (!trimmed) {
      return FALLBACK_PRODUCT_IMAGE;
    }

    if (trimmed.startsWith('http://') || trimmed.startsWith('https://') || trimmed.startsWith('data:')) {
      return trimmed;
    }

    if (trimmed.startsWith('/storage/') || trimmed.startsWith('/images/')) {
      return trimmed;
    }

    if (trimmed.startsWith('storage/')) {
      return `/${trimmed}`;
    }

    return `/storage/${trimmed}`;
  };

  const getFirstProductImage = (order: Order) => {
    const firstItem = order.order_items?.[0];
    if (firstItem?.product?.images) {
      try {
        const parsed = JSON.parse(firstItem.product.images);

        if (Array.isArray(parsed) && parsed.length > 0) {
          return normalizeImageUrl(parsed[0]);
        }

        if (typeof parsed === 'string') {
          return normalizeImageUrl(parsed);
        }

        return FALLBACK_PRODUCT_IMAGE;
      } catch {
        return normalizeImageUrl(firstItem.product.images);
      }
    }
    return FALLBACK_PRODUCT_IMAGE;
  };

  const getPrimaryOrderLabel = (order: Order) => {
    return order.order_items?.[0]?.product?.name || 'Order item';
  };

  const getItemsCount = (order: Order) => {
    if (typeof order.items_count === 'number') {
      return order.items_count;
    }

    return order.order_items?.length || 0;
  };

  return (
    <div className="overflow-hidden rounded-2xl border border-gray-200 bg-white px-4 pb-3 pt-4 dark:border-gray-800 dark:bg-white/[0.03] sm:px-6">
      <div className="mb-4">
        <div>
          <h3 className="text-lg font-semibold text-gray-800 dark:text-white/90">
            Recent Orders
          </h3>
        </div>
      </div>
      <div className="max-w-full overflow-x-auto">
        {orders.length === 0 ? (
          <div className="py-8 text-center text-gray-500 dark:text-gray-400">
            No recent orders found
          </div>
        ) : (
          <Table>
            {/* Table Header */}
            <TableHeader className="border-gray-100 dark:border-gray-800 border-y">
              <TableRow>
                <TableCell
                  isHeader
                  className="py-3 font-medium text-gray-500 text-start text-theme-xs dark:text-gray-400"
                >
                  Order
                </TableCell>
                <TableCell
                  isHeader
                  className="py-3 font-medium text-gray-500 text-start text-theme-xs dark:text-gray-400"
                >
                  Customer
                </TableCell>
                <TableCell
                  isHeader
                  className="py-3 font-medium text-gray-500 text-start text-theme-xs dark:text-gray-400"
                >
                  Date
                </TableCell>
                <TableCell
                  isHeader
                  className="py-3 font-medium text-gray-500 text-start text-theme-xs dark:text-gray-400"
                >
                  Amount
                </TableCell>
                <TableCell
                  isHeader
                  className="py-3 font-medium text-gray-500 text-start text-theme-xs dark:text-gray-400"
                >
                  Status
                </TableCell>
              </TableRow>
            </TableHeader>

            {/* Table Body */}
            <TableBody className="divide-y divide-gray-100 dark:divide-gray-800">
              {orders.map((order) => (
                <TableRow key={order.id} className="">
                  <TableCell className="py-3">
                    <div className="flex items-center gap-3">
                      <div className="h-[50px] w-[50px] overflow-hidden rounded-md">
                        <img
                          src={getFirstProductImage(order)}
                          className="h-[50px] w-[50px] object-cover"
                          alt={getPrimaryOrderLabel(order)}
                          onError={(e) => {
                            e.currentTarget.src = FALLBACK_PRODUCT_IMAGE;
                          }}
                        />
                      </div>
                      <div>
                        <p className="font-medium text-gray-800 text-theme-sm dark:text-white/90">
                          {getPrimaryOrderLabel(order)}
                        </p>
                        <span className="text-gray-500 text-theme-xs dark:text-gray-400">
                          {getItemsCount(order)} item{getItemsCount(order) !== 1 ? 's' : ''}
                        </span>
                      </div>
                    </div>
                  </TableCell>
                  <TableCell className="py-3 text-gray-500 text-theme-sm dark:text-gray-400">
                    <div>
                      <p className="font-medium text-gray-800 dark:text-white/90">
                        {order.customer_name}
                      </p>
                      <span className="text-theme-xs text-gray-500 dark:text-gray-400">
                        {order.customer_email}
                      </span>
                    </div>
                  </TableCell>
                  <TableCell className="py-3 text-gray-500 text-theme-sm dark:text-gray-400">
                    {formatDate(order.created_at)}
                  </TableCell>
                  <TableCell className="py-3 text-gray-800 font-medium text-theme-sm dark:text-white/90">
                    {formatCurrency(order.total_amount)}
                  </TableCell>
                  <TableCell className="py-3 text-gray-500 text-theme-sm dark:text-gray-400">
                    <Badge
                      size="sm"
                      color={getStatusColor(order.status)}
                    >
                      {formatStatusLabel(order.status)}
                    </Badge>
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        )}
      </div>
    </div>
  );
}

