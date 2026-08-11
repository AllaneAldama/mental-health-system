<?php
require_once __DIR__ . "/../includes/session.php"; // ensures user is logged in
if (!isset($_SESSION['role_type']) || $_SESSION['role_type'] !== 'admin') {
    header("Location: ../auth/login.php");
    exit();
}
require_once __DIR__ . "/../config/database.php"; // $conn

// helper
function table_exists($conn,$name){$name_esc=mysqli_real_escape_string($conn,$name);$sql="SHOW TABLES LIKE '$name_esc'"; $res=mysqli_query($conn,$sql); return ($res && mysqli_num_rows($res)>0);}
function column_exists($conn,$table,$column){$t=mysqli_real_escape_string($conn,$table);$c=mysqli_real_escape_string($conn,$column);$sql="SHOW COLUMNS FROM `$t` LIKE '$c'"; $res=mysqli_query($conn,$sql); return ($res && mysqli_num_rows($res)>0);}

// detect table same candidates as assessments.php
$candidates = ['assessments','assessment','assessment_data','assessment_results','assessment_submissions','assessment_records','responses','survey_responses','phq9_results','gad7_results'];
$atable = null; foreach($candidates as $c){ if (table_exists($conn,$c)){ $atable=$c; break; } }

if (!$atable) { echo "<p>No assessments table detected. Tell me the table name/columns.</p>"; exit(); }

// find id column
$id_col = null; foreach (['id','assessment_id','submission_id','record_id','entry_id'] as $c){ if (column_exists($conn,$atable,$c)){ $id_col=$c; break; } }
if (!$id_col) { echo "<p>Could not find an ID column on table $atable.</p>"; exit(); }

$req_id = isset($_GET['id']) ? $_GET['id'] : null;
if (!$req_id) { echo "<p>Missing id</p>"; exit(); }

$req_id_esc = mysqli_real_escape_string($conn,$req_id);
$sql = "SELECT * FROM `$atable` WHERE `$id_col` = '$req_id_esc' LIMIT 1";
$res = mysqli_query($conn,$sql);
if (!$res || mysqli_num_rows($res)===0) { echo "<p>Record not found.</p>"; exit(); }
$record = mysqli_fetch_assoc($res);

?>
<!doctype html>
<html>
  <head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>View Assessment</title>
    <style>body{font-family:Arial,Helvetica,sans-serif;margin:16px}table{border-collapse:collapse;width:100%}td,th{border:1px solid #ddd;padding:8px;text-align:left}</style>
  </head>
  <body>
    <a href="assessments.php">&larr; Back</a>
    <h1>Assessment Details</h1>
    <p class="muted">Table: <?= htmlspecialchars($atable) ?></p>
    <table>
      <tbody>
        <?php foreach ($record as $k=>$v): ?>
          <tr><th><?= htmlspecialchars($k) ?></th><td><?= htmlspecialchars((string)$v) ?></td></tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </body>
</html>
