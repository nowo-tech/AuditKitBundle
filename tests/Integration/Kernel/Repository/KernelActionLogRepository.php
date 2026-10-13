<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Tests\Integration\Kernel\Repository;

use Doctrine\Persistence\ManagerRegistry;
use Nowo\AuditKitBundle\ActionLog\ActionLogRepository;
use Nowo\AuditKitBundle\Tests\Integration\Kernel\Entity\KernelActionLog;

/** @extends ActionLogRepository<KernelActionLog> */
final class KernelActionLogRepository extends ActionLogRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, KernelActionLog::class);
    }
}
