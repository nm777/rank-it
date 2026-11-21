# Import one mailbox into searchable passages

Status: open
Type: task
Blocked by: 01

Start with the first tester’s mailbox export format. Explain searchability before upload, persist the file and queued job before acknowledging it, then parse message bodies into attributable passages with stable source locations. Deduplicate messages and quoted history across batches while preserving reply context. Skip attachments.

## Acceptance

Exact search finds a passage before paid processing. A worker restart, duplicate upload and partially unreadable mailbox produce accurate per-file outcomes without duplicate evidence. Temporary files are removed after the outcome is recorded.
