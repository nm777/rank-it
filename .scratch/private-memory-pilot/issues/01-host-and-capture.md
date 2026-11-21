# Stand up private capture

Status: resolved
Type: task
Blocked by: None

Build the responsive Laravel web process and worker with invite-only local accounts, PostgreSQL, pgvector, a database queue, and a shared staging volume. Scope reads, jobs and citations to the owning account. Save typed or keyboard-dictated memories with original wording and date precision.

Start with one editable input that can later serve recall as well as capture. Apply the interface principle in the pilot specification throughout the capture flow.

## Acceptance

An invited tester saves a memory on a phone and immediately opens it from a laptop. A second tester cannot retrieve it by search or direct link. Worker downtime does not prevent capture.

## Answer

The Laravel web process saves original wording directly to PostgreSQL and shows “Saved” on the resulting memory page. Capture dates are recorded at creation. The optional experience date accepts a year, month, or day, and can remain unknown. The home screen has one editable input with explicit capture and wording-search actions. The signed-in account scopes the recent list, search, detail links, and corrections.

Operators issue email-bound, single-use invitations that expire after seven days. PostgreSQL has pgvector and the database queue tables. The web and worker run from the same image and mount one private staging volume.

## Verification

- Ran the feature suite on native PHP 8.5 and Compose PHP 8.4 against PostgreSQL 17 with pgvector. Both runs passed 5 tests with 60 assertions. Pint and strict Composer validation passed.
- With the Compose worker stopped, a synthetic mobile HTTP client redeemed an invitation and saved a memory. A separate desktop session signed into the same account and opened the original immediately. A second invited account got an empty wording search and a 404 on the direct link. The app also rejected a save without a CSRF token.
- The web container staged a synthetic file and queued a PostgreSQL job. A separate worker processed it and the web process read the output from the shared volume. The queue reported zero pending and zero failed jobs.
- Docker supplied PostgreSQL and pgvector because the host had PHP, Composer, and the PHP PostgreSQL driver but no native PostgreSQL CLI on PATH. An early Compose test run inherited the app database and refreshed disposable fixtures. The suite now requires the testing environment and dedicated database before refreshing. Verified that an unconfigured run leaves two fixture accounts and their memory intact.

## Remaining dependencies

Ticket 02 adds account-scoped import jobs and source passages. Ticket 03 adds embedding jobs, including direct capture indexing. Ticket 04 adds shared-input recall, intent clarification, and citation validation. The local acceptance run used separate HTTP sessions with synthetic fixtures. Physical phone keyboard dictation and the tester’s phone-to-laptop workflow remain for the pilot.
