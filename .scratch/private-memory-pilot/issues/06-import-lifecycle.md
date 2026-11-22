# Complete import coverage and collection controls

Status: resolved
Type: task
Blocked by: none (05 synthetic handoff complete; real tester validation remains open on 05)

Add remaining specified email, mailbox, readable conversation, Word and text formats. Implement batch removal, message deletion and later re-import, explicit promotion from exact search, and targeted rechecks. Revisit reliably linked exchanges when late context arrives.

## Acceptance

Exercise overlapping batches, repaired exports, deleted originals and later re-import. Operators see aggregate recheck results and testers see their own passages. Demonstrate both embedding provider modes and rebuild an index from retained passages before switching queries.

## Answer

The upload form accepts up to four files per batch. Each file has its own checkpoint, outcome and staging cleanup. EML and MBOX preserve mail authorship and dates. DOCX, UTF-8 TXT (including readable conversations), and Markdown preserve readable wording. The batch page reports unreadable files and links to each file’s outcome.

The authenticated collection routes support removing a batch, deleting a message across batches and promoting a screened-out passage. Pending imports skip a message deleted during processing. A fresh upload can restore it. The operator can recheck one batch and see aggregate counts. The owner can open newly qualifying passages. A later parent triggers a budgeted recheck of reliably linked replies.

The feature suite exercised the upload, worker, exact search, promotion, deletion, re-import, recheck and query paths on disposable PostgreSQL with pgvector. The embedding test indexed two retained passages with a synthetic self-hosted endpoint. A budget interruption after one replacement vector kept queries on the old model. Retrying completed the external-profile index with a different dimension and switched queries. Repeating the command avoided duplicate embedding calls.

Provider responses and input files were synthetic. Real tester usefulness, live endpoint quality, latency and billing remain open on ticket 05. Rating extraction and attribution from linked exchanges belong to ticket 07.
