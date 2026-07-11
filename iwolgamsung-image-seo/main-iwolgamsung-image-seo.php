<?php
/**
 * Change Log (2026-05-24)
 * - Issue: Deep scan returned empty results and batch runs were ending with many SKIP/fail states.
 * - Root cause: Keyword filtering was tied too narrowly to URL-only matching and scan candidate selection was inconsistent.
 * - Fix:
 *   1) Switched deep-scan image extraction to a broader image URL regex (jpg/jpeg/png/webp/gif).
 *   2) Reworked candidate SQL keyword filtering across title/content/excerpt/meta text.
 *   3) Kept completed-item exclusion via _img_seo_json_ld to reduce pointless SKIP-heavy runs.
 *   4) Applied URL-level keyword filtering only for URL-like keywords (domain/path tokens).
 */
/**
 * Module Name: Iwolgamsung Image SEO
 * Description 1: 상품 이미지 로컬 저장, ALT 매핑, JSON-LD 출력
 * Description 2: 누락/미연결 이미지 역추적 및 서버 부하 대기 조절
 * Description 3: 대표 이미지와 외부 링크 치환 처리
 * Module Color: color-orange
 * Version: 1.2.3.7
 * Date: 2026-05-24
 * Serial: ho7425
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'IMG_SEO_PRO_VERSION' ) ) {
	define( 'IMG_SEO_PRO_VERSION', '1.2.3.7' );
	define( 'IMG_SEO_PRO_URL', content_url( '/code_snippets/iwolgamsung-image-seo' ) );
}

if ( ! function_exists( 'img_seo_extend_runtime' ) ) {
	function img_seo_extend_runtime( $seconds = 120 ) {
		$seconds = max( 30, absint( $seconds ) );
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( $seconds );
		}
	}
}

if ( ! function_exists( 'img_seo_get_post_type_label' ) ) {
	function img_seo_get_post_type_label( $post_type ) {
		return 'post' === $post_type ? '글' : '상품';
	}
}

if ( ! function_exists( 'img_seo_get_cron_url' ) ) {
	function img_seo_get_cron_url() {
		return site_url( 'wp-cron.php?doing_wp_cron=1' );
	}
}

if ( ! function_exists( 'img_seo_enqueue_assets' ) ) {
	function img_seo_enqueue_assets( $hook ) {
		$is_our_page = in_array( $hook, array( 'hotheart-wp-admin-utility_page_image-seo-tool', 'admin_page_image-seo-tool' ), true );
		if ( ! $is_our_page ) {
			$page = isset( $_GET['page'] ) ? sanitize_text_field( $_GET['page'] ) : '';
			if ( 'image-seo-tool' !== $page ) {
				return;
			}
		}

		wp_enqueue_style( 'img-seo-pro-style', IMG_SEO_PRO_URL . '/style.css', array(), IMG_SEO_PRO_VERSION );
		wp_enqueue_script( 'img-seo-pro-script', IMG_SEO_PRO_URL . '/script.js', array( 'jquery' ), IMG_SEO_PRO_VERSION, true );
		wp_localize_script(
			'img-seo-pro-script',
			'imgSeoPro',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'img_seo_pro_nonce' ),
			)
		);
	}
}

if ( ! function_exists( 'img_seo_verify_ajax' ) ) {
	function img_seo_verify_ajax() {
		check_ajax_referer( 'img_seo_pro_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized', 403 );
		}
	}
}

if ( ! function_exists( 'img_seo_render_page' ) ) {
	function img_seo_render_page() {
		$saved_keyword       = get_option( 'img_seo_keyword', 'media-amazon.com' );
		$saved_delay         = get_option( 'img_seo_delay_time', '5' );
		$adaptive_status     = get_option( 'img_seo_adaptive_status', 'on' );
		$saved_cpu_threshold = get_option( 'img_seo_cpu_threshold', '70' );
		$saved_mem_threshold = get_option( 'img_seo_mem_threshold', '70' );
		$saved_post_types    = get_option( 'img_seo_post_types', array( 'product' ) );
		$cron_enabled        = get_option( 'img_seo_cron_enabled', 'off' );
		$cron_url            = img_seo_get_cron_url();
		if ( ! is_array( $saved_post_types ) ) {
			$saved_post_types = array( 'product' );
		}
		$summary_counts = img_seo_get_summary_counts();

		require __DIR__ . '/view.php';
	}
}

if ( ! function_exists( 'img_seo_get_summary_counts' ) ) {
	function img_seo_get_summary_counts() {
		global $wpdb;

		$summary = array();
		foreach ( array( 'product' => '상품', 'post' => '글' ) as $post_type => $label ) {
			$counts    = wp_count_posts( $post_type );
			$published = isset( $counts->publish ) ? (int) $counts->publish : 0;
			$draft     = isset( $counts->draft ) ? (int) $counts->draft : 0;
			$total     = $published + $draft;
			$like      = '%m.media-amazon.com/images%';
			$candidate_total = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(DISTINCT p.ID)
					FROM {$wpdb->posts} p
					LEFT JOIN {$wpdb->postmeta} src ON p.ID = src.post_id
					WHERE p.post_type = %s
					AND p.post_status IN ('publish', 'draft')
					AND (
						p.post_content LIKE %s
						OR p.post_excerpt LIKE %s
						OR src.meta_value LIKE %s
					)",
					$post_type,
					$like,
					$like,
					$like
				)
			);
			$completed = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(DISTINCT p.ID)
					FROM {$wpdb->posts} p
					LEFT JOIN {$wpdb->postmeta} src ON p.ID = src.post_id
					INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
					WHERE p.post_type = %s
					AND p.post_status IN ('publish', 'draft')
					AND (
						p.post_content LIKE %s
						OR p.post_excerpt LIKE %s
						OR src.meta_value LIKE %s
					)
					AND pm.meta_key = %s
					AND pm.meta_value != ''",
					$post_type,
					$like,
					$like,
					$like,
					'_img_seo_json_ld'
				)
			);

			$summary[ $post_type ] = array(
				'label'      => $label,
				'total'      => $total,
				'publish'    => $published,
				'draft'      => $draft,
				'completed'  => $completed,
				'incomplete' => max( 0, $candidate_total - $completed ),
			);
		}

		return $summary;
	}
}

if ( ! function_exists( 'img_seo_output_json_ld' ) ) {
	function img_seo_output_json_ld() {
		if ( ! function_exists( 'is_product' ) || ! is_product() ) {
			return;
		}

		$json_ld = get_post_meta( get_the_ID(), '_img_seo_json_ld', true );
		if ( ! empty( $json_ld ) ) {
			echo "\n" . wp_kses( $json_ld, array( 'script' => array( 'type' => true ) ) ) . "\n";
		}
	}
}

if ( ! function_exists( 'img_seo_scan_content_images' ) ) {
	function img_seo_extract_common_image_urls( $content ) {
		$content = html_entity_decode( wp_unslash( (string) $content ), ENT_QUOTES, get_bloginfo( 'charset' ) );
		$content = str_replace( '\/', '/', $content );
		$urls    = array();

		// Shared regex for jpg/jpeg/png/webp/gif style image links in raw HTML/shortcode text.
		if ( preg_match_all( '#https?://[^\s"\'<>]+?\.(?:jpg|jpeg|png|webp|gif)(?:[^\s"\'<>]*)?#i', $content, $matches ) ) {
			$urls = array_values( array_unique( array_filter( array_map( 'esc_url_raw', $matches[0] ) ) ) );
		}

		return $urls;
	}

	function img_seo_keyword_matches_text( $text, $keyword ) {
		$keyword = trim( (string) $keyword );
		if ( '' === $keyword ) {
			return true;
		}

		$text = (string) $text;
		$pattern = '/' . preg_quote( $keyword, '/' ) . '/i';
		return 1 === preg_match( $pattern, $text );
	}

	function img_seo_extract_amazon_image_urls( $content ) {
		$urls = array();
		$content = html_entity_decode( wp_unslash( (string) $content ), ENT_QUOTES, get_bloginfo( 'charset' ) );
		$content = str_replace( '\/', '/', $content );

		if ( preg_match_all( '#https:\/\/m\.media-amazon\.com\/images\/[^\s"\'\]<>]+?_\.jpg#i', $content, $matches ) ) {
			$urls = array_values( array_unique( array_filter( array_map( 'esc_url_raw', $matches[0] ) ) ) );
		}

		return $urls;
	}

	function img_seo_resolve_image_value( $value, $post_id = 0 ) {
		$value = is_string( $value ) ? trim( $value ) : '';
		if ( '' === $value ) {
			return '';
		}

		$meta_keys = array( 'product_imgs', '_product_imgs_local', '_product_imgs_loca' );
		if ( $post_id && ( in_array( $value, $meta_keys, true ) || false !== stripos( $value, '_product_imgs_local' ) || false !== stripos( $value, '_product_imgs_loca' ) || false !== stripos( $value, 'product_imgs' ) ) ) {
			foreach ( $meta_keys as $meta_key ) {
				$meta_value = get_post_meta( $post_id, $meta_key, true );
				if ( is_string( $meta_value ) && '' !== trim( $meta_value ) ) {
					return trim( $meta_value );
				}

				if ( is_array( $meta_value ) || is_object( $meta_value ) ) {
					$flat = img_seo_flatten_scan_value( $meta_value );
					$flat = array_filter( array_map( 'trim', $flat ) );
					if ( ! empty( $flat ) ) {
						return implode( ',', $flat );
					}
				}
			}
		}

		return $value;
	}

	function img_seo_scan_content_images( $content, $keyword = '', $post_id = 0 ) {
		$images = img_seo_extract_common_image_urls( $content );
		if ( '' === $keyword ) {
			return $images;
		}

		return array_values(
			array_filter(
				$images,
				function( $url ) use ( $keyword ) {
					// Keyword filtering is regex-safe and can match domains, ids, or literal tokens.
					return img_seo_keyword_matches_text( $url, $keyword );
				}
			)
		);
	}
}

if ( ! function_exists( 'img_seo_render_shortcode_attrs' ) ) {
	function img_seo_render_shortcode_attrs( $attrs ) {
		$parts = array();
		foreach ( $attrs as $key => $value ) {
			if ( is_int( $key ) ) {
				$parts[] = esc_attr( $value );
				continue;
			}
			$parts[] = sprintf( '%s="%s"', $key, esc_attr( $value ) );
		}

		return implode( ' ', array_filter( $parts ) );
	}
}

if ( ! function_exists( 'img_seo_replace_shortcode_image_attrs' ) ) {
	function img_seo_replace_shortcode_image_attrs( $content, $map, $alts, $post_id ) {
		$replacement_urls = array_values( array_filter( $map ) );
		if ( empty( $replacement_urls ) ) {
			return $content;
		}

		$primary_alt = '';
		if ( is_array( $alts ) ) {
			foreach ( $alts as $alt_value ) {
				$alt_value = trim( (string) $alt_value );
				if ( '' !== $alt_value ) {
					$primary_alt = str_replace( ',', '', sanitize_text_field( $alt_value ) );
					break;
				}
			}
		}
		if ( '' === $primary_alt ) {
			$primary_alt = get_the_title( $post_id );
		}

		$content = preg_replace_callback(
			'/\[(custom_carousel|prod_img)\b([^\]]*)\]/i',
			function( $matches ) use ( $replacement_urls, $primary_alt ) {
				$tag_name  = strtolower( $matches[1] );
				$attr_text = trim( $matches[2] ?? '' );
				$attrs     = shortcode_parse_atts( $attr_text );
				if ( ! is_array( $attrs ) ) {
					$attrs = array();
				}

				$target_attr = 'custom_carousel' === $tag_name ? 'link' : 'imglink';
				$attrs[ $target_attr ] = implode( ',', $replacement_urls );
				$attrs['alt']          = $primary_alt;

				return '[' . $tag_name . ' ' . img_seo_render_shortcode_attrs( $attrs ) . ']';
			},
			$content
		);

		return $content;
	}
}

if ( ! function_exists( 'img_seo_get_product_all_images' ) ) {
	function img_seo_get_product_all_images( $post_id, $content = '' ) {
		$images = img_seo_extract_amazon_image_urls( $content );
		return array_values( array_unique( array_filter( $images ) ) );
	}
}

if ( ! function_exists( 'img_seo_get_product_scan_text' ) ) {
	function img_seo_flatten_scan_value( $value ) {
		$parts = array();

		if ( is_string( $value ) ) {
			$maybe = maybe_unserialize( $value );
			if ( $maybe !== $value ) {
				return img_seo_flatten_scan_value( $maybe );
			}
		}

		if ( is_array( $value ) || is_object( $value ) ) {
			foreach ( (array) $value as $child ) {
				$parts = array_merge( $parts, img_seo_flatten_scan_value( $child ) );
			}
			return $parts;
		}

		if ( is_scalar( $value ) ) {
			$parts[] = (string) $value;
		}

		return $parts;
	}

	function img_seo_get_product_scan_text( $post_id, $content = '', $excerpt = '' ) {
		$parts = array( (string) $content, (string) $excerpt );
		$meta  = get_post_meta( $post_id );

		if ( is_array( $meta ) ) {
			foreach ( $meta as $values ) {
				foreach ( (array) $values as $value ) {
					$parts = array_merge( $parts, img_seo_flatten_scan_value( $value ) );
				}
			}
		}

		return implode( "\n", array_filter( $parts ) );
	}
}

if ( ! function_exists( 'img_seo_read_server_usage' ) ) {
	function img_seo_read_server_usage() {
		$cpu_val = 0;
		$mem_val = 0;

		if ( function_exists( 'aapanel_monitor_fetch_stats' ) ) {
			$stats   = aapanel_monitor_fetch_stats();
			$cpu_val = round( (float) ( $stats['cpu']['used'] ?? 0 ) );
			$mem_val = round( (float) ( $stats['memory']['pct'] ?? 0 ) );
		} elseif ( function_exists( 'iwol_load_server_stats_to_global' ) ) {
			$stats   = iwol_load_server_stats_to_global();
			$cpu_val = round( (float) ( $stats['cpu_usage'] ?? 0 ) * 100 );
			$mem_val = round( (float) ( $stats['mem_percent'] ?? 0 ) );
		}

		return array(
			'cpu' => $cpu_val,
			'mem' => $mem_val,
		);
	}
}

if ( ! function_exists( 'img_seo_normalize_post_types' ) ) {
	function img_seo_normalize_post_types( $raw = null ) {
		if ( null === $raw ) {
			$raw = get_option( 'img_seo_post_types', array( 'product' ) );
		}

		if ( ! is_array( $raw ) ) {
			$raw = array( $raw );
		}

		$allowed = array( 'product', 'post' );
		$types   = array_values( array_intersect( array_map( 'sanitize_key', $raw ), $allowed ) );

		return ! empty( $types ) ? $types : array( 'product' );
	}
}

if ( ! function_exists( 'img_seo_append_log' ) ) {
	function img_seo_append_log( $message ) {
		$logs = get_option( 'img_seo_cron_logs', array() );
		if ( ! is_array( $logs ) ) {
			$logs = array();
		}

		array_unshift(
			$logs,
			sprintf(
				'[%s] %s',
				current_time( 'Y-m-d H:i:s' ),
				sanitize_text_field( $message )
			)
		);

		update_option( 'img_seo_cron_logs', array_slice( $logs, 0, 50 ), false );
	}
}

if ( ! function_exists( 'img_seo_cron_schedule' ) ) {
	function img_seo_cron_schedule() {
		if ( 'on' !== get_option( 'img_seo_cron_enabled', 'off' ) ) {
			return;
		}

		if ( ! wp_next_scheduled( 'img_seo_cron_batch_event' ) ) {
			wp_schedule_event( time() + 300, 'hourly', 'img_seo_cron_batch_event' );
		}
	}
}

if ( ! function_exists( 'img_seo_cron_unschedule' ) ) {
	function img_seo_cron_unschedule() {
		$timestamp = wp_next_scheduled( 'img_seo_cron_batch_event' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'img_seo_cron_batch_event' );
		}
	}
}

if ( ! function_exists( 'img_seo_cron_batch_run' ) ) {
	function img_seo_cron_batch_run( $force = false ) {
		global $wpdb;
		img_seo_extend_runtime( 300 );

		if ( ! $force && 'on' !== get_option( 'img_seo_cron_enabled', 'off' ) ) {
			img_seo_cron_unschedule();
			return;
		}

		$keyword      = (string) get_option( 'img_seo_keyword', 'media-amazon.com' );
		$post_types   = img_seo_normalize_post_types();
		$placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
		$sql          = $wpdb->prepare(
			"SELECT ID, post_title, post_content, post_excerpt FROM {$wpdb->posts} WHERE post_type IN ($placeholders) AND post_status != %s ORDER BY ID DESC LIMIT 1",
			array_merge( $post_types, array( 'trash' ) )
		);
		$post         = $wpdb->get_row( $sql );

		if ( ! $post ) {
			img_seo_append_log( 'WP-Cron 실행: 대상 없음' );
			return;
		}

		$scan_content = img_seo_get_product_scan_text( $post->ID, $post->post_content, $post->post_excerpt );
		$matched_imgs = img_seo_scan_content_images( $scan_content, $keyword, $post->ID );
		$all_imgs     = img_seo_get_product_all_images( $post->ID, $scan_content );
		$imgs         = array_values( array_unique( array_filter( array_merge( $matched_imgs, $all_imgs ) ) ) );

		img_seo_append_log( sprintf( 'WP-Cron 1회 실행: %s #%d 이미지 %d개 감지', img_seo_get_post_type_label( $post->post_type ), $post->ID, count( $imgs ) ) );
	}
}

if ( ! function_exists( '_img_seo_process_single_image' ) ) {
	/**
	 * Process a single image: download, convert to WebP, add watermark, upload to media library.
	 * Returns array with 'success' => bool.
	 */
	function _img_seo_process_single_image( $post_id, $url, $alt, $slug, $is_feat ) {
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		if ( ! $post_id || '' === $url ) {
			return array( 'success' => false, 'error' => 'Invalid request' );
		}

		// Skip already completed items.
		$existing_json_ld = get_post_meta( $post_id, '_img_seo_json_ld', true );
		if ( ! empty( $existing_json_ld ) ) {
			return array( 'success' => true, 'skipped' => true );
		}

		$response = wp_remote_get( $url, array( 'timeout' => 20, 'sslverify' => false ) );
		if ( is_wp_error( $response ) ) {
			return array( 'success' => false, 'error' => 'Fetch Error' );
		}

		$raw = wp_remote_retrieve_body( $response );
		$src = function_exists( 'imagecreatefromstring' ) ? @imagecreatefromstring( $raw ) : false;
		if ( ! $src ) {
			return array( 'success' => false, 'error' => 'Image Creation Error' );
		}

		$w = imagesx( $src );
		$h = imagesy( $src );
		if ( $w < 4 || $h < 4 ) {
			imagedestroy( $src );
			return array( 'success' => false, 'error' => 'Image Too Small' );
		}

		$shrink_w = random_int( 1, min( 3, max( 1, $w - 1 ) ) );
		$shrink_h = random_int( 1, min( 3, max( 1, $h - 1 ) ) );
		$dest_w   = max( 1, $w - $shrink_w );
		$dest_h   = max( 1, $h - $shrink_h );
		$quality  = random_int( 80, 100 );

		$dest = imagecreatetruecolor( $dest_w, $dest_h );
		imagealphablending( $dest, false );
		imagesavealpha( $dest, true );
		imagecopyresampled( $dest, $src, 0, 0, 0, 0, $dest_w, $dest_h, $w, $h );

		$txt_color   = imagecolorallocatealpha( $dest, 255, 255, 255, 45 );
		$watermark_x = max( 0, imagesx( $dest ) - 125 - random_int( 3, 8 ) );
		$watermark_y = max( 0, imagesy( $dest ) - 25 - random_int( 3, 8 ) );
		imagestring( $dest, 5, $watermark_x, $watermark_y, 'Iwol Gamseong', $txt_color );

		$temp = wp_tempnam( $url );
		imagewebp( $dest, $temp, $quality );
		imagedestroy( $src );
		imagedestroy( $dest );

		$id = media_handle_sideload(
			array(
				'name'     => $slug . '.webp',
				'tmp_name' => $temp,
			),
			$post_id,
			$alt
		);

		@unlink( $temp );

		if ( is_wp_error( $id ) ) {
			return array( 'success' => false, 'error' => 'Sideload Error' );
		}

		update_post_meta( $id, '_wp_attachment_image_alt', $alt );
		if ( $is_feat ) {
			set_post_thumbnail( $post_id, $id );
		}

		return array( 'success' => true, 'new_url' => wp_get_attachment_url( $id ) );
	}
}

if ( ! function_exists( '_img_seo_finalize_product' ) ) {
	/**
	 * Finalize a product: replace image URLs in content, update ALTs, add JSON-LD.
	 * Returns array with 'success' => bool.
	 */
	function _img_seo_finalize_product( $post_id, $map, $alts ) {
		$content = get_post_field( 'post_content', $post_id );

		foreach ( $map as $old_url => $new_url ) {
			$old_url    = esc_url_raw( $old_url );
			$new_url    = esc_url_raw( $new_url );
			$target_alt = isset( $alts[ $old_url ] ) ? str_replace( ',', '', esc_attr( sanitize_text_field( $alts[ $old_url ] ) ) ) : '';

			if ( '' === $old_url || '' === $new_url ) {
				continue;
			}

			$pattern = '#<img([^>]*?)src=["\']' . preg_quote( $old_url, '#' ) . '["\']([^>]*?)alt=["\'](.*?)["\']([^>]*?)>#i';
			if ( preg_match( $pattern, $content ) ) {
				$content = preg_replace( $pattern, '<img$1src="' . esc_url( $new_url ) . '"$2alt="' . $target_alt . '"$4>', $content );
			} else {
				$content = str_replace( $old_url, $new_url, $content );
				$content = str_replace( 'src="' . $new_url . '"', 'alt="' . $target_alt . '" src="' . $new_url . '"', $content );
				$content = str_replace( "src='" . $new_url . "'", "alt='" . $target_alt . "' src='" . $new_url . "'", $content );
			}
		}

		$content = img_seo_replace_shortcode_image_attrs( $content, $map, $alts, $post_id );

		$ld_objs = array();
		foreach ( $map as $old_url => $new_url ) {
			$old_url = esc_url_raw( $old_url );
			$new_url = esc_url_raw( $new_url );
			if ( '' === $new_url ) {
				continue;
			}
			$ld_objs[] = array(
				'@type'   => 'ImageObject',
				'url'     => $new_url,
				'caption' => isset( $alts[ $old_url ] ) ? str_replace( ',', '', sanitize_text_field( $alts[ $old_url ] ) ) : '',
			);
		}

		$json_ld = '<script type="application/ld+json">' . wp_json_encode(
			array(
				'@context'        => 'https://schema.org',
				'@type'           => 'ImageGallery',
				'name'            => get_the_title( $post_id ),
				'associatedMedia' => $ld_objs,
			),
			JSON_UNESCAPED_UNICODE
		) . '</script>';

		update_post_meta( $post_id, '_img_seo_json_ld', $json_ld );
		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => $content,
			)
		);

		return array( 'success' => true );
	}
}

if ( ! function_exists( 'img_seo_batch_start' ) ) {
	/**
	 * Scan products and populate the background batch queue.
	 * Returns number of enqueued items.
	 */
	function img_seo_batch_start( $keyword = '', $post_types = null ) {
		global $wpdb;

		$post_types   = img_seo_normalize_post_types( $post_types );
		$placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
		$keyword_like = '%' . $wpdb->esc_like( $keyword ) . '%';
		$keyword_sql  = '';
		$keyword_args = array();
		if ( '' !== trim( $keyword ) ) {
			$keyword_sql  = ' AND ( p.post_title LIKE %s OR p.post_content LIKE %s OR p.post_excerpt LIKE %s OR src.meta_value LIKE %s )';
			$keyword_args = array( $keyword_like, $keyword_like, $keyword_like, $keyword_like );
		}

		$sql = $wpdb->prepare(
			"SELECT DISTINCT p.ID, p.post_title, p.post_type, p.post_content, p.post_excerpt
			FROM {$wpdb->posts} p
			LEFT JOIN {$wpdb->postmeta} src ON p.ID = src.post_id
			LEFT JOIN {$wpdb->postmeta} done ON p.ID = done.post_id AND done.meta_key = %s AND done.meta_value != ''
			WHERE p.post_type IN ($placeholders)
			AND p.post_status != %s
			AND done.post_id IS NULL
			{$keyword_sql}
			ORDER BY p.ID DESC",
			array_merge(
				array( '_img_seo_json_ld' ),
				$post_types,
				array( 'trash' ),
				$keyword_args
			)
		);
		$results = $wpdb->get_results( $sql );

		$queue = array();
		foreach ( $results as $p ) {
			$scan_content = img_seo_get_product_scan_text( $p->ID, $p->post_content, $p->post_excerpt );
			$imgs         = img_seo_scan_content_images( $scan_content, '', $p->ID );
			$keyword_trim = trim( $keyword );
			if ( '' !== $keyword_trim && ( false !== strpos( $keyword_trim, '.' ) || false !== strpos( $keyword_trim, '/' ) ) ) {
				$imgs = array_values(
					array_filter(
						$imgs,
						function( $url ) use ( $keyword ) {
							return img_seo_keyword_matches_text( $url, $keyword );
						}
					)
				);
			}
			if ( ! empty( $imgs ) ) {
				$queue[] = array(
					'id'         => (int) $p->ID,
					'title'      => $p->post_title,
					'type'       => $p->post_type,
					'type_label' => img_seo_get_post_type_label( $p->post_type ),
					'images'     => $imgs,
				);
			}
		}

		update_option( '_img_seo_batch_queue', $queue, false );

		// Clear any previous stop flag for fresh start.
		delete_option( '_img_seo_batch_stop' );

		update_option(
			'_img_seo_batch_progress',
			array(
				'total'   => count( $queue ),
				'current' => 0,
				'status'  => 'running',
				'log'     => array( sprintf( '배치 시작: 총 %d개 항목', count( $queue ) ) ),
			),
			false
		);

		return count( $queue );
	}
}

if ( ! function_exists( 'img_seo_batch_tick' ) ) {
	/**
	 * Process next chunk from the batch queue. Hooked to WP-Cron single event.
	 * Respects safe-stop flag: finishes current chunk, then pauses.
	 */
	function img_seo_batch_tick() {
		img_seo_extend_runtime( 300 );

		$queue    = get_option( '_img_seo_batch_queue', array() );
		$progress = get_option( '_img_seo_batch_progress', array( 'total' => 0, 'current' => 0, 'status' => 'running', 'log' => array() ) );

		if ( empty( $queue ) || 'running' !== $progress['status'] ) {
			$progress['status'] = 'done';
			$progress['log'][]  = '배치 완료 (큐 없음)';
			update_option( '_img_seo_batch_progress', $progress, false );
			return;
		}

		// Process up to 3 products per tick.
		$chunk_size   = 3;
		$chunk        = array_splice( $queue, 0, $chunk_size );

		foreach ( $chunk as $item ) {
			$alt_base = mb_substr( $item['title'], 0, 60 );
			$map      = array();
			$alts     = array();
			$has_err  = false;

			foreach ( $item['images'] as $idx => $url ) {
				$slug = sanitize_title( $item['title'] ) . '-' . ( $idx + 1 );
				$alt  = str_replace( ',', '', $alt_base );
				$result = _img_seo_process_single_image( $item['id'], $url, $alt, $slug, 0 === $idx ? 1 : 0 );

				if ( $result['success'] && ! empty( $result['skipped'] ) ) {
					$alts[ $url ] = $alt;
				} elseif ( $result['success'] && ! empty( $result['new_url'] ) ) {
					$map[ $url ]  = $result['new_url'];
					$alts[ $url ] = $alt;
				} else {
					$progress['log'][] = sprintf( '실패: %s #%d 이미지 #%d - %s', $item['type_label'], $item['id'], $idx + 1, $result['error'] ?? '알 수 없음' );
					$has_err = true;
				}
			}

			if ( ! empty( $map ) ) {
				_img_seo_finalize_product( $item['id'], $map, $alts );
			}

			if ( $has_err ) {
				$progress['log'][] = sprintf( '일부 실패: %s #%d', $item['type_label'], $item['id'] );
			} else {
				$progress['log'][] = sprintf( '완료: %s #%d (%d개 이미지)', $item['type_label'], $item['id'], count( $item['images'] ) );
			}

			$progress['current']++;
			update_option( '_img_seo_batch_progress', $progress, false );
		}

		// Save remaining queue.
		update_option( '_img_seo_batch_queue', $queue, false );

		// Check safe-stop flag — finish current chunk, then pause.
		$stop_flag = get_option( '_img_seo_batch_stop', false );

		if ( ! empty( $queue ) && ! $stop_flag ) {
			wp_schedule_single_event( time() + 60, 'img_seo_batch_tick_event' );
		} elseif ( ! empty( $queue ) && $stop_flag ) {
			// Safe stop: current chunk done, remaining queue preserved.
			delete_option( '_img_seo_batch_stop' );
			$progress['status'] = 'paused';
			$progress['log'][]  = sprintf( '안전 중지됨 (%d개 작업 남음)', count( $queue ) );
			update_option( '_img_seo_batch_progress', $progress, false );
		} else {
			$progress['status'] = 'done';
			$progress['log'][]  = '배치 전체 완료!';
			update_option( '_img_seo_batch_progress', $progress, false );
			delete_option( '_img_seo_batch_queue' );
		}
	}
}

add_action( 'img_seo_batch_tick_event', 'img_seo_batch_tick' );

add_action(
	'wp_ajax_img_seo_run_background_batch',
	function() {
		img_seo_verify_ajax();
		img_seo_extend_runtime( 180 );

		// If there's already a paused queue, just resume it.
		$existing_queue = get_option( '_img_seo_batch_queue', array() );
		$progress       = get_option( '_img_seo_batch_progress', array() );
		$is_resume      = ! empty( $existing_queue ) && isset( $progress['status'] ) && 'paused' === $progress['status'];

		if ( $is_resume ) {
			delete_option( '_img_seo_batch_stop' );
			$progress['status'] = 'running';
			$progress['log'][]  = '배치 재개됨';
			update_option( '_img_seo_batch_progress', $progress, false );

			wp_clear_scheduled_hook( 'img_seo_batch_tick_event' );
			wp_schedule_single_event( time() + 10, 'img_seo_batch_tick_event' );

			wp_send_json_success(
				array(
					'count'   => count( $existing_queue ),
					'message' => sprintf( '배치 재개: %d개 항목 남음', count( $existing_queue ) ),
				)
			);
			return;
		}

		$keyword    = sanitize_text_field( wp_unslash( $_POST['keyword'] ?? '' ) );
		$post_types = isset( $_POST['post_types'] ) ? wp_unslash( $_POST['post_types'] ) : null;

		$count = img_seo_batch_start( $keyword, $post_types );

		// Schedule first tick.
		wp_clear_scheduled_hook( 'img_seo_batch_tick_event' );
		if ( $count > 0 ) {
			wp_schedule_single_event( time() + 10, 'img_seo_batch_tick_event' );
		}

		wp_send_json_success(
			array(
				'count'   => $count,
				'message' => sprintf( '백그라운드 배치 시작: %d개 항목', $count ),
			)
		);
	}
);

add_action(
	'wp_ajax_img_seo_batch_progress',
	function() {
		$progress = get_option( '_img_seo_batch_progress', array( 'total' => 0, 'current' => 0, 'status' => 'idle', 'log' => array() ) );
		wp_send_json_success( $progress );
	}
);

add_action(
	'wp_ajax_img_seo_batch_safe_stop',
	function() {
		img_seo_verify_ajax();

		// Set stop flag — tick will finish current chunk, then pause.
		update_option( '_img_seo_batch_stop', true, false );

		// Clear any pending cron events so no new tick starts.
		wp_clear_scheduled_hook( 'img_seo_batch_tick_event' );

		wp_send_json_success( array( 'message' => '안전 중지 요청됨, 현재 작업 완료 후 중지됩니다.' ) );
	}
);

add_action(
	'admin_menu',
	function() {
		add_submenu_page(
			'hotheart-wp-admin-utility',
			'이미지 SEO Pro',
			'이미지 SEO Pro',
			'manage_options',
			'image-seo-tool',
			'img_seo_render_page'
		);
	}
);

add_action( 'admin_enqueue_scripts', 'img_seo_enqueue_assets' );
add_action( 'wp_head', 'img_seo_output_json_ld' );
add_action( 'init', 'img_seo_cron_schedule' );
add_action( 'img_seo_cron_batch_event', 'img_seo_cron_batch_run' );

add_action(
	'wp_ajax_img_seo_save_v1200',
	function() {
		img_seo_verify_ajax();
		img_seo_extend_runtime( 240 );

		$post_id = absint( $_POST['post_id'] ?? 0 );
		$url     = esc_url_raw( wp_unslash( $_POST['img_url'] ?? '' ) );
		$alt     = str_replace( ',', '', sanitize_text_field( wp_unslash( $_POST['alt'] ?? '' ) ) );
		$slug    = sanitize_title( wp_unslash( $_POST['slug'] ?? 'product-img' ) );
		$is_feat = absint( $_POST['is_feat'] ?? 0 );

		$result = _img_seo_process_single_image( $post_id, $url, $alt, $slug, $is_feat );

		if ( $result['success'] ) {
			if ( ! empty( $result['skipped'] ) ) {
				wp_send_json_success( array( 'skipped' => true, 'message' => 'Already completed' ) );
			} else {
				wp_send_json_success( array( 'new_url' => $result['new_url'] ) );
			}
		} else {
			wp_send_json_error( $result['error'] ?? 'Unknown error' );
		}
	}
);

add_action(
	'wp_ajax_img_seo_final_v1200',
	function() {
		img_seo_verify_ajax();

		$post_id = absint( $_POST['post_id'] ?? 0 );
		$map     = isset( $_POST['map'] ) && is_array( $_POST['map'] ) ? wp_unslash( $_POST['map'] ) : array();
		$alts    = isset( $_POST['alts'] ) && is_array( $_POST['alts'] ) ? wp_unslash( $_POST['alts'] ) : array();

		$result = _img_seo_finalize_product( $post_id, $map, $alts );

		if ( $result['success'] ) {
			$delay_time = max( 0, min( 1, (int) get_option( 'img_seo_delay_time', 0 ) ) );
			img_seo_extend_runtime( 180 + $delay_time );
			if ( $delay_time > 0 ) {
				usleep( $delay_time * 1000000 );
			}
			wp_send_json_success();
		} else {
			wp_send_json_error( $result['error'] ?? 'Final save failed' );
		}
	}
);

add_action(
	'wp_ajax_img_seo_scan_v1200',
	function() {
		// Keep the AJAX payload clean so no stray output can break JSON parsing.
		ob_start();
		img_seo_verify_ajax();
		img_seo_extend_runtime( 180 );

		global $wpdb;

		$limit      = max( 1, min( 1000, absint( $_POST['limit'] ?? 100 ) ) );
		$keyword    = sanitize_text_field( wp_unslash( $_POST['keyword'] ?? '' ) );
		$post_types = img_seo_normalize_post_types( isset( $_POST['post_types'] ) ? wp_unslash( $_POST['post_types'] ) : null );
		$placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
		$keyword_like = '%' . $wpdb->esc_like( $keyword ) . '%';
		$keyword_sql  = '';
		$keyword_args = array();
		if ( '' !== trim( $keyword ) ) {
			// Keyword search follows the legacy flow: text-level filter on post content/title/excerpt/meta.
			$keyword_sql  = ' AND ( p.post_title LIKE %s OR p.post_content LIKE %s OR p.post_excerpt LIKE %s OR src.meta_value LIKE %s )';
			$keyword_args = array( $keyword_like, $keyword_like, $keyword_like, $keyword_like );
		}

		$sql = $wpdb->prepare(
			"SELECT DISTINCT p.ID, p.post_title, p.post_type, p.post_content, p.post_excerpt
			FROM {$wpdb->posts} p
			LEFT JOIN {$wpdb->postmeta} src ON p.ID = src.post_id
			LEFT JOIN {$wpdb->postmeta} done ON p.ID = done.post_id AND done.meta_key = %s AND done.meta_value != ''
			WHERE p.post_type IN ($placeholders)
			AND p.post_status != %s
			AND done.post_id IS NULL
			{$keyword_sql}
			ORDER BY p.ID DESC
			LIMIT %d",
			array_merge(
				array( '_img_seo_json_ld' ),
				$post_types,
				array( 'trash' ),
				$keyword_args,
				array( $limit )
			)
		);
		$results = $wpdb->get_results( $sql );
		$found   = array();

		foreach ( $results as $p ) {
			$scan_content = img_seo_get_product_scan_text( $p->ID, $p->post_content, $p->post_excerpt );
			$imgs         = img_seo_scan_content_images( $scan_content, '', $p->ID );
			$keyword_trim = trim( $keyword );
			$is_url_like_keyword = ( false !== strpos( $keyword_trim, '.' ) || false !== strpos( $keyword_trim, '/' ) );
			if ( '' !== $keyword_trim && $is_url_like_keyword ) {
				// URL-like keywords (domain/path tokens) filter image URLs directly.
				$imgs = array_values(
					array_filter(
						$imgs,
						function( $url ) use ( $keyword ) {
							return img_seo_keyword_matches_text( $url, $keyword );
						}
					)
				);
			}
			if ( ! empty( $imgs ) ) {

				$found[] = array(
					'id'            => (int) $p->ID,
					'title'         => $p->post_title,
					'type'          => $p->post_type,
					'type_label'    => img_seo_get_post_type_label( $p->post_type ),
					'link'          => get_permalink( $p->ID ),
					'images'        => $imgs,
					'count'         => count( $imgs ),
					'matched_count' => count( $imgs ),
				);
			}
		}

		while ( ob_get_level() ) {
			ob_end_clean();
		}

		wp_send_json_success(
			array(
				'items' => $found,
				'meta'  => array(
					'candidate_count' => count( $results ),
					'matched_count'   => count( $found ),
				),
			)
		);
	}
);

add_action(
	'wp_ajax_img_seo_save_settings',
	function() {
		img_seo_verify_ajax();
		img_seo_extend_runtime( 90 );

		$delay_time      = max( 1, min( 99999999, absint( $_POST['delay_time'] ?? 5 ) ) );
		$keyword         = sanitize_text_field( wp_unslash( $_POST['keyword'] ?? 'media-amazon.com' ) );
		$adaptive_status = sanitize_key( wp_unslash( $_POST['adaptive_status'] ?? 'on' ) );
		$cpu_threshold   = max( 1, min( 99, absint( $_POST['cpu_threshold'] ?? 70 ) ) );
		$mem_threshold   = max( 1, min( 99, absint( $_POST['mem_threshold'] ?? 70 ) ) );
		$post_types      = img_seo_normalize_post_types( isset( $_POST['post_types'] ) ? wp_unslash( $_POST['post_types'] ) : null );
		$cron_enabled    = 'on' === sanitize_key( wp_unslash( $_POST['cron_enabled'] ?? 'off' ) ) ? 'on' : 'off';

		update_option( 'img_seo_delay_time', $delay_time );
		update_option( 'img_seo_keyword', $keyword );
		update_option( 'img_seo_adaptive_status', in_array( $adaptive_status, array( 'on', 'off' ), true ) ? $adaptive_status : 'on' );
		update_option( 'img_seo_cpu_threshold', $cpu_threshold );
		update_option( 'img_seo_mem_threshold', $mem_threshold );
		update_option( 'img_seo_post_types', $post_types );
		update_option( 'img_seo_cron_enabled', $cron_enabled );

		if ( 'on' === $cron_enabled ) {
			img_seo_cron_schedule();
			img_seo_append_log( 'WP-Cron 자동실행 ON' );
		} else {
			img_seo_cron_unschedule();
			img_seo_append_log( 'WP-Cron 자동실행 OFF' );
		}

		wp_send_json_success();
	}
);

add_action(
	'wp_ajax_img_seo_get_live_delay',
	function() {
		img_seo_verify_ajax();
		img_seo_extend_runtime( 30 );
		wp_send_json_success( array( 'delay' => (int) get_option( 'img_seo_delay_time', 5 ) ) );
	}
);

add_action(
	'wp_ajax_img_seo_get_server_usage',
	function() {
		img_seo_verify_ajax();
		img_seo_extend_runtime( 30 );
		wp_send_json_success( img_seo_read_server_usage() );
	}
);

add_action(
	'wp_ajax_img_seo_run_cron_now',
	function() {
		img_seo_verify_ajax();
		img_seo_extend_runtime( 300 );
		img_seo_cron_batch_run( true );
		wp_send_json_success(
			array(
				'message' => 'WP-Cron 즉시 실행 완료',
			)
		);
	}
);

add_action(
	'wp_ajax_img_seo_scan_broken_v1200',
	function() {
		img_seo_verify_ajax();
		img_seo_extend_runtime( 180 );

		global $wpdb;

		$keyword = sanitize_text_field( wp_unslash( $_POST['keyword'] ?? '' ) );
		$limit   = max( 1, min( 1000, absint( $_POST['limit'] ?? 100 ) ) );
		$post_types = img_seo_normalize_post_types( isset( $_POST['post_types'] ) ? wp_unslash( $_POST['post_types'] ) : null );
		$placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
		$keyword_sql  = '';
		$keyword_args = array();
		if ( '' !== trim( $keyword ) ) {
			$keyword_like = '%' . $wpdb->esc_like( $keyword ) . '%';
			// Broken-image scan should honor the same keyword filter as deep scan.
			$keyword_sql  = ' AND ( post_title LIKE %s OR post_content LIKE %s OR post_excerpt LIKE %s )';
			$keyword_args = array( $keyword_like, $keyword_like, $keyword_like );
		}
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_title, post_type, post_content, post_excerpt FROM {$wpdb->posts} WHERE post_type IN ($placeholders) AND post_status != %s{$keyword_sql} ORDER BY ID DESC LIMIT %d",
				array_merge( $post_types, array( 'trash' ), $keyword_args, array( $limit ) )
			)
		);
		$found = array();

		foreach ( $results as $p ) {
			$is_broken   = false;
			$imgs         = array();
			$matches      = array();
			$thumb_id     = get_post_meta( $p->ID, '_thumbnail_id', true );
			$scan_content = (string) $p->post_content . "\n" . (string) $p->post_excerpt;

			if ( empty( $thumb_id ) ) {
				$is_broken = true;
			} else {
				$thumb_exists = $wpdb->get_var(
					$wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE ID = %d AND post_type = %s", $thumb_id, 'attachment' )
				);
				if ( ! $thumb_exists ) {
					$is_broken = true;
				}
			}

			if ( preg_match_all( '#https?://[^\s"\'<>]+?\.(?:jpg|jpeg|png|webp|gif)(?:[^\s"\'<>]*)?#i', $scan_content, $matches ) ) {
				foreach ( $matches[0] as $url ) {
					$url = trim( $url );
					if ( '' !== $keyword && img_seo_keyword_matches_text( $url, $keyword ) ) {
						$is_broken = true;
						$imgs[]    = esc_url_raw( $url );
					} elseif ( false !== stripos( $url, 'wp-content/uploads' ) ) {
						$filename     = basename( wp_parse_url( $url, PHP_URL_PATH ) );
						$media_exists = $wpdb->get_var(
							$wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE guid LIKE %s AND post_type = %s", '%' . $wpdb->esc_like( $filename ) . '%', 'attachment' )
						);
						if ( ! $media_exists ) {
							$is_broken = true;
							$imgs[]    = esc_url_raw( $url );
						}
					}
				}
			}

			if ( $is_broken ) {
				if ( empty( $imgs ) && ! empty( $matches[0] ) ) {
					$imgs = array_map( 'esc_url_raw', array_map( 'trim', $matches[0] ) );
				}

				$imgs = array_merge( $imgs, img_seo_get_product_all_images( $p->ID, $scan_content ) );
				$imgs = array_values( array_unique( array_filter( $imgs ) ) );
				if ( ! empty( $imgs ) ) {
					$found[] = array(
						'id'          => (int) $p->ID,
						'title'       => '[검증필요] ' . $p->post_title,
						'type'        => $p->post_type,
						'type_label'  => img_seo_get_post_type_label( $p->post_type ),
						'link'        => get_permalink( $p->ID ),
						'images'      => $imgs,
						'count'       => count( $imgs ),
					);
				}
			}
		}

		wp_send_json_success( $found );
	}
);

if ( ! function_exists( 'check_my_server_status' ) ) {
	function check_my_server_status() {
		if ( 'off' === get_option( 'img_seo_adaptive_status', 'on' ) ) {
			return;
		}

		$stats             = img_seo_read_server_usage();
		$cpu               = (int) ( $stats['cpu'] ?? 0 );
		$mem               = (int) ( $stats['mem'] ?? 0 );
		$cpu_limit_percent = (int) get_option( 'img_seo_cpu_threshold', 70 );
		$mem_limit_percent = (int) get_option( 'img_seo_mem_threshold', 70 );

		if ( $cpu > $cpu_limit_percent || $mem > $mem_limit_percent ) {
			$current_delay  = (int) get_option( 'img_seo_delay_time', 5 );
			$increased_delay = min( 120, max( $current_delay * 2, $current_delay + 10 ) );
			update_option( 'img_seo_delay_time', $increased_delay );
			return;
		}

		$current_delay = (int) get_option( 'img_seo_delay_time', 5 );
		if ( $current_delay > 15 ) {
			update_option( 'img_seo_delay_time', 5 );
		}
	}
}

add_action( 'admin_init', 'check_my_server_status', 20 );
