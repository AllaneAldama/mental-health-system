<?php
require_once __DIR__ . "/../includes/counselor_session.php";

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

    $schedule_status_column = queue_column_exists($conn, 'schedule_data', 'status')
        ? 's.status'
        : "'Pending'";

    $sql = "SELECT s.schedule_id, {$schedule_status_column} AS schedule_status
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
    $row = $result ? mysqli_fetch_assoc($result) : null;

    mysqli_stmt_close($stmt);

    if (!$row) {
        return 'Not Scheduled';
    }

    $status = trim((string)($row['schedule_status'] ?? 'Pending'));
    if (strcasecmp($status, 'Completed') === 0) {
        return 'Completed';
    }
    if (strcasecmp($status, 'Cancelled') === 0) {
        return 'Cancelled';
    }
    return 'Scheduled';
}

function queue_latest_referral_status($conn, $assessment_id)
{
    if (
        !queue_table_exists($conn, 'referral_data') ||
        !queue_column_exists($conn, 'referral_data', 'assessment_id') ||
        !queue_column_exists($conn, 'referral_data', 'referral_id') ||
        !queue_column_exists($conn, 'referral_data', 'referral_status')
    ) {
        return 'No Referral';
    }

    $sql = "SELECT referral_status
            FROM referral_data
            WHERE assessment_id = ?
            ORDER BY referral_id DESC
            LIMIT 1";
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        return 'No Referral';
    }

    mysqli_stmt_bind_param($stmt, 'i', $assessment_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = $result ? mysqli_fetch_assoc($result) : null;
    mysqli_stmt_close($stmt);

    $status = trim((string)($row['referral_status'] ?? ''));
    return $status !== '' ? $status : 'No Referral';
}

function queue_referral_status_class($status)
{
    switch (strtolower((string)$status)) {
        case 'accepted':
        case 'completed':
            return 'queue-status-reviewed';
        case 'pending':
            return 'queue-status-pending';
        default:
            return 'queue-status-pending';
    }
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
        ['student_id']
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
    } elseif (!queue_table_exists($conn, 'student_profiles')) {
        $queue_error = 'The student profiles table could not be found.';
    } else {
        $name_column = queue_first_column(
            $conn,
            'student_profiles',
            ['alias', 'fullname', 'student_number']
        );

        $student_expression = $name_column
            ? "COALESCE(NULLIF(u.`{$name_column}`, ''), 'Student')"
            : "'Student'";

        if (
            $name_column === 'alias' &&
            queue_column_exists($conn, 'student_profiles', 'fullname')
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
                LEFT JOIN student_profiles u
                    ON u.student_id = a.`{$user_column}`";

        $where = [];
        $params = [];
        $types = '';

        if ($search !== '') {
            $search_conditions = [];
            $searchable_columns = [];

            foreach (['fullname', 'alias', 'student_number', 'email'] as $column) {
                if (queue_column_exists($conn, 'student_profiles', $column)) {
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
            );
            $item['referral_status'] = queue_latest_referral_status(
                $conn,
                (int)$item['assessment_id']
            );
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

    <link rel="stylesheet" href="../assets/css/counselor/counselor-base.css?v=<?= (int) (@filemtime(__DIR__ . "/../assets/css/counselor/counselor-base.css") ?: 8) ?>">
    <link rel="stylesheet" href="../assets/css/counselor/counselor-layout.css?v=<?= (int) (@filemtime(__DIR__ . "/../assets/css/counselor/counselor-layout.css") ?: 12) ?>">
    <link rel="stylesheet" href="../assets/css/counselor/assessment-queue.css?v=<?= (int) (@filemtime(__DIR__ . '/../assets/css/counselor/assessment-queue.css') ?: 9) ?>">
    <link rel="stylesheet" href="../assets/css/counselor/assessment-queue-mobile.css?v=<?= (int) (@filemtime(__DIR__ . '/../assets/css/counselor/assessment-queue-mobile.css') ?: 9) ?>" media="(max-width: 900px)">
<style>
/* Guardrail: never allow raw unstyled date, summary, search or empty-state SVGs. */
.counselor-page .datetime-icon svg { width:19px; height:19px; display:block; fill:none; stroke:currentColor; stroke-width:1.8; }
.counselor-page .summary-symbol svg { width:21px; height:21px; display:block; fill:none; stroke:currentColor; stroke-width:1.8; }
.counselor-page .queue-search svg { width:18px; height:18px; flex:0 0 18px; fill:none; stroke:currentColor; stroke-width:1.8; }
.counselor-page .queue-empty-icon svg { width:23px; height:23px; fill:none; stroke:currentColor; stroke-width:1.8; }
</style>
</head>
<body class="counselor-page queue-page">
<?php include __DIR__ . "/../includes/counselor/sidebar.php"; ?>
<div class="counselor-workspace">
    <?php include __DIR__ . "/../includes/counselor/topbar.php"; ?>
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
                <span class="summary-symbol" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="m3 8 9-5 9 5-9 5-9-5Zm0 5 9 5 9-5M3 18l9 5 9-5"/></svg></span>
                <strong><?= number_format($total_count) ?></strong>
                <span class="summary-caption">Based on your current filters</span>
            </article>
            <article class="queue-summary-card summary-pending">
                <span class="summary-label">Pending</span>
                <span class="summary-symbol" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l4 2"/></svg></span>
                <strong><?= number_format($pending_count) ?></strong>
                <span class="summary-caption">Awaiting counselor review</span>
            </article>
            <article class="queue-summary-card summary-reviewed">
                <span class="summary-label">Reviewed</span>
                <span class="summary-symbol" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="m8 12 3 3 5-6"/></svg></span>
                <strong><?= number_format($reviewed_count) ?></strong>
                <span class="summary-caption">Marked as reviewed</span>
            </article>
        </section>

        <section class="queue-panel">
            <div class="queue-controls">
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

            </div><!-- /.queue-controls -->
            <div class="queue-records">
                <div class="queue-records-heading">
                    <div><h2>Assessment Records</h2><p>Student assessment submissions and current workflow status</p></div>
                    <span class="queue-record-count">Showing <?= number_format($total_count) ?> record<?= $total_count === 1 ? '' : 's' ?></span>
                </div>
            <?php if ($queue_error !== ''): ?>
                <div class="queue-alert">
                    <strong>Unable to display the queue</strong>
                    <p><?= queue_escape($queue_error) ?></p>
                </div>
            <?php elseif ($assessments): ?>
                <div class="queue-table-wrap">
                    <table class="queue-table">
                        <colgroup>
                            <col class="col-student">
                            <col class="col-assessment">
                            <col class="col-date">
                            <col class="col-status">
                            <col class="col-referral">
                            <col class="col-schedule">
                            <col class="col-priority">
                            <col class="col-action">
                        </colgroup>
                        <thead>
                            <tr>
                                <th scope="col">Student</th>
                                <th scope="col">Assessment ID</th>
                                <th scope="col">Date Submitted</th>
                                <th scope="col" class="center-cell">Review Status</th>
                                <th scope="col" class="center-cell">Referral Status</th>
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
                                    <td data-label="Student">
                                        <div class="queue-student">
                                            <span class="queue-avatar" aria-hidden="true"><?= queue_escape($initial) ?></span>
                                            <span class="queue-student-name"><?= queue_escape($student_name) ?></span>
                                        </div>
                                    </td>
                                    <td data-label="Assessment ID"><span class="assessment-id">#<?= $assessment_id ?></span></td>
                                    <td data-label="Date Submitted">
                                        <span class="submitted-date"><?= queue_escape($date_label) ?></span>
                                        <?php if ($time_label !== ''): ?>
                                            <span class="submitted-time"><?= queue_escape($time_label) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="center-cell" data-label="Review Status">
                                        <span class="queue-status <?= $is_reviewed ? 'queue-status-reviewed' : 'queue-status-pending' ?>">
                                            <span class="queue-status-dot"></span>
                                            <?= queue_escape($status) ?>
                                        </span>
                                    </td>
                                    <td class="center-cell" data-label="Referral Status">
                                        <span class="queue-status <?= queue_referral_status_class($item['referral_status'] ?? 'No Referral') ?>">
                                            <span class="queue-status-dot"></span>
                                            <?= queue_escape($item['referral_status'] ?? 'No Referral') ?>
                                        </span>
                                    </td>
                                    <td class="center-cell" data-label="Schedule Status">
                                        <?php
                                            $schedule_status = $item['schedule_status'] ?? 'Not Scheduled';
                                            $schedule_status_class = in_array($schedule_status, ['Scheduled', 'Completed'], true)
                                                ? 'queue-status-reviewed'
                                                : 'queue-status-pending';
                                        ?>
                                        <span class="queue-status <?= $schedule_status_class ?>">
                                            <span class="queue-status-dot"></span>
                                            <?= queue_escape($schedule_status) ?>
                                        </span>
                                    </td>
                                    <td class="center-cell" data-label="Priority">
                                        <div class="queue-priority-cell">
                                            <span class="priority-badge <?= queue_escape($priority_class) ?>">
                                                <?= queue_escape($priority ?? 'Not Rated') ?>
                                            </span>
                                            <span class="priority-average">
                                                <?= $item['average'] !== null ? number_format($item['average'], 2) . '/5' : 'No score' ?>
                                            </span>
                                        </div>
                                    </td>
                                    <td class="center-cell queue-action-cell" data-label="Action">
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

            </div><!-- /.queue-records -->
            <p class="queue-privacy-note">
                Priority is a provisional workflow indicator based on available Likert responses.
                It is not a clinical diagnosis or validated clinical risk level.
            </p>
        </section>

    </main>
    <?php include __DIR__ . "/../includes/counselor/footer.php"; ?>
    </div><!-- /.counselor-workspace -->

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

<script>
(function () {
    // This endpoint is used only by Assessment Queue. No other page is modified.
    const revisionUrl = 'check_new_assessments.php';
    let lastRevision = null;
    let checking = false;

    async function getRevision() {
        const response = await fetch(revisionUrl + '?_=' + Date.now(), {
            method: 'GET',
            cache: 'no-store',
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' }
        });
        if (!response.ok) throw new Error('Queue revision check failed');
        const data = await response.json();
        if (!data || data.success !== true || typeof data.revision !== 'string') {
            throw new Error('Invalid queue revision response');
        }
        return data.revision;
    }

    async function refreshQueue() {
        const response = await fetch(window.location.pathname + window.location.search + (window.location.search ? '&' : '?') + '_queue_refresh=' + Date.now(), {
            method: 'GET',
            cache: 'no-store',
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        if (!response.ok) throw new Error('Queue refresh failed');

        const html = await response.text();
        const parsed = new DOMParser().parseFromString(html, 'text/html');
        const currentSummary = document.querySelector('.queue-summary-grid');
        const nextSummary = parsed.querySelector('.queue-summary-grid');
        const currentPanel = document.querySelector('.queue-panel');
        const nextPanel = parsed.querySelector('.queue-panel');

        if (currentSummary && nextSummary) currentSummary.replaceWith(nextSummary);
        if (currentPanel && nextPanel) currentPanel.replaceWith(nextPanel);
    }

    async function pollQueue() {
        if (checking || document.visibilityState === 'hidden') return;
        checking = true;
        try {
            const revision = await getRevision();
            if (lastRevision === null) {
                lastRevision = revision;
            } else if (revision !== lastRevision) {
                await refreshQueue();
                lastRevision = revision;
            }
        } catch (error) {
            // Keep the page usable during a temporary network interruption.
        } finally {
            checking = false;
        }
    }

    pollQueue();
    window.setInterval(pollQueue, 4000);
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible') pollQueue();
    });
})();
</script>
<script src="../assets/js/counselor/counselor-layout.js" defer></script>
</body>
</html>