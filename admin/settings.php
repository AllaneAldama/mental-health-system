<?php
require_once __DIR__ . "/../includes/session.php";
if (!isset($_SESSION['role_type']) || $_SESSION['role_type'] !== 'admin') {
    header("Location: ../auth/login.php");
    exit();
}
require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../includes/functions.php";

// Basic system info
$php_version = phpversion();
$os = php_uname();
$db_version = '';
$res = mysqli_query($conn, "SELECT VERSION() AS v");
if ($res) {
    $r = mysqli_fetch_assoc($res);
    $db_version = $r['v'] ?? '';
}
$app_version = '1.0.0'; // bump if you track versions elsewhere

?>
<!doctype html>
<html>
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title>Settings</title>
    <style>
      body{font-family:Arial,Helvetica,sans-serif;margin:16px}
      nav ul{list-style:none;padding:0;display:flex;gap:12px}
      form{max-width:480px;margin-top:12px}
      label{display:block;margin-top:8px}
      input{width:100%;padding:8px;margin-top:4px}
      .card{border:1px solid #ddd;padding:12px;border-radius:6px;margin-top:12px}
      .btn{display:inline-block;padding:8px 12px;background:#007bff;color:#fff;border-radius:4px;text-decoration:none}
      .muted{color:#666}
    </style>
  </head>
  <body>
    <nav>
      <ul>
        <li><a href="dashboard.php">Dashboard</a></li>
        <li><a href="users.php">User Management</a></li>
        <li><a href="assessments.php">Assessments</a></li>
        <li><a href="reports.php">Reports</a></li>
        <li><a href="referrals.php">Referrals</a></li>
        <li><a href="settings.php">Settings</a></li>
      </ul>
    </nav>

    <main>
      <h1>Settings</h1>

      <section class="card">
        <h2>Change Password</h2>
        <form id="change-password-form">
          <label for="current_password">Current Password</label>
          <input type="password" id="current_password" name="current_password" required />

          <label for="new_password">New Password</label>
          <input type="password" id="new_password" name="new_password" required />

          <label for="confirm_password">Confirm New Password</label>
          <input type="password" id="confirm_password" name="confirm_password" required />

          <div style="margin-top:12px">
            <button type="submit" class="btn">Change Password</button>
            <span id="change-status" class="muted" style="margin-left:12px"></span>
          </div>
        </form>
      </section>

      <section class="card">
        <h2>System Information</h2>
        <p><strong>Application version:</strong> <?= htmlspecialchars($app_version) ?></p>
        <p><strong>PHP version:</strong> <?= htmlspecialchars($php_version) ?></p>
        <p><strong>Database version:</strong> <?= htmlspecialchars($db_version) ?></p>
        <p><strong>Server OS:</strong> <?= htmlspecialchars($os) ?></p>
      </section>

    </main>

    <script>
      document.getElementById('change-password-form').addEventListener('submit', async (e) => {
        e.preventDefault();
        const cur = document.getElementById('current_password').value;
        const nw = document.getElementById('new_password').value;
        const cn = document.getElementById('confirm_password').value;
        const status = document.getElementById('change-status');
        status.textContent = '';

        if (nw !== cn) { status.textContent = 'New password and confirmation do not match'; return; }
        if (nw.length < 8) { status.textContent = 'New password should be at least 8 characters'; return; }

        const fd = new FormData();
        fd.append('action','change_password');
        fd.append('current_password', cur);
        fd.append('new_password', nw);

        const res = await fetch('settings_actions.php', { method: 'POST', body: fd });
        const txt = await res.text();
        if (res.ok) {
          status.textContent = 'Password changed successfully';
          document.getElementById('current_password').value='';
          document.getElementById('new_password').value='';
          document.getElementById('confirm_password').value='';
        } else {
          status.textContent = txt || 'Error changing password';
        }
      });
    </script>
  </body>
</html>
