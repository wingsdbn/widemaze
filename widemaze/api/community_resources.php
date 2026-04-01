<?php
/**
 * WideMaze - Community Resources API
 * Version 1.0 - Gestion des ressources partagées dans les communautés
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
        $communityId = intval($_GET['community_id'] ?? 0);
        
        switch ($action) {
            case 'list':
                if (!$communityId) {
                    json_response(['error' => 'ID communauté requis'], STATUS_BAD_REQUEST);
                }
                
                $limit = min(intval($_GET['limit'] ?? 20), 50);
                $offset = intval($_GET['offset'] ?? 0);
                
                $stmt = $pdo->prepare("
                    SELECT r.*, u.surnom as uploader_name, u.avatar as uploader_avatar,
                        (SELECT COUNT(*) FROM community_resource_downloads WHERE resource_id = r.id) as download_count
                    FROM community_resources r
                    JOIN utilisateurs u ON r.uploaded_by = u.id
                    WHERE r.community_id = ?
                    ORDER BY r.created_at DESC
                    LIMIT ? OFFSET ?
                ");
                $stmt->execute([$communityId, $limit, $offset]);
                $resources = $stmt->fetchAll();
                
                // Compter le total
                $countStmt = $pdo->prepare("SELECT COUNT(*) FROM community_resources WHERE community_id = ?");
                $countStmt->execute([$communityId]);
                
                json_response([
                    'success' => true,
                    'resources' => $resources,
                    'total' => $countStmt->fetchColumn()
                ]);
                break;
                
            default:
                json_response(['error' => 'Action inconnue'], STATUS_BAD_REQUEST);
        }
        break;
        
    case 'POST':
        $action = $input['action'] ?? 'upload';
        
        switch ($action) {
            case 'upload':
                $communityId = intval($input['community_id'] ?? 0);
                
                if (!$communityId) {
                    json_response(['error' => 'ID communauté requis'], STATUS_BAD_REQUEST);
                }
                
                // Vérifier que l'utilisateur est membre
                $memberStmt = $pdo->prepare("SELECT id FROM communaute_membres WHERE id_communaute = ? AND id_utilisateur = ?");
                $memberStmt->execute([$communityId, $userId]);
                if (!$memberStmt->fetch()) {
                    json_response(['error' => 'Vous devez être membre pour partager des ressources'], STATUS_FORBIDDEN);
                }
                
                if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                    json_response(['error' => 'Aucun fichier fourni'], STATUS_BAD_REQUEST);
                }
                
                $title = trim($input['title'] ?? '');
                $description = trim($input['description'] ?? '');
                $file = $_FILES['file'];
                
                if (empty($title)) {
                    $title = pathinfo($file['name'], PATHINFO_FILENAME);
                }
                
                $allowedTypes = array_merge(ALLOWED_IMAGE_TYPES, ALLOWED_DOCUMENT_TYPES, ALLOWED_VIDEO_TYPES);
                $upload = handle_file_upload($file, DOCUMENTS_DIR, $allowedTypes, 50 * 1024 * 1024);
                
                if (!$upload['success']) {
                    json_response(['error' => $upload['error']], STATUS_BAD_REQUEST);
                }
                
                $fileType = 'document';
                if (in_array($upload['mime'], ALLOWED_IMAGE_TYPES)) $fileType = 'image';
                elseif (in_array($upload['mime'], ALLOWED_VIDEO_TYPES)) $fileType = 'video';
                elseif ($upload['mime'] === 'application/pdf') $fileType = 'pdf';
                
                $stmt = $pdo->prepare("
                    INSERT INTO community_resources (community_id, title, description, file_url, file_type, file_size, uploaded_by, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                $stmt->execute([
                    $communityId, $title, $description,
                    $upload['filename'], $fileType, $file['size'], $userId
                ]);
                $resourceId = $pdo->lastInsertId();
                
                log_activity($pdo, $userId, 'resource_uploaded', ['resource_id' => $resourceId, 'community_id' => $communityId]);
                
                json_response([
                    'success' => true,
                    'resource_id' => $resourceId,
                    'message' => 'Ressource partagée avec succès'
                ], STATUS_CREATED);
                break;
                
            case 'download':
                $resourceId = intval($input['resource_id'] ?? 0);
                
                if (!$resourceId) {
                    json_response(['error' => 'ID ressource requis'], STATUS_BAD_REQUEST);
                }
                
                $stmt = $pdo->prepare("
                    SELECT r.*, c.id_communaute 
                    FROM community_resources r
                    JOIN communautes c ON r.community_id = c.id_communaute
                    WHERE r.id = ?
                ");
                $stmt->execute([$resourceId]);
                $resource = $stmt->fetch();
                
                if (!$resource) {
                    json_response(['error' => 'Ressource non trouvée'], STATUS_NOT_FOUND);
                }
                
                // Vérifier que l'utilisateur est membre de la communauté
                $memberStmt = $pdo->prepare("SELECT id FROM communaute_membres WHERE id_communaute = ? AND id_utilisateur = ?");
                $memberStmt->execute([$resource['community_id'], $userId]);
                if (!$memberStmt->fetch()) {
                    json_response(['error' => 'Vous devez être membre pour télécharger'], STATUS_FORBIDDEN);
                }
                
                // Enregistrer le téléchargement
                $pdo->prepare("INSERT INTO community_resource_downloads (resource_id, user_id, downloaded_at) VALUES (?, ?, NOW())")->execute([$resourceId, $userId]);
                
                // Incrémenter le compteur
                $pdo->prepare("UPDATE community_resources SET downloads = downloads + 1 WHERE id = ?")->execute([$resourceId]);
                
                $filePath = DOCUMENTS_DIR . $resource['file_url'];
                if (!file_exists($filePath)) {
                    json_response(['error' => 'Fichier non trouvé'], STATUS_NOT_FOUND);
                }
                
                // Forcer le téléchargement
                header('Content-Type: application/octet-stream');
                header('Content-Disposition: attachment; filename="' . $resource['title'] . '.' . pathinfo($resource['file_url'], PATHINFO_EXTENSION) . '"');
                header('Content-Length: ' . filesize($filePath));
                readfile($filePath);
                exit();
                break;
                
            default:
                json_response(['error' => 'Action inconnue'], STATUS_BAD_REQUEST);
        }
        break;
        
    case 'DELETE':
        $resourceId = intval($input['resource_id'] ?? $_GET['resource_id'] ?? 0);
        
        if (!$resourceId) {
            json_response(['error' => 'ID ressource requis'], STATUS_BAD_REQUEST);
        }
        
        $stmt = $pdo->prepare("
            SELECT r.*, cm.role 
            FROM community_resources r
            JOIN communaute_membres cm ON r.community_id = cm.id_communaute AND cm.id_utilisateur = ?
            WHERE r.id = ?
        ");
        $stmt->execute([$userId, $resourceId]);
        $resource = $stmt->fetch();
        
        if (!$resource) {
            json_response(['error' => 'Ressource non trouvée ou accès refusé'], STATUS_NOT_FOUND);
        }
        
        if ($resource['uploaded_by'] != $userId && !in_array($resource['role'], ['admin', 'moderator'])) {
            json_response(['error' => 'Permission refusée'], STATUS_FORBIDDEN);
        }
        
        // Supprimer le fichier
        $filePath = DOCUMENTS_DIR . $resource['file_url'];
        if (file_exists($filePath)) {
            unlink($filePath);
        }
        
        // Supprimer les logs de téléchargement
        $pdo->prepare("DELETE FROM community_resource_downloads WHERE resource_id = ?")->execute([$resourceId]);
        
        // Supprimer la ressource
        $pdo->prepare("DELETE FROM community_resources WHERE id = ?")->execute([$resourceId]);
        
        log_activity($pdo, $userId, 'resource_deleted', ['resource_id' => $resourceId]);
        
        json_response(['success' => true, 'message' => 'Ressource supprimée']);
        break;
        
    default:
        json_response(['error' => 'Méthode non supportée'], STATUS_METHOD_NOT_ALLOWED);
}