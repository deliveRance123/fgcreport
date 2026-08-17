<?php
// Database Configuration
define('DB_HOST', 'sql309.ezyro.com');
define('DB_NAME', 'ezyro_42443183_fgc_db');
define('DB_USER', 'ezyro_42443183');
define('DB_PASS', 'e32705eebd');
define('DB_CHARSET', 'utf8mb4');

/**
 * Returns a singleton PDO connection.
 */
function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            http_response_code(500);
            die(json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]));
        }
    }
    return $pdo;
}
