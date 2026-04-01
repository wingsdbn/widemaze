<?php 
require_once "config.php";

// Redirect if already logged in
if(isset($_SESSION["user_id"])) {
    header("Location: index.php");
    exit();
}

// Initialize variables
$errors = [];
$email = isset($_COOKIE['remember_email']) ? $_COOKIE['remember_email'] : '';
$remember = isset($_COOKIE['remember_email']);

// Process login form
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['seConnecter'])) {
    $email = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
    $password = $_POST['motdepasse'] ?? '';
    
    // Validation
    if (empty($email) || empty($password)) {
        $errors[] = 'Veuillez remplir tous les champs';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Adresse email invalide';
    } else {
        try {
            // Fetch user
            $stmt = $pdo->prepare("
                SELECT id, surnom, email, motdepasse, prenom, nom, avatar, role, is_verified, is_active 
                FROM utilisateurs 
                WHERE email = ? AND is_active = TRUE
                LIMIT 1
            ");
            $stmt->execute([$email]);
            $user = $stmt->fetch();
            
            // ========== VÉRIFICATION EN CLAIR (PAS DE HASH) ==========
            if ($user && $password === $user['motdepasse']) {
                
                // Mettre l'utilisateur EN LIGNE
                $stmt = $pdo->prepare("UPDATE utilisateurs SET status = 'Online', dateconnexion = NOW(), last_ip = ? WHERE id = ?");
                $stmt->execute([$_SERVER['REMOTE_ADDR'], $user['id']]);
                
                // Log activité
                log_activity($pdo, $user['id'], 'login_success');
                
                // SESSION
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['surnom'] = $user['surnom'];
                $_SESSION['email'] = $user['email'];
                $_SESSION['prenom'] = $user['prenom'];
                $_SESSION['nom'] = $user['nom'];
                $_SESSION['avatar'] = $user['avatar'] ?? 'default-avatar.png';
                $_SESSION['role'] = $user['role'];
                $_SESSION['is_verified'] = $user['is_verified'];
                $_SESSION['last_activity'] = time();
                $_SESSION['last_regeneration'] = time();
                
                // Cookie remember me
                if (isset($_POST['remember']) && $_POST['remember']) {
                    setcookie('remember_email', $email, time() + 30 * 24 * 60 * 60, '/');
                }
                
                // Redirection
                header("Location: index.php");
                exit();
                
            } else {
                $errors[] = "Email ou mot de passe incorrect";
                log_activity($pdo, $user['id'] ?? null, 'login_failed');
            }
        } catch (PDOException $e) {
            error_log("Login error: " . $e->getMessage());
            $errors[] = 'Erreur système, veuillez réessayer';
        }
    }
    $_SESSION['avatar'] = !empty($user['avatar']) ? $user['avatar'] : DEFAULT_AVATAR;
}

// ========== STATISTIQUES ==========
$stats = ['users' => '0+', 'countries' => '0+'];
try {
    // Nombre total d'utilisateurs
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM utilisateurs WHERE is_active = 1");
    $totalUsers = $stmt->fetch()['total'];
    $stats['users'] = number_format($totalUsers) . '+';
    
    // Nombre de pays (basé sur nationalité unique)
    $stmt = $pdo->query("SELECT COUNT(DISTINCT nationalite) FROM utilisateurs WHERE nationalite IS NOT NULL AND nationalite != '' AND is_active = 1");
    $countries = $stmt->fetchColumn();
    $stats['countries'] = ($countries > 0 ? $countries : '1') . '+';
    
} catch (Exception $e) {
    error_log("Stats error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WideMaze - Connexion</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');
        
        body { 
            font-family: 'Inter', sans-serif; 
        }
        
        /* Arrière-plan dégradé style photo */
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
        
        /* Glassmorphism pour les badges */
        .glass-badge {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.3);
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        
        /* Formulaire glassmorphism */
        .glass-form {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.5);
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
        }
        
        /* Input style */
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
        
        /* Bouton principal dégradé */
        .btn-primary {
            background: linear-gradient(135deg, #f59e0b 0%, #ea580c 100%);
            transition: all 0.3s ease;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(245, 158, 11, 0.4);
        }
        
        /* Bouton secondaire */
        .btn-secondary {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            transition: all 0.3s ease;
        }
        
        .btn-secondary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(16, 185, 129, 0.4);
        }
        
        /* Séparateur "ou" */
        .divider {
            position: relative;
            text-align: center;
        }
        
        .divider::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 0;
            right: 0;
            height: 1px;
            background: linear-gradient(90deg, transparent, #dee2e6, transparent);
        }
        
        .divider span {
            background: white;
            padding: 0 1rem;
            position: relative;
            color: #6b7280;
            font-size: 0.875rem;
        }
        
    </style>
</head>
<body class="gradient-bg min-h-screen flex items-center justify-center p-4">


<!-- Particles Background -->
<div class="fixed inset-0 overflow-hidden pointer-events-none">
        <div class="absolute top-20 left-20 w-72 h-72 bg-purple-300 rounded-full mix-blend-multiply filter blur-xl opacity-30 animate-float"></div>
        <div class="absolute top-40 right-20 w-72 h-72 bg-yellow-300 rounded-full mix-blend-multiply filter blur-xl opacity-70 animate-float" style="animation-delay: 2s"></div>
        <div class="absolute -bottom-8 left-1/2 w-72 h-72 bg-pink-300 rounded-full mix-blend-multiply filter blur-xl opacity-70 animate-float" style="animation-delay: 4s"></div>
    </div>

    <div class="container mx-auto max-w-6xl">
        <div class="grid lg:grid-cols-2 gap-12 items-center">
            
            <!-- Left Side -->
            <div class="text-center lg:text-left">
                <div class="flex items-center gap-3 mb-6 justify-center lg:justify-start animate-bounce">
                <div class="w-16 h-16 bg-white rounded-2xl flex items-center justify-center shadow-2xl transform group-hover:rotate-12 transition-transform duration-300">
                        <i class="fas fa-network-wired text-orange-500 text-2xl"></i>
                    </div>
                    <h1 class="text-5xl font-bold text-white">WideMaze</h1>
                </div>
                
                <p class="text-xl text-white mb-2">Connectez-vous avec le monde,</p>
                <p class="text-xl text-white mb-6">
                    <span class="font-semibold">partagez vos moments</span> et <span class="font-semibold">découvrez</span> de nouvelles opportunités.
                </p>
                
                <div class="flex gap-3 justify-center lg:justify-start flex-wrap">
                    <div class="glass-badge px-4 py-2 rounded-full flex items-center gap-2">
                        <i class="fas fa-users text-orange-500"></i>
                        <span class="text-sm font-medium text-gray-700"><?= $stats['users'] ?> Utilisateurs</span>
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

                <!-- Feature highlights -->
                <div class="hidden lg:block mt-8 space-y-3">
                    <div class="flex items-center gap-3 text-gray-600">
                        <div class="w-8 h-8 rounded-full bg-orange/20 flex items-center justify-center">
                            <i class="fas fa-check text-green-500 "></i>
                        </div>
                        <span class="text-white">Communautés académiques par filière</span>
                    </div>
                    <div class="flex items-center gap-3 text-gray-600">
                        <div class="w-8 h-8 rounded-full bg-primary/20 flex items-center justify-center">
                            <i class="fas fa-check text-green-500 "></i>
                        </div>
                        <span class="text-white">Partage de ressources et documents</span>
                    </div>
                    <div class="flex items-center gap-3 text-gray-600">
                        <div class="w-8 h-8 rounded-full bg-primary/20 flex items-center justify-center">
                            <i class="fas fa-check text-green-500 "></i>
                        </div>
                        <span class="text-white">Messagerie instantanée sécurisée</span>
                    </div>
                </div>
            </div>

            <!-- Right Side - Login Form -->
            <div class="glass-form rounded-3xl p-8 max-w-md mx-auto w-full">
                <h2 class="text-3xl font-bold text-center mb-1 text-gray-800">Connexion</h2>
                <p class="text-center text-gray-500 mb-6 text-sm">Bienvenue sur WideMaze</p>
                
                <!-- Success Message -->
                <?php if (isset($_GET['success']) && $_GET['success'] === 'registered'): ?>
                <div class="bg-green-50 border border-green-200 rounded-xl p-4 mb-4 flex items-center gap-3">
                    <i class="fas fa-check-circle text-green-500 text-lg"></i>
                    <span class="text-green-700 text-sm">Compte créé avec succès ! Vous pouvez maintenant vous connecter.</span>
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
                    <div class="relative">
                        <input type="email" name="email" required value="<?= htmlspecialchars($email) ?>"
                            class="input-field w-full px-4 py-3.5 rounded-xl outline-none text-gray-700 placeholder-gray-400"
                            placeholder="Adresse email">
                        <i class="fas fa-envelope absolute right-4 top-1/2 -translate-y-1/2 text-gray-400"></i>
                    </div>

                    <div class="relative">
                        <input type="password" name="motdepasse" required
                            class="input-field w-full px-4 py-3.5 rounded-xl outline-none text-gray-700 placeholder-gray-400"
                            placeholder="Mot de passe">
                        <i class="fas fa-eye absolute right-4 top-1/2 -translate-y-1/2 text-gray-400 cursor-pointer hover:text-gray-600"></i>
                    </div>

                    <div class="flex items-center justify-between text-sm">
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="checkbox" name="remember" <?= $remember ? 'checked' : '' ?>
                                class="w-4 h-4 text-orange-500 rounded border-gray-300 focus:ring-orange-500">
                            <span class="text-gray-600">Se souvenir de moi</span>
                        </label>
                        <a href="passwordrecover.php" class="text-orange-500 hover:text-orange-600 font-medium">Mot de passe oublié?</a>
                    </div>

                    <button type="submit" name="seConnecter"
                        class="btn-primary w-full text-white font-semibold py-3.5 rounded-xl flex items-center justify-center gap-2">
                        Se Connecter
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

</body>
</html>