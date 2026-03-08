<?php
/**
 * Plugin Name: Chatbot Mon Assistant IA
 * Description: Assistant flottant pour répondre aux visiteurs à partir des contenus du site (crawl + index + chat).
 * Version: 2.4.1
 * Author: Azertaf
 */

if (!defined('ABSPATH')) {
    exit;
}

final class AZSA_Plugin {
    const VERSION = '2.4.1';
    const OPTION_LEADS = 'azsa_leads';
    const OPTION_SETTINGS = 'azsa_settings';
    const OPTION_INDEX = 'azsa_index';
    const CRON_HOOK = 'azsa_rebuild_index_cron';
    const DEFAULT_ROBOT_LOGO_URL = 'https://monassistant.mapage-wp.online/wp-content/uploads/2026/03/MAP-logo-tete.gif';
    const DEFAULT_GIF_BASE_URL = 'https://monassistant.mapage-wp.online/wp-content/uploads/2026/03/';
    const DEFAULT_GITHUB_REPO = 'juliansebastien-rgb/MonAssistant';
    const DEFAULT_GITHUB_TOKEN = '';

    public static function init() {
        add_action('init', array(__CLASS__, 'register_assets'));
        add_action('wp_enqueue_scripts', array(__CLASS__, 'enqueue_front'));
        add_action('admin_menu', array(__CLASS__, 'admin_menu'));
        add_action('admin_init', array(__CLASS__, 'register_settings'));
        add_action('rest_api_init', array(__CLASS__, 'register_rest'));
        add_action(self::CRON_HOOK, array(__CLASS__, 'rebuild_index'));
        add_filter('pre_set_site_transient_update_plugins', array(__CLASS__, 'check_for_updates'));
        add_filter('plugins_api', array(__CLASS__, 'plugin_info_popup'), 10, 3);
        add_filter('auto_update_plugin', array(__CLASS__, 'auto_update_plugin'), 10, 2);
        add_action('upgrader_process_complete', array(__CLASS__, 'clear_update_cache'), 10, 2);

        register_activation_hook(__FILE__, array(__CLASS__, 'activate'));
        register_deactivation_hook(__FILE__, array(__CLASS__, 'deactivate'));
    }

    public static function defaults() {
        return array(
            'assistant_name' => 'Chatbot Mon Assistant IA',
            'logo_url' => self::DEFAULT_ROBOT_LOGO_URL,
            'character_gif_url' => '',
            'character_gif_base_url' => self::DEFAULT_GIF_BASE_URL,
            'max_pages' => 80,
            'max_depth' => 2,
            'api_key' => '',
            'model' => 'claude-sonnet-4-20250514',
            'lang' => 'fr',
            'elevenlabs_api_key' => '',
            'elevenlabs_voice_male' => 'HQFJsVV9DOZgHpgWP5ku',
            'elevenlabs_speed' => '1.00',
            'github_repo' => self::DEFAULT_GITHUB_REPO,
            'github_token' => self::DEFAULT_GITHUB_TOKEN,
        );
    }

    public static function get_settings() {
        $settings = get_option(self::OPTION_SETTINGS, array());
        $settings = wp_parse_args(is_array($settings) ? $settings : array(), self::defaults());
        // Zero-config updates: always use the official repo embedded in plugin code.
        $settings['github_repo'] = self::DEFAULT_GITHUB_REPO;
        return $settings;
    }

    public static function activate() {
        $settings = self::get_settings();
        update_option(self::OPTION_SETTINGS, $settings, false);
        $has_recurring = wp_next_scheduled(self::CRON_HOOK);
        if (!$has_recurring) {
            wp_schedule_event(time() + 300, 'hourly', self::CRON_HOOK);
        }
        // Avoid timeout on activation: rebuild index asynchronously.
        update_option('azsa_needs_reindex', 1, false);
        wp_schedule_single_event(time() + 20, self::CRON_HOOK);
    }

    public static function deactivate() {
        $ts = wp_next_scheduled(self::CRON_HOOK);
        if ($ts) {
            wp_unschedule_event($ts, self::CRON_HOOK);
        }
    }

    public static function register_assets() {
        wp_register_style(
            'azsa-front',
            plugins_url('assets/azsa.css', __FILE__),
            array(),
            self::VERSION
        );

        wp_register_script(
            'azsa-front',
            plugins_url('assets/azsa.js', __FILE__),
            array(),
            self::VERSION,
            true
        );
    }

    public static function enqueue_front() {
        if (is_admin()) {
            return;
        }

        $settings = self::get_settings();
        $index = get_option(self::OPTION_INDEX, array());
        $has_index = !empty($index['docs']) && is_array($index['docs']);

        wp_enqueue_style('azsa-front');
        wp_enqueue_script('azsa-front');

        wp_localize_script('azsa-front', 'AZSA_CONFIG', array(
            'assistantName' => (string) $settings['assistant_name'],
            'logoUrl' => (string) $settings['logo_url'],
            'characterGifUrl' => (string) $settings['character_gif_url'],
            'gifBaseUrl' => (string) $settings['character_gif_base_url'],
            'restUrl' => esc_url_raw(rest_url('azsa/v1/chat')),
            'leadUrl' => esc_url_raw(rest_url('azsa/v1/lead')),
            'ttsUrl' => esc_url_raw(rest_url('azsa/v1/tts')),
            'nonce' => wp_create_nonce('wp_rest'),
            'hasIndex' => $has_index,
            'lang' => (string) $settings['lang'],
            'welcome' => $has_index
                ? 'Bonjour, je suis votre assistant MonAssistant IA. Posez-moi vos questions sur le site.'
                : 'Bonjour, l\'index du site est en cours de préparation. Revenez dans quelques minutes.',
        ));
    }

    public static function admin_menu() {
        add_options_page(
            'Chatbot Mon Assistant IA',
            'Chatbot Mon Assistant IA',
            'manage_options',
            'azertaf-assistant',
            array(__CLASS__, 'render_admin_page')
        );
    }

    public static function register_settings() {
        register_setting('azsa_settings_group', self::OPTION_SETTINGS, array(__CLASS__, 'sanitize_settings'));
    }

    public static function sanitize_settings($input) {
        $defaults = self::defaults();
        $out = array();

        $out['assistant_name'] = sanitize_text_field($input['assistant_name'] ?? $defaults['assistant_name']);
        $out['logo_url'] = esc_url_raw($input['logo_url'] ?? '');
        if ($out['logo_url'] === '') {
            $out['logo_url'] = self::DEFAULT_ROBOT_LOGO_URL;
        }
        $out['character_gif_url'] = esc_url_raw($input['character_gif_url'] ?? '');
        $out['character_gif_base_url'] = esc_url_raw($input['character_gif_base_url'] ?? self::DEFAULT_GIF_BASE_URL);
        $out['max_pages'] = max(10, min(300, absint($input['max_pages'] ?? $defaults['max_pages'])));
        $out['max_depth'] = max(1, min(4, absint($input['max_depth'] ?? $defaults['max_depth'])));
        $out['api_key'] = sanitize_text_field($input['api_key'] ?? '');
        $out['model'] = sanitize_text_field($input['model'] ?? $defaults['model']);
        $lang = sanitize_key($input['lang'] ?? $defaults['lang']);
        $out['lang'] = in_array($lang, array('fr', 'en', 'es', 'de', 'it', 'pt'), true) ? $lang : 'fr';
        $out['elevenlabs_api_key'] = sanitize_text_field($input['elevenlabs_api_key'] ?? '');
        $out['elevenlabs_voice_male'] = sanitize_text_field($input['elevenlabs_voice_male'] ?? '');
        $speed = (float) ($input['elevenlabs_speed'] ?? $defaults['elevenlabs_speed']);
        if ($speed < 0.7) {
            $speed = 0.7;
        } elseif ($speed > 1.2) {
            $speed = 1.2;
        }
        $out['elevenlabs_speed'] = (string) number_format($speed, 2, '.', '');
        $repo = trim((string) ($input['github_repo'] ?? ''));
        $repo = preg_replace('#^https?://github\\.com/#i', '', $repo);
        $repo = trim((string) $repo, '/');
        $out['github_repo'] = self::DEFAULT_GITHUB_REPO;
        $token_in = sanitize_text_field($input['github_token'] ?? '');
        $out['github_token'] = $token_in !== '' ? $token_in : self::DEFAULT_GITHUB_TOKEN;

        return $out;
    }

    public static function render_admin_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        if (isset($_POST['azsa_reindex']) && check_admin_referer('azsa_reindex_now', 'azsa_reindex_nonce')) {
            self::rebuild_index();
            echo '<div class="notice notice-success"><p>Index reconstruit.</p></div>';
        }

        $settings = self::get_settings();
        $index = get_option(self::OPTION_INDEX, array());
        $count = isset($index['docs']) && is_array($index['docs']) ? count($index['docs']) : 0;
        $generated = !empty($index['generated_at']) ? esc_html($index['generated_at']) : 'jamais';
        ?>
        <div class="wrap">
            <h1>Chatbot Mon Assistant IA</h1>
            <p>Le plugin explore le site, indexe les pages et répond aux visiteurs dans un widget contextuel.</p>
            <p><strong>Index:</strong> <?php echo (int) $count; ?> pages | <strong>Dernière génération:</strong> <?php echo $generated; ?></p>

            <form method="post" style="margin: 18px 0 28px;">
                <?php wp_nonce_field('azsa_reindex_now', 'azsa_reindex_nonce'); ?>
                <button type="submit" name="azsa_reindex" class="button button-primary">Reconstruire l'index maintenant</button>
            </form>

            <form method="post" action="options.php">
                <?php settings_fields('azsa_settings_group'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="azsa_assistant_name">Nom assistant</label></th>
                        <td><input id="azsa_assistant_name" name="<?php echo self::OPTION_SETTINGS; ?>[assistant_name]" type="text" class="regular-text" value="<?php echo esc_attr($settings['assistant_name']); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="azsa_logo_url">URL logo (bouton rond)</label></th>
                        <td>
                            <input id="azsa_logo_url" name="<?php echo self::OPTION_SETTINGS; ?>[logo_url]" type="url" class="regular-text" value="<?php echo esc_attr($settings['logo_url']); ?>" />
                            <p class="description">Par défaut: logo animé tête de robot MonAssistant IA.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="azsa_character_gif_url">URL GIF personnage animé</label></th>
                        <td><input id="azsa_character_gif_url" name="<?php echo self::OPTION_SETTINGS; ?>[character_gif_url]" type="url" class="regular-text" value="<?php echo esc_attr($settings['character_gif_url']); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="azsa_character_gif_base_url">Base URL GIF émotions</label></th>
                        <td>
                            <input id="azsa_character_gif_base_url" name="<?php echo self::OPTION_SETTINGS; ?>[character_gif_base_url]" type="url" class="regular-text" value="<?php echo esc_attr($settings['character_gif_base_url']); ?>" />
                            <p class="description">Ex: dossier contenant `15-est-tranquile.gif`, `19-parler.gif`, `7-reflechit.gif`, etc.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="azsa_lang">Langue par défaut</label></th>
                        <td>
                            <select id="azsa_lang" name="<?php echo self::OPTION_SETTINGS; ?>[lang]">
                                <option value="fr" <?php selected($settings['lang'], 'fr'); ?>>Français</option>
                                <option value="en" <?php selected($settings['lang'], 'en'); ?>>English</option>
                                <option value="es" <?php selected($settings['lang'], 'es'); ?>>Español</option>
                                <option value="de" <?php selected($settings['lang'], 'de'); ?>>Deutsch</option>
                                <option value="it" <?php selected($settings['lang'], 'it'); ?>>Italiano</option>
                                <option value="pt" <?php selected($settings['lang'], 'pt'); ?>>Português</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="azsa_max_pages">Pages max crawl</label></th>
                        <td><input id="azsa_max_pages" name="<?php echo self::OPTION_SETTINGS; ?>[max_pages]" type="number" min="10" max="300" value="<?php echo esc_attr($settings['max_pages']); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="azsa_max_depth">Profondeur max crawl</label></th>
                        <td><input id="azsa_max_depth" name="<?php echo self::OPTION_SETTINGS; ?>[max_depth]" type="number" min="1" max="4" value="<?php echo esc_attr($settings['max_depth']); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="azsa_api_key">Anthropic API key (optionnel)</label></th>
                        <td><input id="azsa_api_key" name="<?php echo self::OPTION_SETTINGS; ?>[api_key]" type="password" class="regular-text" value="<?php echo esc_attr($settings['api_key']); ?>" autocomplete="off" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="azsa_model">Modèle (optionnel)</label></th>
                        <td><input id="azsa_model" name="<?php echo self::OPTION_SETTINGS; ?>[model]" type="text" class="regular-text" value="<?php echo esc_attr($settings['model']); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="azsa_eleven_key">ElevenLabs API key (voix)</label></th>
                        <td><input id="azsa_eleven_key" name="<?php echo self::OPTION_SETTINGS; ?>[elevenlabs_api_key]" type="password" class="regular-text" value="<?php echo esc_attr($settings['elevenlabs_api_key']); ?>" autocomplete="off" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="azsa_eleven_voice_male">ElevenLabs Voice ID homme</label></th>
                        <td><input id="azsa_eleven_voice_male" name="<?php echo self::OPTION_SETTINGS; ?>[elevenlabs_voice_male]" type="text" class="regular-text" value="<?php echo esc_attr($settings['elevenlabs_voice_male']); ?>" placeholder="ex: HQFJsVV9DOZgHpgWP5ku" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="azsa_eleven_speed">Vitesse voix</label></th>
                        <td><input id="azsa_eleven_speed" name="<?php echo self::OPTION_SETTINGS; ?>[elevenlabs_speed]" type="number" step="0.01" min="0.7" max="1.2" value="<?php echo esc_attr($settings['elevenlabs_speed']); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="azsa_github_repo">GitHub repo (updates)</label></th>
                        <td>
                            <input id="azsa_github_repo" name="<?php echo self::OPTION_SETTINGS; ?>[github_repo]" type="text" class="regular-text" value="<?php echo esc_attr($settings['github_repo']); ?>" placeholder="owner/repository" />
                            <p class="description">Ex: <code>votre-org/monassistant-ia</code>. Les mises à jour utiliseront la dernière release GitHub.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="azsa_github_token">GitHub token (optionnel)</label></th>
                        <td>
                            <input id="azsa_github_token" name="<?php echo self::OPTION_SETTINGS; ?>[github_token]" type="password" class="regular-text" value="<?php echo esc_attr($settings['github_token']); ?>" autocomplete="off" />
                            <p class="description">Utile pour repo privé ou limites API GitHub.</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button('Enregistrer'); ?>
            </form>
        </div>
        <?php
    }

    public static function register_rest() {
        register_rest_route('azsa/v1', '/chat', array(
            'methods' => 'POST',
            'permission_callback' => '__return_true',
            'callback' => array(__CLASS__, 'rest_chat'),
        ));
        register_rest_route('azsa/v1', '/tts', array(
            'methods' => 'POST',
            'permission_callback' => '__return_true',
            'callback' => array(__CLASS__, 'rest_tts'),
        ));
        register_rest_route('azsa/v1', '/lead', array(
            'methods' => 'POST',
            'permission_callback' => '__return_true',
            'callback' => array(__CLASS__, 'rest_lead'),
        ));
    }

    public static function get_latest_github_release($settings = array(), $force = false) {
        $repo = isset($settings['github_repo']) ? trim((string) $settings['github_repo']) : '';
        if ($repo === '' || strpos($repo, '/') === false) {
            return null;
        }
        $parts = explode('/', $repo, 2);
        $owner = rawurlencode($parts[0]);
        $name = rawurlencode($parts[1]);

        $cache_key = 'azsa_gh_release_' . md5($repo);
        if (!$force) {
            $cached = get_transient($cache_key);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $headers = array(
            'Accept' => 'application/vnd.github+json',
            'User-Agent' => 'MonAssistant-IA-Updater',
        );
        $token = isset($settings['github_token']) ? trim((string) $settings['github_token']) : '';
        if ($token !== '') {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        $url = 'https://api.github.com/repos/' . $owner . '/' . $name . '/releases/latest';
        $res = wp_remote_get($url, array(
            'timeout' => 20,
            'headers' => $headers,
        ));
        $data = null;
        if (!is_wp_error($res) && (int) wp_remote_retrieve_response_code($res) < 300) {
            $body = json_decode((string) wp_remote_retrieve_body($res), true);
            if (is_array($body)) {
                $version = '';
                if (!empty($body['tag_name'])) {
                    $version = ltrim((string) $body['tag_name'], 'vV');
                } elseif (!empty($body['name'])) {
                    $version = ltrim((string) $body['name'], 'vV');
                }

                $package = '';
                if (!empty($body['assets']) && is_array($body['assets'])) {
                    foreach ($body['assets'] as $asset) {
                        $asset_name = isset($asset['name']) ? strtolower((string) $asset['name']) : '';
                        if ($asset_name !== '' && substr($asset_name, -4) === '.zip' && !empty($asset['browser_download_url'])) {
                            $package = (string) $asset['browser_download_url'];
                            break;
                        }
                    }
                }
                if ($package === '' && !empty($body['zipball_url'])) {
                    $package = (string) $body['zipball_url'];
                }

                if ($version !== '' && $package !== '') {
                    $data = array(
                        'version' => $version,
                        'package' => $package,
                        'url' => !empty($body['html_url']) ? (string) $body['html_url'] : ('https://github.com/' . $repo),
                        'body' => !empty($body['body']) ? wp_strip_all_tags((string) $body['body']) : '',
                    );
                }
            }
        }

        // Fallback: no GitHub Release published yet, use latest tag.
        if (!is_array($data)) {
            $tags_url = 'https://api.github.com/repos/' . $owner . '/' . $name . '/tags?per_page=1';
            $tags_res = wp_remote_get($tags_url, array(
                'timeout' => 20,
                'headers' => $headers,
            ));
            if (is_wp_error($tags_res) || (int) wp_remote_retrieve_response_code($tags_res) >= 300) {
                return null;
            }
            $tags = json_decode((string) wp_remote_retrieve_body($tags_res), true);
            if (!is_array($tags) || empty($tags[0]['name'])) {
                return null;
            }
            $tag = (string) $tags[0]['name'];
            $version = ltrim($tag, 'vV');
            if ($version === '') {
                return null;
            }
            $data = array(
                'version' => $version,
                'package' => 'https://github.com/' . $repo . '/archive/refs/tags/' . rawurlencode($tag) . '.zip',
                'url' => 'https://github.com/' . $repo . '/releases',
                'body' => 'Update depuis tag GitHub: ' . $tag,
            );
        }

        set_transient($cache_key, $data, HOUR_IN_SECONDS);
        return $data;
    }

    public static function check_for_updates($transient) {
        if (!is_object($transient) || empty($transient->checked) || !is_array($transient->checked)) {
            return $transient;
        }

        $plugin_file = plugin_basename(__FILE__);
        if (!isset($transient->checked[$plugin_file])) {
            return $transient;
        }

        $settings = self::get_settings();
        $release = self::get_latest_github_release($settings, false);
        if (!is_array($release) || empty($release['version']) || empty($release['package'])) {
            return $transient;
        }

        $current_version = (string) $transient->checked[$plugin_file];
        if (version_compare($release['version'], $current_version, '<=')) {
            return $transient;
        }

        $update = (object) array(
            'id' => 'github:' . trim((string) $settings['github_repo']),
            'slug' => dirname($plugin_file),
            'plugin' => $plugin_file,
            'new_version' => $release['version'],
            'url' => $release['url'],
            'package' => $release['package'],
            'tested' => get_bloginfo('version'),
            'requires_php' => phpversion(),
        );
        $transient->response[$plugin_file] = $update;
        return $transient;
    }

    public static function plugin_info_popup($result, $action, $args) {
        if ($action !== 'plugin_information' || !is_object($args) || empty($args->slug)) {
            return $result;
        }

        $plugin_file = plugin_basename(__FILE__);
        $slug = dirname($plugin_file);
        if ((string) $args->slug !== (string) $slug) {
            return $result;
        }

        $settings = self::get_settings();
        $release = self::get_latest_github_release($settings, false);
        if (!is_array($release)) {
            return $result;
        }

        return (object) array(
            'name' => 'Chatbot Mon Assistant IA',
            'slug' => $slug,
            'version' => $release['version'],
            'author' => '<a href=\"https://github.com/' . esc_attr((string) $settings['github_repo']) . '\">GitHub</a>',
            'homepage' => $release['url'],
            'download_link' => $release['package'],
            'sections' => array(
                'description' => 'Assistant flottant IA avec crawl du site, chat, mode vocal et animations.',
                'changelog' => nl2br(esc_html((string) $release['body'])),
            ),
        );
    }

    public static function auto_update_plugin($update, $item) {
        if (!is_object($item) || empty($item->plugin)) {
            return $update;
        }
        return ((string) $item->plugin === plugin_basename(__FILE__)) ? true : $update;
    }

    public static function clear_update_cache($upgrader, $hook_extra) {
        if (!is_array($hook_extra) || empty($hook_extra['type']) || $hook_extra['type'] !== 'plugin') {
            return;
        }
        delete_site_transient('update_plugins');
        $settings = self::get_settings();
        $repo = isset($settings['github_repo']) ? (string) $settings['github_repo'] : '';
        if ($repo !== '') {
            delete_transient('azsa_gh_release_' . md5($repo));
        }
    }

    public static function rest_chat(WP_REST_Request $request) {
        $message = trim((string) $request->get_param('message'));
        if ($message === '') {
            return new WP_REST_Response(array('reply' => 'Message vide.'), 400);
        }

        $index = get_option(self::OPTION_INDEX, array());
        $docs = isset($index['docs']) && is_array($index['docs']) ? $index['docs'] : array();
        if (empty($docs)) {
            return new WP_REST_Response(array('reply' => 'Je n\'ai pas encore de données indexées. Réessayez dans quelques minutes.'), 200);
        }

        $hits = self::search_docs($message, $docs, 5);
        $settings = self::get_settings();

        $llm = self::llm_reply($message, $hits, $settings);
        $reply = isset($llm['reply']) ? (string) $llm['reply'] : '';
        if ($reply === '') {
            $reply = self::local_reply($message, $hits);
        }
        $suggestions = array();
        if (!empty($llm['suggestions']) && is_array($llm['suggestions'])) {
            $suggestions = array_values(array_slice(array_filter(array_map('trim', $llm['suggestions'])), 0, 4));
        }
        if (empty($suggestions)) {
            $suggestions = self::local_suggestions($message, $hits, $reply);
        }

        return new WP_REST_Response(array(
            'reply' => $reply,
            'suggestions' => $suggestions,
            'sources' => array_map(function ($d) {
                return array('title' => $d['title'], 'url' => $d['url']);
            }, $hits),
        ), 200);
    }

    public static function rest_tts(WP_REST_Request $request) {
        $text = trim((string) $request->get_param('text'));
        if ($text === '') {
            return new WP_REST_Response(array('mode' => 'browser_tts'), 200);
        }

        $settings = self::get_settings();
        $api_key = trim((string) ($settings['elevenlabs_api_key'] ?? ''));
        $voice_id = trim((string) ($settings['elevenlabs_voice_male'] ?? ''));
        if ($api_key === '' || $voice_id === '') {
            return new WP_REST_Response(array('mode' => 'browser_tts'), 200);
        }

        $lang = (string) ($settings['lang'] ?? 'fr');
        if (!in_array($lang, array('fr', 'en', 'es', 'de', 'it', 'pt'), true)) {
            $lang = 'fr';
        }

        $payload = array(
            'text' => wp_strip_all_tags($text),
            'model_id' => 'eleven_flash_v2_5',
            'language_code' => $lang,
            'voice_settings' => array(
                'stability' => 0.26,
                'similarity_boost' => 0.86,
                'style' => 0.72,
                'use_speaker_boost' => true,
                'speed' => (float) ($settings['elevenlabs_speed'] ?? '1.00'),
            ),
        );

        $res = wp_remote_post('https://api.elevenlabs.io/v1/text-to-speech/' . rawurlencode($voice_id), array(
            'timeout' => 35,
            'headers' => array(
                'xi-api-key' => $api_key,
                'accept' => 'audio/mpeg',
                'content-type' => 'application/json',
            ),
            'body' => wp_json_encode($payload),
        ));

        if (is_wp_error($res)) {
            return new WP_REST_Response(array('mode' => 'browser_tts'), 200);
        }
        $code = (int) wp_remote_retrieve_response_code($res);
        $audio = wp_remote_retrieve_body($res);
        if ($code >= 300 || !$audio) {
            return new WP_REST_Response(array('mode' => 'browser_tts'), 200);
        }

        return new WP_REST_Response(array(
            'mode' => 'ai_audio',
            'mime' => 'audio/mpeg',
            'audio_b64' => base64_encode($audio),
        ), 200);
    }

    public static function rest_lead(WP_REST_Request $request) {
        $first_name = sanitize_text_field((string) $request->get_param('first_name'));
        $last_name = sanitize_text_field((string) $request->get_param('last_name'));
        $email = sanitize_email((string) $request->get_param('email'));
        $phone = sanitize_text_field((string) $request->get_param('phone'));
        $transcript = (string) $request->get_param('transcript');
        $page_url = esc_url_raw((string) $request->get_param('page_url'));
        $intent = sanitize_text_field((string) $request->get_param('intent'));
        $wants_rdv = (bool) $request->get_param('wants_rdv');

        if ($email === '' || !is_email($email)) {
            return new WP_REST_Response(array('ok' => false, 'message' => 'Email invalide.'), 400);
        }

        $phone = preg_replace('/[^0-9+\s().-]/', '', $phone);
        $transcript = self::smart_trim($transcript, 4000);
        $ref = 'LEAD-' . gmdate('Ymd-His') . '-' . wp_generate_password(4, false, false);

        $lead = array(
            'ref' => $ref,
            'created_at' => gmdate('c'),
            'first_name' => $first_name,
            'last_name' => $last_name,
            'email' => $email,
            'phone' => $phone,
            'intent' => $intent,
            'wants_rdv' => $wants_rdv ? 1 : 0,
            'page_url' => $page_url,
            'transcript' => $transcript,
        );

        $leads = get_option(self::OPTION_LEADS, array());
        if (!is_array($leads)) {
            $leads = array();
        }
        array_unshift($leads, $lead);
        if (count($leads) > 1000) {
            $leads = array_slice($leads, 0, 1000);
        }
        update_option(self::OPTION_LEADS, $leads, false);

        $subject = 'Votre récapitulatif - Chatbot Mon Assistant IA';
        $body = "Bonjour,\n\n"
            . "Merci pour votre échange avec notre assistant.\n"
            . "Référence: {$ref}\n\n"
            . "Prénom: " . ($first_name !== '' ? $first_name : 'Non renseigné') . "\n"
            . "Nom: " . ($last_name !== '' ? $last_name : 'Non renseigné') . "\n"
            . "Email: {$email}\n"
            . "Téléphone: " . ($phone !== '' ? $phone : 'Non renseigné') . "\n"
            . "Demande: " . ($wants_rdv ? 'Souhaite un RDV téléphonique' : 'Récapitulatif') . "\n"
            . "Page: " . ($page_url !== '' ? $page_url : 'N/A') . "\n\n"
            . "Récapitulatif de l'échange:\n"
            . ($transcript !== '' ? $transcript : "Aucun message enregistré.") . "\n\n"
            . "Nous revenons vers vous si nécessaire.\n";

        wp_mail($email, $subject, $body);
        $admin_email = get_option('admin_email');
        if (is_email($admin_email)) {
            wp_mail($admin_email, '[Lead] ' . $ref . ' - ' . $email, $body);
        }

        return new WP_REST_Response(array('ok' => true, 'ref' => $ref), 200);
    }

    public static function llm_reply($message, $hits, $settings) {
        $api_key = trim((string) ($settings['api_key'] ?? ''));
        if ($api_key === '' && defined('ANTHROPIC_API_KEY')) {
            $api_key = (string) ANTHROPIC_API_KEY;
        }
        if ($api_key === '') {
            return array('reply' => '', 'suggestions' => array());
        }

        $ctx = array();
        foreach ($hits as $h) {
            $ctx[] = "Titre: {$h['title']}\nURL: {$h['url']}\nContenu: {$h['content']}";
        }

        $system = "Tu es un assistant expert du site web. Réponds en français (sauf demande explicite), ton clair, naturel, ponctué, avec accents corrects. "
            . "Base-toi uniquement sur les extraits fournis. Si info manquante: dis-le clairement. "
            . "Tu dois produire STRICTEMENT du JSON valide avec ce schéma: "
            . "{\"reply\":\"texte réponse utile\",\"suggestions\":[\"question courte 1\",\"question courte 2\",\"question courte 3\"]}. "
            . "Les suggestions doivent être contextuelles à la question et à ta réponse, max 3 à 4 mots chacune, sans URL, sans ponctuation finale.";

        $payload = array(
            'model' => $settings['model'] ?: 'claude-sonnet-4-20250514',
            'max_tokens' => 420,
            'temperature' => 0.35,
            'system' => $system,
            'messages' => array(
                array(
                    'role' => 'user',
                    'content' => array(
                        array(
                            'type' => 'text',
                            'text' => "Question utilisateur: " . wp_strip_all_tags($message) . "\n\n"
                                . "Extraits du site:\n" . implode("\n\n---\n\n", $ctx),
                        ),
                    ),
                ),
            ),
        );

        $res = wp_remote_post('https://api.anthropic.com/v1/messages', array(
            'timeout' => 25,
            'headers' => array(
                'x-api-key' => $api_key,
                'anthropic-version' => '2023-06-01',
                'content-type' => 'application/json',
            ),
            'body' => wp_json_encode($payload),
        ));

        if (is_wp_error($res)) {
            return array('reply' => '', 'suggestions' => array());
        }

        $code = wp_remote_retrieve_response_code($res);
        if ($code >= 300) {
            return array('reply' => '', 'suggestions' => array());
        }

        $body = json_decode(wp_remote_retrieve_body($res), true);
        if (!is_array($body) || empty($body['content'])) {
            return array('reply' => '', 'suggestions' => array());
        }

        $text = '';
        foreach ((array) $body['content'] as $chunk) {
            if (isset($chunk['type'], $chunk['text']) && $chunk['type'] === 'text') {
                $text .= (string) $chunk['text'];
            }
        }

        $raw = trim((string) $text);
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            if (preg_match('/\{.*\}/s', $raw, $m)) {
                $decoded = json_decode($m[0], true);
            }
        }

        if (is_array($decoded) && !empty($decoded['reply'])) {
            $reply = trim(wp_strip_all_tags((string) $decoded['reply']));
            $suggestions = array();
            if (!empty($decoded['suggestions']) && is_array($decoded['suggestions'])) {
                foreach ($decoded['suggestions'] as $s) {
                    $s = trim(wp_strip_all_tags((string) $s));
                    if ($s !== '') {
                        $suggestions[] = $s;
                    }
                }
            }
            return array(
                'reply' => $reply,
                'suggestions' => array_values(array_slice(array_unique($suggestions), 0, 4)),
            );
        }

        return array(
            'reply' => trim(wp_strip_all_tags($raw)),
            'suggestions' => array(),
        );
    }

    public static function local_suggestions($message, $hits, $reply) {
        $suggestions = array();

        $normalized = strtolower(remove_accents((string) $message . ' ' . (string) $reply));
        $topic_map = array(
            'prix' => array('Comparer les offres', 'Voir les tarifs', 'Quel plan choisir'),
            'tarif' => array('Comparer les offres', 'Voir les tarifs', 'Quel plan choisir'),
            'offre' => array('Comparer les offres', 'Voir les tarifs', 'Quel plan choisir'),
            'contact' => array('Comment vous contacter', 'Parler à un conseiller', 'Envoyer un message'),
            'formation' => array('Programme complet', 'Durée de formation', 'Niveau requis'),
            'rncp' => array('RNCP concernés', 'Préparer la certification', 'Compétences attendues'),
            'rs ' => array('Références RS', 'Objectifs pédagogiques', 'Évaluation finale'),
            'wordpress' => array('Créer un site WordPress', 'Choisir un hébergeur', 'Comparer les hébergeurs'),
            'hebergement' => array('Comparer les hébergeurs', 'OVH ou Hostinger', 'Critères de choix'),
            'assistant' => array('Fonctionnalités clés', 'Mode vocal ou écrit', 'Cas d usage'),
            'demo' => array('Essayer la démo', 'Questions fréquentes', 'Exemple concret'),
        );
        foreach ($topic_map as $needle => $items) {
            if (strpos($normalized, $needle) !== false) {
                $suggestions = array_merge($suggestions, $items);
            }
        }

        foreach (array_slice((array) $hits, 0, 3) as $hit) {
            $title = trim(wp_strip_all_tags((string) ($hit['title'] ?? '')));
            if ($title !== '') {
                $suggestions[] = 'En savoir plus';
                $suggestions[] = 'Voir cette section';
                break;
            }
        }

        if (empty($suggestions)) {
            $suggestions = array('En savoir plus', 'Donner un exemple', 'Que faire ensuite');
        }

        return array_values(array_slice(array_unique($suggestions), 0, 3));
    }

    public static function local_reply($message, $hits) {
        if (empty($hits)) {
            return 'Je n\'ai pas trouvé d\'information claire sur cette question dans le site pour le moment.';
        }

        $top = array_slice($hits, 0, 3);
        $parts = array();
        foreach ($top as $h) {
            $summary = self::smart_trim($h['content'], 220);
            $parts[] = $h['title'] . ': ' . $summary . ' (source: ' . $h['url'] . ')';
        }

        return "Voici ce que j\'ai trouvé sur le site:\n- " . implode("\n- ", $parts);
    }

    public static function search_docs($message, $docs, $limit = 5) {
        $tokens = self::tokens($message);
        $scored = array();

        foreach ($docs as $doc) {
            $haystack = strtolower(($doc['title'] ?? '') . ' ' . ($doc['content'] ?? ''));
            $score = 0;
            foreach ($tokens as $t) {
                if ($t !== '' && strpos($haystack, $t) !== false) {
                    $score += 2;
                }
            }
            if ($score > 0) {
                $doc['_score'] = $score;
                $scored[] = $doc;
            }
        }

        usort($scored, function ($a, $b) {
            return ($b['_score'] ?? 0) <=> ($a['_score'] ?? 0);
        });

        return array_slice($scored, 0, $limit);
    }

    public static function tokens($text) {
        $text = strtolower(wp_strip_all_tags((string) $text));
        $parts = preg_split('/[^\p{L}\p{N}]+/u', $text);
        $out = array();
        foreach ((array) $parts as $p) {
            $p = trim((string) $p);
            if ($p === '' || strlen($p) < 3) {
                continue;
            }
            $out[$p] = true;
        }
        return array_keys($out);
    }

    public static function smart_trim($text, $max = 1200) {
        $text = preg_replace('/\s+/', ' ', wp_strip_all_tags((string) $text));
        $text = trim((string) $text);
        if (function_exists('mb_strlen') && mb_strlen($text, 'UTF-8') > $max) {
            return rtrim(mb_substr($text, 0, $max, 'UTF-8')) . '...';
        }
        if (strlen($text) > $max) {
            return rtrim(substr($text, 0, $max)) . '...';
        }
        return $text;
    }

    public static function normalize_internal_url($url, $home_host) {
        $url = trim((string) $url);
        if ($url === '' || strpos($url, 'mailto:') === 0 || strpos($url, 'tel:') === 0 || strpos($url, 'javascript:') === 0) {
            return '';
        }

        $url = strtok($url, '#');
        if ($url === '') {
            return '';
        }

        if (strpos($url, '//') === 0) {
            $url = home_url('/') . ltrim($url, '/');
        } elseif (strpos($url, 'http://') !== 0 && strpos($url, 'https://') !== 0) {
            $url = home_url('/') . ltrim($url, '/');
        }

        $parts = wp_parse_url($url);
        if (!$parts || empty($parts['host'])) {
            return '';
        }

        if (strtolower($parts['host']) !== strtolower($home_host)) {
            return '';
        }

        $normalized = trailingslashit($parts['scheme'] . '://' . $parts['host'] . ($parts['path'] ?? '/'));
        return $normalized;
    }

    public static function extract_links($html) {
        $links = array();
        if (!is_string($html) || $html === '') {
            return $links;
        }

        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->loadHTML($html);
        $xpath = new DOMXPath($dom);
        $nodes = $xpath->query('//a[@href]');
        if ($nodes) {
            foreach ($nodes as $node) {
                $links[] = $node->getAttribute('href');
            }
        }
        libxml_clear_errors();

        return array_values(array_unique($links));
    }

    public static function extract_text($html) {
        if (!is_string($html) || $html === '') {
            return '';
        }

        $clean = preg_replace('#<script[^>]*>.*?</script>#is', ' ', $html);
        $clean = preg_replace('#<style[^>]*>.*?</style>#is', ' ', (string) $clean);
        $clean = wp_strip_all_tags((string) $clean);
        $clean = preg_replace('/\s+/', ' ', (string) $clean);
        return trim((string) $clean);
    }

    public static function rebuild_index() {
        update_option('azsa_needs_reindex', 0, false);
        $settings = self::get_settings();
        $max_pages = (int) $settings['max_pages'];
        $max_depth = (int) $settings['max_depth'];

        $home = home_url('/');
        $home_host = wp_parse_url($home, PHP_URL_HOST);

        $queue = array(array('url' => trailingslashit($home), 'depth' => 0));
        $visited = array();
        $docs = array();

        while (!empty($queue) && count($docs) < $max_pages) {
            $item = array_shift($queue);
            $url = $item['url'];
            $depth = (int) $item['depth'];

            if (isset($visited[$url])) {
                continue;
            }
            $visited[$url] = true;

            $res = wp_remote_get($url, array(
                'timeout' => 12,
                'redirection' => 3,
                'headers' => array('Accept' => 'text/html'),
            ));

            if (is_wp_error($res)) {
                continue;
            }

            $code = (int) wp_remote_retrieve_response_code($res);
            $ctype = (string) wp_remote_retrieve_header($res, 'content-type');
            if ($code >= 300 || stripos($ctype, 'text/html') === false) {
                continue;
            }

            $body = (string) wp_remote_retrieve_body($res);
            if ($body === '') {
                continue;
            }

            preg_match('/<title[^>]*>(.*?)<\/title>/is', $body, $m);
            $title = !empty($m[1]) ? self::smart_trim(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'), 180) : $url;
            $content = self::smart_trim(self::extract_text($body), 5000);

            if ($content !== '') {
                $docs[] = array(
                    'url' => esc_url_raw($url),
                    'title' => $title,
                    'content' => $content,
                );
            }

            if ($depth >= $max_depth) {
                continue;
            }

            $links = self::extract_links($body);
            foreach ($links as $link) {
                $norm = self::normalize_internal_url($link, $home_host);
                if ($norm === '' || isset($visited[$norm])) {
                    continue;
                }
                $queue[] = array('url' => $norm, 'depth' => $depth + 1);
            }
        }

        update_option(self::OPTION_INDEX, array(
            'generated_at' => current_time('mysql'),
            'home' => $home,
            'docs' => $docs,
        ), false);
    }
}

AZSA_Plugin::init();
