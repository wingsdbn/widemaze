<?php
/**
 * WideMaze - Friends API
 * Version 4.0 - Gestion complète des amitiés avec notifications
 * Méthodes: GET (list, requests, status, suggestions), POST (send, accept, reject, cancel, remove)
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

// Vérification authentification
if (!is_logged_in()) {
    json_response(['error' => 'Non authentifié'], STATUS_UNAUTHORIZED);
}

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$userId = $_SESSION['user_id'];

// Vérification CSRF pour les actions de modification
if (in_array($method, ['POST', 'PUT', 'DELETE'])) {
    if (!verify_csrf_token($input['csrf_token'] ?? '')) {
        json_response(['error' => 'Token CSRF invalide'], STATUS_FORBIDDEN);
    }
}

// ==================== ROUTAGE ====================

switch ($method) {
    case 'GET':
        $action = $_GET['action'] ?? 'list';
        
        switch ($action) {
            case 'list':
                // Liste des amis
                $limit = min(intval($_GET['limit'] ?? 50), 100);
                $offset = intval($_GET['offset'] ?? 0);
                $search = trim($_GET['search'] ?? '');
                
                $sql = "
                    SELECT u.id, u.surnom, u.prenom, u.nom, u.avatar, u.status, u.universite,
                        a.date_acceptation as friends_since,
                        (SELECT COUNT(*) FROM posts WHERE id_utilisateur = u.id) as posts_count
                    FROM ami a
                    JOIN utilisateurs u ON (a.id = ? AND a.idami = u.id) OR (a.idami = ? AND a.id = u.id)
                    WHERE a.accepterami = 1
                ";
                $params = [$userId, $userId];
                
                if (!empty($search)) {
                    $sql .= " AND (u.surnom LIKE ? OR u.prenom LIKE ? OR u.nom LIKE ?)";
                    $params[] = "%$search%";
                    $params[] = "%$search%";
                    $params[] = "%$search%";
                }
                
                $sql .= " ORDER BY a.date_acceptation DESC LIMIT ? OFFSET ?";
                $params[] = $limit;
                $params[] = $offset;
                
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $friends = $stmt->fetchAll();
                
                // Compter le total
                $countStmt = $pdo->prepare("
                    SELECT COUNT(*) FROM ami a
                    WHERE (a.id = ? AND a.idami = ?) OR (a.idami = ? AND a.id = ?) AND a.accepterami = 1
                ");
                $countStmt->execute([$userId, $userId, $userId, $userId]);
                $totalCount = $countStmt->fetchColumn();
                
                json_response([
                    'success' => true,
                    'friends' => $friends,
                    'count' => count($friends),
                    'total' => $totalCount,
                    'has_more' => count($friends) === $limit
                ]);
                break;
                
            case 'requests_received':
                // Demandes reçues en attente
                $limit = min(intval($_GET['limit'] ?? 20), 50);
                $offset = intval($_GET['offset'] ?? 0);
                
                $stmt = $pdo->prepare("
                    SELECT u.id, u.surnom, u.prenom, u.nom, u.avatar, u.universite, a.date_demande,
                        (SELECT COUNT(*) FROM ami WHERE (id = u.id OR idami = u.id) AND accepterami = 1) as friends_count
                    FROM ami a
                    JOIN utilisateurs u ON a.id = u.id
                    WHERE a.idami = ? AND a.demandeami = 1 AND a.accepterami = 0
                    ORDER BY a.date_demande DESC
                    LIMIT ? OFFSET ?
                ");
                $stmt->execute([$userId, $limit, $offset]);
                $requests = $stmt->fetchAll();
                
                json_response([
                    'success' => true,
                    'requests' => $requests,
                    'count' => count($requests)
                ]);
                break;
                
            case 'requests_sent':
                // Demandes envoyées en attente
                $stmt = $pdo->prepare("
                    SELECT u.id, u.surnom, u.prenom, u.nom, u.avatar, u.universite, a.date_demande
                    FROM ami a
                    JOIN utilisateurs u ON a.idami = u.id
                    WHERE a.id = ? AND a.demandeami = 1 AND a.accepterami = 0
                    ORDER BY a.date_demande DESC
                ");
                $stmt->execute([$userId]);
                $requests = $stmt->fetchAll();
                
                json_response([
                    'success' => true,
                    'requests_sent' => $requests,
                    'count' => count($requests)
                ]);
                break;
                
            case 'status':
                $targetId = intval($_GET['user_id'] ?? 0);
                if (!$targetId) {
                    json_response(['error' => 'user_id requis'], STATUS_BAD_REQUEST);
                }
                
                $stmt = $pdo->prepare("
                    SELECT demandeami, accepterami,
                        CASE
                            WHEN id = ? THEN 'sent'
                            WHEN idami = ? THEN 'received'
                        END as direction
                    FROM ami
                    WHERE (id = ? AND idami = ?) OR (id = ? AND idami = ?)
                ");
                $stmt->execute([$userId, $userId, $userId, $targetId, $targetId, $userId]);
                $relation = $stmt->fetch();
                
                $status = 'none';
                if ($relation) {
                    if ($relation['accepterami']) {
                        $status = 'friends';
                    } elseif ($relation['demandeami']) {
                        $status = $relation['direction'] === 'sent' ? 'pending_sent' : 'pending_received';
                    }
                }
                
                json_response(['success' => true, 'status' => $status, 'user_id' => $targetId]);
                break;
                
            case 'suggestions':
                // Suggestions d'amis basées sur les amis communs et les intérêts
                $limit = min(intval($_GET['limit'] ?? 10), 20);
                
                $stmt = $pdo->prepare("
                    SELECT u.id, u.surnom, u.prenom, u.nom, u.avatar, u.universite, u.profession,
                        (SELECT COUNT(*) FROM ami 
                         WHERE (id = u.id AND idami IN (
                             SELECT CASE WHEN id = ? THEN idami ELSE id END
                             FROM ami WHERE (id = ? OR idami = ?) AND accepterami = 1
                         )) OR (idami = u.id AND id IN (
                             SELECT CASE WHEN id = ? THEN idami ELSE id END
                             FROM ami WHERE (id = ? OR idami = ?) AND accepterami = 1
                         ))) as mutual_friends,
                        (SELECT COUNT(*) FROM posts WHERE id_utilisateur = u.id) as posts_count
                    FROM utilisateurs u
                    WHERE u.id != ?
                        AND u.is_active = 1
                        AND u.id NOT IN (
                            SELECT CASE WHEN id = ? THEN idami ELSE id END
                            FROM ami WHERE (id = ? OR idami = ?) AND (accepterami = 1 OR demandeami = 1)
                        )
                    ORDER BY mutual_friends DESC, u.surnom ASC
                    LIMIT ?
                ");
                $stmt->execute([
                    $userId, $userId, $userId, $userId, $userId, $userId,
                    $userId,
                    $userId, $userId, $userId,
                    $limit
                ]);
                $suggestions = $stmt->fetchAll();
                
                json_response([
                    'success' => true,
                    'suggestions' => $suggestions,
                    'count' => count($suggestions)
                ]);
                break;
                
            default:
                json_response(['error' => 'Action inconnue'], STATUS_BAD_REQUEST);
        }
        break;
        
    case 'POST':
        $action = $input['action'] ?? '';
        
        switch ($action) {
            case 'send_request':
                $targetId = intval($input['user_id'] ?? 0);
                
                if ($targetId === $userId) {
                    json_response(['error' => 'Vous ne pouvez pas vous ajouter vous-même'], STATUS_BAD_REQUEST);
                }
                if ($targetId <= 0) {
                    json_response(['error' => 'ID utilisateur invalide'], STATUS_BAD_REQUEST);
                }
                
                // Vérifier si l'utilisateur existe et est actif
                $checkStmt = $pdo->prepare("SELECT id, is_active FROM utilisateurs WHERE id = ?");
                $checkStmt->execute([$targetId]);
                $targetUser = $checkStmt->fetch();
                
                if (!$targetUser) {
                    json_response(['error' => 'Utilisateur non trouvé'], STATUS_NOT_FOUND);
                }
                if (!$targetUser['is_active']) {
                    json_response(['error' => 'Cet utilisateur n\'est plus actif'], STATUS_BAD_REQUEST);
                }
                
                // Vérifier si une relation existe déjà
                $checkRelation = $pdo->prepare("
                    SELECT * FROM ami
                    WHERE (id = ? AND idami = ?) OR (id = ? AND idami = ?)
                ");
                $checkRelation->execute([$userId, $targetId, $targetId, $userId]);
                if ($checkRelation->fetch()) {
                    json_response(['error' => 'Une demande existe déjà'], STATUS_CONFLICT);
                }
                
                // Créer la demande
                $stmt = $pdo->prepare("
                    INSERT INTO ami (id, idami, demandeami, date_demande) 
                    VALUES (?, ?, 1, NOW())
                ");
                $stmt->execute([$userId, $targetId]);
                $friendshipId = $pdo->lastInsertId();
                
                // Créer notification pour le destinataire
                create_notification(
                    $pdo,
                    $targetId,
                    NOTIF_FRIEND_REQUEST,
                    '@' . $_SESSION['surnom'] . ' vous a envoyé une demande d\'ami',
                    $userId,
                    SITE_URL . '/pages/notifications.php'
                );
                
                log_activity($pdo, $userId, 'friend_request_sent', ['target_id' => $targetId]);
                
                json_response([
                    'success' => true,
                    'message' => 'Demande envoyée',
                    'friendship_id' => $friendshipId
                ]);
                break;
                
            case 'accept_request':
                $targetId = intval($input['user_id'] ?? 0);
                
                // Vérifier que la demande existe
                $checkStmt = $pdo->prepare("
                    SELECT * FROM ami
                    WHERE id = ? AND idami = ? AND demandeami = 1 AND accepterami = 0
                ");
                $checkStmt->execute([$targetId, $userId]);
                $request = $checkStmt->fetch();
                
                if (!$request) {
                    json_response(['error' => 'Demande non trouvée'], STATUS_NOT_FOUND);
                }
                
                // Accepter la demande
                $stmt = $pdo->prepare("
                    UPDATE ami SET accepterami = 1, date_acceptation = NOW()
                    WHERE id = ? AND idami = ?
                ");
                $stmt->execute([$targetId, $userId]);
                
                // Notifier l'expéditeur
                create_notification(
                    $pdo,
                    $targetId,
                    NOTIF_FRIEND_ACCEPT,
                    '@' . $_SESSION['surnom'] . ' a accepté votre demande d\'ami',
                    $userId,
                    SITE_URL . '/pages/profil.php?id=' . $userId
                );
                
                log_activity($pdo, $userId, 'friend_request_accepted', ['friend_id' => $targetId]);
                
                json_response(['success' => true, 'message' => 'Demande acceptée']);
                break;
                
            case 'reject_request':
                $targetId = intval($input['user_id'] ?? 0);
                
                // Supprimer la demande
                $stmt = $pdo->prepare("
                    DELETE FROM ami
                    WHERE id = ? AND idami = ? AND demandeami = 1 AND accepterami = 0
                ");
                $stmt->execute([$targetId, $userId]);
                
                if ($stmt->rowCount() === 0) {
                    json_response(['error' => 'Demande non trouvée'], STATUS_NOT_FOUND);
                }
                
                log_activity($pdo, $userId, 'friend_request_rejected', ['user_id' => $targetId]);
                json_response(['success' => true, 'message' => 'Demande rejetée']);
                break;
                
            case 'cancel_request':
                $targetId = intval($input['user_id'] ?? 0);
                
                // Annuler une demande envoyée
                $stmt = $pdo->prepare("
                    DELETE FROM ami
                    WHERE id = ? AND idami = ? AND demandeami = 1 AND accepterami = 0
                ");
                $stmt->execute([$userId, $targetId]);
                
                if ($stmt->rowCount() === 0) {
                    json_response(['error' => 'Demande non trouvée'], STATUS_NOT_FOUND);
                }
                
                log_activity($pdo, $userId, 'friend_request_cancelled', ['user_id' => $targetId]);
                json_response(['success' => true, 'message' => 'Demande annulée']);
                break;
                
            case 'remove_friend':
                $targetId = intval($input['user_id'] ?? 0);
                
                // Supprimer l'amitié dans les deux sens
                $stmt = $pdo->prepare("
                    DELETE FROM ami
                    WHERE (id = ? AND idami = ?) OR (id = ? AND idami = ?)
                ");
                $stmt->execute([$userId, $targetId, $targetId, $userId]);
                
                if ($stmt->rowCount() === 0) {
                    json_response(['error' => 'Amitié non trouvée'], STATUS_NOT_FOUND);
                }
                
                log_activity($pdo, $userId, 'friend_removed', ['user_id' => $targetId]);
                json_response(['success' => true, 'message' => 'Amitié supprimée']);
                break;
                
            default:
                json_response(['error' => 'Action non reconnue'], STATUS_BAD_REQUEST);
        }
        break;
        
    default:
        json_response(['error' => 'Méthode non supportée'], STATUS_METHOD_NOT_ALLOWED);
}