<?php
require_once __DIR__ . '/config/config.php';

echo "<h1>✅ Configuration OK !</h1>";
echo "<pre>";
echo "ROOT_PATH: " . ROOT_PATH . "\n";
echo "SITE_URL: " . SITE_URL . "\n";
echo "DB_NAME: " . DB_NAME . "\n";
echo "PDO Connection: " . (isset($pdo) ? "OK" : "NOK") . "\n";
echo "Session Status: " . (session_status() == PHP_SESSION_ACTIVE ? "Active" : "Inactive") . "\n";
echo "is_ajax_request(): " . (function_exists('is_ajax_request') ? "Defined" : "Not defined") . "\n";
echo "</pre>";