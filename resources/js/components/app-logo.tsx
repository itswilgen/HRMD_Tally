import { TanjaySeal } from '@/components/tanjay-seal';

/**
 * The seal and the name, at the top of the sidebar on every page. The seal's
 * alt text is empty because the words beside it already name the system; when
 * the sidebar collapses to icons, the link's tooltip carries the name.
 */
export default function AppLogo() {
    return (
        <>
            <TanjaySeal size={36} alt="" className="size-9 group-data-[collapsible=icon]:size-8" />
            <div className="ml-1 grid flex-1 text-left leading-tight group-data-[collapsible=icon]:hidden">
                <span className="text-sidebar-foreground/80 text-2xs font-semibold tracking-[0.12em] uppercase">HRMD · LGU Tanjay City</span>
                <span className="text-sidebar-accent-foreground font-display truncate text-[0.875rem] font-semibold tracking-[-0.01em]">
                    Leave Tally Sheet
                </span>
            </div>
        </>
    );
}
