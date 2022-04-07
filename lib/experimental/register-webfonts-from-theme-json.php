<?php
/**
 * Bootstraps Global Styles.
 *
 * @package gutenberg
 */

/**
 * Register webfonts defined in theme.json
 *
 * @param array $theme_settings The theme.json file.
 */
function gutenberg_register_webfonts_from_theme_json( $theme_settings ) {
	// Bail out early if there are no settings for webfonts.
	if ( empty( $theme_settings['typography'] ) || empty( $theme_settings['typography']['fontFamilies'] ) ) {
		return;
	}

	$font_faces_to_register = array();

	foreach ( $theme_settings['typography']['fontFamilies'] as $font_families_by_origin ) {
		foreach ( $font_families_by_origin as $font_family ) {
			if ( isset( $font_family['provider'] ) ) {
				if ( empty( $font_family['fontFaces'] ) ) {
					trigger_error(
						sprintf(
							'The "%s" font family specifies a provider, but no font faces.',
							$font_family['fontFamily']
						)
					);

					continue;
				}

				foreach ( $font_family['fontFaces'] as $font_face ) {
					$font_face['provider'] = $font_family['provider'];
					$font_face             = gutenberg_resolve_font_face_uri( $font_face );
					$font_face             = gutenberg_webfont_to_kebab_case( $font_face );

					$font_faces_to_register[] = $font_face;
				}

				continue;
			}

			if ( ! isset( $font_family['fontFaces'] ) ) {
				continue;
			}

			foreach ( $font_family['fontFaces'] as $font_face ) {
				if ( isset( $font_face['provider'] ) ) {
					$font_face = gutenberg_resolve_font_face_uri( $font_face );
					$font_face = gutenberg_webfont_to_kebab_case( $font_face );

					$font_faces_to_register[] = $font_face;
				}
			}
		}
	}

	wp_register_webfonts( $font_faces_to_register );
}
