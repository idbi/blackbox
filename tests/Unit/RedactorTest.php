<?php

namespace Blackbox\Tests\Unit;

use Blackbox\Recorder\Redactor;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RedactorTest extends TestCase
{
    public function test_exact_keys_are_case_insensitive(): void
    {
        $redactor = new Redactor(['password']);

        $this->assertSame(
            ['Password' => 'pas****ord', 'name' => 'a'],
            $redactor->redact(['Password' => 'password', 'name' => 'a'])
        );
    }

    public function test_wildcards_match_prefixes(): void
    {
        $redactor = new Redactor(['card*']);

        $this->assertTrue($redactor->isSensitive('card_number'));
        $this->assertTrue($redactor->isSensitive('CardHolder'));
        $this->assertFalse($redactor->isSensitive('discard'));
    }

    public function test_nested_lists_are_walked(): void
    {
        $redactor = new Redactor(['token']);

        $this->assertSame(
            ['items' => [['token' => 'abc****xyz', 'id' => 1]]],
            $redactor->redact(['items' => [['token' => 'abcdefuvwxyz', 'id' => 1]]])
        );
    }

    /**
     * @dataProvider maskCases
     */
    #[DataProvider('maskCases')]
    public function test_mask_always_shows_a_part_and_hides_the_middle(string $value, string $expected): void
    {
        $this->assertSame($expected, Redactor::mask($value));
    }

    public static function maskCases(): array
    {
        return [
            'long' => ['password', 'pas****ord'],
            'card' => ['4111111111111111', '411****111'],
            'seven' => ['1234567', '123****567'],
            'six' => ['123456', '12****56'],
            'four' => ['1234', '1****4'],
            'three' => ['abc', 'a****c'],
            'two' => ['ab', 'a****'],
            'one' => ['a', '****'],
            'empty' => ['', '****'],
            'multibyte' => ['contraseña', 'con****eña'],
        ];
    }

    public function test_sensitive_subtrees_are_masked_leaf_by_leaf(): void
    {
        $redactor = new Redactor(['card']);

        $this->assertSame(
            ['card' => ['number' => '411****111', 'cvv' => '1****3', 'exp' => null, 'saved' => '****']],
            $redactor->redact(['card' => ['number' => '4111111111111111', 'cvv' => 123, 'exp' => null, 'saved' => true]])
        );
    }

    public function test_files_are_described_instead_of_stored(): void
    {
        $redactor = new Redactor(['secret_doc']);
        $file = UploadedFile::fake()->create('a.pdf', 1, 'application/pdf');

        $expected = ['name' => 'a.pdf', 'size' => 1024, 'mime' => 'application/pdf'];

        $this->assertSame(
            ['doc' => $expected, 'secret_doc' => $expected],
            $redactor->redact(['doc' => $file, 'secret_doc' => $file])
        );
    }

    public function test_unknown_objects_under_sensitive_keys_are_fully_masked(): void
    {
        $redactor = new Redactor(['token']);

        $this->assertSame(['token' => Redactor::MASK], $redactor->redact(['token' => new \stdClass]));
    }

    public function test_cached_verdicts_match_fresh_ones(): void
    {
        $redactor = new Redactor(['password', 'card*']);

        foreach ([1, 2] as $pass) {
            $this->assertTrue($redactor->isSensitive('Password'), "pass {$pass}");
            $this->assertTrue($redactor->isSensitive('card_number'), "pass {$pass}");
            $this->assertFalse($redactor->isSensitive('name'), "pass {$pass}");
            $this->assertFalse($redactor->isSensitive(0), "pass {$pass}");
        }
    }

    public function test_the_verdict_cache_is_capped(): void
    {
        $redactor = new Redactor(['secret*']);

        for ($i = 0; $i < 5000; $i++) {
            $redactor->isSensitive("field_{$i}");
        }

        $verdicts = (new \ReflectionProperty($redactor, 'verdicts'))->getValue($redactor);
        $this->assertCount(4096, $verdicts);
        $this->assertTrue($redactor->isSensitive('secret_9999'));
    }
}
