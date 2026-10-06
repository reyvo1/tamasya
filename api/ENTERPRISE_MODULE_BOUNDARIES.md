# API Module Boundaries

`api.php` is the composition root only. It may bootstrap runtime, validate the request boundary, resolve authentication, and dispatch routes. Do not add new business workflows directly to it.

- `api/routes`: HTTP action orchestration, request validation, authorization, response mapping.
- `api/support`: reusable domain and infrastructure behavior.
- `migrations`: additive and repeat-safe schema changes.
- `validation_tools`: deterministic regression and policy gates.

Rules for every mutation:

1. authorize by server-side role/permission;
2. validate normalized input and ownership/property scope;
3. use a transaction and lock rows when balances or stock can race;
4. use an operation/idempotency identifier for retryable requests;
5. post tax and journal through canonical services;
6. write required audit/security events;
7. return stable JSON error codes without stack traces or secrets.
