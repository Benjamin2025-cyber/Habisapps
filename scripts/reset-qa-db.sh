#!/usr/bin/env bash
#
# Resets the QA database back to the state a fresh deployment produces, while
# preserving ALL user accounts, sectors and sub-sectors.
#
# Kept:    everything DatabaseSeeder installs -- roles and permissions, the
#          institution profile, report definitions, batch procedures, BEAC
#          denominations, the bootstrap admin, the default agency and the PCEMF
#          chart of accounts -- plus every user account with its password hash,
#          public_id and role assignments, and all sectors and sub-sectors.
# Dropped: every other row. Clients, accounts, loans, journals, teller sessions,
#          accounting days, operation-code mappings, products, and all access
#          tokens are tester-created and do NOT come back.
#
# The default agency and the bootstrap admin are opt-in through env
# (SEED_DEFAULT_AGENCY, SEED_BOOTSTRAP_ADMIN) and no-op unless enabled. Note
# that docker compose applies env_file only at container creation: after editing
# .env run `docker compose up -d` or the new values are invisible here.
#
# Run this ON the VPS:
#   ./reset-qa-db.sh              # prompts for confirmation
#   ./reset-qa-db.sh --yes        # unattended
#   ./reset-qa-db.sh --keep=a@b.com,c@d.com
#
# The seeder list below is deliberately identical to .github/workflows/deploy.yml.
# If a seeder is added there, add it here too or the reset will drift from what
# a real deploy produces.

set -Eeuo pipefail

APP_DIR="${APP_DIR:-/srv/habis-finance-api}"
BACKUP_DIR="${BACKUP_DIR:-$HOME/db-backups}"

ASSUME_YES=0
SKIP_BACKUP=0
TARGET_DB=""
KEPT_JSON=/tmp/qa-reset-kept-users.json

usage() {
    cat <<'USAGE'
Resets the QA database to the state a fresh deployment produces, preserving all
user accounts, sectors and sub-sectors (password hashes, public_ids and roles
intact).

Everything else is destroyed: agencies, chart of accounts, operation mappings,
products, clients, loans, journals, teller sessions, accounting days, and all
access tokens. None of that is seeded by the pipeline, so testers must rebuild
the accounting config afterwards.

Usage: ./reset-qa-db.sh [options]     (run this on the VPS)

Options:
  --yes, -y          Skip the interactive confirmation.
  --no-backup        Skip the pre-reset pg_dump. Not recommended.
  --database=NAME    Rehearsal mode: run the whole reset against NAME instead of
                     the live database. Leaves the real database untouched and
                     skips maintenance mode. Use it to dry-run a change to this
                     script against a scratch copy.
  --help, -h         Show this help.

Environment:
  APP_DIR            Compose project dir (default /srv/habis-finance-api).
  BACKUP_DIR         Where the pg_dump lands (default ~/db-backups).
USAGE
}

for arg in "$@"; do
    case "$arg" in
        -y|--yes)     ASSUME_YES=1 ;;
        --no-backup)  SKIP_BACKUP=1 ;;
        --keep=*)     KEEP_EMAILS="${arg#--keep=}" ;;
        --database=*) TARGET_DB="${arg#--database=}" ;;
        -h|--help)    usage; exit 0 ;;
        *) echo "Unknown option: $arg" >&2; usage >&2; exit 2 ;;
    esac
done

log()  { printf '\n\033[1;36m==> %s\033[0m\n' "$*"; }
warn() { printf '\033[1;33m[warn]\033[0m %s\n' "$*"; }
die()  { printf '\033[1;31m[fail]\033[0m %s\n' "$*" >&2; exit 1; }

dc() { docker compose -f "$APP_DIR/docker-compose.yml" "$@"; }

# In rehearsal mode every container command is pointed at a scratch database, so
# the live one is never opened. Requires an uncached config (the script clears it).
EXEC_ENV=()
REHEARSAL=0
if [[ -n "$TARGET_DB" ]]; then
    REHEARSAL=1
    EXEC_ENV=(-e "DB_DATABASE=$TARGET_DB")
fi

api() { dc exec -T "${EXEC_ENV[@]}" api "$@"; }

[[ -d "$APP_DIR" ]] || die "APP_DIR does not exist: $APP_DIR"

# The app is left in maintenance mode and the queue stopped between the wipe and
# the restore. If anything in between fails we must still bring both back up,
# otherwise the reset takes the whole QA environment offline.
RECOVER_NEEDED=0
recover() {
    local rc=$?
    if (( RECOVER_NEEDED )); then
        warn "Restoring service after unexpected exit (status $rc)..."
        dc start queue >/dev/null 2>&1 || true
        api php artisan up >/dev/null 2>&1 || true
        api rm -f "$KEPT_JSON" >/dev/null 2>&1 || true
        warn "Service restored, but the database may be HALF-RESET. Check the summary above and re-run, or restore the backup."
    fi
}
trap recover EXIT

log "Checking containers"
api php artisan --version >/dev/null 2>&1 || die "Cannot reach the api container. Is the stack up? (docker compose up -d)"
dc ps --format 'table {{.Service}}\t{{.State}}'

# Ask Laravel which database it actually resolves, not merely what is in the
# environment: a cached config silently ignores the DB_DATABASE override, which
# would point a "rehearsal" straight at the live database.
target=$(api php -r '
require "/var/www/html/vendor/autoload.php";
$app = require "/var/www/html/bootstrap/app.php";
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
echo \Illuminate\Support\Facades\DB::connection()->getDatabaseName();' | tr -d '\r\n')
[[ -n "$target" ]] || die "Could not determine the target database."

if (( REHEARSAL )); then
    [[ "$target" == "$TARGET_DB" ]] || die \
"Refusing to run: rehearsal asked for '$TARGET_DB' but Laravel resolves '$target'.
 The config is almost certainly cached. Run:
   docker compose -f $APP_DIR/docker-compose.yml exec api php artisan config:clear"
    log "REHEARSAL against '$target' -- the live database is not touched"
fi

if (( ! ASSUME_YES )); then
    cat <<CONFIRM

This DESTROYS all data in database '${target}'.
  Preserved : deploy-seeded reference data + all user accounts + sectors + sub-sectors
  Destroyed : agencies, chart of accounts, operation mappings, products,
              clients, accounts, loans, journals, teller sessions,
              accounting days, and all access tokens.
CONFIRM
    read -r -p "Type 'reset' to continue: " reply
    [[ "$reply" == "reset" ]] || die "Aborted."
fi

# ---------------------------------------------------------------------------
# 1. Backup
# ---------------------------------------------------------------------------
if (( REHEARSAL )); then
    warn "Rehearsal mode: skipping backup of the scratch database."
elif (( SKIP_BACKUP )); then
    warn "Skipping backup (--no-backup)."
else
    log "Backing up the database"
    mkdir -p "$BACKUP_DIR"
    backup_file="$BACKUP_DIR/pre-reset-$(date +%Y%m%d-%H%M%S).sql"
    # Credentials are read from the container's own env and never printed.
    api sh -lc 'PGPASSWORD="$DB_PASSWORD" pg_dump -h "$DB_HOST" -p "${DB_PORT:-5432}" \
        -U "$DB_USERNAME" -d "$DB_DATABASE" --no-owner --no-privileges' > "$backup_file"
    grep -q 'PostgreSQL database dump complete' "$backup_file" \
        || die "Backup looks truncated, refusing to wipe: $backup_file"
    echo "  $(du -h "$backup_file" | cut -f1)  $backup_file"
fi

# ---------------------------------------------------------------------------
# 2. Export the data to preserve (accounts, sectors, sub_sectors)
# ---------------------------------------------------------------------------
log "Exporting data to preserve"
dc exec -T "${EXEC_ENV[@]}" -e KEPT_JSON="$KEPT_JSON" api php <<'PHP'
<?php
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Models\User;

$out = ['users' => [], 'sectors' => [], 'sub_sectors' => []];

// --- sectors ---
$sectors = DB::table('sectors')->orderBy('id')->get();
foreach ($sectors as $row) {
    $out['sectors'][] = (array) $row;
    printf("  export sector %-6s %s\n", $row->code, $row->name);
}
printf("  %d sector(s) staged\n", count($out['sectors']));

// --- sub_sectors ---
$subSectors = DB::table('sub_sectors')->orderBy('id')->get();
foreach ($subSectors as $row) {
    $out['sub_sectors'][] = (array) $row;
    printf("  export sub_sector %-12s %s\n", $row->code, $row->name);
}
printf("  %d sub_sector(s) staged\n", count($out['sub_sectors']));

// --- users ---
$users = DB::table('users')->orderBy('id')->get();
foreach ($users as $row) {
    $data = (array) $row;
    $user = User::query()->find($row->id);
    $data['__roles'] = $user?->roles->pluck('name')->all() ?? [];
    $data['__permissions'] = $user?->getDirectPermissions()->pluck('name')->all() ?? [];
    $out['users'][] = $data;
    printf("  export %-38s roles=%s\n", $row->email, implode(',', $data['__roles']) ?: '-');
}
printf("  %d account(s) staged\n", count($out['users']));

if (empty($out['users'])) {
    fwrite(STDERR, "ABORT: no users found - refusing to wipe blind.\n");
    exit(1);
}

file_put_contents((string) getenv('KEPT_JSON'), json_encode($out, JSON_UNESCAPED_UNICODE));
PHP

# ---------------------------------------------------------------------------
# 3. Wipe and rebuild
# ---------------------------------------------------------------------------
if (( REHEARSAL )); then
    warn "Rehearsal mode: leaving the app serving and the queue running."
else
    log "Entering maintenance mode"
    RECOVER_NEEDED=1
    api php artisan down --render=errors::503 || true
    dc stop queue
fi

log "Rebuilding the schema (migrate:fresh)"
api php artisan migrate:fresh --force --ansi 2>&1 | tail -5

# One call, not a hand-listed set: DatabaseSeeder already encodes the dependency
# order (institution profile and the default agency before the PCEMF chart, which
# hangs its detail accounts off an agency). Listing seeders here instead means
# the list silently drifts as new ones are added.
log "Seeding the installation (DatabaseSeeder)"
api php artisan db:seed --force --ansi 2>&1 | grep -viE '^\s*$|RUNNING' || true

# ---------------------------------------------------------------------------
# 4. Restore the preserved data
# ---------------------------------------------------------------------------
log "Restoring preserved sectors and sub_sectors"
dc exec -T "${EXEC_ENV[@]}" -e KEPT_JSON="$KEPT_JSON" api php <<'PHP'
<?php
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$file = (string) getenv('KEPT_JSON');
$payload = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);

DB::transaction(function () use ($payload): void {
    // --- sectors: upsert by code, build old_id -> new_id map ---
    $sectorIdMap = [];
    foreach ($payload['sectors'] ?? [] as $row) {
        $oldId = $row['id'];
        $existing = DB::table('sectors')->where('code', $row['code'])->first();
        unset($row['id'], $row['created_at'], $row['updated_at']);
        if ($existing !== null) {
            DB::table('sectors')->where('id', $existing->id)->update($row);
            $sectorIdMap[$oldId] = $existing->id;
            $verb = 'updated';
        } else {
            $newId = DB::table('sectors')->insertGetId($row);
            $sectorIdMap[$oldId] = $newId;
            $verb = 'inserted';
        }
        printf("  %-9s sector %-6s %s\n", $verb, $row['code'], $row['name']);
    }

    // --- sub_sectors: upsert by (sector_id, code) with mapped sector_id ---
    foreach ($payload['sub_sectors'] ?? [] as $row) {
        $oldSectorId = $row['sector_id'];
        $newSectorId = $sectorIdMap[$oldSectorId] ?? null;
        if ($newSectorId === null) {
            fwrite(STDERR, "  [warn] skipping sub_sector {$row['code']}: parent sector {$oldSectorId} not found\n");
            continue;
        }
        $existing = DB::table('sub_sectors')
            ->where('sector_id', $newSectorId)
            ->where('code', $row['code'])
            ->first();
        unset($row['id'], $row['created_at'], $row['updated_at']);
        $row['sector_id'] = $newSectorId;
        if ($existing !== null) {
            DB::table('sub_sectors')->where('id', $existing->id)->update($row);
            $verb = 'updated';
        } else {
            DB::table('sub_sectors')->insertGetId($row);
            $verb = 'inserted';
        }
        printf("  %-9s sub_sector %-12s %s\n", $verb, $row['code'], $row['name']);
    }
});

printf("  %d sector(s), %d sub_sector(s) restored\n",
    count($payload['sectors'] ?? []), count($payload['sub_sectors'] ?? []));
PHP

log "Restoring preserved accounts"
dc exec -T "${EXEC_ENV[@]}" -e KEPT_JSON="$KEPT_JSON" api php <<'PHP'
<?php
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Models\User;

$file = (string) getenv('KEPT_JSON');
$payload = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
$rows = $payload['users'] ?? [];

DB::transaction(function () use ($rows): void {
    foreach ($rows as $row) {
        $roles = $row['__roles'] ?? [];
        $perms = $row['__permissions'] ?? [];
        unset($row['__roles'], $row['__permissions'], $row['id']);

        // Agencies are gone; the FK is on_delete:restrict so a stale id would
        // fail outright. Testers re-create the agency and re-assign staff.
        $row['agency_id'] = null;
        $row['agency_code'] = null;
        $row['agency_name'] = null;
        $row['invited_by_user_id'] = null;
        $row['remember_token'] = null;
        $row['last_login_at'] = null;

        // BootstrapAdminSeeder already recreated the admin, so update in place;
        // everyone else is a fresh insert. Either way the write goes through the
        // query builder so the model's `hashed` cast cannot re-hash the hash.
        $existing = DB::table('users')->where('email', $row['email'])->first();
        if ($existing !== null) {
            DB::table('users')->where('id', $existing->id)->update($row);
            $verb = 'updated';
        } else {
            DB::table('users')->insertGetId($row);
            $verb = 'inserted';
        }

        $user = User::query()->where('email', $row['email'])->firstOrFail();
        $user->syncRoles($roles);
        $user->syncPermissions($perms);

        printf(
            "  %-9s %-38s id=%-3d %-22s pwd=%-9s roles=%s\n",
            $verb,
            $user->email,
            $user->id,
            $user->status,
            $row['password'] === null ? 'NONE' : 'preserved',
            implode(',', $roles) ?: '-'
        );
    }
});

@unlink($file);
printf("  %d account(s) restored\n", count($rows));
PHP

# ---------------------------------------------------------------------------
# 5. Back into service
# ---------------------------------------------------------------------------
log "Clearing caches and restarting workers"
api php artisan cache:clear >/dev/null
api php artisan permission:cache-reset >/dev/null
if (( ! REHEARSAL )); then
    api php artisan config:clear >/dev/null
    dc start queue >/dev/null
    api php artisan queue:restart >/dev/null
    api php artisan up
fi
RECOVER_NEEDED=0

# ---------------------------------------------------------------------------
# 6. Verify
# ---------------------------------------------------------------------------
log "Post-reset state"
api php <<'PHP'
<?php
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Models\User;

$tables = DB::select("select tablename from pg_tables where schemaname = 'public' order by tablename");
$empty = 0;
echo '  non-empty tables:'.PHP_EOL;
foreach ($tables as $t) {
    $count = DB::table($t->tablename)->count();
    if ($count > 0) {
        printf("    %-40s %d\n", $t->tablename, $count);
    } else {
        $empty++;
    }
}
printf("  %d of %d tables empty\n", $empty, count($tables));

echo '  users:'.PHP_EOL;
foreach (User::query()->orderBy('id')->get() as $u) {
    printf("    %-3d %-34s %-22s %s\n", $u->id, $u->email, $u->status, $u->roles->pluck('name')->implode(','));
}

$sectorCount = DB::table('sectors')->count();
$subSectorCount = DB::table('sub_sectors')->count();
printf("  sectors: %d, sub_sectors: %d\n", $sectorCount, $subSectorCount);
PHP

if (( REHEARSAL )); then
    log "Rehearsal finished against '$target'. The live database was not touched."
    exit 0
fi

status=$(api sh -lc 'curl -s -o /dev/null -w "%{http_code}" --max-time 15 http://127.0.0.1:8000/up' || echo 000)
log "Health check /up -> HTTP ${status}"
[[ "$status" == "200" ]] || warn "Expected HTTP 200 from /up."

cat <<'DONE'

Reset complete. Next steps for the testers:
  1. Everyone must log in again -- all access tokens were revoked.
  2. The default agency, denominations and the PCEMF chart of accounts are
     seeded. Sectors and sub-sectors are preserved. Preserved accounts are NOT
     attached to the agency: assign staff, then set up operation-account mappings
     and products and open an accounting day before attempting any transaction.
DONE
