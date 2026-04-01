<?php

/**
 * WideMaze - Main Configuration File
 * Include this file at the beginning of every page
 */

// 1. Définir le chemin racine AVANT toute inclusion
define('ROOT_PATH', dirname(__DIR__));

// 2. Configuration des erreurs
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', ROOT_PATH . '/logs/php_errors.log');

// 3. Inclure les constantes (dépendances minimales)
require_once ROOT_PATH . '/config/constants.php';

// 4. Créer le dossier logs s'il n'existe pas
if (!is_dir(LOGS_DIR)) {
    mkdir(LOGS_DIR, 0755, true);
}

// 5. Inclure et initialiser la base de données
require_once ROOT_PATH . '/config/database.php';

// 6. Initialiser la connexion PDO
$db = Database::getInstance();
$pdo = $db->getConnection();

// 7. Inclure les fonctions
require_once ROOT_PATH . '/includes/functions.php';

// 8. Inclure l'authentification
require_once ROOT_PATH . '/includes/auth.php';

// 9. Configuration des sessions
if (session_status() == PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_strict_mode', 1);
    ini_set('session.cookie_samesite', 'Lax');
    
    if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
        ini_set('session.cookie_secure', 1);
    }
    
    session_start();
}

// 10. Régénération périodique de l'ID de session
if (isset($_SESSION['last_regeneration'])) {
    if (time() - $_SESSION['last_regeneration'] > 300) {
        session_regenerate_id(true);
        $_SESSION['last_regeneration'] = time();
    }
} else {
    $_SESSION['last_regeneration'] = time();
}

// 11. Initialiser la dernière activité si nécessaire
if (isset($_SESSION['user_id']) && !isset($_SESSION['last_activity'])) {
    $_SESSION['last_activity'] = time();
}

// 12. Vérification des sessions actives
if (isset($_SESSION['user_id']) && isset($_SESSION['last_activity'])) {
    if (time() - $_SESSION['last_activity'] > SESSION_LIFETIME) {
        session_unset();
        session_destroy();
        
        if (isset($_COOKIE[session_name()])) {
            setcookie(session_name(), '', time() - 3600, '/');
        }
        
        if (function_exists('is_ajax_request') && is_ajax_request()) {
            // Cette fonction sera définie dans functions.php
            json_response(['error' => 'Session expirée', 'redirect' => 'connexion.php'], STATUS_UNAUTHORIZED);
        }
        
        header('Location: ' . SITE_URL . '/pages/connexion.php?error=session_expired');
        exit();
    }
    
    $_SESSION['last_activity'] = time();
}

// 13. Headers de sécurité
if (!headers_sent()) {
    header("X-Content-Type-Options: nosniff");
    header("X-XSS-Protection: 1; mode=block");
    header("Referrer-Policy: strict-origin-when-cross-origin");
    header("X-Frame-Options: DENY");
}

// 14. Fonction utilitaire AJAX (si non définie dans functions.php)
if (!function_exists('is_ajax_request')) {
    function is_ajax_request() {
        return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
               strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';
    }
}