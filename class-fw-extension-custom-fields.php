<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Custom Fields extension.
 *
 * An ACF-style field builder on top of Unyson's existing plumbing:
 *
 *   - The admin page (Unyson+ -> Custom Fields) is an `addable-popup` of Field
 *     Groups. Each group has a title, a location (which post type(s) it shows
 *     on), a meta-box position, and an inline `addable-box` list of fields.
 *   - Each field has a label, a name (its meta key), a type chosen from a
 *     curated list, optional choices and instructions.
 *   - On the `fw_post_options` filter we turn each matching group into a `box`
 *     option, so the framework's existing meta-box engine renders the fields and
 *     saves their values to post meta (fw_get_db_post_option / fw_set...).
 *   - Read values on the front end with fw_get_field( $name [, $post_id ] ).
 *
 * Storage: the whole list lives in the extension settings store under the
 * `field_groups` option id (WP option: fw_ext_settings_options:custom-fields).
 */
class FW_Extension_Custom_Fields extends FW_Extension {

	const PARENT_SLUG = 'fw-extensions';
	const PAGE_SLUG   = 'fw-custom-fields';
	const CAPABILITY  = 'manage_options';
	const OPTION_ID   = 'field_groups';
	const NONCE       = 'fw_ext_custom_fields_save';

	/** @var string|null Hook suffix returned by add_submenu_page() */
	private $hook_suffix = null;

	/**
	 * @internal
	 */
	public function _init() {
		// Inject each matching group's fields into the post edit screen. The
		// framework applies this filter when building meta boxes and when saving.
		add_filter( 'fw_post_options', array( $this, '_filter_inject_fields' ), 10, 2 );

		// Optionally expose field values in the REST API.
		add_action( 'rest_api_init', array( $this, '_action_register_rest_fields' ) );

		if ( is_admin() ) {
			// Priority 20: after the parent Unyson+ menu (10), before
			// Shortcodes / Component Presets (100). The Post Types extension
			// enforces the final left-to-right order at priority 999.
			add_action( 'admin_menu', array( $this, '_action_admin_menu' ), 20 );
			add_action( 'admin_enqueue_scripts', array( $this, '_action_enqueue' ) );

			// Late priority so core meta boxes are already registered when we
			// remove the ones a matching group opts to hide.
			add_action( 'add_meta_boxes', array( $this, '_action_hide_on_screen' ), 1000, 1 );
		}
	}

	/* ---------------------------------------------------------------------- *
	 * Field groups + injection
	 * ---------------------------------------------------------------------- */

	/**
	 * The saved field group definitions.
	 *
	 * @return array[]
	 */
	public function get_field_groups() {
		$value = fw_get_db_ext_settings_option( $this->get_name(), self::OPTION_ID, array() );

		return is_array( $value ) ? $value : array();
	}

	/**
	 * Field groups ordered by their "Order" number (ascending, stable). Each
	 * entry is array( 'index' => original index, 'order' => int, 'group' => … ).
	 * The original index is kept so each group gets a stable meta-box id.
	 *
	 * @return array[]
	 */
	private function ordered_groups() {
		$rows = array();
		foreach ( $this->get_field_groups() as $i => $group ) {
			if ( ! is_array( $group ) ) {
				continue;
			}
			$rows[] = array(
				'index' => $i,
				'order' => ( isset( $group['menu_order'] ) && is_numeric( $group['menu_order'] ) ) ? (int) $group['menu_order'] : 0,
				'group' => $group,
			);
		}

		usort( $rows, function ( $a, $b ) {
			return ( $a['order'] <=> $b['order'] ) ?: ( $a['index'] <=> $b['index'] );
		} );

		return $rows;
	}

	/**
	 * Whether a group is active. Groups saved before the toggle existed default
	 * to active.
	 *
	 * @param array $group
	 *
	 * @return bool
	 */
	private function group_active( $group ) {
		return array_key_exists( 'active', $group ) ? (bool) $group['active'] : true;
	}

	private function group_show_in_rest( $group ) {
		return array_key_exists( 'show_in_rest', $group ) ? (bool) $group['show_in_rest'] : false;
	}

	/**
	 * @internal
	 * Register a `unysonplus_fields` REST field on every post type that has at
	 * least one active group opted into REST.
	 */
	public function _action_register_rest_fields() {
		$post_types = array();

		foreach ( $this->get_field_groups() as $group ) {
			if ( ! is_array( $group ) || ! $this->group_active( $group ) || ! $this->group_show_in_rest( $group ) ) {
				continue;
			}
			foreach ( $this->parse_post_types( isset( $group['location'] ) ? $group['location'] : array() ) as $pt ) {
				$post_types[ $pt ] = true;
			}
		}

		if ( empty( $post_types ) ) {
			return;
		}

		register_rest_field( array_keys( $post_types ), 'unysonplus_fields', array(
			'get_callback' => array( $this, '_rest_get_fields' ),
			'schema'       => null,
		) );
	}

	/**
	 * @internal
	 * REST get_callback: the values of every REST-enabled active group's fields
	 * for this post.
	 *
	 * @param array $post_arr
	 *
	 * @return array
	 */
	public function _rest_get_fields( $post_arr ) {
		$post_id   = isset( $post_arr['id'] ) ? (int) $post_arr['id'] : 0;
		$post_type = isset( $post_arr['type'] ) ? (string) $post_arr['type'] : '';
		$out       = array();

		if ( ! $post_id ) {
			return $out;
		}

		foreach ( $this->get_field_groups() as $group ) {
			if ( ! is_array( $group ) || ! $this->group_active( $group ) || ! $this->group_show_in_rest( $group ) ) {
				continue;
			}
			if ( ! in_array( $post_type, $this->parse_post_types( isset( $group['location'] ) ? $group['location'] : array() ), true ) ) {
				continue;
			}

			$fields = ( isset( $group['fields'] ) && is_array( $group['fields'] ) ) ? $group['fields'] : array();
			foreach ( $fields as $field ) {
				if ( ! is_array( $field ) ) {
					continue;
				}
				$name = isset( $field['name'] ) ? $this->sanitize_field_name( $field['name'] ) : '';
				if ( $name === '' ) {
					continue;
				}
				$out[ $name ] = fw_get_db_post_option( $post_id, $name );
			}
		}

		return $out;
	}

	/**
	 * Evaluate a group's optional page-template / post-status refinements against
	 * the post currently being edited. Empty refinements always match.
	 *
	 * @param array $group
	 *
	 * @return bool
	 */
	private function matches_refinements( $group ) {
		$templates = $this->as_list( isset( $group['page_templates'] ) ? $group['page_templates'] : array() );
		$statuses  = $this->as_list( isset( $group['post_statuses'] ) ? $group['post_statuses'] : array() );

		if ( empty( $templates ) && empty( $statuses ) ) {
			return true;
		}

		$post = $this->current_post();

		if ( ! empty( $templates ) ) {
			$current = $post ? get_page_template_slug( $post->ID ) : '';
			if ( ! $current ) {
				$current = 'default';
			}
			if ( ! in_array( $current, $templates, true ) ) {
				return false;
			}
		}

		if ( ! empty( $statuses ) ) {
			$current = $post ? get_post_status( $post->ID ) : '';
			if ( $current === 'auto-draft' ) {
				$current = 'draft'; // a brand-new post counts as a draft
			}
			if ( ! in_array( $current, $statuses, true ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * The post being edited, from the global or the request. Null if none.
	 *
	 * @return WP_Post|null
	 */
	private function current_post() {
		global $post;
		if ( $post instanceof WP_Post ) {
			return $post;
		}

		$id = isset( $_GET['post'] ) ? (int) $_GET['post'] : ( isset( $_POST['post_ID'] ) ? (int) $_POST['post_ID'] : 0 );

		return $id ? get_post( $id ) : null;
	}

	/**
	 * Normalise a multi-select value into a clean list of non-empty strings.
	 * (Unlike post-type slugs, template filenames keep dots/slashes, so this does
	 * not sanitize_key.)
	 *
	 * @param mixed $raw
	 *
	 * @return string[]
	 */
	private function as_list( $raw ) {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$out = array();
		foreach ( $raw as $v ) {
			$v = trim( (string) $v );
			if ( $v !== '' ) {
				$out[] = $v;
			}
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * Choices for the "Page templates" refinement: every template the active
	 * theme exposes across registered post types, plus the default.
	 *
	 * @return array
	 */
	private function page_template_choices() {
		$choices = array( 'default' => __( 'Default Template', 'fw' ) );

		$theme = wp_get_theme();
		if ( $theme ) {
			foreach ( get_post_types( array( 'public' => true ), 'names' ) as $pt ) {
				foreach ( (array) $theme->get_page_templates( null, $pt ) as $file => $name ) {
					$choices[ $file ] = $name;
				}
			}
		}

		return $choices;
	}

	/**
	 * Choices for the "Post statuses" refinement.
	 *
	 * @return array
	 */
	private function post_status_choices() {
		return array(
			'publish' => __( 'Published', 'fw' ),
			'draft'   => __( 'Draft', 'fw' ),
			'pending' => __( 'Pending Review', 'fw' ),
			'future'  => __( 'Scheduled', 'fw' ),
			'private' => __( 'Private', 'fw' ),
		);
	}

	/**
	 * @internal
	 * Remove the default panels that active groups targeting this post type opt
	 * to hide. Runs late on `add_meta_boxes`, after the core boxes exist.
	 *
	 * @param string $post_type
	 */
	public function _action_hide_on_screen( $post_type ) {
		$hide = array();

		foreach ( $this->ordered_groups() as $row ) {
			$group = $row['group'];
			if ( ! $this->group_active( $group ) ) {
				continue;
			}
			$locations = $this->parse_post_types( isset( $group['location'] ) ? $group['location'] : array() );
			if ( ! in_array( $post_type, $locations, true ) ) {
				continue;
			}
			if ( ! $this->matches_refinements( $group ) ) {
				continue;
			}
			$hide = array_merge( $hide, $this->selected_keys( isset( $group['hide_on_screen'] ) ? $group['hide_on_screen'] : array() ) );
		}

		if ( empty( $hide ) ) {
			return;
		}

		$map = $this->hide_on_screen_meta_boxes();
		foreach ( array_unique( $hide ) as $key ) {
			if ( isset( $map[ $key ] ) ) {
				remove_meta_box( $map[ $key ][0], $post_type, $map[ $key ][1] );
			}
		}
	}

	/**
	 * Choices for the "Hide on screen" checkboxes.
	 *
	 * @return array
	 */
	private function hide_on_screen_choices() {
		return array(
			'excerpt'         => __( 'Excerpt', 'fw' ),
			'discussion'      => __( 'Discussion', 'fw' ),
			'comments'        => __( 'Comments', 'fw' ),
			'revisions'       => __( 'Revisions', 'fw' ),
			'slug'            => __( 'Slug', 'fw' ),
			'author'          => __( 'Author', 'fw' ),
			'format'          => __( 'Format', 'fw' ),
			'page_attributes' => __( 'Page Attributes', 'fw' ),
			'featured_image'  => __( 'Featured Image', 'fw' ),
			'categories'      => __( 'Categories', 'fw' ),
			'tags'            => __( 'Tags', 'fw' ),
			'send_trackbacks' => __( 'Send Trackbacks', 'fw' ),
		);
	}

	/**
	 * Map each "Hide on screen" key to its core meta box id + context.
	 *
	 * @return array
	 */
	private function hide_on_screen_meta_boxes() {
		return array(
			'excerpt'         => array( 'postexcerpt', 'normal' ),
			'discussion'      => array( 'commentstatusdiv', 'normal' ),
			'comments'        => array( 'commentsdiv', 'normal' ),
			'revisions'       => array( 'revisionsdiv', 'normal' ),
			'slug'            => array( 'slugdiv', 'normal' ),
			'author'          => array( 'authordiv', 'normal' ),
			'format'          => array( 'formatdiv', 'side' ),
			'page_attributes' => array( 'pageparentdiv', 'side' ),
			'featured_image'  => array( 'postimagediv', 'side' ),
			'categories'      => array( 'categorydiv', 'side' ),
			'tags'            => array( 'tagsdiv-post_tag', 'side' ),
			'send_trackbacks' => array( 'trackbacksdiv', 'normal' ),
		);
	}

	/**
	 * Read a `checkboxes` value (choice_id => true) into a flat list of selected
	 * choice ids.
	 *
	 * @param mixed $value
	 *
	 * @return string[]
	 */
	private function selected_keys( $value ) {
		return is_array( $value ) ? array_keys( array_filter( $value ) ) : array();
	}

	/**
	 * @internal
	 * Append a meta box of fields for every group whose location matches the
	 * current post type.
	 *
	 * @param array  $options
	 * @param string $post_type
	 *
	 * @return array
	 */
	public function _filter_inject_fields( $options, $post_type ) {
		foreach ( $this->ordered_groups() as $row ) {
			$group = $row['group'];
			$i     = $row['index'];

			if ( ! $this->group_active( $group ) ) {
				continue;
			}

			$locations = $this->parse_post_types( isset( $group['location'] ) ? $group['location'] : array() );
			if ( ! in_array( $post_type, $locations, true ) ) {
				continue;
			}
			if ( ! $this->matches_refinements( $group ) ) {
				continue;
			}

			$fields        = ( isset( $group['fields'] ) && is_array( $group['fields'] ) ) ? $group['fields'] : array();
			$field_options = array();

			// Optional group description, rendered as a note above the fields.
			if ( isset( $group['description'] ) && $group['description'] !== '' ) {
				$field_options['__cf_desc'] = array(
					'type'  => 'html',
					'label' => false,
					'html'  => '<p class="description" style="margin:0 0 .5em">' . esc_html( $group['description'] ) . '</p>',
				);
			}

			foreach ( $fields as $field ) {
				if ( ! is_array( $field ) ) {
					continue;
				}
				$name = isset( $field['name'] ) ? $this->sanitize_field_name( $field['name'] ) : '';
				if ( $name === '' ) {
					continue;
				}
				$field_options[ $name ] = $this->build_field_option( $field );
			}

			if ( empty( $field_options ) || ( count( $field_options ) === 1 && isset( $field_options['__cf_desc'] ) ) ) {
				continue;
			}

			$title = ( isset( $group['display_title'] ) && $group['display_title'] !== '' )
				? $group['display_title']
				: ( ( isset( $group['title'] ) && $group['title'] !== '' ) ? $group['title'] : __( 'Custom Fields', 'fw' ) );
			$context = ( isset( $group['context'] ) && in_array( $group['context'], array( 'normal', 'side', 'advanced' ), true ) )
				? $group['context']
				: 'normal';

			$options[ 'fw_cf_group_' . $i ] = array(
				'type'    => 'box',
				'title'   => $title,
				'context' => $context,
				'options' => $field_options,
			);
		}

		return $options;
	}

	/**
	 * Map one saved field definition to an Unyson option array.
	 *
	 * @param array $field
	 *
	 * @return array
	 */
	private function build_field_option( $field ) {
		$ft   = ( isset( $field['field_type'] ) && is_array( $field['field_type'] ) ) ? $field['field_type'] : array();
		$type = isset( $ft['type'] ) ? (string) $ft['type'] : 'text';
		$sub  = ( isset( $ft[ $type ] ) && is_array( $ft[ $type ] ) ) ? $ft[ $type ] : array();

		$base = array(
			'label' => isset( $field['label'] ) ? (string) $field['label'] : '',
			'desc'  => isset( $field['description'] ) ? (string) $field['description'] : '',
			'help'  => isset( $field['help'] ) ? (string) $field['help'] : '',
		);

		switch ( $type ) {
			case 'textarea':
				$o = array_merge( $base, array(
					'type'            => 'textarea',
					'dynamic_content' => $this->sub_bool( $sub, 'dynamic_content', true ),
				) );
				if ( isset( $sub['default'] ) && $sub['default'] !== '' ) {
					$o['value'] = (string) $sub['default'];
				}
				return $o;

			case 'wysiwyg':
				return array_merge( $base, array(
					'type'            => 'wp-editor',
					'dynamic_content' => $this->sub_bool( $sub, 'dynamic_content', true ),
				) );

			case 'number':
				$o = array_merge( $base, array( 'type' => 'number' ) );
				foreach ( array( 'min', 'max', 'step' ) as $k ) {
					if ( isset( $sub[ $k ] ) && $sub[ $k ] !== '' && is_numeric( $sub[ $k ] ) ) {
						$o[ $k ] = $sub[ $k ] + 0;
					}
				}
				if ( isset( $sub['default'] ) && $sub['default'] !== '' && is_numeric( $sub['default'] ) ) {
					$o['value'] = $sub['default'] + 0;
				}
				return $o;

			case 'short-text':
				return $this->text_with_extras( $base, $sub, 'short-text' );
			case 'medium-text':
				return $this->text_with_extras( $base, $sub, 'medium-text' );

			case 'url':
			case 'email':
				return $this->text_with_extras( $base, $sub );

			case 'image':
				return array_merge( $base, array( 'type' => 'upload', 'images_only' => true ) );
			case 'file':
				return array_merge( $base, array( 'type' => 'upload', 'images_only' => false ) );
			case 'gallery':
				return array_merge( $base, array( 'type' => 'multi-upload' ) );

			case 'select':
			case 'short-select':
				$o = array_merge( $base, array( 'type' => $type, 'choices' => $this->parse_choices_str( isset( $sub['choices'] ) ? $sub['choices'] : '' ) ) );
				if ( isset( $sub['default'] ) && $sub['default'] !== '' ) {
					$o['value'] = (string) $sub['default'];
				}
				return $o;
			case 'radio':
				$o = array_merge( $base, array( 'type' => 'radio', 'choices' => $this->parse_choices_str( isset( $sub['choices'] ) ? $sub['choices'] : '' ) ) );
				if ( isset( $sub['default'] ) && $sub['default'] !== '' ) {
					$o['value'] = (string) $sub['default'];
				}
				return $o;
			case 'checkboxes':
				return array_merge( $base, array( 'type' => 'checkboxes', 'choices' => $this->parse_choices_str( isset( $sub['choices'] ) ? $sub['choices'] : '' ) ) );

			case 'checkbox':
				$o = array_merge( $base, array( 'type' => 'checkbox' ) );
				if ( isset( $sub['default'] ) ) {
					$o['value'] = (bool) $sub['default'];
				}
				return $o;
			case 'switch':
				$o = array_merge( $base, array( 'type' => 'switch' ) );
				if ( isset( $sub['default'] ) && $sub['default'] !== '' ) {
					$o['value'] = $sub['default'];
				}
				return $o;

			case 'color':
				$o = array_merge( $base, array( 'type' => 'color-picker' ) );
				if ( ! empty( $sub['default'] ) ) {
					$o['value'] = (string) $sub['default'];
				}
				return $o;
			case 'date':
				$o = array_merge( $base, array( 'type' => 'date-picker' ) );
				if ( ! empty( $sub['default'] ) ) {
					$o['value'] = (string) $sub['default'];
				}
				return $o;

			case 'repeater':
				return $this->build_repeater_option( $base, $sub );

			case 'text':
			default:
				return $this->text_with_extras( $base, $sub );
		}
	}

	/**
	 * Build a repeater option: an inline `addable-box` whose box-options are the
	 * repeater's sub-fields. The saved value is an array of rows (each row keyed
	 * by sub-field name).
	 *
	 * @param array $base
	 * @param array $sub
	 *
	 * @return array
	 */
	private function build_repeater_option( $base, $sub ) {
		$subdefs = $this->parse_subfields( isset( $sub['subfields'] ) ? $sub['subfields'] : '' );

		$box_options = array();
		foreach ( $subdefs as $sf ) {
			if ( $sf['name'] === '' ) {
				continue;
			}
			$box_options[ $sf['name'] ] = $this->build_subfield_option( $sf );
		}

		if ( empty( $box_options ) ) {
			// No valid sub-fields defined yet: a single text column so the
			// repeater still renders instead of erroring.
			$box_options['value'] = array( 'type' => 'text', 'label' => __( 'Value', 'fw' ), 'dynamic_content' => false );
		}

		return array_merge( $base, array(
			'type'            => 'addable-box',
			'width'           => 'full',
			'add-button-text' => __( 'Add Row', 'fw' ),
			'template'        => $this->repeater_template( $subdefs ),
			'box-options'     => $box_options,
		) );
	}

	/**
	 * Parse the repeater "Sub fields" textarea (one "name | Label | type" line
	 * each) into a list of sub-field definitions.
	 *
	 * @param string $raw
	 *
	 * @return array[]
	 */
	private function parse_subfields( $raw ) {
		$out = array();

		foreach ( preg_split( '/\r\n|\r|\n/', (string) $raw ) as $line ) {
			$line = trim( $line );
			if ( $line === '' ) {
				continue;
			}

			$parts = array_map( 'trim', explode( '|', $line ) );
			$name  = $this->sanitize_field_name( $parts[0] );
			if ( $name === '' ) {
				continue;
			}

			$label = ( isset( $parts[1] ) && $parts[1] !== '' ) ? $parts[1] : ucfirst( str_replace( '_', ' ', $name ) );
			$type  = ( isset( $parts[2] ) && $parts[2] !== '' ) ? strtolower( $parts[2] ) : 'text';

			$out[] = array( 'name' => $name, 'label' => $label, 'type' => $type );
		}

		return $out;
	}

	/**
	 * Map one repeater sub-field definition to an Unyson option (a focused subset
	 * of types — no nested repeaters or choice fields).
	 *
	 * @param array $sf
	 *
	 * @return array
	 */
	private function build_subfield_option( $sf ) {
		$base = array( 'label' => $sf['label'], 'dynamic_content' => false );

		switch ( $sf['type'] ) {
			case 'textarea':
				return array_merge( $base, array( 'type' => 'textarea' ) );
			case 'wysiwyg':
				return array_merge( $base, array( 'type' => 'wp-editor' ) );
			case 'number':
				return array_merge( $base, array( 'type' => 'number' ) );
			case 'image':
				return array_merge( $base, array( 'type' => 'upload', 'images_only' => true ) );
			case 'file':
				return array_merge( $base, array( 'type' => 'upload', 'images_only' => false ) );
			case 'gallery':
				return array_merge( $base, array( 'type' => 'multi-upload' ) );
			case 'color':
				return array_merge( $base, array( 'type' => 'color-picker' ) );
			case 'date':
				return array_merge( $base, array( 'type' => 'date-picker' ) );
			case 'switch':
				return array_merge( $base, array( 'type' => 'switch' ) );
			case 'checkbox':
				return array_merge( $base, array( 'type' => 'checkbox' ) );
			case 'url':
			case 'email':
			case 'text':
			default:
				return array_merge( $base, array( 'type' => 'text' ) );
		}
	}

	/**
	 * addable-box row-title template ("{{- name }}"): the first text-like
	 * sub-field, or empty if none.
	 *
	 * @param array[] $subdefs
	 *
	 * @return string
	 */
	private function repeater_template( $subdefs ) {
		foreach ( $subdefs as $sf ) {
			if ( in_array( $sf['type'], array( 'text', 'textarea', 'number', 'url', 'email' ), true ) ) {
				return '{{- ' . $sf['name'] . ' }}';
			}
		}

		return '';
	}

	/**
	 * Build a text-like option (text / short-text / medium-text) from the common
	 * base plus optional default value and placeholder sub-options.
	 *
	 * @param array  $base
	 * @param array  $sub
	 * @param string $type The text option type to emit.
	 *
	 * @return array
	 */
	private function text_with_extras( $base, $sub, $type = 'text' ) {
		$o = array_merge( $base, array(
			'type'            => $type,
			'dynamic_content' => $this->sub_bool( $sub, 'dynamic_content', true ),
		) );

		if ( isset( $sub['default'] ) && $sub['default'] !== '' ) {
			$o['value'] = (string) $sub['default'];
		}
		if ( ! empty( $sub['placeholder'] ) ) {
			$o['attr'] = array( 'placeholder' => (string) $sub['placeholder'] );
		}

		return $o;
	}

	/**
	 * Read a boolean sub-option from a multi-picker choice value, with a default
	 * for fields saved before the option existed.
	 *
	 * @param array  $sub
	 * @param string $key
	 * @param bool   $default
	 *
	 * @return bool
	 */
	private function sub_bool( $sub, $key, $default ) {
		return array_key_exists( $key, $sub ) ? (bool) $sub[ $key ] : (bool) $default;
	}

	/**
	 * Parse a "choices" textarea string into a value => label map. Each line is
	 * "value : Label", or just "Label" (value derived from it).
	 *
	 * @param string $raw
	 *
	 * @return array
	 */
	private function parse_choices_str( $raw ) {
		$raw     = (string) $raw;
		$choices = array();

		foreach ( preg_split( '/\r\n|\r|\n/', $raw ) as $line ) {
			$line = trim( $line );
			if ( $line === '' ) {
				continue;
			}

			if ( strpos( $line, ':' ) !== false ) {
				list( $val, $label ) = explode( ':', $line, 2 );
				$val   = trim( $val );
				$label = trim( $label );
			} else {
				$label = $line;
				$val   = sanitize_title( $line );
			}

			if ( $val === '' ) {
				$val = sanitize_title( $label );
			}
			if ( $val !== '' ) {
				$choices[ $val ] = ( $label !== '' ) ? $label : $val;
			}
		}

		if ( empty( $choices ) ) {
			$choices = array( '' => __( '(no choices defined)', 'fw' ) );
		}

		return $choices;
	}

	/**
	 * Sanitise a field name into a safe meta key.
	 *
	 * @param string $name
	 *
	 * @return string
	 */
	private function sanitize_field_name( $name ) {
		// Lowercase, keep letters/numbers/underscores; collapse anything else.
		$name = strtolower( trim( (string) $name ) );
		$name = preg_replace( '/[^a-z0-9_]+/', '_', $name );

		return trim( $name, '_' );
	}

	/**
	 * Normalise the location multi-select (array, or legacy comma string) into a
	 * clean list of post type slugs.
	 *
	 * @param mixed $raw
	 *
	 * @return string[]
	 */
	private function parse_post_types( $raw ) {
		$items = is_array( $raw ) ? $raw : explode( ',', (string) $raw );

		$out = array();
		foreach ( $items as $pt ) {
			$pt = sanitize_key( trim( (string) $pt ) );
			if ( $pt !== '' ) {
				$out[] = $pt;
			}
		}

		return array_values( array_unique( $out ) );
	}

	/* ---------------------------------------------------------------------- *
	 * Options schema (the Field Groups editor)
	 * ---------------------------------------------------------------------- */

	/**
	 * Choices for the "Type" select: a curated set of Unyson option types.
	 *
	 * @return array
	 */
	private function field_type_choices() {
		return array(
			'text'         => __( 'Text', 'fw' ),
			'medium-text'  => __( 'Text (medium width)', 'fw' ),
			'short-text'   => __( 'Text (short width)', 'fw' ),
			'textarea'     => __( 'Text Area', 'fw' ),
			'wysiwyg'      => __( 'WYSIWYG Editor', 'fw' ),
			'number'       => __( 'Number', 'fw' ),
			'url'          => __( 'URL', 'fw' ),
			'email'        => __( 'Email', 'fw' ),
			'image'        => __( 'Image', 'fw' ),
			'file'         => __( 'File', 'fw' ),
			'gallery'      => __( 'Gallery (multiple images)', 'fw' ),
			'select'       => __( 'Select', 'fw' ),
			'short-select' => __( 'Select (short width)', 'fw' ),
			'radio'        => __( 'Radio', 'fw' ),
			'checkbox'   => __( 'Checkbox (on/off)', 'fw' ),
			'checkboxes' => __( 'Checkboxes (multiple)', 'fw' ),
			'switch'     => __( 'Switch (on/off)', 'fw' ),
			'color'      => __( 'Color', 'fw' ),
			'date'       => __( 'Date', 'fw' ),
			'repeater'   => __( 'Repeater (rows of sub-fields)', 'fw' ),
		);
	}

	/**
	 * Per-type extra attributes for the Field type multi-picker. Keyed by the
	 * type; only the selected type's options are shown. Types with no extras map
	 * to an empty array (just the type select, no extra fields).
	 *
	 * @return array
	 */
	private function field_type_attribute_choices() {
		$default_text = array(
			'default' => array( 'type' => 'medium-text', 'label' => __( 'Default value', 'fw' ), 'dynamic_content' => false ),
		);
		$placeholder = array(
			'placeholder' => array( 'type' => 'medium-text', 'label' => __( 'Placeholder', 'fw' ), 'dynamic_content' => false ),
		);
		$choices_opt = array(
			'choices' => array(
				'type'            => 'textarea',
				'label'           => __( 'Choices', 'fw' ),
				'desc'            => __( 'One per line, as "value : Label" (or just a label).', 'fw' ),
				'dynamic_content' => false,
			),
		);
		$choice_default = array(
			'default' => array(
				'type'            => 'medium-text',
				'label'           => __( 'Default value', 'fw' ),
				'desc'            => __( 'A choice value from the list above.', 'fw' ),
				'dynamic_content' => false,
			),
		);
		// Per-field toggle for the dynamic-content picker on the rendered field
		// (only for option types that support it). On by default.
		$dynamic = array(
			'dynamic_content' => array(
				'type'  => 'checkbox',
				'label' => __( 'Dynamic Content', 'fw' ),
				'text'  => __( 'Allow dynamic content tags in this field', 'fw' ),
				'value' => true,
			),
		);

		return array(
			'text'        => array_merge( $default_text, $placeholder, $dynamic ),
			'medium-text' => array_merge( $default_text, $placeholder, $dynamic ),
			'short-text'  => array_merge( $default_text, $placeholder, $dynamic ),
			'textarea'    => array_merge( array(
				'default' => array( 'type' => 'textarea', 'label' => __( 'Default value', 'fw' ), 'dynamic_content' => false ),
			), $dynamic ),
			'wysiwyg'    => $dynamic,
			'number'     => array(
				'default' => array( 'type' => 'number', 'label' => __( 'Default value', 'fw' ) ),
				'min'     => array( 'type' => 'number', 'label' => __( 'Min', 'fw' ) ),
				'max'     => array( 'type' => 'number', 'label' => __( 'Max', 'fw' ) ),
				'step'    => array( 'type' => 'number', 'label' => __( 'Step', 'fw' ), 'desc' => __( 'Increment, e.g. 1 or 0.1.', 'fw' ) ),
			),
			'url'        => array_merge( $default_text, $placeholder, $dynamic ),
			'email'      => array_merge( $default_text, $placeholder, $dynamic ),
			'image'      => array(),
			'file'       => array(),
			'gallery'    => array(),
			'select'       => array_merge( $choices_opt, $choice_default ),
			'short-select' => array_merge( $choices_opt, $choice_default ),
			'radio'        => array_merge( $choices_opt, $choice_default ),
			'checkboxes' => $choices_opt,
			'checkbox'   => array(
				'default' => array( 'type' => 'checkbox', 'label' => __( 'Default value', 'fw' ), 'text' => __( 'Checked by default', 'fw' ) ),
			),
			'switch'     => array(
				'default' => array( 'type' => 'switch', 'label' => __( 'Default value', 'fw' ) ),
			),
			'color'      => array(
				'default' => array( 'type' => 'color-picker', 'label' => __( 'Default value', 'fw' ) ),
			),
			'date'       => array(
				'default' => array( 'type' => 'date-picker', 'label' => __( 'Default value', 'fw' ) ),
			),
			'repeater'   => array(
				'subfields' => array(
					'type'            => 'textarea',
					'label'           => __( 'Sub fields', 'fw' ),
					'desc'            => __( 'One per line: name | Label | type. Type is one of: text, textarea, wysiwyg, number, url, email, image, file, gallery, color, date, switch, checkbox (default: text). Example: "price | Price | number".', 'fw' ),
					'dynamic_content' => false,
				),
			),
		);
	}

	/**
	 * Registered post types (minus internal ones) for the location multi-select.
	 *
	 * @return array
	 */
	private function available_post_type_choices() {
		$skip = array(
			'attachment', 'revision', 'nav_menu_item', 'custom_css',
			'customize_changeset', 'oembed_cache', 'user_request', 'wp_block',
			'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation',
			'wp_font_family', 'wp_font_face',
		);

		$choices = array();
		foreach ( get_post_types( array(), 'objects' ) as $pt ) {
			if ( in_array( $pt->name, $skip, true ) ) {
				continue;
			}
			$label = ( isset( $pt->labels->singular_name ) && $pt->labels->singular_name )
				? $pt->labels->singular_name
				: $pt->name;
			$choices[ $pt->name ] = $label . ' (' . $pt->name . ')';
		}

		return $choices;
	}

	/**
	 * The Unyson options array rendered on the admin page.
	 *
	 * @return array
	 */
	public function get_page_options() {
		return array(
			self::OPTION_ID => array(
				'type'            => 'addable-popup',
				'label'           => __( 'Field Groups', 'fw' ),
				'desc'            => __( 'Each group attaches a set of fields to one or more post types.', 'fw' ),
				'template'        => '{{= title }}',
				'popup-title'     => __( 'Field Group', 'fw' ),
				'add-button-text' => __( 'Add Field Group', 'fw' ),
				'size'            => 'large',
				'sortable'        => true,
				// Wrapped in a `group` so the modal renders without separator lines.
				'popup-options'   => array(
					'group' => array(
						'type'    => 'group',
						'options' => array(
							'title' => array(
								'type'            => 'medium-text',
								'label'           => __( 'Group title', 'fw' ),
								'desc'            => __( 'Used as the meta box heading on the edit screen, e.g. "Book Details".', 'fw' ),
								'dynamic_content' => false,
							),
							'display_title' => array(
								'type'            => 'medium-text',
								'label'           => __( 'Display title', 'fw' ),
								'desc'            => __( 'Optional. Overrides the meta box heading shown on the edit screen.', 'fw' ),
								'dynamic_content' => false,
							),
							'description' => array(
								'type'            => 'medium-text',
								'label'           => __( 'Description', 'fw' ),
								'desc'            => __( 'Optional. Shown as a note at the top of the meta box.', 'fw' ),
								'dynamic_content' => false,
							),
							'active' => array(
								'type'  => 'checkbox',
								'label' => __( 'Active', 'fw' ),
								'text'  => __( 'Show this group on the edit screen', 'fw' ),
								'value' => true,
							),
							'show_in_rest' => array(
								'type'  => 'checkbox',
								'label' => __( 'Show in REST API', 'fw' ),
								'text'  => __( 'Expose this group\'s field values under "unysonplus_fields" in the REST API', 'fw' ),
								'value' => false,
							),
							'location' => array(
								'type'        => 'multi-select',
								'label'       => __( 'Show on post types', 'fw' ),
								'desc'        => __( 'Which post types display this group of fields.', 'fw' ),
								'population'  => 'array',
								'choices'     => $this->available_post_type_choices(),
								'prepopulate' => 100,
							),
							'page_templates' => array(
								'type'        => 'multi-select',
								'label'       => __( 'Page templates', 'fw' ),
								'desc'        => __( 'Optional refinement. If set, only show when the post uses one of these templates.', 'fw' ),
								'population'  => 'array',
								'choices'     => $this->page_template_choices(),
								'prepopulate' => 100,
							),
							'post_statuses' => array(
								'type'        => 'multi-select',
								'label'       => __( 'Post statuses', 'fw' ),
								'desc'        => __( 'Optional refinement. If set, only show for these post statuses.', 'fw' ),
								'population'  => 'array',
								'choices'     => $this->post_status_choices(),
								'prepopulate' => 100,
							),
							'context' => array(
								'type'    => 'select',
								'label'   => __( 'Position', 'fw' ),
								'desc'    => __( 'Where the meta box appears on the edit screen.', 'fw' ),
								'value'   => 'normal',
								'choices' => array(
									'normal'   => __( 'Normal (after content)', 'fw' ),
									'side'     => __( 'Side', 'fw' ),
									'advanced' => __( 'Advanced (below normal)', 'fw' ),
								),
							),
							'menu_order' => array(
								'type'  => 'number',
								'label' => __( 'Order', 'fw' ),
								'desc'  => __( 'Groups with a lower number show first when several apply to the same post type.', 'fw' ),
								'value' => 0,
							),
							'hide_on_screen' => array(
								'type'    => 'checkboxes',
								'label'   => __( 'Hide on screen', 'fw' ),
								'desc'    => __( 'Remove these default panels from the edit screen on the targeted post types.', 'fw' ),
								'choices' => $this->hide_on_screen_choices(),
							),
							'fields' => array(
								'type'            => 'addable-box',
								'label'           => __( 'Fields', 'fw' ),
								'desc'            => __( 'The fields shown in this group. Drag to reorder.', 'fw' ),
								'width'           => 'full',
								'sortable'        => true,
								'add-button-text' => __( 'Add Field', 'fw' ),
								'template'        => '{{- label }}',
								// Wrapped in a `group` so each field box renders borderless.
								'box-options'     => array(
									'group' => array(
										'type'    => 'group',
										'options' => array(
											'label' => array(
												'type'            => 'medium-text',
												'label'           => __( 'Field label', 'fw' ),
												'desc'            => __( 'Shown above the field on the edit screen.', 'fw' ),
												'dynamic_content' => false,
											),
											'name' => array(
												'type'            => 'medium-text',
												'label'           => __( 'Field name', 'fw' ),
												'desc'            => __( 'The key used to save and read the value. Lowercase letters, numbers and underscores. Must be unique on the post type. Read it with fw_get_field("name").', 'fw' ),
												'dynamic_content' => false,
											),
											// Field type as a multi-picker so each type reveals
											// only its relevant attributes (choices, min/max/step …).
											'field_type' => array(
												'type'         => 'multi-picker',
												'label'        => false,
												'desc'         => false,
												'show_borders' => false,
												'picker'       => array(
													'type' => array(
														'type'    => 'select',
														'label'   => __( 'Field type', 'fw' ),
														'value'   => 'text',
														'choices' => $this->field_type_choices(),
													),
												),
												'choices'      => $this->field_type_attribute_choices(),
											),
											'description' => array(
												'type'            => 'medium-text',
												'label'           => __( 'Description', 'fw' ),
												'desc'            => __( 'Optional text shown under the field on the edit screen.', 'fw' ),
												'dynamic_content' => false,
											),
											'help' => array(
												'type'            => 'textarea',
												'label'           => __( 'Help', 'fw' ),
												'desc'            => __( 'Optional longer help, shown as a "?" tooltip next to the field.', 'fw' ),
												'dynamic_content' => false,
											),
										),
									),
								),
							),
						),
					),
				),
			),
		);
	}

	/* ---------------------------------------------------------------------- *
	 * Admin menu + page
	 * ---------------------------------------------------------------------- */

	public static function get_page_url() {
		return admin_url( 'admin.php?page=' . self::PAGE_SLUG );
	}

	/**
	 * @internal
	 */
	public function _action_admin_menu() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$this->hook_suffix = add_submenu_page(
			self::PARENT_SLUG,
			__( 'Custom Fields', 'fw' ),
			__( 'Custom Fields', 'fw' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);

		if ( $this->hook_suffix ) {
			add_action( 'load-' . $this->hook_suffix, array( $this, '_maybe_export' ) );
			add_action( 'load-' . $this->hook_suffix, array( $this, '_maybe_save' ) );
		}
	}

	/**
	 * @internal
	 */
	public function _action_enqueue( $hook ) {
		if ( $hook !== $this->hook_suffix ) {
			return;
		}

		fw()->backend->enqueue_options_static( $this->get_page_options() );
	}

	/**
	 * @internal
	 * Save handler (runs on the page's `load-` hook, before any output).
	 */
	public function _maybe_save() {
		if ( 'POST' !== ( isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : '' ) ) {
			return;
		}
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		check_admin_referer( self::NONCE );

		// Import branch (separate Tools form).
		if ( isset( $_POST['fw_cf_import'] ) ) {
			$this->handle_import();
			wp_safe_redirect( add_query_arg(
				array( 'page' => self::PAGE_SLUG, 'fw-imported' => '1' ),
				admin_url( 'admin.php' )
			) );
			exit;
		}

		$values = fw_get_options_values_from_input( $this->get_page_options() );
		$groups = ( isset( $values[ self::OPTION_ID ] ) && is_array( $values[ self::OPTION_ID ] ) )
			? $values[ self::OPTION_ID ]
			: array();

		fw_set_db_ext_settings_option( $this->get_name(), self::OPTION_ID, $groups );

		wp_safe_redirect( add_query_arg(
			array( 'page' => self::PAGE_SLUG, 'fw-saved' => '1' ),
			admin_url( 'admin.php' )
		) );
		exit;
	}

	/**
	 * @internal
	 * Stream the saved field groups as a downloadable JSON file.
	 */
	public function _maybe_export() {
		if ( empty( $_GET['fw_cf_export'] ) ) {
			return;
		}
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		check_admin_referer( 'fw_cf_export' );

		$json = wp_json_encode(
			$this->get_field_groups(),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="unysonplus-field-groups.json"' );
		echo $json; // phpcs:ignore WordPress.Security.EscapeOutput

		exit;
	}

	/**
	 * Parse the pasted JSON and store it (replacing or appending to the existing
	 * groups). Invalid JSON is ignored.
	 */
	private function handle_import() {
		$raw  = isset( $_POST['fw_cf_import_json'] ) ? wp_unslash( $_POST['fw_cf_import_json'] ) : '';
		$data = json_decode( (string) $raw, true );

		if ( ! is_array( $data ) ) {
			return;
		}

		$replace  = ! empty( $_POST['fw_cf_import_replace'] );
		$existing = $replace ? array() : $this->get_field_groups();
		$merged   = array_merge( $existing, array_values( $data ) );

		fw_set_db_ext_settings_option( $this->get_name(), self::OPTION_ID, $merged );
	}

	/**
	 * @internal
	 */
	public function render_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$options = $this->get_page_options();
		$values  = array( self::OPTION_ID => $this->get_field_groups() );
		?>
		<div class="wrap fw-ext-custom-fields">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Custom Fields', 'fw' ); ?></h1>
			<p class="description" style="max-width:50em">
				<?php esc_html_e( 'Attach custom fields to your content. Create a field group, choose which post types it shows on, then add fields. Read a value on the front end with fw_get_field( "field_name" ).', 'fw' ); ?>
			</p>

			<?php if ( isset( $_GET['fw-saved'] ) ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Field groups saved.', 'fw' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( isset( $_GET['fw-imported'] ) ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Field groups imported.', 'fw' ); ?></p>
				</div>
			<?php endif; ?>

			<form method="post" action="" style="margin-top:1.5em">
				<?php wp_nonce_field( self::NONCE ); ?>
				<div class="postbox">
					<div class="inside">
						<?php echo fw()->backend->render_options( $options, $values ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					</div>
				</div>
				<p class="submit">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Save Changes', 'fw' ); ?></button>
				</p>
			</form>

			<?php
			$export_url = wp_nonce_url(
				add_query_arg(
					array( 'page' => self::PAGE_SLUG, 'fw_cf_export' => 1 ),
					admin_url( 'admin.php' )
				),
				'fw_cf_export'
			);
			?>
			<hr style="margin:2em 0">
			<h2><?php esc_html_e( 'Tools', 'fw' ); ?></h2>
			<div class="postbox" style="max-width:50em">
				<div class="inside">
					<h3 style="margin-top:.5em"><?php esc_html_e( 'Export', 'fw' ); ?></h3>
					<p class="description"><?php esc_html_e( 'Download all field groups as a JSON file.', 'fw' ); ?></p>
					<p><a href="<?php echo esc_url( $export_url ); ?>" class="button"><?php esc_html_e( 'Export field groups (JSON)', 'fw' ); ?></a></p>

					<h3><?php esc_html_e( 'Import', 'fw' ); ?></h3>
					<p class="description"><?php esc_html_e( 'Paste an exported JSON below. By default new groups are appended; tick the box to replace everything.', 'fw' ); ?></p>
					<form method="post" action="">
						<?php wp_nonce_field( self::NONCE ); ?>
						<p>
							<textarea name="fw_cf_import_json" rows="6" class="large-text code" placeholder='[ { "title": "…", "fields": [ … ] } ]'></textarea>
						</p>
						<p>
							<label>
								<input type="checkbox" name="fw_cf_import_replace" value="1" />
								<?php esc_html_e( 'Replace existing field groups', 'fw' ); ?>
							</label>
						</p>
						<p>
							<button type="submit" name="fw_cf_import" value="1" class="button"><?php esc_html_e( 'Import', 'fw' ); ?></button>
						</p>
					</form>
				</div>
			</div>
		</div>
		<?php
	}
}
