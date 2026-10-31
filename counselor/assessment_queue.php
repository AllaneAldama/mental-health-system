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

function queue_table_exists($conn, $table)
{
    $table = mysqli_real_escape_string($conn, $table);
    $result = mysqli_query($conn, "SHOW TABLES LIKE '{$table}'");
    return $result && mysqli_num_rows($result) > 0;
}

function queue_column_exists($conn, $table, $column)
{
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
        return false;
    }

    $column = mysqli_real_escape_string($conn, $column);
    $result = mysqli_query(
        $conn,
        "SHOW COLUMNS FROM `{$table}` LIKE '{$column}'"
    );

    return $result && mysqli_num_rows($result) > 0;
}

function queue_first_column($conn, $table, $candidates)
{
    foreach ($candidates as $column) {
        if (queue_column_exists($conn, $table, $column)) {
            return $column;
        }
    }

    return null;
}

function queue_has_scheduled_appointment($conn, $assessment_id)
{
    if (
        !queue_table_exists($conn, 'referral_data') ||
        !queue_table_exists($conn, 'schedule_data') ||
        !queue_column_exists($conn, 'referral_data', 'assessment_id') ||
        !queue_column_exists($conn, 'referral_data', 'referral_id') ||
        !queue_column_exists($conn, 'schedule_data', 'referral_id')
    ) {
        return false;
    }

    $sql = "SELECT s.schedule_id
            FROM referral_data r
            INNER JOIN schedule_data s
                ON s.referral_id = r.referral_id
            WHERE r.assessment_id = ?
            ORDER BY s.schedule_id DESC
            LIMIT 1";

    $stmt = mysqli_prepare($conn, $sql);

    if (!$stmt) {
        return false;
    }

    mysqli_stmt_bind_param($stmt, 'i', $assessment_id);
    mysqli_stmt_execute($stmt);

    $result = mysqli_stmt_get_result($stmt);
    $has_schedule = $result && mysqli_num_rows($result) > 0;

    mysqli_stmt_close($stmt);

    return $has_schedule;
}

function queue_escape($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/**
 * Convert recognized Likert answers to numeric scores.
 * Only valid integer scores from 1 through 5 are accepted.
 */
function queue_response_score($numeric, $text)
{
    $text = trim((string)$text);

    if (
        $numeric !== null &&
        $numeric !== '' &&
        is_numeric($numeric) &&
        (float)$numeric >= 1 &&
        (float)$numeric <= 5 &&
        floor((float)$numeric) == (float)$numeric
    ) {
        return (int)$numeric;
    }

    if (preg_match('/^[1-5]$/', $text)) {
        return (int)$text;
    }

    $labels = [
        'strongly disagree' => 1,
        'disagree' => 2,
        'neutral' => 3,
        'neither agree nor disagree' => 3,
        'agree' => 4,
        'strongly agree' => 5
    ];

    $normalized = strtolower($text);

    return $labels[$normalized] ?? null;
}

/**
 * Calculate a provisional priority from valid Likert responses.
 * Returns priority, average, and valid response count.
 */
function queue_calculate_priority($conn, $assessment_id)
{
    $result_data = [
        'priority' => null,
        'average' => null,
        'count' => 0
    ];

    if (
        !queue_table_exists($conn, 'response_data') ||
        !queue_table_exists($conn, 'question_data') ||
        !queue_column_exists($conn, 'response_data', 'assessment_id') ||
        !queue_column_exists($conn, 'response_data', 'question_id') ||
        !queue_column_exists($conn, 'question_data', 'question_id')
    ) {
        return $result_data;
    }

    $numeric_col = queue_first_column(
        $conn,
        'response_data',
        ['answer_score', 'answer_value', 'score']
    );

    $text_col = queue_first_column(
        $conn,
        'response_data',
        ['answer_text', 'response_text', 'answer']
    );

    if (!$numeric_col && !$text_col) {
        return $result_data;
    }

    $numeric_select = $numeric_col
        ? "r.`{$numeric_col}` AS numeric_answer"
        : "NULL AS numeric_answer";

    $text_select = $text_col
        ? "r.`{$text_col}` AS text_answer"
        : "NULL AS text_answer";

    $sql = "SELECT {$numeric_select}, {$text_select}
            FROM response_data r
            INNER JOIN question_data q
                ON q.question_id = r.question_id
            WHERE r.assessment_id = ?";

    $stmt = mysqli_prepare($conn, $sql);

    if (!$stmt) {
        return $result_data;
    }

    mysqli_stmt_bind_param($stmt, 'i', $assessment_id);

    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        return $result_data;
    }

    $result = mysqli_stmt_get_result($stmt);
    $scores = [];

    while ($row = mysqli_fetch_assoc($result)) {
        $score = queue_response_score(
            $row['numeric_answer'] ?? null,
            $row['text_answer'] ?? ''
        );

        if ($score !== null) {
            $scores[] = $score;
        }
    }

    mysqli_stmt_close($stmt);

    if (!$scores) {
        return $result_data;
    }

    $average = array_sum($scores) / count($scores);

    if ($average >= 4.00) {
        $priority = 'High';
    } elseif ($average >= 3.00) {
        $priority = 'Moderate';
    } else {
        $priority = 'Low';
    }

    return [
        'priority' => $priority,
        'average' => $average,
        'count' => count($scores)
    ];
}

function queue_priority_class($priority)
{
    switch ($priority) {
        case 'Low':
            return 'priority-low';
        case 'Moderate':
            return 'priority-moderate';
        case 'High':
            return 'priority-high';
        default:
            return 'priority-unrated';
    }
}

/* Detect assessment table */
$assessment_table = null;

foreach ([
    'assessment_data',
    'needs_assessment',
    'needs_assessment_responses',
    'assessment_submissions',
    'assessment_records',
    'assessments'
] as $candidate) {
    if (queue_table_exists($conn, $candidate)) {
        $assessment_table = $candidate;
        break;
    }
}

$assessments = [];
$total_count = 0;
$pending_count = 0;
$reviewed_count = 0;
$queue_error = '';

$search = trim($_GET['q'] ?? '');
$filter_status = trim($_GET['status'] ?? '');
$filter_priority = trim($_GET['priority'] ?? '');
$sort_by = trim($_GET['sort'] ?? 'recent');

$status_options = ['Pending', 'Reviewed'];
$priority_options = ['Low', 'Moderate', 'High', 'Not rated'];

if (!in_array($filter_priority, ['', 'Low', 'Moderate', 'High', 'Not rated'], true)) {
    $filter_priority = '';
}

if (!in_array($filter_status, ['', 'Pending', 'Reviewed'], true)) {
    $filter_status = '';
}

if (!in_array($sort_by, ['recent', 'oldest'], true)) {
    $sort_by = 'recent';
}

if ($assessment_table) {
    $id_column = queue_first_column(
        $conn,
        $assessment_table,
        ['assessment_id', 'id', 'submission_id', 'record_id']
    );

    $user_column = queue_first_column(
        $conn,
        $assessment_table,
        ['user_id', 'student_id']
    );

    $date_column = queue_first_column(
        $conn,
        $assessment_table,
        [
            'assessment_date',
            'submitted_at',
            'created_at',
            'date_submitted'
        ]
    );

    $status_column = queue_first_column(
        $conn,
        $assessment_table,
        ['status', 'review_status', 'submission_status']
    );

    if (!$id_column || !$user_column || !$date_column) {
        $queue_error = 'The assessment table is missing an identifiable record ID, student ID, or submission date.';
    } elseif (!queue_table_exists($conn, 'user_data')) {
        $queue_error = 'The student account table user_data could not be found.';
    } else {
        $name_column = queue_first_column(
            $conn,
            'user_data',
            ['alias', 'fullname', 'student_number']
        );

        $student_expression = $name_column
            ? "COALESCE(NULLIF(u.`{$name_column}`, ''), 'Student')"
            : "'Student'";

        if (
            $name_column === 'alias' &&
            queue_column_exists($conn, 'user_data', 'fullname')
        ) {
            $student_expression =
                "COALESCE(NULLIF(u.alias, ''), NULLIF(u.fullname, ''), 'Student')";
        }

        $select_status = $status_column
            ? "a.`{$status_column}` AS review_status"
            : "'Pending' AS review_status";

        $sql = "SELECT
                    a.`{$id_column}` AS assessment_id,
                    a.`{$date_column}` AS submitted_at,
                    {$select_status},
                    {$student_expression} AS student_name
                FROM `{$assessment_table}` a
                LEFT JOIN user_data u
                    ON u.user_id = a.`{$user_column}`";

        $where = [];
        $params = [];
        $types = '';

        if ($search !== '') {
            $search_conditions = [];
            $searchable_columns = [];

            foreach (['fullname', 'alias', 'student_number', 'email'] as $column) {
                if (queue_column_exists($conn, 'user_data', $column)) {
                    $searchable_columns[] = "u.`{$column}`";
                }
            }

            foreach ($searchable_columns as $column) {
                $search_conditions[] = "{$column} LIKE ?";
                $params[] = '%' . $search . '%';
                $types .= 's';
            }

            if (ctype_digit($search)) {
                $search_conditions[] = "a.`{$id_column}` = ?";
                $params[] = (int)$search;
                $types .= 'i';
            }

            if ($search_conditions) {
                $where[] = '(' . implode(' OR ', $search_conditions) . ')';
            }
        }

        if ($status_column && $filter_status !== '') {
            $where[] = "a.`{$status_column}` = ?";
            $params[] = $filter_status;
            $types .= 's';
        } elseif ($status_column) {
            $where[] = "(
                a.`{$status_column}` IN ('Pending', 'Reviewed')
                OR a.`{$status_column}` IS NULL
                OR a.`{$status_column}` = ''
            )";
        }

        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $direction = $sort_by === 'oldest' ? 'ASC' : 'DESC';

        $sql .= " ORDER BY a.`{$date_column}` {$direction},
                           a.`{$id_column}` {$direction}";

        $stmt = mysqli_prepare($conn, $sql);

        if (!$stmt) {
            $queue_error = 'The assessment query could not be prepared.';
        } else {
            if ($types !== '') {
                mysqli_stmt_bind_param($stmt, $types, ...$params);
            }

            if (mysqli_stmt_execute($stmt)) {
                $result = mysqli_stmt_get_result($stmt);

                while ($row = mysqli_fetch_assoc($result)) {
                    $status = trim((string)($row['review_status'] ?? ''));

                    $row['review_status'] = $status !== ''
                        ? $status
                        : 'Pending';

                    $assessments[] = $row;
                }
            } else {
                $queue_error = 'Assessment records could not be loaded.';
            }

            mysqli_stmt_close($stmt);
        }

        /* Calculate priority and average for every matching assessment. */
        foreach ($assessments as &$item) {
            $scoring = queue_calculate_priority(
                $conn,
                (int)$item['assessment_id']
            );

            $item['priority'] = $scoring['priority'];
            $item['average'] = $scoring['average'];
            $item['valid_response_count'] = $scoring['count'];
            $item['schedule_status'] = queue_has_scheduled_appointment(
                $conn,
                (int)$item['assessment_id']
            ) ? 'Scheduled' : 'Not Scheduled';
        }

        unset($item);

        /* Apply priority filter after calculating each assessment. */
        if ($filter_priority !== '') {
            $assessments = array_values(
                array_filter(
                    $assessments,
                    function ($item) use ($filter_priority) {
                        if ($filter_priority === 'Not rated') {
                            return $item['priority'] === null;
                        }

                        return $item['priority'] === $filter_priority;
                    }
                )
            );
        }

        $total_count = count($assessments);

        foreach ($assessments as $item) {
            if (strcasecmp($item['review_status'], 'Pending') === 0) {
                $pending_count++;
            } elseif (strcasecmp($item['review_status'], 'Reviewed') === 0) {
                $reviewed_count++;
            }
        }
    }
} else {
    $queue_error = 'No recognized assessment table was found. Verify the table used by the Needs Assessment Survey.';
}
$latest_assessment_id = 0;

if (
    queue_table_exists($conn, 'assessment_data') &&
    queue_column_exists($conn, 'assessment_data', 'assessment_id') &&
    queue_column_exists($conn, 'assessment_data', 'assessment_type')
) {
    $result = mysqli_query(
        $conn,
        "SELECT COALESCE(MAX(assessment_id), 0) AS latest_id
         FROM assessment_data
         WHERE assessment_type = 'Needs Assessment'"
    );

    if ($result) {
        $row = mysqli_fetch_assoc($result);

        $latest_assessment_id = (int) ($row['latest_id'] ?? 0);
    }
}
    $latest_assessment_id = 0;

    if (
        queue_table_exists($conn, 'assessment_data') &&
        queue_column_exists($conn, 'assessment_data', 'assessment_id')
    ) {
        $result = mysqli_query(
            $conn,
            "SELECT COALESCE(MAX(assessment_id), 0) AS latest_id
            FROM assessment_data"
        );

        if ($result) {
            $row = mysqli_fetch_assoc($result);
            $latest_assessment_id = (int) ($row['latest_id'] ?? 0);
        }
    }

$counselor_name = $_SESSION['fullname']
    ?? $_SESSION['alias']
    ?? 'Counselor';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Assessment Queue | PTC Wellness</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="../assets/css/counselor.css">
    <link rel="stylesheet" href="../assets/css/assessment-queue.css">
</head>
<body class="counselor-page">
<div class="app-shell">

    <!-- Existing sidebar retained -->
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
            <a class="nav-link" href="dashboard.php">
                <span class="nav-icon"><svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1"></rect><rect x="14" y="3" width="7" height="7" rx="1"></rect><rect x="3" y="14" width="7" height="7" rx="1"></rect><rect x="14" y="14" width="7" height="7" rx="1"></rect></svg></span>
                Dashboard
            </a>
            <a class="nav-link active" href="assessment_queue.php">
                <span class="nav-icon"><svg viewBox="0 0 24 24"><path d="M8 6h13M8 12h13M8 18h13"></path><path d="m3 6 1 1 2-2M3 12l1 1 2-2M3 18l1 1 2-2"></path></svg></span>
                Assessments Queue
            </a>
            <a class="nav-link" href="monitoring.php">
                <span class="nav-icon"><svg viewBox="0 0 24 24"><path d="M3 12s3-7 9-7 9 7 9 7-3 7-9 7-9-7-9-7Z"></path><circle cx="12" cy="12" r="3"></circle></svg></span>
                Monitoring
            </a>
            <a class="nav-link" href="referral.php">
                <span class="nav-icon"><svg viewBox="0 0 24 24"><path d="M7 17 17 7M7 7h10v10"></path><path d="M5 5v14h14"></path></svg></span>
                Referral
            </a>
            <a class="nav-link" href="assessment_history.php">
                <span class="nav-icon"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"></circle><path d="M12 7v5l3 2"></path></svg></span>
                Assessment History
            </a>
            <a class="nav-link" href="profile.php">
                <span class="nav-icon"><svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"></circle><path d="M4 21a8 8 0 0 1 16 0"></path></svg></span>
                Profile
            </a>
            <a class="nav-link logout" href="../auth/logout.php?portal=counselor">
                <span class="nav-icon"><svg viewBox="0 0 24 24"><path d="M10 17l5-5-5-5M15 12H3"></path><path d="M12 3h7a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-7"></path></svg></span>
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
                <h1 class="page-title">Assessment Queue</h1>
                <p class="page-subtitle">
                    Welcome back, <?= queue_escape($counselor_name) ?>.
                    Review submitted Needs Assessment Surveys.
                </p>
            </div>

            <div class="header-right">
                <div class="datetime-card">
                    <div class="datetime-item">
                        <span class="datetime-icon"><svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="16" rx="2"></rect><path d="M16 3v4M8 3v4M3 10h18"></path></svg></span>
                        <span id="current-date">Loading date...</span>
                    </div>
                    <span class="datetime-divider"></span>
                    <div class="datetime-item">
                        <span class="datetime-icon"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"></circle><path d="M12 7v5l3 2"></path></svg></span>
                        <span id="current-time">Loading time...</span>
                    </div>
                </div>
                <div class="header-status"><span class="status-dot"></span>Counselor Portal</div>
            </div>
        </header>

        <section class="queue-summary-grid">
            <article class="queue-summary-card">
                <span class="summary-label">Matching Records</span>
                <strong><?= number_format($total_count) ?></strong>
                <span class="summary-caption">Based on your current filters</span>
            </article>
            <article class="queue-summary-card summary-pending">
                <span class="summary-label">Pending</span>
                <strong><?= number_format($pending_count) ?></strong>
                <span class="summary-caption">Awaiting counselor review</span>
            </article>
            <article class="queue-summary-card summary-reviewed">
                <span class="summary-label">Reviewed</span>
                <strong><?= number_format($reviewed_count) ?></strong>
                <span class="summary-caption">Marked as reviewed</span>
            </article>
        </section>

        <section class="queue-panel">
            <div class="queue-panel-heading">
                <div>
                    <h2>Submitted Assessments</h2>
                    <p>Search, filter, and open student submissions.</p>
                </div>
            </div>

            <form method="GET" action="assessment_queue.php" class="queue-filters">
                <label class="queue-search">
                    <svg viewBox="0 0 24 24" aria-hidden="true">
                        <circle cx="11" cy="11" r="7"></circle>
                        <path d="m16 16 4 4"></path>
                    </svg>
                    <input type="search" name="q"
                        placeholder="Search student name, number, email, or assessment ID..."
                        value="<?= queue_escape($search) ?>">
                </label>

                <label class="filter-select">
                    <span class="sr-only">Filter by review status</span>
                    <select name="status">
                        <option value="">All Statuses</option>
                        <?php foreach ($status_options as $option): ?>
                            <option value="<?= queue_escape($option) ?>" <?= $filter_status === $option ? 'selected' : '' ?>>
                                <?= queue_escape($option) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label class="filter-select">
                    <span class="sr-only">Filter by priority</span>
                    <select name="priority">
                        <option value="">All Priorities</option>
                        <option value="Low" <?= $filter_priority === 'Low' ? 'selected' : '' ?>>Low Priority</option>
                        <option value="Moderate" <?= $filter_priority === 'Moderate' ? 'selected' : '' ?>>Moderate Priority</option>
                        <option value="High" <?= $filter_priority === 'High' ? 'selected' : '' ?>>High Priority</option>
                        <option value="Not rated" <?= $filter_priority === 'Not rated' ? 'selected' : '' ?>>Not Rated</option>
                    </select>
                </label>

                <label class="filter-select">
                    <span class="sr-only">Sort submissions</span>
                    <select name="sort">
                        <option value="recent" <?= $sort_by === 'recent' ? 'selected' : '' ?>>Most Recent</option>
                        <option value="oldest" <?= $sort_by === 'oldest' ? 'selected' : '' ?>>Oldest First</option>
                    </select>
                </label>

                <button type="submit" class="queue-search-button">Apply</button>
                <a href="assessment_queue.php" class="queue-reset-button">Reset</a>
            </form>

            <div class="priority-legend">
                <span><b>Low:</b> average below 3.00</span>
                <span><b>Moderate:</b> average 3.00–3.99</span>
                <span><b>High:</b> average 4.00–5.00</span>
                <span><b>Not Rated:</b> no valid Likert answers</span>
            </div>

            <?php if ($queue_error !== ''): ?>
                <div class="queue-alert">
                    <strong>Unable to display the queue</strong>
                    <p><?= queue_escape($queue_error) ?></p>
                </div>
            <?php elseif ($assessments): ?>
                <div class="queue-table-wrap">
                    <table class="queue-table" style="width:100%; min-width:1050px; table-layout:fixed;">
                        <colgroup>
                            <col class="col-student" style="width:19%">
                            <col class="col-assessment" style="width:9%">
                            <col class="col-date" style="width:15%">
                            <col class="col-status" style="width:11%">
                            <col class="col-schedule" style="width:12%">
                            <col class="col-priority" style="width:11%">
                            <col class="col-action" style="width:23%">
                        </colgroup>
                        <thead>
                            <tr>
                                <th scope="col">Student</th>
                                <th scope="col">Assessment ID</th>
                                <th scope="col">Date Submitted</th>
                                <th scope="col" class="center-cell">Review Status</th>
                                <th scope="col" class="center-cell">Schedule Status</th>
                                <th scope="col" class="center-cell">Priority</th>
                                <th scope="col" class="center-cell">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($assessments as $item): ?>
                                <?php
                                    $student_name = trim((string)($item['student_name'] ?? 'Student'));
                                    $assessment_id = (int)$item['assessment_id'];
                                    $status = trim((string)($item['review_status'] ?? 'Pending'));
                                    $is_reviewed = strcasecmp($status, 'Reviewed') === 0;
                                    $timestamp = !empty($item['submitted_at']) ? strtotime($item['submitted_at']) : false;
                                    $date_label = $timestamp ? date('M d, Y', $timestamp) : '—';
                                    $time_label = $timestamp ? date('h:i A', $timestamp) : '';
                                    $initial = strtoupper(function_exists('mb_substr') ? mb_substr($student_name, 0, 1) : substr($student_name, 0, 1));
                                    $priority = $item['priority'];
                                    $priority_class = queue_priority_class($priority);
                                ?>
                                <tr>
                                    <td>
                                        <div class="queue-student">
                                            <span class="queue-avatar" aria-hidden="true"><?= queue_escape($initial) ?></span>
                                            <span class="queue-student-name"><?= queue_escape($student_name) ?></span>
                                        </div>
                                    </td>
                                    <td><span class="assessment-id">#<?= $assessment_id ?></span></td>
                                    <td>
                                        <span class="submitted-date"><?= queue_escape($date_label) ?></span>
                                        <?php if ($time_label !== ''): ?>
                                            <span class="submitted-time"><?= queue_escape($time_label) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="center-cell">
                                        <span class="queue-status <?= $is_reviewed ? 'queue-status-reviewed' : 'queue-status-pending' ?>">
                                            <span class="queue-status-dot"></span>
                                            <?= queue_escape($status) ?>
                                        </span>
                                    </td>
                                    <td class="center-cell">
                                        <?php if (($item['schedule_status'] ?? '') === 'Scheduled'): ?>
                                            <span class="queue-status queue-status-reviewed">
                                                <span class="queue-status-dot"></span>
                                                Scheduled
                                            </span>
                                        <?php else: ?>
                                            <span class="queue-status queue-status-pending">
                                                <span class="queue-status-dot"></span>
                                                Not Scheduled
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="center-cell">
                                        <div class="queue-priority-cell">
                                            <span class="priority-badge <?= queue_escape($priority_class) ?>">
                                                <?= queue_escape($priority ?? 'Not Rated') ?>
                                            </span>
                                            <span class="priority-average">
                                                <?= $item['average'] !== null ? number_format($item['average'], 2) . '/5' : 'No score' ?>
                                            </span>
                                        </div>
                                    </td>
                                    <td class="center-cell queue-action-cell">
                                        <a class="queue-view-button" href="view_assessment.php?id=<?= $assessment_id ?>">
                                            <span>View Assessment</span><span aria-hidden="true">→</span>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="queue-table-footer">
                    <span>
                        Showing <?= number_format(count($assessments)) ?>
                        matching record<?= count($assessments) === 1 ? '' : 's' ?>
                    </span>
                    <span>Student assessment information is confidential.</span>
                </div>
            <?php else: ?>
                <div class="queue-empty-state">
                    <div class="queue-empty-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24">
                            <path d="M4 4h16v16H4z"></path>
                            <path d="M8 9h8M8 13h5"></path>
                            <path d="m15 17 2 2 4-4"></path>
                        </svg>
                    </div>
                    <h3>No assessments found</h3>
                    <p>Try changing your search or filters. Submitted Needs Assessment Surveys will appear here.</p>
                    <a class="queue-reset-button" href="assessment_queue.php">Clear Filters</a>
                </div>
            <?php endif; ?>

            <p class="queue-privacy-note">
                Priority is a provisional workflow indicator based on available Likert responses.
                It is not a clinical diagnosis or validated clinical risk level.
            </p>
        </section>

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
        weekday: 'short',
        month: 'short',
        day: 'numeric',
        year: 'numeric'
    });

    const timeFormatter = new Intl.DateTimeFormat('en-PH', {
        timeZone: 'Asia/Manila',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
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

<!-- Logout Confirmation Modal -->
<style>
    .logout-overlay {
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

    .logout-overlay.is-open {
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

    .logout-overlay.is-open .logout-dialog {
        transform: translateY(0) scale(1);
    }

    .logout-icon {
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

    .logout-icon svg {
        width: 30px;
        height: 30px;
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
</style>

<div class="logout-overlay" id="logout-modal" aria-hidden="true">
    <section class="logout-dialog"
             role="dialog"
             aria-modal="true"
             aria-labelledby="logout-title"
             aria-describedby="logout-description">

        <div class="logout-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24"
                 fill="none"
                 stroke="currentColor"
                 stroke-width="1.8"
                 stroke-linecap="round"
                 stroke-linejoin="round">
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

    if (!modal || !cancelButton || !confirmButton) return;

    const logoutLinks = document.querySelectorAll(
        'a[href*="logout.php"]'
    );

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

    logoutLinks.forEach(function (link) {
        link.addEventListener('click', openModal);
    });

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

                window.location.reload();
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

            // Fetch the current queue, including its active filters.
            const pageResponse = await fetch(window.location.href, {
                cache: 'no-store',
                credentials: 'same-origin'
            });

            if (!pageResponse.ok) return;

            const html = await pageResponse.text();
            const parser = new DOMParser();
            const newPage = parser.parseFromString(html, 'text/html');

            const selectors = [
                '.queue-summary-grid',
                '.queue-panel'
            ];

            let updated = true;

            selectors.forEach(function (selector) {
                const current = document.querySelector(selector);
                const replacement = newPage.querySelector(selector);

                if (current && replacement) {
                    current.replaceWith(replacement);
                } else {
                    updated = false;
                }
            });

            if (updated) {
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