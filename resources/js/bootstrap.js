import axios from 'axios';
import { route as ziggyRoute } from 'ziggy-js';
import { Ziggy } from './ziggy';
window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
window.axios.defaults.withCredentials = true;

// Intercept requests to add CSRF token
window.axios.interceptors.request.use(config => {
    const token = document.head.querySelector('meta[name="csrf-token"]');
    if (token) {
        config.headers['X-CSRF-TOKEN'] = token.content;
    }
    return config;
});

// Handle response errors without redirecting
window.axios.interceptors.response.use(
    response => response,
    error => {
        // Don't redirect on API errors, let the component handle it
        if (error.response?.status === 419) {
            console.error('CSRF token mismatch. Please refresh the page.');
        }
        return Promise.reject(error);
    }
);

// Backward-compatible route() wrapper:
// some legacy pages pass URL paths into route(), which Ziggy rejects.
if (typeof window !== 'undefined') {
    window.route = (name, params, absolute, config) => {
        const runtimeZiggy = typeof window.Ziggy !== 'undefined'
            ? {
                ...Ziggy,
                ...window.Ziggy,
                routes: {
                    ...(Ziggy?.routes || {}),
                    ...(window.Ziggy?.routes || {}),
                },
            }
            : Ziggy;

        if (typeof name === 'string') {
            const trimmed = name.trim();
            if (trimmed.startsWith('/') || trimmed.startsWith('api/')) {
                return trimmed.startsWith('/') ? trimmed : `/${trimmed}`;
            }

            if (!runtimeZiggy?.routes?.[trimmed]) {
                const fallbackNamedRoutes = {
                    'password.request': '/forgot-password',
                    'admin.suspension-appeals': '/admin/appeals',
                };

                if (Object.prototype.hasOwnProperty.call(fallbackNamedRoutes, trimmed)) {
                    return fallbackNamedRoutes[trimmed];
                }
            }
        }

        const resolved = ziggyRoute(name, params, absolute, config || runtimeZiggy);

        // Guard against misconfigured APP_URL in deployment (e.g. localhost),
        // which can make Inertia perform XHR navigation to the wrong origin.
        if (typeof resolved === 'string' && /^https?:\/\//i.test(resolved)) {
            try {
                const parsedUrl = new URL(resolved);
                const currentOrigin = window.location?.origin;

                if (currentOrigin && parsedUrl.origin !== currentOrigin) {
                    return `${parsedUrl.pathname}${parsedUrl.search}${parsedUrl.hash}`;
                }
            } catch (_error) {
                // If URL parsing fails, preserve original behavior.
            }
        }

        return resolved;
    };
}
