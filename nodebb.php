<?php
if (!defined('ABSPATH') || !defined('NODEBB_API_USER_ID'))  exit;

require_once('external/php-jwt/src/JWT.php');
use Firebase\JWT\JWT;

class Castors_NodeBB {
    public static function init() {
        add_action('wp_login', [__CLASS__, 'login'], 10, 2);
        add_action('wp_logout', [__CLASS__, 'logout']);
        add_action('user_register', [__CLASS__, 'profile_saved'], 99);
        add_action('profile_update', [__CLASS__, 'profile_saved'], 99);
    }
    
    public static function admin_init() {

        add_action('wp_nav_menu_item_custom_fields', [__CLASS__, 'menu_item_fields'], 5);
        add_action('wp_update_nav_menu_item', [__CLASS__, 'menu_item_update'], 10, 2);
        add_action('wp_update_nav_menu', [__CLASS__, 'menu_update'], 10, 2);

        add_settings_section(
            'castors-nbb-section',
            __("Authentification sur le forum NodeBB", 'castors'),
            [__CLASS__, 'section_desc'],
            'general'
        );

        add_settings_field(
            'castors_nbb_secret',
            __("JWT Secret", 'castors'),
            [__CLASS__, 'secret'],
            'general',
            'castors-nbb-section',
            [ 'label_for' => 'castors_nbb_secret' ]
        );

        add_settings_field(
            'castors_nbb_api_token',
            __("Jeton API", 'castors'),
            [__CLASS__, 'api_token'],
            'general',
            'castors-nbb-section',
            [ 'label_for' => 'castors_nbb_api_token' ]
        );

        register_setting('general', 'castors_nbb_secret');
        register_setting('general', 'castors_nbb_api_token');

        add_filter('groups_admin_groups_add_form_after_fields', [__CLASS__, 'add_group']);
        add_filter('groups_admin_groups_edit_form_after_fields', [__CLASS__, 'edit_group']);
    }

    public static function login($user_login, $user) {
        $expiration = time() + 14 * DAY_IN_SECONDS;
        $payload = ['id' => $user->ID, 'username' => $user->user_login];
        $secret = get_option('castors_nbb_secret');
        $jwt = JWT::encode($payload, $secret, 'HS256');
        setcookie('wp_nbb_login', $jwt, $expiration, '/', 'les-castors.fr', true, true);
    }

    public static function logout($user_id)
    {
        setcookie('wp_nbb_login', '', time() - 3600, '/', 'les-castors.fr', true);
    }

    public static function profile_saved($id) {
        $user = get_user_by('id', $id);
        static::updateAccount($user);
        static::updateGroups($user);
    }

    public static function add_group($output) {
        $output .= '<p class="description beware">';
        $output .= esc_html__("ATTENTION : pensez à créer un groupe avec le même nom sur le forum NodeBB avant d'ajouter des membres aun nouveau groupe.", 'castors');
        $output .= '</p>';
        return $output;
    }

    public static function edit_group($output) {
        $output .= '<p class="description beware">';
        $output .= esc_html__("ATTENTION : Si un groupe avec le même nom existe sur le forum NodeBB, pensez à le mettre à jour également.", 'castors');
        $output .= '</p>';
        return $output;
    }

    public static function formatEndpoint($endpoint) {
        return sprintf('%s?ts=%d&_uid=%d', $endpoint, current_time('timestamp'), NODEBB_API_USER_ID);
    }

    public static function parseArgs($args) {
        $token = get_option('castors_nbb_api_token');
        $defaults = [
            'headers' => [
                'Authorization' => "Bearer {$token}",
                'Accept' => 'application/json',
            ],
        ];
        return wp_parse_args($args, $defaults);
    }

    public static function read($endpoint, $args = []) {
        $response = wp_remote_get(static::formatEndpoint($endpoint), static::parseArgs($args));
        if (is_wp_error($response)) {
            return $response;
        }
        if ($response['response']['code'] === 404) {
            return null;
        }
        return json_decode($response['body']);
    }

    public static function create($endpoint, $body, $args = []) {
        if ($body) {
            $args['body'] = $body;
            $args['Content-Type'] = 'application/json';
        }
        $response = wp_remote_post(static::formatEndpoint($endpoint), static::parseArgs($args));
        if (is_wp_error($response)) {
            return $response;
        }
        return json_decode($response['body']);
    }

    public static function update($endpoint, $body, $args = []) {
        $args['method'] = 'PUT';
        if ($body) {
            $args['body'] = $body;
            $args['Content-Type'] = 'application/json';
        }
        $response = wp_remote_request(static::formatEndpoint($endpoint), static::parseArgs($args));
        if (is_wp_error($response)) {
            return $response;
        }
        return json_decode($response['body']);
    }

    public static function delete($endpoint, $args = []) {
        $args['method'] = 'DELETE';
        $response = wp_remote_request(static::formatEndpoint($endpoint), static::parseArgs($args));
        if (is_wp_error($response)) {
            return $response;
        }
        return null;
    }

    public static function getAccountById($id) {
        return static::read(get_site_url() . "/forum/api/v3/users/{$id}");
    }

    public static function getAccountByUsername($username) {
        return static::read(get_site_url() . "/forum/api/user/{$username}");
    }

    public static function updateEmail($user, $nodeBBUser) {
        if ($user->user_email !== $nodeBBUser->email) {
            $result = static::create(get_site_url() . "/forum/api/v3/users/{$nodeBBUser->uid}/emails", ["email" => $user->user_email, "skipConfirmation" => 1]);
            return $result;
        }
        return null;
    }

    public static function createAccount($user) {
        $data = [
            'username' => $user->user_login,
            'fullname' => $user->display_name,
        ];
        return static::create(get_site_url() . "/forum/api/v3/users", $data);
    }

    public static function updateAccount($user) {
        $location_details = $user->castors_location_details;
        $location = json_decode(htmlspecialchars_decode($location_details));
        
        $nodeBBUser = static::getAccountByUsername($user->user_login);

        $data = [
            'fullname' => $user->display_name,
            'website' => $user->user_url ?: '',
            'location' => $location ? $location->value : '',
            'aboutme' => $user->description ?: '',
        ];
        if ($location) {
            $data['fullname'] .= " ({$location->department})";
        }

        if (!$nodeBBUser) {
            $nodeBBUser = static::createAccount($user);
        }

        if (!$nodeBBUser) {
            return null;
        }

        static::updateEmail($user, $nodeBBUser);
        return static::update(get_site_url() . "/forum/api/v3/users/{$nodeBBUser->uid}", $data);
    }

    public static function getGroups() {
        return static::read(get_site_url() . "/forum/api/v3/groups");
    }

    public static function updateGroups($user) {
        if (!class_exists('Groups_User')) {
            // Groups extension is not active
            return null;
        }

        $nodeBBUser = static::getAccountByUsername($user->user_login);
        $groupsUser = new Groups_User($user->ID);
        $nodeBBgroups = static::getGroups()->response->groups;
        $nodeBBgroupNames = array_column($nodeBBgroups, 'name');
        $groupNames = [];

        foreach ($groupsUser->groups as $group) {
            $groupNames[] = $group->group->name;

            if (in_array($group->group->name, $nodeBBUser->groupTitleArray)) {
                // Already in NodeBB group
                continue;
            }

            $index = array_search($group->group->name, $nodeBBgroupNames);
            if ($index === false) {
                // Not a NodeBB group
            }

            // Add user to NodeBB group
            $slug = $nodeBBgroups[$index]->slug;
            static::update(get_site_url() . "/forum/api/v3/groups/{$slug}/membership/{$nodeBBUser->uid}", []);
        }
        
        foreach ($nodeBBUser->groups as $group) {
            if (!in_array($group->name, $groupNames)) {
                // User no longer in group
                static::delete(get_site_url() . "/forum/api/v3/groups/{$group->slug}/membership/{$nodeBBUser->uid}");
            }
        }
    }

    public static function section_desc($args) {
        echo '<p>' . __("<b>JWT Secret</b> doit être le même que celui enregistré dans le plugin <b>Session Sharing</b> du forum.", 'castors') . '</p>'
        . '<p>' . __("<b>Jeton API</b> doit être déclaré dans la section <b>Gestion API</b> de l'administration du forum, associé à un compte administrateur.", 'castors') . '</p>';
    }

    public static function secret() {
        echo '<input id="castors_nbb_secret" type="text" name="castors_nbb_secret" value="' . get_option('castors_nbb_secret') . '" class="regular-text">';
    }

    public static function api_token() {
        echo '<input id="castors_nbb_api_token" type="text" name="castors_nbb_api_token" value="' . get_option('castors_nbb_api_token') . '" class="regular-text">';
    }

    public static function menu_item_fields($item_id) {
        $label = __("Capacité", 'castors');
        $value = get_post_meta($item_id, '_menu_item_capabilities', true);
        $description = esc_html("Liste de capacités séparées par des virgules. Seuls les utilisateurs ayant au moins une de ces capacités verront cet élément de menu. Laisser vide pour l'afficher à tous les utilisateurs.", 'castors');

        echo <<<EOF
            <p class="field-capabilities description description-wide">
                <label for="edit-menu-item-capabilities-{$item_id}">
                    {$label}<br />
                    <input type="text" id="edit-menu-item-capabilities-{$item_id}" class="widefat edit-menu-item-capabilities" name="menu_item_capabilities[{$item_id}]" value="{$value}" />
                    <span class="description">{$description}</span>
                </label>
            </p>
        EOF;
    }

    public static function menu_item_update($menu_id, $item_id) {
        if ($_POST['menu_item_capabilities']) {
            $capabilities = $_POST['menu_item_capabilities'][$item_id] ?? '';
            update_post_meta($item_id, '_menu_item_capabilities', $capabilities);
        }
    }

    public static function menu_update($menu_id, $menu_data = []) {
        //var_dump($menu_id, $menu_data); exit;
    }
}
