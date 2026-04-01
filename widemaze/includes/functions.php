<?php
/**
 * WideMaze - General Functions
 * Utility functions for the entire application
 */

/**
 * Generate CSRF token
 * @return string
 */
function generate_csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Verify CSRF token
 * @param string $token Token to verify
 * @return bool
 */
function verify_csrf_token($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Sanitize input
 * @param string $data Data to sanitize
 * @return string
 */
function sanitize_input($data) {
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}

/**
 * Escape HTML for safe output
 * @param string $text Text to escape
 * @return string
 */
function escape_html($text) {
    return htmlspecialchars($text ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * Get avatar URL
 * @param string|null $avatar Avatar filename
 * @return string
 */
function get_avatar_url($avatar = null) {
    if (!empty($avatar) && file_exists(AVATAR_DIR . $avatar)) {
        return AVATAR_URL . $avatar;
    }
    return AVATAR_URL . DEFAULT_AVATAR;
}

/**
 * Format file size
 * @param int $bytes Size in bytes
 * @return string
 */
function format_file_size($bytes) {
    if ($bytes === null || $bytes == 0) return '0 B';
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = floor(log($bytes, 1024));
    return round($bytes / pow(1024, $i), 1) . ' ' . $units[$i];
}

/**
 * Validate password strength
 * @param string $password Password to validate
 * @return array List of errors
 */
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
    if (!preg_match('/[!@#$%^&*()\-_=+{};:,<.>]/', $password)) {
        $errors[] = "Au moins un caractère spécial";
    }
    
    return $errors;
}

/**
 * Hash password
 * @param string $password Plain text password
 * @return string Hashed password
 */
function hash_password($password) {
    return password_hash($password, PASSWORD_ARGON2ID, [
        'memory_cost' => 65536,
        'time_cost' => 4,
        'threads' => 3
    ]);
}

/**
 * Verify password
 * @param string $password Plain text password
 * @param string $hash Hashed password
 * @return bool
 */
function verify_password($password, $hash) {
    return password_verify($password, $hash);
}

/**
 * Check if email exists
 * @param PDO $pdo Database connection
 * @param string $email Email to check
 * @return bool
 */
function email_exists($pdo, $email) {
    $stmt = $pdo->prepare("SELECT id FROM utilisateurs WHERE email = ?");
    $stmt->execute([$email]);
    return $stmt->fetch() !== false;
}

/**
 * Handle file upload with security checks
 * @param array $file $_FILES array element
 * @param string $directory Target directory
 * @param array $allowed_types Allowed MIME types
 * @param int $max_size Maximum file size in bytes
 * @return array Upload result
 */
function handle_file_upload($file, $directory, $allowed_types, $max_size = MAX_FILE_SIZE) {
    // Check upload error
    if ($file['error'] != UPLOAD_ERR_OK) {
        $errors = [
            UPLOAD_ERR_INI_SIZE => 'Fichier trop volumineux (limite serveur)',
            UPLOAD_ERR_FORM_SIZE => 'Fichier trop volumineux',
            UPLOAD_ERR_PARTIAL => 'Upload partiel',
            UPLOAD_ERR_NO_FILE => 'Aucun fichier envoyé',
            UPLOAD_ERR_NO_TMP_DIR => 'Dossier temporaire manquant',
            UPLOAD_ERR_CANT_WRITE => 'Erreur écriture disque',
            UPLOAD_ERR_EXTENSION => 'Extension PHP bloquée'
        ];
        return ['success' => false, 'error' => $errors[$file['error']] ?? 'Erreur inconnue'];
    }
    
    // Check file size
    if ($file['size'] > $max_size) {
        return ['success' => false, 'error' => 'Fichier trop volumineux (max ' . ($max_size / 1024 / 1024) . ' MB)'];
    }
    
    // Verify MIME type with finfo
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    
    if (!in_array($mime, $allowed_types)) {
        return ['success' => false, 'error' => 'Type de fichier non autorisé'];
    }
    
    // Verify extension
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed_exts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'mp4', 'webm', 'pdf', 'doc', 'docx'];
    if (!in_array($ext, $allowed_exts)) {
        return ['success' => false, 'error' => 'Extension non autorisée'];
    }
    
    // Create directory if needed
    if (!is_dir($directory)) {
        mkdir($directory, 0755, true);
    }
    
    // Generate unique filename
    $filename = uniqid() . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    $destination = $directory . $filename;
    
    // Move uploaded file
    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        return ['success' => false, 'error' => 'Erreur lors du déplacement du fichier'];
    }
    
    // Optimize images
    if (in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'])) {
        optimize_image($destination);
    }
    
    return ['success' => true, 'filename' => $filename];
}

/**
 * Optimize image quality and size
 * @param string $path Image path
 * @param int $max_width Maximum width
 * @param int $max_height Maximum height
 * @return bool
 */
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
    
    switch ($type) {
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
    
    switch ($type) {
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

/**
 * Log user activity
 * @param PDO $pdo Database connection
 * @param int|null $user_id User ID (can be null for system actions)
 * @param string $action Action performed
 * @param array|null $details Additional details
 * @return void
 */
function log_activity($pdo, $user_id, $action, $details = null) {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO activity_logs (user_id, action, details, ip_address, user_agent, created_at) 
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $user_id,
            $action,
            $details ? json_encode($details) : null,
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null
        ]);
    } catch (PDOException $e) {
        error_log("Error logging activity: " . $e->getMessage());
    }
}

/**
 * Create notification
 * @param PDO $pdo Database connection
 * @param int $user_id Target user ID
 * @param string $type Notification type
 * @param string $title Notification title
 * @param int|null $actor_id Actor user ID
 * @param string|null $link Notification link
 * @return int|bool Notification ID or false on failure
 */
function create_notification($pdo, $user_id, $type, $title, $actor_id = null, $link = null) {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO notifications (user_id, type, title, actor_id, link, created_at, is_read) 
            VALUES (?, ?, ?, ?, ?, NOW(), 0)
        ");
        $stmt->execute([$user_id, $type, $title, $actor_id, $link]);
        return $pdo->lastInsertId();
    } catch (PDOException $e) {
        error_log("Error creating notification: " . $e->getMessage());
        return false;
    }
}

/**
 * Rate limiting check
 * @param string $action Action identifier
 * @param int $max_attempts Maximum attempts
 * @param int $window Time window in seconds
 * @return void
 */
function check_rate_limit($action, $max_attempts = RATE_LIMIT_REQUESTS, $window = RATE_LIMIT_WINDOW) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
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
    
    if ($_SESSION['rate_limit'][$key]['count'] > $max_attempts) {
        json_response(['error' => 'Trop de requêtes. Veuillez réessayer plus tard.'], 429);
    }
}

/**
 * JSON response helper
 * @param array $data Data to encode
 * @param int $status HTTP status code
 * @return void
 */
function json_response($data, $status = 200) {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit();
}

/**
 * Get old form value
 * @param string $field Field name
 * @param string $default Default value
 * @return string
 */
function old($field, $default = '') {
    return isset($_POST[$field]) ? sanitize_input($_POST[$field]) : $default;
}

/**
 * Get active stories for a user
 * @param PDO $pdo Database connection
 * @param int $user_id Current user ID
 * @return array
 */
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
        error_log("Error getting stories: " . $e->getMessage());
        return [];
    }
}

/**
 * Get user theme preference
 * @param PDO $pdo Database connection
 * @param int $user_id User ID
 * @return bool
 */
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

/**
 * Format time ago
 * @param string $datetime DateTime string
 * @return string
 */
function time_ago($datetime) {
    if (empty($datetime)) return 'Date inconnue';
    
    $time = strtotime($datetime);
    $now = time();
    $diff = $now - $time;
    
    if ($diff < 60) return 'À l\'instant';
    if ($diff < 3600) return floor($diff / 60) . ' min';
    if ($diff < 86400) return floor($diff / 3600) . ' h';
    if ($diff < 604800) return floor($diff / 86400) . ' j';
    return date('d M', $time);
}

/**
 * Effectue un appel API interne sécurisé
 * @param string $endpoint Endpoint API (ex: 'post_reports.php?action=list')
 * @param array $data Données à envoyer (optionnel)
 * @param string $method Méthode HTTP (GET, POST)
 * @return array|null Réponse décodée ou null en cas d'erreur
 */
function call_api($endpoint, $data = null, $method = 'GET') {
    $url = SITE_URL . '/api/' . ltrim($endpoint, '/');
    
    try {
        if (function_exists('curl_init')) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            
            if ($method === 'POST' && $data) {
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
            }
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode === 200 && $response) {
                return json_decode($response, true);
            }
        } else {
            // Fallback avec file_get_contents
            $context = stream_context_create(['http' => ['timeout' => 10]]);
            $response = @file_get_contents($url, false, $context);
            if ($response !== false) {
                return json_decode($response, true);
            }
        }
    } catch (Exception $e) {
        error_log("API call error: " . $e->getMessage());
    }
    
    return null;
}