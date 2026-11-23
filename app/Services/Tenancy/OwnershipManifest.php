<?php

namespace App\Services\Tenancy;

use InvalidArgumentException;
use JsonException;
use stdClass;

final readonly class OwnershipManifest
{
    public const MAX_BYTES = 262144;

    public const MAX_WORKSPACES = 1000;

    public const MAX_IDS = 10000;

    private function __construct(private array $groups, private array $platformIds) {}

    public static function fromJson(string $json): self
    {
        if (strlen($json) > self::MAX_BYTES) {
            self::invalid();
        }

        try {
            // Objects must remain distinguishable from lists, and oversized
            // JSON integers must never be coerced into apparently valid IDs.
            $document = json_decode($json, false, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            self::invalid();
        }

        self::assertUniqueObjectKeys($json);
        self::assertObjectKeys($document, ['schema_version', 'workspaces', 'platform_admin_user_ids']);

        if ($document->schema_version !== 1 || ! is_array($document->workspaces)
            || ! is_array($document->platform_admin_user_ids)
            || count($document->workspaces) > self::MAX_WORKSPACES) {
            self::invalid();
        }

        $groups = [];
        $references = [];
        $events = [];
        $occurrences = 0;
        foreach ($document->workspaces as $group) {
            self::assertObjectKeys($group, ['reference', 'name', 'owner_user_ids', 'event_ids']);
            if (! is_string($group->reference)
                || preg_match('/\A[a-z][a-z0-9_-]{0,63}\z/', $group->reference) !== 1
                || isset($references[$group->reference]) || ! self::validName($group->name)) {
                self::invalid();
            }
            $references[$group->reference] = true;
            $ownerIds = self::ids($group->owner_user_ids, $occurrences, true);
            $eventIds = self::ids($group->event_ids, $occurrences, true);
            foreach ($eventIds as $id) {
                if (isset($events[$id])) {
                    self::invalid();
                }
                $events[$id] = true;
            }
            $groups[] = [
                'reference' => $group->reference,
                'name' => $group->name,
                'owner_user_ids' => $ownerIds,
                'event_ids' => $eventIds,
            ];
        }

        $platformIds = self::ids($document->platform_admin_user_ids, $occurrences, false);

        return new self($groups, $platformIds);
    }

    public function workspaces(): array
    {
        return $this->groups;
    }

    public function platformAdminUserIds(): array
    {
        return $this->platformIds;
    }

    private static function assertObjectKeys(mixed $object, array $expected): void
    {
        if (! $object instanceof stdClass) {
            self::invalid();
        }

        $keys = array_keys(get_object_vars($object));
        sort($keys);
        sort($expected);
        if ($keys !== $expected) {
            self::invalid();
        }
    }

    private static function validName(mixed $name): bool
    {
        return is_string($name) && mb_strlen($name, 'UTF-8') <= 255
            && preg_match('/\p{Cc}/u', $name) === 0
            && preg_match('/[^\s\p{Z}]/u', $name) === 1;
    }

    private static function ids(mixed $values, int &$occurrences, bool $required): array
    {
        if (! is_array($values) || ($required && $values === [])) {
            self::invalid();
        }

        $occurrences += count($values);
        if ($occurrences > self::MAX_IDS) {
            self::invalid();
        }

        $seen = [];
        foreach ($values as $id) {
            if (! is_int($id) || $id <= 0 || isset($seen[$id])) {
                self::invalid();
            }
            $seen[$id] = true;
        }

        return $values;
    }

    /** Native decoding validates grammar; this scan preserves duplicate-key evidence it discards. */
    private static function assertUniqueObjectKeys(string $json): void
    {
        if (preg_match_all('/"(?:[^"\\\\]|\\\\.)*"|[{}\[\]:,]/s', $json, $matches) === false) {
            self::invalid();
        }
        $stack = [];
        foreach ($matches[0] as $token) {
            if ($token === '{' || $token === '[') {
                $stack[] = ['object' => $token === '{', 'key' => true, 'seen' => []];

                continue;
            }
            if ($token === '}' || $token === ']') {
                array_pop($stack);

                continue;
            }
            $last = array_key_last($stack);
            if ($last === null || ! $stack[$last]['object']) {
                continue;
            }
            if ($token === ',') {
                $stack[$last]['key'] = true;
            } elseif ($token === ':') {
                $stack[$last]['key'] = false;
            } elseif ($stack[$last]['key'] && str_starts_with($token, '"')) {
                // The complete input was already decoded without errors.
                $key = json_decode($token, false, 8, JSON_THROW_ON_ERROR);
                if (isset($stack[$last]['seen'][$key])) {
                    self::invalid();
                }
                $stack[$last]['seen'][$key] = true;
                $stack[$last]['key'] = false;
            }
        }
    }

    private static function invalid(): never
    {
        // Never attach the original parser exception or private input as context.
        throw new InvalidArgumentException('Invalid ownership manifest.');
    }
}
