# ADR 002: External key management (no built-in rotation)

## Status

Accepted

## Date

2026-08-12

## Context

The JWT component previously managed the full key lifecycle internally:

- Generated an initial RSA key pair on `maintenance/update.php`
- Rotated keys automatically via a WikiCron job (daily at 02:00)
- Stored keys via `mwstake/mediawiki-component-filestorageutilities` (supporting
  local filesystem or AWS S3)

This approach had several drawbacks:

1. **Unclear responsibility boundary.** The component both minted tokens and managed
   cryptographic key material. In containerised deployments (Kubernetes), key
   lifecycle is a platform concern handled by secrets management, not by application
   code.

2. **Unnecessary dependencies.** The `filestorageutilities`, `wikicron`, and
   `processmanager` packages were required solely for key storage and rotation —
   adding complexity and coupling for functionality that most deployments delegate
   to infrastructure.

3. **Difficult to audit.** Application code generating and writing private keys
   raises security review concerns. Externalising this to the orchestrator means
   keys are managed by dedicated tooling with proper access controls and audit logs.

4. **S3 not needed in practice.** The S3 backend existed to share keys across
   multiple servers. In Kubernetes, this is solved natively by mounting the same
   Secret into all pods. In bare-metal multi-server setups, configuration management
   (Ansible, Chef) distributes key files. Neither scenario requires S3.

## Decision

Remove all key generation, rotation, and write-path logic from the component. The
component now:

- **Reads** private keys from file paths specified in `$mwsgJWTPrivateKeyFile` and
  `$mwsgJWTPrivateKeyPreviousFile`
- **Mints** RS256 JWT tokens using the current private key
- **Publishes** a JWKS endpoint with public keys derived from all configured private keys
- **Does not** generate, rotate, or write key files

Dependencies removed: `filestorageutilities`, `wikicron`, `processmanager`.

Classes removed: `FileBackendKeyStorage`, `Process/RotateKeys`, `Maintenance/GenerateInitialKey`.

## Consequences

- **Positive:** Significantly simpler component with a clear single responsibility
  (token minting + JWKS publishing).
- **Positive:** Fewer dependencies — smaller attack surface and faster installs.
- **Positive:** Key management follows infrastructure best practices (Kubernetes
  Secrets, Vault, Ansible vaults) rather than reinventing them in PHP.
- **Positive:** Easier to audit — no private key generation in application code.
- **Negative:** Operators must provision the initial key manually (one `openssl`
  command) before the component can function. This is documented in the README.
- **Negative:** Existing deployments using the internal key management must migrate
  their keys to file paths and configure the new globals. A migration note should
  be included in the release changelog.

## Migration from previous versions

1. Locate existing keys (default: `cache/wiki_data/jwt-keys/jwt-key-current.pem`)
2. Copy them to a stable path (e.g. `/etc/mediawiki/jwt/`)
3. Set `$mwsgJWTPrivateKeyFile` and optionally `$mwsgJWTPrivateKeyPreviousFile`
4. Remove any `$mwsgJWTKeyStoragePath` or `$mwsgJWTKeyRotationCron` configuration
