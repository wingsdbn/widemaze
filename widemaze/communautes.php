<?php
/**
 * WideMaze - Communautés Académiques
 * Plateforme de groupes par filière, université et intérêts
 */

require_once 'config.php';
require_auth();

$userId = $_SESSION['user_id'];
$page = max(1, intval($_GET['page'] ?? 1));
$limit = 12;
$offset = ($page - 1) * $limit;
$category = $_GET['category'] ?? 'all';
$sort = $_GET['sort'] ?? 'popular'; // popular, recent, name
$search = trim($_GET['search'] ?? '');

// Récupération des communautés
$communities = [];
$totalCommunities = 0;

try {
    // Construction de la requête
    $sql = "
        SELECT c.*, u.surnom as creator_name, u.avatar as creator_avatar,
               (SELECT COUNT(*) FROM communaute_membres WHERE id_communaute = c.id_communaute) as member_count,
               (SELECT COUNT(*) FROM posts WHERE id_communaute = c.id_communaute) as posts_count,
               (SELECT EXISTS(SELECT 1 FROM communaute_membres WHERE id_communaute = c.id_communaute AND id_utilisateur = ?)) as is_member,
               (SELECT created_at FROM communaute_membres WHERE id_communaute = c.id_communaute AND id_utilisateur = ?) as joined_at
        FROM communautes c
        JOIN utilisateurs u ON c.id_createur = u.id
        WHERE c.is_active = 1
    ";
    
    $params = [$userId, $userId];
    
    // Filtre par catégorie
    if ($category !== 'all') {
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
        case 'popular':
        default:
            $sql .= " ORDER BY member_count DESC";
            break;
    }
    
    // Pagination
    $sql .= " LIMIT ? OFFSET ?";
    $params[] = $limit;
    $params[] = $offset;
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $communities = $stmt->fetchAll();
    
    // Compter le total pour la pagination
    $countSql = "
        SELECT COUNT(*) FROM communautes c
        WHERE c.is_active = 1
    ";
    $countParams = [];
    
    if ($category !== 'all') {
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
    error_log("Erreur récupération communautés: " . $e->getMessage());
}

// Récupération des communautés recommandées (pour la sidebar)
$recommendedCommunities = [];
try {
    $stmt = $pdo->prepare("
        SELECT c.*, u.surnom as creator_name,
               (SELECT COUNT(*) FROM communaute_membres WHERE id_communaute = c.id_communaute) as member_count,
               EXISTS(SELECT 1 FROM communaute_membres WHERE id_communaute = c.id_communaute AND id_utilisateur = ?) as is_member
        FROM communautes c
        JOIN utilisateurs u ON c.id_createur = u.id
        WHERE c.is_active = 1
        ORDER BY member_count DESC
        LIMIT 5
    ");
    $stmt->execute([$userId]);
    $recommendedCommunities = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Erreur recommandations: " . $e->getMessage());
}

// Récupération des communautés de l'utilisateur
$myCommunities = [];
try {
    $stmt = $pdo->prepare("
        SELECT c.*, u.surnom as creator_name,
               (SELECT COUNT(*) FROM communaute_membres WHERE id_communaute = c.id_communaute) as member_count,
               cm.created_at as joined_at
        FROM communaute_membres cm
        JOIN communautes c ON cm.id_communaute = c.id_communaute
        JOIN utilisateurs u ON c.id_createur = u.id
        WHERE cm.id_utilisateur = ? AND c.is_active = 1
        ORDER BY cm.created_at DESC
        LIMIT 5
    ");
    $stmt->execute([$userId]);
    $myCommunities = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Erreur mes communautés: " . $e->getMessage());
}

// Catégories disponibles
$categories = [
    'all' => ['name' => 'Toutes', 'icon' => 'globe', 'color' => 'gray'],
    'Academic' => ['name' => 'Académique', 'icon' => 'graduation-cap', 'color' => 'blue'],
    'Club' => ['name' => 'Club', 'icon' => 'users', 'color' => 'green'],
    'Social' => ['name' => 'Social', 'icon' => 'heart', 'color' => 'pink'],
    'Sports' => ['name' => 'Sports', 'icon' => 'futbol', 'color' => 'orange']
];

$totalPages = ceil($totalCommunities / $limit);
$csrfToken = generate_csrf_token();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Communautés - WideMaze</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: '#f59e0b',
                        secondary: '#1e293b',
                    },
                    fontFamily: {
                        sans: ['Inter', 'sans-serif'],
                    },
                    animation: {
                        'fade-in': 'fadeIn 0.3s ease-out',
                        'slide-up': 'slideUp 0.3s ease-out',
                    },
                    keyframes: {
                        fadeIn: {
                            '0%': { opacity: '0' },
                            '100%': { opacity: '1' },
                        },
                        slideUp: {
                            '0%': { transform: 'translateY(20px)', opacity: '0' },
                            '100%': { transform: 'translateY(0)', opacity: '1' },
                        },
                    },
                }
            }
        }
    </script>
    <style>
        .community-card:hover { transform: translateY(-4px); transition: all 0.3s ease; }
        .category-active { background-color: #f59e0b; color: white; border-color: #f59e0b; }
        .gradient-bg { background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); }
        .skeleton { background: linear-gradient(90deg, #f0f0f0 25%, #e0e0e0 50%, #f0f0f0 75%); background-size: 200% 100%; animation: loading 1.5s infinite; }
        @keyframes loading { 0% { background-position: 200% 0; } 100% { background-position: -200% 0; } }
    </style>
</head>
<body class="bg-gray-100">

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
                <a href="messagerie.php" class="p-2 hover:bg-gray-100 rounded-full transition-colors">
                    <i class="fas fa-comment text-gray-600"></i>
                </a>
                <div class="relative group">
                    <button class="flex items-center gap-2 p-1">
                        <img src="<?= AVATAR_URL . htmlspecialchars($_SESSION['avatar'] ?? 'default.jpg') ?>" class="w-8 h-8 rounded-full object-cover border-2 border-orange-500">
                    </button>
                    <div class="absolute right-0 top-full mt-2 w-48 bg-white rounded-xl shadow-xl opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all duration-200 z-50">
                        <a href="profil.php" class="block px-4 py-2 hover:bg-gray-50 rounded-t-xl">Mon profil</a>
                        <a href="parametres.php" class="block px-4 py-2 hover:bg-gray-50">Paramètres</a>
                        <hr>
                        <a href="deconnexion.php" class="block px-4 py-2 hover:bg-gray-50 rounded-b-xl text-red-600">Déconnexion</a>
                    </div>
                </div>
            </div>
        </div>
    </nav>

    <div class="container mx-auto pt-20 pb-8 px-4">
        
        <!-- En-tête -->
        <div class="text-center mb-8">
            <h1 class="text-4xl font-bold text-gray-800 mb-3">Communautés Académiques</h1>
            <p class="text-gray-600 text-lg">Rejoignez des groupes d'étudiants partageant vos intérêts et votre filière</p>
        </div>
        
        <!-- Barre de recherche et création -->
        <div class="flex flex-col md:flex-row gap-4 mb-8">
            <div class="flex-1 relative">
                <i class="fas fa-search absolute left-4 top-1/2 -translate-y-1/2 text-gray-400"></i>
                <form method="get" action="" id="searchForm">
                    <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" 
                           placeholder="Rechercher une communauté par nom ou description..."
                           class="w-full pl-11 pr-4 py-3 bg-white border border-gray-200 rounded-xl focus:border-orange-500 focus:outline-none focus:ring-2 focus:ring-orange-100 transition-all">
                </form>
            </div>
            <button onclick="openCreateModal()" 
                    class="gradient-bg text-white px-6 py-3 rounded-xl font-semibold hover:shadow-lg transition-all flex items-center justify-center gap-2">
                <i class="fas fa-plus"></i>
                <span>Créer une communauté</span>
            </button>
        </div>
        
        <!-- Catégories -->
        <div class="flex flex-wrap gap-2 mb-8">
            <?php foreach ($categories as $key => $cat): ?>
            <a href="?category=<?= $key ?>&sort=<?= $sort ?>&search=<?= urlencode($search) ?>" 
               class="px-5 py-2 rounded-full text-sm font-medium transition-all <?= $category === $key ? 'bg-orange-500 text-white shadow-md' : 'bg-white text-gray-700 hover:bg-gray-100 border border-gray-200' ?>">
                <i class="fas fa-<?= $cat['icon'] ?> mr-2"></i><?= $cat['name'] ?>
            </a>
            <?php endforeach; ?>
        </div>
        
        <div class="grid lg:grid-cols-4 gap-6">
            
            <!-- Sidebar -->
            <div class="lg:col-span-1 space-y-6">
                <!-- Mes communautés -->
                <?php if (!empty($myCommunities)): ?>
                <div class="bg-white rounded-2xl shadow-sm p-5">
                    <h3 class="font-bold text-gray-800 mb-4 flex items-center gap-2">
                        <i class="fas fa-users text-orange-500"></i>
                        Mes communautés
                    </h3>
                    <div class="space-y-3">
                        <?php foreach ($myCommunities as $community): ?>
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 bg-gradient-to-br from-orange-500 to-red-600 rounded-xl flex items-center justify-center text-white font-bold">
                                <?= strtoupper(substr($community['nom'], 0, 1)) ?>
                            </div>
                            <div class="flex-1 min-w-0">
                                <a href="communaute.php?id=<?= $community['id_communaute'] ?>" class="font-medium text-gray-800 hover:text-orange-500 truncate block">
                                    <?= htmlspecialchars($community['nom']) ?>
                                </a>
                                <p class="text-xs text-gray-400"><?= date('d/m/Y', strtotime($community['joined_at'])) ?></p>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php if (count($myCommunities) >= 5): ?>
                    <a href="?tab=my" class="mt-4 block text-center text-sm text-orange-500 hover:text-orange-600">Voir toutes mes communautés →</a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                
                <!-- Communautés recommandées -->
                <div class="bg-white rounded-2xl shadow-sm p-5">
                    <h3 class="font-bold text-gray-800 mb-4 flex items-center gap-2">
                        <i class="fas fa-star text-orange-500"></i>
                        Recommandées
                    </h3>
                    <div class="space-y-3">
                        <?php foreach ($recommendedCommunities as $community): ?>
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 bg-gradient-to-br from-orange-500 to-red-600 rounded-xl flex items-center justify-center text-white font-bold">
                                <?= strtoupper(substr($community['nom'], 0, 1)) ?>
                            </div>
                            <div class="flex-1 min-w-0">
                                <a href="communaute.php?id=<?= $community['id_communaute'] ?>" class="font-medium text-gray-800 hover:text-orange-500 truncate block">
                                    <?= htmlspecialchars($community['nom']) ?>
                                </a>
                                <p class="text-xs text-gray-400"><?= number_format($community['member_count']) ?> membres</p>
                            </div>
                            <?php if (!$community['is_member']): ?>
                            <button onclick="joinCommunity(<?= $community['id_communaute'] ?>)" 
                                    class="text-xs bg-orange-500 text-white px-3 py-1 rounded-full hover:bg-orange-600 transition-colors">
                                Rejoindre
                            </button>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <!-- Tri -->
                <div class="bg-white rounded-2xl shadow-sm p-5">
                    <h3 class="font-bold text-gray-800 mb-3 flex items-center gap-2">
                        <i class="fas fa-sort-amount-down text-orange-500"></i>
                        Trier par
                    </h3>
                    <div class="space-y-2">
                        <a href="?category=<?= $category ?>&sort=popular&search=<?= urlencode($search) ?>" 
                           class="block px-3 py-2 rounded-lg text-sm <?= $sort === 'popular' ? 'bg-orange-50 text-orange-600 font-medium' : 'text-gray-600 hover:bg-gray-50' ?>">
                            <i class="fas fa-fire mr-2"></i>Plus populaires
                        </a>
                        <a href="?category=<?= $category ?>&sort=recent&search=<?= urlencode($search) ?>" 
                           class="block px-3 py-2 rounded-lg text-sm <?= $sort === 'recent' ? 'bg-orange-50 text-orange-600 font-medium' : 'text-gray-600 hover:bg-gray-50' ?>">
                            <i class="fas fa-clock mr-2"></i>Plus récentes
                        </a>
                        <a href="?category=<?= $category ?>&sort=name&search=<?= urlencode($search) ?>" 
                           class="block px-3 py-2 rounded-lg text-sm <?= $sort === 'name' ? 'bg-orange-50 text-orange-600 font-medium' : 'text-gray-600 hover:bg-gray-50' ?>">
                            <i class="fas fa-sort-alpha-down mr-2"></i>Nom (A-Z)
                        </a>
                    </div>
                </div>
            </div>
            
            <!-- Liste des communautés -->
            <div class="lg:col-span-3">
                <?php if (empty($communities)): ?>
                <div class="bg-white rounded-2xl shadow-sm p-12 text-center">
                    <div class="w-24 h-24 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-users-slash text-4xl text-gray-300"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-700 mb-2">Aucune communauté trouvée</h3>
                    <p class="text-gray-500 mb-6"><?= !empty($search) ? "Aucune communauté ne correspond à \"$search\"" : "Aucune communauté n'a encore été créée" ?></p>
                    <button onclick="openCreateModal()" class="bg-orange-500 text-white px-6 py-2 rounded-xl hover:bg-orange-600 transition-colors">
                        <i class="fas fa-plus mr-2"></i>Créer la première communauté
                    </button>
                </div>
                <?php else: ?>
                <div class="grid md:grid-cols-2 gap-6">
                    <?php foreach ($communities as $community): ?>
                    <div class="community-card bg-white rounded-2xl shadow-sm overflow-hidden hover:shadow-xl transition-all animate-fade-in">
                        <!-- Cover image -->
                        <div class="h-32 bg-gradient-to-r from-orange-500 to-red-600 relative">
                            <?php if (!empty($community['image_couverture'])): ?>
                            <img src="uploads/covers/<?= htmlspecialchars($community['image_couverture']) ?>" 
                                 class="w-full h-full object-cover" alt="Cover">
                            <?php endif; ?>
                            <div class="absolute top-3 right-3">
                                <span class="px-2 py-1 bg-white/90 backdrop-blur-sm rounded-lg text-xs font-medium text-gray-700">
                                    <i class="fas fa-<?= $categories[$community['categorie']]['icon'] ?? 'tag' ?> mr-1"></i>
                                    <?= $categories[$community['categorie']]['name'] ?? $community['categorie'] ?>
                                </span>
                            </div>
                        </div>
                        
                        <div class="p-5">
                            <div class="flex items-start gap-3 mb-3">
                                <div class="w-12 h-12 bg-gradient-to-br from-orange-500 to-red-600 rounded-xl flex items-center justify-center text-white text-xl font-bold shadow-md flex-shrink-0">
                                    <?= strtoupper(substr($community['nom'], 0, 1)) ?>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <a href="communaute.php?id=<?= $community['id_communaute'] ?>" 
                                       class="text-lg font-bold text-gray-800 hover:text-orange-500 transition-colors truncate block">
                                        <?= htmlspecialchars($community['nom']) ?>
                                    </a>
                                    <div class="flex items-center gap-3 text-xs text-gray-400 mt-1">
                                        <span><i class="fas fa-user"></i> @<?= htmlspecialchars($community['creator_name']) ?></span>
                                        <span><i class="fas fa-calendar-alt"></i> <?= date('d/m/Y', strtotime($community['date_creation'])) ?></span>
                                    </div>
                                </div>
                            </div>
                            
                            <p class="text-gray-600 text-sm mb-4 line-clamp-2">
                                <?= htmlspecialchars($community['description'] ?? 'Aucune description') ?>
                            </p>
                            
                            <div class="flex items-center justify-between mb-4">
                                <div class="flex items-center gap-3 text-sm">
                                    <span class="flex items-center gap-1 text-gray-500">
                                        <i class="fas fa-users"></i>
                                        <?= number_format($community['member_count']) ?> membres
                                    </span>
                                    <span class="flex items-center gap-1 text-gray-500">
                                        <i class="fas fa-newspaper"></i>
                                        <?= number_format($community['posts_count'] ?? 0) ?> posts
                                    </span>
                                </div>
                                <?php if ($community['is_member']): ?>
                                <span class="bg-green-100 text-green-700 px-3 py-1 rounded-full text-xs font-medium">
                                    <i class="fas fa-check mr-1"></i>Membre
                                </span>
                                <?php else: ?>
                                <button onclick="joinCommunity(<?= $community['id_communaute'] ?>)" 
                                        class="bg-orange-500 text-white px-4 py-1.5 rounded-lg text-sm font-medium hover:bg-orange-600 transition-colors">
                                    <i class="fas fa-sign-in-alt mr-1"></i>Rejoindre
                                </button>
                                <?php endif; ?>
                            </div>
                            
                            <?php if ($community['is_member']): ?>
                            <div class="border-t border-gray-100 pt-3 mt-2">
                                <a href="communaute.php?id=<?= $community['id_communaute'] ?>" 
                                   class="text-orange-500 text-sm hover:text-orange-600 flex items-center gap-1">
                                    <i class="fas fa-arrow-right"></i>
                                    <span>Voir la communauté</span>
                                </a>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                
                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                <div class="flex justify-center gap-2 mt-8">
                    <?php if ($page > 1): ?>
                    <a href="?page=<?= $page-1 ?>&category=<?= $category ?>&sort=<?= $sort ?>&search=<?= urlencode($search) ?>" 
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
                        <a href="?page=<?= $i ?>&category=<?= $category ?>&sort=<?= $sort ?>&search=<?= urlencode($search) ?>" 
                           class="w-10 h-10 flex items-center justify-center rounded-lg <?= $i === $page ? 'bg-orange-500 text-white' : 'bg-white border hover:bg-gray-50' ?> transition-colors">
                            <?= $i ?>
                        </a>
                        <?php endfor; ?>
                    </div>
                    
                    <?php if ($page < $totalPages): ?>
                    <a href="?page=<?= $page+1 ?>&category=<?= $category ?>&sort=<?= $sort ?>&search=<?= urlencode($search) ?>" 
                       class="px-4 py-2 bg-white border rounded-lg hover:bg-gray-50 transition-colors">
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
    <div id="createModal" class="fixed inset-0 bg-black/50 backdrop-blur-sm z-50 hidden items-center justify-center p-4">
        <div class="bg-white rounded-2xl w-full max-w-lg shadow-2xl transform transition-all duration-300 animate-slide-up">
            <div class="p-5 border-b border-gray-100 flex items-center justify-between">
                <h3 class="text-xl font-bold text-gray-800">Créer une communauté</h3>
                <button onclick="closeCreateModal()" class="w-8 h-8 hover:bg-gray-100 rounded-full flex items-center justify-center transition-colors">
                    <i class="fas fa-times text-gray-500"></i>
                </button>
            </div>
            
            <form id="createCommunityForm" enctype="multipart/form-data" class="p-5 space-y-4">
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">Nom de la communauté *</label>
                    <input type="text" name="nom" required 
                           placeholder="Ex: Informatique Université Kinshasa"
                           class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:border-orange-500 focus:ring-2 focus:ring-orange-100 outline-none transition-all">
                </div>
                
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">Catégorie</label>
                    <select name="categorie" class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:border-orange-500 outline-none">
                        <option value="Academic">📚 Académique</option>
                        <option value="Club">🎯 Club</option>
                        <option value="Social">💬 Social</option>
                        <option value="Sports">⚽ Sports</option>
                    </select>
                </div>
                
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">Description</label>
                    <textarea name="description" rows="4" 
                              placeholder="Décrivez le but et les activités de cette communauté..."
                              class="w-full px-4 py-2 border border-gray-200 rounded-lg focus:border-orange-500 focus:ring-2 focus:ring-orange-100 outline-none resize-none"></textarea>
                    <p class="text-xs text-gray-400 mt-1">Maximum 500 caractères</p>
                </div>
                
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">Photo de couverture</label>
                    <div class="border-2 border-dashed border-gray-200 rounded-xl p-4 text-center hover:border-orange-500 transition-colors cursor-pointer" 
                         onclick="document.getElementById('coverInput').click()">
                        <i class="fas fa-cloud-upload-alt text-3xl text-gray-400 mb-2"></i>
                        <p class="text-sm text-gray-500">Cliquez pour télécharger une image</p>
                        <p class="text-xs text-gray-400">JPG, PNG ou GIF • Max 5MB</p>
                    </div>
                    <input type="file" name="cover" id="coverInput" accept="image/*" class="hidden">
                    <div id="coverPreview" class="hidden mt-3 relative rounded-lg overflow-hidden">
                        <img src="" class="w-full h-32 object-cover">
                        <button type="button" onclick="removeCover()" class="absolute top-2 right-2 bg-red-500 text-white rounded-full w-6 h-6 flex items-center justify-center text-xs">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </div>
                
                <button type="submit" 
                        class="w-full gradient-bg text-white py-3 rounded-xl font-semibold hover:shadow-lg transition-all">
                    <i class="fas fa-plus-circle mr-2"></i>Créer la communauté
                </button>
            </form>
        </div>
    </div>
    
    <script>
        const csrfToken = '<?= $csrfToken ?>';
        
        // Rejoindre une communauté
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
                } else {
                    alert(data.error || 'Erreur lors de l\'inscription');
                }
            } catch (err) {
                console.error('Erreur:', err);
                alert('Erreur de connexion');
            }
        }
        
        // Créer une communauté
        document.getElementById('createCommunityForm')?.addEventListener('submit', async (e) => {
            e.preventDefault();
            
            const formData = new FormData(e.target);
            formData.append('action', 'create_community');
            
            const submitBtn = e.target.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Création...';
            
            try {
                const response = await fetch('api/communities.php', {
                    method: 'POST',
                    body: formData
                });
                const data = await response.json();
                
                if (data.success) {
                    location.reload();
                } else {
                    alert(data.error || 'Erreur lors de la création');
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = originalText;
                }
            } catch (err) {
                console.error('Erreur:', err);
                alert('Erreur de connexion');
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalText;
            }
        });
        
        // Modal
        function openCreateModal() {
            const modal = document.getElementById('createModal');
            modal.classList.remove('hidden');
            modal.classList.add('flex');
        }
        
        function closeCreateModal() {
            const modal = document.getElementById('createModal');
            modal.classList.remove('flex');
            modal.classList.add('hidden');
            document.getElementById('createCommunityForm')?.reset();
            removeCover();
        }
        
        // Gestion de l'aperçu de la couverture
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