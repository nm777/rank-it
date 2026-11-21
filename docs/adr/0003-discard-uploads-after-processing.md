# Discard uploaded files after processing

Uploaded files are temporary inputs to bulk import. Once processing finishes and the outcome is recorded, discard the file while retaining successfully imported material and its batch membership. This limits retention of private exports and means a tester must upload the original again to reprocess it.

For the single-host pilot, stage uploads on a persistent disk volume shared by the web process and import worker. This keeps pending files available across application restarts and avoids operating a separate storage service. Revisit the storage choice if those processes move to separate hosts.
