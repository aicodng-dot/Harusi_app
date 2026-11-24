<?php

namespace Tests\Feature;

use App\Models\Guest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\FakesGuestQrDisk;
use Tests\Feature\Invitations\InvitationFixtures;
use Tests\TestCase;

/**
 * Guests page: search, filters, sorting, paging, selection data and bulk delete. Every
 * query is limited to the authorized event; other customers get 404.
 */
class CustomerGuestListTest extends TestCase
{
    use FakesGuestQrDisk;
    use InvitationFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeGuestQrDisk();
    }

    private function names($response): array
    {
        return $response->viewData('guests')->pluck('name')->all();
    }

    private function list(array $f, array $query = [])
    {
        return $this->actingAs($f['owner'])->get(route('customer.events.invitation.guests', ['event' => 101] + $query))->assertOk();
    }

    public function test_search_by_partial_name_phone_code_and_pass_word_stays_in_the_event(): void
    {
        $f = $this->invitationFixture();
        $this->assertSame(['John Komba'], $this->names($this->list($f, ['q' => 'kom'])));
        $this->assertSame(['Asha Mrema'], $this->names($this->list($f, ['q' => '0712 000'])), 'local 07… phone format matches the stored +255 number');
        $code = $f['guests']['special']->fresh()->manual_code;
        $this->assertSame(['Familia ya Mzee Nuru'], $this->names($this->list($f, ['q' => strtolower($code)])));
        $this->assertSame(['John Komba'], $this->names($this->list($f, ['q' => 'double'])));
        // The foreign event's guest never appears, even when it matches.
        $this->assertSame([], $this->names($this->list($f, ['q' => 'Foreign'])));
        $this->list($f, ['q' => 'zzzz-nothing'])->assertSee(__('customer_guest_list.no_results_title', [], 'sw'));
    }

    public function test_filters_combine_and_only_offer_tracked_information(): void
    {
        $f = $this->invitationFixture();
        Guest::query()->create(['event_id' => 101, 'name' => 'Bila Simu', 'phone_number' => '', 'pass_type' => Guest::PASS_SINGLE, 'allowed_entries' => 1]);
        DB::table('guests')->where('id', $f['guests']['special']->id)->update(['used_entries' => 2, 'status' => Guest::STATUS_PARTIALLY_USED]);
        DB::table('guests')->where('id', $f['guests']['single']->id)->update(['used_entries' => 1, 'status' => Guest::STATUS_FULLY_USED]);

        $this->assertSame(['Bila Simu'], $this->names($this->list($f, ['phone' => 'missing'])));
        $this->assertSame(['Familia ya Mzee Nuru'], $this->names($this->list($f, ['admission' => 'partial'])));
        $this->assertSame(['Asha Mrema'], $this->names($this->list($f, ['admission' => 'full'])));
        $this->assertSame(['Bila Simu', 'John Komba'], $this->names($this->list($f, ['admission' => 'not_checked_in'])));
        $this->assertSame(['Bila Simu', 'John Komba'], $this->names($this->list($f, ['pass' => ['single', 'double'], 'admission' => 'not_checked_in'])));
        $this->assertSame(['Familia ya Mzee Nuru'], $this->names($this->list($f, ['entries' => '3plus', 'remaining' => 'some'])));
        $this->assertSame(['Asha Mrema'], $this->names($this->list($f, ['remaining' => 'none'])));
        $page = $this->list($f, ['pass' => ['double'], 'phone' => 'has']);
        $this->assertSame(2, $page->viewData('filterCount'));
        $page->assertSee('class="gm-chips"', false);
        // Unknown values are ignored, never passed to SQL.
        $this->assertCount(4, $this->names($this->list($f, ['pass' => ['vip'], 'admission' => 'drop table', 'sort' => 'id; --'])));
        // WhatsApp is not configured here, so sending filters are not offered.
        $this->list($f)->assertDontSee('name="invite" value="sent"', false)->assertSee('name="invite" value="not_generated"', false);
    }

    public function test_invitation_filters_follow_the_real_card_state(): void
    {
        $f = $this->invitationFixture();
        $this->actingAs($f['owner'])->postJson(route('customer.events.invitation.generate', ['event' => 101]), ['guests' => [$f['guests']['double']->id]])->assertOk();
        $this->assertSame(['John Komba'], $this->names($this->list($f, ['invite' => 'generated'])));
        $this->assertSame(['Asha Mrema', 'Familia ya Mzee Nuru'], $this->names($this->list($f, ['invite' => 'not_generated'])));
        $this->assertSame(['Asha Mrema', 'Familia ya Mzee Nuru'], $this->names($this->list($f, ['filter' => 'no_card'])), 'old ?filter= links keep working');
        $this->assertSame('John Komba', $this->names($this->list($f, ['sort' => 'card_newest']))[0]);
    }

    public function test_sorting_and_server_side_paging(): void
    {
        $f = $this->invitationFixture();
        foreach (range(1, 60) as $i) {
            Guest::query()->create(['event_id' => 101, 'name' => sprintf('Zz Guest %02d', $i), 'phone_number' => '', 'pass_type' => Guest::PASS_SINGLE, 'allowed_entries' => 1]);
        }
        $first = $this->list($f);
        $this->assertCount(50, $this->names($first));
        $this->assertSame('Asha Mrema', $this->names($first)[0]);
        $this->assertCount(63, $first->viewData('result')['ids'], 'select-all-matching covers every page');
        $second = $this->list($f, ['page' => 2]);
        $this->assertSame(['Zz Guest 48', 'Zz Guest 49'], array_slice($this->names($second), 0, 2));
        $this->assertSame('Zz Guest 60', $this->names($this->list($f, ['sort' => 'name_desc']))[0]);
        $this->assertSame('Familia ya Mzee Nuru', $this->names($this->list($f, ['sort' => 'allowed_desc']))[0]);
        $this->assertSame('Zz Guest 60', $this->names($this->list($f, ['sort' => 'newest']))[0]);
        $this->assertSame(['Familia ya Mzee Nuru'], array_slice($this->names($this->list($f, ['sort' => 'pass', 'q' => 'a'])), -1));
        $this->assertSame(['Zz Guest 11'], $this->names($this->list($f, ['q' => 'guest 11', 'sort' => 'oldest'])));
    }

    public function test_page_keeps_masking_phones_and_links(): void
    {
        $f = $this->invitationFixture();
        $this->list($f)->assertSee('+255 712 ••• 214')->assertDontSee('+255712000214')->assertSee('Maalum · 5')
            ->assertSee(route('customer.events.guests.edit', [101, $f['guests']['single']->id]), false)
            ->assertSee(str_replace('/', '\/', route('customer.events.guests.bulk_destroy', ['event' => 101])), false);
        $this->actingAs($f['foreign'])->get(route('customer.events.invitation.guests', ['event' => 101, 'q' => 'Asha']))->assertNotFound();
    }

    public function test_bulk_delete_confirms_keeps_admitted_guests_and_refuses_foreign_ids(): void
    {
        $f = $this->invitationFixture();
        DB::table('guests')->where('id', $f['guests']['single']->id)->update(['used_entries' => 1, 'status' => Guest::STATUS_FULLY_USED]);
        $ids = [$f['guests']['single']->id, $f['guests']['double']->id];
        $url = route('customer.events.guests.bulk_destroy', ['event' => 101]);

        $this->actingAs($f['owner'])->postJson($url, ['guests' => $ids])->assertStatus(422);
        $this->actingAs($f['owner'])->postJson($url, ['guests' => [$f['foreignGuest']->id], 'confirm' => true])->assertNotFound();
        $this->actingAs($f['foreign'])->postJson($url, ['guests' => $ids, 'confirm' => true])->assertNotFound();
        $this->assertSame(3, Guest::query()->where('event_id', 101)->count());

        $this->actingAs($f['owner'])->postJson($url, ['guests' => $ids, 'confirm' => true])->assertOk()->assertJson(['deleted' => 1, 'admitted' => 1]);
        $this->assertSame(['Asha Mrema', 'Familia ya Mzee Nuru'], Guest::query()->where('event_id', 101)->orderBy('name')->pluck('name')->all());
        $this->assertNotNull(Guest::query()->find($f['foreignGuest']->id));
        $this->assertSame(1, DB::table('invitation_activity')->where('action', 'guests_deleted')->count());
    }

    public function test_zip_download_accepts_one_comma_list_of_ids(): void
    {
        $f = $this->invitationFixture();
        $ids = [$f['guests']['single']->id, $f['guests']['double']->id];
        $this->actingAs($f['owner'])->postJson(route('customer.events.invitation.generate', ['event' => 101]), ['guests' => $ids])->assertOk();
        $this->actingAs($f['owner'])->post(route('customer.events.invitation.download', ['event' => 101]), ['format' => 'png', 'guest_ids' => implode(',', $ids)])
            ->assertOk()->assertHeader('Content-Type', 'application/zip');
        $this->actingAs($f['owner'])->post(route('customer.events.invitation.download', ['event' => 101]), ['format' => 'png', 'guest_ids' => $ids[0].','.$f['foreignGuest']->id])->assertNotFound();
    }
}
