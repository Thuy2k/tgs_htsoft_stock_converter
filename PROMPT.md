# tgs_htsoft_stock_converter — Plugin Specification (v2)

## Purpose

Quản lý cấu hình quy đổi tồn kho giữa hệ thống TGS (WooCommerce multisite, 650 cửa hàng) và phần mềm kế toán HTSoft.

Mỗi SKU có thể có **nhiều Đơn Vị Tính (DVT)** — ví dụ SKU "ABC" có:
- "Chai" → tỷ lệ 1 (đơn vị nhỏ nhất)
- "Lốc_4" → tỷ lệ 4 (1 Lốc_4 = 4 đơn vị nhỏ nhất)
- "Thùng_24" → tỷ lệ 24

Hệ thống TGS hiện lưu tồn theo đơn vị nhỏ nhất (bán 1 = trừ 1). Plugin này cung cấp bảng tra cứu để POS tính ngược tồn HTSoft.

---

## DB Schema

**Table:** `wp_global_htsoft_stock_convert`  
(Dùng `$wpdb->base_prefix` — global cho toàn bộ multisite)

| Column | Type | Note |
|--------|------|------|
| `global_htsoft_stock_convert_id` | INT PK AUTO_INCREMENT | |
| `global_product_sku` | VARCHAR(255) COLLATE utf8mb4_bin | Case-sensitive SKU |
| `convert_unit` | VARCHAR(100) COLLATE utf8mb4_unicode_ci | Tên DVT ('' = mặc định) |
| `convert_from_tgs` | DECIMAL(15,4) | Luôn = 1 |
| `convert_to_htsoft` | DECIMAL(15,4) | Số đơn vị nhỏ nhất tương đương |
| `convert_note` | TEXT | Ghi chú, mặc định "1 {unit} = {ratio} đơn vị nhỏ nhất" |
| `user_id` | INT | User tạo/sửa |
| `is_deleted` | TINYINT(1) | Soft-delete flag (0=active) |
| `deleted_at` | DATETIME NULL | |
| `created_at` | DATETIME | |
| `updated_at` | DATETIME | |

**Unique Key:** `uk_sku_unit (global_product_sku, convert_unit)`  
→ MySQL dùng collation `utf8mb4_unicode_ci` cho `convert_unit`: tự động kiểm tra case-insensitive.

---

## Migration

File: `database/migrations/2026_05_25_000100_add_unit_to_htsoft_stock_convert.php`

- `up()`: Thêm cột `convert_unit`, đổi unique key từ `(global_product_sku)` → `(global_product_sku, convert_unit)`
- `down()`: Xóa cột `convert_unit`, khôi phục unique key cũ

Đăng ký trong `class-tgs-database.php` trong array migrations.

---

## AJAX Endpoints

Tất cả đều yêu cầu:
- `nonce` = `wp_create_nonce('tgs_htsoft_converter_nonce')`
- Quyền: `manage_options` hoặc `manage_woocommerce` hoặc `edit_posts`

### Tab 1 — Cấu hình DVT

| Action | POST params | Returns |
|--------|-------------|---------|
| `tgs_htsoft_converter_search_products` | `keyword` | `{ products: [{ local_product_sku, local_product_name, local_product_unit, config_count }] }` |
| `tgs_htsoft_converter_list_configs_by_sku` | `global_product_sku` | `{ configs: [...] }` |
| `tgs_htsoft_converter_save_mapping` | `id, global_product_sku, convert_unit, convert_to_htsoft, convert_note` | `{ id, message }` |
| `tgs_htsoft_converter_delete_mapping` | `id` | `{ id, message }` — **Hard delete** |
| `tgs_htsoft_converter_get_mapping` | `id` | `{ mapping: { ...row, local_product_name } }` |
| `tgs_htsoft_converter_get_mapping_by_sku` | `global_product_sku` | `{ mapping }` — Backward compat, trả về config đầu tiên |

### Bảng tổng

| Action | POST params | Returns |
|--------|-------------|---------|
| `tgs_htsoft_converter_list_mappings` | `keyword, limit` | `{ mappings: [...], total }` |

### POS Lookup

| Action | POST params | Returns |
|--------|-------------|---------|
| `tgs_htsoft_converter_get_mappings_by_skus` | `skus` (JSON array) | `{ mappings: { "SKU": { "unit": { convert_to_htsoft, convert_note } } } }` |

### Excel Import/Export

| Action | POST params | Returns |
|--------|-------------|---------|
| `tgs_htsoft_converter_export_excel_rows` | — | `{ rows: [{ global_product_sku, local_product_name, convert_unit, convert_to_htsoft, convert_note }] }` |
| `tgs_htsoft_converter_import_excel_rows` | `rows_json` | `{ created, updated, skipped, message }` |

### JSON Import/Export (backward compat)

| Action | POST params |
|--------|-------------|
| `tgs_htsoft_converter_export_mappings_json` | — |
| `tgs_htsoft_converter_import_mappings_json` | `mappings_json` |

---

## Excel Format

Columns (A–E):

| A | B | C | D | E |
|---|---|---|---|---|
| Mã hàng | Tên hàng | Đơn vị tính | Tỷ lệ quy đổi | Ghi chú |
| SKU | (ref only) | DVT name | ratio number | note |

- Row 1 = header (bỏ qua khi import)
- Import match by `(global_product_sku, convert_unit)` — update nếu tồn tại, create nếu không
- Cột B (Tên hàng) bị bỏ qua khi import

---

## POS Integration

Khi POS cần tra cứu tỷ lệ cho nhiều SKU:

```js
// POST tgs_htsoft_converter_get_mappings_by_skus
// Body: skus = JSON.stringify(["SKU1", "SKU2"])
// Response:
{
  "SKU1": {
    "Lốc_4":   { "convert_to_htsoft": 4,  "convert_note": "1 Lốc_4 = 4 đơn vị nhỏ nhất" },
    "Thùng_24": { "convert_to_htsoft": 24, "convert_note": "..." },
    "":         { "convert_to_htsoft": 1,  "convert_note": "..." }
  }
}
```

Khoá `""` (chuỗi rỗng) = DVT mặc định (dữ liệu cũ, backward compat).

---

## Design Decisions

1. **Multi-DVT per SKU**: Unique key `(sku, unit)` thay vì chỉ `(sku)`. Mỗi đơn vị bán có tỷ lệ riêng.
2. **`convert_unit = ''`**: Giữ backward compat với dữ liệu cũ. UI hiển thị là "mặc định".
3. **Hard delete**: `ajax_delete_mapping` dùng `$wpdb->delete()` — không soft-delete theo yêu cầu "xóa vĩnh viễn".
4. **Case-sensitive SKU**: Query dùng `BINARY global_product_sku = %s` trong PHP. Cột dùng collation `utf8mb4_bin`.
5. **Case-insensitive DVT**: `convert_unit` dùng `utf8mb4_unicode_ci`. MySQL UNIQUE KEY tự enforce. PHP cũng check trước khi insert/update.
6. **Tab 2 & 3 disabled**: HTML `disabled` attribute + CSS class `tgs-tab-disabled`, không có `data-bs-toggle`/`data-bs-target`. Code tab 2 & 3 giữ nguyên trong PHP, chỉ UI bị disable.
7. **Excel client-side**: XLSX.js parse file → JSON POST → PHP xử lý. Không upload file server.
8. **`config_count` badge**: `ajax_search_products` dùng LEFT JOIN để đếm config per SKU, trả về trong kết quả tìm kiếm.

---

## File Structure

```
tgs_htsoft_stock_converter/
├── tgs-htsoft-stock-converter.php    # Plugin bootstrap
├── PROMPT.md                          # This file
├── includes/
│   └── class-tgs-htsoft-stock-converter.php  # Main class, AJAX handlers
├── admin/
│   └── views/
│       └── page-htsoft-stock-converter.php   # HTML view (Tab 1 active, Tab 2/3 disabled)
└── assets/
    ├── js/htsoft-converter.js         # Frontend JS (v2)
    └── css/htsoft-converter.css       # Styles
```

Parent plugin: `tgs_shop_management`  
- `class-tgs-database.php` — migration runner + table schema
- `assets/js/common/tgs-barcode-scanner.js` — barcode scanner shared component
