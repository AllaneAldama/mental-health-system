<?php
require_once __DIR__ . "/../includes/session.php"; // ensures user is logged in
// allow only admin
if (!isset($_SESSION['role_type']) || $_SESSION['role_type'] !== 'admin') {
    header("Location: ../auth/login.php");
    exit();
}
require_once __DIR__ . "/../config/database.php"; // $conn

// fetch users
$sql = "SELECT user_id, fullname, email, role_type, status FROM user_data ORDER BY fullname ASC";
$result = mysqli_query($conn, $sql);
$users = [];
if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $users[] = $row;
    }
}
?>
<!doctype html>
<html>
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Users</title>
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
      .page-header { display:flex; align-items:center; justify-content:space-between; margin-bottom: 18px; }
      .page-title { font-size: 2.5rem; margin: 0; font-weight: 600; letter-spacing: -0.05em; }
      .panel { background: rgba(255,255,255,0.2); border: 1px solid #dfe4dc; border-radius: 12px; padding: 18px 20px; }
      .filter-row { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
      .filter-row label { font-weight: 600; }
      .filter-row input, .filter-row select { padding: 8px 10px; border: 1px solid #cfd7d1; border-radius: 8px; }
      button { padding: 9px 12px; border: 1px solid #cfd7d1; border-radius: 8px; background: #fff; cursor: pointer; }
      button#open-add { background: #5c8a60; border-color: #5c8a60; color: white; }
      table { width: 100%; border-collapse: collapse; margin-top: 12px; }
      th, td { border-bottom: 1px solid #dfe4dc; padding: 12px 10px; text-align: left; }
      th { background: rgba(255,255,255,0.15); }
      dialog { padding: 20px; border: 1px solid #dfe4dc; border-radius: 12px; }
      @media (max-width: 980px) { .app-shell { display: block; } .sidebar { width: 100%; } .main-content { padding: 22px 18px 30px; } }
    </style>
  </head>
  <body>
    <div class="app-shell">
      <aside class="sidebar">
        <div class="brand">PTC Wellness</div>
        <nav class="nav" aria-label="Main navigation">
          <a class="nav-link" href="dashboard.php"><span class="nav-icon">🏠</span><span>Dashboard</span></a>
          <a class="nav-link active" href="users.php"><span class="nav-icon">👥</span><span>Users</span></a>
          <a class="nav-link" href="assessments.php"><span class="nav-icon">📝</span><span>Assessments</span></a>
          <a class="nav-link" href="referrals.php"><span class="nav-icon">🔁</span><span>Referrals</span></a>
          <a class="nav-link" href="reports.php"><span class="nav-icon">📊</span><span>Reports</span></a>
          <a class="nav-link" href="settings.php"><span class="nav-icon">⚙️</span><span>Settings</span></a>
          <a class="nav-link logout" href="../auth/logout.php"><span class="nav-icon">↩️</span><span>Logout</span></a>
        </nav>
      </aside>

      <main class="main-content">
        <header class="page-header">
          <h1 class="page-title">User Management</h1>
          <button id="open-add">Add User</button>
        </header>

        <section class="panel">
          <div class="filter-row" id="filter-form">
            <label for="search-user">Search</label>
            <input type="search" id="search-user" placeholder="Search by name or email" />

            <label for="role">Role</label>
            <select id="role">
              <option value="">All Roles</option>
              <option value="admin">Admin</option>
              <option value="counselor">Counselor</option>
              <option value="student">Student</option>
            </select>

            <label for="status">Status</label>
            <select id="status">
              <option value="">All</option>
              <option value="Active">Active</option>
              <option value="Deactive">Deactive</option>
            </select>

            <button id="apply-filter">Apply</button>
          </div>
        </section>

        <section class="panel" style="margin-top:20px;">
          <table id="users-table">
            <thead>
              <tr>
                <th>Name</th>
                <th>Email</th>
                <th>Role</th>
                <th>Status</th>
                <th>Actions</th>
              </tr>
            </thead>

            <tbody>
              <?php foreach ($users as $u): ?>
              <tr data-name="<?= htmlspecialchars(strtolower($u['fullname'])) ?>" data-email="<?= htmlspecialchars(strtolower($u['email'])) ?>" data-role="<?= htmlspecialchars($u['role_type']) ?>" data-status="<?= htmlspecialchars($u['status']) ?>">
                <td><?= htmlspecialchars($u['fullname']) ?></td>
                <td><?= htmlspecialchars($u['email']) ?></td>
                <td><?= htmlspecialchars(ucfirst($u['role_type'])) ?></td>
                <td><?= htmlspecialchars($u['status']) ?></td>
                <td>
                  <button class="edit-btn" data-id="<?= $u['user_id'] ?>">Edit</button>
                  <?php if ($u['status'] === 'Active'): ?>
                    <button class="toggle-status-btn" data-id="<?= $u['user_id'] ?>" data-action="deactivate">Deactivate</button>
                  <?php else: ?>
                    <button class="toggle-status-btn" data-id="<?= $u['user_id'] ?>" data-action="activate">Activate</button>
                  <?php endif; ?>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </section>
      </main>

      <dialog id="add-user-modal">
        <form id="add-user-form">
          <h2>Add User</h2>
          <label for="fullname">Full Name</label>
          <input type="text" id="fullname" name="fullname" required />

          <label for="email">Email</label>
          <input type="email" id="email" name="email" required />

          <label for="password">Password</label>
          <input type="password" id="password" name="password" required />

          <label for="user-role">Role</label>
          <select id="user-role" name="role_type">
            <option value="admin">Admin</option>
            <option value="counselor">Counselor</option>
            <option value="student">Student</option>
          </select>

          <div style="margin-top:12px">
            <button type="submit">Save</button>
            <button type="button" id="add-cancel">Cancel</button>
          </div>
        </form>
      </dialog>

      <dialog id="edit-user-modal">
        <form id="edit-user-form">
          <h2>Edit User</h2>
          <input type="hidden" id="edit-id" name="user_id" />

          <label for="edit-name">Full Name</label>
          <input type="text" id="edit-name" name="fullname" required />

          <label for="edit-email">Email</label>
          <input type="email" id="edit-email" name="email" required />

          <label for="edit-role">Role</label>
          <select id="edit-role" name="role_type">
            <option value="admin">Admin</option>
            <option value="counselor">Counselor</option>
            <option value="student">Student</option>
          </select>

          <div style="margin-top:12px">
            <button type="submit">Update</button>
            <button type="button" id="edit-cancel">Cancel</button>
          </div>
        </form>
      </dialog>
    </div>

    <script>
      const addModal = document.getElementById('add-user-modal');
      const editModal = document.getElementById('edit-user-modal');

      document.getElementById('open-add').addEventListener('click', () => addModal.showModal());
      document.getElementById('add-cancel').addEventListener('click', () => addModal.close());
      document.getElementById('edit-cancel').addEventListener('click', () => editModal.close());

      document.getElementById('add-user-form').addEventListener('submit', async (e) => {
        e.preventDefault();
        const form = e.target;
        const data = new FormData(form);
        data.append('action', 'add');

        const res = await fetch('user_actions.php', { method: 'POST', body: data });
        const txt = await res.text();
        if (res.ok) {
          location.reload();
        } else {
          alert('Error: ' + txt);
        }
      });

      document.querySelectorAll('.edit-btn').forEach(btn => {
        btn.addEventListener('click', () => {
          const id = btn.dataset.id;
          const tr = btn.closest('tr');
          document.getElementById('edit-id').value = id;
          document.getElementById('edit-name').value = tr.children[0].textContent.trim();
          document.getElementById('edit-email').value = tr.children[1].textContent.trim();
          const role = tr.children[2].textContent.trim().toLowerCase();
          document.getElementById('edit-role').value = role;
          editModal.showModal();
        });
      });

      document.getElementById('edit-user-form').addEventListener('submit', async (e) => {
        e.preventDefault();
        const form = e.target;
        const data = new FormData(form);
        data.append('action', 'edit');

        const res = await fetch('user_actions.php', { method: 'POST', body: data });
        const txt = await res.text();
        if (res.ok) {
          location.reload();
        } else {
          alert('Error: ' + txt);
        }
      });

      document.querySelectorAll('.toggle-status-btn').forEach(btn => {
        btn.addEventListener('click', async () => {
          const id = btn.dataset.id;
          const action = btn.dataset.action;
          const data = new FormData();
          data.append('action', 'toggle');
          data.append('user_id', id);
          data.append('toggle_action', action);

          const res = await fetch('user_actions.php', { method: 'POST', body: data });
          const txt = await res.text();
          if (res.ok) {
            location.reload();
          } else {
            alert('Error: ' + txt);
          }
        });
      });

      document.getElementById('apply-filter').addEventListener('click', () => {
        const q = document.getElementById('search-user').value.trim().toLowerCase();
        const role = document.getElementById('role').value;
        const status = document.getElementById('status').value;
        document.querySelectorAll('#users-table tbody tr').forEach(tr => {
          const name = tr.dataset.name || '';
          const email = tr.dataset.email || '';
          const r = tr.dataset.role || '';
          const s = tr.dataset.status || '';
          let visible = true;
          if (q && !(name.includes(q) || email.includes(q))) visible = false;
          if (role && r !== role) visible = false;
          if (status && s !== status) visible = false;
          tr.style.display = visible ? '' : 'none';
        });
      });
    </script>
  </body>
</html>
