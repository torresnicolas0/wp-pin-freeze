import { serialize } from '@wordpress/blocks';
import { InspectorControls } from '@wordpress/block-editor';
import * as components from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { __ } from '@wordpress/i18n';

const { PanelBody, TextareaControl, ToggleControl } = components;
const CodeEditor = components.__experimentalCodeEditor || components.CodeEditor;

function BlockPinControl( {
	attributes,
	clientId,
	isSelected,
	setAttributes,
} ) {
	const { wppf_html: pinnedHtml = '', wppf_is_pinned: isPinned = false } =
		attributes || {};

	const block = useSelect(
		( select ) => select( 'core/block-editor' ).getBlock( clientId ),
		[ clientId ]
	);

	if ( ! isSelected ) {
		return null;
	}

	const pinCurrentBlockState = () => {
		if ( ! block ) {
			return '';
		}

		return serialize( [ block ] );
	};

	const onTogglePin = ( nextValue ) => {
		if ( nextValue && ! pinnedHtml ) {
			setAttributes( {
				wppf_is_pinned: true,
				wppf_html: pinCurrentBlockState(),
			} );
			return;
		}

		setAttributes( { wppf_is_pinned: Boolean( nextValue ) } );
	};

	const onChangeHtml = ( nextHtml ) => {
		setAttributes( { wppf_html: nextHtml || '' } );
	};

	return (
		<InspectorControls>
			<PanelBody
				title={ __( 'WP Pin & Freeze', 'wp-pin-freeze' ) }
				initialOpen
			>
				<ToggleControl
					label={ __( 'Pin HTML', 'wp-pin-freeze' ) }
					help={
						isPinned
							? __(
									'Este bloque está pineado y usa HTML estático.',
									'wp-pin-freeze'
							  )
							: __(
									'Congela la salida del bloque para reemplazar su render dinámico.',
									'wp-pin-freeze'
							  )
					}
					checked={ isPinned }
					onChange={ onTogglePin }
				/>

				{ isPinned && (
					<>
						<p className="wppf-inspector-help">
							{ __(
								'HTML estático del bloque pineado:',
								'wp-pin-freeze'
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
								label={ __( 'Frozen HTML', 'wp-pin-freeze' ) }
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
