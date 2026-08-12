<?php
require_once __DIR__ . "/../includes/session.php";
if (!isset($_SESSION['role_type']) || $_SESSION['role_type'] !== 'admin') {
    header("Location: ../auth/login.php");
    exit();
}
require_once __DIR__ . "/../config/database.php"; // $conn

function table_exists($conn,$name){$n=mysqli_real_escape_string($conn,$name);$res=mysqli_query($conn,"SHOW TABLES LIKE '$n'");return ($res && mysqli_num_rows($res)>0);} 
function column_exists($conn,$table,$column){$t=mysqli_real_escape_string($conn,$table);$c=mysqli_real_escape_string($conn,$column);$res=mysqli_query($conn,"SHOW COLUMNS FROM `$t` LIKE '$c'");return ($res && mysqli_num_rows($res)>0);} 

// find referral table
$candidates = ['referrals','referral','referral_requests','referral_records','referral_submissions'];
$rtable = null;
foreach ($candidates as $c) { if (table_exists($conn,$c)) { $rtable = $c; break; } }

// fallback: use assessments table where status='Referred'
$assessment_candidates = ['assessments','assessment','assessment_results','assessment_submissions','assessment_records'];
$atable = null; foreach ($assessment_candidates as $c) { if (table_exists($conn,$c)) { $atable = $c; break; } }

// fetch counselors for assignment
$counselors = [];
if (table_exists($conn,'user_data')) {
    $cres = mysqli_query($conn, "SELECT user_id, fullname FROM user_data WHERE role_type = 'counselor' AND status = 'Active' ORDER BY fullname ASC");
    if ($cres) { while ($r=mysqli_fetch_assoc($cres)) $counselors[]=$r; }
}

$rows = [];
if ($rtable) {
    // attempt to find common columns
    $idcol = null; foreach (['id','referral_id','request_id','ref_id'] as $c) { if (column_exists($conn,$rtable,$c)){ $idcol=$c; break; } }
    $student_col = null; foreach (['student_alias','alias','student_id','user_id','fullname'] as $c) { if (column_exists($conn,$rtable,$c)){ $student_col=$c; break; } }
    $status_col = null; foreach (['status','referral_status'] as $c) { if (column_exists($conn,$rtable,$c)){ $status_col=$c; break; } }
    $assigned_col = null; foreach (['assigned_counselor','counselor_id','assigned_to'] as $c) { if (column_exists($conn,$rtable,$c)){ $assigned_col=$c; break; } }
    $created_col = null; foreach (['created_at','submitted_at','requested_at'] as $c) { if (column_exists($conn,$rtable,$c)){ $created_col=$c; break; } }

    $sql = "SELECT * FROM `$rtable` ORDER BY " . ($created_col ? "`$created_col` DESC" : "1 DESC");
    $res = mysqli_query($conn,$sql);
    if ($res) { while($r=mysqli_fetch_assoc($res)) $rows[] = $r; }
} elseif ($atable) {
    // derive referrals from assessments where status='Referred'
    // find id and status and alias
    $aid=null; foreach (['id','assessment_id','submission_id'] as $c) { if (column_exists($conn,$atable,$c)){ $aid=$c; break; } }
    $astatus = null; foreach (['status','review_status'] as $c) { if (column_exists($conn,$atable,$c)){ $astatus=$c; break; } }
    $aalias = null; foreach (['student_alias','alias','student_id','user_id'] as $c) { if (column_exists($conn,$atable,$c)){ $aalias=$c; break; } }
    $assigned_col = null; foreach (['assigned_counselor','counselor_id','assigned_to'] as $c) { if (column_exists($conn,$atable,$c)){ $assigned_col=$c; break; } }
    if ($aid && $astatus) {
        $sql = "SELECT * FROM `$atable` WHERE `$astatus` IN ('Referred','referred') ORDER BY `" . ($created_col ?? $aid) . "` DESC";
        $res = mysqli_query($conn,$sql);
        if ($res) { while($r=mysqli_fetch_assoc($res)) $rows[]=$r; }
    }
}

?>
<!doctype html>
<html>
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Referrals</title>
    <style>body{font-family:Arial,Helvetica,sans-serif;margin:16px}table{width:100%;border-collapse:collapse}th,td{border:1px solid #ddd;padding:8px;text-align:left}th{background:#f4f4f4}.muted{color:#666}.btn{padding:6px 8px;border-radius:4px;background:#007bff;color:#fff;text-decoration:none}</style>
  </head>
  <body>
    <nav><ul style="list-style:none;padding:0;display:flex;gap:12px"><li><a href="dashboard.php">Dashboard</a></li>
        <li><a href="users.php">User Management</a></li>
        <li><a href="assessments.php">Assessments</a></li>
        <li><a href="reports.php">Reports</a></li>
        <li><a href="referrals.php">Referrals</a></li>
        <li><a href="settings.php">Settings</a></li>
        </ul>
    </nav>
    <main>
      <h1>Referrals</h1>

      <?php if (!$rtable && !$atable): ?>
        <p style="color:#a00">No referrals table detected and no assessments table with 'Referred' status found. If you have a referrals table, provide its name and columns.</p>
      <?php else: ?>

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

      <?php endif; ?>

    <script>
      // Assign counselor via fetch to referral_actions.php
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

    </main>
  </body>
</html>
