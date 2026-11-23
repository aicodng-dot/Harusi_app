<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Guest;
use App\Models\QrCode;
use App\Models\User;
use App\Services\GuestImportExcelReader;
use App\Services\GuestQrImageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\FakesGuestQrDisk;
use Tests\TestCase;

class AdminGuestImportTest extends TestCase
{
    use FakesGuestQrDisk;
    use RefreshDatabase;

    private const XLSX_MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    private const GUEST_HEADERS = ['name', 'phone_number', 'pass_type', 'allowed_entries'];

    public function test_admin_can_preview_and_import_valid_xlsx_with_qr_codes(): void
    {
        Storage::fake('public');
        $this->fakeGuestQrDisk();

        $event = Event::defaultEvent();
        $admin = $this->adminUser();

        $this->actingAs($admin)
            ->withSession(['selected_event_id' => $event->id])
            ->post(route('admin.guests.import.process'), [
                'excel_file' => $this->xlsxFile([
                    ['John Peter', '0712345678', 'single', 1],
                    ['Mary Joseph', '0755555555', 'double', 2],
                    ['Kimaro Family', '0788888888', 'special', 6],
                ]),
                'generate_qr' => '1',
            ])
            ->assertOk()
            ->assertSee('John Peter')
            ->assertSee('Mary Joseph')
            ->assertSee('Kimaro Family')
            ->assertSee('NEW')
            ->assertSee('Confirm import');

        $this->assertDatabaseCount('guests', 0);

        $this->actingAs($admin)
            ->withSession(['selected_event_id' => $event->id])
            ->post(route('admin.guests.import.process'), [
                'import_action' => 'confirm',
                'import_token' => $this->importToken(),
                'generate_qr' => '1',
            ])
            ->assertRedirect(route('admin.guests.import'))
            ->assertSessionHas('success')
            ->assertSessionHas('guest_import.result');

        $this->assertDatabaseHas('guests', [
            'event_id' => $event->id,
            'name' => 'John Peter',
            'phone_number' => '+255712345678',
            'pass_type' => Guest::PASS_SINGLE,
            'allowed_entries' => 1,
        ]);

        $john = Guest::query()->where('name', 'John Peter')->firstOrFail();
        $this->assertMatchesRegularExpression('/^\d{4}$/', $john->manual_code);
        $this->assertNotSame($john->manual_code, $john->qrCode->qr_token);

        $this->assertDatabaseHas('guests', [
            'name' => 'Mary Joseph',
            'pass_type' => Guest::PASS_DOUBLE,
            'allowed_entries' => 2,
        ]);
        $this->assertDatabaseHas('guests', [
            'name' => 'Kimaro Family',
            'pass_type' => Guest::PASS_SPECIAL,
            'allowed_entries' => 6,
        ]);
        $this->assertDatabaseCount('qr_codes', 3);

        $result = session('guest_import.result');
        $this->assertSame(3, $result['summary']['imported_new_contacts']);
        $this->assertSame(0, $result['summary']['duplicates_added']);
        $this->assertSame(3, $result['summary']['generated_qr_codes']);
    }

    public function test_import_preview_and_confirmation_preserve_special_allowances_above_ten(): void
    {
        $event = Event::defaultEvent();
        $admin = $this->adminUser();

        $this->actingAs($admin)
            ->withSession(['selected_event_id' => $event->id])
            ->post(route('admin.guests.import.process'), [
                'excel_file' => $this->xlsxFile([
                    ['Eleven Family', '0712345681', 'special', 11],
                    ['Hundred Family', '0712345682', 'special', 100],
                ]),
            ])
            ->assertOk()
            ->assertSee('Eleven Family')
            ->assertSee('Hundred Family')
            ->assertSee('Confirm import');

        $this->assertDatabaseCount('guests', 0);

        $this->actingAs($admin)
            ->withSession(['selected_event_id' => $event->id])
            ->post(route('admin.guests.import.process'), [
                'import_action' => 'confirm',
                'import_token' => $this->importToken(),
            ])
            ->assertRedirect(route('admin.guests.import'))
            ->assertSessionHasNoErrors();

        foreach (['Eleven Family' => 11, 'Hundred Family' => 100] as $name => $capacity) {
            $this->assertDatabaseHas('guests', [
                'event_id' => $event->id,
                'name' => $name,
                'pass_type' => Guest::PASS_SPECIAL,
                'allowed_entries' => $capacity,
                'used_entries' => 0,
            ]);
        }
        $this->assertSame(2, session('guest_import.result.summary.imported_new_contacts'));
        $this->assertSame(0, session('guest_import.result.summary.invalid_rows'));
    }

    public static function invalidSpecialAllowances(): array
    {
        return ['below special minimum' => [2], 'above special maximum' => [101]];
    }

    public function test_import_preview_accepts_excel_scientific_notation_phone_numbers(): void
    {
        $event = Event::defaultEvent();
        $admin = $this->adminUser();

        $this->actingAs($admin)
            ->withSession(['selected_event_id' => $event->id])
            ->post(route('admin.guests.import.process'), [
                'excel_file' => $this->xlsxFile([
                    ['Excel Phone Guest', 2.557E+11, 'double', 2],
                ]),
            ])
            ->assertOk()
            ->assertSee('Excel Phone Guest')
            ->assertSee('+255700000000')
            ->assertSee('NEW');

        $this->actingAs($admin)
            ->withSession(['selected_event_id' => $event->id])
            ->post(route('admin.guests.import.process'), [
                'import_action' => 'confirm',
                'import_token' => $this->importToken(),
            ])
            ->assertRedirect(route('admin.guests.import'));

        $this->assertDatabaseHas('guests', [
            'event_id' => $event->id,
            'name' => 'Excel Phone Guest',
            'phone_number' => '+255700000000',
            'pass_type' => Guest::PASS_DOUBLE,
            'allowed_entries' => 2,
        ]);
    }

    public function test_import_preview_detects_duplicate_contacts_using_normalized_phone_numbers(): void
    {
        $event = Event::defaultEvent();
        $admin = $this->adminUser();
        $this->guest('Anna Maro', '+255712345678', Guest::PASS_SPECIAL, 7, 3, Guest::STATUS_PARTIALLY_USED, '2804', $event);

        $this->actingAs($admin)
            ->withSession(['selected_event_id' => $event->id])
            ->post(route('admin.guests.import.process'), [
                'excel_file' => $this->xlsxFile([
                    ['Anna Maro', '0712345678', 'special', 7],
                ]),
            ])
            ->assertOk()
            ->assertSee('Duplicate contacts')
            ->assertSee('DUPLICATE')
            ->assertSee('Some contacts match an existing guest name or phone number in this event')
            ->assertSee('Existing contact')
            ->assertSee('Matched by:')
            ->assertSee('Phone number')
            ->assertSee('Code:')
            ->assertSee('2804')
            ->assertSee('Used:')
            ->assertSee('3')
            ->assertSee('Remaining:')
            ->assertSee('4')
            ->assertSee('Skip duplicate')
            ->assertSee('Add anyway');

        $this->assertDatabaseCount('guests', 1);
    }

    public function test_import_preview_detects_case_insensitive_duplicate_names_with_different_phone_numbers(): void
    {
        $event = Event::defaultEvent();
        $admin = $this->adminUser();
        $this->guest('KULWA GEORGE', '0700000000', Guest::PASS_DOUBLE, 2, 0, Guest::STATUS_ACTIVE, '2804', $event);

        $this->actingAs($admin)
            ->withSession(['selected_event_id' => $event->id])
            ->post(route('admin.guests.import.process'), [
                'excel_file' => $this->xlsxFile([
                    ['Kulwa George', '0766809685', 'double', 2],
                ]),
            ])
            ->assertOk()
            ->assertSee('DUPLICATE')
            ->assertSee('Matched by:')
            ->assertSee('Name (case-insensitive)');

        $this->actingAs($admin)
            ->withSession(['selected_event_id' => $event->id])
            ->post(route('admin.guests.import.process'), [
                'import_action' => 'confirm',
                'import_token' => $this->importToken(),
                'duplicate_actions' => [2 => 'skip'],
            ])
            ->assertRedirect(route('admin.guests.import'));

        $this->assertSame(1, Guest::query()->where('event_id', $event->id)->count());
        $this->assertSame(1, session('guest_import.result.summary.duplicates_skipped'));
    }

    public function test_matching_name_in_another_event_is_not_a_duplicate_for_selected_event(): void
    {
        $eventA = Event::defaultEvent();
        $eventB = Event::query()->create(['event_name' => 'Wedding B', 'status' => Event::STATUS_ACTIVE]);
        $admin = $this->adminUser();
        $this->guest('KULWA GEORGE', '0700000000', Guest::PASS_SINGLE, 1, 0, Guest::STATUS_ACTIVE, '2804', $eventA);

        $this->actingAs($admin)
            ->withSession(['selected_event_id' => $eventB->id])
            ->post(route('admin.guests.import.process'), [
                'excel_file' => $this->xlsxFile([
                    ['Kulwa George', '0766809685', 'single', 1],
                ]),
            ])
            ->assertOk()
            ->assertSee('NEW')
            ->assertDontSee('DUPLICATE');
    }

    public function test_same_phone_number_in_another_event_is_not_a_duplicate_for_selected_event(): void
    {
        $eventA = Event::defaultEvent();
        $eventB = Event::query()->create(['event_name' => 'Wedding B', 'status' => Event::STATUS_ACTIVE]);
        $admin = $this->adminUser();
        $this->guest('Wedding A Guest', '0712345678', Guest::PASS_SINGLE, 1, 0, Guest::STATUS_ACTIVE, '2804', $eventA);

        $this->actingAs($admin)
            ->withSession(['selected_event_id' => $eventB->id])
            ->post(route('admin.guests.import.process'), [
                'excel_file' => $this->xlsxFile([
                    ['Wedding B Guest', '255712345678', 'single', 1],
                ]),
            ])
            ->assertOk()
            ->assertSee('New contacts')
            ->assertSee('NEW')
            ->assertDontSee('DUPLICATE');
    }

    public function test_skip_duplicate_does_not_stop_new_contacts_from_importing(): void
    {
        $event = Event::defaultEvent();
        $admin = $this->adminUser();
        $this->guest('Anna Maro', '+255712345691', Guest::PASS_SPECIAL, 7, 3, Guest::STATUS_PARTIALLY_USED, '2804', $event);

        $this->actingAs($admin)
            ->withSession(['selected_event_id' => $event->id])
            ->post(route('admin.guests.import.process'), [
                'excel_file' => $this->xlsxFile([
                    ['Anna Maro', '0712345691', 'special', 7],
                    ['John Peter', '0755111222', 'single', 1],
                ]),
            ])
            ->assertOk()
            ->assertSee('DUPLICATE')
            ->assertSee('NEW');

        $this->actingAs($admin)
            ->withSession(['selected_event_id' => $event->id])
            ->post(route('admin.guests.import.process'), [
                'import_action' => 'confirm',
                'import_token' => $this->importToken(),
                'duplicate_actions' => [
                    2 => 'skip',
                ],
            ])
            ->assertRedirect(route('admin.guests.import'))
            ->assertSessionHas('guest_import.result');

        $this->assertDatabaseHas('guests', [
            'event_id' => $event->id,
            'name' => 'John Peter',
            'phone_number' => '+255755111222',
        ]);
        $this->assertSame(1, Guest::query()->where('event_id', $event->id)->where('phone_number', '+255712345691')->count());

        $result = session('guest_import.result');
        $this->assertSame(1, $result['summary']['imported_new_contacts']);
        $this->assertSame(1, $result['summary']['duplicates_skipped']);
    }

    public function test_add_anyway_creates_a_separate_guest_qr_token_and_manual_code(): void
    {
        Storage::fake('public');
        $this->fakeGuestQrDisk();

        $event = Event::defaultEvent();
        $admin = $this->adminUser();
        $existing = $this->guest('Anna Maro', '+255712345691', Guest::PASS_SPECIAL, 7, 3, Guest::STATUS_PARTIALLY_USED, '2804', $event);

        $this->actingAs($admin)
            ->withSession(['selected_event_id' => $event->id])
            ->post(route('admin.guests.import.process'), [
                'excel_file' => $this->xlsxFile([
                    ['Neema Maro', '+255712345691', 'single', 1],
                ]),
                'generate_qr' => '1',
            ])
            ->assertOk()
            ->assertSee('DUPLICATE');

        $this->actingAs($admin)
            ->withSession(['selected_event_id' => $event->id])
            ->post(route('admin.guests.import.process'), [
                'import_action' => 'confirm',
                'import_token' => $this->importToken(),
                'generate_qr' => '1',
                'duplicate_actions' => [
                    2 => 'add_anyway',
                ],
            ])
            ->assertRedirect(route('admin.guests.import'))
            ->assertSessionHas('guest_import.result');

        $added = Guest::query()->where('name', 'Neema Maro')->firstOrFail();
        $this->assertNotSame($existing->id, $added->id);
        $this->assertSame($existing->phone_number, $added->phone_number);
        $this->assertNotSame($existing->manual_code, $added->manual_code);
        $this->assertNotSame($existing->qrCode->qr_token, $added->qrCode->qr_token);
        $this->assertDatabaseCount('guests', 2);

        $result = session('guest_import.result');
        $this->assertSame(1, $result['summary']['duplicates_added']);
        $this->assertSame(1, $result['summary']['generated_qr_codes']);
    }

    public function test_new_contact_that_becomes_duplicate_before_confirmation_is_reported_as_conflict(): void
    {
        $event = Event::defaultEvent();
        $admin = $this->adminUser();

        $this->actingAs($admin)
            ->withSession(['selected_event_id' => $event->id])
            ->post(route('admin.guests.import.process'), [
                'excel_file' => $this->xlsxFile([
                    ['Race Contact', '0712777000', 'single', 1],
                ]),
            ])
            ->assertOk()
            ->assertSee('NEW');

        $this->guest('Already Added', '0712777000', Guest::PASS_SINGLE, 1, 0, Guest::STATUS_ACTIVE, '3333', $event);

        $this->actingAs($admin)
            ->withSession(['selected_event_id' => $event->id])
            ->post(route('admin.guests.import.process'), [
                'import_action' => 'confirm',
                'import_token' => $this->importToken(),
            ])
            ->assertRedirect(route('admin.guests.import'))
            ->assertSessionHas('guest_import.result');

        $this->assertDatabaseMissing('guests', ['name' => 'Race Contact']);

        $result = session('guest_import.result');
        $this->assertSame(0, $result['summary']['imported_successfully']);
        $this->assertSame(1, $result['summary']['failed_rows']);
        $this->assertStringContainsString('Duplicate conflict', $result['failed'][0]['reason']);
    }

    public function test_duplicate_bulk_action_controls_are_rendered(): void
    {
        $event = Event::defaultEvent();
        $admin = $this->adminUser();
        $this->guest('Anna Maro', '0712345678', Guest::PASS_SINGLE, 1, 0, Guest::STATUS_ACTIVE, '2804', $event);

        $this->actingAs($admin)
            ->withSession(['selected_event_id' => $event->id])
            ->post(route('admin.guests.import.process'), [
                'excel_file' => $this->xlsxFile([
                    ['Anna Maro', '712345678', 'single', 1],
                ]),
            ])
            ->assertOk()
            ->assertSee('Skip all duplicates')
            ->assertSee('Add all duplicates')
            ->assertSee('data-duplicate-bulk="skip"', false)
            ->assertSee('data-duplicate-bulk="add_anyway"', false);
    }

    public function test_import_preview_rejects_invalid_pass_type(): void
    {
        $event = Event::defaultEvent();
        $admin = $this->adminUser();

        $this->actingAs($admin)
            ->withSession(['selected_event_id' => $event->id])
            ->post(route('admin.guests.import.process'), [
                'excel_file' => $this->xlsxFile([
                    ['Invalid Pass', '0712000000', 'vip', 1],
                ]),
            ])
            ->assertOk()
            ->assertSee('Pass type must be single, double, or special.')
            ->assertDontSee('Confirm import');

        $this->assertDatabaseCount('guests', 0);
    }

    #[DataProvider('invalidSpecialAllowances')]
    public function test_import_preview_rejects_special_pass_outside_allowed_range(int $capacity): void
    {
        $event = Event::defaultEvent();
        $admin = $this->adminUser();

        $this->actingAs($admin)
            ->withSession(['selected_event_id' => $event->id])
            ->post(route('admin.guests.import.process'), [
                'excel_file' => $this->xlsxFile([
                    ['Invalid Family', '0712111111', 'special', $capacity],
                ]),
            ])
            ->assertOk()
            ->assertSee('Special / Family passes must have allowed_entries from 3 to 100.')
            ->assertDontSee('Confirm import');

        $this->assertDatabaseCount('guests', 0);
    }

    public function test_import_preview_rejects_missing_name(): void
    {
        $event = Event::defaultEvent();
        $admin = $this->adminUser();

        $this->actingAs($admin)
            ->withSession(['selected_event_id' => $event->id])
            ->post(route('admin.guests.import.process'), [
                'excel_file' => $this->xlsxFile([
                    ['', '0712222222', 'single', 1],
                ]),
            ])
            ->assertOk()
            ->assertSee('Name is required.');

        $this->assertDatabaseCount('guests', 0);
    }

    public function test_import_preview_rejects_invalid_phone_number(): void
    {
        $event = Event::defaultEvent();
        $admin = $this->adminUser();

        $this->actingAs($admin)
            ->withSession(['selected_event_id' => $event->id])
            ->post(route('admin.guests.import.process'), [
                'excel_file' => $this->xlsxFile([
                    ['Invalid Phone', '25578800001', 'single', 1],
                ]),
            ])
            ->assertOk()
            ->assertSee('Invalid phone number format.')
            ->assertDontSee('Confirm import');

        $this->assertDatabaseCount('guests', 0);
    }

    public function test_import_only_creates_valid_rows_from_mixed_xlsx(): void
    {
        Storage::fake('public');
        $this->fakeGuestQrDisk();

        $event = Event::defaultEvent();
        $admin = $this->adminUser();

        $this->actingAs($admin)
            ->withSession(['selected_event_id' => $event->id])
            ->post(route('admin.guests.import.process'), [
                'excel_file' => $this->xlsxFile([
                    ['Valid Guest', '0712333333', 'double', 2],
                    ['Invalid Guest', '0712444444', 'special', 101],
                ]),
            ])
            ->assertOk()
            ->assertSee('Valid Guest')
            ->assertSee('Invalid Guest')
            ->assertSee('Allowed entries cannot be more than 100.');

        $this->actingAs($admin)
            ->withSession(['selected_event_id' => $event->id])
            ->post(route('admin.guests.import.process'), [
                'import_action' => 'confirm',
                'import_token' => $this->importToken(),
            ])
            ->assertRedirect(route('admin.guests.import'))
            ->assertSessionHas('guest_import.result');

        $this->assertDatabaseHas('guests', [
            'name' => 'Valid Guest',
            'pass_type' => Guest::PASS_DOUBLE,
            'allowed_entries' => 2,
        ]);
        $this->assertDatabaseMissing('guests', ['name' => 'Invalid Guest']);
        $this->assertDatabaseCount('qr_codes', 0);

        $result = session('guest_import.result');
        $this->assertSame(1, $result['summary']['imported_new_contacts']);
        $this->assertSame(1, $result['summary']['invalid_rows']);
    }

    public function test_import_rolls_back_failed_guest_row_and_continues_after_qr_generation_error(): void
    {
        Storage::fake('public');
        $this->fakeGuestQrDisk();

        $event = Event::defaultEvent();
        $admin = $this->adminUser();

        $this->actingAs($admin)
            ->withSession(['selected_event_id' => $event->id])
            ->post(route('admin.guests.import.process'), [
                'excel_file' => $this->xlsxFile([
                    ['Failed QR Guest', '0712555001', 'single', 1],
                    ['Successful QR Guest', '0712555002', 'single', 1],
                ]),
                'generate_qr' => '1',
            ])
            ->assertOk()
            ->assertSee('Failed QR Guest')
            ->assertSee('Successful QR Guest');

        $qrImages = Mockery::mock(GuestQrImageService::class);
        $qrImages
            ->shouldReceive('png')
            ->once()
            ->ordered()
            ->andThrow(new RuntimeException('QR renderer failed.'));
        $qrImages
            ->shouldReceive('png')
            ->once()
            ->ordered()
            ->andReturn('fake-png-bytes');
        $this->app->instance(GuestQrImageService::class, $qrImages);

        $this->actingAs($admin)
            ->withSession(['selected_event_id' => $event->id])
            ->post(route('admin.guests.import.process'), [
                'import_action' => 'confirm',
                'import_token' => $this->importToken(),
                'generate_qr' => '1',
            ])
            ->assertRedirect(route('admin.guests.import'))
            ->assertSessionHas('guest_import.result');

        $this->assertDatabaseMissing('guests', ['name' => 'Failed QR Guest']);
        $this->assertDatabaseHas('guests', [
            'event_id' => $event->id,
            'name' => 'Successful QR Guest',
            'phone_number' => '+255712555002',
        ]);

        $successfulGuest = Guest::query()->where('name', 'Successful QR Guest')->firstOrFail();
        $this->assertDatabaseHas('qr_codes', ['guest_id' => $successfulGuest->id]);

        $result = session('guest_import.result');
        $this->assertSame(2, $result['summary']['total_rows']);
        $this->assertSame(1, $result['summary']['imported_new_contacts']);
        $this->assertSame(1, $result['summary']['failed_rows']);
        $this->assertSame(1, $result['summary']['generated_qr_codes']);
        $this->assertSame('Database error. This row was not imported.', $result['failed'][0]['reason']);
    }

    public function test_import_confirmation_rejects_invalid_duplicate_action_values(): void
    {
        $event = Event::defaultEvent();
        $admin = $this->adminUser();
        $this->guest('Anna Maro', '0712345678', Guest::PASS_SINGLE, 1, 0, Guest::STATUS_ACTIVE, '2804', $event);

        $this->actingAs($admin)
            ->withSession(['selected_event_id' => $event->id])
            ->post(route('admin.guests.import.process'), [
                'excel_file' => $this->xlsxFile([
                    ['Anna Maro', '0712345678', 'single', 1],
                ]),
            ])
            ->assertOk();

        $this->actingAs($admin)
            ->withSession(['selected_event_id' => $event->id])
            ->post(route('admin.guests.import.process'), [
                'import_action' => 'confirm',
                'import_token' => $this->importToken(),
                'duplicate_actions' => [2 => 'merge'],
            ])
            ->assertSessionHasErrors('duplicate_actions.2');
    }

    public function test_import_cannot_be_submitted_twice_with_the_same_preview_token(): void
    {
        $event = Event::defaultEvent();
        $admin = $this->adminUser();

        $this->actingAs($admin)
            ->withSession(['selected_event_id' => $event->id])
            ->post(route('admin.guests.import.process'), [
                'excel_file' => $this->xlsxFile([
                    ['John Peter', '0712345678', 'single', 1],
                ]),
            ])
            ->assertOk();

        $token = $this->importToken();

        $this->actingAs($admin)
            ->withSession(['selected_event_id' => $event->id])
            ->post(route('admin.guests.import.process'), [
                'import_action' => 'confirm',
                'import_token' => $token,
            ])
            ->assertRedirect(route('admin.guests.import'));

        $this->actingAs($admin)
            ->withSession(['selected_event_id' => $event->id])
            ->post(route('admin.guests.import.process'), [
                'import_action' => 'confirm',
                'import_token' => $token,
            ])
            ->assertRedirect(route('admin.guests.import'))
            ->assertSessionHasErrors('excel_file');

        $this->assertSame(1, Guest::query()->where('name', 'John Peter')->count());
    }

    public function test_import_matches_capitalized_spaced_and_reordered_headers(): void
    {
        $event = Event::defaultEvent();
        $admin = $this->adminUser();

        $this->actingAs($admin)
            ->withSession(['selected_event_id' => $event->id])
            ->post(route('admin.guests.import.process'), [
                'excel_file' => $this->xlsxFile(
                    [[1, 'Header Guest', 'single', '0766000000']],
                    ['Allowed Entries', 'NAME', 'Pass Type', 'Phone Number'],
                ),
            ])
            ->assertOk()
            ->assertSee('Header Guest')
            ->assertSee('+255766000000')
            ->assertSee('NEW')
            ->assertSee('Confirm import');
    }

    public function test_import_rejects_workbook_with_a_missing_required_column(): void
    {
        $event = Event::defaultEvent();

        $this->actingAs($this->adminUser())
            ->withSession(['selected_event_id' => $event->id])
            ->post(route('admin.guests.import.process'), [
                'excel_file' => $this->xlsxFile(
                    [['Missing Column Guest', '0712000000', 'single']],
                    ['name', 'phone_number', 'pass_type'],
                ),
            ])
            ->assertOk()
            ->assertSee('allowed_entries')
            ->assertDontSee('Confirm import');

        $this->assertNull(session('guest_import.token'));
        $this->assertDatabaseCount('guests', 0);
    }

    public function test_import_rejects_an_empty_workbook(): void
    {
        $event = Event::defaultEvent();

        $this->actingAs($this->adminUser())
            ->withSession(['selected_event_id' => $event->id])
            ->post(route('admin.guests.import.process'), [
                'excel_file' => $this->xlsxFile([], []),
            ])
            ->assertOk()
            ->assertDontSee('Confirm import');

        $this->assertNull(session('guest_import.token'));
        $this->assertDatabaseCount('guests', 0);
    }

    public function test_import_rejects_a_header_only_workbook(): void
    {
        $event = Event::defaultEvent();

        $this->actingAs($this->adminUser())
            ->withSession(['selected_event_id' => $event->id])
            ->post(route('admin.guests.import.process'), [
                'excel_file' => $this->xlsxFile([]),
            ])
            ->assertOk()
            ->assertDontSee('Confirm import');

        $this->assertNull(session('guest_import.token'));
        $this->assertDatabaseCount('guests', 0);
    }

    public function test_import_rejects_a_corrupt_xlsx_workbook(): void
    {
        $event = Event::defaultEvent();
        $corruptWorkbook = UploadedFile::fake()
            ->createWithContent('guests.xlsx', 'This is not an Open XML workbook.')
            ->mimeType(self::XLSX_MIME);

        $this->actingAs($this->adminUser())
            ->withSession(['selected_event_id' => $event->id])
            ->post(route('admin.guests.import.process'), [
                'excel_file' => $corruptWorkbook,
            ])
            ->assertOk()
            ->assertSee('The Excel workbook could not be opened.')
            ->assertDontSee('Confirm import');

        $this->assertNull(session('guest_import.token'));
        $this->assertDatabaseCount('guests', 0);
    }

    public function test_import_rejects_workbooks_over_the_guest_row_limit(): void
    {
        $event = Event::defaultEvent();
        $rows = [];

        for ($index = 1; $index <= GuestImportExcelReader::MAX_ROWS + 1; $index++) {
            $rows[] = [
                'Large Import Guest '.$index,
                '0712'.str_pad((string) $index, 6, '0', STR_PAD_LEFT),
                'single',
                1,
            ];
        }

        $this->actingAs($this->adminUser())
            ->withSession(['selected_event_id' => $event->id])
            ->post(route('admin.guests.import.process'), [
                'excel_file' => $this->xlsxFile($rows),
            ])
            ->assertOk()
            ->assertSee('The Excel worksheet cannot contain more than 5,000 guest rows.')
            ->assertDontSee('Confirm import');

        $this->assertNull(session('guest_import.token'));
        $this->assertDatabaseCount('guests', 0);
    }

    public function test_import_rejects_a_valid_workbook_with_an_invalid_extension(): void
    {
        $event = Event::defaultEvent();

        $this->actingAs($this->adminUser())
            ->withSession(['selected_event_id' => $event->id])
            ->post(route('admin.guests.import.process'), [
                'excel_file' => $this->xlsxFile(
                    [['Wrong Extension', '0712000000', 'single', 1]],
                    self::GUEST_HEADERS,
                    'guests.csv',
                ),
            ])
            ->assertStatus(302)
            ->assertSessionHasErrors('excel_file');

        $this->assertDatabaseCount('guests', 0);
    }

    public function test_import_requires_an_excel_file(): void
    {
        $event = Event::defaultEvent();

        $this->actingAs($this->adminUser())
            ->withSession(['selected_event_id' => $event->id])
            ->post(route('admin.guests.import.process'))
            ->assertStatus(302)
            ->assertSessionHasErrors('excel_file');
    }

    public function test_import_preserves_phone_text_and_accepts_supported_pass_types(): void
    {
        $event = Event::defaultEvent();
        $admin = $this->adminUser();

        $this->actingAs($admin)
            ->withSession(['selected_event_id' => $event->id])
            ->post(route('admin.guests.import.process'), [
                'excel_file' => $this->xlsxFile([
                    ['Leading Zero Guest', '0712345678', 'single', 1],
                    ['Plus Prefix Guest', '+255755555555', 'double', 2],
                    ['Familia Ülomi & Co.', '255788888888', 'special', 10],
                ]),
            ])
            ->assertOk()
            ->assertSee('Leading Zero Guest')
            ->assertSee('Plus Prefix Guest')
            ->assertSee('Familia Ülomi &amp; Co.', false)
            ->assertSee('Confirm import');

        $this->actingAs($admin)
            ->withSession(['selected_event_id' => $event->id])
            ->post(route('admin.guests.import.process'), [
                'import_action' => 'confirm',
                'import_token' => $this->importToken(),
            ])
            ->assertRedirect(route('admin.guests.import'));

        $this->assertDatabaseHas('guests', [
            'event_id' => $event->id,
            'name' => 'Leading Zero Guest',
            'phone_number' => '+255712345678',
            'pass_type' => Guest::PASS_SINGLE,
            'allowed_entries' => 1,
        ]);
        $this->assertDatabaseHas('guests', [
            'event_id' => $event->id,
            'name' => 'Plus Prefix Guest',
            'phone_number' => '+255755555555',
            'pass_type' => Guest::PASS_DOUBLE,
            'allowed_entries' => 2,
        ]);
        $this->assertDatabaseHas('guests', [
            'event_id' => $event->id,
            'name' => 'Familia Ülomi & Co.',
            'phone_number' => '+255788888888',
            'pass_type' => Guest::PASS_SPECIAL,
            'allowed_entries' => 10,
        ]);
    }

    public function test_unauthenticated_users_cannot_access_guest_import_or_template(): void
    {
        Event::defaultEvent();

        $this->get(route('admin.guests.import'))
            ->assertRedirect(route('login'));

        $this->post(route('admin.guests.import.process'), [
            'excel_file' => $this->xlsxFile([
                ['Unauthenticated Guest', '0712000000', 'single', 1],
            ]),
        ])->assertRedirect(route('login'));

        $this->get(route('admin.guests.import.sample'))
            ->assertRedirect(route('login'));
    }

    public function test_scanner_users_cannot_import_contacts(): void
    {
        $event = Event::defaultEvent();
        $scanner = User::query()->create([
            'name' => 'Scanner User',
            'email' => uniqid('scanner', true).'@example.com',
            'password' => Hash::make('password'),
            'role' => User::ROLE_SCANNER,
            'event_id' => $event->id,
        ]);

        $this->actingAs($scanner)
            ->withSession(['selected_event_id' => $event->id])
            ->get(route('admin.guests.import'))
            ->assertForbidden();

        $this->actingAs($scanner)
            ->withSession(['selected_event_id' => $event->id])
            ->post(route('admin.guests.import.process'), [
                'excel_file' => $this->xlsxFile([
                    ['Forbidden Guest', '0712000000', 'single', 1],
                ]),
            ])
            ->assertForbidden();

        $this->actingAs($scanner)
            ->withSession(['selected_event_id' => $event->id])
            ->get(route('admin.guests.import.sample'))
            ->assertForbidden();
    }

    public function test_admin_can_download_guest_import_template(): void
    {
        $response = $this->actingAs($this->adminUser())
            ->get(route('admin.guests.import.sample'))
            ->assertOk()
            ->assertHeader('content-type', self::XLSX_MIME)
            ->assertDownload('guest_import_template.xlsx');

        $templateFile = UploadedFile::fake()
            ->createWithContent('guest_import_template.xlsx', $response->streamedContent())
            ->mimeType(self::XLSX_MIME);
        $workbook = (new XlsxReader)->load($templateFile->getRealPath());
        $sheet = $workbook->getActiveSheet();

        $this->assertSame('Guests', $sheet->getTitle());
        $this->assertSame(
            self::GUEST_HEADERS,
            $sheet->rangeToArray('A1:D1', null, true, false)[0],
        );
        $this->assertSame('John Peter', $sheet->getCell('A2')->getValue());
        $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('B2')->getDataType());
        $this->assertSame('0712345678', $sheet->getCell('B2')->getValue());
        $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('D2')->getDataType());
        $this->assertSame(1, $sheet->getCell('D2')->getValue());

        $workbook->disconnectWorksheets();
    }

    /**
     * @param  list<list<scalar|null>>  $rows
     * @param  list<string>  $headers
     */
    private function xlsxFile(
        array $rows,
        array $headers = self::GUEST_HEADERS,
        string $filename = 'guests.xlsx',
    ): UploadedFile {
        $workbook = new Spreadsheet;
        $sheet = $workbook->getActiveSheet();
        $sheet->setTitle('Guests');

        foreach ($headers as $columnIndex => $header) {
            $coordinate = Coordinate::stringFromColumnIndex($columnIndex + 1).'1';
            $sheet->setCellValueExplicit($coordinate, $header, DataType::TYPE_STRING);
        }

        foreach ($rows as $rowIndex => $row) {
            foreach (array_values($row) as $columnIndex => $value) {
                $coordinate = Coordinate::stringFromColumnIndex($columnIndex + 1).($rowIndex + 2);

                if (is_string($value)) {
                    $sheet->setCellValueExplicit($coordinate, $value, DataType::TYPE_STRING);
                } else {
                    $sheet->setCellValue($coordinate, $value);
                }
            }
        }

        ob_start();

        try {
            (new XlsxWriter($workbook))->save('php://output');
            $contents = ob_get_clean();
        } catch (\Throwable $exception) {
            ob_end_clean();

            throw $exception;
        } finally {
            $workbook->disconnectWorksheets();
        }

        return UploadedFile::fake()
            ->createWithContent($filename, (string) $contents)
            ->mimeType(self::XLSX_MIME);
    }

    private function importToken(): string
    {
        return (string) session('guest_import.token');
    }

    private function adminUser(): User
    {
        Event::defaultEvent();

        return User::query()->create([
            'name' => 'Admin User',
            'email' => uniqid('admin', true).'@example.com',
            'password' => Hash::make('password'),
            'role' => User::ROLE_ADMIN,
        ]);
    }

    private function guest(
        string $name,
        string $phone,
        string $passType,
        int $allowedEntries,
        int $usedEntries = 0,
        string $status = Guest::STATUS_ACTIVE,
        ?string $manualCode = null,
        ?Event $event = null,
    ): Guest {
        $event ??= Event::defaultEvent();

        $guest = Guest::query()->create([
            'event_id' => $event->id,
            'name' => $name,
            'phone_number' => $phone,
            'pass_type' => $passType,
            'allowed_entries' => $allowedEntries,
            'used_entries' => $usedEntries,
            'status' => $status,
            'manual_code' => $manualCode,
        ]);

        QrCode::query()->create([
            'guest_id' => $guest->id,
            'qr_token' => Guest::makeSecureToken(),
            'is_active' => true,
            'generated_at' => now(),
        ]);

        return $guest->fresh('qrCode');
    }
}
