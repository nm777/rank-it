# Local Markdown issue tracker

Keep each feature in `.scratch/<feature-slug>/`. When publishing work, create the directory as needed.

- Save the spec as `spec.md`.
- Save each implementation ticket as `issues/NN-<slug>.md`, numbered from `01`.
- Record ticket state on a `Status:` line near the top. Append discussion under `## Comments`.
- Fetch a ticket by its supplied path or by its number within the feature.

## Wayfinder

Keep the effort map in `map.md` with sections for notes, decisions so far, and open questions. Give each question its own numbered ticket under `issues/`.

Record the question in the ticket body. Set `Type:` to `research`, `prototype`, `grilling`, or `task`. List dependencies by ticket number on a `Blocked by:` line.

Choose the lowest-numbered open, unclaimed ticket whose dependencies are all resolved. Save `Status: claimed` before starting work. When finished, append the answer under `## Answer`, set `Status: resolved`, and add a summary and ticket link to the map’s decisions.
