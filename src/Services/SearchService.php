<?php
namespace App\Services;

use App\Core\Database;
use App\Core\Logger;
use App\Core\Cache;
use OpenSearch\ClientBuilder;

class SearchService
{
    private static ?\OpenSearch\Client $client = null;
    
    public static function search(array $params): array
    {
        try {
            $params = self::validateParams($params);
            
            // Проверяем OpenSearch
            if (!self::isOpenSearchAvailable()) {
                return self::searchViaMySQL($params);
            }
            
            $body = [
                'size' => $params['limit'],
                'from' => ($params['page'] - 1) * $params['limit'],
                'track_total_hits' => true
            ];
            
            // Поисковый запрос
            if (!empty($params['q'])) {
                $query = mb_strtolower(trim($params['q']));
                
                // Для коротких запросов используем prefix
                if (mb_strlen($query) < 3) {
                    $body['query'] = [
                        'bool' => [
                            'should' => [
                                ['prefix' => ['external_id' => ['value' => $query, 'boost' => 10]]],
                                ['prefix' => ['name.autocomplete' => ['value' => $query, 'boost' => 5]]],
                                ['prefix' => ['sku' => ['value' => $query, 'boost' => 5]]]
                            ]
                        ]
                    ];
                } else {
                    // Для длинных - multi_match
                    $body['query'] = [
                        'bool' => [
                            'should' => [
                                ['match' => ['external_id' => ['query' => $query, 'boost' => 10]]],
                                ['match' => ['name' => ['query' => $query, 'boost' => 5]]],
                                ['match_phrase_prefix' => ['name' => ['query' => $query, 'boost' => 3]]],
                                ['match' => ['description' => ['query' => $query, 'boost' => 1]]]
                            ]
                        ]
                    ];
                }
                
                $body['highlight'] = [
                    'fields' => [
                        'name' => ['number_of_fragments' => 0],
                        'description' => ['fragment_size' => 150]
                    ]
                ];
            } else {
                $body['query'] = ['match_all' => new \stdClass()];
            }
            
            // Сортировка
            $body['sort'] = self::buildSort($params['sort'], !empty($params['q']));
            
            $response = self::getClient()->search([
                'index' => 'products_current',
                'body' => $body
            ]);
            
            return self::processResponse($response, $params);
            
        } catch (\Exception $e) {
            Logger::error('Search error', ['error' => $e->getMessage()]);
            return self::searchViaMySQL($params);
        }
    }
    
    public static function autocomplete(string $query, int $limit = 10): array
    {
        if (mb_strlen($query) < 1) return [];
        
        try {
            if (!self::isOpenSearchAvailable()) {
                return self::autocompleteMysql($query, $limit);
            }
            
            $query = mb_strtolower(trim($query));
            
            $body = [
                'suggest' => [
                    'product-suggest' => [
                        'prefix' => $query,
                        'completion' => [
                            'field' => 'suggest',
                            'size' => $limit,
                            'skip_duplicates' => true
                        ]
                    ]
                ]
            ];
            
            // Дополнительный поиск по префиксу
            $body['size'] = $limit;
            $body['_source'] = ['name', 'external_id', 'brand_name'];
            $body['query'] = [
                'bool' => [
                    'should' => [
                        ['prefix' => ['external_id' => ['value' => $query, 'boost' => 10]]],
                        ['prefix' => ['name.autocomplete' => ['value' => $query, 'boost' => 5]]],
                        ['match_phrase_prefix' => ['name' => ['query' => $query, 'boost' => 3]]]
                    ]
                ]
            ];
            
            $response = self::getClient()->search([
                'index' => 'products_current', 
                'body' => $body
            ]);
            
            $suggestions = [];
            
            // Из suggest API
            if (isset($response['suggest']['product-suggest'][0]['options'])) {
                foreach ($response['suggest']['product-suggest'][0]['options'] as $option) {
                    $suggestions[] = [
                        'text' => $option['text'],
                        'type' => 'product',
                        'score' => $option['_score']
                    ];
                }
            }
            
            // Из обычного поиска
            if (isset($response['hits']['hits'])) {
                foreach ($response['hits']['hits'] as $hit) {
                    $source = $hit['_source'];
                    $suggestions[] = [
                        'text' => $source['name'],
                        'type' => 'product',
                        'score' => $hit['_score'],
                        'external_id' => $source['external_id']
                    ];
                }
            }
            
            // Убираем дубликаты
            $unique = [];
            foreach ($suggestions as $s) {
                $key = mb_strtolower($s['text']);
                if (!isset($unique[$key])) {
                    $unique[$key] = $s;
                }
            }
            
            return array_values($unique);
            
        } catch (\Exception $e) {
            return self::autocompleteMysql($query, $limit);
        }
    }
    
    private static function buildSort(string $sort, bool $hasQuery): array
    {
        switch ($sort) {
            case 'name':
                return [['name.keyword' => 'asc']];
            case 'external_id':
                return [['external_id.keyword' => 'asc']];
            case 'price_asc':
                return [['product_id' => 'asc']]; // TODO: добавить цены в индекс
            case 'price_desc':
                return [['product_id' => 'desc']];
            case 'relevance':
            default:
                return $hasQuery ? [['_score' => 'desc']] : [['name.keyword' => 'asc']];
        }
    }
    
    private static function processResponse(array $response, array $params): array
    {
        $products = [];
        
        foreach ($response['hits']['hits'] ?? [] as $hit) {
            $product = $hit['_source'];
            $product['_score'] = $hit['_score'] ?? 0;
            
            if (isset($hit['highlight'])) {
                $product['_highlight'] = $hit['highlight'];
            }
            
            $products[] = $product;
        }
        
        // Обогащаем динамическими данными
        if (!empty($products)) {
            $productIds = array_column($products, 'product_id');
            $cityId = $params['city_id'] ?? 1;
            $userId = $params['user_id'] ?? null;
            
            $dynamicService = new DynamicProductDataService();
            $dynamicData = $dynamicService->getProductsDynamicData($productIds, $cityId, $userId);
            
            foreach ($products as &$product) {
                $pid = $product['product_id'];
                if (isset($dynamicData[$pid])) {
                    $product = array_merge($product, $dynamicData[$pid]);
                }
            }
        }
        
        return [
            'products' => $products,
            'total' => $response['hits']['total']['value'] ?? 0,
            'page' => $params['page'],
            'limit' => $params['limit']
        ];
    }
    
    private static function searchViaMySQL(array $params): array
    {
        $query = $params['q'] ?? '';
        $page = $params['page'];
        $limit = $params['limit'];
        $offset = ($page - 1) * $limit;
        
        $pdo = Database::getConnection();
        
        $sql = "SELECT SQL_CALC_FOUND_ROWS 
                p.*, b.name as brand_name, s.name as series_name
                FROM products p
                LEFT JOIN brands b ON p.brand_id = b.brand_id
                LEFT JOIN series s ON p.series_id = s.series_id
                WHERE 1=1";
        
        $bindParams = [];
        
        if (!empty($query)) {
            $sql .= " AND (p.name LIKE :q OR p.external_id LIKE :q2 OR p.sku LIKE :q3)";
            $bindParams['q'] = "%$query%";
            $bindParams['q2'] = "$query%";
            $bindParams['q3'] = "$query%";
        }
        
        $sql .= " ORDER BY p.name ASC LIMIT :limit OFFSET :offset";
        
        $stmt = $pdo->prepare($sql);
        foreach ($bindParams as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();
        
        $products = $stmt->fetchAll();
        $total = $pdo->query("SELECT FOUND_ROWS()")->fetchColumn();
        
        return [
            'products' => $products,
            'total' => (int)$total,
            'page' => $page,
            'limit' => $limit
        ];
    }
    
    private static function autocompleteMysql(string $query, int $limit): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("
            SELECT name, external_id
            FROM products
            WHERE name LIKE :q OR external_id LIKE :q2
            LIMIT :limit
        ");
        
        $stmt->bindValue(':q', "$query%");
        $stmt->bindValue(':q2', "$query%");
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();
        
        $suggestions = [];
        while ($row = $stmt->fetch()) {
            $suggestions[] = [
                'text' => $row['name'],
                'type' => 'product',
                'external_id' => $row['external_id']
            ];
        }
        
        return $suggestions;
    }
    
    private static function validateParams(array $params): array
    {
        return [
            'q' => trim($params['q'] ?? ''),
            'page' => max(1, (int)($params['page'] ?? 1)),
            'limit' => min(100, max(1, (int)($params['limit'] ?? 20))),
            'city_id' => (int)($params['city_id'] ?? 1),
            'sort' => $params['sort'] ?? 'relevance',
            'user_id' => $params['user_id'] ?? null
        ];
    }
    
    private static function isOpenSearchAvailable(): bool
    {
        try {
            return self::getClient()->ping();
        } catch (\Exception $e) {
            return false;
        }
    }
    
    private static function getClient(): \OpenSearch\Client
    {
        if (self::$client === null) {
            self::$client = ClientBuilder::create()
                ->setHosts(['localhost:9200'])
                ->build();
        }
        return self::$client;
    }
}