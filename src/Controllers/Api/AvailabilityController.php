<?php
namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Services\DynamicProductDataService;
use App\Services\AuthService;

class AvailabilityController extends BaseController
{
    private DynamicProductDataService $dynamicService;
    
    public function __construct()
    {
        $this->dynamicService = new DynamicProductDataService();
    }
    
    public function checkAction(): void
    {
        try {
            $validated = $this->validate($this->getInput(), [
                'city_id' => 'required|integer|min:1|max:10000',
                'product_ids' => 'required|string|max:10000'
            ]);
            
            $productIds = array_filter(
                array_map('intval', explode(',', $validated['product_ids'])),
                fn($id) => $id > 0
            );
            
            if (empty($productIds)) {
                $this->error('Нет валидных product_ids', 400);
            }
            
            if (count($productIds) > 1000) {
                $this->error('Слишком много товаров (макс. 1000)', 400);
            }
            
            $userId = AuthService::check() ? AuthService::user()['id'] : null;
            
            $data = $this->dynamicService->getProductsDynamicData(
                $productIds,
                $validated['city_id'],
                $userId
            );
            
            // Преобразуем в старый формат для совместимости
            $result = [];
            foreach ($data as $productId => $info) {
                $result[$productId] = [
                    'quantity' => $info['stock']['quantity'] ?? 0,
                    'in_stock' => $info['available'] ?? false,
                    'delivery_date' => $info['delivery']['date'] ?? null,
                    'availability_text' => $info['available'] 
                        ? ($info['stock']['quantity'] > 10 ? 'В наличии' : "Осталось {$info['stock']['quantity']} шт.")
                        : 'Под заказ'
                ];
            }
            
            $this->success($result);
            
        } catch (\Exception $e) {
            Logger::error('Availability check failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            $this->error('Ошибка проверки наличия', 500);
        }
    }
}