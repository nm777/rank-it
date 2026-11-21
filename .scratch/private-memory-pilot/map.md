# Private memory pilot implementation plan

## Notes

The first milestone is one tester opening the passage that answers a question they chose before importing their mailbox. Capture that question before running the import. Use small fixtures while building and defer the other import formats until this path has been tried. The [pilot specification](spec.md) defines the full release scope.

### First milestone

1. [01 · Stand up private capture](issues/01-host-and-capture.md)
2. [02 · Import one mailbox into searchable passages](issues/02-mailbox-to-passages.md)
3. [03 · Screen and embed within budget](issues/03-budget-and-screening.md)
4. [04 · Answer a question with verifiable passages](issues/04-cited-recall.md)
5. [05 · Does screening save money while preserving useful evidence?](issues/05-mailbox-trial.md)

### Complete the pilot

6. [06 · Complete import coverage and collection controls](issues/06-import-lifecycle.md)
7. [07 · Extract attributable ratings and resolve identities](issues/07-ratings-and-identity.md)
8. [08 · Verify recall across changes and real decisions](issues/08-conversation-and-pilot.md)

## Decisions so far

Build on the [architecture decisions](../../docs/adr/). One Laravel release serves the browser and worker. PostgreSQL holds the private collection, queue and search data. A shared volume stages uploads. Rank It funds inference under one cap with an answer reserve. Jev screens new imported passages before embedding and rating extraction. Retained text supports exact search and makes missed evidence diagnosable.

Ticket 01 verified private capture across separate sessions with the worker stopped, account isolation in wording search and direct links, and a PostgreSQL queue job using shared staging. [Stand up private capture](issues/01-host-and-capture.md)

Ticket 02 verified queued MBOX import and literal passage search with a synthetic mailbox, including worker restart, repaired uploads, account isolation, and source links for identifiable quoted history. [Import one mailbox into searchable passages](issues/02-mailbox-to-passages.md)

Ticket 03 implemented queued screening and embedding with shared budget reservations and verified them on synthetic data. Live Jev access and billed usage still need a funded account. The installed configuration also lacks a live embedding endpoint. [Screen and embed within budget](issues/03-budget-and-screening.md)

Ticket 04 verified that a paraphrased question can open highlighted evidence from a synthetic mailbox through the shared input. Account isolation, partial answers, clarification and direct results under budget exhaustion or outages passed local route tests. Provider billing and real-world retrieval quality await live verification. [Answer a question with verifiable passages](issues/04-cited-recall.md)

## Open questions

[05 · Does screening save money while preserving useful evidence?](issues/05-mailbox-trial.md) is the first empirical decision. Use its results to keep or revise the screening criteria before expanding import coverage.
