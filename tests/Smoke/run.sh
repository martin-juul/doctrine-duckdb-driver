#!/usr/bin/env bash
# Runs the Symfony smoke application end to end against DuckDB: create the
# database, generate and run migrations, exercise the ORM, rebuild a referenced
# table and a self-referencing table, and drop the database. Stops with a
# non-zero exit code at the first step that fails.
set -uo pipefail

app="$(cd "$(dirname "${BASH_SOURCE[0]}")/symfony-app" && pwd)"
cd "$app" || exit 1

export APP_ENV=dev
export APP_DEBUG=1
export COLUMNS=500

database="$app/var/app.duckdb"
migrations="$app/var/migrations"

fail() {
    echo >&2
    echo "Smoke run failed at: $*" >&2
    exit 1
}

console() {
    php bin/console --no-interaction "$@"
}

step() {
    local label=$1
    shift

    echo
    echo "==> $label"

    "$@" || fail "$label"
}

migration_count() {
    find "$migrations" -name 'Version*.php' | wc -l
}

snapshot() {
    local stage=$1

    echo
    echo "==> Snapshot the data at stage $stage"

    console app:smoke:snapshot --check-tree > "$app/var/snapshot-$stage.json" || fail "snapshot at stage $stage"
    cat "$app/var/snapshot-$stage.json"

    if [[ $stage != 1 ]]; then
        diff -u "$app/var/snapshot-1.json" "$app/var/snapshot-$stage.json" || fail "data changed by the stage $stage migration"
        echo "Data matches the stage 1 snapshot."
    fi
}

if [[ ! -f vendor/autoload.php ]]; then
    step "Install the smoke application's dependencies" composer install --no-interaction --no-progress
fi

rm -rf "$app/var"
mkdir -p "$migrations"

export SMOKE_SCHEMA_STAGE=1

step "Create the database" console doctrine:database:create
[[ -f $database ]] || fail "doctrine:database:create did not create $database"

step "Generate the initial migration from the mapping" console doctrine:migrations:diff
[[ $(migration_count) == 1 ]] || fail "doctrine:migrations:diff did not write one migration"

step "Run the initial migration" console doctrine:migrations:migrate
step "Create, read, update and remove entities" console app:smoke:crud
step "Validate the mapping against the database" console doctrine:schema:validate
snapshot 1

export SMOKE_SCHEMA_STAGE=2

step "Generate the migration renaming users.email (rebuilds the referenced users table)" console doctrine:migrations:diff
[[ $(migration_count) == 2 ]] || fail "doctrine:migrations:diff did not write the users.email migration"

step "Run the users.email migration" console doctrine:migrations:migrate
step "Validate the mapping after renaming users.email" console doctrine:schema:validate
snapshot 2

export SMOKE_SCHEMA_STAGE=3

echo
echo "==> Check that generated SQL refuses to rebuild the self-referencing category table"
output=$(console doctrine:migrations:diff 2>&1)
status=$?
echo "$output"

if [[ $status == 0 ]]; then
    fail "doctrine:migrations:diff generated SQL for a self-referencing rebuild"
fi

grep -qF 'MartinJuul\DBAL\DuckDB\Migrations\RebuildTables::queue()' <<< "$output" \
    || fail "doctrine:migrations:diff failed without naming the RebuildTables helper"
[[ $(migration_count) == 2 ]] || fail "doctrine:migrations:diff wrote a migration although it failed"
echo "doctrine:migrations:diff refused and named the helper."

cp "$app/migrations/VersionRebuildCategoryTree.php" "$migrations/"

step "Run the category migration that uses RebuildTables::queue()" console doctrine:migrations:migrate
step "Validate the mapping after renaming category.name" console doctrine:schema:validate
snapshot 3

step "Drop the database" console doctrine:database:drop --force
[[ ! -e $database && ! -e $database.wal ]] || fail "doctrine:database:drop left $database or its write-ahead log behind"

echo
echo "Smoke run passed."
