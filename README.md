# Rank It

## Run locally

The local stack uses Docker Compose, PostgreSQL 17 with pgvector, and one Laravel image for the web process and queue worker.

```sh
cp .env.example .env
docker compose build web
docker compose run --rm --no-deps web php artisan key:generate --show
```

Copy the printed value into `APP_KEY` in `.env`, then run:

```sh
docker compose run --rm web php artisan migrate
docker compose up -d
docker compose exec web php artisan pilot:invite tester@example.test
```

Open the printed link and create the account. Invitations expire after seven days, work once, and are replaced by issuing a new invitation for the same address. Use the same email and password in another browser to open the account's recent memories. The experience date accepts a year, month, or day, and can be left blank. The keyboard microphone enters editable text in the regular input.

The web and worker mount the same private `import-staging` volume. The worker uses PostgreSQL's `jobs` table. Capture commits directly to PostgreSQL and can be read while the worker is stopped. `Find wording` searches saved text in the signed-in account. Later tickets add imports, embeddings and cited answers.

For a native run, use PHP 8.2 or later with `pdo_pgsql` and Composer. Provide a PostgreSQL database with pgvector available to the migration role, configure `.env`, and run `composer install`, `php artisan key:generate` and `php artisan migrate`. Start `php artisan serve` and `php artisan queue:work database --timeout=60 --tries=3` from the same checkout. Set `IMPORT_STAGING_PATH` to the same writable private directory for both processes if they run from separate checkouts.

## Tests

The suite refreshes the separate `rank_it_test` database. With the local Compose stack running, create it once, then run the suite:

```sh
docker compose exec db createdb -U rank_it rank_it_test
docker compose exec -e APP_ENV=testing -e DB_DATABASE=rank_it_test \
  -e SESSION_DRIVER=array -e CACHE_STORE=array -e BCRYPT_ROUNDS=4 web php artisan test
```

For native tests, create `rank_it_test` in a PostgreSQL instance with pgvector and supply matching `DB_HOST`, `DB_PORT`, `DB_USERNAME` and `DB_PASSWORD` for `php artisan test`.
