# TAMASYA R4.1 Service Worker / SPA Rewrite Hotfix

## Root cause
Staging requested `database_verifity.php`, but the release file is `database_verify.php`. The missing typo path was rewritten by the SPA fallback to `index.html`; index then derived `/database_verifity.php/` as the PWA base and requested `/database_verifity.php/sw.js`, which returned HTML and caused the unsupported MIME type error.

## Permanent fix
- Missing file-like URLs now return HTTP 404 instead of being rewritten to SPA index.html.
- Service Worker base path uses URL semantics and supports both domain-root and subfolder deployments.
- Configurator-generated public `.htaccess` carries the same hardening.
- Correct verifier filename remains `database_verify.php`.

## Database
No schema/table/column/migration change. Do not import `database_setup.sql` into an existing database.
