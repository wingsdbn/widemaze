<?php
/**
 * WideMaze - Notification Item Component
 * Affiche une notification avec son icône et ses actions
 * 
 * Variables attendues:
 * - $notification: array contenant les données de la notification
 * - $icons: array des icônes par type de notification
 */
 
$notification = $notification ?? [];
$icons = $icons ?? [
    'like' => ['icon' => 'fa-heart', 'color' => 'text-red-500', 'bg' => 'bg-red-100'],
    'comment' => ['icon' => 'fa-comment', 'color' => 'text-blue-500', 'bg' => 'bg-blue-100'],
    'friend_request' => ['icon' => 'fa-user-plus', 'color' => 'text-green-500', 'bg' => 'bg-green-100'],
    'friend_accept' => ['icon' => 'fa-check-circle', 'color' => 'text-green-500', 'bg' => 'bg-green-100'],
    'message' => ['icon' => 'fa-envelope', 'color' => 'text-purple-500', 'bg' => 'bg-purple-100'],
    'mention' => ['icon' => 'fa-at', 'color' => 'text-orange-500', 'bg' => 'bg-orange-100'],
    'post' => ['icon' => 'fa-newspaper', 'color' => 'text-orange-500', 'bg' => 'bg-orange-100'],
    'system' => ['icon' => 'fa-info-circle', 'color' => 'text-gray-500', 'bg' => 'bg-gray-100'],
    'announcement' => ['icon' => 'fa-bullhorn', 'color' => 'text-indigo-500', 'bg' => 'bg-indigo-100']
];

$iconData = $icons[$notification['type'] ?? 'system'] ?? $icons['system'];
$isUnread = !($notification['is_read'] ?? true);
$timeAgo = time_ago($notification['created_at'] ?? '');
?>

<div class="notification-item flex items-start gap-4 p-5 border-b border-gray-100 hover:bg-gray-50 transition-all <?= $isUnread ? 'bg-amber-50 border-l-4 border-l-primary' : '' ?> animate-fade-in" data-notification-id="<?= $notification['id'] ?? 0 ?>">
    <!-- Icon or Avatar -->
    <?php if (!empty($notification['actor_id']) && !empty($notification['actor_avatar'])): ?>
        <div class="relative flex-shrink-0">
            <img src="<?= get_avatar_url($notification['actor_avatar'] ?? '') ?>" 
                 class="w-12 h-12 rounded-full object-cover border-2 border-white shadow-sm">
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
        <div class="flex items-start justify-between gap-2">
            <div>
                <p class="<?= $isUnread ? 'font-semibold' : '' ?> text-gray-800">
                    <?= escape_html($notification['title'] ?? 'Notification') ?>
                </p>
                <?php if (!empty($notification['actor_name'])): ?>
                    <p class="text-xs text-gray-500 mt-0.5">
                        par <a href="profil.php?id=<?= $notification['actor_id'] ?>" class="text-primary hover:underline font-medium">
                            @<?= escape_html($notification['actor_name']) ?>
                        </a>
                    </p>
                <?php endif; ?>
            </div>
            
            <!-- Actions -->
            <div class="flex items-center gap-2 flex-shrink-0">
                <?php if (!empty($notification['link'])): ?>
                    <a href="<?= escape_html($notification['link']) ?>" 
                       class="p-2 text-primary hover:bg-orange-50 rounded-lg transition-colors"
                       title="Voir">
                        <i class="fas fa-external-link-alt"></i>
                    </a>
                <?php endif; ?>
                
                <button onclick="deleteNotification(<?= $notification['id'] ?? 0 ?>)" 
                        class="p-2 text-gray-400 hover:text-red-500 hover:bg-red-50 rounded-lg transition-colors"
                        title="Supprimer">
                    <i class="fas fa-times"></i>
                </button>
                
                <?php if ($isUnread): ?>
                    <button onclick="markAsRead(<?= $notification['id'] ?? 0 ?>)" 
                            class="p-2 text-gray-400 hover:text-green-500 hover:bg-green-50 rounded-lg transition-colors"
                            title="Marquer comme lu">
                        <i class="fas fa-check-double"></i>
                    </button>
                <?php endif; ?>
            </div>
        </div>
        
        <?php if (!empty($notification['content'])): ?>
            <p class="text-gray-600 text-sm mt-2 line-clamp-2">
                <?= escape_html($notification['content']) ?>
            </p>
        <?php endif; ?>
        
        <p class="text-gray-400 text-xs mt-2 flex items-center gap-1">
            <i class="far fa-clock"></i>
            <?= $timeAgo ?>
        </p>
    </div>
    
    <?php if ($isUnread): ?>
        <span class="w-2 h-2 bg-primary rounded-full flex-shrink-0 mt-2" title="Non lu"></span>
    <?php endif; ?>
</div>

<script>
// Fonctions pour les notifications (à définir dans le JS principal)
function deleteNotification(notificationId) {
    if (confirm('Supprimer cette notification ?')) {
        if (typeof window.deleteNotification === 'function') {
            window.deleteNotification(notificationId);
        }
    }
}

function markAsRead(notificationId) {
    if (typeof window.markNotificationAsRead === 'function') {
        window.markNotificationAsRead(notificationId);
    }
}
</script>