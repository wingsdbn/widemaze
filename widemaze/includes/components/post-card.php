<?php
/**
 * WideMaze - Post Card Component
 * Affiche une publication avec ses interactions (like, commentaire, partage)
 * 
 * Variables attendues:
 * - $post: array contenant les données de la publication
 * - $currentUserId: int ID de l'utilisateur connecté
 * - $showActions: bool (default true) Afficher les boutons d'interaction
 * - $isOwnProfile: bool (default false) Si on est sur le profil de l'auteur
 */
 
$post = $post ?? [];
$currentUserId = $currentUserId ?? $_SESSION['user_id'] ?? 0;
$showActions = $showActions ?? true;
$isOwnProfile = $isOwnProfile ?? false;

// Formatage de la date
$timeAgo = time_ago($post['date_publication'] ?? '');
$privacyIcon = [
    'public' => '<i class="fas fa-globe text-xs" title="Public"></i>',
    'friends' => '<i class="fas fa-user-friends text-xs" title="Amis uniquement"></i>',
    'private' => '<i class="fas fa-lock text-xs" title="Privé"></i>'
][$post['privacy'] ?? 'public'] ?? '<i class="fas fa-globe text-xs"></i>';

$likeClass = ($post['user_liked'] ?? false) ? 'text-red-500 fas' : 'text-gray-400 far';
$likeIcon = ($post['user_liked'] ?? false) ? 'fa-heart' : 'fa-heart';
?>

<article class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden hover:shadow-md transition-all duration-300 animate-fade-in" data-post-id="<?= $post['idpost'] ?? 0 ?>">
    <!-- Header -->
    <div class="p-5 flex items-center justify-between">
        <div class="flex items-center gap-3">
            <a href="profil.php?id=<?= $post['id_utilisateur'] ?? 0 ?>" class="relative">
                <img src="<?= get_avatar_url($post['avatar'] ?? '') ?>" 
                     class="w-12 h-12 rounded-full object-cover border-2 border-gray-100 hover:border-primary transition-colors">
            </a>
            <div>
                <a href="profil.php?id=<?= $post['id_utilisateur'] ?? 0 ?>" 
                   class="font-bold text-gray-800 hover:text-primary transition-colors block">
                    <?= escape_html($post['surnom'] ?? 'Utilisateur') ?>
                </a>
                <div class="flex items-center gap-2 text-xs text-gray-500">
                    <span><?= $timeAgo ?></span>
                    <span>•</span>
                    <span><?= $privacyIcon ?></span>
                    <?php if (!empty($post['edited_at'])): ?>
                        <span class="text-gray-400">(modifié)</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <?php if (($post['id_utilisateur'] ?? 0) == $currentUserId || is_admin()): ?>
            <div class="relative group">
                <button class="p-2 hover:bg-gray-100 rounded-full transition-colors">
                    <i class="fas fa-ellipsis-h text-gray-400"></i>
                </button>
                <div class="absolute right-0 top-full w-48 bg-white rounded-xl shadow-xl border border-gray-100 opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all z-10 dropdown-enter">
                    <?php if (($post['id_utilisateur'] ?? 0) == $currentUserId): ?>
                        <button onclick="editPost(<?= $post['idpost'] ?? 0 ?>)" 
                                class="w-full text-left px-4 py-2.5 hover:bg-gray-50 text-gray-700 flex items-center gap-2 rounded-t-xl">
                            <i class="fas fa-pen text-gray-400"></i>Modifier
                        </button>
                    <?php endif; ?>
                    <button onclick="deletePost(<?= $post['idpost'] ?? 0 ?>)" 
                            class="w-full text-left px-4 py-2.5 hover:bg-red-50 text-red-600 flex items-center gap-2 <?= ($post['id_utilisateur'] ?? 0) == $currentUserId ? '' : 'rounded-t-xl' ?>">
                        <i class="fas fa-trash text-red-400"></i>Supprimer
                    </button>
                </div>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- Content -->
    <div class="px-5 pb-3">
        <p class="text-gray-800 whitespace-pre-wrap"><?= nl2br(escape_html($post['contenu'] ?? '')) ?></p>
    </div>
    
    <!-- Image -->
    <?php if (!empty($post['image_post'])): ?>
        <div class="relative group cursor-pointer" onclick="openImageModal('<?= POSTS_URL . escape_html($post['image_post']) ?>')">
            <img src="<?= POSTS_URL . escape_html($post['image_post']) ?>" 
                 class="w-full max-h-[500px] object-cover" 
                 loading="lazy"
                 alt="Image de publication">
            <div class="absolute inset-0 bg-black/0 group-hover:bg-black/10 transition-colors"></div>
        </div>
    <?php endif; ?>
    
    <!-- Stats -->
    <div class="px-5 py-3 flex items-center justify-between text-sm text-gray-500 border-t border-gray-100">
        <div class="flex items-center gap-1">
            <span class="w-5 h-5 bg-red-500 rounded-full flex items-center justify-center text-white text-xs">
                <i class="fas fa-heart"></i>
            </span>
            <span id="likes-count-<?= $post['idpost'] ?? 0 ?>"><?= number_format($post['likes_count'] ?? 0) ?></span>
        </div>
        <div class="flex gap-4">
            <span class="hover:text-primary cursor-pointer transition-colors">
                <?= number_format($post['comments_count'] ?? 0) ?> commentaires
            </span>
        </div>
    </div>
    
    <?php if ($showActions): ?>
        <!-- Actions -->
        <div class="px-5 py-2 flex justify-between border-t border-gray-100">
            <button onclick="toggleLike(<?= $post['idpost'] ?? 0 ?>)" 
                    id="like-btn-<?= $post['idpost'] ?? 0 ?>" 
                    class="flex-1 flex items-center justify-center gap-2 py-2.5 hover:bg-gray-50 rounded-xl transition-colors <?= ($post['user_liked'] ?? false) ? 'text-red-500' : 'text-gray-600' ?>">
                <i class="<?= $likeClass ?> fa-heart text-lg transition-transform" id="like-icon-<?= $post['idpost'] ?? 0 ?>"></i>
                <span class="font-semibold text-sm">J'aime</span>
            </button>
            <button onclick="toggleComments(<?= $post['idpost'] ?? 0 ?>)" 
                    class="flex-1 flex items-center justify-center gap-2 py-2.5 hover:bg-gray-50 rounded-xl text-gray-600 transition-colors">
                <i class="far fa-comment-alt text-lg"></i>
                <span class="font-semibold text-sm">Commenter</span>
            </button>
            <button onclick="sharePost(<?= $post['idpost'] ?? 0 ?>)" 
                    class="flex-1 flex items-center justify-center gap-2 py-2.5 hover:bg-gray-50 rounded-xl text-gray-600 transition-colors">
                <i class="fas fa-share text-lg"></i>
                <span class="font-semibold text-sm">Partager</span>
            </button>
        </div>
        
        <!-- Comments Section -->
        <div id="comments-<?= $post['idpost'] ?? 0 ?>" class="hidden px-5 py-3 bg-gray-50 border-t border-gray-100">
            <div class="flex gap-3">
                <img src="<?= get_avatar_url($_SESSION['avatar'] ?? '') ?>" class="w-8 h-8 rounded-full">
                <div class="flex-1 relative">
                    <input type="text" 
                           placeholder="Écrire un commentaire..." 
                           class="w-full px-4 py-2 pr-12 rounded-full bg-white border border-gray-200 focus:border-primary focus:ring-2 focus:ring-primary/20 outline-none text-sm"
                           onkeypress="if(event.key=='Enter') submitComment(<?= $post['idpost'] ?? 0 ?>, this.value)">
                    <button onclick="submitComment(<?= $post['idpost'] ?? 0 ?>, this.previousElementSibling.value)" 
                            class="absolute right-2 top-1/2 -translate-y-1/2 w-8 h-8 bg-primary text-white rounded-full flex items-center justify-center hover:bg-orange-600 transition-colors">
                        <i class="fas fa-paper-plane text-xs"></i>
                    </button>
                </div>
            </div>
            <div id="comments-list-<?= $post['idpost'] ?? 0 ?>" class="mt-3 space-y-2">
                <!-- Comments will be loaded here -->
            </div>
        </div>
    <?php endif; ?>
</article>

<script>
// Fonctions spécifiques au post (à définir dans le JS principal)
function toggleLike(postId) {
    if (typeof window.toggleLike === 'function') {
        window.toggleLike(postId);
    }
}

function submitComment(postId, content) {
    if (typeof window.submitComment === 'function') {
        window.submitComment(postId, content);
    }
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
    if (typeof window.editPost === 'function') {
        window.editPost(postId);
    }
}

function deletePost(postId) {
    if (confirm('Voulez-vous vraiment supprimer cette publication ?')) {
        if (typeof window.deletePost === 'function') {
            window.deletePost(postId);
        }
    }
}

function openImageModal(imageUrl) {
    window.open(imageUrl, '_blank');
}
</script>