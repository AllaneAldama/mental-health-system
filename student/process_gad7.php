<?php

session_start();

require_once "../config/database.php";

/** @var mysqli $conn */

if (!isset($_SESSION['user_id'])) {

    die("User session not found.");

}

$user_id = $_SESSION['user_id'];

/* SAVE GAD ANSWERS */

$_SESSION['gad7_answers'] = $_POST;

/* COMPUTE SCORES */

$phq9_score = array_sum($_SESSION['phq9_answers']);
$gad7_score = array_sum($_SESSION['gad7_answers']);

/* PHQ LEVEL */

if ($phq9_score <= 4) {

    $phq_level = "Minimal";

} elseif ($phq9_score <= 9) {

    $phq_level = "Mild";

} elseif ($phq9_score <= 14) {

    $phq_level = "Moderate";

} elseif ($phq9_score <= 19) {

    $phq_level = "Moderately Severe";

} else {

    $phq_level = "Severe";

}

/* GAD LEVEL */

if ($gad7_score <= 4) {

    $gad_level = "Minimal";

} elseif ($gad7_score <= 9) {

    $gad_level = "Mild";

} elseif ($gad7_score <= 14) {

    $gad_level = "Moderate";

} else {

    $gad_level = "Severe";

}

/* OVERALL RISK */

if ($phq_level == "Severe" || $gad_level == "Severe") {

    $risk_level = "High";

} elseif ($phq_level == "Moderate" || $gad_level == "Moderate") {

    $risk_level = "Moderate";

} else {

    $risk_level = "Low";

}

/* INSERT ASSESSMENT */

$sql = "INSERT INTO assessment_data
(
    user_id,
    phq9_score,
    phq_level,
    gad7_score,
    gad_level,
    risk_level,
    status
)
VALUES
(
    ?,?,?,?,?,?,
    'Pending'
)";

$stmt = mysqli_prepare($conn, $sql);

if (!$stmt) {

    die(mysqli_error($conn));

}

mysqli_stmt_bind_param(

    $stmt,

    "iissss",

    $user_id,
    $phq9_score,
    $phq_level,
    $gad7_score,
    $gad_level,
    $risk_level

);

if (!mysqli_stmt_execute($stmt)) {

    die("INSERT ERROR: " . mysqli_stmt_error($stmt));

}

$assessment_id = mysqli_insert_id($conn);

$_SESSION['assessment_id'] = $assessment_id;

/* SAVE PHQ RESPONSES */

foreach($_SESSION['phq9_answers'] as $question => $answer){

    $question_id = str_replace("question_","",$question);

    $sql = "INSERT INTO response_data
    (
        assessment_id,
        question_id,
        answer_score
    )
    VALUES
    (
        ?,?,?
    )";

    $stmt = mysqli_prepare($conn,$sql);

    mysqli_stmt_bind_param(

        $stmt,

        "iii",

        $assessment_id,
        $question_id,
        $answer

    );

    if(!mysqli_stmt_execute($stmt)){

        die(mysqli_stmt_error($stmt));

    }

}

/* SAVE GAD RESPONSES */

foreach($_SESSION['gad7_answers'] as $question => $answer){

    $question_id = str_replace("question_","",$question);

    $sql = "INSERT INTO response_data
    (
        assessment_id,
        question_id,
        answer_score
    )
    VALUES
    (
        ?,?,?
    )";

    $stmt = mysqli_prepare($conn,$sql);

    mysqli_stmt_bind_param(

        $stmt,

        "iii",

        $assessment_id,
        $question_id,
        $answer

    );

    if(!mysqli_stmt_execute($stmt)){

        die(mysqli_stmt_error($stmt));

    }

}

header("Location: emergency_resources.php");
exit();