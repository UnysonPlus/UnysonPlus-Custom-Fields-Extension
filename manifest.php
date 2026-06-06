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

$manifest['version']    = '0.1.10';
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
