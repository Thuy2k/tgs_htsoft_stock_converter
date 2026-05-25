<?php
/**
 * View: Quy đổi tồn HTSoft
 *
 * Tab 1 — Cấu hình quy đổi DVT (đang dùng)
 *   • Tìm sản phẩm (tên / barcode / SKU) → xem số cấu hình DVT hiện có (huy hiệu)
 *   • Panel phải: list cấu hình DVT của SP + form thêm/sửa từng DVT
 *   • Bảng tổng: tất cả cấu hình — Import Excel / Xuất Excel / Import JSON / Xuất JSON
 *   • Có thể xóa vĩnh viễn từng dòng cấu hình.
 *
 * Tab 2 & 3 (HTSoft→TGS / TGS→HTSoft) — TẠM NGỪNG
 *   • Code giữ trong comment PHP, nút tab bị disabled.
 *   • Lý do: hệ thống chuyển sang nhập tồn đơn vị nhỏ nhất; quy đổi DVT chỉ dùng ở POS.
 *
 * @see PROMPT.md trong thư mục plugin để biết đầy đủ logic thiết kế.
 */

if (!defined('ABSPATH')) {
    exit;
}

$products_url = admin_url('admin.php?page=tgs-shop-management&view=products-v2');
$scanner_js_url = defined('TGS_SHOP_PLUGIN_URL') ? TGS_SHOP_PLUGIN_URL . 'assets/js/common/tgs-barcode-scanner.js' : '';
?>

<!-- ZXing + Scanner (load trực tiếp như hsd-checker để đảm bảo thứ tự) -->
<script src="https://unpkg.com/@zxing/library@0.21.3"></script>
<?php if ($scanner_js_url) : ?>
<script src="<?php echo esc_url($scanner_js_url); ?>"></script>
<?php endif; ?>

<!-- Toast container -->
<div id="tgsToastContainer" class="toast-container position-fixed bottom-0 end-0 p-3" style="z-index:9999;"></div>

<div class="tgs-htsoft-converter-page">

    <!-- ── Header ─────────────────────────────────────────────────────── -->
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <h4 class="fw-bold mb-0">
            <span class="text-muted fw-light">Quản lý kho /</span> Quy đổi tồn HTSoft
        </h4>
        <a href="<?php echo esc_url($products_url); ?>" class="btn btn-label-secondary">
            <i class="bx bx-arrow-back me-1"></i> Quay lại
        </a>
    </div>

    <div class="alert alert-info border-0 mb-4">
        <strong>Mục tiêu mới:</strong> Tồn kho lưu theo đơn vị nhỏ nhất (bán 1 trừ 1).
        Cấu hình DVT bán (Lốc, Vỉ, Thùng…) + tỷ lệ quy đổi tại đây để POS tính đúng tồn cần trừ.
    </div>

    <!-- ── Nav Tabs ───────────────────────────────────────────────────── -->
    <ul class="nav nav-tabs" id="htsoftConverterTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="tab-config"
                    data-bs-toggle="tab" data-bs-target="#pane-config"
                    type="button" role="tab">
                <i class="bx bx-cog me-1"></i>Cấu hình quy đổi DVT
            </button>
        </li>
        <!-- Tab 2 & 3: TẠM NGỪNG — nút disabled, không bấm được -->
        <li class="nav-item" role="presentation">
            <button class="nav-link disabled tgs-tab-disabled"
                    type="button" role="tab"
                    title="Tạm ngừng — hệ thống đã chuyển sang nhập tồn đơn vị nhỏ nhất">
                <i class="bx bx-block me-1 text-danger"></i>Tab 2: HTSoft → TGS
                <span class="badge bg-label-warning ms-1" style="font-size:0.65rem;">Tạm ngừng</span>
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link disabled tgs-tab-disabled"
                    type="button" role="tab"
                    title="Tạm ngừng — hệ thống đã chuyển sang nhập tồn đơn vị nhỏ nhất">
                <i class="bx bx-block me-1 text-danger"></i>Tab 3: TGS → HTSoft
                <span class="badge bg-label-warning ms-1" style="font-size:0.65rem;">Tạm ngừng</span>
            </button>
        </li>
    </ul>

    <div class="tab-content pt-3">

        <!-- ══════════════════════════════════════════════════════════════ -->
        <!--  TAB 1 — Cấu hình quy đổi DVT                                -->
        <!-- ══════════════════════════════════════════════════════════════ -->
        <div class="tab-pane fade show active" id="pane-config" role="tabpanel">

            <!-- Row trên: Tìm SP (trái) + Panel cấu hình (phải) -->
            <div class="row g-3">
                <div class="col-12 col-xl-4">
                    <div class="card h-100">
                        <div class="card-header">
                            <h5 class="mb-0">Tìm sản phẩm để cấu hình</h5>
                        </div>
                        <div class="card-body">
                            <div class="input-group mb-2">
                                <input type="text" class="form-control" id="cfgSearchKeyword"
                                       placeholder="Nhập tên, barcode, SKU…">
                                <button type="button" class="btn btn-primary" id="btnCfgSearch">
                                    <i class="bx bx-search"></i>
                                </button>
                                <button type="button" class="btn btn-outline-primary" id="btnOpenScanner">
                                    <i class="bx bx-camera"></i>
                                </button>
                            </div>
                            <div class="small text-muted mb-3">
                                Hỗ trợ gõ linh hoạt và quét barcode bằng camera.
                                Số huy hiệu xanh = số cấu hình DVT đang có.
                            </div>

                            <div id="scannerWrap" class="mb-3" style="display:none">
                                <video id="cfgScannerVideo" class="w-100 tgs-scan-preview" playsinline muted></video>
                                <button type="button" class="btn btn-sm btn-outline-secondary mt-2"
                                        id="btnCloseScanner">Đóng camera</button>
                            </div>

                            <div id="cfgSearchResults" class="tgs-result-list"></div>
                        </div>
                    </div>
                </div>

                <!-- ── Panel cấu hình DVT ─────────────────────────────── -->
                <div class="col-12 col-xl-8">
                    <div class="card h-100">
                        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                            <div>
                                <h5 class="mb-0" id="cfgPanelTitle">Cấu hình quy đổi</h5>
                                <div class="small text-muted mt-1" id="cfgPanelSubtitle"></div>
                            </div>
                            <span class="badge bg-label-primary" id="cfgFormMode">Thêm mới</span>
                        </div>
                        <div class="card-body">

                            <!-- Trạng thái ban đầu chưa chọn SP -->
                            <div id="cfgEmptyState" class="text-center py-5 text-muted">
                                <i class="bx bx-left-arrow-circle" style="font-size:2.5rem;"></i>
                                <p class="mt-2 mb-0">Chọn sản phẩm từ danh sách bên trái để<br>xem và chỉnh sửa cấu hình DVT.</p>
                            </div>

                            <!-- Nội dung sau khi chọn SP -->
                            <div id="cfgContent" style="display:none">

                                <!-- Thông tin SP đang chọn -->
                                <div class="tgs-selected-product-info mb-3">
                                    <input type="hidden" id="cfgSku">
                                    <div class="fw-semibold fs-6" id="cfgProductNameDisplay"></div>
                                    <div class="small text-muted">
                                        SKU: <span id="cfgSkuDisplay" class="fw-semibold text-dark"></span>
                                        &nbsp;|&nbsp; DVT gốc: <span id="cfgUnitDisplay"></span>
                                    </div>
                                </div>

                                <!-- Danh sách cấu hình DVT hiện có -->
                                <div class="mb-3">
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <h6 class="mb-0">
                                            Cấu hình DVT hiện có
                                            <span class="badge bg-label-primary ms-1" id="cfgCountBadge">0</span>
                                        </h6>
                                        <button type="button" class="btn btn-xs btn-outline-secondary"
                                                id="btnRefreshConfigs" title="Tải lại">
                                            <i class="bx bx-refresh"></i>
                                        </button>
                                    </div>
                                    <div id="cfgExistingConfigs">
                                        <p class="text-muted small">Chưa có cấu hình DVT nào.</p>
                                    </div>
                                </div>

                                <!-- Form thêm / sửa DVT -->
                                <div class="border-top pt-3">
                                    <h6 id="cfgEditTitle" class="mb-3">
                                        <i class="bx bx-plus-circle me-1 text-primary"></i>Thêm cấu hình DVT mới
                                    </h6>
                                    <input type="hidden" id="cfgMappingId" value="">

                                    <div class="row g-2 mb-2">
                                        <div class="col-12 col-md-5">
                                            <label class="form-label">
                                                Đơn vị tính bán <span class="text-danger">*</span>
                                            </label>
                                            <input type="text" class="form-control" id="cfgConvertUnit"
                                                   placeholder="VD: Lốc_4, Vỉ_10, Thùng_48">
                                            <div class="form-text">Tên DVT bán tại POS (không được trùng nhau, không phân biệt hoa/thường).</div>
                                        </div>
                                        <div class="col-12 col-md-3">
                                            <label class="form-label">
                                                Tỷ lệ quy đổi <span class="text-danger">*</span>
                                            </label>
                                            <input type="number" class="form-control" id="cfgConvertToHtsoft"
                                                   min="1" step="1" value="1">
                                            <div class="form-text" id="cfgRatioPreview">1 DVT = 1 đơn vị</div>
                                        </div>
                                        <div class="col-12 col-md-4">
                                            <label class="form-label">Ghi chú hiển thị ở POS</label>
                                            <input type="text" class="form-control" id="cfgNote"
                                                   placeholder="VD: 1 Lốc_4 = 4 Chai">
                                        </div>
                                    </div>

                                    <div class="row g-2 mb-3">
                                        <div class="col-12 col-md-4">
                                            <label class="form-label">
                                                Giá bán <span class="text-muted small">(tuỳ chọn)</span>
                                            </label>
                                            <div class="input-group">
                                                <input type="number" class="form-control" id="cfgUnitPrice"
                                                       min="0" step="1" placeholder="VD: 45000">
                                                <span class="input-group-text">VNĐ</span>
                                            </div>
                                            <div class="form-text">Giá bán theo DVT này. Để trống nếu chưa xác định.</div>
                                        </div>
                                    </div>
                                        <button type="button" class="btn btn-primary" id="btnSaveMapping">
                                            <i class="bx bx-save me-1"></i>Lưu cấu hình
                                        </button>
                                        <button type="button" class="btn btn-outline-secondary" id="btnResetUnitForm">
                                            <i class="bx bx-x me-1"></i>Hủy chỉnh sửa
                                        </button>
                                    </div>
                                </div>
                            </div><!-- /#cfgContent -->

                        </div>
                    </div>
                </div>

            </div><!-- /.row (top) -->

            <!-- ── Bảng tổng: tất cả cấu hình ───────────────────────── -->
            <div class="row mt-3">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                            <h5 class="mb-0">Tất cả cấu hình quy đổi</h5>
                            <div class="d-flex align-items-center flex-wrap gap-2">
                                <div class="input-group input-group-sm tgs-map-search">
                                    <input type="text" class="form-control" id="mappingKeyword"
                                           placeholder="Tìm SKU, tên hàng, DVT…">
                                    <button class="btn btn-outline-secondary" type="button"
                                            id="btnReloadMappings"><i class="bx bx-refresh"></i></button>
                                </div>
                                <!-- Excel (chính) -->
                                <button class="btn btn-sm btn-success" type="button" id="btnImportExcel">
                                    <i class="bx bx-import me-1"></i>Import Excel
                                </button>
                                <input type="file" id="excelImportFile" accept=".xlsx,.xls" class="d-none">
                                <button class="btn btn-sm btn-primary" type="button" id="btnExportExcel">
                                    <i class="bx bx-export me-1"></i>Xuất Excel
                                </button>
                                <!-- JSON (đã xóa) -->
                                <!-- Import giá bán -->
                                <button class="btn btn-sm btn-warning" type="button" id="btnImportPriceExcel">
                                    <i class="bx bx-dollar me-1"></i>Import Giá
                                </button>
                                <input type="file" id="priceImportFile" accept=".xlsx,.xls" class="d-none">
                            </div>
                        </div>
                        <!-- Hướng dẫn cột Excel -->
                        <div class="alert alert-light border-start border-4 border-info mb-0 rounded-0 py-2 px-3"
                             style="font-size:0.82rem;">
                            <strong><i class="bx bx-info-circle me-1 text-info"></i>Định dạng file <em>Import Excel</em> (quy đổi DVT):</strong>
                            <span class="ms-1">
                                Cột <strong>A</strong> Mã hàng (SKU)&nbsp;·&nbsp;
                                Cột <strong>B</strong> Tên hàng <em class="text-muted">(bỏ qua)</em>&nbsp;·&nbsp;
                                Cột <strong>C</strong> Đơn vị tính&nbsp;·&nbsp;
                                Cột <strong>D</strong> Tỷ lệ quy đổi&nbsp;·&nbsp;
                                Cột <strong>E</strong> Ghi chú
                            </span>
                            <span class="ms-2 text-muted">— Dòng 1 là tiêu đề, dữ liệu từ dòng 2.</span>
                        </div>
                        <!-- Hướng dẫn cột Excel giá -->
                        <div class="alert alert-light border-start border-4 border-warning mb-0 rounded-0 py-2 px-3"
                             style="font-size:0.82rem;">
                            <strong><i class="bx bx-dollar me-1 text-warning"></i>Định dạng file <em>Import Giá</em>:</strong>
                            <span class="ms-1">
                                Cột <strong>A</strong> Mã hàng (SKU, bắt buộc)&nbsp;·&nbsp;
                                Cột <strong>B</strong> Tên hàng <em class="text-muted">(tham khảo)</em>&nbsp;·&nbsp;
                                Cột <strong>C</strong> ĐVT (bắt buộc)&nbsp;·&nbsp;
                                Cột <strong>D</strong> Giá bán (bắt buộc)
                            </span>
                            <span class="ms-2 text-muted">— Tự suy giá cho DVT thiếu dựa vào tỷ lệ quy đổi đã cấu hình. Excel ưu tiên.</span>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover mb-0" id="mappingTable">
                                    <thead class="table-light">
                                        <tr>
                                            <th>SKU</th>
                                            <th>Tên sản phẩm</th>
                                            <th>DVT bán</th>
                                            <th>Tỷ lệ</th>
                                            <th>Giá bán</th>
                                            <th>Ghi chú POS</th>
                                            <th style="width:100px;">Thao tác</th>
                                        </tr>
                                    </thead>
                                    <tbody id="mappingTableBody"></tbody>
                                </table>
                            </div>
                        </div>
                        <div class="card-footer d-flex justify-content-between align-items-center flex-wrap gap-2 py-2">
                            <div class="text-muted small" id="mappingTableFooter"></div>
                            <nav id="mappingPagination"></nav>
                        </div>
                    </div>
                </div>
            </div><!-- /.row (bottom table) -->

        </div><!-- /#pane-config -->


        <!-- ══════════════════════════════════════════════════════════════
             TAB 2 — HTSoft → TGS — TẠM NGỪNG (code giữ trong comment)
             Lý do: hệ thống chuyển sang nhập tồn đơn vị nhỏ nhất,
             bán 1 trừ 1; tab này không còn cần thiết trong luồng hiện tại.
        ══════════════════════════════════════════════════════════════════ -->
        <!--
        <div class="tab-pane fade" id="pane-htsoft-to-tgs" role="tabpanel">
            ...HTSoft to TGS conversion logic...
        </div>
        -->


        <!-- ══════════════════════════════════════════════════════════════
             TAB 3 — TGS → HTSoft — TẠM NGỪNG (code giữ trong comment)
        ══════════════════════════════════════════════════════════════════ -->
        <!--
        <div class="tab-pane fade" id="pane-tgs-to-htsoft" role="tabpanel">
            ...TGS to HTSoft conversion logic...
        </div>
        -->

    </div><!-- /.tab-content -->
</div><!-- /.tgs-htsoft-converter-page -->

<!-- ══════════════════════════════════════════════════════════════════════ -->
<!-- Modal: Import Excel (batch, hỗ trợ 20k+ dòng)                        -->
<!-- ══════════════════════════════════════════════════════════════════════ -->
<div class="modal fade" id="excelImportModal" tabindex="-1"
     data-bs-backdrop="static" data-bs-keyboard="false"
     aria-labelledby="excelImportModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header py-3">
                <h5 class="modal-title" id="excelImportModalLabel">
                    <i class="bx bx-import me-2 text-success"></i>Import Excel — Quy đổi DVT
                </h5>
            </div>
            <div class="modal-body">

                <!-- Thông tin file -->
                <div id="eiFileInfo" class="p-3 bg-light rounded mb-3" style="display:none">
                    <div class="small text-muted mb-1">File đang xử lý:</div>
                    <div class="fw-semibold" id="eiFileName"></div>
                    <div class="small mt-1">Tổng dòng dữ liệu hợp lệ: <strong class="text-primary" id="eiRowCount">0</strong></div>
                </div>

                <!-- Thanh tiến độ -->
                <div id="eiProgressWrap" style="display:none">
                    <div class="d-flex justify-content-between mb-1 small">
                        <span id="eiProgressText" class="text-muted">Chuẩn bị...</span>
                        <span id="eiProgressPct" class="fw-semibold">0%</span>
                    </div>
                    <div class="progress mb-3" style="height:20px; border-radius:6px;">
                        <div id="eiProgressBar"
                             class="progress-bar progress-bar-striped progress-bar-animated bg-success"
                             role="progressbar" style="width:0%; transition:width .3s ease;"></div>
                    </div>
                    <div class="d-flex gap-3 flex-wrap small">
                        <span>Tạo mới: <strong class="text-success" id="eiStatCreated">0</strong></span>
                        <span>Cập nhật: <strong class="text-primary" id="eiStatUpdated">0</strong></span>
                        <span>Bỏ qua: <strong class="text-warning" id="eiStatSkipped">0</strong></span>
                        <span>Lỗi: <strong class="text-danger" id="eiStatErrors">0</strong></span>
                        <span class="ms-auto text-muted" id="eiStatBatch"></span>
                    </div>
                </div>

                <!-- Kết quả cuối -->
                <div id="eiResultWrap" class="mt-3" style="display:none">
                    <div class="alert alert-success mb-1" id="eiResultDone" style="display:none"></div>
                    <div class="alert alert-warning mb-1" id="eiResultStopped" style="display:none">
                        <i class="bx bx-stop-circle me-1"></i>Import đã bị dừng theo yêu cầu.
                    </div>
                    <div id="eiErrorsList" style="display:none">
                        <div class="small fw-semibold text-danger mt-2">Chi tiết lỗi:</div>
                        <ul class="small text-danger mt-1 mb-0 ps-3" id="eiErrorsUl" style="max-height:140px;overflow-y:auto;"></ul>
                    </div>
                </div>

            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-danger" id="eiStopBtn" style="display:none">
                    <i class="bx bx-stop-circle me-1"></i>Dừng lại
                </button>
                <button type="button" class="btn btn-secondary" id="eiCloseBtn">Đóng</button>
            </div>
        </div>
    </div>
</div>

<!-- =====================================================================
     Modal: Import Giá bán theo DVT
     ===================================================================== -->
<div class="modal fade" id="priceImportModal" tabindex="-1"
     data-bs-backdrop="static" data-bs-keyboard="false"
     aria-labelledby="priceImportModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="priceImportModalLabel">
                    <i class="bx bx-dollar me-2"></i>Import Giá bán theo DVT
                </h5>
            </div>
            <div class="modal-body">
                <!-- Thông tin file -->
                <div id="piFileInfo" class="alert alert-info py-2 mb-3 d-none">
                    <i class="bx bx-file me-1"></i>
                    <strong id="piFileName"></strong>
                    &mdash; <span id="piRowCount"></span> dòng dữ liệu
                </div>

                <!-- Tiến độ -->
                <div id="piProgressWrap" class="mb-3 d-none">
                    <div class="d-flex justify-content-between small text-muted mb-1">
                        <span id="piProgressText">Đang chuẩn bị…</span>
                        <span id="piProgressPct">0%</span>
                    </div>
                    <div class="progress" style="height:20px;">
                        <div id="piProgressBar" class="progress-bar progress-bar-striped progress-bar-animated"
                             role="progressbar" style="width:0%"></div>
                    </div>
                </div>

                <!-- Kết quả -->
                <div id="piResultWrap" class="d-none">
                    <div id="piResultDone" class="alert alert-success py-2 d-none"></div>
                    <div id="piResultStopped" class="alert alert-warning py-2 d-none">
                        <i class="bx bx-stop-circle me-1"></i>Đã dừng.
                    </div>
                    <div class="row g-2 text-center mb-2">
                        <div class="col-4">
                            <div class="border rounded py-2">
                                <div class="fs-5 fw-bold text-success" id="piStatUpdated">0</div>
                                <div class="small text-muted">Đã cập nhật giá</div>
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="border rounded py-2">
                                <div class="fs-5 fw-bold text-secondary" id="piStatSkipped">0</div>
                                <div class="small text-muted">Bỏ qua</div>
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="border rounded py-2">
                                <div class="fs-5 fw-bold text-info" id="piStatBatch">0/0</div>
                                <div class="small text-muted">Batch</div>
                            </div>
                        </div>
                    </div>
                    <div id="piErrorsWrap" class="d-none">
                        <div class="text-danger small mb-1"><i class="bx bx-error me-1"></i>Lỗi:</div>
                        <ul class="list-unstyled small text-danger ps-2" id="piErrorsUl"></ul>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-danger" id="piStopBtn">
                    <i class="bx bx-stop-circle me-1"></i>Dừng lại
                </button>
                <button type="button" class="btn btn-secondary" id="piCloseBtn">Đóng</button>
            </div>
        </div>
    </div>
</div>
