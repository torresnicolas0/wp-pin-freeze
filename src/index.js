import { createHigherOrderComponent } from '@wordpress/compose';
import { dispatch } from '@wordpress/data';
import {
	createPortal,
	Fragment,
	useEffect,
	useState,
} from '@wordpress/element';
import { addFilter } from '@wordpress/hooks';
import { __ } from '@wordpress/i18n';
import { registerPlugin } from '@wordpress/plugins';
import BlockPinControl from './components/BlockPinControl';
import PinOverlay from './components/PinOverlay';
import PostPinPanel from './components/PostPinPanel';
import { usePostPinMeta } from './store';
import './styles/admin.scss';

const WPPF_BLOCK_ATTRIBUTES = {
	wppf_is_pinned: {
		type: 'boolean',
		default: false,
	},
	wppf_html: {
		type: 'string',
		default: '',
	},
	wppf_history: {
		type: 'array',
		default: [],
	},
};

function addPinAttributes( settings ) {
	if ( ! settings?.attributes ) {
		settings.attributes = {};
	}

	return {
		...settings,
		attributes: {
			...settings.attributes,
			...WPPF_BLOCK_ATTRIBUTES,
		},
	};
}

const withBlockPinControl = createHigherOrderComponent(
	( BlockEdit ) => ( props ) => (
		<Fragment>
			<BlockEdit { ...props } />
			<BlockPinControl { ...props } />
		</Fragment>
	),
	'withBlockPinControl'
);

const withPinOverlay = createHigherOrderComponent(
	( BlockListBlock ) => ( props ) => {
		const isPinned = Boolean( props?.attributes?.wppf_is_pinned );
		const pinnedHtml =
			'string' === typeof props?.attributes?.wppf_html
				? props.attributes.wppf_html
				: '';
		const hasPinnedHtmlPreview = Boolean( pinnedHtml.trim() );
		if ( ! isPinned ) {
			return <BlockListBlock { ...props } />;
		}

		const onRequestUnpin = () => {
			const confirmationMessage =
				window?.wppfEditorSettings?.blockUnpinConfirm ||
				__(
					'Este bloque está pineado. ¿Deseas despinearlo para editar?',
					'pin-freeze'
				);

			// eslint-disable-next-line no-alert
			if ( window.confirm( confirmationMessage ) ) {
				props.setAttributes( { wppf_is_pinned: false } );
			}
		};

		const onRequestSelect = () => {
			if ( props?.clientId ) {
				dispatch( 'core/block-editor' ).selectBlock( props.clientId );
			}
		};

		return (
			<div
				className={ `wppf-block-lock-wrapper${
					hasPinnedHtmlPreview
						? ' wppf-block-lock-wrapper--has-preview'
						: ''
				}` }
			>
				<div className="wppf-block-lock-wrapper__source">
					<BlockListBlock { ...props } />
				</div>
				{ hasPinnedHtmlPreview && (
					<div className="wppf-block-live-preview" aria-hidden="true">
						<div
							className="wppf-block-live-preview__markup"
							dangerouslySetInnerHTML={ { __html: pinnedHtml } }
						/>
					</div>
				) }
				<PinOverlay
					label={ __( 'Bloque pineado', 'pin-freeze' ) }
					actionLabel={ __( 'Despinear bloque', 'pin-freeze' ) }
					onRequestSelect={ onRequestSelect }
					onRequestUnpin={ onRequestUnpin }
				/>
			</div>
		);
	},
	'withPinOverlay'
);

function PostFreezeOverlay() {
	const { isPinned, setPinned } = usePostPinMeta();
	const [ target, setTarget ] = useState( null );

	useEffect( () => {
		setTarget( document.querySelector( '.editor-styles-wrapper' ) );
	}, [] );

	useEffect( () => {
		document.body.classList.toggle( 'wppf-post-is-pinned', isPinned );
		return () => document.body.classList.remove( 'wppf-post-is-pinned' );
	}, [ isPinned ] );

	if ( ! isPinned || ! target ) {
		return null;
	}

	const onRequestUnpin = () => {
		const confirmationMessage =
			window?.wppfEditorSettings?.postUnpinConfirm ||
			__(
				'Esta entrada está pineada. ¿Deseas despinearla para editar?',
				'pin-freeze'
			);

		// eslint-disable-next-line no-alert
		if ( window.confirm( confirmationMessage ) ) {
			setPinned( false );
		}
	};

	return createPortal(
		<PinOverlay
			className="wppf-pin-overlay--post"
			label={ __( 'Entrada/Página pineada', 'pin-freeze' ) }
			onRequestUnpin={ onRequestUnpin }
		/>,
		target
	);
}

function WPPFDocumentPlugin() {
	return (
		<Fragment>
			<PostPinPanel />
			<PostFreezeOverlay />
		</Fragment>
	);
}

addFilter(
	'blocks.registerBlockType',
	'pin-freeze/add-attributes',
	addPinAttributes
);
addFilter(
	'editor.BlockEdit',
	'pin-freeze/block-pin-control',
	withBlockPinControl
);
addFilter(
	'editor.BlockListBlock',
	'pin-freeze/block-pin-overlay',
	withPinOverlay
);

registerPlugin( 'pin-freeze-document-plugin', {
	render: WPPFDocumentPlugin,
} );
