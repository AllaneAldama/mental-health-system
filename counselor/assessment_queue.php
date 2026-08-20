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
| MESSAGE
|--------------------------------------------------------------------------
*/

$message = "";
$message_type = "";


/*
|--------------------------------------------------------------------------
| CREATE ASSESSMENT
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_assessment'])) {

    $student_alias = trim($_POST['student_alias'] ?? '');
    $risk_level    = trim($_POST['risk_level'] ?? '');
    $phq9_score    = intval($_POST['phq9_score'] ?? -1);
    $gad7_score    = intval($_POST['gad7_score'] ?? -1);
    $status        = trim($_POST['status'] ?? '');

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
        $student_alias !== '' &&
        in_array($risk_level, $allowed_risk, true) &&
        in_array($status, $allowed_status, true) &&
        $phq9_score >= 0 &&
        $phq9_score <= 27 &&
        $gad7_score >= 0 &&
        $gad7_score <= 21
    ) {

        /*
        |--------------------------------------------------------------------------
        | DATE REVIEWED
        |--------------------------------------------------------------------------
        */

        if ($status === 'Pending') {
            $date_reviewed = null;
        } else {
            $date_reviewed = date('Y-m-d H:i:s');
        }


        /*
        |--------------------------------------------------------------------------
        | INSERT
        |--------------------------------------------------------------------------
        */

        $sql = "
            INSERT INTO assessments
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

            if (mysqli_stmt_execute($stmt)) {
                $message = "Assessment successfully added.";
                $message_type = "success";
            } else {
                $message = "Failed to add assessment.";
                $message_type = "error";
            }

            mysqli_stmt_close($stmt);

        } else {
            $message = "Database error while preparing the assessment.";
            $message_type = "error";
        }

    } else {

        $message = "Please enter valid assessment information.";
        $message_type = "error";
    }


    /*
    |--------------------------------------------------------------------------
    | REDIRECT
    |--------------------------------------------------------------------------
    */

    if ($message_type === "success") {
        header("Location: assessment_queue.php?message=created");
        exit();
    }
}


/*
|--------------------------------------------------------------------------
| UPDATE ASSESSMENT
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_assessment'])) {

    $assessment_id = intval($_POST['assessment_id'] ?? 0);

    $student_alias = trim($_POST['student_alias'] ?? '');
    $risk_level    = trim($_POST['risk_level'] ?? '');
    $phq9_score    = intval($_POST['phq9_score'] ?? -1);
    $gad7_score    = intval($_POST['gad7_score'] ?? -1);
    $status        = trim($_POST['status'] ?? '');

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
        $student_alias !== '' &&
        in_array($risk_level, $allowed_risk, true) &&
        in_array($status, $allowed_status, true) &&
        $phq9_score >= 0 &&
        $phq9_score <= 27 &&
        $gad7_score >= 0 &&
        $gad7_score <= 21
    ) {

        /*
        |--------------------------------------------------------------------------
        | PENDING
        |--------------------------------------------------------------------------
        */

        if ($status === 'Pending') {

            $sql = "
                UPDATE assessments
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

            }

        /*
        |--------------------------------------------------------------------------
        | REVIEWED / REFERRED
        |--------------------------------------------------------------------------
        */

        } else {

            $date_reviewed = date('Y-m-d H:i:s');

            $sql = "
                UPDATE assessments
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

            }
        }


        /*
        |--------------------------------------------------------------------------
        | EXECUTE UPDATE
        |--------------------------------------------------------------------------
        */

        if (isset($stmt) && $stmt) {

            if (mysqli_stmt_execute($stmt)) {
                $message = "Assessment successfully updated.";
                $message_type = "success";
            } else {
                $message = "Failed to update assessment.";
                $message_type = "error";
            }

            mysqli_stmt_close($stmt);

        } else {

            $message = "Database error while updating assessment.";
            $message_type = "error";
        }

    } else {

        $message = "Invalid assessment information.";
        $message_type = "error";
    }


    /*
    |--------------------------------------------------------------------------
    | REDIRECT
    |--------------------------------------------------------------------------
    */

    if ($message_type === "success") {
        header("Location: assessment_queue.php?message=updated");
        exit();
    }
}


/*
|--------------------------------------------------------------------------
| UPDATE STATUS ONLY
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {

    $assessment_id = intval($_POST['assessment_id'] ?? 0);
    $status = trim($_POST['status'] ?? '');

    $allowed_status = [
        'Pending',
        'Reviewed',
        'Referred'
    ];


    if (
        $assessment_id > 0 &&
        in_array($status, $allowed_status, true)
    ) {

        /*
        |--------------------------------------------------------------------------
        | PENDING
        |--------------------------------------------------------------------------
        */

        if ($status === 'Pending') {

            $sql = "
                UPDATE assessments
                SET
                    status = ?,
                    date_reviewed = NULL
                WHERE assessment_id = ?
            ";

            $stmt = mysqli_prepare($conn, $sql);

            if ($stmt) {

                mysqli_stmt_bind_param(
                    $stmt,
                    "si",
                    $status,
                    $assessment_id
                );
            }

        /*
        |--------------------------------------------------------------------------
        | REVIEWED / REFERRED
        |--------------------------------------------------------------------------
        */

        } else {

            $date_reviewed = date('Y-m-d H:i:s');

            $sql = "
                UPDATE assessments
                SET
                    status = ?,
                    date_reviewed = ?
                WHERE assessment_id = ?
            ";

            $stmt = mysqli_prepare($conn, $sql);

            if ($stmt) {

                mysqli_stmt_bind_param(
                    $stmt,
                    "ssi",
                    $status,
                    $date_reviewed,
                    $assessment_id
                );
            }
        }


        /*
        |--------------------------------------------------------------------------
        | EXECUTE
        |--------------------------------------------------------------------------
        */

        if (isset($stmt) && $stmt) {

            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
    }


    header("Location: assessment_queue.php");
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
            DELETE FROM assessments
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


    header("Location: assessment_queue.php?message=deleted");
    exit();
}


/*
|--------------------------------------------------------------------------
| SUCCESS / ERROR MESSAGES
|--------------------------------------------------------------------------
*/

if (isset($_GET['message'])) {

    switch ($_GET['message']) {

        case 'created':
            $message = "Assessment successfully added.";
            $message_type = "success";
            break;

        case 'updated':
            $message = "Assessment successfully updated.";
            $message_type = "success";
            break;

        case 'deleted':
            $message = "Assessment successfully deleted.";
            $message_type = "success";
            break;
    }
}


/*
|--------------------------------------------------------------------------
| SEARCH AND FILTER
|--------------------------------------------------------------------------
*/

$search = isset($_GET['search'])
    ? trim($_GET['search'])
    : '';

$risk_filter = isset($_GET['risk'])
    ? trim($_GET['risk'])
    : '';

$status_filter = isset($_GET['status'])
    ? trim($_GET['status'])
    : '';


/*
|--------------------------------------------------------------------------
| GET ASSESSMENTS
|--------------------------------------------------------------------------
*/

$sql = "
    SELECT
        assessment_id,
        student_alias,
        risk_level,
        phq9_score,
        gad7_score,
        date_reviewed,
        status
    FROM assessments
    WHERE 1 = 1
";

$params = [];
$types = "";


/*
|--------------------------------------------------------------------------
| SEARCH STUDENT
|--------------------------------------------------------------------------
*/

if ($search !== '') {

    $sql .= " AND student_alias LIKE ?";

    $params[] = "%" . $search . "%";

    $types .= "s";
}


/*
|--------------------------------------------------------------------------
| FILTER RISK
|--------------------------------------------------------------------------
*/

if ($risk_filter !== '') {

    $sql .= " AND risk_level = ?";

    $params[] = $risk_filter;

    $types .= "s";
}


/*
|--------------------------------------------------------------------------
| FILTER STATUS
|--------------------------------------------------------------------------
*/

if ($status_filter !== '') {

    $sql .= " AND status = ?";

    $params[] = $status_filter;

    $types .= "s";
}


/*
|--------------------------------------------------------------------------
| ORDER
|--------------------------------------------------------------------------
*/

$sql .= " ORDER BY assessment_id DESC";


/*
|--------------------------------------------------------------------------
| PREPARE QUERY
|--------------------------------------------------------------------------
*/

$stmt = mysqli_prepare($conn, $sql);

$assessments = [];

if ($stmt) {

    /*
    |--------------------------------------------------------------------------
    | BIND PARAMETERS
    |--------------------------------------------------------------------------
    */

    if (count($params) > 0) {

        /*
        | mysqli_stmt_bind_param requires references.
        | This creates the references correctly.
        */

        $bind_values = [];

        $bind_values[] = $types;

        foreach ($params as $key => $value) {
            $bind_values[] = &$params[$key];
        }

        call_user_func_array(
            [$stmt, 'bind_param'],
            $bind_values
        );
    }


    /*
    |--------------------------------------------------------------------------
    | EXECUTE
    |--------------------------------------------------------------------------
    */

    mysqli_stmt_execute($stmt);

    $result = mysqli_stmt_get_result($stmt);

    if ($result) {

        while ($row = mysqli_fetch_assoc($result)) {

            $assessments[] = $row;
        }
    }

    mysqli_stmt_close($stmt);
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

    <title>Assessment Queue</title>

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

                <li>
                    <a href="../auth/logout.php">
                        Logout
                    </a>
                </li>

            </ul>

        </nav>

    </aside>


    <!-- =====================================================
         MAIN CONTENT
    ====================================================== -->

    <main>

        <header>

            <h1>
                Assessment Queue
            </h1>

            <p>
                View and manage student assessments.
            </p>

        </header>


        <hr>


        <!-- =====================================================
             MESSAGE
        ====================================================== -->

        <?php if ($message !== ''): ?>

            <div>

                <strong>
                    <?= htmlspecialchars($message) ?>
                </strong>

            </div>

            <br>

        <?php endif; ?>


        <!-- =====================================================
             CREATE ASSESSMENT
        ====================================================== -->

        <section>

            <h2>
                Add New Assessment
            </h2>

            <form method="POST">

                <input
                    type="text"
                    name="student_alias"
                    placeholder="Student Alias"
                    required
                >


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


                <input
                    type="number"
                    name="phq9_score"
                    min="0"
                    max="27"
                    placeholder="PHQ-9 Score"
                    required
                >


                <input
                    type="number"
                    name="gad7_score"
                    min="0"
                    max="21"
                    placeholder="GAD-7 Score"
                    required
                >


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
             SEARCH AND FILTER
        ====================================================== -->

        <section>

            <h2>
                Search Assessment
            </h2>

            <form method="GET">

                <input
                    type="text"
                    name="search"
                    placeholder="Search Student Alias"
                    value="<?= htmlspecialchars($search) ?>"
                >


                <select name="risk">

                    <option value="">
                        All Risk Levels
                    </option>

                    <option
                        value="High"
                        <?= $risk_filter === 'High' ? 'selected' : '' ?>
                    >
                        High
                    </option>

                    <option
                        value="Moderate"
                        <?= $risk_filter === 'Moderate' ? 'selected' : '' ?>
                    >
                        Moderate
                    </option>

                    <option
                        value="Low"
                        <?= $risk_filter === 'Low' ? 'selected' : '' ?>
                    >
                        Low
                    </option>

                </select>


                <select name="status">

                    <option value="">
                        All Status
                    </option>

                    <option
                        value="Pending"
                        <?= $status_filter === 'Pending' ? 'selected' : '' ?>
                    >
                        Pending
                    </option>

                    <option
                        value="Reviewed"
                        <?= $status_filter === 'Reviewed' ? 'selected' : '' ?>
                    >
                        Reviewed
                    </option>

                    <option
                        value="Referred"
                        <?= $status_filter === 'Referred' ? 'selected' : '' ?>
                    >
                        Referred
                    </option>

                </select>


                <button type="submit">
                    Search
                </button>


                <a href="assessment_queue.php">
                    Clear
                </a>

            </form>

        </section>


        <br>


        <!-- =====================================================
             ASSESSMENT TABLE
        ====================================================== -->

        <section>

            <h2>
                Assessment Records
            </h2>


            <table
                border="1"
                width="100%"
                cellpadding="10"
            >

                <thead>

                    <tr>

                        <th>
                            ID
                        </th>

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

                <?php if (count($assessments) > 0): ?>

                    <?php foreach ($assessments as $assessment): ?>

                    <tr>


                        <!-- ID -->

                        <td>

                            <?= htmlspecialchars(
                                $assessment['assessment_id']
                            ) ?>

                        </td>


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

                            if (!empty($assessment['date_reviewed'])) {

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


                            <!-- READ -->

                            <a
                                href="view_assessment.php?id=<?= $assessment['assessment_id'] ?>"
                            >
                                View
                            </a>


                            <br>
                            <br>


                            <!-- =================================================
                                 UPDATE
                            ================================================== -->

                            <form method="POST">

                                <input
                                    type="hidden"
                                    name="assessment_id"
                                    value="<?= $assessment['assessment_id'] ?>"
                                >


                                <input
                                    type="text"
                                    name="student_alias"
                                    value="<?= htmlspecialchars($assessment['student_alias']) ?>"
                                    required
                                >


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


                                <input
                                    type="number"
                                    name="phq9_score"
                                    min="0"
                                    max="27"
                                    value="<?= $assessment['phq9_score'] ?>"
                                    required
                                >


                                <input
                                    type="number"
                                    name="gad7_score"
                                    min="0"
                                    max="21"
                                    value="<?= $assessment['gad7_score'] ?>"
                                    required
                                >


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


                                <button
                                    type="submit"
                                    name="update_assessment"
                                >
                                    Update
                                </button>

                            </form>


                            <br>


                            <!-- =================================================
                                 DELETE
                            ================================================== -->

                            <form
                                method="POST"
                                onsubmit="return confirm('Are you sure you want to delete this assessment?');"
                            >

                                <input
                                    type="hidden"
                                    name="assessment_id"
                                    value="<?= $assessment['assessment_id'] ?>"
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
                            colspan="8"
                            align="center"
                        >
                            No assessments found.
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