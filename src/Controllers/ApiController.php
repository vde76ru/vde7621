<?php
namespace App\Controllers;

use App\Core\Database;
use App\Services\SearchService;
use App\Services\DynamicProductDataService;
use App\DTO\ProductAvailabilityDTO;
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
            // Логируем входные данные
            Logger::info('API Availability called', [
                'get_params' => $_GET,
                'city_id' => $_GET['city_id'] ?? 'missing',
                'product_ids' => $_GET['product_ids'] ?? 'missing'
            ]);
    
            $validated = $this->validate($_GET, [
                'city_id' => 'required|integer|min:1',
                'product_ids' => 'required|string|max:10000'
            ]);
            
            Logger::info('Validation passed', ['validated' => $validated]);
            
            $productIds = array_map('intval', explode(',', $validated['product_ids']));
            $productIds = array_filter($productIds, fn($id) => $id > 0);
            
            Logger::info('Product IDs processed', ['product_ids' => $productIds]);
            
            if (empty($productIds)) {
                $this->error('Нет валидных product_ids', 400);
                return;
            }
            
            $cityId = $validated['city_id'];
            
            $dynamicService = new DynamicProductDataService();
            $userId = AuthService::check() ? AuthService::user()['id'] : null;
            
            Logger::info('Before getting dynamic data', [
                'product_ids' => $productIds,
                'city_id' => $cityId,
                'user_id' => $userId
            ]);
            
            $dynamicData = $dynamicService->getProductsDynamicData($productIds, $cityId, $userId);
            
            Logger::info('Dynamic data received', ['data' => $dynamicData]);
            
            // Остальной код...
            
        } catch (\Exception $e) {
            Logger::error('API Availability error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
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