<?php
/**
 * WideMaze - Messagerie Instantanée avec partage de fichiers et notes vocales
 * Version moderne avec fonctionnalités avancées
 */

require_once 'config.php';
require_auth();

$userId = $_SESSION['user_id'];
$selectedUser = isset($_GET['user']) ? intval($_GET['user']) : null;
$isMobile = strpos($_SERVER['HTTP_USER_AGENT'], 'Mobile') !== false;

// Traitement AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
    header('Content-Type: application/json');
    
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        echo json_encode(['error' => 'Token CSRF invalide']);
        exit();
    }
    
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'send_message':
            $to = intval($_POST['to'] ?? 0);
            $message = trim($_POST['message'] ?? '');
            $type = $_POST['type'] ?? 'text';
            
            if (empty($message) && $type === 'text' && empty($_FILES['file']) && empty($_POST['voice_data'])) {
                echo json_encode(['error' => 'Message vide']);
                exit();
            }
            
            $fileInfo = null;
            
            // Gestion des notes vocales (base64)
            if (!empty($_POST['voice_data']) && $_POST['voice_data'] !== 'undefined') {
                $voiceData = $_POST['voice_data'];
                if (preg_match('/^data:audio\/(\w+);base64,(.+)$/', $voiceData, $matches)) {
                    $audioBinary = base64_decode($matches[2]);
                    if ($audioBinary) {
                        $messageDir = UPLOAD_DIR . 'messages/';
                        if (!is_dir($messageDir)) mkdir($messageDir, 0755, true);
                        
                        $filename = uniqid() . '_voice_' . bin2hex(random_bytes(4)) . '.webm';
                        $destination = $messageDir . $filename;
                        
                        if (file_put_contents($destination, $audioBinary)) {
                            $fileInfo = [
                                'url' => $destination,
                                'name' => 'Note vocale.webm',
                                'size' => strlen($audioBinary),
                                'type' => 'audio',
                                'mime' => 'audio/webm',
                                'filename' => $filename,
                                'duration' => $_POST['voice_duration'] ?? 0
                            ];
                        }
                    }
                }
            }
            
            // Gestion de l'upload de fichier classique
            if (!$fileInfo && isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
                $file = $_FILES['file'];
                
                $allowedTypes = [
                    'image' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml'],
                    'video' => ['video/mp4', 'video/webm', 'video/ogg', 'video/quicktime'],
                    'document' => ['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 
                                   'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                                   'application/vnd.ms-powerpoint', 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                                   'text/plain', 'application/rtf', 'application/zip', 'application/x-rar-compressed'],
                    'audio' => ['audio/mpeg', 'audio/wav', 'audio/ogg', 'audio/mp4', 'audio/webm']
                ];
                $allAllowed = array_merge(...array_values($allowedTypes));
                
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime = finfo_file($finfo, $file['tmp_name']);
                finfo_close($finfo);
                
                if (!in_array($mime, $allAllowed)) {
                    echo json_encode(['error' => 'Type de fichier non autorisé']);
                    exit();
                }
                
                if ($file['size'] > 50 * 1024 * 1024) {
                    echo json_encode(['error' => 'Fichier trop volumineux (max 50MB)']);
                    exit();
                }
                
                $messageDir = UPLOAD_DIR . 'messages/';
                if (!is_dir($messageDir)) mkdir($messageDir, 0755, true);
                
                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                $filename = uniqid() . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
                $destination = $messageDir . $filename;
                
                if (move_uploaded_file($file['tmp_name'], $destination)) {
                    if (strpos($mime, 'image/') === 0) {
                        $fileType = 'image';
                    } elseif (strpos($mime, 'video/') === 0) {
                        $fileType = 'video';
                    } elseif (strpos($mime, 'audio/') === 0) {
                        $fileType = 'audio';
                    } else {
                        $fileType = 'document';
                    }
                    
                    $fileInfo = [
                        'url' => $destination,
                        'name' => $file['name'],
                        'size' => $file['size'],
                        'type' => $fileType,
                        'mime' => $mime,
                        'filename' => $filename
                    ];
                } else {
                    echo json_encode(['error' => 'Erreur lors du téléchargement']);
                    exit();
                }
            }
            
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO message (id_expediteur, id_destinataire, textemessage, datemessage, type, lu, file_url, file_name, file_type, file_size, file_duration, deleted_for_sender, deleted_for_receiver) 
                    VALUES (?, ?, ?, NOW(), ?, 0, ?, ?, ?, ?, ?, 0, 0)
                ");
                $stmt->execute([
                    $userId, $to, $message, $type,
                    $fileInfo ? $fileInfo['url'] : null,
                    $fileInfo ? $fileInfo['name'] : null,
                    $fileInfo ? $fileInfo['type'] : null,
                    $fileInfo ? $fileInfo['size'] : null,
                    $fileInfo ? ($fileInfo['duration'] ?? null) : null
                ]);
                $messageId = $pdo->lastInsertId();
                
                if ($to != $userId) {
                    $notificationType = $fileInfo ? 'file' : 'message';
                    $notificationContent = $fileInfo ? 'Nouveau fichier de @' . $_SESSION['surnom'] : 'Nouveau message de @' . $_SESSION['surnom'];
                    create_notification($pdo, $to, $notificationType, $notificationContent, $messageId, $userId);
                }
                
                echo json_encode([
                    'success' => true,
                    'message_id' => $messageId,
                    'message' => [
                        'id' => $messageId,
                        'text' => nl2br(htmlspecialchars($message)),
                        'time' => date('H:i'),
                        'type' => $type,
                        'is_mine' => true,
                        'is_self' => $to == $userId,
                        'file' => $fileInfo ? [
                            'url' => $fileInfo['url'],
                            'name' => $fileInfo['name'],
                            'size' => formatFileSize($fileInfo['size']),
                            'type' => $fileInfo['type'],
                            'mime' => $fileInfo['mime'],
                            'duration' => $fileInfo['duration'] ?? null
                        ] : null
                    ]
                ]);
            } catch (PDOException $e) {
                echo json_encode(['error' => 'Erreur d\'envoi: ' . $e->getMessage()]);
            }
            break;
            
        case 'delete_message':
            $messageId = intval($_POST['message_id'] ?? 0);
            $deleteFor = $_POST['delete_for'] ?? 'me';
            
            try {
                $stmt = $pdo->prepare("SELECT id_expediteur, id_destinataire, file_url FROM message WHERE idmessage = ?");
                $stmt->execute([$messageId]);
                $message = $stmt->fetch();
                
                if (!$message) {
                    echo json_encode(['error' => 'Message non trouvé']);
                    exit();
                }
                
                if ($message['id_expediteur'] != $userId) {
                    echo json_encode(['error' => 'Permission refusée']);
                    exit();
                }
                
                if ($deleteFor === 'everyone') {
                    if ($message['file_url'] && file_exists($message['file_url'])) {
                        unlink($message['file_url']);
                    }
                    $stmt = $pdo->prepare("DELETE FROM message WHERE idmessage = ?");
                    $stmt->execute([$messageId]);
                } else {
                    $stmt = $pdo->prepare("UPDATE message SET deleted_for_sender = 1 WHERE idmessage = ?");
                    $stmt->execute([$messageId]);
                }
                
                echo json_encode(['success' => true]);
            } catch (PDOException $e) {
                echo json_encode(['error' => 'Erreur lors de la suppression']);
            }
            break;
            
            case 'load_messages':
                $with = intval($_POST['with'] ?? 0);
                $before = intval($_POST['before'] ?? 0);
                $limit = min(intval($_POST['limit'] ?? 20), 50);
                
                try {
                    $sql = "
                        SELECT m.*, u.surnom, u.avatar, u.status
                        FROM message m
                        JOIN utilisateurs u ON m.id_expediteur = u.id
                        WHERE ((id_expediteur = ? AND id_destinataire = ?) 
                           OR (id_expediteur = ? AND id_destinataire = ?))
                           AND (deleted_for_sender = 0 OR deleted_for_sender IS NULL)
                    ";
                    $params = [$userId, $with, $with, $userId];
                    
                    if ($before > 0) {
                        $sql .= " AND m.idmessage < ?";
                        $params[] = $before;
                    }
                    
                    $sql .= " ORDER BY m.idmessage DESC LIMIT ?";
                    $params[] = $limit;
                    
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($params);
                    $messages = array_reverse($stmt->fetchAll());
                    
                    // Marquer comme lus UNIQUEMENT si ce n'est pas une conversation avec soi-même
                    if ($with != $userId) {
                        $stmt = $pdo->prepare("
                            UPDATE message SET lu = 1 
                            WHERE id_destinataire = ? AND id_expediteur = ? AND lu = 0
                        ");
                        $stmt->execute([$userId, $with]);
                    }
                    
                    echo json_encode([
                        'success' => true,
                        'messages' => array_map(function($msg) use ($userId) {
                            return [
                                'id' => $msg['idmessage'],
                                'text' => nl2br(htmlspecialchars($msg['textemessage'])),
                                'time' => date('H:i', strtotime($msg['datemessage'])),
                                'date' => date('d/m/Y', strtotime($msg['datemessage'])),
                                'is_mine' => $msg['id_expediteur'] == $userId,
                                'is_self' => $msg['id_expediteur'] == $msg['id_destinataire'],
                                'sender_name' => $msg['surnom'],
                                'sender_avatar' => $msg['avatar'],
                                'status' => $msg['status'],
                                'file' => $msg['file_url'] ? [
                                    'url' => $msg['file_url'],
                                    'name' => $msg['file_name'],
                                    'size' => formatFileSize($msg['file_size']),
                                    'type' => $msg['file_type'],
                                    'mime' => $msg['file_type'],
                                    'duration' => $msg['file_duration']
                                ] : null
                            ];
                        }, $messages),
                        'has_more' => count($messages) === $limit
                    ]);
                } catch (PDOException $e) {
                    echo json_encode(['error' => 'Erreur chargement messages']);
                }
                break;
            
        case 'mark_read':
            $with = intval($_POST['with'] ?? 0);
            if ($with != $userId) {
                try {
                    $stmt = $pdo->prepare("
                        UPDATE message SET lu = 1 
                        WHERE id_destinataire = ? AND id_expediteur = ? AND lu = 0
                    ");
                    $stmt->execute([$userId, $with]);
                } catch (PDOException $e) {
                    echo json_encode(['error' => 'Erreur']);
                    exit();
                }
            }
            echo json_encode(['success' => true]);
            break;
            
        case 'delete_conversation':
            $with = intval($_POST['with'] ?? 0);
            try {
                $stmt = $pdo->prepare("SELECT file_url FROM message WHERE (id_expediteur = ? AND id_destinataire = ?) OR (id_expediteur = ? AND id_destinataire = ?) AND file_url IS NOT NULL");
                $stmt->execute([$userId, $with, $with, $userId]);
                $files = $stmt->fetchAll();
                
                foreach ($files as $file) {
                    if (file_exists($file['file_url'])) {
                        unlink($file['file_url']);
                    }
                }
                
                $stmt = $pdo->prepare("
                    DELETE FROM message 
                    WHERE (id_expediteur = ? AND id_destinataire = ?) 
                       OR (id_expediteur = ? AND id_destinataire = ?)
                ");
                $stmt->execute([$userId, $with, $with, $userId]);
                echo json_encode(['success' => true]);
            } catch (PDOException $e) {
                echo json_encode(['error' => 'Erreur suppression']);
            }
            break;
            
        case 'typing':
            $to = intval($_POST['to'] ?? 0);
            $_SESSION['typing'][$to] = time();
            echo json_encode(['success' => true]);
            break;
    }
    exit();
}

// Récupération des conversations
$conversations = [];
try {
    $stmt = $pdo->prepare("
        SELECT DISTINCT 
            CASE 
                WHEN m.id_expediteur = ? AND m.id_destinataire != ? THEN m.id_destinataire
                WHEN m.id_destinataire = ? AND m.id_expediteur != ? THEN m.id_expediteur
                ELSE ?
            END as other_id,
            u.surnom, u.avatar, u.status, u.prenom, u.nom, u.universite,
            (SELECT textemessage FROM message 
             WHERE (id_expediteur = ? AND id_destinataire = other_id) 
                OR (id_expediteur = other_id AND id_destinataire = ?)
                OR (id_expediteur = ? AND id_destinataire = ?)
             ORDER BY idmessage DESC LIMIT 1) as last_message,
            (SELECT datemessage FROM message 
             WHERE (id_expediteur = ? AND id_destinataire = other_id) 
                OR (id_expediteur = other_id AND id_destinataire = ?)
                OR (id_expediteur = ? AND id_destinataire = ?)
             ORDER BY idmessage DESC LIMIT 1) as last_date,
            (SELECT COUNT(*) FROM message 
             WHERE id_destinataire = ? AND id_expediteur = other_id AND lu = 0) as unread_count
        FROM message m
        LEFT JOIN utilisateurs u ON u.id = CASE 
            WHEN m.id_expediteur = ? AND m.id_destinataire != ? THEN m.id_destinataire
            WHEN m.id_destinataire = ? AND m.id_expediteur != ? THEN m.id_expediteur
            ELSE ?
        END
        WHERE m.id_expediteur = ? OR m.id_destinataire = ?
        GROUP BY other_id, u.surnom, u.avatar, u.status, u.prenom, u.nom, u.universite
        ORDER BY last_date DESC
    ");
    
    $stmt->execute([
        $userId, $userId, $userId, $userId, $userId,
        $userId, $userId, $userId, $userId,
        $userId, $userId, $userId, $userId,
        $userId,
        $userId, $userId, $userId, $userId, $userId,
        $userId, $userId
    ]);
    $conversations = $stmt->fetchAll();
    
    $hasSelfConversation = false;
    foreach ($conversations as $conv) {
        if ($conv['other_id'] == $userId) {
            $hasSelfConversation = true;
            break;
        }
    }
    
    if (!$hasSelfConversation) {
        $selfConversation = [
            'other_id' => $userId,
            'surnom' => $_SESSION['surnom'],
            'avatar' => $_SESSION['avatar'] ?? 'default.jpg',
            'status' => 'Online',
            'prenom' => $_SESSION['prenom'],
            'nom' => $_SESSION['nom'],
            'universite' => $_SESSION['universite'] ?? '',
            'last_message' => '✏️ Vos notes personnelles',
            'last_date' => date('Y-m-d H:i:s'),
            'unread_count' => 0
        ];
        array_unshift($conversations, $selfConversation);
    }
    
} catch (PDOException $e) {
    error_log("Erreur conversations: " . $e->getMessage());
}

// Récupération de l'utilisateur sélectionné
$selectedUserInfo = null;
$isSelf = false;
if ($selectedUser) {
    if ($selectedUser == $userId) {
        $isSelf = true;
        $selectedUserInfo = [
            'id' => $userId,
            'surnom' => $_SESSION['surnom'],
            'avatar' => $_SESSION['avatar'] ?? 'default.jpg',
            'status' => 'Online',
            'prenom' => $_SESSION['prenom'],
            'nom' => $_SESSION['nom'],
            'universite' => $_SESSION['universite'] ?? ''
        ];
    } else {
        try {
            $stmt = $pdo->prepare("SELECT * FROM utilisateurs WHERE id = ?");
            $stmt->execute([$selectedUser]);
            $selectedUserInfo = $stmt->fetch();
        } catch (PDOException $e) {
            error_log("Erreur récupération utilisateur: " . $e->getMessage());
        }
    }
}

$totalUnread = 0;
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM message WHERE id_destinataire = ? AND lu = 0");
    $stmt->execute([$userId]);
    $totalUnread = $stmt->fetchColumn() ?: 0;
} catch (PDOException $e) {
    error_log("Erreur comptage non lus: " . $e->getMessage());
}

function formatFileSize($bytes) {
    if ($bytes === null) return '';
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    return round($bytes, 1) . ' ' . $units[$i];
}

function formatDuration($seconds) {
    if (!$seconds) return '';
    $minutes = floor($seconds / 60);
    $secs = $seconds % 60;
    return sprintf('%d:%02d', $minutes, $secs);
}

$csrfToken = generate_csrf_token();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <title>Messagerie - WideMaze</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { font-family: 'Inter', sans-serif; }
        
        @keyframes slideIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        @keyframes recordingPulse {
            0% { transform: scale(1); opacity: 1; }
            50% { transform: scale(1.2); opacity: 0.7; }
            100% { transform: scale(1); opacity: 1; }
        }
        
        .message-sent, .message-self { animation: slideIn 0.3s ease-out; }
        .message-received { animation: slideIn 0.3s ease-out; }
        .recording-pulse { animation: recordingPulse 1s infinite; }
        
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: #f1f1f1; border-radius: 10px; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
        
        .message-sent { background: linear-gradient(135deg, #f59e0b, #ea580c); color: white; border-radius: 20px 20px 4px 20px; }
        .message-self { background: linear-gradient(135deg, #10b981, #059669); color: white; border-radius: 20px 20px 4px 20px; }
        .message-received { background: #f1f5f9; color: #1e293b; border-radius: 20px 20px 20px 4px; }
        
        .voice-wave {
            display: inline-flex;
            align-items: center;
            gap: 2px;
            height: 24px;
        }
        .voice-wave span {
            width: 3px;
            background-color: currentColor;
            border-radius: 2px;
            animation: wave 0.8s ease-in-out infinite;
        }
        @keyframes wave {
            0%, 100% { height: 8px; }
            50% { height: 18px; }
        }
        
        @media (max-width: 768px) {
            .conversation-list { position: fixed; left: 0; top: 60px; width: 100%; height: calc(100% - 60px); z-index: 40; transform: translateX(0); transition: transform 0.3s ease; }
            .conversation-list.hidden-mobile { transform: translateX(-100%); }
            .chat-area { width: 100%; }
            .chat-area.hidden-mobile { display: none; }
        }
        
        .self-note-badge { background: linear-gradient(135deg, #10b981, #059669); }
        .file-card {
            background: rgba(255,255,255,0.1);
            border-radius: 12px;
            padding: 10px;
            transition: all 0.2s;
        }
        .file-card:hover { background: rgba(255,255,255,0.2); transform: translateY(-2px); }
        .audio-player { background: rgba(0,0,0,0.05); border-radius: 20px; padding: 8px 12px; display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
        .speed-btn { padding: 4px 8px; border-radius: 16px; font-size: 12px; transition: all 0.2s; cursor: pointer; }
        .speed-btn.active { background: #f59e0b; color: white; }
        .speed-btn:hover:not(.active) { background: rgba(0,0,0,0.1); }
        
        /* Menu contextuel pour suppression */
        .context-menu {
            position: fixed;
            background: white;
            border-radius: 12px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            z-index: 100;
            min-width: 200px;
            overflow: hidden;
            animation: fadeIn 0.15s ease-out;
        }
        .context-menu-item {
            padding: 12px 16px;
            display: flex;
            align-items: center;
            gap: 12px;
            cursor: pointer;
            transition: background 0.2s;
            font-size: 14px;
        }
        .context-menu-item:hover {
            background: #f3f4f6;
        }
        .context-menu-item.danger:hover {
            background: #fee2e2;
        }
        .context-menu-item.danger {
            color: #dc2626;
        }
        .context-menu-item i {
            width: 20px;
        }
    </style>
</head>
<body class="bg-gray-100 h-screen overflow-hidden">

    <!-- Navigation -->
    <nav class="fixed top-0 w-full bg-white shadow-md z-50 border-b border-gray-200">
        <div class="container mx-auto px-4 h-16 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <button id="mobileMenuBtn" class="lg:hidden p-2 hover:bg-gray-100 rounded-lg transition-colors">
                    <i class="fas fa-bars text-gray-600 text-xl"></i>
                </button>
                <a href="index.php" class="flex items-center gap-2">
                    <div class="w-10 h-10 bg-gradient-to-br from-orange-500 to-red-600 rounded-lg flex items-center justify-center">
                        <i class="fas fa-network-wired text-white"></i>
                    </div>
                    <span class="text-2xl font-bold text-gray-800 hidden sm:block">WideMaze</span>
                </a>
            </div>
            
            <div class="flex items-center gap-4">
                <div class="relative">
                    <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
                    <input type="text" id="searchConversations" placeholder="Rechercher..." 
                        class="pl-9 pr-4 py-2 bg-gray-100 rounded-full text-sm focus:outline-none focus:ring-2 focus:ring-orange-500 w-40 md:w-64">
                </div>
                <a href="notifications.php" class="p-2 hover:bg-gray-100 rounded-full transition-colors relative">
                    <i class="fas fa-bell text-gray-600"></i>
                </a>
                <a href="profil.php" class="flex items-center gap-2">
                    <img src="<?= getAvatarUrl($conv['avatar'] ?? DEFAULT_AVATAR) ?>" class="w-8 h-8 rounded-full object-cover border-2 border-orange-500">
                </a>
            </div>
        </div>
    </nav>

    <div class="container mx-auto pt-16 h-screen">
        <div class="flex h-full">
            
            <!-- Liste des conversations -->
            <div id="conversationList" class="conversation-list w-full md:w-96 bg-white border-r border-gray-200 flex flex-col h-full">
                <div class="p-4 border-b border-gray-200 bg-white sticky top-0 z-10">
                    <div class="flex items-center justify-between">
                        <h2 class="text-xl font-bold text-gray-800">Messages</h2>
                        <?php if ($totalUnread > 0): ?>
                            <span class="px-2 py-1 bg-orange-500 text-white text-xs rounded-full"><?= $totalUnread ?> non lus</span>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="flex-1 overflow-y-auto">
                    <?php if (empty($conversations)): ?>
                        <div class="flex flex-col items-center justify-center h-64 text-gray-400">
                            <i class="fas fa-comments text-5xl mb-4"></i>
                            <p class="text-lg font-medium">Aucune conversation</p>
                            <p class="text-sm">Commencez à discuter avec vos amis ou avec vous-même !</p>
                            <div class="flex gap-3 mt-4">
                                <a href="recherche.php" class="text-orange-500 hover:text-orange-600">
                                    <i class="fas fa-search mr-1"></i>Trouver des amis
                                </a>
                                <button onclick="startSelfChat()" class="text-green-500 hover:text-green-600">
                                    <i class="fas fa-user-edit mr-1"></i>Notes personnelles
                                </button>
                            </div>
                        </div>
                    <?php else: ?>
                        <?php foreach ($conversations as $conv): 
                            $lastDate = $conv['last_date'] ? date('d/m/Y', strtotime($conv['last_date'])) : '';
                            $today = date('d/m/Y');
                            $displayDate = $lastDate === $today ? 'Aujourd\'hui' : $lastDate;
                            $isSelfConv = $conv['other_id'] == $userId;
                        ?>
                            <div class="conversation-item flex items-center gap-3 p-4 hover:bg-gray-50 cursor-pointer transition-all border-b border-gray-100 <?= $selectedUser == $conv['other_id'] ? 'bg-orange-50' : '' ?>" 
                                 data-user-id="<?= $conv['other_id'] ?>" data-user-name="<?= htmlspecialchars($conv['surnom']) ?>" data-user-avatar="<?= $conv['avatar'] ?>" data-user-status="<?= $conv['status'] ?>">
                                <div class="relative">
                                    <div class="w-14 h-14 rounded-full flex items-center justify-center <?= $isSelfConv ? 'self-note-badge' : '' ?>">
                                        <img src="<?= AVATAR_URL . htmlspecialchars($conv['avatar'] ?? 'default.jpg') ?>" class="w-14 h-14 rounded-full object-cover border-2 <?= $isSelfConv ? 'border-green-500' : 'border-gray-200' ?>">
                                    </div>
                                    <?php if (!$isSelfConv && $conv['status'] === 'Online'): ?>
                                        <span class="absolute bottom-0 right-0 w-3.5 h-3.5 bg-green-500 rounded-full border-2 border-white"></span>
                                    <?php elseif ($isSelfConv): ?>
                                        <span class="absolute bottom-0 right-0 w-3.5 h-3.5 bg-green-500 rounded-full border-2 border-white" title="Vous"></span>
                                    <?php endif; ?>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center justify-between">
                                        <p class="font-semibold text-gray-800 truncate flex items-center gap-1">
                                            <?= $isSelfConv ? '<i class="fas fa-user-edit text-green-500 text-xs"></i>' : '' ?>
                                            <?= htmlspecialchars($isSelfConv ? '📝 ' . $conv['surnom'] . ' (Notes)' : $conv['prenom'] . ' ' . $conv['nom']) ?>
                                        </p>
                                        <p class="text-xs text-gray-400"><?= $displayDate ?></p>
                                    </div>
                                    <p class="text-sm <?= $conv['unread_count'] > 0 ? 'font-semibold text-gray-800' : 'text-gray-500' ?> truncate">
                                        <?= htmlspecialchars(substr($conv['last_message'] ?? '', 0, 50)) ?>
                                    </p>
                                </div>
                                <?php if ($conv['unread_count'] > 0 && !$isSelfConv): ?>
                                    <span class="w-6 h-6 bg-orange-500 text-white text-xs rounded-full flex items-center justify-center font-bold"><?= min($conv['unread_count'], 99) ?></span>

                                <?php elseif ($isSelfConv): ?>
                                    <i class="fas fa-lock text-gray-300 text-xs" title="Messages privés"></i>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Zone de chat -->
            <div id="chatArea" class="chat-area flex-1 flex flex-col bg-gray-50 <?= !$selectedUser ? 'hidden-mobile' : '' ?>">
                <?php if ($selectedUser && $selectedUserInfo): ?>
                    <div class="bg-white border-b border-gray-200 p-4 flex items-center justify-between sticky top-16 z-10">
                        <div class="flex items-center gap-3">
                            <button id="mobileBackBtn" class="lg:hidden p-2 hover:bg-gray-100 rounded-full transition-colors">
                                <i class="fas fa-arrow-left text-gray-600"></i>
                            </button>
                            <div class="relative">
                                <div class="w-12 h-12 rounded-full flex items-center justify-center <?= $isSelf ? 'self-note-badge' : '' ?>">
                                    <img src="<?= AVATAR_URL . htmlspecialchars($selectedUserInfo['avatar'] ?? 'default.jpg') ?>" class="w-12 h-12 rounded-full object-cover border-2 <?= $isSelf ? 'border-green-500' : 'border-orange-500' ?>">
                                </div>
                                <?php if (!$isSelf && $selectedUserInfo['status'] === 'Online'): ?>
                                    <span class="absolute bottom-0 right-0 w-3.5 h-3.5 bg-green-500 rounded-full border-2 border-white"></span>
                                <?php elseif ($isSelf): ?>
                                    <span class="absolute bottom-0 right-0 w-3.5 h-3.5 bg-green-500 rounded-full border-2 border-white" title="Vous"></span>
                                <?php endif; ?>
                            </div>
                            <div>
                                <p class="font-bold text-gray-800 text-lg flex items-center gap-2">
                                    <?php if ($isSelf): ?>
                                        <i class="fas fa-user-edit text-green-500"></i>
                                        <span>Notes personnelles</span>
                                    <?php else: ?>
                                        <?= htmlspecialchars($selectedUserInfo['prenom'] . ' ' . $selectedUserInfo['nom']) ?>
                                    <?php endif; ?>
                                </p>
                                <p class="text-xs <?= $isSelf ? 'text-green-600' : ($selectedUserInfo['status'] === 'Online' ? 'text-green-600' : 'text-gray-400') ?>">
                                    <?php if ($isSelf): ?>
                                        <i class="fas fa-lock mr-1"></i>Messages privés - seulement vous
                                    <?php else: ?>
                                        <?= $selectedUserInfo['status'] === 'Online' ? 'En ligne' : 'Hors ligne' ?>
                                        <?php if (!empty($selectedUserInfo['universite'])): ?>
                                            <span class="mx-1">•</span>
                                            <?= htmlspecialchars($selectedUserInfo['universite']) ?>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </p>
                            </div>
                        </div>
                        <div class="flex items-center gap-2">
                            <?php if (!$isSelf): ?>
                            <a href="profil.php?id=<?= $selectedUser ?>" class="p-2 hover:bg-gray-100 rounded-full transition-colors" title="Voir profil">
                                <i class="fas fa-user text-gray-600"></i>
                            </a>
                            <?php endif; ?>
                            <button onclick="showChatActions()" class="p-2 hover:bg-gray-100 rounded-full transition-colors">
                                <i class="fas fa-ellipsis-v text-gray-600"></i>
                            </button>
                        </div>
                    </div>
                    
                    <div id="messagesContainer" class="flex-1 overflow-y-auto p-4 space-y-3" data-user-id="<?= $selectedUser ?>">
                        <div class="text-center py-8">
                            <div class="inline-block w-8 h-8 border-3 border-orange-500 border-t-transparent rounded-full animate-spin"></div>
                            <p class="text-gray-500 mt-2">Chargement des messages...</p>
                        </div>
                    </div>
                    
                    <?php if (!$isSelf): ?>
                    <div id="typingIndicator" class="px-4 py-2 hidden">
                        <div class="typing-indicator flex items-center gap-1 bg-gray-200 rounded-full px-3 py-2 w-fit">
                            <span class="w-2 h-2 bg-gray-500 rounded-full"></span>
                            <span class="w-2 h-2 bg-gray-500 rounded-full"></span>
                            <span class="w-2 h-2 bg-gray-500 rounded-full"></span>
                            <span class="text-xs text-gray-500 ml-1">est en train d'écrire...</span>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <div class="bg-white border-t border-gray-200 p-4">
                        <div class="flex items-end gap-2">
                            <!-- Bouton d'enregistrement vocal -->
                            <div class="relative">
                                <button id="voiceRecordBtn" onclick="toggleVoiceRecording()" class="p-2 hover:bg-gray-100 rounded-full transition-colors group" title="Note vocale">
                                    <i class="fas fa-microphone text-gray-400 group-hover:text-red-500 text-xl"></i>
                                </button>
                                <div id="recordingIndicator" class="absolute -top-1 -right-1 hidden">
                                    <span class="relative flex h-3 w-3">
                                        <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-red-400 opacity-75"></span>
                                        <span class="relative inline-flex rounded-full h-3 w-3 bg-red-500"></span>
                                    </span>
                                </div>
                            </div>
                            
                            <!-- Bouton de pièce jointe avec menu de types -->
                            <div class="relative">
                                <button id="fileMenuBtn" onclick="toggleFileMenu()" class="p-2 hover:bg-gray-100 rounded-full transition-colors group" title="Joindre un fichier">
                                    <i class="fas fa-paperclip text-gray-400 group-hover:text-orange-500 text-xl"></i>
                                </button>
                                <div id="fileMenu" class="absolute bottom-full left-0 mb-2 bg-white rounded-xl shadow-xl border border-gray-200 hidden w-48 z-20">
                                    <div class="p-2 space-y-1">
                                        <button onclick="selectFileType('image')" class="flex items-center gap-3 w-full px-3 py-2 hover:bg-gray-50 rounded-lg"><i class="fas fa-image text-green-500 w-5"></i><span>Photo</span></button>
                                        <button onclick="selectFileType('video')" class="flex items-center gap-3 w-full px-3 py-2 hover:bg-gray-50 rounded-lg"><i class="fas fa-video text-red-500 w-5"></i><span>Vidéo</span></button>
                                        <button onclick="selectFileType('document')" class="flex items-center gap-3 w-full px-3 py-2 hover:bg-gray-50 rounded-lg"><i class="fas fa-file-pdf text-blue-500 w-5"></i><span>Document</span></button>
                                        <button onclick="selectFileType('audio')" class="flex items-center gap-3 w-full px-3 py-2 hover:bg-gray-50 rounded-lg"><i class="fas fa-music text-purple-500 w-5"></i><span>Audio</span></button>
                                    </div>
                                </div>
                                <input type="file" id="fileInput" class="hidden">
                            </div>
                            
                            <div class="flex-1 relative">
                                <textarea id="messageInput" rows="1" placeholder="<?= $isSelf ? 'Écrivez une note pour vous-même...' : 'Écrivez votre message...' ?>" 
                                    class="w-full px-4 py-3 border border-gray-200 rounded-2xl focus:outline-none focus:border-orange-500 focus:ring-2 focus:ring-orange-100 resize-none transition-all"
                                    onkeydown="handleKeyPress(event)" style="max-height: 120px;"></textarea>
                                <div id="emojiPicker" class="absolute bottom-full left-0 mb-2 bg-white rounded-xl shadow-xl border border-gray-200 hidden w-80 p-3 z-20">
                                    <div class="grid grid-cols-8 gap-2 max-h-48 overflow-y-auto">
                                        <?php
                                        $emojis = ['😀', '😃', '😄', '😁', '😆', '😅', '😂', '🤣', '😊', '😇', '🙂', '🙃', '😉', '😌', '😍', '🥰', '😘', '😗', '😙', '😚', '😋', '😛', '😝', '😜', '🤪', '🤨', '🧐', '🤓', '😎', '🤩', '🥳', '😏', '😒', '😞', '😔', '😟', '😕', '🙁', '☹️', '😣', '😖', '😫', '😩', '🥺', '😢', '😭', '😤', '😠', '😡', '🤬', '🤯', '😳', '🥵', '🥶', '😱', '😨', '😰', '😥', '😓', '🤗', '🤔', '🤭', '🤫', '🤥', '😶', '😐', '😑', '😬', '🙄', '😯', '😦', '😧', '😮', '😲', '🥱', '😴', '🤤', '😪', '😵', '🤐', '🥴', '🤢', '🤮', '🤧', '😷', '🤒', '🤕', '🤑', '🤠', '😈', '👿', '👹', '👺', '🤡', '💩', '👻', '💀', '☠️', '👽', '👾', '🤖', '🎃', '😺', '😸', '😹', '😻', '😼', '😽', '🙀', '😿', '😾'];
                                        foreach ($emojis as $emoji): ?>
                                            <button type="button" onclick="addEmoji('<?= $emoji ?>')" class="text-2xl hover:bg-gray-100 rounded p-1 transition-colors"><?= $emoji ?></button>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                            <button onclick="addEmojiPicker()" class="p-2 hover:bg-gray-100 rounded-full transition-colors group">
                                <i class="far fa-smile text-gray-400 group-hover:text-orange-500 text-xl"></i>
                            </button>
                            <button onclick="sendMessage()" id="sendBtn" class="<?= $isSelf ? 'bg-green-500 hover:bg-green-600' : 'bg-orange-500 hover:bg-orange-600' ?> text-white p-3 rounded-full transition-all hover:scale-105 active:scale-95 shadow-md">
                                <i class="fas fa-paper-plane"></i>
                            </button>
                        </div>
                        
                        <!-- Aperçu du fichier sélectionné -->
                        <div id="filePreview" class="mt-3 hidden">
                            <div class="bg-gray-100 rounded-lg p-3 flex items-center justify-between">
                                <div class="flex items-center gap-2">
                                    <i class="fas fa-file text-gray-500"></i>
                                    <span id="fileName" class="text-sm truncate max-w-[200px]"></span>
                                    <span id="fileSize" class="text-xs text-gray-400"></span>
                                </div>
                                <button onclick="cancelFile()" class="text-red-500 hover:text-red-600">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                            <button onclick="sendFile()" class="mt-2 w-full bg-orange-500 text-white py-1 rounded-lg text-sm hover:bg-orange-600">
                                Envoyer le fichier
                            </button>
                        </div>
                        
                        <!-- Indicateur d'enregistrement vocal -->
                        <div id="voicePreview" class="mt-3 hidden">
                            <div class="bg-red-50 rounded-lg p-3 flex items-center justify-between">
                                <div class="flex items-center gap-3">
                                    <div class="voice-wave">
                                        <span></span><span></span><span></span><span></span><span></span>
                                    </div>
                                    <span class="text-sm text-red-600">Enregistrement en cours...</span>
                                    <span id="recordingTime" class="text-xs text-red-500">0:00</span>
                                </div>
                                <button onclick="stopRecording()" class="bg-red-500 text-white px-3 py-1 rounded-lg text-sm">
                                    Arrêter
                                </button>
                            </div>
                        </div>
                        
                        <p class="text-xs text-gray-400 mt-2 text-center">
                            <i class="fas fa-lock text-[10px] mr-1"></i><?= $isSelf ? 'Ces notes sont privées et visibles uniquement par vous' : 'Messages chiffrés de bout en bout' ?>
                        </p>
                    </div>
                    
                <?php else: ?>
                    <div class="flex-1 flex items-center justify-center text-gray-400">
                        <div class="text-center">
                            <div class="w-32 h-32 bg-gradient-to-br from-orange-100 to-red-100 rounded-full flex items-center justify-center mx-auto mb-6">
                                <i class="fas fa-comments text-5xl text-orange-500"></i>
                            </div>
                            <h3 class="text-2xl font-bold text-gray-700 mb-2">Votre messagerie</h3>
                            <p class="text-gray-500 mb-6">Sélectionnez une conversation pour commencer à discuter</p>
                            <div class="flex flex-col sm:flex-row gap-3 justify-center">
                                <a href="recherche.php" class="inline-flex items-center gap-2 bg-orange-500 text-white px-6 py-3 rounded-xl hover:bg-orange-600 transition-colors shadow-md">
                                    <i class="fas fa-search"></i>
                                    <span>Trouver des amis</span>
                                </a>
                                <button onclick="startSelfChat()" class="inline-flex items-center gap-2 bg-green-500 text-white px-6 py-3 rounded-xl hover:bg-green-600 transition-colors shadow-md">
                                    <i class="fas fa-user-edit"></i>
                                    <span>Notes personnelles</span>
                                </button>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <div id="chatActionsModal" class="fixed inset-0 bg-black/50 backdrop-blur-sm z-50 hidden items-center justify-center p-4">
        <div class="bg-white rounded-2xl w-full max-w-sm shadow-2xl transform transition-all duration-300">
            <div class="p-4 border-b border-gray-100">
                <h3 class="font-bold text-lg">Actions</h3>
            </div>
            <div class="p-2">
                <?php if (!$isSelf): ?>
                <button onclick="clearConversation()" class="flex items-center gap-3 w-full px-4 py-3 hover:bg-red-50 rounded-xl transition-colors text-red-600">
                    <i class="fas fa-trash-alt w-5"></i>
                    <span>Supprimer la conversation</span>
                </button>
                <a href="profil.php?id=<?= $selectedUser ?>" class="flex items-center gap-3 w-full px-4 py-3 hover:bg-gray-50 rounded-xl transition-colors text-gray-700">
                    <i class="fas fa-user w-5"></i>
                    <span>Voir le profil</span>
                </a>
                <?php else: ?>
                <div class="p-4 text-center">
                    <i class="fas fa-lock text-4xl text-green-500 mb-2"></i>
                    <p class="text-gray-600">Vos notes personnelles sont privées</p>
                    <p class="text-sm text-gray-400 mt-1">Elles sont visibles uniquement par vous</p>
                </div>
                <?php endif; ?>
            </div>
            <div class="p-4 border-t border-gray-100">
                <button onclick="closeChatActions()" class="w-full px-4 py-2 bg-gray-100 hover:bg-gray-200 rounded-xl transition-colors">Fermer</button>
            </div>
        </div>
    </div>
    
    <script>
        // Configuration
        const currentUserId = <?= $userId ?>;
        const csrfToken = '<?= $csrfToken ?>';
        let selectedUserId = <?= $selectedUser ?: 0 ?>;
        let isSelfChat = <?= $isSelf ? 'true' : 'false' ?>;
        let lastMessageId = 0;
        let isLoading = false;
        let hasMoreMessages = true;
        let typingTimeout = null;
        let isTyping = false;
        let pollInterval = null;
        let selectedFile = null;
        
        // Variables pour l'enregistrement vocal
        let mediaRecorder = null;
        let audioChunks = [];
        let isRecording = false;
        let recordingStartTime = null;
        let recordingTimer = null;
        
        // Initialisation
        document.addEventListener('DOMContentLoaded', () => {
            if (selectedUserId) {
                loadMessages();
                if (!isSelfChat) {
                    startPolling();
                    markMessagesAsRead();
                }
            }
            setupConversationClick();
            setupSearch();
            setupMobileNav();
        });
        
        function startPolling() {
            if (pollInterval) clearInterval(pollInterval);
            pollInterval = setInterval(() => {
                if (selectedUserId && !isSelfChat) {
                    checkNewMessages();
                }
            }, 3000);
        }
        
        async function checkNewMessages() {
            try {
                const response = await fetch('', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({
                        ajax: 1,
                        action: 'load_messages',
                        with: selectedUserId,
                        before: 0,
                        limit: 5,
                        csrf_token: csrfToken
                    })
                });
                const data = await response.json();
                if (data.success && data.messages.length > 0) {
                    const lastMsg = data.messages[data.messages.length - 1];
                    if (lastMsg.id > lastMessageId) {
                        data.messages.forEach(msg => {
                            if (msg.id > lastMessageId && !msg.is_mine) {
                                appendMessage(msg);
                            }
                        });
                        lastMessageId = data.messages[data.messages.length - 1]?.id || 0;
                        scrollToBottom();
                        markMessagesAsRead();
                    }
                }
            } catch (err) {
                console.error('Erreur polling:', err);
            }
        }
        
        async function loadMessages(before = 0) {
            if (isLoading) return;
            isLoading = true;
            
            try {
                const response = await fetch('', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({
                        ajax: 1,
                        action: 'load_messages',
                        with: selectedUserId,
                        before: before,
                        limit: 20,
                        csrf_token: csrfToken
                    })
                });
                const data = await response.json();
                
                if (data.success) {
                    const container = document.getElementById('messagesContainer');
                    
                    if (before === 0) {
                        container.innerHTML = '';
                        lastMessageId = 0;
                    }
                    
                    data.messages.forEach(msg => {
                        prependMessage(msg);
                        if (msg.id > lastMessageId) lastMessageId = msg.id;
                    });
                    
                    hasMoreMessages = data.has_more;
                    
                    if (before === 0) {
                        scrollToBottom();
                    }
                    
                    if (hasMoreMessages && !before) {
                        addLoadMoreTrigger();
                    }
                }
            } catch (err) {
                console.error('Erreur chargement:', err);
            } finally {
                isLoading = false;
            }
        }
        
        // Fonction pour compter les messages non lus au chargement
function countUnreadMessages() {
    let totalUnread = 0;
    document.querySelectorAll('.conversation-item .bg-orange-500').forEach(badge => {
        totalUnread += parseInt(badge.textContent) || 0;
    });
    return totalUnread;
}

// Mettre à jour le badge du header
function updateHeaderUnreadBadge() {
    const totalUnread = countUnreadMessages();
    const headerBadge = document.querySelector('#conversationList .bg-orange-500');
    if (headerBadge) {
        if (totalUnread > 0) {
            headerBadge.textContent = totalUnread > 99 ? '99+' : totalUnread;
            headerBadge.classList.remove('hidden');
        } else {
            headerBadge.classList.add('hidden');
        }
    }
}


        function addLoadMoreTrigger() {
            const container = document.getElementById('messagesContainer');
            const trigger = document.createElement('div');
            trigger.id = 'loadMoreTrigger';
            trigger.className = 'text-center py-2 cursor-pointer hover:bg-gray-100 rounded-lg';
            trigger.innerHTML = '<i class="fas fa-chevron-up text-gray-400"></i><span class="text-xs text-gray-500 ml-2">Charger plus de messages</span>';
            trigger.onclick = () => {
                const firstMsg = document.querySelector('.message-item:first-child');
                const firstId = firstMsg?.dataset?.messageId;
                if (firstId) loadMessages(parseInt(firstId));
                trigger.remove();
            };
            container.insertBefore(trigger, container.firstChild);
        }
        
        function appendMessage(msg) {
            const container = document.getElementById('messagesContainer');
            const messageHtml = createMessageHtml(msg);
            container.insertAdjacentHTML('beforeend', messageHtml);
        }
        
        function prependMessage(msg) {
            const container = document.getElementById('messagesContainer');
            const messageHtml = createMessageHtml(msg);
            const loadMoreTrigger = document.getElementById('loadMoreTrigger');
            if (loadMoreTrigger) {
                loadMoreTrigger.insertAdjacentHTML('afterend', messageHtml);
            } else {
                container.insertAdjacentHTML('afterbegin', messageHtml);
            }
        }
        
        function createMessageHtml(msg) {
            const isMine = msg.is_mine;
            const isSelf = msg.is_self;
            const bubbleClass = isSelf ? 'message-self' : (isMine ? 'message-sent' : 'message-received');
            
            let fileHtml = '';
            if (msg.file) {
                if (msg.file.type === 'image') {
                    fileHtml = `
                        <div class="mt-2 cursor-pointer" onclick="window.open('${msg.file.url}', '_blank')">
                            <img src="${msg.file.url}" class="max-w-full max-h-64 rounded-lg object-cover" alt="Image">
                        </div>
                    `;
                } else if (msg.file.type === 'video') {
                    fileHtml = `
                        <div class="mt-2">
                            <video controls class="max-w-full max-h-64 rounded-lg">
                                <source src="${msg.file.url}" type="${msg.file.mime}">
                            </video>
                        </div>
                    `;
                } else if (msg.file.type === 'audio') {
                    const duration = msg.file.duration ? formatDuration(parseInt(msg.file.duration)) : '';
                    fileHtml = `
                        <div class="mt-2 audio-player">
                            <audio controls class="flex-1" style="min-width: 150px;">
                                <source src="${msg.file.url}" type="${msg.file.mime}">
                            </audio>
                            <div class="flex gap-1">
                                <button class="speed-btn ${!msg.speed || msg.speed === 1 ? 'active' : ''}" onclick="setPlaybackSpeed(this, 1.0, event)">1x</button>
                                <button class="speed-btn ${msg.speed === 1.5 ? 'active' : ''}" onclick="setPlaybackSpeed(this, 1.5, event)">1.5x</button>
                                <button class="speed-btn ${msg.speed === 2 ? 'active' : ''}" onclick="setPlaybackSpeed(this, 2.0, event)">2x</button>
                            </div>
                            ${duration ? `<span class="text-xs opacity-75 ml-auto">${duration}</span>` : ''}
                        </div>
                    `;
                } else {
                    fileHtml = `
                        <a href="${msg.file.url}" download="${msg.file.name}" class="file-card block mt-2 p-3 ${isMine || isSelf ? 'bg-white/20' : 'bg-gray-100'} rounded-lg hover:opacity-90 transition-opacity">
                            <div class="flex items-center gap-3">
                                <i class="fas ${msg.file.type === 'document' ? 'fa-file-pdf' : 'fa-file'} text-2xl"></i>
                                <div class="flex-1 min-w-0">
                                    <p class="text-sm font-medium truncate">${escapeHtml(msg.file.name)}</p>
                                    <p class="text-xs opacity-75">${msg.file.size}</p>
                                </div>
                                <i class="fas fa-download text-sm"></i>
                            </div>
                        </a>
                    `;
                }
            }
            
            return `
                <div class="message-item flex ${isMine ? 'justify-end' : 'justify-start'} animate-fadeIn" data-message-id="${msg.id}" oncontextmenu="showMessageContextMenu(event, ${msg.id}, ${msg.is_mine})">
                    ${!isMine && !isSelf ? `
                        <img src="<?= AVATAR_URL ?>${msg.sender_avatar || 'default.jpg'}" class="w-8 h-8 rounded-full object-cover mr-2 self-end">
                    ` : ''}
                    <div class="message-bubble max-w-[70%] ${bubbleClass} p-3">
                        ${msg.text ? `<p class="text-sm ${isMine || isSelf ? 'text-white' : 'text-gray-800'} break-words">${msg.text}</p>` : ''}
                        ${fileHtml}
                        <p class="text-xs ${isMine ? 'text-orange-200' : (isSelf ? 'text-green-200' : 'text-gray-400')} mt-1 flex items-center gap-1">
                            ${msg.time}
                            ${isMine ? '<i class="fas fa-check-double text-[10px]"></i>' : ''}
                        </p>
                    </div>
                </div>
            `;
        }
        
        // Menu contextuel pour suppression
        function showMessageContextMenu(event, messageId, isMine) {
            event.preventDefault();
            
            const existingMenu = document.getElementById('dynamicContextMenu');
            if (existingMenu) existingMenu.remove();
            
            const menu = document.createElement('div');
            menu.id = 'dynamicContextMenu';
            menu.className = 'context-menu';
            menu.style.left = event.pageX + 'px';
            menu.style.top = event.pageY + 'px';
            
            let menuHTML = `
                <div class="context-menu-item" onclick="deleteMessage(${messageId}, 'me')">
                    <i class="fas fa-trash-alt"></i>
                    <span>Supprimer pour moi</span>
                </div>
            `;
            
            if (isMine) {
                menuHTML += `
                    <div class="context-menu-item danger" onclick="deleteMessage(${messageId}, 'everyone')">
                        <i class="fas fa-trash-alt"></i>
                        <span>Supprimer pour tout le monde</span>
                    </div>
                `;
            }
            
            menu.innerHTML = menuHTML;
            document.body.appendChild(menu);
            
            setTimeout(() => {
                document.addEventListener('click', function closeMenu(e) {
                    if (!menu.contains(e.target)) {
                        menu.remove();
                        document.removeEventListener('click', closeMenu);
                    }
                });
            }, 0);
        }
        
        // Fonction de suppression
        async function deleteMessage(messageId, deleteFor) {
            try {
                const response = await fetch('', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({
                        ajax: 1,
                        action: 'delete_message',
                        message_id: messageId,
                        delete_for: deleteFor,
                        csrf_token: csrfToken
                    })
                });
                const data = await response.json();
                
                if (data.success) {
                    const messageElement = document.querySelector(`.message-item[data-message-id="${messageId}"]`);
                    if (messageElement) {
                        messageElement.remove();
                    }
                    showToast(deleteFor === 'everyone' ? 'Message supprimé pour tout le monde' : 'Message supprimé pour vous', 'success');
                } else {
                    alert(data.error || 'Erreur lors de la suppression');
                }
            } catch (err) {
                console.error('Erreur:', err);
                alert('Erreur de connexion');
            }
        }
        
        // Fonction pour afficher un toast de confirmation
        function showToast(message, type = 'info') {
            const container = document.getElementById('toastContainer');
            if (!container) {
                const div = document.createElement('div');
                div.id = 'toastContainer';
                div.className = 'fixed bottom-4 right-4 z-50 space-y-2';
                document.body.appendChild(div);
            }
            
            const toast = document.createElement('div');
            const colors = {
                success: 'bg-green-500',
                error: 'bg-red-500',
                info: 'bg-blue-500'
            };
            
            toast.className = `${colors[type]} text-white px-4 py-2 rounded-lg shadow-lg flex items-center gap-2 animate-fadeIn text-sm`;
            toast.innerHTML = `<i class="fas ${type === 'success' ? 'fa-check-circle' : 'fa-info-circle'}"></i> ${message}`;
            
            document.getElementById('toastContainer').appendChild(toast);
            
            setTimeout(() => {
                toast.style.opacity = '0';
                toast.style.transform = 'translateX(20px)';
                setTimeout(() => toast.remove(), 300);
            }, 3000);
        }
        
        // Fonction pour changer la vitesse de lecture
        function setPlaybackSpeed(btn, speed, event) {
            if (event) event.stopPropagation();
            const audio = btn.closest('.audio-player')?.querySelector('audio');
            if (audio) {
                audio.playbackRate = speed;
                const container = btn.closest('.flex');
                container.querySelectorAll('.speed-btn').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
            }
        }
        
        // Enregistrement vocal
        async function toggleVoiceRecording() {
            if (isRecording) {
                stopRecording();
            } else {
                startRecording();
            }
        }
        
        async function startRecording() {
            try {
                const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
                mediaRecorder = new MediaRecorder(stream, { mimeType: 'audio/webm' });
                audioChunks = [];
                
                mediaRecorder.ondataavailable = (event) => {
                    audioChunks.push(event.data);
                };
                
                mediaRecorder.onstop = async () => {
                    const audioBlob = new Blob(audioChunks, { type: 'audio/webm' });
                    const reader = new FileReader();
                    reader.onloadend = async () => {
                        const base64Audio = reader.result;
                        const duration = Math.floor((Date.now() - recordingStartTime) / 1000);
                        
                        const formData = new FormData();
                        formData.append('ajax', 1);
                        formData.append('action', 'send_message');
                        formData.append('to', selectedUserId);
                        formData.append('message', '');
                        formData.append('type', 'voice');
                        formData.append('voice_data', base64Audio);
                        formData.append('voice_duration', duration);
                        formData.append('csrf_token', csrfToken);
                        
                        const sendBtn = document.getElementById('sendBtn');
                        sendBtn.disabled = true;
                        sendBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
                        
                        try {
                            const response = await fetch('', { method: 'POST', body: formData });
                            const data = await response.json();
                            
                            if (data.success) {
                                appendMessage(data.message);
                                scrollToBottom();
                                lastMessageId = data.message_id;
                            } else {
                                alert(data.error || 'Erreur d\'envoi');
                            }
                        } catch (err) {
                            console.error('Erreur:', err);
                            alert('Erreur de connexion');
                        } finally {
                            sendBtn.disabled = false;
                            sendBtn.innerHTML = '<i class="fas fa-paper-plane"></i>';
                        }
                        
                        stream.getTracks().forEach(track => track.stop());
                    };
                    reader.readAsDataURL(audioBlob);
                };
                
                mediaRecorder.start();
                isRecording = true;
                recordingStartTime = Date.now();
                
                document.getElementById('voiceRecordBtn').innerHTML = '<i class="fas fa-stop text-red-500 text-xl"></i>';
                document.getElementById('recordingIndicator').classList.remove('hidden');
                document.getElementById('voicePreview').classList.remove('hidden');
                
                recordingTimer = setInterval(updateRecordingTime, 1000);
                
            } catch (err) {
                console.error('Erreur d\'accès au microphone:', err);
                alert('Impossible d\'accéder au microphone. Vérifiez les permissions.');
            }
        }
        
        function stopRecording() {
            if (mediaRecorder && isRecording) {
                mediaRecorder.stop();
                isRecording = false;
                
                document.getElementById('voiceRecordBtn').innerHTML = '<i class="fas fa-microphone text-gray-400 group-hover:text-red-500 text-xl"></i>';
                document.getElementById('recordingIndicator').classList.add('hidden');
                document.getElementById('voicePreview').classList.add('hidden');
                if (recordingTimer) clearInterval(recordingTimer);
            }
        }
        
        function updateRecordingTime() {
            const elapsed = Math.floor((Date.now() - recordingStartTime) / 1000);
            const minutes = Math.floor(elapsed / 60);
            const seconds = elapsed % 60;
            document.getElementById('recordingTime').textContent = `${minutes}:${seconds.toString().padStart(2, '0')}`;
        }
        
        async function sendMessage() {
            const input = document.getElementById('messageInput');
            const message = input.value.trim();
            
            if (!message && !selectedFile) return;
            
            const sendBtn = document.getElementById('sendBtn');
            sendBtn.disabled = true;
            sendBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
            
            try {
                const formData = new FormData();
                formData.append('ajax', 1);
                formData.append('action', 'send_message');
                formData.append('to', selectedUserId);
                formData.append('message', message);
                formData.append('csrf_token', csrfToken);
                if (selectedFile) {
                    formData.append('file', selectedFile);
                }
                
                const response = await fetch('', {
                    method: 'POST',
                    body: formData
                });
                const data = await response.json();
                
                if (data.success) {
                    input.value = '';
                    autoResizeTextarea(input);
                    appendMessage(data.message);
                    scrollToBottom();
                    lastMessageId = data.message_id;
                    cancelFile();
                } else {
                    alert(data.error || 'Erreur d\'envoi');
                }
            } catch (err) {
                console.error('Erreur:', err);
                alert('Erreur de connexion');
            } finally {
                sendBtn.disabled = false;
                sendBtn.innerHTML = '<i class="fas fa-paper-plane"></i>';
            }
        }
        
        async function sendFile() {
            sendMessage();
        }
        
        async function markMessagesAsRead() {
            if (isSelfChat) return;
            try {
                await fetch('', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({
                        ajax: 1,
                        action: 'mark_read',
                        with: selectedUserId,
                        csrf_token: csrfToken
                    })
                });
                
                const convItem = document.querySelector(`.conversation-item[data-user-id="${selectedUserId}"]`);
                if (convItem) {
                    const badge = convItem.querySelector('.bg-orange-500');
                    if (badge) badge.remove();
                }
            } catch (err) {
                console.error('Erreur marquage lu:', err);
            }
        }
        
        async function clearConversation() {
            if (!confirm('Supprimer toute cette conversation ? Cette action est irréversible.')) return;
            
            try {
                const response = await fetch('', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({
                        ajax: 1,
                        action: 'delete_conversation',
                        with: selectedUserId,
                        csrf_token: csrfToken
                    })
                });
                const data = await response.json();
                
                if (data.success) {
                    location.reload();
                }
            } catch (err) {
                console.error('Erreur suppression:', err);
                alert('Erreur lors de la suppression');
            }
        }
        
        function onTyping() {
            if (isSelfChat) return;
            if (isTyping) return;
            isTyping = true;
            
            fetch('', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    ajax: 1,
                    action: 'typing',
                    to: selectedUserId,
                    csrf_token: csrfToken
                })
            });
            
            if (typingTimeout) clearTimeout(typingTimeout);
            typingTimeout = setTimeout(() => { isTyping = false; }, 2000);
        }
        
        function handleKeyPress(event) {
            if (event.key === 'Enter' && !event.shiftKey) {
                event.preventDefault();
                sendMessage();
            } else {
                onTyping();
            }
            autoResizeTextarea(event.target);
        }
        
        function autoResizeTextarea(textarea) {
            textarea.style.height = 'auto';
            textarea.style.height = Math.min(textarea.scrollHeight, 120) + 'px';
        }
        
        function addEmoji(emoji) {
            const input = document.getElementById('messageInput');
            input.value += emoji;
            input.focus();
            document.getElementById('emojiPicker').classList.add('hidden');
        }
        
        function addEmojiPicker() {
            const picker = document.getElementById('emojiPicker');
            picker.classList.toggle('hidden');
        }
        
        function showChatActions() {
            document.getElementById('chatActionsModal').classList.remove('hidden');
            document.getElementById('chatActionsModal').classList.add('flex');
        }
        
        function closeChatActions() {
            const modal = document.getElementById('chatActionsModal');
            modal.classList.remove('flex');
            modal.classList.add('hidden');
        }
        
        function startSelfChat() {
            window.location.href = `?user=${currentUserId}`;
        }
        
        function scrollToBottom() {
            const container = document.getElementById('messagesContainer');
            if (container) {
                container.scrollTop = container.scrollHeight;
            }
        }
        
        function setupConversationClick() {
            document.querySelectorAll('.conversation-item').forEach(item => {
                item.addEventListener('click', () => {
                    const userId = item.dataset.userId;
                    window.location.href = `?user=${userId}`;
                });
            });
        }
        
        function setupSearch() {
            const searchInput = document.getElementById('searchConversations');
            if (searchInput) {
                searchInput.addEventListener('input', (e) => {
                    const query = e.target.value.toLowerCase();
                    document.querySelectorAll('.conversation-item').forEach(item => {
                        const name = item.querySelector('.font-semibold')?.textContent.toLowerCase() || '';
                        item.style.display = name.includes(query) ? 'flex' : 'none';
                    });
                });
            }
        }
        
        function setupMobileNav() {
            const mobileMenuBtn = document.getElementById('mobileMenuBtn');
            const mobileBackBtn = document.getElementById('mobileBackBtn');
            const conversationList = document.getElementById('conversationList');
            const chatArea = document.getElementById('chatArea');
            
            if (mobileMenuBtn) {
                mobileMenuBtn.addEventListener('click', () => {
                    conversationList.classList.remove('hidden-mobile');
                    chatArea.classList.add('hidden-mobile');
                });
            }
            
            if (mobileBackBtn) {
                mobileBackBtn.addEventListener('click', () => {
                    conversationList.classList.remove('hidden-mobile');
                    chatArea.classList.add('hidden-mobile');
                });
            }
            
            if (selectedUserId) {
                conversationList.classList.add('hidden-mobile');
                chatArea.classList.remove('hidden-mobile');
            }
        }
        
        // Menu des fichiers
        function toggleFileMenu() {
            document.getElementById('fileMenu').classList.toggle('hidden');
        }
        
        function selectFileType(type) {
            document.getElementById('fileMenu').classList.add('hidden');
            let accept = { image:'image/*', video:'video/*', document:'.pdf,.doc,.docx,.txt,.zip', audio:'audio/*' }[type];
            document.getElementById('fileInput').setAttribute('accept', accept);
            document.getElementById('fileInput').click();
        }
        
        function cancelFile() {
            selectedFile = null;
            document.getElementById('fileInput').value = '';
            document.getElementById('filePreview').classList.add('hidden');
        }
        
        document.getElementById('fileInput')?.addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                selectedFile = file;
                const preview = document.getElementById('filePreview');
                document.getElementById('fileName').textContent = file.name;
                document.getElementById('fileSize').textContent = formatFileSize(file.size);
                preview.classList.remove('hidden');
            }
        });
        
        function formatFileSize(bytes) {
            if (bytes === 0) return '0 B';
            const k = 1024;
            const sizes = ['B', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
        }
        
        function formatDuration(seconds) {
            const minutes = Math.floor(seconds / 60);
            const secs = seconds % 60;
            return `${minutes}:${secs.toString().padStart(2, '0')}`;
        }
        
        function escapeHtml(text) {
            if (!text) return '';
            return text.replace(/[&<>]/g, function(m) {
                if (m === '&') return '&amp;';
                if (m === '<') return '&lt;';
                if (m === '>') return '&gt;';
                return m;
            });
        }
        
        document.getElementById('chatActionsModal')?.addEventListener('click', (e) => {
            if (e.target === e.currentTarget) closeChatActions();
        });
        
        document.addEventListener('click', (e) => {
            if (!e.target.closest('#emojiPicker') && !e.target.closest('.fa-smile')) {
                document.getElementById('emojiPicker')?.classList.add('hidden');
            }
            if (!e.target.closest('#fileMenu') && !e.target.closest('#fileMenuBtn')) {
                document.getElementById('fileMenu')?.classList.add('hidden');
            }
        });
    </script>
</body>
</html>