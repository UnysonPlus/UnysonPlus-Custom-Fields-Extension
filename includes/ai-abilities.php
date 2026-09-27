<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * AI Assistant abilities for Custom Fields.
 *
 *   custom-fields-list        field groups (where they show, their fields) and the available field types
 *   custom-fields-save-group  create or update a field group — validated against the Field Groups
 *                             editor's own option schema
 *   custom-fields-set-values  fill a post's field values — validated against the same field options
 *                             the edit screen shows
 *
 * Writes are snapshotted first (the extension's settings option, or the post's options meta) for the
 * AI Assistant's undo-change.
 */

if ( ! function_exists( 'fw_ext_custom_fields_ai_register' ) ) :

	/** @return string The option backing this extension's settings. */
	function fw_ext_custom_fields_ai_option() {
		return 'fw_ext_settings_options:custom-fields';
	}

	/**
	 * Call one of the extension's private helpers (the editor's own definitions).
	 *
	 * @param string $method
	 * @return mixed
	 */
	function fw_ext_custom_fields_ai_private( $method ) {
		$ext = fw_ext( 'custom-fields' );
		$m   = new ReflectionMethod( $ext, $method );
		if ( PHP_VERSION_ID < 80100 ) {
			$m->setAccessible( true );
		}
		return $m->invoke( $ext );
	}

	/** @return array The Field Groups option (addable-popup) from the editor's schema. */
	function fw_ext_custom_fields_ai_groups_option() {
		$opts = fw_extract_only_options( (array) fw_ext( 'custom-fields' )->get_page_options() );
		return $opts[ FW_Extension_Custom_Fields::OPTION_ID ];
	}

	/**
	 * A field row in the stored shape from a friendly definition.
	 *
	 * @param array $f      { label, name, type, choices?, settings?, description? }
	 * @param array $errors
	 * @param int   $i
	 * @return array
	 */
	function fw_ext_custom_fields_ai_field( array $f, array &$errors, $i ) {
		$types = (array) fw_ext_custom_fields_ai_private( 'field_type_choices' );
		$attrs = (array) fw_ext_custom_fields_ai_private( 'field_type_attribute_choices' );
		$type  = (string) ( $f['type'] ?? 'text' );
		$name  = (string) ( $f['name'] ?? '' );
		if ( ! isset( $types[ $type ] ) ) {
			$errors[] = sprintf( 'fields[%d].type "%s" is not a field type (see custom_fields_list).', $i, $type );
		}
		if ( ! preg_match( '/^[a-z][a-z0-9_]{0,63}$/', $name ) ) {
			$errors[] = sprintf( 'fields[%d].name "%s" must be lowercase letters, numbers and underscores (e.g. "isbn", "release_date").', $i, $name );
		}
		$sub = array();
		if ( isset( $f['choices'] ) ) {
			$lines = array();
			foreach ( (array) $f['choices'] as $k => $v ) {
				$lines[] = is_int( $k ) ? (string) $v : $k . ' : ' . $v;
			}
			$sub['choices'] = implode( "\n", $lines );
		}
		$settings = isset( $f['settings'] ) ? (array) json_decode( wp_json_encode( $f['settings'] ), true ) : array();
		$allowed  = isset( $attrs[ $type ] ) ? fw_extract_only_options( (array) $attrs[ $type ] ) : array();
		foreach ( $settings as $k => $v ) {
			if ( ! isset( $allowed[ $k ] ) ) {
				$errors[] = sprintf( 'fields[%d].settings.%s is not a setting of a %s field (valid: %s).', $i, $k, $type, implode( ', ', array_keys( $allowed ) ) ?: 'none' );
				continue;
			}
			$sub[ $k ] = $v;
		}
		return array(
			'label'       => sanitize_text_field( (string) ( $f['label'] ?? $name ) ),
			'name'        => $name,
			'field_type'  => array( 'type' => $type, $type => $sub ),
			'description' => sanitize_text_field( (string) ( $f['description'] ?? '' ) ),
			'help'        => '',
		);
	}

	function fw_ext_custom_fields_ai_register() {
		if ( ! function_exists( 'fw_ai_register_ability' ) || ! fw_ext( 'custom-fields' ) ) {
			return;
		}

		fw_ai_register_ability( 'custom-fields-list', array(
			'label'       => __( 'List custom field groups', 'fw' ),
			'description' => 'Custom field groups: title, the post types they appear on, whether active, and their fields (name, label, type, choices). Also the field types a field can use.',
			'permission'  => 'edit_posts',
			'readonly'    => true,
			'panel'       => true,
			'execute'     => function () {
				$groups = array();
				foreach ( (array) fw_ext( 'custom-fields' )->get_field_groups() as $g ) {
					$fields = array();
					foreach ( (array) ( $g['fields'] ?? array() ) as $f ) {
						$type = (string) ( $f['field_type']['type'] ?? 'text' );
						$row  = array( 'name' => (string) ( $f['name'] ?? '' ), 'label' => (string) ( $f['label'] ?? '' ), 'type' => $type );
						if ( ! empty( $f['field_type'][ $type ]['choices'] ) ) {
							$row['choices'] = array_values( array_filter( array_map( 'trim', explode( "\n", (string) $f['field_type'][ $type ]['choices'] ) ) ) );
						}
						$fields[] = $row;
					}
					$groups[] = array(
						'title'    => (string) ( $g['title'] ?? '' ),
						'location' => array_values( (array) ( $g['location'] ?? array() ) ),
						'active'   => ! isset( $g['active'] ) || ! empty( $g['active'] ),
						'fields'   => $fields,
					);
				}
				return array( 'groups' => $groups, 'field_types' => array_keys( (array) fw_ext_custom_fields_ai_private( 'field_type_choices' ) ) );
			},
		) );

		fw_ai_register_ability( 'custom-fields-save-group', array(
			'label'       => __( 'Create or update a custom field group', 'fw' ),
			'description' => 'Creates a field group (or updates the one with the same title) that adds fields to the edit screen of the given post types (location). fields: [{ label, name (lowercase_with_underscores — the key the value is stored under), type (see custom_fields_list), choices? (for select / radio / checkboxes: a list of labels or { value: label }), settings? (type-specific: default, placeholder, min, max, step …), description? }]. On update, fields are merged by name (replace_fields: true replaces the whole list). Undo with undo_change.',
			'input'       => array(
				'title'          => array( 'type' => 'string', 'minLength' => 1 ),
				'location'       => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
				'fields'         => array( 'type' => 'array', 'items' => array( 'type' => 'object' ) ),
				'replace_fields' => array( 'type' => 'boolean', 'default' => false ),
				'context'        => array( 'type' => 'string', 'enum' => array( 'normal', 'side', 'advanced' ) ),
				'active'         => array( 'type' => 'boolean' ),
				'description'    => array( 'type' => 'string' ),
			),
			'required'    => array( 'title' ),
			'permission'  => 'manage_options',
			'idempotent'  => true,
			'execute'     => 'fw_ext_custom_fields_ai_save_group',
		) );

		fw_ai_register_ability( 'custom-fields-set-values', array(
			'label'       => __( 'Fill in custom fields', 'fw' ),
			'description' => 'Sets custom field values on a post, by field name (see custom_fields_list for the fields its post type has). Values are checked against the field (a select takes one of its choices, a number a number, an image an attachment …). Undo with undo_change.',
			'input'       => array(
				'post_id' => array( 'type' => 'integer' ),
				'values'  => array( 'type' => 'object' ),
			),
			'required'    => array( 'post_id', 'values' ),
			'permission'  => 'edit_post',
			'idempotent'  => true,
			'panel'       => true,
			'execute'     => 'fw_ext_custom_fields_ai_set_values',
		) );
	}
	add_action( 'fw_ai_assistant_register_abilities', 'fw_ext_custom_fields_ai_register' );

	/**
	 * @param array $in
	 * @return array|WP_Error
	 */
	function fw_ext_custom_fields_ai_save_group( $in ) {
		$ext    = fw_ext( 'custom-fields' );
		$groups = array_values( (array) $ext->get_field_groups() );
		$title  = sanitize_text_field( (string) $in['title'] );
		$index  = null;
		foreach ( $groups as $i => $g ) {
			if ( strcasecmp( (string) ( $g['title'] ?? '' ), $title ) === 0 ) {
				$index = $i;
			}
		}
		$option = fw_ext_custom_fields_ai_groups_option();
		$row    = $index !== null
			? $groups[ $index ]
			: fw_get_options_values_from_input( (array) $option['popup-options'], array() );
		$row['title'] = $title;

		$errors = array();
		if ( isset( $in['location'] ) ) {
			$types = array_values( array_filter( array_map( 'sanitize_key', (array) $in['location'] ) ) );
			$bad   = array_diff( $types, get_post_types() );
			if ( $bad ) {
				$errors[] = 'Unknown post type(s) in location: ' . implode( ', ', $bad ) . '.';
			}
			$row['location'] = $types;
		} elseif ( $index === null ) {
			$errors[] = 'A new field group needs location (the post types it appears on).';
		}
		foreach ( array( 'context', 'description' ) as $k ) {
			if ( isset( $in[ $k ] ) ) {
				$row[ $k ] = sanitize_text_field( (string) $in[ $k ] );
			}
		}
		if ( isset( $in['active'] ) ) {
			$row['active'] = (bool) $in['active'];
		}
		if ( isset( $in['fields'] ) ) {
			$new = array();
			foreach ( array_values( (array) $in['fields'] ) as $i => $f ) {
				$new[] = fw_ext_custom_fields_ai_field( (array) $f, $errors, $i );
			}
			$names = array_column( $new, 'name' );
			if ( count( $names ) !== count( array_unique( $names ) ) ) {
				$errors[] = 'Field names must be unique within the group.';
			}
			if ( ! empty( $in['replace_fields'] ) || $index === null ) {
				$row['fields'] = $new;
			} else {
				$fields = array_values( (array) ( $row['fields'] ?? array() ) );
				foreach ( $new as $f ) {
					$pos = array_search( $f['name'], array_column( $fields, 'name' ), true );
					if ( $pos === false ) {
						$fields[] = $f;
					} else {
						$fields[ $pos ] = $f;
					}
				}
				$row['fields'] = $fields;
			}
		}
		// The whole group must satisfy the Field Groups editor's own schema.
		FW_AI_Schema::check_deep( $option, array( $row ), 'group', $errors );
		if ( $errors ) {
			return new WP_Error( 'fw_cf_ai_invalid', 'Nothing was changed: ' . implode( ' | ', array_slice( $errors, 0, 20 ) ) );
		}

		if ( $index === null ) {
			$groups[] = $row;
		} else {
			$groups[ $index ] = $row;
		}
		$rev = fw_ai_snapshot( array( 'options' => array( fw_ext_custom_fields_ai_option() ) ), 'unysonplus/custom-fields-save-group', sprintf( '%s field group "%s"', $index === null ? 'Created' : 'Updated', $title ) );
		fw_set_db_ext_settings_option( 'custom-fields', FW_Extension_Custom_Fields::OPTION_ID, $groups );
		return array(
			'ok'               => true,
			'message'          => sprintf( '%s field group "%s" with %d field(s).', $index === null ? 'Created' : 'Updated', $title, count( (array) $row['fields'] ) ),
			'location'         => array_values( (array) $row['location'] ),
			'fields'           => array_column( (array) $row['fields'], 'name' ),
			'undo_revision_id' => $rev,
		);
	}

	/**
	 * @param array $in
	 * @return array|WP_Error
	 */
	function fw_ext_custom_fields_ai_set_values( $in ) {
		$post_id = (int) $in['post_id'];
		$ext     = fw_ext( 'custom-fields' );
		$options = fw_extract_only_options( (array) $ext->_filter_inject_fields( array(), get_post_type( $post_id ) ) );
		$values  = (array) json_decode( wp_json_encode( $in['values'] ), true );
		$errors  = array();
		foreach ( $values as $name => $v ) {
			if ( ! isset( $options[ $name ] ) ) {
				$errors[] = sprintf( '%s: no such field on a %s (fields: %s).', $name, get_post_type( $post_id ), implode( ', ', array_keys( $options ) ) ?: 'none' );
				continue;
			}
			FW_AI_Schema::check_deep( $options[ $name ], $v, $name, $errors );
		}
		if ( $errors ) {
			return new WP_Error( 'fw_cf_ai_invalid', 'Nothing was changed: ' . implode( ' | ', $errors ) );
		}
		$keys = array( 'fw_options' );
		foreach ( array_keys( $values ) as $name ) {
			$keys[] = 'fw_option:' . $name;
		}
		$rev = fw_ai_snapshot( array( 'post_meta' => array( $post_id => $keys ) ), 'unysonplus/custom-fields-set-values', sprintf( 'Set %s on "%s"', implode( ', ', array_keys( $values ) ), get_the_title( $post_id ) ) );
		foreach ( $values as $name => $v ) {
			fw_set_db_post_option( $post_id, $name, $v );
		}
		$out = array();
		foreach ( array_keys( $values ) as $name ) {
			$out[ $name ] = function_exists( 'fw_get_field' ) ? fw_get_field( $name, $post_id ) : fw_get_db_post_option( $post_id, $name );
		}
		return array(
			'ok'               => true,
			'message'          => sprintf( 'Set %d field(s).', count( $values ) ),
			'post_id'          => $post_id,
			'values'           => $out,
			'undo_revision_id' => $rev,
		);
	}

endif;
