# Does screening save money while preserving useful evidence?

Status: claimed
Type: prototype
Blocked by: 04

Before import, have a tester choose a real mailbox and write down a question they already want answered. Follow the export through opening the cited passage. On a small tester-approved sample, compare screened retrieval with unscreened retrieval using the same questions and embedding model.

Observe whether the tester can both save a memory and ask a question from the shared input without coaching. Check for hesitation, wrong routing, or unintended saves. If choosing the action explicitly would be clearer, try the two-action home screen before adding explanatory copy.

## Acceptance

Record whether the evidence was found, supported the answer and helped the tester act. Compare screening plus downstream inference costs, latency and missed passages. Trace misses to parsing, screening, retrieval or answer generation before choosing what to tune. Keep private examples with the tester unless shared.

## Synthetic trial

Application baseline `9e48176`. Run `bash .scratch/private-memory-pilot/trial/run.sh` from the repository with Docker, local images `rank-it-local:latest` and `pgvector/pgvector:pg17`, and Composer dependencies installed from the lockfile (including dev dependencies). The runner takes application code and fixtures from Git HEAD, uses a fresh PostgreSQL container and an internal Docker network with no published ports, and removes the database and network after the run. Laravel's HTTP fakes serve deterministic provider responses. The runner prints candidate evidence, citation outcomes, local timings, and the simulated ledger.

Both arms upload the same checked-in synthetic MBOX through the normal route and drain the PostgreSQL queue. In the unscreened arm, the harness changes each parser-eligible work item from `screen` to `embed` before the worker can screen it. Parser eligibility, quote handling, retrieval and generation code, questions, embedding model name and vectors, and answer fixture stay fixed. Each arm starts with an empty collection and a fresh ledger. The fake screen accepts Maya's original and deliberately rejects Sam's reply even with its quoted context. The fake answer returns the expected exact quote when retrieval supplies it.

The HTTP and database checks verified upload confirmation and cleanup, two imported messages, one known unreadable record, and one skipped attachment (`inventory.txt`). Both arms opened Maya's dated, highlighted original from “Where is the spare for the lock?” For “Did Sam concur?”, only the unscreened arm supplied Sam's “Agreed.” to the answer fixture and opened the highlighted reply with the quoted key statement in context. In the screened arm, the reply survived parsing and was explicitly skipped by the screen. A wording search for “Agreed.” still linked to the reply, so the missing semantic evidence was attributable to the screen decision, before answer generation. The malformed record stopped at parsing.

In both arms, the shared input routed both questions to recall, asked for clarification on “Locker B-17”, and left the collection empty until “I parked upstairs.” saved exactly one memory. This checked application routing and visible confirmation through Laravel HTTP requests. Hesitation, ease of use without coaching, and usefulness to a tester remain unobserved.

| Simulated ledger, USD billionths | Screened | Unscreened |
|---|---:|---:|
| Screening | 336 (2 calls) | 0 |
| Passage embeddings | 500 (1 call) | 1,000 (2 calls) |
| Question embeddings | 1,000 (2 calls) | 1,000 (2 calls) |
| Answer generation | 80 (1 call) | 160 (2 calls) |
| Total | 1,916 | 2,160 |

The fixture uses four screen tokens per call at the application's Jev ledger rate, five tokens per embedding at an assumed rate of 100 USD billionths per token, and fixed answer usage of 20 input and 10 output tokens at assumed rates of 2 and 4. Screening and passage embedding together saved 164 simulated USD billionths under these assumptions. The other 80 came from omitting generation on the missed question. The routing-only save happened after recall and its background embedding remained queued. There were no billed calls, live provider timings, or measurements of provider relevance.

One recorded run passed 2 cases and 98 assertions. Local upload-through-queue-drain took 450.22 ms screened and 93.64 ms unscreened. Shared-input requests took 53.78 and 9.80 ms in the screened arm and 34.70 and 26.46 ms in the unscreened arm, in question order. These are single-run timings from an in-process HTTP harness with fake providers, screened arm first. They include worker invocations and startup effects and cannot establish a live latency difference.

## Real tester trial outstanding

1. Before export, have the tester privately choose a real mailbox, record a question they already need answered, and approve a small comparison sample. Observe the tester obtaining the export, uploading it to their private collection, and opening the cited passage.
2. With appropriate authorization for provider use and spending, compare the sample in isolated fresh collections. Keep the questions, parser, embedding and answer models, retrieval settings, and budget conditions fixed. Embed every parser-eligible passage in the unscreened arm. Record screening, passage embedding, query embedding, and answer generation usage and charges separately. Time upload through search readiness and submission through answer separately.
3. For each question, record whether the intended source was parsed, retained, retrieved, cited, and opened. Have the tester judge whether the original wording supports the answer and whether it helped them act. Trace a miss to the first failing stage and check wording search for screened-out evidence. Keep private passages, questions, and examples with the tester unless they choose to share them.
4. Observe a save and a question from the shared input without coaching. Record hesitation, wrong routing, and unintended saves. If intent selection appears clearer, try the two-action variant before adding explanatory copy. Use the paired evidence and tester observations to make the screening and home-screen decisions.

## Comments

The synthetic portion is complete. The ticket remains claimed pending a real tester and an authorized live comparison.
