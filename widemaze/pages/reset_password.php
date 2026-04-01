<?php
/**
 * WideMaze - Réinitialisation du mot de passe
 * Version 4.0 - Sécurisée avec validation avancée
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
$showForm = false;
$token = $_GET['token'] ?? '';
$userName = '';
$userEmail = '';

// Vérifier si la table password_resets existe
try {
    $tableCheck = $pdo->query("SHOW TABLES LIKE 'password_resets'");
    if ($tableCheck->rowCount() == 0) {
        $errors[] = "Système de réinitialisation temporairement indisponible";
    }
} catch (PDOException $e) {
    error_log("Table check error: " . $e->getMessage());
    $errors[] = "Erreur système";
}

// Vérifier si le token est valide
if (!empty($token) && empty($errors)) {
    try {
        $stmt = $pdo->prepare("
            SELECT pr.*, u.email, u.prenom, u.nom, u.id as user_id, u.is_active
            FROM password_resets pr
            JOIN utilisateurs u ON pr.user_id = u.id
            WHERE pr.token = ? AND pr.expires_at > NOW() AND pr.used = 0 AND u.is_active = 1
        ");
        $stmt->execute([$token]);
        $reset = $stmt->fetch();
        
        if ($reset) {
            $showForm = true;
            $userEmail = $reset['email'];
            $userName = ($reset['prenom'] ?? '') . ' ' . ($reset['nom'] ?? '');
            $userId = $reset['user_id'];
        } else {
            // Vérifier si le token a expiré
            $checkExpired = $pdo->prepare("
                SELECT expires_at FROM password_resets WHERE token = ? AND used = 0
            ");
            $checkExpired->execute([$token]);
            $expired = $checkExpired->fetch();
            
            if ($expired) {
                $errors[] = "Le lien de réinitialisation a expiré. Veuillez en demander un nouveau.";
            } else {
                $errors[] = "Le lien de réinitialisation est invalide ou a déjà été utilisé.";
            }
        }
    } catch (PDOException $e) {
        error_log("Erreur vérification token: " . $e->getMessage());
        $errors[] = "Erreur système, veuillez réessayer";
    }
}

// Traitement du formulaire
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset'])) {
    // Vérification CSRF
    if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
        $errors[] = "Erreur de sécurité, veuillez réessayer";
    } else {
        $token = $_POST['token'] ?? '';
        $password = $_POST['password'] ?? '';
        $password2 = $_POST['password2'] ?? '';
        
        // Validation du token
        try {
            $stmt = $pdo->prepare("
                SELECT pr.*, u.id as user_id, u.email, u.prenom, u.nom
                FROM password_resets pr
                JOIN utilisateurs u ON pr.user_id = u.id
                WHERE pr.token = ? AND pr.expires_at > NOW() AND pr.used = 0 AND u.is_active = 1
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
                            $useStmt = $pdo->prepare("UPDATE password_resets SET used = 1, used_at = NOW() WHERE id = ?");
                            $useStmt->execute([$reset['id']]);
                            
                            // Supprimer tous les autres tokens pour cet utilisateur
                            $cleanStmt = $pdo->prepare("DELETE FROM password_resets WHERE user_id = ? AND id != ?");
                            $cleanStmt->execute([$reset['user_id'], $reset['id']]);
                            
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
        } catch (PDOException $e) {
            error_log("Erreur validation token: " . $e->getMessage());
            $errors[] = "Erreur système, veuillez réessayer";
        }
    }
}

$csrfToken = generate_csrf_token();
$page_title = 'Nouveau mot de passe';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title><?= $page_title ?> - WideMaze</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { font-family: 'Inter', sans-serif; }
        body { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; }
        
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .glass-card {
            background: rgba(255, 255, 255, 0.98);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.3);
        }
        .input-field {
            transition: all 0.3s ease;
        }
        .input-field:focus {
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
        .password-strength {
            transition: width 0.3s;
        }
    </style>
</head>
<body class="min-h-screen flex items-center justify-center p-4">
    
    <div class="container mx-auto max-w-md relative z-10">
        <!-- Logo -->
        <div class="text-center mb-8">
            <a href="../index.php" class="inline-flex items-center gap-3 group">
                <div class="w-16 h-16 bg-white rounded-2xl flex items-center justify-center shadow-xl group-hover:scale-110 transition-transform">
                    <i class="fas fa-network-wired text-3xl text-orange-500"></i>
                </div>
                <span class="text-3xl font-bold text-white">WideMaze</span>
            </a>
        </div>
        
        <!-- Main Card -->
        <div class="glass-card rounded-3xl shadow-2xl p-8 animate-fadeInUp">
            
            <?php if ($success): ?>
                <!-- Success State -->
                <div class="text-center">
                    <div class="w-20 h-20 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-6">
                        <i class="fas fa-check-circle text-green-500 text-4xl"></i>
                    </div>
                    <h2 class="text-2xl font-bold text-gray-800 mb-3">Mot de passe modifié !</h2>
                    <p class="text-gray-600 mb-6">
                        Votre mot de passe a été réinitialisé avec succès.<br>
                        Vous pouvez maintenant vous connecter avec votre nouveau mot de passe.
                    </p>
                    <a href="connexion.php" class="btn-primary inline-block text-white font-semibold px-8 py-3 rounded-xl shadow-lg hover:shadow-xl transition-all">
                        Se connecter
                    </a>
                </div>
                
            <?php elseif ($showForm): ?>
                <!-- Reset Form -->
                <div class="text-center mb-6">
                    <div class="w-20 h-20 bg-orange-100 rounded-full flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-lock-open text-orange-500 text-3xl"></i>
                    </div>
                    <h2 class="text-2xl font-bold text-gray-800">Nouveau mot de passe</h2>
                    <p class="text-gray-500 text-sm mt-2">
                        Bonjour <strong class="text-orange-600"><?= htmlspecialchars($userName) ?></strong>,<br>
                        choisissez votre nouveau mot de passe
                    </p>
                </div>
                
                <!-- Error Messages -->
                <?php if (!empty($errors)): ?>
                    <div class="bg-red-50 border-l-4 border-red-500 p-4 rounded-r-lg mb-6">
                        <div class="flex items-start gap-3">
                            <i class="fas fa-exclamation-circle text-red-500 mt-0.5"></i>
                            <div>
                                <ul class="text-red-700 text-sm space-y-1">
                                    <?php foreach ($errors as $error): ?>
                                        <li>• <?= htmlspecialchars($error) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
                
                <form method="POST" action="" class="space-y-6" id="resetForm">
                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                    <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
                    
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Nouveau mot de passe</label>
                        <div class="relative">
                            <i class="fas fa-lock absolute left-4 top-1/2 -translate-y-1/2 text-gray-400"></i>
                            <input type="password" name="password" id="password" required
                                   class="input-field w-full pl-12 pr-12 py-3 rounded-xl border-2 border-gray-200 focus:border-orange-500 focus:ring-4 focus:ring-orange-100 outline-none transition-all"
                                   placeholder="••••••••">
                            <button type="button" onclick="togglePassword('password', 'eye1')" class="absolute right-4 top-1/2 -translate-y-1/2 text-gray-400 hover:text-orange-500 transition-colors">
                                <i class="fas fa-eye" id="eye1"></i>
                            </button>
                        </div>
                        
                        <!-- Password strength meter -->
                        <div class="mt-3 space-y-2">
                            <div class="flex gap-1 h-1">
                                <div id="strength-bar-1" class="flex-1 bg-gray-200 rounded-full transition-all"></div>
                                <div id="strength-bar-2" class="flex-1 bg-gray-200 rounded-full transition-all"></div>
                                <div id="strength-bar-3" class="flex-1 bg-gray-200 rounded-full transition-all"></div>
                                <div id="strength-bar-4" class="flex-1 bg-gray-200 rounded-full transition-all"></div>
                            </div>
                            <div id="password-strength-text" class="text-xs text-gray-500 flex items-center gap-2">
                                <i class="fas fa-info-circle"></i>
                                <span>Minimum 8 caractères, 1 majuscule, 1 chiffre, 1 symbole</span>
                            </div>
                        </div>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Confirmer le mot de passe</label>
                        <div class="relative">
                            <i class="fas fa-lock absolute left-4 top-1/2 -translate-y-1/2 text-gray-400"></i>
                            <input type="password" name="password2" id="password2" required
                                   class="input-field w-full pl-12 pr-12 py-3 rounded-xl border-2 border-gray-200 focus:border-orange-500 focus:ring-4 focus:ring-orange-100 outline-none transition-all"
                                   placeholder="••••••••">
                            <span id="match-status" class="absolute right-4 top-1/2 -translate-y-1/2"></span>
                        </div>
                    </div>
                    
                    <div class="bg-blue-50 rounded-xl p-4">
                        <div class="flex items-start gap-2">
                            <i class="fas fa-shield-alt text-blue-500 mt-0.5"></i>
                            <p class="text-xs text-blue-700">
                                Pour votre sécurité, utilisez un mot de passe unique que vous n'utilisez pas ailleurs.
                            </p>
                        </div>
                    </div>
                    
                    <button type="submit" name="reset" id="submitBtn"
                            class="btn-primary w-full text-white font-semibold py-3.5 rounded-xl flex items-center justify-center gap-2 shadow-lg hover:shadow-xl transition-all disabled:opacity-50 disabled:cursor-not-allowed">
                        <span id="btnText">Réinitialiser le mot de passe</span>
                        <i class="fas fa-check-circle" id="btnIcon"></i>
                        <div class="loading-spinner hidden w-5 h-5 border-3 border-white border-t-transparent rounded-full animate-spin" id="loadingSpinner"></div>
                    </button>
                </form>
                
                <div class="mt-6 text-center">
                    <a href="passwordrecover.php" class="text-gray-500 hover:text-orange-500 transition-colors text-sm inline-flex items-center gap-1">
                        <i class="fas fa-arrow-left"></i> Demander un nouveau lien
                    </a>
                </div>
                
            <?php else: ?>
                <!-- Error State -->
                <div class="text-center">
                    <div class="w-20 h-20 bg-red-100 rounded-full flex items-center justify-center mx-auto mb-6">
                        <i class="fas fa-exclamation-triangle text-red-500 text-3xl"></i>
                    </div>
                    <h2 class="text-2xl font-bold text-gray-800 mb-3">Lien invalide</h2>
                    <?php if (!empty($errors)): ?>
                        <div class="bg-red-50 border border-red-200 rounded-xl p-4 mb-6">
                            <?php foreach ($errors as $error): ?>
                                <p class="text-red-600 text-sm"><?= htmlspecialchars($error) ?></p>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <div class="space-y-3">
                        <a href="passwordrecover.php" class="btn-primary inline-block text-white font-semibold px-6 py-3 rounded-xl shadow-lg hover:shadow-xl transition-all">
                            Demander un nouveau lien
                        </a>
                        <br>
                        <a href="connexion.php" class="text-gray-500 hover:text-orange-500 transition-colors text-sm">
                            Retour à la connexion
                        </a>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Footer -->
        <p class="text-center text-white/70 text-xs mt-8">
            &copy; <?= date('Y') ?> WideMaze. Tous droits réservés.
        </p>
    </div>
    
    <script>
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
        
        // Password strength checker
        const passwordInput = document.getElementById('password');
        const strengthBars = {
            1: document.getElementById('strength-bar-1'),
            2: document.getElementById('strength-bar-2'),
            3: document.getElementById('strength-bar-3'),
            4: document.getElementById('strength-bar-4')
        };
        const strengthText = document.getElementById('password-strength-text');
        
        function checkPasswordStrength(password) {
            let strength = 0;
            const checks = {
                length: password.length >= 8,
                uppercase: /[A-Z]/.test(password),
                lowercase: /[a-z]/.test(password),
                number: /[0-9]/.test(password),
                special: /[!@#$%^&*()\-_=+{};:,<.>]/.test(password)
            };
            
            strength = Object.values(checks).filter(Boolean).length;
            
            const colors = ['bg-red-500', 'bg-orange-500', 'bg-yellow-500', 'bg-green-500', 'bg-green-600'];
            const texts = ['Très faible', 'Faible', 'Moyen', 'Fort', 'Très fort'];
            const icons = ['fa-exclamation-circle', 'fa-exclamation-triangle', 'fa-chart-line', 'fa-check-circle', 'fa-shield-alt'];
            
            // Update bars
            for (let i = 1; i <= 4; i++) {
                if (strengthBars[i]) {
                    strengthBars[i].className = `flex-1 rounded-full transition-all ${i <= strength ? colors[strength-1] : 'bg-gray-200'}`;
                }
            }
            
            // Update text
            if (strengthText) {
                const icon = icons[strength-1] || 'fa-info-circle';
                const colorClass = strength >= 3 ? 'text-green-600' : (strength >= 2 ? 'text-orange-500' : 'text-red-500');
                strengthText.innerHTML = `<i class="fas ${icon} mr-1"></i><span class="${colorClass}">${texts[strength-1] || 'Très faible'}</span>`;
                
                // Show requirements if password is weak
                if (strength < 3 && password.length > 0) {
                    let requirements = [];
                    if (!checks.length) requirements.push('8 caractères minimum');
                    if (!checks.uppercase) requirements.push('une majuscule');
                    if (!checks.number) requirements.push('un chiffre');
                    if (!checks.special) requirements.push('un caractère spécial');
                    
                    if (requirements.length > 0) {
                        strengthText.innerHTML += `<span class="text-gray-500 text-xs ml-2">(${requirements.join(', ')})</span>`;
                    }
                }
            }
            
            return strength;
        }
        
        if (passwordInput) {
            passwordInput.addEventListener('input', function() {
                checkPasswordStrength(this.value);
                checkPasswordMatch();
            });
        }
        
        // Password match checker
        const password2Input = document.getElementById('password2');
        const matchStatus = document.getElementById('match-status');
        
        function checkPasswordMatch() {
            const pwd1 = passwordInput?.value || '';
            const pwd2 = password2Input?.value || '';
            
            if (pwd2 === '') {
                matchStatus.innerHTML = '';
                return;
            }
            
            if (pwd1 === pwd2) {
                matchStatus.innerHTML = '<i class="fas fa-check-circle text-green-500"></i>';
            } else {
                matchStatus.innerHTML = '<i class="fas fa-times-circle text-red-500"></i>';
            }
        }
        
        if (password2Input) {
            password2Input.addEventListener('input', checkPasswordMatch);
        }
        
        // Form validation and loading state
        document.getElementById('resetForm')?.addEventListener('submit', function(e) {
            const pwd1 = passwordInput?.value || '';
            const pwd2 = password2Input?.value || '';
            const strength = checkPasswordStrength(pwd1);
            const submitBtn = document.getElementById('submitBtn');
            const btnText = document.getElementById('btnText');
            const btnIcon = document.getElementById('btnIcon');
            const loadingSpinner = document.getElementById('loadingSpinner');
            
            if (pwd1 !== pwd2) {
                e.preventDefault();
                alert('Les mots de passe ne correspondent pas');
                return;
            }
            
            if (strength < 3) {
                e.preventDefault();
                alert('Veuillez choisir un mot de passe plus fort');
                return;
            }
            
            // Show loading state
            submitBtn.disabled = true;
            btnText.textContent = 'Réinitialisation en cours...';
            btnIcon.classList.add('hidden');
            loadingSpinner.classList.remove('hidden');
        });
        
        // Prevent form resubmission on page refresh
        if (window.history.replaceState) {
            window.history.replaceState(null, null, window.location.href);
        }
    </script>
</body>
</html>