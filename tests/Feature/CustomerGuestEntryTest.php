<?php

namespace Tests\Feature;

use App\Models\Guest;
use App\Models\QrCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Concerns\FakesGuestQrDisk;
use Tests\Feature\Invitations\InvitationFixtures;
use Tests\TestCase;

/**
 * Add Guests without a spreadsheet: typed rows, pasted names, pasted details, phone
 * contacts (client side, same bulk endpoint) and the improved Excel/CSV import. Every path
 * ends in CustomerGuests::create(), so the guests and credentials are identical.
 */
class CustomerGuestEntryTest extends TestCase
{
    use FakesGuestQrDisk;
    use InvitationFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeGuestQrDisk();
    }

    private function eventGuests(int $event = 101): array
    {
        return Guest::query()->where('event_id', $event)->orderBy('id')->get(['name', 'phone_number', 'pass_type', 'allowed_entries'])->map(fn ($g) => $g->only(['name', 'phone_number', 'pass_type', 'allowed_entries']))->all();
    }

    public function test_add_page_offers_every_method_and_only_to_the_event_owner(): void
    {
        $f = $this->invitationFixture();
        $this->get(route('customer.events.guests.create', ['event' => 101]))->assertRedirect(route('login'));
        $this->actingAs($f['admin'])->get(route('customer.events.guests.create', ['event' => 101]))->assertForbidden();
        $this->actingAs($f['foreign'])->get(route('customer.events.guests.create', ['event' => 101]))->assertNotFound();
        $page = $this->actingAs($f['owner'])->get(route('customer.events.guests.create', ['event' => 101, 'lang' => 'en']))->assertOk();
        foreach (['single', 'names', 'details', 'file', 'contacts'] as $method) {
            $page->assertSee('id="ga-panel-'.$method.'"', false);
        }
        $page->assertSee(route('customer.events.guests.import.sample_csv', ['event' => 101]), false)
            ->assertSee('class="cs-tabs"', false)->assertSee('data-cs-sheet', false)->assertSee(route('customer.events.invitation.guests', ['event' => 101]), false);
    }

    public function test_a_hundred_pasted_names_are_reviewed_then_saved_with_credentials(): void
    {
        $f = $this->invitationFixture();
        $names = array_map(fn (int $i) => 'Mgeni Namba '.$this->word($i), range(1, 100));
        $review = $this->actingAs($f['owner'])->post(route('customer.events.guests.review', ['event' => 101]), ['mode' => 'names', 'text' => implode("\n", $names)]);
        $review->assertOk()->assertSee('Mgeni Namba '.$this->word(100));
        $this->assertSame(3, Guest::query()->where('event_id', 101)->count(), 'nothing is saved by the review');

        $rows = array_map(fn ($n) => ['name' => $n, 'phone_number' => '', 'pass_type' => 'single', 'allowed_entries' => '1'], $names);
        $this->actingAs($f['owner'])->post(route('customer.events.guests.bulk', ['event' => 101]), ['rows_json' => json_encode($rows), 'action' => 'save', 'source' => 'list'])
            ->assertRedirect(route('customer.events.invitation.guests', ['event' => 101]))->assertSessionHas('guest_notice');

        $created = Guest::query()->where('event_id', 101)->whereIn('name', $names)->get();
        $this->assertCount(100, $created);
        $this->assertTrue($created->every(fn (Guest $g) => $g->phone_number === '' && $g->pass_type === Guest::PASS_SINGLE && (int) $g->allowed_entries === 1 && $g->manual_code !== null));
        $this->assertSame(100, QrCode::query()->whereIn('guest_id', $created->pluck('id'))->where('is_active', true)->count(), 'every guest gets an admission credential');
        $this->assertSame(100, (int) json_decode((string) DB::table('invitation_activity')->where('action', 'guests_added')->value('details'), true)['guests']);
    }

    public function test_structured_paste_and_excel_produce_the_same_guests(): void
    {
        $f = $this->invitationFixture();
        $this->actingAs($f['owner'])->post(route('customer.events.guests.review', ['event' => 101]), [
            'mode' => 'structured', 'text' => "John Michael, 0712345678, VIP, 2\nMary Joseph | 0755555555 | Regular | 1\nPeter James\t0788888888\tFamily\t4",
        ])->assertOk()->assertSee('John Michael')->assertSee('value="double"', false);
        $rows = [
            ['name' => 'John Michael', 'phone_number' => '0712345678', 'pass_type' => 'VIP', 'allowed_entries' => '2'],
            ['name' => 'Mary Joseph', 'phone_number' => '0755555555', 'pass_type' => 'Regular', 'allowed_entries' => '1'],
            ['name' => 'Peter James', 'phone_number' => '0788888888', 'pass_type' => 'Family', 'allowed_entries' => '4'],
        ];
        $this->actingAs($f['owner'])->post(route('customer.events.guests.bulk', ['event' => 101]), ['rows' => $rows, 'action' => 'save'])->assertRedirect();
        $pasted = array_slice($this->eventGuests(), 3);

        // The same guests in a spreadsheet with everyday column names go to a fresh event.
        DB::table('events')->insert(['id' => 102, 'event_name' => 'Second Event', 'venue_name' => 'Hall', 'event_date' => '2026-12-20', 'event_time' => '18:00:00', 'event_type' => 'wedding', 'status' => 'active', 'workspace_id' => 10]);
        $sheet = new Spreadsheet;
        $sheet->getActiveSheet()->fromArray([['Full Name', 'Mobile', 'Type', 'People'], ['John Michael', 255712345678, 'double', 2], ['Mary Joseph', '0755555555', 'single', 1], ['Peter James', '0788888888', 'special', 4]]);
        $path = tempnam(sys_get_temp_dir(), 'gx').'.xlsx';
        (new Xlsx($sheet))->save($path);
        $preview = $this->actingAs($f['owner'])->post(route('customer.events.guests.import.preview', ['event' => 102]), ['file' => new UploadedFile($path, 'guests.xlsx', null, null, true)]);
        $preview->assertOk();
        $token = session('customer_guest_import')['token'];
        $this->actingAs($f['owner'])->post(route('customer.events.guests.import.confirm', ['event' => 102]), ['token' => $token])->assertRedirect();
        @unlink($path);

        $this->assertSame($pasted, $this->eventGuests(102));
        $this->assertSame([['John Michael', '+255712345678', 'double', 2], ['Mary Joseph', '+255755555555', 'single', 1], ['Peter James', '+255788888888', 'special', 4]],
            array_map(fn ($g) => array_values($g), $pasted));
    }

    public function test_csv_with_only_a_name_column_imports_and_defaults_the_pass(): void
    {
        $f = $this->invitationFixture();
        $csv = UploadedFile::fake()->createWithContent('list.csv', "Jina\nAmina Salum\nBaraka Mushi\n");
        $this->actingAs($f['owner'])->post(route('customer.events.guests.import.preview', ['event' => 101]), ['file' => $csv])->assertOk()
            ->assertSee(trans_choice('customer_guests.import.confirm', 2, ['count' => 2], 'sw'))->assertSee(route('customer.events.guests.review', ['event' => 101]), false);
        $this->actingAs($f['owner'])->post(route('customer.events.guests.import.confirm', ['event' => 101]), ['token' => session('customer_guest_import')['token']])->assertRedirect();
        $this->assertSame([['Amina Salum', '', 'single', 1], ['Baraka Mushi', '', 'single', 1]], array_map('array_values', array_slice($this->eventGuests(), 3)));
    }

    public function test_invalid_rows_block_saving_and_are_shown_for_correction(): void
    {
        $f = $this->invitationFixture();
        $rows = [['name' => 'Good Guest', 'phone_number' => '', 'pass_type' => 'single', 'allowed_entries' => '1'], ['name' => 'Bad Phone', 'phone_number' => '12345', 'pass_type' => 'single', 'allowed_entries' => '1'], ['name' => 'Big Family', 'phone_number' => '', 'pass_type' => 'special', 'allowed_entries' => '500']];
        $this->actingAs($f['owner'])->post(route('customer.events.guests.bulk', ['event' => 101]), ['rows' => $rows, 'action' => 'save'])
            ->assertStatus(422)->assertSee('value="Bad Phone"', false)->assertSee('aria-invalid="true"', false);
        $this->assertSame(3, Guest::query()->where('event_id', 101)->count());

        $rows[1]['phone_number'] = '0712 999 888';
        $rows[2]['remove'] = '1';
        $this->actingAs($f['owner'])->post(route('customer.events.guests.bulk', ['event' => 101]), ['rows' => $rows, 'action' => 'save'])->assertRedirect();
        $this->assertSame([['Good Guest', '', 'single', 1], ['Bad Phone', '+255712999888', 'single', 1]], array_map('array_values', array_slice($this->eventGuests(), 3)));
    }

    public function test_duplicates_in_the_event_and_in_the_list_are_skipped(): void
    {
        $f = $this->invitationFixture();
        $rows = [
            ['name' => 'asha  MREMA', 'phone_number' => '', 'pass_type' => '', 'allowed_entries' => ''],          // name already in the event
            ['name' => 'New Person', 'phone_number' => '0755000251', 'pass_type' => '', 'allowed_entries' => ''],   // phone already in the event
            ['name' => 'Neema', 'phone_number' => '', 'pass_type' => '', 'allowed_entries' => ''],
            ['name' => 'neema', 'phone_number' => '', 'pass_type' => '', 'allowed_entries' => ''],                 // repeated in the list
            ['name' => 'Zawadi', 'phone_number' => '', 'pass_type' => '', 'allowed_entries' => ''],                // two guests without phone are not duplicates
        ];
        $this->actingAs($f['owner'])->post(route('customer.events.guests.bulk', ['event' => 101]), ['rows' => $rows, 'action' => 'check'])
            ->assertOk()->assertSee(__('customer_guests.review.duplicate_reason.name', [], 'sw'))->assertSee(__('customer_guests.review.duplicate_reason.list_name', [], 'sw'));
        $this->actingAs($f['owner'])->post(route('customer.events.guests.bulk', ['event' => 101]), ['rows' => $rows, 'action' => 'save'])
            ->assertRedirect()->assertSessionHas('guest_notice', fn ($n) => str_contains($n, '3'));
        $this->assertSame(['Neema', 'Zawadi'], array_column(array_slice($this->eventGuests(), 3), 'name'));
    }

    public function test_single_form_returns_to_the_add_page_for_more_guests(): void
    {
        $f = $this->invitationFixture();
        $this->actingAs($f['owner'])->post(route('customer.events.guests.bulk', ['event' => 101]), [
            'source' => 'single', 'action' => 'save',
            'rows' => [['name' => 'Typed One', 'phone_number' => '0713 000 111', 'pass_type' => 'double', 'allowed_entries' => '2'], ['name' => '', 'phone_number' => '', 'pass_type' => 'single', 'allowed_entries' => '1']],
        ])->assertRedirect(route('customer.events.guests.create', ['event' => 101]));
        $this->assertSame(['Typed One', '+255713000111', 'double', 2], array_values($this->eventGuests()[3]));
        $this->actingAs($f['owner'])->post(route('customer.events.guests.bulk', ['event' => 101]), ['source' => 'single', 'rows' => [['name' => '', 'phone_number' => '']]])->assertStatus(422);
    }

    public function test_another_customer_cannot_review_or_add_guests_to_the_event(): void
    {
        $f = $this->invitationFixture();
        $rows = json_encode([['name' => 'Intruder', 'phone_number' => '', 'pass_type' => 'single', 'allowed_entries' => '1']]);
        $this->actingAs($f['foreign'])->post(route('customer.events.guests.bulk', ['event' => 101]), ['rows_json' => $rows, 'action' => 'save'])->assertNotFound();
        $this->actingAs($f['foreign'])->post(route('customer.events.guests.review', ['event' => 101]), ['mode' => 'names', 'text' => 'Intruder'])->assertNotFound();
        $this->actingAs($f['owner'])->post(route('customer.events.guests.bulk', ['event' => 201]), ['rows_json' => $rows, 'action' => 'save'])->assertNotFound();
        $this->assertSame(0, Guest::query()->where('name', 'Intruder')->count());
    }

    public function test_csv_template_and_help_page_and_overview(): void
    {
        $f = $this->invitationFixture();
        $csv = $this->actingAs($f['owner'])->get(route('customer.events.guests.import.sample_csv', ['event' => 101]))->assertOk();
        $this->assertStringContainsString('name,phone_number,pass_type,allowed_entries', $csv->getContent());
        $this->actingAs($f['foreign'])->get(route('customer.events.guests.import.sample_csv', ['event' => 101]))->assertNotFound();

        $this->actingAs($f['owner'])->get(route('customer.help', ['lang' => 'en']))->assertOk()->assertSee('Help &amp; Support', false);
        $this->actingAs($f['admin'])->get(route('customer.help'))->assertForbidden();

        $home = $this->actingAs($f['owner'])->get(route('customer.home', ['lang' => 'en']))->assertOk();
        $home->assertSee('class="ov"', false)->assertSee('Guests invited')->assertSee(route('customer.events.guests.create', ['event' => 101]), false);
        // 3 guests (1 + 2 + 5 people) only from the owner's event; the foreign event is not counted.
        $home->assertSeeInOrder(['Guests invited', '>3<', '8 people'], false);
    }

    private function word(int $i): string
    {
        // Distinct names without digits: digits would be read as a count or a phone.
        $letters = range('a', 'z');

        return ucfirst($letters[intdiv($i, 26) % 26].$letters[$i % 26].'ra');
    }
}
