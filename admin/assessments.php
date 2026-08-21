<?php
require_once __DIR__ . "/../includes/session.php";
if (!isset($_SESSION['role_type']) || $_SESSION['role_type'] !== 'admin') {
    header("Location: ../auth/login.php");
    exit();
}
require_once __DIR__ . "/../config/database.php";

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
function run_query($conn, $sql) {
    $res = mysqli_query($conn, $sql);
    return $res ?: false;
}

$candidates = ['assessments','assessment','assessment_data','assessment_results','assessment_submissions','assessment_records','responses','survey_responses','phq9_results','gad7_results'];
$atable = null;
foreach ($candidates as $c) { if (table_exists($conn, $c)) { $atable = $c; break; } }

$cols = ['id' => 'id', 'alias' => null, 'risk' => null, 'phq' => null, 'gad' => null, 'submitted' => null, 'status' => null];
if ($atable) {
    foreach (['id','assessment_id','submission_id','record_id','entry_id'] as $c) { if (column_exists($conn,$atable,$c)) { $cols['id']=$c; break; } }
    foreach (['student_alias','alias','user_alias','submitted_by','student_id','user_id'] as $c) { if (column_exists($conn,$atable,$c)) { $cols['alias']=$c; break; } }
    foreach (['risk_level','risk','risklevel'] as $c) { if (column_exists($conn,$atable,$c)) { $cols['risk']=$c; break; } }
    foreach (['phq_score','phq9_score','phq_total','phq_total_score'] as $c) { if (column_exists($conn,$atable,$c)) { $cols['phq']=$c; break; } }
    foreach (['gad_score','gad7_score','gad_total','gad_total_score'] as $c) { if (column_exists($conn,$atable,$c)) { $cols['gad']=$c; break; } }
    foreach (['submitted_at','created_at','timestamp','submitted'] as $c) { if (column_exists($conn,$atable,$c)) { $cols['submitted']=$c; break; } }
    foreach (['status','review_status','submission_status'] as $c) { if (column_exists($conn,$atable,$c)) { $cols['status']=$c; break; } }
}

$search = isset($_GET['q']) ? trim($_GET['q']) : '';
$filter_status = isset($_GET['status']) ? trim($_GET['status']) : '';
$filter_risk = isset($_GET['risk']) ? trim($_GET['risk']) : '';
$rows = [];
if ($atable) {
    $selectCols = [];
    $selectCols[] = "`" . mysqli_real_escape_string($conn, $cols['id']) . "`";
    if ($cols['alias']) $selectCols[] = "`" . mysqli_real_escape_string($conn, $cols['alias']) . "`";
    if ($cols['risk']) $selectCols[] = "`" . mysqli_real_escape_string($conn, $cols['risk']) . "`";
    if ($cols['phq']) $selectCols[] = "`" . mysqli_real_escape_string($conn, $cols['phq']) . "`";
    if ($cols['gad']) $selectCols[] = "`" . mysqli_real_escape_string($conn, $cols['gad']) . "`";
    if ($cols['submitted']) $selectCols[] = "`" . mysqli_real_escape_string($conn, $cols['submitted']) . "`";
    if ($cols['status']) $selectCols[] = "`" . mysqli_real_escape_string($conn, $cols['status']) . "`";

    $sql = "SELECT " . implode(',', $selectCols) . " FROM `$atable`";
    $where = [];
    if ($search) {
        $s = mysqli_real_escape_string($conn, $search);
        $clauses = [];
        if ($cols['alias']) $clauses[] = "`" . mysqli_real_escape_string($conn, $cols['alias']) . "` LIKE '%$s%'";
        if ($cols['id']) $clauses[] = "`" . mysqli_real_escape_string($conn, $cols['id']) . "` LIKE '%$s%'";
        if ($cols['phq']) $clauses[] = "`" . mysqli_real_escape_string($conn, $cols['phq']) . "` LIKE '%$s%'";
        $where[] = '(' . implode(' OR ', $clauses) . ')';
    }
    if ($filter_status && $cols['status']) {
        $fs = mysqli_real_escape_string($conn, $filter_status);
        $where[] = "`" . mysqli_real_escape_string($conn, $cols['status']) . "` = '$fs'";
    }
    if ($filter_risk && $cols['risk']) {
        $fr = mysqli_real_escape_string($conn, $filter_risk);
        $where[] = "`" . mysqli_real_escape_string($conn, $cols['risk']) . "` = '$fr'";
    }
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= ' ORDER BY ' . ($cols['submitted'] ? "`" . mysqli_real_escape_string($conn, $cols['submitted']) . "` DESC" : "`" . mysqli_real_escape_string($conn, $cols['id']) . "` DESC");

    $res = run_query($conn, $sql);
    if ($res) {
        while ($r = mysqli_fetch_assoc($res)) $rows[] = $r;
    }
}
?>
<!doctype html>
<html>
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Assessments</title>
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
      .page-header { display:flex; align-items:center; justify-content:space-between; margin-bottom: 18px; }
      .page-title { font-size: 2.5rem; margin: 0; font-weight: 600; letter-spacing: -0.05em; }
      .panel { background: rgba(255,255,255,0.2); border: 1px solid #dfe4dc; border-radius: 12px; padding: 18px 20px; }
      .filter-row { display:flex; gap:10px; flex-wrap:wrap; align-items:center; }
      .filter-row label { font-weight:600; }
      .filter-row input, .filter-row select, .filter-row button { padding:8px 10px; border-radius:8px; border:1px solid #cfd7d1; }
      button { cursor: pointer; }
      table { width:100%; border-collapse:collapse; margin-top:12px; }
      th, td { border-bottom:1px solid #dfe4dc; padding:12px 10px; text-align:left; }
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
          <a class="nav-link active" href="assessments.php"><span class="nav-icon">📝</span><span>Assessments</span></a>
          <a class="nav-link" href="referrals.php"><span class="nav-icon">🔁</span><span>Referrals</span></a>
          <a class="nav-link" href="reports.php"><span class="nav-icon">📊</span><span>Reports</span></a>
          <a class="nav-link" href="settings.php"><span class="nav-icon">⚙️</span><span>Settings</span></a>
          <a class="nav-link logout" href="../auth/logout.php"><span class="nav-icon">↩️</span><span>Logout</span></a>
        </nav>
      </aside>

      <main class="main-content">
        <header class="page-header">
          <h1 class="page-title">Assessments</h1>
        </header>

        <?php if (!$atable): ?>
          <p style="color:#a00">No assessments table detected. Please create one of the common table names: assessments, assessment_results, assessment_submissions.</p>
        <?php else: ?>
          <section class="panel">
            <form class="filter-row" method="get">
              <label for="q">Search</label>
              <input id="q" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="alias, id or score" />

              <label for="status">Status</label>
              <select id="status" name="status">
                <option value="">All</option>
                <option value="Pending" <?= $filter_status === 'Pending' ? 'selected' : '' ?>>Pending</option>
                <option value="Reviewed" <?= $filter_status === 'Reviewed' ? 'selected' : '' ?>>Reviewed</option>
                <option value="Referred" <?= $filter_status === 'Referred' ? 'selected' : '' ?>>Referred</option>
              </select>

              <label for="risk">Risk Level</label>
              <select id="risk" name="risk">
                <option value="">All</option>
                <option value="High" <?= $filter_risk === 'High' ? 'selected' : '' ?>>High</option>
                <option value="Medium" <?= $filter_risk === 'Medium' ? 'selected' : '' ?>>Medium</option>
                <option value="Low" <?= $filter_risk === 'Low' ? 'selected' : '' ?>>Low</option>
              </select>

              <button type="submit">Apply</button>
            </form>
          </section>

          <section class="panel" style="margin-top:20px;">
            <table>
              <thead>
                <tr>
                  <th>Alias / Student</th>
                  <th>Risk Level</th>
                  <th>PHQ-9</th>
                  <th>GAD-7</th>
                  <th>Status</th>
                  <th>Submitted</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($rows)): ?>
                  <tr><td colspan="6" class="muted">No assessments found.</td></tr>
                <?php else: ?>
                  <?php foreach ($rows as $r): ?>
                    <tr>
                      <td><?= htmlspecialchars($r[$cols['alias']] ?? ($r['student_alias'] ?? $r['alias'] ?? $r['student_id'] ?? $r[$cols['id']] ?? '')) ?></td>
                      <td><?= htmlspecialchars($r[$cols['risk']] ?? ($r['risk_level'] ?? $r['risk'] ?? '')) ?></td>
                      <td><?= htmlspecialchars($r[$cols['phq']] ?? ($r['phq_score'] ?? $r['phq9_score'] ?? '')) ?></td>
                      <td><?= htmlspecialchars($r[$cols['gad']] ?? ($r['gad_score'] ?? $r['gad7_score'] ?? '')) ?></td>
                      <td><?= htmlspecialchars($r[$cols['status']] ?? ($r['status'] ?? $r['review_status'] ?? '')) ?></td>
                      <td><?= htmlspecialchars($r[$cols['submitted']] ?? ($r['submitted_at'] ?? $r['created_at'] ?? '')) ?></td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </section>
        <?php endif; ?>
      </main>
    </div>
  </body>
</html>
