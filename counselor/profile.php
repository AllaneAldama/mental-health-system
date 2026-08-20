<?php

require_once __DIR__ . "/../includes/session.php";
require_once __DIR__ . "/../config/database.php";

// Allow only counselor
if (!isset($_SESSION['role_type']) || $_SESSION['role_type'] !== 'counselor') {
    header("Location: ../auth/login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// Get counselor information
$sql = "SELECT user_id, fullname, email, role_type, status
        FROM user_data
        WHERE user_id = ?
        LIMIT 1";

$stmt = mysqli_prepare($conn, $sql);

if (!$stmt) {
    die("Database error: " . mysqli_error($conn));
}

mysqli_stmt_bind_param($stmt, "i", $user_id);
mysqli_stmt_execute($stmt);

$result = mysqli_stmt_get_result($stmt);
$counselor = mysqli_fetch_assoc($result);

if (!$counselor) {
    die("Counselor account not found.");
}

// Update profile
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {

    $fullname = trim($_POST['fullname']);
    $email = trim($_POST['email']);

    if (empty($fullname) || empty($email)) {

        $message = "Please fill in all fields.";

    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {

        $message = "Please enter a valid email.";

    } else {

        // Check if email belongs to another account
        $check_sql = "SELECT user_id
                      FROM user_data
                      WHERE email = ?
                      AND user_id != ?
                      LIMIT 1";

        $check_stmt = mysqli_prepare($conn, $check_sql);

        mysqli_stmt_bind_param(
            $check_stmt,
            "si",
            $email,
            $user_id
        );

        mysqli_stmt_execute($check_stmt);

        $check_result = mysqli_stmt_get_result($check_stmt);

        if (mysqli_num_rows($check_result) > 0) {

            $message = "Email is already being used.";

        } else {

            // Update counselor information
            $update_sql = "UPDATE user_data
                           SET fullname = ?, email = ?
                           WHERE user_id = ?";

            $update_stmt = mysqli_prepare($conn, $update_sql);

            mysqli_stmt_bind_param(
                $update_stmt,
                "ssi",
                $fullname,
                $email,
                $user_id
            );

            if (mysqli_stmt_execute($update_stmt)) {

                $_SESSION['fullname'] = $fullname;

                $message = "Profile updated successfully.";

                // Refresh counselor information
                $counselor['fullname'] = $fullname;
                $counselor['email'] = $email;

            } else {

                $message = "Failed to update profile.";
            }
        }
    }
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>Counselor Profile</title>

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

        <!-- HEADER -->

        <header>

            <h1>Counselor Profile</h1>

            <p>
                Manage your profile information.
            </p>

        </header>

        <hr>


        <!-- MESSAGE -->

        <?php if (isset($message)): ?>

            <p>
                <?= htmlspecialchars($message) ?>
            </p>

        <?php endif; ?>


        <!-- ================= PROFILE INFORMATION ================= -->

        <section>

            <h2>Profile Information</h2>

            <form method="POST">

                <p>

                    <label for="fullname">
                        Full Name
                    </label>

                    <br>

                    <input
                        type="text"
                        id="fullname"
                        name="fullname"
                        value="<?= htmlspecialchars($counselor['fullname']) ?>"
                        required
                    >

                </p>


                <p>

                    <label for="email">
                        Email
                    </label>

                    <br>

                    <input
                        type="email"
                        id="email"
                        name="email"
                        value="<?= htmlspecialchars($counselor['email']) ?>"
                        required
                    >

                </p>


                <p>

                    <label>
                        Role
                    </label>

                    <br>

                    <input
                        type="text"
                        value="<?= htmlspecialchars(ucfirst($counselor['role_type'])) ?>"
                        readonly
                    >

                </p>


                <p>

                    <label>
                        Account Status
                    </label>

                    <br>

                    <input
                        type="text"
                        value="<?= htmlspecialchars($counselor['status']) ?>"
                        readonly
                    >

                </p>


                <button
                    type="submit"
                    name="update_profile">

                    Update Profile

                </button>

            </form>

        </section>


        <br>


        <!-- ================= ACCOUNT INFORMATION ================= -->

        <section>

            <h2>Account Information</h2>

            <table border="1"
                   cellpadding="10"
                   width="100%">

                <tr>

                    <th>User ID</th>

                    <td>
                        <?= htmlspecialchars($counselor['user_id']) ?>
                    </td>

                </tr>

                <tr>

                    <th>Full Name</th>

                    <td>
                        <?= htmlspecialchars($counselor['fullname']) ?>
                    </td>

                </tr>

                <tr>

                    <th>Email</th>

                    <td>
                        <?= htmlspecialchars($counselor['email']) ?>
                    </td>

                </tr>

                <tr>

                    <th>Role</th>

                    <td>
                        <?= htmlspecialchars(ucfirst($counselor['role_type'])) ?>
                    </td>

                </tr>

                <tr>

                    <th>Status</th>

                    <td>
                        <?= htmlspecialchars($counselor['status']) ?>
                    </td>

                </tr>

            </table>

        </section>

    </main>

</div>

</body>

</html>