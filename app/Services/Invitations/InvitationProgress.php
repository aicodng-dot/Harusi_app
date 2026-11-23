<?php

namespace App\Services\Invitations;

use App\Models\Guest;
use Illuminate\Support\Facades\DB;

/**
 * "Continue Invitation": decides the customer's next invitation step from saved data
 * only (read-only, no stored workflow state), so returning later resumes correctly.
 *
 *   design  -> no design chosen yet
 *   card    -> design chosen but the card has never been saved
 *   guests  -> card saved, no guests to invite yet
 *   cards   -> some guests' cards are missing or out of date
 *   share   -> every card is ready, some guests not yet sent to
 *   manage  -> every guest has been sent their invitation
 */
class InvitationProgress
{
    /** The five customer-facing stages, in order. */
    public const STAGES = ['design', 'card', 'guests', 'cards', 'share'];

    public function __construct(private InvitationStudio $studio, private InvitationDelivery $delivery) {}

    /**
     * @return array{next: string, stage: string, step: int, done: list<string>, route: string, url: string,
     *               counts: array{guests: int, ready: int, to_create: int, sent: int}}
     */
    public function forEvent(object $event, string $language): array
    {
        $design = $this->studio->design($event, $language);
        $guestIds = DB::table('guests')->where('event_id', $event->id)->where('status', '!=', Guest::STATUS_CANCELLED)
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
        $ready = 0;
        $sent = 0;
        if ($guestIds !== []) {
            ['spec' => $spec] = $this->studio->spec($event, $design['doc']);
            $ready = count(array_filter($this->studio->cardStates($event, $guestIds, $spec), fn ($card) => $card['state'] === 'current'));
            $sent = count(array_filter($this->delivery->statuses((int) $event->id, $guestIds), fn ($status) => in_array($status, ['sent', 'delivered', 'read'], true)));
        }
        $guests = count($guestIds);

        $next = match (true) {
            ! $design['saved'] && ! InvitationTemplateCatalog::isValid($event->invitation_template) => 'design',
            ! $design['saved'] => 'card',
            $guests === 0 => 'guests',
            $ready < $guests => 'cards',
            $sent < $guests => 'share',
            default => 'manage',
        };
        $stage = $next === 'manage' ? 'share' : $next;
        $step = array_search($stage, self::STAGES, true) + 1;
        $route = self::route($next);

        return [
            'next' => $next,
            'stage' => $stage,
            'step' => $step,
            'done' => $next === 'manage' ? self::STAGES : array_slice(self::STAGES, 0, $step - 1),
            'route' => $route,
            'url' => route($route, ['event' => $event->id]),
            'counts' => ['guests' => $guests, 'ready' => $ready, 'to_create' => max(0, $guests - $ready), 'sent' => $sent],
        ];
    }

    /** The existing screen that handles a stage. */
    public static function route(string $stage): string
    {
        return match ($stage) {
            'design' => 'customer.events.template',
            'card' => 'customer.events.invitation',
            'guests', 'cards' => 'customer.events.invitation.guests',
            default => 'customer.events.invitation.status',
        };
    }
}
