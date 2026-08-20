<?php

require_once __DIR__ . "/../includes/session.php";
require_once __DIR__ . "/../config/database.php";

/*
|--------------------------------------------------------------------------
| ALLOW ONLY COUNSELOR
|--------------------------------------------------------------------------
*/

if (!isset($_SESSION['role_type']) || $_SESSION['role_type'] !== 'counselor') {
    header("Location: ../auth/login.php");
    exit();
}


/*
|--------------------------------------------------------------------------
| CREATE ASSESSMENT
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_assessment'])) {

    $student_alias = trim($_POST['student_alias'] ?? '');
    $risk_level = trim($_POST['risk_level'] ?? '');
    $phq9_score = intval($_POST['phq9_score'] ?? 0);
    $gad7_score = intval($_POST['gad7_score'] ?? 0);
    $status = trim($_POST['status'] ?? 'Pending');

    $allowed_risk = [
        'Low',
        'Moderate',
        'High'
    ];

    $allowed_status = [
        'Pending',
        'Reviewed',
        'Referred'
    ];

    if (
        !empty($student_alias) &&
        in_array($risk_level, $allowed_risk, true) &&
        in_array($status, $allowed_status, true) &&
        $phq9_score >= 0 &&
        $phq9_score <= 27 &&
        $gad7_score >= 0 &&
        $gad7_score <= 21
    ) {

        if ($status === 'Pending') {
            $date_reviewed = null;
        } else {
            $date_reviewed = date('Y-m-d H:i:s');
        }

        $sql = "
            INSERT INTO assessment_queue
            (
                student_alias,
                risk_level,
                phq9_score,
                gad7_score,
                date_reviewed,
                status
            )
            VALUES (?, ?, ?, ?, ?, ?)
        ";

        $stmt = mysqli_prepare($conn, $sql);

        if ($stmt) {

            mysqli_stmt_bind_param(
                $stmt,
                "ssiiss",
                $student_alias,
                $risk_level,
                $phq9_score,
                $gad7_score,
                $date_reviewed,
                $status
            );

            mysqli_stmt_execute($stmt);

            mysqli_stmt_close($stmt);
        }
    }

    header("Location: dashboard.php");
    exit();
}


/*
|--------------------------------------------------------------------------
| UPDATE ASSESSMENT
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_assessment'])) {

    $assessment_id = intval($_POST['assessment_id'] ?? 0);
    $student_alias = trim($_POST['student_alias'] ?? '');
    $risk_level = trim($_POST['risk_level'] ?? '');
    $phq9_score = intval($_POST['phq9_score'] ?? 0);
    $gad7_score = intval($_POST['gad7_score'] ?? 0);
    $status = trim($_POST['status'] ?? '');

    $allowed_risk = [
        'Low',
        'Moderate',
        'High'
    ];

    $allowed_status = [
        'Pending',
        'Reviewed',
        'Referred'
    ];

    if (
        $assessment_id > 0 &&
        !empty($student_alias) &&
        in_array($risk_level, $allowed_risk, true) &&
        in_array($status, $allowed_status, true) &&
        $phq9_score >= 0 &&
        $phq9_score <= 27 &&
        $gad7_score >= 0 &&
        $gad7_score <= 21
    ) {

        if ($status === 'Pending') {

            $sql = "
                UPDATE assessment_queue
                SET
                    student_alias = ?,
                    risk_level = ?,
                    phq9_score = ?,
                    gad7_score = ?,
                    date_reviewed = NULL,
                    status = ?
                WHERE assessment_id = ?
            ";

            $stmt = mysqli_prepare($conn, $sql);

            if ($stmt) {

                mysqli_stmt_bind_param(
                    $stmt,
                    "ssiisi",
                    $student_alias,
                    $risk_level,
                    $phq9_score,
                    $gad7_score,
                    $status,
                    $assessment_id
                );

                mysqli_stmt_execute($stmt);

                mysqli_stmt_close($stmt);
            }

        } else {

            $date_reviewed = date('Y-m-d H:i:s');

            $sql = "
                UPDATE assessment_queue
                SET
                    student_alias = ?,
                    risk_level = ?,
                    phq9_score = ?,
                    gad7_score = ?,
                    date_reviewed = ?,
                    status = ?
                WHERE assessment_id = ?
            ";

            $stmt = mysqli_prepare($conn, $sql);

            if ($stmt) {

                mysqli_stmt_bind_param(
                    $stmt,
                    "ssiissi",
                    $student_alias,
                    $risk_level,
                    $phq9_score,
                    $gad7_score,
                    $date_reviewed,
                    $status,
                    $assessment_id
                );

                mysqli_stmt_execute($stmt);

                mysqli_stmt_close($stmt);
            }
        }
    }

    header("Location: dashboard.php");
    exit();
}


/*
|--------------------------------------------------------------------------
| DELETE ASSESSMENT
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_assessment'])) {

    $assessment_id = intval($_POST['assessment_id'] ?? 0);

    if ($assessment_id > 0) {

        $sql = "
            DELETE FROM assessment_queue
            WHERE assessment_id = ?
        ";

        $stmt = mysqli_prepare($conn, $sql);

        if ($stmt) {

            mysqli_stmt_bind_param(
                $stmt,
                "i",
                $assessment_id
            );

            mysqli_stmt_execute($stmt);

            mysqli_stmt_close($stmt);
        }
    }

    header("Location: dashboard.php");
    exit();
}


/*
|--------------------------------------------------------------------------
| DASHBOARD COUNTS
|--------------------------------------------------------------------------
*/


/* TOTAL ASSESSMENTS */

$total_sql = "
    SELECT COUNT(*) AS total
    FROM assessment_queue
";

$total_result = mysqli_query($conn, $total_sql);

$total_assessments = 0;

if ($total_result) {

    $total_row = mysqli_fetch_assoc($total_result);

    $total_assessments = intval($total_row['total']);
}


/* PENDING */

$pending_sql = "
    SELECT COUNT(*) AS total
    FROM assessment_queue
    WHERE status = 'Pending'
";

$pending_result = mysqli_query($conn, $pending_sql);

$pending_review = 0;

if ($pending_result) {

    $pending_row = mysqli_fetch_assoc($pending_result);

    $pending_review = intval($pending_row['total']);
}


/* HIGH RISK */

$high_sql = "
    SELECT COUNT(*) AS total
    FROM assessment_queue
    WHERE risk_level = 'High'
";

$high_result = mysqli_query($conn, $high_sql);

$high_risk = 0;

if ($high_result) {

    $high_row = mysqli_fetch_assoc($high_result);

    $high_risk = intval($high_row['total']);
}


/* REFERRED */

$referred_sql = "
    SELECT COUNT(*) AS total
    FROM assessment_queue
    WHERE status = 'Referred'
";

$referred_result = mysqli_query($conn, $referred_sql);

$referred = 0;

if ($referred_result) {

    $referred_row = mysqli_fetch_assoc($referred_result);

    $referred = intval($referred_row['total']);
}


/*
|--------------------------------------------------------------------------
| RISK DISTRIBUTION
|--------------------------------------------------------------------------
*/

$low_sql = "
    SELECT COUNT(*) AS total
    FROM assessment_queue
    WHERE risk_level = 'Low'
";

$low_result = mysqli_query($conn, $low_sql);

$low_risk = 0;

if ($low_result) {

    $low_row = mysqli_fetch_assoc($low_result);

    $low_risk = intval($low_row['total']);
}


$moderate_sql = "
    SELECT COUNT(*) AS total
    FROM assessment_queue
    WHERE risk_level = 'Moderate'
";

$moderate_result = mysqli_query($conn, $moderate_sql);

$moderate_risk = 0;

if ($moderate_result) {

    $moderate_row = mysqli_fetch_assoc($moderate_result);

    $moderate_risk = intval($moderate_row['total']);
}


/*
|--------------------------------------------------------------------------
| RECENT ASSESSMENTS
|--------------------------------------------------------------------------
*/

$recent_sql = "
    SELECT
        assessment_id,
        student_alias,
        risk_level,
        phq9_score,
        gad7_score,
        date_reviewed,
        status
    FROM assessment_queue
    ORDER BY assessment_id DESC
    LIMIT 5
";

$recent_result = mysqli_query($conn, $recent_sql);

$recent_assessments = [];

if ($recent_result) {

    while ($row = mysqli_fetch_assoc($recent_result)) {

        $recent_assessments[] = $row;
    }
}


/*
|--------------------------------------------------------------------------
| ASSESSMENT OVER TIME
|--------------------------------------------------------------------------
*/

$monthly_sql = "
    SELECT
        DATE_FORMAT(date_reviewed, '%Y-%m') AS month,
        COUNT(*) AS total
    FROM assessment_queue
    WHERE date_reviewed IS NOT NULL
    GROUP BY DATE_FORMAT(date_reviewed, '%Y-%m')
    ORDER BY month DESC
    LIMIT 6
";

$monthly_result = mysqli_query($conn, $monthly_sql);

$monthly_assessments = [];

if ($monthly_result) {

    while ($row = mysqli_fetch_assoc($monthly_result)) {

        $monthly_assessments[] = $row;
    }
}

?>


<!DOCTYPE html>

<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Counselor Dashboard</title>

</head>


<body>

<div class="container">


    <!-- =====================================================
         SIDEBAR
    ====================================================== -->

    <aside>

        <h2>PTC Wellness</h2>

        <hr>

        <nav>

            <ul>

                <li>
                    <a href="dashboard.php">
                        Dashboard
                    </a>
                </li>

                <li>
                    <a href="assessment_queue.php">
                        Assessments
                    </a>
                </li>

                <li>
                    <a href="monitoring.php">
                        Monitoring
                    </a>
                </li>

                <li>
                    <a href="referral.php">
                        Referrals
                    </a>
                </li>

                <li>
                    <a href="assessment_history.php">
                        Assessment History
                    </a>
                </li>

                <li>
                    <a href="profile.php">
                        Profile
                    </a>
                </li>

            </ul>

        </nav>

        <hr>

        <a href="../auth/logout.php">
            Logout
        </a>

    </aside>


    <!-- =====================================================
         MAIN CONTENT
    ====================================================== -->

    <main>


        <!-- HEADER -->

        <header>

            <h1>
                Dashboard Overview
            </h1>

            <p>

                Welcome back,

                <?= htmlspecialchars(
                    $_SESSION['fullname'] ?? 'Counselor'
                ) ?>!

            </p>

        </header>


        <hr>


        <!-- =====================================================
             CREATE ASSESSMENT
        ====================================================== -->

        <section>

            <h2>
                Add New Assessment
            </h2>

            <form method="POST">

                <label>
                    Student Alias:
                </label>

                <input
                    type="text"
                    name="student_alias"
                    placeholder="Student Alias"
                    required
                >


                <label>
                    Risk Level:
                </label>

                <select
                    name="risk_level"
                    required
                >

                    <option value="">
                        Select Risk Level
                    </option>

                    <option value="Low">
                        Low
                    </option>

                    <option value="Moderate">
                        Moderate
                    </option>

                    <option value="High">
                        High
                    </option>

                </select>


                <label>
                    PHQ-9 Score:
                </label>

                <input
                    type="number"
                    name="phq9_score"
                    min="0"
                    max="27"
                    placeholder="0 - 27"
                    required
                >


                <label>
                    GAD-7 Score:
                </label>

                <input
                    type="number"
                    name="gad7_score"
                    min="0"
                    max="21"
                    placeholder="0 - 21"
                    required
                >


                <label>
                    Status:
                </label>

                <select
                    name="status"
                    required
                >

                    <option value="Pending">
                        Pending
                    </option>

                    <option value="Reviewed">
                        Reviewed
                    </option>

                    <option value="Referred">
                        Referred
                    </option>

                </select>


                <button
                    type="submit"
                    name="create_assessment"
                >
                    Add Assessment
                </button>

            </form>

        </section>


        <br>

        <hr>

        <br>


        <!-- =====================================================
             SUMMARY CARDS
        ====================================================== -->

        <section>

            <table width="100%">

                <tr>


                    <td>

                        <h3>
                            Total Assessments
                        </h3>

                        <h1>
                            <?= $total_assessments ?>
                        </h1>

                    </td>


                    <td>

                        <h3>
                            Pending Review
                        </h3>

                        <h1>
                            <?= $pending_review ?>
                        </h1>

                    </td>


                    <td>

                        <h3>
                            High Risk
                        </h3>

                        <h1>
                            <?= $high_risk ?>
                        </h1>

                    </td>


                    <td>

                        <h3>
                            Referred
                        </h3>

                        <h1>
                            <?= $referred ?>
                        </h1>

                    </td>

                </tr>

            </table>

        </section>


        <br>


        <!-- =====================================================
             CHART / STATISTICS
        ====================================================== -->

        <section>

            <table
                width="100%"
                border="1"
                cellpadding="10"
            >

                <tr>


                    <!-- ASSESSMENT OVER TIME -->

                    <td
                        width="60%"
                        valign="top"
                    >

                        <h2>
                            Assessment Over Time
                        </h2>

                        <hr>


                        <?php if (count($monthly_assessments) > 0): ?>

                            <table width="100%" border="1">

                                <tr>

                                    <th>
                                        Month
                                    </th>

                                    <th>
                                        Assessments
                                    </th>

                                </tr>


                                <?php foreach (
                                    array_reverse($monthly_assessments)
                                    as $month
                                ): ?>

                                    <tr>

                                        <td>
                                            <?= htmlspecialchars(
                                                $month['month']
                                            ) ?>
                                        </td>

                                        <td>
                                            <?= intval(
                                                $month['total']
                                            ) ?>
                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                            </table>

                        <?php else: ?>

                            <p>
                                No reviewed assessments available yet.
                            </p>

                        <?php endif; ?>

                    </td>


                    <!-- RISK DISTRIBUTION -->

                    <td
                        width="40%"
                        valign="top"
                    >

                        <h2>
                            Risk Level Distribution
                        </h2>

                        <hr>


                        <table width="100%" border="1">

                            <tr>

                                <th>
                                    Risk Level
                                </th>

                                <th>
                                    Total
                                </th>

                            </tr>


                            <tr>

                                <td>
                                    Low
                                </td>

                                <td>
                                    <?= $low_risk ?>
                                </td>

                            </tr>


                            <tr>

                                <td>
                                    Moderate
                                </td>

                                <td>
                                    <?= $moderate_risk ?>
                                </td>

                            </tr>


                            <tr>

                                <td>
                                    High
                                </td>

                                <td>
                                    <?= $high_risk ?>
                                </td>

                            </tr>

                        </table>

                    </td>

                </tr>

            </table>

        </section>


        <br>


        <!-- =====================================================
             RECENT ASSESSMENTS
        ====================================================== -->

        <section>

            <h2>
                Recent Assessments
            </h2>


            <table
                border="1"
                width="100%"
                cellpadding="10"
            >

                <thead>

                    <tr>

                        <th>
                            Student Alias
                        </th>

                        <th>
                            Risk Level
                        </th>

                        <th>
                            PHQ-9
                        </th>

                        <th>
                            GAD-7
                        </th>

                        <th>
                            Date Reviewed
                        </th>

                        <th>
                            Status
                        </th>

                        <th>
                            Actions
                        </th>

                    </tr>

                </thead>


                <tbody>


                <?php if (count($recent_assessments) > 0): ?>


                    <?php foreach ($recent_assessments as $assessment): ?>

                    <tr>


                        <!-- STUDENT -->

                        <td>

                            <?= htmlspecialchars(
                                $assessment['student_alias']
                            ) ?>

                        </td>


                        <!-- RISK -->

                        <td>

                            <?= htmlspecialchars(
                                $assessment['risk_level']
                            ) ?>

                        </td>


                        <!-- PHQ9 -->

                        <td>

                            <?= htmlspecialchars(
                                $assessment['phq9_score']
                            ) ?>

                        </td>


                        <!-- GAD7 -->

                        <td>

                            <?= htmlspecialchars(
                                $assessment['gad7_score']
                            ) ?>

                        </td>


                        <!-- DATE -->

                        <td>

                            <?php

                            if (
                                !empty(
                                    $assessment['date_reviewed']
                                )
                            ) {

                                echo htmlspecialchars(
                                    date(
                                        'F d, Y h:i A',
                                        strtotime(
                                            $assessment['date_reviewed']
                                        )
                                    )
                                );

                            } else {

                                echo "Not reviewed";

                            }

                            ?>

                        </td>


                        <!-- STATUS -->

                        <td>

                            <?= htmlspecialchars(
                                $assessment['status']
                            ) ?>

                        </td>


                        <!-- ACTIONS -->

                        <td>


                            <!-- VIEW -->

                            <a
                                href="view_assessment.php?id=<?= intval($assessment['assessment_id']) ?>"
                            >
                                View
                            </a>


                            <br>
                            <br>


                            <!-- UPDATE -->

                            <form method="POST">

                                <input
                                    type="hidden"
                                    name="assessment_id"
                                    value="<?= intval(
                                        $assessment['assessment_id']
                                    ) ?>"
                                >


                                <input
                                    type="text"
                                    name="student_alias"
                                    value="<?= htmlspecialchars(
                                        $assessment['student_alias']
                                    ) ?>"
                                    required
                                >


                                <br>


                                <select
                                    name="risk_level"
                                    required
                                >

                                    <option
                                        value="Low"
                                        <?= $assessment['risk_level'] === 'Low'
                                            ? 'selected'
                                            : '' ?>
                                    >
                                        Low
                                    </option>

                                    <option
                                        value="Moderate"
                                        <?= $assessment['risk_level'] === 'Moderate'
                                            ? 'selected'
                                            : '' ?>
                                    >
                                        Moderate
                                    </option>

                                    <option
                                        value="High"
                                        <?= $assessment['risk_level'] === 'High'
                                            ? 'selected'
                                            : '' ?>
                                    >
                                        High
                                    </option>

                                </select>


                                <br>


                                <input
                                    type="number"
                                    name="phq9_score"
                                    min="0"
                                    max="27"
                                    value="<?= intval(
                                        $assessment['phq9_score']
                                    ) ?>"
                                    required
                                >


                                <br>


                                <input
                                    type="number"
                                    name="gad7_score"
                                    min="0"
                                    max="21"
                                    value="<?= intval(
                                        $assessment['gad7_score']
                                    ) ?>"
                                    required
                                >


                                <br>


                                <select
                                    name="status"
                                    required
                                >

                                    <option
                                        value="Pending"
                                        <?= $assessment['status'] === 'Pending'
                                            ? 'selected'
                                            : '' ?>
                                    >
                                        Pending
                                    </option>

                                    <option
                                        value="Reviewed"
                                        <?= $assessment['status'] === 'Reviewed'
                                            ? 'selected'
                                            : '' ?>
                                    >
                                        Reviewed
                                    </option>

                                    <option
                                        value="Referred"
                                        <?= $assessment['status'] === 'Referred'
                                            ? 'selected'
                                            : '' ?>
                                    >
                                        Referred
                                    </option>

                                </select>


                                <br>


                                <button
                                    type="submit"
                                    name="update_assessment"
                                >
                                    Update
                                </button>

                            </form>


                            <br>


                            <!-- DELETE -->

                            <form
                                method="POST"
                                onsubmit="return confirm('Are you sure you want to delete this assessment?');"
                            >

                                <input
                                    type="hidden"
                                    name="assessment_id"
                                    value="<?= intval(
                                        $assessment['assessment_id']
                                    ) ?>"
                                >


                                <button
                                    type="submit"
                                    name="delete_assessment"
                                >
                                    Delete
                                </button>

                            </form>


                        </td>

                    </tr>


                    <?php endforeach; ?>


                <?php else: ?>


                    <tr>

                        <td
                            colspan="7"
                            align="center"
                        >

                            No assessments found.

                        </td>

                    </tr>


                <?php endif; ?>


                </tbody>

            </table>

        </section>


        <br>


        <!-- =====================================================
             VIEW ALL
        ====================================================== -->

        <section>

            <a href="assessment_queue.php">
                View All Assessments
            </a>

        </section>


    </main>

</div>

</body>

</html>
```
