</div> <!-- .page-container -->
            </main> <!-- .main-content -->
        </div> <!-- .main-wrapper -->
    </div> <!-- .app-layout -->
    
    <!-- Toast контейнер для уведомлений -->
    <div class="toast-container" id="toastContainer"></div>
    
    <!-- Основной JavaScript -->
    <script>
        // CSRF токен для всех AJAX запросов
        window.CSRF_TOKEN = <?= json_encode(\App\Core\CSRF::token(), JSON_HEX_TAG) ?>;
        
        // Функция показа уведомлений
        function showToast(message, type = 'info', duration = 3000) {
            const toastContainer = document.getElementById('toastContainer');
            
            const toast = document.createElement('div');
            toast.className = `toast toast-${type} show`;
            
            const icons = {
                success: '<svg width="20" height="20" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path></svg>',
                error: '<svg width="20" height="20" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"></path></svg>',
                warning: '<svg width="20" height="20" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"></path></svg>',
                info: '<svg width="20" height="20" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"></path></svg>'
            };
            
            toast.innerHTML = `
                <div class="toast-icon">${icons[type] || icons.info}</div>
                <div class="toast-content">
                    <div class="toast-message">${message}</div>
                </div>
                <button class="toast-close" onclick="this.parentElement.remove()">
                    <svg width="16" height="16" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"></path>
                    </svg>
                </button>
            `;
            
            toastContainer.appendChild(toast);
            
            setTimeout(() => {
                toast.remove();
            }, duration);
        }
        
        // Глобальный поиск
        const globalSearch = document.getElementById('globalSearch');
        if (globalSearch) {
            let searchTimeout;
            
            globalSearch.addEventListener('input', function(e) {
                clearTimeout(searchTimeout);
                const query = e.target.value.trim();
                
                if (query.length > 2) {
                    searchTimeout = setTimeout(() => {
                        // Здесь должен быть AJAX поиск
                        console.log('Поиск:', query);
                    }, 500);
                }
            });
        }
        
        // Обработка выбора города
        const citySelect = document.getElementById('citySelect');
        if (citySelect) {
            citySelect.addEventListener('change', function(e) {
                const cityId = e.target.value;
                const cityName = e.target.options[e.target.selectedIndex].text;
                
                // Сохраняем в localStorage
                localStorage.setItem('selected_city_id', cityId);
                localStorage.setItem('selected_city_name', cityName);
                
                // Обновляем страницу или делаем AJAX запрос
                showToast(`Город изменен на ${cityName}`, 'success');
                
                // Обновляем данные о наличии товаров
                if (typeof window.updateCityAvailability === 'function') {
                    window.updateCityAvailability(cityId);
                }
            });
        }
        
        // Восстановление выбранного города
        document.addEventListener('DOMContentLoaded', function() {
            const savedCityId = localStorage.getItem('selected_city_id');
            if (savedCityId && citySelect) {
                citySelect.value = savedCityId;
            }
        });
        
        // Функция для добавления товара в корзину (глобальная)
        window.addToCart = function(productId, quantity = 1) {
            fetch('/cart/add', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    productId: productId,
                    quantity: quantity,
                    csrf_token: window.CSRF_TOKEN
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast('Товар добавлен в корзину', 'success');
                    updateCartBadge();
                } else {
                    showToast(data.message || 'Ошибка добавления', 'error');
                }
            })
            .catch(error => {
                showToast('Ошибка сервера', 'error');
            });
        };
        
        // Функция обновления бейджа корзины
        function updateCartBadge() {
            fetch('/cart/json')
                .then(res => res.json())
                .then(data => {
                    const cartBadge = document.getElementById('cartBadge');
                    if (cartBadge) {
                        const cart = data.cart || {};
                        const totalItems = Object.values(cart).reduce((sum, item) => sum + (item.quantity || 0), 0);
                        
                        if (totalItems > 0) {
                            cartBadge.textContent = totalItems;
                            cartBadge.style.display = 'block';
                        } else {
                            cartBadge.style.display = 'none';
                        }
                    }
                })
                .catch(() => {
                    // Игнорируем ошибки
                });
        }
        
        // Функция загрузки наличия товаров
        window.loadAvailability = function(productIds) {
            const cityId = document.getElementById('citySelect')?.value || '1';
            
            fetch('/api/availability', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    product_ids: productIds,
                    city_id: cityId
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success && data.data) {
                    Object.entries(data.data).forEach(([productId, info]) => {
                        updateProductAvailability(productId, info);
                    });
                }
            })
            .catch(error => {
                console.error('Ошибка загрузки наличия:', error);
            });
        };
        
        // Обновление информации о наличии товара
        function updateProductAvailability(productId, data) {
            const row = document.querySelector(`tr[data-product-id="${productId}"]`);
            if (!row) return;
            
            const availCell = row.querySelector('.availability-cell');
            const deliveryCell = row.querySelector('.delivery-date-cell');
            
            if (availCell) {
                const qty = data.quantity || 0;
                availCell.textContent = qty > 0 ? `${qty} шт` : 'Нет в наличии';
                availCell.className = 'availability-cell ' + (qty > 10 ? 'text-success' : qty > 0 ? 'text-warning' : 'text-danger');
            }
            
            if (deliveryCell && data.delivery_date) {
                deliveryCell.textContent = new Date(data.delivery_date).toLocaleDateString('ru-RU');
            }
        }
        
        // Анимация при загрузке страницы
        document.addEventListener('DOMContentLoaded', function() {
            // Обновляем счетчик корзины
            updateCartBadge();
            
            // Анимация карточек
            const animateElements = document.querySelectorAll('.stat-card, .card, .table-wrapper');
            animateElements.forEach((el, index) => {
                el.style.opacity = '0';
                el.style.transform = 'translateY(20px)';
                
                setTimeout(() => {
                    el.style.transition = 'all 0.5s ease-out';
                    el.style.opacity = '1';
                    el.style.transform = 'translateY(0)';
                }, index * 100);
            });
        });
    </script>
    
    <!-- Подключаем скомпилированные Vite файлы -->
    <?php
    // Динамическое подключение файлов после сборки
    $distPath = $_SERVER['DOCUMENT_ROOT'] . '/assets/dist/assets/';
    
    if (is_dir($distPath)) {
        // Ищем CSS файлы
        $cssFiles = glob($distPath . 'main-*.css');
        foreach ($cssFiles as $cssFile) {
            $cssUrl = str_replace($_SERVER['DOCUMENT_ROOT'], '', $cssFile);
            echo '<link rel="stylesheet" href="' . htmlspecialchars($cssUrl) . '">' . PHP_EOL;
        }
        
        // Ищем JS файлы
        $jsFiles = glob($distPath . 'main-*.js');
        foreach ($jsFiles as $jsFile) {
            $jsUrl = str_replace($_SERVER['DOCUMENT_ROOT'], '', $jsFile);
            echo '<script type="module" src="' . htmlspecialchars($jsUrl) . '"></script>' . PHP_EOL;
        }
    } else {
        // Fallback если файлы еще не собраны
        echo '<!-- Vite assets not found. Run "npm run build" to generate them. -->' . PHP_EOL;
    }
    ?>
</body>
</html>