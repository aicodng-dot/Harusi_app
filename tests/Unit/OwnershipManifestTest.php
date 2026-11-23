<?php

namespace Tests\Unit;

use App\Services\Tenancy\OwnershipManifest;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class OwnershipManifestTest extends TestCase
{
    public function test_valid_proposal_preserves_explicit_values_without_exposing_them_in_json(): void
    {
        $proposal = $this->proposal();
        $proposal['workspaces'][0]['name'] = ' Private sherehe — familia 🌺 ';
        $proposal['workspaces'][] = [
            'reference' => 'second_workspace-2', 'name' => 'Second proposal',
            'owner_user_ids' => [7, 9], 'event_ids' => [13, PHP_INT_MAX],
        ];
        $proposal['platform_admin_user_ids'] = [7, PHP_INT_MAX];
        $manifest = OwnershipManifest::fromJson($this->encode($proposal));

        $this->assertSame($proposal['workspaces'], $manifest->workspaces());
        $this->assertSame([7, PHP_INT_MAX], $manifest->platformAdminUserIds());
        $this->assertSame('{}', json_encode($manifest, JSON_THROW_ON_ERROR));
        $this->assertSame([], get_object_vars($manifest));
        $copy = $manifest->workspaces();
        $copy[0]['owner_user_ids'][] = 42;
        $copy[0]['name'] = 'Changed outside immutable proposal';
        $this->assertSame($proposal['workspaces'], $manifest->workspaces());
    }

    public function test_empty_proposal_is_valid_without_inferred_workspaces_or_platform_grants(): void
    {
        $manifest = OwnershipManifest::fromJson('{"schema_version":1,"workspaces":[],"platform_admin_user_ids":[]}');

        $this->assertSame([], $manifest->workspaces());
        $this->assertSame([], $manifest->platformAdminUserIds());
    }

    public function test_object_property_order_and_json_escape_spelling_do_not_change_meaning(): void
    {
        // Build the escaped name with the native encoder to avoid testing PHP quoting.
        $name = 'Family "quotes", commas, braces {} and slash /';
        $json = str_replace('NAME_VALUE', json_encode($name, JSON_THROW_ON_ERROR),
            '{"platform_admin_user_ids":[],"workspaces":[{"event_ids":[11],"owner_user_ids":[7],"name":NAME_VALUE,"refe\\u0072ence":"legacy-family"}],"schema_version":1}');
        $manifest = OwnershipManifest::fromJson($json);

        $this->assertSame('legacy-family', $manifest->workspaces()[0]['reference']);
        $this->assertSame($name, $manifest->workspaces()[0]['name']);
    }

    #[DataProvider('invalidJsonDocuments')]
    public function test_malformed_ambiguous_or_wrongly_shaped_json_is_rejected_privately(string $json): void
    {
        $this->assertInvalid($json);
    }

    public static function invalidJsonDocuments(): array
    {
        $group = '{"reference":"family","name":"Private name marker","owner_user_ids":[7],"event_ids":[11]}';
        $prefix = '{"schema_version":1,"workspaces":[';
        $suffix = '],"platform_admin_user_ids":[]}';

        return [
            'empty input' => [''],
            'truncated document' => ['{"schema_version":1'],
            'trailing data' => [$prefix.$group.$suffix.' true'],
            'comment' => ['/* private-comment */'.$prefix.$group.$suffix],
            'invalid UTF-8' => [str_replace('Private name marker', "\xFF", $prefix.$group.$suffix)],
            'unpaired surrogate' => [str_replace('Private name marker', '\\ud800', $prefix.$group.$suffix)],
            'null root' => ['null'],
            'list root' => ['[]'],
            'empty object root' => ['{}'],
            'object workspace list' => ['{"schema_version":1,"workspaces":{},"platform_admin_user_ids":[]}'],
            'object platform list' => ['{"schema_version":1,"workspaces":[],"platform_admin_user_ids":{}}'],
            'numeric keyed group object' => ['{"schema_version":1,"workspaces":{"0":'.$group.'},"platform_admin_user_ids":[]}'],
            'list group' => [$prefix.'["family","Private name marker",[7],[11]]'.$suffix],
            'null group' => [$prefix.'null'.$suffix],
            'extra top field' => ['{"schema_version":1,"workspaces":[],"platform_admin_user_ids":[],"private-secret-marker":1}'],
            'missing top field' => ['{"schema_version":1,"workspaces":[]}'],
            'float schema version' => ['{"schema_version":1.0,"workspaces":[],"platform_admin_user_ids":[]}'],
            'string schema version' => ['{"schema_version":"1","workspaces":[],"platform_admin_user_ids":[]}'],
            'boolean schema version' => ['{"schema_version":true,"workspaces":[],"platform_admin_user_ids":[]}'],
            'unsupported schema version' => ['{"schema_version":2,"workspaces":[],"platform_admin_user_ids":[]}'],
            'duplicate top key' => ['{"schema_version":1,"schema_version":1,"workspaces":[],"platform_admin_user_ids":[]}'],
            'escaped equivalent top key' => ['{"schema_version":1,"schema_ve\\u0072sion":1,"workspaces":[],"platform_admin_user_ids":[]}'],
            'duplicate workspace key' => [$prefix.str_replace('"name":', '"name":"First private name","name":', $group).$suffix],
            'escaped equivalent workspace key' => [$prefix.str_replace('"name":', '"na\\u006de":"First private name","name":', $group).$suffix],
            'duplicate unknown key' => ['{"schema_version":1,"workspaces":[],"platform_admin_user_ids":[],"private":1,"pri\\u0076ate":2}'],
            'object owner list' => [$prefix.str_replace('[7]', '{"0":7}', $group).$suffix],
            'object event list' => [$prefix.str_replace('[11]', '{"0":11}', $group).$suffix],
            'too deep' => ['{"schema_version":1,"workspaces":[],"platform_admin_user_ids":[],"private":[[[[[[[[1]]]]]]]]}'],
        ];
    }

    #[DataProvider('invalidGroupValues')]
    public function test_workspace_values_must_be_explicit_and_unambiguous(string $field, mixed $value): void
    {
        $proposal = $this->proposal();
        $proposal['workspaces'][0][$field] = $value;
        $this->assertInvalid($this->encode($proposal));
    }

    public static function invalidGroupValues(): array
    {
        return [
            'blank reference' => ['reference', ''],
            'uppercase reference' => ['reference', 'Family'],
            'leading number reference' => ['reference', '1family'],
            'whitespace reference' => ['reference', 'family name'],
            'unicode reference' => ['reference', 'familía'],
            'long reference' => ['reference', str_repeat('a', 65)],
            'trailing newline reference' => ['reference', "family\n"],
            'numeric reference' => ['reference', 1],
            'empty name' => ['name', ''],
            'spaces name' => ['name', '   '],
            'unicode whitespace name' => ['name', "\u{00A0}\u{2003}\u{2028}"],
            'newline name' => ['name', "Private\nname"],
            'tab name' => ['name', "Private\tname"],
            'null byte name' => ['name', "Private\0name"],
            'C1 control name' => ['name', "Private\u{0085}name"],
            'long Unicode name' => ['name', str_repeat('界', 256)],
            'numeric name' => ['name', 123],
            'no owners' => ['owner_user_ids', []],
            'no events' => ['event_ids', []],
            'null owners' => ['owner_user_ids', null],
            'null events' => ['event_ids', null],
        ];
    }

    #[DataProvider('invalidIds')]
    public function test_all_id_lists_reject_non_integer_ids(string $field, mixed $id): void
    {
        $proposal = $this->proposal();
        if ($field === 'platform_admin_user_ids') {
            $proposal[$field] = [$id];
        } else {
            $proposal['workspaces'][0][$field] = [$id];
        }
        $this->assertInvalid($this->encode($proposal));
    }

    public static function invalidIds(): array
    {
        $cases = [];
        foreach (['owner_user_ids', 'event_ids', 'platform_admin_user_ids'] as $field) {
            foreach (['zero' => 0, 'negative' => -1, 'string' => '7', 'float' => 7.5, 'true' => true, 'false' => false, 'null' => null, 'list' => [7], 'object' => (object) ['id' => 7]] as $label => $value) {
                $cases[$field.' '.$label] = [$field, $value];
            }
        }

        return $cases;
    }

    public function test_float_notation_and_overflow_cannot_be_coerced_to_integer_ids(): void
    {
        $json = $this->encode($this->proposal());
        foreach (['7.0', '7e0', (string) PHP_INT_MAX.'0'] as $numericToken) {
            $this->assertInvalid(str_replace('[7]', '['.$numericToken.']', $json));
        }
    }

    public function test_duplicate_memberships_event_mappings_references_and_platform_ids_are_rejected(): void
    {
        $proposal = $this->proposal();
        foreach (['owner_user_ids', 'event_ids'] as $field) {
            $invalid = $proposal;
            $invalid['workspaces'][0][$field][] = $invalid['workspaces'][0][$field][0];
            $this->assertInvalid($this->encode($invalid));
        }
        $invalid = $proposal;
        $invalid['platform_admin_user_ids'] = [7, 7];
        $this->assertInvalid($this->encode($invalid));
        $invalid = $proposal;
        $invalid['workspaces'][] = array_replace($proposal['workspaces'][0], ['reference' => 'second-family']);
        $this->assertInvalid($this->encode($invalid));
        $invalid['workspaces'][1]['event_ids'] = [13];
        $invalid['workspaces'][1]['reference'] = 'legacy-family';
        $this->assertInvalid($this->encode($invalid));
    }

    public function test_group_fields_are_exact_even_when_a_missing_field_has_a_similar_replacement(): void
    {
        $proposal = $this->proposal();
        foreach (['reference', 'name', 'owner_user_ids', 'event_ids'] as $field) {
            $invalid = $proposal;
            unset($invalid['workspaces'][0][$field]);
            $this->assertInvalid($this->encode($invalid));
            $invalid['workspaces'][0][$field.'s'] = $proposal['workspaces'][0][$field];
            $this->assertInvalid($this->encode($invalid));
        }
        $proposal['workspaces'][0]['private-extra-field'] = 'private-secret-marker';
        $this->assertInvalid($this->encode($proposal));
    }

    public function test_names_and_references_accept_the_exact_character_limits(): void
    {
        $proposal = $this->proposal();
        $proposal['workspaces'][0]['name'] = str_repeat('界', 255);
        $proposal['workspaces'][0]['reference'] = str_repeat('a', 64);
        $manifest = OwnershipManifest::fromJson($this->encode($proposal));

        $this->assertSame($proposal['workspaces'], $manifest->workspaces());
    }

    public function test_json_size_boundary_is_measured_in_bytes_and_checked_by_the_value_object(): void
    {
        $json = $this->encode($this->proposal());
        $padded = $json.str_repeat(' ', OwnershipManifest::MAX_BYTES - strlen($json));
        $this->assertSame($this->proposal()['workspaces'], OwnershipManifest::fromJson($padded)->workspaces());
        $this->assertInvalid($padded.' ');
    }

    public function test_workspace_limit_allows_1000_and_rejects_1001_without_owner_inference(): void
    {
        $proposal = $this->proposal();
        $proposal['workspaces'] = [];
        for ($index = 1; $index <= OwnershipManifest::MAX_WORKSPACES; $index++) {
            $proposal['workspaces'][] = [
                'reference' => 'family-'.$index, 'name' => 'Family', 'owner_user_ids' => [7], 'event_ids' => [$index],
            ];
        }
        $this->assertCount(1000, OwnershipManifest::fromJson($this->encode($proposal))->workspaces());
        $proposal['workspaces'][] = [
            'reference' => 'excess-family', 'name' => 'Excess family', 'owner_user_ids' => [7], 'event_ids' => [1001],
        ];
        $this->assertInvalid($this->encode($proposal));
    }

    public function test_id_limit_counts_all_occurrences_including_owners_repeated_between_groups(): void
    {
        $proposal = $this->proposal();
        $proposal['workspaces'][0]['owner_user_ids'] = range(1, 4999);
        $proposal['workspaces'][0]['event_ids'] = range(1, 5000);
        $proposal['platform_admin_user_ids'] = [1];
        $manifest = OwnershipManifest::fromJson($this->encode($proposal));
        $this->assertCount(4999, $manifest->workspaces()[0]['owner_user_ids']);
        $this->assertCount(5000, $manifest->workspaces()[0]['event_ids']);
        $proposal['platform_admin_user_ids'][] = 2;
        $this->assertInvalid($this->encode($proposal));

        $proposal['platform_admin_user_ids'] = [];
        $proposal['workspaces'][] = [
            'reference' => 'second-family', 'name' => 'Repeated owner', 'owner_user_ids' => [1], 'event_ids' => [5001],
        ];
        $this->assertInvalid($this->encode($proposal));
    }

    private function proposal(): array
    {
        return [
            'schema_version' => 1,
            'workspaces' => [[
                'reference' => 'legacy-family', 'name' => 'Private name marker',
                'owner_user_ids' => [7], 'event_ids' => [11],
            ]],
            'platform_admin_user_ids' => [],
        ];
    }

    private function encode(array $proposal): string
    {
        return json_encode($proposal, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private function assertInvalid(string $json): void
    {
        $caught = null;
        try {
            OwnershipManifest::fromJson($json);
        } catch (InvalidArgumentException $exception) {
            $caught = $exception;
        }
        $this->assertNotNull($caught, 'Invalid ownership input must be rejected.');
        $this->assertSame('Invalid ownership manifest.', $caught->getMessage());
        $this->assertNull($caught->getPrevious());
    }
}
