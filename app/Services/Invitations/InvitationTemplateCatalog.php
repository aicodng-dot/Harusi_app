<?php

namespace App\Services\Invitations;

/**
 * The published invitation template library: 100 designs with stable identifiers.
 *
 * Each design is a combination of a composition (layout + block order), palette,
 * typography, artwork set, frame, background treatment, pattern, motif, divider,
 * date presentation, guest-card style and QR placement. InvitationTemplateCatalogTest
 * proves every pair of designs differs in several of these structural dimensions and
 * that every design renders to a distinct image, so the library is not one card with
 * different names. The original four identifiers from Working Flow 14
 * (floral_classic, minimal_modern, royal_green, photo_elegant) are preserved.
 *
 * Templates are code, not database rows: they are reviewed and versioned with the
 * renderer that draws them, and customers only ever see this published list.
 */
class InvitationTemplateCatalog
{
    public const VERSION = 1;

    /** Style categories shown as gallery chips (after the event-type chips). */
    public const STYLE_CATEGORIES = ['floral', 'elegant', 'modern', 'luxury', 'traditional', 'botanical', 'romantic', 'photo', 'editorial', 'faith'];

    /** Event-type chips: wedding, send-off, kitchen party and general celebration. */
    public const EVENT_CATEGORIES = ['wedding', 'send_off', 'kitchen_party', 'celebration'];

    public const PALETTES = [
        'ivory_emerald' => ['bg' => '#f8f3ea', 'bg2' => '#efe6d6', 'text' => '#163f2f', 'text2' => '#3f5a4c', 'accent' => '#a9823f', 'decor' => '#b08a4a', 'border' => '#c39a5a'],
        'ivory_gold' => ['bg' => '#fbf7ef', 'bg2' => '#f3eadb', 'text' => '#2b2a26', 'text2' => '#5a5346', 'accent' => '#a8822f', 'decor' => '#c9a35a', 'border' => '#c9a35a'],
        'blush_rose' => ['bg' => '#fbf1ee', 'bg2' => '#f6e2dc', 'text' => '#6b2e2e', 'text2' => '#8a5a55', 'accent' => '#b56a62', 'decor' => '#d9a79e', 'border' => '#d4a373'],
        'burgundy_gold' => ['bg' => '#5c1426', 'bg2' => '#f6ead8', 'text' => '#f6e7c8', 'text2' => '#ead7b6', 'accent' => '#d8b26a', 'decor' => '#d8b26a', 'border' => '#d8b26a'],
        'emerald_gold' => ['bg' => '#123d31', 'bg2' => '#f8f2e6', 'text' => '#f4ead2', 'text2' => '#d9cba8', 'accent' => '#d4af6a', 'decor' => '#d4af6a', 'border' => '#d4af6a'],
        'navy_gold' => ['bg' => '#14213d', 'bg2' => '#f5efe2', 'text' => '#f3e9d2', 'text2' => '#cfc3a6', 'accent' => '#d6b36a', 'decor' => '#d6b36a', 'border' => '#d6b36a'],
        'terracotta' => ['bg' => '#f7efe6', 'bg2' => '#efe0d2', 'text' => '#4a2a1e', 'text2' => '#6b4a3a', 'accent' => '#b85a2a', 'decor' => '#b8894f', 'border' => '#c49a5c'],
        'sage' => ['bg' => '#f4f5ef', 'bg2' => '#e4e9dc', 'text' => '#2f4a3a', 'text2' => '#56705f', 'accent' => '#5f7f52', 'decor' => '#9db48f', 'border' => '#9db48f'],
        'lavender' => ['bg' => '#f7f3f8', 'bg2' => '#ece3f0', 'text' => '#3e2f52', 'text2' => '#6a5a7c', 'accent' => '#7e5f9c', 'decor' => '#b9a2cf', 'border' => '#b49ac8'],
        'charcoal' => ['bg' => '#ffffff', 'bg2' => '#f2f2f0', 'text' => '#1f1f1f', 'text2' => '#555555', 'accent' => '#1f1f1f', 'decor' => '#bdbdbd', 'border' => '#1f1f1f'],
        'sand' => ['bg' => '#f2ebe1', 'bg2' => '#e6dccd', 'text' => '#2d2a26', 'text2' => '#6a6055', 'accent' => '#8c6446', 'decor' => '#c7b299', 'border' => '#a0785a'],
        'black_gold' => ['bg' => '#111111', 'bg2' => '#f3ecdf', 'text' => '#f1e6cc', 'text2' => '#c8b991', 'accent' => '#cfa956', 'decor' => '#cfa956', 'border' => '#cfa956'],
        'royal_purple' => ['bg' => '#2e1a47', 'bg2' => '#f4eefb', 'text' => '#f2e8d5', 'text2' => '#d4c6e3', 'accent' => '#d9b56c', 'decor' => '#d9b56c', 'border' => '#d9b56c'],
        'ocean' => ['bg' => '#eef5f6', 'bg2' => '#dbe9ec', 'text' => '#18414c', 'text2' => '#44656e', 'accent' => '#226a79', 'decor' => '#8fc0c9', 'border' => '#6aa5b2'],
        'sunset' => ['bg' => '#fff4e8', 'bg2' => '#ffe2c7', 'text' => '#7a2e14', 'text2' => '#9a5435', 'accent' => '#c94f1c', 'decor' => '#f2a541', 'border' => '#e0662e'],
        'fuchsia' => ['bg' => '#fff1f6', 'bg2' => '#ffd9e7', 'text' => '#6d1240', 'text2' => '#94446b', 'accent' => '#c22a6c', 'decor' => '#f29fbf', 'border' => '#d6337a'],
        'kanga' => ['bg' => '#fdf6e3', 'bg2' => '#f6e7c1', 'text' => '#1d2f5e', 'text2' => '#44507a', 'accent' => '#c8452f', 'decor' => '#1d2f5e', 'border' => '#e0a526'],
        'forest_cream' => ['bg' => '#f6f1e4', 'bg2' => '#e9e0c9', 'text' => '#234235', 'text2' => '#4d6656', 'accent' => '#9a7634', 'decor' => '#a8823c', 'border' => '#a8823c'],
        'dusty_blue' => ['bg' => '#f2f5f9', 'bg2' => '#e0e8f1', 'text' => '#2a3d5c', 'text2' => '#56688a', 'accent' => '#5b7699', 'decor' => '#a9bcd4', 'border' => '#8aa2c2'],
        'champagne' => ['bg' => '#f9f4ec', 'bg2' => '#efe3d0', 'text' => '#5b4636', 'text2' => '#7d6754', 'accent' => '#9f7d4c', 'decor' => '#d8c3a1', 'border' => '#c8aa7c'],
        'mint' => ['bg' => '#f1faf6', 'bg2' => '#d9f0e6', 'text' => '#134e3a', 'text2' => '#3f725e', 'accent' => '#187a5b', 'decor' => '#f2b84b', 'border' => '#1f9d74'],
        'plum' => ['bg' => '#fbf3f6', 'bg2' => '#f1dfe7', 'text' => '#4b1d36', 'text2' => '#74465d', 'accent' => '#933d63', 'decor' => '#c88aa6', 'border' => '#b97a95'],
        'royal_green' => ['bg' => '#0f3b2e', 'bg2' => '#f6f0e2', 'text' => '#f3e7c9', 'text2' => '#d8caa4', 'accent' => '#d0aa60', 'decor' => '#d0aa60', 'border' => '#d0aa60'],
        'midnight_teal' => ['bg' => '#0e2f36', 'bg2' => '#eef4f2', 'text' => '#eef4f2', 'text2' => '#b9d1cf', 'accent' => '#e2b866', 'decor' => '#e2b866', 'border' => '#e2b866'],
    ];

    /** [heading, body, label, accent(script)] */
    public const FONT_SETS = [
        'p1' => ['playfair', 'cormorant', 'montserrat', 'greatvibes'],
        'p2' => ['cormorant', 'garamond', 'cinzel', 'pinyon'],
        'p3' => ['baskerville', 'lora', 'josefin', 'alexbrush'],
        'p4' => ['dmserif', 'lora', 'raleway', 'allura'],
        'p5' => ['bodoni', 'garamond', 'jost', 'parisienne'],
        'p6' => ['cinzel', 'cormorant', 'cinzel', 'italianno'],
        'p7' => ['prata', 'lora', 'montserrat', 'sacramento'],
        'p8' => ['marcellus', 'garamond', 'marcellus', 'greatvibes'],
        'p9' => ['poppins', 'poppins', 'poppins', 'dancing'],
        'p10' => ['jost', 'lato', 'jost', 'allura'],
        'p11' => ['playfair', 'lato', 'raleway', 'dancing'],
        'p12' => ['garamond', 'garamond', 'cinzel', 'pinyon'],
    ];

    /** Artwork sets: raster clusters from the approved references and vector sprigs. */
    public const DECOR_SETS = [
        'none' => [],
        'lily_cream' => [['asset' => 'lily_white_tl', 'anchor' => 'tl', 'w' => 240], ['asset' => 'rose_band_br', 'anchor' => 'br', 'w' => 330]],
        'lily_white' => [['asset' => 'lily_white_tl', 'anchor' => 'tl', 'w' => 260], ['asset' => 'lily_white_br', 'anchor' => 'br', 'w' => 290]],
        'peony' => [['asset' => 'peony_tl', 'anchor' => 'tl', 'w' => 250], ['asset' => 'peony_br', 'anchor' => 'br', 'w' => 250]],
        'garden' => [['asset' => 'rose_garden_tl', 'anchor' => 'tl', 'w' => 290], ['asset' => 'rose_garden_br', 'anchor' => 'br', 'w' => 270]],
        'lavender' => [['asset' => 'lavender_bl', 'anchor' => 'bl', 'w' => 230], ['asset' => 'lavender_right', 'anchor' => 'r', 'w' => 160, 'dy' => -260]],
        'hibiscus' => [['asset' => 'hibiscus_tl', 'anchor' => 'tl', 'w' => 260], ['asset' => 'pampas_br', 'anchor' => 'br', 'w' => 240]],
        'hibiscus_side' => [['asset' => 'hibiscus_bl', 'anchor' => 'bl', 'w' => 210], ['asset' => 'hibiscus_bl', 'anchor' => 'tr', 'w' => 190, 'flip_x' => true, 'flip_y' => true]],
        'blush' => [['asset' => 'rose_blush_br', 'anchor' => 'br', 'w' => 270], ['asset' => 'rose_blush_br', 'anchor' => 'tl', 'w' => 230, 'flip_x' => true, 'flip_y' => true]],
        'band' => [['asset' => 'lily_band_bl', 'anchor' => 'bl', 'w' => 330], ['asset' => 'rose_band_br', 'anchor' => 'br', 'w' => 330]],
        'sprig_corners' => [['asset' => 'sprig_cream_bl', 'anchor' => 'bl', 'w' => 250], ['asset' => 'sprig_cream_bl', 'anchor' => 'tr', 'w' => 250, 'flip_x' => true, 'flip_y' => true]],
        'gold_leaves' => [['asset' => 'gold_leaf_left', 'anchor' => 'l', 'w' => 150, 'dy' => 220], ['asset' => 'gold_sprig_tr', 'anchor' => 'tr', 'w' => 210, 'dx' => 20, 'dy' => 30]],
        'gold_leaves_mirror' => [['asset' => 'gold_leaf_right', 'anchor' => 'tr', 'w' => 170, 'dx' => 10], ['asset' => 'gold_leaf_left', 'anchor' => 'bl', 'w' => 150, 'dy' => 60, 'dx' => 10]],
        'burgundy_roses' => [['asset' => 'rose_burgundy_tl', 'anchor' => 'tl', 'w' => 220], ['asset' => 'rose_burgundy_br', 'anchor' => 'br', 'w' => 240]],
        'peony_gold' => [['asset' => 'peony_br', 'anchor' => 'br', 'w' => 240], ['asset' => 'gold_sprig_tr', 'anchor' => 'tl', 'w' => 210, 'flip_x' => true, 'dx' => 20, 'dy' => 30]],
        'sprigs_vector' => [
            ['type' => 'sprig', 'x' => 150, 'y' => 150, 'len' => 210, 'angle' => 45.0],
            ['type' => 'sprig', 'x' => 1050, 'y' => 150, 'len' => 210, 'angle' => 135.0],
            ['type' => 'sprig', 'x' => 150, 'y' => 1450, 'len' => 210, 'angle' => -45.0],
            ['type' => 'sprig', 'x' => 1050, 'y' => 1450, 'len' => 210, 'angle' => -135.0],
        ],
        'sprigs_side' => [
            ['type' => 'sprig', 'x' => 95, 'y' => 520, 'len' => 300, 'angle' => 90.0, 'opacity' => 0.7],
            ['type' => 'sprig', 'x' => 1105, 'y' => 520, 'len' => 300, 'angle' => 90.0, 'opacity' => 0.7],
        ],
        'sprig_crown' => [
            ['type' => 'sprig', 'x' => 470, 'y' => 120, 'len' => 230, 'angle' => 180.0],
            ['type' => 'sprig', 'x' => 730, 'y' => 120, 'len' => 230, 'angle' => 0.0],
        ],
    ];

    /** Which decor sets read well on dark grounds. */
    public const DARK_DECOR = ['none', 'gold_leaves', 'gold_leaves_mirror', 'burgundy_roses', 'peony_gold', 'sprigs_vector', 'sprigs_side', 'sprig_crown'];

    /** Composition defaults: block order, horizontal inset and alignment. */
    public const LAYOUTS = [
        'classic' => ['blocks' => ['motif', 'label', 'hosts', 'intro', 'names', 'divider', 'date', 'venue', 'extras', 'message'], 'inset' => 195],
        'families' => ['blocks' => ['label', 'hosts', 'intro', 'names', 'divider', 'date', 'venue', 'extras'], 'inset' => 170, 'hosts_style' => 'columns'],
        'editorial' => ['blocks' => ['label', 'names', 'divider', 'intro', 'date', 'venue', 'extras', 'message'], 'inset' => 150, 'align' => 'left', 'names_layout' => 'stacked'],
        'date_hero' => ['blocks' => ['label', 'date_hero', 'divider', 'names', 'intro', 'venue', 'message'], 'inset' => 170],
        'monogram' => ['blocks' => ['monogram', 'label', 'names', 'intro', 'divider', 'date', 'venue', 'extras'], 'inset' => 175],
        'photo_arch' => ['blocks' => ['label', 'names', 'divider', 'intro', 'date', 'venue', 'extras'], 'inset' => 160],
        'photo_circle' => ['blocks' => ['label', 'names', 'divider', 'intro', 'date', 'venue'], 'inset' => 160],
        'photo_oval' => ['blocks' => ['label', 'names', 'intro', 'date', 'venue', 'extras'], 'inset' => 160],
        'photo_band' => ['blocks' => ['label', 'names', 'divider', 'intro', 'date', 'venue'], 'inset' => 150],
        'panel' => ['blocks' => ['motif', 'label', 'hosts', 'names', 'divider', 'intro', 'date', 'venue', 'extras'], 'inset' => 170],
        'arch_window' => ['blocks' => ['motif', 'label', 'names', 'divider', 'intro', 'date', 'venue'], 'inset' => 210],
        'medallion' => ['blocks' => ['label', 'names', 'divider', 'intro', 'date', 'venue', 'extras'], 'inset' => 160],
        'minimal' => ['blocks' => ['label', 'names', 'divider', 'date', 'venue', 'message'], 'inset' => 210, 'content_top' => 180],
    ];

    /**
     * key => [name, category, layout, palette, fonts, decor, frame, pattern, background,
     *         motif, divider, date style, guest style, QR position, names font, extra]
     * Names font: h = heading, a = script accent.
     */
    private const ROWS = [
        // Floral
        'floral_classic' => ['Floral Classic', 'floral', 'classic', 'ivory_emerald', 'p1', 'lily_cream', 'thin', 'none', 'solid', 'flower', 'line', 'big_day', 'box', 'center', 'h'],
        'peony_romance' => ['Peony Romance', 'floral', 'families', 'forest_cream', 'p2', 'peony', 'double', 'none', 'solid', 'rings', 'diamond', 'icons', 'box', 'right', 'h'],
        'lily_garden' => ['Lily Garden', 'floral', 'classic', 'ivory_gold', 'p8', 'lily_white', 'ornate', 'none', 'solid', 'rings', 'flourish', 'row', 'plaque', 'center', 'a'],
        'english_rose' => ['English Rose', 'floral', 'monogram', 'champagne', 'p3', 'garden', 'rounded', 'none', 'gradient', 'none', 'diamond', 'row', 'box', 'center', 'a'],
        'lavender_whisper' => ['Lavender Whisper', 'floral', 'editorial', 'lavender', 'p4', 'lavender', 'none', 'none', 'solid', 'none', 'line', 'line', 'line', 'right', 'h'],
        'hibiscus_sunset' => ['Hibiscus Sunset', 'floral', 'date_hero', 'terracotta', 'p3', 'hibiscus', 'double', 'none', 'solid', 'none', 'leaves', 'big_day', 'plaque', 'center', 'h'],
        'blush_bouquet' => ['Blush Bouquet', 'floral', 'classic', 'blush_rose', 'p7', 'blush', 'rounded', 'dots', 'solid', 'heart', 'heart', 'big_day', 'box', 'right', 'a'],
        'lily_meadow_band' => ['Lily Meadow', 'floral', 'minimal', 'ivory_emerald', 'p6', 'band', 'none', 'none', 'solid', 'none', 'rings', 'line', 'line', 'center', 'h'],
        'plum_blossom' => ['Plum Blossom', 'floral', 'panel', 'plum', 'p4', 'none', 'none', 'lattice', 'solid', 'flower', 'dots', 'row', 'line', 'center', 'a', ['panel_shape' => 'rounded']],
        'peony_crest' => ['Peony Crest', 'floral', 'medallion', 'blush_rose', 'p2', 'peony', 'thin', 'none', 'gradient', 'none', 'flower', 'icons', 'ribbon', 'right', 'a'],

        // Elegant
        'ivory_elegance' => ['Ivory Elegance', 'elegant', 'classic', 'ivory_gold', 'p1', 'none', 'double', 'none', 'solid', 'rings', 'diamond', 'big_day', 'box', 'center', 'h'],
        'gilded_chamfer' => ['Gilded Chamfer', 'elegant', 'families', 'champagne', 'p12', 'gold_leaves', 'chamfer', 'none', 'solid', 'none', 'flourish', 'row', 'plaque', 'center', 'h'],
        'pearl_monogram' => ['Pearl Monogram', 'elegant', 'monogram', 'ivory_gold', 'p8', 'none', 'thin', 'dots', 'solid', 'none', 'dots', 'line', 'line', 'left', 'h'],
        'golden_arch' => ['Golden Arch', 'elegant', 'arch_window', 'champagne', 'p1', 'gold_leaves_mirror', 'none', 'none', 'glow', 'rings', 'line', 'big_day', 'box', 'center', 'a'],
        'champagne_toast' => ['Champagne Toast', 'elegant', 'date_hero', 'champagne', 'p5', 'none', 'corners', 'none', 'gradient', 'none', 'dots', 'big_day', 'ribbon', 'center', 'a'],
        'silk_ribbon' => ['Silk Ribbon', 'elegant', 'classic', 'dusty_blue', 'p2', 'none', 'rounded', 'none', 'gradient', 'sparkle', 'flourish', 'row', 'ribbon', 'right', 'a'],
        'classic_script' => ['Classic Script', 'elegant', 'minimal', 'ivory_gold', 'p5', 'sprig_crown', 'thin', 'none', 'solid', 'none', 'leaves', 'big_day', 'box', 'center', 'a'],
        'ivory_columns' => ['Ivory Columns', 'elegant', 'families', 'ivory_emerald', 'p6', 'none', 'deco', 'none', 'solid', 'none', 'diamond', 'line', 'plaque', 'left', 'h'],
        'gold_leaf_letter' => ['Gold Leaf Letter', 'elegant', 'editorial', 'ivory_gold', 'p1', 'gold_leaves_mirror', 'thin', 'none', 'solid', 'none', 'line', 'row', 'line', 'right', 'a'],

        // Modern / minimal
        'minimal_modern' => ['Minimal Modern', 'modern', 'minimal', 'sand', 'p10', 'gold_leaves', 'arch', 'none', 'solid', 'none', 'line', 'big_day', 'line', 'center', 'h'],
        'monochrome_type' => ['Monochrome Type', 'modern', 'editorial', 'charcoal', 'p5', 'none', 'corners', 'none', 'solid', 'none', 'dots', 'big_day', 'box', 'left', 'h'],
        'grid_paper' => ['Grid Paper', 'modern', 'date_hero', 'charcoal', 'p10', 'none', 'thin', 'grid', 'solid', 'none', 'dots', 'big_day', 'box', 'center', 'h'],
        'sand_dune' => ['Sand Dune', 'modern', 'classic', 'sand', 'p7', 'none', 'none', 'waves', 'gradient', 'none', 'dots', 'icons', 'box', 'right', 'h'],
        'blue_hour' => ['Blue Hour', 'modern', 'monogram', 'dusty_blue', 'p10', 'none', 'rounded', 'none', 'solid', 'none', 'line', 'line', 'line', 'center', 'h'],
        'stripe_modern' => ['Modern Stripes', 'modern', 'panel', 'charcoal', 'p9', 'none', 'none', 'stripes', 'solid', 'none', 'line', 'icons', 'box', 'center', 'h', ['panel_shape' => 'rounded']],
        'quiet_arch' => ['Quiet Arch', 'modern', 'arch_window', 'sand', 'p4', 'none', 'none', 'dots', 'solid', 'none', 'dots', 'line', 'line', 'center', 'h'],
        'clean_slate' => ['Clean Slate', 'modern', 'minimal', 'charcoal', 'p3', 'none', 'corners', 'none', 'solid', 'none', 'line', 'row', 'box', 'right', 'a'],

        // Luxury & royal
        'royal_green' => ['Royal Green', 'luxury', 'classic', 'royal_green', 'p1', 'gold_leaves', 'arch', 'none', 'vignette', 'flower', 'line', 'big_day', 'ribbon', 'center', 'h'],
        'burgundy_velvet' => ['Burgundy Velvet', 'luxury', 'families', 'burgundy_gold', 'p2', 'burgundy_roses', 'chamfer', 'none', 'vignette', 'rings', 'flourish', 'row', 'ribbon', 'center', 'a'],
        'emerald_crown' => ['Emerald Crown', 'luxury', 'classic', 'emerald_gold', 'p6', 'none', 'ornate', 'none', 'vignette', 'crown', 'diamond', 'row', 'ribbon', 'center', 'h'],
        'midnight_gold' => ['Midnight Gold', 'luxury', 'date_hero', 'navy_gold', 'p5', 'sprigs_vector', 'double', 'none', 'vignette', 'none', 'dots', 'big_day', 'line', 'center', 'a'],
        'onyx_deco' => ['Onyx Deco', 'luxury', 'monogram', 'black_gold', 'p6', 'none', 'deco', 'deco_fans', 'solid', 'none', 'diamond', 'line', 'ribbon', 'right', 'h'],
        'royal_amethyst' => ['Royal Amethyst', 'luxury', 'medallion', 'royal_purple', 'p8', 'none', 'band', 'none', 'vignette', 'crown', 'flourish', 'row', 'ribbon', 'center', 'a'],
        'gold_peony_night' => ['Gold Peony Night', 'luxury', 'classic', 'emerald_gold', 'p2', 'peony_gold', 'thin', 'none', 'vignette', 'rings', 'leaves', 'icons', 'ribbon', 'right', 'a'],
        'velvet_panel' => ['Velvet Panel', 'luxury', 'panel', 'burgundy_gold', 'p1', 'none', 'none', 'lattice', 'vignette', 'rings', 'diamond', 'big_day', 'plaque', 'center', 'a', ['panel_shape' => 'chamfer']],

        // Traditional & cultural-inspired
        'kanga_border' => ['Kanga Border', 'traditional', 'classic', 'kanga', 'p9', 'none', 'none', 'kanga', 'solid', 'none', 'diamond', 'icons', 'box', 'center', 'h', ['content_top' => 160]],
        'kitenge_panel' => ['Kitenge Panel', 'traditional', 'panel', 'kanga', 'p3', 'none', 'none', 'kanga', 'solid', 'flower', 'dots', 'row', 'plaque', 'right', 'h', ['panel_shape' => 'chamfer']],
        'swahili_coast' => ['Swahili Coast', 'traditional', 'arch_window', 'ocean', 'p2', 'none', 'none', 'stars', 'solid', 'crescent', 'diamond', 'line', 'box', 'center', 'a'],
        'zanzibar_door' => ['Zanzibar Door', 'traditional', 'families', 'terracotta', 'p6', 'none', 'arch', 'stars', 'solid', 'none', 'flourish', 'row', 'plaque', 'center', 'h'],
        'sisal_weave' => ['Sisal Weave', 'traditional', 'medallion', 'sand', 'p12', 'none', 'band', 'lattice', 'solid', 'none', 'dots', 'icons', 'box', 'right', 'h'],
        'beaded_border' => ['Beaded Border', 'traditional', 'date_hero', 'kanga', 'p6', 'none', 'none', 'kanga', 'solid', 'none', 'diamond', 'big_day', 'plaque', 'center', 'h', ['content_top' => 160]],
        'heritage_families' => ['Heritage Families', 'traditional', 'families', 'forest_cream', 'p12', 'sprigs_side', 'chamfer', 'none', 'gradient', 'none', 'leaves', 'line', 'ribbon', 'left', 'h'],

        // Botanical & garden
        'eucalyptus' => ['Eucalyptus', 'botanical', 'classic', 'sage', 'p10', 'sprigs_vector', 'corners', 'none', 'solid', 'sprig', 'leaves', 'icons', 'box', 'right', 'h'],
        'gold_sprig_arch' => ['Gold Sprig Arch', 'botanical', 'arch_window', 'forest_cream', 'p4', 'gold_leaves', 'none', 'none', 'solid', 'sprig', 'leaves', 'big_day', 'box', 'center', 'a'],
        'fern_letter' => ['Fern Letter', 'botanical', 'editorial', 'sage', 'p3', 'sprigs_side', 'thin', 'none', 'solid', 'none', 'leaves', 'line', 'line', 'right', 'h'],
        'olive_grove' => ['Olive Grove', 'botanical', 'date_hero', 'forest_cream', 'p8', 'sprig_crown', 'ornate', 'none', 'solid', 'none', 'flourish', 'big_day', 'ribbon', 'right', 'h'],
        'leaf_scatter' => ['Leaf Scatter', 'botanical', 'monogram', 'sage', 'p7', 'none', 'none', 'leaves', 'gradient', 'none', 'leaves', 'row', 'box', 'center', 'a'],
        'greenhouse' => ['Greenhouse', 'botanical', 'panel', 'sage', 'p2', 'none', 'none', 'grid', 'solid', 'sprig', 'leaves', 'line', 'ribbon', 'center', 'h', ['panel_shape' => 'rounded']],
        'cream_sprig_corner' => ['Cream Sprig Corner', 'botanical', 'classic', 'ivory_gold', 'p12', 'sprig_corners', 'none', 'none', 'solid', 'dove_branch', 'leaves', 'row', 'box', 'center', 'h'],

        // Romantic & classic
        'rose_quartz' => ['Rose Quartz', 'romantic', 'classic', 'blush_rose', 'p1', 'none', 'double', 'dots', 'gradient', 'heart', 'heart', 'big_day', 'ribbon', 'center', 'a'],
        'love_letter' => ['Love Letter', 'romantic', 'editorial', 'blush_rose', 'p7', 'blush', 'none', 'none', 'solid', 'none', 'heart', 'line', 'line', 'right', 'a'],
        'champagne_hearts' => ['Champagne Hearts', 'romantic', 'medallion', 'champagne', 'p11', 'none', 'rounded', 'dots', 'solid', 'none', 'heart', 'row', 'box', 'center', 'a'],
        'plum_romance' => ['Plum Romance', 'romantic', 'families', 'plum', 'p5', 'lavender', 'thin', 'none', 'solid', 'heart', 'flourish', 'big_day', 'plaque', 'right', 'a'],
        'garden_vows' => ['Garden Vows', 'romantic', 'panel', 'blush_rose', 'p3', 'none', 'none', 'leaves', 'solid', 'rings', 'heart', 'icons', 'box', 'center', 'a', ['panel_shape' => 'rounded']],
        'twilight_promise' => ['Twilight Promise', 'romantic', 'date_hero', 'dusty_blue', 'p7', 'sprig_crown', 'thin', 'none', 'gradient', 'none', 'heart', 'big_day', 'ribbon', 'center', 'a'],
        'blush_arch' => ['Blush Arch', 'romantic', 'arch_window', 'blush_rose', 'p3', 'none', 'none', 'dots', 'gradient', 'heart', 'flourish', 'line', 'ribbon', 'right', 'a'],
        'velvet_heart' => ['Velvet Heart', 'romantic', 'monogram', 'burgundy_gold', 'p7', 'burgundy_roses', 'thin', 'none', 'vignette', 'none', 'heart', 'row', 'ribbon', 'center', 'a'],

        // Photo-based
        'photo_elegant' => ['Photo Elegant', 'photo', 'photo_arch', 'ivory_emerald', 'p1', 'lily_cream', 'none', 'none', 'solid', 'none', 'rings', 'big_day', 'box', 'right', 'h'],
        'photo_terracotta' => ['Terracotta Portrait', 'photo', 'photo_arch', 'terracotta', 'p3', 'hibiscus', 'double', 'none', 'gradient', 'none', 'diamond', 'icons', 'plaque', 'right', 'h'],
        'photo_circle_gold' => ['Golden Portrait', 'photo', 'photo_circle', 'ivory_gold', 'p8', 'gold_leaves_mirror', 'thin', 'none', 'solid', 'none', 'flourish', 'row', 'box', 'center', 'a'],
        'photo_band_modern' => ['Cover Story', 'photo', 'photo_band', 'sand', 'p4', 'none', 'none', 'none', 'solid', 'none', 'diamond', 'line', 'line', 'left', 'h'],
        'photo_oval_blush' => ['Blush Cameo', 'photo', 'photo_oval', 'blush_rose', 'p7', 'blush', 'rounded', 'none', 'solid', 'none', 'heart', 'big_day', 'ribbon', 'right', 'a'],
        'photo_emerald_night' => ['Emerald Night Portrait', 'photo', 'photo_circle', 'emerald_gold', 'p6', 'sprigs_vector', 'double', 'none', 'vignette', 'none', 'diamond', 'row', 'ribbon', 'right', 'h'],
        'photo_garden_arch' => ['Garden Arch Portrait', 'photo', 'photo_arch', 'champagne', 'p2', 'garden', 'none', 'none', 'solid', 'none', 'leaves', 'line', 'line', 'right', 'a'],
        'photo_band_sage' => ['Sage Cover', 'photo', 'photo_band', 'sage', 'p10', 'none', 'none', 'none', 'solid', 'none', 'leaves', 'icons', 'box', 'right', 'h'],

        // Contemporary editorial
        'editorial_bold' => ['Editorial Bold', 'editorial', 'editorial', 'sand', 'p4', 'none', 'none', 'none', 'glow', 'none', 'line', 'row', 'line', 'right', 'h'],
        'the_date_issue' => ['The Date Issue', 'editorial', 'date_hero', 'charcoal', 'p4', 'none', 'none', 'stripes', 'solid', 'none', 'line', 'big_day', 'box', 'right', 'h'],
        'magazine_cover' => ['Magazine Cover', 'editorial', 'photo_band', 'navy_gold', 'p5', 'none', 'none', 'none', 'solid', 'none', 'dots', 'row', 'ribbon', 'right', 'h'],
        'serif_story' => ['Serif Story', 'editorial', 'editorial', 'ivory_emerald', 'p1', 'none', 'thin', 'none', 'solid', 'none', 'dots', 'big_day', 'line', 'left', 'h'],
        'headline_teal' => ['Headline Teal', 'editorial', 'editorial', 'midnight_teal', 'p5', 'none', 'none', 'grid', 'solid', 'none', 'line', 'line', 'ribbon', 'right', 'h'],
        'column_notes' => ['Column Notes', 'editorial', 'families', 'sand', 'p10', 'none', 'thin', 'none', 'gradient', 'none', 'line', 'icons', 'line', 'right', 'h'],

        // Religious & faith-inspired
        'grace_cross' => ['Grace', 'faith', 'classic', 'ivory_gold', 'p2', 'none', 'arch', 'none', 'glow', 'cross', 'flourish', 'row', 'box', 'center', 'h'],
        'chapel_arch' => ['Chapel Arch', 'faith', 'arch_window', 'dusty_blue', 'p12', 'none', 'none', 'none', 'gradient', 'cross', 'dots', 'line', 'box', 'center', 'a'],
        'nikah_crescent' => ['Nikah Crescent', 'faith', 'monogram', 'emerald_gold', 'p12', 'none', 'ornate', 'stars', 'vignette', 'crescent', 'diamond', 'icons', 'plaque', 'center', 'h'],
        'nikah_stars_panel' => ['Nikah Stars', 'faith', 'panel', 'midnight_teal', 'p8', 'none', 'none', 'stars', 'solid', 'crescent', 'dots', 'line', 'line', 'center', 'a', ['panel_shape' => 'chamfer']],
        'dove_blessing' => ['Blessing', 'faith', 'monogram', 'ivory_emerald', 'p12', 'sprig_crown', 'double', 'none', 'solid', 'none', 'leaves', 'icons', 'box', 'center', 'h'],
        'covenant_columns' => ['Covenant', 'faith', 'families', 'ivory_gold', 'p6', 'none', 'deco', 'none', 'glow', 'cross', 'diamond', 'big_day', 'plaque', 'right', 'h'],

        // Send-off
        'sendoff_blush' => ['Send-Off Blush', 'send_off', 'classic', 'blush_rose', 'p1', 'blush', 'thin', 'none', 'solid', 'sparkle', 'dots', 'big_day', 'ribbon', 'center', 'a'],
        'sendoff_terracotta' => ['Send-Off Terracotta', 'send_off', 'date_hero', 'terracotta', 'p9', 'hibiscus_side', 'none', 'none', 'solid', 'none', 'leaves', 'big_day', 'box', 'right', 'a'],
        'sendoff_midnight' => ['Send-Off Midnight', 'send_off', 'medallion', 'navy_gold', 'p8', 'sprigs_vector', 'thin', 'none', 'vignette', 'none', 'flourish', 'row', 'ribbon', 'center', 'a'],
        'sendoff_lavender' => ['Send-Off Lavender', 'send_off', 'panel', 'lavender', 'p7', 'none', 'none', 'dots', 'solid', 'flower', 'flower', 'icons', 'box', 'center', 'a', ['panel_shape' => 'rounded']],
        'sendoff_photo' => ['Send-Off Portrait', 'send_off', 'photo_circle', 'champagne', 'p11', 'peony', 'none', 'none', 'solid', 'none', 'dots', 'row', 'box', 'right', 'a'],
        'sendoff_garden' => ['Send-Off Garden', 'send_off', 'monogram', 'sage', 'p4', 'sprigs_side', 'rounded', 'none', 'gradient', 'none', 'leaves', 'line', 'line', 'right', 'a'],
        'sendoff_gold' => ['Send-Off Gold', 'send_off', 'arch_window', 'black_gold', 'p5', 'none', 'none', 'deco_fans', 'solid', 'sparkle', 'diamond', 'big_day', 'plaque', 'center', 'a'],
        'sendoff_kanga' => ['Send-Off Kanga', 'send_off', 'families', 'kanga', 'p9', 'none', 'none', 'kanga', 'solid', 'none', 'dots', 'row', 'ribbon', 'center', 'h', ['content_top' => 160]],

        // Kitchen party
        'kitchen_confetti' => ['Kitchen Party Confetti', 'kitchen_party', 'classic', 'sunset', 'p9', 'none', 'rounded', 'confetti', 'solid', 'sparkle', 'dots', 'big_day', 'ribbon', 'center', 'a'],
        'kitchen_fuchsia' => ['Kitchen Party Fuchsia', 'kitchen_party', 'date_hero', 'fuchsia', 'p11', 'none', 'none', 'confetti', 'gradient', 'none', 'heart', 'big_day', 'box', 'right', 'a'],
        'kitchen_mint' => ['Kitchen Party Mint', 'kitchen_party', 'panel', 'mint', 'p9', 'none', 'none', 'dots', 'solid', 'heart', 'dots', 'icons', 'ribbon', 'center', 'a', ['panel_shape' => 'rounded']],
        'kitchen_hibiscus' => ['Kitchen Party Hibiscus', 'kitchen_party', 'monogram', 'sunset', 'p11', 'hibiscus_side', 'none', 'none', 'solid', 'none', 'flower', 'row', 'box', 'center', 'a'],
        'kitchen_stripes' => ['Kitchen Party Stripes', 'kitchen_party', 'editorial', 'fuchsia', 'p9', 'none', 'none', 'stripes', 'solid', 'none', 'dots', 'line', 'ribbon', 'right', 'a'],
        'kitchen_kanga' => ['Kitchen Party Kanga', 'kitchen_party', 'classic', 'kanga', 'p11', 'none', 'none', 'kanga', 'solid', 'heart', 'dots', 'row', 'box', 'right', 'a', ['content_top' => 160]],
        'kitchen_blush_photo' => ['Kitchen Party Portrait', 'kitchen_party', 'photo_oval', 'blush_rose', 'p11', 'none', 'rounded', 'confetti', 'solid', 'none', 'heart', 'icons', 'ribbon', 'right', 'a'],
        'kitchen_sunny' => ['Kitchen Party Sunny', 'kitchen_party', 'medallion', 'sunset', 'p9', 'none', 'thin', 'waves', 'solid', 'none', 'heart', 'big_day', 'box', 'center', 'a'],

        // General celebration
        'celebration_confetti' => ['Celebration Confetti', 'celebration', 'date_hero', 'mint', 'p9', 'none', 'none', 'confetti', 'solid', 'none', 'diamond', 'big_day', 'ribbon', 'right', 'h'],
        'celebration_navy' => ['Celebration Navy', 'celebration', 'classic', 'navy_gold', 'p11', 'none', 'double', 'stars', 'vignette', 'sparkle', 'diamond', 'icons', 'ribbon', 'center', 'h'],
        'celebration_ocean' => ['Celebration Ocean', 'celebration', 'panel', 'ocean', 'p10', 'none', 'none', 'waves', 'solid', 'sparkle', 'line', 'row', 'box', 'center', 'h', ['panel_shape' => 'rounded']],
        'celebration_teal' => ['Celebration Teal', 'celebration', 'monogram', 'midnight_teal', 'p11', 'sprigs_side', 'thin', 'none', 'vignette', 'none', 'dots', 'big_day', 'plaque', 'right', 'a'],
        'celebration_minimal' => ['Celebration Minimal', 'celebration', 'minimal', 'dusty_blue', 'p10', 'none', 'corners', 'none', 'solid', 'none', 'dots', 'icons', 'line', 'center', 'h'],
        'celebration_photo' => ['Celebration Portrait', 'celebration', 'photo_band', 'sunset', 'p11', 'none', 'none', 'none', 'solid', 'none', 'dots', 'big_day', 'ribbon', 'right', 'a'],
        'celebration_gold_arch' => ['Celebration Gold Arch', 'celebration', 'arch_window', 'royal_purple', 'p1', 'none', 'none', 'deco_fans', 'vignette', 'crown', 'diamond', 'row', 'ribbon', 'center', 'h'],
    ];

    /** @var array<string, array>|null */
    private static ?array $templates = null;

    /** @return array<string, array> every published template, keyed by stable identifier */
    public static function all(): array
    {
        if (self::$templates !== null) {
            return self::$templates;
        }
        $templates = [];
        foreach (self::ROWS as $key => $row) {
            [$name, $category, $layout, $palette, $fonts, $decor, $frame, $pattern, $background, $motif, $divider, $date, $guest, $qr, $names] = $row;
            $extra = $row[15] ?? [];
            $fontSet = self::FONT_SETS[$fonts];
            $base = self::LAYOUTS[$layout];
            $templates[$key] = [
                'key' => $key,
                'name' => $name,
                'category' => $category,
                'events' => self::eventsFor($category),
                'layout' => $layout,
                'blocks' => $base['blocks'],
                'inset' => $base['inset'],
                'content_top' => $extra['content_top'] ?? ($base['content_top'] ?? 130),
                'align' => $base['align'] ?? 'center',
                'names_layout' => $base['names_layout'] ?? 'inline',
                'hosts_style' => $base['hosts_style'] ?? 'lines',
                'panel_shape' => $extra['panel_shape'] ?? ($layout === 'arch_window' ? 'arch' : 'rounded'),
                'palette' => $palette,
                'colors' => self::colorsFor($palette, $layout),
                'font_set' => $fonts,
                'fonts' => ['heading' => $fontSet[0], 'body' => $fontSet[1], 'label' => $fontSet[2], 'accent' => $fontSet[3]],
                'decor_set' => $decor,
                'decor' => self::DECOR_SETS[$decor],
                'frame' => $frame,
                'pattern' => $pattern,
                'background' => $background,
                'texture' => in_array($category, ['floral', 'elegant', 'botanical', 'romantic', 'traditional', 'faith'], true),
                'motif' => $motif,
                'divider' => $divider,
                'date_style' => $date,
                'guest_style' => $guest,
                'qr_position' => $qr,
                'names_font' => $names === 'a' ? 'accent' : 'heading',
                'photo_shape' => str_starts_with($layout, 'photo_') ? substr($layout, 6) : null,
            ];
        }

        return self::$templates = $templates;
    }

    public static function isValid(mixed $key): bool
    {
        return is_string($key) && array_key_exists($key, self::all());
    }

    public static function get(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    public static function count(): int
    {
        return count(self::all());
    }

    public static function category(mixed $value): ?string
    {
        return is_string($value) && (in_array($value, self::EVENT_CATEGORIES, true) || in_array($value, self::STYLE_CATEGORIES, true)) ? $value : null;
    }

    /** The chip matching the customer's event type. */
    public static function defaultCategory(?string $eventType): ?string
    {
        return match ($eventType) {
            'wedding' => 'wedding',
            'send_off' => 'send_off',
            'kitchen_party' => 'kitchen_party',
            'other' => 'celebration',
            default => null,
        };
    }

    /**
     * Filters by chip and free-text search over name and category labels.
     *
     * @return array<string, array>
     */
    public static function filter(?string $category, ?string $search = null): array
    {
        $templates = self::all();
        if ($category !== null) {
            $templates = array_filter($templates, function (array $template) use ($category): bool {
                if (in_array($category, self::EVENT_CATEGORIES, true)) {
                    $event = $category === 'celebration' ? 'other' : $category;

                    return in_array($event, $template['events'], true);
                }

                return $template['category'] === $category;
            });
        }
        $search = is_string($search) ? trim(mb_strtolower($search)) : '';
        if ($search !== '') {
            $templates = array_filter($templates, fn (array $template) => str_contains(mb_strtolower($template['name'].' '.$template['category'].' '.$template['palette'].' '.$template['layout']), $search));
        }

        return $templates;
    }

    /**
     * Panel compositions put text on the light panel (bg2). Dark palettes therefore
     * take their text colours from the dark ground so panel text stays readable.
     */
    private static function colorsFor(string $palette, string $layout): array
    {
        $colors = self::PALETTES[$palette];
        if (in_array($layout, ['panel', 'arch_window'], true) && Render\Canvas::luminance($colors['bg']) < 0.3) {
            $colors['text'] = $colors['bg'];
            $colors['text2'] = Render\Canvas::mix($colors['bg'], $colors['bg2'], 0.35);
            $colors['accent'] = Render\Canvas::mix($colors['accent'], '#000000', 0.35);
        }

        return $colors;
    }

    /** @return list<string> event types a style category suits */
    private static function eventsFor(string $category): array
    {
        return match ($category) {
            'send_off' => ['send_off'],
            'kitchen_party' => ['kitchen_party'],
            'celebration' => ['other', 'kitchen_party', 'send_off'],
            'modern', 'editorial', 'photo' => ['wedding', 'send_off', 'other'],
            default => ['wedding', 'send_off'],
        };
    }
}
