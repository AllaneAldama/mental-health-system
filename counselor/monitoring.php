<?php

require_once __DIR__ . "/../includes/session.php";
require_once __DIR__ . "/../config/database.php";

/** @var mysqli $conn */

$message = "";
$error = "";

// ===============================
// ALLOW ONLY COUNSELOR
// ===============================

if (!isset($_SESSION['role_type']) || $_SESSION['role_type'] !== 'counselor') {
    header("Location: ../auth/login.php");
    exit();
}


// ===============================
// CREATE MONITORING RECORD
// ===============================

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $assessment_id = isset($_POST['assessment_id'])
        ? (int) $_POST['assessment_id']
        : 0;

    $monitoring_notes = trim($_POST['monitoring_notes'] ?? "");

    $follow_up_status = trim(
        $_POST['follow_up_status'] ?? "Pending"
    );


    if ($assessment_id <= 0) {

        $error = "Please provide a valid assessment ID.";

    } elseif ($monitoring_notes === "") {

        $error = "Please enter monitoring notes.";

    } else {

        // Check if assessment exists
        $check_sql = "
            SELECT assessment_id
            FROM assessments
            WHERE assessment_id = ?
            LIMIT 1
        ";

        $check_stmt = mysqli_prepare($conn, $check_sql);

        if ($check_stmt) {

            mysqli_stmt_bind_param(
                $check_stmt,
                "i",
                $assessment_id
            );

            mysqli_stmt_execute($check_stmt);

            $check_result = mysqli_stmt_get_result($check_stmt);

            if (mysqli_num_rows($check_result) === 0) {

                $error = "Assessment not found.";

            } else {

                // Insert monitoring record
                $insert_sql = "
                    INSERT INTO monitoring
                    (
                        assessment_id,
                        monitoring_notes,
                        follow_up_status
                    )
                    VALUES (?, ?, ?)
                ";

                $stmt = mysqli_prepare(
                    $conn,
                    $insert_sql
                );

                if ($stmt) {

                    mysqli_stmt_bind_param(
                        $stmt,
                        "iss",
                        $assessment_id,
                        $monitoring_notes,
                        $follow_up_status
                    );

                    if (mysqli_stmt_execute($stmt)) {

                        $message =
                            "Student added to monitoring successfully.";

                    } else {

                        $error =
                            "Failed to create monitoring record: "
                            . mysqli_stmt_error($stmt);
                    }

                    mysqli_stmt_close($stmt);

                } else {

                    $error =
                        "Unable to prepare monitoring query: "
                        . mysqli_error($conn);
                }
            }

            mysqli_stmt_close($check_stmt);

        } else {

            $error =
                "Unable to check assessment: "
                . mysqli_error($conn);
        }
    }
}


// ===============================
// GET MONITORING RECORDS
// ===============================

$monitoringRecords = [];

$sql = "
    SELECT
        m.id,
        m.assessment_id,
        m.monitoring_notes,
        m.follow_up_status,
        m.created_at,

        a.student_alias,
        a.risk_level,
        a.phq9_score,
        a.gad7_score,
        a.status

    FROM monitoring m

    LEFT JOIN assessments a
        ON m.assessment_id = a.assessment_id

    ORDER BY m.id DESC
";

$result = mysqli_query($conn, $sql);

if ($result) {

    while ($row = mysqli_fetch_assoc($result)) {

        $monitoringRecords[] = $row;
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

    <title>Student Monitoring</title>

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

            <h1>Student Monitoring</h1>

            <p>
                Monitor students who require follow-up.
            </p>

        </header>

        <hr>


        <!-- ================= MESSAGES ================= -->

        <?php if ($message): ?>

            <p>
                <?php
                echo htmlspecialchars($message);
                ?>
            </p>

        <?php endif; ?>


        <?php if ($error): ?>

            <p>
                <?php
                echo htmlspecialchars($error);
                ?>
            </p>

        <?php endif; ?>


        <!-- ================= ADD MONITORING ================= -->

        <section>

            <h2>Add Student to Monitoring</h2>

            <form method="POST">

                <p>

                    <label for="assessment_id">
                        Assessment ID:
                    </label>

                    <br>

                    <input
                        type="number"
                        id="assessment_id"
                        name="assessment_id"
                        required
                        value="<?php
                            echo isset($_GET['id'])
                                ? (int) $_GET['id']
                                : '';
                        ?>"
                    >

                </p>


                <p>

                    <label for="monitoring_notes">
                        Monitoring Notes:
                    </label>

                    <br>

                    <textarea
                        id="monitoring_notes"
                        name="monitoring_notes"
                        rows="5"
                        cols="50"
                        required
                    ></textarea>

                </p>


                <p>

                    <label for="follow_up_status">
                        Follow-up Status:
                    </label>

                    <br>

                    <select
                        id="follow_up_status"
                        name="follow_up_status"
                    >

                        <option value="Pending">
                            Pending
                        </option>

                        <option value="Scheduled">
                            Scheduled
                        </option>

                        <option value="Completed">
                            Completed
                        </option>

                    </select>

                </p>


                <button type="submit">
                    Add to Monitoring
                </button>

            </form>

        </section>


        <br>


        <!-- ================= MONITORING LIST ================= -->

        <section>

            <h2>Students Under Monitoring</h2>

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
                            Assessment ID
                        </th>

                        <th>
                            PHQ-9
                        </th>

                        <th>
                            GAD-7
                        </th>

                        <th>
                            Monitoring Notes
                        </th>

                        <th>
                            Follow-up Status
                        </th>

                        <th>
                            Date Added
                        </th>

                    </tr>

                </thead>


                <tbody>

                <?php if (count($monitoringRecords) > 0): ?>

                    <?php foreach ($monitoringRecords as $record): ?>

                        <tr>

                            <td>
                                <?php
                                echo htmlspecialchars(
                                    $record['student_alias']
                                    ?? 'N/A'
                                );
                                ?>
                            </td>


                            <td>
                                <?php
                                echo htmlspecialchars(
                                    $record['risk_level']
                                    ?? 'N/A'
                                );
                                ?>
                            </td>


                            <td>
                                <?php
                                echo htmlspecialchars(
                                    $record['assessment_id']
                                );
                                ?>
                            </td>


                            <td>
                                <?php
                                echo htmlspecialchars(
                                    $record['phq9_score']
                                    ?? 'N/A'
                                );
                                ?>
                            </td>


                            <td>
                                <?php
                                echo htmlspecialchars(
                                    $record['gad7_score']
                                    ?? 'N/A'
                                );
                                ?>
                            </td>


                            <td>
                                <?php
                                echo htmlspecialchars(
                                    $record['monitoring_notes']
                                );
                                ?>
                            </td>


                            <td>
                                <?php
                                echo htmlspecialchars(
                                    $record['follow_up_status']
                                );
                                ?>
                            </td>


                            <td>
                                <?php
                                echo htmlspecialchars(
                                    $record['created_at']
                                );
                                ?>
                            </td>

                        </tr>

                    <?php endforeach; ?>


                <?php else: ?>

                    <tr>

                        <td
                            colspan="8"
                            align="center"
                        >

                            No students are currently
                            under monitoring.

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