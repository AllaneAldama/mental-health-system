
<?php
mysqli_report(MYSQLI_REPORT_OFF);

$conn = mysqli_connect(
    "localhost",
    "root",
    "",
    "mental_health_system"
);

$db_connection_error = null;

if (!$conn) {
    $db_connection_error = mysqli_connect_error();
}
