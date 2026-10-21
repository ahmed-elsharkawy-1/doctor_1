#!/usr/bin/env bash
# Weekly proof that the backups on Google Drive can really be restored.
#
# Takes the newest backup from Google Drive, unlocks it, loads its database
# into a throwaway MySQL container (never the live one) and compares the row
# counts with those recorded when the backup was taken. Reports the result
# to healthchecks.io; a failure, or no report at all, is the alarm.

HERE="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=lib.sh
. "$HERE/lib.sh"

WORK="$(mktemp -d)"
CONTAINER="elayadah-restore-test-$$"
RUN_LOG="$WORK/run.log"
exec > >(tee -a "$RUN_LOG") 2>&1

cleanup() { docker rm -f "$CONTAINER" >/dev/null 2>&1 || true; rm -rf "$WORK"; }
on_error() {
  # A failure inside $(...) or a helper trips this trap there too; only the
  # script itself reports, once.
  [ "$BASHPID" = "$$" ] || exit 1
  log "FAILED at line $1"
  hc "$HC_RESTORE_URL" /fail "$(tail -n 40 "$RUN_LOG")"
  cleanup
  exit 1
}
trap 'on_error $LINENO' ERR
trap cleanup EXIT

hc "$HC_RESTORE_URL" /start

"$HERE/restore.sh" --into "$WORK/restored" > /dev/null
log "restored $(sed -n 's/^backup: *//p' "$WORK/restored/manifest.txt")"

# A private, throwaway MySQL with no network and a random password.
pw="$(head -c 24 /dev/urandom | base64 | tr -dc 'A-Za-z0-9')"
docker run -d --name "$CONTAINER" --network none \
  -e MYSQL_ROOT_PASSWORD="$pw" -e MYSQL_DATABASE=restore_test \
  "$RESTORE_TEST_IMAGE" > /dev/null

for _ in $(seq 1 90); do
  docker exec "$CONTAINER" sh -c "MYSQL_PWD='$pw' mysql -uroot -e 'SELECT 1' restore_test" >/dev/null 2>&1 && break
  sleep 2
done

log "loading the database into the throwaway container"
docker exec -i "$CONTAINER" sh -c "MYSQL_PWD='$pw' mysql -uroot restore_test" < "$WORK/restored/db.sql"

# The counts recorded at backup time, and what the restored copy holds.
expected="$(sed -n '/^rows:/,/^sha256:/p' "$WORK/restored/manifest.txt" | sed '1d;$d' | sed 's/^ *//' | tr -s '\t ' ' ')"
actual="$(docker exec -i "$CONTAINER" sh -c "MYSQL_PWD='$pw' mysql -uroot -N restore_test" <<'SQL' | tr -s '\t ' ' '
SELECT 'clinics', COUNT(*) FROM clinics UNION ALL
SELECT 'users', COUNT(*) FROM users UNION ALL
SELECT 'patients', COUNT(*) FROM patients UNION ALL
SELECT 'bookings', COUNT(*) FROM bookings;
SQL
)"

if [ "$expected" != "$actual" ]; then
  log "row counts differ. recorded: [$(echo "$expected" | tr '\n' ';')] restored: [$(echo "$actual" | tr '\n' ';')]"
  false
fi

tar -tf "$WORK/restored/storage.tar" > /dev/null
[ -s "$WORK/restored/env" ]

log "restore test passed: $(echo "$actual" | tr '\n' ' ')"
hc "$HC_RESTORE_URL" "" "$(cat "$RUN_LOG")"
