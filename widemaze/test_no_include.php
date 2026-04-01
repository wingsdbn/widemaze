<?php
// Ne rien inclure du tout
echo "1. Début du test<br>";

// Tester la session
session_start();
echo "2. Session démarrée<br>";

// Tester la connexion PDO directe
try {
    $pdo = new PDO("mysql:host=127.0.0.1;dbname=widemaze;charset=utf8mb4", "root", "");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "3. Connexion PDO directe OK<br>";
    
    $stmt = $pdo->query("SELECT COUNT(*) FROM utilisateurs");
    $count = $stmt->fetchColumn();
    echo "4. Nombre d'utilisateurs : " . $count . "<br>";
    
    echo "5. TOUT FONCTIONNE !<br>";
    
} catch (PDOException $e) {
    echo "❌ Erreur PDO: " . $e->getMessage() . "<br>";
}