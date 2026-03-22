import React from 'react';

interface ApprovalLevel {
	level: number;
	role: string;
	status: 'pending' | 'approved' | 'rejected';
	reviewedBy?: string | null;
	reviewedAt?: string | null;
	comments?: string | null;
}

interface ApprovalProgressIndicatorProps {
	levels: ApprovalLevel[];
	currentLevel: number;
	totalLevels: number;
	isCompletedWorkflow?: boolean;
	isRejected?: boolean;
	rejectionLevel?: number;
}

const roleDisplayNames: Record<string, string> = {
	'finance': 'Finance',
	'shop_owner': 'Shop Owner',
	'finance_final': 'Finance Final',
};

const getRoleColor = (role: string): string => {
	const colors: Record<string, string> = {
		'finance': 'from-blue-500 to-blue-600',
		'shop_owner': 'from-purple-500 to-purple-600',
		'finance_final': 'from-green-500 to-green-600',
	};
	return colors[role] || 'from-gray-500 to-gray-600';
};

const getStatusIcon = (status: 'pending' | 'approved' | 'rejected') => {
	if (status === 'approved') {
		return (
			<svg className="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
				<path fillRule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clipRule="evenodd" />
			</svg>
		);
	}
	if (status === 'rejected') {
		return (
			<svg className="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
				<path fillRule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clipRule="evenodd" />
			</svg>
		);
	}
	return (
		<svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
			<circle cx="12" cy="12" r="9" strokeWidth={2} />
		</svg>
	);
};

const ApprovalProgressIndicator: React.FC<ApprovalProgressIndicatorProps> = ({
	levels,
	currentLevel,
	totalLevels,
	isCompletedWorkflow = false,
	isRejected = false,
	rejectionLevel,
}) => {
	return (
		<div className="w-full">
			{/* Progress header */}
			<div className="flex items-center justify-between mb-4">
				<div className="flex items-center gap-2">
					<h3 className="text-sm font-semibold text-gray-700 dark:text-gray-300">Approval Progress</h3>
					{isCompletedWorkflow && (
						<span className="inline-flex items-center rounded-full bg-green-100 px-2 py-0.5 text-xs font-semibold text-green-700 dark:bg-green-900/30 dark:text-green-400">
							Completed
						</span>
					)}
					{isRejected && (
						<span className="inline-flex items-center rounded-full bg-red-100 px-2 py-0.5 text-xs font-semibold text-red-700 dark:bg-red-900/30 dark:text-red-400">
							Rejected at Level {rejectionLevel}
						</span>
					)}
				</div>
				<span className="text-xs font-medium text-gray-600 dark:text-gray-400">
					{currentLevel}/{totalLevels}
				</span>
			</div>

			{/* Progress bar */}
			<div className="mb-6">
				<div className="flex gap-2">
					{Array.from({ length: totalLevels }).map((_, index) => {
						const levelNum = index + 1;
						let bgColor = 'bg-gray-200 dark:bg-gray-700';
						
						if (isRejected && rejectionLevel && levelNum >= rejectionLevel) {
							bgColor = 'bg-red-200 dark:bg-red-900/30';
						} else if (currentLevel && levelNum <= currentLevel) {
							bgColor = 'bg-green-200 dark:bg-green-900/30';
						}
						
						return (
							<div key={levelNum} className="flex-1">
								<div className={`h-2 rounded-full ${bgColor} transition-colors`} />
							</div>
						);
					})}
				</div>
			</div>

			{/* Level cards */}
			<div className="space-y-3">
				{levels.map((level) => {
					const isActive = level.level === currentLevel;
					const isDone = (currentLevel && level.level < currentLevel) || isCompletedWorkflow;
					const isRejectedLevel = isRejected && level.level === rejectionLevel;

					return (
						<div
							key={level.level}
							className={`rounded-lg border-2 p-4 transition-all ${
								isDone
									? 'border-green-300 bg-green-50 dark:border-green-800 dark:bg-green-900/20'
									: isRejectedLevel
									? 'border-red-300 bg-red-50 dark:border-red-800 dark:bg-red-900/20'
									: isActive
									? 'border-blue-300 bg-blue-50 dark:border-blue-800 dark:bg-blue-900/20'
									: 'border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-900'
							}`}
						>
							<div className="flex items-start gap-3">
								{/* Status icon */}
								<div
									className={`flex-shrink-0 flex h-8 w-8 items-center justify-center rounded-full bg-gradient-to-br ${getRoleColor(
										level.role
									)} text-white`}
								>
									{level.status === 'approved' || isDone ? (
										<svg className="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
											<path fillRule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clipRule="evenodd" />
										</svg>
									) : isRejectedLevel ? (
										<svg className="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
											<path fillRule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clipRule="evenodd" />
										</svg>
									) : isActive ? (
										<span className="text-sm font-bold">{level.level}</span>
									) : (
										<span className="text-sm font-bold">{level.level}</span>
									)}
								</div>

								{/* Content */}
								<div className="flex-1">
									<div className="flex items-center justify-between mb-1">
										<h4 className="text-sm font-semibold text-gray-900 dark:text-white">
											Level {level.level}: {roleDisplayNames[level.role] || level.role}
										</h4>
										<span
											className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold ${
												isDone || level.status === 'approved'
													? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400'
													: isRejectedLevel
													? 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400'
													: isActive
													? 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400'
													: 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-400'
											}`}
										>
											{isDone || level.status === 'approved'
												? '✓ Approved'
												: isRejectedLevel
												? '✗ Rejected'
												: isActive
												? '⏳ Pending'
												: '○ Waiting'}
										</span>
									</div>

									{(level.reviewedBy || level.reviewedAt) && (
										<p className="text-xs text-gray-600 dark:text-gray-400 mb-2">
											Reviewed by <span className="font-medium">{level.reviewedBy || 'System'}</span>
											{level.reviewedAt && ` on ${new Date(level.reviewedAt).toLocaleDateString()}`}
										</p>
									)}

									{level.comments && (
										<p className="text-xs text-gray-700 dark:text-gray-300 bg-white/50 dark:bg-black/20 rounded px-2 py-1 mt-1">
											{level.comments}
										</p>
									)}
								</div>
							</div>
						</div>
					);
				})}
			</div>
		</div>
	);
};

export default ApprovalProgressIndicator;
