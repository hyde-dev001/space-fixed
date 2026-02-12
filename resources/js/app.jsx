import './bootstrap';
import '../css/app.css';

import { createRoot } from 'react-dom/client';
import { createInertiaApp, router } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { ThemeProvider } from './context/ThemeContext';
import { SidebarProvider } from './context/SidebarContext';
import { QueryProvider } from './providers/QueryProvider';
import { CartProvider } from './contexts/CartContext';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

// Update CSRF token after each Inertia navigation
router.on('navigate', (event) => {
    const csrfToken = event.detail.page.props.csrf_token;
    if (csrfToken) {
        const metaTag = document.querySelector('meta[name="csrf-token"]');
        if (metaTag) {
            metaTag.setAttribute('content', csrfToken);
        }
    }
});

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: async (name) => {
        // Try Pages directory first
        try {
            return await resolvePageComponent(`./Pages/${name}.tsx`, import.meta.glob('./Pages/**/*.tsx'));
        } catch (error) {
            // Fall back to Pages/ERP for legacy ERP pages
            if (name.startsWith('ERP/')) {
                const erpName = name.replace(/^ERP\//, '');
                return resolvePageComponent(`./Pages/ERP/${erpName}.tsx`, import.meta.glob('./Pages/ERP/**/*.tsx'));
            }
            throw error;
        }
    },
    setup({ el, App, props }) {
        const root = createRoot(el);
        
        // Check if the current page is a user-side page (should not have dark mode)
        const isUserSidePage = props.initialPage.component.startsWith('UserSide/');

        // Always wrap with QueryProvider for global state management
        if (isUserSidePage) {
            root.render(
                <QueryProvider>
                    <ThemeProvider>
                        <CartProvider>
                            <App {...props} />
                        </CartProvider>
                    </ThemeProvider>
                </QueryProvider>
            );
        } else {
            root.render(
                <QueryProvider>
                    <ThemeProvider>
                        <SidebarProvider>
                            <CartProvider>
                                <App {...props} />
                            </CartProvider>
                        </SidebarProvider>
                    </ThemeProvider>
                </QueryProvider>
            );
        }
    },
    progress: {
        color: '#465fff',
    },
});
