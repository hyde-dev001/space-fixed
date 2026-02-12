import React from 'react';

/**
 * Get badge component for approval status
 * Shows visual indicator for transaction status (draft, submitted, approved, posted, rejected)
 */
export const getApprovalStatusBadge = (
  isInline: boolean = false,
  status: 'draft' | 'submitted' | 'approved' | 'posted' | 'rejected' | 'sent' | 'paid' | 'overdue' | 'cancelled'
): React.ReactNode => {
  const getStatusColor = (status: string) => {
    const colors: Record<string, string> = {
      draft: "bg-gray-100 text-gray-700 dark:bg-gray-900/30 dark:text-gray-400",
      submitted: "bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400",
      approved: "bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400",
      posted: "bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400",
      rejected: "bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400",
      sent: "bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400",
      paid: "bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400",
      overdue: "bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400",
      cancelled: "bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400",
    };
    return colors[status] || "bg-gray-100 text-gray-800";
  };

  const getStatusIcon = (status: string) => {
    switch (status) {
      case 'approved':
      case 'posted':
      case 'paid':
        return '✓';
      case 'rejected':
        return '✕';
      case 'submitted':
      case 'sent':
        return '→';
      case 'overdue':
        return '!';
      default:
        return '○';
    }
  };

  const displayText = status.charAt(0).toUpperCase() + status.slice(1);

  return (
    <span className={`inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-semibold whitespace-nowrap ${getStatusColor(status)}`}>
      <span>{getStatusIcon(status)}</span>
      {displayText}
    </span>
  );
};

/**
 * Inline approval actions component
 * Provides approve/reject buttons for transactions
 */
interface InlineApprovalActionsProps {
  transactionId: string | number;
  transactionType: 'expense' | 'invoice';
  requiresApproval: boolean;
  status: string;
  amount: number;
  userRole?: string;
  userApprovalLimit?: number;
  onApprovalSuccess?: () => void;
}

export const InlineApprovalActions: React.FC<InlineApprovalActionsProps> = ({
  transactionId,
  transactionType,
  requiresApproval,
  status,
  amount,
  userRole,
  userApprovalLimit,
  onApprovalSuccess,
}) => {
  const [isLoading, setIsLoading] = React.useState(false);
  const canApprove = userRole && userApprovalLimit && amount <= userApprovalLimit;

  const handleApprove = async () => {
    if (!canApprove) return;

    setIsLoading(true);
    try {
      const endpoint = transactionType === 'expense'
        ? `/api/finance/expenses/${transactionId}/approve`
        : `/api/finance/invoices/${transactionId}/approve`;

      const response = await fetch(endpoint, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
        },
        credentials: 'include',
      });

      if (response.ok) {
        onApprovalSuccess?.();
      }
    } catch (error) {
      console.error('Approval failed:', error);
    } finally {
      setIsLoading(false);
    }
  };

  const handleReject = async () => {
    setIsLoading(true);
    try {
      const endpoint = transactionType === 'expense'
        ? `/api/finance/expenses/${transactionId}/reject`
        : `/api/finance/invoices/${transactionId}/reject`;

      const response = await fetch(endpoint, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
        },
        credentials: 'include',
      });

      if (response.ok) {
        onApprovalSuccess?.();
      }
    } catch (error) {
      console.error('Rejection failed:', error);
    } finally {
      setIsLoading(false);
    }
  };

  if (status !== 'submitted') {
    return null;
  }

  return (
    <div className="flex items-center gap-2">
      <button
        onClick={handleApprove}
        disabled={!canApprove || isLoading}
        className={`inline-flex items-center justify-center w-8 h-8 rounded-lg transition-colors ${
          canApprove && !isLoading
            ? 'text-green-600 dark:text-green-400 hover:text-green-800 dark:hover:text-green-300 hover:bg-green-50 dark:hover:bg-green-900/30'
            : 'text-gray-400 dark:text-gray-500 cursor-not-allowed'
        }`}
        title="Approve"
      >
        {isLoading ? (
          <span className="animate-spin">↻</span>
        ) : (
          <svg className="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
          </svg>
        )}
      </button>
      <button
        onClick={handleReject}
        disabled={isLoading}
        className={`inline-flex items-center justify-center w-8 h-8 rounded-lg transition-colors ${
          !isLoading
            ? 'text-red-600 dark:text-red-400 hover:text-red-800 dark:hover:text-red-300 hover:bg-red-50 dark:hover:bg-red-900/30'
            : 'text-gray-400 dark:text-gray-500 cursor-not-allowed'
        }`}
        title="Reject"
      >
        {isLoading ? (
          <span className="animate-spin">↻</span>
        ) : (
          <svg className="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z" />
          </svg>
        )}
      </button>
    </div>
  );
};

/**
 * Approval limit info component
 * Shows user's approval authority
 */
interface ApprovalLimitInfoProps {
  userRole?: string;
  userApprovalLimit?: number;
  transactionAmount: number;
}

export const ApprovalLimitInfo: React.FC<ApprovalLimitInfoProps> = ({
  userRole,
  userApprovalLimit = 0,
  transactionAmount,
}) => {
  const canApprove = transactionAmount <= userApprovalLimit;

  if (!userApprovalLimit || userApprovalLimit === 0) {
    return null;
  }

  return (
    <div className={`text-xs p-2 rounded ${
      canApprove
        ? 'bg-green-50 text-green-700 dark:bg-green-900/30 dark:text-green-400'
        : 'bg-red-50 text-red-700 dark:bg-red-900/30 dark:text-red-400'
    }`}>
      <span className="font-semibold">Approval Limit:</span> ₱{userApprovalLimit.toLocaleString()}
      {!canApprove && (
        <span className="block">⚠️ Amount exceeds your approval limit</span>
      )}
    </div>
  );
};
