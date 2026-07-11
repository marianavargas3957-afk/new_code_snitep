<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap img-seo-wrap">
	<?php
	// Fallback: load CSS directly if wp_enqueue_style failed
	if ( ! wp_style_is( 'img-seo-pro-style', 'done' ) && ! wp_style_is( 'img-seo-pro-style', 'enqueued' ) ) {
		printf( '<link rel="stylesheet" href="%s?v=%s">', esc_url( IMG_SEO_PRO_URL . '/style.css' ), esc_attr( IMG_SEO_PRO_VERSION ) );
	}
	// Fallback: ensure imgSeoPro config is available for script.js
	printf( '<script>if(!window.imgSeoPro){window.imgSeoPro=%s;}</script>', wp_json_encode( array( 'ajaxUrl' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( 'img_seo_pro_nonce' ) ) ) );
	// Fallback: load script.js directly if wp_enqueue_script failed
	if ( ! wp_script_is( 'img-seo-pro-script', 'enqueued' ) && ! wp_script_is( 'img-seo-pro-script', 'done' ) ) {
		printf( '<script src="%s?v=%s"></script>', esc_url( IMG_SEO_PRO_URL . '/script.js' ), esc_attr( IMG_SEO_PRO_VERSION ) );
	}
	?>
	<h1>이미지 SEO Pro <span>v<?php echo esc_html( IMG_SEO_PRO_VERSION ); ?> (Custom Adaptive Batch)</span></h1>

	<div class="img-seo-summary">
		<?php foreach ( $summary_counts as $summary ) : ?>
			<div class="img-seo-summary-row">
				<strong><?php echo esc_html( $summary['label'] ); ?></strong>
				<span>전체 <?php echo number_format_i18n( $summary['total'] ); ?></span>
				<span>발행 <?php echo number_format_i18n( $summary['publish'] ); ?></span>
				<span>초안 <?php echo number_format_i18n( $summary['draft'] ); ?></span>
				<span>작업 완료 <?php echo number_format_i18n( $summary['completed'] ); ?></span>
				<span>작업 미완료 <?php echo number_format_i18n( $summary['incomplete'] ); ?></span>
			</div>
		<?php endforeach; ?>
	</div>

	<div class="img-seo-top">
		<div class="img-seo-panel">
			<div id="progress_bar_container"><div id="progress_bar"></div></div>
			<div id="bg_progress_bar_container" style="display:none;margin-bottom:10px;"><div id="bg_progress_bar" style="width:0%;height:24px;background:#2271b1;border-radius:4px;text-align:center;line-height:24px;color:#fff;font-size:12px;transition:width 0.5s;"></div></div>
			<button id="start_batch_all" class="button button-hero button-primary img-seo-run-all">검색된 모든 상품(전체) 순차 실행</button>
			<button id="start_bg_batch" class="button button-hero button-secondary" style="margin-left:8px;">백그라운드 전체 실행</button>
			<button id="safe_stop_batch" class="button button-hero" style="margin-left:4px;display:none;background:#d63638;border-color:#d63638;color:#fff;">안전 중지</button>
			<p>* 순차 실행은 브라우저를 켜둔 상태에서 진행됩니다. 백그라운드 실행은 브라우저를 닫아도 서버에서 계속 처리됩니다.</p>
		</div>
		<div id="log_area" class="log-box">&gt; 키워드를 입력하고 상품을 스캔하세요.</div>
	</div>

	<div class="img-seo-settings">
		<div>
			<label>키워드</label>
			<input type="text" id="search_keyword" value="<?php echo esc_attr( $saved_keyword ); ?>">
			<label>검색제한</label>
			<!-- Default scan limit is intentionally lower to keep deep scans lighter. -->
			<input type="number" id="limit_count" value="100">
			<button id="scan_images" class="button button-primary img-seo-scan-main">전체스캔</button>
			<button id="scan_broken_images" class="button button-broken">외부 이미지/썸네일 점검</button>
		</div>
		<div class="img-seo-controls">
			<div class="img-seo-post-types">
				<label><input type="checkbox" class="img-seo-post-type" value="product" <?php checked( in_array( 'product', $saved_post_types, true ) ); ?>> 상품</label>
				<label><input type="checkbox" class="img-seo-post-type" value="post" <?php checked( in_array( 'post', $saved_post_types, true ) ); ?>> 글</label>
				<label><input type="checkbox" id="cron_enabled" value="on" <?php checked( $cron_enabled, 'on' ); ?>> WP-Cron</label>
			</div>
			<div class="img-seo-cron-row">
				<span>WP-Cron 실행링크:</span>
				<a id="cron_trigger_link" href="<?php echo esc_url( $cron_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $cron_url ); ?></a>
				<button id="run_cron_now" class="button">즉시 실행</button>
			</div>
			<div>
				<label>휴식 시간</label>
				<input type="number" id="delay_time" value="<?php echo esc_attr( $saved_delay ); ?>" min="1" max="99999999"> 초
			</div>
			<div>
				<label>부하 감지 연장</label>
				<select id="adaptive_status">
					<option value="on" <?php selected( $adaptive_status, 'on' ); ?>>사용함 (ON)</option>
					<option value="off" <?php selected( $adaptive_status, 'off' ); ?>>사용안함 (OFF)</option>
				</select>
			</div>
			<div class="img-seo-thresholds">
				<label>CPU</label>
				<input type="number" id="cpu_threshold" value="<?php echo esc_attr( $saved_cpu_threshold ); ?>" min="1" max="99"> %
				<label>메모리</label>
				<input type="number" id="mem_threshold" value="<?php echo esc_attr( $saved_mem_threshold ); ?>" min="1" max="99"> %
				<span id="live_server_usage" class="server-live-badge">현재: 연결중...</span>
			</div>
			<button id="save_delay_setting" class="button">설정 저장</button>
		</div>
	</div>

	<div id="pagination_top" class="pagination"></div>
	<div id="product_container"></div>
	<div id="pagination_bottom" class="pagination"></div>

	<div class="img-seo-cron-log">
		<h3>예약 실행 로그</h3>
		<div class="img-seo-cron-log-box">
			<?php
			$cron_logs = get_option( 'img_seo_cron_logs', array() );
			if ( empty( $cron_logs ) || ! is_array( $cron_logs ) ) :
				?>
				<div>로그 없음</div>
				<?php
			else :
				foreach ( $cron_logs as $cron_log ) :
					?>
					<div><?php echo esc_html( $cron_log ); ?></div>
					<?php
				endforeach;
			endif;
			?>
		</div>
	</div>
</div>
