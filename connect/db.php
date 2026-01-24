<?php
/**
 * Файл: /connect/db.php - Singleton PDO с Persistent Connections
 */

// Запрещаем прямой доступ.
if (!defined('APP_ACCESS')) {
    http_response_code(403);
    exit('Доступ запрещён');
}

class Database {
    private static $instance = null;
    private static $host = 'localhost';
    private static $dbname = 'u5954392_install';
    private static $user = 'u5954392_install';
    private static $pass = 'N5%BvUvgLOir';

    private function __construct() {
        $dsn = "mysql:host=" . self::$host . ";dbname=" . self::$dbname . ";charset=utf8";
        $options = [
            PDO::ATTR_PERSISTENT => true,           // ✅ ПЕРЕИСПЛЬЗУЕМ соеденения
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            MYSQLI_OPT_CONNECT_TIMEOUT => 10        // Таймаут 10 сек
        ];
        
        $this->pdo = new PDO($dsn, self::$user, self::$pass, $options);
    }

    public static function get() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance->pdo;
    }

    // Закрытие соединения (вызывать при необходимости)
    public static function close() {
        self::$instance = null;
    }
}

// Глобальная переменная для удобства (как раньше)
$pdo = Database::get();
?>
