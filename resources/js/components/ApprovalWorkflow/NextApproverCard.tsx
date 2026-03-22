import React from 'react';

interface NextApproverInfo {
	level: number;
	role: string;
	approverName?: string | null;
}

interface NextApproverCardProps {
	nextApprover?: NextApproverInfo | null;
	currentLevel: number;
	totalLevels: number;
	isCompleted?: boolean;
	userRole?: string;
	canApproveAtCurrentLevel?: boolean;
}

const roleDisplayNames: Record<string, string> = {
	'finance': 'Finance Team',
	'shop_owner': 'Shop Owner',
	'finance_final': 'Finance Manager',
};

const roleDescriptions: Record<string, string> = {
	'finance': 'Finance staff will review and approve this request',
	'shop_owner': 'Shop owner will review and provide final approval',
	'finance_final': 'Finance manager will provide final approval',
};

const getRoleBadgeColor = (role: string): string => {
	const colors: Record<string, string> = {
		'finance': 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400',
		'shop_owner': 'bg-purple-100 text-purple-700 dark:bg-purple-900/30 dark:text-purple-400',
		'finance_final': 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400',
	};
	return colors[role] || 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-400';
};

const NextApproverCard: React.FC<NextApproverCardProps> = ({
	nextApprover,
	currentLevel,
	totalLevels,
	isCompleted = false,
	userRole,
	canApproveAtCurrentLevel = false,
}) => {
	if (isCompleted) {
		return (
			<div className="rounded-lg border-2 border-green-300 bg-green-50 dark:border-green-800 dark:bg-green-900/20 p-4">
				<div className="flex items-start gap-3">
					<div className="flex-shrink-0 flex h-10 w-10 items-center justify-center rounded-full bg-green-500 text-white">
						<svg className="w-6 h-6" fill="currentColor" viewBox="0 0 20 20">
							<path fillRule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clipRule="evenodd" />
						</svg>
					</div>
					<div className="flex-1">
						<h3 className="text-sm font-semibold text-green-900 dark:text-green-100">Approval Complete</h3>
						<p className="text-xs text-green-700 dark:text-green-400 mt-1">
							This request has been fully approved through all 4 levels and is ready for processing.
						</p>
					</div>
				</div>
			</div>
		);
	}

	if (!nextApprover) {
		return (
			<div className="rounded-lg border-2 border-gray-200 bg-gray-50 dark:border-gray-700 dark:bg-gray-900 p-4">
				<div className="flex items-start gap-3">
					<div className="flex-shrink-0 flex h-10 w-10 items-center justify-center rounded-full bg-gray-300 dark:bg-gray-700 text-gray-600 dark:text-gray-400">
						<svg className="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
							<path fillRule="evenodd" d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z" clipRule="evenodd" />
						</svg>
					</div>
					<div className="flex-1">
						<h3 className="text-sm font-semibold text-gray-900 dark:text-white">Awaiting Assignment</h3>
						<p className="text-xs text-gray-600 dark:text-gray-400 mt-1">
							No next approver information available at this time.
						</p>
					</div>
				</div>
			</div>
		);
	}

	const isUserNextApprover = userRole === nextApprover.role || canApproveAtCurrentLevel;

	return (
		<div
			className={`rounded-lg border-2 p-4 transition-all ${
				isUserNextApprover
					? 'border-blue-300 bg-blue-50 dark:border-blue-800 dark:bg-blue-900/20'
					: 'border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-900'
			}`}
		>
			<div className="flex items-start justify-between gap-3 mb-3">
				<h3 className="text-sm font-semibold text-gray-900 dark:text-white">
					Next Approver (Level {nextApprover.level} of {totalLevels})
				</h3>
				<span className={`inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold ${getRoleBadgeColor(nextApprover.role)}`}>
					{roleDisplayNames[nextApprover.role] || nextApprover.role}
				</span>
			</div>

			<div className="space-y-2">
				<p className="text-sm text-gray-700 dark:text-gray-300">
					{roleDescriptions[nextApprover.role] || 'Approval pending from next reviewer'}
				</p>

				{nextApprover.approverName && (
					<p className="text-sm text-gray-600 dark:text-gray-400">
						<span className="font-medium">Approver:</span> {nextApprover.approverName}
					</p>
				)}

				{isUserNextApprover && (
					<div className="flex items-start gap-2 mt-3 p-3 rounded-lg bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-800">
						<svg className="w-5 h-5 flex-shrink-0 text-yellow-600 dark:text-yellow-400 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
							<path fillRule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clipRule="evenodd" />
						</svg>
						<div className="text-xs text-yellow-800 dark:text-yellow-200">
							<p className="font-semibold mb-1">Action Required</p>
							<p>You need to approve or reject this request at this level before it can proceed further.</p>
						</div>
					</div>
				)}
			</div>
		</div>
	);
};

export default NextApproverCard;
