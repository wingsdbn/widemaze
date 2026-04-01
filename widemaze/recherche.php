<?php
/**
 * WideMaze - Recherche Avancée
 * Moteur de recherche intelligent avec filtres et suggestions
 */

require_once 'config.php';
require_auth();

$userId = $_SESSION['user_id'];
$query = trim($_GET['q'] ?? '');
$type = $_GET['type'] ?? 'all';
$page = max(1, intval($_GET['page'] ?? 1));
$limit = 20;
$offset = ($page - 1) * $limit;
$sort = $_GET['sort'] ?? 'relevance'; // relevance, date, popularity
$dateFilter = $_GET['date'] ?? 'all'; // all, today, week, month, year
$university = $_GET['university'] ?? '';
$country = $_GET['country'] ?? '';

// Initialisation
$results = ['users' => [], 'posts' => [], 'communities' => []];
$totalCount = 0;
$suggestions = [];
$relatedSearches = [];
$facetFilters = [];

if (!empty($query) && strlen($query) >= 2) {
    try {
        $searchTerm = "%$query%";
        $exactTerm = "$query%";
        
        // Construire les filtres de date
        $dateCondition = '';
        if ($dateFilter === 'today') {
            $dateCondition = "AND DATE(date_publication) = CURDATE()";
        } elseif ($dateFilter === 'week') {
            $dateCondition = "AND date_publication > DATE_SUB(NOW(), INTERVAL 7 DAY)";
        } elseif ($dateFilter === 'month') {
            $dateCondition = "AND date_publication > DATE_SUB(NOW(), INTERVAL 1 MONTH)";
        } elseif ($dateFilter === 'year') {
            $dateCondition = "AND date_publication > DATE_SUB(NOW(), INTERVAL 1 YEAR)";
        }
        
        // Construire les filtres d'université/pays
        $universityCondition = '';
        if (!empty($university)) {
            $universityCondition = "AND u.universite LIKE ?";
        }
        
        $countryCondition = '';
        if (!empty($country)) {
            $countryCondition = "AND u.nationalite LIKE ?";
        }
        
        // 1. RECHERCHE D'UTILISATEURS
        if ($type === 'all' || $type === 'users') {
            $sql = "
                SELECT u.id, u.surnom, u.prenom, u.nom, u.avatar, u.universite, u.faculte, u.status, 
                       u.profession, u.nationalite, 'user' as result_type,
                       CASE 
                           WHEN a.accepterami = 1 THEN 'friends'
                           WHEN a.demandeami = 1 AND a.id = ? THEN 'pending_sent'
                           WHEN a.demandeami = 1 AND a.idami = ? THEN 'pending_received'
                           ELSE 'none' 
                       END as friendship_status,
                       MATCH(u.surnom, u.prenom, u.nom, u.email) AGAINST(?) as relevance_score
                FROM utilisateurs u
                LEFT JOIN ami a ON (a.id = u.id AND a.idami = ?) OR (a.idami = u.id AND a.id = ?)
                WHERE (u.surnom LIKE ? OR u.prenom LIKE ? OR u.nom LIKE ? OR u.email LIKE ? OR u.universite LIKE ?)
                AND u.id != ?
                AND u.is_active = 1
                $universityCondition $countryCondition
                ORDER BY 
                    CASE WHEN u.surnom LIKE ? THEN 1 ELSE 0 END DESC,
                    CASE WHEN u.prenom LIKE ? THEN 1 ELSE 0 END DESC,
                    CASE WHEN u.nom LIKE ? THEN 1 ELSE 0 END DESC,
                    u.surnom
                LIMIT ? OFFSET ?
            ";
            
            $params = [
                $userId, $userId, $query,
                $userId, $userId,
                $searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm,
                $userId
            ];
            
            if (!empty($university)) {
                $params[] = "%$university%";
            }
            if (!empty($country)) {
                $params[] = "%$country%";
            }
            
            $params[] = $exactTerm;
            $params[] = $exactTerm;
            $params[] = $exactTerm;
            $params[] = $limit;
            $params[] = $offset;
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $results['users'] = $stmt->fetchAll();
            $totalCount += count($results['users']);
        }
        
        // 2. RECHERCHE DE POSTS AVEC SCORE DE PERTINENCE
        if ($type === 'all' || $type === 'posts') {
            $sql = "
                SELECT p.idpost, p.contenu, p.image_post, p.date_publication, 'post' as result_type,
                       u.id as user_id, u.surnom, u.avatar, u.prenom, u.nom,
                       (SELECT COUNT(*) FROM postlike WHERE idpost = p.idpost) as likes_count,
                       (SELECT COUNT(*) FROM postcommentaire WHERE idpost = p.idpost) as comments_count,
                       (SELECT EXISTS(SELECT 1 FROM postlike WHERE idpost = p.idpost AND id = ?)) as user_liked,
                       CASE 
                           WHEN p.contenu LIKE ? THEN 3
                           WHEN p.contenu LIKE ? THEN 2
                           ELSE 1
                       END as relevance_score
                FROM posts p
                JOIN utilisateurs u ON p.id_utilisateur = u.id
                WHERE p.contenu LIKE ?
                $dateCondition
                AND (p.privacy = 'public' OR p.id_utilisateur = ? OR p.id_utilisateur IN (
                    SELECT CASE WHEN id = ? THEN idami ELSE id END 
                    FROM ami WHERE (id = ? OR idami = ?) AND accepterami = 1
                ))
                ORDER BY relevance_score DESC, p.date_publication DESC
                LIMIT ? OFFSET ?
            ";
            
            $params = [
                $userId,
                "%$query%",
                "%$query%",
                "%$query%",
                $userId,
                $userId,
                $userId,
                $userId,
                $limit,
                $offset
            ];
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $results['posts'] = $stmt->fetchAll();
            $totalCount += count($results['posts']);
        }
        
        // 3. RECHERCHE DE COMMUNAUTÉS
        if ($type === 'all' || $type === 'communities') {
            try {
                $sql = "
                    SELECT c.id_communaute, c.nom, c.description, c.categorie, c.image_couverture, 'community' as result_type,
                           (SELECT COUNT(*) FROM communaute_membres WHERE id_communaute = c.id_communaute) as members_count,
                           EXISTS(SELECT 1 FROM communaute_membres WHERE id_communaute = c.id_communaute AND id_utilisateur = ?) as is_member,
                           (SELECT COUNT(*) FROM posts WHERE id_communaute = c.id_communaute) as posts_count,
                           CASE 
                               WHEN c.nom LIKE ? THEN 3
                               WHEN c.description LIKE ? THEN 2
                               ELSE 1
                           END as relevance_score
                    FROM communautes c
                    WHERE c.nom LIKE ? OR c.description LIKE ?
                    ORDER BY relevance_score DESC, members_count DESC
                    LIMIT ? OFFSET ?
                ";
                
                $params = [
                    $userId,
                    "%$query%",
                    "%$query%",
                    "%$query%",
                    "%$query%",
                    $limit,
                    $offset
                ];
                
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $results['communities'] = $stmt->fetchAll();
                $totalCount += count($results['communities']);
            } catch (PDOException $e) {
                error_log("Erreur recherche communautés: " . $e->getMessage());
            }
        }
        
        // 4. GÉNÉRATION DE SUGGESTIONS (recherches populaires)
        $suggestions = [];
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
            error_log("Erreur suggestions: " . $e->getMessage());
        }
        
        // 5. RECHERCHES CONNEXES (mots-clés similaires)
        $relatedSearches = [];
        if (strlen($query) > 2) {
            $relatedSearches = [
                $query . " université",
                $query . " étudiant",
                $query . " cours",
                $query . " master",
                $query . " doctorat"
            ];
        }
        
        // 6. FACETTES/FILTRES (universités et pays populaires)
        $facetFilters = [];
        try {
            $stmt = $pdo->prepare("
                SELECT universite, COUNT(*) as count 
                FROM utilisateurs 
                WHERE (surnom LIKE ? OR prenom LIKE ? OR nom LIKE ? OR universite LIKE ?)
                AND universite IS NOT NULL AND universite != ''
                GROUP BY universite 
                ORDER BY count DESC 
                LIMIT 10
            ");
            $stmt->execute([$searchTerm, $searchTerm, $searchTerm, $searchTerm]);
            $facetFilters['universities'] = $stmt->fetchAll();
            
            $stmt = $pdo->prepare("
                SELECT nationalite, COUNT(*) as count 
                FROM utilisateurs 
                WHERE (surnom LIKE ? OR prenom LIKE ? OR nom LIKE ?)
                AND nationalite IS NOT NULL AND nationalite != ''
                GROUP BY nationalite 
                ORDER BY count DESC 
                LIMIT 10
            ");
            $stmt->execute([$searchTerm, $searchTerm, $searchTerm]);
            $facetFilters['countries'] = $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log("Erreur facets: " . $e->getMessage());
        }
        
        // Log de recherche
        log_activity($pdo, $userId, 'search', ['query' => $query, 'type' => $type, 'results' => $totalCount]);
        
    } catch (PDOException $e) {
        error_log("Erreur recherche: " . $e->getMessage());
    }
}

// Récupération des universités et pays pour les filtres
$universities = [];
$countries = [];
try {
    $stmt = $pdo->query("SELECT DISTINCT universite FROM utilisateurs WHERE universite IS NOT NULL AND universite != '' ORDER BY universite LIMIT 50");
    $universities = $stmt->fetchAll();
    
    $stmt = $pdo->query("SELECT DISTINCT nationalite FROM utilisateurs WHERE nationalite IS NOT NULL AND nationalite != '' ORDER BY nationalite LIMIT 50");
    $countries = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Erreur récupération filtres: " . $e->getMessage());
}

$csrfToken = generate_csrf_token();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= !empty($query) ? htmlspecialchars($query) . ' - ' : '' ?>Recherche - WideMaze</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: { primary: '#f59e0b', secondary: '#1e293b' },
                    fontFamily: { sans: ['Inter', 'sans-serif'] }
                }
            }
        }
    </script>
    <style>
        .search-highlight { background-color: #fef3c7; border-radius: 4px; padding: 0 2px; }
        .result-card:hover { transform: translateY(-2px); transition: all 0.2s; }
        .facet-active { background-color: #f59e0b; color: white; border-color: #f59e0b; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        .animate-fade-in { animation: fadeIn 0.3s ease-out; }
        .skeleton { background: linear-gradient(90deg, #f0f0f0 25%, #e0e0e0 50%, #f0f0f0 75%); background-size: 200% 100%; animation: loading 1.5s infinite; }
        @keyframes loading { 0% { background-position: 200% 0; } 100% { background-position: -200% 0; } }
    </style>
</head>
<body class="bg-gray-100 min-h-screen">

    <!-- Navigation -->
    <nav class="fixed top-0 w-full bg-white shadow-md z-50 border-b border-gray-200">
        <div class="container mx-auto px-4 h-16 flex items-center justify-between">
            <a href="index.php" class="flex items-center gap-2 hover:opacity-80 transition-opacity">
                <div class="w-10 h-10 bg-gradient-to-br from-orange-500 to-red-600 rounded-lg flex items-center justify-center">
                    <i class="fas fa-network-wired text-white"></i>
                </div>
                <span class="text-2xl font-bold text-gray-800 hidden md:block">WideMaze</span>
            </a>
            
            <div class="flex items-center gap-4">
                <a href="notifications.php" class="p-2 hover:bg-gray-100 rounded-full transition-colors">
                    <i class="fas fa-bell text-gray-600"></i>
                </a>
                <div class="relative group">
                    <button class="flex items-center gap-2 p-1">
                        <img src="<?= AVATAR_URL . htmlspecialchars($_SESSION['avatar'] ?? 'default.jpg') ?>" class="w-8 h-8 rounded-full object-cover border-2 border-orange-500">
                    </button>
                </div>
            </div>
        </div>
    </nav>

    <div class="container mx-auto pt-20 pb-8 px-4 max-w-6xl">
        
        <!-- Barre de recherche principale (type Google) -->
        <div class="mb-8">
            <form method="get" action="" id="searchForm">
                <div class="relative">
                    <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                        <i class="fas fa-search text-gray-400 text-lg"></i>
                    </div>
                    <input type="text" name="q" id="searchInput" value="<?= htmlspecialchars($query) ?>" 
                           placeholder="Rechercher des personnes, publications, communautés..."
                           class="w-full pl-12 pr-32 py-4 text-lg border-2 border-gray-200 rounded-2xl focus:border-orange-500 focus:outline-none focus:ring-4 focus:ring-orange-100 transition-all"
                           autocomplete="off">
                    <div class="absolute right-2 top-1/2 -translate-y-1/2 flex gap-2">
                        <button type="submit" class="bg-orange-500 hover:bg-orange-600 text-white px-6 py-2 rounded-xl font-medium transition-colors">
                            <i class="fas fa-search mr-2"></i>Rechercher
                        </button>
                    </div>
                </div>
                
                <!-- Suggestions instantanées -->
                <div id="suggestions" class="absolute z-20 w-full bg-white rounded-xl shadow-xl border border-gray-200 mt-1 hidden max-h-96 overflow-y-auto"></div>
            </form>
            
            <!-- Statistiques de recherche -->
            <?php if (!empty($query)): ?>
            <div class="mt-4 flex items-center gap-4 text-sm text-gray-500">
                <span><i class="fas fa-chart-line mr-1"></i><?= number_format($totalCount) ?> résultat<?= $totalCount > 1 ? 's' : '' ?></span>
                <span><i class="fas fa-clock mr-1"></i><?= number_format(microtime(true) - $_SERVER['REQUEST_TIME_FLOAT'], 2) ?> secondes</span>
            </div>
            <?php endif; ?>
        </div>
        
        <?php if (!empty($query)): ?>
        
        <!-- Filtres horizontaux (type Google) -->
        <div class="flex flex-wrap gap-2 mb-6 border-b border-gray-200 pb-3 overflow-x-auto">
            <a href="?q=<?= urlencode($query) ?>&type=all&sort=<?= $sort ?>&date=<?= $dateFilter ?>&university=<?= urlencode($university) ?>&country=<?= urlencode($country) ?>" 
               class="px-4 py-2 rounded-full text-sm font-medium transition-all <?= $type === 'all' ? 'bg-orange-500 text-white shadow-md' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' ?>">
                <i class="fas fa-globe mr-2"></i>Tous
            </a>
            <a href="?q=<?= urlencode($query) ?>&type=users&sort=<?= $sort ?>&date=<?= $dateFilter ?>&university=<?= urlencode($university) ?>&country=<?= urlencode($country) ?>" 
               class="px-4 py-2 rounded-full text-sm font-medium transition-all <?= $type === 'users' ? 'bg-orange-500 text-white shadow-md' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' ?>">
                <i class="fas fa-users mr-2"></i>Personnes
            </a>
            <a href="?q=<?= urlencode($query) ?>&type=posts&sort=<?= $sort ?>&date=<?= $dateFilter ?>&university=<?= urlencode($university) ?>&country=<?= urlencode($country) ?>" 
               class="px-4 py-2 rounded-full text-sm font-medium transition-all <?= $type === 'posts' ? 'bg-orange-500 text-white shadow-md' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' ?>">
                <i class="fas fa-newspaper mr-2"></i>Publications
            </a>
            <a href="?q=<?= urlencode($query) ?>&type=communities&sort=<?= $sort ?>&date=<?= $dateFilter ?>&university=<?= urlencode($university) ?>&country=<?= urlencode($country) ?>" 
               class="px-4 py-2 rounded-full text-sm font-medium transition-all <?= $type === 'communities' ? 'bg-orange-500 text-white shadow-md' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' ?>">
                <i class="fas fa-university mr-2"></i>Communautés
            </a>
        </div>
        
        <div class="grid lg:grid-cols-4 gap-6">
            <!-- Sidebar filtres (type Google) -->
            <div class="lg:col-span-1 space-y-6">
                <!-- Filtre de date -->
                <div class="bg-white rounded-2xl shadow-sm p-5">
                    <h3 class="font-bold text-gray-800 mb-3 flex items-center gap-2">
                        <i class="fas fa-calendar-alt text-orange-500"></i>Date
                    </h3>
                    <div class="space-y-2">
                        <a href="?q=<?= urlencode($query) ?>&type=<?= $type ?>&sort=<?= $sort ?>&date=all&university=<?= urlencode($university) ?>&country=<?= urlencode($country) ?>" 
                           class="block px-3 py-2 rounded-lg text-sm <?= $dateFilter === 'all' ? 'bg-orange-50 text-orange-600 font-medium' : 'text-gray-600 hover:bg-gray-50' ?>">
                            Toutes les dates
                        </a>
                        <a href="?q=<?= urlencode($query) ?>&type=<?= $type ?>&sort=<?= $sort ?>&date=today&university=<?= urlencode($university) ?>&country=<?= urlencode($country) ?>" 
                           class="block px-3 py-2 rounded-lg text-sm <?= $dateFilter === 'today' ? 'bg-orange-50 text-orange-600 font-medium' : 'text-gray-600 hover:bg-gray-50' ?>">
                            Aujourd'hui
                        </a>
                        <a href="?q=<?= urlencode($query) ?>&type=<?= $type ?>&sort=<?= $sort ?>&date=week&university=<?= urlencode($university) ?>&country=<?= urlencode($country) ?>" 
                           class="block px-3 py-2 rounded-lg text-sm <?= $dateFilter === 'week' ? 'bg-orange-50 text-orange-600 font-medium' : 'text-gray-600 hover:bg-gray-50' ?>">
                            Cette semaine
                        </a>
                        <a href="?q=<?= urlencode($query) ?>&type=<?= $type ?>&sort=<?= $sort ?>&date=month&university=<?= urlencode($university) ?>&country=<?= urlencode($country) ?>" 
                           class="block px-3 py-2 rounded-lg text-sm <?= $dateFilter === 'month' ? 'bg-orange-50 text-orange-600 font-medium' : 'text-gray-600 hover:bg-gray-50' ?>">
                            Ce mois
                        </a>
                        <a href="?q=<?= urlencode($query) ?>&type=<?= $type ?>&sort=<?= $sort ?>&date=year&university=<?= urlencode($university) ?>&country=<?= urlencode($country) ?>" 
                           class="block px-3 py-2 rounded-lg text-sm <?= $dateFilter === 'year' ? 'bg-orange-50 text-orange-600 font-medium' : 'text-gray-600 hover:bg-gray-50' ?>">
                            Cette année
                        </a>
                    </div>
                </div>
                
                <!-- Filtre universités -->
                <?php if (!empty($facetFilters['universities'])): ?>
                <div class="bg-white rounded-2xl shadow-sm p-5">
                    <h3 class="font-bold text-gray-800 mb-3 flex items-center gap-2">
                        <i class="fas fa-graduation-cap text-orange-500"></i>Universités
                    </h3>
                    <div class="space-y-2 max-h-64 overflow-y-auto">
                        <?php foreach ($facetFilters['universities'] as $uni): ?>
                        <a href="?q=<?= urlencode($query) ?>&type=<?= $type ?>&sort=<?= $sort ?>&date=<?= $dateFilter ?>&university=<?= urlencode($uni['universite']) ?>&country=<?= urlencode($country) ?>" 
                           class="flex items-center justify-between px-3 py-2 rounded-lg text-sm hover:bg-gray-50 transition-colors <?= $university === $uni['universite'] ? 'bg-orange-50 text-orange-600' : 'text-gray-600' ?>">
                            <span class="truncate"><?= htmlspecialchars($uni['universite']) ?></span>
                            <span class="text-xs text-gray-400"><?= $uni['count'] ?></span>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Filtre pays -->
                <?php if (!empty($facetFilters['countries'])): ?>
                <div class="bg-white rounded-2xl shadow-sm p-5">
                    <h3 class="font-bold text-gray-800 mb-3 flex items-center gap-2">
                        <i class="fas fa-map-marker-alt text-orange-500"></i>Pays
                    </h3>
                    <div class="space-y-2 max-h-64 overflow-y-auto">
                        <?php foreach ($facetFilters['countries'] as $c): ?>
                        <a href="?q=<?= urlencode($query) ?>&type=<?= $type ?>&sort=<?= $sort ?>&date=<?= $dateFilter ?>&university=<?= urlencode($university) ?>&country=<?= urlencode($c['nationalite']) ?>" 
                           class="flex items-center justify-between px-3 py-2 rounded-lg text-sm hover:bg-gray-50 transition-colors <?= $country === $c['nationalite'] ? 'bg-orange-50 text-orange-600' : 'text-gray-600' ?>">
                            <span><?= htmlspecialchars($c['nationalite']) ?></span>
                            <span class="text-xs text-gray-400"><?= $c['count'] ?></span>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Recherches connexes -->
                <?php if (!empty($relatedSearches)): ?>
                <div class="bg-white rounded-2xl shadow-sm p-5">
                    <h3 class="font-bold text-gray-800 mb-3 flex items-center gap-2">
                        <i class="fas fa-link text-orange-500"></i>Recherches connexes
                    </h3>
                    <div class="space-y-2">
                        <?php foreach ($relatedSearches as $related): ?>
                        <a href="?q=<?= urlencode($related) ?>&type=all" class="block text-sm text-gray-600 hover:text-orange-500 transition-colors">
                            <i class="fas fa-search text-xs mr-2"></i><?= htmlspecialchars($related) ?>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- Résultats de recherche -->
            <div class="lg:col-span-3 space-y-4">
                
                <!-- Résultats utilisateurs -->
                <?php if (($type === 'all' || $type === 'users') && !empty($results['users'])): ?>
                    <?php foreach ($results['users'] as $user): ?>
                    <div class="bg-white rounded-2xl shadow-sm p-5 result-card hover:shadow-md transition-all animate-fade-in">
                        <div class="flex items-start gap-4">
                            <img src="<?= AVATAR_URL . htmlspecialchars($user['avatar'] ?? 'default.jpg') ?>" 
                                 class="w-16 h-16 rounded-full object-cover border-2 border-orange-200">
                            <div class="flex-1">
                                <div class="flex items-center flex-wrap gap-2 mb-1">
                                    <a href="profil.php?id=<?= $user['id'] ?>" class="font-bold text-gray-800 hover:text-orange-500 text-lg">
                                        <?= highlightText($user['prenom'] . ' ' . $user['nom'], $query) ?>
                                    </a>
                                    <?php if ($user['is_verified'] ?? false): ?>
                                        <i class="fas fa-check-circle text-blue-500 text-sm" title="Compte vérifié"></i>
                                    <?php endif; ?>
                                    <span class="text-sm text-gray-500">@<?= htmlspecialchars($user['surnom']) ?></span>
                                </div>
                                <div class="flex flex-wrap gap-3 text-sm text-gray-500 mb-2">
                                    <?php if (!empty($user['universite'])): ?>
                                        <span class="flex items-center gap-1">
                                            <i class="fas fa-university text-orange-400"></i>
                                            <?= highlightText($user['universite'], $query) ?>
                                        </span>
                                    <?php endif; ?>
                                    <?php if (!empty($user['nationalite'])): ?>
                                        <span class="flex items-center gap-1">
                                            <i class="fas fa-map-marker-alt text-orange-400"></i>
                                            <?= highlightText($user['nationalite'], $query) ?>
                                        </span>
                                    <?php endif; ?>
                                    <?php if (!empty($user['profession'])): ?>
                                        <span class="flex items-center gap-1">
                                            <i class="fas fa-briefcase text-orange-400"></i>
                                            <?= highlightText($user['profession'], $query) ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <div class="flex gap-3 mt-3">
                                    <?php if ($user['friendship_status'] === 'none'): ?>
                                        <button onclick="sendFriendRequest(<?= $user['id'] ?>)" 
                                                class="bg-orange-500 text-white px-4 py-1.5 rounded-lg text-sm font-medium hover:bg-orange-600 transition-colors">
                                            <i class="fas fa-user-plus mr-1"></i>Ajouter
                                        </button>
                                    <?php elseif ($user['friendship_status'] === 'pending_sent'): ?>
                                        <span class="bg-gray-200 text-gray-600 px-4 py-1.5 rounded-lg text-sm font-medium">
                                            <i class="fas fa-clock mr-1"></i>Demande envoyée
                                        </span>
                                    <?php elseif ($user['friendship_status'] === 'pending_received'): ?>
                                        <div class="flex gap-2">
                                            <button onclick="acceptFriendRequest(<?= $user['id'] ?>)" 
                                                    class="bg-green-500 text-white px-4 py-1.5 rounded-lg text-sm font-medium hover:bg-green-600">
                                                <i class="fas fa-check mr-1"></i>Accepter
                                            </button>
                                            <button onclick="rejectFriendRequest(<?= $user['id'] ?>)" 
                                                    class="bg-red-500 text-white px-4 py-1.5 rounded-lg text-sm font-medium hover:bg-red-600">
                                                <i class="fas fa-times mr-1"></i>Refuser
                                            </button>
                                        </div>
                                    <?php elseif ($user['friendship_status'] === 'friends'): ?>
                                        <span class="bg-green-100 text-green-700 px-4 py-1.5 rounded-lg text-sm font-medium">
                                            <i class="fas fa-check-circle mr-1"></i>Amis
                                        </span>
                                    <?php endif; ?>
                                    <a href="messagerie.php?user=<?= $user['id'] ?>" 
                                       class="bg-gray-100 text-gray-700 px-4 py-1.5 rounded-lg text-sm font-medium hover:bg-gray-200 transition-colors">
                                        <i class="fas fa-comment mr-1"></i>Message
                                    </a>
                                </div>
                            </div>
                            <?php if ($user['status'] === 'Online'): ?>
                                <span class="w-2.5 h-2.5 bg-green-500 rounded-full animate-pulse"></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
                
                <!-- Résultats publications -->
                <?php if (($type === 'all' || $type === 'posts') && !empty($results['posts'])): ?>
                    <?php foreach ($results['posts'] as $post): ?>
                    <div class="bg-white rounded-2xl shadow-sm p-5 result-card hover:shadow-md transition-all animate-fade-in">
                        <div class="flex items-center gap-3 mb-3">
                            <img src="<?= AVATAR_URL . htmlspecialchars($post['avatar'] ?? 'default.jpg') ?>" class="w-10 h-10 rounded-full">
                            <div>
                                <a href="profil.php?id=<?= $post['user_id'] ?>" class="font-semibold text-gray-800 hover:text-orange-500">
                                    <?= highlightText($post['surnom'], $query) ?>
                                </a>
                                <p class="text-xs text-gray-400"><?= date('d M Y à H:i', strtotime($post['date_publication'])) ?></p>
                            </div>
                        </div>
                        <p class="text-gray-700 mb-3"><?= highlightText(nl2br(htmlspecialchars($post['contenu'])), $query) ?></p>
                        <?php if (!empty($post['image_post'])): ?>
                            <img src="uploads/posts/<?= htmlspecialchars($post['image_post']) ?>" class="rounded-xl max-h-96 object-cover mt-2 mb-3">
                        <?php endif; ?>
                        <div class="flex items-center gap-4 text-sm text-gray-500">
                            <span><i class="fas fa-heart text-red-500"></i> <?= $post['likes_count'] ?></span>
                            <span><i class="fas fa-comment text-blue-500"></i> <?= $post['comments_count'] ?></span>
                            <a href="index.php?post=<?= $post['idpost'] ?>" class="hover:text-orange-500 transition-colors">
                                <i class="fas fa-external-link-alt"></i> Voir la publication
                            </a>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
                
                <!-- Résultats communautés -->
                <?php if (($type === 'all' || $type === 'communities') && !empty($results['communities'])): ?>
                    <?php foreach ($results['communities'] as $community): ?>
                    <div class="bg-white rounded-2xl shadow-sm p-5 result-card hover:shadow-md transition-all animate-fade-in">
                        <div class="flex items-start gap-4">
                            <div class="w-16 h-16 bg-gradient-to-br from-orange-500 to-red-600 rounded-xl flex items-center justify-center text-white text-2xl font-bold shadow-md">
                                <?= strtoupper(substr($community['nom'], 0, 1)) ?>
                            </div>
                            <div class="flex-1">
                                <div class="flex items-center justify-between mb-1">
                                    <a href="communaute.php?id=<?= $community['id_communaute'] ?>" class="font-bold text-gray-800 hover:text-orange-500 text-lg">
                                        <?= highlightText($community['nom'], $query) ?>
                                    </a>
                                    <span class="text-xs bg-gray-100 text-gray-600 px-2 py-1 rounded"><?= htmlspecialchars($community['categorie'] ?? 'Academic') ?></span>
                                </div>
                                <p class="text-gray-600 text-sm mb-2 line-clamp-2"><?= highlightText(htmlspecialchars($community['description'] ?? ''), $query) ?></p>
                                <div class="flex items-center gap-4 text-sm text-gray-500">
                                    <span><i class="fas fa-users"></i> <?= number_format($community['members_count']) ?> membres</span>
                                    <span><i class="fas fa-newspaper"></i> <?= number_format($community['posts_count'] ?? 0) ?> publications</span>
                                </div>
                                <div class="mt-3">
                                    <?php if ($community['is_member']): ?>
                                        <span class="bg-green-100 text-green-700 px-4 py-1.5 rounded-lg text-sm font-medium">
                                            <i class="fas fa-check mr-1"></i>Membre
                                        </span>
                                    <?php else: ?>
                                        <button onclick="joinCommunity(<?= $community['id_communaute'] ?>)" 
                                                class="bg-orange-500 text-white px-4 py-1.5 rounded-lg text-sm font-medium hover:bg-orange-600 transition-colors">
                                            <i class="fas fa-sign-in-alt mr-1"></i>Rejoindre
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
                
                <!-- Aucun résultat -->
                <?php if ($totalCount === 0): ?>
                <div class="bg-white rounded-2xl shadow-sm p-12 text-center">
                    <div class="w-24 h-24 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-search text-4xl text-gray-300"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-700 mb-2">Aucun résultat trouvé</h3>
                    <p class="text-gray-500 mb-4">Nous n'avons trouvé aucun résultat pour "<?= htmlspecialchars($query) ?>"</p>
                    <p class="text-sm text-gray-400">Suggestions :</p>
                    <ul class="text-sm text-gray-500 mt-2 space-y-1">
                        <li>• Vérifiez l'orthographe</li>
                        <li>• Utilisez des termes plus généraux</li>
                        <li>• Essayez d'autres mots-clés</li>
                    </ul>
                </div>
                <?php endif; ?>
                
                <!-- Pagination -->
                <?php if ($totalCount > $limit): 
                    $totalPages = ceil($totalCount / $limit);
                ?>
                <div class="flex justify-center gap-2 mt-8">
                    <?php if ($page > 1): ?>
                    <a href="?q=<?= urlencode($query) ?>&type=<?= $type ?>&page=<?= $page-1 ?>&sort=<?= $sort ?>&date=<?= $dateFilter ?>&university=<?= urlencode($university) ?>&country=<?= urlencode($country) ?>" 
                       class="px-4 py-2 bg-white border rounded-lg hover:bg-gray-50 transition-colors">
                        <i class="fas fa-chevron-left"></i> Précédent
                    </a>
                    <?php endif; ?>
                    
                    <div class="flex gap-1">
                        <?php 
                        $startPage = max(1, $page - 2);
                        $endPage = min($totalPages, $page + 2);
                        for ($i = $startPage; $i <= $endPage; $i++): 
                        ?>
                        <a href="?q=<?= urlencode($query) ?>&type=<?= $type ?>&page=<?= $i ?>&sort=<?= $sort ?>&date=<?= $dateFilter ?>&university=<?= urlencode($university) ?>&country=<?= urlencode($country) ?>" 
                           class="w-10 h-10 flex items-center justify-center rounded-lg <?= $i === $page ? 'bg-orange-500 text-white' : 'bg-white border hover:bg-gray-50' ?> transition-colors">
                            <?= $i ?>
                        </a>
                        <?php endfor; ?>
                    </div>
                    
                    <?php if ($page < $totalPages): ?>
                    <a href="?q=<?= urlencode($query) ?>&type=<?= $type ?>&page=<?= $page+1 ?>&sort=<?= $sort ?>&date=<?= $dateFilter ?>&university=<?= urlencode($university) ?>&country=<?= urlencode($country) ?>" 
                       class="px-4 py-2 bg-white border rounded-lg hover:bg-gray-50 transition-colors">
                        Suivant <i class="fas fa-chevron-right"></i>
                    </a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <?php else: ?>
        
        <!-- Page d'accueil de recherche -->
        <div class="bg-white rounded-2xl shadow-sm p-12 text-center">
            <div class="w-32 h-32 bg-gradient-to-br from-orange-100 to-red-100 rounded-full flex items-center justify-center mx-auto mb-6">
                <i class="fas fa-search text-5xl text-orange-500"></i>
            </div>
            <h2 class="text-2xl font-bold text-gray-800 mb-2">Que cherchez-vous ?</h2>
            <p class="text-gray-500 mb-8">Recherchez des personnes, publications, communautés ou universités</p>
            
            <!-- Suggestions populaires -->
            <div class="max-w-2xl mx-auto">
                <p class="text-sm text-gray-500 mb-4">Suggestions populaires :</p>
                <div class="flex flex-wrap justify-center gap-2">
                    <a href="?q=étudiant" class="px-4 py-2 bg-gray-100 rounded-full text-sm hover:bg-orange-100 hover:text-orange-600 transition-colors">étudiant</a>
                    <a href="?q=université" class="px-4 py-2 bg-gray-100 rounded-full text-sm hover:bg-orange-100 hover:text-orange-600 transition-colors">université</a>
                    <a href="?q=mémoire" class="px-4 py-2 bg-gray-100 rounded-full text-sm hover:bg-orange-100 hover:text-orange-600 transition-colors">mémoire</a>
                    <a href="?q=licence" class="px-4 py-2 bg-gray-100 rounded-full text-sm hover:bg-orange-100 hover:text-orange-600 transition-colors">licence</a>
                    <a href="?q=master" class="px-4 py-2 bg-gray-100 rounded-full text-sm hover:bg-orange-100 hover:text-orange-600 transition-colors">master</a>
                    <a href="?q=doctorat" class="px-4 py-2 bg-gray-100 rounded-full text-sm hover:bg-orange-100 hover:text-orange-600 transition-colors">doctorat</a>
                </div>
            </div>
        </div>
        
        <?php endif; ?>
    </div>

    <script>
        // Fonctions AJAX pour les actions
        const csrfToken = '<?= $csrfToken ?>';
        
        async function sendFriendRequest(userId) {
            const formData = new FormData();
            formData.append('action', 'send_request');
            formData.append('user_id', userId);
            formData.append('csrf_token', csrfToken);
            
            try {
                const response = await fetch('api/friends.php', {
                    method: 'POST',
                    body: formData
                });
                const data = await response.json();
                if (data.success) {
                    location.reload();
                } else {
                    alert(data.error);
                }
            } catch (err) {
                alert('Erreur de connexion');
            }
        }
        
        async function acceptFriendRequest(userId) {
            const formData = new FormData();
            formData.append('action', 'accept_request');
            formData.append('user_id', userId);
            formData.append('csrf_token', csrfToken);
            
            try {
                const response = await fetch('api/friends.php', {
                    method: 'POST',
                    body: formData
                });
                const data = await response.json();
                if (data.success) {
                    location.reload();
                }
            } catch (err) {
                alert('Erreur de connexion');
            }
        }
        
        async function rejectFriendRequest(userId) {
            const formData = new FormData();
            formData.append('action', 'reject_request');
            formData.append('user_id', userId);
            formData.append('csrf_token', csrfToken);
            
            try {
                const response = await fetch('api/friends.php', {
                    method: 'POST',
                    body: formData
                });
                const data = await response.json();
                if (data.success) {
                    location.reload();
                }
            } catch (err) {
                alert('Erreur de connexion');
            }
        }
        
        async function joinCommunity(communityId) {
            const formData = new FormData();
            formData.append('action', 'join');
            formData.append('community_id', communityId);
            formData.append('csrf_token', csrfToken);
            
            try {
                const response = await fetch('api/communities.php', {
                    method: 'POST',
                    body: formData
                });
                const data = await response.json();
                if (data.success) {
                    location.reload();
                }
            } catch (err) {
                alert('Erreur de connexion');
            }
        }
        
        // Suggestions en temps réel
        let searchTimeout;
        const searchInput = document.getElementById('searchInput');
        const suggestionsDiv = document.getElementById('suggestions');
        
        if (searchInput) {
            searchInput.addEventListener('input', function() {
                clearTimeout(searchTimeout);
                const query = this.value.trim();
                
                if (query.length < 2) {
                    suggestionsDiv.classList.add('hidden');
                    return;
                }
                
                searchTimeout = setTimeout(async () => {
                    try {
                        const response = await fetch(`api/search.php?q=${encodeURIComponent(query)}&limit=5`);
                        const data = await response.json();
                        
                        if (data.success && data.suggestions && data.suggestions.length > 0) {
                            suggestionsDiv.innerHTML = data.suggestions.map(s => `
                                <a href="?q=${encodeURIComponent(s.query)}" class="flex items-center gap-3 p-3 hover:bg-gray-50 transition-colors border-b border-gray-100">
                                    <i class="fas fa-search text-gray-400"></i>
                                    <span class="text-gray-700">${escapeHtml(s.query)}</span>
                                    <span class="text-xs text-gray-400 ml-auto">${s.count} recherches</span>
                                </a>
                            `).join('');
                            suggestionsDiv.classList.remove('hidden');
                        } else {
                            suggestionsDiv.classList.add('hidden');
                        }
                    } catch (err) {
                        console.error('Erreur suggestions:', err);
                    }
                }, 300);
            });
            
            // Cacher les suggestions au clic en dehors
            document.addEventListener('click', (e) => {
                if (!searchInput.contains(e.target) && !suggestionsDiv.contains(e.target)) {
                    suggestionsDiv.classList.add('hidden');
                }
            });
        }
        
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        function highlightText(text, query) {
            if (!text || !query) return text;
            const regex = new RegExp(`(${query.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')})`, 'gi');
            return text.replace(regex, '<mark class="search-highlight">$1</mark>');
        }
    </script>
</body>
</html>

<?php
// Fonction d'highlight pour PHP
function highlightText($text, $query) {
    if (empty($text) || empty($query)) return htmlspecialchars($text);
    $pattern = '/(' . preg_quote($query, '/') . ')/i';
    return preg_replace($pattern, '<mark class="search-highlight">$1</mark>', htmlspecialchars($text));
}
?>