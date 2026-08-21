<?php
require_once __DIR__ . "/../includes/session.php";
if (!isset($_SESSION['role_type']) || $_SESSION['role_type'] !== 'admin') {
    header("Location: ../auth/login.php");
    exit();
}
require_once __DIR__ . "/../config/database.php";

function table_exists($conn, $name) {
    $n = mysqli_real_escape_string($conn, $name);
    $res = mysqli_query($conn, "SHOW TABLES LIKE '$n'");
    return ($res && mysqli_num_rows($res) > 0);
}
function column_exists($conn, $table, $column) {
    $t = mysqli_real_escape_string($conn, $table);
    $c = mysqli_real_escape_string($conn, $column);
    $res = mysqli_query($conn, "SHOW COLUMNS FROM `$t` LIKE '$c'");
    return ($res && mysqli_num_rows($res) > 0);
}
function run_count($conn, $sql) {
    $res = mysqli_query($conn, $sql);
    if (!$res) return 0;
    $row = mysqli_fetch_row($res);
    return isset($row[0]) ? (int)$row[0] : 0;
}

$assessment_candidates = ['assessments','assessment','assessment_data','assessment_results','assessment_submissions','assessment_records','responses','survey_responses','phq9_results','gad7_results'];
$referral_candidates = ['referrals','referral','referral_requests','referral_records'];
$atable = null; foreach ($assessment_candidates as $c) { if (table_exists($conn,$c)) { $atable = $c; break; } }
$rtable = null; foreach ($referral_candidates as $c) { if (table_exists($conn,$c)) { $rtable = $c; break; } }

$created_col = null; foreach (['created_at','submitted_at','timestamp','created','submitted'] as $c) { if ($atable && column_exists($conn,$atable,$c)) { $created_col = $c; break; } }
$status_col = null; foreach (['status','review_status','submission_status'] as $c) { if ($atable && column_exists($conn,$atable,$c)) { $status_col = $c; break; } }
$risk_col = null; foreach (['risk_level','risk','risklevel'] as $c) { if ($atable && column_exists($conn,$atable,$c)) { $risk_col = $c; break; } }
$phq_col = null; foreach (['phq_score','phq9_score','phq_total','phq_total_score'] as $c) { if ($atable && column_exists($conn,$atable,$c)) { $phq_col = $c; break; } }
$gad_col = null; foreach (['gad_score','gad7_score','gad_total','gad_total_score'] as $c) { if ($atable && column_exists($conn,$atable,$c)) { $gad_col = $c; break; } }

$total_assessments = $atable ? run_count($conn, "SELECT COUNT(*) FROM `$atable`") : 0;
$total_referrals = 0;
if ($rtable) {
    $total_referrals = run_count($conn, "SELECT COUNT(*) FROM `$rtable`");
} elseif ($atable && $status_col) {
    $total_referrals = run_count($conn, "SELECT COUNT(*) FROM `$atable` WHERE `$status_col` IN ('Referred','referred')");
}

$high_risk = 0;
if ($atable) {
    if ($risk_col) {
        $high_risk = run_count($conn, "SELECT COUNT(*) FROM `$atable` WHERE `$risk_col` IN ('High','high')");
    } else {
        $clauses = [];
        if ($phq_col) $clauses[] = "`$phq_col` >= 15";
        if ($gad_col) $clauses[] = "`$gad_col` >= 15";
        if ($clauses) {
            $where = implode(' OR ', $clauses);
            $high_risk = run_count($conn, "SELECT COUNT(*) FROM `$atable` WHERE $where");
        }
    }
}

$monthly = [];
if ($atable && $created_col) {
    $sql = "SELECT DATE_FORMAT(`$created_col`, '%Y-%m') AS ym, COUNT(*) AS cnt FROM `$atable` GROUP BY ym ORDER BY ym DESC LIMIT 12";
    $res = mysqli_query($conn, $sql);
    if ($res) while ($r = mysqli_fetch_assoc($res)) $monthly[] = $r;
}

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $type = isset($_GET['type']) ? $_GET['type'] : 'all';
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="reports-' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');

    if ($type === 'monthly') {
        fputcsv($out, ['month', 'count']);
        foreach ($monthly as $m) fputcsv($out, [$m['ym'], $m['cnt']]);
    } elseif ($type === 'highrisk') {
        if ($atable) {
            $sql = "SELECT * FROM `$atable`";
            $where = [];
            if ($risk_col) $where[] = "`$risk_col` IN ('High','high')";
            else {
                if ($phq_col) $where[] = "`$phq_col` >= 15";
                if ($gad_col) $where[] = "`$gad_col` >= 15";
            }
            if ($where) $sql .= ' WHERE ' . implode(' OR ', $where);
            $res = mysqli_query($conn, $sql);
            if ($res) {
                $first = true;
                while ($r = mysqli_fetch_assoc($res)) {
                    if ($first) { fputcsv($out, array_keys($r)); $first = false; }
                    fputcsv($out, array_values($r));
                }
            }
        }
    } elseif ($type === 'referrals') {
        if ($rtable) {
            $res = mysqli_query($conn, "SELECT * FROM `$rtable`");
            if ($res) {
                $first = true;
                while ($r = mysqli_fetch_assoc($res)) {
                    if ($first) { fputcsv($out, array_keys($r)); $first = false; }
                    fputcsv($out, array_values($r));
                }
            }
        } elseif ($atable && $status_col) {
            $res = mysqli_query($conn, "SELECT * FROM `$atable` WHERE `$status_col` IN ('Referred','referred')");
            if ($res) {
                $first = true;
                while ($r = mysqli_fetch_assoc($res)) {
                    if ($first) { fputcsv($out, array_keys($r)); $first = false; }
                    fputcsv($out, array_values($r));
                }
            }
        }
    } else {
        if ($atable) {
            $idcol = null; foreach (['id','assessment_id','submission_id','record_id','entry_id'] as $c) { if (column_exists($conn,$atable,$c)) { $idcol = $c; break; } }
            $sel = [];
            if ($idcol) $sel[] = "`$idcol`";
            if ($created_col) $sel[] = "`$created_col`";
            if ($phq_col) $sel[] = "`$phq_col`";
            if ($gad_col) $sel[] = "`$gad_col`";
            if ($status_col) $sel[] = "`$status_col`";
            $sql = 'SELECT ' . implode(',', $sel) . " FROM `$atable`";
            $res = mysqli_query($conn, $sql);
            if ($res) {
                $first = true;
                while ($r = mysqli_fetch_assoc($res)) {
                    if ($first) { fputcsv($out, array_keys($r)); $first = false; }
                    fputcsv($out, array_values($r));
                }
            }
        }
    }
    fclose($out);
    exit();
}
?>
<!doctype html>
<html>
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Reports</title>
    <style>
      * { box-sizing: border-box; }
      html, body { margin: 0; padding: 0; }
      body {
        font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
        background: #efeeeb;
        color: #1a1a1a;
      }
      a { text-decoration: none; color: inherit; }
      .app-shell { display: flex; min-height: 100vh; }
      .sidebar { width: 260px; background: #5c8a60; color: #fff; padding: 22px 18px 18px; display: flex; flex-direction: column; }
      .brand { font-size: 2rem; font-weight: 500; letter-spacing: -0.05em; padding: 6px 12px 20px; }
      .nav { display: flex; flex-direction: column; gap: 12px; margin-top: 24px; }
      .nav-link { display: flex; align-items: center; gap: 12px; padding: 12px 14px; border-radius: 10px; font-size: 1.05rem; font-weight: 600; color: #f1f5ef; }
      .nav-link.active, .nav-link:hover { background: #edf2ee; color: #1f3d2a; }
      .nav-icon { width: 22px; display: inline-flex; justify-content: center; }
      .logout { margin-top: auto; border-top: 1px solid rgba(255,255,255,0.25); padding-top: 16px; }
      .main-content { flex: 1; padding: 30px 32px 40px; }
      .page-title { font-size: 2.5rem; margin: 0 0 20px; font-weight: 600; letter-spacing: -0.05em; }
      .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 18px; }
      .card { background: rgba(255,255,255,0.2); border: 1px solid #dfe4dc; border-radius: 12px; padding: 18px 20px; }
      .card h3 { margin: 0 0 12px; }
      .metric { font-size: 2rem; font-weight: 800; }
      table { width: 100%; border-collapse: collapse; margin-top: 12px; }
      th, td { border-bottom: 1px solid #dfe4dc; padding: 12px 10px; text-align: left; }
      th { background: rgba(255,255,255,0.15); }
      .muted { color:#666; }
      @media (max-width: 980px) { .app-shell { display: block; } .sidebar { width: 100%; } .main-content { padding: 22px 18px 30px; } }
    </style>
  </head>
  <body>
    <div class="app-shell">
      <aside class="sidebar">
        <div class="brand">PTC Wellness</div>
        <nav class="nav" aria-label="Main navigation">
          <a class="nav-link" href="dashboard.php"><span class="nav-icon">🏠</span><span>Dashboard</span></a>
          <a class="nav-link" href="users.php"><span class="nav-icon">👥</span><span>Users</span></a>
          <a class="nav-link" href="assessments.php"><span class="nav-icon">📝</span><span>Assessments</span></a>
          <a class="nav-link" href="referrals.php"><span class="nav-icon">🔁</span><span>Referrals</span></a>
          <a class="nav-link active" href="reports.php"><span class="nav-icon">📊</span><span>Reports</span></a>
          <a class="nav-link" href="settings.php"><span class="nav-icon">⚙️</span><span>Settings</span></a>
          <a class="nav-link logout" href="../auth/logout.php"><span class="nav-icon">↩️</span><span>Logout</span></a>
        </nav>
      </aside>

      <main class="main-content">
        <h1 class="page-title">Reports</h1>

        <div class="grid">
          <div class="card"><h3>Total Assessments</h3><div class="metric"><?= $total_assessments ?></div></div>
          <div class="card"><h3>High Risk Cases</h3><div class="metric"><?= $high_risk ?></div></div>
          <div class="card"><h3>Referral Count</h3><div class="metric"><?= $total_referrals ?></div></div>
        </div>

        <section class="card" style="margin-top:20px;">
          <h2>Monthly Assessments (last 12 months)</h2>
          <?php if (empty($monthly)): ?>
            <p class="muted">No monthly data available. Ensure your assessments table has a created_at or submitted_at column.</p>
          <?php else: ?>
            <table>
              <thead><tr><th>Month</th><th>Count</th></tr></thead>
              <tbody>
                <?php foreach ($monthly as $m): ?>
                  <tr><td><?= htmlspecialchars($m['ym']) ?></td><td><?= htmlspecialchars($m['cnt']) ?></td></tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
        </section>

        <section class="card" style="margin-top:20px;">
          <h2>Exports</h2>
          <p>
            <a href="reports.php?export=csv&type=monthly">Download monthly CSV</a> |
            <a href="reports.php?export=csv&type=highrisk">Download high-risk CSV</a> |
            <a href="reports.php?export=csv&type=referrals">Download referrals CSV</a> |
            <a href="reports.php?export=csv&type=all">Download assessments CSV</a>
          </p>
        </section>

        <?php if (!$atable): ?>
          <p style="color:#a00; margin-top:20px;">Note: No assessments table was detected automatically. Add a table such as assessments or assessment_results to enable reporting.</p>
        <?php endif; ?>
      </main>
    </div>
  </body>
</html>
