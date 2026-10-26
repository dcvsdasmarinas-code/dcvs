<?php
$publicAnnouncementCategories = [];
foreach ($announcements as $announcement) {
    $categoryKey = (string)($announcement['category'] ?? '');
    $filterKey = $categoryKey === 'advisory'
        ? 'advisory'
        : (string)($announcement['subject'] ?? 'general_notices');
    $publicAnnouncementCategories[$filterKey] = $categoryKey === 'advisory'
        ? 'Advisory'
        : ($announcement['_public_category_label'] ?? 'General Notices');
}
ksort($publicAnnouncementCategories);

function publicAnnouncementEscape($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function publicAnnouncementDate($value, string $format): string
{
    if (!$value) {
        return '';
    }
    try {
        return (new DateTimeImmutable((string)$value))->format($format);
    } catch (Exception $exception) {
        return '';
    }
}

function publicAnnouncementIsPast(array $announcement): bool
{
    $isEvent = (int)($announcement['is_event'] ?? 0) === 1;
    if ($isEvent) {
        $date = $announcement['event_end_date'] ?? '';
        if ($date === '') {
            $date = $announcement['event_date'] ?? '';
        }
        return $date !== '' && substr((string)$date, 0, 10) < date('Y-m-d');
    }

    $date = $announcement['publish_at'] ?? '';
    if ($date === '') {
        $date = $announcement['created_at'] ?? '';
    }
    return $date !== ''
        && substr((string)$date, 0, 10) < date('Y-m-d', strtotime('-30 days'));
}

function publicAnnouncementCard(array $announcement, bool $past = false): void
{
    $isAdvisory = (string)($announcement['category'] ?? '') === 'advisory';
    $isEvent = (int)($announcement['is_event'] ?? 0) === 1;
    $categoryKey = $isAdvisory ? 'advisory' : (string)($announcement['subject'] ?? 'general_notices');
    $categoryLabel = $isAdvisory ? 'Advisory' : ($announcement['_public_category_label'] ?? 'General Notices');
    $targets = $announcement['_public_targets'] ?? [];
    $allBarangays = !empty($announcement['_public_all_barangays']);
    $targetIds = array_map(static function ($target) {
        return (string)(int)$target['id'];
    }, $targets);
    $targetFilter = $allBarangays ? '*' : implode(',', $targetIds);
    $imageUrl = (string)($announcement['photo_url'] ?? '');
    $description = trim(strip_tags((string)($announcement['content'] ?? '')));
    $fullDescription = preg_replace('/<br\s*\/?>/i', "\n", (string)($announcement['content'] ?? ''));
    $fullDescription = preg_replace('/<\/(?:p|div|li|h[1-6])\s*>/i', "\n\n", $fullDescription);
    $fullDescription = trim(html_entity_decode(strip_tags($fullDescription), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    $dateValue = $isEvent
        ? ($announcement['event_date'] ?? '')
        : ($announcement['publish_at'] ?? $announcement['created_at'] ?? '');
    $dateEndValue = $isEvent ? ($announcement['event_end_date'] ?? '') : '';
    $dateLabel = $isEvent ? 'Event date: ' : 'Posted: ';
    $dateText = publicAnnouncementDate($dateValue, 'M j, Y');
    if ($dateEndValue && $dateEndValue !== $dateValue) {
        $dateText .= ' – ' . publicAnnouncementDate($dateEndValue, 'M j, Y');
    }
    static $cardIndex = 0;
    $cardIndex++;
    $popoverId = 'public-ann-popover-' . (int)($announcement['announcement_id'] ?? $cardIndex);
    ?>
    <div class="public-ann-item"
        data-announcement-card
        data-kind="<?php echo $isAdvisory ? 'advisory' : 'announcement'; ?>"
        data-category="<?php echo publicAnnouncementEscape($categoryKey); ?>"
        data-barangays="<?php echo publicAnnouncementEscape($targetFilter); ?>"
        data-past="<?php echo $past ? '1' : '0'; ?>"
        tabindex="0"
        aria-describedby="<?php echo publicAnnouncementEscape($popoverId); ?>">
        <article class="public-ann-card<?php echo $isAdvisory ? ' is-advisory' : ''; ?>">
        <?php if ($isAdvisory): ?>
            <div class="public-ann-advisory-top">
                <div class="public-ann-advisory-label">
                    <svg aria-hidden="true" viewBox="0 0 24 24" fill="none">
                        <path d="M12 3 22 20H2L12 3Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/>
                        <path d="M12 9v5m0 3h.01" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                    </svg>
                    <span>Advisory</span>
                </div>
                <time datetime="<?php echo publicAnnouncementEscape($announcement['created_at'] ?? ''); ?>">
                    Posted <?php echo publicAnnouncementEscape(publicAnnouncementDate($announcement['created_at'] ?? '', 'M j, Y')); ?>
                </time>
            </div>
            <h2><?php echo publicAnnouncementEscape($announcement['title'] ?? ''); ?></h2>
            <p class="public-ann-summary"><?php echo publicAnnouncementEscape($description); ?></p>
            <?php if (!empty($announcement['location'])): ?>
                <div class="public-ann-applies">At <?php echo publicAnnouncementEscape($announcement['location']); ?></div>
            <?php endif; ?>
        <?php else: ?>
            <div class="public-ann-image">
                <?php if ($imageUrl !== ''): ?>
                    <img src="<?php echo publicAnnouncementEscape($imageUrl); ?>" alt="<?php echo publicAnnouncementEscape($announcement['title'] ?? 'Announcement poster'); ?>" loading="lazy">
                <?php else: ?>
                    <span>Poster photo</span>
                <?php endif; ?>
            </div>
            <div class="public-ann-body">
                <div class="public-ann-meta">
                    <span class="public-ann-category"><?php echo publicAnnouncementEscape($categoryLabel); ?></span>
                    <span class="public-ann-status"><?php echo $past ? 'Past' : 'Announcement'; ?></span>
                </div>
                <h2>
                    <?php if (!empty($announcement['link_url'])): ?>
                        <a href="<?php echo publicAnnouncementEscape($announcement['link_url']); ?>"<?php if (strpos((string)$announcement['link_url'], 'http') === 0) echo ' target="_blank" rel="noopener noreferrer"'; ?>>
                            <?php echo publicAnnouncementEscape($announcement['title'] ?? ''); ?>
                        </a>
                    <?php else: ?>
                        <?php echo publicAnnouncementEscape($announcement['title'] ?? ''); ?>
                    <?php endif; ?>
                </h2>
                <div class="public-ann-datetime">
                    <time datetime="<?php echo publicAnnouncementEscape($dateValue); ?>">
                        <strong><?php echo publicAnnouncementEscape($dateLabel . $dateText); ?></strong>
                    </time>
                </div>
                <p class="public-ann-summary"><?php echo publicAnnouncementEscape($description); ?></p>
                <div class="public-ann-targets" aria-label="Applicable barangays">
                    <?php if (!empty($announcement['location'])): ?>
                        <span class="public-ann-location-prefix">At</span>
                        <span><?php echo publicAnnouncementEscape($announcement['location']); ?></span>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
        </article>
        <div class="public-ann-popover" id="<?php echo publicAnnouncementEscape($popoverId); ?>" role="region" aria-label="Full announcement details" data-lenis-prevent>
            <strong class="public-ann-popover-title"><?php echo publicAnnouncementEscape($announcement['title'] ?? ''); ?></strong>
            <?php if ($dateText !== ''): ?>
                <span class="public-ann-popover-date"><?php echo publicAnnouncementEscape($dateLabel . $dateText); ?></span>
            <?php endif; ?>
            <?php if ($fullDescription !== ''): ?>
                <p><?php echo publicAnnouncementEscape($fullDescription); ?></p>
            <?php endif; ?>
            <?php if (!empty($announcement['location'])): ?>
                <span class="public-ann-popover-location">At <?php echo publicAnnouncementEscape($announcement['location']); ?></span>
            <?php endif; ?>
            <?php if (!empty($announcement['link_url'])): ?>
                <a href="<?php echo publicAnnouncementEscape($announcement['link_url']); ?>"<?php if (strpos((string)$announcement['link_url'], 'http') === 0) echo ' target="_blank" rel="noopener noreferrer"'; ?>>More information</a>
            <?php endif; ?>
        </div>
    </div>
    <?php
}

$hasUpcomingCards = false;
foreach ($announcements as $announcement) {
    if (!publicAnnouncementIsPast($announcement)) $hasUpcomingCards = true;
}
?>
<style>
    body { background:#f7f8f6!important; color:#17211b; }
    .transition_screen, .cursor_wrap, .page_wrap { display:none!important; }
    #public-announcements-app, #public-announcements-app * { box-sizing:border-box; }
    #public-announcements-app { min-height:100vh; padding:148px 24px 64px; font-family:Inter,"Segoe UI",sans-serif; }
    #public-announcements-app button, #public-announcements-app select { font-family:Inter,"Segoe UI",sans-serif; }
    .public-ann-inner { width:min(1088px,100%); margin:0 auto; }
    .public-ann-header { margin:0 0 22px; }
    .public-ann-header h1 { margin:0; color:#17211b; font-size:36px; font-weight:600; letter-spacing:-.9px; line-height:1.2; }
    .public-ann-header p { margin:6px 0 0; color:#626d66; font-size:15px; line-height:1.5; }
    .public-ann-controls { display:flex; align-items:center; justify-content:space-between; gap:18px; border-bottom:1px solid #dfe4df; margin-bottom:28px; }
    .public-ann-tabs { display:flex; align-items:center; gap:24px; }
    .public-ann-tab { position:relative; padding:12px 4px 13px; border:0; background:transparent; color:#59645e; font:500 14px/1.3 Inter,"Segoe UI",sans-serif; cursor:pointer; }
    .public-ann-tab[aria-selected="true"] { color:#08763b; font-weight:600; }
    .public-ann-tab[aria-selected="true"]::after { position:absolute; right:0; bottom:-1px; left:0; height:2px; background:#07833d; content:""; }
    .public-ann-filters { display:flex; gap:10px; padding-bottom:8px; }
    .public-ann-filters select { min-width:122px; height:36px; padding:0 32px 0 12px; border:1px solid #e4e4e7; border-radius:6px; background-color:#fff; color:#18181b; font:400 14px Inter,"Segoe UI",sans-serif; box-shadow:0 1px 2px rgba(0,0,0,.05); cursor:pointer; }
    .public-ann-filters select:focus-visible { outline:none; border-color:#18181b; box-shadow:0 0 0 1px #18181b; }
    .public-ann-tab:focus-visible, .public-ann-past-toggle:focus-visible { outline:2px solid #18181b; outline-offset:3px; border-radius:4px; }
    .public-ann-section-title { margin:0 0 14px; color:#17211b; font-size:17px; font-weight:650; line-height:1.4; }
    .public-ann-grid { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); align-items:start; gap:20px; }
    .public-ann-item { position:relative; z-index:0; min-width:0; }
    .public-ann-item:hover, .public-ann-item:focus, .public-ann-item:focus-within { z-index:20; outline:none; }
    .public-ann-item:focus-visible { outline:2px solid #08763b; outline-offset:3px; }
    .public-ann-item[hidden] { display:none!important; }
    .public-ann-card { min-width:0; overflow:hidden; border:1px solid #dfe4df; border-radius:0; background:#fff; font-family:'Inter','Segoe UI',sans-serif; }
    .public-ann-image { display:flex; aspect-ratio:4/3; align-items:center; justify-content:center; overflow:hidden; background:#e2e8e3; color:#69766e; font-size:13px; }
    .public-ann-image img { width:100%; height:100%; object-fit:cover; }
    .public-ann-body { padding:16px 18px 18px; font-family:'Inter','Segoe UI',sans-serif; }
    .public-ann-meta { display:flex; align-items:center; justify-content:space-between; gap:10px; margin-bottom:9px; }
    .public-ann-category { display:inline-flex; align-items:center; min-height:22px; color:#045e1f; font-family:Inter,'Segoe UI',sans-serif; font-size:12px; font-weight:500; line-height:1.4; }
    .public-ann-status, .public-ann-targets span:not(.public-ann-location-prefix) { display:inline-flex; align-items:center; min-height:22px; padding:3px 10px; border:1px solid #0e8131; border-radius:4px; background:#0e8131; color:#fff; font-family:Inter,'Segoe UI',sans-serif; font-size:12px; font-weight:500; line-height:1.4; }
    .public-ann-status { white-space:nowrap; }
    .public-ann-card h2 { margin:0 0 7px; color:#18181b; font-family:'Inter','Segoe UI',sans-serif; font-size:18px; font-weight:600; letter-spacing:-.025em; line-height:1.3; }
    .public-ann-card h2 a { color:inherit; text-decoration:none; }
    .public-ann-card h2 a:hover { color:#08763b; text-decoration:underline; }
    .public-ann-datetime { display:flex; flex-wrap:wrap; gap:5px; margin-bottom:9px; color:#18181b; font-family:'Inter','Segoe UI',sans-serif; font-size:14px; line-height:1.5; }
    .public-ann-datetime strong { color:#18181b; font-weight:600; }
    .public-ann-summary { display:-webkit-box; overflow:hidden; margin:0; color:#18181b; font-family:'Inter','Segoe UI',sans-serif; font-size:14px; font-weight:400; line-height:1.45; -webkit-box-orient:vertical; -webkit-line-clamp:2; }
    .public-ann-targets { display:flex; flex-wrap:wrap; gap:5px; margin-top:8px; }
    .public-ann-targets .public-ann-location-prefix { display:inline-flex; align-items:center; min-height:0; padding:0; border:0; border-radius:0; background:transparent; color:#71717a; font-family:'Inter','Segoe UI',sans-serif; font-size:13px; font-weight:500; }
    .public-ann-popover { position:fixed; z-index:2147483001; display:none; box-sizing:border-box; width:min(420px,calc(100vw - 24px)); max-height:min(60vh,440px); overflow:auto; overscroll-behavior:contain; -webkit-overflow-scrolling:touch; touch-action:pan-y; padding:16px 18px; border:1px solid #dfe4df; border-radius:8px; background:#fff; box-shadow:0 12px 32px rgba(18,35,24,.2); color:#18181b; font-family:Inter,'Segoe UI',sans-serif; font-size:14px; line-height:1.55; }
    .public-ann-popover.is-open { display:block; }
    .public-ann-popover-title { display:block; margin-bottom:5px; font-size:16px; line-height:1.35; }
    .public-ann-popover-date, .public-ann-popover-location { display:block; color:#59645e; font-size:13px; font-weight:600; }
    .public-ann-popover p { margin:10px 0; white-space:pre-wrap; overflow-wrap:anywhere; }
    .public-ann-popover a { display:inline-block; margin-top:8px; color:#08763b; font-weight:600; text-decoration:underline; }
    .public-ann-card.is-advisory { display:flex; flex-direction:column; min-height:100%; padding:18px; border-color:#f1d8a4; background:#fff7e7; }
    .public-ann-advisory-top { display:flex; align-items:center; justify-content:space-between; gap:10px; margin-bottom:13px; color:#85530b; }
    .public-ann-advisory-label { display:flex; align-items:center; gap:7px; font-size:11px; font-weight:700; letter-spacing:.04em; text-transform:uppercase; }
    .public-ann-advisory-label svg { width:17px; height:17px; color:#9a620d; }
    .public-ann-advisory-top time { color:#8b641e; font-size:11px; white-space:nowrap; }
    .public-ann-card.is-advisory h2 { margin-bottom:10px; font-size:16px; }
    .public-ann-card.is-advisory .public-ann-summary { -webkit-line-clamp:unset; }
    .public-ann-applies { margin-top:auto; padding-top:22px; color:#8b641e; font-size:12px; }
    .public-ann-empty { grid-column:1/-1; padding:34px 16px; border:1px dashed #cfd8d0; border-radius:10px; color:#657068; text-align:center; font-size:14px; }
    .public-ann-footer { margin-top:30px; padding-top:18px; border-top:1px solid #dfe4df; }
    .public-ann-past-toggle { padding:0; border:0; background:none; color:#08763b; font:600 14px Inter,"Segoe UI",sans-serif; cursor:pointer; }
    .public-ann-past { margin-top:28px; }
    .public-ann-past[hidden] { display:none!important; }
    .public-ann-past .public-ann-grid { margin-bottom:10px; }
    @media(max-width:900px) { .public-ann-grid { grid-template-columns:repeat(2,minmax(0,1fr)); } }
    @media(max-width:620px) {
        #public-announcements-app { padding:125px 18px 42px; }
        .public-ann-header { margin-bottom:17px; }
        .public-ann-header h1 { font-size:30px; }
        .public-ann-controls { align-items:stretch; flex-direction:column; gap:4px; }
        .public-ann-tabs { gap:18px; }
        .public-ann-filters { padding-bottom:10px; }
        .public-ann-filters select { flex:1; min-width:0; }
        .public-ann-grid { grid-template-columns:minmax(0,1fr); gap:14px; }
        .public-ann-card.is-advisory { min-height:230px; }
    }
</style>
<main id="public-announcements-app">
    <div class="public-ann-inner">
        <header class="public-ann-header">
            <h1>Announcements and advisories</h1>
            <p>Updates, events, and notices from DCVS for residents of Dasmariñas.</p>
        </header>
        <div class="public-ann-controls">
            <div class="public-ann-tabs" role="tablist" aria-label="Update type">
                <button class="public-ann-tab" type="button" role="tab" aria-selected="true" data-ann-tab="all">All</button>
                <button class="public-ann-tab" type="button" role="tab" aria-selected="false" data-ann-tab="announcement">Announcements</button>
                <button class="public-ann-tab" type="button" role="tab" aria-selected="false" data-ann-tab="advisory">Advisories</button>
            </div>
            <div class="public-ann-filters">
                <select id="public-ann-barangay" aria-label="Filter by barangay">
                    <option value="all">All barangays</option>
                    <?php foreach ($publicBarangayNames as $barangayId => $barangayName): ?>
                        <option value="<?php echo (int)$barangayId; ?>"><?php echo publicAnnouncementEscape($barangayName); ?></option>
                    <?php endforeach; ?>
                </select>
                <select id="public-ann-category" aria-label="Filter by category">
                    <option value="all">All categories</option>
                    <?php foreach ($publicAnnouncementCategories as $categoryKey => $categoryName): ?>
                        <option value="<?php echo publicAnnouncementEscape($categoryKey); ?>"><?php echo publicAnnouncementEscape($categoryName); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <section aria-labelledby="public-ann-upcoming-title">
            <h2 class="public-ann-section-title" id="public-ann-upcoming-title">Latest</h2>
            <div class="public-ann-grid" data-ann-grid="upcoming">
                <?php foreach ($announcements as $announcement):
                    if (publicAnnouncementIsPast($announcement)) continue;
                    publicAnnouncementCard($announcement);
                endforeach; ?>
                <div class="public-ann-empty" data-ann-empty<?php echo $hasUpcomingCards ? ' hidden' : ''; ?>>No recent announcements or advisories right now.</div>
            </div>
        </section>
        <div class="public-ann-footer">
            <button class="public-ann-past-toggle" type="button" aria-expanded="false" aria-controls="public-ann-past">
                View earlier announcements <span aria-hidden="true">→</span>
            </button>
        </div>
        <section class="public-ann-past" id="public-ann-past" hidden aria-labelledby="public-ann-past-title">
            <h2 class="public-ann-section-title" id="public-ann-past-title">Earlier</h2>
            <div class="public-ann-grid" data-ann-grid="past">
                <?php foreach ($announcements as $announcement):
                    if (!publicAnnouncementIsPast($announcement)) continue;
                    publicAnnouncementCard($announcement, true);
                endforeach; ?>
                <div class="public-ann-empty" data-ann-empty hidden>No earlier announcements match these filters.</div>
            </div>
        </section>
    </div>
</main>
<script>
(function () {
    var tabs = Array.prototype.slice.call(document.querySelectorAll('[data-ann-tab]'));
    var barangayFilter = document.getElementById('public-ann-barangay');
    var categoryFilter = document.getElementById('public-ann-category');
    var pastToggle = document.querySelector('.public-ann-past-toggle');
    var pastSection = document.getElementById('public-ann-past');
    var activeItem = null;
    var closeTimer = null;

    function stopCloseTimer() {
        if (closeTimer) {
            window.clearTimeout(closeTimer);
            closeTimer = null;
        }
    }

    function positionPopover(item, popover) {
        var card = item.querySelector('.public-ann-card');
        if (!card) return;
        var rect = card.getBoundingClientRect();
        var gap = 12;
        var edge = 12;
        var viewportWidth = document.documentElement.clientWidth;
        var viewportHeight = window.innerHeight;
        var maxHeight = Math.min(440, Math.floor(viewportHeight * 0.6));
        var width = Math.min(420, viewportWidth - edge * 2);

        popover.style.width = width + 'px';
        popover.style.maxHeight = maxHeight + 'px';
        popover.style.left = edge + 'px';
        popover.style.top = edge + 'px';
        var popoverHeight = popover.getBoundingClientRect().height;
        var rightSpace = viewportWidth - rect.right - gap - edge;
        var leftSpace = rect.left - gap - edge;

        if (rightSpace >= Math.min(260, width)) {
            popover.style.width = Math.min(width, rightSpace) + 'px';
            popover.style.left = (rect.right + gap) + 'px';
            popover.style.top = Math.max(edge, Math.min(rect.top, viewportHeight - popoverHeight - edge)) + 'px';
            return;
        }
        if (leftSpace >= Math.min(260, width)) {
            popover.style.width = Math.min(width, leftSpace) + 'px';
            popover.style.left = Math.max(edge, rect.left - gap - Math.min(width, leftSpace)) + 'px';
            popover.style.top = Math.max(edge, Math.min(rect.top, viewportHeight - popoverHeight - edge)) + 'px';
            return;
        }

        var topSpace = rect.top - gap - edge;
        var bottomSpace = viewportHeight - rect.bottom - gap - edge;
        var useAbove = topSpace >= Math.min(popoverHeight, 200) || topSpace >= bottomSpace;
        var verticalSpace = Math.max(120, Math.min(maxHeight, useAbove ? topSpace : bottomSpace));
        popover.style.maxHeight = verticalSpace + 'px';
        popoverHeight = popover.getBoundingClientRect().height;
        popover.style.left = Math.max(edge, Math.min(
            rect.left + (rect.width - width) / 2,
            viewportWidth - width - edge
        )) + 'px';
        popover.style.top = useAbove
            ? Math.max(edge, rect.top - gap - popoverHeight) + 'px'
            : Math.min(viewportHeight - popoverHeight - edge, rect.bottom + gap) + 'px';
    }

    function closePopover() {
        stopCloseTimer();
        if (!activeItem) return;
        var item = activeItem;
        var popover = document.getElementById(item.getAttribute('aria-describedby'));
        item.classList.remove('has-open-popover');
        if (popover) {
            popover.classList.remove('is-open');
            item.appendChild(popover);
            popover.style.removeProperty('left');
            popover.style.removeProperty('top');
            popover.style.removeProperty('width');
            popover.style.removeProperty('max-height');
        }
        activeItem = null;
    }

    function schedulePopoverClose() {
        stopCloseTimer();
        closeTimer = window.setTimeout(function () {
            var focused = document.activeElement;
            var popover = activeItem
                ? document.getElementById(activeItem.getAttribute('aria-describedby'))
                : null;
            if (activeItem && !activeItem.matches(':hover')
                && !(popover && popover.matches(':hover'))
                && !(focused && (activeItem.contains(focused) || (popover && popover.contains(focused))))) {
                closePopover();
            }
        }, 180);
    }

    function openPopover(item) {
        stopCloseTimer();
        if (activeItem && activeItem !== item) closePopover();
        var popover = document.getElementById(item.getAttribute('aria-describedby'));
        if (!popover) return;
        activeItem = item;
        item.classList.add('has-open-popover');
        if (popover.parentNode !== document.body) document.body.appendChild(popover);
        popover.classList.add('is-open');
        positionPopover(item, popover);
    }

    function isTouchInteraction(event) {
        return event.pointerType === 'touch'
            || (window.matchMedia && window.matchMedia('(hover: none) and (pointer: coarse)').matches);
    }

    document.querySelectorAll('[data-announcement-card]').forEach(function (item) {
        var popover = document.getElementById(item.getAttribute('aria-describedby'));
        item.addEventListener('pointerenter', function (event) {
            if (event.pointerType !== 'touch') openPopover(item);
        });
        item.addEventListener('pointerleave', schedulePopoverClose);
        item.addEventListener('focusin', function () { openPopover(item); });
        item.addEventListener('focusout', schedulePopoverClose);
        item.addEventListener('click', function (event) {
            if (!isTouchInteraction(event)) return;
            if (event.target.closest('a')) event.preventDefault();
            openPopover(item);
        });
        if (popover) {
            popover.addEventListener('pointerenter', stopCloseTimer);
            popover.addEventListener('pointerleave', schedulePopoverClose);
            popover.addEventListener('focusin', stopCloseTimer);
            popover.addEventListener('focusout', schedulePopoverClose);
        }
    });

    document.addEventListener('pointerdown', function (event) {
        if (!activeItem) return;
        var popover = document.getElementById(activeItem.getAttribute('aria-describedby'));
        if (!activeItem.contains(event.target) && !(popover && popover.contains(event.target))) {
            closePopover();
        }
    });
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') closePopover();
    });
    window.addEventListener('resize', function () {
        if (activeItem) {
            var popover = document.getElementById(activeItem.getAttribute('aria-describedby'));
            if (popover) positionPopover(activeItem, popover);
        }
    });
    window.addEventListener('scroll', function () {
        if (activeItem) {
            var popover = document.getElementById(activeItem.getAttribute('aria-describedby'));
            if (popover) positionPopover(activeItem, popover);
        }
    });

    function applyFilters() {
        var selectedTab = tabs.find(function (tab) { return tab.getAttribute('aria-selected') === 'true'; });
        var kind = selectedTab ? selectedTab.getAttribute('data-ann-tab') : 'all';
        var barangay = barangayFilter.value;
        var category = categoryFilter.value;
        ['upcoming', 'past'].forEach(function (gridName) {
            var grid = document.querySelector('[data-ann-grid="' + gridName + '"]');
            var visibleCount = 0;
            grid.querySelectorAll('[data-announcement-card]').forEach(function (card) {
                var kindMatches = kind === 'all' || card.getAttribute('data-kind') === kind;
                var categoryMatches = category === 'all' || card.getAttribute('data-category') === category;
                var targets = card.getAttribute('data-barangays') || '';
                var barangayMatches = barangay === 'all' || targets === '*' || targets.split(',').indexOf(barangay) !== -1;
                card.hidden = !(kindMatches && categoryMatches && barangayMatches);
                if (card.hidden && activeItem === card) closePopover();
                if (!card.hidden) visibleCount += 1;
            });
            var empty = grid.querySelector('[data-ann-empty]');
            if (empty) empty.hidden = visibleCount !== 0;
        });
    }

    tabs.forEach(function (tab) {
        tab.addEventListener('click', function () {
            tabs.forEach(function (item) { item.setAttribute('aria-selected', String(item === tab)); });
            applyFilters();
        });
    });
    barangayFilter.addEventListener('change', applyFilters);
    categoryFilter.addEventListener('change', applyFilters);
    pastToggle.addEventListener('click', function () {
        var expanded = pastToggle.getAttribute('aria-expanded') === 'true';
        pastToggle.setAttribute('aria-expanded', String(!expanded));
        pastSection.hidden = expanded;
        pastToggle.firstChild.textContent = expanded ? 'View earlier announcements ' : 'Hide earlier announcements ';
        if (!expanded) pastSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
    applyFilters();
})();
</script>
