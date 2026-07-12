<?php
/**
 * =============================================================================
 * 모듈명: Woo AI Description Automator V26 (상품 AI 설명 자동화 V26)
 * -----------------------------------------------------------------------------
 * 기능 요약:
 * 1. WooCommerce 상품에 대한 AI 기반 설명 및 SEO 메타데이터를 자동 생성합니다.
 * 2. GPT Hub Connector 및 Gemini Hub Connector 와 연동하여 다양한 AI 모델을 지원합니다.
 * 3. 생성된 데이터는 JSON-LD 스키마 마크업 형태로 상품 메타필드 (_product_ai_json_ld) 에 저장됩니다.
 * 4. 배치 처리 UI 를 제공하여 다수 상품의 일괄 생성 및 진행 상황 확인이 가능합니다.
 * 5. 관리자 메뉴 (hotheart-wp-admin-utility-2) 하위에 '상품 AI 자동화' 메뉴로 등록됩니다.
 * 
 * 주의사항:
 * - 본 모듈은 독립 실행형이 아니며, 반드시 'hotheart-wp-admin-utility-2' 부모 플러그인이 활성화되어야 합니다.
 * - 부모 메뉴가 존재하지 않을 경우 자동으로 메뉴 등록을 건너뜁니다 (오류 방지).
 * 
 * 버전: 26.6.8 | 날짜: 2026-05-25 | 시리얼: ho2668
 * =============================================================================
 *
 * Module Name: Woo AI Description Automator V26
 * Description 1: Clean schema-only generator storing only _product_ai_json_ld
 * Description 2: Integrates GPT Hub Connector and Gemini Hub Connector bridges
 * Description 3: Batch runner UI with product progress stats and JSON-LD preview
 * Module Color: color-purple
 * Version: 26.6.8
 * Date: 2026-05-25
 * Serial: ho2668
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('Hotheart_Woo_AI_Automator_V268')) {
    class Hotheart_Woo_AI_Automator_V268 {
        const MENU_SLUG = 'woo-ai-v26';
        const NONCE_ACTION = 'woo_ai_v268_nonce';
        const OPTION_PROMPT = 'woo_ai_v26_prompt';
        const OPTION_MODE = 'woo_ai_v26_mode';

        public function __construct() {
            add_action('admin_menu', array($this, 'register_menu'));
            add_action('wp_ajax_save_woo_ai_v26_settings', array($this, 'ajax_save_settings'));
            add_action('wp_ajax_call_woo_ai_v26_process', array($this, 'ajax_process_single'));
            add_action('wp_head', array($this, 'render_schema_on_product'), 100);
        }

        public function register_menu() {
            // Only add as submenu if parent menu exists
            global $menu;
            $parent_exists = false;
            if (is_array($menu)) {
                foreach ($menu as $menu_item) {
                    if (isset($menu_item[2]) && $menu_item[2] === 'hotheart-wp-admin-utility-2') {
                        $parent_exists = true;
                        break;
                    }
                }
            }
            
            if ($parent_exists) {
                add_submenu_page(
                    'hotheart-wp-admin-utility-2',
                    '상품 AI 자동화',
                    '상품 AI 자동화',
                    'manage_options',
                    self::MENU_SLUG,
                    array($this, 'render_admin_page')
                );
            }
            // If parent doesn't exist, do not register menu at all
        }

        private function get_stats() {
            $all_ids = get_posts(array(
                'post_type' => 'product',
                'post_status' => array('publish', 'draft', 'pending'),
                'posts_per_page' => -1,
                'fields' => 'ids',
            ));
            $total = count($all_ids);

            $done_ids = get_posts(array(
                'post_type' => 'product',
                'post_status' => array('publish', 'draft', 'pending'),
                'meta_query' => array(
                    array('key' => '_product_ai_json_ld', 'compare' => 'EXISTS'),
                ),
                'posts_per_page' => -1,
                'fields' => 'ids',
            ));
            $done = count($done_ids);
            return array(
                'total' => $total,
                'done' => $done,
                'remains' => max(0, $total - $done),
            );
        }

        private function call_bridge($mode, $system, $content) {
            /**
             * Integration Note (2026-05-25):
             * Ollama is intentionally disabled by request.
             * Supported engines: gpt-hub-connector / gemini-hub-connector only.
             */
            if ($mode === 'gpt') {
                global $gpt_hub_bridge;
                if (!isset($gpt_hub_bridge) || !is_object($gpt_hub_bridge) || !method_exists($gpt_hub_bridge, 'generate')) {
                    return array('ok' => false, 'error' => 'GPT_BRIDGE_ERROR');
                }
                $res = $gpt_hub_bridge->generate(array(
                    'system' => $system,
                    'content' => $content,
                ));
                if (is_array($res) && isset($res['response']) && is_string($res['response'])) {
                    return array('ok' => true, 'text' => $res['response']);
                }
                return array('ok' => false, 'error' => is_array($res) && !empty($res['message']) ? $res['message'] : 'GPT_GENERATE_FAILED');
            }

            global $gemini_hub_bridge;
            if (!isset($gemini_hub_bridge) || !is_object($gemini_hub_bridge) || !method_exists($gemini_hub_bridge, 'generate')) {
                return array('ok' => false, 'error' => 'GEMINI_BRIDGE_ERROR');
            }
            $res = $gemini_hub_bridge->generate(array(
                'system' => $system,
                'content' => $content,
            ));
            if (is_array($res) && isset($res['response']) && is_string($res['response'])) {
                return array('ok' => true, 'text' => $res['response']);
            }
            return array('ok' => false, 'error' => is_array($res) && !empty($res['message']) ? $res['message'] : 'GEMINI_GENERATE_FAILED');
        }

        private function extract_json_object($text) {
            $text = (string) $text;
            if ($text === '') {
                return null;
            }
            if (preg_match('/\{(?:[^{}]|(?R))*\}/s', $text, $matches)) {
                $obj = json_decode(trim($matches[0]), true);
                if (is_array($obj)) {
                    return $obj;
                }
            }
            return null;
        }

        private function collect_product_context($target_id, $target_post, $product) {
            $product_description_html = get_post_meta($target_id, 'product_desc', true);
            if (!$product_description_html) {
                $product_description_html = $target_post->post_content;
            }
            $raw_content = wp_strip_all_tags((string) $product_description_html);

            $additional_properties = array();
            $brand_name = 'Generic';

            foreach ($product->get_attributes() as $attribute) {
                $raw_name = $attribute->get_name();
                $label_name = wc_attribute_label($raw_name);
                if (stripos($label_name, 'ASIN') !== false || stripos($label_name, 'UPC') !== false) {
                    continue;
                }

                $val = $attribute->is_taxonomy()
                    ? implode(', ', wp_get_post_terms($target_id, $raw_name, array('fields' => 'names')))
                    : implode(', ', $attribute->get_options());

                if (empty($val)) {
                    continue;
                }

                if (strtolower($label_name) === 'brand' || $label_name === '브랜드') {
                    $brand_name = $val;
                }

                $additional_properties[] = array(
                    '@type' => 'PropertyValue',
                    'name' => $label_name,
                    'value' => $val,
                );
            }

            return array($raw_content, $additional_properties, $brand_name);
        }

        public function ajax_save_settings() {
            if (!current_user_can('manage_options')) {
                wp_send_json_error('FORBIDDEN');
            }
            check_ajax_referer(self::NONCE_ACTION, 'nonce');

            $prompt = isset($_POST['prompt']) ? wp_unslash($_POST['prompt']) : '';
            $mode = isset($_POST['mode']) ? sanitize_key(wp_unslash($_POST['mode'])) : 'gemini';
            if (!in_array($mode, array('gemini', 'gpt'), true)) {
                $mode = 'gemini';
            }

            update_option(self::OPTION_PROMPT, $prompt);
            update_option(self::OPTION_MODE, $mode);
            wp_send_json_success(array('message' => 'SETTINGS_SAVED'));
        }

        public function ajax_process_single() {
            if (!current_user_can('manage_options')) {
                wp_send_json_error('FORBIDDEN');
            }
            check_ajax_referer(self::NONCE_ACTION, 'nonce');

            $system = (string) get_option(self::OPTION_PROMPT, '');
            $mode = (string) get_option(self::OPTION_MODE, 'gemini');
            if (!in_array($mode, array('gemini', 'gpt'), true)) {
                $mode = 'gemini';
            }

            $target = get_posts(array(
                'post_type' => 'product',
                'meta_query' => array(
                    array('key' => '_product_ai_json_ld', 'compare' => 'NOT EXISTS'),
                ),
                'posts_per_page' => 1,
                'post_status' => array('publish', 'draft'),
                'orderby' => 'ID',
                'order' => 'DESC',
            ));

            if (empty($target)) {
                wp_send_json_error('NO_TARGET_FOUND');
            }

            $target_id = (int) $target[0]->ID;
            $product = wc_get_product($target_id);
            if (!$product) {
                wp_send_json_error('PRODUCT_LOAD_FAILED');
            }

            list($raw_content, $additional_properties, $brand_name) = $this->collect_product_context($target_id, $target[0], $product);
            $actual_rating = (float) $product->get_average_rating();
            $actual_review_count = (int) $product->get_review_count();

            $full_context = "Product Title: " . $product->get_name() . "\n"
                . "Current Content: " . $raw_content . "\n\n[Technical Attributes]\n";
            foreach ($additional_properties as $prop) {
                $full_context .= $prop['name'] . ": " . $prop['value'] . "\n";
            }

            $bridge = $this->call_bridge($mode, $system, $full_context);
            if (!$bridge['ok']) {
                wp_send_json_error($bridge['error']);
            }

            $ai_json = $this->extract_json_object($bridge['text']);
            if (!$ai_json) {
                wp_send_json_error('JSON_PARSE_FAILED');
            }

            $meta_desc = !empty($ai_json['meta_description']) ? $ai_json['meta_description'] : (!empty($ai_json['description']) ? $ai_json['description'] : '');
            $image_id = $product->get_image_id();
            $featured_image_url = $image_id ? wp_get_attachment_url($image_id) : '';

            $schema_payload = array(
                '@context' => 'https://schema.org/',
                '@type' => 'Product',
                'name' => $product->get_name(),
                'description' => $meta_desc,
                'sku' => $product->get_sku() ? $product->get_sku() : 'SKU-' . $target_id,
                'offers' => array(
                    '@type' => 'Offer',
                    'price' => $product->get_price(),
                    'priceCurrency' => get_woocommerce_currency(),
                    'url' => get_permalink($target_id),
                    'itemCondition' => 'https://schema.org/NewCondition',
                    'availability' => 'https://schema.org/' . ($product->is_in_stock() ? 'InStock' : 'OutOfStock'),
                    'priceValidUntil' => date('Y-12-31'),
                ),
                'image' => $featured_image_url,
                'brand' => array(
                    '@type' => 'Brand',
                    'name' => $brand_name,
                ),
                'additionalProperty' => $additional_properties,
            );

            if ($actual_review_count > 0) {
                $schema_payload['aggregateRating'] = array(
                    '@type' => 'AggregateRating',
                    'ratingValue' => (string) $actual_rating,
                    'bestRating' => '5',
                    'worstRating' => '1',
                    'reviewCount' => $actual_review_count,
                );
            }

            $json_ld_string = wp_json_encode($schema_payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            update_post_meta($target_id, '_product_ai_json_ld', $json_ld_string);

            wp_send_json_success(array(
                'text' => "Engine: " . strtoupper($mode) . "\nProduct: " . $product->get_name() . "\nRating: " . $actual_rating . " (Reviews: " . $actual_review_count . ")",
                'json_ld' => $json_ld_string,
                'post_id' => $target_id,
                'stats' => $this->get_stats(),
            ));
        }

        public function render_schema_on_product() {
            if (!is_product()) {
                return;
            }
            $product_id = get_the_ID();
            $json_ld = get_post_meta($product_id, '_product_ai_json_ld', true);
            if (!$json_ld) {
                return;
            }

            $clean_json_ld = trim((string) $json_ld);
            if ($clean_json_ld !== '' && strpos($clean_json_ld, '{') === 0) {
                echo "\n<!-- Woo AI Optimized Schema Start -->\n";
                echo '<script type="application/ld+json" class="woo-ai-schema">' . $clean_json_ld . '</script>';
                echo "\n<!-- Woo AI Optimized Schema End -->\n";
            }
        }

        public function render_admin_page() {
            $stats = $this->get_stats();
            $saved_prompt = (string) get_option(self::OPTION_PROMPT, '');
            $ai_mode = (string) get_option(self::OPTION_MODE, 'gemini');
            if (!in_array($ai_mode, array('gemini', 'gpt'), true)) {
                $ai_mode = 'gemini';
            }
            ?>
            <div class="wrap">
                <div style="background:#fff;padding:30px;border-radius:12px;border:1px solid #ccd0d4;box-shadow:0 4px 15px rgba(0,0,0,0.07);width:95%;margin:20px auto;">
                    <h1 style="font-size:24px;font-weight:900;color:#2271b1;margin-bottom:25px;">AI Automator V26.6.8 (Schema Only / GPT+Gemini)</h1>

                    <div style="display:flex;gap:15px;margin-bottom:30px;">
                        <div style="flex:1;background:#f0f6ff;padding:20px;border-radius:10px;text-align:center;border:1px solid #d0e2ff;"><div style="font-size:12px;color:#555;">TOTAL PRODUCTS</div><strong style="font-size:24px;"><?php echo number_format($stats['total']); ?></strong></div>
                        <div style="flex:1;background:#e7f9ed;padding:20px;border-radius:10px;text-align:center;border:1px solid #c3e6cb;"><div style="font-size:12px;color:#555;">COMPLETED</div><strong id="stat_done" style="font-size:24px;color:#185a2b;"><?php echo number_format($stats['done']); ?></strong></div>
                        <div style="flex:1;background:#fff3cd;padding:20px;border-radius:10px;text-align:center;border:1px solid #ffeeba;"><div style="font-size:12px;color:#555;">WAITING</div><strong id="stat_remains" style="font-size:24px;color:#856404;"><?php echo number_format($stats['remains']); ?></strong></div>
                    </div>

                    <div style="display:flex;gap:25px;">
                        <div style="flex:1.5;">
                            <h3 style="margin-top:0;">Engine Prompt Settings</h3>
                            <textarea id="ai_system_prompt" style="width:100%;height:250px;font-family:'Consolas',monospace;padding:15px;border-radius:8px;border:1px solid #ddd;background:#fcfcfc;"><?php echo esc_textarea($saved_prompt); ?></textarea>
                            <button id="save_settings_btn" class="button button-secondary" style="margin-top:10px;width:100%;height:40px;">설정 저장</button>

                            <div style="margin-top:20px;display:flex;gap:15px;align-items:center;background:#f9f9f9;padding:20px;border-radius:10px;border:1px solid #eee;">
                                <div>
                                    <span style="font-size:12px;font-weight:bold;display:block;margin-bottom:5px;">AI 엔진 선택:</span>
                                    <label class="switch"><input type="checkbox" id="ai_mode_toggle" <?php checked($ai_mode, 'gpt'); ?>><span class="slider round"></span></label>
                                    <span id="ai_mode_label" style="font-weight:800;color:#1a73e8;margin-left:5px;"><?php echo esc_html(strtoupper($ai_mode)); ?></span>
                                </div>
                                <div style="font-size:12px;color:#666;">왼쪽(OFF)=GEMINI / 오른쪽(ON)=GPT</div>
                            </div>
                        </div>

                        <div style="flex:1;">
                            <h3 style="margin-top:0;">Execution Control</h3>
                            <div style="background:#fff;border:1px solid #ddd;padding:20px;border-radius:10px;">
                                <p style="margin-top:0;font-size:12px;color:#666;">처리할 상품 개수를 입력하세요:</p>
                                <input type="number" id="process_count" value="1" min="1" style="width:100%;height:50px;text-align:center;font-size:22px;font-weight:bold;margin-bottom:15px;border-radius:8px;">
                                <button id="start_btn" class="button button-primary" style="width:100%;height:60px;font-weight:bold;font-size:18px;border-radius:8px;box-shadow:0 4px 0 #005a9c;">배치 작업 시작</button>
                            </div>
                            <div id="log_win" style="margin-top:20px;background:#1e1e1e;color:#4af626;padding:15px;font-family:'Courier New',monospace;font-size:12px;border-radius:8px;height:165px;overflow-y:auto;">[READY] 시스템이 준비되었습니다.</div>
                        </div>
                    </div>

                    <div id="res_display" style="margin-top:30px;width:100%;min-height:200px;background:#252526;border:1px solid #333;border-radius:10px;padding:30px;color:#d4d4d4;overflow-y:auto;">작업을 시작하면 AI 분석 결과가 여기에 표시됩니다.</div>
                </div>
            </div>

            <style>
                .switch { position: relative; display: inline-block; width: 46px; height: 24px; vertical-align: middle; }
                .switch input { opacity: 0; width: 0; height: 0; }
                .slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #ccc; transition: .4s; border-radius: 34px; }
                .slider:before { position: absolute; content: ""; height: 18px; width: 18px; left: 3px; bottom: 3px; background-color: white; transition: .4s; border-radius: 50%; }
                input:checked + .slider { background-color: #2196F3; }
                input:checked + .slider:before { transform: translateX(22px); }
                .json-ld-preview { background: #1a1a1a; color: #9cdcfe; padding: 25px; border-radius: 8px; margin-top: 20px; border-left: 6px solid #ffae00; white-space: pre-wrap; word-break: break-all; }
                .report-section { background: #333; padding: 20px; border-radius: 8px; margin-bottom: 20px; border-left: 6px solid #2271b1; }
            </style>

            <script>
            jQuery(document).ready(function($) {
                const nonce = '<?php echo esc_js(wp_create_nonce(self::NONCE_ACTION)); ?>';

                function addLog(msg, color) {
                    color = color || '#4af626';
                    $('#log_win').append('<div style="color:' + color + '">[' + new Date().toLocaleTimeString() + '] ' + msg + '</div>');
                    $('#log_win').scrollTop($('#log_win')[0].scrollHeight);
                }

                function parseSafeJson(raw) {
                    const t = String(raw || '').trim();
                    const i1 = t.indexOf('{');
                    const i2 = t.indexOf('[');
                    let i = -1;
                    if (i1 >= 0 && i2 >= 0) i = Math.min(i1, i2);
                    else i = Math.max(i1, i2);
                    if (i < 0) throw new Error('JSON token not found');
                    return JSON.parse(t.slice(i));
                }

                function postSafe(payload) {
                    return $.ajax({
                        url: ajaxurl,
                        method: 'POST',
                        data: payload,
                        dataType: 'text'
                    }).then(function(raw) {
                        return parseSafeJson(raw);
                    });
                }

                function currentMode() {
                    return $('#ai_mode_toggle').is(':checked') ? 'gpt' : 'gemini';
                }

                function refreshModeLabel() {
                    $('#ai_mode_label').text(currentMode().toUpperCase());
                }
                refreshModeLabel();

                $('#ai_mode_toggle').on('change', refreshModeLabel);

                $('#save_settings_btn').on('click', function() {
                    postSafe({
                        action: 'save_woo_ai_v26_settings',
                        nonce: nonce,
                        prompt: $('#ai_system_prompt').val(),
                        mode: currentMode()
                    }).done(function() {
                        addLog('Settings Saved!', '#0af');
                        alert('설정이 저장되었습니다.');
                        location.reload();
                    }).fail(function() {
                        addLog('Settings save failed', '#f00');
                    });
                });

                const sleep = (ms) => new Promise(resolve => setTimeout(resolve, ms));

                async function runSingle() {
                    try {
                        const r = await postSafe({ action: 'call_woo_ai_v26_process', nonce: nonce });
                        if (r.success) {
                            let html = '<div class="report-section"><strong style="color:#569cd6;">[Schema.org Data Stored - ID: ' + r.data.post_id + ']</strong><br><br>' + r.data.text.replace(/\n/g, '<br>') + '</div>';
                            if (r.data.json_ld) {
                                html += '<div class="json-ld-preview"><strong>[JSON-LD Content]</strong><br>' + r.data.json_ld + '</div>';
                            }
                            $('#res_display').prepend(html);
                            $('#stat_done').text((r.data.stats.done || 0).toLocaleString());
                            $('#stat_remains').text((r.data.stats.remains || 0).toLocaleString());
                            addLog('SUCCESS: Product ID ' + r.data.post_id);
                            return true;
                        }
                        addLog('Error: ' + (r.data || 'UNKNOWN'), '#f00');
                        return false;
                    } catch (e) {
                        addLog('Connection Error', '#f00');
                        return false;
                    }
                }

                $('#start_btn').on('click', async function() {
                    const count = parseInt($('#process_count').val(), 10);
                    if (!count || count < 1) return;
                    $(this).prop('disabled', true).text('RUNNING...');
                    for (let i = 0; i < count; i++) {
                        const success = await runSingle();
                        if (!success) break;
                        if (i < count - 1) await sleep(5000);
                    }
                    $(this).prop('disabled', false).text('배치 작업 시작');
                    addLog('Batch Completed.', '#0af');
                });
            });
            </script>
            <?php
        }
    }
}

new Hotheart_Woo_AI_Automator_V268();

