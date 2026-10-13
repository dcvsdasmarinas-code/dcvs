<?php

date_default_timezone_set('Asia/Manila');

if (!function_exists('dcvsSetDatabaseTimezone')) {
    function dcvsSetDatabaseTimezone(mysqli $connection): void
    {
        if (!$connection->query("SET time_zone = '+08:00'")) {
            throw new RuntimeException('Could not set the database session timezone to Asia/Manila: ' . $connection->error);
        }
    }
}
