/**
 * Block editor registration for Wedding Party RSVP dynamic blocks.
 *
 * Full metadata is declared here so blocks appear in the inserter even when
 * server-side bootstrap order differs between WordPress versions.
 */
( function ( wp ) {
	var blocks = wp.blocks;
	var element = wp.element;
	var blockEditor = wp.blockEditor;
	var serverSideRender = wp.serverSideRender;
	var i18n = wp.i18n;

	if ( ! blocks || ! element || ! blockEditor || ! serverSideRender || ! i18n ) {
		return;
	}

	var __ = i18n.__;
	var el = element.createElement;
	var useBlockProps = blockEditor.useBlockProps;
	var ServerSideRender = serverSideRender;

	var blockDefinitions = [
		{
			name: 'wedding-party-rsvp/rsvp-form',
			title: __( 'Wedding RSVP Form', 'wedding-party-rsvp' ),
			category: 'wgrsvp',
			icon: 'email',
			description: __(
				'Shows the full Wedding Party RSVP flow (same as the [wedding_rsvp_form] shortcode).',
				'wedding-party-rsvp'
			),
			attributes: {},
		},
		{
			name: 'wedding-party-rsvp/guest-hub',
			title: __( 'Guest Hub', 'wedding-party-rsvp' ),
			category: 'wgrsvp',
			icon: 'id',
			description: __(
				'Personalized RSVP summary for guests after they submit (same as [wgrsvp_guest_hub]).',
				'wedding-party-rsvp'
			),
			attributes: {},
		},
		{
			name: 'wedding-party-rsvp/thankyou-checklist',
			title: __( 'Thank-you checklist', 'wedding-party-rsvp' ),
			category: 'wgrsvp',
			icon: 'yes-alt',
			description: __(
				'Post-event thank-you checklist (same as [wgrsvp_thankyou_tracker]).',
				'wedding-party-rsvp'
			),
			attributes: {
				public: {
					type: 'boolean',
					default: false,
				},
			},
		},
	];

	/**
	 * @param {string} blockName Registered block name.
	 * @return {Function} Edit component.
	 */
	function createEdit( blockName ) {
		return function Edit( props ) {
			var blockProps = useBlockProps( {
				className: 'wgrsvp-block-editor-preview',
			} );

			return el(
				'div',
				blockProps,
				el( ServerSideRender, {
					block: blockName,
					attributes: props.attributes,
				} )
			);
		};
	}

	blockDefinitions.forEach( function ( definition ) {
		var blockName = definition.name;
		var settings = {
			apiVersion: 3,
			title: definition.title,
			category: definition.category,
			icon: definition.icon,
			description: definition.description,
			attributes: definition.attributes,
			supports: {
				html: false,
			},
			edit: createEdit( blockName ),
			save: function () {
				return null;
			},
		};

		var existing = blocks.getBlockType( blockName );
		if ( existing ) {
			blocks.unregisterBlockType( blockName );
		}

		blocks.registerBlockType( blockName, settings );
	} );
} )( window.wp );
