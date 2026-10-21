#!/usr/bin/env bash
# Hourly backup of the production clinic system.
#
# One encrypted file per run holds everything needed to rebuild:
#   db.sql        the whole database (consistent, taken without locking)
#   storage.tar   uploaded files (doctor photos and the like)
#   env           the server's .env (keys and passwords)
#   manifest.txt  when, which commit, row counts and checksums
#
# The file is checked, encrypted with the password in PASSPHRASE_FILE,
# copied to Google Drive and checked again there, then old copies are
# thinned. Success is reported to healthchecks.io; any failure reports
# /fail, and a run that never reports at all is the alarm too.
#
# Runs from root's cron on the host, not from the app, so it still runs
# when the app is down. Settings: backup.conf.example.

HERE="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=lib.sh
. "$HERE/lib.sh"

: "${APP_STORAGE_PATH:=/app/storage/app}"

exec 9>/run/lock/elayadah-backup.lock 2>/dev/null || exec 9>"/tmp/elayadah-backup.lock"
flock -n 9 || { log "another backup is still running; skipping this one"; exit 0; }

RUN_LOG="$(mktemp)"
# Everything printed — including a failing command's own error — goes to the
# log that a failure report carries.
exec > >(tee -a "$RUN_LOG") 2>&1
WORK=""
cleanup() { [ -n "$WORK" ] && rm -rf "$WORK"; rm -f "$RUN_LOG"; }
on_error() {
  # A failure inside $(...) or a helper trips this trap there too; only the
  # script itself reports, once.
  [ "$BASHPID" = "$$" ] || exit 1
  local line="$1"
  log "FAILED at line $line"
  hc "$HC_BACKUP_URL" /fail "$(tail -n 40 "$RUN_LOG")"
  cleanup
  exit 1
}
trap 'on_error $LINENO' ERR
trap cleanup EXIT

step() { log "$*"; }

hc "$HC_BACKUP_URL" /start
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
NAME="${NAME_PREFIX}-${STAMP}.tar.gz.gpg"

# --- 0. Preconditions -------------------------------------------------------
[ -s "$PASSPHRASE_FILE" ] || { step "encryption password file is missing or empty: $PASSPHRASE_FILE"; false; }
mkdir -p "$LOCAL_DIR"
free_mb=$(df -Pm "$LOCAL_DIR" | awk 'NR==2 {print $4}')
[ "$free_mb" -ge "$MIN_FREE_MB" ] || { step "only ${free_mb} MB free on the backup disk"; false; }
WORK="$(mktemp -d "$LOCAL_DIR/.work-XXXXXX")"

# --- 1. Database ------------------------------------------------------------
step "dumping the database"
db_exec 'mysqldump -uroot --single-transaction --quick --routines --triggers --events --hex-blob --no-tablespaces "$MYSQL_DATABASE"' > "$WORK/db.sql"

# A dump that stopped half-way still "succeeds" as a file. Prove it finished
# and has the shape of this database before trusting it.
tail -n 1 "$WORK/db.sql" | grep -q '^-- Dump completed' || { step "the dump did not finish"; false; }
tables=$(grep -c '^CREATE TABLE' "$WORK/db.sql" || true)
[ "$tables" -ge 10 ] || { step "the dump has only $tables tables"; false; }

counts=$(db_exec 'mysql -uroot -N "$MYSQL_DATABASE"' <<'SQL'
SELECT 'clinics', COUNT(*) FROM clinics UNION ALL
SELECT 'users', COUNT(*) FROM users UNION ALL
SELECT 'patients', COUNT(*) FROM patients UNION ALL
SELECT 'bookings', COUNT(*) FROM bookings;
SQL
)

# --- 2. Uploaded files and the server's settings ------------------------------
step "copying uploaded files and .env"
compose exec -T "$APP_SERVICE" tar -C "$(dirname "$APP_STORAGE_PATH")" -cf - "$(basename "$APP_STORAGE_PATH")" > "$WORK/storage.tar"
tar -tf "$WORK/storage.tar" > /dev/null
cp "$STACK_DIR/.env" "$WORK/env"

{
  echo "backup:   $NAME"
  echo "taken:    $(date -u +%FT%TZ)"
  echo "host:     $(hostname)"
  echo "commit:   $(git -C "$STACK_DIR" rev-parse --short HEAD 2>/dev/null || echo unknown)"
  echo "tables:   $tables"
  echo "rows:"
  printf '%s\n' "$counts" | sed 's/^/  /'
  echo "sha256:"
  (cd "$WORK" && sha256sum db.sql storage.tar env | sed 's/^/  /')
} > "$WORK/manifest.txt"

# --- 3. Pack, encrypt, and prove it opens -------------------------------------
step "encrypting"
tar -C "$WORK" -czf - manifest.txt db.sql storage.tar env \
  | gpg --batch --yes --quiet --pinentry-mode loopback --passphrase-file "$PASSPHRASE_FILE" \
        --symmetric --cipher-algo AES256 --output "$LOCAL_DIR/$NAME"
gpg --batch --quiet --pinentry-mode loopback --passphrase-file "$PASSPHRASE_FILE" --decrypt "$LOCAL_DIR/$NAME" \
  | tar -xzOf - manifest.txt > /dev/null

# --- 4. Off the server --------------------------------------------------------
step "uploading to $REMOTE"
"$RCLONE" copyto "$LOCAL_DIR/$NAME" "$REMOTE/$NAME" --retries 5 --low-level-retries 10 --retries-sleep 20s
local_size=$(stat -c %s "$LOCAL_DIR/$NAME")
remote_size=$("$RCLONE" size --json "$REMOTE/$NAME" | python3 -c 'import json,sys; print(json.load(sys.stdin)["bytes"])')
[ "$local_size" = "$remote_size" ] || { step "uploaded size $remote_size differs from local $local_size"; false; }

# --- 5. Thin old copies -------------------------------------------------------
step "thinning old copies"
find "$LOCAL_DIR" -maxdepth 1 -name "${NAME_PREFIX}-*.tar.gz.gpg" -mmin +$((KEEP_LOCAL_HOURS * 60)) -print -delete | sed 's/^/  removed local: /'

# Remote: keep everything from the last KEEP_REMOTE_HOURLY_HOURS, then the
# first backup of each UTC day for KEEP_REMOTE_DAILY_DAYS. Names carry the
# time, so the decision never depends on Drive's own timestamps.
"$RCLONE" lsf "$REMOTE" --files-only --include "${NAME_PREFIX}-*.tar.gz.gpg" \
  | python3 -c '
import sys, re
from datetime import datetime, timedelta, timezone
prefix, hourly_h, daily_d = sys.argv[1], int(sys.argv[2]), int(sys.argv[3])
now = datetime.now(timezone.utc)
pat = re.compile(re.escape(prefix) + r"-(\d{8}T\d{6}Z)\.tar\.gz\.gpg$")
files = sorted(
    (datetime.strptime(m.group(1), "%Y%m%dT%H%M%SZ").replace(tzinfo=timezone.utc), n)
    for n in (l.strip() for l in sys.stdin) if (m := pat.match(n))
)
kept_days = set()
for when, name in files:
    age = now - when
    if age <= timedelta(hours=hourly_h):
        continue
    day = when.date()
    if age <= timedelta(days=daily_d) and day not in kept_days:
        kept_days.add(day)
        continue
    print(name)
' "$NAME_PREFIX" "$KEEP_REMOTE_HOURLY_HOURS" "$KEEP_REMOTE_DAILY_DAYS" \
  | while IFS= read -r old; do
      "$RCLONE" deletefile "$REMOTE/$old"
      step "  removed remote: $old"
    done

# --- 6. Done -------------------------------------------------------------------
step "done: $NAME ($(numfmt --to=iec "$local_size")) — $(printf '%s' "$counts" | tr '\t\n' '= ' )"
hc "$HC_BACKUP_URL" "" "$(cat "$RUN_LOG")"
