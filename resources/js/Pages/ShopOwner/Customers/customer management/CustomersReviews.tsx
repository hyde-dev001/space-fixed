import { Head, usePage } from "@inertiajs/react";
import axios from "axios";
import { useEffect, useMemo, useState } from "react";
import type { ComponentType } from "react";
import Swal from "sweetalert2";
import AppLayoutShopOwner from "../../../../layout/AppLayout_shopOwner";

type OrderType = "product" | "repair";
type BusinessType = "retail" | "repair" | "both";

interface CustomerReview {
  id: string;
  customerName: string;
  rating: number;
  comment: string;
  feedbackImages: string[];
  serviceType: string;
  orderType: OrderType;
  createdAt: string;
}

type MetricColor = "success" | "warning" | "info" | "error";
type ChangeType = "increase" | "decrease";

interface MetricCardProps {
  title: string;
  value: string;
  change: number;
  changeType: ChangeType;
  icon: ComponentType<{ className?: string }>;
  color: MetricColor;
  description: string;
}

const ArrowUpIcon = ({ className = "" }: { className?: string }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 10l7-7m0 0l7 7m-7-7v18" />
  </svg>
);

const ArrowDownIcon = ({ className = "" }: { className?: string }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 14l-7 7m0 0l-7-7m7 7V3" />
  </svg>
);

const ReviewIcon = ({ className = "" }: { className?: string }) => (
  <svg className={className} viewBox="0 0 24 24" fill="currentColor">
    <path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2Zm-2 10H6v-2h12v2Zm0-3H6V7h12v2Zm0-3H6V4h12v2Z" />
  </svg>
);

const RatingIcon = ({ className = "" }: { className?: string }) => (
  <svg className={className} viewBox="0 0 24 24" fill="currentColor">
    <path d="m12 17.27 6.18 3.73-1.64-7.03L22 9.24l-7.19-.61L12 2 9.19 8.63 2 9.24l5.46 4.73L5.82 21z" />
  </svg>
);

const EyeIcon = ({ className = "" }: { className?: string }) => (
  <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
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
      case "error":
        return "from-rose-500 to-red-600";
      default:
        return "from-gray-500 to-gray-600";
    }
  };

  return (
    <div className="group relative overflow-hidden rounded-2xl border border-gray-200 bg-white p-6 shadow-sm transition-all duration-500 hover:shadow-xl hover:border-gray-300 hover:-translate-y-1 dark:border-gray-800 dark:bg-white/3 dark:hover:border-gray-700">
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

const StarIcon = ({ filled, className = "" }: { filled: boolean; className?: string }) => (
  <svg className={className} viewBox="0 0 20 20" fill={filled ? "currentColor" : "none"} stroke="currentColor">
    <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.538 1.118l-2.8-2.034a1 1 0 00-1.176 0l-2.8 2.034c-.783.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.363-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81H7.03a1 1 0 00.951-.69l1.07-3.292z" />
  </svg>
);

export default function CustomerReviews() {
  const { auth } = usePage().props as any;

  const normalizeBusinessType = (rawValue?: string): BusinessType => {
    const normalized = String(rawValue ?? "").toLowerCase().trim();
    if (normalized === "retail") return "retail";
    if (normalized === "repair") return "repair";
    return "both";
  };

  const businessType = normalizeBusinessType(
    auth?.shop_owner?.business_type
    ?? auth?.user?.shop_owner?.business_type
    ?? auth?.business_type,
  );

  const allowedOrderTypes: OrderType[] =
    businessType === "retail"
      ? ["product"]
      : businessType === "repair"
        ? ["repair"]
        : ["product", "repair"];

  const [reviews, setReviews] = useState<CustomerReview[]>([]);
  const [loading, setLoading] = useState(true);
  const [search, setSearch] = useState("");

  useEffect(() => {
    const fetchReviews = async () => {
      try {
        const response = await fetch("/api/shop-owner/reviews", {
          credentials: "include",
          headers: { Accept: "application/json" },
        });
        if (!response.ok) throw new Error("Failed to fetch reviews");
        const data = await response.json();
        setReviews(data.reviews ?? []);
      } catch (err) {
        console.error("Error fetching reviews:", err);
      } finally {
        setLoading(false);
      }
    };
    fetchReviews();
  }, []);
  const [orderTypeFilter, setOrderTypeFilter] = useState<"all" | OrderType>("all");
  const [currentPage, setCurrentPage] = useState(1);
  const [selectedReview, setSelectedReview] = useState<CustomerReview | null>(null);
  const [showReviewModal, setShowReviewModal] = useState(false);
  const [selectedImage, setSelectedImage] = useState<string | null>(null);

  // ── Report state ─────────────────────────────────────────────────────
  const [showReportModal, setShowReportModal] = useState(false);
  const [reportReason, setReportReason] = useState<string>("fake_review");
  const [reportNotes, setReportNotes] = useState<string>("");
  const [submittingReport, setSubmittingReport] = useState(false);
  const [reportedIds, setReportedIds] = useState<Set<string>>(new Set());

  const handleReportReview = async () => {
    if (!selectedReview) return;

    const confirmation = await Swal.fire({
      title: "Submit this report?",
      text: "This review will be forwarded to the admin team for investigation.",
      icon: "warning",
      showCancelButton: true,
      confirmButtonText: "Yes, submit",
      cancelButtonText: "Cancel",
      reverseButtons: true,
      zIndex: 300000,
    });

    if (!confirmation.isConfirmed) return;

    setSubmittingReport(true);
    try {
      await axios.post(
        "/api/shop-owner/reviews/report",
        { review_id: selectedReview.id, reason: reportReason, notes: reportNotes },
        { headers: { Accept: "application/json" } },
      );
      setReportedIds((prev) => new Set([...prev, selectedReview.id]));
      setShowReportModal(false);
      setReportNotes("");
      void Swal.fire({
        title: "Report submitted",
        text: "Our team will review this report shortly.",
        icon: "success",
        timer: 1800,
        showConfirmButton: false,
        zIndex: 300000,
      });
    } catch (err: unknown) {
      const msg =
        (err as { response?: { data?: { error?: string } } })?.response?.data?.error ??
        "Failed to submit report. Please try again.";
      void Swal.fire({
        title: "Submission failed",
        text: msg,
        icon: "error",
        confirmButtonText: "OK",
        zIndex: 300000,
      });
    } finally {
      setSubmittingReport(false);
    }
  };
  const itemsPerPage = 6;

  useEffect(() => {
    if (orderTypeFilter !== "all" && !allowedOrderTypes.includes(orderTypeFilter)) {
      setOrderTypeFilter("all");
    }
  }, [allowedOrderTypes, orderTypeFilter]);

  const filteredReviews = useMemo(() => {
    return reviews.filter((review) => {
      const haystack = `${review.customerName} ${review.comment} ${review.serviceType}`.toLowerCase();
      const matchesSearch = haystack.includes(search.toLowerCase());
      const matchesOrderType = orderTypeFilter === "all" || review.orderType === orderTypeFilter;
      const matchesBusinessType = allowedOrderTypes.includes(review.orderType);
      return matchesBusinessType && matchesSearch && matchesOrderType;
    });
  }, [reviews, search, orderTypeFilter, allowedOrderTypes]);

  const totalPages = Math.max(1, Math.ceil(filteredReviews.length / itemsPerPage));
  const safeCurrentPage = Math.min(currentPage, totalPages);
  const startIndex = (safeCurrentPage - 1) * itemsPerPage;
  const paginatedReviews = filteredReviews.slice(startIndex, startIndex + itemsPerPage);

  const averageRating = useMemo(() => {
    if (reviews.length === 0) return 0;
    return reviews.reduce((sum, review) => sum + review.rating, 0) / reviews.length;
  }, [reviews]);

  const renderStars = (rating: number) => (
    <div className="flex items-center gap-1">
      {[1, 2, 3, 4, 5].map((value) => (
        <StarIcon key={value} filled={value <= rating} className={`size-4 ${value <= rating ? "text-yellow-500" : "text-gray-300 dark:text-gray-600"}`} />
      ))}
    </div>
  );

  return (
    <AppLayoutShopOwner>
      <Head title="Customer Reviews - Shop Owner" />

      <div className="space-y-6 p-6">
        <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
          <div>
            <h1 className="mb-1 text-2xl font-semibold text-gray-900 dark:text-white">Customer Reviews</h1>
            <p className="text-gray-600 dark:text-gray-400">Collect customer satisfaction data and track follow-up response status.</p>
          </div>
          <div className="rounded-full bg-gray-100 px-3 py-1 text-xs font-semibold text-gray-700 dark:bg-gray-800 dark:text-gray-300">Shop Owner Feedback</div>
        </div>

        <div className="grid grid-cols-1 gap-6 md:grid-cols-2">
          <MetricCard
            title="Total Reviews"
            value={reviews.length.toString()}
            change={12}
            changeType="increase"
            icon={ReviewIcon}
            color="info"
            description="Collected customer feedback entries"
          />
          <MetricCard
            title="Average Rating"
            value={`${averageRating.toFixed(1)} / 5`}
            change={6}
            changeType="increase"
            icon={RatingIcon}
            color="warning"
            description="Overall satisfaction score"
          />
        </div>

        <div className="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-white/3">
          {loading && (
            <div className="flex items-center justify-center py-16">
              <div className="flex flex-col items-center gap-3">
                <div className="h-10 w-10 animate-spin rounded-full border-b-2 border-blue-600" />
                <p className="text-sm text-gray-500 dark:text-gray-400">Loading reviews…</p>
              </div>
            </div>
          )}
          {!loading && (
          <>
          <div className="mb-4 flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
            <div className="flex w-full flex-col gap-3 md:flex-row">
              <input
                value={search}
                onChange={(event) => {
                  setSearch(event.target.value);
                  setCurrentPage(1);
                }}
                placeholder="Search by customer, comment, or service type"
                className="w-full rounded-lg border border-gray-300 px-4 py-2.5 text-sm focus:border-blue-500 focus:outline-none dark:border-gray-700 dark:bg-gray-800 dark:text-white"
              />

              <select
                value={orderTypeFilter}
                onChange={(event) => {
                  setOrderTypeFilter(event.target.value as "all" | OrderType);
                  setCurrentPage(1);
                }}
                title="Filter by order type"
                className="w-full rounded-lg border border-gray-300 px-4 py-2.5 text-sm focus:border-blue-500 focus:outline-none dark:border-gray-700 dark:bg-gray-800 dark:text-white md:w-44"
              >
                <option value="all">All order types</option>
                {allowedOrderTypes.includes("product") && <option value="product">Product</option>}
                {allowedOrderTypes.includes("repair") && <option value="repair">Repair</option>}
              </select>

            </div>
          </div>

          <div className="overflow-x-auto rounded-xl border border-gray-200 dark:border-gray-700">
            <table className="w-full min-w-210">
              <thead className="bg-gray-50 dark:bg-gray-800">
                <tr>
                  <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Customer</th>
                  <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Rating</th>
                  <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Comment</th>
                  <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Service Type</th>
                  <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Order Type</th>
                  <th className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Date</th>
                  <th className="px-4 py-3 text-center text-xs font-semibold uppercase tracking-wide text-gray-500">Action</th>
                </tr>
              </thead>

              <tbody className="divide-y divide-gray-200 dark:divide-gray-700">
                {paginatedReviews.map((review) => (
                  <tr key={review.id} className="bg-white transition-colors hover:bg-gray-50 dark:bg-transparent dark:hover:bg-gray-800/40">
                    <td className="px-4 py-3 text-sm font-medium text-gray-900 dark:text-white">{review.customerName}</td>
                    <td className="px-4 py-3">{renderStars(review.rating)}</td>
                    <td className="max-w-90 px-4 py-3 text-sm text-gray-600 dark:text-gray-300">{review.comment}</td>
                    <td className="px-4 py-3 text-sm text-gray-700 dark:text-gray-300">{review.serviceType}</td>
                    <td className="px-4 py-3 text-sm capitalize text-gray-700 dark:text-gray-300">{review.orderType}</td>
                    <td className="px-4 py-3 text-sm text-gray-600 dark:text-gray-300">{new Date(review.createdAt).toLocaleDateString()}</td>
                    <td className="px-4 py-3 text-center">
                      <button
                        onClick={() => {
                          setSelectedReview(review);
                          setShowReviewModal(true);
                        }}
                        title={`View feedback from ${review.customerName}`}
                        className="inline-flex items-center justify-center bg-transparent text-blue-600 transition-colors hover:text-blue-700 dark:text-blue-400 dark:hover:text-blue-300"
                      >
                        <EyeIcon className="size-5" />
                      </button>
                    </td>
                  </tr>
                ))}

                {paginatedReviews.length === 0 && (
                  <tr>
                    <td colSpan={7} className="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                      No customer reviews found.
                    </td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>

          {filteredReviews.length > 0 && (
            <div className="mt-4 flex flex-col gap-3 border-t border-gray-200 pt-4 dark:border-gray-700 md:flex-row md:items-center md:justify-between">
              <p className="text-sm text-gray-600 dark:text-gray-400">
                Showing {startIndex + 1} to {Math.min(startIndex + itemsPerPage, filteredReviews.length)} of {filteredReviews.length} reviews
              </p>
              <div className="flex items-center gap-2">
                <button
                  onClick={() => setCurrentPage((prev) => Math.max(1, prev - 1))}
                  disabled={safeCurrentPage === 1}
                  className="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 transition-colors hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800"
                >
                  Previous
                </button>
                <button
                  onClick={() => setCurrentPage((prev) => Math.min(totalPages, prev + 1))}
                  disabled={safeCurrentPage === totalPages}
                  className="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 transition-colors hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800"
                >
                  Next
                </button>
              </div>
            </div>
          )}
          </>
          )}
        </div>

        {showReviewModal && selectedReview && (
          <>
            <div className="fixed inset-0 z-100000 bg-black/50" />
            <div className="fixed inset-0 z-100001 flex items-center justify-center p-4">
              <div className="max-h-[90vh] w-full max-w-3xl overflow-y-auto rounded-2xl border border-gray-200 bg-white p-5 shadow-xl dark:border-gray-800 dark:bg-gray-900">
                <div className="mb-4 flex items-center justify-between border-b border-gray-200 pb-4 dark:border-gray-800">
                  <div>
                    <h3 className="text-xl font-semibold text-gray-900 dark:text-white">Feedback Details</h3>
                    <p className="text-sm text-gray-500 dark:text-gray-400">{selectedReview.customerName} • {new Date(selectedReview.createdAt).toLocaleDateString()}</p>
                  </div>
                  <div className="flex items-center gap-2">
                    {reportedIds.has(selectedReview.id) ? (
                      <span className="rounded-lg border border-red-300 bg-red-50 px-3 py-1.5 text-xs font-semibold text-red-600 dark:border-red-700 dark:bg-red-900/20 dark:text-red-400">
                        Reported
                      </span>
                    ) : (
                      <button
                        onClick={() => setShowReportModal(true)}
                        className="rounded-lg border border-red-300 px-3 py-1.5 text-sm font-medium text-red-600 hover:bg-red-50 dark:border-red-700 dark:text-red-400 dark:hover:bg-red-900/20"
                      >
                        Report Review
                      </button>
                    )}
                    <button
                      onClick={() => {
                        setShowReviewModal(false);
                        setSelectedReview(null);
                      }}
                      className="rounded-lg border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800"
                    >
                      Close
                    </button>
                  </div>
                </div>

                <div className="space-y-4">
                  <div className="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                    <p className="text-xs font-semibold uppercase tracking-wide text-gray-500">Rating</p>
                    <div className="mt-2">{renderStars(selectedReview.rating)}</div>
                  </div>

                  <div className="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                    <p className="text-xs font-semibold uppercase tracking-wide text-gray-500">Feedback Comment</p>
                    <p className="mt-2 text-sm text-gray-700 dark:text-gray-300">{selectedReview.comment}</p>
                  </div>

                  <div className="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                    <p className="text-xs font-semibold uppercase tracking-wide text-gray-500">Pictures</p>
                    <div className="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
                      {selectedReview.feedbackImages.map((imagePath, index) => (
                        <button
                          key={`${selectedReview.id}-${index}`}
                          onClick={() => setSelectedImage(imagePath)}
                          className="overflow-hidden rounded-lg border border-gray-200 transition-transform hover:scale-105 dark:border-gray-700"
                        >
                          <img
                            src={imagePath}
                            alt={`Feedback ${index + 1} from ${selectedReview.customerName}`}
                            className="h-40 w-full object-cover"
                          />
                        </button>
                      ))}
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </>
        )}

        {/* Report Review Modal */}
        {showReportModal && selectedReview && (
          <>
            <div className="fixed inset-0 z-200000 bg-black/60" onClick={() => setShowReportModal(false)} />
            <div className="fixed inset-0 z-200001 flex items-center justify-center p-4">
              <div className="w-full max-w-md rounded-2xl border border-gray-200 bg-white p-6 shadow-xl dark:border-gray-700 dark:bg-gray-900">
                <div className="mb-5">
                  <h3 className="text-lg font-semibold text-red-600 dark:text-red-400">Report This Review</h3>
                  <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    Report a review from <span className="font-medium text-gray-700 dark:text-gray-300">{selectedReview.customerName}</span> for
                    investigation by our admin team.
                  </p>
                </div>

                <div className="space-y-4">
                  <div>
                    <label className="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">
                      Reason <span className="text-red-500">*</span>
                    </label>
                    <select
                      value={reportReason}
                      onChange={(e) => setReportReason(e.target.value)}
                      title="Select report reason"
                      className="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-red-500 focus:outline-none dark:border-gray-700 dark:bg-gray-800 dark:text-white"
                    >
                      <option value="fake_review">Fake Review</option>
                      <option value="harassment">Harassment</option>
                      <option value="spam">Spam</option>
                      <option value="inappropriate_content">Inappropriate Content</option>
                      <option value="other">Other</option>
                    </select>
                  </div>

                  <div>
                    <label className="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">
                      Additional Notes <span className="text-gray-400 text-xs">(optional)</span>
                    </label>
                    <textarea
                      value={reportNotes}
                      onChange={(e) => setReportNotes(e.target.value)}
                      rows={3}
                      maxLength={1000}
                      placeholder="Describe why this review is malicious or misleading…"
                      className="w-full resize-none rounded-lg border border-gray-300 px-3 py-2.5 text-sm focus:border-red-500 focus:outline-none dark:border-gray-700 dark:bg-gray-800 dark:text-white"
                    />
                    <p className="mt-1 text-right text-xs text-gray-400">{reportNotes.length}/1000</p>
                  </div>

                  <div className="rounded-lg border border-amber-200 bg-amber-50 p-3 dark:border-amber-800 dark:bg-amber-900/20">
                    <p className="text-xs text-amber-700 dark:text-amber-400">
                      ⚠️ False reports may result in your account being reviewed. Only report genuinely malicious content.
                    </p>
                  </div>
                </div>

                <div className="mt-5 flex justify-end gap-3">
                  <button
                    onClick={() => { setShowReportModal(false); setReportNotes(""); }}
                    className="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-gray-800"
                  >
                    Cancel
                  </button>
                  <button
                    onClick={handleReportReview}
                    disabled={submittingReport}
                    className="rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700 disabled:opacity-50"
                  >
                    {submittingReport ? "Submitting…" : "Submit Report"}
                  </button>
                </div>
              </div>
            </div>
          </>
        )}

        {selectedImage && (          <>
            <div
              className="fixed inset-0 z-100002 bg-black/80"
              onClick={() => setSelectedImage(null)}
            />
            <div className="fixed inset-0 z-100003 flex items-center justify-center p-4">
              <div className="relative max-h-[90vh] max-w-4xl">
                <button
                  onClick={() => setSelectedImage(null)}
                  className="absolute -right-12 top-0 rounded-full bg-white/20 p-2 text-white transition-colors hover:bg-white/30 dark:bg-black/30 dark:hover:bg-black/50"
                  title="Close (ESC)"
                >
                  <svg className="size-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                  </svg>
                </button>
                <img
                  src={selectedImage}
                  alt="Full screen preview"
                  className="max-h-[90vh] max-w-4xl rounded-lg shadow-2xl"
                />
              </div>
            </div>
          </>
        )}
      </div>
    </AppLayoutShopOwner>
  );
}