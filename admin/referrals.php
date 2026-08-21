<?php
require_once __DIR__ . "/../includes/session.php";
if (!isset($_SESSION['role_type']) || $_SESSION['role_type'] !== 'admin') {
    header("Location: ../auth/login.php");
    exit();
}
require_once __DIR__ . "/../config/database.php";

function table_exists($conn, $name) {
    $n = mysqli_real_escape_string($conn, $name);
    $res = mysqli_query($conn, "SHOW TABLES LIKE '$n'");
    return ($res && mysqli_num_rows($res) > 0);
}
function column_exists($conn, $table, $column) {
    $t = mysqli_real_escape_string($conn, $table);
    $c = mysqli_real_escape_string($conn, $column);
    $res = mysqli_query($conn, "SHOW COLUMNS FROM `$t` LIKE '$c'");
    return ($res && mysqli_num_rows($res) > 0);
}

$candidates = ['referrals','referral','referral_requests','referral_records','referral_submissions'];
$rtable = null;
foreach ($candidates as $c) { if (table_exists($conn, $c)) { $rtable = $c; break; } }

$assessment_candidates = ['assessments','assessment','assessment_results','assessment_submissions','assessment_records'];
$atable = null;
foreach ($assessment_candidates as $c) { if (table_exists($conn, $c)) { $atable = $c; break; } }

$counselors = [];
if (table_exists($conn, 'user_data')) {
    $cres = mysqli_query($conn, "SELECT user_id, fullname FROM user_data WHERE role_type = 'counselor' AND status = 'Active' ORDER BY fullname ASC");
    if ($cres) while ($r = mysqli_fetch_assoc($cres)) $counselors[] = $r;
}

$rows = [];
$idcol = null;
$student_col = null;
$status_col = null;
$assigned_col = null;
$created_col = null;

if ($rtable) {
    $idcol = null; foreach (['id','referral_id','request_id','ref_id'] as $c) { if (column_exists($conn,$rtable,$c)) { $idcol = $c; break; } }
    $student_col = null; foreach (['student_alias','alias','student_id','user_id','fullname','student_name'] as $c) { if (column_exists($conn,$rtable,$c)) { $student_col = $c; break; } }
    $status_col = null; foreach (['status','referral_status'] as $c) { if (column_exists($conn,$rtable,$c)) { $status_col = $c; break; } }
    $assigned_col = null; foreach (['assigned_counselor','counselor_id','assigned_to'] as $c) { if (column_exists($conn,$rtable,$c)) { $assigned_col = $c; break; } }
    $created_col = null; foreach (['created_at','submitted_at','requested_at'] as $c) { if (column_exists($conn,$rtable,$c)) { $created_col = $c; break; } }

    $sql = "SELECT * FROM `$rtable` ORDER BY " . ($created_col ? "`$created_col` DESC" : "1 DESC");
    $res = mysqli_query($conn, $sql);
    if ($res) while ($r = mysqli_fetch_assoc($res)) $rows[] = $r;
} elseif ($atable) {
    $aid = null; foreach (['id','assessment_id','submission_id'] as $c) { if (column_exists($conn,$atable,$c)) { $aid = $c; break; } }
    $astatus = null; foreach (['status','review_status'] as $c) { if (column_exists($conn,$atable,$c)) { $astatus = $c; break; } }
    $aalias = null; foreach (['student_alias','alias','student_id','user_id'] as $c) { if (column_exists($conn,$atable,$c)) { $aalias = $c; break; } }
    $created_col = null; foreach (['created_at','submitted_at','timestamp'] as $c) { if (column_exists($conn,$atable,$c)) { $created_col = $c; break; } }
    $assigned_col = null; foreach (['assigned_counselor','counselor_id','assigned_to'] as $c) { if (column_exists($conn,$atable,$c)) { $assigned_col = $c; break; } }
    if ($aid && $astatus) {
        $sql = "SELECT * FROM `$atable` WHERE `$astatus` IN ('Referred','referred') ORDER BY " . ($created_col ? "`$created_col` DESC" : "`$aid` DESC");
        $res = mysqli_query($conn, $sql);
        if ($res) while ($r = mysqli_fetch_assoc($res)) $rows[] = $r;
    }
    $idcol = $aid;
    $student_col = $aalias;
    $status_col = $astatus;
}
?>
<!doctype html>
<html>
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Referrals</title>
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
      .sidebar { width: 260px; background: #5c8a60; color: #fff; padding: 22px 18px 18px; display: flex; flex-direction: column; }
      .brand { font-size: 2rem; font-weight: 500; letter-spacing: -0.05em; padding: 6px 12px 20px; }
      .nav { display: flex; flex-direction: column; gap: 12px; margin-top: 24px; }
      .nav-link { display: flex; align-items: center; gap: 12px; padding: 12px 14px; border-radius: 10px; font-size: 1.05rem; font-weight: 600; color: #f1f5ef; }
      .nav-link.active, .nav-link:hover { background: #edf2ee; color: #1f3d2a; }
      .nav-icon { width: 22px; display: inline-flex; justify-content: center; }
      .logout { margin-top: auto; border-top: 1px solid rgba(255,255,255,0.25); padding-top: 16px; }
      .main-content { flex: 1; padding: 30px 32px 40px; }
      .page-title { font-size: 2.5rem; margin: 0 0 20px; font-weight: 600; letter-spacing: -0.05em; }
      .panel { background: rgba(255,255,255,0.2); border: 1px solid #dfe4dc; border-radius: 12px; padding: 18px 20px; }
      table { width:100%; border-collapse:collapse; margin-top:12px; }
      th, td { border-bottom:1px solid #dfe4dc; padding:12px 10px; text-align:left; }
      th { background: rgba(255,255,255,0.15); }
      .muted { color:#666; }
      .btn { padding: 8px 10px; border-radius:8px; background:#5c8a60; color:white; border:none; cursor:pointer; }
      select { padding:8px 10px; border:1px solid #cfd7d1; border-radius:8px; }
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
          <a class="nav-link active" href="referrals.php"><span class="nav-icon">🔁</span><span>Referrals</span></a>
          <a class="nav-link" href="reports.php"><span class="nav-icon">📊</span><span>Reports</span></a>
          <a class="nav-link" href="settings.php"><span class="nav-icon">⚙️</span><span>Settings</span></a>
          <a class="nav-link logout" href="../auth/logout.php"><span class="nav-icon">↩️</span><span>Logout</span></a>
        </nav>
      </aside>

      <main class="main-content">
        <h1 class="page-title">Referrals</h1>

        <?php if (!$rtable && !$atable): ?>
          <p style="color:#a00">No referrals table detected and no assessments table with a Referred status was found.</p>
        <?php else: ?>
          <section class="panel">
            <table>
              <thead>
                <tr><th>ID</th><th>Student</th><th>Assigned Counselor</th><th>Status</th><th>Requested</th><th>Actions</th></tr>
              </thead>
              <tbody>
                <?php if (empty($rows)): ?>
                  <tr><td colspan="6" class="muted">No referrals found.</td></tr>
                <?php else: ?>
                  <?php foreach ($rows as $r): ?>
                    <tr>
                      <td><?= htmlspecialchars($r[$idcol] ?? ($r['id'] ?? '')) ?></td>
                      <td><?= htmlspecialchars($r[$student_col] ?? ($r['student_name'] ?? $r['student_alias'] ?? '')) ?></td>
                      <td>
                        <select class="assign-select" data-id="<?= htmlspecialchars($r[$idcol] ?? ($r['id'] ?? '')) ?>">
                          <option value="">-- Unassigned --</option>
                          <?php foreach ($counselors as $c): ?>
                            <option value="<?= $c['user_id'] ?>" <?= (isset($r[$assigned_col]) && $r[$assigned_col] == $c['user_id']) ? 'selected' : '' ?>><?= htmlspecialchars($c['fullname']) ?></option>
                          <?php endforeach; ?>
                        </select>
                      </td>
                      <td><?= htmlspecialchars($r[$status_col] ?? ($r['status'] ?? '')) ?></td>
                      <td><?= htmlspecialchars($r[$created_col] ?? ($r['created_at'] ?? '')) ?></td>
                      <td>
                        <button class="btn" data-id="<?= htmlspecialchars($r[$idcol] ?? ($r['id'] ?? '')) ?>" data-action="mark-completed">Mark Completed</button>
                        <button class="btn" data-id="<?= htmlspecialchars($r[$idcol] ?? ($r['id'] ?? '')) ?>" data-action="mark-canceled">Mark Canceled</button>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </section>
        <?php endif; ?>
      </main>
    </div>

    <script>
      document.querySelectorAll('.assign-select').forEach(sel => {
        sel.addEventListener('change', async () => {
          const id = sel.dataset.id;
          const counselorId = sel.value;
          const data = new FormData();
          data.append('action','assign');
          data.append('referral_id', id);
          data.append('counselor_id', counselorId);

          const res = await fetch('referral_actions.php', { method: 'POST', body: data });
          const txt = await res.text();
          if (!res.ok) alert('Error: '+txt);
          else location.reload();
        });
      });

      document.querySelectorAll('.btn').forEach(b => {
        b.addEventListener('click', async () => {
          const id = b.dataset.id;
          const action = b.dataset.action;
          const data = new FormData();
          data.append('action','update_status');
          data.append('referral_id', id);
          if (action === 'mark-completed') data.append('status','Completed');
          if (action === 'mark-canceled') data.append('status','Canceled');
          const res = await fetch('referral_actions.php',{method:'POST',body:data});
          const txt = await res.text();
          if (!res.ok) alert('Error: '+txt); else location.reload();
        });
      });
    </script>
  </body>
</html>
