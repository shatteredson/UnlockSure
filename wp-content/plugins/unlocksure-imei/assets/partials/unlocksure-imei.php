<?php
/**
 * Plugin Name: UnlockSure IMEI Checker
 * Description: IMEI checker REST endpoint + shortcode. Uses external IMEI API (API key/service ID are configurable). Includes simulated mode if API key is not set.
 * Version: 0.1
 * Author: UnlockSure
 */


if (! defined('ABSPATH')) exit;


// ----------------------
// Constants / defaults
// ----------------------
if (! defined('UNLOCKSURE_IMEI_CACHE_TTL')) define('UNLOCKSURE_IMEI_CACHE_TTL', 24 * HOUR_IN_SECONDS); // 24 hours
if (! defined('UNLOCKSURE_IMEI_RATE_LIMIT_PER_HOUR')) define('UNLOCKSURE_IMEI_RATE_LIMIT_PER_HOUR', 60); // default 60 requests/hour per IP


// ----------------------
// Register REST route
// ----------------------
add_action('rest_api_init', function() {
    register_rest_route('unlocksure/v1', '/check-imei', array(
        'methods' => 'POST',
        'callback' => 'unlocksure_check_imei_handler',
        'permission_callback' => '__return_true',
    ));
});


// ----------------------
// IMEI Luhn validator
// ----------------------
function unlocksure_is_valid_imei($imei) {
    $imei = preg_replace('/\D/', '', $imei);
    if (strlen($imei) !== 15) return false;


    $sum = 0;
    // Luhn: process from rightmost digit
    for ($i = 14; $i >= 0; $i--) {
        $digit = intval($imei[$i]);
        $posFromRight = 14 - $i + 1; // 1-based
        if ($posFromRight % 2 == 0) { // every 2nd from right
            $digit *= 2;
            if ($digit > 9) $digit -= 9;
        }
        $sum += $digit;
    }
    return ($sum % 10) === 0;
}


// ----------------------
// Helper: get API config (either constant or option)
// ----------------------
function unlocksure_get_api_settings() {
    $api_key = '';
    $service_id = '';
    $api_base = '';


    if (defined('UNLOCKSURE_IMEI_API_KEY')) {
        $api_key = UNLOCKSURE_IMEI_API_KEY;
    } else {
        $api_key = get_option('unlocksure_api_key', '');
    }


    if (defined('UNLOCKSURE_IMEI_SERVICE_ID')) {
        $service_id = UNLOCKSURE_IMEI_SERVICE_ID;
    } else {
        $service_id = get_option('unlocksure_service_id', '');
    }


    $api_base = get_option('unlocksure_api_base', ''); // leave blank by default so user sets
    return array('api_key' => $api_key, 'service_id' => $service_id, 'api_base' => $api_base);
}


// ----------------------
// REST handler
// ----------------------
function unlocksure_check_imei_handler( WP_REST_Request $request ) {
    // Accept JSON body or form data
    $params = $request->get_json_params();
    if (empty($params)) $params = $request->get_params();


    $imei_raw = isset($params['imei']) ? $params['imei'] : '';
    $imei = preg_replace('/\s+/', '', $imei_raw);
    $imei = preg_replace('/\D/', '', $imei); // digits only


    if (empty($imei)) {
        return new WP_Error('no_imei', 'IMEI is required', array('status' => 400));
    }


    if (! preg_match('/^\d{15}$/', $imei) || ! unlocksure_is_valid_imei($imei)) {
        return new WP_Error('invalid_imei', 'Invalid IMEI format (IMEI must be 15 digits and pass Luhn check).', array('status' => 400));
    }


    // Simple rate-limiting per IP
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $rl_key = 'unlocksure_rl_' . md5($ip);
    $attempts = (int) get_transient($rl_key);
    $limit = intval(get_option('unlocksure_rate_limit_per_hour', UNLOCKSURE_IMEI_RATE_LIMIT_PER_HOUR));
    if ($attempts >= $limit) {
        return new WP_Error('rate_limited', 'Too many requests from your IP. Try again later.', array('status' => 429));
    }
    set_transient($rl_key, $attempts + 1, HOUR_IN_SECONDS);


    // Cache lookup
    $cache_key = 'unlocksure_imei_' . md5($imei);
    $cached = get_transient($cache_key);
    if ($cached !== false) {
        return rest_ensure_response(array('cached' => true, 'result' => $cached));
    }


    // Get API settings
    $settings = unlocksure_get_api_settings();
    $api_key = trim($settings['api_key']);
    $service_id = trim($settings['service_id']);
    $api_base = trim($settings['api_base']);


    // If no API key defined, return a simulated result so dev/testing works
    if (empty($api_key) || empty($api_base) || empty($service_id)) {
        $sim = array(
            'model' => 'SIMULATED: Example Model',
            'brand' => 'SIMULATED',
            'simlock' => 'LOCKED',
            'carrier' => 'SIMULATED CARRIER',
            'blacklist' => 'CLEAN',
            'note' => 'No API key / settings configured. This is a simulated result for testing.'
        );
        // store simulated cache for a short time
        set_transient($cache_key, $sim, MINUTE_IN_SECONDS * 10);
        return rest_ensure_response(array('cached' => false, 'simulated' => true, 'result' => $sim));
    }


    // Build the provider URL.
    // IMPORTANT: The exact path and query params depend on provider docs.
    // We assume the provider has a submit endpoint like: {api_base}/submit?apikey=...&service_id=...&input={imei}
    // Replace with the actual endpoint signature from IMEI.org when you're ready.
    $submit_url = rtrim($api_base, '/') . '/submit?apikey=' . rawurlencode($api_key) . '&service_id=' . rawurlencode($service_id) . '&input=' . rawurlencode($imei);


    // Make the request
    $args = array(
        'timeout' => 30,
        'headers' => array('Accept' => 'application/json'),
    );


    $resp = wp_remote_get($submit_url, $args);


    if (is_wp_error($resp)) {
        return new WP_Error('api_error', 'IMEI API request failed: ' . $resp->get_error_message(), array('status' => 502));
    }


    $code = wp_remote_retrieve_response_code($resp);
    $body = wp_remote_retrieve_body($resp);


    if ($code < 200 || $code >= 300) {
        return new WP_Error('api_http_error', 'IMEI provider returned HTTP ' . $code . '. Body: ' . $body, array('status' => 502));
    }


    $json = json_decode($body, true);
    if ($json === null) {
        // return raw body as fallback
        $json = array('raw' => $body);
    }


    // Cache results (24h by default)
    $ttl = intval(get_option('unlocksure_cache_ttl', UNLOCKSURE_IMEI_CACHE_TTL));
    set_transient($cache_key, $json, $ttl);


    return rest_ensure_response(array('cached' => false, 'result' => $json));
}

// ===== Shortcode: [unlocksure_imei_check] =====
function unlocksure_imei_shortcode($atts = [] ) { 
    $html  = '<section class="us-imei-section">';
    $html .= '  <div class="us-imei-header">';
    $html .= '    <h1 class="us-title">Free IMEI Check</h1>';
    $html .= '    <p class="us-subtitle">Enter your 15-digit IMEI to see carrier lock & blacklist status.</p>';
    $html .= '  </div>';

    $html .= '  <div class="unlocksure-imei-wrap">';
    $html .= '    <form id="unlocksure-imei-form" class="unlocksure-imei-form" autocomplete="off">';
    $html .= '      <label for="unlocksure-imei-input">Enter IMEI (15 digits)</label>';
    $html .= '      <input id="unlocksure-imei-input" name="imei" type="text" maxlength="20" ';
    $html .= '             placeholder="e.g. 490154203237518" required inputmode="numeric" ';
    $html .= '             pattern="[0-9]{15}" aria-describedby="imei-help" />';
    $html .= '      <small id="imei-help" class="visually-hidden">IMEI must be 15 digits. Dial *#06# to find yours.</small>';
    $html .= '      <button id="unlocksure-imei-submit" type="submit" aria-label="Check IMEI status">Check IMEI</button>';
    $html .= '    </form>';
    $html .= '    <div id="unlocksure-imei-result" class="unlocksure-imei-result" aria-live="polite"></div>';
    $html .= '    <p class="us-helper">Don’t know your IMEI? Dial <strong>*#06#</strong> or go to <strong>Settings → About</strong>.</p>';
    $html .= '    <p class="us-privacy">We only use your IMEI to run this check and cache the result for up to 24 hours.</p>';
    $html .= '  </div>';

	  $html .= '   <div class="us-why-cards">';
	  $html .= '      <article class="us-card us-card-what">';
	  $html .= '        <h3 class="us-card-title">';
	  $html .= '          <span class="us-card-icon" aria-hidden="true">';
	  $html .= '            <!-- Magnifier SVG -->';
  	$html .= '            <svg viewBox="0 0 24 24" width="20" height="20" role="img" focusable="false">';
  	$html .= '              <path fill="currentColor" d="M10.5 3a7.5 7.5 0 015.91 12.22l3.18 3.18a1 1 0 01-1.42 1.42l-3.18-3.18A7.5 7.5 0 1110.5 3zm0 2a5.5 5.5 0 100 11 5.5 5.5 0 000-11z"/>';
  	$html .= '            </svg>';
  	$html .= '          </span>';
  	$html .= '          What We Check';
  	$html .= '        </h3>';
  	$html .= '        <ul class="us-list">';
  	$html .= '          <li><strong>Carrier Lock Status</strong> — Which network your device is tied to.</li>';
  	$html .= '          <li><strong>Blacklist Status</strong> — Checks for lost/stolen or blocked records.</li>';
	  $html .= '          <li><strong>Original Carrier</strong> — Where the device was first activated.</li>';
	  $html .= '          <li><strong>Model & Variant</strong> — Brand, model, storage/region when available.</li>';
	  $html .= '          <li><strong>IMEI Integrity</strong> — Format and checksum validation before ordering.</li>';
	  $html .= '        </ul>';
  	$html .= '      </article>';

  	$html .= '      <article class="us-card us-card--why">';
  	$html .= '        <h3 class="us-card-title">';
  	$html .= '          <span class="us-card-icon us-card-icon--accent" aria-hidden="true">';
  	$html .= '            <!-- Shield-check SVG -->';
  	$html .= '            <svg viewBox="0 0 24 24" width="20" height="20" role="img" focusable="false">';
  	$html .= '              <path fill="currentColor" d="M12 2l7 3v6c0 5.25-3.438 9.75-7 11-3.562-1.25-7-5.75-7-11V5l7-3zm-1 12l5-5-1.414-1.414L11 11.172 9.414 9.586 8 11l3 3z"/>';
  	$html .= '            </svg>';
  	$html .= '          </span>';
  	$html .= '          Why This Check Matters';
  	$html .= '        </h3>';
  	$html .= '        <ul class="us-list">';
  	$html .= '          <li><strong>Instant Eligibility</strong> — Know immediately if your device meets unlocking requirements.</li>';
  	$html .= '          <li><strong>Accurate Timeframes</strong> — Realistic delivery estimates based on your device’s status.</li>';
  	$html .= '          <li><strong>Order Accuracy</strong> — Avoid delays and refunds by selecting the correct service the first time.</li>';
  	$html .= '        </ul>';
	  $html .= '      </article>';
	  $html .= '    </div>';
	  $html .= '  </section>';

    return $html;
} 
add_shortcode('unlocksure_imei_check', 'unlocksure_imei_shortcode');

// Enqueue only when the page actually contains [unlocksure_imei_check]
function unlocksure_enqueue_imei_assets() {
    if ( ! is_singular() ) return;

    global $post;
    if ( ! $post || ! has_shortcode( $post->post_content, 'unlocksure_imei_check' ) ) return;

    $css_path = plugin_dir_path(__FILE__) . 'css/unlocksure-imei.css';
    $js_path  = plugin_dir_path(__FILE__) . 'js/unlocksure-imei.js';
    $css_url  = plugin_dir_url(__FILE__)  . 'css/unlocksure-imei.css';
    $js_url   = plugin_dir_url(__FILE__)  . 'js/unlocksure-imei.js';

    // filemtime() = automatic cache busting
    wp_enqueue_style('unlocksure-imei-css', $css_url, [], filemtime($css_path));
    wp_enqueue_script('unlocksure-imei-js',  $js_url,  [], filemtime($js_path), true);

    // Pass REST URL + nonce to JS
    wp_localize_script('unlocksure-imei-js', 'unlocksureData', [
        'rest_url' => esc_url_raw( rest_url('unlocksure/v1/check-imei') ),
        'nonce'    => wp_create_nonce('wp_rest'),
    ]);
	
	error_log('UnlockSure IMEI shortcode loaded v=TEST_0901');

}
// Priority 100 ensures we enqueue AFTER Elementor’s own styles
add_action('wp_enqueue_scripts', 'unlocksure_enqueue_imei_assets', 100);


// ----------------------
// Admin settings page
// ----------------------
add_action('admin_menu', function() {
    add_options_page('UnlockSure IMEI', 'UnlockSure IMEI', 'manage_options', 'unlocksure-imei', 'unlocksure_imei_settings_page');
});


function unlocksure_imei_settings_page() {
    if (! current_user_can('manage_options')) return;
    $saved = false;


    if (isset($_POST['unlocksure_imei_nonce']) && wp_verify_nonce($_POST['unlocksure_imei_nonce'], 'unlocksure_imei_save')) {
        update_option('unlocksure_api_key', sanitize_text_field($_POST['unlocksure_api_key']));
        update_option('unlocksure_service_id', sanitize_text_field($_POST['unlocksure_service_id']));
        update_option('unlocksure_api_base', esc_url_raw($_POST['unlocksure_api_base']));
        update_option('unlocksure_cache_ttl', intval($_POST['unlocksure_cache_ttl']));
        update_option('unlocksure_rate_limit_per_hour', intval($_POST['unlocksure_rate_limit_per_hour']));
        $saved = true;
    }


    $api_key = esc_attr(get_option('unlocksure_api_key', ''));
    $service_id = esc_attr(get_option('unlocksure_service_id', ''));
    $api_base = esc_attr(get_option('unlocksure_api_base', ''));
    $cache_ttl = intval(get_option('unlocksure_cache_ttl', UNLOCKSURE_IMEI_CACHE_TTL));
    $rate_limit = intval(get_option('unlocksure_rate_limit_per_hour', UNLOCKSURE_IMEI_RATE_LIMIT_PER_HOUR));


    ?>
    <div class="wrap">
      <h1>UnlockSure IMEI settings</h1>
      <?php if ($saved): ?><div class="updated"><p>Settings saved.</p></div><?php endif; ?>
      <form method="post">
        <?php wp_nonce_field('unlocksure_imei_save', 'unlocksure_imei_nonce'); ?>


        <table class="form-table">
          <tr>
            <th scope="row"><label for="unlocksure_api_key">IMEI API Key</label></th>
            <td>
              <input name="unlocksure_api_key" id="unlocksure_api_key" class="regular-text" value="<?php echo $api_key; ?>" />
              <p class="description">Paste your IMEI provider API key here, or define UNLOCKSURE_IMEI_API_KEY in wp-config.php</p>
            </td>
          </tr>
          <tr>
            <th scope="row"><label for="unlocksure_service_id">Service ID</label></th>
            <td>
              <input name="unlocksure_service_id" id="unlocksure_service_id" class="regular-text" value="<?php echo $service_id; ?>" />
              <p class="description">Service ID from your IMEI provider (the ID of the check you want to run).</p>
            </td>
          </tr>
          <tr>
            <th scope="row"><label for="unlocksure_api_base">IMAI API Base URL</label></th>
            <td>
              <input name="unlocksure_api_base" id="unlocksure_api_base" class="regular-text" value="<?php echo $api_base; ?>" />
              <p class="description">Base URL for the provider endpoints (e.g. https://api-client.imei.org/api). Leave blank for simulated mode.</p>
            </td>
          </tr>
          <tr>
            <th scope="row"><label for="unlocksure_cache_ttl">Cache TTL (seconds)</label></th>
            <td>
              <input name="unlocksure_cache_ttl" id="unlocksure_cache_ttl" class="regular-text" value="<?php echo $cache_ttl; ?>" />
              <p class="description">How long to cache results (default 86400 = 24h)</p>
            </td>
          </tr>
          <tr>
            <th scope="row"><label for="unlocksure_rate_limit_per_hour">Rate limit (per IP per hour)</label></th>
            <td>
              <input name="unlocksure_rate_limit_per_hour" id="unlocksure_rate_limit_per_hour" class="regular-text" value="<?php echo $rate_limit; ?>" />
              <p class="description">Block requests from an IP after this many requests in one hour (default 60).</p>
            </td>
          </tr>
        </table>


        <?php submit_button('Save IMEI Settings'); ?>
      </form>


      <h2>Using constants instead</h2>
      <p>If you prefer to store key & service id in <code>wp-config.php</code>, add lines like:</p>
      <pre>define('UNLOCKSURE_IMEI_API_KEY','your_api_key_here');
define('UNLOCKSURE_IMEI_SERVICE_ID','your_service_id_here');</pre>


      <h2>Notes</h2>
      <ul>
        <li>If API key / API base are empty, the plugin returns a simulated result for testing.</li>
        <li>Replace the "submit" endpoint format if your provider uses a different path. Check the provider docs for exact endpoint and required params.</li>
      </ul>
    </div>
    <?php
}