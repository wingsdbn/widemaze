<?php
/**
 * WideMaze - Messages API
 * Version 4.0 - Messagerie instantanée avec fichiers et notifications
 * Méthodes: GET (conversations, messages), POST (send, delete, typing)
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
        $action = $_GET['action'] ?? 'conversations';
        
        switch ($action) {
            case 'conversations':
                $limit = min(intval($_GET['limit'] ?? 20), 50);
                $offset = intval($_GET['offset'] ?? 0);
                
                // 1. Récupérer les IDs des interlocuteurs
                $stmt = $pdo->prepare("
                    SELECT DISTINCT 
                        CASE 
                            WHEN id_expediteur = ? THEN id_destinataire
                            WHEN id_destinataire = ? THEN id_expediteur
                        END as other_id
                    FROM message
                    WHERE id_expediteur = ? OR id_destinataire = ?
                    ORDER BY datemessage DESC
                ");
                $stmt->execute([$userId, $userId, $userId, $userId]);
                $interlocutors = $stmt->fetchAll();
                
                $conversations = [];
                foreach ($interlocutors as $interlocutor) {
                    $otherId = $interlocutor['other_id'];
                    
                    // 2. Récupérer les infos de la dernière conversation
                    $stmt2 = $pdo->prepare("
                        SELECT m.*, u.surnom, u.avatar, u.status, u.prenom, u.nom, u.universite,
                            (SELECT COUNT(*) FROM message 
                             WHERE id_destinataire = ? AND id_expediteur = ? AND lu = 0) as unread_count
                        FROM message m
                        JOIN utilisateurs u ON u.id = ?
                        WHERE (id_expediteur = ? AND id_destinataire = ?) 
                           OR (id_expediteur = ? AND id_destinataire = ?)
                        ORDER BY m.idmessage DESC
                        LIMIT 1
                    ");
                    $stmt2->execute([$userId, $otherId, $otherId, $userId, $otherId, $otherId, $userId]);
                    $lastMsg = $stmt2->fetch();
                    
                    if ($lastMsg) {
                        $conversations[] = [
                            'other_id' => $otherId,
                            'surnom' => $lastMsg['surnom'],
                            'avatar' => $lastMsg['avatar'],
                            'status' => $lastMsg['status'],
                            'prenom' => $lastMsg['prenom'],
                            'nom' => $lastMsg['nom'],
                            'universite' => $lastMsg['universite'],
                            'last_message' => $lastMsg['textemessage'],
                            'last_date' => $lastMsg['datemessage'],
                            'unread_count' => $lastMsg['unread_count']
                        ];
                    }
                }
                
                // Trier par date
                usort($conversations, function($a, $b) {
                    return strtotime($b['last_date']) - strtotime($a['last_date']);
                });
                
                json_response([
                    'success' => true,
                    'conversations' => array_slice($conversations, $offset, $limit),
                    'count' => count($conversations)
                ]);
                break;
                
            case 'messages':
                $with = intval($_GET['with'] ?? 0);
                $before = intval($_GET['before'] ?? 0);
                $limit = min(intval($_GET['limit'] ?? 20), 50);
                
                if (!$with) {
                    json_response(['error' => 'ID destinataire requis'], STATUS_BAD_REQUEST);
                }
                
                $sql = "
                    SELECT m.*, u.surnom, u.avatar, u.status
                    FROM message m
                    JOIN utilisateurs u ON m.id_expediteur = u.id
                    WHERE ((id_expediteur = ? AND id_destinataire = ?)
                        OR (id_expediteur = ? AND id_destinataire = ?))
                        AND (deleted_for_sender = 0 OR deleted_for_sender IS NULL)
                ";
                $params = [$userId, $with, $with, $userId];
                
                if ($before > 0) {
                    $sql .= " AND m.idmessage < ?";
                    $params[] = $before;
                }
                
                $sql .= " ORDER BY m.idmessage DESC LIMIT ?";
                $params[] = $limit;
                
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $messages = $stmt->fetchAll();
                $messages = array_reverse($messages);
                
                // Marquer comme lus
                if ($with != $userId) {
                    $updateStmt = $pdo->prepare("
                        UPDATE message SET lu = 1 
                        WHERE id_destinataire = ? AND id_expediteur = ? AND lu = 0
                    ");
                    $updateStmt->execute([$userId, $with]);
                }
                
                json_response([
                    'success' => true,
                    'messages' => $messages,
                    'has_more' => count($messages) === $limit
                ]);
                break;
                
            case 'unread_count':
                // Compter les messages non lus
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) FROM message 
                    WHERE id_destinataire = ? AND lu = 0
                ");
                $stmt->execute([$userId]);
                $count = $stmt->fetchColumn();
                
                json_response(['success' => true, 'unread_count' => $count]);
                break;
                
            default:
                json_response(['error' => 'Action inconnue'], STATUS_BAD_REQUEST);
        }
        break;
        
    case 'POST':
        $action = $input['action'] ?? '';
        
        switch ($action) {
            case 'send':
                $to = intval($input['to'] ?? 0);
                $message = trim($input['message'] ?? '');
                $type = $input['type'] ?? 'text';
                
                if (!$to) {
                    json_response(['error' => 'ID destinataire requis'], STATUS_BAD_REQUEST);
                }
                if (empty($message) && empty($_FILES['file']) && empty($input['voice_data'])) {
                    json_response(['error' => 'Message vide'], STATUS_BAD_REQUEST);
                }
                
                $fileInfo = null;
                $duration = null;
                
                // Gestion des notes vocales (base64)
                if (!empty($input['voice_data']) && $input['voice_data'] != 'undefined') {
                    $voiceData = $input['voice_data'];
                    if (preg_match('/data:audio\/(\w+);base64,(.+)/', $voiceData, $matches)) {
                        $audioBinary = base64_decode($matches[2]);
                        if ($audioBinary) {
                            if (!is_dir(MESSAGES_DIR)) mkdir(MESSAGES_DIR, 0755, true);
                            $filename = uniqid() . '_voice_' . bin2hex(random_bytes(4)) . '.webm';
                            $destination = MESSAGES_DIR . $filename;
                            
                            if (file_put_contents($destination, $audioBinary)) {
                                $duration = intval($input['voice_duration'] ?? 0);
                                $fileInfo = [
                                    'url' => str_replace($_SERVER['DOCUMENT_ROOT'], '', $destination),
                                    'name' => 'Note vocale.webm',
                                    'size' => strlen($audioBinary),
                                    'type' => 'voice',
                                    'duration' => $duration
                                ];
                                $type = 'voice';
                                $message = '';
                            }
                        }
                    }
                }
                
                // Gestion des fichiers uploadés
                if (isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
                    $allowedTypes = array_merge(ALLOWED_IMAGE_TYPES, ALLOWED_DOCUMENT_TYPES, ALLOWED_VIDEO_TYPES);
                    $upload = handle_file_upload($_FILES['file'], MESSAGES_DIR, $allowedTypes, 50 * 1024 * 1024);
                    
                    if ($upload['success']) {
                        $fileType = 'document';
                        if (in_array($upload['mime'], ALLOWED_IMAGE_TYPES)) $fileType = 'image';
                        elseif (in_array($upload['mime'], ALLOWED_VIDEO_TYPES)) $fileType = 'video';
                        
                        $fileInfo = [
                            'url' => str_replace($_SERVER['DOCUMENT_ROOT'], '', MESSAGES_DIR . $upload['filename']),
                            'name' => $_FILES['file']['name'],
                            'size' => $_FILES['file']['size'],
                            'type' => $fileType,
                            'mime' => $upload['mime']
                        ];
                        $type = $fileType;
                    } else {
                        json_response(['error' => $upload['error']], STATUS_BAD_REQUEST);
                    }
                }
                
                try {
                    $stmt = $pdo->prepare("
                        INSERT INTO message (id_expediteur, id_destinataire, textemessage, datemessage, type, lu, file_url, file_name, file_size, file_duration)
                        VALUES (?, ?, ?, NOW(), ?, 0, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $userId, $to, $message, $type,
                        $fileInfo ? $fileInfo['url'] : null,
                        $fileInfo ? $fileInfo['name'] : null,
                        $fileInfo ? $fileInfo['size'] : null,
                        $duration
                    ]);
                    $messageId = $pdo->lastInsertId();
                    
                    // Notifier le destinataire
                    if ($to != $userId) {
                        $notificationType = $fileInfo ? 'file' : 'message';
                        $notificationContent = $fileInfo 
                            ? 'Nouveau fichier de @' . $_SESSION['surnom']
                            : 'Nouveau message de @' . $_SESSION['surnom'];
                        
                        create_notification(
                            $pdo,
                            $to,
                            $notificationType,
                            $notificationContent,
                            $userId,
                            SITE_URL . '/pages/messagerie.php?user=' . $userId
                        );
                    }
                    
                    json_response([
                        'success' => true,
                        'message_id' => $messageId,
                        'message' => [
                            'id' => $messageId,
                            'text' => nl2br(htmlspecialchars($message)),
                            'time' => date('H:i'),
                            'type' => $type,
                            'is_mine' => true,
                            'file' => $fileInfo ? [
                                'url' => $fileInfo['url'],
                                'name' => $fileInfo['name'],
                                'size' => format_file_size($fileInfo['size']),
                                'type' => $fileInfo['type'],
                                'duration' => $duration
                            ] : null
                        ]
                    ]);
                } catch (PDOException $e) {
                    error_log("Send message error: " . $e->getMessage());
                    json_response(['error' => 'Erreur lors de l\'envoi'], STATUS_SERVER_ERROR);
                }
                break;
                
            case 'typing':
                $to = intval($input['to'] ?? 0);
                $_SESSION['typing'][$to] = time();
                json_response(['success' => true]);
                break;
                
            case 'check_typing':
                $from = intval($input['from'] ?? 0);
                $isTyping = isset($_SESSION['typing'][$from]) && (time() - $_SESSION['typing'][$from]) < 3;
                json_response(['typing' => $isTyping]);
                break;
                
            default:
                json_response(['error' => 'Action inconnue'], STATUS_BAD_REQUEST);
        }
        break;
        
    case 'DELETE':
        $messageId = intval($input['message_id'] ?? $_GET['id'] ?? 0);
        $deleteFor = $input['delete_for'] ?? 'me';
        
        if (!$messageId) {
            json_response(['error' => 'ID message requis'], STATUS_BAD_REQUEST);
        }
        
        try {
            $stmt = $pdo->prepare("SELECT id_expediteur, file_url FROM message WHERE idmessage = ?");
            $stmt->execute([$messageId]);
            $message = $stmt->fetch();
            
            if (!$message) {
                json_response(['error' => 'Message non trouvé'], STATUS_NOT_FOUND);
            }
            
            // Vérifier les droits
            if ($deleteFor == 'everyone' && $message['id_expediteur'] != $userId) {
                json_response(['error' => 'Permission refusée'], STATUS_FORBIDDEN);
            }
            
            if ($deleteFor == 'everyone' && $message['id_expediteur'] == $userId) {
                // Supprimer le fichier associé
                if ($message['file_url'] && file_exists($_SERVER['DOCUMENT_ROOT'] . $message['file_url'])) {
                    unlink($_SERVER['DOCUMENT_ROOT'] . $message['file_url']);
                }
                $stmt = $pdo->prepare("DELETE FROM message WHERE idmessage = ?");
                $stmt->execute([$messageId]);
            } else {
                // Supprimer seulement pour l'utilisateur actuel
                $field = ($message['id_expediteur'] == $userId) ? 'deleted_for_sender' : 'deleted_for_receiver';
                $stmt = $pdo->prepare("UPDATE message SET $field = 1 WHERE idmessage = ?");
                $stmt->execute([$messageId]);
            }
            
            json_response(['success' => true, 'message' => 'Message supprimé']);
        } catch (PDOException $e) {
            error_log("Delete message error: " . $e->getMessage());
            json_response(['error' => 'Erreur lors de la suppression'], STATUS_SERVER_ERROR);
        }
        break;
        
    case 'PUT':
        // Marquer les messages comme lus
        $with = intval($input['with'] ?? 0);
        
        if (!$with) {
            json_response(['error' => 'ID utilisateur requis'], STATUS_BAD_REQUEST);
        }
        
        $stmt = $pdo->prepare("
            UPDATE message SET lu = 1 
            WHERE id_destinataire = ? AND id_expediteur = ? AND lu = 0
        ");
        $stmt->execute([$userId, $with]);
        
        json_response([
            'success' => true,
            'marked' => $stmt->rowCount()
        ]);
        break;
        
    default:
        json_response(['error' => 'Méthode non supportée'], STATUS_METHOD_NOT_ALLOWED);
}