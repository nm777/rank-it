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

## Screening and embedding

The worker queues direct captures for embedding and new authored mailbox passages for screening. Run `php artisan inference:resume` after migrating existing text, raising the budget or enabling a provider. Duplicate deliveries share the same durable work record.

Set `INFERENCE_CAP_NANOUSD` and `INFERENCE_ANSWER_RESERVE_NANOUSD` in billionths of a US dollar. The cap defaults to zero. Each worker reserves a full request allowance under a PostgreSQL row lock and settles against reported input tokens. Answers can reserve against the full cap through the same Budget service.

Supply `TYPESAFE_API_KEY` and run `php artisan inference:check-jev` before enabling screening. This sends a fixed synthetic locker example and accounts for the charge. Confirm usage against the provider account, then set `JEV_SAMPLE_VERIFIED=true`. The adapter pins Jev 1.14.0, uses a Noul probability threshold of 0.5 and reserves 65,536 input tokens at 42 billionths of a dollar per token. [API](https://docs.typesafe.ai/primitives/noul) · [Model limits](https://docs.typesafe.ai/models) · [Pricing](https://help.typesafe.ai/articles/5187202169-how-much-does-jev-cost)

For embedding, configure an authorized self-hosted or external embeddings-compatible endpoint. Set `EMBEDDING_ENDPOINT` to the full URL, `EMBEDDING_PROVIDER` to the index provider identifier, `EMBEDDING_MODEL` to a pinned model, and `EMBEDDING_DIMENSIONS` to its output size. Supply `EMBEDDING_API_KEY` if needed. Set `EMBEDDING_MAX_INPUT_TOKENS` to the endpoint's enforced maximum billable input per request and `EMBEDDING_NANOUSD_PER_INPUT_TOKEN` to its input-only rate (zero for unmetered hosting). The endpoint must return a vector in `data[0].embedding` and a token count in `usage.prompt_tokens`. Restart long-running workers after changing configuration.

An interrupted or ambiguous call keeps its reservation and blocks automatic replay. With workers stopped, inspect unsettled rows in `inference_calls` (actual is null), confirm the charge with the provider, and run `php artisan inference:reconcile CALL ACTUAL_NANOUSD`. Restart workers and use `inference:resume` to process outstanding work. A confirmed charge for a lost response still counts toward the cap.
