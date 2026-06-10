<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class TGS_HTSoft_Stock_Converter
 *
 * Quản lý cấu hình quy đổi DVT (Đơn Vị Tính bán) cho từng SKU.
 *
 * Thiết kế v2 (25/05/2026):
 *   - 1 SKU có thể có NHIỀU cấu hình DVT (Lốc_4, Vỉ_10, Thùng_48…).
 *   - Mỗi cấu hình: (global_product_sku, convert_unit) là unique (case-insensitive cho unit).
 *   - convert_unit = '' → DVT mặc định (dữ liệu cũ, tỷ lệ 1:1 nếu không khai báo).
 *   - POS sử dụng: chọn SKU + DVT → tra bảng → lấy convert_to_htsoft → tính tồn cần trừ.
 *
 * Tab 2 & 3 tạm ngừng — chỉ Tab 1 (Cấu hình quy đổi DVT) đang hoạt động.
 */
class TGS_HTSoft_Stock_Converter
{
    const VIEW_SLUG   = 'products-htsoft-converter';
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

        // ── Tìm kiếm sản phẩm ──────────────────────────────────────────
        add_action('wp_ajax_tgs_htsoft_converter_search_products', [$this, 'ajax_search_products']);

        // ── Cấu hình theo SKU ──────────────────────────────────────────
        add_action('wp_ajax_tgs_htsoft_converter_list_configs_by_sku',  [$this, 'ajax_list_configs_by_sku']);
        add_action('wp_ajax_tgs_htsoft_converter_save_mapping',         [$this, 'ajax_save_mapping']);
        add_action('wp_ajax_tgs_htsoft_converter_delete_mapping',       [$this, 'ajax_delete_mapping']);

        // ── Bảng tổng ──────────────────────────────────────────────────
        add_action('wp_ajax_tgs_htsoft_converter_list_mappings',        [$this, 'ajax_list_mappings']);
        add_action('wp_ajax_tgs_htsoft_converter_get_mapping',          [$this, 'ajax_get_mapping']);

        // ── POS lookup ─────────────────────────────────────────────────
        add_action('wp_ajax_tgs_htsoft_converter_get_mapping_by_sku',   [$this, 'ajax_get_mapping_by_sku']);
        add_action('wp_ajax_tgs_htsoft_converter_get_mappings_by_skus', [$this, 'ajax_get_mappings_by_skus']);

        // ── Excel import / export ──────────────────────────────────────
        add_action('wp_ajax_tgs_htsoft_converter_export_excel_rows',    [$this, 'ajax_export_excel_rows']);
        add_action('wp_ajax_tgs_htsoft_converter_import_excel_rows',    [$this, 'ajax_import_excel_rows']);
        add_action('wp_ajax_tgs_htsoft_converter_import_price_rows',    [$this, 'ajax_import_price_rows']);

        // ── JSON import / export (backward compat) ─────────────────────
        add_action('wp_ajax_tgs_htsoft_converter_export_mappings_json', [$this, 'ajax_export_mappings_json']);
        add_action('wp_ajax_tgs_htsoft_converter_import_mappings_json', [$this, 'ajax_import_mappings_json']);
    }

    // =========================================================================
    // Route & Menu
    // =========================================================================

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
        $url    = admin_url('admin.php?page=tgs-shop-management&view=' . self::VIEW_SLUG);
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
            'ajaxUrl'     => admin_url('admin-ajax.php'),
            'nonce'       => wp_create_nonce(self::NONCE_ACTION),
            'currentView' => self::VIEW_SLUG,
        ]);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

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
        return defined('TGS_TABLE_GLOBAL_PRODUCT_NAME')
            ? TGS_TABLE_GLOBAL_PRODUCT_NAME
            : $wpdb->base_prefix . 'global_product_name';
    }

    private static function parse_positive_decimal($value, $default = 1)
    {
        $value = str_replace(',', '.', (string) $value);
        $num   = is_numeric($value) ? (float) $value : (float) $default;
        if ($num <= 0) {
            $num = (float) $default;
        }
        return $num;
    }

    private static function parse_optional_decimal($value)
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }

        $raw = str_replace(',', '.', $raw);
        if (!is_numeric($raw)) {
            return null;
        }

        $num = (float) $raw;
        return $num > 0 ? $num : null;
    }

    private static function format_ratio_text($value)
    {
        $number = self::parse_positive_decimal($value, 1);
        if ((float)(int) $number === (float) $number) {
            return (string)(int) $number;
        }
        return rtrim(rtrim(number_format($number, 3, '.', ''), '0'), '.');
    }

    private static function build_default_note($unit, $to_htsoft)
    {
        $unit_label  = ($unit !== '') ? $unit : 'đơn vị';
        $ratio_label = self::format_ratio_text($to_htsoft);
        return '1 ' . $unit_label . ' = ' . $ratio_label . ' đơn vị nhỏ nhất';
    }

    // =========================================================================
    // AJAX: Tìm kiếm sản phẩm (có config_count)
    // =========================================================================

    public function ajax_search_products()
    {
        self::check_permission();
        self::check_nonce();

        global $wpdb;

        $keyword = isset($_POST['keyword']) ? sanitize_text_field(wp_unslash($_POST['keyword'])) : '';
        if (mb_strlen($keyword) < 1) {
            self::success(['products' => []]);
            return;
        }

        $table         = self::table_product();
        $mapping_table = self::table_mapping();
        $like          = '%' . $wpdb->esc_like($keyword) . '%';

        $tokens = preg_split('/\s+/u', trim($keyword));
        $tokens = array_values(array_filter(array_map('trim', (array) $tokens), function ($t) {
            return $t !== '';
        }));
        if (count($tokens) > 8) {
            $tokens = array_slice($tokens, 0, 8);
        }

        $name_conditions = [];
        $values          = [];
        foreach ($tokens as $token) {
            $name_conditions[] = 'p.global_product_name LIKE %s';
            $values[]          = '%' . $wpdb->esc_like($token) . '%';
        }
        if (empty($name_conditions)) {
            $name_conditions[] = 'p.global_product_name LIKE %s';
            $values[]          = $like;
        }
        $name_where = implode(' AND ', $name_conditions);

        $sql = "SELECT
                    p.global_product_name AS local_product_name,
                    p.global_product_sku AS local_product_sku,
                    p.global_product_barcode_main AS local_product_barcode_main,
                    p.global_product_unit AS local_product_unit,
                    0 AS local_product_quantity_no_tracking,
                    COUNT(m.global_htsoft_stock_convert_id) AS config_count
                FROM {$table} p
                LEFT JOIN {$mapping_table} m
                    ON BINARY m.global_product_sku = p.global_product_sku
                   AND (m.is_deleted = 0 OR m.is_deleted IS NULL)
                WHERE (p.is_deleted = 0 OR p.is_deleted IS NULL)
                  AND (p.global_product_is_tracking = 0 OR p.global_product_is_tracking IS NULL)
                  AND (
                    ({$name_where})
                    OR p.global_product_barcode_main LIKE %s
                    OR p.global_product_sku LIKE %s
                  )
                GROUP BY p.global_product_sku
                ORDER BY p.global_product_name ASC
                LIMIT 30";

        $values[] = $like;
        $values[] = $like;

        $sql  = $wpdb->prepare($sql, ...$values);
        $rows = $wpdb->get_results($sql, ARRAY_A);
        self::success(['products' => $rows ?: []]);
    }

    // =========================================================================
    // AJAX: Liệt kê tất cả cấu hình DVT của một SKU
    // =========================================================================

    public function ajax_list_configs_by_sku()
    {
        self::check_permission();
        self::check_nonce();

        global $wpdb;

        $sku = isset($_POST['global_product_sku'])
            ? sanitize_text_field(wp_unslash($_POST['global_product_sku']))
            : '';

        if ($sku === '') {
            self::error('Thiếu SKU sản phẩm.');
            return;
        }

        $mapping_table = self::table_mapping();
        $sql = $wpdb->prepare(
            "SELECT
                global_htsoft_stock_convert_id,
                global_product_sku,
                convert_unit,
                convert_from_tgs,
                convert_to_htsoft,
                convert_note,
                unit_price,
                unit_weight_kg,
                updated_at
             FROM {$mapping_table}
             WHERE BINARY global_product_sku = %s
               AND (is_deleted = 0 OR is_deleted IS NULL)
             ORDER BY convert_unit ASC, global_htsoft_stock_convert_id ASC",
            $sku
        );
        $rows = $wpdb->get_results($sql, ARRAY_A);
        self::success(['configs' => $rows ?: []]);
    }

    // =========================================================================
    // AJAX: Lưu / cập nhật một cấu hình DVT
    // POST: id (0 = tạo mới), global_product_sku, convert_unit, convert_to_htsoft, convert_note
    // =========================================================================

    public function ajax_save_mapping()
    {
        self::check_permission();
        self::check_nonce();

        global $wpdb;

        $mapping_table = self::table_mapping();

        $id        = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        $sku       = isset($_POST['global_product_sku'])
                        ? sanitize_text_field(wp_unslash($_POST['global_product_sku']))
                        : '';
        $unit      = isset($_POST['convert_unit'])
                        ? sanitize_text_field(wp_unslash($_POST['convert_unit']))
                        : '';
        $to_htsoft = self::parse_positive_decimal($_POST['convert_to_htsoft'] ?? 1, 1);
        $note      = isset($_POST['convert_note'])
                        ? sanitize_text_field(wp_unslash($_POST['convert_note']))
                        : '';

        // Giá bán (tuỳ chọn, NULL nếu để trống)
        $raw_price  = isset($_POST['unit_price']) ? trim(wp_unslash($_POST['unit_price'])) : '';
        $unit_price = null;
        if ($raw_price !== '') {
            $cleaned = (float) str_replace(',', '.', $raw_price);
            if ($cleaned >= 0) {
                $unit_price = $cleaned;
            }
        }

        // Khối lượng kg của 1 DVT (tuỳ chọn, NULL nếu để trống)
        $unit_weight_kg = self::parse_optional_decimal($_POST['unit_weight_kg'] ?? '');

        if ($sku === '') {
            self::error('Thiếu SKU sản phẩm.');
            return;
        }
        if ($unit === '' && $id === 0) {
            self::error('Thiếu tên Đơn Vị Tính (DVT).');
            return;
        }

        if ($note === '') {
            $note = self::build_default_note($unit, $to_htsoft);
        }

        $now     = current_time('mysql');
        $user_id = get_current_user_id();

        $data = [
            'global_product_sku' => $sku,
            'convert_unit'       => $unit,
            'convert_from_tgs'   => 1,
            'convert_to_htsoft'  => $to_htsoft,
            'convert_note'       => $note,
            'unit_price'         => $unit_price,
            'unit_weight_kg'     => $unit_weight_kg,
            'user_id'            => $user_id,
            'is_deleted'         => 0,
            'deleted_at'         => null,
            'updated_at'         => $now,
        ];
        $formats = [
            '%s',
            '%s',
            '%f',
            '%f',
            '%s',
            $unit_price !== null ? '%f' : '%s',
            $unit_weight_kg !== null ? '%f' : '%s',
            '%d',
            '%d',
            '%s',
            '%s',
        ];

        // ── CẬP NHẬT theo ID ────────────────────────────────────────────
        if ($id > 0) {
            // Kiểm tra trùng unit với row khác (case-insensitive) trước khi lưu
            $conflict = $wpdb->get_var($wpdb->prepare(
                "SELECT global_htsoft_stock_convert_id
                 FROM {$mapping_table}
                 WHERE BINARY global_product_sku = %s
                   AND convert_unit = %s
                   AND global_htsoft_stock_convert_id <> %d
                   AND (is_deleted = 0 OR is_deleted IS NULL)
                 LIMIT 1",
                $sku,
                $unit,
                $id
            ));
            if ($conflict) {
                self::error('DVT "' . $unit . '" đã tồn tại cho SKU này. Vui lòng dùng tên DVT khác.');
                return;
            }

            $updated = $wpdb->update(
                $mapping_table,
                $data,
                ['global_htsoft_stock_convert_id' => $id],
                $formats,
                ['%d']
            );

            if ($updated === false) {
                self::error('Không thể cập nhật cấu hình.');
                return;
            }

            self::success(['id' => $id], 'Đã cập nhật cấu hình DVT "' . $unit . '".');
            return;
        }

        // ── TẠO MỚI hoặc UPDATE nếu (sku, unit) đã tồn tại ────────────
        // Dùng convert_unit collation unicode_ci → MySQL tự so sánh case-insensitive
        $existing_id = $wpdb->get_var($wpdb->prepare(
            "SELECT global_htsoft_stock_convert_id
             FROM {$mapping_table}
             WHERE BINARY global_product_sku = %s
               AND convert_unit = %s
             LIMIT 1",
            $sku,
            $unit
        ));

        if ($existing_id) {
            // Kiểm tra xem đang active hay soft-deleted
            $is_deleted = $wpdb->get_var($wpdb->prepare(
                "SELECT is_deleted FROM {$mapping_table}
                 WHERE global_htsoft_stock_convert_id = %d",
                (int) $existing_id
            ));

            if ((int) $is_deleted === 0) {
                self::error('DVT "' . $unit . '" đã tồn tại cho SKU này. Hãy chọn trong danh sách để chỉnh sửa.');
                return;
            }

            // Khôi phục bản ghi đã bị xóa mềm
            $wpdb->update(
                $mapping_table,
                $data,
                ['global_htsoft_stock_convert_id' => (int) $existing_id],
                $formats,
                ['%d']
            );
            self::success(['id' => (int) $existing_id], 'Đã khôi phục và cập nhật cấu hình DVT "' . $unit . '".');
            return;
        }

        $data['created_at'] = $now;
        $inserted = $wpdb->insert(
            $mapping_table,
            $data,
            array_merge($formats, ['%s'])
        );

        if (!$inserted) {
            self::error('Không thể tạo cấu hình quy đổi.');
            return;
        }

        self::success(['id' => (int) $wpdb->insert_id], 'Đã tạo cấu hình DVT "' . $unit . '".');
    }

    // =========================================================================
    // AJAX: Xóa vĩnh viễn một cấu hình
    // =========================================================================

    public function ajax_delete_mapping()
    {
        self::check_permission();
        self::check_nonce();

        global $wpdb;

        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        if ($id <= 0) {
            self::error('ID không hợp lệ.');
            return;
        }

        $deleted = $wpdb->delete(
            self::table_mapping(),
            ['global_htsoft_stock_convert_id' => $id],
            ['%d']
        );

        if ($deleted === false) {
            self::error('Không thể xóa cấu hình.');
            return;
        }

        self::success(['id' => $id], 'Đã xóa cấu hình.');
    }

    // =========================================================================
    // AJAX: Danh sách tất cả cấu hình (bảng tổng)
    // =========================================================================

    public function ajax_list_mappings()
    {
        self::check_permission();
        self::check_nonce();

        global $wpdb;

        $mapping_table = self::table_mapping();
        $product_table = self::table_product();

        $keyword  = isset($_POST['keyword'])  ? sanitize_text_field(wp_unslash($_POST['keyword'])) : '';
        $show_all = !empty($_POST['show_all']);

        if ($keyword !== '') {
            $like = '%' . $wpdb->esc_like($keyword) . '%';
            $kw_sql_base = "SELECT
                    m.global_htsoft_stock_convert_id,
                    m.global_product_sku,
                    m.convert_unit,
                    m.convert_from_tgs,
                    m.convert_to_htsoft,
                    m.convert_note,
                    m.unit_price,
                    m.unit_weight_kg,
                    m.updated_at,
                    p.global_product_name AS local_product_name,
                    p.global_product_unit AS local_product_unit
                 FROM {$mapping_table} m
                 LEFT JOIN {$product_table} p
                     ON BINARY p.global_product_sku = m.global_product_sku
                    AND (p.is_deleted = 0 OR p.is_deleted IS NULL)
                 WHERE (m.is_deleted = 0 OR m.is_deleted IS NULL)
                   AND (
                     m.global_product_sku LIKE %s
                     OR m.convert_unit LIKE %s
                     OR p.global_product_name LIKE %s
                     OR p.global_product_barcode_main LIKE %s
                   )
                 ORDER BY m.global_product_sku ASC, m.convert_unit ASC";
            $sql = $show_all
                ? $wpdb->prepare($kw_sql_base, $like, $like, $like, $like)
                : $wpdb->prepare($kw_sql_base . ' LIMIT 101', $like, $like, $like, $like);
        } else {
            $base_sql = "SELECT
                    m.global_htsoft_stock_convert_id,
                    m.global_product_sku,
                    m.convert_unit,
                    m.convert_from_tgs,
                    m.convert_to_htsoft,
                    m.convert_note,
                    m.unit_price,
                    m.unit_weight_kg,
                    m.updated_at,
                    p.global_product_name AS local_product_name,
                    p.global_product_unit AS local_product_unit
                 FROM {$mapping_table} m
                 LEFT JOIN {$product_table} p
                     ON BINARY p.global_product_sku = m.global_product_sku
                    AND (p.is_deleted = 0 OR p.is_deleted IS NULL)
                 WHERE (m.is_deleted = 0 OR m.is_deleted IS NULL)
                 ORDER BY m.global_product_sku ASC, m.convert_unit ASC";
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $sql = $show_all ? $base_sql : $base_sql . ' LIMIT 101';
        }

        $rows     = $wpdb->get_results($sql, ARRAY_A) ?: [];
        $has_more = false;
        if (!$show_all && count($rows) > 100) {
            $has_more = true;
            $rows     = array_slice($rows, 0, 100);
        }
        $total = count($rows);

        self::success([
            'mappings'  => $rows,
            'total'     => $total,
            'has_more'  => $has_more,
        ]);
    }

    // =========================================================================
    // AJAX: Lấy 1 cấu hình theo ID (dùng khi edit từ bảng tổng)
    // =========================================================================

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

        $mapping_table = self::table_mapping();
        $product_table = self::table_product();

        $sql = $wpdb->prepare(
            "SELECT
                m.global_htsoft_stock_convert_id,
                m.global_product_sku,
                m.convert_unit,
                m.convert_from_tgs,
                m.convert_to_htsoft,
                m.convert_note,
                m.unit_price,
                m.unit_weight_kg,
                p.global_product_name AS local_product_name,
                p.global_product_unit AS local_product_unit
             FROM {$mapping_table} m
             LEFT JOIN {$product_table} p
                 ON BINARY p.global_product_sku = m.global_product_sku
                AND (p.is_deleted = 0 OR p.is_deleted IS NULL)
             WHERE m.global_htsoft_stock_convert_id = %d
               AND (m.is_deleted = 0 OR m.is_deleted IS NULL)
             LIMIT 1",
            $id
        );
        $row = $wpdb->get_row($sql, ARRAY_A);

        if (!$row) {
            self::error('Không tìm thấy cấu hình.');
            return;
        }
        self::success(['mapping' => $row]);
    }

    // =========================================================================
    // AJAX: Lấy cấu hình theo SKU (backward compat — trả về cấu hình đầu tiên)
    // =========================================================================

    public function ajax_get_mapping_by_sku()
    {
        self::check_permission();
        self::check_nonce();

        global $wpdb;

        $sku = isset($_POST['global_product_sku'])
            ? sanitize_text_field(wp_unslash($_POST['global_product_sku']))
            : '';
        if ($sku === '') {
            self::error('Thiếu SKU sản phẩm.');
            return;
        }

        $mapping_table = self::table_mapping();
        $sql = $wpdb->prepare(
            "SELECT global_htsoft_stock_convert_id, global_product_sku, convert_unit,
                    convert_from_tgs, convert_to_htsoft, convert_note, unit_price, unit_weight_kg, updated_at
             FROM {$mapping_table}
             WHERE BINARY global_product_sku = %s
               AND (is_deleted = 0 OR is_deleted IS NULL)
             ORDER BY convert_unit ASC
             LIMIT 1",
            $sku
        );
        $row = $wpdb->get_row($sql, ARRAY_A);
        self::success(['mapping' => $row ?: null]);
    }

    // =========================================================================
    // AJAX: POS lookup — lấy tất cả cấu hình cho danh sách SKU
    // Trả về: { "SKU": { "unit": { convert_to_htsoft, convert_note }, ... }, ... }
    // =========================================================================

    public function ajax_get_mappings_by_skus()
    {
        self::check_permission();
        self::check_nonce();

        global $wpdb;

        $skus_json = isset($_POST['skus']) ? wp_unslash($_POST['skus']) : '[]';
        $skus      = json_decode($skus_json, true);

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
        $placeholders  = implode(',', array_fill(0, count($skus), '%s'));
        $sql = "SELECT global_product_sku, convert_unit, convert_from_tgs, convert_to_htsoft, convert_note, unit_price, unit_weight_kg
                FROM {$mapping_table}
                WHERE (is_deleted = 0 OR is_deleted IS NULL)
                  AND global_product_sku IN ({$placeholders})";

        $prepared = $wpdb->prepare($sql, ...$skus);
        $rows     = $wpdb->get_results($prepared, ARRAY_A);

        // Nhóm: { sku: { unit: { ... }, ... } }
        $map = [];
        if ($rows) {
            foreach ($rows as $row) {
                $s = $row['global_product_sku'];
                $u = $row['convert_unit'];
                if (!isset($map[$s])) {
                    $map[$s] = [];
                }
                $map[$s][$u] = [
                    'convert_from_tgs'  => (float) ($row['convert_from_tgs']  ?? 1),
                    'convert_to_htsoft' => (float) ($row['convert_to_htsoft'] ?? 1),
                    'convert_note'      => (string) ($row['convert_note']     ?? ''),
                    'unit_price'        => ($row['unit_price'] !== null && $row['unit_price'] !== '') ? (float) $row['unit_price'] : null,
                    'unit_weight_kg'    => ($row['unit_weight_kg'] !== null && $row['unit_weight_kg'] !== '') ? (float) $row['unit_weight_kg'] : null,
                ];
            }
        }

        self::success(['mappings' => $map]);
    }

    // =========================================================================
    // AJAX: Xuất tất cả cấu hình dưới dạng mảng row để JS dựng Excel
    // Định dạng Excel: Mã hàng | Tên hàng | DVT bán | Tỷ lệ | Ghi chú
    // =========================================================================

    public function ajax_export_excel_rows()
    {
        self::check_permission();
        self::check_nonce();

        global $wpdb;

        $mapping_table = self::table_mapping();
        $product_table = self::table_product();

        $rows = $wpdb->get_results(
            "SELECT
                m.global_product_sku,
                m.convert_unit,
                m.convert_to_htsoft,
                m.convert_note,
                m.unit_price,
                m.unit_weight_kg,
                p.global_product_name AS local_product_name
             FROM {$mapping_table} m
             LEFT JOIN {$product_table} p
                 ON BINARY p.global_product_sku = m.global_product_sku
                AND (p.is_deleted = 0 OR p.is_deleted IS NULL)
             WHERE (m.is_deleted = 0 OR m.is_deleted IS NULL)
             ORDER BY m.global_product_sku ASC, m.convert_unit ASC",
            ARRAY_A
        );

        $export = [];
        foreach ($rows as $row) {
            $ratio = self::parse_positive_decimal($row['convert_to_htsoft'], 1);
            $price = ($row['unit_price'] !== null && $row['unit_price'] !== '') ? (float) $row['unit_price'] : null;
            $weight = ($row['unit_weight_kg'] !== null && $row['unit_weight_kg'] !== '') ? (float) $row['unit_weight_kg'] : null;
            $export[] = [
                'global_product_sku' => $row['global_product_sku'],
                'local_product_name' => (string) ($row['local_product_name'] ?? ''),
                'convert_unit'       => (string) ($row['convert_unit'] ?? ''),
                'convert_to_htsoft'  => (float)  $ratio,
                'convert_note'       => (string) ($row['convert_note'] ?? ''),
                'unit_price'         => $price,
                'unit_weight_kg'     => $weight,
            ];
        }

        self::success([
            'exported_at' => current_time('mysql'),
            'count'       => count($export),
            'rows'        => $export,
        ]);
    }

    // =========================================================================
    // AJAX: Nhập cấu hình từ dữ liệu Excel đã parse bởi XLSX.js phía client
    // POST: rows_json = JSON array of { global_product_sku, convert_unit, convert_to_htsoft, convert_note }
    // =========================================================================

    public function ajax_import_excel_rows()
    {
        self::check_permission();
        self::check_nonce();

        $rows_json = isset($_POST['rows_json']) ? wp_unslash($_POST['rows_json']) : '[]';
        $items     = json_decode($rows_json, true);

        if (!is_array($items) || empty($items)) {
            self::error('Không có dữ liệu hợp lệ để import.');
            return;
        }

        global $wpdb;

        $mapping_table = self::table_mapping();
        $now           = current_time('mysql');
        $user_id       = get_current_user_id();
        $created       = 0;
        $updated       = 0;
        $skipped       = 0;
        $errors        = [];

        foreach ($items as $idx => $item) {
            if (!is_array($item)) {
                $skipped++;
                continue;
            }

            $sku  = isset($item['global_product_sku']) ? sanitize_text_field((string) $item['global_product_sku']) : '';
            $unit = isset($item['convert_unit'])       ? sanitize_text_field((string) $item['convert_unit'])       : '';

            if ($sku === '') {
                $skipped++;
                continue;
            }

            $to_htsoft = self::parse_positive_decimal($item['convert_to_htsoft'] ?? 1, 1);
            $note      = isset($item['convert_note']) ? sanitize_text_field((string) $item['convert_note']) : '';
            $unit_price = self::parse_optional_decimal($item['unit_price'] ?? '');
            $unit_weight_kg = self::parse_optional_decimal($item['unit_weight_kg'] ?? '');
            if ($note === '') {
                $note = self::build_default_note($unit, $to_htsoft);
            }

            $data = [
                'global_product_sku' => $sku,
                'convert_unit'       => $unit,
                'convert_from_tgs'   => 1,
                'convert_to_htsoft'  => $to_htsoft,
                'convert_note'       => $note,
                'unit_price'         => $unit_price,
                'unit_weight_kg'     => $unit_weight_kg,
                'user_id'            => $user_id,
                'is_deleted'         => 0,
                'deleted_at'         => null,
                'updated_at'         => $now,
            ];
            $formats = [
                '%s',
                '%s',
                '%f',
                '%f',
                '%s',
                $unit_price !== null ? '%f' : '%s',
                $unit_weight_kg !== null ? '%f' : '%s',
                '%d',
                '%d',
                '%s',
                '%s',
            ];

            // Tìm row theo (sku, unit) — unicode_ci → case-insensitive cho unit
            $existing_id = $wpdb->get_var($wpdb->prepare(
                "SELECT global_htsoft_stock_convert_id
                 FROM {$mapping_table}
                 WHERE BINARY global_product_sku = %s
                   AND convert_unit = %s
                 LIMIT 1",
                $sku,
                $unit
            ));

            if ($existing_id) {
                $result = $wpdb->update(
                    $mapping_table,
                    $data,
                    ['global_htsoft_stock_convert_id' => (int) $existing_id],
                    $formats,
                    ['%d']
                );
                if ($result !== false) {
                    $updated++;
                } else {
                    $skipped++;
                    $errors[] = "Dòng " . ($idx + 1) . " (SKU: {$sku}, DVT: {$unit}): lỗi cập nhật.";
                }
            } else {
                $data['created_at'] = $now;
                $result = $wpdb->insert(
                    $mapping_table,
                    $data,
                    array_merge($formats, ['%s'])
                );
                if ($result) {
                    $created++;
                } else {
                    $skipped++;
                    $errors[] = "Dòng " . ($idx + 1) . " (SKU: {$sku}, DVT: {$unit}): lỗi tạo mới.";
                }
            }
        }

        self::success([
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
            'errors'  => $errors,
        ], "Import xong. Tạo mới: {$created}, cập nhật: {$updated}, bỏ qua: {$skipped}.");
    }

    // =========================================================================
    // AJAX: Xuất JSON (backward compat — giờ có thêm convert_unit)
    // =========================================================================

    public function ajax_export_mappings_json()
    {
        self::check_permission();
        self::check_nonce();

        global $wpdb;

        $mapping_table = self::table_mapping();
        $rows = $wpdb->get_results(
            "SELECT global_product_sku, convert_unit, convert_from_tgs, convert_to_htsoft, convert_note, unit_weight_kg, updated_at
             FROM {$mapping_table}
             WHERE (is_deleted = 0 OR is_deleted IS NULL)
             ORDER BY global_product_sku ASC, convert_unit ASC",
            ARRAY_A
        );

        foreach ($rows as &$row) {
            $row['convert_from_tgs']  = (string)((int) floatval($row['convert_from_tgs']));
            $row['convert_to_htsoft'] = (string)((int) floatval($row['convert_to_htsoft']));
        }
        unset($row);

        self::success([
            'exported_at' => current_time('mysql'),
            'count'       => count($rows),
            'mappings'    => $rows ?: [],
        ]);
    }

    // =========================================================================
    // AJAX: Import JSON (backward compat — hỗ trợ cả file cũ không có convert_unit)
    // =========================================================================

    public function ajax_import_mappings_json()
    {
        self::check_permission();
        self::check_nonce();

        // Chấp nhận cả 2 cách:
        // 1. JS mới: POST field 'mappings_json' (chuỗi JSON)
        // 2. Backward compat: file upload 'json_file'
        if (!empty($_POST['mappings_json'])) {
            $json = wp_unslash($_POST['mappings_json']);
        } elseif (!empty($_FILES['json_file']['tmp_name'])) {
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
            if (substr($json, 0, 3) === "\xEF\xBB\xBF") {
                $json = substr($json, 3);
            }
        } else {
            self::error('Chưa có dữ liệu JSON để import.');
            return;
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            self::error('Nội dung JSON không hợp lệ.');
            return;
        }

        $items = isset($decoded['mappings']) && is_array($decoded['mappings'])
            ? $decoded['mappings']
            : $decoded;

        if (!is_array($items) || empty($items)) {
            self::error('File JSON không có dữ liệu cấu hình.');
            return;
        }

        global $wpdb;

        $mapping_table = self::table_mapping();
        $now           = current_time('mysql');
        $user_id       = get_current_user_id();
        $created       = 0;
        $updated       = 0;
        $skipped       = 0;

        foreach ($items as $item) {
            if (!is_array($item)) {
                $skipped++;
                continue;
            }

            $sku  = isset($item['global_product_sku']) ? sanitize_text_field((string) $item['global_product_sku']) : '';
            $unit = isset($item['convert_unit'])       ? sanitize_text_field((string) $item['convert_unit'])       : '';
            if ($sku === '') {
                $skipped++;
                continue;
            }

            $to_htsoft = self::parse_positive_decimal($item['convert_to_htsoft'] ?? 1, 1);
            $note      = isset($item['convert_note']) ? sanitize_textarea_field((string) $item['convert_note']) : '';
            $unit_weight_kg = self::parse_optional_decimal($item['unit_weight_kg'] ?? '');
            if ($note === '') {
                $note = self::build_default_note($unit, $to_htsoft);
            }

            $data = [
                'global_product_sku' => $sku,
                'convert_unit'       => $unit,
                'convert_from_tgs'   => 1,
                'convert_to_htsoft'  => $to_htsoft,
                'convert_note'       => $note,
                'unit_weight_kg'     => $unit_weight_kg,
                'user_id'            => $user_id,
                'is_deleted'         => 0,
                'deleted_at'         => null,
                'updated_at'         => $now,
            ];
            $formats = ['%s', '%s', '%f', '%f', '%s', $unit_weight_kg !== null ? '%f' : '%s', '%d', '%d', '%s', '%s'];

            $existing_id = $wpdb->get_var($wpdb->prepare(
                "SELECT global_htsoft_stock_convert_id
                 FROM {$mapping_table}
                 WHERE BINARY global_product_sku = %s
                   AND convert_unit = %s
                 LIMIT 1",
                $sku,
                $unit
            ));

            if ($existing_id) {
                $result = $wpdb->update(
                    $mapping_table,
                    $data,
                    ['global_htsoft_stock_convert_id' => (int) $existing_id],
                    $formats,
                    ['%d']
                );
                if ($result !== false) {
                    $updated++;
                } else {
                    $skipped++;
                }
            } else {
                $data['created_at'] = $now;
                $result = $wpdb->insert(
                    $mapping_table,
                    $data,
                    array_merge($formats, ['%s'])
                );
                if ($result) {
                    $created++;
                } else {
                    $skipped++;
                }
            }
        }

        self::success([
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
        ], "Import JSON xong. Tạo mới: {$created}, cập nhật: {$updated}, bỏ qua: {$skipped}.");
    }

    // =========================================================================
    // AJAX: Import giá bán từ Excel (theo batch)
    // =========================================================================

    /**
     * Nhận batch các dòng giá từ JS, cập nhật cột unit_price trong bảng quy đổi.
     *
     * Logic suy giá (mỗi nhóm SKU):
     *   1. Ưu tiên giá từ Excel nếu có.
     *   2. Nếu thiếu một số DVT:
     *      - Tìm DVT có tỷ lệ = 1 (DVT lẻ) đã có giá → giá_DVT_khác = giá_lẻ × tỷ_lệ
     *      - Nếu không có DVT lẻ nhưng có DVT khác → giá_lẻ = giá_DVT / tỷ_lệ → suy các DVT còn lại
     *   3. Nếu Excel đã có giá cho DVT đó → dùng Excel (ưu tiên).
     *
     * POST params:
     *   rows_json  – JSON array of { sku, unit, price }  (từng batch ~200 dòng)
     *               price là giá Excel (có thể null/'' nếu dòng không có giá)
     *
     * Response: { updated, skipped, errors[] }
     */
    public function ajax_import_price_rows()
    {
        self::check_permission();
        self::check_nonce();

        $rows_json = isset($_POST['rows_json']) ? wp_unslash($_POST['rows_json']) : '[]';
        $items     = json_decode($rows_json, true);

        if (!is_array($items) || empty($items)) {
            self::error('Không có dữ liệu hợp lệ.');
            return;
        }

        global $wpdb;
        $mapping_table = self::table_mapping();
        $now           = current_time('mysql');
        $updated       = 0;
        $skipped       = 0;
        $errors        = [];

        // ── Nhóm các dòng Excel theo SKU ──────────────────────────────────────
        // Mỗi phần tử: [ 'sku' => '...', 'unit' => '...', 'price' => float|null ]
        $by_sku = [];
        foreach ($items as $idx => $item) {
            if (!is_array($item)) { $skipped++; continue; }
            $sku  = isset($item['sku'])  ? sanitize_text_field(trim((string) $item['sku']))  : '';
            $unit = isset($item['unit']) ? sanitize_text_field(trim((string) $item['unit'])) : '';
            if ($sku === '') { $skipped++; continue; }

            $raw_price = $item['price'] ?? null;
            $price     = null;
            if ($raw_price !== null && $raw_price !== '') {
                $cleaned = (float) str_replace(',', '.', (string) $raw_price);
                if ($cleaned > 0) {
                    $price = $cleaned;
                }
            }

            $by_sku[$sku][] = ['unit' => $unit, 'price' => $price, 'idx' => $idx];
        }

        // ── Xử lý từng nhóm SKU ───────────────────────────────────────────────
        foreach ($by_sku as $sku => $excel_rows) {
            // Lấy toàn bộ cấu hình DB của SKU này
            $db_rows = $wpdb->get_results($wpdb->prepare(
                "SELECT global_htsoft_stock_convert_id, convert_unit, convert_to_htsoft
                 FROM {$mapping_table}
                 WHERE BINARY global_product_sku = %s
                   AND (is_deleted = 0 OR is_deleted IS NULL)",
                $sku
            ), ARRAY_A);

            if (empty($db_rows)) {
                foreach ($excel_rows as $er) {
                    $skipped++;
                    $errors[] = "Dòng " . ($er['idx'] + 1) . " (SKU: {$sku}): không tìm thấy cấu hình trong DB.";
                }
                continue;
            }

            // Index DB theo unit (trim, lowercase để so sánh)
            $db_by_unit = [];
            foreach ($db_rows as $dr) {
                $key = strtolower(trim($dr['convert_unit']));
                $db_by_unit[$key] = $dr;
            }

            // Index Excel theo unit (lowercase)
            $excel_by_unit = [];
            foreach ($excel_rows as $er) {
                $key = strtolower(trim($er['unit']));
                $excel_by_unit[$key] = $er['price'];
            }

            // ── Suy giá cho các DVT chưa có trong Excel ───────────────────────
            // 1. Tìm DVT "lẻ" (convert_to_htsoft gần nhất với 1) đã có giá trong Excel
            $base_price_per_unit = null; // giá của 1 đơn vị nhỏ nhất (tỷ lệ 1)

            // Tìm DVT có tỷ lệ = 1 và có giá Excel → base_price = excel_price / 1
            foreach ($db_by_unit as $uk => $dr) {
                $ratio = (float) $dr['convert_to_htsoft'];
                if (abs($ratio - 1.0) < 0.0001 && isset($excel_by_unit[$uk]) && $excel_by_unit[$uk] !== null) {
                    $base_price_per_unit = (float) $excel_by_unit[$uk];
                    break;
                }
            }

            // Nếu chưa tìm được từ DVT lẻ → suy từ DVT khác có giá trong Excel
            if ($base_price_per_unit === null) {
                foreach ($db_by_unit as $uk => $dr) {
                    if (isset($excel_by_unit[$uk]) && $excel_by_unit[$uk] !== null) {
                        $ratio = (float) $dr['convert_to_htsoft'];
                        if ($ratio > 0.0001) {
                            $base_price_per_unit = (float) $excel_by_unit[$uk] / $ratio;
                            break;
                        }
                    }
                }
            }

            // ── Cập nhật từng cấu hình DVT của SKU ───────────────────────────
            foreach ($db_by_unit as $uk => $dr) {
                $id    = (int) $dr['global_htsoft_stock_convert_id'];
                $ratio = (float) $dr['convert_to_htsoft'];

                // Xác định giá sẽ lưu (ưu tiên Excel)
                if (isset($excel_by_unit[$uk]) && $excel_by_unit[$uk] !== null) {
                    $final_price = (float) $excel_by_unit[$uk];
                } elseif ($base_price_per_unit !== null && $ratio > 0.0001) {
                    $final_price = round($base_price_per_unit * $ratio, 2);
                } else {
                    $skipped++;
                    continue; // không đủ thông tin để tính giá
                }

                $result = $wpdb->update(
                    $mapping_table,
                    ['unit_price' => $final_price, 'updated_at' => $now],
                    ['global_htsoft_stock_convert_id' => $id],
                    ['%f', '%s'],
                    ['%d']
                );

                if ($result !== false) {
                    $updated++;
                } else {
                    $skipped++;
                    $errors[] = "SKU: {$sku}, DVT: {$dr['convert_unit']}: lỗi cập nhật.";
                }
            }
        }

        self::success([
            'updated' => $updated,
            'skipped' => $skipped,
            'errors'  => $errors,
        ], "Cập nhật giá xong. Đã cập nhật: {$updated}, bỏ qua: {$skipped}.");
    }
}
