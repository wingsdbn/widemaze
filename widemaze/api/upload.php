<?php
/**
 * WideMaze - Upload API
 * Version 4.0 - Gestion complète des fichiers avec validation avancée
 * Méthodes: POST (upload), DELETE (suppression)
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

// Vérification authentification
if (!is_logged_in()) {
    json_response(['error' => 'Non authentifié'], STATUS_UNAUTHORIZED);
}

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$userId = $_SESSION['user_id'];

// Vérification CSRF pour les actions de modification
if (in_array($method, ['POST', 'DELETE'])) {
    $csrfToken = $_POST['csrf_token'] ?? $_GET['csrf_token'] ?? $input['csrf_token'] ?? '';
    if (!verify_csrf_token($csrfToken)) {
        json_response(['error' => 'Token CSRF invalide'], STATUS_FORBIDDEN);
    }
}

// ==================== CONFIGURATION ====================

$configs = [
    'avatar' => [
        'dir' => AVATAR_DIR,
        'max_size' => 2 * 1024 * 1024,
        'types' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
        'exts' => ['jpg', 'jpeg', 'png', 'gif', 'webp'],
        'resize' => [400, 400],
        'compress_quality' => 85,
        'allowed' => true
    ],
    'post' => [
        'dir' => POSTS_DIR,
        'max_size' => 10 * 1024 * 1024,
        'types' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'video/mp4', 'video/webm', 'video/ogg'],
        'exts' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'mp4', 'webm', 'ogg'],
        'resize' => [1920, 1080],
        'compress_quality' => 80,
        'allowed' => true
    ],
    'cover' => [
        'dir' => COVERS_DIR,
        'max_size' => 5 * 1024 * 1024,
        'types' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
        'exts' => ['jpg', 'jpeg', 'png', 'gif', 'webp'],
        'resize' => [1500, 500],
        'compress_quality' => 85,
        'allowed' => true
    ],
    'message' => [
        'dir' => MESSAGES_DIR,
        'max_size' => 50 * 1024 * 1024,
        'types' => ['image/jpeg', 'image/png', 'image/gif', 'application/pdf', 'application/msword', 
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                    'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    'application/zip', 'video/mp4', 'audio/mpeg', 'audio/webm'],
        'exts' => ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'zip', 'mp4', 'mp3', 'webm'],
        'resize' => false,
        'compress_quality' => 80,
        'allowed' => true
    ],
    'document' => [
        'dir' => DOCUMENTS_DIR,
        'max_size' => 20 * 1024 * 1024,
        'types' => ['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                    'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    'application/zip', 'text/plain', 'application/rtf'],
        'exts' => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'zip', 'txt', 'rtf'],
        'resize' => false,
        'compress_quality' => null,
        'allowed' => true
    ]
];

// Récupération du contexte
$context = $_GET['context'] ?? 'post';
$allowedContexts = ['avatar', 'post', 'cover', 'message', 'document'];

if (!in_array($context, $allowedContexts)) {
    json_response(['error' => 'Contexte invalide'], STATUS_BAD_REQUEST);
}

$config = $configs[$context];

// Vérifier si le dossier existe
if (!is_dir($config['dir'])) {
    mkdir($config['dir'], 0755, true);
}

// ==================== ROUTAGE ====================

switch ($method) {
    case 'POST':
        // Vérifier si un fichier a été envoyé
        if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            $errors = [
                UPLOAD_ERR_INI_SIZE => 'Fichier trop volumineux (limite serveur)',
                UPLOAD_ERR_FORM_SIZE => 'Fichier trop volumineux',
                UPLOAD_ERR_PARTIAL => 'Upload partiel',
                UPLOAD_ERR_NO_FILE => 'Aucun fichier envoyé',
                UPLOAD_ERR_NO_TMP_DIR => 'Dossier temporaire manquant',
                UPLOAD_ERR_CANT_WRITE => 'Erreur écriture disque',
                UPLOAD_ERR_EXTENSION => 'Extension PHP bloquée'
            ];
            $errorCode = $_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE;
            json_response(['error' => $errors[$errorCode] ?? 'Erreur upload inconnue'], STATUS_BAD_REQUEST);
        }
        
        $file = $_FILES['file'];
        
        // Vérifier la taille
        if ($file['size'] > $config['max_size']) {
            json_response([
                'error' => 'Fichier trop volumineux',
                'max_size' => $config['max_size'],
                'size' => $file['size'],
                'max_size_mb' => round($config['max_size'] / (1024 * 1024), 1)
            ], STATUS_BAD_REQUEST);
        }
        
        // Vérifier le type MIME réel
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        
        $allowed = false;
        foreach ($config['types'] as $type) {
            if ($mime === $type) {
                $allowed = true;
                break;
            }
        }
        
        if (!$allowed) {
            json_response([
                'error' => 'Type de fichier non autorisé',
                'mime' => $mime,
                'allowed_types' => $config['types']
            ], STATUS_BAD_REQUEST);
        }
        
        // Vérifier l'extension
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $config['exts'])) {
            json_response([
                'error' => 'Extension non autorisée',
                'ext' => $ext,
                'allowed_exts' => $config['exts']
            ], STATUS_BAD_REQUEST);
        }
        
        // Vérification anti-malware pour les images
        if (in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'])) {
            if (!getimagesize($file['tmp_name'])) {
                json_response(['error' => 'Fichier image corrompu ou invalide'], STATUS_BAD_REQUEST);
            }
        }
        
        // Générer un nom unique
        $filename = uniqid() . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
        $destination = $config['dir'] . $filename;
        
        // Déplacer le fichier
        if (!move_uploaded_file($file['tmp_name'], $destination)) {
            json_response(['error' => 'Erreur lors du déplacement du fichier'], STATUS_SERVER_ERROR);
        }
        
        // Traitement spécifique selon le type
        $metadata = [];
        
        if (in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp']) && $config['resize']) {
            // Redimensionner et compresser l'image
            $resizeResult = resize_image($destination, $config['resize'][0], $config['resize'][1], $config['compress_quality']);
            if ($resizeResult) {
                $metadata['width'] = $resizeResult['width'];
                $metadata['height'] = $resizeResult['height'];
            }
            
            // Ajouter un filigrane si nécessaire
            if ($context == 'post' && isset($_POST['add_watermark']) && $_POST['add_watermark'] == '1') {
                add_watermark($destination);
            }
        } elseif (in_array($mime, ['video/mp4', 'video/webm', 'video/ogg'])) {
            // Récupérer la durée de la vidéo
            $metadata['duration'] = get_video_duration($destination);
        } elseif (in_array($mime, ['audio/mpeg', 'audio/webm'])) {
            // Récupérer la durée de l'audio
            $metadata['duration'] = get_audio_duration($destination);
            $metadata['bitrate'] = get_audio_bitrate($destination);
        }
        
        $metadata['mime'] = $mime;
        $metadata['size'] = $file['size'];
        $metadata['original_name'] = $file['name'];
        
        // Sauvegarder les métadonnées
        $metadataFile = $destination . '.meta.json';
        file_put_contents($metadataFile, json_encode($metadata));
        
        // Log de l'upload
        log_activity($pdo, $userId, 'file_uploaded', [
            'context' => $context,
            'filename' => $filename,
            'size' => $file['size'],
            'mime' => $mime
        ]);
        
        // Construire l'URL
        $baseUrl = rtrim(SITE_URL, '/') . '/';
        $relativePath = str_replace($_SERVER['DOCUMENT_ROOT'], '', $destination);
        $url = $baseUrl . ltrim($relativePath, '/');
        
        json_response([
            'success' => true,
            'filename' => $filename,
            'url' => $url,
            'size' => $file['size'],
            'size_formatted' => format_file_size($file['size']),
            'mime' => $mime,
            'metadata' => $metadata,
            'context' => $context
        ], STATUS_CREATED);
        break;
        
    case 'DELETE':
        $filename = trim($input['filename'] ?? $_GET['filename'] ?? '');
        $context = $_GET['context'] ?? 'post';
        
        if (empty($filename)) {
            json_response(['error' => 'Nom de fichier requis'], STATUS_BAD_REQUEST);
        }
        
        // Vérifier que le fichier appartient bien à l'utilisateur
        $filepath = $configs[$context]['dir'] . $filename;
        $isOwner = false;
        
        // Vérifier selon le contexte
        switch ($context) {
            case 'avatar':
                $stmt = $pdo->prepare("SELECT avatar FROM utilisateurs WHERE id = ? AND avatar = ?");
                $stmt->execute([$userId, $filename]);
                $isOwner = $stmt->fetch();
                break;
            case 'post':
                $stmt = $pdo->prepare("SELECT image_post FROM posts WHERE id_utilisateur = ? AND image_post = ?");
                $stmt->execute([$userId, $filename]);
                $isOwner = $stmt->fetch();
                break;
            case 'cover':
                $stmt = $pdo->prepare("SELECT cover_image FROM utilisateurs WHERE id = ? AND cover_image = ?");
                $stmt->execute([$userId, $filename]);
                $isOwner = $stmt->fetch();
                break;
            case 'message':
                $stmt = $pdo->prepare("SELECT file_url FROM message WHERE id_expediteur = ? AND file_url LIKE ?");
                $stmt->execute([$userId, "%$filename%"]);
                $isOwner = $stmt->fetch();
                break;
        }
        
        if (!$isOwner && !is_admin()) {
            json_response(['error' => 'Permission refusée'], STATUS_FORBIDDEN);
        }
        
        // Supprimer le fichier
        if (file_exists($filepath)) {
            unlink($filepath);
        }
        
        // Supprimer le fichier de métadonnées
        $metadataFile = $filepath . '.meta.json';
        if (file_exists($metadataFile)) {
            unlink($metadataFile);
        }
        
        log_activity($pdo, $userId, 'file_deleted', [
            'context' => $context,
            'filename' => $filename
        ]);
        
        json_response([
            'success' => true,
            'message' => 'Fichier supprimé',
            'filename' => $filename
        ]);
        break;
        
    default:
        json_response(['error' => 'Méthode non supportée'], STATUS_METHOD_NOT_ALLOWED);
}

// ==================== FONCTIONS UTILITAIRES ====================

/**
 * Redimensionne et compresse une image
 */
function resize_image($path, $max_width, $max_height, $quality = 85) {
    if (!extension_loaded('gd')) {
        return false;
    }
    
    list($width, $height, $type) = getimagesize($path);
    
    if ($width <= $max_width && $height <= $max_height) {
        return ['width' => $width, 'height' => $height];
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
        case IMAGETYPE_WEBP:
            $src = imagecreatefromwebp($path);
            break;
        default:
            return false;
    }
    
    $dst = imagecreatetruecolor($new_width, $new_height);
    
    // Conserver la transparence pour PNG
    if ($type == IMAGETYPE_PNG) {
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        $quality = 6; // 0-9 pour PNG
    }
    
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $new_width, $new_height, $width, $height);
    
    switch ($type) {
        case IMAGETYPE_JPEG:
            imagejpeg($dst, $path, $quality);
            break;
        case IMAGETYPE_PNG:
            imagepng($dst, $path, $quality);
            break;
        case IMAGETYPE_GIF:
            imagegif($dst, $path);
            break;
        case IMAGETYPE_WEBP:
            imagewebp($dst, $path, $quality);
            break;
    }
    
    imagedestroy($src);
    imagedestroy($dst);
    
    return ['width' => $new_width, 'height' => $new_height];
}

/**
 * Ajoute un filigrane à une image
 */
function add_watermark($path, $watermark_text = 'WideMaze', $position = 'bottom-right') {
    if (!extension_loaded('gd')) {
        return false;
    }
    
    $image = imagecreatefromstring(file_get_contents($path));
    if (!$image) return false;
    
    $width = imagesx($image);
    $height = imagesy($image);
    
    // Couleurs
    $black = imagecolorallocate($image, 0, 0, 0);
    $white = imagecolorallocate($image, 255, 255, 255);
    
    // Taille de police proportionnelle
    $font_size = 3;
    $text_width = imagefontwidth($font_size) * strlen($watermark_text);
    $text_height = imagefontheight($font_size);
    
    // Position
    switch ($position) {
        case 'top-left':
            $x = 10;
            $y = 10;
            break;
        case 'top-right':
            $x = $width - $text_width - 10;
            $y = 10;
            break;
        case 'bottom-left':
            $x = 10;
            $y = $height - $text_height - 10;
            break;
        case 'center':
            $x = ($width - $text_width) / 2;
            $y = ($height - $text_height) / 2;
            break;
        default: // bottom-right
            $x = $width - $text_width - 10;
            $y = $height - $text_height - 10;
    }
    
    // Ajouter une ombre pour la lisibilité
    imagestring($image, $font_size, $x + 1, $y + 1, $watermark_text, $black);
    imagestring($image, $font_size, $x, $y, $watermark_text, $white);
    
    // Sauvegarder
    imagejpeg($image, $path, 90);
    imagedestroy($image);
    
    return true;
}

/**
 * Récupère la durée d'une vidéo
 */
function get_video_duration($path) {
    if (!function_exists('exec')) {
        return null;
    }
    
    $command = "ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 " . escapeshellarg($path);
    $output = exec($command);
    return $output ? round(floatval($output)) : null;
}

/**
 * Récupère la durée d'un fichier audio
 */
function get_audio_duration($path) {
    if (!function_exists('exec')) {
        return null;
    }
    
    $command = "ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 " . escapeshellarg($path);
    $output = exec($command);
    return $output ? round(floatval($output)) : null;
}

/**
 * Récupère le bitrate d'un fichier audio
 */
function get_audio_bitrate($path) {
    if (!function_exists('exec')) {
        return null;
    }
    
    $command = "ffprobe -v error -show_entries format=bit_rate -of default=noprint_wrappers=1:nokey=1 " . escapeshellarg($path);
    $output = exec($command);
    return $output ? round(intval($output) / 1000) . ' kbps' : null;
}