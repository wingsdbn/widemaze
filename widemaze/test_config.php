<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>Test config.php</h1>";

// 1. Tester l'inclusion de config.php
echo "<h2>1. Inclusion de config.php</h2>";
try {
    require_once __DIR__ . '/config/config.php';
    echo "✅ config.php chargé<br>";
    echo "✅ SITE_URL = " . SITE_URL . "<br>";
    echo "✅ AVATAR_URL = " . AVATAR_URL . "<br>";
} catch (Exception $e) {
    echo "❌ Erreur: " . $e->getMessage() . "<br>";
    exit();
}

// 2. Tester la connexion à la base de données
echo "<h2>2. Connexion à la base de données</h2>";
try {
    $stmt = $pdo->query("SELECT 1");
    echo "✅ Connexion PDO OK<br>";
    
    $stmt = $pdo->query("SELECT COUNT(*) FROM utilisateurs");
    $count = $stmt->fetchColumn();
    echo "✅ Nombre d'utilisateurs : " . $count . "<br>";
} catch (PDOException $e) {
    echo "❌ Erreur PDO: " . $e->getMessage() . "<br>";
}

// 3. Tester les fonctions
echo "<h2>3. Tests des fonctions</h2>";
try {
    if (function_exists('getAvatarUrl')) {
        echo "✅ getAvatarUrl() existe<br>";
    } else {
        echo "❌ getAvatarUrl() n'existe pas<br>";
    }
    
    if (function_exists('generate_csrf_token')) {
        echo "✅ generate_csrf_token() existe<br>";
        $token = generate_csrf_token();
        echo "   Token généré: " . substr($token, 0, 20) . "...<br>";
    } else {
        echo "❌ generate_csrf_token() n'existe pas<br>";
    }
    
    if (function_exists('is_logged_in')) {
        echo "✅ is_logged_in() existe<br>";
    } else {
        echo "❌ is_logged_in() n'existe pas<br>";
    }
} catch (Exception $e) {
    echo "❌ Erreur fonction: " . $e->getMessage() . "<br>";
}

// 4. Tester les constantes
echo "<h2>4. Constantes définies</h2>";
$constants = ['DB_HOST', 'DB_NAME', 'SITE_URL', 'AVATAR_URL', 'DEFAULT_AVATAR', 'POSTS_PER_PAGE'];
foreach ($constants as $const) {
    if (defined($const)) {
        echo "✅ $const = " . constant($const) . "<br>";
    } else {
        echo "❌ $const non définie<br>";
    }
}

echo "<h2>5. Fin du test</h2>";
echo "✅ Tout est OK si vous voyez ce message !";