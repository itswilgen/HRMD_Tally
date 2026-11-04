import AppLogoIcon from './app-logo-icon';

export default function AppLogo() {
    return (
        <>
            <div className="from-sidebar-primary/30 via-sidebar-primary/20 to-sidebar-primary/10 text-sidebar-primary ring-sidebar-primary/35 flex aspect-square size-9 items-center justify-center rounded-lg bg-gradient-to-br shadow-xs ring-1 transition-transform group-hover:scale-105">
                <AppLogoIcon className="size-5" />
            </div>
            <div className="ml-1.5 grid flex-1 text-left group-data-[collapsible=icon]:hidden">
                <div className="flex items-center gap-1.5">
                    <span className="text-2xs text-sidebar-primary font-bold tracking-[0.16em] uppercase">HRMD</span>
                    <span className="bg-sidebar-primary/20 py-0.2 text-sidebar-primary rounded px-1 text-[9px] font-semibold">GOV</span>
                </div>
                <span className="truncate text-sm font-semibold tracking-tight text-white">Leave Tally Sheet</span>
            </div>
        </>
    );
}
