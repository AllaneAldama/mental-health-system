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

// Get assessment ID from URL
$assessment_id = isset($_GET['id']) ? trim($_GET['id']) : null;

if (!$assessment_id) {
    header("Location: assessment_queue.php");
    exit();
}

// Detect the project schema's assessment table and actual column names
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

$assessment = null;
$student_info = null;
$phq9_questions = [];
$gad7_questions = [];
$phq9_responses = [];
$gad7_responses = [];
$counselor_notes = '';

$phq9_questions = [
    1 => "Little interest or pleasure in doing things",
    2 => "Feeling down, depressed, or hopeless",
    3 => "Trouble falling or staying asleep, or sleeping too much",
    4 => "Feeling tired or having little energy",
    5 => "Poor appetite or overeating",
    6 => "Feeling bad about yourself or that you're a failure",
    7 => "Trouble concentrating on things",
    8 => "Moving or speaking so slowly that others have noticed",
    9 => "Thoughts that you'd be better off dead"
];

$gad7_questions = [
    1 => "Feeling nervous, anxious, or on edge",
    2 => "Not being able to stop or control worrying",
    3 => "Worrying too much about different things",
    4 => "Trouble relaxing",
    5 => "Being so restless that it's hard to sit still",
    6 => "Becoming easily annoyed or irritable",
    7 => "Feeling afraid as if something awful might happen"
];

if ($assessment_table) {
    $cols = ['id' => 'assessment_id', 'student' => 'user_id', 'risk' => 'risk_level', 'phq' => 'phq9_score', 'gad' => 'gad7_score', 'submitted' => 'assessment_date', 'status' => 'status'];

    if ($assessment_table !== 'assessment_data') {
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

    $id_col = $cols['id'];
    $assessment_id_esc = mysqli_real_escape_string($conn, $assessment_id);
    $sql = "SELECT a.*, u.fullname, u.email, u.student_number FROM `$assessment_table` a LEFT JOIN user_data u ON u.user_id = a.user_id WHERE a.$id_col = '$assessment_id_esc' LIMIT 1";
    $res = mysqli_query($conn, $sql);

    if ($res && mysqli_num_rows($res) > 0) {
        $assessment = mysqli_fetch_assoc($res);
        $student_info = [
            'user_id' => $assessment['user_id'] ?? null,
            'fullname' => $assessment['fullname'] ?? 'Unknown',
            'email' => $assessment['email'] ?? 'N/A',
            'student_number' => $assessment['student_number'] ?? 'N/A'
        ];
    }
}

if ($assessment_table && $assessment) {
    $phq_sql = "SELECT q.question_number, q.question_text, r.answer_score
                FROM response_data r
                JOIN question_data q ON q.question_id = r.question_id
                WHERE r.assessment_id = '$assessment_id_esc' AND q.assessment_type = 'PHQ-9'
                ORDER BY q.question_number ASC";
    $phq_res = mysqli_query($conn, $phq_sql);
    if ($phq_res) {
        while ($row = mysqli_fetch_assoc($phq_res)) {
            $phq9_responses[$row['question_number']] = [
                'question' => $row['question_text'],
                'score' => (int)$row['answer_score']
            ];
        }
    }

    $gad_sql = "SELECT q.question_number, q.question_text, r.answer_score
                FROM response_data r
                JOIN question_data q ON q.question_id = r.question_id
                WHERE r.assessment_id = '$assessment_id_esc' AND q.assessment_type = 'GAD-7'
                ORDER BY q.question_number ASC";
    $gad_res = mysqli_query($conn, $gad_sql);
    if ($gad_res) {
        while ($row = mysqli_fetch_assoc($gad_res)) {
            $gad7_responses[$row['question_number']] = [
                'question' => $row['question_text'],
                'score' => (int)$row['answer_score']
            ];
        }
    }

    if (table_exists($conn, 'referral_data')) {
        $ref_sql = "SELECT remarks FROM referral_data WHERE assessment_id = '$assessment_id_esc' ORDER BY referral_date DESC LIMIT 1";
        $ref_res = mysqli_query($conn, $ref_sql);
        if ($ref_res && mysqli_num_rows($ref_res) > 0) {
            $ref_row = mysqli_fetch_assoc($ref_res);
            $counselor_notes = $ref_row['remarks'] ?? '';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_notes' && $assessment && table_exists($conn, 'referral_data')) {
    $note_text = isset($_POST['counselor_notes']) ? trim($_POST['counselor_notes']) : '';
    $note_text_esc = mysqli_real_escape_string($conn, $note_text);
    $update_sql = "UPDATE referral_data SET remarks = '$note_text_esc' WHERE assessment_id = '$assessment_id_esc' ORDER BY referral_date DESC LIMIT 1";
    mysqli_query($conn, $update_sql);
    $counselor_notes = $note_text;
}

?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Assessment Details - Counselor</title>
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
            display: flex;
            align-items: center;
            gap: 16px;
            margin-bottom: 24px;
        }
        .back-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
            background: #f0f0f0;
            border-radius: 8px;
            cursor: pointer;
            font-size: 1.3rem;
            transition: background 0.2s ease;
        }
        .back-btn:hover {
            background: #e0e0e0;
        }
        .header-content h1 {
            margin: 0 0 4px;
            font-size: 2.2rem;
            font-weight: 600;
        }
        .header-content p {
            margin: 0;
            color: #666;
        }
        .content-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
            margin-bottom: 24px;
        }
        @media (max-width: 1200px) {
            .content-grid {
                grid-template-columns: 1fr;
            }
        }
        .card {
            background: white;
            border: 1px solid #d8dcd8;
            border-radius: 12px;
            padding: 24px;
        }
        .card-title {
            font-size: 1.3rem;
            font-weight: 600;
            margin: 0 0 18px;
            padding-bottom: 12px;
            border-bottom: 2px solid #f0f0f0;
        }
        .info-group {
            margin-bottom: 18px;
        }
        .info-group:last-child {
            margin-bottom: 0;
        }
        .info-label {
            font-size: 0.9rem;
            color: #666;
            margin-bottom: 4px;
            font-weight: 500;
        }
        .info-value {
            font-size: 1.1rem;
            color: #1a1a1a;
            font-weight: 500;
        }
        .score-display {
            display: inline-block;
            background: #f0f5f0;
            border: 2px solid #5c8a60;
            border-radius: 8px;
            padding: 12px 20px;
            font-size: 1.4rem;
            font-weight: 700;
            color: #5c8a60;
        }
        .risk-high {
            background: #fee2e2;
            color: #dc2626;
            padding: 8px 16px;
            border-radius: 8px;
            display: inline-block;
            font-weight: 600;
        }
        .risk-moderate {
            background: #fef3c7;
            color: #d97706;
            padding: 8px 16px;
            border-radius: 8px;
            display: inline-block;
            font-weight: 600;
        }
        .risk-low {
            background: #dcfce7;
            color: #16a34a;
            padding: 8px 16px;
            border-radius: 8px;
            display: inline-block;
            font-weight: 600;
        }
        .responses-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 16px;
        }
        .response-item {
            background: #f9faf9;
            border: 1px solid #e5e7e5;
            border-radius: 8px;
            padding: 14px;
        }
        .response-question {
            font-weight: 600;
            margin-bottom: 8px;
            color: #1a1a1a;
        }
        .response-answer {
            color: #666;
            font-size: 0.95rem;
        }
        .notes-section {
            grid-column: 1 / -1;
        }
        .notes-textarea {
            width: 100%;
            min-height: 120px;
            padding: 12px;
            border: 1px solid #d8dcd8;
            border-radius: 8px;
            font-family: inherit;
            font-size: 1rem;
            resize: vertical;
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
        .button-group {
            display: flex;
            gap: 12px;
            margin-top: 16px;
        }
        .full-width {
            grid-column: 1 / -1;
        }
        .alert-note {
            background: #fef3c7;
            border-left: 4px solid #f59e0b;
            padding: 16px;
            border-radius: 6px;
            margin-bottom: 24px;
            color: #92400e;
        }
        .alert-error {
            background: #fee2e2;
            border-left: 4px solid #dc2626;
            padding: 16px;
            border-radius: 6px;
            margin-bottom: 24px;
            color: #991b1b;
        }
        .section-title {
            font-size: 1.2rem;
            font-weight: 600;
            margin: 24px 0 16px;
            padding-bottom: 8px;
            border-bottom: 2px solid #f0f0f0;
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
                <a class="back-btn" onclick="history.back()" title="Go back">←</a>
                <div class="header-content">
                    <h1>Assessment Details</h1>
                    <p>Review comprehensive assessment information</p>
                </div>
            </div>

            <?php if (!$assessment): ?>
                <div class="alert-error">
                    ⚠️ Assessment not found. Please go back and select a valid assessment from the queue.
                </div>
                <a href="assessment_queue.php" class="btn">Back to Queue</a>
            <?php else: ?>

                <div class="content-grid">
                    <!-- Student Information -->
                    <div class="card">
                        <h2 class="card-title">👤 Student Information</h2>
                        
                        <div class="info-group">
                            <div class="info-label">Student Name</div>
                            <div class="info-value">
                                <?php 
                                if ($student_info && !empty($student_info['fullname'])) {
                                    echo htmlspecialchars($student_info['fullname']);
                                } else {
                                    echo htmlspecialchars($assessment[$cols['student']] ?? 'N/A');
                                }
                                ?>
                            </div>
                        </div>

                        <?php if ($student_info && !empty($student_info['email'])): ?>
                            <div class="info-group">
                                <div class="info-label">Email</div>
                                <div class="info-value">
                                    <a href="mailto:<?php echo htmlspecialchars($student_info['email']); ?>" style="color: #5c8a60;">
                                        <?php echo htmlspecialchars($student_info['email']); ?>
                                    </a>
                                </div>
                            </div>
                        <?php endif; ?>

                        <div class="info-group">
                            <div class="info-label">Assessment ID</div>
                            <div class="info-value"><?php echo htmlspecialchars($assessment_id); ?></div>
                        </div>

                        <?php if (!empty($assessment[$cols['submitted']])): ?>
                            <div class="info-group">
                                <div class="info-label">Submitted Date</div>
                                <div class="info-value">
                                    <?php echo htmlspecialchars(date('M d, Y h:i A', strtotime($assessment[$cols['submitted']]))); ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($assessment[$cols['status']])): ?>
                            <div class="info-group">
                                <div class="info-label">Status</div>
                                <div class="info-value"><?php echo htmlspecialchars($assessment[$cols['status']]); ?></div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Scores Summary -->
                    <div class="card">
                        <h2 class="card-title">📊 Assessment Scores</h2>
                        
                        <?php if (!empty($assessment[$cols['phq']])): ?>
                            <div class="info-group">
                                <div class="info-label">PHQ-9 Score</div>
                                <div class="info-value">
                                    <span class="score-display"><?php echo htmlspecialchars($assessment[$cols['phq']]); ?>/27</span>
                                </div>
                                <div style="margin-top: 8px; font-size: 0.9rem; color: #666;">
                                    <?php
                                    $phq_score = intval($assessment[$cols['phq']]);
                                    if ($phq_score <= 4) echo "Minimal depression";
                                    elseif ($phq_score <= 9) echo "Mild depression";
                                    elseif ($phq_score <= 14) echo "Moderate depression";
                                    elseif ($phq_score <= 19) echo "Moderately severe depression";
                                    else echo "Severe depression";
                                    ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($assessment[$cols['gad']])): ?>
                            <div class="info-group">
                                <div class="info-label">GAD-7 Score</div>
                                <div class="info-value">
                                    <span class="score-display"><?php echo htmlspecialchars($assessment[$cols['gad']]); ?>/21</span>
                                </div>
                                <div style="margin-top: 8px; font-size: 0.9rem; color: #666;">
                                    <?php
                                    $gad_score = intval($assessment[$cols['gad']]);
                                    if ($gad_score <= 4) echo "Minimal anxiety";
                                    elseif ($gad_score <= 9) echo "Mild anxiety";
                                    elseif ($gad_score <= 14) echo "Moderate anxiety";
                                    else echo "Severe anxiety";
                                    ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($assessment[$cols['risk']])): ?>
                            <div class="info-group">
                                <div class="info-label">Risk Level</div>
                                <div class="info-value">
                                    <span class="risk-<?php echo strtolower($assessment[$cols['risk']]); ?>">
                                        <?php echo htmlspecialchars($assessment[$cols['risk']]); ?>
                                    </span>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- PHQ-9 Responses -->
                    <div class="card full-width">
                        <h2 class="card-title">📋 PHQ-9 Responses</h2>
                        <div class="responses-grid">
                            <?php 
                            if (!empty($assessment)) {
                                for ($i = 1; $i <= 9; $i++) {
                                    $response_key = 'phq9_q' . $i;
                                    $response_value = $assessment[$response_key] ?? null;
                                    
                                    if ($response_value !== null) {
                                        $response_labels = ['0' => 'Not at all', '1' => 'Several days', '2' => 'More than half the days', '3' => 'Nearly every day'];
                                        $label = $response_labels[$response_value] ?? 'Unknown';
                                        ?>
                                        <div class="response-item">
                                            <div class="response-question">
                                                Q<?php echo $i; ?>. <?php echo htmlspecialchars($phq9_questions[$i]); ?>
                                            </div>
                                            <div class="response-answer">
                                                <strong><?php echo htmlspecialchars($label); ?></strong> (Score: <?php echo htmlspecialchars($response_value); ?>)
                                            </div>
                                        </div>
                                        <?php
                                    }
                                }
                            }
                            ?>
                        </div>
                        <?php if (empty($assessment) || !isset($assessment['phq9_q1'])): ?>
                            <p style="color: #999; text-align: center; padding: 20px;">No detailed PHQ-9 responses available</p>
                        <?php endif; ?>
                    </div>

                    <!-- GAD-7 Responses -->
                    <div class="card full-width">
                        <h2 class="card-title">📋 GAD-7 Responses</h2>
                        <div class="responses-grid">
                            <?php 
                            if (!empty($assessment)) {
                                for ($i = 1; $i <= 7; $i++) {
                                    $response_key = 'gad7_q' . $i;
                                    $response_value = $assessment[$response_key] ?? null;
                                    
                                    if ($response_value !== null) {
                                        $response_labels = ['0' => 'Not at all', '1' => 'Several days', '2' => 'More than half the days', '3' => 'Nearly every day'];
                                        $label = $response_labels[$response_value] ?? 'Unknown';
                                        ?>
                                        <div class="response-item">
                                            <div class="response-question">
                                                Q<?php echo $i; ?>. <?php echo htmlspecialchars($gad7_questions[$i]); ?>
                                            </div>
                                            <div class="response-answer">
                                                <strong><?php echo htmlspecialchars($label); ?></strong> (Score: <?php echo htmlspecialchars($response_value); ?>)
                                            </div>
                                        </div>
                                        <?php
                                    }
                                }
                            }
                            ?>
                        </div>
                        <?php if (empty($assessment) || !isset($assessment['gad7_q1'])): ?>
                            <p style="color: #999; text-align: center; padding: 20px;">No detailed GAD-7 responses available</p>
                        <?php endif; ?>
                    </div>

                    <!-- Counselor Notes -->
                    <div class="card full-width notes-section">
                        <h2 class="card-title">📝 Counselor Notes</h2>
                        <form method="POST" action="">
                            <input type="hidden" name="action" value="save_notes">
                            <textarea name="counselor_notes" class="notes-textarea" placeholder="Add your assessment notes, observations, and recommendations here...
                            
This section is for:
- Your clinical observations
- Recommended interventions
- Follow-up actions
- Referral notes"><?php echo htmlspecialchars($counselor_notes); ?></textarea>
                            <div class="button-group">
                                <button type="submit" class="btn">💾 Save Notes</button>
                                <a href="assessment_queue.php" class="btn" style="background: #a0a8a0;">Back to Queue</a>
                            </div>
                        </form>
                    </div>
                </div>

            <?php endif; ?>
        </main>
    </div>
</body>
</html>
