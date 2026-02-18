import { select as dataSelect, useDispatch, useSelect } from '@wordpress/data';

export const WPPF_META_KEYS = {
	IS_POST_PINNED: '_wppf_is_post_pinned',
	POST_HTML: '_wppf_post_html',
};

export function usePostPinMeta() {
	const meta = useSelect(
		( select ) =>
			select( 'core/editor' ).getEditedPostAttribute( 'meta' ) || {},
		[]
	);
	const { editPost } = useDispatch( 'core/editor' );

	const isPinned = Boolean( meta[ WPPF_META_KEYS.IS_POST_PINNED ] );
	const html = meta[ WPPF_META_KEYS.POST_HTML ] || '';

	const updateMeta = ( changes ) => {
		editPost( {
			meta: {
				...meta,
				...changes,
			},
		} );
	};

	const setPinned = ( value ) => {
		updateMeta( { [ WPPF_META_KEYS.IS_POST_PINNED ]: Boolean( value ) } );
	};

	const setHtml = ( value ) => {
		updateMeta( { [ WPPF_META_KEYS.POST_HTML ]: value || '' } );
	};

	const pinCurrentState = () => {
		const editedContent =
			dataSelect( 'core/editor' ).getEditedPostContent() || '';
		updateMeta( {
			[ WPPF_META_KEYS.IS_POST_PINNED ]: true,
			[ WPPF_META_KEYS.POST_HTML ]: editedContent,
		} );
	};

	return {
		html,
		isPinned,
		pinCurrentState,
		setHtml,
		setPinned,
	};
}
