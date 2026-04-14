import { useEffect, useMemo, useState } from "react";
import { Head } from "@inertiajs/react";
import Swal from "sweetalert2";
import axios from "axios";
import AppLayoutShopOwner from "../../../../layout/AppLayout_shopOwner";
import ErrorModal from "../../../../components/common/ErrorModal";

type WarrantyStatusFilter = "all" | "pending_repairer" | "approved" | "rejected" | "expired";

type RepairWarrantyClaimQueueItem = {
  id: number;
  claim_no: string;
  status: string;
  reason_code: string;
  reason_details?: string | null;
  source_channel?: string | null;
  evidence_media?: string[];
  created_at?: string | null;
  warranty_expires_at_snapshot?: string | null;
  original_repair: {
    id: number;
    request_id: string;
    customer_name: string;
    status: string;
  };
};

type RepairWarrantyKpi = {
  window_days: number;
  total_claims: number;
  pending_count: number;
  approved_count: number;
  rejected_count: number;
  expired_count: number;
  from_pos_count: number;
  from_customer_portal_count: number;
  approval_rate: number;
  average_review_hours: number;
};

const STATUS_OPTIONS: Array<{ label: string; value: WarrantyStatusFilter }> = [
  { label: "All Status", value: "all" },
  { label: "Pending", value: "pending_repairer" },
  { label: "Approved", value: "approved" },
  { label: "Rejected", value: "rejected" },
  { label: "Expired", value: "expired" },
];

type ChangeType = "increase" | "decrease";
type MetricColor = "success" | "warning" | "info";

interface MetricCardProps {
  title: string;
  value: number | string;
  change: number;
  changeType: ChangeType;
  icon: ({ className }: { className?: string }) => JSX.Element;
  color: MetricColor;
  description: string;
}

const CheckIcon = ({ className }: { className?: string }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" />
  </svg>
);

const XIcon = ({ className }: { className?: string }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
  </svg>
);

const EyeIcon = ({ className }: { className?: string }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
  </svg>
);

const ClockIcon = ({ className }: { className?: string }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
  </svg>
);

const ReceiptIcon = ({ className }: { className?: string }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
  </svg>
);

const ArrowUpIcon = ({ className }: { className?: string }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 10l7-7m0 0l7 7m-7-7v18" />
  </svg>
);

const ArrowDownIcon = ({ className }: { className?: string }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 14l-7 7m0 0l-7-7m7 7V3" />
  </svg>
);

const AlertTriangleIcon = ({ className }: { className?: string }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
  </svg>
);

const MetricCard = ({ title, value, change, changeType, icon: Icon, color, description }: MetricCardProps) => {
  const getColorClasses = () => {
    switch (color) {
      case "success":
        return "from-green-500 to-emerald-600";
      case "warning":
        return "from-yellow-500 to-orange-600";
      case "info":
        return "from-blue-500 to-indigo-600";
      default:
        return "from-gray-500 to-gray-600";
    }
  };

  return (
    <div className="group relative overflow-hidden rounded-2xl border border-gray-200 bg-white p-6 shadow-sm transition-all duration-500 hover:-translate-y-1 hover:border-gray-300 hover:shadow-xl dark:border-gray-800 dark:bg-white/3 dark:hover:border-gray-700">
      <div className={`absolute inset-0 bg-linear-to-br ${getColorClasses()} opacity-0 transition-opacity duration-500 group-hover:opacity-5`} />
      <div className="relative">
        <div className="mb-4 flex items-center justify-between">
          <div className={`flex h-14 w-14 items-center justify-center rounded-2xl bg-linear-to-br ${getColorClasses()} shadow-lg transition-all duration-300 group-hover:scale-110 group-hover:rotate-6`}>
            <Icon className="size-7 text-white drop-shadow-sm" />
          </div>
          <div
            className={`flex items-center gap-1 rounded-full px-3 py-1 text-xs font-semibold transition-all duration-300 ${
              changeType === "increase"
                ? "bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400"
                : "bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400"
            }`}
          >
            {changeType === "increase" ? <ArrowUpIcon className="size-3" /> : <ArrowDownIcon className="size-3" />}
            {Math.abs(change)}%
          </div>
        </div>
        <div className="space-y-2">
          <p className="text-sm font-medium text-gray-600 dark:text-gray-400">{title}</p>
          <h3 className="text-3xl font-bold text-gray-900 transition-colors duration-300 dark:text-white">{value}</h3>
          <p className="text-xs text-gray-500 dark:text-gray-400">{description}</p>
        </div>
      </div>
    </div>
  );
};

const getStatusBadgeClass = (status: string): string => {
  switch (String(status).toLowerCase()) {
    case "pending_repairer":
      return "bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-300";
    case "approved":
      return "bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-300";
    case "rejected":
      return "bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-300";
    case "expired":
      return "bg-gray-200 text-gray-700 dark:bg-gray-800 dark:text-gray-300";
    default:
      return "bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300";
  }
};

const humanizeReasonCode = (value: string | null | undefined): string => {
  const normalized = String(value ?? "").trim();
  if (!normalized) {
    return "Unspecified";
  }

  return normalized
    .split("_")
    .map((part) => part.charAt(0).toUpperCase() + part.slice(1).toLowerCase())
    .join(" ");
};

const normalizeWarrantyEvidenceUrl = (raw: unknown): string | null => {
  const value = String(raw ?? "").trim();
  if (!value) {
    return null;
  }

  if (value.startsWith("http://") || value.startsWith("https://") || value.startsWith("/")) {
    return value;
  }

  return `/storage/${value.replace(/^\/+/, "")}`;
};

const formatWarrantyDateTime = (value: string | null | undefined): string => {
  const normalized = String(value ?? "").trim();
  if (!normalized) {
    return "N/A";
  }

  // API already returns PHP-timezone formatted datetime for queue records.
  if (/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(normalized)) {
    return normalized;
  }

  const parsed = new Date(normalized);
  if (Number.isNaN(parsed.getTime())) {
    return normalized;
  }

  return parsed.toLocaleString("en-PH", { timeZone: "Asia/Manila" });
};

export default function WarrantyQueue() {
  const [error, setError] = useState<string | null>(null);
  const [statusFilter, setStatusFilter] = useState<WarrantyStatusFilter>("all");
  const [searchQuery, setSearchQuery] = useState("");
  const [currentPage, setCurrentPage] = useState(1);
  const [claims, setClaims] = useState<RepairWarrantyClaimQueueItem[]>([]);
  const [kpi, setKpi] = useState<RepairWarrantyKpi>({
    window_days: 30,
    total_claims: 0,
    pending_count: 0,
    approved_count: 0,
    rejected_count: 0,
    expired_count: 0,
    from_pos_count: 0,
    from_customer_portal_count: 0,
    approval_rate: 0,
    average_review_hours: 0,
  });
  const [isLoading, setIsLoading] = useState(false);
  const [queueError, setQueueError] = useState<string | null>(null);
  const [actionClaimId, setActionClaimId] = useState<number | null>(null);
  const [isClaimDetailsOpen, setIsClaimDetailsOpen] = useState(false);
  const [selectedClaim, setSelectedClaim] = useState<RepairWarrantyClaimQueueItem | null>(null);
  const [modalRejectionReason, setModalRejectionReason] = useState("");

  const fetchWarrantyClaimQueue = async (status: WarrantyStatusFilter) => {
    try {
      setIsLoading(true);
      setQueueError(null);

      const response = await axios.get("/api/shop-owner/warranty-claims", {
        params: status === "all" ? {} : { status },
      });

      if (!response.data?.success) {
        setClaims([]);
        return;
      }

      const rawClaims = Array.isArray(response.data.data) ? response.data.data : [];
      const mappedClaims: RepairWarrantyClaimQueueItem[] = rawClaims
        .map((claim: any): RepairWarrantyClaimQueueItem | null => {
          const claimId = Number(claim?.id);
          if (!Number.isFinite(claimId) || claimId <= 0) {
            return null;
          }

          return {
            id: claimId,
            claim_no: String(claim?.claim_no ?? `WCLM-${claimId}`),
            status: String(claim?.status ?? "pending_repairer"),
            reason_code: String(claim?.reason_code ?? ""),
            reason_details: claim?.reason_details ? String(claim.reason_details) : null,
            source_channel: claim?.source_channel ? String(claim.source_channel) : null,
            evidence_media: Array.isArray(claim?.evidence_media) ? claim.evidence_media : [],
            created_at: claim?.created_at ? String(claim.created_at) : null,
            warranty_expires_at_snapshot: claim?.warranty_expires_at_snapshot
              ? String(claim.warranty_expires_at_snapshot)
              : null,
            original_repair: {
              id: Number(claim?.original_repair?.id ?? 0),
              request_id: String(claim?.original_repair?.request_id ?? ""),
              customer_name: String(claim?.original_repair?.customer_name ?? "Customer"),
              status: String(claim?.original_repair?.status ?? ""),
            },
          };
        })
        .filter((claim: RepairWarrantyClaimQueueItem | null): claim is RepairWarrantyClaimQueueItem => claim !== null);

      setClaims(mappedClaims);
    } catch (claimError: any) {
      setClaims([]);
      setQueueError(claimError?.response?.data?.message || "Failed to load warranty queue.");
    } finally {
      setIsLoading(false);
    }
  };

  const fetchWarrantyKpi = async () => {
    try {
      const response = await axios.get("/api/shop-owner/warranty-claims/kpi", {
        params: { days: 30 },
      });

      if (!response.data?.success || !response.data?.data) {
        return;
      }

      const payload = response.data.data;
      setKpi({
        window_days: Number(payload.window_days ?? 30),
        total_claims: Number(payload.total_claims ?? 0),
        pending_count: Number(payload.pending_count ?? 0),
        approved_count: Number(payload.approved_count ?? 0),
        rejected_count: Number(payload.rejected_count ?? 0),
        expired_count: Number(payload.expired_count ?? 0),
        from_pos_count: Number(payload.from_pos_count ?? 0),
        from_customer_portal_count: Number(payload.from_customer_portal_count ?? 0),
        approval_rate: Number(payload.approval_rate ?? 0),
        average_review_hours: Number(payload.average_review_hours ?? 0),
      });
    } catch {
      // Keep stale KPI values when endpoint is unavailable.
    }
  };

  const handleWarrantyClaimDecision = async (
    claim: RepairWarrantyClaimQueueItem,
    action: "approve" | "reject"
  ) => {
    const isApprove = action === "approve";

    let payload: Record<string, string> = {};

    if (!isApprove) {
      const trimmedReason = modalRejectionReason.trim();
      if (!trimmedReason) {
        await Swal.fire({
          title: "Rejection reason is required",
          text: "Please provide a reason before rejecting this claim.",
          icon: "warning",
          confirmButtonColor: "#2563eb",
        });
        return;
      }

      payload = {
        rejection_reason: trimmedReason,
      };
    }

    setActionClaimId(claim.id);

    try {
      await axios.post(`/api/shop-owner/warranty-claims/${claim.id}/${action}`, payload);

      await Promise.all([
        fetchWarrantyClaimQueue(statusFilter),
        fetchWarrantyKpi(),
      ]);

      setIsClaimDetailsOpen(false);
      setSelectedClaim(null);
      setModalRejectionReason("");

      await Swal.fire({
        title: isApprove ? "Warranty Claim Approved" : "Warranty Claim Rejected",
        text: isApprove
          ? "Linked no-charge warranty repair has been created."
          : "Customer has been notified about the rejection.",
        icon: "success",
        confirmButtonColor: "#2563eb",
      });
    } catch (decisionError: any) {
      await Swal.fire({
        title: "Unable to process warranty claim",
        text: decisionError?.response?.data?.message || "Please try again.",
        icon: "error",
        confirmButtonColor: "#2563eb",
      });
    } finally {
      setActionClaimId(null);
    }
  };

  const openWarrantyClaimDetails = (claim: RepairWarrantyClaimQueueItem) => {
    setSelectedClaim(claim);
    setModalRejectionReason("");
    setIsClaimDetailsOpen(true);
  };

  const closeWarrantyClaimDetails = () => {
    if (selectedClaim && actionClaimId === selectedClaim.id) {
      return;
    }

    setIsClaimDetailsOpen(false);
    setSelectedClaim(null);
    setModalRejectionReason("");
  };

  useEffect(() => {
    void fetchWarrantyClaimQueue(statusFilter);
    void fetchWarrantyKpi();

    const intervalId = window.setInterval(() => {
      void fetchWarrantyClaimQueue(statusFilter);
      void fetchWarrantyKpi();
    }, 10000);

    return () => {
      window.clearInterval(intervalId);
    };
  }, [statusFilter]);

  const filteredClaims = useMemo(() => {
    const query = searchQuery.trim().toLowerCase();
    if (!query) {
      return claims;
    }

    return claims.filter((claim) => {
      const requestId = String(claim.original_repair.request_id || "").toLowerCase();
      const customer = String(claim.original_repair.customer_name || "").toLowerCase();
      const claimNo = String(claim.claim_no || "").toLowerCase();
      const reason = humanizeReasonCode(claim.reason_code).toLowerCase();

      return (
        claimNo.includes(query)
        || requestId.includes(query)
        || customer.includes(query)
        || reason.includes(query)
      );
    });
  }, [claims, searchQuery]);

  const selectedClaimEvidenceMedia = useMemo(() => {
    if (!selectedClaim) {
      return [];
    }

    return (selectedClaim.evidence_media || [])
      .map((media) => normalizeWarrantyEvidenceUrl(media))
      .filter((media): media is string => Boolean(media));
  }, [selectedClaim]);

  const itemsPerPage = 6;
  const totalPages = Math.max(1, Math.ceil(filteredClaims.length / itemsPerPage));
  const startIndex = (currentPage - 1) * itemsPerPage;
  const paginatedClaims = filteredClaims.slice(startIndex, startIndex + itemsPerPage);

  useEffect(() => {
    setCurrentPage(1);
  }, [statusFilter, searchQuery]);

  return (
    <AppLayoutShopOwner hideHeader={isClaimDetailsOpen}>
      <Head title="Warranty Queue" />
      {error && <ErrorModal message={error} onClose={() => setError(null)} />}

      <div className="space-y-6 p-6">
        <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
          <div>
            <h1 className="mb-1 text-2xl font-semibold text-gray-900 dark:text-white">Warranty Queue</h1>
            <p className="text-gray-600 dark:text-gray-400">
              Review warranty claims from customer portal and POS walk-in submissions.
            </p>
          </div>
          <button
            type="button"
            onClick={() => {
              void fetchWarrantyClaimQueue(statusFilter);
              void fetchWarrantyKpi();
            }}
            className="w-fit rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 transition hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200"
          >
            Refresh Queue
          </button>
        </div>

        <div className="grid grid-cols-1 gap-6 md:grid-cols-2 xl:grid-cols-4">
          <MetricCard
            title="Pending Claims"
            value={kpi.pending_count}
            change={0}
            changeType="increase"
            icon={ClockIcon}
            color="warning"
            description="Awaiting your review"
          />
          <MetricCard
            title="Approved"
            value={kpi.approved_count}
            change={0}
            changeType="increase"
            icon={CheckIcon}
            color="success"
            description={`Within ${kpi.window_days} days`}
          />
          <MetricCard
            title="Rejected"
            value={kpi.rejected_count}
            change={0}
            changeType="decrease"
            icon={XIcon}
            color="info"
            description="Needs customer follow-up"
          />
          <MetricCard
            title="Expired"
            value={kpi.expired_count}
            change={0}
            changeType="decrease"
            icon={AlertTriangleIcon}
            color="warning"
            description="Outside warranty window"
          />
        </div>

        <div className="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-800 dark:bg-gray-900">
          <div className="mb-4">
            <h2 className="text-lg font-semibold text-gray-900 dark:text-white">Warranty Claims</h2>
            <p className="text-sm text-gray-500 dark:text-gray-400">Review and take action on warranty submissions</p>
          </div>

          <div className="mb-4 flex flex-col gap-3 sm:flex-row">
            <div className="flex-1">
              <input
                type="text"
                placeholder="Search by claim no, repair request, customer, or reason..."
                value={searchQuery}
                onChange={(event) => setSearchQuery(event.target.value)}
                className="w-full rounded-lg border border-gray-300 bg-white px-4 py-2 text-gray-900 placeholder-gray-400 focus:outline-none focus:border-blue-500 dark:border-gray-600 dark:bg-gray-800 dark:text-white dark:placeholder-gray-500 dark:focus:border-blue-400"
              />
            </div>
            <div className="sm:w-52">
              <select
                aria-label="Filter warranty claims by status"
                value={statusFilter}
                onChange={(event) => setStatusFilter(event.target.value as WarrantyStatusFilter)}
                className="w-full rounded-lg border border-gray-300 bg-white px-4 py-2 text-gray-900 focus:outline-none focus:border-blue-500 dark:border-gray-600 dark:bg-gray-800 dark:text-white dark:focus:border-blue-400"
              >
                {STATUS_OPTIONS.map((option) => (
                  <option key={option.value} value={option.value}>
                    {option.label}
                  </option>
                ))}
              </select>
            </div>
          </div>

          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead className="border-b border-gray-200 text-left text-gray-500 dark:border-gray-800 dark:text-gray-400">
                <tr>
                  <th className="pb-3 font-medium">Claim No</th>
                  <th className="pb-3 font-medium">Repair Request</th>
                  <th className="pb-3 font-medium">Customer</th>
                  <th className="pb-3 font-medium">Reason</th>
                  <th className="pb-3 font-medium">Source</th>
                  <th className="pb-3 font-medium">Submitted</th>
                  <th className="pb-3 font-medium">Status</th>
                  <th className="pb-3 text-right font-medium">Action</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-100 dark:divide-gray-800">
                {paginatedClaims.map((claim) => (
                  <tr key={claim.id} className="transition-colors hover:bg-gray-50 dark:hover:bg-gray-800/50">
                    <td className="py-4 text-gray-700 dark:text-gray-300">{claim.claim_no}</td>
                    <td className="py-4 text-gray-700 dark:text-gray-300">
                      {claim.original_repair.request_id || `Repair #${claim.original_repair.id}`}
                    </td>
                    <td className="py-4 text-gray-700 dark:text-gray-300">{claim.original_repair.customer_name || "N/A"}</td>
                    <td className="py-4 text-gray-700 dark:text-gray-300">{humanizeReasonCode(claim.reason_code)}</td>
                    <td className="py-4 text-gray-700 dark:text-gray-300">{claim.source_channel || "N/A"}</td>
                    <td className="py-4 text-gray-700 dark:text-gray-300">{formatWarrantyDateTime(claim.created_at)}</td>
                    <td className="py-4">
                      <span className={`rounded-full px-2 py-1 text-xs font-semibold ${getStatusBadgeClass(claim.status)}`}>
                        {humanizeReasonCode(claim.status)}
                      </span>
                    </td>
                    <td className="py-4 text-right">
                      <div className="inline-flex items-center gap-2">
                        <button
                          type="button"
                          onClick={() => openWarrantyClaimDetails(claim)}
                          className="rounded-lg p-2 text-blue-600 transition-colors hover:bg-blue-50 hover:text-blue-700 dark:hover:bg-blue-900/20 dark:hover:text-blue-400"
                          title="View Details"
                        >
                          <EyeIcon className="h-5 w-5" />
                        </button>
                      </div>
                    </td>
                  </tr>
                ))}

                {paginatedClaims.length === 0 && (
                  <tr>
                    <td colSpan={8} className="py-10 text-center text-sm text-gray-500 dark:text-gray-400">
                      {isLoading
                        ? "Loading warranty queue..."
                        : queueError || "No warranty claims found."}
                    </td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>

          {filteredClaims.length > 0 && (
            <div className="mt-4 border-t border-gray-200 pt-4 dark:border-gray-700">
              <div className="flex items-center justify-between">
                <div className="text-sm text-gray-700 dark:text-gray-300">
                  Showing <span className="font-medium">{startIndex + 1}</span> to{" "}
                  <span className="font-medium">{Math.min(currentPage * itemsPerPage, filteredClaims.length)}</span> of{" "}
                  <span className="font-medium">{filteredClaims.length}</span>
                </div>
                <div className="flex items-center gap-2">
                  <button
                    onClick={() => setCurrentPage((prev) => Math.max(prev - 1, 1))}
                    disabled={currentPage === 1}
                    className="rounded-lg border border-gray-300 bg-white p-2 text-gray-700 transition-colors hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 dark:hover:bg-gray-800"
                    title="Previous page"
                  >
                    <svg className="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 19l-7-7 7-7" />
                    </svg>
                  </button>

                  <span className="px-3 text-sm text-gray-600 dark:text-gray-300">
                    {currentPage} / {totalPages}
                  </span>

                  <button
                    onClick={() => setCurrentPage((prev) => Math.min(prev + 1, totalPages))}
                    disabled={currentPage === totalPages}
                    className="rounded-lg border border-gray-300 bg-white p-2 text-gray-700 transition-colors hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 dark:hover:bg-gray-800"
                    title="Next page"
                  >
                    <svg className="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5l7 7-7 7" />
                    </svg>
                  </button>
                </div>
              </div>
            </div>
          )}
        </div>

        {isClaimDetailsOpen && selectedClaim && (
          <div
            className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 px-4"
            onClick={closeWarrantyClaimDetails}
          >
            <div
              className="w-full max-w-4xl rounded-2xl border border-slate-200 bg-white shadow-2xl"
              onClick={(event) => event.stopPropagation()}
            >
              <div className="flex items-start justify-between border-b border-slate-200 px-5 py-4">
                <div>
                  <h3 className="text-lg font-semibold text-slate-900">Warranty Claim {selectedClaim.claim_no}</h3>
                  <p className="mt-1 text-sm text-slate-500">Review warranty submission details and take action.</p>
                </div>
                <button
                  type="button"
                  onClick={closeWarrantyClaimDetails}
                  disabled={actionClaimId === selectedClaim.id}
                  className="rounded-lg border border-slate-300 px-3 py-1 text-sm text-slate-700 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50"
                >
                  Close
                </button>
              </div>

              <div className="max-h-[70vh] overflow-y-auto p-5">
                <div className="grid grid-cols-1 gap-3 text-sm md:grid-cols-2">
                  <div className="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3">
                    <p className="text-xs font-medium uppercase tracking-wide text-slate-500">Repair Request</p>
                    <p className="mt-1 font-semibold text-slate-900">{selectedClaim.original_repair.request_id || `Repair #${selectedClaim.original_repair.id}`}</p>
                  </div>
                  <div className="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3">
                    <p className="text-xs font-medium uppercase tracking-wide text-slate-500">Customer</p>
                    <p className="mt-1 font-semibold text-slate-900">{selectedClaim.original_repair.customer_name || "N/A"}</p>
                  </div>
                  <div className="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3">
                    <p className="text-xs font-medium uppercase tracking-wide text-slate-500">Submitted</p>
                    <p className="mt-1 font-semibold text-slate-900">{formatWarrantyDateTime(selectedClaim.created_at)}</p>
                  </div>
                  <div className="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3">
                    <p className="text-xs font-medium uppercase tracking-wide text-slate-500">Warranty Snapshot Expiry</p>
                    <p className="mt-1 font-semibold text-slate-900">{formatWarrantyDateTime(selectedClaim.warranty_expires_at_snapshot)}</p>
                  </div>
                  <div className="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3">
                    <p className="text-xs font-medium uppercase tracking-wide text-slate-500">Source</p>
                    <p className="mt-1 font-semibold text-slate-900">{selectedClaim.source_channel || "N/A"}</p>
                  </div>
                  <div className="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3">
                    <p className="text-xs font-medium uppercase tracking-wide text-slate-500">Current Status</p>
                    <span className={`mt-1 inline-flex rounded-full px-2 py-1 text-xs font-semibold ${getStatusBadgeClass(selectedClaim.status)}`}>
                      {humanizeReasonCode(selectedClaim.status)}
                    </span>
                  </div>
                </div>

                <div className="mt-4 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3">
                  <p className="text-xs font-medium uppercase tracking-wide text-slate-500">Reason</p>
                  <p className="mt-1 font-semibold text-slate-900">{humanizeReasonCode(selectedClaim.reason_code)}</p>
                </div>

                <div className="mt-4 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3">
                  <p className="text-xs font-medium uppercase tracking-wide text-slate-500">Details</p>
                  <p className="mt-1 whitespace-pre-wrap text-slate-800">{selectedClaim.reason_details?.trim() || "No additional details provided."}</p>
                </div>

                <div className="mt-4">
                  <p className="text-xs font-medium uppercase tracking-wide text-slate-500">Evidence Images</p>
                  {selectedClaimEvidenceMedia.length > 0 ? (
                    <div className="mt-2 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
                      {selectedClaimEvidenceMedia.map((media, index) => (
                        <a
                          key={`${media}-${index}`}
                          href={media}
                          target="_blank"
                          rel="noopener noreferrer"
                          className="block overflow-hidden rounded-xl border border-slate-200 bg-slate-50"
                        >
                          <img
                            src={media}
                            alt={`Warranty evidence ${index + 1}`}
                            className="h-28 w-full object-cover"
                          />
                        </a>
                      ))}
                    </div>
                  ) : (
                    <p className="mt-2 text-sm text-slate-500">No evidence images uploaded.</p>
                  )}
                </div>

                {selectedClaim.status === "pending_repairer" && (
                  <div className="mt-4">
                    <label htmlFor="warranty-modal-rejection-reason" className="text-xs font-medium uppercase tracking-wide text-slate-500">
                      Rejection Reason (required if rejecting)
                    </label>
                    <textarea
                      id="warranty-modal-rejection-reason"
                      value={modalRejectionReason}
                      onChange={(event) => setModalRejectionReason(event.target.value)}
                      rows={3}
                      maxLength={2000}
                      placeholder="Explain why this warranty claim should be rejected..."
                      className="mt-2 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm text-slate-900 outline-none transition focus:border-blue-500"
                    />
                  </div>
                )}
              </div>

              <div className="flex items-center justify-end gap-2 border-t border-slate-200 px-5 py-4">
                {selectedClaim.status === "pending_repairer" && (
                  <>
                    <button
                      type="button"
                      onClick={() => void handleWarrantyClaimDecision(selectedClaim, "reject")}
                      disabled={actionClaimId === selectedClaim.id}
                      className="rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-red-700 disabled:cursor-not-allowed disabled:opacity-50"
                    >
                      {actionClaimId === selectedClaim.id ? "Processing..." : "Reject"}
                    </button>
                    <button
                      type="button"
                      onClick={() => void handleWarrantyClaimDecision(selectedClaim, "approve")}
                      disabled={actionClaimId === selectedClaim.id}
                      className="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-50"
                    >
                      {actionClaimId === selectedClaim.id ? "Processing..." : "Approve"}
                    </button>
                  </>
                )}
              </div>
            </div>
          </div>
        )}
      </div>
    </AppLayoutShopOwner>
  );
}
