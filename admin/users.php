
<?php
require_once __DIR__ . '/../includes/admin_session.php';
require_once __DIR__ . '/../config/database.php';

// Allow only administrators.
if (
    !isset($_SESSION['role_type']) ||
    $_SESSION['role_type'] !== 'admin'
) {
    header("Location: ../auth/login.php");
    exit();
}

// CSRF token for sensitive account-management actions.
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

require_once __DIR__ . "/../config/database.php";

// Fetch Admin and Counselor accounts only.
// Student assessment information must not appear here.
$sql = "
    SELECT user_id, fullname, email, role_type, status
    FROM user_data
    WHERE role_type IN ('admin', 'counselor')
    ORDER BY fullname ASC
";

$result = mysqli_query($conn, $sql);
$users = [];

if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $users[] = $row;
    }
} else {
    error_log("Admin user list query failed: " . mysqli_error($conn));
}

function userPageEscape($value)
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>User Management | PTC Wellness</title>
    
    <link rel="stylesheet" href="../assets/css/admin.css">


    <style>

            .account-confirm-modal {
                width: min(420px, calc(100% - 32px));
                padding: 28px;
                border: 1px solid #dfe8dc;
                border-radius: 20px;
                background: #fbfcf9;
                color: #293a2d;
                text-align: center;
                box-shadow: 0 24px 70px rgba(24, 39, 28, .2);
            }

            .account-confirm-modal::backdrop {
                background: rgba(24, 39, 28, .5);
                backdrop-filter: blur(4px);
            }

            .account-confirm-modal[open] {
                animation: confirmModalIn .22s ease-out;
            }

            .account-confirm-icon {
                display: grid;
                place-items: center;
                width: 58px;
                height: 58px;
                margin: 0 auto 16px;
                border-radius: 50%;
                background: #edf5e9;
                color: #47794c;
                font-size: 26px;
                font-weight: 700;
            }

            .account-confirm-modal h2 {
                margin: 0 0 10px;
                font-size: 1.25rem;
            }

            .account-confirm-modal p {
                color: #68756a;
                line-height: 1.6;
            }

            .account-confirm-actions {
                display: flex;
                justify-content: center;
                flex-wrap: wrap;
                gap: 10px;
                margin-top: 22px;
            }

            .account-confirm-actions button {
                padding: 11px 16px;
                border: 1px solid #d8e2d5;
                border-radius: 10px;
                background: white;
                color: #354439;
                font-weight: 600;
                cursor: pointer;
            }

            .account-confirm-actions #account-confirm-yes {
                border-color: #5f8b5b;
                background: #5f8b5b;
                color: white;
            }

            @keyframes confirmModalIn {
                from { opacity: 0; transform: translateY(8px) scale(.97); }
                to { opacity: 1; transform: translateY(0) scale(1); }
            }
      /* Page-specific styles; shared branding comes from ../assets/css/admin.css */
      .accounts-panel { margin-top:18px; }
      .page-description { margin: 8px 0 0; color: var(--muted, #6c786f); font-size: .94rem; line-height: 1.6; }
      .panel {
        background: rgba(255,255,255,.88);
        border: 1px solid var(--border, #e0e8dc);
        border-radius: 17px;
        padding: 19px;
        box-shadow: 0 8px 26px rgba(43,69,44,.035);
        backdrop-filter: blur(8px);
      }
      .filter-row { display:flex; flex-wrap:wrap; align-items:center; gap:11px; }
      .filter-row label { margin-left:3px; color:#4e5e51; font-size:.83rem; font-weight:700; }
      .filter-row input, .filter-row select, .modal-form input, .modal-form select {
        min-height:42px; padding:10px 12px; border:1px solid #d7e1d4; border-radius:10px;
        background:#fff; color:var(--text, #29372f); outline:none; transition:border-color .18s,box-shadow .18s;
      }
      .filter-row input { width:min(230px, 100%); }
      .filter-row select { min-width:135px; }
      .filter-row input:focus, .filter-row select:focus, .modal-form input:focus, .modal-form select:focus {
        border-color:#6a9667; box-shadow:0 0 0 3px rgba(95,139,91,.13);
      }
      .button {
        display:inline-flex; align-items:center; justify-content:center; gap:7px; min-height:39px;
        padding:9px 13px; border:1px solid #d5dfd3; border-radius:10px; background:#fff;
        color:#344438; font-size:.84rem; font-weight:700; cursor:pointer;
        transition:background .18s,border-color .18s,transform .18s,box-shadow .18s;
      }
      .button:hover { background:#f1f7ee; border-color:#c6d8c1; }
      .button:active { transform:translateY(1px); }
      .button:focus-visible { outline:3px solid rgba(95,139,91,.28); outline-offset:2px; }
      .button:disabled { opacity:.65; cursor:wait; }
      .button-primary { background:var(--sage, #5f8b5b); border-color:var(--sage, #5f8b5b); color:#fff; }
      .button-primary:hover { background:var(--sage-dark, #3f704b); border-color:var(--sage-dark, #3f704b); color:#fff; }
      .button-danger { color:#a33e38; border-color:#ebd0cc; background:#fff; }
      .button-danger:hover { background:#fff2f0; border-color:#e8b9b4; }
      .button-success { color:#286b40; border-color:#c9e2cd; background:#fff; }
      .button-success:hover { background:#eff9f0; }
      .button-small { min-height:33px; padding:7px 10px; font-size:.78rem; border-radius:9px; }
      .table-heading { display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; margin:0 0 13px; }
      .table-heading h2 { margin:0; font-size:1.05rem; color:var(--forest, #26392d); font-weight:800; }
      .account-count { color:var(--muted, #6c786f); font-size:.83rem; }
      .table-wrap { width:100%; overflow-x:auto; border:1px solid #e5ebe1; border-radius:12px; }
      table { width:100%; min-width:700px; border-collapse:separate; border-spacing:0; }
      th, td { padding:14px 13px; text-align:left; vertical-align:middle; border-bottom:1px solid #e7ece4; }
      th { background:#f1f5ee; color:#445449; font-size:.79rem; font-weight:800; white-space:nowrap; }
      td { color:#3c4940; font-size:.86rem; }
      tbody tr { background:rgba(255,255,255,.65); transition:background .15s ease; }
      tbody tr:hover { background:#f4f8f1; }
      tbody tr:last-child td { border-bottom:0; }
      .role-badge, .status-badge { display:inline-flex; align-items:center; padding:5px 9px; border-radius:999px; font-size:.75rem; font-weight:800; white-space:nowrap; }
      .role-badge { background:#e9f0e6; color:#365c3d; }
      .status-active { background:#e5f5e8; color:#267443; }
      .status-inactive { background:#fae9e7; color:#a8403b; }
      .status-pending { background:#fff2d8; color:#8a6318; }
      .verification-actions { display:flex; flex-wrap:wrap; align-items:center; gap:6px; }
      .verification-actions form { margin:0; }
      .empty-state { text-align:center; padding:36px 16px; color:var(--muted, #6c786f); }
      .empty-state strong { display:block; margin-bottom:6px; color:var(--forest, #26392d); }
      dialog { width:min(470px,calc(100% - 30px)); max-height:90vh; overflow-y:auto; padding:0; border:1px solid #dfe8dc; border-radius:20px; background:#fbfcf9; color:var(--text,#29372f); box-shadow:0 24px 75px rgba(24,48,29,.23); }
      dialog::backdrop { background:rgba(27,43,31,.46); backdrop-filter:blur(4px); }
      .modal-header { padding:23px 25px 17px; border-bottom:1px solid #e5ebe2; background:linear-gradient(135deg,#f4f8f0,#fff); }
      .modal-header h2 { margin:0; color:var(--forest,#26392d); font-size:1.3rem; font-weight:800; letter-spacing:-.025em; }
      .modal-header p { margin:7px 0 0; color:var(--muted,#6c786f); font-size:.88rem; line-height:1.5; }
      .modal-form { display:flex; flex-direction:column; gap:8px; padding:21px 25px 25px; }
      .modal-form label { margin-top:6px; color:#465449; font-size:.86rem; font-weight:700; }
      .modal-actions { display:flex; justify-content:flex-end; flex-wrap:wrap; gap:8px; margin-top:17px; }
      .password-help { margin:0 0 5px; color:var(--muted,#6c786f); font-size:.85rem; line-height:1.55; }
      #action-feedback { width:min(390px,calc(100% - 30px)); padding:30px 24px; border:1px solid #dfe8dc; border-radius:20px; text-align:center; background:#fff; color:var(--forest,#26392d); box-shadow:0 24px 70px rgba(0,0,0,.2); }
      #action-feedback::backdrop { background:rgba(24,38,27,.48); backdrop-filter:blur(3px); }
      .feedback-icon { width:68px; height:68px; margin:0 auto 18px; display:grid; place-items:center; border-radius:50%; background:#edf5e9; }
      .feedback-spinner { width:34px; height:34px; border:4px solid #dce7dd; border-top-color:#5f8b5b; border-radius:50%; animation:feedback-spin .8s linear infinite; }
      .feedback-check { color:#39824b; font-size:38px; font-weight:800; animation:feedback-pop .35s ease-out; }
      .feedback-error { color:#b33b35; font-size:36px; font-weight:800; }
      #feedback-title { margin:0 0 8px; font-size:1.3rem; }
      #feedback-message { margin:0; color:#68716a; line-height:1.55; }
      #feedback-close { margin-top:22px; }
      @keyframes feedback-spin { to { transform:rotate(360deg); } }
      @keyframes feedback-pop { 0% { transform:scale(.5); opacity:0; } 75% { transform:scale(1.15); opacity:1; } 100% { transform:scale(1); } }
      @media (max-width:980px) {
        .app-shell { display:block; }
        .sidebar { position:relative; top:auto; width:100%; height:auto; min-height:0; padding:17px 16px; }
        .brand { padding-bottom:12px; }
        .nav { flex-direction:row; flex-wrap:wrap; gap:8px; margin-top:5px; }
        .nav-link { min-height:40px; padding:9px 12px; }
        .logout { margin-top:0; border-top:0; padding-top:9px; }
        .main-content { padding:24px 20px 32px; }
        .sidebar-footer { display:none; }
      }
      @media (max-width:620px) {
        .main-content { padding:20px 12px 26px; }
        .page-header { align-items:stretch; flex-direction:column; gap:13px; }
        .page-header > div:last-child, .page-header .button { width:100%; }
        .panel { padding:13px; border-radius:14px; }
        .filter-row { align-items:stretch; flex-direction:column; }
        .filter-row label { margin:5px 0 -5px; }
        .filter-row input, .filter-row select, .filter-row button { width:100%; min-width:0; }
        .table-wrap { border-radius:10px; }
        .modal-header, .modal-form { padding-left:18px; padding-right:18px; }
        .modal-actions .button { flex:1; }
      }
      @media (prefers-reduced-motion:reduce) { *,*::before,*::after { animation-duration:.01ms !important; transition-duration:.01ms !important; } }
    
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

<body>
<div class="app-shell">

    <aside class="sidebar">
        <div class="brand">
            <span class="brand-mark" aria-hidden="true">
                <svg viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M16 26V14" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                    <path d="M16 17C8 17 6 12 6 7C12 7 17 10 16 17Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/>
                    <path d="M16 21C24 21 26 16 26 11C20 11 15 14 16 21Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/>
                </svg>
            </span>
            <span class="brand-copy">
                <span class="brand-name">PTC Wellness</span>
                <span class="brand-tagline">Mental Health &amp; Well-being</span>
            </span>
        </div>

        <nav class="nav" aria-label="Main navigation">
            <a class="nav-link" href="dashboard.php">
                <span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="m3 10 9-7 9 7"/><path d="M5 9v11h14V9"/><path d="M9 20v-7h6v7"/></svg></span>
                <span>Dashboard</span>
            </a>

            <a class="nav-link active" href="users.php" aria-current="page">
                <span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M20 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></span>
                <span>Users</span>
            </a>

            <a class="nav-link" href="settings.php">
                <span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="m19.4 15 .1.1 1.4 1.1-1.4 2.4-1.7-.7a8 8 0 0 1-1.5.9l-.3 1.8h-2.8l-.3-1.8a8 8 0 0 1-1.5-.9l-1.7.7-1.4-2.4 1.4-1.1a7 7 0 0 1 0-1.8l-1.4-1.1 1.4-2.4 1.7.7a8 8 0 0 1 1.5-.9l.3-1.8h2.8l.3 1.8a8 8 0 0 1 1.5.9l1.7-.7 1.4 2.4-1.4 1.1a7 7 0 0 1 0 1.7Z" transform="translate(-1 -1) scale(1.08)"/></svg></span>
                <span>Settings</span>
            </a>

            <a class="nav-link logout" href="../auth/logout.php?portal=admin">
                <span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M10 17l5-5-5-5"/><path d="M15 12H3"/><path d="M12 3h6a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-6"/></svg></span>
                <span>Logout</span>
            </a>
        </nav>
        <div class="sidebar-footer">
          <strong>Well-being matters.</strong>
          <span>
              Supporting a healthier campus, one step at a time.
          </span>
        </div>
    </aside>

    <main class="main-content">

        <header class="page-header">
            <div>
                <p class="eyebrow">PTC Wellness · Administration</p>
                <h1 class="page-title">User Management</h1>
                <p class="page-description">
                    Manage administrator and counselor accounts.
                </p>
            </div>

            <button
                class="button button-primary"
                id="open-add"
                type="button"
            >
                + Add User
            </button>
        </header>

        <section class="panel" aria-label="Account filters">
            <div class="filter-row" id="filter-form">

                <label for="search-user">Search</label>
                <input
                    type="search"
                    id="search-user"
                    placeholder="Search by name or email"
                >

                <label for="role">Role</label>
                <select id="role">
                    <option value="">All Roles</option>
                    <option value="admin">Admin</option>
                    <option value="counselor">Counselor</option>
                </select>

                <label for="status">Status</label>
                <select id="status">
                    <option value="">All Statuses</option>
                    <option value="pending">Pending verification</option>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>

                <button class="button" id="apply-filter" type="button">
                    Apply Filters
                </button>

                <button class="button" id="clear-filter" type="button">
                    Clear
                </button>
            </div>
        </section>

        <section class="panel accounts-panel">

            <div class="table-heading">
                <h2>Accounts</h2>
                <span class="account-count" id="account-count">
                    <?= count($users) ?> account(s)
                </span>
            </div>

            <?php if (!$result): ?>
                <div class="empty-state">
                    <strong>Unable to load accounts</strong>
                    Please check the database connection and server error log.
                </div>
            <?php elseif (empty($users)): ?>
                <div class="empty-state">
                    <strong>No administrator or counselor accounts found</strong>
                    Accounts will appear here when available.
                </div>
            <?php else: ?>

                <div class="table-wrap">
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
                            <?php
                                $role = strtolower($u['role_type'] ?? '');
                                $status = strtolower($u['status'] ?? '');
                            ?>

                            <tr
                                data-id="<?= (int) $u['user_id'] ?>"
                                data-name="<?= userPageEscape(strtolower($u['fullname'])) ?>"
                                data-email="<?= userPageEscape(strtolower($u['email'])) ?>"
                                data-role="<?= userPageEscape($role) ?>"
                                data-status="<?= userPageEscape($status) ?>"
                            >
                                <td><?= userPageEscape($u['fullname']) ?></td>

                                <td><?= userPageEscape($u['email']) ?></td>

                                <td>
                                    <span class="role-badge">
                                        <?= userPageEscape(ucfirst($role)) ?>
                                    </span>
                                </td>

                                <td>
                                    <span class="status-badge status-<?= userPageEscape($status) ?>">
                                        <?= userPageEscape(ucfirst($status)) ?>
                                    </span>
                                </td>

                                <td>
                                    <div class="verification-actions">

                                        <button
                                            class="button button-small edit-btn"
                                            data-id="<?= (int) $u['user_id'] ?>"
                                            type="button"
                                        >
                                            Edit
                                        </button>

                                        <?php if (
                                                $role === 'counselor' ||
                                                ($role === 'admin' && (int) $u['user_id'] !== (int) $_SESSION['user_id'])
                                            ): ?>
                                                <button
                                                    class="button button-small reset-password-btn"
                                                    data-id="<?= (int) $u['user_id'] ?>"
                                                    data-name="<?= userPageEscape($u['fullname']) ?>"
                                                    data-role="<?= userPageEscape($role) ?>"
                                                    type="button"
                                                >
                                                    Reset Password
                                                </button>
                                            <?php endif; ?>

                                        <?php if ($status === 'pending'): ?>
                                            <button
                                                class="button button-small button-success verification-btn"
                                                data-id="<?= (int) $u['user_id'] ?>"
                                                data-action="verify"
                                                type="button"
                                            >
                                                Approve
                                            </button>

                                            <button
                                                class="button button-small button-danger verification-btn"
                                                data-id="<?= (int) $u['user_id'] ?>"
                                                data-action="reject"
                                                type="button"
                                            >
                                                Reject
                                            </button>

                                        <?php elseif ($status === 'active'): ?>
                                            <button
                                                class="button button-small button-danger toggle-status-btn"
                                                data-id="<?= (int) $u['user_id'] ?>"
                                                data-action="deactivate"
                                                type="button"
                                            >
                                                Deactivate
                                            </button>

                                        <?php else: ?>
                                            <button
                                                class="button button-small button-success toggle-status-btn"
                                                data-id="<?= (int) $u['user_id'] ?>"
                                                data-action="activate"
                                                type="button"
                                            >
                                                Activate
                                            </button>
                                        <?php endif; ?>

                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="empty-state" id="no-filter-results" hidden>
                    <strong>No matching accounts</strong>
                    Try changing your search or filters.
                </div>

            <?php endif; ?>
        </section>

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

<!-- Add User Modal -->
<dialog id="add-user-modal">
    <div class="modal-header">
        <h2>Add Account</h2>
        <p>Create an administrator or counselor login account.</p>
    </div>

    <form id="add-user-form" class="modal-form">
        <label for="fullname">Full Name</label>
        <input
            type="text"
            id="fullname"
            name="fullname"
            maxlength="150"
            autocomplete="name"
            required
        >

        <label for="email">Email</label>
        <input
            type="email"
            id="email"
            name="email"
            maxlength="255"
            autocomplete="email"
            required
        >

        <label for="password">Temporary Password</label>
        <input
            type="password"
            id="password"
            name="password"
            autocomplete="new-password"
            minlength="8"
            required
        >

        <label for="user-role">Role</label>
        <select id="user-role" name="role_type" required>
            <option value="admin">Admin</option>
            <option value="counselor">Counselor</option>
        </select>

        <div class="modal-actions">
            <button class="button" type="button" id="add-cancel">
                Cancel
            </button>

            <button class="button button-primary" type="submit">
                Create Account
            </button>
        </div>
    </form>
</dialog>

<!-- Edit User Modal -->
<dialog id="edit-user-modal">
    <div class="modal-header">
        <h2>Edit Account</h2>
        <p>Update the selected administrator or counselor account.</p>
    </div>

    <form id="edit-user-form" class="modal-form">
        <input type="hidden" id="edit-id" name="user_id">

        <label for="edit-name">Full Name</label>
        <input
            type="text"
            id="edit-name"
            name="fullname"
            maxlength="150"
            required
        >

        <label for="edit-email">Email</label>
        <input
            type="email"
            id="edit-email"
            name="email"
            maxlength="255"
            required
        >

        <label for="edit-role">Role</label>
        <select id="edit-role" name="role_type" required>
            <option value="admin">Admin</option>
            <option value="counselor">Counselor</option>
        </select>

        <div class="modal-actions">
            <button class="button" type="button" id="edit-cancel">
                Cancel
            </button>

            <button class="button button-primary" type="submit">
                Save Changes
            </button>
        </div>
    </form>
</dialog>

<!-- Counselor Password Reset Modal -->
<dialog id="reset-password-modal">
    <div class="modal-header">
        <h2 id="reset-password-title">Reset Password</h2>
        <p id="reset-password-account">
            Set a temporary password for the selected account.
        </p>

    <form id="reset-password-form" class="modal-form">
        <input type="hidden" id="reset-user-id" name="user_id">
        <input type="hidden" name="csrf_token" value="<?= userPageEscape($_SESSION['csrf_token']) ?>">

        <p class="password-help">
            Confirm the request with the Counselor first. Share the temporary password privately; never send it in a public channel.
        </p>

        <label for="reset-password">Temporary Password</label>
        <input
            type="password"
            id="reset-password"
            name="new_password"
            minlength="8"
            maxlength="72"
            autocomplete="new-password"
            required
        >

        <label for="reset-password-confirm">Confirm Temporary Password</label>
        <input
            type="password"
            id="reset-password-confirm"
            minlength="8"
            maxlength="72"
            autocomplete="new-password"
            required
        >

        <div class="modal-actions">
            <button class="button" type="button" id="reset-password-cancel">Cancel</button>
            <button class="button button-primary" type="submit">Reset Password</button>
        </div>
    </form>
</dialog>

<dialog id="action-feedback" aria-labelledby="feedback-title">
    <div class="feedback-icon" id="feedback-icon">
        <div class="feedback-spinner"></div>
    </div>

    <h2 id="feedback-title">Creating account...</h2>

    <p id="feedback-message">
        Please wait while we save the account.
    </p>

    <button
        class="button button-primary"
        id="feedback-close"
        type="button"
        hidden
    >
        Close
    </button>
</dialog>

<script>
const addModal = document.getElementById('add-user-modal');
const editModal = document.getElementById('edit-user-modal');
const resetPasswordModal = document.getElementById('reset-password-modal');

const addForm = document.getElementById('add-user-form');
const editForm = document.getElementById('edit-user-form');
const resetPasswordForm = document.getElementById('reset-password-form');

document.getElementById('open-add').addEventListener('click', () => {
    addForm.reset();
    addModal.showModal();
});

document.getElementById('add-cancel').addEventListener('click', () => {
    addModal.close();
});

document.getElementById('edit-cancel').addEventListener('click', () => {
    editModal.close();
});

document.getElementById('reset-password-cancel').addEventListener('click', () => {
    resetPasswordModal.close();
});

document.querySelectorAll('.reset-password-btn').forEach((button) => {
    button.addEventListener('click', () => {
        resetPasswordForm.reset();

        document.getElementById('reset-user-id').value =
            button.dataset.id;

        const role = button.dataset.role;
        const name = button.dataset.name;
        const roleLabel = role === 'admin' ? 'Admin' : 'Counselor';

        document.getElementById('reset-password-title').textContent =
            `Reset ${roleLabel} Password`;

        document.getElementById('reset-password-account').textContent =
            `Set a temporary password for ${name}.`;

        resetPasswordModal.showModal();
    });
});

// Send an account-management request to the PHP backend.

const feedbackModal = document.getElementById('action-feedback');
const feedbackIcon = document.getElementById('feedback-icon');
const feedbackTitle = document.getElementById('feedback-title');
const feedbackMessage = document.getElementById('feedback-message');
const feedbackClose = document.getElementById('feedback-close');

const accountConfirmModal = document.getElementById('account-confirm-modal');
const accountConfirmTitle = document.getElementById('account-confirm-title');
const accountConfirmMessage = document.getElementById('account-confirm-message');
const accountConfirmIcon = document.getElementById('account-confirm-icon');
const accountConfirmYes = document.getElementById('account-confirm-yes');
const accountConfirmCancel = document.getElementById('account-confirm-cancel');


function confirmAccountAction({
    title,
    message,
    confirmText = 'Confirm',
    danger = false
}) {
    return new Promise((resolve) => {
        // Hanapin ulit ang elements mula sa kasalukuyang HTML.
        const modal = document.getElementById('account-confirm-modal');
        const titleEl = document.getElementById('account-confirm-title');
        const messageEl = document.getElementById('account-confirm-message');
        const iconEl = document.getElementById('account-confirm-icon');
        const yesButton = document.getElementById('account-confirm-yes');
        const cancelButton = document.getElementById('account-confirm-cancel');

        // Iwasan ang error kung may nawawalang HTML element.
        if (!modal || !titleEl || !messageEl ||
            !iconEl || !yesButton || !cancelButton) {
            console.error('Account confirmation modal is missing an HTML element.');
            resolve(false);
            return;
        }

        titleEl.textContent = title;
        messageEl.textContent = message;
        yesButton.textContent = confirmText;

        iconEl.textContent = danger ? '!' : '?';
        iconEl.style.background = danger ? '#fff0ee' : '#edf5e9';
        iconEl.style.color = danger ? '#b33b35' : '#47794c';

        yesButton.classList.toggle('button-danger', danger);
        yesButton.classList.toggle('button-primary', !danger);

        if (modal.open) {
            modal.close();
        }

        modal.showModal();

        function cleanup(answer) {
            yesButton.removeEventListener('click', onConfirm);
            cancelButton.removeEventListener('click', onCancel);
            modal.removeEventListener('cancel', onEscape);

            if (modal.open) modal.close();

            resolve(answer);
        }

        function onConfirm() {
            cleanup(true);
        }

        function onCancel() {
            cleanup(false);
        }

        function onEscape() {
            cleanup(false);
        }

        yesButton.addEventListener('click', onConfirm);
        cancelButton.addEventListener('click', onCancel);
        modal.addEventListener('cancel', onEscape);
    });
}


function showFeedbackLoading(title, message) {
    feedbackIcon.innerHTML = '<div class="feedback-spinner"></div>';
    feedbackTitle.textContent = title;
    feedbackMessage.textContent = message;
    feedbackClose.hidden = true;

    if (!feedbackModal.open) {
        feedbackModal.showModal();
    }
}

function showFeedbackSuccess(title, message) {
    feedbackIcon.innerHTML = '<span class="feedback-check">✓</span>';
    feedbackTitle.textContent = title;
    feedbackMessage.textContent = message;
}

function showFeedbackError(message) {
    feedbackIcon.innerHTML = '<span class="feedback-error">!</span>';
    feedbackTitle.textContent = 'Unable to Save';
    feedbackMessage.textContent = message;
    feedbackClose.hidden = false;

    if (!feedbackModal.open) {
        feedbackModal.showModal();
    }
}

feedbackClose.addEventListener('click', () => {
    feedbackModal.close();
});

async function sendUserAction(data, options = {}) {
    showFeedbackLoading(
        options.loadingTitle || 'Saving changes...',
        options.loadingMessage || 'Please wait while we save your changes.'
    );

    try {
        const response = await fetch('user_actions.php', {
            method: 'POST',
            body: data,
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        });

        const message = (await response.text()).trim();

        if (!response.ok || message !== 'OK') {
            showFeedbackError(
                message || 'The server could not save your changes.'
            );
            return false;
        }

        showFeedbackSuccess(
            options.successTitle || 'Changes Saved!',
            options.successMessage || 'Your changes have been saved successfully.'
        );

        return true;

    } catch (error) {
        console.error('User action request failed:', error);

        showFeedbackError(
            'Could not contact the server. Please check XAMPP and try again.'
        );

        return false;
    }
}

// Add account.
addForm.addEventListener('submit', async (event) => {
    event.preventDefault();

    const submitButton = addForm.querySelector('[type="submit"]');

    if (submitButton.disabled) return;

    submitButton.disabled = true;
    submitButton.textContent = 'Creating...';

    const data = new FormData(addForm);
    data.append('action', 'add');

    const success = await sendUserAction(data, {
        loadingTitle: 'Creating account...',
        loadingMessage: 'Please wait while we save the account.',
        successTitle: 'User Added Successfully!',
        successMessage: 'The new account has been saved successfully.'
    });

    if (success) {
        addModal.close();

        // Keep the success screen visible for 2.5 seconds.
        await new Promise(resolve => setTimeout(resolve, 2500));

        feedbackModal.close();
        location.reload();
    } else {
        submitButton.disabled = false;
        submitButton.textContent = 'Create Account';
    }
});

// Open edit dialog and populate it from the selected row.
document.querySelectorAll('.edit-btn').forEach((button) => {
    button.addEventListener('click', () => {
        const row = button.closest('tr');

        document.getElementById('edit-id').value = row.dataset.id;
        document.getElementById('edit-name').value =
            row.children[0].textContent.trim();
        document.getElementById('edit-email').value =
            row.children[1].textContent.trim();
        document.getElementById('edit-role').value = row.dataset.role;

        editModal.showModal();
    });
});

// Update account details and keep the success message visible.
editForm.addEventListener('submit', async (event) => {
    event.preventDefault();

    const submitButton = editForm.querySelector('[type="submit"]');

    if (submitButton.disabled) return;

    submitButton.disabled = true;
    submitButton.textContent = 'Saving...';

    const data = new FormData(editForm);
    data.append('action', 'edit');

    const success = await sendUserAction(data, {
        loadingTitle: 'Saving account changes...',
        loadingMessage: 'Please wait while we update the account.',
        successTitle: 'Changes Saved Successfully!',
        successMessage: 'The account details have been updated.'
    });

    if (success) {
        editModal.close();

        // Give the user enough time to read the success message.
        await new Promise(resolve => setTimeout(resolve, 3000));

        feedbackModal.close();
        location.reload();
    } else {
        submitButton.disabled = false;
        submitButton.textContent = 'Save Changes';
    }
});

// Activate or deactivate an account using the custom confirmation modal.
document.querySelectorAll('.toggle-status-btn').forEach((button) => {
    button.addEventListener('click', async () => {
        const action = button.dataset.action;
        const isDeactivate = action === 'deactivate';

        const confirmed = await confirmAccountAction({
            title: isDeactivate ? 'Deactivate Account?' : 'Activate Account?',
            message: isDeactivate
                ? 'This account will no longer be able to log in. Do you want to continue?'
                : 'This account will be allowed to log in again. Do you want to continue?',
            confirmText: isDeactivate ? 'Yes, Deactivate' : 'Yes, Activate',
            danger: isDeactivate
        });

        if (!confirmed) return;

        const data = new FormData();
        data.append('action', 'toggle');
        data.append('user_id', button.dataset.id);
        data.append('toggle_action', action);

        button.disabled = true;

        const success = await sendUserAction(data, {
            loadingTitle: isDeactivate ? 'Deactivating account...' : 'Activating account...',
            loadingMessage: 'Please wait while the account status is updated.',
            successTitle: isDeactivate ? 'Account Deactivated' : 'Account Activated',
            successMessage: isDeactivate
                ? 'The account has been deactivated successfully.'
                : 'The account has been activated successfully.'
        });

        if (success) {
            await new Promise(resolve => setTimeout(resolve, 2500));
            feedbackModal.close();
            location.reload();
        } else {
            button.disabled = false;
        }
    });
});

// Approve or reject pending accounts using custom confirmation.
document.querySelectorAll('.verification-btn').forEach((button) => {
    button.addEventListener('click', async () => {
        const action = button.dataset.action;
        const isApprove = action === 'verify';

        const confirmed = await confirmAccountAction({
            title: isApprove ? 'Approve Account?' : 'Reject Account?',
            message: isApprove
                ? 'This will approve the account and allow it to become active. Continue?'
                : 'This will reject the account and mark it inactive. Continue?',
            confirmText: isApprove ? 'Yes, Approve' : 'Yes, Reject',
            danger: !isApprove
        });

        if (!confirmed) return;

        const data = new FormData();
        data.append('action', action);
        data.append('user_id', button.dataset.id);

        button.disabled = true;

        const success = await sendUserAction(data, {
            loadingTitle: isApprove ? 'Approving account...' : 'Rejecting account...',
            loadingMessage: 'Please wait while we update the account.',
            successTitle: isApprove ? 'Account Approved' : 'Account Rejected',
            successMessage: isApprove
                ? 'The account has been approved successfully.'
                : 'The account has been rejected successfully.'
        });

        if (success) {
            await new Promise(resolve => setTimeout(resolve, 2500));
            feedbackModal.close();
            location.reload();
        } else {
            button.disabled = false;
        }
    });
});

// Reset a Counselor password. The backend independently validates the role and CSRF token.
resetPasswordForm.addEventListener('submit', async (event) => {
    event.preventDefault();

    const newPassword = document.getElementById('reset-password').value;
    const confirmPassword = document.getElementById('reset-password-confirm').value;
    const submitButton = resetPasswordForm.querySelector('[type="submit"]');

    if (newPassword !== confirmPassword) {
        showFeedbackError('The temporary password and confirmation do not match.');
        return;
    }

    if (newPassword.length < 8 || newPassword.length > 72) {
        showFeedbackError('Use a temporary password between 8 and 72 characters.');
        return;
    }

    if (submitButton.disabled) return;
    submitButton.disabled = true;

    const data = new FormData(resetPasswordForm);

    const selectedRow = document.querySelector(
        `#users-table tbody tr[data-id="${document.getElementById('reset-user-id').value}"]`
    );

    const selectedRole = selectedRow?.dataset.role;

    if (selectedRole !== 'admin' && selectedRole !== 'counselor') {
        showFeedbackError('Unable to identify the selected account role.');
        submitButton.disabled = false;
        return;
    }

    const action = selectedRole === 'admin'
        ? 'reset_admin_password'
        : 'reset_counselor_password';

    data.append('action', action);

    const success = await sendUserAction(data, {
    loadingTitle: 'Resetting password...',
    loadingMessage: 'Please wait while the account password is updated.',
    successTitle: 'Password Reset Successfully',
    successMessage: 'The temporary password has been saved. Share it with the account owner privately.'
    });
    
    submitButton.disabled = false;

    if (success) {
        resetPasswordModal.close();
        resetPasswordForm.reset();
        await new Promise(resolve => setTimeout(resolve, 2200));
        feedbackModal.close();
        location.reload();
    }
});

// Apply client-side search and filters.
function applyFilters() {
    const query = document.getElementById('search-user')
        .value.trim().toLowerCase();
    const role = document.getElementById('role').value;
    const status = document.getElementById('status').value;

    const rows = document.querySelectorAll('#users-table tbody tr');
    let visibleCount = 0;

    rows.forEach((row) => {
        const name = row.dataset.name || '';
        const email = row.dataset.email || '';
        const rowRole = row.dataset.role || '';
        const rowStatus = row.dataset.status || '';

        const matchesQuery =
            !query || name.includes(query) || email.includes(query);
        const matchesRole = !role || rowRole === role;
        const matchesStatus = !status || rowStatus === status;

        const visible = matchesQuery && matchesRole && matchesStatus;

        row.hidden = !visible;

        if (visible) {
            visibleCount++;
        }
    });

    const noResults = document.getElementById('no-filter-results');
    const countLabel = document.getElementById('account-count');

    if (noResults) {
        noResults.hidden = visibleCount !== 0;
    }

    if (countLabel) {
        countLabel.textContent = visibleCount + ' matching account(s)';
    }
}

document.getElementById('apply-filter').addEventListener('click', applyFilters);

document.getElementById('clear-filter').addEventListener('click', () => {
    document.getElementById('search-user').value = '';
    document.getElementById('role').value = '';
    document.getElementById('status').value = '';
    applyFilters();
});

document.getElementById('search-user').addEventListener('keydown', (event) => {
    if (event.key === 'Enter') {
        event.preventDefault();
        applyFilters();
    }
});
</script>
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

            <!-- Account Confirmation Modal -->
            <dialog id="account-confirm-modal" class="account-confirm-modal">
                <div class="account-confirm-icon" id="account-confirm-icon">?</div>

                <h2 id="account-confirm-title">Confirm Action</h2>

                <p id="account-confirm-message">
                    Are you sure you want to continue?
                </p>

                <div class="account-confirm-actions">
                    <button type="button" id="account-confirm-cancel">
                        Go Back
                    </button>

                    <button type="button" id="account-confirm-yes">
                        Confirm
                    </button>
                </div>
            </dialog>


</body>
</html>
