<?php
/**
 * WideMaze - Messagerie Instantanée Avancée
 * Version 4.0 - Chat temps réel, fichiers, notes vocales, appels vidéo
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_auth();

$userId = $_SESSION['user_id'];
$selectedUser = isset($_GET['user']) ? intval($_GET['user']) : null;
$isMobile = strpos($_SERVER['HTTP_USER_AGENT'], 'Mobile') !== false;

// Traitement AJAX
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['ajax'])) {
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
            
            if (empty($message) && $type == 'text' && empty($_FILES['file']) && empty($_POST['voice_data'])) {
                echo json_encode(['error' => 'Message vide']);
                exit();
            }
            
            $fileInfo = null;
            $duration = null;
            
            // Gestion des notes vocales (base64)
            if (!empty($_POST['voice_data']) && $_POST['voice_data'] != 'undefined') {
                $voiceData = $_POST['voice_data'];
                if (preg_match('/data:audio\/(\w+);base64,(.+)/', $voiceData, $matches)) {
                    $audioBinary = base64_decode($matches[2]);
                    if ($audioBinary) {
                        $messageDir = MESSAGES_DIR;
                        if (!is_dir($messageDir)) mkdir($messageDir, 0755, true);
                        $filename = uniqid() . '_voice_' . bin2hex(random_bytes(4)) . '.webm';
                        $destination = $messageDir . $filename;
                        
                        if (file_put_contents($destination, $audioBinary)) {
                            $duration = intval($_POST['voice_duration'] ?? 0);
                            $fileInfo = [
                                'url' => str_replace($_SERVER['DOCUMENT_ROOT'], '', $destination),
                                'name' => 'Note vocale.webm',
                                'size' => strlen($audioBinary),
                                'type' => 'voice',
                                'mime' => 'audio/webm',
                                'duration' => $duration,
                                'filename' => $filename
                            ];
                            $type = 'voice';
                            $message = '';
                        }
                    }
                }
            }
            
            // Gestion des fichiers
            if (isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
                $allowedTypes = array_merge(ALLOWED_IMAGE_TYPES, ALLOWED_DOCUMENT_TYPES, ALLOWED_VIDEO_TYPES);
                $upload = handle_file_upload($_FILES['file'], MESSAGES_DIR, $allowedTypes, 50 * 1024 * 1024);
                if ($upload['success']) {
                    $fileType = 'document';
                    if (in_array($upload['mime'], ALLOWED_IMAGE_TYPES)) $fileType = 'image';
                    elseif (in_array($upload['mime'], ALLOWED_VIDEO_TYPES)) $fileType = 'video';
                    
                    $fileInfo = [
                        'url' => str_replace($_SERVER['DOCUMENT_ROOT'], '', MESSAGES_DIR . $upload['filename']),
                        'name' => $_FILES['file']['name'],
                        'size' => $_FILES['file']['size'],
                        'type' => $fileType,
                        'mime' => $upload['mime'],
                        'filename' => $upload['filename']
                    ];
                    $type = $fileType;
                } else {
                    echo json_encode(['error' => $upload['error']]);
                    exit();
                }
            }
            
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO message (id_expediteur, id_destinataire, textemessage, datemessage, type, lu, file_url, file_name, file_size, file_duration)
                    VALUES (?, ?, ?, NOW(), ?, 0, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $userId, $to, $message, $type,
                    $fileInfo ? $fileInfo['url'] : null,
                    $fileInfo ? $fileInfo['name'] : null,
                    $fileInfo ? $fileInfo['size'] : null,
                    $duration
                ]);
                $messageId = $pdo->lastInsertId();
                
                if ($to != $userId) {
                    $notificationType = $fileInfo ? 'file' : 'message';
                    $notificationContent = $fileInfo ? 'Nouveau fichier de @' . $_SESSION['surnom'] : 'Nouveau message de @' . $_SESSION['surnom'];
                    create_notification($pdo, $to, $notificationType, $notificationContent, $userId, 'messagerie.php?user=' . $userId);
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
                        'file' => $fileInfo ? [
                            'url' => $fileInfo['url'],
                            'name' => $fileInfo['name'],
                            'size' => format_file_size($fileInfo['size']),
                            'type' => $fileInfo['type'],
                            'duration' => $duration
                        ] : null
                    ]
                ]);
            } catch (PDOException $e) {
                error_log("Send message error: " . $e->getMessage());
                echo json_encode(['error' => 'Erreur lors de l\'envoi']);
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
                $messages = $stmt->fetchAll();
                $messages = array_reverse($messages);
                
                // Marquer comme lus
                if ($with != $userId) {
                    $stmt = $pdo->prepare("UPDATE message SET lu = 1 WHERE id_destinataire = ? AND id_expediteur = ? AND lu = 0");
                    $stmt->execute([$userId, $with]);
                }
                
                echo json_encode([
                    'success' => true,
                    'messages' => $messages,
                    'has_more' => count($messages) === $limit
                ]);
            } catch (PDOException $e) {
                error_log("Load messages error: " . $e->getMessage());
                echo json_encode(['error' => 'Erreur de chargement']);
            }
            break;
            
        case 'delete_message':
            $messageId = intval($_POST['message_id'] ?? 0);
            $deleteFor = $_POST['delete_for'] ?? 'me';
            
            try {
                $stmt = $pdo->prepare("SELECT id_expediteur, file_url FROM message WHERE idmessage = ?");
                $stmt->execute([$messageId]);
                $message = $stmt->fetch();
                
                if (!$message) {
                    echo json_encode(['error' => 'Message non trouvé']);
                    exit();
                }
                
                if ($deleteFor == 'everyone' && $message['id_expediteur'] == $userId) {
                    if ($message['file_url'] && file_exists($_SERVER['DOCUMENT_ROOT'] . $message['file_url'])) {
                        unlink($_SERVER['DOCUMENT_ROOT'] . $message['file_url']);
                    }
                    $stmt = $pdo->prepare("DELETE FROM message WHERE idmessage = ?");
                    $stmt->execute([$messageId]);
                } else {
                    $field = ($message['id_expediteur'] == $userId) ? 'deleted_for_sender' : 'deleted_for_receiver';
                    $stmt = $pdo->prepare("UPDATE message SET $field = 1 WHERE idmessage = ?");
                    $stmt->execute([$messageId]);
                }
                
                echo json_encode(['success' => true]);
            } catch (PDOException $e) {
                echo json_encode(['error' => 'Erreur lors de la suppression']);
            }
            break;
            
        case 'delete_conversation':
            $with = intval($_POST['with'] ?? 0);
            
            try {
                $stmt = $pdo->prepare("SELECT file_url FROM message WHERE (id_expediteur = ? AND id_destinataire = ?) OR (id_expediteur = ? AND id_destinataire = ?) AND file_url IS NOT NULL");
                $stmt->execute([$userId, $with, $with, $userId]);
                $files = $stmt->fetchAll();
                
                foreach ($files as $file) {
                    if ($file['file_url'] && file_exists($_SERVER['DOCUMENT_ROOT'] . $file['file_url'])) {
                        unlink($_SERVER['DOCUMENT_ROOT'] . $file['file_url']);
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
            
        case 'check_typing':
            $from = intval($_POST['from'] ?? 0);
            $isTyping = isset($_SESSION['typing'][$from]) && (time() - $_SESSION['typing'][$from]) < 3;
            echo json_encode(['typing' => $isTyping]);
            break;
    }
    exit();
}

// Récupération des conversations
$conversations = [];
$totalUnread = 0;

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
             WHERE ((id_expediteur = ? AND id_destinataire = other_id) OR (id_expediteur = other_id AND id_destinataire = ?))
             AND (deleted_for_sender = 0 OR deleted_for_sender IS NULL)
             ORDER BY idmessage DESC LIMIT 1) as last_message,
            (SELECT datemessage FROM message 
             WHERE ((id_expediteur = ? AND id_destinataire = other_id) OR (id_expediteur = other_id AND id_destinataire = ?))
             AND (deleted_for_sender = 0 OR deleted_for_sender IS NULL)
             ORDER BY idmessage DESC LIMIT 1) as last_date,
            (SELECT COUNT(*) FROM message 
             WHERE id_destinataire = ? AND id_expediteur = other_id AND lu = 0) as unread_count,
            (SELECT type FROM message 
             WHERE ((id_expediteur = ? AND id_destinataire = other_id) OR (id_expediteur = other_id AND id_destinataire = ?))
             ORDER BY idmessage DESC LIMIT 1) as last_type,
            (SELECT file_url FROM message 
             WHERE ((id_expediteur = ? AND id_destinataire = other_id) OR (id_expediteur = other_id AND id_destinataire = ?))
             AND file_url IS NOT NULL
             ORDER BY idmessage DESC LIMIT 1) as last_file
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
        $userId,
        $userId, $userId, $userId, $userId,
        $userId, $userId, $userId, $userId,
        $userId, $userId, $userId, $userId,
        $userId, $userId
    ]);
    $conversations = $stmt->fetchAll();
    
    // Calculer le total des messages non lus
    foreach ($conversations as $conv) {
        $totalUnread += $conv['unread_count'];
    }
    
    // Ajouter la conversation avec soi-même
    $hasSelf = false;
    foreach ($conversations as $conv) {
        if ($conv['other_id'] == $userId) {
            $hasSelf = true;
            break;
        }
    }
    
    if (!$hasSelf) {
        array_unshift($conversations, [
            'other_id' => $userId,
            'surnom' => $_SESSION['surnom'],
            'avatar' => $_SESSION['avatar'] ?? 'default.jpg',
            'status' => 'Online',
            'prenom' => $_SESSION['prenom'],
            'nom' => $_SESSION['nom'],
            'universite' => $_SESSION['universite'] ?? '',
            'last_message' => '📝 Vos notes personnelles',
            'last_date' => date('Y-m-d H:i:s'),
            'unread_count' => 0,
            'last_type' => 'text'
        ]);
    }
} catch (PDOException $e) {
    error_log("Error fetching conversations: " . $e->getMessage());
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
            $stmt = $pdo->prepare("SELECT id, surnom, avatar, status, prenom, nom, universite FROM utilisateurs WHERE id = ? AND is_active = 1");
            $stmt->execute([$selectedUser]);
            $selectedUserInfo = $stmt->fetch();
        } catch (PDOException $e) {
            error_log("Error fetching user: " . $e->getMessage());
        }
    }
}

$csrfToken = generate_csrf_token();
$page_title = 'Messagerie';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title>Messagerie - WideMaze</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { font-family: 'Inter', sans-serif; }
        body { background: linear-gradient(135deg, #f5f7fa 0%, #eef2f7 100%); height: 100vh; overflow: hidden; }
        
        /* Animations */
        @keyframes slideIn {
            from { opacity: 0; transform: translateX(-20px); }
            to { opacity: 1; transform: translateX(0); }
        }
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        @keyframes wave {
            0%, 100% { height: 8px; }
            50% { height: 18px; }
        }
        @keyframes typing {
            0%, 100% { opacity: 0.3; }
            50% { opacity: 1; }
        }
        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.05); }
        }
        
        /* Messages */
        .message-sent { 
            background: linear-gradient(135deg, #f59e0b 0%, #ea580c 100%);
            color: white; 
            border-radius: 20px 20px 4px 20px;
        }
        .message-received { 
            background: #f1f5f9; 
            color: #1e293b; 
            border-radius: 20px 20px 20px 4px;
        }
        .message-self { 
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white; 
            border-radius: 20px 20px 4px 20px;
        }
        
        /* Animations des messages */
        .message-item {
            animation: fadeInUp 0.3s ease-out;
        }
        
        /* Voice wave */
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
        .voice-wave span:nth-child(2) { animation-delay: 0.1s; }
        .voice-wave span:nth-child(3) { animation-delay: 0.2s; }
        .voice-wave span:nth-child(4) { animation-delay: 0.3s; }
        .voice-wave span:nth-child(5) { animation-delay: 0.4s; }
        
        /* Typing indicator */
        .typing-indicator span {
            display: inline-block;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background-color: #f59e0b;
            margin: 0 2px;
            animation: typing 1s infinite;
        }
        .typing-indicator span:nth-child(2) { animation-delay: 0.2s; }
        .typing-indicator span:nth-child(3) { animation-delay: 0.4s; }
        
        /* File cards */
        .file-card {
            background: rgba(255,255,255,0.1);
            border-radius: 12px;
            padding: 10px;
            transition: all 0.2s;
        }
        .file-card:hover {
            background: rgba(255,255,255,0.2);
            transform: translateY(-2px);
        }
        
        /* Scrollbar */
        .chat-container::-webkit-scrollbar {
            width: 6px;
        }
        .chat-container::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 10px;
        }
        .chat-container::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 10px;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .conversation-list {
                position: fixed;
                left: 0;
                top: 60px;
                width: 100%;
                height: calc(100% - 60px);
                z-index: 40;
                transform: translateX(0);
                transition: transform 0.3s ease;
            }
            .conversation-list.hidden-mobile {
                transform: translateX(-100%);
            }
            .chat-area {
                width: 100%;
            }
            .chat-area.hidden-mobile {
                display: none;
            }
        }
        
        /* Self note badge */
        .self-note-badge {
            background: linear-gradient(135deg, #10b981, #059669);
        }
        
        /* Recording pulse */
        .recording-pulse {
            animation: pulse 1s infinite;
        }
        
        /* Toast */
        .toast {
            animation: fadeInUp 0.3s ease-out;
        }
        
        /* Online indicator */
        .online-dot {
            position: absolute;
            bottom: 2px;
            right: 2px;
            width: 12px;
            height: 12px;
            background-color: #10b981;
            border-radius: 50%;
            border: 2px solid white;
        }
    </style>
</head>
<body class="h-screen overflow-hidden">
    <!-- Navigation -->
    <nav class="fixed top-0 w-full bg-white/95 backdrop-blur-md shadow-lg z-50 border-b border-gray-100">
        <div class="container mx-auto px-4 h-16 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <button id="mobileMenuBtn" class="lg:hidden p-2 hover:bg-gray-100 rounded-xl transition-colors">
                    <i class="fas fa-bars text-gray-600 text-xl"></i>
                </button>
                <a href="../index.php" class="flex items-center gap-2 group">
                    <div class="w-10 h-10 bg-gradient-to-br from-orange-500 to-red-600 rounded-xl flex items-center justify-center shadow-lg group-hover:scale-110 transition-transform">
                        <i class="fas fa-network-wired text-white"></i>
                    </div>
                    <span class="text-2xl font-bold bg-gradient-to-r from-orange-500 to-red-600 bg-clip-text text-transparent hidden sm:block">WideMaze</span>
                </a>
            </div>
            <div class="flex items-center gap-4">
                <a href="notifications.php" class="p-2 hover:bg-gray-100 rounded-full transition-colors relative">
                    <i class="fas fa-bell text-gray-600 text-xl"></i>
                </a>
                <a href="../index.php" class="p-2 hover:bg-gray-100 rounded-full transition-colors">
                    <i class="fas fa-home text-gray-600 text-xl"></i>
                </a>
                <div class="relative group">
                    <button class="flex items-center gap-2 p-1">
                        <img src="<?= get_avatar_url($_SESSION['avatar'] ?? '') ?>" class="w-8 h-8 rounded-full object-cover border-2 border-orange-500">
                    </button>
                    <div class="absolute right-0 top-full mt-2 w-48 bg-white rounded-xl shadow-xl opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all duration-200 z-50">
                        <a href="profil.php" class="block px-4 py-2 hover:bg-gray-50 rounded-t-xl">Mon profil</a>
                        <a href="parametres.php" class="block px-4 py-2 hover:bg-gray-50">Paramètres</a>
                        <hr>
                        <a href="deconnexion.php" class="block px-4 py-2 hover:bg-gray-50 rounded-b-xl text-red-600">Déconnexion</a>
                    </div>
                </div>
            </div>
        </div>
    </nav>
    
    <div class="container mx-auto pt-16 h-full">
        <div class="flex h-full">
            
            <!-- Liste des conversations -->
            <div id="conversationList" class="conversation-list w-full md:w-96 bg-white border-r border-gray-200 flex flex-col h-full shadow-xl">
                <div class="p-4 border-b border-gray-200 bg-white sticky top-0 z-10">
                    <div class="flex items-center justify-between mb-3">
                        <h2 class="text-xl font-bold text-gray-800 flex items-center gap-2">
                            <i class="fas fa-comment-dots text-orange-500"></i>
                            Messages
                            <?php if ($totalUnread > 0): ?>
                                <span class="px-2 py-0.5 bg-orange-500 text-white text-xs rounded-full"><?= $totalUnread ?></span>
                            <?php endif; ?>
                        </h2>
                    </div>
                    <div class="relative">
                        <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
                        <input type="text" id="searchConversations" placeholder="Rechercher une conversation..."
                               class="w-full pl-9 pr-4 py-2.5 bg-gray-100 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-orange-500 transition-all">
                    </div>
                </div>
                <div class="flex-1 overflow-y-auto">
                    <?php if (empty($conversations)): ?>
                        <div class="flex flex-col items-center justify-center h-64 text-gray-400">
                            <i class="fas fa-comments text-5xl mb-4"></i>
                            <p class="text-lg font-medium">Aucune conversation</p>
                            <p class="text-sm">Commencez à discuter avec vos amis !</p>
                            <div class="flex gap-3 mt-4">
                                <a href="recherche.php" class="text-orange-500 hover:text-orange-600 text-sm font-medium">
                                    <i class="fas fa-search mr-1"></i>Trouver des amis
                                </a>
                                <button onclick="startSelfChat()" class="text-green-500 hover:text-green-600 text-sm font-medium">
                                    <i class="fas fa-user-edit mr-1"></i>Notes personnelles
                                </button>
                            </div>
                        </div>
                    <?php else: ?>
                        <?php foreach ($conversations as $conv):
                            $isSelfConv = $conv['other_id'] == $userId;
                            $lastDate = $conv['last_date'] ? date('d/m/Y', strtotime($conv['last_date'])) : '';
                            $today = date('d/m/Y');
                            $displayDate = $lastDate === $today ? "Aujourd'hui" : $lastDate;
                            
                            // Formater le dernier message
                            $lastMessageDisplay = '';
                            if ($conv['last_type'] == 'image') $lastMessageDisplay = '🖼️ Photo';
                            elseif ($conv['last_type'] == 'video') $lastMessageDisplay = '🎥 Vidéo';
                            elseif ($conv['last_type'] == 'voice') $lastMessageDisplay = '🎤 Note vocale';
                            elseif ($conv['last_type'] == 'document') $lastMessageDisplay = '📄 Document';
                            elseif ($conv['last_message']) $lastMessageDisplay = htmlspecialchars(substr($conv['last_message'], 0, 40));
                            else $lastMessageDisplay = 'Message';
                        ?>
                            <div class="conversation-item flex items-center gap-3 p-4 hover:bg-gray-50 cursor-pointer transition-all border-b border-gray-100 <?= $selectedUser == $conv['other_id'] ? 'bg-orange-50 border-l-4 border-l-orange-500' : '' ?>" data-user-id="<?= $conv['other_id'] ?>" data-user-name="<?= htmlspecialchars($conv['surnom']) ?>" data-user-avatar="<?= $conv['avatar'] ?>" data-user-status="<?= $conv['status'] ?>">
                                <div class="relative flex-shrink-0">
                                    <div class="w-14 h-14 rounded-full flex items-center justify-center <?= $isSelfConv ? 'self-note-badge' : '' ?>">
                                        <img src="<?= get_avatar_url($conv['avatar'] ?? '') ?>" class="w-14 h-14 rounded-full object-cover border-2 <?= $isSelfConv ? 'border-green-500' : 'border-gray-200' ?>">
                                    </div>
                                    <?php if (!$isSelfConv && $conv['status'] == 'Online'): ?>
                                        <span class="absolute bottom-0 right-0 w-3.5 h-3.5 bg-green-500 rounded-full border-2 border-white"></span>
                                    <?php endif; ?>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center justify-between">
                                        <p class="font-semibold text-gray-800 truncate flex items-center gap-1">
                                            <?php if ($isSelfConv): ?>
                                                <i class="fas fa-user-edit text-green-500 text-xs"></i>
                                            <?php endif; ?>
                                            <?= htmlspecialchars($isSelfConv ? 'Mes notes' : ($conv['prenom'] . ' ' . $conv['nom'])) ?>
                                        </p>
                                        <p class="text-xs text-gray-400"><?= $displayDate ?></p>
                                    </div>
                                    <p class="text-sm <?= $conv['unread_count'] > 0 ? 'font-semibold text-gray-800' : 'text-gray-500' ?> truncate">
                                        <?= $lastMessageDisplay ?>
                                    </p>
                                </div>
                                <?php if ($conv['unread_count'] > 0 && !$isSelfConv): ?>
                                    <span class="min-w-[24px] h-6 bg-orange-500 text-white text-xs rounded-full flex items-center justify-center px-1.5 font-bold"><?= min($conv['unread_count'], 99) ?></span>
                                <?php elseif ($isSelfConv): ?>
                                    <i class="fas fa-lock text-gray-300 text-xs" title="Messages privés"></i>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Zone de chat -->
            <div id="chatArea" class="chat-area flex-1 flex flex-col bg-gradient-to-br from-gray-50 to-white <?= !$selectedUser ? 'hidden-mobile' : '' ?>">
                <?php if ($selectedUser && $selectedUserInfo): ?>
                    <!-- Header du chat -->
                    <div class="bg-white border-b border-gray-200 p-4 flex items-center justify-between sticky top-16 z-10 shadow-sm">
                        <div class="flex items-center gap-3">
                            <button id="mobileBackBtn" class="lg:hidden p-2 hover:bg-gray-100 rounded-full transition-colors">
                                <i class="fas fa-arrow-left text-gray-600"></i>
                            </button>
                            <div class="relative">
                                <div class="w-12 h-12 rounded-full flex items-center justify-center <?= $isSelf ? 'self-note-badge' : '' ?>">
                                    <img src="<?= get_avatar_url($selectedUserInfo['avatar'] ?? '') ?>" class="w-12 h-12 rounded-full object-cover border-2 <?= $isSelf ? 'border-green-500' : 'border-orange-500' ?>">
                                </div>
                                <?php if (!$isSelf && ($selectedUserInfo['status'] ?? '') == 'Online'): ?>
                                    <span class="online-dot"></span>
                                <?php endif; ?>
                            </div>
                            <div>
                                <p class="font-bold text-gray-800 text-lg flex items-center gap-2">
                                    <?php if ($isSelf): ?>
                                        <i class="fas fa-user-edit text-green-500"></i>
                                        <span>Notes personnelles</span>
                                    <?php else: ?>
                                        <?= htmlspecialchars(($selectedUserInfo['prenom'] ?? '') . ' ' . ($selectedUserInfo['nom'] ?? '')) ?>
                                    <?php endif; ?>
                                </p>
                                <p class="text-xs <?= $isSelf ? 'text-green-600' : (($selectedUserInfo['status'] ?? '') == 'Online' ? 'text-green-600' : 'text-gray-400') ?>">
                                    <?php if ($isSelf): ?>
                                        <i class="fas fa-lock mr-1"></i>Messages privés - seulement vous
                                    <?php else: ?>
                                        <span id="userStatusText"><?= ($selectedUserInfo['status'] ?? '') == 'Online' ? 'En ligne' : 'Hors ligne' ?></span>
                                        <?php if (!empty($selectedUserInfo['universite'])): ?>
                                            <span class="mx-1">•</span>
                                            <span class="text-gray-500"><?= htmlspecialchars($selectedUserInfo['universite']) ?></span>
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
                    
                    <!-- Messages container -->
                    <div id="messagesContainer" class="flex-1 overflow-y-auto p-4 space-y-3 chat-container" data-user-id="<?= $selectedUser ?>">
                        <div class="text-center py-8">
                            <div class="inline-block w-8 h-8 border-3 border-orange-500 border-t-transparent rounded-full animate-spin"></div>
                            <p class="text-gray-500 mt-2">Chargement des messages...</p>
                        </div>
                    </div>
                    
                    <!-- Typing indicator -->
                    <?php if (!$isSelf): ?>
                        <div id="typingIndicator" class="px-4 py-2 hidden">
                            <div class="typing-indicator flex items-center gap-1 bg-gray-100 rounded-full px-4 py-2 w-fit shadow-sm">
                                <span></span><span></span><span></span>
                                <span class="text-xs text-gray-500 ml-2">est en train d'écrire...</span>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Input area -->
                    <div class="bg-white border-t border-gray-200 p-4 shadow-lg">
                        <div class="flex items-end gap-2">
                            <!-- Émojis -->
                            <button onclick="toggleEmojiPicker()" class="p-2 hover:bg-gray-100 rounded-full transition-colors group" title="Émojis">
                                <i class="fas fa-smile text-gray-400 group-hover:text-orange-500 text-xl"></i>
                            </button>
                            
                            <!-- Pièce jointe -->
                            <div class="relative">
                                <button id="fileMenuBtn" onclick="toggleFileMenu()" class="p-2 hover:bg-gray-100 rounded-full transition-colors group" title="Joindre un fichier">
                                    <i class="fas fa-paperclip text-gray-400 group-hover:text-orange-500 text-xl"></i>
                                </button>
                                <div id="fileMenu" class="absolute bottom-full left-0 mb-2 bg-white rounded-xl shadow-xl border border-gray-200 hidden w-48 z-20">
                                    <div class="p-2 space-y-1">
                                        <button onclick="selectFileType('image')" class="flex items-center gap-3 w-full px-3 py-2 hover:bg-gray-50 rounded-lg transition-colors">
                                            <i class="fas fa-image text-green-500 w-5"></i><span>Photo</span>
                                        </button>
                                        <button onclick="selectFileType('video')" class="flex items-center gap-3 w-full px-3 py-2 hover:bg-gray-50 rounded-lg">
                                            <i class="fas fa-video text-red-500 w-5"></i><span>Vidéo</span>
                                        </button>
                                        <button onclick="selectFileType('document')" class="flex items-center gap-3 w-full px-3 py-2 hover:bg-gray-50 rounded-lg">
                                            <i class="fas fa-file-pdf text-blue-500 w-5"></i><span>Document</span>
                                        </button>
                                        <button onclick="selectFileType('audio')" class="flex items-center gap-3 w-full px-3 py-2 hover:bg-gray-50 rounded-lg">
                                            <i class="fas fa-music text-purple-500 w-5"></i><span>Audio</span>
                                        </button>
                                    </div>
                                </div>
                                <input type="file" id="fileInput" class="hidden">
                            </div>
                            
                            <!-- Zone de texte -->
                            <div class="flex-1 relative">
                                <textarea id="messageInput" rows="1" placeholder="<?= $isSelf ? '📝 Écrivez une note pour vous-même...' : '✏️ Écrivez votre message...' ?>" 
                                          class="w-full px-4 py-3 border border-gray-200 rounded-2xl focus:outline-none focus:border-orange-500 focus:ring-2 focus:ring-orange-100 resize-none transition-all text-gray-700"
                                          onkeydown="handleKeyPress(event)" style="max-height: 120px;"></textarea>
                                
                                <!-- Emoji picker -->
                                <div id="emojiPicker" class="absolute bottom-full left-0 mb-2 bg-white rounded-xl shadow-xl border border-gray-200 hidden w-80 p-3 z-20">
                                    <div class="grid grid-cols-8 gap-2 max-h-48 overflow-y-auto">
                                        <?php
                                        $emojis = ['😀', '😃', '😄', '😁', '😆', '😅', '😂', '🤣', '😊', '😇', '🙂', '🙃', '😉', '😌', '😍', '🥰', '😘', '😗', '😙', '😚', '😋', '😛', '😝', '😜', '🤪', '🤨', '🧐', '🤓', '😎', '🤩', '🥳', '😏', '😒', '😞', '😔', '😟', '😕', '🙁', '☹️', '😣', '😖', '😫', '😩', '🥺', '😢', '😭', '😤', '😠', '😡', '🤬', '🤯', '😳', '🥵', '🥶', '😱', '😨', '😰', '😥', '😓', '🤗', '🤔', '🤭', '🤫', '🤥', '😶', '😐', '😑', '😬', '🙄', '😯', '😦', '😧', '😮', '😲', '🥱', '😴', '🤤', '😪', '😵', '🤐', '🥴', '🤢', '🤮', '🤧', '😷', '🤒', '🤕', '🤑', '🤠', '😈', '👿', '👹', '👺', '💀', '👻', '👽', '🤖', '💩', '😺', '😸', '😹', '😻', '😼', '😽', '🙀', '😿', '😾', '👍', '👎', '👌', '✌️', '🤞', '🤟', '🤘', '🤙', '👈', '👉', '👆', '👇', '🖕', '✍️', '💪', '🦾', '🖐️', '✋', '👋', '🤚', '🦶', '🦵', '🦿', '💄', '💋', '👄', '🦷', '👅', '👂', '🦻', '👃', '👣', '👁️', '👀', '🧠', '🫀', '🫁', '🗣️', '👤', '👥', '🫂', '👶', '🧒', '👦', '👧', '🧑', '👩', '🧔', '👨', '🧓', '👴', '👵'];
                                        foreach ($emojis as $emoji) {
                                            echo "<button type='button' onclick='addEmoji(\"$emoji\")' class='text-2xl hover:bg-gray-100 rounded p-1 transition-colors'>$emoji</button>";
                                        }
                                        ?>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Bouton d'envoi -->
                            <button id="sendBtn" onclick="sendMessage()" class="w-10 h-10 bg-gradient-to-r from-orange-500 to-red-500 hover:from-orange-600 hover:to-red-600 text-white rounded-full flex items-center justify-center transition-all shadow-md hover:shadow-lg">
                                <i class="fas fa-paper-plane"></i>
                            </button>
                        </div>
                        
                        <!-- File preview -->
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
                            <button onclick="sendFile()" class="mt-2 w-full bg-orange-500 text-white py-1.5 rounded-lg text-sm hover:bg-orange-600 transition-colors">
                                Envoyer le fichier
                            </button>
                        </div>
                        
                        <!-- Voice recording preview -->
                        <div id="voicePreview" class="mt-3 hidden">
                            <div class="bg-red-50 rounded-lg p-3 flex items-center justify-between">
                                <div class="flex items-center gap-3">
                                    <div class="voice-wave">
                                        <span></span><span></span><span></span><span></span><span></span>
                                    </div>
                                    <span class="text-sm text-red-600 font-medium">Enregistrement en cours...</span>
                                    <span id="recordingTime" class="text-xs text-red-500 font-mono">0:00</span>
                                </div>
                                <button onclick="stopRecording()" class="bg-red-500 hover:bg-red-600 text-white px-3 py-1.5 rounded-lg text-sm transition-colors">
                                    Arrêter
                                </button>
                            </div>
                        </div>
                        
                        <!-- Bouton d'enregistrement vocal -->
                        <div class="flex justify-center mt-2">
                            <button id="voiceRecordBtn" onclick="toggleVoiceRecording()" class="flex items-center gap-2 px-4 py-2 bg-gray-100 hover:bg-gray-200 rounded-full transition-all group" title="Note vocale">
                                <i class="fas fa-microphone text-gray-500 group-hover:text-red-500"></i>
                                <span class="text-xs text-gray-500 group-hover:text-red-500">Note vocale</span>
                            </button>
                            <div id="recordingIndicator" class="hidden ml-2">
                                <span class="relative flex h-3 w-3">
                                    <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-red-400 opacity-75"></span>
                                    <span class="relative inline-flex rounded-full h-3 w-3 bg-red-500"></span>
                                </span>
                            </div>
                        </div>
                        
                        <p class="text-xs text-gray-400 mt-2 text-center">
                            <i class="fas fa-lock text-[10px] mr-1"></i><?= $isSelf ? 'Ces notes sont privées et visibles uniquement par vous' : 'Messages chiffrés de bout en bout' ?>
                        </p>
                    </div>
                    
                <?php else: ?>
                    <!-- Écran d'accueil de la messagerie -->
                    <div class="flex-1 flex items-center justify-center">
                        <div class="text-center">
                            <div class="w-32 h-32 bg-gradient-to-br from-orange-100 to-red-100 rounded-full flex items-center justify-center mx-auto mb-6 shadow-lg">
                                <i class="fas fa-comments text-5xl text-orange-500"></i>
                            </div>
                            <h3 class="text-2xl font-bold text-gray-700 mb-2">Votre messagerie</h3>
                            <p class="text-gray-500 mb-6 max-w-md">Sélectionnez une conversation pour commencer à discuter avec vos amis ou prenez des notes personnelles</p>
                            <div class="flex flex-col sm:flex-row gap-3 justify-center">
                                <a href="recherche.php" class="inline-flex items-center gap-2 bg-gradient-to-r from-orange-500 to-red-500 text-white px-6 py-3 rounded-xl hover:shadow-lg transition-all shadow-md">
                                    <i class="fas fa-search"></i>
                                    <span>Trouver des amis</span>
                                </a>
                                <button onclick="startSelfChat()" class="inline-flex items-center gap-2 bg-gradient-to-r from-green-500 to-emerald-600 text-white px-6 py-3 rounded-xl hover:shadow-lg transition-all shadow-md">
                                    <i class="fas fa-user-edit"></i>
                                    <span>Notes personnelles</span>
                                </button>
                            </div>
                            <div class="mt-8 flex gap-6 justify-center text-gray-400 text-sm">
                                <div><i class="fas fa-image mr-1"></i> Photos</div>
                                <div><i class="fas fa-microphone mr-1"></i> Vocaux</div>
                                <div><i class="fas fa-file-pdf mr-1"></i> Documents</div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Modal Actions -->
    <div id="chatActionsModal" class="fixed inset-0 bg-black/50 backdrop-blur-sm z-50 hidden items-center justify-center p-4">
        <div class="bg-white rounded-2xl w-full max-w-sm shadow-2xl transform transition-all">
            <div class="p-4 border-b border-gray-100">
                <h3 class="font-bold text-lg flex items-center gap-2">
                    <i class="fas fa-cog text-gray-500"></i>Actions
                </h3>
            </div>
            <div class="p-2">
                <?php if (!$isSelf && $selectedUser): ?>
                    <button onclick="clearConversation()" class="flex items-center gap-3 w-full px-4 py-3 hover:bg-red-50 rounded-xl transition-colors text-red-600">
                        <i class="fas fa-trash-alt w-5"></i>
                        <span>Supprimer la conversation</span>
                    </button>
                    <a href="profil.php?id=<?= $selectedUser ?>" class="flex items-center gap-3 w-full px-4 py-3 hover:bg-gray-50 rounded-xl transition-colors text-gray-700">
                        <i class="fas fa-user w-5"></i>
                        <span>Voir le profil</span>
                    </a>
                    <button onclick="blockUser()" class="flex items-center gap-3 w-full px-4 py-3 hover:bg-gray-50 rounded-xl transition-colors text-gray-700">
                        <i class="fas fa-ban w-5"></i>
                        <span>Bloquer l'utilisateur</span>
                    </button>
                <?php else: ?>
                    <div class="p-4 text-center">
                        <i class="fas fa-lock text-4xl text-green-500 mb-2"></i>
                        <p class="text-gray-600">Vos notes personnelles sont privées</p>
                        <p class="text-sm text-gray-400 mt-1">Elles sont visibles uniquement par vous</p>
                    </div>
                <?php endif; ?>
            </div>
            <div class="p-4 border-t border-gray-100">
                <button onclick="closeChatActions()" class="w-full px-4 py-2 bg-gray-100 hover:bg-gray-200 rounded-xl transition-colors font-medium">Fermer</button>
            </div>
        </div>
    </div>
    
    <script>
        // ==================== CONFIGURATION ====================
        const csrfToken = '<?= $csrfToken ?>';
        const currentUserId = <?= $userId ?>;
        let selectedUserId = <?= $selectedUser ?: 0 ?>;
        let isSelfChat = <?= $isSelf ? 'true' : 'false' ?>;
        let lastMessageId = 0;
        let isLoading = false;
        let hasMoreMessages = true;
        let typingTimeout = null;
        let isTyping = false;
        let pollInterval = null;
        let typingPollInterval = null;
        let selectedFile = null;
        
        // Variables pour l'enregistrement vocal
        let mediaRecorder = null;
        let audioChunks = [];
        let isRecording = false;
        let recordingStartTime = null;
        let recordingTimer = null;
        
        // ==================== INITIALISATION ====================
        document.addEventListener('DOMContentLoaded', () => {
            if (selectedUserId) {
                loadMessages();
                if (!isSelfChat) {
                    startPolling();
                    startTypingPolling();
                }
            }
            setupConversationClick();
            setupSearch();
            setupMobileNav();
            autoResizeTextarea(document.getElementById('messageInput'));
        });
        
        // ==================== GESTION DES MESSAGES ====================
        
        function startPolling() {
            if (pollInterval) clearInterval(pollInterval);
            pollInterval = setInterval(() => {
                if (selectedUserId && !isSelfChat) {
                    checkNewMessages();
                }
            }, 3000);
        }
        
        function startTypingPolling() {
            if (typingPollInterval) clearInterval(typingPollInterval);
            typingPollInterval = setInterval(() => {
                if (selectedUserId && !isSelfChat) {
                    checkTyping();
                }
            }, 2000);
        }
        
        async function loadMessages(before = 0) {
            if (isLoading) return;
            isLoading = true;
            
            try {
                const formData = new FormData();
                formData.append('ajax', 1);
                formData.append('action', 'load_messages');
                formData.append('with', selectedUserId);
                formData.append('before', before);
                formData.append('limit', 20);
                formData.append('csrf_token', csrfToken);
                
                const response = await fetch('', { method: 'POST', body: formData });
                const data = await response.json();
                
                if (data.success) {
                    const container = document.getElementById('messagesContainer');
                    if (before === 0) {
                        container.innerHTML = '';
                        lastMessageId = 0;
                    }
                    
                    data.messages.forEach(msg => {
                        appendMessageToContainer(msg, before === 0);
                        if (msg.id > lastMessageId) lastMessageId = msg.id;
                    });
                    hasMoreMessages = data.has_more;
                    
                    if (before === 0) {
                        scrollToBottom();
                    }
                    
                    if (hasMoreMessages && before === 0) {
                        addLoadMoreTrigger();
                    }
                }
            } catch (err) {
                console.error('Error loading messages:', err);
                showToast('Erreur de chargement', 'error');
            } finally {
                isLoading = false;
            }
        }
        
        function appendMessageToContainer(msg, isNew = true) {
            const container = document.getElementById('messagesContainer');
            const isMine = msg.id_expediteur == currentUserId;
            const messageHtml = createMessageHtml(msg, isMine);
            
            if (isNew) {
                container.insertAdjacentHTML('beforeend', messageHtml);
            } else {
                const firstChild = container.firstChild;
                if (firstChild) {
                    container.insertAdjacentHTML('afterbegin', messageHtml);
                } else {
                    container.innerHTML = messageHtml;
                }
            }
        }
        
        function createMessageHtml(msg, isMine) {
            const time = new Date(msg.datemessage).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' });
            const bubbleClass = isMine ? (isSelfChat ? 'message-self' : 'message-sent') : 'message-received';
            
            let fileHtml = '';
            if (msg.file_url) {
                const fileType = msg.type;
                if (fileType === 'voice') {
                    fileHtml = `
                        <div class="audio-player mt-2 flex items-center gap-2 p-2 bg-white/20 rounded-lg">
                            <i class="fas fa-microphone-alt"></i>
                            <audio controls class="h-8" preload="none">
                                <source src="${msg.file_url}" type="audio/webm">
                            </audio>
                            <span class="text-xs font-mono">${formatDuration(msg.file_duration)}</span>
                        </div>
                    `;
                } else if (fileType === 'image') {
                    fileHtml = `
                        <div class="mt-2 cursor-pointer" onclick="window.open('${msg.file_url}', '_blank')">
                            <img src="${msg.file_url}" class="max-w-full max-h-64 rounded-lg shadow-md hover:opacity-90 transition-opacity">
                        </div>
                    `;
                } else if (fileType === 'video') {
                    fileHtml = `
                        <video controls class="max-w-full max-h-64 rounded-lg mt-2" preload="metadata">
                            <source src="${msg.file_url}" type="video/mp4">
                        </video>
                    `;
                } else {
                    const fileIcon = msg.file_url?.match(/\.(pdf)$/i) ? 'fa-file-pdf' : 
                                     (msg.file_url?.match(/\.(doc|docx)$/i) ? 'fa-file-word' : 'fa-file');
                    fileHtml = `
                        <a href="${msg.file_url}" download class="file-card block mt-2 p-3 ${isMine ? 'bg-white/20' : 'bg-gray-100'} rounded-lg hover:opacity-90 transition-all">
                            <div class="flex items-center gap-3">
                                <i class="fas ${fileIcon} text-2xl"></i>
                                <div class="flex-1 min-w-0">
                                    <p class="text-sm font-medium truncate">${escapeHtml(msg.file_name)}</p>
                                    <p class="text-xs opacity-75">${formatFileSize(msg.file_size)}</p>
                                </div>
                                <i class="fas fa-download text-sm"></i>
                            </div>
                        </a>
                    `;
                }
            }
            
            return `
                <div class="message-item flex ${isMine ? 'justify-end' : 'justify-start'} animate-fadeIn" data-message-id="${msg.idmessage}" oncontextmenu="showMessageContextMenu(event, ${msg.idmessage}, ${isMine})">
                    ${!isMine && !isSelfChat ? `
                        <img src="<?= AVATAR_URL ?>${msg.avatar || 'default.jpg'}" class="w-8 h-8 rounded-full object-cover mr-2 self-end shadow-sm">
                    ` : ''}
                    <div class="message-bubble max-w-[75%] ${bubbleClass} p-3 shadow-sm">
                        ${msg.textemessage ? `<p class="text-sm ${isMine || isSelfChat ? 'text-white' : 'text-gray-800'} break-words leading-relaxed">${msg.textemessage}</p>` : ''}
                        ${fileHtml}
                        <p class="text-xs ${isMine || isSelfChat ? 'text-orange-200' : 'text-gray-400'} mt-1 flex items-center gap-1">
                            <i class="far fa-clock"></i>
                            ${time}
                            ${isMine ? '<i class="fas fa-check-double text-[10px] ml-1"></i>' : ''}
                        </p>
                    </div>
                </div>
            `;
        }
        
        function addLoadMoreTrigger() {
            const container = document.getElementById('messagesContainer');
            const trigger = document.createElement('div');
            trigger.id = 'loadMoreTrigger';
            trigger.className = 'text-center py-3 cursor-pointer hover:bg-gray-100 rounded-lg transition-colors';
            trigger.innerHTML = `
                <i class="fas fa-chevron-up text-gray-400"></i>
                <span class="text-xs text-gray-500 ml-2">Charger plus de messages</span>
            `;
            trigger.onclick = () => {
                const firstMsg = document.querySelector('.message-item:first-child');
                const firstId = firstMsg?.dataset?.messageId;
                if (firstId) loadMessages(parseInt(firstId));
                trigger.remove();
            };
            container.insertBefore(trigger, container.firstChild);
        }
        
        async function checkNewMessages() {
            try {
                const formData = new FormData();
                formData.append('ajax', 1);
                formData.append('action', 'load_messages');
                formData.append('with', selectedUserId);
                formData.append('before', 0);
                formData.append('limit', 1);
                formData.append('csrf_token', csrfToken);
                
                const response = await fetch('', { method: 'POST', body: formData });
                const data = await response.json();
                
                if (data.success && data.messages.length > 0) {
                    const latestMsg = data.messages[data.messages.length - 1];
                    if (latestMsg.id > lastMessageId && latestMsg.id_expediteur != currentUserId) {
                        loadMessages();
                    }
                }
            } catch (err) {
                console.error('Error checking messages:', err);
            }
        }
        
        async function checkTyping() {
            try {
                const formData = new FormData();
                formData.append('ajax', 1);
                formData.append('action', 'check_typing');
                formData.append('from', selectedUserId);
                formData.append('csrf_token', csrfToken);
                
                const response = await fetch('', { method: 'POST', body: formData });
                const data = await response.json();
                
                const indicator = document.getElementById('typingIndicator');
                if (indicator) {
                    if (data.typing) {
                        indicator.classList.remove('hidden');
                    } else {
                        indicator.classList.add('hidden');
                    }
                }
            } catch (err) {
                console.error('Error checking typing:', err);
            }
        }
        
        async function sendMessage() {
            const input = document.getElementById('messageInput');
            const message = input.value.trim();
            if (!message && !selectedFile && !isRecording) return;
            
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
                if (selectedFile) formData.append('file', selectedFile);
                
                const response = await fetch('', { method: 'POST', body: formData });
                const data = await response.json();
                
                if (data.success) {
                    input.value = '';
                    autoResizeTextarea(input);
                    appendMessage(data.message);
                    scrollToBottom();
                    lastMessageId = data.message_id;
                    cancelFile();
                } else {
                    showToast(data.error || 'Erreur d\'envoi', 'error');
                }
            } catch (err) {
                console.error('Error:', err);
                showToast('Erreur de connexion', 'error');
            } finally {
                sendBtn.disabled = false;
                sendBtn.innerHTML = '<i class="fas fa-paper-plane"></i>';
            }
        }
        
        function appendMessage(msg) {
            const container = document.getElementById('messagesContainer');
            const isMine = msg.is_mine === true;
            const time = msg.time;
            
            let fileHtml = '';
            if (msg.file) {
                if (msg.file.type === 'voice') {
                    fileHtml = `
                        <div class="audio-player mt-2 flex items-center gap-2 p-2 bg-white/20 rounded-lg">
                            <i class="fas fa-microphone-alt"></i>
                            <audio controls class="h-8">
                                <source src="${msg.file.url}" type="audio/webm">
                            </audio>
                            <span class="text-xs">${formatDuration(msg.file.duration)}</span>
                        </div>
                    `;
                } else if (msg.file.type === 'image') {
                    fileHtml = `<img src="${msg.file.url}" class="max-w-full max-h-64 rounded-lg mt-2 cursor-pointer" onclick="window.open('${msg.file.url}', '_blank')">`;
                } else {
                    fileHtml = `
                        <a href="${msg.file.url}" download class="file-card block mt-2 p-3 ${isMine ? 'bg-white/20' : 'bg-gray-100'} rounded-lg">
                            <div class="flex items-center gap-3">
                                <i class="fas fa-file-pdf text-2xl"></i>
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
            
            const bubbleClass = isMine ? (isSelfChat ? 'message-self' : 'message-sent') : 'message-received';
            
            const messageHtml = `
                <div class="message-item flex ${isMine ? 'justify-end' : 'justify-start'} animate-fadeIn">
                    ${!isMine && !isSelfChat ? `<img src="<?= AVATAR_URL ?>${msg.avatar || 'default.jpg'}" class="w-8 h-8 rounded-full object-cover mr-2 self-end">` : ''}
                    <div class="message-bubble max-w-[75%] ${bubbleClass} p-3 shadow-sm">
                        ${msg.text ? `<p class="text-sm ${isMine || isSelfChat ? 'text-white' : 'text-gray-800'} break-words">${msg.text}</p>` : ''}
                        ${fileHtml}
                        <p class="text-xs ${isMine || isSelfChat ? 'text-orange-200' : 'text-gray-400'} mt-1 flex items-center gap-1">
                            <i class="far fa-clock"></i>${time}
                            ${isMine ? '<i class="fas fa-check-double text-[10px] ml-1"></i>' : ''}
                        </p>
                    </div>
                </div>
            `;
            container.insertAdjacentHTML('beforeend', messageHtml);
        }
        
        async function deleteMessage(messageId, deleteFor) {
            try {
                const formData = new FormData();
                formData.append('ajax', 1);
                formData.append('action', 'delete_message');
                formData.append('message_id', messageId);
                formData.append('delete_for', deleteFor);
                formData.append('csrf_token', csrfToken);
                
                const response = await fetch('', { method: 'POST', body: formData });
                const data = await response.json();
                
                if (data.success) {
                    const msgDiv = document.querySelector(`[data-message-id="${messageId}"]`);
                    if (msgDiv) {
                        msgDiv.style.animation = 'fadeOut 0.3s ease-out';
                        setTimeout(() => msgDiv.remove(), 300);
                    }
                    showToast('Message supprimé', 'success');
                }
            } catch (err) {
                console.error('Error:', err);
                showToast('Erreur de connexion', 'error');
            }
        }
        
        function showMessageContextMenu(event, messageId, isMine) {
            event.preventDefault();
            
            const existingMenu = document.getElementById('dynamicContextMenu');
            if (existingMenu) existingMenu.remove();
            
            const menu = document.createElement('div');
            menu.id = 'dynamicContextMenu';
            menu.className = 'fixed bg-white rounded-xl shadow-xl border border-gray-200 z-50 min-w-[200px] overflow-hidden';
            menu.style.left = event.pageX + 'px';
            menu.style.top = event.pageY + 'px';
            
            let menuHTML = `
                <div class="context-menu-item px-4 py-2.5 hover:bg-gray-50 cursor-pointer flex items-center gap-3" onclick="deleteMessage(${messageId}, 'me')">
                    <i class="fas fa-trash-alt text-gray-400 w-4"></i>
                    <span class="text-sm">Supprimer pour moi</span>
                </div>
            `;
            
            if (isMine) {
                menuHTML += `
                    <div class="context-menu-item px-4 py-2.5 hover:bg-red-50 cursor-pointer flex items-center gap-3 border-t border-gray-100" onclick="deleteMessage(${messageId}, 'everyone')">
                        <i class="fas fa-trash-alt text-red-500 w-4"></i>
                        <span class="text-sm text-red-600">Supprimer pour tout le monde</span>
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
        
        async function clearConversation() {
            if (!confirm('⚠️ Supprimer toute cette conversation ? Cette action est irréversible.')) return;
            
            try {
                const formData = new FormData();
                formData.append('ajax', 1);
                formData.append('action', 'delete_conversation');
                formData.append('with', selectedUserId);
                formData.append('csrf_token', csrfToken);
                
                const response = await fetch('', { method: 'POST', body: formData });
                const data = await response.json();
                
                if (data.success) {
                    location.reload();
                }
            } catch (err) {
                console.error('Error:', err);
                showToast('Erreur lors de la suppression', 'error');
            }
        }
        
        function blockUser() {
            showToast('Fonctionnalité en développement', 'info');
        }
        
        // ==================== GESTION DES FICHIERS ====================
        
        function toggleFileMenu() {
            document.getElementById('fileMenu').classList.toggle('hidden');
        }
        
        function selectFileType(type) {
            document.getElementById('fileMenu').classList.add('hidden');
            let accept = { 
                image: 'image/*', 
                video: 'video/*', 
                document: '.pdf,.doc,.docx,.txt,.zip,.rar', 
                audio: 'audio/*' 
            }[type];
            document.getElementById('fileInput').setAttribute('accept', accept);
            document.getElementById('fileInput').click();
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
        
        function cancelFile() {
            selectedFile = null;
            document.getElementById('fileInput').value = '';
            document.getElementById('filePreview').classList.add('hidden');
        }
        
        function sendFile() {
            sendMessage();
        }
        
        // ==================== ENREGISTREMENT VOCAL ====================
        
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
                                showToast(data.error || 'Erreur d\'envoi', 'error');
                            }
                        } catch (err) {
                            console.error('Error:', err);
                            showToast('Erreur de connexion', 'error');
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
                
                document.getElementById('voiceRecordBtn').innerHTML = '<i class="fas fa-stop text-red-500"></i><span class="text-xs text-red-500 ml-1">Arrêter</span>';
                document.getElementById('recordingIndicator').classList.remove('hidden');
                document.getElementById('voicePreview').classList.remove('hidden');
                
                recordingTimer = setInterval(updateRecordingTime, 1000);
                
            } catch (err) {
                console.error('Error accessing microphone:', err);
                showToast('Impossible d\'accéder au microphone. Vérifiez les permissions.', 'error');
            }
        }
        
        function stopRecording() {
            if (mediaRecorder && isRecording) {
                mediaRecorder.stop();
                isRecording = false;
                
                document.getElementById('voiceRecordBtn').innerHTML = '<i class="fas fa-microphone"></i><span class="text-xs ml-1">Note vocale</span>';
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
        
        // ==================== ÉMOJIS ====================
        
        function toggleEmojiPicker() {
            const picker = document.getElementById('emojiPicker');
            picker.classList.toggle('hidden');
        }
        
        function addEmoji(emoji) {
            const input = document.getElementById('messageInput');
            input.value += emoji;
            input.focus();
            document.getElementById('emojiPicker').classList.add('hidden');
            autoResizeTextarea(input);
        }
        
        // ==================== TYPING ====================
        
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
            if (!textarea) return;
            textarea.style.height = 'auto';
            textarea.style.height = Math.min(textarea.scrollHeight, 120) + 'px';
        }
        
        // ==================== NAVIGATION ====================
        
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
        
        function showChatActions() {
            document.getElementById('chatActionsModal').classList.remove('hidden');
            document.getElementById('chatActionsModal').classList.add('flex');
        }
        
        function closeChatActions() {
            const modal = document.getElementById('chatActionsModal');
            modal.classList.remove('flex');
            modal.classList.add('hidden');
        }
        
        // ==================== UTILITAIRES ====================
        
        function formatFileSize(bytes) {
            if (bytes === 0) return '0 B';
            const k = 1024;
            const sizes = ['B', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
        }
        
        function formatDuration(seconds) {
            if (!seconds) return '0:00';
            const mins = Math.floor(seconds / 60);
            const secs = seconds % 60;
            return `${mins}:${secs.toString().padStart(2, '0')}`;
        }
        
        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        function showToast(message, type = 'info') {
            const container = document.getElementById('toastContainer') || (() => {
                const div = document.createElement('div');
                div.id = 'toastContainer';
                div.className = 'fixed bottom-4 right-4 z-50 space-y-2';
                document.body.appendChild(div);
                return div;
            })();
            
            const colors = {
                success: 'bg-gradient-to-r from-green-500 to-green-600',
                error: 'bg-gradient-to-r from-red-500 to-red-600',
                info: 'bg-gradient-to-r from-blue-500 to-blue-600',
                warning: 'bg-gradient-to-r from-yellow-500 to-yellow-600'
            };
            
            const toast = document.createElement('div');
            toast.className = `${colors[type]} text-white px-5 py-3 rounded-xl shadow-lg flex items-center gap-3 toast`;
            toast.innerHTML = `
                <i class="fas ${type === 'success' ? 'fa-check-circle' : type === 'error' ? 'fa-exclamation-circle' : 'fa-info-circle'}"></i>
                <span class="font-medium">${message}</span>
                <button onclick="this.parentElement.remove()" class="ml-4 text-white/70 hover:text-white">
                    <i class="fas fa-times"></i>
                </button>
            `;
            
            container.appendChild(toast);
            setTimeout(() => {
                toast.style.opacity = '0';
                toast.style.transform = 'translateX(20px)';
                setTimeout(() => toast.remove(), 300);
            }, 4000);
        }
        
        // Fermeture des modals au clic extérieur
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
        
        // MutationObserver pour les nouveaux messages
        const observer = new MutationObserver((mutations) => {
            mutations.forEach((mutation) => {
                mutation.addedNodes.forEach((node) => {
                    if (node.nodeType === 1 && node.classList && node.classList.contains('message-item')) {
                        node.style.animation = 'fadeInUp 0.3s ease-out';
                    }
                });
            });
        });
        
        const messagesContainer = document.getElementById('messagesContainer');
        if (messagesContainer) {
            observer.observe(messagesContainer, { childList: true, subtree: true });
        }
    </script>
</body>
</html>