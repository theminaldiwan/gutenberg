/**
 * External dependencies
 */
import { has, get } from 'lodash';

/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';
import { applyFilters } from '@wordpress/hooks';
import {
	DropZone,
	Button,
	Spinner,
	ResponsiveWrapper,
	withNotices,
	withFilters,
} from '@wordpress/components';
import { isBlobURL } from '@wordpress/blob';
import { useState } from '@wordpress/element';
import { compose } from '@wordpress/compose';
import { useDispatch, useSelect } from '@wordpress/data';
import {
	MediaUpload,
	MediaUploadCheck,
	store as blockEditorStore,
} from '@wordpress/block-editor';
import { store as coreStore } from '@wordpress/core-data';
import { edit, trash } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import PostFeaturedImageCheck from './check';
import { store as editorStore } from '../../store';

const ALLOWED_MEDIA_TYPES = [ 'image' ];

// Used when labels from post type were not yet loaded or when they are not present.
const DEFAULT_FEATURE_IMAGE_LABEL = __( 'Featured image' );
const DEFAULT_SET_FEATURE_IMAGE_LABEL = __( 'Set featured image' );
const DEFAULT_REMOVE_FEATURE_IMAGE_LABEL = __( 'Remove image' );

const instructions = (
	<p>
		{ __(
			'To edit the featured image, you need permission to upload media.'
		) }
	</p>
);

function getMediaDetails( media, postId ) {
	if ( ! media ) {
		return {};
	}

	const defaultSize = applyFilters(
		'editor.PostFeaturedImage.imageSize',
		'large',
		media.id,
		postId
	);
	if ( has( media, [ 'media_details', 'sizes', defaultSize ] ) ) {
		return {
			mediaWidth: media.media_details.sizes[ defaultSize ].width,
			mediaHeight: media.media_details.sizes[ defaultSize ].height,
			mediaSourceUrl: media.media_details.sizes[ defaultSize ].source_url,
		};
	}

	// Use fallbackSize when defaultSize is not available.
	const fallbackSize = applyFilters(
		'editor.PostFeaturedImage.imageSize',
		'thumbnail',
		media.id,
		postId
	);
	if ( has( media, [ 'media_details', 'sizes', fallbackSize ] ) ) {
		return {
			mediaWidth: media.media_details.sizes[ fallbackSize ].width,
			mediaHeight: media.media_details.sizes[ fallbackSize ].height,
			mediaSourceUrl:
				media.media_details.sizes[ fallbackSize ].source_url,
		};
	}

	// Use full image size when fallbackSize and defaultSize are not available.
	return {
		mediaWidth: media.media_details.width,
		mediaHeight: media.media_details.height,
		mediaSourceUrl: media.source_url,
	};
}

function PostFeaturedImage( { noticeUI, noticeOperations } ) {
	const [ isLoading, setIsLoading ] = useState( false );
	const {
		media,
		mediaUpload,
		currentPostId,
		postType,
		featuredImageId,
	} = useSelect( ( select ) => {
		const { getMedia, getPostType } = select( coreStore );
		const { getSettings } = select( blockEditorStore );
		const { getCurrentPostId, getEditedPostAttribute } = select(
			editorStore
		);
		const featuredImage = getEditedPostAttribute( 'featured_media' );

		return {
			media: featuredImage
				? getMedia( featuredImage, { context: 'view' } )
				: null,
			currentPostId: getCurrentPostId(),
			postType: getPostType( getEditedPostAttribute( 'type' ) ),
			featuredImageId: featuredImage,
			mediaUpload: getSettings().mediaUpload,
		};
	}, [] );
	const { editPost } = useDispatch( editorStore );

	const postLabel = get( postType, [ 'labels' ], {} );
	const { mediaWidth, mediaHeight, mediaSourceUrl } = getMediaDetails(
		media,
		currentPostId
	);

	function onUpdateImage( image ) {
		if ( isBlobURL( image.url ) ) {
			setIsLoading( true );
			return;
		}

		editPost( { featured_media: image.id } );
		setIsLoading( false );
	}

	function onRemoveImage() {
		editPost( { featured_media: 0 } );
	}

	function onDropImage( filesList ) {
		mediaUpload( {
			allowedTypes: [ 'image' ],
			filesList,
			onFileChange( [ image ] ) {
				onUpdateImage( image );
			},
			onError( message ) {
				noticeOperations.removeAllNotices();
				noticeOperations.createErrorNotice( message );
			},
		} );
	}

	return (
		<PostFeaturedImageCheck>
			{ noticeUI }
			<div className="editor-post-featured-image">
				{ media && (
					<div
						id={ `editor-post-featured-image-${ featuredImageId }-describedby` }
						className="hidden"
					>
						{ media.alt_text &&
							sprintf(
								// Translators: %s: The selected image alt text.
								__( 'Current image: %s' ),
								media.alt_text
							) }
						{ ! media.alt_text &&
							sprintf(
								// Translators: %s: The selected image filename.
								__(
									'The current image has no alternative text. The file name is: %s'
								),
								media.media_details.sizes?.full?.file ||
									media.slug
							) }
					</div>
				) }
				<div className="editor-post-featured-image__container">
					<MediaUploadCheck fallback={ instructions }>
						<MediaUpload
							title={
								postLabel.featured_image ||
								DEFAULT_FEATURE_IMAGE_LABEL
							}
							onSelect={ onUpdateImage }
							unstableFeaturedImageFlow
							allowedTypes={ ALLOWED_MEDIA_TYPES }
							modalClass="editor-post-featured-image__media-modal"
							render={ ( { open } ) => (
								<>
									<Button
										className={
											! featuredImageId
												? 'editor-post-featured-image__toggle'
												: 'editor-post-featured-image__preview'
										}
										onClick={ open }
										aria-label={
											! featuredImageId
												? null
												: __(
														'Edit or update the image'
												  )
										}
										aria-describedby={
											! featuredImageId
												? null
												: `editor-post-featured-image-${ featuredImageId }-describedby`
										}
									>
										{ !! featuredImageId && media && (
											<ResponsiveWrapper
												naturalWidth={ mediaWidth }
												naturalHeight={ mediaHeight }
												isInline
											>
												<img
													src={ mediaSourceUrl }
													alt=""
												/>
											</ResponsiveWrapper>
										) }
										{ isLoading && <Spinner /> }
										{ ! featuredImageId &&
											! isLoading &&
											( postLabel.set_featured_image ||
												DEFAULT_SET_FEATURE_IMAGE_LABEL ) }
									</Button>
									<DropZone onFilesDrop={ onDropImage } />
								</>
							) }
							value={ featuredImageId }
						/>
					</MediaUploadCheck>
					<div className="editor-post-featured-image__actions">
						{ !! featuredImageId && media && (
							<MediaUploadCheck>
								<MediaUpload
									title={
										postLabel.featured_image ||
										DEFAULT_FEATURE_IMAGE_LABEL
									}
									onSelect={ onUpdateImage }
									unstableFeaturedImageFlow
									allowedTypes={ ALLOWED_MEDIA_TYPES }
									modalClass="editor-post-featured-image__media-modal"
									render={ ( { open } ) => (
										<Button
											label={ __( 'Replace Image' ) }
											icon={ edit }
											onClick={ open }
										/>
									) }
								/>
							</MediaUploadCheck>
						) }
						{ !! featuredImageId && (
							<MediaUploadCheck>
								<Button
									label={
										postLabel.remove_featured_image ||
										DEFAULT_REMOVE_FEATURE_IMAGE_LABEL
									}
									icon={ trash }
									onClick={ onRemoveImage }
								/>
							</MediaUploadCheck>
						) }
					</div>
				</div>
			</div>
		</PostFeaturedImageCheck>
	);
}

export default compose(
	withNotices,
	withFilters( 'editor.PostFeaturedImage' )
)( PostFeaturedImage );
