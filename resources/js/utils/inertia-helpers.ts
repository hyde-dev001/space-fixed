/**
 * Inertia helper utilities for better error handling and cache management
 */

import { router } from '@inertiajs/react';

/**
 * Clear Inertia page cache to prevent "Content unavailable" errors
 * Only clears when absolutely necessary to avoid disrupting normal navigation
 */
export function clearInertiaCache() {
  try {
    // Clear page cache only if the router method exists
    if (router && typeof router.clearHistory === 'function') {
      router.clearHistory();
    }
    
    // Don't clear session storage as it may contain authentication data
  } catch (error) {
    console.warn('Failed to clear Inertia cache:', error);
  }
}

/**
 * Handle Inertia navigation errors with automatic cache clearing
 */
export function handleInertiaError(error: any) {
  console.error('Inertia error:', error);
  
  const errorMessage = error?.message || error?.toString() || 'Unknown error';
  
  // Check for cache-related errors
  if (
    errorMessage.includes('not cached') ||
    errorMessage.includes('Content unavailable') ||
    errorMessage.includes('page cache')
  ) {
    clearInertiaCache();
    
    // Offer to reload the page
    if (typeof window !== 'undefined') {
      const shouldReload = confirm(
        'A caching error occurred. The page will reload to fix this. Click OK to continue.'
      );
      if (shouldReload) {
        window.location.reload();
      }
    }
  }
}

/**
 * Setup global Inertia error handlers
 */
export function setupInertiaErrorHandling() {
  if (typeof window === 'undefined') return;
  
  // Track last activity for session monitoring (passive monitoring only)
  let lastActivity = Date.now();
  
  const updateActivity = () => {
    lastActivity = Date.now();
    sessionStorage.setItem('last_activity', lastActivity.toString());
  };
  
  // Update activity on user interaction (passive monitoring, no interference)
  ['click', 'keypress'].forEach(event => {
    document.addEventListener(event, updateActivity, { passive: true });
  });
  
  // Handle page visibility changes (helps with stale sessions)
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') {
      // Check if session might be stale when user returns to tab
      const storedActivity = sessionStorage.getItem('last_activity');
      if (storedActivity) {
        const timeSinceActivity = Date.now() - parseInt(storedActivity);
        // If more than 2 hours (session lifetime), just log a warning
        if (timeSinceActivity > 120 * 60 * 1000) {
          console.warn('Session may be stale due to inactivity');
          // Don't auto-clear cache or reload - let the server handle authentication
        }
      }
    }
    updateActivity();
  });
}
