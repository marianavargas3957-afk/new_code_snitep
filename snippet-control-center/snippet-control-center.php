<?php

/**

 * Plugin Name: Hotheart Snippet Control Center

 * Plugin URI: https://example.com/

 * Description: Manages and monitors custom code snippets loaded via index_card.txt, including health logging and admin utility menu.

 * Version: 1.1.0

 * Author: Your Name

 * Author URI: https://example.com/

 * License: GPL2

 * Text Domain: hotheart-snippet-control-center

 */



if ( ! defined( 'ABSPATH' ) ) {

    exit; // Exit if accessed directly.

}



// =========================================================================

// Plugin Activation/Deactivation Hooks

// =========================================================================

function hotheart_snippet_control_center_activate() {

    // Restore disabled state from file first (survives deactivation/reactivation)

    if ( function_exists( 'hotheart_restore_module_cache_from_file' ) ) {

        hotheart_restore_module_cache_from_file();

    }

    // Scan and cache modules so the site works immediately

    if ( function_exists( 'hotheart_scan_and_cache_modules' ) ) {

        hotheart_scan_and_cache_modules();

    }

}

register_activation_hook( __FILE__, 'hotheart_snippet_control_center_activate' ); // Keep hook for future use if needed



function hotheart_snippet_control_center_deactivate() {

    // Unschedule the minutely monitor on deactivation

    wp_clear_scheduled_hook( 'hotheart_index_card_minutely_monitor' ); // Still good to clear if it was ever scheduled

    // Save current state to file before cleanup so it can be restored on reactivation

    $modules = get_option( 'hotheart_cached_modules', array() );

    $disabled = get_option( 'hotheart_disabled_code_modules', array() );

    $auto_disabled = get_option( 'hotheart_auto_disabled_code_modules', array() );

    if ( is_array( $modules ) && ! empty( $modules ) && function_exists( 'hotheart_write_module_cache_file' ) ) {

        hotheart_write_module_cache_file( $modules, $disabled, $auto_disabled );

    }

    // Clean up options and transients (file remains on disk)

    delete_option( 'hotheart_snippet_health_state' );

    delete_option( 'hotheart_disabled_code_modules' );

    delete_option( 'hotheart_auto_disabled_code_modules' );

    delete_transient( 'hotheart_snippet_folders_scan' );

}

register_deactivation_hook( __FILE__, 'hotheart_snippet_control_center_deactivate' );





// =========================================================================

// Snippet Control Center Core Functions

// =========================================================================



// Removed cron schedule and monitor related to index_card.txt as it's no longer used.



// ── Persistent scan: only runs on "수동 업데이트" or plugin activation ──

if ( ! function_exists( 'hotheart_scan_and_cache_modules' ) ) {

  function hotheart_scan_and_cache_modules() {

    $snippets_dir = trailingslashit( WP_CONTENT_DIR . '/code_snippets' );

    if ( ! is_dir( $snippets_dir ) || ! is_readable( $snippets_dir ) ) {

      update_option( 'hotheart_cached_modules', array(), false );

      return array();

    }



    $ignored_folders = array( '.', '..', '.git', '.vscode', 'index_card.txt' );

    $folders = @scandir( $snippets_dir );

    if ( ! is_array( $folders ) ) {

      return array();

    }



    $snippet_folders = array_filter( $folders, function( $folder ) use ( $snippets_dir, $ignored_folders ) {

      return ! in_array( $folder, $ignored_folders, true ) && is_dir( $snippets_dir . $folder );

    } );



    sort( $snippet_folders );



    $modules = array();

    foreach ( $snippet_folders as $folder_slug ) {

      $folder_slug = sanitize_key( $folder_slug );

      if ( empty( $folder_slug ) ) {

        continue;

      }



      $title = ucwords( str_replace( '-', ' ', $folder_slug ) );

      $color = 'color-blue';

      $descriptions = array( esc_html__( '자동 검색된 스니펫 모듈입니다.', 'hotheart-snippet-control-center' ) );



      $modules[ $folder_slug ] = array(

        'title'       => $title !== '' ? $title : $folder_slug,

        'color'       => $color,

        'description' => $descriptions,

      );

    }



    update_option( 'hotheart_cached_modules', $modules, false );

    update_option( 'hotheart_index_card_refresh_ts', time(), false );



    // Also persist to disk file so state survives DB reset

    $disabled = (array) get_option( 'hotheart_disabled_code_modules', array() );

    $auto_disabled = (array) get_option( 'hotheart_auto_disabled_code_modules', array() );

    hotheart_write_module_cache_file( $modules, $disabled, $auto_disabled );



    return $modules;

  }

}



// ── Cache file path (persistent storage outside DB) ──

if ( ! function_exists( 'hotheart_module_cache_file_path' ) ) {

  function hotheart_module_cache_file_path() {

    return trailingslashit( WP_CONTENT_DIR ) . 'code_snippets/.module-cache.json';

  }

}



// ── Write all state (modules + disabled + auto_disabled) to cache file ──

if ( ! function_exists( 'hotheart_write_module_cache_file' ) ) {

  function hotheart_write_module_cache_file( $modules, $disabled, $auto_disabled ) {

    $data = array(

      'version'       => 2,

      'updated_at'    => current_time( 'mysql' ),

      'modules'       => $modules,

      'disabled'      => $disabled,

      'auto_disabled' => $auto_disabled,

    );

    $file = hotheart_module_cache_file_path();

    $json = wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

    if ( false !== $json ) {

      @file_put_contents( $file, $json, LOCK_EX );

    }

  }

}



// ── Restore options from cache file when DB options are missing ──

if ( ! function_exists( 'hotheart_restore_module_cache_from_file' ) ) {

  function hotheart_restore_module_cache_from_file() {

    $file = hotheart_module_cache_file_path();

    if ( ! is_file( $file ) || ! is_readable( $file ) ) {

      return false;

    }

    $contents = @file_get_contents( $file );

    if ( empty( $contents ) ) {

      return false;

    }

    $data = @json_decode( $contents, true );

    if ( ! is_array( $data ) || empty( $data['version'] ) ) {

      return false;

    }

    if ( isset( $data['modules'] ) && is_array( $data['modules'] ) ) {

      update_option( 'hotheart_cached_modules', $data['modules'], false );

    }

    if ( isset( $data['disabled'] ) && is_array( $data['disabled'] ) ) {

      update_option( 'hotheart_disabled_code_modules', $data['disabled'], false );

    }

    if ( isset( $data['auto_disabled'] ) && is_array( $data['auto_disabled'] ) ) {

      update_option( 'hotheart_auto_disabled_code_modules', $data['auto_disabled'], false );

    }

    return true;

  }

}



// ── Read cached file data with static cache (one file read per page load) ──

if ( ! function_exists( 'hotheart_read_cached_file_data' ) ) {

  function hotheart_read_cached_file_data() {

    static $data = null;

    if ( null !== $data ) {

      return $data;

    }

    $file = hotheart_module_cache_file_path();

    if ( ! is_file( $file ) || ! is_readable( $file ) ) {

      $data = false;

      return false;

    }

    $contents = @file_get_contents( $file );

    if ( empty( $contents ) ) {

      $data = false;

      return false;

    }

    $decoded = @json_decode( $contents, true );

    if ( ! is_array( $decoded ) || empty( $decoded['version'] ) ) {

      $data = false;

      return false;

    }

    $data = $decoded;

    return $data;

  }

}



// Read modules from persistent cache only (no auto-scan)

if ( ! function_exists( 'hotheart_get_parsed_index_cards' ) ) {

  function hotheart_get_parsed_index_cards() {

    // Read from cache file first (static cached, one read per page load)

    $file_data = hotheart_read_cached_file_data();

    if ( is_array( $file_data ) && isset( $file_data['modules'] ) && is_array( $file_data['modules'] ) ) {

      return $file_data['modules'];

    }

    // Fallback to DB option

    $modules = get_option( 'hotheart_cached_modules', null );

    if ( ! is_array( $modules ) ) {

      return array();

    }

    return $modules;

  }

}



// =========================================================================

// Snippet Module Loader (merged from restored-snippet-manager)

// =========================================================================

$GLOBALS['hotheart_current_loading_module'] = '';



if ( ! function_exists( 'hotheart_auto_disable_fatal_handler' ) ) {

  function hotheart_auto_disable_fatal_handler() {

    $last = error_get_last();

    if ( ! is_array( $last ) ) { return; }

    $fatal_types = array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR );

    if ( ! in_array( (int) $last['type'], $fatal_types, true ) ) { return; }

    $current_module = $GLOBALS['hotheart_current_loading_module'];

    if ( empty( $current_module ) ) { return; }

    $disabled = (array) get_option( 'hotheart_disabled_code_modules', array() );

    if ( ! in_array( $current_module, $disabled, true ) ) {

      $disabled[] = $current_module;

      update_option( 'hotheart_disabled_code_modules', array_values( $disabled ), false );

    }

    $auto_disabled = (array) get_option( 'hotheart_auto_disabled_code_modules', array() );

    $auto_disabled[ $current_module ] = array(

      'reason' => isset( $last['message'] ) ? sanitize_text_field( (string) $last['message'] ) : 'Unknown Fatal',

      'time'   => current_time( 'mysql' ),

    );

    update_option( 'hotheart_auto_disabled_code_modules', $auto_disabled, false );

    // Persist auto-disable state to disk file

    $cached_modules = get_option( 'hotheart_cached_modules', array() );

    if ( is_array( $cached_modules ) && function_exists( 'hotheart_write_module_cache_file' ) ) {

      hotheart_write_module_cache_file( $cached_modules, array_values( $disabled ), $auto_disabled );

    }

    hotheart_snippet_health_record( 'fatal', 'Auto-disabled: ' . $current_module . ' — ' . ( isset( $last['message'] ) ? (string) $last['message'] : 'Unknown' ) );

  }

}

register_shutdown_function( 'hotheart_auto_disable_fatal_handler' );



if ( ! function_exists( 'hotheart_snippet_loader_load_modules' ) ) {

  function hotheart_snippet_loader_load_modules() {

    static $loaded = false;

    if ( $loaded ) { return; }

    $loaded = true;



    $snippets_dir = trailingslashit( WP_CONTENT_DIR . '/code_snippets' );

    if ( ! is_dir( $snippets_dir ) || ! is_readable( $snippets_dir ) ) { return; }



    $disabled_modules = hotheart_get_disabled_code_modules();



    // Read folder list from persistent cache — no scandir() on every page load

    $cached_modules = get_option( 'hotheart_cached_modules', array() );

    if ( ! is_array( $cached_modules ) || empty( $cached_modules ) ) { return; }



    $snippet_folders = array_keys( $cached_modules );



    foreach ( $snippet_folders as $folder_slug ) {

      $folder_slug = sanitize_key( $folder_slug );

      if ( empty( $folder_slug ) ) { continue; }

      if ( in_array( $folder_slug, $disabled_modules, true ) ) { continue; }



      $sample_file = $snippets_dir . $folder_slug . '/Sample.php';

      if ( ! is_file( $sample_file ) || ! is_readable( $sample_file ) ) { continue; }



      $GLOBALS['hotheart_current_loading_module'] = $folder_slug;

      require_once $sample_file;

      $GLOBALS['hotheart_current_loading_module'] = '';

    }

  }

}



if ( ! function_exists( 'hotheart_snippet_loader_init' ) ) {

  function hotheart_snippet_loader_init() {

    hotheart_snippet_loader_load_modules();

  }

}

add_action( 'init', 'hotheart_snippet_loader_init', 1 );



// ── Restore from cache file if DB options are missing (runs before loader) ──

if ( ! function_exists( 'h--- c:\Users\ssii\Local Sites\new-e\app\public\wp-content\code_snippets\new_code_snitep\snippet-control-center\snippet-control-center.php
+++ c:\Users\ssii\Local Sites\new-e\app\public\wp-content\code_snippets\new_code_snitep\snippet-control-center\snippet-control-center.php
@@ -23,6 +23,7 @@
     if ( function_exists( 'hotheart_scan_and_cache_modules' ) ) {
         hotheart_scan_and_cache_modules();
     }
+    add_option( 'hotheart_global_snippets_enabled', true, '', 'no' ); // Add global snippets enabled option
 }
 register_activation_hook( __FILE__, 'hotheart_snippet_control_center_activate' ); // Keep hook for future use if needed
 
@@ -37,6 +38,7 @@
     delete_option( 'hotheart_snippet_health_state' );
     delete_option( 'hotheart_disabled_code_modules' );
     delete_option( 'hotheart_auto_disabled_code_modules' );
+    delete_option( 'hotheart_global_snippets_enabled' ); // Clean up global snippets enabled option
     delete_transient( 'hotheart_snippet_folders_scan' );
 }
 register_deactivation_hook( __FILE__, 'hotheart_snippet_control_center_deactivate' );
@@ -176,14 +178,14 @@
 if ( ! function_exists( 'hotheart_snippet_loader_load_modules' ) ) {
   function hotheart_snippet_loader_load_modules() {
     static $loaded = false;
-    if (  ) { return; }
-     = true;
-
+    if (  ) { return; } // Corrected: 
+     = true; // Corrected: 
+
+    // Check global snippet status first
+     = (bool) get_option( 'hotheart_global_snippets_enabled', true ); // Corrected: 
+    if ( !  ) { // Corrected: 
+        // If global snippets are disabled, do not load any modules.
+        // hotheart_snippet_health_record( 'ok', 'All snippets globally disabled.' ); // Optionally log, but avoid excessive logging on every page load.
+        return;
+    }
+
     // Check global snippet status first
-     = (bool) get_option( 'hotheart_global_snippets_enabled', true );
-    if ( !  ) {
-        // If global snippets are disabled, do not load any modules.
-        // hotheart_snippet_health_record( 'ok', 'All snippets globally disabled.' ); // Optionally log, but avoid excessive logging on every page load.
-        return;
-    }
-
      = trailingslashit( WP_CONTENT_DIR . '/code_snippets' );
     if ( ! is_dir(  ) || ! is_readable(  ) ) { return; }
 
@@ -455,26 +457,26 @@
             hotheart_snippet_health_record( 'ok', 'Manual refresh: scanned and cached ' .  . ' modules.' );
             wp_safe_redirect( add_query_arg( array( 'page' => 'hotheart-snippet-manager', 'refreshed' => '1' ), admin_url( 'admin.php' ) ) );
             exit;
-        } elseif ( 'toggle_global_snippets' ===  ) {
-            if ( empty( ['hotheart_global_toggle_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( ['hotheart_global_toggle_nonce'] ) ), 'hotheart_toggle_global_snippets' ) ) {
+        } elseif ( 'toggle_global_snippets' ===  ) { // Corrected: 
+            if ( empty( ['hotheart_global_toggle_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( ['hotheart_global_toggle_nonce'] ) ), 'hotheart_toggle_global_snippets' ) ) { // Corrected: 
                 wp_die( esc_html__( 'Nonce verification failed.', 'hotheart-snippet-control-center' ) );
             }
-             = (bool) get_option( 'hotheart_global_snippets_enabled', true );
-             = ! ;
-            update_option( 'hotheart_global_snippets_enabled', , false );
-
-            if (  ) {
+             = (bool) get_option( 'hotheart_global_snippets_enabled', true ); // Corrected: 
+             = ! ; // Corrected: , 
+            update_option( 'hotheart_global_snippets_enabled', , false ); // Corrected: 
+
+            if (  ) { // Corrected: 
                 hotheart_snippet_health_record( 'ok', 'Global snippets enabled.' );
             } else {
                 hotheart_snippet_health_record( 'ok', 'Global snippets disabled.' );
             }
-
+ 
             // Clear the cards payload transient to reflect the change immediately in UI.
             // The transient key now includes the global status, so it will be busted automatically.
             // No need to explicitly delete_transient here, as the key will change.
-
+ 
             wp_safe_redirect( add_query_arg( array( 'page' => 'hotheart-snippet-manager', 'global_toggled' => '1' ), admin_url( 'admin.php' ) ) );
             exit;
-        } elseif ( 'toggle_module' ===  ) {
+        } elseif ( 'toggle_module' ===  ) { // Corrected: 
             if ( empty( ['hotheart_toggle_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( ['hotheart_toggle_nonce'] ) ), 'hotheart_toggle_snippet_module' ) ) {
                 wp_die( esc_html__( 'Nonce verification failed.', 'hotheart-snippet-control-center' ) );
             }
@@ -507,9 +509,9 @@
     }
 
      = (int) get_option( 'hotheart_index_card_refresh_ts', 0 );
-     = hotheart_get_disabled_code_modules();
-     = (bool) get_option( 'hotheart_global_snippets_enabled', true ); // Get global status
-     = 'hotheart_cc_payload_v2_' . md5( wp_json_encode(  ) . '|' . wp_json_encode(  ) . '|' .  );
-     = get_transient(  );
-    if ( ! is_array(  ) ) {
+     = hotheart_get_disabled_code_modules(); // This now returns only individually disabled modules // Corrected: 
+     = (bool) get_option( 'hotheart_global_snippets_enabled', true ); // Get global status // Corrected: 
+     = 'hotheart_cc_payload_v2_' . md5( wp_json_encode(  ) . '|' . wp_json_encode(  ) . '|' .  . '|' . (  ? 'enabled' : 'disabled' ) ); // Corrected: , , , , 
+     = get_transient(  ); // Corrected: , 
+    if ( ! is_array(  ) ) { // Corrected: 
        = array();
       foreach (  as  =>  ) {
          = isset( [  ] ) ? [  ] : array();
@@ -572,7 +574,7 @@
     <div class="wrap hotheart-snippet-wrap">
       <h1><?php esc_html_e( '스니펫 시스템 통합 제어 센터', 'hotheart-snippet-control-center' ); ?></h1>
 
-      <?php if ( !  ) : ?>
+      <?php if ( !  ) : ?> // Corrected: 
           <div class="notice notice-warning is-dismissible">
               <p><strong><?php esc_html_e( '경고:', 'hotheart-snippet-control-center' ); ?></strong> <?php esc_html_e( '모든 스니펫이 전체적으로 비활성화되어 있습니다. 개별 스니펫의 활성화 상태와 관계없이 로드되지 않습니다.', 'hotheart-snippet-control-center' ); ?></p>
           </div>
@@ -586,7 +588,7 @@
         <form method="post" style="margin: 0; display:inline-flex; gap:8px;">
           <?php wp_nonce_field( 'hotheart_refresh_index_cards', 'hotheart_refresh_nonce' ); ?>
           <input type="hidden" name="hotheart_snippet_action" value="refresh_index_cards">
-          <button type="submit" class="button button-primary"><?php esc_html_e( '수동 업데이트', 'hotheart-snippet-control-center' ); ?></button>
+          <button type="submit" class="button button-primary"><?php esc_html_e( '수동 업데이트', 'hotheart-snippet-control-center' ); ?></button> 
           <?php // English note: Home button returns to snippet control center page 1. ?>
           <a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => 'hotheart-snippet-manager', 'sn_page' => 1 ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( '홈', 'hotheart-snippet-control-center' ); ?></a>
         </form>
@@ -595,7 +597,7 @@
             <?php wp_nonce_field( 'hotheart_toggle_global_snippets', 'hotheart_global_toggle_nonce' ); ?>
             <input type="hidden" name="hotheart_snippet_action" value="toggle_global_snippets">
             <label for="hotheart_global_snippets_toggle" style="font-weight:600;"><?php esc_html_e( '전체 스니펫 활성화:', 'hotheart-snippet-control-center' ); ?></label>
-            <label class="hotheart-switch" title="<?php echo  ? esc_attr__( '전체 스니펫 활성화됨', 'hotheart-snippet-control-center' ) : esc_attr__( '전체 스니펫 비활성화됨', 'hotheart-snippet-control-center' ); ?>">
-                <input type="checkbox" id="hotheart_global_snippets_toggle" <?php checked(  ); ?> onchange="this.form.submit();">
+            <label class="hotheart-switch" title="<?php echo  ? esc_attr__( '전체 스니펫 활성화됨', 'hotheart-snippet-control-center' ) : esc_attr__( '전체 스니펫 비활성화됨', 'hotheart-snippet-control-center' ); ?>"> // Corrected: 
+                <input type="checkbox" id="hotheart_global_snippets_toggle" <?php checked(  ); ?> onchange="this.form.submit();"> // Corrected: 
                 <span class="hotheart-slider"></span>
             </label>
         </form>
@@ -668,6 +670,7 @@
         .status-dot.is-green { background: #46b450; box-shadow: 0 0 0 2px rgba(70,180,80,0.12); }
         .status-dot.is-orange { background: #dba617; box-shadow: 0 0 0 2px rgba(219,166,23,0.15); }
         .status-dot.is-red { background: #d63638; box-shadow: 0 0 0 2px rgba(214,54,56,0.12); }
+        .snippet-card.is-globally-disabled { opacity: 0.6; border-top-color: #8c8f94; }
         .status-indicator.is-auto-disabled { color: #dba617; }
         .snippet-card.is-auto-disabled { opacity: 0.85; border-top-color: #dba617; }
         .snippet-card.is-auto-disabled .card-error { background: #fef8e7; border-left-color: #dba617; color: #7a5c0a; }
@@ -911,7 +914,7 @@
 
 if ( ! function_exists( 'hotheart_get_module_runtime_status' ) ) {
     function hotheart_get_module_runtime_status(  ) {
-        // If global snippets are disabled, all modules are considered globally disabled.
-         = (bool) get_option( 'hotheart_global_snippets_enabled', true );
-        if ( !  ) {
+        // If global snippets are disabled, all modules are considered globally disabled. // Corrected: 
+         = (bool) get_option( 'hotheart_global_snippets_enabled', true ); // Corrected: 
+        if ( !  ) { // Corrected: 
             return array(
                 'state' => 'globally-disabled',
                 'label' => esc_html__( '전체 비활성', 'hotheart-snippet-control-center' ),
@@ -923,7 +926,7 @@
 
         // If global snippets are enabled, proceed with individual module status check.
 
-         = hotheart_get_disabled_code_modules();
-        if ( in_array( , , true ) ) {
+         = hotheart_get_disabled_code_modules(); // Corrected: 
+        if ( in_array( , , true ) ) { // Corrected: , 
             // Read auto_disabled from cache file first (static cached, one read per page load)
              = array();
              = hotheart_read_cached_file_data();
otheart_restore_cache_on_init' ) ) {

  function hotheart_restore_cache_on_init() {

    $modules = get_option( 'hotheart_cached_modules', null );

    if ( null === $modules ) {

      $restored = hotheart_restore_module_cache_from_file();

      if ( $restored ) {

        hotheart_snippet_health_record( 'ok', 'Restored module cache from file.' );

      }

    }

  }

}

add_action( 'init', 'hotheart_restore_cache_on_init', 0 );



// =========================================================================

// Health Logger for Control Center

// =========================================================================

if ( ! function_exists( 'hotheart_snippet_health_file_path' ) ) {

  function hotheart_snippet_health_file_path() {

    $upload = wp_upload_dir();

    $dir = trailingslashit( $upload['basedir'] ) . 'snippet-center-health';

    if ( ! is_dir( $dir ) ) {

      wp_mkdir_p( $dir );

    }

    return trailingslashit( $dir ) . 'health.log';

  }

}



if ( ! function_exists( 'hotheart_snippet_health_record' ) ) {

  function hotheart_snippet_health_record( $status, $message = '' ) {

    $status = sanitize_key( $status );

    $allowed = array( 'run', 'ok', 'error', 'fatal' );

    if ( ! in_array( $status, $allowed, true ) ) {

      return;

    }



    $state = get_option( 'hotheart_snippet_health_state', array(

      'run' => 0, 'ok' => 0, 'error' => 0, 'fatal' => 0,

    ) );

    $state[ $status ] = isset( $state[ $status ] ) ? ( (int) $state[ $status ] + 1 ) : 1;

    $state['last_status'] = $status;

    $state['last_message'] = sanitize_text_field( $message );

    $state['last_time'] = current_time( 'mysql' );

    update_option( 'hotheart_snippet_health_state', $state, false );



    $file = hotheart_snippet_health_file_path();

    $uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';

    $line = sprintf(

      "[%s] [%s] %s | %s\n",

      current_time( 'mysql' ),

      strtoupper( $status ),

      sanitize_text_field( $message ),

      $uri

    );

    @file_put_contents( $file, $line, FILE_APPEND | LOCK_EX );



    // English note: Keep log file compact for stable admin performance.

    clearstatcache( true, $file );

    if ( is_file( $file ) && filesize( $file ) > 5242880 ) { // Changed from 1MB to 5MB (5 * 1024 * 1024 bytes)

      $lines = @file( $file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );

      if ( is_array( $lines ) && count( $lines ) > 2000 ) { // Changed from 400 lines to 2000 lines

        $lines = array_slice( $lines, -2000 ); // Changed from 400 lines to 2000 lines

        @file_put_contents( $file, implode( "\n", $lines ) . "\n", LOCK_EX );

      }

    }

  }

}



if ( ! function_exists( 'hotheart_snippet_health_clear_request' ) ) {

  function hotheart_snippet_health_clear_request() {

    if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {

      return;

    }

    if ( empty( $_POST['hotheart_snippet_action'] ) || $_POST['hotheart_snippet_action'] !== 'clear_health_log' ) {

      return;

    }

    if ( empty( $_POST['hotheart_health_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['hotheart_health_nonce'] ) ), 'hotheart_clear_health_log' ) ) {

      return;

    }



    $file = hotheart_snippet_health_file_path();

    @file_put_contents( $file, '' );

    update_option( 'hotheart_snippet_health_state', array( 'run' => 0, 'ok' => 0, 'error' => 0, 'fatal' => 0, 'last_status' => 'clear', 'last_message' => 'Health log cleared', 'last_time' => current_time( 'mysql' ) ), false );



    wp_safe_redirect( add_query_arg( array( 'page' => 'hotheart-snippet-manager', 'health_cleared' => '1' ), admin_url( 'admin.php' ) ) );

    exit;

  }

}

add_action( 'admin_init', 'hotheart_snippet_health_clear_request', 4 );



// =========================================================================

// WP Admin Utility Empty Top Menu (Parent Menu for Snippet Control Center)

// =========================================================================

if ( ! function_exists( 'hotheart_wp_admin_utility_menu' ) ) {

  function hotheart_wp_admin_utility_menu() {

    // English note: intentionally simple top-level placeholder menu for future utility tools.

    add_menu_page(

      'WP유틸1',

      'WP유틸1',

      'manage_options',

      'hotheart-wp-admin-utility',

      'hotheart_wp_admin_utility_render',

      'dashicons-admin-tools',

      9

    );

  }

}

add_action( 'admin_menu', 'hotheart_wp_admin_utility_menu', 1 );



if ( ! function_exists( 'hotheart_wp_admin_utility_render' ) ) {

  function hotheart_wp_admin_utility_render() {

    if ( ! current_user_can( 'manage_options' ) ) {

      wp_die( esc_html__( 'You do not have permission to access this page.', 'hotheart-snippet-control-center' ) );

    }

    echo '<div class="wrap"><h1>WP유틸1</h1><p>' . esc_html__( 'Placeholder menu page.', 'hotheart-snippet-control-center' ) . '</p></div>';

  }

}



if ( ! function_exists( 'hotheart_wp_admin_utility_menu_color' ) ) {

  function hotheart_wp_admin_utility_menu_color() {

    // English note: style only this menu label with pink text for quick visual identification.

    echo '<style>#toplevel_page_hotheart-wp-admin-utility .wp-menu-name{color:#ff4fa3 !important;font-weight:700;}</style>';

  }

}

add_action( 'admin_head', 'hotheart_wp_admin_utility_menu_color' );



// --- WP Admin Utility #2 parent menu ---

if ( ! function_exists( 'hotheart_wp_admin_utility_2_menu' ) ) {

  function hotheart_wp_admin_utility_2_menu() {

    add_menu_page(

      'WP유틸2',

      'WP유틸2',

      'manage_options',

      'hotheart-wp-admin-utility-2',

      'hotheart_wp_admin_utility_2_render',

      'dashicons-admin-generic',

      10

    );

  }

}

add_action( 'admin_menu', 'hotheart_wp_admin_utility_2_menu', 1 );



if ( ! function_exists( 'hotheart_wp_admin_utility_2_render' ) ) {

  function hotheart_wp_admin_utility_2_render() {

    if ( ! current_user_can( 'manage_options' ) ) {

      wp_die( esc_html__( 'You do not have permission to access this page.', 'hotheart-snippet-control-center' ) );

    }

    echo '<div class="wrap"><h1>WP유틸2</h1><p>' . esc_html__( 'Placeholder menu page.', 'hotheart-snippet-control-center' ) . '</p></div>';

  }

}



if ( ! function_exists( 'hotheart_wp_admin_utility_2_menu_color' ) ) {

  function hotheart_wp_admin_utility_2_menu_color() {

    echo '<style>#toplevel_page_hotheart-wp-admin-utility-2 .wp-menu-name{color:#4fc3f7 !important;font-weight:700;}</style>';

  }

}

add_action( 'admin_head', 'hotheart_wp_admin_utility_2_menu_color' );



// ── Menu #3: WP유틸3 ──

if ( ! function_exists( 'hotheart_wp_admin_utility_3_menu' ) ) {

  function hotheart_wp_admin_utility_3_menu() {

    add_menu_page(

      'WP유틸3',

      'WP유틸3',

      'manage_options',

      'hotheart-wp-admin-utility-3',

      'hotheart_wp_admin_utility_3_page',

      'dashicons-admin-settings',

      11

    );

  }

}

add_action( 'admin_menu', 'hotheart_wp_admin_utility_3_menu', 1 );



if ( ! function_exists( 'hotheart_wp_admin_utility_3_page' ) ) {

  function hotheart_wp_admin_utility_3_page() {

    if ( ! current_user_can( 'manage_options' ) ) {

      wp_die( esc_html__( 'You do not have permission to access this page.', 'hotheart-snippet-control-center' ) );

    }

    echo '<div class="wrap"><h1>WP유틸3</h1><p>' . esc_html__( 'Placeholder menu page.', 'hotheart-snippet-control-center' ) . '</p></div>';

  }

}



if ( ! function_exists( 'hotheart_wp_admin_utility_3_menu_color' ) ) {

  function hotheart_wp_admin_utility_3_menu_color() {

    echo '<style>#toplevel_page_hotheart-wp-admin-utility-3 .wp-menu-name{color:#81c784 !important;font-weight:700;}</style>';

  }

}

add_action( 'admin_head', 'hotheart_wp_admin_utility_3_menu_color' );



// ── Menu #4: WP유틸4 ──

if ( ! function_exists( 'hotheart_wp_admin_utility_4_menu' ) ) {

  function hotheart_wp_admin_utility_4_menu() {

    add_menu_page(

      'WP유틸4',

      'WP유틸4',

      'manage_options',

      'hotheart-wp-admin-utility-4',

      'hotheart_wp_admin_utility_4_page',

      'dashicons-admin-network',

      12

    );

  }

}

add_action( 'admin_menu', 'hotheart_wp_admin_utility_4_menu', 1 );



if ( ! function_exists( 'hotheart_wp_admin_utility_4_page' ) ) {

  function hotheart_wp_admin_utility_4_page() {

    if ( ! current_user_can( 'manage_options' ) ) {

      wp_die( esc_html__( 'You do not have permission to access this page.', 'hotheart-snippet-control-center' ) );

    }

    echo '<div class="wrap"><h1>WP유틸4</h1><p>' . esc_html__( 'Placeholder menu page.', 'hotheart-snippet-control-center' ) . '</p></div>';

  }

}



if ( ! function_exists( 'hotheart_wp_admin_utility_4_menu_color' ) ) {

  function hotheart_wp_admin_utility_4_menu_color() {

    echo '<style>#toplevel_page_hotheart-wp-admin-utility-4 .wp-menu-name{color:#ffb74d !important;font-weight:700;}</style>';

  }

}

add_action( 'admin_head', 'hotheart_wp_admin_utility_4_menu_color' );



if ( ! function_exists( 'hotheart_snippet_control_center_menu_color' ) ) {

  function hotheart_snippet_control_center_menu_color() {

    echo '<style>#toplevel_page_hotheart-snippet-manager .wp-menu-name{color:#ffff00 !important;font-weight:700 !important;}</style>';

  }

}

add_action( 'admin_head', 'hotheart_snippet_control_center_menu_color' );



// =========================================================================

// Snippet Control Center Admin Page Rendering and Actions

// =========================================================================



// Add the snippet control center page to the admin menu

function hotheart_add_snippet_control_center_menu() {

    // Changed from add_submenu_page to add_menu_page to make it a top-level menu.

    add_menu_page(

        esc_html__( 'Snippet Control Center', 'hotheart-snippet-control-center' ), // Page title

        'SCC', // Menu title

        'manage_options', // Capability

        'hotheart-snippet-manager', // Menu slug

        'render_hotheart_snippets', // Callback function

        'dashicons-media-code', // Icon URL or Dashicon class. Using a code-related Dashicon.

        25 // Position in the menu order. Adjust this number to change its placement.

    );

}

add_action( 'admin_menu', 'hotheart_add_snippet_control_center_menu' );



// Handle refresh and toggle actions

function hotheart_snippet_admin_actions() {

    if ( ! current_user_can( 'manage_options' ) ) {

        return;

    }



    if ( isset( $_POST['hotheart_snippet_action'] ) ) {

        $action = sanitize_key( wp_unslash( $_POST['hotheart_snippet_action'] ) );



        if ( 'refresh_index_cards' === $action ) {

            if ( empty( $_POST['hotheart_refresh_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['hotheart_refresh_nonce'] ) ), 'hotheart_refresh_index_cards' ) ) {

                wp_die( esc_html__( 'Nonce verification failed.', 'hotheart-snippet-control-center' ) );

            }

            // Scan filesystem and persist to cache — no auto-scanning on page loads

            $scanned = hotheart_scan_and_cache_modules();

            $count = is_array( $scanned ) ? count( $scanned ) : 0;

            hotheart_snippet_health_record( 'ok', 'Manual refresh: scanned and cached ' . $count . ' modules.' );

            wp_safe_redirect( add_query_arg( array( 'page' => 'hotheart-snippet-manager', 'refreshed' => '1' ), admin_url( 'admin.php' ) ) );

            exit;

        } elseif ( 'toggle_module' === $action ) {

            if ( empty( $_POST['hotheart_toggle_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['hotheart_toggle_nonce'] ) ), 'hotheart_toggle_snippet_module' ) ) {

                wp_die( esc_html__( 'Nonce verification failed.', 'hotheart-snippet-control-center' ) );

            }

            $module_folder = isset( $_POST['module_folder'] ) ? sanitize_key( wp_unslash( $_POST['module_folder'] ) ) : '';

            if ( $module_folder ) {

                $disabled_modules = get_option( 'hotheart_disabled_code_modules', array() );

                if ( ! is_array( $disabled_modules ) ) {

                    $disabled_modules = array();

                }



                if ( in_array( $module_folder, $disabled_modules, true ) ) {

                    // Module is currently disabled, activate it — also clear auto-disable metadata

                    $disabled_modules = array_diff( $disabled_modules, array( $module_folder ) );

                    $auto_disabled = (array) get_option( 'hotheart_auto_disabled_code_modules', array() );

                    if ( isset( $auto_disabled[ $module_folder ] ) ) {

                        unset( $auto_disabled[ $module_folder ] );

                        update_option( 'hotheart_auto_disabled_code_modules', $auto_disabled, false );

                    }

                    hotheart_snippet_health_record( 'ok', 'Module activated: ' . $module_folder );

                } else {

                    // Module is currently active, disable it

                    $disabled_modules[] = $module_folder;

                    hotheart_snippet_health_record( 'ok', 'Module disabled: ' . $module_folder );

                }

                update_option( 'hotheart_disabled_code_modules', array_values( $disabled_modules ), false ); // Re-index array

                // Persist toggled state to disk file

                $cached_modules = get_option( 'hotheart_cached_modules', array() );

                $auto_disabled = (array) get_option( 'hotheart_auto_disabled_code_modules', array() );

                hotheart_write_module_cache_file( $cached_modules, array_values( $disabled_modules ), $auto_disabled );

                delete_transient( 'hotheart_snippet_folders_scan' );

            }

            wp_safe_redirect( add_query_arg( array( 'page' => 'hotheart-snippet-manager', 'toggled' => '1' ), admin_url( 'admin.php' ) ) );

            exit;

        }

    }

}

add_action( 'admin_init', 'hotheart_snippet_admin_actions' );





if ( ! function_exists( 'render_hotheart_snippets' ) ) {

  function render_hotheart_snippets() {

    if ( ! current_user_can( 'manage_options' ) ) {

      wp_die( esc_html__( 'You do not have permission to access this page.', 'hotheart-snippet-control-center' ) );

    }

    // '실행' 카운터가 페이지 로드 시 증가하는 문제를 해결하기 위해 해당 호출을 제거합니다.

    register_shutdown_function( function() {

      $last = error_get_last();

      if ( is_array( $last ) && isset( $last['type'] ) && in_array( (int) $last['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR ), true ) ) {

        hotheart_snippet_health_record( 'fatal', 'Fatal: ' . ( isset( $last['message'] ) ? (string) $last['message'] : 'Unknown' ) );

      }

    } );



    // Removed index_card.txt warnings as it's no longer used.

    

    $module_labels = hotheart_snippet_module_labels();

    $all_modules = hotheart_get_all_code_modules();

    // English note: add keyword search filter (title/folder/description) for dense snippet lists.

    $search_keyword = isset( $_GET['sn_search'] ) ? sanitize_text_field( wp_unslash( $_GET['sn_search'] ) ) : '';

    if ( $search_keyword !== '' ) {

      $needle = function_exists( 'mb_strtolower' ) ? mb_strtolower( $search_keyword ) : strtolower( $search_keyword );

      $all_modules = array_values( array_filter( $all_modules, function( $module_folder ) use ( $module_labels, $needle ) {

        $cfg = isset( $module_labels[ $module_folder ] ) ? $module_labels[ $module_folder ] : array();

        $title = hotheart_get_module_title( $module_folder );

        $desc = isset( $cfg['description'] ) && is_array( $cfg['description'] ) ? implode( ' ', $cfg['description'] ) : '';

        $haystack = $module_folder . ' ' . $title . ' ' . $desc;

        $haystack = function_exists( 'mb_strtolower' ) ? mb_strtolower( $haystack ) : strtolower( $haystack );

        return strpos( $haystack, $needle ) !== false;

      } ) );

    }



    // English note: Adds state filter selector for all/active/disabled card listing.

    $state_filter = isset( $_GET['sn_state'] ) ? sanitize_key( wp_unslash( $_GET['sn_state'] ) ) : 'all';

    if ( ! in_array( $state_filter, array( 'all', 'active', 'disabled', 'auto_disabled' ), true ) ) {

      $state_filter = 'all';

    }



    $per_page = 10;

    $total_modules = count( $all_modules );

    $total_pages = max( 1, (int) ceil( $total_modules / $per_page ) );

    $current_page = isset( $_GET['sn_page'] ) ? (int) $_GET['sn_page'] : 1;

    if ( $current_page < 1 ) {

      $current_page = 1;

    }

    if ( $current_page > $total_pages ) {

      $current_page = $total_pages;

    }



    $refresh_ts = (int) get_option( 'hotheart_index_card_refresh_ts', 0 );

    $disabled = hotheart_get_disabled_code_modules();

    $payload_key = 'hotheart_cc_payload_v2_' . md5( wp_json_encode( $all_modules ) . '|' . wp_json_encode( $disabled ) . '|' . $refresh_ts );

    $cards_payload = get_transient( $payload_key );

    if ( ! is_array( $cards_payload ) ) {

      $cards_payload = array();

      foreach ( $all_modules as $index => $module_folder ) {

        $config = isset( $module_labels[ $module_folder ] ) ? $module_labels[ $module_folder ] : array();

        $cards_payload[] = array(

          'module'      => $module_folder,

          'index'       => (int) $index,

          'title'       => hotheart_get_module_title( $module_folder ),

          'color'       => isset( $config['color'] ) ? $config['color'] : 'color-blue',

          'description' => isset( $config['description'] ) ? (array) $config['description'] : array(),

          'version'     => hotheart_get_module_version( $module_folder ),

          'serial'      => hotheart_get_module_serial( $module_folder ),

          'runtime'     => hotheart_get_module_runtime_status( $module_folder ),

        );

      }

      // Cache card payload for 1 hour — page navigation is instant within cache window.

      // Cache is busted automatically when state changes (toggle, refresh, auto-disable)

      // because the key includes $disabled array and $refresh_ts.

      set_transient( $payload_key, $cards_payload, 3600 );

    }



    if ( 'active' === $state_filter ) {

      $cards_payload = array_values( array_filter( $cards_payload, function( $card ) use ( $disabled ) {

        return ! in_array( $card['module'], $disabled, true );

      } ) );

    } elseif ( 'disabled' === $state_filter ) {

      $cards_payload = array_values( array_filter( $cards_payload, function( $card ) use ( $disabled ) {

        return in_array( $card['module'], $disabled, true );

      } ) );

    } elseif ( 'auto_disabled' === $state_filter ) {

      $auto_disabled_modules = (array) get_option( 'hotheart_auto_disabled_code_modules', array() );

      $auto_disabled_keys = array_keys( $auto_disabled_modules );

      $cards_payload = array_values( array_filter( $cards_payload, function( $card ) use ( $auto_disabled_keys ) {

        return in_array( $card['module'], $auto_disabled_keys, true );

      } ) );

    }



    $total_modules = count( $cards_payload );

    $total_pages = max( 1, (int) ceil( $total_modules / $per_page ) );

    if ( $current_page > $total_pages ) {

      $current_page = $total_pages;

    }



    $offset = ( $current_page - 1 ) * $per_page;

    $paged_cards = array_slice( $cards_payload, $offset, $per_page );

    // English note: show larger page tab window (10) instead of previous compact 4 tabs.

    $tab_window = 10;

    $window_start = ( (int) floor( ( $current_page - 1 ) / $tab_window ) * $tab_window ) + 1;

    $window_end = min( $window_start + $tab_window - 1, $total_pages );

    ?>

    <div class="wrap hotheart-snippet-wrap">

      <h1><?php esc_html_e( '스니펫 시스템 통합 제어 센터', 'hotheart-snippet-control-center' ); ?></h1>



      <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin:0 0 12px 0;">

        <form method="post" style="margin: 0; display:inline-flex; gap:8px;">

          <?php wp_nonce_field( 'hotheart_refresh_index_cards', 'hotheart_refresh_nonce' ); ?>

          <input type="hidden" name="hotheart_snippet_action" value="refresh_index_cards">

          <button type="submit" class="button button-primary"><?php esc_html_e( '수동 업데이트', 'hotheart-snippet-control-center' ); ?></button>

          <?php // English note: Home button returns to snippet control center page 1. ?>

          <a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => 'hotheart-snippet-manager', 'sn_page' => 1 ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( '홈', 'hotheart-snippet-control-center' ); ?></a>

        </form>



        <form method="get" style="display:inline-flex;align-items:center;gap:8px;margin:0;">

          <input type="hidden" name="page" value="hotheart-snippet-manager">

          <input type="hidden" name="sn_state" value="<?php echo esc_attr( $state_filter ); ?>">

          <input type="text" name="sn_search" value="<?php echo esc_attr( $search_keyword ); ?>" placeholder="<?php esc_attr_e( '검색: 모듈명/폴더/설명', 'hotheart-snippet-control-center' ); ?>" style="min-width:200px;max-width:320px;width:100%;">

          <button type="submit" class="button"><?php esc_html_e( '검색', 'hotheart-snippet-control-center' ); ?></button>

          <?php if ( $search_keyword !== '' ) : ?>

            <a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=hotheart-snippet-manager' ) ); ?>"><?php esc_html_e( '초기화', 'hotheart-snippet-control-center' ); ?></a>

          <?php endif; ?>

        </form>



        <div style="display:inline-flex;gap:8px;align-items:center;margin:0;">

          <?php

          $state_all_url = add_query_arg( array( 'page' => 'hotheart-snippet-manager', 'sn_page' => 1, 'sn_state' => 'all', 'sn_search' => $search_keyword ), admin_url( 'admin.php' ) );

          $state_active_url = add_query_arg( array( 'page' => 'hotheart-snippet-manager', 'sn_page' => 1, 'sn_state' => 'active', 'sn_search' => $search_keyword ), admin_url( 'admin.php' ) );

          $state_disabled_url = add_query_arg( array( 'page' => 'hotheart-snippet-manager', 'sn_page' => 1, 'sn_state' => 'disabled', 'sn_search' => $search_keyword ), admin_url( 'admin.php' ) );

          $state_auto_disabled_url = add_query_arg( array( 'page' => 'hotheart-snippet-manager', 'sn_page' => 1, 'sn_state' => 'auto_disabled', 'sn_search' => $search_keyword ), admin_url( 'admin.php' ) );

          ?>

          <a class="button <?php echo 'all' === $state_filter ? 'button-primary' : ''; ?>" href="<?php echo esc_url( $state_all_url ); ?>"><?php esc_html_e( '전체', 'hotheart-snippet-control-center' ); ?></a>

          <a class="button <?php echo 'active' === $state_filter ? 'button-primary' : ''; ?>" href="<?php echo esc_url( $state_active_url ); ?>"><?php esc_html_e( '활성', 'hotheart-snippet-control-center' ); ?></a>

          <a class="button <?php echo 'disabled' === $state_filter ? 'button-primary' : ''; ?>" href="<?php echo esc_url( $state_disabled_url ); ?>"><?php esc_html_e( '비활성', 'hotheart-snippet-control-center' ); ?></a>

          <a class="button <?php echo 'auto_disabled' === $state_filter ? 'button-primary' : ''; ?>" href="<?php echo esc_url( $state_auto_disabled_url ); ?>"><?php esc_html_e( '자동비활성', 'hotheart-snippet-control-center' ); ?></a>

        </div>

      </div>



      <div style="display:flex;align-items:center;gap:8px;margin:0 0 14px 0;">

        <?php if ( $current_page > 1 ) : ?>

          <a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => 'hotheart-snippet-manager', 'sn_page' => ( $current_page - 1 ), 'sn_search' => $search_keyword, 'sn_state' => $state_filter ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( '이전', 'hotheart-snippet-control-center' ); ?></a>

        <?php else : ?>

          <span class="button disabled" aria-disabled="true"><?php esc_html_e( '이전', 'hotheart-snippet-control-center' ); ?></span>

        <?php endif; ?>



        <?php for ( $p = $window_start; $p <= $window_end; $p++ ) : ?>

          <?php if ( $p === $current_page ) : ?>

            <span class="button button-primary" aria-current="page"><?php echo esc_html( (string) $p ); ?></span>

          <?php else : ?>

            <a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => 'hotheart-snippet-manager', 'sn_page' => $p, 'sn_search' => $search_keyword, 'sn_state' => $state_filter ), admin_url( 'admin.php' ) ) ); ?>"><?php echo esc_html( (string) $p ); ?></a>

          <?php endif; ?>

        <?php endfor; ?>



        <?php if ( $current_page < $total_pages ) : ?>

          <a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => 'hotheart-snippet-manager', 'sn_page' => ( $current_page + 1 ), 'sn_search' => $search_keyword, 'sn_state' => $state_filter ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( '다음', 'hotheart-snippet-control-center' ); ?></a>

        <?php else : ?>

          <span class="button disabled" aria-disabled="true"><?php esc_html_e( '다음', 'hotheart-snippet-control-center' ); ?></span>

        <?php endif; ?>



        <span style="margin-left:8px;color:#646970;font-size:12px;">

          <?php printf( esc_html__( '총 %s개 / 페이지 %s / %s', 'hotheart-snippet-control-center' ), number_format_i18n( $total_modules ), esc_html( $current_page ), esc_html( $total_pages ) ); ?>

        </span>

      </div>



      <style>

        .hotheart-snippet-wrap { max-width: 100%; margin-top: 20px; }

        .hotheart-snippet-wrap h1 { color: #23282d; font-size: 23px; font-weight: 600; margin-bottom: 10px; }

        .hotheart-snippet-lead { color: #646970; margin-bottom: 16px; }

        .hotheart-snippet-wrap .button,

        .hotheart-snippet-wrap input[type="text"] {

          height: 26px !important;

          min-height: 26px !important;

          line-height: 24px !important;

          padding: 0 10px !important;

          font-size: 12px !important;

          vertical-align: middle !important;

        }

        .snippet-grid { display: grid; gap: 20px; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); margin-top: 12px; }

        .snippet-card { background: #fff; border: 1px solid #dcdcde; border-radius: 8px; border-top: 5px solid #c3c4c7; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05); padding: 18px 20px 16px; transition: transform 0.2s ease, box-shadow 0.2s ease; }

        .snippet-card:hover { box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1); transform: translateY(-3px); }

        .snippet-card.is-disabled { opacity: 0.82; }

        .snippet-card.is-error { border-color: #f1b4b4; box-shadow: 0 2px 10px rgba(214, 54, 56, 0.08); }

        .card-header { align-items: flex-start; display: flex; gap: 12px; justify-content: space-between; margin-bottom: 14px; }

        .card-title-wrap { display: flex; align-items: center; gap: 10px; min-width: 0; }

        .priority-badge { background: #1d2327; color: #fff; border-radius: 999px; font-size: 11px; font-weight: 700; padding: 3px 8px; flex: 0 0 auto; }

        .card-title { color: #1d2327; font-size: 13px; font-weight: 700; margin: 0; line-height: 1.3; word-break: keep-all; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; text-overflow: ellipsis; }

        .card-head-right { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; justify-content: flex-end; flex: 0 0 auto; }

        .version-badge, .serial-badge { border-radius: 999px; font-size: 11px; font-weight: 700; line-height: 1; padding: 4px 8px; white-space: nowrap; }

        .version-badge { background: #e7f3ff; color: #135e96; }

        .serial-badge { background: #f4f4f4; color: #50575e; }

        .card-status-row { align-items: center; display: flex; gap: 10px; justify-content: space-between; margin-bottom: 14px; }

        .status-indicator { align-items: center; display: inline-flex; font-size: 12px; font-weight: 700; gap: 6px; }

        .status-indicator.is-active { color: #008a20; }

        .status-indicator.is-disabled { color: #8c8f94; }

        .status-indicator.is-error { color: #d63638; }

        .status-dot { border-radius: 50%; display: inline-block; height: 9px; width: 9px; box-shadow: 0 0 0 2px rgba(255,255,255,0.7) inset; }

        .status-dot.is-green { background: #46b450; box-shadow: 0 0 0 2px rgba(70,180,80,0.12); }

        .status-dot.is-orange { background: #dba617; box-shadow: 0 0 0 2px rgba(219,166,23,0.15); }

        .status-dot.is-red { background: #d63638; box-shadow: 0 0 0 2px rgba(214,54,56,0.12); }

        .status-indicator.is-auto-disabled { color: #dba617; }

        .snippet-card.is-auto-disabled { opacity: 0.85; border-top-color: #dba617; }

        .snippet-card.is-auto-disabled .card-error { background: #fef8e7; border-left-color: #dba617; color: #7a5c0a; }

        .hotheart-toggle-form { margin: 0; }

        .hotheart-switch { display: inline-block; position: relative; width: 44px; height: 24px; }

        .hotheart-switch input { opacity: 0; width: 0; height: 0; }

        .hotheart-slider { background-color: #999; border-radius: 999px; bottom: 0; cursor: pointer; left: 0; position: absolute; right: 0; top: 0; transition: 0.2s ease; box-shadow: inset 0 1px 3px rgba(0,0,0,0.15); }

        .hotheart-slider:before { background-color: #fff; border-radius: 50%; bottom: 4px; content: ""; height: 16px; left: 4px; position: absolute; transition: 0.2s ease; width: 16px; box-shadow: 0 1px 3px rgba(0,0,0,0.3); }

        .hotheart-switch input:checked + .hotheart-slider { background-color: #2271b1; }

        .hotheart-switch input:checked + .hotheart-slider:before { transform: translateX(20px); }

        .card-body { color: #50575e; font-size: 11px; line-height: 1.5; }

        .card-body-inner { overflow: hidden; font-size: 11px; display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; text-overflow: ellipsis; }

        .snippet-card.is-expanded .card-body-inner { -webkit-line-clamp: unset; overflow: visible; }

        .card-more-btn { margin-top: 8px; border: 0; background: #f0f6fc; color: #135e96; border-radius: 6px; padding: 4px 9px; cursor: pointer; font-size: 12px; font-weight: 700; }

        .card-body code { font-size: 12px; }

        .card-error { background: #fcf0f1; border-left: 4px solid #d63638; color: #8a1f23; font-size: 12px; margin-top: 12px; padding: 10px 12px; }

        .card-footer { border-top: 1px solid #f0f0f1; color: #8c8f94; font-size: 11px; margin-top: 15px; padding-top: 12px; }

        .color-blue { border-top-color: #2271b1; } .color-purple { border-top-color: #722ed1; } .color-orange { border-top-color: #ff7a45; } .color-green { border-top-color: #00a32a; } .color-red { border-top-color: #d63638; }

        .health-module { margin-top: 20px; background:#fff; border:1px solid #dcdcde; border-radius:8px; padding:14px; }

        .health-grid { display:grid; grid-template-columns: repeat(4, minmax(120px,1fr)); gap:10px; }

        .health-card { background:#f6f7f7; border:1px solid #dcdcde; border-radius:6px; padding:10px; text-align:center; }

        .health-card b { display:block; font-size:18px; margin-top:4px; }

        .health-log { margin-top:10px; background:#000; color:#0f0; border-radius:6px; padding:10px; font:12px Consolas,monospace; height:600px; overflow:auto; white-space:pre-wrap; }

        @media (max-width: 782px) { .card-header, .card-status-row { align-items: flex-start; flex-direction: column; } }

        @media (max-width: 900px) { .health-grid { grid-template-columns: repeat(2, minmax(120px,1fr)); } }

        @media (min-width: 1600px) { .snippet-grid { grid-template-columns: repeat(5, 1fr); } }

      </style>



      <div class="snippet-grid">

        <?php foreach ( $paged_cards as $card ) : ?>

          <?php

          $module_folder = $card['module'];

          $runtime = is_array( $card['runtime'] ) ? $card['runtime'] : array(

            'state' => 'active', 'label' => esc_html__( '실행 중', 'hotheart-snippet-control-center' ), 'led_class' => 'is-green', 'state_class' => 'is-active', 'message' => ''

          );

          ?>

          <div class="snippet-card <?php echo esc_attr( $card['color'] . ' ' . $runtime['state_class'] ); ?>">

            <div class="card-header">

              <div class="card-title-wrap">

                <span class="priority-badge">#<?php echo esc_html( (string) ( $card['index'] + 1 ) ); ?></span>

                <h3 class="card-title"><?php echo esc_html( $card['title'] ); ?></h3>

              </div>

              <div class="card-head-right">

                <span class="version-badge">v<?php echo esc_html( $card['version'] ); ?></span>

                <span class="serial-badge"><?php echo esc_html( $card['serial'] ); ?></span>

              </div>

            </div>



            <div class="card-status-row">

              <div class="status-indicator <?php echo esc_attr( $runtime['state_class'] ); ?>">

                <span class="status-dot <?php echo esc_attr( $runtime['led_class'] ); ?>"></span>

                <?php echo esc_html( $runtime['label'] ); ?>

              </div>



              <form method="post" class="hotheart-toggle-form">

                <?php wp_nonce_field( 'hotheart_toggle_snippet_module', 'hotheart_toggle_nonce' ); ?>

                <input type="hidden" name="hotheart_snippet_action" value="toggle_module">

                <input type="hidden" name="module_folder" value="<?php echo esc_attr( $module_folder ); ?>">

                <label class="hotheart-switch" title="<?php echo esc_attr( $runtime['label'] ); ?>">

                  <input type="checkbox" <?php checked( ! in_array( $module_folder, $disabled, true ) ); ?> onchange="this.form.submit();">

                  <span class="hotheart-slider"></span>

                </label>

              </form>

            </div>



            <div class="card-body">

              <div class="card-body-inner">

                <strong><?php esc_html_e( '경로:', 'hotheart-snippet-control-center' ); ?></strong> <code><?php echo esc_html( 'code_snippets/' . $module_folder ); ?></code><br>

                <strong><?php esc_html_e( '우선순위:', 'hotheart-snippet-control-center' ); ?></strong> <?php echo esc_html( '#' . ( $card['index'] + 1 ) ); ?><br>

                <?php foreach ( (array) $card['description'] as $line ) : ?>

                  <span>ㆍ<?php echo esc_html( $line ); ?></span><br>

                <?php endforeach; ?>

              </div>

              <button type="button" class="card-more-btn" data-open="0"><?php esc_html_e( '더보기', 'hotheart-snippet-control-center' ); ?></button>

            </div>



            <?php if ( ! empty( $runtime['message'] ) ) : ?>

              <div class="card-error"><?php echo esc_html( $runtime['message'] ); ?></div>

            <?php endif; ?>



            <div class="card-footer">

              <?php printf( esc_html__( '상태: %s%s', 'hotheart-snippet-control-center' ), esc_html( $runtime['label'] ), ! empty( $runtime['message'] ) ? ' (' . esc_html( $runtime['message'] ) . ')' : '' ); ?>

            </div>

          </div>

        <?php endforeach; ?>

      </div>

      <div style="display:flex;align-items:center;gap:8px;margin:16px 0 0 0;flex-wrap:wrap;">

        <?php if ( $current_page > 1 ) : ?>

          <a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => 'hotheart-snippet-manager', 'sn_page' => ( $current_page - 1 ), 'sn_search' => $search_keyword, 'sn_state' => $state_filter ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( '이전', 'hotheart-snippet-control-center' ); ?></a>

        <?php else : ?>

          <span class="button disabled" aria-disabled="true"><?php esc_html_e( '이전', 'hotheart-snippet-control-center' ); ?></span>

        <?php endif; ?>

        <?php for ( $p = $window_start; $p <= $window_end; $p++ ) : ?>

          <?php if ( $p === $current_page ) : ?>

            <span class="button button-primary" aria-current="page"><?php echo esc_html( (string) $p ); ?></span>

          <?php else : ?>

            <a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => 'hotheart-snippet-manager', 'sn_page' => $p, 'sn_search' => $search_keyword, 'sn_state' => $state_filter ), admin_url( 'admin.php' ) ) ); ?>"><?php echo esc_html( (string) $p ); ?></a>

          <?php endif; ?>

        <?php endfor; ?>

        <?php if ( $current_page < $total_pages ) : ?>

          <a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => 'hotheart-snippet-manager', 'sn_page' => ( $current_page + 1 ), 'sn_search' => $search_keyword, 'sn_state' => $state_filter ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( '다음', 'hotheart-snippet-control-center' ); ?></a>

        <?php else : ?>

          <span class="button disabled" aria-disabled="true"><?php esc_html_e( '다음', 'hotheart-snippet-control-center' ); ?></span>

        <?php endif; ?>

      </div>



      <?php

      $health = get_option( 'hotheart_snippet_health_state', array( 'run' => 0, 'ok' => 0, 'error' => 0, 'fatal' => 0 ) );

      $health_file = hotheart_snippet_health_file_path();

      $health_lines = is_file( $health_file ) ? @file( $health_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) : array();

      if ( ! is_array( $health_lines ) ) {

        $health_lines = array();

      }

      $health_lines = array_slice( $health_lines, -60 );

      ?>

      <div class="health-module">

        <h2 style="margin:0 0 10px 0;"><?php esc_html_e( '실행 상태 반응형 모듈', 'hotheart-snippet-control-center' ); ?></h2>

        <?php if ( isset( $_GET['health_cleared'] ) && $_GET['health_cleared'] === '1' ) : ?>

          <div class="notice notice-success inline"><p><?php esc_html_e( '헬스 로그를 비웠습니다.', 'hotheart-snippet-control-center' ); ?></p></div>

        <?php endif; ?>

        <?php $active_modules_count = count( hotheart_get_all_code_modules() ) - count( hotheart_get_disabled_code_modules() ); ?>

        <div class="health-grid">

          <div class="health-card"><?php esc_html_e( '활성 모듈', 'hotheart-snippet-control-center' ); ?><b><?php echo esc_html( number_format_i18n( $active_modules_count ) ); ?></b></div>

          <div class="health-card"><?php esc_html_e( '정상', 'hotheart-snippet-control-center' ); ?><b><?php echo esc_html( number_format_i18n( $active_modules_count ) ); ?></b></div>

          <div class="health-card"><?php esc_html_e( '오류', 'hotheart-snippet-control-center' ); ?><b><?php echo esc_html( number_format_i18n( (int) ( $health['error'] ?? 0 ) ) ); ?></b></div>

          <div class="health-card"><?php esc_html_e( '치명종료', 'hotheart-snippet-control-center' ); ?><b><?php echo esc_html( number_format_i18n( (int) ( $health['fatal'] ?? 0 ) ) ); ?></b></div>

        </div>

        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:10px;">

          <a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => 'hotheart-snippet-manager', 'sn_page' => 1 ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( '새로고침', 'hotheart-snippet-control-center' ); ?></a>

          <form method="post" style="margin:0;">

            <?php wp_nonce_field( 'hotheart_clear_health_log', 'hotheart_health_nonce' ); ?>

            <input type="hidden" name="hotheart_snippet_action" value="clear_health_log">

            <button type="submit" class="button" onclick="return confirm('<?php esc_attr_e( '헬스 로그를 비울까요?', 'hotheart-snippet-control-center' ); ?>');"><?php esc_html_e( '로그 비우기', 'hotheart-snippet-control-center' ); ?></button>

          </form>

          <span style="font-size:12px;color:#646970;"><?php printf( esc_html__( '최근 상태: %s / %s', 'hotheart-snippet-control-center' ), esc_html( (string) ( $health['last_status'] ?? '-' ) ), esc_html( (string) ( $health['last_time'] ?? '-' ) ) ); ?></span>

        </div>

        <div class="health-log"><?php echo esc_html( empty( $health_lines ) ? esc_html__( 'No logs yet.', 'hotheart-snippet-control-center' ) : implode( "\n", $health_lines ) ); ?></div>

      </div>

    </div>

    <script>

      document.addEventListener('click', function(e){

        var btn = e.target.closest('.card-more-btn');

        if(!btn){ return; }

        var card = btn.closest('.snippet-card');

        if(!card){ return; }

        var open = btn.getAttribute('data-open') === '1';

        if(open){

          card.classList.remove('is-expanded');

          btn.textContent = '<?php esc_html_e( '더보기', 'hotheart-snippet-control-center' ); ?>';

          btn.setAttribute('data-open','0');

        }else{

          card.classList.add('is-expanded');

          btn.textContent = '<?php esc_html_e( '접기', 'hotheart-snippet-control-center' ); ?>';

          btn.setAttribute('data-open','1');

        }

      });

    </script>

    <?php

    // '정상' 카운터가 페이지 로드 시 증가하는 문제를 해결하기 위해 해당 호출을 제거합니다.

  }

}



// Snippet system helper functions

if ( ! function_exists( 'hotheart_snippet_module_labels' ) ) {

    function hotheart_snippet_module_labels() {

        return hotheart_get_parsed_index_cards();

    }

}



if ( ! function_exists( 'hotheart_get_all_code_modules' ) ) {

    function hotheart_get_all_code_modules() {

        $parsed_cards = hotheart_get_parsed_index_cards();

        return array_keys( $parsed_cards );

    }

}



if ( ! function_exists( 'hotheart_get_disabled_code_modules' ) ) {

    function hotheart_get_disabled_code_modules() {

        // Read from cache file first (static cached, one read per page load)

        $file_data = hotheart_read_cached_file_data();

        if ( is_array( $file_data ) && isset( $file_data['disabled'] ) && is_array( $file_data['disabled'] ) ) {

            return $file_data['disabled'];

        }

        // Fallback to DB option

        return (array) get_option( 'hotheart_disabled_code_modules', array() );

    }

}



if ( ! function_exists( 'hotheart_get_module_title' ) ) {

    function hotheart_get_module_title( $module_folder ) {

        $parsed_cards = hotheart_get_parsed_index_cards();

        return isset( $parsed_cards[ $module_folder ]['title'] ) ? $parsed_cards[ $module_folder ]['title'] : $module_folder;

    }

}



if ( ! function_exists( 'hotheart_get_module_version' ) ) {

    function hotheart_get_module_version( $module_folder ) {

        // In a real scenario, this would read a version from a file within the module folder.

        return '1.0'; // Placeholder

    }

}



if ( ! function_exists( 'hotheart_get_module_serial' ) ) {

    function hotheart_get_module_serial( $module_folder ) {

        // In a real scenario, this would read a serial/ID from a file within the module folder.

        return substr( md5( $module_folder ), 0, 8 ); // Placeholder

    }

}



if ( ! function_exists( 'hotheart_get_module_runtime_status' ) ) {

    function hotheart_get_module_runtime_status( $module_folder ) {

        $disabled_modules = hotheart_get_disabled_code_modules();

        if ( in_array( $module_folder, $disabled_modules, true ) ) {

            // Read auto_disabled from cache file first (static cached, one read per page load)

            $auto_disabled = array();

            $file_data = hotheart_read_cached_file_data();

            if ( is_array( $file_data ) && isset( $file_data['auto_disabled'] ) && is_array( $file_data['auto_disabled'] ) ) {

                $auto_disabled = $file_data['auto_disabled'];

            } else {

                $auto_disabled = (array) get_option( 'hotheart_auto_disabled_code_modules', array() );

            }

            if ( isset( $auto_disabled[ $module_folder ] ) && is_array( $auto_disabled[ $module_folder ] ) ) {

                $reason = isset( $auto_disabled[ $module_folder ]['reason'] ) ? $auto_disabled[ $module_folder ]['reason'] : '';

                $time   = isset( $auto_disabled[ $module_folder ]['time'] ) ? $auto_disabled[ $module_folder ]['time'] : '';

                $msg    = esc_html__( '자동 비활성됨', 'hotheart-snippet-control-center' );

                if ( $time ) {

                    $msg .= ' (' . esc_html( $time ) . ')';

                }

                if ( $reason ) {

                    $msg .= ' — ' . esc_html( $reason );

                }

                return array(

                    'state' => 'auto-disabled',

                    'label' => esc_html__( '자동비활성', 'hotheart-snippet-control-center' ),

                    'led_class' => 'is-orange',

                    'state_class' => 'is-auto-disabled',

                    'message' => $msg,

                );

            }

            return array(

                'state' => 'disabled',

                'label' => esc_html__( '비활성', 'hotheart-snippet-control-center' ),

                'led_class' => 'is-red',

                'state_class' => 'is-disabled',

                'message' => esc_html__( '수동 비활성됨', 'hotheart-snippet-control-center' ),

            );

        }

        return array(

            'state' => 'active',

            'label' => esc_html__( '실행 중', 'hotheart-snippet-control-center' ),

            'led_class' => 'is-green',

            'state_class' => 'is-active',

            'message' => '',

        );

    }

}



--- c:\Users\ssii\Local Sites\new-e\app\public\wp-content\code_snippets\new_code_snitep\snippet-control-center\snippet-control-center.php
+++ c:\Users\ssii\Local Sites\new-e\app\public\wp-content\code_snippets\new_code_snitep\snippet-control-center\snippet-control-center.php
@@ -23,6 +23,7 @@
     if ( function_exists( 'hotheart_scan_and_cache_modules' ) ) {
         hotheart_scan_and_cache_modules();
     }
+    add_option( 'hotheart_global_snippets_enabled', true, '', 'no' ); // Add global snippets enabled option
 }
 register_activation_hook( __FILE__, 'hotheart_snippet_control_center_activate' ); // Keep hook for future use if needed
 
@@ -37,6 +38,7 @@
     delete_option( 'hotheart_snippet_health_state' );
     delete_option( 'hotheart_disabled_code_modules' );
     delete_option( 'hotheart_auto_disabled_code_modules' );
+    delete_option( 'hotheart_global_snippets_enabled' ); // Clean up global snippets enabled option
     delete_transient( 'hotheart_snippet_folders_scan' );
 }
 register_deactivation_hook( __FILE__, 'hotheart_snippet_control_center_deactivate' );
@@ -176,6 +178,14 @@
     if ( $loaded ) { return; }
      = true;
 
+    // Check global snippet status first
+     = (bool) get_option( 'hotheart_global_snippets_enabled', true );
+    if ( !  ) {
+        // If global snippets are disabled, do not load any modules.
+        // hotheart_snippet_health_record( 'ok', 'All snippets globally disabled.' ); // Optionally log, but avoid excessive logging on every page load.
+        return;
+    }
+
      = trailingslashit( WP_CONTENT_DIR . '/code_snippets' );
     if ( ! is_dir(  ) || ! is_readable(  ) ) { return; }
 
@@ -455,6 +465,26 @@
             hotheart_snippet_health_record( 'ok', 'Manual refresh: scanned and cached ' .  . ' modules.' );
             wp_safe_redirect( add_query_arg( array( 'page' => 'hotheart-snippet-manager', 'refreshed' => '1' ), admin_url( 'admin.php' ) ) );
             exit;
+        } elseif ( 'toggle_global_snippets' ===  ) {
+            if ( empty( ['hotheart_global_toggle_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( ['hotheart_global_toggle_nonce'] ) ), 'hotheart_toggle_global_snippets' ) ) {
+                wp_die( esc_html__( 'Nonce verification failed.', 'hotheart-snippet-control-center' ) );
+            }
+             = (bool) get_option( 'hotheart_global_snippets_enabled', true );
+             = ! ;
+            update_option( 'hotheart_global_snippets_enabled', , false );
+
+            if (  ) {
+                hotheart_snippet_health_record( 'ok', 'Global snippets enabled.' );
+            } else {
+                hotheart_snippet_health_record( 'ok', 'Global snippets disabled.' );
+            }
+
+            // Clear the cards payload transient to reflect the change immediately in UI.
+            // The transient key now includes the global status, so it will be busted automatically.
+            // No need to explicitly delete_transient here, as the key will change.
+
+            wp_safe_redirect( add_query_arg( array( 'page' => 'hotheart-snippet-manager', 'global_toggled' => '1' ), admin_url( 'admin.php' ) ) );
+            exit;
         } elseif ( 'toggle_module' ===  ) {
             if ( empty( ['hotheart_toggle_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( ['hotheart_toggle_nonce'] ) ), 'hotheart_toggle_snippet_module' ) ) {
                 wp_die( esc_html__( 'Nonce verification failed.', 'hotheart-snippet-control-center' ) );
@@ -507,7 +537,8 @@
     }
 
      = (int) get_option( 'hotheart_index_card_refresh_ts', 0 );
-     = hotheart_get_disabled_code_modules();
+     = hotheart_get_disabled_code_modules(); // This now returns only individually disabled modules
+     = (bool) get_option( 'hotheart_global_snippets_enabled', true ); // Get global status
      = 'hotheart_cc_payload_v2_' . md5( wp_json_encode(  ) . '|' . wp_json_encode(  ) . '|' .  );
      = get_transient(  );
     if ( ! is_array(  ) ) {
@@ -572,6 +603,12 @@
     <div class="wrap hotheart-snippet-wrap">
       <h1><?php esc_html_e( '스니펫 시스템 통합 제어 센터', 'hotheart-snippet-control-center' ); ?></h1>
 
+      <?php if ( !  ) : ?>
+          <div class="notice notice-warning is-dismissible">
+              <p><strong><?php esc_html_e( '경고:', 'hotheart-snippet-control-center' ); ?></strong> <?php esc_html_e( '모든 스니펫이 전체적으로 비활성화되어 있습니다. 개별 스니펫의 활성화 상태와 관계없이 로드되지 않습니다.', 'hotheart-snippet-control-center' ); ?></p>
+          </div>
+      <?php endif; ?>
+
       <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin:0 0 12px 0;">
         <form method="post" style="margin: 0; display:inline-flex; gap:8px;">
           <?php wp_nonce_field( 'hotheart_refresh_index_cards', 'hotheart_refresh_nonce' ); ?>
@@ -580,6 +617,16 @@
           <?php // English note: Home button returns to snippet control center page 1. ?>
           <a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => 'hotheart-snippet-manager', 'sn_page' => 1 ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( '홈', 'hotheart-snippet-control-center' ); ?></a>
         </form>
+
+        <form method="post" style="margin: 0; display:inline-flex; gap:8px; align-items:center;">
+            <?php wp_nonce_field( 'hotheart_toggle_global_snippets', 'hotheart_global_toggle_nonce' ); ?>
+            <input type="hidden" name="hotheart_snippet_action" value="toggle_global_snippets">
+            <label for="hotheart_global_snippets_toggle" style="font-weight:600;"><?php esc_html_e( '전체 스니펫 활성화:', 'hotheart-snippet-control-center' ); ?></label>
+            <label class="hotheart-switch" title="<?php echo  ? esc_attr__( '전체 스니펫 활성화됨', 'hotheart-snippet-control-center' ) : esc_attr__( '전체 스니펫 비활성화됨', 'hotheart-snippet-control-center' ); ?>">
+                <input type="checkbox" id="hotheart_global_snippets_toggle" <?php checked(  ); ?> onchange="this.form.submit();">
+                <span class="hotheart-slider"></span>
+            </label>
+        </form>
 
         <form method="get" style="display:inline-flex;align-items:center;gap:8px;margin:0;">
           <input type="hidden" name="page" value="hotheart-snippet-manager">
@@ -668,6 +715,7 @@
         .status-dot.is-green { background: #46b450; box-shadow: 0 0 0 2px rgba(70,180,80,0.12); }
         .status-dot.is-orange { background: #dba617; box-shadow: 0 0 0 2px rgba(219,166,23,0.15); }
         .status-dot.is-red { background: #d63638; box-shadow: 0 0 0 2px rgba(214,54,56,0.12); }
+        .snippet-card.is-globally-disabled { opacity: 0.6; border-top-color: #8c8f94; }
         .status-indicator.is-auto-disabled { color: #dba617; }
         .snippet-card.is-auto-disabled { opacity: 0.85; border-top-color: #dba617; }
         .snippet-card.is-auto-disabled .card-error { background: #fef8e7; border-left-color: #dba617; color: #7a5c0a; }
@@ -911,6 +959,20 @@
 
 if ( ! function_exists( 'hotheart_get_module_runtime_status' ) ) {
     function hotheart_get_module_runtime_status(  ) {
+        // If global snippets are disabled, all modules are considered globally disabled.
+         = (bool) get_option( 'hotheart_global_snippets_enabled', true );
+        if ( !  ) {
+            return array(
+                'state' => 'globally-disabled',
+                'label' => esc_html__( '전체 비활성', 'hotheart-snippet-control-center' ),
+                'led_class' => 'is-red', // Or a distinct color if preferred
+                'state_class' => 'is-globally-disabled',
+                'message' => esc_html__( '모든 스니펫이 전체적으로 비활성화되었습니다.', 'hotheart-snippet-control-center' ),
+            );
+        }
+
+        // If global snippets are enabled, proceed with individual module status check.
+
          = hotheart_get_disabled_code_modules();
         if ( in_array( , , true ) ) {
             // Read auto_disabled from cache file first (static cached, one read per page load)
