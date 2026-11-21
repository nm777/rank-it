# Answer a question with verifiable passages

Status: resolved
Type: task
Blocked by: 03

Combine exact and meaning-based retrieval within the account. Send the question and selected passages to the answer model using Rank It funding. Validate citation targets and show original highlighted wording, surrounding context, author and date. Handle unsupported claims and partial evidence.

Connect recall to the shared input. Treat questions and hypothetical details as conversation context. Verify that uncertain intent can be clarified before anything is saved.

## Acceptance

A paraphrased question returns a supported answer with a working citation. Missing evidence is explicit. At the cap or during a model outage, available search results still open directly.

## Answer

The shared input answers questions, recognizes common capture wording, and asks before saving unclear input. Questions and hypothetical details can be used for the current request without creating a memory. Multi-turn conversation remains in ticket 08.

Recall combines account-scoped wording and compatible pgvector rankings. Query embedding and the configurable answer endpoint both reserve funds through the ticket 03 ledger. Answers display provider-selected verbatim excerpts verified against the retrieved sources. Each citation opens the highlighted wording in its original context. Partial answers can name the unsupported part of the question. Available results open directly when generation is unavailable.

Synthetic Laravel route tests exercised capture and clarification, queued mailbox import and indexing, paraphrased recall, invented or mismatched citations, cross-account isolation, partial evidence, provider outages and a zero cap on a dedicated PostgreSQL 17/pgvector database. HTTP fakes supplied all provider responses. Live model quality, authentication and provider billing were not verified. The ticket 03 live Jev check and ticket 05 tester trial remain outstanding.
