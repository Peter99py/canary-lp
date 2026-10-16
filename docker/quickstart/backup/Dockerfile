FROM alpine:3.20

RUN apk add --no-cache \
    mariadb-client \
    aws-cli \
    bash \
    tzdata \
    gzip \
    coreutils

COPY quickstart/backup/backup.sh /usr/local/bin/backup.sh
COPY quickstart/backup/restore.sh /usr/local/bin/restore.sh
COPY quickstart/backup/entrypoint.sh /usr/local/bin/entrypoint.sh

RUN chmod +x /usr/local/bin/backup.sh /usr/local/bin/restore.sh /usr/local/bin/entrypoint.sh

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
