Конфиграция сайта 
# -------------------  HTTP (80) блок: только редирект на HTTPS  -------------------
server {
    listen 79.133.183.86:80 default_server;
    server_name vdestor.ru www.vdestor.ru;
    root /var/www/www-root/data/site/vdestor.ru/public;

    # Перенаправление на HTTPS
    return 301 https://$host$request_uri;
}

# -------------------  HTTPS (443) основной блок  -------------------
server {
    listen 79.133.183.86:443 ssl http2 default_server;
    server_name vdestor.ru www.vdestor.ru;
    root /var/www/www-root/data/site/vdestor.ru/public;
    index index.php index.html;

    # SSL сертификаты
    ssl_certificate "/var/www/httpd-cert/www-root/vdestor.ru_le2.crtca";
    ssl_certificate_key "/var/www/httpd-cert/www-root/vdestor.ru_le2.key";
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_prefer_server_ciphers on;
    ssl_dhparam /etc/ssl/certs/dhparam4096.pem;
    ssl_ciphers EECDH:+AES256:-3DES:RSA+AES:!NULL:!RC4;

    # Безопасные заголовки (для всего сайта)
    add_header X-Content-Type-Options nosniff always;
    add_header X-Frame-Options DENY always;
    add_header X-XSS-Protection "1; mode=block" always;
    add_header Referrer-Policy strict-origin-when-cross-origin always;
    add_header Content-Security-Policy "default-src 'self' https://vdestor.ru; script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdnjs.cloudflare.com https://cdn.jsdelivr.net https://vdestor.ru; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdnjs.cloudflare.com https://vdestor.ru; font-src 'self' https://fonts.gstatic.com https://cdnjs.cloudflare.com data:; img-src 'self' data: https: blob:; connect-src 'self' https://localhost:9200 https://vdestor.ru; object-src 'none';" always;

    # Логи
    access_log /var/www/httpd-logs/vdestor.ru.access.log;
    error_log  /var/www/httpd-logs/vdestor.ru.error.log notice;

    # SSI, индексы, симлинки
    ssi on;
    disable_symlinks if_not_owner from=/var/www/www-root/data/site/vdestor.ru/public;
    charset off;

    # Включение Gzip
    gzip on;
    gzip_comp_level 5;
    gzip_disable "msie6";
    gzip_types text/plain text/css application/json application/x-javascript text/xml application/xml application/xml+rss text/javascript application/javascript image/svg+xml;

    # Подключение инклудов (если используются)
    include /etc/nginx/vhosts-includes/*.conf;
    include /etc/nginx/vhosts-resources/vdestor.ru/*.conf;

    # Редирект www на не-www
    if ($host = www.vdestor.ru) {
        return 301 https://vdestor.ru$request_uri;
    }

    # ----------------- Защита доступа к скрытым и важным файлам -----------------
    location ~ /\.env { deny all; return 404; }
    location ~ /\.(git|svn|ht|DS_Store) { deny all; }
    location ~ ^/config/(generate_hash\.php|config_bd\.ini)$ { deny all; return 404; }

    # ----------------- Основной роутинг -----------------
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    # Защита от прямого запуска .php и .phtml
    location ~ [^/]\.ph(p\d*|tml)$ {
        try_files /does_not_exists @php;
    }

    # Кеширование статики
    location ~* ^.+\.(jpg|jpeg|gif|png|svg|js|css|mp3|ogg|mpe?g|avi|zip|gz|bz2?|rar|swf|webp|woff|woff2)$ {
        expires 7d;
    }
    location /api/ {
    # ВАЖНО: Направляем все API запросы в index.php, а не ищем физические файлы
    try_files $uri $uri/ /index.php?$query_string;
    
    # Настройки для API
    limit_req zone=api burst=10 nodelay;
    
    # Доп. безопасные заголовки для API
    add_header X-Content-Type-Options nosniff always;
    add_header X-Frame-Options DENY always;
    add_header X-XSS-Protection "1; mode=block" always;
    
    # Обработка PHP для API
    location ~ \.php$ {
            include /etc/nginx/vhosts-resources/vdestor.ru/dynamic/*.conf;
            fastcgi_index index.php;
            fastcgi_param PHP_ADMIN_VALUE "sendmail_path = /usr/sbin/sendmail -t -i -f vde76ru@yandex.ru";
            fastcgi_param HTTPS on;
            fastcgi_param HTTP_X_FORWARDED_PROTO $scheme;
            fastcgi_pass unix:/run/php/php8.1-fpm.sock;
            fastcgi_split_path_info ^((?U).+\.ph(?:p\d*|tml))(/?.+)$;
            try_files $uri =404;
            include fastcgi_params;
        }
    }

    # ----------------- PHP-обработчик -----------------
    location @php {
        include /etc/nginx/vhosts-resources/vdestor.ru/dynamic/*.conf;
        fastcgi_index index.php;
        fastcgi_param PHP_ADMIN_VALUE "sendmail_path = /usr/sbin/sendmail -t -i -f vde76ru@yandex.ru";
        fastcgi_param  HTTPS               on;
        fastcgi_param  HTTP_X_FORWARDED_PROTO  $scheme;
        fastcgi_pass unix:/run/php/php8.1-fpm.sock;
        fastcgi_split_path_info ^((?U).+\.ph(?:p\d*|tml))(/?.+)$;
        try_files $uri =404;
        include fastcgi_params;
    }
}
