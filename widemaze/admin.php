<?php
/**
 * WideMaze - Panel d'Administration
 * Gestion complète du réseau social académique
 * Accès réservé aux administrateurs
 */

require_once 'config.php';

// Vérification des droits administrateur
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: index.php');
    exit();
}

$userId = $_SESSION['user_id'];
$activeTab = $_GET['tab'] ?? 'dashboard';
$success = $_GET['success'] ?? '';
$error = $_GET['error'] ?? '';

// ============================================================
// TRAITEMENT DES ACTIONS ADMIN
// ============================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        header('Location: admin.php?error=csrf');
        exit();
    }
    
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'update_user_role':
            $targetUserId = intval($_POST['user_id'] ?? 0);
            $newRole = $_POST['role'] ?? 'etudiant';
            $validRoles = ['etudiant', 'professeur', 'admin'];
            
            if (in_array($newRole, $validRoles) && $targetUserId > 0 && $targetUserId != $userId) {
                try {
                    $stmt = $pdo->prepare("UPDATE utilisateurs SET role = ? WHERE id = ?");
                    $stmt->execute([$newRole, $targetUserId]);
                    log_activity($pdo, $userId, 'admin_role_change', ['target' => $targetUserId, 'role' => $newRole]);
                    header('Location: admin.php?tab=users&success=role_updated');
                    exit();
                } catch (PDOException $e) {
                    $error = 'db_error';
                }
            }
            break;
            
        case 'toggle_user_status':
            $targetUserId = intval($_POST['user_id'] ?? 0);
            $isActive = intval($_POST['is_active'] ?? 0);
            
            if ($targetUserId > 0 && $targetUserId != $userId) {
                try {
                    $stmt = $pdo->prepare("UPDATE utilisateurs SET is_active = ? WHERE id = ?");
                    $stmt->execute([$isActive, $targetUserId]);
                    log_activity($pdo, $userId, 'admin_user_status', ['target' => $targetUserId, 'active' => $isActive]);
                    header('Location: admin.php?tab=users&success=status_updated');
                    exit();
                } catch (PDOException $e) {
                    $error = 'db_error';
                }
            }
            break;
            
        case 'delete_user':
            $targetUserId = intval($_POST['user_id'] ?? 0);
            $confirm = $_POST['confirm'] ?? '';
            
            if ($confirm === 'DELETE' && $targetUserId > 0 && $targetUserId != $userId) {
                try {
                    // Supprimer les données associées
                    $pdo->prepare("DELETE FROM posts WHERE id_utilisateur = ?")->execute([$targetUserId]);
                    $pdo->prepare("DELETE FROM ami WHERE id = ? OR idami = ?")->execute([$targetUserId, $targetUserId]);
                    $pdo->prepare("DELETE FROM message WHERE id_expediteur = ? OR id_destinataire = ?")->execute([$targetUserId, $targetUserId]);
                    $pdo->prepare("DELETE FROM notifications WHERE user_id = ?")->execute([$targetUserId]);
                    $pdo->prepare("DELETE FROM communaute_membres WHERE id_utilisateur = ?")->execute([$targetUserId]);
                    
                    // Supprimer l'utilisateur
                    $stmt = $pdo->prepare("DELETE FROM utilisateurs WHERE id = ?");
                    $stmt->execute([$targetUserId]);
                    
                    log_activity($pdo, $userId, 'admin_user_delete', ['target' => $targetUserId]);
                    header('Location: admin.php?tab=users&success=user_deleted');
                    exit();
                } catch (PDOException $e) {
                    $error = 'db_error';
                }
            }
            break;
            
        case 'delete_post':
            $postId = intval($_POST['post_id'] ?? 0);
            
            try {
                // Récupérer l'image pour suppression
                $stmt = $pdo->prepare("SELECT image_post FROM posts WHERE idpost = ?");
                $stmt->execute([$postId]);
                $post = $stmt->fetch();
                
                if ($post && !empty($post['image_post']) && file_exists(POSTS_DIR . $post['image_post'])) {
                    unlink(POSTS_DIR . $post['image_post']);
                }
                
                $pdo->prepare("DELETE FROM postlike WHERE idpost = ?")->execute([$postId]);
                $pdo->prepare("DELETE FROM postcommentaire WHERE idpost = ?")->execute([$postId]);
                $pdo->prepare("DELETE FROM posts WHERE idpost = ?")->execute([$postId]);
                
                log_activity($pdo, $userId, 'admin_post_delete', ['post_id' => $postId]);
                header('Location: admin.php?tab=content&success=post_deleted');
                exit();
            } catch (PDOException $e) {
                $error = 'db_error';
            }
            break;
            
        case 'delete_community':
            $communityId = intval($_POST['community_id'] ?? 0);
            
            try {
                $pdo->prepare("DELETE FROM communaute_membres WHERE id_communaute = ?")->execute([$communityId]);
                $pdo->prepare("DELETE FROM communautes WHERE id_communaute = ?")->execute([$communityId]);
                
                log_activity($pdo, $userId, 'admin_community_delete', ['community_id' => $communityId]);
                header('Location: admin.php?tab=communities&success=community_deleted');
                exit();
            } catch (PDOException $e) {
                $error = 'db_error';
            }
            break;
            
        case 'clear_logs':
            try {
                $pdo->prepare("TRUNCATE TABLE activity_logs")->execute();
                log_activity($pdo, $userId, 'admin_clear_logs');
                header('Location: admin.php?tab=logs&success=logs_cleared');
                exit();
            } catch (PDOException $e) {
                $error = 'db_error';
            }
            break;
            
        case 'create_announcement':
            $title = trim($_POST['title'] ?? '');
            $content = trim($_POST['content'] ?? '');
            $target = $_POST['target'] ?? 'all';
            
            if (!empty($title) && !empty($content)) {
                try {
                    // Créer des notifications pour tous les utilisateurs
                    if ($target === 'all') {
                        $stmt = $pdo->prepare("INSERT INTO notifications (user_id, type, title, content, created_at, is_read) 
                                               SELECT id, 'announcement', ?, ?, NOW(), 0 FROM utilisateurs WHERE is_active = 1");
                        $stmt->execute([$title, $content]);
                    } else {
                        $stmt = $pdo->prepare("INSERT INTO notifications (user_id, type, title, content, created_at, is_read) 
                                               VALUES (?, 'announcement', ?, ?, NOW(), 0)");
                        $stmt->execute([$target, $title, $content]);
                    }
                    
                    log_activity($pdo, $userId, 'admin_announcement', ['title' => $title]);
                    header('Location: admin.php?tab=announcements&success=announcement_sent');
                    exit();
                } catch (PDOException $e) {
                    $error = 'db_error';
                }
            }
            break;
    }
}

// ============================================================
// RÉCUPÉRATION DES DONNÉES
// ============================================================

// Statistiques globales
$stats = [];
try {
    $stats['total_users'] = $pdo->query("SELECT COUNT(*) FROM utilisateurs")->fetchColumn();
    $stats['active_users'] = $pdo->query("SELECT COUNT(*) FROM utilisateurs WHERE is_active = 1")->fetchColumn();
    $stats['online_users'] = $pdo->query("SELECT COUNT(*) FROM utilisateurs WHERE status = 'Online'")->fetchColumn();
    $stats['total_posts'] = $pdo->query("SELECT COUNT(*) FROM posts")->fetchColumn();
    $stats['total_comments'] = $pdo->query("SELECT COUNT(*) FROM postcommentaire")->fetchColumn();
    $stats['total_likes'] = $pdo->query("SELECT COUNT(*) FROM postlike")->fetchColumn();
    $stats['total_friends'] = $pdo->query("SELECT COUNT(*) FROM ami WHERE accepterami = 1")->fetchColumn();
    $stats['total_communities'] = $pdo->query("SELECT COUNT(*) FROM communautes")->fetchColumn();
    $stats['total_messages'] = $pdo->query("SELECT COUNT(*) FROM message")->fetchColumn();
    $stats['unread_messages'] = $pdo->query("SELECT COUNT(*) FROM message WHERE lu = 0")->fetchColumn();
    $stats['total_notifications'] = $pdo->query("SELECT COUNT(*) FROM notifications")->fetchColumn();
    $stats['unread_notifications'] = $pdo->query("SELECT COUNT(*) FROM notifications WHERE is_read = 0")->fetchColumn();
    $stats['total_stories'] = $pdo->query("SELECT COUNT(*) FROM stories WHERE expires_at > NOW()")->fetchColumn();
    $stats['total_ressources'] = $pdo->query("SELECT COUNT(*) FROM ressources")->fetchColumn();
    $stats['total_universities'] = $pdo->query("SELECT COUNT(DISTINCT universite) FROM utilisateurs WHERE universite IS NOT NULL AND universite != ''")->fetchColumn();
    $stats['total_countries'] = $pdo->query("SELECT COUNT(DISTINCT nationalite) FROM utilisateurs WHERE nationalite IS NOT NULL AND nationalite != ''")->fetchColumn();
} catch (PDOException $e) {
    error_log("Erreur stats: " . $e->getMessage());
}

// Liste des utilisateurs (pour l'onglet users)
$users = [];
try {
    $stmt = $pdo->prepare("
        SELECT u.*, 
               (SELECT COUNT(*) FROM posts WHERE id_utilisateur = u.id) as posts_count,
               (SELECT COUNT(*) FROM ami WHERE (id = u.id OR idami = u.id) AND accepterami = 1) as friends_count
        FROM utilisateurs u
        ORDER BY u.id DESC
        LIMIT 50
    ");
    $stmt->execute();
    $users = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Erreur récupération utilisateurs: " . $e->getMessage());
}

// Liste des posts signalés
$reportedPosts = [];
try {
    $stmt = $pdo->prepare("
        SELECT p.*, u.surnom, u.avatar,
               (SELECT COUNT(*) FROM postlike WHERE idpost = p.idpost) as likes_count,
               (SELECT COUNT(*) FROM postcommentaire WHERE idpost = p.idpost) as comments_count
        FROM posts p
        JOIN utilisateurs u ON p.id_utilisateur = u.id
        WHERE p.is_reported = 1
        ORDER BY p.reported_at DESC
        LIMIT 50
    ");
    $stmt->execute();
    $reportedPosts = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Erreur récupération posts signalés: " . $e->getMessage());
}

// Liste des logs
$logs = [];
try {
    $stmt = $pdo->prepare("
        SELECT al.*, u.surnom
        FROM activity_logs al
        LEFT JOIN utilisateurs u ON al.user_id = u.id
        ORDER BY al.created_at DESC
        LIMIT 100
    ");
    $stmt->execute();
    $logs = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Erreur récupération logs: " . $e->getMessage());
}

$csrfToken = generate_csrf_token();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Administration - WideMaze</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: '#f59e0b',
                        secondary: '#1e293b',
                        admin: '#dc2626',
                    },
                    fontFamily: {
                        sans: ['Inter', 'sans-serif'],
                    }
                }
            }
        }
    </script>
    <style>
        .admin-sidebar a.active { background-color: #fef3c7; color: #f59e0b; border-right: 3px solid #f59e0b; }
        .stat-card:hover { transform: translateY(-2px); transition: all 0.2s; }
        .tab-content { animation: fadeIn 0.3s ease-out; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
    </style>
</head>
<body class="bg-gray-100">

    <!-- Navigation -->
    <nav class="fixed top-0 w-full bg-gradient-to-r from-red-600 to-orange-600 shadow-lg z-50">
        <div class="container mx-auto px-4 h-16 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 bg-white rounded-xl flex items-center justify-center">
                    <i class="fas fa-shield-alt text-orange-600 text-xl"></i>
                </div>
                <div>
                    <h1 class="text-xl font-bold text-white">Administration WideMaze</h1>
                    <p class="text-xs text-white/80">Panel de contrôle avancé</p>
                </div>
            </div>
            <div class="flex items-center gap-4">
                <div class="hidden md:flex items-center gap-2 px-4 py-2 bg-white/20 rounded-full">
                    <i class="fas fa-user-shield text-white"></i>
                    <span class="text-sm text-white font-medium"><?= htmlspecialchars($_SESSION['surnom'] ?? 'Admin') ?></span>
                </div>
                <a href="index.php" class="px-4 py-2 bg-white/20 hover:bg-white/30 text-white rounded-lg transition-colors">
                    <i class="fas fa-home mr-2"></i>Retour au site
                </a>
            </div>
        </div>
    </nav>

    <div class="container mx-auto pt-20 pb-8 px-4">
        <div class="flex flex-col lg:flex-row gap-6">
            
            <!-- Sidebar Administration -->
            <aside class="lg:w-72 flex-shrink-0">
                <div class="bg-white rounded-2xl shadow-sm overflow-hidden sticky top-20">
                    <div class="p-4 border-b border-gray-100 bg-gray-50">
                        <h2 class="font-bold text-gray-800">Menu d'administration</h2>
                    </div>
                    <nav class="admin-sidebar p-2">
                        <a href="?tab=dashboard" class="flex items-center gap-3 px-4 py-3 rounded-xl transition-colors <?= $activeTab === 'dashboard' ? 'active' : 'text-gray-600 hover:bg-gray-50' ?>">
                            <i class="fas fa-chart-line w-5"></i>
                            <span>Tableau de bord</span>
                        </a>
                        <a href="?tab=users" class="flex items-center gap-3 px-4 py-3 rounded-xl transition-colors <?= $activeTab === 'users' ? 'active' : 'text-gray-600 hover:bg-gray-50' ?>">
                            <i class="fas fa-users w-5"></i>
                            <span>Utilisateurs</span>
                        </a>
                        <a href="?tab=content" class="flex items-center gap-3 px-4 py-3 rounded-xl transition-colors <?= $activeTab === 'content' ? 'active' : 'text-gray-600 hover:bg-gray-50' ?>">
                            <i class="fas fa-newspaper w-5"></i>
                            <span>Contenu</span>
                        </a>
                        <a href="?tab=communities" class="flex items-center gap-3 px-4 py-3 rounded-xl transition-colors <?= $activeTab === 'communities' ? 'active' : 'text-gray-600 hover:bg-gray-50' ?>">
                            <i class="fas fa-university w-5"></i>
                            <span>Communautés</span>
                        </a>
                        <a href="?tab=reports" class="flex items-center gap-3 px-4 py-3 rounded-xl transition-colors <?= $activeTab === 'reports' ? 'active' : 'text-gray-600 hover:bg-gray-50' ?>">
                            <i class="fas fa-flag w-5"></i>
                            <span>Signalements</span>
                        </a>
                        <a href="?tab=announcements" class="flex items-center gap-3 px-4 py-3 rounded-xl transition-colors <?= $activeTab === 'announcements' ? 'active' : 'text-gray-600 hover:bg-gray-50' ?>">
                            <i class="fas fa-bullhorn w-5"></i>
                            <span>Annonces</span>
                        </a>
                        <a href="?tab=logs" class="flex items-center gap-3 px-4 py-3 rounded-xl transition-colors <?= $activeTab === 'logs' ? 'active' : 'text-gray-600 hover:bg-gray-50' ?>">
                            <i class="fas fa-history w-5"></i>
                            <span>Journaux</span>
                        </a>
                        <a href="?tab=settings" class="flex items-center gap-3 px-4 py-3 rounded-xl transition-colors <?= $activeTab === 'settings' ? 'active' : 'text-gray-600 hover:bg-gray-50' ?>">
                            <i class="fas fa-cog w-5"></i>
                            <span>Paramètres</span>
                        </a>
                    </nav>
                </div>
            </aside>
            
            <!-- Contenu principal -->
            <main class="flex-1">
                
                <!-- Messages de succès/erreur -->
                <?php if ($success): ?>
                <div class="bg-green-50 border-l-4 border-green-500 p-4 rounded-r-lg mb-6">
                    <div class="flex items-center gap-2">
                        <i class="fas fa-check-circle text-green-500"></i>
                        <span class="text-green-700"><?= getSuccessMessage($success) ?></span>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if ($error): ?>
                <div class="bg-red-50 border-l-4 border-red-500 p-4 rounded-r-lg mb-6">
                    <div class="flex items-center gap-2">
                        <i class="fas fa-exclamation-circle text-red-500"></i>
                        <span class="text-red-700"><?= getErrorMessage($error) ?></span>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- TAB: Dashboard -->
                <div id="tab-dashboard" class="tab-content <?= $activeTab === 'dashboard' ? '' : 'hidden' ?>">
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8">
                        <div class="bg-white rounded-2xl p-5 shadow-sm stat-card border-l-4 border-blue-500">
                            <div class="flex items-center justify-between mb-2">
                                <i class="fas fa-users text-2xl text-blue-500"></i>
                            </div>
                            <p class="text-2xl font-bold text-gray-800"><?= number_format($stats['total_users'] ?? 0) ?></p>
                            <p class="text-xs text-gray-500">Utilisateurs totaux</p>
                            <p class="text-xs text-green-600 mt-1">+<?= number_format($stats['active_users'] ?? 0) ?> actifs</p>
                        </div>
                        <div class="bg-white rounded-2xl p-5 shadow-sm stat-card border-l-4 border-green-500">
                            <div class="flex items-center justify-between mb-2">
                                <i class="fas fa-newspaper text-2xl text-green-500"></i>
                            </div>
                            <p class="text-2xl font-bold text-gray-800"><?= number_format($stats['total_posts'] ?? 0) ?></p>
                            <p class="text-xs text-gray-500">Publications</p>
                            <p class="text-xs text-gray-400 mt-1"><?= number_format($stats['total_comments'] ?? 0) ?> commentaires</p>
                        </div>
                        <div class="bg-white rounded-2xl p-5 shadow-sm stat-card border-l-4 border-purple-500">
                            <div class="flex items-center justify-between mb-2">
                                <i class="fas fa-university text-2xl text-purple-500"></i>
                            </div>
                            <p class="text-2xl font-bold text-gray-800"><?= number_format($stats['total_communities'] ?? 0) ?></p>
                            <p class="text-xs text-gray-500">Communautés</p>
                        </div>
                        <div class="bg-white rounded-2xl p-5 shadow-sm stat-card border-l-4 border-orange-500">
                            <div class="flex items-center justify-between mb-2">
                                <i class="fas fa-heart text-2xl text-orange-500"></i>
                            </div>
                            <p class="text-2xl font-bold text-gray-800"><?= number_format($stats['total_likes'] ?? 0) ?></p>
                            <p class="text-xs text-gray-500">Likes totaux</p>
                        </div>
                    </div>
                    
                    <div class="grid lg:grid-cols-2 gap-6">
                        <div class="bg-white rounded-2xl shadow-sm p-6">
                            <h3 class="font-bold text-gray-800 mb-4">Activité récente</h3>
                            <div class="space-y-3 max-h-96 overflow-y-auto">
                                <?php foreach (array_slice($logs, 0, 10) as $log): ?>
                                <div class="flex items-start gap-3 p-3 bg-gray-50 rounded-lg">
                                    <div class="w-8 h-8 bg-orange-100 rounded-full flex items-center justify-center flex-shrink-0">
                                        <i class="fas fa-circle text-orange-500 text-xs"></i>
                                    </div>
                                    <div class="flex-1">
                                        <p class="text-sm text-gray-800">
                                            <span class="font-semibold">@<?= htmlspecialchars($log['surnom'] ?? 'Système') ?></span>
                                            <span class="text-gray-600"><?= getActivityLabel($log['action'] ?? '') ?></span>
                                        </p>
                                        <p class="text-xs text-gray-400"><?= date('d/m/Y H:i', strtotime($log['created_at'])) ?></p>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="bg-white rounded-2xl shadow-sm p-6">
                            <h3 class="font-bold text-gray-800 mb-4">Statistiques globales</h3>
                            <canvas id="statsChart" height="200"></canvas>
                        </div>
                    </div>
                </div>
                
                <!-- TAB: Utilisateurs -->
                <div id="tab-users" class="tab-content <?= $activeTab === 'users' ? '' : 'hidden' ?>">
                    <div class="bg-white rounded-2xl shadow-sm overflow-hidden">
                        <div class="p-5 border-b border-gray-100 bg-gray-50 flex justify-between items-center">
                            <h3 class="font-bold text-gray-800">Gestion des utilisateurs</h3>
                            <div class="relative">
                                <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
                                <input type="text" id="userSearch" placeholder="Rechercher..." class="pl-9 pr-4 py-2 border rounded-lg text-sm focus:outline-none focus:border-orange-500">
                            </div>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="w-full">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="text-left p-3 text-xs font-semibold text-gray-600">Utilisateur</th>
                                        <th class="text-left p-3 text-xs font-semibold text-gray-600">Email</th>
                                        <th class="text-center p-3 text-xs font-semibold text-gray-600">Rôle</th>
                                        <th class="text-center p-3 text-xs font-semibold text-gray-600">Statut</th>
                                        <th class="text-center p-3 text-xs font-semibold text-gray-600">Publications</th>
                                        <th class="text-center p-3 text-xs font-semibold text-gray-600">Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="usersTableBody">
                                    <?php foreach ($users as $user): ?>
                                    <tr class="border-b hover:bg-gray-50 transition-colors">
                                        <td class="p-3">
                                            <div class="flex items-center gap-3">
                                                <img src="<?= AVATAR_URL . htmlspecialchars($user['avatar'] ?? 'default.jpg') ?>" class="w-10 h-10 rounded-full object-cover">
                                                <div>
                                                    <p class="font-medium text-gray-800"><?= htmlspecialchars($user['prenom'] . ' ' . $user['nom']) ?></p>
                                                    <p class="text-xs text-gray-500">@<?= htmlspecialchars($user['surnom']) ?></p>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="p-3 text-sm text-gray-600"><?= htmlspecialchars($user['email']) ?></td>
                                        <td class="p-3 text-center">
                                            <form method="POST" class="inline-block">
                                                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                                <input type="hidden" name="action" value="update_user_role">
                                                <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                                <select name="role" onchange="this.form.submit()" class="text-xs rounded-lg border-gray-200 focus:border-orange-500">
                                                    <option value="etudiant" <?= $user['role'] === 'etudiant' ? 'selected' : '' ?>>Étudiant</option>
                                                    <option value="professeur" <?= $user['role'] === 'professeur' ? 'selected' : '' ?>>Professeur</option>
                                                    <option value="admin" <?= $user['role'] === 'admin' ? 'selected' : '' ?>>Admin</option>
                                                </select>
                                            </form>
                                        </td>
                                        <td class="p-3 text-center">
                                            <form method="POST" class="inline-block">
                                                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                                <input type="hidden" name="action" value="toggle_user_status">
                                                <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                                <input type="hidden" name="is_active" value="<?= $user['is_active'] ? 0 : 1 ?>">
                                                <button type="submit" class="px-2 py-1 rounded-full text-xs font-medium <?= $user['is_active'] ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' ?>">
                                                    <?= $user['is_active'] ? 'Actif' : 'Suspendu' ?>
                                                </button>
                                            </form>
                                        </td>
                                        <td class="p-3 text-center text-sm text-gray-600"><?= $user['posts_count'] ?? 0 ?></td>
                                        <td class="p-3 text-center">
                                            <button onclick="confirmDeleteUser(<?= $user['id'] ?>, '<?= addslashes($user['surnom']) ?>')" 
                                                    class="text-red-500 hover:text-red-700 transition-colors">
                                                <i class="fas fa-trash-alt"></i>
                                            </button>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    
                    <!-- Modal suppression utilisateur -->
                    <div id="deleteUserModal" class="fixed inset-0 bg-black/50 backdrop-blur-sm z-50 hidden items-center justify-center p-4">
                        <div class="bg-white rounded-2xl w-full max-w-md shadow-2xl">
                            <div class="p-5 border-b border-gray-100">
                                <h3 class="text-xl font-bold text-red-600">Supprimer l'utilisateur</h3>
                            </div>
                            <div class="p-5">
                                <p class="text-gray-700 mb-4">Êtes-vous sûr de vouloir supprimer <strong id="deleteUserName"></strong> ?</p>
                                <p class="text-sm text-red-600 mb-4">Cette action est irréversible ! Toutes ses données seront supprimées.</p>
                                <form method="POST" id="deleteUserForm">
                                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                    <input type="hidden" name="action" value="delete_user">
                                    <input type="hidden" name="user_id" id="deleteUserId">
                                    <div class="mb-4">
                                        <label class="block text-sm font-medium text-gray-700 mb-2">Tapez "DELETE" pour confirmer</label>
                                        <input type="text" name="confirm" required pattern="DELETE" class="w-full px-4 py-2 border rounded-lg focus:border-red-500 focus:ring-red-100">
                                    </div>
                                    <div class="flex gap-3">
                                        <button type="button" onclick="closeDeleteUserModal()" class="flex-1 px-4 py-2 bg-gray-100 hover:bg-gray-200 rounded-lg transition-colors">Annuler</button>
                                        <button type="submit" class="flex-1 px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded-lg transition-colors">Supprimer</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- TAB: Contenu -->
                <div id="tab-content" class="tab-content <?= $activeTab === 'content' ? '' : 'hidden' ?>">
                    <div class="bg-white rounded-2xl shadow-sm p-6">
                        <h3 class="font-bold text-gray-800 mb-4">Dernières publications</h3>
                        <div class="space-y-4">
                            <?php
                            $allPosts = [];
                            try {
                                $stmt = $pdo->prepare("
                                    SELECT p.*, u.surnom, u.avatar, u.prenom, u.nom,
                                           (SELECT COUNT(*) FROM postlike WHERE idpost = p.idpost) as likes_count,
                                           (SELECT COUNT(*) FROM postcommentaire WHERE idpost = p.idpost) as comments_count
                                    FROM posts p
                                    JOIN utilisateurs u ON p.id_utilisateur = u.id
                                    ORDER BY p.idpost DESC
                                    LIMIT 30
                                ");
                                $stmt->execute();
                                $allPosts = $stmt->fetchAll();
                            } catch (PDOException $e) {
                                error_log("Erreur récupération posts: " . $e->getMessage());
                            }
                            ?>
                            <?php foreach ($allPosts as $post): ?>
                            <div class="border rounded-xl p-4 hover:shadow-md transition-all">
                                <div class="flex items-center gap-3 mb-3">
                                    <img src="<?= AVATAR_URL . htmlspecialchars($post['avatar'] ?? 'default.jpg') ?>" class="w-10 h-10 rounded-full">
                                    <div>
                                        <p class="font-semibold text-gray-800"><?= htmlspecialchars($post['prenom'] . ' ' . $post['nom']) ?></p>
                                        <p class="text-xs text-gray-400">@<?= htmlspecialchars($post['surnom']) ?> • <?= date('d/m/Y H:i', strtotime($post['date_publication'])) ?></p>
                                    </div>
                                    <div class="ml-auto flex gap-2">
                                        <span class="text-xs bg-gray-100 px-2 py-1 rounded-full"><i class="fas fa-heart text-red-500"></i> <?= $post['likes_count'] ?></span>
                                        <span class="text-xs bg-gray-100 px-2 py-1 rounded-full"><i class="fas fa-comment text-blue-500"></i> <?= $post['comments_count'] ?></span>
                                    </div>
                                </div>
                                <p class="text-gray-700 mb-3"><?= nl2br(htmlspecialchars($post['contenu'])) ?></p>
                                <?php if (!empty($post['image_post'])): ?>
                                <img src="uploads/posts/<?= htmlspecialchars($post['image_post']) ?>" class="rounded-lg max-h-64 object-cover mt-2">
                                <?php endif; ?>
                                <div class="mt-3 flex justify-end">
                                    <form method="POST" class="inline-block">
                                        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                        <input type="hidden" name="action" value="delete_post">
                                        <input type="hidden" name="post_id" value="<?= $post['idpost'] ?>">
                                        <button type="submit" onclick="return confirm('Supprimer cette publication ?')" 
                                                class="text-red-500 hover:text-red-700 text-sm">
                                            <i class="fas fa-trash-alt mr-1"></i>Supprimer
                                        </button>
                                    </form>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                
                <!-- TAB: Signalements -->
                <div id="tab-reports" class="tab-content <?= $activeTab === 'reports' ? '' : 'hidden' ?>">
                    <div class="bg-white rounded-2xl shadow-sm p-6">
                        <h3 class="font-bold text-gray-800 mb-4">Contenu signalé</h3>
                        <?php if (empty($reportedPosts)): ?>
                        <div class="text-center py-12 text-gray-400">
                            <i class="fas fa-flag-checkered text-4xl mb-3"></i>
                            <p>Aucun contenu signalé</p>
                        </div>
                        <?php else: ?>
                        <div class="space-y-4">
                            <?php foreach ($reportedPosts as $post): ?>
                            <div class="border border-red-200 rounded-xl p-4 bg-red-50">
                                <div class="flex items-center gap-3 mb-3">
                                    <img src="<?= AVATAR_URL . htmlspecialchars($post['avatar'] ?? 'default.jpg') ?>" class="w-10 h-10 rounded-full">
                                    <div>
                                        <p class="font-semibold text-gray-800"><?= htmlspecialchars($post['surnom']) ?></p>
                                        <p class="text-xs text-gray-500">Signalé le <?= date('d/m/Y H:i', strtotime($post['reported_at'] ?? $post['date_publication'])) ?></p>
                                    </div>
                                </div>
                                <p class="text-gray-700 mb-3"><?= nl2br(htmlspecialchars($post['contenu'])) ?></p>
                                <div class="flex gap-3 justify-end">
                                    <form method="POST" class="inline-block">
                                        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                        <input type="hidden" name="action" value="delete_post">
                                        <input type="hidden" name="post_id" value="<?= $post['idpost'] ?>">
                                        <button type="submit" class="bg-red-500 text-white px-4 py-2 rounded-lg hover:bg-red-600 text-sm">
                                            <i class="fas fa-trash-alt mr-1"></i>Supprimer
                                        </button>
                                    </form>
                                    <form method="POST" class="inline-block">
                                        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                        <input type="hidden" name="action" value="dismiss_report">
                                        <input type="hidden" name="post_id" value="<?= $post['idpost'] ?>">
                                        <button type="submit" class="bg-gray-200 text-gray-700 px-4 py-2 rounded-lg hover:bg-gray-300 text-sm">
                                            <i class="fas fa-check mr-1"></i>Ignorer
                                        </button>
                                    </form>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- TAB: Annonces -->
                <div id="tab-announcements" class="tab-content <?= $activeTab === 'announcements' ? '' : 'hidden' ?>">
                    <div class="bg-white rounded-2xl shadow-sm p-6">
                        <h3 class="font-bold text-gray-800 mb-4">Envoyer une annonce</h3>
                        <form method="POST" class="space-y-4">
                            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                            <input type="hidden" name="action" value="create_announcement">
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Titre</label>
                                <input type="text" name="title" required class="w-full px-4 py-2 border rounded-lg focus:border-orange-500 focus:ring-orange-100">
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Message</label>
                                <textarea name="content" rows="5" required class="w-full px-4 py-2 border rounded-lg focus:border-orange-500 focus:ring-orange-100 resize-none"></textarea>
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Destinataires</label>
                                <select name="target" class="w-full px-4 py-2 border rounded-lg focus:border-orange-500">
                                    <option value="all">Tous les utilisateurs</option>
                                    <option value="etudiant">Étudiants uniquement</option>
                                    <option value="professeur">Professeurs uniquement</option>
                                    <option value="admin">Administrateurs uniquement</option>
                                </select>
                            </div>
                            
                            <button type="submit" class="bg-orange-500 text-white px-6 py-2 rounded-lg hover:bg-orange-600 transition-colors">
                                <i class="fas fa-paper-plane mr-2"></i>Envoyer l'annonce
                            </button>
                        </form>
                    </div>
                </div>
                
                <!-- TAB: Logs -->
                <div id="tab-logs" class="tab-content <?= $activeTab === 'logs' ? '' : 'hidden' ?>">
                    <div class="bg-white rounded-2xl shadow-sm overflow-hidden">
                        <div class="p-5 border-b border-gray-100 bg-gray-50 flex justify-between items-center">
                            <h3 class="font-bold text-gray-800">Journal d'activité</h3>
                            <form method="POST">
                                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                <input type="hidden" name="action" value="clear_logs">
                                <button type="submit" onclick="return confirm('Vider tous les logs ?')" class="text-red-500 hover:text-red-700 text-sm">
                                    <i class="fas fa-trash-alt mr-1"></i>Vider les logs
                                </button>
                            </form>
                        </div>
                        <div class="p-4 max-h-[600px] overflow-y-auto">
                            <div class="space-y-2">
                                <?php foreach ($logs as $log): ?>
                                <div class="flex items-start gap-3 p-3 bg-gray-50 rounded-lg">
                                    <div class="w-8 h-8 bg-gray-200 rounded-full flex items-center justify-center flex-shrink-0">
                                        <i class="fas fa-<?= getActivityIcon($log['action'] ?? '') ?> text-gray-500 text-xs"></i>
                                    </div>
                                    <div class="flex-1">
                                        <p class="text-sm">
                                            <span class="font-semibold">@<?= htmlspecialchars($log['surnom'] ?? 'Système') ?></span>
                                            <span class="text-gray-600"><?= getActivityLabel($log['action'] ?? '') ?></span>
                                        </p>
                                        <p class="text-xs text-gray-400"><?= date('d/m/Y H:i:s', strtotime($log['created_at'])) ?></p>
                                        <?php if (!empty($log['details'])): ?>
                                        <p class="text-xs text-gray-500 mt-1 font-mono"><?= htmlspecialchars($log['details']) ?></p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- TAB: Paramètres -->
                <div id="tab-settings" class="tab-content <?= $activeTab === 'settings' ? '' : 'hidden' ?>">
                    <div class="bg-white rounded-2xl shadow-sm p-6">
                        <h3 class="font-bold text-gray-800 mb-4">Paramètres système</h3>
                        <div class="space-y-4">
                            <div class="p-4 bg-gray-50 rounded-lg">
                                <h4 class="font-semibold text-gray-700 mb-2">Informations serveur</h4>
                                <ul class="text-sm text-gray-600 space-y-1">
                                    <li><strong>PHP Version :</strong> <?= phpversion() ?></li>
                                    <li><strong>MySQL Version :</strong> <?= $pdo->getAttribute(PDO::ATTR_SERVER_VERSION) ?></li>
                                    <li><strong>Serveur :</strong> <?= $_SERVER['SERVER_SOFTWARE'] ?></li>
                                    <li><strong>Date/Heure :</strong> <?= date('d/m/Y H:i:s') ?></li>
                                </ul>
                            </div>
                            <div class="p-4 bg-gray-50 rounded-lg">
                                <h4 class="font-semibold text-gray-700 mb-2">Cache</h4>
                                <button onclick="clearCache()" class="bg-gray-200 text-gray-700 px-4 py-2 rounded-lg hover:bg-gray-300 transition-colors">
                                    <i class="fas fa-broom mr-2"></i>Vider le cache
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                
            </main>
        </div>
    </div>
    
    <script>
        // Graphique
        const ctx = document.getElementById('statsChart');
        if (ctx) {
            new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: ['Utilisateurs', 'Publications', 'Communautés', 'Messages', 'Likes'],
                    datasets: [{
                        label: 'Statistiques',
                        data: [
                            <?= $stats['total_users'] ?? 0 ?>,
                            <?= $stats['total_posts'] ?? 0 ?>,
                            <?= $stats['total_communities'] ?? 0 ?>,
                            <?= $stats['total_messages'] ?? 0 ?>,
                            <?= $stats['total_likes'] ?? 0 ?>
                        ],
                        backgroundColor: 'rgba(245, 158, 11, 0.6)',
                        borderColor: '#f59e0b',
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    plugins: { legend: { display: false } }
                }
            });
        }
        
        // Recherche utilisateurs
        const userSearch = document.getElementById('userSearch');
        if (userSearch) {
            userSearch.addEventListener('input', function() {
                const searchTerm = this.value.toLowerCase();
                const rows = document.querySelectorAll('#usersTableBody tr');
                rows.forEach(row => {
                    const text = row.textContent.toLowerCase();
                    row.style.display = text.includes(searchTerm) ? '' : 'none';
                });
            });
        }
        
        // Suppression utilisateur
        let deleteUserId = null;
        let deleteUserName = null;
        
        function confirmDeleteUser(id, name) {
            deleteUserId = id;
            deleteUserName = name;
            document.getElementById('deleteUserName').textContent = name;
            document.getElementById('deleteUserId').value = id;
            document.getElementById('deleteUserModal').classList.remove('hidden');
            document.getElementById('deleteUserModal').classList.add('flex');
        }
        
        function closeDeleteUserModal() {
            document.getElementById('deleteUserModal').classList.remove('flex');
            document.getElementById('deleteUserModal').classList.add('hidden');
        }
        
        // Vider le cache
        function clearCache() {
            if (confirm('Vider le cache du navigateur ?')) {
                localStorage.clear();
                sessionStorage.clear();
                alert('Cache vidé !');
            }
        }
        
        // Fermer les modals en cliquant à l'extérieur
        document.getElementById('deleteUserModal')?.addEventListener('click', function(e) {
            if (e.target === this) closeDeleteUserModal();
        });
    </script>
</body>
</html>

<?php
// Fonctions utilitaires
function getSuccessMessage($code) {
    $messages = [
        'role_updated' => 'Rôle de l\'utilisateur mis à jour',
        'status_updated' => 'Statut de l\'utilisateur modifié',
        'user_deleted' => 'Utilisateur supprimé avec succès',
        'post_deleted' => 'Publication supprimée',
        'community_deleted' => 'Communauté supprimée',
        'logs_cleared' => 'Journaux d\'activité vidés',
        'announcement_sent' => 'Annonce envoyée avec succès',
    ];
    return $messages[$code] ?? 'Action effectuée avec succès';
}

function getErrorMessage($code) {
    $messages = [
        'csrf' => 'Erreur de sécurité, veuillez réessayer',
        'db_error' => 'Erreur de base de données',
        'user_not_found' => 'Utilisateur non trouvé',
    ];
    return $messages[$code] ?? 'Une erreur est survenue';
}

function getActivityIcon($action) {
    $icons = [
        'login_success' => 'sign-in-alt', 'login_failed' => 'exclamation-triangle',
        'register' => 'user-plus', 'logout' => 'sign-out-alt',
        'post_created' => 'plus-circle', 'post_deleted' => 'trash',
        'friend_request_sent' => 'user-friends', 'friend_request_accepted' => 'check',
        'admin_role_change' => 'user-cog', 'admin_user_delete' => 'user-slash',
        'admin_post_delete' => 'trash-alt', 'admin_community_delete' => 'university',
        'admin_announcement' => 'bullhorn', 'search' => 'search'
    ];
    return $icons[$action] ?? 'circle';
}

function getActivityLabel($action) {
    $labels = [
        'login_success' => 's\'est connecté',
        'login_failed' => 'a échoué à se connecter',
        'register' => 'a créé un compte',
        'logout' => 's\'est déconnecté',
        'post_created' => 'a créé une publication',
        'post_deleted' => 'a supprimé une publication',
        'friend_request_sent' => 'a envoyé une demande d\'ami',
        'friend_request_accepted' => 'a accepté une demande d\'ami',
        'admin_role_change' => 'a modifié un rôle utilisateur',
        'admin_user_delete' => 'a supprimé un utilisateur',
        'admin_post_delete' => 'a supprimé une publication',
        'admin_community_delete' => 'a supprimé une communauté',
        'admin_announcement' => 'a envoyé une annonce',
        'search' => 'a effectué une recherche'
    ];
    return $labels[$action] ?? $action;
}
?>