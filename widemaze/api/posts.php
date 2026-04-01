<?php
/**
 * WideMaze - Posts API
 * Version 4.0 - CRUD complet avec notifications, partages, signalements
 * Méthodes: GET (feed, user, single), POST (create, like, comment, share), PUT, DELETE
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

// Vérification authentification pour toutes les requêtes
require_auth();

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$userId = $_SESSION['user_id'];

// Vérification CSRF pour les actions d'écriture
if (in_array($method, ['POST', 'PUT', 'DELETE'])) {
    $csrfToken = $input['csrf_token'] ?? $_GET['csrf_token'] ?? '';
    if (!verify_csrf_token($csrfToken)) {
        json_response(['error' => 'Token CSRF invalide'], STATUS_FORBIDDEN);
    }
}

// Rate limiting
check_rate_limit('posts_api');

// ==================== ROUTAGE ====================

switch ($method) {
    case 'GET':
        $action = $_GET['action'] ?? 'feed';
        
        switch ($action) {
            case 'feed':
                $limit = min(intval($_GET['limit'] ?? POSTS_PER_PAGE), 50);
                $offset = intval($_GET['offset'] ?? 0);
                $filter = $_GET['filter'] ?? 'all'; // all, friends, photos, communities
                
                try {
                    $sql = "
                        SELECT p.*, u.surnom, u.avatar, u.prenom, u.nom,
                            (SELECT COUNT(*) FROM postlike WHERE idpost = p.idpost) as likes_count,
                            (SELECT COUNT(*) FROM postcommentaire WHERE idpost = p.idpost) as comments_count,
                            (SELECT EXISTS(SELECT 1 FROM postlike WHERE idpost = p.idpost AND id = :user_id)) as user_liked,
                            (SELECT COUNT(*) FROM postlike WHERE idpost = p.idpost) as reaction_count,
                            CASE 
                                WHEN p.id_communaute IS NOT NULL THEN 'community'
                                ELSE 'personal'
                            END as post_type
                        FROM posts p
                        JOIN utilisateurs u ON p.id_utilisateur = u.id
                        WHERE 1=1
                    ";
                    $params = ['user_id' => $userId];
                    
                    // Appliquer les filtres
                    if ($filter == 'friends') {
                        $sql .= " AND (p.id_utilisateur IN (
                            SELECT CASE WHEN id = :user_id2 THEN idami ELSE id END
                            FROM ami WHERE (id = :user_id3 OR idami = :user_id4) AND accepterami = 1
                        ) OR p.id_utilisateur = :user_id5)";
                        $params[':user_id2'] = $userId;
                        $params[':user_id3'] = $userId;
                        $params[':user_id4'] = $userId;
                        $params[':user_id5'] = $userId;
                    } elseif ($filter == 'photos') {
                        $sql .= " AND p.image_post IS NOT NULL";
                    } elseif ($filter == 'communities') {
                        $sql .= " AND p.id_communaute IS NOT NULL";
                    }
                    
                    // Filtrer par visibilité
                    $sql .= " AND (p.privacy = 'public' OR p.id_utilisateur = :user_id6 OR p.id_utilisateur IN (
                        SELECT CASE WHEN id = :user_id7 THEN idami ELSE id END
                        FROM ami WHERE (id = :user_id8 OR idami = :user_id9) AND accepterami = 1
                    ))";
                    $params[':user_id6'] = $userId;
                    $params[':user_id7'] = $userId;
                    $params[':user_id8'] = $userId;
                    $params[':user_id9'] = $userId;
                    
                    $sql .= " ORDER BY p.date_publication DESC LIMIT :limit OFFSET :offset";
                    $params[':limit'] = $limit;
                    $params[':offset'] = $offset;
                    
                    $stmt = $pdo->prepare($sql);
                    foreach ($params as $key => $value) {
                        if (is_int($value)) {
                            $stmt->bindValue($key, $value, PDO::PARAM_INT);
                        } else {
                            $stmt->bindValue($key, $value);
                        }
                    }
                    $stmt->execute();
                    $posts = $stmt->fetchAll();
                    
                    json_response([
                        'success' => true,
                        'posts' => $posts,
                        'has_more' => count($posts) === $limit
                    ]);
                } catch (PDOException $e) {
                    error_log("Feed error: " . $e->getMessage());
                    json_response(['error' => 'Erreur lors du chargement du fil'], STATUS_SERVER_ERROR);
                }
                break;
                
            case 'user':
                $targetId = intval($_GET['user_id'] ?? $userId);
                $limit = min(intval($_GET['limit'] ?? POSTS_PER_PAGE), 50);
                $offset = intval($_GET['offset'] ?? 0);
                
                try {
                    $stmt = $pdo->prepare("
                        SELECT p.*, u.surnom, u.avatar,
                            (SELECT COUNT(*) FROM postlike WHERE idpost = p.idpost) as likes_count,
                            (SELECT COUNT(*) FROM postcommentaire WHERE idpost = p.idpost) as comments_count,
                            (SELECT EXISTS(SELECT 1 FROM postlike WHERE idpost = p.idpost AND id = ?)) as user_liked
                        FROM posts p
                        JOIN utilisateurs u ON p.id_utilisateur = u.id
                        WHERE p.id_utilisateur = ?
                        ORDER BY p.date_publication DESC
                        LIMIT ? OFFSET ?
                    ");
                    $stmt->execute([$userId, $targetId, $limit, $offset]);
                    $posts = $stmt->fetchAll();
                    json_response(['success' => true, 'posts' => $posts]);
                } catch (PDOException $e) {
                    error_log("User posts error: " . $e->getMessage());
                    json_response(['error' => 'Erreur lors du chargement'], STATUS_SERVER_ERROR);
                }
                break;
                
            case 'single':
                $postId = intval($_GET['id'] ?? 0);
                if (!$postId) {
                    json_response(['error' => 'ID de post requis'], STATUS_BAD_REQUEST);
                }
                
                try {
                    $stmt = $pdo->prepare("
                        SELECT p.*, u.surnom, u.avatar, u.prenom, u.nom,
                            (SELECT COUNT(*) FROM postlike WHERE idpost = p.idpost) as likes_count,
                            (SELECT EXISTS(SELECT 1 FROM postlike WHERE idpost = p.idpost AND id = ?)) as user_liked,
                            (SELECT COUNT(*) FROM postcommentaire WHERE idpost = p.idpost) as comments_count
                        FROM posts p
                        JOIN utilisateurs u ON p.id_utilisateur = u.id
                        WHERE p.idpost = ?
                    ");
                    $stmt->execute([$userId, $postId]);
                    $post = $stmt->fetch();
                    
                    if (!$post) {
                        json_response(['error' => 'Post non trouvé'], STATUS_NOT_FOUND);
                    }
                    
                    // Vérifier la visibilité
                    if ($post['privacy'] != 'public' && $post['id_utilisateur'] != $userId) {
                        $checkStmt = $pdo->prepare("
                            SELECT accepterami FROM ami 
                            WHERE (id = ? AND idami = ?) OR (id = ? AND idami = ?)
                        ");
                        $checkStmt->execute([$userId, $post['id_utilisateur'], $post['id_utilisateur'], $userId]);
                        $isFriend = $checkStmt->fetch();
                        
                        if ($post['privacy'] == 'friends' && !$isFriend) {
                            json_response(['error' => 'Accès non autorisé'], STATUS_FORBIDDEN);
                        } elseif ($post['privacy'] == 'private') {
                            json_response(['error' => 'Accès non autorisé'], STATUS_FORBIDDEN);
                        }
                    }
                    
                    // Récupérer les commentaires
                    $commentsStmt = $pdo->prepare("
                        SELECT c.*, u.surnom, u.avatar
                        FROM postcommentaire c
                        JOIN utilisateurs u ON c.id = u.id
                        WHERE c.idpost = ?
                        ORDER BY c.datecommentaire DESC
                        LIMIT ?
                    ");
                    $commentsStmt->execute([$postId, COMMENTS_PER_PAGE]);
                    $comments = $commentsStmt->fetchAll();
                    
                    json_response([
                        'success' => true,
                        'post' => $post,
                        'comments' => $comments
                    ]);
                } catch (PDOException $e) {
                    error_log("Single post error: " . $e->getMessage());
                    json_response(['error' => 'Erreur lors du chargement'], STATUS_SERVER_ERROR);
                }
                break;
                
            default:
                json_response(['error' => 'Action non reconnue'], STATUS_BAD_REQUEST);
        }
        break;
        
    case 'POST':
        $action = $input['action'] ?? 'create';
        
        switch ($action) {
            case 'create':
                $content = trim($input['content'] ?? '');
                $privacy = isset($input['privacy']) && in_array($input['privacy'], ['public', 'friends', 'private']) 
                    ? $input['privacy'] 
                    : 'public';
                $communityId = isset($input['community_id']) ? intval($input['community_id']) : null;
                
                if (empty($content) && empty($_FILES['image'])) {
                    json_response(['error' => 'Contenu requis'], STATUS_BAD_REQUEST);
                }
                
                $imagePath = null;
                $videoPath = null;
                
                // Gestion de l'upload d'image/vidéo
                if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
                    $allowedTypes = array_merge(ALLOWED_IMAGE_TYPES, ALLOWED_VIDEO_TYPES);
                    $upload = handle_file_upload($_FILES['image'], POSTS_DIR, $allowedTypes, MAX_FILE_SIZE);
                    
                    if (!$upload['success']) {
                        json_response(['error' => $upload['error']], STATUS_BAD_REQUEST);
                    }
                    
                    if (isset($upload['mime'])) {
                        if (in_array($upload['mime'], ALLOWED_IMAGE_TYPES)) {
                            $imagePath = $upload['filename'];
                        } elseif (in_array($upload['mime'], ALLOWED_VIDEO_TYPES)) {
                            $videoPath = $upload['filename'];
                        }
                    }
                }
                
                try {
                    $pdo->beginTransaction();
                    
                    $stmt = $pdo->prepare("
                        INSERT INTO posts (id_utilisateur, contenu, image_post, privacy, date_publication, id_communaute) 
                        VALUES (?, ?, ?, ?, NOW(), ?)
                    ");
                    $stmt->execute([$userId, $content, $imagePath ?: $videoPath, $privacy, $communityId]);
                    $postId = $pdo->lastInsertId();
                    
                    // Notifier les amis si le post est public ou amis
                    if ($privacy != 'private' && !$communityId) {
                        $friendsStmt = $pdo->prepare("
                            SELECT CASE WHEN id = ? THEN idami ELSE id END as friend_id
                            FROM ami WHERE (id = ? OR idami = ?) AND accepterami = 1
                        ");
                        $friendsStmt->execute([$userId, $userId, $userId]);
                        $friends = $friendsStmt->fetchAll();
                        
                        foreach ($friends as $friend) {
                            create_notification(
                                $pdo,
                                $friend['friend_id'],
                                NOTIF_POST,
                                'Nouvelle publication de @' . $_SESSION['surnom'],
                                $userId,
                                SITE_URL . '/index.php?post=' . $postId
                            );
                        }
                    } elseif ($communityId) {
                        // Notifier les membres de la communauté
                        $membersStmt = $pdo->prepare("
                            SELECT id_utilisateur FROM communaute_membres 
                            WHERE id_communaute = ? AND id_utilisateur != ?
                        ");
                        $membersStmt->execute([$communityId, $userId]);
                        $members = $membersStmt->fetchAll();
                        
                        foreach ($members as $member) {
                            create_notification(
                                $pdo,
                                $member['id_utilisateur'],
                                'community_post',
                                'Nouvelle publication dans une communauté que vous suivez',
                                $userId,
                                SITE_URL . '/pages/communaute.php?id=' . $communityId
                            );
                        }
                    }
                    
                    $pdo->commit();
                    log_activity($pdo, $userId, 'post_created', ['post_id' => $postId]);
                    
                    json_response([
                        'success' => true,
                        'post_id' => $postId,
                        'message' => 'Publication créée avec succès'
                    ], STATUS_CREATED);
                } catch (PDOException $e) {
                    $pdo->rollBack();
                    error_log("Post creation error: " . $e->getMessage());
                    json_response(['error' => 'Erreur lors de la création'], STATUS_SERVER_ERROR);
                }
                break;
                
            case 'like':
                $postId = intval($input['post_id'] ?? 0);
                if (!$postId) {
                    json_response(['error' => 'ID de post requis'], STATUS_BAD_REQUEST);
                }
                
                try {
                    // Vérifier si déjà liké
                    $checkStmt = $pdo->prepare("SELECT idlike FROM postlike WHERE idpost = ? AND id = ?");
                    $checkStmt->execute([$postId, $userId]);
                    $alreadyLiked = $checkStmt->fetch();
                    
                    if ($alreadyLiked) {
                        // Unlike
                        $stmt = $pdo->prepare("DELETE FROM postlike WHERE idpost = ? AND id = ?");
                        $stmt->execute([$postId, $userId]);
                        $liked = false;
                    } else {
                        // Like
                        $stmt = $pdo->prepare("INSERT INTO postlike (idpost, id) VALUES (?, ?)");
                        $stmt->execute([$postId, $userId]);
                        $liked = true;
                        
                        // Notifier l'auteur du post
                        $authorStmt = $pdo->prepare("SELECT id_utilisateur FROM posts WHERE idpost = ?");
                        $authorStmt->execute([$postId]);
                        $authorId = $authorStmt->fetchColumn();
                        
                        if ($authorId && $authorId != $userId) {
                            create_notification(
                                $pdo,
                                $authorId,
                                NOTIF_LIKE,
                                '@' . $_SESSION['surnom'] . ' a aimé votre publication',
                                $userId,
                                SITE_URL . '/index.php?post=' . $postId
                            );
                        }
                    }
                    
                    // Compter les likes
                    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM postlike WHERE idpost = ?");
                    $countStmt->execute([$postId]);
                    $likesCount = $countStmt->fetchColumn();
                    
                    json_response([
                        'success' => true,
                        'liked' => $liked,
                        'likes_count' => $likesCount
                    ]);
                } catch (PDOException $e) {
                    error_log("Like error: " . $e->getMessage());
                    json_response(['error' => 'Erreur lors de l\'action'], STATUS_SERVER_ERROR);
                }
                break;
                
            case 'comment':
                $postId = intval($input['post_id'] ?? 0);
                $content = trim($input['content'] ?? '');
                
                if (!$postId) {
                    json_response(['error' => 'ID de post requis'], STATUS_BAD_REQUEST);
                }
                if (empty($content)) {
                    json_response(['error' => 'Commentaire vide'], STATUS_BAD_REQUEST);
                }
                if (strlen($content) > 500) {
                    json_response(['error' => 'Commentaire trop long (max 500 caractères)'], STATUS_BAD_REQUEST);
                }
                
                try {
                    $pdo->beginTransaction();
                    
                    $stmt = $pdo->prepare("
                        INSERT INTO postcommentaire (idpost, id, textecommentaire, datecommentaire) 
                        VALUES (?, ?, ?, NOW())
                    ");
                    $stmt->execute([$postId, $userId, $content]);
                    $commentId = $pdo->lastInsertId();
                    
                    // Notifier l'auteur du post
                    $authorStmt = $pdo->prepare("SELECT id_utilisateur FROM posts WHERE idpost = ?");
                    $authorStmt->execute([$postId]);
                    $authorId = $authorStmt->fetchColumn();
                    
                    if ($authorId && $authorId != $userId) {
                        create_notification(
                            $pdo,
                            $authorId,
                            NOTIF_COMMENT,
                            '@' . $_SESSION['surnom'] . ' a commenté votre publication',
                            $userId,
                            SITE_URL . '/index.php?post=' . $postId
                        );
                    }
                    
                    // Récupérer le commentaire créé
                    $commentStmt = $pdo->prepare("
                        SELECT c.*, u.surnom, u.avatar
                        FROM postcommentaire c
                        JOIN utilisateurs u ON c.id = u.id
                        WHERE c.idcommentaire = ?
                    ");
                    $commentStmt->execute([$commentId]);
                    $comment = $commentStmt->fetch();
                    
                    $pdo->commit();
                    
                    json_response([
                        'success' => true,
                        'comment' => $comment
                    ], STATUS_CREATED);
                } catch (PDOException $e) {
                    $pdo->rollBack();
                    error_log("Comment error: " . $e->getMessage());
                    json_response(['error' => 'Erreur lors de l\'ajout du commentaire'], STATUS_SERVER_ERROR);
                }
                break;
                
            case 'share':
                $postId = intval($input['post_id'] ?? 0);
                $comment = trim($input['comment'] ?? '');
                
                if (!$postId) {
                    json_response(['error' => 'ID de post requis'], STATUS_BAD_REQUEST);
                }
                
                try {
                    // Récupérer le post original
                    $originalStmt = $pdo->prepare("SELECT * FROM posts WHERE idpost = ?");
                    $originalStmt->execute([$postId]);
                    $original = $originalStmt->fetch();
                    
                    if (!$original) {
                        json_response(['error' => 'Post non trouvé'], STATUS_NOT_FOUND);
                    }
                    
                    // Créer un nouveau post avec référence
                    $content = $comment 
                        ? $comment . "\n\n[Partagé] " . $original['contenu']
                        : "[Partagé] " . $original['contenu'];
                    
                    $stmt = $pdo->prepare("
                        INSERT INTO posts (id_utilisateur, contenu, image_post, shared_from, date_publication) 
                        VALUES (?, ?, ?, ?, NOW())
                    ");
                    $stmt->execute([$userId, $content, $original['image_post'], $postId]);
                    $newPostId = $pdo->lastInsertId();
                    
                    // Notifier l'auteur original
                    if ($original['id_utilisateur'] != $userId) {
                        create_notification(
                            $pdo,
                            $original['id_utilisateur'],
                            'share',
                            '@' . $_SESSION['surnom'] . ' a partagé votre publication',
                            $userId,
                            SITE_URL . '/index.php?post=' . $newPostId
                        );
                    }
                    
                    json_response([
                        'success' => true,
                        'post_id' => $newPostId,
                        'message' => 'Publication partagée'
                    ]);
                } catch (PDOException $e) {
                    error_log("Share error: " . $e->getMessage());
                    json_response(['error' => 'Erreur lors du partage'], STATUS_SERVER_ERROR);
                }
                break;
                
                case 'report':
                    $postId = intval($input['post_id'] ?? 0);
                    $reason = trim($input['reason'] ?? '');
                    $description = trim($input['description'] ?? '');
                    
                    if (!$postId) {
                        json_response(['error' => 'ID de post requis'], STATUS_BAD_REQUEST);
                        break;
                    }
                    
                    $validReasons = ['spam', 'harassment', 'inappropriate', 'violence', 'hate_speech', 'copyright', 'other'];
                    if (!in_array($reason, $validReasons)) {
                        json_response(['error' => 'Motif de signalement invalide'], STATUS_BAD_REQUEST);
                        break;
                    }
                    
                    // Utiliser l'API de signalement
                    $ch = curl_init(SITE_URL . '/api/post_reports.php');
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_POST, true);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
                        'action' => 'create',
                        'post_id' => $postId,
                        'reason' => $reason,
                        'description' => $description,
                        'csrf_token' => $input['csrf_token'] ?? ''
                    ]));
                    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                    $response = curl_exec($ch);
                    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);
                    
                    if ($httpCode === 201) {
                        $data = json_decode($response, true);
                        json_response([
                            'success' => true,
                            'report_id' => $data['report_id'],
                            'message' => 'Signalement envoyé. Notre équipe va examiner ce contenu.'
                        ], STATUS_CREATED);
                    } else {
                        $data = json_decode($response, true);
                        json_response(['error' => $data['error'] ?? 'Erreur lors du signalement'], STATUS_BAD_REQUEST);
                    }
                    break;
                
            default:
                json_response(['error' => 'Action non reconnue'], STATUS_BAD_REQUEST);
        }
        break;
        
    case 'PUT':
        $postId = intval($input['post_id'] ?? 0);
        $content = trim($input['content'] ?? '');
        
        if (!$postId) {
            json_response(['error' => 'ID de post requis'], STATUS_BAD_REQUEST);
        }
        
        try {
            // Vérifier propriétaire
            $checkStmt = $pdo->prepare("SELECT id_utilisateur FROM posts WHERE idpost = ?");
            $checkStmt->execute([$postId]);
            $ownerId = $checkStmt->fetchColumn();
            
            if ($ownerId != $userId && !is_admin()) {
                json_response(['error' => 'Permission refusée'], STATUS_FORBIDDEN);
            }
            
            $stmt = $pdo->prepare("UPDATE posts SET contenu = ?, edited_at = NOW() WHERE idpost = ?");
            $stmt->execute([$content, $postId]);
            
            log_activity($pdo, $userId, 'post_updated', ['post_id' => $postId]);
            json_response(['success' => true, 'message' => 'Publication modifiée']);
        } catch (PDOException $e) {
            error_log("Post update error: " . $e->getMessage());
            json_response(['error' => 'Erreur lors de la modification'], STATUS_SERVER_ERROR);
        }
        break;
        
    case 'DELETE':
        $postId = intval($input['post_id'] ?? $_GET['post_id'] ?? 0);
        
        if (!$postId) {
            json_response(['error' => 'ID de post requis'], STATUS_BAD_REQUEST);
        }
        
        try {
            // Vérifier propriétaire
            $checkStmt = $pdo->prepare("SELECT id_utilisateur, image_post FROM posts WHERE idpost = ?");
            $checkStmt->execute([$postId]);
            $post = $checkStmt->fetch();
            
            if (!$post) {
                json_response(['error' => 'Post non trouvé'], STATUS_NOT_FOUND);
            }
            
            if ($post['id_utilisateur'] != $userId && !is_admin()) {
                json_response(['error' => 'Permission refusée'], STATUS_FORBIDDEN);
            }
            
            // Supprimer l'image associée
            if ($post['image_post'] && file_exists(POSTS_DIR . $post['image_post'])) {
                unlink(POSTS_DIR . $post['image_post']);
            }
            
            $pdo->beginTransaction();
            
            // Supprimer les likes et commentaires (cascade normalement)
            $pdo->prepare("DELETE FROM postlike WHERE idpost = ?")->execute([$postId]);
            $pdo->prepare("DELETE FROM postcommentaire WHERE idpost = ?")->execute([$postId]);
            $pdo->prepare("DELETE FROM post_reports WHERE post_id = ?")->execute([$postId]);
            
            // Supprimer le post
            $stmt = $pdo->prepare("DELETE FROM posts WHERE idpost = ?");
            $stmt->execute([$postId]);
            
            $pdo->commit();
            
            log_activity($pdo, $userId, 'post_deleted', ['post_id' => $postId]);
            json_response(['success' => true, 'message' => 'Publication supprimée']);
        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log("Post deletion error: " . $e->getMessage());
            json_response(['error' => 'Erreur lors de la suppression'], STATUS_SERVER_ERROR);
        }
        break;
        
    default:
        json_response(['error' => 'Méthode non supportée'], STATUS_BAD_REQUEST);
}