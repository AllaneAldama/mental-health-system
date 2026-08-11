<?php
require_once __DIR__ . "/../includes/session.php"; // ensures user is logged in
// allow only admin
if (!isset($_SESSION['role_type']) || $_SESSION['role_type'] !== 'admin') {
    header("Location: ../auth/login.php");
    exit();
}
require_once __DIR__ . "/../config/database.php"; // $conn

// Helper functions
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

// Detect assessments table
$candidates = ['assessments','assessment','assessment_data','assessment_results','assessment_submissions','assessment_records','responses','survey_responses','phq9_results','gad7_results'];
$atable = null;
foreach ($candidates as $c) { if (table_exists($conn,$c)) { $atable = $c; break; } }

// Build columns mapping (best-effort)
$cols = [
    'id' => 'id',
    'alias' => null,
    'risk' => null,
    'phq' => null,
    'gad' => null,
    'submitted' => null,
    'status' => null,
];
if ($atable) {
    // find likely id column
    foreach (['id','assessment_id','submission_id','record_id','entry_id'] as $c) { if (column_exists($conn,$atable,$c)) { $cols['id']=$c; break; } }
    foreach (['student_alias','alias','user_alias','submitted_by','student_id','user_id'] as $c) { if (column_exists($conn,$atable,$c)) { $cols['alias']=$c; break; } }
    foreach (['risk_level','risk','risklevel'] as $c) { if (column_exists($conn,$atable,$c)) { $cols['risk']=$c; break; } }
    foreach (['phq_score','phq9_score','phq_total','phq_total_score'] as $c) { if (column_exists($conn,$atable,$c)) { $cols['phq']=$c; break; } }
    foreach (['gad_score','gad7_score','gad_total','gad_total_score'] as $c) { if (column_exists($conn,$atable,$c)) { $cols['gad']=$c; break; } }
    foreach (['submitted_at','created_at','timestamp','submitted'] as $c) { if (column_exists($conn,$atable,$c)) { $cols['submitted']=$c; break; } }
    foreach (['status','review_status','submission_status'] as $c) { if (column_exists($conn,$atable,$c)) { $cols['status']=$c; break; } }
}

// Handle search & filter params (server-side filtering)
$search = isset($_GET['q']) ? trim($_GET['q']) : '';
$filter_status = isset($_GET['status']) ? trim($_GET['status']) : '';
$filter_risk = isset($_GET['risk']) ? trim($_GET['risk']) : '';

$rows = [];
if ($atable) {
    // build select list
    $selectCols = [];
    $selectCols[] = "`".mysqli_real_escape_string($conn,$cols['id'])."`";
    if ($cols['alias']) $selectCols[] = "`".mysqli_real_escape_string($conn,$cols['alias'])."`";
    if ($cols['risk']) $selectCols[] = "`".mysqli_real_escape_string($conn,$cols['risk'])."`";
    if ($cols['phq']) $selectCols[] = "`".mysqli_real_escape_string($conn,$cols['phq'])."`";
    if ($cols['gad']) $selectCols[] = "`".mysqli_real_escape_string($conn,$cols['gad'])."`";
    if ($cols['submitted']) $selectCols[] = "`".mysqli_real_escape_string($conn,$cols['submitted'])."`";
    if ($cols['status']) $selectCols[] = "`".mysqli_real_escape_string($conn,$cols['status'])."`";

    $sql = "SELECT " . implode(',', $selectCols) . " FROM `$atable`";
    $where = [];
    if ($search) {
        $s = mysqli_real_escape_string($conn,$search);
        $clauses = [];
        if ($cols['alias']) $clauses[] = "`".mysqli_real_escape_string($conn,$cols['alias'])."` LIKE '%$s%'";
        if ($cols['id']) $clauses[] = "`".mysqli_real_escape_string($conn,$cols['id'])."` LIKE '%$s%'";
        if ($cols['phq']) $clauses[] = "`".mysqli_real_escape_string($conn,$cols['phq'])."` LIKE '%$s%'";
        $where[] = '(' . implode(' OR ', $clauses) . ')';
    }
    if ($filter_status && $cols['status']) {
        $fs = mysqli_real_escape_string($conn,$filter_status);
        $where[] = "`".mysqli_real_escape_string($conn,$cols['status'])."` = '$fs'";
    }
    if ($filter_risk && $cols['risk']) {
        $fr = mysqli_real_escape_string($conn,$filter_risk);
        $where[] = "`".mysqli_real_escape_string($conn,$cols['risk'])."` = '$fr'";
    }
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= ' ORDER BY ' . ($cols['submitted'] ? "`".mysqli_real_escape_string($conn,$cols['submitted'])."` DESC" : "`".mysqli_real_escape_string($conn,$cols['id'])."` DESC");

    $res = run_query($conn,$sql);
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
      body{font-family:Arial,Helvetica,sans-serif;margin:16px}
      nav ul{list-style:none;padding:0;display:flex;gap:12px}
      table{width:100%;border-collapse:collapse;margin-top:12px}
      th,td{border:1px solid #ddd;padding:8px;text-align:left}
      th{background:#f4f4f4}
      form.inline{display:flex;gap:8px;align-items:center}
      .muted{color:#666}
      a.button{display:inline-block;padding:8px 12px;background:#007bff;color:#fff;text-decoration:none;border-radius:4px}
    </style>
  </head>
  <body>
    <nav>
      <ul>
        <li><a href="dashboard.php">Dashboard</a></li>
        <li><a href="users.php">User Management</a></li>
        <li><a href="assessments.php">Assessments</a></li>
        <li><a href="reports.php">Reports</a></li>
        <li><a href="referrals.php">Referrals</a></li>
        <li><a href="settings.php">Settings</a></li>
      </ul>
    </nav>

    <main>
      <header>
        <h1>Assessments</h1>
      </header>

      <?php if (!$atable): ?>
        <p style="color:#a00">No assessments table detected. Please tell me your assessments table name and columns or add one of the common table names: assessments, assessment_results, assessment_submissions.</p>
      <?php else: ?>

      <section>
        <form class="inline" method="get">
          <label for="q">Search</label>
          <input id="q" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="alias, id or score" />

          <label for="status">Status</label>
          <select id="status" name="status">
            <option value="">All</option>
            <option <?= $filter_status==='Pending' ? 'selected' : '' ?>>Pending</option>
            <option <?= $filter_status==='Reviewed' ? 'selected' : '' ?>>Reviewed</option>
            <option <?= $filter_status==='Referred' ? 'selected' : '' ?>>Referred</option>
          </select>

          <label for="risk">Risk Level</label>
          <select id="risk" name="risk">
            <option value="">All</option>
            <option <?= $filter_risk==='High' ? 'selected' : '' ?>>High</option>
            <option <?= $filter_risk==='Medium' ? 'selected' : '' ?>>Medium</option>
            <option <?= $filter_risk==='Low' ? 'selected' : '' ?>>Low</option>
          </select>

          <button type="submit">Apply</button>
        </form>
      </section>

      <section>
        <table>
          <thead>
            <tr>
              <th>Alias / Student</th>
              <th>Risk Level</th>
              <th>PHQ-9</th>
              <th>GAD-7</th>
              <th>Submitted</th>
              <th>Status</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($rows)): ?>
              <tr><td colspan="7" class="muted">No records found.</td></tr>
            <?php else: ?>
              <?php foreach ($rows as $r): ?>
                <tr>
                  <td><?= htmlspecialchars($r[$cols['alias']] ?? $r[$cols['id']] ?? '') ?></td>
                  <td><?= htmlspecialchars($r[$cols['risk']] ?? '') ?></td>
                  <td><?= htmlspecialchars($r[$cols['phq']] ?? '') ?></td>
                  <td><?= htmlspecialchars($r[$cols['gad']] ?? '') ?></td>
                  <td><?= htmlspecialchars($r[$cols['submitted']] ?? '') ?></td>
                  <td><?= htmlspecialchars($r[$cols['status']] ?? '') ?></td>
                  <td><a href="assessment_view.php?id=<?= urlencode($r[$cols['id']]) ?>">View</a></td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </section>

      <?php endif; ?>

    </main>
  </body>
</html>
