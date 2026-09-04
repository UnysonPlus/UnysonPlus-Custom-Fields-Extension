/**
 * Unyson+ Custom Fields — Block Bindings picker.
 *
 * Adds a "Unyson+ Field Binding" panel to core blocks so an editor can bind a block
 * attribute to a Custom Fields value without hand-editing block markup. Writes the
 * standard WordPress `metadata.bindings` shape pointing at the `unysonplus/field`
 * source; the PHP resolver fills the value at render time.
 *
 * Plain wp.element (no JSX / no build step) so it enqueues as-is.
 */
( function ( wp ) {
	if ( ! wp || ! wp.hooks || ! wp.element || ! wp.blockEditor || ! wp.components || ! wp.compose ) {
		return;
	}

	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var PanelBody = wp.components.PanelBody;
	var SelectControl = wp.components.SelectControl;
	var createHigherOrderComponent = wp.compose.createHigherOrderComponent;
	var __ = ( wp.i18n && wp.i18n.__ ) ? wp.i18n.__ : function ( s ) { return s; };

	var DATA = window.upwcBindings || { fields: [] };
	var SOURCE = 'unysonplus/field';

	// The bindable attributes each supported core block exposes (one sidebar row per attribute).
	var ATTR = {
		'core/paragraph': [ 'content' ],
		'core/heading': [ 'content' ],
		'core/button': [ 'text', 'url' ],
		'core/image': [ 'url', 'alt' ]
	};

	// A friendlier label than the raw attribute slug.
	var ATTR_LABEL = { content: 'text', text: 'label', url: 'link', alt: 'alt text' };

	function currentPostType() {
		try {
			return wp.data.select( 'core/editor' ).getCurrentPostType() || '';
		} catch ( e ) {
			return '';
		}
	}

	// Fields whose group targets the current post type (or that target no specific type).
	function fieldsForType( pt ) {
		return ( DATA.fields || [] ).filter( function ( f ) {
			return ! f.types || ! f.types.length || f.types.indexOf( pt ) !== -1;
		} );
	}

	var withBinding = createHigherOrderComponent( function ( BlockEdit ) {
		return function ( props ) {
			var attrs = ATTR[ props.name ];
			if ( ! attrs ) {
				return el( BlockEdit, props );
			}
			var fields = fieldsForType( currentPostType() );
			if ( ! fields.length ) {
				return el( BlockEdit, props );
			}

			var bindings = ( props.attributes.metadata && props.attributes.metadata.bindings ) || {};
			var options = [ { label: __( '— None —', 'fw' ), value: '' } ].concat(
				fields.map( function ( f ) {
					return { label: f.label || f.key, value: f.key };
				} )
			);

			function setBinding( attr, key ) {
				var meta = Object.assign( {}, props.attributes.metadata );
				var b = Object.assign( {}, meta.bindings );
				if ( key ) {
					b[ attr ] = { source: SOURCE, args: { key: key } };
				} else {
					delete b[ attr ];
				}
				if ( Object.keys( b ).length ) {
					meta.bindings = b;
				} else {
					delete meta.bindings;
				}
				props.setAttributes( { metadata: Object.keys( meta ).length ? meta : undefined } );
			}

			var rows = attrs.map( function ( attr ) {
				var bound = bindings[ attr ];
				var current = ( bound && bound.source === SOURCE && bound.args ) ? bound.args.key : '';
				return el( SelectControl, {
					key: attr,
					label: __( 'Bind ' + ( ATTR_LABEL[ attr ] || attr ) + ' to field', 'fw' ),
					value: current,
					options: options,
					onChange: function ( key ) { setBinding( attr, key ); }
				} );
			} );

			rows.push(
				el( 'p', { key: '__help', style: { fontSize: '12px', color: '#757575', margin: '4px 0 0' } },
					__( 'Fills the block from a Custom Fields value on the post it appears on.', 'fw' ) )
			);

			return el(
				Fragment,
				{},
				el( BlockEdit, props ),
				el(
					InspectorControls,
					{},
					el(
						PanelBody,
						{ title: __( 'Unyson+ Field Binding', 'fw' ), initialOpen: false },
						rows
					)
				)
			);
		};
	}, 'withUnysonFieldBinding' );

	wp.hooks.addFilter( 'editor.BlockEdit', 'unysonplus/field-binding', withBinding );
} )( window.wp );
