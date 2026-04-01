<?php
/**
 * WideMaze - Authentication Functions
 * Handles user authentication, authorization, and session management
 */

/**
 * Check if user is logged in
 * @return bool
 */
function is_logged_in() {
    return isset($_SESSION['user_id']);
}

/**
 * Check if the current request is an AJAX request
 * @return bool
 */
function is_ajax_request() {
    return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
           strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';
}

/**
 * Require authentication - redirects to login if not authenticated
 * @return void
 */
function require_auth() {
    if (!is_logged_in()) {
        if (is_ajax_request()) {
            json_response(['error' => 'Non authentifié', 'redirect' => 'connexion.php'], STATUS_UNAUTHORIZED);
        }
        header('Location: ' . SITE_URL . '/pages/connexion.php');
        exit();
    }
    
    // Check session activity
    if (isset($_SESSION['last_activity']) && time() - $_SESSION['last_activity'] > SESSION_LIFETIME) {
        logout_user();
        if (is_ajax_request()) {
            json_response(['error' => 'Session expirée', 'redirect' => 'connexion.php'], STATUS_UNAUTHORIZED);
        }
        header('Location: ' . SITE_URL . '/pages/connexion.php?error=session_expired');
        exit();
    }
    
    // Update last activity time
    $_SESSION['last_activity'] = time();
}

/**
 * Check if user is admin
 * @return bool
 */
function is_admin() {
    return isset($_SESSION['role']) && $_SESSION['role'] == ROLE_ADMIN;
}

/**
 * Require admin access - redirects if not admin
 * @return void
 */
function require_admin() {
    require_auth();
    if (!is_admin()) {
        if (is_ajax_request()) {
            json_response(['error' => 'Accès non autorisé'], STATUS_FORBIDDEN);
        }
        header('Location: ' . SITE_URL . '/index.php');
        exit();
    }
}

/**
 * Login user
 * @param PDO $pdo Database connection
 * @param string $email User email
 * @param string $password User password
 * @param bool $remember Remember me option
 * @return array Login result
 */
function login_user($pdo, $email, $password, $remember = false) {
    try {
        // Check login attempts
        $attemptCheck = check_login_attempts($pdo, $email);
        if ($attemptCheck['blocked']) {
            return ['success' => false, 'error' => "Trop de tentatives. Réessayez dans {$attemptCheck['wait']} secondes."];
        }
        
        // Fetch user
        $stmt = $pdo->prepare("
            SELECT id, surnom, email, motdepasse, prenom, nom, avatar, role, is_verified, is_active
            FROM utilisateurs 
            WHERE email = ? LIMIT 1
        ");
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        
        // Verify user exists and is active
        if (!$user || !$user['is_active']) {
            record_failed_login($pdo, $email);
            return ['success' => false, 'error' => 'Email ou mot de passe incorrect'];
        }
        
        // Verify password
        if (!verify_password($password, $user['motdepasse'])) {
            record_failed_login($pdo, $email);
            return ['success' => false, 'error' => 'Email ou mot de passe incorrect'];
        }
        
        // Reset login attempts on successful login
        reset_login_attempts($pdo, $user['id']);
        
        // Set session variables
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['surnom'] = $user['surnom'];
        $_SESSION['email'] = $user['email'];
        $_SESSION['prenom'] = $user['prenom'];
        $_SESSION['nom'] = $user['nom'];
        $_SESSION['avatar'] = $user['avatar'] ?: DEFAULT_AVATAR;
        $_SESSION['role'] = $user['role'];
        $_SESSION['is_verified'] = $user['is_verified'];
        $_SESSION['last_activity'] = time();
        $_SESSION['last_regeneration'] = time();
        
        // Update user status to online
        set_user_online($pdo, $user['id']);
        
        // Set remember me cookie
        if ($remember) {
            $token = bin2hex(random_bytes(32));
            $expires = time() + 30 * 24 * 60 * 60;
            setcookie('remember_token', $token, $expires, '/', isset($_SERVER['HTTPS']), true);
            
            // Store token in database
            $stmt = $pdo->prepare("UPDATE utilisateurs SET remember_token = ?, remember_expires = FROM_UNIXTIME(?) WHERE id = ?");
            $stmt->execute([$token, $expires, $user['id']]);
        }
        
        // Log activity
        log_activity($pdo, $user['id'], 'login_success');
        
        return ['success' => true, 'user' => $user];
        
    } catch (PDOException $e) {
        error_log("Login error: " . $e->getMessage());
        return ['success' => false, 'error' => 'Erreur système, veuillez réessayer'];
    }
}

/**
 * Logout user
 * @return void
 */
function logout_user() {
    global $pdo;
    
    if (isset($_SESSION['user_id'])) {
        set_user_offline($pdo, $_SESSION['user_id']);
        log_activity($pdo, $_SESSION['user_id'], 'logout');
    }
    
    // Clear remember me cookie
    setcookie('remember_token', '', time() - 3600, '/', isset($_SERVER['HTTPS']), true);
    
    // Destroy session
    $_SESSION = [];
    session_destroy();
}

/**
 * Check login attempts and lockout
 * @param PDO $pdo Database connection
 * @param string $email User email
 * @return array Attempt status
 */
function check_login_attempts($pdo, $email) {
    $stmt = $pdo->prepare("SELECT failed_login_attempts, locked_until FROM utilisateurs WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    
    if ($user && $user['locked_until'] && strtotime($user['locked_until']) > time()) {
        $wait = strtotime($user['locked_until']) - time();
        return ['blocked' => true, 'wait' => $wait];
    }
    
    return ['blocked' => false, 'attempts' => $user['failed_login_attempts'] ?? 0];
}

/**
 * Record failed login attempt
 * @param PDO $pdo Database connection
 * @param string $email User email
 * @return void
 */
function record_failed_login($pdo, $email) {
    $stmt = $pdo->prepare("UPDATE utilisateurs SET failed_login_attempts = failed_login_attempts + 1 WHERE email = ?");
    $stmt->execute([$email]);
    
    // Lock account after max attempts
    $stmt = $pdo->prepare("
        UPDATE utilisateurs 
        SET locked_until = DATE_ADD(NOW(), INTERVAL ? SECOND) 
        WHERE email = ? AND failed_login_attempts >= ?
    ");
    $stmt->execute([LOCKOUT_DURATION, $email, MAX_LOGIN_ATTEMPTS]);
}

/**
 * Reset login attempts on successful login
 * @param PDO $pdo Database connection
 * @param int $user_id User ID
 * @return void
 */
function reset_login_attempts($pdo, $user_id) {
    $stmt = $pdo->prepare("UPDATE utilisateurs SET failed_login_attempts = 0, locked_until = NULL WHERE id = ?");
    $stmt->execute([$user_id]);
}

/**
 * Set user online status
 * @param PDO $pdo Database connection
 * @param int $user_id User ID
 * @return bool
 */
function set_user_online($pdo, $user_id) {
    try {
        $stmt = $pdo->prepare("UPDATE utilisateurs SET status = 'Online', dateconnexion = NOW(), last_ip = ? WHERE id = ?");
        $stmt->execute([$_SERVER['REMOTE_ADDR'], $user_id]);
        return true;
    } catch (PDOException $e) {
        error_log("Error setting user online: " . $e->getMessage());
        return false;
    }
}

/**
 * Set user offline status
 * @param PDO $pdo Database connection
 * @param int $user_id User ID
 * @return bool
 */
function set_user_offline($pdo, $user_id) {
    try {
        $stmt = $pdo->prepare("UPDATE utilisateurs SET status = 'Offline' WHERE id = ?");
        $stmt->execute([$user_id]);
        return true;
    } catch (PDOException $e) {
        error_log("Error setting user offline: " . $e->getMessage());
        return false;
    }
}