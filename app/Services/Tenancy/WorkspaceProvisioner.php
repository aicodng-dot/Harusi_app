<?php

namespace App\Services\Tenancy;

use Illuminate\Database\Connection;
use Illuminate\Database\MySqlConnection;
use Throwable;

/** Callers must obtain creator ID from authenticated identity. */
class WorkspaceProvisioner
{
    public function __construct(private readonly WorkspaceProvisioningSchema $schema) {}

    public function provision(Connection $connection, mixed $creatorUserId, mixed $name, mixed $requestKey): WorkspaceProvisioningResult
    {
        return $this->provisionForRole($connection, $creatorUserId, $name, $requestKey, null);
    }

    public function provisionCustomer(Connection $connection, mixed $creatorUserId, mixed $name, mixed $requestKey): WorkspaceProvisioningResult
    {
        return $this->provisionForRole($connection, $creatorUserId, $name, $requestKey, 'customer');
    }

    private function provisionForRole(Connection $connection, mixed $creatorUserId, mixed $name, mixed $requestKey, ?string $requiredRole): WorkspaceProvisioningResult
    {
        // Reject ambiguous IDs/keys and raw controls before any normalization or DB access.
        if (! is_int($creatorUserId) || $creatorUserId < 1 || ! is_string($name)
            || ! mb_check_encoding($name, 'UTF-8') || preg_match('/[\p{Cc}\p{Cf}]/u', $name) !== 0
            || ! is_string($requestKey)
            || preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $requestKey) !== 1) {
            $this->fail('invalid_input');
        }
        $name = preg_replace('/\A[\p{Z}\s]+|[\p{Z}\s]+\z/u', '', $name);
        if ($name === null || $name === '' || mb_strlen($name, 'UTF-8') > 255) {
            $this->fail('invalid_input');
        }

        try {
            $this->schema->assertReady($connection);
            $requestHash = hash('sha256', "harusi.workspace.request.v1\0".$requestKey);
            $nameHash = hash('sha256', "harusi.workspace.name.v1\0".$name);

            return $connection->transaction(function () use ($connection, $creatorUserId, $name, $requestHash, $nameHash, $requiredRole): WorkspaceProvisioningResult {
                // All same-creator setup requests take this lock first. It also
                // prevents committed account deactivation during this transaction.
                $accounts = $connection->table('users')->useWritePdo()->select(['id', 'role', 'is_active'])
                    ->where('id', $creatorUserId)->orderBy('id')->limit(2)->lockForUpdate()->get();
                if ($accounts->count() !== 1 || $this->storedId($accounts->first()->id) !== $creatorUserId
                    || ! $this->isTrue($accounts->first()->is_active)
                    || ! in_array($accounts->first()->role, ['admin', 'scanner', 'customer'], true)
                    || ($requiredRole !== null && $accounts->first()->role !== $requiredRole)) {
                    $this->fail('creator_unavailable');
                }
                $existing = $this->receipt($connection, $creatorUserId, $requestHash, $nameHash);
                if ($existing !== null) {
                    $workspaceId = $this->storedId($existing->workspace_id);
                    if ($workspaceId === null) {
                        $this->fail('request_conflict');
                    }
                    $this->verifyOwner($connection, $creatorUserId, $workspaceId);

                    return new WorkspaceProvisioningResult($workspaceId, false);
                }

                $timestamp = now();
                $workspaceId = $this->insertedId($connection, 'workspaces', [
                    'name' => $name, 'created_at' => $timestamp, 'updated_at' => $timestamp,
                ]);
                $this->insertedId($connection, 'workspace_users', [
                    'workspace_id' => $workspaceId, 'user_id' => $creatorUserId, 'role' => 'owner', 'is_active' => true,
                    'created_at' => $timestamp, 'updated_at' => $timestamp,
                ]);
                $this->insertedId($connection, 'workspace_provisionings', [
                    'creator_user_id' => $creatorUserId, 'workspace_id' => $workspaceId,
                    'request_hash' => $requestHash, 'name_hash' => $nameHash,
                    'created_at' => $timestamp, 'updated_at' => $timestamp,
                ]);
                $receipt = $this->receipt($connection, $creatorUserId, $requestHash, $nameHash);
                if ($receipt === null || $this->storedId($receipt->workspace_id) !== $workspaceId) {
                    $this->fail('request_conflict');
                }
                $this->verifyOwner($connection, $creatorUserId, $workspaceId);

                return new WorkspaceProvisioningResult($workspaceId, true);
            });
        } catch (WorkspaceProvisioningException $exception) {
            throw $exception;
        } catch (Throwable) {
            // Do not retain SQL, bindings, private names or connection errors.
            throw WorkspaceProvisioningException::because('database_unavailable');
        }
    }

    private function receipt(Connection $connection, int $creatorId, string $requestHash, string $nameHash): ?object
    {
        $rows = $connection->table('workspace_provisionings')->useWritePdo()
            ->select(['id', 'creator_user_id', 'workspace_id', 'request_hash', 'name_hash'])
            ->where('creator_user_id', $creatorId)->where('request_hash', $requestHash)
            ->orderBy('id')->limit(2)->lockForUpdate()->get();
        if ($rows->count() > 1) {
            $this->fail('request_conflict');
        }
        $row = $rows->first();
        // SQL collation must not turn a changed/corrupt hash into an exact retry.
        if ($row !== null && ($this->storedId($row->id) === null || $this->storedId($row->creator_user_id) !== $creatorId
            || $row->request_hash !== $requestHash || $row->name_hash !== $nameHash)) {
            $this->fail('request_conflict');
        }

        return $row;
    }

    private function verifyOwner(Connection $connection, int $creatorId, int $workspaceId): void
    {
        $parents = $connection->table('workspaces')->useWritePdo()->select('id')
            ->where('id', $workspaceId)->orderBy('id')->limit(2)->lockForUpdate()->get();
        $members = $connection->table('workspace_users')->useWritePdo()->select(['id', 'workspace_id', 'user_id', 'role', 'is_active'])
            ->where('workspace_id', $workspaceId)->where('user_id', $creatorId)->orderBy('id')->limit(2)->lockForUpdate()->get();
        if ($parents->count() !== 1 || $this->storedId($parents->first()->id) !== $workspaceId || $members->count() !== 1) {
            $this->fail('request_conflict');
        }
        $member = $members->first();
        if ($this->storedId($member->id) === null || $this->storedId($member->workspace_id) !== $workspaceId
            || $this->storedId($member->user_id) !== $creatorId || $member->role !== 'owner' || ! $this->isTrue($member->is_active)) {
            $this->fail('request_conflict');
        }
    }

    private function insertedId(Connection $connection, string $table, array $values): int
    {
        $processed = $connection->table($table)->insertGetId($values);
        $raw = $connection instanceof MySqlConnection ? $connection->getLastInsertId() : $connection->getPdo()->lastInsertId();
        $id = $this->storedId($raw);
        if ($id === null || $processed !== $id) {
            $this->fail('invalid_prerequisites');
        }

        return $id;
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

    private function isTrue(mixed $value): bool
    {
        return in_array($value, [true, 1, '1'], true);
    }

    private function fail(string $reason): never
    {
        throw WorkspaceProvisioningException::because($reason);
    }
}
