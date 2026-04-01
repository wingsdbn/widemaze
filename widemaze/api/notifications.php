<?php
/**
 * WideMaze - Notifications API
 * Version 4.0 - Gestion complète des notifications avec filtres et pagination
 * Méthodes: GET (list, count), POST (mark_read, mark_unread), DELETE
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
                $limit = min(intval($_GET['limit'] ?? 20), 100);
                $offset = intval($_GET['offset'] ?? 0);
                $type = $_GET['type'] ?? 'all';
                $unreadOnly = isset($_GET['unread']) && $_GET['unread'] == '1';
                
                $sql = "
                    SELECT n.*, u.surnom as actor_name, u.avatar as actor_avatar,
                        CASE 
                            WHEN n.type = 'like' THEN 'fa-heart'
                            WHEN n.type = 'comment' THEN 'fa-comment'
                            WHEN n.type = 'friend_request' THEN 'fa-user-plus'
                            WHEN n.type = 'friend_accept' THEN 'fa-check-circle'
                            WHEN n.type = 'message' THEN 'fa-envelope'
                            WHEN n.type = 'mention' THEN 'fa-at'
                            WHEN n.type = 'post' THEN 'fa-newspaper'
                            WHEN n.type = 'announcement' THEN 'fa-bullhorn'
                            WHEN n.type = 'share' THEN 'fa-share-alt'
                            WHEN n.type = 'community_post' THEN 'fa-users'
                            ELSE 'fa-info-circle'
                        END as icon_class,
                        CASE 
                            WHEN n.type = 'like' THEN 'text-red-500'
                            WHEN n.type = 'comment' THEN 'text-blue-500'
                            WHEN n.type = 'friend_request' THEN 'text-green-500'
                            WHEN n.type = 'friend_accept' THEN 'text-green-500'
                            WHEN n.type = 'message' THEN 'text-purple-500'
                            WHEN n.type = 'mention' THEN 'text-orange-500'
                            WHEN n.type = 'post' THEN 'text-orange-500'
                            WHEN n.type = 'announcement' THEN 'text-indigo-500'
                            WHEN n.type = 'share' THEN 'text-teal-500'
                            WHEN n.type = 'community_post' THEN 'text-pink-500'
                            ELSE 'text-gray-500'
                        END as icon_color
                    FROM notifications n
                    LEFT JOIN utilisateurs u ON n.actor_id = u.id
                    WHERE n.user_id = ?
                ";
                $params = [$userId];
                
                if ($unreadOnly) {
                    $sql .= " AND n.is_read = 0";
                }
                if ($type != 'all') {
                    $sql .= " AND n.type = ?";
                    $params[] = $type;
                }
                
                $sql .= " ORDER BY n.created_at DESC LIMIT ? OFFSET ?";
                $params[] = $limit;
                $params[] = $offset;
                
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $notifications = $stmt->fetchAll();
                
                // Compter le total
                $countSql = "SELECT COUNT(*) FROM notifications WHERE user_id = ?";
                $countParams = [$userId];
                if ($unreadOnly) $countSql .= " AND is_read = 0";
                if ($type != 'all') $countSql .= " AND type = ?";
                
                $countStmt = $pdo->prepare($countSql);
                $countStmt->execute($countParams);
                $totalCount = $countStmt->fetchColumn();
                
                json_response([
                    'success' => true,
                    'notifications' => $notifications,
                    'total' => $totalCount,
                    'has_more' => count($notifications) === $limit
                ]);
                break;
                
            case 'count':
                // Compter les notifications non lues
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0
                ");
                $stmt->execute([$userId]);
                $unreadCount = $stmt->fetchColumn();
                
                // Compter par type
                $typeStmt = $pdo->prepare("
                    SELECT type, COUNT(*) as count 
                    FROM notifications 
                    WHERE user_id = ? AND is_read = 0 
                    GROUP BY type
                ");
                $typeStmt->execute([$userId]);
                $byType = $typeStmt->fetchAll();
                
                json_response([
                    'success' => true,
                    'unread_count' => $unreadCount,
                    'by_type' => $byType
                ]);
                break;
                
            default:
                json_response(['error' => 'Action inconnue'], STATUS_BAD_REQUEST);
        }
        break;
        
    case 'POST':
        $action = $input['action'] ?? '';
        
        switch ($action) {
            case 'mark_read':
                $notifId = isset($input['id']) ? intval($input['id']) : null;
                
                if ($notifId) {
                    // Marquer une notification spécifique
                    $stmt = $pdo->prepare("
                        UPDATE notifications SET is_read = 1, read_at = NOW() 
                        WHERE id = ? AND user_id = ?
                    ");
                    $stmt->execute([$notifId, $userId]);
                    $marked = $stmt->rowCount();
                } else {
                    // Marquer toutes comme lues
                    $stmt = $pdo->prepare("
                        UPDATE notifications SET is_read = 1, read_at = NOW() 
                        WHERE user_id = ? AND is_read = 0
                    ");
                    $stmt->execute([$userId]);
                    $marked = $stmt->rowCount();
                }
                
                // Compter les non lues restantes
                $countStmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
                $countStmt->execute([$userId]);
                
                json_response([
                    'success' => true,
                    'marked' => $marked,
                    'unread_count' => $countStmt->fetchColumn()
                ]);
                break;
                
            case 'mark_unread':
                $notifId = intval($input['id'] ?? 0);
                
                $stmt = $pdo->prepare("
                    UPDATE notifications SET is_read = 0, read_at = NULL 
                    WHERE id = ? AND user_id = ?
                ");
                $stmt->execute([$notifId, $userId]);
                
                json_response(['success' => true, 'marked' => $stmt->rowCount()]);
                break;
                
            case 'delete_all':
                // Supprimer toutes les notifications
                $stmt = $pdo->prepare("DELETE FROM notifications WHERE user_id = ?");
                $stmt->execute([$userId]);
                
                json_response([
                    'success' => true,
                    'deleted' => $stmt->rowCount()
                ]);
                break;
                
            case 'delete_by_type':
                $type = $input['type'] ?? '';
                if (empty($type)) {
                    json_response(['error' => 'Type requis'], STATUS_BAD_REQUEST);
                }
                
                $stmt = $pdo->prepare("
                    DELETE FROM notifications WHERE user_id = ? AND type = ?
                ");
                $stmt->execute([$userId, $type]);
                
                json_response([
                    'success' => true,
                    'deleted' => $stmt->rowCount()
                ]);
                break;
                
            default:
                json_response(['error' => 'Action inconnue'], STATUS_BAD_REQUEST);
        }
        break;
        
    case 'DELETE':
        $notifId = intval($input['id'] ?? $_GET['id'] ?? 0);
        
        if (!$notifId) {
            json_response(['error' => 'ID requis'], STATUS_BAD_REQUEST);
        }
        
        $stmt = $pdo->prepare("
            DELETE FROM notifications WHERE id = ? AND user_id = ?
        ");
        $stmt->execute([$notifId, $userId]);
        
        if ($stmt->rowCount() === 0) {
            json_response(['error' => 'Notification non trouvée'], STATUS_NOT_FOUND);
        }
        
        json_response([
            'success' => true,
            'deleted' => $notifId
        ]);
        break;
        
    default:
        json_response(['error' => 'Méthode non supportée'], STATUS_METHOD_NOT_ALLOWED);
}