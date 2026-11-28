<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Unit\Change;

use DateTimeImmutable;
use InvalidArgumentException;
use Nowo\AuditKitBundle\Change\ChangeSet;
use Nowo\AuditKitBundle\Change\ChangeSetBuilder;
use Nowo\AuditKitBundle\Change\UnsafeTraceableFieldException;
use Nowo\AuditKitBundle\Tests\Support\SampleAction;
use Nowo\AuditKitBundle\Tests\Support\SampleUnitEnum;
use PHPUnit\Framework\TestCase;
use stdClass;
use Stringable;

use function array_keys;
use function json_encode;
use function str_repeat;
use function strlen;

use const INF;
use const JSON_THROW_ON_ERROR;
use const NAN;

final class ChangeSetBuilderTest extends TestCase
{
    public function testPlainFieldsStoreFromAndToAndSkipUnchangedOnes(): void
    {
        $set = ChangeSet::between(['title' => 'Old', 'status' => 'open'], ['title' => 'New', 'status' => 'open'], ['title' => ChangeSet::PLAIN, 'status' => ChangeSet::PLAIN]);

        $this->assertSame(['title' => ['from' => 'Old', 'to' => 'New']], $set->toArray());
        $this->assertSame($set->toArray(), $set->jsonSerialize());
        $this->assertCount(1, $set);
        $this->assertFalse($set->isEmpty());
        $this->assertFalse($set->isTruncated());
    }

    public function testNothingAllowlistedChangedGivesAnEmptySet(): void
    {
        $set = ChangeSet::between(['title' => 'A', 'other' => 1], ['title' => 'A', 'other' => 2], ['title' => ChangeSet::PLAIN]);

        $this->assertTrue($set->isEmpty());
        $this->assertSame([], $set->toArray());
        $this->assertTrue((new ChangeSet())->isEmpty());
    }

    public function testMissingKeysCompareAsNull(): void
    {
        $this->assertSame(
            ['added' => ['from' => null, 'to' => 'x'], 'removed' => ['from' => 'y', 'to' => null]],
            ChangeSet::between(['removed' => 'y'], ['added' => 'x'], ['added' => ChangeSet::PLAIN, 'removed' => ChangeSet::PLAIN])->toArray(),
        );
    }

    public function testWithoutPolicyEveryFieldIsComparedAndSecretLookingNamesAreMasked(): void
    {
        $set = ChangeSet::between(
            ['name' => 'a', 'password' => 'hunter2', 'apiToken' => 't1', 'Smtp_DSN' => 'smtp://u:p@h'],
            ['name' => 'b', 'password' => 'hunter3', 'apiToken' => 't2', 'Smtp_DSN' => 'smtp://u:q@h', 'added' => 'n'],
        );

        $this->assertSame([
            'name'     => ['from' => 'a', 'to' => 'b'],
            'password' => ['changed' => true],
            'apiToken' => ['changed' => true],
            'Smtp_DSN' => ['changed' => true],
            'added'    => ['from' => null, 'to' => 'n'],
        ], $set->toArray());
        $this->assertStringNotContainsString('hunter', (string) json_encode($set));
    }

    public function testSecretPolicyStoresOnlyChanged(): void
    {
        $this->assertSame(['pin' => ['changed' => true]], ChangeSet::between(['pin' => '1234'], ['pin' => '9999'], ['pin' => ChangeSet::SECRET])->toArray());
    }

    public function testPlainPolicyOnASecretLookingNameThrowsWithoutTheValue(): void
    {
        try {
            ChangeSet::between(['client_secret' => 's3cr3t-old'], ['client_secret' => 's3cr3t-new'], ['client_secret' => ChangeSet::PLAIN]);
            $this->fail('Expected UnsafeTraceableFieldException');
        } catch (UnsafeTraceableFieldException $e) {
            $this->assertStringContainsString('"client_secret"', $e->getMessage());
            $this->assertStringNotContainsString('s3cr3t', $e->getMessage());
        }
    }

    public function testPublicPolicyAllowsANameThatOnlyLooksLikeASecret(): void
    {
        $this->assertSame(
            ['issue_key' => ['from' => 'A-1', 'to' => 'A-2']],
            ChangeSet::between(['issue_key' => 'A-1'], ['issue_key' => 'A-2'], ['issue_key' => ChangeSet::PUBLIC])->toArray(),
        );
    }

    public function testUnknownPolicyThrows(): void
    {
        $this->expectException(UnsafeTraceableFieldException::class);
        $this->expectExceptionMessage('Unknown change policy "raw" for the field "title"');

        ChangeSet::between([], [], ['title' => 'raw']);
    }

    public function testConfiguredSecretFieldsWinOverEveryPolicy(): void
    {
        $builder = new ChangeSetBuilder(['IBAN']);

        $this->assertSame(
            ['iban' => ['changed' => true], 'Iban' => ['changed' => true]],
            $builder->between(['iban' => 'ES1', 'Iban' => 'ES3'], ['iban' => 'ES2', 'Iban' => 'ES4'], ['iban' => ChangeSet::PUBLIC, 'Iban' => ChangeSet::LONG_TEXT])->toArray(),
        );
        $this->assertSame(['iban' => ['changed' => true]], $builder->between(['iban' => 'ES1'], ['iban' => 'ES2'])->toArray());
        $this->assertTrue($builder->isSecret('Iban'));
        $this->assertTrue($builder->isSecret('db_password'));
        $this->assertFalse($builder->isSecret('title'));
    }

    public function testLongTextAndLongStringsStoreLengthsOnly(): void
    {
        $long = str_repeat('é', 501);
        $set  = ChangeSet::between(
            ['body' => 'short', 'note' => 'a', 'count' => 1],
            ['body' => 'longer', 'note' => $long, 'count' => $long],
            ['body' => ChangeSet::LONG_TEXT, 'note' => ChangeSet::PLAIN, 'count' => ChangeSet::PLAIN],
        );

        $this->assertSame([
            'body'  => ['changed' => true, 'from_len' => 5, 'to_len' => 6],
            'note'  => ['changed' => true, 'from_len' => 1, 'to_len' => 501],
            'count' => ['changed' => true, 'from_len' => null, 'to_len' => 501],
        ], $set->toArray());
    }

    public function testValuesArePresented(): void
    {
        $stringable = new class implements Stringable {
            public function __toString(): string
            {
                return 'label';
            }
        };

        $set = ChangeSet::between(
            ['due' => null, 'state' => null, 'kind' => null, 'owner' => null, 'blob' => null, 'meta' => null],
            [
                'due'   => new DateTimeImmutable('2026-01-02 03:04:05.678 UTC'),
                'state' => SampleAction::Viewed,
                'kind'  => SampleUnitEnum::Draft,
                'owner' => $stringable,
                'blob'  => new stdClass(),
                'meta'  => ['label' => 'Bob', 'id' => 7, 'nested' => ['apiKey' => 'k', 'ok' => true]],
            ],
        );

        $this->assertSame([
            'due'   => ['from' => null, 'to' => '2026-01-02T03:04:05.678+00:00'],
            'state' => ['from' => null, 'to' => SampleAction::Viewed->value],
            'kind'  => ['from' => null, 'to' => 'Draft'],
            'owner' => ['from' => null, 'to' => 'label'],
            'blob'  => ['from' => null, 'to' => '[stdClass]'],
            'meta'  => ['from' => null, 'to' => ['label' => 'Bob', 'id' => 7, 'nested' => ['apiKey' => ChangeSet::MASK, 'ok' => true]]],
        ], $set->toArray());
    }

    public function testASetOverTheSizeLimitIsCutFieldByField(): void
    {
        $builder = new ChangeSetBuilder(maxStringLength: 10000, maxBytes: 400);
        $before  = [];
        $after   = [];
        for ($i = 0; $i < 40; ++$i) {
            $before['f' . $i] = 'x';
            $after['f' . $i]  = str_repeat('y', $i === 0 ? 180 : 40);
        }

        $set   = $builder->between($before, $after);
        $array = $set->toArray();

        $this->assertTrue($set->isTruncated());
        $this->assertSame(['f0', 'f1', 'f2', 'f3', ChangeSet::TRUNCATED_KEY], array_keys($array));
        $this->assertSame(['from' => 'x', 'to' => str_repeat('y', 40)], $array['f2']);
        $this->assertSame(['changed' => true, 'truncated' => true], $array['f3']);
        $this->assertTrue($array[ChangeSet::TRUNCATED_KEY]);
        $this->assertLessThanOrEqual(400, strlen((string) json_encode($set)));
    }

    public function testAFirstFieldOverTheLimitIsCutToo(): void
    {
        $set = (new ChangeSetBuilder(maxStringLength: 10000, maxBytes: 64))->between(['a' => ''], ['a' => str_repeat('z', 200)]);

        $this->assertSame(['a' => ['changed' => true, 'truncated' => true]], $set->toArray());
        $this->assertTrue($set->isTruncated());
    }

    public function testValuesThatJsonCannotHoldAreMadeStorable(): void
    {
        $set = ChangeSet::between(
            ['legacy' => 'ok', 'ratio' => 1.5, 'max' => 0.0, 'min' => 0.0, 'meta' => []],
            ['legacy' => "caf\xE9", 'ratio' => NAN, 'max' => INF, 'min' => -INF, 'meta' => ["k\xFF" => "v\xFE"], "bad\xC3" => 'x'],
        );

        $array = $set->toArray();
        $this->assertSame(['from' => 'ok', 'to' => 'caf?'], $array['legacy']);
        $this->assertSame(['from' => 1.5, 'to' => 'NaN'], $array['ratio']);
        $this->assertSame(['from' => 0.0, 'to' => 'INF'], $array['max']);
        $this->assertSame(['from' => 0.0, 'to' => '-INF'], $array['min']);
        $this->assertSame(['from' => [], 'to' => ['k?' => 'v?']], $array['meta']);
        $this->assertSame(['from' => null, 'to' => 'x'], $array['bad?']);
        $this->assertJson(json_encode($set, JSON_THROW_ON_ERROR));
    }

    public function testLongTextOnASecretNeverStoresLengths(): void
    {
        $set = (new ChangeSetBuilder(['recovery_note']))->between(
            ['api_key' => 'a', 'recovery_note' => 'b', 'body' => 'c'],
            ['api_key' => 'aa', 'recovery_note' => 'bb', 'body' => 'cc'],
            ['api_key' => ChangeSet::LONG_TEXT, 'recovery_note' => ChangeSet::LONG_TEXT, 'body' => ChangeSet::LONG_TEXT],
        );

        $this->assertSame([
            'api_key'       => ['changed' => true],
            'recovery_note' => ['changed' => true],
            'body'          => ['changed' => true, 'from_len' => 1, 'to_len' => 2],
        ], $set->toArray());
    }

    public function testTheDefaultPatternCoversCommonSecretNames(): void
    {
        $builder = new ChangeSetBuilder();
        foreach (['passphrase', 'db_pwd', 'Authorization', 'auth_header', 'cookie', 'session_id', 'bearer', 'otp_code', 'salt', 'signature', 'card_cvv'] as $name) {
            $this->assertTrue($builder->isSecret($name), $name);
        }

        $set = $builder->between(['headers' => []], ['headers' => ['Authorization' => 'Bearer x', 'Cookie' => 'sid=1', 'Accept' => 'json']]);
        $this->assertSame(['from' => [], 'to' => ['Authorization' => ChangeSet::MASK, 'Cookie' => ChangeSet::MASK, 'Accept' => 'json']], $set->toArray()['headers']);
    }

    public function testInvalidPatternIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('is not a valid regular expression');

        new ChangeSetBuilder(secretPattern: '/(/');
    }

    public function testInvalidLimitsAreRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ChangeSetBuilder(maxBytes: 10);
    }
}
