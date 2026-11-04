<?php
/**
 * PTC Wellness counselor shell interaction layer.
 * Loaded once by the existing sidebar.php; no page-specific includes needed.
 * Does not alter Referral's calendar loader or any page-specific action overlays.
 */
$ptcUiCss = __DIR__ . '/../../assets/css/counselor/shared-page-ui.css';
$ptcUiJs  = __DIR__ . '/../../assets/js/counselor/shared-page-ui.js';
?>
<link rel="stylesheet" href="../assets/css/counselor/shared-page-ui.css?v=<?= (int)(@filemtime($ptcUiCss) ?: 1) ?>">
<div class="ptc-shared-ui" id="ptcSharedUi">
    <div class="ptc-page-loader" id="ptcPageLoader" role="status" aria-live="polite" aria-label="Loading PTC Wellness page">
        <div class="ptc-loading-logo" aria-hidden="true">
            <svg viewBox="0 0 32 32"><path d="M16 27V15M16 17C8 17 5 12 5 5c7 0 12 4 11 12Z"/><path d="M16 21c0-7 4-11 11-11 0 7-4 11-11 11Z"/></svg>
        </div>
        <strong class="ptc-loading-title">PTC Wellness</strong>
        <span class="ptc-loading-caption">Preparing your workspace</span>
        <span class="ptc-loading-progress" aria-hidden="true"><span></span></span>
    </div>

    <div class="ptc-logout-overlay" id="ptcSharedLogout" role="presentation" hidden>
        <section class="ptc-logout-card" role="dialog" aria-modal="true"
                 aria-labelledby="ptcLogoutTitle" aria-describedby="ptcLogoutDescription" tabindex="-1">
            <span class="ptc-logout-symbol" aria-hidden="true">
                <svg viewBox="0 0 24 24"><path d="M10 17l5-5-5-5M15 12H3M12 3h7a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-7"/></svg>
            </span>
            <h2 id="ptcLogoutTitle">Are you sure you want to log out?</h2>
            <p id="ptcLogoutDescription">You may lose any unsaved changes. Please make sure you've saved your work before leaving.</p>
            <div class="ptc-logout-actions">
                <button type="button" class="ptc-logout-cancel" id="ptcSharedLogoutCancel">Go Back</button>
                <button type="button" class="ptc-logout-confirm" id="ptcSharedLogoutConfirm">Yes, Log Out</button>
            </div>
        </section>
    </div>
</div>
<noscript><style>.ptc-page-loader{display:none!important}</style></noscript>
<script src="../assets/js/counselor/shared-page-ui.js?v=<?= (int)(@filemtime($ptcUiJs) ?: 1) ?>" defer></script>
