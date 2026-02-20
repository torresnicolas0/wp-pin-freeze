import apiFetch from '@wordpress/api-fetch';
import { getBlockContent, getBlockType } from '@wordpress/blocks';
import { InspectorControls } from '@wordpress/block-editor';
import * as components from '@wordpress/components';
import { useState } from '@wordpress/element';
import { useSelect } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import { addQueryArgs } from '@wordpress/url';

const { Notice, PanelBody, TextareaControl, ToggleControl } = components;
const CodeEditor = components.__experimentalCodeEditor || components.CodeEditor;
const BLOCK_COMMENT_PATTERN = /^\s*<!--\s*wp:[\s\S]*-->\s*$/;

function isCommentOnlyMarkup( html ) {
	if ( ! html || 'string' !== typeof html ) {
		return true;
	}

	return BLOCK_COMMENT_PATTERN.test( html );
}

function normalizeCapturedHtml( html ) {
	if ( 'string' !== typeof html ) {
		return '';
	}

	return html.trim();
}

function BlockPinControl( {
	attributes,
	clientId,
	isSelected,
	setAttributes,
} ) {
	const { wppf_html: pinnedHtml = '', wppf_is_pinned: isPinned = false } =
		attributes || {};
	const [ isCapturing, setIsCapturing ] = useState( false );
	const [ captureError, setCaptureError ] = useState( '' );

	const { block, postId } = useSelect(
		( select ) => ( {
			block: select( 'core/block-editor' ).getBlock( clientId ),
			postId: select( 'core/editor' ).getCurrentPostId(),
		} ),
		[ clientId ]
	);

	if ( ! isSelected ) {
		return null;
	}

	const captureRenderedBlockHtml = async () => {
		if ( ! block ) {
			return '';
		}

		const blockType = getBlockType( block.name );
		const staticCandidate = normalizeCapturedHtml(
			getBlockContent( block )
		);
		const shouldTryServerRender =
			! blockType ||
			'function' !== typeof blockType.save ||
			isCommentOnlyMarkup( staticCandidate );

		if ( shouldTryServerRender ) {
			try {
				const queryArgs = {
					context: 'edit',
				};
				if ( postId ) {
					queryArgs.post_id = Number( postId );
				}

				const path = addQueryArgs(
					`/wp/v2/block-renderer/${ encodeURIComponent(
						block.name
					) }`,
					queryArgs
				);

				const response = await apiFetch( {
					path,
					method: 'POST',
					data: {
						attributes: block.attributes || {},
					},
				} );

				const serverRendered = normalizeCapturedHtml(
					response?.rendered || ''
				);
				if (
					serverRendered &&
					! isCommentOnlyMarkup( serverRendered )
				) {
					return serverRendered;
				}
			} catch {
				// Fallback below intentionally handles transient REST failures.
			}
		}

		if ( staticCandidate && ! isCommentOnlyMarkup( staticCandidate ) ) {
			return staticCandidate;
		}

		return '';
	};

	const onTogglePin = async ( nextValue ) => {
		if ( ! nextValue ) {
			setCaptureError( '' );
			setAttributes( { wppf_is_pinned: false } );
			return;
		}

		if ( pinnedHtml && ! isCommentOnlyMarkup( pinnedHtml ) ) {
			setCaptureError( '' );
			setAttributes( { wppf_is_pinned: true } );
			return;
		}

		setIsCapturing( true );
		setCaptureError( '' );

		try {
			const capturedHtml = await captureRenderedBlockHtml();
			if ( capturedHtml ) {
				setAttributes( {
					wppf_is_pinned: true,
					wppf_html: capturedHtml,
				} );
				return;
			}

			setAttributes( { wppf_is_pinned: false } );
			setCaptureError(
				__(
					'No se pudo capturar HTML renderizado para este bloque. Intenta refrescar o guardar y reintentar.',
					'pin-freeze'
				)
			);
		} finally {
			setIsCapturing( false );
		}
	};

	const onChangeHtml = ( nextHtml ) => {
		setCaptureError( '' );
		setAttributes( { wppf_html: nextHtml || '' } );
	};

	let toggleHelp = __(
		'Congela la salida del bloque para reemplazar su render dinámico.',
		'pin-freeze'
	);

	if ( isPinned ) {
		toggleHelp = __(
			'Este bloque está pineado y usa HTML estático.',
			'pin-freeze'
		);
	}

	if ( isCapturing ) {
		toggleHelp = __(
			'Capturando HTML renderizado del bloque…',
			'pin-freeze'
		);
	}

	return (
		<InspectorControls>
			<PanelBody title={ __( 'Pin & Freeze', 'pin-freeze' ) } initialOpen>
				<ToggleControl
					label={ __( 'Pin HTML', 'pin-freeze' ) }
					help={ toggleHelp }
					checked={ isPinned }
					onChange={ onTogglePin }
					disabled={ isCapturing }
				/>

				{ !! captureError && (
					<Notice
						status="warning"
						isDismissible={ false }
						className="wppf-block-capture-notice"
					>
						<p>{ captureError }</p>
					</Notice>
				) }

				{ isPinned && (
					<>
						<p className="wppf-inspector-help">
							{ __(
								'HTML estático del bloque pineado:',
								'pin-freeze'
							) }
						</p>
						{ CodeEditor ? (
							<CodeEditor
								value={ pinnedHtml }
								onChange={ onChangeHtml }
								language="html"
								className="wppf-code-editor"
							/>
						) : (
							<TextareaControl
								label={ __( 'Frozen HTML', 'pin-freeze' ) }
								value={ pinnedHtml }
								onChange={ onChangeHtml }
								rows={ 12 }
								className="wppf-code-editor-fallback"
							/>
						) }
					</>
				) }
			</PanelBody>
		</InspectorControls>
	);
}

export default BlockPinControl;
