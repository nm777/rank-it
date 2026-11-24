# Verify recall across changes and real decisions

Status: claimed
Type: task
Blocked by: 07

Complete current-conversation follow-ups, corrections, hypothetical details, dismissals, list coverage and evidence invalidation on edit or deletion. Run the full pilot with real imports and everyday captures across subjects.

## Acceptance

Verify each recall behavior against the spec, including partial answers, unresolved conflicts, ambiguous people and removed evidence. Testers return after time has passed and report whether the cited evidence helped their decision.

## Answer

Follow-ups use the current conversation’s questions and re-read retained passages. Clarifications and hypothetical details stay in the session. Dismissal suppresses a suggestion for that conversation. A new conversation clears those choices. Testers can save a fact, identify a person in one memory, or attach a separately dated correction to an imported passage. The correction survives deletion of the original message.

A visible answer checks its cited sources for changes. Edits preserve the earlier answer and take the citation to the corrected memory. Deletions hide the answer and exclude its turn from subsequent context. Ask again requests fresh evidence. Ordinary lists report bounded retrieval coverage and separate uncertain matches. Structured rating searches continue to report their own processing coverage.

## Comments

Synthetic feature tests exercised follow-ups, hypothetical context, isolated collections, scoped dismissal, explicit saves, clarification, conflicting accounts, partial coverage, uncertain dates and correction retention. A separate Chrome check in two tabs verified the visible edit notice, deletion of the entire answer and an explicit retry against the remaining collection. All verification used disposable PostgreSQL with pgvector and synthetic inputs. Model responses in the feature tests were scripted.

The ticket remains claimed pending the real pilot. Have each tester choose a question before import, open and assess its evidence, capture everyday memories across subjects, then return with a decision after time has passed. Record whether retrieval found the intended passage, whether the wording supported the answer and whether it helped the decision.
