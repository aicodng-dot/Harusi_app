<?php

namespace App\Http\Controllers;

use App\Services\Invitations\InvitationStudio;
use App\Services\Invitations\InvitationWording;
use App\Services\Rsvp\RsvpService;
use App\Services\Sharing\InvitationLinks;
use App\Services\Sharing\ShareMessage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The guest-facing page behind a personal invitation link (/i/{key}): the guest's own card
 * with its QR code, the event facts, a download button and the RSVP ("Will you attend?").
 * No login. Unknown, revoked and expired links all get the same "not available" page.
 * Opening the page never answers the RSVP or changes any record; only the explicit POST does.
 */
class PublicInvitationController extends Controller
{
    private const HEADERS = [
        'Cache-Control' => 'private, no-store',
        'Referrer-Policy' => 'no-referrer',
        'X-Robots-Tag' => 'noindex, nofollow',
        'X-Frame-Options' => 'DENY',
    ];

    public function show(Request $request, string $key, InvitationLinks $links, RsvpService $rsvp): Response
    {
        $found = $links->resolve($key);
        $language = InvitationWording::language($request->query('lang', $request->session()->get('invite_lang', 'sw')));
        if ($found === null) {
            return response()->view('reference_ui.public_invitation', ['language' => $language, 'found' => false], 404)->withHeaders(self::HEADERS);
        }
        $request->session()->put('invite_lang', $language);
        ['event' => $event, 'guest' => $guest] = $found;
        $settings = $rsvp->settings((int) $event->id);

        return response()->view('reference_ui.public_invitation', [
            'language' => $language,
            'found' => true,
            'key' => $key,
            'event' => $event,
            'guestName' => (string) $guest->name,
            'allowed' => max(1, (int) $guest->allowed_entries),
            'passLine' => InvitationWording::passLine($language, (string) $guest->pass_type, (int) $guest->allowed_entries),
            'values' => ShareMessage::eventValues($event, $language),
            'fileName' => InvitationStudio::fileName((string) $guest->name, 1).'.png',
            'rsvp' => $settings['enabled'] ? $rsvp->forGuest((int) $event->id, (int) $guest->id) : null,
            'rsvpOpen' => $rsvp->isOpen($event, $settings),
            'rsvpSettings' => $settings,
            // A fresh key per page view: a double tap or refresh of this form is applied once.
            'submissionKey' => bin2hex(random_bytes(16)),
        ])->withHeaders(self::HEADERS);
    }

    public function respond(Request $request, string $key, InvitationLinks $links, RsvpService $rsvp): RedirectResponse
    {
        $found = $links->resolve($key);
        abort_if($found === null, 404);
        ['event' => $event, 'guest' => $guest] = $found;
        $data = $request->validate([
            'response' => ['required', 'in:'.implode(',', RsvpService::RESPONSES)],
            'count' => ['nullable', 'integer', 'min:1', 'max:100'],
            'note' => ['nullable', 'string', 'max:'.(RsvpService::NOTE_MAX * 2)],
            'revision' => ['nullable', 'integer', 'min:0'],
            'submission' => ['required', 'string', 'regex:/\A[a-f0-9]{32}\z/'],
        ]);
        $settings = $rsvp->settings((int) $event->id);
        abort_unless($settings['enabled'], 404);
        $result = $rsvp->submit($event, (int) $guest->id, $data['response'], isset($data['count']) ? (int) $data['count'] : null,
            $data['note'] ?? null, isset($data['revision']) ? (int) $data['revision'] : null, $data['submission'], 'guest');

        return redirect()->to(route('invitations.public', ['key' => $key]).'#rsvp')
            ->with('rsvp_status', $result['status'])
            ->withHeaders(self::HEADERS);
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
