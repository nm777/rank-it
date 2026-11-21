# Rank It private memory pilot

Help a person capture memories about any subject and answer questions using their own private collection. Subcontractor decisions and flavor preferences are examples. The two main actions are **Remember this** and **Ask my memory**.

## Interface principle

Use the fewest visible elements a person needs for the task at hand. Give each button, heading, and line of copy a concrete purpose. Surface secondary actions where they become relevant.

Prototype one shared input for capture and recall. A clear memory can be saved directly and a clear question answered directly. If the intended action is unclear and the choice would save something to the collection, ask a brief clarification before saving. Test this against the simpler-to-explain two-action alternative, “Record a memory” and “Ask a question,” before settling the home screen.

## Remember this

For the pilot, the person can type or use their keyboard’s microphone in the same text field. Rank It receives editable text from either input.

Save typed or dictated wording even when a detail is missing or a name is ambiguous. Ratings are optional. Defer structured flags for the pilot. Keep preferences such as “I’d hire Lee again for trim” in the person’s own words and preserve those qualifications when using the memory in a recommendation. Record the capture date and preserve the experience date at the precision the person supplied, including when it is unknown.

Show “Saved” as soon as the database commits the memory. The person can open the original wording immediately. A background worker generates the embedding, so recall by meaning can lag briefly behind capture.

Automatically record an explicitly stated score with the dated memory, preserving what was rated and the scale the person supplied. Show the score alongside the original wording so the person can correct it. “John’s work is a 2 out of 5” rates his work. “Lemon cake is a nine” records 9 with an unknown scale. Questions with a scale-specific threshold use scores on that scale.

For a current shortlist, use the most recent rating by experience date for the same subject and aspect, then apply the requested threshold. Importing an older rating later preserves its place in the history. For an “ever rated” question, consider every dated rating and cite the experience that met the threshold.

Keep the date a person expressed a rating distinct from the experience date and the import date. Resolve relative wording against the date of the message containing it. In a March email, “today” refers to the day the email was written and “last month” refers to February at month precision. If the email gives a score without saying when the work occurred, use March for questions about when the rating was given and leave the experience date unknown for questions about when the work occurred.

In an imported exchange, a person can adopt another author’s exact score by agreeing with it. If Maya writes “Lemon cake is 9/10” and the tester replies “Agreed,” count 9/10 for lemon cake in the tester’s personal rating searches. Preserve Maya’s original rating and cite both her message and the tester’s reply as evidence for the adopted score.

When Maya rates lemon cake 9/10 and carrot cake 4/10 in the same message, an unqualified “Agreed” adopts both scores. “Agreed on the lemon cake” adopts only the lemon cake score. “One of those sounds right” leaves both scores unattributed to the tester unless they clarify which one they meant.

Let the person correct a transcription in place. Record a real change, such as moving a spare key, as a new dated memory so questions about either period can still be answered.

## Bulk import

Accept batches of exported email messages and mailboxes, readable text conversations, Word documents, and plain-text or Markdown notes. Explain before import that the contents will become searchable in the person’s private collection. One upload authorizes the whole batch. Report which files imported and which need another format.

For email and mailbox imports, process message bodies and skip attachment contents. The import summary identifies skipped attachments by filename when available so the tester knows what the search covers. A tester can upload a supported attachment separately as a document.

Confirm when the upload is complete and the person can close the tab. Process the batch in the background and keep each file’s result available when the person returns.

If some messages in a mailbox cannot be read, import the readable messages and mark the file “Partially imported.” Report how many messages imported and how many could not be read when those counts are known. If the tester uploads a repaired export later, use the existing matching rules to import recovered messages without reindexing copies already processed.

Keep imported passages available for exact-text search. Identify previously processed messages and repeated quoted history before spending inference on new wording. Screen each new passage with a fast model, supplying enough surrounding context to interpret replies such as “Agreed.” Candidate signals include opinions, performance, quality, reliability, rankings, ratings, and concrete facts or changes worth recalling. Start with Jev as the screening provider for its expected low cost and fast responses. Keep the provider replaceable and evaluate screening cost, speed, and missed useful passages on pilot imports as the criteria are refined.

Only passages that pass the screen proceed to embedding and rating extraction. Screened-out passages remain searchable by their wording and can be opened in context. This screen applies to bulk imports; saving a memory through Remember this already expresses the tester’s intent.

When an exact-text result opens a screened-out passage, the tester can choose “Remember this” to override the screen and queue that passage for embedding under the existing budget. Opening the result alone leaves its indexing status unchanged. Once indexed, the passage can surface in meaning-based search, with citations pointing to the original imported source and preserving its authorship.

Apply revised screening criteria to new imports immediately. To apply revised criteria to previously screened-out passages, recheck them only when the operator selects an import batch and requests a recheck. Use the current criteria and the existing background inference budget. Show the operator aggregate counts of passages rechecked and newly qualifying. Let the tester open the newly qualifying passages in their private collection and choose whether to share a specific example with the operator. Queue newly qualifying passages for the usual embedding and rating extraction.

If embedding a batch would exceed the remaining inference budget, save the imported text for exact-text search and queue the embedding work. Show “Text search is ready. Meaning-based search is pending.” Resume indexing from the saved text when budget is available, using the original batch authorization.

Extract ratings from imported conversations in the background and reuse the results for later searches. Link each rating to its supporting passages, including both messages when a reply adopts someone else’s score. If extraction is incomplete, report the ratings found so far and tell the tester that the search covers only the processed portion of the import.

When a later import supplies new context that can be reliably linked to an earlier reply, automatically queue the affected exchange for rechecking under the background inference budget. For example, if an earlier “Agreed” can now be linked to Maya’s original 9/10 rating, attribute the adopted score to the reply author and cite both messages.

Use one Rank It–funded inference spending cap for the pilot, with a configurable amount reserved for answering questions. Queue background screening, indexing, and rating extraction if their cost would draw on the remaining answer reserve, and resume when budget is available. Answers can use the reserve up to the overall cap. Once the cap is reached, show matching passages directly.

Preserve original wording, authorship, dates, and surrounding context where available. Ask which identities belong to the person when the answer depends on it. A shared address can have uncertain authorship. A correction to an imported claim can guide a present-day decision while the original passage remains available for questions about what its author said.

Remember confirmed personal email addresses on the tester’s account across imports. Ask about an unfamiliar address only when authorship would change the answer. For a shared address, ask about the specific message and apply the tester’s confirmation only to that message, including matching copies in other import batches.

Save the person’s correction as a distinct dated memory linked to the exact imported passage it corrects. If the person deletes the original message, keep the correction available for recall. Future answers can cite the person’s correction and must omit the deleted passage from evidence and citations.

Discard each uploaded file when processing finishes, including files reported as unsuccessful. Successfully imported material remains searchable. Reprocessing the original file requires a new upload.

Recognize the same message across batches and quoted replies. Repetition adds no independent support. Removing a batch preserves a message retained by another batch. Deleting the message itself removes it from recall across all existing batches. The person can add it again by importing material that contains it in a later batch. The usual batch authorization and matching rules apply to that import.

Within a tester’s collection, match imported email copies by their original Message-ID when available. If the ID is missing, merge copies only when the sender, date, and full message text are all available and agree. Keep uncertain matches separate. When a reply quotes an identifiable earlier message, link the quoted passage to that message as evidence and preserve the reply author’s own words separately.

## Ask my memory

For recommendations, retrieve the most relevant supporting passages. For a list or count, claim completeness only when Rank It has checked the full set of memories that could meet the stated conditions. If coverage is uncertain, report what was found, such as “I found four confirmed hires in your memories.” Show uncertain matches separately and exclude them from the confirmed count.

If a tester edits a memory cited by an answer already on screen, mark that answer “Supporting memory edited” and have the affected citation open the corrected memory. Keep the earlier answer visible and offer “Ask again” so the tester can choose when to generate a fresh answer.

If the tester deletes a memory cited by a visible answer, immediately hide the entire answer and show “A supporting memory was deleted” with an “Ask again” action. Exclude the hidden answer and deleted passage from follow-up context. Generate a replacement from the remaining collection only when the tester asks.

If search succeeds but the answer model is unavailable or Rank It has reached its inference budget, show the matching passages directly. Label them as search results, explain briefly that answer generation is unavailable, and include dates and authors when known. Each result opens its passage in context.

Answer ordinary questions from the person’s memories alone. Link each factual claim to its supporting dated passage. Combine memories when useful and identify inferences. Answer the supported part of a question and make any gap explicit. For example, a parking memory may establish the level and space while leaving the meter expiration unknown.

Opening a citation takes the person directly to the exact supporting passage, highlighted within the saved memory or imported text. Show the surrounding wording and include the author and date when known. Present imported material in a readable text layout; reproducing the source file’s formatting is outside the pilot scope.

Use context to resolve people when it suffices. Ask a focused follow-up when choosing between plausible matches would change the answer. Use dated changes to answer questions about the relevant period. Surface conflicting accounts when the timing or evidence cannot resolve them.

Use a clarification such as “I mean John from Acme” for the current conversation. If the tester identifies a person in a specific memory, offer to save that link, or save it directly when explicitly asked. Future questions can use the saved link for that memory. Other mentions of the same name remain unresolved unless their own context identifies the person.

When a question asks when an experience occurred, use the experience date to determine which matches fall within the requested period. Show otherwise relevant matches separately when the experience date is unknown or too imprecise to settle the question. Explain the uncertainty and exclude those matches from the confirmed count. Use the capture date when the question asks when a memory was saved.

Dismissing a suggestion leaves its supporting memories intact. Use hypothetical details within the current conversation. Offer to save a new fact or correction, and save it directly when explicitly asked. Future answers use only memories still in the collection.

Keep context for follow-up questions in the current Ask my memory conversation. Starting a new conversation clears that conversational context. The pilot does not provide a history of chats to reopen. Memories explicitly saved during a conversation remain in the person’s collection.

## Pilot evaluation

Have each tester select a real collection they already want to search and identify a question before importing it. Test the path from obtaining the export to opening the passage that answers the question. Also have testers capture everyday memories across subjects and return with questions that arise after time has passed.

For each question, check whether Rank It found the right evidence, whether the original wording supports the answer, and whether the answer helped the person act. Include partial answers, ambiguous names, changed facts, and questions the collection cannot answer. Image capture and live information lookup are deferred.
