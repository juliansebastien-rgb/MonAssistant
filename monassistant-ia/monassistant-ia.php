<?php
/**
 * Plugin Name: Chatbot Mon Assistant IA
 * Description: Assistant flottant pour répondre aux visiteurs à partir des contenus du site (crawl + index + chat).
 * Version: 3.1.0
 * Author: Azertaf
 */

if (!defined('ABSPATH')) {
    exit;
}

final class AZSA_Plugin {
    const VERSION = '3.1.0';
    const OPTION_LEADS = 'azsa_leads';
    const OPTION_SETTINGS = 'azsa_settings';
    const OPTION_PUBLIC_OWNER = 'azsa_public_owner_user_id';
    const OPTION_ADMIN_NOTES = 'azsa_admin_notes';
    const OPTION_ADMIN_CHAT_STATE = 'azsa_admin_chat_state';
    const OPTION_EVENTS = 'azsa_events';
    const OPTION_INDEX = 'azsa_index';
    const CRON_HOOK = 'azsa_rebuild_index_cron';
    const DEFAULT_ROBOT_LOGO_URL = 'https://monassistant.mapage-wp.online/wp-content/uploads/2026/03/MAP-logo-tete.gif';
    const DEFAULT_GIF_BASE_URL = 'https://monassistant.mapage-wp.online/wp-content/uploads/2026/03/';
    const DEFAULT_GITHUB_REPO = 'juliansebastien-rgb/MonAssistant';
    const DEFAULT_GITHUB_TOKEN = '';

    public static function init() {
        add_action('init', array(__CLASS__, 'register_assets'));
        add_action('wp_enqueue_scripts', array(__CLASS__, 'enqueue_front'));
        add_action('template_redirect', array(__CLASS__, 'maybe_render_admin_assistant_page'));
        add_action('admin_menu', array(__CLASS__, 'admin_menu'));
        add_action('admin_init', array(__CLASS__, 'register_settings'));
        add_action('rest_api_init', array(__CLASS__, 'register_rest'));
        add_action(self::CRON_HOOK, array(__CLASS__, 'rebuild_index'));
        add_filter('pre_set_site_transient_update_plugins', array(__CLASS__, 'check_for_updates'));
        add_filter('plugins_api', array(__CLASS__, 'plugin_info_popup'), 10, 3);
        add_filter('plugins_auto_update_enabled', '__return_true');
        add_filter('plugin_auto_update_setting_html', array(__CLASS__, 'plugin_auto_update_setting_html'), 10, 3);
        add_action('admin_init', array(__CLASS__, 'handle_plugin_row_auto_update_toggle'));
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
            'elevenlabs_speed' => '1.08',
            'calendar_provider' => 'none',
            'calendly_url' => '',
            'calendly_pat' => '',
            'github_repo' => self::DEFAULT_GITHUB_REPO,
            'github_token' => self::DEFAULT_GITHUB_TOKEN,
        );
    }

    public static function normalize_owner_user_id($owner_user_id) {
        $owner_user_id = (int) $owner_user_id;
        if ($owner_user_id > 0) {
            return $owner_user_id;
        }
        $fallback = self::get_public_owner_user_id();
        return $fallback > 0 ? $fallback : 1;
    }

    public static function get_public_owner_user_id() {
        $owner_user_id = (int) get_option(self::OPTION_PUBLIC_OWNER, 0);
        if ($owner_user_id > 0) {
            return $owner_user_id;
        }
        if (function_exists('get_users')) {
            $admins = get_users(array(
                'role' => 'administrator',
                'number' => 1,
                'orderby' => 'ID',
                'order' => 'ASC',
                'fields' => 'ID',
            ));
            if (!empty($admins[0])) {
                return (int) $admins[0];
            }
        }
        return 1;
    }

    public static function set_public_owner_user_id($owner_user_id) {
        $owner_user_id = self::normalize_owner_user_id($owner_user_id);
        update_option(self::OPTION_PUBLIC_OWNER, $owner_user_id, false);
        return $owner_user_id;
    }

    public static function get_runtime_owner_user_id() {
        $uid = (int) get_current_user_id();
        if ($uid > 0 && is_admin() && current_user_can('manage_options')) {
            return $uid;
        }
        return self::get_public_owner_user_id();
    }

    public static function get_admin_assistant_token($owner_user_id, $regenerate = false) {
        $owner_user_id = self::normalize_owner_user_id($owner_user_id);
        $key = 'azsa_admin_assistant_token';
        $token = (string) get_user_meta($owner_user_id, $key, true);
        if ($regenerate || $token === '' || strlen($token) < 24) {
            $token = wp_generate_password(48, false, false);
            update_user_meta($owner_user_id, $key, $token);
        }
        return $token;
    }

    public static function validate_admin_assistant_token($owner_user_id, $token) {
        $owner_user_id = self::normalize_owner_user_id($owner_user_id);
        $stored = (string) self::get_admin_assistant_token($owner_user_id, false);
        $token = (string) $token;
        if ($stored === '' || $token === '') {
            return false;
        }
        return hash_equals($stored, $token);
    }

    public static function get_admin_assistant_url($owner_user_id = null) {
        if ($owner_user_id === null) {
            $owner_user_id = self::get_runtime_owner_user_id();
        }
        $owner_user_id = self::normalize_owner_user_id($owner_user_id);
        $token = self::get_admin_assistant_token($owner_user_id, false);
        return add_query_arg(array(
            'azsa_admin_assistant' => '1',
            'owner' => $owner_user_id,
            'token' => $token,
        ), home_url('/'));
    }

    public static function get_settings($owner_user_id = null) {
        if ($owner_user_id === null) {
            $owner_user_id = self::get_runtime_owner_user_id();
        }
        $owner_user_id = self::normalize_owner_user_id($owner_user_id);
        $raw = get_option(self::OPTION_SETTINGS, array());
        $settings = array();
        if (is_array($raw) && isset($raw['by_owner']) && is_array($raw['by_owner'])) {
            $settings = isset($raw['by_owner'][(string) $owner_user_id]) && is_array($raw['by_owner'][(string) $owner_user_id])
                ? $raw['by_owner'][(string) $owner_user_id]
                : array();
        } elseif (is_array($raw)) {
            // Backward compatibility with legacy flat option shape.
            $settings = $raw;
        }
        $settings = wp_parse_args(is_array($settings) ? $settings : array(), self::defaults());
        // Zero-config updates: always use the official repo embedded in plugin code.
        $settings['github_repo'] = self::DEFAULT_GITHUB_REPO;
        return $settings;
    }

    public static function activate() {
        $owner_user_id = self::set_public_owner_user_id(get_current_user_id());
        $settings = self::get_settings($owner_user_id);
        $raw = get_option(self::OPTION_SETTINGS, array());
        if (!is_array($raw) || !isset($raw['by_owner']) || !is_array($raw['by_owner'])) {
            $raw = array('by_owner' => array());
        }
        $raw['by_owner'][(string) $owner_user_id] = $settings;
        update_option(self::OPTION_SETTINGS, $raw, false);
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
            'calendlyResolveUrl' => esc_url_raw(rest_url('azsa/v1/calendly/resolve')),
            'calendlyPollUrl' => esc_url_raw(rest_url('azsa/v1/calendly/poll')),
            'ttsUrl' => esc_url_raw(rest_url('azsa/v1/tts')),
            'calendarProvider' => (string) ($settings['calendar_provider'] ?? 'none'),
            'calendlyUrl' => esc_url_raw((string) ($settings['calendly_url'] ?? '')),
            'nonce' => wp_create_nonce('wp_rest'),
            'hasIndex' => $has_index,
            'lang' => (string) $settings['lang'],
            'welcome' => $has_index
                ? 'Bonjour, je suis votre assistant MonAssistant IA. Posez-moi vos questions sur le site.'
                : 'Bonjour, l\'index du site est en cours de préparation. Revenez dans quelques minutes.',
        ));
    }

    public static function admin_menu() {
        add_menu_page(
            'Chatbot Mon Assistant IA',
            'Chatbot Mon Assistant IA',
            'manage_options',
            'azsa-prospects',
            array(__CLASS__, 'render_prospects_page'),
            'dashicons-format-chat',
            58
        );
        add_submenu_page(
            'azsa-prospects',
            'Contacts',
            'Contacts',
            'manage_options',
            'azsa-prospects',
            array(__CLASS__, 'render_prospects_page')
        );
        add_submenu_page(
            'azsa-prospects',
            'Événements',
            'Événements',
            'manage_options',
            'azsa-events',
            array(__CLASS__, 'render_events_page')
        );
        add_submenu_page(
            'azsa-prospects',
            'Fiche contact',
            'Fiche contact',
            'manage_options',
            'azsa-contact',
            array(__CLASS__, 'render_contact_page')
        );
        add_submenu_page(
            'azsa-prospects',
            'Réglages',
            'Réglages',
            'manage_options',
            'azsa-settings',
            array(__CLASS__, 'render_admin_page')
        );
    }

    public static function register_settings() {
        register_setting('azsa_settings_group', self::OPTION_SETTINGS, array(__CLASS__, 'sanitize_settings'));
    }

    public static function sanitize_settings($input) {
        $owner_user_id = self::get_runtime_owner_user_id();
        $out = self::get_settings($owner_user_id);
        if (isset($input['calendar_provider'])) {
            $provider = sanitize_key((string) $input['calendar_provider']);
            $out['calendar_provider'] = in_array($provider, array('none', 'calendly'), true) ? $provider : 'none';
        }
        if (isset($input['calendly_url'])) {
            $out['calendly_url'] = esc_url_raw((string) $input['calendly_url']);
        }
        if (isset($input['calendly_pat'])) {
            $out['calendly_pat'] = sanitize_text_field((string) $input['calendly_pat']);
        }
        $out['github_repo'] = self::DEFAULT_GITHUB_REPO;
        $out = wp_parse_args($out, self::defaults());

        $raw = get_option(self::OPTION_SETTINGS, array());
        if (!is_array($raw)) {
            $raw = array();
        }
        // Migrate legacy flat shape to scoped by owner.
        if (!isset($raw['by_owner']) || !is_array($raw['by_owner'])) {
            $legacy = array();
            if (isset($raw['calendar_provider']) || isset($raw['calendly_url']) || isset($raw['calendly_pat'])) {
                $legacy = wp_parse_args($raw, self::defaults());
            }
            $raw = array('by_owner' => array());
            if (!empty($legacy)) {
                $raw['by_owner'][(string) self::get_public_owner_user_id()] = $legacy;
            }
        }
        $raw['by_owner'][(string) $owner_user_id] = $out;
        self::set_public_owner_user_id($owner_user_id);
        return $raw;
    }

    public static function render_admin_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $owner_user_id = self::get_runtime_owner_user_id();

        if (isset($_POST['azsa_regen_admin_assistant_token']) && check_admin_referer('azsa_regen_admin_assistant_token', 'azsa_regen_admin_assistant_token_nonce')) {
            self::get_admin_assistant_token($owner_user_id, true);
            echo '<div class="notice notice-success"><p>Lien de l’assistant personnel régénéré.</p></div>';
        }
        if (isset($_POST['azsa_email_admin_assistant_link']) && check_admin_referer('azsa_email_admin_assistant_link', 'azsa_email_admin_assistant_link_nonce')) {
            $admin_user = get_user_by('id', $owner_user_id);
            $to = ($admin_user && !empty($admin_user->user_email) && is_email((string) $admin_user->user_email))
                ? (string) $admin_user->user_email
                : (string) get_option('admin_email');
            $link = self::get_admin_assistant_url($owner_user_id);
            $site_name = (string) get_bloginfo('name');
            $subject = '[' . $site_name . '] Lien assistant personnel administrateur';
            $body = "Bonjour,\n\nVoici votre lien d'accès à l'assistant personnel CRM IA:\n" . $link . "\n\nSite: " . home_url('/') . "\n";
            if (is_email($to) && wp_mail($to, $subject, $body)) {
                echo '<div class="notice notice-success"><p>Lien envoyé par e-mail à ' . esc_html($to) . '.</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>Impossible d’envoyer l’e-mail pour le moment.</p></div>';
            }
        }

        if (isset($_POST['azsa_check_updates']) && check_admin_referer('azsa_check_updates_now', 'azsa_check_updates_nonce')) {
            $settings = self::get_settings();
            $repo = isset($settings['github_repo']) ? (string) $settings['github_repo'] : '';
            delete_site_transient('update_plugins');
            if ($repo !== '') {
                delete_transient('azsa_gh_release_' . md5($repo));
            }
            if (function_exists('wp_clean_plugins_cache')) {
                wp_clean_plugins_cache(true);
            }
            if (function_exists('wp_update_plugins')) {
                wp_update_plugins();
            }
            self::get_latest_github_release($settings, true);
            echo '<div class="notice notice-success"><p>Vérification des mises à jour effectuée.</p></div>';
        }
        if (isset($_POST['azsa_enable_auto_updates']) && check_admin_referer('azsa_toggle_auto_updates', 'azsa_toggle_auto_updates_nonce')) {
            self::set_auto_updates_enabled(true);
            echo '<div class="notice notice-success"><p>Mises à jour automatiques activées.</p></div>';
        }
        if (isset($_POST['azsa_disable_auto_updates']) && check_admin_referer('azsa_toggle_auto_updates', 'azsa_toggle_auto_updates_nonce')) {
            self::set_auto_updates_enabled(false);
            echo '<div class="notice notice-success"><p>Mises à jour automatiques désactivées.</p></div>';
        }

        $settings = self::get_settings($owner_user_id);
        $admin_assistant_url = self::get_admin_assistant_url($owner_user_id);
        $release = self::get_latest_github_release($settings, false);
        $latest = is_array($release) && !empty($release['version']) ? (string) $release['version'] : 'indisponible';
        $auto_enabled = self::is_auto_updates_enabled();
        $has_new = is_array($release) && !empty($release['version']) && version_compare((string) $release['version'], self::VERSION, '>');
        ?>
        <div class="wrap">
            <h1>Chatbot Mon Assistant IA</h1>
            <p>Configurez uniquement la connexion au calendrier pour les RDV.</p>
            <div style="margin:12px 0 18px;padding:12px 14px;background:#fff;border:1px solid #dcdcde;border-radius:10px;max-width:980px;">
                <p style="margin:0 0 8px;"><strong>Assistant personnel pour l’administrateur:</strong></p>
                <p id="azsa-admin-assistant-url" style="margin:0 0 10px;word-break:break-all;"><a href="<?php echo esc_url($admin_assistant_url); ?>" target="_blank" rel="noopener"><?php echo esc_html($admin_assistant_url); ?></a></p>
                <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
                    <button type="button" id="azsa-copy-admin-link" class="button">Copier</button>
                    <form method="post" style="margin:0;">
                        <?php wp_nonce_field('azsa_email_admin_assistant_link', 'azsa_email_admin_assistant_link_nonce'); ?>
                        <button type="submit" name="azsa_email_admin_assistant_link" class="button">Envoyer par e-mail</button>
                    </form>
                </div>
                <form method="post" style="margin:10px 0 0;">
                    <?php wp_nonce_field('azsa_regen_admin_assistant_token', 'azsa_regen_admin_assistant_token_nonce'); ?>
                    <button type="submit" name="azsa_regen_admin_assistant_token" class="button">Régénérer le lien</button>
                </form>
            </div>
            <script>
            (function(){
                var btn = document.getElementById('azsa-copy-admin-link');
                var node = document.getElementById('azsa-admin-assistant-url');
                if (!btn || !node) return;
                btn.addEventListener('click', function(){
                    var txt = node.textContent || '';
                    if (!txt) return;
                    if (navigator.clipboard && navigator.clipboard.writeText) {
                        navigator.clipboard.writeText(txt).then(function(){ btn.textContent = 'Copié'; setTimeout(function(){ btn.textContent = 'Copier'; }, 1200); });
                        return;
                    }
                    var ta = document.createElement('textarea'); ta.value = txt; document.body.appendChild(ta); ta.select(); document.execCommand('copy'); document.body.removeChild(ta);
                    btn.textContent = 'Copié'; setTimeout(function(){ btn.textContent = 'Copier'; }, 1200);
                });
            })();
            </script>
            <p>
                <strong>Version installée:</strong> <?php echo esc_html(self::VERSION); ?> |
                <strong>Dernière version:</strong> <?php echo esc_html($latest); ?> |
                <strong>Auto-update:</strong> <?php echo $auto_enabled ? 'Activé' : 'Désactivé'; ?>
                <?php if ($has_new): ?>
                    <span style="color:#0a7f33;font-weight:700;"> | Mise à jour disponible</span>
                <?php endif; ?>
            </p>

            <form method="post" style="margin: 0 0 14px;">
                <?php wp_nonce_field('azsa_check_updates_now', 'azsa_check_updates_nonce'); ?>
                <button type="submit" name="azsa_check_updates" class="button">Vérifier les mises à jour maintenant</button>
            </form>
            <form method="post" style="margin: 0 0 22px;">
                <?php wp_nonce_field('azsa_toggle_auto_updates', 'azsa_toggle_auto_updates_nonce'); ?>
                <?php if ($auto_enabled): ?>
                    <button type="submit" name="azsa_disable_auto_updates" class="button">Désactiver les mises à jour automatiques</button>
                <?php else: ?>
                    <button type="submit" name="azsa_enable_auto_updates" class="button button-primary">Activer les mises à jour automatiques</button>
                <?php endif; ?>
            </form>

            <div style="display:flex;gap:20px;align-items:flex-start;max-width:1200px;">
                <div style="flex:1 1 760px;min-width:520px;">
                    <form method="post" action="options.php">
                        <?php settings_fields('azsa_settings_group'); ?>
                        <table class="form-table" role="presentation">
                            <tr>
                                <th scope="row"><label for="azsa_calendar_provider">Agenda RDV</label></th>
                                <td>
                                    <select id="azsa_calendar_provider" name="<?php echo self::OPTION_SETTINGS; ?>[calendar_provider]">
                                        <option value="none" <?php selected($settings['calendar_provider'] ?? 'none', 'none'); ?>>Aucun</option>
                                        <option value="calendly" <?php selected($settings['calendar_provider'] ?? 'none', 'calendly'); ?>>Calendly</option>
                                    </select>
                                    <p class="description">Calendly affichera un calendrier de disponibilités dans le chat lors d'une demande de RDV.</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="azsa_calendly_url">URL Calendly</label></th>
                                <td>
                                    <input id="azsa_calendly_url" name="<?php echo self::OPTION_SETTINGS; ?>[calendly_url]" type="url" class="regular-text" value="<?php echo esc_attr($settings['calendly_url'] ?? ''); ?>" placeholder="https://calendly.com/votre-compte/votre-lien" />
                                    <p class="description">URL de prise de RDV publique Calendly (embed).</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="azsa_calendly_pat">Calendly PAT</label></th>
                                <td>
                                    <input id="azsa_calendly_pat" name="<?php echo self::OPTION_SETTINGS; ?>[calendly_pat]" type="password" class="regular-text" value="<?php echo esc_attr($settings['calendly_pat'] ?? ''); ?>" autocomplete="off" />
                                    <p class="description">Token personnel Calendly (Personal Access Token) pour récupérer nom, prénom, email et créneau du RDV.</p>
                                </td>
                            </tr>
                        </table>
                        <?php submit_button('Enregistrer'); ?>
                    </form>
                </div>
                <aside style="width:340px;background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:16px;box-shadow:0 1px 2px rgba(0,0,0,.04);">
                    <h2 style="margin-top:0;">Aide Calendly</h2>
                    <ol style="margin-left:18px;">
                        <li>Ouvrez Calendly puis <code>Integrations & apps</code> > <code>API and Webhooks</code>.</li>
                        <li>Créez un <code>Personal Access Token</code> (PAT).</li>
                        <li>Copiez votre lien public Calendly (ex: <code>https://calendly.com/votre-compte/votre-event</code>).</li>
                        <li>Collez le lien dans <strong>URL Calendly</strong>.</li>
                        <li>Collez le token dans <strong>Calendly PAT</strong>.</li>
                        <li>Enregistrez puis testez un RDV dans le chatbot.</li>
                    </ol>
                    <p style="margin-bottom:0;color:#50575e;">Le PAT sert uniquement à récupérer automatiquement nom, prénom, email et créneau après réservation.</p>
                </aside>
            </div>
        </div>
        <?php
    }

    public static function get_all_leads_raw() {
        $raw = get_option(self::OPTION_LEADS, null);
        if ($raw === null) {
            // Legacy fallback keys.
            $legacy_keys = array('azsa_lead', 'azsa_leads_v1', 'monassistant_leads');
            foreach ($legacy_keys as $k) {
                $v = get_option($k, null);
                if ($v !== null) {
                    $raw = $v;
                    break;
                }
            }
        }
        return self::normalize_leads($raw);
    }

    public static function get_leads($owner_user_id = null) {
        if ($owner_user_id === null) {
            $owner_user_id = self::get_runtime_owner_user_id();
        }
        $owner_user_id = self::normalize_owner_user_id($owner_user_id);
        $all = self::get_all_leads_raw();
        return array_values(array_filter($all, function ($lead) use ($owner_user_id) {
            return self::lead_visible_for_owner($lead, $owner_user_id);
        }));
    }

    public static function update_leads($leads, $owner_user_id = null) {
        if (!is_array($leads)) {
            $leads = array();
        }
        if ($owner_user_id === null) {
            $owner_user_id = self::get_runtime_owner_user_id();
        }
        $owner_user_id = self::normalize_owner_user_id($owner_user_id);
        $normalized = self::normalize_leads($leads);
        foreach ($normalized as &$row) {
            $row['owner_user_id'] = $owner_user_id;
        }
        unset($row);

        $all = self::get_all_leads_raw();
        $kept = array_values(array_filter($all, function ($lead) use ($owner_user_id) {
            return !self::lead_visible_for_owner($lead, $owner_user_id);
        }));
        $merged = array_merge($normalized, $kept);
        usort($merged, function ($a, $b) {
            $ta = strtotime((string) ($a['created_at'] ?? '')) ?: 0;
            $tb = strtotime((string) ($b['created_at'] ?? '')) ?: 0;
            return $tb <=> $ta;
        });
        if (count($merged) > 3000) {
            $merged = array_slice($merged, 0, 3000);
        }
        update_option(self::OPTION_LEADS, $merged, false);
    }

    public static function update_lead_phone($owner_user_id, $contact_ref, $phone) {
        $owner_user_id = self::normalize_owner_user_id($owner_user_id);
        $contact_ref = sanitize_text_field((string) $contact_ref);
        $phone = preg_replace('/[^0-9+\s().-]/', '', (string) $phone);
        if ($contact_ref === '' || trim((string) $phone) === '') {
            return false;
        }
        $leads = self::get_leads($owner_user_id);
        $changed = false;
        foreach ($leads as &$lead) {
            if ((string) ($lead['ref'] ?? '') !== $contact_ref) {
                continue;
            }
            $lead['phone'] = trim((string) $phone);
            $changed = true;
            break;
        }
        unset($lead);
        if ($changed) {
            self::update_leads($leads, $owner_user_id);
        }
        return $changed;
    }

    public static function normalize_leads($raw) {
        if (is_string($raw) && $raw !== '') {
            $json = json_decode($raw, true);
            if (is_array($json)) {
                $raw = $json;
            } elseif (strpos($raw, 'a:') === 0 && function_exists('maybe_unserialize')) {
                $raw = maybe_unserialize($raw);
            }
        }
        if (!is_array($raw)) {
            return array();
        }
        // If single associative lead, wrap it.
        if (isset($raw['ref']) || isset($raw['email']) || isset($raw['phone'])) {
            $raw = array($raw);
        }
        $out = array();
        foreach ($raw as $lead) {
            if (!is_array($lead)) {
                continue;
            }
            $out[] = array(
                'ref' => (string) ($lead['ref'] ?? ''),
                'created_at' => (string) ($lead['created_at'] ?? ''),
                'first_name' => (string) ($lead['first_name'] ?? ''),
                'last_name' => (string) ($lead['last_name'] ?? ''),
                'email' => (string) ($lead['email'] ?? ''),
                'phone' => (string) ($lead['phone'] ?? ''),
                'intent' => (string) ($lead['intent'] ?? ''),
                'wants_rdv' => !empty($lead['wants_rdv']) ? 1 : 0,
                'page_url' => (string) ($lead['page_url'] ?? ''),
                'transcript' => (string) ($lead['transcript'] ?? ''),
                'session_id' => self::sanitize_session_id((string) ($lead['session_id'] ?? '')),
                'callback_status' => (string) ($lead['callback_status'] ?? ''),
                'owner_user_id' => self::normalize_owner_user_id((int) ($lead['owner_user_id'] ?? 0)),
            );
        }
        return $out;
    }

    public static function lead_owner_user_id($lead) {
        return self::normalize_owner_user_id((int) ($lead['owner_user_id'] ?? 0));
    }

    public static function lead_visible_for_owner($lead, $owner_user_id) {
        return self::lead_owner_user_id($lead) === self::normalize_owner_user_id($owner_user_id);
    }

    public static function is_rdv_lead($lead) {
        if (!is_array($lead)) {
            return false;
        }
        if (!empty($lead['wants_rdv'])) {
            return true;
        }
        $intent = strtolower(trim((string) ($lead['intent'] ?? '')));
        if ($intent !== '' && (strpos($intent, 'rdv') !== false || strpos($intent, 'rendez') !== false || strpos($intent, 'appel') !== false)) {
            return true;
        }
        $transcript = strtolower((string) ($lead['transcript'] ?? ''));
        if ($transcript !== '' && (strpos($transcript, 'rdv') !== false || strpos($transcript, 'rendez-vous') !== false || strpos($transcript, 'téléphonique') !== false || strpos($transcript, 'telephonique') !== false)) {
            return true;
        }
        return false;
    }

    public static function process_lead_actions() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $action = sanitize_text_field((string) ($_GET['azsa_action'] ?? ''));
        $ref = sanitize_text_field((string) ($_GET['ref'] ?? ''));
        $nonce = sanitize_text_field((string) ($_GET['_wpnonce'] ?? ''));
        if ($action === '' || $ref === '') {
            return;
        }
        if (!in_array($action, array('mark_callback_open', 'mark_callback_done', 'clear_callback', 'delete_lead'), true)) {
            return;
        }
        if (!wp_verify_nonce($nonce, 'azsa_lead_action_' . $ref)) {
            return;
        }
        $leads = self::get_leads();
        $changed = false;
        if ($action === 'delete_lead') {
            $before = count($leads);
            $leads = array_values(array_filter($leads, function ($lead) use ($ref) {
                return (string) ($lead['ref'] ?? '') !== $ref;
            }));
            $changed = (count($leads) !== $before);
            if ($changed) {
                self::log_event(self::normalize_owner_user_id(get_current_user_id()), 'admin', 'lead_deleted', array(
                    'ref' => $ref,
                ), '', $ref);
            }
        }
        if ($action !== 'delete_lead') {
            foreach ($leads as &$lead) {
                if ((string) ($lead['ref'] ?? '') !== $ref) {
                    continue;
                }
                if ($action === 'mark_callback_open') {
                    $lead['callback_status'] = 'open';
                    $changed = true;
                    self::log_event(self::normalize_owner_user_id(get_current_user_id()), 'admin', 'callback_open', array(
                        'ref' => $ref,
                    ), '', $ref);
                } elseif ($action === 'mark_callback_done') {
                    $lead['callback_status'] = 'done';
                    $changed = true;
                    self::log_event(self::normalize_owner_user_id(get_current_user_id()), 'admin', 'callback_done', array(
                        'ref' => $ref,
                    ), '', $ref);
                } elseif ($action === 'clear_callback') {
                    unset($lead['callback_status']);
                    $changed = true;
                    self::log_event(self::normalize_owner_user_id(get_current_user_id()), 'admin', 'callback_cleared', array(
                        'ref' => $ref,
                    ), '', $ref);
                }
                break;
            }
            unset($lead);
        }
        if ($changed) {
            self::update_leads($leads);
        }
    }

    public static function current_lead_filters() {
        return array(
            'q' => sanitize_text_field((string) ($_GET['q'] ?? '')),
            'email' => sanitize_text_field((string) ($_GET['email'] ?? '')),
            'phone' => sanitize_text_field((string) ($_GET['phone'] ?? '')),
            'date_from' => sanitize_text_field((string) ($_GET['date_from'] ?? '')),
            'date_to' => sanitize_text_field((string) ($_GET['date_to'] ?? '')),
            'sort' => sanitize_text_field((string) ($_GET['sort'] ?? 'date_desc')),
        );
    }

    public static function filter_leads($rows, $filters) {
        $q = strtolower((string) ($filters['q'] ?? ''));
        $email = strtolower((string) ($filters['email'] ?? ''));
        $phone = strtolower((string) ($filters['phone'] ?? ''));
        $date_from = (string) ($filters['date_from'] ?? '');
        $date_to = (string) ($filters['date_to'] ?? '');

        $filtered = array_values(array_filter((array) $rows, function ($lead) use ($q, $email, $phone, $date_from, $date_to) {
            $created = (string) ($lead['created_at'] ?? '');
            $stack = strtolower(implode(' ', array(
                (string) ($lead['ref'] ?? ''),
                (string) ($lead['first_name'] ?? ''),
                (string) ($lead['last_name'] ?? ''),
                (string) ($lead['email'] ?? ''),
                (string) ($lead['phone'] ?? ''),
                (string) ($lead['intent'] ?? ''),
            )));
            if ($q !== '' && strpos($stack, $q) === false) {
                return false;
            }
            if ($email !== '' && strpos(strtolower((string) ($lead['email'] ?? '')), $email) === false) {
                return false;
            }
            if ($phone !== '' && strpos(strtolower((string) ($lead['phone'] ?? '')), $phone) === false) {
                return false;
            }
            if ($date_from !== '' && $created !== '' && strtotime($created) < strtotime($date_from . ' 00:00:00')) {
                return false;
            }
            if ($date_to !== '' && $created !== '' && strtotime($created) > strtotime($date_to . ' 23:59:59')) {
                return false;
            }
            return true;
        }));

        $sort = (string) ($filters['sort'] ?? 'date_desc');
        usort($filtered, function ($a, $b) use ($sort) {
            $ta = strtotime((string) ($a['created_at'] ?? '')) ?: 0;
            $tb = strtotime((string) ($b['created_at'] ?? '')) ?: 0;
            if ($sort === 'date_asc') {
                return $ta <=> $tb;
            }
            return $tb <=> $ta;
        });

        return $filtered;
    }

    public static function render_leads_filters_form($page_slug, $filters) {
        echo '<form method="get" style="margin: 0 0 12px;display:flex;gap:8px;flex-wrap:wrap;align-items:end;">';
        echo '<input type="hidden" name="page" value="' . esc_attr($page_slug) . '" />';
        echo '<div><label for="azsa_filter_q">Recherche</label><br/><input id="azsa_filter_q" type="text" name="q" value="' . esc_attr($filters['q'] ?? '') . '" placeholder="nom, email, ref..." /></div>';
        echo '<div><label for="azsa_filter_email">Email</label><br/><input id="azsa_filter_email" type="text" name="email" value="' . esc_attr($filters['email'] ?? '') . '" /></div>';
        echo '<div><label for="azsa_filter_phone">Téléphone</label><br/><input id="azsa_filter_phone" type="text" name="phone" value="' . esc_attr($filters['phone'] ?? '') . '" /></div>';
        echo '<div><label for="azsa_filter_date_from">Du</label><br/><input id="azsa_filter_date_from" type="date" name="date_from" value="' . esc_attr($filters['date_from'] ?? '') . '" /></div>';
        echo '<div><label for="azsa_filter_date_to">Au</label><br/><input id="azsa_filter_date_to" type="date" name="date_to" value="' . esc_attr($filters['date_to'] ?? '') . '" /></div>';
        echo '<div><label for="azsa_filter_sort">Tri</label><br/><select id="azsa_filter_sort" name="sort">'
            . '<option value="date_desc"' . selected(($filters['sort'] ?? 'date_desc'), 'date_desc', false) . '>Plus récent</option>'
            . '<option value="date_asc"' . selected(($filters['sort'] ?? 'date_desc'), 'date_asc', false) . '>Plus ancien</option>'
            . '</select></div>';
        echo '<div><button class="button button-primary" type="submit">Filtrer</button></div>';
        echo '<div><a class="button" href="' . esc_url(admin_url('admin.php?page=' . $page_slug)) . '">Réinitialiser</a></div>';
        echo '</form>';
    }

    public static function render_leads_table($rows) {
        if (empty($rows)) {
            echo '<p>Aucun enregistrement pour le moment.</p>';
            return;
        }
        echo '<style>
.azsa-actions-stack{display:flex;flex-direction:column;align-items:flex-start;gap:6px;white-space:normal;min-width:170px}
.azsa-actions-stack .button{margin:0!important}
.azsa-actions-stack details{display:block!important}
.azsa-actions-stack details summary.button{display:inline-block}
.azsa-actions-stack .azsa-transcript{position:relative}
.azsa-actions-stack .azsa-transcript-panel{
    position:absolute;
    left:0;
    bottom:calc(100% + 6px);
    z-index:50;
    width:min(480px,70vw);
    max-height:220px;
    overflow:auto;
    white-space:pre-wrap;
    border:1px solid #dcdcde;
    padding:8px;
    background:#fff;
    box-shadow:0 10px 24px rgba(0,0,0,.08)
}
</style>';
        echo '<table class="widefat striped"><thead><tr>';
        echo '<th>Réf</th><th>Date</th><th>Prénom</th><th>Nom</th><th>Email</th><th>Téléphone</th><th>Demande</th><th>Rappel</th><th>Actions rapides</th>';
        echo '</tr></thead><tbody>';
        foreach ($rows as $lead) {
            $ref = esc_html((string) ($lead['ref'] ?? ''));
            $date = esc_html(self::format_date_short((string) ($lead['created_at'] ?? '')));
            $first = esc_html((string) ($lead['first_name'] ?? ''));
            $last = esc_html((string) ($lead['last_name'] ?? ''));
            $email = esc_html((string) ($lead['email'] ?? ''));
            $phone = esc_html((string) ($lead['phone'] ?? ''));
            $email_raw = (string) ($lead['email'] ?? '');
            $phone_raw = (string) ($lead['phone'] ?? '');
            $transcript = esc_html((string) ($lead['transcript'] ?? ''));
            $demand = !empty($lead['wants_rdv']) ? 'RDV téléphonique' : 'Prospect';
            $callback_status = (string) ($lead['callback_status'] ?? '');
            $ref_raw = (string) ($lead['ref'] ?? '');
            $nonce = wp_create_nonce('azsa_lead_action_' . $ref_raw);
            $open_url = add_query_arg(array(
                'page' => sanitize_text_field((string) ($_GET['page'] ?? 'azsa-prospects')),
                'azsa_action' => 'mark_callback_open',
                'ref' => $ref_raw,
                '_wpnonce' => $nonce,
            ), admin_url('admin.php'));
            $done_url = add_query_arg(array(
                'page' => sanitize_text_field((string) ($_GET['page'] ?? 'azsa-prospects')),
                'azsa_action' => 'mark_callback_done',
                'ref' => $ref_raw,
                '_wpnonce' => $nonce,
            ), admin_url('admin.php'));
            $clear_url = add_query_arg(array(
                'page' => sanitize_text_field((string) ($_GET['page'] ?? 'azsa-prospects')),
                'azsa_action' => 'clear_callback',
                'ref' => $ref_raw,
                '_wpnonce' => $nonce,
            ), admin_url('admin.php'));
            $delete_url = add_query_arg(array(
                'page' => sanitize_text_field((string) ($_GET['page'] ?? 'azsa-prospects')),
                'azsa_action' => 'delete_lead',
                'ref' => $ref_raw,
                '_wpnonce' => $nonce,
            ), admin_url('admin.php'));
            $view_url = add_query_arg(array(
                'page' => 'azsa-contact',
                'ref' => $ref_raw,
            ), admin_url('admin.php'));
            echo '<tr>';
            echo '<td>' . $ref . '</td><td>' . $date . '</td><td>' . $first . '</td><td>' . $last . '</td><td>' . $email . '</td><td>' . $phone . '</td><td>' . esc_html($demand) . '</td>';
            echo '<td>';
            if ($callback_status === 'done') {
                echo '<span style="display:inline-block;padding:3px 8px;border-radius:999px;background:#e7f7ed;color:#0a7f33;font-weight:600;">Fait</span>';
            } elseif ($callback_status === 'open') {
                echo '<span style="display:inline-block;padding:3px 8px;border-radius:999px;background:#fff4e5;color:#a05a00;font-weight:600;">À faire</span>';
            } else {
                echo '<span style="display:inline-block;padding:3px 8px;border-radius:999px;background:#f0f0f1;color:#50575e;">-</span>';
            }
            echo '</td>';
            echo '<td><div class="azsa-actions-stack">';
            if ($callback_status !== 'open') {
                echo '<a class="button button-small" href="' . esc_url($open_url) . '">Créer tâche rappel</a>';
            }
            if ($callback_status === 'open') {
                echo '<a class="button button-small" href="' . esc_url($done_url) . '">Rappel fait</a>';
            }
            if ($callback_status !== '') {
                echo '<a class="button button-small" href="' . esc_url($clear_url) . '">Retirer</a>';
            }
            if ($email_raw !== '') {
                echo '<a class="button button-small" href="mailto:' . esc_attr($email_raw) . '">Email</a>';
                echo '<button type="button" class="button button-small azsa-copy-btn" data-label="Copier email" data-copy="' . esc_attr($email_raw) . '">Copier email</button>';
            }
            if ($phone_raw !== '') {
                echo '<a class="button button-small" href="tel:' . esc_attr($phone_raw) . '">Appeler</a>';
                echo '<button type="button" class="button button-small azsa-copy-btn" data-label="Copier tél" data-copy="' . esc_attr($phone_raw) . '">Copier tél</button>';
            }
            if ($transcript !== '') {
                echo '<details class="azsa-transcript"><summary class="button button-small" style="cursor:pointer;">Transcript</summary><div class="azsa-transcript-panel">' . $transcript . '</div></details>';
            }
            echo '<a class="button button-small button-primary" href="' . esc_url($view_url) . '">Voir la fiche</a>';
            echo '<a class="button button-small" style="border-color:#b32d2e;color:#b32d2e;" href="' . esc_url($delete_url) . '" onclick="return confirm(\'Supprimer définitivement cet enregistrement ?\');">Supprimer</a>';
            echo '</div></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        echo "<script>
document.querySelectorAll('.azsa-copy-btn').forEach(function(btn){
  btn.addEventListener('click', function(){
    var t = btn.getAttribute('data-copy') || '';
    var label = btn.getAttribute('data-label') || 'Copier';
    if (!t) return;
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(t).then(function(){ btn.textContent='Copié'; setTimeout(function(){ btn.textContent = label; }, 1200); });
    } else {
      var ta = document.createElement('textarea'); ta.value=t; document.body.appendChild(ta); ta.select(); document.execCommand('copy'); document.body.removeChild(ta);
      btn.textContent='Copié'; setTimeout(function(){ btn.textContent = label; }, 1200);
    }
  });
});
</script>";
    }

    public static function format_date_short($value) {
        $raw = (string) $value;
        if ($raw === '') {
            return '';
        }
        $ts = strtotime($raw);
        if (!$ts) {
            return $raw;
        }
        return gmdate('d/m/y', $ts);
    }

    public static function get_contact_by_ref($owner_user_id, $ref) {
        $owner_user_id = self::normalize_owner_user_id($owner_user_id);
        $ref = sanitize_text_field((string) $ref);
        if ($ref === '') {
            return array();
        }
        foreach (self::get_leads($owner_user_id) as $lead) {
            if ((string) ($lead['ref'] ?? '') === $ref) {
                return $lead;
            }
        }
        return array();
    }

    public static function event_data_to_text($event) {
        $data = json_decode((string) ($event['data'] ?? '{}'), true);
        if (!is_array($data)) {
            return '';
        }
        $parts = array();
        foreach ($data as $k => $v) {
            if (is_array($v) || is_object($v)) {
                continue;
            }
            $val = trim((string) $v);
            if ($val === '') {
                continue;
            }
            $parts[] = $k . ': ' . $val;
        }
        return implode(' | ', array_slice($parts, 0, 6));
    }

    public static function get_contact_timeline($owner_user_id, $lead) {
        $owner_user_id = self::normalize_owner_user_id($owner_user_id);
        $ref = sanitize_text_field((string) ($lead['ref'] ?? ''));
        $session_id = self::sanitize_session_id((string) ($lead['session_id'] ?? ''));
        $events = array_values(array_filter(self::get_events_raw(), function ($ev) use ($owner_user_id, $ref, $session_id) {
            if (self::normalize_owner_user_id((int) ($ev['owner_user_id'] ?? 0)) !== $owner_user_id) {
                return false;
            }
            $ev_ref = sanitize_text_field((string) ($ev['contact_ref'] ?? ''));
            $ev_session = self::sanitize_session_id((string) ($ev['session_id'] ?? ''));
            if ($ref !== '' && $ev_ref === $ref) {
                return true;
            }
            if ($session_id !== '' && $ev_session === $session_id) {
                return true;
            }
            return false;
        }));
        usort($events, function ($a, $b) {
            $ta = strtotime((string) ($a['created_at'] ?? '')) ?: 0;
            $tb = strtotime((string) ($b['created_at'] ?? '')) ?: 0;
            return $ta <=> $tb;
        });
        return $events;
    }

    public static function render_prospects_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        self::process_lead_actions();
        $all_leads = self::get_leads();
        $leads = array_values($all_leads);
        $filters = self::current_lead_filters();
        $leads = self::filter_leads($leads, $filters);
        echo '<div class="wrap"><h1>Contacts (Leads)</h1>';
        echo '<p><strong>Total base:</strong> ' . (int) count($all_leads) . '</p>';
        echo '<p><strong>Total affiché:</strong> ' . (int) count($leads) . '</p>';
        self::render_leads_filters_form('azsa-prospects', $filters);
        self::render_leads_table($leads);
        echo '</div>';
    }

    public static function render_rdv_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        self::process_lead_actions();
        $all_leads = self::get_leads();
        $leads = array_values(array_filter($all_leads, function ($l) {
            return self::is_rdv_lead($l);
        }));
        $filters = self::current_lead_filters();
        $leads = self::filter_leads($leads, $filters);
        echo '<div class="wrap"><h1>Liste des RDV</h1>';
        echo '<p><strong>Total base:</strong> ' . (int) count($all_leads) . '</p>';
        echo '<p><strong>Total affiché:</strong> ' . (int) count($leads) . '</p>';
        self::render_leads_filters_form('azsa-rdv', $filters);
        self::render_leads_table($leads);
        echo '</div>';
    }

    public static function render_events_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $owner_user_id = self::normalize_owner_user_id(get_current_user_id());
        $actor = sanitize_key((string) ($_GET['actor'] ?? ''));
        $event_type = sanitize_key((string) ($_GET['event_type'] ?? ''));
        $session_id = self::sanitize_session_id((string) ($_GET['session_id'] ?? ''));
        $contact_ref = sanitize_text_field((string) ($_GET['contact_ref'] ?? ''));

        $events = array_values(array_filter(self::get_events_raw(), function ($ev) use ($owner_user_id, $actor, $event_type, $session_id, $contact_ref) {
            if (self::normalize_owner_user_id((int) ($ev['owner_user_id'] ?? 0)) !== $owner_user_id) {
                return false;
            }
            if ($actor !== '' && sanitize_key((string) ($ev['actor'] ?? '')) !== $actor) {
                return false;
            }
            if ($event_type !== '' && sanitize_key((string) ($ev['event_type'] ?? '')) !== $event_type) {
                return false;
            }
            if ($session_id !== '' && self::sanitize_session_id((string) ($ev['session_id'] ?? '')) !== $session_id) {
                return false;
            }
            if ($contact_ref !== '' && sanitize_text_field((string) ($ev['contact_ref'] ?? '')) !== $contact_ref) {
                return false;
            }
            return true;
        }));
        $events = array_slice($events, 0, 300);

        echo '<div class="wrap"><h1>Événements</h1>';
        echo '<p>Liste unique des événements (visiteurs, assistants, admins, RDV, actions CRM).</p>';
        echo '<form method="get" style="margin:0 0 12px;display:flex;gap:8px;flex-wrap:wrap;align-items:end;">';
        echo '<input type="hidden" name="page" value="azsa-events"/>';
        echo '<div><label for="azsa_ev_actor">Acteur</label><br/><input id="azsa_ev_actor" type="text" name="actor" value="' . esc_attr($actor) . '" placeholder="visitor / assistant / admin"/></div>';
        echo '<div><label for="azsa_ev_type">Type</label><br/><input id="azsa_ev_type" type="text" name="event_type" value="' . esc_attr($event_type) . '" placeholder="visitor_chat_user_message"/></div>';
        echo '<div><label for="azsa_ev_sess">Session</label><br/><input id="azsa_ev_sess" type="text" name="session_id" value="' . esc_attr($session_id) . '" placeholder="visitor_..."/></div>';
        echo '<div><label for="azsa_ev_ref">Ref contact</label><br/><input id="azsa_ev_ref" type="text" name="contact_ref" value="' . esc_attr($contact_ref) . '" placeholder="LEAD-..."/></div>';
        echo '<div><button class="button button-primary" type="submit">Filtrer</button></div>';
        echo '<div><a class="button" href="' . esc_url(admin_url('admin.php?page=azsa-events')) . '">Réinitialiser</a></div>';
        echo '</form>';

        if (empty($events)) {
            echo '<p>Aucun événement trouvé.</p></div>';
            return;
        }

        echo '<table class="widefat striped"><thead><tr>';
        echo '<th>Date</th><th>Acteur</th><th>Type</th><th>Session</th><th>Contact</th><th>Données</th>';
        echo '</tr></thead><tbody>';
        foreach ($events as $ev) {
            $data_decoded = json_decode((string) ($ev['data'] ?? '{}'), true);
            if (!is_array($data_decoded)) {
                $data_decoded = array('raw' => (string) ($ev['data'] ?? ''));
            }
            $json = wp_json_encode($data_decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            if (!is_string($json)) {
                $json = '{}';
            }
            $ref_raw = sanitize_text_field((string) ($ev['contact_ref'] ?? ''));
            $view_contact = $ref_raw !== '' ? add_query_arg(array('page' => 'azsa-contact', 'ref' => $ref_raw), admin_url('admin.php')) : '';
            echo '<tr>';
            echo '<td>' . esc_html(self::format_date_short((string) ($ev['created_at'] ?? ''))) . '</td>';
            echo '<td>' . esc_html((string) ($ev['actor'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) ($ev['event_type'] ?? '')) . '</td>';
            echo '<td style="max-width:220px;word-break:break-all;">' . esc_html((string) ($ev['session_id'] ?? '')) . '</td>';
            if ($view_contact !== '') {
                echo '<td><a class="button button-small" href="' . esc_url($view_contact) . '">Voir la fiche</a></td>';
            } else {
                echo '<td>-</td>';
            }
            echo '<td style="max-width:420px;"><pre style="white-space:pre-wrap;margin:0;">' . esc_html($json) . '</pre></td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
    }

    public static function render_contact_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        self::process_lead_actions();
        $owner_user_id = self::normalize_owner_user_id(get_current_user_id());
        $ref = sanitize_text_field((string) ($_GET['ref'] ?? ''));
        $lead = self::get_contact_by_ref($owner_user_id, $ref);

        echo '<div class="wrap"><h1>Fiche contact</h1>';
        if (empty($lead)) {
            echo '<p>Contact introuvable.</p>';
            echo '<p><a class="button" href="' . esc_url(admin_url('admin.php?page=azsa-prospects')) . '">Retour contacts</a></p></div>';
            return;
        }

        $ref_raw = (string) ($lead['ref'] ?? '');
        $nonce = wp_create_nonce('azsa_lead_action_' . $ref_raw);
        $open_url = add_query_arg(array('page' => 'azsa-contact', 'ref' => $ref_raw, 'azsa_action' => 'mark_callback_open', '_wpnonce' => $nonce), admin_url('admin.php'));
        $done_url = add_query_arg(array('page' => 'azsa-contact', 'ref' => $ref_raw, 'azsa_action' => 'mark_callback_done', '_wpnonce' => $nonce), admin_url('admin.php'));
        $clear_url = add_query_arg(array('page' => 'azsa-contact', 'ref' => $ref_raw, 'azsa_action' => 'clear_callback', '_wpnonce' => $nonce), admin_url('admin.php'));
        $delete_url = add_query_arg(array('page' => 'azsa-prospects', 'azsa_action' => 'delete_lead', 'ref' => $ref_raw, '_wpnonce' => $nonce), admin_url('admin.php'));
        $email_raw = (string) ($lead['email'] ?? '');
        $phone_raw = (string) ($lead['phone'] ?? '');
        $callback_status = (string) ($lead['callback_status'] ?? '');

        echo '<div style="background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:14px;margin:0 0 12px;max-width:920px;">';
        echo '<p><strong>Réf:</strong> ' . esc_html($ref_raw) . '</p>';
        echo '<p><strong>Nom:</strong> ' . esc_html(trim((string) ($lead['first_name'] ?? '') . ' ' . (string) ($lead['last_name'] ?? ''))) . '</p>';
        echo '<p><strong>Email:</strong> ' . esc_html($email_raw) . ' | <strong>Téléphone:</strong> ' . esc_html($phone_raw) . '</p>';
        echo '<p><strong>Statut:</strong> ' . (!empty($lead['wants_rdv']) ? 'RDV téléphonique' : 'Prospect') . '</p>';
        echo '</div>';

        echo '<div class="azsa-actions-stack" style="margin:0 0 12px;">';
        if ($callback_status !== 'open') {
            echo '<a class="button button-small" href="' . esc_url($open_url) . '">Créer tâche rappel</a>';
        }
        if ($callback_status === 'open') {
            echo '<a class="button button-small" href="' . esc_url($done_url) . '">Rappel fait</a>';
        }
        if ($callback_status !== '') {
            echo '<a class="button button-small" href="' . esc_url($clear_url) . '">Retirer</a>';
        }
        if ($email_raw !== '') {
            echo '<a class="button button-small" href="mailto:' . esc_attr($email_raw) . '">Email</a>';
            echo '<button type="button" class="button button-small azsa-copy-btn" data-label="Copier email" data-copy="' . esc_attr($email_raw) . '">Copier email</button>';
        }
        if ($phone_raw !== '') {
            echo '<a class="button button-small" href="tel:' . esc_attr($phone_raw) . '">Appeler</a>';
            echo '<button type="button" class="button button-small azsa-copy-btn" data-label="Copier tél" data-copy="' . esc_attr($phone_raw) . '">Copier tél</button>';
        }
        echo '<a class="button button-small" style="border-color:#b32d2e;color:#b32d2e;" href="' . esc_url($delete_url) . '" onclick="return confirm(\'Supprimer définitivement ce contact ?\');">Supprimer</a>';
        echo '<a class="button button-small" href="' . esc_url(admin_url('admin.php?page=azsa-prospects')) . '">Retour contacts</a>';
        echo '</div>';

        $timeline = self::get_contact_timeline($owner_user_id, $lead);
        echo '<h2 style="margin:12px 0 8px;">Historique chronologique</h2>';
        if (empty($timeline)) {
            echo '<p>Aucun événement pour ce contact.</p></div>';
            return;
        }
        echo '<table class="widefat striped"><thead><tr><th>Date</th><th>Acteur</th><th>Type</th><th>Détail</th></tr></thead><tbody>';
        foreach ($timeline as $ev) {
            echo '<tr>';
            echo '<td>' . esc_html(self::format_date_short((string) ($ev['created_at'] ?? ''))) . '</td>';
            echo '<td>' . esc_html((string) ($ev['actor'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) ($ev['event_type'] ?? '')) . '</td>';
            echo '<td>' . esc_html(self::event_data_to_text($ev)) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        echo "<script>
document.querySelectorAll('.azsa-copy-btn').forEach(function(btn){
  btn.addEventListener('click', function(){
    var t = btn.getAttribute('data-copy') || '';
    var label = btn.getAttribute('data-label') || 'Copier';
    if (!t) return;
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(t).then(function(){ btn.textContent='Copié'; setTimeout(function(){ btn.textContent = label; }, 1200); });
    }
  });
});
</script>";
        echo '</div>';
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
        register_rest_route('azsa/v1', '/calendly/resolve', array(
            'methods' => 'POST',
            'permission_callback' => '__return_true',
            'callback' => array(__CLASS__, 'rest_calendly_resolve'),
        ));
        register_rest_route('azsa/v1', '/calendly/poll', array(
            'methods' => 'POST',
            'permission_callback' => '__return_true',
            'callback' => array(__CLASS__, 'rest_calendly_poll'),
        ));
        register_rest_route('azsa/v1', '/admin-assistant/chat', array(
            'methods' => 'POST',
            'permission_callback' => '__return_true',
            'callback' => array(__CLASS__, 'rest_admin_assistant_chat'),
        ));
    }

    public static function rest_calendly_resolve(WP_REST_Request $request) {
        $settings = self::get_settings();
        $token = trim((string) ($settings['calendly_pat'] ?? ''));
        if ($token === '') {
            return new WP_REST_Response(array('ok' => false, 'message' => 'Calendly PAT manquant.'), 400);
        }

        $invitee_uri = esc_url_raw((string) $request->get_param('invitee_uri'));
        $event_uri = esc_url_raw((string) $request->get_param('event_uri'));
        if ($invitee_uri === '' && $event_uri === '') {
            return new WP_REST_Response(array('ok' => false, 'message' => 'Données Calendly manquantes.'), 400);
        }

        $headers = array(
            'Authorization' => 'Bearer ' . $token,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'Calendly-Version' => '2020-08-01',
        );

        $invitee = array();
        if ($invitee_uri !== '') {
            $res = wp_remote_get($invitee_uri, array('timeout' => 20, 'headers' => $headers));
            if (!is_wp_error($res) && (int) wp_remote_retrieve_response_code($res) < 300) {
                $body = json_decode((string) wp_remote_retrieve_body($res), true);
                if (is_array($body) && !empty($body['resource']) && is_array($body['resource'])) {
                    $invitee = $body['resource'];
                }
            }
        }

        $event = array();
        if ($event_uri !== '') {
            $res2 = wp_remote_get($event_uri, array('timeout' => 20, 'headers' => $headers));
            if (!is_wp_error($res2) && (int) wp_remote_retrieve_response_code($res2) < 300) {
                $body2 = json_decode((string) wp_remote_retrieve_body($res2), true);
                if (is_array($body2) && !empty($body2['resource']) && is_array($body2['resource'])) {
                    $event = $body2['resource'];
                }
            }
        }

        $name = trim((string) ($invitee['name'] ?? ''));
        $first_name = '';
        $last_name = '';
        if ($name !== '') {
            $parts = preg_split('/\s+/', $name);
            if (!empty($parts)) {
                $first_name = array_shift($parts);
                $last_name = implode(' ', $parts);
            }
        }

        return new WP_REST_Response(array(
            'ok' => true,
            'first_name' => $first_name,
            'last_name' => $last_name,
            'name' => $name,
            'email' => (string) ($invitee['email'] ?? ''),
            'phone' => (string) ($invitee['text_reminder_number'] ?? ''),
            'event_start' => (string) ($event['start_time'] ?? ''),
            'event_end' => (string) ($event['end_time'] ?? ''),
            'event_name' => (string) ($event['name'] ?? ''),
        ), 200);
    }

    public static function calendly_api_get($url, $token, $timeout = 20) {
        $res = wp_remote_get($url, array(
            'timeout' => $timeout,
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'Calendly-Version' => '2020-08-01',
            ),
        ));
        if (is_wp_error($res) || (int) wp_remote_retrieve_response_code($res) >= 300) {
            return array();
        }
        $body = json_decode((string) wp_remote_retrieve_body($res), true);
        return is_array($body) ? $body : array();
    }

    public static function rest_calendly_poll(WP_REST_Request $request) {
        $settings = self::get_settings();
        $token = trim((string) ($settings['calendly_pat'] ?? ''));
        $session_id = sanitize_text_field((string) $request->get_param('session_id'));
        if ($token === '' || $session_id === '') {
            return new WP_REST_Response(array('ok' => false, 'found' => false), 200);
        }

        $me = self::calendly_api_get('https://api.calendly.com/users/me', $token, 15);
        $user_uri = (string) (($me['resource']['uri'] ?? ''));
        if ($user_uri === '') {
            return new WP_REST_Response(array('ok' => false, 'found' => false), 200);
        }

        $events_url = 'https://api.calendly.com/scheduled_events?user=' . rawurlencode($user_uri) . '&status=active&sort=start_time:desc&count=10';
        $events = self::calendly_api_get($events_url, $token, 15);
        $collection = isset($events['collection']) && is_array($events['collection']) ? $events['collection'] : array();
        if (empty($collection)) {
            return new WP_REST_Response(array('ok' => true, 'found' => false), 200);
        }

        foreach ($collection as $ev) {
            $ev_uri = (string) ($ev['uri'] ?? '');
            if ($ev_uri === '') {
                continue;
            }
            $inv_url = 'https://api.calendly.com/scheduled_events/' . rawurlencode(basename($ev_uri)) . '/invitees';
            $inv = self::calendly_api_get($inv_url, $token, 15);
            $invites = isset($inv['collection']) && is_array($inv['collection']) ? $inv['collection'] : array();
            foreach ($invites as $iv) {
                $tracking = isset($iv['tracking']) && is_array($iv['tracking']) ? $iv['tracking'] : array();
                $utm_content = (string) ($tracking['utm_content'] ?? '');
                if ($utm_content !== $session_id) {
                    continue;
                }
                $name = trim((string) ($iv['name'] ?? ''));
                $parts = $name !== '' ? preg_split('/\s+/', $name) : array();
                $first = '';
                $last = '';
                if (!empty($parts)) {
                    $first = array_shift($parts);
                    $last = implode(' ', $parts);
                }
                return new WP_REST_Response(array(
                    'ok' => true,
                    'found' => true,
                    'first_name' => $first,
                    'last_name' => $last,
                    'name' => $name,
                    'email' => (string) ($iv['email'] ?? ''),
                    'phone' => (string) ($iv['text_reminder_number'] ?? ''),
                    'event_start' => (string) ($ev['start_time'] ?? ''),
                    'event_end' => (string) ($ev['end_time'] ?? ''),
                    'event_name' => (string) ($ev['name'] ?? ''),
                ), 200);
            }
        }

        return new WP_REST_Response(array('ok' => true, 'found' => false), 200);
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

    public static function is_auto_updates_enabled() {
        $plugin_file = plugin_basename(__FILE__);
        $list = get_site_option('auto_update_plugins', array());
        if (!is_array($list)) {
            $list = array();
        }
        return in_array($plugin_file, $list, true);
    }

    public static function set_auto_updates_enabled($enabled) {
        $plugin_file = plugin_basename(__FILE__);
        $list = get_site_option('auto_update_plugins', array());
        if (!is_array($list)) {
            $list = array();
        }
        if ($enabled) {
            if (!in_array($plugin_file, $list, true)) {
                $list[] = $plugin_file;
            }
        } else {
            $list = array_values(array_filter($list, function ($p) use ($plugin_file) {
                return (string) $p !== (string) $plugin_file;
            }));
        }
        update_site_option('auto_update_plugins', $list);
    }

    public static function plugin_auto_update_setting_html($html, $plugin_file, $plugin_data) {
        $self_plugin = plugin_basename(__FILE__);
        if ((string) $plugin_file !== (string) $self_plugin) {
            return $html;
        }
        $mode = self::is_auto_updates_enabled() ? 'off' : 'on';
        $label = ($mode === 'on') ? 'Activer auto-update' : 'Désactiver auto-update';
        $url = wp_nonce_url(
            add_query_arg(
                array(
                    'action' => 'azsa_toggle_auto_update',
                    'mode' => $mode,
                ),
                admin_url('plugins.php')
            ),
            'azsa_toggle_auto_update'
        );
        return '<a href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
    }

    public static function handle_plugin_row_auto_update_toggle() {
        if (!is_admin() || !current_user_can('manage_options')) {
            return;
        }
        if (empty($_GET['action']) || (string) $_GET['action'] !== 'azsa_toggle_auto_update') {
            return;
        }
        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce((string) $_GET['_wpnonce'], 'azsa_toggle_auto_update')) {
            return;
        }
        $mode = sanitize_text_field((string) ($_GET['mode'] ?? ''));
        if ($mode === 'on') {
            self::set_auto_updates_enabled(true);
        } elseif ($mode === 'off') {
            self::set_auto_updates_enabled(false);
        }
        delete_site_transient('update_plugins');
        wp_safe_redirect(admin_url('plugins.php'));
        exit;
    }

    public static function maybe_render_admin_assistant_page() {
        if (is_admin()) {
            return;
        }
        if ((string) ($_GET['azsa_admin_assistant'] ?? '') !== '1') {
            return;
        }
        $owner_user_id = (int) ($_GET['owner'] ?? 0);
        $token = sanitize_text_field((string) ($_GET['token'] ?? ''));
        if (!self::validate_admin_assistant_token($owner_user_id, $token)) {
            status_header(403);
            wp_die('Accès refusé.');
        }
        $owner_user_id = self::normalize_owner_user_id($owner_user_id);
        $settings = self::get_settings();
        $cfg = array(
            'owner_id' => $owner_user_id,
            'token' => $token,
            'endpoint' => esc_url_raw(rest_url('azsa/v1/admin-assistant/chat')),
            'tts_url' => esc_url_raw(rest_url('azsa/v1/tts')),
            'site_name' => get_bloginfo('name'),
            'lang' => 'fr',
            'character_gif_url' => (string) ($settings['character_gif_url'] ?? ''),
            'gif_base_url' => (string) ($settings['character_gif_base_url'] ?? self::DEFAULT_GIF_BASE_URL),
        );
        status_header(200);
        nocache_headers();
        $cfg_json = wp_json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $html = <<<'HTML'
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>Assistant CRM IA</title>
<style>
body{margin:0;background:#ffffff;font-family:Raleway,Segoe UI,Arial,sans-serif;color:#123d64}
.ma-ai-stage{padding:8px 14px 12px}
.ma-ai-stage__inner{max-width:1120px;margin:0 auto}
.ma-ai-layout{width:100%;display:grid;grid-template-columns:minmax(320px,1fr) minmax(360px,1fr);gap:20px;align-items:stretch}
.ma-ai-left{position:relative;min-height:520px;height:520px;max-height:520px;overflow:visible;background:transparent!important}
.ma-ai-particles{position:absolute;inset:0;width:100%;height:100%;pointer-events:none;opacity:.9;z-index:4}
.ma-ai-character{position:absolute;inset:0;width:100%;height:100%;object-fit:contain;z-index:2;background:transparent!important}
.ma-ai-chat{position:relative;min-height:520px;height:520px;max-height:520px;border:1px solid rgba(18,61,100,.16);border-radius:18px;background:rgba(255,255,255,.84);box-shadow:0 12px 36px rgba(18,61,100,.10);padding:14px;display:flex;flex-direction:column;gap:10px}
.ma-ai-chat-head{margin:0 0 2px}
.ma-ai-chat-title{margin:0;color:#123d64;font-size:17px;font-weight:700}
.ma-ai-chat-help{margin:4px 0 0;color:rgba(18,61,100,.82);font-size:12px;line-height:1.4}
.ma-ai-thread{width:100%;flex:1 1 auto;min-height:0;overflow-y:auto;overflow-x:hidden;display:flex;flex-direction:column;gap:10px;padding:8px 2px 2px 0}
.ma-ai-msg{display:flex;width:100%}
.ma-ai-msg-assistant{justify-content:flex-start}
.ma-ai-msg-user{justify-content:flex-end}
.ma-ai-bubble{max-width:88%;padding:10px 12px;border-radius:14px;line-height:1.45;font-size:14px;white-space:pre-wrap;word-wrap:break-word}
.ma-ai-msg-assistant .ma-ai-bubble{background:#eef6ff;color:#123d64;border:1px solid rgba(18,61,100,.14);border-bottom-left-radius:6px}
.ma-ai-msg-user .ma-ai-bubble{background:#123d64;color:#fff;border:1px solid rgba(18,61,100,.24);border-bottom-right-radius:6px}
.ma-ai-suggestions{display:flex;flex-wrap:wrap;gap:8px}
.ma-ai-suggest-btn{border:1px solid rgba(18,61,100,.20);border-radius:999px;background:#f4f9ff;color:#123d64;font-size:12px;padding:7px 10px;cursor:pointer}
.ma-ai-suggest-btn:hover{background:#e6f1ff}
.ma-ai-status{font-size:12px;color:rgba(18,61,100,.78)}
.ma-ai-status.is-thinking{font-weight:700;color:#1c6ea4}
.ma-ai-input{display:flex;gap:8px;align-items:center}
.ma-ai-input input{flex:1 1 auto;min-width:0;border:1px solid rgba(18,61,100,.24);border-radius:12px;padding:10px 12px;font-size:14px;color:#123d64;background:rgba(255,255,255,.95)}
.ma-ai-input button{border:1px solid rgba(13,67,118,.25);border-radius:12px;padding:10px 12px;background:#123d64;color:#fff;font-weight:600;font-size:11px;white-space:nowrap;cursor:pointer}
#ma-ai-mode-toggle{background:#eef6ff;color:#123d64;border-color:rgba(18,61,100,.18);font-size:12px}
#ma-ai-mode-toggle:hover{background:#dfeeff}
#ma-ai-mic-toggle{min-width:46px}
.ma-ai-hidden{display:none!important}
@media (max-width:920px){.ma-ai-layout{grid-template-columns:1fr}.ma-ai-left{min-height:260px;height:260px;max-height:260px}.ma-ai-chat{min-height:62dvh;height:62dvh;max-height:62dvh}}
</style>
</head>
<body>
<div class="ma-ai-stage">
  <div class="ma-ai-stage__inner">
    <div class="ma-ai-layout">
      <div class="ma-ai-left">
        <img class="ma-ai-character" id="ma-ai-character" alt="Assistant visuel" />
        <canvas id="ma-ai-particles" class="ma-ai-particles" aria-hidden="true"></canvas>
      </div>
      <div class="ma-ai-chat">
        <div class="ma-ai-chat-head">
          <h2 class="ma-ai-chat-title">Assistant CRM IA</h2>
          <p class="ma-ai-chat-help" id="ma-ai-chat-help"></p>
        </div>
        <div id="ma-ai-thread" class="ma-ai-thread" aria-live="polite"></div>
        <div id="ma-ai-suggestions" class="ma-ai-suggestions"></div>
        <div id="ma-ai-status" class="ma-ai-status">Prêt</div>
        <form id="ma-ai-text-form" class="ma-ai-input">
          <button type="button" id="ma-ai-mode-toggle">Mode: Écrit</button>
          <button type="button" id="ma-ai-mic-toggle" class="ma-ai-hidden" aria-label="Activer le micro">🎙</button>
          <input id="ma-ai-text-input" type="text" placeholder="Ex: donne la fiche du contact Sébastien" />
          <button type="submit">Envoyer</button>
        </form>
      </div>
    </div>
  </div>
</div>
<script>window.AZSA_ADMIN=__CFG_JSON__;</script>
<script>
(function(){
var c=window.AZSA_ADMIN||{};
var thread=document.getElementById('ma-ai-thread');
var form=document.getElementById('ma-ai-text-form');
var input=document.getElementById('ma-ai-text-input');
var statusNode=document.getElementById('ma-ai-status');
var suggestions=document.getElementById('ma-ai-suggestions');
var modeBtn=document.getElementById('ma-ai-mode-toggle');
var micBtn=document.getElementById('ma-ai-mic-toggle');
var chatHelp=document.getElementById('ma-ai-chat-help');
var character=document.getElementById('ma-ai-character');
var canvas=document.getElementById('ma-ai-particles');
if(!thread||!form||!input||!statusNode||!suggestions||!modeBtn||!micBtn||!character||!canvas){return;}

chatHelp.textContent='Connecté à '+(c.site_name||'votre site')+'. Demandez une fiche contact, les dernières actions, un numéro ou ajoutez des notes.';
var gifBase=(c.gif_base_url||'').replace(/\/?$/,'/');
if(!gifBase){gifBase='https://monassistant.mapage-wp.online/wp-content/uploads/2026/03/';}
var characterGifs={
  welcome:gifBase+'2-welcome.gif',
  speaking:gifBase+'19-parler.gif',
  waiting:gifBase+'7-reflechit.gif',
  question:gifBase+'3-Lit-et-se-questionne.gif',
  success:gifBase+'4-obtient-5-etoile.gif',
  listening:gifBase+'9-regarde-avec-une-loupe.gif',
  idle:gifBase+'15-est-tranquile.gif',
  error:gifBase+'21-error.gif'
};
var defaultGif=(c.character_gif_url||'').trim()||characterGifs.idle;
character.src=defaultGif;
character.onerror=function(){if(character.src!==defaultGif){character.src=defaultGif;}};

var lastRef='',chatSessionId='',voiceMode=false,recognizer=null,listening=false,keepListening=false,currentAudio=null,processing=false;
function setMood(m){if(characterGifs[m]){character.src=characterGifs[m];}}
function setStatus(text,thinking){statusNode.textContent=text||'';statusNode.classList.toggle('is-thinking',!!thinking);}
function pushMessage(role,text){
  var row=document.createElement('div');
  row.className='ma-ai-msg ma-ai-msg-'+(role==='user'?'user':'assistant');
  var bubble=document.createElement('div');
  bubble.className='ma-ai-bubble';
  bubble.textContent=(text||'').toString();
  row.appendChild(bubble);
  thread.appendChild(row);
  thread.scrollTop=thread.scrollHeight;
}
function setSuggestions(items){
  suggestions.innerHTML='';
  (items||[]).forEach(function(label){
    var btn=document.createElement('button');
    btn.type='button';
    btn.className='ma-ai-suggest-btn';
    btn.textContent=String(label||'').trim();
    btn.addEventListener('click',function(){
      input.value=btn.textContent;
      form.dispatchEvent(new Event('submit',{cancelable:true}));
    });
    suggestions.appendChild(btn);
  });
}
function stopAudio(){
  if(currentAudio){try{currentAudio.pause();}catch(e){}currentAudio.src='';currentAudio=null;}
  if(window.speechSynthesis){try{window.speechSynthesis.cancel();}catch(e){}}
}
async function fetchTTS(text){
  if(!c.tts_url){return{mode:'browser_tts'};}
  try{
    var res=await fetch(c.tts_url,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({text:text})});
    return await res.json();
  }catch(e){return{mode:'browser_tts'};}
}
function playBase64(mime,b64){
  return new Promise(function(resolve){
    stopAudio();
    try{
      var audio=new Audio('data:'+(mime||'audio/mpeg')+';base64,'+b64);
      currentAudio=audio;
      audio.onended=function(){setMood('idle');resolve(true);};
      audio.onerror=function(){setMood('error');resolve(false);};
      setMood('speaking');
      var p=audio.play();
      if(p&&p.catch){p.catch(function(){resolve(false);});}
    }catch(e){resolve(false);}
  });
}
function speakBrowser(text){
  return new Promise(function(resolve){
    if(!window.speechSynthesis){resolve(false);return;}
    var u=new SpeechSynthesisUtterance(text||'');
    u.lang=(c.lang||'fr')==='fr'?'fr-FR':'en-US';
    u.rate=1.06;
    u.pitch=1.0;
    u.onstart=function(){setMood('speaking');};
    u.onend=function(){setMood('idle');resolve(true);};
    u.onerror=function(){setMood('error');resolve(false);};
    window.speechSynthesis.speak(u);
  });
}
async function speak(text){
  var r=await fetchTTS(text);
  if(r&&r.mode==='ai_audio'&&r.audio_b64){
    var ok=await playBase64(r.mime||'audio/mpeg',r.audio_b64);
    if(ok){return true;}
  }
  return await speakBrowser(text);
}
function setupRecognizer(){
  var SR=window.SpeechRecognition||window.webkitSpeechRecognition;
  if(!SR){return null;}
  var rr=new SR();
  rr.lang=(c.lang||'fr')==='fr'?'fr-FR':'en-US';
  rr.interimResults=false;
  rr.continuous=true;
  rr.maxAlternatives=1;
  rr.onstart=function(){listening=true;setMood('listening');micBtn.textContent='⏹';setStatus('Écoute en cours',true);};
  rr.onresult=function(ev){
    var hit=(((ev||{}).results||[])[0]||[])[0];
    var txt=hit&&hit.transcript?String(hit.transcript).trim():'';
    if(!txt){return;}
    input.value=txt;
    form.dispatchEvent(new Event('submit',{cancelable:true}));
  };
  rr.onend=function(){
    listening=false;
    if(keepListening&&voiceMode&&!processing){
      setTimeout(function(){try{rr.start();}catch(e){}},280);
    }else{
      micBtn.textContent='🎙';
      setMood('idle');
      setStatus('Prêt',false);
    }
  };
  rr.onerror=function(ev){
    setMood('error');
    listening=false;
    var code=((ev||{}).error||'').toString();
    if(code==='not-allowed'||code==='service-not-allowed'){
      keepListening=false;
      setStatus('Micro bloqué: autorisez le micro',false);
    }else if(code==='no-speech'){
      setStatus('Je n ai rien entendu',false);
    }else{
      setStatus('Micro indisponible temporairement',false);
    }
    micBtn.textContent='🎙';
  };
  return rr;
}
async function ask(msg){
  var res=await fetch(c.endpoint,{
    method:'POST',
    headers:{'Content-Type':'application/json'},
    body:JSON.stringify({owner_id:c.owner_id,token:c.token,message:msg,last_contact_ref:lastRef,session_id:chatSessionId})
  });
  return await res.json();
}
modeBtn.addEventListener('click',async function(){
  voiceMode=!voiceMode;
  modeBtn.textContent=voiceMode?'Mode: Vocal':'Mode: Écrit';
  micBtn.classList.toggle('ma-ai-hidden',!voiceMode);
  if(!voiceMode){
    keepListening=false;
    if(recognizer&&listening){try{recognizer.stop();}catch(e){}}
    stopAudio();
    setStatus('Mode écrit actif',false);
    setMood('idle');
    return;
  }
  keepListening=true;
  setTimeout(function(){ if(recognizer&&!listening){ try{recognizer.start();}catch(e){} } },120);
  var msg='Mode vocal activé. Je vous écoute.';
  if(thread.children.length>1){msg='Je réactive le mode vocal. Dites-moi comment je peux vous aider.';}
  pushMessage('assistant',msg);
  await speak(msg);
});
micBtn.addEventListener('click',function(){
  if(!voiceMode||!recognizer){return;}
  if(keepListening){
    keepListening=false;
    if(listening){try{recognizer.stop();}catch(e){}}
    micBtn.textContent='🎙';
    setMood('idle');
    setStatus('Micro arrêté',false);
    return;
  }
  keepListening=true;
  stopAudio();
  try{recognizer.start();}catch(e){}
});
form.addEventListener('submit',async function(e){
  e.preventDefault();
  var q=(input.value||'').trim();
  if(!q||processing){return;}
  processing=true;
  pushMessage('user',q);
  input.value='';
  setMood('waiting');
  setStatus('Analyse...',true);
  try{
    var d=await ask(q);
    var rep=(d&&d.reply)?d.reply:'Je n ai pas pu traiter la demande.';
    pushMessage('assistant',rep);
    if(d&&d.last_contact_ref){lastRef=d.last_contact_ref;}
    if(d&&d.session_id){chatSessionId=String(d.session_id);}
    if(d&&Array.isArray(d.suggestions)){setSuggestions(d.suggestions.slice(0,4));}
    if(voiceMode){await speak(rep);}
    setMood('idle');
    setStatus('Prêt',false);
  }catch(err){
    pushMessage('assistant','Erreur technique temporaire.');
    setMood('error');
    setStatus('Erreur',false);
  }
  processing=false;
  if(voiceMode&&keepListening&&recognizer&&!listening){
    setTimeout(function(){try{recognizer.start();}catch(e){}},240);
  }
});

function initParticles(){
  var ctx=canvas.getContext('2d');
  if(!ctx){return;}
  var dots=[],w=0,h=0,count=44;
  function resize(){
    w=canvas.clientWidth||520;
    h=canvas.clientHeight||520;
    canvas.width=Math.floor(w*window.devicePixelRatio);
    canvas.height=Math.floor(h*window.devicePixelRatio);
    ctx.setTransform(window.devicePixelRatio,0,0,window.devicePixelRatio,0,0);
    dots=[];
    for(var k=0;k<count;k++){
      dots.push({x:Math.random()*w,y:Math.random()*h,r:Math.random()*1.9+0.5,vx:(Math.random()-0.5)*0.34,vy:(Math.random()-0.5)*0.34,a:Math.random()*0.6+0.15});
    }
  }
  function tick(){
    ctx.clearRect(0,0,w,h);
    for(var n=0;n<dots.length;n++){
      var p=dots[n];
      p.x+=p.vx;p.y+=p.vy;
      if(p.x<0||p.x>w){p.vx*=-1;}
      if(p.y<0||p.y>h){p.vy*=-1;}
      ctx.beginPath();
      ctx.arc(p.x,p.y,p.r,0,Math.PI*2,false);
      ctx.fillStyle='rgba(18,61,100,'+p.a+')';
      ctx.fill();
    }
    requestAnimationFrame(tick);
  }
  resize();
  window.addEventListener('resize',resize);
  tick();
}

recognizer=setupRecognizer();
initParticles();
pushMessage('assistant','Bonjour, je suis votre assistant CRM IA. Je peux lister les dernières actions, ouvrir une fiche contact, donner un numéro et enregistrer des notes.');
setSuggestions(['Dernières actions','Fiche du dernier contact','Numéro du dernier contact','Note pour ce contact: à rappeler demain']);
setStatus('Prêt',false);
setMood('welcome');
setTimeout(function(){setMood('idle');},1600);
})();
</script>
</body>
</html>
HTML;
        echo str_replace('__CFG_JSON__', $cfg_json, $html);
        exit;
    }

    public static function sanitize_session_id($value) {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }
        $value = preg_replace('/[^a-zA-Z0-9\-_]/', '', $value);
        if ($value === null) {
            return '';
        }
        return substr($value, 0, 64);
    }

    public static function create_session_id($prefix = 'sess') {
        $prefix = preg_replace('/[^a-zA-Z0-9]/', '', (string) $prefix);
        if ($prefix === '') {
            $prefix = 'sess';
        }
        try {
            $rand = bin2hex(random_bytes(8));
        } catch (Exception $e) {
            $rand = wp_generate_password(16, false, false);
        }
        return $prefix . '_' . gmdate('YmdHis') . '_' . $rand;
    }

    public static function get_events_raw() {
        $raw = get_option(self::OPTION_EVENTS, array());
        return is_array($raw) ? $raw : array();
    }

    public static function log_event($owner_user_id, $actor, $event_type, $data = array(), $session_id = '', $contact_ref = '') {
        $owner_user_id = self::normalize_owner_user_id((int) $owner_user_id);
        $actor = sanitize_key((string) $actor);
        $event_type = sanitize_key((string) $event_type);
        $session_id = self::sanitize_session_id($session_id);
        $contact_ref = sanitize_text_field((string) $contact_ref);
        if (!is_array($data)) {
            $data = array();
        }
        $data_json = wp_json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($data_json)) {
            $data_json = '{}';
        }
        $events = self::get_events_raw();
        array_unshift($events, array(
            'created_at' => gmdate('c'),
            'owner_user_id' => $owner_user_id,
            'actor' => $actor,
            'event_type' => $event_type,
            'session_id' => $session_id,
            'contact_ref' => $contact_ref,
            'data' => $data_json,
        ));
        if (count($events) > 5000) {
            $events = array_slice($events, 0, 5000);
        }
        update_option(self::OPTION_EVENTS, $events, false);
    }

    public static function get_admin_chat_state_raw() {
        $raw = get_option(self::OPTION_ADMIN_CHAT_STATE, array());
        return is_array($raw) ? $raw : array();
    }

    public static function get_admin_chat_state($owner_user_id, $session_id) {
        $owner_user_id = self::normalize_owner_user_id($owner_user_id);
        $session_id = self::sanitize_session_id($session_id);
        if ($session_id === '') {
            return array();
        }
        $raw = self::get_admin_chat_state_raw();
        if (isset($raw[(string) $owner_user_id][$session_id]) && is_array($raw[(string) $owner_user_id][$session_id])) {
            return $raw[(string) $owner_user_id][$session_id];
        }
        return array();
    }

    public static function set_admin_chat_state($owner_user_id, $session_id, $state) {
        $owner_user_id = self::normalize_owner_user_id($owner_user_id);
        $session_id = self::sanitize_session_id($session_id);
        if ($session_id === '' || !is_array($state)) {
            return;
        }
        $raw = self::get_admin_chat_state_raw();
        if (!isset($raw[(string) $owner_user_id]) || !is_array($raw[(string) $owner_user_id])) {
            $raw[(string) $owner_user_id] = array();
        }
        $state['updated_at'] = gmdate('c');
        $raw[(string) $owner_user_id][$session_id] = $state;
        update_option(self::OPTION_ADMIN_CHAT_STATE, $raw, false);
    }

    public static function clear_admin_chat_state($owner_user_id, $session_id) {
        $owner_user_id = self::normalize_owner_user_id($owner_user_id);
        $session_id = self::sanitize_session_id($session_id);
        if ($session_id === '') {
            return;
        }
        $raw = self::get_admin_chat_state_raw();
        if (isset($raw[(string) $owner_user_id][$session_id])) {
            unset($raw[(string) $owner_user_id][$session_id]);
            update_option(self::OPTION_ADMIN_CHAT_STATE, $raw, false);
        }
    }

    public static function get_admin_notes() {
        $raw = get_option(self::OPTION_ADMIN_NOTES, array());
        return is_array($raw) ? $raw : array();
    }

    public static function add_admin_note($owner_user_id, $contact_ref, $note_text) {
        $owner_user_id = self::normalize_owner_user_id($owner_user_id);
        $contact_ref = sanitize_text_field((string) $contact_ref);
        $note_text = trim((string) $note_text);
        if ($contact_ref === '' || $note_text === '') {
            return;
        }
        $all = self::get_admin_notes();
        if (!isset($all[(string) $owner_user_id]) || !is_array($all[(string) $owner_user_id])) {
            $all[(string) $owner_user_id] = array();
        }
        if (!isset($all[(string) $owner_user_id][$contact_ref]) || !is_array($all[(string) $owner_user_id][$contact_ref])) {
            $all[(string) $owner_user_id][$contact_ref] = array();
        }
        array_unshift($all[(string) $owner_user_id][$contact_ref], array(
            'created_at' => gmdate('c'),
            'text' => self::smart_trim($note_text, 1200),
        ));
        $all[(string) $owner_user_id][$contact_ref] = array_slice($all[(string) $owner_user_id][$contact_ref], 0, 40);
        update_option(self::OPTION_ADMIN_NOTES, $all, false);
        self::log_event($owner_user_id, 'admin', 'admin_note_added', array(
            'note' => self::smart_trim($note_text, 320),
        ), '', $contact_ref);
    }

    public static function get_contact_notes($owner_user_id, $contact_ref) {
        $all = self::get_admin_notes();
        return isset($all[(string) $owner_user_id][$contact_ref]) && is_array($all[(string) $owner_user_id][$contact_ref])
            ? $all[(string) $owner_user_id][$contact_ref]
            : array();
    }

    public static function find_contact_by_query($leads, $query, $fallback_ref = '') {
        $query = trim((string) $query);
        if ($fallback_ref !== '') {
            foreach ((array) $leads as $lead) {
                if ((string) ($lead['ref'] ?? '') === $fallback_ref) {
                    return $lead;
                }
            }
        }
        if ($query === '') {
            return array();
        }
        $needle = strtolower($query);
        foreach ((array) $leads as $lead) {
            $stack = strtolower(implode(' ', array(
                (string) ($lead['ref'] ?? ''),
                (string) ($lead['first_name'] ?? ''),
                (string) ($lead['last_name'] ?? ''),
                (string) ($lead['email'] ?? ''),
            )));
            if ($stack !== '' && strpos($stack, $needle) !== false) {
                return $lead;
            }
        }
        return array();
    }

    public static function normalize_search_text($text) {
        $text = trim((string) $text);
        if ($text === '') {
            return '';
        }
        if (function_exists('remove_accents')) {
            $text = remove_accents($text);
        }
        $text = strtolower($text);
        $text = preg_replace('/[^a-z0-9@\.\s\-_]/', ' ', $text);
        $text = preg_replace('/\s+/', ' ', (string) $text);
        return trim((string) $text);
    }

    public static function extract_contact_query($message) {
        $message = trim((string) $message);
        if ($message === '') {
            return '';
        }
        if (preg_match('/([a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,})/i', $message, $em)) {
            return trim((string) $em[1]);
        }
        $q = self::normalize_search_text($message);
        // Remove intent words so we keep only potential contact identifiers.
        $q = preg_replace('/\b(donne|moi|la|le|les|fiche|du|de|des|contact|numero|numeros|numeros|telephone|tel|stp|svp|pour|du|d)\b/', ' ', (string) $q);
        $q = preg_replace('/\s+/', ' ', (string) $q);
        return trim((string) $q);
    }

    public static function find_contacts_by_query($leads, $query, $limit = 5) {
        $query = self::normalize_search_text($query);
        if ($query === '') {
            return array();
        }
        $tokens = array_values(array_filter(explode(' ', $query), function ($t) {
            return strlen((string) $t) >= 2;
        }));

        $scored = array();
        foreach ((array) $leads as $lead) {
            $ref = (string) ($lead['ref'] ?? '');
            $first = (string) ($lead['first_name'] ?? '');
            $last = (string) ($lead['last_name'] ?? '');
            $email = (string) ($lead['email'] ?? '');
            $name = trim($first . ' ' . $last);

            $ref_n = self::normalize_search_text($ref);
            $name_n = self::normalize_search_text($name);
            $email_n = self::normalize_search_text($email);
            $stack = trim($ref_n . ' ' . $name_n . ' ' . $email_n);
            if ($stack === '') {
                continue;
            }

            $score = 0;
            if ($email_n !== '' && $query === $email_n) {
                $score += 120;
            }
            if ($ref_n !== '' && $query === $ref_n) {
                $score += 110;
            }
            if ($name_n !== '' && $query === $name_n) {
                $score += 100;
            }
            if (strpos($stack, $query) !== false) {
                $score += 60;
            }
            foreach ($tokens as $tk) {
                if (strpos($stack, $tk) !== false) {
                    $score += 8;
                }
            }
            if ($score > 0) {
                $lead['_score'] = $score;
                $scored[] = $lead;
            }
        }

        usort($scored, function ($a, $b) {
            $sa = (int) ($a['_score'] ?? 0);
            $sb = (int) ($b['_score'] ?? 0);
            if ($sa === $sb) {
                $ta = strtotime((string) ($a['created_at'] ?? '')) ?: 0;
                $tb = strtotime((string) ($b['created_at'] ?? '')) ?: 0;
                return $tb <=> $ta;
            }
            return $sb <=> $sa;
        });
        return array_slice($scored, 0, max(1, (int) $limit));
    }

    public static function get_events_for_owner($owner_user_id, $limit = 30) {
        $owner_user_id = self::normalize_owner_user_id($owner_user_id);
        $rows = array_values(array_filter(self::get_events_raw(), function ($ev) use ($owner_user_id) {
            return self::normalize_owner_user_id((int) ($ev['owner_user_id'] ?? 0)) === $owner_user_id;
        }));
        return array_slice($rows, 0, max(1, (int) $limit));
    }

    public static function admin_ai_intent($message, $last_contact_ref, $settings) {
        $api_key = trim((string) ($settings['api_key'] ?? ''));
        if ($api_key === '' && defined('ANTHROPIC_API_KEY')) {
            $api_key = (string) ANTHROPIC_API_KEY;
        }
        if ($api_key === '') {
            return array();
        }

        $system = "Tu es un routeur d'intentions CRM. Retourne STRICTEMENT un JSON valide: "
            . "{\"action\":\"...\",\"target\":\"...\",\"note\":\"...\",\"phone\":\"...\"}. "
            . "Actions autorisées: get_contact, add_note, update_phone, list_actions, list_events, summary, unknown. "
            . "Règles: si l'utilisateur dit \"cette fiche\" ou équivalent, target=\"__CURRENT__\". "
            . "Si demande de modification du téléphone sans numéro, action=update_phone et phone=\"\". "
            . "Ne jamais inventer de valeur.";

        $payload = array(
            'model' => $settings['model'] ?: 'claude-sonnet-4-20250514',
            'max_tokens' => 220,
            'temperature' => 0.1,
            'system' => $system,
            'messages' => array(
                array(
                    'role' => 'user',
                    'content' => array(
                        array(
                            'type' => 'text',
                            'text' => "Message admin: " . wp_strip_all_tags((string) $message)
                                . "\nDernier contact courant: " . (string) $last_contact_ref,
                        ),
                    ),
                ),
            ),
        );

        $res = wp_remote_post('https://api.anthropic.com/v1/messages', array(
            'timeout' => 20,
            'headers' => array(
                'x-api-key' => $api_key,
                'anthropic-version' => '2023-06-01',
                'content-type' => 'application/json',
            ),
            'body' => wp_json_encode($payload),
        ));
        if (is_wp_error($res) || (int) wp_remote_retrieve_response_code($res) >= 300) {
            return array();
        }
        $body = json_decode((string) wp_remote_retrieve_body($res), true);
        if (!is_array($body) || empty($body['content'])) {
            return array();
        }
        $txt = '';
        foreach ((array) $body['content'] as $c) {
            if (($c['type'] ?? '') === 'text') {
                $txt .= (string) ($c['text'] ?? '');
            }
        }
        $txt = trim((string) $txt);
        $data = json_decode($txt, true);
        if (!is_array($data) && preg_match('/\{.*\}/s', $txt, $m)) {
            $data = json_decode((string) $m[0], true);
        }
        if (!is_array($data)) {
            return array();
        }
        return array(
            'action' => sanitize_key((string) ($data['action'] ?? '')),
            'target' => trim((string) ($data['target'] ?? '')),
            'note' => trim((string) ($data['note'] ?? '')),
            'phone' => trim((string) ($data['phone'] ?? '')),
        );
    }

    public static function rest_admin_assistant_chat(WP_REST_Request $request) {
        $owner_user_id = self::normalize_owner_user_id((int) $request->get_param('owner_id'));
        $token = sanitize_text_field((string) $request->get_param('token'));
        if (!self::validate_admin_assistant_token($owner_user_id, $token)) {
            return new WP_REST_Response(array('reply' => 'Accès refusé.'), 403);
        }
        $message = trim((string) $request->get_param('message'));
        $session_id = self::sanitize_session_id((string) $request->get_param('session_id'));
        if ($session_id === '') {
            $session_id = self::create_session_id('admin');
        }
        $last_contact_ref = sanitize_text_field((string) $request->get_param('last_contact_ref'));
        if ($message === '') {
            return new WP_REST_Response(array('reply' => 'Message vide.'), 400);
        }
        self::log_event($owner_user_id, 'admin', 'admin_chat_user_message', array(
            'message' => self::smart_trim($message, 500),
        ), $session_id, $last_contact_ref);

        $leads = self::get_leads($owner_user_id);
        $lower = strtolower($message);
        $reply = '';
        $suggestions = array('Dernières actions', 'Fiche du dernier contact', 'Numéro du dernier contact');
        $out_ref = $last_contact_ref;
        $admin_state = self::get_admin_chat_state($owner_user_id, $session_id);
        $settings = self::get_settings($owner_user_id);
        $ai_intent = self::admin_ai_intent($message, $last_contact_ref, $settings);
        $ai_action = (string) ($ai_intent['action'] ?? '');
        $ai_target = (string) ($ai_intent['target'] ?? '');
        $ai_note = (string) ($ai_intent['note'] ?? '');
        $ai_phone = preg_replace('/[^0-9+\s().-]/', '', (string) ($ai_intent['phone'] ?? ''));

        if ($reply === '' && in_array($ai_action, array('get_contact', 'add_note', 'update_phone', 'list_actions', 'list_events', 'summary'), true)) {
            $target = $ai_target;
            if ($target === '__CURRENT__' || $target === '' || strpos(strtolower($target), 'cette fiche') !== false) {
                $target = $last_contact_ref;
            }
            if ($ai_action === 'get_contact') {
                $contact = $target !== '' ? self::find_contact_by_query($leads, $target, $last_contact_ref) : self::find_contact_by_query($leads, '', $last_contact_ref);
                if (!empty($contact['ref'])) {
                    $out_ref = (string) $contact['ref'];
                    $name = trim((string) ($contact['first_name'] ?? '') . ' ' . (string) ($contact['last_name'] ?? ''));
                    $reply = "Fiche contact:\n"
                        . "- Réf: " . $out_ref . "\n"
                        . "- Nom: " . ($name !== '' ? $name : 'N/A') . "\n"
                        . "- Email: " . ((string) ($contact['email'] ?? '') !== '' ? (string) $contact['email'] : 'N/A') . "\n"
                        . "- Téléphone: " . ((string) ($contact['phone'] ?? '') !== '' ? (string) $contact['phone'] : 'N/A');
                }
            } elseif ($ai_action === 'add_note') {
                $contact = $target !== '' ? self::find_contact_by_query($leads, $target, $last_contact_ref) : self::find_contact_by_query($leads, '', $last_contact_ref);
                if (!empty($contact['ref']) && $ai_note !== '') {
                    $out_ref = (string) $contact['ref'];
                    self::add_admin_note($owner_user_id, $out_ref, $ai_note);
                    $reply = 'Note enregistrée sur la fiche en cours.';
                }
            } elseif ($ai_action === 'update_phone') {
                $contact = $target !== '' ? self::find_contact_by_query($leads, $target, $last_contact_ref) : self::find_contact_by_query($leads, '', $last_contact_ref);
                if (!empty($contact['ref'])) {
                    $out_ref = (string) $contact['ref'];
                    if (trim((string) $ai_phone) === '') {
                        self::set_admin_chat_state($owner_user_id, $session_id, array('await' => 'phone', 'ref' => $out_ref));
                        $reply = "D’accord. Quel numéro voulez-vous enregistrer pour cette fiche ?";
                    } else {
                        $updated = self::update_lead_phone($owner_user_id, $out_ref, $ai_phone);
                        if ($updated) {
                            self::clear_admin_chat_state($owner_user_id, $session_id);
                            $reply = "Numéro mis à jour pour la fiche " . $out_ref . ": " . trim((string) $ai_phone) . ".";
                        }
                    }
                }
            } elseif ($ai_action === 'list_actions') {
                $items = array_slice($leads, 0, 8);
                if (!empty($items)) {
                    $lines = array();
                    foreach ($items as $lead) {
                        $name = trim((string) ($lead['first_name'] ?? '') . ' ' . (string) ($lead['last_name'] ?? ''));
                        $name = $name !== '' ? $name : ((string) ($lead['email'] ?? '') !== '' ? (string) $lead['email'] : (string) ($lead['ref'] ?? ''));
                        $date = self::format_date_short((string) ($lead['created_at'] ?? ''));
                        $kind = !empty($lead['wants_rdv']) ? 'RDV' : 'Lead';
                        $lines[] = '- ' . $date . ' | ' . $kind . ' | ' . $name;
                    }
                    $reply = "Dernières actions:\n" . implode("\n", $lines);
                }
            } elseif ($ai_action === 'list_events') {
                $events = self::get_events_for_owner($owner_user_id, 8);
                if (!empty($events)) {
                    $lines = array();
                    foreach ($events as $ev) {
                        $lines[] = '- ' . self::format_date_short((string) ($ev['created_at'] ?? ''))
                            . ' | ' . (string) ($ev['actor'] ?? '')
                            . ' | ' . (string) ($ev['event_type'] ?? '');
                    }
                    $reply = "Derniers événements:\n" . implode("\n", $lines);
                }
            } elseif ($ai_action === 'summary') {
                $count = count($leads);
                $rdv = count(array_filter($leads, function ($l) { return !empty($l['wants_rdv']); }));
                $reply = "Vue CRM rapide: " . $count . " contacts au total, dont " . $rdv . " avec RDV.";
            }
            if ($reply !== '') {
                self::log_event($owner_user_id, 'admin', 'admin_ai_router_used', array(
                    'action' => $ai_action,
                    'target' => $ai_target,
                ), $session_id, $out_ref);
            }
        }

        if ($reply === '' && !empty($admin_state['await']) && (string) $admin_state['await'] === 'phone' && !empty($admin_state['ref'])) {
            $candidate_phone = preg_replace('/[^0-9+\s().-]/', '', (string) $message);
            $digits = preg_replace('/\D/', '', (string) $candidate_phone);
            if (strlen((string) $digits) >= 8) {
                $target_ref = sanitize_text_field((string) $admin_state['ref']);
                $updated = self::update_lead_phone($owner_user_id, $target_ref, $candidate_phone);
                self::clear_admin_chat_state($owner_user_id, $session_id);
                if ($updated) {
                    $out_ref = $target_ref;
                    $reply = "Numéro mis à jour pour la fiche " . $target_ref . ": " . trim((string) $candidate_phone) . ".";
                    self::log_event($owner_user_id, 'admin', 'contact_phone_updated', array(
                        'phone' => trim((string) $candidate_phone),
                    ), $session_id, $target_ref);
                } else {
                    $reply = "Je n’ai pas réussi à mettre à jour le numéro sur cette fiche.";
                }
            } else {
                $reply = "Je n’ai pas reconnu un numéro valide. Donnez-moi un téléphone (au moins 8 chiffres).";
            }
        } elseif ($reply === '' && preg_match('/^note pour\s+(.+?)[\:\-]\s*(.+)$/iu', $message, $m)) {
            $target = trim((string) $m[1]);
            $note = trim((string) $m[2]);
            $contact = self::find_contact_by_query($leads, $target, $last_contact_ref);
            if (!empty($contact['ref'])) {
                self::add_admin_note($owner_user_id, (string) $contact['ref'], $note);
                $out_ref = (string) $contact['ref'];
                $reply = 'Note enregistrée pour ' . trim((string) ($contact['first_name'] ?? '') . ' ' . (string) ($contact['last_name'] ?? '')) . '.';
            } else {
                $reply = 'Contact introuvable pour enregistrer la note.';
            }
        } elseif ($reply === '' &&
            preg_match('/\b(note|ecris|écris|ajoute)\b/iu', $message)
            && preg_match('/[\:\-]/u', $message)
            && (strpos($lower, 'cette fiche') !== false || strpos($lower, 'fiche') !== false || strpos($lower, 'contact') !== false)
        ) {
            $parts = preg_split('/[\:\-]/u', $message, 2);
            $note = isset($parts[1]) ? trim((string) $parts[1]) : '';
            $contact = array();
            if ($last_contact_ref !== '') {
                $contact = self::find_contact_by_query($leads, '', $last_contact_ref);
            }
            if (!empty($contact['ref']) && $note !== '') {
                self::add_admin_note($owner_user_id, (string) $contact['ref'], $note);
                $out_ref = (string) $contact['ref'];
                $reply = 'Note enregistrée sur la fiche en cours.';
            } else {
                $reply = "Je n’ai pas de fiche active. Ouvrez d’abord une fiche contact.";
            }
        } elseif ($reply === '' &&
            preg_match('/\b(modif|modifie|modifier|change|changer|mettre a jour|mettre à jour)\b/iu', $message)
            && preg_match('/\b(numero|numéro|telephone|t[eé]l)\b/iu', $message)
        ) {
            $query = self::extract_contact_query($message);
            $contact = array();
            if ($query !== '') {
                $matchesOne = self::find_contacts_by_query($leads, $query, 1);
                if (!empty($matchesOne[0])) {
                    $contact = $matchesOne[0];
                }
            }
            if (empty($contact['ref']) && $last_contact_ref !== '') {
                $contact = self::find_contact_by_query($leads, '', $last_contact_ref);
            }
            if (!empty($contact['ref'])) {
                $out_ref = (string) $contact['ref'];
                self::set_admin_chat_state($owner_user_id, $session_id, array(
                    'await' => 'phone',
                    'ref' => $out_ref,
                ));
                $reply = "D’accord. Quel numéro voulez-vous enregistrer pour cette fiche ?";
                $suggestions = array('06 12 34 56 78', 'Voir la fiche', 'Dernières actions');
            } else {
                $reply = "Je n’ai pas trouvé la fiche à modifier. Donnez le nom, l’email ou ouvrez d’abord la fiche.";
            }
        } elseif ($reply === '' && (strpos($lower, 'fiche') !== false || strpos($lower, 'contact') !== false || strpos($lower, 'num') !== false || strpos($lower, 'tél') !== false || strpos($lower, 'tel') !== false)) {
            $query = self::extract_contact_query($message);
            $contact = array();
            $matches = array();
            if ($query !== '') {
                $matches = self::find_contacts_by_query($leads, $query, 4);
                if (!empty($matches[0])) {
                    $contact = $matches[0];
                }
            }
            if (empty($contact['ref']) && $last_contact_ref !== '') {
                $contact = self::find_contact_by_query($leads, '', $last_contact_ref);
            }
            if (empty($contact['ref'])) {
                $reply = 'Je n’ai pas trouvé le contact demandé.';
            } else {
                $out_ref = (string) $contact['ref'];
                $name = trim((string) ($contact['first_name'] ?? '') . ' ' . (string) ($contact['last_name'] ?? ''));
                $email = (string) ($contact['email'] ?? '');
                $phone = (string) ($contact['phone'] ?? '');
                $kind = !empty($contact['wants_rdv']) ? 'RDV' : 'Lead';
                $notes = self::get_contact_notes($owner_user_id, $out_ref);
                $last_note = !empty($notes[0]['text']) ? (string) $notes[0]['text'] : 'Aucune';
                $reply = "Fiche contact:\n"
                    . "- Réf: " . $out_ref . "\n"
                    . "- Nom: " . ($name !== '' ? $name : 'N/A') . "\n"
                    . "- Email: " . ($email !== '' ? $email : 'N/A') . "\n"
                    . "- Téléphone: " . ($phone !== '' ? $phone : 'N/A') . "\n"
                    . "- Statut: " . $kind . "\n"
                    . "- Dernière note: " . $last_note;

                if (!empty($matches) && count($matches) > 1) {
                    $alts = array();
                    foreach (array_slice($matches, 1, 3) as $m) {
                        $alt_name = trim((string) ($m['first_name'] ?? '') . ' ' . (string) ($m['last_name'] ?? ''));
                        $alt_email = (string) ($m['email'] ?? '');
                        $alt_ref = (string) ($m['ref'] ?? '');
                        $alts[] = '- ' . ($alt_name !== '' ? $alt_name : 'N/A') . ' | ' . ($alt_email !== '' ? $alt_email : 'N/A') . ' | ' . $alt_ref;
                    }
                    if (!empty($alts)) {
                        $reply .= "\n\nAutres fiches proches:\n" . implode("\n", $alts);
                    }
                }
            }
        } elseif ($reply === '' && (strpos($lower, 'action') !== false || strpos($lower, 'évén') !== false || strpos($lower, 'even') !== false || strpos($lower, 'historique') !== false)) {
            $items = array_slice($leads, 0, 8);
            if (empty($items)) {
                $reply = 'Aucune action enregistrée pour le moment.';
            } else {
                $lines = array();
                foreach ($items as $lead) {
                    $name = trim((string) ($lead['first_name'] ?? '') . ' ' . (string) ($lead['last_name'] ?? ''));
                    $name = $name !== '' ? $name : ((string) ($lead['email'] ?? '') !== '' ? (string) $lead['email'] : (string) ($lead['ref'] ?? ''));
                    $date = self::format_date_short((string) ($lead['created_at'] ?? ''));
                    $kind = !empty($lead['wants_rdv']) ? 'RDV' : 'Lead';
                    $lines[] = '- ' . $date . ' | ' . $kind . ' | ' . $name;
                }
                $reply = "Dernières actions:\n" . implode("\n", $lines);
                $out_ref = (string) ($items[0]['ref'] ?? '');
            }
        } elseif ($reply === '') {
            $count = count($leads);
            $rdv = count(array_filter($leads, function ($l) { return !empty($l['wants_rdv']); }));
            $reply = "Vue CRM rapide: " . $count . " contacts au total, dont " . $rdv . " avec RDV. "
                . "Vous pouvez me demander: dernières actions, fiche d’un contact, numéro d’un contact, ou enregistrer une note.";
        }

        self::log_event($owner_user_id, 'assistant', 'admin_chat_assistant_reply', array(
            'reply' => self::smart_trim($reply, 700),
        ), $session_id, $out_ref);
        return new WP_REST_Response(array(
            'reply' => $reply,
            'suggestions' => $suggestions,
            'last_contact_ref' => $out_ref,
            'session_id' => $session_id,
        ), 200);
    }

    public static function rest_chat(WP_REST_Request $request) {
        $message = trim((string) $request->get_param('message'));
        $session_id = self::sanitize_session_id((string) $request->get_param('session_id'));
        if ($session_id === '') {
            $session_id = self::create_session_id('visitor');
        }
        $owner_user_id = self::normalize_owner_user_id((int) get_current_user_id());
        if ($message === '') {
            return new WP_REST_Response(array('reply' => 'Message vide.'), 400);
        }
        self::log_event($owner_user_id, 'visitor', 'visitor_chat_user_message', array(
            'message' => self::smart_trim($message, 500),
            'page_url' => esc_url_raw((string) $request->get_param('page_url')),
        ), $session_id);

        $index = get_option(self::OPTION_INDEX, array());
        $docs = isset($index['docs']) && is_array($index['docs']) ? $index['docs'] : array();
        if (empty($docs)) {
            return new WP_REST_Response(array('reply' => 'Je n\'ai pas encore de données indexées. Réessayez dans quelques minutes.'), 200);
        }

        $hits = self::search_docs($message, $docs, 5);
        $settings = self::get_settings();

        $llm = self::private_backend_chat($message, $hits, $settings);
        if (empty($llm['reply'])) {
            $llm = self::llm_reply($message, $hits, $settings);
        }
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

        self::log_event($owner_user_id, 'assistant', 'visitor_chat_assistant_reply', array(
            'reply' => self::smart_trim($reply, 700),
        ), $session_id);
        return new WP_REST_Response(array(
            'reply' => $reply,
            'suggestions' => $suggestions,
            'sources' => array_map(function ($d) {
                return array('title' => $d['title'], 'url' => $d['url']);
            }, $hits),
            'session_id' => $session_id,
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

        $speed = (float) ($settings['elevenlabs_speed'] ?? '1.08');
        if ($speed < 1.05) {
            $speed = 1.05;
        }
        if ($speed > 1.25) {
            $speed = 1.25;
        }

        $payload = array(
            'text' => wp_strip_all_tags($text),
            'model_id' => 'eleven_flash_v2_5',
            'language_code' => $lang,
            'voice_settings' => array(
                'stability' => 0.20,
                'similarity_boost' => 0.90,
                'style' => 0.88,
                'use_speaker_boost' => true,
                'speed' => $speed,
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

    public static function private_backend_config() {
        $url = '';
        $token = '';
        if (defined('MONASSISTANT_BACKEND_URL')) {
            $url = trim((string) MONASSISTANT_BACKEND_URL);
        }
        if (defined('MONASSISTANT_BACKEND_TOKEN')) {
            $token = trim((string) MONASSISTANT_BACKEND_TOKEN);
        }
        return array(
            'url' => esc_url_raw($url),
            'token' => sanitize_text_field($token),
        );
    }

    public static function private_backend_chat($message, $hits, $settings) {
        $cfg = self::private_backend_config();
        $base = trim((string) ($cfg['url'] ?? ''));
        if ($base === '') {
            return array('reply' => '', 'suggestions' => array());
        }
        $endpoint = trailingslashit($base) . 'chat';
        $ctx = array();
        foreach ((array) $hits as $h) {
            $ctx[] = array(
                'title' => (string) ($h['title'] ?? ''),
                'url' => (string) ($h['url'] ?? ''),
                'content' => self::smart_trim((string) ($h['content'] ?? ''), 1400),
            );
        }

        $headers = array(
            'content-type' => 'application/json',
            'accept' => 'application/json',
        );
        if (!empty($cfg['token'])) {
            $headers['authorization'] = 'Bearer ' . $cfg['token'];
        }

        $payload = array(
            'message' => (string) $message,
            'lang' => (string) ($settings['lang'] ?? 'fr'),
            'site_url' => home_url('/'),
            'context' => $ctx,
        );

        $res = wp_remote_post($endpoint, array(
            'timeout' => 20,
            'headers' => $headers,
            'body' => wp_json_encode($payload),
        ));
        if (is_wp_error($res) || (int) wp_remote_retrieve_response_code($res) >= 300) {
            return array('reply' => '', 'suggestions' => array());
        }
        $body = json_decode((string) wp_remote_retrieve_body($res), true);
        if (!is_array($body)) {
            return array('reply' => '', 'suggestions' => array());
        }
        $reply = trim(wp_strip_all_tags((string) ($body['reply'] ?? '')));
        $suggestions = array();
        if (!empty($body['suggestions']) && is_array($body['suggestions'])) {
            foreach ($body['suggestions'] as $s) {
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

    public static function rest_lead(WP_REST_Request $request) {
        $owner_user_id = self::get_runtime_owner_user_id();
        $site_origin = esc_url_raw(home_url('/'));
        $first_name = sanitize_text_field((string) $request->get_param('first_name'));
        $last_name = sanitize_text_field((string) $request->get_param('last_name'));
        $email = sanitize_email((string) $request->get_param('email'));
        $phone = sanitize_text_field((string) $request->get_param('phone'));
        $transcript = (string) $request->get_param('transcript');
        $page_url = esc_url_raw((string) $request->get_param('page_url'));
        $intent = sanitize_text_field((string) $request->get_param('intent'));
        $wants_rdv = (bool) $request->get_param('wants_rdv');
        $session_id = self::sanitize_session_id((string) $request->get_param('session_id'));
        if ($session_id === '') {
            $session_id = self::create_session_id('visitor');
        }

        if (($email === '' || !is_email($email)) && !$wants_rdv) {
            return new WP_REST_Response(array('ok' => false, 'message' => 'Email invalide.'), 400);
        }
        if (!is_email($email)) {
            $email = '';
        }

        $phone = preg_replace('/[^0-9+\s().-]/', '', $phone);
        $transcript = self::smart_trim($transcript, 4000);
        $ref = 'LEAD-' . gmdate('Ymd-His') . '-' . wp_generate_password(4, false, false);

        $lead = array(
            'ref' => $ref,
            'created_at' => gmdate('c'),
            'owner_user_id' => $owner_user_id,
            'first_name' => $first_name,
            'last_name' => $last_name,
            'email' => $email,
            'phone' => $phone,
            'intent' => $intent,
            'wants_rdv' => $wants_rdv ? 1 : 0,
            'page_url' => $page_url,
            'transcript' => $transcript,
            'session_id' => $session_id,
        );

        $leads = self::get_all_leads_raw();
        array_unshift($leads, $lead);
        if (count($leads) > 3000) {
            $leads = array_slice($leads, 0, 3000);
        }
        update_option(self::OPTION_LEADS, $leads, false);

        $settings = self::get_settings($owner_user_id);
        $logo_url = trim((string) ($settings['logo_url'] ?? ''));
        if ($logo_url === '') {
            $logo_url = self::DEFAULT_ROBOT_LOGO_URL;
        }

        $subject = 'Votre récapitulatif - Chatbot Mon Assistant IA';
        $customer_html = self::build_lead_email_html(array(
            'title' => 'Merci pour votre échange',
            'subtitle' => 'Votre demande a bien été enregistrée.',
            'ref' => $ref,
            'first_name' => $first_name,
            'last_name' => $last_name,
            'email' => $email,
            'phone' => $phone,
            'demand' => ($wants_rdv ? 'Souhaite un RDV téléphonique' : 'Récapitulatif'),
            'site_origin' => $site_origin,
            'page_url' => $page_url,
            'transcript' => $transcript,
            'logo_url' => $logo_url,
        ));
        $customer_text = self::build_lead_email_text(array(
            'ref' => $ref,
            'first_name' => $first_name,
            'last_name' => $last_name,
            'email' => $email,
            'phone' => $phone,
            'demand' => ($wants_rdv ? 'Souhaite un RDV téléphonique' : 'Récapitulatif'),
            'site_origin' => $site_origin,
            'page_url' => $page_url,
            'transcript' => $transcript,
        ));
        if ($email !== '') {
            self::send_html_mail($email, $subject, $customer_html, $customer_text);
        }

        $admin_email = get_option('admin_email');
        if (is_email($admin_email)) {
            $admin_html = self::build_lead_email_html(array(
                'title' => 'Nouveau lead reçu',
                'subtitle' => 'Un visiteur a demandé un suivi.',
                'ref' => $ref,
                'first_name' => $first_name,
                'last_name' => $last_name,
                'email' => $email,
                'phone' => $phone,
                'demand' => ($wants_rdv ? 'Souhaite un RDV téléphonique' : 'Récapitulatif'),
                'site_origin' => $site_origin,
                'page_url' => $page_url,
                'transcript' => $transcript,
                'logo_url' => $logo_url,
            ));
            self::send_html_mail($admin_email, '[Lead] ' . $ref . ' - ' . $email, $admin_html, $customer_text);
        }
        self::log_event($owner_user_id, 'visitor', 'lead_saved', array(
            'first_name' => $first_name,
            'last_name' => $last_name,
            'email' => $email,
            'phone' => $phone,
            'intent' => $intent,
            'wants_rdv' => $wants_rdv ? 1 : 0,
            'page_url' => $page_url,
        ), $session_id, $ref);
        if ($wants_rdv) {
            self::log_event($owner_user_id, 'visitor', 'phone_call_requested', array(
                'intent' => $intent,
            ), $session_id, $ref);
        }

        return new WP_REST_Response(array('ok' => true, 'ref' => $ref, 'session_id' => $session_id), 200);
    }

    public static function send_html_mail($to, $subject, $html, $text_fallback = '') {
        $headers = array('Content-Type: text/html; charset=UTF-8');
        $ok = wp_mail($to, $subject, $html, $headers);
        if (!$ok && $text_fallback !== '') {
            wp_mail($to, $subject, $text_fallback);
        }
        return $ok;
    }

    public static function build_lead_email_text($data) {
        $lines = array(
            'Bonjour,',
            '',
            'Référence: ' . (string) ($data['ref'] ?? ''),
            'Prénom: ' . ((string) ($data['first_name'] ?? '') !== '' ? (string) $data['first_name'] : 'Non renseigné'),
            'Nom: ' . ((string) ($data['last_name'] ?? '') !== '' ? (string) $data['last_name'] : 'Non renseigné'),
            'Email: ' . (string) ($data['email'] ?? ''),
            'Téléphone: ' . ((string) ($data['phone'] ?? '') !== '' ? (string) $data['phone'] : 'Non renseigné'),
            'Demande: ' . (string) ($data['demand'] ?? ''),
            'Site d’origine: ' . ((string) ($data['site_origin'] ?? '') !== '' ? (string) ($data['site_origin'] ?? '') : 'N/A'),
            'Page: ' . ((string) ($data['page_url'] ?? '') !== '' ? (string) $data['page_url'] : 'N/A'),
            '',
            'Récapitulatif de l’échange:',
            (string) ($data['transcript'] ?? 'Aucun message enregistré.'),
            '',
            'Chatbot Mon Assistant IA',
        );
        return implode("\n", $lines);
    }

    public static function build_lead_email_html($data) {
        $logo = esc_url((string) ($data['logo_url'] ?? ''));
        $title = esc_html((string) ($data['title'] ?? 'Récapitulatif'));
        $subtitle = esc_html((string) ($data['subtitle'] ?? ''));
        $ref = esc_html((string) ($data['ref'] ?? ''));
        $first = esc_html((string) ($data['first_name'] ?? ''));
        $last = esc_html((string) ($data['last_name'] ?? ''));
        $email = esc_html((string) ($data['email'] ?? ''));
        $phone = esc_html((string) ($data['phone'] ?? ''));
        $demand = esc_html((string) ($data['demand'] ?? ''));
        $origin = esc_url((string) ($data['site_origin'] ?? ''));
        $page = esc_url((string) ($data['page_url'] ?? ''));
        $transcript = nl2br(esc_html((string) ($data['transcript'] ?? 'Aucun message enregistré.')));

        $first_display = $first !== '' ? $first : 'Non renseigné';
        $last_display = $last !== '' ? $last : 'Non renseigné';
        $phone_display = $phone !== '' ? $phone : 'Non renseigné';
        $origin_display = $origin !== '' ? '<a href="' . $origin . '">' . $origin . '</a>' : 'N/A';
        $page_display = $page !== '' ? '<a href="' . $page . '">' . $page . '</a>' : 'N/A';
        $logo_block = $logo !== '' ? '<img src="' . $logo . '" alt="Logo" style="max-height:56px; width:auto; display:block; margin:0 auto 14px;" />' : '';

        return '<!doctype html><html><body style="margin:0;padding:0;background:#f3f6fb;font-family:Raleway,Segoe UI,Arial,sans-serif;color:#123d64;">'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="padding:24px 12px;">'
            . '<tr><td align="center"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:660px;background:#ffffff;border-radius:16px;overflow:hidden;border:1px solid rgba(18,61,100,.12);">'
            . '<tr><td style="padding:22px 24px;background:linear-gradient(135deg,#123d64 0%,#1c6ea4 100%);color:#fff;text-align:center;">'
            . $logo_block
            . '<div style="font-size:22px;font-weight:700;line-height:1.2;">' . $title . '</div>'
            . '<div style="margin-top:6px;font-size:14px;opacity:.95;">' . $subtitle . '</div>'
            . '</td></tr>'
            . '<tr><td style="padding:20px 24px;">'
            . '<div style="font-size:13px;margin-bottom:14px;"><strong>Référence:</strong> ' . $ref . '</div>'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="font-size:14px;border-collapse:collapse;">'
            . '<tr><td style="padding:6px 0;"><strong>Prénom</strong></td><td style="padding:6px 0;">' . $first_display . '</td></tr>'
            . '<tr><td style="padding:6px 0;"><strong>Nom</strong></td><td style="padding:6px 0;">' . $last_display . '</td></tr>'
            . '<tr><td style="padding:6px 0;"><strong>Email</strong></td><td style="padding:6px 0;">' . $email . '</td></tr>'
            . '<tr><td style="padding:6px 0;"><strong>Téléphone</strong></td><td style="padding:6px 0;">' . $phone_display . '</td></tr>'
            . '<tr><td style="padding:6px 0;"><strong>Demande</strong></td><td style="padding:6px 0;">' . $demand . '</td></tr>'
            . '<tr><td style="padding:6px 0;"><strong>Site d’origine</strong></td><td style="padding:6px 0;">' . $origin_display . '</td></tr>'
            . '<tr><td style="padding:6px 0;"><strong>Page</strong></td><td style="padding:6px 0;">' . $page_display . '</td></tr>'
            . '</table>'
            . '<div style="margin-top:18px;padding:14px;border-radius:10px;background:#f5f9ff;border:1px solid rgba(18,61,100,.14);">'
            . '<div style="font-size:13px;font-weight:700;margin-bottom:8px;">Récapitulatif de l’échange</div>'
            . '<div style="font-size:13px;line-height:1.5;color:#234f77;">' . $transcript . '</div>'
            . '</div>'
            . '<div style="margin-top:16px;font-size:12px;color:#5b7390;">Cet e-mail a été généré automatiquement par Chatbot Mon Assistant IA.</div>'
            . '</td></tr></table></td></tr></table></body></html>';
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
            . "Base-toi strictement sur les extraits fournis et ne fabrique rien. Si le sujet n'est pas clairement présent dans les extraits: dis-le explicitement. "
            . "Ne redirige pas vers des offres/formations hors sujet. Si un nom précis est demandé (ex: Amadeus), confirme d'abord sa présence dans les extraits; sinon indique que l'information n'est pas disponible sur cette base. "
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
        $query_norm = self::normalize_text_for_search($message);
        $scored = array();

        foreach ($docs as $doc) {
            $title = (string) ($doc['title'] ?? '');
            $content = (string) ($doc['content'] ?? '');
            $haystack = self::normalize_text_for_search($title . ' ' . $content);
            $score = 0;
            if ($query_norm !== '' && strpos($haystack, $query_norm) !== false) {
                $score += 14;
            }
            foreach ($tokens as $t) {
                if ($t !== '' && strpos($haystack, $t) !== false) {
                    $score += 3;
                    if (strpos(self::normalize_text_for_search($title), $t) !== false) {
                        $score += 4;
                    }
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
        $text = self::normalize_text_for_search($text);
        $parts = preg_split('/[^\p{L}\p{N}]+/u', $text);
        $out = array();
        $stop = array(
            'les' => true, 'des' => true, 'une' => true, 'dans' => true, 'avec' => true, 'pour' => true,
            'est' => true, 'sont' => true, 'sur' => true, 'que' => true, 'qui' => true, 'quoi' => true,
            'comment' => true, 'vous' => true, 'nous' => true, 'site' => true, 'monassistant' => true,
            'assistant' => true, 'chatbot' => true, 'this' => true, 'that' => true, 'from' => true,
        );
        foreach ((array) $parts as $p) {
            $p = trim((string) $p);
            if ($p === '' || strlen($p) < 3) {
                continue;
            }
            if (isset($stop[$p])) {
                continue;
            }
            $out[$p] = true;
        }
        return array_keys($out);
    }

    public static function normalize_text_for_search($text) {
        $text = wp_strip_all_tags((string) $text);
        if (function_exists('remove_accents')) {
            $text = remove_accents($text);
        }
        $text = strtolower($text);
        $text = preg_replace('/\s+/', ' ', (string) $text);
        return trim((string) $text);
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
