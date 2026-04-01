<?php
/**
 * WideMaze - Search API
 * Version 4.0 - Recherche avancée avec suggestions en temps réel
 * Méthodes: GET (recherche, suggestions)
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

if ($method !== 'GET') {
    json_response(['error' => 'Méthode non supportée'], STATUS_METHOD_NOT_ALLOWED);
}

$userId = $_SESSION['user_id'];
$query = trim($_GET['q'] ?? '');
$type = $_GET['type'] ?? 'all';
$limit = min(intval($_GET['limit'] ?? 10), 50);
$offset = intval($_GET['offset'] ?? 0);
$suggestionsOnly = isset($_GET['suggestions']) && $_GET['suggestions'] == '1';

// ==================== VALIDATION ====================

if (empty($query) && !$suggestionsOnly) {
    json_response(['error' => 'Requête de recherche vide'], STATUS_BAD_REQUEST);
}

if (strlen($query) < 2 && !$suggestionsOnly) {
    json_response(['error' => 'Requête trop courte (min 2 caractères)'], STATUS_BAD_REQUEST);
}

$searchTerm = "%$query%";
$exactTerm = "$query%";
$results = ['users' => [], 'posts' => [], 'communities' => []];
$suggestions = [];

// ==================== RECHERCHE COMPLÈTE ====================

if (!$suggestionsOnly && !empty($query)) {
    
    // 1. RECHERCHE D'UTILISATEURS
    if ($type == 'all' || $type == 'users') {
        $sql = "
            SELECT u.id, u.surnom, u.prenom, u.nom, u.avatar, u.universite, u.faculte, u.profession, u.status, u.nationalite, u.is_verified,
                'user' as result_type,
                CASE 
                    WHEN a.accepterami = 1 THEN 'friends'
                    WHEN a.demandeami = 1 AND a.id = ? THEN 'pending_sent'
                    WHEN a.demandeami = 1 AND a.idami = ? THEN 'pending_received'
                    ELSE 'none'
                END as friendship_status,
                (SELECT COUNT(*) FROM posts WHERE id_utilisateur = u.id) as posts_count,
                (SELECT COUNT(*) FROM ami WHERE (id = u.id OR idami = u.id) AND accepterami = 1) as friends_count,
                MATCH(u.surnom, u.prenom, u.nom, u.email) AGAINST(?) as relevance_score
            FROM utilisateurs u
            LEFT JOIN ami a ON (a.id = u.id AND a.idami = ?) OR (a.idami = u.id AND a.id = ?)
            WHERE (u.surnom LIKE ? OR u.prenom LIKE ? OR u.nom LIKE ? OR u.email LIKE ? OR u.universite LIKE ? OR u.faculte LIKE ? OR u.profession LIKE ?)
                AND u.id != ?
                AND u.is_active = 1
            ORDER BY 
                CASE WHEN u.surnom LIKE ? THEN 1 ELSE 0 END DESC,
                relevance_score DESC,
                u.surnom
            LIMIT ? OFFSET ?
        ";
        
        $params = [
            $userId, $userId, $query,
            $userId, $userId,
            $searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm,
            $userId,
            $exactTerm,
            $limit, $offset
        ];
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $results['users'] = $stmt->fetchAll();
    }
    
    // 2. RECHERCHE DE POSTS
    if ($type == 'all' || $type == 'posts') {
        $sql = "
            SELECT p.idpost, p.contenu, p.image_post, p.date_publication, 'post' as result_type,
                u.id as user_id, u.surnom, u.avatar, u.prenom, u.nom,
                (SELECT COUNT(*) FROM postlike WHERE idpost = p.idpost) as likes_count,
                (SELECT COUNT(*) FROM postcommentaire WHERE idpost = p.idpost) as comments_count,
                (SELECT EXISTS(SELECT 1 FROM postlike WHERE idpost = p.idpost AND id = ?)) as user_liked,
                CASE 
                    WHEN p.contenu LIKE ? THEN 10
                    WHEN p.contenu LIKE ? THEN 5
                    ELSE 1
                END as relevance_score
            FROM posts p
            JOIN utilisateurs u ON p.id_utilisateur = u.id
            WHERE (p.contenu LIKE ? OR p.contenu LIKE ?)
                AND (p.privacy = 'public' OR p.id_utilisateur = ? OR p.id_utilisateur IN (
                    SELECT CASE WHEN id = ? THEN idami ELSE id END
                    FROM ami WHERE (id = ? OR idami = ?) AND accepterami = 1
                ))
            ORDER BY relevance_score DESC, p.date_publication DESC
            LIMIT ? OFFSET ?
        ";
        
        $params = [
            $userId,
            "%$query%", "%$query%",
            "%$query%", "%$query%",
            $userId, $userId, $userId, $userId,
            $limit, $offset
        ];
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $results['posts'] = $stmt->fetchAll();
    }
    
    // 3. RECHERCHE DE COMMUNAUTÉS
    if ($type == 'all' || $type == 'communities') {
        try {
            $sql = "
                SELECT c.id_communaute, c.nom, c.description, c.categorie, c.image_couverture, 'community' as result_type,
                    (SELECT COUNT(*) FROM communaute_membres WHERE id_communaute = c.id_communaute) as members_count,
                    EXISTS(SELECT 1 FROM communaute_membres WHERE id_communaute = c.id_communaute AND id_utilisateur = ?) as is_member,
                    (SELECT COUNT(*) FROM posts WHERE id_communaute = c.id_communaute) as posts_count,
                    CASE 
                        WHEN c.nom LIKE ? THEN 10
                        WHEN c.description LIKE ? THEN 5
                        ELSE 1
                    END as relevance_score
                FROM communautes c
                WHERE (c.nom LIKE ? OR c.description LIKE ?)
                    AND c.is_active = 1
                ORDER BY relevance_score DESC, members_count DESC
                LIMIT ? OFFSET ?
            ";
            
            $params = [
                $userId,
                "%$query%", "%$query%",
                "%$query%", "%$query%",
                $limit, $offset
            ];
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $results['communities'] = $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log("Error searching communities: " . $e->getMessage());
        }
    }
    
    // 4. GÉNÉRATION DE SUGGESTIONS
    try {
        $stmt = $pdo->prepare("
            SELECT query, COUNT(*) as count 
            FROM search_history 
            WHERE query LIKE ? AND user_id != ? 
            GROUP BY query 
            ORDER BY count DESC 
            LIMIT 10
        ");
        $stmt->execute(["%$query%", $userId]);
        $suggestions = $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("Error fetching suggestions: " . $e->getMessage());
    }
    
    // 5. ENREGISTREMENT DE L'HISTORIQUE
    try {
        $historyStmt = $pdo->prepare("
            INSERT INTO search_history (user_id, query, searched_at) 
            VALUES (?, ?, NOW())
            ON DUPLICATE KEY UPDATE searched_at = NOW()
        ");
        $historyStmt->execute([$userId, substr($query, 0, 255)]);
    } catch (PDOException $e) {
        // Ignorer silencieusement
    }
    
    // Log de recherche
    $totalCount = count($results['users']) + count($results['posts']) + count($results['communities']);
    log_activity($pdo, $userId, 'search', ['query' => $query, 'type' => $type, 'results' => $totalCount]);
    
    json_response([
        'success' => true,
        'query' => $query,
        'type' => $type,
        'results' => $results,
        'suggestions' => $suggestions,
        'total_count' => $totalCount
    ]);
    
} else {
    // ==================== SUGGESTIONS UNIQUEMENT ====================
    
    try {
        // Suggestions basées sur l'historique
        $stmt = $pdo->prepare("
            SELECT query, COUNT(*) as count 
            FROM search_history 
            WHERE query LIKE ? AND user_id != ? 
            GROUP BY query 
            ORDER BY count DESC 
            LIMIT ?
        ");
        $stmt->execute(["%$query%", $userId, $limit]);
        $suggestions = $stmt->fetchAll();
        
        // Si pas assez de suggestions, ajouter des mots-clés populaires
        if (count($suggestions) < 5) {
            $popularStmt = $pdo->prepare("
                SELECT query, COUNT(*) as count 
                FROM search_history 
                GROUP BY query 
                ORDER BY count DESC 
                LIMIT ?
            ");
            $popularStmt->execute([$limit]);
            $popular = $popularStmt->fetchAll();
            
            // Fusionner les suggestions
            $existingQueries = array_column($suggestions, 'query');
            foreach ($popular as $p) {
                if (!in_array($p['query'], $existingQueries) && count($suggestions) < $limit) {
                    $suggestions[] = $p;
                }
            }
        }
        
        json_response([
            'success' => true,
            'suggestions' => $suggestions,
            'query' => $query
        ]);
    } catch (PDOException $e) {
        error_log("Error fetching suggestions: " . $e->getMessage());
        json_response([
            'success' => false,
            'error' => 'Erreur lors de la récupération des suggestions'
        ], STATUS_SERVER_ERROR);
    }
}