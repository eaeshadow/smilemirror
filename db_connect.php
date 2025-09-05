<?php
// Configurações do banco de dados
define('DB_HOST', 'localhost'); 
define('DB_USER', 'root'); 
define('DB_PASS', ""); 
define('DB_NAME', 'chatdb'); 

// Habilita erros (retirar em produção)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
} catch(PDOException $e) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Erro de conexão com o banco de dados: ' . $e->getMessage()]);
    die();
}
?>
