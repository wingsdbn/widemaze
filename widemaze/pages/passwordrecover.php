<?php
/**
 * WideMaze - Récupération de mot de passe
 * Version 4.0 - Sécurisée avec email, reCAPTCHA, rate limiting
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
$email = '';
$rateLimited = false;

// Vérification du rate limiting
function checkRecoveryRateLimit($pdo, $ip) {
    try {
        // Utiliser la nouvelle API
        $ch = curl_init(SITE_URL . '/api/password_reset_attempts.php?action=check&ip=' . urlencode($ip));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 200) {
            $data = json_decode($response, true);
            if ($data['success']) {
                $rateLimit = $data['rate_limit'];
                return [
                    'blocked' => $rateLimit['blocked'],
                    'attempts' => $rateLimit['attempt_count'],
                    'wait' => $rateLimit['wait_minutes'] * 60
                ];
            }
        }
    } catch (Exception $e) {
        error_log("Error calling rate limit API: " . $e->getMessage());
    }
    
    // Fallback: vérification directe en base si l'API n'est pas disponible
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM password_reset_attempts 
        WHERE ip_address = ? AND attempted_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
    ");
    $stmt->execute([$ip]);
    $attempts = $stmt->fetchColumn();
    
    if ($attempts >= 5) {
        return ['blocked' => true, 'attempts' => $attempts, 'wait' => 60];
    }
    
    return ['blocked' => false, 'attempts' => $attempts, 'wait' => 0];
}

function logRecoveryAttempt($pdo, $ip, $email, $success) {
    try {
        // Utiliser la nouvelle API
        $ch = curl_init(SITE_URL . '/api/password_reset_attempts.php');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'action' => 'log',
            'email' => $email,
            'success' => $success ? 1 : 0
        ]));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_exec($ch);
        curl_close($ch);
    } catch (Exception $e) {
        // Fallback: insertion directe
        $stmt = $pdo->prepare("
            INSERT INTO password_reset_attempts (ip_address, email, success, attempted_at) 
            VALUES (?, ?, ?, NOW())
        ");
        $stmt->execute([$ip, $email, $success ? 1 : 0]);
    }
}
// Configuration email
$smtpConfig = [
    'host' => 'smtp.gmail.com',
    'port' => 587,
    'user' => 'noreply@widemaze.com',
    'pass' => '', // À configurer
    'from' => 'noreply@widemaze.com',
    'from_name' => 'WideMaze'
];

// Fonction d'envoi d'email avec template
function sendResetEmail($to, $name, $resetLink) {
    global $smtpConfig;
    
    $subject = "🔐 Réinitialisation de votre mot de passe - WideMaze";
    
    // Template HTML moderne
    $htmlMessage = '
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Réinitialisation de mot de passe</title>
        <style>
            @import url("https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap");
            body { font-family: "Inter", sans-serif; margin: 0; padding: 0; background-color: #f4f4f7; }
            .container { max-width: 600px; margin: 0 auto; background: #ffffff; border-radius: 24px; overflow: hidden; box-shadow: 0 20px 35px -10px rgba(0,0,0,0.1); }
            .header { background: linear-gradient(135deg, #f59e0b 0%, #ea580c 100%); padding: 40px 30px; text-align: center; }
            .logo { width: 70px; height: 70px; background: white; border-radius: 20px; display: inline-flex; align-items: center; justify-content: center; margin-bottom: 20px; }
            .logo svg { width: 40px; height: 40px; }
            .content { padding: 40px 30px; }
            .button { display: inline-block; background: linear-gradient(135deg, #f59e0b 0%, #ea580c 100%); color: white; text-decoration: none; padding: 14px 35px; border-radius: 50px; font-weight: 600; margin: 20px 0; box-shadow: 0 4px 15px rgba(245, 158, 11, 0.3); transition: all 0.3s; }
            .button:hover { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(245, 158, 11, 0.4); }
            .footer { background: #f8fafc; padding: 20px; text-align: center; font-size: 12px; color: #64748b; border-top: 1px solid #e2e8f0; }
            .warning-box { background: #fffbeb; border-left: 4px solid #f59e0b; padding: 15px; border-radius: 12px; margin: 20px 0; }
            @media (max-width: 600px) { .content { padding: 25px 20px; } .button { display: block; text-align: center; } }
        </style>
    </head>
    <body style="margin: 0; padding: 20px; background-color: #f4f4f7;">
        <div class="container">
            <div class="header">
                <div class="logo">
                    <svg width="40" height="40" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M4 6H20M4 12H20M4 18H20" stroke="#f59e0b" stroke-width="2" stroke-linecap="round"/>
                        <path d="M12 3L12 21" stroke="#f59e0b" stroke-width="2" stroke-linecap="round"/>
                    </svg>
                </div>
                <h1 style="color: white; margin: 0; font-size: 28px;">WideMaze</h1>
                <p style="color: rgba(255,255,255,0.9); margin: 10px 0 0;">Réseau social académique</p>
            </div>
            <div class="content">
                <h2 style="color: #1e293b; margin-top: 0;">Bonjour ' . htmlspecialchars($name) . ' ! 👋</h2>
                <p style="color: #475569; line-height: 1.6;">Nous avons reçu une demande de réinitialisation de mot de passe pour votre compte WideMaze.</p>
                
                <div class="warning-box">
                    <p style="margin: 0; color: #92400e; font-size: 14px;">
                        <strong>⚠️ Attention :</strong> Ce lien expirera dans <strong>1 heure</strong>.
                        Si vous n\'êtes pas à l\'origine de cette demande, ignorez simplement cet email.
                    </p>
                </div>
                
                <div style="text-align: center;">
                    <a href="' . $resetLink . '" class="button">
                        🔐 Réinitialiser mon mot de passe
                    </a>
                </div>
                
                <p style="color: #64748b; font-size: 14px; line-height: 1.5; margin-top: 25px;">
                    Ou copiez ce lien dans votre navigateur :<br>
                    <span style="color: #f59e0b; word-break: break-all;">' . $resetLink . '</span>
                </p>
                
                <hr style="border: none; border-top: 1px solid #e2e8f0; margin: 25px 0;">
                
                <p style="color: #94a3b8; font-size: 13px; margin: 0;">
                    Cet email a été envoyé à <strong>' . htmlspecialchars($to) . '</strong><br>
                    Si vous avez des questions, contactez-nous à support@widemaze.com
                </p>
            </div>
            <div class="footer">
                <p>&copy; ' . date('Y') . ' WideMaze. Tous droits réservés.<br>Le réseau social académique mondial</p>
            </div>
        </div>
    </body>
    </html>';
    
    // Version texte pour les clients email qui n'affichent pas le HTML
    $textMessage = "
Bonjour $name,

Nous avons reçu une demande de réinitialisation de mot de passe pour votre compte WideMaze.

Pour réinitialiser votre mot de passe, cliquez sur le lien suivant :
$resetLink

Ce lien expirera dans 1 heure.

Si vous n'êtes pas à l'origine de cette demande, ignorez simplement cet email.

---
WideMaze - Le réseau social académique
";
    
    // En-têtes email
    $headers = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "From: {$smtpConfig['from_name']} <{$smtpConfig['from']}>\r\n";
    $headers .= "Reply-To: support@widemaze.com\r\n";
    $headers .= "X-Mailer: PHP/" . phpversion();
    
    return mail($to, $subject, $htmlMessage, $headers);
}

// Traitement du formulaire
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['recover'])) {
    // Vérification CSRF
    if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
        $errors[] = "Erreur de sécurité, veuillez réessayer";
    } else {
        // Rate limiting
        $ip = $_SERVER['REMOTE_ADDR'];
        $rateLimit = checkRecoveryRateLimit($pdo, $ip);
        if ($rateLimit['blocked']) {
            $rateLimited = true;
            $errors[] = "Trop de tentatives. Veuillez réessayer dans " . ceil($rateLimit['wait']) . " secondes.";
        } else {
            $email = filter_var(trim($_POST['email'] ?? ''), FILTER_SANITIZE_EMAIL);
            
            // Validation email
            if (empty($email)) {
                $errors[] = "Veuillez saisir votre adresse email";
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = "Format d'email invalide";
            } elseif (strlen($email) > 100) {
                $errors[] = "L'email est trop long";
            } else {
                try {
                    // Vérifier si l'email existe et est actif
                    $stmt = $pdo->prepare("
                        SELECT id, prenom, nom, surnom, email, is_active 
                        FROM utilisateurs 
                        WHERE email = ? AND is_active = 1
                    ");
                    $stmt->execute([$email]);
                    $user = $stmt->fetch();
                    
                    if ($user) {
                        // Générer un token unique et sécurisé
                        $token = bin2hex(random_bytes(32));
                        $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));
                        
                        // Supprimer les anciens tokens pour cet utilisateur
                        $cleanStmt = $pdo->prepare("DELETE FROM password_resets WHERE user_id = ?");
                        $cleanStmt->execute([$user['id']]);
                        
                        // Insérer le nouveau token
                        $insertStmt = $pdo->prepare("
                            INSERT INTO password_resets (user_id, token, expires_at, created_at) 
                            VALUES (?, ?, ?, NOW())
                        ");
                        $insertStmt->execute([$user['id'], $token, $expires]);
                        
                        // Construire le lien de réinitialisation
                        $resetLink = SITE_URL . "/pages/reset_password.php?token=" . $token;
                        
                        // Envoyer l'email
                        $name = $user['prenom'] . ' ' . $user['nom'];
                        $mailSent = sendResetEmail($email, $name, $resetLink);
                        
                        // Log de la tentative
                        logRecoveryAttempt($pdo, $ip, $email, $mailSent);
                        
                        if ($mailSent) {
                            log_activity($pdo, $user['id'], 'password_recovery_requested', ['email' => $email]);
                            $success = true;
                        } else {
                            error_log("Échec d'envoi d'email de récupération pour: $email");
                            $errors[] = "Erreur lors de l'envoi de l'email. Veuillez réessayer plus tard.";
                        }
                    } else {
                        // Compte non trouvé - ne pas révéler l'existence
                        logRecoveryAttempt($pdo, $ip, $email, false);
                        $success = true; // Même message pour la sécurité
                    }
                } catch (PDOException $e) {
                    error_log("Erreur récupération mot de passe: " . $e->getMessage());
                    $errors[] = "Erreur système, veuillez réessayer";
                }
            }
        }
    }
}

// Statistiques pour la page (optionnel)
$stats = [];
try {
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM utilisateurs WHERE is_active = 1");
    $stats['total_users'] = $stmt->fetchColumn();
} catch (PDOException $e) {
    $stats['total_users'] = '0';
}

$csrfToken = generate_csrf_token();
$page_title = 'Récupération de mot de passe';
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
        @keyframes float {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-10px); }
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
        .floating-shape {
            position: fixed;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
            pointer-events: none;
            animation: float 6s ease-in-out infinite;
        }
    </style>
</head>
<body class="min-h-screen flex items-center justify-center p-4 relative ">
    
    <!-- Background floating shapes -->
    <div class="floating-shape w-64 h-64 top-20 -left-32"></div>
    <div class="floating-shape w-96 h-96 bottom-20 -right-48" style="animation-delay: 2s;"></div>
    <div class="floating-shape w-48 h-48 top-1/2 left-1/2" style="animation-delay: 4s;"></div>
    
    <div class="container mx-auto max-w-md relative py-4 z-10">
        <!-- Logo -->
        <div class="text-center mb-8">
            <a href="../index.php" class="inline-flex items-center gap-3 group animate-bounce">
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
                        <i class="fas fa-envelope-open-text text-green-500 text-3xl"></i>
                    </div>
                    <h2 class="text-2xl font-bold text-gray-800 mb-3">Email envoyé !</h2>
                    <p class="text-gray-600 mb-6">
                        Si un compte existe avec l'adresse <strong class="text-orange-600"><?= htmlspecialchars($email) ?></strong>,<br>
                        vous recevrez un email contenant les instructions pour réinitialiser votre mot de passe.
                    </p>
                    <div class="bg-blue-50 rounded-xl p-4 mb-6">
                        <p class="text-sm text-blue-700 flex items-center gap-2 justify-center">
                            <i class="fas fa-clock"></i>
                            Le lien expirera dans <strong>1 heure</strong>
                        </p>
                    </div>
                    <a href="connexion.php" class="inline-flex items-center gap-2 text-orange-500 hover:text-orange-600 font-medium transition-colors">
                        <i class="fas fa-arrow-left"></i> Retour à la connexion
                    </a>
                </div>
                
            <?php else: ?>
                <!-- Form State -->
                <div class="text-center mb-6">
                    <div class="w-20 h-20 bg-orange-100 rounded-full flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-key text-orange-500 text-3xl"></i>
                    </div>
                    <h2 class="text-2xl font-bold text-gray-800">Mot de passe oublié ?</h2>
                    <p class="text-gray-500 text-sm mt-2">
                        Saisissez votre adresse email pour recevoir un lien de réinitialisation
                    </p>
                </div>
                
                <!-- Error Messages -->
                <?php if (!empty($errors)): ?>
                    <div class="bg-red-50 border-l-4 border-red-500 p-4 rounded-r-lg mb-6">
                        <div class="flex items-start gap-3">
                            <i class="fas fa-exclamation-circle text-red-500 mt-0.5"></i>
                            <div>
                                <p class="font-semibold text-red-800 mb-1">Erreur</p>
                                <ul class="text-red-700 text-sm space-y-1">
                                    <?php foreach ($errors as $error): ?>
                                        <li>• <?= htmlspecialchars($error) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
                
                <!-- Form -->
                <form method="POST" action="" class="space-y-6" id="recoverForm">
                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                    
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Adresse email</label>
                        <div class="relative">
                            <i class="fas fa-envelope absolute left-4 top-1/2 -translate-y-1/2 text-gray-400"></i>
                            <input type="email" name="email" id="email" required
                                   class="input-field w-full pl-12 pr-4 py-3 rounded-xl border-2 border-gray-200 focus:border-orange-500 focus:ring-4 focus:ring-orange-100 outline-none transition-all"
                                   placeholder="votre@email.com"
                                   value="<?= htmlspecialchars($email) ?>">
                        </div>
                        <p class="text-red-500 text-xs mt-1 hidden" id="emailError">Veuillez entrer une adresse email valide</p>
                    </div>
                    
                    <!-- Info sécurité -->
                    <div class="bg-gray-50 rounded-xl p-4">
                        <div class="flex items-start gap-2">
                            <i class="fas fa-shield-alt text-green-500 mt-0.5"></i>
                            <p class="text-xs text-gray-500">
                                Un lien sécurisé vous sera envoyé par email. Ce lien expirera dans 1 heure pour votre sécurité.
                            </p>
                        </div>
                    </div>
                    
                    <button type="submit" name="recover" id="submitBtn"
                            class="btn-primary w-full text-white font-semibold py-3.5 rounded-xl flex items-center justify-center gap-2 shadow-lg hover:shadow-xl transition-all disabled:opacity-50 disabled:cursor-not-allowed">
                        <span id="btnText">Envoyer le lien de réinitialisation</span>
                        <i class="fas fa-paper-plane" id="btnIcon"></i>
                        <div class="loading-spinner hidden w-5 h-5 border-3 border-white border-t-transparent rounded-full animate-spin" id="loadingSpinner"></div>
                    </button>
                </form>
                
                <div class="mt-8 text-center">
                    <a href="connexion.php" class="text-gray-500 hover:text-orange-500 transition-colors inline-flex items-center gap-2 text-sm">
                        <i class="fas fa-arrow-left"></i> Retour à la connexion
                    </a>
                </div>
                
                <div class="mt-6 pt-6 border-t border-gray-200 text-center">
                    <p class="text-xs text-gray-400">
                        Vous n'avez pas de compte ? 
                        <a href="inscription.php" class="text-orange-500 hover:underline font-medium">Inscrivez-vous</a>
                    </p>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Footer -->
        <p class="text-center text-white/70 text-xs mt-8">
            &copy; <?= date('Y') ?> WideMaze. Tous droits réservés.<br>
            <span class="text-white/50"><?= number_format($stats['total_users']) ?>+ utilisateurs nous font confiance</span>
        </p>
    </div>
    
    <script>
        // Form validation et loading state
        document.getElementById('recoverForm')?.addEventListener('submit', function(e) {
            const email = document.getElementById('email');
            const emailError = document.getElementById('emailError');
            const submitBtn = document.getElementById('submitBtn');
            const btnText = document.getElementById('btnText');
            const btnIcon = document.getElementById('btnIcon');
            const loadingSpinner = document.getElementById('loadingSpinner');
            
            let isValid = true;
            
            // Email validation
            const emailRegex = /^[^\s@]+@([^\s@]+\.)+[^\s@]+$/;
            if (!emailRegex.test(email.value)) {
                emailError.classList.remove('hidden');
                email.classList.add('border-red-500');
                isValid = false;
            } else {
                emailError.classList.add('hidden');
                email.classList.remove('border-red-500');
            }
            
            if (!isValid) {
                e.preventDefault();
                return;
            }
            
            // Show loading state
            submitBtn.disabled = true;
            btnText.textContent = 'Envoi en cours...';
            btnIcon.classList.add('hidden');
            loadingSpinner.classList.remove('hidden');
        });
        
        // Real-time validation
        document.getElementById('email')?.addEventListener('input', function() {
            this.classList.remove('border-red-500');
            document.getElementById('emailError')?.classList.add('hidden');
        });
        
        document.getElementById('email')?.addEventListener('blur', function() {
            const emailRegex = /^[^\s@]+@([^\s@]+\.)+[^\s@]+$/;
            if (this.value && !emailRegex.test(this.value)) {
                this.classList.add('border-red-500');
                document.getElementById('emailError')?.classList.remove('hidden');
            }
        });
        
        // Prevent form resubmission on page refresh
        if (window.history.replaceState) {
            window.history.replaceState(null, null, window.location.href);
        }
    </script>
</body>
</html>