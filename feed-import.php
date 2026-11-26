<?php
/**
 * Plugin Name: Feed Import
 * Description: Import produktów z Google Merchant Center XML do prywatnego katalogu WordPressa.
 * Version: 1.0.0
 * Requires at least: 6.2
 * Requires PHP: 8.0
 */

defined('ABSPATH') || exit;

final class MG_Feed_Import {
    private const POST_TYPE = 'mg_feed_product';
    private const TAXONOMY = 'mg_feed_category';
    private const TERM = 'importowane-produkty';
    private const OPTION = 'mg_feed_import_url';
    private const DEFAULT_URL = 'https://magento.mgprojekt.com.pl/media/feed/gmc.xml';
    private const MAX_BYTES = 16 * 1024 * 1024;
    private const MAX_PRODUCTS = 5000;
    private const GOOGLE_NS = 'http://base.google.com/ns/1.0';

    private const FIELDS = [
        'id' => 'Identyfikator w feedzie',
        'price' => 'Cena',
        'sale_price' => 'Cena promocyjna',
        'availability' => 'Dostępność',
        'brand' => 'Marka',
        'gtin' => 'GTIN',
        'mpn' => 'MPN',
        'condition' => 'Stan',
        'product_type' => 'Typ produktu w feedzie',
        'google_product_category' => 'Kategoria Google',
        'link' => 'Adres produktu',
        'image_link' => 'Adres zdjęcia',
    ];

    public static function boot(): void {
        add_action('init', [self::class, 'register_content']);
        add_action('admin_menu', [self::class, 'add_menu']);
        add_action('admin_post_mg_feed_import', [self::class, 'handle_import']);
        add_action('add_meta_boxes_' . self::POST_TYPE, [self::class, 'add_details_box']);
    }

    public static function register_content(): void {
        register_post_type(self::POST_TYPE, [
            'labels' => [
                'name' => 'Produkty z feeda',
                'singular_name' => 'Produkt z feeda',
                'edit_item' => 'Edytuj produkt z feeda',
            ],
            'public' => false,
            'publicly_queryable' => false,
            'show_ui' => true,
            'show_in_rest' => false,
            'rewrite' => false,
            'query_var' => false,
            'supports' => ['title', 'editor'],
            'map_meta_cap' => true,
            'capabilities' => ['create_posts' => 'do_not_allow'],
            'menu_icon' => 'dashicons-products',
        ]);

        register_taxonomy(self::TAXONOMY, self::POST_TYPE, [
            'label' => 'Kategorie produktów z feeda',
            'hierarchical' => true,
            'public' => false,
            'publicly_queryable' => false,
            'show_ui' => true,
            'show_admin_column' => true,
            'show_in_rest' => false,
            'rewrite' => false,
            'query_var' => false,
        ]);
    }

    public static function add_menu(): void {
        add_submenu_page(
            'edit.php?post_type=' . self::POST_TYPE,
            'Import XML',
            'Import XML',
            'manage_options',
            'mg-feed-import',
            [self::class, 'render_page']
        );
    }

    public static function render_page(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        $url = get_option(self::OPTION, self::DEFAULT_URL);
        ?>
        <div class="wrap">
            <h1>Import produktów z XML</h1>
            <?php
            if (isset($_GET['imported'], $_GET['skipped'], $_GET['failed'])) {
                $imported = absint($_GET['imported']);
                $skipped = absint($_GET['skipped']);
                $failed = absint($_GET['failed']);
                $notice = $failed || $skipped ? 'notice-warning' : 'notice-success';
                ?>
                <div class="notice <?php echo esc_attr($notice); ?>"><p>
                    <?php echo esc_html(sprintf(
                        'Zapisano lub zaktualizowano: %d. Pominięto bez ID lub tytułu: %d. Błędy zapisu: %d.',
                        $imported,
                        $skipped,
                        $failed
                    )); ?>
                </p></div>
                <?php
            }
            ?>
            <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post">
                <input type="hidden" name="action" value="mg_feed_import">
                <?php wp_nonce_field('mg_feed_import'); ?>
                <p><label for="mg-feed-url">Adres feeda Google Merchant Center (XML)</label></p>
                <input id="mg-feed-url" class="large-text" name="feed_url" type="url"
                    value="<?php echo esc_attr($url); ?>" required>
                <?php submit_button('Importuj produkty'); ?>
            </form>
        </div>
        <?php
    }

    public static function add_details_box(): void {
        add_meta_box('mg-feed-details', 'Dane z feeda', [self::class, 'render_details'], self::POST_TYPE);
    }

    public static function render_details(WP_Post $post): void {
        echo '<dl>';
        foreach (self::FIELDS as $field => $label) {
            $value = get_post_meta($post->ID, '_mg_feed_' . $field, true);
            if ($value !== '') {
                printf('<dt><strong>%s</strong></dt><dd>%s</dd>', esc_html($label), esc_html($value));
            }
        }
        echo '</dl>';
    }

    public static function handle_import(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Import wymaga uprawnień administratora.', '', ['response' => 403]);
        }
        check_admin_referer('mg_feed_import');

        $raw_url = $_POST['feed_url'] ?? '';
        $url = is_string($raw_url) ? esc_url_raw(trim(wp_unslash($raw_url)), ['https', 'http']) : '';
        if ($url === '' || !wp_http_validate_url($url)) {
            wp_die('Podaj poprawny, publicznie dostępny adres HTTP lub HTTPS.', '', ['back_link' => true]);
        }

        update_option(self::OPTION, $url, false);
        $result = self::import($url);
        if (is_wp_error($result)) {
            wp_die(esc_html($result->get_error_message()), 'Import XML', ['back_link' => true]);
        }

        $page = admin_url('edit.php?post_type=' . self::POST_TYPE . '&page=mg-feed-import');
        wp_safe_redirect(add_query_arg($result, $page));
        exit;
    }

    private static function import(string $url) {
        global $wpdb;

        if (!class_exists(DOMDocument::class)) {
            return new WP_Error('mg_feed_dom', 'Import wymaga rozszerzenia DOM w PHP.');
        }

        $response = wp_safe_remote_get($url, [
            'timeout' => 45,
            'redirection' => 3,
            'limit_response_size' => self::MAX_BYTES + 1,
        ]);
        if (is_wp_error($response)) {
            return new WP_Error('mg_feed_download', 'Nie udało się pobrać feeda. ' . $response->get_error_message());
        }

        $status = wp_remote_retrieve_response_code($response);
        if ($status !== 200) {
            return new WP_Error('mg_feed_http', sprintf('Serwer feeda zwrócił HTTP %s.', $status));
        }

        $body = wp_remote_retrieve_body($response);
        if ($body === '' || strlen($body) > self::MAX_BYTES) {
            return new WP_Error('mg_feed_size', 'Feed jest pusty lub przekracza 16 MiB.');
        }

        $items = self::parse($body);
        if (is_wp_error($items)) {
            return $items;
        }

        $lock = 'mg_feed_' . md5(DB_NAME . '|' . $wpdb->posts);
        if ((string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $lock)) !== '1') {
            return new WP_Error('mg_feed_lock', 'Nie udało się uzyskać blokady importu. Sprawdź, czy trwa inny import.');
        }

        try {
            return self::save_items($items, $url);
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        }
    }

    private static function parse(string $body) {
        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument();

        try {
            $loaded = $document->loadXML($body, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if (!$loaded || $document->doctype !== null) {
            return new WP_Error('mg_feed_xml', 'Feed musi być poprawnym XML bez deklaracji DOCTYPE.');
        }

        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('g', self::GOOGLE_NS);
        $nodes = $xpath->query('/rss/channel/item');
        if (!$nodes->length) {
            return new WP_Error('mg_feed_items', 'Nie znaleziono produktów w strukturze RSS rss/channel/item.');
        }

        if ($nodes->length > self::MAX_PRODUCTS) {
            return new WP_Error('mg_feed_limit', 'Feed przekracza limit 5000 pozycji na import.');
        }

        $items = [];
        foreach ($nodes as $node) {
            $item = [];
            foreach (array_merge(['title', 'description'], array_keys(self::FIELDS)) as $field) {
                $value = $xpath->evaluate('string(g:' . $field . ')', $node);
                $item[$field] = trim($value !== '' ? $value : $xpath->evaluate('string(' . $field . ')', $node));
            }
            $items[] = $item;
        }

        return $items;
    }

    private static function save_items(array $items, string $url) {
        $term = term_exists(self::TERM, self::TAXONOMY);
        if (!$term) {
            $term = wp_insert_term('Importowane produkty', self::TAXONOMY, ['slug' => self::TERM]);
        }
        if (is_wp_error($term)) {
            return $term;
        }

        $term_id = (int) $term['term_id'];
        $counts = ['imported' => 0, 'skipped' => 0, 'failed' => 0];

        foreach ($items as $item) {
            $title = sanitize_text_field($item['title']);
            if ($item['id'] === '' || $title === '') {
                ++$counts['skipped'];
                continue;
            }

            $key = hash('sha256', $url . "\0" . $item['id']);
            $existing = get_posts([
                'post_type' => self::POST_TYPE,
                'post_status' => ['private', 'publish', 'draft', 'pending', 'future'],
                'posts_per_page' => 1,
                'fields' => 'ids',
                'meta_key' => '_mg_feed_key',
                'meta_value' => $key,
                'no_found_rows' => true,
            ]);

            $post = [
                'post_type' => self::POST_TYPE,
                'post_status' => 'private',
                'post_title' => $title,
                'post_content' => wp_kses_post($item['description']),
            ];
            if ($existing) {
                $post['ID'] = $existing[0];
            }

            $post_id = wp_insert_post(wp_slash($post), true);
            if (is_wp_error($post_id) || !$post_id) {
                ++$counts['failed'];
                continue;
            }

            $assigned = wp_set_object_terms($post_id, [$term_id], self::TAXONOMY, true);
            if (is_wp_error($assigned)) {
                if (!$existing) {
                    wp_delete_post($post_id, true);
                }
                ++$counts['failed'];
                continue;
            }

            update_post_meta($post_id, '_mg_feed_key', $key);
            foreach (self::FIELDS as $field => $label) {
                $value = $item[$field];
                $value = in_array($field, ['link', 'image_link'], true)
                    ? esc_url_raw($value, ['https', 'http'])
                    : sanitize_text_field($value);
                if ($value === '') {
                    delete_post_meta($post_id, '_mg_feed_' . $field);
                } else {
                    update_post_meta($post_id, '_mg_feed_' . $field, wp_slash($value));
                }
            }
            ++$counts['imported'];
        }

        return $counts;
    }
}

MG_Feed_Import::boot();
