<?php

require_once __DIR__ . "/../includes/session.php";
require_once __DIR__ . "/../config/database.php";

/** @var mysqli $conn */

// Allow only counselor
if (!isset($_SESSION['role_type']) || $_SESSION['role_type'] !== 'counselor') {
    header("Location: ../auth/login.php");
    exit();
}

$assessment = null;
$error = "";

// Get assessment ID
$assessment_id = isset($_GET['id'])
    ? (int)$_GET['id']
    : 0;

if ($assessment_id <= 0) {

    $error = "Invalid assessment ID.";

} else {

    $sql = "
        SELECT
            assessment_id,
            student_alias,
            phq9_score,
            gad7_score,
            risk_level,
            date_reviewed,
            status
        FROM assessments
        WHERE assessment_id = ?
        LIMIT 1
    ";

    $stmt = mysqli_prepare($conn, $sql);

    if (!$stmt) {

        $error = "Database error: " . mysqli_error($conn);

    } else {

        mysqli_stmt_bind_param(
            $stmt,
            "i",
            $assessment_id
        );

        mysqli_stmt_execute($stmt);

        $result = mysqli_stmt_get_result($stmt);

        $assessment = mysqli_fetch_assoc($result);

        mysqli_stmt_close($stmt);

        if (!$assessment) {
            $error = "Assessment not found.";
        }
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

    <title>View Assessment</title>

</head>

<body>

<div class="container">

    <!-- ================= SIDEBAR ================= -->

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


    <!-- ================= MAIN CONTENT ================= -->

    <main>

        <header>

            <h1>Assessment Details</h1>

            <p>
                View the student's assessment information.
            </p>

        </header>

        <hr>


        <?php if ($error): ?>

            <p>
                <?php echo htmlspecialchars($error); ?>
            </p>

            <p>

                <a href="assessment_history.php">
                    Back to Assessment History
                </a>

            </p>

        <?php elseif ($assessment): ?>


            <!-- ================= STUDENT INFORMATION ================= -->

            <section>

                <h2>Student Information</h2>

                <table
                    border="1"
                    cellpadding="10"
                    width="100%"
                >

                    <tr>

                        <th>
                            Assessment ID
                        </th>

                        <td>
                            <?php
                            echo htmlspecialchars(
                                $assessment['assessment_id']
                            );
                            ?>
                        </td>

                    </tr>

                    <tr>

                        <th>
                            Student Alias
                        </th>

                        <td>
                            <?php
                            echo htmlspecialchars(
                                $assessment['student_alias']
                            );
                            ?>
                        </td>

                    </tr>

                    <tr>

                        <th>
                            Status
                        </th>

                        <td>
                            <?php
                            echo htmlspecialchars(
                                $assessment['status']
                            );
                            ?>
                        </td>

                    </tr>

                    <tr>

                        <th>
                            Date Reviewed
                        </th>

                        <td>
                            <?php
                            echo htmlspecialchars(
                                $assessment['date_reviewed'] ?? 'N/A'
                            );
                            ?>
                        </td>

                    </tr>

                </table>

            </section>


            <br>


            <!-- ================= ASSESSMENT SCORES ================= -->

            <section>

                <h2>Assessment Scores</h2>

                <table
                    border="1"
                    cellpadding="10"
                    width="100%"
                >

                    <tr>

                        <th>
                            PHQ-9 Score
                        </th>

                        <td>
                            <?php
                            echo htmlspecialchars(
                                $assessment['phq9_score']
                            );
                            ?>
                        </td>

                    </tr>

                    <tr>

                        <th>
                            GAD-7 Score
                        </th>

                        <td>
                            <?php
                            echo htmlspecialchars(
                                $assessment['gad7_score']
                            );
                            ?>
                        </td>

                    </tr>

                    <tr>

                        <th>
                            Risk Level
                        </th>

                        <td>
                            <?php
                            echo htmlspecialchars(
                                $assessment['risk_level']
                            );
                            ?>
                        </td>

                    </tr>

                </table>

            </section>


            <br>


            <!-- ================= ACTIONS ================= -->

            <section>

                <h2>Actions</h2>

                <p>

                    <a
                        href="monitoring.php?id=<?php echo (int)$assessment['assessment_id']; ?>"
                    >
                        Add to Monitoring
                    </a>

                </p>

                <p>

                    <a
                        href="referral.php?id=<?php echo (int)$assessment['assessment_id']; ?>"
                    >
                        Create Referral
                    </a>

                </p>

                <p>

                    <a href="assessment_history.php">
                        Back to Assessment History
                    </a>

                </p>

            </section>


        <?php endif; ?>

    </main>

</div>

</body>

</html>