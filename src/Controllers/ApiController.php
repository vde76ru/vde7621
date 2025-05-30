<?php
namespace App\Controllers;

use App\Services\SearchService;
use App\Services\DynamicProductDataService;
use App\DTO\ProductAvailabilityDTO;
use App\Services\AuthService;
use App\Core\Logger;

class ApiController extends BaseController
{
    /**
     * GET /api/availability - Оптимизированная версия
     */
    public function availabilityAction(): void
    {
        try {
            // Прямая валидация без Validator
            $cityId = (int)($_GET['city_id'] ?? 1);
            $productIdsStr = $_GET['product_ids'] ?? '';
            
            if ($cityId < 1 || empty($productIdsStr)) {
                $this->error('Неверные параметры', 400);
                return;
            }
            
            $productIds = array_map('intval', explode(',', $productIdsStr));
            $productIds = array_filter($productIds, fn($id) => $id > 0);
            
            if (empty($productIds)) {
                $this->error('Нет валидных product_ids', 400);
                return;
            }
            
            $dynamicService = new DynamicProductDataService();
            $userId = AuthService::check() ? AuthService::user()['id'] : null;
            
            $dynamicData = $dynamicService->getProductsDynamicData($productIds, $cityId, $userId);
            
            $result = [];
            foreach ($dynamicData as $productId => $data) {
                $dto = ProductAvailabilityDTO::fromDynamicData($productId, $data);
                $result[$productId] = $dto->toArray();
            }
            
            $this->success($result);
            
        } catch (\Exception $e) {
            Logger::error('API Availability error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            $this->error('Ошибка сервера', 500);
        }
    }
    
    /**
     * GET /api/search - Упрощенная версия
     */
    public function searchAction(): void
    {
        try {
            // Прямое получение параметров
            $params = [
                'q' => $_GET['q'] ?? '',
                'page' => max(1, (int)($_GET['page'] ?? 1)),
                'limit' => min(100, max(1, (int)($_GET['limit'] ?? 20))),
                'city_id' => (int)($_GET['city_id'] ?? 1),
                'sort' => $_GET['sort'] ?? 'relevance'
            ];
            
            // Валидация сортировки
            $allowedSorts = ['relevance', 'name', 'price_asc', 'price_desc', 'availability'];
            if (!in_array($params['sort'], $allowedSorts)) {
                $params['sort'] = 'relevance';
            }
            
            if (AuthService::check()) {
                $params['user_id'] = AuthService::user()['id'];
            }
            
            $result = SearchService::search($params);
            
            $this->success($result);
            
        } catch (\Exception $e) {
            Logger::error('API Search error', [
                'error' => $e->getMessage(),
                'params' => $_GET
            ]);
            $this->error('Ошибка поиска', 500);
        }
    }
    
    /**
     * GET /api/autocomplete - Минималистичная версия
     */
    public function autocompleteAction(): void
    {
        try {
            $query = trim($_GET['q'] ?? '');
            $limit = min(20, max(1, (int)($_GET['limit'] ?? 10)));
            
            if (strlen($query) < 2) {
                $this->success(['suggestions' => []]);
                return;
            }
            
            $suggestions = SearchService::autocomplete($query, $limit);
            
            $this->success(['suggestions' => $suggestions]);
            
        } catch (\Exception $e) {
            Logger::error('API Autocomplete error', [
                'error' => $e->getMessage(),
                'query' => $_GET['q'] ?? ''
            ]);
            $this->success(['suggestions' => []]); // Не ломаем UI
        }
    }
    
    /**
     * GET /api/test
     */
    public function testAction(): void
    {
        $this->success([
            'message' => 'API работает',
            'timestamp' => date('Y-m-d H:i:s'),
            'user_authenticated' => AuthService::check()
        ]);
    }
}