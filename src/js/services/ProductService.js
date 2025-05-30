/**
 * Централизованный сервис для работы с товарами
 * Объединяет поиск, автодополнение и динамические данные
 */
export class ProductService {
    constructor() {
        this.baseUrl = '/api';
        this.cache = new Map();
        this.cacheTimeout = 5 * 60 * 1000; // 5 минут
        this.requestTimeout = 10000; // 10 секунд
    }
    
    /**
     * Универсальный поиск товаров
     */
    async search(params = {}) {
        const endpoint = `${this.baseUrl}/search`;
        const allowedSorts = ['relevance', 'name', 'price_asc', 'price_desc', 'availability'];
        if (params.sort && !allowedSorts.includes(params.sort)) {
            delete params.sort; // Удаляем невалидную сортировку
        }
    
        const cacheKey = this.getCacheKey('search', params);
        
        // Проверка кеша
        const cached = this.getFromCache(cacheKey);
        if (cached) return cached;
        
        try {
            const response = await this.request(endpoint, params);
            
            if (response.success) {
                const result = {
                    success: true,
                    data: {
                        products: response.data?.products || [],
                        total: response.data?.total || 0,
                        page: params.page || 1,
                        limit: params.limit || 20,
                        aggregations: response.data?.aggregations || {}
                    }
                };
                
                this.saveToCache(cacheKey, result);
                return result;
            }
            
            return this.errorResponse('Search failed');
            
        } catch (error) {
            console.error('Search error:', error);
            return this.errorResponse(error.message);
        }
    }
    
    /**
     * Получить товары по ID
     */
    async getProductsByIds(ids, cityId = null) {
        if (!ids.length) return { success: true, data: [] };
        
        const endpoint = `${this.baseUrl}/products/batch`;
        
        try {
            const response = await this.request(endpoint, {
                ids: ids.join(','),
                city_id: cityId || this.getCurrentCityId()
            });
            
            return {
                success: true,
                data: response.data || []
            };
            
        } catch (error) {
            return this.errorResponse(error.message);
        }
    }
    
    /**
     * Получить один товар
     */
    async getProduct(id, cityId = null) {
        const endpoint = `${this.baseUrl}/products/${id}`;
        const cacheKey = this.getCacheKey('product', { id, cityId });
        
        const cached = this.getFromCache(cacheKey);
        if (cached) return cached;
        
        try {
            const response = await this.request(endpoint, {
                city_id: cityId || this.getCurrentCityId()
            });
            
            if (response.success) {
                this.saveToCache(cacheKey, response);
                return response;
            }
            
            return this.errorResponse('Product not found');
            
        } catch (error) {
            return this.errorResponse(error.message);
        }
    }
    
    /**
     * Автодополнение
     */
    async autocomplete(query, limit = 10) {
        if (!query || query.length < 2) {
            return { success: true, suggestions: [] };
        }
        
        const endpoint = `${this.baseUrl}/autocomplete`;
        
        try {
            const response = await this.request(endpoint, { q: query, limit }, 3000);
            
            return {
                success: true,
                suggestions: response.data?.suggestions || []
            };
            
        } catch (error) {
            return { success: false, suggestions: [] };
        }
    }
    
    /**
     * Универсальный метод запроса
     */
    async request(url, params = {}, timeout = null) {
        const controller = new AbortController();
        const timeoutId = setTimeout(
            () => controller.abort(),
            timeout || this.requestTimeout
        );
        
        try {
            // Очистка пустых параметров
            const cleanParams = Object.entries(params)
                .filter(([_, v]) => v !== null && v !== undefined && v !== '')
                .reduce((acc, [k, v]) => ({ ...acc, [k]: v }), {});
            
            const queryString = new URLSearchParams(cleanParams).toString();
            const fullUrl = queryString ? `${url}?${queryString}` : url;
            
            const response = await fetch(fullUrl, {
                method: 'GET',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                credentials: 'same-origin',
                signal: controller.signal
            });
            
            clearTimeout(timeoutId);
            
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }
            
            return await response.json();
            
        } catch (error) {
            clearTimeout(timeoutId);
            
            if (error.name === 'AbortError') {
                throw new Error('Request timeout');
            }
            
            throw error;
        }
    }
    
    /**
     * Работа с кешем
     */
    getCacheKey(type, params) {
        return `${type}:${JSON.stringify(params)}`;
    }
    
    getFromCache(key) {
        const cached = this.cache.get(key);
        if (!cached) return null;
        
        if (Date.now() - cached.timestamp > this.cacheTimeout) {
            this.cache.delete(key);
            return null;
        }
        
        return cached.data;
    }
    
    saveToCache(key, data) {
        // Ограничение размера кеша
        if (this.cache.size > 100) {
            const firstKey = this.cache.keys().next().value;
            this.cache.delete(firstKey);
        }
        
        this.cache.set(key, {
            timestamp: Date.now(),
            data: data
        });
    }
    
    clearCache() {
        this.cache.clear();
    }
    
    /**
     * Утилиты
     */
    getCurrentCityId() {
        return document.getElementById('citySelect')?.value || '1';
    }
    
    errorResponse(message) {
        return {
            success: false,
            error: message,
            data: {
                products: [],
                total: 0
            }
        };
    }
}

// Экспорт синглтона
export const productService = new ProductService();