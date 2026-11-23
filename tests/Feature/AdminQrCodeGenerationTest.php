<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Guest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Tests\Concerns\FakesGuestQrDisk;
use Tests\TestCase;

class AdminQrCodeGenerationTest extends TestCase
{
    use FakesGuestQrDisk;
    use RefreshDatabase;

    private const QR_EXPORT_HEADERS = [
        'Guest ID',
        'Guest name',
        'Phone number',
        'Pass type',
        'Allowed entries',
        'Used entries',
        'Guest status',
        'QR status',
        'Generated at',
        'Revoked at',
        'Download file name',
    ];

    public function test_admin_can_generate_open_download_and_validate_guest_qr_code(): void
    {
        Storage::fake('public');
        $this->fakeGuestQrDisk();

        $admin = $this->userWithRole(User::ROLE_ADMIN);
        $scanner = $this->userWithRole(User::ROLE_SCANNER);
        $guest = Guest::query()->create([
            'name' => 'QR Test Guest',
            'phone_number' => '+255 700 111 222',
            'pass_type' => Guest::PASS_SPECIAL,
            'allowed_entries' => 4,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.guests.qr.generate', $guest))
            ->assertRedirect();

        $qrCode = $guest->fresh('qrCode')->qrCode;

        $this->assertNotNull($qrCode);
        $this->assertNotEmpty($qrCode->qr_token);
        $this->assertNotEmpty($qrCode->qr_image_path);
        $this->assertTrue($qrCode->is_active);
        $this->assertStringContainsString('guest-'.$guest->id.'-'.$qrCode->qr_token.'.png', $qrCode->qr_image_path);
        Storage::disk('guest_qr')->assertExists($qrCode->qr_image_path);
        $this->assertStringStartsWith("\x89PNG\r\n\x1a\n", Storage::disk('guest_qr')->get($qrCode->qr_image_path));

        $openResponse = $this->actingAs($admin)->get(route('admin.guests.qr', $guest));
        $openResponse->assertOk();
        $openResponse->assertHeader('Content-Type', 'image/png');
        $openResponse->assertDontSee('QR Test Guest', false);
        $openResponse->assertDontSee('+255 700 111 222', false);

        $downloadResponse = $this->actingAs($admin)->get(route('admin.guests.qr.download', $guest));
        $downloadResponse->assertOk();
        $downloadResponse->assertHeader('Content-Type', 'image/png');
        $this->assertStringContainsString('qr_test_guest_'.str_pad((string) $guest->id, 3, '0', STR_PAD_LEFT).'_qr_'.$guest->fresh()->manual_code.'.png', (string) $downloadResponse->headers->get('Content-Disposition'));

        $this->actingAs($scanner)
            ->get(route('scanner.verify-token', $qrCode->qr_token))
            ->assertOk()
            ->assertSee($qrCode->qr_token);

        $this->actingAs($scanner)
            ->postJson(route('scanner.verify'), ['token' => $qrCode->qr_token])
            ->assertOk()
            ->assertJsonPath('result', 'valid');
    }

    public function test_regenerating_qr_replaces_old_token_and_old_token_no_longer_validates(): void
    {
        Storage::fake('public');
        $this->fakeGuestQrDisk();

        $admin = $this->userWithRole(User::ROLE_ADMIN);
        $scanner = $this->userWithRole(User::ROLE_SCANNER);
        $guest = Guest::query()->create([
            'name' => 'Regenerate Guest',
            'phone_number' => '+255 700 333 444',
            'pass_type' => Guest::PASS_DOUBLE,
            'allowed_entries' => 2,
        ]);

        $this->actingAs($admin)->post(route('admin.guests.qr.generate', $guest))->assertRedirect();
        $firstQrCode = $guest->fresh('qrCode')->qrCode;
        $oldToken = $firstQrCode->qr_token;
        $oldImagePath = $firstQrCode->qr_image_path;

        $this->actingAs($admin)->post(route('admin.guests.qr.generate', $guest))->assertRedirect();
        $newQrCode = $guest->fresh('qrCode')->qrCode;

        $this->assertNotSame($oldToken, $newQrCode->qr_token);
        $this->assertNotSame($oldImagePath, $newQrCode->qr_image_path);
        $this->assertDatabaseCount('qr_codes', 1);
        Storage::disk('guest_qr')->assertMissing($oldImagePath);
        Storage::disk('guest_qr')->assertExists($newQrCode->qr_image_path);

        $this->actingAs($scanner)
            ->postJson(route('scanner.verify'), ['token' => $oldToken])
            ->assertNotFound()
            ->assertJsonPath('result', 'invalid');

        $this->actingAs($scanner)
            ->postJson(route('scanner.verify'), ['token' => $newQrCode->qr_token])
            ->assertOk()
            ->assertJsonPath('result', 'valid');
    }

    public function test_revoked_qr_code_does_not_validate(): void
    {
        Storage::fake('public');
        $this->fakeGuestQrDisk();

        $admin = $this->userWithRole(User::ROLE_ADMIN);
        $scanner = $this->userWithRole(User::ROLE_SCANNER);
        $guest = Guest::query()->create([
            'name' => 'Revoked QR Guest',
            'phone_number' => '+255 700 555 666',
            'pass_type' => Guest::PASS_SINGLE,
            'allowed_entries' => 1,
        ]);

        $this->actingAs($admin)->post(route('admin.guests.qr.generate', $guest))->assertRedirect();
        $guest = $guest->fresh('qrCode');

        $this->actingAs($admin)
            ->patch(route('admin.guests.revoke', $guest))
            ->assertRedirect();

        $this->actingAs($scanner)
            ->postJson(route('scanner.verify'), ['token' => $guest->qrCode->qr_token])
            ->assertOk()
            ->assertJsonPath('result', 'revoked')
            ->assertJsonPath('ok', false);
    }

    public function test_qr_codes_page_lists_guests_with_filters_and_individual_actions(): void
    {
        Storage::fake('public');
        $this->fakeGuestQrDisk();

        $admin = $this->userWithRole(User::ROLE_ADMIN);
        $activeGuest = $this->guest('Active QR Guest', Guest::PASS_SINGLE, 1);
        $missingGuest = $this->guest('Missing QR Guest', Guest::PASS_DOUBLE, 2);
        $fullyUsedGuest = $this->guest('Fully Used QR Guest', Guest::PASS_SINGLE, 1, 1);

        $this->actingAs($admin)->post(route('admin.guests.qr.generate', $activeGuest))->assertRedirect();
        $this->actingAs($admin)->post(route('admin.guests.qr.generate', $fullyUsedGuest))->assertRedirect();
        $fullyUsedGuest = $fullyUsedGuest->fresh('qrCode');

        $this->actingAs($admin)
            ->get(route('admin.qr-codes.index'))
            ->assertOk()
            ->assertSee('Active QR Guest')
            ->assertSee('Missing QR Guest')
            ->assertSee('Generate missing QR codes')
            ->assertSee('Download all ZIP')
            ->assertSee('Export QR list Excel')
            ->assertDontSee('Export QR list CSV')
            ->assertSee('Regenerate')
            ->assertSee('Generate QR');

        $this->actingAs($admin)
            ->get(route('admin.qr-codes.index', ['filter' => 'missing_qr']))
            ->assertOk()
            ->assertSee('Missing QR Guest')
            ->assertDontSee('Active QR Guest');

        $this->actingAs($admin)
            ->get(route('admin.qr-codes.index', ['filter' => 'fully_used']))
            ->assertOk()
            ->assertSee('Fully Used QR Guest')
            ->assertDontSee('Missing QR Guest');

        $this->actingAs($admin)
            ->patch(route('admin.qr-codes.deactivate', $activeGuest))
            ->assertRedirect();

        $this->assertFalse($activeGuest->fresh('qrCode')->qrCode->is_active);

        $this->actingAs($admin)
            ->patch(route('admin.qr-codes.activate', $activeGuest))
            ->assertRedirect();

        $this->assertTrue($activeGuest->fresh('qrCode')->qrCode->is_active);
    }

    public function test_admin_can_batch_generate_missing_qr_codes(): void
    {
        Storage::fake('public');
        $this->fakeGuestQrDisk();

        $admin = $this->userWithRole(User::ROLE_ADMIN);
        $guestWithQr = $this->guest('Existing QR Guest', Guest::PASS_SINGLE, 1);
        $missingOne = $this->guest('Batch Missing One', Guest::PASS_DOUBLE, 2);
        $missingTwo = $this->guest('Batch Missing Two', Guest::PASS_SPECIAL, 4);

        $this->actingAs($admin)->post(route('admin.guests.qr.generate', $guestWithQr))->assertRedirect();

        $this->actingAs($admin)
            ->post(route('admin.qr-codes.generate-missing'))
            ->assertRedirect();

        $this->assertNotNull($missingOne->fresh('qrCode')->qrCode);
        $this->assertNotNull($missingTwo->fresh('qrCode')->qrCode);
        $this->assertDatabaseCount('qr_codes', 3);
    }

    public function test_qr_codes_page_can_search_by_guest_name_or_phone_number(): void
    {
        Storage::fake('public');
        $this->fakeGuestQrDisk();

        $admin = $this->userWithRole(User::ROLE_ADMIN);
        $nameMatch = $this->guest('Neema Kweka', Guest::PASS_SINGLE, 1);
        $phoneMatch = $this->guest('Phone Match Guest', Guest::PASS_DOUBLE, 2);
        $otherGuest = $this->guest('Other Guest', Guest::PASS_SPECIAL, 4);

        $nameMatch->update(['phone_number' => '+255 711 111 111']);
        $phoneMatch->update(['phone_number' => '+255 754 321 987']);
        $otherGuest->update(['phone_number' => '+255 722 222 222']);
        $this->actingAs($admin)->post(route('admin.guests.qr.generate', $nameMatch))->assertRedirect();

        $this->actingAs($admin)
            ->get(route('admin.qr-codes.index', ['search' => 'Neema']))
            ->assertOk()
            ->assertSee('Search by guest name or phone number')
            ->assertSee('Neema Kweka')
            ->assertDontSee('Phone Match Guest')
            ->assertDontSee('Other Guest')
            ->assertSee('Export selected ZIP')
            ->assertSee('guest_ids[]', false);

        $this->actingAs($admin)
            ->get(route('admin.qr-codes.index', ['search' => '321 987']))
            ->assertOk()
            ->assertSee('Phone Match Guest')
            ->assertDontSee('Neema Kweka')
            ->assertDontSee('Other Guest');
    }

    public function test_admin_can_download_all_qr_codes_as_zip_and_export_selected_event_qr_list_as_xlsx(): void
    {
        Storage::fake('public');
        $this->fakeGuestQrDisk();

        $event = Event::defaultEvent();
        $event->update([
            'event_name' => 'Nuru & Baraka Wedding',
            'event_date' => '2026-09-12',
        ]);
        $otherEvent = Event::query()->create([
            'event_name' => 'Another Wedding',
            'status' => Event::STATUS_ACTIVE,
        ]);
        $admin = $this->userWithRole(User::ROLE_ADMIN);
        $john = $this->eventGuest($event, 'John Peter', '0710000122', Guest::PASS_SINGLE, 1, '0047');
        $asha = $this->eventGuest($event, 'Asha QR Export', '+255 710 000 223', Guest::PASS_DOUBLE, 2);
        $formulaName = '=1+1';
        $formulaGuest = $this->eventGuest($event, $formulaName, '+255 710 000 224', Guest::PASS_SINGLE, 1);
        $specialName = 'Asha & Juma / VIP';
        $specialGuest = $this->eventGuest($event, $specialName, '+255 710 000 225', Guest::PASS_SPECIAL, 4);
        $missing = $this->eventGuest($event, 'Missing Export QR', '+255 710 000 226', Guest::PASS_SPECIAL, 4);
        $otherEventGuest = $this->eventGuest($otherEvent, 'Other Event Guest', '+255 710 000 227', Guest::PASS_SINGLE, 1);

        foreach ([$john, $asha] as $guest) {
            $this->actingAs($admin)
                ->withSession(['selected_event_id' => $event->id])
                ->post(route('admin.guests.qr.generate', $guest))
                ->assertRedirect();
        }

        $zipResponse = $this->actingAs($admin)
            ->withSession(['selected_event_id' => $event->id])
            ->get(route('admin.qr-codes.download-all'));
        $zipResponse->assertOk();
        $zipResponse->assertHeader('Content-Type', 'application/zip');
        $this->assertStringStartsWith('PK', $zipResponse->getContent());
        $this->assertStringContainsString('john_peter_'.str_pad((string) $john->id, 3, '0', STR_PAD_LEFT).'_qr_0047_1.png', $zipResponse->getContent());
        $this->assertStringContainsString('asha_qr_export_'.str_pad((string) $asha->id, 3, '0', STR_PAD_LEFT).'_qr_'.$asha->fresh()->manual_code.'_2.png', $zipResponse->getContent());
        $this->assertStringNotContainsString('missing_export_qr', $zipResponse->getContent());

        $exportDate = now()->format('Y-m-d');
        $xlsxResponse = $this->actingAs($admin)
            ->withSession(['selected_event_id' => $event->id])
            ->get(route('admin.qr-codes.export'));

        $xlsxResponse->assertOk();
        $xlsxResponse->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $xlsxResponse->assertDownload($event->safeSlug().'-qr-list-'.$exportDate.'.xlsx');

        $xlsx = $xlsxResponse->streamedContent();
        $this->assertStringStartsWith('PK', $xlsx);

        $spreadsheet = $this->loadXlsx($xlsx);

        try {
            $this->assertQrListWorkbook($spreadsheet, [
                'event' => $event,
                'guests' => [$john, $asha, $formulaGuest, $specialGuest, $missing],
                'excluded_guest' => $otherEventGuest,
                'formula_guest' => $formulaGuest,
                'formula_name' => $formulaName,
                'special_guest' => $specialGuest,
                'special_name' => $specialName,
                'download_guest' => $john,
                'missing_guest' => $missing,
            ]);
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }

    public function test_admin_can_export_an_empty_qr_list_as_a_valid_xlsx_workbook(): void
    {
        $event = Event::query()->create([
            'event_name' => 'Empty Wedding',
            'status' => Event::STATUS_ACTIVE,
        ]);
        $admin = $this->userWithRole(User::ROLE_ADMIN);
        $exportDate = now()->format('Y-m-d');

        $response = $this->actingAs($admin)
            ->withSession(['selected_event_id' => $event->id])
            ->get(route('admin.qr-codes.export'));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->assertDownload($event->safeSlug().'-qr-list-'.$exportDate.'.xlsx');

        $spreadsheet = $this->loadXlsx($response->streamedContent());

        try {
            $worksheet = $spreadsheet->getSheetByName('QR Codes');
            $this->assertInstanceOf(Worksheet::class, $worksheet);
            $this->assertSame(self::QR_EXPORT_HEADERS, $worksheet->rangeToArray('A1:K1', null, false, false)[0]);
            $this->assertSame('K', $worksheet->getHighestDataColumn());
            $this->assertSame(1, $worksheet->getHighestDataRow());
            $this->assertSame('A2', $worksheet->getFreezePane());
            $this->assertSame('A1:K1', $worksheet->getAutoFilter()->getRange());
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }

    public function test_admin_can_export_a_large_qr_list_as_a_valid_xlsx_workbook(): void
    {
        $event = Event::query()->create([
            'event_name' => 'Large Export Wedding',
            'status' => Event::STATUS_ACTIVE,
        ]);
        $admin = $this->userWithRole(User::ROLE_ADMIN);

        for ($index = 1; $index <= 125; $index++) {
            $this->eventGuest(
                $event,
                'Large Export Guest '.str_pad((string) $index, 4, '0', STR_PAD_LEFT),
                '+255 711 '.str_pad((string) $index, 6, '0', STR_PAD_LEFT),
                Guest::PASS_SINGLE,
                1,
                str_pad((string) $index, 4, '0', STR_PAD_LEFT),
            );
        }

        $response = $this->actingAs($admin)
            ->withSession(['selected_event_id' => $event->id])
            ->get(route('admin.qr-codes.export'));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $spreadsheet = $this->loadXlsx($response->streamedContent());

        try {
            $worksheet = $spreadsheet->getSheetByName('QR Codes');
            $this->assertInstanceOf(Worksheet::class, $worksheet);
            $this->assertSame(self::QR_EXPORT_HEADERS, $worksheet->rangeToArray('A1:K1', null, false, false)[0]);
            $this->assertSame(126, $worksheet->getHighestDataRow());
            $this->assertSame('A1:K126', $worksheet->getAutoFilter()->getRange());
            $this->assertSame(DataType::TYPE_STRING, $worksheet->getCell('C2')->getDataType());
            $this->assertSame('missing', $worksheet->getCell('H2')->getValue());
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }

    public function test_scanner_cannot_export_the_qr_list_xlsx(): void
    {
        $scanner = $this->userWithRole(User::ROLE_SCANNER);

        $this->actingAs($scanner)
            ->get(route('admin.qr-codes.export'))
            ->assertForbidden();
    }

    public function test_admin_can_export_single_or_multiple_selected_qr_codes_only(): void
    {
        Storage::fake('public');
        $this->fakeGuestQrDisk();

        $admin = $this->userWithRole(User::ROLE_ADMIN);
        $first = $this->guest('First Selected QR', Guest::PASS_SINGLE, 1);
        $second = $this->guest('Second Selected QR', Guest::PASS_DOUBLE, 2);
        $notSelected = $this->guest('Not Selected QR', Guest::PASS_SPECIAL, 4);
        $missingQr = $this->guest('Missing Selected QR', Guest::PASS_SINGLE, 1);

        $this->actingAs($admin)->post(route('admin.guests.qr.generate', $first))->assertRedirect();
        $this->actingAs($admin)->post(route('admin.guests.qr.generate', $second))->assertRedirect();
        $this->actingAs($admin)->post(route('admin.guests.qr.generate', $notSelected))->assertRedirect();

        $singleResponse = $this->actingAs($admin)->post(route('admin.qr-codes.download-selected'), [
            'guest_ids' => [$first->id],
        ]);

        $singleResponse->assertOk();
        $singleResponse->assertHeader('Content-Type', 'application/zip');
        $this->assertStringContainsString('selected-qr-codes', (string) $singleResponse->headers->get('Content-Disposition'));
        $this->assertStringContainsString('first_selected_qr_'.str_pad((string) $first->id, 3, '0', STR_PAD_LEFT), $singleResponse->getContent());
        $this->assertStringNotContainsString('second_selected_qr', $singleResponse->getContent());
        $this->assertStringNotContainsString('not_selected_qr', $singleResponse->getContent());

        $multipleResponse = $this->actingAs($admin)->post(route('admin.qr-codes.download-selected'), [
            'guest_ids' => [$first->id, $second->id],
        ]);

        $multipleResponse->assertOk();
        $multipleResponse->assertHeader('Content-Type', 'application/zip');
        $this->assertStringContainsString('first_selected_qr_'.str_pad((string) $first->id, 3, '0', STR_PAD_LEFT), $multipleResponse->getContent());
        $this->assertStringContainsString('second_selected_qr_'.str_pad((string) $second->id, 3, '0', STR_PAD_LEFT), $multipleResponse->getContent());
        $this->assertStringNotContainsString('not_selected_qr', $multipleResponse->getContent());

        $this->actingAs($admin)
            ->from(route('admin.qr-codes.index'))
            ->post(route('admin.qr-codes.download-selected'))
            ->assertRedirect(route('admin.qr-codes.index'))
            ->assertSessionHas('error');

        $this->actingAs($admin)
            ->from(route('admin.qr-codes.index'))
            ->post(route('admin.qr-codes.download-selected'), ['guest_ids' => [$missingQr->id]])
            ->assertRedirect(route('admin.qr-codes.index'))
            ->assertSessionHas('error');
    }

    public function test_deactivated_and_revoked_qr_codes_do_not_validate_and_regeneration_replaces_old_token(): void
    {
        Storage::fake('public');
        $this->fakeGuestQrDisk();

        $admin = $this->userWithRole(User::ROLE_ADMIN);
        $scanner = $this->userWithRole(User::ROLE_SCANNER);
        $guest = $this->guest('Secure QR Guest', Guest::PASS_DOUBLE, 2);

        $this->actingAs($admin)->post(route('admin.guests.qr.generate', $guest))->assertRedirect();
        $qrCode = $guest->fresh('qrCode')->qrCode;
        $firstToken = $qrCode->qr_token;

        $this->actingAs($admin)->patch(route('admin.qr-codes.deactivate', $guest))->assertRedirect();

        $this->actingAs($scanner)
            ->postJson(route('scanner.validate'), ['qr_token' => $firstToken])
            ->assertOk()
            ->assertJsonPath('status', 'revoked');

        $this->actingAs($admin)->patch(route('admin.qr-codes.activate', $guest))->assertRedirect();

        $this->actingAs($scanner)
            ->postJson(route('scanner.validate'), ['qr_token' => $firstToken])
            ->assertOk()
            ->assertJsonPath('status', 'valid');

        $this->actingAs($admin)->patch(route('admin.guests.revoke', $guest))->assertRedirect();

        $this->actingAs($scanner)
            ->postJson(route('scanner.validate'), ['qr_token' => $firstToken])
            ->assertOk()
            ->assertJsonPath('status', 'revoked');

        $this->actingAs($admin)->post(route('admin.guests.qr.generate', $guest))->assertRedirect();
        $newToken = $guest->fresh('qrCode')->qrCode->qr_token;

        $this->assertNotSame($firstToken, $newToken);

        $this->actingAs($scanner)
            ->postJson(route('scanner.validate'), ['qr_token' => $firstToken])
            ->assertOk()
            ->assertJsonPath('status', 'invalid');

        $this->actingAs($scanner)
            ->postJson(route('scanner.validate'), ['qr_token' => $newToken])
            ->assertOk()
            ->assertJsonPath('status', 'valid');
    }

    public function test_generated_qr_png_contains_manual_code_section_without_changing_manual_code_or_payload(): void
    {
        Storage::fake('public');
        $this->fakeGuestQrDisk();

        $admin = $this->userWithRole(User::ROLE_ADMIN);
        $scanner = $this->userWithRole(User::ROLE_SCANNER);
        $guest = Guest::query()->create([
            'name' => 'Anna Maro',
            'phone_number' => '+255 700 777 001',
            'manual_code' => '0047',
            'pass_type' => Guest::PASS_SPECIAL,
            'allowed_entries' => 4,
        ]);

        $this->actingAs($admin)->post(route('admin.guests.qr.generate', $guest))->assertRedirect();

        $guest = $guest->fresh('qrCode');
        $png = Storage::disk('guest_qr')->get($guest->qrCode->qr_image_path);

        $this->assertSame('0047', $guest->manual_code);
        $this->assertNotSame($guest->manual_code, $guest->qrCode->qr_token);
        $this->assertStringStartsWith("\x89PNG\r\n\x1a\n", $png);
        $this->assertSame(['width' => 1000, 'height' => 1200], $this->pngDimensions($png));
        $this->assertStringContainsString("Manual admission code\x00".$guest->manual_code, $png);
        $this->assertStringContainsString("QR token tail\x00".substr($guest->qrCode->qr_token, -12), $png);

        $this->actingAs($scanner)
            ->postJson(route('scanner.verify'), ['token' => $guest->qrCode->qr_token])
            ->assertOk()
            ->assertJsonPath('guest.guest_name', 'Anna Maro');

        $this->actingAs($scanner)
            ->get(route('scanner.manual-search', ['q' => '0047']))
            ->assertOk()
            ->assertSee('Anna Maro');
    }

    public function test_qr_regeneration_preserves_existing_manual_code_and_download_matches_preview_asset(): void
    {
        Storage::fake('public');
        $this->fakeGuestQrDisk();

        $admin = $this->userWithRole(User::ROLE_ADMIN);
        $guest = Guest::query()->create([
            'name' => 'Leading Zero Guest',
            'phone_number' => '+255 700 777 002',
            'manual_code' => '0280',
            'pass_type' => Guest::PASS_DOUBLE,
            'allowed_entries' => 2,
        ]);

        $this->actingAs($admin)->post(route('admin.guests.qr.generate', $guest))->assertRedirect();
        $firstQrCode = $guest->fresh('qrCode')->qrCode;

        $this->actingAs($admin)->post(route('admin.guests.qr.generate', $guest))->assertRedirect();
        $guest = $guest->fresh('qrCode');
        $png = Storage::disk('guest_qr')->get($guest->qrCode->qr_image_path);

        $this->assertSame('0280', $guest->manual_code);
        $this->assertNotSame($firstQrCode->qr_token, $guest->qrCode->qr_token);
        $this->assertStringContainsString("Manual admission code\x00".$guest->manual_code, $png);

        $previewResponse = $this->actingAs($admin)->get(route('admin.guests.qr', $guest));
        $downloadResponse = $this->actingAs($admin)->get(route('admin.guests.qr.download', $guest));

        $previewResponse->assertOk();
        $downloadResponse->assertOk();
        $this->assertSame($previewResponse->getContent(), $downloadResponse->getContent());
        $this->assertStringContainsString('leading_zero_guest_'.str_pad((string) $guest->id, 3, '0', STR_PAD_LEFT).'_qr_0280.png', (string) $downloadResponse->headers->get('Content-Disposition'));
    }

    public function test_bulk_qr_zip_contains_each_guests_own_manual_code_image(): void
    {
        Storage::fake('public');
        $this->fakeGuestQrDisk();

        $admin = $this->userWithRole(User::ROLE_ADMIN);
        $anna = Guest::query()->create([
            'name' => 'Anna Maro',
            'phone_number' => '+255 700 777 003',
            'manual_code' => '2804',
            'pass_type' => Guest::PASS_SINGLE,
            'allowed_entries' => 1,
        ]);
        $john = Guest::query()->create([
            'name' => 'John Peter',
            'phone_number' => '+255 700 777 004',
            'manual_code' => '5193',
            'pass_type' => Guest::PASS_DOUBLE,
            'allowed_entries' => 2,
        ]);
        $special = Guest::query()->create([
            'name' => 'Dr Haruna Mkono',
            'phone_number' => '+255 700 777 005',
            'manual_code' => '2942',
            'pass_type' => Guest::PASS_SPECIAL,
            'allowed_entries' => 5,
            'used_entries' => 2,
        ]);

        $this->actingAs($admin)->post(route('admin.guests.qr.generate', $anna))->assertRedirect();
        $this->actingAs($admin)->post(route('admin.guests.qr.generate', $john))->assertRedirect();
        $this->actingAs($admin)->post(route('admin.guests.qr.generate', $special))->assertRedirect();

        $qrStateBeforeExport = collect([$anna, $john, $special])
            ->mapWithKeys(function (Guest $guest): array {
                $guest = $guest->fresh('qrCode');

                return [$guest->id => [
                    'token' => $guest->qrCode->qr_token,
                    'path' => $guest->qrCode->qr_image_path,
                    'image' => Storage::disk('guest_qr')->get($guest->qrCode->qr_image_path),
                ]];
            })
            ->all();

        $zipResponse = $this->actingAs($admin)->get(route('admin.qr-codes.download-all'));
        $zip = $zipResponse->getContent();

        $zipResponse->assertOk();
        $this->assertStringContainsString('anna_maro_'.str_pad((string) $anna->id, 3, '0', STR_PAD_LEFT).'_qr_2804_1.png', $zip);
        $this->assertStringContainsString('john_peter_'.str_pad((string) $john->id, 3, '0', STR_PAD_LEFT).'_qr_5193_2.png', $zip);
        $this->assertStringContainsString('dr_haruna_mkono_'.str_pad((string) $special->id, 3, '0', STR_PAD_LEFT).'_qr_2942_5.png', $zip);

        foreach ([$anna, $john, $special] as $guest) {
            $guest = $guest->fresh('qrCode');
            $this->assertSame($qrStateBeforeExport[$guest->id]['token'], $guest->qrCode->qr_token);
            $this->assertSame($qrStateBeforeExport[$guest->id]['path'], $guest->qrCode->qr_image_path);
            $this->assertSame(
                $qrStateBeforeExport[$guest->id]['image'],
                Storage::disk('guest_qr')->get($guest->qrCode->qr_image_path),
            );
        }
        $this->assertStringContainsString("Manual admission code\x002804", $zip);
        $this->assertStringContainsString("Manual admission code\x005193", $zip);
    }

    private function assertQrListWorkbook(Spreadsheet $spreadsheet, array $expected): void
    {
        $this->assertSame(1, $spreadsheet->getSheetCount());
        $worksheet = $spreadsheet->getSheetByName('QR Codes');
        $this->assertInstanceOf(Worksheet::class, $worksheet);
        $this->assertSame(self::QR_EXPORT_HEADERS, $worksheet->rangeToArray('A1:K1', null, false, false)[0]);
        $this->assertNotContains('Manual code', self::QR_EXPORT_HEADERS);
        $this->assertNotContains('QR token', self::QR_EXPORT_HEADERS);
        $this->assertSame('K', $worksheet->getHighestDataColumn());
        $this->assertSame(count($expected['guests']) + 1, $worksheet->getHighestDataRow());
        $this->assertSame('A2', $worksheet->getFreezePane());
        $this->assertSame('A1:K'.$worksheet->getHighestDataRow(), $worksheet->getAutoFilter()->getRange());

        $rowNumbers = $this->qrExportRowNumbersByGuestId($worksheet);
        $expectedGuestIds = array_map(fn (Guest $guest): int => $guest->id, $expected['guests']);
        $actualGuestIds = array_keys($rowNumbers);
        sort($expectedGuestIds);
        sort($actualGuestIds);

        $this->assertSame($expectedGuestIds, $actualGuestIds);
        $this->assertArrayNotHasKey($expected['excluded_guest']->id, $rowNumbers);
        $this->assertQrListTextCells($worksheet, $rowNumbers, $expected);
    }

    private function assertQrListTextCells(Worksheet $worksheet, array $rowNumbers, array $expected): void
    {
        foreach ($expected['guests'] as $guest) {
            $phoneCell = $worksheet->getCell('C'.$rowNumbers[$guest->id]);

            $this->assertSame(DataType::TYPE_STRING, $phoneCell->getDataType());
            $this->assertSame($guest->phone_number, $phoneCell->getValue());
        }

        $formulaNameCell = $worksheet->getCell('B'.$rowNumbers[$expected['formula_guest']->id]);
        $this->assertSame(DataType::TYPE_STRING, $formulaNameCell->getDataType());
        $this->assertSame($expected['formula_name'], $formulaNameCell->getValue());

        $specialNameCell = $worksheet->getCell('B'.$rowNumbers[$expected['special_guest']->id]);
        $this->assertSame(DataType::TYPE_STRING, $specialNameCell->getDataType());
        $this->assertSame($expected['special_name'], $specialNameCell->getValue());

        $downloadGuest = $expected['download_guest'];
        $downloadCell = $worksheet->getCell('K'.$rowNumbers[$downloadGuest->id]);
        $this->assertSame(DataType::TYPE_STRING, $downloadCell->getDataType());
        $this->assertSame(
            $expected['event']->safeSlug().'_john_peter_'.str_pad((string) $downloadGuest->id, 3, '0', STR_PAD_LEFT).'_qr_0047.png',
            $downloadCell->getValue(),
        );

        $this->assertSame('active', $worksheet->getCell('H'.$rowNumbers[$downloadGuest->id])->getValue());
        $missingGuest = $expected['missing_guest'];
        $this->assertSame('missing', $worksheet->getCell('H'.$rowNumbers[$missingGuest->id])->getValue());
        $this->assertSame('', (string) $worksheet->getCell('K'.$rowNumbers[$missingGuest->id])->getValue());
    }

    private function qrExportRowNumbersByGuestId(Worksheet $worksheet): array
    {
        $rowNumbers = [];

        for ($rowNumber = 2; $rowNumber <= $worksheet->getHighestDataRow(); $rowNumber++) {
            $guestId = $worksheet->getCell('A'.$rowNumber)->getValue();

            if ($guestId !== null && $guestId !== '') {
                $rowNumbers[(int) $guestId] = $rowNumber;
            }
        }

        return $rowNumbers;
    }

    private function loadXlsx(string $contents): Spreadsheet
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'qr-list-');
        $this->assertNotFalse($tempFile);

        try {
            $this->assertSame(strlen($contents), file_put_contents($tempFile, $contents));

            return IOFactory::createReader('Xlsx')->load($tempFile);
        } finally {
            if (is_file($tempFile)) {
                unlink($tempFile);
            }
        }
    }

    private function pngDimensions(string $png): array
    {
        $dimensions = unpack('Nwidth/Nheight', substr($png, 16, 8));

        return [
            'width' => $dimensions['width'],
            'height' => $dimensions['height'],
        ];
    }

    private function eventGuest(
        Event $event,
        string $name,
        string $phoneNumber,
        string $passType,
        int $allowedEntries,
        ?string $manualCode = null,
    ): Guest {
        $attributes = [
            'event_id' => $event->id,
            'name' => $name,
            'phone_number' => $phoneNumber,
            'pass_type' => $passType,
            'allowed_entries' => $allowedEntries,
        ];

        if ($manualCode !== null) {
            $attributes['manual_code'] = $manualCode;
        }

        return Guest::query()->create($attributes);
    }

    private function guest(
        string $name,
        string $passType,
        int $allowedEntries,
        int $usedEntries = 0,
        string $status = Guest::STATUS_ACTIVE,
    ): Guest {
        return Guest::query()->create([
            'name' => $name,
            'phone_number' => '+255 700 '.str_pad((string) random_int(100000, 999999), 6, '0', STR_PAD_LEFT),
            'pass_type' => $passType,
            'allowed_entries' => $allowedEntries,
            'used_entries' => $usedEntries,
            'status' => $status,
        ]);
    }

    private function userWithRole(string $role): User
    {
        return User::query()->create([
            'name' => ucfirst($role).' User',
            'email' => uniqid($role, true).'@example.com',
            'password' => Hash::make('password'),
            'role' => $role,
            'gate_name' => $role === User::ROLE_SCANNER ? 'Main Gate' : null,
        ]);
    }
}
