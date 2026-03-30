import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarGroup,
    SidebarGroupContent,
    SidebarGroupLabel,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    SidebarMenuSub,
    SidebarMenuSubButton,
    SidebarMenuSubItem,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes/filament/admin/pages';
import { type SharedData } from '@/types';
import { usePage } from '@inertiajs/react';
import { LayoutGrid } from 'lucide-react';
import AppLogo from './app-logo';

export function AppSidebar() {
    const { auth } = usePage<SharedData>().props;
    const cmsUrl = dashboard.url();
    const twoFactorEnabled = Boolean(auth.user?.two_factor_enabled);

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <div>
                                <AppLogo />
                            </div>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <SidebarGroup className="px-2 py-0">
                    <SidebarGroupLabel>Platform</SidebarGroupLabel>
                    <SidebarGroupContent>
                        <SidebarMenu>
                            <SidebarMenuItem>
                                <SidebarMenuButton asChild tooltip={{ children: 'Dashboard' }}>
                                    <div>
                                        <LayoutGrid />
                                        <span>Dashboard</span>
                                    </div>
                                </SidebarMenuButton>

                                <SidebarMenuSub>
                                    <SidebarMenuSubItem>
                                        {twoFactorEnabled ? (
                                            <SidebarMenuSubButton asChild>
                                                <a href={cmsUrl}>Go To CMS</a>
                                            </SidebarMenuSubButton>
                                        ) : (
                                            <SidebarMenuSubButton asChild aria-disabled="true">
                                                <span>Go To CMS</span>
                                            </SidebarMenuSubButton>
                                        )}
                                    </SidebarMenuSubItem>
                                </SidebarMenuSub>
                            </SidebarMenuItem>
                        </SidebarMenu>

                        {!twoFactorEnabled && (
                            <p className="px-2 pt-2 text-xs text-muted-foreground">
                                You need to enable 2FA to access the CMS.
                            </p>
                        )}
                    </SidebarGroupContent>
                </SidebarGroup>
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
