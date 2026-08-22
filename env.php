<?php
/**
 * DCVS Environment Loader
 *
 * Loads configuration values from the .env file (or real environment
 * variables set on the server). Never expose this file or .env publicly.
 */

if (!function_exists('loadDcvsEnv')) {
    function loadDcvsEnv($file = __DIR__ . '/.env') {
        if (!is_file($file) || !is_readable($file)) {
            return;
        }
        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
                continue;
            }
            $pos = strpos($line, '=');
            $key = trim(substr($line, 0, $pos));
            $val = trim(substr($line, $pos + 1));
            if (strlen($val) >= 2
                && (($val[0] === '"' && substr($val, -1) === '"')
                    || ($val[0] === "'" && substr($val, -1) === "'"))) {
                $val = substr($val, 1, -1);
            }
            if ($key === '') {
                continue;
            }
            if (getenv($key) === false) {
                putenv($key . '=' . $val);
            }
            if (!array_key_exists($key, $_ENV)) {
                $_ENV[$key] = $val;
            }
        }
    }
}

/**
 * Safely read an env value (getenv wins over .env) with a fallback default.
 */
if (!function_exists('env')) {
    function env($key, $default = null) {
        $fromEnv = getenv($key);
        if ($fromEnv !== false && $fromEnv !== '') {
            return $fromEnv;
        }
        return array_key_exists($key, $_ENV) ? $_ENV[$key] : $default;
    }
}