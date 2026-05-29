<?php

namespace Tests\Feature;

use App\Models\Guest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminGuestImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_preview_and_import_valid_csv_with_qr_codes(): void
    {
        Storage::fake('public');

        $admin = $this->adminUser();

        $this->actingAs($admin)
            ->post(route('admin.guests.import.process'), [
                'csv_file' => $this->csvFile(<<<CSV
name,phone_number,pass_type,allowed_entries
John Peter,0712345678,single,1
Mary Joseph,0755555555,double,2
Kimaro Family,0788888888,special,6
CSV),
                'generate_qr' => '1',
            ])
            ->assertOk()
            ->assertSee('John Peter')
            ->assertSee('Mary Joseph')
            ->assertSee('Kimaro Family')
            ->assertSee('Import valid rows');

        $this->assertDatabaseCount('guests', 0);

        $this->actingAs($admin)
            ->post(route('admin.guests.import.process'), [
                'import_action' => 'confirm',
                'generate_qr' => '1',
            ])
            ->assertRedirect(route('admin.guests.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('guests', [
            'name' => 'John Peter',
            'phone_number' => '0712345678',
            'pass_type' => Guest::PASS_SINGLE,
            'allowed_entries' => 1,
        ]);
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
    }

    public function test_import_preview_rejects_invalid_pass_type(): void
    {
        $admin = $this->adminUser();

        $this->actingAs($admin)
            ->post(route('admin.guests.import.process'), [
                'csv_file' => $this->csvFile(<<<CSV
name,phone_number,pass_type,allowed_entries
Invalid Pass,0712000000,vip,1
CSV),
            ])
            ->assertOk()
            ->assertSee('Pass type must be single, double, or special.')
            ->assertDontSee('Import valid rows');

        $this->assertDatabaseCount('guests', 0);
    }

    public function test_import_preview_rejects_special_pass_above_ten(): void
    {
        $admin = $this->adminUser();

        $this->actingAs($admin)
            ->post(route('admin.guests.import.process'), [
                'csv_file' => $this->csvFile(<<<CSV
name,phone_number,pass_type,allowed_entries
Too Large Family,0712111111,special,11
CSV),
            ])
            ->assertOk()
            ->assertSee('Allowed entries cannot be more than 10.')
            ->assertSee('Special / Family passes must have allowed_entries from 3 to 10.');

        $this->assertDatabaseCount('guests', 0);
    }

    public function test_import_preview_rejects_missing_name(): void
    {
        $admin = $this->adminUser();

        $this->actingAs($admin)
            ->post(route('admin.guests.import.process'), [
                'csv_file' => $this->csvFile(<<<CSV
name,phone_number,pass_type,allowed_entries
,0712222222,single,1
CSV),
            ])
            ->assertOk()
            ->assertSee('Name is required.');

        $this->assertDatabaseCount('guests', 0);
    }

    public function test_import_only_creates_valid_rows_from_mixed_csv(): void
    {
        Storage::fake('public');

        $admin = $this->adminUser();

        $this->actingAs($admin)
            ->post(route('admin.guests.import.process'), [
                'csv_file' => $this->csvFile(<<<CSV
name,phone_number,pass_type,allowed_entries
Valid Guest,0712333333,double,2
Invalid Guest,0712444444,special,12
CSV),
            ])
            ->assertOk()
            ->assertSee('Valid Guest')
            ->assertSee('Invalid Guest')
            ->assertSee('Allowed entries cannot be more than 10.');

        $this->actingAs($admin)
            ->post(route('admin.guests.import.process'), [
                'import_action' => 'confirm',
            ])
            ->assertRedirect(route('admin.guests.index'));

        $this->assertDatabaseHas('guests', [
            'name' => 'Valid Guest',
            'pass_type' => Guest::PASS_DOUBLE,
            'allowed_entries' => 2,
        ]);
        $this->assertDatabaseMissing('guests', [
            'name' => 'Invalid Guest',
        ]);
        $this->assertDatabaseCount('qr_codes', 0);
    }

    public function test_admin_can_download_guest_import_template(): void
    {
        $response = $this->actingAs($this->adminUser())
            ->get(route('admin.guests.import.sample'))
            ->assertOk()
            ->assertDownload('guest-import-template.csv');

        $csv = $response->streamedContent();

        $this->assertStringContainsString('name,phone_number,pass_type,allowed_entries', $csv);
        $this->assertStringContainsString('John Peter', $csv);
    }

    private function csvFile(string $content): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('guests.csv', $content);
    }

    private function adminUser(): User
    {
        return User::query()->create([
            'name' => 'Admin User',
            'email' => uniqid('admin', true).'@example.com',
            'password' => Hash::make('password'),
            'role' => User::ROLE_ADMIN,
        ]);
    }
}
