import { __ } from '@wordpress/i18n';

function PinOverlay( {
	className = '',
	label = '',
	actionLabel = '',
	onRequestSelect,
	onRequestUnpin,
} ) {
	const hasActionButton = Boolean( onRequestUnpin && actionLabel );
	const hasDirectOverlayAction = Boolean(
		onRequestUnpin && ! hasActionButton
	);
	const hasOverlaySelectAction = Boolean(
		onRequestSelect && ! hasDirectOverlayAction
	);
	const hasOverlayAction = Boolean(
		hasDirectOverlayAction || hasOverlaySelectAction
	);

	const onClick = ( event ) => {
		if ( ! hasOverlayAction ) {
			return;
		}

		event.preventDefault();
		event.stopPropagation();

		if ( hasDirectOverlayAction && onRequestUnpin ) {
			onRequestUnpin();
			return;
		}

		if ( hasOverlaySelectAction && onRequestSelect ) {
			onRequestSelect();
		}
	};

	const onKeyDown = ( event ) => {
		if ( ! hasOverlayAction ) {
			return;
		}

		if ( 'Enter' !== event.key && ' ' !== event.key ) {
			return;
		}

		event.preventDefault();
		onClick( event );
	};

	const onActionClick = ( event ) => {
		event.preventDefault();
		event.stopPropagation();

		if ( onRequestUnpin ) {
			onRequestUnpin();
		}
	};

	return (
		<div
			className={ `wppf-pin-overlay ${ className }`.trim() }
			role={ hasOverlayAction ? 'button' : undefined }
			tabIndex={ hasOverlayAction ? 0 : undefined }
			onClick={ hasOverlayAction ? onClick : undefined }
			onKeyDown={ hasOverlayAction ? onKeyDown : undefined }
		>
			<div className="wppf-pin-overlay__content">
				<span className="wppf-pin-overlay__title-row">
					<span className="wppf-pin-overlay__icon" aria-hidden="true">
						<svg viewBox="0 0 24 24" focusable="false">
							<path d="M16 9V4l1-1V2H7v1l1 1v5l-2 2v1h5v7l1 1 1-1v-7h5v-1l-2-2z" />
						</svg>
					</span>
					<span className="wppf-pin-overlay__title">
						{ label ||
							__( 'Este contenido está pineado.', 'pin-freeze' ) }
					</span>
				</span>
				{ ! hasActionButton && (
					<span className="wppf-pin-overlay__subtitle">
						{ __( 'Haz clic para despinearlo.', 'pin-freeze' ) }
					</span>
				) }
				{ hasActionButton && (
					<button
						type="button"
						className="wppf-pin-overlay__button"
						onClick={ onActionClick }
					>
						{ actionLabel }
					</button>
				) }
			</div>
		</div>
	);
}

export default PinOverlay;
