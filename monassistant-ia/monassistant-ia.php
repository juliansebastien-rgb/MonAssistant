<?php
/**
 * Plugin Name: Chatbot Mon Assistant IA
 * Description: Assistant flottant pour répondre aux visiteurs à partir des contenus du site (crawl + index + chat).
 * Version: 2.8.2
 * Author: Azertaf
 */

if (!defined('ABSPATH')) {
    exit;
}

final class AZSA_Plugin {
    const VERSION = '2.8.2';
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
        add_filter('plugins_auto_update_enabled', '__return_true');
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
            'calendar_provider' => 'none',
            'calendly_url' => '',
            'calendly_pat' => '',
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
            'Liste des prospects',
            'Liste des prospects',
            'manage_options',
            'azsa-prospects',
            array(__CLASS__, 'render_prospects_page')
        );
        add_submenu_page(
            'azsa-prospects',
            'Liste des RDV',
            'Liste des RDV',
            'manage_options',
            'azsa-rdv',
            array(__CLASS__, 'render_rdv_page')
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
        $out = self::get_settings();
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
        return wp_parse_args($out, self::defaults());
    }

    public static function render_admin_page() {
        if (!current_user_can('manage_options')) {
            return;
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

        $settings = self::get_settings();
        $release = self::get_latest_github_release($settings, false);
        $latest = is_array($release) && !empty($release['version']) ? (string) $release['version'] : 'indisponible';
        $auto_enabled = self::is_auto_updates_enabled();
        $has_new = is_array($release) && !empty($release['version']) && version_compare((string) $release['version'], self::VERSION, '>');
        ?>
        <div class="wrap">
            <h1>Chatbot Mon Assistant IA</h1>
            <p>Configurez uniquement la connexion au calendrier pour les RDV.</p>
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

    public static function get_leads() {
        $leads = get_option(self::OPTION_LEADS, array());
        return is_array($leads) ? $leads : array();
    }

    public static function update_leads($leads) {
        if (!is_array($leads)) {
            $leads = array();
        }
        update_option(self::OPTION_LEADS, $leads, false);
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
        if (!wp_verify_nonce($nonce, 'azsa_lead_action_' . $ref)) {
            return;
        }
        $leads = self::get_leads();
        $changed = false;
        foreach ($leads as &$lead) {
            if ((string) ($lead['ref'] ?? '') !== $ref) {
                continue;
            }
            if ($action === 'mark_callback_open') {
                $lead['callback_status'] = 'open';
                $changed = true;
            } elseif ($action === 'mark_callback_done') {
                $lead['callback_status'] = 'done';
                $changed = true;
            } elseif ($action === 'clear_callback') {
                unset($lead['callback_status']);
                $changed = true;
            }
            break;
        }
        unset($lead);
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
        echo '<table class="widefat striped"><thead><tr>';
        echo '<th>Réf</th><th>Date</th><th>Prénom</th><th>Nom</th><th>Email</th><th>Téléphone</th><th>Demande</th><th>Rappel</th><th>Actions rapides</th>';
        echo '</tr></thead><tbody>';
        foreach ($rows as $lead) {
            $ref = esc_html((string) ($lead['ref'] ?? ''));
            $date = esc_html((string) ($lead['created_at'] ?? ''));
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
            echo '<td style="white-space:nowrap;">';
            if ($callback_status !== 'open') {
                echo '<a class="button button-small" href="' . esc_url($open_url) . '">Créer tâche rappel</a> ';
            }
            if ($callback_status === 'open') {
                echo '<a class="button button-small" href="' . esc_url($done_url) . '">Rappel fait</a> ';
            }
            if ($callback_status !== '') {
                echo '<a class="button button-small" href="' . esc_url($clear_url) . '">Retirer</a> ';
            }
            if ($email_raw !== '') {
                echo '<a class="button button-small" href="mailto:' . esc_attr($email_raw) . '">Email</a> ';
                echo '<button type="button" class="button button-small azsa-copy-btn" data-label="Copier email" data-copy="' . esc_attr($email_raw) . '">Copier email</button> ';
            }
            if ($phone_raw !== '') {
                echo '<a class="button button-small" href="tel:' . esc_attr($phone_raw) . '">Appeler</a> ';
                echo '<button type="button" class="button button-small azsa-copy-btn" data-label="Copier tél" data-copy="' . esc_attr($phone_raw) . '">Copier tél</button> ';
            }
            if ($transcript !== '') {
                echo '<details style="display:inline-block;vertical-align:middle;"><summary class="button button-small" style="cursor:pointer;">Transcript</summary><div style="margin-top:8px;max-width:480px;max-height:220px;overflow:auto;white-space:pre-wrap;border:1px solid #dcdcde;padding:8px;background:#fff;">' . $transcript . '</div></details>';
            }
            echo '</td>';
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

    public static function render_prospects_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        self::process_lead_actions();
        $leads = array_values(array_filter(self::get_leads(), function ($l) {
            return empty($l['wants_rdv']);
        }));
        $filters = self::current_lead_filters();
        $leads = self::filter_leads($leads, $filters);
        echo '<div class="wrap"><h1>Liste des prospects</h1>';
        self::render_leads_filters_form('azsa-prospects', $filters);
        self::render_leads_table($leads);
        echo '</div>';
    }

    public static function render_rdv_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        self::process_lead_actions();
        $leads = array_values(array_filter(self::get_leads(), function ($l) {
            return !empty($l['wants_rdv']) || (($l['intent'] ?? '') === 'rdv');
        }));
        $filters = self::current_lead_filters();
        $leads = self::filter_leads($leads, $filters);
        echo '<div class="wrap"><h1>Liste des RDV</h1>';
        self::render_leads_filters_form('azsa-rdv', $filters);
        self::render_leads_table($leads);
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
        $first_name = sanitize_text_field((string) $request->get_param('first_name'));
        $last_name = sanitize_text_field((string) $request->get_param('last_name'));
        $email = sanitize_email((string) $request->get_param('email'));
        $phone = sanitize_text_field((string) $request->get_param('phone'));
        $transcript = (string) $request->get_param('transcript');
        $page_url = esc_url_raw((string) $request->get_param('page_url'));
        $intent = sanitize_text_field((string) $request->get_param('intent'));
        $wants_rdv = (bool) $request->get_param('wants_rdv');

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

        $settings = self::get_settings();
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
                'page_url' => $page_url,
                'transcript' => $transcript,
                'logo_url' => $logo_url,
            ));
            self::send_html_mail($admin_email, '[Lead] ' . $ref . ' - ' . $email, $admin_html, $customer_text);
        }

        return new WP_REST_Response(array('ok' => true, 'ref' => $ref), 200);
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
        $page = esc_url((string) ($data['page_url'] ?? ''));
        $transcript = nl2br(esc_html((string) ($data['transcript'] ?? 'Aucun message enregistré.')));

        $first_display = $first !== '' ? $first : 'Non renseigné';
        $last_display = $last !== '' ? $last : 'Non renseigné';
        $phone_display = $phone !== '' ? $phone : 'Non renseigné';
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
