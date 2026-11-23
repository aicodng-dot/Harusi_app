<?php

namespace App\Services\Invitations;

use Carbon\CarbonInterface;

/**
 * Original Kiswahili and English invitation wording, independent of the interface
 * language. Each event type offers five tones; each tone has a sentence for the
 * couple/honoree inviting directly ("self"), for one host family ("one", grammatically
 * singular: "Familia ya ... inayo furaha") and for two families or several hosts
 * ("many": "... zinayo furaha"). All wording is a starting point the owner can edit.
 */
class InvitationWording
{
    public const LANGUAGES = ['sw', 'en'];

    public const STYLES = ['formal', 'warm', 'modern', 'short', 'faith'];

    public const HOST_MODES = ['couple', 'bride_family', 'groom_family', 'both_families', 'other'];

    private const TEXT = [
        'sw' => [
            'wedding' => [
                'formal' => ['self' => 'Tunayo heshima kubwa kukualika katika sherehe ya ndoa yetu.', 'one' => 'inayo heshima kubwa kukualika katika sherehe ya harusi ya mtoto wao.', 'many' => 'zinayo heshima kubwa kukualika katika sherehe ya harusi ya watoto wao.', 'message' => 'Uwepo wako utakuwa heshima kubwa kwetu.'],
                'warm' => ['self' => 'Kwa furaha kubwa, tunakukaribisha ushiriki nasi siku ya harusi yetu.', 'one' => 'inayo furaha kukukaribisha kushiriki nao katika sherehe ya harusi ya mpendwa wao.', 'many' => 'zinayo furaha kukukaribisha kushiriki nao katika sherehe ya harusi ya wapendwa wao.', 'message' => 'Karibu tusherehekee pamoja upendo, furaha na mwanzo mpya.'],
                'modern' => ['self' => 'Tunafunga ndoa! Karibu usherehekee pamoja nasi.', 'one' => 'inakualika kusherehekea pamoja nao harusi ya mtoto wao.', 'many' => 'zinakualika kusherehekea pamoja nao harusi ya watoto wao.', 'message' => 'Njoo tufurahi, tucheze na tusherehekee pamoja!'],
                'short' => ['self' => 'Karibu kwenye harusi yetu.', 'one' => 'inakukaribisha kwenye harusi ya mtoto wao.', 'many' => 'zinakukaribisha kwenye harusi ya watoto wao.', 'message' => 'Karibu sana.'],
                'faith' => ['self' => 'Kwa neema ya Mungu, tunakualika kushuhudia ndoa yetu takatifu.', 'one' => 'kwa shukrani kwa Mwenyezi Mungu, inakualika kushuhudia ndoa ya mtoto wao.', 'many' => 'kwa shukrani kwa Mwenyezi Mungu, zinakualika kushuhudia ndoa ya watoto wao.', 'message' => 'Mungu akubariki unapojumuika nasi katika siku hii ya baraka.'],
            ],
            'send_off' => [
                'formal' => ['self' => 'Unakaribishwa kwa heshima kuhudhuria sherehe ya kumuaga bibi harusi mtarajiwa.', 'one' => 'inayo heshima kukualika katika sherehe ya kumuaga binti yao.', 'many' => 'zinayo heshima kukualika katika sherehe ya kumuaga binti yao.', 'message' => 'Uwepo wako utaifanya siku hii kuwa ya kipekee.'],
                'warm' => ['self' => 'Njoo tumuage kwa upendo na baraka anapoanza safari mpya ya maisha.', 'one' => 'inayo furaha kukukaribisha kumuaga binti yao kwa upendo.', 'many' => 'zinayo furaha kukukaribisha kumuaga binti yao kwa upendo.', 'message' => 'Karibu tumtakie heri katika maisha yake mapya.'],
                'modern' => ['self' => 'Ni wakati wa Send-Off! Karibu tusherehekee pamoja.', 'one' => 'inakualika kusherehekea Send-Off ya binti yao.', 'many' => 'zinakualika kusherehekea Send-Off ya binti yao.', 'message' => 'Njoo na furaha yako, tusherehekee pamoja!'],
                'short' => ['self' => 'Karibu kwenye Send-Off.', 'one' => 'inakukaribisha kwenye Send-Off ya binti yao.', 'many' => 'zinakukaribisha kwenye Send-Off ya binti yao.', 'message' => 'Karibu sana.'],
                'faith' => ['self' => 'Kwa neema ya Mungu, karibu tumuage kwa sala na baraka.', 'one' => 'kwa shukrani kwa Mungu, inakualika kumuombea na kumuaga binti yao.', 'many' => 'kwa shukrani kwa Mungu, zinakualika kumuombea na kumuaga binti yao.', 'message' => 'Mungu ambariki katika safari yake mpya.'],
            ],
            'kitchen_party' => [
                'formal' => ['self' => 'Unakaribishwa kwa heshima katika Kitchen Party ya bibi harusi mtarajiwa.', 'one' => 'inayo heshima kukualika katika Kitchen Party ya binti yao.', 'many' => 'zinayo heshima kukualika katika Kitchen Party ya binti yao.', 'message' => 'Uwepo wako utampa faraja na furaha kubwa.'],
                'warm' => ['self' => 'Karibu tumpe zawadi, ushauri na upendo kabla ya siku yake kuu.', 'one' => 'inayo furaha kukukaribisha katika Kitchen Party ya binti yao.', 'many' => 'zinayo furaha kukukaribisha katika Kitchen Party ya binti yao.', 'message' => 'Njoo tumuandae kwa upendo na hekima.'],
                'modern' => ['self' => 'Ni Kitchen Party! Njoo na furaha, zawadi na vicheko.', 'one' => 'inakualika kwenye Kitchen Party ya binti yao.', 'many' => 'zinakualika kwenye Kitchen Party ya binti yao.', 'message' => 'Tutakula, tutacheza na kufurahi pamoja!'],
                'short' => ['self' => 'Karibu kwenye Kitchen Party.', 'one' => 'inakukaribisha kwenye Kitchen Party ya binti yao.', 'many' => 'zinakukaribisha kwenye Kitchen Party ya binti yao.', 'message' => 'Karibu sana.'],
                'faith' => ['self' => 'Kwa neema ya Mungu, karibu tumbariki bibi harusi mtarajiwa.', 'one' => 'kwa shukrani kwa Mungu, inakualika kumbariki binti yao.', 'many' => 'kwa shukrani kwa Mungu, zinakualika kumbariki binti yao.', 'message' => 'Mungu abariki maandalizi ya maisha yake mapya.'],
            ],
            'other' => [
                'formal' => ['self' => 'Unakaribishwa kwa heshima kuhudhuria sherehe yetu.', 'one' => 'inayo heshima kukualika katika sherehe yao.', 'many' => 'zinayo heshima kukualika katika sherehe yao.', 'message' => 'Uwepo wako utakuwa heshima kubwa kwetu.'],
                'warm' => ['self' => 'Tungependa kusherehekea siku hii maalum pamoja nawe.', 'one' => 'inayo furaha kukukaribisha kusherehekea pamoja nao.', 'many' => 'zinayo furaha kukukaribisha kusherehekea pamoja nao.', 'message' => 'Karibu tufurahi pamoja.'],
                'modern' => ['self' => 'Twende tusherehekee pamoja!', 'one' => 'inakualika kusherehekea pamoja nao.', 'many' => 'zinakualika kusherehekea pamoja nao.', 'message' => 'Njoo na furaha yako!'],
                'short' => ['self' => 'Karibu kwenye sherehe.', 'one' => 'inakukaribisha kwenye sherehe yao.', 'many' => 'zinakukaribisha kwenye sherehe yao.', 'message' => 'Karibu sana.'],
                'faith' => ['self' => 'Kwa shukrani kwa Mungu, karibu ujumuike nasi katika sherehe hii.', 'one' => 'kwa shukrani kwa Mungu, inakualika kujumuika nao.', 'many' => 'kwa shukrani kwa Mungu, zinakualika kujumuika nao.', 'message' => 'Mungu akubariki.'],
            ],
        ],
        'en' => [
            'wedding' => [
                'formal' => ['self' => 'We request the honour of your presence at our wedding.', 'one' => 'requests the honour of your presence at the marriage of their beloved child.', 'many' => 'request the honour of your presence at the marriage of their beloved children.', 'message' => 'Your presence would be our greatest honour.'],
                'warm' => ['self' => 'With joy in our hearts, we invite you to celebrate our wedding day with us.', 'one' => 'joyfully invites you to celebrate the wedding of their beloved child.', 'many' => 'joyfully invite you to celebrate the wedding of their beloved children.', 'message' => 'Come celebrate love, laughter and a new beginning with us.'],
                'modern' => ['self' => "We're getting married! Come celebrate with us.", 'one' => 'invites you to celebrate the wedding of their child.', 'many' => 'invite you to celebrate the wedding of their children.', 'message' => "Let's eat, dance and celebrate together!"],
                'short' => ['self' => 'Join us for our wedding.', 'one' => 'invites you to the wedding of their child.', 'many' => 'invite you to the wedding of their children.', 'message' => 'We look forward to seeing you.'],
                'faith' => ['self' => 'By the grace of God, we invite you to witness our holy matrimony.', 'one' => 'gratefully invites you, by the grace of God, to witness the marriage of their child.', 'many' => 'gratefully invite you, by the grace of God, to witness the marriage of their children.', 'message' => 'May God bless you as you share this blessed day with us.'],
            ],
            'send_off' => [
                'formal' => ['self' => 'You are cordially invited to the send-off celebration of the bride-to-be.', 'one' => 'requests the honour of your presence at the send-off of their daughter.', 'many' => 'request the honour of your presence at the send-off of their daughter.', 'message' => 'Your presence will make this day truly special.'],
                'warm' => ['self' => 'Join us as we bid her farewell with love and blessings on her new journey.', 'one' => 'joyfully invites you to bid their daughter farewell with love.', 'many' => 'joyfully invite you to bid their daughter farewell with love.', 'message' => 'Come wish her every happiness in her new life.'],
                'modern' => ['self' => "It's send-off time! Come celebrate with us.", 'one' => 'invites you to celebrate the send-off of their daughter.', 'many' => 'invite you to celebrate the send-off of their daughter.', 'message' => 'Bring your joy and celebrate with us!'],
                'short' => ['self' => 'Join us for the send-off.', 'one' => 'invites you to the send-off of their daughter.', 'many' => 'invite you to the send-off of their daughter.', 'message' => 'We look forward to seeing you.'],
                'faith' => ['self' => 'By the grace of God, join us to pray for and celebrate the bride-to-be.', 'one' => 'gratefully invites you to pray for and bid farewell to their daughter.', 'many' => 'gratefully invite you to pray for and bid farewell to their daughter.', 'message' => 'May God bless her new journey.'],
            ],
            'kitchen_party' => [
                'formal' => ['self' => 'You are cordially invited to the kitchen party of the bride-to-be.', 'one' => 'requests the honour of your presence at the kitchen party of their daughter.', 'many' => 'request the honour of your presence at the kitchen party of their daughter.', 'message' => 'Your presence will bring her great comfort and joy.'],
                'warm' => ['self' => 'Come shower her with gifts, wisdom and love before her big day.', 'one' => 'joyfully invites you to the kitchen party of their daughter.', 'many' => 'joyfully invite you to the kitchen party of their daughter.', 'message' => 'Come help us prepare her with love and wisdom.'],
                'modern' => ['self' => "It's a kitchen party! Bring your joy, gifts and laughter.", 'one' => 'invites you to the kitchen party of their daughter.', 'many' => 'invite you to the kitchen party of their daughter.', 'message' => "We'll eat, dance and have fun together!"],
                'short' => ['self' => 'Join us for the kitchen party.', 'one' => 'invites you to their daughter\'s kitchen party.', 'many' => 'invite you to their daughter\'s kitchen party.', 'message' => 'We look forward to seeing you.'],
                'faith' => ['self' => 'By the grace of God, join us to bless the bride-to-be.', 'one' => 'gratefully invites you to bless their daughter.', 'many' => 'gratefully invite you to bless their daughter.', 'message' => 'May God bless her preparations for married life.'],
            ],
            'other' => [
                'formal' => ['self' => 'You are cordially invited to our celebration.', 'one' => 'requests the honour of your presence at their celebration.', 'many' => 'request the honour of your presence at their celebration.', 'message' => 'Your presence would be an honour.'],
                'warm' => ['self' => 'We would love to celebrate this special day with you.', 'one' => 'joyfully invites you to celebrate with them.', 'many' => 'joyfully invite you to celebrate with them.', 'message' => 'Come share the joy with us.'],
                'modern' => ['self' => "Let's celebrate together!", 'one' => 'invites you to celebrate with them.', 'many' => 'invite you to celebrate with them.', 'message' => 'Bring your joy!'],
                'short' => ['self' => 'Join the celebration.', 'one' => 'invites you to their celebration.', 'many' => 'invite you to their celebration.', 'message' => 'We look forward to seeing you.'],
                'faith' => ['self' => 'With gratitude to God, join us for this celebration.', 'one' => 'gratefully invites you to join them.', 'many' => 'gratefully invite you to join them.', 'message' => 'God bless you.'],
            ],
        ],
    ];

    private const STRINGS = [
        'sw' => [
            'label' => ['wedding' => 'Mwaliko wa Harusi', 'send_off' => 'Mwaliko wa Send-Off', 'kitchen_party' => 'Mwaliko wa Kitchen Party', 'other' => 'Mwaliko'],
            'guest_label' => 'Mgeni wetu mpendwa', 'guest_sample' => 'Jina la Mgeni', 'qr_caption' => 'Onyesha QR hii mlangoni', 'code_label' => 'Namba',
            'qr_sample' => 'Mfano', 'dress_code_label' => 'Mavazi', 'time_label' => 'Saa', 'photo_hint' => 'Ongeza picha',
            'hosts_heading' => 'Kwa niaba ya familia', 'bride_family' => 'Familia ya Bi Harusi', 'groom_family' => 'Familia ya Bwana Harusi',
            'family_of' => 'Familia ya :name', 'family_of_second' => 'Familia ya :name', 'and' => 'na',
            'pass' => ['single' => 'Kadi ya mtu 1', 'double' => 'Kadi ya watu 2', 'special' => 'Kadi maalum · watu :count'],
            'months' => ['Januari', 'Februari', 'Machi', 'Aprili', 'Mei', 'Juni', 'Julai', 'Agosti', 'Septemba', 'Oktoba', 'Novemba', 'Desemba'],
            'weekdays' => ['Jumapili', 'Jumatatu', 'Jumanne', 'Jumatano', 'Alhamisi', 'Ijumaa', 'Jumamosi'],
            'periods' => ['night' => 'Usiku', 'dawn' => 'Alfajiri', 'morning' => 'Asubuhi', 'afternoon' => 'Mchana', 'evening' => 'Jioni'],
        ],
        'en' => [
            'label' => ['wedding' => 'Wedding Invitation', 'send_off' => 'Send-Off Invitation', 'kitchen_party' => 'Kitchen Party Invitation', 'other' => "You're Invited"],
            'guest_label' => 'Dear guest', 'guest_sample' => 'Guest Name', 'qr_caption' => 'Show this QR at the entrance', 'code_label' => 'Code',
            'qr_sample' => 'Sample', 'dress_code_label' => 'Dress code', 'time_label' => 'Time', 'photo_hint' => 'Add a photo',
            'hosts_heading' => 'On behalf of the families', 'bride_family' => "The Bride's Family", 'groom_family' => "The Groom's Family",
            'family_of' => 'The family of :name', 'family_of_second' => 'the family of :name', 'and' => 'and',
            'pass' => ['single' => 'Admits 1', 'double' => 'Admits 2', 'special' => 'Special pass · admits :count'],
            'months' => ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'],
            'weekdays' => ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'],
        ],
    ];

    public static function language(mixed $value, string $fallback = 'sw'): string
    {
        return is_string($value) && in_array($value, self::LANGUAGES, true) ? $value : $fallback;
    }

    public static function eventType(?string $type): string
    {
        return in_array($type, ['wedding', 'send_off', 'kitchen_party', 'other'], true) ? $type : 'wedding';
    }

    /** Invitation sentence for the tone and host arrangement. */
    public static function intro(string $language, string $eventType, string $style, string $hostMode): string
    {
        $form = match ($hostMode) {
            'bride_family', 'groom_family' => 'one',
            'both_families', 'other' => 'many',
            default => 'self',
        };

        return self::TEXT[self::language($language)][self::eventType($eventType)][in_array($style, self::STYLES, true) ? $style : 'formal'][$form];
    }

    public static function message(string $language, string $eventType, string $style): string
    {
        return self::TEXT[self::language($language)][self::eventType($eventType)][in_array($style, self::STYLES, true) ? $style : 'formal']['message'];
    }

    /** @return array<string, array{intro: string, message: string}> every tone for previews in the editor */
    public static function options(string $language, string $eventType, string $hostMode): array
    {
        $options = [];
        foreach (self::STYLES as $style) {
            $options[$style] = ['intro' => self::intro($language, $eventType, $style, $hostMode), 'message' => self::message($language, $eventType, $style)];
        }

        return $options;
    }

    public static function string(string $language, string $key, array $replace = []): string
    {
        $value = self::STRINGS[self::language($language)][$key] ?? '';
        foreach ($replace as $name => $replacement) {
            $value = str_replace(':'.$name, (string) $replacement, $value);
        }

        return $value;
    }

    public static function label(string $language, string $eventType): string
    {
        return self::STRINGS[self::language($language)]['label'][self::eventType($eventType)];
    }

    public static function passLine(string $language, string $passType, int $allowed): string
    {
        $passes = self::STRINGS[self::language($language)]['pass'];
        $line = $passes[$passType] ?? $passes['special'];

        return str_replace(':count', (string) $allowed, $line);
    }

    /** @return array{weekday: string, day: string, month: string, year: string, date_long: string, date_short: string} */
    public static function dateParts(CarbonInterface $date, string $language): array
    {
        $strings = self::STRINGS[self::language($language)];
        $weekday = $strings['weekdays'][(int) $date->dayOfWeek];
        $month = $strings['months'][(int) $date->month - 1];
        $day = (string) $date->day;
        $year = (string) $date->year;
        $long = $language === 'en' ? "$weekday, $day $month $year" : "$weekday, $day $month $year";

        return ['weekday' => $weekday, 'day' => $day, 'month' => $month, 'year' => $year, 'date_long' => $long, 'date_short' => "$day $month $year"];
    }

    /** "Saa 12:00 Jioni" (Swahili time counts from 06:00/18:00) or "6:00 PM". */
    public static function time(string $hhmm, string $language): ?string
    {
        if (preg_match('/\A(\d{2}):(\d{2})/', $hhmm, $m) !== 1 || (int) $m[1] > 23 || (int) $m[2] > 59) {
            return null;
        }
        $hour = (int) $m[1];
        if (self::language($language) === 'en') {
            return (($hour % 12) ?: 12).':'.$m[2].' '.($hour < 12 ? 'AM' : 'PM');
        }
        $period = match (true) {
            $hour < 4 || $hour >= 19 => 'night',
            $hour < 7 => 'dawn',
            $hour < 12 => 'morning',
            $hour < 16 => 'afternoon',
            default => 'evening',
        };

        return 'Saa '.((($hour + 6) % 12) ?: 12).':'.$m[2].' '.self::STRINGS['sw']['periods'][$period];
    }
}
