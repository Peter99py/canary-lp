#!/usr/bin/env bash
set -eo pipefail

echo "=========================================="
echo "    Canary Database R2 Backup Service     "
echo "=========================================="

if [ -n "${BACKUP_TZ}" ]; then
    cp "/usr/share/zoneinfo/${BACKUP_TZ}" /etc/localtime 2>/dev/null || true
    echo "${BACKUP_TZ}" > /etc/timezone 2>/dev/null || true
fi

echo "Data e hora local: $(date)"
echo "Frequência cron: ${BACKUP_CRON:-0 4 * * *}"
echo "Bucket destino: ${R2_BUCKET}"

# Exportar variáveis de ambiente para o cron
env | grep -E '^(CANARY_|R2_|BACKUP_|AWS_|PATH)' > /etc/environment

# Configurar crontab
CRON_SCHEDULE="${BACKUP_CRON:-0 4 * * *}"
echo "${CRON_SCHEDULE} . /etc/environment && /usr/local/bin/backup.sh >> /var/log/backup.log 2>&1" > /etc/crontabs/root

echo "Iniciando crond em primeiro plano..."
# Inicia cron com logs direcionados para stdout
exec crond -f -l 2
