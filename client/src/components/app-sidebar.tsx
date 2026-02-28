import { useLocation, Link } from "wouter";
import {
  Sidebar,
  SidebarContent,
  SidebarGroup,
  SidebarGroupContent,
  SidebarGroupLabel,
  SidebarMenu,
  SidebarMenuButton,
  SidebarMenuItem,
  SidebarHeader,
  SidebarFooter,
} from "@/components/ui/sidebar";
import { Code2, BookOpen, Terminal, LayoutDashboard } from "lucide-react";

const navItems = [
  { title: "Playground", url: "/", icon: Terminal },
  { title: "Snippets", url: "/snippets", icon: BookOpen },
];

export function AppSidebar() {
  const [location] = useLocation();

  return (
    <Sidebar>
      <SidebarHeader className="p-4">
        <Link href="/" className="flex items-center gap-2.5">
          <div className="w-8 h-8 rounded-md bg-primary flex items-center justify-center">
            <Code2 className="w-4 h-4 text-primary-foreground" />
          </div>
          <div className="flex flex-col">
            <span className="text-sm font-semibold leading-tight" data-testid="text-app-name">
              PHP Playground
            </span>
            <span className="text-xs text-muted-foreground leading-tight">
              v8.2
            </span>
          </div>
        </Link>
      </SidebarHeader>
      <SidebarContent>
        <SidebarGroup>
          <SidebarGroupLabel>Navigation</SidebarGroupLabel>
          <SidebarGroupContent>
            <SidebarMenu>
              {navItems.map((item) => (
                <SidebarMenuItem key={item.title}>
                  <SidebarMenuButton
                    asChild
                    isActive={location === item.url}
                  >
                    <Link href={item.url} data-testid={`link-nav-${item.title.toLowerCase()}`}>
                      <item.icon className="w-4 h-4" />
                      <span>{item.title}</span>
                    </Link>
                  </SidebarMenuButton>
                </SidebarMenuItem>
              ))}
            </SidebarMenu>
          </SidebarGroupContent>
        </SidebarGroup>
      </SidebarContent>
      <SidebarFooter className="p-4">
        <a
          href="/portal"
          className="flex items-center gap-2 px-3 py-2 rounded-md text-sm font-medium hover:bg-accent transition-colors"
          data-testid="link-portal"
        >
          <LayoutDashboard className="w-4 h-4" />
          <span>Client Portal</span>
        </a>
        <p className="text-xs text-muted-foreground text-center mt-2">
          PHP {">"}= 8.2 supported
        </p>
      </SidebarFooter>
    </Sidebar>
  );
}
