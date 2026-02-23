import { useState, useEffect } from 'react';
import { router } from '@inertiajs/react';

interface BadgeCounts {
  orderStatusCount: number;
  repairStatusCount: number;
  chatIconCount: number;
  userIconCount: number;
}

/**
 * Custom hook to fetch and auto-refresh badge counts for navigation header
 * Polls every 2 seconds for real-time updates
 */
export function useBadgeCounts(enabled: boolean = true): BadgeCounts {
  const [counts, setCounts] = useState<BadgeCounts>({
    orderStatusCount: 0,
    repairStatusCount: 0,
    chatIconCount: 0,
    userIconCount: 0,
  });

  useEffect(() => {
    if (!enabled) return;

    const fetchCounts = async () => {
      try {
        const response = await fetch('/api/customer/badge-counts', {
          credentials: 'include',
          headers: {
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
          },
        });

        if (response.ok) {
          const data = await response.json();
          setCounts({
            orderStatusCount: data.orderStatusCount || 0,
            repairStatusCount: data.repairStatusCount || 0,
            chatIconCount: data.chatIconCount || 0,
            userIconCount: data.userIconCount || 0,
          });
        } else if (response.status === 401) {
          // User is not authenticated, redirect to login
          router.visit('/user/login');
        }
      } catch (error) {
        console.error('Failed to fetch badge counts:', error);
      }
    };

    // Initial fetch
    fetchCounts();

    // Poll every 2 seconds
    const interval = setInterval(fetchCounts, 2000);

    return () => clearInterval(interval);
  }, [enabled]);

  return counts;
}
