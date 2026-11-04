
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

function e($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function history_status_class($status): string
{
    switch (strtolower(trim((string)$status))) {
        case 'reviewed':
            return 'reviewed';
        case 'pending':
            return 'pending';
        default:
            return 'neutral';
    }
}

$search = trim($_GET['search'] ?? '');
$filter = trim($_GET['filter'] ?? 'all');

$allowed_filters = ['all', 'pending', 'reviewed', 'accepted'];

if (!in_array($filter, $allowed_filters, true)) {
    $filter = 'all';
}

/* Summary counts */
$stats = [
    'total' => 0,
    'pending' => 0,
    'reviewed' => 0,
    'accepted' => 0
];

$statsSql = "
    SELECT
        COUNT(*) AS total_records,
        COALESCE(SUM(status = 'Pending'), 0) AS pending_records,
        COALESCE(SUM(status = 'Reviewed'), 0) AS reviewed_records
    FROM assessment_data
";

$statsResult = mysqli_query($conn, $statsSql);

if ($statsResult && $statsRow = mysqli_fetch_assoc($statsResult)) {
    $stats['total'] = (int)$statsRow['total_records'];
    $stats['pending'] = (int)$statsRow['pending_records'];
    $stats['reviewed'] = (int)$statsRow['reviewed_records'];
}

/* Accepted referrals are counted from the referral workflow, not assessment review status. */
$acceptedResult = mysqli_query(
    $conn,
    "SELECT COUNT(DISTINCT assessment_id) AS accepted_records
     FROM referral_data
     WHERE LOWER(TRIM(COALESCE(referral_status, ''))) = 'accepted'"
);

if ($acceptedResult && $acceptedRow = mysqli_fetch_assoc($acceptedResult)) {
    $stats['accepted'] = (int)$acceptedRow['accepted_records'];
}

/* Assessment history */
$records = [];
$queryError = false;

$sql = "
    SELECT
        a.assessment_id,
        a.user_id,
        a.assessment_type,
        a.assessment_date,
        a.status,
        u.fullname AS student_name,
        u.email AS student_email,
        u.student_number,
        u.alias AS student_alias,
        u.program AS student_program,
        u.year_level AS student_year_level
    FROM assessment_data AS a
    LEFT JOIN student_profiles AS u
        ON u.student_id = a.student_id
";

$conditions = [];
$params = [];
$types = '';

if ($search !== '') {
    $conditions[] = "(
        u.fullname LIKE ?
        OR u.alias LIKE ?
        OR u.email LIKE ?
        OR u.student_number LIKE ?
        OR u.program LIKE ?
        OR CAST(a.assessment_id AS CHAR) LIKE ?
    )";

    $term = '%' . $search . '%';

    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;

    $types .= 'ssssss';
}

$statusMap = [
    'pending' => 'Pending',
    'reviewed' => 'Reviewed'
];

if ($filter === 'accepted') {
    $conditions[] = "EXISTS (
        SELECT 1
        FROM referral_data AS rd
        WHERE rd.assessment_id = a.assessment_id
          AND LOWER(TRIM(COALESCE(rd.referral_status, ''))) = 'accepted'
    )";
} elseif (isset($statusMap[$filter])) {
    $conditions[] = 'a.status = ?';
    $params[] = $statusMap[$filter];
    $types .= 's';
}

if ($conditions) {
    $sql .= ' WHERE ' . implode(' AND ', $conditions);
}

$sql .= ' ORDER BY a.assessment_date DESC, a.assessment_id DESC';

$stmt = mysqli_prepare($conn, $sql);

if ($stmt) {
    if ($params) {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }

    if (mysqli_stmt_execute($stmt)) {
        $result = mysqli_stmt_get_result($stmt);

        if ($result) {
            while ($row = mysqli_fetch_assoc($result)) {
                $records[] = $row;
            }
        }
    } else {
        $queryError = true;
    }

    mysqli_stmt_close($stmt);
} else {
    $queryError = true;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Assessment History | PTC Wellness</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap"
        rel="stylesheet"
    >

    <link rel="stylesheet" href="../assets/css/counselor/counselor-base.css">
    <link rel="stylesheet" href="../assets/css/counselor/counselor-layout.css?v=<?= (int) @filemtime(__DIR__ . '/../assets/css/counselor/counselor-layout.css') ?>">
    <link rel="stylesheet" href="../assets/css/counselor/assessment-history.css?v=<?= (int) @filemtime(__DIR__ . '/../assets/css/counselor/assessment-history.css') ?>">
    <link rel="stylesheet" href="../assets/css/counselor/assessment-history-mobile.css?v=<?= (int) @filemtime(__DIR__ . '/../assets/css/counselor/assessment-history-mobile.css') ?>" media="(max-width: 900px)">
</head>

<body class="counselor-page history-page">

<?php include __DIR__ . '/../includes/counselor/sidebar.php'; ?>
<div class="counselor-workspace">
<?php include __DIR__ . '/../includes/counselor/topbar.php'; ?>
    <!-- MAIN CONTENT -->
    <main class="main-content history-content">

        <header class="page-header history-header">
            <div class="page-heading">
                <p class="history-eyebrow">PTC WELLNESS · GUIDANCE OFFICE</p>
                <h1 class="page-title">Assessment History</h1>
                <p class="history-subtitle">Review submitted Needs Assessments and track their current status.</p>
            </div>
            <div class="header-right">
                <div class="datetime-card" aria-label="Current date and time in Manila">
                    <span class="datetime-item"><span class="datetime-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/></svg></span><span id="history-current-date">Loading date...</span></span>
                    <span class="datetime-divider" aria-hidden="true"></span>
                    <span class="datetime-item"><span class="datetime-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg></span><span id="history-current-time">Loading time...</span></span>
                </div>
            </div>
        </header>

        <!-- SUMMARY CARDS -->
        <section class="history-stats" aria-label="Assessment statistics">

            <article class="history-stat">
                <span>Total Records</span>
                <strong><?= number_format($stats['total']) ?></strong>
                <small>All submitted assessments</small>
            </article>

            <article class="history-stat">
                <span>Pending Review</span>
                <strong><?= number_format($stats['pending']) ?></strong>
                <small>Awaiting counselor review</small>
            </article>

            <article class="history-stat">
                <span>Reviewed</span>
                <strong><?= number_format($stats['reviewed']) ?></strong>
                <small>Reviewed by the counselor</small>
            </article>

            <article class="history-stat">
                <span>Accepted</span>
                <strong><?= number_format($stats['accepted']) ?></strong>
                <small>Assessments with accepted referrals</small>
            </article>

        </section>

        <!-- HISTORY TABLE -->
        <section class="history-panel">

            <div class="history-panel-heading">
                <div>
                    <h2>Assessment Records</h2>
                    <p>
                        Search student records or filter by assessment status.
                    </p>
                </div>

                <span class="history-record-count">
                    <?= number_format(count($records)) ?>
                    <?= count($records) === 1 ? 'record' : 'records' ?>
                </span>
            </div>

            <form class="history-search" method="GET"
                  action="assessment_history.php">

                <label class="history-search-field">
                    <span class="visually-hidden">Search student or assessment ID</span>
                    <input
                        type="search"
                        name="search"
                        value="<?= e($search) ?>"
                        placeholder="Search student, email, or assessment ID..."
                    >
                </label>

                <label class="history-filter-field">
                    <span class="visually-hidden">Filter by status</span>
                    <select name="filter">
                        <option value="all" <?= $filter === 'all' ? 'selected' : '' ?>>
                            All statuses
                        </option>
                        <option value="pending" <?= $filter === 'pending' ? 'selected' : '' ?>>
                            Pending
                        </option>
                        <option value="reviewed" <?= $filter === 'reviewed' ? 'selected' : '' ?>>
                            Reviewed
                        </option>
                        <option value="accepted" <?= $filter === 'accepted' ? 'selected' : '' ?>>
                            Accepted
                        </option>
                    </select>
                </label>

                <button class="history-button primary" type="submit">
                    Search
                </button>

                <?php if ($search !== '' || $filter !== 'all'): ?>
                    <a class="history-button secondary"
                       href="assessment_history.php">
                        Clear
                    </a>
                <?php endif; ?>

            </form>

            <?php if ($queryError): ?>

                <div class="history-message error">
                    Unable to load assessment records. Please check the database
                    connection and table structure.
                </div>

            <?php elseif (empty($records)): ?>

                <div class="history-empty">
                    <div class="history-empty-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24">
                            <circle cx="10.5" cy="10.5" r="6.5"></circle>
                            <path d="m16 16 4 4"></path>
                        </svg>
                    </div>

                    <strong>No assessment records found</strong>
                    <span>
                        Try a different search term or choose another status filter.
                    </span>
                </div>

            <?php else: ?>

                <div class="history-table-wrap">
                    <table class="history-table">
                        <thead>
                            <tr>
                                <th>Student</th>
                                <th>Assessment ID</th>
                                <th>Assessment Type</th>
                                <th>Date Submitted</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php foreach ($records as $record): ?>
                                <?php
                                    $studentName = $record['student_name'] ?: 'Unknown student';
                                    $studentEmail = $record['student_email'] ?: 'No email available';
                                    $assessmentType = $record['assessment_type'] ?: 'Needs Assessment';
                                    $status = $record['status'] ?: 'Pending';

                                    $dateSubmitted = 'Not available';

                                    if (!empty($record['assessment_date'])) {
                                        $timestamp = strtotime($record['assessment_date']);

                                        if ($timestamp !== false) {
                                            $dateSubmitted = date('M d, Y · h:i A', $timestamp);
                                        }
                                    }
                                ?>

                                <tr class="history-record-row">
                                    <td data-label="Student">
                                        <span class="history-student-name">
                                            <?= e($studentName) ?>
                                        </span>

                                        <span class="history-student-detail">
                                            <?= e($studentEmail) ?>
                                        </span>

                                        <?php if (!empty($record['student_alias']) && $record['student_alias'] !== $studentName): ?>
                                            <span class="history-student-number">
                                                Alias: <?= e($record['student_alias']) ?>
                                            </span>
                                        <?php endif; ?>

                                        <?php if (!empty($record['student_number'])): ?>
                                            <span class="history-student-number">
                                                Student No. <?= e($record['student_number']) ?>
                                            </span>
                                        <?php endif; ?>

                                        <?php if (!empty($record['student_program']) || !empty($record['student_year_level'])): ?>
                                            <span class="history-student-number">
                                                <?= e(trim(($record['student_program'] ?? '') . (!empty($record['student_program']) && !empty($record['student_year_level']) ? ' · ' : '') . ($record['student_year_level'] ?? ''))) ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>

                                    <td data-label="Assessment ID">
                                        <span class="history-id">
                                            #<?= (int)$record['assessment_id'] ?>
                                        </span>
                                    </td>

                                    <td data-label="Assessment Type">
                                        <span class="history-assessment-type">
                                            <?= e($assessmentType) ?>
                                        </span>
                                    </td>

                                    <td data-label="Date Submitted"><?= e($dateSubmitted) ?></td>

                                    <td data-label="Status">
                                        <span class="history-status <?= e(history_status_class($status)) ?>">
                                            <?= e($status) ?>
                                        </span>
                                    </td>

                                    <td data-label="Action">
                                        <a
                                            class="history-button view"
                                            href="view_assessment.php?id=<?= (int)$record['assessment_id'] ?>"
                                        >
                                            View
                                            <span aria-hidden="true">↗</span>
                                        </a>
                                    </td>
                                </tr>

                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

            <?php endif; ?>

            <div class="history-table-footer">
                <span>
                    Showing <?= number_format(count($records)) ?>
                    <?= count($records) === 1 ? 'record' : 'records' ?>
                    for the selected filters.
                </span>
                <span>Confidential student information — handle with care.</span>
            </div>

        </section>



    </main>
    <?php include __DIR__ . '/../includes/counselor/footer.php'; ?>
</div>

<!-- Logout Confirmation Modal -->

<div class="logout-overlay"
     id="logout-modal"
     aria-hidden="true">

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
        if (event.key === 'Escape' &&
            modal.classList.contains('is-open')) {
            closeModal();
        }
    });
})();
</script>


<script>
(function(){
  const dateEl=document.getElementById('history-current-date');
  const timeEl=document.getElementById('history-current-time');
  if(!dateEl||!timeEl)return;
  const d=new Intl.DateTimeFormat('en-PH',{timeZone:'Asia/Manila',weekday:'long',month:'short',day:'numeric',year:'numeric'});
  const t=new Intl.DateTimeFormat('en-PH',{timeZone:'Asia/Manila',hour:'2-digit',minute:'2-digit',hour12:true});
  function refresh(){const now=new Date();dateEl.textContent=d.format(now);timeEl.textContent=t.format(now);}
  refresh();window.setInterval(refresh,1000);
})();
</script>
<script src="../assets/js/counselor/counselor-layout.js?v=<?= (int) @filemtime(__DIR__ . '/../assets/js/counselor/counselor-layout.js') ?>" defer></script>
</body>
</html>
