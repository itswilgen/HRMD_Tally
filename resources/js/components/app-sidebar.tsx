import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type NavGroup } from '@/types';
import { Link } from '@inertiajs/react';
import { Building2, CalendarRange, Contact, LayoutDashboard, SlidersHorizontal, UsersRound } from 'lucide-react';
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
    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href="/dashboard" prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent className="gap-4">
                <NavMain groups={navGroups} />
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
