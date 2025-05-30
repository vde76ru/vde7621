<?php
namespace App\DTO;

/**
 * Единый формат данных о наличии товара
 * Используется во всей системе для согласованности
 */
class ProductAvailabilityDTO
{
    public int $productId;
    public int $quantity;
    public bool $inStock;
    public ?string $deliveryDate;
    public string $deliveryText;
    public ?float $price;
    public ?float $basePrice;
    public bool $hasSpecialPrice;
    public array $warehouses;
    
    public function __construct(array $data = [])
    {
        $this->productId = $data['product_id'] ?? 0;
        $this->quantity = $data['quantity'] ?? 0;
        $this->inStock = $this->quantity > 0;
        $this->deliveryDate = $data['delivery_date'] ?? null;
        $this->deliveryText = $data['delivery_text'] ?? ($this->inStock ? 'В наличии' : 'Под заказ');
        $this->price = $data['price'] ?? null;
        $this->basePrice = $data['base_price'] ?? null;
        $this->hasSpecialPrice = $data['has_special_price'] ?? false;
        $this->warehouses = $data['warehouses'] ?? [];
    }
    
    /**
     * Преобразовать в массив для API
     */
    public function toArray(): array
    {
        return [
            'product_id' => $this->productId,
            'quantity' => $this->quantity,
            'in_stock' => $this->inStock,
            'delivery_date' => $this->deliveryDate,
            'delivery_text' => $this->deliveryText,
            'availability_text' => $this->getAvailabilityText(),
            'price' => $this->price,
            'base_price' => $this->basePrice,
            'has_special_price' => $this->hasSpecialPrice,
            'warehouses' => $this->warehouses
        ];
    }
    
    /**
     * Получить текст наличия для отображения
     */
    public function getAvailabilityText(): string
    {
        if ($this->quantity > 10) {
            return 'В наличии';
        } elseif ($this->quantity > 0) {
            return "Осталось {$this->quantity} шт.";
        } else {
            return 'Под заказ';
        }
    }
    
    /**
     * Создать из данных DynamicProductDataService
     */
    public static function fromDynamicData(int $productId, array $dynamicData): self
    {
        return new self([
            'product_id' => $productId,
            'quantity' => $dynamicData['stock']['quantity'] ?? 0,
            'delivery_date' => $dynamicData['delivery']['date'] ?? null,
            'delivery_text' => $dynamicData['delivery']['text'] ?? 'Уточняйте',
            'price' => $dynamicData['price']['final'] ?? null,
            'base_price' => $dynamicData['price']['base'] ?? null,
            'has_special_price' => $dynamicData['price']['has_special'] ?? false,
            'warehouses' => $dynamicData['stock']['warehouses'] ?? []
        ]);
    }
}