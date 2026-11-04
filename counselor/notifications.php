<?php
/** Counselor notification feed backed by real Needs Assessment submissions.
 * Read receipts persist in existing notification_data; no schema changes.
 * This endpoint never changes assessment status or review locks.
 */
require_once __DIR__ . '/../includes/counselor_session.php';
require_once __DIR__ . '/../config/database.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
function notification_reply($status, $data) { http_response_code($status); echo json_encode($data, JSON_UNESCAPED_UNICODE); exit(); }
$counselorId=(int)($_SESSION['user_id']??0);
if ($counselorId<1 || ($_SESSION['role_type']??'')!=='counselor') notification_reply(403,['success'=>false,'message'=>'Unauthorized']);
if (!isset($conn) || !$conn || !mysqli_ping($conn)) notification_reply(503,['success'=>false,'message'=>'Database unavailable']);

$method=$_SERVER['REQUEST_METHOD']??'GET';
if ($method === 'POST') {
    $csrf=(string)($_POST['csrf_token']??'');
    if (!hash_equals((string)($_SESSION['counselor_notification_csrf']??''),$csrf) || $csrf==='') notification_reply(403,['success'=>false,'message'=>'Invalid security token']);
    $action=(string)($_POST['action']??'');
    if (!in_array($action,['mark_read','mark_all_read'],true)) notification_reply(422,['success'=>false,'message'=>'Invalid action']);

    // Guard simultaneous tabs for the SAME counselor, without changing schema.
    $lockName='ptc_counselor_notify_'.$counselorId;
    $lockStmt=mysqli_prepare($conn,'SELECT GET_LOCK(?, 3)');
    if (!$lockStmt) notification_reply(500,['success'=>false,'message'=>'Unable to update notifications']);
    mysqli_stmt_bind_param($lockStmt,'s',$lockName);
    mysqli_stmt_execute($lockStmt);
    $lockResult=mysqli_stmt_get_result($lockStmt);
    $locked=(int)(mysqli_fetch_row($lockResult)[0]??0)===1;
    mysqli_stmt_close($lockStmt);
    if (!$locked) notification_reply(409,['success'=>false,'message'=>'Please retry']);

    $ok=false;
    try {
        mysqli_begin_transaction($conn);
        $assessmentIds=[];
        if ($action==='mark_read') {
            $assessmentId=filter_input(INPUT_POST,'assessment_id',FILTER_VALIDATE_INT);
            if (!$assessmentId || $assessmentId<1) throw new RuntimeException('Invalid assessment');
            $check=mysqli_prepare($conn,"SELECT assessment_id FROM assessment_data WHERE assessment_id=? AND assessment_type='Needs Assessment' LIMIT 1");
            if (!$check) throw new RuntimeException('Unable to check assessment');
            mysqli_stmt_bind_param($check,'i',$assessmentId);
            mysqli_stmt_execute($check);
            $found=mysqli_stmt_get_result($check);
            if ($found && mysqli_fetch_row($found)) $assessmentIds[]=$assessmentId;
            mysqli_stmt_close($check);
            if (!$assessmentIds) throw new RuntimeException('Assessment unavailable');
        } else {
            $pending=mysqli_query($conn,"SELECT assessment_id FROM assessment_data WHERE assessment_type='Needs Assessment' AND LOWER(TRIM(status))='pending'");
            if (!$pending) throw new RuntimeException('Unable to read pending assessments');
            while ($record=mysqli_fetch_assoc($pending)) $assessmentIds[]=(int)$record['assessment_id'];
        }
        $exists=mysqli_prepare($conn,'SELECT notification_id FROM notification_data WHERE counselor_id=? AND assessment_id=? LIMIT 1');
        $insert=mysqli_prepare($conn,"INSERT INTO notification_data (counselor_id,assessment_id,message,notification_status) VALUES (?,?,'Needs Assessment submission','Read')");
        if (!$exists || !$insert) throw new RuntimeException('Unable to prepare read receipts');
        foreach ($assessmentIds as $aid) {
            mysqli_stmt_bind_param($exists,'ii',$counselorId,$aid);
            if (!mysqli_stmt_execute($exists)) throw new RuntimeException('Unable to look up read receipt');
            $found=mysqli_stmt_get_result($exists);
            $alreadyExists=$found && (bool)mysqli_fetch_row($found);
            if ($found) mysqli_free_result($found);
            if (!$alreadyExists) {
                mysqli_stmt_bind_param($insert,'ii',$counselorId,$aid);
                if (!mysqli_stmt_execute($insert)) throw new RuntimeException('Unable to save read receipt');
            }
        }
        mysqli_stmt_close($exists);mysqli_stmt_close($insert);
        if ($action==='mark_read') {
            $stmt=mysqli_prepare($conn,"UPDATE notification_data SET notification_status='Read' WHERE counselor_id=? AND assessment_id=?");
            mysqli_stmt_bind_param($stmt,'ii',$counselorId,$assessmentId);
        } else {
            $stmt=mysqli_prepare($conn,"UPDATE notification_data SET notification_status='Read' WHERE counselor_id=? AND assessment_id IN (SELECT assessment_id FROM assessment_data WHERE assessment_type='Needs Assessment' AND LOWER(TRIM(status))='pending')");
            mysqli_stmt_bind_param($stmt,'i',$counselorId);
        }
        if (!$stmt || !mysqli_stmt_execute($stmt)) throw new RuntimeException('Unable to mark read');
        mysqli_stmt_close($stmt);
        mysqli_commit($conn);$ok=true;
    } catch (Throwable $err) {
        mysqli_rollback($conn);
        error_log('Notification write: '.$err->getMessage());
    } finally {
        $release=mysqli_prepare($conn,'SELECT RELEASE_LOCK(?)');
        if ($release) {mysqli_stmt_bind_param($release,'s',$lockName);mysqli_stmt_execute($release);mysqli_stmt_close($release);}
    }
    notification_reply($ok?200:500,['success'=>$ok,'message'=>$ok?'Updated':'Unable to update notifications']);
}
if ($method!=='GET') notification_reply(405,['success'=>false,'message'=>'Method not allowed']);

$countSql="SELECT COUNT(*) FROM assessment_data a
 WHERE a.assessment_type='Needs Assessment' AND LOWER(TRIM(a.status))='pending'
 AND NOT EXISTS(SELECT 1 FROM notification_data n WHERE n.counselor_id=?
  AND n.assessment_id=a.assessment_id AND n.notification_status='Read')";
$stmt=mysqli_prepare($conn,$countSql);
if (!$stmt) notification_reply(500,['success'=>false,'message'=>'Notifications unavailable']);
mysqli_stmt_bind_param($stmt,'i',$counselorId);
mysqli_stmt_execute($stmt);
$countResult=mysqli_stmt_get_result($stmt);
$unread=(int)(mysqli_fetch_row($countResult)[0]??0);
mysqli_stmt_close($stmt);

$listSql="SELECT a.assessment_id, a.assessment_date, a.status,
 COALESCE(NULLIF(u.alias,''),u.fullname,'Student') AS student_name,
 EXISTS(SELECT 1 FROM notification_data n WHERE n.counselor_id=?
 AND n.assessment_id=a.assessment_id AND n.notification_status='Read') AS is_read
 FROM assessment_data a
 LEFT JOIN student_profiles u ON u.student_id=a.student_id
 WHERE a.assessment_type='Needs Assessment' AND LOWER(TRIM(a.status))='pending'
 ORDER BY a.assessment_date DESC,a.assessment_id DESC LIMIT 12";
$stmt=mysqli_prepare($conn,$listSql);
if (!$stmt) notification_reply(500,['success'=>false,'message'=>'Notifications unavailable']);
mysqli_stmt_bind_param($stmt,'i',$counselorId);
mysqli_stmt_execute($stmt);
$rows=mysqli_stmt_get_result($stmt);
$items=[];
while($row=mysqli_fetch_assoc($rows)) $items[]=[
 'assessment_id'=>(int)$row['assessment_id'],
 'student_name'=>$row['student_name'],
 'date'=>$row['assessment_date'],
 'is_read'=>(bool)$row['is_read'],
 'url'=>'view_assessment.php?id='.(int)$row['assessment_id']
];
mysqli_stmt_close($stmt);
notification_reply(200,['success'=>true,'unread_count'=>$unread,'items'=>$items]);
