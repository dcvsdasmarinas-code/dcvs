<?php

if (!function_exists('dcvsStaffAppBaseUrl')) {
    function dcvsStaffAppBaseUrl(): string
    {
        $appRootPath = realpath(__DIR__ . DIRECTORY_SEPARATOR . '..');
        $documentRootPath = realpath((string)($_SERVER['DOCUMENT_ROOT'] ?? ''));

        if ($appRootPath !== false && $documentRootPath !== false
            && strncasecmp($appRootPath, $documentRootPath, strlen($documentRootPath)) === 0) {
            $relativePath = trim(
                str_replace(DIRECTORY_SEPARATOR, '/', substr($appRootPath, strlen($documentRootPath))),
                '/'
            );

            return $relativePath === '' ? '' : '/' . $relativePath;
        }

        $requestPath = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
        $staffPathMarker = '/pages/staff/';
        $staffPathOffset = strpos($requestPath, $staffPathMarker);
        if ($staffPathOffset !== false) {
            return substr($requestPath, 0, $staffPathOffset);
        }

        $dashboardSuffix = '/dashboard-staff.php';
        if (substr($requestPath, -strlen($dashboardSuffix)) === $dashboardSuffix) {
            return substr($requestPath, 0, -strlen($dashboardSuffix));
        }

        return '';
    }
}
