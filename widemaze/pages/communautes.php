<?php
/**
 * WideMaze - Explorer les Communautés Académiques
 * Version 4.0 - Annuaire intelligent avec filtres avancés, suggestions personnalisées
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_auth();

$userId = $_SESSION['user_id'];
$page = max(1, intval($_GET['page'] ?? 1));
$limit = 12;
$offset = ($page - 1) * $limit;
$category = $_GET['category'] ?? 'all';
$sort = $_GET['sort'] ?? 'popular'; // popular, recent, name, members
$search = trim($_GET['search'] ?? '');
$view = $_GET['view'] ?? 'grid'; // grid, list
$myOnly = isset($_GET['my']) && $_GET['my'] == '1';

// Catégories disponibles avec icônes et couleurs
$categories = [
    'all' => ['name' => 'Toutes', 'icon' => 'globe', 'color' => 'gray', 'bg' => 'bg-gray-100', 'text' => 'text-gray-600'],
    'Academic' => ['name' => 'Académique', 'icon' => 'graduation-cap', 'color' => 'blue', 'bg' => 'bg-blue-100', 'text' => 'text-blue-600'],
    'Club' => ['name' => 'Club', 'icon' => 'users', 'color' => 'green', 'bg' => 'bg-green-100', 'text' => 'text-green-600'],
    'Social' => ['name' => 'Social', 'icon' => 'heart', 'color' => 'pink', 'bg' => 'bg-pink-100', 'text' => 'text-pink-600'],
    'Sports' => ['name' => 'Sports', 'icon' => 'futbol', 'color' => 'orange', 'bg' => 'bg-orange-100', 'text' => 'text-orange-600'],
    'Arts' => ['name' => 'Arts & Culture', 'icon' => 'palette', 'color' => 'purple', 'bg' => 'bg-purple-100', 'text' => 'text-purple-600'],
    'Tech' => ['name' => 'Technologie', 'icon' => 'microchip', 'color' => 'indigo', 'bg' => 'bg-indigo-100', 'text' => 'text-indigo-600'],
    'Career' => ['name' => 'Carrière', 'icon' => 'briefcase', 'color' => 'yellow', 'bg' => 'bg-yellow-100', 'text' => 'text-yellow-700']
];

// Récupération des communautés
$communities = [];
$totalCommunities = 0;

try {
    $sql = "
        SELECT c.*, u.surnom as creator_name, u.avatar as creator_avatar,
            (SELECT COUNT(*) FROM communaute_membres WHERE id_communaute = c.id_communaute) as member_count,
            (SELECT COUNT(*) FROM posts WHERE id_communaute = c.id_communaute) as posts_count,
            (SELECT COUNT(*) FROM posts WHERE id_communaute = c.id_communaute AND date_publication > DATE_SUB(NOW(), INTERVAL 7 DAY)) as posts_week,
            (SELECT EXISTS(SELECT 1 FROM communaute_membres WHERE id_communaute = c.id_communaute AND id_utilisateur = ?)) as is_member,
            (SELECT created_at FROM communaute_membres WHERE id_communaute = c.id_communaute AND id_utilisateur = ?) as joined_at,
            (SELECT COUNT(*) FROM communaute_membres WHERE id_communaute = c.id_communaute AND created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)) as new_members_week
        FROM communautes c
        JOIN utilisateurs u ON c.id_createur = u.id
        WHERE c.is_active = 1
    ";
    $params = [$userId, $userId];
    
    // Filtre "mes communautés"
    if ($myOnly) {
        $sql .= " AND EXISTS(SELECT 1 FROM communaute_membres WHERE id_communaute = c.id_communaute AND id_utilisateur = ?)";
        $params[] = $userId;
    }
    
    // Filtre par catégorie
    if ($category != 'all') {
        $sql .= " AND c.categorie = ?";
        $params[] = $category;
    }
    
    // Filtre par recherche
    if (!empty($search)) {
        $sql .= " AND (c.nom LIKE ? OR c.description LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }
    
    // Tri
    switch ($sort) {
        case 'recent':
            $sql .= " ORDER BY c.date_creation DESC";
            break;
        case 'name':
            $sql .= " ORDER BY c.nom ASC";
            break;
        case 'members':
            $sql .= " ORDER BY member_count DESC";
            break;
        case 'active':
            $sql .= " ORDER BY posts_week DESC, new_members_week DESC";
            break;
        case 'popular':
        default:
            $sql .= " ORDER BY member_count DESC, posts_count DESC";
            break;
    }
    
    // Pagination
    $sql .= " LIMIT ? OFFSET ?";
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
    $totalCommunities = $countStmt->fetchColumn();
    
} catch (PDOException $e) {
    error_log("Error fetching communities: " . $e->getMessage());
}

// Récupération des communautés recommandées (personnalisées)
$recommendedCommunities = [];
try {
    // Recommandations basées sur les intérêts de l'utilisateur (université, faculté, catégories populaires)
    $stmt = $pdo->prepare("
        SELECT c.*, u.surnom as creator_name,
            (SELECT COUNT(*) FROM communaute_membres WHERE id_communaute = c.id_communaute) as member_count,
            EXISTS(SELECT 1 FROM communaute_membres WHERE id_communaute = c.id_communaute AND id_utilisateur = ?) as is_member
        FROM communautes c
        JOIN utilisateurs u ON c.id_createur = u.id
        WHERE c.is_active = 1
        AND c.id_communaute NOT IN (
            SELECT id_communaute FROM communaute_membres WHERE id_utilisateur = ?
        )
        AND (c.categorie IN (
            SELECT DISTINCT c2.categorie 
            FROM communaute_membres cm
            JOIN communautes c2 ON cm.id_communaute = c2.id_communaute
            WHERE cm.id_utilisateur = ?
        ) OR c.nom LIKE CONCAT('%', ?, '%') OR c.description LIKE CONCAT('%', ?, '%'))
        ORDER BY member_count DESC
        LIMIT 6
    ");
    $university = $user['universite'] ?? '';
    $stmt->execute([$userId, $userId, $userId, $university, $university]);
    $recommendedCommunities = $stmt->fetchAll();
    
    // Si pas assez de recommandations, ajouter des populaires
    if (count($recommendedCommunities) < 6) {
        $stmt = $pdo->prepare("
            SELECT c.*, u.surnom as creator_name,
                (SELECT COUNT(*) FROM communaute_membres WHERE id_communaute = c.id_communaute) as member_count,
                EXISTS(SELECT 1 FROM communaute_membres WHERE id_communaute = c.id_communaute AND id_utilisateur = ?) as is_member
            FROM communautes c
            JOIN utilisateurs u ON c.id_createur = u.id
            WHERE c.is_active = 1
            AND c.id_communaute NOT IN (
                SELECT id_communaute FROM communaute_membres WHERE id_utilisateur = ?
            )
            ORDER BY member_count DESC
            LIMIT ?
        ");
        $remaining = 6 - count($recommendedCommunities);
        $stmt->execute([$userId, $userId, $remaining]);
        $more = $stmt->fetchAll();
        $recommendedCommunities = array_merge($recommendedCommunities, $more);
    }
} catch (PDOException $e) {
    error_log("Error fetching recommendations: " . $e->getMessage());
}

// Récupération des communautés de l'utilisateur
$myCommunities = [];
try {
    $stmt = $pdo->prepare("
        SELECT c.*, u.surnom as creator_name,
            (SELECT COUNT(*) FROM communaute_membres WHERE id_communaute = c.id_communaute) as member_count,
            cm.created_at as joined_at,
            cm.role as member_role
        FROM communaute_membres cm
        JOIN communautes c ON cm.id_communaute = c.id_communaute
        JOIN utilisateurs u ON c.id_createur = u.id
        WHERE cm.id_utilisateur = ? AND c.is_active = 1
        ORDER BY cm.created_at DESC
        LIMIT 8
    ");
    $stmt->execute([$userId]);
    $myCommunities = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Error fetching my communities: " . $e->getMessage());
}

// Récupération des catégories populaires (pour les filtres rapides)
$popularCategories = [];
try {
    $stmt = $pdo->prepare("
        SELECT categorie, COUNT(*) as count 
        FROM communautes 
        WHERE is_active = 1 
        GROUP BY categorie 
        ORDER BY count DESC 
        LIMIT 8
    ");
    $stmt->execute();
    $popularCategories = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Error fetching popular categories: " . $e->getMessage());
}

// Récupération des tendances (communautés qui gagnent le plus de membres)
$trendingCommunities = [];
try {
    $stmt = $pdo->prepare("
        SELECT c.*, u.surnom as creator_name,
            (SELECT COUNT(*) FROM communaute_membres WHERE id_communaute = c.id_communaute) as member_count,
            (SELECT COUNT(*) FROM communaute_membres WHERE id_communaute = c.id_communaute AND created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)) as new_members
        FROM communautes c
        JOIN utilisateurs u ON c.id_createur = u.id
        WHERE c.is_active = 1
        ORDER BY new_members DESC, member_count DESC
        LIMIT 5
    ");
    $stmt->execute();
    $trendingCommunities = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Error fetching trending: " . $e->getMessage());
}

$totalPages = ceil($totalCommunities / $limit);
$csrfToken = generate_csrf_token();
$page_title = $myOnly ? 'Mes Communautés' : ($search ? "Recherche: $search" : 'Explorer les Communautés');
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
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }
        @keyframes scaleIn {
            from { opacity: 0; transform: scale(0.95); }
            to { opacity: 1; transform: scale(1); }
        }
        @keyframes slideIn {
            from { opacity: 0; transform: translateX(-20px); }
            to { opacity: 1; transform: translateX(0); }
        }
        
        .community-card {
            animation: fadeInUp 0.4s ease-out;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .community-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 20px 25px -12px rgba(0,0,0,0.15);
        }
        .category-filter {
            transition: all 0.2s ease;
        }
        .category-filter.active {
            background: linear-gradient(135deg, #f59e0b, #ea580c);
            color: white;
            transform: scale(1.05);
        }
        .stat-badge {
            transition: all 0.2s;
        }
        .stat-badge:hover {
            transform: scale(1.1);
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
        .gradient-border {
            position: relative;
            background: linear-gradient(135deg, #f59e0b, #ec4899, #8b5cf6);
            padding: 2px;
            border-radius: 1rem;
        }
        .gradient-border > * {
            background: white;
            border-radius: calc(1rem - 2px);
        }
        .toast {
            animation: slideIn 0.3s ease-out;
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
        
        <!-- Hero Section -->
        <div class="relative mb-12">
            <div class="absolute inset-0 bg-gradient-to-r from-orange-500/10 to-red-500/10 rounded-3xl blur-3xl"></div>
            <div class="relative text-center">
                <div class="inline-flex items-center gap-2 px-4 py-2 bg-white/80 backdrop-blur-sm rounded-full shadow-sm mb-4">
                    <i class="fas fa-users text-orange-500"></i>
                    <span class="text-sm text-gray-600"><?= number_format($totalCommunities) ?> communautés actives</span>
                </div>
                <h1 class="text-4xl md:text-5xl font-bold text-gray-800 mb-4">
                    Rejoignez des <span class="bg-gradient-to-r from-orange-500 to-red-500 bg-clip-text text-transparent">communautés académiques</span>
                </h1>
                <p class="text-gray-600 text-lg max-w-2xl mx-auto">
                    Connectez-vous avec des étudiants partageant vos intérêts, échangez des ressources et développez votre réseau professionnel
                </p>
            </div>
        </div>
        
        <!-- Barre de recherche et actions -->
        <div class="flex flex-col md:flex-row gap-4 mb-8">
            <div class="flex-1 relative">
                <i class="fas fa-search absolute left-4 top-1/2 -translate-y-1/2 text-gray-400"></i>
                <form method="get" id="searchForm" class="relative">
                    <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" 
                           placeholder="Rechercher une communauté par nom, description ou université..."
                           class="w-full pl-12 pr-24 py-4 bg-white border border-gray-200 rounded-2xl focus:border-orange-500 focus:outline-none focus:ring-4 focus:ring-orange-100 transition-all text-lg">
                    <button type="submit" class="absolute right-2 top-1/2 -translate-y-1/2 bg-gradient-to-r from-orange-500 to-red-500 text-white px-5 py-2 rounded-xl font-medium hover:shadow-lg transition-all">
                        <i class="fas fa-search mr-2"></i>Rechercher
                    </button>
                </form>
            </div>
            <button onclick="openCreateModal()" 
                    class="bg-gradient-to-r from-orange-500 to-red-500 text-white px-6 py-4 rounded-2xl font-semibold hover:shadow-xl transition-all flex items-center justify-center gap-2 whitespace-nowrap">
                <i class="fas fa-plus-circle text-xl"></i>
                <span>Créer une communauté</span>
            </button>
        </div>
        
        <!-- Filtres rapides -->
        <div class="flex flex-wrap gap-2 mb-6">
            <a href="?my=1<?= $search ? '&search='.urlencode($search) : '' ?>" 
               class="px-4 py-2 rounded-full text-sm font-medium transition-all <?= $myOnly ? 'bg-orange-500 text-white shadow-md' : 'bg-white text-gray-600 hover:bg-gray-100 border border-gray-200' ?>">
                <i class="fas fa-star mr-2"></i>Mes communautés
                <?php if (count($myCommunities) > 0): ?>
                    <span class="ml-1 px-1.5 py-0.5 bg-orange-500/20 rounded-full text-xs"><?= count($myCommunities) ?></span>
                <?php endif; ?>
            </a>
            <a href="?<?= $myOnly ? 'my=1&' : '' ?>view=list<?= $search ? '&search='.urlencode($search) : '' ?>" 
               class="px-4 py-2 rounded-full text-sm font-medium transition-all <?= $view == 'list' ? 'bg-orange-500 text-white shadow-md' : 'bg-white text-gray-600 hover:bg-gray-100 border border-gray-200' ?>">
                <i class="fas fa-list mr-2"></i>Vue liste
            </a>
            <a href="?<?= $myOnly ? 'my=1&' : '' ?>view=grid<?= $search ? '&search='.urlencode($search) : '' ?>" 
               class="px-4 py-2 rounded-full text-sm font-medium transition-all <?= $view == 'grid' ? 'bg-orange-500 text-white shadow-md' : 'bg-white text-gray-600 hover:bg-gray-100 border border-gray-200' ?>">
                <i class="fas fa-th-large mr-2"></i>Vue grille
            </a>
        </div>
        
        <div class="grid lg:grid-cols-4 gap-8">
            
            <!-- Sidebar - Filtres avancés -->
            <div class="lg:col-span-1 space-y-6">
                
                <!-- Catégories populaires -->
                <div class="bg-white rounded-2xl shadow-sm p-5 border border-gray-100">
                    <h3 class="font-bold text-gray-800 mb-4 flex items-center gap-2">
                        <i class="fas fa-tags text-orange-500"></i>Catégories
                    </h3>
                    <div class="space-y-2">
                        <a href="?<?= $myOnly ? 'my=1&' : '' ?>category=all<?= $search ? '&search='.urlencode($search) : '' ?>" 
                           class="flex items-center justify-between px-3 py-2 rounded-lg text-sm transition-all <?= $category == 'all' ? 'bg-orange-50 text-orange-600 font-medium' : 'text-gray-600 hover:bg-gray-50' ?>">
                            <span><i class="fas fa-globe mr-2"></i>Toutes les catégories</span>
                            <span class="text-xs text-gray-400"><?= number_format($totalCommunities) ?></span>
                        </a>
                        <?php foreach ($popularCategories as $cat): ?>
                            <?php $catInfo = $categories[$cat['categorie']] ?? ['name' => $cat['categorie'], 'icon' => 'tag', 'color' => 'gray']; ?>
                            <a href="?<?= $myOnly ? 'my=1&' : '' ?>category=<?= urlencode($cat['categorie']) ?><?= $search ? '&search='.urlencode($search) : '' ?>" 
                               class="flex items-center justify-between px-3 py-2 rounded-lg text-sm transition-all <?= $category == $cat['categorie'] ? 'bg-orange-50 text-orange-600 font-medium' : 'text-gray-600 hover:bg-gray-50' ?>">
                                <span><i class="fas fa-<?= $catInfo['icon'] ?> mr-2"></i><?= $catInfo['name'] ?></span>
                                <span class="text-xs text-gray-400"><?= number_format($cat['count']) ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <!-- Tri -->
                <div class="bg-white rounded-2xl shadow-sm p-5 border border-gray-100">
                    <h3 class="font-bold text-gray-800 mb-3 flex items-center gap-2">
                        <i class="fas fa-sort-amount-down text-orange-500"></i>Trier par
                    </h3>
                    <div class="space-y-2">
                        <a href="?<?= $myOnly ? 'my=1&' : '' ?>sort=popular&category=<?= $category ?><?= $search ? '&search='.urlencode($search) : '' ?>" 
                           class="block px-3 py-2 rounded-lg text-sm <?= $sort == 'popular' ? 'bg-orange-50 text-orange-600 font-medium' : 'text-gray-600 hover:bg-gray-50' ?>">
                            <i class="fas fa-fire mr-2"></i>Plus populaires
                        </a>
                        <a href="?<?= $myOnly ? 'my=1&' : '' ?>sort=active&category=<?= $category ?><?= $search ? '&search='.urlencode($search) : '' ?>" 
                           class="block px-3 py-2 rounded-lg text-sm <?= $sort == 'active' ? 'bg-orange-50 text-orange-600 font-medium' : 'text-gray-600 hover:bg-gray-50' ?>">
                            <i class="fas fa-chart-line mr-2"></i>Plus actives
                        </a>
                        <a href="?<?= $myOnly ? 'my=1&' : '' ?>sort=recent&category=<?= $category ?><?= $search ? '&search='.urlencode($search) : '' ?>" 
                           class="block px-3 py-2 rounded-lg text-sm <?= $sort == 'recent' ? 'bg-orange-50 text-orange-600 font-medium' : 'text-gray-600 hover:bg-gray-50' ?>">
                            <i class="fas fa-clock mr-2"></i>Plus récentes
                        </a>
                        <a href="?<?= $myOnly ? 'my=1&' : '' ?>sort=name&category=<?= $category ?><?= $search ? '&search='.urlencode($search) : '' ?>" 
                           class="block px-3 py-2 rounded-lg text-sm <?= $sort == 'name' ? 'bg-orange-50 text-orange-600 font-medium' : 'text-gray-600 hover:bg-gray-50' ?>">
                            <i class="fas fa-sort-alpha-down mr-2"></i>Nom (A-Z)
                        </a>
                    </div>
                </div>
                
                <!-- Mes communautés (sidebar) -->
                <?php if (!empty($myCommunities) && !$myOnly): ?>
                    <div class="bg-white rounded-2xl shadow-sm p-5 border border-gray-100">
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="font-bold text-gray-800 flex items-center gap-2">
                                <i class="fas fa-star text-orange-500"></i>Mes communautés
                            </h3>
                            <a href="?my=1" class="text-xs text-orange-500 hover:underline">Voir tout</a>
                        </div>
                        <div class="space-y-3">
                            <?php foreach (array_slice($myCommunities, 0, 5) as $community): ?>
                                <div class="flex items-center gap-3">
                                    <div class="w-10 h-10 bg-gradient-to-br from-orange-500 to-red-500 rounded-xl flex items-center justify-center text-white font-bold text-sm">
                                        <?= strtoupper(substr($community['nom'], 0, 1)) ?>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <a href="communaute.php?id=<?= $community['id_communaute'] ?>" class="font-medium text-gray-800 hover:text-orange-500 truncate block text-sm">
                                            <?= htmlspecialchars($community['nom']) ?>
                                        </a>
                                        <p class="text-xs text-gray-400"><?= number_format($community['member_count']) ?> membres</p>
                                    </div>
                                    <?php if ($community['member_role'] == 'admin'): ?>
                                        <span class="text-xs bg-red-100 text-red-600 px-1.5 py-0.5 rounded">Admin</span>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
                
                <!-- Tendances -->
                <?php if (!empty($trendingCommunities)): ?>
                    <div class="bg-gradient-to-br from-orange-50 to-red-50 rounded-2xl p-5 border border-orange-100">
                        <h3 class="font-bold text-gray-800 mb-3 flex items-center gap-2">
                            <i class="fas fa-chart-line text-orange-500"></i>En tendance 🔥
                        </h3>
                        <div class="space-y-3">
                            <?php foreach ($trendingCommunities as $trend): ?>
                                <div class="flex items-center gap-2">
                                    <span class="text-orange-500 font-bold text-sm">#<?= $loop->iteration ?></span>
                                    <a href="communaute.php?id=<?= $trend['id_communaute'] ?>" class="flex-1 text-sm text-gray-700 hover:text-orange-500 truncate">
                                        <?= htmlspecialchars($trend['nom']) ?>
                                    </a>
                                    <span class="text-xs text-green-600">+<?= $trend['new_members'] ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Liste des communautés -->
            <div class="lg:col-span-3">
                <?php if (empty($communities)): ?>
                    <div class="bg-white rounded-2xl shadow-sm p-16 text-center">
                        <div class="w-32 h-32 bg-gradient-to-br from-gray-100 to-gray-200 rounded-full flex items-center justify-center mx-auto mb-6">
                            <i class="fas fa-users-slash text-4xl text-gray-400"></i>
                        </div>
                        <h3 class="text-2xl font-bold text-gray-700 mb-2">Aucune communauté trouvée</h3>
                        <p class="text-gray-500 mb-6 max-w-md mx-auto">
                            <?= !empty($search) ? "Aucune communauté ne correspond à \"$search\"" : "Aucune communauté n'a encore été créée" ?>
                        </p>
                        <button onclick="openCreateModal()" class="bg-gradient-to-r from-orange-500 to-red-500 text-white px-6 py-3 rounded-xl font-medium hover:shadow-lg transition-all">
                            <i class="fas fa-plus mr-2"></i>Créer la première communauté
                        </button>
                    </div>
                <?php else: ?>
                    
                    <!-- Compteur de résultats -->
                    <div class="flex items-center justify-between mb-6">
                        <p class="text-sm text-gray-500">
                            <i class="fas fa-chart-line mr-1"></i>
                            <?= number_format($totalCommunities) ?> résultat<?= $totalCommunities > 1 ? 's' : '' ?>
                            <?= $myOnly ? 'dans mes communautés' : ($search ? "pour \"$search\"" : '') ?>
                        </p>
                        <div class="flex gap-2">
                            <button onclick="setView('grid')" class="p-2 rounded-lg <?= $view == 'grid' ? 'bg-orange-100 text-orange-600' : 'bg-gray-100 text-gray-500' ?>">
                                <i class="fas fa-th-large"></i>
                            </button>
                            <button onclick="setView('list')" class="p-2 rounded-lg <?= $view == 'list' ? 'bg-orange-100 text-orange-600' : 'bg-gray-100 text-gray-500' ?>">
                                <i class="fas fa-list"></i>
                            </button>
                        </div>
                    </div>
                    
                    <!-- Affichage en grille -->
                    <?php if ($view == 'grid'): ?>
                        <div class="grid md:grid-cols-2 lg:grid-cols-3 gap-6">
                            <?php foreach ($communities as $community): 
                                $catInfo = $categories[$community['categorie']] ?? ['name' => $community['categorie'], 'icon' => 'tag', 'color' => 'gray', 'bg' => 'bg-gray-100', 'text' => 'text-gray-600'];
                            ?>
                                <div class="community-card bg-white rounded-2xl shadow-sm overflow-hidden hover:shadow-xl transition-all group">
                                    <!-- Cover -->
                                    <div class="h-32 bg-gradient-to-r from-orange-500 to-red-500 relative overflow-hidden">
                                        <?php if (!empty($community['image_couverture'])): ?>
                                            <img src="../uploads/covers/<?= htmlspecialchars($community['image_couverture']) ?>" class="w-full h-full object-cover group-hover:scale-110 transition-transform duration-500">
                                        <?php endif; ?>
                                        <div class="absolute inset-0 bg-gradient-to-t from-black/60 to-transparent"></div>
                                        <div class="absolute top-3 right-3">
                                            <span class="px-2 py-1 bg-white/90 backdrop-blur-sm rounded-lg text-xs font-medium <?= $catInfo['text'] ?>">
                                                <i class="fas fa-<?= $catInfo['icon'] ?> mr-1"></i><?= $catInfo['name'] ?>
                                            </span>
                                        </div>
                                        <div class="absolute bottom-3 left-3">
                                            <div class="flex items-center gap-2">
                                                <div class="w-10 h-10 bg-white rounded-xl flex items-center justify-center shadow-lg">
                                                    <span class="font-bold text-orange-500 text-lg"><?= strtoupper(substr($community['nom'], 0, 1)) ?></span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="p-5">
                                        <a href="communaute.php?id=<?= $community['id_communaute'] ?>" class="block">
                                            <h3 class="font-bold text-gray-800 text-lg hover:text-orange-500 transition-colors line-clamp-1">
                                                <?= htmlspecialchars($community['nom']) ?>
                                            </h3>
                                        </a>
                                        <p class="text-gray-500 text-sm mt-1 line-clamp-2"><?= htmlspecialchars($community['description'] ?? 'Aucune description') ?></p>
                                        
                                        <div class="flex items-center justify-between mt-4 pt-4 border-t border-gray-100">
                                            <div class="flex items-center gap-3 text-xs text-gray-500">
                                                <span class="flex items-center gap-1 stat-badge cursor-help" title="Membres">
                                                    <i class="fas fa-users text-orange-400"></i>
                                                    <?= number_format($community['member_count']) ?>
                                                </span>
                                                <span class="flex items-center gap-1 stat-badge cursor-help" title="Publications">
                                                    <i class="fas fa-newspaper text-orange-400"></i>
                                                    <?= number_format($community['posts_count'] ?? 0) ?>
                                                </span>
                                                <?php if (($community['new_members_week'] ?? 0) > 0): ?>
                                                    <span class="flex items-center gap-1 text-green-500">
                                                        <i class="fas fa-chart-line"></i>
                                                        +<?= $community['new_members_week'] ?>
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                            
                                            <?php if ($community['is_member']): ?>
                                                <span class="text-xs bg-green-100 text-green-700 px-2 py-1 rounded-full">
                                                    <i class="fas fa-check-circle mr-1"></i>Membre
                                                </span>
                                            <?php else: ?>
                                                <button onclick="joinCommunity(<?= $community['id_communaute'] ?>)" 
                                                        class="text-xs bg-gradient-to-r from-orange-500 to-red-500 text-white px-3 py-1.5 rounded-full hover:shadow-md transition-all">
                                                    <i class="fas fa-plus mr-1"></i>Rejoindre
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <!-- Affichage en liste -->
                        <div class="space-y-4">
                            <?php foreach ($communities as $community): 
                                $catInfo = $categories[$community['categorie']] ?? ['name' => $community['categorie'], 'icon' => 'tag', 'color' => 'gray'];
                            ?>
                                <div class="community-card bg-white rounded-2xl shadow-sm p-5 hover:shadow-lg transition-all flex flex-col md:flex-row md:items-center gap-5">
                                    <div class="flex items-center gap-4 flex-1">
                                        <div class="w-16 h-16 bg-gradient-to-br from-orange-500 to-red-500 rounded-xl flex items-center justify-center text-white text-2xl font-bold shadow-md flex-shrink-0">
                                            <?= strtoupper(substr($community['nom'], 0, 1)) ?>
                                        </div>
                                        <div class="flex-1 min-w-0">
                                            <div class="flex items-center gap-2 flex-wrap">
                                                <a href="communaute.php?id=<?= $community['id_communaute'] ?>" class="font-bold text-gray-800 hover:text-orange-500 text-lg">
                                                    <?= htmlspecialchars($community['nom']) ?>
                                                </a>
                                                <span class="px-2 py-0.5 <?= $catInfo['bg'] ?? 'bg-gray-100' ?> <?= $catInfo['text'] ?? 'text-gray-600' ?> rounded-full text-xs">
                                                    <i class="fas fa-<?= $catInfo['icon'] ?> mr-1"></i><?= $catInfo['name'] ?>
                                                </span>
                                            </div>
                                            <p class="text-gray-500 text-sm mt-1 line-clamp-1"><?= htmlspecialchars($community['description'] ?? 'Aucune description') ?></p>
                                            <div class="flex items-center gap-4 mt-2 text-xs text-gray-400">
                                                <span><i class="fas fa-user"></i> @<?= htmlspecialchars($community['creator_name']) ?></span>
                                                <span><i class="fas fa-calendar-alt"></i> <?= date('d M Y', strtotime($community['date_creation'])) ?></span>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="flex items-center gap-4">
                                        <div class="text-right">
                                            <div class="flex items-center gap-3 text-sm">
                                                <span class="flex items-center gap-1"><i class="fas fa-users text-orange-400"></i> <?= number_format($community['member_count']) ?></span>
                                                <span class="flex items-center gap-1"><i class="fas fa-newspaper text-orange-400"></i> <?= number_format($community['posts_count'] ?? 0) ?></span>
                                            </div>
                                            <?php if (($community['new_members_week'] ?? 0) > 0): ?>
                                                <span class="text-xs text-green-500">+<?= $community['new_members_week'] ?> nouveaux</span>
                                            <?php endif; ?>
                                        </div>
                                        <?php if ($community['is_member']): ?>
                                            <span class="bg-green-100 text-green-700 px-4 py-2 rounded-xl text-sm font-medium whitespace-nowrap">
                                                <i class="fas fa-check-circle mr-1"></i>Membre
                                            </span>
                                        <?php else: ?>
                                            <button onclick="joinCommunity(<?= $community['id_communaute'] ?>)" 
                                                    class="bg-gradient-to-r from-orange-500 to-red-500 text-white px-5 py-2 rounded-xl font-medium hover:shadow-lg transition-all whitespace-nowrap">
                                                <i class="fas fa-plus mr-1"></i>Rejoindre
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Pagination -->
                    <?php if ($totalPages > 1): ?>
                        <div class="flex justify-center gap-2 mt-8">
                            <?php if ($page > 1): ?>
                                <a href="?page=<?= $page-1 ?>&category=<?= $category ?>&sort=<?= $sort ?>&search=<?= urlencode($search) ?><?= $myOnly ? '&my=1' : '' ?>" 
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
                                    <a href="?page=<?= $i ?>&category=<?= $category ?>&sort=<?= $sort ?>&search=<?= urlencode($search) ?><?= $myOnly ? '&my=1' : '' ?>" 
                                       class="w-10 h-10 flex items-center justify-center rounded-xl transition-all <?= $i == $page ? 'bg-gradient-to-r from-orange-500 to-red-500 text-white shadow-md' : 'bg-white border hover:bg-gray-50' ?>">
                                        <?= $i ?>
                                    </a>
                                <?php endfor; ?>
                            </div>
                            
                            <?php if ($page < $totalPages): ?>
                                <a href="?page=<?= $page+1 ?>&category=<?= $category ?>&sort=<?= $sort ?>&search=<?= urlencode($search) ?><?= $myOnly ? '&my=1' : '' ?>" 
                                   class="px-4 py-2 bg-white border rounded-xl hover:bg-gray-50 transition-colors">
                                    Suivant <i class="fas fa-chevron-right"></i>
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Modal de création de communauté -->
    <div id="createModal" class="fixed inset-0 bg-black/60 backdrop-blur-sm z-50 hidden items-center justify-center p-4">
        <div class="bg-white rounded-2xl w-full max-w-lg shadow-2xl transform transition-all duration-300 scale-95 opacity-0" id="createModalContent">
            <div class="p-5 border-b border-gray-100 flex items-center justify-between bg-gradient-to-r from-orange-50 to-red-50 rounded-t-2xl">
                <h3 class="text-xl font-bold text-gray-800 flex items-center gap-2">
                    <i class="fas fa-plus-circle text-orange-500"></i>Créer une communauté
                </h3>
                <button onclick="closeCreateModal()" class="w-8 h-8 hover:bg-white/50 rounded-full flex items-center justify-center transition-colors">
                    <i class="fas fa-times text-gray-500"></i>
                </button>
            </div>
            <form id="createCommunityForm" enctype="multipart/form-data" class="p-5 space-y-4">
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                <input type="hidden" name="action" value="create_community">
                
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">Nom de la communauté *</label>
                    <div class="relative">
                        <i class="fas fa-tag absolute left-4 top-1/2 -translate-y-1/2 text-gray-400"></i>
                        <input type="text" name="nom" required placeholder="Ex: Informatique Université Kinshasa"
                               class="w-full pl-12 pr-4 py-3 border border-gray-200 rounded-xl focus:border-orange-500 focus:ring-2 focus:ring-orange-100 outline-none transition-all">
                    </div>
                </div>
                
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">Catégorie</label>
                    <select name="categorie" class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:border-orange-500 outline-none">
                        <option value="Academic">🎓 Académique</option>
                        <option value="Club">👥 Club</option>
                        <option value="Social">❤️ Social</option>
                        <option value="Sports">⚽ Sports</option>
                        <option value="Arts">🎨 Arts & Culture</option>
                        <option value="Tech">💻 Technologie</option>
                        <option value="Career">💼 Carrière</option>
                    </select>
                </div>
                
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">Description</label>
                    <textarea name="description" rows="4" placeholder="Décrivez le but et les activités de cette communauté..."
                              class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:border-orange-500 focus:ring-2 focus:ring-orange-100 outline-none resize-none"></textarea>
                    <p class="text-xs text-gray-400 mt-1">Maximum 500 caractères</p>
                </div>
                
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">Photo de couverture</label>
                    <div class="border-2 border-dashed border-gray-200 rounded-xl p-6 text-center hover:border-orange-500 transition-all cursor-pointer group" onclick="document.getElementById('coverInput').click()">
                        <i class="fas fa-cloud-upload-alt text-4xl text-gray-400 mb-3 group-hover:text-orange-500 transition-colors"></i>
                        <p class="text-sm text-gray-500">Cliquez pour télécharger une image</p>
                        <p class="text-xs text-gray-400">JPG, PNG ou GIF • Max 5MB • Format paysage recommandé</p>
                    </div>
                    <input type="file" name="cover" id="coverInput" accept="image/*" class="hidden">
                    <div id="coverPreview" class="hidden mt-3 relative rounded-xl overflow-hidden">
                        <img src="" class="w-full h-40 object-cover rounded-xl">
                        <button type="button" onclick="removeCover()" class="absolute top-2 right-2 bg-red-500 text-white rounded-full w-8 h-8 flex items-center justify-center hover:bg-red-600 transition-colors">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </div>
                
                <button type="submit" class="w-full bg-gradient-to-r from-orange-500 to-red-500 text-white py-3 rounded-xl font-semibold hover:shadow-xl transition-all flex items-center justify-center gap-2">
                    <i class="fas fa-plus-circle"></i>Créer la communauté
                </button>
            </form>
        </div>
    </div>
    
    <!-- Toast Container -->
    <div id="toastContainer" class="fixed bottom-4 right-4 z-50 space-y-2"></div>
    
    <script>
        const csrfToken = '<?= $csrfToken ?>';
        
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
        
        document.getElementById('createCommunityForm')?.addEventListener('submit', async (e) => {
            e.preventDefault();
            const formData = new FormData(e.target);
            
            const submitBtn = e.target.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Création en cours...';
            
            try {
                const response = await fetch('../api/communities.php', {
                    method: 'POST',
                    body: formData
                });
                const data = await response.json();
                if (data.success) {
                    showToast('Communauté créée avec succès !', 'success');
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showToast(data.error || 'Erreur lors de la création', 'error');
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = originalText;
                }
            } catch (err) {
                console.error('Error:', err);
                showToast('Erreur de connexion', 'error');
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalText;
            }
        });
        
        function openCreateModal() {
            const modal = document.getElementById('createModal');
            const content = document.getElementById('createModalContent');
            modal.classList.remove('hidden');
            modal.classList.add('flex');
            setTimeout(() => {
                content.classList.remove('scale-95', 'opacity-0');
                content.classList.add('scale-100', 'opacity-100');
            }, 10);
        }
        
        function closeCreateModal() {
            const modal = document.getElementById('createModal');
            const content = document.getElementById('createModalContent');
            content.classList.remove('scale-100', 'opacity-100');
            content.classList.add('scale-95', 'opacity-0');
            setTimeout(() => {
                modal.classList.add('hidden');
                modal.classList.remove('flex');
                document.getElementById('createCommunityForm')?.reset();
                removeCover();
            }, 300);
        }
        
        document.getElementById('coverInput')?.addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = function(event) {
                    const preview = document.getElementById('coverPreview');
                    const img = preview.querySelector('img');
                    img.src = event.target.result;
                    preview.classList.remove('hidden');
                };
                reader.readAsDataURL(file);
            }
        });
        
        function removeCover() {
            document.getElementById('coverInput').value = '';
            document.getElementById('coverPreview').classList.add('hidden');
        }
        
        function setView(view) {
            const url = new URL(window.location.href);
            url.searchParams.set('view', view);
            window.location.href = url.toString();
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
        
        // Fermer le modal en cliquant à l'extérieur
        document.getElementById('createModal')?.addEventListener('click', (e) => {
            if (e.target === e.currentTarget) closeCreateModal();
        });
        
        // Recherche automatique
        const searchForm = document.getElementById('searchForm');
        if (searchForm) {
            const searchInput = searchForm.querySelector('input[name="search"]');
            let searchTimeout;
            searchInput.addEventListener('input', () => {
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(() => searchForm.submit(), 500);
            });
        }
    </script>
</body>
</html>