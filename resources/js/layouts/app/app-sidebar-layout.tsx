import { AppContent } from '@/components/app-content';
import { AppShell } from '@/components/app-shell';
import { AppSidebar } from '@/components/app-sidebar';
import { AppSidebarHeader } from '@/components/app-sidebar-header';
import { FlashMessages } from '@/components/flash-messages';
import { type BreadcrumbItem } from '@/types';
import { usePage } from '@inertiajs/react';
import { motion, useReducedMotion } from 'motion/react';

export default function AppSidebarLayout({ children, breadcrumbs = [] }: { children: React.ReactNode; breadcrumbs?: BreadcrumbItem[] }) {
    const { component } = usePage();
    const reduceMotion = useReducedMotion();

    return (
        <AppShell variant="sidebar">
            <AppSidebar />
            <AppContent variant="sidebar">
                <AppSidebarHeader breadcrumbs={breadcrumbs} />
                {/*
                  One quiet entrance per page change. Keyed on the component, not
                  the URL, so changing a filter or turning a page does not
                  re-animate the table underneath the person reading it.
                */}
                <motion.main
                    key={component}
                    initial={reduceMotion ? { opacity: 0 } : { opacity: 0, y: 6 }}
                    animate={{ opacity: 1, y: 0 }}
                    transition={reduceMotion ? { duration: 0 } : { duration: 0.25, ease: [0.22, 1, 0.36, 1] }}
                    className="flex min-w-0 flex-1 flex-col gap-6 p-4 md:p-6"
                >
                    {children}
                </motion.main>
                <FlashMessages />
            </AppContent>
        </AppShell>
    );
}
