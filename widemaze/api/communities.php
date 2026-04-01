<?php
/**
 * WideMaze - Communities API
 * Version 4.0 - Gestion complète des communautés
 * Méthodes: GET (list, detail, members), POST (create, join, leave, update), DELETE
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

// Vérification authentification
if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['error' => 'Non authentifié']);
    exit();
}

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$userId = $_SESSION['user_id'];

// Vérification CSRF pour les actions de modification
if (in_array($method, ['POST', 'PUT', 'DELETE'])) {
    $csrfToken = $input['csrf_token'] ?? $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($csrfToken)) {
        http_response_code(403);
        echo json_encode(['error' => 'Token CSRF invalide']);
        exit();
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
                $category = $_GET['category'] ?? 'all';
                $search = trim($_GET['search'] ?? '');
                $myOnly = isset($_GET['my']) && $_GET['my'] == '1';
                
                $sql = "
                    SELECT c.*, u.surnom as creator_name,
                        (SELECT COUNT(*) FROM communaute_membres WHERE id_communaute = c.id_communaute) as member_count,
                        (SELECT COUNT(*) FROM posts WHERE id_communaute = c.id_communaute) as posts_count,
                        (SELECT EXISTS(SELECT 1 FROM communaute_membres WHERE id_communaute = c.id_communaute AND id_utilisateur = ?)) as is_member
                    FROM communautes c
                    JOIN utilisateurs u ON c.id_createur = u.id
                    WHERE c.is_active = 1
                ";
                $params = [$userId];
                
                if ($myOnly) {
                    $sql .= " AND EXISTS(SELECT 1 FROM communaute_membres WHERE id_communaute = c.id_communaute AND id_utilisateur = ?)";
                    $params[] = $userId;
                }
                if ($category != 'all') {
                    $sql .= " AND c.categorie = ?";
                    $params[] = $category;
                }
                if (!empty($search)) {
                    $sql .= " AND (c.nom LIKE ? OR c.description LIKE ?)";
                    $params[] = "%$search%";
                    $params[] = "%$search%";
                }
                
                $sql .= " ORDER BY member_count DESC LIMIT ? OFFSET ?";
                $params[] = $limit;
                $params[] = $offset;
                
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $communities = $stmt->fetchAll();
                
                // Compter le total
                $countSql = "SELECT COUNT(*) FROM communautes c WHERE c.is_active = 1";
                $countParams = [];
                if ($myOnly) {
                    $countSql .= " AND EXISTS(SELECT 1 FROM communaute_membres WHERE id_communaute = c.id_communaute AND id_utilisateur = ?)";
                    $countParams[] = $userId;
                }
                if ($category != 'all') {
                    $countSql .= " AND c.categorie = ?";
                    $countParams[] = $category;
                }
                if (!empty($search)) {
                    $countSql .= " AND (c.nom LIKE ? OR c.description LIKE ?)";
                    $countParams[] = "%$search%";
                    $countParams[] = "%$search%";
                }
                
                $countStmt = $pdo->prepare($countSql);
                $countStmt->execute($countParams);
                $totalCount = $countStmt->fetchColumn();
                
                echo json_encode([
                    'success' => true,
                    'communities' => $communities,
                    'total' => $totalCount,
                    'has_more' => count($communities) === $limit
                ]);
                break;
                
            case 'detail':
                $communityId = intval($_GET['id'] ?? 0);
                if (!$communityId) {
                    http_response_code(400);
                    echo json_encode(['error' => 'ID de communauté requis']);
                    exit();
                }
                
                $stmt = $pdo->prepare("
                    SELECT c.*, u.surnom as creator_name, u.avatar as creator_avatar,
                        (SELECT COUNT(*) FROM communaute_membres WHERE id_communaute = c.id_communaute) as member_count,
                        (SELECT COUNT(*) FROM posts WHERE id_communaute = c.id_communaute) as posts_count,
                        (SELECT COUNT(*) FROM posts WHERE id_communaute = c.id_communaute AND date_publication > DATE_SUB(NOW(), INTERVAL 7 DAY)) as posts_week,
                        (SELECT EXISTS(SELECT 1 FROM communaute_membres WHERE id_communaute = c.id_communaute AND id_utilisateur = ?)) as is_member,
                        (SELECT role FROM communaute_membres WHERE id_communaute = c.id_communaute AND id_utilisateur = ?) as member_role
                    FROM communautes c
                    JOIN utilisateurs u ON c.id_createur = u.id
                    WHERE c.id_communaute = ? AND c.is_active = 1
                ");
                $stmt->execute([$userId, $userId, $communityId]);
                $community = $stmt->fetch();
                
                if (!$community) {
                    http_response_code(404);
                    echo json_encode(['error' => 'Communauté non trouvée']);
                    exit();
                }
                
                echo json_encode(['success' => true, 'community' => $community]);
                break;
                
            case 'members':
                $communityId = intval($_GET['id'] ?? 0);
                $limit = min(intval($_GET['limit'] ?? 20), 100);
                $offset = intval($_GET['offset'] ?? 0);
                
                if (!$communityId) {
                    http_response_code(400);
                    echo json_encode(['error' => 'ID de communauté requis']);
                    exit();
                }
                
                $stmt = $pdo->prepare("
                    SELECT u.id, u.surnom, u.prenom, u.nom, u.avatar, u.status, cm.role, cm.created_at as joined_at
                    FROM communaute_membres cm
                    JOIN utilisateurs u ON cm.id_utilisateur = u.id
                    WHERE cm.id_communaute = ?
                    ORDER BY cm.role = 'admin' DESC, cm.role = 'moderator' DESC, cm.created_at ASC
                    LIMIT ? OFFSET ?
                ");
                $stmt->execute([$communityId, $limit, $offset]);
                $members = $stmt->fetchAll();
                
                // Compter le total
                $countStmt = $pdo->prepare("SELECT COUNT(*) FROM communaute_membres WHERE id_communaute = ?");
                $countStmt->execute([$communityId]);
                $totalCount = $countStmt->fetchColumn();
                
                echo json_encode([
                    'success' => true,
                    'members' => $members,
                    'total' => $totalCount,
                    'has_more' => count($members) === $limit
                ]);
                break;
                
            default:
                http_response_code(400);
                echo json_encode(['error' => 'Action inconnue']);
                exit();
        }
        break;
        
    case 'POST':
        $action = $input['action'] ?? '';
        
        switch ($action) {
            case 'create_community':
                // Créer une communauté
                $nom = trim($input['nom'] ?? '');
                $description = trim($input['description'] ?? '');
                $categorie = $input['categorie'] ?? 'Academic';
                
                $validCategories = ['Academic', 'Club', 'Social', 'Sports', 'Arts', 'Tech', 'Career'];
                if (!in_array($categorie, $validCategories)) {
                    $categorie = 'Academic';
                }
                
                if (empty($nom)) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Nom de communauté requis']);
                    exit();
                }
                if (strlen($nom) > 100) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Nom trop long (max 100 caractères)']);
                    exit();
                }
                if (strlen($description) > 500) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Description trop longue (max 500 caractères)']);
                    exit();
                }
                
                // Vérifier si le nom existe déjà
                $checkStmt = $pdo->prepare("SELECT id_communaute FROM communautes WHERE nom = ?");
                $checkStmt->execute([$nom]);
                if ($checkStmt->fetch()) {
                    http_response_code(409);
                    echo json_encode(['error' => 'Une communauté avec ce nom existe déjà']);
                    exit();
                }
                
                // Gérer l'image de couverture
                $coverImage = null;
                if (isset($_FILES['cover']) && $_FILES['cover']['error'] === UPLOAD_ERR_OK) {
                    $upload = handle_file_upload($_FILES['cover'], COVERS_DIR, ALLOWED_IMAGE_TYPES, 5 * 1024 * 1024);
                    if ($upload['success']) {
                        $coverImage = $upload['filename'];
                    }
                }
                
                $stmt = $pdo->prepare("
                    INSERT INTO communautes (nom, description, categorie, image_couverture, id_createur, date_creation)
                    VALUES (?, ?, ?, ?, ?, NOW())
                ");
                $stmt->execute([$nom, $description, $categorie, $coverImage, $userId]);
                $communityId = $pdo->lastInsertId();
                
                // Ajouter le créateur comme membre admin
                $stmt = $pdo->prepare("
                    INSERT INTO communaute_membres (id_communaute, id_utilisateur, role, created_at)
                    VALUES (?, ?, 'admin', NOW())
                ");
                $stmt->execute([$communityId, $userId]);
                
                log_activity($pdo, $userId, 'community_created', ['community_id' => $communityId, 'name' => $nom]);
                
                http_response_code(201);
                echo json_encode([
                    'success' => true,
                    'community_id' => $communityId,
                    'message' => 'Communauté créée avec succès'
                ]);
                break;
                
            case 'join':
                $communityId = intval($input['community_id'] ?? 0);
                if (!$communityId) {
                    http_response_code(400);
                    echo json_encode(['error' => 'ID de communauté requis']);
                    exit();
                }
                
                // Vérifier si déjà membre
                $checkStmt = $pdo->prepare("SELECT * FROM communaute_membres WHERE id_communaute = ? AND id_utilisateur = ?");
                $checkStmt->execute([$communityId, $userId]);
                if ($checkStmt->fetch()) {
                    http_response_code(409);
                    echo json_encode(['error' => 'Vous êtes déjà membre de cette communauté']);
                    exit();
                }
                
                // Ajouter le membre
                $stmt = $pdo->prepare("
                    INSERT INTO communaute_membres (id_communaute, id_utilisateur, role, created_at)
                    VALUES (?, ?, 'member', NOW())
                ");
                $stmt->execute([$communityId, $userId]);
                
                // Notifier le créateur de la communauté
                $creatorStmt = $pdo->prepare("SELECT id_createur FROM communautes WHERE id_communaute = ?");
                $creatorStmt->execute([$communityId]);
                $creatorId = $creatorStmt->fetchColumn();
                
                if ($creatorId != $userId) {
                    create_notification(
                        $pdo,
                        $creatorId,
                        'community_join',
                        '@' . $_SESSION['surnom'] . ' a rejoint votre communauté',
                        $userId,
                        SITE_URL . '/pages/communaute.php?id=' . $communityId
                    );
                }
                
                log_activity($pdo, $userId, 'community_joined', ['community_id' => $communityId]);
                echo json_encode(['success' => true, 'message' => 'Vous avez rejoint la communauté']);
                break;
                
            case 'leave':
                $communityId = intval($input['community_id'] ?? 0);
                if (!$communityId) {
                    http_response_code(400);
                    echo json_encode(['error' => 'ID de communauté requis']);
                    exit();
                }
                
                // Vérifier si c'est le créateur
                $creatorStmt = $pdo->prepare("SELECT id_createur FROM communautes WHERE id_communaute = ?");
                $creatorStmt->execute([$communityId]);
                $creatorId = $creatorStmt->fetchColumn();
                
                if ($creatorId == $userId) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Le créateur ne peut pas quitter sa communauté']);
                    exit();
                }
                
                // Supprimer le membre
                $stmt = $pdo->prepare("DELETE FROM communaute_membres WHERE id_communaute = ? AND id_utilisateur = ?");
                $stmt->execute([$communityId, $userId]);
                
                log_activity($pdo, $userId, 'community_left', ['community_id' => $communityId]);
                echo json_encode(['success' => true, 'message' => 'Vous avez quitté la communauté']);
                break;
                
            case 'update_cover':
                $communityId = intval($input['community_id'] ?? 0);
                if (!$communityId) {
                    http_response_code(400);
                    echo json_encode(['error' => 'ID de communauté requis']);
                    exit();
                }
                
                // Vérifier les droits
                $checkStmt = $pdo->prepare("SELECT id_createur FROM communautes WHERE id_communaute = ?");
                $checkStmt->execute([$communityId]);
                $creatorId = $checkStmt->fetchColumn();
                
                if ($creatorId != $userId && !is_admin()) {
                    http_response_code(403);
                    echo json_encode(['error' => 'Permission refusée']);
                    exit();
                }
                
                if (!isset($_FILES['cover']) || $_FILES['cover']['error'] !== UPLOAD_ERR_OK) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Aucun fichier fourni']);
                    exit();
                }
                
                $upload = handle_file_upload($_FILES['cover'], COVERS_DIR, ALLOWED_IMAGE_TYPES, 5 * 1024 * 1024);
                if (!$upload['success']) {
                    http_response_code(400);
                    echo json_encode(['error' => $upload['error']]);
                    exit();
                }
                
                // Supprimer l'ancienne couverture
                $oldStmt = $pdo->prepare("SELECT image_couverture FROM communautes WHERE id_communaute = ?");
                $oldStmt->execute([$communityId]);
                $oldCover = $oldStmt->fetchColumn();
                
                if ($oldCover && file_exists(COVERS_DIR . $oldCover)) {
                    unlink(COVERS_DIR . $oldCover);
                }
                
                $stmt = $pdo->prepare("UPDATE communautes SET image_couverture = ? WHERE id_communaute = ?");
                $stmt->execute([$upload['filename'], $communityId]);
                
                echo json_encode([
                    'success' => true,
                    'cover_url' => COVERS_URL . $upload['filename'],
                    'message' => 'Photo de couverture mise à jour'
                ]);
                break;
                
            case 'update_description':
                $communityId = intval($input['community_id'] ?? 0);
                $description = trim($input['description'] ?? '');
                
                if (!$communityId) {
                    http_response_code(400);
                    echo json_encode(['error' => 'ID de communauté requis']);
                    exit();
                }
                
                // Vérifier les droits
                $checkStmt = $pdo->prepare("SELECT id_createur FROM communautes WHERE id_communaute = ?");
                $checkStmt->execute([$communityId]);
                $creatorId = $checkStmt->fetchColumn();
                
                $roleStmt = $pdo->prepare("SELECT role FROM communaute_membres WHERE id_communaute = ? AND id_utilisateur = ?");
                $roleStmt->execute([$communityId, $userId]);
                $role = $roleStmt->fetchColumn();
                
                if ($creatorId != $userId && $role != 'moderator' && !is_admin()) {
                    http_response_code(403);
                    echo json_encode(['error' => 'Permission refusée']);
                    exit();
                }
                
                $stmt = $pdo->prepare("UPDATE communautes SET description = ? WHERE id_communaute = ?");
                $stmt->execute([$description, $communityId]);
                
                log_activity($pdo, $userId, 'community_updated', ['community_id' => $communityId, 'field' => 'description']);
                echo json_encode(['success' => true, 'message' => 'Description mise à jour']);
                break;
                
            case 'update_category':
                $communityId = intval($input['community_id'] ?? 0);
                $category = $input['category'] ?? 'Academic';
                
                $validCategories = ['Academic', 'Club', 'Social', 'Sports', 'Arts', 'Tech', 'Career'];
                if (!in_array($category, $validCategories)) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Catégorie invalide']);
                    exit();
                }
                
                // Vérifier les droits
                $checkStmt = $pdo->prepare("SELECT id_createur FROM communautes WHERE id_communaute = ?");
                $checkStmt->execute([$communityId]);
                $creatorId = $checkStmt->fetchColumn();
                
                if ($creatorId != $userId && !is_admin()) {
                    http_response_code(403);
                    echo json_encode(['error' => 'Permission refusée']);
                    exit();
                }
                
                $stmt = $pdo->prepare("UPDATE communautes SET categorie = ? WHERE id_communaute = ?");
                $stmt->execute([$category, $communityId]);
                
                echo json_encode(['success' => true, 'message' => 'Catégorie mise à jour']);
                break;
                
            default:
                http_response_code(400);
                echo json_encode(['error' => 'Action inconnue']);
                exit();
        }
        break;
        
    case 'DELETE':
        $communityId = intval($input['community_id'] ?? $_GET['id'] ?? 0);
        
        if (!$communityId) {
            http_response_code(400);
            echo json_encode(['error' => 'ID de communauté requis']);
            exit();
        }
        
        // Vérifier les droits
        $checkStmt = $pdo->prepare("SELECT id_createur FROM communautes WHERE id_communaute = ?");
        $checkStmt->execute([$communityId]);
        $creatorId = $checkStmt->fetchColumn();
        
        if ($creatorId != $userId && !is_admin()) {
            http_response_code(403);
            echo json_encode(['error' => 'Permission refusée']);
            exit();
        }
        
        // Supprimer les données associées
        $pdo->beginTransaction();
        
        // Récupérer la couverture pour suppression
        $coverStmt = $pdo->prepare("SELECT image_couverture FROM communautes WHERE id_communaute = ?");
        $coverStmt->execute([$communityId]);
        $cover = $coverStmt->fetchColumn();
        
        if ($cover && file_exists(COVERS_DIR . $cover)) {
            unlink(COVERS_DIR . $cover);
        }
        
        // Supprimer les membres
        $pdo->prepare("DELETE FROM communaute_membres WHERE id_communaute = ?")->execute([$communityId]);
        
        // Supprimer les posts de la communauté
        $postsStmt = $pdo->prepare("SELECT image_post FROM posts WHERE id_communaute = ?");
        $postsStmt->execute([$communityId]);
        $posts = $postsStmt->fetchAll();
        foreach ($posts as $post) {
            if ($post['image_post'] && file_exists(POSTS_DIR . $post['image_post'])) {
                unlink(POSTS_DIR . $post['image_post']);
            }
        }
        $pdo->prepare("DELETE FROM posts WHERE id_communaute = ?")->execute([$communityId]);
        
        // Supprimer la communauté
        $pdo->prepare("DELETE FROM communautes WHERE id_communaute = ?")->execute([$communityId]);
        
        $pdo->commit();
        
        log_activity($pdo, $userId, 'community_deleted', ['community_id' => $communityId]);
        echo json_encode(['success' => true, 'message' => 'Communauté supprimée']);
        break;
        
    default:
        http_response_code(405);
        echo json_encode(['error' => 'Méthode non supportée']);
        exit();
}