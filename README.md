## MediaWiki Stakeholders Group - Components
# JWT for MediaWiki

Provides RS256 JWT token minting, a profile registry for extension-specific payloads,
and a JWKS (JSON Web Key Set) endpoint that exposes the corresponding public keys
for token verification.

Key generation and rotation are **not** handled by this component — they are the
responsibility of the external orchestrator (Kubernetes, Ansible, manual process, etc.).

**This code is meant to be executed within the MediaWiki application context. No standalone usage is intended.**

## Compatibility
- `1.0.x` -> MediaWiki 1.43
- `2.0.x` -> MediaWiki 1.47

## Use in a MediaWiki extension

Require this component in the `composer.json` of your extension:

```json
{
  "require": {
    "mwstake/mediawiki-component-jwt": "~1"
  }
}
```

## Configuration

| Global | Type | Default | Description |
|--------|------|---------|-------------|
| `$mwsgJWTPrivateKeyFile` | string\|null | `null` | Absolute path to the current PEM-encoded RSA private key file. **Required** for the component to function. Typically populated from an environment variable. |
| `$mwsgJWTPrivateKeyPreviousFile` | string\|null | `null` | Absolute path to the **previous** PEM private key file. Optional — used during key rotation overlap so tokens signed with the old key remain valid until they expire. |
| `$mwsgJWTDefaultTTL` | int | `3600` | Default token lifetime in seconds (used when no profile-specific TTL is set) |

## Services

| Service name | Class | Description |
|---|---|---|
| `MWStake.JWT.KeyManager` | `KeyManager` | Reads RSA key pair from configured file paths |
| `MWStake.JWT.TokenService` | `TokenService` | Mints JWT tokens and generates JWKS |
| `MWStake.JWT.ProfileRegistry` | `JwtProfileRegistry` | Registry of named payload providers |
| `MWStake.JWT.KeyStorage` | `FileSystemKeyStorage` | Reads key files from local filesystem paths |
| `MWStake.JWT.Payload.BasicUserInfo` | `BasicUserInfo` | Standard payload provider (username, email, groups, wiki_id) |

## Minting a token with custom claims

The simplest way to mint a JWT is to call `TokenService::mint()` directly with an
array of claims:

```php
use MediaWiki\MediaWikiServices;

$services = MediaWikiServices::getInstance();
$tokenService = $services->getService( 'MWStake.JWT.TokenService' );

$token = $tokenService->mint( [
    'sub' => $user->getName(),
    'email' => $user->getEmail(),
    'custom_field' => 'any_value',
], 120 ); // TTL in seconds (optional, falls back to $mwsgJWTDefaultTTL)
```

Registered claims (`sub`, `iss`, `aud`, `jti`, `nbf`) are automatically handled via
the dedicated lcobucci/jwt builder methods. Custom claims use `withClaim()`.

## Using profiles

Profiles allow extensions to define reusable payload configurations. A profile is a
named `IPayloadProvider` that builds claims for a given user and defines an optional TTL.

### Registering a profile

Register your profile early (e.g. via `ExtensionFunctions` in `extension.json`):

```json
{
  "ExtensionFunctions": [
    "\\MyExtension\\Setup::registerJwtProfile"
  ]
}
```

```php
namespace MyExtension;

use MediaWiki\MediaWikiServices;
use MWStake\MediaWiki\Component\JWT\Payload\BasicUserInfo;

class Setup {
    public static function registerJwtProfile(): void {
        $services = MediaWikiServices::getInstance();
        $registry = $services->getService( 'MWStake.JWT.ProfileRegistry' );

        // Use the built-in BasicUserInfo provider with a custom TTL
        $provider = new BasicUserInfo(
            $services->getUserGroupManager(),
            60 // TTL in seconds
        );

        $registry->register( 'my-extension-grafana', $provider );
    }
}
```

### Implementing a custom payload provider

For custom claim structures, implement `IPayloadProvider`:

```php
namespace MyExtension;

use MediaWiki\User\User;
use MWStake\MediaWiki\Component\JWT\IPayloadProvider;

class ChatPayloadProvider implements IPayloadProvider {

    public function getClaims( User $user ): array {
        return [
            'sub' => $user->getName(),
            'email' => $user->getEmail(),
            'display_name' => $user->getRealName() ?: $user->getName(),
            'aud' => 'rocketchat',
        ];
    }

    public function getTtl(): ?int {
        return 300; // 5 minutes, or null for global default
    }
}
```

### Minting via profile

```php
$tokenService = $services->getService( 'MWStake.JWT.TokenService' );
$token = $tokenService->mintForProfile( 'my-extension-grafana', $user );
```

This fetches the provider from the registry, builds claims, and mints a signed token.

## JWKS endpoint

The component automatically registers a `/.well-known/jwks.json` endpoint (via the
`mwstake/mediawiki-component-wellknown` component) that exposes the **public keys**
derived from all configured private keys. Configure your JWT consumer (e.g. Grafana)
to validate tokens against this URL.

The JWKS includes:
- The current key (always present when `$mwsgJWTPrivateKeyFile` is configured)
- The previous key (present when `$mwsgJWTPrivateKeyPreviousFile` is configured)

Each key entry includes a `kid` (Key ID) computed as the RFC 7638 JWK Thumbprint.
Tokens include the matching `kid` in their header so consumers can identify which
key to use for verification.

## Key lifecycle

Key generation and rotation are the responsibility of the **external orchestrator**
(Kubernetes, Ansible, CI/CD pipeline, manual process). This component only reads
private key files from the paths specified in configuration.

See `docs/adr/002-external-key-management.md` for the architectural decision.

## Wiki farm usage

In a wiki farm, all instances should point to the same key files (e.g. mounted from
the same Kubernetes Secret). This ensures all wikis share the same signing keys and
any wiki's `/.well-known/jwks.json` returns identical content.

See `docs/adr/001-shared-signing-keys-across-farm.md` for details.

## Architecture

```
TokenService
├── mint(claims[], ttl)          — low-level: sign claims into a JWT
├── mintForProfile(name, user)   — high-level: lookup profile, build claims, mint
└── getJWKS()                    — generate JSON Web Key Set from all active keys

JwtProfileRegistry
├── register(name, provider)     — store a named IPayloadProvider
├── get(name)                    — retrieve a provider
├── hasProfile(name)             — check existence
└── getProfileNames()            — list all registered profiles

KeyManager
├── getCurrentPrivateKey()       — read current PEM key (throws if missing)
├── getPreviousPrivateKey()      — read previous PEM key (null if missing)
├── getAllKeys()                  — array of all active PEM keys
└── isConfigured()               — check if current key file exists
```

## Kubernetes deployment

In Kubernetes, JWT private keys should be managed as **Secrets** and mounted into
the MediaWiki pods as files. The component reads them via `$mwsgJWTPrivateKeyFile`
and `$mwsgJWTPrivateKeyPreviousFile`, which are typically set from environment
variables.

### 1. Create the Secret

Generate an RSA key pair and store it as a Kubernetes Secret:

```bash
# Generate a 2048-bit RSA private key
openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:2048 \
  -out jwt-key-current.pem

# Create the Kubernetes Secret
kubectl create secret generic mediawiki-jwt-keys \
  --from-file=jwt-key-current.pem=jwt-key-current.pem \
  --namespace=bluespice

# Clean up local file
rm jwt-key-current.pem
```

### 2. Mount the Secret into the pod

```yaml
# deployment.yaml (relevant excerpts)
apiVersion: apps/v1
kind: Deployment
metadata:
  name: mediawiki
spec:
  template:
    spec:
      containers:
        - name: mediawiki
          env:
            - name: MW_JWT_PRIVATE_KEY_FILE
              value: /etc/mediawiki/jwt/jwt-key-current.pem
            - name: MW_JWT_PRIVATE_KEY_PREVIOUS_FILE
              value: /etc/mediawiki/jwt/jwt-key-previous.pem
          volumeMounts:
            - name: jwt-keys
              mountPath: /etc/mediawiki/jwt
              readOnly: true
      volumes:
        - name: jwt-keys
          secret:
            secretName: mediawiki-jwt-keys
            optional: false
```

### 3. Wire the environment variables in `LocalSettings.php`

```php
$mwsgJWTPrivateKeyFile = getenv( 'MW_JWT_PRIVATE_KEY_FILE' ) ?: null;
$mwsgJWTPrivateKeyPreviousFile = getenv( 'MW_JWT_PRIVATE_KEY_PREVIOUS_FILE' ) ?: null;
```

### 4. Key rotation procedure

Rotation is a two-phase process that ensures zero-downtime token validation:

**Phase 1 — Prepare the new key (keep old key active):**

```bash
# Generate new key
openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:2048 \
  -out jwt-key-new.pem

# Extract current key to become "previous"
kubectl get secret mediawiki-jwt-keys -n bluespice \
  -o jsonpath='{.data.jwt-key-current\.pem}' | base64 -d > jwt-key-previous.pem

# Update the secret with both keys
kubectl create secret generic mediawiki-jwt-keys \
  --from-file=jwt-key-current.pem=jwt-key-new.pem \
  --from-file=jwt-key-previous.pem=jwt-key-previous.pem \
  --namespace=bluespice \
  --dry-run=client -o yaml | kubectl apply -f -

# Clean up
rm jwt-key-new.pem jwt-key-previous.pem
```

**Phase 2 — Rolling restart:**

```bash
# Trigger a rolling restart so pods pick up the new secret volume
kubectl rollout restart deployment/mediawiki -n bluespice
kubectl rollout status deployment/mediawiki -n bluespice
```

After the restart, pods sign new tokens with the new key. The JWKS endpoint serves
both the new and previous public keys, so tokens signed with the old key remain
valid until they expire (controlled by `$mwsgJWTDefaultTTL` or per-profile TTL).

**Phase 3 — Remove previous key (optional, after token expiry window):**

Once all tokens signed with the previous key have expired (wait at least the
configured TTL), remove the previous key from the secret:

```bash
kubectl create secret generic mediawiki-jwt-keys \
  --from-file=jwt-key-current.pem=<(kubectl get secret mediawiki-jwt-keys \
    -n bluespice -o jsonpath='{.data.jwt-key-current\.pem}' | base64 -d) \
  --namespace=bluespice \
  --dry-run=client -o yaml | kubectl apply -f -
```

### Automating rotation with a CronJob

For fully automated rotation, create a Kubernetes CronJob:

```yaml
apiVersion: batch/v1
kind: CronJob
metadata:
  name: jwt-key-rotation
  namespace: bluespice
spec:
  schedule: "0 2 * * 0"  # Weekly on Sunday at 02:00
  jobTemplate:
    spec:
      template:
        spec:
          serviceAccountName: jwt-key-rotator
          containers:
            - name: rotate
              image: bitnami/kubectl:latest
              command:
                - /bin/sh
                - -c
                - |
                  set -e
                  # Generate new key
                  openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:2048 \
                    -out /tmp/jwt-key-new.pem

                  # Extract current → previous
                  kubectl get secret mediawiki-jwt-keys -n bluespice \
                    -o jsonpath='{.data.jwt-key-current\.pem}' \
                    | base64 -d > /tmp/jwt-key-previous.pem

                  # Update secret
                  kubectl create secret generic mediawiki-jwt-keys \
                    --from-file=jwt-key-current.pem=/tmp/jwt-key-new.pem \
                    --from-file=jwt-key-previous.pem=/tmp/jwt-key-previous.pem \
                    -n bluespice --dry-run=client -o yaml \
                    | kubectl apply -f -

                  # Rolling restart
                  kubectl rollout restart deployment/mediawiki -n bluespice
          restartPolicy: OnFailure
```

The `jwt-key-rotator` ServiceAccount needs RBAC permissions to read/update the
secret and restart the deployment:

```yaml
apiVersion: rbac.authorization.k8s.io/v1
kind: Role
metadata:
  name: jwt-key-rotator
  namespace: bluespice
rules:
  - apiGroups: [""]
    resources: ["secrets"]
    resourceNames: ["mediawiki-jwt-keys"]
    verbs: ["get", "update", "patch", "create"]
  - apiGroups: ["apps"]
    resources: ["deployments"]
    resourceNames: ["mediawiki"]
    verbs: ["get", "patch"]
---
apiVersion: rbac.authorization.k8s.io/v1
kind: RoleBinding
metadata:
  name: jwt-key-rotator
  namespace: bluespice
roleRef:
  apiGroup: rbac.authorization.k8s.io
  kind: Role
  name: jwt-key-rotator
subjects:
  - kind: ServiceAccount
    name: jwt-key-rotator
    namespace: bluespice
```
