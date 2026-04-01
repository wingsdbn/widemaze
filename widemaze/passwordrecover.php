<?php

// Vérifier si PHPMailer est disponible avant de l'utiliser
/*if (file_exists(__DIR__ . '/phpmailer/src/Exception.php')) 
{
    use PHPMailer\PHPMailer\PHPMailer;
    use PHPMailer\PHPMailer\SMTP;
    use PHPMailer\PHPMailer\Exception;
    
    require_once __DIR__ . '/phpmailer/src/Exception.php';
    require_once __DIR__ . '/phpmailer/src/PHPMailer.php';
    require_once __DIR__ . '/phpmailer/src/SMTP.php'; }*/

// En haut de ton fichier, avant require_once 'config.php'
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

// Inclusion des fichiers PHPMailer (chemins corrigés)
require_once __DIR__ . '/phpmailer/src/Exception.php';
require_once __DIR__ . '/phpmailer/src/PHPMailer.php';
require_once __DIR__ . '/phpmailer/src/SMTP.php';

require_once 'config.php';

// Rediriger si déjà connecté
if (isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

$errors = [];
$success = false;
$email = '';

// Configuration SMTP (à remplacer par vos identifiants)
define('SMTP_HOST', 'smtp.gmail.com'); // ou smtp.mail.me.com pour iCloud
define('SMTP_PORT', 587);
define('SMTP_USER', 'votre-email@gmail.com'); // Votre email
define('SMTP_PASS', 'votre-mot-de-passe-application'); // Mot de passe d'application
define('SMTP_FROM', 'noreply@widemaze.com');
define('SMTP_FROM_NAME', 'WideMaze');

// Fonction d'envoi d'email avec PHPMailer (corrigée)
function sendEmailWithPHPMailer($to, $subject, $message, $from = SMTP_FROM, $fromName = SMTP_FROM_NAME) {
    // Créer une instance de PHPMailer
    $mail = new PHPMailer(true);
    
    try {
        // Configuration du serveur
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS; // Corrigé
        $mail->Port       = SMTP_PORT;
        $mail->CharSet    = 'UTF-8';
        
        // Expéditeur et destinataire
        $mail->setFrom($from, $fromName);
        $mail->addAddress($to);
        $mail->addReplyTo($from, $fromName);
        
        // Contenu
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $message;
        $mail->AltBody = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $message));
        
        $mail->send();
        return true;
        
    } catch (Exception $e) {
        error_log("Erreur PHPMailer: " . $mail->ErrorInfo);
        return false;
    }
}

// Fonction d'envoi avec mail() native (fallback)
function sendEmailWithMail($to, $subject, $message, $from = SMTP_FROM, $fromName = SMTP_FROM_NAME) {
    $headers = "MIME-Version: 1.0" . "\r\n";
    $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
    $headers .= "From: $fromName <$from>" . "\r\n";
    $headers .= "Reply-To: $from" . "\r\n";
    $headers .= "X-Mailer: PHP/" . phpversion();
    
    return mail($to, $subject, $message, $headers);
}

// Traitement du formulaire de demande de réinitialisation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['recover'])) {
    // Vérification CSRF
    if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
        $errors[] = "Erreur de sécurité, veuillez réessayer";
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
                // Vérifier si l'email existe
                $stmt = $pdo->prepare("SELECT id, prenom, nom, surnom FROM utilisateurs WHERE email = ? AND is_active = 1");
                $stmt->execute([$email]);
                $user = $stmt->fetch();
                
                if ($user) {
                    // Générer un token unique
                    $token = bin2hex(random_bytes(32));
                    $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));
                    
                    // Supprimer les anciens tokens pour cet utilisateur
                    $cleanStmt = $pdo->prepare("DELETE FROM password_resets WHERE user_id = ?");
                    $cleanStmt->execute([$user['id']]);
                    
                    // Insérer le nouveau token
                    $insertStmt = $pdo->prepare("INSERT INTO password_resets (user_id, token, expires_at, created_at) VALUES (?, ?, ?, NOW())");
                    $insertStmt->execute([$user['id'], $token, $expires]);
                    
                    // Construire le lien de réinitialisation
                    $resetLink = SITE_URL . "/reset_password.php?token=" . $token;
                    
                    // Déterminer le fournisseur pour adapter l'envoi
                    $emailDomain = substr(strrchr($email, "@"), 1);
                    $isGmail = strpos($emailDomain, 'gmail.com') !== false;
                    $isICloud = strpos($emailDomain, 'icloud.com') !== false || strpos($emailDomain, 'me.com') !== false;
                    
                    // Contenu de l'email (optimisé)
                    $subject = "🔐 Récupération de mot de passe - WideMaze";
                    
                    // Version texte pour les clients email qui n'affichent pas le HTML
                    $textMessage = "Bonjour " . $user['prenom'] . " " . $user['nom'] . ",\n\n";
                    $textMessage .= "Vous avez demandé la réinitialisation de votre mot de passe sur WideMaze.\n\n";
                    $textMessage .= "Cliquez sur ce lien pour définir un nouveau mot de passe :\n";
                    $textMessage .= $resetLink . "\n\n";
                    $textMessage .= "Ce lien expirera dans 1 heure.\n\n";
                    $textMessage .= "Si vous n'êtes pas à l'origine de cette demande, ignorez simplement cet email.\n\n";
                    $textMessage .= "---\n";
                    $textMessage .= "WideMaze - Le réseau social académique";
                    
                    // Version HTML améliorée
                    $htmlMessage = "
                    <!DOCTYPE html>
                    <html>
                    <head>
                        <meta charset='UTF-8'>
                        <meta name='viewport' content='width=device-width, initial-scale=1.0'>
                        <title>Récupération de mot de passe</title>
                    </head>
                    <body style='margin:0; padding:0; font-family: -apple-system, BlinkMacSystemFont, \"Segoe UI\", Roboto, Helvetica, Arial, sans-serif; background-color: #f4f4f7;'>
                        <div style='max-width: 600px; margin: 0 auto; background-color: #ffffff; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 6px rgba(0,0,0,0.1);'>
                            <!-- Header avec dégradé -->
                            <div style='background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); padding: 30px 20px; text-align: center;'>
                                <div style='display: inline-block; width: 60px; height: 60px; background-color: white; border-radius: 16px; margin-bottom: 15px; display: flex; align-items: center; justify-content: center; margin-left: auto; margin-right: auto;'>
                                    <svg width='30' height='30' viewBox='0 0 24 24' fill='none' xmlns='http://www.w3.org/2000/svg'>
                                        <path d='M4 6H20M4 12H20M4 18H20' stroke='#f59e0b' stroke-width='2' stroke-linecap='round'/>
                                    </svg>
                                </div>
                                <h1 style='color: white; margin: 0; font-size: 28px; font-weight: 700;'>WideMaze</h1>
                            </div>
                            
                            <!-- Contenu -->
                            <div style='padding: 40px 30px;'>
                                <h2 style='color: #1e293b; font-size: 24px; margin-top: 0; margin-bottom: 20px;'>Bonjour " . htmlspecialchars($user['prenom'] . ' ' . $user['nom']) . ",</h2>
                                
                                <p style='color: #475569; font-size: 16px; line-height: 1.6; margin-bottom: 25px;'>
                                    Vous avez demandé la réinitialisation de votre mot de passe sur WideMaze.
                                </p>
                                
                                <div style='background-color: #f8fafc; border-left: 4px solid #f59e0b; padding: 20px; margin-bottom: 30px; border-radius: 8px;'>
                                    <p style='margin: 0 0 10px 0; color: #475569; font-size: 14px;'>
                                        <strong>🔐 Lien de réinitialisation :</strong>
                                    </p>
                                    <p style='margin: 0; word-break: break-all;'>
                                        <a href='" . $resetLink . "' style='color: #f59e0b; text-decoration: none; font-weight: 500;'>" . $resetLink . "</a>
                                    </p>
                                </div>
                                
                                <div style='text-align: center; margin-bottom: 30px;'>
                                    <a href='" . $resetLink . "' 
                                       style='display: inline-block; background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); 
                                              color: white; text-decoration: none; padding: 14px 35px; 
                                              border-radius: 50px; font-weight: 600; font-size: 16px;
                                              box-shadow: 0 4px 6px rgba(245, 158, 11, 0.25);'>
                                        Réinitialiser mon mot de passe
                                    </a>
                                </div>
                                
                                <div style='background-color: #fff3cd; border: 1px solid #ffeeba; padding: 15px; border-radius: 8px; margin-bottom: 25px;'>
                                    <p style='margin: 0; color: #856404; font-size: 14px;'>
                                        <strong>⚠️ Important :</strong> Ce lien expirera dans 1 heure. 
                                        Si vous n'êtes pas à l'origine de cette demande, ignorez simplement cet email.
                                    </p>
                                </div>
                                
                                <p style='color: #64748b; font-size: 14px; line-height: 1.5; border-top: 1px solid #e2e8f0; padding-top: 25px; margin-bottom: 0;'>
                                    Cet email a été envoyé à <strong>" . htmlspecialchars($email) . "</strong><br>
                                    Si vous avez des questions, contactez-nous à support@widemaze.com
                                </p>
                            </div>
                            
                            <!-- Footer -->
                            <div style='background-color: #f1f5f9; padding: 20px; text-align: center;'>
                                <p style='color: #64748b; font-size: 12px; margin: 0;'>
                                    &copy; " . date('Y') . " WideMaze. Tous droits réservés.<br>
                                    Le réseau social académique
                                </p>
                            </div>
                        </div>
                    </body>
                    </html>
                    ";
                    
                    // Tentative d'envoi avec PHPMailer
                    $mailSent = sendEmailWithPHPMailer($email, $subject, $htmlMessage);
                    
                    // Fallback vers mail() native si PHPMailer a échoué
                    if (!$mailSent) {
                        // Adapter les headers selon le fournisseur
                        $headers = "MIME-Version: 1.0\r\n";
                        $headers .= "Content-type:text/html;charset=UTF-8\r\n";
                        $headers .= "From: WideMaze <" . SMTP_FROM . ">\r\n";
                        
                        // Headers supplémentaires pour Gmail
                        if ($isGmail) {
                            $headers .= "Reply-To: " . SMTP_FROM . "\r\n";
                            $headers .= "X-Priority: 1\r\n";
                            $headers .= "X-MSMail-Priority: High\r\n";
                        }
                        
                        // Headers pour iCloud
                        if ($isICloud) {
                            $headers .= "List-Unsubscribe: <mailto:unsubscribe@" . $_SERVER['HTTP_HOST'] . ">\r\n";
                        }
                        
                        $headers .= "X-Mailer: PHP/" . phpversion();
                        
                        $mailSent = mail($email, $subject, $htmlMessage, $headers);
                        
                        // Envoyer aussi la version texte pour les clients qui ne supportent pas le HTML
                        if ($mailSent) {
                            $textHeaders = "From: WideMaze <" . SMTP_FROM . ">\r\n";
                            mail($email, $subject . " (version texte)", $textMessage, $textHeaders);
                        }
                    }
                    
                    if ($mailSent) {
                        log_activity($pdo, $user['id'], 'password_recovery_requested', ['email' => $email, 'domain' => $emailDomain]);
                    } else {
                        error_log("Échec d'envoi d'email de récupération pour: " . $email);
                    }
                }
                
                // Toujours afficher le même message pour des raisons de sécurité
                $success = true;
                
            } catch (PDOException $e) {
                error_log("Erreur récupération mot de passe: " . $e->getMessage());
                $errors[] = "Erreur système, veuillez réessayer";
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
    <title>WideMaze - Récupération de mot de passe</title>
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
                              radial-gradient(at 0% 50%, hsla(340,100%,76%,1) 0px, transparent 50%),
                              radial-gradient(at 80% 50%, hsla(340,100%,76%,1) 0px, transparent 50%),
                              radial-gradient(at 0% 100%, hsla(22,100%,77%,1) 0px, transparent 50%),
                              radial-gradient(at 80% 100%, hsla(242,100%,70%,1) 0px, transparent 50%);
        }
        .glass {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
    </style>
</head>
<body class="mesh-bg min-h-screen flex items-center justify-center p-4">

    <!-- Particles Background -->
    <div class="fixed inset-0 overflow-hidden pointer-events-none">
        <div class="absolute top-20 left-20 w-72 h-72 bg-purple-300 rounded-full mix-blend-multiply filter blur-xl opacity-70 animate-pulse"></div>
        <div class="absolute top-40 right-20 w-72 h-72 bg-yellow-300 rounded-full mix-blend-multiply filter blur-xl opacity-70 animate-pulse" style="animation-delay: 2s"></div>
    </div>

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
            
            <!-- Header -->
            <div class="text-center mb-8">
                <div class="w-20 h-20 bg-orange-100 rounded-full flex items-center justify-center mx-auto mb-4">
                    <i class="fas fa-key text-primary text-3xl"></i>
                </div>
                <h1 class="text-2xl font-bold text-gray-800">Mot de passe oublié ?</h1>
                <p class="text-gray-600 text-sm mt-2">
                    Saisissez votre adresse email pour recevoir un lien de réinitialisation
                </p>
            </div>

            <!-- Success Message -->
            <?php if ($success): ?>
                <div class="bg-green-50 border-l-4 border-green-500 p-4 rounded-r-lg mb-6 animate-fade-in">
                    <div class="flex items-center gap-3">
                        <i class="fas fa-check-circle text-green-500 text-xl"></i>
                        <div>
                            <p class="text-green-700 font-medium">Email envoyé !</p>
                            <p class="text-green-600 text-sm mt-1">
                                Si un compte existe avec cette adresse, vous recevrez un email contenant les instructions.
                            </p>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Error Messages -->
            <?php if (!empty($errors)): ?>
                <div class="bg-red-50 border-l-4 border-red-500 p-4 rounded-r-lg mb-6">
                    <div class="flex items-center gap-3">
                        <i class="fas fa-exclamation-circle text-red-500 text-xl"></i>
                        <div>
                            <p class="text-red-700 font-medium">Erreur</p>
                            <ul class="text-red-600 text-sm mt-1 list-disc list-inside">
                                <?php foreach ($errors as $error): ?>
                                    <li><?= htmlspecialchars($error) ?></li>
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
                    <label class="block text-sm font-medium text-gray-700 mb-2">Adresse email</label>
                    <div class="relative">
                        <i class="fas fa-envelope absolute left-4 top-1/2 -translate-y-1/2 text-gray-400"></i>
                        <input type="email" name="email" id="email" required
                            class="w-full pl-12 pr-4 py-3 rounded-xl border-2 border-gray-200 focus:border-primary focus:ring-4 focus:ring-orange-100 outline-none transition-all"
                            placeholder="votre@email.com"
                            value="<?= htmlspecialchars($email) ?>"
                            autocomplete="email">
                    </div>
                    <p class="text-red-500 text-xs mt-1 hidden" id="emailError">Veuillez entrer une adresse email valide</p>
                </div>

                <button type="submit" name="recover" id="submitBtn"
                    class="w-full bg-gradient-to-r from-primary to-orange-600 text-white font-semibold py-3 rounded-xl shadow-lg hover:shadow-xl transform hover:-translate-y-0.5 transition-all duration-200 flex items-center justify-center gap-2 disabled:opacity-50 disabled:cursor-not-allowed">
                    <span id="btnText">Envoyer le lien</span>
                    <i class="fas fa-paper-plane" id="btnIcon"></i>
                    <div class="loading-spinner hidden w-5 h-5 border-3 border-white border-t-transparent rounded-full animate-spin" id="loadingSpinner"></div>
                </button>
            </form>

            <!-- Back to login -->
            <div class="mt-8 text-center">
                <a href="connexion.php" class="text-gray-500 hover:text-primary transition-colors inline-flex items-center gap-2">
                    <i class="fas fa-arrow-left"></i>
                    Retour à la connexion
                </a>
            </div>
        </div>

        <!-- Footer -->
        <p class="text-center text-gray-500 text-xs mt-8">
            &copy; <?= date('Y') ?> WideMaze. Tous droits réservés.
        </p>
    </div>

    <script>
        // Form validation and submission
        document.getElementById('recoverForm').addEventListener('submit', function(e) {
            const email = document.getElementById('email');
            const submitBtn = document.getElementById('submitBtn');
            const btnText = document.getElementById('btnText');
            const btnIcon = document.getElementById('btnIcon');
            const loadingSpinner = document.getElementById('loadingSpinner');
            const emailError = document.getElementById('emailError');
            
            let isValid = true;
            
            // Email validation
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
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
        document.getElementById('email').addEventListener('blur', function() {
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            const error = document.getElementById('emailError');
            if (this.value && !emailRegex.test(this.value)) {
                error.classList.remove('hidden');
                this.classList.add('border-red-500');
            } else {
                error.classList.add('hidden');
                this.classList.remove('border-red-500');
            }
        });

        document.getElementById('email').addEventListener('input', function() {
            this.classList.remove('border-red-500');
            document.getElementById('emailError').classList.add('hidden');
        });

        // Prevent form resubmission on page refresh
        if (window.history.replaceState) {
            window.history.replaceState(null, null, window.location.href);
        }
    </script>
</body>
</html>