<?php
/**
 * Shared Counselor sidebar.
 * Include from counselor/*.php after counselor session validation.
 */
$currentPage = basename($_SERVER['PHP_SELF'] ?? '');
$counselorNavItems = [
    ['file' => 'dashboard.php', 'label' => 'Dashboard', 'icon' => 'grid'],
    ['file' => 'assessment_queue.php', 'label' => 'Assessment Queue', 'icon' => 'list'],
    ['file' => 'monitoring.php', 'label' => 'Monitoring', 'icon' => 'eye'],
    ['file' => 'referral.php', 'label' => 'Referral', 'icon' => 'external'],
    ['file' => 'assessment_history.php', 'label' => 'Assessment History', 'icon' => 'clock'],
    ['file' => 'profile.php', 'label' => 'Profile', 'icon' => 'user'],
];
?>
<aside class="counselor-sidebar" id="counselorSidebar" aria-label="Counselor sidebar">
    <a class="counselor-brand" href="dashboard.php" aria-label="PTC Wellness Counselor Dashboard">
        <span class="counselor-brand-mark" aria-hidden="true">
            <svg viewBox="0 0 32 32"><path d="M16 27V15"/><path d="M16 17C8 17 5 12 5 5c7 0 12 4 11 12Z"/><path d="M16 21c0-7 4-11 11-11 0 7-4 11-11 11Z"/></svg>
        </span>
        <span class="counselor-brand-copy">
            <strong>PTC Wellness</strong>
            <small>Guidance, Counseling and Values Enrichment Office</small>
        </span>
    </a>

    <nav class="counselor-nav" aria-label="Counselor navigation">
        <?php foreach ($counselorNavItems as $item): ?>
            <a class="counselor-nav-link<?= $currentPage === $item['file'] ? ' is-active' : '' ?>"
               href="<?= htmlspecialchars($item['file'], ENT_QUOTES, 'UTF-8') ?>"
               aria-label="<?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?>"
               <?= $currentPage === $item['file'] ? 'aria-current="page"' : '' ?>>
                <span class="counselor-nav-icon" aria-hidden="true">
                    <?php if ($item['icon'] === 'grid'): ?>
                        <svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
                    <?php elseif ($item['icon'] === 'list'): ?>
                        <svg viewBox="0 0 24 24"><path d="M8 6h13M8 12h13M8 18h13"/><path d="m3 6 1 1 2-2m-3 7 1 1 2-2m-3 7 1 1 2-2"/></svg>
                    <?php elseif ($item['icon'] === 'eye'): ?>
                        <svg viewBox="0 0 24 24"><path d="M3 12s3.2-7 9-7 9 7 9 7-3.2 7-9 7-9-7-9-7Z"/><circle cx="12" cy="12" r="3"/></svg>
                    <?php elseif ($item['icon'] === 'external'): ?>
                        <svg viewBox="0 0 24 24"><path d="M7 17 17 7M7 7h10v10M5 5v14h14"/></svg>
                    <?php elseif ($item['icon'] === 'clock'): ?>
                        <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
                    <?php else: ?>
                        <svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg>
                    <?php endif; ?>
                </span>
                <span class="counselor-nav-text"><?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?></span>
            </a>
        <?php endforeach; ?>
    </nav>

    <div class="counselor-sidebar-bottom">
        <div class="counselor-wellbeing-note">
            <span class="counselor-wellbeing-decoration" aria-hidden="true"><svg viewBox="0 0 32 32"><path d="M16 27V15M16 17C8 17 5 12 5 5c7 0 12 4 11 12ZM16 21c0-7 4-11 11-11 0 7-4 11-11 11Z"/></svg></span>
            <strong>Well-being matters.</strong>
            <span>Supporting a healthier campus, one step at a time.</span>
        </div>
        <a class="counselor-logout-link" href="../auth/logout.php?portal=counselor">
            <span aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M10 17l5-5-5-5M15 12H3M12 3h7a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-7"/></svg></span>
            <span class="counselor-logout-text">Logout</span>
        </a>
    </div>
</aside>
<div class="counselor-sidebar-backdrop" id="counselorSidebarBackdrop" hidden></div>

<?php // Shared counselor page loader + centralized logout confirmation. ?>
<?php require_once __DIR__ . '/shared-page-ui.php'; ?>
