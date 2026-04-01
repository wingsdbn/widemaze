<?php
// Désactiver l'affichage des erreurs en production
error_reporting(E_ALL);
ini_set('display_errors', 1); // TEMPORAIRE - mettre 0 en production
ini_set('log_errors', 1);
ini_set('error_log', 'logs/errors.log');

// Constantes de configuration (DOIVENT être en premier !)
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'widemaze');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_PORT', '3306');
define('DB_CHARSET', 'utf8mb4');

// Constantes de l'application
define('SITE_URL', 'http://localhost/widemaze');
define('UPLOAD_DIR', 'uploads/');
define('AVATAR_DIR', 'uploads/avatars/');
define('POSTS_DIR', 'uploads/posts/');
define('AVATAR_URL', 'uploads/avatars/');
define('DEFAULT_AVATAR', 'default-avatar.png');
define('MAX_FILE_SIZE', 5 * 1024 * 1024); // 5MB
define('SESSION_LIFETIME', 1800); // 30 minutes
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOCKOUT_DURATION', 900); // 15 minutes
define('RATE_LIMIT_REQUESTS', 100); // Requêtes par minute
define('RATE_LIMIT_WINDOW', 60);

// Headers de sécurité (MAINTENANT les constantes sont définies)
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("X-XSS-Protection: 1; mode=block");
header("Referrer-Policy: strict-origin-when-cross-origin");
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' cdn.tailwindcss.com cdnjs.cloudflare.com unpkg.com; style-src 'self' 'unsafe-inline' cdn.tailwindcss.com cdnjs.cloudflare.com fonts.googleapis.com; font-src 'self' fonts.gstatic.com cdnjs.cloudflare.com; img-src 'self' data: https:; connect-src 'self';");

// Options PDO pour la connexion
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
    PDO::ATTR_PERSISTENT         => true
];

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    $pdo->query("SELECT 1");
} catch (PDOException $e) {
    error_log("[" . date('Y-m-d H:i:s') . "] Erreur DB: " . $e->getMessage());
    die(json_encode(['error' => 'Service temporairement indisponible']));
}

// Démarrer la session sécurisée - VERSION SIMPLIFIÉE
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Régénérer l'ID de session périodiquement
if (isset($_SESSION['last_regeneration']) && 
    time() - $_SESSION['last_regeneration'] > 300) {
    session_regenerate_id(true);
    $_SESSION['last_regeneration'] = time();
}

/**
 * Fonctions de sécurité
 */

function secure_input($data) {
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}

function generate_csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf_token($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function is_logged_in() {
    return isset($_SESSION['user_id']);
}

function require_auth() {
    if (!is_logged_in()) {
        // Si c'est une requête AJAX
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
            strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Non authentifié', 'redirect' => 'connexion.php']);
            exit();
        }
        
        // Redirection normale
        header('Location: connexion.php');
        exit();
    }
    
    // Vérifier l'activité
    if (isset($_SESSION['last_activity']) && 
        time() - $_SESSION['last_activity'] > SESSION_LIFETIME) {
        // Mettre l'utilisateur hors ligne avant de détruire la session
        if (isset($_SESSION['user_id'])) {
            global $pdo;
            $stmt = $pdo->prepare("UPDATE utilisateurs SET status = 'Offline' WHERE id = ?");
            $stmt->execute([$_SESSION['user_id']]);
        }
        
        session_destroy();
        
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
            strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Session expirée', 'redirect' => 'connexion.php']);
            exit();
        }
        
        header('Location: connexion.php?login_err=session_expired');
        exit();
    }
    
    $_SESSION['last_activity'] = time();
}

// Rate Limiting
function check_rate_limit($action, $maxAttempts = RATE_LIMIT_REQUESTS, $window = RATE_LIMIT_WINDOW) {
    $ip = $_SERVER['REMOTE_ADDR'];
    $key = $ip . '_' . $action;
    
    if (!isset($_SESSION['rate_limit'])) {
        $_SESSION['rate_limit'] = [];
    }
    
    $now = time();
    if (!isset($_SESSION['rate_limit'][$key])) {
        $_SESSION['rate_limit'][$key] = ['count' => 0, 'reset' => $now + $window];
    }
    
    if ($_SESSION['rate_limit'][$key]['reset'] < $now) {
        $_SESSION['rate_limit'][$key] = ['count' => 0, 'reset' => $now + $window];
    }
    
    $_SESSION['rate_limit'][$key]['count']++;
    
    if ($_SESSION['rate_limit'][$key]['count'] > $maxAttempts) {
        http_response_code(429);
        die(json_encode(['error' => 'Trop de requêtes. Veuillez réessayer plus tard.']));
    }
}

function check_login_attempts($pdo, $email) {
    $stmt = $pdo->prepare("SELECT failed_login_attempts, locked_until FROM utilisateurs WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    
    if ($user && $user['locked_until'] && strtotime($user['locked_until']) > time()) {
        return ['blocked' => true, 'wait' => strtotime($user['locked_until']) - time()];
    }
    return ['blocked' => false, 'attempts' => $user['failed_login_attempts'] ?? 0];
}

function record_failed_login($pdo, $email) {
    $stmt = $pdo->prepare("UPDATE utilisateurs SET failed_login_attempts = failed_login_attempts + 1 
                          WHERE email = ?");
    $stmt->execute([$email]);
    
    // Verrouiller après trop de tentatives
    $stmt = $pdo->prepare("UPDATE utilisateurs SET locked_until = DATE_ADD(NOW(), INTERVAL ? SECOND) 
                          WHERE email = ? AND failed_login_attempts >= ?");
    $stmt->execute([LOCKOUT_DURATION, $email, MAX_LOGIN_ATTEMPTS]);
}

function reset_login_attempts($pdo, $user_id) {
    $stmt = $pdo->prepare("UPDATE utilisateurs SET failed_login_attempts = 0, locked_until = NULL WHERE id = ?");
    $stmt->execute([$user_id]);
}

// ========== FONCTIONS DE MOT DE PASSE MODIFIÉES ==========

// Stockage en CLAIR - DANGEREUX mais demandé
function store_password($password) {
    // Ne fait rien - on stocke tel quel
    return $password;
}

// Vérification en CLAIR
/*function verify_password($password, $stored) {
    return $password === $stored;
}
*/
// Ancienne fonction avec hashage 

function hash_password($password) {
    return password_hash($password, PASSWORD_ARGON2ID, [
        'memory_cost' => 65536,
        'time_cost' => 4,
        'threads' => 3
    ]);
}

function verify_password($password, $hash) {
    return password_verify($password, $hash);
}


// ========== FIN MODIFICATIONS MOT DE PASSE ==========

function email_exists($pdo, $email) {
    $stmt = $pdo->prepare("SELECT id FROM utilisateurs WHERE email = ?");
    $stmt->execute([$email]);
    return $stmt->fetch() !== false;
}

function validate_password_strength($password) {
    $errors = [];
    if (strlen($password) < 8) {
        $errors[] = "Au moins 8 caractères";
    }
    if (!preg_match('/[A-Z]/', $password)) {
        $errors[] = "Au moins une majuscule";
    }
    if (!preg_match('/[a-z]/', $password)) {
        $errors[] = "Au moins une minuscule";
    }
    if (!preg_match('/[0-9]/', $password)) {
        $errors[] = "Au moins un chiffre";
    }
    if (!preg_match('/[!@#$%^&*(),.?":{}|<>]/', $password)) {
        $errors[] = "Au moins un caractère spécial";
    }
    return $errors;
}

function handle_file_upload($file, $directory, $allowed_types, $max_size = MAX_FILE_SIZE) {
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'error' => 'Erreur upload: ' . $file['error']];
    }
    
    if ($file['size'] > $max_size) {
        return ['success' => false, 'error' => 'Fichier trop volumineux (max ' . ($max_size/1024/1024) . 'MB)'];
    }
    
    // Vérification MIME réelle
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    
    if (!in_array($mime, $allowed_types)) {
        return ['success' => false, 'error' => 'Type de fichier non autorisé'];
    }
    
    // Vérifier l'extension
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed_exts = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    if (!in_array($ext, $allowed_exts)) {
        return ['success' => false, 'error' => 'Extension non autorisée'];
    }
    
    // Vérification du contenu réel (anti-malware)
    if (in_array($mime, ['image/jpeg', 'image/png', 'image/gif'])) {
        if (!getimagesize($file['tmp_name'])) {
            return ['success' => false, 'error' => 'Fichier image invalide'];
        }
    }
    
    // Créer le dossier si nécessaire
    if (!is_dir($directory)) {
        mkdir($directory, 0755, true);
    }
    
    // Générer un nom unique
    $filename = uniqid() . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    $destination = $directory . $filename;
    
    // Déplacer et vérifier
    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        return ['success' => false, 'error' => 'Erreur lors du déplacement'];
    }
    
    // Optimiser l'image
    if (in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'])) {
        optimize_image($destination);
    }
    
    return ['success' => true, 'filename' => $filename];
}

function optimize_image($path, $max_width = 1920, $max_height = 1080) {
    if (!extension_loaded('gd')) {
        return true;
    }
    
    list($width, $height, $type) = getimagesize($path);
    
    if ($width <= $max_width && $height <= $max_height) {
        return true;
    }
    
    $ratio = min($max_width / $width, $max_height / $height);
    $new_width = round($width * $ratio);
    $new_height = round($height * $ratio);
    
    switch($type) {
        case IMAGETYPE_JPEG:
            $src = imagecreatefromjpeg($path);
            break;
        case IMAGETYPE_PNG:
            $src = imagecreatefrompng($path);
            imagealphablending($src, true);
            imagesavealpha($src, true);
            break;
        case IMAGETYPE_GIF:
            $src = imagecreatefromgif($path);
            break;
        default:
            return true;
    }
    
    $dst = imagecreatetruecolor($new_width, $new_height);
    
    if ($type == IMAGETYPE_PNG) {
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
    }
    
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $new_width, $new_height, $width, $height);
    
    switch($type) {
        case IMAGETYPE_JPEG:
            imagejpeg($dst, $path, 85);
            break;
        case IMAGETYPE_PNG:
            imagepng($dst, $path, 6);
            break;
        case IMAGETYPE_GIF:
            imagegif($dst, $path);
            break;
    }
    
    imagedestroy($src);
    imagedestroy($dst);
    return true;
}

function old($field, $default = '') {
    return isset($_POST[$field]) ? secure_input($_POST[$field]) : $default;
}

function json_response($data, $status = 200) {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit();
}

function log_activity($pdo, $user_id, $action, $details = null) {
    try {
        $stmt = $pdo->prepare("INSERT INTO activity_logs (user_id, action, details, ip_address, user_agent) 
                              VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([
            $user_id, 
            $action, 
            $details ? json_encode($details) : null,
            $_SERVER['REMOTE_ADDR'],
            $_SERVER['HTTP_USER_AGENT']
        ]);
    } catch (PDOException $e) {
        error_log("Erreur log activity: " . $e->getMessage());
    }
}

// Fonctions de notification - CORRIGÉES pour correspondre à la BD
function create_notification($pdo, $user_id, $type, $content, $item_id = null, $actor_id = null) {
    try {
        $stmt = $pdo->prepare("INSERT INTO notifications (user_id, type, content, item_id, actor_id, created_at) 
                              VALUES (?, ?, ?, ?, ?, NOW())");
        $stmt->execute([$user_id, $type, $content, $item_id, $actor_id]);
        return $pdo->lastInsertId();
    } catch (PDOException $e) {
        error_log("Erreur notification: " . $e->getMessage());
        return false;
    }
}

function get_unread_notifications_count($pdo, $user_id) {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
        $stmt->execute([$user_id]);
        return $stmt->fetchColumn();
    } catch (PDOException $e) {
        return 0;
    }
}

function mark_notifications_read($pdo, $user_id, $notification_ids = null) {
    try {
        if ($notification_ids && is_array($notification_ids)) {
            $placeholders = implode(',', array_fill(0, count($notification_ids), '?'));
            $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND id IN ($placeholders)");
            $stmt->execute(array_merge([$user_id], $notification_ids));
        } else {
            $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0");
            $stmt->execute([$user_id]);
        }
        return true;
    } catch (PDOException $e) {
        error_log("Erreur mark read: " . $e->getMessage());
        return false;
    }
}

// Fonctions pour les stories - CORRIGÉES pour correspondre à la BD
function create_story($pdo, $user_id, $media_url, $type = 'image') {
    try {
        $expires_at = date('Y-m-d H:i:s', strtotime('+24 hours'));
        $stmt = $pdo->prepare("INSERT INTO stories (user_id, media_url, type, expires_at, created_at) 
                              VALUES (?, ?, ?, ?, NOW())");
        $stmt->execute([$user_id, $media_url, $type, $expires_at]);
        return $pdo->lastInsertId();
    } catch (PDOException $e) {
        error_log("Erreur story: " . $e->getMessage());
        return false;
    }
}

function get_active_stories($pdo, $user_id) {
    try {
        $stmt = $pdo->prepare("
            SELECT s.*, u.surnom, u.avatar, 
                   EXISTS(SELECT 1 FROM story_views sv WHERE sv.story_id = s.id AND sv.user_id = ?) as viewed
            FROM stories s
            JOIN utilisateurs u ON s.user_id = u.id
            WHERE s.expires_at > NOW() 
            AND (s.user_id = ? OR s.user_id IN (
                SELECT CASE 
                    WHEN a.id = ? THEN a.idami 
                    ELSE a.id 
                END as friend_id
                FROM ami a
                WHERE (a.id = ? OR a.idami = ?) AND a.accepterami = 1
            ))
            ORDER BY s.created_at DESC
        ");
        $stmt->execute([$user_id, $user_id, $user_id, $user_id, $user_id]);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("Erreur get stories: " . $e->getMessage());
        return [];
    }
}

// Mode sombre
function get_user_theme($pdo, $user_id) {
    try {
        $stmt = $pdo->prepare("SELECT dark_mode FROM user_preferences WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $result = $stmt->fetch();
        return $result ? (bool)$result['dark_mode'] : false;
    } catch (PDOException $e) {
        return false;
    }
}

function getAvatarUrl($avatar) {
    if (!empty($avatar) && file_exists(AVATAR_DIR . $avatar)) {
        return AVATAR_URL . $avatar;
    }
    return AVATAR_URL . DEFAULT_AVATAR;
}
// Fonction pour mettre un utilisateur en ligne
function set_user_online($pdo, $user_id) {
    try {
        $stmt = $pdo->prepare("UPDATE utilisateurs SET status = 'Online', dateconnexion = NOW(), last_ip = ? WHERE id = ?");
        $stmt->execute([$_SERVER['REMOTE_ADDR'], $user_id]);
        return true;
    } catch (PDOException $e) {
        error_log("Erreur set_user_online: " . $e->getMessage());
        return false;
    }
}

// Fonction pour mettre un utilisateur hors ligne
function set_user_offline($pdo, $user_id) {
    try {
        $stmt = $pdo->prepare("UPDATE utilisateurs SET status = 'Offline' WHERE id = ?");
        $stmt->execute([$user_id]);
        return true;
    } catch (PDOException $e) {
        error_log("Erreur set_user_offline: " . $e->getMessage());
        return false;
    }
}
?>