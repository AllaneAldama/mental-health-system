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
    <title>Profile - Counselor Dashboard</title>
    <link rel="stylesheet" href="../assets/css/counselor.css">
    
</head>
<body class="counselor-page">
    <div class="app-shell">
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
                <span class="nav-icon"><svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1"></rect><rect x="14" y="3" width="7" height="7" rx="1"></rect><rect x="3" y="14" width="7" height="7" rx="1"></rect><rect x="14" y="14" width="7" height="7" rx="1"></rect></svg></span>
                Dashboard
            </a>
            <a class="nav-link" href="assessment_queue.php">
                <span class="nav-icon"><svg viewBox="0 0 24 24"><path d="M8 6h13M8 12h13M8 18h13"></path><path d="m3 6 1 1 2-2M3 12l1 1 2-2M3 18l1 1 2-2"></path></svg></span>
                Assessments Queue
            </a>
            <a class="nav-link" href="monitoring.php">
                <span class="nav-icon"><svg viewBox="0 0 24 24"><path d="M3 12s3-7 9-7 9 7 9 7-3 7-9 7-9-7-9-7Z"></path><circle cx="12" cy="12" r="3"></circle></svg></span>
                Monitoring
            </a>
            <a class="nav-link" href="referral.php">
                <span class="nav-icon"><svg viewBox="0 0 24 24"><path d="M7 17 17 7M7 7h10v10"></path><path d="M5 5v14h14"></path></svg></span>
                Referral
            </a>
            <a class="nav-link" href="assessment_history.php">
                <span class="nav-icon"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"></circle><path d="M12 7v5l3 2"></path></svg></span>
                Assessment History
            </a>
            <a class="nav-link active" href="profile.php" aria-current="page">
                <span class="nav-icon"><svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"></circle><path d="M4 21a8 8 0 0 1 16 0"></path></svg></span>
                Profile
            </a>
            <a class="nav-link logout" href="../auth/logout.php?portal=counselor">
                <span class="nav-icon"><svg viewBox="0 0 24 24"><path d="M10 17l5-5-5-5M15 12H3"></path><path d="M12 3h7a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-7"></path></svg></span>
                Logout
            </a>
        </nav>

        <div class="sidebar-footer">
            <strong>Well-being matters.</strong>
            <span>Supporting a healthier campus, one step at a time.</span>
        </div>
      </aside>

        <main class="main-content">
            <header class="page-header">
                <div class="page-heading">
                    <p class="eyebrow">PTC Wellness · Guidance Office</p>
                    <h1 class="page-title">Profile Settings</h1>
                    <p class="page-subtitle">Manage your counselor account information and keep your account secure.</p>
                </div>
                <<span class="portal-badge">
                <span class="portal-dot"></span>
                     Counselor Portal
                </span>
            </header>

            <div class="profile-grid">
                <section class="panel">
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
                        <div style="margin-top: 18px;">
                            <button type="submit" class="btn">Update Profile</button>
                        </div>
                    </form>
                </section>

                <section class="panel">
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
                        <div style="margin-top: 18px;">
                            <button type="submit" class="btn">Change Password</button>
                        </div>
                    </form>
                </section>
            </div>
            <footer class="page-footer">
                <span>PTC Wellness · Counselor Portal</span>
                <span>Confidential account information — keep your password private.</span>
            </footer>
        </main>
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
        background: rgba(15, 35, 20, .58);
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

<div class="logout-overlay" id="logout-modal" aria-hidden="true">
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
            <button type="button" class="logout-confirm" id="confirm-logout">
                Yes, Log Out
            </button>

            <button type="button" class="logout-cancel" id="cancel-logout">
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
        if (event.key === 'Escape' && modal.classList.contains('is-open')) {
            closeModal();
        }
    });
})();
</script>
</body>
</html>
