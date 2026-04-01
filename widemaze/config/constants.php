<?php
/**
 * WideMaze - Constants Configuration
 * Centralized constants for the entire application
 */

// Database Constants
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'widemaze');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_PORT', '3306');
define('DB_CHARSET', 'utf8mb4');

// Application Constants
define('SITE_NAME', 'WideMaze');
define('SITE_URL', 'http://localhost/widemaze');
define('SITE_EMAIL', 'noreply@widemaze.com');
define('SITE_VERSION', '5.0.0');

// Directory Constants
define('BASE_DIR', dirname(__DIR__));
define('UPLOAD_DIR', BASE_DIR . '/uploads/');
define('AVATAR_DIR', UPLOAD_DIR . 'avatars/');
define('POSTS_DIR', UPLOAD_DIR . 'posts/');
define('COVERS_DIR', UPLOAD_DIR . 'covers/');
define('DOCUMENTS_DIR', UPLOAD_DIR . 'documents/');
define('MESSAGES_DIR', UPLOAD_DIR . 'messages/');
define('LOGS_DIR', BASE_DIR . '/logs/');

// URL Constants
define('AVATAR_URL', SITE_URL . '/uploads/avatars/');
define('POSTS_URL', SITE_URL . '/uploads/posts/');
define('COVERS_URL', SITE_URL . '/uploads/covers/');
define('DEFAULT_AVATAR', 'default-avatar.png');
define('STORIES_DIR', UPLOAD_DIR . 'stories/');
define('STORIES_URL', SITE_URL . '/uploads/stories/');

// Security Constants
define('SESSION_LIFETIME', 1800);
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOCKOUT_DURATION', 900);
define('RATE_LIMIT_REQUESTS', 100);
define('RATE_LIMIT_WINDOW', 60);
define('MAX_FILE_SIZE', 10 * 1024 * 1024);

// Pagination Constants
define('POSTS_PER_PAGE', 10);
define('USERS_PER_PAGE', 20);
define('COMMENTS_PER_PAGE', 20);
define('MESSAGES_PER_PAGE', 50);

// Allowed File Types
define('ALLOWED_IMAGE_TYPES', ['image/jpeg', 'image/png', 'image/gif', 'image/webp']);
define('ALLOWED_DOCUMENT_TYPES', ['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document']);
define('ALLOWED_VIDEO_TYPES', ['video/mp4', 'video/webm', 'video/ogg']);

// Post Privacy Options
define('PRIVACY_PUBLIC', 'public');
define('PRIVACY_FRIENDS', 'friends');
define('PRIVACY_PRIVATE', 'private');

// Notification Types
define('NOTIF_LIKE', 'like');
define('NOTIF_COMMENT', 'comment');
define('NOTIF_FRIEND_REQUEST', 'friend_request');
define('NOTIF_FRIEND_ACCEPT', 'friend_accept');
define('NOTIF_MESSAGE', 'message');
define('NOTIF_MENTION', 'mention');
define('NOTIF_SYSTEM', 'system');
define('NOTIF_ANNOUNCEMENT', 'announcement');
define('NOTIF_POST', 'post');
define('NOTIF_SHARE', 'share');
define('NOTIF_COMMUNITY_POST', 'community_post');

// User Roles
define('ROLE_USER', 'etudiant');
define('ROLE_TEACHER', 'professeur');
define('ROLE_ADMIN', 'admin');

// HTTP Status Codes
define('STATUS_OK', 200);
define('STATUS_CREATED', 201);
define('STATUS_BAD_REQUEST', 400);
define('STATUS_UNAUTHORIZED', 401);
define('STATUS_FORBIDDEN', 403);
define('STATUS_NOT_FOUND', 404);
define('STATUS_METHOD_NOT_ALLOWED', 405);
define('STATUS_CONFLICT', 409);
define('STATUS_TOO_MANY_REQUESTS', 429);
define('STATUS_SERVER_ERROR', 500);