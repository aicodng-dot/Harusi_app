<?php

namespace App\Services\Tenancy;

use Illuminate\Database\Connection;
use Throwable;

/** Dormant permission observations; no runtime caller invokes this service. */
class WorkspaceAccess
{
    private const ACTIONS = ['workspace.view', 'workspace.rename', 'event.create', 'event.view', 'event.update', 'event.delete'];

    private const EVENT_ACTIONS = ['event.view', 'event.update', 'event.delete'];

    /** Destructive actions reserved to the workspace owner (managers may edit but not remove). */
    private const OWNER_ACTIONS = ['workspace.rename', 'event.delete'];

    public function __construct(private readonly AuthorityContextResolver $authority) {}

    public function allows(Connection $connection, mixed $userId, mixed $workspaceId, mixed $action, mixed $eventId = null): bool
    {
        // No implicit context, string-ID coercion or open-ended role permission.
        if (! is_int($userId) || $userId < 1 || ! is_int($workspaceId) || $workspaceId < 1
            || ! is_string($action) || ! in_array($action, self::ACTIONS, true)) {
            return false;
        }
        if (in_array($action, self::EVENT_ACTIONS, true)
            ? ! is_int($eventId) || $eventId < 1
            : $eventId !== null) {
            return false;
        }

        try {
            // Never accept cached caller facts, a receipt or a platform bypass.
            // This follows caller isolation; it does not fence concurrent revocation.
            $context = $this->authority->resolve($connection, $userId, $workspaceId);
            $role = $context->workspaceRole();
            if (! $context->isAccountEligible() || ! in_array($role, ['owner', 'manager'], true)
                || (in_array($action, self::OWNER_ACTIONS, true) && $role !== 'owner')) {
                return false;
            }

            return $eventId === null || $this->eventBelongsTo($connection, $eventId, $workspaceId);
        } catch (Throwable) {
            // Unavailable/malformed schema is a denial, never a privilege fallback.
            // No raw errors, bindings, names or connection context are logged.
            return false;
        }
    }

    private function eventBelongsTo(Connection $connection, int $eventId, int $workspaceId): bool
    {
        $schema = $connection->getSchemaBuilder();
        $columns = $connection->getDriverName() === 'sqlite'
            ? $connection->getPostProcessor()->processColumns($connection->selectFromWriteConnection(
                $connection->getSchemaGrammar()->compileColumns(null, $connection->getTablePrefix().'events')
            ))
            : $schema->getColumns('events');
        if (array_diff(['id', 'workspace_id'], array_column($columns, 'name')) !== []) {
            return false;
        }
        $idColumn = null;
        foreach ($columns as $column) {
            if ($column['name'] === 'id') {
                $idColumn = $column;
                break;
            }
        }
        if ($idColumn === null || ! in_array(strtolower($idColumn['type_name'] ?? ''), ['integer', 'int', 'bigint', 'mediumint', 'smallint', 'tinyint'], true)
            || (($idColumn['nullable'] ?? null) !== false && $connection->getDriverName() !== 'sqlite')) {
            return false;
        }
        $primary = false;
        foreach ($schema->getIndexes('events') as $index) {
            if (($index['primary'] ?? false) === true && ($index['unique'] ?? false) === true
                && ($index['columns'] ?? []) === ['id']) {
                $primary = true;
                break;
            }
        }
        if (! $primary) {
            return false;
        }
        $events = $connection->table('events')->useWritePdo()->select(['id', 'workspace_id'])
            ->where('id', $eventId)->where('workspace_id', $workspaceId)->orderBy('id')->limit(2)->get();

        return $events->count() === 1 && $this->storedId($events->first()->id) === $eventId
            && $this->storedId($events->first()->workspace_id) === $workspaceId;
    }

    private function storedId(mixed $value): ?int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value) && preg_match('/\A[1-9][0-9]*\z/', $value) === 1) {
            $id = filter_var($value, FILTER_VALIDATE_INT);
            if ($id !== false) {
                return $id;
            }
        }

        return null;
    }
}
