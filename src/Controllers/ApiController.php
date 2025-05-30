<?php
namespace App\Controllers;

use App\Core\Database;
use App\Services\SearchService;
use App\Services\DynamicProductDataService;
use App\Services\AuthService;
use App\Core\Logger;

class ApiController extends BaseController
{
    /**
     * GET /api/availability
     * Получение информации о наличии товаров
     */
    public function availabilityAction(): void
    {
        try {
            // Для GET запросов используем только $_GET
            $validated = $this->validate($_GET, [
                'city_id' => 'required|integer|min:1',
                'product_ids' => 'required|string|max:10000'
            ]);
            
            $productIds = array_map('intval', explode(',', $validated['product_ids']));
            $productIds = array_filter($productIds, fn($id) => $id > 0);
            
            if (empty($productIds)) {
                $this->error('Нет валидных product_ids', 400);
                return;
            }
            
            $cityId = $validated['city_id'];
            
            $dynamicService = new DynamicProductDataService();
            $userId = AuthService::check() ? AuthService::user()['id'] : null;
            
            $data = $dynamicService->getProductsDynamicData($productIds, $cityId, $userId);
            
            $this->success($data);
            
        } catch (\Exception $e) {
            Logger::error('API Availability error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            $this->error('Ошибка проверки наличия: ' . $e->getMessage(), 500);
        }
    }
    
    /**
     * GET /api/search
     * Поиск товаров через OpenSearch
     */
    public function searchAction(): void
    {
        try {
            // Логируем для отладки
            Logger::info('API Search called', ['params' => $this->getInput()]);
            
            $params = $this->validate($this->getInput(), [
                'q' => 'string|max:500',
                'page' => 'integer|min:1|max:1000',
                'limit' => 'integer|min:1|max:100',
                'city_id' => 'integer|min:1',
                'sort' => 'string|in:relevance,name,price_asc,price_desc,availability'
            ]);
            
            // Добавляем user_id для персонализации
            if (AuthService::check()) {
                $params['user_id'] = AuthService::user()['id'];
            }
            
            $result = SearchService::search($params);
            
            Logger::info('Search completed', [
                'query' => $params['q'] ?? '',
                'results_count' => $result['total'] ?? 0
            ]);
            
            $this->success($result);
            
        } catch (\Exception $e) {
            Logger::error('API Search error', [
                'params' => $this->getInput(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            $this->error('Ошибка поиска: ' . $e->getMessage(), 500);
        }
    }
    
    /**
     * GET /api/autocomplete
     * Автодополнение для поисковой строки
     */
    public function autocompleteAction(): void
    {
        try {
            Logger::info('API Autocomplete called', ['params' => $this->getInput()]);
            
            $validated = $this->validate($this->getInput(), [
                'q' => 'required|string|min:2|max:100',
                'limit' => 'integer|min:1|max:20'
            ]);
            
            $suggestions = SearchService::autocomplete(
                $validated['q'], 
                $validated['limit'] ?? 10
            );
            
            $this->success(['suggestions' => $suggestions]);
            
        } catch (\Exception $e) {
            Logger::error('API Autocomplete error', [
                'params' => $this->getInput(),
                'error' => $e->getMessage()
            ]);
            
            $this->error('Ошибка автодополнения: ' . $e->getMessage(), 500);
        }
    }
    
    /**
     * Тестовый эндпоинт для проверки работы API
     * GET /api/test
     */
    public function testAction(): void
    {
        $this->success([
            'message' => 'API работает корректно!',
            'timestamp' => date('Y-m-d H:i:s'),
            'user_authenticated' => AuthService::check(),
            'server_info' => [
                'php_version' => PHP_VERSION,
                'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown'
            ]
        ]);
    }
}
