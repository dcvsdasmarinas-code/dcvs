"use client"

import Link from "next/link"
import { usePathname } from "next/navigation"
import { cn } from "@/lib/utils"
import {
  Activity,
  BarChart3,
  Calendar,
  Users,
  Wallet,
  Settings,
  Search,
  Command,
  X,
} from "lucide-react"
import { ThemeToggle } from "./theme-toggle"

const navItems = [
  { href: "/", label: "Pulse", icon: Activity, description: "Overview" },
  { href: "/architect", label: "Architect", icon: Calendar, description: "Campaigns" },
  { href: "/analytics", label: "Engine", icon: BarChart3, description: "Analytics" },
  { href: "/audience", label: "Intel", icon: Users, description: "Audience" },
  { href: "/budget", label: "Budget", icon: Wallet, description: "Finance" },
]

interface SidebarProps {
  onOpenCommand?: () => void
  isOpen?: boolean
  onClose?: () => void
}

export function Sidebar({ onOpenCommand, isOpen, onClose }: SidebarProps) {
  const pathname = usePathname()

  return (
    <>
      {/* Mobile Overlay */}
      {isOpen && (
        <div 
          className="fixed inset-0 bg-black/60 backdrop-blur-sm z-40 lg:hidden"
          onClick={onClose}
        />
      )}
      
      <aside className={cn(
        "fixed left-0 top-0 h-screen w-64 flex flex-col border-r border-border bg-background z-50 transition-transform duration-300",
        "lg:translate-x-0",
        isOpen ? "translate-x-0" : "-translate-x-full"
      )}>
      {/* Logo & Theme Toggle */}
      <div className="p-6 border-b border-border">
        <div className="flex items-center justify-between mb-4">
          <Link href="/" className="flex items-center gap-3 group" onClick={onClose}>
            <div className="w-8 h-8 bg-lime flex items-center justify-center">
              <span className="text-background font-mono text-sm font-bold">A</span>
            </div>
            <span className="text-lg font-semibold tracking-tight">AETHER</span>
          </Link>
          <button 
            onClick={onClose}
            className="lg:hidden p-1 hover:bg-surface-hover transition-colors"
          >
            <X className="w-5 h-5" />
          </button>
        </div>
        <ThemeToggle />
      </div>

      {/* Search Trigger */}
      <div className="p-4">
        <button
          onClick={onOpenCommand}
          className="w-full flex items-center gap-3 px-3 py-2 text-sm text-muted-foreground bg-surface border border-border hover:bg-surface-hover transition-colors"
        >
          <Search className="w-4 h-4" />
          <span className="flex-1 text-left">Search...</span>
          <kbd className="flex items-center gap-1 text-xs font-mono bg-background px-1.5 py-0.5 border border-border">
            <Command className="w-3 h-3" />K
          </kbd>
        </button>
      </div>

      {/* Navigation */}
      <nav className="flex-1 p-4 space-y-1">
        {navItems.map((item) => {
          const isActive = pathname === item.href
          return (
            <Link
              key={item.href}
              href={item.href}
              onClick={onClose}
              className={cn(
                "flex items-center gap-3 px-3 py-2.5 text-sm transition-all group relative",
                isActive
                  ? "text-accent-foreground bg-accent"
                  : "text-muted-foreground hover:text-foreground hover:bg-surface-hover"
              )}
            >
              {isActive && (
                <div className="absolute left-0 top-1/2 -translate-y-1/2 w-0.5 h-4 bg-lime lime-glow-sm" />
              )}
              <item.icon
                className={cn(
                  "w-4 h-4 transition-all",
                  isActive ? "text-accent-foreground" : ""
                )}
              />
              <span className="font-medium">{item.label}</span>
              <span className="ml-auto text-xs text-muted-foreground opacity-0 group-hover:opacity-100 transition-opacity">
                {item.description}
              </span>
            </Link>
          )
        })}
      </nav>

      {/* Bottom Section */}
      <div className="p-4 border-t border-border">
        <Link
          href="/settings"
          onClick={onClose}
          className="flex items-center gap-3 px-3 py-2.5 text-sm text-muted-foreground hover:text-foreground hover:bg-surface-hover transition-colors"
        >
          <Settings className="w-4 h-4" />
          <span>Settings</span>
        </Link>

        {/* User */}
        <div className="mt-4 flex items-center gap-3 px-3">
          <div className="w-8 h-8 bg-surface border border-border flex items-center justify-center">
            <span className="text-xs font-mono text-muted-foreground">JD</span>
          </div>
          <div className="flex-1 min-w-0">
            <p className="text-sm font-medium truncate">John Doe</p>
            <p className="text-xs text-muted-foreground truncate">Marketing Lead</p>
          </div>
        </div>
      </div>
    </aside>
    </>
  )
}
