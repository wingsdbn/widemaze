<?php
/**
 * WideMaze - Sidebar Template
 * Left sidebar for navigation and user info
 */
?>

<!-- Left Sidebar -->
<aside class="hidden lg:block lg:col-span-3 space-y-4 sticky top-24 h-fit">
    <!-- User Card -->
    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5 hover:shadow-md transition-shadow">
        <div class="flex items-center gap-4 mb-4">
            <div class="relative">
                <img src="<?= get_avatar_url($currentUser['avatar'] ?? '') ?>" 
                     class="w-14 h-14 rounded-full object-cover border-2 border-primary/20">
                <span class="absolute bottom-0 right-0 w-3.5 h-3.5 bg-green-500 border-2 border-white rounded-full"></span>
            </div>
            <div>
                <h3 class="font-bold text-gray-800"><?= escape_html($currentUser['surnom'] ?? '') ?></h3>
                <p class="text-xs text-gray-500"><?= escape_html($currentUser['universite'] ?? 'Université non spécifiée') ?></p>
            </div>
        </div>
        
        <div class="grid grid-cols-3 gap-2 text-center border-t border-gray-100 pt-4">
            <div class="cursor-pointer hover:bg-gray-50 rounded-lg p-2 transition-colors" onclick="location.href='profil.php?tab=posts'">
                <p class="font-bold text-lg text-gray-800"><?= number_format($currentUser['posts_count'] ?? 0) ?></p>
                <p class="text-xs text-gray-500">Posts</p>
            </div>
            <div class="cursor-pointer hover:bg-gray-50 rounded-lg p-2 transition-colors" onclick="location.href='profil.php?tab=friends'">
                <p class="font-bold text-lg text-gray-800"><?= number_format($currentUser['friends_count'] ?? 0) ?></p>
                <p class="text-xs text-gray-500">Amis</p>
            </div>
            <div class="cursor-pointer hover:bg-gray-50 rounded-lg p-2 transition-colors" onclick="location.href='communautes.php'">
                <p class="font-bold text-lg text-gray-800"><?= number_format($currentUser['communities_count'] ?? 0) ?></p>
                <p class="text-xs text-gray-500">Commu.</p>
            </div>
        </div>
    </div>
    
    <!-- Navigation Menu -->
    <nav class="bg-white rounded-2xl shadow-sm border border-gray-100 p-3">
        <a href="index.php" class="flex items-center gap-4 px-5 py-3.5 hover:bg-gray-50 text-gray-700 transition-colors rounded-xl">
            <div class="w-10 h-10 bg-orange-100 rounded-xl flex items-center justify-center">
                <i class="fas fa-home text-orange-600"></i>
            </div>
            <span>Accueil</span>
        </a>
        
        <a href="profil.php" class="flex items-center gap-4 px-5 py-3.5 hover:bg-gray-50 text-gray-700 transition-colors rounded-xl">
            <div class="w-10 h-10 bg-blue-100 rounded-xl flex items-center justify-center">
                <i class="fas fa-user text-blue-600"></i>
            </div>
            <span>Mon Profil</span>
        </a>
        
        <a href="notifications.php" class="flex items-center gap-4 px-5 py-3.5 hover:bg-gray-50 text-gray-700 transition-colors rounded-xl">
            <div class="w-10 h-10 bg-red-100 rounded-xl flex items-center justify-center relative">
                <i class="fas fa-bell text-red-600"></i>
                <?php if (($currentUser['unread_notifications'] ?? 0) > 0): ?>
                    <span class="absolute -top-1 -right-1 w-5 h-5 bg-red-500 text-white text-xs rounded-full flex items-center justify-center">
                        <?= min($currentUser['unread_notifications'], 99) ?>
                    </span>
                <?php endif; ?>
            </div>
            <span>Notifications</span>
        </a>
        
        <a href="messagerie.php" class="flex items-center gap-4 px-5 py-3.5 hover:bg-gray-50 text-gray-700 transition-colors rounded-xl">
            <div class="w-10 h-10 bg-green-100 rounded-xl flex items-center justify-center">
                <i class="fas fa-comment-dots text-green-600"></i>
            </div>
            <span>Messagerie</span>
        </a>
        
        <a href="communautes.php" class="flex items-center gap-4 px-5 py-3.5 hover:bg-gray-50 text-gray-700 transition-colors rounded-xl">
            <div class="w-10 h-10 bg-purple-100 rounded-xl flex items-center justify-center">
                <i class="fas fa-users text-purple-600"></i>
            </div>
            <span>Communautés</span>
        </a>
        
        <a href="recherche.php" class="flex items-center gap-4 px-5 py-3.5 hover:bg-gray-50 text-gray-700 transition-colors rounded-xl">
            <div class="w-10 h-10 bg-teal-100 rounded-xl flex items-center justify-center">
                <i class="fas fa-search text-teal-600"></i>
            </div>
            <span>Recherche</span>
        </a>
        
        <a href="parametres.php" class="flex items-center gap-4 px-5 py-3.5 hover:bg-gray-50 text-gray-700 transition-colors rounded-xl">
            <div class="w-10 h-10 bg-gray-100 rounded-xl flex items-center justify-center">
                <i class="fas fa-cog text-gray-600"></i>
            </div>
            <span>Paramètres</span>
        </a>
        
        <?php if (is_admin()): ?>
            <a href="pages/admin.php" class="flex items-center gap-4 px-5 py-3.5 hover:bg-red-50 text-red-600 transition-colors rounded-xl">
                <div class="w-10 h-10 bg-red-100 rounded-xl flex items-center justify-center">
                    <i class="fas fa-shield-alt text-red-600"></i>
                </div>
                <span>Administration</span>
                <?php
                $reportCount = 0;
                try {
                    $stmt = $pdo->query("SELECT COUNT(*) FROM posts WHERE is_reported = 1");
                    $reportCount = $stmt->fetchColumn();
                } catch (PDOException $e) {}
                ?>
                <?php if ($reportCount > 0): ?>
                    <span class="ml-auto bg-red-500 text-white text-xs px-2 py-1 rounded-full animate-pulse">
                        <?= min($reportCount, 99) ?>
                    </span>
                <?php endif; ?>
            </a>
        <?php endif; ?>
    </nav>
    
    <!-- Footer Links -->
    <div class="text-xs text-gray-400 px-4 space-y-1">
        <div class="flex flex-wrap gap-x-3 gap-y-1">
            <a href="#" class="hover:text-gray-600">À propos</a>
            <a href="#" class="hover:text-gray-600">Confidentialité</a>
            <a href="#" class="hover:text-gray-600">Conditions</a>
            <a href="#" class="hover:text-gray-600">Aide</a>
        </div>
        <p>&copy; <?= date('Y') ?> WideMaze</p>
    </div>
</aside>