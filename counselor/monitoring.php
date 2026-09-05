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
    if ($s === 'accepted' || $s === 'monitoring' || $s === 'active') {
        return 'success';
    }
    if ($s === 'follow-up' || $s === 'follow_up' || $s === 'pending') {
        return 'warning';
    }
    if ($s === 'high risk' || $s === 'high' || $s === 'critical') {
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

$referral_table_candidates = ['referral_data', 'referrals', 'referral', 'referral_records'];
$referral_table = null;
foreach ($referral_table_candidates as $cand) {
    if (table_exists($conn, $cand)) { $referral_table = $cand; break; }
}

$message = '';
$error = '';
$action = isset($_POST['action']) ? $_POST['action'] : '';

if ($action === 'save_monitoring_note') {
    $student_id = isset($_POST['student_id']) ? (int)$_POST['student_id'] : 0;
    $assessment_id = isset($_POST['assessment_id']) ? (int)$_POST['assessment_id'] : 0;
    $note = isset($_POST['note']) ? mysqli_real_escape_string($conn, $_POST['note']) : '';
    $status = isset($_POST['monitoring_status']) ? mysqli_real_escape_string($conn, $_POST['monitoring_status']) : 'Monitoring';
    $follow_up_date = isset($_POST['follow_up_date']) ? mysqli_real_escape_string($conn, $_POST['follow_up_date']) : '';

    if ($referral_table && $assessment_id > 0) {
        $sql = "UPDATE `$referral_table` SET referral_status = '$status'";
        if ($note !== '') {
            $sql .= ", remarks = CONCAT(COALESCE(remarks, ''), '\n---\n', '$note')";
        }
        $sql .= " WHERE assessment_id = '$assessment_id'";

        if (mysqli_query($conn, $sql)) {
            $message = "Monitoring note saved successfully.";
        } else {
            $error = "Unable to save monitoring note. " . mysqli_error($conn);
        }
    } else {
        $error = "Unable to save monitoring note. Missing referral or assessment data.";
    }
}

$monitored_students = [];

if ($referral_table) {
    $sql = "SELECT r.referral_id, r.assessment_id, r.referral_status, r.referral_date, r.remarks,
                   a.user_id, a.risk_level, a.assessment_date,
                   u.fullname, u.email
            FROM `$referral_table` r
            LEFT JOIN `assessment_data` a ON a.assessment_id = r.assessment_id
            LEFT JOIN user_data u ON u.user_id = a.user_id
            WHERE r.referral_status IN ('Pending', 'Accepted', 'Completed')
            ORDER BY r.referral_date DESC, r.referral_id DESC";
    $res = mysqli_query($conn, $sql);
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $monitored_students[] = $row;
        }
    }
}

if (empty($monitored_students) && $assessment_table) {
    $sql = "SELECT a.assessment_id, a.user_id, a.risk_level, a.assessment_date, a.status,
                   u.fullname, u.email
            FROM `$assessment_table` a
            LEFT JOIN user_data u ON u.user_id = a.user_id
            WHERE a.risk_level IN ('High', 'Moderate') OR a.status IN ('Monitoring', 'Referred')
            ORDER BY a.assessment_date DESC, a.assessment_id DESC";
    $res = mysqli_query($conn, $sql);
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $monitored_students[] = $row;
        }
    }
}

$students = [];
if (table_exists($conn, 'user_data')) {
    $res = mysqli_query($conn, "SELECT user_id, fullname, email, student_number, role_type FROM user_data ORDER BY fullname ASC");
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $students[$row['user_id']] = $row;
        }
    }
}

$stats = [
    'total_monitored' => count($monitored_students),
    'pending_followups' => 0,
    'high_risk' => 0,
    'followups_today' => 0
];

foreach ($monitored_students as $case) {
    $status = safe_string($case['referral_status'] ?? ($case['status'] ?? 'Monitoring'));
    if (stripos($status, 'pending') !== false || stripos($status, 'accepted') !== false) {
        $stats['pending_followups']++;
    }
    $risk = isset($case['risk_level']) ? safe_string($case['risk_level']) : '';
    if (stripos($risk, 'high') !== false) {
        $stats['high_risk']++;
    }

    $follow_up_date = $case['follow_up_date'] ?? $case['assessment_date'] ?? '';
    if (!empty($follow_up_date) && date('Y-m-d', strtotime($follow_up_date)) === date('Y-m-d')) {
        $stats['followups_today']++;
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Monitoring - Counselor Dashboard</title>
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
        .page-header { margin-bottom: 22px; }
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
        .alert {
            padding: 12px 14px;
            border-radius: 10px;
            margin-bottom: 18px;
            font-weight: 600;
        }
        .alert-success { background: #e8f5e9; color: #1b5e20; border: 1px solid #c8e6c9; }
        .alert-error { background: #ffebee; color: #b71c1c; border: 1px solid #ffcdd2; }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(180px, 1fr));
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
            letter-spacing: -0.05em;
            line-height: 1;
        }
        .panel {
            background: rgba(255,255,255,0.28);
            border: 1px solid #dfe4dc;
            border-radius: 12px;
            padding: 18px;
            margin-bottom: 24px;
        }
        .panel-title {
            margin: 0 0 16px;
            font-size: 1.2rem;
            font-weight: 800;
        }
        .table-wrap { overflow-x: auto; }
        table {
            width: 100%;
            border-collapse: collapse;
            background: rgba(255,255,255,0.2);
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
        .btn {
            display: inline-block;
            border: none;
            border-radius: 8px;
            padding: 10px 14px;
            font-weight: 700;
            cursor: pointer;
            transition: 0.2s ease;
        }
        .btn-primary {
            background: #5c8a60;
            color: white;
        }
        .btn-primary:hover { background: #496e4f; }
        .btn-secondary {
            background: #dfe4dc;
            color: #1f3d2a;
        }
        .btn-secondary:hover { background: #d0d8cf; }
        .inline-form { display: flex; gap: 10px; flex-wrap: wrap; }
        .inline-form input, .inline-form select, .inline-form textarea {
            padding: 10px 12px;
            border-radius: 8px;
            border: 1px solid #cdd4cf;
            font: inherit;
            background: #fff;
        }
        textarea { min-height: 110px; resize: vertical; }
        .modal {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.45);
            z-index: 1000;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .modal.show { display: flex; }
        .modal-content {
            width: min(650px, 100%);
            background: #fff;
            border-radius: 14px;
            box-shadow: 0 15px 35px rgba(0,0,0,0.18);
            padding: 22px;
        }
        .modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 16px;
        }
        .close-modal {
            border: none;
            background: transparent;
            font-size: 1.5rem;
            cursor: pointer;
            color: #4c4c4c;
        }
        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
        }
        .field { display: flex; flex-direction: column; gap: 8px; }
        .field.full { grid-column: 1 / -1; }
        label {
            font-size: 0.9rem;
            font-weight: 700;
            color: #2a2a2a;
        }
        @media (max-width: 900px) {
            .app-shell { flex-direction: column; }
            .sidebar { width: 100%; min-height: auto; }
            .main-content { padding: 20px; }
            .stats-grid, .form-grid { grid-template-columns: 1fr; }
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
          <a class="nav-link active" href="monitoring.php"><span class="nav-icon">👁️</span><span>Monitoring</span></a>
          <a class="nav-link" href="referral.php"><span class="nav-icon">📤</span><span>Referral</span></a>
          <a class="nav-link" href="assessment_history.php"><span class="nav-icon">📚</span><span>Assessment History</span></a>
          <a class="nav-link" href="profile.php"><span class="nav-icon">👤</span><span>Profile</span></a>
          <a class="nav-link logout" href="../auth/logout.php"><span class="nav-icon">↩️</span><span>Logout</span></a>
        </nav>
      </aside>

        <main class="main-content">
            <header class="page-header">
                <h1 class="page-title">Students Under Monitoring</h1>
                <p class="page-subtitle">Track follow-up care, update notes, and monitor case progress.</p>
            </header>

            <?php if ($message !== ''): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>
            <?php if ($error !== ''): ?>
                <div class="alert alert-error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>

            <section class="stats-grid">
                <div class="stat-card">
                    <div class="stat-label">Total Monitored</div>
                    <div class="stat-value"><?php echo (int)$stats['total_monitored']; ?></div>
                    <div class="stat-note">Active student cases</div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Pending Follow-ups</div>
                    <div class="stat-value"><?php echo (int)$stats['pending_followups']; ?></div>
                    <div class="stat-note">Need attention</div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">High-Risk Cases</div>
                    <div class="stat-value"><?php echo (int)$stats['high_risk']; ?></div>
                    <div class="stat-note">Escalated monitoring</div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Follow-ups Today</div>
                    <div class="stat-value"><?php echo (int)$stats['followups_today']; ?></div>
                    <div class="stat-note">Scheduled for today</div>
                </div>
            </section>

            <section class="panel">
                <h2 class="panel-title">Monitoring Overview</h2>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Student</th>
                                <th>Assessment</th>
                                <th>Status</th>
                                <th>Follow-up Date</th>
                                <th>Last Note</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($monitored_students)): ?>
                                <tr>
                                    <td colspan="6" style="padding: 24px; color: #666; text-align: center;">No monitored students found. Accepted referrals and active risk cases will appear here.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($monitored_students as $case): ?>
                                    <?php
                                        $case_id = $case['referral_id'] ?? ($case['assessment_id'] ?? 0);
                                        $student_id = $case['student_id'] ?? ($case['user_id'] ?? '');
                                        $assessment_id = $case['assessment_id'] ?? '';
                                        $status = safe_string($case['status'] ?? ($case['monitoring_status'] ?? ($case['referral_status'] ?? 'Monitoring')));
                                        $follow_up_date = safe_string($case['follow_up_date'] ?? ($case['next_follow_up'] ?? ($case['followup_date'] ?? ($case['assessment_date'] ?? ''))));
                                        $note = safe_string($case['counselor_notes'] ?? ($case['remarks'] ?? ($case['notes'] ?? ($case['monitoring_notes'] ?? 'No notes yet.'))));
                                        $student = null;
                                        if ($student_id !== '') {
                                            $student = $students[$student_id] ?? null;
                                        }
                                        if (!$student && isset($case['fullname'])) {
                                            $student = ['fullname' => $case['fullname'], 'email' => $case['email'] ?? ''];
                                        }
                                        $student_name = $student['fullname'] ?? ($student_id !== '' ? 'Student #' . $student_id : 'Unknown Student');
                                        $student_email = $student['email'] ?? 'N/A';
                                        $assessment_label = $assessment_id !== '' ? 'Assessment #' . $assessment_id : 'N/A';
                                    ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo htmlspecialchars($student_name, ENT_QUOTES, 'UTF-8'); ?></strong><br>
                                            <small><?php echo htmlspecialchars($student_email, ENT_QUOTES, 'UTF-8'); ?></small>
                                        </td>
                                        <td><?php echo htmlspecialchars($assessment_label, ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td><span class="badge badge-<?php echo status_badge($status); ?>"><?php echo htmlspecialchars($status === '' ? 'Monitoring' : $status, ENT_QUOTES, 'UTF-8'); ?></span></td>
                                        <td><?php echo htmlspecialchars($follow_up_date !== '' ? $follow_up_date : 'Not set', ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td><?php echo htmlspecialchars(strlen($note) > 100 ? substr($note, 0, 100) . '...' : $note, ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td>
                                            <button class="btn btn-primary open-note-modal" data-student-id="<?php echo htmlspecialchars($student_id, ENT_QUOTES, 'UTF-8'); ?>" data-assessment-id="<?php echo htmlspecialchars($assessment_id, ENT_QUOTES, 'UTF-8'); ?>" data-status="<?php echo htmlspecialchars($status, ENT_QUOTES, 'UTF-8'); ?>" data-followup="<?php echo htmlspecialchars($follow_up_date, ENT_QUOTES, 'UTF-8'); ?>" data-note="<?php echo htmlspecialchars($note, ENT_QUOTES, 'UTF-8'); ?>">Update</button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </main>
    </div>

    <div id="noteModal" class="modal" aria-modal="true" role="dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Update Monitoring Case</h3>
                <button class="close-modal" type="button" aria-label="Close">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="save_monitoring_note">
                <input type="hidden" name="student_id" id="studentId">
                <input type="hidden" name="assessment_id" id="assessmentId">

                <div class="form-grid">
                    <div class="field">
                        <label for="monitoringStatus">Monitoring Status</label>
                        <select id="monitoringStatus" name="monitoring_status">
                            <option value="Monitoring">Monitoring</option>
                            <option value="Follow-up">Follow-up</option>
                            <option value="Accepted">Accepted</option>
                            <option value="Completed">Completed</option>
                        </select>
                    </div>
                    <div class="field">
                        <label for="followUpDate">Follow-up Date</label>
                        <input type="date" id="followUpDate" name="follow_up_date">
                    </div>
                    <div class="field full">
                        <label for="noteText">Monitoring Notes</label>
                        <textarea id="noteText" name="note" placeholder="Record updates, symptom trends, check-in notes, and next steps..."></textarea>
                    </div>
                </div>

                <div style="margin-top: 20px; display: flex; justify-content: flex-end; gap: 10px;">
                    <button type="button" class="btn btn-secondary close-modal-trigger">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Notes</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const modal = document.getElementById('noteModal');
        const studentIdField = document.getElementById('studentId');
        const assessmentIdField = document.getElementById('assessmentId');
        const statusField = document.getElementById('monitoringStatus');
        const followUpField = document.getElementById('followUpDate');
        const noteField = document.getElementById('noteText');

        document.querySelectorAll('.open-note-modal').forEach(function (button) {
            button.addEventListener('click', function () {
                studentIdField.value = this.dataset.studentId || '';
                assessmentIdField.value = this.dataset.assessmentId || '';
                statusField.value = this.dataset.status || 'Monitoring';
                followUpField.value = this.dataset.followup || '';
                noteField.value = this.dataset.note || '';
                modal.classList.add('show');
            });
        });

        function hideModal() { modal.classList.remove('show'); }

        document.querySelectorAll('.close-modal').forEach(function (btn) {
            btn.addEventListener('click', hideModal);
        });

        document.querySelectorAll('.close-modal-trigger').forEach(function (btn) {
            btn.addEventListener('click', hideModal);
        });

        modal.addEventListener('click', function (event) {
            if (event.target === modal) hideModal();
        });
    </script>
</body>
</html>
