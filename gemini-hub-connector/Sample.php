<?php
/**
 * Plugin Name: 제미나이 AI 허브 커넥터 (Gemini AI Hub Connector)
 * Description: 다중 API 키 로테이션 (5 개), 무료 티어 한도 관리 (API 별 개별 모니터링), 동적 모델 선택, 내부 앱 통신 브리지.
 * Version: 3.3.1
 * Author: CEO Lee Wol Gam Seong
 *
 * [v3.3.1 변경 사항]
 * - 🛡️ Free Tier Limit Manager: 각 API 키별 RPM/TPM/RPD 사용량을 개별 게이지와 상태로 시각화
 * - 🔌 Hub Bridge Info: generate() 응답의 feedback_type, feedback_msg 변수를 Output 에 실시간 표시
 * - UI 개선: 모델 목록 창 세로 길이 확장, 불필요한 층수 표시 및 저장 메시지 제거
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( defined( 'GEMINI_HUB_CONNECTOR_LOADED' ) ) { return; }
define( 'GEMINI_HUB_CONNECTOR_LOADED', true );
@set_time_limit( 180 );
global $gemini_hub_bridge;

// ============================================================
// [암호화 함수]
// ============================================================
if ( ! function_exists( 'wp_custom_encrypt' ) ) {
    function wp_custom_encrypt( $data ) {
        if ( empty( $data ) || ! defined( 'AUTH_KEY' ) ) return $data;
        $key = AUTH_KEY;
        $cipher = 'aes-256-cbc';
        if ( ! in_array( $cipher, openssl_get_cipher_methods() ) ) return $data;
        $ivlen = openssl_cipher_iv_length( $cipher );
        $iv = openssl_random_pseudo_bytes( $ivlen );
        $ciphertext = openssl_encrypt( $data, $cipher, $key, 0, $iv );
        return ( false === $ciphertext ) ? $data : base64_encode( $iv . $ciphertext );
    }
}
if ( ! function_exists( 'wp_custom_decrypt' ) ) {
    function wp_custom_decrypt( $data ) {
        if ( empty( $data ) || ! defined( 'AUTH_KEY' ) ) return $data;
        $key = AUTH_KEY;
        $cipher = 'aes-256-cbc';
        if ( ! in_array( $cipher, openssl_get_cipher_methods() ) ) return $data;
        $decoded_data = base64_decode( $data, true );
        if ( false === $decoded_data ) return $data;
        $ivlen = openssl_cipher_iv_length( $cipher );
        if ( strlen( $decoded_data ) < $ivlen ) return $data;
        $iv = substr( $decoded_data, 0, $ivlen );
        $ciphertext = substr( $decoded_data, $ivlen );
        $decrypted_data = openssl_decrypt( $ciphertext, $cipher, $key, 0, $iv );
        return ( false === $decrypted_data ) ? $data : $decrypted_data;
    }
}

// ============================================================
// [API 키 가져오기]
// ============================================================
if ( ! function_exists( 'get_gemini_hub_api_key' ) ) {
    function get_gemini_hub_api_key() {
        $keys = array();
        for ( $i = 1; $i <= 5; $i++ ) {
            $k = get_option( 'gemini_hub_api_key_' . $i );
            if ( ! empty( $k ) ) {
                $keys[] = function_exists( 'wp_custom_decrypt' ) ? wp_custom_decrypt( $k ) : $k;
            }
        }
        if ( ! empty( $keys ) ) return $keys[ array_rand( $keys ) ];
        $api_keys = get_option( 'custom_app_api_keys', array() );
        if ( is_array( $api_keys ) ) {
            foreach ( $api_keys as $item ) {
                if ( isset( $item['v_name'] ) && $item['v_name'] === 'gv_gemini_api' && ! empty( $item['val'] ) ) {
                    return function_exists( 'wp_custom_decrypt' ) ? wp_custom_decrypt( $item['val'] ) : $item['val'];
                }
            }
        }
        return false;
    }
}

// ============================================================
// [허브 커넥터 클래스]
// ============================================================
if ( ! class_exists( 'Gemini_AI_Hub_Connector' ) ) {
    class Gemini_AI_Hub_Connector {
        // ✅ 수정: 기본 모델을 gemini-2.0-flash-exp (실제 존재하는 실험용 모델) 로 변경
        // 참고: gemini-2.5-flash-lite 는 존재하지 않는 모델명입니다
        private $api_base = 'https://generativelanguage.googleapis.com/v1/models/';
        private $default_model = 'gemini-2.0-flash-exp';

        // 무료 티어 한도 상수
        const FREE_RPM_LIMIT = 15;
        const FREE_TPM_LIMIT = 250000;
        const FREE_RPD_LIMIT = 1000;

        /**
         * 현재 선택된 모델명 반환 (기본값: gemini-2.0-flash-exp)
         */
        public function get_model_name() {
            return get_option( 'gemini_hub_model', $this->default_model );
        }

        /**
         * 현재 모델의 API URL 동적 생성
         */
        private function get_api_url() {
            $model = $this->get_model_name();
            return $this->api_base . rawurlencode( $model ) . ':generateContent';
        }

        /**
         * 무료 티어 사용량 키 (API 키별)
         */
        private function daily_key( $api_key_index = null ) {
            if ( $api_key_index !== null ) {
                return 'gemini_hub_usage_daily_' . gmdate( 'Ymd' . '_key' . $api_key_index );
            }
            return 'gemini_hub_usage_daily_' . gmdate( 'Ymd' );
        }

        /**
         * API 키별 사용량 키 생성
         */
        private function get_api_key_usage_keys( $api_key_index ) {
            return array(
                'rpm_key' => 'gemini_hub_usage_rpm_' . gmdate( 'Ymd_Hi' . '_key' . $api_key_index ),
                'tpm_key' => 'gemini_hub_usage_tpm_' . gmdate( 'Ymd_Hi' . '_key' . $api_key_index ),
                'rpd_key' => $this->daily_key( $api_key_index ),
            );
        }

        /**
         * 무료 티어 한도 체크 (전체)
         */
        public function check_free_tier_limits() {
            if ( ! get_option( 'gemini_hub_free_tier_enabled', 1 ) ) {
                return array( 'allowed' => true );
            }
            // 전체 사용량 집계 (모든 API 키 합산)
            $total_rpm = 0;
            $total_tpm = 0;
            $total_rpd = 0;
            
            for ( $i = 1; $i <= 5; $i++ ) {
                $keys = $this->get_api_key_usage_keys( $i );
                $total_rpm += (int) get_transient( $keys['rpm_key'] );
                $total_tpm += (int) get_transient( $keys['tpm_key'] );
                $total_rpd += (int) get_option( $keys['rpd_key'], 0 );
            }
            
            // 레거시 키도 확인 (기존 데이터 호환성)
            $rpm_key_legacy = 'gemini_hub_usage_rpm_' . gmdate( 'Ymd_Hi' );
            $tpm_key_legacy = 'gemini_hub_usage_tpm_' . gmdate( 'Ymd_Hi' );
            $rpd_key_legacy = $this->daily_key();
            $total_rpm += (int) get_transient( $rpm_key_legacy );
            $total_tpm += (int) get_transient( $tpm_key_legacy );
            $total_rpd += (int) get_option( $rpd_key_legacy, 0 );

            if ( $total_rpm >= self::FREE_RPM_LIMIT ) {
                return array( 
                    'allowed' => false, 
                    'code' => 429, 
                    'message' => "RPM 한도 초과 ({$total_rpm}/" . self::FREE_RPM_LIMIT . " 요청/분)",
                    'feedback_type' => 'free_tier_limit',
                    'feedback_msg' => '현재 무료 티어 분당 요청 한도 (RPM) 에 도달했습니다. 1 분 후 다시 시도해 주세요.'
                );
            }
            if ( $total_tpm >= self::FREE_TPM_LIMIT ) {
                return array( 
                    'allowed' => false, 
                    'code' => 429, 
                    'message' => "TPM 한도 초과 (" . number_format( $total_tpm ) . "/" . number_format( self::FREE_TPM_LIMIT ) . " 토큰/분)",
                    'feedback_type' => 'free_tier_limit',
                    'feedback_msg' => '현재 무료 티어 분당 토큰 한도 (TPM) 에 도달했습니다. 1 분 후 다시 시도해 주세요.'
                );
            }
            if ( $total_rpd >= self::FREE_RPD_LIMIT ) {
                return array( 
                    'allowed' => false, 
                    'code' => 429, 
                    'message' => "RPD 한도 초과 ({$total_rpd}/" . self::FREE_RPD_LIMIT . " 요청/일)",
                    'feedback_type' => 'free_tier_limit',
                    'feedback_msg' => '현재 무료 티어 일일 요청 한도 (RPD) 에 도달했습니다. 내일 다시 시도해 주세요.'
                );
            }
            return array( 'allowed' => true );
        }

        /**
         * API 키별 사용량 체크
         */
        public function check_api_key_limits( $api_key_index ) {
            if ( ! get_option( 'gemini_hub_free_tier_enabled', 1 ) ) {
                return array( 'allowed' => true, 'index' => $api_key_index );
            }
            
            $keys = $this->get_api_key_usage_keys( $api_key_index );
            $rpm = (int) get_transient( $keys['rpm_key'] );
            $tpm = (int) get_transient( $keys['tpm_key'] );
            $rpd = (int) get_option( $keys['rpd_key'], 0 );

            if ( $rpm >= self::FREE_RPM_LIMIT ) {
                return array( 
                    'allowed' => false, 
                    'code' => 429, 
                    'message' => "API#{$api_key_index} RPM 한도 초과",
                    'feedback_type' => 'free_tier_limit',
                    'feedback_msg' => "API 키 #{$api_key_index} 의 분당 요청 한도에 도달했습니다."
                );
            }
            if ( $tpm >= self::FREE_TPM_LIMIT ) {
                return array( 
                    'allowed' => false, 
                    'code' => 429, 
                    'message' => "API#{$api_key_index} TPM 한도 초과",
                    'feedback_type' => 'free_tier_limit',
                    'feedback_msg' => "API 키 #{$api_key_index} 의 분당 토큰 한도에 도달했습니다."
                );
            }
            if ( $rpd >= self::FREE_RPD_LIMIT ) {
                return array( 
                    'allowed' => false, 
                    'code' => 429, 
                    'message' => "API#{$api_key_index} RPD 한도 초과",
                    'feedback_type' => 'free_tier_limit',
                    'feedback_msg' => "API 키 #{$api_key_index} 의 일일 요청 한도에 도달했습니다."
                );
            }
            return array( 'allowed' => true, 'index' => $api_key_index );
        }

        /**
         * 사용량 업데이트 (API 키별)
         */
        public function update_usage( $input_tokens = 0, $output_tokens = 0, $api_key_index = null ) {
            if ( ! get_option( 'gemini_hub_free_tier_enabled', 1 ) ) return;

            // API 키 인덱스가 있으면 해당 키의 사용량만 업데이트
            if ( $api_key_index !== null ) {
                $keys = $this->get_api_key_usage_keys( $api_key_index );
            } else {
                // 레거시 호환성: 기본 키 사용
                $keys = array(
                    'rpm_key' => 'gemini_hub_usage_rpm_' . gmdate( 'Ymd_Hi' ),
                    'tpm_key' => 'gemini_hub_usage_tpm_' . gmdate( 'Ymd_Hi' ),
                    'rpd_key' => $this->daily_key(),
                );
            }

            $total_tokens = (int) $input_tokens + (int) $output_tokens;
            $now = time();
            $ttl_1min = max( 60, 60 - ( $now % 60 ) );

            set_transient( $keys['rpm_key'], (int) get_transient( $keys['rpm_key'] ) + 1, $ttl_1min );
            set_transient( $keys['tpm_key'], (int) get_transient( $keys['tpm_key'] ) + $total_tokens, $ttl_1min );
            update_option( $keys['rpd_key'], (int) get_option( $keys['rpd_key'], 0 ) + 1 );
            update_option( 'gemini_hub_last_usage_update', current_time( 'mysql' ) );
        }

        /**
         * 현재 사용량 통계 반환 (전체 + API 키별)
         */
        public function get_usage_stats() {
            // 전체 사용량 (모든 API 키 합산)
            $total_rpm = 0;
            $total_tpm = 0;
            $total_rpd = 0;
            
            // API 키별 사용량 배열
            $api_stats = array();
            
            for ( $i = 1; $i <= 5; $i++ ) {
                $keys = $this->get_api_key_usage_keys( $i );
                $rpm = (int) get_transient( $keys['rpm_key'] );
                $tpm = (int) get_transient( $keys['tpm_key'] );
                $rpd = (int) get_option( $keys['rpd_key'], 0 );
                
                $total_rpm += $rpm;
                $total_tpm += $tpm;
                $total_rpd += $rpd;
                
                $api_stats[] = array(
                    'index' => $i,
                    'rpm' => $rpm,
                    'tpm' => $tpm,
                    'rpd' => $rpd,
                    'rpm_limit' => self::FREE_RPM_LIMIT,
                    'tpm_limit' => self::FREE_TPM_LIMIT,
                    'rpd_limit' => self::FREE_RPD_LIMIT,
                );
            }
            
            // 레거시 키도 포함
            $rpm_key_legacy = 'gemini_hub_usage_rpm_' . gmdate( 'Ymd_Hi' );
            $tpm_key_legacy = 'gemini_hub_usage_tpm_' . gmdate( 'Ymd_Hi' );
            $rpd_key_legacy = $this->daily_key();
            $total_rpm += (int) get_transient( $rpm_key_legacy );
            $total_tpm += (int) get_transient( $tpm_key_legacy );
            $total_rpd += (int) get_option( $rpd_key_legacy, 0 );

            return array(
                'rpm'         => $total_rpm,
                'rpm_limit'   => self::FREE_RPM_LIMIT,
                'tpm'         => $total_tpm,
                'tpm_limit'   => self::FREE_TPM_LIMIT,
                'rpd'         => $total_rpd,
                'rpd_limit'   => self::FREE_RPD_LIMIT,
                'enabled'     => (bool) get_option( 'gemini_hub_free_tier_enabled', 1 ),
                'last_update' => get_option( 'gemini_hub_last_usage_update', '-' ),
                'api_stats'   => $api_stats,
            );
        }

        /**
         * AI 응답 생성
         */
        public function generate( $args = array() ) {
            $start_time = microtime( true );

            // [1] 무료 티어 한도 체크
            $limit_check = $this->check_free_tier_limits();
            if ( ! $limit_check['allowed'] ) {
                return array(
                    'status' => 'error', 'code' => $limit_check['code'],
                    'message' => 'FREE_TIER_LIMIT: ' . $limit_check['message'],
                    'duration' => '0s', 'input_tokens' => 0, 'output_tokens' => 0,
                    // 피드백 변수 추가 (2. Free Tier Limit Manager 도달로 대기중)
                    'feedback_type' => isset($limit_check['feedback_type']) ? $limit_check['feedback_type'] : 'free_tier_limit',
                    'feedback_msg' => isset($limit_check['feedback_msg']) ? $limit_check['feedback_msg'] : '현재 요청이 너무 많아 처리할 수 없습니다. 잠시 후 다시 시도해 주세요.',
                );
            }

            $api_key = get_gemini_hub_api_key();
            if ( ! $api_key ) {
                return array(
                    'status' => 'error', 'code' => 401,
                    'message' => 'API Key Missing (5 개 중 입력된 키가 없음)',
                    'duration' => '0s', 'input_tokens' => 0, 'output_tokens' => 0,
                    'feedback_type' => 'api_key_missing',
                    'feedback_msg' => 'API 키가 설정되지 않았습니다. 관리자에게 문의하세요.',
                );
            }

            $last_run = get_transient( 'gemini_hub_last_run' );
            if ( $last_run ) {
                $remaining = 1 - ( time() - $last_run );
                if ( $remaining > 0 ) {
                    return array(
                        'status' => 'error', 'code' => 429,
                        'message' => 'Cooldown: ' . $remaining . 's',
                        'duration' => '0s', 'input_tokens' => 0, 'output_tokens' => 0,
                        // 피드백 변수 추가 (1. 다른 API 를 처리중)
                        'feedback_type' => 'processing_other_request',
                        'feedback_msg' => '지금은 다른 요청을 처리 중입니다. 잠시 후 다시 시도해 주세요.',
                    );
                }
            }
            set_transient( 'gemini_hub_last_run', time(), 1 );

            $system   = $args['system'] ?? 'Expert assistant.';
            $content  = $args['content'] ?? '';
            $site_url = get_site_url();
            $estimated_input_tokens = (int) ceil( ( mb_strlen( $system ) + mb_strlen( $content ) ) / 4 );

            // ✅ 동적 모델 URL 사용
            $api_url = $this->get_api_url();
            $current_model = $this->get_model_name();

            $request_args = array(
                'headers' => array(
                    'Content-Type' => 'application/json',
                    'User-Agent'   => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0.0.0 Safari/537.36',
                    'Referer'      => $site_url,
                    'Origin'       => $site_url,
                ),
                'body'      => wp_json_encode( array(
                    'contents' => array( array(
                        'parts' => array( array(
                            'text' => 'System Instruction: ' . $system . "\nUser Content: " . $content,
                        ) ),
                    ) ),
                    'generationConfig' => array(
                        'temperature'     => 0.7,
                        'maxOutputTokens' => 4096,
                    ),
                ) ),
                'timeout'   => 120,
                'sslverify' => true,
            );

            $response = wp_remote_post( $api_url . '?key=' . rawurlencode( $api_key ), $request_args );
            $duration = round( microtime( true ) - $start_time, 2 );

            if ( is_wp_error( $response ) ) {
                $err_msg = $response->get_error_message();
                $new_log = $this->log_request( 'NETWORK_FAIL', $err_msg, $duration, 500 );
                return array(
                    'status' => 'error', 'code' => 500, 'message' => $err_msg,
                    'duration' => $duration . 's', 'log' => $new_log, 'model' => $current_model,
                    'input_tokens' => $estimated_input_tokens, 'output_tokens' => 0,
                    // 피드백 변수 추가 (3. 지금 접속 또는 처리 불능)
                    'feedback_type' => 'service_unavailable',
                    'feedback_msg' => '현재 서버에 접속할 수 없거나 처리가 불가능합니다. 네트워크 상태를 확인하거나 잠시 후 다시 시도해 주세요.',
                );
            }

            $http_code = wp_remote_retrieve_response_code( $response );
            $raw_body  = wp_remote_retrieve_body( $response );
            $body      = json_decode( $raw_body, true );

            if ( 200 !== (int) $http_code ) {
                $detail  = $body['error']['message'] ?? 'No detail provided';
                $new_log = $this->log_request( 'API_ERROR', "[{$current_model}] " . $detail, $duration, $http_code );
                
                // HTTP 상태코드별 피드백 분류
                $feedback_type = 'service_unavailable'; // 3. 지금 접속 또는 처리 불능
                $feedback_msg = '현재 서버에 접속할 수 없거나 처리가 불가능합니다. 네트워크 상태를 확인하거나 잠시 후 다시 시도해 주세요.';
                
                if ( $http_code == 429 ) {
                    $feedback_type = 'rate_limit_exceeded';
                    $feedback_msg = '요청 횟수가 너무 많습니다. 잠시 후 다시 시도해 주세요.';
                } elseif ( $http_code == 401 || $http_code == 403 ) {
                    $feedback_type = 'auth_error';
                    $feedback_msg = '인증 오류가 발생했습니다. API 키를 확인하세요.';
                } elseif ( $http_code == 404 ) {
                    $feedback_type = 'model_not_found';
                    $feedback_msg = '요청한 모델을 찾을 수 없습니다. 모델명을 확인하세요.';
                }
                
                return array(
                    'status' => 'error', 'code' => $http_code, 'message' => $detail,
                    'duration' => $duration . 's', 'log' => $new_log, 'model' => $current_model,
                    'input_tokens' => $estimated_input_tokens, 'output_tokens' => 0,
                    'feedback_type' => $feedback_type,
                    'feedback_msg' => $feedback_msg,
                );
            }

            $ai_result = $body['candidates'][0]['content']['parts'][0]['text'] ?? 'Empty response.';
            $usage_meta    = $body['usageMetadata'] ?? array();
            $input_tokens  = (int) ( $usage_meta['promptTokenCount'] ?? $estimated_input_tokens );
            $output_tokens = (int) ( $usage_meta['candidatesTokenCount'] ?? 0 );

            $this->update_usage( $input_tokens, $output_tokens );
            $new_log = $this->log_request( 'SUCCESS', "[{$current_model}] OK (in:{$input_tokens}/out:{$output_tokens})", $duration, 200 );

            return array(
                'status' => 'success', 'code' => 200,
                'response' => trim( $ai_result ), 'duration' => $duration . 's',
                'log' => $new_log, 'model' => $current_model,
                'input_tokens' => $input_tokens, 'output_tokens' => $output_tokens,
                'feedback_type' => 'success',
                'feedback_msg' => '요청이 성공적으로 처리되었습니다.',
            );
        }

        private function log_request( $status, $message, $duration, $code ) {
            $logs = get_option( 'gemini_hub_logs', array() );
            $new_entry = array(
                'time' => current_time( 'mysql' ), 'timestamp' => time() * 1000,
                'status' => $status, 'code' => $code,
                'message' => $message, 'duration' => (float) $duration,
            );
            array_unshift( $logs, $new_entry );
            update_option( 'gemini_hub_logs', array_slice( $logs, 0, 50 ) );
            return $new_entry;
        }

        public function get_model_info() {
            return $this->get_model_name();
        }

        /**
         * 사용 가능한 모델 목록 (generateContent 지원 모델만 필터링)
         */
        public function get_available_models() {
            $start_time = microtime( true );
            $api_key    = get_gemini_hub_api_key();
            if ( ! $api_key ) {
                return array( 'status' => 'error', 'code' => 401, 'message' => 'API Key Missing.', 'duration' => '0s' );
            }
            $models_api_url = 'https://generativelanguage.googleapis.com/v1/models';
            $request_args = array(
                'headers'   => array( 'Content-Type' => 'application/json' ),
                'timeout'   => 30,
                'sslverify' => true,
            );
            $response = wp_remote_get( $models_api_url . '?key=' . rawurlencode( $api_key ), $request_args );
            $duration = round( microtime( true ) - $start_time, 2 );

            if ( is_wp_error( $response ) ) {
                return array( 'status' => 'error', 'code' => 500, 'message' => $response->get_error_message(), 'duration' => $duration . 's' );
            }
            $http_code = wp_remote_retrieve_response_code( $response );
            $body      = json_decode( wp_remote_retrieve_body( $response ), true );

            if ( 200 !== (int) $http_code ) {
                return array( 'status' => 'error', 'code' => $http_code, 'message' => $body['error']['message'] ?? 'API error', 'duration' => $duration . 's' );
            }

            $models = array();
            if ( isset( $body['models'] ) && is_array( $body['models'] ) ) {
                foreach ( $body['models'] as $model ) {
                    $methods = $model['supportedGenerationMethods'] ?? array();
                    $supports_generate = in_array( 'generateContent', $methods );
                    $models[] = array(
                        'name'        => str_replace( 'models/', '', $model['name'] ),
                        'version'     => $model['version'] ?? 'N/A',
                        'description' => $model['description'] ?? 'N/A',
                        'input_token_limit'  => $model['inputTokenLimit'] ?? 'N/A',
                        'output_token_limit' => $model['outputTokenLimit'] ?? 'N/A',
                        'supported_generation_methods' => $methods,
                        'can_generate' => $supports_generate,
                    );
                }
            }
            return array( 'status' => 'success', 'code' => 200, 'models' => $models, 'duration' => $duration . 's' );
        }
    }
}

$gemini_hub_bridge = new Gemini_AI_Hub_Connector();

// ============================================================
// [관리자 페이지 클래스]
// ============================================================
if ( ! class_exists( 'Gemini_AI_Hub_Admin' ) ) {
    class Gemini_AI_Hub_Admin {
        // ✅ 수정: 기본 모델명 상수화 (실제 존재하는 모델)
        const DEFAULT_MODEL = 'gemini-2.0-flash-exp';

        public function __construct() {
            add_action( 'admin_menu', array( $this, 'add_menu' ) );
            add_action( 'wp_ajax_gemini_hub_test', array( $this, 'handle_test' ) );
            add_action( 'wp_ajax_gemini_hub_save_settings', array( $this, 'handle_save_settings' ) );
            add_action( 'wp_ajax_gemini_hub_basic_test', array( $this, 'handle_basic_test' ) );
            add_action( 'wp_ajax_gemini_hub_get_models', array( $this, 'handle_get_available_models' ) );
            add_action( 'wp_ajax_gemini_hub_clear_logs', array( $this, 'handle_clear_logs' ) );
            add_action( 'wp_ajax_gemini_hub_get_usage', array( $this, 'handle_get_usage' ) );
            add_action( 'wp_ajax_gemini_hub_toggle_free_tier', array( $this, 'handle_toggle_free_tier' ) );
            add_action( 'wp_ajax_gemini_hub_save_model', array( $this, 'handle_save_model' ) );
        }

        public function add_menu() {
            $hook = add_submenu_page(
                'hotheart-wp-admin-utility-2',
                '제미나이 AI 허브', '제미나이 AI 허브',
                'manage_options', 'gemini-ai-hub',
                array( $this, 'render_page' )
            );
            if ( empty( $hook ) ) {
                add_management_page(
                    '제미나이 AI 허브', '제미나이 AI 허브',
                    'manage_options', 'gemini-ai-hub',
                    array( $this, 'render_page' )
                );
            }
        }

        public function handle_save_settings() {
            check_ajax_referer( 'gemini_hub_nonce', 'nonce' );
            if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized', 403 );
            for ( $i = 1; $i <= 5; $i++ ) {
                update_option( 'gemini_hub_api_key_' . $i, sanitize_text_field( $_POST[ 'api_key_' . $i ] ?? '' ) );
            }
            update_option( 'gemini_hub_sys_prompt', wp_kses_post( $_POST['system_prompt'] ?? '' ) );
            update_option( 'gemini_hub_user_content', wp_kses_post( $_POST['user_content'] ?? '' ) );
            wp_send_json_success( '모든 API 키와 설정이 저장되었습니다.' );
        }

        public function handle_save_model() {
            check_ajax_referer( 'gemini_hub_nonce', 'nonce' );
            if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized', 403 );
            $model = sanitize_text_field( $_POST['model'] ?? self::DEFAULT_MODEL );
            update_option( 'gemini_hub_model', $model );
            wp_send_json_success( array(
                'model'   => $model,
                'message' => '저장 완료',
            ) );
        }

        public function handle_test() {
            check_ajax_referer( 'gemini_hub_nonce', 'nonce' );
            if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized', 403 );
            global $gemini_hub_bridge;
            $result = $gemini_hub_bridge->generate( array(
                'system'  => wp_kses_post( $_POST['system_prompt'] ?? '' ),
                'content' => wp_kses_post( $_POST['user_content'] ?? '' ),
            ) );
            wp_send_json_success( $result );
        }

        public function handle_basic_test() {
            check_ajax_referer( 'gemini_hub_nonce', 'nonce' );
            if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized', 403 );
            global $gemini_hub_bridge;
            $result = $gemini_hub_bridge->generate( array(
                'system'  => 'You are a helpful assistant.',
                'content' => 'Hello, world!',
            ) );
            wp_send_json_success( $result );
        }

        public function handle_get_available_models() {
            check_ajax_referer( 'gemini_hub_nonce', 'nonce' );
            if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized', 403 );
            global $gemini_hub_bridge;
            wp_send_json_success( $gemini_hub_bridge->get_available_models() );
        }

        public function handle_clear_logs() {
            check_ajax_referer( 'gemini_hub_nonce', 'nonce' );
            if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized', 403 );
            update_option( 'gemini_hub_logs', array() );
            wp_send_json_success();
        }

        public function handle_get_usage() {
            check_ajax_referer( 'gemini_hub_nonce', 'nonce' );
            if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized', 403 );
            global $gemini_hub_bridge;
            wp_send_json_success( $gemini_hub_bridge->get_usage_stats() );
        }

        public function handle_toggle_free_tier() {
            check_ajax_referer( 'gemini_hub_nonce', 'nonce' );
            if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized', 403 );
            $enabled = isset( $_POST['enabled'] ) && $_POST['enabled'] === '1' ? 1 : 0;
            update_option( 'gemini_hub_free_tier_enabled', $enabled );
            wp_send_json_success( array(
                'enabled' => $enabled,
                'message' => $enabled ? '무료 티어 한도 제한이 활성화되었습니다.' : '무료 티어 한도 제한이 비활성화되었습니다.',
            ) );
        }

        public function render_page() {
            if ( ! current_user_can( 'manage_options' ) ) return;

            $logs          = get_option( 'gemini_hub_logs', array() );
            $saved_system  = get_option( 'gemini_hub_sys_prompt', 'You are a professional mechanical designer and WordPress expert.' );
            $saved_content = get_option( 'gemini_hub_user_content', '' );
            $free_tier_on  = (bool) get_option( 'gemini_hub_free_tier_enabled', 1 );
            // ✅ 수정: 기본 모델명을 gemini-2.0-flash-exp 로 변경 (실제 존재하는 모델)
            $current_model = get_option( 'gemini_hub_model', self::DEFAULT_MODEL );

            global $gemini_hub_bridge;
            $usage_stats = $gemini_hub_bridge->get_usage_stats();
            ?>
            <style>
                .gh-card { background:#fff; border:1px solid #ccd0d4; padding:20px; margin-bottom:20px; border-radius:4px; box-shadow:0 1px 1px rgba(0,0,0,.04); width:100%; box-sizing:border-box; }
                .gh-badge { padding:4px 8px; border-radius:3px; font-weight:bold; font-size:11px; }
                .status-200 { background:#e7f8ed; color:#2e7d32; }
                .status-err { background:#fbeae5; color:#d32f2f; }
                .gh-console { background:#1e1e1e; color:#d4d4d4; padding:15px; font-family:Consolas,monospace; border-radius:4px; line-height:1.5; white-space:pre-wrap; word-break:break-all; height:750px; overflow-y:auto; width:100%; }
                .gh-prompt-area { height:600px; font-family:Consolas,monospace; width:100%; padding:10px; border:1px solid #ddd; }
                .log-table { width:100%; border-collapse:collapse; font-size:12px; }
                .log-table td { padding:8px; border-bottom:1px solid #eee; vertical-align:top; }
                .msg-detail { color:#d32f2f; font-weight:bold; }
                .flex-row { display:flex; gap:20px; margin-top:10px; }
                .flex-item { flex:1; }
                .key-grid { display:grid; grid-template-columns:repeat(5,1fr); gap:10px; margin-bottom:15px; }
                .bridge-info { background:#f0f6fb; padding:10px; border-left:4px solid #2196F3; font-family:monospace; font-size:12px; margin-top:10px; }

                .gh-switch { position:relative; display:inline-block; width:50px; height:26px; vertical-align:middle; }
                .gh-switch input { opacity:0; width:0; height:0; }
                .gh-slider { position:absolute; cursor:pointer; top:0; left:0; right:0; bottom:0; background:#ccc; transition:.3s; border-radius:26px; }
                .gh-slider:before { position:absolute; content:""; height:20px; width:20px; left:3px; bottom:3px; background:#fff; transition:.3s; border-radius:50%; }
                input:checked + .gh-slider { background:#4CAF50; }
                input:checked + .gh-slider:before { transform:translateX(24px); }

                .usage-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:15px; margin-top:10px; }
                .usage-box { background:#f9f9f9; border:1px solid #e0e0e0; border-radius:6px; padding:12px; }
                .usage-box h4 { margin:0 0 8px; font-size:13px; color:#555; }
                .usage-value { font-size:20px; font-weight:bold; color:#333; }
                .usage-limit { font-size:11px; color:#888; }
                .usage-bar { width:100%; height:8px; background:#eee; border-radius:4px; margin-top:8px; overflow:hidden; }
                .usage-bar-fill { height:100%; background:linear-gradient(90deg,#4CAF50,#8BC34A); transition:width .5s; border-radius:4px; }
                .usage-bar-fill.warn { background:linear-gradient(90deg,#FF9800,#FFC107); }
                .usage-bar-fill.danger { background:linear-gradient(90deg,#f44336,#FF5722); }

                .hub-info-grid { display:grid; grid-template-columns:1fr 1fr; gap:15px; margin-top:10px; }
                .hub-info-box { background:#fafafa; border-left:4px solid #673ab7; padding:12px; font-family:Consolas,monospace; font-size:12px; }
                .hub-info-box strong { color:#673ab7; display:block; margin-bottom:6px; }
                .hub-info-box code { background:#fff; padding:2px 6px; border-radius:3px; border:1px solid #eee; }

                .model-selector { display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
                .model-selector select { padding:6px 10px; font-size:13px; border:1px solid #ccc; border-radius:4px; min-width:280px; }
                .model-badge { background:#FFC107; color:#333; padding:3px 8px; border-radius:3px; font-size:11px; font-weight:bold; }
                .model-badge.err { background:#f44336; color:#fff; }
                .lite-badge { background:#00BCD4; color:#fff; padding:3px 8px; border-radius:3px; font-size:11px; font-weight:bold; margin-left:5px; }
            </style>

            <div class="wrap" style="max-width:1400px;">
                <h1>제미나이 AI 허브 커넥터 <span style="font-size:12px; opacity:0.6;">v3.1.1 (Flash-Lite Default)</span></h1>

                <!-- [무료 티어 토글 & 사용량] -->
                <div class="gh-card" style="border-top:4px solid #FF5722;">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;">
                        <h3 style="margin:0;">🛡️ Free Tier Limit Manager</h3>
                        <div>
                            <span style="margin-right:10px; font-size:13px;">무료 티어 한도 제한:</span>
                            <label class="gh-switch">
                                <input type="checkbox" id="gh-free-tier-toggle" <?php checked( $free_tier_on, true ); ?>>
                                <span class="gh-slider"></span>
                            </label>
                            <span id="gh-free-tier-status" style="margin-left:10px; font-weight:bold; color:<?php echo $free_tier_on ? '#4CAF50' : '#999'; ?>;">
                                <?php echo $free_tier_on ? 'ON (제한 활성)' : 'OFF (제한 해제)'; ?>
                            </span>
                        </div>
                    </div>
                    <div class="usage-grid">
                        <div class="usage-box">
                            <h4>📊 RPM (분당 요청 수)</h4>
                            <div class="usage-value"><span id="gh-rpm"><?php echo $usage_stats['rpm']; ?></span> <span class="usage-limit">/ <?php echo $usage_stats['rpm_limit']; ?></span></div>
                            <div class="usage-bar"><div id="gh-rpm-bar" class="usage-bar-fill" style="width:<?php echo min( 100, ( $usage_stats['rpm'] / $usage_stats['rpm_limit'] ) * 100 ); ?>%;"></div></div>
                        </div>
                        <div class="usage-box">
                            <h4>🔤 TPM (분당 토큰 수)</h4>
                            <div class="usage-value"><span id="gh-tpm"><?php echo number_format( $usage_stats['tpm'] ); ?></span> <span class="usage-limit">/ <?php echo number_format( $usage_stats['tpm_limit'] ); ?></span></div>
                            <div class="usage-bar"><div id="gh-tpm-bar" class="usage-bar-fill" style="width:<?php echo min( 100, ( $usage_stats['tpm'] / $usage_stats['tpm_limit'] ) * 100 ); ?>%;"></div></div>
                        </div>
                        <div class="usage-box">
                            <h4>📅 RPD (일일 요청 수)</h4>
                            <div class="usage-value"><span id="gh-rpd"><?php echo $usage_stats['rpd']; ?></span> <span class="usage-limit">/ <?php echo $usage_stats['rpd_limit']; ?></span></div>
                            <div class="usage-bar"><div id="gh-rpd-bar" class="usage-bar-fill" style="width:<?php echo min( 100, ( $usage_stats['rpd'] / $usage_stats['rpd_limit'] ) * 100 ); ?>%;"></div></div>
                        </div>
                    </div>
                    <div style="margin-top:10px; font-size:11px; color:#888;">마지막 갱신: <span id="gh-last-update"><?php echo esc_html( $usage_stats['last_update'] ); ?></span> (5초마다 자동 갱신)</div>
                </div>

                <!-- [설정 카드] -->
                <div class="gh-card" style="border-top:4px solid #2196F3;">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;">
                        <h3 style="margin:0;">🛠️ Multi-API Key & Prompt Settings</h3>
                        <button id="gh-save-all" class="button button-primary">모든 설정 저장</button>
                    </div>

                    <!-- ✅ 모델 선택 영역 (Flash-Lite 기본) -->
                    <div style="background:#e0f7fa; border:1px solid #4dd0e1; padding:12px; border-radius:4px; margin-bottom:15px;">
                        <div style="display:flex; justify-content:space-between; align-items:center; gap:15px; flex-wrap:wrap;">
                            <div class="model-selector">
                                <strong>🤖 Active Model:</strong>
                                <select id="gh-model-select">
                                    <option value="<?php echo esc_attr( $current_model ); ?>" selected><?php echo esc_html( $current_model ); ?> (현재)</option>
                                </select>
                                <button id="gh-save-model" class="button button-secondary" style="background:#00BCD4; color:#fff; border:none; font-weight:bold;">모델 저장</button>
                                <button id="gh-refresh-models" class="button button-secondary">🔄 모델 목록 새로고침</button>
                            </div>
                            <div>
                                <span id="gh-current-model-badge" class="model-badge"><?php echo esc_html( $current_model ); ?></span>
                                <?php if ( $current_model === 'gemini-2.0-flash-exp' ) : ?>
                                    <span class="lite-badge">💰 실험용 모델 (무료)</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <small style="color:#006064; display:block; margin-top:8px;">
                            ✅ <strong>기본 모델: <code>gemini-2.0-flash-exp</code></strong> — Google Gemini 의 실험용 모델로 무료로 사용 가능합니다.
                            "모델 목록 새로고침"을 클릭하면 <code>generateContent</code>를 지원하는 실제 사용 가능한 모델만 표시됩니다.
                        </small>
                    </div>

                    <div class="key-grid">
                        <?php for ( $i = 1; $i <= 5; $i++ ) : ?>
                            <div>
                                <label><small>API Key #<?php echo (int) $i; ?></small></label>
                                <input type="password" id="gh_api_key_<?php echo (int) $i; ?>" class="regular-text" style="width:100%;" value="<?php echo esc_attr( get_option( 'gemini_hub_api_key_' . $i ) ); ?>" placeholder="Key <?php echo (int) $i; ?>">
                            </div>
                        <?php endfor; ?>
                    </div>
                    <div class="flex-row">
                        <div class="flex-item">
                            <label><strong>System Instruction</strong></label>
                            <textarea id="gh_system" class="gh-prompt-area" style="margin-top:5px;"><?php echo esc_textarea( $saved_system ); ?></textarea>
                        </div>
                        <div class="flex-item">
                            <label><strong>User Input Content</strong></label>
                            <textarea id="gh_content" class="gh-prompt-area" style="margin-top:5px;"><?php echo esc_textarea( $saved_content ); ?></textarea>
                        </div>
                    </div>
                </div>

                <!-- [글로벌 변수 / 입력 / 출력 정보] -->
                <div class="gh-card" style="border-top:4px solid #9C27B0;">
                    <h3 style="margin:0 0 10px;">🔌 Hub Bridge Info (Global Variable / Input / Output)</h3>
                    <div class="hub-info-grid">
                        <div class="hub-info-box">
                            <strong>🌐 Global Bridge Variable</strong>
                            <code>global $gemini_hub_bridge;</code><br>
                            <code>$res = $gemini_hub_bridge-&gt;generate([<br>
                            &nbsp;&nbsp;'system' =&gt; '...',<br>
                            &nbsp;&nbsp;'content' =&gt; '...'<br>
                            ]);</code>
                        </div>
                        <div class="hub-info-box">
                            <strong>📥 Input / 📤 Output</strong>
                            입력 토큰: <span id="gh-last-input" style="color:#2196F3; font-weight:bold;">0</span><br>
                            출력 토큰: <span id="gh-last-output" style="color:#4CAF50; font-weight:bold;">0</span><br>
                            총 토큰: <span id="gh-last-total" style="color:#FF5722; font-weight:bold;">0</span><br>
                            <small style="color:#999;">※ 마지막 요청 기준 (API 응답 usageMetadata 기반)</small>
                        </div>
                    </div>
                </div>

                <!-- [AI 응답 결과] -->
                <div class="gh-card" style="border-top:4px solid #673ab7;">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;">
                        <h3 style="margin:0;">🚀 AI Response Result</h3>
                        <button id="gh-basic-test" class="button button-secondary" style="background:#4CAF50; color:#fff; border:none; cursor:pointer; font-size:14px; font-weight:bold; padding:8px 15px;">기본 API 통신 테스트</button>
                        <div id="gh-status-badge"></div>
                    </div>
                    <button id="gh-run" class="button button-secondary" style="width:100%; height:50px; background:#673ab7; color:#fff; border:none; cursor:pointer; font-size:16px; font-weight:bold; margin-bottom:15px;">최적화 실행 (Random Key Rotation)</button>
                    <div id="gh_output" class="gh-console">결과가 여기에 표시됩니다...</div>
                </div>

                <!-- [로그] -->
                <div class="gh-card" style="border-top:4px solid #4CAF50;">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
                        <h3 style="margin:0;">📜 History Logs</h3>
                        <button id="gh-clear-logs" class="button button-link">로그 초기화</button>
                    </div>
                    <div style="max-height:300px; overflow-y:auto;">
                        <table class="log-table">
                            <thead>
                                <tr style="background:#f9f9f9; text-align:left;">
                                    <th style="padding:8px;">시간</th>
                                    <th style="padding:8px;">코드</th>
                                    <th style="padding:8px;">메시지/상세내용</th>
                                    <th style="padding:8px;">소요시간</th>
                                </tr>
                            </thead>
                            <tbody id="gh-log-body">
                                <?php foreach ( $logs as $log ) : ?>
                                    <tr>
                                        <td><small><?php echo esc_html( $log['time'] ); ?></small></td>
                                        <td><span class="gh-badge <?php echo ( (int) $log['code'] === 200 ) ? 'status-200' : 'status-err'; ?>"><?php echo esc_html( $log['code'] ); ?></span></td>
                                        <td><small class="<?php echo ( (int) $log['code'] !== 200 ) ? 'msg-detail' : ''; ?>"><?php echo esc_html( $log['message'] ); ?></small></td>
                                        <td><small><?php echo esc_html( $log['duration'] ); ?>s</small></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- [모델 목록] -->
                <div class="gh-card" style="border-top:4px solid #FFC107;">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;">
                        <h3 style="margin:0;">📚 Available Models List</h3>
                        <button id="gh-fetch-models" class="button button-secondary" style="background:#FFC107; color:#333; border:none; cursor:pointer; font-size:14px; font-weight:bold; padding:8px 15px;">사용 가능한 모델 목록 불러오기</button>
                    </div>
                    <div id="gh-models-output" class="gh-console" style="height:900px;">모델 목록이 여기에 표시됩니다...</div>
                </div>
            </div>

            <script>
            jQuery(document).ready(function($) {
                const nonce = '<?php echo esc_js( wp_create_nonce( 'gemini_hub_nonce' ) ); ?>';
                const defaultModel = '<?php echo esc_js( self::DEFAULT_MODEL ); ?>';

                // ============================================================
                // [사용량 통계 5초마다 자동 갱신]
                // ============================================================
                function refreshUsage() {
                    $.post(ajaxurl, { action: 'gemini_hub_get_usage', nonce: nonce }, function(res) {
                        if (!res.success) return;
                        const d = res.data;
                        $('#gh-rpm').text(d.rpm);
                        const rpmPct = Math.min(100, (d.rpm / d.rpm_limit) * 100);
                        $('#gh-rpm-bar').css('width', rpmPct + '%').removeClass('warn danger').addClass(rpmPct > 80 ? 'danger' : (rpmPct > 50 ? 'warn' : ''));

                        $('#gh-tpm').text(d.tpm.toLocaleString());
                        const tpmPct = Math.min(100, (d.tpm / d.tpm_limit) * 100);
                        $('#gh-tpm-bar').css('width', tpmPct + '%').removeClass('warn danger').addClass(tpmPct > 80 ? 'danger' : (tpmPct > 50 ? 'warn' : ''));

                        $('#gh-rpd').text(d.rpd);
                        const rpdPct = Math.min(100, (d.rpd / d.rpd_limit) * 100);
                        $('#gh-rpd-bar').css('width', rpdPct + '%').removeClass('warn danger').addClass(rpdPct > 80 ? 'danger' : (rpdPct > 50 ? 'warn' : ''));

                        $('#gh-last-update').text(d.last_update);
                    });
                }
                setInterval(refreshUsage, 5000);

                // ============================================================
                // [무료 티어 토글]
                // ============================================================
                $('#gh-free-tier-toggle').change(function() {
                    const enabled = $(this).is(':checked') ? '1' : '0';
                    $.post(ajaxurl, { action: 'gemini_hub_toggle_free_tier', nonce: nonce, enabled: enabled }, function(res) {
                        if (res.success) {
                            const on = res.data.enabled == 1;
                            $('#gh-free-tier-status').text(on ? 'ON (제한 활성)' : 'OFF (제한 해제)').css('color', on ? '#4CAF50' : '#999');
                        }
                    });
                });

                // ============================================================
                // [모델 목록 새로고침 → 드롭다운 채우기]
                // ============================================================
                function loadModelsToSelect() {
                    const $select = $('#gh-model-select');
                    const currentVal = $select.val() || defaultModel;
                    $select.html('<option value="">불러오는 중...</option>');

                    $.post(ajaxurl, { action: 'gemini_hub_get_models', nonce: nonce }, function(res) {
                        if (res.success && res.data.status === 'success' && res.data.models) {
                            const models = res.data.models.filter(m => m.can_generate);
                            if (models.length === 0) {
                                $select.html('<option value="">generateContent 지원 모델 없음</option>');
                                return;
                            }
                            // ✅ Flash-Lite를 최상단에 배치
                            models.sort((a, b) => {
                                if (a.name === defaultModel) return -1;
                                if (b.name === defaultModel) return 1;
                                return a.name.localeCompare(b.name);
                            });
                            let html = '';
                            models.forEach(m => {
                                const selected = (m.name === currentVal) ? 'selected' : '';
                                const isLite = m.name === defaultModel ? ' 💰(최저가)' : '';
                                html += `<option value="${m.name}" ${selected}>${m.name}${isLite} (in:${m.input_token_limit})</option>`;
                            });
                            $select.html(html);
                        } else {
                            $select.html(`<option value="">오류: ${(res.data && res.data.message) || 'Unknown'}</option>`);
                        }
                    }).fail(function() {
                        $select.html('<option value="">네트워크 오류</option>');
                    });
                }

                $('#gh-refresh-models').click(function() {
                    const $btn = $(this);
                    $btn.prop('disabled', true).text('불러오는 중...');
                    loadModelsToSelect();
                    setTimeout(() => { $btn.prop('disabled', false).text('🔄 모델 목록 새로고침'); }, 1500);
                });

                // ============================================================
                // [모델 저장]
                // ============================================================
                $('#gh-save-model').click(function() {
                    const model = $('#gh-model-select').val();
                    if (!model) { alert('선택된 모델이 없습니다.'); return; }
                    const $btn = $(this);
                    $btn.prop('disabled', true).text('저장 중...');
                    $.post(ajaxurl, { action: 'gemini_hub_save_model', nonce: nonce, model: model }, function(res) {
                        if (res.success) {
                            alert(res.data.message);
                            $('#gh-current-model-badge').text(model);
                        } else {
                            alert('저장 실패');
                        }
                    }).always(function() {
                        $btn.prop('disabled', false).text('모델 저장');
                    });
                });

                // ============================================================
                // [설정 저장]
                // ============================================================
                $('#gh-save-all').click(function() {
                    const $btn = $(this);
                    $btn.prop('disabled', true).text('저장 중...');
                    const data = {
                        action: 'gemini_hub_save_settings', nonce: nonce,
                        system_prompt: $('#gh_system').val(), user_content: $('#gh_content').val()
                    };
                    for (let i = 1; i <= 5; i++) data['api_key_' + i] = $('#gh_api_key_' + i).val();
                    $.post(ajaxurl, data, function(res) { alert(res.data); })
                      .always(function() { $btn.prop('disabled', false).text('모든 설정 저장'); });
                });

                // ============================================================
                // [응답 결과 공통 처리]
                // ============================================================
                function handleResponse(res) {
                    const d = res.data;
                    $('#gh_output').text(d.response || d.message);
                    
                    // 기존 배지 + 피드백 메시지 추가
                    let badgeHtml = `<span class="gh-badge ${d.code == 200 ? 'status-200' : 'status-err'}">HTTP ${d.code} / ${d.duration} / Model: ${d.model || 'N/A'}</span>`;
                    
                    // 피드백 메시지가 있으면 추가 표시 (한글)
                    if (d.feedback_msg) {
                        const feedbackClass = d.code == 200 ? 'style="color:#4CAF50; font-weight:bold; margin-left:10px;"' : 'style="color:#FF9800; font-weight:bold; margin-left:10px;"';
                        badgeHtml += `<span ${feedbackClass}>💡 ${d.feedback_msg}</span>`;
                    }
                    
                    $('#gh-status-badge').html(badgeHtml);
                    if (typeof d.input_tokens !== 'undefined') {
                        $('#gh-last-input').text(d.input_tokens.toLocaleString());
                        $('#gh-last-output').text((d.output_tokens || 0).toLocaleString());
                        $('#gh-last-total').text(((d.input_tokens || 0) + (d.output_tokens || 0)).toLocaleString());
                    }
                    if (d.log) {
                        const newRow = `<tr>
                            <td><small>${d.log.time}</small></td>
                            <td><span class="gh-badge ${d.code == 200 ? 'status-200' : 'status-err'}">${d.code}</span></td>
                            <td><small class="${d.code != 200 ? 'msg-detail' : ''}">${d.log.message}</small></td>
                            <td><small>${d.log.duration}s</small></td>
                        </tr>`;
                        $('#gh-log-body').prepend(newRow);
                    }
                    refreshUsage();
                }

                // ============================================================
                // [최적화 실행]
                // ============================================================
                $('#gh-run').click(function() {
                    const $btn = $(this);
                    $btn.prop('disabled', true).text('랜덤 키 배분 및 분석 중...');
                    $('#gh_output').text('Processing with Random Key Rotation...');
                    $.post(ajaxurl, {
                        action: 'gemini_hub_test', nonce: nonce,
                        system_prompt: $('#gh_system').val(), user_content: $('#gh_content').val()
                    }, handleResponse)
                    .fail(function() { $('#gh_output').text('Critical Network Error.'); })
                    .always(function() { $btn.prop('disabled', false).text('최적화 실행 (Random Key Rotation)'); });
                });

                // ============================================================
                // [기본 테스트]
                // ============================================================
                $('#gh-basic-test').click(function() {
                    const $btn = $(this);
                    $btn.prop('disabled', true).text('테스트 중...');
                    $('#gh_output').text('Performing basic API communication test...');
                    $.post(ajaxurl, { action: 'gemini_hub_basic_test', nonce: nonce }, handleResponse)
                    .fail(function() { $('#gh_output').text('Critical Network Error during basic test.'); })
                    .always(function() { $btn.prop('disabled', false).text('기본 API 통신 테스트'); });
                });

                // ============================================================
                // [모델 목록 상세 보기]
                // ============================================================
                $('#gh-fetch-models').click(function() {
                    const $btn = $(this);
                    $btn.prop('disabled', true).text('모델 불러오는 중...');
                    $('#gh-models-output').text('Fetching available models...');
                    $.post(ajaxurl, { action: 'gemini_hub_get_models', nonce: nonce }, function(res) {
                        if (res.success && res.data.status === 'success' && res.data.models) {
                            const data = res.data;
                            const genModels = data.models.filter(m => m.can_generate);
                            const embedModels = data.models.filter(m => !m.can_generate);
                            let out = `✅ generateContent 지원 모델: ${genModels.length}개\n`;
                            out += `📦 기타 모델 (embedContent 등): ${embedModels.length}개\n`;
                            out += `응답 시간: ${data.duration}s\n`;
                            out += `========================================\n\n`;
                            out += `[generateContent 지원 모델 목록]\n`;
                            genModels.forEach(m => {
                                const isDefault = m.name === defaultModel ? ' 💰[기본/최저가]' : '';
                                out += `• ${m.name}${isDefault}\n  버전: ${m.version}\n  설명: ${m.description}\n  입력 토큰 제한: ${m.input_token_limit}\n  출력 토큰 제한: ${m.output_token_limit}\n----------------------------------------\n`;
                            });
                            $('#gh-models-output').text(out);
                            loadModelsToSelect();
                        } else {
                            $('#gh-models-output').text(`Error: ${(res.data && res.data.message) || 'Unknown error'}`);
                        }
                    }).fail(function() { $('#gh-models-output').text('Critical Network Error fetching models.'); })
                      .always(function() { $btn.prop('disabled', false).text('사용 가능한 모델 목록 불러오기'); });
                });

                // ============================================================
                // [로그 초기화]
                // ============================================================
                $('#gh-clear-logs').click(function(e) {
                    e.preventDefault();
                    if (confirm('모든 로그 기록을 삭제하시겠습니까?')) {
                        $.post(ajaxurl, { action: 'gemini_hub_clear_logs', nonce: nonce }, function() { location.reload(); });
                    }
                });

                // 페이지 로드 시 모델 목록 자동 로드
                loadModelsToSelect();
            });
            </script>
            <?php
        }
    }
}

new Gemini_AI_Hub_Admin();