<?php
/**
 * WideMaze - Page de Profil
 * Affichage du profil utilisateur avec ses publications et informations
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_auth();

$userId = $_SESSION['user_id'];
$profileId = isset($_GET['id']) ? intval($_GET['id']) : $userId;
$isOwnProfile = ($profileId == $userId);
$activeTab = $_GET['tab'] ?? 'posts';

// Récupération des données utilisateur
try {
    $stmt = $pdo->prepare("
        SELECT u.*,
            (SELECT COUNT(*) FROM posts WHERE id_utilisateur = u.id) as posts_count,
            (SELECT COUNT(*) FROM ami WHERE (id = u.id OR idami = u.id) AND accepterami = 1) as friends_count,
            (SELECT COUNT(*) FROM notifications WHERE user_id = u.id AND is_read = 0) as unread_notifications
        FROM utilisateurs u
        WHERE u.id = ? AND u.is_active = 1
    ");
    $stmt->execute([$profileId]);
    $user = $stmt->fetch();
    
    if (!$user) {
        header('Location: ../index.php');
        exit();
    }
} catch (PDOException $e) {
    error_log("Error fetching user: " . $e->getMessage());
    header('Location: ../index.php');
    exit();
}

$pendingRequestsCount = 0;
if ($isOwnProfile) {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM ami WHERE idami = ? AND demandeami = 1 AND accepterami = 0");
        $stmt->execute([$userId]);
        $pendingRequestsCount = $stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log("Error fetching pending requests: " . $e->getMessage());
    }
}
// Vérification relation d'amitié
$friendshipStatus = 'none';
if (!$isOwnProfile) {
    try {
        $stmt = $pdo->prepare("
            SELECT demandeami, accepterami FROM ami
            WHERE (id = ? AND idami = ?) OR (id = ? AND idami = ?)
        ");
        $stmt->execute([$userId, $profileId, $profileId, $userId]);
        $friendship = $stmt->fetch();
        
        if ($friendship) {
            if ($friendship['accepterami']) {
                $friendshipStatus = 'friends';
            } elseif ($friendship['demandeami']) {
                $friendshipStatus = 'pending';
            }
        }
    } catch (PDOException $e) {
        error_log("Error fetching friendship: " . $e->getMessage());
    }
}

// Récupération des posts
$posts = [];
try {
    $stmt = $pdo->prepare("
        SELECT p.*,
            (SELECT COUNT(*) FROM postlike WHERE idpost = p.idpost) as likes_count,
            (SELECT COUNT(*) FROM postcommentaire WHERE idpost = p.idpost) as comments_count,
            (SELECT EXISTS(SELECT 1 FROM postlike WHERE idpost = p.idpost AND id = ?)) as user_liked
        FROM posts p
        WHERE p.id_utilisateur = ?
        ORDER BY p.date_publication DESC
        LIMIT 20
    ");
    $stmt->execute([$userId, $profileId]);
    $posts = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Error fetching posts: " . $e->getMessage());
}

// Récupération des photos
$photos = [];
try {
    $stmt = $pdo->prepare("
        SELECT image_post FROM posts
        WHERE id_utilisateur = ? AND image_post IS NOT NULL
        ORDER BY date_publication DESC
        LIMIT 9
    ");
    $stmt->execute([$profileId]);
    $photos = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Error fetching photos: " . $e->getMessage());
}

$csrfToken = generate_csrf_token();
$page_title = $user['prenom'] . ' ' . $user['nom'] . ' - Profil';
$body_class = 'bg-gray-100';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title) ?> - WideMaze</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f3f4f6; }
        .cover-gradient {
            background: linear-gradient(to bottom, rgba(0,0,0,0.4) 0%, rgba(0,0,0,0) 50%, rgba(0,0,0,0.4) 100%);
        }
        .tab-btn { transition: all 0.2s; }
        .tab-active { border-bottom: 3px solid #f59e0b; color: #f59e0b; }
        .photo-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 4px; }
        .story-ring { background: linear-gradient(45deg, #f59e0b, #ec4899, #8b5cf6); padding: 3px; border-radius: 50%; }
    </style>
</head>
<body class="bg-gray-100">
    <!-- Navigation -->
    <nav class="fixed top-0 w-full bg-white shadow-md z-50">
        <div class="container mx-auto px-4 h-16 flex items-center justify-between">
            <a href="../index.php" class="flex items-center gap-2 hover:opacity-80 transition-opacity">
                <div class="w-10 h-10 bg-gradient-to-br from-orange-500 to-red-600 rounded-lg flex items-center justify-center">
                    <i class="fas fa-network-wired text-white"></i>
                </div>
                <span class="text-2xl font-bold text-gray-800 hidden md:block">WideMaze</span>
            </a>
            <div class="flex items-center gap-4">
                <a href="../index.php" class="p-2 hover:bg-gray-100 rounded-full transition-colors">
                    <i class="fas fa-home text-xl text-gray-600"></i>
                </a>
                <a href="notifications.php" class="p-2 hover:bg-gray-100 rounded-full transition-colors relative">
                    <i class="fas fa-bell text-xl text-gray-600"></i>
                    <?php if (($user['unread_notifications'] ?? 0) > 0): ?>
                        <span class="absolute top-0 right-0 w-2 h-2 bg-red-500 rounded-full"></span>
                    <?php endif; ?>
                </a>
                <a href="messagerie.php" class="p-2 hover:bg-gray-100 rounded-full transition-colors">
                    <i class="fas fa-comment text-xl text-gray-600"></i>
                </a>
                <div class="relative group">
                    <button class="flex items-center gap-2 p-1 hover:bg-gray-100 rounded-full transition-colors">
                        <img src="<?= get_avatar_url($_SESSION['avatar'] ?? '') ?>" class="w-8 h-8 rounded-full object-cover border-2 border-orange-500">
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
    
    <!-- Cover Photo -->
    <div class="h-80 md:h-96 mt-16 relative group">
        <div class="absolute inset-0 bg-gradient-to-r from-purple-600 via-pink-500 to-orange-500">
            <?php if (!empty($user['cover_image'])): ?>
                <img src="../uploads/covers/<?= htmlspecialchars($user['cover_image']) ?>" class="w-full h-full object-cover" alt="Photo de couverture">
            <?php endif; ?>
        </div>
        <div class="absolute inset-0 cover-gradient"></div>
        <?php if ($isOwnProfile): ?>
            <button onclick="document.getElementById('coverInput').click()" class="absolute bottom-4 right-4 bg-white/90 hover:bg-white px-4 py-2 rounded-lg shadow-lg transition-all opacity-0 group-hover:opacity-100 flex items-center gap-2">
                <i class="fas fa-camera"></i>Modifier la photo de couverture
            </button>
            <input type="file" id="coverInput" class="hidden" accept="image/*" onchange="updateCover(this)">
        <?php endif; ?>
    </div>
    
    <!-- Profile Header -->
    <div class="container mx-auto px-4">
        <div class="relative -mt-20 md:mt-24 mb-6">
            <div class="flex flex-col md:flex-row items-center md:items-end gap-6">
                <!-- Avatar -->
                <div class="relative">
                    <div class="story-ring p-1 rounded-full">
                        <div class="w-32 h-32 md:w-40 md:h-40 rounded-full border-4 border-white bg-white overflow-hidden shadow-xl">
                            <img src="<?= get_avatar_url($user['avatar'] ?? '') ?>" class="w-full h-full object-cover">
                        </div>
                    </div>
                    <?php if ($isOwnProfile): ?>
                        <a href="parametres.php?tab=avatar" class="absolute bottom-2 right-2 w-10 h-10 bg-gray-100 hover:bg-gray-200 rounded-full flex items-center justify-center shadow-md transition-colors border-2 border-white">
                            <i class="fas fa-camera text-gray-600"></i>
                        </a>
                    <?php endif; ?>
                    <?php if (!empty($user['is_verified'])): ?>
                        <div class="absolute bottom-2 left-2 w-8 h-8 bg-blue-500 rounded-full flex items-center justify-center text-white text-sm border-2 border-white" title="Compte vérifié">
                            <i class="fas fa-check"></i>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Info -->
                <div class="flex-1 text-center md:text-left mb-2">
                    <h1 class="text-3xl font-bold text-gray-800 flex items-center justify-center md:justify-start gap-2">
                        <?= htmlspecialchars($user['prenom'] . ' ' . $user['nom']) ?>
                        <?php if (($user['role'] ?? '') == 'admin'): ?>
                            <span class="px-2 py-1 bg-red-100 text-red-600 text-xs rounded-full">Admin</span>
                        <?php elseif (($user['role'] ?? '') == 'professeur'): ?>
                            <span class="px-2 py-1 bg-blue-100 text-blue-600 text-xs rounded-full">Professeur</span>
                        <?php endif; ?>
                    </h1>
                    <p class="text-gray-500 text-lg">@<?= htmlspecialchars($user['surnom'] ?? '') ?></p>
                    <div class="flex flex-wrap items-center justify-center md:justify-start gap-3 mt-2 text-sm text-gray-600">
                        <?php if (!empty($user['profession'])): ?>
                            <span class="flex items-center gap-1">
                                <i class="fas fa-briefcase text-orange-500"></i>
                                <?= htmlspecialchars($user['profession']) ?>
                            </span>
                        <?php endif; ?>
                        <?php if (!empty($user['universite'])): ?>
                            <span class="flex items-center gap-1">
                                <i class="fas fa-university text-orange-500"></i>
                                <?= htmlspecialchars($user['universite']) ?>
                            </span>
                        <?php endif; ?>
                        <?php if (!empty($user['nationalite'])): ?>
                            <span class="flex items-center gap-1">
                                <i class="fas fa-map-marker-alt text-orange-500"></i>
                                <?= htmlspecialchars($user['nationalite']) ?>
                            </span>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Actions -->
                    <div class="flex gap-3 mt-4 justify-center md:justify-start">
                        <?php if ($isOwnProfile): ?>
                            <button onclick="openEditModal()" class="bg-gray-200 hover:bg-gray-300 px-6 py-2 rounded-lg font-medium transition-colors flex items-center gap-2">
                                <i class="fas fa-pen"></i>Modifier le profil
                            </button>
                            <button onclick="switchTab('requests')" class="tab-btn <?= $activeTab == 'requests' ? 'tab-active' : '' ?> px-4 py-2 font-semibold text-gray-600 hover:bg-gray-100 rounded-lg transition-colors" data-tab="requests">
                                <span class="text-2xl block text-center"><?= $pendingRequestsCount ?? 0 ?></span>
                                <span class="text-sm">Demandes</span>
                            </button>
                            <a href="parametres.php" class="bg-orange-500 hover:bg-orange-600 text-white px-6 py-2 rounded-lg font-medium transition-colors flex items-center gap-2">
                                <i class="fas fa-cog"></i>Paramètres
                            </a>
                        <?php else: ?>
                            <?php if ($friendshipStatus == 'none'): ?>
                                <button onclick="sendFriendRequest(<?= $profileId ?>)" class="bg-orange-500 hover:bg-orange-600 text-white px-6 py-2 rounded-lg font-medium transition-colors flex items-center gap-2">
                                    <i class="fas fa-user-plus"></i>Ajouter
                                </button>
                            <?php elseif ($friendshipStatus == 'pending'): ?>
                                <button class="bg-gray-200 px-6 py-2 rounded-lg font-medium flex items-center gap-2 cursor-default">
                                    <i class="fas fa-clock"></i>En attente
                                </button>
                            <?php else: ?>
                                <button class="bg-green-100 text-green-700 px-6 py-2 rounded-lg font-medium flex items-center gap-2">
                                    <i class="fas fa-check"></i>Amis
                                </button>
                            <?php endif; ?>
                            <a href="messagerie.php?user=<?= $profileId ?>" class="bg-gray-200 hover:bg-gray-300 px-6 py-2 rounded-lg font-medium transition-colors flex items-center gap-2">
                                <i class="fas fa-comment"></i>Message
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Stats -->
                <div class="flex gap-8 border-t border-b border-gray-200 py-4 mt-4 w-full justify-center md:justify-start">
                    <button onclick="switchTab('posts')" class="tab-btn <?= $activeTab == 'posts' ? 'tab-active' : '' ?> px-4 py-2 font-semibold text-gray-600 hover:bg-gray-100 rounded-lg transition-colors" data-tab="posts">
                        <span class="text-2xl block text-center"><?= number_format($user['posts_count'] ?? 0) ?></span>
                        <span class="text-sm">Publications</span>
                    </button>
                    <button onclick="switchTab('friends')" class="tab-btn <?= $activeTab == 'friends' ? 'tab-active' : '' ?> px-4 py-2 font-semibold text-gray-600 hover:bg-gray-100 rounded-lg transition-colors" data-tab="friends">
                        <span class="text-2xl block text-center"><?= number_format($user['friends_count'] ?? 0) ?></span>
                        <span class="text-sm">Amis</span>
                    </button>
                    <button onclick="switchTab('photos')" class="tab-btn <?= $activeTab == 'photos' ? 'tab-active' : '' ?> px-4 py-2 font-semibold text-gray-600 hover:bg-gray-100 rounded-lg transition-colors" data-tab="photos">
                        <span class="text-2xl block text-center"><?= count($photos) ?></span>
                        <span class="text-sm">Photos</span>
                    </button>
                    <button onclick="switchTab('about')" class="tab-btn <?= $activeTab == 'about' ? 'tab-active' : '' ?> px-4 py-2 font-semibold text-gray-600 hover:bg-gray-100 rounded-lg transition-colors" data-tab="about">
                        <span class="text-2xl block text-center"><i class="fas fa-info-circle text-lg"></i></span>
                        <span class="text-sm">À propos</span>
                    </button>
                </div>
            </div>
        </div>
        
        <!-- Content Grid -->
        <div class="grid md:grid-cols-3 gap-6 pb-8">
            <!-- Left Sidebar -->
            <div class="space-y-4">
                <!-- Intro -->
                <div class="bg-white rounded-xl shadow-sm p-4">
                    <h3 class="font-bold text-lg mb-4 flex items-center gap-2">
                        <i class="fas fa-user-circle text-orange-500"></i>Intro
                    </h3>
                    <?php if (!empty($user['bio'])): ?>
                        <p class="text-gray-700 mb-4"><?= nl2br(htmlspecialchars($user['bio'])) ?></p>
                    <?php else: ?>
                        <p class="text-gray-400 italic mb-4">Aucune bio</p>
                    <?php endif; ?>
                    <?php if ($isOwnProfile): ?>
                        <button onclick="editBio()" class="w-full bg-gray-100 hover:bg-gray-200 py-2 rounded-lg font-medium transition-colors text-sm">
                            <?= empty($user['bio']) ? 'Ajouter une bio' : 'Modifier la bio' ?>
                        </button>
                    <?php endif; ?>
                    
                    <div class="mt-4 space-y-3 text-sm border-t pt-4">
                        <?php if (!empty($user['universite'])): ?>
                            <div class="flex items-center gap-3">
                                <i class="fas fa-graduation-cap text-gray-400 w-5"></i>
                                <span>Étudie à <strong><?= htmlspecialchars($user['universite']) ?></strong></span>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($user['faculte'])): ?>
                            <div class="flex items-center gap-3">
                                <i class="fas fa-book text-gray-400 w-5"></i>
                                <span><?= htmlspecialchars($user['faculte']) ?></span>
                            </div>
                        <?php endif; ?>
                        <div class="flex items-center gap-3">
                            <i class="fas fa-map-marker-alt text-gray-400 w-5"></i>
                            <span>Habite à <strong><?= htmlspecialchars($user['nationalite'] ?? 'Non spécifié') ?></strong></span>
                        </div>
                        <div class="flex items-center gap-3">
                            <i class="fas fa-clock text-gray-400 w-5"></i>
                            <span>A rejoint WideMaze en <?= isset($user['dateinscription']) ? date('F Y', strtotime($user['dateinscription'])) : 'récemment' ?></span>
                        </div>
                        <?php if (!empty($user['datedenaissance'])): ?>
                            <div class="flex items-center gap-3">
                                <i class="fas fa-birthday-cake text-gray-400 w-5"></i>
                                <span><?= date('d F Y', strtotime($user['datedenaissance'])) ?></span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Photos Grid -->
                <div class="bg-white rounded-xl shadow-sm p-4">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="font-bold text-lg">Photos</h3>
                        <button onclick="switchTab('photos')" class="text-orange-500 hover:underline text-sm">Voir tout</button>
                    </div>
                    <?php if (count($photos) > 0): ?>
                        <div class="photo-grid">
                            <?php foreach (array_slice($photos, 0, 9) as $photo): ?>
                                <div class="aspect-square bg-gray-200 rounded-lg overflow-hidden hover:opacity-80 cursor-pointer transition-opacity">
                                    <img src="../uploads/posts/<?= htmlspecialchars($photo['image_post']) ?>" class="w-full h-full object-cover" alt="Photo">
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p class="text-gray-400 text-center py-4">Aucune photo</p>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Main Content -->
            <div class="md:col-span-2">
                <!-- Tab: Posts -->
                <div id="tab-posts" class="tab-content <?= $activeTab == 'posts' ? '' : 'hidden' ?> space-y-4">
                    <?php if ($isOwnProfile): ?>
                        <!-- Create Post -->
                        <div class="bg-white rounded-xl shadow-sm p-4">
                            <div class="flex gap-3">
                                <img src="<?= get_avatar_url($_SESSION['avatar'] ?? '') ?>" class="w-10 h-10 rounded-full">
                                <button onclick="location.href='../index.php?create=post'" class="flex-1 bg-gray-100 hover:bg-gray-200 rounded-full px-4 py-2 text-left text-gray-600 transition-colors">
                                    Quoi de neuf, <?= htmlspecialchars($_SESSION['surnom'] ?? '') ?> ?
                                </button>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($posts)): ?>
                        <?php foreach ($posts as $post): ?>
                            <?php include __DIR__ . '/../includes/components/post-card.php'; ?>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="bg-white rounded-xl shadow-sm p-12 text-center text-gray-500">
                            <i class="fas fa-camera text-4xl mb-4 text-gray-300"></i>
                            <p class="text-lg">Aucune publication pour le moment</p>
                            <?php if ($isOwnProfile): ?>
                                <button onclick="location.href='../index.php?create=post'" class="mt-4 text-orange-500 hover:underline">Créer votre première publication</button>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Tab: Friends -->
                <div id="tab-friends" class="tab-content <?= $activeTab == 'friends' ? '' : 'hidden' ?> bg-white rounded-xl shadow-sm p-8 text-center">
                    <i class="fas fa-users text-4xl text-gray-300 mb-4"></i>
                    <p class="text-gray-500">Liste des amis à implémenter</p>
                </div>
                
                <!-- Tab: Photos -->
                <div id="tab-photos" class="tab-content <?= $activeTab == 'photos' ? '' : 'hidden' ?>">
                    <div class="bg-white rounded-xl shadow-sm p-4">
                        <h3 class="font-bold text-lg mb-4">Toutes les photos</h3>
                        <div class="grid grid-cols-2 md:grid-cols-3 gap-4">
                            <?php foreach ($photos as $photo): ?>
                                <div class="aspect-square bg-gray-200 rounded-lg overflow-hidden hover:opacity-80 cursor-pointer transition-opacity">
                                    <img src="../uploads/posts/<?= htmlspecialchars($photo['image_post']) ?>" class="w-full h-full object-cover" alt="Photo">
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Tab: Demandes d'ami -->
                <div id="tab-requests" class="tab-content <?= $activeTab == 'requests' ? '' : 'hidden' ?> bg-white rounded-xl shadow-sm p-6">
                    <h3 class="font-bold text-xl mb-6 flex items-center gap-2">
                        <i class="fas fa-user-plus text-orange-500"></i>
                        Demandes d'ami
                    </h3>
                    
                    <?php
                    // Récupérer les demandes reçues
                    $pendingRequests = [];
                    try {
                        $stmt = $pdo->prepare("
                            SELECT u.id, u.surnom, u.prenom, u.nom, u.avatar, u.universite, a.date_demande
                            FROM ami a
                            JOIN utilisateurs u ON a.id = u.id
                            WHERE a.idami = ? AND a.demandeami = 1 AND a.accepterami = 0
                            ORDER BY a.date_demande DESC
                        ");
                        $stmt->execute([$userId]);
                        $pendingRequests = $stmt->fetchAll();
                    } catch (PDOException $e) {
                        error_log("Error fetching pending requests: " . $e->getMessage());
                    }
                    ?>
                    
                    <?php if (empty($pendingRequests)): ?>
                        <div class="text-center py-12 text-gray-400">
                            <i class="fas fa-user-friends text-5xl mb-3"></i>
                            <p>Aucune demande d'ami en attente</p>
                        </div>
                    <?php else: ?>
                        <div class="space-y-4">
                            <?php foreach ($pendingRequests as $request): ?>
                                <div class="flex items-center justify-between p-4 bg-gray-50 rounded-xl hover:shadow-md transition-all">
                                    <div class="flex items-center gap-4">
                                        <img src="<?= get_avatar_url($request['avatar'] ?? '') ?>" class="w-12 h-12 rounded-full object-cover">
                                        <div>
                                            <a href="profil.php?id=<?= $request['id'] ?>" class="font-semibold text-gray-800 hover:text-orange-500">
                                                <?= htmlspecialchars($request['prenom'] . ' ' . $request['nom']) ?>
                                            </a>
                                            <p class="text-sm text-gray-500">@<?= htmlspecialchars($request['surnom']) ?></p>
                                            <?php if (!empty($request['universite'])): ?>
                                                <p class="text-xs text-gray-400"><i class="fas fa-university mr-1"></i><?= htmlspecialchars($request['universite']) ?></p>
                                            <?php endif; ?>
                                            <p class="text-xs text-gray-400 mt-1">Demande envoyée le <?= date('d/m/Y', strtotime($request['date_demande'])) ?></p>
                                        </div>
                                    </div>
                                    <div class="flex gap-2">
                                        <button onclick="acceptFriendRequest(<?= $request['id'] ?>)" 
                                                class="bg-green-500 hover:bg-green-600 text-white px-4 py-2 rounded-lg transition-colors flex items-center gap-2">
                                            <i class="fas fa-check"></i> Accepter
                                        </button>
                                        <button onclick="rejectFriendRequest(<?= $request['id'] ?>)" 
                                                class="bg-red-500 hover:bg-red-600 text-white px-4 py-2 rounded-lg transition-colors flex items-center gap-2">
                                            <i class="fas fa-times"></i> Refuser
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
                <!-- Tab: About -->
                <div id="tab-about" class="tab-content <?= $activeTab == 'about' ? '' : 'hidden' ?> bg-white rounded-xl shadow-sm p-6">
                    <h3 class="font-bold text-xl mb-6">À propos</h3>
                    <div class="space-y-6">
                        <div>
                            <h4 class="font-semibold text-gray-700 mb-2">Informations de contact</h4>
                            <div class="space-y-2 text-sm">
                                <?php if (!empty($user['telephone'])): ?>
                                    <p><i class="fas fa-phone text-gray-400 w-6"></i> <?= htmlspecialchars($user['telephone']) ?></p>
                                <?php endif; ?>
                                <p><i class="fas fa-envelope text-gray-400 w-6"></i> <?= htmlspecialchars($user['email']) ?></p>
                            </div>
                        </div>
                        <div>
                            <h4 class="font-semibold text-gray-700 mb-2">Informations académiques</h4>
                            <div class="space-y-2 text-sm">
                                <?php if (!empty($user['universite'])): ?>
                                    <p><i class="fas fa-university text-gray-400 w-6"></i> <?= htmlspecialchars($user['universite']) ?></p>
                                <?php endif; ?>
                                <?php if (!empty($user['faculte'])): ?>
                                    <p><i class="fas fa-building text-gray-400 w-6"></i> <?= htmlspecialchars($user['faculte']) ?></p>
                                <?php endif; ?>
                                <?php if (!empty($user['niveau_etude'])): ?>
                                    <p><i class="fas fa-graduation-cap text-gray-400 w-6"></i> <?= htmlspecialchars($user['niveau_etude']) ?></p>
                                <?php endif; ?>
                                <p><i class="fas fa-user-tag text-gray-400 w-6"></i> Rôle: <?= htmlspecialchars($user['role'] ?? 'étudiant') ?></p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Edit Bio Modal -->
    <div id="bioModal" class="fixed inset-0 bg-black/50 backdrop-blur-sm z-50 hidden items-center justify-center p-4">
        <div class="bg-white rounded-2xl w-full max-w-lg shadow-2xl transform scale-95 opacity-0 transition-all duration-300" id="bioModalContent">
            <div class="p-4 border-b flex items-center justify-between">
                <h3 class="font-bold text-lg">Modifier la bio</h3>
                <button onclick="closeBioModal()" class="w-8 h-8 hover:bg-gray-100 rounded-full flex items-center justify-center">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="p-4">
                <textarea id="bioInput" class="w-full h-32 resize-none border rounded-xl p-3 focus:border-orange-500 focus:ring-2 focus:ring-orange-200 outline-none" placeholder="Décrivez-vous..."><?= htmlspecialchars($user['bio'] ?? '') ?></textarea>
                <p class="text-xs text-gray-500 mt-2"><span id="bioCount"><?= strlen($user['bio'] ?? '') ?></span>/500 caractères</p>
            </div>
            <div class="p-4 border-t flex justify-end gap-3">
                <button onclick="closeBioModal()" class="px-4 py-2 hover:bg-gray-100 rounded-lg transition-colors">Annuler</button>
                <button onclick="saveBio()" class="px-4 py-2 bg-orange-500 hover:bg-orange-600 text-white rounded-lg transition-colors">Enregistrer</button>
            </div>
        </div>
    </div>
    
    <script>
        const csrfToken = '<?= $csrfToken ?>';
        const currentUserId = <?= $userId ?>;
        const profileUserId = <?= $profileId ?>;
        const isOwnProfile = <?= $isOwnProfile ? 'true' : 'false' ?>;
        
        function switchTab(tabName) {
            document.querySelectorAll('.tab-content').forEach(tab => tab.classList.add('hidden'));
            document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('tab-active'));
            
            const targetTab = document.getElementById('tab-' + tabName);
            if (targetTab) targetTab.classList.remove('hidden');
            
            const targetBtn = document.querySelector(`[data-tab="${tabName}"]`);
            if (targetBtn) targetBtn.classList.add('tab-active');
            
            // Mettre à jour l'URL sans recharger
            const url = new URL(window.location.href);
            url.searchParams.set('tab', tabName);
            window.history.pushState({}, '', url);
        }
        
        // Bio editing
        function editBio() {
            const modal = document.getElementById('bioModal');
            const content = document.getElementById('bioModalContent');
            modal.classList.remove('hidden');
            modal.classList.add('flex');
            setTimeout(() => {
                content.classList.remove('scale-95', 'opacity-0');
                content.classList.add('scale-100', 'opacity-100');
            }, 10);
            updateBioCount();
        }
        
        function closeBioModal() {
            const modal = document.getElementById('bioModal');
            const content = document.getElementById('bioModalContent');
            content.classList.remove('scale-100', 'opacity-100');
            content.classList.add('scale-95', 'opacity-0');
            setTimeout(() => {
                modal.classList.add('hidden');
                modal.classList.remove('flex');
            }, 300);
        }
        
        function updateBioCount() {
            const input = document.getElementById('bioInput');
            if (input) {
                document.getElementById('bioCount').textContent = input.value.length;
            }
        }
        
        document.getElementById('bioInput')?.addEventListener('input', updateBioCount);
        
        async function saveBio() {
            const bio = document.getElementById('bioInput').value || '';
            const formData = new FormData();
            formData.append('action', 'update_bio');
            formData.append('bio', bio);
            formData.append('csrf_token', csrfToken);
            
            try {
                const response = await fetch('../api/users.php', {
                    method: 'POST',
                    body: formData
                });
                const data = await response.json();
                
                if (data.success) {
                    const bioText = document.querySelector('#tab-about .space-y-6');
                    if (bioText) {
                        location.reload();
                    }
                    closeBioModal();
                    showToast('Bio mise à jour !', 'success');
                } else {
                    alert(data.error || 'Erreur lors de la sauvegarde');
                }
            } catch (err) {
                console.error('Error:', err);
                alert('Erreur de connexion');
            }
        }
        
        // Cover photo update
        async function updateCover(input) {
            if (!input.files || !input.files[0]) return;
            
            const formData = new FormData();
            formData.append('action', 'update_cover');
            formData.append('cover', input.files[0]);
            formData.append('csrf_token', csrfToken);
            
            try {
                const response = await fetch('../api/users.php', {
                    method: 'POST',
                    body: formData
                });
                const data = await response.json();
                
                if (data.success) {
                    location.reload();
                } else {
                    alert(data.error || 'Erreur lors du téléchargement');
                }
            } catch (err) {
                console.error('Error:', err);
                alert('Erreur de connexion');
            }
        }
        
        // Send friend request
        async function sendFriendRequest(userId) {
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
                    location.reload();
                } else {
                    alert(data.error || 'Erreur lors de l\'envoi de la demande');
                }
            } catch (err) {
                console.error('Error:', err);
                alert('Erreur de connexion');
            }
        }
        
        function openEditModal() {
            window.location.href = 'parametres.php';
        }
        
        function showToast(message, type) {
            const container = document.getElementById('toastContainer') || (() => {
                const div = document.createElement('div');
                div.id = 'toastContainer';
                div.className = 'fixed bottom-4 right-4 z-50 space-y-2';
                document.body.appendChild(div);
                return div;
            })();
            
            const colors = {
                success: 'bg-green-500',
                error: 'bg-red-500',
                info: 'bg-blue-500'
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
        
        // Close modal on outside click
        document.getElementById('bioModal')?.addEventListener('click', function(e) {
            if (e.target === this) closeBioModal();
        });
        async function acceptFriendRequest(userId) {
    try {
        const formData = new FormData();
        formData.append('action', 'accept_request');
        formData.append('user_id', userId);
        formData.append('csrf_token', csrfToken);
        
        const response = await fetch('../api/friends.php', {
            method: 'POST',
            body: formData
        });
        const data = await response.json();
        
        if (data.success) {
            showToast('Demande d\'ami acceptée !', 'success');
            location.reload();
        } else {
            showToast(data.error || 'Erreur lors de l\'acceptation', 'error');
        }
    } catch (err) {
        console.error('Error:', err);
        showToast('Erreur de connexion', 'error');
    }
}

async function rejectFriendRequest(userId) {
    if (!confirm('Refuser cette demande d\'ami ?')) return;
    
    try {
        const formData = new FormData();
        formData.append('action', 'reject_request');
        formData.append('user_id', userId);
        formData.append('csrf_token', csrfToken);
        
        const response = await fetch('../api/friends.php', {
            method: 'POST',
            body: formData
        });
        const data = await response.json();
        
        if (data.success) {
            showToast('Demande refusée', 'info');
            location.reload();
        } else {
            showToast(data.error || 'Erreur lors du refus', 'error');
        }
    } catch (err) {
        console.error('Error:', err);
        showToast('Erreur de connexion', 'error');
    }
}

    </script>
</body>
</html>