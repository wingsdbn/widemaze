<?php
/**
 * WideMaze - Déconnexion
 * Fermeture de session et nettoyage
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

// Mettre à jour le statut si utilisateur connecté
if (isset($_SESSION['user_id'])) {
    try {
        set_user_offline($pdo, $_SESSION['user_id']);
        log_activity($pdo, $_SESSION['user_id'], 'logout');
    } catch (PDOException $e) {
        // Ignorer les erreurs de log lors de la déconnexion
        error_log("Logout error: " . $e->getMessage());
    }
}

// Détruire la session
$_SESSION = [];
session_destroy();

// Supprimer le cookie de session
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Supprimer le cookie remember me
setcookie('remember_email', '', time() - 3600, '/');

// Redirection
header('Location: connexion.php?logout=1');
exit();
?>