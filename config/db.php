<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        return $pdo;
    } catch (PDOException $e) {
        http_response_code(500);
        echo '<div style="max-width:900px;margin:40px auto;font:16px Arial;color:#fff;background:#0d2238;padding:24px;border-radius:14px">';
        echo '<h2>Não foi possível ligar à base de dados.</h2>';
        echo '<p>Confirma que o MySQL está iniciado no XAMPP e que importaste o ficheiro <b>database.sql</b>.</p>';
        echo '<p>Também podes alterar utilizador/password em <b>config/config.php</b>.</p>';
        echo '<pre style="white-space:pre-wrap;color:#9fc0dc">' . htmlspecialchars($e->getMessage()) . '</pre></div>';
        exit;
    }
}
