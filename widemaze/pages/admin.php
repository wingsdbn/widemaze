<?php
/**
 * WideMaze - Panel d'Administration
 * Version 5.0 - Dashboard complet avec graphiques, logs, gestion utilisateurs
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

// Vérification des droits administrateur
require_admin();

$userId = $_SESSION['user_id'];
$activeTab = $_GET['tab'] ?? 'dashboard';
$success = $_GET['success'] ?? '';
$error = $_GET['error'] ?? '';
// Forcer l'initialisation des variables
$success = $success ?? '';
$error = $error ?? '';
if (!isset($success)) $success = '';
if (!isset($error)) $error = '';

// ==================== TRAITEMENT DES ACTIONS ADMIN ====================

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
            
            if ($confirm == 'DELETE' && $targetUserId > 0 && $targetUserId != $userId) {
                try {
                    // Supprimer les données associées
                    $pdo->prepare("DELETE FROM posts WHERE id_utilisateur = ?")->execute([$targetUserId]);
                    $pdo->prepare("DELETE FROM ami WHERE id = ? OR idami = ?")->execute([$targetUserId, $targetUserId]);
                    $pdo->prepare("DELETE FROM message WHERE id_expediteur = ? OR id_destinataire = ?")->execute([$targetUserId, $targetUserId]);
                    $pdo->prepare("DELETE FROM notifications WHERE user_id = ?")->execute([$targetUserId]);
                    $pdo->prepare("DELETE FROM communaute_membres WHERE id_utilisateur = ?")->execute([$targetUserId]);
                    $pdo->prepare("DELETE FROM utilisateurs WHERE id = ?")->execute([$targetUserId]);
                    
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
                $pdo->prepare("DELETE FROM post_reports WHERE post_id = ?")->execute([$postId]);
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

            case 'dismiss_report':
                $postId = intval($_POST['post_id'] ?? 0);
                try {
                    $stmt = $pdo->prepare("
                        UPDATE post_reports 
                        SET status = 'dismissed', reviewed_at = NOW(), reviewed_by = ? 
                        WHERE post_id = ? AND status = 'pending'
                    ");
                    $stmt->execute([$userId, $postId]);
                    
                    // Vérifier s'il reste des signalements en attente
                    $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM post_reports WHERE post_id = ? AND status = 'pending'");
                    $checkStmt->execute([$postId]);
                    if ($checkStmt->fetchColumn() == 0) {
                        $pdo->prepare("UPDATE posts SET is_reported = 0, reported_at = NULL WHERE idpost = ?")->execute([$postId]);
                    }
                    
                    log_activity($pdo, $userId, 'admin_report_dismissed', ['post_id' => $postId]);
                    header('Location: admin.php?tab=reports&success=report_dismissed');
                    exit();
                } catch (PDOException $e) {
                    error_log("Error dismissing report: " . $e->getMessage());
                    header('Location: admin.php?tab=reports&error=db_error');
                    exit();
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
                    if ($target == 'all') {
                        $stmt = $pdo->prepare("
                            INSERT INTO notifications (user_id, type, title, content, created_at, is_read) 
                            SELECT id, 'announcement', ?, ?, NOW(), 0 
                            FROM utilisateurs WHERE is_active = 1
                        ");
                        $stmt->execute([$title, $content]);
                    } else {
                        $stmt = $pdo->prepare("
                            INSERT INTO notifications (user_id, type, title, content, created_at, is_read) 
                            VALUES (?, 'announcement', ?, ?, NOW(), 0)
                        ");
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

// ==================== RÉCUPÉRATION DES DONNÉES ====================

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
    $stats['total_resources'] = $pdo->query("SELECT COUNT(*) FROM ressources")->fetchColumn();
    $stats['total_universities'] = $pdo->query("SELECT COUNT(DISTINCT universite) FROM utilisateurs WHERE universite IS NOT NULL AND universite != ''")->fetchColumn();
    $stats['total_countries'] = $pdo->query("SELECT COUNT(DISTINCT nationalite) FROM utilisateurs WHERE nationalite IS NOT NULL AND nationalite != ''")->fetchColumn();
    
    // Statistiques par jour (derniers 7 jours)
    $stats['daily_users'] = [];
    $stmt = $pdo->query("
        SELECT DATE(dateinscription) as date, COUNT(*) as count 
        FROM utilisateurs 
        WHERE dateinscription > DATE_SUB(NOW(), INTERVAL 7 DAY)
        GROUP BY DATE(dateinscription)
        ORDER BY date
    ");
    $stats['daily_users'] = $stmt->fetchAll();
    
    $stats['daily_posts'] = [];
    $stmt = $pdo->query("
        SELECT DATE(date_publication) as date, COUNT(*) as count 
        FROM posts 
        WHERE date_publication > DATE_SUB(NOW(), INTERVAL 7 DAY)
        GROUP BY DATE(date_publication)
        ORDER BY date
    ");
    $stats['daily_posts'] = $stmt->fetchAll();
    
} catch (PDOException $e) {
    error_log("Error fetching stats: " . $e->getMessage());
}

// Liste des utilisateurs
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
    error_log("Error fetching users: " . $e->getMessage());
}

// Liste des posts récents
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
    error_log("Error fetching posts: " . $e->getMessage());
}

// Liste des signalements via l'API
// Liste des signalements
$reportedPosts = [];
$apiResponse = call_api('post_reports.php?action=list&status=pending&limit=50');
if ($apiResponse && $apiResponse['success']) {
    $reportedPosts = $apiResponse['reports'];
} else {
    // Fallback direct en base
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
        error_log("Error fetching reported posts: " . $e->getMessage());
    }
}
//Messages de succès
if (isset($success) && $success): ?>
    <div class="m-6 bg-green-50 border-l-4 border-green-500 p-4 rounded-r-lg">
        <div class="flex items-center gap-2">
            <i class="fas fa-check-circle text-green-500"></i>
            <span class="text-green-700">
                <?php
                    $messages = [
                        'general' => 'Profil mis à jour avec succès',
                        'avatar' => 'Photo de profil mise à jour',
                        'avatar_reset' => 'Photo de profil réinitialisée',
                        'password' => 'Mot de passe modifié avec succès',
                        'preferences' => 'Préférences enregistrées',
                        'role_updated' => 'Rôle utilisateur mis à jour',
                        'status_updated' => 'Statut utilisateur modifié',
                        'user_deleted' => 'Utilisateur supprimé',
                        'post_deleted' => 'Publication supprimée',
                        'community_deleted' => 'Communauté supprimée',
                        'logs_cleared' => 'Logs vidés',
                        'announcement_sent' => 'Annonce envoyée',
                        'report_dismissed' => 'Signalement ignoré'
                    ];
                    echo $messages[$success] ?? 'Action effectuée avec succès';
                ?>
            </span>
        </div>
    </div>
<?php endif; 
//Messages d'erreur
if (isset($error) && $error): ?>
    <div class="m-6 bg-red-50 border-l-4 border-red-500 p-4 rounded-r-lg">
        <div class="flex items-center gap-2">
            <i class="fas fa-exclamation-circle text-red-500"></i>
            <span class="text-red-700">
                <?php
                    $errorMessages = [
                        'csrf' => 'Erreur de sécurité, veuillez réessayer',
                        'db_error' => 'Erreur de base de données',
                        'validation' => 'Erreur de validation des données',
                        'surnom_taken' => 'Ce surnom est déjà utilisé',
                        'current_password' => 'Mot de passe actuel incorrect',
                        'weak_password' => 'Le mot de passe est trop faible',
                        'match' => 'Les mots de passe ne correspondent pas',
                        'confirm' => 'Vous devez taper "DELETE" pour confirmer',
                        'password' => 'Mot de passe incorrect',
                        'role_invalid' => 'Rôle invalide',
                        'user_not_found' => 'Utilisateur non trouvé'
                    ];
                    echo $errorMessages[$error] ?? 'Une erreur est survenue';
                ?>
            </span>
        </div>
    </div>
<?php endif; 
// Liste des communautés
$communities = [];
try {
    $stmt = $pdo->prepare("
        SELECT c.*, u.surnom as creator_name,
            (SELECT COUNT(*) FROM communaute_membres WHERE id_communaute = c.id_communaute) as member_count
        FROM communautes c
        JOIN utilisateurs u ON c.id_createur = u.id
        ORDER BY c.id_communaute DESC
        LIMIT 30
    ");
    $stmt->execute();
    $communities = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Error fetching communities: " . $e->getMessage());
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
    error_log("Error fetching logs: " . $e->getMessage());
}

$csrfToken = generate_csrf_token();
$page_title = 'Administration';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Administration - WideMaze</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        * { font-family: 'Inter', sans-serif; }
        body { background: linear-gradient(135deg, #f5f7fa 0%, #eef2f7 100%); min-height: 100vh; }
        
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .stat-card {
            transition: all 0.3s ease;
        }
        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 20px 25px -12px rgba(0,0,0,0.1);
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
        .toast {
            animation: fadeInUp 0.3s ease-out;
        }
    </style>
</head>
<body class="min-h-screen">
    
    <!-- Navigation -->
    <nav class="fixed top-0 w-full bg-white/95 backdrop-blur-md shadow-lg z-50 border-b border-gray-100">
        <div class="container mx-auto px-4 h-16 flex items-center justify-between">
            <a href="../index.php" class="flex items-center gap-2 group">
                <div class="w-10 h-10 bg-gradient-to-br from-orange-500 to-red-600 rounded-xl flex items-center justify-center shadow-lg group-hover:scale-110 transition-transform">
                    <i class="fas fa-shield-alt text-white"></i>
                </div>
                <span class="text-2xl font-bold bg-gradient-to-r from-orange-500 to-red-600 bg-clip-text text-transparent hidden sm:block">Administration</span>
            </a>
            <div class="flex items-center gap-4">
                <a href="../index.php" class="p-2 hover:bg-gray-100 rounded-full transition-colors">
                    <i class="fas fa-home text-xl text-gray-600"></i>
                </a>
                <a href="notifications.php" class="p-2 hover:bg-gray-100 rounded-full transition-colors">
                    <i class="fas fa-bell text-xl text-gray-600"></i>
                </a>
                <a href="../wcc.php" class="p-2 hover:bg-gray-100 rounded-full transition-colors group relative" title="WideMaze Control Center">
                    <i class="fas fa-brain text-xl text-gray-600 group-hover:text-orange-500 transition-colors"></i>
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
    
    <div class="container mx-auto pt-20 pb-8 px-4 max-w-7xl">
        
        <!-- Header -->
        <div class="mb-8">
            <h1 class="text-3xl md:text-4xl font-bold text-gray-800 flex items-center gap-3">
                <i class="fas fa-shield-alt text-orange-500"></i>
                Panel d'Administration
                <span class="text-sm bg-orange-100 text-orange-600 px-3 py-1 rounded-full">Super Admin</span>
            </h1>
            <p class="text-gray-500 mt-2">Gestion complète du réseau social académique WideMaze</p>
        </div>
        
        <!-- KPI Cards -->
        <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-7 gap-4 mb-8">
            <div class="stat-card bg-white rounded-2xl p-4 shadow-sm border border-gray-100">
                <div class="flex items-center justify-between mb-2">
                    <i class="fas fa-users text-2xl text-blue-500"></i>
                </div>
                <p class="text-2xl font-bold text-gray-800"><?= number_format($stats['total_users'] ?? 0) ?></p>
                <p class="text-xs text-gray-500">Utilisateurs</p>
                <p class="text-xs text-green-600 mt-1">+<?= number_format($stats['active_users'] ?? 0) ?> actifs</p>
            </div>
            <div class="stat-card bg-white rounded-2xl p-4 shadow-sm border border-gray-100">
                <div class="flex items-center justify-between mb-2">
                    <i class="fas fa-circle text-2xl text-green-500"></i>
                </div>
                <p class="text-2xl font-bold text-gray-800"><?= number_format($stats['online_users'] ?? 0) ?></p>
                <p class="text-xs text-gray-500">En ligne</p>
            </div>
            <div class="stat-card bg-white rounded-2xl p-4 shadow-sm border border-gray-100">
                <div class="flex items-center justify-between mb-2">
                    <i class="fas fa-newspaper text-2xl text-purple-500"></i>
                </div>
                <p class="text-2xl font-bold text-gray-800"><?= number_format($stats['total_posts'] ?? 0) ?></p>
                <p class="text-xs text-gray-500">Publications</p>
            </div>
            <div class="stat-card bg-white rounded-2xl p-4 shadow-sm border border-gray-100">
                <div class="flex items-center justify-between mb-2">
                    <i class="fas fa-heart text-2xl text-red-500"></i>
                </div>
                <p class="text-2xl font-bold text-gray-800"><?= number_format($stats['total_likes'] ?? 0) ?></p>
                <p class="text-xs text-gray-500">Likes</p>
            </div>
            <div class="stat-card bg-white rounded-2xl p-4 shadow-sm border border-gray-100">
                <div class="flex items-center justify-between mb-2">
                    <i class="fas fa-university text-2xl text-orange-500"></i>
                </div>
                <p class="text-2xl font-bold text-gray-800"><?= number_format($stats['total_communities'] ?? 0) ?></p>
                <p class="text-xs text-gray-500">Communautés</p>
            </div>
            <div class="stat-card bg-white rounded-2xl p-4 shadow-sm border border-gray-100">
                <div class="flex items-center justify-between mb-2">
                    <i class="fas fa-graduation-cap text-2xl text-indigo-500"></i>
                </div>
                <p class="text-2xl font-bold text-gray-800"><?= number_format($stats['total_universities'] ?? 0) ?></p>
                <p class="text-xs text-gray-500">Universités</p>
            </div>
            <div class="stat-card bg-gradient-to-r from-orange-500 to-red-500 rounded-2xl p-4 shadow-sm">
                <div class="flex items-center justify-between mb-2">
                    <i class="fas fa-globe text-2xl text-white"></i>
                </div>
                <p class="text-2xl font-bold text-white"><?= number_format($stats['total_countries'] ?? 0) ?></p>
                <p class="text-xs text-white/80">Pays</p>
            </div>
        </div>
        
        <!-- Graphique d'activité -->
        <div class="bg-white rounded-2xl shadow-sm p-6 mb-8 border border-gray-100">
            <h3 class="font-bold text-gray-800 mb-4 flex items-center gap-2">
                <i class="fas fa-chart-line text-orange-500"></i>Activité des 7 derniers jours
            </h3>
            <canvas id="activityChart" height="100"></canvas>
        </div>
        
        <!-- Tabs Navigation -->
        <div class="bg-white rounded-t-2xl shadow-sm border-b overflow-x-auto">
            <div class="flex">
                <button onclick="switchTab('dashboard')" class="tab-btn px-6 py-4 font-medium text-sm whitespace-nowrap transition-colors hover:bg-gray-50 <?= $activeTab == 'dashboard' ? 'tab-active text-orange-600' : 'text-gray-600' ?>" data-tab="dashboard">
                    <i class="fas fa-chart-line mr-2"></i>Dashboard
                </button>
                <button onclick="switchTab('users')" class="tab-btn px-6 py-4 font-medium text-sm whitespace-nowrap transition-colors hover:bg-gray-50 <?= $activeTab == 'users' ? 'tab-active text-orange-600' : 'text-gray-600' ?>" data-tab="users">
                    <i class="fas fa-users mr-2"></i>Utilisateurs
                </button>
                <button onclick="switchTab('content')" class="tab-btn px-6 py-4 font-medium text-sm whitespace-nowrap transition-colors hover:bg-gray-50 <?= $activeTab == 'content' ? 'tab-active text-orange-600' : 'text-gray-600' ?>" data-tab="content">
                    <i class="fas fa-newspaper mr-2"></i>Contenu
                </button>
                <button onclick="switchTab('communities')" class="tab-btn px-6 py-4 font-medium text-sm whitespace-nowrap transition-colors hover:bg-gray-50 <?= $activeTab == 'communities' ? 'tab-active text-orange-600' : 'text-gray-600' ?>" data-tab="communities">
                    <i class="fas fa-university mr-2"></i>Communautés
                </button>
                <button onclick="switchTab('reports')" class="tab-btn px-6 py-4 font-medium text-sm whitespace-nowrap transition-colors hover:bg-gray-50 <?= $activeTab == 'reports' ? 'tab-active text-orange-600' : 'text-gray-600' ?>" data-tab="reports">
                    <i class="fas fa-flag mr-2"></i>Signalements
                    <?php if (count($reportedPosts) > 0): ?>
                        <span class="ml-1 px-1.5 py-0.5 bg-red-500 text-white text-xs rounded-full"><?= count($reportedPosts) ?></span>
                    <?php endif; ?>
                </button>
                <button onclick="switchTab('announcements')" class="tab-btn px-6 py-4 font-medium text-sm whitespace-nowrap transition-colors hover:bg-gray-50 <?= $activeTab == 'announcements' ? 'tab-active text-orange-600' : 'text-gray-600' ?>" data-tab="announcements">
                    <i class="fas fa-bullhorn mr-2"></i>Annonces
                </button>
                <button onclick="switchTab('logs')" class="tab-btn px-6 py-4 font-medium text-sm whitespace-nowrap transition-colors hover:bg-gray-50 <?= $activeTab == 'logs' ? 'tab-active text-orange-600' : 'text-gray-600' ?>" data-tab="logs">
                    <i class="fas fa-history mr-2"></i>Journaux
                </button>
                <button onclick="switchTab('settings')" class="tab-btn px-6 py-4 font-medium text-sm whitespace-nowrap transition-colors hover:bg-gray-50 <?= $activeTab == 'settings' ? 'tab-active text-orange-600' : 'text-gray-600' ?>" data-tab="settings">
                    <i class="fas fa-cog mr-2"></i>Paramètres
                </button>
                <a href="../wcc.php" class="tab-btn px-6 py-4 font-medium text-sm whitespace-nowrap transition-colors hover:bg-gray-50 text-orange-500 hover:text-orange-600 flex items-center gap-2 ml-auto">
                    <i class="fas fa-brain"></i>WCC
                </a>
            </div>
        </div>
        
        <!-- Tab Content -->
        <div class="bg-white rounded-b-2xl shadow-sm p-6 min-h-[500px]">
            
            <!-- TAB: Dashboard -->
            <div id="tab-dashboard" class="tab-content <?= $activeTab == 'dashboard' ? '' : 'hidden' ?>">
                <div class="grid md:grid-cols-2 gap-6">
                    <div>
                        <h3 class="font-bold text-gray-800 mb-4">Statistiques détaillées</h3>
                        <div class="space-y-3">
                            <div class="flex justify-between items-center p-3 bg-gray-50 rounded-lg">
                                <span class="text-gray-600">Commentaires</span>
                                <span class="font-bold text-gray-800"><?= number_format($stats['total_comments'] ?? 0) ?></span>
                            </div>
                            <div class="flex justify-between items-center p-3 bg-gray-50 rounded-lg">
                                <span class="text-gray-600">Messages privés</span>
                                <span class="font-bold text-gray-800"><?= number_format($stats['total_messages'] ?? 0) ?></span>
                            </div>
                            <div class="flex justify-between items-center p-3 bg-gray-50 rounded-lg">
                                <span class="text-gray-600">Messages non lus</span>
                                <span class="font-bold text-red-600"><?= number_format($stats['unread_messages'] ?? 0) ?></span>
                            </div>
                            <div class="flex justify-between items-center p-3 bg-gray-50 rounded-lg">
                                <span class="text-gray-600">Notifications</span>
                                <span class="font-bold text-gray-800"><?= number_format($stats['total_notifications'] ?? 0) ?></span>
                            </div>
                            <div class="flex justify-between items-center p-3 bg-gray-50 rounded-lg">
                                <span class="text-gray-600">Stories actives</span>
                                <span class="font-bold text-gray-800"><?= number_format($stats['total_stories'] ?? 0) ?></span>
                            </div>
                        </div>
                    </div>
                    <div>
                        <h3 class="font-bold text-gray-800 mb-4">Top Universités</h3>
                        <div class="space-y-2 max-h-96 overflow-y-auto">
                            <?php
                            $topUnis = [];
                            try {
                                $stmt = $pdo->query("SELECT universite, COUNT(*) as count FROM utilisateurs WHERE universite IS NOT NULL AND universite != '' GROUP BY universite ORDER BY count DESC LIMIT 10");
                                $topUnis = $stmt->fetchAll();
                            } catch (PDOException $e) {}
                            ?>
                            <?php foreach ($topUnis as $i => $uni): ?>
                                <div class="flex items-center gap-3 p-2 hover:bg-gray-50 rounded-lg">
                                    <span class="w-6 h-6 bg-orange-100 text-orange-600 rounded-full flex items-center justify-center text-sm font-bold"><?= $i+1 ?></span>
                                    <span class="flex-1 text-gray-700 truncate"><?= htmlspecialchars($uni['universite']) ?></span>
                                    <span class="text-sm text-gray-500"><?= number_format($uni['count']) ?> membres</span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- TAB: Utilisateurs -->
            <div id="tab-users" class="tab-content <?= $activeTab == 'users' ? '' : 'hidden' ?>">
                <div class="flex justify-between items-center mb-4">
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
                                            <img src="<?= get_avatar_url($user['avatar'] ?? '') ?>" class="w-10 h-10 rounded-full object-cover">
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
                                                <option value="etudiant" <?= $user['role'] == 'etudiant' ? 'selected' : '' ?>>Étudiant</option>
                                                <option value="professeur" <?= $user['role'] == 'professeur' ? 'selected' : '' ?>>Professeur</option>
                                                <option value="admin" <?= $user['role'] == 'admin' ? 'selected' : '' ?>>Admin</option>
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
            
            <!-- TAB: Contenu -->
            <div id="tab-content" class="tab-content <?= $activeTab == 'content' ? '' : 'hidden' ?>">
                <h3 class="font-bold text-gray-800 mb-4">Publications récentes</h3>
                <div class="space-y-4">
                    <?php foreach ($allPosts as $post): ?>
                        <div class="border rounded-xl p-4 hover:shadow-md transition-all">
                            <div class="flex items-center gap-3 mb-3">
                                <img src="<?= get_avatar_url($post['avatar'] ?? '') ?>" class="w-10 h-10 rounded-full">
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
                                <img src="../uploads/posts/<?= htmlspecialchars($post['image_post']) ?>" class="rounded-lg max-h-64 object-cover mt-2">
                            <?php endif; ?>
                            <div class="mt-3 flex justify-end">
                                <form method="POST" class="inline-block">
                                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                    <input type="hidden" name="action" value="delete_post">
                                    <input type="hidden" name="post_id" value="<?= $post['idpost'] ?>">
                                    <button type="submit" onclick="return confirm('Supprimer cette publication ?')" class="text-red-500 hover:text-red-700 text-sm">
                                        <i class="fas fa-trash-alt mr-1"></i>Supprimer
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <!-- TAB: Communautés -->
            <div id="tab-communities" class="tab-content <?= $activeTab == 'communities' ? '' : 'hidden' ?>">
                <h3 class="font-bold text-gray-800 mb-4">Communautés</h3>
                <div class="grid md:grid-cols-2 gap-4">
                    <?php foreach ($communities as $community): ?>
                        <div class="border rounded-xl p-4 hover:shadow-md transition-all">
                            <div class="flex items-center gap-3">
                                <div class="w-12 h-12 bg-gradient-to-br from-orange-500 to-red-500 rounded-xl flex items-center justify-center text-white font-bold text-lg">
                                    <?= strtoupper(substr($community['nom'], 0, 1)) ?>
                                </div>
                                <div class="flex-1">
                                    <p class="font-semibold text-gray-800"><?= htmlspecialchars($community['nom']) ?></p>
                                    <p class="text-xs text-gray-500">Créée par @<?= htmlspecialchars($community['creator_name']) ?></p>
                                    <p class="text-xs text-gray-400 mt-1"><?= number_format($community['member_count']) ?> membres</p>
                                </div>
                                <form method="POST">
                                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                    <input type="hidden" name="action" value="delete_community">
                                    <input type="hidden" name="community_id" value="<?= $community['id_communaute'] ?>">
                                    <button type="submit" onclick="return confirm('Supprimer cette communauté ?')" class="text-red-500 hover:text-red-700">
                                        <i class="fas fa-trash-alt"></i>
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <!-- TAB: Signalements -->
            <div id="tab-reports" class="tab-content <?= $activeTab == 'reports' ? '' : 'hidden' ?>">
                <h3 class="font-bold text-gray-800 mb-4">Publications signalées</h3>
                <?php if (empty($reportedPosts)): ?>
                    <div class="text-center py-12 text-gray-500">
                        <i class="fas fa-check-circle text-5xl text-green-300 mb-3"></i>
                        <p>Aucun signalement en attente</p>
                    </div>
                <?php else: ?>
                    <div class="space-y-4">
                        <?php foreach ($reportedPosts as $post): ?>
                            <div class="border-l-4 border-red-500 rounded-xl p-4 bg-red-50">
                                <div class="flex items-center gap-3 mb-3">
                                    <img src="<?= get_avatar_url($post['avatar'] ?? '') ?>" class="w-10 h-10 rounded-full">
                                    <div>
                                        <p class="font-semibold text-gray-800"><?= htmlspecialchars($post['surnom']) ?></p>
                                        <p class="text-xs text-gray-500">Signalé le <?= date('d/m/Y H:i', strtotime($post['reported_at'])) ?></p>
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
            
            <!-- TAB: Annonces -->
            <div id="tab-announcements" class="tab-content <?= $activeTab == 'announcements' ? '' : 'hidden' ?>">
                <div class="max-w-2xl mx-auto">
                    <h3 class="font-bold text-gray-800 mb-4">Envoyer une annonce</h3>
                    <form method="POST" class="space-y-4">
                        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                        <input type="hidden" name="action" value="create_announcement">
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Titre</label>
                            <input type="text" name="title" required class="w-full px-4 py-2 border rounded-lg focus:border-orange-500 focus:ring-2 focus:ring-orange-100 outline-none">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Message</label>
                            <textarea name="content" rows="6" required class="w-full px-4 py-2 border rounded-lg focus:border-orange-500 focus:ring-2 focus:ring-orange-100 outline-none resize-none"></textarea>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Destinataires</label>
                            <select name="target" class="w-full px-4 py-2 border rounded-lg focus:border-orange-500 outline-none">
                                <option value="all">Tous les utilisateurs</option>
                                <option value="etudiant">Étudiants uniquement</option>
                                <option value="professeur">Professeurs uniquement</option>
                                <option value="admin">Administrateurs uniquement</option>
                            </select>
                        </div>
                        
                        <button type="submit" class="bg-gradient-to-r from-orange-500 to-red-500 text-white px-6 py-2 rounded-lg hover:shadow-lg transition-all">
                            <i class="fas fa-paper-plane mr-2"></i>Envoyer l'annonce
                        </button>
                    </form>
                </div>
            </div>
            
            <!-- TAB: Logs -->
            <div id="tab-logs" class="tab-content <?= $activeTab == 'logs' ? '' : 'hidden' ?>">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="font-bold text-gray-800">Journal d'activité</h3>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                        <input type="hidden" name="action" value="clear_logs">
                        <button type="submit" onclick="return confirm('Vider tous les logs ?')" class="text-red-500 hover:text-red-700 text-sm">
                            <i class="fas fa-trash-alt mr-1"></i>Vider les logs
                        </button>
                    </form>
                </div>
                <div class="space-y-2 max-h-96 overflow-y-auto">
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
            
            <!-- TAB: Paramètres -->
            <div id="tab-settings" class="tab-content <?= $activeTab == 'settings' ? '' : 'hidden' ?>">
                <div class="max-w-2xl mx-auto space-y-6">
                    <div class="bg-gray-50 rounded-xl p-5">
                        <h4 class="font-semibold text-gray-800 mb-3">Configuration générale</h4>
                        <div class="space-y-3">
                            <div class="flex justify-between items-center">
                                <span class="text-gray-600">Inscriptions ouvertes</span>
                                <label class="relative inline-flex items-center cursor-pointer">
                                    <input type="checkbox" checked class="sr-only peer">
                                    <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-orange-500"></div>
                                </label>
                            </div>
                            <div class="flex justify-between items-center">
                                <span class="text-gray-600">Maintenance mode</span>
                                <label class="relative inline-flex items-center cursor-pointer">
                                    <input type="checkbox" class="sr-only peer">
                                    <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-orange-500"></div>
                                </label>
                            </div>
                        </div>
                    </div>
                    
                    <div class="bg-gray-50 rounded-xl p-5">
                        <h4 class="font-semibold text-gray-800 mb-3">Cache</h4>
                        <button onclick="clearCache()" class="bg-gray-200 text-gray-700 px-4 py-2 rounded-lg hover:bg-gray-300 transition-colors">
                            <i class="fas fa-broom mr-2"></i>Vider le cache
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Delete User Modal -->
    <div id="deleteUserModal" class="fixed inset-0 bg-black/50 backdrop-blur-sm z-50 hidden items-center justify-center p-4">
        <div class="bg-white rounded-2xl w-full max-w-md shadow-2xl">
            <div class="p-5 border-b border-gray-100">
                <h3 class="font-bold text-lg text-red-600">⚠️ Supprimer l'utilisateur</h3>
            </div>
            <div class="p-5">
                <p class="text-gray-700 mb-4">
                    Êtes-vous sûr de vouloir supprimer <strong id="deleteUserName"></strong> ?
                    Cette action est irréversible et supprimera toutes ses données.
                </p>
                <p class="text-sm text-gray-500 mb-4">Tapez <strong class="font-mono bg-gray-100 px-2 py-1 rounded">DELETE</strong> pour confirmer :</p>
                <form method="POST" id="deleteUserForm">
                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                    <input type="hidden" name="action" value="delete_user">
                    <input type="hidden" name="user_id" id="deleteUserId">
                    <input type="text" name="confirm" id="deleteConfirm" required pattern="DELETE" class="w-full px-4 py-2 border rounded-lg focus:border-red-500 focus:ring-2 focus:ring-red-100 outline-none font-mono uppercase">
                    <div class="flex gap-3 mt-4">
                        <button type="button" onclick="closeDeleteModal()" class="flex-1 px-4 py-2 bg-gray-100 hover:bg-gray-200 rounded-lg transition-colors">Annuler</button>
                        <button type="submit" class="flex-1 px-4 py-2 bg-red-500 hover:bg-red-600 text-white rounded-lg transition-colors">Supprimer</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Toast Container -->
    <div id="toastContainer" class="fixed bottom-4 right-4 z-50 space-y-2"></div>
    
    <script>
        // Graphique
        const ctx = document.getElementById('activityChart');
        if (ctx) {
            const dailyUsers = <?= json_encode(array_map(function($d) { return $d['count']; }, $stats['daily_users'])) ?>;
            const dailyPosts = <?= json_encode(array_map(function($d) { return $d['count']; }, $stats['daily_posts'])) ?>;
            const labels = <?= json_encode(array_map(function($d) { return date('d/m', strtotime($d['date'])); }, $stats['daily_users'])) ?>;
            
            new Chart(ctx, {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [
                        {
                            label: 'Nouveaux utilisateurs',
                            data: dailyUsers,
                            borderColor: '#f59e0b',
                            backgroundColor: 'rgba(245, 158, 11, 0.1)',
                            tension: 0.4,
                            fill: true
                        },
                        {
                            label: 'Nouvelles publications',
                            data: dailyPosts,
                            borderColor: '#10b981',
                            backgroundColor: 'rgba(16, 185, 129, 0.1)',
                            tension: 0.4,
                            fill: true
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    plugins: {
                        legend: { position: 'top' }
                    }
                }
            });
        }
        
        // Tab switching
        function switchTab(tabName) {
            document.querySelectorAll('.tab-content').forEach(tab => tab.classList.add('hidden'));
            document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('tab-active', 'text-orange-600'));
            
            const targetTab = document.getElementById('tab-' + tabName);
            if (targetTab) targetTab.classList.remove('hidden');
            
            const targetBtn = document.querySelector(`[data-tab="${tabName}"]`);
            if (targetBtn) {
                targetBtn.classList.add('tab-active', 'text-orange-600');
            }
            
            // Mettre à jour l'URL
            const url = new URL(window.location.href);
            url.searchParams.set('tab', tabName);
            window.history.pushState({}, '', url);
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
        
        function closeDeleteModal() {
            document.getElementById('deleteUserModal').classList.remove('flex');
            document.getElementById('deleteUserModal').classList.add('hidden');
            document.getElementById('deleteConfirm').value = '';
        }
        
        // Vider le cache
        function clearCache() {
            if (confirm('Vider le cache du navigateur ?')) {
                localStorage.clear();
                sessionStorage.clear();
                showToast('Cache vidé !', 'success');
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
                <i class="fas ${type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'}"></i>
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
        
        // Fermer les modals en cliquant à l'extérieur
        document.getElementById('deleteUserModal')?.addEventListener('click', function(e) {
            if (e.target === this) closeDeleteModal();
        });
    </script>
</body>
</html>

<?php
// Fonctions utilitaires
function getActivityIcon($action) {
    $icons = [
        'login_success' => 'sign-in-alt', 'login_failed' => 'exclamation-triangle',
        'register' => 'user-plus', 'logout' => 'sign-out-alt',
        'update_profile' => 'user-edit', 'update_avatar' => 'camera',
        'password_change' => 'key', 'post_created' => 'plus-circle',
        'post_deleted' => 'trash', 'like' => 'heart', 'comment' => 'comment',
        'friend_request' => 'user-friends', 'account_deleted' => 'user-slash',
        'admin_role_change' => 'user-shield', 'admin_user_status' => 'toggle-on',
        'admin_post_delete' => 'trash-alt', 'admin_community_delete' => 'university',
        'admin_clear_logs' => 'broom', 'admin_announcement' => 'bullhorn',
        'search' => 'search', 'password_recovery_requested' => 'key',
        'password_reset_completed' => 'check-circle'
    ];
    return $icons[$action] ?? 'circle';
}

function getActivityLabel($action) {
    $labels = [
        'login_success' => 's\'est connecté', 'login_failed' => 'a échoué à se connecter',
        'register' => 'a créé un compte', 'logout' => 's\'est déconnecté',
        'update_profile' => 'a mis à jour son profil', 'update_avatar' => 'a changé sa photo',
        'password_change' => 'a changé son mot de passe', 'post_created' => 'a créé une publication',
        'post_deleted' => 'a supprimé une publication', 'like' => 'a aimé une publication',
        'comment' => 'a commenté une publication', 'friend_request' => 'a envoyé une demande d\'ami',
        'account_deleted' => 'a supprimé son compte', 'admin_role_change' => 'a changé le rôle d\'un utilisateur',
        'admin_user_status' => 'a modifié le statut d\'un utilisateur', 'admin_post_delete' => 'a supprimé une publication',
        'admin_community_delete' => 'a supprimé une communauté', 'admin_clear_logs' => 'a vidé les logs',
        'admin_announcement' => 'a envoyé une annonce', 'search' => 'a effectué une recherche',
        'password_recovery_requested' => 'a demandé une réinitialisation',
        'password_reset_completed' => 'a réinitialisé son mot de passe'
    ];
    return $labels[$action] ?? $action;
}
?>