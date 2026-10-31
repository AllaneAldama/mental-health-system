
<?php
require_once __DIR__ . '/../includes/counselor_session.php';
require_once __DIR__ . '/../config/database.php';

if (
    !isset($_SESSION['role_type']) ||
    $_SESSION['role_type'] !== 'counselor'
) {
    header("Location: ../auth/login.php");
    exit();
}

require_once __DIR__ . "/../config/database.php";

function counselor_table_exists($conn, $table)
{
    $table = mysqli_real_escape_string($conn, $table);
    $result = mysqli_query($conn, "SHOW TABLES LIKE '{$table}'");
    return $result && mysqli_num_rows($result) > 0;
}

function counselor_column_exists($conn, $table, $column)
{
    $column = mysqli_real_escape_string($conn, $column);
    $result = mysqli_query(
        $conn,
        "SHOW COLUMNS FROM `{$table}` LIKE '{$column}'"
    );

    return $result && mysqli_num_rows($result) > 0;
}

function counselor_count($conn, $sql)
{
    $result = mysqli_query($conn, $sql);

    if (!$result) {
        return 0;
    }

    $row = mysqli_fetch_row($result);
    return (int) ($row[0] ?? 0);
}

function counselor_first_column($conn, $table, $columns)
{
    foreach ($columns as $column) {
        if (counselor_column_exists($conn, $table, $column)) {
            return $column;
        }
    }

    return null;
}

function counselor_escape($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$total_students = 0;
$total_assessments = 0;
$pending_assessments = 0;
$reviewed_assessments = 0;
$total_counselors = 0;
$recent_assessments = [];
$dashboard_notice = '';

/* Account totals */
if (counselor_table_exists($conn, 'user_data')) {
    $active_filter = counselor_column_exists(
        $conn,
        'user_data',
        'status'
    ) ? " AND status = 'active'" : '';

    $total_students = counselor_count(
        $conn,
        "SELECT COUNT(*) FROM user_data
         WHERE role_type = 'student'{$active_filter}"
    );

    $total_counselors = counselor_count(
        $conn,
        "SELECT COUNT(*) FROM user_data
         WHERE role_type = 'counselor'{$active_filter}"
    );
}

/*
 * Assessment table detection.
 * Confirm this candidate list against your actual database schema.
 */
$assessment_table = null;

$assessment_candidates = [
    'assessment_data',
    'needs_assessment',
    'needs_assessment_responses',
    'assessment_submissions',
    'assessment_records',
    'assessments'
];

foreach ($assessment_candidates as $candidate) {
    if (counselor_table_exists($conn, $candidate)) {
        $assessment_table = $candidate;
        break;
    }
}

if ($assessment_table !== null) {
    $total_assessments = counselor_count(
        $conn,
        "SELECT COUNT(*) FROM `{$assessment_table}`"
    );

    $status_column = counselor_first_column(
        $conn,
        $assessment_table,
        ['status', 'review_status']
    );

    if ($status_column !== null) {
        $pending_assessments = counselor_count(
            $conn,
            "SELECT COUNT(*) FROM `{$assessment_table}`
             WHERE LOWER(TRIM(`{$status_column}`)) = 'pending'"
        );

        $reviewed_assessments = counselor_count(
            $conn,
            "SELECT COUNT(*) FROM `{$assessment_table}`
             WHERE LOWER(TRIM(`{$status_column}`)) = 'reviewed'"
        );
    } elseif (
        counselor_column_exists(
            $conn,
            $assessment_table,
            'is_reviewed'
        )
    ) {
        $pending_assessments = counselor_count(
            $conn,
            "SELECT COUNT(*) FROM `{$assessment_table}`
             WHERE is_reviewed = 0"
        );

        $reviewed_assessments = counselor_count(
            $conn,
            "SELECT COUNT(*) FROM `{$assessment_table}`
             WHERE is_reviewed = 1"
        );
    }

    $id_column = counselor_first_column(
        $conn,
        $assessment_table,
        ['assessment_id', 'id', 'submission_id']
    );

    $date_column = counselor_first_column(
        $conn,
        $assessment_table,
        [
            'assessment_date',
            'submitted_at',
            'created_at',
            'date_submitted'
        ]
    );

    // Use an actual review timestamp if the assessment table has one.
    // Do not substitute updated_at because it may represent unrelated edits.
    $review_date_column = counselor_first_column(
        $conn,
        $assessment_table,
        [
            'reviewed_at',
            'review_date',
            'reviewed_on',
            'review_timestamp',
            'reviewed_datetime'
        ]
    );

    $user_column = counselor_first_column(
        $conn,
        $assessment_table,
        ['user_id', 'student_id']
    );

    $join = '';
    $student_fields = "'' AS student_name";

    if (
        $user_column &&
        counselor_table_exists($conn, 'user_data') &&
        counselor_column_exists($conn, 'user_data', 'user_id')
    ) {
        $name_expression = counselor_column_exists(
            $conn,
            'user_data',
            'alias'
        ) ? "NULLIF(u.alias, '')" : "NULL";

        if (counselor_column_exists($conn, 'user_data', 'fullname')) {
            $name_expression = counselor_column_exists(
                $conn,
                'user_data',
                'alias'
            )
                ? "COALESCE(NULLIF(u.alias, ''), u.fullname)"
                : "u.fullname";
        }

        $student_fields = "{$name_expression} AS student_name";

        $join = " LEFT JOIN user_data u
                  ON u.user_id = a.`{$user_column}`";
    }

    $id_expression = $id_column
        ? "a.`{$id_column}` AS assessment_id"
        : "0 AS assessment_id";

    $date_expression = $date_column
        ? "a.`{$date_column}` AS submitted_date"
        : "NULL AS submitted_date";

    $review_date_expression = $review_date_column
        ? "a.`{$review_date_column}` AS review_date"
        : "NULL AS review_date";

    if ($status_column) {
        $status_expression = "a.`{$status_column}` AS review_status";
    } elseif (
        counselor_column_exists(
            $conn,
            $assessment_table,
            'is_reviewed'
        )
    ) {
        $status_expression =
            "CASE WHEN a.is_reviewed = 1
             THEN 'Reviewed' ELSE 'Pending' END AS review_status";
    } else {
        $status_expression = "'Pending' AS review_status";
    }

    $order_expression = $date_column
        ? "a.`{$date_column}` DESC"
        : ($id_column ? "a.`{$id_column}` DESC" : "1 DESC");

    $sql = "SELECT
                {$id_expression},
                {$date_expression},
                {$status_expression},
                {$review_date_expression},
                {$student_fields}
            FROM `{$assessment_table}` a
            {$join}
            ORDER BY {$order_expression}
            LIMIT 8";

    $result = mysqli_query($conn, $sql);

    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $recent_assessments[] = $row;
        }
    } else {
        $dashboard_notice =
            'Could not load assessment records. Please verify the assessment table schema.';
    }
} else {
    $dashboard_notice =
        'No recognized assessment table was found. Verify the table name for Needs Assessment Survey submissions.';
}

// Latest submitted Needs Assessment ID.
$latest_assessment_id = 0;

if (
    counselor_table_exists($conn, 'assessment_data') &&
    counselor_column_exists($conn, 'assessment_data', 'assessment_id') &&
    counselor_column_exists($conn, 'assessment_data', 'assessment_type')
) {
    $latest_assessment_id = counselor_count(
        $conn,
        "SELECT COALESCE(MAX(assessment_id), 0)
         FROM assessment_data
         WHERE assessment_type = 'Needs Assessment'"
    );
}

$counselor_name =
    $_SESSION['fullname'] ??
    $_SESSION['alias'] ??
    'Counselor';

$workflow_total = $pending_assessments + $reviewed_assessments;

$pending_percentage = $workflow_total > 0
    ? ($pending_assessments / $workflow_total) * 100
    : 0;

$reviewed_percentage = $workflow_total > 0
    ? ($reviewed_assessments / $workflow_total) * 100
    : 0;
    $latest_assessment_id = 0;

    if (
        counselor_table_exists($conn, 'assessment_data') &&
        counselor_column_exists($conn, 'assessment_data', 'assessment_id')
    ) {
        $latest_assessment_id = counselor_count(
            $conn,
            "SELECT COALESCE(MAX(assessment_id), 0)
            FROM assessment_data"
        );
    }
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Counselor Dashboard | PTC Wellness</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

    <link
        href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap"
        rel="stylesheet"
    >

    <link rel="stylesheet" href="../assets/css/counselor.css">
    <style>
        .recent-panel .events-table {
            table-layout: auto;
            width: 100%;
        }

        .recent-panel .events-table th,
        .recent-panel .events-table td {
            white-space: nowrap;
        }

        .recent-panel .events-table th:first-child,
        .recent-panel .events-table td:first-child {
            white-space: normal;
            min-width: 180px;
        }

        .recent-panel .review-date {
            color: #65796b;
            font-size: 0.78rem;
        }
    </style>
<style>

    /* Logout confirmation modal */
     .logout-modal {
        position: fixed;
        inset: 0;
        z-index: 99999;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 20px;
        background: rgba(15, 35, 20, .58);
        backdrop-filter: blur(5px);
        opacity: 0;
        visibility: hidden;
        transition: opacity .25s ease, visibility .25s ease;
    }

    .logout-modal.is-open {
        opacity: 1;
        visibility: visible;
    }

    .logout-dialog {
        width: 100%;
        max-width: 430px;
        padding: 32px 28px;
        border-radius: 20px;
        background: #fff;
        text-align: center;
        box-shadow: 0 24px 70px rgba(0, 0, 0, .22);
        transform: translateY(16px) scale(.96);
        transition: transform .25s ease;
    }

    .logout-modal.is-open .logout-dialog {
        transform: translateY(0) scale(1);
    }

    .logout-modal-icon {
        display: flex;
        align-items: center;
        justify-content: center;
        width: 64px;
        height: 64px;
        margin: 0 auto 20px;
        border-radius: 50%;
        background: #e8f3e7;
        color: #3f7840;
    }

    .logout-modal-icon svg {
        width: 30px;
        height: 30px;
        fill: none;
        stroke: currentColor;
        stroke-width: 1.8;
        stroke-linecap: round;
        stroke-linejoin: round;
    }

    .logout-dialog h2 {
        margin: 0 0 12px;
        color: #243629;
        font-size: 21px;
        line-height: 1.4;
    }

    .logout-dialog p {
        margin: 0 0 25px;
        color: #68736b;
        font-size: 14px;
        line-height: 1.7;
    }

    .logout-actions {
        display: flex;
        flex-direction: column;
        gap: 10px;
    }

    .logout-actions button {
        width: 100%;
        min-height: 46px;
        padding: 12px 18px;
        border: 0;
        border-radius: 10px;
        font: inherit;
        font-weight: 600;
        cursor: pointer;
        transition: background .2s ease, transform .2s ease;
    }

    .logout-confirm {
        background: #5e8b59;
        color: #fff;
    }

    .logout-confirm:hover {
        background: #497446;
    }

    .logout-cancel {
        background: #f0f3ef;
        color: #354438;
    }

    .logout-cancel:hover {
        background: #e3e9e1;
    }

    .logout-actions button:active {
        transform: scale(.98);
    }

    @media (max-width: 480px) {
        .logout-dialog {
            padding: 28px 20px;
        }

        .logout-dialog h2 {
            font-size: 19px;
        }
    }

    @media (prefers-reduced-motion: reduce) {
        .logout-modal,
        .logout-dialog,
        .logout-actions button {
            transition: none;
        }
    }
</style>
</head>

<body class="counselor-page">

<div class="app-shell">

    <aside class="sidebar">
        <a class="brand" href="dashboard.php">
            <span class="brand-mark" aria-hidden="true">
                <svg viewBox="0 0 32 32">
                    <path d="M16 27V15"></path>
                    <path d="M16 17C8 17 5 12 5 5c7 0 12 4 11 12Z"></path>
                    <path d="M16 21c0-7 4-11 11-11 0 7-4 11-11 11Z"></path>
                </svg>
            </span>

            <span class="brand-copy">
                <strong class="brand-name">PTC Wellness</strong>
                <small class="brand-tagline">Mental Health &amp; Well-being</small>
            </span>
        </a>

        <nav class="nav" aria-label="Counselor navigation">

            <a class="nav-link active" href="dashboard.php">
                <span class="nav-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24">
                        <rect x="3" y="3" width="7" height="7" rx="1"></rect>
                        <rect x="14" y="3" width="7" height="7" rx="1"></rect>
                        <rect x="3" y="14" width="7" height="7" rx="1"></rect>
                        <rect x="14" y="14" width="7" height="7" rx="1"></rect>
                    </svg>
                </span>
                Dashboard
            </a>

            <a class="nav-link" href="assessment_queue.php">
                <span class="nav-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24">
                        <path d="M8 6h13"></path>
                        <path d="M8 12h13"></path>
                        <path d="M8 18h13"></path>
                        <path d="m3 6 1 1 2-2"></path>
                        <path d="m3 12 1 1 2-2"></path>
                        <path d="m3 18 1 1 2-2"></path>
                    </svg>
                </span>
                Assessments Queue
            </a>

            <a class="nav-link" href="monitoring.php">
                <span class="nav-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24">
                        <path d="M3 12s3.2-7 9-7 9 7 9 7-3.2 7-9 7-9-7-9-7Z"></path>
                        <circle cx="12" cy="12" r="3"></circle>
                    </svg>
                </span>
                Monitoring
            </a>

            <a class="nav-link" href="referral.php">
                <span class="nav-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24">
                        <path d="M7 17 17 7"></path>
                        <path d="M7 7h10v10"></path>
                        <path d="M5 5v14h14"></path>
                    </svg>
                </span>
                Referral
            </a>

            <a class="nav-link" href="assessment_history.php">
                <span class="nav-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24">
                        <circle cx="12" cy="12" r="9"></circle>
                        <path d="M12 7v5l3 2"></path>
                    </svg>
                </span>
                Assessment History
            </a>

            <a class="nav-link" href="profile.php">
                <span class="nav-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24">
                        <circle cx="12" cy="8" r="4"></circle>
                        <path d="M4 21a8 8 0 0 1 16 0"></path>
                    </svg>
                </span>
                Profile
            </a>

            <a class="nav-link logout" href="../auth/logout.php?portal=counselor">
                <span class="nav-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24">
                        <path d="M10 17l5-5-5-5"></path>
                        <path d="M15 12H3"></path>
                        <path d="M12 3h7a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-7"></path>
                    </svg>
                </span>
                Logout
            </a>

        </nav>

        <div class="sidebar-footer">
            <strong>Well-being matters.</strong>
            <span>Supporting a healthier campus, one step at a time.</span>
        </div>
    </aside>

    <main class="main-content">

    <header class="page-header">
        <div class="page-heading">
            <p class="eyebrow">PTC WELLNESS · GUIDANCE OFFICE</p>
            <h1 class="page-title">Counselor Dashboard</h1>

            <p class="page-subtitle">
                Welcome back, <?= counselor_escape($counselor_name) ?>.
                Here's your assessment overview.
            </p>
        </div>

        <div class="header-right">
            <div class="datetime-card">
                <div class="datetime-item">
                    <span class="datetime-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24">
                            <rect x="3" y="5" width="18" height="16" rx="2"></rect>
                            <path d="M16 3v4M8 3v4M3 10h18"></path>
                        </svg>
                    </span>

                    <span id="current-date">Loading date...</span>
                </div>

                <span class="datetime-divider"></span>

                <div class="datetime-item">
                    <span class="datetime-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24">
                            <circle cx="12" cy="12" r="9"></circle>
                            <path d="M12 7v5l3 2"></path>
                        </svg>
                    </span>

                    <span id="current-time">Loading time...</span>
                </div>
            </div>

            <div class="header-status">
                <span class="status-dot"></span>
                Counselor Portal
            </div>
        </div>
    </header>

        <section class="metric-grid" aria-label="Assessment summary">

            <article class="metric-card">
                <div class="metric-top">
                    <div class="metric-label">Total Student Records</div>
                    <span class="metric-symbol">
                        <svg viewBox="0 0 24 24">
                            <path d="M16 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                            <circle cx="10" cy="7" r="4"></circle>
                            <path d="M20 21v-2a4 4 0 0 0-3-3.87"></path>
                            <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
                        </svg>
                    </span>
                </div>
                <div class="metric-value"><?= number_format($total_students) ?></div>
            </article>

            <article class="metric-card">
                <div class="metric-top">
                    <div class="metric-label">Total Assessments</div>
                    <span class="metric-symbol">
                        <svg viewBox="0 0 24 24">
                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"></path>
                            <path d="M14 2v6h6"></path>
                            <path d="M8 13h8M8 17h8"></path>
                        </svg>
                    </span>
                </div>
                <div class="metric-value"><?= number_format($total_assessments) ?></div>
            </article>

            <article class="metric-card">
                <div class="metric-top">
                    <div class="metric-label">Active Counselors</div>
                    <span class="metric-symbol">
                        <svg viewBox="0 0 24 24">
                            <path d="M16 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                            <circle cx="10" cy="7" r="4"></circle>
                            <path d="M19 8v6M16 11h6"></path>
                        </svg>
                    </span>
                </div>
                <div class="metric-value"><?= number_format($total_counselors) ?></div>
            </article>

        </section>

        <section class="system-panel workflow-panel">
            <div class="section-heading">
                <div>
                    <h2>Assessment Overview</h2>
                    <p class="section-description">
                        Monitor the review progress of submitted Needs Assessment Surveys.
                    </p>
                </div>
            </div>

            <div class="workflow-cards">
                <div class="workflow-card pending-card">
                    <span class="workflow-label">Pending Assessments</span>
                    <strong><?= number_format($pending_assessments) ?></strong>
                    <small>Awaiting counselor review</small>
                </div>

                <div class="workflow-card reviewed-card">
                    <span class="workflow-label">Reviewed Assessments</span>
                    <strong><?= number_format($reviewed_assessments) ?></strong>
                    <small>Marked as reviewed</small>
                </div>
            </div>

            <div class="progress-heading">
                <span>Review progress</span>
                <strong><?= (int) round($reviewed_percentage) ?>%</strong>
            </div>

            <div
                class="progress-track"
                role="img"
                aria-label="<?= (int) round($reviewed_percentage) ?> percent of assessments reviewed"
            >
                <span
                    class="progress-reviewed"
                    style="width: <?= number_format($reviewed_percentage, 2, '.', '') ?>%"
                ></span>
            </div>

            <div class="workflow-legend">
                <span><i class="legend-pending"></i> Pending</span>
                <span><i class="legend-reviewed"></i> Reviewed</span>
            </div>

            <p class="privacy-note">
                Review status reflects workflow progress only. It is not a clinical diagnosis
                or an automatic classification of a student's mental health.
            </p>
        </section>

        <section class="monitoring-section">
            <div class="monitoring-heading">
                <span class="section-icon">
                    <svg viewBox="0 0 24 24">
                        <path d="M4 19V5"></path>
                        <path d="M4 19h17"></path>
                        <path d="M8 15v-4M13 15V7M18 15V3"></path>
                    </svg>
                </span>

                <div>
                    <h2>Quick Actions</h2>
                    <p class="section-description">
                        Access common counselor tasks.
                    </p>
                </div>
            </div>

            <div class="monitoring-grid">

                <a class="monitoring-card action-card" href="assessment_queue.php">
                    <span class="monitoring-symbol">
                        <svg viewBox="0 0 24 24">
                            <path d="M8 6h13M8 12h13M8 18h13"></path>
                            <path d="m3 6 1 1 2-2M3 12l1 1 2-2M3 18l1 1 2-2"></path>
                        </svg>
                    </span>
                    <span class="monitoring-copy">
                        <h3>Assessments Queue</h3>
                        <strong>Review submissions</strong>
                        <p>Open submitted assessments for review.</p>
                    </span>
                </a>

                <a class="monitoring-card action-card" href="monitoring.php">
                    <span class="monitoring-symbol">
                        <svg viewBox="0 0 24 24">
                            <path d="M3 12s3-7 9-7 9 7 9 7-3 7-9 7-9-7-9-7Z"></path>
                            <circle cx="12" cy="12" r="3"></circle>
                        </svg>
                    </span>
                    <span class="monitoring-copy">
                        <h3>Student Monitoring</h3>
                        <strong>View monitoring</strong>
                        <p>Access available student monitoring records.</p>
                    </span>
                </a>

                <a class="monitoring-card action-card" href="referral.php">
                    <span class="monitoring-symbol">
                        <svg viewBox="0 0 24 24">
                            <path d="M7 17 17 7M7 7h10v10"></path>
                            <path d="M5 5v14h14"></path>
                        </svg>
                    </span>
                    <span class="monitoring-copy">
                        <h3>Referral</h3>
                        <strong>Manage referrals</strong>
                        <p>Open the referral module.</p>
                    </span>
                </a>

            </div>
        </section>

        <section class="system-panel recent-panel">
            <div class="section-heading">
                <div>
                    <h2>Recent Assessments</h2>
                    <p class="section-description">
                        Latest submissions recorded in the system.
                    </p>
                </div>

                <a class="section-link" href="assessment_history.php">
                    View history →
                </a>
            </div>

            <div class="events-table-wrap">
                <table class="events-table">
                    <thead>
                        <tr>
                            <th>Student</th>
                            <th>Assessment ID</th>
                            <th>Date Submitted</th>
                            <th>Review Status</th>
                            <th>Review Date</th>
                            <th>Action</th>
                        </tr>
                    </thead>

                    <tbody>
                    <?php if ($recent_assessments): ?>
                        <?php foreach ($recent_assessments as $item): ?>
                            <?php
                            $student_name = trim(
                                (string) ($item['student_name'] ?? '')
                            );

                            if ($student_name === '') {
                                $student_name = 'Student';
                            }

                            $assessment_id = (int) (
                                $item['assessment_id'] ?? 0
                            );

                            $status = trim(
                                (string) ($item['review_status'] ?? 'Pending')
                            );

                            $is_reviewed = strtolower($status) === 'reviewed';

                            $submitted_date = $item['submitted_date'] ?? null;

                            $formatted_date = $submitted_date
                                ? date('M d, Y · g:i A', strtotime($submitted_date))
                                : '—';

                            $review_date = $item['review_date'] ?? null;
                            $formatted_review_date = (
                                $is_reviewed &&
                                $review_date &&
                                $review_date !== '0000-00-00 00:00:00'
                            )
                                ? date('M d, Y · g:i A', strtotime($review_date))
                                : '—';
                            ?>

                            <tr>
                            <td>
                                <div class="dashboard-student">
                                    <span class="dashboard-student-avatar" aria-hidden="true">
                                        <svg viewBox="0 0 24 24">
                                            <circle cx="12" cy="8" r="4"></circle>
                                            <path d="M4 21a8 8 0 0 1 16 0"></path>
                                        </svg>
                                    </span>

                                    <span class="dashboard-student-details">
                                        <span class="dashboard-student-name">
                                            <?= counselor_escape($student_name) ?>
                                        </span>
                                        <span class="dashboard-student-id">
                                            Assessment #<?= $assessment_id ?>
                                        </span>
                                    </span>
                                </div>
                            </td>


                                <td>
                                    <?= $assessment_id > 0
                                        ? '#' . $assessment_id
                                        : '—' ?>
                                </td>

                                <td><?= counselor_escape($formatted_date) ?></td>

                                <td>
                                    <span class="review-badge <?= $is_reviewed
                                        ? 'review-badge-reviewed'
                                        : 'review-badge-pending' ?>">
                                        <?= counselor_escape($status ?: 'Pending') ?>
                                    </span>
                                </td>

                                <td>
                                    <span class="review-date">
                                        <?= counselor_escape($formatted_review_date) ?>
                                    </span>
                                </td>

                                <td>
                                    <?php if ($assessment_id > 0): ?>
                                        <a
                                            class="table-action"
                                            href="view_assessment.php?id=<?= $assessment_id ?>"
                                        >
                                            View →
                                        </a>
                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" class="empty-state">
                                No assessment records to display yet.
                            </td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <?php if ($dashboard_notice !== ''): ?>
            <div class="dashboard-notice" role="status">
                <?= counselor_escape($dashboard_notice) ?>
            </div>
        <?php endif; ?>

        <footer class="page-footer">
            <span>PTC Wellness · Counselor Portal</span>
            <span>Confidential student information — handle with care.</span>
        </footer>

    </main>
</div>


<script>
(function () {
    const dateElement = document.getElementById('current-date');
    const timeElement = document.getElementById('current-time');

    if (!dateElement || !timeElement) return;

    const dateFormatter = new Intl.DateTimeFormat('en-PH', {
        timeZone: 'Asia/Manila',
        weekday: 'long',
        month: 'short',
        day: 'numeric',
        year: 'numeric'
    });

    const timeFormatter = new Intl.DateTimeFormat('en-PH', {
        timeZone: 'Asia/Manila',
        hour: '2-digit',
        minute: '2-digit',
        hour12: true
    });

    function updateDateTime() {
        const now = new Date();

        dateElement.textContent = dateFormatter.format(now);
        timeElement.textContent = timeFormatter.format(now);
    }

    updateDateTime();
    setInterval(updateDateTime, 1000);
})();
</script>

<div class="logout-modal" id="logout-modal" aria-hidden="true">
    <section class="logout-dialog"
             role="dialog"
             aria-modal="true"
             aria-labelledby="logout-title"
             aria-describedby="logout-description">

        <div class="logout-modal-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24">
                <path d="M10 17l5-5-5-5"></path>
                <path d="M15 12H3"></path>
                <path d="M12 3h7a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-7"></path>
            </svg>
        </div>

        <h2 id="logout-title">Are you sure you want to log out?</h2>

        <p id="logout-description">
            You may lose any unsaved changes. Please make sure
            you've saved your work before leaving.
        </p>

        <div class="logout-actions">
            <button type="button"
                    class="logout-confirm"
                    id="confirm-logout">
                Yes, Log Out
            </button>

            <button type="button"
                    class="logout-cancel"
                    id="cancel-logout">
                Go Back
            </button>
        </div>
    </section>
</div>
<script>
(function () {
    const modal = document.getElementById('logout-modal');
    const cancelButton = document.getElementById('cancel-logout');
    const confirmButton = document.getElementById('confirm-logout');
    const logoutLink = document.querySelector('.nav-link.logout');
    if (!modal || !cancelButton || !confirmButton || !logoutLink) return;

    let previousFocus = null;

    function openModal(event) {
        event.preventDefault();
        previousFocus = document.activeElement;
        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
        cancelButton.focus();
    }

    function closeModal() {
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
        if (previousFocus) previousFocus.focus();
    }

    logoutLink.addEventListener('click', openModal);
    cancelButton.addEventListener('click', closeModal);

    confirmButton.addEventListener('click', function () {
        window.location.href = '../auth/logout.php?portal=counselor';
    });

    modal.addEventListener('click', function (event) {
        if (event.target === modal) closeModal();
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && modal.classList.contains('is-open')) {
            closeModal();
        }
    });
})();
</script>

<script>
(function () {

    const initialLatestId = <?= (int) $latest_assessment_id ?>;

    let checking = false;
    let newAssessmentDetected = false;

    async function checkNewAssessments() {

        if (
            checking ||
            newAssessmentDetected ||
            document.hidden
        ) {
            return;
        }

        checking = true;

        try {

            const response = await fetch(
                'check_new_assessments.php',
                {
                    method: 'GET',
                    cache: 'no-store',
                    credentials: 'same-origin',
                    headers: {
                        'Accept': 'application/json'
                    }
                }
            );

            if (!response.ok) {
                throw new Error('Unable to check assessments.');
            }

            const data = await response.json();

            if (
                data.success &&
                Number(data.latest_id) > initialLatestId
            ) {
                newAssessmentDetected = true;

            }

        } catch (error) {
            console.error('Assessment update check failed:', error);

        } finally {
            checking = false;
        }
    }

    setInterval(checkNewAssessments, 8000);

})();


(function () {
    let lastAssessmentId = <?= (int) $latest_assessment_id ?>;
    let checking = false;

    async function checkForNewAssessments() {
        if (checking || document.hidden) return;

        checking = true;

        try {
            const response = await fetch(
                'check_new_assessments.php',
                {
                    cache: 'no-store',
                    credentials: 'same-origin'
                }
            );

            if (!response.ok) return;

            const data = await response.json();

            if (
                !data.success ||
                Number(data.latest_id) <= lastAssessmentId
            ) {
                return;
            }

            // Get updated dashboard HTML without reloading this page.
            const pageResponse = await fetch(window.location.href, {
                cache: 'no-store',
                credentials: 'same-origin'
            });

            if (!pageResponse.ok) return;

            const html = await pageResponse.text();
            const parser = new DOMParser();
            const newPage = parser.parseFromString(html, 'text/html');

            const currentPanel = document.querySelector('.recent-panel');
            const updatedPanel = newPage.querySelector('.recent-panel');

            if (currentPanel && updatedPanel) {
                currentPanel.replaceWith(updatedPanel);
                lastAssessmentId = Number(data.latest_id);
            }

        } catch (error) {
            console.error('Live assessment update:', error);
        } finally {
            checking = false;
        }
    }

    setInterval(checkForNewAssessments, 5000);
})();


</script>

</body>
</html>


</body>
</html>
