# 10. A config-driven parameter has no default in code

**Status:** accepted

## Context

The photo encoder's bounding box and quality are published in
`config/openapi.yaml`: a caller is told uploads become WebP quality 80, scaled
to fit 500×500. They live in `config/params.php` and are injected in
`config/di.php`.

A constructor default (`int $quality = 80`) reads as harmless. It is not: if the
`di.php` wiring is ever removed or misspelled, the encoder keeps working with a
number that silently no longer comes from configuration — and the published
contract quietly becomes a lie.

## Decision

A parameter fed from `config/` declares **no default anywhere** in the
application.

| Where | Value | Source |
| --- | --- | --- |
| `ImagickWebpEncoder` — three required constructor arguments | bounding box, quality | `config/params.php` |
| `RefreshTokenService::$ttl` — required | refresh-token lifetime | `JWT_REFRESH_TTL` |
| `PasswordService::$ttl` — required | reset-link lifetime | `PASSWORD_RESET_TTL` |
| `EmailVerificationService::$ttl` — required | verification-link lifetime | `EMAIL_VERIFICATION_TTL` |
| `JwtService::$ttl` — uninitialised typed property plus an `init()` guard | access-token lifetime | `JWT_TTL` |

`JwtService` is the odd one because a `yii\base\Component` is configured by
array rather than through a constructor, so "no default" has to be expressed as
a property that has never been assigned and a guard that says so.

A missing binding therefore fails loudly at construction instead of restoring a
magic number.

## Consequences

- `tests/unit/ConfigDrivenDefaultsTest.php` is the one place this is asserted —
  one case per row of the table above — and a new config-driven parameter belongs
  in that test rather than in a sixth copy of the same assertion. Two of the rows
  went unpinned for a while, which is the failure mode to expect: the rule is
  easy to follow when writing the service and easy to forget when writing its
  test, because nothing about the service looks wrong.
- That test proves only "no default exists". It would stay green if the
  parameter and the document drifted *together*, which is why
  `UploadParamsContractTest` separately holds `params.php` to the numbers the
  document publishes. The two are orthogonal and both are needed.
- Constructing these classes by hand — in a test, say — is more verbose. That is
  the cost, and it is paid where a mistake is visible immediately.
