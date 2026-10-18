<?php
require_once __DIR__ . "/../includes/session.php"; // ensures user is logged in
// allow only admin
if (!isset($_SESSION['role_type']) || $_SESSION['role_type'] !== 'admin') {
    header("Location: ../auth/login.php");
    exit();
}
$dashboard_monitoring_mode = true;
require_once __DIR__ . "/../config/database.php"; // provides $conn (mysqli)
require_once __DIR__ . "/../includes/system_monitoring.php";

mysqli_report(MYSQLI_REPORT_OFF);
$database_available = $conn instanceof mysqli && @mysqli_ping($conn);
$database_version = 'Unavailable';
$account_data_available = false;
$monitoring_available = false;
$database_version_query_failed = false;
$total_accounts = $pending_verifications = $active_accounts = $inactive_accounts = null;
$event_counts = [
     'login_successes' => 0,
     'login_failures' => 0,
     'registrations' => 0,
     'admin_actions' => 0,
     'system_activities' => 0,
     'security_events' => 0,
     'application_errors' => 0
];
$recent_events = [];

$account_metrics = false;
if ($database_available) {
     $version_result = mysqli_query($conn, "SELECT VERSION() AS version");
     if ($version_result) {
         $version_row = mysqli_fetch_assoc($version_result);
         $database_version = $version_row['version'] ?? 'Unknown';
     } else {
         error_log('Admin dashboard database version query failed: ' . mysqli_error($conn));
         $database_version_query_failed = true;
     }

     $account_metrics = mysqli_query(
         $conn,
         "SELECT
             COUNT(*) AS total_accounts,
             COALESCE(SUM(LOWER(COALESCE(status, '')) = 'pending'), 0) AS pending_verifications,
             COALESCE(SUM(LOWER(COALESCE(status, '')) = 'active'), 0) AS active_accounts,
             COALESCE(SUM(LOWER(COALESCE(status, '')) = 'inactive'), 0) AS inactive_accounts
          FROM user_data"
     );
}

$account_data_available = (bool)$account_metrics;
if ($account_data_available) {
     $metrics = mysqli_fetch_assoc($account_metrics);
     $total_accounts = (int)$metrics['total_accounts'];
     $pending_verifications = (int)$metrics['pending_verifications'];
     $active_accounts = (int)$metrics['active_accounts'];
     $inactive_accounts = (int)$metrics['inactive_accounts'];
} else {
     if ($database_available) {
         error_log('Admin dashboard account metrics query failed: ' . mysqli_error($conn));
     }
}

$monitoring_available = $database_available && system_monitoring_table_available($conn);
if ($monitoring_available) {
     if ($database_version_query_failed) {
         system_monitor_log($conn, 'application_error', (int)$_SESSION['user_id'], null, 'Database version health check failed');
     }
     if (!$account_data_available) {
         system_monitor_log($conn, 'application_error', (int)$_SESSION['user_id'], null, 'Dashboard account metrics query failed');
     }
     $events_result = mysqli_query(
         $conn,
         "SELECT
             COALESCE(SUM(event_type = 'login_success'), 0) AS login_successes,
             COALESCE(SUM(event_type = 'login_failure'), 0) AS login_failures,
             COALESCE(SUM(event_type = 'account_registered'), 0) AS registrations,
             COALESCE(SUM(event_type IN ('account_updated', 'account_verified', 'account_rejected', 'account_status_changed', 'password_changed')), 0) AS admin_actions,
             COALESCE(SUM(event_type IN ('account_updated', 'account_verified', 'account_rejected', 'account_status_changed', 'password_changed', 'referral_created', 'referral_updated', 'referral_accepted')), 0) AS system_activities,
             COALESCE(SUM(event_type IN ('login_failure', 'password_change_failure')), 0) AS security_events,
             COALESCE(SUM(event_type = 'application_error'), 0) AS application_errors
          FROM system_monitor_events
          WHERE created_at >= NOW() - INTERVAL 1 DAY"
     );
     if ($events_result) {
         $event_counts = mysqli_fetch_assoc($events_result);
         foreach ($event_counts as $event_name => $count) {
             $event_counts[$event_name] = (int)$count;
         }
     } else {
         error_log('Admin dashboard monitoring counts query failed: ' . mysqli_error($conn));
         system_monitor_log($conn, 'application_error', (int)$_SESSION['user_id'], null, 'Dashboard monitoring counts query failed');
         $monitoring_available = false;
     }

     if ($monitoring_available) {
         $recent_events_result = mysqli_query(
             $conn,
             "SELECT event_type, actor_user_id, subject_user_id, details, created_at
              FROM system_monitor_events
              WHERE event_type IN (
                 'login_success', 'login_failure', 'account_registered', 'account_updated',
                 'account_verified', 'account_rejected', 'account_status_changed',
                 'password_changed', 'password_change_failure', 'referral_created',
                 'referral_updated', 'referral_accepted', 'application_error'
              )
              ORDER BY created_at DESC, event_id DESC
              LIMIT 20"
         );
         if ($recent_events_result) {
             while ($event = mysqli_fetch_assoc($recent_events_result)) {
                 $recent_events[] = $event;
             }
         } else {
             error_log('Admin dashboard recent events query failed: ' . mysqli_error($conn));
             system_monitor_log($conn, 'application_error', (int)$_SESSION['user_id'], null, 'Dashboard recent events query failed');
             $monitoring_available = false;
         }
     }
}

$free_disk_bytes = @disk_free_space(__DIR__);
$total_disk_bytes = @disk_total_space(__DIR__);
$server_health = $free_disk_bytes !== false && $total_disk_bytes > 0
    ? (($free_disk_bytes / $total_disk_bytes) < 0.1 ? 'Low disk space' : 'Healthy')
    : 'Not reported';
$system_status = $database_available && $account_data_available && $monitoring_available
     ? 'Operational'
     : 'Needs attention';
$system_status_note = $system_status === 'Operational'
     ? 'Application is online; database, account data, and monitoring store are available.'
     : 'One or more health checks failed. Review the checks and application error log.';
$system_checks = [
     ['label' => 'Application availability', 'status' => 'Online'],
     ['label' => 'Database connection', 'status' => $database_available ? 'Connected' : 'Unavailable'],
     ['label' => 'Database server', 'status' => $database_version],
     ['label' => 'Account data', 'status' => $account_data_available ? 'Available' : 'Unavailable'],
     ['label' => 'Monitoring event store', 'status' => $monitoring_available ? 'Available' : 'Unavailable'],
     ['label' => 'PHP runtime', 'status' => PHP_VERSION],
     ['label' => 'Server OS', 'status' => php_uname('s')],
     ['label' => 'Server disk space', 'status' => $server_health]
];

function format_bytes($bytes)
{
     $units = ['B', 'KB', 'MB', 'GB', 'TB'];
     $unit_index = 0;
     while ($bytes >= 1024 && $unit_index < count($units) - 1) {
         $bytes /= 1024;
         $unit_index++;
     }
     return round($bytes, 1) . ' ' . $units[$unit_index];
}

$memory_usage_label = format_bytes(memory_get_usage(true));
$memory_limit = ini_get('memory_limit');
$system_checks[] = [
     'label' => 'PHP memory usage',
     'status' => $memory_usage_label . ($memory_limit ? ' / ' . $memory_limit : '')
];

$disk_space_label = $server_health !== 'Not reported'
     ? format_bytes($free_disk_bytes) . ' free of ' . format_bytes($total_disk_bytes)
     : $server_health;
?>
<!doctype html>
<html>
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Admin Dashboard</title>
    <style>
      * { box-sizing: border-box; }
      html, body { margin: 0; padding: 0; }
      body {
        font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
        background: #efeeeb;
        color: #1a1a1a;
      }
      a { text-decoration: none; color: inherit; }
      .app-shell {
        display: flex;
        min-height: 100vh;
      }
      .sidebar {
        width: 260px;
        background: #5c8a60;
        color: #fff;
        padding: 22px 18px 18px;
        display: flex;
        flex-direction: column;
      }
      .brand {
        font-size: 2rem;
        font-weight: 500;
        letter-spacing: -0.05em;
        padding: 6px 12px 20px;
        color: #f5f8f3;
      }
      .nav {
        display: flex;
        flex-direction: column;
        gap: 12px;
        margin-top: 24px;
      }
      .nav-link {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 12px 14px;
        border-radius: 10px;
        font-size: 1.05rem;
        font-weight: 600;
        color: #f1f5ef;
        transition: all 0.2s ease;
      }
      .nav-link:hover,
      .nav-link.active {
        background: #edf2ee;
        color: #1f3d2a;
      }
      .nav-icon {
        width: 22px;
        display: inline-flex;
        justify-content: center;
      }
      .logout {
        margin-top: auto;
        border-top: 1px solid rgba(255,255,255,0.25);
        padding-top: 16px;
      }
      .main-content {
        flex: 1;
        padding: 30px 32px 40px;
      }
      .page-header {
        margin-bottom: 10px;
      }
      .page-title {
        font-size: 2.7rem;
        margin: 0;
        font-weight: 600;
        letter-spacing: -0.06em;
      }
      .page-subtitle {
        margin: 0 0 22px;
        font-size: 1.2rem;
        color: #4c4c4c;
      }
      .metric-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(160px, 1fr));
        gap: 18px;
        margin-bottom: 24px;
      }
      .metric-card {
        background: rgba(255,255,255,0.2);
        border: 1px solid #dfe4dc;
        border-radius: 12px;
        padding: 18px 18px 14px;
        min-height: 130px;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
      }
      .metric-label {
        font-size: 0.98rem;
        color: #2e2e2e;
        font-weight: 700;
      }
      .metric-value {
        font-size: 2.2rem;
        font-weight: 800;
        line-height: 1;
        letter-spacing: -0.05em;
      }
      .status-counts {
        display: flex;
        gap: 24px;
      }
      .status-count {
        display: flex;
        flex-direction: column;
        gap: 6px;
      }
      .status-count-label {
        color: #2e2e2e;
        font-size: 0.9rem;
        font-weight: 700;
      }
      .metric-note {
        color: #6a6a6a;
        font-size: 0.82rem;
      }
      .system-panel {
        background: rgba(255,255,255,0.2);
        border: 1px solid #dfe4dc;
        border-radius: 12px;
        padding: 18px 20px;
      }
      .system-panel h2 {
        margin: 0 0 12px;
        font-size: 1.2rem;
      }
      .system-checks {
        display: grid;
        grid-template-columns: repeat(2, minmax(220px, 1fr));
        gap: 14px;
      }
      .system-check {
        display: flex;
        justify-content: space-between;
        gap: 12px;
        padding: 12px;
        background: rgba(255,255,255,0.35);
        border-radius: 8px;
      }
      .system-check-status {
        font-weight: 700;
        text-align: right;
      }
      .monitoring-section {
        margin-top: 22px;
      }
      .monitoring-grid {
        display: grid;
        grid-template-columns: repeat(3, minmax(180px, 1fr));
        gap: 14px;
      }
      .monitoring-card {
        background: rgba(255,255,255,0.2);
        border: 1px solid #dfe4dc;
        border-radius: 12px;
        padding: 16px;
      }
      .monitoring-card h3 {
        margin: 0 0 10px;
        font-size: 0.96rem;
      }
      .monitoring-card strong {
        font-size: 1.8rem;
      }
      .monitoring-card p {
        margin: 6px 0 0;
        color: #6a6a6a;
        font-size: 0.8rem;
      }
      .events-table-wrap {
        overflow-x: auto;
      }
      .events-table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 12px;
      }
      .events-table th,
      .events-table td {
        padding: 10px;
        border-bottom: 1px solid #dfe4dc;
        text-align: left;
        vertical-align: top;
      }
      .events-table th {
        font-size: 0.9rem;
      }
      .empty-state {
        padding: 14px 0;
        color: #6a6a6a;
      }
      @media (max-width: 980px) {
        .app-shell { display: block; }
        .sidebar { width: 100%; }
        .main-content { padding: 22px 18px 30px; }
        .metric-grid { grid-template-columns: repeat(2, minmax(160px,1fr)); }
        .system-checks { grid-template-columns: 1fr; }
        .monitoring-grid { grid-template-columns: repeat(2, minmax(160px,1fr)); }
      }
      @media (max-width: 560px) {
        .metric-grid { grid-template-columns: 1fr; }
        .monitoring-grid { grid-template-columns: 1fr; }
        .page-title { font-size: 2.1rem; }
      }
    </style>
  </head>
  <body>
    <div class="app-shell">
      <aside class="sidebar">
        <div class="brand">PTC Wellness</div>
        <nav class="nav" aria-label="Main navigation">
          <a class="nav-link active" href="dashboard.php"><span class="nav-icon">🏠</span><span>Dashboard</span></a>
          <a class="nav-link" href="users.php"><span class="nav-icon">👥</span><span>Users</span></a>
          <a class="nav-link" href="settings.php"><span class="nav-icon">⚙️</span><span>Settings</span></a>
          <a class="nav-link logout" href="../auth/logout.php"><span class="nav-icon">↩️</span><span>Logout</span></a>
        </nav>
      </aside>

      <main class="main-content">

        <p class="page-subtitle">Welcome back, <?= htmlspecialchars($_SESSION['fullname'] ?? 'Admin') ?>!</p>

        <section class="metric-grid">
          <article class="metric-card">
            <div class="metric-label">Total Registered Accounts</div>
            <div class="metric-value"><?= $total_accounts ?? '—' ?></div>
          </article>
          <article class="metric-card">
            <div class="metric-label">Pending Account Verifications</div>
            <div class="metric-value"><?= $pending_verifications ?? '—' ?></div>
          </article>
          <article class="metric-card">
            <div class="metric-label">Active and Inactive Accounts</div>
            <div class="status-counts">
              <div class="status-count">
                <span class="status-count-label">Active</span>
                <span class="metric-value"><?= $active_accounts ?? '—' ?></span>
              </div>
              <div class="status-count">
                <span class="status-count-label">Inactive</span>
                <span class="metric-value"><?= $inactive_accounts ?? '—' ?></span>
              </div>
            </div>
          </article>
        </section>

        <section class="system-panel" aria-labelledby="system-monitoring-title">
          <h2 id="system-monitoring-title">System Monitoring</h2>
          <div class="system-checks">
            <?php foreach ($system_checks as $check): ?>
              <div class="system-check">
                <span><?= htmlspecialchars($check['label']) ?></span>
                <span class="system-check-status">
                  <?= htmlspecialchars($check['label'] === 'Server disk space' ? $disk_space_label : $check['status']) ?>
                </span>
              </div>
            <?php endforeach; ?>
          </div>
        </section>

        <section class="monitoring-section" aria-labelledby="activity-monitoring-title">
          <h2 id="activity-monitoring-title">Activity and Error Counts</h2>
          <div class="monitoring-grid">
            <article class="monitoring-card">
              <h3>Application Availability and Errors</h3>
              <strong>Online</strong>
              <p><?= $monitoring_available ? $event_counts['application_errors'] : '—' ?> logged application errors</p>
            </article>
            <article class="monitoring-card">
              <h3>Successful Logins</h3>
              <strong><?= $monitoring_available ? $event_counts['login_successes'] : '—' ?></strong>
              <p>Successful authentications</p>
            </article>
            <article class="monitoring-card">
              <h3>Failed Logins</h3>
              <strong><?= $monitoring_available ? $event_counts['login_failures'] : '—' ?></strong>
              <p>Failed or blocked sign-in attempts</p>
            </article>
            <article class="monitoring-card">
              <h3>Registrations</h3>
              <strong><?= $monitoring_available ? $event_counts['registrations'] : '—' ?></strong>
              <p>Accounts created by an administrator</p>
            </article>
            <article class="monitoring-card">
              <h3>Administrative Actions</h3>
              <strong><?= $monitoring_available ? $event_counts['admin_actions'] : '—' ?></strong>
              <p>Account changes and password changes</p>
            </article>
            <article class="monitoring-card">
              <h3>Security Events</h3>
              <strong><?= $monitoring_available ? $event_counts['security_events'] : '—' ?></strong>
              <p>Failed sign-ins and failed password checks</p>
            </article>
          </div>
        </section>

        <section class="system-panel monitoring-section" aria-labelledby="security-events-title">
          <h2 id="security-events-title">Security Events and Administrative Actions</h2>
          <p class="metric-note">Most recent recorded events.</p>
          <?php if (!$monitoring_available): ?>
            <p class="empty-state">Event history is unavailable. Check database access and the server error log.</p>
          <?php elseif (!$recent_events): ?>
            <p class="empty-state">No security or administrative events have been recorded yet.</p>
          <?php else: ?>
            <div class="events-table-wrap">
              <table class="events-table">
                <thead>
                  <tr>
                    <th>Time</th>
                    <th>Event</th>
                    <th>Admin/user ID</th>
                    <th>Target account ID</th>
                    <th>Details</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($recent_events as $event): ?>
                    <tr>
                      <td><?= htmlspecialchars($event['created_at']) ?></td>
                      <td><?= htmlspecialchars(ucwords(str_replace('_', ' ', $event['event_type']))) ?></td>
                      <td><?= $event['actor_user_id'] === null ? '—' : (int)$event['actor_user_id'] ?></td>
                      <td><?= $event['subject_user_id'] === null ? '—' : (int)$event['subject_user_id'] ?></td>
                      <td><?= htmlspecialchars($event['details']) ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </section>
      </main>
    </div>
  </body>
</html>
