<?php
namespace App\Controllers;

use App\Core\Logger;
use App\Core\Validator;
use App\Services\AuthService; // ⭐ Добавляем этот импорт!
use App\Exceptions\ValidationException;

/**
 * Базовый контроллер с общей функциональностью
 * 
 * Этот класс содержит все общие методы, которые используют другие контроллеры.
 * Думайте о нем как о "фундаменте" для всех ваших API контроллеров.
 */
abstract class BaseController
{
    protected array $data = [];
    protected int $statusCode = 200;
    
    /**
     * Валидация входных данных
     * 
     * Этот метод проверяет, что данные от пользователя соответствуют нашим правилам.
     * Например, что email действительно выглядит как email, а возраст - это число.
     */
    protected function validate(array $data, array $rules): array
    {
        $validator = new Validator($data, $rules);
        
        if (!$validator->passes()) {
            throw new ValidationException("Validation failed", $validator->errors());
        }
        
        return $validator->validated();
    }

    /**
     * JSON ответ
     * 
     * Используйте этот метод для отправки данных в формате JSON.
     * Он автоматически установит правильные заголовки и код ответа.
     */
    protected function jsonResponse(array $data, int $statusCode = 200): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * Успешный ответ
     * 
     * Используйте этот метод когда всё прошло хорошо.
     * Пример: $this->success(['users' => $usersList]);
     */
    protected function success($data = null, string $message = 'Success'): void
    {
        $response = [
            'success' => true,
            'message' => $message
        ];
        
        if ($data !== null) {
            $response['data'] = $data;
        }
        
        $this->jsonResponse($response);
    }

    /**
     * Ответ с ошибкой
     * 
     * Используйте этот метод когда что-то пошло не так.
     * Пример: $this->error('Пользователь не найден', 404);
     */
    protected function error(string $message, int $statusCode = 400, array $errors = []): void
    {
        $response = [
            'success' => false,
            'message' => $message
        ];
        
        if (!empty($errors)) {
            $response['errors'] = $errors;
        }
        
        Logger::warning("Controller error response", [
            'message' => $message,
            'status_code' => $statusCode,
            'errors' => $errors
        ]);
        
        $this->jsonResponse($response, $statusCode);
    }

    /**
     * Получить входные данные
     * 
     * Этот метод извлекает данные из HTTP запроса, 
     * независимо от того, как они были отправлены (GET, POST, JSON).
     */
    protected function getInput(): array
    {
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        
        if (strpos($contentType, 'application/json') !== false) {
            // Если данные пришли в формате JSON
            $input = json_decode(file_get_contents('php://input'), true);
            return $input ?: [];
        }
        
        // Обычные GET/POST параметры
        return array_merge($_GET, $_POST);
    }

    /**
     * Проверка аутентификации
     * 
     * Вызывайте этот метод в контроллерах, которые требуют авторизации.
     * Если пользователь не авторизован, автоматически вернется ошибка 401.
     */
    protected function requireAuth(): array
    {
        if (!AuthService::validateSession()) {
            $this->error('Authentication required', 401);
        }
        
        return [
            'user_id' => $_SESSION['user_id'],
            'username' => $_SESSION['username'],
            'role' => $_SESSION['role']
        ];
    }

    /**
     * Проверка прав доступа
     * 
     * Используйте этот метод для проверки роли пользователя.
     * Например: $this->requireRole('admin');
     */
    protected function requireRole(string $role): void
    {
        $user = $this->requireAuth();
        
        if ($user['role'] !== $role && $user['role'] !== 'admin') {
            $this->error('Insufficient permissions', 403);
        }
    }
}