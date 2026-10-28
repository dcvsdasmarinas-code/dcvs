<?php

if (!function_exists('dcvsAppBaseUrl')) {
    function dcvsAppBaseUrl(): string
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
        $pagesPathOffset = strpos($requestPath, '/pages/');
        if ($pagesPathOffset !== false) {
            return substr($requestPath, 0, $pagesPathOffset);
        }

        return '';
    }
}
