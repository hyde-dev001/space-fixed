import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.jsx'],
            refresh: true,
        }),
        react({
            include: /src\/.*\.[jt]sx?$/,
        }),
        tailwindcss(),
    ],
    server: {
        // bind to the same host as the backend to avoid cross-origin cookie issues
        host: '127.0.0.1',
        port: 5173,
        strictPort: false,
        cors: true,
        // proxy API and sanctum cookie requests to the backend so the browser stays same-origin
        proxy: {
            '/api': {
                target: 'http://127.0.0.1:8000',
                changeOrigin: true,
                secure: false,
            },
            '/sanctum': {
                target: 'http://127.0.0.1:8000',
                changeOrigin: true,
                secure: false,
            },
            '/user': {
                target: 'http://127.0.0.1:8000',
                changeOrigin: true,
                secure: false,
            },
        },
        hmr: {
            host: '127.0.0.1',
            protocol: 'ws',
        },
    },
    resolve: {
        alias: {
            '@': '/resources/js',
        },
    },
});
