# Estágio de assets: gera tools/ext/ (jquery, jquery-ui, bootstrap, tinymce).
# No MyAAC upstream essa pasta é ignorada no git e criada pelo postinstall do
# npm a partir de node_modules; sem ela o jQuery e o AdminLTE não carregam.
FROM node:20-alpine AS assets
WORKDIR /src
COPY quickstart/myaac/package.json quickstart/myaac/package-lock.json quickstart/myaac/npm-post-install.js ./
RUN npm ci --omit=dev

FROM php:8.2-fpm-alpine
WORKDIR /var/www/html
COPY quickstart/myaac/ .
COPY --from=assets /src/tools/ext ./tools/ext
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
