/**
 * APPROVAL WORKFLOW UI INTEGRATION GUIDE
 * 
 * This file documents how to integrate the new 4-step approval workflow
 * UI components into existing approval pages.
 * 
 * Components available:
 * 1. ApprovalProgressIndicator - Shows full 4-step workflow progress
 * 2. NextApproverCard - Shows next approver and action required
 * 3. ApprovalLevelBadge - Badge for list items showing level/progress
 * 4. ApprovalStatusBadge - Badge showing approval status
 * 5. ApprovalTimeline - Timeline of approvals/rejections
 */

/**
 * EXAMPLE 1: Show approval progress in a detail modal
 * 
 * In price approval detail modal, add:
 */
export const exampleProgressIndicatorUsage = `
import ApprovalProgressIndicator from '@/components/ApprovalWorkflow/ApprovalProgressIndicator';

// In your modal component:
{approval && (
  <ApprovalProgressIndicator
    levels={approval.level_history || [
      {
        level: 1,
        role: 'finance',
        status: 'approved',
        reviewedBy: 'John Finance',
        reviewedAt: '2026-03-22',
        comments: 'Price increase approved',
      },
      {
        level: 2,
        role: 'shop_owner',
        status: 'pending',
      },
      {
        level: 3,
        role: 'finance',
        status: 'pending',
      },
      {
        level: 4,
        role: 'finance_final',
        status: 'pending',
      },
    ]}
    currentLevel={approval.current_approval_level}
    totalLevels={4}
    isRejected={approval.status === 'rejected'}
    rejectionLevel={approval.rejection_level}
  />
)}
`;

/**
 * EXAMPLE 2: Show next approver card
 * 
 * In approval detail view, show who needs to act next:
 */
export const exampleNextApproverUsage = `
import NextApproverCard from '@/components/ApprovalWorkflow/NextApproverCard';

// In your detail component:
{approval && (
  <NextApproverCard
    nextApprover={{
      level: approval.current_approval_level + 1,
      role: approval.next_approver_role,
      approverName: approval.next_approver_name,
    }}
    currentLevel={approval.current_approval_level}
    totalLevels={4}
    isCompleted={approval.is_final}
    userRole={currentUser.role}
    canApproveAtCurrentLevel={approval.current_approver_role === currentUser.role}
  />
)}
`;

/**
 * EXAMPLE 3: Show approval level in list items
 * 
 * In approval list table rows:
 */
export const exampleBadgeUsage = `
import { ApprovalLevelBadge, ApprovalStatusBadge } from '@/components/ApprovalWorkflow/ApprovalBadges';

// In table row:
<td>
  <ApprovalLevelBadge
    currentLevel={item.current_approval_level}
    totalLevels={4}
    approvalProgress={item.approval_progress}
    status={item.status === 'pending' ? 'pending' : item.status === 'rejected' ? 'rejected' : 'approved'}
  />
</td>

// Or status badge:
<td>
  <ApprovalStatusBadge
    status={item.is_final ? 'completed' : item.status === 'rejected' ? 'rejected' : 'pending'}
    level={item.current_approval_level}
  />
</td>
`;

/**
 * EXAMPLE 4: Show approval timeline in detail modal
 * 
 * Show history of approvals/rejections:
 */
export const exampleTimelineUsage = `
import { ApprovalTimeline } from '@/components/ApprovalWorkflow/ApprovalBadges';

// In your detail modal:
{approval?.level_history && (
  <ApprovalTimeline
    events={approval.level_history.map((level: any) => ({
      level: level.level,
      role: level.role,
      action: level.status === 'approved' ? 'approved' : 'rejected',
      by: level.reviewer_id,
      at: level.reviewed_at,
      comments: level.comments,
    }))}
    compact={false}
  />
)}
`;

/**
 * INTEGRATION CHECKLIST FOR EXISTING PAGES
 * 
 * For ExpenseApproval.tsx:
 * - [ ] Import ApprovalProgressIndicator in viewing modal
 * - [ ] Add approval progress display when approval_id exists
 * - [ ] Show ApprovalLevelBadge in table instead of just status
 * - [ ] Add NextApproverCard above action buttons
 * - [ ] Update API to return approval details (current_approval_level, level_history, etc.)
 * 
 * For PriceApprovals.tsx:
 * - [ ] Same as ExpenseApproval.tsx
 * - [ ] Show approval_level in status badge
 * 
 * For PayslipApproval.tsx:
 * - [ ] Same pattern as above
 * 
 * For PurchaseRequestApproval.tsx:
 * - [ ] Same pattern as above
 */

/**
 * API RESPONSE EXPECTATIONS
 * 
 * When fetching approval items, expect these additional fields:
 * 
 * {
 *   id: number,
 *   approval_id: number,        // Link to Approval model
 *   current_approval_level: number,  // 1-4
 *   approval_workflow_version: string, // 'v4_multi_level' or 'legacy'
 *   approval: {
 *     id: number,
 *     current_level: number,
 *     total_levels: number,
 *     approval_roles: {
 *       "1": "finance",
 *       "2": "shop_owner",
 *       "3": "finance",
 *       "4": "finance_final"
 *     },
 *     current_approver_role: string,
 *     level_history: [
 *       {
 *         level: 1,
 *         role: "finance",
 *         user_id: 123,
 *         action: "approved",
 *         comments: "...",
 *         reviewed_at: "2026-03-22T10:00:00Z"
 *       }
 *     ],
 *     // ... other fields
 *   },
 *   // ... other item fields
 * }
 */

/**
 * STYLING NOTES
 * 
 * All components use Tailwind CSS with:
 * - Dark mode support (dark:*)
 * - Consistent color scheme:
 *   - Finance: Blue (blue-500)
 *   - Shop Owner: Purple (purple-500)
 *   - Finance Final: Green (green-500)
 * - Status-based colors:
 *   - Approved: Green
 *   - Rejected: Red
 *   - Pending: Yellow/Blue
 * 
 * To customize colors, edit getRoleColor() and getBadgeColor() functions
 * in the respective component files.
 */

export const COMPONENT_IMPORT_GUIDE = `
// Import individual components as needed:
import ApprovalProgressIndicator from '@/components/ApprovalWorkflow/ApprovalProgressIndicator';
import NextApproverCard from '@/components/ApprovalWorkflow/NextApproverCard';
import {
  ApprovalLevelBadge,
  ApprovalStatusBadge,
  ApprovalTimeline,
} from '@/components/ApprovalWorkflow/ApprovalBadges';
`;

export default {
	exampleProgressIndicatorUsage,
	exampleNextApproverUsage,
	exampleBadgeUsage,
	exampleTimelineUsage,
	COMPONENT_IMPORT_GUIDE,
};
