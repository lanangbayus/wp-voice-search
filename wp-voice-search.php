<?php
/**
 * Plugin Name: WP Voice Search
 * Description: Tambahkan fitur pencarian suara ke WordPress. Support multi-language.
 * Version: 1.5.1
 * Author: Lanang Bayu S
 * License: GPLv2 or later
 */

if (!defined('ABSPATH')) exit;

// Sertakan file pengaturan bila ada (opsional)
if (file_exists(plugin_dir_path(__FILE__) . 'wpvs-settings.php')) {
  require_once plugin_dir_path(__FILE__) . 'wpvs-settings.php';
}

/**
 * =====================================================
 * Helper: Ambil URL ikon mic terbaru / pilihan user
 * - Jika ada option 'wpvs_mic_icon_id' (attachment id) → pakai itu
 * - Jika tidak ada: pilih attachment terbaru yang namanya mengandung
 *   'microphone' atau 'icon'
 * - Fallback ke aset plugin
 * =====================================================
 */
if (!function_exists('wpvs_get_mic_icon_url')) {
  function wpvs_get_mic_icon_url() {
    // 1) Pilihan user (opsional): attachment id disimpan di option
    $id = (int) get_option('wpvs_mic_icon_id', 0);
    if ($id) {
      $url  = wp_get_attachment_image_url($id, 'full');
      $path = get_attached_file($id);
      if ($url) {
        $ver = ($path && file_exists($path)) ? filemtime($path) : time();
        return add_query_arg('ver', $ver, $url);
      }
    }

    // 2) Cari otomatis file terbaru yang mengandung "microphone" / "icon"
    $q = new WP_Query([
      'post_type'      => 'attachment',
      'post_status'    => 'inherit',
      'posts_per_page' => 1,
      'orderby'        => 'date',
      'order'          => 'DESC',
      'meta_query'     => [
        'relation' => 'OR',
        [
          'key'     => '_wp_attached_file',
          'value'   => 'microphone',
          'compare' => 'LIKE',
        ],
        [
          'key'     => '_wp_attached_file',
          'value'   => 'icon',
          'compare' => 'LIKE',
        ],
      ],
      'no_found_rows'  => true,
    ]);

    if ($q->have_posts()) {
      $att  = $q->posts[0];
      $url  = wp_get_attachment_url($att->ID);
      $path = get_attached_file($att->ID);
      if ($url) {
        $ver = ($path && file_exists($path)) ? filemtime($path) : time();
        return add_query_arg('ver', $ver, $url);
      }
    }

    // 3) Fallback ke aset plugin (ganti ke path aset kamu jika perlu)
    return plugins_url('assets/microphone-default.svg', __FILE__);
  }
}

/**
 * =====================================================
 * Kelas utama
 * =====================================================
 */
class WP_Voice_Search_Starter {
  const VERSION    = '1.0.3';
  const HANDLE_JS  = 'wpvs-js';
  const HANDLE_CSS  = 'wpvs-css';

  public function __construct() {
    add_shortcode('voice_search', [$this, 'shortcode']);
    add_filter('get_search_form', [$this, 'filter_search_form'], 20);
    add_filter('render_block',   [$this, 'filter_render_block_search'], 20, 2);
    add_action('wp_enqueue_scripts', [$this, 'register_assets']);
  }

  /** ---------- Fallback settings jika wpvs_get_setting() tidak ada ---------- */
  private function get_setting($key, $default = null) {
    if (function_exists('wpvs_get_setting')) {
      return wpvs_get_setting($key);
    }
    // defaults aman saat file settings tidak ada
    $defaults = [
      'enable_voice'     => 1,
      'dark_mode'        => 0,
      'mic_size'         => 20,
      'mic_right'        => 12,
      'input_height'     => 44,
      'button_color'     => '#ccff00',
      'status_idle'      => 'Click mic to speak',
      'status_listening' => '🎙️ Mendengarkan...',
      'mic_icon_url'     => '', // opsional kalau mau dipaksa manual
    ];
    return array_key_exists($key, $defaults) ? $defaults[$key] : $default;
  }

  /** =====================================================
   * 1) Daftarkan CSS & JS (assets/*)
   * ===================================================== */
  public function register_assets() {
    $base = plugin_dir_url(__FILE__);
    $path = plugin_dir_path(__FILE__);

    // CSS
    $css_path = $path . 'assets/css/voice-search.css';
    $css_url  = $base . 'assets/css/voice-search.css';
    if (file_exists($css_path)) {
      wp_register_style(self::HANDLE_CSS, $css_url, [], filemtime($css_path));
    }

    // JS
    $js_path = $path . 'assets/js/voice-search.js';
    $js_url  = $base . 'assets/js/voice-search.js';
    if (file_exists($js_path)) {
      wp_register_script(self::HANDLE_JS, $js_url, [], filemtime($js_path), true);
    }
  }

  /** Localize config untuk JS (dipakai di semua jalur render) */
  private function enqueue_and_localize($lang, $autostart = false, $autosubmit = true, $btn = '🎤') {
    wp_enqueue_script(self::HANDLE_JS);
    wp_enqueue_style (self::HANDLE_CSS);

    wp_localize_script(self::HANDLE_JS, 'WPVS', [
      'enabled'    => (bool) $this->get_setting('enable_voice', 1),
      'dark'       => (bool) $this->get_setting('dark_mode', 0),
      'lang'       => $lang,
      'autostart'  => (bool) $autostart,
      'autosubmit' => (bool) $autosubmit,
      'btn'        => (string) $btn,
      'messages'   => [
        'unsupported' => __('Browser tidak mendukung voice search.', 'wpvs'),
        'listening'   => __($this->get_setting('status_listening', '🎙️ Mendengarkan...'), 'wpvs'),
        'stopped'     => __('Selesai.', 'wpvs'),
        'error'       => __('Terjadi error, coba lagi.', 'wpvs'),
        'disabled'    => __('Voice search dimatikan.', 'wpvs'),
      ]
    ]);
  }

  /** =====================================================
   * 2) Deteksi bahasa otomatis
   * ===================================================== */
  private function detect_locale_for_speech() {
    if (function_exists('pll_current_language')) {
      $slug = pll_current_language('slug');
      if (!empty($slug)) return $this->normalize_lang($slug);
    }
    if (defined('ICL_LANGUAGE_CODE') && ICL_LANGUAGE_CODE) {
      return $this->normalize_lang(ICL_LANGUAGE_CODE);
    }
    if (function_exists('weglot_get_current_language')) {
      $slug = weglot_get_current_language();
      if (!empty($slug)) return $this->normalize_lang($slug);
    }
    $site_lang = get_bloginfo('language');
    if (!empty($site_lang)) return $this->normalize_lang($site_lang);
    return 'en-US';
  }

  private function normalize_lang($code) {
    $map = [
      'en'=>'en-US','id'=>'id-ID','es'=>'es-ES','pt'=>'pt-BR','fr'=>'fr-FR',
      'de'=>'de-DE','it'=>'it-IT','nl'=>'nl-NL','ja'=>'ja-JP','ko'=>'ko-KR',
      'zh'=>'zh-CN','ar'=>'ar-SA','ru'=>'ru-RU','hi'=>'hi-IN','th'=>'th-TH'
    ];
    $lc = trim((string)$code);
    if (preg_match('/^[a-z]{2}[-_][A-Za-z]{2}$/', $lc)) {
      [$l,$r] = preg_split('/[-_]/', $lc, 2);
      return strtolower($l) . '-' . strtoupper($r);
    }
    $lc = strtolower($lc);
    return $map[$lc] ?? 'en-US';
  }

  /** =====================================================
   * 3) Shortcode: [voice_search]
   * ===================================================== */
  public function shortcode($atts = [], $content = null) {
    $auto_lang = $this->detect_locale_for_speech();
    $mic_label = apply_filters('wpvs_mic_label', '🎤');

    $atts = shortcode_atts([
      'placeholder' => __('Search…', 'wpvs'),
      'lang'        => $auto_lang,
      'autostart'   => 'false',
      'submit'      => 'true',
      'btn'         => $mic_label,
    ], $atts, 'voice_search');

    $this->enqueue_and_localize(
      $atts['lang'],
      filter_var($atts['autostart'], FILTER_VALIDATE_BOOLEAN),
      filter_var($atts['submit'],    FILTER_VALIDATE_BOOLEAN),
      (string)$atts['btn']
    );

    // URL ikon dinamis (terbaru / pilihan user)
    $icon_url = esc_url( wpvs_get_mic_icon_url() );

    ob_start(); ?>
<div class="vs-wrap" style="--wpvs-mic-icon: url('<?php echo $icon_url; ?>')">
  <form role="search" method="get" class="vs-search" action="<?php echo esc_url( home_url( '/' ) ); ?>">
    <div class="vs-field">
      <input class="vs-input" type="search" name="s" placeholder="<?php echo esc_attr($atts['placeholder']); ?>" value="<?php echo esc_attr(get_search_query()); ?>" />
      <button type="button" class="vs-mic" aria-pressed="false" aria-label="<?php esc_attr_e('Mulai voice search','wpvs'); ?>">
        <!-- fallback SVG (akan disembunyikan jika has-custom-icon aktif) -->
        <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true">
          <path fill="currentColor" d="M12 14a3 3 0 0 0 3-3V6a3 3 0 1 0-6 0v5a3 3 0 0 0 3 3z"/>
          <path fill="currentColor" d="M5 11a1 1 0 1 0-2 0 9 9 0 0 0 8 8v3h2v-3a9 9 0 0 0 8-8 1 1 0 1 0-2 0 7 7 0 0 1-14 0z"/>
        </svg>
      </button>

      <!-- meter real-time -->
      <div class="vs-meter" aria-hidden="true">
        <i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i>
      </div>
    </div>

    <button class="vs-submit" type="submit"><?php esc_html_e('Search', 'wpvs'); ?></button>
  </form>
</div>
    <?php
    return ob_get_clean();
  }

  /** =====================================================
   * 4) Theme classic & Gutenberg block
   * ===================================================== */
  public function filter_search_form($html) {
    $auto_lang = $this->detect_locale_for_speech();
    $mic_label = apply_filters('wpvs_mic_label', '🎤');

    // pastikan assets + config terpasang juga untuk form theme
    $this->enqueue_and_localize($auto_lang, false, true, (string)$mic_label);

    // suntik CSS var --wpvs-mic-icon ke <form> agar ikon shortcode/classic ikut dinamis
    $icon_url = esc_url( wpvs_get_mic_icon_url() );
    if (preg_match('/<form\b[^>]*\bclass="/i', $html)) {
      // tambahkan class wpvs-search-form & style (jika belum ada)
      $html = preg_replace('/(<form\b[^>]*\bclass=")([^"]*)(")/i', '$1$2 wpvs-search-form$3', $html, 1);
    } else {
      $html = preg_replace('/<form\b/i', '<form class="wpvs-search-form"', $html, 1);
    }
    // inject / append style var
    if (preg_match('/<form\b[^>]*\bstyle="/i', $html)) {
      $html = preg_replace('/(<form\b[^>]*\bstyle=")([^"]*)(")/i', '$1$2; --wpvs-mic-icon: url('.$icon_url.')$3', $html, 1);
    } else {
      $html = preg_replace('/<form\b/i', '<form style="--wpvs-mic-icon: url('.$icon_url.')"', $html, 1);
    }

    return $this->inject_voice_form($html);
  }

  public function filter_render_block_search($block_content, $block) {
    if (empty($block['blockName']) || $block['blockName'] !== 'core/search') return $block_content;

    $auto_lang = $this->detect_locale_for_speech();
    $mic_label = apply_filters('wpvs_mic_label', '🎤');

    // localize untuk Gutenberg juga
    $this->enqueue_and_localize($auto_lang, false, true, (string)$mic_label);

    // suntik CSS var --wpvs-mic-icon ke wrapper .wp-block-search
    $icon_url = esc_url( wpvs_get_mic_icon_url() );
    $content  = $block_content;

    if (strpos($content, 'class="wp-block-search') !== false) {
      if (preg_match('/<div\s+class="wp-block-search[^"]*"\s+style="([^"]*)"/', $content)) {
        // sudah punya style → append var
        $content = preg_replace(
          '/(<div\s+class="wp-block-search[^"]*"\s+style=")([^"]*)(")/',
          '$1$2; --wpvs-mic-icon: url(' . $icon_url . ')$3',
          $content,
          1
        );
      } else {
        // belum punya style → tambah style baru
        $content = preg_replace(
          '/(<div\s+class="wp-block-search[^"]*")/',
          '$1 style="--wpvs-mic-icon: url(' . $icon_url . ')"',
          $content,
          1
        );
      }
    }

    return $this->inject_voice_form($content);
  }

  /**
   * Sisipkan wrapper input + tombol mic (jika belum ada),
   * tambah kelas form (dark/disabled), tetap non-destruktif.
   */
  private function inject_voice_form($content) {
    $mic_label = apply_filters('wpvs_mic_label', '🎤');

    // siapkan kelas tambahan untuk form
    $extra_classes = ['wpvs-search-form'];
    if ($this->get_setting('dark_mode', 0))    $extra_classes[] = 'wpvs-dark';
    if (!$this->get_setting('enable_voice',1)) $extra_classes[] = 'wpvs-disabled';
    $extra = implode(' ', $extra_classes);

    // 1) tambahkan class ke <form ...>
    if (preg_match('/<form\b[^>]*\bclass="/i', $content)) {
      $content = preg_replace('/(<form\b[^>]*\bclass=")([^"]*)(")/i', '$1$2 ' . $extra . '$3', $content, 1);
    } else {
      $content = preg_replace('/<form\b/i', '<form class="' . $extra . '"', $content, 1);
    }

    // 2) Bungkus input[type=search] + sisipkan tombol mic (jika belum ada)
    $pattern = '/(<input\b[^>]*type="search"[^>]*)(>)/i';
    if (preg_match($pattern, $content)) {
      // Hindari double wrap: cek apakah sudah ada .wpvs-input-wrap di sekitar input
      if (strpos($content, 'wpvs-input-wrap') === false) {
        $content = preg_replace_callback($pattern, function($m) use($mic_label) {
          $input = $m[1];

          // pastikan punya class .wpvs-field
          if (!preg_match('/\bclass="/i', $input)) {
            $input = preg_replace('/<input\b/i', '<input class="wpvs-field"', $input, 1);
          } else {
            $input = preg_replace('/class="([^"]*)"/i', 'class="$1 wpvs-field"', $input, 1);
          }

          $mic = '<button type="button" class="wpvs-mic" aria-label="'.esc_attr__('Voice search','wpvs').'" title="'.esc_attr__('Voice search','wpvs').'">'.esc_html($mic_label).'</button>';
          return '<div class="wpvs-input-wrap">'.$input.$m[2].$mic.'</div>';
        }, $content, 1);
      }
    }

    return $content;
  }
}

new WP_Voice_Search_Starter();
