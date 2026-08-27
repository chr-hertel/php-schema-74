# mcp/schema-74

A PHP 7.4 port of the `Mcp\Schema` namespace of the [official PHP MCP SDK](https://github.com/modelcontextprotocol/php-sdk).

The SDK itself requires PHP 8.1. Its schema layer — the JSON-RPC envelopes, the
request and result shapes, the content types — is pure data modelling with no
runtime dependencies, so it ports cleanly to hosts stuck on 7.4. This package is
that layer and nothing else: no transport, no server, no registry.

```php
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Content\TextContent;

$request = CallToolRequest::fromArray(json_decode($body, true));
$result = new CallToolResult([new TextContent('done')]);

echo json_encode($result->withId($request->getId()));
```

## Installation

```
composer require mcp/schema-74
```

The package keeps the upstream `Mcp\\` namespace so ported code reads the same as
the code it came from, and therefore declares a conflict with `mcp/sdk`. On PHP
8.1 and up, use the SDK instead.

## What is generated, and from what

`src/` and `tests/` are generated — every file says so in its header. They come
from upstream `src/Schema`, the four exception classes the schema throws, and
`tests/Unit/Schema`, run through [Rector](https://getrector.com)'s downgrade
sets plus four rules written for this package. Change the upstream source and
re-run the port; do not edit `src/` by hand.

```
composer install -d tools/downgrade   # Rector and PHP-CS-Fixer, needs PHP 8.2+
bin/port                              # regenerate src/ and tests/
bin/test                              # run the ported suite on PHP 7.4 (Docker)
```

`bin/port` reads the SDK checkout next door; point `UPSTREAM` elsewhere to
override. `bin/lint` and `bin/test` run inside `php:7.4-cli`, because a
downgrade nobody ran on a real 7.4 is a downgrade nobody verified.

The port currently tracks upstream `e473c3c`. All 555 tests of the upstream
schema suite pass unchanged against the ported sources.

## How it differs from the 8.1 original

Most of the downgrade is invisible: promoted constructor properties become
ordinary ones, `readonly` and union types move into docblocks, `match` becomes
`switch`. The differences below are the ones you can see from calling code.

### Enums

PHP 7.4 has no enums. Each one becomes a final class extending
`Mcp\Schema\Enum\BackedEnum`, with a memoised instance per case:

| PHP 8.1 | PHP 7.4 |
| --- | --- |
| `Role::User` | `Role::User()` — the instance |
| — | `Role::User` — the backing string, for constant expressions |
| `$role->value`, `$role->name` | unchanged |
| `Role::from()`, `::tryFrom()`, `::cases()` | unchanged |
| `$a === $b` | unchanged; cases are singletons |
| `Role::from('nope')` throws `\ValueError` | throws `Mcp\Exception\InvalidArgumentException` |
| `$role instanceof \BackedEnum` | `instanceof Mcp\Schema\Enum\BackedEnum` |

A case keeps its original spelling in both forms, so `CacheScope::Public` is the
string and `CacheScope::Public()` the object. Watch for that when a value is
passed somewhere loosely typed: the constant form is the one that compiles in a
default value or another constant, and the object form is the one everything
else expects.

Enums serialise to their backing value through `JsonSerializable`, so encoding a
schema object produces byte-identical JSON either way.

### Signatures

A parameter that defaulted to an enum case is now nullable with a `null`
default, and resolves the case on entry — a constant expression cannot hold an
object. `ElicitRequest::__construct()`, `ElicitResult::fromArray()` and
`ToolChoice::__construct()` are the three affected.

`HasMethodInterface::fromArray()` declares no return type. Implementations
returned `static`, which erases to nothing, and PHP 7.4 cannot widen a return
type back.

### Constants

`MessageInterface::PROTOCOL_VERSION` is the version string rather than a
`ProtocolVersion` instance. Its counterparts on the enum —
`ProtocolVersion::FIRST_MODERN_VERSION` and `::DEFAULT_HEADER_VERSION` — follow
the same rule as cases: the constant is the string, the accessor gives the
instance.

### Extensions

`ExtensionInterface::getRequestHandlers()` is documented as `iterable<object>`.
Upstream types it against the SDK's request handler interface, which lives
outside the schema and is not part of this package.

## Licence

Apache-2.0, the same as the SDK it is generated from.
