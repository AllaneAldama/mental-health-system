<?php

session_start();

$_SESSION['phq9_answers'] = $_POST;

header("Location: gad7.php");
exit();

?>