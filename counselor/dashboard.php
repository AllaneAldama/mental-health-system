<?php
require_once __DIR__ . "/../includes/session.php"; // ensures user is logged in
// allow only admin
if (!isset($_SESSION['role_type']) || $_SESSION['role_type'] !== 'counselor') {
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
    // prefer counting only active users
    $total_students = run_count($conn, "SELECT COUNT(*) FROM user_data WHERE role_type = 'student' AND status = 'active'");
    $total_counselors = run_count($conn, "SELECT COUNT(*) FROM user_data WHERE role_type = 'counselor' AND status = 'active'");
}

// 2) Assessments table detection
$assessment_table_candidates = [
    'assessment_data', 'assessments', 'assessment', 'assessment_results', 'assessment_submissions',
    'assessment_records', 'responses', 'survey_responses', 'phq9_results', 'gad7_results'
];
$assessment_table = null;
foreach ($assessment_table_candidates as $cand) {
    if (table_exists($conn, $cand)) { $assessment_table = $cand; break; }
}

$total_assessments = 0;
$pending_assessments = 0;
$high_risk_cases = 0;
$recent_assessments = [];
$monthly_assessments = [];
$risk_distribution = [
    'High' => 0,
    'Moderate' => 0,
    'Low' => 0,
];
$dashboard_data_error = '';

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

    if (
        $assessment_table === 'assessment_data'
        && table_exists($conn, 'user_data')
        && column_exists($conn, $assessment_table, 'assessment_date')
        && column_exists($conn, $assessment_table, 'assessment_id')
        && column_exists($conn, $assessment_table, 'user_id')
        && column_exists($conn, $assessment_table, 'risk_level')
        && column_exists($conn, $assessment_table, 'phq9_score')
        && column_exists($conn, $assessment_table, 'gad7_score')
        && column_exists($conn, $assessment_table, 'status')
        && column_exists($conn, 'user_data', 'fullname')
        && column_exists($conn, 'user_data', 'alias')
    ) {
        $first_month = new DateTimeImmutable('first day of this month');
        $first_month = $first_month->modify('-5 months');
        $month_labels = [];
        for ($i = 0; $i < 6; $i++) {
            $month = $first_month->modify("+$i months");
            $month_key = $month->format('Y-m');
            $month_labels[$month_key] = [
                'label' => $month->format('M'),
                'count' => 0,
            ];
        }

        $range_start = $first_month->format('Y-m-d');
        $range_end = (new DateTimeImmutable('first day of next month'))->format('Y-m-d');
        $trend_sql = "SELECT DATE_FORMAT(assessment_date, '%Y-%m') AS month_key, COUNT(*) AS assessment_count
                      FROM `$assessment_table`
                      WHERE assessment_date >= '$range_start' AND assessment_date < '$range_end'
                      GROUP BY DATE_FORMAT(assessment_date, '%Y-%m')";
        $trend_result = mysqli_query($conn, $trend_sql);
        if ($trend_result) {
            while ($trend_row = mysqli_fetch_assoc($trend_result)) {
                if (isset($month_labels[$trend_row['month_key']])) {
                    $month_labels[$trend_row['month_key']]['count'] = (int)$trend_row['assessment_count'];
                }
            }
            $monthly_assessments = array_values($month_labels);
        } else {
            $dashboard_data_error = 'Unable to load the assessment trend: ' . mysqli_error($conn);
        }

        $risk_sql = "SELECT
                         SUM(CASE WHEN LOWER(TRIM(risk_level)) = 'high' THEN 1 ELSE 0 END) AS high_count,
                         SUM(CASE WHEN LOWER(TRIM(risk_level)) = 'moderate' THEN 1 ELSE 0 END) AS moderate_count,
                         SUM(CASE WHEN LOWER(TRIM(risk_level)) = 'low' THEN 1 ELSE 0 END) AS low_count
                     FROM `$assessment_table`";
        $risk_result = mysqli_query($conn, $risk_sql);
        if ($risk_result && ($risk_row = mysqli_fetch_assoc($risk_result))) {
            $risk_distribution = [
                'High' => (int)$risk_row['high_count'],
                'Moderate' => (int)$risk_row['moderate_count'],
                'Low' => (int)$risk_row['low_count'],
            ];
        } elseif (!$risk_result) {
            $dashboard_data_error = 'Unable to load risk distribution: ' . mysqli_error($conn);
        }

        $recent_sql = "SELECT a.assessment_id, a.phq9_score, a.gad7_score, a.risk_level, a.assessment_date, a.status,
                              u.fullname, u.alias
                       FROM `$assessment_table` a
                       LEFT JOIN user_data u ON u.user_id = a.user_id
                       ORDER BY a.assessment_date DESC, a.assessment_id DESC
                       LIMIT 8";
        $recent_result = mysqli_query($conn, $recent_sql);
        if ($recent_result) {
            while ($recent_row = mysqli_fetch_assoc($recent_result)) {
                $recent_assessments[] = $recent_row;
            }
        } else {
            $dashboard_data_error = 'Unable to load recent assessments: ' . mysqli_error($conn);
        }
    } else {
        $dashboard_data_error = 'Assessment charts require the assessment_data and user_data fields from the configured schema.';
    }
}

$risk_total = array_sum($risk_distribution);
$risk_colors = [
    'High' => '#d9695d',
    'Moderate' => '#e2bf61',
    'Low' => '#7ba889',
];
$risk_stops = [];
$risk_offset = 0;
foreach ($risk_distribution as $risk_name => $risk_count) {
    $slice = $risk_total > 0 ? ($risk_count / $risk_total) * 100 : 0;
    $risk_stops[] = $risk_colors[$risk_name] . ' ' . $risk_offset . '% ' . ($risk_offset + $slice) . '%';
    $risk_offset += $slice;
}
$risk_donut_background = $risk_total > 0
    ? 'conic-gradient(' . implode(', ', $risk_stops) . ')'
    : '#dfe4dc';

$trend_max = 0;
foreach ($monthly_assessments as $month_row) {
    $trend_max = max($trend_max, (int)$month_row['count']);
}
$trend_scale_max = max(1, (int)(ceil($trend_max / 5) * 5));
$trend_points = [];
$trend_x_positions = [];
$trend_count = count($monthly_assessments);
foreach ($monthly_assessments as $index => $month_row) {
    $x = $trend_count > 1 ? 48 + ($index * (392 / ($trend_count - 1))) : 244;
    $y = 150 - (((int)$month_row['count'] / $trend_scale_max) * 120);
    $trend_points[] = round($x, 1) . ',' . round($y, 1);
    $trend_x_positions[] = round($x, 1);
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
        background: #dfe4dc;
      }
      .donut::after {
        content: "";
        position: absolute;
        inset: 32px;
        background: #efeeeb;
        border-radius: 50%;
        border: 1px solid #dfe4dc;
      }
      .donut-total {
        position: absolute;
        inset: 0;
        z-index: 1;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        font-size: 1.55rem;
        font-weight: 800;
      }
      .donut-total small {
        color: #6a6a6a;
        font-size: 0.72rem;
        font-weight: 600;
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
      .chart-empty {
        width: 100%;
        color: #777;
        text-align: center;
        font-size: 0.9rem;
      }
      .risk-empty {
        width: 100%;
        color: #777;
        text-align: center;
        font-size: 0.85rem;
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
          <a class="nav-link" href="assessment_queue.php"><span class="nav-icon">📋</span><span>Assessments Queue</span></a>
          <a class="nav-link" href="monitoring.php"><span class="nav-icon">👁️</span><span>Monitoring</span></a>
          <a class="nav-link" href="referral.php"><span class="nav-icon">📤</span><span>Referral</span></a>
          <a class="nav-link" href="assessment_history.php"><span class="nav-icon">📚</span><span>Assessment History</span></a>
          <a class="nav-link" href="profile.php"><span class="nav-icon">👤</span><span>Profile</span></a>
          <a class="nav-link logout" href="../auth/logout.php"><span class="nav-icon">↩️</span><span>Logout</span></a>
        </nav>
      </aside>

      <main class="main-content">

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
            <div class="chart-wrap">
              <?php if (!empty($monthly_assessments)): ?>
                <svg width="100%" height="220" viewBox="0 0 460 210" preserveAspectRatio="none" role="img" aria-label="Assessment counts per month for the last six months">
                  <g fill="none" stroke="#d5ddd4" stroke-width="1">
                    <?php for ($grid_line = 0; $grid_line <= 4; $grid_line++): ?>
                      <?php $grid_y = 150 - ($grid_line * 30); ?>
                      <line x1="42" y1="<?php echo $grid_y; ?>" x2="448" y2="<?php echo $grid_y; ?>" />
                    <?php endfor; ?>
                  </g>
                  <g fill="#6a6a6a" font-size="10" font-family="Segoe UI, sans-serif" text-anchor="end">
                    <?php for ($grid_line = 0; $grid_line <= 4; $grid_line++): ?>
                      <?php $grid_value = (int)round(($trend_scale_max / 4) * $grid_line); ?>
                      <?php $grid_y = 153 - ($grid_line * 30); ?>
                      <text x="35" y="<?php echo $grid_y; ?>"><?php echo $grid_value; ?></text>
                    <?php endfor; ?>
                  </g>
                  <polyline
                    points="<?php echo htmlspecialchars(implode(' ', $trend_points), ENT_QUOTES, 'UTF-8'); ?>"
                    fill="none"
                    stroke="#5c8a60"
                    stroke-width="3"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                  />
                  <?php foreach ($monthly_assessments as $index => $month_row): ?>
                    <?php
                      $point_x = $trend_x_positions[$index];
                      $point_y = 150 - (((int)$month_row['count'] / $trend_scale_max) * 120);
                    ?>
                    <circle cx="<?php echo $point_x; ?>" cy="<?php echo round($point_y, 1); ?>" r="4" fill="#5c8a60">
                      <title><?php echo htmlspecialchars($month_row['label'] . ': ' . $month_row['count'] . ' assessments', ENT_QUOTES, 'UTF-8'); ?></title>
                    </circle>
                    <text x="<?php echo $point_x; ?>" y="177" fill="#3d3d3d" font-size="11" font-family="Segoe UI, sans-serif" text-anchor="middle">
                      <?php echo htmlspecialchars($month_row['label'], ENT_QUOTES, 'UTF-8'); ?>
                    </text>
                  <?php endforeach; ?>
                </svg>
              <?php else: ?>
                <p class="chart-empty">Assessment trend data is unavailable.</p>
              <?php endif; ?>
            </div>
          </article>

          <article class="panel">
            <h2 class="panel-title">Risk Level Distribution</h2>
            <div class="donut-wrap">
              <div class="donut" role="img" aria-label="Risk level distribution: <?php echo $risk_total; ?> assessments" style="background: <?php echo htmlspecialchars($risk_donut_background, ENT_QUOTES, 'UTF-8'); ?>">
                <div class="donut-total"><?php echo $risk_total; ?><small>assessments</small></div>
              </div>
              <div class="legend">
                <?php foreach ($risk_distribution as $risk_name => $risk_count): ?>
                  <?php $risk_percentage = $risk_total > 0 ? round(($risk_count / $risk_total) * 100) : 0; ?>
                  <div class="legend-row">
                    <span class="legend-label">
                      <span class="legend-dot" style="background:<?php echo $risk_colors[$risk_name]; ?>"></span>
                      <?php echo htmlspecialchars($risk_name, ENT_QUOTES, 'UTF-8'); ?>
                    </span>
                    <span><?php echo $risk_count; ?> (<?php echo $risk_percentage; ?>%)</span>
                  </div>
                <?php endforeach; ?>
                <?php if ($risk_total === 0): ?>
                  <p class="risk-empty">No risk level data available yet.</p>
                <?php endif; ?>
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
                <?php if (!empty($recent_assessments)): ?>
                  <?php foreach ($recent_assessments as $recent_assessment): ?>
                    <tr>
                      <td>
                        <a href="view_assessment.php?id=<?php echo (int)$recent_assessment['assessment_id']; ?>">
                          <?php echo htmlspecialchars($recent_assessment['alias'] ?: ($recent_assessment['fullname'] ?: 'Student'), ENT_QUOTES, 'UTF-8'); ?>
                        </a>
                      </td>
                      <td><?php echo htmlspecialchars($recent_assessment['risk_level'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?></td>
                      <td><?php echo (int)$recent_assessment['phq9_score']; ?></td>
                      <td><?php echo (int)$recent_assessment['gad7_score']; ?></td>
                      <td><?php echo !empty($recent_assessment['assessment_date']) ? htmlspecialchars(date('M d, Y g:i A', strtotime($recent_assessment['assessment_date'])), ENT_QUOTES, 'UTF-8') : 'N/A'; ?></td>
                      <td><?php echo htmlspecialchars($recent_assessment['status'] ?? 'Pending', ENT_QUOTES, 'UTF-8'); ?></td>
                    </tr>
                  <?php endforeach; ?>
                <?php else: ?>
                  <tr>
                    <td colspan="6" style="text-align:center; color:#777;">No assessment records found.</td>
                  </tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </section>

        <?php if (!$assessment_table): ?>
          <div class="alert-note">No assessments table was detected automatically. Check the assessment table configuration.</div>
        <?php elseif ($dashboard_data_error !== ''): ?>
          <div class="alert-note"><?php echo htmlspecialchars($dashboard_data_error, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>
      </main>
    </div>
  </body>
</html>
