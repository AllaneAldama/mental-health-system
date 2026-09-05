<?php
require_once __DIR__ . "/../includes/session.php";
// Allow only counselor
if (!isset($_SESSION['role_type']) || $_SESSION['role_type'] !== 'counselor') {
    header("Location: ../auth/login.php");
    exit();
}
require_once __DIR__ . "/../config/database.php";

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

/** Helper: run a query safely */
function run_query($conn, $sql) {
    $res = mysqli_query($conn, $sql);
    return $res ?: false;
}

// Detect assessment table and use the actual schema columns from assessment_data
$assessment_table_candidates = [
    'assessment_data', 'assessments', 'assessment', 'assessment_results', 'assessment_submissions',
    'assessment_records', 'responses', 'survey_responses', 'phq9_results', 'gad7_results'
];
$assessment_table = null;
foreach ($assessment_table_candidates as $cand) {
    if (table_exists($conn, $cand)) {
        $assessment_table = $cand;
        break;
    }
}

// Actual schema mapping for this project
$cols = [
    'id' => 'assessment_id',
    'student' => 'user_id',
    'risk' => 'risk_level',
    'phq' => 'phq9_score',
    'gad' => 'gad7_score',
    'submitted' => 'assessment_date',
    'status' => 'status'
];
if ($assessment_table && $assessment_table !== 'assessment_data') {
    foreach (['id','assessment_id','submission_id','record_id','entry_id'] as $c) {
        if (column_exists($conn,$assessment_table,$c)) { $cols['id']=$c; break; }
    }
    foreach (['student_alias','alias','user_alias','submitted_by','student_id','user_id','fullname'] as $c) {
        if (column_exists($conn,$assessment_table,$c)) { $cols['student']=$c; break; }
    }
    foreach (['risk_level','risk','risklevel'] as $c) {
        if (column_exists($conn,$assessment_table,$c)) { $cols['risk']=$c; break; }
    }
    foreach (['phq_score','phq9_score','phq_total','phq_total_score'] as $c) {
        if (column_exists($conn,$assessment_table,$c)) { $cols['phq']=$c; break; }
    }
    foreach (['gad_score','gad7_score','gad_total','gad_total_score'] as $c) {
        if (column_exists($conn,$assessment_table,$c)) { $cols['gad']=$c; break; }
    }
    foreach (['submitted_at','created_at','assessment_date','timestamp','submitted'] as $c) {
        if (column_exists($conn,$assessment_table,$c)) { $cols['submitted']=$c; break; }
    }
    foreach (['status','review_status','submission_status'] as $c) {
        if (column_exists($conn,$assessment_table,$c)) { $cols['status']=$c; break; }
    }
}

// Get search and filter parameters
$search = isset($_GET['q']) ? trim($_GET['q']) : '';
$filter_status = isset($_GET['status']) ? trim($_GET['status']) : '';
$filter_risk = isset($_GET['risk']) ? trim($_GET['risk']) : '';
$sort_by = isset($_GET['sort']) ? trim($_GET['sort']) : 'recent';

$assessments = [];
$total_count = 0;

if ($assessment_table && !empty($cols['student']) && !empty($cols['submitted'])) {
    $alias = $assessment_table === 'assessment_data' ? 'a' : $assessment_table;
    $select_cols = [];
    $select_cols[] = $alias . '.' . $cols['id'] . ' AS assessment_id';
    $select_cols[] = 'u.fullname AS student_name';
    if ($cols['risk']) $select_cols[] = $alias . '.' . $cols['risk'] . ' AS risk_level';
    if ($cols['phq']) $select_cols[] = $alias . '.' . $cols['phq'] . ' AS phq_score';
    if ($cols['gad']) $select_cols[] = $alias . '.' . $cols['gad'] . ' AS gad_score';
    $select_cols[] = $alias . '.' . $cols['submitted'] . ' AS submitted_at';
    if ($cols['status']) $select_cols[] = $alias . '.' . $cols['status'] . ' AS status';

    $sql = "SELECT " . implode(', ', $select_cols) . " FROM `$assessment_table` `$alias` LEFT JOIN user_data u ON u.user_id = $alias.user_id";

    $where = [];

    if ($search) {
        $s = mysqli_real_escape_string($conn, $search);
        $where[] = "(u.fullname LIKE '%$s%' OR u.email LIKE '%$s%' OR u.student_number LIKE '%$s%' OR $alias.assessment_id LIKE '%$s%')";
    }

    if ($filter_status === '') {
        if ($cols['status']) {
            $where[] = "($alias." . $cols['status'] . " IN ('Pending','Pending Review','Reviewed','Monitoring','Referred') OR $alias." . $cols['status'] . " IS NULL)";
        }
    } else {
        if ($cols['status']) {
            $fs = mysqli_real_escape_string($conn, $filter_status);
            $where[] = "$alias." . $cols['status'] . " = '$fs'";
        }
    }

    if ($filter_risk) {
        if ($cols['risk']) {
            $fr = mysqli_real_escape_string($conn, $filter_risk);
            $where[] = "$alias." . $cols['risk'] . " = '$fr'";
        }
    }

    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }

    if ($sort_by === 'risk_high') {
        $sql .= " ORDER BY FIELD($alias." . $cols['risk'] . ", 'High', 'Moderate', 'Low', 'high', 'moderate', 'low'), $alias." . $cols['submitted'] . " DESC";
    } else {
        $sql .= " ORDER BY $alias." . $cols['submitted'] . " DESC, $alias." . $cols['id'] . " DESC";
    }

    $res = run_query($conn, $sql);
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $assessments[] = $row;
        }
        $total_count = count($assessments);
    }
}

$status_options = ['Pending', 'Reviewed', 'Monitoring', 'Referred'];
$risk_options = ['High', 'Moderate', 'Low'];
?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Assessment Queue - Counselor</title>
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
            position: fixed;
            height: 100vh;
            overflow-y: auto;
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
            margin-left: 260px;
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
        .controls {
            display: flex;
            gap: 16px;
            margin-bottom: 24px;
            flex-wrap: wrap;
            align-items: center;
        }
        .search-box {
            flex: 1;
            min-width: 250px;
            display: flex;
            align-items: center;
            background: white;
            border: 1px solid #d8dcd8;
            border-radius: 8px;
            padding: 10px 14px;
            gap: 8px;
        }
        .search-box input {
            flex: 1;
            border: none;
            font-size: 1rem;
            outline: none;
        }
        .filter-group {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }
        select {
            background: white;
            border: 1px solid #d8dcd8;
            border-radius: 8px;
            padding: 10px 12px;
            font-size: 1rem;
            cursor: pointer;
            color: #1a1a1a;
        }
        .btn {
            background: #5c8a60;
            color: white;
            border: none;
            border-radius: 8px;
            padding: 10px 18px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s ease;
        }
        .btn:hover {
            background: #4a6f4f;
        }
        .btn-secondary {
            background: #a0a8a0;
        }
        .btn-secondary:hover {
            background: #8a9289;
        }
        .table-wrap {
            background: white;
            border: 1px solid #d8dcd8;
            border-radius: 12px;
            overflow: hidden;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        thead {
            background: #f5f7f5;
            border-bottom: 2px solid #d8dcd8;
        }
        th {
            padding: 16px;
            text-align: left;
            font-weight: 600;
            color: #1a1a1a;
        }
        td {
            padding: 14px 16px;
            border-bottom: 1px solid #e8ebe8;
        }
        tbody tr:hover {
            background: #f9faf9;
        }
        tbody tr:last-child td {
            border-bottom: none;
        }
        .risk-high {
            color: #d9695d;
            font-weight: 600;
        }
        .risk-moderate {
            color: #e2bf61;
            font-weight: 600;
        }
        .risk-low {
            color: #7ba889;
            font-weight: 600;
        }
        .status-pending {
            background: #fff4e6;
            color: #d97706;
            padding: 6px 12px;
            border-radius: 6px;
            font-weight: 500;
            font-size: 0.9rem;
            display: inline-block;
        }
        .status-reviewed {
            background: #dbeafe;
            color: #0284c7;
            padding: 6px 12px;
            border-radius: 6px;
            font-weight: 500;
            font-size: 0.9rem;
            display: inline-block;
        }
        .status-referred {
            background: #ddd6fe;
            color: #6f42c1;
            padding: 6px 12px;
            border-radius: 6px;
            font-weight: 500;
            font-size: 0.9rem;
            display: inline-block;
        }
        .action-links {
            display: flex;
            gap: 12px;
        }
        .action-links a {
            padding: 8px 14px;
            background: #5c8a60;
            color: white;
            border-radius: 6px;
            font-size: 0.9rem;
            font-weight: 500;
            transition: background 0.2s ease;
        }
        .action-links a:hover {
            background: #4a6f4f;
        }
        .empty-state {
            text-align: center;
            padding: 60px 32px;
            color: #666;
        }
        .empty-state-icon {
            font-size: 3rem;
            margin-bottom: 16px;
        }
        .empty-state h3 {
            margin: 0 0 8px;
            font-size: 1.5rem;
        }
        .empty-state p {
            margin: 0;
            color: #999;
        }
        .queue-stats {
            background: white;
            border: 1px solid #d8dcd8;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 24px;
            display: flex;
            gap: 40px;
        }
        .stat-item {
            flex: 0 0 auto;
        }
        .stat-label {
            font-size: 0.95rem;
            color: #666;
            margin-bottom: 4px;
        }
        .stat-value {
            font-size: 2.5rem;
            font-weight: 700;
            color: #5c8a60;
        }
        .alert-note {
            background: #fef3c7;
            border-left: 4px solid #f59e0b;
            padding: 16px;
            border-radius: 6px;
            margin-bottom: 24px;
            color: #92400e;
        }
    </style>
</head>
<body>
    <div class="app-shell">
      <aside class="sidebar">
        <div class="brand">PTC Wellness</div>
        <nav class="nav" aria-label="Main navigation">
          <a class="nav-link" href="dashboard.php"><span class="nav-icon">🏠</span><span>Dashboard</span></a>
          <a class="nav-link active" href="assessment_queue.php"><span class="nav-icon">📋</span><span>Assessments Queue</span></a>
          <a class="nav-link" href="monitoring.php"><span class="nav-icon">👁️</span><span>Monitoring</span></a>
          <a class="nav-link" href="referral.php"><span class="nav-icon">📤</span><span>Referral</span></a>
          <a class="nav-link" href="assessment_history.php"><span class="nav-icon">📚</span><span>Assessment History</span></a>
          <a class="nav-link" href="profile.php"><span class="nav-icon">👤</span><span>Profile</span></a>
          <a class="nav-link logout" href="../auth/logout.php"><span class="nav-icon">↩️</span><span>Logout</span></a>
        </nav>
      </aside>

        <main class="main-content">
            <div class="page-header">
                <h1 class="page-title">Assessment Queue</h1>
                <p class="page-subtitle">View and manage pending assessments</p>
            </div>

            <?php if ($assessment_table): ?>
                <div class="queue-stats">
                    <div class="stat-item">
                        <div class="stat-label">Total in Queue</div>
                        <div class="stat-value"><?php echo $total_count; ?></div>
                    </div>
                </div>
            <?php endif; ?>

            <div class="controls">
                <form method="GET" action="" style="display: flex; gap: 12px; flex: 1; min-width: 250px;">
                    <div class="search-box">
                        <span>🔍</span>
                        <input type="text" name="q" placeholder="Search student name or ID..." value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    <button type="submit" class="btn">Search</button>
                </form>

                <div class="filter-group">
                    <form method="GET" action="" style="display: flex; gap: 12px;">
                        <input type="hidden" name="q" value="<?php echo htmlspecialchars($search); ?>">
                        
                        <select name="risk" onchange="this.form.submit()">
                            <option value="">All Risk Levels</option>
                            <?php foreach ($risk_options as $risk): ?>
                                <option value="<?php echo htmlspecialchars($risk); ?>" <?php echo $filter_risk === $risk ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($risk); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <select name="status" onchange="this.form.submit()">
                            <option value="">All Statuses</option>
                            <?php foreach ($status_options as $status): ?>
                                <option value="<?php echo htmlspecialchars($status); ?>" <?php echo $filter_status === $status ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($status); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <select name="sort" onchange="this.form.submit()">
                            <option value="recent" <?php echo $sort_by === 'recent' ? 'selected' : ''; ?>>Most Recent</option>
                            <option value="risk_high" <?php echo $sort_by === 'risk_high' ? 'selected' : ''; ?>>Highest Risk</option>
                        </select>
                    </form>
                </div>
            </div>

            <?php if ($assessment_table): ?>
                <?php if (!empty($assessments)): ?>
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Student</th>
                                    <th>Risk Level</th>
                                    <th>PHQ-9</th>
                                    <th>GAD-7</th>
                                    <th>Submitted</th>
                                    <th>Status</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($assessments as $assessment): ?>
                                    <tr>
                                        <td>
                                            <?php 
                                            $student_name = !empty($assessment['student_fullname']) 
                                                ? htmlspecialchars($assessment['student_fullname'])
                                                : htmlspecialchars($assessment['student_name']);
                                            echo $student_name;
                                            ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($assessment['risk_level'])): ?>
                                                <span class="risk-<?php echo strtolower($assessment['risk_level']); ?>">
                                                    <?php echo htmlspecialchars($assessment['risk_level']); ?>
                                                </span>
                                            <?php else: ?>
                                                <span style="color: #999;">N/A</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo !empty($assessment['phq_score']) ? htmlspecialchars($assessment['phq_score']) : 'N/A'; ?></td>
                                        <td><?php echo !empty($assessment['gad_score']) ? htmlspecialchars($assessment['gad_score']) : 'N/A'; ?></td>
                                        <td><?php echo !empty($assessment['submitted_at']) ? htmlspecialchars(date('M d, Y h:i A', strtotime($assessment['submitted_at']))) : 'N/A'; ?></td>
                                        <td>
                                            <?php 
                                            $status = !empty($assessment['status']) ? strtolower($assessment['status']) : 'pending';
                                            $status_class = 'status-' . str_replace(' ', '-', $status);
                                            if (!strpos($status_class, 'status-')) {
                                                $status_class = 'status-pending';
                                            }
                                            ?>
                                            <span class="<?php echo $status_class; ?>">
                                                <?php echo htmlspecialchars(ucfirst($assessment['status'] ?? 'Pending')); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="action-links">
                                                <a href="view_assessment.php?id=<?php echo htmlspecialchars($assessment['assessment_id']); ?>">View</a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <div class="empty-state-icon">📭</div>
                        <h3>No Assessments Found</h3>
                        <p><?php echo $search ? 'Try adjusting your search criteria' : 'No pending assessments at the moment'; ?></p>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <div class="alert-note">
                    ⚠️ No assessments table was detected. If your assessments table uses a different name or schema, 
                    please contact your administrator or update the database configuration.
                </div>
            <?php endif; ?>
        </main>
    </div>
</body>
</html>
