<?php
require_once __DIR__ . '/../includes/counselor_session.php';
require_once __DIR__ . '/../config/database.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role_type'] ?? '') !== 'counselor') {
    header('Location: ../auth/login.php');
    exit();
}

/* The original dashboard remains database-driven; only read-only queries are added for
   the four status cards, the 30-day trend and recent activity. */
function counselor_escape($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function dashboard_count($conn, $sql) {
    $result = mysqli_query($conn, $sql);
    if (!$result) { error_log('Dashboard count query: ' . mysqli_error($conn)); return 0; }
    $row = mysqli_fetch_row($result);
    return (int)($row[0] ?? 0);
}
function dashboard_has_table($conn, $table) {
    $escaped = mysqli_real_escape_string($conn, $table);
    $result = mysqli_query($conn, "SHOW TABLES LIKE '{$escaped}'");
    return $result && mysqli_num_rows($result) > 0;
}
function dashboard_has_column($conn, $table, $column) {
    $escaped = mysqli_real_escape_string($conn, $column);
    $result = mysqli_query($conn, "SHOW COLUMNS FROM `{$table}` LIKE '{$escaped}'");
    return $result && mysqli_num_rows($result) > 0;
}

$total_students = $total_counselors = $total_assessments = 0;
$pending_assessments = $reviewed_assessments = $monitoring_assessments = $referred_assessments = 0;
$active_referrals = 0;
$recent_assessments = $recent_activities = [];
$dashboard_notice = '';
$has_users = dashboard_has_table($conn, 'user_data');
$has_assessments = dashboard_has_table($conn, 'assessment_data');
$has_referrals = dashboard_has_table($conn, 'referral_data');

if ($has_users) {
    $activeFilter = dashboard_has_column($conn, 'user_data', 'status') ? " AND status = 'active'" : '';
    $total_students = dashboard_count($conn, "SELECT COUNT(*) FROM student_profiles");
    $total_counselors = dashboard_count($conn, "SELECT COUNT(*) FROM user_data WHERE role_type='counselor'{$activeFilter}");
}
if ($has_assessments) {
    $total_assessments = dashboard_count($conn, "SELECT COUNT(*) FROM assessment_data WHERE assessment_type='Needs Assessment'");
    if (dashboard_has_column($conn, 'assessment_data', 'status')) {
        $pending_assessments = dashboard_count($conn, "SELECT COUNT(*) FROM assessment_data WHERE assessment_type='Needs Assessment' AND LOWER(TRIM(status))='pending'");
        $reviewed_assessments = dashboard_count($conn, "SELECT COUNT(*) FROM assessment_data WHERE assessment_type='Needs Assessment' AND LOWER(TRIM(status))='reviewed'");
        $monitoring_assessments = dashboard_count($conn, "SELECT COUNT(*) FROM assessment_data WHERE assessment_type='Needs Assessment' AND LOWER(TRIM(status))='monitoring'");
        $referred_assessments = dashboard_count($conn, "SELECT COUNT(*) FROM assessment_data WHERE assessment_type='Needs Assessment' AND LOWER(TRIM(status))='referred'");
    }
    $sql = "SELECT a.assessment_id, a.assessment_date AS submitted_date,
                a.status AS review_status, a.reviewed_at AS review_date,
                COALESCE(NULLIF(u.alias,''),u.fullname,'Student') AS student_name
            FROM assessment_data a
            LEFT JOIN student_profiles u ON u.student_id=a.student_id
            WHERE a.assessment_type='Needs Assessment'
            ORDER BY a.assessment_date DESC, a.assessment_id DESC LIMIT 8";
    $res = mysqli_query($conn, $sql);
    if ($res) { while ($r=mysqli_fetch_assoc($res)) $recent_assessments[]=$r; }
    else { $dashboard_notice='Could not load recent assessment records.'; error_log('Dashboard recent: ' . mysqli_error($conn)); }
} else { $dashboard_notice='No assessment_data table was found.'; }
if ($has_referrals) {
    $active_referrals = dashboard_count($conn, "SELECT COUNT(*) FROM referral_data WHERE referral_status IN ('Pending','Accepted')");
}
$workflow_total = $pending_assessments + $reviewed_assessments;
$reviewed_percentage = $workflow_total > 0 ? ($reviewed_assessments / $workflow_total) * 100 : 0;
$counselor_name = $_SESSION['fullname'] ?? $_SESSION['alias'] ?? 'Counselor';

/* Actual submission counts, grouped into five successive six-day periods (30 days total). */
$trend_labels = []; $trend_values = [];
$trend_data = [];
if ($has_assessments) {
    $q = mysqli_query($conn, "SELECT DATE(assessment_date) AS submitted_day, COUNT(*) AS total
            FROM assessment_data WHERE assessment_type='Needs Assessment' AND assessment_date >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
            AND assessment_date < DATE_ADD(CURDATE(), INTERVAL 1 DAY)
            GROUP BY DATE(assessment_date)");
    if ($q) { while ($row = mysqli_fetch_assoc($q)) $trend_data[$row['submitted_day']] = (int)$row['total']; }
}
$today = new DateTimeImmutable('today', new DateTimeZone('Asia/Manila'));
for ($bucket=0; $bucket<5; $bucket++) {
    $start = $today->modify('-' . (29 - $bucket*6) . ' days');
    $count=0;
    for($day=0;$day<6;$day++) $count += $trend_data[$start->modify("+{$day} days")->format('Y-m-d')] ?? 0;
    $trend_labels[]=$start->format('M j');
    $trend_values[]=$count;
}
$trend_max=max(1,max($trend_values));
$trend_points=[];
for ($i=0;$i<5;$i++) {
    $x=35+$i*105;
    $y=153-(int)round(($trend_values[$i]/$trend_max)*120);
    $trend_points[]=[$x,$y];
}
$svg_points=implode(' ',array_map(fn($p)=>$p[0].','.$p[1],$trend_points));
$svg_fill='35,153 '.$svg_points.' 455,153';

if (dashboard_has_table($conn, 'system_monitor_events')) {
    $q=mysqli_query($conn, "SELECT event_type, created_at, details FROM system_monitor_events
            WHERE event_type IN ('referral_created','user_updated','user_status_changed')
            ORDER BY event_id DESC LIMIT 4");
    if ($q) { while ($row=mysqli_fetch_assoc($q)) $recent_activities[]=$row; }
}
function dashboard_activity_label($type) {
    return match ($type) {
        'referral_created'=>'Referral created',
        'user_updated'=>'Account updated',
        'user_status_changed'=>'Account status updated',
        default=>'System activity'
    };
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Counselor Dashboard | PTC Wellness</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/css/counselor/counselor-base.css">
<link rel="stylesheet" href="../assets/css/counselor/counselor-layout.css?v=<?= (int) (@filemtime(__DIR__ . "/../assets/css/counselor/counselor-layout.css") ?: 12) ?>">
<link rel="stylesheet" href="../assets/css/counselor/dashboard.css?v=<?= (int) (@filemtime(__DIR__ . '/../assets/css/counselor/dashboard.css') ?: 9) ?>">
<link rel="stylesheet" href="../assets/css/counselor/dashboard-mobile.css?v=<?= (int) (@filemtime(__DIR__ . '/../assets/css/counselor/dashboard-mobile.css') ?: 9) ?>" media="(max-width: 900px)">
</head>
<body class="counselor-page dashboard-page">
<?php include __DIR__ . '/../includes/counselor/sidebar.php'; ?>
<div class="counselor-workspace">
<?php include __DIR__ . '/../includes/counselor/topbar.php'; ?>
<main class="main-content">

<header class="page-header dashboard-hero">
  <div class="page-heading">
    <p class="eyebrow">PTC WELLNESS · GUIDANCE OFFICE</p>
    <h1 class="page-title">Dashboard</h1>
    <p class="page-subtitle">Welcome back, <?= counselor_escape($counselor_name) ?>. Overview of assessments, cases, and referrals.</p>
  </div>
  <div class="header-right">
    <div class="datetime-card">
      <div class="datetime-item"><span class="datetime-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/></svg></span><span id="current-date">Loading date...</span></div>
      <span class="datetime-divider"></span>
      <div class="datetime-item"><span class="datetime-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg></span><span id="current-time">Loading time...</span></div>
    </div>
  </div>
</header>

<section class="metric-grid" aria-label="Live assessment summary">
  <article class="metric-card metric-pending"><div class="metric-top"><div class="metric-label">Pending Assessments</div><span class="metric-symbol" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="5" y="4" width="14" height="17" rx="2"/><path d="M9 4.5h6M8 10h8M8 14h8"/></svg></span></div><div class="metric-value"><?= number_format($pending_assessments) ?></div><div class="metric-hint">Awaiting counselor review</div></article>
  <article class="metric-card metric-monitoring"><div class="metric-top"><div class="metric-label">Under Monitoring</div><span class="metric-symbol" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M2 12h4l3-7 5 14 3-7h5"/></svg></span></div><div class="metric-value"><?= number_format($monitoring_assessments) ?></div><div class="metric-hint">Current monitoring status</div></article>
  <article class="metric-card metric-referrals"><div class="metric-top"><div class="metric-label">Active Referrals</div><span class="metric-symbol" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M7 17 17 7M7 7h10v10M5 5v14h14"/></svg></span></div><div class="metric-value"><?= number_format($active_referrals) ?></div><div class="metric-hint">Pending or accepted referrals</div></article>
  <article class="metric-card metric-total"><div class="metric-top"><div class="metric-label">Total Assessments</div><span class="metric-symbol" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 13 9 18 20 6"/></svg></span></div><div class="metric-value"><?= number_format($total_assessments) ?></div><div class="metric-hint">All submitted assessments</div></article>
</section>

<div class="dashboard-analysis-grid">
<section class="system-panel trend-panel" aria-label="Assessment submissions in the last 30 days">
 <div class="section-heading"><div><h2>Assessment Trend</h2><p class="section-description">Assessments submitted in the last 30 days.</p></div><span class="section-heading-icon" aria-hidden="true">▥</span></div>
 <div class="trend-chart">
  <svg role="img" aria-label="Five periods of six days. Submission counts: <?= counselor_escape(implode(', ', $trend_values)) ?>" viewBox="0 0 490 205" preserveAspectRatio="xMidYMid meet">
   <defs><linearGradient id="trendFill" x1="0" x2="0" y1="0" y2="1"><stop offset="0" stop-color="#65bd7d" stop-opacity=".30"/><stop offset="1" stop-color="#65bd7d" stop-opacity=".02"/></linearGradient></defs>
   <?php foreach ([33,73,113,153] as $y): ?><line x1="35" y1="<?= $y ?>" x2="455" y2="<?= $y ?>" class="trend-grid-line"/><?php endforeach; ?>
   <polygon points="<?= counselor_escape($svg_fill) ?>" fill="url(#trendFill)"/>
   <polyline points="<?= counselor_escape($svg_points) ?>" class="trend-line"/>
   <?php foreach ($trend_points as $i=>$p): ?><circle cx="<?= $p[0] ?>" cy="<?= $p[1] ?>" r="5" class="trend-point"><title><?= counselor_escape($trend_labels[$i]) ?>: <?= (int)$trend_values[$i] ?> submissions</title></circle><?php endforeach; ?>
   <?php foreach ($trend_labels as $i=>$label): ?><text x="<?= 35+$i*105 ?>" y="187" class="trend-tick" text-anchor="middle"><?= counselor_escape($label) ?></text><?php endforeach; ?>
  </svg>
 </div>
 <p class="trend-chart-note">Live data from assessment submissions · grouped into five 6-day periods</p>
</section>
<section class="system-panel workflow-panel">
 <div class="section-heading"><div><h2>Assessment Workflow</h2><p class="section-description">Status of submitted assessments and next steps.</p></div></div>
 <div class="workflow-status-list">
  <a href="assessment_queue.php" class="workflow-status-row status-pending"><span class="workflow-status-icon" aria-hidden="true">▤</span><span><strong>Pending Review</strong><small>Newly submitted assessments for evaluation</small></span><b><?= number_format($pending_assessments) ?></b></a>
  <a href="assessment_history.php" class="workflow-status-row status-reviewed"><span class="workflow-status-icon" aria-hidden="true">✓</span><span><strong>Reviewed</strong><small>Assessments marked as reviewed</small></span><b><?= number_format($reviewed_assessments) ?></b></a>
  <a href="monitoring.php" class="workflow-status-row status-monitoring"><span class="workflow-status-icon" aria-hidden="true">⌁</span><span><strong>Under Monitoring</strong><small>Cases under monitoring</small></span><b><?= number_format($monitoring_assessments) ?></b></a>
  <a href="referral.php" class="workflow-status-row status-referred"><span class="workflow-status-icon" aria-hidden="true">↗</span><span><strong>Referred</strong><small>Assessments marked as referred</small></span><b><?= number_format($referred_assessments) ?></b></a>
 </div>
 <p class="workflow-privacy-caption">Status indicates workflow progress—not a clinical diagnosis.</p>
</section>
</div>

<div class="dashboard-detail-grid">
<section class="system-panel recent-panel">
 <div class="section-heading"><div><h2>Recent Assessments</h2><p class="section-description">Latest submissions in the system.</p></div><a class="section-link" href="assessment_history.php">View All →</a></div>
 <div class="events-table-wrap"><table class="events-table"><thead><tr><th>Student</th><th>Assessment ID</th><th>Date</th><th>Status</th><th>Action</th></tr></thead><tbody>
 <?php if ($recent_assessments): ?><?php foreach ($recent_assessments as $item):
  $student_name=trim((string)($item['student_name']??'')); if ($student_name==='') $student_name='Student';
  $aid=(int)$item['assessment_id']; $status=trim((string)($item['review_status']??'Pending'));
  $date=$item['submitted_date'] ? date('M d, Y',strtotime($item['submitted_date'])):'—';
  $status_key=strtolower($status);
  $reviewDate=$status_key==='reviewed' && !empty($item['review_date']) ? date('M d, Y',strtotime($item['review_date'])) : '';
 ?>
 <tr><td><div class="dashboard-student"><span class="dashboard-student-avatar" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg></span><span class="dashboard-student-details"><span class="dashboard-student-name"><?= counselor_escape($student_name) ?></span><span class="dashboard-student-id">Assessment #<?= $aid ?></span></span></div></td><td>#<?= $aid ?></td><td><?= counselor_escape($date) ?><?php if ($reviewDate): ?><small class="assessment-reviewed-date">Reviewed: <?= counselor_escape($reviewDate) ?></small><?php endif; ?></td><td><span class="review-badge review-badge-<?= in_array($status_key,['pending','reviewed','monitoring','referred'],true)?$status_key:'pending' ?>"><?= counselor_escape($status) ?></span></td><td><a class="table-action" href="view_assessment.php?id=<?= $aid ?>">View →</a></td></tr>
 <?php endforeach; ?><?php else: ?><tr><td colspan="5" class="empty-state">No assessment records yet.</td></tr><?php endif; ?>
 </tbody></table></div>
</section>
<section class="system-panel activity-panel">
 <div class="section-heading"><div><h2>Recent Activities</h2><p class="section-description">Latest recorded counselor system events.</p></div></div>
 <div class="activity-list">
 <?php if ($recent_activities): ?><?php foreach ($recent_activities as $activity): ?>
 <div class="activity-row"><span class="activity-icon" aria-hidden="true">◷</span><span><strong><?= counselor_escape(dashboard_activity_label($activity['event_type'])) ?></strong><small><?= counselor_escape(date('M d, Y · g:i A',strtotime($activity['created_at']))) ?></small></span></div>
 <?php endforeach; ?><?php else: ?><p class="activity-empty">No recent recorded activities.</p><?php endif; ?>
 </div>
</section>
</div>

<section class="system-panel quick-panel">
 <div class="section-heading"><div><h2>Quick Actions</h2><p class="section-description">Open common counselor tools.</p></div></div>
 <div class="monitoring-grid">
  <a class="monitoring-card action-card" href="assessment_queue.php"><span class="monitoring-symbol" aria-hidden="true">▤</span><span class="monitoring-copy"><h3>Review Queue</h3><p>Open submitted assessments</p></span><span class="action-arrow" aria-hidden="true">→</span></a>
  <a class="monitoring-card action-card" href="monitoring.php"><span class="monitoring-symbol" aria-hidden="true">⌁</span><span class="monitoring-copy"><h3>View Monitoring</h3><p>Follow up active cases</p></span><span class="action-arrow" aria-hidden="true">→</span></a>
  <a class="monitoring-card action-card" href="referral.php"><span class="monitoring-symbol" aria-hidden="true">↗</span><span class="monitoring-copy"><h3>Manage Referrals</h3><p>Access referral records</p></span><span class="action-arrow" aria-hidden="true">→</span></a>
  <a class="monitoring-card action-card" href="assessment_history.php"><span class="monitoring-symbol" aria-hidden="true">◷</span><span class="monitoring-copy"><h3>Assessment History</h3><p>View previous submissions</p></span><span class="action-arrow" aria-hidden="true">→</span></a>
 </div>
</section>
<?php if ($dashboard_notice): ?><div class="dashboard-notice" role="status"><?= counselor_escape($dashboard_notice) ?></div><?php endif; ?>
</main>
<?php include __DIR__ . '/../includes/counselor/footer.php'; ?>
</div>

<div class="logout-modal" id="logout-modal" aria-hidden="true"><section class="logout-dialog" role="dialog" aria-modal="true" aria-labelledby="logout-title" aria-describedby="logout-description"><div class="logout-modal-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M10 17l5-5-5-5M15 12H3M12 3h7a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-7"/></svg></div><h2 id="logout-title">Are you sure you want to log out?</h2><p id="logout-description">You may lose any unsaved changes. Please make sure you've saved your work before leaving.</p><div class="logout-actions"><button type="button" class="logout-confirm" id="confirm-logout">Yes, Log Out</button><button type="button" class="logout-cancel" id="cancel-logout">Go Back</button></div></section></div>
<script>
(function(){
  const dateEl=document.getElementById('current-date'), timeEl=document.getElementById('current-time');
  if(!dateEl||!timeEl)return;
  const dateFmt=new Intl.DateTimeFormat('en-PH',{timeZone:'Asia/Manila',weekday:'long',month:'short',day:'numeric',year:'numeric'});
  const timeFmt=new Intl.DateTimeFormat('en-PH',{timeZone:'Asia/Manila',hour:'2-digit',minute:'2-digit',hour12:true});
  function update(){const d=new Date();dateEl.textContent=dateFmt.format(d);timeEl.textContent=timeFmt.format(d);}
  update();window.setInterval(update,1000);
})();
(function(){
  const modal=document.getElementById('logout-modal'), cancel=document.getElementById('cancel-logout'),confirm=document.getElementById('confirm-logout'),link=document.querySelector('.counselor-logout-link');
  if(!modal||!cancel||!confirm||!link)return;
  let previous=null;
  function close(){modal.classList.remove('is-open');modal.setAttribute('aria-hidden','true');document.body.style.overflow='';if(previous)previous.focus();}
  link.addEventListener('click',e=>{e.preventDefault();previous=document.activeElement;modal.classList.add('is-open');modal.setAttribute('aria-hidden','false');document.body.style.overflow='hidden';cancel.focus();});
  cancel.addEventListener('click',close);
  confirm.addEventListener('click',()=>{window.location.href='../auth/logout.php?portal=counselor';});
  modal.addEventListener('click',e=>{if(e.target===modal)close();});
  document.addEventListener('keydown',e=>{if(e.key==='Escape'&&modal.classList.contains('is-open'))close();});
})();
(function(){
  const revisionUrl='dashboard_live_data.php';let lastRevision=null,checking=false;
  async function getRevision(){const response=await fetch(revisionUrl+'?_='+Date.now(),{method:'GET',cache:'no-store',credentials:'same-origin',headers:{'Accept':'application/json'}});if(!response.ok)throw new Error('Dashboard revision check failed');const data=await response.json();if(!data||data.success!==true||typeof data.revision!=='string')throw new Error('Invalid dashboard revision response');return data.revision;}
  async function refresh(){const refreshUrl=window.location.pathname+window.location.search+(window.location.search?'&':'?')+'_dashboard_refresh='+Date.now();const response=await fetch(refreshUrl,{method:'GET',cache:'no-store',credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest'}});if(!response.ok)throw new Error('Dashboard refresh failed');const parsed=new DOMParser().parseFromString(await response.text(),'text/html');for(const selector of ['.metric-grid','.dashboard-analysis-grid','.dashboard-detail-grid','.quick-panel','.dashboard-notice']){const current=document.querySelector(selector),updated=parsed.querySelector(selector);if(current&&updated)current.replaceWith(updated);else if(!current&&updated){const main=document.querySelector('.main-content');const footer=main?.querySelector('.page-footer');if(main){if(footer)footer.before(updated);else main.appendChild(updated);}}else if(current&&!updated)current.remove();}}
  async function poll(){if(checking||document.visibilityState==='hidden')return;checking=true;try{const revision=await getRevision();if(lastRevision===null)lastRevision=revision;else if(revision!==lastRevision){await refresh();lastRevision=revision;}}catch(err){console.error('Dashboard synchronization:',err);}finally{checking=false;}}
  poll();window.setInterval(poll,5000);document.addEventListener('visibilitychange',()=>{if(document.visibilityState==='visible')poll();});
})();
</script>
<script src="../assets/js/counselor/counselor-layout.js" defer></script>
</body></html>
