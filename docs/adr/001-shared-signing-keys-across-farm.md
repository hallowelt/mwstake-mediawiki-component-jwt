# ADR 001: Shared signing keys across wiki farm instances

## Status

Accepted. Amended by [ADR 002](002-external-key-management.md), which moved key
generation and rotation out of the component. The Decision, chosen alternative and
Consequences below describe the current design; Context and rejected alternatives are
kept as originally written.

## Date

2026-07-02

## Context

The JWT component operates within a BlueSpice wiki farm where multiple wiki instances
(e.g. "w", "en", "de") share a single Grafana installation for statistics
dashboards. Each wiki instance embeds Grafana panels in iframes, authenticating users
via short-lived JWTs. Grafana validates these tokens against a single configured JWKS URL.

The initial implementation used per-wiki subdirectories for key storage
(`jwt-keys/{wikiId}/jwt-key-current.pem`), providing cryptographic isolation between
wiki instances. However, since all instances authenticate against the **same** Grafana,
this caused tokens minted by non-root instances to fail validation — Grafana only
trusted the JWKS from whichever wiki's URL it was configured with.

## Decision

All wiki instances in the farm share a single key pair. Every instance points
`$mwsgJWTPrivateKeyFile` (and, during rotation, `$mwsgJWTPrivateKeyPreviousFile`) at the
same files, so all wikis sign with the same key and publish an identical JWKS.
Provisioning and rotating those files is the orchestrator's job (see
[ADR 002](002-external-key-management.md)).

## Alternatives Considered

### 1. Per-wiki keys with designated authority

One wiki (e.g. root) owns the keys. All other wikis are configured to read from
the authority's path. Only the authority rotates keys.

**Rejected because:**
- Requires per-wiki configuration (`$mwsgJWTKeyStoragePath` override on each instance)
- Single point of failure for rotation
- No real isolation benefit when all tokens go to the same Grafana

### 2. Per-wiki keys with aggregated JWKS endpoint

Each wiki keeps its own keys. A special endpoint on one wiki enumerates all
subdirectories and serves a combined JWKS containing all wikis' public keys.

**Rejected because:**
- More complex (directory enumeration, caching, auto-discovery)
- Per-wiki isolation is unnecessary when the single consumer (Grafana) must trust
  all wikis equally
- Adds latency to JWKS responses (reading multiple keys)
- `kid`-based key selection adds a failure mode if misconfigured

### 3. Shared keys (chosen)

All wikis read the same key files. Any wiki's `/.well-known/jwks.json` returns
identical content. The files are provisioned and rotated outside the wikis (e.g. from
a shared Kubernetes Secret or by configuration management), so no wiki generates or
writes keys and there is nothing to coordinate between instances.

## Consequences

- **Positive:** No per-wiki configuration — new wiki instances use the farm's shared
  key files and immediately work with Grafana JWT auth.
- **Positive:** Single JWKS URL to configure in Grafana (any wiki instance works).
- **Positive:** Simple mental model — one key pair for the entire farm.
- **Negative:** No cryptographic isolation between wiki instances. A token minted by
  "en" is valid if presented to a service expecting "bluespice" tokens. Mitigated by
  the `aud` claim in JWT profiles (e.g. `"aud": "statistics-grafana"`).
- **Negative:** Every instance must be given the same key files. An instance pointed at
  different files would publish a different JWKS, and Grafana (which trusts a single
  JWKS URL) would reject its tokens. Mitigated by mounting one shared Secret or
  distributing the files with configuration management (see ADR 002).

## Notes

If per-wiki isolation becomes necessary in the future (e.g. separate Grafana instances
per wiki, or multi-tenant SaaS), give each wiki its own `$mwsgJWTPrivateKeyFile` (and
previous-key file) and configure Grafana per instance. This needs no code change, as
the component has no wiki-specific key handling.
