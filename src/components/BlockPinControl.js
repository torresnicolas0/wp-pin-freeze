import apiFetch from '@wordpress/api-fetch';
import { getBlockContent, getBlockType } from '@wordpress/blocks';
import { InspectorControls } from '@wordpress/block-editor';
import * as components from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { useSelect } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import { addQueryArgs } from '@wordpress/url';

const { Button, Notice, PanelBody, TextareaControl, ToggleControl } =
	components;
const CodeEditor = components.__experimentalCodeEditor || components.CodeEditor;
const BLOCK_COMMENT_PATTERN = /^\s*<!--\s*wp:[\s\S]*-->\s*$/;
const BLOCK_HISTORY_LIMIT = 5;

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

function normalizeHistoryEntries( history ) {
	if ( ! Array.isArray( history ) ) {
		return [];
	}

	return history
		.filter(
			( entry ) =>
				entry &&
				'object' === typeof entry &&
				'string' === typeof entry.html
		)
		.map( ( entry ) => ( {
			html: normalizeCapturedHtml( entry.html ),
			capturedAt:
				'string' === typeof entry.capturedAt ? entry.capturedAt : '',
		} ) )
		.filter(
			( entry ) =>
				'' !== entry.html && ! isCommentOnlyMarkup( entry.html )
		)
		.slice( 0, BLOCK_HISTORY_LIMIT );
}

function buildHistoryEntry( html ) {
	return {
		html,
		capturedAt: new Date().toISOString(),
	};
}

function pushHistoryEntry( previousHtml, history ) {
	const normalizedPrevious = normalizeCapturedHtml( previousHtml );
	const normalizedHistory = normalizeHistoryEntries( history );

	if (
		'' === normalizedPrevious ||
		isCommentOnlyMarkup( normalizedPrevious )
	) {
		return normalizedHistory;
	}

	const deduplicated = normalizedHistory.filter(
		( entry ) => entry.html !== normalizedPrevious
	);

	return [ buildHistoryEntry( normalizedPrevious ), ...deduplicated ].slice(
		0,
		BLOCK_HISTORY_LIMIT
	);
}

function formatHistoryDate( isoDate ) {
	if ( ! isoDate || 'string' !== typeof isoDate ) {
		return __( 'Sin fecha', 'pin-freeze' );
	}

	const dateObject = new Date( isoDate );
	if ( Number.isNaN( dateObject.getTime() ) ) {
		return isoDate;
	}

	return dateObject.toLocaleString();
}

function createHistoryExcerpt( html ) {
	const compact = normalizeCapturedHtml( html ).replace( /\s+/g, ' ' );
	if ( ! compact ) {
		return '';
	}

	if ( compact.length <= 96 ) {
		return compact;
	}

	return `${ compact.slice( 0, 93 ) }...`;
}

function BlockPinControl( {
	attributes,
	clientId,
	isSelected,
	setAttributes,
} ) {
	const {
		wppf_html: pinnedHtml = '',
		wppf_is_pinned: isPinned = false,
		wppf_history: blockHistory = [],
	} = attributes || {};
	const [ isCapturing, setIsCapturing ] = useState( false );
	const [ captureError, setCaptureError ] = useState( '' );
	const [ draftHtml, setDraftHtml ] = useState( pinnedHtml || '' );
	const [ applyNotice, setApplyNotice ] = useState( '' );

	const { block, postId } = useSelect(
		( select ) => ( {
			block: select( 'core/block-editor' ).getBlock( clientId ),
			postId: select( 'core/editor' ).getCurrentPostId(),
		} ),
		[ clientId ]
	);

	useEffect( () => {
		setDraftHtml( pinnedHtml || '' );
	}, [ pinnedHtml ] );

	if ( ! isSelected ) {
		return null;
	}

	const normalizedHistory = normalizeHistoryEntries( blockHistory );

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
			setApplyNotice( '' );
			setAttributes( { wppf_is_pinned: false } );
			return;
		}

		if ( pinnedHtml && ! isCommentOnlyMarkup( pinnedHtml ) ) {
			setCaptureError( '' );
			setApplyNotice( '' );
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
					wppf_history: pushHistoryEntry(
						pinnedHtml,
						normalizedHistory
					),
				} );
				setApplyNotice(
					__(
						'HTML capturado y aplicado. Revisa el bloque pineado en el lienzo antes de guardar.',
						'pin-freeze'
					)
				);
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
		setApplyNotice( '' );
		setDraftHtml( nextHtml || '' );
	};

	const onApplyHtml = () => {
		const nextHtml = draftHtml || '';
		if ( nextHtml === ( pinnedHtml || '' ) ) {
			return;
		}

		setCaptureError( '' );
		setAttributes( {
			wppf_html: nextHtml,
			wppf_history: pushHistoryEntry( pinnedHtml, normalizedHistory ),
		} );
		setApplyNotice(
			__(
				'Cambios aplicados. Revisa el bloque pineado actualizado en el lienzo.',
				'pin-freeze'
			)
		);
	};

	const onRestoreHistory = ( historyIndex ) => {
		const selectedEntry = normalizedHistory[ historyIndex ];
		if ( ! selectedEntry ) {
			return;
		}

		if ( selectedEntry.html === ( pinnedHtml || '' ) ) {
			setDraftHtml( selectedEntry.html );
			setApplyNotice(
				__( 'Esta versión ya está aplicada.', 'pin-freeze' )
			);
			return;
		}

		const updatedHistory = pushHistoryEntry(
			pinnedHtml,
			normalizedHistory.filter(
				( _entry, index ) => index !== historyIndex
			)
		);

		setCaptureError( '' );
		setAttributes( {
			wppf_html: selectedEntry.html,
			wppf_history: updatedHistory,
		} );
		setDraftHtml( selectedEntry.html );
		setApplyNotice(
			__( 'Versión del historial restaurada y aplicada.', 'pin-freeze' )
		);
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

	const hasDraftChanges = draftHtml !== ( pinnedHtml || '' );

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
								value={ draftHtml }
								onChange={ onChangeHtml }
								language="html"
								className="wppf-code-editor"
							/>
						) : (
							<TextareaControl
								label={ __( 'Frozen HTML', 'pin-freeze' ) }
								value={ draftHtml }
								onChange={ onChangeHtml }
								rows={ 12 }
								className="wppf-code-editor-fallback"
							/>
						) }

						<div className="wppf-html-actions">
							<Button
								variant="primary"
								onClick={ onApplyHtml }
								disabled={ ! hasDraftChanges || isCapturing }
							>
								{ __( 'Aplicar cambios', 'pin-freeze' ) }
							</Button>
						</div>

						{ !! applyNotice && (
							<Notice
								status="success"
								isDismissible={ false }
								className="wppf-block-capture-notice"
							>
								<p>{ applyNotice }</p>
							</Notice>
						) }

						<div className="wppf-block-history">
							<p className="wppf-inspector-help">
								{ __(
									'Historial del bloque (últimas 5 versiones aplicadas):',
									'pin-freeze'
								) }
							</p>
							{ normalizedHistory.length > 0 ? (
								<ul className="wppf-block-history-list">
									{ normalizedHistory.map(
										( entry, index ) => (
											<li
												key={ `${
													entry.capturedAt || 'entry'
												}-${ index }` }
												className="wppf-block-history-item"
											>
												<div className="wppf-block-history-meta">
													<span className="wppf-block-history-date">
														{ formatHistoryDate(
															entry.capturedAt
														) }
													</span>
													<code className="wppf-block-history-excerpt">
														{ createHistoryExcerpt(
															entry.html
														) ||
															__(
																'(Sin contenido)',
																'pin-freeze'
															) }
													</code>
												</div>
												<Button
													variant="secondary"
													size="small"
													onClick={ () =>
														onRestoreHistory(
															index
														)
													}
													disabled={ isCapturing }
												>
													{ __(
														'Restaurar',
														'pin-freeze'
													) }
												</Button>
											</li>
										)
									) }
								</ul>
							) : (
								<p className="wppf-block-history-empty">
									{ __(
										'Aún no hay historial para este bloque.',
										'pin-freeze'
									) }
								</p>
							) }
						</div>
					</>
				) }
			</PanelBody>
		</InspectorControls>
	);
}

export default BlockPinControl;
