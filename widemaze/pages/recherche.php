<?php
/**
 * WideMaze - Moteur de Recherche Intelligent
 * Version 5.0 - Recherche sémantique, filtres avancés, suggestions en temps réel
 * Combine le meilleur des versions précédentes avec des fonctionnalités IA-like
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_auth();

$userId = $_SESSION['user_id'];
$query = trim($_GET['q'] ?? '');
$type = $_GET['type'] ?? 'all'; // all, users, posts, communities
$page = max(1, intval($_GET['page'] ?? 1));
$limit = 20;
$offset = ($page - 1) * $limit;
$sort = $_GET['sort'] ?? 'relevance'; // relevance, date, popularity
$dateFilter = $_GET['date'] ?? 'all'; // all, today, week, month, year
$university = trim($_GET['university'] ?? '');
$country = trim($_GET['country'] ?? '');
$category = trim($_GET['category'] ?? '');
$hasImage = isset($_GET['has_image']) && $_GET['has_image'] == '1';

$results = ['users' => [], 'posts' => [], 'communities' => []];
$totalCount = 0;
$suggestions = [];
$relatedSearches = [];
$facetFilters = ['universities' => [], 'countries' => [], 'categories' => []];
$searchTime = microtime(true);

// ==================== FONCTIONS DE RECHERCHE AVANCÉES ====================

function highlightText($text, $query) {
    if (empty($text) || empty($query)) return htmlspecialchars($text);
    $words = explode(' ', $query);
    $pattern = '/(' . implode('|', array_map('preg_quote', $words)) . ')/i';
    return preg_replace($pattern, '<mark class="search-highlight bg-yellow-200 text-gray-900 px-0.5 rounded">$1</mark>', htmlspecialchars($text));
}

function calculateRelevance($text, $query, $boost = 1) {
    if (empty($text) || empty($query)) return 0;
    $textLower = strtolower($text);
    $queryLower = strtolower($query);
    $words = explode(' ', $queryLower);
    $score = 0;
    foreach ($words as $word) {
        if (strpos($textLower, $word) !== false) {
            $score += substr_count($textLower, $word) * $boost;
        }
    }
    // Bonus pour correspondance exacte en début de phrase
    if (stripos($text, $query) === 0) $score += 5;
    return $score;
}

// ==================== EXÉCUTION DE LA RECHERCHE ====================

if (!empty($query) && strlen($query) >= 2) {
    $searchTerm = "%$query%";
    $exactTerm = "$query%";
    
    // 1. RECHERCHE D'UTILISATEURS AVEC SCORE DE PERTINENCE
    if ($type == 'all' || $type == 'users') {
        $sql = "
            SELECT u.id, u.surnom, u.prenom, u.nom, u.avatar, u.universite, u.faculte, u.status, u.profession, u.nationalite, u.is_verified,
                'user' as result_type,
                CASE 
                    WHEN a.accepterami = 1 THEN 'friends'
                    WHEN a.demandeami = 1 AND a.id = ? THEN 'pending_sent'
                    WHEN a.demandeami = 1 AND a.idami = ? THEN 'pending_received'
                    ELSE 'none'
                END as friendship_status,
                (SELECT COUNT(*) FROM ami WHERE (id = u.id OR idami = u.id) AND accepterami = 1) as friends_count,
                (SELECT COUNT(*) FROM posts WHERE id_utilisateur = u.id) as posts_count,
                (CASE 
                    WHEN u.surnom LIKE ? THEN 10
                    WHEN u.prenom LIKE ? THEN 8
                    WHEN u.nom LIKE ? THEN 8
                    WHEN u.universite LIKE ? THEN 5
                    WHEN u.faculte LIKE ? THEN 4
                    WHEN u.profession LIKE ? THEN 3
                    ELSE 0
                END) as relevance_score
            FROM utilisateurs u
            LEFT JOIN ami a ON (a.id = u.id AND a.idami = ?) OR (a.idami = u.id AND a.id = ?)
            WHERE (u.surnom LIKE ? OR u.prenom LIKE ? OR u.nom LIKE ? OR u.email LIKE ? OR u.universite LIKE ? OR u.faculte LIKE ? OR u.profession LIKE ?)
                AND u.id != ?
                AND u.is_active = 1
        ";
        $params = [$userId, $userId, $exactTerm, $exactTerm, $exactTerm, $searchTerm, $searchTerm, $searchTerm, $userId, $userId, $searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm, $userId];
        
        if (!empty($university)) {
            $sql .= " AND u.universite = ?";
            $params[] = $university;
        }
        if (!empty($country)) {
            $sql .= " AND u.nationalite = ?";
            $params[] = $country;
        }
        
        $sql .= " ORDER BY relevance_score DESC, u.surnom ASC LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $results['users'] = $stmt->fetchAll();
        $totalCount += count($results['users']);
    }
    
    // 2. RECHERCHE DE POSTS AVEC ANALYSE SÉMANTIQUE
    if ($type == 'all' || $type == 'posts') {
        $sql = "
            SELECT p.idpost, p.contenu, p.image_post, p.date_publication, p.privacy, 'post' as result_type,
                u.id as user_id, u.surnom, u.avatar, u.prenom, u.nom,
                (SELECT COUNT(*) FROM postlike WHERE idpost = p.idpost) as likes_count,
                (SELECT COUNT(*) FROM postcommentaire WHERE idpost = p.idpost) as comments_count,
                (SELECT EXISTS(SELECT 1 FROM postlike WHERE idpost = p.idpost AND id = ?)) as user_liked,
                (CASE 
                    WHEN p.contenu LIKE ? THEN 10
                    WHEN p.contenu LIKE ? THEN 5
                    ELSE 1
                END) as relevance_score
            FROM posts p
            JOIN utilisateurs u ON p.id_utilisateur = u.id
            WHERE (p.contenu LIKE ? OR p.contenu LIKE ?)
                AND (p.privacy = 'public' OR p.id_utilisateur = ? OR p.id_utilisateur IN (
                    SELECT CASE WHEN id = ? THEN idami ELSE id END
                    FROM ami WHERE (id = ? OR idami = ?) AND accepterami = 1
                ))
        ";
        $params = [$userId, "%$query%", "%$query%", "%$query%", "%$query%", $userId, $userId, $userId, $userId];
        
        if ($hasImage) {
            $sql .= " AND p.image_post IS NOT NULL";
        }
        
        if ($dateFilter != 'all') {
            $dateConditions = [
                'today' => "AND DATE(p.date_publication) = CURDATE()",
                'week' => "AND p.date_publication > DATE_SUB(NOW(), INTERVAL 7 DAY)",
                'month' => "AND p.date_publication > DATE_SUB(NOW(), INTERVAL 1 MONTH)",
                'year' => "AND p.date_publication > DATE_SUB(NOW(), INTERVAL 1 YEAR)"
            ];
            if (isset($dateConditions[$dateFilter])) {
                $sql .= " " . $dateConditions[$dateFilter];
            }
        }
        
        if ($sort == 'date') {
            $sql .= " ORDER BY p.date_publication DESC";
        } elseif ($sort == 'popularity') {
            $sql .= " ORDER BY likes_count DESC, comments_count DESC";
        } else {
            $sql .= " ORDER BY relevance_score DESC, p.date_publication DESC";
        }
        
        $sql .= " LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $results['posts'] = $stmt->fetchAll();
        $totalCount += count($results['posts']);
    }
    
    // 3. RECHERCHE DE COMMUNAUTÉS AVEC FILTRES
    if ($type == 'all' || $type == 'communities') {
        try {
            $sql = "
                SELECT c.id_communaute, c.nom, c.description, c.categorie, c.image_couverture, 'community' as result_type,
                    (SELECT COUNT(*) FROM communaute_membres WHERE id_communaute = c.id_communaute) as members_count,
                    EXISTS(SELECT 1 FROM communaute_membres WHERE id_communaute = c.id_communaute AND id_utilisateur = ?) as is_member,
                    (SELECT COUNT(*) FROM posts WHERE id_communaute = c.id_communaute) as posts_count,
                    (CASE 
                        WHEN c.nom LIKE ? THEN 10
                        WHEN c.description LIKE ? THEN 5
                        ELSE 1
                    END) as relevance_score
                FROM communautes c
                WHERE (c.nom LIKE ? OR c.description LIKE ?)
                    AND c.is_active = 1
            ";
            $params = [$userId, "%$query%", "%$query%", "%$query%", "%$query%"];
            
            if (!empty($category)) {
                $sql .= " AND c.categorie = ?";
                $params[] = $category;
            }
            
            $sql .= " ORDER BY relevance_score DESC, members_count DESC LIMIT ? OFFSET ?";
            $params[] = $limit;
            $params[] = $offset;
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $results['communities'] = $stmt->fetchAll();
            $totalCount += count($results['communities']);
        } catch (PDOException $e) {
            error_log("Error searching communities: " . $e->getMessage());
        }
    }
    
    // 4. GÉNÉRATION DE SUGGESTIONS INTELLIGENTES
    try {
        // Suggestions basées sur l'historique de recherche
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
        
        // Recherches connexes (expansion sémantique)
        $relatedSearches = [];
        $semanticMap = [
            'étudiant' => ['étudiante', 'élève', 'apprenant', 'stagiaire'],
            'université' => ['faculté', 'campus', 'école', 'institut'],
            'mémoire' => ['thèse', 'dissertation', 'recherche', 'article'],
            'licence' => ['bachelor', 'undergraduate', 'L1', 'L2', 'L3'],
            'master' => ['M1', 'M2', 'graduate', 'postgraduate'],
            'doctorat' => ['phd', 'doctorant', 'recherche'],
            'informatique' => ['informatique', 'programmation', 'dev', 'coding', 'python', 'java'],
            'médecine' => ['médecin', 'santé', 'hôpital', 'clinique'],
            'droit' => ['juridique', 'avocat', 'justice', 'loi'],
            'économie' => ['commerce', 'gestion', 'finance', 'marketing']
        ];
        
        $queryLower = strtolower($query);
        foreach ($semanticMap as $key => $synonyms) {
            if (strpos($queryLower, $key) !== false || $key === $queryLower) {
                foreach ($synonyms as $syn) {
                    $relatedSearches[] = str_replace($key, $syn, $query);
                }
            }
        }
        $relatedSearches = array_unique($relatedSearches);
        
    } catch (PDOException $e) {
        error_log("Error generating suggestions: " . $e->getMessage());
    }
    
    // 5. FACETTES POUR FILTRES (Universités et Pays populaires)
    try {
        // Universités populaires
        $stmt = $pdo->prepare("
            SELECT universite, COUNT(*) as count 
            FROM utilisateurs 
            WHERE (surnom LIKE ? OR prenom LIKE ? OR nom LIKE ? OR universite LIKE ?)
                AND universite IS NOT NULL AND universite != ''
            GROUP BY universite 
            ORDER BY count DESC 
            LIMIT 15
        ");
        $stmt->execute([$searchTerm, $searchTerm, $searchTerm, $searchTerm]);
        $facetFilters['universities'] = $stmt->fetchAll();
        
        // Pays populaires
        $stmt = $pdo->prepare("
            SELECT nationalite, COUNT(*) as count 
            FROM utilisateurs 
            WHERE (surnom LIKE ? OR prenom LIKE ? OR nom LIKE ?)
                AND nationalite IS NOT NULL AND nationalite != ''
            GROUP BY nationalite 
            ORDER BY count DESC 
            LIMIT 15
        ");
        $stmt->execute([$searchTerm, $searchTerm, $searchTerm]);
        $facetFilters['countries'] = $stmt->fetchAll();
        
        // Catégories de communautés populaires
        $stmt = $pdo->prepare("
            SELECT categorie, COUNT(*) as count 
            FROM communautes 
            WHERE (nom LIKE ? OR description LIKE ?)
                AND categorie IS NOT NULL
            GROUP BY categorie 
            ORDER BY count DESC 
            LIMIT 10
        ");
        $stmt->execute([$searchTerm, $searchTerm]);
        $facetFilters['categories'] = $stmt->fetchAll();
        
    } catch (PDOException $e) {
        error_log("Error fetching facets: " . $e->getMessage());
    }
    
    // 6. ENREGISTREMENT DE L'HISTORIQUE DE RECHERCHE
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
    log_activity($pdo, $userId, 'search', ['query' => $query, 'type' => $type, 'results' => $totalCount]);
}

// Récupération des universités et pays pour les filtres
$allUniversities = [];
$allCountries = [];
try {
    $stmt = $pdo->query("SELECT DISTINCT universite FROM utilisateurs WHERE universite IS NOT NULL AND universite != '' ORDER BY universite LIMIT 100");
    $allUniversities = $stmt->fetchAll();
    
    $stmt = $pdo->query("SELECT DISTINCT nationalite FROM utilisateurs WHERE nationalite IS NOT NULL AND nationalite != '' ORDER BY nationalite LIMIT 100");
    $allCountries = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Error fetching filters: " . $e->getMessage());
}

// Récupération des tendances de recherche
$trendingSearches = [];
try {
    $stmt = $pdo->prepare("
        SELECT query, COUNT(*) as count 
        FROM search_history 
        WHERE searched_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
        GROUP BY query 
        ORDER BY count DESC 
        LIMIT 10
    ");
    $stmt->execute();
    $trendingSearches = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Error fetching trending: " . $e->getMessage());
}

$searchTime = round((microtime(true) - $searchTime) * 1000, 2);
$totalPages = ceil($totalCount / $limit);
$csrfToken = generate_csrf_token();
$page_title = !empty($query) ? "$query - Recherche" : "Recherche avancée";
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title><?= htmlspecialchars($page_title) ?> - WideMaze</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { font-family: 'Inter', sans-serif; }
        body { background: linear-gradient(135deg, #f5f7fa 0%, #eef2f7 100%); min-height: 100vh; }
        
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        @keyframes slideIn {
            from { opacity: 0; transform: translateX(-20px); }
            to { opacity: 1; transform: translateX(0); }
        }
        
        .search-highlight {
            background-color: #fef3c7;
            padding: 0 2px;
            border-radius: 4px;
            font-weight: 500;
        }
        .result-card {
            animation: fadeInUp 0.3s ease-out;
            transition: all 0.2s;
        }
        .result-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px -5px rgba(0,0,0,0.1);
        }
        .filter-active {
            background: linear-gradient(135deg, #f59e0b, #ea580c);
            color: white;
            border-color: transparent;
        }
        .suggestion-item {
            transition: all 0.2s;
        }
        .suggestion-item:hover {
            background-color: #fef3c7;
            transform: translateX(4px);
        }
        .facet-item {
            transition: all 0.2s;
        }
        .facet-item:hover {
            background-color: #fef3c7;
        }
        .skeleton {
            background: linear-gradient(90deg, #f0f0f0 25%, #e0e0e0 50%, #f0f0f0 75%);
            background-size: 200% 100%;
            animation: loading 1.5s infinite;
        }
        @keyframes loading {
            0% { background-position: 200% 0; }
            100% { background-position: -200% 0; }
        }
        .toast {
            animation: fadeInUp 0.3s ease-out;
        }
    </style>
</head>
<body class="min-h-screen">
    
    <!-- Navigation Flottante -->
    <nav class="fixed top-4 left-1/2 -translate-x-1/2 w-[95%] max-w-7xl bg-white/95 backdrop-blur-md rounded-2xl shadow-lg border border-gray-100 z-50 px-6 py-3">
        <div class="flex items-center justify-between">
            <a href="../index.php" class="flex items-center gap-3 group">
                <div class="w-10 h-10 bg-gradient-to-br from-orange-500 to-red-600 rounded-xl flex items-center justify-center shadow-lg group-hover:scale-110 transition-transform">
                    <i class="fas fa-network-wired text-white text-lg"></i>
                </div>
                <span class="text-2xl font-bold bg-gradient-to-r from-orange-500 to-red-600 bg-clip-text text-transparent hidden sm:block">WideMaze</span>
            </a>
            
            <div class="flex items-center gap-4">
                <a href="../index.php" class="p-2.5 hover:bg-gray-100 rounded-xl transition-all relative group">
                    <i class="fas fa-home text-xl text-gray-600 group-hover:text-orange-500 transition-colors"></i>
                </a>
                <a href="notifications.php" class="p-2.5 hover:bg-gray-100 rounded-xl transition-all relative group">
                    <i class="fas fa-bell text-xl text-gray-600 group-hover:text-orange-500 transition-colors"></i>
                </a>
                <a href="messagerie.php" class="p-2.5 hover:bg-gray-100 rounded-xl transition-all relative group">
                    <i class="fas fa-comment-dots text-xl text-gray-600 group-hover:text-orange-500 transition-colors"></i>
                </a>
                <div class="relative group">
                    <button class="flex items-center gap-2 p-1.5 hover:bg-gray-100 rounded-xl transition-all">
                        <img src="<?= get_avatar_url($_SESSION['avatar'] ?? '') ?>" class="w-9 h-9 rounded-full object-cover border-2 border-orange-300">
                        <i class="fas fa-chevron-down text-xs text-gray-400 hidden sm:block"></i>
                    </button>
                    <div class="absolute right-0 top-full mt-3 w-64 bg-white rounded-2xl shadow-xl border border-gray-100 opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all z-50">
                        <div class="p-4 border-b border-gray-100 bg-gradient-to-r from-orange-50 to-red-50">
                            <p class="font-bold text-gray-800"><?= htmlspecialchars($_SESSION['prenom'] . ' ' . $_SESSION['nom']) ?></p>
                            <p class="text-sm text-gray-500">@<?= htmlspecialchars($_SESSION['surnom']) ?></p>
                        </div>
                        <div class="p-2">
                            <a href="profil.php" class="flex items-center gap-3 px-4 py-2.5 hover:bg-gray-50 rounded-xl"><i class="fas fa-user text-gray-400 w-5"></i>Mon profil</a>
                            <a href="parametres.php" class="flex items-center gap-3 px-4 py-2.5 hover:bg-gray-50 rounded-xl"><i class="fas fa-cog text-gray-400 w-5"></i>Paramètres</a>
                        </div>
                        <div class="p-2 border-t border-gray-100">
                            <a href="deconnexion.php" class="flex items-center gap-3 px-4 py-2.5 hover:bg-red-50 rounded-xl text-red-600"><i class="fas fa-sign-out-alt w-5"></i>Déconnexion</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </nav>
    
    <div class="container mx-auto pt-24 pb-8 px-4 max-w-7xl">
        
        <!-- Hero Section avec Barre de Recherche Avancée -->
        <div class="mb-10">
            <div class="text-center mb-6">
                <h1 class="text-3xl md:text-4xl font-bold text-gray-800 mb-3">
                    <i class="fas fa-search text-orange-500 mr-2"></i>
                    Recherche <span class="bg-gradient-to-r from-orange-500 to-red-500 bg-clip-text text-transparent">intelligente</span>
                </h1>
                <p class="text-gray-500">Trouvez des personnes, publications et communautés académiques</p>
            </div>
            
            <form method="get" action="" id="searchForm" class="relative">
                <div class="relative">
                    <div class="absolute inset-y-0 left-0 pl-5 flex items-center pointer-events-none">
                        <i class="fas fa-search text-gray-400 text-xl"></i>
                    </div>
                    <input type="text" name="q" id="searchInput" value="<?= htmlspecialchars($query) ?>" 
                           placeholder="Rechercher des personnes, publications, communautés, universités..."
                           class="w-full pl-14 pr-36 py-5 text-lg border-2 border-gray-200 rounded-2xl focus:border-orange-500 focus:outline-none focus:ring-4 focus:ring-orange-100 transition-all shadow-sm"
                           autocomplete="off">
                    <div class="absolute right-2 top-1/2 -translate-y-1/2 flex gap-2">
                        <button type="submit" class="bg-gradient-to-r from-orange-500 to-red-500 text-white px-6 py-2.5 rounded-xl font-medium transition-all hover:shadow-lg">
                            <i class="fas fa-search mr-2"></i>Rechercher
                        </button>
                    </div>
                </div>
                
                <!-- Suggestions en temps réel -->
                <div id="suggestionsDropdown" class="absolute top-full left-0 right-0 mt-2 bg-white rounded-2xl shadow-xl border border-gray-200 hidden z-50 overflow-hidden">
                    <div id="suggestionsList" class="max-h-96 overflow-y-auto"></div>
                </div>
            </form>
            
            <?php if (!empty($query)): ?>
                <div class="flex flex-wrap items-center justify-between gap-4 mt-4">
                    <div class="flex items-center gap-4 text-sm text-gray-500">
                        <span><i class="fas fa-chart-line text-orange-500"></i> <?= number_format($totalCount) ?> résultat<?= $totalCount > 1 ? 's' : '' ?></span>
                        <span><i class="fas fa-clock text-orange-500"></i> <?= $searchTime ?> ms</span>
                        <?php if (!empty($query)): ?>
                            <span><i class="fas fa-search text-orange-500"></i> "<?= htmlspecialchars($query) ?>"</span>
                        <?php endif; ?>
                    </div>
                    <div class="flex items-center gap-3">
                        <button onclick="toggleFilters()" class="text-sm text-orange-500 hover:text-orange-600 flex items-center gap-1">
                            <i class="fas fa-sliders-h"></i> Filtres avancés
                        </button>
                        <?php if (!empty($university) || !empty($country) || !empty($category) || $hasImage || $dateFilter != 'all'): ?>
                            <a href="recherche.php?q=<?= urlencode($query) ?>&type=<?= $type ?>" class="text-xs bg-gray-100 hover:bg-gray-200 px-2 py-1 rounded-full transition-colors">
                                <i class="fas fa-times-circle mr-1"></i>Effacer les filtres
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        
        <?php if (!empty($query)): ?>
            
            <!-- Filtres horizontaux par type -->
            <div class="flex flex-wrap gap-2 mb-6 border-b border-gray-200 pb-4">
                <a href="?q=<?= urlencode($query) ?>&type=all&sort=<?= $sort ?>&date=<?= $dateFilter ?>&university=<?= urlencode($university) ?>&country=<?= urlencode($country) ?>&category=<?= urlencode($category) ?><?= $hasImage ? '&has_image=1' : '' ?>" 
                   class="px-5 py-2.5 rounded-full text-sm font-medium transition-all <?= $type === 'all' ? 'filter-active shadow-md' : 'bg-white text-gray-600 hover:bg-gray-100 border border-gray-200' ?>">
                    <i class="fas fa-globe mr-2"></i>Tous
                    <?php if ($type === 'all' && $totalCount > 0): ?>
                        <span class="ml-1 text-xs">(<?= $totalCount ?>)</span>
                    <?php endif; ?>
                </a>
                <a href="?q=<?= urlencode($query) ?>&type=users&sort=<?= $sort ?>&date=<?= $dateFilter ?>&university=<?= urlencode($university) ?>&country=<?= urlencode($country) ?>&category=<?= urlencode($category) ?><?= $hasImage ? '&has_image=1' : '' ?>" 
                   class="px-5 py-2.5 rounded-full text-sm font-medium transition-all <?= $type === 'users' ? 'filter-active shadow-md' : 'bg-white text-gray-600 hover:bg-gray-100 border border-gray-200' ?>">
                    <i class="fas fa-users mr-2"></i>Personnes
                    <?php if (isset($results['users']) && count($results['users']) > 0): ?>
                        <span class="ml-1 text-xs">(<?= count($results['users']) ?>)</span>
                    <?php endif; ?>
                </a>
                <a href="?q=<?= urlencode($query) ?>&type=posts&sort=<?= $sort ?>&date=<?= $dateFilter ?>&university=<?= urlencode($university) ?>&country=<?= urlencode($country) ?>&category=<?= urlencode($category) ?><?= $hasImage ? '&has_image=1' : '' ?>" 
                   class="px-5 py-2.5 rounded-full text-sm font-medium transition-all <?= $type === 'posts' ? 'filter-active shadow-md' : 'bg-white text-gray-600 hover:bg-gray-100 border border-gray-200' ?>">
                    <i class="fas fa-newspaper mr-2"></i>Publications
                    <?php if (isset($results['posts']) && count($results['posts']) > 0): ?>
                        <span class="ml-1 text-xs">(<?= count($results['posts']) ?>)</span>
                    <?php endif; ?>
                </a>
                <a href="?q=<?= urlencode($query) ?>&type=communities&sort=<?= $sort ?>&date=<?= $dateFilter ?>&university=<?= urlencode($university) ?>&country=<?= urlencode($country) ?>&category=<?= urlencode($category) ?><?= $hasImage ? '&has_image=1' : '' ?>" 
                   class="px-5 py-2.5 rounded-full text-sm font-medium transition-all <?= $type === 'communities' ? 'filter-active shadow-md' : 'bg-white text-gray-600 hover:bg-gray-100 border border-gray-200' ?>">
                    <i class="fas fa-university mr-2"></i>Communautés
                    <?php if (isset($results['communities']) && count($results['communities']) > 0): ?>
                        <span class="ml-1 text-xs">(<?= count($results['communities']) ?>)</span>
                    <?php endif; ?>
                </a>
            </div>
            
            <div class="grid lg:grid-cols-4 gap-6">
                
                <!-- Sidebar - Filtres Avancés -->
                <div class="lg:col-span-1 space-y-5" id="filtersSidebar">
                    
                    <!-- Filtre de date -->
                    <div class="bg-white rounded-2xl shadow-sm p-5 border border-gray-100">
                        <h3 class="font-bold text-gray-800 mb-3 flex items-center gap-2">
                            <i class="fas fa-calendar-alt text-orange-500"></i>Période
                        </h3>
                        <div class="space-y-2">
                            <a href="?q=<?= urlencode($query) ?>&type=<?= $type ?>&sort=<?= $sort ?>&date=all&university=<?= urlencode($university) ?>&country=<?= urlencode($country) ?>&category=<?= urlencode($category) ?><?= $hasImage ? '&has_image=1' : '' ?>" 
                               class="block px-3 py-2 rounded-lg text-sm transition-all <?= $dateFilter === 'all' ? 'bg-orange-50 text-orange-600 font-medium' : 'text-gray-600 hover:bg-gray-50' ?>">
                                📅 Toutes les dates
                            </a>
                            <a href="?q=<?= urlencode($query) ?>&type=<?= $type ?>&sort=<?= $sort ?>&date=today&university=<?= urlencode($university) ?>&country=<?= urlencode($country) ?>&category=<?= urlencode($category) ?><?= $hasImage ? '&has_image=1' : '' ?>" 
                               class="block px-3 py-2 rounded-lg text-sm transition-all <?= $dateFilter === 'today' ? 'bg-orange-50 text-orange-600 font-medium' : 'text-gray-600 hover:bg-gray-50' ?>">
                                📆 Aujourd'hui
                            </a>
                            <a href="?q=<?= urlencode($query) ?>&type=<?= $type ?>&sort=<?= $sort ?>&date=week&university=<?= urlencode($university) ?>&country=<?= urlencode($country) ?>&category=<?= urlencode($category) ?><?= $hasImage ? '&has_image=1' : '' ?>" 
                               class="block px-3 py-2 rounded-lg text-sm transition-all <?= $dateFilter === 'week' ? 'bg-orange-50 text-orange-600 font-medium' : 'text-gray-600 hover:bg-gray-50' ?>">
                                📊 Cette semaine
                            </a>
                            <a href="?q=<?= urlencode($query) ?>&type=<?= $type ?>&sort=<?= $sort ?>&date=month&university=<?= urlencode($university) ?>&country=<?= urlencode($country) ?>&category=<?= urlencode($category) ?><?= $hasImage ? '&has_image=1' : '' ?>" 
                               class="block px-3 py-2 rounded-lg text-sm transition-all <?= $dateFilter === 'month' ? 'bg-orange-50 text-orange-600 font-medium' : 'text-gray-600 hover:bg-gray-50' ?>">
                                📈 Ce mois
                            </a>
                            <a href="?q=<?= urlencode($query) ?>&type=<?= $type ?>&sort=<?= $sort ?>&date=year&university=<?= urlencode($university) ?>&country=<?= urlencode($country) ?>&category=<?= urlencode($category) ?><?= $hasImage ? '&has_image=1' : '' ?>" 
                               class="block px-3 py-2 rounded-lg text-sm transition-all <?= $dateFilter === 'year' ? 'bg-orange-50 text-orange-600 font-medium' : 'text-gray-600 hover:bg-gray-50' ?>">
                                📅 Cette année
                            </a>
                        </div>
                    </div>
                    
                    <!-- Filtre universités -->
                    <?php if (!empty($facetFilters['universities'])): ?>
                        <div class="bg-white rounded-2xl shadow-sm p-5 border border-gray-100">
                            <h3 class="font-bold text-gray-800 mb-3 flex items-center gap-2">
                                <i class="fas fa-graduation-cap text-orange-500"></i>Universités
                            </h3>
                            <div class="space-y-2 max-h-64 overflow-y-auto">
                                <?php foreach ($facetFilters['universities'] as $uni): ?>
                                    <a href="?q=<?= urlencode($query) ?>&type=<?= $type ?>&sort=<?= $sort ?>&date=<?= $dateFilter ?>&university=<?= urlencode($uni['universite']) ?>&country=<?= urlencode($country) ?>&category=<?= urlencode($category) ?><?= $hasImage ? '&has_image=1' : '' ?>" 
                                       class="facet-item flex items-center justify-between px-3 py-2 rounded-lg text-sm transition-all <?= $university === $uni['universite'] ? 'bg-orange-50 text-orange-600 font-medium' : 'text-gray-600 hover:bg-gray-50' ?>">
                                        <span class="truncate">🏛️ <?= htmlspecialchars($uni['universite']) ?></span>
                                        <span class="text-xs text-gray-400"><?= number_format($uni['count']) ?></span>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                            <?php if (count($facetFilters['universities']) >= 10): ?>
                                <a href="#" onclick="showMoreUniversities()" class="text-xs text-orange-500 mt-2 block text-center">Voir plus</a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Filtre pays -->
                    <?php if (!empty($facetFilters['countries'])): ?>
                        <div class="bg-white rounded-2xl shadow-sm p-5 border border-gray-100">
                            <h3 class="font-bold text-gray-800 mb-3 flex items-center gap-2">
                                <i class="fas fa-map-marker-alt text-orange-500"></i>Pays
                            </h3>
                            <div class="space-y-2 max-h-64 overflow-y-auto">
                                <?php foreach ($facetFilters['countries'] as $c): ?>
                                    <a href="?q=<?= urlencode($query) ?>&type=<?= $type ?>&sort=<?= $sort ?>&date=<?= $dateFilter ?>&university=<?= urlencode($university) ?>&country=<?= urlencode($c['nationalite']) ?>&category=<?= urlencode($category) ?><?= $hasImage ? '&has_image=1' : '' ?>" 
                                       class="facet-item flex items-center justify-between px-3 py-2 rounded-lg text-sm transition-all <?= $country === $c['nationalite'] ? 'bg-orange-50 text-orange-600 font-medium' : 'text-gray-600 hover:bg-gray-50' ?>">
                                        <span>🌍 <?= htmlspecialchars($c['nationalite']) ?></span>
                                        <span class="text-xs text-gray-400"><?= number_format($c['count']) ?></span>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Filtre catégories communautés -->
                    <?php if (!empty($facetFilters['categories']) && ($type == 'all' || $type == 'communities')): ?>
                        <div class="bg-white rounded-2xl shadow-sm p-5 border border-gray-100">
                            <h3 class="font-bold text-gray-800 mb-3 flex items-center gap-2">
                                <i class="fas fa-tags text-orange-500"></i>Catégories
                            </h3>
                            <div class="space-y-2">
                                <?php foreach ($facetFilters['categories'] as $cat): ?>
                                    <?php
                                    $catIcons = [
                                        'Academic' => '🎓', 'Club' => '👥', 'Social' => '❤️', 'Sports' => '⚽',
                                        'Arts' => '🎨', 'Tech' => '💻', 'Career' => '💼'
                                    ];
                                    $icon = $catIcons[$cat['categorie']] ?? '📁';
                                    ?>
                                    <a href="?q=<?= urlencode($query) ?>&type=<?= $type ?>&sort=<?= $sort ?>&date=<?= $dateFilter ?>&university=<?= urlencode($university) ?>&country=<?= urlencode($country) ?>&category=<?= urlencode($cat['categorie']) ?><?= $hasImage ? '&has_image=1' : '' ?>" 
                                       class="facet-item flex items-center justify-between px-3 py-2 rounded-lg text-sm transition-all <?= $category === $cat['categorie'] ? 'bg-orange-50 text-orange-600 font-medium' : 'text-gray-600 hover:bg-gray-50' ?>">
                                        <span><?= $icon ?> <?= htmlspecialchars($cat['categorie']) ?></span>
                                        <span class="text-xs text-gray-400"><?= number_format($cat['count']) ?></span>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Filtre images -->
                    <div class="bg-white rounded-2xl shadow-sm p-5 border border-gray-100">
                        <h3 class="font-bold text-gray-800 mb-3 flex items-center gap-2">
                            <i class="fas fa-image text-orange-500"></i>Médias
                        </h3>
                        <label class="flex items-center gap-3 cursor-pointer">
                            <input type="checkbox" id="hasImageFilter" <?= $hasImage ? 'checked' : '' ?> onchange="toggleImageFilter()" class="w-4 h-4 text-orange-500 rounded focus:ring-orange-500">
                            <span class="text-sm text-gray-600">Uniquement avec images</span>
                        </label>
                    </div>
                    
                    <!-- Tri -->
                    <div class="bg-white rounded-2xl shadow-sm p-5 border border-gray-100">
                        <h3 class="font-bold text-gray-800 mb-3 flex items-center gap-2">
                            <i class="fas fa-sort-amount-down text-orange-500"></i>Trier par
                        </h3>
                        <div class="space-y-2">
                            <a href="?q=<?= urlencode($query) ?>&type=<?= $type ?>&sort=relevance&date=<?= $dateFilter ?>&university=<?= urlencode($university) ?>&country=<?= urlencode($country) ?>&category=<?= urlencode($category) ?><?= $hasImage ? '&has_image=1' : '' ?>" 
                               class="block px-3 py-2 rounded-lg text-sm <?= $sort == 'relevance' ? 'bg-orange-50 text-orange-600 font-medium' : 'text-gray-600 hover:bg-gray-50' ?>">
                                <i class="fas fa-star mr-2"></i>Pertinence
                            </a>
                            <a href="?q=<?= urlencode($query) ?>&type=<?= $type ?>&sort=date&date=<?= $dateFilter ?>&university=<?= urlencode($university) ?>&country=<?= urlencode($country) ?>&category=<?= urlencode($category) ?><?= $hasImage ? '&has_image=1' : '' ?>" 
                               class="block px-3 py-2 rounded-lg text-sm <?= $sort == 'date' ? 'bg-orange-50 text-orange-600 font-medium' : 'text-gray-600 hover:bg-gray-50' ?>">
                                <i class="fas fa-clock mr-2"></i>Plus récents
                            </a>
                            <a href="?q=<?= urlencode($query) ?>&type=<?= $type ?>&sort=popularity&date=<?= $dateFilter ?>&university=<?= urlencode($university) ?>&country=<?= urlencode($country) ?>&category=<?= urlencode($category) ?><?= $hasImage ? '&has_image=1' : '' ?>" 
                               class="block px-3 py-2 rounded-lg text-sm <?= $sort == 'popularity' ? 'bg-orange-50 text-orange-600 font-medium' : 'text-gray-600 hover:bg-gray-50' ?>">
                                <i class="fas fa-fire mr-2"></i>Plus populaires
                            </a>
                        </div>
                    </div>
                    
                    <!-- Tendances de recherche -->
                    <?php if (!empty($trendingSearches)): ?>
                        <div class="bg-gradient-to-br from-orange-50 to-red-50 rounded-2xl p-5 border border-orange-100">
                            <h3 class="font-bold text-gray-800 mb-3 flex items-center gap-2">
                                <i class="fas fa-fire text-orange-500"></i>Tendances 🔥
                            </h3>
                            <div class="space-y-2">
                                <?php foreach ($trendingSearches as $trend): ?>
                                    <a href="?q=<?= urlencode($trend['query']) ?>&type=all" 
                                       class="flex items-center justify-between text-sm text-gray-700 hover:text-orange-500 transition-colors">
                                        <span><i class="fas fa-search text-xs mr-2"></i><?= htmlspecialchars($trend['query']) ?></span>
                                        <span class="text-xs text-gray-400"><?= number_format($trend['count']) ?> recherches</span>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Résultats de recherche -->
                <div class="lg:col-span-3 space-y-4">
                    
                    <!-- Résultats Utilisateurs -->
                    <?php if (($type === 'all' || $type === 'users') && !empty($results['users'])): ?>
                        <div class="mb-4">
                            <h2 class="text-lg font-bold text-gray-800 mb-3 flex items-center gap-2">
                                <i class="fas fa-users text-orange-500"></i>Personnes
                                <span class="text-sm text-gray-400 font-normal">(<?= count($results['users']) ?> résultats)</span>
                            </h2>
                        </div>
                        <?php foreach ($results['users'] as $user): ?>
                            <div class="result-card bg-white rounded-2xl shadow-sm p-5 hover:shadow-md transition-all border border-gray-100">
                                <div class="flex items-start gap-4">
                                    <div class="relative">
                                        <img src="<?= get_avatar_url($user['avatar'] ?? '') ?>" class="w-16 h-16 rounded-full object-cover border-2 border-orange-200 shadow-sm">
                                        <?php if ($user['status'] === 'Online'): ?>
                                            <span class="absolute bottom-0 right-0 w-3.5 h-3.5 bg-green-500 rounded-full border-2 border-white"></span>
                                        <?php endif; ?>
                                    </div>
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
                                                    <i class="fas fa-university text-orange-400 text-xs"></i>
                                                    <?= highlightText($user['universite'], $query) ?>
                                                </span>
                                            <?php endif; ?>
                                            <?php if (!empty($user['nationalite'])): ?>
                                                <span class="flex items-center gap-1">
                                                    <i class="fas fa-map-marker-alt text-orange-400 text-xs"></i>
                                                    <?= highlightText($user['nationalite'], $query) ?>
                                                </span>
                                            <?php endif; ?>
                                            <?php if (!empty($user['profession'])): ?>
                                                <span class="flex items-center gap-1">
                                                    <i class="fas fa-briefcase text-orange-400 text-xs"></i>
                                                    <?= highlightText($user['profession'], $query) ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="flex items-center gap-3 text-xs text-gray-400 mb-3">
                                            <span><i class="fas fa-users"></i> <?= number_format($user['friends_count'] ?? 0) ?> amis</span>
                                            <span><i class="fas fa-newspaper"></i> <?= number_format($user['posts_count'] ?? 0) ?> publications</span>
                                        </div>
                                        <div class="flex gap-3">
                                            <?php if ($user['friendship_status'] === 'none'): ?>
                                                <button onclick="sendFriendRequest(<?= $user['id'] ?>, this)" 
                                                        class="bg-gradient-to-r from-orange-500 to-red-500 text-white px-4 py-1.5 rounded-lg text-sm font-medium hover:shadow-md transition-all">
                                                    <i class="fas fa-user-plus mr-1"></i>Ajouter
                                                </button>
                                            <?php elseif ($user['friendship_status'] === 'pending_sent'): ?>
                                                <span class="bg-gray-100 text-gray-500 px-4 py-1.5 rounded-lg text-sm font-medium">
                                                    <i class="fas fa-clock mr-1"></i>Demande envoyée
                                                </span>
                                            <?php elseif ($user['friendship_status'] === 'pending_received'): ?>
                                                <div class="flex gap-2">
                                                    <button onclick="acceptFriendRequest(<?= $user['id'] ?>)" 
                                                            class="bg-green-500 text-white px-4 py-1.5 rounded-lg text-sm font-medium hover:bg-green-600 transition-colors">
                                                        <i class="fas fa-check mr-1"></i>Accepter
                                                    </button>
                                                    <button onclick="rejectFriendRequest(<?= $user['id'] ?>)" 
                                                            class="bg-red-500 text-white px-4 py-1.5 rounded-lg text-sm font-medium hover:bg-red-600 transition-colors">
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
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    
                    <!-- Résultats Publications -->
                    <?php if (($type === 'all' || $type === 'posts') && !empty($results['posts'])): ?>
                        <div class="mb-4 mt-6">
                            <h2 class="text-lg font-bold text-gray-800 mb-3 flex items-center gap-2">
                                <i class="fas fa-newspaper text-orange-500"></i>Publications
                                <span class="text-sm text-gray-400 font-normal">(<?= count($results['posts']) ?> résultats)</span>
                            </h2>
                        </div>
                        <?php foreach ($results['posts'] as $post): ?>
                            <div class="result-card bg-white rounded-2xl shadow-sm p-5 hover:shadow-md transition-all border border-gray-100">
                                <div class="flex items-center gap-3 mb-3">
                                    <img src="<?= get_avatar_url($post['avatar'] ?? '') ?>" class="w-10 h-10 rounded-full object-cover">
                                    <div>
                                        <a href="profil.php?id=<?= $post['user_id'] ?>" class="font-semibold text-gray-800 hover:text-orange-500">
                                            <?= highlightText($post['surnom'], $query) ?>
                                        </a>
                                        <p class="text-xs text-gray-400"><?= date('d M Y à H:i', strtotime($post['date_publication'])) ?></p>
                                    </div>
                                    <?php if ($post['privacy'] == 'private'): ?>
                                        <span class="ml-auto text-xs text-gray-400"><i class="fas fa-lock"></i> Privé</span>
                                    <?php elseif ($post['privacy'] == 'friends'): ?>
                                        <span class="ml-auto text-xs text-gray-400"><i class="fas fa-user-friends"></i> Amis</span>
                                    <?php endif; ?>
                                </div>
                                <p class="text-gray-700 mb-3 line-clamp-3"><?= highlightText(nl2br(htmlspecialchars($post['contenu'])), $query) ?></p>
                                <?php if (!empty($post['image_post'])): ?>
                                    <img src="../uploads/posts/<?= htmlspecialchars($post['image_post']) ?>" class="rounded-xl max-h-64 object-cover mt-2 mb-3 cursor-pointer" onclick="window.open(this.src, '_blank')">
                                <?php endif; ?>
                                <div class="flex items-center gap-4 text-sm text-gray-500 pt-2 border-t border-gray-100">
                                    <button onclick="toggleLike(<?= $post['idpost'] ?>)" class="flex items-center gap-1 hover:text-red-500 transition-colors <?= ($post['user_liked'] ?? false) ? 'text-red-500' : '' ?>">
                                        <i class="fas fa-heart"></i> <?= number_format($post['likes_count']) ?>
                                    </button>
                                    <span class="flex items-center gap-1">
                                        <i class="fas fa-comment"></i> <?= number_format($post['comments_count']) ?>
                                    </span>
                                    <a href="../index.php?post=<?= $post['idpost'] ?>" class="hover:text-orange-500 transition-colors ml-auto">
                                        <i class="fas fa-external-link-alt"></i> Voir la publication
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    
                    <!-- Résultats Communautés -->
                    <?php if (($type === 'all' || $type === 'communities') && !empty($results['communities'])): ?>
                        <div class="mb-4 mt-6">
                            <h2 class="text-lg font-bold text-gray-800 mb-3 flex items-center gap-2">
                                <i class="fas fa-university text-orange-500"></i>Communautés
                                <span class="text-sm text-gray-400 font-normal">(<?= count($results['communities']) ?> résultats)</span>
                            </h2>
                        </div>
                        <?php foreach ($results['communities'] as $community): 
                            $catIcons = [
                                'Academic' => '🎓', 'Club' => '👥', 'Social' => '❤️', 'Sports' => '⚽',
                                'Arts' => '🎨', 'Tech' => '💻', 'Career' => '💼'
                            ];
                            $icon = $catIcons[$community['categorie']] ?? '📁';
                        ?>
                            <div class="result-card bg-white rounded-2xl shadow-sm p-5 hover:shadow-md transition-all border border-gray-100">
                                <div class="flex items-start gap-4">
                                    <div class="w-16 h-16 bg-gradient-to-br from-orange-500 to-red-500 rounded-xl flex items-center justify-center text-white text-2xl font-bold shadow-md flex-shrink-0">
                                        <?= strtoupper(substr($community['nom'], 0, 1)) ?>
                                    </div>
                                    <div class="flex-1">
                                        <div class="flex items-center justify-between mb-1 flex-wrap gap-2">
                                            <a href="communaute.php?id=<?= $community['id_communaute'] ?>" class="font-bold text-gray-800 hover:text-orange-500 text-lg">
                                                <?= highlightText($community['nom'], $query) ?>
                                            </a>
                                            <span class="text-xs bg-gray-100 text-gray-600 px-2 py-1 rounded-full">
                                                <?= $icon ?> <?= htmlspecialchars($community['categorie'] ?? 'Academic') ?>
                                            </span>
                                        </div>
                                        <p class="text-gray-600 text-sm mb-2 line-clamp-2"><?= highlightText(htmlspecialchars($community['description'] ?? ''), $query) ?></p>
                                        <div class="flex items-center gap-4 text-sm text-gray-500">
                                            <span><i class="fas fa-users"></i> <?= number_format($community['members_count']) ?> membres</span>
                                            <span><i class="fas fa-newspaper"></i> <?= number_format($community['posts_count'] ?? 0) ?> publications</span>
                                        </div>
                                        <div class="mt-3">
                                            <?php if ($community['is_member']): ?>
                                                <span class="bg-green-100 text-green-700 px-4 py-1.5 rounded-lg text-sm font-medium inline-flex items-center gap-1">
                                                    <i class="fas fa-check-circle"></i> Membre
                                                </span>
                                            <?php else: ?>
                                                <button onclick="joinCommunity(<?= $community['id_communaute'] ?>)" 
                                                        class="bg-gradient-to-r from-orange-500 to-red-500 text-white px-4 py-1.5 rounded-lg text-sm font-medium hover:shadow-md transition-all">
                                                    <i class="fas fa-sign-in-alt mr-1"></i>Rejoindre
                                                </button>
                                            <?php endif; ?>
                                            <a href="communaute.php?id=<?= $community['id_communaute'] ?>" class="ml-3 text-orange-500 text-sm hover:underline">
                                                Voir la communauté →
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    
                    <!-- Aucun résultat -->
                    <?php if ($totalCount === 0): ?>
                        <div class="bg-white rounded-2xl shadow-sm p-16 text-center border border-gray-100">
                            <div class="w-32 h-32 bg-gradient-to-br from-gray-100 to-gray-200 rounded-full flex items-center justify-center mx-auto mb-6">
                                <i class="fas fa-search text-5xl text-gray-400"></i>
                            </div>
                            <h3 class="text-2xl font-bold text-gray-700 mb-2">Aucun résultat trouvé</h3>
                            <p class="text-gray-500 mb-6 max-w-md mx-auto">
                                Nous n'avons trouvé aucun résultat pour "<strong><?= htmlspecialchars($query) ?></strong>"
                            </p>
                            <div class="flex flex-wrap justify-center gap-4">
                                <div class="text-left">
                                    <p class="text-sm font-medium text-gray-600 mb-2">Suggestions :</p>
                                    <ul class="text-sm text-gray-500 space-y-1">
                                        <li>• Vérifiez l'orthographe</li>
                                        <li>• Utilisez des termes plus généraux</li>
                                        <li>• Essayez d'autres mots-clés</li>
                                        <li>• Réduisez le nombre de filtres</li>
                                    </ul>
                                </div>
                                <?php if (!empty($trendingSearches)): ?>
                                    <div class="text-left border-l border-gray-200 pl-6">
                                        <p class="text-sm font-medium text-gray-600 mb-2">Recherches populaires :</p>
                                        <div class="flex flex-wrap gap-2">
                                            <?php foreach (array_slice($trendingSearches, 0, 5) as $trend): ?>
                                                <a href="?q=<?= urlencode($trend['query']) ?>" class="px-3 py-1 bg-gray-100 rounded-full text-sm hover:bg-orange-100 hover:text-orange-600 transition-colors">
                                                    <?= htmlspecialchars($trend['query']) ?>
                                                </a>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Pagination -->
                    <?php if ($totalPages > 1): ?>
                        <div class="flex justify-center gap-2 mt-8 pt-4">
                            <?php if ($page > 1): ?>
                                <a href="?q=<?= urlencode($query) ?>&type=<?= $type ?>&page=<?= $page-1 ?>&sort=<?= $sort ?>&date=<?= $dateFilter ?>&university=<?= urlencode($university) ?>&country=<?= urlencode($country) ?>&category=<?= urlencode($category) ?><?= $hasImage ? '&has_image=1' : '' ?>" 
                                   class="px-4 py-2 bg-white border rounded-xl hover:bg-gray-50 transition-colors">
                                    <i class="fas fa-chevron-left"></i> Précédent
                                </a>
                            <?php endif; ?>
                            
                            <div class="flex gap-1">
                                <?php
                                $startPage = max(1, $page - 2);
                                $endPage = min($totalPages, $page + 2);
                                for ($i = $startPage; $i <= $endPage; $i++):
                                ?>
                                    <a href="?q=<?= urlencode($query) ?>&type=<?= $type ?>&page=<?= $i ?>&sort=<?= $sort ?>&date=<?= $dateFilter ?>&university=<?= urlencode($university) ?>&country=<?= urlencode($country) ?>&category=<?= urlencode($category) ?><?= $hasImage ? '&has_image=1' : '' ?>" 
                                       class="w-10 h-10 flex items-center justify-center rounded-xl transition-all <?= $i == $page ? 'bg-gradient-to-r from-orange-500 to-red-500 text-white shadow-md' : 'bg-white border hover:bg-gray-50' ?>">
                                        <?= $i ?>
                                    </a>
                                <?php endfor; ?>
                            </div>
                            
                            <?php if ($page < $totalPages): ?>
                                <a href="?q=<?= urlencode($query) ?>&type=<?= $type ?>&page=<?= $page+1 ?>&sort=<?= $sort ?>&date=<?= $dateFilter ?>&university=<?= urlencode($university) ?>&country=<?= urlencode($country) ?>&category=<?= urlencode($category) ?><?= $hasImage ? '&has_image=1' : '' ?>" 
                                   class="px-4 py-2 bg-white border rounded-xl hover:bg-gray-50 transition-colors">
                                    Suivant <i class="fas fa-chevron-right"></i>
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
        <?php else: ?>
            
            <!-- Page d'accueil de recherche avec suggestions -->
            <div class="bg-white rounded-3xl shadow-sm p-12 text-center border border-gray-100">
                <div class="w-36 h-36 bg-gradient-to-br from-orange-100 to-red-100 rounded-full flex items-center justify-center mx-auto mb-8 shadow-lg">
                    <i class="fas fa-search text-6xl text-orange-500"></i>
                </div>
                <h2 class="text-3xl font-bold text-gray-800 mb-3">Que cherchez-vous ?</h2>
                <p class="text-gray-500 mb-8 max-w-md mx-auto">Recherchez des personnes, publications, communautés ou universités académiques</p>
                
                <!-- Suggestions populaires -->
                <div class="max-w-3xl mx-auto">
                    <p class="text-sm text-gray-500 mb-4">Suggestions populaires :</p>
                    <div class="flex flex-wrap justify-center gap-3">
                        <a href="?q=étudiant" class="px-4 py-2.5 bg-gray-100 rounded-full text-sm hover:bg-orange-100 hover:text-orange-600 transition-all">🎓 étudiant</a>
                        <a href="?q=université" class="px-4 py-2.5 bg-gray-100 rounded-full text-sm hover:bg-orange-100 hover:text-orange-600 transition-all">🏛️ université</a>
                        <a href="?q=mémoire" class="px-4 py-2.5 bg-gray-100 rounded-full text-sm hover:bg-orange-100 hover:text-orange-600 transition-all">📚 mémoire</a>
                        <a href="?q=licence" class="px-4 py-2.5 bg-gray-100 rounded-full text-sm hover:bg-orange-100 hover:text-orange-600 transition-all">📖 licence</a>
                        <a href="?q=master" class="px-4 py-2.5 bg-gray-100 rounded-full text-sm hover:bg-orange-100 hover:text-orange-600 transition-all">🎓 master</a>
                        <a href="?q=doctorat" class="px-4 py-2.5 bg-gray-100 rounded-full text-sm hover:bg-orange-100 hover:text-orange-600 transition-all">🔬 doctorat</a>
                        <a href="?q=informatique" class="px-4 py-2.5 bg-gray-100 rounded-full text-sm hover:bg-orange-100 hover:text-orange-600 transition-all">💻 informatique</a>
                        <a href="?q=médecine" class="px-4 py-2.5 bg-gray-100 rounded-full text-sm hover:bg-orange-100 hover:text-orange-600 transition-all">🩺 médecine</a>
                        <a href="?q=droit" class="px-4 py-2.5 bg-gray-100 rounded-full text-sm hover:bg-orange-100 hover:text-orange-600 transition-all">⚖️ droit</a>
                        <a href="?q=économie" class="px-4 py-2.5 bg-gray-100 rounded-full text-sm hover:bg-orange-100 hover:text-orange-600 transition-all">📊 économie</a>
                    </div>
                </div>
                
                <!-- Catégories rapides -->
                <div class="mt-12 pt-8 border-t border-gray-100">
                    <p class="text-sm text-gray-500 mb-4">Explorer par catégorie :</p>
                    <div class="flex flex-wrap justify-center gap-3">
                        <a href="?q=communautés académiques" class="px-4 py-2 bg-gray-50 rounded-full text-sm hover:bg-orange-50 hover:text-orange-600 transition-all">🎓 Communautés académiques</a>
                        <a href="?q=clubs étudiants" class="px-4 py-2 bg-gray-50 rounded-full text-sm hover:bg-orange-50 hover:text-orange-600 transition-all">👥 Clubs étudiants</a>
                        <a href="?q=ressources pédagogiques" class="px-4 py-2 bg-gray-50 rounded-full text-sm hover:bg-orange-50 hover:text-orange-600 transition-all">📚 Ressources pédagogiques</a>
                        <a href="?q=événements universitaires" class="px-4 py-2 bg-gray-50 rounded-full text-sm hover:bg-orange-50 hover:text-orange-600 transition-all">📅 Événements</a>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- Toast Container -->
    <div id="toastContainer" class="fixed bottom-4 right-4 z-50 space-y-2"></div>
    
    <script>
        const csrfToken = '<?= $csrfToken ?>';
        let searchTimeout;
        
        // ==================== FONCTIONS DE RECHERCHE ====================
        
        function toggleFilters() {
            const sidebar = document.getElementById('filtersSidebar');
            if (sidebar) {
                sidebar.classList.toggle('hidden');
                sidebar.classList.toggle('block');
            }
        }
        
        function toggleImageFilter() {
            const checkbox = document.getElementById('hasImageFilter');
            const url = new URL(window.location.href);
            if (checkbox.checked) {
                url.searchParams.set('has_image', '1');
            } else {
                url.searchParams.delete('has_image');
            }
            window.location.href = url.toString();
        }
        
        // Suggestions en temps réel
        const searchInput = document.getElementById('searchInput');
        const suggestionsDropdown = document.getElementById('suggestionsDropdown');
        const suggestionsList = document.getElementById('suggestionsList');
        
        if (searchInput) {
            searchInput.addEventListener('input', function() {
                clearTimeout(searchTimeout);
                const query = this.value.trim();
                
                if (query.length < 2) {
                    suggestionsDropdown.classList.add('hidden');
                    return;
                }
                
                searchTimeout = setTimeout(async () => {
                    try {
                        const response = await fetch(`../api/search.php?q=${encodeURIComponent(query)}&limit=8&suggestions=1`);
                        const data = await response.json();
                        
                        if (data.success && data.suggestions && data.suggestions.length > 0) {
                            suggestionsList.innerHTML = data.suggestions.map(s => `
                                <a href="?q=${encodeURIComponent(s.query)}" class="suggestion-item flex items-center gap-3 p-3 hover:bg-orange-50 transition-colors border-b border-gray-100 last:border-0">
                                    <i class="fas fa-search text-gray-400 w-5"></i>
                                    <span class="flex-1 text-gray-700">${escapeHtml(s.query)}</span>
                                    <span class="text-xs text-gray-400">${s.count} recherches</span>
                                    <i class="fas fa-arrow-right text-gray-300"></i>
                                </a>
                            `).join('');
                            suggestionsDropdown.classList.remove('hidden');
                        } else {
                            suggestionsList.innerHTML = '<div class="p-4 text-center text-gray-500"><i class="fas fa-info-circle mr-2"></i>Aucune suggestion</div>';
                            suggestionsDropdown.classList.remove('hidden');
                        }
                    } catch (err) {
                        console.error('Error fetching suggestions:', err);
                    }
                }, 300);
            });
            
            // Fermer les suggestions au clic extérieur
            document.addEventListener('click', (e) => {
                if (!searchInput.contains(e.target) && !suggestionsDropdown.contains(e.target)) {
                    suggestionsDropdown.classList.add('hidden');
                }
            });
        }
        
        // ==================== FONCTIONS D'INTERACTION ====================
        
        async function sendFriendRequest(userId, btn) {
            const formData = new FormData();
            formData.append('action', 'send_request');
            formData.append('user_id', userId);
            formData.append('csrf_token', csrfToken);
            
            try {
                const response = await fetch('../api/friends.php', {
                    method: 'POST',
                    body: formData
                });
                const data = await response.json();
                if (data.success) {
                    if (btn) {
                        btn.innerHTML = '<i class="fas fa-clock mr-1"></i> Demande envoyée';
                        btn.disabled = true;
                        btn.classList.remove('bg-gradient-to-r', 'from-orange-500', 'to-red-500');
                        btn.classList.add('bg-gray-300', 'text-gray-600');
                    }
                    showToast('Demande d\'ami envoyée !', 'success');
                } else {
                    showToast(data.error || 'Erreur lors de l\'envoi', 'error');
                }
            } catch (err) {
                console.error('Error:', err);
                showToast('Erreur de connexion', 'error');
            }
        }
        
        async function acceptFriendRequest(userId) {
            const formData = new FormData();
            formData.append('action', 'accept_request');
            formData.append('user_id', userId);
            formData.append('csrf_token', csrfToken);
            
            try {
                const response = await fetch('../api/friends.php', {
                    method: 'POST',
                    body: formData
                });
                const data = await response.json();
                if (data.success) {
                    location.reload();
                }
            } catch (err) {
                console.error('Error:', err);
            }
        }
        
        async function rejectFriendRequest(userId) {
            const formData = new FormData();
            formData.append('action', 'reject_request');
            formData.append('user_id', userId);
            formData.append('csrf_token', csrfToken);
            
            try {
                const response = await fetch('../api/friends.php', {
                    method: 'POST',
                    body: formData
                });
                const data = await response.json();
                if (data.success) {
                    location.reload();
                }
            } catch (err) {
                console.error('Error:', err);
            }
        }
        
        async function joinCommunity(communityId) {
            const formData = new FormData();
            formData.append('action', 'join');
            formData.append('community_id', communityId);
            formData.append('csrf_token', csrfToken);
            
            try {
                const response = await fetch('../api/communities.php', {
                    method: 'POST',
                    body: formData
                });
                const data = await response.json();
                if (data.success) {
                    location.reload();
                } else {
                    showToast(data.error || 'Erreur lors de l\'inscription', 'error');
                }
            } catch (err) {
                console.error('Error:', err);
                showToast('Erreur de connexion', 'error');
            }
        }
        
        async function toggleLike(postId) {
            try {
                const response = await fetch('../api/posts.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'like',
                        post_id: postId,
                        csrf_token: csrfToken
                    })
                });
                const data = await response.json();
                if (data.success) {
                    location.reload();
                }
            } catch (err) {
                console.error('Error:', err);
            }
        }
        
        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        function showToast(message, type = 'info') {
            const container = document.getElementById('toastContainer');
            const colors = {
                success: 'bg-gradient-to-r from-green-500 to-green-600',
                error: 'bg-gradient-to-r from-red-500 to-red-600',
                info: 'bg-gradient-to-r from-blue-500 to-blue-600',
                warning: 'bg-gradient-to-r from-yellow-500 to-yellow-600'
            };
            
            const toast = document.createElement('div');
            toast.className = `${colors[type]} text-white px-5 py-3 rounded-xl shadow-lg flex items-center gap-3 toast`;
            toast.innerHTML = `
                <i class="fas ${type === 'success' ? 'fa-check-circle' : type === 'error' ? 'fa-exclamation-circle' : 'fa-info-circle'}"></i>
                <span class="font-medium">${message}</span>
                <button onclick="this.parentElement.remove()" class="ml-4 text-white/70 hover:text-white">
                    <i class="fas fa-times"></i>
                </button>
            `;
            
            container.appendChild(toast);
            setTimeout(() => {
                toast.style.opacity = '0';
                toast.style.transform = 'translateX(20px)';
                setTimeout(() => toast.remove(), 300);
            }, 4000);
        }
        
        function showMoreUniversities() {
            showToast('Fonctionnalité en développement', 'info');
        }
    </script>
</body>
</html>