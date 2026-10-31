<?php
require_once __DIR__ . '/session.php';

if(!isset($_SESSION['assessment_id'])){
    header("Location: welcome.php");
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Mental Health Resources</title>

<link rel="stylesheet" href="../assets/css/student.css">

<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

<link rel="stylesheet"
href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">

</head>

<body class="emergency-page">

<div class="emergency-wrapper">

<div class="progress-wrapper">

<span class="progress-text">

Step 6 of 6

</span>

<div class="progress-bar">

<div class="progress-fill" style="width:100%"></div>

</div>

</div>

<div class="emergency-card">

<h1>Mental Health Support Resources</h1>

<p class="emergency-subtitle">

If you or someone you know is struggling,
please remember that support is available.

</p>

<div class="resource-card">

<div class="resource-icon">

<i class="fa-solid fa-school"></i>

</div>

<div class="resource-content">

<h3>PTC Guidance Office</h3>

<p>

Visit the Guidance and Counseling Office
during office hours for consultation and support.

</p>

</div>

</div>

<div class="resource-card">

<div class="resource-icon">

<i class="fa-solid fa-phone"></i>

</div>

<div class="resource-content">

<h3>National Center for Mental Health</h3>

<div class="hotline-chip">1553</div>

<div class="hotline-chip">0917-899-USAP (8727)</div>

<div class="hotline-chip">0908-639-2672</div>

</div>

</div>

<div class="resource-card emergency-warning">

<div class="resource-icon">

<i class="fa-solid fa-triangle-exclamation"></i>

</div>

<div class="resource-content">

<h3>Emergency Reminder</h3>

<p>

If you believe you are in immediate danger,
contact a trusted family member, guidance counselor,
or emergency services immediately.

</p>

</div>

</div>

<form action="thank_you.php" method="POST">

<button class="emergency-btn">

Continue →

</button>

</form>

</div>

</div>

</body>

</html>