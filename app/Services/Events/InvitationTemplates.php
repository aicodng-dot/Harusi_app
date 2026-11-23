<?php

namespace App\Services\Events;

use App\Services\Invitations\InvitationTemplateCatalog;

/**
 * Working Flow 14 registry, now backed by the full invitation template library
 * (App\Services\Invitations\InvitationTemplateCatalog). Kept as the entry point used by
 * CustomerEventTemplateSelector so the stored events.invitation_template values and
 * their validation rules stay unchanged; the original four identifiers still exist.
 */
class InvitationTemplates
{
    public static function isValid(mixed $key): bool
    {
        return InvitationTemplateCatalog::isValid($key);
    }

    public static function category(mixed $value): ?string
    {
        return InvitationTemplateCatalog::category($value);
    }

    public static function defaultCategory(?string $eventType): ?string
    {
        return InvitationTemplateCatalog::defaultCategory($eventType);
    }

    /** @return array<string, array> */
    public static function forCategory(?string $category, ?string $search = null): array
    {
        return InvitationTemplateCatalog::filter($category, $search);
    }

    public static function name(string $key): string
    {
        return InvitationTemplateCatalog::get($key)['name'] ?? $key;
    }
}
