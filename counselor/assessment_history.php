<?php
require_once __DIR__ . "/../includes/session.php";
if (!isset($_SESSION['role_type']) || $_SESSION['role_type'] !== 'counselor') {
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

function safe_string($value) {
    return isset($value) ? trim((string)$value) : '';
}

function status_badge($status) {
    $s = strtolower(trim((string)$status));
    if ($s === 'completed' || $s === 'reviewed' || $s === 'accepted') {
        return 'success';
    }
    if ($s === 'pending' || $s === 'in review' || $s === 'follow-up') {
        return 'warning';
    }
    if ($s === 'high' || $s === 'high risk' || $s === 'critical') {
        return 'danger';
    }
    return 'secondary';
}

$assessment_table_candidates = [
    'assessment_data', 'assessments', 'assessment', 'assessment_results', 'assessment_submissions',
    'assessment_records', 'responses', 'survey_responses', 'phq9_results', 'gad7_results'
];
$assessment_table = null;
foreach ($assessment_table_candidates as $cand) {
    if (table_exists($conn, $cand)) { $assessment_table = $cand; break; }
}

$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$filter = isset($_GET['filter']) ? $_GET['filter'] : 'all';

$history_rows = [];
$stats = [
    'total' => 0,
    'completed' => 0,
    'reviewed' => 0,
    'high_risk' => 0,
];

if ($assessment_table) {
    $sql = "SELECT a.assessment_id, a.user_id, a.phq9_score, a.gad7_score, a.risk_level, a.assessment_date, a.status,
                   u.fullname AS student_name, u.email AS student_email
            FROM `$assessment_table` a
            LEFT JOIN user_data u ON u.user_id = a.user_id";

    $cond = [];
    if ($search !== '') {
        $search_esc = mysqli_real_escape_string($conn, $search);
        $cond[] = "(u.fullname LIKE '%$search_esc%' OR u.email LIKE '%$search_esc%' OR u.student_number LIKE '%$search_esc%' OR a.assessment_id LIKE '%$search_esc%')";
    }

    if ($filter !== 'all') {
        if ($filter === 'completed') {
            $cond[] = "a.status IN ('Reviewed', 'Monitoring', 'Referred')";
        } elseif ($filter === 'reviewed') {
            $cond[] = "a.status IN ('Reviewed')";
        } elseif ($filter === 'high-risk') {
            $cond[] = "a.risk_level IN ('High')";
        }
    }

    if (!empty($cond)) {
        $sql .= " WHERE " . implode(' AND ', $cond);
    }

    $sql .= " ORDER BY a.assessment_date DESC, a.assessment_id DESC";

    $res = mysqli_query($conn, $sql);
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $history_rows[] = $row;
        }
    }

    $stats['total'] = count($history_rows);

    foreach ($history_rows as $row) {
        $status = safe_string($row['status'] ?? 'Reviewed');
        if (strtolower($status) === 'reviewed' || strtolower($status) === 'monitoring' || strtolower($status) === 'referred') {
            $stats['completed']++;
        }
        if (strtolower($status) === 'reviewed') {
            $stats['reviewed']++;
        }

        $risk = safe_string($row['risk_level'] ?? '');
        if (strtolower($risk) === 'high') {
            $stats['high_risk']++;
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Assessment History - Counselor Dashboard</title>
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
        .nav { display: flex; flex-direction: column; gap: 12px; margin-top: 24px; }
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
        .nav-link:hover, .nav-link.active {
            background: #edf2ee;
            color: #1f3d2a;
        }
        .logout {
            margin-top: auto;
            border-top: 1px solid rgba(255,255,255,0.25);
            padding-top: 16px;
        }
        .main-content { flex: 1; padding: 30px 32px 40px; }
        .page-header { margin-bottom: 20px; }
        .page-title {
            margin: 0;
            font-size: 2.7rem;
            font-weight: 600;
            letter-spacing: -0.06em;
        }
        .page-subtitle {
            margin: 8px 0 0;
            font-size: 1.15rem;
            color: #4c4c4c;
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(170px, 1fr));
            gap: 18px;
            margin-bottom: 24px;
        }
        .stat-card {
            background: rgba(255,255,255,0.28);
            border: 1px solid #dfe4dc;
            border-radius: 12px;
            padding: 18px;
            min-height: 120px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }
        .stat-label {
            font-size: 0.95rem;
            color: #2e2e2e;
            font-weight: 700;
        }
        .stat-value {
            font-size: 2.1rem;
            font-weight: 800;
            line-height: 1;
            letter-spacing: -0.05em;
        }
        .panel {
            background: rgba(255,255,255,0.28);
            border: 1px solid #dfe4dc;
            border-radius: 12px;
            padding: 18px;
            margin-bottom: 24px;
        }
        .panel-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            margin-bottom: 16px;
            flex-wrap: wrap;
        }
        .panel-title {
            margin: 0;
            font-size: 1.2rem;
            font-weight: 800;
        }
        .filters {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            align-items: center;
        }
        .filters input, .filters select, .btn-search {
            padding: 10px 12px;
            border-radius: 8px;
            border: 1px solid #ccd4ce;
            font: inherit;
        }
        .btn-search {
            background: #5c8a60;
            color: white;
            border: none;
            font-weight: 700;
            cursor: pointer;
        }
        .table-wrap { overflow-x: auto; }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th, td {
            padding: 12px 10px;
            text-align: left;
            border-bottom: 1px solid #dfe4dc;
            vertical-align: top;
        }
        th {
            background: #edf3ee;
            font-weight: 800;
            color: #1f3d2a;
        }
        .badge {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 999px;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }
        .badge-success { background: #dfeee2; color: #2e7d32; }
        .badge-warning { background: #fff3cd; color: #9a6b00; }
        .badge-danger { background: #f8d7da; color: #b02a37; }
        .badge-secondary { background: #e2e3e5; color: #383d41; }
        .action-link {
            display: inline-block;
            padding: 8px 12px;
            border-radius: 8px;
            background: #e8efe9;
            color: #1f3d2a;
            font-weight: 700;
        }
        @media (max-width: 900px) {
            .app-shell { flex-direction: column; }
            .sidebar { width: 100%; min-height: auto; }
            .main-content { padding: 20px; }
            .stats-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <div class="app-shell">
      <aside class="sidebar">
        <div class="brand">PTC Wellness</div>
        <nav class="nav" aria-label="Main navigation">
          <a class="nav-link" href="dashboard.php"><span class="nav-icon">🏠</span><span>Dashboard</span></a>
          <a class="nav-link" href="assessment_queue.php"><span class="nav-icon">📋</span><span>Assessments Queue</span></a>
          <a class="nav-link" href="monitoring.php"><span class="nav-icon">👁️</span><span>Monitoring</span></a>
          <a class="nav-link" href="referral.php"><span class="nav-icon">📤</span><span>Referral</span></a>
          <a class="nav-link active" href="assessment_history.php"><span class="nav-icon">📚</span><span>Assessment History</span></a>
          <a class="nav-link" href="profile.php"><span class="nav-icon">👤</span><span>Profile</span></a>
          <a class="nav-link logout" href="../auth/logout.php"><span class="nav-icon">↩️</span><span>Logout</span></a>
        </nav>
      </aside>

        <main class="main-content">
            <header class="page-header">
                <h1 class="page-title">Assessment History</h1>
                <p class="page-subtitle">Review completed and previously assessed student cases.</p>
            </header>

            <section class="stats-grid">
                <div class="stat-card">
                    <div class="stat-label">Total Records</div>
                    <div class="stat-value"><?php echo (int)$stats['total']; ?></div>
                    <div class="stat-note">All assessments</div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Completed</div>
                    <div class="stat-value"><?php echo (int)$stats['completed']; ?></div>
                    <div class="stat-note">Completed cases</div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Reviewed</div>
                    <div class="stat-value"><?php echo (int)$stats['reviewed']; ?></div>
                    <div class="stat-note">Reviewed by counselor</div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">High Risk</div>
                    <div class="stat-value"><?php echo (int)$stats['high_risk']; ?></div>
                    <div class="stat-note">Escalated cases</div>
                </div>
            </section>

            <section class="panel">
                <div class="panel-header">
                    <h2 class="panel-title">History Search</h2>
                    <form class="filters" method="GET">
                        <input type="text" name="search" value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Search student or ID">
                        <select name="filter">
                            <option value="all" <?php echo $filter === 'all' ? 'selected' : ''; ?>>All</option>
                            <option value="completed" <?php echo $filter === 'completed' ? 'selected' : ''; ?>>Completed</option>
                            <option value="reviewed" <?php echo $filter === 'reviewed' ? 'selected' : ''; ?>>Reviewed</option>
                            <option value="high-risk" <?php echo $filter === 'high-risk' ? 'selected' : ''; ?>>High Risk</option>
                        </select>
                        <button type="submit" class="btn-search">Search</button>
                    </form>
                </div>

                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Student</th>
                                <th>Assessment ID</th>
                                <th>Date Submitted</th>
                                <th>Risk Level</th>
                                <th>PHQ-9</th>
                                <th>GAD-7</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($history_rows)): ?>
                                <tr>
                                    <td colspan="8" style="padding: 24px; text-align: center; color: #666;">No assessment history found for the current filter.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($history_rows as $row): ?>
                                    <?php
                                        $student_name = safe_string($row['student_name'] ?? 'Unknown Student');
                                        if ($student_name === '') {
                                            $student_name = 'Student #' . safe_string($row['student_id'] ?? 'Unknown');
                                        }
                                        $student_email = safe_string($row['student_email'] ?? 'N/A');
                                        $assessment_id = $row['id'] ?? 0;
                                        $submitted_at = safe_string($row['submitted_at'] ?? ($row['created_at'] ?? 'N/A'));
                                        $risk = safe_string($row['risk_level'] ?? 'Not set');
                                        $phq = $row['phq_score'] ?? ($row['phq9_score'] ?? ($row['phq_total'] ?? 'N/A'));
                                        $gad = $row['gad_score'] ?? ($row['gad7_score'] ?? ($row['gad_total'] ?? 'N/A'));
                                        $status = safe_string($row['status'] ?? ($row['review_status'] ?? ($row['assessment_status'] ?? 'Completed')));
                                    ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo htmlspecialchars($student_name, ENT_QUOTES, 'UTF-8'); ?></strong><br>
                                            <small><?php echo htmlspecialchars($student_email, ENT_QUOTES, 'UTF-8'); ?></small>
                                        </td>
                                        <td>#<?php echo htmlspecialchars((string)$assessment_id, ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td><?php echo htmlspecialchars($submitted_at !== '' ? $submitted_at : 'N/A', ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td>
                                            <span class="badge badge-<?php echo status_badge($risk); ?>"><?php echo htmlspecialchars($risk !== '' ? $risk : 'Unknown', ENT_QUOTES, 'UTF-8'); ?></span>
                                        </td>
                                        <td><?php echo htmlspecialchars((string)$phq, ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td><?php echo htmlspecialchars((string)$gad, ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td>
                                            <span class="badge badge-<?php echo status_badge($status); ?>"><?php echo htmlspecialchars($status !== '' ? $status : 'Completed', ENT_QUOTES, 'UTF-8'); ?></span>
                                        </td>
                                        <td><a class="action-link" href="view_assessment.php?id=<?php echo (int)$assessment_id; ?>">View</a></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </main>
    </div>
</body>
</html>
