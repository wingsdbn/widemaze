<?php
/**
 * WideMaze - Paramètres Utilisateur
 * Gestion complète du profil, sécurité, confidentialité et préférences
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_auth();

$userId = $_SESSION['user_id'];
$activeTab = $_GET['tab'] ?? 'general';
$success = $_GET['success'] ?? '';
$error = $_GET['error'] ?? '';

// Récupération des données utilisateur
try {
    $stmt = $pdo->prepare("SELECT * FROM utilisateurs WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    
    if (!$user) {
        session_destroy();
        header('Location: connexion.php');
        exit();
    }
} catch (PDOException $e) {
    error_log("Error fetching user: " . $e->getMessage());
}

// Récupération des préférences utilisateur
$preferences = [
    'dark_mode' => 0,
    'email_notifications' => 1,
    'like_notifications' => 1,
    'comment_notifications' => 1,
    'friend_notifications' => 1,
    'message_notifications' => 1,
    'language' => 'fr',
    'timezone' => 'Europe/Paris'
];

try {
    $stmt = $pdo->prepare("SELECT * FROM user_preferences WHERE user_id = ?");
    $stmt->execute([$userId]);
    $prefData = $stmt->fetch();
    if ($prefData) {
        $preferences = array_merge($preferences, $prefData);
    }
} catch (PDOException $e) {
    // Table peut ne pas exister encore
    error_log("Error fetching preferences: " . $e->getMessage());
}

// Traitement des formulaires
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        header('Location: parametres.php?tab=' . $activeTab . '&error=csrf');
        exit();
    }
    
    $formAction = $_POST['form_action'] ?? '';
    
    switch ($formAction) {
        case 'update_general':
            $prenom = trim($_POST['prenom'] ?? '');
            $nom = trim($_POST['nom'] ?? '');
            $surnom = trim($_POST['surnom'] ?? '');
            $bio = trim($_POST['bio'] ?? '');
            $universite = trim($_POST['universite'] ?? '');
            $faculte = trim($_POST['faculte'] ?? '');
            $niveau_etude = trim($_POST['niveau_etude'] ?? '');
            $nationalite = trim($_POST['nationalite'] ?? '');
            $telephone = trim($_POST['telephone'] ?? '');
            
            // Validation
            $errors = [];
            if (strlen($prenom) > 20) $errors[] = "Prénom trop long";
            if (strlen($nom) > 20) $errors[] = "Nom trop long";
            if (strlen($surnom) > 30) $errors[] = "Surnom trop long";
            if (!preg_match('/^[a-zA-Z0-9_]+$/', $surnom)) $errors[] = "Surnom invalide (lettres, chiffres, underscore)";
            if (!empty($telephone) && !preg_match('/^[0-9+\-\s()]+$/', $telephone)) $errors[] = "Téléphone invalide";
            
            if (empty($errors)) {
                try {
                    // Vérifier que le surnom n'est pas déjà pris
                    $checkStmt = $pdo->prepare("SELECT id FROM utilisateurs WHERE surnom = ? AND id != ?");
                    $checkStmt->execute([$surnom, $userId]);
                    if ($checkStmt->fetch()) {
                        header('Location: parametres.php?tab=general&error=surnom_taken');
                        exit();
                    }
                    
                    $stmt = $pdo->prepare("
                        UPDATE utilisateurs 
                        SET prenom = ?, nom = ?, surnom = ?, bio = ?, universite = ?, faculte = ?, 
                            niveau_etude = ?, nationalite = ?, telephone = ?
                        WHERE id = ?
                    ");
                    $stmt->execute([$prenom, $nom, $surnom, $bio, $universite, $faculte, $niveau_etude, $nationalite, $telephone, $userId]);
                    
                    // Mettre à jour la session
                    $_SESSION['prenom'] = $prenom;
                    $_SESSION['nom'] = $nom;
                    $_SESSION['surnom'] = $surnom;
                    
                    log_activity($pdo, $userId, 'update_profile', ['fields' => 'general']);
                    header('Location: parametres.php?tab=general&success=general');
                    exit();
                } catch (PDOException $e) {
                    error_log("Update error: " . $e->getMessage());
                    header('Location: parametres.php?tab=general&error=db_error');
                    exit();
                }
            } else {
                header('Location: parametres.php?tab=general&error=validation');
                exit();
            }
            break;
            
        case 'update_avatar':
            if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
                $upload = handle_file_upload($_FILES['avatar'], AVATAR_DIR, ALLOWED_IMAGE_TYPES, 2 * 1024 * 1024);
                if ($upload['success']) {
                    // Supprimer l'ancien avatar
                    $oldAvatar = $user['avatar'];
                    if ($oldAvatar && $oldAvatar !== DEFAULT_AVATAR && file_exists(AVATAR_DIR . $oldAvatar)) {
                        unlink(AVATAR_DIR . $oldAvatar);
                    }
                    
                    $stmt = $pdo->prepare("UPDATE utilisateurs SET avatar = ? WHERE id = ?");
                    $stmt->execute([$upload['filename'], $userId]);
                    
                    $_SESSION['avatar'] = $upload['filename'];
                    log_activity($pdo, $userId, 'update_avatar');
                    header('Location: parametres.php?tab=avatar&success=avatar');
                    exit();
                } else {
                    header('Location: parametres.php?tab=avatar&error=' . urlencode($upload['error']));
                    exit();
                }
            }
            break;
            
        case 'reset_avatar':
            $oldAvatar = $user['avatar'];
            if ($oldAvatar && $oldAvatar !== DEFAULT_AVATAR && file_exists(AVATAR_DIR . $oldAvatar)) {
                unlink(AVATAR_DIR . $oldAvatar);
            }
            
            $stmt = $pdo->prepare("UPDATE utilisateurs SET avatar = ? WHERE id = ?");
            $stmt->execute([DEFAULT_AVATAR, $userId]);
            
            $_SESSION['avatar'] = DEFAULT_AVATAR;
            header('Location: parametres.php?tab=avatar&success=avatar_reset');
            exit();
            break;
            
        case 'update_password':
            $current = $_POST['current_password'] ?? '';
            $new = $_POST['new_password'] ?? '';
            $confirm = $_POST['confirm_password'] ?? '';
            
            // Vérifier le mot de passe actuel
            if (!verify_password($current, $user['motdepasse'])) {
                header('Location: parametres.php?tab=security&error=current_password');
                exit();
            }
            
            // Validation du nouveau mot de passe
            $strengthErrors = validate_password_strength($new);
            if (!empty($strengthErrors)) {
                header('Location: parametres.php?tab=security&error=weak_password');
                exit();
            }
            
            if ($new !== $confirm) {
                header('Location: parametres.php?tab=security&error=match');
                exit();
            }
            
            $newHash = hash_password($new);
            $stmt = $pdo->prepare("UPDATE utilisateurs SET motdepasse = ? WHERE id = ?");
            $stmt->execute([$newHash, $userId]);
            
            log_activity($pdo, $userId, 'password_change');
            header('Location: parametres.php?tab=security&success=password');
            exit();
            break;
            
        case 'update_preferences':
            $dark_mode = isset($_POST['dark_mode']) ? 1 : 0;
            $email_notifications = isset($_POST['email_notifications']) ? 1 : 0;
            $like_notifications = isset($_POST['like_notifications']) ? 1 : 0;
            $comment_notifications = isset($_POST['comment_notifications']) ? 1 : 0;
            $friend_notifications = isset($_POST['friend_notifications']) ? 1 : 0;
            $message_notifications = isset($_POST['message_notifications']) ? 1 : 0;
            $language = $_POST['language'] ?? 'fr';
            $timezone = $_POST['timezone'] ?? 'Europe/Paris';
            
            try {
                // Vérifier si les préférences existent
                $checkStmt = $pdo->prepare("SELECT id FROM user_preferences WHERE user_id = ?");
                $checkStmt->execute([$userId]);
                
                if ($checkStmt->fetch()) {
                    $stmt = $pdo->prepare("
                        UPDATE user_preferences SET 
                            dark_mode = ?, email_notifications = ?, like_notifications = ?,
                            comment_notifications = ?, friend_notifications = ?, message_notifications = ?,
                            language = ?, timezone = ?, updated_at = NOW()
                        WHERE user_id = ?
                    ");
                    $stmt->execute([$dark_mode, $email_notifications, $like_notifications, 
                                   $comment_notifications, $friend_notifications, $message_notifications,
                                   $language, $timezone, $userId]);
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO user_preferences (
                            user_id, dark_mode, email_notifications, like_notifications,
                            comment_notifications, friend_notifications, message_notifications,
                            language, timezone, created_at, updated_at
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                    ");
                    $stmt->execute([$userId, $dark_mode, $email_notifications, $like_notifications,
                                   $comment_notifications, $friend_notifications, $message_notifications,
                                   $language, $timezone]);
                }
                
                log_activity($pdo, $userId, 'update_preferences');
                header('Location: parametres.php?tab=notifications&success=preferences');
                exit();
            } catch (PDOException $e) {
                error_log("Error updating preferences: " . $e->getMessage());
                header('Location: parametres.php?tab=notifications&error=db_error');
                exit();
            }
            break;
            
        case 'delete_account':
            $confirm = $_POST['confirm_delete'] ?? '';
            $password = $_POST['delete_password'] ?? '';
            
            if ($confirm !== 'SUPPRIMER') {
                header('Location: parametres.php?tab=delete&error=confirm');
                exit();
            }
            
            if (!verify_password($password, $user['motdepasse'])) {
                header('Location: parametres.php?tab=delete&error=password');
                exit();
            }
            
            try {
                // Suppression logique (désactivation)
                $stmt = $pdo->prepare("
                    UPDATE utilisateurs 
                    SET is_active = 0, 
                        email = CONCAT(email, '_deleted_', UNIX_TIMESTAMP()),
                        status = 'Offline'
                    WHERE id = ?
                ");
                $stmt->execute([$userId]);
                
                log_activity($pdo, $userId, 'account_deleted');
                
                session_destroy();
                header('Location: connexion.php?deleted=1');
                exit();
            } catch (PDOException $e) {
                error_log("Error deleting account: " . $e->getMessage());
                header('Location: parametres.php?tab=delete&error=db_error');
                exit();
            }
            break;
    }
}

$csrfToken = generate_csrf_token();
$page_title = 'Paramètres';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Paramètres - WideMaze</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f3f4f6; }
        .tab-btn { transition: all 0.2s; }
        .tab-active { border-bottom: 3px solid #f59e0b; color: #f59e0b; }
        .password-strength { transition: width 0.3s; }
        .stat-card { transition: all 0.3s ease; }
        .stat-card:hover { transform: translateY(-2px); box-shadow: 0 10px 25px -5px rgba(0,0,0,0.1); }
    </style>
</head>
<body class="bg-gray-100">
    <!-- Navigation -->
    <nav class="fixed top-0 w-full bg-white shadow-md z-50 border-b border-gray-200">
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
                <a href="notifications.php" class="p-2 hover:bg-gray-100 rounded-full transition-colors">
                    <i class="fas fa-bell text-xl text-gray-600"></i>
                </a>
                <a href="messagerie.php" class="p-2 hover:bg-gray-100 rounded-full transition-colors">
                    <i class="fas fa-comment text-xl text-gray-600"></i>
                </a>
                <div class="relative group">
                    <button class="flex items-center gap-2 p-1">
                        <img src="<?= get_avatar_url($_SESSION['avatar'] ?? '') ?>" class="w-8 h-8 rounded-full object-cover border-2 border-orange-500">
                    </button>
                    <div class="absolute right-0 top-full mt-2 w-48 bg-white rounded-xl shadow-xl opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all duration-200 z-50">
                        <a href="profil.php" class="block px-4 py-2 hover:bg-gray-50 rounded-t-xl">Mon profil</a>
                        <a href="parametres.php" class="block px-4 py-2 hover:bg-gray-50 bg-orange-50 text-orange-600">Paramètres</a>
                        <hr>
                        <a href="deconnexion.php" class="block px-4 py-2 hover:bg-gray-50 rounded-b-xl text-red-600">Déconnexion</a>
                    </div>
                </div>
            </div>
        </div>
    </nav>
    
    <div class="container mx-auto pt-20 pb-8 px-4 max-w-5xl">
        <div class="bg-white rounded-2xl shadow-sm overflow-hidden">
            <!-- Header -->
            <div class="bg-gradient-to-r from-orange-50 to-pink-50 p-6 border-b">
                <h1 class="text-2xl font-bold text-gray-800">Paramètres du compte</h1>
                <p class="text-gray-600 mt-1">Gérez vos informations personnelles et les préférences de votre compte</p>
            </div>
            
            <!-- Tabs Navigation -->
            <div class="flex overflow-x-auto border-b scrollbar-hide">
                <a href="?tab=general" class="tab-btn px-6 py-4 font-medium text-sm whitespace-nowrap transition-colors <?= $activeTab == 'general' ? 'tab-active text-orange-600' : 'text-gray-600 hover:text-gray-800' ?>">
                    <i class="fas fa-user mr-2"></i>Général
                </a>
                <a href="?tab=avatar" class="tab-btn px-6 py-4 font-medium text-sm whitespace-nowrap transition-colors <?= $activeTab == 'avatar' ? 'tab-active text-orange-600' : 'text-gray-600 hover:text-gray-800' ?>">
                    <i class="fas fa-camera mr-2"></i>Photo de profil
                </a>
                <a href="?tab=security" class="tab-btn px-6 py-4 font-medium text-sm whitespace-nowrap transition-colors <?= $activeTab == 'security' ? 'tab-active text-orange-600' : 'text-gray-600 hover:text-gray-800' ?>">
                    <i class="fas fa-lock mr-2"></i>Sécurité
                </a>
                <a href="?tab=privacy" class="tab-btn px-6 py-4 font-medium text-sm whitespace-nowrap transition-colors <?= $activeTab == 'privacy' ? 'tab-active text-orange-600' : 'text-gray-600 hover:text-gray-800' ?>">
                    <i class="fas fa-shield-alt mr-2"></i>Confidentialité
                </a>
                <a href="?tab=notifications" class="tab-btn px-6 py-4 font-medium text-sm whitespace-nowrap transition-colors <?= $activeTab == 'notifications' ? 'tab-active text-orange-600' : 'text-gray-600 hover:text-gray-800' ?>">
                    <i class="fas fa-bell mr-2"></i>Notifications
                </a>
                <a href="?tab=delete" class="tab-btn px-6 py-4 font-medium text-sm whitespace-nowrap transition-colors <?= $activeTab == 'delete' ? 'tab-active text-red-600' : 'text-red-500 hover:text-red-700' ?>">
                    <i class="fas fa-trash-alt mr-2"></i>Supprimer le compte
                </a>
            </div>
            
            <!-- Messages de succès -->
            <?php if ($success): ?>
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
                                    'preferences' => 'Préférences enregistrées'
                                ];
                                echo $messages[$success] ?? 'Action effectuée avec succès';
                            ?>
                        </span>
                    </div>
                </div>
            <?php endif; ?>
            
            <!-- Messages d'erreur -->
            <?php if ($error): ?>
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
                                    'confirm' => 'Vous devez taper "SUPPRIMER" pour confirmer',
                                    'password' => 'Mot de passe incorrect'
                                ];
                                echo $errorMessages[$error] ?? 'Une erreur est survenue';
                            ?>
                        </span>
                    </div>
                </div>
            <?php endif; ?>
            
            <!-- TAB: General -->
            <?php if ($activeTab == 'general'): ?>
                <div class="p-6">
                    <h3 class="text-2xl font-bold text-gray-800 mb-6">Informations générales</h3>
                    <form method="POST" action="" class="space-y-6 max-w-2xl">
                        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                        <input type="hidden" name="form_action" value="update_general">
                        
                        <div class="grid md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Prénom *</label>
                                <input type="text" name="prenom" value="<?= htmlspecialchars($user['prenom'] ?? '') ?>" required
                                       class="w-full px-4 py-2 rounded-lg border border-gray-300 focus:border-orange-500 focus:ring-2 focus:ring-orange-200 outline-none transition-all">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Nom *</label>
                                <input type="text" name="nom" value="<?= htmlspecialchars($user['nom'] ?? '') ?>" required
                                       class="w-full px-4 py-2 rounded-lg border border-gray-300 focus:border-orange-500 focus:ring-2 focus:ring-orange-200 outline-none transition-all">
                            </div>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Pseudonyme *</label>
                            <div class="relative">
                                <span class="absolute left-4 top-1/2 -translate-y-1/2 text-gray-400">@</span>
                                <input type="text" name="surnom" value="<?= htmlspecialchars($user['surnom'] ?? '') ?>" required
                                       class="w-full pl-8 pr-4 py-2 rounded-lg border border-gray-300 focus:border-orange-500 focus:ring-2 focus:ring-orange-200 outline-none transition-all">
                            </div>
                            <p class="text-xs text-gray-500 mt-1">Lettres, chiffres et underscores uniquement</p>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Bio</label>
                            <textarea name="bio" rows="4" maxlength="500" 
                                      class="w-full px-4 py-2 rounded-lg border border-gray-300 focus:border-orange-500 focus:ring-2 focus:ring-orange-200 outline-none transition-all resize-none"
                                      placeholder="Décrivez-vous en quelques mots..."><?= htmlspecialchars($user['bio'] ?? '') ?></textarea>
                            <div class="flex justify-between items-center mt-1">
                                <p class="text-xs text-gray-500">Maximum 500 caractères</p>
                                <p class="text-xs text-gray-500"><span id="bioCount"><?= strlen($user['bio'] ?? '') ?></span>/500</p>
                            </div>
                        </div>
                        
                        <div class="grid md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Université</label>
                                <input type="text" name="universite" value="<?= htmlspecialchars($user['universite'] ?? '') ?>"
                                       class="w-full px-4 py-2 rounded-lg border border-gray-300 focus:border-orange-500 focus:ring-2 focus:ring-orange-200 outline-none transition-all"
                                       placeholder="Ex: Université de Kinshasa">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Faculté</label>
                                <input type="text" name="faculte" value="<?= htmlspecialchars($user['faculte'] ?? '') ?>"
                                       class="w-full px-4 py-2 rounded-lg border border-gray-300 focus:border-orange-500 focus:ring-2 focus:ring-orange-200 outline-none transition-all"
                                       placeholder="Ex: Sciences Informatiques">
                            </div>
                        </div>
                        
                        <div class="grid md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Niveau d'études</label>
                                <input type="text" name="niveau_etude" value="<?= htmlspecialchars($user['niveau_etude'] ?? '') ?>"
                                       class="w-full px-4 py-2 rounded-lg border border-gray-300 focus:border-orange-500 focus:ring-2 focus:ring-orange-200 outline-none transition-all"
                                       placeholder="Licence 3, Master 1...">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Pays</label>
                                <input type="text" name="nationalite" value="<?= htmlspecialchars($user['nationalite'] ?? '') ?>"
                                       class="w-full px-4 py-2 rounded-lg border border-gray-300 focus:border-orange-500 focus:ring-2 focus:ring-orange-200 outline-none transition-all"
                                       placeholder="Ex: France, RDC, Canada...">
                            </div>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Téléphone</label>
                            <input type="tel" name="telephone" value="<?= htmlspecialchars($user['telephone'] ?? '') ?>"
                                   class="w-full px-4 py-2 rounded-lg border border-gray-300 focus:border-orange-500 focus:ring-2 focus:ring-orange-200 outline-none transition-all"
                                   placeholder="+243 823 851 403">
                            <p class="text-xs text-gray-500 mt-1">Format international recommandé</p>
                        </div>
                        
                        <div class="pt-4">
                            <button type="submit" class="bg-orange-500 hover:bg-orange-600 text-white px-6 py-2.5 rounded-lg font-medium transition-colors shadow-sm hover:shadow-md">
                                <i class="fas fa-save mr-2"></i>Enregistrer les modifications
                            </button>
                        </div>
                    </form>
                </div>
            <?php endif; ?>
            
            <!-- TAB: Avatar -->
            <?php if ($activeTab == 'avatar'): ?>
                <div class="p-6 text-center">
                    <div class="relative mb-6 inline-block group">
                        <div class="w-40 h-40 rounded-full overflow-hidden border-4 border-gray-200 shadow-lg">
                            <img src="<?= get_avatar_url($user['avatar'] ?? '') ?>" id="avatarPreview" class="w-full h-full object-cover">
                        </div>
                        <div class="absolute bottom-0 right-0 w-10 h-10 bg-orange-500 rounded-full flex items-center justify-center text-white shadow-lg group-hover:scale-110 transition-transform">
                            <i class="fas fa-camera"></i>
                        </div>
                    </div>
                    
                    <form method="POST" action="" enctype="multipart/form-data" class="text-center">
                        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                        <input type="hidden" name="form_action" value="update_avatar">
                        
                        <div class="mb-4">
                            <input type="file" name="avatar" id="avatarInput" accept="image/*" required
                                   class="block w-full max-w-xs mx-auto text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-orange-500 file:text-white hover:file:bg-orange-600 file:cursor-pointer file:transition-colors"
                                   onchange="previewAvatar(this)">
                        </div>
                        <p class="text-sm text-gray-500 mb-4">Formats acceptés: JPG, PNG, GIF, WebP. Max 2MB.</p>
                        <button type="submit" class="bg-orange-500 hover:bg-orange-600 text-white px-6 py-2.5 rounded-lg font-medium transition-colors shadow-sm hover:shadow-md">
                            <i class="fas fa-upload mr-2"></i>Mettre à jour la photo
                        </button>
                    </form>
                    
                    <form method="POST" action="" class="mt-4">
                        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                        <input type="hidden" name="form_action" value="reset_avatar">
                        <button type="submit" class="bg-gray-500 hover:bg-gray-600 text-white px-5 py-2 rounded-lg transition-colors text-sm">
                            <i class="fas fa-undo-alt mr-2"></i>Réinitialiser l'avatar
                        </button>
                    </form>
                    
                    <div class="mt-8 bg-blue-50 border border-blue-200 rounded-lg p-4 text-left max-w-md mx-auto">
                        <h4 class="font-semibold text-blue-800 mb-2 flex items-center gap-2">
                            <i class="fas fa-info-circle"></i>Conseils
                        </h4>
                        <ul class="text-sm text-blue-700 space-y-1 list-disc list-inside">
                            <li>Utilisez une photo où votre visage est clairement visible</li>
                            <li>Évitez les images contenant du texte ou des logos</li>
                            <li>La photo doit respecter les conditions d'utilisation</li>
                            <li>Format recommandé : carré, 400x400 pixels</li>
                        </ul>
                    </div>
                </div>
            <?php endif; ?>
            
            <!-- TAB: Security -->
            <?php if ($activeTab == 'security'): ?>
                <div class="p-6">
                    <h3 class="text-2xl font-bold text-gray-800 mb-6">Sécurité du compte</h3>
                    <div class="space-y-8 max-w-2xl">
                        <!-- Change Password -->
                        <div class="bg-gray-50 rounded-xl p-6">
                            <h4 class="font-semibold text-lg mb-4 flex items-center gap-2">
                                <i class="fas fa-key text-orange-500"></i>Changer le mot de passe
                            </h4>
                            <form method="POST" action="" class="space-y-4">
                                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                <input type="hidden" name="form_action" value="update_password">
                                
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Mot de passe actuel</label>
                                    <div class="relative">
                                        <input type="password" name="current_password" required
                                               class="w-full px-4 py-2.5 rounded-lg border border-gray-300 focus:border-orange-500 focus:ring-2 focus:ring-orange-200 outline-none transition-all">
                                        <button type="button" onclick="togglePassword(this)" class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </div>
                                </div>
                                
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Nouveau mot de passe</label>
                                    <div class="relative">
                                        <input type="password" name="new_password" id="newPassword" required oninput="checkPasswordStrength(this.value)"
                                               class="w-full px-4 py-2.5 rounded-lg border border-gray-300 focus:border-orange-500 focus:ring-2 focus:ring-orange-200 outline-none transition-all">
                                        <button type="button" onclick="togglePassword(this)" class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </div>
                                    <div class="mt-2 h-1 bg-gray-200 rounded-full overflow-hidden">
                                        <div id="passwordStrength" class="password-strength w-0 bg-red-500 h-full rounded-full"></div>
                                    </div>
                                    <p id="passwordFeedback" class="text-xs text-gray-500 mt-1">
                                        <i class="fas fa-shield-alt mr-1"></i>Minimum 8 caractères, 1 majuscule, 1 chiffre, 1 symbole
                                    </p>
                                </div>
                                
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Confirmer le nouveau mot de passe</label>
                                    <div class="relative">
                                        <input type="password" name="confirm_password" id="confirmPassword" required
                                               class="w-full px-4 py-2.5 rounded-lg border border-gray-300 focus:border-orange-500 focus:ring-2 focus:ring-orange-200 outline-none transition-all"
                                               oninput="checkPasswordMatch()">
                                        <span id="matchStatus" class="absolute right-3 top-1/2 -translate-y-1/2"></span>
                                    </div>
                                </div>
                                
                                <button type="submit" class="bg-orange-500 hover:bg-orange-600 text-white px-6 py-2.5 rounded-lg font-medium transition-colors shadow-sm hover:shadow-md">
                                    <i class="fas fa-sync-alt mr-2"></i>Mettre à jour le mot de passe
                                </button>
                            </form>
                        </div>
                        
                        <!-- Active Sessions -->
                        <div class="bg-gray-50 rounded-xl p-6">
                            <h4 class="font-semibold text-lg mb-4 flex items-center gap-2">
                                <i class="fas fa-desktop text-orange-500"></i>Sessions actives
                            </h4>
                            <div class="flex items-center justify-between p-4 bg-white rounded-lg border">
                                <div class="flex items-center gap-3">
                                    <i class="fas fa-laptop text-gray-400 text-xl"></i>
                                    <div>
                                        <p class="font-medium text-gray-800">Cet appareil</p>
                                        <p class="text-xs text-gray-500"><?= htmlspecialchars($_SERVER['HTTP_USER_AGENT'] ?? 'Navigateur inconnu') ?></p>
                                    </div>
                                </div>
                                <span class="px-3 py-1 bg-green-100 text-green-700 text-xs rounded-full font-medium">
                                    <i class="fas fa-circle text-[8px] mr-1"></i>Actif
                                </span>
                            </div>
                            <p class="text-xs text-gray-500 mt-3 text-center">
                                <i class="fas fa-info-circle mr-1"></i>Déconnectez-vous des autres appareils en changeant votre mot de passe
                            </p>
                        </div>
                        
                        <!-- 2FA Placeholder -->
                        <div class="bg-gray-50 rounded-xl p-6">
                            <h4 class="font-semibold text-lg mb-4 flex items-center gap-2">
                                <i class="fas fa-shield-alt text-orange-500"></i>Authentification à deux facteurs
                            </h4>
                            <p class="text-gray-600 mb-4">Ajoutez une couche de sécurité supplémentaire à votre compte.</p>
                            <button disabled class="bg-gray-300 text-gray-500 px-6 py-2.5 rounded-lg font-medium cursor-not-allowed">
                                <i class="fas fa-clock mr-2"></i>Bientôt disponible
                            </button>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
            
            <!-- TAB: Privacy -->
            <?php if ($activeTab == 'privacy'): ?>
                <div class="p-6">
                    <h3 class="text-2xl font-bold text-gray-800 mb-6">Confidentialité</h3>
                    <form method="POST" action="" class="space-y-6 max-w-2xl">
                        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                        <input type="hidden" name="form_action" value="update_privacy">
                        
                        <div class="space-y-4">
                            <div class="flex items-center justify-between p-4 bg-gray-50 rounded-lg">
                                <div>
                                    <h4 class="font-medium text-gray-800">Compte actif</h4>
                                    <p class="text-sm text-gray-500">Votre profil est visible par les autres utilisateurs</p>
                                </div>
                                <label class="relative inline-flex items-center cursor-pointer">
                                    <input type="checkbox" name="is_active" value="1" <?= $user['is_active'] ? 'checked' : '' ?> 
                                           class="sr-only peer" onchange="this.form.submit()">
                                    <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-orange-500"></div>
                                </label>
                            </div>
                            
                            <div class="flex items-center justify-between p-4 bg-gray-50 rounded-lg opacity-50">
                                <div>
                                    <h4 class="font-medium text-gray-800">Profil privé</h4>
                                    <p class="text-sm text-gray-500">Seuls vos amis peuvent voir vos publications</p>
                                </div>
                                <label class="relative inline-flex items-center cursor-pointer">
                                    <input type="checkbox" disabled class="sr-only peer">
                                    <div class="w-11 h-6 bg-gray-200 rounded-full peer after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5"></div>
                                </label>
                            </div>
                            <p class="text-xs text-orange-600 flex items-center gap-1">
                                <i class="fas fa-tools"></i>Fonctionnalité en développement
                            </p>
                        </div>
                        
                        <div class="pt-4">
                            <button type="submit" class="bg-orange-500 hover:bg-orange-600 text-white px-6 py-2.5 rounded-lg font-medium transition-colors">
                                <i class="fas fa-save mr-2"></i>Enregistrer les préférences
                            </button>
                        </div>
                    </form>
                </div>
            <?php endif; ?>
            
            <!-- TAB: Notifications -->
            <?php if ($activeTab == 'notifications'): ?>
                <div class="p-6">
                    <h3 class="text-2xl font-bold text-gray-800 mb-6">Préférences de notification</h3>
                    <form method="POST" action="" class="space-y-4 max-w-2xl">
                        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                        <input type="hidden" name="form_action" value="update_preferences">
                        
                        <div class="flex items-center justify-between p-4 bg-gray-50 rounded-lg">
                            <div>
                                <h4 class="font-medium text-gray-800">Notifications par email</h4>
                                <p class="text-sm text-gray-500">Recevoir les notifications importantes par email</p>
                            </div>
                            <label class="relative inline-flex items-center cursor-pointer">
                                <input type="checkbox" name="email_notifications" value="1" <?= $preferences['email_notifications'] ? 'checked' : '' ?> 
                                       class="sr-only peer">
                                <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-orange-500"></div>
                            </label>
                        </div>
                        
                        <div class="flex items-center justify-between p-4 bg-gray-50 rounded-lg">
                            <div>
                                <h4 class="font-medium text-gray-800">Likes sur mes publications</h4>
                                <p class="text-sm text-gray-500">Notifier quand quelqu'un aime votre contenu</p>
                            </div>
                            <label class="relative inline-flex items-center cursor-pointer">
                                <input type="checkbox" name="like_notifications" value="1" <?= $preferences['like_notifications'] ? 'checked' : '' ?> 
                                       class="sr-only peer">
                                <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-orange-500"></div>
                            </label>
                        </div>
                        
                        <div class="flex items-center justify-between p-4 bg-gray-50 rounded-lg">
                            <div>
                                <h4 class="font-medium text-gray-800">Commentaires</h4>
                                <p class="text-sm text-gray-500">Notifier les nouveaux commentaires</p>
                            </div>
                            <label class="relative inline-flex items-center cursor-pointer">
                                <input type="checkbox" name="comment_notifications" value="1" <?= $preferences['comment_notifications'] ? 'checked' : '' ?> 
                                       class="sr-only peer">
                                <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-orange-500"></div>
                            </label>
                        </div>
                        
                        <div class="flex items-center justify-between p-4 bg-gray-50 rounded-lg">
                            <div>
                                <h4 class="font-medium text-gray-800">Demandes d'ami</h4>
                                <p class="text-sm text-gray-500">Notifier les nouvelles demandes d'ami</p>
                            </div>
                            <label class="relative inline-flex items-center cursor-pointer">
                                <input type="checkbox" name="friend_notifications" value="1" <?= $preferences['friend_notifications'] ? 'checked' : '' ?> 
                                       class="sr-only peer">
                                <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-orange-500"></div>
                            </label>
                        </div>
                        
                        <div class="flex items-center justify-between p-4 bg-gray-50 rounded-lg">
                            <div>
                                <h4 class="font-medium text-gray-800">Messages privés</h4>
                                <p class="text-sm text-gray-500">Notifier les nouveaux messages privés</p>
                            </div>
                            <label class="relative inline-flex items-center cursor-pointer">
                                <input type="checkbox" name="message_notifications" value="1" <?= $preferences['message_notifications'] ? 'checked' : '' ?> 
                                       class="sr-only peer">
                                <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-orange-500"></div>
                            </label>
                        </div>
                        
                        <div class="grid md:grid-cols-2 gap-4 pt-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Langue</label>
                                <select name="language" class="w-full px-4 py-2 rounded-lg border border-gray-300 focus:border-orange-500 focus:ring-2 focus:ring-orange-200 outline-none transition-all bg-white">
                                    <option value="fr" <?= $preferences['language'] == 'fr' ? 'selected' : '' ?>>Français</option>
                                    <option value="en" <?= $preferences['language'] == 'en' ? 'selected' : '' ?>>English</option>
                                    <option value="es" <?= $preferences['language'] == 'es' ? 'selected' : '' ?>>Español</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Fuseau horaire</label>
                                <select name="timezone" class="w-full px-4 py-2 rounded-lg border border-gray-300 focus:border-orange-500 focus:ring-2 focus:ring-orange-200 outline-none transition-all bg-white">
                                    <option value="Europe/Paris" <?= $preferences['timezone'] == 'Europe/Paris' ? 'selected' : '' ?>>Paris (UTC+1)</option>
                                    <option value="Africa/Kinshasa" <?= $preferences['timezone'] == 'Africa/Kinshasa' ? 'selected' : '' ?>>Kinshasa (UTC+1)</option>
                                    <option value="America/New_York" <?= $preferences['timezone'] == 'America/New_York' ? 'selected' : '' ?>>New York (UTC-5)</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="pt-4">
                            <button type="submit" class="bg-orange-500 hover:bg-orange-600 text-white px-6 py-2.5 rounded-lg font-medium transition-colors">
                                <i class="fas fa-save mr-2"></i>Enregistrer les préférences
                            </button>
                        </div>
                    </form>
                </div>
            <?php endif; ?>
            
            <!-- TAB: Delete Account -->
            <?php if ($activeTab == 'delete'): ?>
                <div class="p-6">
                    <h3 class="text-2xl font-bold text-red-600 mb-6 flex items-center gap-2">
                        <i class="fas fa-exclamation-triangle"></i>Supprimer le compte
                    </h3>
                    <div class="bg-red-50 border border-red-200 rounded-xl p-6 mb-6">
                        <h4 class="font-bold text-red-800 mb-2">⚠️ Attention ! Cette action est irréversible</h4>
                        <p class="text-red-700 text-sm mb-4">La suppression de votre compte entraînera :</p>
                        <ul class="text-red-700 text-sm space-y-2 list-disc list-inside">
                            <li>La désactivation immédiate de votre profil</li>
                            <li>La suppression de toutes vos publications</li>
                            <li>La perte de tous vos contacts et messages</li>
                            <li>L'impossibilité de récupérer vos données</li>
                            <li>La libération de votre pseudonyme</li>
                        </ul>
                    </div>
                    <form method="POST" action="" class="max-w-2xl space-y-4" onsubmit="return confirmDelete()">
                        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                        <input type="hidden" name="form_action" value="delete_account">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Pour confirmer, tapez <span class="font-mono font-bold bg-gray-100 px-2 py-1 rounded">SUPPRIMER</span></label>
                            <input type="text" name="confirm_delete" required pattern="SUPPRIMER"
                                   class="w-full px-4 py-2.5 rounded-lg border border-red-300 focus:border-red-500 focus:ring-2 focus:ring-red-200 outline-none transition-all font-mono uppercase"
                                   placeholder="SUPPRIMER">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Entrez votre mot de passe</label>
                            <div class="relative">
                                <input type="password" name="delete_password" required
                                       class="w-full px-4 py-2.5 rounded-lg border border-gray-300 focus:border-red-500 focus:ring-2 focus:ring-red-200 outline-none transition-all">
                                <button type="button" onclick="togglePassword(this)" class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                        </div>
                        <div class="pt-4">
                            <button type="submit" class="bg-red-600 hover:bg-red-700 text-white px-6 py-2.5 rounded-lg font-medium transition-colors shadow-sm hover:shadow-md flex items-center gap-2">
                                <i class="fas fa-trash-alt"></i>Supprimer définitivement mon compte
                            </button>
                        </div>
                    </form>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <script>
        // Toggle password visibility
        function togglePassword(btn) {
            const input = btn.parentElement.querySelector('input');
            const icon = btn.querySelector('i');
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        }
        
        // Password strength checker
        function checkPasswordStrength(password) {
            const strengthBar = document.getElementById('passwordStrength');
            const feedback = document.getElementById('passwordFeedback');
            
            let strength = 0;
            if (password.length >= 8) strength++;
            if (/[A-Z]/.test(password)) strength++;
            if (/[0-9]/.test(password)) strength++;
            if (/[^A-Za-z0-9]/.test(password)) strength++;
            
            const colors = ['bg-red-500', 'bg-orange-500', 'bg-yellow-500', 'bg-green-500', 'bg-green-600'];
            const widths = ['20%', '40%', '60%', '80%', '100%'];
            const texts = ['Très faible', 'Faible', 'Moyen', 'Fort', 'Très fort'];
            
            if (strengthBar) {
                strengthBar.className = `password-strength ${colors[strength-1] || 'bg-red-500'} h-full rounded-full`;
                strengthBar.style.width = widths[strength-1] || '0%';
            }
            if (feedback) {
                feedback.innerHTML = `<i class="fas fa-${strength >= 3 ? 'check-circle text-green-500' : 'exclamation-triangle text-orange-500'} mr-1"></i>${texts[strength-1] || 'Minimum 8 caractères, 1 majuscule, 1 chiffre, 1 symbole'}`;
                feedback.className = `text-xs mt-1 ${strength >= 3 ? 'text-green-600' : 'text-orange-600'}`;
            }
        }
        
        // Password match checker
        function checkPasswordMatch() {
            const pwd1 = document.getElementById('newPassword')?.value || '';
            const pwd2 = document.getElementById('confirmPassword')?.value || '';
            const icon = document.getElementById('matchStatus');
            
            if (pwd2 && pwd1 === pwd2) {
                icon.innerHTML = '<i class="fas fa-check-circle text-green-500"></i>';
            } else if (pwd2) {
                icon.innerHTML = '<i class="fas fa-times-circle text-red-500"></i>';
            } else {
                icon.innerHTML = '';
            }
        }
        
        // Avatar preview
        function previewAvatar(input) {
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    document.getElementById('avatarPreview').src = e.target.result;
                };
                reader.readAsDataURL(input.files[0]);
            }
        }
        
        // Bio counter
        const bioTextarea = document.querySelector('textarea[name="bio"]');
        if (bioTextarea) {
            const bioCount = document.getElementById('bioCount');
            bioTextarea.addEventListener('input', function() {
                if (bioCount) bioCount.textContent = this.value.length;
            });
        }
        
        // Confirm delete
        function confirmDelete() {
            return confirm('Êtes-vous absolument sûr de vouloir supprimer votre compte ? Cette action est définitive et toutes vos données seront perdues.');
        }
    </script>
</body>
</html>