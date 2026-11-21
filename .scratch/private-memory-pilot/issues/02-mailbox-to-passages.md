# Import one mailbox into searchable passages

Status: resolved
Type: task
Blocked by: 01

Start with the first tester’s mailbox export format. Explain searchability before upload, persist the file and queued job before acknowledging it, then parse message bodies into attributable passages with stable source locations. Deduplicate messages and quoted history across batches while preserving reply context. Skip attachments.

## Acceptance

Exact search finds a passage before paid processing. A worker restart, duplicate upload and partially unreadable mailbox produce accurate per-file outcomes without duplicate evidence. Temporary files are removed after the outcome is recorded.

## Answer

The authenticated Mailbox imports page accepts one MBOX per batch, up to 16 MiB. The web request stages the upload privately and commits the batch and database job together before confirming receipt. Each worker job commits one envelope's outcome, the byte offset, and the next job together. A retried cursor cannot add another membership. Completion records the outcome before deleting the staged file.

Literal wording search opens a stable, highlighted passage in its message with the sender, date, and surrounding text. Message identity is scoped to the account. Copies share passages across batches, while missing identity information keeps uncertain copies separate. Marked quotations retain their place in the reply. When the reply identifies its parent and the quoted wording matches a parent passage, search returns the original evidence and the reply links to it. Unresolved quotations are labeled as quoted history. MIME attachment contents are excluded and the import summary lists skipped filenames.

## Verification

- Exercised a real localhost HTTP session against disposable PostgreSQL 17 with pgvector. A synthetic invitee uploaded the fixture with the worker stopped, received the queued receipt, and closed the request. One worker processed the first envelope and exited. A second worker finished the batch. The status page reported two imported, one known unreadable, and the skipped attachment filename. Searching for B-17 opened Maya's highlighted passage. The staging directory had no remaining files.
- The Laravel suite passed 7 tests with 105 assertions on PHP 8.5. Coverage includes a stale job replay, repeat and repaired batches, a reply imported before its parent, HTML quoted text, uncertain identities, a wholly unreadable upload, attachment exclusion, cross-account search and direct-link isolation, and a second account importing the same mailbox. Mailbox tests launch separate Artisan database worker processes.
- Pint, strict Composer validation, and the staged diff whitespace check passed.

No tester export was supplied. Verification used synthetic MBOX fixtures. The first tester's export dialect, quotation conventions, and mailbox size remain to be checked in the mailbox trial.
