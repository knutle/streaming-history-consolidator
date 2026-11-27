import { Link, usePage } from '@inertiajs/react';
import {
    BookOpen,
    ChevronDown,
    Disc3,
    FileUp,
    Folder,
    LayoutGrid,
    Link2,
    Menu,
    Music2,
    Users,
} from 'lucide-react';
import AppLogo from '@/components/app-logo';
import { Breadcrumbs } from '@/components/breadcrumbs';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    NavigationMenu,
    NavigationMenuItem,
    NavigationMenuList,
    navigationMenuTriggerStyle,
} from '@/components/ui/navigation-menu';
import {
    Sheet,
    SheetContent,
    SheetHeader,
    SheetTitle,
    SheetTrigger,
} from '@/components/ui/sheet';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { UserMenuContent } from '@/components/user-menu-content';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { useInitials } from '@/hooks/use-initials';
import { cn, toUrl } from '@/lib/utils';
import { dashboard } from '@/routes';
import { index as albumsIndex } from '@/routes/albums';
import { index as artistsIndex } from '@/routes/artists';
import { index as importsIndex } from '@/routes/imports';
import { index as listensIndex } from '@/routes/listens';
import { index as matchesIndex } from '@/routes/matches';
import type { BreadcrumbItem, NavItem } from '@/types';

type Props = {
    breadcrumbs?: BreadcrumbItem[];
};

const primaryNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        href: dashboard(),
        icon: LayoutGrid,
    },
    {
        title: 'Listening history',
        href: listensIndex(),
        icon: Music2,
    },
    {
        title: 'Artists',
        href: artistsIndex(),
        icon: Users,
    },
    {
        title: 'Albums',
        href: albumsIndex(),
        icon: Disc3,
    },
];

const overflowNavItems: NavItem[] = [
    {
        title: 'Imports',
        href: importsIndex(),
        icon: FileUp,
    },
    {
        title: 'Manual matches',
        href: matchesIndex(),
        icon: Link2,
    },
];

const mainNavItems: NavItem[] = [...primaryNavItems, ...overflowNavItems];

const rightNavItems: NavItem[] = [
    {
        title: 'Repository',
        href: 'https://github.com/knutle/weftstream',
        icon: Folder,
    },
    {
        title: 'Documentation',
        href: 'https://github.com/knutle/weftstream/blob/main/README.md',
        icon: BookOpen,
    },
];

const activeItemStyles = 'text-emerald-800 dark:text-emerald-300';
const mobileActiveItemStyles =
    'bg-emerald-50 text-emerald-900 dark:bg-emerald-950/50 dark:text-emerald-100';

export function AppHeader({ breadcrumbs = [] }: Props) {
    const page = usePage();
    const { auth } = page.props;
    const getInitials = useInitials();
    const { isCurrentUrl, whenCurrentUrl } = useCurrentUrl();
    const isOverflowActive = overflowNavItems.some((item) =>
        isCurrentUrl(item.href),
    );

    return (
        <>
            <header className="border-b bg-background">
                <div className="mx-auto flex min-h-16 w-full max-w-6xl items-center gap-3 px-5 py-4 sm:px-8">
                    <div className="lg:hidden">
                        <Sheet>
                            <SheetTrigger asChild>
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    className="size-9 shrink-0"
                                >
                                    <Menu className="size-5" />
                                    <span className="sr-only">Open menu</span>
                                </Button>
                            </SheetTrigger>
                            <SheetContent
                                side="left"
                                className="flex h-full w-80 max-w-[calc(100vw-1rem)] flex-col bg-background p-5"
                            >
                                <SheetTitle className="sr-only">
                                    Application navigation
                                </SheetTitle>
                                <SheetHeader className="border-b pb-5 text-left">
                                    <Link
                                        href={dashboard()}
                                        prefetch
                                        className="w-fit"
                                    >
                                        <AppLogo />
                                    </Link>
                                </SheetHeader>
                                <div className="flex flex-1 flex-col gap-6 py-6">
                                    <nav
                                        aria-label="Primary navigation"
                                        className="flex flex-col gap-1"
                                    >
                                        {mainNavItems.map((item) => {
                                            const isActive = isCurrentUrl(
                                                item.href,
                                            );

                                            return (
                                                <Link
                                                    key={item.title}
                                                    href={item.href}
                                                    aria-current={
                                                        isActive
                                                            ? 'page'
                                                            : undefined
                                                    }
                                                    className={cn(
                                                        'flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium text-muted-foreground transition-colors hover:bg-accent hover:text-accent-foreground',
                                                        isActive &&
                                                            mobileActiveItemStyles,
                                                    )}
                                                >
                                                    {item.icon && (
                                                        <item.icon className="size-5" />
                                                    )}
                                                    <span>{item.title}</span>
                                                </Link>
                                            );
                                        })}
                                    </nav>
                                    <nav
                                        aria-label="Project resources"
                                        className="mt-auto flex flex-col gap-1 border-t pt-4"
                                    >
                                        {rightNavItems.map((item) => (
                                            <a
                                                key={item.title}
                                                href={toUrl(item.href)}
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                className="flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium text-muted-foreground transition-colors hover:bg-accent hover:text-accent-foreground"
                                            >
                                                {item.icon && (
                                                    <item.icon className="size-5" />
                                                )}
                                                <span>{item.title}</span>
                                            </a>
                                        ))}
                                    </nav>
                                </div>
                            </SheetContent>
                        </Sheet>
                    </div>

                    <Link
                        href={dashboard()}
                        prefetch
                        aria-label="Go to dashboard"
                        className="min-w-0 shrink-0"
                    >
                        <AppLogo />
                    </Link>

                    <nav
                        aria-label="Primary navigation"
                        className="hidden min-w-0 flex-1 justify-center lg:flex"
                    >
                        <NavigationMenu className="flex max-w-full items-center">
                            <NavigationMenuList className="flex flex-wrap items-center justify-center gap-1">
                                {primaryNavItems.map((item) => (
                                    <NavigationMenuItem
                                        key={item.title}
                                        className="relative flex items-center"
                                    >
                                        <Link
                                            href={item.href}
                                            aria-current={
                                                isCurrentUrl(item.href)
                                                    ? 'page'
                                                    : undefined
                                            }
                                            className={cn(
                                                navigationMenuTriggerStyle(),
                                                whenCurrentUrl(
                                                    item.href,
                                                    activeItemStyles,
                                                ),
                                                'h-9 cursor-pointer px-2.5 text-sm',
                                            )}
                                        >
                                            {item.icon && (
                                                <item.icon className="mr-1.5 size-4" />
                                            )}
                                            {item.title}
                                        </Link>
                                        {isCurrentUrl(item.href) && (
                                            <div className="absolute inset-x-2 bottom-0 h-0.5 rounded-full bg-emerald-700 dark:bg-emerald-400" />
                                        )}
                                    </NavigationMenuItem>
                                ))}
                                <NavigationMenuItem className="relative flex items-center">
                                    <DropdownMenu>
                                        <DropdownMenuTrigger
                                            className={cn(
                                                navigationMenuTriggerStyle(),
                                                isOverflowActive &&
                                                    activeItemStyles,
                                                'h-9 cursor-pointer px-2.5 text-sm',
                                            )}
                                        >
                                            More
                                            <ChevronDown className="ml-1 size-4" />
                                        </DropdownMenuTrigger>
                                        <DropdownMenuContent align="start">
                                            {overflowNavItems.map((item) => (
                                                <DropdownMenuItem
                                                    key={item.title}
                                                    asChild
                                                >
                                                    <Link
                                                        href={item.href}
                                                        aria-current={
                                                            isCurrentUrl(
                                                                item.href,
                                                            )
                                                                ? 'page'
                                                                : undefined
                                                        }
                                                        className={cn(
                                                            'flex items-center gap-2',
                                                            isCurrentUrl(
                                                                item.href,
                                                            ) &&
                                                                mobileActiveItemStyles,
                                                        )}
                                                    >
                                                        {item.icon && (
                                                            <item.icon className="size-4" />
                                                        )}
                                                        {item.title}
                                                    </Link>
                                                </DropdownMenuItem>
                                            ))}
                                        </DropdownMenuContent>
                                    </DropdownMenu>
                                    {isOverflowActive && (
                                        <div className="absolute inset-x-2 bottom-0 h-0.5 rounded-full bg-emerald-700 dark:bg-emerald-400" />
                                    )}
                                </NavigationMenuItem>
                            </NavigationMenuList>
                        </NavigationMenu>
                    </nav>

                    <div className="ml-auto flex shrink-0 items-center gap-1">
                        <nav
                            aria-label="Project resources"
                            className="hidden items-center gap-1 md:flex"
                        >
                            {rightNavItems.map((item) => (
                                <Tooltip key={item.title}>
                                    <TooltipTrigger asChild>
                                        <a
                                            href={toUrl(item.href)}
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            aria-label={item.title}
                                            className="group inline-flex h-9 w-9 items-center justify-center rounded-md bg-transparent p-0 text-sm font-medium text-accent-foreground ring-offset-background transition-colors hover:bg-accent hover:text-accent-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none disabled:pointer-events-none disabled:opacity-50"
                                        >
                                            {item.icon && (
                                                <item.icon className="size-5 opacity-80 group-hover:opacity-100" />
                                            )}
                                        </a>
                                    </TooltipTrigger>
                                    <TooltipContent>
                                        <p>{item.title}</p>
                                    </TooltipContent>
                                </Tooltip>
                            ))}
                        </nav>
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <Button
                                    variant="ghost"
                                    className="size-10 rounded-full p-1"
                                >
                                    <Avatar className="size-8 overflow-hidden rounded-full">
                                        <AvatarImage
                                            src={auth.user?.avatar}
                                            alt={auth.user?.name}
                                        />
                                        <AvatarFallback className="rounded-lg bg-emerald-100 text-emerald-950 dark:bg-emerald-900 dark:text-emerald-100">
                                            {getInitials(auth.user?.name ?? '')}
                                        </AvatarFallback>
                                    </Avatar>
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent className="w-56" align="end">
                                {auth.user && (
                                    <UserMenuContent user={auth.user} />
                                )}
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </div>
                </div>
            </header>
            {breadcrumbs.length > 1 && (
                <div className="w-full border-b">
                    <div className="mx-auto flex h-12 w-full max-w-6xl items-center px-5 text-muted-foreground sm:px-8">
                        <Breadcrumbs breadcrumbs={breadcrumbs} />
                    </div>
                </div>
            )}
        </>
    );
}
