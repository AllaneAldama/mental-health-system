<?php
require_once __DIR__ . '/../includes/admin_session.php';
require_once __DIR__ . '/../config/database.php';

if (
    !isset($_SESSION['role_type']) ||
    strtolower($_SESSION['role_type']) !== 'admin'
) {
    header("Location: ../auth/login.php");
    exit();
}

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../includes/functions.php";

$app_version = '1.0.0';
$php_version = PHP_VERSION;
$os = php_uname();
$db_version = 'Unavailable';

$db_connected = (
    isset($conn) &&
    $conn instanceof mysqli &&
    mysqli_ping($conn)
);

if ($db_connected) {
    $res = mysqli_query($conn, "SELECT VERSION() AS v");
    if ($res) {
        $row = mysqli_fetch_assoc($res);
        $db_version = $row['v'] ?? 'Unavailable';
        mysqli_free_result($res);
    } else {
        $db_connected = false;
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Settings | PTC Wellness</title>
  <link rel="stylesheet" href="../assets/css/admin.css">
  <style>
    /* Settings-only styles; shared admin branding comes from admin.css. */
    .sidebar-footer { position: relative; display: block; margin-top: auto; padding: 15px 13px; border: 1px solid rgba(255,255,255,.15); border-radius: 14px; background: rgba(255,255,255,.07); color: rgba(255,255,255,.84); font-size: .78rem; line-height: 1.55; overflow-wrap: normal; }
    .sidebar-footer strong { display: block; margin-bottom: 4px; color: #fff; font-size: .82rem; line-height: 1.4; }
    .sidebar-footer span { display: block; }

    .settings-page { max-width: 1180px; margin: 0 auto; }
    .settings-heading { margin: 0 0 6px; color: #293a2d; font-size: clamp(1.8rem, 3vw, 2.35rem); letter-spacing: -.04em; }
    .settings-subtitle { margin: 0; color: #68756a; line-height: 1.6; }
    .settings-grid { display: grid; grid-template-columns: minmax(0, 1.2fr) minmax(280px, .8fr); gap: 20px; margin-top: 24px; align-items: start; }
    .settings-card { min-width: 0; padding: 26px; border: 1px solid #dfe8db; border-radius: 18px; background: rgba(255,255,255,.88); box-shadow: 0 12px 30px rgba(41,64,46,.055); }
    .settings-card h2 { margin: 0 0 8px; color: #293a2d; font-size: 1.25rem; letter-spacing: -.025em; }
    .settings-card-intro { margin: 0 0 22px; color: #728074; font-size: .92rem; line-height: 1.55; }
    .settings-icon { display: inline-grid; place-items: center; width: 42px; height: 42px; margin-bottom: 16px; border-radius: 13px; background: #edf6e8; color: #4f8054; }
    .settings-icon svg { width: 21px; height: 21px; }
    .settings-form { max-width: 560px; }
    .settings-form label { display: block; margin: 15px 0 7px; color: #354439; font-size: .9rem; font-weight: 650; }
    .settings-form input { display: block; width: 100%; min-height: 45px; padding: 11px 13px; border: 1px solid #d5dfd4; border-radius: 10px; background: #fff; color: #293a2d; font: inherit; outline: none; transition: border-color .18s ease, box-shadow .18s ease; }
    .settings-form input:focus { border-color: #5f8b5b; box-shadow: 0 0 0 4px rgba(95,139,91,.13); }
    .settings-form input::placeholder { color: #9aa49a; }
    .settings-form button { margin-top: 20px; min-height: 43px; padding: 11px 17px; border: 0; border-radius: 10px; background: #5f8b5b; color: #fff; font: inherit; font-size: .92rem; font-weight: 700; cursor: pointer; transition: background .18s ease, transform .18s ease; }
    .settings-form button:hover { background: #496f4d; }
    .settings-form button:active { transform: translateY(1px); }
    .settings-form button:disabled { cursor: wait; opacity: .72; }
    .settings-status { display: block; margin-top: 14px; min-height: 22px; font-size: .9rem; line-height: 1.5; }
    .settings-status.success { color: #237a3b; }
    .settings-status.error { color: #b42318; }
    .settings-status.info { color: #657267; }
    .system-list { display: grid; gap: 0; margin: 8px 0 0; }
    .system-row { display: flex; justify-content: space-between; align-items: flex-start; gap: 18px; padding: 14px 0; border-bottom: 1px solid #edf0e9; }
    .system-row:last-child { border-bottom: 0; padding-bottom: 0; }
    .system-row dt { color: #6d796e; font-size: .9rem; }
    .system-row dd { margin: 0; color: #293a2d; font-size: .9rem; font-weight: 650; text-align: right; overflow-wrap: anywhere; }
    .connection-status { display: inline-flex; align-items: center; gap: 7px; padding: 6px 10px; border-radius: 999px; font-size: .8rem; font-weight: 700; white-space: nowrap; }
    .connection-status::before { content: ""; width: 7px; height: 7px; border-radius: 50%; background: currentColor; }
    .connection-status.connected { color: #237a3b; background: #e2f5e8; }
    .connection-status.disconnected { color: #b42318; background: #fde8e7; }
    .security-note { display: flex; gap: 12px; align-items: flex-start; margin-top: 18px; padding: 15px; border: 1px solid #e0ebd9; border-radius: 13px; background: #f1f8ed; color: #4f6651; font-size: .88rem; line-height: 1.55; }
    .security-note svg { width: 20px; height: 20px; flex: 0 0 auto; margin-top: 1px; color: #5f8b5b; }
    @media (max-width: 900px) { .settings-grid { grid-template-columns: 1fr; } }
    @media (max-width: 560px) { .settings-card { padding: 20px 17px; border-radius: 15px; } .system-row { gap: 12px; } .system-row dt, .system-row dd { font-size: .84rem; } .settings-form button { width: 100%; } }
    @media (prefers-reduced-motion: reduce) { .settings-form input, .settings-form button { transition: none; } }
  
/* Admin logout modal sizing and spacing fix */
.logout-overlay .logout-dialog {
    width: min(500px, calc(100vw - 32px)) !important;
    max-width: 500px !important;
    box-sizing: border-box;
    padding: 36px 34px !important;
    border-radius: 20px;
}

.logout-overlay .logout-icon {
    width: 64px;
    height: 64px;
    margin: 0 auto 20px;
}

.logout-overlay .logout-dialog h2 {
    margin: 0 0 14px;
    font-size: 1.3rem;
    line-height: 1.45;
    overflow-wrap: normal;
    word-break: normal;
}

.logout-overlay .logout-dialog p {
    margin: 0 0 26px;
    font-size: 0.92rem;
    line-height: 1.75;
}

.logout-overlay .logout-actions {
    display: flex;
    flex-direction: row;
    justify-content: center;
    align-items: stretch;
    gap: 12px;
}

.logout-overlay .logout-actions button {
    flex: 1 1 0;
    width: auto;
    min-width: 0;
    min-height: 44px;
    padding: 12px 16px;
    border-radius: 10px;
    font-size: 0.88rem;
}

@media (max-width: 480px) {
    .logout-overlay .logout-dialog {
        width: calc(100vw - 32px) !important;
        padding: 28px 22px !important;
    }

    .logout-overlay .logout-dialog h2 {
        font-size: 1.15rem;
    }

    .logout-overlay .logout-actions {
        flex-direction: column;
    }

    .logout-overlay .logout-actions button {
        flex: auto;
        width: 100%;
    }
}

  </style>
</head>


<body>
<div class="app-shell">
  <aside class="sidebar">
    <div class="brand">
      <span class="brand-mark" aria-hidden="true">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20v-8"/><path d="M12 13C6 13 4 9 4 5c5 0 8 2 8 8"/><path d="M12 16c0-5 3-8 8-8 0 5-2 8-8 8"/><path d="M5 20h14"/></svg>
      </span>
      <span class="brand-copy">
        <strong class="brand-name">PTC Wellness</strong>
        <small class="brand-tagline">Mental Health &amp; Well-being</small>
      </span>
    </div>
    <nav class="nav" aria-label="Main navigation">
      <a class="nav-link" href="dashboard.php"><span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="m3 10 9-7 9 7"/><path d="M5 9v11h14V9"/><path d="M9 20v-7h6v7"/></svg></span><span>Dashboard</span></a>
      <a class="nav-link" href="users.php"><span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="10" cy="7" r="4"/><path d="M20 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg></span><span>Users</span></a>
      <a class="nav-link active" href="settings.php" aria-current="page"><span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="m19.4 15 .1.1 1.4 1.1-1.4 2.4-1.7-.6a8 8 0 0 1-1.7 1l-.3 1.8h-2.8l-.3-1.8a8 8 0 0 1-1.7-1l-1.7.6-1.4-2.4 1.4-1.1a7 7 0 0 1 0-2l-1.4-1.1 1.4-2.4 1.7.6a8 8 0 0 1 1.7-1l.3-1.8h2.8l.3 1.8a8 8 0 0 1 1.7 1l1.7-.6 1.4 2.4-1.4 1.1a7 7 0 0 1 0 2Z" transform="translate(-1 -1) scale(1.08)"/></svg></span><span>Settings</span></a>
      <a class="nav-link logout" href="../auth/logout.php?portal=admin"><span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M10 17l5-5-5-5"/><path d="M15 12H3"/><path d="M12 3h7a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-7"/></svg></span><span>Logout</span></a>
    </nav>
    <div class="sidebar-footer"><strong>Well-being matters.</strong><span>Supporting a healthier campus, one step at a time.</span></div>
  </aside>

  <main class="main-content settings-page">
    <header class="settings-page-header">
      <p class="eyebrow">PTC WELLNESS · ADMINISTRATION</p>
      <h1 class="settings-heading">Settings</h1>
      <p class="settings-subtitle">Manage your administrator password and review system information.</p>
    </header>

    <div class="settings-grid">
      <section class="settings-card" aria-labelledby="password-heading">
        <span class="settings-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/><path d="M12 14v3"/></svg></span>
        <h2 id="password-heading">Account Security</h2>
        <p class="settings-card-intro">Update your administrator password. Enter your current password to confirm the change.</p>
        <form id="change-password-form" class="settings-form">
          <label for="current_password">Current Password</label>
          <input type="password" id="current_password" name="current_password" autocomplete="current-password" placeholder="Enter current password" required>
          <label for="new_password">New Password</label>
          <input type="password" id="new_password" name="new_password" autocomplete="new-password" minlength="8" maxlength="72" placeholder="At least 8 characters" required>
          <label for="confirm_password">Confirm New Password</label>
          <input type="password" id="confirm_password" name="confirm_password" autocomplete="new-password" minlength="8" maxlength="72" placeholder="Re-enter new password" required>
          <button type="submit">Change Password</button>
          <span id="change-status" class="settings-status" role="status" aria-live="polite"></span>
        </form>
        <div class="security-note">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 22s8-4 8-11V5l-8-3-8 3v6c0 7 8 11 8 11Z"/><path d="m9 12 2 2 4-4"/></svg>
          <span><strong>Keep your account secure.</strong> Never share your administrator password with other users.</span>
        </div>
      </section>

      <section class="settings-card" aria-labelledby="system-heading">
        <span class="settings-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M7 8h10M7 12h5M7 16h7"/></svg></span>
        <h2 id="system-heading">System Information</h2>
        <p class="settings-card-intro">Current application and server environment details.</p>
        <dl class="system-list">
          <div class="system-row"><dt>Application version</dt><dd><?= htmlspecialchars($app_version, ENT_QUOTES, 'UTF-8') ?></dd></div>
          <div class="system-row"><dt>PHP version</dt><dd><?= htmlspecialchars($php_version, ENT_QUOTES, 'UTF-8') ?></dd></div>
          <div class="system-row"><dt>Database version</dt><dd><?= htmlspecialchars($db_version, ENT_QUOTES, 'UTF-8') ?></dd></div>
          <div class="system-row"><dt>Server OS</dt><dd><?= htmlspecialchars($os, ENT_QUOTES, 'UTF-8') ?></dd></div>
          <div class="system-row"><dt>Database connection</dt><dd><span class="connection-status <?= $db_connected ? 'connected' : 'disconnected' ?>"><?= $db_connected ? 'Connected' : 'Disconnected' ?></span></dd></div>
        </dl>
      </section>
    </div>
  </main>
</div>

<!-- Logout Confirmation Modal -->
      <div class="logout-overlay" id="logout-modal"
          aria-hidden="true">
        <section class="logout-dialog"
                role="dialog"
                aria-modal="true"
                aria-labelledby="logout-title"
                aria-describedby="logout-description">

          <div class="logout-icon">
            <svg viewBox="0 0 24 24" fill="none"
                stroke="currentColor" stroke-width="1.8"
                stroke-linecap="round" stroke-linejoin="round"
                aria-hidden="true">
              <path d="M10 17l5-5-5-5"/>
              <path d="M15 12H3"/>
              <path d="M12 3h7a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-7"/>
            </svg>
          </div>

          <h2 id="logout-title">Are you sure you want to log out?</h2>

          <p id="logout-description">
            You may lose any unsaved changes. Please make sure
            you've saved your work before leaving.
          </p>

          <div class="logout-actions">
            <button type="button" class="logout-confirm"
                    id="confirm-logout">
              Yes, Log Out
            </button>

            <button type="button" class="logout-cancel"
                    id="cancel-logout">
              Go Back
            </button>
          </div>
        </section>
      </div>
      <script>
    (() => {
        const modal = document.getElementById('logout-modal');
        const cancelButton = document.getElementById('cancel-logout');
        const confirmButton = document.getElementById('confirm-logout');

        if (!modal || !cancelButton || !confirmButton) return;

        const logoutLinks = document.querySelectorAll(
            'a[href*="logout.php"]'
        );

        let previousFocus = null;

        function openLogoutModal(event) {
            event.preventDefault();
            previousFocus = document.activeElement;
            modal.classList.add('is-open');
            modal.setAttribute('aria-hidden', 'false');
            document.body.style.overflow = 'hidden';
            cancelButton.focus();
        }

        function closeLogoutModal() {
            modal.classList.remove('is-open');
            modal.setAttribute('aria-hidden', 'true');
            document.body.style.overflow = '';

            if (previousFocus) previousFocus.focus();
        }

        logoutLinks.forEach(link => {
            link.addEventListener('click', openLogoutModal);
        });

        cancelButton.addEventListener('click', closeLogoutModal);

        confirmButton.addEventListener('click', () => {
            window.location.href = '../auth/logout.php?portal=admin';
        });

        modal.addEventListener('click', event => {
            if (event.target === modal) closeLogoutModal();
        });

        document.addEventListener('keydown', event => {
            if (event.key === 'Escape' &&
                modal.classList.contains('is-open')) {
                closeLogoutModal();
            }
        });
    })();
    </script>    

<script>
document.getElementById('change-password-form').addEventListener('submit', async (event) => {
  event.preventDefault();

  const form = event.currentTarget;
  const currentPassword = document.getElementById('current_password').value;
  const newPassword = document.getElementById('new_password').value;
  const confirmPassword = document.getElementById('confirm_password').value;
  const status = document.getElementById('change-status');
  const button = form.querySelector('button[type="submit"]');

  status.textContent = '';
  status.className = 'settings-status';

  if (newPassword !== confirmPassword) {
    status.textContent = 'New password and confirmation do not match.';
    status.classList.add('error');
    return;
  }
  if (newPassword.length < 8 || newPassword.length > 72) {
    status.textContent = 'Use a password between 8 and 72 characters.';
    status.classList.add('error');
    return;
  }
  if (newPassword === currentPassword) {
    status.textContent = 'Choose a password different from your current password.';
    status.classList.add('error');
    return;
  }

  const formData = new FormData();
  formData.append('action', 'change_password');
  formData.append('current_password', currentPassword);
  formData.append('new_password', newPassword);

  button.disabled = true;
  button.textContent = 'Updating...';
  status.textContent = 'Verifying your account...';
  status.classList.add('info');

  try {
    const response = await fetch('settings_actions.php', {
      method: 'POST',
      body: formData,
      credentials: 'same-origin'
    });
    const message = (await response.text()).trim();

    if (!response.ok || message !== 'OK') {
      status.textContent = message || 'Unable to change password.';
      status.className = 'settings-status error';
      return;
    }

    status.textContent = 'Password changed successfully!';
    status.className = 'settings-status success';
    form.reset();
  } catch (error) {
    status.textContent = 'Connection error. Please try again.';
    status.className = 'settings-status error';
  } finally {
    button.disabled = false;
    button.textContent = 'Change Password';
  }
});
</script>
</body>
</html>
