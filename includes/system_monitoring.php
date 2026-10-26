<?php

function system_monitoring_table_available($conn)
{
    static $checked = false;
    static $available = false;

    if ($checked) {
        return $available;
    }
    $checked = true;

    $sql = "CREATE TABLE IF NOT EXISTS system_monitor_events (
        event_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        event_type VARCHAR(40) NOT NULL,
        actor_user_id BIGINT UNSIGNED NULL,
        subject_user_id BIGINT UNSIGNED NULL,
        details VARCHAR(255) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (event_id),
        KEY idx_system_monitor_created (created_at),
        KEY idx_system_monitor_type_created (event_type, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    try {
        $available = mysqli_query($conn, $sql) !== false;
    } catch (mysqli_sql_exception $exception) {
        error_log('System monitoring table could not be created or checked: ' . $exception->getMessage());
        $available = false;
        return $available;
    }
    if (!$available) {
        error_log('System monitoring table could not be created or checked: ' . mysqli_error($conn));
    }

    return $available;
}

function system_monitor_log($conn, $event_type, $actor_user_id = null, $subject_user_id = null, $details = '')
{
    if (!system_monitoring_table_available($conn)) {
        return false;
    }

    try {
        $stmt = mysqli_prepare(
            $conn,
            "INSERT INTO system_monitor_events (event_type, actor_user_id, subject_user_id, details)
             VALUES (?, ?, ?, ?)"
        );
    } catch (mysqli_sql_exception $exception) {
        error_log('System monitoring event could not be prepared: ' . $exception->getMessage());
        return false;
    }
    if (!$stmt) {
        error_log('System monitoring event could not be prepared: ' . mysqli_error($conn));
        return false;
    }

    try {
        mysqli_stmt_bind_param($stmt, 'siis', $event_type, $actor_user_id, $subject_user_id, $details);
        $saved = mysqli_stmt_execute($stmt);
    } catch (mysqli_sql_exception $exception) {
        error_log('System monitoring event could not be saved: ' . $exception->getMessage());
        mysqli_stmt_close($stmt);
        return false;
    }
    if (!$saved) {
        error_log('System monitoring event could not be saved: ' . mysqli_stmt_error($stmt));
        mysqli_stmt_close($stmt);
        return false;
    }

    mysqli_stmt_close($stmt);
    return true;
}
