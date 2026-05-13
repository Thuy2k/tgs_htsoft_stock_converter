# Checklist Trien Khai - TGS HTSoft Stock Converter

Ngay tao: 2026-05-13
Mục tiêu: Tạo plugin độc lập quy đổi tồn kho HTSoft <-> Hệ thống TGS, hook vào menu Sản phẩm của tgs_shop_management.

## Yeu cau chuc nang
- [ ] Tạo bảng DB trung gian quy đổi trong class-tgs-database.php của tgs_shop_management.
- [ ] Tao plugin moi doc lap: tgs_htsoft_stock_converter.
- [ ] Hook menu con vao muc San pham (mega nav) cua tgs_shop_management.
- [ ] Tao route view moi trong dashboard routes cua tgs_shop_management.
- [ ] Tao trang admin 3 tab:
  - [ ] Tab 1: Cau hinh quy doi SKU.
  - [ ] Tab 2: Quy doi HTSoft -> TGS (import Excel 3 cot, export Excel ket qua).
  - [ ] Tab 3: Quy doi TGS -> HTSoft (import Excel 3 cot, export Excel ket qua).
- [ ] Neu SKU co cau hinh: ap dung ti le quy doi.
- [ ] Neu SKU khong co cau hinh: quy doi 1:1.
- [ ] So khop SKU chinh xac tuyet doi (phan biet hoa thuong, dung do dai chuoi).
- [ ] Cong thuc lam tron cho HTSoft -> TGS:
  - [ ] qty_tgs = ceil(qty_htsoft / convert_to_htsoft).
- [ ] Cong thuc cho TGS -> HTSoft:
  - [ ] qty_htsoft = qty_tgs * convert_to_htsoft.
- [ ] Tab 1 cho phep:
  - [ ] Tim kiem san pham theo ten, barcode_main, sku.
  - [ ] Quet camera de dien barcode tim kiem nhanh.
  - [ ] Them/sua quy doi va ghi chu thong minh.
  - [ ] Hien thi SKU, Don vi tinh, quantity_no_tracking_logs tham khao.
- [ ] UI responsive tot cho dien thoai.
- [ ] Bao mat/AJAX:
  - [ ] nonce check.
  - [ ] check permission.
  - [ ] sanitize input.
- [ ] Kiem tra loi cu phap PHP sau khi code.

## Ghi chu ky thuat
- Bảng quy đổi dùng chung toàn multisite với `$wpdb->base_prefix`.
- Plugin moi chi hoat dong khi tgs_shop_management da active.
- Tai su dung scanner class `TGSBarcodeScanner` tu tgs_shop_management assets.
- Xu ly Excel o client-side bang SheetJS de nguoi dung xem/tai file ket qua ngay.

## Tieu chi nghiem thu
- [ ] Co submenu trong San pham: "Quy doi ton HTSoft".
- [ ] Mo trang thay du 3 tab va chay duoc tren mobile.
- [ ] Tab 1 luu/sua quy doi duoc.
- [ ] Tab 2 quy doi dung cong thuc + xuat duoc file.
- [ ] Tab 3 quy doi dung cong thuc + xuat duoc file.
- [ ] Reactivate plugin tgs_shop_management tao duoc bang moi.
