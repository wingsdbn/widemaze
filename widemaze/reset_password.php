<?php
require_once 'config.php';

// Rediriger si déjà connecté
if (isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

$errors = [];
$success = false;
$token = $_GET['token'] ?? '';
$showForm = false;
$userName = '';

// Vérifier si le token est valide
if (!empty($token)) {
    try {
        // Vérifier si la table password_resets existe
        $tableCheck = $pdo->query("SHOW TABLES LIKE 'password_resets'");
        if ($tableCheck->rowCount() > 0) {
            $stmt = $pdo->prepare("
                SELECT pr.*, u.email, u.prenom, u.nom 
                FROM password_resets pr
                JOIN utilisateurs u ON pr.user_id = u.id
                WHERE pr.token = ? AND pr.expires_at > NOW() AND pr.used = 0
            ");
            $stmt->execute([$token]);
            $reset = $stmt->fetch();
            
            if ($reset) {
                $showForm = true;
                $userEmail = $reset['email'];
                $userName = ($reset['prenom'] ?? '') . ' ' . ($reset['nom'] ?? '');
            } else {
                $errors[] = "Le lien de réinitialisation est invalide ou a expiré.";
            }
        } else {
            $errors[] = "Système de réinitialisation non disponible.";
        }
    } catch (PDOException $e) {
        error_log("Erreur vérification token: " . $e->getMessage());
        $errors[] = "Erreur système, veuillez réessayer";
    }
}

// Traitement du formulaire de nouveau mot de passe
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset'])) {
    // Vérification CSRF
    if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
        $errors[] = "Erreur de sécurité, veuillez réessayer";
    } else {
        $token = $_POST['token'] ?? '';
        $password = $_POST['password'] ?? '';
        $password2 = $_POST['password2'] ?? '';
        
        // Vérifier que le token est toujours valide
        $stmt = $pdo->prepare("
            SELECT pr.*, u.id as user_id 
            FROM password_resets pr
            JOIN utilisateurs u ON pr.user_id = u.id
            WHERE pr.token = ? AND pr.expires_at > NOW() AND pr.used = 0
        ");
        $stmt->execute([$token]);
        $reset = $stmt->fetch();
        
        if (!$reset) {
            $errors[] = "Le lien de réinitialisation est invalide ou a expiré.";
        } else {
            // Validation du mot de passe
            if ($password !== $password2) {
                $errors[] = "Les mots de passe ne correspondent pas";
            } else {
                $pwdErrors = validate_password_strength($password);
                if (!empty($pwdErrors)) {
                    $errors = array_merge($errors, $pwdErrors);
                }
                
                if (empty($errors)) {
                    try {
                        // Hasher le nouveau mot de passe
                        $hash = hash_password($password);
                        
                        // Mettre à jour le mot de passe
                        $updateStmt = $pdo->prepare("UPDATE utilisateurs SET motdepasse = ? WHERE id = ?");
                        $updateStmt->execute([$hash, $reset['user_id']]);
                        
                        // Marquer le token comme utilisé
                        $useStmt = $pdo->prepare("UPDATE password_resets SET used = 1 WHERE id = ?");
                        $useStmt->execute([$reset['id']]);
                        
                        // Log de l'activité
                        log_activity($pdo, $reset['user_id'], 'password_reset_completed');
                        
                        $success = true;
                        
                    } catch (PDOException $e) {
                        error_log("Erreur réinitialisation: " . $e->getMessage());
                        $errors[] = "Erreur lors de la réinitialisation";
                    }
                }
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
    <title>WideMaze - Nouveau mot de passe</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: '#f59e0b',
                        secondary: '#1e293b',
                    }
                }
            }
        }
    </script>
    <style>
        body { font-family: 'Inter', sans-serif; }
        .mesh-bg {
            background-color: #f3f4f6;
            background-image: radial-gradient(at 40% 20%, hsla(28,100%,74%,1) 0px, transparent 50%),
                              radial-gradient(at 80% 0%, hsla(189,100%,56%,1) 0px, transparent 50%),
                              radial-gradient(at 0% 50%, hsla(340,100%,76%,1) 0px, transparent 50%);
        }
        .glass {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        .password-strength { height: 4px; border-radius: 2px; transition: all 0.3s; }
    </style>
</head>
<body class="mesh-bg min-h-screen flex items-center justify-center p-4">

    <div class="container mx-auto max-w-md relative z-10">
        <!-- Logo -->
        <div class="text-center mb-8">
            <a href="index.php" class="inline-flex items-center gap-3 group">
                <div class="w-14 h-14 bg-gradient-to-br from-primary to-orange-600 rounded-2xl flex items-center justify-center shadow-xl group-hover:scale-110 transition-transform">
                    <i class="fas fa-network-wired text-white text-2xl"></i>
                </div>
                <span class="text-4xl font-bold text-secondary">WideMaze</span>
            </a>
        </div>

        <!-- Main Card -->
        <div class="glass rounded-3xl shadow-2xl p-8">
            
            <?php if ($success): ?>
                <!-- Success State -->
                <div class="text-center">
                    <div class="w-20 h-20 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-check-circle text-green-500 text-3xl"></i>
                    </div>
                    <h1 class="text-2xl font-bold text-gray-800 mb-2">Mot de passe modifié !</h1>
                    <p class="text-gray-600 mb-6">
                        Votre mot de passe a été réinitialisé avec succès.
                    </p>
                    <a href="connexion.php" 
                        class="inline-block bg-gradient-to-r from-primary to-orange-600 text-white font-semibold px-8 py-3 rounded-xl shadow-lg hover:shadow-xl transition-all">
                        Se connecter
                    </a>
                </div>
            <?php elseif ($showForm): ?>
                <!-- Reset Form -->
                <div class="text-center mb-6">
                    <div class="w-20 h-20 bg-orange-100 rounded-full flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-lock-open text-primary text-3xl"></i>
                    </div>
                    <h1 class="text-2xl font-bold text-gray-800">Nouveau mot de passe</h1>
                    <p class="text-gray-600 text-sm mt-2">
                        Bonjour <span class="font-semibold"><?= htmlspecialchars($userName) ?></span>,<br>
                        choisissez votre nouveau mot de passe
                    </p>
                </div>

                <!-- Error Messages -->
                <?php if (!empty($errors)): ?>
                    <div class="bg-red-50 border-l-4 border-red-500 p-4 rounded-r-lg mb-6">
                        <ul class="text-red-600 text-sm list-disc list-inside">
                            <?php foreach ($errors as $error): ?>
                                <li><?= htmlspecialchars($error) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <form method="POST" action="" class="space-y-6">
                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                    <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Nouveau mot de passe</label>
                        <div class="relative">
                            <i class="fas fa-lock absolute left-4 top-1/2 -translate-y-1/2 text-gray-400"></i>
                            <input type="password" name="password" id="password" required
                                class="w-full pl-12 pr-12 py-3 rounded-xl border-2 border-gray-200 focus:border-primary focus:ring-4 focus:ring-orange-100 outline-none transition-all"
                                placeholder="••••••••">
                            <button type="button" onclick="togglePassword('password', 'eye1')" class="absolute right-4 top-1/2 -translate-y-1/2 text-gray-400 hover:text-primary">
                                <i class="fas fa-eye" id="eye1"></i>
                            </button>
                        </div>
                        <div class="mt-2">
                            <div class="h-1 bg-gray-200 rounded-full overflow-hidden">
                                <div id="passwordStrength" class="password-strength w-0 bg-red-500"></div>
                            </div>
                            <p id="passwordFeedback" class="text-xs text-gray-500 mt-1">Minimum 8 caractères</p>
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Confirmer le mot de passe</label>
                        <div class="relative">
                            <i class="fas fa-lock absolute left-4 top-1/2 -translate-y-1/2 text-gray-400"></i>
                            <input type="password" name="password2" id="password2" required
                                class="w-full pl-12 pr-12 py-3 rounded-xl border-2 border-gray-200 focus:border-primary focus:ring-4 focus:ring-orange-100 outline-none transition-all"
                                placeholder="••••••••">
                            <span id="matchStatus" class="absolute right-4 top-1/2 -translate-y-1/2"></span>
                        </div>
                    </div>

                    <button type="submit" name="reset"
                        class="w-full bg-gradient-to-r from-primary to-orange-600 text-white font-semibold py-3 rounded-xl shadow-lg hover:shadow-xl transform hover:-translate-y-0.5 transition-all">
                        Réinitialiser le mot de passe
                    </button>
                </form>

                <div class="mt-6 text-center">
                    <a href="connexion.php" class="text-gray-500 hover:text-primary transition-colors text-sm">
                        Retour à la connexion
                    </a>
                </div>

            <?php else: ?>
                <!-- Error State (token invalide) -->
                <div class="text-center">
                    <div class="w-20 h-20 bg-red-100 rounded-full flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-exclamation-triangle text-red-500 text-3xl"></i>
                    </div>
                    <h1 class="text-2xl font-bold text-gray-800 mb-2">Lien invalide</h1>
                    <?php if (!empty($errors)): ?>
                        <p class="text-red-600 mb-6"><?= htmlspecialchars($errors[0]) ?></p>
                    <?php endif; ?>
                    <div class="space-y-3">
                        <a href="passwordrecover.php" 
                            class="inline-block bg-gradient-to-r from-primary to-orange-600 text-white font-semibold px-8 py-3 rounded-xl shadow-lg hover:shadow-xl transition-all">
                            Renvoyer un lien
                        </a>
                        <br>
                        <a href="connexion.php" class="text-gray-500 hover:text-primary transition-colors text-sm">
                            Retour à la connexion
                        </a>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
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

        // Password strength checker
        document.getElementById('password')?.addEventListener('input', function() {
            const pwd = this.value;
            let strength = 0;
            if (pwd.length >= 8) strength++;
            if (/[A-Z]/.test(pwd)) strength++;
            if (/[a-z]/.test(pwd)) strength++;
            if (/[0-9]/.test(pwd)) strength++;
            if (/[^A-Za-z0-9]/.test(pwd)) strength++;

            const strengthBar = document.getElementById('passwordStrength');
            const feedback = document.getElementById('passwordFeedback');
            
            const colors = ['bg-red-500', 'bg-orange-500', 'bg-yellow-500', 'bg-green-500', 'bg-green-600'];
            const widths = ['20%', '40%', '60%', '80%', '100%'];
            const texts = ['Très faible', 'Faible', 'Moyen', 'Fort', 'Très fort'];
            
            if (strengthBar) {
                strengthBar.className = `password-strength ${colors[strength-1] || 'bg-red-500'}`;
                strengthBar.style.width = widths[strength-1] || '0%';
            }
            if (feedback) {
                feedback.textContent = texts[strength-1] || 'Minimum 8 caractères';
                feedback.className = `text-xs mt-1 ${strength >= 3 ? 'text-green-600' : 'text-gray-500'}`;
            }
        });

        // Password match checker
        document.getElementById('password2')?.addEventListener('input', function() {
            const pwd1 = document.getElementById('password').value;
            const icon = document.getElementById('matchStatus');
            if (this.value === pwd1 && this.value !== '') {
                icon.innerHTML = '<i class="fas fa-check-circle text-green-500"></i>';
            } else if (this.value !== '') {
                icon.innerHTML = '<i class="fas fa-times-circle text-red-500"></i>';
            } else {
                icon.innerHTML = '';
            }
        });
    </script>
</body>
</html>