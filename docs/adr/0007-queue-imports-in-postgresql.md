# Queue background imports in PostgreSQL

Use PostgreSQL as the pilot’s import queue to keep operations within the existing database. Confirm the upload only after the files are durably stored and the queued job is committed. The worker records each file’s outcome so a restarted worker can reclaim interrupted work, skip completed files, and retry an unfinished file without duplicating imported material.
