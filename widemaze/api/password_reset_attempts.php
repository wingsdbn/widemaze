<?php
/**
 * WideMaze - Password Reset Attempts API
 * Version 1.0 - Gestion des tentatives de récupération de mot de passe (rate limiting)
 * Méthodes: POST (log_attempt), GET (check_rate_limit)
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

// Vérification authentification administrateur pour certaines actions
// (Les tentatives sont enregistrées sans authentification)
$isAdmin = is_admin();

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$ipAddress = $_SERVER['REMOTE_ADDR'];

// ==================== FONCTIONS ====================

/**
 * Enregistre une tentative de récupération de mot de passe
 * @param PDO $pdo Connexion à la base de données
 * @param string $ip Adresse IP
 * @param string|null $email Email utilisé (optionnel)
 * @param bool $success Succès ou échec de l'envoi
 * @return bool
 */
function logPasswordResetAttempt($pdo, $ip, $email = null, $success = false) {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO password_reset_attempts (ip_address, email, success, attempted_at) 
            VALUES (?, ?, ?, NOW())
        ");
        return $stmt->execute([$ip, $email, $success ? 1 : 0]);
    } catch (PDOException $e) {
        error_log("Error logging password reset attempt: " . $e->getMessage());
        return false;
    }
}

/**
 * Vérifie si une IP a dépassé le nombre de tentatives autorisées
 * @param PDO $pdo Connexion à la base de données
 * @param string $ip Adresse IP
 * @param int $maxAttempts Nombre maximal de tentatives (défaut: 5)
 * @param int $windowMinutes Fenêtre de temps en minutes (défaut: 60)
 * @return array ['blocked' => bool, 'remaining_attempts' => int, 'wait_minutes' => int]
 */
function checkPasswordResetRateLimit($pdo, $ip, $maxAttempts = 5, $windowMinutes = 60) {
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as attempt_count, 
                   MAX(attempted_at) as last_attempt
            FROM password_reset_attempts 
            WHERE ip_address = ? 
              AND attempted_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)
        ");
        $stmt->execute([$ip, $windowMinutes]);
        $result = $stmt->fetch();
        
        $attemptCount = (int)$result['attempt_count'];
        $lastAttempt = $result['last_attempt'];
        
        if ($attemptCount >= $maxAttempts) {
            // Calculer le temps restant
            $lastAttemptTime = strtotime($lastAttempt);
            $expiryTime = $lastAttemptTime + ($windowMinutes * 60);
            $waitMinutes = ceil(($expiryTime - time()) / 60);
            
            return [
                'blocked' => true,
                'remaining_attempts' => 0,
                'wait_minutes' => max(1, $waitMinutes),
                'attempt_count' => $attemptCount,
                'max_attempts' => $maxAttempts
            ];
        }
        
        return [
            'blocked' => false,
            'remaining_attempts' => $maxAttempts - $attemptCount,
            'wait_minutes' => 0,
            'attempt_count' => $attemptCount,
            'max_attempts' => $maxAttempts
        ];
    } catch (PDOException $e) {
        error_log("Error checking rate limit: " . $e->getMessage());
        return [
            'blocked' => false,
            'remaining_attempts' => $maxAttempts,
            'wait_minutes' => 0,
            'error' => true
        ];
    }
}

/**
 * Nettoie les anciennes tentatives (plus de 24h)
 * @param PDO $pdo Connexion à la base de données
 * @return int Nombre de lignes supprimées
 */
function cleanOldResetAttempts($pdo) {
    try {
        $stmt = $pdo->prepare("
            DELETE FROM password_reset_attempts 
            WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ");
        $stmt->execute();
        return $stmt->rowCount();
    } catch (PDOException $e) {
        error_log("Error cleaning old reset attempts: " . $e->getMessage());
        return 0;
    }
}

// ==================== ROUTAGE ====================

switch ($method) {
    case 'GET':
        $action = $_GET['action'] ?? 'check';
        
        switch ($action) {
            case 'check':
                // Vérifier le rate limiting pour une IP
                $ip = $_GET['ip'] ?? $ipAddress;
                $email = $_GET['email'] ?? null;
                
                // Vérification d'authentification (admin uniquement)
                if ($ip !== $ipAddress && !$isAdmin) {
                    json_response(['error' => 'Permission refusée'], STATUS_FORBIDDEN);
                    break;
                }
                
                $rateLimit = checkPasswordResetRateLimit($pdo, $ip);
                
                json_response([
                    'success' => true,
                    'rate_limit' => $rateLimit
                ]);
                break;
                
            case 'stats':
                // Statistiques des tentatives (admin uniquement)
                if (!$isAdmin) {
                    json_response(['error' => 'Permission refusée'], STATUS_FORBIDDEN);
                    break;
                }
                
                $period = $_GET['period'] ?? '24h';
                
                $interval = match($period) {
                    'today' => 'CURDATE()',
                    'week' => 'DATE_SUB(NOW(), INTERVAL 7 DAY)',
                    'month' => 'DATE_SUB(NOW(), INTERVAL 30 DAY)',
                    default => 'DATE_SUB(NOW(), INTERVAL 24 HOUR)'
                };
                
                $stmt = $pdo->prepare("
                    SELECT 
                        COUNT(*) as total_attempts,
                        SUM(success) as successful_attempts,
                        COUNT(DISTINCT ip_address) as unique_ips,
                        COUNT(DISTINCT email) as unique_emails
                    FROM password_reset_attempts 
                    WHERE attempted_at > $interval
                ");
                $stmt->execute();
                $stats = $stmt->fetch();
                
                // Tentatives par IP
                $stmt = $pdo->prepare("
                    SELECT ip_address, COUNT(*) as attempts, MAX(attempted_at) as last_attempt
                    FROM password_reset_attempts 
                    WHERE attempted_at > $interval
                    GROUP BY ip_address
                    ORDER BY attempts DESC
                    LIMIT 10
                ");
                $stmt->execute();
                $topIps = $stmt->fetchAll();
                
                json_response([
                    'success' => true,
                    'period' => $period,
                    'stats' => $stats,
                    'top_ips' => $topIps
                ]);
                break;
                
            case 'clean':
                // Nettoyage manuel (admin uniquement)
                if (!$isAdmin) {
                    json_response(['error' => 'Permission refusée'], STATUS_FORBIDDEN);
                    break;
                }
                
                $deleted = cleanOldResetAttempts($pdo);
                
                json_response([
                    'success' => true,
                    'deleted' => $deleted,
                    'message' => "$deleted anciennes tentatives supprimées"
                ]);
                break;
                
            default:
                json_response(['error' => 'Action inconnue'], STATUS_BAD_REQUEST);
        }
        break;
        
    case 'POST':
        $action = $input['action'] ?? 'log';
        
        switch ($action) {
            case 'log':
                // Enregistrer une tentative (appelé par passwordrecover.php)
                $email = $input['email'] ?? null;
                $success = isset($input['success']) ? (bool)$input['success'] : false;
                
                // Vérification CSRF optionnelle (appel interne, pas obligatoire)
                // Les appels depuis passwordrecover.php incluent déjà leur propre CSRF
                
                $result = logPasswordResetAttempt($pdo, $ipAddress, $email, $success);
                
                json_response([
                    'success' => $result,
                    'message' => $result ? 'Tentative enregistrée' : 'Erreur lors de l\'enregistrement'
                ]);
                break;
                
            case 'check_and_log':
                // Vérifier puis enregistrer (pour éviter un appel séparé)
                $email = $input['email'] ?? null;
                $maxAttempts = $input['max_attempts'] ?? 5;
                $windowMinutes = $input['window_minutes'] ?? 60;
                
                $rateLimit = checkPasswordResetRateLimit($pdo, $ipAddress, $maxAttempts, $windowMinutes);
                
                if ($rateLimit['blocked']) {
                    json_response([
                        'success' => false,
                        'error' => 'Trop de tentatives',
                        'rate_limit' => $rateLimit
                    ], STATUS_TOO_MANY_REQUESTS);
                    break;
                }
                
                // Enregistrer la tentative
                logPasswordResetAttempt($pdo, $ipAddress, $email, false);
                
                json_response([
                    'success' => true,
                    'rate_limit' => $rateLimit,
                    'message' => 'Tentative autorisée'
                ]);
                break;
                
            case 'clean':
                // Nettoyage automatique (peut être appelé par cron)
                $deleted = cleanOldResetAttempts($pdo);
                
                json_response([
                    'success' => true,
                    'deleted' => $deleted,
                    'message' => "$deleted anciennes tentatives supprimées"
                ]);
                break;
                
            default:
                json_response(['error' => 'Action inconnue'], STATUS_BAD_REQUEST);
        }
        break;
        
    case 'DELETE':
        // Supprimer les tentatives d'une IP (admin uniquement)
        if (!$isAdmin) {
            json_response(['error' => 'Permission refusée'], STATUS_FORBIDDEN);
            break;
        }
        
        $ip = $input['ip'] ?? $_GET['ip'] ?? null;
        
        if (!$ip) {
            json_response(['error' => 'IP requise'], STATUS_BAD_REQUEST);
            break;
        }
        
        try {
            $stmt = $pdo->prepare("DELETE FROM password_reset_attempts WHERE ip_address = ?");
            $stmt->execute([$ip]);
            $deleted = $stmt->rowCount();
            
            log_activity($pdo, $_SESSION['user_id'], 'admin_clear_reset_attempts', ['ip' => $ip, 'deleted' => $deleted]);
            
            json_response([
                'success' => true,
                'deleted' => $deleted,
                'message' => "$deleted tentatives supprimées pour l'IP $ip"
            ]);
        } catch (PDOException $e) {
            error_log("Error deleting reset attempts: " . $e->getMessage());
            json_response(['error' => 'Erreur lors de la suppression'], STATUS_SERVER_ERROR);
        }
        break;
        
    default:
        json_response(['error' => 'Méthode non supportée'], STATUS_METHOD_NOT_ALLOWED);
}