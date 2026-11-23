<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Time-limited, signed media link for one generated invitation card, used only as the
 * image URL handed to the WhatsApp Cloud API (Meta fetches it without a session).
 * The `signed` middleware rejects tampered or expired URLs; `v` must also match the
 * card's current design fingerprint, so a regenerated card invalidates older links.
 */
class InvitationMediaController extends Controller
{
    public function show(string $card, string $v): BinaryFileResponse
    {
        abort_unless(preg_match('/\A[1-9][0-9]{0,18}\z/', $card) === 1 && preg_match('/\A[a-f0-9]{16}\z/', $v) === 1, 404);
        $row = DB::table('invitation_cards')->where('id', (int) $card)->first(['png_path', 'design_fingerprint']);
        abort_unless($row !== null && hash_equals(substr($row->design_fingerprint, 0, 16), $v), 404);
        $disk = Storage::disk('invitations');
        abort_unless($disk->exists($row->png_path), 404);

        return response()->file($disk->path($row->png_path), [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'private, no-store',
            'Referrer-Policy' => 'no-referrer',
            'X-Robots-Tag' => 'noindex',
        ]);
    }
}
