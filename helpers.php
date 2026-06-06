<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

if ( ! function_exists( 'fw_get_field' ) ) {
	/**
	 * Read a custom field value saved by the Custom Fields extension.
	 *
	 * Thin wrapper over fw_get_db_post_option(): the field's "name" is the post
	 * meta / option id, so this resolves to whatever the field stored (string,
	 * array, upload array, etc.).
	 *
	 * @param string   $name    The field name (its key).
	 * @param int|null $post_id Post id; defaults to the current post in the loop.
	 * @param mixed    $default Returned when nothing is stored.
	 *
	 * @return mixed
	 */
	function fw_get_field( $name, $post_id = null, $default = null ) {
		if ( $post_id === null ) {
			$post_id = get_the_ID();
		}
		if ( ! $post_id ) {
			return $default;
		}

		$value = fw_get_db_post_option( (int) $post_id, $name, null );

		return ( $value === null ) ? $default : $value;
	}
}
