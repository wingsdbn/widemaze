<?php
/**
 * WideMaze - Page d'Inscription
 * Création de compte avec validation avancée
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';

// Rediriger si déjà connecté
if (isset($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit();
}

$errors = [];
$success = false;

// Traitement du formulaire d'inscription
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['inscrire'])) {
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
        
        // Validation date de naissance
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
        if (!empty($telephone) && !preg_match('/^[0-9+\-\s()]+$/', $telephone)) {
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
                        // Hashage du mot de passe
                        $hash = hash_password($motdepasse);
                        
                        // Gestion avatar par défaut
                        $avatar = DEFAULT_AVATAR;
                        
                        // Gestion avatar uploadé
                        if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
                            if (!is_dir(AVATAR_DIR)) {
                                mkdir(AVATAR_DIR, 0755, true);
                            }
                            $upload = handle_file_upload($_FILES['avatar'], AVATAR_DIR, ALLOWED_IMAGE_TYPES, 2 * 1024 * 1024);
                            if ($upload['success']) {
                                $avatar = $upload['filename'];
                            } else {
                                $errors[] = "Erreur avatar: " . $upload['error'];
                            }
                        }
                        
                        if (empty($errors)) {
                            // Insertion de l'utilisateur
                            $stmt = $pdo->prepare("
                                INSERT INTO utilisateurs (
                                    prenom, nom, surnom, email, motdepasse, avatar,
                                    profession, universite, faculte, niveau_etude,
                                    nationalite, telephone, datedenaissance, sexe, bio,
                                    dateinscription, is_verified, is_active
                                ) VALUES (
                                    ?, ?, ?, ?, ?, ?,
                                    ?, ?, ?, ?,
                                    ?, ?, ?, ?, ?,
                                    NOW(), 0, 1
                                )
                            ");
                            
                            $stmt->execute([
                                $prenom, $nom, $surnom, $email, $hash, $avatar,
                                $profession, $universite, $faculte, $niveau_etude,
                                $nationalite, $telephone, $datedenaissance, $sexe, $bio
                            ]);
                            
                            $userId = $pdo->lastInsertId();
                            
                            // Log de l'activité
                            log_activity($pdo, $userId, 'register', ['ip' => $_SERVER['REMOTE_ADDR']]);
                            
                            // Créer les préférences utilisateur par défaut
                            try {
                                $stmt = $pdo->prepare("
                                    INSERT INTO user_preferences (user_id) VALUES (?)
                                ");
                                $stmt->execute([$userId]);
                            } catch (PDOException $e) {
                                // Table peut ne pas exister encore
                            }
                            
                            $success = true;
                        }
                    }
                }
            } catch (PDOException $e) {
                error_log("Registration error: " . $e->getMessage());
                $errors[] = "Erreur système, veuillez réessayer";
            }
        }
    }
}

$csrfToken = generate_csrf_token();
$page_title = 'Inscription';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inscription - WideMaze</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');
        body { font-family: 'Inter', sans-serif; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
        .glass {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
        }
        .step { display: none; animation: fadeIn 0.3s ease; }
        .step.active { display: block; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        .progress-bar { transition: width 0.4s ease; }
        .input-field {
            transition: all 0.3s ease;
        }
        .input-field:focus {
            border-color: #f59e0b;
            box-shadow: 0 0 0 3px rgba(245, 158, 11, 0.1);
        }
        .btn-gradient {
            background: linear-gradient(135deg, #f59e0b 0%, #ea580c 100%);
            transition: all 0.3s ease;
        }
        .btn-gradient:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(245, 158, 11, 0.4);
        }
        .password-strength { height: 4px; transition: all 0.3s; }
        .country-suggestions {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            max-height: 250px;
            overflow-y: auto;
            z-index: 50;
            box-shadow: 0 10px 25px -5px rgba(0,0,0,0.1);
        }
        .country-suggestion-item {
            padding: 10px 12px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 10px;
            transition: background 0.2s;
        }
        .country-suggestion-item:hover {
            background: #f9fafb;
        }
        .country-suggestion-item.selected {
            background: #fef3c7;
        }
    </style>
</head>
<body class="min-h-screen p-4 flex items-center justify-center p-4 relative">
    

    <div class="container mx-auto max-w-3xl py-8 relative">
        
        <?php if ($success): ?>
            <!-- Success Message -->
            <div class="glass rounded-3xl shadow-2xl p-8 text-center">
                <div class="w-24 h-24 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-4">
                    <i class="fas fa-check-circle text-green-500 text-5xl"></i>
                </div>
                <h2 class="text-3xl font-bold text-gray-800 mb-2">Inscription réussie !</h2>
                <p class="text-gray-600 mb-6">Votre compte a été créé avec succès.</p>
                <a href="connexion.php" class="btn-gradient inline-block text-white font-semibold px-8 py-3 rounded-xl shadow-lg hover:shadow-xl transition-all">
                    Se connecter
                </a>
            </div>
        <?php else: ?>
            <!-- Header -->
            <div class="text-center mb-8">
                <div class="inline-flex items-center gap-3 mb-4 animate-bounce">
                    <div class="w-14 h-14 bg-gradient-to-br from-orange-500 to-pink-500 rounded-2xl flex items-center justify-center shadow-xl">
                        <i class="fas fa-network-wired text-white text-2xl"></i>
                    </div>
                    <h1 class="text-4xl font-bold text-white">WideMaze</h1>
                </div>
                <p class="text-white/80 text-lg">Rejoignez notre communauté académique mondiale</p>
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
            <div class="glass rounded-3xl shadow-2xl p-8">
                <h2 class="text-3xl font-bold text-center mb-2 text-gray-800">Créer un compte</h2>
                <p class="text-center text-gray-500 mb-8">Commencez votre aventure sur WideMaze</p>
                
                <!-- Errors -->
                <?php if (!empty($errors)): ?>
                    <div class="bg-red-50 border-l-4 border-red-500 p-4 rounded-r-lg mb-6">
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
                        <div class="flex items-center gap-3 mb-6">
                            <div class="w-10 h-10 rounded-full bg-orange-100 flex items-center justify-center">
                                <i class="fas fa-user text-orange-500 text-lg"></i>
                            </div>
                            <h3 class="text-xl font-bold text-gray-800">Qui êtes-vous ?</h3>
                        </div>
                        
                        <div class="grid md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-semibold text-gray-700 mb-2">Prénom *</label>
                                <div class="relative">
                                    <i class="fas fa-user absolute left-4 top-1/2 -translate-y-1/2 text-gray-400"></i>
                                    <input type="text" name="prenom" required
                                           class="input-field w-full pl-12 pr-4 py-3 rounded-xl border-2 border-gray-200 focus:border-orange-500 focus:ring-4 focus:ring-orange-100 outline-none transition-all"
                                           value="<?= htmlspecialchars($_POST['prenom'] ?? '') ?>"
                                           placeholder="David">
                                </div>
                            </div>
                            <div>
                                <label class="block text-sm font-semibold text-gray-700 mb-2">Nom *</label>
                                <div class="relative">
                                    <i class="fas fa-user absolute left-4 top-1/2 -translate-y-1/2 text-gray-400"></i>
                                    <input type="text" name="nom" required
                                           class="input-field w-full pl-12 pr-4 py-3 rounded-xl border-2 border-gray-200 focus:border-orange-500 focus:ring-4 focus:ring-orange-100 outline-none transition-all"
                                           value="<?= htmlspecialchars($_POST['nom'] ?? '') ?>"
                                           placeholder="Ngwangwa">
                                </div>
                            </div>
                        </div>
                        
                        <div class="mt-4">
                            <label class="block text-sm font-semibold text-gray-700 mb-2">Pseudonyme *</label>
                            <div class="relative">
                                <i class="fas fa-at absolute left-4 top-1/2 -translate-y-1/2 text-gray-400"></i>
                                <input type="text" name="surnom" id="surnom" required
                                       class="input-field w-full pl-12 pr-4 py-3 rounded-xl border-2 border-gray-200 focus:border-orange-500 focus:ring-4 focus:ring-orange-100 outline-none transition-all"
                                       value="<?= htmlspecialchars($_POST['surnom'] ?? '') ?>"
                                       placeholder="wings_dbn">
                            </div>
                            <p class="text-xs text-gray-500 mt-1">Lettres, chiffres et underscores uniquement</p>
                        </div>
                        
                        <div class="grid md:grid-cols-2 gap-4 mt-4">
                            <div>
                                <label class="block text-sm font-semibold text-gray-700 mb-2">Date de naissance *</label>
                                <input type="date" name="datedenaissance" required
                                       min="1920-01-01" max="2011-12-31"
                                       class="input-field w-full px-4 py-3 rounded-xl border-2 border-gray-200 focus:border-orange-500 focus:ring-4 focus:ring-orange-100 outline-none transition-all"
                                       value="<?= htmlspecialchars($_POST['datedenaissance'] ?? '') ?>">
                            </div>
                            <div>
                                <label class="block text-sm font-semibold text-gray-700 mb-2">Genre</label>
                                <select name="sexe" class="input-field w-full px-4 py-3 rounded-xl border-2 border-gray-200 focus:border-orange-500 focus:ring-4 focus:ring-orange-100 outline-none transition-all bg-white">
                                    <option value="Masculin" <?= ($_POST['sexe'] ?? '') == 'Masculin' ? 'selected' : '' ?>>Masculin</option>
                                    <option value="Feminin" <?= ($_POST['sexe'] ?? '') == 'Feminin' ? 'selected' : '' ?>>Féminin</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Step 2: Account Security -->
                    <div class="step" data-step="2">
                        <div class="flex items-center gap-3 mb-6">
                            <div class="w-10 h-10 rounded-full bg-orange-100 flex items-center justify-center">
                                <i class="fas fa-shield-alt text-orange-500 text-lg"></i>
                            </div>
                            <h3 class="text-xl font-bold text-gray-800">Sécurisez votre compte</h3>
                        </div>
                        
                        <div class="space-y-4">
                            <div>
                                <label class="block text-sm font-semibold text-gray-700 mb-2">Email *</label>
                                <div class="relative">
                                    <i class="fas fa-envelope absolute left-4 top-1/2 -translate-y-1/2 text-gray-400"></i>
                                    <input type="email" name="email" id="email" required
                                           class="input-field w-full pl-12 pr-4 py-3 rounded-xl border-2 border-gray-200 focus:border-orange-500 focus:ring-4 focus:ring-orange-100 outline-none transition-all"
                                           value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                                           placeholder="david@exemple.com">
                                </div>
                            </div>
                            
                            <div>
                                <label class="block text-sm font-semibold text-gray-700 mb-2">Mot de passe *</label>
                                <div class="relative">
                                    <i class="fas fa-lock absolute left-4 top-1/2 -translate-y-1/2 text-gray-400"></i>
                                    <input type="password" name="motdepasse" id="motdepasse" required
                                           class="input-field w-full pl-12 pr-12 py-3 rounded-xl border-2 border-gray-200 focus:border-orange-500 focus:ring-4 focus:ring-orange-100 outline-none transition-all"
                                           placeholder="••••••••">
                                    <button type="button" onclick="togglePassword('motdepasse', 'eye1')" class="absolute right-4 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600">
                                        <i class="fas fa-eye" id="eye1"></i>
                                    </button>
                                </div>
                                <div class="mt-2 space-y-2">
                                    <div class="flex gap-1 h-1">
                                        <div id="pwd-strength-1" class="flex-1 bg-gray-200 rounded-full"></div>
                                        <div id="pwd-strength-2" class="flex-1 bg-gray-200 rounded-full"></div>
                                        <div id="pwd-strength-3" class="flex-1 bg-gray-200 rounded-full"></div>
                                        <div id="pwd-strength-4" class="flex-1 bg-gray-200 rounded-full"></div>
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
                                           class="input-field w-full pl-12 pr-12 py-3 rounded-xl border-2 border-gray-200 focus:border-orange-500 focus:ring-4 focus:ring-orange-100 outline-none transition-all"
                                           placeholder="••••••••">
                                    <span id="matchStatus" class="absolute right-4 top-1/2 -translate-y-1/2"></span>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Step 3: Profile Details -->
                    <div class="step" data-step="3">
                        <div class="flex items-center gap-3 mb-6">
                            <div class="w-10 h-10 rounded-full bg-orange-100 flex items-center justify-center">
                                <i class="fas fa-graduation-cap text-orange-500 text-lg"></i>
                            </div>
                            <h3 class="text-xl font-bold text-gray-800">Votre profil académique</h3>
                        </div>
                        
                        <div class="grid md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-semibold text-gray-700 mb-2">Profession/Rôle</label>
                                <select name="profession" class="input-field w-full px-4 py-3 rounded-xl border-2 border-gray-200 focus:border-orange-500 focus:ring-4 focus:ring-orange-100 outline-none transition-all bg-white">
                                    <option value="etudiant" <?= ($_POST['profession'] ?? '') == 'etudiant' ? 'selected' : '' ?>>Étudiant</option>
                                    <option value="enseignant" <?= ($_POST['profession'] ?? '') == 'enseignant' ? 'selected' : '' ?>>Enseignant</option>
                                    <option value="professionnel" <?= ($_POST['profession'] ?? '') == 'professionnel' ? 'selected' : '' ?>>Professionnel</option>
                                    <option value="autre" <?= ($_POST['profession'] ?? '') == 'autre' ? 'selected' : '' ?>>Autre</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-semibold text-gray-700 mb-2">Pays</label>
                                <div class="relative">
                                    <i class="fas fa-globe absolute left-4 top-1/2 -translate-y-1/2 text-gray-400 z-10"></i>
                                    <input type="text" id="countrySearchInput" autocomplete="off"
                                           class="input-field w-full pl-12 pr-4 py-3 rounded-xl border-2 border-gray-200 focus:border-orange-500 focus:ring-4 focus:ring-orange-100 outline-none transition-all"
                                           placeholder="Rechercher votre pays..."
                                           value="<?= htmlspecialchars($_POST['nationalite'] ?? '') ?>">
                                    <div id="countrySuggestions" class="country-suggestions hidden"></div>
                                    <input type="hidden" name="nationalite" id="nationalite" value="<?= htmlspecialchars($_POST['nationalite'] ?? '') ?>">
                                </div>
                            </div>
                        </div>
                        
                        <div class="mt-4">
                            <label class="block text-sm font-semibold text-gray-700 mb-2">Université/Établissement</label>
                            <div class="relative">
                                <i class="fas fa-university absolute left-4 top-1/2 -translate-y-1/2 text-gray-400"></i>
                                <input type="text" name="universite"
                                       class="input-field w-full pl-12 pr-4 py-3 rounded-xl border-2 border-gray-200 focus:border-orange-500 focus:ring-4 focus:ring-orange-100 outline-none transition-all"
                                       value="<?= htmlspecialchars($_POST['universite'] ?? '') ?>"
                                       placeholder="Université de ...">
                            </div>
                        </div>
                        
                        <div class="grid md:grid-cols-2 gap-4 mt-4">
                            <div>
                                <label class="block text-sm font-semibold text-gray-700 mb-2">Faculté</label>
                                <input type="text" name="faculte"
                                       class="input-field w-full px-4 py-3 rounded-xl border-2 border-gray-200 focus:border-orange-500 focus:ring-4 focus:ring-orange-100 outline-none transition-all"
                                       value="<?= htmlspecialchars($_POST['faculte'] ?? '') ?>"
                                       placeholder="Sciences, Droit...">
                            </div>
                            <div>
                                <label class="block text-sm font-semibold text-gray-700 mb-2">Niveau d'études</label>
                                <input type="text" name="niveau_etude"
                                       class="input-field w-full px-4 py-3 rounded-xl border-2 border-gray-200 focus:border-orange-500 focus:ring-4 focus:ring-orange-100 outline-none transition-all"
                                       value="<?= htmlspecialchars($_POST['niveau_etude'] ?? '') ?>"
                                       placeholder="Licence 3, Master 1...">
                            </div>
                        </div>
                        
                        <div class="mt-4">
                            <label class="block text-sm font-semibold text-gray-700 mb-2">Téléphone</label>
                            <div class="relative">
                                <i class="fas fa-phone absolute left-4 top-1/2 -translate-y-1/2 text-gray-400"></i>
                                <input type="tel" name="telephone"
                                       class="input-field w-full pl-12 pr-4 py-3 rounded-xl border-2 border-gray-200 focus:border-orange-500 focus:ring-4 focus:ring-orange-100 outline-none transition-all"
                                       value="<?= htmlspecialchars($_POST['telephone'] ?? '') ?>"
                                       placeholder="+243 823 851 403">
                            </div>
                        </div>
                        
                        <div class="mt-4">
                            <label class="block text-sm font-semibold text-gray-700 mb-2">Bio (présentation courte)</label>
                            <textarea name="bio" rows="3" maxlength="500"
                                      class="input-field w-full px-4 py-3 rounded-xl border-2 border-gray-200 focus:border-orange-500 focus:ring-4 focus:ring-orange-100 outline-none transition-all resize-none"
                                      placeholder="Parlez-nous un peu de vous..."><?= htmlspecialchars($_POST['bio'] ?? '') ?></textarea>
                            <p class="text-xs text-gray-500 mt-1 text-right"><span id="bioCount"><?= strlen($_POST['bio'] ?? '') ?></span>/500</p>
                        </div>
                    </div>
                    
                    <!-- Step 4: Avatar & Finalization -->
                    <div class="step" data-step="4">
                        <div class="flex items-center gap-3 mb-6">
                            <div class="w-10 h-10 rounded-full bg-orange-100 flex items-center justify-center">
                                <i class="fas fa-camera text-orange-500 text-lg"></i>
                            </div>
                            <h3 class="text-xl font-bold text-gray-800">Photo de profil</h3>
                        </div>
                        
                        <div class="text-center">
                            <div class="relative inline-block">
                                <div id="avatarPreview" class="w-32 h-32 rounded-full bg-gray-200 flex items-center justify-center overflow-hidden border-4 border-white shadow-lg mx-auto mb-4">
                                    <i class="fas fa-user text-6xl text-gray-400"></i>
                                </div>
                                <label for="avatar" class="absolute bottom-0 right-0 w-10 h-10 bg-orange-500 hover:bg-orange-600 rounded-full flex items-center justify-center cursor-pointer shadow-lg transition-colors">
                                    <i class="fas fa-camera text-white"></i>
                                </label>
                            </div>
                            <input type="file" name="avatar" id="avatar" accept="image/*" class="hidden" onchange="previewAvatar(this)">
                            <p class="text-sm text-gray-600 mb-2">Ajoutez une photo de profil (optionnel)</p>
                            <p class="text-xs text-gray-400">JPG, PNG ou GIF • Max 2MB</p>
                        </div>
                        
                        <div class="mt-8 p-4 bg-orange-50 rounded-xl border border-orange-200">
                            <h4 class="font-semibold text-orange-800 mb-2">
                                <i class="fas fa-info-circle mr-2"></i>Récapitulatif
                            </h4>
                            <ul class="text-sm text-orange-700 space-y-1" id="recapList"></ul>
                        </div>
                        
                        <div class="mt-4 flex items-start gap-3">
                            <input type="checkbox" id="terms" required class="mt-1 w-4 h-4 text-orange-500 rounded border-gray-300 focus:ring-orange-500">
                            <label for="terms" class="text-sm text-gray-600">
                                J'accepte les <a href="#" class="text-orange-500 hover:underline">Conditions d'utilisation</a> et la 
                                <a href="#" class="text-orange-500 hover:underline">Politique de confidentialité</a> de WideMaze
                            </label>
                        </div>
                    </div>
                    
                    <!-- Navigation Buttons -->
                    <div class="flex justify-between pt-6 border-t border-gray-200">
                        <button type="button" id="prevBtn" onclick="changeStep(-1)" class="px-6 py-3 rounded-xl border-2 border-gray-300 text-gray-700 hover:bg-gray-50 hover:border-gray-400 transition-all font-medium hidden items-center gap-2">
                            <i class="fas fa-arrow-left"></i>Retour
                        </button>
                        <div class="ml-auto flex gap-3">
                            <button type="button" id="nextBtn" onclick="changeStep(1)" class="px-8 py-3 bg-gradient-to-r from-orange-500 to-orange-600 text-white rounded-xl hover:shadow-xl hover:scale-105 transition-all font-semibold flex items-center gap-2">
                                Suivant <i class="fas fa-arrow-right"></i>
                            </button>
                            <button type="submit" name="inscrire" id="submitBtn" class="px-8 py-3 bg-gradient-to-r from-green-500 to-emerald-600 text-white rounded-xl hover:shadow-xl hover:scale-105 transition-all font-semibold hidden items-center gap-2">
                                <i class="fas fa-check"></i>Créer mon compte
                            </button>
                        </div>
                    </div>
                </form>
                
                <div class="text-center mt-8 pt-6 border-t border-gray-200">
                    <p class="text-gray-600">
                        Déjà membre ?
                        <a href="connexion.php" class="text-orange-500 hover:text-orange-600 font-semibold hover:underline transition-colors">
                            Connectez-vous
                        </a>
                    </p>
                </div>
            </div>
        <?php endif; ?>
    </div>
    
    <script>
        let currentStep = 1;
        const totalSteps = 4;
        
        // Liste des pays - DOIT être définie AVANT son utilisation
        const countriesList = [
            { code: "ZA", name: "Afrique du Sud", native: "South Africa", flag: "🇿🇦" },
            { code: "AL", name: "Albanie", native: "Shqipëria", flag: "🇦🇱" },
            { code: "DZ", name: "Algérie", native: "الجزائر", flag: "🇩🇿" },
            { code: "DE", name: "Allemagne", native: "Deutschland", flag: "🇩🇪" },
            { code: "AD", name: "Andorre", native: "Andorra", flag: "🇦🇩" },
            { code: "AO", name: "Angola", native: "Angola", flag: "🇦🇴" },
            { code: "AG", name: "Antigua-et-Barbuda", native: "Antigua and Barbuda", flag: "🇦🇬" },
            { code: "SA", name: "Arabie Saoudite", native: "المملكة العربية السعودية", flag: "🇸🇦" },
            { code: "AR", name: "Argentine", native: "Argentina", flag: "🇦🇷" },
            { code: "AM", name: "Arménie", native: "Հայաստան", flag: "🇦🇲" },
            { code: "AU", name: "Australie", native: "Australia", flag: "🇦🇺" },
            { code: "AT", name: "Autriche", native: "Österreich", flag: "🇦🇹" },
            { code: "AZ", name: "Azerbaïdjan", native: "Azərbaycan", flag: "🇦🇿" },
            { code: "BS", name: "Bahamas", native: "Bahamas", flag: "🇧🇸" },
            { code: "BH", name: "Bahreïn", native: "البحرين", flag: "🇧🇭" },
            { code: "BD", name: "Bangladesh", native: "বাংলাদেশ", flag: "🇧🇩" },
            { code: "BB", name: "Barbade", native: "Barbados", flag: "🇧🇧" },
            { code: "BE", name: "Belgique", native: "België/Belgique", flag: "🇧🇪" },
            { code: "BZ", name: "Belize", native: "Belize", flag: "🇧🇿" },
            { code: "BJ", name: "Bénin", native: "Bénin", flag: "🇧🇯" },
            { code: "BT", name: "Bhoutan", native: "འབྲུག་ཡུལ", flag: "🇧🇹" },
            { code: "BY", name: "Biélorussie", native: "Беларусь", flag: "🇧🇾" },
            { code: "MM", name: "Birmanie", native: "မြန်မာ", flag: "🇲🇲" },
            { code: "BO", name: "Bolivie", native: "Bolivia", flag: "🇧🇴" },
            { code: "BA", name: "Bosnie-Herzégovine", native: "Bosna i Hercegovina", flag: "🇧🇦" },
            { code: "BW", name: "Botswana", native: "Botswana", flag: "🇧🇼" },
            { code: "BR", name: "Brésil", native: "Brasil", flag: "🇧🇷" },
            { code: "BN", name: "Brunei", native: "Brunei", flag: "🇧🇳" },
            { code: "BG", name: "Bulgarie", native: "България", flag: "🇧🇬" },
            { code: "BF", name: "Burkina Faso", native: "Burkina", flag: "🇧🇫" },
            { code: "BI", name: "Burundi", native: "Burundi", flag: "🇧🇮" },
            { code: "KH", name: "Cambodge", native: "កម្ពុជា", flag: "🇰🇭" },
            { code: "CM", name: "Cameroun", native: "Cameroun", flag: "🇨🇲" },
            { code: "CA", name: "Canada", native: "Canada", flag: "🇨🇦" },
            { code: "CV", name: "Cap-Vert", native: "Cabo Verde", flag: "🇨🇻" },
            { code: "CL", name: "Chili", native: "Chile", flag: "🇨🇱" },
            { code: "CN", name: "Chine", native: "中国", flag: "🇨🇳" },
            { code: "CY", name: "Chypre", native: "Κύπρος", flag: "🇨🇾" },
            { code: "CO", name: "Colombie", native: "Colombia", flag: "🇨🇴" },
            { code: "KM", name: "Comores", native: "Komori", flag: "🇰🇲" },
            { code: "CG", name: "République du Congo", native: "Congo", flag: "🇨🇬" },
            { code: "KR", name: "Corée du Sud", native: "한국", flag: "🇰🇷" },
            { code: "CR", name: "Costa Rica", native: "Costa Rica", flag: "🇨🇷" },
            { code: "CI", name: "Côte d'Ivoire", native: "Côte d'Ivoire", flag: "🇨🇮" },
            { code: "HR", name: "Croatie", native: "Hrvatska", flag: "🇭🇷" },
            { code: "CU", name: "Cuba", native: "Cuba", flag: "🇨🇺" },
            { code: "DK", name: "Danemark", native: "Danmark", flag: "🇩🇰" },
            { code: "DJ", name: "Djibouti", native: "Djibouti", flag: "🇩🇯" },
            { code: "DO", name: "République Dominicaine", native: "República Dominicana", flag: "🇩🇴" },
            { code: "EG", name: "Égypte", native: "مصر", flag: "🇪🇬" },
            { code: "AE", name: "Émirats Arabes Unis", native: "الإمارات العربية المتحدة", flag: "🇦🇪" },
            { code: "EC", name: "Équateur", native: "Ecuador", flag: "🇪🇨" },
            { code: "ER", name: "Érythrée", native: "ኤርትራ", flag: "🇪🇷" },
            { code: "ES", name: "Espagne", native: "España", flag: "🇪🇸" },
            { code: "EE", name: "Estonie", native: "Eesti", flag: "🇪🇪" },
            { code: "US", name: "États-Unis", native: "United States", flag: "🇺🇸" },
            { code: "ET", name: "Éthiopie", native: "ኢትዮጵያ", flag: "🇪🇹" },
            { code: "FJ", name: "Fidji", native: "Fiji", flag: "🇫🇯" },
            { code: "FI", name: "Finlande", native: "Suomi", flag: "🇫🇮" },
            { code: "FR", name: "France", native: "France", flag: "🇫🇷" },
            { code: "GA", name: "Gabon", native: "Gabon", flag: "🇬🇦" },
            { code: "GM", name: "Gambie", native: "Gambia", flag: "🇬🇲" },
            { code: "GE", name: "Géorgie", native: "საქართველო", flag: "🇬🇪" },
            { code: "GH", name: "Ghana", native: "Ghana", flag: "🇬🇭" },
            { code: "GR", name: "Grèce", native: "Ελλάδα", flag: "🇬🇷" },
            { code: "GD", name: "Grenade", native: "Grenada", flag: "🇬🇩" },
            { code: "GT", name: "Guatemala", native: "Guatemala", flag: "🇬🇹" },
            { code: "GN", name: "Guinée", native: "Guinée", flag: "🇬🇳" },
            { code: "GW", name: "Guinée-Bissau", native: "Guiné-Bissau", flag: "🇬🇼" },
            { code: "GY", name: "Guyana", native: "Guyana", flag: "🇬🇾" },
            { code: "HT", name: "Haïti", native: "Haïti", flag: "🇭🇹" },
            { code: "HN", name: "Honduras", native: "Honduras", flag: "🇭🇳" },
            { code: "HU", name: "Hongrie", native: "Magyarország", flag: "🇭🇺" },
            { code: "IN", name: "Inde", native: "भारत", flag: "🇮🇳" },
            { code: "ID", name: "Indonésie", native: "Indonesia", flag: "🇮🇩" },
            { code: "IR", name: "Iran", native: "ایران", flag: "🇮🇷" },
            { code: "IQ", name: "Irak", native: "العراق", flag: "🇮🇶" },
            { code: "IE", name: "Irlande", native: "Ireland", flag: "🇮🇪" },
            { code: "IS", name: "Islande", native: "Ísland", flag: "🇮🇸" },
            { code: "IL", name: "Israël", native: "ישראל", flag: "🇮🇱" },
            { code: "IT", name: "Italie", native: "Italia", flag: "🇮🇹" },
            { code: "JM", name: "Jamaïque", native: "Jamaica", flag: "🇯🇲" },
            { code: "JP", name: "Japon", native: "日本", flag: "🇯🇵" },
            { code: "JO", name: "Jordanie", native: "الأردن", flag: "🇯🇴" },
            { code: "KZ", name: "Kazakhstan", native: "Қазақстан", flag: "🇰🇿" },
            { code: "KE", name: "Kenya", native: "Kenya", flag: "🇰🇪" },
            { code: "KG", name: "Kirghizistan", native: "Кыргызстан", flag: "🇰🇬" },
            { code: "KW", name: "Koweït", native: "الكويت", flag: "🇰🇼" },
            { code: "LA", name: "Laos", native: "ລາວ", flag: "🇱🇦" },
            { code: "LS", name: "Lesotho", native: "Lesotho", flag: "🇱🇸" },
            { code: "LV", name: "Lettonie", native: "Latvija", flag: "🇱🇻" },
            { code: "LB", name: "Liban", native: "لبنان", flag: "🇱🇧" },
            { code: "LR", name: "Libéria", native: "Liberia", flag: "🇱🇷" },
            { code: "LY", name: "Libye", native: "ليبيا", flag: "🇱🇾" },
            { code: "LI", name: "Liechtenstein", native: "Liechtenstein", flag: "🇱🇮" },
            { code: "LT", name: "Lituanie", native: "Lietuva", flag: "🇱🇹" },
            { code: "LU", name: "Luxembourg", native: "Luxembourg", flag: "🇱🇺" },
            { code: "MK", name: "Macédoine du Nord", native: "Северна Македонија", flag: "🇲🇰" },
            { code: "MG", name: "Madagascar", native: "Madagasikara", flag: "🇲🇬" },
            { code: "MY", name: "Malaisie", native: "Malaysia", flag: "🇲🇾" },
            { code: "MW", name: "Malawi", native: "Malawi", flag: "🇲🇼" },
            { code: "MV", name: "Maldives", native: "ދިވެހިރާއްޖެ", flag: "🇲🇻" },
            { code: "ML", name: "Mali", native: "Mali", flag: "🇲🇱" },
            { code: "MT", name: "Malte", native: "Malta", flag: "🇲🇹" },
            { code: "MA", name: "Maroc", native: "المغرب", flag: "🇲🇦" },
            { code: "MU", name: "Maurice", native: "Mauritius", flag: "🇲🇺" },
            { code: "MR", name: "Mauritanie", native: "موريتانيا", flag: "🇲🇷" },
            { code: "MX", name: "Mexique", native: "México", flag: "🇲🇽" },
            { code: "MC", name: "Monaco", native: "Monaco", flag: "🇲🇨" },
            { code: "MN", name: "Mongolie", native: "Монгол улс", flag: "🇲🇳" },
            { code: "ME", name: "Monténégro", native: "Crna Gora", flag: "🇲🇪" },
            { code: "MZ", name: "Mozambique", native: "Moçambique", flag: "🇲🇿" },
            { code: "NA", name: "Namibie", native: "Namibia", flag: "🇳🇦" },
            { code: "NP", name: "Népal", native: "नेपाल", flag: "🇳🇵" },
            { code: "NG", name: "Nigeria", native: "Nigeria", flag: "🇳🇬" },
            { code: "NE", name: "Niger", native: "Niger", flag: "🇳🇪" },
            { code: "NO", name: "Norvège", native: "Norge", flag: "🇳🇴" },
            { code: "NZ", name: "Nouvelle-Zélande", native: "New Zealand", flag: "🇳🇿" },
            { code: "NL", name: "Pays-Bas", native: "Nederland", flag: "🇳🇱" },
            { code: "PE", name: "Pérou", native: "Perú", flag: "🇵🇪" },
            { code: "PL", name: "Pologne", native: "Polska", flag: "🇵🇱" },
            { code: "PT", name: "Portugal", native: "Portugal", flag: "🇵🇹" },
            { code: "QA", name: "Qatar", native: "قطر", flag: "🇶🇦" },
            { code: "CD", name: "République Démocratique du Congo", native: "RDC", flag: "🇨🇩" },
            { code: "CZ", name: "République Tchèque", native: "Česko", flag: "🇨🇿" },
            { code: "RO", name: "Roumanie", native: "România", flag: "🇷🇴" },
            { code: "GB", name: "Royaume-Uni", native: "United Kingdom", flag: "🇬🇧" },
            { code: "RU", name: "Russie", native: "Россия", flag: "🇷🇺" },
            { code: "RW", name: "Rwanda", native: "Rwanda", flag: "🇷🇼" },
            { code: "SN", name: "Sénégal", native: "Sénégal", flag: "🇸🇳" },
            { code: "RS", name: "Serbie", native: "Србија", flag: "🇷🇸" },
            { code: "SG", name: "Singapour", native: "Singapore", flag: "🇸🇬" },
            { code: "SK", name: "Slovaquie", native: "Slovensko", flag: "🇸🇰" },
            { code: "SI", name: "Slovénie", native: "Slovenija", flag: "🇸🇮" },
            { code: "SD", name: "Soudan", native: "السودان", flag: "🇸🇩" },
            { code: "SE", name: "Suède", native: "Sverige", flag: "🇸🇪" },
            { code: "CH", name: "Suisse", native: "Schweiz/Suisse", flag: "🇨🇭" },
            { code: "SR", name: "Suriname", native: "Suriname", flag: "🇸🇷" },
            { code: "TH", name: "Thaïlande", native: "ประเทศไทย", flag: "🇹🇭" },
            { code: "TN", name: "Tunisie", native: "تونس", flag: "🇹🇳" },
            { code: "TR", name: "Turquie", native: "Türkiye", flag: "🇹🇷" },
            { code: "UA", name: "Ukraine", native: "Україна", flag: "🇺🇦" },
            { code: "UY", name: "Uruguay", native: "Uruguay", flag: "🇺🇾" },
            { code: "VN", name: "Vietnam", native: "Việt Nam", flag: "🇻🇳" }
        ];
        
        // Navigation entre étapes
        function updateProgress() {
            const percent = (currentStep / totalSteps) * 100;
            const progressBar = document.getElementById('progressBar');
            const progressPercent = document.getElementById('progressPercent');
            const currentStepNum = document.getElementById('currentStepNum');
            
            if (progressBar) {
                progressBar.style.width = percent + '%';
            }
            if (progressPercent) {
                progressPercent.textContent = Math.round(percent) + '%';
            }
            if (currentStepNum) {
                currentStepNum.textContent = currentStep;
            }
            
            const prevBtn = document.getElementById('prevBtn');
            const nextBtn = document.getElementById('nextBtn');
            const submitBtn = document.getElementById('submitBtn');
            
            if (prevBtn) {
                if (currentStep === 1) {
                    prevBtn.classList.add('hidden');
                    prevBtn.classList.remove('flex');
                } else {
                    prevBtn.classList.remove('hidden');
                    prevBtn.classList.add('flex');
                }
            }
            
            if (nextBtn && submitBtn) {
                if (currentStep === totalSteps) {
                    nextBtn.classList.add('hidden');
                    submitBtn.classList.remove('hidden');
                    submitBtn.classList.add('flex');
                    updateRecap();
                } else {
                    nextBtn.classList.remove('hidden');
                    submitBtn.classList.add('hidden');
                    submitBtn.classList.remove('flex');
                }
            }
        }
        
        function validateStep(step) {
            let valid = true;
            
            if (step === 1) {
                const prenom = document.querySelector('input[name="prenom"]')?.value.trim();
                const nom = document.querySelector('input[name="nom"]')?.value.trim();
                const surnom = document.querySelector('input[name="surnom"]')?.value.trim();
                const dob = document.querySelector('input[name="datedenaissance"]')?.value;
                
                if (!prenom || !nom || !surnom || !dob) {
                    alert('Veuillez remplir tous les champs obligatoires');
                    valid = false;
                } else if (!/^[a-zA-Z0-9_]+$/.test(surnom)) {
                    alert('Le surnom ne doit contenir que des lettres, chiffres et underscores');
                    valid = false;
                }
            }
            
            if (step === 2) {
                const email = document.querySelector('input[name="email"]')?.value.trim();
                const pwd1 = document.querySelector('input[name="motdepasse"]')?.value;
                const pwd2 = document.querySelector('input[name="motdepasse2"]')?.value;
                
                if (!email || !pwd1 || !pwd2) {
                    alert('Veuillez remplir tous les champs');
                    valid = false;
                } else if (pwd1 !== pwd2) {
                    alert('Les mots de passe ne correspondent pas');
                    valid = false;
                } else if (pwd1.length < 8) {
                    alert('Le mot de passe doit faire au moins 8 caractères');
                    valid = false;
                } else if (!/(?=.*[A-Z])(?=.*[a-z])(?=.*[0-9])(?=.*[@#%&*()\-_=+{};:,<.>])/.test(pwd1)) {
                    alert('Le mot de passe ne respecte pas les critères de sécurité');
                    valid = false;
                }
            }
            
            if (step === 3) {
                // Validation optionnelle
            }
            
            if (step === 4) {
                const terms = document.getElementById('terms');
                if (terms && !terms.checked) {
                    alert('Vous devez accepter les conditions d\'utilisation');
                    valid = false;
                }
            }
            
            return valid;
        }
        
        function changeStep(direction) {
            if (direction === 1 && !validateStep(currentStep)) return;
            
            const currentStepDiv = document.querySelector(`.step[data-step="${currentStep}"]`);
            if (currentStepDiv) currentStepDiv.classList.remove('active');
            
            currentStep += direction;
            
            const newStepDiv = document.querySelector(`.step[data-step="${currentStep}"]`);
            if (newStepDiv) newStepDiv.classList.add('active');
            
            if (currentStep === totalSteps) updateRecap();
            updateProgress();
        }
        
        function updateRecap() {
            const recap = document.getElementById('recapList');
            if (!recap) return;
            
            const prenom = document.querySelector('input[name="prenom"]')?.value || '';
            const nom = document.querySelector('input[name="nom"]')?.value || '';
            const surnom = document.querySelector('input[name="surnom"]')?.value || '';
            const email = document.querySelector('input[name="email"]')?.value || '';
            
            recap.innerHTML = `
                <li><i class="fas fa-user mr-2"></i> ${escapeHtml(prenom)} ${escapeHtml(nom)} (@${escapeHtml(surnom)})</li>
                <li><i class="fas fa-envelope mr-2"></i> ${escapeHtml(email)}</li>
                <li><i class="fas fa-shield-alt mr-2"></i> Mot de passe sécurisé</li>
            `;
        }
        
        // Password strength checker
        document.getElementById('motdepasse')?.addEventListener('input', function() {
            const pwd = this.value;
            const strength = [
                pwd.length >= 8,
                /[A-Z]/.test(pwd),
                /[a-z]/.test(pwd),
                /[0-9]/.test(pwd),
                /[!@#$%^&*()\-_=+{};:,<.>]/.test(pwd)
            ].filter(Boolean).length;
            
            const indicators = ['req-length', 'req-upper', 'req-lower', 'req-number', 'req-special'];
            const conditions = [
                pwd.length >= 8,
                /[A-Z]/.test(pwd),
                /[a-z]/.test(pwd),
                /[0-9]/.test(pwd),
                /[!@#$%^&*()\-_=+{};:,<.>]/.test(pwd)
            ];
            
            indicators.forEach((id, i) => {
                const el = document.getElementById(id);
                if (el) {
                    el.className = conditions[i] ? 'text-green-600' : 'text-gray-500';
                }
            });
            
            const colors = ['bg-red-500', 'bg-orange-500', 'bg-yellow-500', 'bg-green-500', 'bg-green-600'];
            for (let i = 1; i <= 4; i++) {
                const bar = document.getElementById(`pwd-strength-${i}`);
                if (bar) {
                    bar.className = `flex-1 rounded-full transition-colors ${i <= strength ? colors[strength-1] : 'bg-gray-200'}`;
                }
            }
        });
        
        // Password match checker
        document.getElementById('motdepasse2')?.addEventListener('input', function() {
            const pwd1 = document.getElementById('motdepasse')?.value;
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
        document.querySelector('textarea[name="bio"]')?.addEventListener('input', function() {
            const count = document.getElementById('bioCount');
            if (count) count.textContent = this.value.length;
        });
        
        // Toggle password visibility
        function togglePassword(inputId, eyeId) {
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
        
        // Avatar preview
        function previewAvatar(input) {
            const preview = document.getElementById('avatarPreview');
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    preview.innerHTML = `<img src="${e.target.result}" class="w-full h-full object-cover">`;
                };
                reader.readAsDataURL(input.files[0]);
            }
        }
        
        // Country search
        let currentCountrySuggestions = [];
        let selectedCountryIndex = -1;
        const countrySearchInput = document.getElementById('countrySearchInput');
        const countrySuggestionsDiv = document.getElementById('countrySuggestions');
        const nationaliteHidden = document.getElementById('nationalite');
        
        function searchCountries(searchTerm) {
            if (!searchTerm || searchTerm.trim() === "") {
                return countriesList.slice(0, 10);
            }
            const term = searchTerm.toLowerCase().trim();
            return countriesList.filter(country => 
                country.name.toLowerCase().includes(term) ||
                country.code.toLowerCase().includes(term)
            ).slice(0, 15);
        }
        
        function showSuggestions(suggestions) {
            if (!countrySuggestionsDiv) return;
            
            if (suggestions.length === 0) {
                countrySuggestionsDiv.innerHTML = '<div class="p-3 text-gray-500 text-center"><i class="fas fa-search mr-2"></i>Aucun pays trouvé</div>';
                countrySuggestionsDiv.classList.remove('hidden');
                return;
            }
            
            currentCountrySuggestions = suggestions;
            selectedCountryIndex = -1;
            
            const html = suggestions.map((country, index) => `
                <div class="country-suggestion-item" data-index="${index}" data-country-name="${country.name}" data-country-flag="${country.flag}">
                    <span class="text-xl">${country.flag}</span>
                    <div class="flex-1">
                        <div class="font-medium">${country.name}</div>
                        <div class="text-xs text-gray-500">${country.code}</div>
                    </div>
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
            if (countrySearchInput) {
                countrySearchInput.value = `${countryFlag} ${countryName}`;
            }
            if (nationaliteHidden) {
                nationaliteHidden.value = countryName;
            }
            if (countrySuggestionsDiv) {
                countrySuggestionsDiv.classList.add('hidden');
            }
            selectedCountryIndex = -1;
        }
        
        if (countrySearchInput) {
            countrySearchInput.addEventListener('input', function(e) {
                const value = this.value;
                const searchValue = value.replace(/^[🇫🇷🇨🇩🇨🇬🇨🇦🇧🇪🇨🇭🇸🇳🇨🇮🇨🇲🇲🇦🇩🇿🇹🇳🇺🇸🇬🇧🇩🇪🇪🇸🇮🇹🇵🇹🇳🇱🇳🇬🇿🇦🇧🇷🇨🇳🇯🇵🇮🇳]+\s*/, '');
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
                    if (countrySuggestionsDiv) countrySuggestionsDiv.classList.add('hidden');
                }
            });
            
            countrySearchInput.addEventListener('blur', () => {
                setTimeout(() => {
                    if (countrySuggestionsDiv) countrySuggestionsDiv.classList.add('hidden');
                }, 200);
            });
        }
        
        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        // Initialisation
        updateProgress();
    </script>
</body>
</html>