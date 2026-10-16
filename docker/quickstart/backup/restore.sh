#!/usr/bin/env bash
set -eo pipefail

echo "=========================================="
echo "    Canary Database R2 Restore Service    "
echo "=========================================="

DB_HOST="${CANARY_DB_HOST:-db}"
DB_PORT="${CANARY_DB_PORT:-3306}"
DB_USER="${CANARY_DB_USER:-root}"
DB_PASSWORD="${CANARY_DB_PASSWORD:-root}"
DB_NAME="${CANARY_DB_NAME:-canary}"

export AWS_ACCESS_KEY_ID="${R2_ACCESS_KEY_ID}"
export AWS_SECRET_ACCESS_KEY="${R2_SECRET_ACCESS_KEY}"
export AWS_DEFAULT_REGION="auto"

R2_ENDPOINT="https://${R2_ACCOUNT_ID}.r2.cloudflarestorage.com"
PREFIX="${R2_PATH_PREFIX:-backups}"
PREFIX="${PREFIX#/}"
PREFIX="${PREFIX%/}"

if [ -n "${PREFIX}" ]; then
    BASE_S3_URI="s3://${R2_BUCKET}/${PREFIX}"
else
    BASE_S3_URI="s3://${R2_BUCKET}"
fi

# Se foi passado um arquivo específico por parâmetro, usa ele.
# Caso contrário, busca dinamicamente o último backup criado no R2.
SPECIFIED_BACKUP="$1"

if [ -n "${SPECIFIED_BACKUP}" ]; then
    TARGET_BACKUP="$(basename "${SPECIFIED_BACKUP}")"
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] Backup especificado manualmente: ${TARGET_BACKUP}"
else
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] Consultando Cloudflare R2 para obter o último backup disponível em ${BASE_S3_URI}/..."
    TARGET_BACKUP=$(aws s3 ls "${BASE_S3_URI}/" --endpoint-url "${R2_ENDPOINT}" \
        | grep -E 'canary_[0-9]{8}_[0-9]{6}\.sql\.gz' \
        | awk '{print $4}' \
        | sort \
        | tail -n 1 || true)
fi

if [ -z "${TARGET_BACKUP}" ]; then
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] ERRO: Nenhum arquivo de backup encontrado em ${BASE_S3_URI}/"
    exit 1
fi

TMP_DIR="/tmp/restore"
mkdir -p "${TMP_DIR}"
LOCAL_FILEPATH="${TMP_DIR}/${TARGET_BACKUP}"

echo "[$(date '+%Y-%m-%d %H:%M:%S')] Último backup identificado: ${TARGET_BACKUP}"
echo "[$(date '+%Y-%m-%d %H:%M:%S')] Baixando do R2 (${BASE_S3_URI}/${TARGET_BACKUP})..."

aws s3 cp "${BASE_S3_URI}/${TARGET_BACKUP}" "${LOCAL_FILEPATH}" --endpoint-url "${R2_ENDPOINT}"

FILE_SIZE=$(du -h "${LOCAL_FILEPATH}" | cut -f1)
echo "[$(date '+%Y-%m-%d %H:%M:%S')] Download concluído com sucesso (${FILE_SIZE})."

echo "[$(date '+%Y-%m-%d %H:%M:%S')] Aplicando dump no banco de dados '${DB_NAME}' em ${DB_HOST}:${DB_PORT}..."
gunzip -c "${LOCAL_FILEPATH}" | mariadb \
    --host="${DB_HOST}" \
    --port="${DB_PORT}" \
    --user="${DB_USER}" \
    --password="${DB_PASSWORD}" \
    "${DB_NAME}"

echo "[$(date '+%Y-%m-%d %H:%M:%S')] Restauração finalizada com sucesso no banco '${DB_NAME}'!"

rm -f "${LOCAL_FILEPATH}"
echo "[$(date '+%Y-%m-%d %H:%M:%S')] Arquivo temporário local removido."
echo "=========================================="
