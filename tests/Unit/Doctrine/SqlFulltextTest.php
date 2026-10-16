<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Unit\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Nowo\AuditKitBundle\Doctrine\SqlFulltext;
use PHPUnit\Framework\TestCase;

use function str_repeat;

final class SqlFulltextTest extends TestCase
{
    public function testMysqlUsesBooleanFulltextOnTheIndexedColumns(): void
    {
        $this->assertSame(
            ['MATCH(i.title, i.culprit) AGAINST(:q BOOLEAN) > 0', ['q' => '+fatal* +e2e* +xss*']],
            SqlFulltext::clause(['i.title', 'i.culprit'], 'Fatal e2e-xss', 'q', true),
        );
    }

    public function testLikeOnOtherPlatformsAndWhenNoWordIsUsable(): void
    {
        $this->assertSame(
            ["(c.body LIKE :q ESCAPE '\\')", ['q' => '%50\\%%']],
            SqlFulltext::clause(['c.body'], ' 50% ', 'q', false),
        );
        $this->assertSame(
            ["(i.title LIKE :t ESCAPE '\\' OR i.culprit LIKE :t ESCAPE '\\')", ['t' => '%ab%']],
            SqlFulltext::clause(['i.title', 'i.culprit'], 'ab', 't', true),
        );
    }

    public function testClausePassesTokenLengthAndStopwords(): void
    {
        $this->assertSame(
            ['MATCH(e.message) AGAINST(:m BOOLEAN) > 0', ['m' => '+ab*']],
            SqlFulltext::clause(['e.message'], 'ab the', 'm', true, 2, ['THE']),
        );
    }

    public function testPlatformDetectionFromConnectionOrPlatform(): void
    {
        $this->assertTrue(SqlFulltext::isMysql(true));
        $this->assertFalse(SqlFulltext::isMysql(false));
        $this->assertTrue(SqlFulltext::isMysql(new MySQLPlatform()));
        $this->assertTrue(SqlFulltext::isMysql(new MariaDBPlatform()));
        $this->assertFalse(SqlFulltext::isMysql(new SQLitePlatform()));
        $this->assertFalse(SqlFulltext::isMysql(new PostgreSQLPlatform()));

        $mysql = $this->createStub(Connection::class);
        $mysql->method('getDatabasePlatform')->willReturn(new MySQLPlatform());
        $sqlite = $this->createStub(Connection::class);
        $sqlite->method('getDatabasePlatform')->willReturn(new SQLitePlatform());

        $this->assertSame(['MATCH(i.title) AGAINST(:q BOOLEAN) > 0', ['q' => '+checkout*']], SqlFulltext::clause(['i.title'], 'checkout', 'q', $mysql));
        $this->assertSame(["(i.title LIKE :q ESCAPE '\\')", ['q' => '%checkout%']], SqlFulltext::clause(['i.title'], 'checkout', 'q', $sqlite));
        $this->assertSame(["(i.title LIKE :q ESCAPE '\\')", ['q' => '%checkout%']], SqlFulltext::clause(['i.title'], 'checkout', 'q', new SQLitePlatform()));
    }

    public function testBooleanQueryDropsShortWordsStopwordsAndDuplicates(): void
    {
        $this->assertSame('+error* +checkout*', SqlFulltext::booleanQuery('the Error in checkout ERROR'));
        $this->assertSame('', SqlFulltext::booleanQuery('a + - ()'));
        $this->assertSame('', SqlFulltext::booleanQuery(''));
        $this->assertSame('+café*', SqlFulltext::booleanQuery('"Café"'));
        $this->assertSame('+snake_case*', SqlFulltext::booleanQuery('snake_case'));
    }

    public function testBooleanQueryWithCustomTokenLengthAndStopwords(): void
    {
        $this->assertSame('+in* +checkout*', SqlFulltext::booleanQuery('in checkout', 2, []));
        $this->assertSame('+error*', SqlFulltext::booleanQuery('Error checkout', 3, ['Checkout']));
        $this->assertSame('+x* +the*', SqlFulltext::booleanQuery('x the a', 0, ['a']));
        $this->assertSame('', SqlFulltext::booleanQuery('a i', 1));
        $this->assertSame('+checkout*', SqlFulltext::booleanQuery('error checkout', 6));
    }

    public function testDefaultStopwordsAreInnodbs(): void
    {
        $this->assertSame('', SqlFulltext::booleanQuery('a about an i in www und com', 1));
        $this->assertSame('+ab* +cd*', SqlFulltext::booleanQuery('ab of cd', 1));
    }

    public function testSnippetCentresOnTheFirstMatchingWord(): void
    {
        $this->assertSame('short text', SqlFulltext::snippet("  short \n text ", 'zzz'));
        $long    = str_repeat('lorem ', 30) . 'needle here ' . str_repeat('ipsum ', 30);
        $snippet = SqlFulltext::snippet($long, 'missing needle', 40);
        $this->assertStringStartsWith('…', $snippet);
        $this->assertStringEndsWith('…', $snippet);
        $this->assertStringContainsString('needle', $snippet);
        $this->assertStringStartsWith('lorem', SqlFulltext::snippet($long, 'absent', 40));
        $this->assertStringStartsWith('lorem', SqlFulltext::snippet($long, '', 40));
        $this->assertStringEndsNotWith('…', SqlFulltext::snippet('alpha beta gamma delta epsilon', 'epsilon', 20));
        $this->assertSame('alpha beta…', SqlFulltext::snippet('alpha beta gamma', 'ALPHA', 11));
        $this->assertSame('a…', SqlFulltext::snippet('abc', 'zzz', 0));
    }
}
