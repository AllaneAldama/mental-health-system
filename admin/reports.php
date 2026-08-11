<?php
require_once __DIR__ . "/../includes/session.php";
if (!isset($_SESSION['role_type']) || $_SESSION['role_type'] !== 'admin') {
    header("Location: ../auth/login.php");
    exit();
}
require_once __DIR__ . "/../config/database.php"; // $conn

// Helpers
function table_exists($conn, $name) {
    $name_esc = mysqli_real_escape_string($conn, $name);
    $sql = "SHOW TABLES LIKE '$name_esc'";
    $res = mysqli_query($conn, $sql);
    return ($res && mysqli_num_rows($res) > 0);
}
function column_exists($conn, $table, $column) {
    $t = mysqli_real_escape_string($conn, $table);
    $c = mysqli_real_escape_string($conn, $column);
    $sql = "SHOW COLUMNS FROM `$t` LIKE '$c'";
    $res = mysqli_query($conn, $sql);
    return ($res && mysqli_num_rows($res) > 0);
}
function run_count($conn, $sql) {
    $res = mysqli_query($conn, $sql);
    if (!$res) return 0;
    $row = mysqli_fetch_row($res);
    return isset($row[0]) ? (int)$row[0] : 0;
}

// Detect tables and important columns
$assessment_candidates = ['assessments','assessment','assessment_data','assessment_results','assessment_submissions','assessment_records','responses','survey_responses','phq9_results','gad7_results'];
$referral_candidates = ['referrals','referral','referral_requests','referral_records'];
$atable = null; foreach ($assessment_candidates as $c) { if (table_exists($conn,$c)){ $atable=$c; break; } }
$rtable = null; foreach ($referral_candidates as $c) { if (table_exists($conn,$c)){ $rtable=$c; break; } }

// Find created/submitted column for assessments
$created_col = null; foreach (['created_at','submitted_at','timestamp','created','submitted'] as $c) { if ($atable && column_exists($conn,$atable,$c)) { $created_col=$c; break; } }
$status_col = null; foreach (['status','review_status','submission_status'] as $c) { if ($atable && column_exists($conn,$atable,$c)) { $status_col=$c; break; } }
$risk_col = null; foreach (['risk_level','risk','risklevel'] as $c) { if ($atable && column_exists($conn,$atable,$c)) { $risk_col=$c; break; } }
$phq_col = null; foreach (['phq_score','phq9_score','phq_total','phq_total_score'] as $c) { if ($atable && column_exists($conn,$atable,$c)) { $phq_col=$c; break; } }
$gad_col = null; foreach (['gad_score','gad7_score','gad_total','gad_total_score'] as $c) { if ($atable && column_exists($conn,$atable,$c)) { $gad_col=$c; break; } }

// Totals
$total_assessments = $atable ? run_count($conn, "SELECT COUNT(*) FROM `$atable`") : 0;
$total_referrals = 0;
if ($rtable) {
    $total_referrals = run_count($conn, "SELECT COUNT(*) FROM `$rtable`");
} else {
    // fallback: count assessments with status='Referred'
    if ($atable && $status_col) {
        $total_referrals = run_count($conn, "SELECT COUNT(*) FROM `$atable` WHERE `$status_col` IN ('Referred','referred')");
    }
}

// High risk count
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

// Monthly counts (last 12 months)
$monthly = [];
if ($atable && $created_col) {
    $sql = "SELECT DATE_FORMAT(`$created_col`, '%Y-%m') AS ym, COUNT(*) AS cnt FROM `$atable` GROUP BY ym ORDER BY ym DESC LIMIT 12";
    $res = mysqli_query($conn,$sql);
    if ($res) { while ($r = mysqli_fetch_assoc($res)) $monthly[] = $r; }
}

// CSV export handling
if (isset($_GET['export']) && $_GET['export']==='csv') {
    $type = isset($_GET['type']) ? $_GET['type'] : 'all';
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="reports-' . date('Ymd') . '.csv"');
    $out = fopen('php://output','w');
    if ($type === 'monthly') {
        fputcsv($out, ['month','count']);
        foreach ($monthly as $m) fputcsv($out, [$m['ym'],$m['cnt']]);
    } elseif ($type === 'highrisk') {
        // export high risk rows
        if ($atable) {
            $sql = "SELECT * FROM `$atable`";
            $where = [];
            if ($risk_col) $where[] = "`$risk_col` IN ('High','high')";
            else {
                if ($phq_col) $where[] = "`$phq_col` >= 15";
                if ($gad_col) $where[] = "`$gad_col` >= 15";
            }
            if ($where) $sql .= ' WHERE ' . implode(' OR ',$where);
            $res = mysqli_query($conn,$sql);
            if ($res) {
                $first = true;
                while ($r = mysqli_fetch_assoc($res)) {
                    if ($first) { fputcsv($out,array_keys($r)); $first=false; }
                    fputcsv($out,array_values($r));
                }
            }
        }
    } elseif ($type === 'referrals') {
        if ($rtable) {
            $res = mysqli_query($conn, "SELECT * FROM `$rtable`");
            if ($res) { $first=true; while($r=mysqli_fetch_assoc($res)) { if ($first) { fputcsv($out,array_keys($r)); $first=false; } fputcsv($out,array_values($r)); } }
        } else {
            // fallback: assessments with status Referred
            if ($atable && $status_col) {
                $res = mysqli_query($conn, "SELECT * FROM `$atable` WHERE `$status_col` IN ('Referred','referred')");
                if ($res) { $first=true; while($r=mysqli_fetch_assoc($res)) { if ($first) { fputcsv($out,array_keys($r)); $first=false; } fputcsv($out,array_values($r)); } }
            }
        }
    } else {
        // default: full assessments list (selected columns for brevity)
        if ($atable) {
            $cols = ['id'=>$phq_col?:null];
            // attempt id col
            $idcol = null; foreach (['id','assessment_id','submission_id','record_id','entry_id'] as $c) { if (column_exists($conn,$atable,$c)){ $idcol=$c; break; } }
            $sel = [];
            if ($idcol) $sel[] = "`$idcol`";
            if ($created_col) $sel[] = "`$created_col`";
            if ($phq_col) $sel[] = "`$phq_col`";
            if ($gad_col) $sel[] = "`$gad_col`";
            if ($status_col) $sel[] = "`$status_col`";
            $sql = 'SELECT ' . implode(',', $sel) . " FROM `$atable`";
            $res = mysqli_query($conn,$sql);
            if ($res) { $first=true; while($r=mysqli_fetch_assoc($res)) { if ($first) { fputcsv($out,array_keys($r)); $first=false; } fputcsv($out,array_values($r)); } }
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
    <style>body{font-family:Arial,Helvetica,sans-serif;margin:16px}.card{border:1px solid #ddd;padding:12px;border-radius:6px;margin-bottom:12px}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px}</style>
  </head>
  <body>
    <nav><ul style="list-style:none;display:flex;gap:12px;padding:0"><li><a href="dashboard.php">Dashboard</a></li><li><a href="users.php">Users</a></li><li><a href="assessments.php">Assessments</a></li><li><a href="reports.php">Reports</a></li></ul></nav>
    <main>
      <h1>Reports</h1>

      <div class="grid">
        <div class="card"><h3>Total Assessments</h3><div style="font-size:1.8rem;font-weight:600"><?= $total_assessments ?></div></div>
        <div class="card"><h3>High Risk Cases</h3><div style="font-size:1.8rem;font-weight:600"><?= $high_risk ?></div></div>
        <div class="card"><h3>Referral Count</h3><div style="font-size:1.8rem;font-weight:600"><?= $total_referrals ?></div></div>
      </div>

      <section style="margin-top:18px">
        <h2>Monthly Assessments (last 12 months)</h2>
        <?php if (empty($monthly)): ?>
          <p class="muted">No monthly data available. Ensure your assessments table has a created_at / submitted_at column.</p>
        <?php else: ?>
          <table style="width:100%;border-collapse:collapse"><thead><tr><th>Month</th><th>Count</th></tr></thead><tbody>
            <?php foreach ($monthly as $m): ?>
              <tr><td><?= htmlspecialchars($m['ym']) ?></td><td><?= htmlspecialchars($m['cnt']) ?></td></tr>
            <?php endforeach; ?>
          </tbody></table>
        <?php endif; ?>
      </section>

      <section style="margin-top:18px">
        <h2>Exports</h2>
        <p>
          <a href="reports.php?export=csv&type=monthly">Download monthly CSV</a> |
          <a href="reports.php?export=csv&type=highrisk">Download high-risk CSV</a> |
          <a href="reports.php?export=csv&type=referrals">Download referrals CSV</a> |
          <a href="reports.php?export=csv&type=all">Download assessments CSV</a>
        </p>
      </section>

      <?php if (!$atable): ?>
        <p style="color:#a00">Note: No assessments table was detected automatically. Provide the table/columns or create a table with one of the common names to enable these reports.</p>
      <?php endif; ?>

    </main>
  </body>
</html>
