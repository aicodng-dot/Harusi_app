<?php

namespace App\Support\AdminRead;

use App\Models\NotificationLog;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/** Immutable selected-Event notification row. Message, context and token never reach the view. */
final readonly class NotificationLogView
{
    public function __construct(
        public ?CarbonImmutable $created_at,
        public ?HistoryGuest $guest,
        public ?string $channel,
        public ?string $message_type,
        public ?string $template_name,
        public ?string $recipient_phone,
        public ?string $status,
        public ?string $provider,
        public ?string $provider_message_id,
        public ?string $error_message,
    ) {}

    public static function fromModel(NotificationLog $log, ?HistoryGuest $guest): self
    {
        return new self(
            $log->created_at instanceof DateTimeInterface ? CarbonImmutable::instance($log->created_at) : null,
            $guest,
            $log->getRawOriginal('channel'),
            $log->getRawOriginal('message_type'),
            $log->getRawOriginal('template_name'),
            $log->getRawOriginal('recipient_phone'),
            $log->getRawOriginal('status'),
            $log->getRawOriginal('provider'),
            $log->getRawOriginal('provider_message_id'),
            $log->getRawOriginal('error_message'),
        );
    }
}
