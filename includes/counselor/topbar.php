<?php
/** Shared Counselor topbar. Include after counselor session validation. */
$counselorDisplayName = $_SESSION['fullname'] ?? $_SESSION['alias'] ?? 'Counselor';
$counselorDisplayEmail = $_SESSION['email'] ?? '';
$counselorInitial = function_exists('mb_substr')
    ? mb_strtoupper(mb_substr(trim((string)$counselorDisplayName), 0, 1, 'UTF-8'), 'UTF-8')
    : strtoupper(substr(trim((string)$counselorDisplayName), 0, 1));
if ($counselorInitial === '') { $counselorInitial = 'C'; }
if (empty($_SESSION['counselor_notification_csrf'])) { $_SESSION['counselor_notification_csrf'] = bin2hex(random_bytes(24)); }
?>
<header class="counselor-topbar">
    <div class="counselor-topbar-left">
        <button class="counselor-mobile-menu-toggle" id="counselorMenuToggle" type="button" aria-label="Open navigation menu" aria-controls="counselorSidebar" aria-expanded="false">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
        </button>
        <div class="counselor-topbar-brand-icon" aria-hidden="true">
            <svg viewBox="0 0 32 32"><path d="M16 27V15"/><path d="M16 17C8 17 5 12 5 5c7 0 12 4 11 12Z"/><path d="M16 21c0-7 4-11 11-11 0 7-4 11-11 11Z"/></svg>
        </div>
        <div class="counselor-topbar-heading">
            <strong class="counselor-desktop-portal-title">Counselor Portal</strong>
            <strong class="counselor-mobile-brand-title">PTC Wellness</strong>
            <span class="counselor-desktop-portal-subtitle">PTC Wellness – Guidance, Counseling and Values Enrichment Office</span>
            <span class="counselor-mobile-portal-subtitle">Counselor Portal</span>
        </div>
    </div>

    <div class="counselor-topbar-actions">
        <div class="counselor-notification-wrap" data-notification-component data-csrf="<?= htmlspecialchars($_SESSION['counselor_notification_csrf'], ENT_QUOTES, 'UTF-8') ?>">
            <button class="counselor-notification-button" id="counselorNotificationToggle" type="button" aria-label="Open notifications" aria-haspopup="true" aria-controls="counselorNotificationPanel" aria-expanded="false">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/></svg>
                <span class="counselor-notification-indicator" id="counselorNotificationIndicator" aria-hidden="true" hidden></span>
                <span class="counselor-notification-count" id="counselorNotificationCount" hidden></span>
            </button>
            <section class="counselor-notification-panel" id="counselorNotificationPanel" aria-label="Notifications" hidden>
                <div class="notification-panel-header"><div><strong>Notifications</strong><small>New Needs Assessment submissions</small></div><button type="button" id="counselorNotificationClose" aria-label="Close notifications">×</button></div>
                <div class="notification-panel-tools"><span id="counselorNotificationSummary">Loading notifications...</span><button type="button" id="counselorNotificationMarkAll">Mark all as read</button></div>
                <div class="notification-panel-list" id="counselorNotificationList" role="status" aria-live="polite"><p class="notification-panel-empty">Loading...</p></div>
                <a class="notification-panel-footer" href="assessment_queue.php">Open Assessment Queue →</a>
            </section>
        </div>
        <a class="counselor-account-link" href="profile.php" aria-label="Open counselor profile">
            <span class="counselor-account-avatar" aria-hidden="true"><?= htmlspecialchars($counselorInitial, ENT_QUOTES, 'UTF-8') ?></span>
            <span class="counselor-account-copy">
                <strong><?= htmlspecialchars((string)$counselorDisplayName, ENT_QUOTES, 'UTF-8') ?></strong>
                <small>Counselor<?= $counselorDisplayEmail !== '' ? ' · ' . htmlspecialchars((string)$counselorDisplayEmail, ENT_QUOTES, 'UTF-8') : '' ?></small>
            </span>
        </a>
    </div>
</header>
