import { useState, useCallback } from 'react';

/**
 * Hook to manage loading state for async actions and prevent duplicate submissions
 * Tracks loading state for specific action types to allow multiple concurrent actions
 *
 * Usage:
 * const { isLoading, execute } = useActionLoading();
 *
 * const handleApprove = async (id: number) => {
 *   execute('approve', async () => {
 *     await api.approve(id);
 *   });
 * };
 *
 * // Use in button:
 * <button disabled={isLoading('approve')} onClick={() => handleApprove(id)}>
 *   Approve
 * </button>
 */
export const useActionLoading = () => {
	const [loadingStates, setLoadingStates] = useState<Record<string, boolean>>({});

	/**
	 * Check if a specific action is loading
	 */
	const isLoading = useCallback(
		(action: string = 'default') => loadingStates[action] ?? false,
		[loadingStates]
	);

	/**
	 * Check if any action is loading
	 */
	const isAnyLoading = useCallback(
		() => Object.values(loadingStates).some((state) => state),
		[loadingStates]
	);

	/**
	 * Execute an async action with automatic loading state management
	 * Prevents the action from running if it's already loading
	 */
	const execute = useCallback(
		async (
			action: string = 'default',
			asyncFn: () => Promise<void>,
		) => {
			if (loadingStates[action]) {
				console.warn(`Action "${action}" is already in progress`);
				return;
			}

			setLoadingStates((prev) => ({ ...prev, [action]: true }));

			try {
				await asyncFn();
			} finally {
				setLoadingStates((prev) => ({ ...prev, [action]: false }));
			}
		},
		[loadingStates]
	);

	/**
	 * Manually set loading state for an action
	 */
	const setLoading = useCallback((action: string = 'default', loading: boolean) => {
		setLoadingStates((prev) => ({ ...prev, [action]: loading }));
	}, []);

	/**
	 * Reset all loading states
	 */
	const reset = useCallback(() => {
		setLoadingStates({});
	}, []);

	return {
		isLoading,
		isAnyLoading,
		execute,
		setLoading,
		reset,
	};
};
