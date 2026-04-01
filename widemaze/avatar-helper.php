<?php
// avatar_helper.php
if (!function_exists('getAvatarUrl')) {
    function getAvatarUrl($avatar) {
        define('AVATAR_DIR', 'uploads/avatars/');
        define('AVATAR_URL', 'uploads/avatars/');
        define('DEFAULT_AVATAR', 'default-avatar.png');
        
        if (!empty($avatar) && file_exists(AVATAR_DIR . $avatar)) {
            return AVATAR_URL . $avatar;
        }
        return AVATAR_URL . DEFAULT_AVATAR;
    }
}

if (!function_exists('getUserAvatar')) {
    function getUserAvatar($user) {
        if (is_array($user) && !empty($user['avatar'])) {
            $avatar = $user['avatar'];
        } elseif (is_object($user) && !empty($user->avatar)) {
            $avatar = $user->avatar;
        } else {
            $avatar = null;
        }
        return getAvatarUrl($avatar);
    }
}
?>