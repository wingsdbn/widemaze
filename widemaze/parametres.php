<?php
require_once 'config.php';
require_auth();

$userId = $_SESSION['user_id'];
$activeTab = $_GET['tab'] ?? 'general';
$success = $_GET['success'] ?? '';
$error = $_GET['error'] ?? '';

// Traitement des formulaires
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        header('Location: parametres.php?error=csrf');
        exit();
    }

    $action = $_POST['form_action'] ?? '';

    switch ($action) {
        case 'update_general':
            $prenom = trim($_POST['prenom'] ?? '');
            $nom = trim($_POST['nom'] ?? '');
            $surnom = trim($_POST['surnom'] ?? '');
            $bio = trim($_POST['bio'] ?? '');
            $universite = trim($_POST['universite'] ?? '');
            $faculte = trim($_POST['faculte'] ?? '');
            $niveau_etude = trim($_POST['niveau_etude'] ?? '');
            $profession = trim($_POST['profession'] ?? '');
            $nationalite = trim($_POST['nationalite'] ?? '');
            $telephone = trim($_POST['telephone'] ?? '');
            $datedenaissance = $_POST['datedenaissance'] ?? null;

            // Validation téléphone si fourni
            if (!empty($telephone) && !preg_match('/^[\d\s\+\-\(\)]+$/', $telephone)) {
                header('Location: parametres.php?tab=general&error=invalid_phone');
                exit();
            }

            try {
                $stmt = $pdo->prepare("UPDATE utilisateurs SET 
                    prenom = ?, nom = ?, surnom = ?, bio = ?, universite = ?, 
                    faculte = ?, niveau_etude = ?, profession = ?, nationalite = ?, 
                    telephone = ?, datedenaissance = ? WHERE id = ?");
                $stmt->execute([$prenom, $nom, $surnom, $bio, $universite, 
                    $faculte, $niveau_etude, $profession, $nationalite, 
                    $telephone, $datedenaissance, $userId]);
                
                // Mettre à jour la session
                $_SESSION['surnom'] = $surnom;
                
                log_activity($pdo, $userId, 'update_profile', ['fields' => 'general']);
                header('Location: parametres.php?tab=general&success=general');
                exit();
            } catch (PDOException $e) {
                $error = 'db_error';
            }
            break;

        case 'update_avatar':
            if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
                // Vérifier que le dossier existe
                if (!is_dir(AVATAR_DIR)) {
                    mkdir(AVATAR_DIR, 0755, true);
                }
                
                $upload = handle_file_upload($_FILES['avatar'], AVATAR_DIR, 
                    ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], 2*1024*1024);
                
                if ($upload['success']) {
                    // Supprimer l'ancien avatar si ce n'est pas default-avatar.png
                    $stmt = $pdo->prepare("SELECT avatar FROM utilisateurs WHERE id = ?");
                    $stmt->execute([$userId]);
                    $oldAvatar = $stmt->fetchColumn();
                    
                     // Ne supprimer que si ce n'est pas l'avatar par défaut
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
                    $error = $upload['error'];
                }
            }
            break;
        case 'reset_avatar':
            // Supprimer l'avatar actuel si ce n'est pas celui par défaut
            $stmt = $pdo->prepare("SELECT avatar FROM utilisateurs WHERE id = ?");
            $stmt->execute([$userId]);
            $oldAvatar = $stmt->fetchColumn();
            
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
            $stmt = $pdo->prepare("SELECT motdepasse FROM utilisateurs WHERE id = ?");
            $stmt->execute([$userId]);
            $hash = $stmt->fetchColumn();

            if (!password_verify($current, $hash)) {
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

        case 'update_privacy':
            $is_active = isset($_POST['is_active']) ? 1 : 0;
            // Ajouter d'autres paramètres de confidentialité ici
            
            $stmt = $pdo->prepare("UPDATE utilisateurs SET is_active = ? WHERE id = ?");
            $stmt->execute([$is_active, $userId]);
            
            header('Location: parametres.php?tab=privacy&success=privacy');
            exit();
            break;

        case 'delete_account':
            $confirm = $_POST['confirm_delete'] ?? '';
            $password = $_POST['delete_password'] ?? '';
            
            if ($confirm !== 'SUPPRIMER') {
                header('Location: parametres.php?tab=delete&error=confirm');
                exit();
            }

            // Vérifier le mot de passe
            $stmt = $pdo->prepare("SELECT motdepasse FROM utilisateurs WHERE id = ?");
            $stmt->execute([$userId]);
            $hash = $stmt->fetchColumn();

            if (!password_verify($password, $hash)) {
                header('Location: parametres.php?tab=delete&error=password');
                exit();
            }

            // Suppression logique (soft delete) ou physique
            // Ici on désactive le compte
            $stmt = $pdo->prepare("UPDATE utilisateurs SET is_active = 0, email = CONCAT(email, '.inactive.', UNIX_TIMESTAMP()) WHERE id = ?");
            $stmt->execute([$userId]);
            
            log_activity($pdo, $userId, 'account_deleted');
            session_destroy();
            header('Location: connexion.php?deleted=1');
            exit();
            break;
    }
}

// Récupération des données actuelles
$stmt = $pdo->prepare("SELECT * FROM utilisateurs WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch();

// Messages d'erreur/succès
$messages = [
    'success' => [
        'general' => 'Profil mis à jour avec succès',
        'avatar' => 'Photo de profil mise à jour',
        'password' => 'Mot de passe modifié avec succès',
        'privacy' => 'Paramètres de confidentialité mis à jour'
    ],
    'error' => [
        'csrf' => 'Token de sécurité invalide',
        'current_password' => 'Mot de passe actuel incorrect',
        'weak_password' => 'Le nouveau mot de passe est trop faible',
        'match' => 'Les mots de passe ne correspondent pas',
        'confirm' => 'Veuillez confirmer la suppression',
        'password' => 'Mot de passe incorrect',
        'db_error' => 'Erreur de base de données',
        'invalid_phone' => 'Format de téléphone invalide'
    ]
];

// Ajouter des valeurs par défaut pour les messages qui pourraient manquer
$successMessage = isset($messages['success'][$success]) ? $messages['success'][$success] : '';
$errorMessage = isset($messages['error'][$error]) ? $messages['error'][$error] : '';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Paramètres - WideMaze</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: '#f59e0b',
                        secondary: '#1e293b',
                        danger: '#ef4444',
                    }
                }
            }
        }
    </script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');
        body { font-family: 'Inter', sans-serif; }
        .settings-nav .active { background-color: #fef3c7; color: #d97706; border-right: 3px solid #f59e0b; }
        .password-strength { height: 4px; border-radius: 2px; transition: all 0.3s; }
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
                <a href="profil.php" class="flex items-center gap-2 hover:bg-gray-100 px-3 py-2 rounded-lg transition-colors">
                    <img src="<?= AVATAR_URL . htmlspecialchars($_SESSION['avatar']) ?>" class="w-8 h-8 rounded-full">
                    <span class="hidden md:block font-medium"><?= htmlspecialchars($_SESSION['surnom']) ?></span>
                </a>
            </div>
        </div>
    </nav>

    <div class="container mx-auto pt-20 pb-8 px-4">
        <div class="max-w-5xl mx-auto bg-white rounded-2xl shadow-lg overflow-hidden min-h-[600px]">
            <div class="grid md:grid-cols-4 min-h-[600px]">
                
                <!-- Sidebar Navigation -->
                <div class="bg-gray-50 border-r border-gray-200 p-4">
                    <h2 class="text-lg font-bold text-gray-800 mb-6 px-4">Paramètres</h2>
                    <nav class="space-y-1 settings-nav">
                        <a href="?tab=general" class="flex items-center gap-3 px-4 py-3 rounded-lg hover:bg-gray-100 transition-colors <?= $activeTab === 'general' ? 'active' : 'text-gray-600' ?>">
                            <i class="fas fa-user w-5"></i>
                            <span>Général</span>
                        </a>
                        <a href="?tab=avatar" class="flex items-center gap-3 px-4 py-3 rounded-lg hover:bg-gray-100 transition-colors <?= $activeTab === 'avatar' ? 'active' : 'text-gray-600' ?>">
                            <i class="fas fa-camera w-5"></i>
                            <span>Photo de profil</span>
                        </a>
                        <a href="?tab=security" class="flex items-center gap-3 px-4 py-3 rounded-lg hover:bg-gray-100 transition-colors <?= $activeTab === 'security' ? 'active' : 'text-gray-600' ?>">
                            <i class="fas fa-shield-alt w-5"></i>
                            <span>Sécurité</span>
                        </a>
                        <a href="?tab=privacy" class="flex items-center gap-3 px-4 py-3 rounded-lg hover:bg-gray-100 transition-colors <?= $activeTab === 'privacy' ? 'active' : 'text-gray-600' ?>">
                            <i class="fas fa-lock w-5"></i>
                            <span>Confidentialité</span>
                        </a>
                        <a href="?tab=notifications" class="flex items-center gap-3 px-4 py-3 rounded-lg hover:bg-gray-100 transition-colors <?= $activeTab === 'notifications' ? 'active' : 'text-gray-600' ?>">
                            <i class="fas fa-bell w-5"></i>
                            <span>Notifications</span>
                        </a>
                        <hr class="my-4 border-gray-200">
                        <a href="?tab=delete" class="flex items-center gap-3 px-4 py-3 rounded-lg hover:bg-red-50 text-red-600 transition-colors <?= $activeTab === 'delete' ? 'bg-red-50 border-r-3 border-red-500' : '' ?>">
                            <i class="fas fa-trash-alt w-5"></i>
                            <span>Supprimer compte</span>
                        </a>
                    </nav>
                </div>

                <!-- Content -->
                <div class="md:col-span-3 p-6 md:p-8">
                    
                    <!-- Alert Messages -->
                    <?php if ($success && isset($messages['success'][$success])): ?>
                        <div class="bg-green-50 border-l-4 border-green-500 p-4 rounded-r-lg mb-6 flex items-center gap-3">
                            <i class="fas fa-check-circle text-green-500"></i>
                            <span class="text-green-700"><?= $messages['success'][$success] ?></span>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($error && isset($messages['error'][$error])): ?>
                        <div class="bg-red-50 border-l-4 border-red-500 p-4 rounded-r-lg mb-6 flex items-center gap-3">
                            <i class="fas fa-exclamation-circle text-red-500"></i>
                            <span class="text-red-700"><?= $messages['error'][$error] ?></span>
                        </div>
                    <?php endif; ?>

                    <!-- TAB: General -->
                    <?php if ($activeTab === 'general'): ?>
                        <div>
                            <h3 class="text-2xl font-bold text-gray-800 mb-6">Informations générales</h3>
                            
                            <form method="POST" action="" class="space-y-6 max-w-2xl">
                                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                                <input type="hidden" name="form_action" value="update_general">

                                <div class="grid md:grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-2">Prénom</label>
                                        <input type="text" name="prenom" value="<?= htmlspecialchars($user['prenom']) ?>" required
                                            class="w-full px-4 py-2 rounded-lg border border-gray-300 focus:border-primary focus:ring-2 focus:ring-primary/20 outline-none transition-all">
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-2">Nom</label>
                                        <input type="text" name="nom" value="<?= htmlspecialchars($user['nom']) ?>" required
                                            class="w-full px-4 py-2 rounded-lg border border-gray-300 focus:border-primary focus:ring-2 focus:ring-primary/20 outline-none transition-all">
                                    </div>
                                </div>

                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Pseudonyme</label>
                                    <div class="relative">
                                        <span class="absolute left-4 top-1/2 -translate-y-1/2 text-gray-400">@</span>
                                        <input type="text" name="surnom" value="<?= htmlspecialchars($user['surnom']) ?>" required
                                            class="w-full pl-8 pr-4 py-2 rounded-lg border border-gray-300 focus:border-primary focus:ring-2 focus:ring-primary/20 outline-none transition-all">
                                    </div>
                                </div>

                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Bio</label>
                                    <textarea name="bio" rows="3" maxlength="500"
                                        class="w-full px-4 py-2 rounded-lg border border-gray-300 focus:border-primary focus:ring-2 focus:ring-primary/20 outline-none transition-all resize-none"><?= htmlspecialchars($user['bio'] ?? '') ?></textarea>
                                    <p class="text-xs text-gray-500 mt-1">Maximum 500 caractères</p>
                                </div>

                                <div class="grid md:grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-2">Université</label>
                                        <input type="text" name="universite" value="<?= htmlspecialchars($user['universite'] ?? '') ?>"
                                            class="w-full px-4 py-2 rounded-lg border border-gray-300 focus:border-primary focus:ring-2 focus:ring-primary/20 outline-none transition-all">
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-2">Faculté</label>
                                        <input type="text" name="faculte" value="<?= htmlspecialchars($user['faculte'] ?? '') ?>"
                                            class="w-full px-4 py-2 rounded-lg border border-gray-300 focus:border-primary focus:ring-2 focus:ring-primary/20 outline-none transition-all">
                                    </div>
                                </div>

                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Niveau d'études</label>
                                    <select name="niveau_etude" class="w-full px-4 py-2 rounded-lg border border-gray-300 focus:border-primary focus:ring-2 focus:ring-primary/20 outline-none transition-all bg-white">
                                        <option value="">Sélectionner...</option>
                                        <option value="Licence 1" <?= $user['niveau_etude'] === 'Licence 1' ? 'selected' : '' ?>>Licence 1</option>
                                        <option value="Licence 2" <?= $user['niveau_etude'] === 'Licence 2' ? 'selected' : '' ?>>Licence 2</option>
                                        <option value="Licence 3" <?= $user['niveau_etude'] === 'Licence 3' ? 'selected' : '' ?>>Licence 3</option>
                                        <option value="Master 1" <?= $user['niveau_etude'] === 'Master 1' ? 'selected' : '' ?>>Master 1</option>
                                        <option value="Master 2" <?= $user['niveau_etude'] === 'Master 2' ? 'selected' : '' ?>>Master 2</option>
                                        <option value="Doctorat" <?= $user['niveau_etude'] === 'Doctorat' ? 'selected' : '' ?>>Doctorat</option>
                                    </select>
                                </div>

                                <div class="grid md:grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-2">Profession</label>
                                        <input type="text" name="profession" value="<?= htmlspecialchars($user['profession'] ?? '') ?>"
                                            class="w-full px-4 py-2 rounded-lg border border-gray-300 focus:border-primary focus:ring-2 focus:ring-primary/20 outline-none transition-all">
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-2">Nationalité</label>
                                        <input type="text" name="nationalite" value="<?= htmlspecialchars($user['nationalite'] ?? '') ?>"
                                            class="w-full px-4 py-2 rounded-lg border border-gray-300 focus:border-primary focus:ring-2 focus:ring-primary/20 outline-none transition-all">
                                    </div>
                                </div>

                                <div class="grid md:grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-2">Téléphone</label>
                                        <input type="tel" name="telephone" value="<?= htmlspecialchars($user['telephone'] ?? '') ?>"
                                            class="w-full px-4 py-2 rounded-lg border border-gray-300 focus:border-primary focus:ring-2 focus:ring-primary/20 outline-none transition-all">
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-2">Date de naissance</label>
                                        <input type="date" name="datedenaissance" value="<?= $user['datedenaissance'] ?>"
                                            class="w-full px-4 py-2 rounded-lg border border-gray-300 focus:border-primary focus:ring-2 focus:ring-primary/20 outline-none transition-all">
                                    </div>
                                </div>

                                <div class="pt-4">
                                    <button type="submit" class="bg-primary hover:bg-orange-600 text-white px-6 py-2 rounded-lg font-medium transition-colors flex items-center gap-2">
                                        <i class="fas fa-save"></i>Enregistrer les modifications
                                    </button>
                                </div>
                            </form>
                        </div>
                    <?php endif; ?>

                    <!-- TAB: Avatar -->
                    <?php if ($activeTab === 'avatar'): ?>
                        <div>
                            <h3 class="text-2xl font-bold text-gray-800 mb-6">Photo de profil</h3>
                            
                            <div class="flex flex-col items-center mb-8">
                                <div class="relative mb-6">
                                    <div class="w-40 h-40 rounded-full overflow-hidden border-4 border-gray-200 shadow-lg">
                                        <img src="<?= AVATAR_URL . htmlspecialchars($user['avatar']) ?>" id="avatarPreview" class="w-full h-full object-cover">
                                    </div>
                                    <div class="absolute bottom-0 right-0 w-10 h-10 bg-primary rounded-full flex items-center justify-center text-white shadow-lg">
                                        <i class="fas fa-camera"></i>
                                    </div>
                                </div>
                                
                                <form method="POST" action="" enctype="multipart/form-data" class="text-center">
                                    <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                                    <input type="hidden" name="form_action" value="update_avatar">
                                    
                                    <div class="mb-4">
                                        <input type="file" name="avatar" id="avatarInput" accept="image/*" required
                                            class="block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-primary file:text-white hover:file:bg-orange-600 file:cursor-pointer file:transition-colors"
                                            onchange="previewAvatar(this)">
                                    </div>
                                    
                                    <p class="text-sm text-gray-500 mb-4">Formats acceptés: JPG, PNG, GIF, WebP. Max 2MB.</p>
                                    
                                    <button type="submit" class="bg-primary hover:bg-orange-600 text-white px-6 py-2 rounded-lg font-medium transition-colors">
                                        Mettre à jour la photo
                                    </button>
                                    <button type="submit" name="form_action" value="reset_avatar" 
                                            class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg transition-colors">
                                        <i class="fas fa-undo mr-2"></i>Réinitialiser l'avatar
                                    </button>
                                </form>
                            </div>
                            
                            <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
                                <h4 class="font-semibold text-blue-800 mb-2 flex items-center gap-2">
                                    <i class="fas fa-info-circle"></i>Conseils
                                </h4>
                                <ul class="text-sm text-blue-700 space-y-1 list-disc list-inside">
                                    <li>Utilisez une photo où votre visage est clairement visible</li>
                                    <li>Évitez les images contenant du texte ou des logos</li>
                                    <li>La photo doit respecter les conditions d'utilisation</li>
                                </ul>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- TAB: Security -->
                    <?php if ($activeTab === 'security'): ?>
                        <div>
                            <h3 class="text-2xl font-bold text-gray-800 mb-6">Sécurité du compte</h3>
                            
                            <div class="space-y-8 max-w-2xl">
                                <!-- Change Password -->
                                <div class="bg-gray-50 rounded-xl p-6">
                                    <h4 class="font-semibold text-lg mb-4 flex items-center gap-2">
                                        <i class="fas fa-key text-primary"></i>Changer le mot de passe
                                    </h4>
                                    
                                    <form method="POST" action="" class="space-y-4">
                                        <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                                        <input type="hidden" name="form_action" value="update_password">

                                        <div>
                                            <label class="block text-sm font-medium text-gray-700 mb-2">Mot de passe actuel</label>
                                            <div class="relative">
                                                <input type="password" name="current_password" required
                                                    class="w-full px-4 py-2 rounded-lg border border-gray-300 focus:border-primary focus:ring-2 focus:ring-primary/20 outline-none transition-all">
                                                <button type="button" onclick="togglePassword(this)" class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                            </div>
                                        </div>

                                        <div>
                                            <label class="block text-sm font-medium text-gray-700 mb-2">Nouveau mot de passe</label>
                                            <div class="relative">
                                                <input type="password" name="new_password" id="newPassword" required oninput="checkPasswordStrength(this.value)"
                                                    class="w-full px-4 py-2 rounded-lg border border-gray-300 focus:border-primary focus:ring-2 focus:ring-primary/20 outline-none transition-all">
                                                <button type="button" onclick="togglePassword(this)" class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                            </div>
                                            <div class="mt-2 h-1 bg-gray-200 rounded-full overflow-hidden">
                                                <div id="passwordStrength" class="password-strength w-0 bg-red-500"></div>
                                            </div>
                                            <p id="passwordFeedback" class="text-xs text-gray-500 mt-1">Minimum 8 caractères, 1 majuscule, 1 chiffre, 1 symbole</p>
                                        </div>

                                        <div>
                                            <label class="block text-sm font-medium text-gray-700 mb-2">Confirmer le nouveau mot de passe</label>
                                            <input type="password" name="confirm_password" required
                                                class="w-full px-4 py-2 rounded-lg border border-gray-300 focus:border-primary focus:ring-2 focus:ring-primary/20 outline-none transition-all">
                                        </div>

                                        <button type="submit" class="bg-primary hover:bg-orange-600 text-white px-6 py-2 rounded-lg font-medium transition-colors">
                                            Mettre à jour le mot de passe
                                        </button>
                                    </form>
                                </div>

                                <!-- Active Sessions -->
                                <div class="bg-gray-50 rounded-xl p-6">
                                    <h4 class="font-semibold text-lg mb-4 flex items-center gap-2">
                                        <i class="fas fa-desktop text-primary"></i>Sessions actives
                                    </h4>
                                    <div class="flex items-center justify-between p-4 bg-white rounded-lg border">
                                        <div class="flex items-center gap-3">
                                            <i class="fas fa-laptop text-gray-400 text-xl"></i>
                                            <div>
                                                <p class="font-medium">Cet appareil</p>
                                                <p class="text-sm text-gray-500"><?= htmlspecialchars($_SERVER['HTTP_USER_AGENT']) ?></p>
                                            </div>
                                        </div>
                                        <span class="px-3 py-1 bg-green-100 text-green-700 text-xs rounded-full font-medium">Actif</span>
                                    </div>
                                </div>

                                <!-- 2FA Placeholder -->
                                <div class="bg-gray-50 rounded-xl p-6">
                                    <h4 class="font-semibold text-lg mb-4 flex items-center gap-2">
                                        <i class="fas fa-shield-alt text-primary"></i>Authentification à deux facteurs
                                    </h4>
                                    <p class="text-gray-600 mb-4">Ajoutez une couche de sécurité supplémentaire à votre compte.</p>
                                    <button disabled class="bg-gray-300 text-gray-500 px-6 py-2 rounded-lg font-medium cursor-not-allowed">
                                        Configurer (bientôt disponible)
                                    </button>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- TAB: Privacy -->
                    <?php if ($activeTab === 'privacy'): ?>
                        <div>
                            <h3 class="text-2xl font-bold text-gray-800 mb-6">Confidentialité</h3>
                            
                            <form method="POST" action="" class="space-y-6 max-w-2xl">
                                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                                <input type="hidden" name="form_action" value="update_privacy">

                                <div class="space-y-4">
                                    <div class="flex items-center justify-between p-4 bg-gray-50 rounded-lg">
                                        <div>
                                            <h4 class="font-medium text-gray-800">Compte actif</h4>
                                            <p class="text-sm text-gray-500">Votre profil est visible par les autres utilisateurs</p>
                                        </div>
                                        <label class="relative inline-flex items-center cursor-pointer">
                                            <input type="checkbox" name="is_active" value="1" <?= $user['is_active'] ? 'checked' : '' ?> class="sr-only peer">
                                            <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-primary"></div>
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
                                    <button type="submit" class="bg-primary hover:bg-orange-600 text-white px-6 py-2 rounded-lg font-medium transition-colors">
                                        Enregistrer les préférences
                                    </button>
                                </div>
                            </form>
                        </div>
                    <?php endif; ?>

                    <!-- TAB: Notifications Settings -->
                    <?php if ($activeTab === 'notifications'): ?>
                        <div>
                            <h3 class="text-2xl font-bold text-gray-800 mb-6">Préférences de notification</h3>
                            
                            <div class="space-y-4 max-w-2xl">
                                <div class="flex items-center justify-between p-4 bg-gray-50 rounded-lg">
                                    <div>
                                        <h4 class="font-medium text-gray-800">Notifications par email</h4>
                                        <p class="text-sm text-gray-500">Recevoir les notifications importantes par email</p>
                                    </div>
                                    <label class="relative inline-flex items-center cursor-pointer">
                                        <input type="checkbox" checked class="sr-only peer">
                                        <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-primary"></div>
                                    </label>
                                </div>

                                <div class="flex items-center justify-between p-4 bg-gray-50 rounded-lg">
                                    <div>
                                        <h4 class="font-medium text-gray-800">J'aime sur mes publications</h4>
                                        <p class="text-sm text-gray-500">Notifier quand quelqu'un aime votre contenu</p>
                                    </div>
                                    <label class="relative inline-flex items-center cursor-pointer">
                                        <input type="checkbox" checked class="sr-only peer">
                                        <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-primary"></div>
                                    </label>
                                </div>

                                <div class="flex items-center justify-between p-4 bg-gray-50 rounded-lg">
                                    <div>
                                        <h4 class="font-medium text-gray-800">Commentaires</h4>
                                        <p class="text-sm text-gray-500">Notifier les nouveaux commentaires</p>
                                    </div>
                                    <label class="relative inline-flex items-center cursor-pointer">
                                        <input type="checkbox" checked class="sr-only peer">
                                        <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-primary"></div>
                                    </label>
                                </div>

                                <div class="flex items-center justify-between p-4 bg-gray-50 rounded-lg">
                                    <div>
                                        <h4 class="font-medium text-gray-800">Demandes d'ami</h4>
                                        <p class="text-sm text-gray-500">Notifier les nouvelles demandes d'ami</p>
                                    </div>
                                    <label class="relative inline-flex items-center cursor-pointer">
                                        <input type="checkbox" checked class="sr-only peer">
                                        <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-primary"></div>
                                    </label>
                                </div>

                                <div class="flex items-center justify-between p-4 bg-gray-50 rounded-lg opacity-50">
                                    <div>
                                        <h4 class="font-medium text-gray-800">Messages</h4>
                                        <p class="text-sm text-gray-500">Notifier les nouveaux messages privés</p>
                                    </div>
                                    <label class="relative inline-flex items-center cursor-pointer">
                                        <input type="checkbox" checked disabled class="sr-only peer">
                                        <div class="w-11 h-6 bg-gray-200 rounded-full peer after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5"></div>
                                    </label>
                                </div>
                                <p class="text-xs text-orange-600 flex items-center gap-1">
                                    <i class="fas fa-tools"></i>La messagerie est en développement
                                </p>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- TAB: Delete Account -->
                    <?php if ($activeTab === 'delete'): ?>
                        <div>
                            <h3 class="text-2xl font-bold text-red-600 mb-6 flex items-center gap-2">
                                <i class="fas fa-exclamation-triangle"></i>Supprimer le compte
                            </h3>
                            
                            <div class="bg-red-50 border border-red-200 rounded-xl p-6 mb-6">
                                <h4 class="font-bold text-red-800 mb-2">Attention ! Cette action est irréversible</h4>
                                <p class="text-red-700 text-sm mb-4">La suppression de votre compte entraînera :</p>
                                <ul class="text-red-700 text-sm space-y-2 list-disc list-inside">
                                    <li>La désactivation immédiate de votre profil</li>
                                    <li>La suppression de toutes vos publications</li>
                                    <li>La perte de tous vos contacts et messages</li>
                                    <li>L'impossibilité de récupérer vos données</li>
                                </ul>
                            </div>

                            <form method="POST" action="" class="max-w-2xl space-y-4" onsubmit="return confirmDelete()">
                                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                                <input type="hidden" name="form_action" value="delete_account">

                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Pour confirmer, tapez "SUPPRIMER"</label>
                                    <input type="text" name="confirm_delete" required pattern="SUPPRIMER"
                                        class="w-full px-4 py-2 rounded-lg border border-red-300 focus:border-red-500 focus:ring-2 focus:ring-red-200 outline-none transition-all font-mono uppercase">
                                </div>

                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Entrez votre mot de passe</label>
                                    <input type="password" name="delete_password" required
                                        class="w-full px-4 py-2 rounded-lg border border-gray-300 focus:border-red-500 focus:ring-2 focus:ring-red-200 outline-none transition-all">
                                </div>

                                <div class="pt-4">
                                    <button type="submit" class="bg-red-600 hover:bg-red-700 text-white px-6 py-2 rounded-lg font-medium transition-colors flex items-center gap-2">
                                        <i class="fas fa-trash-alt"></i>Supprimer définitivement mon compte
                                    </button>
                                </div>
                            </form>
                        </div>
                    <?php endif; ?>

                </div>
            </div>
        </div>
    </div>

    <script>
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
            
            strengthBar.className = `password-strength ${colors[strength] || 'bg-red-500'}`;
            strengthBar.style.width = widths[strength] || '0%';
            feedback.textContent = texts[strength] || 'Minimum 8 caractères, 1 majuscule, 1 chiffre, 1 symbole';
            feedback.className = `text-xs mt-1 ${strength >= 3 ? 'text-green-600' : 'text-red-500'}`;
        }

        function previewAvatar(input) {
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    document.getElementById('avatarPreview').src = e.target.result;
                };
                reader.readAsDataURL(input.files[0]);
            }
        }

        function confirmDelete() {
            return confirm('Êtes-vous absolument sûr de vouloir supprimer votre compte ? Cette action est définitive.');
        }
    </script>
</body>
</html>