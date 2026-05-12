<?php
/**
 * Plugin Name: Multilingual Page Translator
 * Description: Duplicate and translate WordPress pages, including ACF fields and internal links, with a flag language dropdown for menus.
 * Version: 1.0.72
 * Author: L.P.
 * Text Domain: multilingual-page-translator
 */

if (!defined('ABSPATH')) {
    exit;
}

final class MPT_Multilingual_Page_Translator
{
    const OPTION_LANGUAGES = 'mpt_languages';
    const OPTION_SETTINGS = 'mpt_settings';
    const OPTION_VERSION = 'mpt_version';
    const META_LANG = '_mpt_lang';
    const META_GROUP = '_mpt_group';
    const META_SOURCE = '_mpt_source_post';
    const META_SOURCE_ATTACHMENT = '_mpt_source_attachment';
    const META_REVIEW = '_mpt_translation_review';
    const MENU_META_LANG = '_mpt_menu_lang';
    const MENU_META_SOURCE = '_mpt_source_menu';
    const MENU_META_LOCATION = '_mpt_source_location';
    const NONCE = 'mpt_nonce';

    private static $instance = null;
    private $translation_cache = array();
    private $attachment_clone_cache = array();

    public static function instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct()
    {
        add_action('init', array($this, 'register_assets'));
        add_action('init', array($this, 'register_language_rewrite_rules'));
        add_action('admin_init', array($this, 'maybe_flush_rewrite_rules'));
        add_action('admin_menu', array($this, 'register_admin_menu'));
        add_action('add_meta_boxes', array($this, 'register_meta_boxes'));
        add_action('save_post_page', array($this, 'save_page_language_meta'), 10, 2);
        add_filter('manage_pages_columns', array($this, 'add_page_language_column'));
        add_action('manage_pages_custom_column', array($this, 'render_page_language_column'), 10, 2);
        add_action('admin_post_mpt_duplicate_page', array($this, 'handle_duplicate_page'));
        add_action('admin_post_mpt_bulk_duplicate', array($this, 'handle_bulk_duplicate'));
        add_action('admin_post_mpt_translate_options', array($this, 'handle_translate_options'));
        add_action('admin_post_mpt_duplicate_menus', array($this, 'handle_duplicate_menus'));
        add_action('admin_post_mpt_save_settings', array($this, 'handle_save_settings'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_front_assets'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        add_filter('pre_option', array($this, 'load_translated_option_value'), 10, 3);
        add_filter('wp_nav_menu_args', array($this, 'switch_language_menu_args'), 5);
        add_filter('wp_nav_menu_objects', array($this, 'translate_menu_objects'), 10, 2);
        add_filter('wp_nav_menu_items', array($this, 'append_switcher_to_menu'), 10, 2);
        add_filter('get_custom_logo', array($this, 'filter_custom_logo_link'));
        add_filter('the_title', array($this, 'translate_attachment_title'), 10, 2);
        add_filter('acf/load_value', array($this, 'load_translated_acf_option_value'), 20, 3);
        add_filter('acf/format_value', array($this, 'translate_acf_formatted_value'), 20, 3);
        add_filter('query_vars', array($this, 'register_query_vars'));
        add_filter('page_link', array($this, 'filter_page_link'), 10, 2);
        add_filter('redirect_canonical', array($this, 'disable_language_canonical_redirect'), 10, 2);
        add_action('parse_request', array($this, 'parse_language_request'));
        add_shortcode('mpt_language_switcher', array($this, 'render_language_switcher_shortcode'));
        add_action('wp_head', array($this, 'print_hreflang_links'));
    }

    public static function activate()
    {
        if (!get_option(self::OPTION_LANGUAGES)) {
            add_option(self::OPTION_LANGUAGES, array(
                array('code' => 'pt', 'name' => 'Portuguese', 'flag' => '🇵🇹', 'locale' => 'pt_PT', 'display' => '1'),
                array('code' => 'en', 'name' => 'English', 'flag' => '🇬🇧', 'locale' => 'en_GB', 'display' => '1'),
            ));
        }

        if (!get_option(self::OPTION_SETTINGS)) {
            add_option(self::OPTION_SETTINGS, array(
                'default_language' => 'pt',
                'menu_auto' => '0',
                'menu_location' => '',
                'translation_provider' => 'mymemory',
                'translation_endpoint' => '',
                'translation_api_key' => '',
                'translated_post_status' => 'pending',
            ));
        }

        update_option(self::OPTION_VERSION, '1.0.72');
        flush_rewrite_rules();
    }

    public function register_assets()
    {
        $url = plugin_dir_url(__FILE__);
        wp_register_style('mpt-front', $url . 'assets/mpt-front.css', array(), '1.0.72');
        wp_register_style('mpt-admin', $url . 'assets/mpt-admin.css', array(), '1.0.72');
        wp_register_script('mpt-front', $url . 'assets/mpt-front.js', array(), '1.0.72', true);
        wp_register_script('mpt-admin', $url . 'assets/mpt-admin.js', array(), '1.0.72', true);
    }

    public function enqueue_front_assets()
    {
        wp_enqueue_style('mpt-front');
        wp_enqueue_script('mpt-front');

        $current_lang = $this->get_current_language();
        $default_lang = $this->get_default_language();
        wp_add_inline_script(
            'mpt-front',
            'window.mptLanguage=' . wp_json_encode(array(
                'current' => $current_lang,
                'default' => $default_lang,
                'homeUrl' => $current_lang && $current_lang !== $default_lang ? home_url(user_trailingslashit($current_lang)) : home_url('/'),
                'rootUrl' => home_url('/'),
            )) . ';',
            'before'
        );
    }

    public function enqueue_admin_assets($hook)
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        $is_page_list = $hook === 'edit.php' && $screen && $screen->post_type === 'page';

        if (strpos((string) $hook, 'mpt-translator') !== false || $hook === 'post.php' || $hook === 'post-new.php' || $is_page_list) {
            wp_enqueue_style('mpt-admin');
        }

        if (strpos((string) $hook, 'mpt-translator') !== false) {
            wp_enqueue_script('mpt-admin');
            wp_localize_script('mpt-admin', 'mptAdmin', array(
                'duplicateMessage' => __('Duplicating and translating pages. This can take a few minutes. Please keep this tab open.', 'multilingual-page-translator'),
                'optionsMessage' => __('Translating option-page fields. This can take a few minutes. Please keep this tab open.', 'multilingual-page-translator'),
                'settingsMessage' => __('Saving settings...', 'multilingual-page-translator'),
                'buttonText' => __('Working...', 'multilingual-page-translator'),
            ));
        }
    }

    public function register_admin_menu()
    {
        add_menu_page(
            __('Page Translator', 'multilingual-page-translator'),
            __('Page Translator', 'multilingual-page-translator'),
            'manage_options',
            'mpt-translator',
            array($this, 'render_admin_page'),
            'dashicons-translation',
            58
        );
    }

    public function register_meta_boxes()
    {
        add_meta_box(
            'mpt_page_language',
            __('Translations', 'multilingual-page-translator'),
            array($this, 'render_page_meta_box'),
            'page',
            'side',
            'high'
        );
    }

    public function add_page_language_column($columns)
    {
        $new_columns = array();

        foreach ($columns as $key => $label) {
            $new_columns[$key] = $label;

            if ($key === 'title') {
                $new_columns['mpt_language'] = __('Language', 'multilingual-page-translator');
            }
        }

        if (!isset($new_columns['mpt_language'])) {
            $new_columns['mpt_language'] = __('Language', 'multilingual-page-translator');
        }

        return $new_columns;
    }

    public function render_page_language_column($column_name, $post_id)
    {
        if ($column_name !== 'mpt_language') {
            return;
        }

        $code = $this->get_post_language($post_id);
        $language = $this->find_language($code);
        $label = $this->get_post_language_label($post_id, true);

        printf(
            '<span class="mpt-admin-language-flag" title="%s" aria-label="%s">%s</span>',
            esc_attr($label),
            esc_attr($label),
            esc_html($language['flag'])
        );
    }

    public function render_page_meta_box($post)
    {
        wp_nonce_field(self::NONCE, self::NONCE);

        $current_lang = $this->get_post_language($post->ID);
        $group = $this->get_post_group($post->ID);
        $translations = $this->get_group_posts($group);
        $languages = $this->get_languages();

        echo '<p><label for="mpt_lang"><strong>' . esc_html__('Page language', 'multilingual-page-translator') . '</strong></label></p>';
        echo '<select name="mpt_lang" id="mpt_lang" class="widefat">';
        foreach ($languages as $language) {
            printf(
                '<option value="%s" %s>%s %s</option>',
                esc_attr($language['code']),
                selected($current_lang, $language['code'], false),
                esc_html($language['flag']),
                esc_html($language['name'])
            );
        }
        echo '</select>';

        echo '<p><strong>' . esc_html__('Connected pages', 'multilingual-page-translator') . '</strong></p>';
        echo '<ul class="mpt-translation-list">';
        foreach ($languages as $language) {
            $linked_id = isset($translations[$language['code']]) ? (int) $translations[$language['code']] : 0;
            echo '<li>';
            echo '<span>' . esc_html($language['flag'] . ' ' . $language['name']) . '</span>';
            if ($linked_id) {
                printf(' <a href="%s">%s</a>', esc_url(get_edit_post_link($linked_id)), esc_html__('Edit', 'multilingual-page-translator'));
                printf(' <a href="%s" target="_blank" rel="noopener">%s</a>', esc_url(get_permalink($linked_id)), esc_html__('View', 'multilingual-page-translator'));
            } else {
                $url = wp_nonce_url(
                    admin_url('admin-post.php?action=mpt_duplicate_page&post_id=' . (int) $post->ID . '&target_lang=' . rawurlencode($language['code'])),
                    self::NONCE
                );
                printf(' <a class="button button-small" href="%s">%s</a>', esc_url($url), esc_html__('Create', 'multilingual-page-translator'));
                if ($language['code'] !== $current_lang) {
                    $translate_url = wp_nonce_url(
                        admin_url('admin-post.php?action=mpt_duplicate_page&post_id=' . (int) $post->ID . '&target_lang=' . rawurlencode($language['code']) . '&auto_translate=1'),
                        self::NONCE
                    );
                    printf(' <a class="button button-small" href="%s">%s</a>', esc_url($translate_url), esc_html__('Create + Translate', 'multilingual-page-translator'));
                }
            }
            echo '</li>';
        }
        echo '</ul>';

        $review = get_post_meta($post->ID, self::META_REVIEW, true);
        if ($review) {
            echo '<p class="mpt-review-status"><strong>' . esc_html__('Review status:', 'multilingual-page-translator') . '</strong> ' . esc_html($this->review_status_label($review)) . '</p>';
        }

        $default_lang = $this->get_default_language();
        if ($current_lang !== $default_lang && !empty($translations[$default_lang])) {
            $retranslate_url = wp_nonce_url(
                admin_url('admin-post.php?action=mpt_duplicate_page&post_id=' . (int) $translations[$default_lang] . '&target_lang=' . rawurlencode($current_lang) . '&auto_translate=1'),
                self::NONCE
            );
            echo '<p><a class="button" href="' . esc_url($retranslate_url) . '">' . esc_html__('Retranslate from default language', 'multilingual-page-translator') . '</a></p>';
        }
    }

    public function save_page_language_meta($post_id, $post)
    {
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }

        if (!isset($_POST[self::NONCE]) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST[self::NONCE])), self::NONCE)) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (!current_user_can('edit_page', $post_id)) {
            return;
        }

        $lang = isset($_POST['mpt_lang']) ? sanitize_key(wp_unslash($_POST['mpt_lang'])) : $this->get_default_language();
        if (!$this->language_exists($lang)) {
            $lang = $this->get_default_language();
        }

        update_post_meta($post_id, self::META_LANG, $lang);

        if (!$this->get_post_group($post_id, false)) {
            update_post_meta($post_id, self::META_GROUP, $this->new_group_id());
        }
    }

    public function render_admin_page()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $languages = $this->get_languages();
        $settings = $this->get_settings();
        $pages = get_pages(array('sort_column' => 'post_title', 'sort_order' => 'ASC'));
        $default_target_language = $this->get_default_target_language($settings['default_language']);

        echo '<div class="wrap mpt-admin">';
        echo '<h1>' . esc_html__('Multilingual Page Translator', 'multilingual-page-translator') . '</h1>';

        if (isset($_GET['mpt_notice'])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(sanitize_text_field(wp_unslash($_GET['mpt_notice']))) . '</p></div>';
        }

        echo '<div class="mpt-grid">';
        echo '<section class="mpt-panel">';
        echo '<h2>' . esc_html__('Duplicate Pages', 'multilingual-page-translator') . '</h2>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" data-mpt-progress="duplicate">';
        wp_nonce_field(self::NONCE, self::NONCE);
        echo '<input type="hidden" name="action" value="mpt_bulk_duplicate">';
        echo '<p><label for="source_lang">' . esc_html__('Source language', 'multilingual-page-translator') . '</label></p>';
        echo '<select id="source_lang" name="source_lang">';
        foreach ($languages as $language) {
            printf('<option value="%s" %s>%s %s</option>', esc_attr($language['code']), selected($settings['default_language'], $language['code'], false), esc_html($language['flag']), esc_html($language['name']));
        }
        echo '</select>';

        echo '<p><label for="target_lang">' . esc_html__('Target language', 'multilingual-page-translator') . '</label></p>';
        echo '<select id="target_lang" name="target_lang">';
        foreach ($languages as $language) {
            if ($language['code'] === $settings['default_language']) {
                continue;
            }
            printf('<option value="%s" %s>%s %s</option>', esc_attr($language['code']), selected($default_target_language, $language['code'], false), esc_html($language['flag']), esc_html($language['name']));
        }
        echo '</select>';

        echo '<p><label for="page_scope">' . esc_html__('Pages', 'multilingual-page-translator') . '</label></p>';
        echo '<select id="page_scope" name="page_scope">';
        echo '<option value="all">' . esc_html__('All source-language pages', 'multilingual-page-translator') . '</option>';
        foreach ($pages as $page) {
            printf('<option value="%d">%s</option>', (int) $page->ID, esc_html($page->post_title));
        }
        echo '</select>';

        echo '<p><label><input type="checkbox" name="auto_translate" value="1" checked> ' . esc_html__('Automatically translate using the configured translation API', 'multilingual-page-translator') . '</label></p>';
        echo '<p class="description">' . esc_html__('Translated pages are created for editor review. Publish them after checking the automatic translation.', 'multilingual-page-translator') . '</p>';
        submit_button(__('Duplicate / Translate', 'multilingual-page-translator'));
        echo '</form>';

        echo '<hr>';
        echo '<h2>' . esc_html__('Translate Option Pages', 'multilingual-page-translator') . '</h2>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" data-mpt-progress="options">';
        wp_nonce_field(self::NONCE, self::NONCE);
        echo '<input type="hidden" name="action" value="mpt_translate_options">';
        echo '<p><label for="options_source_lang">' . esc_html__('Source language', 'multilingual-page-translator') . '</label></p>';
        echo '<select id="options_source_lang" name="source_lang">';
        foreach ($languages as $language) {
            printf('<option value="%s" %s>%s %s</option>', esc_attr($language['code']), selected($settings['default_language'], $language['code'], false), esc_html($language['flag']), esc_html($language['name']));
        }
        echo '</select>';
        echo '<p><label for="options_target_lang">' . esc_html__('Target language', 'multilingual-page-translator') . '</label></p>';
        echo '<select id="options_target_lang" name="target_lang">';
        foreach ($languages as $language) {
            if ($language['code'] === $settings['default_language']) {
                continue;
            }
            printf('<option value="%s" %s>%s %s</option>', esc_attr($language['code']), selected($default_target_language, $language['code'], false), esc_html($language['flag']), esc_html($language['name']));
        }
        echo '</select>';
        echo '<p class="description">' . esc_html__('Translates ACF option-page values such as footer text. The default-language options stay unchanged.', 'multilingual-page-translator') . '</p>';
        submit_button(__('Translate Options', 'multilingual-page-translator'), 'secondary');
        echo '</form>';

        echo '<hr>';
        echo '<h2>' . esc_html__('Duplicate Menus', 'multilingual-page-translator') . '</h2>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" data-mpt-progress="settings">';
        wp_nonce_field(self::NONCE, self::NONCE);
        echo '<input type="hidden" name="action" value="mpt_duplicate_menus">';
        echo '<p><label for="menus_source_lang">' . esc_html__('Source language', 'multilingual-page-translator') . '</label></p>';
        echo '<select id="menus_source_lang" name="source_lang">';
        foreach ($languages as $language) {
            printf('<option value="%s" %s>%s %s</option>', esc_attr($language['code']), selected($settings['default_language'], $language['code'], false), esc_html($language['flag']), esc_html($language['name']));
        }
        echo '</select>';
        echo '<p><label for="menus_target_lang">' . esc_html__('Target language', 'multilingual-page-translator') . '</label></p>';
        echo '<select id="menus_target_lang" name="target_lang">';
        foreach ($languages as $language) {
            if ($language['code'] === $settings['default_language']) {
                continue;
            }
            printf('<option value="%s" %s>%s %s</option>', esc_attr($language['code']), selected($default_target_language, $language['code'], false), esc_html($language['flag']), esc_html($language['name']));
        }
        echo '</select>';
        echo '<p class="description">' . esc_html__('Creates separate editable WordPress menus for the target language. Existing translated menus are not overwritten.', 'multilingual-page-translator') . '</p>';
        submit_button(__('Duplicate Menus', 'multilingual-page-translator'), 'secondary');
        echo '</form>';
        echo '</section>';

        echo '<section class="mpt-panel">';
        echo '<h2>' . esc_html__('Settings', 'multilingual-page-translator') . '</h2>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" data-mpt-progress="settings">';
        wp_nonce_field(self::NONCE, self::NONCE);
        echo '<input type="hidden" name="action" value="mpt_save_settings">';

        echo '<p><label for="mpt_languages"><strong>' . esc_html__('Languages JSON', 'multilingual-page-translator') . '</strong></label></p>';
        echo '<textarea id="mpt_languages" name="languages" rows="10" class="large-text code">' . esc_textarea(wp_json_encode($languages, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . '</textarea>';
        echo '<p class="description">' . esc_html__('Each language needs code, name, flag, locale, and display. Set display to "1" to show it in the front-end dropdown, or "0" to keep it available only in admin.', 'multilingual-page-translator') . '</p>';

        echo '<p><label for="default_language">' . esc_html__('Default language', 'multilingual-page-translator') . '</label></p>';
        echo '<input id="default_language" name="settings[default_language]" value="' . esc_attr($settings['default_language']) . '" class="regular-text">';

        echo '<p><label><input type="checkbox" name="settings[menu_auto]" value="1" ' . checked($settings['menu_auto'], '1', false) . '> ' . esc_html__('Automatically add flag dropdown to menus', 'multilingual-page-translator') . '</label></p>';
        echo '<p><label for="menu_location">' . esc_html__('Only this menu theme location (optional)', 'multilingual-page-translator') . '</label></p>';
        echo '<input id="menu_location" name="settings[menu_location]" value="' . esc_attr($settings['menu_location']) . '" class="regular-text" placeholder="primary">';

        echo '<p><label for="translated_post_status">' . esc_html__('Translated page status', 'multilingual-page-translator') . '</label></p>';
        echo '<select id="translated_post_status" name="settings[translated_post_status]">';
        echo '<option value="pending" ' . selected($settings['translated_post_status'], 'pending', false) . '>' . esc_html__('Pending review', 'multilingual-page-translator') . '</option>';
        echo '<option value="draft" ' . selected($settings['translated_post_status'], 'draft', false) . '>' . esc_html__('Draft', 'multilingual-page-translator') . '</option>';
        echo '</select>';

        submit_button(__('Save Settings', 'multilingual-page-translator'));
        echo '</form>';
        echo '</section>';
        echo '</div>';

        echo '<section class="mpt-panel">';
        echo '<h2>' . esc_html__('Usage', 'multilingual-page-translator') . '</h2>';
        echo '<p>' . esc_html__('Use the shortcode [mpt_language_switcher] anywhere, or enable automatic menu insertion above. Edit each translated page normally; connected translations stay linked through the Translations box.', 'multilingual-page-translator') . '</p>';
        echo '</section>';
        echo '</div>';
    }

    public function handle_save_settings()
    {
        $this->require_admin_nonce();

        $raw_languages = isset($_POST['languages']) ? wp_unslash($_POST['languages']) : '[]';
        $decoded = json_decode($raw_languages, true);
        if (!is_array($decoded)) {
            $decoded = $this->get_languages();
        }

        $languages = array();
        foreach ($decoded as $language) {
            if (!is_array($language) || empty($language['code']) || empty($language['name'])) {
                continue;
            }

            $languages[] = $this->normalize_language(array(
                'code' => sanitize_key($language['code']),
                'name' => sanitize_text_field($language['name']),
                'flag' => sanitize_text_field(isset($language['flag']) ? $language['flag'] : ''),
                'locale' => sanitize_text_field(isset($language['locale']) ? $language['locale'] : ''),
                'display' => isset($language['display']) && (string) $language['display'] === '0' ? '0' : '1',
            ));
        }

        if ($languages) {
            update_option(self::OPTION_LANGUAGES, $languages);
        }

        $posted = isset($_POST['settings']) && is_array($_POST['settings']) ? wp_unslash($_POST['settings']) : array();
        $default_language = isset($posted['default_language']) ? sanitize_key($posted['default_language']) : 'pt';
        if (!$this->language_exists($default_language)) {
            $default_language = 'pt';
        }

        $settings = array(
            'default_language' => $default_language,
            'menu_auto' => isset($posted['menu_auto']) ? '1' : '0',
            'menu_location' => isset($posted['menu_location']) ? sanitize_key($posted['menu_location']) : '',
            'translation_provider' => 'mymemory',
            'translation_endpoint' => '',
            'translation_api_key' => '',
            'translated_post_status' => isset($posted['translated_post_status']) && in_array($posted['translated_post_status'], array('draft', 'pending'), true) ? sanitize_key($posted['translated_post_status']) : 'pending',
        );
        update_option(self::OPTION_SETTINGS, $settings);

        wp_safe_redirect(add_query_arg('mpt_notice', rawurlencode(__('Settings saved.', 'multilingual-page-translator')), admin_url('admin.php?page=mpt-translator')));
        exit;
    }

    public function handle_duplicate_page()
    {
        $this->require_edit_nonce();

        $post_id = isset($_GET['post_id']) ? absint($_GET['post_id']) : 0;
        $target_lang = isset($_GET['target_lang']) ? sanitize_key(wp_unslash($_GET['target_lang'])) : '';
        $auto_translate = !empty($_GET['auto_translate']);

        if (!$post_id || !$this->language_exists($target_lang) || !current_user_can('edit_page', $post_id)) {
            wp_die(esc_html__('Invalid duplicate request.', 'multilingual-page-translator'));
        }

        if ($auto_translate) {
            $settings = $this->get_settings();
            if ($settings['translation_provider'] === 'libretranslate' && empty($settings['translation_endpoint'])) {
                wp_die(esc_html__('Automatic translation needs a real translation API endpoint in Page Translator settings.', 'multilingual-page-translator'));
            }

        }

        $new_id = $this->duplicate_page($post_id, $target_lang, $auto_translate);
        wp_safe_redirect(get_edit_post_link($new_id, ''));
        exit;
    }

    public function handle_bulk_duplicate()
    {
        $this->require_admin_nonce();

        $source_lang = isset($_POST['source_lang']) ? sanitize_key(wp_unslash($_POST['source_lang'])) : $this->get_default_language();
        $target_lang = isset($_POST['target_lang']) ? sanitize_key(wp_unslash($_POST['target_lang'])) : '';
        $page_scope = isset($_POST['page_scope']) ? sanitize_text_field(wp_unslash($_POST['page_scope'])) : 'all';
        $settings = $this->get_settings();
        $auto_translate = !empty($_POST['auto_translate']);

        if (!$this->language_exists($source_lang) || !$this->language_exists($target_lang) || $source_lang === $target_lang) {
            wp_die(esc_html__('Choose two different valid languages.', 'multilingual-page-translator'));
        }

        if ($auto_translate && $settings['translation_provider'] === 'libretranslate' && empty($settings['translation_endpoint'])) {
            wp_die(esc_html__('Automatic translation needs a real translation API endpoint in Page Translator settings. Without an endpoint, the plugin can duplicate pages for manual translation only.', 'multilingual-page-translator'));
        }

        $page_ids = array();
        if ($page_scope === 'all') {
            $meta_query = array(
                array(
                    'key' => self::META_LANG,
                    'value' => $source_lang,
                ),
            );

            if ($source_lang === $this->get_default_language()) {
                $meta_query = array(
                    'relation' => 'OR',
                    array(
                        'key' => self::META_LANG,
                        'value' => $source_lang,
                    ),
                    array(
                        'key' => self::META_LANG,
                        'compare' => 'NOT EXISTS',
                    ),
                );
            }

            $query = new WP_Query(array(
                'post_type' => 'page',
                'post_status' => array('publish', 'draft', 'private', 'pending'),
                'posts_per_page' => -1,
                'fields' => 'ids',
                'no_found_rows' => true,
                'update_post_meta_cache' => false,
                'update_post_term_cache' => false,
                // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Required to find source-language pages, including pages not tagged yet.
                'meta_query' => $meta_query,
            ));
            $page_ids = $query->posts;
        } else {
            $page_ids = array(absint($page_scope));
        }

        $count = 0;
        foreach ($page_ids as $page_id) {
            if ($page_id && current_user_can('edit_page', $page_id)) {
                $this->duplicate_page($page_id, $target_lang, $auto_translate);
                $count++;
            }
        }

        $this->rewrite_links_for_language($target_lang);
        $option_count = $auto_translate ? $this->translate_option_values($source_lang, $target_lang) : $this->copy_option_values($source_lang, $target_lang);

        $notice = sprintf(
            /* translators: 1: Number of pages processed. 2: Number of option values processed. */
            __('Processed %1$d page(s) and %2$d option value(s).', 'multilingual-page-translator'),
            $count,
            $option_count
        );
        wp_safe_redirect(add_query_arg('mpt_notice', rawurlencode($notice), admin_url('admin.php?page=mpt-translator')));
        exit;
    }

    public function handle_translate_options()
    {
        $this->require_admin_nonce();

        $source_lang = isset($_POST['source_lang']) ? sanitize_key(wp_unslash($_POST['source_lang'])) : $this->get_default_language();
        $target_lang = isset($_POST['target_lang']) ? sanitize_key(wp_unslash($_POST['target_lang'])) : '';

        if (!$this->language_exists($source_lang) || !$this->language_exists($target_lang) || $source_lang === $target_lang) {
            wp_die(esc_html__('Choose two different valid languages.', 'multilingual-page-translator'));
        }

        $count = $this->translate_option_values($source_lang, $target_lang);

        /* translators: %d: Number of option values translated. */
        wp_safe_redirect(add_query_arg('mpt_notice', rawurlencode(sprintf(__('Translated %d option value(s).', 'multilingual-page-translator'), $count)), admin_url('admin.php?page=mpt-translator')));
        exit;
    }

    public function handle_duplicate_menus()
    {
        $this->require_admin_nonce();

        $source_lang = isset($_POST['source_lang']) ? sanitize_key(wp_unslash($_POST['source_lang'])) : $this->get_default_language();
        $target_lang = isset($_POST['target_lang']) ? sanitize_key(wp_unslash($_POST['target_lang'])) : '';

        if (!$this->language_exists($source_lang) || !$this->language_exists($target_lang) || $source_lang === $target_lang) {
            wp_die(esc_html__('Choose two different valid languages.', 'multilingual-page-translator'));
        }

        $result = $this->duplicate_language_menus($source_lang, $target_lang);
        $notice = sprintf(
            /* translators: 1: Number of menus created. 2: Number of existing menus skipped. */
            __('Created %1$d translated menu(s). Kept %2$d existing translated menu(s).', 'multilingual-page-translator'),
            $result['created'],
            $result['existing']
        );

        wp_safe_redirect(add_query_arg('mpt_notice', rawurlencode($notice), admin_url('admin.php?page=mpt-translator')));
        exit;
    }

    private function duplicate_language_menus($source_lang, $target_lang)
    {
        $locations = $this->get_source_menu_locations();
        $created = 0;
        $existing = 0;

        foreach ($locations as $location => $source_menu_id) {
            update_term_meta($source_menu_id, self::MENU_META_LANG, $source_lang);
            update_term_meta($source_menu_id, self::MENU_META_LOCATION, $location);

            $target_menu_id = $this->get_translated_menu_id($source_menu_id, $target_lang, $location);
            if ($target_menu_id) {
                $existing++;
                continue;
            }

            $target_menu_id = $this->create_translated_menu($source_menu_id, $source_lang, $target_lang, $location);
            if ($target_menu_id) {
                $created++;
            }
        }

        return array(
            'created' => $created,
            'existing' => $existing,
        );
    }

    private function get_source_menu_locations()
    {
        $settings = $this->get_settings();
        $assigned_locations = get_nav_menu_locations();
        $locations = array();

        foreach ($assigned_locations as $location => $menu_id) {
            $menu_id = (int) $menu_id;
            if (!$menu_id) {
                continue;
            }

            if ($settings['menu_location'] && $location !== $settings['menu_location']) {
                continue;
            }

            $locations[$location] = $menu_id;
        }

        return $locations;
    }

    private function create_translated_menu($source_menu_id, $source_lang, $target_lang, $location)
    {
        $source_menu = wp_get_nav_menu_object($source_menu_id);
        if (!$source_menu) {
            return 0;
        }

        $target_menu_id = wp_create_nav_menu($source_menu->name . ' (' . strtoupper($target_lang) . ')');
        if (is_wp_error($target_menu_id) || !$target_menu_id) {
            return 0;
        }

        update_term_meta($target_menu_id, self::MENU_META_LANG, $target_lang);
        update_term_meta($target_menu_id, self::MENU_META_SOURCE, (int) $source_menu_id);
        update_term_meta($target_menu_id, self::MENU_META_LOCATION, $location);
        $this->copy_menu_items($source_menu_id, $target_menu_id, $source_lang, $target_lang);

        return (int) $target_menu_id;
    }

    private function copy_menu_items($source_menu_id, $target_menu_id, $source_lang, $target_lang)
    {
        $items = wp_get_nav_menu_items($source_menu_id, array('post_status' => 'any'));
        if (!$items || is_wp_error($items)) {
            return;
        }

        $created_items = array();
        foreach ($items as $item) {
            $parent_id = !empty($item->menu_item_parent) && isset($created_items[(int) $item->menu_item_parent]) ? $created_items[(int) $item->menu_item_parent] : 0;
            $type = (string) $item->type;
            $object = (string) $item->object;
            $object_id = (int) $item->object_id;
            $url = (string) $item->url;
            $title = (string) $item->title;

            if ($type === 'post_type' && $object === 'page') {
                $translated_id = $this->get_translated_menu_page_id($object_id, $target_lang);
                if ($translated_id) {
                    $object_id = $translated_id;
                    $url = '';
                    $title = get_the_title($translated_id);
                }
            } elseif ($type === 'custom') {
                $custom_link = $this->translate_custom_menu_link($url, $target_lang);
                $url = $custom_link['url'];
                if ($custom_link['title'] !== '') {
                    $title = $custom_link['title'];
                }
            }

            if ($title !== '') {
                $title = $this->translate_persistent_string($title, $source_lang, $target_lang, 'menu_title_copy_' . (int) $item->db_id);
            }

            $new_item_id = wp_update_nav_menu_item($target_menu_id, 0, array(
                'menu-item-object-id' => $object_id,
                'menu-item-object' => $object,
                'menu-item-parent-id' => $parent_id,
                'menu-item-position' => (int) $item->menu_order,
                'menu-item-type' => $type,
                'menu-item-title' => $title,
                'menu-item-url' => $url,
                'menu-item-description' => (string) $item->description,
                'menu-item-attr-title' => (string) $item->attr_title,
                'menu-item-target' => (string) $item->target,
                'menu-item-classes' => is_array($item->classes) ? implode(' ', array_filter($item->classes)) : '',
                'menu-item-xfn' => (string) $item->xfn,
                'menu-item-status' => 'publish',
            ));

            if (!is_wp_error($new_item_id) && $new_item_id) {
                $created_items[(int) $item->db_id] = (int) $new_item_id;
            }
        }
    }

    private function get_translated_menu_page_id($page_id, $target_lang)
    {
        $group = $this->get_post_group($page_id, false);
        if (!$group) {
            return 0;
        }

        return $this->get_translation_id($group, $target_lang, array('publish', 'draft', 'private', 'pending'));
    }

    private function translate_custom_menu_link($url, $target_lang)
    {
        $url = (string) $url;
        $page_id = $this->get_page_id_from_menu_url($url);
        if ($page_id) {
            $translated_id = $this->get_translated_menu_page_id($page_id, $target_lang);
            if ($translated_id) {
                return array(
                    'url' => $this->append_url_fragment($this->get_language_permalink($translated_id, $target_lang), $url),
                    'title' => get_the_title($translated_id),
                );
            }
        }

        return array(
            'url' => $this->translate_menu_url($url, $target_lang),
            'title' => '',
        );
    }

    private function get_page_id_from_menu_url($url)
    {
        $url = trim((string) $url);
        if ($url === '' || $url === '#' || strpos($url, '#') === 0) {
            return 0;
        }

        $absolute_url = $this->absolute_menu_url($url);
        $page_id = url_to_postid($absolute_url);
        if ($page_id && get_post_type($page_id) === 'page') {
            return (int) $page_id;
        }

        $path = trim((string) wp_parse_url($absolute_url, PHP_URL_PATH), '/');
        if ($path === '') {
            return (int) get_option('page_on_front');
        }

        $path = preg_replace('/^(?:' . implode('|', array_map('preg_quote', wp_list_pluck($this->get_languages(), 'code'))) . ')\//', '', $path);
        $pages = get_pages(array('post_status' => array('publish', 'draft', 'private', 'pending')));
        foreach ($pages as $page) {
            $page_path = trim((string) wp_parse_url(get_permalink($page->ID), PHP_URL_PATH), '/');
            $page_path = preg_replace('/^(?:' . implode('|', array_map('preg_quote', wp_list_pluck($this->get_languages(), 'code'))) . ')\//', '', $page_path);
            if ($page_path === $path) {
                return (int) $page->ID;
            }
        }

        return 0;
    }

    private function absolute_menu_url($url)
    {
        $url = trim((string) $url);
        if (preg_match('#^https?://#i', $url)) {
            return $url;
        }

        if (strpos($url, '//') === 0) {
            return (is_ssl() ? 'https:' : 'http:') . $url;
        }

        return home_url('/' . ltrim($url, '/'));
    }

    private function append_url_fragment($target_url, $source_url)
    {
        $fragment = (string) wp_parse_url($source_url, PHP_URL_FRAGMENT);
        if ($fragment === '') {
            return $target_url;
        }

        return untrailingslashit($target_url) . '/#' . rawurlencode($fragment);
    }

    private function duplicate_page($source_id, $target_lang, $auto_translate)
    {
        $source = get_post($source_id);
        if (!$source || $source->post_type !== 'page') {
            return 0;
        }

        $source_lang = $this->get_post_language($source_id);
        $group = $this->get_post_group($source_id);
        $existing = $this->get_translation_id($group, $target_lang);
        $settings = $this->get_settings();
        $title = $source->post_title;
        $content = $source->post_content;
        $excerpt = $source->post_excerpt;
        $this->attachment_clone_cache = array();

        if ($auto_translate) {
            $title = $this->translate_text($title, $source_lang, $target_lang);
            $content = $this->translate_post_content($content, $source_lang, $target_lang);
            $excerpt = $this->translate_text($excerpt, $source_lang, $target_lang);
        }

        $post_data = array(
            'post_type' => 'page',
            'post_status' => $existing ? get_post_status($existing) : $settings['translated_post_status'],
            'post_author' => get_current_user_id() ?: $source->post_author,
            'post_title' => $title,
            'post_content' => $content,
            'post_excerpt' => $excerpt,
            'post_parent' => $this->translated_parent_id($source->post_parent, $target_lang),
            'menu_order' => $source->menu_order,
            'comment_status' => $source->comment_status,
            'ping_status' => $source->ping_status,
            'post_name' => $this->translated_slug($source, $target_lang),
        );

        if ($existing) {
            $post_data['ID'] = $existing;
            $new_id = wp_update_post(wp_slash($post_data), true);
        } else {
            $new_id = wp_insert_post(wp_slash($post_data), true);
        }

        if (is_wp_error($new_id) || !$new_id) {
            return 0;
        }

        update_post_meta($source_id, self::META_GROUP, $group);
        update_post_meta($new_id, self::META_GROUP, $group);
        update_post_meta($new_id, self::META_LANG, $target_lang);
        update_post_meta($new_id, self::META_SOURCE, $source_id);
        update_post_meta($new_id, self::META_REVIEW, $auto_translate ? 'needs_editor_review' : 'manual_translation_needed');

        $this->copy_post_meta($source_id, $new_id, $source_lang, $target_lang, $auto_translate);
        $this->rewrite_post_links($new_id, $target_lang);

        return (int) $new_id;
    }

    private function copy_post_meta($source_id, $target_id, $source_lang, $target_lang, $auto_translate)
    {
        $protected = array('_edit_lock', '_edit_last', self::META_LANG, self::META_GROUP, self::META_SOURCE, self::META_REVIEW);
        $meta = get_post_meta($source_id);

        foreach ($meta as $key => $values) {
            if (in_array($key, $protected, true)) {
                continue;
            }

            delete_post_meta($target_id, $key);
            foreach ($values as $value) {
                $value = maybe_unserialize($value);
                $field_type = $this->get_acf_post_field_type($source_id, $key);
                $value = $this->clone_media_value($value, $field_type, $target_lang);
                if ($auto_translate && $this->should_translate_acf_value($key, $value, $field_type)) {
                    $value = $this->translate_meta_value($value, $source_lang, $target_lang);
                }
                $value = $this->replace_links_in_value($value, $target_lang);
                add_post_meta($target_id, $key, $value);
            }
        }
    }

    private function should_translate_meta_key($key)
    {
        $key = (string) $key;

        if ($key === '' || $key[0] === '_') {
            return false;
        }

        return $this->should_translate_acf_value($key, '', '');
    }

    private function translate_meta_value($value, $source_lang, $target_lang)
    {
        if (is_string($value) && $this->should_translate_string($value)) {
            return $this->translate_content_preserving_html($value, $source_lang, $target_lang);
        }

        if (is_array($value)) {
            foreach ($value as $key => $item) {
                if ($this->is_non_translatable_acf_key($key, $item)) {
                    continue;
                }

                $value[$key] = $this->translate_meta_value($item, $source_lang, $target_lang);
            }
        }

        return $value;
    }

    private function clone_media_value($value, $field_type, $target_lang)
    {
        if (!in_array((string) $field_type, array('file', 'image', 'gallery'), true)) {
            return $value;
        }

        if (is_numeric($value)) {
            return $this->clone_attachment_for_language((int) $value, $target_lang);
        }

        if (is_array($value)) {
            foreach ($value as $key => $item) {
                if (is_numeric($item)) {
                    $value[$key] = $this->clone_attachment_for_language((int) $item, $target_lang);
                    continue;
                }

                if (is_array($item) && isset($item['ID']) && is_numeric($item['ID'])) {
                    $cloned_id = $this->clone_attachment_for_language((int) $item['ID'], $target_lang);
                    $value[$key]['ID'] = $cloned_id;
                    $value[$key]['id'] = $cloned_id;
                }
            }
        }

        return $value;
    }

    private function clone_attachment_for_language($attachment_id, $target_lang)
    {
        $attachment_id = absint($attachment_id);
        if (!$attachment_id || get_post_type($attachment_id) !== 'attachment') {
            return $attachment_id;
        }

        $cache_key = $attachment_id . '|' . $target_lang;
        if (isset($this->attachment_clone_cache[$cache_key])) {
            return $this->attachment_clone_cache[$cache_key];
        }

        $existing = get_posts(array(
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'posts_per_page' => 1,
            'fields' => 'ids',
            'no_found_rows' => true,
            'meta_query' => array(
                array(
                    'key' => self::META_SOURCE_ATTACHMENT,
                    'value' => $attachment_id,
                ),
                array(
                    'key' => self::META_LANG,
                    'value' => $target_lang,
                ),
            ),
        ));

        if (!empty($existing[0])) {
            $this->attachment_clone_cache[$cache_key] = (int) $existing[0];
            return (int) $existing[0];
        }

        $file = get_attached_file($attachment_id);
        if (!$file || !file_exists($file) || !is_readable($file)) {
            return $attachment_id;
        }

        $upload_dir = wp_upload_dir();
        if (!empty($upload_dir['error']) || empty($upload_dir['path'])) {
            return $attachment_id;
        }

        $info = pathinfo($file);
        $extension = isset($info['extension']) && $info['extension'] !== '' ? '.' . strtolower($info['extension']) : '';
        $base_name = sanitize_file_name(($info['filename'] ?? 'attachment') . '-' . $target_lang . $extension);
        $unique_name = wp_unique_filename($upload_dir['path'], $base_name);
        $target_file = trailingslashit($upload_dir['path']) . $unique_name;

        if (!copy($file, $target_file)) {
            return $attachment_id;
        }

        $source = get_post($attachment_id);
        $attachment_data = array(
            'post_mime_type' => get_post_mime_type($attachment_id),
            'post_title' => $source ? $source->post_title : preg_replace('/\.[^.]+$/', '', $unique_name),
            'post_content' => $source ? $source->post_content : '',
            'post_excerpt' => $source ? $source->post_excerpt : '',
            'post_status' => 'inherit',
        );

        $new_id = wp_insert_attachment(wp_slash($attachment_data), $target_file);
        if (is_wp_error($new_id) || !$new_id) {
            return $attachment_id;
        }

        update_post_meta($new_id, self::META_SOURCE_ATTACHMENT, $attachment_id);
        update_post_meta($new_id, self::META_LANG, $target_lang);

        $alt = get_post_meta($attachment_id, '_wp_attachment_image_alt', true);
        if ($alt !== '') {
            update_post_meta($new_id, '_wp_attachment_image_alt', $alt);
        }

        if (!function_exists('wp_generate_attachment_metadata')) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        $metadata = wp_generate_attachment_metadata($new_id, $target_file);
        if (!is_wp_error($metadata) && !empty($metadata)) {
            wp_update_attachment_metadata($new_id, $metadata);
        }

        $this->attachment_clone_cache[$cache_key] = (int) $new_id;
        return (int) $new_id;
    }

    private function translate_post_content($content, $source_lang, $target_lang)
    {
        if (strpos($content, '<!-- wp:') === false || !function_exists('parse_blocks') || !function_exists('serialize_blocks')) {
            return $this->translate_content_preserving_html($content, $source_lang, $target_lang);
        }

        $blocks = parse_blocks($content);
        if (!is_array($blocks) || !$blocks) {
            return $this->translate_content_preserving_html($content, $source_lang, $target_lang);
        }

        foreach ($blocks as $index => $block) {
            $blocks[$index] = $this->translate_block($block, $source_lang, $target_lang);
        }

        return serialize_blocks($blocks);
    }

    private function translate_block($block, $source_lang, $target_lang)
    {
        if (!is_array($block)) {
            return $block;
        }

        if (isset($block['attrs']['data']) && is_array($block['attrs']['data'])) {
            $block['attrs']['data'] = $this->translate_acf_block_data($block['attrs']['data'], $source_lang, $target_lang);
        }

        if (isset($block['innerHTML']) && is_string($block['innerHTML']) && $block['innerHTML'] !== '') {
            $block['innerHTML'] = $this->translate_content_preserving_html($block['innerHTML'], $source_lang, $target_lang);
        }

        if (isset($block['innerContent']) && is_array($block['innerContent'])) {
            foreach ($block['innerContent'] as $key => $content) {
                if (is_string($content) && $content !== '') {
                    $block['innerContent'][$key] = $this->translate_content_preserving_html($content, $source_lang, $target_lang);
                }
            }
        }

        if (isset($block['innerBlocks']) && is_array($block['innerBlocks'])) {
            foreach ($block['innerBlocks'] as $key => $inner_block) {
                $block['innerBlocks'][$key] = $this->translate_block($inner_block, $source_lang, $target_lang);
            }
        }

        return $block;
    }

    private function translate_acf_block_data($data, $source_lang, $target_lang)
    {
        foreach ($data as $key => $value) {
            $field_type = $this->get_acf_block_field_type($data, $key);
            $data[$key] = $this->clone_media_value($value, $field_type, $target_lang);
            $value = $data[$key];

            if (is_array($value)) {
                $data[$key] = $this->translate_acf_block_data($value, $source_lang, $target_lang);
                continue;
            }

            if (!$this->should_translate_acf_value($key, $value, $field_type)) {
                continue;
            }

            if (is_string($value) && $this->should_translate_string($value)) {
                $data[$key] = $this->translate_content_preserving_html($value, $source_lang, $target_lang);
            }
        }

        return $data;
    }

    private function should_translate_acf_value($key, $value, $field_type = '')
    {
        $key = (string) $key;
        $field_type = (string) $field_type;

        if ($key === '' || $key[0] === '_' || is_numeric($value) || is_bool($value) || $value === null) {
            return false;
        }

        if ($this->is_always_non_translatable_acf_key($key)) {
            return false;
        }

        if ($this->is_translatable_acf_field_type($field_type)) {
            return true;
        }

        if ($this->is_hard_non_translatable_acf_key($key)) {
            return false;
        }

        return !$this->is_non_translatable_acf_key($key, $value);
    }

    private function is_translatable_acf_field_type($field_type)
    {
        return in_array((string) $field_type, array('text', 'textarea', 'wysiwyg'), true);
    }

    private function get_acf_block_field_type($data, $key)
    {
        $field_key = '';
        if (isset($data['_' . $key]) && is_string($data['_' . $key])) {
            $field_key = $data['_' . $key];
        }

        return $this->get_acf_field_type_by_key($field_key);
    }

    private function get_acf_post_field_type($post_id, $key)
    {
        return $this->get_acf_field_type_by_key((string) get_post_meta($post_id, '_' . $key, true));
    }

    private function get_acf_option_field_type($option_name)
    {
        return $this->get_acf_field_type_by_key((string) get_option($this->acf_option_reference_name($option_name), ''));
    }

    private function get_acf_field_type_by_key($field_key)
    {
        if ($field_key === '' || !function_exists('acf_get_field')) {
            return '';
        }

        $field = acf_get_field($field_key);
        return is_array($field) && isset($field['type']) ? (string) $field['type'] : '';
    }

    private function acf_option_reference_name($option_name)
    {
        if (strpos($option_name, 'options_') === 0) {
            return '_options_' . substr($option_name, 8);
        }

        if (strpos($option_name, 'options_' . $this->get_default_language() . '_') === 0) {
            return '_options_' . substr($option_name, 8);
        }

        return '_' . ltrim((string) $option_name, '_');
    }

    private function is_non_translatable_acf_key($key, $value)
    {
        $key = (string) $key;

        if ($key === '' || $key[0] === '_') {
            return true;
        }

        if (is_numeric($value) || is_bool($value) || $value === null) {
            return true;
        }

        if ($this->is_hard_non_translatable_acf_key($key)) {
            return true;
        }

        if (preg_match('/(^|_)(label|title|heading|subtitle|description|text|note|prefix|eyebrow|caption)(_|$)/i', $key)) {
            return false;
        }

        if (preg_match('/(^|_)(id|ids|url|uri|href|src|link|image|images|gallery|file|files|video|icon|class|style|color|background|anchor|align|layout|template|target|rel|email|phone|tel|slug|name|type|size|width|height|lat|lng|map|date|time|css|filter)(_|$)/i', $key)) {
            return true;
        }

        return false;
    }

    private function is_hard_non_translatable_acf_key($key)
    {
        return (bool) preg_match('/(^|_)(id|ids|url|uri|href|src|link|image|images|gallery|file|files|video|icon|class|style|color|background|anchor|align|layout|template|target|rel|email|phone_number|whatsapp_number|tel|slug|name|first_name|last_name|type|size|width|height|lat|lng|map|date|time|css|filter)(_|$)/i', (string) $key);
    }

    private function is_always_non_translatable_acf_key($key)
    {
        return (bool) preg_match('/(^|_)(id|ids|url|uri|href|src|link|file|files|video|email|phone_number|whatsapp_number|tel|slug|name|first_name|last_name|type|css|filter)(_|$)/i', (string) $key);
    }

    private function translate_content_preserving_html($content, $source_lang, $target_lang)
    {
        if (!$this->should_translate_string($content)) {
            return $content;
        }

        if (strpos($content, '<') === false) {
            return $this->translate_text($content, $source_lang, $target_lang);
        }

        $parts = preg_split('/(<[^>]+>)/u', $content, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (!is_array($parts)) {
            return $content;
        }

        foreach ($parts as $index => $part) {
            if ($part === '' || preg_match('/^<[^>]+>$/u', $part) || trim($part) === '') {
                continue;
            }

            $parts[$index] = $this->translate_text($part, $source_lang, $target_lang);
        }

        return implode('', $parts);
    }

    private function translate_text($text, $source_lang, $target_lang)
    {
        $settings = $this->get_settings();
        if (trim((string) $text) === '') {
            return $text;
        }

        $cache_key = md5($source_lang . '|' . $target_lang . '|' . $text);
        if (isset($this->translation_cache[$cache_key])) {
            return $this->translation_cache[$cache_key];
        }

        if ($settings['translation_provider'] === 'mymemory') {
            $translated = $this->translate_text_with_public_providers($text, $source_lang, $target_lang);
            $this->translation_cache[$cache_key] = $translated;
            return $translated;
        }

        $body = array(
            'q' => $text,
            'source' => $source_lang,
            'target' => $target_lang,
            'format' => 'html',
        );

        if ($settings['translation_api_key']) {
            $body['api_key'] = $settings['translation_api_key'];
        }

        $endpoint = $this->sanitize_translation_endpoint($settings['translation_endpoint']);
        if (!$endpoint) {
            return $text;
        }

        $response = wp_remote_post($endpoint, array(
            'timeout' => 20,
            'redirection' => 2,
            'limit_response_size' => 1048576,
            'headers' => array('Content-Type' => 'application/json'),
            'body' => wp_json_encode($body),
        ));

        if (is_wp_error($response)) {
            return $text;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            return $text;
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (isset($data['translatedText']) && is_string($data['translatedText'])) {
            $this->translation_cache[$cache_key] = wp_kses_post($data['translatedText']);
            return $this->translation_cache[$cache_key];
        }

        if (isset($data['translation']) && is_string($data['translation'])) {
            $this->translation_cache[$cache_key] = wp_kses_post($data['translation']);
            return $this->translation_cache[$cache_key];
        }

        if (isset($data['data']['translations'][0]['translatedText']) && is_string($data['data']['translations'][0]['translatedText'])) {
            $this->translation_cache[$cache_key] = wp_kses_post($data['data']['translations'][0]['translatedText']);
            return $this->translation_cache[$cache_key];
        }

        return $text;
    }

    private function translate_text_with_public_providers($text, $source_lang, $target_lang)
    {
        $translated = $this->translate_text_with_mymemory($text, $source_lang, $target_lang);
        if ($this->translation_changed($text, $translated)) {
            return $translated;
        }

        $translated = $this->translate_text_with_google_fallback($text, $source_lang, $target_lang);
        if ($this->translation_changed($text, $translated)) {
            return $translated;
        }

        return $text;
    }

    private function translate_text_with_mymemory($text, $source_lang, $target_lang)
    {
        $chunks = $this->split_translation_text($text, 450);
        $translated_chunks = array();

        foreach ($chunks as $chunk) {
            if (trim($chunk) === '') {
                $translated_chunks[] = $chunk;
                continue;
            }

            $url = add_query_arg(array(
                'q' => $chunk,
                'langpair' => $source_lang . '|' . $target_lang,
                'mt' => '1',
            ), 'https://api.mymemory.translated.net/get');

            $response = wp_remote_get($url, array(
                'timeout' => 12,
                'redirection' => 2,
                'limit_response_size' => 65536,
            ));

            if (is_wp_error($response)) {
                return $text;
            }

            $code = (int) wp_remote_retrieve_response_code($response);
            if ($code < 200 || $code >= 300) {
                return $text;
            }

            $data = json_decode(wp_remote_retrieve_body($response), true);
            if (isset($data['responseData']['translatedText']) && is_string($data['responseData']['translatedText'])) {
                $translated_chunks[] = wp_kses_post($data['responseData']['translatedText']);
                continue;
            }

            return $text;
        }

        return implode('', $translated_chunks);
    }

    private function translate_text_with_google_fallback($text, $source_lang, $target_lang)
    {
        $chunks = $this->split_translation_text($text, 450);
        $translated_chunks = array();

        foreach ($chunks as $chunk) {
            if (trim($chunk) === '') {
                $translated_chunks[] = $chunk;
                continue;
            }

            $url = add_query_arg(array(
                'client' => 'gtx',
                'sl' => $source_lang,
                'tl' => $target_lang,
                'dt' => 't',
                'q' => $chunk,
            ), 'https://translate.googleapis.com/translate_a/single');

            $response = wp_remote_get($url, array(
                'timeout' => 12,
                'redirection' => 2,
                'limit_response_size' => 65536,
                'headers' => array(
                    'Accept' => 'application/json',
                    'User-Agent' => 'WordPress/' . get_bloginfo('version') . '; ' . home_url('/'),
                ),
            ));

            if (is_wp_error($response)) {
                return $text;
            }

            $code = (int) wp_remote_retrieve_response_code($response);
            if ($code < 200 || $code >= 300) {
                return $text;
            }

            $data = json_decode(wp_remote_retrieve_body($response), true);
            if (!isset($data[0]) || !is_array($data[0])) {
                return $text;
            }

            $translated_chunk = '';
            foreach ($data[0] as $part) {
                if (isset($part[0]) && is_string($part[0])) {
                    $translated_chunk .= $part[0];
                }
            }

            if ($translated_chunk === '') {
                return $text;
            }

            $translated_chunks[] = wp_kses_post($translated_chunk);
        }

        return implode('', $translated_chunks);
    }

    private function translation_changed($source, $translated)
    {
        return is_string($translated) && trim($translated) !== '' && trim($translated) !== trim((string) $source);
    }

    private function split_translation_text($text, $max_bytes)
    {
        if (strlen($text) <= $max_bytes) {
            return array($text);
        }

        $parts = preg_split('/(?<=[.!?;:])(\s+)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (!is_array($parts) || count($parts) < 2) {
            return $this->split_text_by_bytes($text, $max_bytes);
        }

        $chunks = array();
        $current = '';
        foreach ($parts as $part) {
            if (strlen($current . $part) > $max_bytes && $current !== '') {
                $chunks[] = $current;
                $current = '';
            }

            if (strlen($part) > $max_bytes) {
                if ($current !== '') {
                    $chunks[] = $current;
                    $current = '';
                }
                $chunks = array_merge($chunks, $this->split_text_by_bytes($part, $max_bytes));
                continue;
            }

            $current .= $part;
        }

        if ($current !== '') {
            $chunks[] = $current;
        }

        return $chunks;
    }

    private function split_text_by_bytes($text, $max_bytes)
    {
        $chunks = array();
        while ($text !== '') {
            if (strlen($text) <= $max_bytes) {
                $chunks[] = $text;
                break;
            }

            if (function_exists('mb_strcut')) {
                $chunk = mb_strcut($text, 0, $max_bytes, 'UTF-8');
                $chunks[] = $chunk;
                $text = (string) substr($text, strlen($chunk));
                continue;
            }

            $chunks[] = substr($text, 0, $max_bytes);
            $text = substr($text, $max_bytes);
        }

        return $chunks;
    }

    private function translation_api_is_available($source_lang, $target_lang)
    {
        $settings = $this->get_settings();
        if ($settings['translation_provider'] === 'mymemory') {
            $translated = $this->translate_text_with_public_providers('Olá mundo', $source_lang, $target_lang);
            return $translated !== 'Olá mundo';
        }

        $endpoint = $this->sanitize_translation_endpoint($settings['translation_endpoint']);
        if (!$endpoint) {
            return false;
        }

        $body = array(
            'q' => 'Olá mundo',
            'source' => $source_lang,
            'target' => $target_lang,
            'format' => 'text',
        );

        if ($settings['translation_api_key']) {
            $body['api_key'] = $settings['translation_api_key'];
        }

        $response = wp_remote_post($endpoint, array(
            'timeout' => 10,
            'redirection' => 2,
            'limit_response_size' => 65536,
            'headers' => array('Content-Type' => 'application/json'),
            'body' => wp_json_encode($body),
        ));

        if (is_wp_error($response)) {
            return false;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            return false;
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        return isset($data['translatedText']) || isset($data['translation']) || isset($data['data']['translations'][0]['translatedText']);
    }

    private function translate_persistent_string($text, $source_lang, $target_lang, $context)
    {
        if (!$this->should_translate_string($text)) {
            return $text;
        }

        $option_name = 'mpt_string_' . md5($context . '|' . $source_lang . '|' . $target_lang . '|' . $text);
        $stored = get_option($option_name, null);
        if (is_string($stored) && $stored !== '') {
            return $stored;
        }

        if (!$this->is_admin_translation_request()) {
            return $text;
        }

        $translated = $this->translate_text($text, $source_lang, $target_lang);
        if ($translated !== $text && is_string($translated) && $translated !== '') {
            update_option($option_name, $translated, false);
        }

        return $translated;
    }

    private function is_admin_translation_request()
    {
        if (!is_admin() || !current_user_can('manage_options')) {
            return false;
        }

        $action = '';
        if (isset($_POST['action'])) {
            $action = sanitize_key(wp_unslash($_POST['action']));
        } elseif (isset($_GET['action'])) {
            $action = sanitize_key(wp_unslash($_GET['action']));
        }

        return in_array($action, array('mpt_duplicate_page', 'mpt_bulk_duplicate', 'mpt_translate_options', 'mpt_duplicate_menus'), true);
    }

    private function should_translate_string($value)
    {
        if (!is_string($value) || trim($value) === '') {
            return false;
        }

        if (preg_match('/^[\d\s.,:;#_\-\/]+$/', $value)) {
            return false;
        }

        return true;
    }

    private function translated_parent_id($parent_id, $target_lang)
    {
        if (!$parent_id) {
            return 0;
        }

        $group = $this->get_post_group($parent_id, false);
        if (!$group) {
            return 0;
        }

        return $this->get_translation_id($group, $target_lang);
    }

    private function translated_slug($source, $target_lang)
    {
        $base = $source->post_name ?: sanitize_title($source->post_title);
        return wp_unique_post_slug($base . '-' . $target_lang, 0, 'draft', 'page', $this->translated_parent_id($source->post_parent, $target_lang));
    }

    private function rewrite_links_for_language($target_lang)
    {
        $query = new WP_Query(array(
            'post_type' => 'page',
            'post_status' => array('publish', 'draft', 'private', 'pending'),
            'posts_per_page' => -1,
            'fields' => 'ids',
            'no_found_rows' => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Required to rewrite links only on pages in the selected target language.
            'meta_key' => self::META_LANG,
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Required to rewrite links only on pages in the selected target language.
            'meta_value' => $target_lang,
        ));

        foreach ($query->posts as $post_id) {
            $this->rewrite_post_links($post_id, $target_lang);
        }
    }

    private function rewrite_post_links($post_id, $target_lang)
    {
        $post = get_post($post_id);
        if (!$post) {
            return;
        }

        $content = $this->replace_links_in_value($post->post_content, $target_lang);
        if ($content !== $post->post_content) {
            remove_action('save_post_page', array($this, 'save_page_language_meta'), 10);
            wp_update_post(array('ID' => $post_id, 'post_content' => wp_slash($content)));
            add_action('save_post_page', array($this, 'save_page_language_meta'), 10, 2);
        }
    }

    private function replace_links_in_value($value, $target_lang, $statuses = null)
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = $this->replace_links_in_value($item, $target_lang, $statuses);
            }
            return $value;
        }

        if (!is_string($value) || strpos($value, home_url()) === false) {
            return $value;
        }

        $pages = get_pages(array('post_status' => $statuses ?: array('publish', 'draft', 'private', 'pending')));
        usort($pages, array($this, 'sort_pages_by_permalink_length_desc'));

        foreach ($pages as $page) {
            $group = $this->get_post_group($page->ID, false);
            if (!$group) {
                continue;
            }

            $translated_id = $this->get_translation_id($group, $target_lang, $statuses);
            if (!$translated_id || (int) $translated_id === (int) $page->ID) {
                continue;
            }

            $value = $this->replace_permalink_in_value($value, $page->ID, $translated_id);
            $value = $this->replace_page_id_links($value, $page->ID, $translated_id);
        }

        $value = $this->normalize_language_url($value, $target_lang);

        return $value;
    }

    private function sort_pages_by_permalink_length_desc($a, $b)
    {
        return strlen(get_permalink($b->ID)) <=> strlen(get_permalink($a->ID));
    }

    private function replace_permalink_in_value($value, $source_page_id, $translated_id)
    {
        $source_url = get_permalink($source_page_id);
        $target_url = get_permalink($translated_id);

        if (!$source_url || !$target_url) {
            return $value;
        }

        $source_path = trim((string) wp_parse_url($source_url, PHP_URL_PATH), '/');
        $is_home_source = (int) $source_page_id === (int) get_option('page_on_front') || $source_path === '';

        if ($is_home_source) {
            return $this->replace_exact_url($value, $source_url, $target_url);
        }

        return str_replace($source_url, $target_url, $value);
    }

    private function replace_exact_url($value, $source_url, $target_url)
    {
        $pattern = '/(?<![A-Za-z0-9])' . preg_quote(untrailingslashit($source_url), '/') . '\/?(?![A-Za-z0-9_\-\/])/i';
        return preg_replace($pattern, untrailingslashit($target_url) . '/', $value);
    }

    private function normalize_language_url($value, $target_lang)
    {
        if (!is_string($value) || $value === '') {
            return $value;
        }

        $base = preg_quote(untrailingslashit(home_url()), '/');
        $lang = preg_quote($target_lang, '/');
        $home_slug = 'home-page';

        $value = preg_replace('#(' . $base . ')/(?:' . $lang . '/' . $home_slug . '/)+(?:' . $lang . '/' . $home_slug . '/)*#i', '$1/' . $target_lang . '/', $value);
        $value = preg_replace('#(' . $base . '/' . $lang . '/)(?:' . $home_slug . '/)+#i', '$1', $value);

        return $value;
    }

    public function load_translated_option_value($pre_option, $option, $default_value)
    {
        if (is_admin() || !is_string($option) || !$this->is_default_acf_option_name($option)) {
            return $pre_option;
        }

        $lang = $this->get_current_language();
        if (!$lang || $lang === $this->get_default_language()) {
            return $pre_option;
        }

        $translated_option = $this->translated_acf_option_name($option, $lang);
        if (!$translated_option || $translated_option === $option) {
            return $pre_option;
        }

        $value = get_option($translated_option, null);
        return $value === null ? $pre_option : $value;
    }

    public function load_translated_acf_option_value($value, $post_id, $field)
    {
        if (is_admin() || !$this->is_acf_option_post_id($post_id) || !is_array($field)) {
            return $value;
        }

        $lang = $this->get_current_language();
        if (!$lang || $lang === $this->get_default_language()) {
            return $value;
        }

        foreach ($this->acf_option_value_names($field, $lang, $post_id) as $option_name) {
            $translated_value = get_option($option_name, null);
            if ($translated_value !== null) {
                return maybe_unserialize($translated_value);
            }
        }

        return $value;
    }

    private function is_acf_option_post_id($post_id)
    {
        if (!is_string($post_id)) {
            return false;
        }

        return !is_numeric($post_id);
    }

    private function acf_option_value_names($field, $lang, $post_id = 'options')
    {
        $names = array();
        $field_name = isset($field['name']) ? (string) $field['name'] : '';
        if ($field_name === '') {
            return $names;
        }

        $base_prefix = $this->acf_option_post_id_prefix($post_id);
        $default_prefix = $base_prefix . '_';
        $target_prefix = $base_prefix . '_' . sanitize_key($lang) . '_';
        $default_lang_prefix = $base_prefix . '_' . $this->get_default_language() . '_';

        if (strpos($field_name, $target_prefix) === 0) {
            $names[] = $field_name;
        } elseif (strpos($field_name, $default_prefix) === 0) {
            $names[] = $target_prefix . substr($field_name, strlen($default_prefix));
        } elseif (strpos($field_name, $default_lang_prefix) === 0) {
            $names[] = $target_prefix . substr($field_name, strlen($default_lang_prefix));
        } else {
            $names[] = $target_prefix . $field_name;
        }

        if (!empty($field['key']) && is_string($field['key'])) {
            $reference_value = get_option('_' . $base_prefix . '_' . $field_name, null);
            if ($reference_value === $field['key']) {
                $names[] = $target_prefix . $field_name;
            }
        }

        return array_values(array_unique(array_filter($names)));
    }

    private function acf_option_post_id_prefix($post_id)
    {
        $post_id = is_string($post_id) && $post_id !== '' ? $post_id : 'options';

        if (in_array($post_id, array('option', 'options'), true)) {
            return 'options';
        }

        return trim($post_id, '_');
    }

    private function translate_option_values($source_lang, $target_lang)
    {
        return $this->copy_option_values($source_lang, $target_lang, true);
    }

    private function copy_option_values($source_lang, $target_lang, $auto_translate = false)
    {
        global $wpdb;

        $count = 0;
        foreach ($this->get_acf_option_prefixes() as $base_prefix) {
            $source_prefix = $source_lang === $this->get_default_language() ? $base_prefix . '_' : $base_prefix . '_' . $source_lang . '_';
            $source_reference_prefix = '_' . $source_prefix;
            $cache_key = 'mpt_option_rows_' . md5($source_prefix . '|' . $source_reference_prefix);
            $rows = wp_cache_get($cache_key, 'multilingual-page-translator');

            if (!is_array($rows)) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- ACF option fields are stored as dynamic option names; results are cached above.
                $rows = $wpdb->get_results($wpdb->prepare(
                    "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s ORDER BY option_name ASC",
                    $wpdb->esc_like($source_prefix) . '%',
                    $wpdb->esc_like($source_reference_prefix) . '%'
                ), ARRAY_A);
                wp_cache_set($cache_key, $rows, 'multilingual-page-translator', MINUTE_IN_SECONDS);
            }

            foreach ($rows as $row) {
                $option_name = (string) $row['option_name'];
                if ($this->is_translated_acf_option_name($option_name)) {
                    continue;
                }

                $target_option_name = $this->translated_acf_option_name_for_prefix($option_name, $target_lang, $source_lang, $base_prefix);
                if (!$target_option_name) {
                    continue;
                }

                $value = maybe_unserialize($row['option_value']);
                $field_type = $this->get_acf_option_field_type($option_name);
                $value = $this->clone_media_value($value, $field_type, $target_lang);
                if (strpos($option_name, '_' . $base_prefix . '_') === 0) {
                    update_option($target_option_name, $value, false);
                    $count++;
                    continue;
                }

                if ($auto_translate && $this->should_translate_acf_value($option_name, $value, $field_type)) {
                    $value = $this->translate_meta_value($value, $source_lang, $target_lang);
                }
                $value = $this->replace_links_in_value($value, $target_lang);

                update_option($target_option_name, $value, false);
                $count++;
            }
        }

        return $count;
    }

    private function get_acf_option_prefixes()
    {
        $prefixes = array('options');

        if (function_exists('acf_get_options_pages')) {
            $pages = acf_get_options_pages();
            if (is_array($pages)) {
                foreach ($pages as $page) {
                    if (!is_array($page)) {
                        continue;
                    }

                    $post_id = isset($page['post_id']) && is_string($page['post_id']) && $page['post_id'] !== '' ? $page['post_id'] : 'options';
                    $prefixes[] = $this->acf_option_post_id_prefix($post_id);
                }
            }
        }

        return array_values(array_unique(array_filter($prefixes)));
    }

    private function is_default_acf_option_name($option_name)
    {
        foreach ($this->get_acf_option_prefixes() as $base_prefix) {
            if (strpos($option_name, $base_prefix . '_') === 0 || strpos($option_name, '_' . $base_prefix . '_') === 0) {
                return !$this->is_translated_acf_option_name($option_name);
            }
        }

        return false;
    }

    private function is_translated_acf_option_name($option_name)
    {
        foreach ($this->get_acf_option_prefixes() as $base_prefix) {
            $prefixes = array('_' . $base_prefix . '_', $base_prefix . '_');
            foreach ($prefixes as $prefix) {
                if (strpos($option_name, $prefix) !== 0) {
                    continue;
                }

                $rest = substr($option_name, strlen($prefix));
                $code = strtok($rest, '_');
                if ($code && $this->language_exists($code)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function translated_acf_option_name($option_name, $target_lang)
    {
        foreach ($this->get_acf_option_prefixes() as $base_prefix) {
            $translated = $this->translated_acf_option_name_for_prefix($option_name, $target_lang, $this->get_default_language(), $base_prefix);
            if ($translated) {
                return $translated;
            }
        }

        return '';
    }

    private function translated_acf_option_name_for_prefix($option_name, $target_lang, $source_lang, $base_prefix)
    {
        $is_reference = strpos($option_name, '_') === 0;
        $plain_name = $is_reference ? substr($option_name, 1) : $option_name;
        $source_prefix = $source_lang === $this->get_default_language() ? $base_prefix . '_' : $base_prefix . '_' . $source_lang . '_';

        if (strpos($plain_name, $source_prefix) !== 0) {
            return '';
        }

        $target_prefix = $base_prefix . '_' . $target_lang . '_';
        $target_name = $target_prefix . substr($plain_name, strlen($source_prefix));

        return $is_reference ? '_' . $target_name : $target_name;
    }

    private function replace_page_id_links($value, $source_page_id, $translated_id)
    {
        $target_url = get_permalink($translated_id);
        $source_page_id = (int) $source_page_id;

        $patterns = array(
            preg_quote(home_url('/?page_id=' . $source_page_id), '/'),
            preg_quote(site_url('/?page_id=' . $source_page_id), '/'),
        );

        foreach ($patterns as $pattern) {
            $value = preg_replace('/' . $pattern . '(?:\?page_id=\d+)*(?:[A-Za-z0-9_\-\/]*)?/i', $target_url, $value);
        }

        return $value;
    }

    public function register_language_rewrite_rules()
    {
        $codes = array_filter(array_map(function ($language) {
            return preg_quote($language['code'], '/');
        }, $this->get_languages()));

        if (!$codes) {
            return;
        }

        $language_pattern = '(' . implode('|', $codes) . ')';
        add_rewrite_rule('^' . $language_pattern . '/?$', 'index.php?mpt_lang=$matches[1]&mpt_home=1', 'top');
        add_rewrite_rule('^' . $language_pattern . '/(.+?)/?$', 'index.php?mpt_lang=$matches[1]&mpt_path=$matches[2]', 'top');
    }

    public function maybe_flush_rewrite_rules()
    {
        if (get_option(self::OPTION_VERSION) !== '1.0.72') {
            update_option(self::OPTION_VERSION, '1.0.72');
            flush_rewrite_rules();
        }
    }

    public function register_query_vars($vars)
    {
        $vars[] = 'mpt_lang';
        $vars[] = 'mpt_home';
        $vars[] = 'mpt_path';
        return $vars;
    }

    public function parse_language_request($wp)
    {
        if (empty($wp->query_vars['mpt_lang'])) {
            return;
        }

        $lang = sanitize_key($wp->query_vars['mpt_lang']);
        if (!$this->language_exists($lang)) {
            return;
        }

        $post_id = 0;
        if (!empty($wp->query_vars['mpt_home'])) {
            $post_id = $this->get_language_home_page_id($lang, array('publish'));
        } elseif (!empty($wp->query_vars['mpt_path'])) {
            $post_id = $this->find_page_by_language_path($lang, sanitize_title($wp->query_vars['mpt_path']));
        }

        if (!$post_id) {
            return;
        }

        $wp->query_vars = array(
            'page_id' => $post_id,
            'post_type' => 'page',
        );
    }

    public function filter_page_link($link, $post_id)
    {
        $post = get_post($post_id);
        if (!$post || $post->post_type !== 'page') {
            return $link;
        }

        $lang = $this->get_post_language($post_id);
        if (!$this->language_exists($lang)) {
            return $link;
        }

        if ($lang === $this->get_default_language()) {
            if ((int) $post_id === (int) $this->get_language_home_page_id($lang, array('publish'))) {
                return home_url('/');
            }

            return $link;
        }

        if ((int) $post_id === (int) $this->get_language_home_page_id($lang, array('publish'))) {
            return home_url(user_trailingslashit($lang));
        }

        return home_url(user_trailingslashit($lang . '/' . $this->get_language_page_slug($post)));
    }

    private function get_language_permalink($post_id, $lang = '')
    {
        $post = get_post($post_id);
        if (!$post || $post->post_type !== 'page') {
            return get_permalink($post_id);
        }

        $lang = $lang ?: $this->get_post_language($post_id);
        if (!$this->language_exists($lang)) {
            return get_permalink($post_id);
        }

        if ($lang === $this->get_default_language()) {
            if ((int) $post_id === (int) $this->get_language_home_page_id($lang, array('publish'))) {
                return home_url('/');
            }

            return home_url(user_trailingslashit($this->get_language_page_slug($post)));
        }

        if ((int) $post_id === (int) $this->get_language_home_page_id($lang, array('publish'))) {
            return home_url(user_trailingslashit($lang));
        }

        return home_url(user_trailingslashit($lang . '/' . $this->get_language_page_slug($post)));
    }

    public function disable_language_canonical_redirect($redirect_url, $requested_url)
    {
        $path = trim((string) wp_parse_url($requested_url, PHP_URL_PATH), '/');
        if ($path === '') {
            return $redirect_url;
        }

        $first_segment = strtok($path, '/');
        return $this->language_exists($first_segment) ? false : $redirect_url;
    }

    public function filter_custom_logo_link($html)
    {
        if (is_admin() || !is_string($html) || $html === '') {
            return $html;
        }

        $target_lang = $this->get_current_language();
        if (!$target_lang || $target_lang === $this->get_default_language()) {
            return $html;
        }

        $root_url = trailingslashit((string) get_option('home'));
        $language_url = trailingslashit(untrailingslashit($root_url) . '/' . trim(user_trailingslashit($target_lang), '/'));

        return preg_replace(
            '/href=(["\'])' . preg_quote($root_url, '/') . '\1/i',
            'href=$1' . esc_url($language_url) . '$1',
            $html
        );
    }

    public function translate_menu_objects($items, $args)
    {
        if (is_admin() || !is_array($items)) {
            return $items;
        }

        $target_lang = $this->get_current_language();
        $source_lang = $this->get_default_language();
        if (!$target_lang || $target_lang === $source_lang) {
            return $items;
        }

        $menu_id = $this->get_menu_id_from_args($args);
        if ($menu_id && get_term_meta($menu_id, self::MENU_META_LANG, true) === $target_lang) {
            return $items;
        }

        foreach ($items as $item) {
            if (!is_object($item)) {
                continue;
            }

            if (isset($item->object, $item->object_id) && $item->object === 'page') {
                $group = $this->get_post_group((int) $item->object_id, false);
                $translated_id = $group ? $this->get_translation_id($group, $target_lang, array('publish')) : 0;
                $translated_post = $translated_id ? get_post($translated_id) : null;

                if ($translated_post) {
                    $item->object_id = (string) $translated_id;
                    $item->url = get_permalink($translated_id);
                    $item->title = get_the_title($translated_id);
                    continue;
                }
            }

            if (!empty($item->url)) {
                $item->url = $this->translate_menu_url((string) $item->url, $target_lang);
            }

            if (!empty($item->title)) {
                $item->title = $this->translate_persistent_string((string) $item->title, $source_lang, $target_lang, 'menu_title');
            }

            if (!empty($item->attr_title)) {
                $item->attr_title = $this->translate_persistent_string((string) $item->attr_title, $source_lang, $target_lang, 'menu_attr');
            }
        }

        return $items;
    }

    private function translate_menu_url($url, $target_lang)
    {
        if ($url === '' || $url === '#') {
            return $url;
        }

        if (strpos($url, '#') === 0) {
            $home_id = $this->get_language_home_page_id($target_lang, array('publish'));
            return ($home_id ? get_permalink($home_id) : home_url(user_trailingslashit($target_lang))) . $url;
        }

        return $this->replace_links_in_value($url, $target_lang, array('publish'));
    }

    public function switch_language_menu_args($args)
    {
        if (is_admin() || !is_array($args)) {
            return $args;
        }

        $target_lang = $this->get_current_language();
        if (!$target_lang || $target_lang === $this->get_default_language()) {
            return $args;
        }

        $settings = $this->get_settings();
        $location = isset($args['theme_location']) ? (string) $args['theme_location'] : '';
        if ($settings['menu_location'] && $location !== $settings['menu_location']) {
            return $args;
        }

        $source_menu_id = $this->get_menu_id_from_args($args);
        if (!$source_menu_id) {
            return $args;
        }

        $translated_menu_id = $this->get_translated_menu_id($source_menu_id, $target_lang, $location);
        if ($translated_menu_id) {
            $args['menu'] = $translated_menu_id;
        }

        return $args;
    }

    private function get_menu_id_from_args($args)
    {
        $menu_arg = is_array($args) && isset($args['menu']) ? $args['menu'] : (is_object($args) && isset($args->menu) ? $args->menu : 0);
        if ($menu_arg) {
            $menu = wp_get_nav_menu_object($menu_arg);
            if ($menu) {
                return (int) $menu->term_id;
            }
        }

        $location = is_array($args) && isset($args['theme_location']) ? (string) $args['theme_location'] : (is_object($args) && isset($args->theme_location) ? (string) $args->theme_location : '');
        if (!$location) {
            return 0;
        }

        $locations = get_nav_menu_locations();
        return isset($locations[$location]) ? (int) $locations[$location] : 0;
    }

    private function get_translated_menu_id($source_menu_id, $target_lang, $location = '')
    {
        $meta_query = array(
            'relation' => 'AND',
            array(
                'key' => self::MENU_META_SOURCE,
                'value' => (int) $source_menu_id,
            ),
            array(
                'key' => self::MENU_META_LANG,
                'value' => $target_lang,
            ),
        );

        if ($location !== '') {
            $meta_query[] = array(
                'key' => self::MENU_META_LOCATION,
                'value' => $location,
            );
        }

        $menus = get_terms(array(
            'taxonomy' => 'nav_menu',
            'hide_empty' => false,
            'number' => 1,
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Required to find the editable menu copy for the current language.
            'meta_query' => $meta_query,
        ));

        return !empty($menus) && !is_wp_error($menus) ? (int) $menus[0]->term_id : 0;
    }

    public function translate_attachment_title($title, $post_id)
    {
        if (is_admin() || !$post_id || get_post_type($post_id) !== 'attachment') {
            return $title;
        }

        $target_lang = $this->get_current_language();
        $source_lang = $this->get_default_language();
        if (!$target_lang || $target_lang === $source_lang) {
            return $title;
        }

        return $this->translate_persistent_string((string) $title, $source_lang, $target_lang, 'attachment_title_' . (int) $post_id);
    }

    public function translate_acf_formatted_value($value, $post_id, $field)
    {
        if (is_admin() || !is_array($value) || !is_array($field)) {
            return $value;
        }

        $field_type = isset($field['type']) ? (string) $field['type'] : '';
        if (!in_array($field_type, array('file', 'image', 'gallery', 'link'), true)) {
            return $value;
        }

        $target_lang = $this->get_current_language();
        $source_lang = $this->get_default_language();
        if (!$target_lang || $target_lang === $source_lang) {
            return $value;
        }

        return $this->translate_acf_display_strings($value, $source_lang, $target_lang, 'acf_' . $field_type . '_' . ($field['key'] ?? $field['name'] ?? 'field'));
    }

    private function translate_acf_display_strings($value, $source_lang, $target_lang, $context)
    {
        if (!is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->translate_acf_display_strings($item, $source_lang, $target_lang, $context . '_' . $key);
                continue;
            }

            if (!is_string($item) || !$this->should_translate_string($item)) {
                continue;
            }

            if (!in_array((string) $key, array('title', 'caption', 'description', 'alt'), true)) {
                continue;
            }

            $value[$key] = $this->translate_persistent_string($item, $source_lang, $target_lang, $context . '_' . $key);
        }

        return $value;
    }

    private function get_language_home_page_id($lang, $statuses = null)
    {
        $front_id = (int) get_option('page_on_front');
        if (!$front_id) {
            return 0;
        }

        $front_group = $this->get_post_group($front_id, false);
        if ($front_group) {
            $translated_id = $this->get_translation_id($front_group, $lang, $statuses);
            if ($translated_id) {
                return $translated_id;
            }
        }

        if ($statuses && !in_array(get_post_status($front_id), $statuses, true)) {
            return 0;
        }

        return $this->get_post_language($front_id) === $lang ? $front_id : 0;
    }

    private function find_page_by_language_path($lang, $path)
    {
        $path = trim((string) $path, '/');
        if ($path === '') {
            return 0;
        }

        $query = new WP_Query(array(
            'post_type' => 'page',
            'post_status' => array('publish'),
            'posts_per_page' => -1,
            'fields' => 'ids',
            'no_found_rows' => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Required to resolve a public language path to its translated page.
            'meta_key' => self::META_LANG,
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Required to resolve a public language path to its translated page.
            'meta_value' => $lang,
        ));

        foreach ($query->posts as $post_id) {
            $post = get_post($post_id);
            if (!$post) {
                continue;
            }

            if ($post->post_name === $path || $this->get_language_page_slug($post) === $path || $this->get_public_page_slug($post) === $path) {
                return (int) $post_id;
            }
        }

        $source_post_id = $this->find_source_page_by_public_slug($path);
        if ($source_post_id) {
            $source_group = $this->get_post_group($source_post_id, false);
            if ($source_group) {
                return $this->get_translation_id($source_group, $lang, array('publish'));
            }
        }

        return 0;
    }

    private function find_source_page_by_public_slug($path)
    {
        $default_lang = $this->get_default_language();
        $query = new WP_Query(array(
            'post_type' => 'page',
            'post_status' => array('publish'),
            'posts_per_page' => -1,
            'fields' => 'ids',
            'no_found_rows' => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Required to resolve default-language source pages by language.
            'meta_key' => self::META_LANG,
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Required to resolve default-language source pages by language.
            'meta_value' => $default_lang,
        ));

        foreach ($query->posts as $post_id) {
            $post = get_post($post_id);
            if ($post && ($post->post_name === $path || $this->get_public_page_slug($post) === $path || $this->get_language_page_slug($post) === $path)) {
                return (int) $post_id;
            }
        }

        return 0;
    }

    private function get_language_page_slug($post)
    {
        $manual_slug = get_post_meta(is_object($post) ? $post->ID : 0, '_mpt_public_slug', true);
        if ($manual_slug) {
            return sanitize_title($manual_slug);
        }

        $title_slug = is_object($post) ? sanitize_title($post->post_title) : '';
        if ($title_slug && $title_slug !== 'home-page') {
            return $title_slug;
        }

        return $this->get_public_page_slug($post);
    }

    private function get_public_page_slug($post)
    {
        $slug = is_object($post) ? $post->post_name : (string) $post;
        $codes = array_map(function ($language) {
            return preg_quote($language['code'], '/');
        }, $this->get_languages());

        if ($codes) {
            $pattern = '/-(?:' . implode('|', $codes) . ')(?:-(?:' . implode('|', $codes) . '))*$/';
            $slug = preg_replace($pattern, '', $slug);
        }

        return $slug ?: (is_object($post) ? sanitize_title($post->post_title) : '');
    }

    public function append_switcher_to_menu($items, $args)
    {
        $settings = $this->get_settings();
        if ($settings['menu_auto'] !== '1') {
            return $items;
        }

        if ($settings['menu_location'] && isset($args->theme_location) && $args->theme_location !== $settings['menu_location']) {
            return $items;
        }

        $switcher = $this->render_language_switcher();
        if ($switcher === '') {
            return $items;
        }

        return $items . '<li class="menu-item mpt-menu-item">' . $switcher . '</li>';
    }

    public function render_language_switcher_shortcode()
    {
        return $this->render_language_switcher();
    }

    private function render_language_switcher()
    {
        if (!is_singular('page')) {
            return '';
        }

        $current_id = get_queried_object_id();
        $current_lang = $this->get_current_language();
        $group = $this->get_post_group($current_id, false);
        if (!$group) {
            return '';
        }

        $translations = $this->get_group_posts($group, $this->get_frontend_translation_statuses());
        $languages = $this->get_display_languages();
        $current_language = $this->find_language($current_lang);
        $options = array();

        foreach ($languages as $language) {
            if ($language['code'] === $current_lang || empty($translations[$language['code']])) {
                continue;
            }

            $options[] = array(
                'language' => $language,
                'post_id' => (int) $translations[$language['code']],
            );
        }

        if (!$options) {
            return '';
        }

        ob_start();
        ?>
        <div class="mpt-language-switcher" data-mpt-switcher>
            <button class="mpt-language-current" type="button" aria-expanded="false">
                <span class="mpt-flag"><?php echo esc_html($current_language['flag']); ?></span>
                <span class="mpt-caret" aria-hidden="true"></span>
                <span class="screen-reader-text"><?php echo esc_html($current_language['name']); ?></span>
            </button>
            <ul class="mpt-language-options">
                <?php foreach ($options as $option) : ?>
                    <?php $language = $option['language']; ?>
                    <li>
                        <a href="<?php echo esc_url($this->get_switcher_language_url($current_id, $option['post_id'], $language['code'])); ?>" hreflang="<?php echo esc_attr($language['code']); ?>" data-mpt-language="<?php echo esc_attr($language['code']); ?>">
                            <span class="mpt-flag"><?php echo esc_html($language['flag']); ?></span>
                            <span class="mpt-name"><?php echo esc_html($language['name']); ?></span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php
        return trim(ob_get_clean());
    }

    private function get_switcher_language_url($current_id, $target_post_id, $target_lang)
    {
        $target_lang = sanitize_key($target_lang);
        $current_lang = $this->get_current_language();

        if ($target_lang === $this->get_default_language() && $this->is_current_language_home_request()) {
            return home_url('/');
        }

        if ($target_lang === $this->get_default_language() && $this->is_language_home_page($current_id, $current_lang)) {
            return home_url('/');
        }

        $target_post_id = (int) $target_post_id;
        if (!$target_post_id || $this->get_post_language($target_post_id) !== $target_lang) {
            $group = $this->get_post_group($current_id, false);
            $resolved_id = $group ? $this->get_translation_id($group, $target_lang, $this->get_frontend_translation_statuses()) : 0;
            if ($resolved_id) {
                $target_post_id = $resolved_id;
            }
        }

        if ($target_lang === $this->get_default_language() && $this->is_language_home_page($target_post_id, $target_lang)) {
            return home_url('/');
        }

        return $this->get_language_permalink($target_post_id, $target_lang);
    }

    private function is_current_language_home_request()
    {
        $request_uri = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '';
        $path = trim((string) wp_parse_url($request_uri, PHP_URL_PATH), '/');

        return $path !== '' && $this->language_exists($path);
    }

    private function is_language_home_page($post_id, $lang)
    {
        $post_id = (int) $post_id;
        $lang = sanitize_key($lang);

        return $post_id > 0 && $lang !== '' && $post_id === (int) $this->get_language_home_page_id($lang, $this->get_frontend_translation_statuses());
    }

    public function print_hreflang_links()
    {
        if (!is_singular('page')) {
            return;
        }

        $group = $this->get_post_group(get_queried_object_id(), false);
        if (!$group) {
            return;
        }

        $translations = $this->get_group_posts($group, array('publish'));
        foreach ($translations as $lang => $post_id) {
            printf('<link rel="alternate" hreflang="%s" href="%s">' . "\n", esc_attr($lang), esc_url($this->get_language_permalink($post_id, $lang)));
        }
    }

    private function get_languages()
    {
        $languages = get_option(self::OPTION_LANGUAGES, array());
        if (!is_array($languages)) {
            return array();
        }

        return array_values(array_map(array($this, 'normalize_language'), $languages));
    }

    private function get_display_languages()
    {
        return array_values(array_filter($this->get_languages(), function ($language) {
            return isset($language['display']) && $language['display'] === '1';
        }));
    }

    private function get_frontend_translation_statuses()
    {
        return array('publish');
    }

    private function normalize_language($language)
    {
        $language = is_array($language) ? $language : array();
        $code = isset($language['code']) ? sanitize_key($language['code']) : '';
        $display = isset($language['display']) ? (string) $language['display'] : ($code === 'ka' ? '0' : '1');
        $flag = isset($language['flag']) ? sanitize_text_field($language['flag']) : '';
        $locale = isset($language['locale']) ? sanitize_text_field($language['locale']) : '';

        if ($code === 'en' && ($flag === '' || $flag === '🇺🇸')) {
            $flag = '🇬🇧';
        }

        if ($code === 'en' && ($locale === '' || $locale === 'en_US')) {
            $locale = 'en_GB';
        }

        return array(
            'code' => $code,
            'name' => isset($language['name']) ? sanitize_text_field($language['name']) : '',
            'flag' => $flag,
            'locale' => $locale,
            'display' => $display === '0' ? '0' : '1',
        );
    }

    private function find_language($code)
    {
        foreach ($this->get_languages() as $language) {
            if ($language['code'] === $code) {
                return $language;
            }
        }

        return array('code' => $code, 'name' => strtoupper($code), 'flag' => '', 'locale' => '');
    }

    private function language_exists($code)
    {
        foreach ($this->get_languages() as $language) {
            if ($language['code'] === $code) {
                return true;
            }
        }

        return false;
    }

    private function get_settings()
    {
        $settings = wp_parse_args(get_option(self::OPTION_SETTINGS, array()), array(
            'default_language' => 'pt',
            'menu_auto' => '0',
            'menu_location' => '',
            'translation_provider' => 'mymemory',
            'translation_endpoint' => '',
            'translation_api_key' => '',
            'translated_post_status' => 'pending',
        ));

        $endpoint = (string) $settings['translation_endpoint'];
        if (
            $settings['translation_provider'] !== 'libretranslate'
            || $endpoint === ''
            || $endpoint === 'https://libretranslate.example.com/translate'
            || strpos($endpoint, '127.0.0.1') !== false
            || strpos($endpoint, 'localhost') !== false
        ) {
            $settings['translation_provider'] = 'mymemory';
            $settings['translation_endpoint'] = '';
            $settings['translation_api_key'] = '';
        }

        $settings['translation_endpoint'] = $this->sanitize_translation_endpoint($settings['translation_endpoint']);

        if ($settings['default_language'] === 'en' && $this->language_exists('pt')) {
            $settings['default_language'] = 'pt';
        }

        return $settings;
    }

    private function sanitize_translation_endpoint($endpoint)
    {
        $endpoint = esc_url_raw((string) $endpoint);
        if ($endpoint === '') {
            return '';
        }

        $scheme = wp_parse_url($endpoint, PHP_URL_SCHEME);
        if (!in_array($scheme, array('http', 'https'), true)) {
            return '';
        }

        return $endpoint;
    }

    private function get_default_language()
    {
        $settings = $this->get_settings();
        return $settings['default_language'];
    }

    private function get_default_target_language($source_lang)
    {
        foreach ($this->get_languages() as $language) {
            if ($language['code'] !== $source_lang) {
                return $language['code'];
            }
        }

        return '';
    }

    private function get_current_language()
    {
        $query_lang = get_query_var('mpt_lang');
        if ($query_lang && $this->language_exists($query_lang)) {
            return $query_lang;
        }

        $path_lang = $this->get_language_from_request_path();
        if ($path_lang && $path_lang !== $this->get_default_language()) {
            return $path_lang;
        }

        $post_id = get_queried_object_id();
        if ($post_id) {
            return $this->get_post_language($post_id);
        }

        return $path_lang ?: $this->get_default_language();
    }

    private function get_language_from_request_path()
    {
        $request_uri = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '';
        $path = trim((string) wp_parse_url($request_uri, PHP_URL_PATH), '/');

        if ($path === '') {
            return $this->get_default_language();
        }

        $first_segment = strtok($path, '/');
        if ($first_segment && $this->language_exists($first_segment)) {
            return $first_segment;
        }

        return $this->get_default_language();
    }

    private function get_post_language($post_id)
    {
        $lang = get_post_meta($post_id, self::META_LANG, true);
        return $lang ?: $this->get_default_language();
    }

    private function get_post_language_label($post_id, $include_code = false)
    {
        $code = $this->get_post_language($post_id);
        $language = $this->find_language($code);
        $label = trim($language['flag'] . ' ' . $language['name']);

        if ($include_code) {
            $label .= ' (' . strtoupper($code) . ')';
        }

        return $label;
    }

    private function get_post_group($post_id, $create = true)
    {
        $group = get_post_meta($post_id, self::META_GROUP, true);
        if (!$group && $create) {
            $group = $this->new_group_id();
            update_post_meta($post_id, self::META_GROUP, $group);
        }

        return $group;
    }

    private function new_group_id()
    {
        if (function_exists('wp_generate_uuid4')) {
            return wp_generate_uuid4();
        }

        return uniqid('mpt_', true);
    }

    private function get_group_posts($group, $statuses = null)
    {
        if (!$group) {
            return array();
        }

        $query = new WP_Query(array(
            'post_type' => 'page',
            'post_status' => $statuses ?: array('publish', 'draft', 'private', 'pending'),
            'posts_per_page' => -1,
            'fields' => 'ids',
            'no_found_rows' => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Required to find all pages connected to the same translation group.
            'meta_query' => array(
                array(
                    'key' => self::META_GROUP,
                    'value' => $group,
                ),
            ),
        ));

        $posts = array();
        foreach ($query->posts as $post_id) {
            $posts[$this->get_post_language($post_id)] = (int) $post_id;
        }

        return $posts;
    }

    private function get_translation_id($group, $target_lang, $statuses = null)
    {
        $posts = $this->get_group_posts($group, $statuses);
        return isset($posts[$target_lang]) ? (int) $posts[$target_lang] : 0;
    }

    private function review_status_label($status)
    {
        $labels = array(
            'needs_editor_review' => __('Needs editor review', 'multilingual-page-translator'),
            'manual_translation_needed' => __('Manual translation needed', 'multilingual-page-translator'),
        );

        return isset($labels[$status]) ? $labels[$status] : $status;
    }

    private function require_admin_nonce()
    {
        if (!current_user_can('manage_options') || !$this->verify_request_nonce($_POST)) {
            wp_die(esc_html__('Permission denied.', 'multilingual-page-translator'));
        }
    }

    private function require_edit_nonce()
    {
        if (!$this->verify_request_nonce($_GET)) {
            wp_die(esc_html__('Permission denied.', 'multilingual-page-translator'));
        }
    }

    private function verify_request_nonce($request)
    {
        $nonce = '';

        if (isset($request[self::NONCE])) {
            $nonce = sanitize_text_field(wp_unslash($request[self::NONCE]));
        } elseif (isset($request['_wpnonce'])) {
            $nonce = sanitize_text_field(wp_unslash($request['_wpnonce']));
        }

        return $nonce && wp_verify_nonce($nonce, self::NONCE);
    }
}

register_activation_hook(__FILE__, array('MPT_Multilingual_Page_Translator', 'activate'));
MPT_Multilingual_Page_Translator::instance();
