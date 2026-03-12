import { useQuery } from '@tanstack/react-query';
import { useMemo } from 'react';

interface SearchResult {
    id: number;
    type: 'job' | 'invoice' | 'expense';
    title: string;
    subtitle: string;
    status: string;
    amount: number;
    date: string;
    url: string;
    icon: string;
    badge: string;
    badgeColor: 'green' | 'blue' | 'yellow' | 'red' | 'gray';
}

interface SearchResponse {
    jobs: SearchResult[];
    invoices: SearchResult[];
    expenses: SearchResult[];
    query: string;
    total: number;
}

/**
 * Hook for unified search across all ERP modules
 * 
 * @param query - Search query string (min 2 chars)
 * @param enabled - Whether to execute the search
 * @param limit - Maximum results per category (default 10)
 */
export function useSearch(query: string, enabled: boolean = true, limit: number = 10) {
    const trimmedQuery = query.trim();
    
    return useQuery<SearchResponse>({
        queryKey: ['search', trimmedQuery, limit],
        queryFn: async () => {
            const response = await fetch(
                `/api/search?query=${encodeURIComponent(trimmedQuery)}&limit=${limit}`,
                {
                    method: 'GET',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
                    },
                    credentials: 'include'
                }
            );

            if (!response.ok) {
                const error = await response.json();
                throw new Error(error.error || 'Search failed');
            }

            return response.json();
        },
        enabled: enabled && trimmedQuery.length >= 2,
        staleTime: 30000, // 30 seconds
        gcTime: 300000, // 5 minutes
        retry: 1
    });
}

/**
 * Hook to get flattened search results sorted by relevance
 */
export function useSearchResults(query: string, enabled: boolean = true, limit: number = 10) {
    const { data, isLoading, error } = useSearch(query, enabled, limit);

    const flatResults = useMemo(() => {
        if (!data) return [];

        // Combine all results and sort by date (most recent first)
        const allResults = [
            ...data.jobs,
            ...data.invoices,
            ...data.expenses
        ];

        return allResults.sort((a, b) => {
            return new Date(b.date).getTime() - new Date(a.date).getTime();
        });
    }, [data]);

    return {
        results: flatResults,
        categorized: data ? {
            jobs: data.jobs,
            invoices: data.invoices,
            expenses: data.expenses
        } : null,
        total: data?.total ?? 0,
        query: data?.query ?? query,
        isLoading,
        error
    };
}

/**
 * Format amount for display
 */
export function formatAmount(amount: number): string {
    return `₱${amount.toLocaleString('en-PH', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    })}`;
}

/**
 * Format date for display (relative time)
 */
export function formatSearchDate(date: string): string {
    const now = new Date();
    const searchDate = new Date(date);
    const diffMs = now.getTime() - searchDate.getTime();
    const diffMins = Math.floor(diffMs / 60000);
    const diffHours = Math.floor(diffMs / 3600000);
    const diffDays = Math.floor(diffMs / 86400000);

    if (diffMins < 1) return 'just now';
    if (diffMins < 60) return `${diffMins}m ago`;
    if (diffHours < 24) return `${diffHours}h ago`;
    if (diffDays < 7) return `${diffDays}d ago`;
    if (diffDays < 30) return `${Math.floor(diffDays / 7)}w ago`;
    
    // Format as date for older results
    return searchDate.toLocaleDateString('en-PH', {
        month: 'short',
        day: 'numeric',
        year: searchDate.getFullYear() !== now.getFullYear() ? 'numeric' : undefined
    });
}

/**
 * Get badge color classes for Tailwind
 */
export function getBadgeClasses(color: SearchResult['badgeColor']): string {
    const baseClasses = 'px-2 py-0.5 text-xs font-medium rounded-full';
    
    const colorClasses = {
        green: 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200',
        blue: 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200',
        yellow: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200',
        red: 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200',
        gray: 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300'
    };

    return `${baseClasses} ${colorClasses[color]}`;
}
