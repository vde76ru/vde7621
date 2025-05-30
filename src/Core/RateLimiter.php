<?php
namespace App\Core;

/**
 * Rate Limiter для защиты от DDoS
 */
class RateLimiter
{
    private PDO $pdo;
    
    public function __construct()
    {
        $this->pdo = Database::getConnection();
        $this->createTable();
    }
    
    private function createTable(): void
    {
        $sql = "
            CREATE TABLE IF NOT EXISTS rate_limits (
                id VARCHAR(255) PRIMARY KEY,
                action VARCHAR(50) NOT NULL,
                count INT NOT NULL DEFAULT 0,
                window_start INT NOT NULL,
                INDEX idx_window (window_start)
            ) ENGINE=MEMORY
        ";
        
        $this->pdo->exec($sql);
    }
    
    public function check(string $identifier, string $action, int $windowSeconds, int $maxAttempts): bool
    {
        $now = time();
        $windowStart = floor($now / $windowSeconds) * $windowSeconds;
        $key = md5($identifier . ':' . $action . ':' . $windowStart);
        
        // Очистка старых записей
        $this->pdo->exec("DELETE FROM rate_limits WHERE window_start < " . ($now - 3600));
        
        // Проверка текущего лимита
        $stmt = $this->pdo->prepare("
            INSERT INTO rate_limits (id, action, count, window_start) 
            VALUES (:id, :action, 1, :window)
            ON DUPLICATE KEY UPDATE count = count + 1
        ");
        
        $stmt->execute([
            'id' => $key,
            'action' => $action,
            'window' => $windowStart
        ]);
        
        // Получаем текущий счетчик
        $stmt = $this->pdo->prepare("SELECT count FROM rate_limits WHERE id = :id");
        $stmt->execute(['id' => $key]);
        $count = (int)$stmt->fetchColumn();
        
        return $count <= $maxAttempts;
    }
}