# Deploy Rank It as one application

Release the web process and background worker together from one codebase. The web process handles capture and recall, and the worker handles imports and indexing after the browser closes. Both share one database and can run on one machine to keep development costs low.

Build the application in Laravel, the maintainer’s usual framework, so the pilot uses familiar tooling for authentication and background jobs.

Serve a responsive web interface from the Laravel application. Testers can open an invitation on a phone, dictate a memory using the keyboard microphone, and ask questions in the browser. They can use the same account on a laptop to import a mailbox and review the results.
