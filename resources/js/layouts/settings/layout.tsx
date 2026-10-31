import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { isActiveUrl } from '@/lib/nav';
import { cn } from '@/lib/utils';
import { type NavItem } from '@/types';
import { Link, usePage } from '@inertiajs/react';

const sidebarNavItems: NavItem[] = [
    { title: 'Profile', url: '/settings/profile' },
    { title: 'Password', url: '/settings/password' },
    { title: 'Appearance', url: '/settings/appearance' },
];

export default function SettingsLayout({ children }: { children: React.ReactNode }) {
    const page = usePage();

    return (
        <div className="space-y-6">
            <PageHeader eyebrow="My settings" title="Settings" description="Your own name, sign-in password and how the interface looks." />

            <div className="flex flex-col gap-8 lg:flex-row lg:gap-12">
                <aside className="w-full lg:w-48">
                    <nav className="flex flex-col gap-1">
                        {sidebarNavItems.map((item) => (
                            <Button
                                key={item.url}
                                size="sm"
                                variant="ghost"
                                asChild
                                className={cn('w-full justify-start', {
                                    'bg-muted font-medium': isActiveUrl(page.url, item.url),
                                })}
                            >
                                <Link href={item.url} prefetch>
                                    {item.title}
                                </Link>
                            </Button>
                        ))}
                    </nav>
                </aside>

                <Separator className="lg:hidden" />

                <div className="flex-1">
                    <section className="max-w-xl space-y-12">{children}</section>
                </div>
            </div>
        </div>
    );
}
