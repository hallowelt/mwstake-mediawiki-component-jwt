# ADR 001: Shared signing keys across wiki farm instances

## Status

Accepted

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

All wiki instances in the farm share a single key pair stored at the base
`$mwsgJWTKeyStoragePath` (default: `jwt-keys/`) without per-wiki subdirectories.

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

All wikis read/write the same key files. Any wiki's `/.well-known/jwks.json` returns
identical content. First wiki to run `update.php` generates the initial key; others
detect it already exists. Key rotation is idempotent — whichever wiki's cron fires
first rotates, others see the key is already fresh.

## Consequences

- **Positive:** Zero configuration for new wiki instances — they immediately work
  with Grafana JWT auth.
- **Positive:** Single JWKS URL to configure in Grafana (any wiki instance works).
- **Positive:** Simple mental model — one key pair for the entire farm.
- **Negative:** No cryptographic isolation between wiki instances. A token minted by
  "en" is valid if presented to a service expecting "bluespice" tokens. Mitigated by
  the `aud` claim in JWT profiles (e.g. `"aud": "statistics-grafana"`).
- **Negative:** Theoretical race condition if two wikis' cron jobs attempt rotation at
  the exact same millisecond. Mitigated by FileBackend's atomic operations and the age
  check in `RotateKeys` (only rotates if key is older than `$mwsgJWTRotationInterval`).

## Notes

If per-wiki isolation becomes necessary in the future (e.g. separate Grafana instances
per wiki, or multi-tenant SaaS), re-introduce the `WikiMap::getCurrentWikiId()` suffix
in `ServiceWiring.php` and configure Grafana per-instance.
