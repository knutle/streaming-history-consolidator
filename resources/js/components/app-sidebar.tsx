import { Link } from '@inertiajs/react';
import {
    BookOpen,
    Disc3,
    FolderGit2,
    LayoutGrid,
    Link2,
    Music2,
    Upload,
    Users,
} from 'lucide-react';
import AppLogo from '@/components/app-logo';
import { NavFooter } from '@/components/nav-footer';
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
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import { index as albumsIndex } from '@/routes/albums';
import { index as artistsIndex } from '@/routes/artists';
import { index as importsIndex } from '@/routes/imports';
import { index as listensIndex } from '@/routes/listens';
import { index as matchesIndex } from '@/routes/matches';
import type { NavItem } from '@/types';

const mainNavItems: NavItem[] = [
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
    {
        title: 'Imports',
        href: importsIndex(),
        icon: Upload,
    },
    {
        title: 'Manual matches',
        href: matchesIndex(),
        icon: Link2,
    },
];

const footerNavItems: NavItem[] = [
    {
        title: 'Repository',
        href: 'https://github.com/knutle/playweft',
        icon: FolderGit2,
    },
    {
        title: 'Documentation',
        href: 'https://github.com/knutle/playweft/blob/main/README.md',
        icon: BookOpen,
    },
];

export function AppSidebar() {
    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={dashboard()} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={mainNavItems} />
            </SidebarContent>

            <SidebarFooter>
                <NavFooter items={footerNavItems} className="mt-auto" />
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
