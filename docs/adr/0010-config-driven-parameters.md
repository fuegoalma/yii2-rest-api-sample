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
application. `ImagickWebpEncoder` takes its three numbers as required
constructor arguments; `JwtService::$ttl` is an uninitialised typed property
with an `init()` guard (a `yii\base\Component` is configured by array, so it
cannot use a constructor); `RefreshTokenService::$ttl` is required.

A missing binding therefore fails loudly at construction instead of restoring a
magic number.

## Consequences

- `tests/unit/ConfigDrivenDefaultsTest.php` is the one place this is asserted,
  and a new config-driven parameter belongs in that test rather than in a fourth
  copy of the same assertion.
- That test proves only "no default exists". It would stay green if the
  parameter and the document drifted *together*, which is why
  `UploadParamsContractTest` separately holds `params.php` to the numbers the
  document publishes. The two are orthogonal and both are needed.
- Constructing these classes by hand — in a test, say — is more verbose. That is
  the cost, and it is paid where a mistake is visible immediately.
