import { SidebarGroup, SidebarGroupLabel, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { useCan } from '@/hooks/use-can';
import { type NavGroup } from '@/types';
import { Link, usePage } from '@inertiajs/react';

function isActive(currentUrl: string, itemUrl: string): boolean {
    const path = currentUrl.split('?')[0];

    return path === itemUrl || path.startsWith(`${itemUrl}/`);
}

export function NavMain({ groups = [] }: { groups: NavGroup[] }) {
    const page = usePage();
    const can = useCan();

    return (
        <>
            {groups.map((group) => {
                const items = group.items.filter((item) => can(item.permission));

                if (items.length === 0) {
                    return null;
                }

                return (
                    <SidebarGroup key={group.title} className="px-2 py-0">
                        <SidebarGroupLabel>{group.title}</SidebarGroupLabel>
                        <SidebarMenu>
                            {items.map((item) => (
                                <SidebarMenuItem key={item.title}>
                                    <SidebarMenuButton asChild isActive={isActive(page.url, item.url)} tooltip={{ children: item.title }}>
                                        <Link href={item.url} prefetch>
                                            {item.icon && <item.icon />}
                                            <span>{item.title}</span>
                                        </Link>
                                    </SidebarMenuButton>
                                </SidebarMenuItem>
                            ))}
                        </SidebarMenu>
                    </SidebarGroup>
                );
            })}
        </>
    );
}
