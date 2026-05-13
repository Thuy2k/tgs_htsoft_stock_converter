<?php

if (!defined('ABSPATH')) {
    exit;
}

class TGS_HTSoft_Stock_Converter
{
    const VIEW_SLUG = 'products-htsoft-converter';
    const NONCE_ACTION = 'tgs_htsoft_converter_nonce';

    public static function init()
    {
        $instance = new self();
        $instance->hooks();
    }

    private function hooks()
    {
        add_filter('tgs_shop_dashboard_routes', [$this, 'register_route']);
        add_action('tgs_shop_product_menu', [$this, 'render_product_submenu'], 20, 1);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);

        add_action('wp_ajax_tgs_htsoft_converter_search_products', [$this, 'ajax_search_products']);
        add_action('wp_ajax_tgs_htsoft_converter_list_mappings', [$this, 'ajax_list_mappings']);
        add_action('wp_ajax_tgs_htsoft_converter_save_mapping', [$this, 'ajax_save_mapping']);
        add_action('wp_ajax_tgs_htsoft_converter_get_mapping', [$this, 'ajax_get_mapping']);
        add_action('wp_ajax_tgs_htsoft_converter_get_mapping_by_sku', [$this, 'ajax_get_mapping_by_sku']);
        add_action('wp_ajax_tgs_htsoft_converter_get_mappings_by_skus', [$this, 'ajax_get_mappings_by_skus']);
        add_action('wp_ajax_tgs_htsoft_converter_export_mappings_json', [$this, 'ajax_export_mappings_json']);
        add_action('wp_ajax_tgs_htsoft_converter_import_mappings_json', [$this, 'ajax_import_mappings_json']);
    }

    public function register_route($routes)
    {
        $routes[self::VIEW_SLUG] = [
            'Quy đổi tồn HTSoft',
            TGS_HTSOFT_CONVERTER_DIR . 'admin/views/page-htsoft-stock-converter.php',
        ];

        return $routes;
    }

    public function render_product_submenu($current_view)
    {
        $url = admin_url('admin.php?page=tgs-shop-management&view=' . self::VIEW_SLUG);
        $active = ($current_view === self::VIEW_SLUG) ? 'active' : '';

        echo '<li><a href="' . esc_url($url) . '" class="' . esc_attr($active) . '">'
            . '<i class="bx bx-transfer"></i>Quy đổi tồn HTSoft</a></li>';
    }

    public function enqueue_assets($hook)
    {
        if ($hook !== 'toplevel_page_tgs-shop-management') {
            return;
        }

        $view = isset($_GET['view']) ? sanitize_text_field(wp_unslash($_GET['view'])) : '';
        if ($view !== self::VIEW_SLUG) {
            return;
        }

        wp_enqueue_style(
            'tgs-htsoft-converter-css',
            TGS_HTSOFT_CONVERTER_URL . 'assets/css/htsoft-converter.css',
            [],
            filemtime(TGS_HTSOFT_CONVERTER_DIR . 'assets/css/htsoft-converter.css')
        );

        // ZXing + tgs-barcode-scanner được load trực tiếp trong view PHP (như hsd-checker)
        // để đảm bảo đúng thứ tự và TGSBarcodeScanner luôn sẵn sàng trước htsoft-converter.js

        wp_enqueue_script(
            'tgs-xlsx-lib',
            'https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js',
            [],
            '0.18.5',
            true
        );

        wp_enqueue_script(
            'tgs-htsoft-converter-js',
            TGS_HTSOFT_CONVERTER_URL . 'assets/js/htsoft-converter.js',
            ['jquery', 'tgs-xlsx-lib'],
            filemtime(TGS_HTSOFT_CONVERTER_DIR . 'assets/js/htsoft-converter.js'),
            true
        );

        wp_localize_script('tgs-htsoft-converter-js', 'TGSHTSoftConverterConfig', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce(self::NONCE_ACTION),
            'currentView' => self::VIEW_SLUG,
        ]);
    }

    private static function check_permission()
    {
        if (
            !current_user_can('manage_options') &&
            !current_user_can('manage_woocommerce') &&
            !current_user_can('edit_posts')
        ) {
            wp_send_json_error(['message' => 'Bạn không có quyền thao tác.'], 403);
        }
    }

    private static function check_nonce()
    {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');
    }

    private static function success($data = [], $message = '')
    {
        if ($message !== '') {
            $data['message'] = $message;
        }
        wp_send_json_success($data);
    }

    private static function error($message = 'Có lỗi xảy ra', $code = 400)
    {
        wp_send_json_error(['message' => $message], $code);
    }

    private static function table_mapping()
    {
        global $wpdb;
        return $wpdb->base_prefix . 'global_htsoft_stock_convert';
    }

    private static function table_product()
    {
        global $wpdb;
        if (defined('TGS_TABLE_LOCAL_PRODUCT_NAME')) {
            return TGS_TABLE_LOCAL_PRODUCT_NAME;
        }
        return $wpdb->prefix . 'local_product_name';
    }

    private static function parse_positive_decimal($value, $default = 1)
    {
        $value = str_replace(',', '.', (string) $value);
        $num = is_numeric($value) ? (float) $value : (float) $default;
        if ($num <= 0) {
            $num = (float) $default;
        }
        return $num;
    }

    private static function format_ratio_text($value)
    {
        $number = self::parse_positive_decimal($value, 1);

        if ((float) (int) $number === (float) $number) {
            return (string) (int) $number;
        }

        return rtrim(rtrim(number_format($number, 3, '.', ''), '0'), '.');
    }

    private static function build_default_note($to_htsoft)
    {
        return '1 đơn vị bên TGS tương ứng ' . self::format_ratio_text($to_htsoft) . ' đơn vị bên HTSoft';
    }

    private static function get_mapping_row_by_where($where_sql, array $params)
    {
        global $wpdb;

        $mapping_table = self::table_mapping();
        $product_table = self::table_product();

                $sql = "SELECT
                                m.global_htsoft_stock_convert_id,
                                m.global_product_sku,
                                m.convert_from_tgs,
                                m.convert_to_htsoft,
                                m.convert_note,
                                p.local_product_name,
                                p.local_product_barcode_main,
                                p.local_product_unit,
                                p.local_product_quantity_no_tracking_logs,
                                p.local_product_quantity_no_tracking
                        FROM {$mapping_table} m
                        LEFT JOIN {$product_table} p
                                ON BINARY p.local_product_sku = m.global_product_sku
                             AND (p.is_deleted = 0 OR p.is_deleted IS NULL)
                        WHERE {$where_sql}
                            AND (m.is_deleted = 0 OR m.is_deleted IS NULL)
                        LIMIT 1";

        $prepared = $wpdb->prepare($sql, ...$params);
        return $wpdb->get_row($prepared, ARRAY_A);
    }

    public function ajax_search_products()
    {
        self::check_permission();
        self::check_nonce();

        global $wpdb;

        $keyword = isset($_POST['keyword']) ? wp_unslash($_POST['keyword']) : '';
        $keyword = sanitize_text_field($keyword);

        if (mb_strlen($keyword) < 1) {
            self::success(['products' => []]);
            return;
        }

        $table = self::table_product();
        $like = '%' . $wpdb->esc_like($keyword) . '%';

        $tokens = preg_split('/\s+/u', trim($keyword));
        $tokens = array_values(array_filter(array_map('trim', (array) $tokens), function ($token) {
            return $token !== '';
        }));
        if (count($tokens) > 8) {
            $tokens = array_slice($tokens, 0, 8);
        }

        $name_conditions = [];
        $values = [];
        foreach ($tokens as $token) {
            $name_conditions[] = 'local_product_name LIKE %s';
            $values[] = '%' . $wpdb->esc_like($token) . '%';
        }

        if (empty($name_conditions)) {
            $name_conditions[] = 'local_product_name LIKE %s';
            $values[] = $like;
        }

        $name_where = implode(' AND ', $name_conditions);
        $sql = "SELECT
                local_product_name,
                local_product_sku,
                local_product_barcode_main,
                local_product_unit,
                local_product_quantity_no_tracking_logs,
                local_product_quantity_no_tracking
            FROM {$table}
            WHERE (is_deleted = 0 OR is_deleted IS NULL)
              AND (local_product_is_tracking = 0 OR local_product_is_tracking IS NULL)
              AND (
                ({$name_where})
                OR local_product_barcode_main LIKE %s
                OR local_product_sku LIKE %s
              )
            ORDER BY local_product_name ASC
            LIMIT 30";

        $values[] = $like;
        $values[] = $like;

        $sql = $wpdb->prepare($sql, ...$values);

        $rows = $wpdb->get_results($sql, ARRAY_A);
        self::success(['products' => $rows ?: []]);
    }

    public function ajax_list_mappings()
    {
        self::check_permission();
        self::check_nonce();

        global $wpdb;

        $mapping_table = self::table_mapping();
        $product_table = self::table_product();

        $keyword = isset($_POST['keyword']) ? sanitize_text_field(wp_unslash($_POST['keyword'])) : '';
        $limit = isset($_POST['limit']) ? max(1, min(200, (int) $_POST['limit'])) : 100;

        if ($keyword !== '') {
            $like = '%' . $wpdb->esc_like($keyword) . '%';
            $sql = $wpdb->prepare(
                "SELECT
                    m.global_htsoft_stock_convert_id,
                    m.global_product_sku,
                    m.convert_from_tgs,
                    m.convert_to_htsoft,
                    m.convert_note,
                    m.updated_at,
                    p.local_product_name,
                    p.local_product_unit,
                    p.local_product_quantity_no_tracking_logs
                                FROM {$mapping_table} m
                                LEFT JOIN {$product_table} p
                                        ON BINARY p.local_product_sku = m.global_product_sku
                                     AND (p.is_deleted = 0 OR p.is_deleted IS NULL)
                WHERE (m.is_deleted = 0 OR m.is_deleted IS NULL)
                  AND (
                                        m.global_product_sku LIKE %s
                    OR p.local_product_name LIKE %s
                    OR p.local_product_barcode_main LIKE %s
                  )
                ORDER BY m.updated_at DESC, m.global_htsoft_stock_convert_id DESC
                LIMIT %d",
                $like,
                $like,
                $like,
                $limit
            );
        } else {
            $sql = $wpdb->prepare(
                "SELECT
                    m.global_htsoft_stock_convert_id,
                    m.global_product_sku,
                    m.convert_from_tgs,
                    m.convert_to_htsoft,
                    m.convert_note,
                    m.updated_at,
                    p.local_product_name,
                    p.local_product_unit,
                    p.local_product_quantity_no_tracking_logs
                FROM {$mapping_table} m
                LEFT JOIN {$product_table} p
                    ON BINARY p.local_product_sku = m.global_product_sku
                   AND (p.is_deleted = 0 OR p.is_deleted IS NULL)
                WHERE (m.is_deleted = 0 OR m.is_deleted IS NULL)
                ORDER BY m.updated_at DESC, m.global_htsoft_stock_convert_id DESC
                LIMIT %d",
                $limit
            );
        }

        $rows = $wpdb->get_results($sql, ARRAY_A);
        self::success(['mappings' => $rows ?: []]);
    }

    public function ajax_get_mapping()
    {
        self::check_permission();
        self::check_nonce();

        global $wpdb;

        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        if ($id <= 0) {
            self::error('ID không hợp lệ.');
            return;
        }

        $row = self::get_mapping_row_by_where('m.global_htsoft_stock_convert_id = %d', [$id]);
        if (!$row) {
            self::error('Không tìm thấy cấu hình.');
            return;
        }
        // Đảm bảo luôn trả về số lượng tham khảo, nếu không có thì là 0
        if (!isset($row['local_product_quantity_no_tracking']) || $row['local_product_quantity_no_tracking'] === null) {
            $row['local_product_quantity_no_tracking'] = 0;
        }
        self::success(['mapping' => $row]);
    }

    public function ajax_get_mapping_by_sku()
    {
        self::check_permission();
        self::check_nonce();

        $sku = isset($_POST['global_product_sku']) ? sanitize_text_field(wp_unslash($_POST['global_product_sku'])) : '';
        if ($sku === '') {
            self::error('Thiếu SKU sản phẩm.');
            return;
        }

        $row = self::get_mapping_row_by_where('m.global_product_sku = %s', [$sku]);
        if ($row && (!isset($row['local_product_quantity_no_tracking']) || $row['local_product_quantity_no_tracking'] === null)) {
            $row['local_product_quantity_no_tracking'] = 0;
        }
        self::success(['mapping' => $row ?: null]);
    }

    public function ajax_save_mapping()
    {
        self::check_permission();
        self::check_nonce();

        global $wpdb;

        $mapping_table = self::table_mapping();

        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        $sku = isset($_POST['global_product_sku']) ? sanitize_text_field(wp_unslash($_POST['global_product_sku'])) : '';
        $to_htsoft = self::parse_positive_decimal($_POST['convert_to_htsoft'] ?? 1, 1);
        $note = isset($_POST['convert_note']) ? sanitize_textarea_field(wp_unslash($_POST['convert_note'])) : '';

        if ($sku === '') {
            self::error('Thiếu SKU sản phẩm.');
            return;
        }

        if ($note === '') {
            $note = self::build_default_note($to_htsoft);
        }

        $now = current_time('mysql');
        $user_id = get_current_user_id();

        $data = [
            'global_product_sku' => $sku,
            'convert_from_tgs' => 1,
            'convert_to_htsoft' => $to_htsoft,
            'convert_note' => $note,
            'user_id' => $user_id,
            'is_deleted' => 0,
            'deleted_at' => null,
            'updated_at' => $now,
        ];

        if ($id > 0) {
            $updated = $wpdb->update(
                $mapping_table,
                $data,
                ['global_htsoft_stock_convert_id' => $id],
                ['%s', '%f', '%f', '%s', '%d', '%d', '%s', '%s'],
                ['%d']
            );

            if ($updated === false) {
                self::error('Không thể cập nhật cấu hình.');
                return;
            }

            self::success(['id' => $id], 'Đã cập nhật cấu hình quy đổi.');
            return;
        }

        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT global_htsoft_stock_convert_id
             FROM {$mapping_table}
             WHERE global_product_sku = %s
             LIMIT 1",
            $sku
        ));

        if ($existing) {
            $updated = $wpdb->update(
                $mapping_table,
                $data,
                ['global_htsoft_stock_convert_id' => (int) $existing->global_htsoft_stock_convert_id],
                ['%s', '%f', '%f', '%s', '%d', '%d', '%s', '%s'],
                ['%d']
            );

            if ($updated === false) {
                self::error('Không thể cập nhật cấu hình tồn tại.');
                return;
            }

            self::success(['id' => (int) $existing->global_htsoft_stock_convert_id], 'Đã cập nhật cấu hình quy đổi.');
            return;
        }

        $data['created_at'] = $now;
        $inserted = $wpdb->insert(
            $mapping_table,
            $data,
            ['%s', '%f', '%f', '%s', '%d', '%d', '%s', '%s', '%s']
        );

        if (!$inserted) {
            self::error('Không thể tạo cấu hình quy đổi.');
            return;
        }

        self::success(['id' => (int) $wpdb->insert_id], 'Đã tạo cấu hình quy đổi.');
    }

    public function ajax_export_mappings_json()
    {
        self::check_permission();
        self::check_nonce();

        global $wpdb;

        $mapping_table = self::table_mapping();
        $rows = $wpdb->get_results(
            "SELECT global_product_sku, convert_from_tgs, convert_to_htsoft, convert_note, updated_at
             FROM {$mapping_table}
             WHERE (is_deleted = 0 OR is_deleted IS NULL)
             ORDER BY updated_at DESC, global_htsoft_stock_convert_id DESC",
            ARRAY_A
        );

        // Format các trường convert_from_tgs, convert_to_htsoft thành số nguyên (không có phần thập phân)
        foreach ($rows as &$row) {
            $row['convert_from_tgs'] = (string)((int)floatval($row['convert_from_tgs']));
            $row['convert_to_htsoft'] = (string)((int)floatval($row['convert_to_htsoft']));
        }
        unset($row);

        self::success([
            'exported_at' => current_time('mysql'),
            'count' => count($rows),
            'mappings' => $rows ?: [],
        ]);
    }

    public function ajax_import_mappings_json()
    {
        self::check_permission();
        self::check_nonce();

        if (empty($_FILES['json_file']['tmp_name'])) {
            self::error('Chưa có file JSON để import.');
            return;
        }

        $file_name = isset($_FILES['json_file']['name']) ? (string) $_FILES['json_file']['name'] : '';
        if ($file_name !== '' && !preg_match('/\.json$/i', $file_name)) {
            self::error('File import phải là định dạng JSON.');
            return;
        }

        $json = file_get_contents($_FILES['json_file']['tmp_name']);
        if ($json === false || trim($json) === '') {
            self::error('Không đọc được file JSON.');
            return;
        }

        // Strip UTF-8 BOM if present (EF BB BF)
        if (substr($json, 0, 3) === "\xEF\xBB\xBF") {
            $json = substr($json, 3);
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            self::error('Nội dung JSON không hợp lệ.');
            return;
        }

        $items = isset($decoded['mappings']) && is_array($decoded['mappings']) ? $decoded['mappings'] : $decoded;
        if (!is_array($items) || empty($items)) {
            self::error('File JSON không có dữ liệu cấu hình để import.');
            return;
        }

        global $wpdb;

        $mapping_table = self::table_mapping();
        $now = current_time('mysql');
        $user_id = get_current_user_id();
        $created = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($items as $item) {
            if (!is_array($item)) {
                $skipped++;
                continue;
            }

            $sku = isset($item['global_product_sku']) ? sanitize_text_field((string) $item['global_product_sku']) : '';
            if ($sku === '') {
                $skipped++;
                continue;
            }

            $to_htsoft = self::parse_positive_decimal($item['convert_to_htsoft'] ?? 1, 1);
            $note = isset($item['convert_note']) ? sanitize_textarea_field((string) $item['convert_note']) : '';
            if ($note === '') {
                $note = self::build_default_note($to_htsoft);
            }

            $data = [
                'global_product_sku' => $sku,
                'convert_from_tgs' => 1,
                'convert_to_htsoft' => $to_htsoft,
                'convert_note' => $note,
                'user_id' => $user_id,
                'is_deleted' => 0,
                'deleted_at' => null,
                'updated_at' => $now,
            ];

            $existing_id = $wpdb->get_var($wpdb->prepare(
                "SELECT global_htsoft_stock_convert_id
                 FROM {$mapping_table}
                 WHERE global_product_sku = %s
                 LIMIT 1",
                $sku
            ));

            if ($existing_id) {
                $result = $wpdb->update(
                    $mapping_table,
                    $data,
                    ['global_htsoft_stock_convert_id' => (int) $existing_id],
                    ['%s', '%f', '%f', '%s', '%d', '%d', '%s', '%s'],
                    ['%d']
                );

                if ($result !== false) {
                    $updated++;
                } else {
                    $skipped++;
                }

                continue;
            }

            $data['created_at'] = $now;
            $result = $wpdb->insert(
                $mapping_table,
                $data,
                ['%s', '%f', '%f', '%s', '%d', '%d', '%s', '%s', '%s']
            );

            if ($result) {
                $created++;
            } else {
                $skipped++;
            }
        }

        self::success([
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
        ], 'Đã import cấu hình JSON.');
    }

    public function ajax_get_mappings_by_skus()
    {
        self::check_permission();
        self::check_nonce();

        global $wpdb;

        $skus_json = isset($_POST['skus']) ? wp_unslash($_POST['skus']) : '[]';
        $skus = json_decode($skus_json, true);

        if (!is_array($skus)) {
            self::error('Danh sách SKU không hợp lệ.');
            return;
        }

        $skus = array_values(array_unique(array_filter(array_map(function ($sku) {
            return (string) $sku;
        }, $skus), function ($sku) {
            return $sku !== '';
        })));

        if (empty($skus)) {
            self::success(['mappings' => []]);
            return;
        }

        $mapping_table = self::table_mapping();

                $placeholders = implode(',', array_fill(0, count($skus), '%s'));
        $sql = "SELECT global_product_sku, convert_from_tgs, convert_to_htsoft, convert_note
                FROM {$mapping_table}
                WHERE (is_deleted = 0 OR is_deleted IS NULL)
                                    AND global_product_sku IN ({$placeholders})";

                $prepared = $wpdb->prepare($sql, ...$skus);
        $rows = $wpdb->get_results($prepared, ARRAY_A);

        $map = [];
        if ($rows) {
            foreach ($rows as $row) {
                $map[$row['global_product_sku']] = [
                    'convert_from_tgs' => (float) ($row['convert_from_tgs'] ?? 1),
                    'convert_to_htsoft' => (float) ($row['convert_to_htsoft'] ?? 1),
                    'convert_note' => (string) ($row['convert_note'] ?? ''),
                ];
            }
        }

        self::success(['mappings' => $map]);
    }
}
