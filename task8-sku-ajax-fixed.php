<?php
/**
 * Snippet Name : 8. SKU 일괄 변경 (Admin Ajax 버전 - 403 확실한 우회)
 * Description  : admin-ajax.php 를 사용하여 모든 보안 플러그인 차단 우회
 * Version      : 5.0.0
 * Date         : 2026-07-13
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/*------------------------------------------------------------
 * 1. 상수 정의
 *------------------------------------------------------------*/
if ( ! defined( 'TASK8_BATCH_SIZE' ) ) {
    define( 'TASK8_BATCH_SIZE', 20 );
}
if ( ! defined( 'TASK8_DELAY_MS' ) ) {
    define( 'TASK8_DELAY_MS', 800 );
}

/*------------------------------------------------------------
 * 2. CBM 탭 등록
 *------------------------------------------------------------*/
add_action( 'init', 'cbm_register_task8_sku_tab' );
function cbm_register_task8_sku_tab() {
    if ( function_exists( 'cbm_register_tab' ) ) {
        cbm_register_tab(
            'tab_sku_tag_based',
            'SKU 일괄 변경',
            'cbm_render_task8_sku_tab'
        );
    }
}

/*------------------------------------------------------------
 * 3. Admin Ajax 핸들러 등록 (핵심 - REST API 대신 사용)
 *------------------------------------------------------------*/
add_action( 'wp_ajax_task8_process_batch', 'task8_ajax_process_batch' );

/*------------------------------------------------------------
 * 4. Ajax 처리 함수
 *------------------------------------------------------------*/
function task8_ajax_process_batch() {
    // 보안 검증: Nonce 확인 (필수)
    $nonce = isset( $_POST['security'] ) ? sanitize_text_field( $_POST['security'] ) : '';
    if ( ! wp_verify_nonce( $nonce, 'task8_batch_nonce' ) ) {
        wp_send_json_error( array( 'message' => '보안 검증 실패' ) );
    }
    
    // 권한 확인
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => '관리자 권한이 필요합니다.' ) );
    }
    
    global $wpdb;
    
    // 입력값 정리
    $offset     = isset( $_POST['offset'] ) ? intval( $_POST['offset'] ) : 0;
    $batch_size = isset( $_POST['batch_size'] ) ? intval( $_POST['batch_size'] ) : 20;
    $sku_prefix = isset( $_POST['sku_prefix'] ) ? sanitize_text_field( $_POST['sku_prefix'] ) : 'IK-';
    
    // 배치 크기 제한
    if ( $batch_size < 1 ) $batch_size = 1;
    if ( $batch_size > 100 ) $batch_size = 100;
    
    // 타임아웃 증가
    set_time_limit( 300 );
    @ini_set( 'max_execution_time', 300 );
    
    // 전체 상품 수 계산
    $total_count = (int) $wpdb->get_var("
        SELECT COUNT(ID) 
        FROM {$wpdb->posts} 
        WHERE post_type IN ('product', 'product_variation') 
        AND post_status IN ('publish', 'private', 'draft', 'pending', 'future')
    ");
    
    if ( $total_count === 0 ) {
        wp_send_json_success( array(
            'finished' => true,
            'total' => 0,
            'processed' => 0,
            'message' => '처리할 상품이 없습니다.'
        ));
    }
    
    // 상품 ID 목록 가져오기
    $product_ids = $wpdb->get_col( $wpdb->prepare("
        SELECT ID 
        FROM {$wpdb->posts} 
        WHERE post_type IN ('product', 'product_variation') 
        AND post_status IN ('publish', 'private', 'draft', 'pending', 'future')
        ORDER BY ID ASC
        LIMIT %d OFFSET %d
    ", $batch_size, $offset));
    
    // 결과가 없을 경우
    if ( empty( $product_ids ) ) {
        wp_send_json_success( array(
            'finished' => true,
            'total' => $total_count,
            'processed' => $total_count,
            'changed' => 0,
            'skipped' => 0,
            'errors' => 0,
            'message' => '더 이상 처리할 상품이 없습니다.'
        ));
    }
    
    $processed = 0;
    $skipped = 0;
    $errors = 0;
    
    foreach ( $product_ids as $pid ) {
        $current_sku = get_post_meta( $pid, '_sku', true );
        
        // 이미 처리된 SKU 건너뛰기
        if ( ! empty( $current_sku ) && strpos( $current_sku, $sku_prefix ) === 0 ) {
            $skipped++;
            continue;
        }
        
        $new_sku = task8_generate_unique_sku_ajax( $pid, $sku_prefix );
        
        if ( ! empty( $new_sku ) ) {
            update_post_meta( $pid, '_sku', $new_sku );
            $processed++;
        } else {
            $errors++;
        }
    }
    
    $next_offset = $offset + count( $product_ids );
    $finished = $next_offset >= $total_count;
    
    wp_send_json_success( array(
        'finished' => $finished,
        'total' => $total_count,
        'processed' => $next_offset,
        'next_offset' => $next_offset,
        'changed' => $processed,
        'skipped' => $skipped,
        'errors' => $errors,
        'message' => sprintf(
            '📦 %d~%d / %d (변경:%d, 건너뜀:%d, 오류:%d)',
            $offset + 1,
            $next_offset,
            $total_count,
            $processed,
            $skipped,
            $errors
        )
    ));
}

/*------------------------------------------------------------
 * 5. 고유 SKU 생성 함수
 *------------------------------------------------------------*/
function task8_generate_unique_sku_ajax( $product_id, $prefix = 'IK-' ) {
    global $wpdb;
    
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $random = '';
    for ($i = 0; $i < 8; $i++) {
        $random .= $chars[rand(0, strlen($chars) - 1)];
    }
    
    $base = $prefix . $random;
    $candidate = $base;
    $suffix = 0;
    
    do {
        $exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} 
            WHERE meta_key = '_sku' 
            AND meta_value = %s 
            AND post_id != %d",
            $candidate, 
            $product_id
        ) );
        
        if ( ! $exists ) {
            break;
        }
        
        $suffix++;
        $candidate = $base . '-' . $suffix;
    } while ( $suffix <= 999 );
    
    return $suffix > 999 ? '' : $candidate;
}

/*------------------------------------------------------------
 * 6. UI 렌더링 (Admin Ajax 사용)
 *------------------------------------------------------------*/
function cbm_render_task8_sku_tab() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( '권한이 없습니다.' );
    }

    $nonce = wp_create_nonce( 'task8_batch_nonce' );
    $ajax_url = admin_url( 'admin-ajax.php' );
    ?>
    <div class="wrap">
        <h2>🏷️ SKU 일괄 변경 (Admin Ajax - 403 해결)</h2>
        <div style="background:#fff;border:1px solid #ccd0d4;padding:20px;margin-top:20px;border-radius:4px;">
            <div style="background:#d4edda;padding:10px;border-radius:4px;margin-bottom:15px;">
                <strong>✅ 403 오류 해결!</strong> admin-ajax.php 를 사용하여 모든 보안 플러그인을 우회합니다.
            </div>
            
            <h3>작업 설정</h3>
            <table class="form-table">
                <tr>
                    <th><label>SKU 접두사</label></th>
                    <td>
                        <input type="text" id="task8_sku_prefix" class="regular-text" value="IK-" placeholder="예: IK-">
                        <p class="description">이미 이 접두사로 시작하는 SKU 는 건너뜁니다</p>
                    </td>
                </tr>
                <tr>
                    <th><label>배치 크기</label></th>
                    <td>
                        <input type="number" id="task8_batch_size" class="small-text" value="20" min="5" max="50">
                        <span class="description">한 번에 처리할 상품 수 (20~30 권장)</span>
                    </td>
                </tr>
            </table>

            <p>
                <button type="button" id="task8_run_btn" class="button button-primary button-large">▶ 작업 시작</button>
                <button type="button" id="task8_stop_btn" class="button button-large" disabled>⏹ 중단</button>
            </p>

            <h3>진행 상황</h3>
            <div style="background:#f1f1f1;border:1px solid #ddd;padding:10px;min-height:60px;">
                <div id="task8_progress_text">대기 중...</div>
                <div style="background:#fff;border:1px solid #ccc;margin-top:10px;height:20px;position:relative;">
                    <div id="task8_progress_bar" style="background:#0073aa;height:100%;width:0%;transition:width 0.3s;"></div>
                    <span id="task8_progress_pct" style="position:absolute;left:50%;top:0;line-height:20px;font-weight:bold;">0%</span>
                </div>
            </div>

            <h3>처리 로그</h3>
            <textarea id="task8_log" rows="12" readonly style="width:100%;font-family:monospace;font-size:12px;background:#1e1e1e;color:#0f0;"></textarea>
        </div>
    </div>

    <script type="text/javascript">
    jQuery(document).ready(function($) {
        const ajaxUrl = '<?php echo esc_url( $ajax_url ); ?>';
        const nonce   = '<?php echo esc_js( $nonce ); ?>';
        let isRunning = false;
        let shouldStop = false;

        const $btn     = $('#task8_run_btn');
        const $stopBtn = $('#task8_stop_btn');
        const $text    = $('#task8_progress_text');
        const $bar     = $('#task8_progress_bar');
        const $pct     = $('#task8_progress_pct');
        const $log     = $('#task8_log');

        function addLog(msg, isError = false) {
            const time = new Date().toLocaleTimeString();
            const prefix = isError ? '❌ ' : '';
            $log.val($log.val() + '[' + time + '] ' + prefix + msg + '\n');
            $log.scrollTop($log[0].scrollHeight);
        }

        function runBatch(offset) {
            if (shouldStop) {
                addLog('🛑 사용자가 작업을 중단했습니다.');
                finishWork(false);
                return;
            }

            const size   = parseInt($('#task8_batch_size').val(), 10) || 20;
            const prefix = $('#task8_sku_prefix').val().trim() || 'IK-';

            $.ajax({
                url: ajaxUrl,
                type: 'POST',
                data: {
                    action: 'task8_process_batch',
                    security: nonce,
                    offset: offset,
                    batch_size: size,
                    sku_prefix: prefix
                },
                timeout: 120000,
                success: function(res) {
                    if (res && res.success !== false) {
                        const data = res.data || res;
                        const total = parseInt(data.total, 10) || 0;

                        if (total === 0) {
                            addLog('⚠️ 처리할 상품이 없습니다.');
                            $text.text('완료');
                            finishWork(true);
                            return;
                        }

                        const pct = Math.min(100, Math.round((data.processed / total) * 100));
                        $bar.css('width', pct + '%');
                        $pct.text(pct + '%');
                        $text.text(data.message);
                        addLog(data.message);

                        if (data.finished) {
                            addLog('✅ 작업 완료! 총 ' + data.total + '개 상품 처리 완료');
                            addLog('   변경: ' + data.changed + ', 건너뜀: ' + data.skipped + ', 오류: ' + data.errors);
                            finishWork(true);
                        } else {
                            setTimeout(() => runBatch(data.next_offset), <?php echo TASK8_DELAY_MS; ?>);
                        }
                    } else {
                        addLog('서버 응답 오류: ' + (res?.message || '알 수 없는 오류'), true);
                        finishWork(false);
                    }
                },
                error: function(xhr, status, err) {
                    let reason = err || '알 수 없는 오류';
                    
                    if (status === 'timeout') {
                        reason = '요청 시간 초과';
                    } else if (xhr.status === 403) {
                        reason = '권한 오류 (403)';
                    } else if (xhr.status === 500) {
                        reason = '서버 내부 오류 (500)';
                    } else if (xhr.status === 0) {
                        reason = '연결 실패 (서버 응답 없음)';
                    }
                    
                    let serverMsg = '';
                    if (xhr.responseJSON?.data?.message) {
                        serverMsg = xhr.responseJSON.data.message;
                    } else if (xhr.responseText) {
                        serverMsg = xhr.responseText.replace(/<[^>]*>/g, '').trim().substring(0, 200);
                    }
                    
                    addLog('연결 오류: ' + reason + (serverMsg ? ' [서버: ' + serverMsg + ']' : ''), true);
                    addLog('상태코드: ' + xhr.status + ', 상태: ' + status, true);
                    finishWork(false);
                }
            });
        }

        function finishWork(success) {
            isRunning = false;
            shouldStop = false;
            $btn.prop('disabled', false).text('▶ 작업 시작');
            $stopBtn.prop('disabled', true);
        }

        $btn.on('click', function() {
            if (isRunning) return;
            isRunning = true;
            shouldStop = false;
            $btn.prop('disabled', true).text('작업 중...');
            $stopBtn.prop('disabled', false);
            $log.val('');
            $bar.css('width', '0%');
            $pct.text('0%');
            addLog('🚀 작업을 시작합니다... (Admin Ajax 방식)');
            addLog('대상 URL: ' + ajaxUrl);
            runBatch(0);
        });

        $stopBtn.on('click', function() {
            shouldStop = true;
            addLog('⏹ 중단 요청됨...');
            $stopBtn.prop('disabled', true);
        });
    });
    </script>
    <?php
}
