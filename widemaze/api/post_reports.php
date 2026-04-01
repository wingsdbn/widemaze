<?php
/**
 * WideMaze - Post Reports API
 * Version 1.0 - Gestion complète des signalements de publications
 * Méthodes: GET (list, stats), POST (create, update_status), DELETE
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
$isAdmin = is_admin();

// Vérification CSRF pour les actions de modification
if (in_array($method, ['POST', 'PUT', 'DELETE'])) {
    $csrfToken = $input['csrf_token'] ?? $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($csrfToken)) {
        json_response(['error' => 'Token CSRF invalide'], STATUS_FORBIDDEN);
    }
}

// ==================== FONCTIONS ====================

/**
 * Crée un signalement pour un post
 */
function createReport($pdo, $postId, $reporterId, $reason, $description = null) {
    $validReasons = ['spam', 'harassment', 'inappropriate', 'violence', 'hate_speech', 'copyright', 'other'];
    
    if (!in_array($reason, $validReasons)) {
        return ['success' => false, 'error' => 'Motif de signalement invalide'];
    }
    
    try {
        // Vérifier si déjà signalé par cet utilisateur
        $checkStmt = $pdo->prepare("
            SELECT id FROM post_reports 
            WHERE post_id = ? AND reporter_id = ? AND status != 'dismissed'
        ");
        $checkStmt->execute([$postId, $reporterId]);
        
        if ($checkStmt->fetch()) {
            return ['success' => false, 'error' => 'Vous avez déjà signalé ce contenu'];
        }
        
        $stmt = $pdo->prepare("
            INSERT INTO post_reports (post_id, reporter_id, reason, description, status, created_at)
            VALUES (?, ?, ?, ?, 'pending', NOW())
        ");
        $stmt->execute([$postId, $reporterId, $reason, $description]);
        $reportId = $pdo->lastInsertId();
        
        // Marquer le post comme signalé
        $pdo->prepare("
            UPDATE posts SET is_reported = 1, reported_at = NOW() 
            WHERE idpost = ? AND is_reported = 0
        ")->execute([$postId]);
        
        // Notifier l'administrateur
        $adminStmt = $pdo->prepare("
            SELECT id FROM utilisateurs WHERE role = 'admin' AND is_active = 1
        ");
        $adminStmt->execute();
        $admins = $adminStmt->fetchAll();
        
        foreach ($admins as $admin) {
            create_notification(
                $pdo,
                $admin['id'],
                'report',
                'Nouveau signalement de contenu à examiner',
                $reporterId,
                SITE_URL . '/pages/admin.php?tab=reports'
            );
        }
        
        return ['success' => true, 'report_id' => $reportId];
        
    } catch (PDOException $e) {
        error_log("Error creating report: " . $e->getMessage());
        return ['success' => false, 'error' => 'Erreur lors du signalement'];
    }
}

/**
 * Récupère la liste des signalements avec filtres
 */
function getReports($pdo, $filters = [], $limit = 20, $offset = 0) {
    $sql = "
        SELECT r.*, 
               p.idpost, p.contenu, p.image_post, p.date_publication,
               u.surnom as reporter_name, u.avatar as reporter_avatar,
               a.surnom as author_name, a.avatar as author_avatar,
               rv.surnom as reviewer_name
        FROM post_reports r
        JOIN posts p ON r.post_id = p.idpost
        JOIN utilisateurs u ON r.reporter_id = u.id
        JOIN utilisateurs a ON p.id_utilisateur = a.id
        LEFT JOIN utilisateurs rv ON r.reviewed_by = rv.id
        WHERE 1=1
    ";
    $params = [];
    
    // Filtre par statut
    if (!empty($filters['status'])) {
        $sql .= " AND r.status = ?";
        $params[] = $filters['status'];
    }
    
    // Filtre par motif
    if (!empty($filters['reason'])) {
        $sql .= " AND r.reason = ?";
        $params[] = $filters['reason'];
    }
    
    // Filtre par post_id
    if (!empty($filters['post_id'])) {
        $sql .= " AND r.post_id = ?";
        $params[] = $filters['post_id'];
    }
    
    // Filtre par reporter
    if (!empty($filters['reporter_id'])) {
        $sql .= " AND r.reporter_id = ?";
        $params[] = $filters['reporter_id'];
    }
    
    $sql .= " ORDER BY 
                CASE r.status 
                    WHEN 'pending' THEN 1 
                    WHEN 'under_review' THEN 2 
                    ELSE 3 
                END,
                r.created_at DESC 
              LIMIT ? OFFSET ?";
    $params[] = $limit;
    $params[] = $offset;
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $reports = $stmt->fetchAll();
    
    // Compter le total
    $countSql = "SELECT COUNT(*) FROM post_reports r WHERE 1=1";
    $countParams = [];
    
    if (!empty($filters['status'])) {
        $countSql .= " AND r.status = ?";
        $countParams[] = $filters['status'];
    }
    if (!empty($filters['reason'])) {
        $countSql .= " AND r.reason = ?";
        $countParams[] = $filters['reason'];
    }
    
    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($countParams);
    $totalCount = $countStmt->fetchColumn();
    
    return [
        'reports' => $reports,
        'total' => $totalCount,
        'has_more' => count($reports) === $limit
    ];
}

/**
 * Met à jour le statut d'un signalement
 */
function updateReportStatus($pdo, $reportId, $status, $reviewerId, $notes = null) {
    $validStatuses = ['pending', 'under_review', 'action_taken', 'dismissed'];
    
    if (!in_array($status, $validStatuses)) {
        return ['success' => false, 'error' => 'Statut invalide'];
    }
    
    try {
        $pdo->beginTransaction();
        
        $stmt = $pdo->prepare("
            UPDATE post_reports 
            SET status = ?, reviewed_at = NOW(), reviewed_by = ?, resolution_notes = ?
            WHERE id = ?
        ");
        $stmt->execute([$status, $reviewerId, $notes, $reportId]);
        
        // Si action prise, on peut marquer le post comme traité
        if ($status === 'action_taken') {
            $reportStmt = $pdo->prepare("SELECT post_id FROM post_reports WHERE id = ?");
            $reportStmt->execute([$reportId]);
            $postId = $reportStmt->fetchColumn();
            
            if ($postId) {
                // Option: supprimer le post ou simplement le masquer
                // Ici on le marque simplement comme supprimé
                $pdo->prepare("UPDATE posts SET is_active = 0 WHERE idpost = ?")->execute([$postId]);
            }
        }
        
        $pdo->commit();
        
        return ['success' => true];
        
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log("Error updating report status: " . $e->getMessage());
        return ['success' => false, 'error' => 'Erreur lors de la mise à jour'];
    }
}

/**
 * Supprime un signalement
 */
function deleteReport($pdo, $reportId, $reviewerId) {
    try {
        $stmt = $pdo->prepare("
            DELETE FROM post_reports 
            WHERE id = ?
        ");
        $stmt->execute([$reportId]);
        $deleted = $stmt->rowCount();
        
        if ($deleted > 0) {
            log_activity($pdo, $reviewerId, 'admin_report_deleted', ['report_id' => $reportId]);
        }
        
        return ['success' => true, 'deleted' => $deleted];
        
    } catch (PDOException $e) {
        error_log("Error deleting report: " . $e->getMessage());
        return ['success' => false, 'error' => 'Erreur lors de la suppression'];
    }
}

/**
 * Récupère les statistiques des signalements
 */
function getReportStats($pdo) {
    try {
        $stats = [];
        
        // Total par statut
        $stmt = $pdo->query("
            SELECT status, COUNT(*) as count 
            FROM post_reports 
            GROUP BY status
        ");
        $stats['by_status'] = $stmt->fetchAll();
        
        // Total par motif
        $stmt = $pdo->query("
            SELECT reason, COUNT(*) as count 
            FROM post_reports 
            GROUP BY reason 
            ORDER BY count DESC
        ");
        $stats['by_reason'] = $stmt->fetchAll();
        
        // Évolution des signalements (30 derniers jours)
        $stmt = $pdo->query("
            SELECT DATE(created_at) as date, COUNT(*) as count 
            FROM post_reports 
            WHERE created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)
            GROUP BY DATE(created_at)
            ORDER BY date
        ");
        $stats['daily'] = $stmt->fetchAll();
        
        // Utilisateurs les plus signalés
        $stmt = $pdo->query("
            SELECT a.surnom, COUNT(*) as report_count
            FROM post_reports r
            JOIN posts p ON r.post_id = p.idpost
            JOIN utilisateurs a ON p.id_utilisateur = a.id
            GROUP BY a.id
            ORDER BY report_count DESC
            LIMIT 10
        ");
        $stats['top_reported_users'] = $stmt->fetchAll();
        
        // Temps moyen de résolution
        $stmt = $pdo->query("
            SELECT AVG(TIMESTAMPDIFF(HOUR, created_at, reviewed_at)) as avg_resolution_hours
            FROM post_reports 
            WHERE reviewed_at IS NOT NULL AND status IN ('action_taken', 'dismissed')
        ");
        $stats['avg_resolution_hours'] = round($stmt->fetchColumn(), 1);
        
        return ['success' => true, 'stats' => $stats];
        
    } catch (PDOException $e) {
        error_log("Error getting report stats: " . $e->getMessage());
        return ['success' => false, 'error' => 'Erreur lors de la récupération des statistiques'];
    }
}

// ==================== ROUTAGE ====================

switch ($method) {
    case 'GET':
        $action = $_GET['action'] ?? 'list';
        
        switch ($action) {
            case 'list':
                // Liste des signalements (admin uniquement)
                if (!$isAdmin) {
                    json_response(['error' => 'Permission refusée'], STATUS_FORBIDDEN);
                    break;
                }
                
                $limit = min(intval($_GET['limit'] ?? 20), 100);
                $offset = intval($_GET['offset'] ?? 0);
                $status = $_GET['status'] ?? null;
                $reason = $_GET['reason'] ?? null;
                $postId = isset($_GET['post_id']) ? intval($_GET['post_id']) : null;
                $reporterId = isset($_GET['reporter_id']) ? intval($_GET['reporter_id']) : null;
                
                $filters = [];
                if ($status) $filters['status'] = $status;
                if ($reason) $filters['reason'] = $reason;
                if ($postId) $filters['post_id'] = $postId;
                if ($reporterId) $filters['reporter_id'] = $reporterId;
                
                $result = getReports($pdo, $filters, $limit, $offset);
                
                json_response([
                    'success' => true,
                    'reports' => $result['reports'],
                    'total' => $result['total'],
                    'has_more' => $result['has_more']
                ]);
                break;
                
            case 'stats':
                // Statistiques des signalements (admin uniquement)
                if (!$isAdmin) {
                    json_response(['error' => 'Permission refusée'], STATUS_FORBIDDEN);
                    break;
                }
                
                $stats = getReportStats($pdo);
                json_response($stats);
                break;
                
            case 'check':
                // Vérifier si un utilisateur a déjà signalé un post
                $postId = intval($_GET['post_id'] ?? 0);
                
                if (!$postId) {
                    json_response(['error' => 'ID de post requis'], STATUS_BAD_REQUEST);
                    break;
                }
                
                $stmt = $pdo->prepare("
                    SELECT id, status FROM post_reports 
                    WHERE post_id = ? AND reporter_id = ?
                ");
                $stmt->execute([$postId, $userId]);
                $existing = $stmt->fetch();
                
                json_response([
                    'success' => true,
                    'has_reported' => !empty($existing),
                    'report_id' => $existing['id'] ?? null,
                    'status' => $existing['status'] ?? null
                ]);
                break;
                
            default:
                json_response(['error' => 'Action inconnue'], STATUS_BAD_REQUEST);
        }
        break;
        
    case 'POST':
        $action = $input['action'] ?? 'create';
        
        switch ($action) {
            case 'create':
                // Créer un signalement
                $postId = intval($input['post_id'] ?? 0);
                $reason = trim($input['reason'] ?? '');
                $description = trim($input['description'] ?? '');
                
                if (!$postId) {
                    json_response(['error' => 'ID de post requis'], STATUS_BAD_REQUEST);
                    break;
                }
                
                if (empty($reason)) {
                    json_response(['error' => 'Motif de signalement requis'], STATUS_BAD_REQUEST);
                    break;
                }
                
                // Vérifier que le post existe
                $postStmt = $pdo->prepare("SELECT idpost FROM posts WHERE idpost = ?");
                $postStmt->execute([$postId]);
                if (!$postStmt->fetch()) {
                    json_response(['error' => 'Post non trouvé'], STATUS_NOT_FOUND);
                    break;
                }
                
                $result = createReport($pdo, $postId, $userId, $reason, $description);
                
                if ($result['success']) {
                    log_activity($pdo, $userId, 'post_reported', ['post_id' => $postId, 'reason' => $reason]);
                    json_response([
                        'success' => true,
                        'report_id' => $result['report_id'],
                        'message' => 'Signalement envoyé. Notre équipe va examiner ce contenu.'
                    ], STATUS_CREATED);
                } else {
                    json_response(['error' => $result['error']], STATUS_BAD_REQUEST);
                }
                break;
                
            case 'update_status':
                // Mettre à jour le statut d'un signalement (admin uniquement)
                if (!$isAdmin) {
                    json_response(['error' => 'Permission refusée'], STATUS_FORBIDDEN);
                    break;
                }
                
                $reportId = intval($input['report_id'] ?? 0);
                $status = $input['status'] ?? '';
                $notes = $input['notes'] ?? null;
                
                if (!$reportId) {
                    json_response(['error' => 'ID de signalement requis'], STATUS_BAD_REQUEST);
                    break;
                }
                
                $result = updateReportStatus($pdo, $reportId, $status, $userId, $notes);
                
                if ($result['success']) {
                    log_activity($pdo, $userId, 'admin_report_updated', ['report_id' => $reportId, 'status' => $status]);
                    json_response([
                        'success' => true,
                        'message' => 'Statut du signalement mis à jour'
                    ]);
                } else {
                    json_response(['error' => $result['error']], STATUS_BAD_REQUEST);
                }
                break;
                
            default:
                json_response(['error' => 'Action inconnue'], STATUS_BAD_REQUEST);
        }
        break;
        
    case 'DELETE':
        // Supprimer un signalement (admin uniquement)
        if (!$isAdmin) {
            json_response(['error' => 'Permission refusée'], STATUS_FORBIDDEN);
            break;
        }
        
        $reportId = intval($input['report_id'] ?? $_GET['report_id'] ?? 0);
        
        if (!$reportId) {
            json_response(['error' => 'ID de signalement requis'], STATUS_BAD_REQUEST);
            break;
        }
        
        $result = deleteReport($pdo, $reportId, $userId);
        
        if ($result['success']) {
            json_response([
                'success' => true,
                'deleted' => $result['deleted'],
                'message' => 'Signalement supprimé'
            ]);
        } else {
            json_response(['error' => $result['error']], STATUS_SERVER_ERROR);
        }
        break;
        
    default:
        json_response(['error' => 'Méthode non supportée'], STATUS_METHOD_NOT_ALLOWED);
}