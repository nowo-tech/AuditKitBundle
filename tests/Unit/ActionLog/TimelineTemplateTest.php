<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Unit\ActionLog;

use Nowo\AuditKitBundle\Tests\Integration\Kernel\Entity\KernelActionLog;
use Nowo\AuditKitBundle\Tests\Integration\Kernel\Entity\KernelUser;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;

/** Renders @NowoAuditKitBundle/action_log/_timeline.html.twig with a stub `trans` filter ([domain:key params]). */
final class TimelineTemplateTest extends TestCase
{
    private Environment $twig;

    protected function setUp(): void
    {
        $loader = new FilesystemLoader();
        $loader->addPath(__DIR__ . '/../../../src/Resources/views', 'NowoAuditKitBundle');
        $this->twig = new Environment($loader, ['strict_variables' => true, 'autoescape' => 'html']);
        $this->twig->addFilter(new TwigFilter('trans', static fn (string $key, array $params = [], ?string $domain = null): string => '[' . $domain . ':' . $key . ($params === [] ? '' : ' ' . implode(',', $params)) . ']'));
    }

    public function testEmptyState(): void
    {
        $html = $this->render(['entries' => []]);

        $this->assertStringContainsString('nowo-ui-empty', $html);
        $this->assertStringContainsString('[NowoAuditKitBundle:nowo_audit_kit.action_log.empty]', $html);
    }

    public function testEmptyStateKeyAndDomainAreOverridable(): void
    {
        $html = $this->render(['entries' => [], 'empty_key' => 'audit.none', 'translation_domain' => 'admin']);

        $this->assertStringContainsString('[admin:audit.none]', $html);
    }

    public function testRendersEntriesWithActorSubjectIpAndContext(): void
    {
        $alice = new KernelUser('alice@example.com');
        $bob   = new KernelUser('bob@example.com');
        $full  = (new KernelActionLog())
            ->setAction('project.member_added')
            ->setActor($alice)
            ->setSubject($bob)
            ->setIpAddress('203.0.113.7')
            ->setContext(['project_name' => 'Demo', 'roles' => ['a', 'b']]);
        $system = (new KernelActionLog())->setAction('user.created')->setSubject($bob);

        $html = $this->render(['entries' => [$full, $system], 'action_prefix' => 'users.activity.action.']);

        $this->assertSame(2, substr_count($html, 'data-testid="action-log-entry"'));
        $this->assertStringContainsString('id="action-log-timeline"', $html);
        $this->assertStringContainsString('[messages:users.activity.action.project.member_added]', $html);
        $this->assertStringContainsString('[NowoAuditKitBundle:nowo_audit_kit.action_log.by alice@example.com]', $html);
        $this->assertStringContainsString('[NowoAuditKitBundle:nowo_audit_kit.action_log.subject bob@example.com]', $html);
        $this->assertStringContainsString('[NowoAuditKitBundle:nowo_audit_kit.action_log.by_system]', $html);
        $this->assertStringContainsString('203.0.113.7', $html);
        $this->assertStringContainsString('[messages:nowo_audit_kit.action_log.context.project_name]', $html);
        $this->assertStringContainsString('<dd>Demo</dd>', $html);
        $this->assertStringContainsString('[&quot;a&quot;,&quot;b&quot;]', $html);
    }

    public function testIpAndContextCanBeHidden(): void
    {
        $entry = (new KernelActionLog())->setAction('x')->setIpAddress('203.0.113.7')->setContext(['k' => 'v']);

        $html = $this->render(['entries' => [$entry], 'show_ip' => false, 'show_context' => false, 'timeline_id' => 'tl', 'entry_testid' => 'row']);

        $this->assertStringNotContainsString('203.0.113.7', $html);
        $this->assertStringNotContainsString('<dl', $html);
        $this->assertStringContainsString('id="tl"', $html);
        $this->assertStringContainsString('data-testid="row"', $html);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function render(array $context): string
    {
        return $this->twig->render('@NowoAuditKitBundle/action_log/_timeline.html.twig', $context);
    }
}
