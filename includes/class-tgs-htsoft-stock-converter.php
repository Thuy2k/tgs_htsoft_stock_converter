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

        // ── Bảng giá ───────────────────────────────────────────────────
        add_action('wp_ajax_tgs_htsoft_converter_list_price_lists',       [$this, 'ajax_list_price_lists']);
        add_action('wp_ajax_tgs_htsoft_converter_save_price_list',        [$this, 'ajax_save_price_list']);
        add_action('wp_ajax_tgs_htsoft_converter_delete_price_list',      [$this, 'ajax_delete_price_list']);
        add_action('wp_ajax_tgs_htsoft_converter_list_price_list_blogs',  [$this, 'ajax_list_price_list_blogs']);
        add_action('wp_ajax_tgs_htsoft_converter_assign_price_list_blogs', [$this, 'ajax_assign_price_list_blogs']);

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

        // ── BASE: khai báo tỉ lệ quy đổi (nguồn cấu trúc duy nhất) ──────
        add_action('wp_ajax_tgs_htsoft_base_search_products',      [$this, 'ajax_base_search_products']);
        add_action('wp_ajax_tgs_htsoft_base_list_configs_by_sku',  [$this, 'ajax_base_list_configs_by_sku']);
        add_action('wp_ajax_tgs_htsoft_base_save_mapping',         [$this, 'ajax_base_save_mapping']);
        add_action('wp_ajax_tgs_htsoft_base_delete_mapping',       [$this, 'ajax_base_delete_mapping']);
        add_action('wp_ajax_tgs_htsoft_base_set_default_unit',     [$this, 'ajax_base_set_default_unit']);
        add_action('wp_ajax_tgs_htsoft_base_list_mappings',        [$this, 'ajax_base_list_mappings']);
        add_action('wp_ajax_tgs_htsoft_base_get_mapping',          [$this, 'ajax_base_get_mapping']);
        add_action('wp_ajax_tgs_htsoft_base_export_excel_rows',    [$this, 'ajax_base_export_excel_rows']);
        add_action('wp_ajax_tgs_htsoft_base_import_excel_rows',    [$this, 'ajax_base_import_excel_rows']);
        add_action('wp_ajax_tgs_htsoft_base_default_scan_prepare', [$this, 'ajax_base_default_scan_prepare']);
        add_action('wp_ajax_tgs_htsoft_base_default_scan_batch',   [$this, 'ajax_base_default_scan_batch']);
        add_action('wp_ajax_tgs_htsoft_base_sync_prepare',         [$this, 'ajax_base_sync_prepare']);
        add_action('wp_ajax_tgs_htsoft_base_sync_batch',           [$this, 'ajax_base_sync_batch']);

        // ── Bảng giá: bổ trợ giá theo tỉ lệ + ĐVT chính theo base ──────
        add_action('wp_ajax_tgs_htsoft_converter_fill_missing_prices',  [$this, 'ajax_fill_missing_prices']);
        add_action('wp_ajax_tgs_htsoft_converter_reset_default_to_base', [$this, 'ajax_reset_default_to_base']);

        // ── Lưới "tải hết" (Excel-like, virtualBody) ───────────────────
        add_action('wp_ajax_tgs_htsoft_converter_list_all', [$this, 'ajax_converter_list_all']);
        add_action('wp_ajax_tgs_htsoft_base_list_all',      [$this, 'ajax_base_list_all']);
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

    private static function table_price_list()
    {
        global $wpdb;
        return $wpdb->base_prefix . 'global_htsoft_price_list';
    }

    private static function table_price_list_blog()
    {
        global $wpdb;
        return $wpdb->base_prefix . 'global_htsoft_price_list_blog';
    }

    /** Bảng GỐC khai báo tỉ lệ quy đổi (không có giá, không sync xuống shop) */
    private static function table_unit_base()
    {
        global $wpdb;
        return $wpdb->base_prefix . 'global_htsoft_unit_base';
    }

    // =========================================================================
    // BẢNG GIÁ — API dùng chung (POS gọi được từ ngoài)
    //
    // Cấu trúc:
    //   wp_global_htsoft_price_list       → danh sách bảng giá
    //   wp_global_htsoft_price_list_blog  → website nào áp bảng giá nào (1-1)
    //   wp_global_htsoft_stock_convert.price_list_id → cấu hình thuộc bảng giá nào
    // =========================================================================

    /**
     * ID bảng giá mặc định (dùng khi website chưa được gán bảng giá riêng).
     *
     * @return int 0 nếu hệ thống chưa có bảng giá nào
     */
    public static function get_default_price_list_id()
    {
        global $wpdb;

        $table = self::table_price_list();

        $id = (int) $wpdb->get_var(
            "SELECT global_htsoft_price_list_id FROM {$table}
             WHERE is_default = 1
               AND (is_deleted = 0 OR is_deleted IS NULL)
             ORDER BY global_htsoft_price_list_id ASC
             LIMIT 1"
        );

        if (!$id) {
            $id = (int) $wpdb->get_var(
                "SELECT global_htsoft_price_list_id FROM {$table}
                 WHERE (is_deleted = 0 OR is_deleted IS NULL)
                 ORDER BY global_htsoft_price_list_id ASC
                 LIMIT 1"
            );
        }

        return $id;
    }

    /**
     * Bảng giá mà 1 website đang áp dụng.
     *
     * Đây là hàm POS nên gọi: lấy price_list_id của shop hiện tại rồi mới
     * truy vấn giá theo ĐVT trong bảng giá đó.
     *
     * @param int|null $blog_id Mặc định = website hiện tại
     * @return int price_list_id (rơi về bảng giá mặc định nếu chưa gán)
     */
    public static function get_price_list_id_for_blog($blog_id = null)
    {
        global $wpdb;

        if ($blog_id === null) {
            $blog_id = get_current_blog_id();
        }
        $blog_id = (int) $blog_id;

        $blog_table = self::table_price_list_blog();
        $list_table = self::table_price_list();

        $id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT b.global_htsoft_price_list_id
             FROM {$blog_table} b
             INNER JOIN {$list_table} l
                 ON l.global_htsoft_price_list_id = b.global_htsoft_price_list_id
                AND (l.is_deleted = 0 OR l.is_deleted IS NULL)
             WHERE b.blog_id = %d
               AND (b.is_deleted = 0 OR b.is_deleted IS NULL)
             LIMIT 1",
            $blog_id
        ));

        if (!$id) {
            $id = self::get_default_price_list_id();
        }

        return (int) apply_filters('tgs_htsoft_converter_price_list_for_blog', $id, $blog_id);
    }

    /**
     * Chuẩn hoá price_list_id nhận từ request.
     * Không hợp lệ / không tồn tại → rơi về bảng giá mặc định.
     */
    private static function resolve_price_list_id($raw)
    {
        global $wpdb;

        $id = (int) $raw;
        if ($id > 0) {
            $table = self::table_price_list();
            $ok = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT global_htsoft_price_list_id FROM {$table}
                 WHERE global_htsoft_price_list_id = %d
                   AND (is_deleted = 0 OR is_deleted IS NULL)
                 LIMIT 1",
                $id
            ));
            if ($ok) {
                return $ok;
            }
        }

        return self::get_default_price_list_id();
    }

    /** price_list_id gửi kèm request hiện tại (màn hình quản trị) */
    private static function posted_price_list_id()
    {
        return self::resolve_price_list_id($_POST['price_list_id'] ?? 0);
    }

    /**
     * price_list_id cho các request từ POS: ưu tiên tham số gửi lên,
     * không có thì lấy bảng giá của website đang gọi.
     */
    private static function pos_price_list_id()
    {
        if (isset($_POST['price_list_id']) && (int) $_POST['price_list_id'] > 0) {
            return self::resolve_price_list_id($_POST['price_list_id']);
        }
        return self::get_price_list_id_for_blog();
    }

    /**
     * API cho tgs_pos: lấy toàn bộ cấu hình ĐVT của danh sách SKU
     * theo bảng giá website đang áp dụng.
     *
     * @param string[] $skus
     * @param int|null $price_list_id null = tự tra theo website hiện tại
     * @return array { SKU: { DVT: {convert_to_htsoft, unit_price, is_default_unit, …} } }
     */
    public static function get_unit_configs_for_skus($skus, $price_list_id = null)
    {
        global $wpdb;

        $skus = array_values(array_unique(array_filter(array_map('strval', (array) $skus), function ($s) {
            return $s !== '';
        })));

        if (empty($skus)) {
            return [];
        }

        if ($price_list_id === null) {
            $price_list_id = self::get_price_list_id_for_blog();
        }

        $mapping_table = self::table_mapping();
        $placeholders  = implode(',', array_fill(0, count($skus), '%s'));

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT global_product_sku, convert_unit, convert_from_tgs, convert_to_htsoft,
                    convert_note, unit_price, unit_weight_kg, is_default_unit
             FROM {$mapping_table}
             WHERE price_list_id = %d
               AND (is_deleted = 0 OR is_deleted IS NULL)
               AND global_product_sku IN ({$placeholders})",
            (int) $price_list_id,
            ...$skus
        ), ARRAY_A) ?: [];

        $map = [];
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

        return $map;
    }

    /**
     * API cho tgs_pos: ĐVT bán chính (đã đánh dấu) của 1 SKU trong bảng giá đang áp.
     * Dùng khi POS tìm ra sản phẩm và cần thêm vào giỏ với đúng ĐVT + giá ưu tiên.
     *
     * @param string   $sku
     * @param int|null $price_list_id null = tự tra theo website hiện tại
     * @return array|null
     */
    public static function get_default_unit_for_sku($sku, $price_list_id = null)
    {
        global $wpdb;

        $sku = (string) $sku;
        if ($sku === '') {
            return null;
        }

        if ($price_list_id === null) {
            $price_list_id = self::get_price_list_id_for_blog();
        }

        $mapping_table = self::table_mapping();

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT global_htsoft_stock_convert_id, price_list_id, global_product_sku, convert_unit,
                    convert_to_htsoft, convert_note, unit_price, unit_weight_kg, is_default_unit
             FROM {$mapping_table}
             WHERE price_list_id = %d
               AND global_product_sku = %s
               AND is_default_unit = 1
               AND (is_deleted = 0 OR is_deleted IS NULL)
             LIMIT 1",
            (int) $price_list_id,
            $sku
        ), ARRAY_A);

        return $row ?: null;
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

    /** So sánh 2 giá trị decimal cho phép NULL (NULL == NULL, NULL != số) */
    private static function decimals_equal($a, $b)
    {
        $an = ($a === null || $a === '') ? null : (float) $a;
        $bn = ($b === null || $b === '') ? null : (float) $b;
        if ($an === null || $bn === null) {
            return $an === $bn;
        }
        return abs($an - $bn) < 0.005;
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
     * Đặt 1 dòng làm ĐVT bán chính của SKU TRONG MỘT BẢNG GIÁ,
     * mọi dòng còn lại của SKU đó (cùng bảng giá) về 0.
     *
     * @param string   $sku
     * @param int|null $winner_id     ID dòng được chọn (null = xoá hết cờ của SKU)
     * @param int      $price_list_id Bảng giá đang thao tác
     * @return int Số dòng thực sự bị thay đổi
     */
    private static function apply_default_for_sku($sku, $winner_id, $price_list_id)
    {
        global $wpdb;

        $table = self::table_mapping();
        $now   = current_time('mysql');
        $touch = 0;

        // Hạ cờ tất cả dòng khác của SKU trong CÙNG bảng giá (kể cả dòng đã xoá mềm)
        $cleared = $wpdb->query($wpdb->prepare(
            "UPDATE {$table}
             SET is_default_unit = 0, updated_at = %s
             WHERE price_list_id = %d
               AND BINARY global_product_sku = %s
               AND is_default_unit <> 0
               AND global_htsoft_stock_convert_id <> %d",
            $now,
            (int) $price_list_id,
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
     * Lấy toàn bộ dòng cấu hình đang hoạt động của 1 SKU trong 1 bảng giá
     * (dùng cho việc chọn lại ĐVT chính).
     */
    private static function fetch_active_configs($sku, $price_list_id)
    {
        global $wpdb;

        $table = self::table_mapping();
        return $wpdb->get_results($wpdb->prepare(
            "SELECT global_htsoft_stock_convert_id, convert_unit, convert_to_htsoft, unit_price, is_default_unit
             FROM {$table}
             WHERE price_list_id = %d
               AND BINARY global_product_sku = %s
               AND (is_deleted = 0 OR is_deleted IS NULL)
             ORDER BY global_htsoft_stock_convert_id ASC",
            (int) $price_list_id,
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
        $scope = (isset($_POST['scope']) && $_POST['scope'] === 'base') ? 'base' : 'pricelist';
        $rows  = ($scope === 'base')
            ? self::attach_base_config_count($rows)
            : self::attach_config_count($rows, self::posted_price_list_id());

        self::success(['products' => $rows]);
    }

    /** Tìm sản phẩm cho màn BASE (đếm config_count từ wp_global_htsoft_unit_base) */
    public function ajax_base_search_products()
    {
        $_POST['scope'] = 'base';
        $this->ajax_search_products();
    }

    /**
     * Gắn config_count (số DVT đã cấu hình trong bảng giá đang xem) cho danh sách sản phẩm.
     * Dùng IN(...) trên cột có UNIQUE KEY thay cho LEFT JOIN + BINARY.
     *
     * @param array $rows          Mỗi phần tử có key local_product_sku
     * @param int   $price_list_id Bảng giá đang thao tác
     * @return array
     */
    private static function attach_config_count($rows, $price_list_id)
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
                    WHERE price_list_id = %d
                      AND (is_deleted = 0 OR is_deleted IS NULL)
                      AND global_product_sku IN ({$placeholders})
                    GROUP BY global_product_sku";

            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $found = $wpdb->get_results(
                $wpdb->prepare($sql, (int) $price_list_id, ...$skus),
                ARRAY_A
            ) ?: [];
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
        $price_list_id = self::posted_price_list_id();

        $sql = $wpdb->prepare(
            "SELECT
                global_htsoft_stock_convert_id,
                price_list_id,
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
             WHERE price_list_id = %d
               AND BINARY global_product_sku = %s
               AND (is_deleted = 0 OR is_deleted IS NULL)
             ORDER BY convert_unit ASC, global_htsoft_stock_convert_id ASC",
            $price_list_id,
            $sku
        );
        $rows = $wpdb->get_results($sql, ARRAY_A);
        self::success(['configs' => $rows ?: [], 'price_list_id' => $price_list_id]);
    }

    // =========================================================================
    // AJAX: Lưu cấu hình DVT trong 1 BẢNG GIÁ
    //
    // Từ 01/09/2026: cấu trúc (ĐVT / tỉ lệ / khối lượng) do BASE quy định — bảng
    // giá KHÔNG thêm/xóa ĐVT, KHÔNG sửa tỉ lệ. Ở đây chỉ sửa được:
    //   - unit_price      (giá theo ĐVT, riêng từng bảng giá)
    //   - convert_note    (ghi chú — khác base ⇒ note_overridden = 1)
    //   - is_default_unit (đổi ĐVT bán chính cho riêng bảng giá ⇒ default_unit_overridden = 1)
    //
    // POST: id (> 0 bắt buộc), unit_price, convert_note, is_default_unit
    // =========================================================================

    public function ajax_save_mapping()
    {
        self::check_permission();
        self::check_nonce();

        global $wpdb;

        $mapping_table = self::table_mapping();

        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        if ($id <= 0) {
            self::error('Thêm / xóa ĐVT phải làm ở "Khai báo tỉ lệ quy đổi" (Bảng gốc). Bảng giá chỉ khai giá.');
            return;
        }

        $price_list_id = self::posted_price_list_id();
        if (!$price_list_id) {
            self::error('Chưa chọn bảng giá.');
            return;
        }

        // Dòng gốc trong bảng giá
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT global_htsoft_stock_convert_id, price_list_id, global_product_sku,
                    convert_unit, convert_to_htsoft, unit_price, is_default_unit
             FROM {$mapping_table}
             WHERE global_htsoft_stock_convert_id = %d
               AND (is_deleted = 0 OR is_deleted IS NULL)
             LIMIT 1",
            $id
        ), ARRAY_A);

        if (!$row || (int) $row['price_list_id'] !== (int) $price_list_id) {
            self::error('Không tìm thấy dòng cấu hình trong bảng giá này.');
            return;
        }

        $sku  = (string) $row['global_product_sku'];
        $unit = (string) $row['convert_unit'];
        $now  = current_time('mysql');

        // ── Giá bán (NULL nếu để trống) ────────────────────────────────
        $raw_price  = isset($_POST['unit_price']) ? trim(wp_unslash($_POST['unit_price'])) : '';
        $unit_price = null;
        if ($raw_price !== '') {
            $cleaned = (float) str_replace(',', '.', $raw_price);
            if ($cleaned >= 0) {
                $unit_price = $cleaned;
            }
        }

        // ── Ghi chú: so với ghi chú GỐC ở base để đặt cờ override ──────
        $note      = isset($_POST['convert_note']) ? sanitize_text_field(wp_unslash($_POST['convert_note'])) : '';
        $base_note = (string) $wpdb->get_var($wpdb->prepare(
            "SELECT convert_note FROM " . self::table_unit_base() . "
             WHERE BINARY global_product_sku = %s AND convert_unit = %s LIMIT 1",
            $sku,
            $unit
        ));
        if ($note === '') {
            $note = ($base_note !== '') ? $base_note : self::build_default_note($unit, (float) $row['convert_to_htsoft']);
        }
        $note_overridden = ($note !== $base_note && $base_note !== '') ? 1 : 0;

        $is_default_unit = !empty($_POST['is_default_unit']) ? 1 : 0;

        $data = [
            'unit_price'      => $unit_price,
            'convert_note'    => $note,
            'note_overridden' => $note_overridden,
            'updated_at'      => $now,
        ];
        $formats = [$unit_price !== null ? '%f' : '%s', '%s', '%d', '%s'];

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

        // ── Đổi ĐVT bán chính cho RIÊNG bảng giá này ───────────────────
        if ($is_default_unit === 1 && (int) $row['is_default_unit'] !== 1) {
            self::apply_default_for_sku($sku, $id, $price_list_id);
            $wpdb->query($wpdb->prepare(
                "UPDATE {$mapping_table} SET default_unit_overridden = 1, updated_at = %s
                 WHERE price_list_id = %d AND BINARY global_product_sku = %s",
                $now,
                $price_list_id,
                $sku
            ));
        }

        // ── ĐVT cùng SKU trong bảng giá này còn thiếu giá → cho JS hỏi ──
        $missing = $wpdb->get_results($wpdb->prepare(
            "SELECT convert_unit, convert_to_htsoft
             FROM {$mapping_table}
             WHERE price_list_id = %d
               AND BINARY global_product_sku = %s
               AND (is_deleted = 0 OR is_deleted IS NULL)
               AND (unit_price IS NULL OR unit_price = '')
             ORDER BY convert_to_htsoft ASC",
            $price_list_id,
            $sku
        ), ARRAY_A) ?: [];

        self::success([
            'id'                 => $id,
            'global_product_sku' => $sku,
            'saved_unit'         => $unit,
            'saved_unit_price'   => $unit_price,
            'saved_unit_ratio'   => (float) $row['convert_to_htsoft'],
            'missing_price_units' => array_map(function ($m) {
                return [
                    'unit'  => (string) $m['convert_unit'],
                    'ratio' => (float) $m['convert_to_htsoft'],
                ];
            }, $missing),
        ], 'Đã lưu giá cho ĐVT "' . $unit . '".');
    }

    // =========================================================================
    // AJAX: Xóa 1 cấu hình DVT trong bảng giá
    //
    // Từ 01/09/2026: xóa ĐVT là thay đổi CẤU TRÚC ⇒ phải làm ở Bảng gốc
    // (ajax_base_delete_mapping). Ở bảng giá chỉ chặn.
    // =========================================================================

    public function ajax_delete_mapping()
    {
        self::check_permission();
        self::check_nonce();

        self::error('ĐVT do "Khai báo tỉ lệ quy đổi" (Bảng gốc) quản lý. '
            . 'Muốn xóa ĐVT hãy xóa ở Bảng gốc — mọi bảng giá sẽ tự cập nhật theo.');
    }

    /** @deprecated Xóa cứng theo ID — chỉ dùng nội bộ / lịch sử */
    public function ajax_delete_mapping_legacy()
    {
        self::check_permission();
        self::check_nonce();

        global $wpdb;

        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        if ($id <= 0) {
            self::error('ID không hợp lệ.');
            return;
        }

        // Ghi nhớ bảng giá + SKU + có phải ĐVT bán chính không, để chọn lại sau khi xóa
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT price_list_id, global_product_sku, is_default_unit
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
            $sku      = (string) $row['global_product_sku'];
            $list_id  = (int) $row['price_list_id'];
            $winner   = self::pick_default_config(self::fetch_active_configs($sku, $list_id));
            if ($winner) {
                $new_default_id = (int) $winner['global_htsoft_stock_convert_id'];
                self::apply_default_for_sku($sku, $new_default_id, $list_id);
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
            "SELECT price_list_id, global_product_sku, convert_unit
             FROM " . self::table_mapping() . "
             WHERE global_htsoft_stock_convert_id = %d
               AND (is_deleted = 0 OR is_deleted IS NULL)",
            $id
        ), ARRAY_A);

        if (!$row) {
            self::error('Không tìm thấy cấu hình.');
            return;
        }

        $sku     = (string) $row['global_product_sku'];
        $list_id = (int) $row['price_list_id'];

        self::apply_default_for_sku($sku, $id, $list_id);

        // Bảng giá tự chọn ĐVT bán chính ⇒ đánh dấu override để propagation từ
        // Base KHÔNG ghi đè lựa chọn này.
        $wpdb->query($wpdb->prepare(
            "UPDATE " . self::table_mapping() . "
             SET default_unit_overridden = 1, updated_at = %s
             WHERE price_list_id = %d AND BINARY global_product_sku = %s",
            current_time('mysql'),
            $list_id,
            $sku
        ));

        $label = ($row['convert_unit'] !== '') ? $row['convert_unit'] : 'Mặc định';
        self::success(
            ['id' => $id, 'global_product_sku' => $sku],
            'Đã đặt "' . $label . '" làm ĐVT bán chính cho bảng giá này.'
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

        $table         = self::table_mapping();
        $price_list_id = self::posted_price_list_id();

        $total = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT global_product_sku)
             FROM {$table}
             WHERE price_list_id = %d
               AND (is_deleted = 0 OR is_deleted IS NULL)",
            $price_list_id
        ));

        self::success([
            'total_skus'    => $total,
            'batch_size'    => self::DEFAULT_SCAN_BATCH_SIZE,
            'price_list_id' => $price_list_id,
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

        $price_list_id = self::posted_price_list_id();

        // ── Lấy 1 lô SKU (thứ tự cố định → phân trang ổn định giữa các batch) ──
        $skus = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT global_product_sku
             FROM {$table}
             WHERE price_list_id = %d
               AND (is_deleted = 0 OR is_deleted IS NULL)
             ORDER BY global_product_sku ASC
             LIMIT %d OFFSET %d",
            $price_list_id,
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
             WHERE price_list_id = %d
               AND global_product_sku IN ({$placeholders})
             ORDER BY global_htsoft_stock_convert_id ASC",
            $price_list_id,
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
        $price_list_id = self::posted_price_list_id();

        $where  = 'm.price_list_id = %d AND (m.is_deleted = 0 OR m.is_deleted IS NULL)';
        $params = [$price_list_id];

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
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $count_sql = $wpdb->prepare($count_sql, ...$params);
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
            'mappings'      => $rows,
            'total'         => $total,
            'page'          => $page,
            'per_page'      => $per_page,
            'total_pages'   => $total_pages,
            'price_list_id' => $price_list_id,
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
        $price_list_id = self::pos_price_list_id();

        $sql = $wpdb->prepare(
            "SELECT global_htsoft_stock_convert_id, price_list_id, global_product_sku, convert_unit,
                    convert_from_tgs, convert_to_htsoft, convert_note, unit_price, unit_weight_kg,
                    is_default_unit, updated_at
             FROM {$mapping_table}
             WHERE price_list_id = %d
               AND BINARY global_product_sku = %s
               AND (is_deleted = 0 OR is_deleted IS NULL)
             ORDER BY is_default_unit DESC, convert_unit ASC
             LIMIT 1",
            $price_list_id,
            $sku
        );
        $row = $wpdb->get_row($sql, ARRAY_A);
        self::success(['mapping' => $row ?: null, 'price_list_id' => $price_list_id]);
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

        // Lấy theo ĐÚNG bảng giá website đang áp dụng
        $price_list_id = self::pos_price_list_id();
        $map           = self::get_unit_configs_for_skus($skus, $price_list_id);

        self::success(['mappings' => $map, 'price_list_id' => $price_list_id]);
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

        $price_list_id = self::posted_price_list_id();

        // Không JOIN có BINARY (chặn index → full scan bảng sản phẩm cho mỗi dòng).
        // Lấy mapping trước, rồi bổ sung tên sản phẩm theo lô 1000 SKU.
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT
                m.global_product_sku,
                m.convert_unit,
                m.convert_to_htsoft,
                m.convert_note,
                m.unit_price,
                m.unit_weight_kg,
                m.is_default_unit
             FROM {$mapping_table} m
             WHERE m.price_list_id = %d
               AND (m.is_deleted = 0 OR m.is_deleted IS NULL)
             ORDER BY m.global_product_sku ASC, m.convert_unit ASC",
            $price_list_id
        ), ARRAY_A) ?: [];

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
            'exported_at'   => current_time('mysql'),
            'count'         => count($export),
            'rows'          => $export,
            'price_list_id' => $price_list_id,
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
        $price_list_id = self::posted_price_list_id();
        $created       = 0;
        $updated       = 0;
        $skipped       = 0;
        $errors        = [];

        if (!$price_list_id) {
            self::error('Chưa chọn bảng giá để import.');
            return;
        }

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
                'price_list_id'      => $price_list_id,
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
                '%d',
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

            // Tìm row theo (bảng giá, sku, unit) — unicode_ci → case-insensitive cho unit
            $existing_id = $wpdb->get_var($wpdb->prepare(
                "SELECT global_htsoft_stock_convert_id
                 FROM {$mapping_table}
                 WHERE price_list_id = %d
                   AND BINARY global_product_sku = %s
                   AND convert_unit = %s
                 LIMIT 1",
                $price_list_id,
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
        $price_list_id = self::posted_price_list_id();

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT global_product_sku, convert_unit, convert_from_tgs, convert_to_htsoft, convert_note, unit_weight_kg, updated_at
             FROM {$mapping_table}
             WHERE price_list_id = %d
               AND (is_deleted = 0 OR is_deleted IS NULL)
             ORDER BY global_product_sku ASC, convert_unit ASC",
            $price_list_id
        ), ARRAY_A);

        foreach ($rows as &$row) {
            $row['convert_from_tgs']  = (string)((int) floatval($row['convert_from_tgs']));
            $row['convert_to_htsoft'] = (string)((int) floatval($row['convert_to_htsoft']));
        }
        unset($row);

        self::success([
            'exported_at'   => current_time('mysql'),
            'count'         => count($rows),
            'mappings'      => $rows ?: [],
            'price_list_id' => $price_list_id,
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
        $price_list_id = self::posted_price_list_id();
        $created       = 0;
        $updated       = 0;
        $skipped       = 0;

        if (!$price_list_id) {
            self::error('Chưa chọn bảng giá để import.');
            return;
        }

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
                'price_list_id'      => $price_list_id,
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
            $formats = ['%d', '%s', '%s', '%f', '%f', '%s', $unit_weight_kg !== null ? '%f' : '%s', '%d', '%d', '%s', '%s'];

            $existing_id = $wpdb->get_var($wpdb->prepare(
                "SELECT global_htsoft_stock_convert_id
                 FROM {$mapping_table}
                 WHERE price_list_id = %d
                   AND BINARY global_product_sku = %s
                   AND convert_unit = %s
                 LIMIT 1",
                $price_list_id,
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
        $price_list_id = self::posted_price_list_id();
        // Mặc định KHÔNG tự suy giá cho ĐVT thiếu: giá phải lấy CHUẨN theo ĐVT
        // trong file Excel (dữ liệu từ phần mềm cũ). Chỉ khi người dùng chủ động
        // bật checkbox mới suy giá theo tỉ lệ.
        $derive_missing = !empty($_POST['derive_missing']);
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
                 WHERE price_list_id = %d
                   AND BINARY global_product_sku = %s
                   AND (is_deleted = 0 OR is_deleted IS NULL)",
                $price_list_id,
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
            // (CHỈ khi bật cờ derive_missing — mặc định tắt)
            $base_price_per_unit = null;
            foreach (($derive_missing ? $excel_rows : []) as $er) {
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

                // Xác định giá cuối: LẤY CHUẨN theo giá trong Excel. Chỉ suy theo
                // tỉ lệ khi người dùng bật cờ derive_missing.
                if ($er['price'] !== null) {
                    $final_price = (float) $er['price'];
                } elseif ($derive_missing && $base_price_per_unit !== null && $ratio > 0.0001) {
                    $final_price = round($base_price_per_unit * $ratio, 2);
                } else {
                    $skipped++;
                    $details[] = [
                        'sku'        => $sku,
                        'unit'       => $er['unit'],
                        'old_price'  => $current_price,
                        'new_price'  => null,
                        'status'     => 'skipped',
                        'note'       => 'File không có giá cho ĐVT này — giữ nguyên (để trống)',
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

    // =========================================================================
    // AJAX: DANH SÁCH BẢNG GIÁ (kèm thống kê)
    // =========================================================================

    public function ajax_list_price_lists()
    {
        self::check_permission();
        self::check_nonce();

        global $wpdb;

        $list_table    = self::table_price_list();
        $blog_table    = self::table_price_list_blog();
        $mapping_table = self::table_mapping();

        $lists = $wpdb->get_results(
            "SELECT global_htsoft_price_list_id, price_list_code, price_list_name,
                    price_list_note, price_list_status, is_default, updated_at
             FROM {$list_table}
             WHERE (is_deleted = 0 OR is_deleted IS NULL)
             ORDER BY is_default DESC, price_list_name ASC",
            ARRAY_A
        ) ?: [];

        // Thống kê: số dòng cấu hình + số mã hàng của từng bảng giá (1 query)
        $stats = $wpdb->get_results(
            "SELECT price_list_id,
                    COUNT(*) AS config_count,
                    COUNT(DISTINCT global_product_sku) AS sku_count,
                    SUM(CASE WHEN is_default_unit = 1 THEN 1 ELSE 0 END) AS default_unit_count
             FROM {$mapping_table}
             WHERE (is_deleted = 0 OR is_deleted IS NULL)
             GROUP BY price_list_id",
            ARRAY_A
        ) ?: [];

        $stat_map = [];
        foreach ($stats as $s) {
            $stat_map[(int) $s['price_list_id']] = $s;
        }

        // Website đang áp từng bảng giá
        $assigns = $wpdb->get_results(
            "SELECT global_htsoft_price_list_id, blog_id
             FROM {$blog_table}
             WHERE (is_deleted = 0 OR is_deleted IS NULL)",
            ARRAY_A
        ) ?: [];

        $blogs_by_list = [];
        foreach ($assigns as $a) {
            $blogs_by_list[(int) $a['global_htsoft_price_list_id']][] = (int) $a['blog_id'];
        }

        foreach ($lists as &$row) {
            $id  = (int) $row['global_htsoft_price_list_id'];
            $st  = $stat_map[$id] ?? [];
            $bl  = $blogs_by_list[$id] ?? [];

            $row['config_count']       = (int) ($st['config_count'] ?? 0);
            $row['sku_count']          = (int) ($st['sku_count'] ?? 0);
            $row['default_unit_count'] = (int) ($st['default_unit_count'] ?? 0);
            $row['blog_ids']           = $bl;
            $row['blog_count']         = count($bl);
            $row['blog_names']         = array_values(array_map([__CLASS__, 'blog_label'], $bl));
        }
        unset($row);

        self::success([
            'price_lists'    => $lists,
            'default_id'     => self::get_default_price_list_id(),
            'current_blog_id' => get_current_blog_id(),
        ]);
    }

    /** Tên hiển thị của 1 website trong multisite */
    private static function blog_label($blog_id)
    {
        $blog_id = (int) $blog_id;

        if (function_exists('get_blog_details')) {
            $details = get_blog_details($blog_id);
            if ($details && !empty($details->blogname)) {
                return $details->blogname;
            }
        }

        return 'Site #' . $blog_id;
    }

    // =========================================================================
    // AJAX: Tạo / cập nhật bảng giá
    // POST: id (0 = tạo mới), price_list_name, price_list_code, price_list_note,
    //       price_list_status, is_default, copy_from_id (chỉ khi tạo mới)
    // =========================================================================

    public function ajax_save_price_list()
    {
        self::check_permission();
        self::check_nonce();

        global $wpdb;

        $table = self::table_price_list();

        $id     = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        $name   = isset($_POST['price_list_name']) ? sanitize_text_field(wp_unslash($_POST['price_list_name'])) : '';
        $code   = isset($_POST['price_list_code']) ? sanitize_text_field(wp_unslash($_POST['price_list_code'])) : '';
        $note   = isset($_POST['price_list_note']) ? sanitize_textarea_field(wp_unslash($_POST['price_list_note'])) : '';
        $status = !empty($_POST['price_list_status']) ? 1 : 0;
        $is_def = !empty($_POST['is_default']) ? 1 : 0;

        if ($name === '') {
            self::error('Vui lòng nhập tên bảng giá.');
            return;
        }

        // Mã bảng giá: bỏ trống → sinh từ tên (bỏ dấu, in hoa)
        if ($code === '') {
            $code = strtoupper(preg_replace('/[^a-z0-9]+/', '_', self::normalize_unit($name)));
            $code = trim(substr($code, 0, 40), '_');
            if ($code === '') {
                $code = 'BG';
            }
        }
        $code = substr($code, 0, 50);

        // Mã không được trùng
        $dup = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT global_htsoft_price_list_id FROM {$table}
             WHERE price_list_code = %s AND global_htsoft_price_list_id <> %d
             LIMIT 1",
            $code,
            $id
        ));
        if ($dup) {
            // Tự thêm hậu tố để không chặn thao tác của người dùng
            $code = substr($code, 0, 44) . '_' . wp_rand(100, 999);
        }

        $now  = current_time('mysql');
        $data = [
            'price_list_code'   => $code,
            'price_list_name'   => $name,
            'price_list_note'   => $note,
            'price_list_status' => $status,
            'is_default'        => $is_def,
            'user_id'           => get_current_user_id(),
            'is_deleted'        => 0,
            'deleted_at'        => null,
            'updated_at'        => $now,
        ];
        $formats = ['%s', '%s', '%s', '%d', '%d', '%d', '%d', '%s', '%s'];

        if ($id > 0) {
            $ok = $wpdb->update($table, $data, ['global_htsoft_price_list_id' => $id], $formats, ['%d']);
            if ($ok === false) {
                self::error('Không thể cập nhật bảng giá.');
                return;
            }
            self::sync_default_flag($id, $is_def);
            self::success(['id' => $id], 'Đã cập nhật bảng giá "' . $name . '".');
            return;
        }

        $data['created_at'] = $now;
        $ok = $wpdb->insert($table, $data, array_merge($formats, ['%s']));
        if (!$ok) {
            self::error('Không thể tạo bảng giá.');
            return;
        }

        $new_id = (int) $wpdb->insert_id;
        self::sync_default_flag($new_id, $is_def);

        // Bảng giá mới LUÔN lấy cấu trúc (ĐVT + tỉ lệ quy đổi + ĐVT bán chính)
        // từ Base; giá để trống. Không còn "nhân bản cấu trúc" từ bảng giá khác.
        $copied  = self::populate_price_list_from_base($new_id);

        // Tuỳ chọn clone: chép thêm GIÁ + ghi chú + ĐVT bán chính từ bảng giá nguồn.
        $copy_id = isset($_POST['copy_from_id']) ? (int) $_POST['copy_from_id'] : 0;
        if ($copy_id > 0 && $copy_id !== $new_id) {
            self::overlay_price_list_values($copy_id, $new_id);
        }

        self::success(
            ['id' => $new_id, 'copied' => $copied],
            $copy_id > 0
                ? 'Đã tạo bảng giá "' . $name . '" (cấu trúc từ Bảng gốc, chép giá từ bảng giá nguồn).'
                : 'Đã tạo bảng giá "' . $name . '" theo cấu trúc Bảng gốc.'
        );
    }

    /** Chỉ 1 bảng giá được là mặc định */
    private static function sync_default_flag($id, $is_default)
    {
        global $wpdb;

        if (!$is_default) {
            return;
        }

        $table = self::table_price_list();
        $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET is_default = 0, updated_at = %s
             WHERE global_htsoft_price_list_id <> %d AND is_default <> 0",
            current_time('mysql'),
            (int) $id
        ));
    }

    /**
     * Sao chép toàn bộ cấu hình ĐVT từ bảng giá này sang bảng giá khác.
     * Bỏ qua các (SKU, DVT) đã tồn tại ở bảng giá đích.
     *
     * @return int Số dòng đã chép
     */
    private static function copy_configs_between_lists($from_id, $to_id)
    {
        global $wpdb;

        $table = self::table_mapping();
        $now   = current_time('mysql');
        $user  = get_current_user_id();

        $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$table}
                (price_list_id, global_product_sku, convert_unit, convert_from_tgs, convert_to_htsoft,
                 convert_note, unit_price, unit_weight_kg, is_default_unit,
                 user_id, is_deleted, deleted_at, created_at, updated_at)
             SELECT %d, global_product_sku, convert_unit, convert_from_tgs, convert_to_htsoft,
                    convert_note, unit_price, unit_weight_kg, is_default_unit,
                    %d, 0, NULL, %s, %s
             FROM {$table}
             WHERE price_list_id = %d
               AND (is_deleted = 0 OR is_deleted IS NULL)",
            (int) $to_id,
            (int) $user,
            $now,
            $now,
            (int) $from_id
        ));

        return (int) $wpdb->rows_affected;
    }

    // =========================================================================
    // AJAX: Xóa bảng giá (xóa mềm)
    // =========================================================================

    public function ajax_delete_price_list()
    {
        self::check_permission();
        self::check_nonce();

        global $wpdb;

        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        if ($id <= 0) {
            self::error('ID bảng giá không hợp lệ.');
            return;
        }

        $list_table = self::table_price_list();
        $blog_table = self::table_price_list_blog();

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT price_list_name, is_default FROM {$list_table}
             WHERE global_htsoft_price_list_id = %d
               AND (is_deleted = 0 OR is_deleted IS NULL)",
            $id
        ), ARRAY_A);

        if (!$row) {
            self::error('Không tìm thấy bảng giá.');
            return;
        }

        if ((int) $row['is_default'] === 1) {
            self::error('Không thể xóa bảng giá mặc định. Hãy đặt bảng giá khác làm mặc định trước.');
            return;
        }

        // Còn website đang áp → chặn, tránh POS mất giá đột ngột
        $in_use = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$blog_table}
             WHERE global_htsoft_price_list_id = %d
               AND (is_deleted = 0 OR is_deleted IS NULL)",
            $id
        ));
        if ($in_use > 0) {
            self::error('Đang có ' . $in_use . ' website áp dụng bảng giá này. Hãy gỡ áp dụng trước khi xóa.');
            return;
        }

        $now = current_time('mysql');
        $wpdb->update(
            $list_table,
            ['is_deleted' => 1, 'deleted_at' => $now, 'updated_at' => $now],
            ['global_htsoft_price_list_id' => $id],
            ['%d', '%s', '%s'],
            ['%d']
        );

        // Cấu hình bên trong cũng đánh dấu xóa để shop đồng bộ theo
        $wpdb->query($wpdb->prepare(
            "UPDATE " . self::table_mapping() . "
             SET is_deleted = 1, deleted_at = %s, updated_at = %s
             WHERE price_list_id = %d AND (is_deleted = 0 OR is_deleted IS NULL)",
            $now,
            $now,
            $id
        ));

        self::success(['id' => $id], 'Đã xóa bảng giá "' . $row['price_list_name'] . '".');
    }

    // =========================================================================
    // AJAX: Danh sách website + bảng giá đang áp (cho modal "Áp dụng")
    // =========================================================================

    public function ajax_list_price_list_blogs()
    {
        self::check_permission();
        self::check_nonce();

        global $wpdb;

        $blog_table = self::table_price_list_blog();

        $assigned = $wpdb->get_results(
            "SELECT blog_id, global_htsoft_price_list_id
             FROM {$blog_table}
             WHERE (is_deleted = 0 OR is_deleted IS NULL)",
            ARRAY_A
        ) ?: [];

        $assigned_map = [];
        foreach ($assigned as $a) {
            $assigned_map[(int) $a['blog_id']] = (int) $a['global_htsoft_price_list_id'];
        }

        // Tên bảng giá để hiển thị "đang áp bảng giá nào"
        $names = $wpdb->get_results(
            "SELECT global_htsoft_price_list_id, price_list_name
             FROM " . self::table_price_list() . "
             WHERE (is_deleted = 0 OR is_deleted IS NULL)",
            ARRAY_A
        ) ?: [];

        $name_map = [];
        foreach ($names as $n) {
            $name_map[(int) $n['global_htsoft_price_list_id']] = (string) $n['price_list_name'];
        }

        $blogs = [];
        if (function_exists('get_sites')) {
            foreach (get_sites(['number' => 1000, 'orderby' => 'id']) as $site) {
                $bid       = (int) $site->blog_id;
                $list_id   = $assigned_map[$bid] ?? 0;
                $blogs[] = [
                    'blog_id'         => $bid,
                    'blog_name'       => self::blog_label($bid),
                    'blog_url'        => (string) $site->domain . (string) $site->path,
                    'price_list_id'   => $list_id,
                    'price_list_name' => $list_id ? ($name_map[$list_id] ?? '') : '',
                ];
            }
        } else {
            $bid     = get_current_blog_id();
            $list_id = $assigned_map[$bid] ?? 0;
            $blogs[] = [
                'blog_id'         => $bid,
                'blog_name'       => self::blog_label($bid),
                'blog_url'        => '',
                'price_list_id'   => $list_id,
                'price_list_name' => $list_id ? ($name_map[$list_id] ?? '') : '',
            ];
        }

        self::success(['blogs' => $blogs]);
    }

    // =========================================================================
    // AJAX: Áp dụng bảng giá cho danh sách website
    // POST: price_list_id, blog_ids (JSON array)
    //
    // Mỗi website chỉ áp 1 bảng giá ⇒ upsert theo blog_id (UNIQUE),
    // các website bỏ tick sẽ được gỡ khỏi bảng giá này.
    // =========================================================================

    public function ajax_assign_price_list_blogs()
    {
        self::check_permission();
        self::check_nonce();

        global $wpdb;

        $price_list_id = isset($_POST['price_list_id']) ? (int) $_POST['price_list_id'] : 0;
        if ($price_list_id <= 0) {
            self::error('Chưa chọn bảng giá.');
            return;
        }

        $raw      = isset($_POST['blog_ids']) ? wp_unslash($_POST['blog_ids']) : '[]';
        $blog_ids = json_decode($raw, true);
        if (!is_array($blog_ids)) {
            $blog_ids = [];
        }
        $blog_ids = array_values(array_unique(array_filter(array_map('intval', $blog_ids))));

        $blog_table = self::table_price_list_blog();
        $now        = current_time('mysql');
        $user_id    = get_current_user_id();

        $assigned = 0;

        foreach ($blog_ids as $blog_id) {
            $existing_id = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT global_htsoft_price_list_blog_id FROM {$blog_table} WHERE blog_id = %d LIMIT 1",
                $blog_id
            ));

            $data = [
                'blog_id'                     => $blog_id,
                'global_htsoft_price_list_id' => $price_list_id,
                'user_id'                     => $user_id,
                'is_deleted'                  => 0,
                'deleted_at'                  => null,
                'updated_at'                  => $now,
            ];
            $formats = ['%d', '%d', '%d', '%d', '%s', '%s'];

            if ($existing_id) {
                $wpdb->update($blog_table, $data, ['global_htsoft_price_list_blog_id' => $existing_id], $formats, ['%d']);
            } else {
                $data['created_at'] = $now;
                $wpdb->insert($blog_table, $data, array_merge($formats, ['%s']));
            }
            $assigned++;
        }

        // Gỡ các website trước đây áp bảng giá này nhưng nay không còn được chọn
        $removed = 0;
        if (empty($blog_ids)) {
            $removed = (int) $wpdb->query($wpdb->prepare(
                "UPDATE {$blog_table} SET is_deleted = 1, deleted_at = %s, updated_at = %s
                 WHERE global_htsoft_price_list_id = %d AND (is_deleted = 0 OR is_deleted IS NULL)",
                $now,
                $now,
                $price_list_id
            ));
        } else {
            $in = implode(',', array_map('intval', $blog_ids));
            $removed = (int) $wpdb->query($wpdb->prepare(
                "UPDATE {$blog_table} SET is_deleted = 1, deleted_at = %s, updated_at = %s
                 WHERE global_htsoft_price_list_id = %d
                   AND blog_id NOT IN ({$in})
                   AND (is_deleted = 0 OR is_deleted IS NULL)",
                $now,
                $now,
                $price_list_id
            ));
        }

        self::success(
            ['assigned' => $assigned, 'removed' => max(0, $removed)],
            'Đã áp dụng cho ' . $assigned . ' website'
                . ($removed > 0 ? ', gỡ khỏi ' . $removed . ' website.' : '.')
        );
    }

    // #########################################################################
    // #  BASE — KHAI BÁO TỈ LỆ QUY ĐỔI  (wp_global_htsoft_unit_base)
    // #
    // #  Nguồn CẤU TRÚC duy nhất: mã hàng ↔ ĐVT ↔ tỉ lệ quy đổi ↔ ĐVT bán chính.
    // #  Mọi thay đổi ở đây được "propagate" (chiếu) xuống MỌI bảng giá trong
    // #  wp_global_htsoft_stock_convert — bảng giá chỉ khai thêm GIÁ.
    // #########################################################################

    /** Danh sách price_list_id đang hoạt động (không xóa mềm) */
    private static function active_price_list_ids()
    {
        global $wpdb;
        $t = self::table_price_list();
        return array_values(array_map('intval', (array) $wpdb->get_col(
            "SELECT global_htsoft_price_list_id FROM {$t}
             WHERE (is_deleted = 0 OR is_deleted IS NULL)"
        )));
    }

    /**
     * Chiếu cấu trúc Base → các bảng giá.
     *   - Chèn mọi dòng (SKU, ĐVT) của base còn thiếu.
     *   - Đồng bộ tỉ lệ / khối lượng cho dòng đã có.
     *   - Ghi chú & ĐVT bán chính: chỉ ghi đè khi bảng giá CHƯA override.
     *
     * @param string[]|null $skus         Lọc theo SKU (null = toàn bộ base)
     * @param int           $only_list_id Chỉ chiếu vào 1 bảng giá (0 = tất cả)
     * @param string        $price_mode   'none'      = KHÔNG đụng unit_price (mặc định),
     *                                     'fill'      = điền giá tham khảo cho ĐVT đang trống giá,
     *                                     'overwrite' = ghi đè unit_price bằng giá tham khảo
     * @return int Số dòng bị ảnh hưởng
     */
    public static function propagate_base_to_price_lists($skus = null, $only_list_id = 0, $price_mode = 'none')
    {
        global $wpdb;

        $base = self::table_unit_base();
        $conv = self::table_mapping();
        $now  = current_time('mysql');
        $uid  = (int) get_current_user_id();

        $list_ids = ($only_list_id > 0) ? [(int) $only_list_id] : self::active_price_list_ids();
        if (empty($list_ids)) {
            return 0;
        }

        $sku_where = '';
        $sku_args  = [];
        if (is_array($skus)) {
            $skus = array_values(array_unique(array_filter(array_map('strval', $skus), function ($s) {
                return $s !== '';
            })));
            if (empty($skus)) {
                return 0;
            }
            $ph        = implode(',', array_fill(0, count($skus), '%s'));
            $sku_where = " AND b.global_product_sku IN ({$ph}) ";
            $sku_args  = $skus;
        }

        // Cột unit_price trong SELECT + nhánh cập nhật theo $price_mode
        if ($price_mode === 'overwrite' || $price_mode === 'fill') {
            $price_select = 'b.unit_price';
            $price_update = ($price_mode === 'overwrite')
                ? "unit_price = VALUES(unit_price),"
                : "unit_price = IF({$conv}.unit_price IS NULL OR {$conv}.unit_price = 0,
                                   VALUES(unit_price), {$conv}.unit_price),";
        } else {
            $price_select = 'NULL';        // dòng mới: giá trống
            $price_update = '';            // dòng cũ: giữ nguyên unit_price
        }

        $touched = 0;
        foreach ($list_ids as $lid) {
            $sql = "INSERT INTO {$conv}
                        (price_list_id, global_product_sku, convert_unit, convert_from_tgs, convert_to_htsoft,
                         convert_note, unit_price, unit_weight_kg, is_default_unit,
                         note_overridden, default_unit_overridden,
                         user_id, is_deleted, created_at, updated_at)
                    SELECT %d, b.global_product_sku, b.convert_unit, b.convert_from_tgs, b.convert_to_htsoft,
                           b.convert_note, {$price_select}, b.unit_weight_kg, b.is_default_unit,
                           0, 0,
                           %d, 0, %s, %s
                    FROM {$base} b
                    WHERE (b.is_deleted = 0 OR b.is_deleted IS NULL) {$sku_where}
                    ON DUPLICATE KEY UPDATE
                        convert_from_tgs = VALUES(convert_from_tgs),
                        convert_to_htsoft = VALUES(convert_to_htsoft),
                        unit_weight_kg = VALUES(unit_weight_kg),
                        {$price_update}
                        convert_note = IF({$conv}.note_overridden = 1,
                                          {$conv}.convert_note, VALUES(convert_note)),
                        is_default_unit = IF({$conv}.default_unit_overridden = 1,
                                             {$conv}.is_default_unit, VALUES(is_default_unit)),
                        is_deleted = 0, deleted_at = NULL,
                        updated_at = VALUES(updated_at)";

            $args = array_merge([$lid, $uid, $now, $now], $sku_args);
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $touched += (int) $wpdb->query($wpdb->prepare($sql, ...$args));

            self::reassert_overridden_defaults($lid, is_array($skus) ? $skus : null);
        }

        return $touched;
    }

    /**
     * Bảng giá đang OVERRIDE ĐVT bán chính của 1 SKU mà lại có > 1 dòng
     * is_default_unit = 1 (do propagation vừa chèn thêm 1 ĐVT base mang cờ 1) →
     * giữ đúng 1 dòng: ưu tiên dòng override đang = 1, không có thì dòng id nhỏ
     * nhất; hạ cờ các dòng còn lại.
     */
    private static function reassert_overridden_defaults($list_id, $skus = null)
    {
        global $wpdb;

        $conv = self::table_mapping();
        $now  = current_time('mysql');

        $sku_where = '';
        $args      = [(int) $list_id];
        if (is_array($skus) && !empty($skus)) {
            $ph        = implode(',', array_fill(0, count($skus), '%s'));
            $sku_where = " AND g.global_product_sku IN ({$ph}) ";
            $args      = array_merge($args, $skus);
        }

        $sql = "UPDATE {$conv} c
                JOIN (
                    SELECT g.price_list_id, g.global_product_sku,
                           MIN(CASE WHEN g.default_unit_overridden = 1 AND g.is_default_unit = 1
                                    THEN g.global_htsoft_stock_convert_id END) AS ov_default_id,
                           MIN(CASE WHEN g.is_default_unit = 1
                                    THEN g.global_htsoft_stock_convert_id END) AS any_default_id
                    FROM {$conv} g
                    WHERE g.price_list_id = %d
                      AND (g.is_deleted = 0 OR g.is_deleted IS NULL)
                      {$sku_where}
                    GROUP BY g.price_list_id, g.global_product_sku
                    HAVING MAX(g.default_unit_overridden = 1) = 1
                       AND SUM(g.is_default_unit = 1) > 1
                ) k ON k.price_list_id = c.price_list_id
                   AND k.global_product_sku = c.global_product_sku
                SET c.is_default_unit = 0, c.updated_at = %s
                WHERE c.price_list_id = %d
                  AND c.is_default_unit = 1
                  AND c.global_htsoft_stock_convert_id <> COALESCE(k.ov_default_id, k.any_default_id)";

        $args[] = $now;
        $args[] = (int) $list_id;
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->query($wpdb->prepare($sql, ...$args));
    }

    /**
     * Base xóa 1 (SKU, ĐVT) → XÓA VĨNH VIỄN dòng tương ứng ở MỌI bảng giá
     * (giữ DB sạch, không để lại dòng xóa mềm). Bảng giá nào vừa mất ĐVT bán
     * chính của SKU → chọn lại.
     */
    public static function propagate_base_delete($sku, $unit)
    {
        global $wpdb;

        $conv = self::table_mapping();
        $now  = current_time('mysql');

        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$conv}
             WHERE BINARY global_product_sku = %s AND convert_unit = %s",
            (string) $sku,
            (string) $unit
        ));

        $lists = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT price_list_id FROM {$conv} WHERE BINARY global_product_sku = %s",
            (string) $sku
        ));

        foreach ((array) $lists as $lid) {
            $lid = (int) $lid;
            $has = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$conv}
                 WHERE price_list_id = %d AND BINARY global_product_sku = %s
                   AND is_default_unit = 1 AND (is_deleted = 0 OR is_deleted IS NULL)",
                $lid,
                (string) $sku
            ));
            if ($has === 0) {
                $winner = self::pick_default_config(self::fetch_active_configs((string) $sku, $lid));
                if ($winner) {
                    self::apply_default_for_sku((string) $sku, (int) $winner['global_htsoft_stock_convert_id'], $lid);
                }
            }
        }
    }

    /** Bảng giá mới: đổ toàn bộ cấu trúc từ Base (giá NULL) */
    public static function populate_price_list_from_base($new_list_id)
    {
        return self::propagate_base_to_price_lists(null, (int) $new_list_id);
    }

    /** Clone: chép GIÁ + ghi chú + ĐVT bán chính từ bảng giá nguồn đè lên đích */
    private static function overlay_price_list_values($from_id, $to_id)
    {
        global $wpdb;

        $conv = self::table_mapping();
        $now  = current_time('mysql');

        // global_product_sku là utf8mb4_bin ở cả 2 vế → '=' đã là so khớp nhị
        // phân + DÙNG ĐƯỢC index uk_list_sku_unit. Thêm BINARY chỉ làm mất index.
        $wpdb->query($wpdb->prepare(
            "UPDATE {$conv} d
             JOIN {$conv} s
               ON s.price_list_id = %d
              AND s.global_product_sku = d.global_product_sku
              AND s.convert_unit = d.convert_unit
             SET d.unit_price              = s.unit_price,
                 d.convert_note            = s.convert_note,
                 d.note_overridden         = s.note_overridden,
                 d.is_default_unit         = s.is_default_unit,
                 d.default_unit_overridden = s.default_unit_overridden,
                 d.updated_at              = %s
             WHERE d.price_list_id = %d
               AND (d.is_deleted = 0 OR d.is_deleted IS NULL)
               AND (s.is_deleted = 0 OR s.is_deleted IS NULL)",
            (int) $from_id,
            $now,
            (int) $to_id
        ));
    }

    // ── Base: helpers ──────────────────────────────────────────────────────

    /** Đếm số ĐVT khai trong BASE cho danh sách sản phẩm (giống attach_config_count) */
    private static function attach_base_config_count($rows)
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
            $base         = self::table_unit_base();
            $placeholders = implode(',', array_fill(0, count($skus), '%s'));
            $sql = "SELECT global_product_sku, COUNT(*) AS config_count
                    FROM {$base}
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

    /**
     * Toàn bộ dòng base đang hoạt động của 1 SKU.
     * Alias id sang global_htsoft_stock_convert_id để dùng chung pick_default_config().
     */
    private static function fetch_base_active_configs($sku)
    {
        global $wpdb;
        $base = self::table_unit_base();
        return $wpdb->get_results($wpdb->prepare(
            "SELECT global_htsoft_unit_base_id,
                    global_htsoft_unit_base_id AS global_htsoft_stock_convert_id,
                    convert_unit, convert_to_htsoft, unit_price, is_default_unit
             FROM {$base}
             WHERE BINARY global_product_sku = %s
               AND (is_deleted = 0 OR is_deleted IS NULL)
             ORDER BY global_htsoft_unit_base_id ASC",
            (string) $sku
        ), ARRAY_A) ?: [];
    }

    /** Đặt 1 dòng base làm ĐVT bán chính của SKU, các dòng khác về 0 */
    private static function apply_base_default_for_sku($sku, $winner_id)
    {
        global $wpdb;
        $base = self::table_unit_base();
        $now  = current_time('mysql');

        $wpdb->query($wpdb->prepare(
            "UPDATE {$base} SET is_default_unit = 0, updated_at = %s
             WHERE BINARY global_product_sku = %s AND is_default_unit <> 0
               AND global_htsoft_unit_base_id <> %d",
            $now,
            (string) $sku,
            (int) $winner_id
        ));
        if ($winner_id) {
            $wpdb->query($wpdb->prepare(
                "UPDATE {$base} SET is_default_unit = 1, updated_at = %s
                 WHERE global_htsoft_unit_base_id = %d AND is_default_unit <> 1",
                $now,
                (int) $winner_id
            ));
        }
    }

    // ── Base: AJAX ─────────────────────────────────────────────────────────

    public function ajax_base_list_configs_by_sku()
    {
        self::check_permission();
        self::check_nonce();

        global $wpdb;

        $sku = isset($_POST['global_product_sku'])
            ? sanitize_text_field(wp_unslash($_POST['global_product_sku'])) : '';
        if ($sku === '') {
            self::error('Thiếu SKU sản phẩm.');
            return;
        }

        $base = self::table_unit_base();
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT global_htsoft_unit_base_id,
                    global_htsoft_unit_base_id AS global_htsoft_stock_convert_id,
                    global_product_sku, convert_unit,
                    convert_from_tgs, convert_to_htsoft, unit_price, convert_note,
                    unit_weight_kg, is_default_unit, updated_at
             FROM {$base}
             WHERE BINARY global_product_sku = %s
               AND (is_deleted = 0 OR is_deleted IS NULL)
             ORDER BY convert_to_htsoft ASC, convert_unit ASC",
            $sku
        ), ARRAY_A) ?: [];

        self::success(['configs' => $rows]);
    }

    /**
     * Lưu / tạo 1 dòng khai báo trong BASE.
     * POST: id (0 = tạo mới), global_product_sku, convert_unit, convert_to_htsoft,
     *       convert_note, unit_weight_kg, is_default_unit
     */
    public function ajax_base_save_mapping()
    {
        self::check_permission();
        self::check_nonce();

        global $wpdb;

        $base = self::table_unit_base();

        $id        = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        $sku       = isset($_POST['global_product_sku'])
                        ? sanitize_text_field(wp_unslash($_POST['global_product_sku'])) : '';
        $unit      = isset($_POST['convert_unit'])
                        ? sanitize_text_field(wp_unslash($_POST['convert_unit'])) : '';
        $to_htsoft = self::parse_positive_decimal($_POST['convert_to_htsoft'] ?? 1, 1);
        $note      = isset($_POST['convert_note'])
                        ? sanitize_text_field(wp_unslash($_POST['convert_note'])) : '';
        $weight    = self::parse_optional_decimal($_POST['unit_weight_kg'] ?? '');
        $is_def    = !empty($_POST['is_default_unit']) ? 1 : 0;

        // Giá tham khảo (tuỳ chọn, NULL nếu để trống)
        $raw_price  = isset($_POST['unit_price']) ? trim(wp_unslash($_POST['unit_price'])) : '';
        $unit_price = null;
        if ($raw_price !== '') {
            $cleaned = (float) str_replace(',', '.', $raw_price);
            if ($cleaned >= 0) {
                $unit_price = $cleaned;
            }
        }

        if ($sku === '') {
            self::error('Thiếu SKU sản phẩm.');
            return;
        }
        if ($unit === '' && $id === 0) {
            self::error('Thiếu tên Đơn Vị Tính (ĐVT).');
            return;
        }
        if ($note === '') {
            $note = self::build_default_note($unit, $to_htsoft);
        }

        $now  = current_time('mysql');
        $uid  = get_current_user_id();

        $data = [
            'global_product_sku' => $sku,
            'convert_unit'       => $unit,
            'convert_from_tgs'   => 1,
            'convert_to_htsoft'  => $to_htsoft,
            'unit_price'         => $unit_price,
            'convert_note'       => $note,
            'unit_weight_kg'     => $weight,
            'is_default_unit'    => $is_def,
            'user_id'            => $uid,
            'is_deleted'         => 0,
            'deleted_at'         => null,
            'updated_at'         => $now,
        ];
        $formats = ['%s', '%s', '%f', '%f', $unit_price !== null ? '%f' : '%s', '%s',
                    $weight !== null ? '%f' : '%s', '%d', '%d', '%d', '%s', '%s'];

        if ($id > 0) {
            $conflict = $wpdb->get_var($wpdb->prepare(
                "SELECT global_htsoft_unit_base_id FROM {$base}
                 WHERE BINARY global_product_sku = %s AND convert_unit = %s
                   AND global_htsoft_unit_base_id <> %d
                   AND (is_deleted = 0 OR is_deleted IS NULL) LIMIT 1",
                $sku,
                $unit,
                $id
            ));
            if ($conflict) {
                self::error('ĐVT "' . $unit . '" đã có trong Bảng gốc cho SKU này.');
                return;
            }
            $wpdb->update($base, $data, ['global_htsoft_unit_base_id' => $id], $formats, ['%d']);
            $row_id = $id;
        } else {
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT global_htsoft_unit_base_id FROM {$base}
                 WHERE BINARY global_product_sku = %s AND convert_unit = %s LIMIT 1",
                $sku,
                $unit
            ));
            if ($existing) {
                $wpdb->update($base, $data, ['global_htsoft_unit_base_id' => (int) $existing], $formats, ['%d']);
                $row_id = (int) $existing;
            } else {
                $data['created_at'] = $now;
                $wpdb->insert($base, $data, array_merge($formats, ['%s']));
                $row_id = (int) $wpdb->insert_id;
            }
        }

        if ($is_def === 1 && $row_id) {
            self::apply_base_default_for_sku($sku, $row_id);
        }

        // Chiếu xuống mọi bảng giá
        self::propagate_base_to_price_lists([$sku]);

        self::success(['id' => $row_id], 'Đã lưu khai báo ĐVT "' . $unit . '" ở Bảng gốc.');
    }

    public function ajax_base_delete_mapping()
    {
        self::check_permission();
        self::check_nonce();

        global $wpdb;

        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        if ($id <= 0) {
            self::error('ID không hợp lệ.');
            return;
        }

        $base = self::table_unit_base();
        $row  = $wpdb->get_row($wpdb->prepare(
            "SELECT global_product_sku, convert_unit, is_default_unit
             FROM {$base} WHERE global_htsoft_unit_base_id = %d",
            $id
        ), ARRAY_A);
        if (!$row) {
            self::error('Không tìm thấy khai báo.');
            return;
        }

        $sku  = (string) $row['global_product_sku'];
        $unit = (string) $row['convert_unit'];

        $wpdb->delete($base, ['global_htsoft_unit_base_id' => $id], ['%d']);

        // Chọn lại ĐVT bán chính ở base nếu vừa xóa đúng dòng đó
        if ((int) $row['is_default_unit'] === 1) {
            $winner = self::pick_default_config(self::fetch_base_active_configs($sku));
            if ($winner) {
                self::apply_base_default_for_sku($sku, (int) $winner['global_htsoft_unit_base_id']);
            }
        }

        // Xóa mềm ở mọi bảng giá + chọn lại ĐVT chính chỗ nào mất
        self::propagate_base_delete($sku, $unit);
        // Đồng bộ lại phần còn lại (tỉ lệ / ĐVT chính mới)
        self::propagate_base_to_price_lists([$sku]);

        self::success(['id' => $id], 'Đã xóa ĐVT "' . $unit . '" ở Bảng gốc. Mọi bảng giá đã cập nhật theo.');
    }

    public function ajax_base_set_default_unit()
    {
        self::check_permission();
        self::check_nonce();

        global $wpdb;

        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        if ($id <= 0) {
            self::error('ID không hợp lệ.');
            return;
        }

        $base = self::table_unit_base();
        $row  = $wpdb->get_row($wpdb->prepare(
            "SELECT global_product_sku, convert_unit FROM {$base}
             WHERE global_htsoft_unit_base_id = %d
               AND (is_deleted = 0 OR is_deleted IS NULL)",
            $id
        ), ARRAY_A);
        if (!$row) {
            self::error('Không tìm thấy khai báo.');
            return;
        }

        $sku = (string) $row['global_product_sku'];
        self::apply_base_default_for_sku($sku, $id);
        self::propagate_base_to_price_lists([$sku]);

        self::success(
            ['id' => $id, 'global_product_sku' => $sku],
            'Đã đặt "' . $row['convert_unit'] . '" làm ĐVT bán chính (Bảng gốc). '
                . 'Bảng giá nào chưa tự chọn riêng sẽ cập nhật theo.'
        );
    }

    public function ajax_base_get_mapping()
    {
        self::check_permission();
        self::check_nonce();

        global $wpdb;

        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        if ($id <= 0) {
            self::error('ID không hợp lệ.');
            return;
        }

        $base = self::table_unit_base();
        $row  = $wpdb->get_row($wpdb->prepare(
            "SELECT global_htsoft_unit_base_id,
                    global_htsoft_unit_base_id AS global_htsoft_stock_convert_id,
                    global_product_sku, convert_unit,
                    convert_from_tgs, convert_to_htsoft, unit_price, convert_note,
                    unit_weight_kg, is_default_unit
             FROM {$base} WHERE global_htsoft_unit_base_id = %d
               AND (is_deleted = 0 OR is_deleted IS NULL) LIMIT 1",
            $id
        ), ARRAY_A);
        if (!$row) {
            self::error('Không tìm thấy khai báo.');
            return;
        }

        $hydrated = self::attach_product_info([$row]);
        self::success(['mapping' => $hydrated[0]]);
    }

    /**
     * Bảng tổng BASE — phân trang phía server (không có cột giá).
     * POST: keyword, page, per_page
     */
    public function ajax_base_list_mappings()
    {
        self::check_permission();
        self::check_nonce();

        global $wpdb;

        $base    = self::table_unit_base();
        $keyword = isset($_POST['keyword']) ? sanitize_text_field(wp_unslash($_POST['keyword'])) : '';

        $page = isset($_POST['page']) ? max(1, (int) $_POST['page']) : 1;
        $per_page = isset($_POST['per_page']) ? (int) $_POST['per_page'] : self::LIST_PER_PAGE_DEFAULT;
        $per_page = max(10, min(self::LIST_PER_PAGE_MAX, $per_page));

        $where  = '(b.is_deleted = 0 OR b.is_deleted IS NULL)';
        $params = [];

        if ($keyword !== '') {
            $like         = '%' . $wpdb->esc_like($keyword) . '%';
            $matched_skus = self::find_skus_by_product_keyword($like);

            $parts    = ['b.global_product_sku LIKE %s', 'b.convert_unit LIKE %s'];
            $params[] = $like;
            $params[] = $like;
            if (!empty($matched_skus)) {
                $ph       = implode(',', array_fill(0, count($matched_skus), '%s'));
                $parts[]  = "b.global_product_sku IN ({$ph})";
                $params   = array_merge($params, $matched_skus);
            }
            $where .= ' AND (' . implode(' OR ', $parts) . ')';
        }

        $count_sql = "SELECT COUNT(*) FROM {$base} b WHERE {$where}";
        $total = (int) (empty($params)
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            ? $wpdb->get_var($count_sql)
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            : $wpdb->get_var($wpdb->prepare($count_sql, ...$params)));

        $total_pages = max(1, (int) ceil($total / $per_page));
        if ($page > $total_pages) {
            $page = $total_pages;
        }
        $offset = ($page - 1) * $per_page;

        $rows_sql = "SELECT b.global_htsoft_unit_base_id,
                            b.global_htsoft_unit_base_id AS global_htsoft_stock_convert_id,
                            b.global_product_sku, b.convert_unit,
                            b.convert_from_tgs, b.convert_to_htsoft, b.unit_price, b.convert_note,
                            b.unit_weight_kg, b.is_default_unit, b.updated_at
                     FROM {$base} b
                     WHERE {$where}
                     ORDER BY b.global_product_sku ASC, b.convert_to_htsoft ASC
                     LIMIT %d OFFSET %d";
        $row_params = array_merge($params, [$per_page, $offset]);
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results($wpdb->prepare($rows_sql, ...$row_params), ARRAY_A) ?: [];
        $rows = self::attach_product_info($rows);

        self::success([
            'mappings'    => $rows,
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $per_page,
            'total_pages' => $total_pages,
        ]);
    }

    public function ajax_base_export_excel_rows()
    {
        self::check_permission();
        self::check_nonce();

        global $wpdb;

        $base = self::table_unit_base();
        $rows = $wpdb->get_results(
            "SELECT global_product_sku, convert_unit, convert_to_htsoft, unit_price, convert_note,
                    unit_weight_kg, is_default_unit
             FROM {$base}
             WHERE (is_deleted = 0 OR is_deleted IS NULL)
             ORDER BY global_product_sku ASC, convert_to_htsoft ASC",
            ARRAY_A
        ) ?: [];

        $hydrated = [];
        foreach (array_chunk($rows, 1000) as $chunk) {
            foreach (self::attach_product_info($chunk) as $r) {
                $hydrated[] = $r;
            }
        }

        $export = [];
        foreach ($hydrated as $row) {
            $export[] = [
                'global_product_sku' => $row['global_product_sku'],
                'local_product_name' => (string) ($row['local_product_name'] ?? ''),
                'convert_unit'       => (string) ($row['convert_unit'] ?? ''),
                'convert_to_htsoft'  => (float) self::parse_positive_decimal($row['convert_to_htsoft'], 1),
                'unit_price'         => ($row['unit_price'] !== null && $row['unit_price'] !== '')
                                            ? (float) $row['unit_price'] : null,
                'unit_weight_kg'     => ($row['unit_weight_kg'] !== null && $row['unit_weight_kg'] !== '')
                                            ? (float) $row['unit_weight_kg'] : null,
                'convert_note'       => (string) ($row['convert_note'] ?? ''),
                'is_default_unit'    => ((int) ($row['is_default_unit'] ?? 0) === 1) ? 1 : 0,
            ];
        }

        self::success(['exported_at' => current_time('mysql'), 'count' => count($export), 'rows' => $export]);
    }

    /**
     * Import cấu trúc vào BASE (batch). Cột: A Mã · B Tên · C ĐVT · D Tỉ lệ ·
     * E Giá tham khảo · F Khối lượng · G Ghi chú.
     * Mỗi lô xong → propagate các SKU trong lô.
     */
    public function ajax_base_import_excel_rows()
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
        $base      = self::table_unit_base();
        $now       = current_time('mysql');
        $uid       = get_current_user_id();
        $created   = 0;
        $updated   = 0;
        $unchanged = 0;   // (SKU, ĐVT, tỉ lệ, giá, KL, ghi chú) trùng khít → bỏ qua
        $skipped   = 0;
        $errors    = [];
        $skus      = [];

        foreach ($items as $idx => $item) {
            if (!is_array($item)) { $skipped++; continue; }

            $sku  = isset($item['global_product_sku']) ? sanitize_text_field((string) $item['global_product_sku']) : '';
            $unit = isset($item['convert_unit']) ? sanitize_text_field((string) $item['convert_unit']) : '';
            if ($sku === '') { $skipped++; continue; }

            $to_htsoft  = self::parse_positive_decimal($item['convert_to_htsoft'] ?? 1, 1);
            $note       = isset($item['convert_note']) ? sanitize_text_field((string) $item['convert_note']) : '';
            $weight     = self::parse_optional_decimal($item['unit_weight_kg'] ?? '');
            $unit_price = self::parse_optional_decimal($item['unit_price'] ?? '');
            if ($note === '') {
                $note = self::build_default_note($unit, $to_htsoft);
            }

            $data = [
                'global_product_sku' => $sku,
                'convert_unit'       => $unit,
                'convert_from_tgs'   => 1,
                'convert_to_htsoft'  => $to_htsoft,
                'unit_price'         => $unit_price,
                'convert_note'       => $note,
                'unit_weight_kg'     => $weight,
                'user_id'            => $uid,
                'is_deleted'         => 0,
                'deleted_at'         => null,
                'updated_at'         => $now,
            ];
            $formats = ['%s', '%s', '%f', '%f', $unit_price !== null ? '%f' : '%s', '%s',
                        $weight !== null ? '%f' : '%s', '%d', '%d', '%s', '%s'];

            $cur = $wpdb->get_row($wpdb->prepare(
                "SELECT global_htsoft_unit_base_id, convert_to_htsoft, unit_price, unit_weight_kg, convert_note
                 FROM {$base}
                 WHERE BINARY global_product_sku = %s AND convert_unit = %s LIMIT 1",
                $sku,
                $unit
            ), ARRAY_A);

            if ($cur) {
                // Giống khít mọi giá trị → BỎ QUA (giữ DB sạch, không đụng updated_at)
                $same =
                    abs((float) $cur['convert_to_htsoft'] - (float) $to_htsoft) < 0.0005
                    && self::decimals_equal($cur['unit_price'], $unit_price)
                    && self::decimals_equal($cur['unit_weight_kg'], $weight)
                    && (string) ($cur['convert_note'] ?? '') === (string) $note;

                if ($same) {
                    $unchanged++;
                    $skus[$sku] = true;
                    continue;
                }

                $ok = $wpdb->update($base, $data, ['global_htsoft_unit_base_id' => (int) $cur['global_htsoft_unit_base_id']], $formats, ['%d']);
                if ($ok !== false) { $updated++; } else { $skipped++; $errors[] = 'Dòng ' . ($idx + 1) . ": lỗi cập nhật ({$sku}/{$unit})."; }
            } else {
                $data['created_at'] = $now;
                $ok = $wpdb->insert($base, $data, array_merge($formats, ['%s']));
                if ($ok) { $created++; } else { $skipped++; $errors[] = 'Dòng ' . ($idx + 1) . ": lỗi tạo mới ({$sku}/{$unit})."; }
            }

            $skus[$sku] = true;
        }

        // Chỉ propagate khi thực sự có thêm/sửa (bỏ qua thì không cần)
        if (($created + $updated) > 0 && !empty($skus)) {
            self::propagate_base_to_price_lists(array_keys($skus));
        }

        self::success([
            'created'   => $created,
            'updated'   => $updated,
            'unchanged' => $unchanged,
            'skipped'   => $skipped,
            'errors'    => $errors,
        ], "Import Bảng gốc xong. Tạo mới: {$created}, cập nhật: {$updated}, "
            . "trùng bỏ qua: {$unchanged}, lỗi: {$skipped}.");
    }

    public function ajax_base_default_scan_prepare()
    {
        self::check_permission();
        self::check_nonce();

        global $wpdb;
        $base = self::table_unit_base();
        $total = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT global_product_sku) FROM {$base}
             WHERE (is_deleted = 0 OR is_deleted IS NULL)"
        );
        self::success(['total_skus' => $total, 'batch_size' => self::DEFAULT_SCAN_BATCH_SIZE]);
    }

    public function ajax_base_default_scan_batch()
    {
        self::check_permission();
        self::check_nonce();

        global $wpdb;

        $base   = self::table_unit_base();
        $offset = isset($_POST['offset']) ? max(0, (int) $_POST['offset']) : 0;
        $limit  = isset($_POST['batch_size']) ? (int) $_POST['batch_size'] : self::DEFAULT_SCAN_BATCH_SIZE;
        if ($limit < 10) { $limit = self::DEFAULT_SCAN_BATCH_SIZE; }
        if ($limit > self::DEFAULT_SCAN_BATCH_MAX) { $limit = self::DEFAULT_SCAN_BATCH_MAX; }
        $only_missing = !empty($_POST['only_missing']);

        $skus = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT global_product_sku FROM {$base}
             WHERE (is_deleted = 0 OR is_deleted IS NULL)
             ORDER BY global_product_sku ASC
             LIMIT %d OFFSET %d",
            $limit,
            $offset
        ));

        if (empty($skus)) {
            self::success([
                'processed' => 0, 'assigned' => 0, 'unchanged' => 0, 'no_candidate' => 0,
                'next_offset' => $offset, 'done' => true, 'samples' => [],
            ]);
            return;
        }

        $ph   = implode(',', array_fill(0, count($skus), '%s'));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT global_htsoft_unit_base_id,
                    global_htsoft_unit_base_id AS global_htsoft_stock_convert_id,
                    global_product_sku, convert_unit,
                    convert_to_htsoft, unit_price, is_default_unit
             FROM {$base}
             WHERE global_product_sku IN ({$ph})
               AND (is_deleted = 0 OR is_deleted IS NULL)
             ORDER BY global_htsoft_unit_base_id ASC",
            ...$skus
        ), ARRAY_A) ?: [];

        $groups = [];
        foreach ($rows as $r) {
            $groups[(string) $r['global_product_sku']][] = $r;
        }

        $assigned = 0; $unchanged = 0; $no_candidate = 0;
        $set_one = []; $set_zero = []; $samples = [];

        foreach ($skus as $sku) {
            $sku  = (string) $sku;
            $grp  = $groups[$sku] ?? [];
            $cur  = [];
            foreach ($grp as $r) {
                if ((int) $r['is_default_unit'] === 1) {
                    $cur[] = (int) $r['global_htsoft_unit_base_id'];
                }
            }
            if ($only_missing && count($cur) === 1) { $unchanged++; continue; }

            $winner    = self::pick_default_config($grp); // công thức gốc: tỷ lệ > 1 gần nhất VÀ có giá…
            $winner_id = $winner ? (int) $winner['global_htsoft_unit_base_id'] : 0;
            if (!$winner_id) { $no_candidate++; }

            foreach ($cur as $cid) {
                if ($cid !== $winner_id) { $set_zero[] = $cid; }
            }
            if ($winner_id) {
                if (count($cur) === 1 && $cur[0] === $winner_id) {
                    $unchanged++;
                } else {
                    $set_one[] = $winner_id;
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

        $now = current_time('mysql');
        if (!empty($set_zero)) {
            $in = implode(',', array_map('intval', $set_zero));
            $wpdb->query($wpdb->prepare(
                "UPDATE {$base} SET is_default_unit = 0, updated_at = %s
                 WHERE global_htsoft_unit_base_id IN ({$in})",
                $now
            ));
        }
        if (!empty($set_one)) {
            $in = implode(',', array_map('intval', $set_one));
            $wpdb->query($wpdb->prepare(
                "UPDATE {$base} SET is_default_unit = 1, updated_at = %s
                 WHERE global_htsoft_unit_base_id IN ({$in})",
                $now
            ));
        }

        // Chiếu ĐVT bán chính mới xuống bảng giá (chỗ nào chưa override)
        self::propagate_base_to_price_lists(array_map('strval', $skus));

        $processed = count($skus);
        self::success([
            'processed'    => $processed,
            'assigned'     => $assigned,
            'unchanged'    => $unchanged,
            'no_candidate' => $no_candidate,
            'next_offset'  => $offset + $processed,
            'done'         => ($processed < $limit),
            'samples'      => $samples,
        ]);
    }

    /** Đồng bộ Base → tất cả bảng giá (nút bảo trì). Chạy theo lô SKU. */
    public function ajax_base_sync_prepare()
    {
        self::check_permission();
        self::check_nonce();

        global $wpdb;
        $base = self::table_unit_base();
        $total = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT global_product_sku) FROM {$base}
             WHERE (is_deleted = 0 OR is_deleted IS NULL)"
        );
        self::success(['total_skus' => $total, 'batch_size' => self::DEFAULT_SCAN_BATCH_SIZE]);
    }

    public function ajax_base_sync_batch()
    {
        self::check_permission();
        self::check_nonce();

        global $wpdb;

        $base   = self::table_unit_base();
        $offset = isset($_POST['offset']) ? max(0, (int) $_POST['offset']) : 0;
        $limit  = isset($_POST['batch_size']) ? (int) $_POST['batch_size'] : self::DEFAULT_SCAN_BATCH_SIZE;
        $limit  = max(10, min(self::DEFAULT_SCAN_BATCH_MAX, $limit));

        // 'none' (mặc định) | 'fill' (chỉ điền ĐVT trống giá) | 'overwrite' (ghi đè)
        $price_mode = isset($_POST['sync_prices']) ? (string) $_POST['sync_prices'] : 'none';
        if (!in_array($price_mode, ['none', 'fill', 'overwrite'], true)) {
            $price_mode = 'none';
        }

        $skus = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT global_product_sku FROM {$base}
             WHERE (is_deleted = 0 OR is_deleted IS NULL)
             ORDER BY global_product_sku ASC
             LIMIT %d OFFSET %d",
            $limit,
            $offset
        ));

        if (empty($skus)) {
            self::success(['processed' => 0, 'next_offset' => $offset, 'done' => true]);
            return;
        }

        $affected  = self::propagate_base_to_price_lists(array_map('strval', $skus), 0, $price_mode);
        $processed = count($skus);

        self::success([
            'processed'   => $processed,
            'affected'    => $affected,
            'next_offset' => $offset + $processed,
            'done'        => ($processed < $limit),
        ]);
    }

    // ── Bảng giá: bổ trợ ──────────────────────────────────────────────────

    /**
     * Điền giá cho các ĐVT cùng SKU đang TRỐNG giá trong bảng giá hiện tại,
     * suy theo tỉ lệ từ 1 giá mốc. Không đụng ĐVT đã có giá.
     * POST: global_product_sku, from_unit_price, from_ratio (tuỳ chọn)
     */
    public function ajax_fill_missing_prices()
    {
        self::check_permission();
        self::check_nonce();

        global $wpdb;

        $sku = isset($_POST['global_product_sku'])
            ? sanitize_text_field(wp_unslash($_POST['global_product_sku'])) : '';
        if ($sku === '') {
            self::error('Thiếu SKU sản phẩm.');
            return;
        }

        $price_list_id = self::posted_price_list_id();
        if (!$price_list_id) {
            self::error('Chưa chọn bảng giá.');
            return;
        }

        $conv = self::table_mapping();

        // Giá 1 ĐVT NHỎ NHẤT làm mốc
        $per_smallest = 0.0;
        $from_price   = isset($_POST['from_unit_price']) ? (float) str_replace(',', '.', (string) $_POST['from_unit_price']) : 0.0;
        $from_ratio   = isset($_POST['from_ratio']) ? (float) $_POST['from_ratio'] : 0.0;
        if ($from_price > 0 && $from_ratio > 0) {
            $per_smallest = $from_price / $from_ratio;
        } else {
            $priced = $wpdb->get_results($wpdb->prepare(
                "SELECT convert_to_htsoft, unit_price FROM {$conv}
                 WHERE price_list_id = %d AND BINARY global_product_sku = %s
                   AND (is_deleted = 0 OR is_deleted IS NULL)
                   AND unit_price IS NOT NULL AND unit_price > 0
                 ORDER BY convert_to_htsoft ASC",
                $price_list_id,
                $sku
            ), ARRAY_A) ?: [];
            foreach ($priced as $p) {
                $r = (float) $p['convert_to_htsoft'];
                if ($r > 0) { $per_smallest = (float) $p['unit_price'] / $r; break; }
            }
        }

        if ($per_smallest <= 0) {
            self::error('Chưa có giá mốc nào để suy. Hãy nhập giá cho ít nhất 1 ĐVT.');
            return;
        }

        $now     = current_time('mysql');
        $updated = (int) $wpdb->query($wpdb->prepare(
            "UPDATE {$conv}
             SET unit_price = ROUND(%f * convert_to_htsoft, 2), updated_at = %s
             WHERE price_list_id = %d AND BINARY global_product_sku = %s
               AND (is_deleted = 0 OR is_deleted IS NULL)
               AND (unit_price IS NULL OR unit_price = '' OR unit_price = 0)",
            $per_smallest,
            $now,
            $price_list_id,
            $sku
        ));

        self::success(['updated' => $updated], 'Đã điền giá theo tỉ lệ cho ' . $updated . ' ĐVT còn trống.');
    }

    /**
     * Bỏ override ĐVT bán chính của bảng giá → quay về theo Base.
     * POST: global_product_sku (rỗng = toàn bộ bảng giá)
     */
    public function ajax_reset_default_to_base()
    {
        self::check_permission();
        self::check_nonce();

        global $wpdb;

        $price_list_id = self::posted_price_list_id();
        if (!$price_list_id) {
            self::error('Chưa chọn bảng giá.');
            return;
        }

        $sku  = isset($_POST['global_product_sku'])
            ? sanitize_text_field(wp_unslash($_POST['global_product_sku'])) : '';
        $conv = self::table_mapping();
        $now  = current_time('mysql');

        if ($sku !== '') {
            $wpdb->query($wpdb->prepare(
                "UPDATE {$conv} SET default_unit_overridden = 0, updated_at = %s
                 WHERE price_list_id = %d AND BINARY global_product_sku = %s",
                $now,
                $price_list_id,
                $sku
            ));
            self::propagate_base_to_price_lists([$sku], $price_list_id);
        } else {
            $wpdb->query($wpdb->prepare(
                "UPDATE {$conv} SET default_unit_overridden = 0, updated_at = %s
                 WHERE price_list_id = %d",
                $now,
                $price_list_id
            ));
            self::propagate_base_to_price_lists(null, $price_list_id);
        }

        self::success([], 'Đã đưa ĐVT bán chính về theo Bảng gốc.');
    }

    // ── Lưới "tải hết" ────────────────────────────────────────────────────
    //
    // Trả TOÀN BỘ dòng trong 1 request (không phân trang). JS giữ mảng trong RAM
    // rồi vẽ theo khung nhìn (TGSDesignSystem.virtualBody) — 20k+ dòng vẫn mượt.
    // JOIN thẳng wp_global_product_name (cùng collation utf8mb4_bin) → 1 query.

    /** Toàn bộ dòng cấu hình của BẢNG GIÁ đang mở, xếp cạnh nhau theo mã hàng */
    public function ajax_converter_list_all()
    {
        self::check_permission();
        self::check_nonce();

        global $wpdb;

        $price_list_id = self::posted_price_list_id();
        if (!$price_list_id) {
            self::error('Chưa chọn bảng giá.');
            return;
        }

        $m = self::table_mapping();
        $p = self::table_product();

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT m.global_htsoft_stock_convert_id, m.global_product_sku,
                    p.global_product_name AS local_product_name,
                    m.convert_unit, m.convert_to_htsoft, m.unit_price, m.unit_weight_kg,
                    m.is_default_unit, m.convert_note,
                    m.note_overridden, m.default_unit_overridden
             FROM {$m} m
             LEFT JOIN {$p} p ON p.global_product_sku = m.global_product_sku
                             AND (p.is_deleted = 0 OR p.is_deleted IS NULL)
             WHERE m.price_list_id = %d
               AND (m.is_deleted = 0 OR m.is_deleted IS NULL)
             ORDER BY m.global_product_sku ASC, m.convert_to_htsoft ASC, m.convert_unit ASC",
            $price_list_id
        ), ARRAY_A) ?: [];

        self::success(['rows' => $rows, 'count' => count($rows), 'price_list_id' => $price_list_id]);
    }

    /** Toàn bộ dòng khai báo trong BẢNG GỐC */
    public function ajax_base_list_all()
    {
        self::check_permission();
        self::check_nonce();

        global $wpdb;

        $b = self::table_unit_base();
        $p = self::table_product();

        $rows = $wpdb->get_results(
            "SELECT b.global_htsoft_unit_base_id,
                    b.global_htsoft_unit_base_id AS global_htsoft_stock_convert_id,
                    b.global_product_sku,
                    p.global_product_name AS local_product_name,
                    b.convert_unit, b.convert_to_htsoft, b.unit_price, b.unit_weight_kg,
                    b.is_default_unit, b.convert_note
             FROM {$b} b
             LEFT JOIN {$p} p ON p.global_product_sku = b.global_product_sku
                             AND (p.is_deleted = 0 OR p.is_deleted IS NULL)
             WHERE (b.is_deleted = 0 OR b.is_deleted IS NULL)
             ORDER BY b.global_product_sku ASC, b.convert_to_htsoft ASC, b.convert_unit ASC",
            ARRAY_A
        ) ?: [];

        self::success(['rows' => $rows, 'count' => count($rows)]);
    }
}
