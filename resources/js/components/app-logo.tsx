import AppLogoIcon from '@/components/app-logo-icon';
import { appName } from '@/lib/app-name';

export default function AppLogo() {
    return (
        <span className="flex min-w-0 items-center gap-2">
            <span className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-emerald-700 text-white dark:bg-emerald-500 dark:text-emerald-950">
                <AppLogoIcon className="size-5" />
            </span>
            <span className="min-w-0 truncate text-sm font-semibold tracking-tight">
                <span className="sm:hidden">History</span>
                <span className="hidden sm:inline">{appName}</span>
            </span>
        </span>
    );
}
