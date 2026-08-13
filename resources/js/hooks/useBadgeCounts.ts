import { useState, useEffect } from 'react';

interface BadgeCounts {
  orderStatusCount: number;
  repairStatusCount: number;
  chatIconCount: number;
  userIconCount: number;
}

type InitialBadgeCounts = Partial<BadgeCounts>;

/**
 * Custom hook to fetch and auto-refresh badge counts for navigation header
 * Polls every 2 seconds for real-time updates
 */
export function useBadgeCounts(enabled: boolean = true, initialCounts: InitialBadgeCounts = {}): BadgeCounts {
  const [counts, setCounts] = useState<BadgeCounts>({
    orderStatusCount: initialCounts.orderStatusCount ?? 0,
    repairStatusCount: initialCounts.repairStatusCount ?? 0,
    chatIconCount: initialCounts.chatIconCount ?? 0,
    userIconCount: initialCounts.userIconCount ?? 0,
  });

  useEffect(() => {
    if (!enabled) return;

    let stopped = false;

    const fetchCounts = async () => {
      if (stopped) return;

      try {
        const response = await fetch('/api/customer/badge-counts', {
          credentials: 'include',
          headers: {
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
          },
        });

        if (response.status === 401) {
          stopped = true;
          setCounts({
            orderStatusCount: 0,
            repairStatusCount: 0,
            chatIconCount: 0,
            userIconCount: 0,
          });
        } else if (response.ok) {
          const data = await response.json();
          setCounts({
            orderStatusCount: data.orderStatusCount || 0,
            repairStatusCount: data.repairStatusCount || 0,
            chatIconCount: data.chatIconCount || 0,
            userIconCount: data.userIconCount || 0,
          });
        }
      } catch (error) {
        console.error('Failed to fetch badge counts:', error);
      }
    };

    // Initial fetch
    void fetchCounts();

    // Poll every 2 seconds
    const interval = window.setInterval(() => void fetchCounts(), 2000);

    return () => {
      stopped = true;
      window.clearInterval(interval);
    };
  }, [enabled]);

  return counts;
}
