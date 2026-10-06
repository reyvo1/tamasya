# Support modules

Files use numeric prefixes to make bootstrap order explicit. Dependencies must point from higher-level route modules toward support modules, never from support modules back into routes.

- `001_runtime_security.php`: runtime and error boundary.
- `002_enterprise_hardening.php`: request boundary, proxy/host/origin policy, password policy, account lockout, session idle timeout, security telemetry and endpoint rate limits.
- `010_*`: schema/runtime migration helpers.
- `020_*`: identity, access and audit.
- `030_*` onward: business-domain helpers.

Keep functions cohesive. A new domain should receive its own support module rather than extending a large unrelated file.
