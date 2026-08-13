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

        // ── ĐVT bán chính (is_default_unit) ────────────────────────────
        add_action('wp_ajax_tgs_htsoft_converter_set_default_unit',     [$this, 'ajax_set_default_unit']);
        add_action('wp_ajax_tgs_htsoft_converter_default_scan_prepare', [$this, 'ajax_default_scan_prepare']);
        add_action('wp_ajax_tgs_htsoft_converter_default_scan_batch',   [$this, 'ajax_default_scan_batch']);

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
            'Quản lý đơn vị tính và giá theo ĐVT',
            TGS_HTSOFT_CONVERTER_DIR . 'admin/views/page-htsoft-stock-converter.php',
        ];
        return $routes;
    }

    public function render_product_submenu($current_view)
    {
        $url    = admin_url('admin.php?page=tgs-shop-management&view=' . self::VIEW_SLUG);
        $active = ($current_view === self::VIEW_SLUG) ? 'active' : '';

        echo '<li><a href="' . esc_url($url) . '" class="' . esc_attr($active) . '">'
            . '<i class="bx bx-transfer"></i>Quản lý đơn vị tính và giá theo ĐVT</a></li>';
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
    // ĐVT BÁN CHÍNH (is_default_unit)
    //
    // Trong 1 nhóm cùng SKU chỉ có DUY NHẤT 1 dòng is_default_unit = 1.
    // POS ưu tiên lấy dòng này khi tìm sản phẩm → thêm vào giỏ đúng giá.
    // =========================================================================

    /** Sai số khi so sánh tỷ lệ quy đổi (DECIMAL(15,3)) */
    const RATIO_EPS = 0.0005;

    /**
     * Các DVT bị LOẠI mặc định khi quét tự động (dù có giá).
     * - Thùng / thung / Thùng_48 …  → hàng bán sỉ, không đưa ra POS mặc định
     * - kg / Kg_5 / KG …            → đơn vị cân, không đưa ra POS mặc định
     *
     * @return string[] danh sách regex chạy trên tên DVT đã bỏ dấu + lowercase
     */
    private static function excluded_unit_patterns()
    {
        return apply_filters('tgs_htsoft_converter_excluded_default_units', [
            '/thung/',                       // thùng, thung, thùng_48, 1thung…
            '/(^|[^a-z0-9])kg([^a-z]|$)/',   // kg, kg_5, _kg, "kg 10"
        ]);
    }

    /**
     * Bỏ dấu tiếng Việt + lowercase để so khớp tên DVT.
     */
    private static function normalize_unit($unit)
    {
        $unit = mb_strtolower(trim((string) $unit), 'UTF-8');

        $map = [
            'à','á','ạ','ả','ã','â','ầ','ấ','ậ','ẩ','ẫ','ă','ằ','ắ','ặ','ẳ','ẵ',
            'è','é','ẹ','ẻ','ẽ','ê','ề','ế','ệ','ể','ễ',
            'ì','í','ị','ỉ','ĩ',
            'ò','ó','ọ','ỏ','õ','ô','ồ','ố','ộ','ổ','ỗ','ơ','ờ','ớ','ợ','ở','ỡ',
            'ù','ú','ụ','ủ','ũ','ư','ừ','ứ','ự','ử','ữ',
            'ỳ','ý','ỵ','ỷ','ỹ','đ',
        ];
        $plain = [
            'a','a','a','a','a','a','a','a','a','a','a','a','a','a','a','a','a',
            'e','e','e','e','e','e','e','e','e','e','e',
            'i','i','i','i','i',
            'o','o','o','o','o','o','o','o','o','o','o','o','o','o','o','o','o',
            'u','u','u','u','u','u','u','u','u','u','u',
            'y','y','y','y','y','d',
        ];

        return str_replace($map, $plain, $unit);
    }

    /**
     * DVT này có nằm trong danh sách loại trừ mặc định không?
     */
    private static function is_excluded_unit($unit)
    {
        $norm = self::normalize_unit($unit);
        if ($norm === '') {
            return false;
        }
        foreach (self::excluded_unit_patterns() as $pattern) {
            if (preg_match($pattern, $norm)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Chọn dòng cấu hình sẽ làm ĐVT bán chính cho 1 SKU.
     *
     * Thứ tự ưu tiên (chỉ xét DVT KHÔNG bị loại trừ):
     *   1. Tỷ lệ > 1 và CÓ giá   → lấy tỷ lệ nhỏ nhất (gần 1 nhất)
     *   2. Tỷ lệ = 1 và CÓ giá
     *   3. Tỷ lệ = 1 (không cần giá)
     *   4. Tỷ lệ > 1 (không có giá) → lấy tỷ lệ nhỏ nhất
     *   → Không có ứng viên nào hợp lệ: trả về null (SKU đó không có ĐVT chính).
     *
     * VD: SKU có tỷ lệ 1, 3, 6, 8 → chọn 3. Nếu 3 chưa có giá → chọn 6…
     *
     * @param array $rows Các dòng cấu hình ĐANG hoạt động của 1 SKU
     * @return array|null Dòng được chọn
     */
    private static function pick_default_config($rows)
    {
        $bigger_priced  = [];
        $bigger_any     = [];
        $one_priced     = [];
        $one_any        = [];

        foreach ($rows as $row) {
            if (self::is_excluded_unit($row['convert_unit'] ?? '')) {
                continue;
            }

            $ratio = (float) ($row['convert_to_htsoft'] ?? 1);
            if ($ratio <= 0) {
                continue;
            }

            $has_price = ($row['unit_price'] !== null && $row['unit_price'] !== '' && (float) $row['unit_price'] > 0);

            if ($ratio > 1 + self::RATIO_EPS) {
                $bigger_any[] = $row;
                if ($has_price) {
                    $bigger_priced[] = $row;
                }
            } else {
                // Tỷ lệ = 1 (hoặc < 1 hiếm gặp) — nhóm "đơn vị nhỏ nhất"
                $one_any[] = $row;
                if ($has_price) {
                    $one_priced[] = $row;
                }
            }
        }

        // Sắp xếp: tỷ lệ nhỏ nhất trước, cùng tỷ lệ thì lấy dòng tạo trước
        $sort_by_ratio = function (&$list) {
            usort($list, function ($a, $b) {
                $ra = (float) $a['convert_to_htsoft'];
                $rb = (float) $b['convert_to_htsoft'];
                if (abs($ra - $rb) > self::RATIO_EPS) {
                    return ($ra < $rb) ? -1 : 1;
                }
                return ((int) $a['global_htsoft_stock_convert_id']) <=> ((int) $b['global_htsoft_stock_convert_id']);
            });
        };

        $sort_by_id = function (&$list) {
            usort($list, function ($a, $b) {
                return ((int) $a['global_htsoft_stock_convert_id']) <=> ((int) $b['global_htsoft_stock_convert_id']);
            });
        };

        $sort_by_ratio($bigger_priced);
        $sort_by_ratio($bigger_any);
        $sort_by_id($one_priced);
        $sort_by_id($one_any);

        if (!empty($bigger_priced)) { return $bigger_priced[0]; }
        if (!empty($one_priced))    { return $one_priced[0]; }
        if (!empty($one_any))       { return $one_any[0]; }
        if (!empty($bigger_any))    { return $bigger_any[0]; }

        return null;
    }

    /**
     * Đặt 1 dòng làm ĐVT bán chính của SKU, mọi dòng còn lại về 0.
     *
     * @param string   $sku
     * @param int|null $winner_id ID dòng được chọn (null = xoá hết cờ của SKU)
     * @return int Số dòng thực sự bị thay đổi
     */
    private static function apply_default_for_sku($sku, $winner_id)
    {
        global $wpdb;

        $table = self::table_mapping();
        $now   = current_time('mysql');
        $touch = 0;

        // Hạ cờ tất cả dòng khác của SKU (kể cả dòng đã xoá mềm)
        $cleared = $wpdb->query($wpdb->prepare(
            "UPDATE {$table}
             SET is_default_unit = 0, updated_at = %s
             WHERE BINARY global_product_sku = %s
               AND is_default_unit <> 0
               AND global_htsoft_stock_convert_id <> %d",
            $now,
            $sku,
            (int) $winner_id
        ));
        $touch += max(0, (int) $cleared);

        if ($winner_id) {
            $set = $wpdb->query($wpdb->prepare(
                "UPDATE {$table}
                 SET is_default_unit = 1, updated_at = %s
                 WHERE global_htsoft_stock_convert_id = %d
                   AND is_default_unit <> 1",
                $now,
                (int) $winner_id
            ));
            $touch += max(0, (int) $set);
        }

        return $touch;
    }

    /**
     * Lấy toàn bộ dòng cấu hình đang hoạt động của 1 SKU (dùng cho việc chọn lại ĐVT chính).
     */
    private static function fetch_active_configs($sku)
    {
        global $wpdb;

        $table = self::table_mapping();
        return $wpdb->get_results($wpdb->prepare(
            "SELECT global_htsoft_stock_convert_id, convert_unit, convert_to_htsoft, unit_price, is_default_unit
             FROM {$table}
             WHERE BINARY global_product_sku = %s
               AND (is_deleted = 0 OR is_deleted IS NULL)
             ORDER BY global_htsoft_stock_convert_id ASC",
            $sku
        ), ARRAY_A) ?: [];
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

        $table = self::table_product();
        $like  = '%' . $wpdb->esc_like($keyword) . '%';

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

        // ── B1: Tìm sản phẩm (KHÔNG join bảng quy đổi) ─────────────────────
        //
        // Trước đây câu này LEFT JOIN bảng quy đổi bằng `BINARY m.sku = p.sku`
        // để đếm config_count. BINARY chặn index → mỗi dòng sản phẩm quét full
        // bảng quy đổi 20k dòng ⇒ 1 lần tìm mất ~20 GIÂY, gõ vài ký tự là treo
        // trang. Tách làm 2 query: lọc 30 sản phẩm trước (~0.13s), rồi đếm cấu
        // hình đúng 30 SKU đó qua IN(...) dùng được index (~0.01s).
        $sql = "SELECT
                    p.global_product_name AS local_product_name,
                    p.global_product_sku AS local_product_sku,
                    p.global_product_barcode_main AS local_product_barcode_main,
                    p.global_product_unit AS local_product_unit,
                    0 AS local_product_quantity_no_tracking
                FROM {$table} p
                WHERE (p.is_deleted = 0 OR p.is_deleted IS NULL)
                  AND (p.global_product_is_tracking = 0 OR p.global_product_is_tracking IS NULL)
                  AND (
                    ({$name_where})
                    OR p.global_product_barcode_main LIKE %s
                    OR p.global_product_sku LIKE %s
                  )
                ORDER BY p.global_product_name ASC
                LIMIT 30";

        $values[] = $like;
        $values[] = $like;

        $sql  = $wpdb->prepare($sql, ...$values);
        $rows = $wpdb->get_results($sql, ARRAY_A) ?: [];

        // ── B2: Đếm số cấu hình DVT cho đúng các SKU vừa tìm được ──────────
        $rows = self::attach_config_count($rows);

        self::success(['products' => $rows]);
    }

    /**
     * Gắn config_count (số DVT đã cấu hình) cho danh sách sản phẩm.
     * Dùng IN(...) trên cột có UNIQUE KEY thay cho LEFT JOIN + BINARY.
     *
     * @param array $rows Mỗi phần tử có key local_product_sku
     * @return array
     */
    private static function attach_config_count($rows)
    {
        if (empty($rows)) {
            return [];
        }

        global $wpdb;

        $skus = [];
        foreach ($rows as $row) {
            $sku = (string) ($row['local_product_sku'] ?? '');
            if ($sku !== '') {
                $skus[$sku] = true;
            }
        }
        $skus = array_keys($skus);

        $counts = [];
        if (!empty($skus)) {
            $mapping_table = self::table_mapping();
            $placeholders  = implode(',', array_fill(0, count($skus), '%s'));

            // Cột global_product_sku dùng collation utf8mb4_bin → so khớp
            // phân biệt hoa/thường y hệt BINARY nhưng vẫn dùng được index.
            $sql = "SELECT global_product_sku, COUNT(*) AS config_count
                    FROM {$mapping_table}
                    WHERE (is_deleted = 0 OR is_deleted IS NULL)
                      AND global_product_sku IN ({$placeholders})
                    GROUP BY global_product_sku";

            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $found = $wpdb->get_results($wpdb->prepare($sql, ...$skus), ARRAY_A) ?: [];
            foreach ($found as $f) {
                $counts[(string) $f['global_product_sku']] = (int) $f['config_count'];
            }
        }

        foreach ($rows as &$row) {
            $sku = (string) ($row['local_product_sku'] ?? '');
            $row['config_count'] = $counts[$sku] ?? 0;
        }
        unset($row);

        return $rows;
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
                is_default_unit,
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

        // ĐVT bán chính — 1 SKU chỉ có duy nhất 1 dòng = 1
        $is_default_unit = !empty($_POST['is_default_unit']) ? 1 : 0;

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
            'is_default_unit'    => $is_default_unit,
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

            // Chọn làm ĐVT bán chính → hạ cờ mọi DVT khác của SKU
            if ($is_default_unit === 1) {
                self::apply_default_for_sku($sku, $id);
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
            if ($is_default_unit === 1) {
                self::apply_default_for_sku($sku, (int) $existing_id);
            }
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

        $new_id = (int) $wpdb->insert_id;
        if ($is_default_unit === 1) {
            self::apply_default_for_sku($sku, $new_id);
        }

        self::success(['id' => $new_id], 'Đã tạo cấu hình DVT "' . $unit . '".');
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

        // Ghi nhớ SKU + có phải ĐVT bán chính không, để chọn lại sau khi xóa
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT global_product_sku, is_default_unit
             FROM " . self::table_mapping() . "
             WHERE global_htsoft_stock_convert_id = %d",
            $id
        ), ARRAY_A);

        $deleted = $wpdb->delete(
            self::table_mapping(),
            ['global_htsoft_stock_convert_id' => $id],
            ['%d']
        );

        if ($deleted === false) {
            self::error('Không thể xóa cấu hình.');
            return;
        }

        // Xóa đúng ĐVT bán chính → tự chọn lại DVT khác để POS luôn có mặc định
        $new_default_id = 0;
        if ($row && (int) $row['is_default_unit'] === 1) {
            $sku    = (string) $row['global_product_sku'];
            $winner = self::pick_default_config(self::fetch_active_configs($sku));
            if ($winner) {
                $new_default_id = (int) $winner['global_htsoft_stock_convert_id'];
                self::apply_default_for_sku($sku, $new_default_id);
            }
        }

        self::success(
            ['id' => $id, 'new_default_id' => $new_default_id],
            $new_default_id ? 'Đã xóa cấu hình và chọn lại ĐVT bán chính.' : 'Đã xóa cấu hình.'
        );
    }

    // =========================================================================
    // AJAX: Đặt 1 cấu hình làm ĐVT bán chính (các DVT khác cùng SKU về 0)
    // =========================================================================

    public function ajax_set_default_unit()
    {
        self::check_permission();
        self::check_nonce();

        global $wpdb;

        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        if ($id <= 0) {
            self::error('ID không hợp lệ.');
            return;
        }

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT global_product_sku, convert_unit
             FROM " . self::table_mapping() . "
             WHERE global_htsoft_stock_convert_id = %d
               AND (is_deleted = 0 OR is_deleted IS NULL)",
            $id
        ), ARRAY_A);

        if (!$row) {
            self::error('Không tìm thấy cấu hình.');
            return;
        }

        self::apply_default_for_sku((string) $row['global_product_sku'], $id);

        $label = ($row['convert_unit'] !== '') ? $row['convert_unit'] : 'Mặc định';
        self::success(
            ['id' => $id, 'global_product_sku' => $row['global_product_sku']],
            'Đã đặt "' . $label . '" làm ĐVT bán chính.'
        );
    }

    // =========================================================================
    // AJAX: Quét & thống nhất ĐVT bán chính toàn hệ thống
    //
    // Chạy theo batch (mỗi request xử lý N mã hàng) để không timeout với 20k+ dòng.
    //  - prepare: đếm tổng số SKU cần quét
    //  - batch:   xử lý 1 lô SKU theo offset, trả về thống kê
    // =========================================================================

    /** Số SKU xử lý trong 1 request quét */
    const DEFAULT_SCAN_BATCH_SIZE = 200;
    const DEFAULT_SCAN_BATCH_MAX  = 500;

    public function ajax_default_scan_prepare()
    {
        self::check_permission();
        self::check_nonce();

        global $wpdb;

        $table = self::table_mapping();
        $total = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT global_product_sku)
             FROM {$table}
             WHERE (is_deleted = 0 OR is_deleted IS NULL)"
        );

        self::success([
            'total_skus' => $total,
            'batch_size' => self::DEFAULT_SCAN_BATCH_SIZE,
        ]);
    }

    public function ajax_default_scan_batch()
    {
        self::check_permission();
        self::check_nonce();

        global $wpdb;

        $table  = self::table_mapping();
        $offset = isset($_POST['offset']) ? max(0, (int) $_POST['offset']) : 0;

        $limit = isset($_POST['batch_size']) ? (int) $_POST['batch_size'] : self::DEFAULT_SCAN_BATCH_SIZE;
        if ($limit < 10) {
            $limit = self::DEFAULT_SCAN_BATCH_SIZE;
        }
        if ($limit > self::DEFAULT_SCAN_BATCH_MAX) {
            $limit = self::DEFAULT_SCAN_BATCH_MAX;
        }

        // 1 = chỉ xử lý SKU chưa có ĐVT chính; 0 = tính lại toàn bộ
        $only_missing = !empty($_POST['only_missing']);

        // ── Lấy 1 lô SKU (thứ tự cố định → phân trang ổn định giữa các batch) ──
        $skus = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT global_product_sku
             FROM {$table}
             WHERE (is_deleted = 0 OR is_deleted IS NULL)
             ORDER BY global_product_sku ASC
             LIMIT %d OFFSET %d",
            $limit,
            $offset
        ));

        if (empty($skus)) {
            self::success([
                'processed'    => 0,
                'assigned'     => 0,
                'unchanged'    => 0,
                'no_candidate' => 0,
                'next_offset'  => $offset,
                'done'         => true,
                'samples'      => [],
            ]);
            return;
        }

        // ── Lấy toàn bộ dòng của các SKU trong lô (1 query) ────────────────
        $placeholders = implode(',', array_fill(0, count($skus), '%s'));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT global_htsoft_stock_convert_id, global_product_sku, convert_unit,
                    convert_to_htsoft, unit_price, is_default_unit, is_deleted
             FROM {$table}
             WHERE global_product_sku IN ({$placeholders})
             ORDER BY global_htsoft_stock_convert_id ASC",
            ...$skus
        ), ARRAY_A) ?: [];

        $groups = [];
        foreach ($rows as $r) {
            $groups[(string) $r['global_product_sku']][] = $r;
        }

        $assigned     = 0;
        $unchanged    = 0;
        $no_candidate = 0;
        $ids_set_one  = [];
        $ids_set_zero = [];
        $samples      = [];

        foreach ($skus as $sku) {
            $sku      = (string) $sku;
            $all_rows = $groups[$sku] ?? [];

            $active = array_values(array_filter($all_rows, function ($r) {
                return ((int) $r['is_deleted']) === 0 || $r['is_deleted'] === null;
            }));

            $current_default_ids = [];
            foreach ($all_rows as $r) {
                if ((int) $r['is_default_unit'] === 1) {
                    $current_default_ids[] = (int) $r['global_htsoft_stock_convert_id'];
                }
            }

            // Chỉ bù SKU thiếu: đã có đúng 1 cờ hợp lệ thì bỏ qua
            if ($only_missing && count($current_default_ids) === 1) {
                $keep_id = $current_default_ids[0];
                $still_active = false;
                foreach ($active as $r) {
                    if ((int) $r['global_htsoft_stock_convert_id'] === $keep_id) {
                        $still_active = true;
                        break;
                    }
                }
                if ($still_active) {
                    $unchanged++;
                    continue;
                }
            }

            $winner    = self::pick_default_config($active);
            $winner_id = $winner ? (int) $winner['global_htsoft_stock_convert_id'] : 0;

            if (!$winner_id) {
                $no_candidate++;
            }

            // Dòng cần hạ cờ: mọi dòng đang = 1 nhưng không phải dòng thắng
            foreach ($current_default_ids as $cid) {
                if ($cid !== $winner_id) {
                    $ids_set_zero[] = $cid;
                }
            }

            if ($winner_id) {
                if (in_array($winner_id, $current_default_ids, true) && count($current_default_ids) === 1) {
                    $unchanged++;
                } else {
                    $ids_set_one[] = $winner_id;
                    $assigned++;
                    if (count($samples) < 20) {
                        $samples[] = [
                            'sku'   => $sku,
                            'unit'  => (string) $winner['convert_unit'],
                            'ratio' => (float) $winner['convert_to_htsoft'],
                            'price' => ($winner['unit_price'] !== null && $winner['unit_price'] !== '')
                                        ? (float) $winner['unit_price'] : null,
                        ];
                    }
                }
            }
        }

        // ── Ghi DB: 2 query gộp cho cả lô ──────────────────────────────────
        $now = current_time('mysql');

        if (!empty($ids_set_zero)) {
            $in = implode(',', array_map('intval', $ids_set_zero));
            $wpdb->query($wpdb->prepare(
                "UPDATE {$table} SET is_default_unit = 0, updated_at = %s
                 WHERE global_htsoft_stock_convert_id IN ({$in})",
                $now
            ));
        }

        if (!empty($ids_set_one)) {
            $in = implode(',', array_map('intval', $ids_set_one));
            $wpdb->query($wpdb->prepare(
                "UPDATE {$table} SET is_default_unit = 1, updated_at = %s
                 WHERE global_htsoft_stock_convert_id IN ({$in})",
                $now
            ));
        }

        $processed = count($skus);

        self::success([
            'processed'    => $processed,
            'assigned'     => $assigned,
            'unchanged'    => $unchanged,
            'no_candidate' => $no_candidate,
            'cleared'      => count($ids_set_zero),
            'next_offset'  => $offset + $processed,
            'done'         => ($processed < $limit),
            'samples'      => $samples,
        ]);
    }

    // =========================================================================
    // AJAX: Danh sách tất cả cấu hình (bảng tổng) — PHÂN TRANG PHÍA SERVER
    //
    // Trước đây: "Xem tất cả" trả về TOÀN BỘ bảng (20k+ dòng) trong 1 request
    // → MySQL phải join full-table (BINARY chặn index), JSON hàng chục MB,
    //   trình duyệt dựng 20k <tr> → treo tab + nặng server.
    //
    // Nay: luôn LIMIT/OFFSET theo trang (tối đa 200 dòng/trang) và tách join
    // sản phẩm thành query phụ theo IN(...) → dùng được index uk_global_product_sku.
    //
    // POST: keyword, page (1-based), per_page (10..200)
    // Trả về: mappings, total, page, per_page, total_pages
    // =========================================================================

    const LIST_PER_PAGE_DEFAULT = 50;
    const LIST_PER_PAGE_MAX     = 200;
    /** Giới hạn số SKU lấy từ bảng sản phẩm khi tìm theo tên/barcode */
    const LIST_SEARCH_SKU_CAP   = 2000;

    public function ajax_list_mappings()
    {
        self::check_permission();
        self::check_nonce();

        global $wpdb;

        $mapping_table = self::table_mapping();

        $keyword = isset($_POST['keyword']) ? sanitize_text_field(wp_unslash($_POST['keyword'])) : '';

        $page = isset($_POST['page']) ? (int) $_POST['page'] : 1;
        if ($page < 1) {
            $page = 1;
        }

        $per_page = isset($_POST['per_page']) ? (int) $_POST['per_page'] : self::LIST_PER_PAGE_DEFAULT;
        if ($per_page < 10) {
            $per_page = 10;
        }
        if ($per_page > self::LIST_PER_PAGE_MAX) {
            $per_page = self::LIST_PER_PAGE_MAX;
        }

        // ── Dựng mệnh đề WHERE (chỉ trên bảng mapping, không join) ────────
        $where  = '(m.is_deleted = 0 OR m.is_deleted IS NULL)';
        $params = [];

        if ($keyword !== '') {
            $like = '%' . $wpdb->esc_like($keyword) . '%';

            // Tìm SKU khớp tên / barcode ở bảng sản phẩm bằng 1 query riêng,
            // tránh JOIN toàn bảng với BINARY (không dùng được index).
            $matched_skus = self::find_skus_by_product_keyword($like);

            $kw_parts = ['m.global_product_sku LIKE %s', 'm.convert_unit LIKE %s'];
            $params[] = $like;
            $params[] = $like;

            if (!empty($matched_skus)) {
                $placeholders = implode(',', array_fill(0, count($matched_skus), '%s'));
                $kw_parts[]   = "m.global_product_sku IN ({$placeholders})";
                $params       = array_merge($params, $matched_skus);
            }

            $where .= ' AND (' . implode(' OR ', $kw_parts) . ')';
        }

        // ── Đếm tổng ───────────────────────────────────────────────────────
        $count_sql = "SELECT COUNT(*) FROM {$mapping_table} m WHERE {$where}";
        if (!empty($params)) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $count_sql = $wpdb->prepare($count_sql, ...$params);
        }
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $total = (int) $wpdb->get_var($count_sql);

        $total_pages = ($per_page > 0) ? (int) ceil($total / $per_page) : 1;
        if ($total_pages < 1) {
            $total_pages = 1;
        }
        if ($page > $total_pages) {
            $page = $total_pages;
        }
        $offset = ($page - 1) * $per_page;

        // ── Lấy 1 trang dữ liệu (ORDER BY dùng được uk_sku_unit) ──────────
        $rows_sql = "SELECT
                m.global_htsoft_stock_convert_id,
                m.global_product_sku,
                m.convert_unit,
                m.convert_from_tgs,
                m.convert_to_htsoft,
                m.convert_note,
                m.unit_price,
                m.unit_weight_kg,
                m.is_default_unit,
                m.updated_at
             FROM {$mapping_table} m
             WHERE {$where}
             ORDER BY m.global_product_sku ASC, m.convert_unit ASC
             LIMIT %d OFFSET %d";

        $row_params   = $params;
        $row_params[] = $per_page;
        $row_params[] = $offset;

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results($wpdb->prepare($rows_sql, ...$row_params), ARRAY_A) ?: [];

        // ── Bổ sung tên / DVT gốc cho đúng các SKU trong trang ─────────────
        $rows = self::attach_product_info($rows);

        self::success([
            'mappings'    => $rows,
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $per_page,
            'total_pages' => $total_pages,
        ]);
    }

    /**
     * Tìm danh sách SKU theo tên / barcode sản phẩm (dùng cho ô tìm kiếm bảng tổng).
     * Có LIMIT cứng để không kéo về hàng chục nghìn SKU.
     *
     * @param string $like Chuỗi LIKE đã bọc %...%
     * @return string[]
     */
    private static function find_skus_by_product_keyword($like)
    {
        global $wpdb;

        $product_table = self::table_product();

        $sql = $wpdb->prepare(
            "SELECT global_product_sku
             FROM {$product_table}
             WHERE (is_deleted = 0 OR is_deleted IS NULL)
               AND global_product_sku IS NOT NULL
               AND global_product_sku <> ''
               AND (
                 global_product_name LIKE %s
                 OR global_product_barcode_main LIKE %s
               )
             LIMIT %d",
            $like,
            $like,
            self::LIST_SEARCH_SKU_CAP
        );

        $skus = $wpdb->get_col($sql);
        return $skus ? array_values(array_unique(array_map('strval', $skus))) : [];
    }

    /**
     * Gắn local_product_name / local_product_unit vào các dòng mapping của 1 trang.
     * Dùng IN(...) trên cột có UNIQUE KEY → nhanh, thay cho LEFT JOIN có BINARY.
     *
     * @param array $rows
     * @return array
     */
    private static function attach_product_info($rows)
    {
        if (empty($rows)) {
            return [];
        }

        global $wpdb;

        $skus = [];
        foreach ($rows as $row) {
            $sku = (string) ($row['global_product_sku'] ?? '');
            if ($sku !== '') {
                $skus[$sku] = true;
            }
        }
        $skus = array_keys($skus);

        $by_sku       = [];
        $by_sku_lower = [];

        if (!empty($skus)) {
            $product_table = self::table_product();
            $placeholders  = implode(',', array_fill(0, count($skus), '%s'));

            $sql = "SELECT global_product_sku, global_product_name, global_product_unit
                    FROM {$product_table}
                    WHERE (is_deleted = 0 OR is_deleted IS NULL)
                      AND global_product_sku IN ({$placeholders})";

            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $products = $wpdb->get_results($wpdb->prepare($sql, ...$skus), ARRAY_A) ?: [];

            foreach ($products as $p) {
                $key                = (string) $p['global_product_sku'];
                $by_sku[$key]       = $p;
                $by_sku_lower[mb_strtolower($key)] = $p;
            }
        }

        foreach ($rows as &$row) {
            $sku = (string) ($row['global_product_sku'] ?? '');
            $p   = null;
            if (isset($by_sku[$sku])) {
                $p = $by_sku[$sku];
            } elseif (isset($by_sku_lower[mb_strtolower($sku)])) {
                $p = $by_sku_lower[mb_strtolower($sku)];
            }
            $row['local_product_name'] = $p ? (string) $p['global_product_name'] : '';
            $row['local_product_unit'] = $p ? (string) $p['global_product_unit'] : '';
        }
        unset($row);

        return $rows;
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
                m.is_default_unit
             FROM {$mapping_table} m
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

        $hydrated = self::attach_product_info([$row]);
        self::success(['mapping' => $hydrated[0]]);
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
                    convert_from_tgs, convert_to_htsoft, convert_note, unit_price, unit_weight_kg,
                    is_default_unit, updated_at
             FROM {$mapping_table}
             WHERE BINARY global_product_sku = %s
               AND (is_deleted = 0 OR is_deleted IS NULL)
             ORDER BY is_default_unit DESC, convert_unit ASC
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
        $sql = "SELECT global_product_sku, convert_unit, convert_from_tgs, convert_to_htsoft, convert_note, unit_price, unit_weight_kg, is_default_unit
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
                    'is_default_unit'   => ((int) ($row['is_default_unit'] ?? 0) === 1) ? 1 : 0,
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

        // Không JOIN có BINARY (chặn index → full scan bảng sản phẩm cho mỗi dòng).
        // Lấy mapping trước, rồi bổ sung tên sản phẩm theo lô 1000 SKU.
        $rows = $wpdb->get_results(
            "SELECT
                m.global_product_sku,
                m.convert_unit,
                m.convert_to_htsoft,
                m.convert_note,
                m.unit_price,
                m.unit_weight_kg,
                m.is_default_unit
             FROM {$mapping_table} m
             WHERE (m.is_deleted = 0 OR m.is_deleted IS NULL)
             ORDER BY m.global_product_sku ASC, m.convert_unit ASC",
            ARRAY_A
        ) ?: [];

        $hydrated = [];
        foreach (array_chunk($rows, 1000) as $chunk) {
            foreach (self::attach_product_info($chunk) as $r) {
                $hydrated[] = $r;
            }
        }
        $rows = $hydrated;

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
                'is_default_unit'    => ((int) ($row['is_default_unit'] ?? 0) === 1) ? 1 : 0,
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
     * Logic xử lý:
     *   1. Chỉ xử lý các DVT có trong file Excel (không động đến DVT không có trong Excel)
     *   2. Nếu Excel có giá → dùng giá đó trực tiếp
     *   3. Nếu Excel không có giá → suy từ các DVT khác trong cùng SKU dựa vào tỷ lệ
     *   4. Trả về chi tiết từng dòng: old_price, new_price, status (updated/no_change/skipped)
     *
     * POST params:
     *   rows_json  – JSON array of { sku, unit, price }  (từng batch ~200 dòng)
     *
     * Response: { updated, no_change, skipped, errors[], details[] }
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
        $no_change     = 0;
        $skipped       = 0;
        $errors        = [];
        $details       = [];

        // ── Nhóm các dòng Excel theo SKU ──────────────────────────────────────
        $by_sku = [];
        foreach ($items as $idx => $item) {
            if (!is_array($item)) { continue; }
            $sku  = isset($item['sku'])  ? sanitize_text_field(trim((string) $item['sku']))  : '';
            $unit = isset($item['unit']) ? sanitize_text_field(trim((string) $item['unit'])) : '';
            if ($sku === '') { continue; }

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
                "SELECT global_htsoft_stock_convert_id, convert_unit, convert_to_htsoft, unit_price
                 FROM {$mapping_table}
                 WHERE BINARY global_product_sku = %s
                   AND (is_deleted = 0 OR is_deleted IS NULL)",
                $sku
            ), ARRAY_A);

            if (empty($db_rows)) {
                foreach ($excel_rows as $er) {
                    $skipped++;
                    $errors[] = "Dòng " . ($er['idx'] + 1) . " (SKU: {$sku}): không tìm thấy cấu hình trong DB.";
                    $details[] = [
                        'sku'        => $sku,
                        'unit'       => $er['unit'],
                        'old_price'  => null,
                        'new_price'  => null,
                        'status'     => 'skipped',
                        'note'       => 'Không tìm thấy cấu hình DVT trong DB',
                    ];
                }
                continue;
            }

            // Index DB rows theo unit (lowercase)
            $db_by_unit = [];
            foreach ($db_rows as $dr) {
                $db_by_unit[strtolower(trim($dr['convert_unit']))] = $dr;
            }

            // Tìm base price từ Excel rows có giá để suy cho DVT không có giá
            $base_price_per_unit = null;
            foreach ($excel_rows as $er) {
                if ($er['price'] !== null) {
                    $uk = strtolower(trim($er['unit']));
                    if (isset($db_by_unit[$uk])) {
                        $ratio = (float) $db_by_unit[$uk]['convert_to_htsoft'];
                        if ($ratio > 0.0001) {
                            $base_price_per_unit = (float) $er['price'] / $ratio;
                            break;
                        }
                    }
                }
            }

            // ── Chỉ xử lý các DVT có trong Excel ──────────────────────────────
            foreach ($excel_rows as $er) {
                $uk = strtolower(trim($er['unit']));

                if (!isset($db_by_unit[$uk])) {
                    $skipped++;
                    $details[] = [
                        'sku'        => $sku,
                        'unit'       => $er['unit'],
                        'old_price'  => null,
                        'new_price'  => null,
                        'status'     => 'skipped',
                        'note'       => 'Không tìm thấy DVT "' . $er['unit'] . '" trong DB',
                    ];
                    continue;
                }

                $dr            = $db_by_unit[$uk];
                $id            = (int) $dr['global_htsoft_stock_convert_id'];
                $current_price = ($dr['unit_price'] !== null && $dr['unit_price'] !== '') ? (float) $dr['unit_price'] : null;
                $ratio         = (float) $dr['convert_to_htsoft'];

                // Xác định giá cuối: ưu tiên Excel, nếu không có thì suy từ base
                if ($er['price'] !== null) {
                    $final_price = (float) $er['price'];
                } elseif ($base_price_per_unit !== null && $ratio > 0.0001) {
                    $final_price = round($base_price_per_unit * $ratio, 2);
                } else {
                    $skipped++;
                    $details[] = [
                        'sku'        => $sku,
                        'unit'       => $er['unit'],
                        'old_price'  => $current_price,
                        'new_price'  => null,
                        'status'     => 'skipped',
                        'note'       => 'Không có giá trong Excel và không đủ dữ liệu để quy đổi',
                    ];
                    continue;
                }

                // Kiểm tra nếu giá không thay đổi
                if ($current_price !== null && abs($current_price - $final_price) < 0.001) {
                    $no_change++;
                    $details[] = [
                        'sku'        => $sku,
                        'unit'       => $er['unit'],
                        'old_price'  => $current_price,
                        'new_price'  => $final_price,
                        'status'     => 'no_change',
                        'note'       => '',
                    ];
                    continue;
                }

                // Cập nhật DB
                $result = $wpdb->update(
                    $mapping_table,
                    ['unit_price' => $final_price, 'updated_at' => $now],
                    ['global_htsoft_stock_convert_id' => $id],
                    ['%f', '%s'],
                    ['%d']
                );

                if ($result !== false) {
                    $updated++;
                    $change_desc = $current_price !== null
                        ? 'Đã đổi từ ' . number_format($current_price, 0, ',', '.') . '₫ → ' . number_format($final_price, 0, ',', '.') . '₫'
                        : 'Đã đặt giá ' . number_format($final_price, 0, ',', '.') . '₫';
                    $details[] = [
                        'sku'        => $sku,
                        'unit'       => $er['unit'],
                        'old_price'  => $current_price,
                        'new_price'  => $final_price,
                        'status'     => 'updated',
                        'note'       => $change_desc,
                    ];
                } else {
                    $skipped++;
                    $errors[] = "SKU: {$sku}, DVT: {$er['unit']}: lỗi cập nhật.";
                    $details[] = [
                        'sku'        => $sku,
                        'unit'       => $er['unit'],
                        'old_price'  => $current_price,
                        'new_price'  => $final_price,
                        'status'     => 'skipped',
                        'note'       => 'Lỗi cập nhật DB',
                    ];
                }
            }
        }

        self::success([
            'updated'   => $updated,
            'no_change' => $no_change,
            'skipped'   => $skipped,
            'errors'    => $errors,
            'details'   => $details,
        ], "Cập nhật giá xong. Đã cập nhật: {$updated}, không đổi: {$no_change}, bỏ qua: {$skipped}.");
    }
}
