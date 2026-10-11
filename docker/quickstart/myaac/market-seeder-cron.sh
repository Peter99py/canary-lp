#!/usr/bin/env sh
# market-seeder: executa a rotina diaria do plugin como www-data.
#
# Chamado pelo cron do container (crontab do root, as 03:00). O crond do busybox
# so executa o crontab do root, entao usamos `su` para rodar o PHP como www-data
# (assim os arquivos de cache/log ficam com o mesmo dono do php-fpm).
#
# Log: /var/www/html/system/logs/market-seeder.log
exec su -s /bin/sh www-data -c '/usr/local/bin/php /var/www/html/aac market-seeder:run' >> /var/www/html/system/logs/market-seeder.log 2>&1
