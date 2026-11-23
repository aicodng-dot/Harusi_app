<?php

namespace App\Services\Tenancy;

use Illuminate\Database\Connection;
use Throwable;

/** Dormant guest/QR permission observations, not a mutation or file-access fence. */
class GuestAccess
{
    private const ACTIONS = ['guest.view', 'guest.update', 'qr.view', 'qr.regenerate'];

    public function __construct(private readonly WorkspaceAccess $workspaces) {}

    public function allows(Connection $connection, mixed $userId, mixed $workspaceId, mixed $eventId, mixed $guestId, mixed $action, mixed $qrCodeId = null): bool
    {
        foreach ([$userId, $workspaceId, $eventId, $guestId] as $id) {
            if (! is_int($id) || $id < 1) {
                return false;
            }
        }
        if (! is_string($action) || ! in_array($action, self::ACTIONS, true)) {
            return false;
        }
        $qrAction = in_array($action, ['qr.view', 'qr.regenerate'], true);
        if ($qrAction ? ! is_int($qrCodeId) || $qrCodeId < 1 : $qrCodeId !== null) {
            return false;
        }

        try {
            $eventAction = in_array($action, ['guest.view', 'qr.view'], true) ? 'event.view' : 'event.update';
            if (! $this->workspaces->allows($connection, $userId, $workspaceId, $eventAction, $eventId)
                || ! $this->safeKey($connection, 'guests', ['id', 'event_id'])
                || ($qrAction && ! $this->safeKey($connection, 'qr_codes', ['id', 'guest_id']))) {
                return false;
            }
            // Recheck the full resource lineage in one bounded primary query.
            // No guest names, tokens, codes, counters or file paths are selected.
            $query = $connection->table('guests as g')->useWritePdo()
                ->join('events as e', 'e.id', '=', 'g.event_id')
                ->join('workspaces as w', 'w.id', '=', 'e.workspace_id')
                ->where('g.id', $guestId)->where('g.event_id', $eventId)
                ->where('e.id', $eventId)->where('e.workspace_id', $workspaceId)->where('w.id', $workspaceId)
                ->select(['g.id as guest_id', 'g.event_id as event_id', 'e.id as linked_event_id', 'e.workspace_id as workspace_id', 'w.id as linked_workspace_id']);
            if ($qrAction) {
                $query->join('qr_codes as q', 'q.guest_id', '=', 'g.id')
                    ->where('q.id', $qrCodeId)->where('q.guest_id', $guestId)
                    ->addSelect(['q.id as qr_id', 'q.guest_id as qr_guest_id']);
            }
            $rows = $query->orderBy('g.id')->limit(2)->get();
            if ($rows->count() !== 1) {
                return false;
            }
            $row = $rows->first();

            return $this->storedId($row->guest_id) === $guestId && $this->storedId($row->event_id) === $eventId
                && $this->storedId($row->linked_event_id) === $eventId && $this->storedId($row->workspace_id) === $workspaceId
                && $this->storedId($row->linked_workspace_id) === $workspaceId
                && (! $qrAction || ($this->storedId($row->qr_id) === $qrCodeId && $this->storedId($row->qr_guest_id) === $guestId));
        } catch (Throwable) {
            // No raw exception, payload, binding or private context is retained/logged.
            return false;
        }
    }

    private function safeKey(Connection $connection, string $table, array $required): bool
    {
        $schema = $connection->getSchemaBuilder();
        $columns = $connection->getDriverName() === 'sqlite'
            ? $connection->getPostProcessor()->processColumns($connection->selectFromWriteConnection(
                $connection->getSchemaGrammar()->compileColumns(null, $connection->getTablePrefix().$table)
            ))
            : $schema->getColumns($table);
        if (array_diff($required, array_column($columns, 'name')) !== []) {
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
        foreach ($schema->getIndexes($table) as $index) {
            if (($index['primary'] ?? false) === true && ($index['unique'] ?? false) === true && ($index['columns'] ?? []) === ['id']) {
                return true;
            }
        }

        return false;
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
