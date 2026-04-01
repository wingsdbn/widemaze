<?php
/**
 * WideMaze - Users API
 * Version 4.0 - Gestion des profils utilisateurs et préférences
 * Méthodes: GET (profil, settings), POST (update, avatar, cover, bio, password)
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
    $csrfToken = $input['csrf_token'] ?? $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($csrfToken)) {
        json_response(['error' => 'Token CSRF invalide'], STATUS_FORBIDDEN);
    }
}

// ==================== ROUTAGE ====================

switch ($method) {
    case 'GET':
        $action = $_GET['action'] ?? 'profile';
        $targetId = isset($_GET['user_id']) ? intval($_GET['user_id']) : $userId;
        
        switch ($action) {
            case 'profile':
                // Récupérer le profil utilisateur
                $stmt = $pdo->prepare("
                    SELECT u.id, u.surnom, u.prenom, u.nom, u.avatar, u.cover_image, u.bio,
                        u.universite, u.faculte, u.niveau_etude, u.profession, u.nationalite,
                        u.telephone, u.dateinscription, u.status, u.is_verified,
                        (SELECT COUNT(*) FROM posts WHERE id_utilisateur = u.id) as posts_count,
                        (SELECT COUNT(*) FROM ami WHERE (id = u.id OR idami = u.id) AND accepterami = 1) as friends_count,
                        (SELECT COUNT(*) FROM communaute_membres WHERE id_utilisateur = u.id) as communities_count
                    FROM utilisateurs u
                    WHERE u.id = ? AND u.is_active = 1
                ");
                $stmt->execute([$targetId]);
                $user = $stmt->fetch();
                
                if (!$user) {
                    json_response(['error' => 'Utilisateur non trouvé'], STATUS_NOT_FOUND);
                }
                
                // Vérifier la relation d'amitié
                $friendshipStatus = 'none';
                if ($targetId != $userId) {
                    $friendStmt = $pdo->prepare("
                        SELECT accepterami, demandeami FROM ami
                        WHERE (id = ? AND idami = ?) OR (id = ? AND idami = ?)
                    ");
                    $friendStmt->execute([$userId, $targetId, $targetId, $userId]);
                    $relation = $friendStmt->fetch();
                    
                    if ($relation) {
                        if ($relation['accepterami']) {
                            $friendshipStatus = 'friends';
                        } elseif ($relation['demandeami']) {
                            $friendshipStatus = 'pending';
                        }
                    }
                }
                
                json_response([
                    'success' => true,
                    'user' => $user,
                    'friendship_status' => $friendshipStatus,
                    'is_own_profile' => ($targetId == $userId)
                ]);
                break;
                
            case 'settings':
                // Récupérer les paramètres utilisateur
                $stmt = $pdo->prepare("
                    SELECT * FROM utilisateurs 
                    WHERE id = ? AND is_active = 1
                ");
                $stmt->execute([$userId]);
                $user = $stmt->fetch();
                
                // Récupérer les préférences
                $preferences = [];
                try {
                    $prefStmt = $pdo->prepare("SELECT * FROM user_preferences WHERE user_id = ?");
                    $prefStmt->execute([$userId]);
                    $preferences = $prefStmt->fetch();
                } catch (PDOException $e) {
                    // Table peut ne pas exister
                }
                
                json_response([
                    'success' => true,
                    'user' => $user,
                    'preferences' => $preferences
                ]);
                break;
                
            case 'stats':
                // Statistiques utilisateur
                $stmt = $pdo->prepare("
                    SELECT 
                        (SELECT COUNT(*) FROM posts WHERE id_utilisateur = ?) as total_posts,
                        (SELECT COUNT(*) FROM postlike WHERE id = ?) as total_likes,
                        (SELECT COUNT(*) FROM postcommentaire WHERE id = ?) as total_comments,
                        (SELECT COUNT(*) FROM ami WHERE (id = ? OR idami = ?) AND accepterami = 1) as total_friends,
                        (SELECT COUNT(*) FROM communaute_membres WHERE id_utilisateur = ?) as total_communities,
                        (SELECT COUNT(*) FROM message WHERE id_expediteur = ?) as messages_sent,
                        (SELECT COUNT(*) FROM message WHERE id_destinataire = ? AND lu = 0) as unread_messages
                ");
                $stmt->execute([$userId, $userId, $userId, $userId, $userId, $userId, $userId, $userId]);
                $stats = $stmt->fetch();
                
                json_response(['success' => true, 'stats' => $stats]);
                break;
                
            default:
                json_response(['error' => 'Action inconnue'], STATUS_BAD_REQUEST);
        }
        break;
        
    case 'POST':
        $action = $input['action'] ?? '';
        
        switch ($action) {
            case 'update_profile':
                // Mettre à jour le profil général
                $fields = [
                    'prenom' => trim($input['prenom'] ?? ''),
                    'nom' => trim($input['nom'] ?? ''),
                    'surnom' => trim($input['surnom'] ?? ''),
                    'bio' => trim($input['bio'] ?? ''),
                    'universite' => trim($input['universite'] ?? ''),
                    'faculte' => trim($input['faculte'] ?? ''),
                    'niveau_etude' => trim($input['niveau_etude'] ?? ''),
                    'profession' => trim($input['profession'] ?? ''),
                    'nationalite' => trim($input['nationalite'] ?? ''),
                    'telephone' => trim($input['telephone'] ?? '')
                ];
                
                // Validation
                $errors = [];
                if (strlen($fields['prenom']) > 20) $errors[] = 'Prénom trop long';
                if (strlen($fields['nom']) > 20) $errors[] = 'Nom trop long';
                if (strlen($fields['surnom']) > 30) $errors[] = 'Surnom trop long';
                if (!empty($fields['telephone']) && !preg_match('/^[0-9+\-\s()]+$/', $fields['telephone'])) {
                    $errors[] = 'Format de téléphone invalide';
                }
                
                if (!empty($errors)) {
                    json_response(['error' => implode(', ', $errors)], STATUS_BAD_REQUEST);
                }
                
                // Vérifier l'unicité du surnom
                $checkStmt = $pdo->prepare("SELECT id FROM utilisateurs WHERE surnom = ? AND id != ?");
                $checkStmt->execute([$fields['surnom'], $userId]);
                if ($checkStmt->fetch()) {
                    json_response(['error' => 'Ce surnom est déjà utilisé'], STATUS_CONFLICT);
                }
                
                // Construire la requête de mise à jour
                $setClauses = [];
                $params = [];
                foreach ($fields as $key => $value) {
                    $setClauses[] = "$key = ?";
                    $params[] = $value;
                }
                $params[] = $userId;
                
                $stmt = $pdo->prepare("
                    UPDATE utilisateurs 
                    SET " . implode(', ', $setClauses) . "
                    WHERE id = ?
                ");
                $stmt->execute($params);
                
                // Mettre à jour la session
                $_SESSION['prenom'] = $fields['prenom'];
                $_SESSION['nom'] = $fields['nom'];
                $_SESSION['surnom'] = $fields['surnom'];
                
                log_activity($pdo, $userId, 'update_profile', ['fields' => array_keys($fields)]);
                
                json_response(['success' => true, 'message' => 'Profil mis à jour']);
                break;
                
            case 'update_avatar':
                // Mise à jour de l'avatar (géré par upload.php)
                if (!isset($_FILES['avatar']) || $_FILES['avatar']['error'] !== UPLOAD_ERR_OK) {
                    json_response(['error' => 'Aucun fichier avatar fourni'], STATUS_BAD_REQUEST);
                }
                
                $upload = handle_file_upload($_FILES['avatar'], AVATAR_DIR, ALLOWED_IMAGE_TYPES, 2 * 1024 * 1024);
                if (!$upload['success']) {
                    json_response(['error' => $upload['error']], STATUS_BAD_REQUEST);
                }
                
                // Supprimer l'ancien avatar
                $stmt = $pdo->prepare("SELECT avatar FROM utilisateurs WHERE id = ?");
                $stmt->execute([$userId]);
                $oldAvatar = $stmt->fetchColumn();
                
                if ($oldAvatar && $oldAvatar !== DEFAULT_AVATAR && file_exists(AVATAR_DIR . $oldAvatar)) {
                    unlink(AVATAR_DIR . $oldAvatar);
                }
                
                // Mettre à jour la base de données
                $stmt = $pdo->prepare("UPDATE utilisateurs SET avatar = ? WHERE id = ?");
                $stmt->execute([$upload['filename'], $userId]);
                
                $_SESSION['avatar'] = $upload['filename'];
                log_activity($pdo, $userId, 'update_avatar');
                
                json_response([
                    'success' => true,
                    'avatar_url' => AVATAR_URL . $upload['filename'],
                    'message' => 'Avatar mis à jour'
                ]);
                break;
                
            case 'update_cover':
                // Mise à jour de la photo de couverture
                if (!isset($_FILES['cover']) || $_FILES['cover']['error'] !== UPLOAD_ERR_OK) {
                    json_response(['error' => 'Aucun fichier couverture fourni'], STATUS_BAD_REQUEST);
                }
                
                $upload = handle_file_upload($_FILES['cover'], COVERS_DIR, ALLOWED_IMAGE_TYPES, 5 * 1024 * 1024);
                if (!$upload['success']) {
                    json_response(['error' => $upload['error']], STATUS_BAD_REQUEST);
                }
                
                // Supprimer l'ancienne couverture
                $stmt = $pdo->prepare("SELECT cover_image FROM utilisateurs WHERE id = ?");
                $stmt->execute([$userId]);
                $oldCover = $stmt->fetchColumn();
                
                if ($oldCover && file_exists(COVERS_DIR . $oldCover)) {
                    unlink(COVERS_DIR . $oldCover);
                }
                
                // Mettre à jour la base de données
                $stmt = $pdo->prepare("UPDATE utilisateurs SET cover_image = ? WHERE id = ?");
                $stmt->execute([$upload['filename'], $userId]);
                
                log_activity($pdo, $userId, 'update_cover');
                
                json_response([
                    'success' => true,
                    'cover_url' => COVERS_URL . $upload['filename'],
                    'message' => 'Photo de couverture mise à jour'
                ]);
                break;
                
            case 'update_bio':
                $bio = trim($input['bio'] ?? '');
                if (strlen($bio) > 500) {
                    json_response(['error' => 'Bio trop longue (max 500 caractères)'], STATUS_BAD_REQUEST);
                }
                
                $stmt = $pdo->prepare("UPDATE utilisateurs SET bio = ? WHERE id = ?");
                $stmt->execute([$bio, $userId]);
                
                log_activity($pdo, $userId, 'update_bio');
                json_response(['success' => true, 'bio' => $bio]);
                break;
                
            case 'change_password':
                $current = $input['current_password'] ?? '';
                $new = $input['new_password'] ?? '';
                $confirm = $input['confirm_password'] ?? '';
                
                // Vérifier le mot de passe actuel
                $stmt = $pdo->prepare("SELECT motdepasse FROM utilisateurs WHERE id = ?");
                $stmt->execute([$userId]);
                $hash = $stmt->fetchColumn();
                
                if (!verify_password($current, $hash)) {
                    json_response(['error' => 'Mot de passe actuel incorrect'], STATUS_BAD_REQUEST);
                }
                
                // Validation du nouveau mot de passe
                $strengthErrors = validate_password_strength($new);
                if (!empty($strengthErrors)) {
                    json_response(['error' => implode(', ', $strengthErrors)], STATUS_BAD_REQUEST);
                }
                
                if ($new !== $confirm) {
                    json_response(['error' => 'Les mots de passe ne correspondent pas'], STATUS_BAD_REQUEST);
                }
                
                $newHash = hash_password($new);
                $stmt = $pdo->prepare("UPDATE utilisateurs SET motdepasse = ? WHERE id = ?");
                $stmt->execute([$newHash, $userId]);
                
                log_activity($pdo, $userId, 'password_change');
                json_response(['success' => true, 'message' => 'Mot de passe modifié']);
                break;
                
            case 'update_preferences':
                $preferences = [
                    'dark_mode' => isset($input['dark_mode']) ? 1 : 0,
                    'email_notifications' => isset($input['email_notifications']) ? 1 : 0,
                    'like_notifications' => isset($input['like_notifications']) ? 1 : 0,
                    'comment_notifications' => isset($input['comment_notifications']) ? 1 : 0,
                    'friend_notifications' => isset($input['friend_notifications']) ? 1 : 0,
                    'message_notifications' => isset($input['message_notifications']) ? 1 : 0,
                    'language' => $input['language'] ?? 'fr',
                    'timezone' => $input['timezone'] ?? 'Europe/Paris'
                ];
                
                try {
                    $checkStmt = $pdo->prepare("SELECT id FROM user_preferences WHERE user_id = ?");
                    $checkStmt->execute([$userId]);
                    
                    if ($checkStmt->fetch()) {
                        $setClauses = [];
                        $params = [];
                        foreach ($preferences as $key => $value) {
                            $setClauses[] = "$key = ?";
                            $params[] = $value;
                        }
                        $params[] = $userId;
                        
                        $stmt = $pdo->prepare("
                            UPDATE user_preferences 
                            SET " . implode(', ', $setClauses) . ", updated_at = NOW()
                            WHERE user_id = ?
                        ");
                        $stmt->execute($params);
                    } else {
                        $stmt = $pdo->prepare("
                            INSERT INTO user_preferences (user_id, dark_mode, email_notifications, like_notifications,
                                comment_notifications, friend_notifications, message_notifications, language, timezone)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ");
                        $stmt->execute([$userId, $preferences['dark_mode'], $preferences['email_notifications'],
                            $preferences['like_notifications'], $preferences['comment_notifications'],
                            $preferences['friend_notifications'], $preferences['message_notifications'],
                            $preferences['language'], $preferences['timezone']]);
                    }
                    
                    log_activity($pdo, $userId, 'update_preferences');
                    json_response(['success' => true, 'message' => 'Préférences mises à jour']);
                } catch (PDOException $e) {
                    error_log("Error updating preferences: " . $e->getMessage());
                    json_response(['error' => 'Erreur lors de la mise à jour'], STATUS_SERVER_ERROR);
                }
                break;
                
            default:
                json_response(['error' => 'Action inconnue'], STATUS_BAD_REQUEST);
        }
        break;
        
    default:
        json_response(['error' => 'Méthode non supportée'], STATUS_METHOD_NOT_ALLOWED);
}