# Screen and embed within budget

Status: blocked (live Jev verification)
Type: task
Blocked by: 02

Verify Jev access and pricing with nonprivate samples first. Reserve estimated cost atomically before paid work, reconcile usage, and protect the answer reserve. Screen only new wording with thread context. Embed qualifying passages through a configurable provider and store the model configuration. Direct captures bypass screening.

## Acceptance

Skipped passages remain available by exact search. Replaying a finished job incurs no repeat processing. Background work pauses before consuming the answer reserve and resumes from retained text.

## Answer

Implemented the capture and MBOX queue pipeline, conservative per-call reservations, actual-usage reconciliation, and pgvector storage. Direct captures bypass screening. Only new authored, nonblank mailbox passages are screened, with surrounding wording and account-scoped parent context. Rejected passages retain their literal search links. The migration backfills work for retained text. The operator can resume pending work and reconcile an interrupted provider request before retrying. Configuration and commands are in the [README](../../../README.md).

TypeSafe identifies Jev as its System One model. The adapter uses its documented bearer-authenticated Noul API, pinned model, and published input-only price of $0.042 per million tokens. [API](https://docs.typesafe.ai/primitives/noul) · [Models](https://docs.typesafe.ai/models) · [Pricing](https://help.typesafe.ai/articles/5187202169-how-much-does-jev-cost)

The Laravel suite passed 11 tests and 145 assertions using synthetic records and a disposable PostgreSQL 17/pgvector database named rank_it_test. Local HTTP fakes exercised both provider contracts. Four concurrent processes competed for one shared allowance. Tests also covered account-scoped exact search, an edited capture with stale jobs, budget exhaustion and resumption, replay after completion, and an ambiguous provider failure with manual reconciliation.

Live verification remains blocked. The synthetic Jev check reported a missing TYPESAFE_API_KEY. Authentication, account billing and a live decision still require a funded key. No embedding endpoint or model was configured for a live vector or usage check. No private material was sent externally.
