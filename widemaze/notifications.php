<?php
require_once 'config.php';
require_auth();

$userId = $_SESSION['user_id'];

// Marquer toutes comme lues si demandé
if (isset($_GET['mark_read'])) {
    try {
        $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
        $stmt->execute([$userId]);
    } catch (PDOException $e) {
        error_log("Erreur marquer notifications lues: " . $e->getMessage());
    }
    header('Location: notifications.php');
    exit();
}

// Supprimer une notification
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    try {
        $stmt = $pdo->prepare("DELETE FROM notifications WHERE id = ? AND user_id = ?");
        $stmt->execute([$_GET['delete'], $userId]);
    } catch (PDOException $e) {
        error_log("Erreur suppression notification: " . $e->getMessage());
    }
    header('Location: notifications.php');
    exit();
}

// Récupération des notifications
$notifications = [];
try {
    $stmt = $pdo->prepare("SELECT n.*, u.surnom as actor_name, u.avatar as actor_avatar 
        FROM notifications n 
        LEFT JOIN utilisateurs u ON n.actor_id = u.id 
        WHERE n.user_id = ? 
        ORDER BY n.created_at DESC 
        LIMIT 50");
    $stmt->execute([$userId]);
    $notifications = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Erreur récupération notifications: " . $e->getMessage());
}

// Compter les non lues
$unreadCount = 0;
try {
    $unreadStmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
    $unreadStmt->execute([$userId]);
    $unreadCount = $unreadStmt->fetchColumn();
} catch (PDOException $e) {
    error_log("Erreur comptage notifications: " . $e->getMessage());
}

// Grouper par date
$groupedNotifications = [];
foreach ($notifications as $notif) {
    if (!empty($notif['created_at'])) {
        $date = date('Y-m-d', strtotime($notif['created_at']));
        if (!isset($groupedNotifications[$date])) {
            $groupedNotifications[$date] = [];
        }
        $groupedNotifications[$date][] = $notif;
    }
}

// Fonction pour formater la date relative
function timeAgo($datetime) {
    if (empty($datetime)) return 'Date inconnue';
    
    $time = strtotime($datetime);
    $now = time();
    $diff = $now - $time;
    
    if ($diff < 60) return 'À l\'instant';
    if ($diff < 3600) return floor($diff / 60) . ' min';
    if ($diff < 86400) return floor($diff / 3600) . ' h';
    if ($diff < 604800) return floor($diff / 86400) . ' j';
    return date('d M', $time);
}

// Icônes par type
$icons = [
    'like' => ['icon' => 'fa-heart', 'color' => 'text-red-500', 'bg' => 'bg-red-100'],
    'comment' => ['icon' => 'fa-comment', 'color' => 'text-blue-500', 'bg' => 'bg-blue-100'],
    'friend_request' => ['icon' => 'fa-user-plus', 'color' => 'text-green-500', 'bg' => 'bg-green-100'],
    'friend_accept' => ['icon' => 'fa-check', 'color' => 'text-green-500', 'bg' => 'bg-green-100'],
    'mention' => ['icon' => 'fa-at', 'color' => 'text-purple-500', 'bg' => 'bg-purple-100'],
    'post' => ['icon' => 'fa-newspaper', 'color' => 'text-orange-500', 'bg' => 'bg-orange-100'],
    'system' => ['icon' => 'fa-info-circle', 'color' => 'text-gray-500', 'bg' => 'bg-gray-100']
];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications<?= $unreadCount > 0 ? " ($unreadCount)" : '' ?> - WideMaze</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: '#f59e0b',
                        secondary: '#1e293b',
                    }
                }
            }
        }
    </script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');
        body { font-family: 'Inter', sans-serif; }
        .notification-item { transition: all 0.2s; }
        .notification-item:hover { transform: translateX(4px); }
        .unread { border-left: 3px solid #f59e0b; background-color: #fffbeb; }
        .notification-enter { animation: slideIn 0.3s ease-out; }
        @keyframes slideIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
    </style>
</head>
<body class="bg-gray-100">
    <!-- Navigation -->
    <nav class="fixed top-0 w-full bg-white shadow-md z-50">
        <div class="container mx-auto px-4 h-16 flex items-center justify-between">
            <a href="index.php" class="flex items-center gap-2">
                <div class="w-10 h-10 bg-gradient-to-br from-primary to-orange-600 rounded-lg flex items-center justify-center">
                    <i class="fas fa-network-wired text-white"></i>
                </div>
                <span class="text-2xl font-bold text-secondary hidden md:block">WideMaze</span>
            </a>
            
            <div class="flex items-center gap-4">
                <a href="index.php" class="p-2 hover:bg-gray-100 rounded-full transition-colors">
                    <i class="fas fa-home text-xl text-gray-600"></i>
                </a>
                <a href="notifications.php" class="p-2 hover:bg-gray-100 rounded-full transition-colors relative bg-gray-100">
                    <i class="fas fa-bell text-xl text-primary"></i>
                    <?php if ($unreadCount > 0): ?>
                        <span class="absolute -top-1 -right-1 w-5 h-5 bg-red-500 text-white text-xs rounded-full flex items-center justify-center font-bold"><?= $unreadCount > 9 ? '9+' : $unreadCount ?></span>
                    <?php endif; ?>
                </a>
                <a href="messagerie.php" class="p-2 hover:bg-gray-100 rounded-full transition-colors">
                    <i class="fas fa-comment text-xl text-gray-600"></i>
                </a>
                <div class="relative group">
                    <button class="flex items-center gap-2 p-1 hover:bg-gray-100 rounded-full transition-colors">
                        <img src="<?= AVATAR_URL . htmlspecialchars($_SESSION['avatar']) ?>" class="w-8 h-8 rounded-full object-cover border-2 border-primary">
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

    <div class="container mx-auto pt-20 pb-8 px-4 max-w-3xl">
        <!-- Header -->
        <div class="bg-white rounded-t-2xl shadow-sm p-6 flex items-center justify-between border-b">
            <div>
                <h1 class="text-2xl font-bold text-gray-800 flex items-center gap-3">
                    <i class="fas fa-bell text-primary"></i>Notifications
                    <?php if ($unreadCount > 0): ?>
                        <span class="px-3 py-1 bg-primary text-white text-sm rounded-full"><?= $unreadCount ?> nouvelle<?= $unreadCount > 1 ? 's' : '' ?></span>
                    <?php endif; ?>
                </h1>
                <p class="text-gray-500 text-sm mt-1">Restez informé de vos activités</p>
            </div>
            <?php if ($unreadCount > 0): ?>
                <a href="?mark_read=1" class="text-primary hover:text-orange-600 font-medium text-sm flex items-center gap-2 px-4 py-2 hover:bg-orange-50 rounded-lg transition-colors">
                    <i class="fas fa-check-double"></i>Tout marquer comme lu
                </a>
            <?php endif; ?>
        </div>

        <!-- Notifications List -->
        <div class="bg-white rounded-b-2xl shadow-sm min-h-[500px]">
            <?php if (empty($notifications)): ?>
                <div class="flex flex-col items-center justify-center py-16 text-gray-400">
                    <div class="w-24 h-24 bg-gray-100 rounded-full flex items-center justify-center mb-4">
                        <i class="fas fa-bell-slash text-4xl text-gray-300"></i>
                    </div>
                    <p class="text-lg font-medium text-gray-500">Aucune notification</p>
                    <p class="text-sm">Vous verrez ici vos likes, commentaires et demandes d'ami</p>
                </div>
            <?php else: ?>
                <?php foreach ($groupedNotifications as $date => $notifs): ?>
                    <div class="px-6 py-3 bg-gray-50 border-b border-gray-100">
                        <h3 class="text-xs font-bold text-gray-500 uppercase tracking-wider">
                            <?php 
                            $today = date('Y-m-d');
                            $yesterday = date('Y-m-d', strtotime('-1 day'));
                            if ($date === $today) echo 'Aujourd\'hui';
                            elseif ($date === $yesterday) echo 'Hier';
                            else echo date('d F Y', strtotime($date));
                            ?>
                        </h3>
                    </div>
                    
                    <?php foreach ($notifs as $notif): 
                        $iconData = $icons[$notif['type']] ?? $icons['system'];
                        $isUnread = !$notif['is_read'];
                    ?>
                        <div class="notification-item flex items-start gap-4 p-6 border-b border-gray-100 hover:bg-gray-50 <?= $isUnread ? 'unread' : '' ?> <?= $isUnread ? 'notification-enter' : '' ?>">
                            <!-- Icon or Avatar -->
                            <?php if ($notif['actor_id'] && $notif['actor_avatar']): ?>
                                <div class="relative flex-shrink-0">
                                    <img src="<?= getAvatarUrl($notif['actor_avatar']) ?>" class="w-12 h-12 rounded-full object-cover">
                                    <div class="absolute -bottom-1 -right-1 w-6 h-6 <?= $iconData['bg'] ?> rounded-full flex items-center justify-center border-2 border-white">
                                        <i class="fas <?= $iconData['icon'] ?> <?= $iconData['color'] ?> text-xs"></i>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="w-12 h-12 <?= $iconData['bg'] ?> rounded-full flex items-center justify-center flex-shrink-0">
                                    <i class="fas <?= $iconData['icon'] ?> <?= $iconData['color'] ?> text-xl"></i>
                                </div>
                            <?php endif; ?>

                            <!-- Content -->
                            <div class="flex-1 min-w-0">
                                <p class="text-gray-800 <?= $isUnread ? 'font-semibold' : '' ?>">
                                    <?= htmlspecialchars($notif['title']) ?>
                                    <?php if ($notif['actor_name']): ?>
                                        <span class="font-normal text-gray-500">par</span>
                                        <a href="profil.php?id=<?= $notif['actor_id'] ?>" class="text-primary hover:underline font-medium">@<?= htmlspecialchars($notif['actor_name']) ?></a>
                                    <?php endif; ?>
                                </p>
                                <?php if ($notif['message']): ?>
                                    <p class="text-gray-600 text-sm mt-1 line-clamp-2"><?= htmlspecialchars($notif['message']) ?></p>
                                <?php endif; ?>
                                <p class="text-gray-400 text-xs mt-2 flex items-center gap-1">
                                    <i class="far fa-clock"></i><?= timeAgo($notif['created_at']) ?>
                                </p>
                            </div>

                            <!-- Actions -->
                            <div class="flex items-center gap-2">
                                <?php if ($notif['link']): ?>
                                    <a href="<?= htmlspecialchars($notif['link']) ?>" class="p-2 text-primary hover:bg-orange-50 rounded-lg transition-colors" title="Voir">
                                        <i class="fas fa-external-link-alt"></i>
                                    </a>
                                <?php endif; ?>
                                <a href="?delete=<?= $notif['id'] ?>" onclick="return confirm('Supprimer cette notification ?')" class="p-2 text-gray-400 hover:text-red-500 hover:bg-red-50 rounded-lg transition-colors" title="Supprimer">
                                    <i class="fas fa-times"></i>
                                </a>
                                <?php if ($isUnread): ?>
                                    <span class="w-2 h-2 bg-primary rounded-full flex-shrink-0" title="Non lu"></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Footer -->
        <div class="mt-6 text-center">
            <a href="parametres.php?tab=notifications" class="text-gray-500 hover:text-primary text-sm flex items-center justify-center gap-2 transition-colors">
                <i class="fas fa-cog"></i>Configurer les préférences de notification
            </a>
        </div>
    </div>

    <script>
        // Auto-refresh toutes les 30 secondes s'il y a des notifications non lues
        <?php if ($unreadCount > 0): ?>
        setTimeout(() => {
            location.reload();
        }, 30000);
        <?php endif; ?>

        // Marquer comme lu au clic (AJAX)
        document.querySelectorAll('.notification-item').forEach(item => {
            item.addEventListener('click', async function(e) {
                if (e.target.closest('a') || e.target.closest('button')) return;
                
                const notifId = this.dataset.id;
                if (this.classList.contains('unread')) {
                    // Envoyer requête AJAX pour marquer comme lu
                    try {
                        await fetch('api/notifications.php', {
                            method: 'POST',
                            headers: {'Content-Type': 'application/json'},
                            body: JSON.stringify({action: 'mark_read', id: notifId})
                        });
                        this.classList.remove('unread');
                        this.classList.remove('notification-enter');
                    } catch (err) {
                        console.error('Error marking as read:', err);
                    }
                }
            });
        });
    </script>
</body>
</html>