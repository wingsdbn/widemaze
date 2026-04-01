<?php
/**
 * WideMaze - User Card Component
 * Affiche un utilisateur avec son avatar et boutons d'action
 * 
 * Variables attendues:
 * - $user: array contenant les données de l'utilisateur
 * - $currentUserId: int ID de l'utilisateur connecté
 * - $showActions: bool (default true) Afficher les boutons d'action
 * - $friendshipStatus: string (none, pending_sent, pending_received, friends)
 */
 
$user = $user ?? [];
$currentUserId = $currentUserId ?? $_SESSION['user_id'] ?? 0;
$showActions = $showActions ?? true;
$friendshipStatus = $friendshipStatus ?? 'none';
$isCurrentUser = ($user['id'] ?? 0) == $currentUserId;
?>

<div class="flex items-center gap-3 p-3 hover:bg-gray-50 rounded-xl transition-colors group">
    <!-- Avatar -->
    <a href="profil.php?id=<?= $user['id'] ?? 0 ?>" class="relative flex-shrink-0">
        <img src="<?= get_avatar_url($user['avatar'] ?? '') ?>" 
             class="w-12 h-12 rounded-full object-cover border-2 border-gray-200 group-hover:border-primary transition-colors">
        <?php if (($user['status'] ?? '') === 'Online'): ?>
            <span class="absolute bottom-0 right-0 w-3 h-3 bg-green-500 rounded-full border-2 border-white"></span>
        <?php endif; ?>
    </a>
    
    <!-- Info -->
    <div class="flex-1 min-w-0">
        <div class="flex items-center gap-2">
            <a href="profil.php?id=<?= $user['id'] ?? 0 ?>" 
               class="font-semibold text-gray-800 hover:text-primary transition-colors truncate">
                <?= escape_html(($user['prenom'] ?? '') . ' ' . ($user['nom'] ?? '')) ?>
            </a>
            <?php if (!empty($user['is_verified'])): ?>
                <i class="fas fa-check-circle text-blue-500 text-xs" title="Compte vérifié"></i>
            <?php endif; ?>
        </div>
        <p class="text-xs text-gray-500 truncate">@<?= escape_html($user['surnom'] ?? '') ?></p>
        <?php if (!empty($user['universite'])): ?>
            <p class="text-xs text-gray-400 truncate">
                <i class="fas fa-university mr-1"></i><?= escape_html($user['universite']) ?>
            </p>
        <?php endif; ?>
        
        <?php if (isset($user['mutual_friends']) && $user['mutual_friends'] > 0): ?>
            <p class="text-xs text-primary mt-1">
                <?= $user['mutual_friends'] ?> ami(s) en commun
            </p>
        <?php endif; ?>
    </div>
    
    <!-- Actions -->
    <?php if ($showActions && !$isCurrentUser): ?>
        <div class="flex-shrink-0">
            <?php if ($friendshipStatus === 'none'): ?>
                <button onclick="sendFriendRequest(<?= $user['id'] ?? 0 ?>, this)" 
                        class="w-9 h-9 bg-primary/10 hover:bg-primary text-primary hover:text-white rounded-full flex items-center justify-center transition-all">
                    <i class="fas fa-user-plus text-sm"></i>
                </button>
                
            <?php elseif ($friendshipStatus === 'pending_sent'): ?>
                <span class="inline-flex items-center gap-1 px-3 py-1.5 bg-gray-100 text-gray-500 rounded-full text-xs">
                    <i class="fas fa-clock"></i> En attente
                </span>
                
            <?php elseif ($friendshipStatus === 'pending_received'): ?>
                <div class="flex gap-1">
                    <button onclick="acceptFriendRequest(<?= $user['id'] ?? 0 ?>)" 
                            class="w-8 h-8 bg-green-500 hover:bg-green-600 text-white rounded-full flex items-center justify-center transition-all">
                        <i class="fas fa-check text-xs"></i>
                    </button>
                    <button onclick="rejectFriendRequest(<?= $user['id'] ?? 0 ?>)" 
                            class="w-8 h-8 bg-red-500 hover:bg-red-600 text-white rounded-full flex items-center justify-center transition-all">
                        <i class="fas fa-times text-xs"></i>
                    </button>
                </div>
                
            <?php elseif ($friendshipStatus === 'friends'): ?>
                <span class="inline-flex items-center gap-1 px-3 py-1.5 bg-green-100 text-green-600 rounded-full text-xs">
                    <i class="fas fa-check-circle"></i> Amis
                </span>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<script>
// Fonctions d'action pour les amis (à définir dans le JS principal)
function sendFriendRequest(userId, btn) {
    if (typeof window.sendFriendRequest === 'function') {
        window.sendFriendRequest(userId, btn);
    }
}

function acceptFriendRequest(userId) {
    if (typeof window.acceptFriendRequest === 'function') {
        window.acceptFriendRequest(userId);
    }
}

function rejectFriendRequest(userId) {
    if (typeof window.rejectFriendRequest === 'function') {
        window.rejectFriendRequest(userId);
    }
}
</script>