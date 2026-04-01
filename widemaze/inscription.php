<?php 
require_once 'config.php';

$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['inscrire'])) {
    // Vérification CSRF
    if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
        $errors[] = "Erreur de sécurité, veuillez réessayer";
    } else {
        // Validation des données
        $prenom = trim($_POST['prenom'] ?? '');
        $nom = trim($_POST['nom'] ?? '');
        $email = filter_var(trim($_POST['email'] ?? ''), FILTER_SANITIZE_EMAIL);
        $motdepasse = $_POST['motdepasse'] ?? '';
        $motdepasse2 = $_POST['motdepasse2'] ?? '';
        $surnom = trim($_POST['surnom'] ?? '');
        $datedenaissance = $_POST['datedenaissance'] ?? null;
        $sexe = $_POST['sexe'] ?? 'Masculin';
        $profession = trim($_POST['profession'] ?? '');
        $nationalite = trim($_POST['nationalite'] ?? '');
        $telephone = trim($_POST['telephone'] ?? '');
        $bio = trim($_POST['bio'] ?? '');
        $universite = trim($_POST['universite'] ?? '');
        $faculte = trim($_POST['faculte'] ?? '');
        $niveau_etude = trim($_POST['niveau_etude'] ?? '');

        // Validation des champs obligatoires
        if (empty($prenom) || empty($nom) || empty($email) || empty($motdepasse) || empty($surnom)) {
            $errors[] = "Tous les champs obligatoires doivent être remplis";
        }

        // Validation longueur
        if (strlen($prenom) > 20) $errors[] = "Le prénom ne doit pas dépasser 20 caractères";
        if (strlen($nom) > 20) $errors[] = "Le nom ne doit pas dépasser 20 caractères";
        if (strlen($surnom) > 30) $errors[] = "Le surnom ne doit pas dépasser 30 caractères";
        if (strlen($email) > 100) $errors[] = "L'email est trop long";

        // Validation email
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Format d'email invalide";
        }

        // Validation mot de passe
        if ($motdepasse !== $motdepasse2) {
            $errors[] = "Les mots de passe ne correspondent pas";
        }

        $pwdErrors = validate_password_strength($motdepasse);
        if (!empty($pwdErrors)) {
            $errors = array_merge($errors, $pwdErrors);
        }

        // Validation date de naissance (13-100 ans)
        if ($datedenaissance) {
            $birthDate = new DateTime($datedenaissance);
            $today = new DateTime();
            $age = $today->diff($birthDate)->y;
            if ($age < 13) $errors[] = "Vous devez avoir au moins 13 ans";
            if ($age > 100) $errors[] = "Date de naissance invalide";
        } else {
            $errors[] = "La date de naissance est requise";
        }

        // Validation surnom (alphanumérique + underscore)
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $surnom)) {
            $errors[] = "Le surnom ne doit contenir que des lettres, chiffres et underscores";
        }

        // Validation téléphone si fourni
        if (!empty($telephone) && !preg_match('/^[\d\s\+\-\(\)]+$/', $telephone)) {
            $errors[] = "Format de téléphone invalide";
        }

        if (empty($errors)) {
            try {
                // Vérification email existant
                if (email_exists($pdo, $email)) {
                    $errors[] = "Cet email est déjà utilisé";
                } else {
                    // Vérification surnom unique
                    $checkSurnom = $pdo->prepare("SELECT id FROM utilisateurs WHERE surnom = ?");
                    $checkSurnom->execute([$surnom]);
                    if ($checkSurnom->fetch()) {
                        $errors[] = "Ce surnom est déjà pris";
                    } else {
        // Hashage sécurisé du mot de passe
                        $hash = ($motdepasse);
                        
                        // Gestion avatar avec validation stricte
                        $avatar = 'default-avatar';
                        if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
                            // Vérifier que le dossier existe
                            if (!is_dir(AVATAR_DIR)) {
                                mkdir(AVATAR_DIR, 0755, true);
                            }
                            
                            $uploadResult = handle_file_upload(
                                $_FILES['avatar'], 
                                AVATAR_DIR, 
                                ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
                                2 * 1024 * 1024 // 2MB max pour avatars
                            );
                            
                            if ($uploadResult['success']) {
                                $avatar = $uploadResult['filename'];
                            } else {
                                $errors[] = "Avatar: " . $uploadResult['error'];
                            }
                        }
                        // Insertion (l'avatar sera 'default-avatar.png' si pas d'upload)
                        $stmt = $pdo->prepare("INSERT INTO utilisateurs 
                        (prenom, nom, email, motdepasse, surnom, profession, nationalite, 
                        telephone, datedenaissance, sexe, avatar, bio, universite, 
                        faculte, niveau_etude, dateinscription, status, is_active) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 'Offline', TRUE)");
                        
                        if (empty($errors)) {
                            // Insertion avec toutes les colonnes de la DB
                            $stmt = $pdo->prepare("INSERT INTO utilisateurs 
                                (prenom, nom, email, motdepasse, surnom, profession, nationalite, 
                                 telephone, datedenaissance, sexe, avatar, bio, universite, 
                                 faculte, niveau_etude, dateinscription, status, is_active) 
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 'Offline', TRUE)");
                            
                            $stmt->execute([
                                $prenom, $nom, $email, $hash, $surnom, $profession, $nationalite,
                                $telephone, $datedenaissance, $sexe, $avatar, $bio, $universite,
                                $faculte, $niveau_etude
                            ]);
                            
                            $newUserId = $pdo->lastInsertId();
                            
                            // Log de l'inscription
                            log_activity($pdo, $newUserId, 'register', ['ip' => $_SERVER['REMOTE_ADDR']]);
                            
                            // ========== REDIRECTION AVEC SUCCÈS ==========
                            header("Location: connexion.php?success=registered");
                            exit();
                        }
                    }
                }
            } catch (PDOException $e) {
                error_log("Erreur inscription: " . $e->getMessage());
                $errors[] = "Erreur lors de l'inscription, veuillez réessayer";
            }
        }
    }
}

// Génération du token CSRF
$csrfToken = generate_csrf_token();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WideMaze - Inscription</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!--<link rel="stylesheet" href="main2.css">-->
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: '#f59e0b',
                        secondary: '#1e293b',
                        success: '#10b981',
                        danger: '#ef4444',
                    }
                }
            }
        }
    </script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');
        body { font-family: 'Inter', sans-serif; }
        .glass {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
        }
        .step { display: none; animation: fadeIn 0.3s ease; }
        .step.active { display: block; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        .progress-bar { transition: width 0.4s ease; }
        .input-error { border-color: #ef4444 !important; }
        .input-success { border-color: #10b981 !important; }
        .validation-icon { position: absolute; right: 40px; top: 50%; transform: translateY(-50%); }
    </style>
</head>
<body class="bg-gradient-to-br from-gray-100 via-orange-50 to-gray-200 min-h-screen p-4">

    <div class="container mx-auto max-w-3xl py-8">
        <!-- Header -->
        <div class="text-center mb-8">
            <div class="inline-flex items-center gap-3 mb-4 animate-bounce">
                <div class="w-14 h-14 bg-gradient-to-br from-primary to-orange-600 rounded-2xl flex items-center justify-center shadow-xl">
                    <i class="fas fa-network-wired text-white text-2xl"></i>
                </div>
                <h1 class="text-4xl font-bold text-secondary">WideMaze</h1>
            </div>
            <p class="text-gray-600 text-lg">Rejoignez notre communauté dès maintenant</p>
        </div>

        <!-- Progress -->
        <div class="mb-8 bg-white rounded-2xl p-4 shadow-lg">
            <div class="flex justify-between text-sm font-medium text-gray-600 mb-2">
                <span>Étape <span id="currentStepNum" class="text-primary font-bold text-lg">1</span> sur 4</span>
                <span id="progressPercent" class="text-primary font-bold">25%</span>
            </div>
            <div class="w-full bg-gray-200 rounded-full h-3 overflow-hidden">
                <div id="progressBar" class="progress-bar bg-gradient-to-r from-primary to-orange-600 h-full rounded-full shadow-lg" style="width: 25%"></div>
            </div>
            <div class="flex justify-between mt-2 text-xs text-gray-400">
                <span>Identité</span>
                <span>Sécurité</span>
                <span>Profil</span>
                <span>Finalisation</span>
            </div>
        </div>

        <!-- Form -->
        <div class="glass rounded-3xl shadow-2xl p-8 border border-white/50">
            <h2 class="text-3xl font-bold text-center mb-2 text-secondary">Créer un compte</h2>
            <p class="text-center text-gray-500 mb-8">Commencez votre aventure sur WideMaze</p>

            <!-- Errors -->
            <?php if (!empty($errors)): ?>
                <div class="bg-red-50 border-l-4 border-red-500 p-4 rounded-r-xl mb-6 animate-pulse">
                    <div class="flex items-center gap-2 mb-2">
                        <i class="fas fa-exclamation-triangle text-red-500"></i>
                        <span class="font-semibold text-red-700">Veuillez corriger les erreurs suivantes :</span>
                    </div>
                    <ul class="list-disc list-inside text-red-600 text-sm space-y-1">
                        <?php foreach ($errors as $error): ?>
                            <li><?= htmlspecialchars($error) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <form id="registerForm" method="POST" action="" enctype="multipart/form-data" class="space-y-6" novalidate>
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                
                <!-- Step 1: Identity -->
                <div class="step active" data-step="1">
                    <div class="flex items-center gap-3 mb-6 text-primary">
                        <div class="w-10 h-10 rounded-full bg-orange-100 flex items-center justify-center">
                            <i class="fas fa-user text-lg"></i>
                        </div>
                        <h3 class="text-xl font-bold text-gray-800">Qui êtes-vous ?</h3>
                    </div>
                    
                    <div class="grid md:grid-cols-2 gap-4">
                        <div class="relative">
                            <label class="block text-sm font-semibold text-gray-700 mb-2">Prénom *</label>
                            <div class="relative">
                                <i class="fas fa-user absolute left-4 top-1/2 -translate-y-1/2 text-gray-400"></i>
                                <input type="text" name="prenom" id="prenom" required 
                                    class="w-full pl-12 pr-4 py-3 rounded-xl border-2 border-gray-200 focus:border-primary focus:ring-4 focus:ring-orange-100 outline-none transition-all"
                                    value="<?= old('prenom') ?>" placeholder="David">
                            </div>
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2">Nom *</label>
                            <div class="relative">
                                <i class="fas fa-user absolute left-4 top-1/2 -translate-y-1/2 text-gray-400"></i>
                                <input type="text" name="nom" id="nom" required 
                                    class="w-full pl-12 pr-4 py-3 rounded-xl border-2 border-gray-200 focus:border-primary focus:ring-4 focus:ring-orange-100 outline-none transition-all"
                                    value="<?= old('nom') ?>" placeholder="Ngwangwa">
                            </div>
                        </div>
                    </div>

                    <div class="mt-4">
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Pseudonyme *</label>
                        <div class="relative">
                            <i class="fas fa-at absolute left-4 top-1/2 -translate-y-1/2 text-gray-400"></i>
                            <input type="text" name="surnom" id="surnom" required 
                                class="w-full pl-12 pr-12 py-3 rounded-xl border-2 border-gray-200 focus:border-primary focus:ring-4 focus:ring-orange-100 outline-none transition-all"
                                value="<?= old('surnom') ?>" placeholder="wings_dbn">
                            <span id="surnomStatus" class="validation-icon"></span>
                        </div>
                        <p class="text-xs text-gray-500 mt-1">Lettres, chiffres et underscores uniquement</p>
                    </div>

                    <div class="grid md:grid-cols-2 gap-4 mt-4">
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2">Date de naissance *</label>
                            <input type="date" name="datedenaissance" id="datedenaissance" required
                                min="1920-01-01" max="2011-12-31"
                                class="w-full px-4 py-3 rounded-xl border-2 border-gray-200 focus:border-primary focus:ring-4 focus:ring-orange-100 outline-none transition-all"
                                value="<?= old('datedenaissance') ?>">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2">Genre</label>
                            <select name="sexe" class="w-full px-4 py-3 rounded-xl border-2 border-gray-200 focus:border-primary focus:ring-4 focus:ring-orange-100 outline-none transition-all bg-white">
                                <option value="Masculin" <?= old('sexe') === 'Masculin' ? 'selected' : '' ?>>Masculin</option>
                                <option value="Feminin" <?= old('sexe') === 'Feminin' ? 'selected' : '' ?>>Féminin</option>
                                
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Step 2: Account Security -->
                <div class="step" data-step="2">
                    <div class="flex items-center gap-3 mb-6 text-primary">
                        <div class="w-10 h-10 rounded-full bg-orange-100 flex items-center justify-center">
                            <i class="fas fa-shield-alt text-lg"></i>
                        </div>
                        <h3 class="text-xl font-bold text-gray-800">Sécurisez votre compte</h3>
                    </div>

                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2">Email *</label>
                            <div class="relative">
                                <i class="fas fa-envelope absolute left-4 top-1/2 -translate-y-1/2 text-gray-400"></i>
                                <input type="email" name="email" id="email" required 
                                    class="w-full pl-12 pr-12 py-3 rounded-xl border-2 border-gray-200 focus:border-primary focus:ring-4 focus:ring-orange-100 outline-none transition-all"
                                    value="<?= old('email') ?>" placeholder="david@exemple.com">
                                <span id="emailStatus" class="validation-icon"></span>
                            </div>
                        </div>

                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2">Mot de passe *</label>
                            <div class="relative">
                                <i class="fas fa-lock absolute left-4 top-1/2 -translate-y-1/2 text-gray-400"></i>
                                <input type="password" name="motdepasse" id="motdepasse" required 
                                    class="w-full pl-12 pr-12 py-3 rounded-xl border-2 border-gray-200 focus:border-primary focus:ring-4 focus:ring-orange-100 outline-none transition-all"
                                    placeholder="••••••••">
                                <button type="button" onclick="togglePwd('motdepasse', 'eye1')" class="absolute right-4 top-1/2 -translate-y-1/2 text-gray-400 hover:text-primary transition-colors">
                                    <i class="fas fa-eye" id="eye1"></i>
                                </button>
                            </div>
                            <!-- Password strength indicator -->
                            <div class="mt-2 space-y-2">
                                <div class="flex gap-1 h-1">
                                    <div id="pwd-strength-1" class="flex-1 bg-gray-200 rounded-full transition-colors"></div>
                                    <div id="pwd-strength-2" class="flex-1 bg-gray-200 rounded-full transition-colors"></div>
                                    <div id="pwd-strength-3" class="flex-1 bg-gray-200 rounded-full transition-colors"></div>
                                    <div id="pwd-strength-4" class="flex-1 bg-gray-200 rounded-full transition-colors"></div>
                                </div>
                                <ul class="text-xs text-gray-500 space-y-1">
                                    <li id="req-length"><i class="fas fa-circle text-[8px] mr-1"></i> 8 caractères minimum</li>
                                    <li id="req-upper"><i class="fas fa-circle text-[8px] mr-1"></i> Une majuscule</li>
                                    <li id="req-lower"><i class="fas fa-circle text-[8px] mr-1"></i> Une minuscule</li>
                                    <li id="req-number"><i class="fas fa-circle text-[8px] mr-1"></i> Un chiffre</li>
                                    <li id="req-special"><i class="fas fa-circle text-[8px] mr-1"></i> Un caractère spécial</li>
                                </ul>
                            </div>
                        </div>

                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2">Confirmer le mot de passe *</label>
                            <div class="relative">
                                <i class="fas fa-lock absolute left-4 top-1/2 -translate-y-1/2 text-gray-400"></i>
                                <input type="password" name="motdepasse2" id="motdepasse2" required 
                                    class="w-full pl-12 pr-12 py-3 rounded-xl border-2 border-gray-200 focus:border-primary focus:ring-4 focus:ring-orange-100 outline-none transition-all"
                                    placeholder="••••••••">
                                <span id="matchStatus" class="validation-icon right-4"></span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Step 3: Profile Details -->
                <div class="step" data-step="3">
                    <div class="flex items-center gap-3 mb-6 text-primary">
                        <div class="w-10 h-10 rounded-full bg-orange-100 flex items-center justify-center">
                            <i class="fas fa-graduation-cap text-lg"></i>
                        </div>
                        <h3 class="text-xl font-bold text-gray-800">Votre profil académique</h3>
                    </div>

                    <div class="grid md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2">Profession/Rôle</label>
                            <select name="profession" class="w-full px-4 py-3 rounded-xl border-2 border-gray-200 focus:border-primary focus:ring-4 focus:ring-orange-100 outline-none transition-all bg-white">
                                <option value="etudiant" <?= old('profession') === 'etudiant' ? 'selected' : '' ?>>Étudiant</option>
                                <option value="enseignant" <?= old('profession') === 'enseignant' ? 'selected' : '' ?>>Enseignant</option>
                                <option value="professionnel" <?= old('profession') === 'professionnel' ? 'selected' : '' ?>>Professionnel</option>
                                <option value="autre" <?= old('profession') === 'autre' ? 'selected' : '' ?>>Autre</option>
                            </select>
                        </div>
                        <!-- Pays avec recherche moderne -->
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2">Pays *</label>
                            <div class="country-search-wrapper">
                                <div class="relative">
                                    <i class="fas fa-globe absolute left-4 top-1/2 -translate-y-1/2 text-gray-400 z-10"></i>
                                    <input type="text" 
                                        id="countrySearchInput"
                                        autocomplete="off"
                                        class="w-full pl-12 pr-10 py-3 rounded-xl border-2 border-gray-200 focus:border-primary focus:ring-4 focus:ring-orange-100 outline-none transition-all"
                                        placeholder="Rechercher votre pays...">
                                    <i id="countrySearchIcon" class="fas fa-chevron-down absolute right-4 top-1/2 -translate-y-1/2 text-gray-400 text-sm pointer-events-none transition-transform"></i>
                                </div>
                                <div id="countrySuggestions" class="country-suggestions hidden"></div>
                                <input type="hidden" name="nationalite" id="nationalite" value="<?= old('nationalite') ?>">
                            </div>
                            <p class="text-xs text-gray-500 mt-1">Recherchez par nom, code ou emoji</p>
                        </div>
                    </div>

                    <div class="mt-4">
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Université/Établissement</label>
                        <div class="relative">
                            <i class="fas fa-university absolute left-4 top-1/2 -translate-y-1/2 text-gray-400"></i>
                            <input type="text" name="universite" 
                                class="w-full pl-12 pr-4 py-3 rounded-xl border-2 border-gray-200 focus:border-primary focus:ring-4 focus:ring-orange-100 outline-none transition-all"
                                value="<?= old('universite') ?>" placeholder="Université de ...">
                        </div>
                    </div>

                    <div class="grid md:grid-cols-2 gap-4 mt-4">
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2">Faculté</label>
                            <input type="text" name="faculte" 
                                class="w-full px-4 py-3 rounded-xl border-2 border-gray-200 focus:border-primary focus:ring-4 focus:ring-orange-100 outline-none transition-all"
                                value="<?= old('faculte') ?>" placeholder="Sciences, Droit...">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2">Niveau d'études</label>
                            <input type="text" name="niveau_etude" 
                                class="w-full px-4 py-3 rounded-xl border-2 border-gray-200 focus:border-primary focus:ring-4 focus:ring-orange-100 outline-none transition-all"
                                value="<?= old('niveau_etude') ?>" placeholder="Licence 3, Master 1...">
                        </div>
                    </div>

                    <div class="mt-4">
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Téléphone</label>
                        <div class="relative">
                            <i class="fas fa-phone absolute left-4 top-1/2 -translate-y-1/2 text-gray-400"></i>
                            <input type="tel" name="telephone" 
                                class="w-full pl-12 pr-4 py-3 rounded-xl border-2 border-gray-200 focus:border-primary focus:ring-4 focus:ring-orange-100 outline-none transition-all"
                                value="<?= old('telephone') ?>" placeholder="+243 823 851 403">
                        </div>
                    </div>

                    <div class="mt-4">
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Bio (présentation courte)</label>
                        <textarea name="bio" rows="3" maxlength="500"
                            class="w-full px-4 py-3 rounded-xl border-2 border-gray-200 focus:border-primary focus:ring-4 focus:ring-orange-100 outline-none transition-all resize-none"
                            placeholder="Parlez-nous un peu de vous..."><?= old('bio') ?></textarea>
                        <p class="text-xs text-gray-500 mt-1 text-right"><span id="bioCount">0</span>/500</p>
                    </div>
                </div>

                <!-- Step 4: Avatar & Finalization -->
                <div class="step" data-step="4">
                    <div class="flex items-center gap-3 mb-6 text-primary">
                        <div class="w-10 h-10 rounded-full bg-orange-100 flex items-center justify-center">
                            <i class="fas fa-camera text-lg"></i>
                        </div>
                        <h3 class="text-xl font-bold text-gray-800">Photo de profil</h3>
                    </div>

                    <div class="text-center">
                        <div class="relative inline-block">
                            <div id="avatarPreview" class="w-32 h-32 rounded-full bg-gray-200 flex items-center justify-center overflow-hidden border-4 border-white shadow-lg mx-auto mb-4">
                                <i class="fas fa-user text-6xl text-gray-400"></i>
                            </div>
                            <label for="avatar" class="absolute bottom-0 right-0 w-10 h-10 bg-primary hover:bg-orange-600 rounded-full flex items-center justify-center cursor-pointer shadow-lg transition-colors">
                                <i class="fas fa-camera text-white"></i>
                            </label>
                        </div>
                        <input type="file" name="avatar" id="avatar" accept="image/*" class="hidden" onchange="previewAvatar(this)">
                        <p class="text-sm text-gray-600 mb-2">Ajoutez une photo de profil (optionnel)</p>
                        <p class="text-xs text-gray-400">JPG, PNG ou GIF • Max 2MB</p>
                    </div>

                    <div class="mt-8 p-4 bg-orange-50 rounded-xl border border-orange-200">
                        <h4 class="font-semibold text-orange-800 mb-2"><i class="fas fa-info-circle mr-2"></i>Récapitulatif</h4>
                        <ul class="text-sm text-orange-700 space-y-1" id="recapList">
                            <!-- Rempli par JS -->
                        </ul>
                    </div>

                    <div class="mt-4 flex items-start gap-3">
                        <input type="checkbox" id="terms" required class="mt-1 w-4 h-4 text-primary rounded border-gray-300 focus:ring-primary">
                        <label for="terms" class="text-sm text-gray-600">
                            J'accepte les <a href="#" class="text-primary hover:underline">Conditions d'utilisation</a> et la 
                            <a href="#" class="text-primary hover:underline">Politique de confidentialité</a> de WideMaze *
                        </label>
                    </div>
                </div>

                <!-- Navigation Buttons -->
                <div class="flex justify-between pt-6 border-t border-gray-200">
                    <button type="button" id="prevBtn" onclick="changeStep(-1)" 
                        class="px-6 py-3 rounded-xl border-2 border-gray-300 text-gray-700 hover:bg-gray-50 hover:border-gray-400 transition-all font-medium hidden items-center gap-2">
                        <i class="fas fa-arrow-left"></i>Retour
                    </button>
                    
                    <div class="ml-auto flex gap-3">
                        <button type="button" id="nextBtn" onclick="changeStep(1)" 
                            class="px-8 py-3 bg-gradient-to-r from-primary to-orange-600 text-white rounded-xl hover:shadow-xl hover:scale-105 transition-all font-semibold flex items-center gap-2">
                            Suivant<i class="fas fa-arrow-right"></i>
                        </button>
                        
                        <button type="submit" name="inscrire" id="submitBtn" 
                            class="px-8 py-3 bg-gradient-to-r from-green-500 to-emerald-600 text-white rounded-xl hover:shadow-xl hover:scale-105 transition-all font-semibold hidden items-center gap-2">
                            <i class="fas fa-check"></i>Créer mon compte
                        </button>
                    </div>
                </div>
            </form>

            <div class="text-center mt-8 pt-6 border-t border-gray-200">
                <p class="text-gray-600">
                    Déjà membre ? 
                    <a href="connexion.php" class="text-primary hover:text-orange-600 font-semibold hover:underline transition-colors">
                        Connectez-vous
                    </a>
                </p>
            </div>
        </div>
    </div>

    <script>
        let currentStep = 1;
        const totalSteps = 4;

        function updateProgress() {
            const percent = (currentStep / totalSteps) * 100;
            document.getElementById('progressBar').style.width = percent + '%';
            document.getElementById('progressPercent').textContent = Math.round(percent) + '%';
            document.getElementById('currentStepNum').textContent = currentStep;
            
            document.getElementById('prevBtn').classList.toggle('hidden', currentStep === 1);
            document.getElementById('prevBtn').classList.toggle('flex', currentStep !== 1);
            document.getElementById('nextBtn').classList.toggle('hidden', currentStep === totalSteps);
            document.getElementById('submitBtn').classList.toggle('hidden', currentStep !== totalSteps);
            document.getElementById('submitBtn').classList.toggle('flex', currentStep === totalSteps);
        }

        function validateStep(step) {
            let valid = true;
            
            if (step === 1) {
                const prenom = document.getElementById('prenom').value.trim();
                const nom = document.getElementById('nom').value.trim();
                const surnom = document.getElementById('surnom').value.trim();
                const dob = document.getElementById('datedenaissance').value;
                
                if (!prenom || !nom || !surnom || !dob) {
                    alert('Veuillez remplir tous les champs obligatoires');
                    valid = false;
                } else if (!/^[a-zA-Z0-9_]+$/.test(surnom)) {
                    alert('Le surnom ne doit contenir que des lettres, chiffres et underscores');
                    valid = false;
                }
            }
            
            if (step === 2) {
                const email = document.getElementById('email').value.trim();
                const pwd1 = document.getElementById('motdepasse').value;
                const pwd2 = document.getElementById('motdepasse2').value;
                
                if (!email || !pwd1 || !pwd2) {
                    alert('Veuillez remplir tous les champs');
                    valid = false;
                } else if (pwd1 !== pwd2) {
                    alert('Les mots de passe ne correspondent pas');
                    valid = false;
                } else if (pwd1.length < 8) {
                    alert('Le mot de passe doit faire au moins 8 caractères');
                    valid = false;
                } else if (!/(?=.*[A-Z])(?=.*[a-z])(?=.*[0-9])(?=.*[!@#$%^&*(),.?":{}|<>])/.test(pwd1)) {
                    alert('Le mot de passe ne respecte pas les critères de sécurité');
                    valid = false;
                }
            }
            
            if (step === 3) {
                const country = document.getElementById('nationalite').value.trim();
            if (!country) {
                alert('Veuillez sélectionner votre pays');
                valid = false;
            }
                // Optionnel mais valider format téléphone si rempli
                const phone = document.querySelector('input[name="telephone"]').value;
                if (phone && !/^[\d\s\+\-\(\)]+$/.test(phone)) {
                    alert('Format de téléphone invalide');
                    valid = false;
                }
            }
            
            if (step === 4) {
                if (!document.getElementById('terms').checked) {
                    alert('Vous devez accepter les conditions d\'utilisation');
                    valid = false;
                }
            }
            
            return valid;
        }

        function changeStep(direction) {
            if (direction === 1 && !validateStep(currentStep)) return;
            
            document.querySelector(`.step[data-step="${currentStep}"]`).classList.remove('active');
            currentStep += direction;
            document.querySelector(`.step[data-step="${currentStep}"]`).classList.add('active');
            
            if (currentStep === 4) updateRecap();
            updateProgress();
        }

        function togglePwd(inputId, eyeId) {
            const input = document.getElementById(inputId);
            const eye = document.getElementById(eyeId);
            if (input.type === 'password') {
                input.type = 'text';
                eye.classList.remove('fa-eye');
                eye.classList.add('fa-eye-slash');
            } else {
                input.type = 'password';
                eye.classList.remove('fa-eye-slash');
                eye.classList.add('fa-eye');
            }
        }

        function previewAvatar(input) {
            const preview = document.getElementById('avatarPreview');
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    preview.innerHTML = `<img src="${e.target.result}" class="w-full h-full object-cover">`;
                }
                reader.readAsDataURL(input.files[0]);
            }
        }

        function updateRecap() {
            const recap = document.getElementById('recapList');
            const prenom = document.getElementById('prenom').value;
            const nom = document.getElementById('nom').value;
            const surnom = document.getElementById('surnom').value;
            const email = document.getElementById('email').value;
            
            recap.innerHTML = `
                <li><i class="fas fa-user mr-2"></i>${prenom} ${nom} (@${surnom})</li>
                <li><i class="fas fa-envelope mr-2"></i>${email}</li>
                <li><i class="fas fa-shield-alt mr-2"></i>Mot de passe sécurisé</li>
            `;
        }

        // Password strength checker
        document.getElementById('motdepasse').addEventListener('input', function() {
            const pwd = this.value;
            const strength = [
                pwd.length >= 8,
                /[A-Z]/.test(pwd),
                /[a-z]/.test(pwd),
                /[0-9]/.test(pwd),
                /[!@#$%^&*(),.?":{}|<>]/.test(pwd)
            ].filter(Boolean).length;

            // Update indicators
            document.getElementById('req-length').className = pwd.length >= 8 ? 'text-green-600' : 'text-gray-500';
            document.getElementById('req-upper').className = /[A-Z]/.test(pwd) ? 'text-green-600' : 'text-gray-500';
            document.getElementById('req-lower').className = /[a-z]/.test(pwd) ? 'text-green-600' : 'text-gray-500';
            document.getElementById('req-number').className = /[0-9]/.test(pwd) ? 'text-green-600' : 'text-gray-500';
            document.getElementById('req-special').className = /[!@#$%^&*(),.?":{}|<>]/.test(pwd) ? 'text-green-600' : 'text-gray-500';

            // Update bars
            const colors = ['bg-red-500', 'bg-orange-500', 'bg-yellow-500', 'bg-green-500'];
            for (let i = 1; i <= 4; i++) {
                const bar = document.getElementById(`pwd-strength-${i}`);
                bar.className = `flex-1 rounded-full transition-colors ${i <= strength ? colors[strength-1] : 'bg-gray-200'}`;
            }
        });

        // Password match checker
        document.getElementById('motdepasse2').addEventListener('input', function() {
            const pwd1 = document.getElementById('motdepasse').value;
            const icon = document.getElementById('matchStatus');
            if (this.value === pwd1 && this.value !== '') {
                icon.innerHTML = '<i class="fas fa-check-circle text-green-500"></i>';
            } else if (this.value !== '') {
                icon.innerHTML = '<i class="fas fa-times-circle text-red-500"></i>';
            } else {
                icon.innerHTML = '';
            }
        });

        // Bio counter
        document.querySelector('textarea[name="bio"]').addEventListener('input', function() {
            document.getElementById('bioCount').textContent = this.value.length;
        });
    // ==================== LISTE DES PAYS ====================
const countriesList = [
    { code: "FR", name: "France", native: "France", flag: "🇫🇷" },
    { code: "CD", name: "République Démocratique du Congo", native: "RDC", flag: "🇨🇩" },
    { code: "CG", name: "République du Congo", native: "Congo", flag: "🇨🇬" },
    { code: "CA", name: "Canada", native: "Canada", flag: "🇨🇦" },
    { code: "BE", name: "Belgique", native: "België/Belgique", flag: "🇧🇪" },
    { code: "CH", name: "Suisse", native: "Schweiz/Suisse", flag: "🇨🇭" },
    { code: "SN", name: "Sénégal", native: "Sénégal", flag: "🇸🇳" },
    { code: "CI", name: "Côte d'Ivoire", native: "Côte d'Ivoire", flag: "🇨🇮" },
    { code: "CM", name: "Cameroun", native: "Cameroun", flag: "🇨🇲" },
    { code: "MA", name: "Maroc", native: "المغرب", flag: "🇲🇦" },
    { code: "DZ", name: "Algérie", native: "الجزائر", flag: "🇩🇿" },
    { code: "TN", name: "Tunisie", native: "تونس", flag: "🇹🇳" },
    { code: "ML", name: "Mali", native: "Mali", flag: "🇲🇱" },
    { code: "BF", name: "Burkina Faso", native: "Burkina", flag: "🇧🇫" },
    { code: "NE", name: "Niger", native: "Niger", flag: "🇳🇪" },
    { code: "TD", name: "Tchad", native: "Tchad", flag: "🇹🇩" },
    { code: "GA", name: "Gabon", native: "Gabon", flag: "🇬🇦" },
    { code: "US", name: "États-Unis", native: "United States", flag: "🇺🇸" },
    { code: "GB", name: "Royaume-Uni", native: "United Kingdom", flag: "🇬🇧" },
    { code: "DE", name: "Allemagne", native: "Deutschland", flag: "🇩🇪" },
    { code: "ES", name: "Espagne", native: "España", flag: "🇪🇸" },
    { code: "IT", name: "Italie", native: "Italia", flag: "🇮🇹" },
    { code: "PT", name: "Portugal", native: "Portugal", flag: "🇵🇹" },
    { code: "NL", name: "Pays-Bas", native: "Nederland", flag: "🇳🇱" },
    { code: "NG", name: "Nigeria", native: "Nigeria", flag: "🇳🇬" },
    { code: "ZA", name: "Afrique du Sud", native: "South Africa", flag: "🇿🇦" },
    { code: "GH", name: "Ghana", native: "Ghana", flag: "🇬🇭" },
    { code: "BJ", name: "Bénin", native: "Bénin", flag: "🇧🇯" },
    { code: "TG", name: "Togo", native: "Togo", flag: "🇹🇬" },
    { code: "AO", name: "Angola", native: "Angola", flag: "🇦🇴" },
    { code: "BR", name: "Brésil", native: "Brasil", flag: "🇧🇷" },
    { code: "CN", name: "Chine", native: "中国", flag: "🇨🇳" },
    { code: "JP", name: "Japon", native: "日本", flag: "🇯🇵" },
    { code: "IN", name: "Inde", native: "भारत", flag: "🇮🇳" },
    { code: "RU", name: "Russie", native: "Россия", flag: "🇷🇺" }
];

// Variables
let currentCountrySuggestions = [];
let selectedCountryIndex = -1;
let countrySearchInput = document.getElementById('countrySearchInput');
let countrySuggestionsDiv = document.getElementById('countrySuggestions');
let nationaliteHidden = document.getElementById('nationalite');

function searchCountries(searchTerm) {
    if (!searchTerm || searchTerm.trim() === '') {
        return countriesList.slice(0, 10);
    }
    const term = searchTerm.toLowerCase().trim();
    return countriesList.filter(country => 
        country.name.toLowerCase().includes(term) ||
        country.native.toLowerCase().includes(term) ||
        country.code.toLowerCase().includes(term)
    ).slice(0, 15);
}

function showSuggestions(suggestions) {
    if (suggestions.length === 0) {
        countrySuggestionsDiv.innerHTML = '<div class="no-results"><i class="fas fa-search mr-2"></i>Aucun pays trouvé</div>';
        countrySuggestionsDiv.classList.remove('hidden');
        return;
    }
    
    currentCountrySuggestions = suggestions;
    selectedCountryIndex = -1;
    
    const html = suggestions.map((country, index) => `
        <div class="country-suggestion-item" data-index="${index}" data-country-name="${country.name}" data-country-flag="${country.flag}">
            <span class="country-flag">${country.flag}</span>
            <div class="flex-1">
                <div class="country-name">${country.name}</div>
                <div class="country-native">${country.native}</div>
            </div>
            <div class="text-xs text-gray-400">${country.code}</div>
        </div>
    `).join('');
    
    countrySuggestionsDiv.innerHTML = html;
    countrySuggestionsDiv.classList.remove('hidden');
    
    document.querySelectorAll('.country-suggestion-item').forEach(item => {
        item.addEventListener('click', () => {
            const countryName = item.getAttribute('data-country-name');
            const countryFlag = item.getAttribute('data-country-flag');
            selectCountry(countryName, countryFlag);
        });
    });
}

function selectCountry(countryName, countryFlag) {
    countrySearchInput.value = `${countryFlag} ${countryName}`;
    nationaliteHidden.value = countryName;
    countrySuggestionsDiv.classList.add('hidden');
    selectedCountryIndex = -1;
    countrySearchInput.style.borderColor = '#10b981';
    setTimeout(() => { countrySearchInput.style.borderColor = ''; }, 500);
}

if (countrySearchInput) {
    countrySearchInput.addEventListener('input', function(e) {
        const value = this.value;
        const searchValue = value.replace(/^[🇦-🇿]+\s/, '');
        const suggestions = searchCountries(searchValue);
        showSuggestions(suggestions);
    });

    countrySearchInput.addEventListener('keydown', function(e) {
        const items = document.querySelectorAll('.country-suggestion-item');
        
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            if (selectedCountryIndex < items.length - 1) {
                selectedCountryIndex++;
                items.forEach((item, idx) => {
                    if (idx === selectedCountryIndex) {
                        item.classList.add('selected');
                        item.scrollIntoView({ block: 'nearest' });
                    } else {
                        item.classList.remove('selected');
                    }
                });
            }
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            if (selectedCountryIndex > 0) {
                selectedCountryIndex--;
                items.forEach((item, idx) => {
                    if (idx === selectedCountryIndex) {
                        item.classList.add('selected');
                        item.scrollIntoView({ block: 'nearest' });
                    } else {
                        item.classList.remove('selected');
                    }
                });
            }
        } else if (e.key === 'Enter') {
            e.preventDefault();
            if (selectedCountryIndex >= 0 && currentCountrySuggestions[selectedCountryIndex]) {
                const country = currentCountrySuggestions[selectedCountryIndex];
                selectCountry(country.name, country.flag);
            } else if (currentCountrySuggestions.length > 0) {
                const country = currentCountrySuggestions[0];
                selectCountry(country.name, country.flag);
            }
        } else if (e.key === 'Escape') {
            countrySuggestionsDiv.classList.add('hidden');
        }
    });

    countrySearchInput.addEventListener('focus', () => {
        const icon = document.getElementById('countrySearchIcon');
        if (icon) icon.style.transform = 'rotate(180deg)';
    });
    
    countrySearchInput.addEventListener('blur', () => {
        setTimeout(() => {
            const icon = document.getElementById('countrySearchIcon');
            if (icon) icon.style.transform = 'rotate(0deg)';
        }, 200);
    });
}

document.addEventListener('click', function(e) {
    if (countrySearchInput && !countrySearchInput.contains(e.target) && countrySuggestionsDiv && !countrySuggestionsDiv.contains(e.target)) {
        countrySuggestionsDiv.classList.add('hidden');
    }
});

// Initialisation de la valeur existante
const existingCountry = nationaliteHidden ? nationaliteHidden.value : '';
if (existingCountry && countrySearchInput) {
    const found = countriesList.find(c => c.name === existingCountry);
    if (found) {
        countrySearchInput.value = `${found.flag} ${found.name}`;
    }
}
    </script>
</body>
</html>