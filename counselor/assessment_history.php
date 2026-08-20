<?php

require_once __DIR__ . "/../includes/session.php";
require_once __DIR__ . "/../config/database.php";

/** @var mysqli $conn */

// Allow only counselor
if (!isset($_SESSION['role_type']) || $_SESSION['role_type'] !== 'counselor') {
    header("Location: ../auth/login.php");
    exit();
}

$assessments = [];

// Get assessment history
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
    WHERE status IN ('Completed', 'Reviewed', 'Referred')
    ORDER BY assessment_id DESC
";

$result = mysqli_query($conn, $sql);

if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $assessments[] = $row;
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

    <title>Assessment History</title>
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

            <h1>Assessment History</h1>

            <p>
                View completed, reviewed, and referred assessments.
            </p>

        </header>

        <hr>


        <!-- ================= HISTORY TABLE ================= -->

        <section>

            <h2>Assessment Records</h2>

            <table
                border="1"
                width="100%"
                cellpadding="10"
            >

                <thead>

                    <tr>

                        <th>
                            Assessment ID
                        </th>

                        <th>
                            Student Alias
                        </th>

                        <th>
                            PHQ-9 Score
                        </th>

                        <th>
                            GAD-7 Score
                        </th>

                        <th>
                            Risk Level
                        </th>

                        <th>
                            Date Reviewed
                        </th>

                        <th>
                            Status
                        </th>

                        <th>
                            Action
                        </th>

                    </tr>

                </thead>

                <tbody>

                <?php if (count($assessments) > 0): ?>

                    <?php foreach ($assessments as $assessment): ?>

                        <tr>

                            <td>
                                <?php
                                echo htmlspecialchars(
                                    $assessment['assessment_id']
                                );
                                ?>
                            </td>

                            <td>
                                <?php
                                echo htmlspecialchars(
                                    $assessment['student_alias']
                                );
                                ?>
                            </td>

                            <td>
                                <?php
                                echo htmlspecialchars(
                                    $assessment['phq9_score']
                                );
                                ?>
                            </td>

                            <td>
                                <?php
                                echo htmlspecialchars(
                                    $assessment['gad7_score']
                                );
                                ?>
                            </td>

                            <td>
                                <?php
                                echo htmlspecialchars(
                                    $assessment['risk_level']
                                );
                                ?>
                            </td>

                            <td>
                                <?php
                                echo htmlspecialchars(
                                    $assessment['date_reviewed'] ?? 'N/A'
                                );
                                ?>
                            </td>

                            <td>
                                <?php
                                echo htmlspecialchars(
                                    $assessment['status']
                                );
                                ?>
                            </td>

                            <td>

                                <a
                                    href="view_assessment.php?id=<?php echo (int)$assessment['assessment_id']; ?>"
                                >
                                    View
                                </a>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                <?php else: ?>

                    <tr>

                        <td
                            colspan="8"
                            align="center"
                        >
                            No assessment history found.
                        </td>

                    </tr>

                <?php endif; ?>

                </tbody>

            </table>

        </section>

    </main>

</div>

</body>

</html>