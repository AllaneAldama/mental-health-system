<?php
require_once __DIR__ . '/../includes/admin_session.php';
require_once __DIR__ . '/../config/database.php';
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
$active_admin_count = null;
$active_counselor_count = null;
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
    $account_metrics = mysqli_query(
        $conn,
        "SELECT
            COALESCE(SUM(
                LOWER(COALESCE(role_type, '')) IN ('admin', 'counselor')
            ), 0) AS total_accounts,

            COALESCE(SUM(
                LOWER(COALESCE(role_type, '')) IN ('admin', 'counselor')
                AND LOWER(COALESCE(status, '')) = 'pending'
            ), 0) AS pending_verifications,

            COALESCE(SUM(
                LOWER(COALESCE(role_type, '')) IN ('admin', 'counselor')
                AND LOWER(COALESCE(status, '')) = 'active'
            ), 0) AS active_accounts,

            COALESCE(SUM(
                LOWER(COALESCE(role_type, '')) IN ('admin', 'counselor')
                AND LOWER(COALESCE(status, '')) = 'inactive'
            ), 0) AS inactive_accounts,

            COALESCE(SUM(
                LOWER(COALESCE(role_type, '')) = 'admin'
                AND LOWER(COALESCE(status, '')) = 'active'
            ), 0) AS active_admins,

            COALESCE(SUM(
                LOWER(COALESCE(role_type, '')) = 'counselor'
                AND LOWER(COALESCE(status, '')) = 'active'
            ), 0) AS active_counselors

        FROM user_data"
    );
}

$account_data_available = (bool) $account_metrics;

if ($account_data_available) {
    $metrics = mysqli_fetch_assoc($account_metrics);

    $total_accounts = (int) $metrics['total_accounts'];
    $pending_verifications = (int) $metrics['pending_verifications'];
    $active_accounts = (int) $metrics['active_accounts'];
    $inactive_accounts = (int) $metrics['inactive_accounts'];
    $active_admin_count = (int) $metrics['active_admins'];
    $active_counselor_count = (int) $metrics['active_counselors'];

    mysqli_free_result($account_metrics);
} else {
    if ($database_available) {
        error_log(
            'Admin dashboard account metrics query failed: '
            . mysqli_error($conn)
        );
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
    <link rel="stylesheet" href="../assets/css/admin.css">
    <style>
      :root {
        --sage: #5f8b5b; --sage-dark: #3f704b; --forest: #26392d;
        --text: #29372f; --muted: #6c786f; --page: #f7f8f3;
        --surface: rgba(255,255,255,.94); --surface-soft: #edf5e9;
        --border: #e0e8dc; --success: #26804a; --warning: #a87316;
        --danger: #b74440; --shadow: 0 8px 26px rgba(43,69,44,.045);
      }
      * { box-sizing: border-box; }
      html, body { margin: 0; min-height: 100%; }
      body { font-family: "Poppins", "Segoe UI", Arial, sans-serif; color: var(--text); background: radial-gradient(ellipse at 3% 0%, rgba(221,236,210,.58), transparent 25rem), radial-gradient(ellipse at 98% 10%, rgba(230,239,221,.7), transparent 26rem), linear-gradient(135deg,#f8faf6 0%,#f3f6ef 55%,#fafbf8 100%); background-attachment: fixed; }
      body::before { content:""; position:fixed; z-index:-1; pointer-events:none; width:360px; height:360px; right:-170px; top:180px; border-radius:48% 52% 63% 37% / 43% 40% 60% 57%; background:rgba(222,237,213,.22); transform:rotate(-22deg); }
      a { text-decoration:none; color:inherit; } button,input,select { font:inherit; }
      .app-shell { display:flex; min-height:100vh; }
      .sidebar { width:258px; flex:0 0 258px; position:sticky; top:0; height:100vh; overflow:hidden; background:linear-gradient(160deg,#507b57 0%,#3f6d4c 100%); color:#fff; padding:27px 16px 20px; display:flex; flex-direction:column; box-shadow:7px 0 28px rgba(37,68,44,.07); z-index:2; }
      .sidebar::before { content:""; position:absolute; width:270px; height:270px; left:-150px; bottom:-145px; border:1px solid rgba(255,255,255,.10); border-radius:50%; box-shadow:0 0 0 32px rgba(255,255,255,.025),0 0 0 68px rgba(255,255,255,.02); pointer-events:none; }
      .brand { position:relative; display:flex; align-items:center; gap:10px; padding:2px 8px 24px; color:#fff; }
      .brand-mark { width:43px; height:43px; flex:0 0 43px; display:grid; place-items:center; border:1px solid rgba(255,255,255,.23); border-radius:14px; background:rgba(255,255,255,.10); }
      .brand-mark svg { width:29px; height:29px; }
      .brand-copy { min-width:0; }
      .brand-name { display:block; font-size:1.17rem; font-weight:800; letter-spacing:-.045em; line-height:1.15; white-space:nowrap; }
      .brand-tagline { display:block; margin-top:5px; color:rgba(255,255,255,.76); font-size:.62rem; letter-spacing:.015em; white-space:nowrap; }
      .nav { position:relative; display:flex; flex-direction:column; gap:7px; margin-top:10px; }
      .nav-link { display:flex; align-items:center; gap:12px; min-height:46px; padding:11px 13px; border-radius:12px; color:#edf5ee; font-size:.91rem; font-weight:600; transition:background .18s ease,color .18s ease,transform .18s ease; }
      .nav-link:hover { background:rgba(255,255,255,.12); color:#fff; transform:translateX(2px); }
      .nav-link.active { background:#f5f8f1; color:#294331; box-shadow:0 3px 12px rgba(29,54,35,.08); }
      .nav-icon { width:22px; height:22px; display:inline-grid; place-items:center; flex:0 0 22px; }
      .nav-icon svg { width:20px; height:20px; fill:none; stroke:currentColor; stroke-width:1.8; stroke-linecap:round; stroke-linejoin:round; }
      .logout { margin-top:12px; border-top:1px solid rgba(255,255,255,.20); padding-top:17px; }
      .sidebar-footer { position:relative; margin-top:auto; padding:15px 13px; border:1px solid rgba(255,255,255,.15); border-radius:14px; background:rgba(255,255,255,.055); color:rgba(255,255,255,.84); font-size:.78rem; line-height:1.55; }
      .sidebar-footer strong { display:block; margin-bottom:3px; color:#fff; font-size:.82rem; }
      .main-content { flex:1; min-width:0; padding:30px clamp(20px,3vw,46px) 42px; }
      .page-header { display:flex; align-items:flex-start; justify-content:space-between; gap:20px; margin-bottom:24px; }
      .eyebrow { margin:0 0 7px; color:var(--sage-dark); text-transform:uppercase; letter-spacing:.13em; font-size:.7rem; font-weight:800; }
      .page-title { margin:0; color:var(--forest); font-size:clamp(1.9rem,2.7vw,2.55rem); line-height:1.15; font-weight:800; letter-spacing:-.055em; }
      .page-subtitle { margin:8px 0 0; color:var(--muted); font-size:.92rem; line-height:1.6; }
      .header-right { display:flex; align-items:center; gap:15px; }
      .header-status { display:inline-flex; align-items:center; gap:8px; padding:9px 13px; border:1px solid var(--border); border-radius:999px; background:rgba(255,255,255,.8); color:var(--success); font-size:.78rem; font-weight:750; white-space:nowrap; }
      .status-dot { width:8px; height:8px; border-radius:50%; background:#39a65d; box-shadow:0 0 0 4px rgba(57,166,93,.11); }
      .metric-grid { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:15px; margin-bottom:20px; }
      .metric-card { position:relative; overflow:hidden; isolation:isolate; display:flex; flex-direction:column; justify-content:space-between; min-height:126px; padding:19px 20px; border:1px solid var(--border); border-radius:17px; background:var(--surface); box-shadow:var(--shadow); }
      .metric-card::after { content:""; position:absolute; z-index:-1; width:130px; height:80px; right:-38px; bottom:-42px; border-radius:60% 40% 0 0; background:var(--accent-soft,#eaf4e5); opacity:.88; transform:rotate(-15deg); }
      .metric-card:nth-child(2) { --accent-soft:#fff4d9; }
      .metric-top { display:flex; justify-content:space-between; align-items:flex-start; gap:12px; }
      .metric-label { max-width:250px; color:#536256; font-size:.84rem; line-height:1.45; font-weight:700; }
      .metric-symbol { width:42px; height:42px; flex:0 0 42px; display:grid; place-items:center; border-radius:13px; background:var(--accent-soft,#eaf4e5); color:var(--accent,#39764b); }
      .metric-symbol svg { width:22px; height:22px; fill:none; stroke:currentColor; stroke-width:1.9; stroke-linecap:round; stroke-linejoin:round; }
      .metric-value { margin-top:13px; color:var(--forest); font-size:2.2rem; line-height:1; font-weight:800; letter-spacing:-.055em; }
      .status-counts { display:flex; gap:24px; margin-top:15px; }
      .status-count { display:flex; flex-direction:column; gap:5px; }
      .status-count-label { color:var(--muted); font-size:.77rem; font-weight:650; }
      .status-count .metric-value { margin-top:0; font-size:1.7rem; }
      .system-panel { padding:21px; border:1px solid var(--border); border-radius:18px; background:var(--surface); box-shadow:var(--shadow); }
      .section-heading { display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:15px; }
      .section-title-wrap { display:flex; align-items:center; gap:11px; }
      .section-icon { display:grid; place-items:center; width:40px; height:40px; flex:0 0 40px; border-radius:13px; background:#eaf4e7; color:var(--sage-dark); }
      .section-icon svg { width:22px; height:22px; fill:none; stroke:currentColor; stroke-width:1.8; stroke-linecap:round; stroke-linejoin:round; }
      .system-panel h2,.monitoring-section>h2 { margin:0; color:var(--forest); font-size:1.1rem; letter-spacing:-.025em; }
      .section-description { margin:5px 0 0; color:var(--muted); font-size:.81rem; line-height:1.5; }
      .health-pill { display:inline-flex; align-items:center; gap:7px; padding:8px 11px; border-radius:999px; background:#edf7eb; color:#327347; font-size:.74rem; font-weight:700; white-space:nowrap; }
      .health-pill svg { width:16px; height:16px; fill:none; stroke:currentColor; stroke-width:1.8; }
      .system-checks { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:8px 11px; }
      .system-check { display:flex; align-items:center; justify-content:space-between; gap:12px; min-width:0; padding:11px 13px; border:1px solid #edf1e9; border-radius:11px; background:#f7f9f5; }
      .system-check>span:first-child { color:#667267; font-size:.8rem; line-height:1.4; }
      .system-check-status { color:#26392d; font-size:.79rem; font-weight:750; text-align:right; overflow-wrap:anywhere; }
      .monitoring-section { margin-top:23px; }
      .monitoring-heading { display:flex; align-items:center; gap:11px; margin-bottom:14px; }
      .monitoring-grid { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:11px; }
      .monitoring-card { --card-accent:#2f8a51; --card-bg:#f2f9f0; position:relative; isolation:isolate; display:grid; grid-template-columns:44px minmax(0,1fr); align-items:center; gap:12px; min-width:0; overflow:hidden; padding:14px; border:1px solid var(--card-border,#e0ecdc); border-radius:14px; background:linear-gradient(115deg,var(--card-bg) 0%,#fff 100%); }
      .monitoring-card::after { content:""; position:absolute; z-index:-1; right:-22px; bottom:-39px; width:100px; height:80px; border-radius:50%; background:var(--card-accent); opacity:.055; }
      .monitoring-card:nth-child(2) { --card-accent:#2386d8; --card-bg:#f0f7ff; --card-border:#dfebfa; }
      .monitoring-card:nth-child(3),.monitoring-card:nth-child(6) { --card-accent:#e04444; --card-bg:#fff4f3; --card-border:#f5e2df; }
      .monitoring-card:nth-child(4) { --card-accent:#3187c6; --card-bg:#f2f8ff; --card-border:#e0ebf8; }
      .monitoring-card:nth-child(5) { --card-accent:#8261d4; --card-bg:#f7f3ff; --card-border:#eae2fb; }
      .monitoring-symbol { width:44px; height:44px; display:grid; place-items:center; border-radius:13px; color:var(--card-accent); background:color-mix(in srgb,var(--card-accent) 10%,white); }
      .monitoring-symbol svg { width:23px; height:23px; fill:none; stroke:currentColor; stroke-width:1.8; stroke-linecap:round; stroke-linejoin:round; }
      .monitoring-copy { min-width:0; }
      .monitoring-card h3 { margin:0 0 5px; color:#526154; font-size:.76rem; line-height:1.4; font-weight:750; }
      .monitoring-card strong { display:block; color:var(--forest); font-size:1.55rem; line-height:1.1; letter-spacing:-.04em; overflow-wrap:anywhere; }
      .monitoring-card p { margin:5px 0 0; color:var(--muted); font-size:.72rem; line-height:1.45; }
      .events-panel { padding:21px; }
      .events-table-wrap { overflow-x:auto; border:1px solid #e7ede3; border-radius:12px; }
      .events-table { width:100%; min-width:760px; border-collapse:separate; border-spacing:0; margin:0; }
      .events-table th,.events-table td { padding:12px 13px; border-bottom:1px solid #e9eee5; text-align:left; vertical-align:top; font-size:.78rem; line-height:1.5; }
      .events-table th { background:#f0f5ec; color:#536653; font-size:.7rem; text-transform:uppercase; letter-spacing:.045em; font-weight:800; white-space:nowrap; }
      .events-table tbody tr { background:rgba(255,255,255,.75); transition:background .15s ease; }
      .events-table tbody tr:hover { background:#f5f8f1; }
      .events-table tbody tr:last-child td { border-bottom:0; }
      .events-table td { color:#59665b; overflow-wrap:anywhere; }
      .events-table td:nth-child(2) { color:var(--forest); font-weight:650; }
      .empty-state { padding:18px 4px; color:var(--muted); font-size:.88rem; }
      @media (max-width:1180px) { .sidebar { width:230px; flex-basis:230px; } .main-content { padding-left:24px; padding-right:24px; } .monitoring-grid { grid-template-columns:repeat(2,minmax(0,1fr)); } }
      @media (max-width:900px) { .app-shell { display:block; } .sidebar { width:100%; height:auto; min-height:0; position:relative; padding:16px 18px; } .brand { padding-bottom:13px; } .nav { flex-direction:row; flex-wrap:wrap; gap:7px; margin-top:4px; } .nav-link { min-height:42px; padding:9px 11px; } .logout { margin:0; border:0; padding-top:9px; } .sidebar-footer { display:none; } .main-content { padding:24px 20px 34px; } }
      @media (max-width:640px) { .main-content { padding:21px 13px 28px; } .page-header { display:block; } .header-right { margin-top:14px; } .metric-grid { grid-template-columns:1fr; gap:11px; } .metric-card { min-height:116px; } .system-panel,.events-panel { padding:15px; } .section-heading { align-items:flex-start; } .health-pill { display:none; } .system-checks,.monitoring-grid { grid-template-columns:1fr; } .system-check { align-items:flex-start; } .system-check-status { max-width:58%; } .monitoring-card { padding:13px; } .page-title { font-size:1.85rem; } .brand-name { font-size:1.08rem; } }
      @media (prefers-reduced-motion:reduce) { *,*::before,*::after { scroll-behavior:auto!important; transition:none!important; } }

      /* Visual refresh v2: clearer branding, richer sidebar, restrained background */
      body {
        background:
          radial-gradient(ellipse at 94% 3%, rgba(224,239,214,.68) 0, rgba(224,239,214,0) 24rem),
          radial-gradient(ellipse at 0% 100%, rgba(226,239,220,.45) 0, rgba(226,239,220,0) 22rem),
          #f7f8f4;
      }
      body::after {
        content:""; position:fixed; z-index:0; pointer-events:none;
        width:300px; height:300px; right:-170px; top:360px;
        border:1px solid rgba(95,139,91,.09); border-radius:50%;
        box-shadow:0 0 0 28px rgba(95,139,91,.025), 0 0 0 58px rgba(95,139,91,.018);
      }
      .app-shell { position:relative; z-index:1; }
      .sidebar {
        background:linear-gradient(165deg,#416d4d 0%,#315d42 58%,#284d38 100%);
        width:264px; flex-basis:264px; padding:28px 17px 22px;
        box-shadow:8px 0 30px rgba(34,70,43,.10);
      }
      .sidebar::after {
        content:""; position:absolute; pointer-events:none; width:210px;height:210px;
        right:-125px; top:235px; border:1px solid rgba(255,255,255,.09);
        border-radius:48% 52% 60% 40%; transform:rotate(28deg);
      }
      .brand { padding:4px 7px 30px; gap:12px; }
      .brand-mark {
        width:48px;height:48px;flex-basis:48px;border-radius:16px;
        background:linear-gradient(145deg,rgba(255,255,255,.18),rgba(255,255,255,.06));
        border:1px solid rgba(255,255,255,.28); box-shadow:0 5px 16px rgba(0,0,0,.08);
      }
      .brand-mark svg { width:31px;height:31px; }
      .brand-name { font-size:1.2rem; letter-spacing:-.04em; }
      .brand-tagline { font-size:.64rem; letter-spacing:.01em; }
      .nav { gap:8px; }
      .nav-link {
        min-height:48px; border:1px solid transparent; border-radius:13px;
        padding:12px 13px; color:rgba(255,255,255,.88); font-size:.94rem;
        transition:background .18s ease, transform .18s ease, border-color .18s ease;
      }
      .nav-link:hover { background:rgba(255,255,255,.10); border-color:rgba(255,255,255,.10); transform:translateX(2px); color:#fff; }
      .nav-link.active { background:#f3f7ee; color:#294c35; box-shadow:0 5px 16px rgba(19,48,28,.12); }
      .nav-icon { width:22px;height:22px; }
      .nav-icon svg { width:21px;height:21px;stroke-width:1.8; }
      .logout { margin-top:14px; border-top:1px solid rgba(255,255,255,.2); padding-top:17px; border-radius:0; }
      .sidebar-footer {
        margin: auto 3px 0; padding:17px 15px; border:1px solid rgba(255,255,255,.18);
        border-radius:16px; background:rgba(255,255,255,.065); color:rgba(255,255,255,.76);
        font-size:.78rem; line-height:1.65;
      }
      .sidebar-footer strong { display:block; color:#fff; font-size:.9rem; margin-bottom:4px; }
      .main-content { padding:32px clamp(22px,2.5vw,42px) 44px; }
      .page-header { margin-bottom:23px; }
      .page-title { font-size:clamp(2.05rem,2.4vw,2.65rem); letter-spacing:-.055em; color:#26392d; }
      .page-subtitle { color:#718077; font-size:1rem; margin-top:7px; }
      .metric-grid { gap:16px; margin-bottom:21px; }
      .metric-card {
        position:relative; overflow:hidden; background:rgba(255,255,255,.94);
        border:1px solid #e0e9dc; border-radius:19px; padding:22px 22px 19px;
        min-height:145px; box-shadow:0 7px 24px rgba(43,69,44,.045);
        transition:transform .18s ease, box-shadow .18s ease;
      }
      .metric-card:hover { transform:translateY(-2px); box-shadow:0 12px 28px rgba(43,69,44,.075); }
      .metric-card::after {
        content:""; position:absolute; width:100px;height:100px;right:-40px;top:-45px;
        border-radius:50%; background:#edf5e8; opacity:.9; pointer-events:none;
      }
      .metric-card:nth-child(2)::after { background:#fbf3dc; }
      .metric-card:nth-child(3)::after { background:#e8f2e5; }
      .metric-label { position:relative;z-index:1;color:#526459;font-size:.88rem;line-height:1.4; }
      .metric-value { position:relative;z-index:1;color:#20372a;font-size:2.45rem; }
      .metric-note { color:#7a877e; }
      .system-panel,.events-panel {
        background:rgba(255,255,255,.94); border:1px solid #e0e9dc;
        border-radius:20px; padding:23px; box-shadow:0 8px 26px rgba(43,69,44,.04);
      }
      .system-panel h2,.monitoring-section > h2,.events-panel h2 { color:#2b4433; letter-spacing:-.025em; }
      .system-checks { gap:9px 12px; }
      .system-check { min-height:44px; padding:12px 14px; border:1px solid #edf1e9; border-radius:12px; background:#f7f9f5; }
      .system-check-status { color:#294734; }
      .monitoring-section { margin-top:25px; }
      .monitoring-grid { gap:13px; }
      .monitoring-card {
        position:relative; overflow:hidden; background:rgba(255,255,255,.96);
        border:1px solid #e1e9de; border-radius:17px; padding:19px;
        box-shadow:0 6px 20px rgba(43,69,44,.035);
      }
      .monitoring-card:nth-child(1) { background:linear-gradient(120deg,#fff 0%,#f1f8ed 100%); }
      .monitoring-card:nth-child(2) { background:linear-gradient(120deg,#fff 0%,#f0f6fd 100%); }
      .monitoring-card:nth-child(3),.monitoring-card:nth-child(6) { background:linear-gradient(120deg,#fff 0%,#fff3f1 100%); }
      .monitoring-card:nth-child(4) { background:linear-gradient(120deg,#fff 0%,#f0f7fc 100%); }
      .monitoring-card:nth-child(5) { background:linear-gradient(120deg,#fff 0%,#f6f1fd 100%); }
      .monitoring-card h3 { color:#526459; line-height:1.45; }
      .monitoring-card strong { color:#253c2e; }
      .monitoring-card p { color:#748077; line-height:1.5; }
      .events-table-wrap { border:1px solid #e6ece2; border-radius:13px; background:#fff; }
      .events-table { margin-top:0; border-collapse:separate; border-spacing:0; }
      .events-table th { background:#f2f7ef; color:#48604d; font-size:.8rem; letter-spacing:.02em; }
      .events-table th:first-child { border-top-left-radius:12px; }
      .events-table th:last-child { border-top-right-radius:12px; }
      .events-table td,.events-table th { padding:13px 14px; border-bottom:1px solid #edf1e9; }
      .events-table tbody tr:hover { background:#f8faf6; }
      @media(max-width:980px) {
        .sidebar { width:100%;height:auto;position:relative;flex-basis:auto; }
        .brand { padding-bottom:17px; }
        .sidebar-footer { display:none; }
      }
      @media(max-width:640px) {
        .sidebar { padding:18px 14px; }
        .nav-link { min-height:42px; }
        .main-content { padding:22px 15px 30px; }
        .metric-card { min-height:122px; }
        .system-panel,.events-panel { padding:16px; }
        .monitoring-card { padding:15px; }
      }

      
/* Admin logout modal sizing and spacing fix */
.logout-overlay .logout-dialog {
    width: min(500px, calc(100vw - 32px)) !important;
    max-width: 500px !important;
    box-sizing: border-box;
    padding: 36px 34px !important;
    border-radius: 20px;
}

.logout-overlay .logout-icon {
    width: 64px;
    height: 64px;
    margin: 0 auto 20px;
}

.logout-overlay .logout-dialog h2 {
    margin: 0 0 14px;
    font-size: 1.3rem;
    line-height: 1.45;
    overflow-wrap: normal;
    word-break: normal;
}

.logout-overlay .logout-dialog p {
    margin: 0 0 26px;
    font-size: 0.92rem;
    line-height: 1.75;
}

.logout-overlay .logout-actions {
    display: flex;
    flex-direction: row;
    justify-content: center;
    align-items: stretch;
    gap: 12px;
}

.logout-overlay .logout-actions button {
    flex: 1 1 0;
    width: auto;
    min-width: 0;
    min-height: 44px;
    padding: 12px 16px;
    border-radius: 10px;
    font-size: 0.88rem;
}

@media (max-width: 480px) {
    .logout-overlay .logout-dialog {
        width: calc(100vw - 32px) !important;
        padding: 28px 22px !important;
    }

    .logout-overlay .logout-dialog h2 {
        font-size: 1.15rem;
    }

    .logout-overlay .logout-actions {
        flex-direction: column;
    }

    .logout-overlay .logout-actions button {
        flex: auto;
        width: 100%;
    }
}

    </style>
  </head>


   <body>
    <div class="app-shell">
      <aside class="sidebar">
        <div class="brand">
          <span class="brand-mark" aria-hidden="true"><svg viewBox="0 0 32 32" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M16 27V15"/><path d="M16 20C8 20 5 14 5 7c7 0 13 3 13 10"/><path d="M16 16C16 8 21 4 28 4c0 8-4 13-12 14"/><path d="M10 27h13"/></svg></span>
          <span class="brand-copy"><span class="brand-name">PTC Wellness</span><span class="brand-tagline">Mental Health &amp; Well-being</span></span>
        </div>
        <nav class="nav" aria-label="Main navigation">
          <a class="nav-link active" href="dashboard.php"><span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="m3 10 9-7 9 7"/><path d="M5.5 9v11h5.2v-6h2.6v6h5.2V9"/></svg></span><span>Dashboard</span></a>
          <a class="nav-link" href="users.php"><span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M16 20v-1.5a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4V20"/><circle cx="9.5" cy="7" r="3.5"/><path d="M17 4.2a3.5 3.5 0 0 1 0 6.7M18 14.5a4 4 0 0 1 3 3.8V20"/></svg></span><span>Users</span></a>
          <a class="nav-link" href="settings.php"><span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="m19.4 15 .1.1 1.2 1-1.4 2.4-1.5-.5a7.8 7.8 0 0 1-1.6.9l-.3 1.6h-2.8l-.3-1.6a7.8 7.8 0 0 1-1.6-.9l-1.5.5-1.4-2.4 1.2-1a7.2 7.2 0 0 1 0-1.9l-1.2-1 1.4-2.4 1.5.5a7.8 7.8 0 0 1 1.6-.9l.3-1.6h2.8l.3 1.6a7.8 7.8 0 0 1 1.6.9l1.5-.5 1.4 2.4-1.2 1a7.2 7.2 0 0 1 0 1.8Z"/></svg></span><span>Settings</span></a>
          <a class="nav-link logout" href="../auth/logout.php?portal=admin"><span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M10 17l5-5-5-5"/><path d="M15 12H3"/><path d="M12 3h6a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-6"/></svg></span><span>Logout</span></a>
        </nav>
       <div class="sidebar-footer">
          <strong>Well-being matters.</strong>
          <span>
              Supporting a healthier campus, one step at a time.
          </span>
        </div>
      </aside>

      <main class="main-content">

        <header class="page-header">
          <div>
            <p class="eyebrow">PTC Wellness · Administration</p>
            <h1 class="page-title">Admin Dashboard</h1>
            <p class="page-subtitle">Welcome back, <?= htmlspecialchars($_SESSION['fullname'] ?? 'Admin', ENT_QUOTES, 'UTF-8') ?>. Here’s your system overview.</p>
          </div>
          <div class="header-right"><div class="header-status"><span class="status-dot"></span><?= htmlspecialchars($system_status) ?></div></div>
        </header>

        <section class="metric-grid">
          <article class="metric-card">
            <div class="metric-top"><div class="metric-label">Total Personnel Accounts</div><span class="metric-symbol" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M16 20v-1.5a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4V20"/><circle cx="9.5" cy="7" r="3.5"/><path d="M17 4.5a3.5 3.5 0 0 1 0 6.5M18 14.5a4 4 0 0 1 3 3.5V20"/></svg></span></div>
            <div class="metric-value"><?= $total_accounts ?? '—' ?></div>
          </article>
          <article class="metric-card">
            <div class="metric-top"><div class="metric-label">Active Admin &amp; Counselor Accounts</div><span class="metric-symbol" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M16 20v-1.5a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4V20"/><circle cx="9.5" cy="7" r="3.5"/><path d="M17 11.5a3.5 3.5 0 0 0 0-7"/><path d="M18 14.5a4 4 0 0 1 3 3.5V20"/></svg></span></div>
            <div class="status-counts">
              <div class="status-count">
                <span class="status-count-label">Admin</span>
                <span class="metric-value"><?= $active_admin_count ?? '—' ?></span>
              </div>
              <div class="status-count">
                <span class="status-count-label">Counselor</span>
                <span class="metric-value"><?= $active_counselor_count ?? '—' ?></span>
              </div>
            </div>
          </article>
          <article class="metric-card">
            <div class="metric-top"><div class="metric-label">Active and Inactive Accounts</div><span class="metric-symbol" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 19V12M10 19V5M16 19V9M22 19V3"/></svg></span></div>
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
          <div class="section-heading"><div><h2 id="system-monitoring-title">System Monitoring</h2><p class="section-description">Live checks for application and server health.</p></div></div>
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
          <div class="monitoring-heading"><span class="section-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M5 20v-7M12 20V4M19 20V9"/></svg></span><div><h2 id="activity-monitoring-title">Activity and Error Counts</h2><p class="section-description">Overview of system activity based on recorded events.</p></div></div>
          <div class="monitoring-grid">
            <article class="monitoring-card">
              <span class="monitoring-symbol" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="4" y="4" width="16" height="5" rx="1.5"/><rect x="4" y="10" width="16" height="5" rx="1.5"/><rect x="4" y="16" width="16" height="4" rx="1.5"/><path d="M8 6.5h.01M8 12.5h.01M8 18h.01"/></svg></span><div class="monitoring-copy">
              <h3>Application Availability and Errors</h3>
              <strong>Online</strong>
              <p><?= $monitoring_available ? $event_counts['application_errors'] : '—' ?> logged application errors</p></div>
            </article>
            <article class="monitoring-card">
              <span class="monitoring-symbol" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M14 3h-7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h7"/><path d="M10 12h11M16 7l5 5-5 5"/></svg></span><div class="monitoring-copy">
              <h3>Successful Logins</h3>
              <strong><?= $monitoring_available ? $event_counts['login_successes'] : '—' ?></strong>
              <p>Successful authentications</p></div>
            </article>
            <article class="monitoring-card">
              <span class="monitoring-symbol" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="9" cy="7" r="4"/><path d="M2.5 20v-1.5A4.5 4.5 0 0 1 7 14h4a4.5 4.5 0 0 1 4.5 4.5V20"/><circle cx="18" cy="17" r="3"/><path d="m20.2 19.2 1.3 1.3"/></svg></span><div class="monitoring-copy">
              <h3>Failed Logins</h3>
              <strong><?= $monitoring_available ? $event_counts['login_failures'] : '—' ?></strong>
              <p>Failed or blocked sign-in attempts</p></div>
            </article>
            <article class="monitoring-card">
              <span class="monitoring-symbol" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><path d="M14 2v6h6M8 13h8M8 17h8"/></svg></span><div class="monitoring-copy">
              <h3>Registrations</h3>
              <strong><?= $monitoring_available ? $event_counts['registrations'] : '—' ?></strong>
              <p>Accounts created by an administrator</p></div>
            </article>
            <article class="monitoring-card">
              <span class="monitoring-symbol" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="m19 13.5 1.2 1-1.6 2.7-1.5-.5a7 7 0 0 1-1.4.8l-.3 1.6h-3.2l-.3-1.6a7 7 0 0 1-1.4-.8l-1.5.5-1.6-2.7 1.2-1a6 6 0 0 1 0-3l-1.2-1L7 6.8l1.5.5a7 7 0 0 1 1.4-.8l.3-1.6h3.2l.3 1.6a7 7 0 0 1 1.4.8l1.5-.5 1.6 2.7-1.2 1a6 6 0 0 1 0 3Z"/></svg></span><div class="monitoring-copy">
              <h3>Administrative Actions</h3>
              <strong><?= $monitoring_available ? $event_counts['admin_actions'] : '—' ?></strong>
              <p>Account changes and password changes</p></div>
            </article>
            <article class="monitoring-card">
              <span class="monitoring-symbol" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 3 21 7v5c0 5-3.8 8-9 10-5.2-2-9-5-9-10V7Z"/><path d="M12 8v5M12 16h.01"/></svg></span><div class="monitoring-copy">
              <h3>Security Events</h3>
              <strong><?= $monitoring_available ? $event_counts['security_events'] : '—' ?></strong>
              <p>Failed sign-ins and failed password checks</p></div>
            </article>
          </div>
        </section>

        <section class="system-panel events-panel monitoring-section" aria-labelledby="security-events-title">
          <h2 id="security-events-title">Security Events and Administrative Actions</h2>
          <p class="section-description" style="margin:6px 0 16px;">Most recent recorded security and administrative events.</p>
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

    
      <!-- Logout Confirmation Modal -->
      <div class="logout-overlay" id="logout-modal"
          aria-hidden="true">
        <section class="logout-dialog"
                role="dialog"
                aria-modal="true"
                aria-labelledby="logout-title"
                aria-describedby="logout-description">

          <div class="logout-icon">
            <svg viewBox="0 0 24 24" fill="none"
                stroke="currentColor" stroke-width="1.8"
                stroke-linecap="round" stroke-linejoin="round"
                aria-hidden="true">
              <path d="M10 17l5-5-5-5"/>
              <path d="M15 12H3"/>
              <path d="M12 3h7a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-7"/>
            </svg>
          </div>

          <h2 id="logout-title">Are you sure you want to log out?</h2>

          <p id="logout-description">
            You may lose any unsaved changes. Please make sure
            you've saved your work before leaving.
          </p>

          <div class="logout-actions">
            <button type="button" class="logout-confirm"
                    id="confirm-logout">
              Yes, Log Out
            </button>

            <button type="button" class="logout-cancel"
                    id="cancel-logout">
              Go Back
            </button>
          </div>
        </section>
      </div>
      <script>
    (() => {
        const modal = document.getElementById('logout-modal');
        const cancelButton = document.getElementById('cancel-logout');
        const confirmButton = document.getElementById('confirm-logout');

        if (!modal || !cancelButton || !confirmButton) return;

        const logoutLinks = document.querySelectorAll(
            'a[href*="logout.php"]'
        );

        let previousFocus = null;

        function openLogoutModal(event) {
            event.preventDefault();
            previousFocus = document.activeElement;
            modal.classList.add('is-open');
            modal.setAttribute('aria-hidden', 'false');
            document.body.style.overflow = 'hidden';
            cancelButton.focus();
        }

        function closeLogoutModal() {
            modal.classList.remove('is-open');
            modal.setAttribute('aria-hidden', 'true');
            document.body.style.overflow = '';

            if (previousFocus) previousFocus.focus();
        }

        logoutLinks.forEach(link => {
            link.addEventListener('click', openLogoutModal);
        });

        cancelButton.addEventListener('click', closeLogoutModal);

        confirmButton.addEventListener('click', () => {
            window.location.href = '../auth/logout.php?portal=admin';
        });

        modal.addEventListener('click', event => {
            if (event.target === modal) closeLogoutModal();
        });

        document.addEventListener('keydown', event => {
            if (event.key === 'Escape' &&
                modal.classList.contains('is-open')) {
                closeLogoutModal();
            }
        });
    })();
    </script>    
  </body>
</html>
