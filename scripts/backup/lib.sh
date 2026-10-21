#!/usr/bin/env bash
# Shared by backup.sh, restore.sh and restore-test.sh. Not run on its own.

set -Eeuo pipefail
umask 077

CONF="${ELAYADAH_BACKUP_CONF:-/root/.config/elayadah-backup.conf}"
[ -r "$CONF" ] || { echo "Missing settings file: $CONF (see backup.conf.example)" >&2; exit 2; }
# shellcheck source=/dev/null
. "$CONF"

RCLONE="${RCLONE:-rclone}"

log() { printf '%s  %s\n' "$(date -u +%FT%TZ)" "$*"; }

# Reports to healthchecks.io. Never lets a reporting hiccup fail a backup.
hc() { # hc <url> [/start|/fail|""] [message]
  local url="$1" suffix="${2:-}" body="${3:-}"
  [ -n "$url" ] || return 0
  curl -fsS -m 10 --retry 3 -o /dev/null --data-raw "${body:0:9000}" "${url}${suffix}" || log "warning: could not reach healthchecks.io"
}

compose() { (cd "$STACK_DIR" && docker compose -f "$COMPOSE_FILE" "$@"); }

# Runs a mysql/mysqldump client inside the database container, with the
# container's own root password — it never appears on a command line here.
db_exec() { # db_exec <command...>  (sees $MYSQL_DATABASE inside)
  compose exec -T "$DB_SERVICE" sh -c "MYSQL_PWD=\"\$MYSQL_ROOT_PASSWORD\" $*"
}
