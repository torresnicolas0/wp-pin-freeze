import * as components from '@wordpress/components';
import { select as dataSelect, useSelect } from '@wordpress/data';
import { dateI18n } from '@wordpress/date';
import { PluginDocumentSettingPanel } from '@wordpress/edit-post';
import { useMemo, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { usePostPinMeta } from '../store';

const {
	Button,
	Notice,
	SelectControl,
	Spinner,
	TabPanel,
	TextareaControl,
	ToggleControl,
} = components;

const CodeEditor = components.__experimentalCodeEditor || components.CodeEditor;

function getEditorSettings() {
	return window?.wppfEditorSettings || {};
}

function getAjaxUrl() {
	return getEditorSettings().ajaxUrl || window?.ajaxurl || '';
}

async function requestWppfAjax( action, payload ) {
	const ajaxUrl = getAjaxUrl();
	if ( ! ajaxUrl ) {
		throw new Error(
			__(
				'No se encontró la URL de AJAX para Pin & Freeze.',
				'pin-freeze'
			)
		);
	}

	const formData = new window.FormData();
	formData.append( 'action', action );
	formData.append( 'nonce', getEditorSettings().nonce || '' );

	Object.entries( payload || {} ).forEach( ( [ key, value ] ) => {
		const normalizedValue =
			null === value || undefined === value ? '' : String( value );
		formData.append( key, normalizedValue );
	} );

	const response = await window.fetch( ajaxUrl, {
		method: 'POST',
		credentials: 'same-origin',
		body: formData,
	} );

	if ( ! response.ok ) {
		throw new Error(
			sprintf(
				/* translators: %d: HTTP status code */
				__(
					'Error de red al procesar la petición (%d).',
					'pin-freeze'
				),
				response.status
			)
		);
	}

	let json = null;
	try {
		json = await response.json();
	} catch {
		throw new Error(
			__(
				'La respuesta del servidor no es válida. Verifica si el sitio está en mantenimiento o inaccesible.',
				'pin-freeze'
			)
		);
	}
	if ( ! json?.success ) {
		throw new Error(
			json?.data?.message ||
				__(
					'No se pudo completar la operación de pinning.',
					'pin-freeze'
				)
		);
	}

	return json.data || {};
}

function formatSnapshotDate( dateValue ) {
	if ( ! dateValue ) {
		return __( 'Sin fecha', 'pin-freeze' );
	}

	return dateI18n( 'Y-m-d H:i', dateValue );
}

function getSnapshotAuthorName( snapshot ) {
	const embeddedAuthor = snapshot?._embedded?.author?.[ 0 ]?.name;
	if ( embeddedAuthor ) {
		return embeddedAuthor;
	}

	return __( 'Autor desconocido', 'pin-freeze' );
}

function getSnapshotHtml( snapshot ) {
	if ( snapshot?.content?.raw ) {
		return snapshot.content.raw;
	}

	if ( snapshot?.content?.rendered ) {
		return snapshot.content.rendered;
	}

	return '';
}

function PostPinPanel() {
	const { html, isPinned, setHtml, setPinned } = usePostPinMeta();
	const [ captureMode, setCaptureMode ] = useState( 'editor' );
	const [ isFetchingLive, setIsFetchingLive ] = useState( false );
	const [ isSavingPin, setIsSavingPin ] = useState( false );
	const [ isRestoringSnapshot, setIsRestoringSnapshot ] = useState( false );
	const [ successMessage, setSuccessMessage ] = useState( '' );
	const [ errorMessage, setErrorMessage ] = useState( '' );

	const postId = useSelect(
		( select ) => select( 'core/editor' ).getCurrentPostId(),
		[]
	);

	const snapshotPostType =
		getEditorSettings().snapshotPostType || 'wppf_snapshot';
	const snapshotQuery = useMemo(
		() => ( {
			per_page: 20,
			parent: postId,
			order: 'desc',
			orderby: 'date',
			context: 'edit',
			_embed: true,
		} ),
		[ postId ]
	);

	const snapshots = useSelect(
		( select ) => {
			if ( ! postId ) {
				return [];
			}

			const records =
				select( 'core' ).getEntityRecords(
					'postType',
					snapshotPostType,
					snapshotQuery
				) || [];

			return records
				.filter(
					( snapshot ) =>
						Number( snapshot?.parent ) === Number( postId )
				)
				.slice( 0, 5 );
		},
		[ postId, snapshotPostType, snapshotQuery ]
	);

	const isResolvingSnapshots = useSelect(
		( select ) => {
			if ( ! postId ) {
				return false;
			}

			return select( 'core/data' ).isResolving(
				'core',
				'getEntityRecords',
				[ 'postType', snapshotPostType, snapshotQuery ]
			);
		},
		[ postId, snapshotPostType, snapshotQuery ]
	);

	const canUnfilteredHtml = Boolean( getEditorSettings().canUnfilteredHtml );
	const captureSelector = getEditorSettings().captureSelector || '#content';

	const clearNotices = () => {
		setErrorMessage( '' );
		setSuccessMessage( '' );
	};

	const onFetchLiveFrontend = async () => {
		clearNotices();

		if ( ! postId ) {
			setErrorMessage(
				__(
					'No se encontró el ID del contenido actual.',
					'pin-freeze'
				)
			);
			return;
		}

		setIsFetchingLive( true );

		try {
			const data = await requestWppfAjax( 'wppf_fetch_frontend', {
				post_id: postId,
			} );

			setHtml( data.html || '' );
			setSuccessMessage(
				__(
					'Contenido capturado desde frontend. Revísalo y guarda el pin.',
					'pin-freeze'
				)
			);
		} catch ( error ) {
			setErrorMessage(
				error?.message ||
					__( 'Error al capturar frontend.', 'pin-freeze' )
			);
		} finally {
			setIsFetchingLive( false );
		}
	};

	const onSavePin = async () => {
		clearNotices();

		if ( ! postId ) {
			setErrorMessage(
				__(
					'No se encontró el ID del contenido actual.',
					'pin-freeze'
				)
			);
			return;
		}

		setIsSavingPin( true );

		try {
			let htmlToSave = html || '';

			if ( 'editor' === captureMode ) {
				htmlToSave =
					dataSelect( 'core/editor' ).getEditedPostContent() || '';
				setHtml( htmlToSave );
			}

			if ( ! htmlToSave.trim() ) {
				throw new Error(
					__( 'No hay HTML para pinear.', 'pin-freeze' )
				);
			}

			const data = await requestWppfAjax( 'wppf_save_post_pin', {
				post_id: postId,
				html: htmlToSave,
				is_pinned: 1,
			} );

			if ( data?.html ) {
				setHtml( data.html );
			}

			setPinned( true );
			setSuccessMessage(
				data?.message ||
					__( 'Pin guardado correctamente.', 'pin-freeze' )
			);
		} catch ( error ) {
			setErrorMessage(
				error?.message ||
					__( 'Error guardando el pin.', 'pin-freeze' )
			);
		} finally {
			setIsSavingPin( false );
		}
	};

	const onRestoreSnapshot = async ( snapshot ) => {
		clearNotices();

		if ( ! postId ) {
			setErrorMessage(
				__(
					'No se encontró el ID del contenido actual.',
					'pin-freeze'
				)
			);
			return;
		}

		const restoredHtml = getSnapshotHtml( snapshot );
		if ( ! restoredHtml ) {
			setErrorMessage(
				__(
					'El snapshot seleccionado no contiene HTML utilizable.',
					'pin-freeze'
				)
			);
			return;
		}

		setIsRestoringSnapshot( true );

		try {
			const data = await requestWppfAjax( 'wppf_save_post_pin', {
				post_id: postId,
				html: restoredHtml,
				is_pinned: 1,
				skip_snapshot: 1,
			} );

			setHtml( data?.html || restoredHtml );
			setPinned( true );
			setSuccessMessage(
				__(
					'Snapshot restaurado y meta actualizada correctamente.',
					'pin-freeze'
				)
			);
		} catch ( error ) {
			setErrorMessage(
				error?.message ||
					__( 'No se pudo restaurar el snapshot.', 'pin-freeze' )
			);
		} finally {
			setIsRestoringSnapshot( false );
		}
	};

	return (
		<PluginDocumentSettingPanel
			name="wppf-post-pinning"
			title={ __( 'Post Pinning', 'pin-freeze' ) }
			className="wppf-post-panel"
		>
			<ToggleControl
				label={ __( 'Pin complete post HTML', 'pin-freeze' ) }
				help={
					isPinned
						? __(
								'El frontend mostrará el HTML estático guardado en meta.',
								'pin-freeze'
						  )
						: __(
								'Permite reemplazar todo el contenido de frontend por HTML estático.',
								'pin-freeze'
						  )
				}
				checked={ isPinned }
				onChange={ setPinned }
			/>

			{ isPinned && (
				<Notice status="warning" isDismissible={ false }>
					{ __(
						'La edición visual queda bloqueada mientras esta entrada/página esté pineada.',
						'pin-freeze'
					) }
				</Notice>
			) }

			{ ! canUnfilteredHtml && (
				<Notice status="info" isDismissible={ false }>
					{ __(
						'Tu rol no tiene unfiltered_html. El HTML se sanitizará automáticamente al guardar y renderizar.',
						'pin-freeze'
					) }
				</Notice>
			) }

			{ errorMessage && (
				<Notice
					status="error"
					isDismissible={ true }
					onRemove={ () => setErrorMessage( '' ) }
				>
					{ errorMessage }
				</Notice>
			) }

			{ successMessage && (
				<Notice
					status="success"
					isDismissible={ true }
					onRemove={ () => setSuccessMessage( '' ) }
				>
					{ successMessage }
				</Notice>
			) }

			<TabPanel
				className="wppf-post-tabs"
				tabs={ [
					{
						name: 'capture',
						title: __( 'Capture', 'pin-freeze' ),
					},
					{
						name: 'history',
						title: __( 'Pin History', 'pin-freeze' ),
					},
				] }
			>
				{ ( tab ) => {
					if ( 'history' === tab.name ) {
						if ( isResolvingSnapshots ) {
							return (
								<div className="wppf-history-loading">
									<Spinner />
								</div>
							);
						}

						if ( ! snapshots.length ) {
							return (
								<p className="wppf-history-empty">
									{ __(
										'No hay snapshots todavía para esta entrada.',
										'pin-freeze'
									) }
								</p>
							);
						}

						return (
							<ul className="wppf-history-list">
								{ snapshots.map( ( snapshot ) => (
									<li
										key={ snapshot.id }
										className="wppf-history-item"
									>
										<div className="wppf-history-item__meta">
											<strong>
												{ formatSnapshotDate(
													snapshot.date
												) }
											</strong>
											<span>
												{ getSnapshotAuthorName(
													snapshot
												) }
											</span>
										</div>
										<Button
											variant="secondary"
											onClick={ () =>
												onRestoreSnapshot( snapshot )
											}
											disabled={
												isRestoringSnapshot ||
												isSavingPin ||
												isFetchingLive
											}
										>
											{ __(
												'Restaurar',
												'pin-freeze'
											) }
										</Button>
									</li>
								) ) }
							</ul>
						);
					}

					return (
						<>
							<SelectControl
								label={ __(
									'Modo de Captura',
									'pin-freeze'
								) }
								value={ captureMode }
								onChange={ setCaptureMode }
								options={ [
									{
										label: __(
											'Editor State',
											'pin-freeze'
										),
										value: 'editor',
									},
									{
										label: __(
											'Live Frontend',
											'pin-freeze'
										),
										value: 'live',
									},
								] }
							/>

							{ 'live' === captureMode && (
								<p className="wppf-capture-selector-hint">
									{ sprintf(
										/* translators: %s: CSS selector */
										__(
											'Selector configurado: %s',
											'pin-freeze'
										),
										captureSelector
									) }
								</p>
							) }

							<div className="wppf-post-actions">
								{ 'live' === captureMode && (
									<Button
										variant="secondary"
										onClick={ onFetchLiveFrontend }
										disabled={
											isFetchingLive || isSavingPin
										}
									>
										{ isFetchingLive
											? __(
													'Capturando…',
													'pin-freeze'
											  )
											: __(
													'Fetch & Pin URL',
													'pin-freeze'
											  ) }
									</Button>
								) }

								<Button
									variant="primary"
									onClick={ onSavePin }
									disabled={ isSavingPin || isFetchingLive }
								>
									{ isSavingPin
										? __( 'Guardando…', 'pin-freeze' )
										: __(
												'Pin HTML (Guardar)',
												'pin-freeze'
										  ) }
								</Button>
							</div>

							{ CodeEditor ? (
								<CodeEditor
									value={ html }
									onChange={ setHtml }
									language="html"
									className="wppf-post-code-editor"
								/>
							) : (
								<TextareaControl
									label={ __(
										'Post Frozen HTML',
										'pin-freeze'
									) }
									value={ html }
									onChange={ setHtml }
									rows={ 18 }
									className="wppf-post-code-editor-fallback"
								/>
							) }
						</>
					);
				} }
			</TabPanel>
		</PluginDocumentSettingPanel>
	);
}

export default PostPinPanel;
