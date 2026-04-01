<?php
/**
 * WideMaze - Community Events API
 * Version 1.0 - Gestion des événements dans les communautés
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

if (in_array($method, ['POST', 'PUT', 'DELETE'])) {
    if (!verify_csrf_token($input['csrf_token'] ?? $_POST['csrf_token'] ?? '')) {
        json_response(['error' => 'Token CSRF invalide'], STATUS_FORBIDDEN);
    }
}

// Vérifier si l'utilisateur est membre de la communauté
function checkCommunityMembership($pdo, $communityId, $userId, $requireModerator = false) {
    $stmt = $pdo->prepare("
        SELECT role FROM communaute_membres 
        WHERE id_communaute = ? AND id_utilisateur = ?
    ");
    $stmt->execute([$communityId, $userId]);
    $member = $stmt->fetch();
    
    if (!$member) {
        return ['error' => 'Vous n\'êtes pas membre de cette communauté'];
    }
    
    if ($requireModerator && !in_array($member['role'], ['admin', 'moderator'])) {
        return ['error' => 'Permission refusée'];
    }
    
    return ['success' => true, 'role' => $member['role']];
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
                $upcoming = isset($_GET['upcoming']) && $_GET['upcoming'] == '1';
                
                $sql = "
                    SELECT e.*, u.surnom as creator_name, u.avatar as creator_avatar,
                        (SELECT COUNT(*) FROM event_participants WHERE event_id = e.id AND status = 'going') as participants_count,
                        (SELECT status FROM event_participants WHERE event_id = e.id AND user_id = ?) as my_status
                    FROM community_events e
                    JOIN utilisateurs u ON e.created_by = u.id
                    WHERE e.community_id = ?
                ";
                $params = [$userId, $communityId];
                
                if ($upcoming) {
                    $sql .= " AND e.event_date > NOW()";
                }
                
                $sql .= " ORDER BY e.event_date ASC LIMIT ? OFFSET ?";
                $params[] = $limit;
                $params[] = $offset;
                
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $events = $stmt->fetchAll();
                
                // Compter le total
                $countSql = "SELECT COUNT(*) FROM community_events WHERE community_id = ?";
                if ($upcoming) $countSql .= " AND event_date > NOW()";
                $countStmt = $pdo->prepare($countSql);
                $countStmt->execute([$communityId]);
                
                json_response([
                    'success' => true,
                    'events' => $events,
                    'total' => $countStmt->fetchColumn()
                ]);
                break;
                
            case 'participants':
                $eventId = intval($_GET['event_id'] ?? 0);
                if (!$eventId) {
                    json_response(['error' => 'ID événement requis'], STATUS_BAD_REQUEST);
                }
                
                $stmt = $pdo->prepare("
                    SELECT u.id, u.surnom, u.prenom, u.nom, u.avatar, ep.status, ep.registered_at
                    FROM event_participants ep
                    JOIN utilisateurs u ON ep.user_id = u.id
                    WHERE ep.event_id = ?
                    ORDER BY ep.registered_at ASC
                    LIMIT 50
                ");
                $stmt->execute([$eventId]);
                $participants = $stmt->fetchAll();
                
                json_response(['success' => true, 'participants' => $participants]);
                break;
                
            default:
                json_response(['error' => 'Action inconnue'], STATUS_BAD_REQUEST);
        }
        break;
        
    case 'POST':
        $action = $input['action'] ?? 'create';
        $communityId = intval($input['community_id'] ?? 0);
        
        switch ($action) {
            case 'create':
                if (!$communityId) {
                    json_response(['error' => 'ID communauté requis'], STATUS_BAD_REQUEST);
                }
                
                // Vérifier que l'utilisateur est modérateur
                $check = checkCommunityMembership($pdo, $communityId, $userId, true);
                if (isset($check['error'])) {
                    json_response(['error' => $check['error']], STATUS_FORBIDDEN);
                }
                
                $title = trim($input['title'] ?? '');
                $description = trim($input['description'] ?? '');
                $eventDate = $input['event_date'] ?? '';
                $location = trim($input['location'] ?? '');
                $maxParticipants = intval($input['max_participants'] ?? 0);
                
                if (empty($title) || empty($eventDate)) {
                    json_response(['error' => 'Titre et date requis'], STATUS_BAD_REQUEST);
                }
                
                if (strtotime($eventDate) < time()) {
                    json_response(['error' => 'La date doit être dans le futur'], STATUS_BAD_REQUEST);
                }
                
                $stmt = $pdo->prepare("
                    INSERT INTO community_events (community_id, title, description, event_date, location, max_participants, created_by, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                $stmt->execute([$communityId, $title, $description, $eventDate, $location, $maxParticipants ?: null, $userId]);
                $eventId = $pdo->lastInsertId();
                
                log_activity($pdo, $userId, 'event_created', ['event_id' => $eventId, 'community_id' => $communityId]);
                
                json_response([
                    'success' => true,
                    'event_id' => $eventId,
                    'message' => 'Événement créé avec succès'
                ], STATUS_CREATED);
                break;
                
            case 'register':
                $eventId = intval($input['event_id'] ?? 0);
                $status = $input['status'] ?? 'going';
                
                if (!$eventId) {
                    json_response(['error' => 'ID événement requis'], STATUS_BAD_REQUEST);
                }
                
                // Vérifier que l'événement existe
                $eventStmt = $pdo->prepare("
                    SELECT community_id, max_participants, event_date FROM community_events WHERE id = ?
                ");
                $eventStmt->execute([$eventId]);
                $event = $eventStmt->fetch();
                
                if (!$event) {
                    json_response(['error' => 'Événement non trouvé'], STATUS_NOT_FOUND);
                }
                
                // Vérifier que l'utilisateur est membre de la communauté
                $check = checkCommunityMembership($pdo, $event['community_id'], $userId);
                if (isset($check['error'])) {
                    json_response(['error' => $check['error']], STATUS_FORBIDDEN);
                }
                
                // Vérifier la capacité
                if ($status == 'going' && $event['max_participants'] > 0) {
                    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM event_participants WHERE event_id = ? AND status = 'going'");
                    $countStmt->execute([$eventId]);
                    $currentCount = $countStmt->fetchColumn();
                    
                    if ($currentCount >= $event['max_participants']) {
                        json_response(['error' => 'Cet événement a atteint sa capacité maximale'], STATUS_BAD_REQUEST);
                    }
                }
                
                // Inscription ou mise à jour
                $stmt = $pdo->prepare("
                    INSERT INTO event_participants (event_id, user_id, status, registered_at)
                    VALUES (?, ?, ?, NOW())
                    ON DUPLICATE KEY UPDATE status = ?, registered_at = NOW()
                ");
                $stmt->execute([$eventId, $userId, $status, $status]);
                
                log_activity($pdo, $userId, 'event_registration', ['event_id' => $eventId, 'status' => $status]);
                
                json_response(['success' => true, 'message' => 'Inscription enregistrée']);
                break;
                
            default:
                json_response(['error' => 'Action inconnue'], STATUS_BAD_REQUEST);
        }
        break;
        
    case 'DELETE':
        $eventId = intval($input['event_id'] ?? $_GET['event_id'] ?? 0);
        
        if (!$eventId) {
            json_response(['error' => 'ID événement requis'], STATUS_BAD_REQUEST);
        }
        
        // Vérifier que l'utilisateur est le créateur ou modérateur
        $stmt = $pdo->prepare("
            SELECT e.*, cm.role 
            FROM community_events e
            LEFT JOIN communaute_membres cm ON e.community_id = cm.id_communaute AND cm.id_utilisateur = ?
            WHERE e.id = ?
        ");
        $stmt->execute([$userId, $eventId]);
        $event = $stmt->fetch();
        
        if (!$event) {
            json_response(['error' => 'Événement non trouvé'], STATUS_NOT_FOUND);
        }
        
        if ($event['created_by'] != $userId && !in_array($event['role'], ['admin', 'moderator'])) {
            json_response(['error' => 'Permission refusée'], STATUS_FORBIDDEN);
        }
        
        // Supprimer les participants
        $pdo->prepare("DELETE FROM event_participants WHERE event_id = ?")->execute([$eventId]);
        
        // Supprimer l'événement
        $pdo->prepare("DELETE FROM community_events WHERE id = ?")->execute([$eventId]);
        
        log_activity($pdo, $userId, 'event_deleted', ['event_id' => $eventId]);
        
        json_response(['success' => true, 'message' => 'Événement supprimé']);
        break;
        
    default:
        json_response(['error' => 'Méthode non supportée'], STATUS_METHOD_NOT_ALLOWED);
}