<?php

/**
 * WideMaze Control Center (WCC)
 * Tableau de bord administratif et de surveillance du réseau social académique
 * Version 5.0 - Assistant IA avancé, monitoring complet, diagnostics
 * Objectif: Réunir étudiants et institutions universitaires mondiales
 */

// Démarrer la session si elle n'est pas déjà active
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

// Vérification des droits administrateur (ou accès technique)
$isAdmin = isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
$isLoggedIn = isset($_SESSION['user_id']);
$currentUserId = $_SESSION['user_id'] ?? null;

// Si non connecté, rediriger vers la page de connexion
if (!$isLoggedIn) {
    header('Location: pages/connexion.php');
    exit();
}

// ============================================================
// ANALYSE INTELLIGENTE DU PROJET - ASSISTANT IA
// ============================================================

// Liste complète des pages requises avec leurs métadonnées
$requiredPages = [
    'index.php' => ['name' => 'Accueil', 'status' => 'critical', 'desc' => 'Page principale du flux', 'module' => 'Core', 'dependencies' => [], 'type' => 'frontend'],
    'pages/connexion.php' => ['name' => 'Connexion', 'status' => 'critical', 'desc' => 'Authentification utilisateurs', 'module' => 'Auth', 'dependencies' => [], 'type' => 'frontend'],
    'pages/inscription.php' => ['name' => 'Inscription', 'status' => 'critical', 'desc' => 'Création de comptes', 'module' => 'Auth', 'dependencies' => [], 'type' => 'frontend'],
    'pages/deconnexion.php' => ['name' => 'Déconnexion', 'status' => 'critical', 'desc' => 'Fermeture de session', 'module' => 'Auth', 'dependencies' => [], 'type' => 'frontend'],
    'pages/profil.php' => ['name' => 'Profil', 'status' => 'critical', 'desc' => 'Profils utilisateurs', 'module' => 'User', 'dependencies' => [], 'type' => 'frontend'],
    'pages/parametres.php' => ['name' => 'Paramètres', 'status' => 'high', 'desc' => 'Configuration utilisateur', 'module' => 'User', 'dependencies' => [], 'type' => 'frontend'],
    'pages/notifications.php' => ['name' => 'Notifications', 'status' => 'high', 'desc' => 'Centre de notifications', 'module' => 'Social', 'dependencies' => [], 'type' => 'frontend'],
    'pages/messagerie.php' => ['name' => 'Messagerie', 'status' => 'high', 'desc' => 'Chat entre utilisateurs', 'module' => 'Social', 'dependencies' => ['api/messages.php'], 'type' => 'frontend'],
    'pages/communautes.php' => ['name' => 'Communautés', 'status' => 'high', 'desc' => 'Groupes académiques', 'module' => 'Community', 'dependencies' => ['api/communities.php'], 'type' => 'frontend'],
    'pages/communaute.php' => ['name' => 'Communauté (détail)', 'status' => 'high', 'desc' => 'Page détaillée d\'une communauté', 'module' => 'Community', 'dependencies' => [], 'type' => 'frontend'],
    'pages/recherche.php' => ['name' => 'Recherche', 'status' => 'high', 'desc' => 'Recherche globale', 'module' => 'Search', 'dependencies' => [], 'type' => 'frontend'],
    'pages/admin.php' => ['name' => 'Administration', 'status' => 'high', 'desc' => 'Panel admin avancé', 'module' => 'Admin', 'dependencies' => [], 'type' => 'frontend'],
    'pages/passwordrecover.php' => ['name' => 'Récupération MDP', 'status' => 'medium', 'desc' => 'Demande de réinitialisation', 'module' => 'Auth', 'dependencies' => [], 'type' => 'frontend'],
    'pages/reset_password.php' => ['name' => 'Reset MDP', 'status' => 'medium', 'desc' => 'Nouveau mot de passe', 'module' => 'Auth', 'dependencies' => [], 'type' => 'frontend'],
    'wcc.php' => ['name' => 'Control Center', 'status' => 'completed', 'desc' => 'Tableau de bord technique', 'module' => 'Admin', 'dependencies' => [], 'type' => 'admin'],
    'pages/conditions.php' => ['name' => 'Conditions', 'status' => 'low', 'desc' => 'Conditions d\'utilisation', 'module' => 'Legal', 'dependencies' => [], 'type' => 'static'],
    'pages/confidentialite.php' => ['name' => 'Confidentialité', 'status' => 'low', 'desc' => 'Politique de confidentialité', 'module' => 'Legal', 'dependencies' => [], 'type' => 'static'],
    'pages/about.php' => ['name' => 'À propos', 'status' => 'low', 'desc' => 'Page d\'information', 'module' => 'Legal', 'dependencies' => [], 'type' => 'static'],
];

// Liste des API endpoints requis
$requiredApis = [
    'api/posts.php' => ['name' => 'Posts API', 'status' => 'completed', 'desc' => 'CRUD publications', 'module' => 'API', 'methods' => 'GET, POST, PUT, DELETE'],
    'api/friends.php' => ['name' => 'Friends API', 'status' => 'completed', 'desc' => 'Gestion des amis', 'module' => 'API', 'methods' => 'GET, POST'],
    'api/notifications.php' => ['name' => 'Notifications API', 'status' => 'completed', 'desc' => 'Notifications', 'module' => 'API', 'methods' => 'GET, POST, DELETE'],
    'api/search.php' => ['name' => 'Search API', 'status' => 'completed', 'desc' => 'Recherche globale', 'module' => 'API', 'methods' => 'GET'],
    'api/upload.php' => ['name' => 'Upload API', 'status' => 'completed', 'desc' => 'Gestion des fichiers', 'module' => 'API', 'methods' => 'POST, DELETE'],
    'api/users.php' => ['name' => 'Users API', 'status' => 'completed', 'desc' => 'Gestion utilisateurs', 'module' => 'API', 'methods' => 'GET, POST'],
    'api/communities.php' => ['name' => 'Communities API', 'status' => 'completed', 'desc' => 'Gestion des communautés', 'module' => 'API', 'methods' => 'GET, POST, DELETE'],
    'api/messages.php' => ['name' => 'Messages API', 'status' => 'completed', 'desc' => 'Messages instantanés', 'module' => 'API', 'methods' => 'GET, POST, DELETE, PUT'],
];

// Tables de base de données requises
$requiredTables = [
    'utilisateurs' => ['desc' => 'Utilisateurs du réseau', 'module' => 'Core', 'status' => 'completed', 'records' => 0],
    'posts' => ['desc' => 'Publications', 'module' => 'Core', 'status' => 'completed', 'records' => 0],
    'postlike' => ['desc' => 'Likes', 'module' => 'Core', 'status' => 'completed', 'records' => 0],
    'postcommentaire' => ['desc' => 'Commentaires', 'module' => 'Core', 'status' => 'completed', 'records' => 0],
    'ami' => ['desc' => 'Relations d\'amitié', 'module' => 'Core', 'status' => 'completed', 'records' => 0],
    'message' => ['desc' => 'Messages privés', 'module' => 'Core', 'status' => 'completed', 'records' => 0],
    'notifications' => ['desc' => 'Notifications', 'module' => 'Core', 'status' => 'completed', 'records' => 0],
    'communautes' => ['desc' => 'Communautés académiques', 'module' => 'Community', 'status' => 'completed', 'records' => 0],
    'communaute_membres' => ['desc' => 'Membres des communautés', 'module' => 'Community', 'status' => 'completed', 'records' => 0],
    'stories' => ['desc' => 'Stories (24h)', 'module' => 'Social', 'status' => 'completed', 'records' => 0],
    'story_views' => ['desc' => 'Vues des stories', 'module' => 'Social', 'status' => 'completed', 'records' => 0],
    'ressources' => ['desc' => 'Ressources partagées', 'module' => 'Resources', 'status' => 'completed', 'records' => 0],
    'activity_logs' => ['desc' => 'Journaux d\'activité', 'module' => 'Admin', 'status' => 'completed', 'records' => 0],
    'search_history' => ['desc' => 'Historique de recherche', 'module' => 'Search', 'status' => 'completed', 'records' => 0],
    'password_resets' => ['desc' => 'Réinitialisations MDP', 'module' => 'Auth', 'status' => 'completed', 'records' => 0],
    'user_preferences' => ['desc' => 'Préférences utilisateur', 'module' => 'User', 'status' => 'missing', 'records' => 0],
    'post_reports' => ['desc' => 'Signalements', 'module' => 'Admin', 'status' => 'missing', 'records' => 0],
    'password_reset_attempts' => ['desc' => 'Tentatives de récupération', 'module' => 'Auth', 'status' => 'missing', 'records' => 0],
];

// Dossiers requis
$requiredDirs = [
    'uploads/' => ['name' => 'Uploads', 'desc' => 'Fichiers utilisateurs', 'status' => 'critical', 'permissions' => '755'],
    'uploads/avatars/' => ['name' => 'Avatars', 'desc' => 'Photos de profil', 'status' => 'critical', 'permissions' => '755'],
    'uploads/posts/' => ['name' => 'Posts', 'desc' => 'Images des publications', 'status' => 'critical', 'permissions' => '755'],
    'uploads/covers/' => ['name' => 'Covers', 'desc' => 'Photos de couverture', 'status' => 'critical', 'permissions' => '755'],
    'uploads/documents/' => ['name' => 'Documents', 'desc' => 'Ressources académiques', 'status' => 'high', 'permissions' => '755'],
    'uploads/messages/' => ['name' => 'Messages', 'desc' => 'Fichiers partagés', 'status' => 'high', 'permissions' => '755'],
    'logs/' => ['name' => 'Logs', 'desc' => 'Journaux système', 'status' => 'critical', 'permissions' => '755'],
    'api/' => ['name' => 'API', 'desc' => 'Endpoints API', 'status' => 'high', 'permissions' => '755'],
    'assets/css/' => ['name' => 'CSS', 'desc' => 'Feuilles de style', 'status' => 'medium', 'permissions' => '755'],
    'assets/js/' => ['name' => 'JavaScript', 'desc' => 'Scripts JS', 'status' => 'medium', 'permissions' => '755'],
    'assets/images/' => ['name' => 'Images', 'desc' => 'Images statiques', 'status' => 'low', 'permissions' => '755'],
    'includes/templates/' => ['name' => 'Templates', 'desc' => 'Templates HTML', 'status' => 'high', 'permissions' => '755'],
    'includes/components/' => ['name' => 'Components', 'desc' => 'Composants réutilisables', 'status' => 'high', 'permissions' => '755'],
];

$activeTab = $_GET['tab'] ?? 'overview';
// Initialisation des variables
$dbError = null;
$stats = [];
$topUniversities = [];
$topCountries = [];
$recentUsers = [];
$recentActivity = [];
$systemErrors = [];
$serverInfo = [];

// ============================================================
// RÉCUPÉRATION DES STATISTIQUES
// ============================================================

try {
    if (isset($pdo)) {
        // Statistiques utilisateurs
        $stats['users'] = $pdo->query("SELECT COUNT(*) FROM utilisateurs WHERE is_active = 1")->fetchColumn() ?: 0;
        $stats['users_total'] = $pdo->query("SELECT COUNT(*) FROM utilisateurs")->fetchColumn() ?: 0;
        $stats['users_today'] = $pdo->query("SELECT COUNT(*) FROM utilisateurs WHERE DATE(dateinscription) = CURDATE()")->fetchColumn() ?: 0;
        $stats['users_week'] = $pdo->query("SELECT COUNT(*) FROM utilisateurs WHERE dateinscription > DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn() ?: 0;
        $stats['online'] = $pdo->query("SELECT COUNT(*) FROM utilisateurs WHERE status = 'Online'")->fetchColumn() ?: 0;
        $stats['verified'] = $pdo->query("SELECT COUNT(*) FROM utilisateurs WHERE is_verified = 1")->fetchColumn() ?: 0;
        
        // Statistiques publications
        $stats['posts'] = $pdo->query("SELECT COUNT(*) FROM posts")->fetchColumn() ?: 0;
        $stats['posts_today'] = $pdo->query("SELECT COUNT(*) FROM posts WHERE DATE(date_publication) = CURDATE()")->fetchColumn() ?: 0;
        $stats['posts_week'] = $pdo->query("SELECT COUNT(*) FROM posts WHERE date_publication > DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn() ?: 0;
        $stats['comments'] = $pdo->query("SELECT COUNT(*) FROM postcommentaire")->fetchColumn() ?: 0;
        $stats['likes'] = $pdo->query("SELECT COUNT(*) FROM postlike")->fetchColumn() ?: 0;
        
        // Statistiques social
        $stats['friends'] = $pdo->query("SELECT COUNT(*) FROM ami WHERE accepterami = 1")->fetchColumn() ?: 0;
        $stats['pending_friends'] = $pdo->query("SELECT COUNT(*) FROM ami WHERE demandeami = 1 AND accepterami = 0")->fetchColumn() ?: 0;
        $stats['communities'] = $pdo->query("SELECT COUNT(*) FROM communautes")->fetchColumn() ?: 0;
        $stats['community_members'] = $pdo->query("SELECT COUNT(*) FROM communaute_membres")->fetchColumn() ?: 0;
        
        // Statistiques messages
        $stats['messages'] = $pdo->query("SELECT COUNT(*) FROM message")->fetchColumn() ?: 0;
        $stats['unread_messages'] = $pdo->query("SELECT COUNT(*) FROM message WHERE lu = 0")->fetchColumn() ?: 0;
        
        // Statistiques notifications
        $stats['notifications'] = $pdo->query("SELECT COUNT(*) FROM notifications")->fetchColumn() ?: 0;
        $stats['unread_notif'] = $pdo->query("SELECT COUNT(*) FROM notifications WHERE is_read = 0")->fetchColumn() ?: 0;
        
        // Statistiques stories
        $stats['stories'] = $pdo->query("SELECT COUNT(*) FROM stories WHERE expires_at > NOW()")->fetchColumn() ?: 0;
        
        // Statistiques ressources
        $stats['resources'] = $pdo->query("SELECT COUNT(*) FROM ressources")->fetchColumn() ?: 0;
        
        // Statistiques divers
        $stats['universities'] = $pdo->query("SELECT COUNT(DISTINCT universite) FROM utilisateurs WHERE universite IS NOT NULL AND universite != ''")->fetchColumn() ?: 0;
        $stats['countries'] = $pdo->query("SELECT COUNT(DISTINCT nationalite) FROM utilisateurs WHERE nationalite IS NOT NULL AND nationalite != ''")->fetchColumn() ?: 0;
        $stats['active_logs'] = $pdo->query("SELECT COUNT(*) FROM activity_logs WHERE created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)")->fetchColumn() ?: 0;
        
        // Top universités
        $topUniversities = $pdo->query("SELECT universite, COUNT(*) as count FROM utilisateurs WHERE universite IS NOT NULL AND universite != '' AND is_active = 1 GROUP BY universite ORDER BY count DESC LIMIT 10")->fetchAll();
        
        // Top pays
        $topCountries = $pdo->query("SELECT nationalite, COUNT(*) as count FROM utilisateurs WHERE nationalite IS NOT NULL AND nationalite != '' AND is_active = 1 GROUP BY nationalite ORDER BY count DESC LIMIT 10")->fetchAll();
        
        // Utilisateurs récents
        $recentUsers = $pdo->query("SELECT id, surnom, email, prenom, nom, universite, nationalite, dateinscription, status, role, is_verified, avatar FROM utilisateurs ORDER BY dateinscription DESC LIMIT 10")->fetchAll();
        
        // Activité récente
        $recentActivity = $pdo->query("SELECT al.*, u.surnom, u.avatar FROM activity_logs al LEFT JOIN utilisateurs u ON al.user_id = u.id ORDER BY al.created_at DESC LIMIT 20")->fetchAll();
        
        // Statistiques par jour (7 derniers jours)
        $stats['daily_users'] = [];
        $dailyStmt = $pdo->query("
            SELECT DATE(dateinscription) as date, COUNT(*) as count 
            FROM utilisateurs 
            WHERE dateinscription > DATE_SUB(NOW(), INTERVAL 7 DAY)
            GROUP BY DATE(dateinscription)
            ORDER BY date
        ");
        $stats['daily_users'] = $dailyStmt->fetchAll();
        
        $stats['daily_posts'] = [];
        $dailyStmt = $pdo->query("
            SELECT DATE(date_publication) as date, COUNT(*) as count 
            FROM posts 
            WHERE date_publication > DATE_SUB(NOW(), INTERVAL 7 DAY)
            GROUP BY DATE(date_publication)
            ORDER BY date
        ");
        $stats['daily_posts'] = $dailyStmt->fetchAll();
        
        // Remplir les records count pour les tables
        foreach ($requiredTables as $table => &$info) {
            if ($info['status'] === 'completed') {
                try {
                    $countStmt = $pdo->query("SELECT COUNT(*) FROM `$table`");
                    $info['records'] = $countStmt->fetchColumn() ?: 0;
                } catch (PDOException $e) {
                    $info['records'] = 0;
                }
            }
        }
    }
} catch (PDOException $e) {
    $dbError = $e->getMessage();
}

// ============================================================
// VÉRIFICATION DES FICHIERS ET DOSSIERS
// ============================================================

// Vérification des pages existantes
$pageStatus = [];
foreach ($requiredPages as $file => $info) {
    $exists = file_exists($file);
    $size = $exists ? filesize($file) : 0;
    $modified = $exists ? date('Y-m-d H:i:s', filemtime($file)) : null;
    $pageStatus[$file] = array_merge($info, ['exists' => $exists, 'size' => $size, 'modified' => $modified]);
}

// Vérification des API
$apiStatus = [];
foreach ($requiredApis as $api => $info) {
    $exists = file_exists($api);
    $size = $exists ? filesize($api) : 0;
    $modified = $exists ? date('Y-m-d H:i:s', filemtime($api)) : null;
    $apiStatus[$api] = array_merge($info, ['exists' => $exists, 'size' => $size, 'modified' => $modified]);
}

// Vérification des dossiers
$dirStatus = [];
foreach ($requiredDirs as $dir => $info) {
    $exists = is_dir($dir);
    $writable = $exists ? is_writable($dir) : false;
    $size = $exists ? getDirectorySize($dir) : 0;
    $dirStatus[$dir] = array_merge($info, ['exists' => $exists, 'writable' => $writable, 'size' => $size]);
}

// Vérification des tables
$tableStatus = [];
try {
    if (isset($pdo)) {
        $existingTables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($requiredTables as $table => $info) {
            $tableStatus[$table] = [
                'desc' => $info['desc'],
                'module' => $info['module'],
                'exists' => in_array($table, $existingTables),
                'records' => $info['records'] ?? 0,
                'status' => $info['status']
            ];
        }
    }
} catch (Exception $e) {
    $tableStatus = [];
}

// ============================================================
// INFORMATIONS SERVEUR
// ============================================================

$serverInfo = [
    'php_version' => phpversion(),
    'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
    'server_protocol' => $_SERVER['SERVER_PROTOCOL'] ?? 'Unknown',
    'server_name' => $_SERVER['SERVER_NAME'] ?? 'Unknown',
    'document_root' => $_SERVER['DOCUMENT_ROOT'] ?? 'Unknown',
    'memory_limit' => ini_get('memory_limit'),
    'upload_max_filesize' => ini_get('upload_max_filesize'),
    'post_max_size' => ini_get('post_max_size'),
    'max_execution_time' => ini_get('max_execution_time'),
    'max_input_time' => ini_get('max_input_time'),
    'session_save_path' => session_save_path() ?: 'default',
    'mysql_version' => $pdo ? $pdo->query("SELECT VERSION()")->fetchColumn() : 'N/A',
];

// Calcul de l'utilisation mémoire
$serverInfo['memory_usage'] = memory_get_usage(true);
$serverInfo['memory_peak'] = memory_get_peak_usage(true);

// Temps d'exécution
$serverInfo['execution_time'] = microtime(true) - $_SERVER['REQUEST_TIME_FLOAT'];

// ============================================================
// CALCUL DES POURCENTAGES D'AVANCEMENT
// ============================================================

function calculateModuleProgress($files, $apis, $tables, $dirs) {
    $totalPages = count($files);
    $existingPages = 0;
    foreach ($files as $file => $info) {
        if (file_exists($file)) $existingPages++;
    }
    
    $totalApis = count($apis);
    $existingApis = 0;
    foreach ($apis as $api => $info) {
        if (file_exists($api)) $existingApis++;
    }
    
    $totalTables = count($tables);
    $existingTables = 0;
    foreach ($tables as $table => $info) {
        if ($info['exists'] ?? false) $existingTables++;
    }
    
    $totalDirs = count($dirs);
    $existingDirs = 0;
    $writableDirs = 0;
    foreach ($dirs as $dir => $info) {
        if (is_dir($dir)) {
            $existingDirs++;
            if (is_writable($dir)) $writableDirs++;
        }
    }
    
    $pagesProgress = ($totalPages > 0) ? round(($existingPages / $totalPages) * 100) : 0;
    $apisProgress = ($totalApis > 0) ? round(($existingApis / $totalApis) * 100) : 0;
    $tablesProgress = ($totalTables > 0) ? round(($existingTables / $totalTables) * 100) : 0;
    $dirsProgress = ($totalDirs > 0) ? round(($existingDirs / $totalDirs) * 100) : 0;
    
    $overallProgress = round(
        ($pagesProgress * 0.30) +
        ($apisProgress * 0.25) +
        ($tablesProgress * 0.25) +
        ($dirsProgress * 0.20)
    );
    
    return [
        'pages' => ['total' => $totalPages, 'existing' => $existingPages, 'progress' => $pagesProgress],
        'apis' => ['total' => $totalApis, 'existing' => $existingApis, 'progress' => $apisProgress],
        'tables' => ['total' => $totalTables, 'existing' => $existingTables, 'progress' => $tablesProgress],
        'dirs' => ['total' => $totalDirs, 'existing' => $existingDirs, 'writable' => $writableDirs, 'progress' => $dirsProgress],
        'overall' => $overallProgress
    ];
}

// ============================================================
// FONCTIONS UTILITAIRES
// ============================================================

function getDirectorySize($path) {
    $size = 0;
    if (!is_dir($path)) return 0;
    $files = scandir($path);
    foreach ($files as $file) {
        if ($file != '.' && $file != '..') {
            $fullPath = $path . '/' . $file;
            if (is_file($fullPath)) {
                $size += filesize($fullPath);
            } elseif (is_dir($fullPath)) {
                $size += getDirectorySize($fullPath);
            }
        }
    }
    return $size;
}

function formatBytes($bytes, $precision = 2) {
    if ($bytes == 0) return '0 B';
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = floor(log($bytes, 1024));
    return round($bytes / pow(1024, $i), $precision) . ' ' . $units[$i];
}

function getActivityIcon($action) {
    $icons = [
        'login_success' => 'sign-in-alt', 'login_failed' => 'exclamation-triangle',
        'register' => 'user-plus', 'logout' => 'sign-out-alt',
        'update_profile' => 'user-edit', 'update_avatar' => 'camera',
        'password_change' => 'key', 'post_created' => 'plus-circle',
        'post_deleted' => 'trash', 'like' => 'heart', 'comment' => 'comment',
        'friend_request' => 'user-friends', 'account_deleted' => 'user-slash',
        'search' => 'search', 'password_recovery' => 'key',
        'admin_action' => 'shield-alt', 'file_upload' => 'upload',
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
        'account_deleted' => 'a supprimé son compte', 'search' => 'a effectué une recherche',
        'password_recovery' => 'a demandé une réinitialisation', 'admin_action' => 'action administrative',
        'file_upload' => 'a téléchargé un fichier',
    ];
    return $labels[$action] ?? $action;
}

function getStatusColor($status) {
    $colors = [
        'critical' => 'bg-red-100 text-red-700',
        'high' => 'bg-orange-100 text-orange-700',
        'medium' => 'bg-yellow-100 text-yellow-700',
        'low' => 'bg-gray-100 text-gray-600',
        'completed' => 'bg-green-100 text-green-700',
        'planned' => 'bg-blue-100 text-blue-700',
        'missing' => 'bg-red-100 text-red-700',
    ];
    return $colors[$status] ?? 'bg-gray-100 text-gray-600';
}

// Calcul des pourcentages d'avancement
$progress = calculateModuleProgress($requiredPages, $requiredApis, $tableStatus, $requiredDirs);

// Générer des suggestions intelligentes
$intelligentSuggestions = generateSuggestions($pageStatus, $apiStatus, $tableStatus, $dirStatus, $progress, $stats);

// ============================================================
// GÉNÉRATION DES SUGGESTIONS INTELLIGENTES
// ============================================================

function generateSuggestions($pageStatus, $apiStatus, $tableStatus, $dirStatus, $progress, $stats) {
    $suggestions = [];
    $priorityActions = [];
    
    // Vérification de l'avancement global
    if ($progress['overall'] < 50) {
        $priorityActions[] = [
            'title' => '⚠️ Avancement critique',
            'desc' => "Le projet est à {$progress['overall']}% d'avancement. Concentrez-vous d'abord sur les pages critiques.",
            'priority' => 'critical',
            'icon' => 'fa-skull-crosswalk'
        ];
    }
    
    // Pages critiques manquantes
    $missingCriticalPages = [];
    foreach ($pageStatus as $file => $info) {
        if (!$info['exists'] && $info['status'] === 'critical') {
            $missingCriticalPages[] = $file;
        }
    }
    if (!empty($missingCriticalPages)) {
        $priorityActions[] = [
            'title' => '📄 Pages critiques manquantes',
            'desc' => "Ces pages sont essentielles au fonctionnement: " . implode(', ', $missingCriticalPages),
            'priority' => 'critical',
            'action' => 'Générer ces pages en priorité',
            'icon' => 'fa-file-code'
        ];
        $suggestions[] = "Créez les pages critiques manquantes : " . implode(', ', $missingCriticalPages);
    }
    
    // API manquantes
    $missingApis = [];
    foreach ($apiStatus as $api => $info) {
        if (!$info['exists']) {
            $missingApis[] = $api;
        }
    }
    if (!empty($missingApis)) {
        $suggestions[] = "Développez les endpoints API manquants : " . implode(', ', $missingApis);
    }
    
    // Tables manquantes
    $missingTables = [];
    foreach ($tableStatus as $table => $info) {
        if (!$info['exists']) {
            $missingTables[] = $table;
        }
    }
    if (!empty($missingTables)) {
        $suggestions[] = "Créez les tables de base de données manquantes : " . implode(', ', $missingTables);
    }
    
    // Dossiers manquants
    $missingDirs = [];
    foreach ($dirStatus as $dir => $info) {
        if (!$info['exists']) {
            $missingDirs[] = $dir;
        }
    }
    if (!empty($missingDirs)) {
        $suggestions[] = "Créez les dossiers manquants : " . implode(', ', $missingDirs);
    }
    
    // Dossiers non writables
    $unwritableDirs = [];
    foreach ($dirStatus as $dir => $info) {
        if ($info['exists'] && !$info['writable']) {
            $unwritableDirs[] = $dir;
        }
    }
    if (!empty($unwritableDirs)) {
        $suggestions[] = "Corrigez les permissions des dossiers non writables : " . implode(', ', $unwritableDirs);
    }
    
    // Suggestions basées sur les données
    if (($stats['users'] ?? 0) < 10) {
        $suggestions[] = "Peu d'utilisateurs enregistrés. Envisagez une campagne de communication pour attirer plus d'étudiants.";
    }
    
    if (($stats['posts'] ?? 0) < 5 && ($stats['users'] ?? 0) > 0) {
        $suggestions[] = "Peu de publications. Encouragez les utilisateurs à partager du contenu.";
    }
    
    if (($stats['communities'] ?? 0) < 3) {
        $suggestions[] = "Créez des communautés de départ pour animer le réseau.";
    }
    
    return ['suggestions' => $suggestions, 'priorityActions' => $priorityActions];
}

// Récupération des logs d'erreur système
$systemErrors = [];
if (file_exists('logs/errors.log')) {
    $errors = file('logs/errors.log');
    $systemErrors = is_array($errors) ? array_slice($errors, -20) : [];
}

// Définir AVATAR_URL si non défini
if (!defined('AVATAR_URL')) {
    define('AVATAR_URL', 'uploads/avatars/');
}

$csrfToken = generate_csrf_token();
$page_title = 'WideMaze Control Center';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>WideMaze Control Center | Assistant IA Intelligent</title>
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
        @keyframes slideIn {
            from { opacity: 0; transform: translateX(-20px); }
            to { opacity: 1; transform: translateX(0); }
        }
        @keyframes pulse-dot {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.5; transform: scale(1.2); }
        }
        
        .stat-card {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 20px 25px -12px rgba(0,0,0,0.15);
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
        .progress-ring {
            transition: stroke-dashoffset 1s ease-out;
        }
        .suggestion-card {
            transition: all 0.2s;
        }
        .suggestion-card:hover {
            transform: translateX(4px);
            background-color: #fef3c7;
        }
        .pulse-dot {
            animation: pulse-dot 2s infinite;
        }
        .scrollbar-hide::-webkit-scrollbar { display: none; }
        .scrollbar-hide { -ms-overflow-style: none; scrollbar-width: none; }
        
        /* Chatbot styles */
        .chat-message {
            animation: fadeInUp 0.2s ease-out;
        }
        .chat-typing span {
            display: inline-block;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background-color: #f59e0b;
            margin: 0 2px;
            animation: pulse-dot 1s infinite;
        }
        .chat-typing span:nth-child(2) { animation-delay: 0.2s; }
        .chat-typing span:nth-child(3) { animation-delay: 0.4s; }
        .chat-container {
            scroll-behavior: smooth;
        }
        .code-block {
            background: #1e293b;
            color: #e2e8f0;
            border-radius: 12px;
            padding: 16px;
            overflow-x: auto;
            font-family: 'Monaco', 'Menlo', monospace;
            font-size: 13px;
            line-height: 1.5;
        }
        .toast {
            animation: slideIn 0.3s ease-out;
        }
    </style>
</head>
<body class="min-h-screen">
    
    <!-- Navigation -->
    <nav class="fixed top-0 w-full bg-white/95 backdrop-blur-md shadow-lg z-50 border-b border-gray-100">
        <div class="container mx-auto px-4 h-16 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 bg-gradient-to-br from-orange-500 to-red-600 rounded-xl flex items-center justify-center shadow-lg">
                    <i class="fas fa-brain text-white text-xl"></i>
                </div>
                <div>
                    <h1 class="text-xl font-bold text-gray-800">WideMaze Control Center</h1>
                    <p class="text-xs text-gray-500">Assistant IA Intelligent • Analyse avancée • Monitoring temps réel</p>
                </div>
            </div>
            <a href="pages/admin.php" class="px-4 py-2 bg-gradient-to-r from-orange-500 to-purple-500 text-white rounded-lg hover:shadow-md transition-all">
                <i class="fas fa-shield-alt mr-2"></i>Admin
            </a>
            <div class="flex items-center gap-4">
                <div class="hidden md:flex items-center gap-2 px-4 py-2 bg-green-50 rounded-full">
                    <span class="w-2 h-2 bg-green-500 rounded-full pulse-dot"></span>
                    <span class="text-sm text-green-700 font-medium">Système opérationnel • <?= $progress['overall'] ?>% complété</span>
                </div>
                <a href="index.php" class="px-4 py-2 bg-gradient-to-r from-orange-500 to-red-500 text-white rounded-lg hover:shadow-md transition-all">
                    <i class="fas fa-home mr-2"></i>Retour
                </a>
            </div>

        </div>
    </nav>

    <div class="container mx-auto pt-24 pb-8 px-4 max-w-7xl">
        
        <!-- KPI Cards -->
        <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-8 gap-4 mb-8">
            <div class="stat-card bg-white rounded-2xl p-4 shadow-sm border border-gray-100">
                <div class="flex items-center justify-between mb-2">
                    <i class="fas fa-users text-2xl text-blue-500"></i>
                    <span class="text-xs font-medium text-green-600 bg-green-50 px-2 py-1 rounded-full">+<?= number_format($stats['users_today'] ?? 0) ?></span>
                </div>
                <p class="text-2xl font-bold text-gray-800"><?= number_format($stats['users'] ?? 0) ?></p>
                <p class="text-xs text-gray-500">Utilisateurs</p>
            </div>
            <div class="stat-card bg-white rounded-2xl p-4 shadow-sm border border-gray-100">
                <div class="flex items-center justify-between mb-2">
                    <i class="fas fa-circle text-2xl text-green-500 pulse-dot"></i>
                </div>
                <p class="text-2xl font-bold text-gray-800"><?= number_format($stats['online'] ?? 0) ?></p>
                <p class="text-xs text-gray-500">En ligne</p>
            </div>
            <div class="stat-card bg-white rounded-2xl p-4 shadow-sm border border-gray-100">
                <div class="flex items-center justify-between mb-2">
                    <i class="fas fa-newspaper text-2xl text-purple-500"></i>
                    <span class="text-xs font-medium text-green-600 bg-green-50 px-2 py-1 rounded-full">+<?= number_format($stats['posts_today'] ?? 0) ?></span>
                </div>
                <p class="text-2xl font-bold text-gray-800"><?= number_format($stats['posts'] ?? 0) ?></p>
                <p class="text-xs text-gray-500">Publications</p>
            </div>
            <div class="stat-card bg-white rounded-2xl p-4 shadow-sm border border-gray-100">
                <div class="flex items-center justify-between mb-2">
                    <i class="fas fa-heart text-2xl text-red-500"></i>
                </div>
                <p class="text-2xl font-bold text-gray-800"><?= number_format($stats['likes'] ?? 0) ?></p>
                <p class="text-xs text-gray-500">Likes</p>
            </div>
            <div class="stat-card bg-white rounded-2xl p-4 shadow-sm border border-gray-100">
                <div class="flex items-center justify-between mb-2">
                    <i class="fas fa-university text-2xl text-orange-500"></i>
                </div>
                <p class="text-2xl font-bold text-gray-800"><?= number_format($stats['communities'] ?? 0) ?></p>
                <p class="text-xs text-gray-500">Communautés</p>
            </div>
            <div class="stat-card bg-white rounded-2xl p-4 shadow-sm border border-gray-100">
                <div class="flex items-center justify-between mb-2">
                    <i class="fas fa-graduation-cap text-2xl text-indigo-500"></i>
                </div>
                <p class="text-2xl font-bold text-gray-800"><?= number_format($stats['universities'] ?? 0) ?></p>
                <p class="text-xs text-gray-500">Universités</p>
            </div>
            <div class="stat-card bg-white rounded-2xl p-4 shadow-sm border border-gray-100">
                <div class="flex items-center justify-between mb-2">
                    <i class="fas fa-globe text-2xl text-teal-500"></i>
                </div>
                <p class="text-2xl font-bold text-gray-800"><?= number_format($stats['countries'] ?? 0) ?></p>
                <p class="text-xs text-gray-500">Pays</p>
            </div>
            <div class="stat-card bg-gradient-to-r from-orange-500 to-red-500 rounded-2xl p-4 shadow-sm">
                <div class="flex items-center justify-between mb-2">
                    <i class="fas fa-chart-line text-2xl text-white"></i>
                </div>
                <p class="text-2xl font-bold text-white"><?= $progress['overall'] ?>%</p>
                <p class="text-xs text-white/80">Avancement</p>
            </div>
        </div>
        
        <!-- Graphique d'activité -->
        <div class="bg-white rounded-2xl shadow-sm p-6 mb-8 border border-gray-100">
            <h3 class="font-bold text-gray-800 mb-4 flex items-center gap-2">
                <i class="fas fa-chart-line text-orange-500"></i>Activité des 7 derniers jours
            </h3>
            <canvas id="activityChart" height="80"></canvas>
        </div>
        
        <!-- Tabs Navigation -->
        <div class="bg-white rounded-t-2xl shadow-sm border-b overflow-x-auto scrollbar-hide">
            <div class="flex">
            <div class="flex items-center gap-4">
            <div class="hidden md:flex items-center gap-3 px-4 py-2 bg-green-50/80 backdrop-blur-sm rounded-xl border border-green-200 shadow-sm">
    <div class="flex items-center gap-2">
        <div class="relative">
            <span class="absolute inset-0 w-2 h-2 bg-green-500 rounded-full animate-ping opacity-75"></span>
            <span class="relative w-2 h-2 bg-green-500 rounded-full"></span>
        </div>
        <span class="text-xs font-medium text-green-700 uppercase tracking-wide">STATUT</span>
        <span class="text-xs text-green-600">•</span>
        <span class="text-sm font-semibold text-green-800">Opérationnel</span>
    </div>
    <div class="h-5 w-px bg-green-200"></div>
    <div class="flex items-center gap-2">
        <span class="text-xs text-green-600 font-medium">Complétion</span>
        <div class="w-20 h-1.5 bg-green-100 rounded-full overflow-hidden">
            <div class="h-full bg-gradient-to-r from-green-500 to-emerald-500 rounded-full" style="width: <?= $progress['overall'] ?>%"></div>
        </div>
        <span class="text-sm font-bold text-green-700"><?= $progress['overall'] ?>%</span>
    </div>
</div>
    
    <!-- Bouton Admin -->
    <a href="pages/admin.php" class="flex items-center gap-2 px-4 py-2 bg-gradient-to-r from-purple-600 to-indigo-600 text-white rounded-lg hover:shadow-lg hover:scale-105 transition-all duration-200">
        <i class="fas fa-shield-alt text-sm"></i>
        <span class="text-sm font-medium">Administration</span>
    </a>
    
    <!-- Bouton Retour Accueil -->
    <a href="index.php" class="flex items-center gap-2 px-4 py-2 bg-gradient-to-r from-orange-500 to-red-500 text-white rounded-lg hover:shadow-lg hover:scale-105 transition-all duration-200">
        <i class="fas fa-home text-sm"></i>
        <span class="text-sm font-medium">Retour</span>
    </a>
</div>
                <button onclick="switchTab('overview')" class="tab-btn px-6 py-4 font-medium text-sm whitespace-nowrap transition-colors hover:bg-gray-50 <?= $activeTab == 'overview' ? 'tab-active text-orange-600' : 'text-gray-600' ?>" data-tab="overview">
                    <i class="fas fa-chart-line mr-2"></i>Dashboard
                </button>
                <button onclick="switchTab('chatbot')" class="tab-btn px-6 py-4 font-medium text-sm whitespace-nowrap transition-colors hover:bg-gray-50 <?= $activeTab == 'chatbot' ? 'tab-active text-orange-600' : 'text-gray-600' ?>" data-tab="chatbot">
                    <i class="fas fa-comment-dots mr-2 text-orange-500"></i>Chatbot IA
                </button>
                <button onclick="switchTab('assistant')" class="tab-btn px-6 py-4 font-medium text-sm whitespace-nowrap transition-colors hover:bg-gray-50 <?= $activeTab == 'assistant' ? 'tab-active text-orange-600' : 'text-gray-600' ?>" data-tab="assistant">
                    <i class="fas fa-robot mr-2"></i>Assistant
                </button>
                <button onclick="switchTab('structure')" class="tab-btn px-6 py-4 font-medium text-sm whitespace-nowrap transition-colors hover:bg-gray-50 <?= $activeTab == 'structure' ? 'tab-active text-orange-600' : 'text-gray-600' ?>" data-tab="structure">
                    <i class="fas fa-sitemap mr-2"></i>Structure
                </button>
                <button onclick="switchTab('pages')" class="tab-btn px-6 py-4 font-medium text-sm whitespace-nowrap transition-colors hover:bg-gray-50 <?= $activeTab == 'pages' ? 'tab-active text-orange-600' : 'text-gray-600' ?>" data-tab="pages">
                    <i class="fas fa-file-code mr-2"></i>Pages
                </button>
                <button onclick="switchTab('api')" class="tab-btn px-6 py-4 font-medium text-sm whitespace-nowrap transition-colors hover:bg-gray-50 <?= $activeTab == 'api' ? 'tab-active text-orange-600' : 'text-gray-600' ?>" data-tab="api">
                    <i class="fas fa-plug mr-2"></i>API
                </button>
                <button onclick="switchTab('database')" class="tab-btn px-6 py-4 font-medium text-sm whitespace-nowrap transition-colors hover:bg-gray-50 <?= $activeTab == 'database' ? 'tab-active text-orange-600' : 'text-gray-600' ?>" data-tab="database">
                    <i class="fas fa-database mr-2"></i>Base de données
                </button>
                <button onclick="switchTab('server')" class="tab-btn px-6 py-4 font-medium text-sm whitespace-nowrap transition-colors hover:bg-gray-50 <?= $activeTab == 'server' ? 'tab-active text-orange-600' : 'text-gray-600' ?>" data-tab="server">
                    <i class="fas fa-server mr-2"></i>Serveur
                </button>
                <button onclick="switchTab('logs')" class="tab-btn px-6 py-4 font-medium text-sm whitespace-nowrap transition-colors hover:bg-gray-50 <?= $activeTab == 'logs' ? 'tab-active text-orange-600' : 'text-gray-600' ?>" data-tab="logs">
                    <i class="fas fa-history mr-2"></i>Logs
                </button>
                <button onclick="switchTab('roadmap')" class="tab-btn px-6 py-4 font-medium text-sm whitespace-nowrap transition-colors hover:bg-gray-50 <?= $activeTab == 'roadmap' ? 'tab-active text-orange-600' : 'text-gray-600' ?>" data-tab="roadmap">
                    <i class="fas fa-road mr-2"></i>Roadmap
                </button>
            </div>
        </div>
        
        <!-- Tab Content -->
        <div class="bg-white rounded-b-2xl shadow-sm p-6 min-h-[600px]">
            
            <!-- TAB: Vue d'ensemble -->
            <div id="tab-overview" class="tab-content">
                <div class="grid lg:grid-cols-3 gap-6">
                    <div class="lg:col-span-2">
                        <h3 class="text-lg font-bold text-gray-800 mb-4">Avancement du projet</h3>
                        <div class="bg-gray-50 rounded-2xl p-6">
                            <div class="flex items-center justify-center mb-4">
                                <div class="relative w-40 h-40">
                                    <svg class="w-full h-full transform -rotate-90">
                                        <circle cx="80" cy="80" r="70" fill="none" stroke="#e5e7eb" stroke-width="12"/>
                                        <circle id="progressCircle" cx="80" cy="80" r="70" fill="none" stroke="#f59e0b" stroke-width="12" 
                                                stroke-dasharray="439.82" stroke-dashoffset="<?= 439.82 - (439.82 * $progress['overall'] / 100) ?>" 
                                                class="progress-ring"/>
                                    </svg>
                                    <div class="absolute inset-0 flex items-center justify-center">
                                        <span class="text-4xl font-bold text-orange-500"><?= $progress['overall'] ?>%</span>
                                    </div>
                                </div>
                                <div class="ml-6 space-y-2">
                                    <div><span class="inline-block w-3 h-3 bg-green-500 rounded-full mr-2"></span>Pages: <?= $progress['pages']['progress'] ?>% (<?= $progress['pages']['existing'] ?>/<?= $progress['pages']['total'] ?>)</div>
                                    <div><span class="inline-block w-3 h-3 bg-blue-500 rounded-full mr-2"></span>API: <?= $progress['apis']['progress'] ?>% (<?= $progress['apis']['existing'] ?>/<?= $progress['apis']['total'] ?>)</div>
                                    <div><span class="inline-block w-3 h-3 bg-purple-500 rounded-full mr-2"></span>Tables: <?= $progress['tables']['progress'] ?>% (<?= $progress['tables']['existing'] ?>/<?= $progress['tables']['total'] ?>)</div>
                                    <div><span class="inline-block w-3 h-3 bg-orange-500 rounded-full mr-2"></span>Dossiers: <?= $progress['dirs']['progress'] ?>% (<?= $progress['dirs']['existing'] ?>/<?= $progress['dirs']['total'] ?>)</div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="space-y-4">
                        <h3 class="text-lg font-bold text-gray-800 mb-4">Statistiques détaillées</h3>
                        <div class="bg-gray-50 rounded-xl p-4">
                            <div class="flex justify-between mb-2"><span class="text-gray-600">Commentaires</span><span class="font-bold text-gray-800"><?= number_format($stats['comments'] ?? 0) ?></span></div>
                            <div class="w-full bg-gray-200 rounded-full h-2"><div class="bg-blue-500 h-2 rounded-full" style="width: <?= min(100, ($stats['comments'] ?? 0) / max(1, $stats['posts'] ?? 0) * 100) ?>%"></div></div>
                        </div>
                        <div class="bg-gray-50 rounded-xl p-4">
                            <div class="flex justify-between mb-2"><span class="text-gray-600">Messages non lus</span><span class="font-bold <?= ($stats['unread_messages'] ?? 0) > 0 ? 'text-red-600' : 'text-gray-800' ?>"><?= number_format($stats['unread_messages'] ?? 0) ?></span></div>
                        </div>
                        <div class="bg-gray-50 rounded-xl p-4">
                            <div class="flex justify-between mb-2"><span class="text-gray-600">Notifications non lues</span><span class="font-bold <?= ($stats['unread_notif'] ?? 0) > 0 ? 'text-red-600' : 'text-gray-800' ?>"><?= number_format($stats['unread_notif'] ?? 0) ?></span></div>
                        </div>
                        <div class="bg-gradient-to-r from-orange-500 to-red-500 rounded-xl p-4 text-white">
                            <p class="text-sm opacity-90">Objectif global</p>
                            <p class="text-3xl font-bold"><?= $progress['overall'] ?>%</p>
                            <p class="text-xs opacity-75 mt-1">En route vers la version 1.0</p>
                        </div>
                    </div>
                </div>
                <div class="mt-8 grid md:grid-cols-2 gap-6">
                    <div>
                        <h3 class="text-lg font-bold text-gray-800 mb-4 flex items-center gap-2">
                            <i class="fas fa-university text-orange-500"></i>Top Universités
                        </h3>
                        <?php foreach ($topUniversities as $i => $uni): ?>
                        <div class="flex items-center gap-3 p-3 bg-gray-50 rounded-lg mb-2 hover:bg-orange-50 transition-colors">
                            <span class="w-8 h-8 bg-orange-500 text-white rounded-full flex items-center justify-center font-bold text-sm"><?= $i+1 ?></span>
                            <div class="flex-1"><p class="font-medium text-gray-800"><?= htmlspecialchars($uni['universite']) ?></p></div>
                            <span class="text-sm text-gray-500"><?= number_format($uni['count']) ?> membres</span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <div>
                        <h3 class="text-lg font-bold text-gray-800 mb-4 flex items-center gap-2">
                            <i class="fas fa-map-marker-alt text-orange-500"></i>Top Pays
                        </h3>
                        <?php foreach ($topCountries as $i => $country): ?>
                        <div class="flex items-center gap-3 p-3 bg-gray-50 rounded-lg mb-2 hover:bg-orange-50 transition-colors">
                            <span class="w-8 h-8 bg-blue-500 text-white rounded-full flex items-center justify-center font-bold text-sm"><?= $i+1 ?></span>
                            <div class="flex-1"><p class="font-medium text-gray-800"><?= htmlspecialchars($country['nationalite']) ?></p></div>
                            <span class="text-sm text-gray-500"><?= number_format($country['count']) ?> membres</span>
                        </div>
                        <?php endforeach; ?>
                    </div>

                </div>
            </div>
            
            <!-- TAB: Chatbot IA -->
            <div id="tab-chatbot" class="tab-content hidden">
                <div class="bg-gradient-to-r from-orange-50 to-yellow-50 rounded-2xl p-6 mb-6 border border-orange-200">
                    <div class="flex items-center gap-4">
                        <div class="w-16 h-16 bg-gradient-to-r from-orange-500 to-red-500 rounded-full flex items-center justify-center shadow-lg animate-pulse">
                            <i class="fas fa-robot text-white text-3xl"></i>
                        </div>
                        <div>
                            <h3 class="text-2xl font-bold text-gray-800">Assistant IA WideMaze v2.0</h3>
                            <p class="text-gray-600">NLP avancé • Génération de code • Architecture • Sécurité • Optimisation</p>
                            <div class="flex flex-wrap gap-2 mt-2">
                                <span class="text-xs bg-white rounded-full px-3 py-1 shadow-sm">📊 avancement</span>
                                <span class="text-xs bg-white rounded-full px-3 py-1 shadow-sm">✏️ code pour messagerie.php</span>
                                <span class="text-xs bg-white rounded-full px-3 py-1 shadow-sm">🗄️ sql table user_preferences</span>
                                <span class="text-xs bg-white rounded-full px-3 py-1 shadow-sm">🔒 audit sécurité</span>
                                <span class="text-xs bg-white rounded-full px-3 py-1 shadow-sm">🏗️ architecture</span>
                                <span class="text-xs bg-white rounded-full px-3 py-1 shadow-sm">⚡ optimisation</span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="grid lg:grid-cols-3 gap-6">
                    <!-- Zone de chat -->
                    <div class="lg:col-span-2">
                        <div class="bg-gray-50 rounded-2xl overflow-hidden border border-gray-200">
                            <div class="bg-white border-b border-gray-200 p-4">
                                <div class="flex items-center gap-2">
                                    <i class="fas fa-robot text-orange-500"></i>
                                    <span class="font-semibold text-gray-800">Assistant IA - Conversation contextuelle</span>
                                    <span class="ml-auto text-xs text-green-600 bg-green-50 px-2 py-1 rounded-full">
                                        <i class="fas fa-circle text-[8px] mr-1"></i>En ligne
                                    </span>
                                    <button onclick="clearChat()" class="text-xs text-gray-400 hover:text-red-500 transition-colors">
                                        <i class="fas fa-trash-alt mr-1"></i>Effacer
                                    </button>
                                </div>
                            </div>
                            <div id="chatContainer" class="h-96 overflow-y-auto p-4 space-y-3 chat-container">
                                <div class="flex items-start gap-3">
                                    <div class="w-8 h-8 bg-gradient-to-r from-orange-500 to-red-500 rounded-full flex items-center justify-center flex-shrink-0">
                                        <i class="fas fa-robot text-white text-sm"></i>
                                    </div>
                                    <div class="flex-1 bg-white rounded-xl p-3 shadow-sm max-w-[85%]">
                                        <p class="text-gray-700 text-sm">👋 Bonjour ! Je suis l'assistant IA avancé de WideMaze v2.0. Je peux comprendre le contexte de vos questions, générer du code optimisé, auditer votre sécurité, et vous guider dans l'architecture du projet.</p>
                                        <p class="text-xs text-gray-400 mt-2">💡 Essayez: "avancement", "code pour messagerie.php", "audit sécurité", ou "aide" pour tout voir</p>
                                    </div>
                                </div>
                            </div>
                            <div class="border-t border-gray-200 p-4 bg-white">
                                <div class="flex gap-3">
                                    <input type="text" id="chatInput" placeholder="Posez votre question technique..." 
                                           class="flex-1 px-4 py-3 border border-gray-200 rounded-xl focus:border-orange-500 focus:ring-2 focus:ring-orange-100 outline-none transition-all"
                                           onkeypress="if(event.key==='Enter') sendMessage()">
                                    <button onclick="sendMessage()" class="bg-gradient-to-r from-orange-500 to-red-500 text-white px-6 py-3 rounded-xl hover:shadow-lg transition-all">
                                        <i class="fas fa-paper-plane"></i>
                                    </button>
                                </div>
                                <div class="mt-2 flex flex-wrap gap-2">
                                    <span class="text-xs text-gray-400">Suggestions:</span>
                                    <button onclick="sendQuickCommand('avancement')" class="text-xs bg-gray-100 hover:bg-gray-200 px-2 py-1 rounded transition-colors">📊 avancement</button>
                                    <button onclick="sendQuickCommand('code pour communautes.php')" class="text-xs bg-gray-100 hover:bg-gray-200 px-2 py-1 rounded transition-colors">✏️ code communautés</button>
                                    <button onclick="sendQuickCommand('audit sécurité')" class="text-xs bg-gray-100 hover:bg-gray-200 px-2 py-1 rounded transition-colors">🔒 audit sécurité</button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Suggestions rapides -->
                    <div class="space-y-4">
                        <div class="bg-white rounded-2xl border border-gray-200 p-5">
                            <h4 class="font-bold text-gray-800 mb-3 flex items-center gap-2">
                                <i class="fas fa-terminal text-orange-500"></i>Commandes avancées
                            </h4>
                            <div class="space-y-2 text-sm">
                                <div class="p-2 bg-gray-50 rounded-lg hover:bg-orange-50 transition-colors">
                                    <code class="text-orange-600 font-semibold">avancement</code>
                                    <p class="text-xs text-gray-600 mt-1">Rapport complet avec analyse critique</p>
                                </div>
                                <div class="p-2 bg-gray-50 rounded-lg hover:bg-orange-50 transition-colors">
                                    <code class="text-orange-600 font-semibold">code pour [fichier]</code>
                                    <p class="text-xs text-gray-600 mt-1">Génération MVC avec dépendances</p>
                                </div>
                                <div class="p-2 bg-gray-50 rounded-lg hover:bg-orange-50 transition-colors">
                                    <code class="text-orange-600 font-semibold">sql table [nom]</code>
                                    <p class="text-xs text-gray-600 mt-1">Schéma avec contraintes et index</p>
                                </div>
                                <div class="p-2 bg-gray-50 rounded-lg hover:bg-orange-50 transition-colors">
                                    <code class="text-orange-600 font-semibold">audit sécurité</code>
                                    <p class="text-xs text-gray-600 mt-1">Analyse vulnérabilités et checklist</p>
                                </div>
                                <div class="p-2 bg-gray-50 rounded-lg hover:bg-orange-50 transition-colors">
                                    <code class="text-orange-600 font-semibold">architecture</code>
                                    <p class="text-xs text-gray-600 mt-1">Documentation structure MVC</p>
                                </div>
                                <div class="p-2 bg-gray-50 rounded-lg hover:bg-orange-50 transition-colors">
                                    <code class="text-orange-600 font-semibold">optimisation</code>
                                    <p class="text-xs text-gray-600 mt-1">Conseils performance SQL/PHP</p>
                                </div>
                            </div>
                        </div>

                        <div class="bg-gradient-to-br from-orange-50 to-red-50 rounded-2xl border border-orange-100 p-5">
                            <h4 class="font-bold text-gray-800 mb-2 flex items-center gap-2">
                                <i class="fas fa-lightbulb text-orange-500"></i>Capacités v2.0
                            </h4>
                            <ul class="text-sm text-gray-600 space-y-1">
                                <li>• 🧠 Compréhension contextuelle des conversations</li>
                                <li>• ✏️ Génération de code avec templates intelligents</li>
                                <li>• 🔒 Audit de sécurité automatisé</li>
                                <li>• 🏗️ Recommandations d'architecture</li>
                                <li>• ⚡ Optimisation SQL avancée</li>
                                <li>• 🎯 Détection d'intention (NLP)</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- TAB: Assistant (Suggestions) -->
            <div id="tab-assistant" class="tab-content hidden">
                <div class="bg-gradient-to-r from-orange-50 to-yellow-50 rounded-2xl p-6 mb-8 border border-orange-200">
                    <div class="flex items-center gap-4">
                        <div class="w-16 h-16 bg-gradient-to-r from-orange-500 to-red-500 rounded-full flex items-center justify-center shadow-lg">
                            <i class="fas fa-robot text-white text-3xl"></i>
                        </div>
                        <div>
                            <h3 class="text-2xl font-bold text-gray-800">Assistant Intelligent WideMaze</h3>
                            <p class="text-gray-600">Analyse en temps réel • Suggestions personnalisées • Plan d'action prioritaire</p>
                        </div>
                    </div>
                </div>

                <div class="mb-8">
                    <h3 class="text-lg font-bold text-gray-800 mb-4 flex items-center gap-2">
                        <i class="fas fa-exclamation-triangle text-red-500"></i>Actions prioritaires recommandées
                    </h3>
                    <div class="space-y-3">
                        <?php foreach ($intelligentSuggestions['priorityActions'] as $action): ?>
                        <div class="border-l-4 <?= $action['priority'] === 'critical' ? 'border-red-500 bg-red-50' : 'border-orange-500 bg-orange-50' ?> rounded-r-xl p-4 hover:shadow-md transition-all">
                            <div class="flex items-start gap-3">
                                <i class="fas <?= $action['icon'] ?? ($action['priority'] === 'critical' ? 'fa-skull-crosswalk' : 'fa-flag-checkered') ?> <?= $action['priority'] === 'critical' ? 'text-red-500' : 'text-orange-500' ?> mt-1"></i>
                                <div>
                                    <p class="font-bold text-gray-800"><?= $action['title'] ?></p>
                                    <p class="text-sm text-gray-600"><?= $action['desc'] ?></p>
                                    <?php if (isset($action['action'])): ?>
                                        <button class="mt-2 text-xs bg-white px-3 py-1 rounded-full text-orange-600 hover:bg-orange-500 hover:text-white transition-colors">
                                            <?= $action['action'] ?> →
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="mb-8">
                    <h3 class="text-lg font-bold text-gray-800 mb-4 flex items-center gap-2">
                        <i class="fas fa-lightbulb text-yellow-500"></i>Suggestions d'amélioration
                    </h3>
                    <div class="grid md:grid-cols-2 gap-3">
                        <?php foreach ($intelligentSuggestions['suggestions'] as $suggestion): ?>
                        <div class="bg-gray-50 rounded-xl p-4 suggestion-card hover:shadow-md transition-all">
                            <div class="flex items-start gap-3">
                                <i class="fas fa-chevron-right text-orange-500 mt-1"></i>
                                <p class="text-gray-700 text-sm"><?= htmlspecialchars($suggestion) ?></p>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="bg-gray-50 rounded-2xl p-6">
                    <h3 class="text-lg font-bold text-gray-800 mb-4 flex items-center gap-2">
                        <i class="fas fa-chart-simple text-orange-500"></i>Analyse rapide
                    </h3>
                    <div class="grid md:grid-cols-3 gap-4">
                        <div class="bg-white rounded-xl p-4 text-center hover:shadow-md transition-all">
                            <p class="text-2xl font-bold text-gray-800"><?= $progress['pages']['existing'] ?>/<?= $progress['pages']['total'] ?></p>
                            <p class="text-xs text-gray-500">Pages créées</p>
                            <div class="w-full bg-gray-200 rounded-full h-1.5 mt-2"><div class="bg-orange-500 h-1.5 rounded-full" style="width: <?= $progress['pages']['progress'] ?>%"></div></div>
                        </div>
                        <div class="bg-white rounded-xl p-4 text-center hover:shadow-md transition-all">
                            <p class="text-2xl font-bold text-gray-800"><?= $progress['apis']['existing'] ?>/<?= $progress['apis']['total'] ?></p>
                            <p class="text-xs text-gray-500">API endpoints</p>
                            <div class="w-full bg-gray-200 rounded-full h-1.5 mt-2"><div class="bg-blue-500 h-1.5 rounded-full" style="width: <?= $progress['apis']['progress'] ?>%"></div></div>
                        </div>
                        <div class="bg-white rounded-xl p-4 text-center hover:shadow-md transition-all">
                            <p class="text-2xl font-bold text-gray-800"><?= $progress['dirs']['existing'] ?>/<?= $progress['dirs']['total'] ?></p>
                            <p class="text-xs text-gray-500">Dossiers requis</p>
                            <div class="w-full bg-gray-200 rounded-full h-1.5 mt-2"><div class="bg-green-500 h-1.5 rounded-full" style="width: <?= $progress['dirs']['progress'] ?>%"></div></div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- TAB: Structure -->
            <div id="tab-structure" class="tab-content hidden">
                <h3 class="text-lg font-bold text-gray-800 mb-6">Architecture du système</h3>
                <div class="grid md:grid-cols-2 lg:grid-cols-3 gap-4">
                    <?php foreach ($dirStatus as $dir => $info): ?>
                    <div class="border rounded-xl p-4 <?= $info['exists'] && $info['writable'] ? 'border-green-200 bg-green-50' : ($info['exists'] ? 'border-yellow-200 bg-yellow-50' : 'border-red-200 bg-red-50') ?> hover:shadow-md transition-all">
                        <div class="flex items-start gap-3">
                            <i class="fas fa-folder text-2xl <?= $info['exists'] ? 'text-yellow-500' : 'text-gray-300' ?>"></i>
                            <div class="flex-1">
                                <p class="font-semibold text-gray-800"><?= htmlspecialchars($info['name']) ?></p>
                                <p class="text-xs text-gray-500"><?= htmlspecialchars($dir) ?></p>
                                <div class="mt-2 flex gap-2">
                                    <?php if ($info['exists']): ?>
                                        <span class="text-xs bg-green-100 text-green-700 px-2 py-1 rounded">Existe</span>
                                        <?= $info['writable'] ? '<span class="text-xs bg-green-100 text-green-700 px-2 py-1 rounded">✅ Writable</span>' : '<span class="text-xs bg-red-100 text-red-700 px-2 py-1 rounded">⚠️ Non writable</span>' ?>
                                        <span class="text-xs bg-gray-100 text-gray-600 px-2 py-1 rounded">📁 <?= formatBytes($info['size']) ?></span>
                                    <?php else: ?>
                                        <span class="text-xs bg-red-100 text-red-700 px-2 py-1 rounded">❌ Manquant</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <!-- TAB: Pages -->
            <div id="tab-pages" class="tab-content hidden">
                <div class="flex justify-between items-center mb-6">
                    <h3 class="text-lg font-bold text-gray-800">État des pages</h3>
                    <div class="relative">
                        <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
                        <input type="text" id="pagesSearch" placeholder="Rechercher..." class="pl-9 pr-4 py-2 border rounded-lg text-sm focus:outline-none focus:border-orange-500">
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full" id="pagesTable">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="p-3 text-left text-xs font-semibold text-gray-600">Page</th>
                                <th class="p-3 text-left text-xs font-semibold text-gray-600">Description</th>
                                <th class="p-3 text-center text-xs font-semibold text-gray-600">Module</th>
                                <th class="p-3 text-center text-xs font-semibold text-gray-600">Statut</th>
                                <th class="p-3 text-center text-xs font-semibold text-gray-600">Priorité</th>
                                <th class="p-3 text-center text-xs font-semibold text-gray-600">Taille</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pageStatus as $file => $info): ?>
                            <tr class="border-b hover:bg-gray-50 transition-colors" data-page-name="<?= strtolower($file) ?>">
                                <td class="p-3"><code class="text-sm"><?= htmlspecialchars($file) ?></code>\(
                                <td class="p-3 text-sm text-gray-600"><?= htmlspecialchars($info['desc']) ?>\(
                                <td class="p-3 text-center"><span class="text-xs bg-gray-100 px-2 py-1 rounded"><?= htmlspecialchars($info['module']) ?></span>\(
                                <td class="p-3 text-center">
                                    <?php if ($info['exists']): ?>
                                        <span class="bg-green-100 text-green-700 px-3 py-1 rounded-full text-xs"><i class="fas fa-check mr-1"></i>Existe</span>
                                    <?php else: ?>
                                        <span class="bg-red-100 text-red-700 px-3 py-1 rounded-full text-xs"><i class="fas fa-times mr-1"></i>Manquant</span>
                                    <?php endif; ?>
                                    </td>
                                <td class="p-3 text-center"><span class="<?= getStatusColor($info['status']) ?> px-3 py-1 rounded-full text-xs"><?= $info['status'] ?></span>\(
                                <td class="p-3 text-center text-xs text-gray-500"><?= $info['exists'] ? formatBytes($info['size']) : '-' ?>\(
                            \)                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <!-- TAB: API -->
            <div id="tab-api" class="tab-content hidden">
                <div class="flex justify-between items-center mb-6">
                    <h3 class="text-lg font-bold text-gray-800">Endpoints API</h3>
                    <div class="relative">
                        <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
                        <input type="text" id="apiSearch" placeholder="Rechercher..." class="pl-9 pr-4 py-2 border rounded-lg text-sm focus:outline-none focus:border-orange-500">
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full" id="apiTable">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="p-3 text-left text-xs font-semibold text-gray-600">Endpoint</th>
                                <th class="p-3 text-left text-xs font-semibold text-gray-600">Description</th>
                                <th class="p-3 text-center text-xs font-semibold text-gray-600">Méthodes</th>
                                <th class="p-3 text-center text-xs font-semibold text-gray-600">Statut</th>
                                <th class="p-3 text-center text-xs font-semibold text-gray-600">Taille</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($apiStatus as $api => $info): ?>
                            <tr class="border-b hover:bg-gray-50 transition-colors" data-api-name="<?= strtolower($api) ?>">
                                <td class="p-3"><code class="text-sm"><?= htmlspecialchars($api) ?></code></td>
                                <td class="p-3 text-sm text-gray-600"><?= htmlspecialchars($info['desc']) ?></td>
                                <td class="p-3 text-center"><span class="text-xs bg-gray-100 px-2 py-1 rounded"><?= htmlspecialchars($info['methods'] ?? 'GET') ?></span></td>
                                <td class="p-3 text-center">
                                    <?php if ($info['exists']): ?>
                                        <span class="bg-green-100 text-green-700 px-3 py-1 rounded-full text-xs"><i class="fas fa-check mr-1"></i>Actif</span>
                                    <?php else: ?>
                                        <span class="bg-red-100 text-red-700 px-3 py-1 rounded-full text-xs"><i class="fas fa-times mr-1"></i>Manquant</span>
                                    <?php endif; ?>
                                </td>
                                <td class="p-3 text-center text-xs text-gray-500"><?= $info['exists'] ? formatBytes($info['size']) : '-' ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <!-- TAB: Base de données -->
            <div id="tab-database" class="tab-content hidden">
                <h3 class="text-lg font-bold text-gray-800 mb-6">État des tables</h3>
                <div class="grid md:grid-cols-2 lg:grid-cols-3 gap-4">
                    <?php foreach ($tableStatus as $table => $info): ?>
                    <div class="border rounded-xl p-4 <?= $info['exists'] ? 'border-green-200 bg-green-50' : 'border-red-200 bg-red-50' ?> hover:shadow-md transition-all">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="font-semibold text-gray-800"><?= htmlspecialchars($table) ?></p>
                                <p class="text-xs text-gray-500"><?= htmlspecialchars($info['desc']) ?></p>
                                <?php if ($info['exists'] && $info['records'] > 0): ?>
                                    <p class="text-xs text-gray-400 mt-1">📊 <?= number_format($info['records']) ?> enregistrements</p>
                                <?php endif; ?>
                            </div>
                            <i class="fas fa-<?= $info['exists'] ? 'check-circle text-green-500' : 'times-circle text-red-500' ?> text-xl"></i>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <!-- TAB: Serveur -->
            <div id="tab-server" class="tab-content hidden">
                <div class="grid md:grid-cols-2 gap-6">
                    <div>
                        <h3 class="text-lg font-bold text-gray-800 mb-4 flex items-center gap-2">
                            <i class="fas fa-server text-orange-500"></i>Informations serveur
                        </h3>
                        <div class="bg-gray-50 rounded-xl p-5 space-y-3">
                            <div class="flex justify-between py-2 border-b border-gray-200">
                                <span class="text-gray-600">PHP Version</span>
                                <span class="font-semibold text-gray-800"><?= $serverInfo['php_version'] ?></span>
                            </div>
                            <div class="flex justify-between py-2 border-b border-gray-200">
                                <span class="text-gray-600">Serveur</span>
                                <span class="font-semibold text-gray-800"><?= htmlspecialchars($serverInfo['server_software']) ?></span>
                            </div>
                            <div class="flex justify-between py-2 border-b border-gray-200">
                                <span class="text-gray-600">Nom d'hôte</span>
                                <span class="font-semibold text-gray-800"><?= htmlspecialchars($serverInfo['server_name']) ?></span>
                            </div>
                            <div class="flex justify-between py-2 border-b border-gray-200">
                                <span class="text-gray-600">MySQL Version</span>
                                <span class="font-semibold text-gray-800"><?= $serverInfo['mysql_version'] ?></span>
                            </div>
                            <div class="flex justify-between py-2 border-b border-gray-200">
                                <span class="text-gray-600">Racine du projet</span>
                                <span class="font-semibold text-gray-800 text-sm"><?= htmlspecialchars($serverInfo['document_root']) ?></span>
                            </div>
                        </div>
                    </div>
                    <div>
                        <h3 class="text-lg font-bold text-gray-800 mb-4 flex items-center gap-2">
                            <i class="fas fa-microchip text-orange-500"></i>Configuration PHP
                        </h3>
                        <div class="bg-gray-50 rounded-xl p-5 space-y-3">
                            <div class="flex justify-between py-2 border-b border-gray-200">
                                <span class="text-gray-600">Mémoire limite</span>
                                <span class="font-semibold text-gray-800"><?= $serverInfo['memory_limit'] ?></span>
                            </div>
                            <div class="flex justify-between py-2 border-b border-gray-200">
                                <span class="text-gray-600">Upload max</span>
                                <span class="font-semibold text-gray-800"><?= $serverInfo['upload_max_filesize'] ?></span>
                            </div>
                            <div class="flex justify-between py-2 border-b border-gray-200">
                                <span class="text-gray-600">Post max</span>
                                <span class="font-semibold text-gray-800"><?= $serverInfo['post_max_size'] ?></span>
                            </div>
                            <div class="flex justify-between py-2 border-b border-gray-200">
                                <span class="text-gray-600">Exécution max</span>
                                <span class="font-semibold text-gray-800"><?= $serverInfo['max_execution_time'] ?>s</span>
                            </div>
                            <div class="flex justify-between py-2 border-b border-gray-200">
                                <span class="text-gray-600">Mémoire utilisée</span>
                                <span class="font-semibold text-gray-800"><?= formatBytes($serverInfo['memory_usage']) ?> / <?= formatBytes($serverInfo['memory_peak']) ?> (peak)</span>
                            </div>
                            <div class="flex justify-between py-2">
                                <span class="text-gray-600">Temps d'exécution</span>
                                <span class="font-semibold text-gray-800"><?= round($serverInfo['execution_time'], 3) ?>s</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- TAB: Logs -->
            <div id="tab-logs" class="tab-content hidden">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-lg font-bold text-gray-800">Journal d'activité</h3>
                    <div class="flex gap-3">
                        <div class="relative">
                            <i class="fas fa-filter absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
                            <select id="logFilter" class="pl-9 pr-8 py-2 border rounded-lg text-sm focus:outline-none focus:border-orange-500">
                                <option value="all">Tous les logs</option>
                                <option value="login">Connexions</option>
                                <option value="post">Publications</option>
                                <option value="friend">Amis</option>
                                <option value="admin">Administration</option>
                            </select>
                        </div>
                        <button onclick="refreshLogs()" class="px-3 py-2 bg-gray-100 hover:bg-gray-200 rounded-lg transition-colors text-sm">
                            <i class="fas fa-sync-alt"></i>
                        </button>
                    </div>
                </div>
                <div class="space-y-2 max-h-96 overflow-y-auto" id="logsContainer">
                    <?php foreach ($recentActivity as $activity): ?>
                    <div class="flex items-start gap-4 p-4 bg-gray-50 rounded-xl hover:bg-orange-50 transition-colors" data-log-type="<?= strpos($activity['action'] ?? '', 'login') !== false ? 'login' : (strpos($activity['action'] ?? '', 'post') !== false ? 'post' : (strpos($activity['action'] ?? '', 'friend') !== false ? 'friend' : 'admin')) ?>">
                        <div class="w-10 h-10 bg-primary/10 rounded-full flex items-center justify-center">
                            <i class="fas fa-<?= getActivityIcon($activity['action'] ?? '') ?> text-primary"></i>
                        </div>
                        <div class="flex-1">
                            <p class="text-gray-800">
                                <span class="font-semibold">@<?= htmlspecialchars($activity['surnom'] ?? 'Système') ?></span>
                                <span class="text-gray-600"><?= getActivityLabel($activity['action'] ?? '') ?></span>
                            </p>
                            <p class="text-xs text-gray-400 mt-1"><?= isset($activity['created_at']) ? date('d/m/Y H:i:s', strtotime($activity['created_at'])) : '-' ?></p>
                            <?php if (!empty($activity['details'])): ?>
                                <p class="text-xs text-gray-500 mt-1 font-mono bg-gray-100 inline-block px-2 py-1 rounded"><?= htmlspecialchars($activity['details']) ?></p>
                            <?php endif; ?>
                        </div>
                        <div class="text-right">
                            <span class="text-xs text-gray-400"><?= isset($activity['ip_address']) ? $activity['ip_address'] : '-' ?></span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php if (!empty($systemErrors)): ?>
                <div class="mt-6 bg-red-50 rounded-xl p-5 border border-red-200">
                    <h4 class="font-semibold text-red-800 mb-3 flex items-center gap-2">
                        <i class="fas fa-exclamation-triangle"></i>Erreurs système récentes
                    </h4>
                    <div class="space-y-2 max-h-48 overflow-y-auto">
                        <?php foreach ($systemErrors as $error): ?>
                            <div class="text-xs text-red-700 font-mono p-2 bg-red-100 rounded break-all"><?= htmlspecialchars($error) ?></div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- TAB: Roadmap -->
            <div id="tab-roadmap" class="tab-content hidden">
                <h3 class="text-lg font-bold text-gray-800 mb-6">Feuille de route WideMaze</h3>
                <p class="text-gray-600 mb-6">Objectif: Réunir les étudiants et institutions universitaires du monde • Avancement global: <strong class="text-orange-600"><?= $progress['overall'] ?>%</strong></p>
                <div class="space-y-6">
                    <div class="relative">
                        <div class="flex items-center gap-4 mb-3">
                            <div class="w-12 h-12 bg-green-500 rounded-full flex items-center justify-center text-white shadow-lg">
                                <i class="fas fa-check"></i>
                            </div>
                            <div>
                                <h4 class="font-bold text-gray-800 text-lg">Phase 1: Fondations</h4>
                                <p class="text-sm text-green-600">Terminé ✅</p>
                            </div>
                        </div>
                        <div class="ml-16 space-y-2">
                            <div class="flex items-center gap-2"><i class="fas fa-check-circle text-green-500"></i><span>Système d'authentification complet</span></div>
                            <div class="flex items-center gap-2"><i class="fas fa-check-circle text-green-500"></i><span>Profils utilisateurs avec avatar/couverture</span></div>
                            <div class="flex items-center gap-2"><i class="fas fa-check-circle text-green-500"></i><span>Système de publications (CRUD)</span></div>
                            <div class="flex items-center gap-2"><i class="fas fa-check-circle text-green-500"></i><span>Tableau de bord WCC v5.0</span></div>
                            <div class="flex items-center gap-2"><i class="fas fa-check-circle text-green-500"></i><span>API RESTful complète</span></div>
                        </div>
                    </div>
                    <div class="relative">
                        <div class="flex items-center gap-4 mb-3">
                            <div class="w-12 h-12 bg-gradient-to-r from-orange-500 to-red-500 rounded-full flex items-center justify-center text-white shadow-lg animate-pulse">
                                <i class="fas fa-spinner fa-spin"></i>
                            </div>
                            <div>
                                <h4 class="font-bold text-gray-800 text-lg">Phase 2: Fonctionnalités Sociales</h4>
                                <p class="text-sm text-orange-600">En cours (<?= round(($progress['pages']['existing'] / $progress['pages']['total']) * 100) ?>%)</p>
                            </div>
                        </div>
                        <div class="ml-16 space-y-2">
                            <div class="flex items-center gap-2"><i class="fas fa-<?= file_exists('pages/messagerie.php') ? 'check-circle text-green-500' : 'clock text-orange-500' ?>"></i><span>Messagerie instantanée avec fichiers et vocaux</span></div>
                            <div class="flex items-center gap-2"><i class="fas fa-<?= file_exists('pages/communautes.php') ? 'check-circle text-green-500' : 'clock text-orange-500' ?>"></i><span>Système de communautés académiques</span></div>
                            <div class="flex items-center gap-2"><i class="fas fa-<?= file_exists('pages/recherche.php') ? 'check-circle text-green-500' : 'clock text-orange-500' ?>"></i><span>Recherche avancée avec suggestions</span></div>
                            <div class="flex items-center gap-2"><i class="fas fa-<?= file_exists('pages/notifications.php') ? 'check-circle text-green-500' : 'clock text-orange-500' ?>"></i><span>Notifications temps réel</span></div>
                            <div class="flex items-center gap-2"><i class="fas fa-clock text-orange-500"></i><span>Stories (24h)</span></div>
                        </div>
                    </div>
                    <div class="relative">
                        <div class="flex items-center gap-4 mb-3">
                            <div class="w-12 h-12 bg-gray-300 rounded-full flex items-center justify-center">
                                <i class="fas fa-lock text-white"></i>
                            </div>
                            <div>
                                <h4 class="font-bold text-gray-800 text-lg">Phase 3: Fonctionnalités Académiques</h4>
                                <p class="text-sm text-gray-500">Planifié 📋</p>
                            </div>
                        </div>
                        <div class="ml-16 space-y-2 text-gray-500">
                            <div><i class="fas fa-circle text-xs mr-2"></i>Partage de ressources pédagogiques</div>
                            <div><i class="fas fa-circle text-xs mr-2"></i>Calendrier académique et événements</div>
                            <div><i class="fas fa-circle text-xs mr-2"></i>Système de mentorat étudiant</div>
                            <div><i class="fas fa-circle text-xs mr-2"></i>Groupes de travail par matière</div>
                            <div><i class="fas fa-circle text-xs mr-2"></i>Intégration LMS (Moodle, Canvas)</div>
                        </div>
                    </div>
                    <div class="relative">
                        <div class="flex items-center gap-4 mb-3">
                            <div class="w-12 h-12 bg-gray-300 rounded-full flex items-center justify-center">
                                <i class="fas fa-chart-line text-white"></i>
                            </div>
                            <div>
                                <h4 class="font-bold text-gray-800 text-lg">Phase 4: Scale & Optimisation</h4>
                                <p class="text-sm text-gray-500">Planifié 📋</p>
                            </div>
                        </div>
                        <div class="ml-16 space-y-2 text-gray-500">
                            <div><i class="fas fa-circle text-xs mr-2"></i>Optimisation des requêtes SQL</div>
                            <div><i class="fas fa-circle text-xs mr-2"></i>Mise en cache Redis/Memcached</div>
                            <div><i class="fas fa-circle text-xs mr-2"></i>Tests automatisés (PHPUnit)</div>
                            <div><i class="fas fa-circle text-xs mr-2"></i>Documentation API complète</div>
                            <div><i class="fas fa-circle text-xs mr-2"></i>PWA (Progressive Web App)</div>
                        </div>
                    </div>
                </div>
                <div class="mt-8 bg-gradient-to-r from-blue-50 to-indigo-50 rounded-xl p-6 border border-blue-100">
                    <h4 class="font-semibold text-blue-800 mb-4 flex items-center gap-2">
                        <i class="fas fa-list-check"></i>Prochaines étapes recommandées
                    </h4>
                    <div class="grid md:grid-cols-2 gap-3">
                        <div class="bg-white rounded-lg p-3 flex items-center gap-2 hover:shadow-md transition-all">
                            <i class="fas fa-comment text-orange-500"></i>
                            <span>Finaliser messagerie.php avec WebSocket</span>
                        </div>
                        <div class="bg-white rounded-lg p-3 flex items-center gap-2 hover:shadow-md transition-all">
                            <i class="fas fa-users text-orange-500"></i>
                            <span>Développer communautes.php et communaute.php</span>
                        </div>
                        <div class="bg-white rounded-lg p-3 flex items-center gap-2 hover:shadow-md transition-all">
                            <i class="fas fa-search text-orange-500"></i>
                            <span>Améliorer recherche.php avec Elasticsearch</span>
                        </div>
                        <div class="bg-white rounded-lg p-3 flex items-center gap-2 hover:shadow-md transition-all">
                            <i class="fas fa-database text-orange-500"></i>
                            <span>Exécuter script de migration SQL pour nouvelles tables</span>
                        </div>
                        <div class="bg-white rounded-lg p-3 flex items-center gap-2 hover:shadow-md transition-all">
                            <i class="fas fa-shield-alt text-orange-500"></i>
                            <span>Implémenter la 2FA pour les comptes admin</span>
                        </div>
                        <div class="bg-white rounded-lg p-3 flex items-center gap-2 hover:shadow-md transition-all">
                            <i class="fas fa-chart-line text-orange-500"></i>
                            <span>Configurer Google Analytics pour le suivi</span>
                        </div>
                    </div>
                </div>
            </div>
            
        </div>
    </div>
    
    <!-- Toast Container -->
    <div id="toastContainer" class="fixed bottom-4 right-4 z-50 space-y-2"></div>
    
    <script>
        // ==================== TAB SWITCHING ====================
        let activeTab = '<?= $activeTab ?? 'overview' ?>';
        
        function switchTab(tabName) {
            document.querySelectorAll('.tab-content').forEach(tab => tab.classList.add('hidden'));
            document.querySelectorAll('.tab-btn').forEach(btn => {
                btn.classList.remove('tab-active', 'text-orange-600');
                btn.classList.add('text-gray-600');
            });
            
            const targetTab = document.getElementById('tab-' + tabName);
            if (targetTab) targetTab.classList.remove('hidden');
            
            const targetBtn = document.querySelector(`[data-tab="${tabName}"]`);
            if (targetBtn) {
                targetBtn.classList.add('tab-active', 'text-orange-600');
                targetBtn.classList.remove('text-gray-600');
            }
            
            activeTab = tabName;
            
            // Mettre à jour l'URL sans recharger
            const url = new URL(window.location.href);
            url.searchParams.set('tab', tabName);
            window.history.pushState({}, '', url);
        }
        
        // ==================== CHART ====================
        const ctx = document.getElementById('activityChart');
        if (ctx) {
            const dailyUsers = <?= json_encode(array_map(function($d) { return $d['count'] ?? 0; }, $stats['daily_users'])) ?>;
            const dailyPosts = <?= json_encode(array_map(function($d) { return $d['count'] ?? 0; }, $stats['daily_posts'])) ?>;
            const labels = <?= json_encode(array_map(function($d) { 
                return isset($d['date']) ? date('d/m', strtotime($d['date'])) : ''; 
            }, $stats['daily_users'])) ?>;
            
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
                            fill: true,
                            pointBackgroundColor: '#f59e0b',
                            pointBorderColor: '#fff',
                            pointRadius: 4,
                            pointHoverRadius: 6
                        },
                        {
                            label: 'Nouvelles publications',
                            data: dailyPosts,
                            borderColor: '#10b981',
                            backgroundColor: 'rgba(16, 185, 129, 0.1)',
                            tension: 0.4,
                            fill: true,
                            pointBackgroundColor: '#10b981',
                            pointBorderColor: '#fff',
                            pointRadius: 4,
                            pointHoverRadius: 6
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    plugins: {
                        legend: { position: 'top' },
                        tooltip: { mode: 'index', intersect: false }
                    },
                    scales: {
                        y: { beginAtZero: true, grid: { color: '#e5e7eb' } },
                        x: { grid: { display: false } }
                    }
                }
            });
        }
        
        // ==================== CHATBOT ====================
        let isTyping = false;
        
        function sendMessage() {
            const input = document.getElementById('chatInput');
            const message = input.value.trim();
            if (!message || isTyping) return;
            
            addMessageToChat(message, 'user');
            input.value = '';
            
            showTypingIndicator();
            
            fetch('', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'chatbot_message=' + encodeURIComponent(message)
            })
            .then(response => response.json())
            .then(data => {
                removeTypingIndicator();
                addMessageToChat(data.response, 'bot');
            })
            .catch(error => {
                removeTypingIndicator();
                addMessageToChat("❌ Désolé, une erreur est survenue. Veuillez réessayer.", 'bot');
                showToast('Erreur de connexion', 'error');
            });
        }
        
        function sendQuickCommand(command) {
            document.getElementById('chatInput').value = command;
            sendMessage();
        }
        
        function addMessageToChat(message, sender) {
            const container = document.getElementById('chatContainer');
            const isBot = sender === 'bot';
            const formattedMessage = formatMessage(message);
            
            const messageDiv = document.createElement('div');
            messageDiv.className = `flex items-start gap-3 chat-message ${isBot ? '' : 'flex-row-reverse'}`;
            messageDiv.innerHTML = `
                <div class="w-8 h-8 ${isBot ? 'bg-gradient-to-r from-orange-500 to-red-500' : 'bg-gray-300'} rounded-full flex items-center justify-center flex-shrink-0 shadow-sm">
                    <i class="fas ${isBot ? 'fa-robot text-white' : 'fa-user text-gray-600'} text-sm"></i>
                </div>
                <div class="flex-1 ${isBot ? 'bg-white' : 'bg-orange-50'} rounded-xl p-3 shadow-sm max-w-[85%]">
                    <div class="text-gray-700 text-sm whitespace-pre-wrap">${formattedMessage}</div>
                </div>
            `;
            container.appendChild(messageDiv);
            container.scrollTop = container.scrollHeight;
        }
        
        function formatMessage(message) {
            // Formater les blocs de code SQL
            message = message.replace(/```sql\n([\s\S]*?)\n```/g, '<div class="code-block mt-2"><i class="fas fa-database mr-2"></i>SQL:<br><pre class="text-xs overflow-x-auto">$1</pre></div>');
            // Formater les blocs de code PHP
            message = message.replace(/```php\n([\s\S]*?)\n```/g, '<div class="code-block mt-2"><i class="fab fa-php mr-2"></i>PHP:<br><pre class="text-xs overflow-x-auto">$1</pre></div>');
            // Formater les blocs de code bash
            message = message.replace(/```bash\n([\s\S]*?)\n```/g, '<div class="code-block mt-2"><i class="fas fa-terminal mr-2"></i>Terminal:<br><pre class="text-xs overflow-x-auto">$1</pre></div>');
            // Formater les blocs de code génériques
            message = message.replace(/```\n([\s\S]*?)\n```/g, '<div class="code-block mt-2"><pre class="text-xs overflow-x-auto">$1</pre></div>');
            // Formater les inline code
            message = message.replace(/`([^`]+)`/g, '<code class="bg-gray-100 px-1 rounded text-xs font-mono text-orange-600">$1</code>');
            // Formater les listes
            message = message.replace(/\n- /g, '<br>• ');
            message = message.replace(/\n  □ /g, '<br>&nbsp;&nbsp;◻ ');
            message = message.replace(/\n  → /g, '<br>&nbsp;&nbsp;→ ');
            message = message.replace(/\n/g, '<br>');
            // Formater le gras
            message = message.replace(/\*\*([^*]+)\*\*/g, '<strong class="text-gray-900">$1</strong>');
            // Formater les émojis
            message = message.replace(/✅/g, '<span class="text-green-500">✅</span>');
            message = message.replace(/⚠️/g, '<span class="text-yellow-500">⚠️</span>');
            message = message.replace(/🚨/g, '<span class="text-red-500">🚨</span>');
            message = message.replace(/🎉/g, '<span class="text-purple-500">🎉</span>');
            message = message.replace(/💡/g, '<span class="text-yellow-500">💡</span>');
            return message;
        }
        
        function showTypingIndicator() {
            isTyping = true;
            const container = document.getElementById('chatContainer');
            const indicator = document.createElement('div');
            indicator.id = 'typingIndicator';
            indicator.className = 'flex items-start gap-3';
            indicator.innerHTML = `
                <div class="w-8 h-8 bg-gradient-to-r from-orange-500 to-red-500 rounded-full flex items-center justify-center flex-shrink-0 shadow-sm">
                    <i class="fas fa-robot text-white text-sm"></i>
                </div>
                <div class="bg-white rounded-xl p-3 shadow-sm">
                    <div class="chat-typing flex items-center gap-1">
                        <span></span><span></span><span></span>
                    </div>
                </div>
            `;
            container.appendChild(indicator);
            container.scrollTop = container.scrollHeight;
        }
        
        function removeTypingIndicator() {
            isTyping = false;
            const indicator = document.getElementById('typingIndicator');
            if (indicator) indicator.remove();
        }
        
        function clearChat() {
            const container = document.getElementById('chatContainer');
            container.innerHTML = `
                <div class="flex items-start gap-3">
                    <div class="w-8 h-8 bg-gradient-to-r from-orange-500 to-red-500 rounded-full flex items-center justify-center flex-shrink-0 shadow-sm">
                        <i class="fas fa-robot text-white text-sm"></i>
                    </div>
                    <div class="flex-1 bg-white rounded-xl p-3 shadow-sm max-w-[85%]">
                        <p class="text-gray-700 text-sm">👋 Conversation réinitialisée. Je suis l'assistant IA avancé de WideMaze v5.0. Comment puis-je vous aider ?</p>
                        <p class="text-xs text-gray-400 mt-2">💡 Essayez: "avancement", "code pour messagerie.php", "audit sécurité"</p>
                    </div>
                </div>
            `;
            showToast('Conversation réinitialisée', 'info');
        }
        
        // ==================== RECHERCHE ====================
        const pagesSearch = document.getElementById('pagesSearch');
        if (pagesSearch) {
            pagesSearch.addEventListener('input', function() {
                const term = this.value.toLowerCase();
                const rows = document.querySelectorAll('#pagesTable tbody tr');
                rows.forEach(row => {
                    const name = row.getAttribute('data-page-name') || '';
                    row.style.display = name.includes(term) ? '' : 'none';
                });
            });
        }
        
        const apiSearch = document.getElementById('apiSearch');
        if (apiSearch) {
            apiSearch.addEventListener('input', function() {
                const term = this.value.toLowerCase();
                const rows = document.querySelectorAll('#apiTable tbody tr');
                rows.forEach(row => {
                    const name = row.getAttribute('data-api-name') || '';
                    row.style.display = name.includes(term) ? '' : 'none';
                });
            });
        }
        
        // ==================== FILTRE LOGS ====================
        const logFilter = document.getElementById('logFilter');
        if (logFilter) {
            logFilter.addEventListener('change', function() {
                const filter = this.value;
                const logs = document.querySelectorAll('#logsContainer > div');
                logs.forEach(log => {
                    const type = log.getAttribute('data-log-type') || '';
                    if (filter === 'all' || type === filter) {
                        log.style.display = '';
                    } else {
                        log.style.display = 'none';
                    }
                });
            });
        }
        
        function refreshLogs() {
            showToast('Actualisation des logs...', 'info');
            setTimeout(() => location.reload(), 500);
        }
        
        // ==================== PROGRESSION CIRCLE ====================
        setTimeout(() => {
            const circle = document.getElementById('progressCircle');
            if (circle) {
                const circumference = 439.82;
                const progress = <?= $progress['overall'] ?>;
                circle.style.strokeDashoffset = circumference - (circumference * progress / 100);
            }
        }, 100);
        
        // ==================== TOAST ====================
        function showToast(message, type = 'info') {
            const container = document.getElementById('toastContainer');
            const colors = {
                success: 'bg-gradient-to-r from-green-500 to-green-600',
                error: 'bg-gradient-to-r from-red-500 to-red-600',
                info: 'bg-gradient-to-r from-blue-500 to-blue-600',
                warning: 'bg-gradient-to-r from-yellow-500 to-yellow-600'
            };
            const icons = {
                success: 'fa-check-circle',
                error: 'fa-exclamation-circle',
                info: 'fa-info-circle',
                warning: 'fa-exclamation-triangle'
            };
            
            const toast = document.createElement('div');
            toast.className = `${colors[type]} text-white px-5 py-3 rounded-xl shadow-lg flex items-center gap-3 toast`;
            toast.innerHTML = `
                <i class="fas ${icons[type]}"></i>
                <span class="flex-1 font-medium">${escapeHtml(message)}</span>
                <button onclick="this.parentElement.remove()" class="text-white/70 hover:text-white">
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
        
        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        // ==================== RÉCUPÉRER LE TAB DEPUIS L'URL ====================
        const urlParams = new URLSearchParams(window.location.search);
        const tabParam = urlParams.get('tab');
        if (tabParam && ['overview', 'chatbot', 'assistant', 'structure', 'pages', 'api', 'database', 'server', 'logs', 'roadmap'].includes(tabParam)) {
            switchTab(tabParam);
        }
        
        // ==================== ANIMATION DES STAT CARDS ====================
        document.querySelectorAll('.stat-card').forEach(card => {
            card.addEventListener('mouseenter', () => {
                card.style.transform = 'translateY(-4px)';
            });
            card.addEventListener('mouseleave', () => {
                card.style.transform = 'translateY(0)';
            });
        });
    </script>
</body>
</html>