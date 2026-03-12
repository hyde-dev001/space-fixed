<?php

namespace App\Http\Controllers\superAdmin;

use App\Http\Controllers\Controller;
use App\Models\ReviewReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class FlaggedAccountsController extends Controller
{
    public function index(): Response
    {
        $reports = ReviewReport::with([
                'customer:id,name,email',
                'shopOwner:id,business_name,first_name,last_name',
            ])
            ->orderByDesc('created_at')
            ->get()
            ->map(function ($report) {
                $shopName = $report->shopOwner?->business_name
                    ?? trim(($report->shopOwner?->first_name ?? '') . ' ' . ($report->shopOwner?->last_name ?? ''))
                    ?: 'Unknown Shop';

                return [
                    'id'             => (string) $report->id,
                    'username'       => $report->customer?->name ?? 'Unknown Customer',
                    'email'          => $report->customer?->email ?? '',
                    'flaggedReason'  => ReviewReport::$reasonLabels[$report->reason] ?? ucfirst(str_replace('_', ' ', $report->reason)),
                    'flaggedDate'    => $report->created_at->toISOString(),
                    'status'         => $report->status,
                    // Extended review detail fields
                    'reviewType'     => $report->review_type,
                    'reviewSnapshot' => $report->review_snapshot,
                    'reportNotes'    => $report->notes,
                    'reportedBy'     => $shopName,
                    'adminNotes'     => $report->admin_notes,
                ];
            });

        return Inertia::render('superAdmin/Users/FlaggedAccounts', [
            'flaggedAccounts' => $reports,
        ]);
    }

    /** Mark a report as under investigation */
    public function markReviewed(int $id): JsonResponse
    {
        $report = ReviewReport::findOrFail($id);
        $report->update(['status' => 'under_investigation']);
        return response()->json(['status' => $report->status]);
    }

    /** Dismiss the report — keep the review live */
    public function dismiss(Request $request, int $id): JsonResponse
    {
        $report = ReviewReport::findOrFail($id);
        $report->update([
            'status'      => 'dismissed',
            'admin_notes' => $request->input('admin_notes'),
            'resolved_at' => now(),
        ]);
        return response()->json(['status' => $report->status]);
    }

    /** Ban the customer and resolve the report */
    public function ban(Request $request, int $id): JsonResponse
    {
        $report = ReviewReport::findOrFail($id);
        $report->update([
            'status'      => 'banned',
            'admin_notes' => $request->input('admin_notes'),
            'resolved_at' => now(),
        ]);

        // Suspend the customer's User account if desired:
        // $report->customer?->update(['is_suspended' => true]);

        return response()->json(['status' => $report->status]);
    }
}

