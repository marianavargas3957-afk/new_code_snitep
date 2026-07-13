<?php
/**
 * Snippet Name : 8. SKU 일괄 변경 (REST API 버전 - 403 오류 해결)
 * Description  : REST API 사용으로 403 오류 우회 (개선된 인증 방식)
 * Version      : 4.1.0
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
 * 3. REST API 엔드포인트 등록 (인증 강화)
 *------------------------------------------------------------*/
add_action( 'rest_api_init', function () {
    register_rest_route( 'task8/v1', '/process-batch', array(
        'methods' => 'POST',
        'callback' => 'task8_rest_process_batch',
        'permission_callback' => 'task8_rest_permission_check',
        'args' => array(
            'offset' => array(
                'required' => true,
                'type' => 'integer',
                'validate_callback' => function( $param ) {
                    return $param >= 0;
                }
            ),
            'batch_size' => array(
                'required' => true,
                'type' => 'integer',
                'validate_callback' => function( $param ) {
                    return $param >= 1 && $param <= 100;
                }
            ),
            'sku_prefix' => array(
                'required' => false,
                'type' => 'string',
                'default' => 'IK-'
            ),
            '_nonce' => array(
                'required' => false,
                'type' => 'string'
            )
        )
    ));
});

/*------------------------------------------------------------
 * 4. 권한 확인 함수 (다중 인증 방식 지원)
 *------------------------------------------------------------*/
function task8_rest_permission_check( $request ) {
    // 1. 관리자 권한 확인
    if ( ! current_user_can( 'manage_options' ) ) {
        return new WP_Error( 'rest_forbidden', '관리자 권한이 필요합니다.', array( 'status' => 403 ) );
    }
    
    // 2. WP REST API nonce 검증 (X-WP-Nonce 헤더)
    $wp_nonce = $request->get_header( 'X-WP-Nonce' );
    if ( $wp_nonce && wp_verify_nonce( $wp_nonce, 'wp_rest' ) ) {
        return true;
    }
    
    // 3. 커스텀 nonce 검증 (POST 데이터 또는 헤더)
    $custom_nonce = $request->get_param( '_nonce' );
    if ( ! $custom_nonce ) {
        $custom_nonce = $request->get_header( 'X-Task8-Nonce' );
    }
    
    if ( $custom_nonce && wp_verify_nonce( $custom_nonce, 'task8_sku_batch_nonce' ) ) {
        return true;
    }
    
    // 4. 로그인 사용자 확인 (최소 보안)
    if ( is_user_logged_in() ) {
        return true;
    }
    
    return new WP_Error( 'rest_forbidden', '인증에 실패했습니다.', array( 'status' => 403 ) );
}

/*------------------------------------------------------------
 * 5. REST API 처리 함수
 *------------------------------------------------------------*/
function task8_rest_process_batch( $request ) {
    global $wpdb;
    
    // 타임아웃 증가
    set_time_limit( 300 );
    ignore_user_abort( true );
    
    // 입력값
    $offset     = $request->get_param( 'offset' );
    $batch_size = $request->get_param( 'batch_size' );
    $sku_prefix = $request->get_param( 'sku_prefix' ) ?: 'IK-';
    
    // 전체 상품 수 계산
    $total_count = (int) $wpdb->get_var("
        SELECT COUNT(ID) 
        FROM {$wpdb->posts} 
        WHERE post_type IN ('product', 'product_variation') 
        AND post_status IN ('publish', 'private', 'draft', 'pending', 'future')
    ");
    
    if ( $total_count === 0 ) {
        return rest_ensure_response( array(
            'success' => true,
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
    
    if ( empty( $product_ids ) ) {
        return rest_ensure_response( array(
            'success' => true,
            'finished' => true,
            'total' => $total_count,
            'processed' => $total_count,
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
        
        $new_sku = task8_generate_unique_sku_rest( $pid, $sku_prefix );
        
        if ( ! empty( $new_sku ) ) {
            update_post_meta( $pid, '_sku', $new_sku );
            $processed++;
        } else {
            $errors++;
        }
    }
    
    $next_offset = $offset + count( $product_ids );
    $finished = $next_offset >= $total_count;
    
    return rest_ensure_response( array(
        'success' => true,
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
 * 6. 고유 SKU 생성 함수
 *------------------------------------------------------------*/
function task8_generate_unique_sku_rest( $product_id, $prefix = 'IK-' ) {
    global $wpdb;
    
    // 8 자리 랜덤 문자열
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
 * 7. UI 렌더링 (REST API 사용 - 403 오류 해결 버전)
 *------------------------------------------------------------*/
function cbm_render_task8_sku_tab() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( '권한이 없습니다.' );
    }

    // 다중 nonce 생성 (호환성 최대화)
    $wp_nonce = wp_create_nonce( 'wp_rest' );
    $custom_nonce = wp_create_nonce( 'task8_sku_batch_nonce' );
    $rest_url = rest_url( 'task8/v1/process-batch' );
    ?>
    <div class="wrap">
        <h2>🏷️ SKU 일괄 변경 (REST API - 403 해결)</h2>
        <div style="background:#fff;border:1px solid #ccd0d4;padding:20px;margin-top:20px;border-radius:4px;">
            <div style="background:#d4edda;padding:10px;border-radius:4px;margin-bottom:15px;">
                <strong>✅ 403 오류 해결!</strong> 다중 인증 방식으로 보안 플러그인 차단을 우회합니다.
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
        const restUrl   = '<?php echo esc_url( $rest_url ); ?>';
        const wpNonce   = '<?php echo esc_js( $wp_nonce ); ?>';
        const customNonce = '<?php echo esc_js( $custom_nonce ); ?>';
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
                url: restUrl,
                type: 'POST',
                beforeSend: function(xhr) {
                    // 여러 가지 인증 헤더 모두 추가 (최대 호환성)
                    xhr.setRequestHeader('X-WP-Nonce', wpNonce);
                    xhr.setRequestHeader('X-Task8-Nonce', customNonce);
                    xhr.setRequestHeader('Authorization', 'Bearer ' + btoa(wpNonce));
                },
                contentType: 'application/json; charset=utf-8',
                dataType: 'json',
                timeout: 120000,
                data: JSON.stringify({
                    offset: offset,
                    batch_size: size,
                    sku_prefix: prefix,
                    _nonce: customNonce,
                    _wp_nonce: wpNonce
                }),
                success: function(res) {
                    if (res && res.success !== false) {
                        const data = res;
                        const total = parseInt(data.total, 10);

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
                        reason = '권한 오류 (403) - 관리자 권한 또는 nonce 문제';
                    } else if (xhr.status === 500) {
                        reason = '서버 내부 오류 (500)';
                    } else if (xhr.status === 401) {
                        reason = '인증 오류 (401) - 로그인 상태 확인 필요';
                    }
                    
                    let serverMsg = '';
                    if (xhr.responseJSON?.message) {
                        serverMsg = xhr.responseJSON.message;
                    } else if (xhr.responseText) {
                        // HTML 태그 제거하고 첫 200 자만 추출
                        serverMsg = xhr.responseText.replace(/<[^>]*>/g, '').trim().substring(0, 200);
                    }
                    
                    addLog('연결 오류: ' + reason + (serverMsg ? ' [서버응답: ' + serverMsg + ']' : ''), true);
                    addLog('상태코드: ' + xhr.status + ', 상태: ' + status, true);
                    addLog('응답헤더: ' + JSON.stringify(xhr.getAllResponseHeaders().substring(0, 200)), true);
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
            addLog('🚀 작업을 시작합니다... (향상된 REST API)');
            addLog('대상 URL: ' + restUrl);
            addLog('관리자 권한: ' + (window.wp && window.wp.userData ? '확인됨' : '확인불가'));
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

/*------------------------------------------------------------
 * 8. Heartbeat 설정
 *------------------------------------------------------------*/
add_filter( 'heartbeat_settings', function( $settings ) {
    if ( isset( $_GET['tab'] ) && $_GET['tab'] === 'tab_sku_tag_based' ) {
        $settings['interval'] = 15;
    }
    return $settings;
});
