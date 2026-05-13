# Plan Cong Viec - HTSoft Stock Converter

## Muc tieu
- Tao plugin doc lap de quy doi ton kho 2 chieu giua HTSoft va TGS.
- Hook vao menu con San pham trong he thong tgs_shop_management.
- Co 3 tab: Cau hinh, HTSoft -> TGS, TGS -> HTSoft.

## Pham vi thuc hien
1. DB schema:
- Thêm bảng dùng chung toàn multisite: `{$wpdb->base_prefix}global_htsoft_stock_convert` trong class-tgs-database.php.
- Kich hoat lai plugin `tgs_shop_management` se tao bang.

2. Plugin moi:
- Dang ky route qua filter `tgs_shop_dashboard_routes`.
- Them menu con qua action `tgs_shop_product_menu`.
- AJAX API rieng cho:
  - Tim san pham.
  - Luu/sua cau hinh quy doi.
  - Lay danh sach cau hinh.
  - Lay mapping theo danh sach SKU de quy doi Excel.

3. UI/UX:
- Tab 1 mobile-first, ho tro quet camera barcode.
- Tab 2/3 import file Excel 3 cot va export file ket qua.
- Neu SKU chua cau hinh thi mac dinh 1:1.

4. Quy tac quy doi:
- Config: 1 (TGS, khoa) -> N (HTSoft, cho sua).
- HTSoft -> TGS: `ceil(so_luong_htsoft / N)`.
- TGS -> HTSoft: `so_luong_tgs * N`.

## Kiem thu
- Menu hien dung vi tri.
- 3 tab hoat dong tren desktop/mobile.
- Quy doi dung cong thuc.
- Luu/sua cau hinh khong loi.
