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
    build: {
        emptyOutDir: true,
        chunkSizeWarningLimit: 650,
        rollupOptions: {
            output: {
                manualChunks(id) {
                    if (!id.includes('node_modules')) {
                        return;
                    }

                    if (id.includes('react-apexcharts') || id.includes('/apexcharts/')) {
                        return 'vendor-apexcharts';
                    }

                    if (id.includes('/three/examples/')) {
                        return 'vendor-three-examples';
                    }

                    if (id.includes('/three/')) {
                        return 'vendor-three-core';
                    }

                    if (id.includes('/leaflet/')) {
                        return 'vendor-leaflet';
                    }

                    if (id.includes('/sweetalert2/')) {
                        return 'vendor-sweetalert2';
                    }

                    if (id.includes('@fullcalendar/')) {
                        return 'vendor-fullcalendar';
                    }

                    if (id.includes('@react-jvectormap/')) {
                        return 'vendor-jvectormap';
                    }
                },
            },
        },
    },
});
