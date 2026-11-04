import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    SidebarRail,
    useSidebar,
} from '@/components/ui/sidebar';
import { type NavGroup } from '@/types';
import { Link } from '@inertiajs/react';
import { Building2, CalendarRange, Contact, LayoutDashboard, PanelLeftClose, PanelLeftOpen, SlidersHorizontal, UsersRound } from 'lucide-react';
import AppLogo from './app-logo';

/*
 * Only live destinations are listed. The exports and the activity log arrive in
 * later phases and are announced on the dashboard until then, so nobody clicks
 * into a dead end.
 */
const navGroups: NavGroup[] = [
    {
        title: 'Overview',
        items: [{ title: 'Dashboard', url: '/dashboard', icon: LayoutDashboard }],
    },
    {
        title: 'Records',
        items: [
            // The tally sheet first: it is the screen this system exists for,
            // and the masterlist behind it is visited far less often.
            { title: 'Leave tally', url: '/tally', icon: CalendarRange, permission: 'viewTally' },
            { title: 'Employees', url: '/employees', icon: Contact, permission: 'manageEmployees' },
        ],
    },
    {
        title: 'Administration',
        items: [
            { title: 'User accounts', url: '/settings/users', icon: UsersRound, permission: 'manageUsers' },
            // Both roles may read the office list, because the tally sheet is
            // filtered by office; the add and rename controls are what the
            // permission gates, on the page itself.
            { title: 'Offices', url: '/settings/offices', icon: Building2 },
            { title: 'My settings', url: '/settings/profile', icon: SlidersHorizontal },
        ],
    },
];

export function AppSidebar() {
    const { state, toggleSidebar } = useSidebar();
    const isCollapsed = state === 'collapsed';

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem className="flex items-center gap-1">
                        <SidebarMenuButton size="lg" asChild className="flex-1">
                            <Link href="/dashboard" prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                        <button
                            type="button"
                            onClick={toggleSidebar}
                            title={isCollapsed ? 'Expand sidebar' : 'Collapse to icon sidebar'}
                            aria-label={isCollapsed ? 'Expand sidebar' : 'Collapse to icon sidebar'}
                            className="text-sidebar-foreground/60 hover:bg-sidebar-accent flex size-8 cursor-pointer items-center justify-center rounded-lg transition-colors group-data-[collapsible=icon]:hidden hover:text-white"
                        >
                            <PanelLeftClose className="size-4" />
                        </button>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent className="gap-4">
                <NavMain groups={navGroups} />
            </SidebarContent>

            <SidebarFooter className="gap-1">
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton
                            onClick={toggleSidebar}
                            tooltip={isCollapsed ? 'Expand sidebar' : 'Switch to icon sidebar'}
                            className="text-sidebar-foreground/85 hover:bg-sidebar-accent cursor-pointer transition-colors hover:text-white"
                        >
                            {isCollapsed ? (
                                <PanelLeftOpen className="text-sidebar-primary size-4 shrink-0" />
                            ) : (
                                <PanelLeftClose className="size-4 shrink-0" />
                            )}
                            <span className="truncate font-medium">{isCollapsed ? 'Expand sidebar' : 'Collapse to icon'}</span>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
                <NavUser />
            </SidebarFooter>

            <SidebarRail />
        </Sidebar>
    );
}
