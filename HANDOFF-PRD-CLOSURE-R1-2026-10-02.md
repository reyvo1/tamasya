# TAMASYA PRD Closure R1 — Handoff

## Parent authority

- Locked parent commit: `639d811cf6ad4c336b7814f815bb02a76861d6f9`
- Parent full UAT: 731/731 PASS
- Parent source ZIP SHA-256: `2a318841168770ee983e199ae4e04d71dbdec074e97d69e24d947f417028d346`
- PRD: TAMASYA Hybrid Modular Architecture v1.0, 15 September 2026

## Candidate

- Build ID: `20261002-prd-closure-r1`
- Purpose: close remaining deterministic PRD software gaps without changing PHP canonical financial authority.
- Do not merge the old experimental 0-PHP Node migration R1–R35 into this lineage.

## Changes

1. Shared-hosting shell performance:
   - optional route addons lazy-loaded by `assets/runtime-addon-loader.js`;
   - Service Worker install precache reduced for optional addons;
   - canonical report route tracking changed from 600 ms polling to SPA route events;
   - opportunistic maintenance does not run while the page is hidden.
2. PRD evidence:
   - architecture regression;
   - performance regression and eager-asset budget;
   - deployment-profile regression;
   - browser network UAT for actual lazy loading;
   - GitHub Docker/Compose simulation for VPS, worker, HQ and horizontal SaaS profiles.
3. Build/cache identity advanced to `20261002-prd-closure-r1` consistently across release contract, Service Worker registration/cache and compiled chunk URLs.

## Local evidence before GitHub

PASS:
- PHP regression 68/68
- JS regression 21/21
- Finance regression 9/9
- Hybrid regression 33/33
- Realtime regression 16/16
- Device contract 9/9
- Database TLS 4/4
- PRD architecture 5/5
- PRD performance 7/7
- PRD deployment 5/5
- PHP lint all files
- JS/MJS syntax all files
- Python compile + shell syntax

The complete MySQL/browser/two-node/HQ suite cannot be truthfully marked green locally because this environment does not provide the full GitHub service topology. Run the repository workflow and use the exact same-commit GitHub evidence as the final gate.

## Gate

Do not call this release FINAL until all GitHub jobs are green. If a job is red, fix the first real root cause without reducing assertion coverage or disabling financial/sync/security protections.
