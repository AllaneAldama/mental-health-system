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
      /* Minimal styles to keep layout usable */
      body{font-family:Arial,Helvetica,sans-serif;margin:16px}
      nav ul{list-style:none;padding:0;display:flex;gap:12px}
      table{width:100%;border-collapse:collapse;margin-top:12px}
      th,td{border:1px solid #ddd;padding:8px;text-align:left}
      th{background:#f4f4f4}
      button{padding:6px 10px;margin-right:6px}
      form.inline{display:flex;gap:8px;align-items:center}
      dialog{padding:12px;border:1px solid #ccc}
    </style>
  </head>
  <body>
    <nav>
      <ul>
        <li><a href="dashboard.php">Dashboard</a></li>
        <li><a href="users.php">User Management</a></li>
        <li><a href="assessments.php">Assessment Management</a></li>
        <li><a href="reports.php">Reports</a></li>
        <li><a href="referrals.php">Referrals</a></li>
        <li><a href="settings.php">Settings</a></li>
      </ul>
    </nav>

    <main>
      <header style="display:flex;justify-content:space-between;align-items:center">
        <h1>User Management</h1>
        <button id="open-add">Add User</button>
      </header>

      <section>
        <form class="inline" id="filter-form" onsubmit="return false;">
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
        </form>
      </section>

      <section>
        <h2>Users</h2>

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

    <script>
      // modal handling
      const addModal = document.getElementById('add-user-modal');
      const editModal = document.getElementById('edit-user-modal');

      document.getElementById('open-add').addEventListener('click', () => addModal.showModal());
      document.getElementById('add-cancel').addEventListener('click', () => addModal.close());
      document.getElementById('edit-cancel').addEventListener('click', () => editModal.close());

      // add user
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

      // edit user: open and populate
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

      // toggle status
      document.querySelectorAll('.toggle-status-btn').forEach(btn => {
        btn.addEventListener('click', async () => {
          const id = btn.dataset.id;
          const action = btn.dataset.action; // activate or deactivate
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

      // filtering (client-side)
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
