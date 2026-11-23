<?php

namespace Tests\Feature;

use App\Models\Checkin;
use App\Models\Event;
use App\Models\Guest;
use App\Models\QrCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CsvExportSafetyTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('downloads')]
    public function test_all_csv_downloads_neutralize_formula_cells_without_changing_records(
        string $route,
        string $filename,
        int $columns,
    ): void {
        $this->travelTo(now()->setDate(2026, 10, 19)->setTime(12, 0));
        [$admin, $event, $guest] = $this->fixture('=1+2";,@SUM(1,2)');
        $before = $this->snapshot();
        $response = $this->actingAs($admin)->withSession(['selected_event_id' => $event->id])
            ->get(route($route))->assertOk()->assertDownload($filename.'2026-10-19-120000.csv');
        $this->assertSame('text/csv; charset=UTF-8', $response->headers->get('Content-Type'));
        $csv = $response->streamedContent();
        $rows = $this->parse($csv);
        $this->assertCount(2, $rows);
        $this->assertCount($columns, $rows[0]);
        $this->assertCount($columns, $rows[1]);
        $row = array_combine($rows[0], $rows[1]);
        $this->assertSame("\t".$guest->name, $row['Guest name']);
        $this->assertSame("\t+255712345678", $row['Phone number']);
        if (isset($row['Gate name'])) {
            $this->assertSame("\t@SUM(1,2)", $row['Gate name']);
            $this->assertSame("\t+Scanner", $row['Scanner user']);
            $this->assertSame("\t-1+1", $row['IP address']);
        } else {
            $this->assertSame('3', $row['Allowed entries']);
            $this->assertSame('1', $row['Used entries']);
            $this->assertSame('2', $row['Remaining entries']);
            $this->assertSame(Guest::STATUS_PARTIALLY_USED, $row['Status']);
        }
        $this->assertStringContainsString("\"\t", $csv);
        $this->assertStringNotContainsString('Other event guest', $csv);
        $this->assertSame($csv, $this->get(route($route))->assertOk()->streamedContent());
        $this->assertSame($before, $this->snapshot());
        $this->assertSame('0042', $guest->fresh()->manual_code);
        $this->assertSame(str_repeat('a', 64), $guest->fresh('qrCode')->qrCode->qr_token);
    }

    #[DataProvider('cellValues')]
    public function test_streamed_cells_round_trip_with_documented_spreadsheet_prefix(string $value, bool $guarded): void
    {
        [$admin, $event] = $this->fixture($value);
        $before = $this->snapshot();
        $csv = $this->actingAs($admin)->withSession(['selected_event_id' => $event->id])
            ->get(route('admin.reports.export.guest-list'))->assertOk()->streamedContent();
        $rows = $this->parse($csv);
        $this->assertCount(2, $rows);
        $this->assertCount(8, $rows[1]);
        $this->assertSame(($guarded ? "\t" : '').$value, $rows[1][0]);
        $this->assertSame($before, $this->snapshot());
    }

    #[DataProvider('downloads')]
    public function test_csv_downloads_retain_existing_browser_authorization(string $route): void
    {
        [$admin, $event] = $this->fixture('Normal guest');
        $this->get(route($route))->assertRedirect(route('login'));
        $scanner = User::query()->where('role', User::ROLE_SCANNER)->firstOrFail();
        $this->actingAs($scanner)->withSession(['selected_event_id' => $event->id])->get(route($route))->assertForbidden();
        $admin->update(['is_active' => false]);
        $this->actingAs($admin)->get(route($route))->assertRedirect(route('login'));
    }

    public static function downloads(): array
    {
        return [
            'checkins' => ['admin.checkins.export', 'checkins-', 11],
            'guest list' => ['admin.reports.export.guest-list', 'guest-list-', 8],
            'checked in' => ['admin.reports.export.checked-in-guests', 'checked-in-guests-', 8],
            'remaining' => ['admin.reports.export.remaining-guests', 'remaining-guests-', 8],
            'invalid scans' => ['admin.reports.export.invalid-scans', 'invalid-scan-attempts-', 8],
        ];
    }

    public static function cellValues(): array
    {
        return [
            'equals' => ['=1+2', true],
            'plus' => ['+1+2', true],
            'minus' => ['-1+2', true],
            'at' => ['@SUM(1,2)', true],
            'tab' => ["\t=1", true],
            'ordinary tab prefix' => ["\tMgeni", true],
            'carriage return' => ["\r=1", true],
            'line feed' => ["\n=1", true],
            'null prefix' => ["\0=1", true],
            'space prefix' => ['  =1', true],
            'unicode space' => ["\u{00A0}+1", true],
            'unicode marker' => ["\u{FEFF}=1", true],
            'fullwidth equals' => ['＝1+2', true],
            'fullwidth plus' => ['＋1+2', true],
            'fullwidth minus' => ['－1+2', true],
            'fullwidth at' => ['＠SUM(1,2)', true],
            'quoted breakout' => ['=1";,@SUM(1,2)', true],
            'ordinary Unicode' => ['Mgeni العربية 😀', false],
            'ordinary leading space' => ['  Mgeni', false],
            'ordinary apostrophe' => ["'Mgeni", false],
            'embedded formula text' => ['Mgeni =1+2', false],
            'comma quote slash newline' => ["Mgeni, \"quoted\" \\\"\nnext", false],
            'leading zero text' => ['0042', false],
        ];
    }

    private function fixture(string $name): array
    {
        Event::defaultEvent();
        $event = Event::query()->create(['event_name' => 'CSV selected event', 'status' => Event::STATUS_ACTIVE]);
        $other = Event::query()->create(['event_name' => 'CSV other event', 'status' => Event::STATUS_ACTIVE]);
        $admin = User::query()->create(['name' => 'CSV administrator', 'email' => 'csv-admin@example.invalid',
            'password' => Hash::make('password'), 'role' => User::ROLE_ADMIN]);
        $scanner = User::query()->create(['name' => '+Scanner', 'email' => 'csv-scanner@example.invalid',
            'password' => Hash::make('password'), 'role' => User::ROLE_SCANNER, 'event_id' => $event->id]);
        $guest = Guest::query()->create(['event_id' => $event->id, 'manual_code' => '0042', 'name' => $name,
            'phone_number' => '+255712345678', 'pass_type' => Guest::PASS_SPECIAL, 'allowed_entries' => 3, 'used_entries' => 1]);
        QrCode::query()->create(['guest_id' => $guest->id, 'qr_token' => str_repeat('a', 64), 'is_active' => true, 'generated_at' => now()]);
        Checkin::query()->create(['event_id' => $event->id, 'guest_id' => $guest->id, 'qr_code_id' => $guest->qrCode->id,
            'user_id' => $scanner->id, 'gate_name' => '@SUM(1,2)', 'entries_added' => 0, 'used_entries_after_scan' => 1,
            'remaining_entries_after_scan' => 2, 'scan_result' => Checkin::RESULT_INVALID, 'ip_address' => '-1+1',
            'checked_in_at' => now()]);
        $decoy = Guest::query()->create(['event_id' => $other->id, 'manual_code' => '0042', 'name' => 'Other event guest',
            'phone_number' => '+255712345679', 'pass_type' => Guest::PASS_SPECIAL, 'allowed_entries' => 3, 'used_entries' => 1]);
        Checkin::query()->create(['event_id' => $other->id, 'guest_id' => $decoy->id, 'user_id' => $scanner->id,
            'scan_result' => Checkin::RESULT_INVALID, 'checked_in_at' => now()]);

        return [$admin, $event, $guest];
    }

    private function parse(string $csv): array
    {
        $handle = fopen('php://temp', 'w+');
        fwrite($handle, $csv);
        rewind($handle);
        $rows = [];
        while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            $rows[] = $row;
        }
        fclose($handle);

        return $rows;
    }

    private function snapshot(): array
    {
        $snapshot = [];
        foreach (Schema::getTableListing(null, false) as $table) {
            $rows = array_map(fn (object $row): array => (array) $row, DB::table($table)->get()->all());
            usort($rows, fn (array $a, array $b): int => strcmp(serialize($a), serialize($b)));
            $snapshot[$table] = $rows;
        }
        ksort($snapshot);

        return $snapshot;
    }
}
