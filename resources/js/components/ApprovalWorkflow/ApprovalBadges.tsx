import React from 'react';

interface ApprovalLevelBadgeProps {
	currentLevel?: number | null;
	totalLevels?: number;
	status?: 'pending' | 'approved' | 'rejected';
	approvalProgress?: string; // e.g., "2/4"
	size?: 'sm' | 'md' | 'lg';
	showLabel?: boolean;
}

interface ApprovalStatusBadgeProps {
	status: 'pending' | 'approved' | 'rejected' | 'completed';
	level?: number;
	size?: 'sm' | 'md' | 'lg';
}

/**
 * Badge showing current approval level and progress
 */
export const ApprovalLevelBadge: React.FC<ApprovalLevelBadgeProps> = ({
	currentLevel,
	totalLevels = 4,
	status = 'pending',
	approvalProgress,
	size = 'md',
	showLabel = false,
}) => {
	const sizeClasses = {
		sm: 'px-2 py-1 text-xs',
		md: 'px-3 py-1.5 text-sm',
		lg: 'px-4 py-2 text-base',
	};

	const bgColor = {
		pending: 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400',
		approved: 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400',
		rejected: 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400',
	};

	const progressText = approvalProgress || (currentLevel && totalLevels ? `${currentLevel}/${totalLevels}` : null);

	return (
		<span className={`inline-flex items-center gap-1.5 rounded-full font-medium ${sizeClasses[size]} ${bgColor[status]}`}>
			{status === 'approved' && (
				<svg className="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
					<path fillRule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clipRule="evenodd" />
				</svg>
			)}
			{status === 'rejected' && (
				<svg className="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
					<path fillRule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clipRule="evenodd" />
			)}
			{status === 'pending' && (
				<svg className="w-3 h-3 animate-spin" fill="currentColor" viewBox="0 0 20 20">
					<circle cx="10" cy="10" r="8" fill="none" stroke="currentColor" strokeWidth="2" />
				</svg>
			)}
			<span>
				{showLabel
					? `Level ${progressText || currentLevel}${status === 'approved' ? ' ✓' : status === 'rejected' ? ' ✗' : ''}`
					: progressText || `Level ${currentLevel}`}
			</span>
		</span>
	);
};

/**
 * Badge showing approval status
 */
export const ApprovalStatusBadge: React.FC<ApprovalStatusBadgeProps> = ({
	status,
	level,
	size = 'md',
}) => {
	const sizeClasses = {
		sm: 'px-2 py-1 text-xs',
		md: 'px-3 py-1.5 text-sm',
		lg: 'px-4 py-2 text-base',
	};

	const statusConfig = {
		pending: {
			bg: 'bg-yellow-100 dark:bg-yellow-900/30',
			text: 'text-yellow-700 dark:text-yellow-400',
			icon: '⏳',
			label: 'Pending',
		},
		approved: {
			bg: 'bg-green-100 dark:bg-green-900/30',
			text: 'text-green-700 dark:text-green-400',
			icon: '✓',
			label: 'Approved',
		},
		rejected: {
			bg: 'bg-red-100 dark:bg-red-900/30',
			text: 'text-red-700 dark:text-red-400',
			icon: '✗',
			label: 'Rejected',
		},
		completed: {
			bg: 'bg-emerald-100 dark:bg-emerald-900/30',
			text: 'text-emerald-700 dark:text-emerald-400',
			icon: '✓✓',
			label: 'Completed',
		},
	};

	const config = statusConfig[status];

	return (
		<span className={`inline-flex items-center gap-1.5 rounded-full font-medium ${sizeClasses[size]} ${config.bg} ${config.text}`}>
			<span>{config.icon}</span>
			<span>
				{config.label}
				{level && ` (L${level})`}
			</span>
		</span>
	);
};

/**
 * Timeline component showing approval history
 */
interface ApprovalTimelineEvent {
	level: number;
	role: string;
	action: 'approved' | 'rejected';
	by?: string;
	at?: string;
	comments?: string;
}

interface ApprovalTimelineProps {
	events: ApprovalTimelineEvent[];
	compact?: boolean;
}

const PH_TIME_ZONE = 'Asia/Manila';
const PH_LOCALE = 'en-PH';

const formatTimelineDate = (value: string): string => {
	const parsed = new Date(value);
	if (Number.isNaN(parsed.getTime())) return value;

	return new Intl.DateTimeFormat(PH_LOCALE, {
		timeZone: PH_TIME_ZONE,
		month: 'short',
		day: '2-digit',
		year: 'numeric',
	}).format(parsed);
};

export const ApprovalTimeline: React.FC<ApprovalTimelineProps> = ({ events, compact = false }) => {
	const roleDisplayNames: Record<string, string> = {
		'finance': 'Finance',
		'shop_owner': 'Shop Owner',
		'finance_final': 'Finance Manager',
	};

	const actionColors = {
		approved: 'bg-green-100 border-green-300 text-green-700 dark:bg-green-900/30 dark:border-green-800 dark:text-green-400',
		rejected: 'bg-red-100 border-red-300 text-red-700 dark:bg-red-900/30 dark:border-red-800 dark:text-red-400',
	};

	if (compact) {
		return (
			<div className="flex gap-1 flex-wrap">
				{events.map((event, idx) => (
					<div key={idx} className="inline-flex items-center gap-1">
						<span className={`inline-flex items-center rounded-full px-2 py-1 text-xs font-medium ${actionColors[event.action]}`}>
							L{event.level} {event.action === 'approved' ? '✓' : '✗'}
						</span>
					</div>
				))}
			</div>
		);
	}

	return (
		<div className="space-y-3">
			{events.map((event, idx) => (
				<div key={idx} className="flex gap-3">
					{/* Timeline dot */}
					<div className="flex flex-col items-center">
						<div className={`flex h-8 w-8 items-center justify-center rounded-full ${actionColors[event.action]} text-sm font-bold`}>
							{event.action === 'approved' ? '✓' : '✗'}
						</div>
						{idx < events.length - 1 && <div className="h-8 w-0.5 bg-gray-200 dark:bg-gray-700" />}
					</div>

					{/* Content */}
					<div className="flex-1 pt-1">
						<div className="flex items-baseline gap-2">
							<span className="text-sm font-semibold text-gray-900 dark:text-white">
								Level {event.level}: {roleDisplayNames[event.role] || event.role}
							</span>
							<span className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold ${actionColors[event.action]}`}>
								{event.action === 'approved' ? 'Approved' : 'Rejected'}
							</span>
						</div>
						{event.by && (
							<p className="text-xs text-gray-600 dark:text-gray-400 mt-0.5">
								By <span className="font-medium">{event.by}</span>
								{event.at && ` on ${formatTimelineDate(event.at)}`}
							</p>
						)}
						{event.comments && (
							<p className="text-xs text-gray-700 dark:text-gray-300 mt-2 p-2 rounded bg-gray-100 dark:bg-gray-800">
								"{event.comments}"
							</p>
						)}
					</div>
				</div>
			))}
		</div>
	);
};

export default ApprovalLevelBadge;
