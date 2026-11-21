#!/usr/bin/env bash
set -euo pipefail

trial_dir=$(cd "$(dirname "$0")" && pwd)
repo=$(git -C "$trial_dir" rev-parse --show-toplevel)
snapshot=$(mktemp -d)
name="rank-it-mailbox-trial-$(basename "$snapshot" | tr '[:upper:].' '[:lower:]-')"
cleanup() {
    docker rm -f -v "$name-db" >/dev/null 2>&1 || true
    docker network rm "$name" >/dev/null 2>&1 || true
    rm -rf "$snapshot"
}
trap cleanup EXIT

git -C "$repo" archive HEAD app bootstrap config database resources routes storage tests \
    artisan composer.json composer.lock phpunit.xml | tar -x -C "$snapshot"
cp "$trial_dir/MailboxTrial.php" "$snapshot/MailboxTrial.php"
ln -s "$repo/vendor" "$snapshot/vendor"

docker network create --internal "$name" >/dev/null
docker run --pull never -d --name "$name-db" --network "$name" \
    -e POSTGRES_DB=rank_it_test -e POSTGRES_USER=trial -e POSTGRES_PASSWORD=fixture \
    pgvector/pgvector:pg17 >/dev/null

for attempt in {1..30}; do
    if docker exec "$name-db" pg_isready -h 127.0.0.1 -U trial -d rank_it_test >/dev/null 2>&1; then
        break
    fi
    if [[ "$attempt" == 30 ]]; then
        docker logs "$name-db" >&2
        exit 1
    fi
    sleep 1
done

COPYFILE_DISABLE=1 tar -chf - -C "$snapshot" . | docker run --pull never --rm -i --network "$name" \
    -e DB_HOST="$name-db" -e DB_PORT=5432 -e DB_USERNAME=trial -e DB_PASSWORD=fixture \
    rank-it-local sh -ec 'mkdir /tmp/trial; cd /tmp/trial; tar --warning=no-unknown-keyword -xf -; php vendor/bin/phpunit MailboxTrial.php'
