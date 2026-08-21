# `DB::makeId()` vs. PHP UUID solutions

This note compares `TiMMiT\MySQL\DB::makeId()` with common PHP UUID approaches
(`ramsey/uuid` v4 and v7), covering performance, entropy/collision resistance and
practical properties. It is meant to inform when to keep using `makeId()` and when a
UUID is the better fit.

## TL;DR

- `makeId()` (random mode) is cryptographically secure. Entropy is `n × log₂34` and
  depends on hash mode and default length: ~127 bits for `md5` (25 chars), ~158 bits
  for `sha1` (31 chars), ~163 bits for the default call (32 chars), ~254 bits for
  `sha256` (50 chars). All of those exceed a UUIDv4's 122 bits, so uniqueness and
  unguessability are more than enough.
- `makeId()` is somewhat slower than `ramsey/uuid` but the absolute cost is negligible
  (sub-2 µs per id; hundreds of thousands per second).
- `makeId()` has a feature UUIDv4/v7 lack: a **deterministic, seed-based mode** (comparable
  to UUIDv5), which the codebase relies on for idempotent upserts. That is not replaceable
  by a random UUID.
- UUID wins on **index locality** (UUIDv7 is time-ordered), **compact binary storage**
  (`BINARY(16)`) and **standardisation/interop** (RFC 9562).

## Benchmark

The output below is a separate historical run (PHP 8.4.18, ramsey/uuid 4.9.3),
not a capture of the current reproduction script. Numbers vary per machine/run;
treat them as orders of magnitude.

```text
N=200,000 | PHP 8.4.18 | ramsey/uuid 4.9.3

makeId sha1 (random)              301.5 ms    1.51 us/each  len=31  t0yputmbyn1he1bw42x8v65e2i3hkny
makeId seeded (sha1+suffix)       573.2 ms    2.87 us/each  len=32  99sezaknynksoowko4g0840ccso4wocX
ramsey UuidV4                     170.7 ms    0.85 us/each  len=36  f9d722db-e755-4d9b-956c-afb84adbda04
ramsey UuidV7                     270.9 ms    1.35 us/each  len=36  019efa16-1356-71bc-8508-642fe83d5b59

Uniqueness over 200,000:
  makeId sha1:   200,000 / 200,000 unique
  ramsey UuidV4: 200,000 / 200,000 unique
  ramsey UuidV7: 200,000 / 200,000 unique
```

| Method | time (200k) | per id | length |
|---|---|---|---|
| `makeId('sha1')` random | 301.5 ms | ~1.51 µs | 31 |
| `makeId(...)` seeded | 573.2 ms | ~2.87 µs* | 32 |
| ramsey UuidV4 | 170.7 ms | ~0.85 µs | 36 |
| ramsey UuidV7 | 270.9 ms | ~1.35 µs | 36 |

\* The seeded measurement also includes generating a fresh random seed per iteration, so it
overstates the pure `makeId` cost.

`ramsey/uuid` is the fastest because UuidV4 boils down to a single `random_bytes(16)`.
`makeId()` allocates a `Random\Randomizer` + `Random\Engine\Secure` per call and draws each
character from a 34-symbol alphabet. The difference (~1.8x) is irrelevant at these speeds.

## Properties

| Aspect | `makeId()` (random) | UUIDv4 | UUIDv7 |
|---|---|---|---|
| Entropy | `n × log₂34` at default length: ~**127** (`md5`, 25), ~**158** (`sha1`, 31), ~**163** (default, 32), ~**254** (`sha256`, 50) bits | **122 bits** | ~**74 bits** random + 48 bits time |
| 50% collision after (birthday bound) | ~2⁶⁴ (`md5`) to ~2¹²⁷ (`sha256`); `sha1` ~2⁷⁹ ≈ 6·10²³ | ~2⁶¹ ≈ 2·10¹⁸ | ~2³⁷ within one ms; effectively unique across time |
| CSPRNG | yes (`Engine\Secure` / `random_int`) | yes (`random_bytes`) | random part yes, timestamp predictable |
| Time-sortable | no | no | **yes** (index-friendly) |
| Format | base34, excludes `l`/`o` | hex + dashes | hex + dashes |
| Storage | string ~31 bytes | 16 bytes binary possible | 16 bytes binary possible |
| Standard / interop | custom | RFC 9562 | RFC 9562 |
| Deterministic variant | **yes** (seeded ≈ UUIDv5) | no | no |
| External dependency | none (built-in) | library | library |

## Where `makeId()` holds up well

- **Security / uniqueness:** CSPRNG entropy of ~127–254 bits at the supported default
  lengths (e.g. ~158 bits for `sha1` at 31 characters), comfortably above UUIDv4.
  Suitable for unguessable tokens. (UUIDv7 is a poor secret token: predictable timestamp +
  fewer random bits.)
- **Two modes in one function:** random (≈ UUIDv4) *and* deterministic seed-based
  (≈ UUIDv5/name-based). The codebase uses `DB::makeId('', 1, $seed)` for idempotent upserts; a plain
  random UUID cannot replace that.
- **No dependency:** works out of the box, no `ramsey/uuid` / `symfony/uid` required.

## Where UUID wins

- **DB insert / index locality:** UUIDv7 is time-ordered, giving better B-tree locality and
  less fragmentation than random keys. Both `makeId()` random and UUIDv4 are random and suffer
  the "random insert" problem on large/clustered indexes.
- **Compact storage:** a UUID fits in 16 bytes (`BINARY(16)` / native `uuid` column).
  `makeId()` is a 31-character string (~31 bytes) stored as text.
- **Standardisation / interop:** RFC UUIDs are recognised by databases, languages and tooling;
  `makeId()` is a bespoke format.

## Recommendation

- For **existing ids in this codebase**: keep `makeId()`. It is secure enough and its
  deterministic seeded mode is a feature UUID does not offer.
- For **new high-insert-rate tables** where you only need a random primary key and index
  locality matters: consider **UUIDv7** stored as `BINARY(16)`.
- Migrating to binary UUIDs only makes sense if compact storage / standardisation becomes a
  hard requirement — a larger, separate decision.

## Reproducing the benchmark

This script measures random `makeId()`, seeded `makeId()`, UUIDv4 and UUIDv7,
including uniqueness counts. It is not expected to recreate the historical
numbers above (`ramsey/uuid` is unpinned).

```bash
mkdir uuidbench && cd uuidbench
composer require ramsey/uuid
# place bench.php (below) next to vendor/ and adjust the path to DB.php
php bench.php
```

```php
<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';
require '/path/to/timmit/db-mysql/src/TiMMiT/MySQL/DB.php';

use TiMMiT\MySQL\DB;
use Ramsey\Uuid\Uuid;

$N = 200000;
$unique = [];
$bench = static function (string $label, callable $fn) use ($N, &$unique): void {
    $sample = (string) $fn();
    $t = microtime(true);
    for ($i = 0; $i < $N; $i++) {
        $fn();
    }
    $dur = (microtime(true) - $t) * 1000;
    printf("%-30s %8.1f ms  %6.2f us/each  len=%-2d  %s\n", $label, $dur, ($dur * 1000) / $N, strlen($sample), $sample);

    $seen = [];
    for ($i = 0; $i < $N; $i++) {
        $seen[(string) $fn()] = true;
    }
    $unique[$label] = count($seen);
};

$bench('makeId sha1 (random)', static fn() => DB::makeId('sha1'));
$bench('makeId seeded (sha1+suffix)', static fn() => DB::makeId('sha1', 32, bin2hex(random_bytes(16)), 'X'));
$bench('ramsey UuidV4', static fn() => Uuid::uuid4()->toString());
$bench('ramsey UuidV7', static fn() => Uuid::uuid7()->toString());

echo "\nUniqueness over {$N}:\n";
foreach ($unique as $label => $count) {
    printf("  %s: %s / %s unique\n", $label, number_format($count), number_format($N));
}
```
