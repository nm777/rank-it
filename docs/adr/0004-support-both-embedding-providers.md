# Support self-hosted and external embedding models

Rank It will support a self-hosted model and an external embedding API through the same interface for indexing passages and embedding questions. This lets us choose based on measured cost and retrieval quality as usage changes. Each index records its model and configuration. When switching to an incompatible model, rebuild the index from retained passages and switch queries to the matching model when the replacement index is ready.
