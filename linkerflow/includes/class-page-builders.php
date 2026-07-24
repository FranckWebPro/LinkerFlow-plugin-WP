<?php
defined( 'ABSPATH' ) || exit;

// Translates page-builder content to and from the plain-HTML view LinkerFlow expects.
// Elementor keeps its content as a JSON tree in the `_elementor_data` post meta, Divi keeps
// it as shortcodes inside post_content. On read both are flattened into HTML; on write the
// anchors LinkerFlow added are diffed against the live content and pushed back into the
// originating widget or shortcode, so the rest of the builder layout is untouched.
class LinkerFlow_Page_Builders {

	const TYPE_ELEMENTOR   = 'elementor';
	const TYPE_DIVI        = 'divi';
	const TYPE_UNSUPPORTED = 'unsupported';

	// Markers for builders we cannot yet translate; their posts stay excluded.
	// Divi 5 stores block-based layouts (`divi/*` blocks), not Divi 4 shortcodes,
	// so it is excluded until a block translator exists.
	const UNSUPPORTED_MARKERS = array( '[vc_row', '[fusion_builder', '<!-- wp:divi/' );

	// Elementor widget types whose body text LinkerFlow may link into.
	const ELEMENTOR_LINKABLE = array( 'text-editor' );

	// Returns 'elementor', 'divi', 'unsupported', or '' for native (Gutenberg/Classic) content.
	public function detect( WP_Post $post ) {
		foreach ( self::UNSUPPORTED_MARKERS as $marker ) {
			if ( false !== strpos( $post->post_content, $marker ) ) {
				return self::TYPE_UNSUPPORTED;
			}
		}

		if ( 'builder' === get_post_meta( $post->ID, '_elementor_edit_mode', true )
			&& '' !== (string) get_post_meta( $post->ID, '_elementor_data', true ) ) {
			return self::TYPE_ELEMENTOR;
		}

		if ( false !== strpos( $post->post_content, '[et_pb_section' ) ) {
			return self::TYPE_DIVI;
		}

		// Divi builder enabled but no Divi 4 shortcodes: Divi 5 block content the
		// shortcode translator cannot handle.
		if ( 'on' === get_post_meta( $post->ID, '_et_pb_use_builder', true ) ) {
			return self::TYPE_UNSUPPORTED;
		}

		return '';
	}

	public function is_supported( $type ) {
		return self::TYPE_ELEMENTOR === $type || self::TYPE_DIVI === $type;
	}

	// Flattens a builder post into the HTML view LinkerFlow crawls and links against.
	public function read_html( WP_Post $post, $type ) {
		if ( self::TYPE_ELEMENTOR === $type ) {
			return $this->elementor_read( $post );
		}
		if ( self::TYPE_DIVI === $type ) {
			return $this->divi_read( $post );
		}
		return $post->post_content;
	}

	// Applies LinkerFlow's anchor changes to the builder content and persists them.
	// Returns true on success, false when nothing matched (no write performed).
	public function write_html( WP_Post $post, $type, $incoming_html ) {
		if ( self::TYPE_ELEMENTOR === $type ) {
			return $this->elementor_write( $post, $incoming_html );
		}
		if ( self::TYPE_DIVI === $type ) {
			return $this->divi_write( $post, $incoming_html );
		}
		return false;
	}

	// --- Elementor ----------------------------------------------------------

	private function elementor_data( $post_id ) {
		$raw = get_post_meta( $post_id, '_elementor_data', true );
		if ( '' === (string) $raw ) {
			return array();
		}
		$data = json_decode( $raw, true );
		if ( null === $data ) {
			$data = json_decode( wp_unslash( $raw ), true );
		}
		return is_array( $data ) ? $data : array();
	}

	private function elementor_read( WP_Post $post ) {
		$blocks = array();
		$this->elementor_collect( $this->elementor_data( $post->ID ), $blocks );
		return implode( "\n", $blocks );
	}

	// Collects body text from text-editor widgets (the write targets) plus headings and any
	// widget carrying a link, so the link graph sees inbound builder links and the AI gets
	// SEO context. Read-only widgets are emitted but never written back to.
	private function elementor_collect( array $elements, array &$blocks ) {
		foreach ( $elements as $element ) {
			$el_type  = isset( $element['elType'] ) ? $element['elType'] : '';
			$settings = isset( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : array();

			if ( 'widget' === $el_type ) {
				$widget = isset( $element['widgetType'] ) ? $element['widgetType'] : '';

				if ( 'text-editor' === $widget && ! empty( $settings['editor'] ) ) {
					$blocks[] = $settings['editor'];
				} elseif ( 'heading' === $widget && ! empty( $settings['title'] ) ) {
					$blocks[] = '<h2>' . esc_html( $settings['title'] ) . '</h2>';
				} else {
					$link = $this->elementor_link( $settings, $widget );
					if ( '' !== $link['url'] && '' !== $link['text'] ) {
						$blocks[] = '<a href="' . esc_url( $link['url'] ) . '">' . esc_html( $link['text'] ) . '</a>';
					}
				}
			}

			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$this->elementor_collect( $element['elements'], $blocks );
			}
		}
	}

	// Resolves the link URL and label for a linked widget (button, call-to-action, linked image).
	private function elementor_link( array $settings, $widget ) {
		$url = '';
		if ( ! empty( $settings['link']['url'] ) ) {
			$url = $settings['link']['url'];
		} elseif ( ! empty( $settings['button_link']['url'] ) ) {
			$url = $settings['button_link']['url'];
		} elseif ( ! empty( $settings['image_link']['url'] ) ) {
			$url = $settings['image_link']['url'];
		}

		$text = '';
		foreach ( array( 'text', 'title', 'title_text', 'heading' ) as $key ) {
			if ( ! empty( $settings[ $key ] ) && is_string( $settings[ $key ] ) ) {
				$text = $settings[ $key ];
				break;
			}
		}
		if ( '' === $text && '' !== $url ) {
			$text = $widget;
		}

		return array( 'url' => $url, 'text' => $text );
	}

	private function elementor_write( WP_Post $post, $incoming_html ) {
		$data = $this->elementor_data( $post->ID );
		if ( ! $data ) {
			return false;
		}

		$current = $this->elementor_read( $post );
		$added   = $this->anchors_added( $current, $incoming_html );
		$removed = $this->anchors_removed( $current, $incoming_html );
		if ( ! $added && ! $removed ) {
			return false;
		}

		$backup  = $data;
		$changed = false;
		$this->elementor_apply( $data, $added, $removed, $changed );
		if ( ! $changed ) {
			return false;
		}

		update_post_meta( $post->ID, '_linkerflow_elementor_backup', wp_slash( wp_json_encode( $backup ) ) );
		update_post_meta( $post->ID, '_elementor_data', wp_slash( wp_json_encode( $data ) ) );
		$this->flush_elementor_css( $post->ID );
		$this->flush_page_caches( $post->ID );
		return true;
	}

	// Walks the tree and applies the anchor diff to text-editor widgets only.
	private function elementor_apply( array &$elements, array &$added, array $removed, &$changed ) {
		foreach ( $elements as &$element ) {
			$el_type = isset( $element['elType'] ) ? $element['elType'] : '';
			$widget  = isset( $element['widgetType'] ) ? $element['widgetType'] : '';

			if ( 'widget' === $el_type
				&& in_array( $widget, self::ELEMENTOR_LINKABLE, true )
				&& ! empty( $element['settings']['editor'] ) ) {

				$html = $element['settings']['editor'];

				foreach ( $removed as $anchor ) {
					$html = $this->unwrap_anchor( $html, $anchor );
				}

				foreach ( $added as $key => $anchor ) {
					$next = $this->inject_anchor( $html, $anchor );
					if ( null !== $next ) {
						$html = $next;
						unset( $added[ $key ] );
					}
				}

				if ( $html !== $element['settings']['editor'] ) {
					$element['settings']['editor'] = $html;
					$changed                       = true;
				}
			}

			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$this->elementor_apply( $element['elements'], $added, $removed, $changed );
			}
		}
		unset( $element );
	}

	// --- Divi ---------------------------------------------------------------

	private function divi_read( WP_Post $post ) {
		$blocks = array();

		if ( preg_match_all( '/\[et_pb_text\b[^\]]*\](.*?)\[\/et_pb_text\]/is', $post->post_content, $matches ) ) {
			foreach ( $matches[1] as $inner ) {
				$inner = trim( $inner );
				if ( '' !== $inner ) {
					$blocks[] = $inner;
				}
			}
		}

		// Surface button links so the link graph sees inbound builder navigation. Read-only.
		if ( preg_match_all( '/\[et_pb_button\b([^\]]*)\]/is', $post->post_content, $buttons ) ) {
			foreach ( $buttons[1] as $attrs ) {
				$url  = $this->divi_attr( $attrs, 'button_url' );
				$text = $this->divi_attr( $attrs, 'button_text' );
				if ( '' !== $url ) {
					$blocks[] = '<a href="' . esc_url( $url ) . '">' . esc_html( '' !== $text ? $text : $url ) . '</a>';
				}
			}
		}

		return implode( "\n", $blocks );
	}

	private function divi_attr( $attrs, $name ) {
		if ( preg_match( '/\b' . preg_quote( $name, '/' ) . '\s*=\s*("|\')(.*?)\1/i', $attrs, $match ) ) {
			return $match[2];
		}
		return '';
	}

	private function divi_write( WP_Post $post, $incoming_html ) {
		$current = $this->divi_read( $post );
		$added   = $this->anchors_added( $current, $incoming_html );
		$removed = $this->anchors_removed( $current, $incoming_html );
		if ( ! $added && ! $removed ) {
			return false;
		}

		$new_content = preg_replace_callback(
			'/(\[et_pb_text\b[^\]]*\])(.*?)(\[\/et_pb_text\])/is',
			function ( $match ) use ( &$added, $removed ) {
				$html = $match[2];

				foreach ( $removed as $anchor ) {
					$html = $this->unwrap_anchor( $html, $anchor );
				}

				foreach ( $added as $key => $anchor ) {
					$next = $this->inject_anchor( $html, $anchor );
					if ( null !== $next ) {
						$html = $next;
						unset( $added[ $key ] );
					}
				}

				return $match[1] . $html . $match[3];
			},
			$post->post_content
		);

		if ( null === $new_content || $new_content === $post->post_content ) {
			return false;
		}

		$result = wp_update_post(
			array(
				'ID'           => $post->ID,
				'post_content' => $new_content,
			),
			true
		);
		if ( is_wp_error( $result ) ) {
			return false;
		}

		$this->flush_divi_css( $post->ID );
		$this->flush_page_caches( $post->ID );
		return true;
	}

	// --- Anchor diff helpers ------------------------------------------------

	// Anchors present in the incoming HTML but not in the current content (new links to add).
	// Each entry keeps the full <a> tag so LinkerFlow's rel/target attributes survive.
	private function anchors_added( $current_html, $incoming_html ) {
		$current  = $this->anchor_keys( $current_html );
		$incoming = $this->extract_anchors( $incoming_html );
		$added    = array();
		foreach ( $incoming as $anchor ) {
			if ( ! isset( $current[ $anchor['key'] ] ) ) {
				$added[] = $anchor;
			}
		}
		return $added;
	}

	// Anchors present in the current content but gone from the incoming HTML (links to remove).
	private function anchors_removed( $current_html, $incoming_html ) {
		$incoming = $this->anchor_keys( $incoming_html );
		$current  = $this->extract_anchors( $current_html );
		$removed  = array();
		foreach ( $current as $anchor ) {
			if ( ! isset( $incoming[ $anchor['key'] ] ) ) {
				$removed[] = $anchor;
			}
		}
		return $removed;
	}

	private function anchor_keys( $html ) {
		$keys = array();
		foreach ( $this->extract_anchors( $html ) as $anchor ) {
			$keys[ $anchor['key'] ] = true;
		}
		return $keys;
	}

	private function extract_anchors( $html ) {
		$anchors = array();
		if ( ! preg_match_all( '/<a\b([^>]*)>(.*?)<\/a>/is', (string) $html, $matches, PREG_SET_ORDER ) ) {
			return $anchors;
		}
		foreach ( $matches as $match ) {
			$href = '';
			if ( preg_match( '/href\s*=\s*("|\')(.*?)\1/i', $match[1], $href_match ) ) {
				$href = $href_match[2];
			}
			$text = trim( wp_strip_all_tags( $match[2] ) );
			if ( '' === $text ) {
				continue;
			}
			$anchors[] = array(
				'tag'  => $match[0],
				'text' => $text,
				'href' => $href,
				'key'  => strtolower( $text ) . "\0" . $href,
			);
		}
		return $anchors;
	}

	// Wraps the first unlinked plain-text occurrence of the anchor text in the full <a> tag.
	// Returns the new HTML, or null when the text was not found outside an existing link.
	private function inject_anchor( $html, array $anchor ) {
		$segments = preg_split( '/(<a\b[^>]*>.*?<\/a>)/is', $html, -1, PREG_SPLIT_DELIM_CAPTURE );
		$injected = false;

		foreach ( $segments as $index => $segment ) {
			if ( '' === $segment || preg_match( '/^<a\b/i', $segment ) ) {
				continue;
			}
			$next = $this->replace_text_outside_tags( $segment, $anchor['text'], $anchor['tag'] );
			if ( null !== $next ) {
				$segments[ $index ] = $next;
				$injected           = true;
				break;
			}
		}

		return $injected ? implode( '', $segments ) : null;
	}

	// Replaces the first occurrence of $text that sits outside any HTML tag. Returns the new
	// string, or null when the text was only found inside markup (or not at all).
	private function replace_text_outside_tags( $html, $text, $replacement ) {
		$parts   = preg_split( '/(<[^>]+>)/', $html, -1, PREG_SPLIT_DELIM_CAPTURE );
		$changed = false;

		foreach ( $parts as $index => $part ) {
			if ( '' === $part || '<' === $part[0] ) {
				continue;
			}
			$pos = strpos( $part, $text );
			if ( false !== $pos ) {
				$parts[ $index ] = substr( $part, 0, $pos ) . $replacement . substr( $part, $pos + strlen( $text ) );
				$changed         = true;
				break;
			}
		}

		return $changed ? implode( '', $parts ) : null;
	}

	private function unwrap_anchor( $html, array $anchor ) {
		$pattern = '/<a\b[^>]*href\s*=\s*("|\')' . preg_quote( $anchor['href'], '/' ) . '\1[^>]*>(' . preg_quote( $anchor['text'], '/' ) . ')<\/a>/i';
		$result  = preg_replace( $pattern, '$2', $html, 1 );
		return null === $result ? $html : $result;
	}

	// --- Cache flushing -----------------------------------------------------

	private function flush_elementor_css( $post_id ) {
		if ( class_exists( '\\Elementor\\Plugin' ) && isset( \Elementor\Plugin::$instance->files_manager ) ) {
			\Elementor\Plugin::$instance->files_manager->clear_cache();
			return;
		}
		delete_post_meta( $post_id, '_elementor_css' );
	}

	private function flush_divi_css( $post_id ) {
		// Static CSS file: force = true so a generation lock cannot skip the removal.
		if ( class_exists( '\\ET_Core_PageResource' ) && method_exists( '\\ET_Core_PageResource', 'remove_static_resources' ) ) {
			\ET_Core_PageResource::remove_static_resources( $post_id, 'all', true );
		}

		// Dynamic Assets / Dynamic Module Framework: the per-module CSS component files
		// under et-cache/{id} are not touched by remove_static_resources, so a stale
		// manifest can serve a partial stylesheet after a link write. Drop the whole folder.
		$this->rmdir_recursive( WP_CONTENT_DIR . '/et-cache/' . (int) $post_id );
	}

	// Public entry point for the native (Gutenberg/Classic) write path, which updates
	// post_content directly and still needs the page caches purged.
	public function flush_caches( $post_id ) {
		$this->flush_page_caches( $post_id );
	}

	// --- Full-page cache flushing -------------------------------------------

	// A link write changes the rendered HTML, but WP Rocket / Cloudflare / host page
	// caches keep serving the previous markup (and, with Remove Unused CSS, a stale
	// inlined stylesheet) until the URL is purged. Each layer is guarded so a missing
	// plugin is a no-op. Scoped to the single edited post.
	private function flush_page_caches( $post_id ) {
		$post_id = (int) $post_id;

		// WP Rocket: purges the cached HTML, the Used CSS (RUCSS) entry, and Cloudflare
		// through the WP Rocket add-on when enabled.
		if ( function_exists( 'rocket_clean_post' ) ) {
			rocket_clean_post( $post_id );
		}

		// Kinsta and other host/object caches listen on this core hook.
		clean_post_cache( $post_id );

		// LiteSpeed and any listener on this action; no-op when absent.
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- native LiteSpeed Cache hook name, not ours to prefix.
		do_action( 'litespeed_purge_post', $post_id );
	}

	// Recursively deletes a directory through WP_Filesystem. The path is expected to be
	// a single et-cache/{id} folder; callers must pass a bounded, plugin-owned path.
	private function rmdir_recursive( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		global $wp_filesystem;
		if ( ! $wp_filesystem ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}

		if ( $wp_filesystem ) {
			$wp_filesystem->delete( $dir, true );
		}
	}
}
