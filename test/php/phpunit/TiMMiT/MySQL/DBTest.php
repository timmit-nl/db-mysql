<?php

declare(strict_types=1);

namespace test\php\phpunit\TiMMiT\MySQL;

use ReflectionClass;
use TiMMiT\MySQL\DB;
use test\php\phpunit\BaseTestCase;

/**
 * PHPUnit class voor DB::makeId().
 *
 * De tests dekken:
 * - De willekeurige (niet-geseede) tak: lengte, toegestaan alfabet, uniciteit en CSPRNG-gedrag.
 * - De geseede tak: determinisme (reproduceerbaarheid) en exacte lengte, inclusief de padding.
 * - De randgevallen rond $len === 0 (natuurlijke lengte i.p.v. een lege string).
 *
 * makeId() is statisch en heeft voor id-generatie geen databaseverbinding nodig, dus de
 * functie kan rechtstreeks worden aangeroepen.
 */
class DBTest extends BaseTestCase
{
    /** @var string Alfabet dat de willekeurige tak hoort te gebruiken (zonder l/o en hoofdletters). */
    private const EXPECTED_RANDOM_ALPHABET = 'abcdefghijkmnpqrstuvwxyz0123456789';

    /**
     * The affected-row API must remain public and use the write connection by default.
     */
    public function testGetAffectedRowsApiContract(): void
    {
        $reflection = new ReflectionClass(DB::class);
        $method = $reflection->getMethod('getAffectedRows');
        $parameters = $method->getParameters();

        $this->assertTrue($method->isPublic());
        $this->assertSame('int', (string)$method->getReturnType());
        $this->assertCount(1, $parameters);
        $this->assertSame('writeNode', $parameters[0]->getName());
        $this->assertTrue($parameters[0]->getDefaultValue());
    }

    /**
     * De private constante met het random-alfabet mag niet ongemerkt wijzigen.
     */
    public function testRandomAlphabetConstantValue(): void
    {
        $reflection = new ReflectionClass(DB::class);
        $alphabet = $reflection->getConstant('RANDOM_ID_ALPHABET');

        $this->assertSame(self::EXPECTED_RANDOM_ALPHABET, $alphabet, 'Het random-alfabet is gewijzigd; controleer de impact op id-generatie.');
        $this->assertStringNotContainsString('l', $alphabet, 'Het alfabet hoort de ambigue "l" niet te bevatten.');
        $this->assertStringNotContainsString('o', $alphabet, 'Het alfabet hoort de ambigue "o" niet te bevatten.');
    }

    /**
     * Een willekeurige id heeft exact de gevraagde (of per-hash standaard) lengte.
     *
     * @dataProvider randomLengthProvider
     */
    public function testRandomIdHasExpectedLength(string $hash, int $len, int $expectedLength): void
    {
        $id = DB::makeId($hash, $len);

        $this->assertSame($expectedLength, strlen($id), "makeId('$hash', $len) levert niet de verwachte lengte.");
    }

    /**
     * @return array<string, array{0: string, 1: int, 2: int}>
     */
    public static function randomLengthProvider(): array
    {
        return [
            'sha1 default len'   => ['sha1', 1, 31],
            'md5 default len'    => ['md5', 1, 25],
            'sha256 default len' => ['sha256', 1, 50],
            'onbekende hash valt terug op default 32' => ['', 1, 32],
            'len groter dan default' => ['sha1', 40, 40],
            'len veel groter dan default' => ['sha1', 64, 64],
            'len kleiner dan default wordt opgehoogd' => ['sha256', 10, 50],
        ];
    }

    /**
     * Een willekeurige id bevat uitsluitend tekens uit het toegestane alfabet.
     */
    public function testRandomIdUsesOnlyAllowedAlphabet(): void
    {
        for ($i = 0; $i < 1000; $i++) {
            $id = DB::makeId('sha1', 31);
            $this->assertSame(31, strlen($id));
            $this->assertSame(
                1,
                preg_match('/^[' . preg_quote(self::EXPECTED_RANDOM_ALPHABET, '/') . ']+$/', $id),
                "Willekeurige id bevat een teken buiten het toegestane alfabet: $id"
            );
        }
    }

    /**
     * Willekeurige ids zijn (praktisch) uniek over een grote set.
     */
    public function testRandomIdsAreUnique(): void
    {
        $amount = 20000;
        $ids = [];
        for ($i = 0; $i < $amount; $i++) {
            $ids[DB::makeId('sha1', 31)] = true;
        }

        $this->assertCount($amount, $ids, 'Er zijn dubbele willekeurige ids gegenereerd; controleer de entropiebron.');
    }

    /**
     * Twee opeenvolgende willekeurige ids met dezelfde parameters verschillen.
     */
    public function testRandomIdsAreNotDeterministic(): void
    {
        $this->assertNotSame(DB::makeId('sha1', 31), DB::makeId('sha1', 31), 'Willekeurige ids horen niet reproduceerbaar te zijn.');
    }

    /**
     * Regressietest punt 4: bij $len === 0 levert de willekeurige tak de natuurlijke
     * hashlengte op i.p.v. een lege string.
     *
     * @dataProvider lenZeroProvider
     */
    public function testRandomIdLenZeroReturnsNaturalLength(string $hash, int $expectedLength): void
    {
        $id = DB::makeId($hash, 0);

        $this->assertNotSame('', $id, "makeId('$hash', 0) hoort geen lege string te zijn.");
        $this->assertSame($expectedLength, strlen($id), "makeId('$hash', 0) hoort de standaardlengte te leveren.");
        $this->assertSame(
            1,
            preg_match('/^[' . preg_quote(self::EXPECTED_RANDOM_ALPHABET, '/') . ']+$/', $id),
            "makeId('$hash', 0) bevat een teken buiten het toegestane alfabet: $id"
        );
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function lenZeroProvider(): array
    {
        return [
            'sha1'   => ['sha1', 31],
            'md5'    => ['md5', 25],
            'sha256' => ['sha256', 50],
        ];
    }

    /**
     * Een geseede id is reproduceerbaar: dezelfde seed levert dezelfde id.
     *
     * @dataProvider seededProvider
     */
    public function testSeededIdIsDeterministic(string $hash, int $len, mixed $seed, string $add): void
    {
        $first = DB::makeId($hash, $len, $seed, $add);
        $second = DB::makeId($hash, $len, $seed, $add);

        $this->assertSame($first, $second, 'Een geseede id hoort reproduceerbaar te zijn.');
        $this->assertSame($len, strlen($first), 'Een geseede id hoort exact de gevraagde lengte te hebben.');
    }

    /**
     * @return array<string, array{0: string, 1: int, 2: mixed, 3: string}>
     */
    public static function seededProvider(): array
    {
        return [
            'string seed met suffix'  => ['sha1', 31, 'seed-abc', 'X'],
            'string seed zonder suffix' => ['sha1', 31, 'seed-abc', ''],
            'array seed met suffix'   => ['sha1', 32, ['werknemer', 'adres'], 'X'],
            'md5 seed'                => ['md5', 25, 'andere-seed', 'X'],
            'sha256 seed'             => ['sha256', 50, 'nog-een-seed', 'X'],
            'lengte forceert padding (suffix)' => ['sha1', 40, 'pad-mij', 'X'],
            'lengte forceert padding (geen suffix)' => ['sha1', 40, 'pad-mij', ''],
        ];
    }

    /**
     * Verschillende seeds leveren verschillende ids.
     */
    public function testSeededDifferentSeedsProduceDifferentIds(): void
    {
        $this->assertNotSame(
            DB::makeId('sha1', 31, 'seed-a', 'X'),
            DB::makeId('sha1', 31, 'seed-b', 'X'),
            'Verschillende seeds horen verschillende ids te geven.'
        );
    }

    /**
     * Een array-seed levert hetzelfde resultaat als de geserialiseerde variant ervan,
     * en is reproduceerbaar.
     */
    public function testSeededArraySeedMatchesSerializedSeed(): void
    {
        $arraySeed = ['werknemer' => 1, 'adres' => 2];
        $serialized = serialize($arraySeed);

        $this->assertSame(
            DB::makeId('sha1', 32, $arraySeed, 'X'),
            DB::makeId('sha1', 32, $serialized, 'X'),
            'Een array-seed hoort gelijk te zijn aan zijn geserialiseerde stringvorm.'
        );
    }

    /**
     * Over een grote reeks seeds is een geseede id altijd exact de gevraagde lengte,
     * reproduceerbaar en (zonder suffix) volledig base36. Dit dekt de padding-tak,
     * inclusief de seeds waarbij base_convert() korter uitvalt dan de doellengte.
     */
    public function testSeededIdsAreAlwaysExactLengthAndDeterministic(): void
    {
        for ($i = 0; $i < 2000; $i++) {
            $seed = 'seed-' . $i;
            $id = DB::makeId('sha1', 31, $seed, '');

            $this->assertSame(31, strlen($id), "Geseede id voor '$seed' heeft niet de juiste lengte.");
            $this->assertSame($id, DB::makeId('sha1', 31, $seed, ''), "Geseede id voor '$seed' is niet reproduceerbaar.");
            $this->assertSame(
                1,
                preg_match('/^[0-9a-z]+$/', $id),
                "Geseede id voor '$seed' (zonder suffix) bevat ongeldige tekens: $id"
            );
        }
    }

    /**
     * Wanneer de doellengte langer is dan de natuurlijke hashlengte en er een suffix is,
     * wordt deterministisch met dat suffix-teken bijgevuld (geen rand()).
     */
    public function testSeededPaddingWithSuffixAppendsAddCharacter(): void
    {
        $id = DB::makeId('sha1', 40, 'forceer-padding', 'X');

        $this->assertSame(40, strlen($id));
        $this->assertSame(
            1,
            preg_match('/^[0-9a-z]+X+$/', $id),
            "Verwacht een base36-voorvoegsel gevolgd door X-padding, kreeg: $id"
        );
        $this->assertSame($id, DB::makeId('sha1', 40, 'forceer-padding', 'X'), 'Suffix-padding hoort deterministisch te zijn.');
    }

    /**
     * Wanneer de doellengte langer is dan de natuurlijke hashlengte zonder suffix,
     * wordt deterministisch met base36-tekens uit de seed bijgevuld (geen rand(),
     * geen hoofdletters).
     */
    public function testSeededPaddingWithoutSuffixIsDeterministicBase36(): void
    {
        $id = DB::makeId('sha1', 40, 'forceer-padding', '');

        $this->assertSame(40, strlen($id));
        $this->assertSame(
            1,
            preg_match('/^[0-9a-z]+$/', $id),
            "Verwacht uitsluitend base36-tekens, kreeg: $id"
        );
        $this->assertSame($id, DB::makeId('sha1', 40, 'forceer-padding', ''), 'Padding zonder suffix hoort deterministisch te zijn.');
    }

    /**
     * Bij $len === 0 levert de geseede tak de natuurlijke base_convert-lengte en blijft
     * deterministisch.
     */
    public function testSeededLenZeroReturnsNaturalLength(): void
    {
        $id = DB::makeId('sha1', 0, 'seed-abc');

        $this->assertNotSame('', $id, 'Een geseede id met len 0 hoort niet leeg te zijn.');
        $this->assertLessThanOrEqual(31, strlen($id), 'De natuurlijke sha1-lengte is hooguit 31 tekens.');
        $this->assertSame($id, DB::makeId('sha1', 0, 'seed-abc'), 'Geseede len 0 hoort deterministisch te zijn.');
    }
}
