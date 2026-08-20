<?php

require_once __DIR__ . "/../includes/session.php";
require_once __DIR__ . "/../config/database.php";

/** @var mysqli $conn */

// Allow only counselor
if (!isset($_SESSION['role_type']) || $_SESSION['role_type'] !== 'counselor') {
    header("Location: ../auth/login.php");
    exit();
}

$message = "";
$error = "";


// =====================================================
// CREATE REFERRALS TABLE IF IT DOES NOT EXIST
// =====================================================

$createTableSQL = "
    CREATE TABLE IF NOT EXISTS referrals (
        referral_id INT(11) NOT NULL AUTO_INCREMENT,
        assessment_id INT(11) NOT NULL,
        referral_reason TEXT NOT NULL,
        status ENUM(
            'Pending',
            'Accepted',
            'Completed',
            'Cancelled'
        ) NOT NULL DEFAULT 'Pending',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (referral_id)
    ) ENGINE=InnoDB
    DEFAULT CHARSET=utf8mb4
";

if (!mysqli_query($conn, $createTableSQL)) {

    $error =
        "Unable to create referrals table: "
        . mysqli_error($conn);
}


// =====================================================
// CREATE REFERRAL
// =====================================================

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $assessment_id = isset($_POST['assessment_id'])
        ? (int)$_POST['assessment_id']
        : 0;

    $referral_reason = trim(
        $_POST['referral_reason'] ?? ""
    );

    $referral_status = "Pending";


    if ($assessment_id <= 0) {

        $error = "Invalid assessment.";

    } elseif ($referral_reason === "") {

        $error = "Please enter a referral reason.";

    } else {

        // Check if assessment exists
        $checkSQL = "
            SELECT assessment_id
            FROM assessments
            WHERE assessment_id = ?
            LIMIT 1
        ";

        $checkStmt = mysqli_prepare(
            $conn,
            $checkSQL
        );

        if ($checkStmt) {

            mysqli_stmt_bind_param(
                $checkStmt,
                "i",
                $assessment_id
            );

            mysqli_stmt_execute($checkStmt);

            $checkResult = mysqli_stmt_get_result(
                $checkStmt
            );

            if (mysqli_num_rows($checkResult) === 0) {

                $error = "Assessment does not exist.";

            } else {

                // Create referral
                $stmt = mysqli_prepare(
                    $conn,
                    "
                    INSERT INTO referrals
                    (
                        assessment_id,
                        referral_reason,
                        status
                    )
                    VALUES (?, ?, ?)
                    "
                );

                if ($stmt) {

                    mysqli_stmt_bind_param(
                        $stmt,
                        "iss",
                        $assessment_id,
                        $referral_reason,
                        $referral_status
                    );

                    if (mysqli_stmt_execute($stmt)) {

                        $message =
                            "Referral created successfully.";

                    } else {

                        $error =
                            "Failed to create referral: "
                            . mysqli_error($conn);
                    }

                    mysqli_stmt_close($stmt);

                } else {

                    $error =
                        "Unable to prepare referral query.";
                }
            }

            mysqli_stmt_close($checkStmt);

        } else {

            $error =
                "Unable to check assessment.";
        }
    }
}


// =====================================================
// GET REFERRALS
// =====================================================

$referrals = [];

$sql = "
    SELECT
        r.referral_id,
        r.assessment_id,
        r.referral_reason,
        r.status,
        r.created_at,
        a.student_alias,
        a.risk_level
    FROM referrals r
    LEFT JOIN assessments a
        ON r.assessment_id = a.assessment_id
    ORDER BY r.referral_id DESC
";

$result = mysqli_query($conn, $sql);

if ($result) {

    while ($row = mysqli_fetch_assoc($result)) {

        $referrals[] = $row;
    }
}


// =====================================================
// GET ASSESSMENT ID FROM URL
// =====================================================

$urlAssessmentID = isset($_GET['id'])
    ? (int)$_GET['id']
    : "";

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Referral Management</title>

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

            <h1>Referral Management</h1>

            <p>
                Manage student referrals.
            </p>

        </header>

        <hr>


        <!-- ================= MESSAGE ================= -->

        <?php if ($message): ?>

            <p>
                <?php echo htmlspecialchars($message); ?>
            </p>

        <?php endif; ?>


        <?php if ($error): ?>

            <p>
                <?php echo htmlspecialchars($error); ?>
            </p>

        <?php endif; ?>


        <!-- ================= CREATE REFERRAL ================= -->

        <section>

            <h2>Create Referral</h2>

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
                            echo htmlspecialchars(
                                $urlAssessmentID
                            );
                        ?>"
                    >

                </p>


                <p>

                    <label for="referral_reason">
                        Referral Reason:
                    </label>

                    <br>

                    <textarea
                        id="referral_reason"
                        name="referral_reason"
                        rows="5"
                        cols="50"
                        required
                    ></textarea>

                </p>


                <button type="submit">
                    Create Referral
                </button>

            </form>

        </section>


        <br>


        <!-- ================= REFERRAL LIST ================= -->

        <section>

            <h2>Existing Referrals</h2>

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
                            Referral Reason
                        </th>

                        <th>
                            Status
                        </th>

                        <th>
                            Date Created
                        </th>

                    </tr>

                </thead>


                <tbody>

                <?php if (count($referrals) > 0): ?>

                    <?php foreach ($referrals as $referral): ?>

                        <tr>

                            <td>
                                <?php
                                echo htmlspecialchars(
                                    $referral['student_alias'] ?? 'N/A'
                                );
                                ?>
                            </td>

                            <td>
                                <?php
                                echo htmlspecialchars(
                                    $referral['risk_level'] ?? 'N/A'
                                );
                                ?>
                            </td>

                            <td>
                                <?php
                                echo htmlspecialchars(
                                    $referral['assessment_id']
                                );
                                ?>
                            </td>

                            <td>
                                <?php
                                echo htmlspecialchars(
                                    $referral['referral_reason']
                                );
                                ?>
                            </td>

                            <td>
                                <?php
                                echo htmlspecialchars(
                                    $referral['status']
                                );
                                ?>
                            </td>

                            <td>
                                <?php
                                echo htmlspecialchars(
                                    $referral['created_at']
                                );
                                ?>
                            </td>

                        </tr>

                    <?php endforeach; ?>

                <?php else: ?>

                    <tr>

                        <td
                            colspan="6"
                            align="center"
                        >
                            No referrals found.
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