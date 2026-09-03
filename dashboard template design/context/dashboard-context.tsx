"use client"

import React, { createContext, useContext, useState, useCallback, useEffect } from "react"

// Types
export type CampaignStatus = "live" | "paused" | "scheduled" | "ended"
export type AssetType = "image" | "video"

export interface Campaign {
  id: string
  name: string
  status: CampaignStatus
  spend: number
  impressions: number
  channel: string
  lastUpdated: string
  startDate?: Date
  endDate?: Date
  progress?: number
  budget?: number
}

export interface Asset {
  id: string
  name: string
  type: AssetType
  thumbnail: string
  impressions: number
  engagement: number
  ctr: number
  campaign: string
  size: "small" | "medium" | "large"
}

export interface AudienceSegment {
  id: string
  name: string
  size: number
  growth: number
  engagement: number
}

export interface BudgetItem {
  channel: string
  allocated: number
  spent: number
}

export interface Notification {
  id: string
  title: string
  message: string
  type: "success" | "warning" | "error" | "info"
  timestamp: Date
}

interface DashboardState {
  // Campaigns
  campaigns: Campaign[]
  selectedCampaignId: string | null
  
  // Assets
  assets: Asset[]
  selectedAssetId: string | null
  
  // Budget
  totalBudget: number
  dailyBudget: number
  totalSpent: number
  channelBudgets: BudgetItem[]
  
  // Audience
  audienceSegments: AudienceSegment[]
  
  // UI State
  notifications: Notification[]
  isLoading: boolean
  
  // Metrics (live updating)
  metrics: {
    totalImpressions: number
    activeROAS: number
    conversionRate: number
    totalClicks: number
  }
}

interface DashboardContextType extends DashboardState {
  // Campaign actions
  addCampaign: (campaign: Omit<Campaign, "id">) => void
  updateCampaign: (id: string, updates: Partial<Campaign>) => void
  deleteCampaign: (id: string) => void
  toggleCampaignStatus: (id: string) => void
  selectCampaign: (id: string | null) => void
  
  // Asset actions
  addAsset: (asset: Omit<Asset, "id">) => void
  deleteAsset: (id: string) => void
  selectAsset: (id: string | null) => void
  
  // Budget actions
  updateBudget: (totalBudget: number, dailyBudget: number) => void
  updateChannelBudget: (channel: string, allocated: number) => void
  addSpend: (channel: string, amount: number) => void
  
  // Notification actions
  addNotification: (notification: Omit<Notification, "id" | "timestamp">) => void
  dismissNotification: (id: string) => void
  
  // Utility
  refreshMetrics: () => void
}

// Initial data
const initialCampaigns: Campaign[] = [
  {
    id: "camp-001",
    name: "Q1 Brand Awareness Push",
    status: "live",
    spend: 12450,
    impressions: 892000,
    channel: "Meta Ads",
    lastUpdated: "2m ago",
    startDate: new Date("2026-01-01"),
    endDate: new Date("2026-02-15"),
    progress: 72,
    budget: 50000,
  },
  {
    id: "camp-002",
    name: "Product Launch - Series X",
    status: "live",
    spend: 8920,
    impressions: 456000,
    channel: "Google Ads",
    lastUpdated: "5m ago",
    startDate: new Date("2026-01-10"),
    endDate: new Date("2026-02-28"),
    progress: 45,
    budget: 75000,
  },
  {
    id: "camp-003",
    name: "Retargeting - Cart Abandoners",
    status: "live",
    spend: 3240,
    impressions: 234000,
    channel: "Meta Ads",
    lastUpdated: "8m ago",
    startDate: new Date("2026-01-05"),
    endDate: new Date("2026-03-31"),
    progress: 28,
    budget: 25000,
  },
  {
    id: "camp-004",
    name: "Spring Sale Promotion",
    status: "scheduled",
    spend: 0,
    impressions: 0,
    channel: "TikTok Ads",
    lastUpdated: "1h ago",
    startDate: new Date("2026-02-15"),
    endDate: new Date("2026-03-15"),
    progress: 0,
    budget: 40000,
  },
  {
    id: "camp-005",
    name: "Influencer Partnership - Wave 2",
    status: "paused",
    spend: 5670,
    impressions: 312000,
    channel: "YouTube",
    lastUpdated: "3h ago",
    startDate: new Date("2026-01-15"),
    endDate: new Date("2026-02-28"),
    progress: 35,
    budget: 60000,
  },
  {
    id: "camp-006",
    name: "Holiday Campaign 2024",
    status: "ended",
    spend: 45000,
    impressions: 2100000,
    channel: "Multi-channel",
    lastUpdated: "2d ago",
    startDate: new Date("2025-11-01"),
    endDate: new Date("2025-12-31"),
    progress: 100,
    budget: 45000,
  },
  {
    id: "camp-007",
    name: "Email Nurture Sequence",
    status: "live",
    spend: 2340,
    impressions: 156000,
    channel: "Email",
    lastUpdated: "15m ago",
    startDate: new Date("2026-01-01"),
    endDate: new Date("2026-04-30"),
    progress: 22,
    budget: 15000,
  },
]

const initialAssets: Asset[] = [
  { id: "ast-001", name: "Hero Banner - Spring Launch", type: "image", thumbnail: "/assets/campaign-hero-1.jpg", impressions: 892000, engagement: 8.5, ctr: 4.2, campaign: "Q1 Brand Awareness", size: "large" },
  { id: "ast-002", name: "Fashion Lifestyle Shoot", type: "video", thumbnail: "/assets/campaign-hero-2.jpg", impressions: 456000, engagement: 12.3, ctr: 6.8, campaign: "Product Launch", size: "medium" },
  { id: "ast-003", name: "Abstract Brand Visual", type: "image", thumbnail: "/assets/campaign-hero-3.jpg", impressions: 234000, engagement: 6.2, ctr: 3.1, campaign: "Retargeting", size: "small" },
  { id: "ast-004", name: "Skincare Product Flat Lay", type: "image", thumbnail: "/assets/campaign-hero-4.jpg", impressions: 678000, engagement: 9.8, ctr: 5.5, campaign: "Spring Sale", size: "small" },
  { id: "ast-005", name: "Fitness Campaign Video", type: "video", thumbnail: "/assets/campaign-hero-5.jpg", impressions: 312000, engagement: 15.2, ctr: 7.9, campaign: "Influencer Wave 2", size: "medium" },
  { id: "ast-006", name: "Luxury Watch Product Shot", type: "image", thumbnail: "/assets/campaign-hero-6.jpg", impressions: 189000, engagement: 5.4, ctr: 2.8, campaign: "Email Nurture", size: "small" },
  { id: "ast-007", name: "Interior Design Showcase", type: "image", thumbnail: "/assets/campaign-hero-7.jpg", impressions: 245000, engagement: 11.7, ctr: 6.2, campaign: "Social Proof", size: "small" },
  { id: "ast-008", name: "Coffee Brand Story", type: "video", thumbnail: "/assets/campaign-hero-8.jpg", impressions: 523000, engagement: 18.9, ctr: 8.4, campaign: "Brand Awareness", size: "large" },
]

const initialChannelBudgets: BudgetItem[] = [
  { channel: "Meta Ads", allocated: 120000, spent: 78500 },
  { channel: "Google Ads", allocated: 95000, spent: 62300 },
  { channel: "TikTok Ads", allocated: 45000, spent: 12800 },
  { channel: "YouTube", allocated: 60000, spent: 38200 },
  { channel: "Email", allocated: 15000, spent: 8900 },
  { channel: "Display", allocated: 30000, spent: 18400 },
]

const DashboardContext = createContext<DashboardContextType | undefined>(undefined)

export function DashboardProvider({ children }: { children: React.ReactNode }) {
  const [campaigns, setCampaigns] = useState<Campaign[]>(initialCampaigns)
  const [selectedCampaignId, setSelectedCampaignId] = useState<string | null>(null)
  const [assets, setAssets] = useState<Asset[]>(initialAssets)
  const [selectedAssetId, setSelectedAssetId] = useState<string | null>(null)
  const [totalBudget, setTotalBudget] = useState(365000)
  const [dailyBudget, setDailyBudget] = useState(35000)
  const [totalSpent, setTotalSpent] = useState(219100)
  const [channelBudgets, setChannelBudgets] = useState<BudgetItem[]>(initialChannelBudgets)
  const [audienceSegments] = useState<AudienceSegment[]>([
    { id: "seg-001", name: "High-Value Customers", size: 45000, growth: 12.5, engagement: 8.2 },
    { id: "seg-002", name: "Cart Abandoners", size: 128000, growth: -3.2, engagement: 4.5 },
    { id: "seg-003", name: "New Visitors", size: 892000, growth: 25.8, engagement: 2.1 },
  ])
  const [notifications, setNotifications] = useState<Notification[]>([])
  const [isLoading] = useState(false)
  const [metrics, setMetrics] = useState({
    totalImpressions: 2847593,
    activeROAS: 4.2,
    conversionRate: 3.8,
    totalClicks: 127432,
  })

  // Simulate live metric updates
  useEffect(() => {
    const interval = setInterval(() => {
      setMetrics(prev => ({
        totalImpressions: prev.totalImpressions + Math.floor(Math.random() * 100),
        activeROAS: Math.round((prev.activeROAS + (Math.random() - 0.5) * 0.1) * 100) / 100,
        conversionRate: Math.round((prev.conversionRate + (Math.random() - 0.5) * 0.05) * 100) / 100,
        totalClicks: prev.totalClicks + Math.floor(Math.random() * 10),
      }))
    }, 5000)
    return () => clearInterval(interval)
  }, [])

  // Campaign actions
  const addCampaign = useCallback((campaign: Omit<Campaign, "id">) => {
    const newCampaign: Campaign = {
      ...campaign,
      id: `camp-${String(Date.now()).slice(-6)}`,
    }
    setCampaigns(prev => [newCampaign, ...prev])
    addNotification({ title: "Campaign Created", message: `${newCampaign.name} has been created`, type: "success" })
  }, [])

  const updateCampaign = useCallback((id: string, updates: Partial<Campaign>) => {
    setCampaigns(prev => prev.map(c => c.id === id ? { ...c, ...updates, lastUpdated: "Just now" } : c))
  }, [])

  const deleteCampaign = useCallback((id: string) => {
    const campaign = campaigns.find(c => c.id === id)
    setCampaigns(prev => prev.filter(c => c.id !== id))
    if (campaign) {
      addNotification({ title: "Campaign Deleted", message: `${campaign.name} has been deleted`, type: "info" })
    }
  }, [campaigns])

  const toggleCampaignStatus = useCallback((id: string) => {
    setCampaigns(prev => prev.map(c => {
      if (c.id !== id) return c
      const newStatus: CampaignStatus = c.status === "live" ? "paused" : c.status === "paused" ? "live" : c.status
      return { ...c, status: newStatus, lastUpdated: "Just now" }
    }))
  }, [])

  const selectCampaign = useCallback((id: string | null) => {
    setSelectedCampaignId(id)
  }, [])

  // Asset actions
  const addAsset = useCallback((asset: Omit<Asset, "id">) => {
    const newAsset: Asset = {
      ...asset,
      id: `ast-${String(Date.now()).slice(-6)}`,
    }
    setAssets(prev => [newAsset, ...prev])
    addNotification({ title: "Asset Uploaded", message: `${newAsset.name} has been added`, type: "success" })
  }, [])

  const deleteAsset = useCallback((id: string) => {
    const asset = assets.find(a => a.id === id)
    setAssets(prev => prev.filter(a => a.id !== id))
    if (asset) {
      addNotification({ title: "Asset Deleted", message: `${asset.name} has been removed`, type: "info" })
    }
  }, [assets])

  const selectAsset = useCallback((id: string | null) => {
    setSelectedAssetId(id)
  }, [])

  // Budget actions
  const updateBudget = useCallback((newTotalBudget: number, newDailyBudget: number) => {
    setTotalBudget(newTotalBudget)
    setDailyBudget(newDailyBudget)
  }, [])

  const updateChannelBudget = useCallback((channel: string, allocated: number) => {
    setChannelBudgets(prev => prev.map(b => b.channel === channel ? { ...b, allocated } : b))
  }, [])

  const addSpend = useCallback((channel: string, amount: number) => {
    setChannelBudgets(prev => prev.map(b => b.channel === channel ? { ...b, spent: b.spent + amount } : b))
    setTotalSpent(prev => prev + amount)
  }, [])

  // Notification actions
  const addNotification = useCallback((notification: Omit<Notification, "id" | "timestamp">) => {
    const newNotification: Notification = {
      ...notification,
      id: `notif-${Date.now()}`,
      timestamp: new Date(),
    }
    setNotifications(prev => [newNotification, ...prev].slice(0, 10))
    
    // Auto-dismiss after 5 seconds
    setTimeout(() => {
      setNotifications(prev => prev.filter(n => n.id !== newNotification.id))
    }, 5000)
  }, [])

  const dismissNotification = useCallback((id: string) => {
    setNotifications(prev => prev.filter(n => n.id !== id))
  }, [])

  const refreshMetrics = useCallback(() => {
    setMetrics({
      totalImpressions: Math.floor(2800000 + Math.random() * 100000),
      activeROAS: Math.round((4 + Math.random()) * 100) / 100,
      conversionRate: Math.round((3.5 + Math.random() * 0.5) * 100) / 100,
      totalClicks: Math.floor(125000 + Math.random() * 5000),
    })
  }, [])

  const value: DashboardContextType = {
    campaigns,
    selectedCampaignId,
    assets,
    selectedAssetId,
    totalBudget,
    dailyBudget,
    totalSpent,
    channelBudgets,
    audienceSegments,
    notifications,
    isLoading,
    metrics,
    addCampaign,
    updateCampaign,
    deleteCampaign,
    toggleCampaignStatus,
    selectCampaign,
    addAsset,
    deleteAsset,
    selectAsset,
    updateBudget,
    updateChannelBudget,
    addSpend,
    addNotification,
    dismissNotification,
    refreshMetrics,
  }

  return (
    <DashboardContext.Provider value={value}>
      {children}
    </DashboardContext.Provider>
  )
}

// Default values for when context is not available (SSR/build time)
const defaultContextValue: DashboardContextType = {
  campaigns: initialCampaigns,
  selectedCampaignId: null,
  assets: initialAssets,
  selectedAssetId: null,
  totalBudget: 365000,
  dailyBudget: 35000,
  totalSpent: 219100,
  channelBudgets: initialChannelBudgets,
  audienceSegments: [],
  notifications: [],
  isLoading: false,
  metrics: {
    totalImpressions: 2847593,
    activeROAS: 4.2,
    conversionRate: 3.8,
    totalClicks: 127432,
  },
  addCampaign: () => {},
  updateCampaign: () => {},
  deleteCampaign: () => {},
  toggleCampaignStatus: () => {},
  selectCampaign: () => {},
  addAsset: () => {},
  deleteAsset: () => {},
  selectAsset: () => {},
  updateBudget: () => {},
  updateChannelBudget: () => {},
  addSpend: () => {},
  addNotification: () => {},
  dismissNotification: () => {},
  refreshMetrics: () => {},
}

export function useDashboard() {
  const context = useContext(DashboardContext)
  // Return default values during SSR/build time instead of throwing
  if (context === undefined) {
    return defaultContextValue
  }
  return context
}
