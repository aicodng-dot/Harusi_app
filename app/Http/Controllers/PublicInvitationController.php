<?php

namespace App\Http\Controllers;

use App\Services\Invitations\InvitationStudio;
use App\Services\Invitations\InvitationWording;
use App\Services\Sharing\InvitationLinks;
use App\Services\Sharing\ShareMessage;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The guest-facing page behind a personal invitation link (/i/{key}): the guest's own card
 * with its QR code, the event facts and a download button. No login. Unknown, revoked and
 * expired links all get the same "not available" page. Nothing here changes any record.
 */
class PublicInvitationController extends Controller
{
    private const HEADERS = [
        'Cache-Control' => 'private, no-store',
        'Referrer-Policy' => 'no-referrer',
        'X-Robots-Tag' => 'noindex, nofollow',
        'X-Frame-Options' => 'DENY',
    ];

    public function show(Request $request, string $key, InvitationLinks $links): Response
    {
        $found = $links->resolve($key);
        $language = InvitationWording::language($request->query('lang', 'sw'));
        if ($found === null) {
            return response()->view('reference_ui.public_invitation', ['language' => $language, 'found' => false], 404)->withHeaders(self::HEADERS);
        }
        ['event' => $event, 'guest' => $guest] = $found;

        return response()->view('reference_ui.public_invitation', [
            'language' => $language,
            'found' => true,
            'key' => $key,
            'event' => $event,
            'guestName' => (string) $guest->name,
            'passLine' => InvitationWording::passLine($language, (string) $guest->pass_type, (int) $guest->allowed_entries),
            'values' => ShareMessage::eventValues($event, $language),
            'fileName' => InvitationStudio::fileName((string) $guest->name, 1).'.png',
        ])->withHeaders(self::HEADERS);
    }

    public function card(Request $request, string $key, InvitationLinks $links): BinaryFileResponse
    {
        $found = $links->resolve($key);
        abort_if($found === null, 404);
        $disk = Storage::disk('invitations');
        abort_unless($disk->exists($found['card']->png_path), 404);
        $name = InvitationStudio::fileName((string) $found['guest']->name, 1).'.png';

        return response()->file($disk->path($found['card']->png_path), self::HEADERS + [
            'Content-Type' => 'image/png',
            'Content-Disposition' => ($request->boolean('download') ? 'attachment' : 'inline').'; filename="'.$name.'"',
        ]);
    }
}
