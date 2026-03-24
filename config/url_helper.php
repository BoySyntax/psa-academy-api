<?php
// Shared URL helper used by all API endpoints.
//
// STRATEGY: Upload endpoints always save  http://localhost/charming_api/...
// paths in the database. Read endpoints call normalize_public_file_url()
// to rewrite those stored localhost URLs to whatever the current public
// host is (e.g. the ngrok tunnel URL). This means the database never
// stores a ngrok URL that could become stale when ngrok is restarted.

if (!function_exists('get_public_base_url')) {
    function get_public_base_url() {
        $scheme = 'http';
        if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
            $scheme = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_PROTO'])[0]);
        } elseif (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            $scheme = 'https';
        }

        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        if (!empty($_SERVER['HTTP_X_FORWARDED_HOST'])) {
            $forwardedHosts = explode(',', $_SERVER['HTTP_X_FORWARDED_HOST']);
            $host = trim($forwardedHosts[0]);
        }

        return $scheme . '://' . $host . '/charming_api';
    }
}

if (!function_exists('normalize_public_file_url')) {
    // Rewrites any stored http://localhost/... URL to the current public host.
    // Safe to call on already-public URLs – they pass through unchanged.
    function normalize_public_file_url($url) {
        if (!$url) return $url;
        return preg_replace(
            '#^https?://(localhost|127\.0\.0\.1)(/charming_api)?#',
            get_public_base_url(),
            $url
        );
    }
}

if (!function_exists('make_local_file_url')) {
    // Builds a canonical http://localhost/... URL for a given upload sub-path.
    // Upload endpoints use this so the database always stores localhost URLs.
    function make_local_file_url($subPath) {
        // $subPath e.g. 'uploads/profile_images/profile_xxx.jpg'
        return 'http://localhost/charming_api/' . ltrim($subPath, '/');
    }
}

if (!function_exists('url_to_local_path')) {
    // Converts any stored URL (localhost or public) to the relative filesystem
    // path under charming_api/ so files can be deleted or checked.
    function url_to_local_path($url) {
        if (!$url) return null;
        $path = parse_url($url, PHP_URL_PATH);
        if (!$path) return null;
        $prefix = '/charming_api/';
        $pos = strpos($path, $prefix);
        if ($pos === false) return null;
        return '../' . substr($path, $pos + strlen($prefix));
    }
}
?>
