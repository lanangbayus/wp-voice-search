<?php // (no plugin header here)


if ( ! defined( 'ABSPATH' ) ) exit;

/** ==========================================================
 * 🍼 Konstanta & nilai default
 * ========================================================== */
define('WPVS_OPTION_KEY', 'wpvs_settings');

/** keterangan: tambahkan enable_voice & dark_mode */
function wpvs_default_settings() {
  return array(
    'enable_voice'     => 1,          // 1=ON, 0=OFF
    'dark_mode'        => 0,          // 1=ON (pakai tema gelap untuk komponen), 0=OFF
    'mic_size'         => 20,
    'mic_right'        => 12,
    'input_height'     => 44,
    'button_color'     => '#ccff00',
    'status_idle'      => 'Click mic to speak',
    'status_listening' => 'Listening…',
    'mic_icon_url'     => '',         // custom icon (opsional)
  );
}

/** keterangan: helper ambil setting dengan fallback */
function wpvs_get_setting( $key ) {
  $opts = get_option( WPVS_OPTION_KEY, array() );
  $defaults = wpvs_default_settings();
  $opts = wp_parse_args( is_array($opts) ? $opts : array(), $defaults );
  return $opts[$key] ?? $defaults[$key];
}

/** ==========================================================
 * 🍼 Menu Settings → Voice Search
 * ========================================================== */
add_action('admin_menu', function () {
  add_options_page(
    'Voice Search Settings',
    'Voice Search',
    'manage_options',
    'voice-search-settings',
    'wpvs_settings_page_html'
  );
});

/** ==========================================================
 * 🍼 Daftar field pengaturan
 * ========================================================== */
add_action('admin_init', function () {
  register_setting(
    'wpvs_settings_group',
    WPVS_OPTION_KEY,
    array(
      'type'              => 'array',
      'sanitize_callback' => 'wpvs_sanitize_settings',
      'default'           => wpvs_default_settings(),
    )
  );

  /** Seksi: General Toggles */
  add_settings_section(
    'wpvs_section_general',
    'General',
    function () {
      echo '<p>Pengaturan umum—aktif/nonaktif voice search dan dark mode komponen.</p>';
    },
    'voice-search-settings'
  );

  // 🔘 Enable Voice Search (toggle)
  add_settings_field('wpvs_enable_voice', 'Enable Voice Search', function () {
    $val = (int) wpvs_get_setting('enable_voice');
    echo "<label><input type='checkbox' name='".WPVS_OPTION_KEY."[enable_voice]' value='1' ".checked(1,$val,false)." /> Aktifkan voice search</label>";
  }, 'voice-search-settings', 'wpvs_section_general');

  // 🌗 Dark Mode (toggle)
  add_settings_field('wpvs_dark_mode', 'Dark Mode (component)', function () {
    $val = (int) wpvs_get_setting('dark_mode');
    echo "<label><input type='checkbox' name='".WPVS_OPTION_KEY."[dark_mode]' value='1' ".checked(1,$val,false)." /> Gunakan tema gelap untuk komponen form voice search</label>";
  }, 'voice-search-settings', 'wpvs_section_general');

  /** Seksi: Style */
  add_settings_section(
    'wpvs_section_style',
    'Style Options',
    function () {
      echo '<p>Atur ukuran, posisi, dan warna tampilan Voice Search.</p>';
    },
    'voice-search-settings'
  );

  // Mic size
  add_settings_field('wpvs_mic_size', 'Mic Icon Size (px)', function () {
    $val = (int) wpvs_get_setting('mic_size');
    echo "<input type='number' name='".WPVS_OPTION_KEY."[mic_size]' value='$val' min='10' max='60' />";
  }, 'voice-search-settings', 'wpvs_section_style');

  // Mic right offset
  add_settings_field('wpvs_mic_right', 'Mic Right Offset (px)', function () {
    $val = (int) wpvs_get_setting('mic_right');
    echo "<input type='number' name='".WPVS_OPTION_KEY."[mic_right]' value='$val' min='0' max='40' />";
  }, 'voice-search-settings', 'wpvs_section_style');

  // Input height
  add_settings_field('wpvs_input_height', 'Input Height (px)', function () {
    $val = (int) wpvs_get_setting('input_height');
    echo "<input type='number' name='".WPVS_OPTION_KEY."[input_height]' value='$val' min='32' max='72' />";
  }, 'voice-search-settings', 'wpvs_section_style');

  // Button color
  add_settings_field('wpvs_button_color', 'Button Color', function () {
    $val = wpvs_get_setting('button_color');
    echo "<input type='color' name='".WPVS_OPTION_KEY."[button_color]' value='".esc_attr($val)."' />";
  }, 'voice-search-settings', 'wpvs_section_style');

  /** Seksi: Icon & Labels */
  add_settings_section(
    'wpvs_section_assets',
    'Icon & Labels',
    function () {
      echo '<p>Upload ikon mic custom serta ubah teks status.</p>';
    },
    'voice-search-settings'
  );

  // Custom Mic Icon (pakai media uploader WP)
  add_settings_field('wpvs_mic_icon_url', 'Custom Mic Icon', function () {
    $val = esc_url(wpvs_get_setting('mic_icon_url'));
    $preview = $val ? "<img src='$val' style='max-width:48px;height:auto;border-radius:4px;'>" : "<em>No icon selected</em>";

    echo '
      <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
        <input type="hidden" id="wpvs_mic_icon_url" name="'.WPVS_OPTION_KEY.'[mic_icon_url]" value="'.esc_attr($val).'" />
        <button type="button" class="button" id="wpvs_upload_icon_btn">Pilih / Upload Ikon</button>
        <button type="button" class="button" id="wpvs_remove_icon_btn" style="display:'.($val?'inline-block':'none').'">Hapus</button>
        <div id="wpvs_icon_preview" style="margin-top:8px;min-width:52px;min-height:52px;display:flex;align-items:center;">'.$preview.'</div>
      </div>
    ';

    // Media uploader
    echo "
    <script>
    (function($){
      if (typeof wp !== 'undefined' && wp.media) {
        let frame;
        $('#wpvs_upload_icon_btn').on('click', function(e){
          e.preventDefault();
          if (frame) { frame.open(); return; }
          frame = wp.media({
            title: 'Pilih atau Upload Ikon Mic',
            button: { text: 'Gunakan ikon ini' },
            multiple: false,
            library: { type: ['image'] }
          });
          frame.on('select', function(){
            const file = frame.state().get('selection').first().toJSON();
            $('#wpvs_mic_icon_url').val(file.url).trigger('change');
            $('#wpvs_icon_preview').html('<img src=\"'+file.url+'\" style=\"max-width:48px;height:auto;border-radius:4px;\">');
            $('#wpvs_remove_icon_btn').show();
          });
          frame.open();
        });
        $('#wpvs_remove_icon_btn').on('click', function(){
          $('#wpvs_mic_icon_url').val('').trigger('change');
          $('#wpvs_icon_preview').html('<em>No icon selected</em>');
          $(this).hide();
        });
      }
    })(jQuery);
    </script>
    ";
  }, 'voice-search-settings', 'wpvs_section_assets');

  // Label Idle
  add_settings_field('wpvs_status_idle', 'Status (Idle)', function () {
    $val = wpvs_get_setting('status_idle');
    echo "<input type='text' class='regular-text' name='".WPVS_OPTION_KEY."[status_idle]' value='".esc_attr($val)."' />";
  }, 'voice-search-settings', 'wpvs_section_assets');

  // Label Listening
  add_settings_field('wpvs_status_listening', 'Status (Listening)', function () {
    $val = wpvs_get_setting('status_listening');
    echo "<input type='text' class='regular-text' name='".WPVS_OPTION_KEY."[status_listening]' value='".esc_attr($val)."' />";
  }, 'voice-search-settings', 'wpvs_section_assets');
});

/** keterangan: pastikan media uploader siap di halaman settings */
add_action('admin_enqueue_scripts', function($hook){
  if ($hook === 'settings_page_voice-search-settings') {
    wp_enqueue_media();
  }
});

/** ==========================================================
 * 🍼 Sanitasi input
 * ========================================================== */
function wpvs_sanitize_settings( $input ) {
  $d = wpvs_default_settings();
  $o = [];

  $o['enable_voice']     = !empty($input['enable_voice']) ? 1 : 0;
  $o['dark_mode']        = !empty($input['dark_mode']) ? 1 : 0;
  $o['mic_size']         = max(10, min(60, intval($input['mic_size'] ?? $d['mic_size'])));
  $o['mic_right']        = max(0,  min(40, intval($input['mic_right'] ?? $d['mic_right'])));
  $o['input_height']     = max(32, min(72, intval($input['input_height'] ?? $d['input_height'])));
  $color                 = trim($input['button_color'] ?? $d['button_color']);
  $o['button_color']     = preg_match('/^#([A-Fa-f0-9]{6})$/', $color) ? $color : $d['button_color'];
  $o['mic_icon_url']     = esc_url_raw($input['mic_icon_url'] ?? '');
  $o['status_idle']      = sanitize_text_field($input['status_idle'] ?? $d['status_idle']);
  $o['status_listening'] = sanitize_text_field($input['status_listening'] ?? $d['status_listening']);

  return $o;
}

/** ==========================================================
 * 🍼 Halaman pengaturan + Live Preview (real-time)
 * ========================================================== */
function wpvs_settings_page_html() {
  if ( ! current_user_can('manage_options') ) return;
  ?>
  <div class="wrap">
    <h1>Voice Search Settings</h1>
    <form method="post" action="options.php">
      <?php
        settings_fields('wpvs_settings_group');
        do_settings_sections('voice-search-settings');
        submit_button();
      ?>
    </form>

    <hr />
    <h2>🎧 Live Preview</h2>
    <div id="wpvs-preview" style="padding:12px;border:1px solid #ddd;max-width:520px;background:#fff;border-radius:8px;">
      <form class="wpvs-search-form" style="display:flex;align-items:center;gap:8px;">
        <div class="wpvs-input-wrap" style="position:relative;flex:1;">
          <input type="search" class="wpvs-field" placeholder="Try saying something..." style="width:100%;padding:10px 42px 10px 12px;font-size:14px;">
          <button type="button" class="wpvs-mic" style="position:absolute;top:50%;right:12px;transform:translateY(-50%);font-size:18px;background:transparent;border:none;">🎤</button>
        </div>
        <button type="submit" class="wpvs-submit" style="padding:10px 14px;font-size:14px;cursor:pointer;">Search</button>
      </form>
      <small class="wpvs-status" style="display:block;margin-top:4px;color:#555;font-size:12px;">Status: idle</small>
    </div>
  </div>

  <script>
  (function($){
    // Live update helper
    const updatePreview = function(){
      const form   = $('#wpvs-preview .wpvs-search-form');
      const mic    = form.find('.wpvs-mic');
      const btn    = form.find('.wpvs-submit');
      const field  = form.find('.wpvs-field');

      const enable = $('input[name="wpvs_settings[enable_voice]"]').is(':checked');
      const dark   = $('input[name="wpvs_settings[dark_mode]"]').is(':checked');

      const micSize = $('input[name="wpvs_settings[mic_size]"]').val();
      const micRight = $('input[name="wpvs_settings[mic_right]"]').val();
      const inputHeight = $('input[name="wpvs_settings[input_height]"]').val();
      const btnColor = $('input[name="wpvs_settings[button_color]"]').val();
      const iconUrl = $('#wpvs_mic_icon_url').val();

      // toggle enable
      form.toggleClass('wpvs-disabled', !enable);

      // toggle dark
      form.toggleClass('wpvs-dark', dark);

      // sizing
      mic.css({'font-size': micSize + 'px','right': micRight + 'px'});
      field.css('min-height', inputHeight + 'px');
      btn.css('background-color', btnColor);

      // icon
      if(iconUrl){
        mic.html('<img src="'+iconUrl+'" style="width:'+micSize+'px;height:'+micSize+'px;">');
      } else {
        mic.text('🎤');
      }
    };

    // trigger on change
    $('input, select').on('input change', updatePreview);
    $('#wpvs_mic_icon_url').on('change', updatePreview);
    $(document).ready(updatePreview);
  })(jQuery);
  </script>
  <?php
}

/** ==========================================================
 * 🍼 Inject CSS variable + dark/disabled helper ke <head>
 * ========================================================== */
add_action('wp_head', function () {
  $enable       = (int) wpvs_get_setting('enable_voice');
  $dark         = (int) wpvs_get_setting('dark_mode');
  $mic_size     = (int) wpvs_get_setting('mic_size');
  $mic_right    = (int) wpvs_get_setting('mic_right');
  $input_height = (int) wpvs_get_setting('input_height');
  $btn_color    = wpvs_get_setting('button_color');
  $icon_url     = wpvs_get_setting('mic_icon_url');
  ?>
  <style id="wpvs-inline-style">
    .wpvs-search-form {
      --wpvs-input-height: <?php echo esc_attr($input_height); ?>px;
      --wpvs-mic-size: <?php echo esc_attr($mic_size); ?>px;
      --wpvs-mic-right: <?php echo esc_attr($mic_right); ?>px;
      --wpvs-btn-bg: <?php echo esc_attr($btn_color); ?>;
      <?php if($icon_url): ?>--wpvs-mic-icon: url('<?php echo esc_url($icon_url); ?>');<?php endif; ?>
    }
    /* Disabled state (matikan interaksi mic) */
    <?php if(!$enable): ?>
    .wpvs-search-form .wpvs-mic{ pointer-events:none; opacity:.45; filter:grayscale(1); }
    <?php endif; ?>

    /* Dark mode komponen */
    <?php if($dark): ?>
    .wpvs-search-form.wpvs-dark .wpvs-field,
    .wpvs-search-form.wpvs-dark .wp-block-search__input {
      background:#111; color:#eee; border-color:#444;
    }
    .wpvs-search-form.wpvs-dark .wpvs-submit,
    .wpvs-search-form.wpvs-dark .search-submit,
    .wpvs-search-form.wpvs-dark .wp-block-search__button {
      background-color: var(--wpvs-btn-bg); color:#000;
      filter: brightness(.95);
    }
    .wpvs-search-form.wpvs-dark .wpvs-mic { color:#ddd; }
    <?php endif; ?>
  </style>
  <?php
});

/** ==========================================================
 * 🍼 Muat CSS utama dari assets/css
 * ========================================================== */
add_action('wp_enqueue_scripts', function() {
  $css_path = plugin_dir_path(__FILE__) . 'assets/css/voice-search.css';
  $css_url  = plugin_dir_url(__FILE__) . 'assets/css/voice-search.css';
  if ( file_exists($css_path) ) {
    wp_enqueue_style('voice-search-style', $css_url, [], filemtime($css_path));
  }
});
