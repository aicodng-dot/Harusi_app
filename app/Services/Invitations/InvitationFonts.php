<?php

namespace App\Services\Invitations;

/**
 * The approved invitation font set (SIL Open Font License, Google Fonts static TTFs in
 * public/assets/invitation/fonts with each family's OFL text under licenses/).
 *
 * Only these keys can be stored in a design. The renderer (GD/FreeType) and the editor's
 * font picker (@font-face) read the same files, so preview and export use one face.
 */
class InvitationFonts
{
    public const DIRECTORY = 'assets/invitation/fonts';

    /** @var array<string, array{label: string, kind: string, regular: string, bold: string, italic: string}> */
    public const FONTS = [
        'playfair' => ['label' => 'Playfair Display', 'kind' => 'serif', 'regular' => 'PlayfairDisplay-400', 'bold' => 'PlayfairDisplay-700', 'italic' => 'PlayfairDisplay-400i'],
        'cormorant' => ['label' => 'Cormorant Garamond', 'kind' => 'serif', 'regular' => 'CormorantGaramond-500', 'bold' => 'CormorantGaramond-600', 'italic' => 'CormorantGaramond-500i'],
        'cinzel' => ['label' => 'Cinzel', 'kind' => 'serif', 'regular' => 'Cinzel-400', 'bold' => 'Cinzel-600', 'italic' => 'Cinzel-400'],
        'baskerville' => ['label' => 'Libre Baskerville', 'kind' => 'serif', 'regular' => 'LibreBaskerville-400', 'bold' => 'LibreBaskerville-700', 'italic' => 'LibreBaskerville-400i'],
        'lora' => ['label' => 'Lora', 'kind' => 'serif', 'regular' => 'Lora-400', 'bold' => 'Lora-600', 'italic' => 'Lora-400i'],
        'garamond' => ['label' => 'EB Garamond', 'kind' => 'serif', 'regular' => 'EBGaramond-400', 'bold' => 'EBGaramond-600', 'italic' => 'EBGaramond-400i'],
        'marcellus' => ['label' => 'Marcellus', 'kind' => 'serif', 'regular' => 'Marcellus-400', 'bold' => 'Marcellus-400', 'italic' => 'Marcellus-400'],
        'prata' => ['label' => 'Prata', 'kind' => 'serif', 'regular' => 'Prata-400', 'bold' => 'Prata-400', 'italic' => 'Prata-400'],
        'dmserif' => ['label' => 'DM Serif Display', 'kind' => 'serif', 'regular' => 'DMSerifDisplay-400', 'bold' => 'DMSerifDisplay-400', 'italic' => 'DMSerifDisplay-400i'],
        'bodoni' => ['label' => 'Bodoni Moda', 'kind' => 'serif', 'regular' => 'BodoniModa-400', 'bold' => 'BodoniModa-600', 'italic' => 'BodoniModa-400'],
        'greatvibes' => ['label' => 'Great Vibes', 'kind' => 'script', 'regular' => 'GreatVibes-400', 'bold' => 'GreatVibes-400', 'italic' => 'GreatVibes-400'],
        'parisienne' => ['label' => 'Parisienne', 'kind' => 'script', 'regular' => 'Parisienne-400', 'bold' => 'Parisienne-400', 'italic' => 'Parisienne-400'],
        'allura' => ['label' => 'Allura', 'kind' => 'script', 'regular' => 'Allura-400', 'bold' => 'Allura-400', 'italic' => 'Allura-400'],
        'alexbrush' => ['label' => 'Alex Brush', 'kind' => 'script', 'regular' => 'AlexBrush-400', 'bold' => 'AlexBrush-400', 'italic' => 'AlexBrush-400'],
        'dancing' => ['label' => 'Dancing Script', 'kind' => 'script', 'regular' => 'DancingScript-500', 'bold' => 'DancingScript-700', 'italic' => 'DancingScript-500'],
        'pinyon' => ['label' => 'Pinyon Script', 'kind' => 'script', 'regular' => 'PinyonScript-400', 'bold' => 'PinyonScript-400', 'italic' => 'PinyonScript-400'],
        'italianno' => ['label' => 'Italianno', 'kind' => 'script', 'regular' => 'Italianno-400', 'bold' => 'Italianno-400', 'italic' => 'Italianno-400'],
        'sacramento' => ['label' => 'Sacramento', 'kind' => 'script', 'regular' => 'Sacramento-400', 'bold' => 'Sacramento-400', 'italic' => 'Sacramento-400'],
        'montserrat' => ['label' => 'Montserrat', 'kind' => 'sans', 'regular' => 'Montserrat-400', 'bold' => 'Montserrat-600', 'italic' => 'Montserrat-500'],
        'josefin' => ['label' => 'Josefin Sans', 'kind' => 'sans', 'regular' => 'JosefinSans-400', 'bold' => 'JosefinSans-600', 'italic' => 'JosefinSans-400'],
        'poppins' => ['label' => 'Poppins', 'kind' => 'sans', 'regular' => 'Poppins-400', 'bold' => 'Poppins-600', 'italic' => 'Poppins-500'],
        'raleway' => ['label' => 'Raleway', 'kind' => 'sans', 'regular' => 'Raleway-400', 'bold' => 'Raleway-600', 'italic' => 'Raleway-400'],
        'jost' => ['label' => 'Jost', 'kind' => 'sans', 'regular' => 'Jost-400', 'bold' => 'Jost-500', 'italic' => 'Jost-400'],
        'lato' => ['label' => 'Lato', 'kind' => 'sans', 'regular' => 'Lato-400', 'bold' => 'Lato-700', 'italic' => 'Lato-400'],
    ];

    public static function isValid(mixed $key): bool
    {
        return is_string($key) && array_key_exists($key, self::FONTS);
    }

    /** Absolute TTF path for a font key and weight ('regular', 'bold' or 'italic'). */
    public static function path(string $key, string $weight = 'regular'): string
    {
        $font = self::FONTS[$key] ?? self::FONTS['playfair'];

        return public_path(self::DIRECTORY.'/'.($font[$weight] ?? $font['regular']).'.ttf');
    }

    public static function url(string $key, string $weight = 'regular'): string
    {
        $font = self::FONTS[$key] ?? self::FONTS['playfair'];

        return asset(self::DIRECTORY.'/'.($font[$weight] ?? $font['regular']).'.ttf');
    }

    /** @return array<string, string> key => label, for the editor picker. */
    public static function options(): array
    {
        return array_map(fn (array $font) => $font['label'], self::FONTS);
    }
}
