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
      * { box-sizing: border-box; }
      html, body { margin: 0; padding: 0; }
      body { font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif; background: #efeeeb; color: #1a1a1a; }
      a { text-decoration: none; color: inherit; }
      .app-shell { display: flex; min-height: 100vh; }
      .sidebar { width: 260px; background: #5c8a60; color: #fff; padding: 22px 18px 18px; display: flex; flex-direction: column; }
      .brand { font-size: 2rem; font-weight: 500; letter-spacing: -0.05em; padding: 6px 12px 20px; }
      .nav { display: flex; flex-direction: column; gap: 12px; margin-top: 24px; }
      .nav-link { display: flex; align-items: center; gap: 12px; padding: 12px 14px; border-radius: 10px; font-size: 1.05rem; font-weight: 600; color: #f1f5ef; }
      .nav-link.active, .nav-link:hover { background: #edf2ee; color: #1f3d2a; }
      .nav-icon { width: 22px; display: inline-flex; justify-content: center; }
      .logout { margin-top: auto; border-top: 1px solid rgba(255,255,255,0.25); padding-top: 16px; }
      .main-content { flex: 1; padding: 30px 32px 40px; }
      .page-title { font-size: 2.5rem; margin: 0 0 20px; font-weight: 600; letter-spacing: -0.05em; }
      form { max-width: 480px; }
      label { display: block; margin-top: 8px; font-weight: 600; }
      input { width: 100%; padding: 10px 12px; margin-top: 6px; border-radius: 8px; border: 1px solid #cfd7d1; }
      .card { border: 1px solid #dfe4dc; border-radius: 12px; background: rgba(255,255,255,0.2); padding: 18px 20px; margin-top: 18px; }
      .btn { display:inline-block; padding: 10px 14px; border-radius: 8px; background: #5c8a60; color:#fff; border:none; cursor:pointer; }
      .muted{color:#666}
      @media (max-width: 980px) { .app-shell { display: block; } .sidebar { width: 100%; } .main-content { padding: 22px 18px 30px; } }
    </style>
  </head>
  <body>
    <div class="app-shell">
      <aside class="sidebar">
        <div class="brand">PTC Wellness</div>
        <nav class="nav" aria-label="Main navigation">
          <a class="nav-link" href="dashboard.php"><span class="nav-icon">🏠</span><span>Dashboard</span></a>
          <a class="nav-link" href="users.php"><span class="nav-icon">👥</span><span>Users</span></a>
          <a class="nav-link" href="assessments.php"><span class="nav-icon">📝</span><span>Assessments</span></a>
          <a class="nav-link" href="referrals.php"><span class="nav-icon">🔁</span><span>Referrals</span></a>
          <a class="nav-link" href="reports.php"><span class="nav-icon">📊</span><span>Reports</span></a>
          <a class="nav-link active" href="settings.php"><span class="nav-icon">⚙️</span><span>Settings</span></a>
          <a class="nav-link logout" href="../auth/logout.php"><span class="nav-icon">↩️</span><span>Logout</span></a>
        </nav>
      </aside>
      <main class="main-content">
        <h1 class="page-title">Settings</h1>

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
