<?php
require_once 'config.php';

// Mettre à jour le statut si utilisateur connecté
if (isset($_SESSION['user_id'])) {
    try {
        $stmt = $pdo->prepare("UPDATE utilisateurs SET status = 'Offline' WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        log_activity($pdo, $_SESSION['user_id'], 'logout');
    } catch (PDOException $e) {
        // Ignorer les erreurs
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

// Redirection
header('Location: connexion.php');
exit();