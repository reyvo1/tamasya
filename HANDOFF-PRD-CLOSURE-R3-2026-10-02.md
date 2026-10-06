# TAMASYA PRD Closure R3 — Macro Handoff

## Parent / evidence

- Existing repository: `reyvo1/testamasya`; no new repo and no new product lineage.
- Locked pre-PRD parent: `639d811cf6ad4c336b7814f815bb02a76861d6f9`.
- R1 operator commit: `a2889c5` — reported GREEN.
- R2 GitHub commit under test: `93311195790b17730dbee0199b5e66825e15dd4f`.
- R2 source gate: GREEN.
- R2 Full hotel finance/booking/UI/Telegram UAT: GREEN.
- R2 PRD deployment simulation: RED before database/runtime assertions because Docker failed while creating two API replicas against the same fresh `state` named volume.

Exact R2 failure:

`failed to mkdir /var/lib/docker/volumes/tamasya-prd-r2-runtime_state/_data/outbox: ... file exists`

The failure occurred while Compose created `api` and `api2` concurrently. The image had pre-created `/var/lib/tamasya/outbox` and `/var/lib/tamasya/backups`, and Docker automatic volume copy-up attempted to populate the same new volume from parallel containers. This is a deployment storage lifecycle defect, not a booking/finance/UAT business failure.

## R3 root fix

R3 does not hide the race with retries, sleeps, cleanup, or serial API startup. It changes the runtime storage contract:

1. `state` and `uploads` named volumes use `volume.nocopy=true`.
2. A dedicated one-shot `storage-init` service is the only owner of first-time runtime directory creation.
3. Every API/worker waits for `storage-init` with `condition: service_completed_successfully`.
4. SaaS web waits for storage initialization and both API processes.
5. The initializer rejects symlink/non-directory corruption instead of deleting or overwriting it.
6. PHP-FPM and worker remain non-root; only the one-shot initializer runs as root with the minimal filesystem capabilities required to create/chown the mounted directories.
7. Upload and private-state volume initialization are solved together so the same copy-up race cannot move from `state` to `uploads` after the first fix.

## Macro-wave evidence added before next push

The horizontal runtime GitHub smoke now proves, in one run:

- fresh parallel `api + api2 + web` startup against brand-new volumes;
- completed storage initializer with no Docker copy-up race;
- non-root API writable only in `state/uploads`, while application source remains read-only;
- state written by API1 is visible to API2;
- public upload written by API2 is visible through Nginx;
- 96 concurrent DB-backed routed API reads;
- canonical PHP worker runs non-root and shares initialized durable state;
- state and uploads survive runtime restarts without reinitialization race;
- one API replica can be stopped and Nginx passively quarantines it for subsequent reads;
- `fastcgi_next_upstream off` remains mandatory, so an uncertain financial/business request is never replayed to a second writer;
- private `deploy/` and `tests/` paths remain denied.

Existing realtime-process, HQ delivery TLS/MySQL and S3 SigV4 simulations remain in the same PRD deployment job after the horizontal runtime gate.

## Safety retained

- PHP remains canonical financial/business authority.
- No financial mutation was moved into Node/HQ/worker.
- No second writer was enabled.
- No UAT assertion was removed or softened.
- Full hotel UAT remains unchanged from the R2 green job.
- Physical device/provider/cloud multi-fault-domain commissioning is still not relabeled as CI proof.

## Next gate

Do not push small intermediate fixes. Overlay the R3 macro PATCH over exact R2 commit `93311195790b17730dbee0199b5e66825e15dd4f`, commit once, push the same existing branch, and run the full workflow. If RED, continue from the first new failure in that exact macro commit. If GREEN, freeze the exact GREEN commit as PRD SOFTWARE FINAL and generate the final source ZIP/SHA/As-Built handoff.
