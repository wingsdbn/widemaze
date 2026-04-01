<?php
/**
 * WideMaze - Centre de Notifications Avancé
 * Version 3.0 - Notifications temps réel, filtres, préférences
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_auth();

$userId = $_SESSION['user_id'];
$filter = $_GET['filter'] ?? 'all'; // all, unread, read
$limit = min(intval($_GET['limit'] ?? 20), 100);
$offset = intval($_GET['offset'] ?? 0);
$ajax = isset($_GET['ajax']) || isset($_POST['ajax']);

// Traitement AJAX
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['ajax'])) {
    header('Content-Type: application/json');
    
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        echo json_encode(['error' => 'Token CSRF invalide']);
        exit();
    }
    
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'mark_read':
            $notifId = isset($_POST['id']) ? intval($_POST['id']) : null;
            if ($notifId) {
                $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1, read_at = NOW() WHERE id = ? AND user_id = ?");
                $stmt->execute([$notifId, $userId]);
            } else {
                $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1, read_at = NOW() WHERE user_id = ?");
                $stmt->execute([$userId]);
            }
            
            // Compter les non lues restantes
            $countStmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
            $countStmt->execute([$userId]);
            
            echo json_encode([
                'success' => true,
                'unread_count' => $countStmt->fetchColumn(),
                'marked' => $stmt->rowCount()
            ]);
            break;
            
        case 'delete_notification':
            $notifId = intval($_POST['id'] ?? 0);
            $stmt = $pdo->prepare("DELETE FROM notifications WHERE id = ? AND user_id = ?");
            $stmt->execute([$notifId, $userId]);
            echo json_encode(['success' => true, 'deleted' => $stmt->rowCount()]);
            break;
            
        case 'delete_all':
            $stmt = $pdo->prepare("DELETE FROM notifications WHERE user_id = ?");
            $stmt->execute([$userId]);
            echo json_encode(['success' => true, 'deleted' => $stmt->rowCount()]);
            break;
            
        case 'mark_unread':
            $notifId = intval($_POST['id'] ?? 0);
            $stmt = $pdo->prepare("UPDATE notifications SET is_read = 0 WHERE id = ? AND user_id = ?");
            $stmt->execute([$notifId, $userId]);
            echo json_encode(['success' => true]);
            break;
            
        case 'get_unread_count':
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
            $stmt->execute([$userId]);
            echo json_encode(['success' => true, 'unread_count' => $stmt->fetchColumn()]);
            break;
    }
    exit();
}

// Récupération des notifications avec filtres
$notifications = [];
$totalCount = 0;

try {
    $sql = "
        SELECT n.*, u.surnom as actor_name, u.avatar as actor_avatar,
            CASE 
                WHEN n.type = 'like' THEN 'fa-heart'
                WHEN n.type = 'comment' THEN 'fa-comment'
                WHEN n.type = 'friend_request' THEN 'fa-user-plus'
                WHEN n.type = 'friend_accept' THEN 'fa-check-circle'
                WHEN n.type = 'message' THEN 'fa-envelope'
                WHEN n.type = 'mention' THEN 'fa-at'
                WHEN n.type = 'post' THEN 'fa-newspaper'
                WHEN n.type = 'announcement' THEN 'fa-bullhorn'
                ELSE 'fa-info-circle'
            END as icon_class,
            CASE 
                WHEN n.type = 'like' THEN 'text-red-500'
                WHEN n.type = 'comment' THEN 'text-blue-500'
                WHEN n.type = 'friend_request' THEN 'text-green-500'
                WHEN n.type = 'friend_accept' THEN 'text-green-500'
                WHEN n.type = 'message' THEN 'text-purple-500'
                WHEN n.type = 'mention' THEN 'text-orange-500'
                WHEN n.type = 'post' THEN 'text-orange-500'
                WHEN n.type = 'announcement' THEN 'text-indigo-500'
                ELSE 'text-gray-500'
            END as icon_color,
            CASE 
                WHEN n.type = 'like' THEN 'bg-red-100'
                WHEN n.type = 'comment' THEN 'bg-blue-100'
                WHEN n.type = 'friend_request' THEN 'bg-green-100'
                WHEN n.type = 'friend_accept' THEN 'bg-green-100'
                WHEN n.type = 'message' THEN 'bg-purple-100'
                WHEN n.type = 'mention' THEN 'bg-orange-100'
                WHEN n.type = 'post' THEN 'bg-orange-100'
                WHEN n.type = 'announcement' THEN 'bg-indigo-100'
                ELSE 'bg-gray-100'
            END as icon_bg
        FROM notifications n
        LEFT JOIN utilisateurs u ON n.actor_id = u.id
        WHERE n.user_id = ?
    ";
    $params = [$userId];
    
    if ($filter == 'unread') {
        $sql .= " AND n.is_read = 0";
    } elseif ($filter == 'read') {
        $sql .= " AND n.is_read = 1";
    }
    
    $sql .= " ORDER BY n.created_at DESC LIMIT ? OFFSET ?";
    $params[] = $limit;
    $params[] = $offset;
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $notifications = $stmt->fetchAll();
    
    // Compter le total
    $countSql = "SELECT COUNT(*) FROM notifications WHERE user_id = ?";
    if ($filter == 'unread') $countSql .= " AND is_read = 0";
    elseif ($filter == 'read') $countSql .= " AND is_read = 1";
    
    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute([$userId]);
    $totalCount = $countStmt->fetchColumn();
    
} catch (PDOException $e) {
    error_log("Error fetching notifications: " . $e->getMessage());
}

// Compter les non lues
$unreadCount = 0;
try {
    $unreadStmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
    $unreadStmt->execute([$userId]);
    $unreadCount = $unreadStmt->fetchColumn();
} catch (PDOException $e) {
    error_log("Error counting notifications: " . $e->getMessage());
}

// Statistiques par type
$statsByType = [];
try {
    $stmt = $pdo->prepare("
        SELECT type, COUNT(*) as count 
        FROM notifications 
        WHERE user_id = ? 
        GROUP BY type 
        ORDER BY count DESC
    ");
    $stmt->execute([$userId]);
    $statsByType = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Error fetching stats: " . $e->getMessage());
}

// Grouper par date
$groupedNotifications = [];
foreach ($notifications as $notif) {
    $date = date('Y-m-d', strtotime($notif['created_at']));
    if (!isset($groupedNotifications[$date])) {
        $groupedNotifications[$date] = [];
    }
    $groupedNotifications[$date][] = $notif;
}

$csrfToken = generate_csrf_token();
$page_title = $unreadCount > 0 ? "Notifications ($unreadCount)" : "Notifications";
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title><?= $page_title ?> - WideMaze</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { font-family: 'Inter', sans-serif; }
        body { background: linear-gradient(135deg, #f5f7fa 0%, #eef2f7 100%); min-height: 100vh; }
        
        @keyframes slideIn {
            from { opacity: 0; transform: translateX(-20px); }
            to { opacity: 1; transform: translateX(0); }
        }
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-5px); }
            75% { transform: translateX(5px); }
        }
        
        .notification-item {
            animation: slideIn 0.3s ease-out;
            transition: all 0.2s;
        }
        .notification-item:hover {
            transform: translateX(4px);
            background-color: #f9fafb;
        }
        .unread {
            background: linear-gradient(90deg, #fffbeb 0%, #ffffff 100%);
            border-left: 3px solid #f59e0b;
        }
        .stat-card {
            transition: all 0.3s ease;
        }
        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px -5px rgba(0,0,0,0.1);
        }
        .filter-active {
            background-color: #f59e0b !important;
            color: white !important;
            box-shadow: 0 4px 6px -1px rgba(245, 158, 11, 0.2);
        }
        .toast {
            animation: fadeInUp 0.3s ease-out;
        }
    </style>
</head>
<body class="min-h-screen">
    
    <!-- Navigation Flottante -->
    <nav class="fixed top-4 left-1/2 -translate-x-1/2 w-[95%] max-w-6xl bg-white/95 backdrop-blur-md rounded-2xl shadow-lg border border-gray-100 z-50 px-6 py-3">
        <div class="flex items-center justify-between">
            <a href="../index.php" class="flex items-center gap-3 group">
                <div class="w-10 h-10 bg-gradient-to-br from-orange-500 to-red-600 rounded-xl flex items-center justify-center shadow-lg group-hover:scale-110 transition-transform duration-300">
                    <i class="fas fa-network-wired text-white text-lg"></i>
                </div>
                <span class="text-2xl font-bold bg-gradient-to-r from-orange-500 to-red-600 bg-clip-text text-transparent hidden sm:block">WideMaze</span>
            </a>
            
            <div class="flex items-center gap-4">
                <a href="../index.php" class="p-2.5 hover:bg-gray-100 rounded-xl transition-all relative group">
                    <i class="fas fa-home text-xl text-gray-600 group-hover:text-orange-500 transition-colors"></i>
                    <span class="absolute -bottom-1 left-1/2 -translate-x-1/2 w-1 h-1 bg-orange-500 rounded-full opacity-0 group-hover:opacity-100 transition-opacity"></span>
                </a>
                
                <a href="notifications.php" class="p-2.5 hover:bg-gray-100 rounded-xl transition-all relative bg-orange-50">
                    <i class="fas fa-bell text-xl text-orange-500"></i>
                    <?php if ($unreadCount > 0): ?>
                        <span class="absolute -top-1 -right-1 min-w-[20px] h-5 bg-red-500 text-white text-xs rounded-full flex items-center justify-center px-1 animate-pulse" id="unreadBadge">
                            <?= $unreadCount > 99 ? '99+' : $unreadCount ?>
                        </span>
                    <?php endif; ?>
                </a>
                
                <a href="messagerie.php" class="p-2.5 hover:bg-gray-100 rounded-xl transition-all relative group">
                    <i class="fas fa-comment-dots text-xl text-gray-600 group-hover:text-orange-500 transition-colors"></i>
                </a>
                
                <div class="relative group">
                    <button class="flex items-center gap-2 p-1.5 hover:bg-gray-100 rounded-xl transition-all">
                        <img src="<?= get_avatar_url($_SESSION['avatar'] ?? '') ?>" class="w-9 h-9 rounded-full object-cover border-2 border-orange-300 group-hover:border-orange-500 transition-colors">
                        <i class="fas fa-chevron-down text-xs text-gray-400 hidden sm:block"></i>
                    </button>
                    <div class="absolute right-0 top-full mt-3 w-64 bg-white rounded-2xl shadow-xl border border-gray-100 opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all duration-200 z-50 overflow-hidden">
                        <div class="p-4 border-b border-gray-100 bg-gradient-to-r from-orange-50 to-red-50">
                            <p class="font-bold text-gray-800"><?= htmlspecialchars($_SESSION['prenom'] . ' ' . $_SESSION['nom']) ?></p>
                            <p class="text-sm text-gray-500">@<?= htmlspecialchars($_SESSION['surnom']) ?></p>
                        </div>
                        <div class="p-2">
                            <a href="profil.php" class="flex items-center gap-3 px-4 py-2.5 hover:bg-gray-50 rounded-xl transition-colors text-gray-700"><i class="fas fa-user text-gray-400 w-5"></i>Mon profil</a>
                            <a href="parametres.php" class="flex items-center gap-3 px-4 py-2.5 hover:bg-gray-50 rounded-xl transition-colors text-gray-700"><i class="fas fa-cog text-gray-400 w-5"></i>Paramètres</a>
                            <?php if (is_admin()): ?>
                                <a href="admin.php" class="flex items-center gap-3 px-4 py-2.5 hover:bg-red-50 rounded-xl text-red-600"><i class="fas fa-shield-alt text-red-400 w-5"></i>Administration</a>
                            <?php endif; ?>
                        </div>
                        <div class="p-2 border-t border-gray-100">
                            <a href="deconnexion.php" class="flex items-center gap-3 px-4 py-2.5 hover:bg-red-50 rounded-xl transition-colors text-red-600 font-medium"><i class="fas fa-sign-out-alt w-5"></i>Déconnexion</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </nav>
    
    <div class="container mx-auto pt-24 pb-8 px-4 max-w-5xl">
        <!-- Header avec statistiques -->
        <div class="mb-8">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 mb-6">
                <div>
                    <h1 class="text-3xl md:text-4xl font-bold text-gray-800 flex items-center gap-3">
                        <div class="w-12 h-12 bg-gradient-to-br from-orange-500 to-red-500 rounded-2xl flex items-center justify-center shadow-lg">
                            <i class="fas fa-bell text-white text-xl"></i>
                        </div>
                        <span>Notifications</span>
                        <span id="unreadCountDisplay" class="text-sm font-medium <?= $unreadCount > 0 ? 'bg-orange-100 text-orange-600' : 'bg-gray-100 text-gray-500' ?> px-3 py-1 rounded-full">
                            <?= $unreadCount ?> non lue<?= $unreadCount > 1 ? 's' : '' ?>
                        </span>
                    </h1>
                    <p class="text-gray-500 mt-2 ml-14">Restez informé de toutes vos activités sur WideMaze</p>
                </div>
                
                <div class="flex gap-3">
                    <?php if ($unreadCount > 0): ?>
                        <button onclick="markAllAsRead()" class="px-4 py-2 bg-green-50 hover:bg-green-100 text-green-600 rounded-xl transition-all flex items-center gap-2 text-sm font-medium">
                            <i class="fas fa-check-double"></i>
                            <span class="hidden sm:inline">Tout marquer comme lu</span>
                        </button>
                    <?php endif; ?>
                    
                    <?php if ($totalCount > 0): ?>
                        <button onclick="deleteAllNotifications()" class="px-4 py-2 bg-red-50 hover:bg-red-100 text-red-500 rounded-xl transition-all flex items-center gap-2 text-sm font-medium">
                            <i class="fas fa-trash-alt"></i>
                            <span class="hidden sm:inline">Tout supprimer</span>
                        </button>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Cartes de statistiques -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8">
                <div class="stat-card bg-white rounded-2xl p-4 shadow-sm border border-gray-100">
                    <div class="flex items-center justify-between mb-2">
                        <i class="fas fa-bell text-2xl text-orange-500"></i>
                        <span class="text-xs text-gray-400">Total</span>
                    </div>
                    <p class="text-2xl font-bold text-gray-800"><?= number_format($totalCount) ?></p>
                    <p class="text-xs text-gray-500">notifications</p>
                </div>
                <div class="stat-card bg-white rounded-2xl p-4 shadow-sm border border-gray-100">
                    <div class="flex items-center justify-between mb-2">
                        <i class="fas fa-envelope-open-text text-2xl text-green-500"></i>
                    </div>
                    <p class="text-2xl font-bold text-gray-800"><?= number_format($totalCount - $unreadCount) ?></p>
                    <p class="text-xs text-gray-500">lues</p>
                </div>
                <div class="stat-card bg-white rounded-2xl p-4 shadow-sm border border-gray-100">
                    <div class="flex items-center justify-between mb-2">
                        <i class="fas fa-clock text-2xl text-yellow-500"></i>
                    </div>
                    <p class="text-2xl font-bold text-gray-800"><?= number_format($unreadCount) ?></p>
                    <p class="text-xs text-gray-500">non lues</p>
                </div>
                <div class="stat-card bg-gradient-to-r from-orange-500 to-red-500 rounded-2xl p-4 shadow-sm">
                    <div class="flex items-center justify-between mb-2">
                        <i class="fas fa-chart-line text-2xl text-white"></i>
                    </div>
                    <p class="text-2xl font-bold text-white"><?= number_format(($totalCount > 0 ? round(($totalCount - $unreadCount) / $totalCount * 100) : 0)) ?>%</p>
                    <p class="text-xs text-white/80">taux de lecture</p>
                </div>
            </div>
            
            <!-- Filtres -->
            <div class="flex flex-wrap gap-2 mb-6">
                <a href="?filter=all" class="filter-btn px-5 py-2 rounded-full text-sm font-medium transition-all <?= $filter == 'all' ? 'filter-active bg-orange-500 text-white shadow-md' : 'bg-white text-gray-600 hover:bg-gray-100 border border-gray-200' ?>" data-filter="all">
                    <i class="fas fa-globe mr-2"></i>Toutes
                </a>
                <a href="?filter=unread" class="filter-btn px-5 py-2 rounded-full text-sm font-medium transition-all <?= $filter == 'unread' ? 'filter-active bg-orange-500 text-white shadow-md' : 'bg-white text-gray-600 hover:bg-gray-100 border border-gray-200' ?>" data-filter="unread">
                    <i class="fas fa-clock mr-2"></i>Non lues
                    <?php if ($unreadCount > 0): ?>
                        <span class="ml-1 px-1.5 py-0.5 bg-red-500 text-white text-xs rounded-full"><?= $unreadCount ?></span>
                    <?php endif; ?>
                </a>
                <a href="?filter=read" class="filter-btn px-5 py-2 rounded-full text-sm font-medium transition-all <?= $filter == 'read' ? 'filter-active bg-orange-500 text-white shadow-md' : 'bg-white text-gray-600 hover:bg-gray-100 border border-gray-200' ?>" data-filter="read">
                    <i class="fas fa-check-circle mr-2"></i>Lues
                </a>
            </div>
            
            <!-- Statistiques par type -->
            <?php if (!empty($statsByType)): ?>
                <div class="bg-white rounded-2xl shadow-sm p-4 mb-6 overflow-x-auto">
                    <div class="flex gap-6 min-w-max">
                        <?php foreach ($statsByType as $stat): ?>
                            <div class="text-center">
                                <div class="w-10 h-10 rounded-full bg-gray-100 flex items-center justify-center mx-auto mb-1">
                                    <i class="fas fa-<?= $stat['type'] == 'like' ? 'heart' : ($stat['type'] == 'comment' ? 'comment' : ($stat['type'] == 'friend_request' ? 'user-plus' : 'bell')) ?> text-orange-500"></i>
                                </div>
                                <p class="text-lg font-bold text-gray-800"><?= number_format($stat['count']) ?></p>
                                <p class="text-xs text-gray-500"><?= ucfirst(str_replace('_', ' ', $stat['type'])) ?></p>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Notifications List -->
        <div id="notificationsContainer" class="bg-white rounded-2xl shadow-sm min-h-[400px] overflow-hidden">
            <?php if (empty($notifications)): ?>
                <div class="flex flex-col items-center justify-center py-20 text-gray-400">
                    <div class="w-32 h-32 bg-gradient-to-br from-gray-100 to-gray-200 rounded-full flex items-center justify-center mb-6">
                        <i class="fas fa-bell-slash text-5xl text-gray-300"></i>
                    </div>
                    <p class="text-xl font-medium text-gray-500 mb-2">Aucune notification</p>
                    <p class="text-sm text-gray-400">Vous verrez ici vos likes, commentaires et demandes d'ami</p>
                    <a href="../index.php" class="mt-6 inline-flex items-center gap-2 text-orange-500 hover:text-orange-600 font-medium">
                        <i class="fas fa-home"></i>Retour à l'accueil
                    </a>
                </div>
            <?php else: ?>
                <?php foreach ($groupedNotifications as $date => $notifs): ?>
                    <div class="sticky  z-10 bg-white">
                        <div class="px-6 py-3 bg-gray-50 border-b border-gray-100">
                            <h3 class=" text-xs font-bold text-gray-500 uppercase tracking-wider flex items-center gap-2">
                                <i class="fas fa-calendar-alt"></i>
                                <?php
                                    $today = date('Y-m-d');
                                    $yesterday = date('Y-m-d', strtotime('-1 day'));
                                    $weekAgo = date('Y-m-d', strtotime('-7 days'));
                                    if ($date === $today) echo "Aujourd'hui";
                                    elseif ($date === $yesterday) echo "Hier";
                                    elseif ($date >= $weekAgo) echo date('l', strtotime($date));
                                    else echo date('d F Y', strtotime($date));
                                ?>
                            </h3>
                        </div>
                    </div>
                    <?php foreach ($notifs as $notif): 
                        $isUnread = !$notif['is_read'];
                    ?>
                        <div class="notification-item flex items-start gap-4 p-6 border-b border-gray-100 hover:bg-gray-50 transition-all <?= $isUnread ? 'unread' : '' ?>" data-notif-id="<?= $notif['id'] ?>" data-notif-type="<?= $notif['type'] ?>">
                            <!-- Icon or Avatar -->
                            <?php if ($notif['actor_id'] && $notif['actor_avatar']): ?>
                                <div class="relative flex-shrink-0">
                                    <img src="<?= get_avatar_url($notif['actor_avatar']) ?>" class="w-14 h-14 rounded-full object-cover border-2 border-white shadow-sm">
                                    <div class="absolute -bottom-1 -right-1 w-7 h-7 <?= $notif['icon_bg'] ?> rounded-full flex items-center justify-center border-2 border-white shadow-sm">
                                        <i class="fas <?= $notif['icon_class'] ?> <?= $notif['icon_color'] ?> text-sm"></i>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="w-14 h-14 <?= $notif['icon_bg'] ?> rounded-full flex items-center justify-center flex-shrink-0 shadow-sm">
                                    <i class="fas <?= $notif['icon_class'] ?> <?= $notif['icon_color'] ?> text-xl"></i>
                                </div>
                            <?php endif; ?>
                            
                            <!-- Content -->
                            <div class="flex-1 min-w-0">
                                <div class="flex items-start justify-between gap-2">
                                    <div class="flex-1">
                                        <p class="text-gray-800 <?= $isUnread ? 'font-semibold' : '' ?> leading-relaxed">
                                            <?= htmlspecialchars($notif['title']) ?>
                                            <?php if ($notif['actor_name']): ?>
                                                <span class="font-normal text-gray-500">par</span>
                                                <a href="profil.php?id=<?= $notif['actor_id'] ?>" class="text-orange-500 hover:underline font-medium">
                                                    @<?= htmlspecialchars($notif['actor_name']) ?>
                                                </a>
                                            <?php endif; ?>
                                        </p>
                                        <?php if (!empty($notif['content'])): ?>
                                            <p class="text-gray-500 text-sm mt-1"><?= htmlspecialchars($notif['content']) ?></p>
                                        <?php endif; ?>
                                        <p class="text-gray-400 text-xs mt-2 flex items-center gap-1">
                                            <i class="far fa-clock"></i>
                                            <?= time_ago($notif['created_at']) ?>
                                            <?php if ($notif['read_at']): ?>
                                                <span class="mx-1">•</span>
                                                <i class="fas fa-check-circle text-green-500 text-xs"></i>
                                                <span>Lu le <?= date('d/m/Y H:i', strtotime($notif['read_at'])) ?></span>
                                            <?php endif; ?>
                                        </p>
                                    </div>
                                    
                                    <!-- Actions -->
                                    <div class="flex items-center gap-1 flex-shrink-0">
                                        <?php if ($notif['link']): ?>
                                            <a href="<?= htmlspecialchars($notif['link']) ?>" class="p-2 text-orange-500 hover:bg-orange-50 rounded-lg transition-colors" title="Voir">
                                                <i class="fas fa-external-link-alt text-sm"></i>
                                            </a>
                                        <?php endif; ?>
                                        
                                        <?php if ($isUnread): ?>
                                            <button onclick="markAsRead(<?= $notif['id'] ?>)" class="p-2 text-gray-400 hover:text-green-500 hover:bg-green-50 rounded-lg transition-colors" title="Marquer comme lu">
                                                <i class="fas fa-check-double"></i>
                                            </button>
                                        <?php else: ?>
                                            <button onclick="markAsUnread(<?= $notif['id'] ?>)" class="p-2 text-gray-400 hover:text-orange-500 hover:bg-orange-50 rounded-lg transition-colors" title="Marquer comme non lu">
                                                <i class="fas fa-clock"></i>
                                            </button>
                                        <?php endif; ?>
                                        
                                        <button onclick="deleteNotification(<?= $notif['id'] ?>)" class="p-2 text-gray-400 hover:text-red-500 hover:bg-red-50 rounded-lg transition-colors" title="Supprimer">
                                            <i class="fas fa-times"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                            
                            <?php if ($isUnread): ?>
                                <span class="w-2 h-2 bg-orange-500 rounded-full flex-shrink-0 mt-2 animate-pulse"></span>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endforeach; ?>
                
                <!-- Load More -->
                <?php if ($totalCount > $limit): ?>
                    <div class="text-center py-6 border-t border-gray-100">
                        <button onclick="loadMore()" id="loadMoreBtn" class="px-6 py-3 bg-white border border-gray-200 rounded-xl text-gray-600 font-medium hover:border-orange-500 hover:text-orange-500 transition-all shadow-sm hover:shadow-md">
                            <i class="fas fa-arrow-down mr-2"></i>
                            <span>Charger plus de notifications</span>
                            <i class="fas fa-spinner fa-spin ml-2 hidden" id="loadMoreSpinner"></i>
                        </button>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Toast Container -->
    <div id="toastContainer" class="fixed bottom-4 right-4 z-50 space-y-2"></div>
    
    <script>
        const csrfToken = '<?= $csrfToken ?>';
        let currentOffset = <?= $offset + $limit ?>;
        let currentFilter = '<?= $filter ?>';
        let isLoading = false;
        let hasMore = <?= $totalCount > $limit ? 'true' : 'false' ?>;
        
        // ==================== NOTIFICATIONS FUNCTIONS ====================
        
        async function markAsRead(notificationId) {
            try {
                const formData = new FormData();
                formData.append('ajax', 1);
                formData.append('action', 'mark_read');
                formData.append('id', notificationId);
                formData.append('csrf_token', csrfToken);
                
                const response = await fetch('', { method: 'POST', body: formData });
                const data = await response.json();
                
                if (data.success) {
                    const notifDiv = document.querySelector(`[data-notif-id="${notificationId}"]`);
                    if (notifDiv) {
                        notifDiv.classList.remove('unread');
                        const titleSpan = notifDiv.querySelector('.font-semibold');
                        if (titleSpan) titleSpan.classList.remove('font-semibold');
                        const badge = notifDiv.querySelector('.w-2.h-2');
                        if (badge) badge.remove();
                        
                        const unreadCountDisplay = document.getElementById('unreadCountDisplay');
                        const unreadBadge = document.getElementById('unreadBadge');
                        if (unreadCountDisplay && unreadBadge) {
                            const newCount = data.unread_count;
                            if (newCount > 0) {
                                unreadCountDisplay.textContent = `${newCount} non lue${newCount > 1 ? 's' : ''}`;
                                unreadBadge.textContent = newCount > 99 ? '99+' : newCount;
                            } else {
                                unreadCountDisplay.textContent = '0 non lue';
                                unreadBadge.remove();
                            }
                        }
                    }
                    showToast('Notification marquée comme lue', 'success');
                }
            } catch (err) {
                console.error('Error:', err);
                showToast('Erreur de connexion', 'error');
            }
        }
        
        async function markAsUnread(notificationId) {
            try {
                const formData = new FormData();
                formData.append('ajax', 1);
                formData.append('action', 'mark_unread');
                formData.append('id', notificationId);
                formData.append('csrf_token', csrfToken);
                
                const response = await fetch('', { method: 'POST', body: formData });
                const data = await response.json();
                
                if (data.success) {
                    location.reload();
                }
            } catch (err) {
                console.error('Error:', err);
                showToast('Erreur de connexion', 'error');
            }
        }
        
        async function markAllAsRead() {
            if (!confirm('Marquer toutes les notifications comme lues ?')) return;
            
            try {
                const formData = new FormData();
                formData.append('ajax', 1);
                formData.append('action', 'mark_read');
                formData.append('csrf_token', csrfToken);
                
                const response = await fetch('', { method: 'POST', body: formData });
                const data = await response.json();
                
                if (data.success) {
                    location.reload();
                }
            } catch (err) {
                console.error('Error:', err);
                showToast('Erreur de connexion', 'error');
            }
        }
        
        async function deleteNotification(notificationId) {
            if (!confirm('Supprimer cette notification ?')) return;
            
            try {
                const formData = new FormData();
                formData.append('ajax', 1);
                formData.append('action', 'delete_notification');
                formData.append('id', notificationId);
                formData.append('csrf_token', csrfToken);
                
                const response = await fetch('', { method: 'POST', body: formData });
                const data = await response.json();
                
                if (data.success) {
                    const notifDiv = document.querySelector(`[data-notif-id="${notificationId}"]`);
                    if (notifDiv) {
                        notifDiv.style.animation = 'shake 0.3s ease-out';
                        setTimeout(() => notifDiv.remove(), 300);
                    }
                    
                    const remaining = document.querySelectorAll('[data-notif-id]').length;
                    if (remaining === 0) {
                        location.reload();
                    }
                    
                    showToast('Notification supprimée', 'success');
                }
            } catch (err) {
                console.error('Error:', err);
                showToast('Erreur de connexion', 'error');
            }
        }
        
        async function deleteAllNotifications() {
            if (!confirm('⚠️ Supprimer TOUTES les notifications ? Cette action est irréversible.')) return;
            
            try {
                const formData = new FormData();
                formData.append('ajax', 1);
                formData.append('action', 'delete_all');
                formData.append('csrf_token', csrfToken);
                
                const response = await fetch('', { method: 'POST', body: formData });
                const data = await response.json();
                
                if (data.success) {
                    location.reload();
                }
            } catch (err) {
                console.error('Error:', err);
                showToast('Erreur de connexion', 'error');
            }
        }
        
        async function loadMore() {
            if (isLoading || !hasMore) return;
            isLoading = true;
            
            const loadMoreBtn = document.getElementById('loadMoreBtn');
            const spinner = document.getElementById('loadMoreSpinner');
            if (loadMoreBtn) {
                loadMoreBtn.disabled = true;
                if (spinner) spinner.classList.remove('hidden');
            }
            
            try {
                const response = await fetch(`?filter=${currentFilter}&limit=20&offset=${currentOffset}&ajax=1`);
                const text = await response.text();
                const tempDiv = document.createElement('div');
                tempDiv.innerHTML = text;
                
                const newNotifications = tempDiv.querySelectorAll('.notification-item');
                const container = document.getElementById('notificationsContainer');
                const loadMoreDiv = document.querySelector('.text-center.py-6');
                
                newNotifications.forEach(notif => {
                    if (loadMoreDiv) {
                        container.insertBefore(notif, loadMoreDiv);
                    } else {
                        container.appendChild(notif);
                    }
                });
                
                currentOffset += 20;
                
                if (newNotifications.length < 20) {
                    hasMore = false;
                    if (loadMoreBtn) loadMoreBtn.style.display = 'none';
                }
                
            } catch (err) {
                console.error('Error loading more:', err);
                showToast('Erreur de chargement', 'error');
            } finally {
                isLoading = false;
                if (loadMoreBtn) {
                    loadMoreBtn.disabled = false;
                    if (spinner) spinner.classList.add('hidden');
                }
            }
        }
        
        // ==================== UTILITIES ====================
        
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
        
        // Mise à jour en temps réel du badge de notifications
        async function updateUnreadCount() {
            try {
                const formData = new FormData();
                formData.append('ajax', 1);
                formData.append('action', 'get_unread_count');
                formData.append('csrf_token', csrfToken);
                
                const response = await fetch('', { method: 'POST', body: formData });
                const data = await response.json();
                
                if (data.success) {
                    const badge = document.getElementById('unreadBadge');
                    const display = document.getElementById('unreadCountDisplay');
                    
                    if (data.unread_count > 0) {
                        if (badge) {
                            badge.textContent = data.unread_count > 99 ? '99+' : data.unread_count;
                            badge.classList.remove('hidden');
                        } else {
                            const newBadge = document.createElement('span');
                            newBadge.id = 'unreadBadge';
                            newBadge.className = 'absolute -top-1 -right-1 min-w-[20px] h-5 bg-red-500 text-white text-xs rounded-full flex items-center justify-center px-1 animate-pulse';
                            newBadge.textContent = data.unread_count > 99 ? '99+' : data.unread_count;
                            document.querySelector('.fa-bell')?.parentElement?.appendChild(newBadge);
                        }
                        if (display) display.textContent = `${data.unread_count} non lue${data.unread_count > 1 ? 's' : ''}`;
                    } else {
                        if (badge) badge.remove();
                        if (display) display.textContent = '0 non lue';
                    }
                }
            } catch (err) {
                console.error('Error updating count:', err);
            }
        }
        
        // Polling toutes les 30 secondes
        setInterval(updateUnreadCount, 30000);
        
        // Animation des nouveaux éléments
        const observer = new MutationObserver((mutations) => {
            mutations.forEach((mutation) => {
                mutation.addedNodes.forEach((node) => {
                    if (node.nodeType === 1 && node.classList && node.classList.contains('notification-item')) {
                        node.style.animation = 'slideIn 0.3s ease-out';
                    }
                });
            });
        });
        
        const container = document.getElementById('notificationsContainer');
        if (container) {
            observer.observe(container, { childList: true, subtree: true });
        }
    </script>
</body>
</html>