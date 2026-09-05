<?php
require_once __DIR__ . "/../includes/session.php";
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

// Detect tables using the actual schema for this project
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

$provider_table = null;
$providers = [];

$message = '';
$error = '';
$action = isset($_POST['action']) ? $_POST['action'] : '';

if ($action === 'create_referral' && $referral_table) {
    $assessment_id = isset($_POST['assessment_id']) ? mysqli_real_escape_string($conn, $_POST['assessment_id']) : '';
    $remarks = isset($_POST['counselor_notes']) ? mysqli_real_escape_string($conn, $_POST['counselor_notes']) : '';
    $counselor_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
    $today = date('Y-m-d H:i:s');

    if ($assessment_id !== '' && $counselor_id > 0) {
        $sql = "INSERT INTO `$referral_table` (assessment_id, counselor_id, referral_status, referral_date, remarks)
                VALUES ('$assessment_id', '$counselor_id', 'Pending', '$today', '$remarks')";

        if (mysqli_query($conn, $sql)) {
            $message = "Referral created successfully!";
        } else {
            $error = "Error creating referral: " . mysqli_error($conn);
        }
    } else {
        $error = "Assessment and counselor details are required to create a referral.";
    }
} elseif ($action === 'update_status' && $referral_table) {
    $referral_id = isset($_POST['referral_id']) ? mysqli_real_escape_string($conn, $_POST['referral_id']) : '';
    $status = isset($_POST['status']) ? mysqli_real_escape_string($conn, $_POST['status']) : 'Pending';
    $notes = isset($_POST['update_notes']) ? mysqli_real_escape_string($conn, $_POST['update_notes']) : '';

    $sql = "UPDATE `$referral_table` SET referral_status = '$status'";
    if (!empty($notes)) {
        $sql .= ", remarks = CONCAT(COALESCE(remarks, ''), '\n---\n', '$notes', ' (Updated: " . date('Y-m-d H:i') . ")')";
    }
    $sql .= " WHERE referral_id = '$referral_id'";

    if (mysqli_query($conn, $sql)) {
        $message = "Referral status updated successfully!";
    } else {
        $error = "Error updating referral: " . mysqli_error($conn);
    }
} elseif ($action === 'accept_referral' && $referral_table) {
    $referral_id = isset($_POST['referral_id']) ? mysqli_real_escape_string($conn, $_POST['referral_id']) : '';
    $sql = "UPDATE `$referral_table` SET referral_status = 'Accepted' WHERE referral_id = '$referral_id'";

    if (mysqli_query($conn, $sql)) {
        $message = "Referral accepted!";
    } else {
        $error = "Error accepting referral: " . mysqli_error($conn);
    }
}

$pending_referrals = [];
$for_referral_assessments = [];

if ($assessment_table && $referral_table) {
    $sql = "SELECT r.referral_id, r.assessment_id, r.counselor_id, r.referral_status, r.referral_date, r.remarks,
                   a.user_id, a.phq9_score, a.gad7_score, a.risk_level, a.assessment_date,
                   u.fullname, u.email
            FROM `$referral_table` r
            LEFT JOIN `$assessment_table` a ON r.assessment_id = a.assessment_id
            LEFT JOIN user_data u ON u.user_id = a.user_id
            WHERE r.referral_status IN ('Pending', 'Accepted')
            ORDER BY r.referral_date DESC";

    $res = mysqli_query($conn, $sql);
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $pending_referrals[] = $row;
        }
    }

    $sql = "SELECT a.assessment_id, a.user_id, a.phq9_score, a.gad7_score, a.risk_level, a.assessment_date,
                   u.fullname, u.email
            FROM `$assessment_table` a
            LEFT JOIN user_data u ON u.user_id = a.user_id
            LEFT JOIN `$referral_table` r ON r.assessment_id = a.assessment_id
            WHERE a.risk_level IN ('High', 'Moderate') AND r.referral_id IS NULL
            ORDER BY a.assessment_date DESC";

    $res = mysqli_query($conn, $sql);
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $for_referral_assessments[] = $row;
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Referral Management - Counselor Dashboard</title>
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
        .header { margin-bottom: 20px; }
        .header h1 {
            margin: 0;
            font-size: 2.7rem;
            font-weight: 600;
            letter-spacing: -0.06em;
            color: #1a1a1a;
        }
        .header p {
            margin: 8px 0 0;
            font-size: 1.15rem;
            color: #4c4c4c;
        }
        .stats-container {
            display: grid;
            grid-template-columns: repeat(3, minmax(180px, 1fr));
            gap: 18px;
            margin-bottom: 24px;
        }
        .stat-card {
            background: rgba(255,255,255,0.28);
            border: 1px solid #dfe4dc;
            border-radius: 12px;
            padding: 18px 18px 14px;
            min-height: 120px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }
        .stat-card h3 {
            font-size: 0.98rem;
            color: #2e2e2e;
            font-weight: 700;
            margin: 0;
        }
        .stat-card .value {
            font-size: 2.2rem;
            font-weight: 800;
            line-height: 1;
            letter-spacing: -0.05em;
            color: #1f3d2a;
        }
        .alert {
            padding: 12px 14px;
            border-radius: 10px;
            margin-bottom: 18px;
            font-weight: 600;
        }
        .alert-success { background: #e8f5e9; color: #1b5e20; border: 1px solid #c8e6c9; }
        .alert-error { background: #ffebee; color: #b71c1c; border: 1px solid #ffcdd2; }
        .card {
            background: rgba(255,255,255,0.28);
            border: 1px solid #dfe4dc;
            border-radius: 12px;
            padding: 18px 18px 12px;
            margin-bottom: 20px;
        }
        .card h2 {
            margin: 0 0 14px;
            font-size: 1.15rem;
            font-weight: 800;
            color: #1d1d1d;
        }
        .card h3 {
            color: #34495e;
            margin-top: 20px;
            margin-bottom: 15px;
            font-size: 16px;
        }
        .form-group { margin-bottom: 15px; }
        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #2a2a2a;
            font-weight: 700;
        }
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #cdd4cf;
            border-radius: 8px;
            font: inherit;
            background: #fff;
        }
        .form-group textarea { min-height: 80px; resize: vertical; }
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            transition: background 0.2s ease;
            text-decoration: none;
            display: inline-block;
            text-align: center;
        }
        .btn-primary { background: #5c8a60; color: white; }
        .btn-primary:hover { background: #496e4f; }
        .btn-secondary {
            background: #dfe4dc;
            color: #1f3d2a;
            margin-left: 5px;
        }
        .btn-secondary:hover { background: #d0d8cf; }
        .btn-success { background: #4fae6d; color: white; }
        .btn-success:hover { background: #3f8d5a; }
        .btn-info { background: #3d7cb3; color: white; }
        .btn-info:hover { background: #315f8d; }
        .table-container { overflow-x: auto; margin-top: 20px; }
        table { width: 100%; border-collapse: collapse; }
        thead {
            background: rgba(255,255,255,0.35);
            border-bottom: 1px solid #dfe4dc;
        }
        th {
            padding: 12px;
            text-align: left;
            color: #2d2d2d;
            font-weight: 700;
        }
        td {
            padding: 12px;
            border-bottom: 1px solid #edf2ee;
            color: #34495e;
        }
        tr:hover { background: rgba(255,255,255,0.18); }
        .badge {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
        }
        .badge-pending { background: #fff2b2; color: #8a5b00; }
        .badge-accepted { background: #dff5e3; color: #1f6f3c; }
        .badge-completed { background: #e4e9ee; color: #4c5962; }
        .badge-high { background: #ffd8d4; color: #a8433b; }
        .badge-moderate { background: #ffe7b8; color: #ac6d00; }
        .badge-low { background: #dff5e3; color: #207e46; }
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
            background: #fff;
            border-radius: 14px;
            box-shadow: 0 15px 35px rgba(0,0,0,0.18);
            padding: 22px;
            max-width: 600px;
            width: min(600px, 100%);
            max-height: 90vh;
            overflow-y: auto;
        }
        .modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 16px;
            padding-bottom: 10px;
            border-bottom: 2px solid #5c8a60;
        }
        .modal-header h2 {
            color: #2a2a2a;
            margin: 0;
            font-size: 1.5rem;
        }
        .modal-close {
            border: none;
            background: transparent;
            font-size: 1.5rem;
            cursor: pointer;
            color: #4c4c4c;
        }
        .assessment-info {
            background: #f5f7f5;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 15px;
        }
        .assessment-info p { margin: 5px 0; color: #34495e; }
        .assessment-info strong { color: #2c3e50; }
        .risk-high { color: #c0392b; font-weight: bold; }
        .risk-moderate { color: #f39c12; font-weight: bold; }
        .risk-low { color: #27ae60; font-weight: bold; }
        @media (max-width: 900px) {
            .app-shell { flex-direction: column; }
            .sidebar { width: 100%; min-height: auto; }
            .main-content { padding: 20px; }
            .stats-container { grid-template-columns: 1fr; }
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
          <a class="nav-link active" href="referral.php"><span class="nav-icon">📤</span><span>Referral</span></a>
          <a class="nav-link" href="assessment_history.php"><span class="nav-icon">📚</span><span>Assessment History</span></a>
          <a class="nav-link" href="profile.php"><span class="nav-icon">👤</span><span>Profile</span></a>
          <a class="nav-link logout" href="../auth/logout.php"><span class="nav-icon">↩️</span><span>Logout</span></a>
        </nav>
      </aside>

        <main class="main-content">
        <div class="header">
            <h1>Referral Management</h1>
            <p>Manage student referrals to external providers and departments</p>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <div class="stats-container">
            <div class="stat-card">
                <h3>Pending Referrals</h3>
                <div class="value"><?php echo count($pending_referrals); ?></div>
            </div>
            <div class="stat-card">
                <h3>High Risk (No Referral)</h3>
                <div class="value"><?php echo count($for_referral_assessments); ?></div>
            </div>
            <div class="stat-card">
                <h3>Available Providers</h3>
                <div class="value"><?php echo count($providers); ?></div>
            </div>
        </div>

        <?php if (!$assessment_table || !$referral_table): ?>
            <div class="alert alert-error">
                <strong>Database Configuration Issue:</strong>
                Assessment table: <?php echo $assessment_table ? '✓' : '✗'; ?> |
                Referral table: <?php echo $referral_table ? '✓' : '✗'; ?>
            </div>
        <?php endif; ?>

        <!-- Students Ready for Referral -->
        <div class="card">
            <h2>Students Ready for Referral</h2>
            <?php if (!empty($for_referral_assessments)): ?>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Student Name</th>
                                <th>Assessment ID</th>
                                <th>PHQ-9 / GAD-7</th>
                                <th>Risk Level</th>
                                <th>Submitted</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($for_referral_assessments as $assessment): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($assessment['fullname'] ?? 'Unknown Student'); ?></td>
                                    <td>#<?php echo htmlspecialchars($assessment['assessment_id'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($assessment['phq9_score'] ?? 'N/A'); ?> / <?php echo htmlspecialchars($assessment['gad7_score'] ?? 'N/A'); ?></td>
                                    <td>
                                        <span class="badge badge-<?php echo strtolower($assessment['risk_level'] ?? 'low'); ?>">
                                            <?php echo htmlspecialchars($assessment['risk_level'] ?? 'Unknown'); ?>
                                        </span>
                                    </td>
                                    <td><?php echo date('M d, Y', strtotime($assessment['assessment_date'] ?? 'now')); ?></td>
                                    <td>
                                        <button class="btn btn-primary" onclick="openReferralForm(<?php echo (int)($assessment['assessment_id'] ?? 0); ?>, '<?php echo htmlspecialchars($assessment['fullname'] ?? 'Unknown'); ?>', <?php echo (int)($assessment['phq9_score'] ?? 0); ?>, <?php echo (int)($assessment['gad7_score'] ?? 0); ?>)">
                                            Create Referral
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p>No high-risk students awaiting referral at this time.</p>
            <?php endif; ?>
        </div>

        <!-- Pending Referrals -->
        <div class="card">
            <h2>Pending Referrals</h2>
            <?php if (!empty($pending_referrals)): ?>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Student</th>
                                <th>Assessment</th>
                                <th>Status</th>
                                <th>Created</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pending_referrals as $referral): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($referral['fullname'] ?? 'Unknown Student'); ?></td>
                                    <td>#<?php echo htmlspecialchars($referral['assessment_id'] ?? 'N/A'); ?></td>
                                    <td>
                                        <span class="badge badge-<?php echo strtolower($referral['referral_status'] ?? 'pending'); ?>">
                                            <?php echo htmlspecialchars($referral['referral_status'] ?? 'Pending'); ?>
                                        </span>
                                    </td>
                                    <td><?php echo date('M d, Y', strtotime($referral['referral_date'] ?? 'now')); ?></td>
                                    <td>
                                        <button class="btn btn-success" onclick="acceptReferral(<?php echo (int)($referral['referral_id'] ?? 0); ?>)">Accept</button>
                                        <button class="btn btn-info" onclick="openUpdateStatus(<?php echo (int)($referral['referral_id'] ?? 0); ?>, '<?php echo htmlspecialchars($referral['referral_status'] ?? 'Pending'); ?>')">Update</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p>No pending referrals to display.</p>
            <?php endif; ?>
        </div>
    </main>
    </div>

    <!-- Create Referral Modal -->
    <div id="referralModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <button class="modal-close" onclick="closeModal('referralModal')">&times;</button>
                <h2>Create New Referral</h2>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="action" value="create_referral">
                <input type="hidden" name="assessment_id" id="assessmentId">
                <input type="hidden" name="student_id" id="studentId">

                <div class="assessment-info">
                    <p><strong>Student:</strong> <span id="modalStudentName"></span></p>
                    <p><strong>Scores:</strong> <span id="modalScores"></span></p>
                </div>

                <div class="form-group">
                    <label for="providerId">Select Provider *</label>
                    <select name="provider_id" id="providerId" required>
                        <option value="">-- Select Provider --</option>
                        <?php foreach ($providers as $provider): ?>
                            <option value="<?php echo htmlspecialchars($provider['id']); ?>">
                                <?php echo htmlspecialchars($provider['name'] . ' - ' . ($provider['department'] ?? '')); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="referralType">Referral Type *</label>
                    <select name="referral_type" id="referralType" required>
                        <option value="">-- Select Type --</option>
                        <option value="Mental Health Counseling">Mental Health Counseling</option>
                        <option value="Psychiatric Evaluation">Psychiatric Evaluation</option>
                        <option value="Crisis Intervention">Crisis Intervention</option>
                        <option value="Substance Abuse Treatment">Substance Abuse Treatment</option>
                        <option value="Other">Other</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="counselorNotes">Counselor Notes</label>
                    <textarea name="counselor_notes" id="counselorNotes" placeholder="Add any relevant information for the referring provider..."></textarea>
                </div>

                <div>
                    <button type="submit" class="btn btn-primary">Create Referral</button>
                    <button type="button" class="btn btn-secondary" onclick="closeModal('referralModal')">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Update Status Modal -->
    <div id="updateStatusModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <button class="modal-close" onclick="closeModal('updateStatusModal')">&times;</button>
                <h2>Update Referral Status</h2>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="action" value="update_status">
                <input type="hidden" name="referral_id" id="referralId">

                <div class="form-group">
                    <label for="statusSelect">Status *</label>
                    <select name="status" id="statusSelect" required>
                        <option value="Pending">Pending</option>
                        <option value="Accepted">Accepted</option>
                        <option value="In Progress">In Progress</option>
                        <option value="Completed">Completed</option>
                        <option value="Closed">Closed</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="updateNotes">Update Notes</label>
                    <textarea name="update_notes" id="updateNotes" placeholder="Add notes about this status update..."></textarea>
                </div>

                <div>
                    <button type="submit" class="btn btn-primary">Update Status</button>
                    <button type="button" class="btn btn-secondary" onclick="closeModal('updateStatusModal')">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openReferralForm(assessmentId, studentName, phqScore, gadScore) {
            document.getElementById('assessmentId').value = assessmentId;
            document.getElementById('studentId').value = assessmentId;
            document.getElementById('modalStudentName').textContent = studentName;
            document.getElementById('modalScores').textContent = 'PHQ-9: ' + phqScore + ' | GAD-7: ' + gadScore;
            document.getElementById('referralModal').classList.add('show');
        }

        function openUpdateStatus(referralId, status) {
            document.getElementById('referralId').value = referralId;
            document.getElementById('statusSelect').value = status;
            document.getElementById('updateStatusModal').classList.add('show');
        }

        function acceptReferral(referralId) {
            if (confirm('Are you sure you want to accept this referral?')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = '<input type="hidden" name="action" value="accept_referral"><input type="hidden" name="referral_id" value="' + referralId + '">';
                document.body.appendChild(form);
                form.submit();
            }
        }

        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('show');
        }

        window.onclick = function(event) {
            const referralModal = document.getElementById('referralModal');
            const updateModal = document.getElementById('updateStatusModal');
            if (event.target === referralModal) {
                referralModal.classList.remove('show');
            }
            if (event.target === updateModal) {
                updateModal.classList.remove('show');
            }
        };
    </script>
</body>
</html>
