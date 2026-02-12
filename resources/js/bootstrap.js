import axios from 'axios';
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
