
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
    LEFT JOIN user_data AS u
        ON u.user_id = a.user_id
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

    <link rel="stylesheet" href="../assets/css/counselor.css">
    <link rel="stylesheet" href="../assets/css/assessment_history.css">
</head>

<body class="counselor-page history-page">

<div class="app-shell">

    <!-- CONSISTENT PTC WELLNESS SIDEBAR -->
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
                <span class="nav-icon">
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
                <span class="nav-icon">
                    <svg viewBox="0 0 24 24">
                        <path d="M8 6h13M8 12h13M8 18h13"></path>
                        <path d="m3 6 1 1 2-2M3 12l1 1 2-2M3 18l1 1 2-2"></path>
                    </svg>
                </span>
                Assessments Queue
            </a>

            <a class="nav-link" href="monitoring.php">
                <span class="nav-icon">
                    <svg viewBox="0 0 24 24">
                        <path d="M3 12s3-7 9-7 9 7 9 7-3 7-9 7-9-7-9-7Z"></path>
                        <circle cx="12" cy="12" r="3"></circle>
                    </svg>
                </span>
                Monitoring
            </a>

            <a class="nav-link" href="referral.php">
                <span class="nav-icon">
                    <svg viewBox="0 0 24 24">
                        <path d="M7 17 17 7M7 7h10v10"></path>
                        <path d="M5 5v14h14"></path>
                    </svg>
                </span>
                Referral
            </a>

            <a class="nav-link active"
               href="assessment_history.php"
               aria-current="page">
                <span class="nav-icon">
                    <svg viewBox="0 0 24 24">
                        <circle cx="12" cy="12" r="9"></circle>
                        <path d="M12 7v5l3 2"></path>
                    </svg>
                </span>
                Assessment History
            </a>

            <a class="nav-link" href="profile.php">
                <span class="nav-icon">
                    <svg viewBox="0 0 24 24">
                        <circle cx="12" cy="8" r="4"></circle>
                        <path d="M4 21a8 8 0 0 1 16 0"></path>
                    </svg>
                </span>
                Profile
            </a>

            <a class="nav-link logout" href="../auth/logout.php?portal=counselor">
                <span class="nav-icon">
                    <svg viewBox="0 0 24 24">
                        <path d="M10 17l5-5-5-5M15 12H3"></path>
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

    <!-- MAIN CONTENT -->
    <main class="history-content">

        <header class="history-header">
            <div>
                <p class="history-eyebrow">PTC WELLNESS · GUIDANCE OFFICE</p>
                <h1>Assessment History</h1>
                <p class="history-subtitle">
                    Review submitted Needs Assessments and track their current status.
                </p>
            </div>

            <span class="history-portal-tag">Counselor Portal</span>
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

                                <tr>
                                    <td>
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

                                    <td>
                                        <span class="history-id">
                                            #<?= (int)$record['assessment_id'] ?>
                                        </span>
                                    </td>

                                    <td>
                                        <span class="history-assessment-type">
                                            <?= e($assessmentType) ?>
                                        </span>
                                    </td>

                                    <td><?= e($dateSubmitted) ?></td>

                                    <td>
                                        <span class="history-status <?= e(history_status_class($status)) ?>">
                                            <?= e($status) ?>
                                        </span>
                                    </td>

                                    <td>
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

        <footer class="history-footer">
            <span>PTC Wellness · Counselor Portal</span>
            <span>Supporting a healthier campus, one step at a time.</span>
        </footer>

    </main>
</div>

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
        background: rgba(15, 35, 20, 0.58);
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


</body>
</html>
