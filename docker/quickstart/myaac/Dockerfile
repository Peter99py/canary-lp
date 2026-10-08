FROM php:8.2-fpm-alpine
WORKDIR /var/www/html
COPY quickstart/myaac/ .
RUN rm -rf .git \
    && apk add --no-cache nginx curl git unzip $PHPIZE_DEPS \
    && docker-php-ext-install pdo_mysql \
    && curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer \
    && composer install --no-dev --optimize-autoloader \
    && cp nginx-sample.conf /etc/nginx/http.d/default.conf \
    && echo 'cgi.fix_pathinfo=0' > /usr/local/etc/php/conf.d/zz-custom.ini \
    && chmod +x entrypoint.sh
EXPOSE 80
ENTRYPOINT ["/var/www/html/entrypoint.sh"]
CMD ["sh", "-c", "nginx && php-fpm"]
