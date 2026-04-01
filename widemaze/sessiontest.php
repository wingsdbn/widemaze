<?php
require_once 'config.php';
?>
<!DOCTYPE html>
<html>
<head><title>Test Session</title></head>
<body>
<h1>Test Session</h1>
<pre>
<?php
echo "Session ID: " . session_id() . "\n";
echo "Session data:\n";
print_r($_SESSION);
?>
</pre>
<?php if (isset($_SESSION['user_id'])): ?>
    <p style="color:green">Connecté en tant que <?= $_SESSION['user_id'] ?></p>
    <a href="index.php">Aller à index.php</a>
<?php else: ?>
    <p style="color:red">Non connecté</p>
    <a href="connexion.php">Se connecter</a>
<?php endif; ?>
</body>
</html>