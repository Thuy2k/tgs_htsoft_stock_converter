# BẢNG GỐC (Base) & BẢNG GIÁ

> Plugin `tgs_htsoft_stock_converter` — màn "Quản lý đơn vị tính và giá theo ĐVT"
> Phiên bản: 2.0 | 2026-09-01

---

## 1. VÌ SAO CÓ

Trước 01/09/2026: mỗi **bảng giá** (`wp_global_htsoft_price_list`) tự khai cấu trúc
quy đổi riêng trong `wp_global_htsoft_stock_convert` — mã hàng nào có ĐVT nào, tỉ lệ
bao nhiêu, ĐVT bán chính nào. Thêm mã / ĐVT / tỉ lệ ở bảng giá này thì bảng giá
khác KHÔNG được cập nhật → lệch cấu trúc, thiếu ĐVT, thiếu mã. Mỗi bảng giá lại có
nút "Import Excel cấu trúc" riêng → trùng lặp, dễ sai.

Từ 01/09/2026:

- **Bảng gốc (Base)** = nguồn khai CẤU TRÚC duy nhất: SKU ↔ ĐVT ↔ tỉ lệ quy đổi ↔
  ĐVT bán chính ↔ khối lượng ↔ ghi chú gốc + **giá tham khảo** (`unit_price`).
  Giá tham khảo KHÔNG dùng trực tiếp cho POS — POS đọc `unit_price` của
  `wp_global_htsoft_stock_convert` theo `price_list_id`. Nó chỉ để:
  (a) "Thống nhất ĐVT bán chính" chạy đúng công thức gốc (tỉ lệ > 1 gần nhất
  **VÀ có giá**…); (b) nút "Đồng bộ Base → tất cả bảng giá" có tuỳ chọn đổ giá
  tham khảo xuống bảng giá.
- **Bảng giá** bám theo Base. KHÔNG thêm/xóa ĐVT, KHÔNG sửa tỉ lệ. Chỉ khai:
  - `unit_price` — giá theo ĐVT, **riêng từng bảng giá** (được phép khác nhau).
  - `convert_note` — sửa ghi chú nếu cần (⇒ `note_overridden = 1`).
  - `is_default_unit` — đổi ĐVT bán chính cho riêng bảng giá (⇒ `default_unit_overridden = 1`).

---

## 2. BẢNG DỮ LIỆU

```
wp_global_htsoft_unit_base                 ← BASE (mới, hub-only, KHÔNG sync xuống shop)
   global_htsoft_unit_base_id  PK
   global_product_sku          utf8mb4_bin
   convert_unit                utf8mb4_unicode_ci
   convert_to_htsoft           tỉ lệ (1 ĐVT này = N đơn vị nhỏ nhất)
   convert_from_tgs            luôn = 1
   convert_note                ghi chú gốc
   unit_weight_kg              tuỳ chọn
   is_default_unit             ĐVT bán chính "gốc"
   UNIQUE (global_product_sku, convert_unit)

wp_global_htsoft_stock_convert             ← BẢNG ĐỌC cho POS (không đổi vai trò)
   … như cũ …
   + note_overridden          TINYINT  (1 = bảng giá tự sửa ghi chú → Base không ghi đè)
   + default_unit_overridden  TINYINT  (1 = bảng giá tự chọn ĐVT chính → Base không ghi đè is_default_unit cho SKU đó)
```

Schema: `tgs_shop_management/database/class-tgs-database.php`
(`sql_global_htsoft_unit_base()`, thêm 2 cột vào `sql_global_htsoft_stock_convert()`).
Migration: `2026_09_01_000100_create_htsoft_unit_base.php`.

---

## 3. PROPAGATION — CHIẾU BASE → BẢNG GIÁ

Hàm lõi: `TGS_HTSoft_Stock_Converter::propagate_base_to_price_lists($skus = null, $only_list_id = 0)`

1 câu `INSERT … SELECT … FROM unit_base … ON DUPLICATE KEY UPDATE` cho từng bảng giá:

| Cột | Khi INSERT (dòng mới) | Khi UPDATE (dòng đã có) |
|---|---|---|
| `convert_to_htsoft`, `convert_from_tgs`, `unit_weight_kg` | theo Base | **luôn** theo Base |
| `unit_price` | `sync_prices` = none → `NULL`; fill/overwrite → giá tham khảo của Base | none → giữ nguyên; fill → điền nếu đang NULL; overwrite → ghi đè bằng giá tham khảo |
| `convert_note` | theo Base | `IF(note_overridden = 1, giữ, theo Base)` |
| `is_default_unit` | theo Base | `IF(default_unit_overridden = 1, giữ, theo Base)` |
| `is_deleted` | 0 | 0 (hồi sinh dòng đã xóa nếu Base khai lại) |

Sau upsert: `reassert_overridden_defaults()` — nếu Base vừa chèn thêm 1 ĐVT mang cờ
`is_default_unit = 1` vào bảng giá đang override → giữ dòng id NHỎ NHẤT (lựa chọn cũ
của bảng giá), hạ cờ các dòng còn lại (mỗi SKU trong 1 bảng giá chỉ 1 ĐVT chính).

**Base xóa 1 (SKU, ĐVT)** → `propagate_base_delete()`:
xóa mềm dòng tương ứng ở MỌI bảng giá; bảng giá nào vừa mất ĐVT bán chính của SKU
→ tự chọn lại bằng `pick_default_config()` + `apply_default_for_sku()`.

### Propagation chạy khi nào

| Hành động ở Base | Gọi |
|---|---|
| Lưu / sửa 1 ĐVT (`ajax_base_save_mapping`) | `propagate_base_to_price_lists([$sku])` |
| Xóa 1 ĐVT (`ajax_base_delete_mapping`) | `propagate_base_delete()` + `propagate_base_to_price_lists([$sku])` |
| Đặt ĐVT bán chính (`ajax_base_set_default_unit`) | `propagate_base_to_price_lists([$sku])` |
| Import Excel cấu trúc (`ajax_base_import_excel_rows`) | mỗi lô → propagate các SKU trong lô |
| Thống nhất ĐVT bán chính (`ajax_base_default_scan_batch`) | mỗi lô → propagate |
| Nút "Đồng bộ Base → tất cả bảng giá" (`ajax_base_sync_batch`) | chạy lại toàn bộ theo lô SKU; tham số `sync_prices`: `none` / `fill` (điền ĐVT trống giá) / `overwrite` (ghi đè) |
| Tạo bảng giá mới (`ajax_save_price_list`) | `populate_price_list_from_base($new_id)` |

---

## 4. BẢNG GIÁ — HÀNH VI

### 4.1. Khai tay 1 giá (`ajax_save_mapping`, chỉ `id > 0`)

Chỉ ghi `unit_price` + `convert_note` (+ `note_overridden`) + `is_default_unit`
(+ `default_unit_overridden`). Trả về `missing_price_units` = các ĐVT cùng SKU
trong bảng giá này còn **trống giá**.

JS: nếu vừa nhập 1 giá > 0 và còn ĐVT trống giá → mở modal
**"Điền giá cho ĐVT còn trống?"**. Bấm **Điền** → `ajax_fill_missing_prices`
suy theo tỉ lệ từ giá vừa nhập (`per_smallest = giá / tỉ_lệ`), chỉ set các ĐVT
đang NULL, **không đụng ĐVT đã có giá**. Bấm **Bỏ qua** → không làm gì.

### 4.2. Import Giá (`ajax_import_price_rows`)

- Lấy **CHUẨN theo giá trong file** cho đúng ĐVT có trong file.
- ĐVT **không có trong file** → không đụng.
- Dòng có trong file nhưng **trống giá** → để `NULL` (không tự suy theo tỉ lệ).
- Checkbox **"Tự suy giá cho ĐVT thiếu theo tỉ lệ"** (`derive_missing`) — **tắt sẵn**.
  Chỉ khi bật mới suy `base_price_per_unit × tỉ_lệ` cho ĐVT thiếu giá.

> Vì sao: giá lấy từ phần mềm cũ. Tự tính lại → màn bán hàng thấy sai giá
> (thực tế shop không bán theo giá suy ra).

### 4.3. Thêm / xóa ĐVT, sửa tỉ lệ

Bị chặn ở bảng giá (`ajax_save_mapping` với `id = 0` và `ajax_delete_mapping` trả
lỗi). Làm ở Bảng gốc — mọi bảng giá tự cập nhật theo.

### 4.4b. Xóa = XÓA VĨNH VIỄN

Xóa 1 ĐVT ở Bảng gốc (`ajax_base_delete_mapping`) → `DELETE` cứng dòng base +
`propagate_base_delete()` **`DELETE` cứng** dòng tương ứng ở MỌI bảng giá (không
để lại dòng xóa mềm — DB sạch). Bảng giá nào vừa mất ĐVT bán chính của SKU thì tự
chọn lại.

### 4.5b. Import Excel cấu trúc (Bảng gốc) — chống ghi thừa

`ajax_base_import_excel_rows` so từng dòng với DB:
- `(SKU, ĐVT)` chưa có → **tạo mới**.
- Đã có, và tỉ lệ + giá tham khảo + khối lượng + ghi chú **trùng khít** → **bỏ qua**
  (không đụng `updated_at`, không propagate).
- Đã có nhưng khác → **cập nhật**.

Chỉ propagate xuống bảng giá khi thực sự có tạo mới / cập nhật.

### 4.4. ĐVT bán chính

- Nút sao ⭐ trên dòng ĐVT (`ajax_set_default_unit`) → đặt cho **riêng bảng giá**,
  set `default_unit_overridden = 1` cho mọi dòng của SKU đó ⇒ Base không ghi đè.
- Nút **"ĐVT chính theo Bảng gốc"** (`ajax_reset_default_to_base`) → bỏ override
  (toàn bộ bảng giá hoặc 1 SKU) rồi propagate lại từ Base.

### 4.5. Clone bảng giá

`ajax_save_price_list` (tạo mới): `populate_price_list_from_base($new_id)` đổ cấu
trúc từ Base (giá NULL). Nếu chọn "Chép GIÁ từ bảng giá X" →
`overlay_price_list_values(X, new_id)` chép thêm `unit_price` + `convert_note` +
`note_overridden` + `is_default_unit` + `default_unit_overridden`.

---

## 5. MÀN HÌNH

1 view, 2 chế độ (`state.mode` trong `assets/js/htsoft-converter.js`):

| | Bảng gốc (`base`) | Bảng giá (`pricelist`) |
|---|---|---|
| Vào từ | nút **"Mở Bảng gốc"** | thẻ bảng giá → **"Quản lý ĐVT & giá"** |
| Form ĐVT | ĐVT / Tỉ lệ / Ghi chú / Khối lượng / ĐVT chính | **ẩn giá? không** — hiện Giá; ĐVT/Tỉ lệ/Khối lượng **khóa** |
| Cột giá ở lưới | "Giá tham khảo" | "Giá bán" |
| Nút | Import Excel (cấu trúc), Xuất Excel, Thống nhất ĐVT, **Đồng bộ Base → tất cả bảng giá** | Import Giá, Xuất Excel, **ĐVT chính theo Bảng gốc** |
| Xóa ĐVT trên dòng | có (xóa vĩnh viễn) | ẩn |

`postAjax()` tự đổi `tgs_htsoft_converter_*` → `tgs_htsoft_base_*` khi `mode === 'base'`
(bảng `BASE_ACTION_MAP`) và không đính kèm `price_list_id`. Các endpoint base trả
về cùng shape + alias `global_htsoft_unit_base_id AS global_htsoft_stock_convert_id`
để JS dùng lại nguyên hàm render.

### 5b. Lưới "tải hết" (Excel-like)

Bảng dưới cùng KHÔNG phân trang. Vào Bảng gốc / bảng giá → `loadAllRows()` gọi
`tgs_htsoft_converter_list_all` / `tgs_htsoft_base_list_all` (1 query LEFT JOIN
`wp_global_product_name`, ~20k dòng < 1.2s, xếp cạnh nhau theo mã hàng rồi tỉ lệ),
giữ mảng trong RAM, vẽ theo khung nhìn bằng **`TGSDesignSystem.virtualBody`** (như
báo cáo `tgs-bc-tk`): DOM chỉ giữ ~40 dòng, cuộn mượt dù 20k+ dòng. Dải ô lọc theo
cột + nút "Xuất Excel" do `tgs-table-filter.js` / `tgs-erp-ds.js` (nạp sẵn ở
`main-layout.php`) tự gắn.

**Sửa 1 dòng:** bấm dòng (hoặc nút ✎) → nạp vào **dải sửa nhanh** `#gridEditStrip`
phía trên lưới (giống lưới phần mềm cũ). "Lưu dòng" → `confirm('Cập nhật dòng
này?')` → `ajax_save_mapping` → cập nhật tại chỗ trong mảng + vẽ lại. Ở bảng giá
ĐVT/tỉ lệ/khối lượng bị khoá (chỉ sửa Giá + Ghi chú + ĐVT chính). Nút 🗑 (chỉ Bảng
gốc) = xóa vĩnh viễn cả ở mọi bảng giá.

---

## 6. KHÔNG ĐỘNG TỚI

`wp_global_htsoft_stock_convert` giữ nguyên vai trò "bảng đọc" theo `price_list_id`.
Do đó **không sửa** `tgs_pos` (`class-tgs-pos-price-list.php`),
`tgs_shop_management/functions/class-tgs-price-list.php`,
`tgs_selling_policy` (`class-selling-policy-price-units.php`), `tgs-bc-tk`,
`tgs-viettel-invoice`, `tgs-hub-api` / `tgs-pos-sync` (registry, pull-handler).
Propagation luôn giữ bảng đọc đầy đủ.

---

## 7. MIGRATION / TRIỂN KHAI

1. Kích hoạt lại plugin `tgs_shop_management` (hoặc chạy runner migration) để chạy
   `2026_09_01_000100_create_htsoft_unit_base.php`:
   - Tạo `wp_global_htsoft_unit_base` + 2 cột override.
   - **Backfill Base** từ bảng giá mặc định (`is_default = 1`); bổ sung (SKU, ĐVT)
     chỉ có ở bảng giá khác.
   - Đánh dấu `note_overridden` / `default_unit_overridden` cho dữ liệu cũ đang
     lệch Base.
   - 1 lượt propagate Base → mọi bảng giá.
2. Nếu dữ liệu lớn và migration chưa chiếu hết → bấm **"Đồng bộ Base → tất cả
   bảng giá"** trong màn Bảng gốc (chạy theo lô, an toàn chạy lại).

Kiểm tra nhanh:

```sql
SHOW TABLES LIKE 'wp_global_htsoft_unit_base';
SHOW COLUMNS FROM wp_global_htsoft_stock_convert LIKE '%overridden';

SELECT COUNT(*) FROM wp_global_htsoft_unit_base;                 -- ≈ số dòng ĐVT bảng giá mặc định
SELECT price_list_id, COUNT(*) FROM wp_global_htsoft_stock_convert
 WHERE (is_deleted = 0 OR is_deleted IS NULL) GROUP BY price_list_id;  -- mỗi bảng giá ≥ số dòng base
```
