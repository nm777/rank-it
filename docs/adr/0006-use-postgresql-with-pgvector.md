# Store memories and search embeddings in PostgreSQL

The web process and background worker share one PostgreSQL database for memories, supporting passages, import progress, and embeddings. Use pgvector for similarity search so the pilot can run with one data store and deleting a memory can remove its passages and embeddings in the same transaction.

Ask my memory combines literal text matches and meaning-based matches from the tester’s collection. Literal matching can find an identifier such as `B-17` as soon as the memory is saved. Once its embedding is ready, the same memory can also surface for a question phrased differently. A passage found by both methods counts once as supporting evidence.
