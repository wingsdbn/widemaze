<?php
/**
 * WideMaze - Page de Connexion
 * Authentification des utilisateurs avec sécurité renforcée
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';

// Rediriger si déjà connecté
if (isset($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit();
}

$errors = [];
$email = isset($_COOKIE['remember_email']) ? $_COOKIE['remember_email'] : '';
$remember = isset($_COOKIE['remember_email']);

// Traitement du formulaire de connexion
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['seConnecter'])) {
    $email = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
    $password = $_POST['motdepasse'] ?? '';
    $remember = isset($_POST['remember']);
    
    // Validation
    if (empty($email) || empty($password)) {
        $errors[] = 'Veuillez remplir tous les champs';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Adresse email invalide';
    } else {
        try {
            // Vérifier les tentatives de connexion
            $attemptCheck = check_login_attempts($pdo, $email);
            if ($attemptCheck['blocked']) {
                $errors[] = "Trop de tentatives. Réessayez dans {$attemptCheck['wait']} secondes.";
            } else {
                // Récupérer l'utilisateur
                $stmt = $pdo->prepare("
                    SELECT id, surnom, email, motdepasse, prenom, nom, avatar, role, is_verified, is_active
                    FROM utilisateurs 
                    WHERE email = ? LIMIT 1
                ");
                $stmt->execute([$email]);
                $user = $stmt->fetch();
                
                // Vérification du mot de passe
                if ($user && $user['is_active'] && verify_password($password, $user['motdepasse'])) {
                    // Réinitialiser les tentatives de connexion
                    reset_login_attempts($pdo, $user['id']);
                    
                    // Mettre l'utilisateur en ligne
                    set_user_online($pdo, $user['id']);
                    
                    // Log de l'activité
                    log_activity($pdo, $user['id'], 'login_success');
                    
                    // Session
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['surnom'] = $user['surnom'];
                    $_SESSION['email'] = $user['email'];
                    $_SESSION['prenom'] = $user['prenom'];
                    $_SESSION['nom'] = $user['nom'];
                    $_SESSION['avatar'] = $user['avatar'] ?? DEFAULT_AVATAR;
                    $_SESSION['role'] = $user['role'];
                    $_SESSION['is_verified'] = $user['is_verified'];
                    $_SESSION['last_activity'] = time();
                    $_SESSION['last_regeneration'] = time();
                    
                    // Cookie remember me
                    if ($remember) {
                        setcookie('remember_email', $email, time() + 30 * 24 * 60 * 60, '/');
                    }
                    
                    // Redirection
                    header('Location: ../index.php');
                    exit();
                } else {
                    record_failed_login($pdo, $email);
                    $errors[] = "Email ou mot de passe incorrect";
                    log_activity($pdo, $user['id'] ?? null, 'login_failed');
                }
            }
        } catch (PDOException $e) {
            error_log("Login error: " . $e->getMessage());
            $errors[] = "Erreur système, veuillez réessayer";
        }
    }
}

// Statistiques pour la page d'accueil
$stats = ['users' => '0+', 'countries' => '0+'];
try {
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM utilisateurs WHERE is_active = 1");
    $totalUsers = $stmt->fetch()['total'];
    $stats['users'] = number_format($totalUsers) . '+';
    
    $stmt = $pdo->query("SELECT COUNT(DISTINCT nationalite) FROM utilisateurs WHERE nationalite IS NOT NULL AND nationalite != '' AND is_active = 1");
    $countries = $stmt->fetchColumn();
    $stats['countries'] = ($countries > 0 ? number_format($countries) : '1') . '+';
} catch (Exception $e) {
    error_log("Stats error: " . $e->getMessage());
}

$csrfToken = generate_csrf_token();
$page_title = 'Connexion';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Connexion - WideMaze</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');
        body { font-family: 'Inter', sans-serif; }
        .gradient-bg {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 25%, #f093fb 50%, #4facfe 75%, #00f2fe 100%);
            background-size: 400% 400%;
            animation: gradientShift 15s ease infinite;
        }
        @keyframes gradientShift {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }
        .glass-badge {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.3);
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        .glass-form {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.5);
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
        }
        .input-field {
            background: #f8f9fa;
            border: 1px solid #e9ecef;
            transition: all 0.3s ease;
        }
        .input-field:focus {
            background: #fff;
            border-color: #f59e0b;
            box-shadow: 0 0 0 3px rgba(245, 158, 11, 0.1);
        }
        .btn-primary {
            background: linear-gradient(135deg, #f59e0b 0%, #ea580c 100%);
            transition: all 0.3s ease;
        }
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(245, 158, 11, 0.4);
        }
        .btn-secondary {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            transition: all 0.3s ease;
        }
        .btn-secondary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(16, 185, 129, 0.4);
        }
        .divider {
            display: flex;
            align-items: center;
            text-align: center;
            color: #9ca3af;
        }
        .divider::before,
        .divider::after {
            content: '';
            flex: 1;
            border-bottom: 1px solid #e5e7eb;
        }
        .divider::before {
            margin-right: 1rem;
        }
        .divider::after {
            margin-left: 1rem;
        }
    </style>
</head>
<body class="gradient-bg min-h-screen flex items-center justify-center p-4">
    <div class="container mx-auto max-w-6xl">
        <div class="grid lg:grid-cols-2 gap-8 items-center">
            
            <!-- Left Side - Branding -->
            <div class="hidden lg:block text-white">
                <div class="mb-8">
                    <div class="inline-flex items-center gap-3  backdrop-blur-sm rounded-2xl px-6 py-3 mb-6 animate-bounce">
                        <div class="w-16 h-16 bg-gradient-to-br from-orange-500 to-pink-500 rounded-xl flex items-center justify-center">
                            <i class="fas fa-network-wired text-white text-2xl"></i>
                        </div>
                        <span class="text-5xl font-bold ">WideMaze</span>
                    </div>
                    <h1 class="text-xl font-bold mb-3">Rejoignez la communauté académique mondiale</h1>
                    <p class="text-xl text-white/80 mb-6">Connectez-vous avec des étudiants et enseignants du monde entier</p>
                </div>
                
                <div class="flex gap-4 mb-8">
                    <div class="glass-badge px-4 py-2 rounded-full flex items-center gap-2">
                        <i class="fas fa-users text-blue-500"></i>
                        <span class="text-sm font-medium text-gray-700"><?= $stats['users'] ?> Membres</span>
                    </div>
                    <div class="glass-badge px-4 py-2 rounded-full flex items-center gap-2">
                        <i class="fas fa-globe text-blue-500"></i>
                        <span class="text-sm font-medium text-gray-700"><?= $stats['countries'] ?> Pays</span>
                    </div>
                    <div class="glass-badge px-4 py-2 rounded-full flex items-center gap-2">
                        <i class="fas fa-shield-alt text-green-500"></i>
                        <span class="text-sm font-medium text-gray-700">Sécurisé</span>
                    </div>
                </div>
                
                <div class="space-y-3">
                    <div class="flex items-center gap-3 text-white/80">
                        <div class="w-8 h-8 rounded-full bg-white/20 flex items-center justify-center">
                            <i class="fas fa-check text-green-400"></i>
                        </div>
                        <span>Communautés académiques par filière</span>
                    </div>
                    <div class="flex items-center gap-3 text-white/80">
                        <div class="w-8 h-8 rounded-full bg-white/20 flex items-center justify-center">
                            <i class="fas fa-check text-green-400"></i>
                        </div>
                        <span>Partage de ressources et documents</span>
                    </div>
                    <div class="flex items-center gap-3 text-white/80">
                        <div class="w-8 h-8 rounded-full bg-white/20 flex items-center justify-center">
                            <i class="fas fa-check text-green-400"></i>
                        </div>
                        <span>Messagerie instantanée sécurisée</span>
                    </div>
                </div>
            </div>
            
            <!-- Right Side - Login Form -->
            <div class="glass-form rounded-3xl p-8 max-w-md mx-auto w-full">
                <h2 class="text-3xl font-bold text-center mb-1 text-gray-800">Connexion</h2>
                <p class="text-center text-gray-500 mb-6 text-sm">Bienvenue sur WideMaze</p>
                
                <!-- Success Message -->
                <?php if (isset($_GET['success']) && $_GET['success'] === 'registered'): ?>
                    <div class="bg-green-50 border border-green-200 rounded-xl p-4 mb-4 flex items-center gap-3 animate-fade-in">
                        <i class="fas fa-check-circle text-green-500 text-lg"></i>
                        <span class="text-green-700 text-sm">Compte créé avec succès ! Vous pouvez maintenant vous connecter.</span>
                    </div>
                <?php endif; ?>
                
                <?php if (isset($_GET['deleted']) && $_GET['deleted'] == 1): ?>
                    <div class="bg-blue-50 border border-blue-200 rounded-xl p-4 mb-4 flex items-center gap-3">
                        <i class="fas fa-info-circle text-blue-500 text-lg"></i>
                        <span class="text-blue-700 text-sm">Votre compte a été désactivé. Contactez l'administrateur pour plus d'informations.</span>
                    </div>
                <?php endif; ?>
                
                <?php if (isset($_GET['error']) && $_GET['error'] === 'session_expired'): ?>
                    <div class="bg-yellow-50 border border-yellow-200 rounded-xl p-4 mb-4 flex items-center gap-3">
                        <i class="fas fa-clock text-yellow-500 text-lg"></i>
                        <span class="text-yellow-700 text-sm">Session expirée. Veuillez vous reconnecter.</span>
                    </div>
                <?php endif; ?>
                
                <!-- Error Messages -->
                <?php if (!empty($errors)): ?>
                    <div class="bg-red-50 border border-red-200 rounded-xl p-4 mb-4">
                        <?php foreach ($errors as $error): ?>
                            <p class="text-red-700 text-sm flex items-center gap-2">
                                <i class="fas fa-exclamation-circle"></i>
                                <?= htmlspecialchars($error) ?>
                            </p>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                
                <form method="post" action="" class="space-y-4">
                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                    
                    <div class="relative">
                        <i class="fas fa-envelope absolute left-4 top-1/2 -translate-y-1/2 text-gray-400"></i>
                        <input type="email" name="email" required value="<?= htmlspecialchars($email) ?>"
                               class="input-field w-full px-4 py-3.5 pl-12 rounded-xl outline-none text-gray-700 placeholder-gray-400"
                               placeholder="Adresse email">
                    </div>
                    
                    <div class="relative">
                        <i class="fas fa-lock absolute left-4 top-1/2 -translate-y-1/2 text-gray-400"></i>
                        <input type="password" name="motdepasse" id="password" required
                               class="input-field w-full px-4 py-3.5 pl-12 pr-12 rounded-xl outline-none text-gray-700 placeholder-gray-400"
                               placeholder="Mot de passe">
                        <button type="button" onclick="togglePassword()" class="absolute right-4 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600">
                            <i class="fas fa-eye" id="togglePasswordIcon"></i>
                        </button>
                    </div>
                    
                    <div class="flex items-center justify-between text-sm">
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="checkbox" name="remember" <?= $remember ? 'checked' : '' ?>
                                   class="w-4 h-4 text-orange-500 rounded border-gray-300 focus:ring-orange-500">
                            <span class="text-gray-600">Se souvenir de moi</span>
                        </label>
                        <a href="passwordrecover.php" class="text-orange-500 hover:text-orange-600 font-medium">Mot de passe oublié ?</a>
                    </div>
                    
                    <button type="submit" name="seConnecter"
                            class="btn-primary w-full text-white font-semibold py-3.5 rounded-xl flex items-center justify-center gap-2">
                        Se connecter
                        <i class="fas fa-arrow-right"></i>
                    </button>
                </form>
                
                <div class="divider my-6">
                    <span>ou</span>
                </div>
                
                <a href="inscription.php"
                   class="btn-secondary block w-full text-white font-semibold py-3.5 rounded-xl text-center">
                    Créer un nouveau compte
                </a>
                
                <p class="text-center text-xs text-gray-500 mt-6 leading-relaxed">
                    En vous connectant, vous acceptez nos
                    <a href="conditions.php" class="text-orange-500 hover:underline">Conditions d'utilisation</a> et
                    <a href="confidentialite.php" class="text-orange-500 hover:underline">Politique de confidentialité</a>
                </p>
            </div>
        </div>
    </div>
    
    <script>
        function togglePassword() {
            const passwordInput = document.getElementById('password');
            const icon = document.getElementById('togglePasswordIcon');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                passwordInput.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        }
    </script>
</body>
</html>