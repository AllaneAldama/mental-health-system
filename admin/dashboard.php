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
        grid-template-columns: repeat(5, minmax(160px, 1fr));
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
      .metric-note {
        color: #6a6a6a;
        font-size: 0.82rem;
      }
      .panel-grid {
        display: grid;
        grid-template-columns: 1.7fr 1fr;
        gap: 18px;
      }
      .panel {
        background: rgba(255,255,255,0.2);
        border: 1px solid #dfe4dc;
        border-radius: 12px;
        padding: 18px 18px 12px;
      }
      .panel-title {
        margin: 0 0 14px;
        font-size: 1.15rem;
        font-weight: 800;
      }
      .chart-wrap {
        width: 100%;
        height: 220px;
        display: flex;
        align-items: center;
        justify-content: center;
      }
      .donut-wrap {
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 14px;
      }
      .donut {
        position: relative;
        width: 164px;
        height: 164px;
        border-radius: 50%;
        background: conic-gradient(#d9695d 0 30%, #e2bf61 30% 80%, #7ba889 80% 100%);
      }
      .donut::after {
        content: "";
        position: absolute;
        inset: 32px;
        background: #efeeeb;
        border-radius: 50%;
        border: 1px solid #dfe4dc;
      }
      .legend {
        display: flex;
        flex-direction: column;
        gap: 8px;
        width: 100%;
        margin-top: 10px;
      }
      .legend-row {
        display: flex;
        align-items: center;
        justify-content: space-between;
        font-size: 0.82rem;
        color: #4a4a4a;
      }
      .legend-label {
        display: flex;
        align-items: center;
        gap: 8px;
      }
      .legend-dot {
        width: 10px;
        height: 10px;
        border-radius: 50%;
      }
      .list-panel {
        margin-top: 22px;
        background: rgba(255,255,255,0.2);
        border: 1px solid #dfe4dc;
        border-radius: 12px;
        padding: 18px 20px 10px;
      }
      .table-wrap {
        overflow-x: auto;
      }
      table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 8px;
      }
      th, td {
        text-align: left;
        padding: 12px 10px;
        border-bottom: 1px solid #dfe4dc;
      }
      th {
        font-size: 0.95rem;
        color: #363636;
        font-weight: 700;
      }
      td {
        font-size: 0.95rem;
        color: #303030;
      }
      .alert-note {
        margin-top: 16px;
        padding: 8px 12px;
        background: #fff7e8;
        border: 1px solid #f0d8a4;
        border-radius: 10px;
        color: #7a5402;
      }
      @media (max-width: 980px) {
        .app-shell { display: block; }
        .sidebar { width: 100%; }
        .main-content { padding: 22px 18px 30px; }
        .metric-grid { grid-template-columns: repeat(2, minmax(160px,1fr)); }
        .panel-grid { grid-template-columns: 1fr; }
      }
      @media (max-width: 560px) {
        .metric-grid { grid-template-columns: 1fr; }
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
          <a class="nav-link" href="assessments.php"><span class="nav-icon">📝</span><span>Assessments</span></a>
          <a class="nav-link" href="referrals.php"><span class="nav-icon">🔁</span><span>Referrals</span></a>
          <a class="nav-link" href="reports.php"><span class="nav-icon">📊</span><span>Reports</span></a>
          <a class="nav-link" href="settings.php"><span class="nav-icon">⚙️</span><span>Settings</span></a>
          <a class="nav-link logout" href="../auth/logout.php"><span class="nav-icon">↩️</span><span>Logout</span></a>
        </nav>
      </aside>

      <main class="main-content">
        <header class="page-header">
          <h1 class="page-title">Dashboard Overview</h1>
        </header>

        <p class="page-subtitle">Welcome back, <?= htmlspecialchars($_SESSION['fullname'] ?? 'Counselor') ?>!</p>

        <section class="metric-grid">
          <article class="metric-card">
            <div class="metric-label">Total Students</div>
            <div class="metric-value"><?= $total_students ?></div>
          </article>
          <article class="metric-card">
            <div class="metric-label">Total Assessments</div>
            <div class="metric-value"><?= $total_assessments ?></div>
          </article>
          <article class="metric-card">
            <div class="metric-label">Pending Assessments</div>
            <div class="metric-value"><?= $pending_assessments ?></div>
          </article>
          <article class="metric-card">
            <div class="metric-label">High Risk Cases</div>
            <div class="metric-value"><?= $high_risk_cases ?></div>
          </article>
          <article class="metric-card">
            <div class="metric-label">Total Counselors</div>
            <div class="metric-value"><?= $total_counselors ?></div>
          </article>
        </section>

        <section class="panel-grid">
          <article class="panel">
            <h2 class="panel-title">Assessment Overview</h2>
            <div class="chart-wrap" aria-label="Assessment overview chart">
              <svg width="100%" height="200" viewBox="0 0 460 200" preserveAspectRatio="none" role="img">
                <g fill="none" stroke="#a9b6a9" stroke-width="1">
                  <line x1="20" y1="150" x2="440" y2="150" />
                  <line x1="20" y1="120" x2="440" y2="120" />
                  <line x1="20" y1="90" x2="440" y2="90" />
                  <line x1="20" y1="60" x2="440" y2="60" />
                  <line x1="20" y1="30" x2="440" y2="30" />
                </g>
                <g fill="none" stroke="#5c8a60" stroke-width="3">
                  <path d="M20 150 C90 120, 120 90, 160 80 S240 60, 260 70 S310 140, 340 102 S390 75, 440 80" />
                </g>
                <g fill="#5c8a60">
                  <circle cx="160" cy="80" r="4" />
                  <circle cx="260" cy="70" r="4" />
                  <circle cx="340" cy="102" r="4" />
                </g>
                <g fill="#3d3d3d" font-size="11" font-family="Segoe UI, sans-serif">
                  <text x="30" y="170">May</text>
                  <text x="120" y="170">June</text>
                  <text x="210" y="170">July</text>
                  <text x="300" y="170">August</text>
                  <text x="390" y="170">September</text>
                </g>
              </svg>
            </div>
          </article>

          <article class="panel">
            <h2 class="panel-title">Risk Level Distribution</h2>
            <div class="donut-wrap">
              <div class="donut" aria-label="Risk distribution donut chart"></div>
              <div class="legend">
                <div class="legend-row">
                  <span class="legend-label"><span class="legend-dot" style="background:#d9695d"></span>High</span>
                  <span>30%</span>
                </div>
                <div class="legend-row">
                  <span class="legend-label"><span class="legend-dot" style="background:#e2bf61"></span>Moderate</span>
                  <span>50%</span>
                </div>
                <div class="legend-row">
                  <span class="legend-label"><span class="legend-dot" style="background:#7ba889"></span>Low</span>
                  <span>20%</span>
                </div>
              </div>
            </div>
          </article>
        </section>

        <section class="list-panel">
          <h2 class="panel-title">Recent Assessments</h2>
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Student Alias</th>
                  <th>Risk Level</th>
                  <th>PHQ-9</th>
                  <th>GAD-7</th>
                  <th>Submitted At</th>
                  <th>Status</th>
                </tr>
              </thead>
              <tbody>
                <tr>
                  <td>Batosai</td>
                  <td>High</td>
                  <td>18</td>
                  <td>13</td>
                  <td>May 10, 2026 10:00 AM</td>
                  <td>Pending</td>
                </tr>
                <tr>
                  <td>Marie</td>
                  <td>Moderate</td>
                  <td>11</td>
                  <td>9</td>
                  <td>May 9, 2026 2:30 PM</td>
                  <td>Reviewed</td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>

        <?php if (!$assessment_table): ?>
          <div class="alert-note">No assessments table was detected automatically. If your assessments table uses a different name or schema, update the detection list in <code>admin/dashboard.php</code> or tell me the table/column names and I will adjust the queries.</div>
        <?php endif; ?>
      </main>
    </div>
  </body>
</html>
