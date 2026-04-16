<?php
// ============================================================
//  config/database.php
//  Shared database configuration — used by ALL modules
//  Edit this file once; all modules inherit the settings.
// ============================================================

define('DB_HOST',    'localhost');
define('DB_NAME',    'itds_tracker');
define('DB_USER',    'root');        // change in production
define('DB_PASS',    '');            // change in production
define('DB_CHARSET', 'utf8mb4');

/**
 * Returns a singleton PDO connection.
 * Usage: $pdo = db();
 */
function db(): PDO {
    static $pdo = null;

    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=%s',
            DB_HOST, DB_NAME, DB_CHARSET
        );

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            // In production, log instead of echoing
            http_response_code(500);
            die(json_encode(['success' => false, 'message' => 'Database connection failed.']));
        }
    }

    return $pdo;
}
