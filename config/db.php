<?php
/**
 * ══════════════════════════════════════════════════════════
 *  DATABASE CONFIGURATION — Edit this file for your host
 * ══════════════════════════════════════════════════════════
 *
 *  LOCAL (XAMPP):
 *    DB_HOST = 'localhost'
 *    DB_NAME = 'foursquare_reports'
 *    DB_USER = 'root'
 *    DB_PASS = ''
 *
 *  FREE HOST (InfinityFree, 000webhost, etc.):
 *    DB_HOST = your host's MySQL hostname  (e.g. sql200.byethost.com)
 *    DB_NAME = your database name          (e.g. b12345_foursquare_reports)
 *    DB_USER = your database username      (e.g. b12345_admin)
 *    DB_PASS = your database password
 *    APP_DEBUG = false  (hides errors from visitors)
 */

// Allow override via custom host file if present
if (file_exists(__DIR__ . '/db_custom.php')) {
    require_once __DIR__ . '/db_custom.php';
}

// ─── Database credentials (with environment variable fallback) ──────────────
if (!defined('DB_HOST'))    define('DB_HOST',    getenv('DB_HOST')    ?: 'localhost');
if (!defined('DB_PORT'))    define('DB_PORT',    getenv('DB_PORT')    ?: '3306');
if (!defined('DB_NAME'))    define('DB_NAME',    getenv('DB_NAME')    ?: 'foursquare_reports');
if (!defined('DB_USER'))    define('DB_USER',    getenv('DB_USER')    ?: 'root');
if (!defined('DB_PASS'))    define('DB_PASS',    getenv('DB_PASS') !== false ? getenv('DB_PASS') : '');
if (!defined('DB_CHARSET')) define('DB_CHARSET', 'utf8mb4');

// ─── Debug mode: set false on live hosting ─────────────
if (!defined('APP_DEBUG'))  define('APP_DEBUG', true);

/**
 * Returns a singleton PDO connection.
 */
function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            // Return structured JSON error for AJAX requests
            $msg = 'Database connection failed to host [' . DB_HOST . '] database [' . DB_NAME . ']: ' . $e->getMessage();
            if (ob_get_length()) ob_clean();
            header('Content-Type: application/json; charset=utf-8');
            die(json_encode([
                'success' => false,
                'error'   => $msg,
                'message' => $msg
            ], JSON_UNESCAPED_UNICODE));
        }
    }
    return $pdo;
}
