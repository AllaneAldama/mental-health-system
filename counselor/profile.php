<?php
require_once __DIR__ . '/../includes/counselor_session.php';
require_once __DIR__ . '/../config/database.php';
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
        // Fetch the latest password hash for the signed-in counselor account.
        $current_password = (string)($_POST['current_password'] ?? '');
        $new_password = (string)($_POST['new_password'] ?? '');
        $confirm_password = (string)($_POST['confirm_password'] ?? '');

        if ($current_password === '' || $new_password === '' || $confirm_password === '') {
            $error = 'Please complete all three password fields.';
        } elseif ($new_password !== $confirm_password) {
            $error = 'New password and confirmation do not match.';
        } elseif (strlen($new_password) < 8) {
            $error = 'Your new password must contain at least 8 characters.';
        } elseif ($new_password === $current_password) {
            $error = 'Choose a new password different from your current password.';
        } else {
            $stmt = mysqli_prepare(
                $conn,
                "SELECT password_hash FROM user_data WHERE user_id = ? AND role_type = 'counselor' LIMIT 1"
            );

            if (!$stmt) {
                error_log('Profile password lookup preparation failed: ' . mysqli_error($conn));
                $error = 'Unable to verify your account right now. Please try again.';
            } else {
                mysqli_stmt_bind_param($stmt, "i", $user_id);
                $executed = mysqli_stmt_execute($stmt);
                $result = $executed ? mysqli_stmt_get_result($stmt) : false;
                $password_row = $result ? mysqli_fetch_assoc($result) : null;
                mysqli_stmt_close($stmt);

                $stored_hash = (string)($password_row['password_hash'] ?? '');

                if ($stored_hash === '' || !password_verify($current_password, $stored_hash)) {
                    $error = 'Current password is incorrect. Please use the same password you use to sign in to this counselor account.';
                } else {
                    $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
                    $update_stmt = mysqli_prepare(
                        $conn,
                        "UPDATE user_data SET password_hash = ? WHERE user_id = ? AND role_type = 'counselor'"
                    );

                    if (!$update_stmt) {
                        error_log('Profile password update preparation failed: ' . mysqli_error($conn));
                        $error = 'Password change failed. Please try again.';
                    } else {
                        mysqli_stmt_bind_param($update_stmt, "si", $new_hash, $user_id);
                        $saved = mysqli_stmt_execute($update_stmt);
                        mysqli_stmt_close($update_stmt);

                        if ($saved) {
                            $message = 'Password changed successfully.';
                            $res = mysqli_query($conn, "SELECT * FROM user_data WHERE user_id = " . (int)$user_id . " LIMIT 1");
                            if ($res && mysqli_num_rows($res) > 0) {
                                $profile = mysqli_fetch_assoc($res);
                            }
                        } else {
                            error_log('Profile password update failed for user_id ' . $user_id . ': ' . mysqli_error($conn));
                            $error = 'Password could not be saved. Please try again.';
                        }
                    }
                }
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
    <title>Profile Settings | PTC Wellness</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/counselor/counselor-base.css">
    <link rel="stylesheet" href="../assets/css/counselor/counselor-layout.css?v=<?= (int) @filemtime(__DIR__ . '/../assets/css/counselor/counselor-layout.css') ?>">
    <link rel="stylesheet" href="../assets/css/counselor/profile.css?v=<?= (int) @filemtime(__DIR__ . '/../assets/css/counselor/profile.css') ?>">
    <link rel="stylesheet" href="../assets/css/counselor/profile-mobile.css?v=<?= (int) @filemtime(__DIR__ . '/../assets/css/counselor/profile-mobile.css') ?>" media="(max-width: 900px)">
</head>
<body class="counselor-page profile-page">
<?php include __DIR__ . '/../includes/counselor/sidebar.php'; ?>
<div class="counselor-workspace">
<?php include __DIR__ . '/../includes/counselor/topbar.php'; ?>
<main class="main-content profile-content">
    <header class="page-header profile-header">
        <div class="page-heading">
            <p class="eyebrow profile-eyebrow">PTC WELLNESS · GUIDANCE OFFICE</p>
            <h1 class="page-title">Profile Settings</h1>
            <p class="page-subtitle">Manage your counselor account information and keep your account secure.</p>
        </div>
        <div class="header-right">
            <div class="datetime-card" aria-label="Current date and time in Manila">
                <span class="datetime-item"><span class="datetime-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/></svg></span><span id="profile-current-date">Loading date...</span></span>
                <span class="datetime-divider" aria-hidden="true"></span>
                <span class="datetime-item"><span class="datetime-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg></span><span id="profile-current-time">Loading time...</span></span>
            </div>
        </div>
    </header>
            <div class="profile-grid">
                <section class="panel profile-panel">
                    <h2 class="panel-title">Profile Information</h2>
                    <div class="info-box">
                        <div class="avatar"><?php echo strtoupper(substr($fullname ?: 'C', 0, 1)); ?></div>
                        <div class="profile-identity">
                            <strong><?php echo htmlspecialchars($fullname ?: 'Counselor', ENT_QUOTES, 'UTF-8'); ?></strong>
                            <span><?php echo htmlspecialchars($email ?: 'No email set', ENT_QUOTES, 'UTF-8'); ?></span>
                        </div>
                    </div>

                    <form method="POST">
                        <input type="hidden" name="action" value="update_profile">
                        <div class="form-grid">
                            <div class="field">
                                <label for="fullname">Full Name</label>
                                <input id="fullname" name="fullname" type="text" required value="<?php echo htmlspecialchars($fullname, ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                            <div class="field">
                                <label for="email">Email</label>
                                <input id="email" name="email" type="email" required value="<?php echo htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?>">
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
                        <div class="profile-form-actions">
                            <button type="submit" class="btn">Update Profile</button>
                        </div>
                    </form>
                </section>

                <section class="panel profile-panel">
                    <h2 class="panel-title">Change Password</h2>
                    <p class="security-hint">Use a strong password that you do not use for other accounts.</p>
                    <form method="POST">
                        <input type="hidden" name="action" value="change_password">
                        <div class="form-grid">
                            <div class="field full">
                                <label for="current_password">Current Password</label>
                                <input id="current_password" name="current_password" type="password" autocomplete="current-password" required>
                            </div>
                            <div class="field full">
                                <label for="new_password">New Password</label>
                                <input id="new_password" name="new_password" type="password" autocomplete="new-password" minlength="8" required>
                            </div>
                            <div class="field full">
                                <label for="confirm_password">Confirm New Password</label>
                                <input id="confirm_password" name="confirm_password" type="password" autocomplete="new-password" minlength="8" required>
                            </div>
                        </div>
                        <div class="profile-form-actions">
                            <button type="submit" class="btn">Change Password</button>
                        </div>
                    </form>
                </section>
            </div>
</main>
<?php include __DIR__ . '/../includes/counselor/footer.php'; ?>
</div>

    <?php if ($message !== '' || $error !== ''): ?>
    <?php $modalSuccess = ($message !== ''); ?>
    <div class="profile-modal-backdrop is-visible" id="profileFeedbackModal" role="dialog" aria-modal="true" aria-labelledby="profileModalTitle" aria-describedby="profileModalMessage">
        <div class="profile-modal-card">
            <button class="profile-modal-close" type="button" aria-label="Close notification" data-close-profile-modal>&times;</button>
            <div class="profile-modal-icon <?php echo $modalSuccess ? 'is-success' : 'is-error'; ?>" aria-hidden="true">
                <?php if ($modalSuccess): ?>
                    <svg viewBox="0 0 24 24"><path d="m5 12 4 4L19 6"></path></svg>
                <?php else: ?>
                    <svg viewBox="0 0 24 24"><path d="M12 8v5"></path><path d="M12 17h.01"></path><circle cx="12" cy="12" r="9"></circle></svg>
                <?php endif; ?>
            </div>
            <p class="profile-modal-eyebrow"><?php echo $modalSuccess ? 'SUCCESS' : 'PLEASE CHECK'; ?></p>
            <h2 id="profileModalTitle"><?php echo htmlspecialchars($modalSuccess ? 'Changes saved' : 'Unable to save changes', ENT_QUOTES, 'UTF-8'); ?></h2>
            <p id="profileModalMessage"><?php echo htmlspecialchars($modalSuccess ? $message : $error, ENT_QUOTES, 'UTF-8'); ?></p>
            <button type="button" class="profile-modal-button" data-close-profile-modal>Got it</button>
        </div>
    </div>
    <?php endif; ?>

    <script>
    (function () {
        const modal = document.getElementById('profileFeedbackModal');
        if (!modal) return;
        let closing = false;
        function closeModal() {
            if (closing) return;
            closing = true;
            modal.classList.remove('is-visible');
            modal.classList.add('is-closing');
            window.setTimeout(function () {
                if (modal.parentNode) modal.parentNode.removeChild(modal);
            }, 220);
        }
        modal.querySelectorAll('[data-close-profile-modal]').forEach(function (button) {
            button.addEventListener('click', closeModal);
        });
        modal.addEventListener('click', function (event) {
            if (event.target === modal) closeModal();
        });
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') closeModal();
        });
        window.setTimeout(function () {
            const button = modal.querySelector('[data-close-profile-modal]');
            if (button) button.focus();
        }, 80);
    })();
    </script>

<script>
(function () {
    const dateEl = document.getElementById('profile-current-date');
    const timeEl = document.getElementById('profile-current-time');
    if (!dateEl || !timeEl) return;
    const dateFmt = new Intl.DateTimeFormat('en-PH', {timeZone:'Asia/Manila', weekday:'long', month:'short', day:'numeric', year:'numeric'});
    const timeFmt = new Intl.DateTimeFormat('en-PH', {timeZone:'Asia/Manila', hour:'2-digit', minute:'2-digit', hour12:true});
    function update() { const now = new Date(); dateEl.textContent = dateFmt.format(now); timeEl.textContent = timeFmt.format(now); }
    update(); window.setInterval(update, 1000);
})();
</script>
<script src="../assets/js/counselor/counselor-layout.js?v=<?= (int) @filemtime(__DIR__ . '/../assets/js/counselor/counselor-layout.js') ?>" defer></script>
</body>
</html>
