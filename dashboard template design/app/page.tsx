"use client"

import { DashboardLayout } from "@/components/dashboard/dashboard-layout"
import { GlanceCard } from "@/components/dashboard/glance-card"
import { CampaignFeed, SpendCounter } from "@/components/dashboard/campaign-feed"
import { TrendingUp, DollarSign, Users, RefreshCw } from "lucide-react"
import { useDashboard } from "@/context/dashboard-context"
import { Button } from "@/components/ui/button"

function PulseDashboardInner() {
  const { campaigns, metrics, dailyBudget, totalSpent, refreshMetrics } = useDashboard()

  // Build glance cards from live metrics
  const glanceCards = [
    {
      title: "Total Impressions",
      value: metrics.totalImpressions,
      suffix: "",
      change: 12.5,
      sparklineData: [30, 45, 38, 52, 48, 60, 55, 72, 68, 85, 78, 92],
    },
    {
      title: "Active ROAS",
      value: metrics.activeROAS,
      prefix: "",
      suffix: "x",
      change: 8.3,
      sparklineData: [2.1, 2.8, 3.2, 2.9, 3.5, 3.8, 4.0, 3.7, 4.1, 4.0, 4.2, 4.2],
    },
    {
      title: "Conversion Rate",
      value: metrics.conversionRate,
      suffix: "%",
      change: -2.1,
      sparklineData: [4.2, 4.0, 3.8, 4.1, 3.9, 3.6, 3.5, 3.7, 3.6, 3.8, 3.7, 3.8],
    },
    {
      title: "Total Clicks",
      value: metrics.totalClicks,
      change: 15.7,
      sparklineData: [45, 52, 48, 65, 72, 68, 85, 92, 88, 105, 112, 127],
    },
  ]

  // Calculate live stats from campaigns
  const bestPerformer = campaigns.length > 0 
    ? campaigns.reduce((best, c) => (c.impressions > (best?.impressions || 0)) ? c : best, campaigns[0])
    : null
  const totalLeads = Math.floor(metrics.totalClicks * 0.0098)

  return (
    <div className="p-4 md:p-6 lg:p-8">
      {/* Header */}
      <div className="mb-6 md:mb-8">
        <div className="flex items-center justify-between">
          <div>
            <div className="flex items-center gap-3 mb-2">
              <div className="w-2 h-2 bg-lime pulse-live" />
              <span className="text-xs font-mono text-muted-foreground uppercase tracking-wider">
                Real-time Overview
              </span>
            </div>
            <h1 className="text-2xl md:text-3xl font-semibold tracking-tight">Pulse Dashboard</h1>
            <p className="text-sm md:text-base text-muted-foreground mt-1">
              Monitor your marketing performance at a glance
            </p>
          </div>
          <Button 
            variant="outline" 
            size="sm" 
            onClick={refreshMetrics}
            className="gap-2 bg-transparent"
          >
            <RefreshCw className="w-4 h-4" />
            <span className="hidden sm:inline">Refresh</span>
          </Button>
        </div>
      </div>

      {/* Glance Cards */}
      <div className="grid grid-cols-2 lg:grid-cols-4 gap-3 md:gap-4 mb-6 md:mb-8">
        {glanceCards.map((card) => (
          <GlanceCard
            key={card.title}
            title={card.title}
            value={card.value}
            prefix={card.prefix}
            suffix={card.suffix}
            change={card.change}
            sparklineData={card.sparklineData}
          />
        ))}
      </div>

      {/* Main Content Grid */}
      <div className="grid grid-cols-1 lg:grid-cols-3 gap-4 md:gap-6 items-stretch">
        {/* Campaign Feed - Takes 2 columns */}
        <div className="lg:col-span-2 order-2 lg:order-1">
          <CampaignFeed campaigns={campaigns} />
        </div>

        {/* Side Panel */}
        <div className="flex flex-col gap-4 md:gap-6 order-1 lg:order-2">
          <SpendCounter totalSpend={totalSpent} dailyBudget={dailyBudget} />
          
          {/* Quick Stats */}
          <div className="bg-card border border-border p-6 flex-1">
            <h2 className="text-sm font-medium mb-4">Today&apos;s Highlights</h2>
            <div className="space-y-4">
              <div className="flex items-center gap-3">
                <div className="w-8 h-8 bg-lime/10 flex items-center justify-center shrink-0">
                  <TrendingUp className="w-4 h-4 text-lime" />
                </div>
                <span className="text-sm flex-1">Best Performer</span>
                <span className="text-sm font-mono text-right truncate max-w-24">
                  {bestPerformer?.name.split(" - ")[0] || "N/A"}
                </span>
              </div>
              <div className="flex items-center gap-3">
                <div className="w-8 h-8 bg-lime/10 flex items-center justify-center shrink-0">
                  <DollarSign className="w-4 h-4 text-lime" />
                </div>
                <span className="text-sm flex-1">Lowest CPA</span>
                <span className="text-sm font-mono text-right">
                  ${metrics.totalClicks > 0 ? (totalSpent / metrics.totalClicks * 10).toFixed(2) : "0.00"}
                </span>
              </div>
              <div className="flex items-center gap-3">
                <div className="w-8 h-8 bg-lime/10 flex items-center justify-center shrink-0">
                  <Users className="w-4 h-4 text-lime" />
                </div>
                <span className="text-sm flex-1">New Leads</span>
                <span className="text-sm font-mono text-right">{totalLeads.toLocaleString()}</span>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  )
}

export default function PulseDashboard() {
  return (
    <DashboardLayout>
      <PulseDashboardInner />
    </DashboardLayout>
  )
}
