<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

$manifest = array();

$manifest['name']        = __( 'Custom Fields', 'fw' );
$manifest['slug']        = 'unysonplus-custom-fields';
$manifest['description'] = __(
	'An ACF-style custom fields builder for Unyson+. Create Field Groups, choose which post types they show on, and add fields (text, textarea, WYSIWYG, image, gallery, select, checkbox, color, date and more). Fields render as native meta boxes and save to post meta — read them on the front end with fw_get_field( "name" ).',
	'fw'
);

$manifest['version']    = '0.1.19';
$manifest['github_update'] = 'UnysonPlus/UnysonPlus-Custom-Fields-Extension';
$manifest['display']    = true;
$manifest['standalone'] = true;

// Author Info
$manifest['author']     = 'UnysonPlus';
$manifest['author_uri'] = 'https://www.lastimosa.com.ph/unysonplus';

// Meta
$manifest['license']      = 'GPL-2.0-or-later';
$manifest['text_domain']  = 'fw';
$manifest['requires_php'] = '7.4';
$manifest['requires_wp']  = '5.8';

/**
 * Changelog ----------------------------------------------------------------
 *
 * 0.1.19 - AI Assistant abilities. With the AI Assistant extension active, the AI can
 *         work with custom fields: custom-fields-list, custom-fields-save-group (validated against the
 *         Field Groups editor schema) and custom-fields-set-values — undoable through undo_change / page revisions.
 *         See includes/ai-abilities.php.
 *
 *
 * 0.1.18 - Block Bindings support (WP 6.5+). Registers an `unysonplus/field`
 *          binding source so core blocks - Paragraph, Heading, Image, Button -
 *          can pull their content/url from a Custom Fields value with no custom
 *          block: bind an attribute with source `unysonplus/field` and
 *          `args.key = <field name>`. The resolver reads the value with the same
 *          `fw_get_db_post_option()` the REST field uses, keyed off the block's
 *          post context (postId/postType), and coerces it to what the attribute
 *          needs (an image/file field binds its URL). Only fields of an ACTIVE
 *          group targeting the post's type resolve - an unknown key returns null
 *          rather than exposing arbitrary stored options. A block-editor picker
 *          adds a "Unyson+ Field Binding" panel to those core blocks so a field
 *          can be bound from the sidebar, not just in block markup - covering
 *          Paragraph/Heading text, Button label + link, and Image link + alt.
 *          This makes the
 *          field data layer a first-class citizen of the block editor and is the
 *          foundation for the converter's data-driven block-theme output.
 *
 * 0.1.15 - Eighteen new field types, most of them exposing option types the
 *          framework already shipped but Custom Fields never offered. The
 *          headline additions are relationships - Related posts, Taxonomy terms
 *          and Users - which drive Unyson's multi-select in its `posts` /
 *          `taxonomy` / `users` population modes, giving AJAX-searched pickers
 *          with a configurable source and item limit (set the limit to 1 for a
 *          single relationship). Also added: Embed (oEmbed with preview), Icon,
 *          Date & time, Time, Date range, Slider, Range, Measurement (value +
 *          unit), Code / HTML, Image choice, Color with transparency, Color
 *          (theme preset - routes through the shared preset picker so field
 *          colors stay tied to Theme Settings), Location (map), List (a
 *          repeating single value), and a Repeater variant whose rows are edited
 *          in a popup instead of inline. The repeater sub-field line syntax
 *          gained oembed, icon, datetime and time. The map type degrades to a
 *          plain text input with an explanation when no Google Maps API key is
 *          configured, rather than rendering an empty grey box.
 *
 * --------------------------------------------------------------------------
 */
