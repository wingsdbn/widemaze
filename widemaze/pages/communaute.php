<?php
/**
 * WideMaze - Page détaillée d'une communauté
 * Version 4.0 - Feed, membres, événements, ressources
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_auth();

$userId = $_SESSION['user_id'];
$communityId = isset($_GET['id']) ? intval($_GET['id']) : 0;
$activeTab = $_GET['tab'] ?? 'feed';
$page = max(1, intval($_GET['page'] ?? 1));
$limit = 15;
$offset = ($page - 1) * $limit;

if (!$communityId) {
    header('Location: communautes.php');
    exit();
}

// Récupération des informations de la communauté
$community = null;
try {
    $stmt = $pdo->prepare("
        SELECT c.*, u.surnom as creator_name, u.avatar as creator_avatar, u.id as creator_id,
            (SELECT COUNT(*) FROM communaute_membres WHERE id_communaute = c.id_communaute) as member_count,
            (SELECT COUNT(*) FROM posts WHERE id_communaute = c.id_communaute) as posts_count,
            (SELECT COUNT(*) FROM posts WHERE id_communaute = c.id_communaute AND date_publication > DATE_SUB(NOW(), INTERVAL 7 DAY)) as posts_week,
            (SELECT COUNT(*) FROM communaute_membres WHERE id_communaute = c.id_communaute AND created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)) as new_members_week
        FROM communautes c
        JOIN utilisateurs u ON c.id_createur = u.id
        WHERE c.id_communaute = ? AND c.is_active = 1
    ");
    $stmt->execute([$communityId]);
    $community = $stmt->fetch();
    
    if (!$community) {
        header('Location: communautes.php');
        exit();
    }
} catch (PDOException $e) {
    error_log("Error fetching community: " . $e->getMessage());
    header('Location: communautes.php');
    exit();
}

// Vérifier si l'utilisateur est membre
$isMember = false;
$memberRole = null;
$joinedAt = null;
try {
    $stmt = $pdo->prepare("SELECT role, created_at FROM communaute_membres WHERE id_communaute = ? AND id_utilisateur = ?");
    $stmt->execute([$communityId, $userId]);
    $membership = $stmt->fetch();
    if ($membership) {
        $isMember = true;
        $memberRole = $membership['role'];
        $joinedAt = $membership['created_at'];
    }
} catch (PDOException $e) {
    error_log("Error checking membership: " . $e->getMessage());
}

// Vérifier si l'utilisateur est le créateur
$isCreator = ($community['creator_id'] == $userId);
$isModerator = $isCreator || $memberRole == 'moderator';

// Catégories pour l'affichage
$categories = [
    'Academic' => ['name' => 'Académique', 'icon' => 'graduation-cap', 'color' => 'blue', 'bg' => 'bg-blue-100', 'text' => 'text-blue-600'],
    'Club' => ['name' => 'Club', 'icon' => 'users', 'color' => 'green', 'bg' => 'bg-green-100', 'text' => 'text-green-600'],
    'Social' => ['name' => 'Social', 'icon' => 'heart', 'color' => 'pink', 'bg' => 'bg-pink-100', 'text' => 'text-pink-600'],
    'Sports' => ['name' => 'Sports', 'icon' => 'futbol', 'color' => 'orange', 'bg' => 'bg-orange-100', 'text' => 'text-orange-600'],
    'Arts' => ['name' => 'Arts & Culture', 'icon' => 'palette', 'color' => 'purple', 'bg' => 'bg-purple-100', 'text' => 'text-purple-600'],
    'Tech' => ['name' => 'Technologie', 'icon' => 'microchip', 'color' => 'indigo', 'bg' => 'bg-indigo-100', 'text' => 'text-indigo-600'],
    'Career' => ['name' => 'Carrière', 'icon' => 'briefcase', 'color' => 'yellow', 'bg' => 'bg-yellow-100', 'text' => 'text-yellow-700']
];
$categoryInfo = $categories[$community['categorie']] ?? ['name' => $community['categorie'], 'icon' => 'tag', 'color' => 'gray', 'bg' => 'bg-gray-100', 'text' => 'text-gray-600'];

// Récupération des posts de la communauté
$posts = [];
$totalPosts = 0;
try {
    $sql = "
        SELECT p.*, u.surnom, u.avatar, u.prenom, u.nom,
            (SELECT COUNT(*) FROM postlike WHERE idpost = p.idpost) as likes_count,
            (SELECT COUNT(*) FROM postcommentaire WHERE idpost = p.idpost) as comments_count,
            (SELECT EXISTS(SELECT 1 FROM postlike WHERE idpost = p.idpost AND id = ?)) as user_liked
        FROM posts p
        JOIN utilisateurs u ON p.id_utilisateur = u.id
        WHERE p.id_communaute = ?
        ORDER BY p.date_publication DESC
        LIMIT ? OFFSET ?
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$userId, $communityId, $limit, $offset]);
    $posts = $stmt->fetchAll();
    
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM posts WHERE id_communaute = ?");
    $countStmt->execute([$communityId]);
    $totalPosts = $countStmt->fetchColumn();
} catch (PDOException $e) {
    error_log("Error fetching community posts: " . $e->getMessage());
}

// Récupération des membres (avec pagination)
$members = [];
$totalMembers = 0;
$membersPage = isset($_GET['members_page']) ? max(1, intval($_GET['members_page'])) : 1;
$membersLimit = 12;
$membersOffset = ($membersPage - 1) * $membersLimit;

try {
    $stmt = $pdo->prepare("
    SELECT u.id, u.surnom, u.prenom, u.nom, u.avatar, u.status, cm.role, 
           COALESCE(cm.created_at, cm.created_at) as joined_at
    FROM communaute_membres cm
    JOIN utilisateurs u ON cm.id_utilisateur = u.id
    WHERE cm.id_communaute = ?
    ORDER BY cm.role = 'admin' DESC, cm.role = 'moderator' DESC, cm.created_at ASC
    LIMIT ? OFFSET ?
");
    $stmt->execute([$communityId, $membersLimit, $membersOffset]);
    $members = $stmt->fetchAll();
    
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM communaute_membres WHERE id_communaute = ?");
    $countStmt->execute([$communityId]);
    $totalMembers = $countStmt->fetchColumn();
} catch (PDOException $e) {
    error_log("Error fetching members: " . $e->getMessage());
}
$totalMembersPages = ceil($totalMembers / $membersLimit);

// Récupération des événements de la communauté (si existent)
$events = [];
try {
    $stmt = $pdo->prepare("
        SELECT * FROM community_events 
        WHERE community_id = ? AND event_date > NOW()
        ORDER BY event_date ASC
        LIMIT 5
    ");
    $stmt->execute([$communityId]);
    $events = $stmt->fetchAll();
} catch (PDOException $e) {
    // Table peut ne pas exister encore
    $events = [];
}

// Récupération des ressources de la communauté
$resources = [];
try {
    $stmt = $pdo->prepare("
        SELECT * FROM community_resources 
        WHERE community_id = ?
        ORDER BY created_at DESC
        LIMIT 5
    ");
    $stmt->execute([$communityId]);
    $resources = $stmt->fetchAll();
} catch (PDOException $e) {
    // Table peut ne pas exister encore
    $resources = [];
}

$csrfToken = generate_csrf_token();
$page_title = $community['nom'] . ' - Communauté';
$totalPages = ceil($totalPosts / $limit);
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
        
        .cover-gradient {
            background: linear-gradient(to bottom, rgba(0,0,0,0.4) 0%, rgba(0,0,0,0) 50%, rgba(0,0,0,0.4) 100%);
        }
        .tab-btn {
            transition: all 0.2s;
            position: relative;
        }
        .tab-active {
            color: #f59e0b;
        }
        .tab-active::after {
            content: '';
            position: absolute;
            bottom: -2px;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(90deg, #f59e0b, #ea580c);
            border-radius: 3px;
        }
        .member-card {
            transition: all 0.2s;
        }
        .member-card:hover {
            transform: translateY(-4px);
            background-color: #fef3c7;
        }
        .stat-card {
            transition: all 0.3s;
        }
        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px -5px rgba(0,0,0,0.1);
        }
    </style>
</head>
<body class="min-h-screen">
    
    <!-- Navigation -->
    <nav class="fixed top-0 w-full bg-white/95 backdrop-blur-md shadow-lg z-50 border-b border-gray-100">
        <div class="container mx-auto px-4 h-16 flex items-center justify-between">
            <a href="../index.php" class="flex items-center gap-2 group">
                <div class="w-10 h-10 bg-gradient-to-br from-orange-500 to-red-600 rounded-xl flex items-center justify-center shadow-lg group-hover:scale-110 transition-transform">
                    <i class="fas fa-network-wired text-white"></i>
                </div>
                <span class="text-2xl font-bold bg-gradient-to-r from-orange-500 to-red-600 bg-clip-text text-transparent hidden sm:block">WideMaze</span>
            </a>
            <div class="flex items-center gap-4">
                <a href="../index.php" class="p-2 hover:bg-gray-100 rounded-full transition-colors">
                    <i class="fas fa-home text-xl text-gray-600"></i>
                </a>
                <a href="notifications.php" class="p-2 hover:bg-gray-100 rounded-full transition-colors">
                    <i class="fas fa-bell text-xl text-gray-600"></i>
                </a>
                <a href="messagerie.php" class="p-2 hover:bg-gray-100 rounded-full transition-colors">
                    <i class="fas fa-comment text-xl text-gray-600"></i>
                </a>
                <div class="relative group">
                    <button class="flex items-center gap-2 p-1">
                        <img src="<?= get_avatar_url($_SESSION['avatar'] ?? '') ?>" class="w-8 h-8 rounded-full object-cover border-2 border-orange-500">
                    </button>
                    <div class="absolute right-0 top-full mt-2 w-48 bg-white rounded-xl shadow-xl opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all z-50">
                        <a href="profil.php" class="block px-4 py-2 hover:bg-gray-50 rounded-t-xl">Mon profil</a>
                        <a href="parametres.php" class="block px-4 py-2 hover:bg-gray-50">Paramètres</a>
                        <hr>
                        <a href="deconnexion.php" class="block px-4 py-2 hover:bg-gray-50 rounded-b-xl text-red-600">Déconnexion</a>
                    </div>
                </div>
            </div>
        </div>
    </nav>
    
    <!-- Cover Photo -->
    <div class="h-72 md:h-96 mt-16 relative group">
        <div class="absolute inset-0 bg-gradient-to-r from-orange-500 via-red-500 to-pink-500">
            <?php if (!empty($community['image_couverture'])): ?>
                <img src="../uploads/covers/<?= htmlspecialchars($community['image_couverture']) ?>" class="w-full h-full object-cover" alt="Photo de couverture">
            <?php endif; ?>
        </div>
        <div class="absolute inset-0 cover-gradient"></div>
        <?php if ($isCreator): ?>
            <button onclick="document.getElementById('coverInput').click()" class="absolute bottom-4 right-4 bg-white/90 hover:bg-white px-4 py-2 rounded-lg shadow-lg transition-all opacity-0 group-hover:opacity-100 flex items-center gap-2">
                <i class="fas fa-camera"></i>Modifier la couverture
            </button>
            <input type="file" id="coverInput" class="hidden" accept="image/*" onchange="updateCover(this)">
        <?php endif; ?>
    </div>
    
    <div class="container mx-auto px-4 max-w-7xl">
        <!-- Community Header -->
        <div class="relative -mt-16 md:-mt-20 mb-6">
            <div class="flex flex-col md:flex-row items-center md:items-end gap-6">
                <!-- Avatar -->
                <div class="relative">
                    <div class="w-28 h-28 md:w-36 md:h-36 bg-white rounded-2xl shadow-xl flex items-center justify-center overflow-hidden border-4 border-white">
                        <div class="w-full h-full bg-gradient-to-br from-orange-500 to-red-500 flex items-center justify-center text-white text-5xl md:text-6xl font-bold">
                            <?= strtoupper(substr($community['nom'], 0, 1)) ?>
                        </div>
                    </div>
                </div>
                
                <!-- Info -->
                <div class="flex-1 text-center md:text-left">
                    <div class="flex items-center justify-center md:justify-start gap-3 flex-wrap">
                        <h1 class="text-3xl md:text-4xl font-bold text-gray-800"><?= htmlspecialchars($community['nom']) ?></h1>
                        <span class="px-3 py-1 <?= $categoryInfo['bg'] ?> <?= $categoryInfo['text'] ?> rounded-full text-sm font-medium">
                            <i class="fas fa-<?= $categoryInfo['icon'] ?> mr-1"></i><?= $categoryInfo['name'] ?>
                        </span>
                        <?php if ($community['posts_week'] > 10): ?>
                            <span class="px-2 py-1 bg-green-100 text-green-700 rounded-full text-xs">
                                <i class="fas fa-fire"></i> Très active
                            </span>
                        <?php endif; ?>
                    </div>
                    <p class="text-gray-500 mt-2">Créée par <a href="profil.php?id=<?= $community['creator_id'] ?>" class="text-orange-500 hover:underline">@<?= htmlspecialchars($community['creator_name']) ?></a> • <?= date('d M Y', strtotime($community['date_creation'])) ?></p>
                    <div class="flex flex-wrap items-center justify-center md:justify-start gap-4 mt-3">
                        <div class="flex items-center gap-1 text-gray-600 stat-card cursor-help" title="Membres">
                            <i class="fas fa-users text-orange-500"></i>
                            <span class="font-semibold"><?= number_format($community['member_count']) ?></span>
                            <span class="text-sm">membres</span>
                            <?php if ($community['new_members_week'] > 0): ?>
                                <span class="text-xs text-green-500 ml-1">+<?= $community['new_members_week'] ?> cette semaine</span>
                            <?php endif; ?>
                        </div>
                        <div class="flex items-center gap-1 text-gray-600 stat-card cursor-help" title="Publications">
                            <i class="fas fa-newspaper text-orange-500"></i>
                            <span class="font-semibold"><?= number_format($community['posts_count'] ?? 0) ?></span>
                            <span class="text-sm">publications</span>
                        </div>
                        <?php if ($isMember && $joinedAt): ?>
                            <div class="flex items-center gap-1 text-gray-500 text-sm">
                                <i class="fas fa-calendar-check text-green-500"></i>
                                <span>Membre depuis <?= date('d M Y', strtotime($joinedAt)) ?></span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Actions -->
                <div class="flex gap-3">
                    <?php if ($isMember): ?>
                        <button onclick="leaveCommunity(<?= $communityId ?>)" class="bg-red-500 hover:bg-red-600 text-white px-6 py-2.5 rounded-xl font-medium transition-all flex items-center gap-2 shadow-md hover:shadow-lg">
                            <i class="fas fa-sign-out-alt"></i>Quitter
                        </button>
                        <?php if ($isModerator): ?>
                            <button onclick="openModerationPanel()" class="bg-gray-200 hover:bg-gray-300 px-6 py-2.5 rounded-xl font-medium transition-all flex items-center gap-2">
                                <i class="fas fa-shield-alt"></i>Modération
                            </button>
                        <?php endif; ?>
                    <?php else: ?>
                        <button onclick="joinCommunity(<?= $communityId ?>)" class="bg-gradient-to-r from-orange-500 to-red-500 text-white px-8 py-2.5 rounded-xl font-medium transition-all hover:shadow-xl flex items-center gap-2">
                            <i class="fas fa-sign-in-alt"></i>Rejoindre
                        </button>
                    <?php endif; ?>
                    <button onclick="shareCommunity()" class="bg-gray-100 hover:bg-gray-200 px-4 py-2.5 rounded-xl transition-all">
                        <i class="fas fa-share-alt text-gray-600"></i>
                    </button>
                </div>
            </div>
        </div>
        
        <!-- Description -->
        <?php if (!empty($community['description'])): ?>
            <div class="bg-white rounded-2xl shadow-sm p-6 mb-6 border border-gray-100">
                <h3 class="font-bold text-gray-800 mb-2 flex items-center gap-2">
                    <i class="fas fa-info-circle text-orange-500"></i>À propos
                </h3>
                <p class="text-gray-700 leading-relaxed"><?= nl2br(htmlspecialchars($community['description'])) ?></p>
            </div>
        <?php endif; ?>
        
        <!-- Tabs Navigation -->
        <div class="flex overflow-x-auto border-b border-gray-200 mb-6 scrollbar-hide">
            <a href="?id=<?= $communityId ?>&tab=feed" class="tab-btn px-6 py-3 font-medium text-sm whitespace-nowrap transition-colors <?= $activeTab == 'feed' ? 'tab-active text-orange-600' : 'text-gray-600 hover:text-gray-800' ?>">
                <i class="fas fa-newspaper mr-2"></i>Fil d'actualité
                <?php if ($totalPosts > 0): ?>
                    <span class="ml-1 text-xs text-gray-400">(<?= $totalPosts ?>)</span>
                <?php endif; ?>
            </a>
            <a href="?id=<?= $communityId ?>&tab=members" class="tab-btn px-6 py-3 font-medium text-sm whitespace-nowrap transition-colors <?= $activeTab == 'members' ? 'tab-active text-orange-600' : 'text-gray-600 hover:text-gray-800' ?>">
                <i class="fas fa-users mr-2"></i>Membres
                <?php if ($totalMembers > 0): ?>
                    <span class="ml-1 text-xs text-gray-400">(<?= $totalMembers ?>)</span>
                <?php endif; ?>
            </a>
            <a href="?id=<?= $communityId ?>&tab=events" class="tab-btn px-6 py-3 font-medium text-sm whitespace-nowrap transition-colors <?= $activeTab == 'events' ? 'tab-active text-orange-600' : 'text-gray-600 hover:text-gray-800' ?>">
                <i class="fas fa-calendar-alt mr-2"></i>Événements
                <?php if (count($events) > 0): ?>
                    <span class="ml-1 text-xs text-gray-400">(<?= count($events) ?>)</span>
                <?php endif; ?>
            </a>
            <a href="?id=<?= $communityId ?>&tab=resources" class="tab-btn px-6 py-3 font-medium text-sm whitespace-nowrap transition-colors <?= $activeTab == 'resources' ? 'tab-active text-orange-600' : 'text-gray-600 hover:text-gray-800' ?>">
                <i class="fas fa-folder-open mr-2"></i>Ressources
                <?php if (count($resources) > 0): ?>
                    <span class="ml-1 text-xs text-gray-400">(<?= count($resources) ?>)</span>
                <?php endif; ?>
            </a>
            <?php if ($isModerator): ?>
                <a href="?id=<?= $communityId ?>&tab=settings" class="tab-btn px-6 py-3 font-medium text-sm whitespace-nowrap transition-colors <?= $activeTab == 'settings' ? 'tab-active text-orange-600' : 'text-gray-600 hover:text-gray-800' ?>">
                    <i class="fas fa-cog mr-2"></i>Paramètres
                </a>
            <?php endif; ?>
        </div>
        
        <!-- Main Content -->
        <?php if ($activeTab == 'feed'): ?>
            <div class="grid lg:grid-cols-3 gap-6 pb-8">
                <!-- Feed -->
                <div class="lg:col-span-2 space-y-4">
                    <!-- Create Post -->
                    <?php if ($isMember): ?>
                        <div class="bg-white rounded-2xl shadow-sm p-5 border border-gray-100">
                            <div class="flex gap-3">
                                <img src="<?= get_avatar_url($_SESSION['avatar'] ?? '') ?>" class="w-12 h-12 rounded-full">
                                <div class="flex-1">
                                    <button onclick="openCreatePostModal()" 
                                            class="w-full bg-gray-100 hover:bg-gray-200 rounded-full px-5 py-3 text-left text-gray-600 transition-colors">
                                        ✍️ Partager quelque chose dans <?= htmlspecialchars($community['nom']) ?>...
                                    </button>
                                </div>
                            </div>
                            <div class="flex justify-between mt-4 pt-4 border-t border-gray-100">
                                <button onclick="openCreatePostModal('text')" class="flex items-center gap-2 px-4 py-2 hover:bg-gray-50 rounded-lg text-gray-600">
                                    <i class="fas fa-pencil-alt text-gray-400"></i>Texte
                                </button>
                                <button onclick="openCreatePostModal('image')" class="flex items-center gap-2 px-4 py-2 hover:bg-gray-50 rounded-lg text-gray-600">
                                    <i class="fas fa-image text-green-500"></i>Photo
                                </button>
                                <button onclick="openCreatePostModal('link')" class="flex items-center gap-2 px-4 py-2 hover:bg-gray-50 rounded-lg text-gray-600">
                                    <i class="fas fa-link text-blue-500"></i>Lien
                                </button>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Posts -->
                    <?php if (!empty($posts)): ?>
                        <?php foreach ($posts as $post): ?>
                            <?php include __DIR__ . '/../includes/components/post-card.php'; ?>
                        <?php endforeach; ?>
                        
                        <!-- Pagination -->
                        <?php if ($totalPages > 1): ?>
                            <div class="flex justify-center gap-2 mt-6">
                                <?php if ($page > 1): ?>
                                    <a href="?id=<?= $communityId ?>&tab=feed&page=<?= $page-1 ?>" class="px-4 py-2 bg-white border rounded-xl hover:bg-gray-50 transition-colors">
                                        <i class="fas fa-chevron-left"></i> Précédent
                                    </a>
                                <?php endif; ?>
                                
                                <div class="flex gap-1">
                                    <?php
                                    $startPage = max(1, $page - 2);
                                    $endPage = min($totalPages, $page + 2);
                                    for ($i = $startPage; $i <= $endPage; $i++):
                                    ?>
                                        <a href="?id=<?= $communityId ?>&tab=feed&page=<?= $i ?>" 
                                           class="w-10 h-10 flex items-center justify-center rounded-xl transition-all <?= $i == $page ? 'bg-gradient-to-r from-orange-500 to-red-500 text-white shadow-md' : 'bg-white border hover:bg-gray-50' ?>">
                                            <?= $i ?>
                                        </a>
                                    <?php endfor; ?>
                                </div>
                                
                                <?php if ($page < $totalPages): ?>
                                    <a href="?id=<?= $communityId ?>&tab=feed&page=<?= $page+1 ?>" class="px-4 py-2 bg-white border rounded-xl hover:bg-gray-50 transition-colors">
                                        Suivant <i class="fas fa-chevron-right"></i>
                                    </a>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="bg-white rounded-2xl shadow-sm p-12 text-center text-gray-500">
                            <div class="w-24 h-24 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-4">
                                <i class="fas fa-newspaper text-4xl text-gray-300"></i>
                            </div>
                            <p class="text-lg font-medium">Aucune publication</p>
                            <p class="text-sm mt-1">Soyez le premier à partager quelque chose dans cette communauté !</p>
                            <?php if ($isMember): ?>
                                <button onclick="openCreatePostModal()" class="mt-4 text-orange-500 hover:underline">Créer une publication</button>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Sidebar -->
                <div class="space-y-6">
                    <!-- Stats -->
                    <div class="bg-white rounded-2xl shadow-sm p-5 border border-gray-100">
                        <h3 class="font-bold text-gray-800 mb-4 flex items-center gap-2">
                            <i class="fas fa-chart-line text-orange-500"></i>Statistiques
                        </h3>
                        <div class="space-y-4">
                            <div class="flex justify-between items-center py-2 border-b border-gray-100">
                                <span class="text-gray-600">Membres</span>
                                <span class="font-bold text-gray-800"><?= number_format($community['member_count']) ?></span>
                            </div>
                            <div class="flex justify-between items-center py-2 border-b border-gray-100">
                                <span class="text-gray-600">Publications</span>
                                <span class="font-bold text-gray-800"><?= number_format($community['posts_count'] ?? 0) ?></span>
                            </div>
                            <div class="flex justify-between items-center py-2 border-b border-gray-100">
                                <span class="text-gray-600">Activité cette semaine</span>
                                <span class="font-bold text-green-600"><?= number_format($community['posts_week'] ?? 0) ?> posts</span>
                            </div>
                            <div class="flex justify-between items-center py-2">
                                <span class="text-gray-600">Nouveaux membres</span>
                                <span class="font-bold text-green-600">+<?= number_format($community['new_members_week'] ?? 0) ?></span>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Membres en vedette -->
                    <?php if (!empty($members)): ?>
                        <div class="bg-white rounded-2xl shadow-sm p-5 border border-gray-100">
                            <div class="flex items-center justify-between mb-4">
                                <h3 class="font-bold text-gray-800 flex items-center gap-2">
                                    <i class="fas fa-medal text-orange-500"></i>Membres actifs
                                </h3>
                                <a href="?id=<?= $communityId ?>&tab=members" class="text-sm text-orange-500 hover:underline">Voir tout</a>
                            </div>
                            <div class="space-y-3">
                                <?php foreach (array_slice($members, 0, 5) as $member): ?>
                                    <a href="profil.php?id=<?= $member['id'] ?>" class="member-card flex items-center gap-3 p-2 rounded-xl transition-all">
                                        <img src="<?= get_avatar_url($member['avatar'] ?? '') ?>" class="w-10 h-10 rounded-full object-cover">
                                        <div class="flex-1 min-w-0">
                                            <p class="font-semibold text-gray-800 truncate"><?= htmlspecialchars($member['prenom'] . ' ' . $member['nom']) ?></p>
                                            <p class="text-xs text-gray-500">@<?= htmlspecialchars($member['surnom']) ?></p>
                                        </div>
                                        <?php if ($member['role'] == 'admin'): ?>
                                            <span class="text-xs bg-red-100 text-red-600 px-2 py-0.5 rounded-full">Admin</span>
                                        <?php elseif ($member['role'] == 'moderator'): ?>
                                            <span class="text-xs bg-blue-100 text-blue-600 px-2 py-0.5 rounded-full">Modo</span>
                                        <?php endif; ?>
                                        <?php if ($member['status'] == 'Online'): ?>
                                            <span class="w-2 h-2 bg-green-500 rounded-full"></span>
                                        <?php endif; ?>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Événements à venir -->
                    <?php if (!empty($events)): ?>
                        <div class="bg-gradient-to-br from-orange-50 to-red-50 rounded-2xl p-5 border border-orange-100">
                            <h3 class="font-bold text-gray-800 mb-3 flex items-center gap-2">
                                <i class="fas fa-calendar-alt text-orange-500"></i>Événements à venir
                            </h3>
                            <div class="space-y-3">
                                <?php foreach ($events as $event): ?>
                                    <div class="flex items-center gap-3 p-2 bg-white rounded-xl">
                                        <div class="text-center min-w-[50px]">
                                            <div class="text-2xl font-bold text-orange-500"><?= date('d', strtotime($event['event_date'])) ?></div>
                                            <div class="text-xs text-gray-500"><?= date('M', strtotime($event['event_date'])) ?></div>
                                        </div>
                                        <div class="flex-1">
                                            <p class="font-medium text-gray-800 text-sm"><?= htmlspecialchars($event['title']) ?></p>
                                            <p class="text-xs text-gray-500"><?= date('H:i', strtotime($event['event_date'])) ?></p>
                                        </div>
                                        <button class="text-orange-500 text-sm">S'inscrire</button>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php elseif ($activeTab == 'members'): ?>
            <div class="pb-8">
                <div class="bg-white rounded-2xl shadow-sm p-6">
                    <h2 class="text-xl font-bold text-gray-800 mb-6 flex items-center gap-2">
                        <i class="fas fa-users text-orange-500"></i>Membres de la communauté
                        <span class="text-sm text-gray-500 font-normal">(<?= number_format($totalMembers) ?> membres)</span>
                    </h2>
                    
                    <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
                        <?php foreach ($members as $member): ?>
                            <a href="profil.php?id=<?= $member['id'] ?>" class="member-card flex items-center gap-3 p-3 bg-gray-50 rounded-xl hover:bg-orange-50 transition-all">
                                <img src="<?= get_avatar_url($member['avatar'] ?? '') ?>" class="w-12 h-12 rounded-full object-cover">
                                <div class="flex-1 min-w-0">
                                    <p class="font-semibold text-gray-800 truncate"><?= htmlspecialchars($member['prenom'] . ' ' . $member['nom']) ?></p>
                                    <p class="text-xs text-gray-500 truncate">@<?= htmlspecialchars($member['surnom']) ?></p>
                                    <?php if ($member['role'] == 'admin'): ?>
                                        <span class="text-xs bg-red-100 text-red-600 px-2 py-0.5 rounded-full mt-1 inline-block">Admin</span>
                                    <?php elseif ($member['role'] == 'moderator'): ?>
                                        <span class="text-xs bg-blue-100 text-blue-600 px-2 py-0.5 rounded-full mt-1 inline-block">Modérateur</span>
                                    <?php endif; ?>
                                </div>
                                <?php if ($member['status'] == 'Online'): ?>
                                    <span class="w-2 h-2 bg-green-500 rounded-full"></span>
                                <?php endif; ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                    
                    <!-- Pagination membres -->
                    <?php if ($totalMembersPages > 1): ?>
                        <div class="flex justify-center gap-2 mt-6 pt-4 border-t border-gray-100">
                            <?php if ($membersPage > 1): ?>
                                <a href="?id=<?= $communityId ?>&tab=members&members_page=<?= $membersPage-1 ?>" class="px-4 py-2 bg-white border rounded-xl hover:bg-gray-50 transition-colors">
                                    <i class="fas fa-chevron-left"></i> Précédent
                                </a>
                            <?php endif; ?>
                            
                            <div class="flex gap-1">
                                <?php
                                $startPage = max(1, $membersPage - 2);
                                $endPage = min($totalMembersPages, $membersPage + 2);
                                for ($i = $startPage; $i <= $endPage; $i++):
                                ?>
                                    <a href="?id=<?= $communityId ?>&tab=members&members_page=<?= $i ?>" 
                                       class="w-10 h-10 flex items-center justify-center rounded-xl transition-all <?= $i == $membersPage ? 'bg-gradient-to-r from-orange-500 to-red-500 text-white shadow-md' : 'bg-white border hover:bg-gray-50' ?>">
                                        <?= $i ?>
                                    </a>
                                <?php endfor; ?>
                            </div>
                            
                            <?php if ($membersPage < $totalMembersPages): ?>
                                <a href="?id=<?= $communityId ?>&tab=members&members_page=<?= $membersPage+1 ?>" class="px-4 py-2 bg-white border rounded-xl hover:bg-gray-50 transition-colors">
                                    Suivant <i class="fas fa-chevron-right"></i>
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php elseif ($activeTab == 'events'): ?>
    <div class="pb-8">
        <div class="bg-white rounded-2xl shadow-sm p-6">
            <div class="flex justify-between items-center mb-6">
                <h2 class="text-xl font-bold text-gray-800 flex items-center gap-2">
                    <i class="fas fa-calendar-alt text-orange-500"></i>Événements
                </h2>
                <?php if ($isModerator): ?>
                    <button onclick="openEventModal()" class="bg-gradient-to-r from-orange-500 to-red-500 text-white px-4 py-2 rounded-lg hover:shadow-md transition-all flex items-center gap-2">
                        <i class="fas fa-plus"></i>Créer un événement
                    </button>
                <?php endif; ?>
            </div>
            
            <div id="eventsList">
                <div class="text-center py-8">
                    <div class="loading-spinner w-8 h-8 border-3 border-orange-500 border-t-transparent rounded-full animate-spin mx-auto"></div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal de création d'événement -->
    <div id="eventModal" class="fixed inset-0 bg-black/60 backdrop-blur-sm z-50 hidden items-center justify-center p-4">
        <div class="bg-white rounded-2xl w-full max-w-lg shadow-2xl">
            <div class="p-5 border-b border-gray-100 flex items-center justify-between">
                <h3 class="font-bold text-lg flex items-center gap-2">
                    <i class="fas fa-calendar-plus text-orange-500"></i>Créer un événement
                </h3>
                <button onclick="closeEventModal()" class="w-8 h-8 hover:bg-gray-100 rounded-full">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="p-5 space-y-4">
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">Titre *</label>
                    <input type="text" id="eventTitle" class="w-full px-4 py-2 border rounded-lg focus:border-orange-500 outline-none" placeholder="Titre de l'événement">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">Date et heure *</label>
                    <input type="datetime-local" id="eventDate" class="w-full px-4 py-2 border rounded-lg focus:border-orange-500 outline-none">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">Lieu</label>
                    <input type="text" id="eventLocation" class="w-full px-4 py-2 border rounded-lg focus:border-orange-500 outline-none" placeholder="Salle, campus, lien Zoom...">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">Nombre maximum de participants</label>
                    <input type="number" id="eventMaxParticipants" class="w-full px-4 py-2 border rounded-lg focus:border-orange-500 outline-none" placeholder="Illimité si vide">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-2">Description</label>
                    <textarea id="eventDescription" rows="4" class="w-full px-4 py-2 border rounded-lg focus:border-orange-500 outline-none resize-none" placeholder="Détails de l'événement..."></textarea>
                </div>
            </div>
            <div class="p-5 border-t border-gray-100 flex gap-3">
                <button onclick="closeEventModal()" class="flex-1 px-4 py-2 bg-gray-100 rounded-lg hover:bg-gray-200 transition-colors">Annuler</button>
                <button onclick="createEvent()" class="flex-1 bg-gradient-to-r from-orange-500 to-red-500 text-white px-4 py-2 rounded-lg hover:shadow-md transition-colors">Créer</button>
            </div>
        </div>
    </div>
        <?php elseif ($activeTab == 'resources'): ?>
            <div class="pb-8">
                <div class="bg-white rounded-2xl shadow-sm p-6 text-center">
                    <i class="fas fa-folder-open text-5xl text-gray-300 mb-4"></i>
                    <p class="text-gray-500">Fonctionnalité en développement</p>
                    <p class="text-sm text-gray-400 mt-2">Partage de ressources bientôt disponible</p>
                </div>
            </div>
        <?php elseif ($activeTab == 'settings' && $isModerator): ?>
            <div class="pb-8">
                <div class="bg-white rounded-2xl shadow-sm p-6">
                    <h2 class="text-xl font-bold text-gray-800 mb-6">Paramètres de la communauté</h2>
                    <div class="space-y-6 max-w-2xl">
                        <div class="bg-gray-50 rounded-xl p-5">
                            <h3 class="font-semibold text-gray-800 mb-3">Description</h3>
                            <textarea id="communityDescription" rows="4" class="w-full px-4 py-2 border rounded-lg focus:border-orange-500 focus:ring-2 focus:ring-orange-100 outline-none"><?= htmlspecialchars($community['description'] ?? '') ?></textarea>
                            <button onclick="updateDescription()" class="mt-3 bg-orange-500 text-white px-4 py-2 rounded-lg hover:bg-orange-600 transition-colors">Mettre à jour</button>
                        </div>
                        
                        <div class="bg-gray-50 rounded-xl p-5">
                            <h3 class="font-semibold text-gray-800 mb-3">Catégorie</h3>
                            <select id="communityCategory" class="px-4 py-2 border rounded-lg focus:border-orange-500 outline-none">
                                <option value="Academic" <?= $community['categorie'] == 'Academic' ? 'selected' : '' ?>>Académique</option>
                                <option value="Club" <?= $community['categorie'] == 'Club' ? 'selected' : '' ?>>Club</option>
                                <option value="Social" <?= $community['categorie'] == 'Social' ? 'selected' : '' ?>>Social</option>
                                <option value="Sports" <?= $community['categorie'] == 'Sports' ? 'selected' : '' ?>>Sports</option>
                                <option value="Arts" <?= $community['categorie'] == 'Arts' ? 'selected' : '' ?>>Arts & Culture</option>
                                <option value="Tech" <?= $community['categorie'] == 'Tech' ? 'selected' : '' ?>>Technologie</option>
                                <option value="Career" <?= $community['categorie'] == 'Career' ? 'selected' : '' ?>>Carrière</option>
                            </select>
                            <button onclick="updateCategory()" class="mt-3 bg-orange-500 text-white px-4 py-2 rounded-lg hover:bg-orange-600 transition-colors">Changer la catégorie</button>
                        </div>
                        
                        <div class="bg-red-50 rounded-xl p-5 border border-red-200">
                            <h3 class="font-semibold text-red-800 mb-2">Zone dangereuse</h3>
                            <p class="text-sm text-red-700 mb-3">La suppression d'une communauté est irréversible.</p>
                            <button onclick="deleteCommunity()" class="bg-red-500 hover:bg-red-600 text-white px-4 py-2 rounded-lg transition-colors">
                                <i class="fas fa-trash-alt mr-2"></i>Supprimer la communauté
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- Create Post Modal -->
    <div id="createPostModal" class="fixed inset-0 bg-black/60 backdrop-blur-sm z-50 hidden items-center justify-center p-4">
        <div class="bg-white rounded-2xl w-full max-w-lg shadow-2xl transform transition-all duration-300 scale-95 opacity-0" id="createPostModalContent">
            <div class="p-5 border-b border-gray-100 bg-gradient-to-r from-orange-50 to-red-50 rounded-t-2xl">
                <div class="flex items-center justify-between">
                    <h3 class="font-bold text-lg flex items-center gap-2">
                        <i class="fas fa-plus-circle text-orange-500"></i>Publier dans <?= htmlspecialchars($community['nom']) ?>
                    </h3>
                    <button onclick="closeCreatePostModal()" class="w-8 h-8 hover:bg-white/50 rounded-full flex items-center justify-center">
                        <i class="fas fa-times text-gray-500"></i>
                    </button>
                </div>
            </div>
            <div class="p-5">
                <div class="flex items-center gap-3 mb-4">
                    <img src="<?= get_avatar_url($_SESSION['avatar'] ?? '') ?>" class="w-12 h-12 rounded-full">
                    <div>
                        <p class="font-semibold text-gray-800"><?= htmlspecialchars($_SESSION['surnom']) ?></p>
                        <p class="text-xs text-orange-600">Publication dans la communauté</p>
                    </div>
                </div>
                <textarea id="postContent" class="w-full h-32 resize-none outline-none text-lg placeholder-gray-400 border-0 focus:ring-0" 
                          placeholder="Partagez quelque chose avec la communauté..."></textarea>
                
                <div class="border-2 border-dashed border-gray-200 rounded-xl p-4 hover:border-orange-500 transition-all cursor-pointer mt-3" onclick="document.getElementById('postImage').click()">
                    <div class="flex items-center justify-center gap-2 text-gray-400">
                        <i class="fas fa-images text-2xl"></i>
                        <span class="font-medium">Ajouter une image</span>
                    </div>
                    <input type="file" id="postImage" class="hidden" accept="image/*" onchange="previewPostImage(this)">
                </div>
                <div id="imagePreview" class="hidden relative mt-3 rounded-xl overflow-hidden">
                    <img src="" class="w-full h-48 object-cover">
                    <button onclick="removePostImage()" class="absolute top-2 right-2 w-8 h-8 bg-black/50 hover:bg-black/70 text-white rounded-full flex items-center justify-center">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            </div>
            <div class="p-5 border-t border-gray-100">
                <button onclick="submitCommunityPost(<?= $communityId ?>)" class="w-full bg-gradient-to-r from-orange-500 to-red-500 text-white font-semibold py-3 rounded-xl transition-all hover:shadow-lg flex items-center justify-center gap-2">
                    <i class="fas fa-paper-plane"></i>
                    <span>Publier</span>
                </button>
            </div>
        </div>
    </div>
    
    <!-- Toast Container -->
    <div id="toastContainer" class="fixed bottom-4 right-4 z-50 space-y-2"></div>
    
    <script>
        const csrfToken = '<?= $csrfToken ?>';
        let selectedPostImage = null;
        
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
        
        async function leaveCommunity(communityId) {
            if (!confirm('Quitter cette communauté ? Vous pourrez toujours la rejoindre plus tard.')) return;
            
            const formData = new FormData();
            formData.append('action', 'leave');
            formData.append('community_id', communityId);
            formData.append('csrf_token', csrfToken);
            
            try {
                const response = await fetch('../api/communities.php', {
                    method: 'POST',
                    body: formData
                });
                const data = await response.json();
                if (data.success) {
                    window.location.href = 'communautes.php';
                } else {
                    showToast(data.error || 'Erreur lors du départ', 'error');
                }
            } catch (err) {
                console.error('Error:', err);
                showToast('Erreur de connexion', 'error');
            }
        }
        
        function openCreatePostModal() {
            const modal = document.getElementById('createPostModal');
            const content = document.getElementById('createPostModalContent');
            modal.classList.remove('hidden');
            modal.classList.add('flex');
            setTimeout(() => {
                content.classList.remove('scale-95', 'opacity-0');
                content.classList.add('scale-100', 'opacity-100');
            }, 10);
        }
        
        function closeCreatePostModal() {
            const modal = document.getElementById('createPostModal');
            const content = document.getElementById('createPostModalContent');
            content.classList.remove('scale-100', 'opacity-100');
            content.classList.add('scale-95', 'opacity-0');
            setTimeout(() => {
                modal.classList.add('hidden');
                modal.classList.remove('flex');
                document.getElementById('postContent').value = '';
                removePostImage();
            }, 300);
        }
        
        function previewPostImage(input) {
            if (input.files && input.files[0]) {
                selectedPostImage = input.files[0];
                const reader = new FileReader();
                reader.onload = function(e) {
                    const preview = document.getElementById('imagePreview');
                    preview.querySelector('img').src = e.target.result;
                    preview.classList.remove('hidden');
                };
                reader.readAsDataURL(input.files[0]);
            }
        }
        
        function removePostImage() {
            selectedPostImage = null;
            document.getElementById('postImage').value = '';
            document.getElementById('imagePreview').classList.add('hidden');
        }
        
        async function submitCommunityPost(communityId) {
            const content = document.getElementById('postContent').value.trim();
            if (!content && !selectedPostImage) {
                showToast('Veuillez ajouter du contenu', 'error');
                return;
            }
            
            const formData = new FormData();
            formData.append('action', 'create');
            formData.append('content', content);
            formData.append('community_id', communityId);
            formData.append('csrf_token', csrfToken);
            if (selectedPostImage) formData.append('image', selectedPostImage);
            
            const btn = event.target;
            const originalText = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Publication...';
            
            try {
                const response = await fetch('../api/posts.php', {
                    method: 'POST',
                    body: formData
                });
                const data = await response.json();
                if (data.success) {
                    showToast('Publication créée !', 'success');
                    closeCreatePostModal();
                    location.reload();
                } else {
                    showToast(data.error || 'Erreur lors de la publication', 'error');
                    btn.disabled = false;
                    btn.innerHTML = originalText;
                }
            } catch (err) {
                console.error('Error:', err);
                showToast('Erreur de connexion', 'error');
                btn.disabled = false;
                btn.innerHTML = originalText;
            }
        }
        
        async function updateCover(input) {
            if (!input.files || !input.files[0]) return;
            
            const formData = new FormData();
            formData.append('action', 'update_cover');
            formData.append('community_id', <?= $communityId ?>);
            formData.append('cover', input.files[0]);
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
                    showToast(data.error || 'Erreur lors du téléchargement', 'error');
                }
            } catch (err) {
                console.error('Error:', err);
                showToast('Erreur de connexion', 'error');
            }
        }
        
        function shareCommunity() {
            navigator.clipboard.writeText(window.location.href);
            showToast('Lien copié dans le presse-papier !', 'success');
        }
        
        function openModerationPanel() {
            showToast('Panel de modération en développement', 'info');
        }
        
        async function updateDescription() {
            const description = document.getElementById('communityDescription')?.value;
            if (!description) return;
            
            const formData = new FormData();
            formData.append('action', 'update_description');
            formData.append('community_id', <?= $communityId ?>);
            formData.append('description', description);
            formData.append('csrf_token', csrfToken);
            
            try {
                const response = await fetch('../api/communities.php', {
                    method: 'POST',
                    body: formData
                });
                const data = await response.json();
                if (data.success) {
                    showToast('Description mise à jour !', 'success');
                    location.reload();
                } else {
                    showToast(data.error || 'Erreur lors de la mise à jour', 'error');
                }
            } catch (err) {
                console.error('Error:', err);
                showToast('Erreur de connexion', 'error');
            }
        }
        
        async function updateCategory() {
            const category = document.getElementById('communityCategory')?.value;
            if (!category) return;
            
            const formData = new FormData();
            formData.append('action', 'update_category');
            formData.append('community_id', <?= $communityId ?>);
            formData.append('category', category);
            formData.append('csrf_token', csrfToken);
            
            try {
                const response = await fetch('../api/communities.php', {
                    method: 'POST',
                    body: formData
                });
                const data = await response.json();
                if (data.success) {
                    showToast('Catégorie mise à jour !', 'success');
                    location.reload();
                } else {
                    showToast(data.error || 'Erreur lors de la mise à jour', 'error');
                }
            } catch (err) {
                console.error('Error:', err);
                showToast('Erreur de connexion', 'error');
            }
        }
        
        async function deleteCommunity() {
            if (!confirm('⚠️ ATTENTION : Cette action est irréversible ! Supprimer définitivement cette communauté ?')) return;
            
            const formData = new FormData();
            formData.append('action', 'delete_community');
            formData.append('community_id', <?= $communityId ?>);
            formData.append('csrf_token', csrfToken);
            
            try {
                const response = await fetch('../api/communities.php', {
                    method: 'POST',
                    body: formData
                });
                const data = await response.json();
                if (data.success) {
                    window.location.href = 'communautes.php';
                } else {
                    showToast(data.error || 'Erreur lors de la suppression', 'error');
                }
            } catch (err) {
                console.error('Error:', err);
                showToast('Erreur de connexion', 'error');
            }
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
        
        // Fermer les modals
        document.getElementById('createPostModal')?.addEventListener('click', (e) => {
            if (e.target === e.currentTarget) closeCreatePostModal();
        });

        // Chargement des événements
async function loadEvents() {
    try {
        const response = await fetch(`../api/community_events.php?action=list&community_id=<?= $communityId ?>&upcoming=1`);
        const data = await response.json();
        
        const eventsList = document.getElementById('eventsList');
        if (data.success && data.events.length > 0) {
            eventsList.innerHTML = data.events.map(event => `
                <div class="border rounded-xl p-5 mb-4 hover:shadow-md transition-all">
                    <div class="flex items-start justify-between">
                        <div class="flex items-center gap-4">
                            <div class="text-center min-w-[70px]">
                                <div class="text-3xl font-bold text-orange-500">${new Date(event.event_date).getDate()}</div>
                                <div class="text-sm text-gray-500">${new Date(event.event_date).toLocaleString('fr', { month: 'short' })}</div>
                            </div>
                            <div>
                                <h3 class="font-bold text-gray-800 text-lg">${escapeHtml(event.title)}</h3>
                                <p class="text-sm text-gray-500 mt-1">
                                    <i class="fas fa-clock mr-1"></i>${new Date(event.event_date).toLocaleString('fr-FR')}
                                </p>
                                ${event.location ? `<p class="text-sm text-gray-500"><i class="fas fa-map-marker-alt mr-1"></i>${escapeHtml(event.location)}</p>` : ''}
                                <p class="text-sm text-gray-600 mt-2">${escapeHtml(event.description || '')}</p>
                                <div class="flex items-center gap-4 mt-3 text-sm">
                                    <span class="text-gray-500"><i class="fas fa-users mr-1"></i>${event.participants_count || 0} participants</span>
                                    ${event.max_participants ? `<span class="text-gray-500">Max: ${event.max_participants}</span>` : ''}
                                    <span class="text-gray-400">Organisé par @${escapeHtml(event.creator_name)}</span>
                                </div>
                            </div>
                        </div>
                        <div>
                            ${event.my_status === 'going' 
                                ? `<button onclick="unregisterEvent(${event.id})" class="bg-green-100 text-green-700 px-4 py-2 rounded-lg text-sm">✅ Inscrit</button>`
                                : `<button onclick="registerEvent(${event.id})" class="bg-orange-500 text-white px-4 py-2 rounded-lg text-sm hover:bg-orange-600">📝 S'inscrire</button>`
                            }
                        </div>
                    </div>
                </div>
            `).join('');
        } else {
            eventsList.innerHTML = `
                <div class="text-center py-12 text-gray-500">
                    <i class="fas fa-calendar-week text-5xl mb-4 text-gray-300"></i>
                    <p>Aucun événement à venir</p>
                    ${<?= $isModerator ? 'true' : 'false' ?> ? '<p class="text-sm mt-2">Créez le premier événement de la communauté !</p>' : ''}
                </div>
            `;
        }
    } catch (err) {
        console.error('Error loading events:', err);
        document.getElementById('eventsList').innerHTML = '<div class="text-center py-8 text-red-500">Erreur de chargement</div>';
    }
}

// Création d'événement
async function createEvent() {
    const title = document.getElementById('eventTitle').value.trim();
    const eventDate = document.getElementById('eventDate').value;
    const location = document.getElementById('eventLocation').value.trim();
    const maxParticipants = document.getElementById('eventMaxParticipants').value;
    const description = document.getElementById('eventDescription').value.trim();
    
    if (!title || !eventDate) {
        showToast('Veuillez remplir le titre et la date', 'error');
        return;
    }
    
    const formData = new FormData();
    formData.append('action', 'create');
    formData.append('community_id', <?= $communityId ?>);
    formData.append('title', title);
    formData.append('event_date', eventDate);
    formData.append('location', location);
    formData.append('max_participants', maxParticipants);
    formData.append('description', description);
    formData.append('csrf_token', csrfToken);
    
    try {
        const response = await fetch('../api/community_events.php', {
            method: 'POST',
            body: formData
        });
        const data = await response.json();
        
        if (data.success) {
            showToast('Événement créé !', 'success');
            closeEventModal();
            loadEvents();
        } else {
            showToast(data.error || 'Erreur lors de la création', 'error');
        }
    } catch (err) {
        console.error('Error:', err);
        showToast('Erreur de connexion', 'error');
    }
}

async function registerEvent(eventId) {
    const formData = new FormData();
    formData.append('action', 'register');
    formData.append('event_id', eventId);
    formData.append('status', 'going');
    formData.append('csrf_token', csrfToken);
    
    try {
        const response = await fetch('../api/community_events.php', {
            method: 'POST',
            body: formData
        });
        const data = await response.json();
        
        if (data.success) {
            showToast('Inscription confirmée !', 'success');
            loadEvents();
        } else {
            showToast(data.error || 'Erreur lors de l\'inscription', 'error');
        }
    } catch (err) {
        console.error('Error:', err);
        showToast('Erreur de connexion', 'error');
    }
}

function openEventModal() {
    document.getElementById('eventModal').classList.remove('hidden');
    document.getElementById('eventModal').classList.add('flex');
    // Date minimale = maintenant
    const now = new Date();
    now.setMinutes(now.getMinutes() - now.getTimezoneOffset());
    document.getElementById('eventDate').min = now.toISOString().slice(0, 16);
}

function closeEventModal() {
    document.getElementById('eventModal').classList.add('hidden');
    document.getElementById('eventModal').classList.remove('flex');
    document.getElementById('eventTitle').value = '';
    document.getElementById('eventDate').value = '';
    document.getElementById('eventLocation').value = '';
    document.getElementById('eventMaxParticipants').value = '';
    document.getElementById('eventDescription').value = '';
}

// Charger les événements au chargement de la page si l'onglet est actif
if (document.getElementById('tab-events')) {
    if (window.location.search.includes('tab=events')) {
        loadEvents();
    }
}
    </script>
</body>
</html>