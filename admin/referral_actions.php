<?php
require_once __DIR__ . "/../includes/session.php";
if (!isset($_SESSION['role_type']) || $_SESSION['role_type'] !== 'admin') {
    header('HTTP/1.1 403 Forbidden'); echo 'Forbidden'; exit();
}
require_once __DIR__ . "/../config/database.php";

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('HTTP/1.1 405 Method Not Allowed'); echo 'Method not allowed'; exit();
}

$action = isset($_POST['action']) ? $_POST['action'] : '';

function table_exists($conn,$name){$n=mysqli_real_escape_string($conn,$name);$res=mysqli_query($conn,"SHOW TABLES LIKE '$n'");return ($res && mysqli_num_rows($res)>0);} 
function column_exists($conn,$table,$column){$t=mysqli_real_escape_string($conn,$table);$c=mysqli_real_escape_string($conn,$column);$res=mysqli_query($conn,"SHOW COLUMNS FROM `$t` LIKE '$c'");return ($res && mysqli_num_rows($res)>0);} 

// candidates
$ref_candidates = ['referrals','referral','referral_requests','referral_records','referral_submissions'];
$rtable = null; foreach ($ref_candidates as $c) { if (table_exists($conn,$c)) { $rtable=$c; break; } }
$assessment_candidates = ['assessments','assessment','assessment_results','assessment_submissions','assessment_records'];
$atable = null; foreach ($assessment_candidates as $c) { if (table_exists($conn,$c)) { $atable=$c; break; } }

if ($action === 'assign') {
    $referral_id = isset($_POST['referral_id']) ? $_POST['referral_id'] : '';
    $counselor_id = isset($_POST['counselor_id']) && $_POST['counselor_id'] !== '' ? intval($_POST['counselor_id']) : null;
    if (!$referral_id) { header('HTTP/1.1 400 Bad Request'); echo 'Missing referral_id'; exit(); }

    // prefer referrals table
    if ($rtable) {
        // find assigned column
        $assigned_col = null; foreach (['assigned_counselor','counselor_id','assigned_to'] as $c) { if (column_exists($conn,$rtable,$c)){ $assigned_col=$c; break; } }
        if (!$assigned_col) { header('HTTP/1.1 400 Bad Request'); echo 'No assignable column'; exit(); }
        $sql = "UPDATE `$rtable` SET `$assigned_col` = ? WHERE `". (column_exists($conn,$rtable,'id') ? 'id' : 'referral_id') ."` = ?";
        $stmt = mysqli_prepare($conn,$sql);
        mysqli_stmt_bind_param($stmt,'ii',$counselor_id,$referral_id);
        if (mysqli_stmt_execute($stmt)) { echo 'OK'; exit(); } else { header('HTTP/1.1 500 Internal Server Error'); echo 'Update failed'; exit(); }
    }

    // fallback: assessments table
    if ($atable) {
        $assigned_col = null; foreach (['assigned_counselor','counselor_id','assigned_to'] as $c) { if (column_exists($conn,$atable,$c)){ $assigned_col=$c; break; } }
        $idcol = null; foreach (['id','assessment_id','submission_id'] as $c) { if (column_exists($conn,$atable,$c)){ $idcol=$c; break; } }
        if (!$assigned_col || !$idcol) { header('HTTP/1.1 400 Bad Request'); echo 'No assignable column or id'; exit(); }
        $sql = "UPDATE `$atable` SET `$assigned_col` = ? WHERE `$idcol` = ?";
        $stmt = mysqli_prepare($conn,$sql);
        mysqli_stmt_bind_param($stmt,'ii',$counselor_id,$referral_id);
        if (mysqli_stmt_execute($stmt)) { echo 'OK'; exit(); } else { header('HTTP/1.1 500 Internal Server Error'); echo 'Update failed'; exit(); }
    }

    header('HTTP/1.1 400 Bad Request'); echo 'No referrals or assessments table to update'; exit();

} elseif ($action === 'update_status') {
    $referral_id = isset($_POST['referral_id']) ? $_POST['referral_id'] : '';
    $status = isset($_POST['status']) ? trim($_POST['status']) : '';
    if (!$referral_id || $status==='') { header('HTTP/1.1 400 Bad Request'); echo 'Missing parameters'; exit(); }

    if ($rtable) {
        $status_col = null; foreach (['status','referral_status'] as $c) { if (column_exists($conn,$rtable,$c)){ $status_col=$c; break; } }
        if (!$status_col) { header('HTTP/1.1 400 Bad Request'); echo 'No status column'; exit(); }
        $sql = "UPDATE `$rtable` SET `$status_col` = ? WHERE `". (column_exists($conn,$rtable,'id') ? 'id' : 'referral_id') ."` = ?";
        $stmt = mysqli_prepare($conn,$sql);
        mysqli_stmt_bind_param($stmt,'si',$status,$referral_id);
        if (mysqli_stmt_execute($stmt)) { echo 'OK'; exit(); } else { header('HTTP/1.1 500 Internal Server Error'); echo 'Update failed'; exit(); }
    }

    if ($atable) {
        $status_col = null; foreach (['status','review_status'] as $c) { if (column_exists($conn,$atable,$c)){ $status_col=$c; break; } }
        $idcol = null; foreach (['id','assessment_id','submission_id'] as $c) { if (column_exists($conn,$atable,$c)){ $idcol=$c; break; } }
        if (!$status_col || !$idcol) { header('HTTP/1.1 400 Bad Request'); echo 'No status or id column'; exit(); }
        $sql = "UPDATE `$atable` SET `$status_col` = ? WHERE `$idcol` = ?";
        $stmt = mysqli_prepare($conn,$sql);
        mysqli_stmt_bind_param($stmt,'si',$status,$referral_id);
        if (mysqli_stmt_execute($stmt)) { echo 'OK'; exit(); } else { header('HTTP/1.1 500 Internal Server Error'); echo 'Update failed'; exit(); }
    }

    header('HTTP/1.1 400 Bad Request'); echo 'No referrals or assessments table to update'; exit();

} else {
    header('HTTP/1.1 400 Bad Request'); echo 'Unknown action'; exit();
}
