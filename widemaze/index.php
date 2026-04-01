<?php


require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

// Require authentication
require_auth();

$userId = $_SESSION['user_id'];
$currentUser = [];

// Récupération des informations de l'utilisateur courant
try {
    $stmt = $pdo->prepare("
        SELECT u.*,
            (SELECT COUNT(*) FROM posts WHERE id_utilisateur = u.id) as posts_count,
            (SELECT COUNT(*) FROM ami WHERE (id = u.id OR idami = u.id) AND accepterami = 1) as friends_count,
            (SELECT COUNT(*) FROM communaute_membres WHERE id_utilisateur = u.id) as communities_count,
            (SELECT COUNT(*) FROM notifications WHERE user_id = u.id AND is_read = 0) as unread_notifications
        FROM utilisateurs u
        WHERE u.id = ?
    ");
    $stmt->execute([$userId]);
    $currentUser = $stmt->fetch();
    
    if (!$currentUser) {
        session_destroy();
        header('Location: pages/connexion.php');
        exit();
    }
} catch (PDOException $e) {
    error_log("Error fetching user: " . $e->getMessage());
}

// Récupération des suggestions d'amis
$friendSuggestions = [];
try {
    $stmt = $pdo->prepare("
        SELECT u.id, u.surnom, u.prenom, u.nom, u.avatar, u.universite,
            (SELECT COUNT(*) FROM ami 
             WHERE (id = u.id AND idami = ?) OR (idami = u.id AND id = ?)) as mutual_friends
        FROM utilisateurs u
        WHERE u.id != ?
        AND u.is_active = 1
        AND u.id NOT IN (
            SELECT CASE WHEN id = ? THEN idami ELSE id END
            FROM ami WHERE (id = ? OR idami = ?) AND (accepterami = 1 OR demandeami = 1)
        )
        ORDER BY RAND()
        LIMIT 5
    ");
    $stmt->execute([$userId, $userId, $userId, $userId, $userId, $userId]);
    $friendSuggestions = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Error fetching friend suggestions: " . $e->getMessage());
    $friendSuggestions = [];
}

// Récupération des communautés populaires
$communities = [];
try {
    $stmt = $pdo->prepare("
        SELECT c.id_communaute, c.nom, c.description, c.categorie,
            (SELECT COUNT(*) FROM communaute_membres WHERE id_communaute = c.id_communaute) as members_count,
            EXISTS(SELECT 1 FROM communaute_membres WHERE id_communaute = c.id_communaute AND id_utilisateur = ?) as is_member
        FROM communautes c
        WHERE c.is_active = 1
        ORDER BY members_count DESC
        LIMIT 5
    ");
    $stmt->execute([$userId]);
    $communities = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Error fetching communities: " . $e->getMessage());
    $communities = [];
}

// Récupération des amis en ligne
$onlineFriends = [];
try {
    $stmt = $pdo->prepare("
        SELECT u.id, u.surnom, u.avatar, u.status
        FROM utilisateurs u
        WHERE u.id IN (
            SELECT CASE WHEN id = ? THEN idami ELSE id END
            FROM ami WHERE (id = ? OR idami = ?) AND accepterami = 1
        )
        AND u.status = 'Online'
        AND u.is_active = 1
        LIMIT 10
    ");
    $stmt->execute([$userId, $userId, $userId]);
    $onlineFriends = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Error fetching online friends: " . $e->getMessage());
    $onlineFriends = [];
}

// Récupération des stories actives
$stories = [];
try {
    $stmt = $pdo->prepare("
        SELECT s.*, u.surnom, u.avatar,
            EXISTS(SELECT 1 FROM story_views WHERE story_id = s.id AND user_id = ?) as has_viewed,
            EXISTS(SELECT 1 FROM ami WHERE (id = u.id AND idami = ?) OR (idami = u.id AND id = ?) AND accepterami = 1) as is_friend
        FROM stories s
        JOIN utilisateurs u ON s.user_id = u.id
        WHERE s.expires_at > NOW()
        ORDER BY s.created_at DESC
        LIMIT 10
    ");
    $stmt->execute([$userId, $userId, $userId]);
    $stories = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Error fetching stories: " . $e->getMessage());
    $stories = [];
}

// Initialisation des variables pour éviter les warnings
$stories = $stories ?? [];
$friendSuggestions = $friendSuggestions ?? [];
$communities = $communities ?? [];
$onlineFriends = $onlineFriends ?? [];
$currentUser = $currentUser ?? [
    'posts_count' => 0,
    'friends_count' => 0,
    'unread_notifications' => 0
];

// Génération du token CSRF
$csrfToken = generate_csrf_token();

// Titre de la page
$page_title = 'Accueil';

// Inclusion du header
include __DIR__ . '/includes/templates/header.php';
?>

<!-- Navigation Flottante -->
<nav class="fixed top-4 left-1/2 -translate-x-1/2 w-[95%] max-w-6xl glass rounded-2xl shadow-lg border border-white/20 z-50 px-6 py-3">
    <div class="flex items-center justify-between">
        <!-- Logo -->
        <a href="index.php" class="flex items-center gap-3 group">
            <div class="w-10 h-10 bg-gradient-to-br from-orange-500 to-pink-500 rounded-xl flex items-center justify-center shadow-lg group-hover:scale-110 transition-transform duration-300">
                <i class="fas fa-network-wired text-white text-lg"></i>
            </div>
            <span class="text-2xl font-bold gradient-text hidden sm:block"><?= SITE_NAME ?></span>
        </a>
        
        <!-- Search Bar -->
        <div class="hidden md:flex flex-1 max-w-md mx-8 relative">
            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                <i class="fas fa-search text-gray-400"></i>
            </div>
            <input type="text" id="globalSearch" placeholder="Rechercher des personnes, posts, communautés..."
                   class="w-full pl-10 pr-4 py-2.5 bg-gray-100/80 border-0 rounded-xl focus:bg-white focus:ring-2 focus:ring-primary/30 focus:outline-none transition-all text-sm"
                   onkeyup="handleSearch(this.value)">
            <div id="searchResults" class="absolute top-full left-0 right-0 mt-2 bg-white rounded-xl shadow-xl border border-gray-100 hidden max-h-96 overflow-y-auto z-50"></div>
        </div>
        
        <!-- Actions -->
        <div class="flex items-center gap-2 sm:gap-4">
            <button onclick="openCreateModal()" class="hidden sm:flex items-center gap-2 bg-gradient-to-br from-primary to-orange-600 text-white px-4 py-2 rounded-xl font-medium transition-all hover:shadow-lg hover:scale-105 active:scale-95">
                <i class="fas fa-plus"></i>
                <span>Créer</span>
            </button>
            
            <a href="index.php" class="p-2.5 hover:bg-gray-100 rounded-xl transition-colors relative group">
                <i class="fas fa-home text-xl text-primary"></i>
                <span class="absolute -bottom-1 left-1/2 -translate-x-1/2 w-1 h-1 bg-primary rounded-full opacity-0 group-hover:opacity-100 transition-opacity"></span>
            </a>
            
            <a href="pages/notifications.php" class="p-2.5 hover:bg-gray-100 rounded-xl transition-colors relative group">
                <i class="fas fa-bell text-xl text-gray-600 group-hover:text-primary transition-colors"></i>
                <?php if (($currentUser['unread_notifications'] ?? 0) > 0): ?>
                    <span class="absolute top-1 right-1 w-5 h-5 bg-red-500 text-white text-xs font-bold rounded-full flex items-center justify-center animate-pulse">
                        <?= min($currentUser['unread_notifications'] ?? 0, 9) > 9 ? '9+' : ($currentUser['unread_notifications'] ?? 0) ?>
                    </span>
                <?php endif; ?>
            </a>
            
            <!-- Admin Quick Access -->
            <?php if (is_admin()): ?>
                <a href="pages/admin.php" class="p-2 hover:bg-gray-100 rounded-full transition-colors relative">
                    <i class="fas fa-shield-alt text-gray-600"></i>
                    <?php
                    $reportCount = 0;
                    try {
                        $stmt = $pdo->query("SELECT COUNT(*) FROM posts WHERE is_reported = 1");
                        $reportCount = $stmt->fetchColumn();
                    } catch (PDOException $e) {}
                    ?>
                    <?php if ($reportCount > 0): ?>
                        <span class="absolute -top-1 -right-1 w-5 h-5 bg-red-500 text-white text-xs rounded-full flex items-center justify-center animate-pulse">
                            <?= min($reportCount, 9) ?>
                        </span>
                    <?php endif; ?>
                </a>
            <?php endif; ?>
            
            <a href="pages/messagerie.php" class="p-2.5 hover:bg-gray-100 rounded-xl transition-colors relative group">
                <i class="fas fa-comment-dots text-xl text-gray-600 group-hover:text-primary transition-colors"></i>
            </a>
            
            <!-- Profile Dropdown -->
            <div class="relative group">
                <button class="flex items-center gap-2 p-1.5 hover:bg-gray-100 rounded-xl transition-all">
                    <img src="<?= get_avatar_url($currentUser['avatar'] ?? '') ?>" class="w-9 h-9 rounded-full object-cover border-2 border-primary/30">
                    <i class="fas fa-chevron-down text-xs text-gray-400 hidden sm:block"></i>
                </button>
                
                <div class="absolute right-0 top-full mt-3 w-64 bg-white rounded-2xl shadow-xl border border-gray-100 opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all duration-200 z-50 overflow-hidden">
                    <div class="p-4 border-b border-gray-100 bg-gradient-to-r from-orange-50 to-pink-50">
                        <p class="font-bold text-gray-800"><?= escape_html(($currentUser['prenom'] ?? '') . ' ' . ($currentUser['nom'] ?? '')) ?></p>
                        <p class="text-sm text-gray-500">@<?= escape_html($currentUser['surnom'] ?? '') ?></p>
                    </div>
                    <div class="p-2">
                        <a href="pages/profil.php" class="flex items-center gap-3 px-4 py-2.5 hover:bg-gray-50 rounded-xl transition-colors text-gray-700">
                            <i class="fas fa-user text-gray-400 w-5"></i>Mon profil
                        </a>
                        <a href="pages/parametres.php" class="flex items-center gap-3 px-4 py-2.5 hover:bg-gray-50 rounded-xl transition-colors text-gray-700">
                            <i class="fas fa-cog text-gray-400 w-5"></i>Paramètres
                        </a>
                        <?php if (is_admin()): ?>
                            <a href="pages/admin.php" class="flex items-center gap-3 px-4 py-2.5 hover:bg-red-50 rounded-xl text-red-600">
                                <i class="fas fa-shield-alt text-red-400 w-5"></i>Administration
                            </a>
                        <?php endif; ?>
                    </div>
                    <div class="p-2 border-t border-gray-100">
                        <a href="pages/deconnexion.php" class="flex items-center gap-3 px-4 py-2.5 hover:bg-red-50 rounded-xl transition-colors text-red-600 font-medium">
                            <i class="fas fa-sign-out-alt w-5"></i>Déconnexion
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</nav>

<!-- Main Layout -->
<div class="container mx-auto max-w-7xl pt-24 pb-8 px-4">
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
        
        <!-- Left Sidebar -->
        <?php include __DIR__ . '/includes/templates/sidebar.php'; ?>
        
        <!-- Main Feed -->
        <main class="lg:col-span-6 space-y-5">
            
            <!-- Stories Carousel -->
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-4">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="font-bold text-gray-800">Stories</h3>
                    <a href="#" class="text-sm text-primary hover:text-orange-600 font-medium">Voir tout</a>
                </div>
                <div class="flex gap-4 overflow-x-auto no-scrollbar pb-2">
                    
                    <!-- My Story -->
                    <div class="flex-shrink-0 flex flex-col items-center gap-2 cursor-pointer group" onclick="openStoryUpload()">
                        <div class="relative w-16 h-16">
                            <img src="<?= get_avatar_url($currentUser['avatar'] ?? '') ?>" 
                                class="w-full h-full rounded-full object-cover border-2 border-dashed border-gray-300 group-hover:border-primary transition-colors p-0.5">
                            <div class="absolute -bottom-1 -right-1 w-6 h-6 bg-primary rounded-full flex items-center justify-center text-white shadow-lg group-hover:scale-110 transition-transform">
                                <i class="fas fa-plus text-xs"></i>
                            </div>
                        </div>
                        <span class="text-xs font-medium text-gray-600">Ajouter une story</span>
                    </div>
                    <!-- Story Upload Modal -->
                    <div id="storyModal" class="fixed inset-0 bg-black/60 backdrop-blur-sm z-50 hidden items-center justify-center p-4">
                        <div class="bg-white rounded-2xl w-full max-w-md shadow-2xl">
                            <div class="p-5 border-b border-gray-100 flex items-center justify-between">
                                <h3 class="font-bold text-lg flex items-center gap-2">
                                    <i class="fas fa-camera text-orange-500"></i>Créer une story
                                </h3>
                                <button onclick="closeStoryUploadModal()" class="w-8 h-8 hover:bg-gray-100 rounded-full flex items-center justify-center">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                            <div class="p-5">
                                <div class="border-2 border-dashed border-gray-200 rounded-xl p-8 text-center hover:border-primary transition-all cursor-pointer" onclick="document.getElementById('storyFile').click()">
                                    <i class="fas fa-cloud-upload-alt text-5xl text-gray-400 mb-3"></i>
                                    <p class="text-gray-500">Cliquez pour sélectionner une photo ou vidéo</p>
                                    <p class="text-xs text-gray-400 mt-2">Durée max : 30 secondes • Taille max : 20MB</p>
                                </div>
                                <input type="file" id="storyFile" class="hidden" accept="image/*,video/*" onchange="previewStory(this)">
                                <div id="storyPreview" class="hidden mt-4 relative rounded-xl overflow-hidden">
                                    <video id="storyVideoPreview" class="w-full max-h-64 hidden" controls></video>
                                    <img id="storyImagePreview" class="w-full max-h-64 object-cover hidden">
                                    <button onclick="removeStoryPreview()" class="absolute top-2 right-2 w-8 h-8 bg-black/50 hover:bg-black/70 text-white rounded-full flex items-center justify-center">
                                        <i class="fas fa-times"></i>
                                    </button>
                                </div>
                                <button id="submitStoryBtn" onclick="submitStory()" class="w-full bg-gradient-to-r from-orange-500 to-red-500 text-white font-semibold py-3 rounded-xl mt-4 hover:shadow-lg transition-all disabled:opacity-50 hidden">
                                    <i class="fas fa-paper-plane mr-2"></i>Publier la story
                                </button>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Other Stories -->
                    <?php if (!empty($stories)): ?>
                        <?php foreach ($stories as $story): ?>
                            <div class="flex-shrink-0 flex flex-col items-center gap-2 cursor-pointer group" onclick="viewStory(<?= $story['id'] ?? 0 ?>)">
                                <div class="story-ring <?= !($story['has_viewed'] ?? false) ? 'p-0.5' : '' ?>">
                                    <img src="<?= get_avatar_url($story['avatar'] ?? '') ?>" 
                                         class="w-14 h-14 rounded-full object-cover border-2 border-white">
                                </div>
                                <span class="text-xs font-medium text-gray-600 truncate max-w-[4rem]"><?= escape_html($story['surnom'] ?? '') ?></span>
                                <?php if (($story['is_friend'] ?? false)): ?>
                                    <span class="text-[10px] text-primary font-medium">Ami</span>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="text-center text-gray-400 text-sm py-4">Aucune story disponible</div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Create Post Card -->
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5">
                <div class="flex gap-4">
                    <img src="<?= get_avatar_url($currentUser['avatar'] ?? '') ?>" class="w-12 h-12 rounded-full object-cover border-2 border-gray-100">
                    <div class="flex-1">
                        <button onclick="openCreateModal()" 
                                class="w-full bg-gray-100 hover:bg-gray-200 rounded-xl py-3 px-4 text-left text-gray-600 font-medium transition-all hover:shadow-inner">
                            Quoi de neuf, <?= escape_html($currentUser['surnom'] ?? '') ?> ?
                        </button>
                    </div>
                </div>
                <div class="flex justify-between mt-4 pt-4 border-t border-gray-100">
                    <button onclick="openCreateModal('photo')" class="flex items-center gap-2 px-4 py-2 hover:bg-green-50 text-gray-600 hover:text-green-600 rounded-xl transition-all">
                        <i class="fas fa-image text-green-500 text-lg"></i>
                        <span class="font-medium text-sm">Photo</span>
                    </button>
                    <button onclick="openCreateModal('video')" class="flex items-center gap-2 px-4 py-2 hover:bg-red-50 text-gray-600 hover:text-red-600 rounded-xl transition-all">
                        <i class="fas fa-video text-red-500 text-lg"></i>
                        <span class="font-medium text-sm">Vidéo</span>
                    </button>
                    <button onclick="openCreateModal('mood')" class="flex items-center gap-2 px-4 py-2 hover:bg-yellow-50 text-gray-600 hover:text-yellow-600 rounded-xl transition-all">
                        <i class="fas fa-smile text-yellow-500 text-lg"></i>
                        <span class="font-medium text-sm">Humeur</span>
                    </button>
                </div>
            </div>
            
            <!-- Feed Filters -->
            <div class="flex items-center gap-3 overflow-x-auto no-scrollbar">
                <button onclick="loadFeed('all')" class="feed-filter active px-5 py-2 bg-primary text-white rounded-full font-medium text-sm whitespace-nowrap shadow-md hover:shadow-lg transition-all" data-filter="all">
                    Tout
                </button>
                <button onclick="loadFeed('friends')" class="feed-filter px-5 py-2 bg-white text-gray-600 rounded-full font-medium text-sm whitespace-nowrap border border-gray-200 hover:border-primary hover:text-primary transition-all" data-filter="friends">
                    <i class="fas fa-user-friends mr-2"></i>Amis
                </button>
                <button onclick="loadFeed('photos')" class="feed-filter px-5 py-2 bg-white text-gray-600 rounded-full font-medium text-sm whitespace-nowrap border border-gray-200 hover:border-primary hover:text-primary transition-all" data-filter="photos">
                    <i class="fas fa-image mr-2"></i>Photos
                </button>
            </div>
            
            <!-- Posts Container -->
            <div id="postsContainer" class="space-y-5">
                <!-- Loading Skeleton -->
                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5 space-y-4">
                    <div class="flex items-center gap-4">
                        <div class="w-12 h-12 rounded-full skeleton"></div>
                        <div class="space-y-2 flex-1">
                            <div class="h-4 w-32 rounded skeleton"></div>
                            <div class="h-3 w-24 rounded skeleton"></div>
                        </div>
                    </div>
                    <div class="space-y-2">
                        <div class="h-4 w-full rounded skeleton"></div>
                        <div class="h-4 w-3/4 rounded skeleton"></div>
                    </div>
                    <div class="h-64 rounded-xl skeleton"></div>
                </div>
            </div>
            
            <!-- Load More -->
            <div class="text-center py-4">
                <button onclick="loadMorePosts()" id="loadMoreBtn" class="px-6 py-3 bg-white border border-gray-200 rounded-xl text-gray-600 font-medium hover:border-primary hover:text-primary transition-all shadow-sm hover:shadow-md">
                    <i class="fas fa-spinner fa-spin mr-2 hidden" id="loadMoreSpinner"></i>
                    Charger plus de publications
                </button>
            </div>
        </main>
        
        <!-- Right Sidebar -->
        <aside class="hidden lg:block lg:col-span-3 space-y-4 sticky top-24 h-fit">
            
            <!-- Friend Suggestions -->
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="font-bold text-gray-800">Suggestions</h3>
                    <a href="#" class="text-sm text-primary hover:text-orange-600 font-medium">Voir tout</a>
                </div>
                <div class="space-y-4">
                    <?php if (!empty($friendSuggestions)): ?>
                        <?php foreach ($friendSuggestions as $suggestion): ?>
                            <?php 
                                $userData = [
                                    'id' => $suggestion['id'],
                                    'surnom' => $suggestion['surnom'],
                                    'prenom' => $suggestion['prenom'],
                                    'nom' => $suggestion['nom'],
                                    'avatar' => $suggestion['avatar'],
                                    'universite' => $suggestion['universite']
                                ];
                                $mutualFriends = $suggestion['mutual_friends'] ?? 0;
                            ?>
                            <div class="flex items-center gap-3">
                                <img src="<?= get_avatar_url($suggestion['avatar'] ?? '') ?>" class="w-10 h-10 rounded-full object-cover">
                                <div class="flex-1 min-w-0">
                                    <p class="font-semibold text-sm text-gray-800 truncate"><?= escape_html($suggestion['surnom'] ?? '') ?></p>
                                    <p class="text-xs text-gray-500 truncate">
                                        <?= $mutualFriends > 0 ? $mutualFriends . ' ami(s) en commun' : escape_html($suggestion['universite'] ?? 'WideMaze') ?>
                                    </p>
                                </div>
                                <button onclick="sendFriendRequest(<?= $suggestion['id'] ?? 0 ?>, this)" 
                                        class="w-8 h-8 bg-primary/10 hover:bg-primary text-primary hover:text-white rounded-full flex items-center justify-center transition-all">
                                    <i class="fas fa-user-plus text-sm"></i>
                                </button>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p class="text-gray-400 text-center py-4">Aucune suggestion pour le moment</p>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Trending Communities -->
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="font-bold text-gray-800">Communautés</h3>
                    <a href="pages/communautes.php" class="text-sm text-primary hover:text-orange-600 font-medium">Explorer</a>
                </div>
                <div class="space-y-3">
                    <?php if (!empty($communities)): ?>
                        <?php foreach ($communities as $community): ?>
                            <div class="flex items-center gap-3 p-2 hover:bg-gray-50 rounded-xl transition-colors cursor-pointer" 
                                 onclick="location.href='pages/communaute.php?id=<?= $community['id_communaute'] ?? 0 ?>'">
                                <div class="w-12 h-12 bg-gradient-to-br from-primary to-pink-500 rounded-xl flex items-center justify-center text-white font-bold text-lg">
                                    <?= strtoupper(substr($community['nom'] ?? '', 0, 1)) ?>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <p class="font-semibold text-sm text-gray-800 truncate"><?= escape_html($community['nom'] ?? '') ?></p>
                                    <p class="text-xs text-gray-500"><?= number_format($community['members_count'] ?? 0) ?> membres</p>
                                </div>
                                <?php if (!($community['is_member'] ?? false)): ?>
                                    <button onclick="event.stopPropagation(); joinCommunity(<?= $community['id_communaute'] ?? 0 ?>)" 
                                            class="text-xs bg-primary/10 text-primary px-3 py-1.5 rounded-lg font-medium hover:bg-primary hover:text-white transition-colors">
                                        Rejoindre
                                    </button>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p class="text-gray-400 text-center py-4">Aucune communauté disponible</p>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Online Friends -->
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="font-bold text-gray-800">En ligne</h3>
                    <span class="text-xs text-green-600 font-medium flex items-center gap-1">
                        <span class="w-2 h-2 bg-green-500 rounded-full animate-pulse"></span>
                        <?= count($onlineFriends) ?> actif(s)
                    </span>
                </div>
                <div class="space-y-3 max-h-64 overflow-y-auto no-scrollbar">
                    <?php if (!empty($onlineFriends)): ?>
                        <?php foreach ($onlineFriends as $friend): ?>
                            <a href="pages/messagerie.php?user=<?= $friend['id'] ?? 0 ?>" class="flex items-center gap-3 p-2 hover:bg-gray-50 rounded-xl transition-colors group">
                                <div class="relative">
                                    <img src="<?= get_avatar_url($friend['avatar'] ?? '') ?>" class="w-10 h-10 rounded-full object-cover">
                                    <span class="absolute bottom-0 right-0 w-3 h-3 bg-green-500 border-2 border-white rounded-full"></span>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <p class="font-semibold text-sm text-gray-800 group-hover:text-primary transition-colors"><?= escape_html($friend['surnom'] ?? '') ?></p>
                                    <p class="text-xs text-green-600">En ligne</p>
                                </div>
                                <i class="fas fa-comment text-gray-300 group-hover:text-primary transition-colors"></i>
                            </a>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p class="text-sm text-gray-400 text-center py-4">Aucun ami en ligne</p>
                    <?php endif; ?>
                </div>
            </div>
        </aside>
    </div>
</div>

<!-- Create Post Modal -->
<div id="createModal" class="fixed inset-0 bg-black/60 backdrop-blur-sm z-50 hidden items-center justify-center p-4 opacity-0 transition-opacity duration-300">
    <div class="bg-white rounded-2xl w-full max-w-lg shadow-2xl transform scale-95 opacity-0 transition-all duration-300" id="createModalContent">
        <div class="p-4 border-b border-gray-100 flex items-center justify-between">
            <h3 class="font-bold text-lg">Créer une publication</h3>
            <button onclick="closeCreateModal()" class="w-8 h-8 hover:bg-gray-100 rounded-full flex items-center justify-center transition-colors">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="p-4">
            <div class="flex items-center gap-3 mb-4">
                <img src="<?= get_avatar_url($currentUser['avatar'] ?? '') ?>" class="w-10 h-10 rounded-full object-cover border-2 border-primary">
                <div>
                    <p class="font-semibold text-sm"><?= escape_html($currentUser['surnom'] ?? '') ?></p>
                    <select id="postPrivacy" class="text-xs bg-gray-100 rounded-lg px-2 py-1 border-0 outline-none cursor-pointer">
                        <option value="public">🌍 Public</option>
                        <option value="friends">👥 Amis</option>
                        <option value="private">🔒 Privé</option>
                    </select>
                </div>
            </div>
            <textarea id="postContent" class="w-full h-32 resize-none outline-none text-lg placeholder-gray-400" 
                      placeholder="Quoi de neuf, <?= escape_html($currentUser['surnom'] ?? '') ?> ?"></textarea>
            
            <!-- Image Preview -->
            <div id="imagePreview" class="hidden relative mb-4 rounded-xl overflow-hidden">
                <img src="" class="w-full h-48 object-cover">
                <button onclick="removeImage()" class="absolute top-2 right-2 w-8 h-8 bg-black/50 hover:bg-black/70 text-white rounded-full flex items-center justify-center transition-colors">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            
            <div class="border-2 border-dashed border-gray-200 rounded-xl p-4 hover:border-primary transition-colors cursor-pointer" onclick="document.getElementById('postImage').click()">
                <div class="flex items-center justify-center gap-2 text-gray-400">
                    <i class="fas fa-images text-2xl"></i>
                    <span class="font-medium">Ajouter photos/vidéos</span>
                </div>
                <input type="file" id="postImage" class="hidden" accept="image/*,video/*" onchange="handleImageSelect(this)">
            </div>
        </div>
        <div class="p-4 border-t border-gray-100">
            <button onclick="submitPost()" id="submitPostBtn" class="w-full bg-primary hover:bg-orange-600 text-white font-semibold py-3 rounded-xl transition-all hover:shadow-lg disabled:opacity-50 disabled:cursor-not-allowed flex items-center justify-center gap-2">
                <i class="fas fa-paper-plane"></i>
                <span>Publier</span>
            </button>
        </div>
    </div>
</div>

<!-- Story Viewer Modal -->
<div id="storyModal" class="fixed inset-0 bg-black z-50 hidden items-center justify-center">
    <button onclick="closeStoryModal()" class="absolute top-4 right-4 text-white/70 hover:text-white z-10">
        <i class="fas fa-times text-2xl"></i>
    </button>
    <div class="w-full max-w-md h-[80vh] bg-gray-900 rounded-2xl overflow-hidden relative">
        <div class="absolute top-0 left-0 right-0 h-1 bg-white/20">
            <div class="h-full bg-white w-0 transition-all duration-[5000ms]" id="storyProgress"></div>
        </div>
        <div class="absolute top-4 left-4 flex items-center gap-3">
            <img id="storyAvatar" src="" class="w-10 h-10 rounded-full border-2 border-white">
            <span id="storyUsername" class="text-white font-semibold"></span>
        </div>
        <img id="storyImage" src="" class="w-full h-full object-cover">
    </div>
</div>

<script>
// Configuration globale
const csrfToken = '<?= $csrfToken ?>';
const currentUserId = <?= $userId ?>;
let currentOffset = 0;
let currentFilter = 'all';
let isLoading = false;
let selectedImage = null;

// Initialisation
document.addEventListener('DOMContentLoaded', () => {
    loadFeed('all');
    startRealtimeUpdates();
});

// Chargement du feed
async function loadFeed(filter = 'all', append = false) {
    if (isLoading) return;
    isLoading = true;
    
    if (!append) {
        currentOffset = 0;
        document.getElementById('postsContainer').innerHTML = createSkeleton();
    }
    
    // Mettre à jour les filtres visuellement
    document.querySelectorAll('.feed-filter').forEach(btn => {
        btn.classList.remove('bg-primary', 'text-white', 'shadow-md');
        btn.classList.add('bg-white', 'text-gray-600', 'border', 'border-gray-200');
        if (btn.dataset.filter === filter) {
            btn.classList.remove('bg-white', 'text-gray-600', 'border', 'border-gray-200');
            btn.classList.add('bg-primary', 'text-white', 'shadow-md');
        }
    });
    
    currentFilter = filter;
    
    try {
        const response = await fetch(`api/posts.php?action=feed&filter=${filter}&limit=10&offset=${currentOffset}`);
        const data = await response.json();
        
        if (data.success) {
            if (append) {
                data.posts.forEach(post => appendPostToFeed(post));
            } else {
                document.getElementById('postsContainer').innerHTML = '';
                data.posts.forEach(post => appendPostToFeed(post));
            }
            document.getElementById('loadMoreBtn').style.display = data.has_more ? 'block' : 'none';
        } else {
            showToast('Erreur de chargement', 'error');
        }
    } catch (err) {
        console.error('Error loading feed:', err);
        showToast('Erreur de connexion', 'error');
    } finally {
        isLoading = false;
        document.getElementById('loadMoreSpinner').classList.add('hidden');
    }
}

function loadMorePosts() {
    currentOffset += 10;
    document.getElementById('loadMoreSpinner').classList.remove('hidden');
    loadFeed(currentFilter, true);
}

function createSkeleton() {
    return `
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5 space-y-4 animate-pulse">
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 rounded-full bg-gray-200"></div>
                <div class="space-y-2 flex-1">
                    <div class="h-4 w-32 bg-gray-200 rounded"></div>
                    <div class="h-3 w-24 bg-gray-200 rounded"></div>
                </div>
            </div>
            <div class="space-y-2">
                <div class="h-4 w-full bg-gray-200 rounded"></div>
                <div class="h-4 w-3/4 bg-gray-200 rounded"></div>
            </div>
            <div class="h-64 bg-gray-200 rounded-xl"></div>
        </div>
    `;
}

function appendPostToFeed(post) {
    const container = document.getElementById('postsContainer');
    const postElement = createPostElement(post);
    container.insertAdjacentHTML('beforeend', postElement);
}

function createPostElement(post) {
    const timeAgo = formatTimeAgo(post.date_publication);
    const likeClass = post.user_liked ? 'text-red-500 fas' : 'text-gray-400 far';
    const privacyIcon = {
        'public': '<i class="fas fa-globe text-xs"></i>',
        'friends': '<i class="fas fa-user-friends text-xs"></i>',
        'private': '<i class="fas fa-lock text-xs"></i>'
    }[post.privacy] || '<i class="fas fa-globe text-xs"></i>';
    
    return `
        <article class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden hover:shadow-md transition-all duration-300 animate-fade-in" data-post-id="${post.idpost}">
            <div class="p-5 flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <a href="pages/profil.php?id=${post.id_utilisateur}" class="relative">
                        <img src="<?= AVATAR_URL ?>${escapeHtml(post.avatar)}" class="w-12 h-12 rounded-full object-cover border-2 border-gray-100 hover:border-primary transition-colors">
                    </a>
                    <div>
                        <a href="pages/profil.php?id=${post.id_utilisateur}" class="font-bold text-gray-800 hover:text-primary transition-colors block">${escapeHtml(post.surnom)}</a>
                        <div class="flex items-center gap-2 text-xs text-gray-500">
                            <span>${timeAgo}</span>
                            <span>•</span>
                            <span>${privacyIcon}</span>
                            ${post.edited_at ? '<span class="text-gray-400">(modifié)</span>' : ''}
                        </div>
                    </div>
                </div>
                ${post.id_utilisateur == currentUserId ? `
                <div class="relative group">
                    <button class="p-2 hover:bg-gray-100 rounded-full transition-colors">
                        <i class="fas fa-ellipsis-h text-gray-400"></i>
                    </button>
                    <div class="absolute right-0 top-full w-48 bg-white rounded-xl shadow-xl border border-gray-100 opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all z-10">
                        <button onclick="editPost(${post.idpost})" class="w-full text-left px-4 py-2.5 hover:bg-gray-50 text-gray-700 flex items-center gap-2 rounded-t-xl">
                            <i class="fas fa-pen text-gray-400"></i>Modifier
                        </button>
                        <button onclick="deletePost(${post.idpost})" class="w-full text-left px-4 py-2.5 hover:bg-red-50 text-red-600 flex items-center gap-2">
                            <i class="fas fa-trash text-red-400"></i>Supprimer
                        </button>
                    </div>
                </div>
                ` : ''}
            </div>
            <div class="px-5 pb-3">
                <p class="text-gray-800 whitespace-pre-wrap">${escapeHtml(post.contenu)}</p>
            </div>
            ${post.image_post ? `
            <div class="relative group cursor-pointer" onclick="openImageModal('<?= POSTS_URL ?>${escapeHtml(post.image_post)}')">
                <img src="<?= POSTS_URL ?>${escapeHtml(post.image_post)}" class="w-full max-h-[500px] object-cover" loading="lazy">
                <div class="absolute inset-0 bg-black/0 group-hover:bg-black/10 transition-colors"></div>
            </div>
            ` : ''}
            <div class="px-5 py-3 flex items-center justify-between text-sm text-gray-500 border-t border-gray-100">
                <div class="flex items-center gap-1">
                    <span class="w-5 h-5 bg-red-500 rounded-full flex items-center justify-center text-white text-xs">
                        <i class="fas fa-heart"></i>
                    </span>
                    <span id="likes-count-${post.idpost}">${post.likes_count || 0}</span>
                </div>
                <div class="flex gap-4">
                    <span class="hover:text-primary cursor-pointer transition-colors">${post.comments_count || 0} commentaires</span>
                </div>
            </div>
            <div class="px-5 py-2 flex justify-between border-t border-gray-100">
                <button onclick="toggleLike(${post.idpost})" id="like-btn-${post.idpost}" class="flex-1 flex items-center justify-center gap-2 py-2.5 hover:bg-gray-50 rounded-xl transition-colors ${post.user_liked ? 'text-red-500' : 'text-gray-600'}">
                    <i class="${likeClass} fa-heart text-lg transition-transform" id="like-icon-${post.idpost}"></i>
                    <span class="font-semibold text-sm">J'aime</span>
                </button>
                <button onclick="toggleComments(${post.idpost})" class="flex-1 flex items-center justify-center gap-2 py-2.5 hover:bg-gray-50 rounded-xl text-gray-600 transition-colors">
                    <i class="far fa-comment-alt text-lg"></i>
                    <span class="font-semibold text-sm">Commenter</span>
                </button>
                <button onclick="sharePost(${post.idpost})" class="flex-1 flex items-center justify-center gap-2 py-2.5 hover:bg-gray-50 rounded-xl text-gray-600 transition-colors">
                    <i class="fas fa-share text-lg"></i>
                    <span class="font-semibold text-sm">Partager</span>
                </button>
            </div>
            <div id="comments-${post.idpost}" class="hidden px-5 py-3 bg-gray-50 border-t border-gray-100">
                <div class="flex gap-3">
                    <img src="<?= get_avatar_url($_SESSION['avatar'] ?? '') ?>" class="w-8 h-8 rounded-full">
                    <div class="flex-1 relative">
                        <input type="text" placeholder="Écrire un commentaire..." 
                               class="w-full px-4 py-2 pr-12 rounded-full bg-white border border-gray-200 focus:border-primary focus:ring-2 focus:ring-primary/20 outline-none text-sm"
                               onkeypress="if(event.key=='Enter') submitComment(${post.idpost}, this.value)">
                        <button onclick="submitComment(${post.idpost}, this.previousElementSibling.value)" 
                                class="absolute right-2 top-1/2 -translate-y-1/2 w-8 h-8 bg-primary text-white rounded-full flex items-center justify-center hover:bg-orange-600 transition-colors">
                            <i class="fas fa-paper-plane text-xs"></i>
                        </button>
                    </div>
                </div>
                <div id="comments-list-${post.idpost}" class="mt-3 space-y-2"></div>
            </div>
        </article>
    `;
}

// Fonctions d'interaction
async function toggleLike(postId) {
    try {
        const response = await fetch('api/posts.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                action: 'like',
                post_id: postId,
                csrf_token: csrfToken
            })
        });
        const data = await response.json();
        
        if (data.success) {
            const btn = document.getElementById(`like-btn-${postId}`);
            const icon = document.getElementById(`like-icon-${postId}`);
            const count = document.getElementById(`likes-count-${postId}`);
            
            if (data.liked) {
                btn.classList.add('text-red-500');
                btn.classList.remove('text-gray-600');
                icon.classList.remove('far', 'text-gray-400');
                icon.classList.add('fas');
                icon.style.transform = 'scale(1.3)';
                setTimeout(() => icon.style.transform = 'scale(1)', 200);
            } else {
                btn.classList.remove('text-red-500');
                btn.classList.add('text-gray-600');
                icon.classList.remove('fas');
                icon.classList.add('far');
            }
            count.textContent = data.likes_count;
        }
    } catch (err) {
        console.error('Error toggling like:', err);
        showToast('Erreur lors de l\'action', 'error');
    }
}

async function submitComment(postId, content) {
    if (!content.trim()) return;
    
    try {
        const response = await fetch('api/posts.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                action: 'comment',
                post_id: postId,
                content: content,
                csrf_token: csrfToken
            })
        });
        const data = await response.json();
        
        if (data.success) {
            showToast('Commentaire ajouté', 'success');
            // Rafraîchir le post pour voir le nouveau commentaire
            loadFeed(currentFilter);
        } else {
            showToast(data.error || 'Erreur lors de l\'ajout', 'error');
        }
    } catch (err) {
        console.error('Error submitting comment:', err);
        showToast('Erreur de connexion', 'error');
    }
}

async function deletePost(postId) {
    if (!confirm('Voulez-vous vraiment supprimer cette publication ?')) return;
    
    try {
        const response = await fetch(`api/posts.php?post_id=${postId}&csrf_token=${csrfToken}`, {
            method: 'DELETE'
        });
        const data = await response.json();
        
        if (data.success) {
            document.querySelector(`[data-post-id="${postId}"]`).remove();
            showToast('Publication supprimée', 'success');
        } else {
            showToast(data.error || 'Erreur lors de la suppression', 'error');
        }
    } catch (err) {
        console.error('Error deleting post:', err);
        showToast('Erreur de connexion', 'error');
    }
}

// Gestion du modal de création
function openCreateModal(type = 'post') {
    const modal = document.getElementById('createModal');
    const content = document.getElementById('createModalContent');
    modal.classList.remove('hidden');
    modal.classList.add('flex');
    setTimeout(() => {
        modal.classList.remove('opacity-0');
        content.classList.remove('scale-95', 'opacity-0');
        content.classList.add('scale-100', 'opacity-100');
    }, 10);
}

function closeCreateModal() {
    const modal = document.getElementById('createModal');
    const content = document.getElementById('createModalContent');
    modal.classList.add('opacity-0');
    content.classList.remove('scale-100', 'opacity-100');
    content.classList.add('scale-95', 'opacity-0');
    setTimeout(() => {
        modal.classList.add('hidden');
        modal.classList.remove('flex');
        document.getElementById('postContent').value = '';
        removeImage();
    }, 300);
}

function handleImageSelect(input) {
    if (input.files && input.files[0]) {
        selectedImage = input.files[0];
        const reader = new FileReader();
        reader.onload = function(e) {
            const preview = document.getElementById('imagePreview');
            preview.querySelector('img').src = e.target.result;
            preview.classList.remove('hidden');
        };
        reader.readAsDataURL(input.files[0]);
    }
}

function removeImage() {
    selectedImage = null;
    document.getElementById('postImage').value = '';
    document.getElementById('imagePreview').classList.add('hidden');
}

async function submitPost() {
    const content = document.getElementById('postContent').value.trim();
    const privacy = document.getElementById('postPrivacy').value;
    const btn = document.getElementById('submitPostBtn');
    
    if (!content && !selectedImage) {
        showToast('Veuillez ajouter du contenu', 'error');
        return;
    }
    
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Publication...';
    
    try {
        const formData = new FormData();
        formData.append('action', 'create');
        formData.append('content', content);
        formData.append('privacy', privacy);
        formData.append('csrf_token', csrfToken);
        if (selectedImage) formData.append('image', selectedImage);
        
        const response = await fetch('api/posts.php', {
            method: 'POST',
            body: formData
        });
        const data = await response.json();
        
        if (data.success) {
            showToast('Publication créée !', 'success');
            closeCreateModal();
            loadFeed(currentFilter);
        } else {
            showToast(data.error || 'Erreur lors de la publication', 'error');
        }
    } catch (err) {
        console.error('Error submitting post:', err);
        showToast('Erreur de connexion', 'error');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-paper-plane"></i><span>Publier</span>';
    }
}

// Stories
function viewStory(storyId) {
    const modal = document.getElementById('storyModal');
    modal.classList.remove('hidden');
    modal.classList.add('flex');
    setTimeout(() => {
        document.getElementById('storyProgress').style.width = '100%';
    }, 100);
    setTimeout(() => {
        closeStoryModal();
    }, 5000);
}

function closeStoryModal() {
    const modal = document.getElementById('storyModal');
    modal.classList.add('hidden');
    modal.classList.remove('flex');
    document.getElementById('storyProgress').style.width = '0';
}

// Recherche
let searchTimeout;
function handleSearch(query) {
    clearTimeout(searchTimeout);
    const resultsDiv = document.getElementById('searchResults');
    
    if (query.length < 2) {
        resultsDiv.classList.add('hidden');
        return;
    }
    
    searchTimeout = setTimeout(async () => {
        try {
            const response = await fetch(`api/search.php?q=${encodeURIComponent(query)}&limit=5`);
            const data = await response.json();
            
            if (data.success && data.results.users && data.results.users.length > 0) {
                resultsDiv.innerHTML = data.results.users.map(user => `
                    <a href="pages/profil.php?id=${user.id}" class="flex items-center gap-3 p-3 hover:bg-gray-50 transition-colors border-b border-gray-100 last:border-0">
                        <img src="<?= AVATAR_URL ?>${escapeHtml(user.avatar)}" class="w-10 h-10 rounded-full">
                        <div>
                            <p class="font-semibold text-sm text-gray-800">${escapeHtml(user.surnom)}</p>
                            <p class="text-xs text-gray-500">${escapeHtml(user.universite || 'WideMaze')}</p>
                        </div>
                    </a>
                `).join('');
                resultsDiv.classList.remove('hidden');
            } else {
                resultsDiv.innerHTML = '<p class="p-4 text-gray-500 text-center">Aucun résultat</p>';
                resultsDiv.classList.remove('hidden');
            }
        } catch (err) {
            console.error('Search error:', err);
        }
    }, 300);
}

// Actions amis et communautés
async function sendFriendRequest(userId, btn) {
    try {
        const formData = new FormData();
        formData.append('action', 'send_request');
        formData.append('user_id', userId);
        formData.append('csrf_token', csrfToken);
        
        const response = await fetch('api/friends.php', {
            method: 'POST',
            body: formData
        });
        const data = await response.json();
        
        if (data.success) {
            if (btn) {
                btn.innerHTML = '<i class="fas fa-clock"></i>';
                btn.classList.add('text-orange-500');
                btn.disabled = true;
            }
            showToast('Demande d\'ami envoyée !', 'success');
        } else {
            showToast(data.error || 'Erreur lors de l\'envoi', 'error');
        }
    } catch (err) {
        console.error('Error sending friend request:', err);
        showToast('Erreur de connexion', 'error');
    }
}

async function joinCommunity(communityId) {
    try {
        const formData = new FormData();
        formData.append('action', 'join');
        formData.append('community_id', communityId);
        formData.append('csrf_token', csrfToken);
        
        const response = await fetch('api/communities.php', {
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
        console.error('Error joining community:', err);
        showToast('Erreur de connexion', 'error');
    }
}

// Utilitaires
function formatTimeAgo(dateString) {
    const date = new Date(dateString);
    const now = new Date();
    const diff = Math.floor((now - date) / 1000);
    
    if (diff < 60) return 'À l\'instant';
    if (diff < 3600) return `Il y a ${Math.floor(diff / 60)} min`;
    if (diff < 86400) return `Il y a ${Math.floor(diff / 3600)} h`;
    if (diff < 604800) return `Il y a ${Math.floor(diff / 86400)} j`;
    return date.toLocaleDateString('fr-FR', {day: 'numeric', month: 'short'});
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
        success: 'bg-green-500',
        error: 'bg-red-500',
        info: 'bg-blue-500',
        warning: 'bg-yellow-500'
    };
    
    const toast = document.createElement('div');
    toast.className = `${colors[type]} text-white px-6 py-3 rounded-xl shadow-lg flex items-center gap-3 animate-fade-in`;
    toast.innerHTML = `
        <i class="fas ${type === 'success' ? 'fa-check-circle' : type === 'error' ? 'fa-exclamation-circle' : 'fa-info-circle'}"></i>
        <span class="font-medium">${message}</span>
    `;
    
    container.appendChild(toast);
    
    setTimeout(() => {
        toast.style.opacity = '0';
        toast.style.transform = 'translateX(20px)';
        setTimeout(() => toast.remove(), 300);
    }, 3000);
}

function toggleComments(postId) {
    const commentsDiv = document.getElementById(`comments-${postId}`);
    if (commentsDiv) {
        commentsDiv.classList.toggle('hidden');
    }
}

function sharePost(postId) {
    navigator.clipboard.writeText(window.location.href.split('?')[0] + '?post=' + postId);
    showToast('Lien copié dans le presse-papier !', 'success');
}

function editPost(postId) {
    // À implémenter
    console.log('Edit post:', postId);
    showToast('Fonctionnalité en développement', 'info');
}

function openImageModal(imageUrl) {
    window.open(imageUrl, '_blank');
}

function startRealtimeUpdates() {
    // Vérifier les nouvelles notifications toutes les 30 secondes
    setInterval(async () => {
        try {
            const response = await fetch('api/notifications.php?unread=1&limit=1');
            const data = await response.json();
            if (data.unread_count > 0) {
                const badge = document.querySelector('.fa-bell + span');
                if (badge) {
                    badge.textContent = data.unread_count > 9 ? '9+' : data.unread_count;
                    badge.classList.remove('hidden');
                }
            }
        } catch (err) {
            console.error('Error checking notifications:', err);
        }
    }, 30000);
}

// Fermeture des modals
document.getElementById('createModal')?.addEventListener('click', (e) => {
    if (e.target === e.currentTarget) closeCreateModal();
});

document.addEventListener('click', (e) => {
    if (!e.target.closest('#globalSearch') && !e.target.closest('#searchResults')) {
        document.getElementById('searchResults')?.classList.add('hidden');
    }
});

// Variables pour story
let selectedStoryFile = null;
let storyType = null;

function openStoryUpload() {
    document.getElementById('storyModal').classList.remove('hidden');
    document.getElementById('storyModal').classList.add('flex');
}

function closeStoryUploadModal() {
    document.getElementById('storyModal').classList.add('hidden');
    document.getElementById('storyModal').classList.remove('flex');
    document.getElementById('storyFile').value = '';
    document.getElementById('storyPreview').classList.add('hidden');
    document.getElementById('submitStoryBtn').classList.add('hidden');
    selectedStoryFile = null;
}

function previewStory(input) {
    if (input.files && input.files[0]) {
        selectedStoryFile = input.files[0];
        const file = selectedStoryFile;
        const isVideo = file.type.startsWith('video/');
        const isImage = file.type.startsWith('image/');
        
        if (!isVideo && !isImage) {
            showToast('Format non supporté', 'error');
            return;
        }
        
        if (file.size > 20 * 1024 * 1024) {
            showToast('Fichier trop volumineux (max 20MB)', 'error');
            return;
        }
        
        storyType = isVideo ? 'video' : 'image';
        const preview = document.getElementById('storyPreview');
        const imgPreview = document.getElementById('storyImagePreview');
        const videoPreview = document.getElementById('storyVideoPreview');
        
        const reader = new FileReader();
        reader.onload = function(e) {
            if (isVideo) {
                imgPreview.classList.add('hidden');
                videoPreview.classList.remove('hidden');
                videoPreview.src = e.target.result;
                // Vérifier la durée de la vidéo
                videoPreview.onloadedmetadata = function() {
                    if (videoPreview.duration > 30) {
                        showToast('Vidéo trop longue (max 30 secondes)', 'error');
                        removeStoryPreview();
                    }
                };
            } else {
                videoPreview.classList.add('hidden');
                imgPreview.classList.remove('hidden');
                imgPreview.src = e.target.result;
            }
            preview.classList.remove('hidden');
            document.getElementById('submitStoryBtn').classList.remove('hidden');
        };
        reader.readAsDataURL(file);
    }
}

function removeStoryPreview() {
    selectedStoryFile = null;
    document.getElementById('storyFile').value = '';
    document.getElementById('storyPreview').classList.add('hidden');
    document.getElementById('submitStoryBtn').classList.add('hidden');
}

async function submitStory() {
    if (!selectedStoryFile) return;
    
    const formData = new FormData();
    formData.append('action', 'create');
    formData.append('media', selectedStoryFile);
    formData.append('csrf_token', csrfToken);
    
    const btn = document.getElementById('submitStoryBtn');
    const originalText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Publication...';
    
    try {
        const response = await fetch('api/stories.php', {
            method: 'POST',
            body: formData
        });
        const data = await response.json();
        
        if (data.success) {
            showToast('Story publiée !', 'success');
            closeStoryUploadModal();
            // Recharger la page pour voir la nouvelle story
            setTimeout(() => location.reload(), 1500);
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
</script>

<?php include __DIR__ . '/includes/templates/footer.php'; ?>