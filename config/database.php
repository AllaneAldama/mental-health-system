<?php

$host = "localhost";
$username = "root";
$password = "";
$database = "mental_health_system";

$conn = false;
try {
    $conn = @mysqli_connect($host, $username, $password, $database);
} catch (mysqli_sql_exception $exception) {
    $database_connection_error = $exception->getMessage();
}

if (!$conn) {
    if (!isset($database_connection_error)) {
        $database_connection_error = mysqli_connect_error();
    }
    error_log("Database connection failed: " . $database_connection_error);
    if (empty($dashboard_monitoring_mode)) {
        die("Database connection failed: " . $database_connection_error);
    }
}