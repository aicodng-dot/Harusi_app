<?php

namespace App\Services\Customers;

use App\Models\User;
use App\Services\AdminAudit;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Administrator operations on customer accounts. Each operation runs in one transaction
 * that also writes its audit entry. Lock order matches customer event creation
 * (user -> workspaces -> memberships -> events) so the two cannot deadlock or race.
 */
class CustomerAccountManager
{
    public function __construct(private readonly AdminAudit $audit) {}

    /** @param array{name: string, email: string, phone_number: ?string, preferred_language: string} $data */
    public function update(User $actor, int $customerId, array $data): User
    {
        return DB::transaction(function () use ($actor, $customerId, $data): User {
            $customer = $this->lockCustomer($customerId);
            $before = $customer->only(array_keys($data));
            $customer->fill($data);
            $changed = array_keys($customer->getDirty());
            if ($changed !== []) {
                if ($customer->isDirty('email')) {
                    // Pending reset links were sent to the old address.
                    DB::table('password_reset_tokens')->where('email', $before['email'])->delete();
                }
                $customer->save();
                $this->audit->record($actor, 'customer.updated', 'customer', $customer->id, $customer->email, ['fields' => $changed]);
            }

            return $customer;
        });
    }

    public function suspend(User $actor, int $customerId, ?string $reason): User
    {
        return DB::transaction(function () use ($actor, $customerId, $reason): User {
            $customer = $this->lockCustomer($customerId);
            if ($customer->is_active) {
                $customer->is_active = false;
                // Ends "remember me" logins; EnsureActiveAccount ends open sessions on their next request.
                $customer->setRememberToken(Str::random(60));
                $customer->save();
                $customer->tokens()->delete();
                if (config('session.driver') === 'database') {
                    DB::table(config('session.table', 'sessions'))->where('user_id', $customer->id)->delete();
                }
                $this->audit->record($actor, 'customer.suspended', 'customer', $customer->id, $customer->email,
                    array_filter(['reason' => $reason === null ? null : mb_substr($reason, 0, 500)]));
            }

            return $customer;
        });
    }

    public function reactivate(User $actor, int $customerId): User
    {
        return DB::transaction(function () use ($actor, $customerId): User {
            $customer = $this->lockCustomer($customerId);
            if (! $customer->is_active) {
                $customer->is_active = true;
                $customer->save();
                $this->audit->record($actor, 'customer.reactivated', 'customer', $customer->id, $customer->email);
            }

            return $customer;
        });
    }

    /**
     * What deleting this customer would remove, or why it is refused. Read-only.
     *
     * @return array{allowed: bool, reason: ?string, events: int, workspaces_removed: list<int>, memberships: int}
     */
    public function deletionPlan(int $customerId, ?ConnectionInterface $connection = null, bool $lock = false): array
    {
        $db = $connection ?? DB::connection();
        $memberships = $db->table('workspace_users')->useWritePdo()->where('user_id', $customerId)->orderBy('id');
        $memberships = ($lock ? $memberships->lockForUpdate() : $memberships)->get(['id', 'workspace_id']);
        $workspaceIds = $memberships->pluck('workspace_id')->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();
        if ($lock && $workspaceIds !== []) {
            $db->table('workspaces')->useWritePdo()->whereIn('id', $workspaceIds)->orderBy('id')->lockForUpdate()->get(['id']);
        }

        $events = $workspaceIds === [] ? 0 : $db->table('events')->useWritePdo()->whereIn('workspace_id', $workspaceIds)->count();
        if ($events > 0) {
            return ['allowed' => false, 'reason' => 'events', 'events' => $events, 'workspaces_removed' => [], 'memberships' => $memberships->count()];
        }

        $remove = [];
        foreach ($workspaceIds as $workspaceId) {
            $others = $db->table('workspace_users')->useWritePdo()->where('workspace_id', $workspaceId)->where('user_id', '!=', $customerId)->count();
            if ($others === 0) {
                if ($db->table('legacy_workspace_mappings')->useWritePdo()->where('workspace_id', $workspaceId)->exists()) {
                    return ['allowed' => false, 'reason' => 'legacy_mapping', 'events' => 0, 'workspaces_removed' => [], 'memberships' => $memberships->count()];
                }
                $remove[] = $workspaceId;
            }
        }
        // A provisioning record names this customer as creator of a workspace that would survive.
        $kept = $db->table('workspace_provisionings')->useWritePdo()->where('creator_user_id', $customerId)
            ->whereNotIn('workspace_id', $remove === [] ? [0] : $remove)->exists();
        if ($kept) {
            return ['allowed' => false, 'reason' => 'shared_workspace', 'events' => 0, 'workspaces_removed' => [], 'memberships' => $memberships->count()];
        }

        return ['allowed' => true, 'reason' => null, 'events' => 0, 'workspaces_removed' => $remove, 'memberships' => $memberships->count()];
    }

    /**
     * Deletes a customer that owns no events. Empty workspaces they alone belong to are
     * removed with them; shared workspaces only lose this membership. Returns the plan
     * that was executed, or the refusal (nothing changed).
     */
    public function delete(User $actor, int $customerId): array
    {
        return DB::transaction(function () use ($actor, $customerId): array {
            $customer = $this->lockCustomer($customerId);
            $db = DB::connection();
            $plan = $this->deletionPlan($customerId, $db, true);
            if (! $plan['allowed']) {
                return $plan;
            }

            $db->table('workspace_users')->where('user_id', $customerId)->delete();
            if ($plan['workspaces_removed'] !== []) {
                $db->table('workspace_provisionings')->whereIn('workspace_id', $plan['workspaces_removed'])->delete();
                $db->table('workspaces')->whereIn('id', $plan['workspaces_removed'])->delete();
            }
            $db->table('password_reset_tokens')->where('email', $customer->email)->delete();
            $customer->tokens()->delete();
            if (config('session.driver') === 'database') {
                $db->table(config('session.table', 'sessions'))->where('user_id', $customerId)->delete();
            }
            $label = $customer->email;
            $customer->delete();
            $this->audit->record($actor, 'customer.deleted', 'customer', $customerId, $label, [
                'name' => $customer->name, 'workspaces_removed' => $plan['workspaces_removed'], 'memberships_removed' => $plan['memberships'],
            ]);

            return $plan;
        });
    }

    private function lockCustomer(int $customerId): User
    {
        $customer = User::query()->useWritePdo()->whereKey($customerId)->lockForUpdate()->first();
        abort_unless($customer !== null && $customer->getRawOriginal('role') === User::ROLE_CUSTOMER
            && ! (bool) $customer->getRawOriginal('is_platform_admin'), 404);

        return $customer;
    }
}
