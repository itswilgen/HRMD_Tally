import AppLogoIcon from './app-logo-icon';

export default function AppLogo() {
    return (
        <>
            <div className="bg-sidebar-primary/15 text-sidebar-primary ring-sidebar-primary/25 flex aspect-square size-9 items-center justify-center rounded-md ring-1">
                <AppLogoIcon className="size-5" />
            </div>
            <div className="ml-1 grid flex-1 text-left">
                <span className="text-2xs text-sidebar-foreground/60 truncate font-medium tracking-[0.14em] uppercase">HRMD</span>
                <span className="text-sidebar-accent-foreground truncate text-sm leading-tight font-semibold">Leave Tally Sheet</span>
            </div>
        </>
    );
}
