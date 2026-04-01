<?php
/**
 * WideMaze - Stories API
 * Version 1.0 - Gestion des stories (24h)
 * Méthodes: GET (list), POST (create), DELETE
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

if (!is_logged_in()) {
    json_response(['error' => 'Non authentifié'], STATUS_UNAUTHORIZED);
}

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$userId = $_SESSION['user_id'];

if (in_array($method, ['POST', 'DELETE'])) {
    if (!verify_csrf_token($input['csrf_token'] ?? $_POST['csrf_token'] ?? '')) {
        json_response(['error' => 'Token CSRF invalide'], STATUS_FORBIDDEN);
    }
}

switch ($method) {
    case 'GET':
        $action = $_GET['action'] ?? 'list';
        
        switch ($action) {
            case 'list':
                // Récupérer les stories des amis et de l'utilisateur
                $limit = min(intval($_GET['limit'] ?? 20), 50);
                
                $sql = "
                    SELECT s.*, u.surnom, u.avatar,
                        EXISTS(SELECT 1 FROM story_views WHERE story_id = s.id AND user_id = ?) as has_viewed,
                        CASE 
                            WHEN s.user_id = ? THEN 'own'
                            WHEN EXISTS(SELECT 1 FROM ami WHERE (id = s.user_id AND idami = ?) OR (idami = s.user_id AND id = ?) AND accepterami = 1) 
                                 THEN 'friend'
                            ELSE 'other'
                        END as relationship
                    FROM stories s
                    JOIN utilisateurs u ON s.user_id = u.id
                    WHERE s.expires_at > NOW()
                        AND (s.user_id = ? OR s.user_id IN (
                            SELECT CASE WHEN id = ? THEN idami ELSE id END
                            FROM ami WHERE (id = ? OR idami = ?) AND accepterami = 1
                        ))
                    ORDER BY s.created_at DESC
                    LIMIT ?
                ";
                
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$userId, $userId, $userId, $userId, $userId, $userId, $userId, $userId, $limit]);
                $stories = $stmt->fetchAll();
                
                json_response(['success' => true, 'stories' => $stories]);
                break;
                
            case 'view':
                $storyId = intval($_GET['story_id'] ?? 0);
                if (!$storyId) {
                    json_response(['error' => 'ID story requis'], STATUS_BAD_REQUEST);
                }
                
                // Enregistrer la vue
                try {
                    $stmt = $pdo->prepare("
                        INSERT IGNORE INTO story_views (story_id, user_id, viewed_at)
                        VALUES (?, ?, NOW())
                    ");
                    $stmt->execute([$storyId, $userId]);
                    
                    json_response(['success' => true]);
                } catch (PDOException $e) {
                    error_log("Error recording story view: " . $e->getMessage());
                    json_response(['error' => 'Erreur lors de l\'enregistrement'], STATUS_SERVER_ERROR);
                }
                break;
                
            default:
                json_response(['error' => 'Action inconnue'], STATUS_BAD_REQUEST);
        }
        break;
        
    case 'POST':
        $action = $input['action'] ?? 'create';
        
        switch ($action) {
            case 'create':
                if (!isset($_FILES['media']) || $_FILES['media']['error'] !== UPLOAD_ERR_OK) {
                    json_response(['error' => 'Aucun fichier fourni'], STATUS_BAD_REQUEST);
                }
                
                $file = $_FILES['media'];
                $allowedTypes = array_merge(ALLOWED_IMAGE_TYPES, ALLOWED_VIDEO_TYPES);
                $upload = handle_file_upload($file, STORIES_DIR ?? UPLOAD_DIR . 'stories/', $allowedTypes, 20 * 1024 * 1024);
                
                if (!$upload['success']) {
                    json_response(['error' => $upload['error']], STATUS_BAD_REQUEST);
                }
                
                $type = in_array($upload['mime'], ALLOWED_IMAGE_TYPES) ? 'image' : 'video';
                $expiresAt = date('Y-m-d H:i:s', strtotime('+24 hours'));
                
                $stmt = $pdo->prepare("
                    INSERT INTO stories (user_id, media_url, type, created_at, expires_at)
                    VALUES (?, ?, ?, NOW(), ?)
                ");
                $stmt->execute([$userId, $upload['filename'], $type, $expiresAt]);
                $storyId = $pdo->lastInsertId();
                
                log_activity($pdo, $userId, 'story_created', ['story_id' => $storyId]);
                
                json_response([
                    'success' => true,
                    'story_id' => $storyId,
                    'expires_at' => $expiresAt
                ], STATUS_CREATED);
                break;
                
            default:
                json_response(['error' => 'Action inconnue'], STATUS_BAD_REQUEST);
        }
        break;
        
    case 'DELETE':
        $storyId = intval($input['story_id'] ?? $_GET['story_id'] ?? 0);
        
        if (!$storyId) {
            json_response(['error' => 'ID story requis'], STATUS_BAD_REQUEST);
        }
        
        // Vérifier que l'utilisateur est le propriétaire
        $stmt = $pdo->prepare("SELECT user_id, media_url FROM stories WHERE id = ?");
        $stmt->execute([$storyId]);
        $story = $stmt->fetch();
        
        if (!$story) {
            json_response(['error' => 'Story non trouvée'], STATUS_NOT_FOUND);
        }
        
        if ($story['user_id'] != $userId && !is_admin()) {
            json_response(['error' => 'Permission refusée'], STATUS_FORBIDDEN);
        }
        
        // Supprimer le fichier
        if (file_exists(STORIES_DIR . $story['media_url'])) {
            unlink(STORIES_DIR . $story['media_url']);
        }
        
        // Supprimer les vues
        $pdo->prepare("DELETE FROM story_views WHERE story_id = ?")->execute([$storyId]);
        
        // Supprimer la story
        $pdo->prepare("DELETE FROM stories WHERE id = ?")->execute([$storyId]);
        
        log_activity($pdo, $userId, 'story_deleted', ['story_id' => $storyId]);
        
        json_response(['success' => true, 'message' => 'Story supprimée']);
        break;
        
    default:
        json_response(['error' => 'Méthode non supportée'], STATUS_METHOD_NOT_ALLOWED);
}