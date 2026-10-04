#!/bin/bash
# Pull-based auto-deploy, run by cron every minute on the live server (installed as ~/auto-deploy.sh).
#
# GitHub Actions (.github/workflows/deploy.yml) tests the code, builds the assets and publishes the
# `production` branch. This script notices when that branch has moved and deploys it:
#   fetch -> (DB snapshot + maintenance mode only if migrations/composer changed) -> reset code ->
#   composer install (only if composer.* changed) -> migrate -> rebuild caches -> health check.
# If the site was healthy before and is not after, or any step fails, the previous code is restored
# and that commit is not retried. Database changes already applied by a failed migration are NOT
# undone — the pre-deploy snapshot in ~/backups is the way back for those.
#
# Nothing here needs a secret: the repository is public and the server only reads from it.
# Log: ~/deploy.log      Retry a failed commit: rm ~/.auto-deploy-failed (or push a new commit).

set -u
export PATH=/usr/local/bin:/usr/bin:/bin:$PATH
export COMPOSER_HOME="${COMPOSER_HOME:-$HOME/.composer}"
export GIT_TERMINAL_PROMPT=0   # never hang waiting for a password under cron

APP="${APP:-$HOME/domains/ai.amsal.online/public_html}"
REPO="${REPO:-https://github.com/msalmanali0683/examination_system.git}"
BRANCH="${BRANCH:-production}"
HEALTH_URL="${HEALTH_URL:-https://ai.amsal.online/login}"
PHP="${PHP:-php}"
LOG="$HOME/deploy.log"
FAILED_MARK="$HOME/.auto-deploy-failed"

log() { printf '%s %s\n' "$(date -u '+%Y-%m-%d %H:%M:%S UTC')" "$*" >> "$LOG"; }

# Only one run at a time: cron fires every minute and a deploy can take longer.
exec 9>"$HOME/.auto-deploy.lock"
flock -n 9 || exit 0

cd "$APP" || { log "ABORT: $APP is missing"; exit 1; }

remote=$(git ls-remote "$REPO" "refs/heads/$BRANCH" 2>/dev/null | cut -f1)
[ -n "$remote" ] || exit 0                                       # GitHub unreachable or no such branch
current=$(git rev-parse HEAD)
[ "$remote" != "$current" ] || exit 0                            # nothing new
[ "$remote" != "$(cat "$FAILED_MARK" 2>/dev/null)" ] || exit 0   # this commit already failed once

log "new commit ${remote:0:7} on $BRANCH (running ${current:0:7})"

if ! "$PHP" artisan migrate:status >/dev/null 2>&1; then
    log "SKIP: the database is unreachable, so a deploy could not be checked — will retry"
    exit 0
fi

git fetch -q "$REPO" "$BRANCH" >>"$LOG" 2>&1 || { log "FAIL: git fetch"; exit 1; }
new=$(git rev-parse FETCH_HEAD)

changed=$(git diff --name-only "$current" "$new")
needs_composer=$(printf '%s\n' "$changed" | grep -cE '^composer\.(json|lock)$')
has_migrations=$(printf '%s\n' "$changed" | grep -c '^database/migrations/')

http_code() { curl -s -o /dev/null -m 25 -w '%{http_code}' "$HEALTH_URL" 2>/dev/null; }
is_healthy() { [ "$1" = "200" ] || [ "$1" = "302" ]; }
before=$(http_code)

getenv() { grep -E "^$1=" "$APP/.env" | head -1 | cut -d= -f2- | sed -E "s/^\"(.*)\"\$/\1/; s/^'(.*)'\$/\1/"; }

dump_db() {
    local f="$HOME/backups/pre-deploy-$(date -u +%Y%m%d-%H%M%S).sql.gz"
    mkdir -p "$HOME/backups" && chmod 700 "$HOME/backups"
    (
        set -o pipefail
        umask 077
        export MYSQL_PWD="$(getenv DB_PASSWORD)"
        mysqldump --single-transaction --no-tablespaces -h 127.0.0.1 -u "$(getenv DB_USERNAME)" "$(getenv DB_DATABASE)" 2>>"$LOG" | gzip > "$f"
    ) || return 1
    [ "$(zcat "$f" 2>/dev/null | grep -c '^CREATE TABLE')" -gt 0 ] || return 1
    ls -1t "$HOME"/backups/pre-deploy-*.sql.gz 2>/dev/null | tail -n +11 | xargs -r rm -f   # keep the last 10
    echo "$f"
}

rebuild_caches() {
    "$PHP" artisan config:clear >/dev/null 2>&1
    "$PHP" artisan route:clear  >/dev/null 2>&1
    "$PHP" artisan view:clear   >/dev/null 2>&1
    "$PHP" artisan config:cache >>"$LOG" 2>&1 && "$PHP" artisan route:cache >>"$LOG" 2>&1 && "$PHP" artisan view:cache >>"$LOG" 2>&1
}

snapshot=""
rollback() {
    log "ROLLBACK to ${current:0:7}: $1"
    git reset -q --hard "$current" >>"$LOG" 2>&1
    [ "$needs_composer" -gt 0 ] && composer install --no-dev --optimize-autoloader --no-interaction -q >>"$LOG" 2>&1
    rebuild_caches
    "$PHP" artisan up >>"$LOG" 2>&1
    echo "$new" > "$FAILED_MARK"
    [ -n "$snapshot" ] && log "pre-deploy database snapshot: $snapshot"
    exit 1
}

if [ "$has_migrations" -gt 0 ]; then
    snapshot=$(dump_db) || { log "FAIL: could not take the pre-deploy database snapshot — not deploying"; echo "$new" > "$FAILED_MARK"; exit 1; }
    log "database snapshot: $snapshot"
fi

if [ "$has_migrations" -gt 0 ] || [ "$needs_composer" -gt 0 ]; then
    "$PHP" artisan down --retry=30 >>"$LOG" 2>&1
fi

git reset -q --hard "$new" >>"$LOG" 2>&1 || rollback "git reset failed"

if [ "$needs_composer" -gt 0 ]; then
    composer install --no-dev --optimize-autoloader --no-interaction -q >>"$LOG" 2>&1 || rollback "composer install failed"
fi

"$PHP" artisan migrate --force >>"$LOG" 2>&1 || rollback "migration failed (changes it made before failing are not undone)"
rebuild_caches || rollback "cache rebuild failed"
"$PHP" artisan queue:restart >>"$LOG" 2>&1
"$PHP" artisan up >>"$LOG" 2>&1

sleep 3
after=$(http_code)
if is_healthy "$before" && ! is_healthy "$after"; then
    rollback "site was healthy (HTTP $before) before the deploy but answered HTTP $after after it"
fi

rm -f "$FAILED_MARK"
log "OK deployed ${new:0:7} (site HTTP $before -> $after)"
