# Feature Specification: Full-text search helper (FULLTEXT with LIKE fallback)

**Feature Branch**: `005-sql-fulltext`  
**Created**: 2026-10-16  
**Status**: Implemented (v1.7.0)

**Package**: `nowo-tech/audit-kit-bundle`  
**Configuration root**: `nowo_audit_kit` (no new keys)  
**Baseline**: [`../001-baseline/spec.md`](../001-baseline/spec.md) · [`../002-doctrine-helpers/spec.md`](../002-doctrine-helpers/spec.md)  
**Origin**: Symfony Beacon spec 151 (global header search) — `App\Issues\Repository\Query\PaletteTextMatch`, used by the issue, comment and event repositories, is generic DQL and moves next to `SqlLike`.

---

## Summary

`Nowo\AuditKitBundle\Doctrine\SqlFulltext` (final, static, not a service) builds a DQL text condition:

| Platform / query | Condition | Parameter |
| ---------------- | --------- | --------- |
| MySQL / MariaDB, at least one usable word | `MATCH(f1, f2) AGAINST(:p BOOLEAN) > 0` | `+word1* +word2*` |
| Other platforms, or no usable word | `(f1 LIKE :p ESCAPE '\' OR f2 LIKE :p ESCAPE '\')` | `SqlLike::contains(trim($query))` |

plus `snippet()` to show the matched part of a long text in results. The `MATCH` DQL function is provided by the host (e.g. `beberlei/doctrineextensions` `MatchAgainst`); the bundle has no dependency on it.

---

## User Scenarios

### US-01 — FULLTEXT on MySQL (P1)

**Given** fields `i.title`, `i.culprit` (one FULLTEXT index) and the query `Fatal e2e-xss` on MySQL,  
**Then** `clause()` returns `MATCH(i.title, i.culprit) AGAINST(:q BOOLEAN) > 0` with `q = '+fatal* +e2e* +xss*'`.

### US-02 — LIKE elsewhere (P1)

**Given** SQLite (or `false`), **Then** `clause(['c.body'], ' 50% ', 'q', …)` returns `(c.body LIKE :q ESCAPE '\')` with `q = '%50\%%'`.

### US-03 — No usable word (P1)

**Given** MySQL and the query `ab` (shorter than the minimum token length) or only stopwords / punctuation,  
**Then** the `LIKE` form is used (a required unindexed word would match nothing).

### US-04 — Platform detection (P2)

**Given** a DBAL `Connection` or `AbstractPlatform` instead of a boolean, **Then** MySQL and MariaDB platforms use FULLTEXT, others `LIKE`.

### US-05 — Server settings (P3)

**Given** a server with another `innodb_ft_min_token_size` or stopword table, **Then** `$minTokenLength` / `$stopwords` change the filtering.

### US-06 — Snippet (P2)

**Given** a long text and a query, **Then** `snippet()` returns about `$width` characters around the first literal case-insensitive match of a query word with `…` at the cuts, or the start of the text when nothing matches literally; short texts are returned whole (whitespace collapsed).

---

## Requirements

- **FR-FT-001**: `clause(array $fields, string $query, string $param, bool|Connection|AbstractPlatform $mysql, int $minTokenLength = 3, ?array $stopwords = null): array` returns `[string $dql, array<string, string> $parameters]` as in the summary table. `$fields` and `$param` are DQL fragments inserted as is (never user input).
- **FR-FT-002**: `isMysql(bool|Connection|AbstractPlatform): bool` — the boolean itself, or `getDatabasePlatform()` / the platform `instanceof AbstractMySQLPlatform`.
- **FR-FT-003**: `booleanQuery(string $query, int $minTokenLength = 3, ?array $stopwords = null): string` — lower-case (`mb_strtolower`), split on `/[^\p{L}\p{N}_]+/u`, drop words shorter than `max(1, $minTokenLength)` and stopwords (compared lower-case; `null` = `INNODB_STOPWORDS`), dedupe in order, join `+word*` with spaces; `""` when nothing is left. The output contains no boolean operators from user input.
- **FR-FT-004**: `DEFAULT_MIN_TOKEN_LENGTH = 3` (InnoDB `innodb_ft_min_token_size`); `INNODB_STOPWORDS` = InnoDB's default stopword list (`INFORMATION_SCHEMA.INNODB_FT_DEFAULT_STOPWORD`, 35 words).
- **FR-FT-005**: `snippet(string $text, string $query, int $width = 90): string` — whitespace collapsed and trimmed; first query word (split on whitespace) found with `mb_stripos`; window starts `intdiv($width, 3)` characters before the match (not before 0); leading `…` when the window does not start at 0, trailing `…` when the text continues; `$width < 1` is treated as 1.
- **FR-FT-006**: Results match Beacon's `PaletteTextMatch` for the same arguments with the defaults (drop-in replacement).

---

## Success Criteria

- **SC-001**: Unit tests cover MySQL / LIKE forms, platform detection (MySQL, MariaDB, SQLite, PostgreSQL, connection stub), custom token length and stopwords, and snippet cuts; 100% line coverage, PHPStan, CS, Rector, Igor green (`make release-check`).

## Explicit non-goals

- No `MATCH` DQL function shipped and no `beberlei/doctrineextensions` dependency (documented host requirement).
- No relevance ordering, no natural-language or query-expansion modes, no native SQL (DBAL) variant, no MySQL service in the test setup.
- Beacon adoption happens in Beacon.
