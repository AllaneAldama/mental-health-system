<?php
require_once __DIR__ . "/../includes/session.php";
if (!isset($_SESSION['role_type']) || $_SESSION['role_type'] !== 'counselor') {
    header("Location: ../auth/login.php");
    exit();
}
require_once __DIR__ . "/../config/database.php";

function column_exists($conn, $table, $column) {
    $t = mysqli_real_escape_string($conn, $table);
    $c = mysqli_real_escape_string($conn, $column);
    $sql = "SHOW COLUMNS FROM `$t` LIKE '$c'";
    $res = mysqli_query($conn, $sql);
    return ($res && mysqli_num_rows($res) > 0);
}

$message = '';
$error = '';

$user_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
$profile = null;

if ($user_id > 0) {
    $res = mysqli_query($conn, "SELECT * FROM user_data WHERE user_id = $user_id LIMIT 1");
    if ($res && mysqli_num_rows($res) > 0) {
        $profile = mysqli_fetch_assoc($res);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'update_profile') {
        $fullname = isset($_POST['fullname']) ? mysqli_real_escape_string($conn, trim($_POST['fullname'])) : '';
        $email = isset($_POST['email']) ? mysqli_real_escape_string($conn, trim($_POST['email'])) : '';
//        $program = isset($_POST['program']) ? mysqli_real_escape_string($conn, trim($_POST['program'])) : '';
//        $year_level = isset($_POST['year_level']) ? mysqli_real_escape_string($conn, trim($_POST['year_level'])) : '';
//        $alias = isset($_POST['alias']) ? mysqli_real_escape_string($conn, trim($_POST['alias'])) : '';
        $phone = isset($_POST['phone']) ? mysqli_real_escape_string($conn, trim($_POST['phone'])) : '';
        $department = isset($_POST['department']) ? mysqli_real_escape_string($conn, trim($_POST['department'])) : '';
        $specialization = isset($_POST['specialization']) ? mysqli_real_escape_string($conn, trim($_POST['specialization'])) : '';

        $updates = [];
        if (!empty($fullname)) $updates[] = "fullname = '$fullname'";
        if (!empty($email)) $updates[] = "email = '$email'";
//        if (column_exists($conn, 'user_data', 'program')) $updates[] = "program = '" . (!empty($program) ? $program : '') . "'";
//        if (column_exists($conn, 'user_data', 'year_level')) $updates[] = "year_level = '" . (!empty($year_level) ? $year_level : '') . "'";
//        if (column_exists($conn, 'user_data', 'alias')) $updates[] = "alias = '" . (!empty($alias) ? $alias : '') . "'";
        if (column_exists($conn, 'user_data', 'phone')) $updates[] = "phone = '" . $phone . "'";
        if (column_exists($conn, 'user_data', 'department')) $updates[] = "department = '" . $department . "'";
        if (column_exists($conn, 'user_data', 'specialization')) $updates[] = "specialization = '" . $specialization . "'";

        if (!empty($updates)) {
            $sql = "UPDATE user_data SET " . implode(', ', $updates) . " WHERE user_id = $user_id";
            if (mysqli_query($conn, $sql)) {
                $message = 'Profile updated successfully.';
                $res = mysqli_query($conn, "SELECT * FROM user_data WHERE user_id = $user_id LIMIT 1");
                if ($res && mysqli_num_rows($res) > 0) {
                    $profile = mysqli_fetch_assoc($res);
                }
            } else {
                $error = 'Profile update failed: ' . mysqli_error($conn);
            }
        }
    }

    if ($action === 'change_password') {
        $current_password = $_POST['current_password'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
            $error = 'All password fields are required.';
        } elseif ($new_password !== $confirm_password) {
            $error = 'New password and confirmation do not match.';
        } else {
            $user = $profile;
            if ($user) {
                $stored_hash = $user['password_hash'] ?? '';
                if (!empty($stored_hash) && password_verify($current_password, $stored_hash)) {
                    $hash = password_hash($new_password, PASSWORD_DEFAULT);
                    $sql = "UPDATE user_data SET password_hash = '$hash' WHERE user_id = $user_id";
                    if (mysqli_query($conn, $sql)) {
                        $message = 'Password changed successfully.';
                    } else {
                        $error = 'Password change failed: ' . mysqli_error($conn);
                    }
                } else {
                    $error = 'Current password is incorrect.';
                }
            } else {
                $error = 'Unable to load your profile.';
            }
        }
    }
}

$fullname = $profile['fullname'] ?? '';
$email = $profile['email'] ?? '';
// $program = $profile['program'] ?? '';
// $year_level = $profile['year_level'] ?? '';
// $alias = $profile['alias'] ?? '';
$has_phone = column_exists($conn, 'user_data', 'phone');
$has_department = column_exists($conn, 'user_data', 'department');
$has_specialization = column_exists($conn, 'user_data', 'specialization');
$phone = $has_phone ? ($profile['phone'] ?? '') : '';
$department = $has_department ? ($profile['department'] ?? '') : '';
$specialization = $has_specialization ? ($profile['specialization'] ?? '') : '';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile - Counselor Dashboard</title>
    <style>
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; }
        body {
            font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
            background: #efeeeb;
            color: #1a1a1a;
        }
        a { text-decoration: none; color: inherit; }
        .app-shell { display: flex; min-height: 100vh; }
        .sidebar {
            width: 260px;
            background: #5c8a60;
            color: #fff;
            padding: 22px 18px 18px;
            display: flex;
            flex-direction: column;
        }
        .brand {
            font-size: 2rem;
            font-weight: 500;
            letter-spacing: -0.05em;
            padding: 6px 12px 20px;
            color: #f5f8f3;
        }
        .nav { display: flex; flex-direction: column; gap: 12px; margin-top: 24px; }
        .nav-link {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 14px;
            border-radius: 10px;
            font-size: 1.05rem;
            font-weight: 600;
            color: #f1f5ef;
            transition: all 0.2s ease;
        }
        .nav-link:hover, .nav-link.active {
            background: #edf2ee;
            color: #1f3d2a;
        }
        .logout {
            margin-top: auto;
            border-top: 1px solid rgba(255,255,255,0.25);
            padding-top: 16px;
        }
        .main-content { flex: 1; padding: 30px 32px 40px; }
        .page-header { margin-bottom: 20px; }
        .page-title {
            margin: 0;
            font-size: 2.7rem;
            font-weight: 600;
            letter-spacing: -0.06em;
        }
        .page-subtitle {
            margin: 8px 0 0;
            font-size: 1.15rem;
            color: #4c4c4c;
        }
        .alert {
            padding: 12px 14px;
            margin-bottom: 18px;
            border-radius: 10px;
            font-weight: 600;
        }
        .alert-success { background: #e8f5e9; color: #1b5e20; border: 1px solid #c8e6c9; }
        .alert-error { background: #ffebee; color: #b71c1c; border: 1px solid #ffcdd2; }
        .profile-grid {
            display: grid;
            grid-template-columns: 1.2fr 1fr;
            gap: 22px;
        }
        .panel {
            background: rgba(255,255,255,0.28);
            border: 1px solid #dfe4dc;
            border-radius: 12px;
            padding: 20px;
        }
        .panel-title {
            margin: 0 0 18px;
            font-size: 1.2rem;
            font-weight: 800;
        }
        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }
        .field { display: flex; flex-direction: column; gap: 8px; }
        .field.full { grid-column: 1 / -1; }
        label {
            font-size: 0.9rem;
            font-weight: 700;
            color: #2a2a2a;
        }
        input, select {
            padding: 10px 12px;
            border: 1px solid #cbd5cc;
            border-radius: 8px;
            font: inherit;
            background: #fff;
        }
        .btn {
            background: #5c8a60;
            color: white;
            border: none;
            padding: 10px 18px;
            border-radius: 8px;
            font-weight: 700;
            cursor: pointer;
        }
        .info-box {
            background: linear-gradient(135deg, #edf4ee, #f5faf5);
            border: 1px solid #dfe8de;
            border-radius: 12px;
            padding: 18px;
            margin-bottom: 18px;
        }
        .avatar {
            width: 90px;
            height: 90px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #5c8a60;
            color: white;
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 18px;
        }
        @media (max-width: 900px) {
            .app-shell { flex-direction: column; }
            .sidebar { width: 100%; }
            .main-content { padding: 20px; }
            .profile-grid, .form-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <div class="app-shell">
      <aside class="sidebar">
        <div class="brand">PTC Wellness</div>
        <nav class="nav" aria-label="Main navigation">
          <a class="nav-link" href="dashboard.php"><span class="nav-icon">🏠</span><span>Dashboard</span></a>
          <a class="nav-link" href="assessment_queue.php"><span class="nav-icon">📋</span><span>Assessments Queue</span></a>
          <a class="nav-link" href="monitoring.php"><span class="nav-icon">👁️</span><span>Monitoring</span></a>
          <a class="nav-link" href="referral.php"><span class="nav-icon">📤</span><span>Referral</span></a>
          <a class="nav-link" href="assessment_history.php"><span class="nav-icon">📚</span><span>Assessment History</span></a>
          <a class="nav-link active" href="profile.php"><span class="nav-icon">👤</span><span>Profile</span></a>
          <a class="nav-link logout" href="../auth/logout.php"><span class="nav-icon">↩️</span><span>Logout</span></a>
        </nav>
      </aside>

        <main class="main-content">
            <header class="page-header">
                <h1 class="page-title">My Profile</h1>
                <p class="page-subtitle">Manage your counselor account details and security settings.</p>
            </header>

            <?php if ($message !== ''): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>
            <?php if ($error !== ''): ?>
                <div class="alert alert-error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>

            <div class="profile-grid">
                <section class="panel">
                    <h2 class="panel-title">Profile Information</h2>
                    <div class="info-box">
                        <div class="avatar"><?php echo strtoupper(substr($fullname ?: 'C', 0, 1)); ?></div>
                        <div><strong><?php echo htmlspecialchars($fullname ?: 'Counselor', ENT_QUOTES, 'UTF-8'); ?></strong></div>
                        <div><?php echo htmlspecialchars($email ?: 'No email set', ENT_QUOTES, 'UTF-8'); ?></div>
                    </div>

                    <form method="POST">
                        <input type="hidden" name="action" value="update_profile">
                        <div class="form-grid">
                            <div class="field">
                                <label for="fullname">Full Name</label>
                                <input id="fullname" name="fullname" type="text" value="<?php echo htmlspecialchars($fullname, ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                            <div class="field">
                                <label for="email">Email</label>
                                <input id="email" name="email" type="email" value="<?php echo htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                            <?php if ($has_phone): ?>
                                <div class="field">
                                    <label for="phone">Phone</label>
                                    <input id="phone" name="phone" type="text" value="<?php echo htmlspecialchars($phone, ENT_QUOTES, 'UTF-8'); ?>">
                                </div>
                            <?php endif; ?>
                            <?php if ($has_department): ?>
                                <div class="field">
                                    <label for="department">Department</label>
                                    <input id="department" name="department" type="text" value="<?php echo htmlspecialchars($department, ENT_QUOTES, 'UTF-8'); ?>">
                                </div>
                            <?php endif; ?>
                            <?php if ($has_specialization): ?>
                                <div class="field full">
                                    <label for="specialization">Specialization</label>
                                    <input id="specialization" name="specialization" type="text" value="<?php echo htmlspecialchars($specialization, ENT_QUOTES, 'UTF-8'); ?>">
                                </div>
                            <?php endif; ?>
                        </div>
                        <div style="margin-top: 18px;">
                            <button type="submit" class="btn">Update Profile</button>
                        </div>
                    </form>
                </section>

                <section class="panel">
                    <h2 class="panel-title">Change Password</h2>
                    <form method="POST">
                        <input type="hidden" name="action" value="change_password">
                        <div class="form-grid">
                            <div class="field full">
                                <label for="current_password">Current Password</label>
                                <input id="current_password" name="current_password" type="password">
                            </div>
                            <div class="field full">
                                <label for="new_password">New Password</label>
                                <input id="new_password" name="new_password" type="password">
                            </div>
                            <div class="field full">
                                <label for="confirm_password">Confirm New Password</label>
                                <input id="confirm_password" name="confirm_password" type="password">
                            </div>
                        </div>
                        <div style="margin-top: 18px;">
                            <button type="submit" class="btn">Change Password</button>
                        </div>
                    </form>
                </section>
            </div>
        </main>
    </div>
</body>
</html>
