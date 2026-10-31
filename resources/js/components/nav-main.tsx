import { SidebarGroup, SidebarGroupLabel, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { useCan } from '@/hooks/use-auth';
import { isActiveUrl } from '@/lib/nav';
import { type NavGroup } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { motion, useReducedMotion } from 'motion/react';

export function NavMain({ groups }: { groups: NavGroup[] }) {
    const page = usePage();
    const can = useCan();
    const reduceMotion = useReducedMotion();

    return (
        <>
            {groups.map((group) => {
                const items = group.items.filter((item) => !item.permission || can(item.permission));

                if (items.length === 0) {
                    return null;
                }

                return (
                    <SidebarGroup key={group.title} className="px-2 py-0">
                        <SidebarGroupLabel className="text-sidebar-foreground/50 text-2xs tracking-[0.12em] uppercase">
                            {group.title}
                        </SidebarGroupLabel>
                        <SidebarMenu>
                            {items.map((item) => {
                                const isActive = isActiveUrl(page.url, item.url);

                                return (
                                    <SidebarMenuItem key={item.url}>
                                        {isActive && (
                                            /*
                                             * The ledger rail. One marker for the whole sidebar, so
                                             * it slides from the old item to the new one instead of
                                             * blinking out and back in.
                                             */
                                            <motion.span
                                                layoutId="sidebar-active-rail"
                                                aria-hidden="true"
                                                className="bg-sidebar-primary absolute top-1.5 bottom-1.5 left-0 w-[3px] rounded-full"
                                                transition={reduceMotion ? { duration: 0 } : { type: 'spring', stiffness: 380, damping: 30 }}
                                            />
                                        )}
                                        <SidebarMenuButton asChild isActive={isActive} tooltip={item.title}>
                                            <Link href={item.url} prefetch>
                                                {item.icon && <item.icon />}
                                                <span>{item.title}</span>
                                            </Link>
                                        </SidebarMenuButton>
                                    </SidebarMenuItem>
                                );
                            })}
                        </SidebarMenu>
                    </SidebarGroup>
                );
            })}
        </>
    );
}
