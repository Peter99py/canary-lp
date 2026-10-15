#!/usr/bin/env bash
set -eo pipefail

TIMESTAMP=$(date +"%Y%m%d_%H%M%S")
BACKUP_DIR="/tmp/backups"
BACKUP_FILENAME="canary_${TIMESTAMP}.sql.gz"
BACKUP_FILEPATH="${BACKUP_DIR}/${BACKUP_FILENAME}"

mkdir -p "${BACKUP_DIR}"

DB_HOST="${CANARY_DB_HOST:-db}"
DB_PORT="${CANARY_DB_PORT:-3306}"
DB_USER="${CANARY_DB_USER:-root}"
DB_PASSWORD="${CANARY_DB_PASSWORD:-root}"
DB_NAME="${CANARY_DB_NAME:-canary}"

echo "[$(date '+%Y-%m-%d %H:%M:%S')] Iniciando dump do banco de dados '${DB_NAME}' em ${DB_HOST}:${DB_PORT}..."

mariadb-dump \
    --host="${DB_HOST}" \
    --port="${DB_PORT}" \
    --user="${DB_USER}" \
    --password="${DB_PASSWORD}" \
    --single-transaction \
    --quick \
    --routines \
    --triggers \
    "${DB_NAME}" | gzip -c > "${BACKUP_FILEPATH}"

FILE_SIZE=$(du -h "${BACKUP_FILEPATH}" | cut -f1)
echo "[$(date '+%Y-%m-%d %H:%M:%S')] Dump concluído com sucesso: ${BACKUP_FILENAME} (${FILE_SIZE})"

export AWS_ACCESS_KEY_ID="${R2_ACCESS_KEY_ID}"
export AWS_SECRET_ACCESS_KEY="${R2_SECRET_ACCESS_KEY}"
export AWS_DEFAULT_REGION="auto"

R2_ENDPOINT="https://${R2_ACCOUNT_ID}.r2.cloudflarestorage.com"
PREFIX="${R2_PATH_PREFIX:-backups}"
PREFIX="${PREFIX#/}"
PREFIX="${PREFIX%/}"

if [ -n "${PREFIX}" ]; then
    BASE_S3_URI="s3://${R2_BUCKET}/${PREFIX}"
    DEST_PATH="${BASE_S3_URI}/${BACKUP_FILENAME}"
else
    BASE_S3_URI="s3://${R2_BUCKET}"
    DEST_PATH="${BASE_S3_URI}/${BACKUP_FILENAME}"
fi

echo "[$(date '+%Y-%m-%d %H:%M:%S')] Enviando para Cloudflare R2: ${DEST_PATH}..."

aws s3 cp "${BACKUP_FILEPATH}" "${DEST_PATH}" --endpoint-url "${R2_ENDPOINT}"

echo "[$(date '+%Y-%m-%d %H:%M:%S')] Upload finalizado com sucesso no R2!"

rm -f "${BACKUP_FILEPATH}"
echo "[$(date '+%Y-%m-%d %H:%M:%S')] Arquivo temporário local removido."

RETENTION="${BACKUP_RETENTION:-4}"
if [ "${RETENTION}" -gt 0 ]; then
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] Verificando retenção no R2 (manter últimos ${RETENTION} backups)..."

    # Lista os arquivos ordenados por nome/data (o mais antigo primeiro)
    FILES_TO_DELETE=$(aws s3 ls "${BASE_S3_URI}/" --endpoint-url "${R2_ENDPOINT}" \
        | grep -E 'canary_[0-9]{8}_[0-9]{6}\.sql\.gz' \
        | awk '{print $4}' \
        | sort \
        | head -n -"${RETENTION}" || true)

    if [ -n "${FILES_TO_DELETE}" ]; then
        for OLD_FILE in ${FILES_TO_DELETE}; do
            echo "[$(date '+%Y-%m-%d %H:%M:%S')] Excluindo backup antigo: ${BASE_S3_URI}/${OLD_FILE}"
            aws s3 rm "${BASE_S3_URI}/${OLD_FILE}" --endpoint-url "${R2_ENDPOINT}"
        done
        echo "[$(date '+%Y-%m-%d %H:%M:%S')] Rotação de backups concluída com sucesso."
    else
        echo "[$(date '+%Y-%m-%d %H:%M:%S')] Quantidade de backups dentro do limite (${RETENTION}). Nenhuma exclusão necessária."
    fi
fi
