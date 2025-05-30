<?php
// src/Services/SearchService.php
namespace App\Services;

use App\Core\SearchConfig;
use App\Core\Logger;
use App\Core\Cache;
use OpenSearch\ClientBuilder;

/**
 * Исправленный сервис для поиска товаров
 * Теперь с поддержкой всех типов сортировки!
 */
class SearchService
{
    private static ?\OpenSearch\Client $client = null;
    
    /**
     * Поиск товаров с упрощенной логикой
     */
    public static function search(array $params): array
    {
        try {
            // Валидируем и очищаем параметры простым способом
            $params = self::validateSearchParams($params);
            
            // Проверяем кеш (упрощенно)
            $cacheKey = self::getCacheKey($params);
            $cached = self::getFromCache($cacheKey);
            if ($cached !== null) {
                return $cached;
            }
            
            // Строим запрос к OpenSearch
            $searchBody = self::buildSearchQuery($params);
            
            // Выполняем поиск
            $response = self::getClient()->search([
                'index' => 'products_current',
                'body' => $searchBody
            ]);
            
            // Обрабатываем результаты
            $result = self::processSearchResults($response, $params);
            
            // Обогащаем динамическими данными (упрощенно)
            if (!empty($result['products'])) {
                $result['products'] = self::enrichWithBasicData($result['products']);
            }
            
            // Сохраняем в кеш
            self::saveToCache($cacheKey, $result);
            
            return $result;
            
        } catch (\Exception $e) {
            // Логируем ошибку
            if (class_exists('App\\Core\\Logger')) {
                Logger::error('Search failed', [
                    'params' => $params,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            } else {
                error_log('Search failed: ' . $e->getMessage());
            }
            
            // Возвращаем пустой результат вместо ошибки
            return [
                'products' => [],
                'total' => 0,
                'page' => $params['page'] ?? 1,
                'limit' => $params['limit'] ?? 20,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Автодополнение для поиска (упрощенная версия)
     */
    public static function autocomplete(string $query, int $limit = 10): array
    {
        if (strlen($query) < 2) {
            return [];
        }
        
        try {
            // Простой поиск по названию для автодополнения
            $response = self::getClient()->search([
                'index' => 'products_current',
                'body' => [
                    'size' => $limit,
                    'query' => [
                        'bool' => [
                            'should' => [
                                ['match_phrase_prefix' => ['name' => ['query' => $query, 'boost' => 3]]],
                                ['match_phrase_prefix' => ['external_id' => ['query' => $query, 'boost' => 2]]],
                                ['match_phrase_prefix' => ['brand_name' => ['query' => $query, 'boost' => 1]]]
                            ]
                        ]
                    ],
                    '_source' => ['name', 'external_id', 'brand_name']
                ]
            ]);
            
            $suggestions = [];
            
            if (isset($response['hits']['hits'])) {
                foreach ($response['hits']['hits'] as $hit) {
                    $source = $hit['_source'];
                    $suggestions[] = [
                        'text' => $source['name'] ?? '',
                        'type' => 'product',
                        'score' => $hit['_score'] ?? 0
                    ];
                }
            }
            
            return $suggestions;
            
        } catch (\Exception $e) {
            if (class_exists('App\\Core\\Logger')) {
                Logger::error('Autocomplete failed', ['error' => $e->getMessage()]);
            }
            return [];
        }
    }
    
    /**
     * Получить один товар по ID
     */
    public static function getProduct($id, int $cityId = 1, ?int $userId = null): ?array
    {
        try {
            $response = self::getClient()->search([
                'index' => 'products_current',
                'body' => [
                    'size' => 1,
                    'query' => [
                        'bool' => [
                            'should' => [
                                ['term' => ['product_id' => $id]],
                                ['term' => ['external_id.keyword' => $id]]
                            ],
                            'minimum_should_match' => 1
                        ]
                    ]
                ]
            ]);
            
            if (empty($response['hits']['hits'])) {
                return null;
            }
            
            $product = $response['hits']['hits'][0]['_source'];
            
            // Базовое обогащение данных
            $products = self::enrichWithBasicData([$product]);
            
            return $products[0] ?? null;
            
        } catch (\Exception $e) {
            if (class_exists('App\\Core\\Logger')) {
                Logger::error('Get product failed', [
                    'id' => $id,
                    'error' => $e->getMessage()
                ]);
            }
            return null;
        }
    }
    
    // === Приватные методы ===
    
    /**
     * Простая валидация параметров
     */
    private static function validateSearchParams(array $params): array
    {
        $defaults = [
            'q' => '',
            'page' => 1,
            'limit' => 20,
            'sort' => 'relevance',
            'city_id' => 1
        ];
        
        $params = array_merge($defaults, $params);
        
        // Простая очистка
        $params['page'] = max(1, min(1000, (int)$params['page']));
        $params['limit'] = max(1, min(100, (int)$params['limit']));
        $params['city_id'] = max(1, (int)$params['city_id']);
        $params['q'] = trim(strip_tags($params['q'] ?? ''));
        
        // ВАЖНО: Проверяем валидность типа сортировки
        $validSorts = ['relevance', 'name', 'price_asc', 'price_desc', 'popularity', 'availability', 'external_id', 'sku', 'brand_series', 'status', 'delivery_date', 'orders_count'];
        if (!in_array($params['sort'], $validSorts)) {
            $params['sort'] = 'relevance';
        }
        
        return $params;
    }
    
    /**
     * Построение поискового запроса
     */
    private static function buildSearchQuery(array $params): array
    {
        $from = ($params['page'] - 1) * $params['limit'];
        
        $body = [
            'size' => $params['limit'],
            'from' => $from,
            'track_total_hits' => true
        ];
        
        // Основной запрос
        if (!empty($params['q'])) {
            $body['query'] = [
                'multi_match' => [
                    'query' => $params['q'],
                    'fields' => [
                        'name^10',
                        'external_id^7',
                        'sku^6',
                        'brand_name^5',
                        'description^2'
                    ],
                    'type' => 'best_fields',
                    'operator' => 'or',
                    'fuzziness' => 'AUTO'
                ]
            ];
            
            // Подсветка результатов
            $body['highlight'] = [
                'pre_tags' => ['<mark>'],
                'post_tags' => ['</mark>'],
                'fields' => [
                    'name' => ['number_of_fragments' => 0],
                    'description' => ['fragment_size' => 150, 'number_of_fragments' => 2]
                ]
            ];
        } else {
            $body['query'] = ['match_all' => new \stdClass()];
        }
        
        // Сортировка
        $body['sort'] = self::buildSort($params['sort'], !empty($params['q']));
        
        return $body;
    }
    
    /**
     * Построение сортировки
     * ИСПРАВЛЕНО: Добавлена поддержка всех типов сортировки из frontend
     */
    private static function buildSort(string $sort, bool $hasQuery): array
    {
        switch ($sort) {
            case 'name':
                return [['name.keyword' => 'asc']];
                
            case 'external_id':
                return [['external_id.keyword' => 'asc']];
                
            case 'sku':
                return [['sku.keyword' => 'asc']];
                
            case 'price_asc':
            case 'price':
                // Временно используем product_id, пока нет цен в индексе
                return [['product_id' => 'asc']];
                
            case 'price_desc':
            case 'retail_price':
                // Временно используем product_id, пока нет цен в индексе
                return [['product_id' => 'desc']];
                
            case 'availability':
                // Сортировка по наличию - пока используем product_id
                // В будущем нужно добавить поле stock_quantity в индекс
                return [['product_id' => 'desc']];
                
            case 'delivery_date':
                // Сортировка по дате доставки - пока используем created_at
                return [['created_at' => 'asc']];
                
            case 'status':
                // Если есть поле status в индексе
                return [['product_id' => 'asc']];
                
            case 'orders_count':
            case 'popularity':
                // Сортировка по популярности - пока используем product_id
                return [['product_id' => 'desc']];
                
            case 'relevance':
            default:
                // Для релевантности используем счет поиска или имя
                return $hasQuery 
                    ? [['_score' => 'desc'], ['name.keyword' => 'asc']]
                    : [['name.keyword' => 'asc']];
        }
    }
    
    /**
     * Обработка результатов поиска
     */
    private static function processSearchResults(array $response, array $params): array
    {
        $products = [];
        
        if (isset($response['hits']['hits'])) {
            foreach ($response['hits']['hits'] as $hit) {
                $product = $hit['_source'];
                $product['_score'] = $hit['_score'] ?? 0;
                
                if (isset($hit['highlight'])) {
                    $product['_highlight'] = $hit['highlight'];
                }
                
                $products[] = $product;
            }
        }
        
        return [
            'products' => $products,
            'total' => $response['hits']['total']['value'] ?? 0,
            'page' => $params['page'],
            'limit' => $params['limit'],
            'pages' => ceil(($response['hits']['total']['value'] ?? 0) / $params['limit'])
        ];
    }
    
    /**
     * Базовое обогащение товаров (без сложных зависимостей)
     */
    private static function enrichWithBasicData(array $products): array
    {
        if (empty($products)) return $products;
        
        $productIds = array_column($products, 'product_id');
        $cityId = $_GET['city_id'] ?? 1;
        $userId = AuthService::check() ? AuthService::user()['id'] : null;
        
        $dynamicService = new DynamicProductDataService();
        $dynamicData = $dynamicService->getProductsDynamicData($productIds, $cityId, $userId);
        
        foreach ($products as &$product) {
            $pid = $product['product_id'];
            if (isset($dynamicData[$pid])) {
                $product = array_merge($product, $dynamicData[$pid]);
            }
        }
        
        return $products;
    }
    
    /**
     * Получить ключ кеша
     */
    private static function getCacheKey(array $params): string
    {
        return 'search:' . md5(json_encode($params));
    }
    
    /**
     * Простой кеш в памяти (без внешних зависимостей)
     */
    private static array $cache = [];
    
    private static function getFromCache(string $key)
    {
        return self::$cache[$key] ?? null;
    }
    
    private static function saveToCache(string $key, array $data): void
    {
        // Ограничиваем размер кеша
        if (count(self::$cache) > 100) {
            self::$cache = array_slice(self::$cache, -50, null, true);
        }
        
        self::$cache[$key] = $data;
    }
    
    /**
     * Получить клиент OpenSearch
     */
    private static function getClient(): \OpenSearch\Client
    {
        if (self::$client === null) {
            self::$client = ClientBuilder::create()
                ->setHosts(['localhost:9200'])
                ->setRetries(2)
                ->build();
        }
        
        return self::$client;
    }
}