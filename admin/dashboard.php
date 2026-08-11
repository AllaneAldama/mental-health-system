<?php
require_once __DIR__ . "/../includes/session.php"; // ensures user is logged in
// allow only admin
if (!isset($_SESSION['role_type']) || $_SESSION['role_type'] !== 'admin') {
    header("Location: ../auth/login.php");
    exit();
}
require_once __DIR__ . "/../config/database.php"; // provides $conn (mysqli)

/** Helper: check whether a table exists */
function table_exists($conn, $name) {
    $name_esc = mysqli_real_escape_string($conn, $name);
    $sql = "SHOW TABLES LIKE '$name_esc'";
    $res = mysqli_query($conn, $sql);
    return ($res && mysqli_num_rows($res) > 0);
}

/** Helper: check whether a column exists on a table */
function column_exists($conn, $table, $column) {
    $t = mysqli_real_escape_string($conn, $table);
    $c = mysqli_real_escape_string($conn, $column);
    $sql = "SHOW COLUMNS FROM `$t` LIKE '$c'";
    $res = mysqli_query($conn, $sql);
    return ($res && mysqli_num_rows($res) > 0);
}

/** Helper: run a count query safely and return int */
function run_count($conn, $sql) {
    $res = mysqli_query($conn, $sql);
    if (!$res) return 0;
    $row = mysqli_fetch_row($res);
    return isset($row[0]) ? (int)$row[0] : 0;
}

// 1) Total students and total counselors (uses user_data table)
$total_students = 0;
$total_counselors = 0;
if (table_exists($conn, 'user_data')) {
    // prefer counting only Active users
    $total_students = run_count($conn, "SELECT COUNT(*) FROM user_data WHERE role_type = 'student' AND status = 'Active'");
    $total_counselors = run_count($conn, "SELECT COUNT(*) FROM user_data WHERE role_type = 'counselor' AND status = 'Active'");
}

// 2) Assessments table detection
$assessment_table_candidates = [
    'assessments', 'assessment', 'assessment_data', 'assessment_results', 'assessment_submissions',
    'assessment_records', 'responses', 'survey_responses', 'phq9_results', 'gad7_results'
];
$assessment_table = null;
foreach ($assessment_table_candidates as $cand) {
    if (table_exists($conn, $cand)) { $assessment_table = $cand; break; }
}

$total_assessments = 0;
$pending_assessments = 0;
$high_risk_cases = 0;

if ($assessment_table) {
    // total assessments
    $total_assessments = run_count($conn, "SELECT COUNT(*) FROM `$assessment_table`");

    // pending assessments: try multiple heuristics
    if (column_exists($conn, $assessment_table, 'status')) {
        $pending_assessments = run_count($conn, "SELECT COUNT(*) FROM `$assessment_table` WHERE status IN ('Pending','pending')");
    } elseif (column_exists($conn, $assessment_table, 'review_status')) {
        $pending_assessments = run_count($conn, "SELECT COUNT(*) FROM `$assessment_table` WHERE review_status IN ('Pending','pending')");
    } elseif (column_exists($conn, $assessment_table, 'is_reviewed')) {
        // assume boolean 0 = not reviewed
        $pending_assessments = run_count($conn, "SELECT COUNT(*) FROM `$assessment_table` WHERE is_reviewed = 0");
    }

    // high risk cases: try risk_level, then score thresholds
    if (column_exists($conn, $assessment_table, 'risk_level')) {
        $high_risk_cases = run_count($conn, "SELECT COUNT(*) FROM `$assessment_table` WHERE risk_level IN ('High','high')");
    } else {
        $phq_columns = ['phq_score','phq9_score','phq_total','phq9_total'];
        $gad_columns = ['gad_score','gad7_score','gad_total','gad7_total'];
        $phq_col = null; $gad_col = null;
        foreach ($phq_columns as $c) { if (column_exists($conn, $assessment_table, $c)) { $phq_col = $c; break; } }
        foreach ($gad_columns as $c) { if (column_exists($conn, $assessment_table, $c)) { $gad_col = $c; break; } }

        if ($phq_col || $gad_col) {
            $clauses = [];
            if ($phq_col) {
                // PHQ-9 high threshold (>= 15)
                $clauses[] = "`$phq_col` >= 15";
            }
            if ($gad_col) {
                // GAD-7 high threshold (>= 15)
                $clauses[] = "`$gad_col` >= 15";
            }
            $where = implode(' OR ', $clauses);
            $high_risk_cases = $where ? run_count($conn, "SELECT COUNT(*) FROM `$assessment_table` WHERE $where") : 0;
        }
    }
}

// If assessments table not found, leave numbers as 0 and present an informational note in the UI
?>
<!doctype html>
<html>
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Admin Dashboard</title>
    <style>
      body{font-family:Arial,Helvetica,sans-serif;margin:16px}
      nav ul{list-style:none;padding:0;display:flex;gap:12px}
      .grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;margin-top:12px}
      .card{border:1px solid #ddd;padding:12px;border-radius:6px;background:#fff}
      .card h3{margin:0 0 8px}
      .muted{color:#666;font-size:0.9em}
      a.button{display:inline-block;padding:8px 12px;background:#007bff;color:#fff;text-decoration:none;border-radius:4px}
    </style>
  </head>
  <body>
    <nav>
      <ul>
        <li><a href="dashboard.php">Dashboard</a></li>
        <li><a href="users.php">Users</a></li>
        <li><a href="assessments.php">Assessments</a></li>
        <li><a href="reports.php">Reports</a></li>
        <li><a href="referrals.php">Referrals</a></li>
        <li><a href="settings.php">Settings</a></li>
      </ul>
    </nav>

    <main>
      <header style="display:flex;justify-content:space-between;align-items:center">
        <h1>Dashboard</h1>
        <div class="muted">Welcome, <?= htmlspecialchars($_SESSION['fullname'] ?? 'Admin') ?></div>
      </header>

      <div class="grid">
        <div class="card">
          <h3>Total Students</h3>
          <div style="font-size:2rem;font-weight:600"><?= $total_students ?></div>
          <div class="muted">Active students in system</div>
        </div>

        <div class="card">
          <h3>Total Counselors</h3>
          <div style="font-size:2rem;font-weight:600"><?= $total_counselors ?></div>
          <div class="muted">Active counselors</div>
        </div>

        <div class="card">
          <h3>Total Assessments</h3>
          <div style="font-size:2rem;font-weight:600"><?= $total_assessments ?></div>
          <div class="muted"><?= $assessment_table ? "From table: $assessment_table" : "Assessments table not detected" ?></div>
        </div>

        <div class="card">
          <h3>Pending Assessments</h3>
          <div style="font-size:2rem;font-weight:600"><?= $pending_assessments ?></div>
          <div class="muted">Awaiting review</div>
        </div>

        <div class="card">
          <h3>High Risk Cases</h3>
          <div style="font-size:2rem;font-weight:600"><?= $high_risk_cases ?></div>
          <div class="muted">PHQ-9 / GAD-7 threshold based</div>
        </div>
      </div>

      <?php if (!$assessment_table): ?>
        <p style="margin-top:16px;color:#a00">Note: No assessments table was detected automatically. If your assessments table uses a different name or schema, update the detection list in <code>admin/dashboard.php</code> or tell me the table/column names and I will adjust the queries.</p>
      <?php endif; ?>

      <section style="margin-top:18px">
        <a class="button" href="users.php">Manage Users</a>
        <a class="button" href="assessments.php" style="margin-left:8px">View Assessments</a>
      </section>

    </main>
  </body>
</html>
