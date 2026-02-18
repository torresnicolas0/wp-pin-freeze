import { __ } from '@wordpress/i18n';

function PinOverlay( { className = '', label = '', onRequestUnpin } ) {
	const onClick = ( event ) => {
		event.preventDefault();
		event.stopPropagation();

		if ( onRequestUnpin ) {
			onRequestUnpin();
		}
	};

	const onKeyDown = ( event ) => {
		if ( 'Enter' !== event.key && ' ' !== event.key ) {
			return;
		}

		event.preventDefault();
		onClick( event );
	};

	return (
		<div
			className={ `wppf-pin-overlay ${ className }`.trim() }
			role="button"
			tabIndex={ 0 }
			onClick={ onClick }
			onKeyDown={ onKeyDown }
		>
			<span className="dashicons dashicons-lock" aria-hidden="true" />
			<span className="wppf-pin-overlay__title">
				{ label ||
					__( 'Este contenido está pineado.', 'pin-freeze' ) }
			</span>
			<span className="wppf-pin-overlay__subtitle">
				{ __( 'Haz clic para despinearlo.', 'pin-freeze' ) }
			</span>
		</div>
	);
}

export default PinOverlay;
