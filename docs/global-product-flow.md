# Luồng sản phẩm global cho TGS HTSoft Stock Converter

Plugin này cấu hình quy đổi đơn vị tồn HTSoft theo SKU. Từ bản chuyển global, catalog sản phẩm không đọc bảng local nữa.

## Nguyên tắc

- Bảng cấu hình quy đổi vẫn dùng SKU chung ở cột `global_product_sku`.
- Thông tin sản phẩm để tìm kiếm, hiển thị tên, barcode, đơn vị tính lấy từ `wp_global_product_name`.
- Các field `local_product_sku`, `local_product_name`, `local_product_unit` trong response chỉ là alias để giữ JS hiện tại chạy ổn.
- Không dùng `TGS_TABLE_LOCAL_PRODUCT_NAME`, không join `local_product_name`.
- Plugin này không cập nhật tồn; nó chỉ lưu tỷ lệ quy đổi. Phần tồn thực tế vẫn theo ledger/API ở `tgs_shop_management`.

## File chính

- `includes/class-tgs-htsoft-stock-converter.php`
  - `table_product()` trả về bảng global product.
  - `ajax_search_products()` tìm theo tên/SKU/barcode global và trả alias `local_product_*`.
  - `ajax_list_mappings()`, `ajax_get_mapping()`, `ajax_export_excel_rows()` join mapping với global product bằng `global_product_sku`.

Khi thêm tính năng mới, hãy dùng SKU làm khóa chính để nối với cấu hình quy đổi, và lấy thông tin catalog từ global product/API.
