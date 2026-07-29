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

$manifest['version']    = '0.1.15';
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
