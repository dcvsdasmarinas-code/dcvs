<!-- Shared View Details drawer for the staff and barangay geo maps. -->
<div class="geo-drawer-overlay" id="geoDrawerOverlay" onclick="closeGeoDrawer()"></div>
<div id="geoDrawer" class="geo-drawer" role="dialog" aria-modal="true" aria-labelledby="geoDrawerName">
  <div class="geo-drawer-hd">
    <div class="geo-drawer-hd-text">
      <h2 class="geo-drawer-hd-name" id="geoDrawerName">Barangay</h2>
      <p class="geo-drawer-hd-sub">Barangay Monitoring</p>
    </div>
    <button type="button" class="geo-drawer-close" onclick="closeGeoDrawer()" aria-label="Close">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
    </button>
  </div>
  <div class="geo-drawer-body">
    <div class="geo-risk" id="geoDrawerRiskSection">
      <span class="geo-risk-dot" id="geoDrawerRiskDot"></span>
      <div>
        <div class="geo-risk-text" id="geoDrawerRiskText">MEDIUM RISK</div>
        <div class="geo-risk-desc" id="geoDrawerRiskDesc">Active cases detected in this barangay</div>
      </div>
    </div>
    <div class="geo-drawer-section">
      <div class="geo-drawer-label">Case Overview</div>
      <div class="geo-metrics">
        <div class="geo-metric"><div class="geo-metric-num" id="geoDrawerConfirmed">0</div><div class="geo-metric-lbl">Confirmed Cases</div></div>
        <div class="geo-metric"><div class="geo-metric-num" id="geoDrawerPending">0</div><div class="geo-metric-lbl">Pending Reports</div></div>
        <div class="geo-metric"><div class="geo-metric-num" id="geoDrawerResolved">0</div><div class="geo-metric-lbl">Resolved Cases</div></div>
        <div class="geo-metric"><div class="geo-metric-num" id="geoDrawerTotal">0</div><div class="geo-metric-lbl">Total Reports</div></div>
      </div>
    </div>
    <div class="geo-drawer-section">
      <div class="geo-drawer-label">Report Activity</div>
      <div id="geoDrawerBreakdown"></div>
    </div>
    <div class="geo-drawer-section" id="geoDrawerRecentWrap" style="display:none;">
      <div class="geo-drawer-label">Recent Report</div>
      <div class="geo-recent-card" id="geoDrawerRecent"></div>
    </div>
    <div class="geo-drawer-section" id="geoDrawerNoRecent" style="display:none;">
      <div class="geo-drawer-label">Recent Report</div>
      <div style="font-size:13px;color:#a1a1aa;">No recent reports</div>
    </div>
    <a class="geo-drawer-action" id="geoDrawerViewAll" href="#">
      <span>View All Reports</span>
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>
    </a>
    <div class="geo-drawer-meta">Last Updated<br><span id="geoDrawerUpdated" style="color:#71717a;">--</span></div>
  </div>
</div>
