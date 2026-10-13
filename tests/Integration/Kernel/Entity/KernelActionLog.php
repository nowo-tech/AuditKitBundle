<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Integration\Kernel\Entity;

use Doctrine\ORM\Mapping as ORM;
use Nowo\AuditKitBundle\ActionLog\AbstractActionLog;
use Nowo\AuditKitBundle\Tests\Integration\Kernel\Repository\KernelActionLogRepository;
use ReflectionProperty;

#[ORM\Entity(repositoryClass: KernelActionLogRepository::class)]
#[ORM\Table(name: 'kernel_action_log')]
#[ORM\Index(name: 'idx_kernel_action_log_subject_created', columns: ['subject_id', 'created_at'])]
#[ORM\Index(name: 'idx_kernel_action_log_actor_created', columns: ['actor_id', 'created_at'])]
#[ORM\Index(name: 'idx_kernel_action_log_created', columns: ['created_at'])]
#[ORM\Index(name: 'idx_kernel_action_log_scope_created', columns: ['scope', 'created_at'])]
class KernelActionLog extends AbstractActionLog
{
    /** Test helper: backdate a row (the trait keeps createdAt append-only for application code). */
    public function backdate(string $modifier): void
    {
        $this->createdAtOverride($modifier);
    }

    private function createdAtOverride(string $modifier): void
    {
        $property = new ReflectionProperty(AbstractActionLog::class, 'createdAt');
        $property->setValue($this, $this->getCreatedAt()->modify($modifier));
    }
}
