/*  */import './bootstrap';
import '../css/app.css';

import { createRoot } from 'react-dom/client';
import { useEffect, useState } from 'react';
import { createInertiaApp, router } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { ThemeProvider } from './context/ThemeContext';
import { SidebarProvider } from './context/SidebarContext';
import { QueryProvider } from './providers/QueryProvider';
import { CartProvider } from './contexts/CartContext';
import { dismissAppLoader } from './utils/appLoader';
import { syncPageTheme } from './utils/pageTheme';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';
const USER_SIDE_SCROLLBAR_CLASS = 'userside-hide-scrollbar';

const syncUserSideScrollbar = (componentName = '') => {
    const isUserSidePage = componentName.startsWith('UserSide/');
    document.documentElement.classList.toggle(USER_SIDE_SCROLLBAR_CLASS, isUserSidePage);
    document.body.classList.toggle(USER_SIDE_SCROLLBAR_CLASS, isUserSidePage);
};

const syncPagePresentation = (componentName = '') => {
    syncUserSideScrollbar(componentName);
    syncPageTheme(componentName);
};

const ApplicationProviders = ({ initialComponent, children }) => {
    const [component, setComponent] = useState(initialComponent);

    useEffect(() => router.on('navigate', (event) => {
        setComponent(event.detail?.page?.component ?? '');
    }), []);

    const isUserSidePage = component.startsWith('UserSide/');
    const isUserAuthPage = component.startsWith('UserSide/Auth/');

    return (
        <QueryProvider>
            <ThemeProvider>
                {isUserSidePage ? (
                    <CartProvider syncEnabled={!isUserAuthPage}>
                        {children}
                    </CartProvider>
                ) : (
                    <SidebarProvider>
                        {children}
                    </SidebarProvider>
                )}
            </ThemeProvider>
        </QueryProvider>
    );
};

// Update CSRF token after each Inertia navigation
router.on('navigate', (event) => {
    const page = event.detail?.page;
    const csrfToken = page?.props?.csrf_token;
    if (csrfToken) {
        const metaTag = document.querySelector('meta[name="csrf-token"]');
        if (metaTag) {
            metaTag.setAttribute('content', csrfToken);
        }
    }

    syncPagePresentation(page?.component ?? '');
});

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: async (name) => {
        const appPages = import.meta.glob([
            './Pages/**/*.tsx',
            '!./Pages/**/__tests__/**',
            '!./Pages/**/*.test.tsx',
            '!./Pages/**/*.spec.tsx',
        ]);

        const erpPages = import.meta.glob([
            './Pages/ERP/**/*.tsx',
            '!./Pages/ERP/**/__tests__/**',
            '!./Pages/ERP/**/*.test.tsx',
            '!./Pages/ERP/**/*.spec.tsx',
        ]);

        // Try Pages directory first
        try {
            return await resolvePageComponent(`./Pages/${name}.tsx`, appPages);
        } catch (error) {
            // Fall back to Pages/ERP for legacy ERP pages
            if (name.startsWith('ERP/')) {
                const erpName = name.replace(/^ERP\//, '');
                return resolvePageComponent(`./Pages/ERP/${erpName}.tsx`, erpPages);
            }
            throw error;
        }
    },
    setup({ el, App, props }) {
        const root = createRoot(el);
        
        const component = props.initialPage.component ?? '';
        syncPagePresentation(component);

        root.render(
            <ApplicationProviders initialComponent={component}>
                <App {...props} />
            </ApplicationProviders>
        );

        dismissAppLoader();
    },
    progress: {
        color: '#465fff',
    },
});
