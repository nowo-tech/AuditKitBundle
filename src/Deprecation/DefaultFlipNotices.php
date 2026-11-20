<?php

declare(strict_types=1);

namespace Nowo\AuditKitBundle\Deprecation;

use Nowo\AuditKitBundle\NowoAuditKitBundle;

use function trigger_deprecation;

/**
 * Since 1.18 (REQ-ARCH-001 wave 0): the runtime notices for the defaults that change in 2.0, triggered once per kernel
 * boot by {@see NowoAuditKitBundle::boot()} for each option the host left unset. The extension
 * decides which ones apply (parameter {@see PARAMETER}); setting the option explicitly, to either value, silences its
 * notice.
 *
 * @internal
 */
final class DefaultFlipNotices
{
    public const PACKAGE = 'nowo-tech/audit-kit-bundle';

    public const VERSION = '1.18';

    /** Container parameter: the keys of {@see MESSAGES} that apply to this container. */
    public const PARAMETER = 'nowo_audit_kit.default_flip_notices';

    public const FLUSH_MODE = 'action_log.flush_mode';

    public const ATTRIBUTE_OPT_IN = 'attribute_opt_in';

    /** @var array<string, string> */
    public const MESSAGES = [
        self::FLUSH_MODE       => 'Leaving "nowo_audit_kit.action_log.flush_mode" unset is deprecated: recordAndFlush() and recordIdsAndFlush() then flush the entity manager of the action log class with all your pending changes ("host"). The default becomes "isolated" in 2.0 (only the new row is written; your pending changes stay pending). Set "nowo_audit_kit.action_log.flush_mode: isolated" to opt in now, or "flush_mode: host" to keep the current behaviour explicitly.',
        self::ATTRIBUTE_OPT_IN => 'Leaving "nowo_audit_kit.attribute_opt_in" unset is deprecated: #[Auditable] alone (without a bundle interface or trait) is ignored today. The default becomes true in 2.0 (the attribute alone opts a class in). Set "nowo_audit_kit.attribute_opt_in: true" to opt in now, or "attribute_opt_in: false" to keep the current behaviour explicitly.',
    ];

    /**
     * Triggers the notice of each given key once.
     *
     * @param iterable<mixed> $keys
     */
    public static function trigger(iterable $keys): void
    {
        foreach ($keys as $key) {
            if (isset(self::MESSAGES[$key])) {
                trigger_deprecation(self::PACKAGE, self::VERSION, self::MESSAGES[$key]);
            }
        }
    }
}
