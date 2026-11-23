<?php

namespace Tests\Unit;

use App\Services\Tenancy\OwnershipManifest;
use App\Services\Tenancy\OwnershipPlanIdentity;
use Error;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class OwnershipPlanIdentityTest extends TestCase
{
    public function test_identity_matches_an_independently_written_canonical_byte_fixture(): void
    {
        // This literal specifies the protocol bytes independently of production
        // sorting/serialization, including exact Unicode and unescaped slash.
        $canonical = '{"schema_version":1,"workspaces":[{"reference":"alpha","name":"Familia / café 🌺","owner_user_ids":[2,10],"event_ids":[11,20]},{"reference":"zeta","name":"Zetu","owner_user_ids":[2,7],"event_ids":[30]}],"platform_admin_user_ids":[3,9]}';
        // Known answer computed independently with Node crypto over those bytes.
        $expected = 'b73bd880d700acb1a438f031ffb833a034ed1f699841a4251c098e648290902d';
        $this->assertSame($expected, hash('sha256', "kadify:legacy-ownership-plan:v1\0".$canonical));
        $identity = OwnershipPlanIdentity::fromManifest($this->manifest($this->proposal()));

        $this->assertSame($expected, $identity->digest());
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $identity->digest());
        $this->assertNotSame(hash('sha256', $canonical), $identity->digest());
        $this->assertNotSame(hash('sha256', 'kadify:legacy-ownership-plan:v1'.$canonical), $identity->digest());
    }

    public function test_json_formatting_key_group_and_id_list_order_do_not_change_identity(): void
    {
        $proposal = $this->proposal();
        $expected = $this->digest($proposal);
        $reordered = $proposal;
        $reordered['platform_admin_user_ids'] = array_reverse($reordered['platform_admin_user_ids']);
        $reordered['workspaces'] = array_reverse($reordered['workspaces']);
        foreach ($reordered['workspaces'] as &$group) {
            $group['owner_user_ids'] = array_reverse($group['owner_user_ids']);
            $group['event_ids'] = array_reverse($group['event_ids']);
            $group = array_reverse($group, true);
        }
        unset($group);
        $reordered = array_reverse($reordered, true);
        $pretty = json_encode($reordered, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $escaped = json_encode($reordered, JSON_THROW_ON_ERROR);

        $this->assertSame($expected, OwnershipPlanIdentity::fromManifest(OwnershipManifest::fromJson(" \n".$pretty."\n "))->digest());
        $this->assertSame($expected, OwnershipPlanIdentity::fromManifest(OwnershipManifest::fromJson($escaped))->digest());
        $this->assertSame($expected, $this->digest($proposal));
    }

    #[DataProvider('meaningChanges')]
    public function test_actual_proposal_changes_produce_a_different_identity(?int $group, string $field, array|string $value): void
    {
        $proposal = $this->proposal();
        $changed = $proposal;
        if ($group === null) {
            $changed[$field] = $value;
        } else {
            $changed['workspaces'][$group][$field] = $value;
        }

        $this->assertNotSame($this->digest($proposal), $this->digest($changed));
    }

    public static function meaningChanges(): array
    {
        return [
            'reference' => [1, 'reference', 'alpha-new'],
            'name' => [1, 'name', 'Different family'],
            'owner added' => [1, 'owner_user_ids', [2, 10, 12]],
            'owner removed' => [1, 'owner_user_ids', [2]],
            'owner replaced' => [1, 'owner_user_ids', [2, 12]],
            'event added' => [1, 'event_ids', [11, 20, 21]],
            'event removed' => [1, 'event_ids', [11]],
            'event replaced' => [1, 'event_ids', [11, 21]],
            'platform added' => [null, 'platform_admin_user_ids', [3, 9, 12]],
            'platform removed' => [null, 'platform_admin_user_ids', [3]],
            'platform replaced' => [null, 'platform_admin_user_ids', [3, 12]],
        ];
    }

    public function test_moving_the_same_event_or_owner_sets_between_groups_changes_identity(): void
    {
        $proposal = $this->proposal();
        $changed = $proposal;
        [$changed['workspaces'][0]['event_ids'], $changed['workspaces'][1]['event_ids']]
            = [$changed['workspaces'][1]['event_ids'], $changed['workspaces'][0]['event_ids']];
        $this->assertNotSame($this->digest($proposal), $this->digest($changed));

        $changed = $proposal;
        [$changed['workspaces'][0]['owner_user_ids'], $changed['workspaces'][1]['owner_user_ids']]
            = [$changed['workspaces'][1]['owner_user_ids'], $changed['workspaces'][0]['owner_user_ids']];
        $this->assertNotSame($this->digest($proposal), $this->digest($changed));

        $changed = $proposal;
        $changed['workspaces'][] = [
            'reference' => 'middle', 'name' => 'New workspace', 'owner_user_ids' => [2], 'event_ids' => [40],
        ];
        $this->assertNotSame($this->digest($proposal), $this->digest($changed));
    }

    public function test_exact_whitespace_and_unicode_names_are_never_trimmed_or_normalized(): void
    {
        $names = [
            'Familia', ' Familia', 'Familia ', ' Familia ', "Familia\u{00A0}",
            'Café', "Cafe\u{0301}", 'CAFÉ', 'Familia 🌺', 'Familia 🌸',
        ];
        $digests = [];
        foreach ($names as $name) {
            $proposal = $this->proposal();
            $proposal['workspaces'][1]['name'] = $name;
            $digests[] = $this->digest($proposal);
        }

        $this->assertCount(count($names), array_unique($digests));
    }

    public function test_reference_sorting_is_bytewise_instead_of_natural_numeric_order(): void
    {
        $proposal = ['schema_version' => 1, 'workspaces' => [], 'platform_admin_user_ids' => []];
        foreach (['family_1', 'family-2', 'familya', 'family-10'] as $index => $reference) {
            $proposal['workspaces'][] = [
                'reference' => $reference, 'name' => 'Family', 'owner_user_ids' => [1], 'event_ids' => [$index + 1],
            ];
        }
        $canonical = '{"schema_version":1,"workspaces":[{"reference":"family-10","name":"Family","owner_user_ids":[1],"event_ids":[4]},{"reference":"family-2","name":"Family","owner_user_ids":[1],"event_ids":[2]},{"reference":"family_1","name":"Family","owner_user_ids":[1],"event_ids":[1]},{"reference":"familya","name":"Family","owner_user_ids":[1],"event_ids":[3]}],"platform_admin_user_ids":[]}';

        $this->assertSame(hash('sha256', "kadify:legacy-ownership-plan:v1\0".$canonical), $this->digest($proposal));
    }

    public function test_integer_boundaries_sort_exactly_and_neighboring_ids_remain_distinct(): void
    {
        $proposal = $this->proposal();
        $proposal['workspaces'][1]['owner_user_ids'] = [PHP_INT_MAX, 2, PHP_INT_MAX - 1, 10];
        $proposal['workspaces'][1]['event_ids'] = [PHP_INT_MAX, 11, PHP_INT_MAX - 1];
        $proposal['platform_admin_user_ids'] = [PHP_INT_MAX, 2, PHP_INT_MAX - 1];
        $expected = $this->digest($proposal);
        $reordered = $proposal;
        $reordered['workspaces'][1]['owner_user_ids'] = [2, 10, PHP_INT_MAX - 1, PHP_INT_MAX];
        $reordered['workspaces'][1]['event_ids'] = [11, PHP_INT_MAX - 1, PHP_INT_MAX];
        $reordered['platform_admin_user_ids'] = [2, PHP_INT_MAX - 1, PHP_INT_MAX];
        $this->assertSame($expected, $this->digest($reordered));

        foreach (['owner_user_ids', 'event_ids'] as $field) {
            $first = $this->proposal();
            $first['workspaces'][1][$field] = [PHP_INT_MAX];
            $second = $first;
            $second['workspaces'][1][$field] = [PHP_INT_MAX - 1];
            $this->assertNotSame($this->digest($first), $this->digest($second), $field);
        }
        $first = $this->proposal();
        $first['platform_admin_user_ids'] = [PHP_INT_MAX];
        $second = $first;
        $second['platform_admin_user_ids'] = [PHP_INT_MAX - 1];
        $this->assertNotSame($this->digest($first), $this->digest($second));
    }

    public function test_empty_and_platform_only_proposals_have_stable_distinct_identities(): void
    {
        $empty = ['schema_version' => 1, 'workspaces' => [], 'platform_admin_user_ids' => []];
        $platformOnly = $empty;
        $platformOnly['platform_admin_user_ids'] = [9, 3];
        $reordered = $platformOnly;
        $reordered['platform_admin_user_ids'] = [3, 9];

        $this->assertNotSame($this->digest($empty), $this->digest($platformOnly));
        $this->assertSame($this->digest($platformOnly), $this->digest($reordered));
    }

    public function test_identity_calculation_preserves_the_original_manifest_and_keeps_digest_private(): void
    {
        $manifest = $this->manifest($this->proposal());
        $beforeGroups = $manifest->workspaces();
        $beforePlatformIds = $manifest->platformAdminUserIds();
        $identity = OwnershipPlanIdentity::fromManifest($manifest);

        $this->assertSame($beforeGroups, $manifest->workspaces());
        $this->assertSame($beforePlatformIds, $manifest->platformAdminUserIds());
        $this->assertSame('{}', json_encode($identity, JSON_THROW_ON_ERROR));
        $this->assertSame([], get_object_vars($identity));
        $this->assertSame($identity->digest(), OwnershipPlanIdentity::fromManifest($manifest)->digest());
    }

    public function test_identity_has_no_implicit_string_conversion(): void
    {
        $identity = OwnershipPlanIdentity::fromManifest($this->manifest($this->proposal()));
        $this->expectException(Error::class);

        (string) $identity;
    }

    private function proposal(): array
    {
        return [
            'schema_version' => 1,
            'workspaces' => [
                ['reference' => 'zeta', 'name' => 'Zetu', 'owner_user_ids' => [7, 2], 'event_ids' => [30]],
                ['reference' => 'alpha', 'name' => 'Familia / café 🌺', 'owner_user_ids' => [10, 2], 'event_ids' => [20, 11]],
            ],
            'platform_admin_user_ids' => [9, 3],
        ];
    }

    private function manifest(array $proposal): OwnershipManifest
    {
        return OwnershipManifest::fromJson(json_encode($proposal, JSON_THROW_ON_ERROR));
    }

    private function digest(array $proposal): string
    {
        return OwnershipPlanIdentity::fromManifest($this->manifest($proposal))->digest();
    }
}
