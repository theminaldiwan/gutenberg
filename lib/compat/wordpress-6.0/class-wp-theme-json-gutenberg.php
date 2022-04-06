<?php
/**
 * WP_Theme_JSON_Gutenberg class
 *
 * @package gutenberg
 */

/**
 * Class that encapsulates the processing of structures that adhere to the theme.json spec.
 *
 * This class is for internal core usage and is not supposed to be used by extenders (plugins and/or themes).
 * This is a low-level API that may need to do breaking changes. Please,
 * use get_global_settings, get_global_styles, and get_global_stylesheet instead.
 *
 * @access private
 */
class WP_Theme_JSON_Gutenberg extends WP_Theme_JSON_5_9 {

	/**
	 * The top-level keys a theme.json can have.
	 *
	 * @var string[]
	 */
	const VALID_TOP_LEVEL_KEYS = array(
		'customTemplates',
		'patterns',
		'settings',
		'styles',
		'templateParts',
		'version',
		'title',
	);

	const APPEARANCE_TOOLS_OPT_INS = array(
		array( 'border', 'color' ),
		array( 'border', 'radius' ),
		array( 'border', 'style' ),
		array( 'border', 'width' ),
		array( 'color', 'link' ),
		array( 'spacing', 'blockGap' ),
		array( 'spacing', 'margin' ),
		array( 'spacing', 'padding' ),
		array( 'typography', 'lineHeight' ),
	);

	/**
	 * Returns the current theme's wanted patterns(slugs) to be
	 * registered from Pattern Directory.
	 *
	 * @return array
	 */
	public function get_patterns() {
		if ( isset( $this->theme_json['patterns'] ) && is_array( $this->theme_json['patterns'] ) ) {
			return $this->theme_json['patterns'];
		}
		return array();
	}

	/**
	 * Converts each style section into a list of rulesets
	 * containing the block styles to be appended to the stylesheet.
	 *
	 * See glossary at https://developer.mozilla.org/en-US/docs/Web/CSS/Syntax
	 *
	 * For each section this creates a new ruleset such as:
	 *
	 *   block-selector {
	 *     style-property-one: value;
	 *   }
	 *
	 * @param array $style_nodes Nodes with styles.
	 * @return string The new stylesheet.
	 */
	protected function get_block_classes( $style_nodes ) {
		$block_rules = '';

		foreach ( $style_nodes as $metadata ) {
			if ( null === $metadata['selector'] ) {
				continue;
			}

			$node         = _wp_array_get( $this->theme_json, $metadata['path'], array() );
			$selector     = $metadata['selector'];
			$settings     = _wp_array_get( $this->theme_json, array( 'settings' ) );
			$declarations = static::compute_style_properties( $node, $settings );

			// 1. Separate the ones who use the general selector
			// and the ones who use the duotone selector.
			$declarations_duotone = array();
			foreach ( $declarations as $index => $declaration ) {
				if ( 'filter' === $declaration['name'] ) {
					unset( $declarations[ $index ] );
					$declarations_duotone[] = $declaration;
				}
			}

			/*
			 * Reset default browser margin on the root body element.
			 * This is set on the root selector **before** generating the ruleset
			 * from the `theme.json`. This is to ensure that if the `theme.json` declares
			 * `margin` in its `spacing` declaration for the `body` element then these
			 * user-generated values take precedence in the CSS cascade.
			 * @link https://github.com/WordPress/gutenberg/issues/36147.
			 */
			if ( static::ROOT_BLOCK_SELECTOR === $selector ) {
				$block_rules .= 'body { margin: 0; }';
			}

			// 2. Generate the rules that use the general selector.
			$block_rules .= static::to_ruleset( $selector, $declarations );

			// 3. Generate the rules that use the duotone selector.
			if ( isset( $metadata['duotone'] ) && ! empty( $declarations_duotone ) ) {
				$selector_duotone = static::scope_selector( $metadata['selector'], $metadata['duotone'] );
				$block_rules     .= static::to_ruleset( $selector_duotone, $declarations_duotone );
			}

			if ( static::ROOT_BLOCK_SELECTOR === $selector ) {
				$block_rules .= '.wp-site-blocks > .alignleft { float: left; margin-right: 2em; }';
				$block_rules .= '.wp-site-blocks > .alignright { float: right; margin-left: 2em; }';
				$block_rules .= '.wp-site-blocks > .aligncenter { justify-content: center; margin-left: auto; margin-right: auto; }';

				$has_block_gap_support = _wp_array_get( $this->theme_json, array( 'settings', 'spacing', 'blockGap' ) ) !== null;
				if ( $has_block_gap_support ) {
					$block_rules .= '.wp-site-blocks > * { margin-block-start: 0; margin-block-end: 0; }';
					$block_rules .= '.wp-site-blocks > * + * { margin-block-start: var( --wp--style--block-gap ); }';
				}
			}
		}

		return $block_rules;
	}

	/**
	 * Returns a valid theme.json for a theme.
	 * Essentially, it flattens the preset data.
	 *
	 * @return array
	 */
	public function get_data() {
		$flattened_theme_json = $this->theme_json;
		$nodes                = static::get_setting_nodes( $this->theme_json );
		foreach ( $nodes as $node ) {
			foreach ( static::PRESETS_METADATA as $preset_metadata ) {
				$path   = array_merge( $node['path'], $preset_metadata['path'] );
				$preset = _wp_array_get( $flattened_theme_json, $path, null );
				if ( null === $preset ) {
					continue;
				}

				$items = array();
				if ( isset( $preset['theme'] ) ) {
					foreach ( $preset['theme'] as $item ) {
						$slug = $item['slug'];
						unset( $item['slug'] );
						$items[ $slug ] = $item;
					}
				}
				if ( isset( $preset['custom'] ) ) {
					foreach ( $preset['custom'] as $item ) {
						$slug = $item['slug'];
						unset( $item['slug'] );
						$items[ $slug ] = $item;
					}
				}
				$flattened_preset = array();
				foreach ( $items as $slug => $value ) {
					$flattened_preset[] = array_merge( array( 'slug' => $slug ), $value );
				}
				_wp_array_set( $flattened_theme_json, $path, $flattened_preset );
			}
		}

		$flattened_theme_json = static::use_appearance_tools_setting( $flattened_theme_json );

		wp_recursive_ksort( $flattened_theme_json );


		return $flattened_theme_json;
	}

	protected static function do_opt_in_into_settings( &$context ) {
		foreach ( static::APPEARANCE_TOOLS_OPT_INS as $path ) {
			// Use "unset prop" as a marker instead of "null" because
			// "null" can be a valid value for some props (e.g. blockGap).
			if ( 'unset prop' === _wp_array_get( $context, $path, 'unset prop' ) ) {
				_wp_array_set( $context, $path, true );
			}
		}

		unset( $context['appearanceTools'] );
	}

	protected static function use_appearance_tools_setting( $theme_json ) {
		$nodes = static::get_setting_nodes( $theme_json );
		foreach ( $nodes as $node ) {
			$all_opt_ins_are_set = true;
			foreach( static::APPEARANCE_TOOLS_OPT_INS as $opt_in_path ) {
				$full_path = array_merge( $node['path'], $opt_in_path );
				// Use "unset prop" as a marker instead of "null" because
				// "null" can be a valid value for some props (e.g. blockGap).
				$opt_in_value = _wp_array_get( $theme_json, $full_path, 'unset prop' );
				if ( 'unset prop' === $opt_in_value ) {
					$all_opt_ins_are_set = false;
					break;
				}
			}

			if ( $all_opt_ins_are_set ) {
				_wp_array_set( $theme_json, array_merge( $node['path'], array( 'appearanceTools' ) ), true );
				foreach( static::APPEARANCE_TOOLS_OPT_INS as $opt_in_path ) {
					$full_path = array_merge( $node['path'], $opt_in_path );
					// Use "unset prop" as a marker instead of "null" because
					// "null" can be a valid value for some props (e.g. blockGap).
					$opt_in_value = _wp_array_get( $theme_json, $full_path, 'unset prop' );
					if ( true !== $opt_in_value ) {
						continue;
					}

					// The following could be improved to be path independent.
					// At the moment it relies on a couple of assumptions:
					//
					// - all opt-ins having a path of size 2
					// - there's two sources of settings: the top-level and the block-level
					if (
						( 1 === count( $node['path'] ) ) &&
						( 'settings' === $node['path'][0] )
					) {
						// Top-level settings.
						unset( $theme_json['settings'][ $opt_in_path[0] ][ $opt_in_path[1] ] );
						if ( empty( $theme_json['settings'][ $opt_in_path[0] ] ) ) {
							unset( $theme_json['settings'][ $opt_in_path[0] ] );
						}
					} else if (
						( 3 === count( $node['path'] ) ) &&
						( 'settings' === $node['path'][0] ) &&
						( 'blocks' === $node['path'][1] )
					) {
						// Block-level settings.
						$block_name = $node['path'][2];
						unset( $theme_json['settings']['blocks'][ $block_name ][ $opt_in_path[0] ][ $opt_in_path[1] ] );
						if ( empty( $theme_json['settings']['blocks'][ $block_name ][ $opt_in_path[0] ] ) ) {
							unset( $theme_json['settings']['blocks'][ $block_name ][ $opt_in_path[0] ] );
						}
					}
				}
			}
		}

		return $theme_json;
	}

}
