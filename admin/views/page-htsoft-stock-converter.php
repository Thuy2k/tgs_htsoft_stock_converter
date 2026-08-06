<?php
/**
 * View: Quản lý đơn vị tính và giá theo ĐVT
 * Giao diện đơn giản không tab - Cấu hình DVT quy đổi cho POS
 */

if (!defined('ABSPATH')) {
    exit;
}

$products_url = admin_url('admin.php?page=tgs-shop-management&view=products-v2');
$scanner_js_url = defined('TGS_SHOP_PLUGIN_URL') ? TGS_SHOP_PLUGIN_URL . 'assets/js/common/tgs-barcode-scanner.js' : '';
?>

<!-- ZXing + Scanner -->
<script src="https://unpkg.com/@zxing/library@0.21.3"></script>
<?php if ($scanner_js_url) : ?>
<script src="<?php echo esc_url($scanner_js_url); ?>"></script>
<?php endif; ?>

<!-- Toast container -->
<div id="tgsToastContainer" class="toast-container position-fixed bottom-0 end-0 p-3" style="z-index:9999;"></div>

<div class="tgs-htsoft-converter-page">

    <!-- ── Header ─────────────────────────────────────────────────────── -->
    <div class="tgs-page-header mb-4">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
            <div>
                <div class="d-flex align-items-center gap-2 mb-2">
                    <div class="tgs-icon-badge">
                        <i class="bx bx-transfer"></i>
                    </div>
                    <h3 class="fw-bold mb-0">Quản lý đơn vị tính và giá theo ĐVT</h3>
                </div>
                <p class="text-muted mb-0 small">
                    Cấu hình tỷ lệ quy đổi và giá bán cho các đơn vị bán (Lốc, Vỉ, Thùng…) để POS tính đúng tồn kho
                </p>
            </div>
            <a href="<?php echo esc_url($products_url); ?>" class="btn btn-outline-secondary">
                <i class="bx bx-arrow-back me-1"></i> Quay lại
            </a>
        </div>
    </div>

    <div class="tgs-content-wrapper">

        <!-- ── Section: Tìm kiếm & Cấu hình sản phẩm ────────────────── -->
        <div class="row g-4 mb-4">
            <!-- Sidebar: Tìm kiếm sản phẩm -->
            <div class="col-12 col-lg-4 col-xl-3">
                <div class="tgs-search-panel">
                    <div class="tgs-panel-header">
                        <h6 class="mb-1 fw-semibold">Tìm sản phẩm</h6>
                        <p class="text-muted small mb-0">Tìm theo tên, barcode hoặc mã SKU</p>
                    </div>
                    <div class="tgs-panel-body">
                        <div class="input-group mb-3">
                            <input type="text" class="form-control" id="cfgSearchKeyword"
                                   placeholder="Tìm kiếm sản phẩm...">
                            <button type="button" class="btn btn-primary" id="btnCfgSearch">
                                <i class="bx bx-search"></i>
                            </button>
                            <button type="button" class="btn btn-outline-primary" id="btnOpenScanner">
                                <i class="bx bx-camera"></i>
                            </button>
                        </div>

                        <div id="scannerWrap" class="mb-3" style="display:none">
                            <video id="cfgScannerVideo" class="w-100 tgs-scan-preview" playsinline muted></video>
                            <button type="button" class="btn btn-sm btn-outline-secondary mt-2 w-100"
                                    id="btnCloseScanner"><i class="bx bx-x me-1"></i>Đóng camera</button>
                        </div>

                        <div id="cfgSearchResults" class="tgs-result-list"></div>
                    </div>
                </div>
            </div>

            <!-- Main: Panel cấu hình DVT -->
            <div class="col-12 col-lg-8 col-xl-9">
                <div class="tgs-config-panel">
                    <div class="tgs-panel-header">
                        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                            <div>
                                <h6 class="mb-1 fw-semibold" id="cfgPanelTitle">Cấu hình đơn vị quy đổi</h6>
                                <div class="text-muted small" id="cfgPanelSubtitle">Chọn sản phẩm để bắt đầu cấu hình</div>
                            </div>
                            <span class="badge bg-primary" id="cfgFormMode" style="display:none;">Thêm mới</span>
                        </div>
                    </div>
                    <div class="tgs-panel-body">

                        <!-- Trạng thái ban đầu chưa chọn SP -->
                        <div id="cfgEmptyState" class="tgs-empty-state">
                            <div class="text-center py-5">
                                <div class="tgs-empty-icon mb-3">
                                    <i class="bx bx-package"></i>
                                </div>
                                <h6 class="text-muted fw-normal">Chưa chọn sản phẩm</h6>
                                <p class="text-muted small mb-0">Tìm và chọn sản phẩm từ bên trái để cấu hình đơn vị quy đổi</p>
                            </div>
                        </div>

                        <!-- Nội dung sau khi chọn SP -->
                        <div id="cfgContent" style="display:none">

                            <!-- Thông tin SP đang chọn -->
                            <div class="tgs-product-selected mb-4">
                                <input type="hidden" id="cfgSku">
                                <div class="d-flex align-items-center gap-3">
                                    <div class="tgs-product-avatar">
                                        <i class="bx bx-box"></i>
                                    </div>
                                    <div class="flex-grow-1">
                                        <h6 class="mb-1 fw-semibold" id="cfgProductNameDisplay"></h6>
                                        <div class="small text-muted">
                                            <span class="badge bg-light text-dark me-2">
                                                <i class="bx bx-barcode me-1"></i><span id="cfgSkuDisplay"></span>
                                            </span>
                                            <span class="text-muted">DVT gốc: <strong id="cfgUnitDisplay"></strong></span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Danh sách cấu hình DVT hiện có -->
                            <div class="tgs-config-list mb-4">
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <h6 class="mb-0 fw-semibold">
                                        Danh sách đơn vị đã cấu hình
                                        <span class="badge bg-primary ms-2" id="cfgCountBadge">0</span>
                                    </h6>
                                    <button type="button" class="btn btn-sm btn-light" id="btnRefreshConfigs">
                                        <i class="bx bx-refresh"></i>
                                    </button>
                                </div>
                                <div id="cfgExistingConfigs" class="tgs-config-cards">
                                    <div class="text-muted small text-center py-3">Chưa có cấu hình DVT nào</div>
                                </div>
                            </div>

                            <!-- Form thêm / sửa DVT -->
                            <div class="tgs-form-section">
                                <div class="tgs-form-header mb-3">
                                    <h6 class="mb-0 fw-semibold" id="cfgEditTitle">
                                        <i class="bx bx-plus-circle me-1"></i>Thêm đơn vị tính mới
                                    </h6>
                                </div>
                                <input type="hidden" id="cfgMappingId" value="">

                                <div class="row g-3">
                                    <div class="col-12 col-md-6">
                                        <label class="form-label fw-semibold">
                                            Đơn vị tính bán <span class="text-danger">*</span>
                                        </label>
                                        <input type="text" class="form-control" id="cfgConvertUnit"
                                               placeholder="VD: Lốc_4, Vỉ_10, Thùng_48">
                                        <div class="form-text">Tên DVT hiển thị tại POS (không trùng lặp)</div>
                                    </div>
                                    <div class="col-12 col-md-3">
                                        <label class="form-label fw-semibold">
                                            Tỷ lệ quy đổi <span class="text-danger">*</span>
                                        </label>
                                        <input type="number" class="form-control" id="cfgConvertToHtsoft"
                                               min="1" step="1" value="1">
                                        <div class="form-text text-primary fw-semibold" id="cfgRatioPreview">1 DVT = 1 đơn vị</div>
                                    </div>
                                    <div class="col-12 col-md-3">
                                        <label class="form-label fw-semibold">Giá bán (VNĐ)</label>
                                        <input type="number" class="form-control" id="cfgUnitPrice"
                                               min="0" step="1000" placeholder="45000">
                                        <div class="form-text">Tuỳ chọn</div>
                                    </div>
                                    <div class="col-12 col-md-6">
                                        <label class="form-label fw-semibold">Ghi chú hiển thị</label>
                                        <input type="text" class="form-control" id="cfgNote"
                                               placeholder="VD: 1 Lốc = 4 Chai">
                                        <div class="form-text">Mô tả hiển thị tại POS</div>
                                    </div>
                                    <div class="col-12 col-md-3">
                                        <label class="form-label fw-semibold">Khối lượng (kg)</label>
                                        <input type="number" class="form-control" id="cfgUnitWeightKg"
                                               min="0" step="0.001" placeholder="2.3">
                                        <div class="form-text">Tuỳ chọn</div>
                                    </div>
                                </div>

                                <div class="mt-4 d-flex gap-2 flex-wrap">
                                    <button type="button" class="btn btn-primary" id="btnSaveMapping">
                                        <i class="bx bx-save me-1"></i>Lưu cấu hình
                                    </button>
                                    <button type="button" class="btn btn-light" id="btnResetUnitForm">
                                        <i class="bx bx-x me-1"></i>Hủy
                                    </button>
                                </div>
                            </div>
                        </div><!-- /#cfgContent -->

                    </div>
                </div>
            </div>
        </div><!-- /.row g-4 -->

        <!-- ── Section: Bảng tổng tất cả cấu hình ────────────────────── -->
        <div class="tgs-table-section">
            <div class="tgs-table-panel">
                <div class="tgs-table-header">
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                        <div>
                            <h6 class="mb-1 fw-semibold">Tất cả cấu hình quy đổi</h6>
                            <p class="text-muted small mb-0">Quản lý toàn bộ cấu hình đơn vị quy đổi</p>
                        </div>
                        <div class="d-flex align-items-center flex-wrap gap-2">
                            <div class="input-group input-group-sm tgs-table-search">
                                <input type="text" class="form-control" id="mappingKeyword"
                                       placeholder="Tìm kiếm...">
                                <button class="btn btn-outline-secondary" type="button" id="btnReloadMappings">
                                    <i class="bx bx-refresh"></i>
                                </button>
                            </div>
                            <button class="btn btn-sm btn-success" type="button" id="btnImportExcel">
                                <i class="bx bx-import me-1"></i>Import Excel
                            </button>
                            <input type="file" id="excelImportFile" accept=".xlsx,.xls" class="d-none">
                            <button class="btn btn-sm btn-primary" type="button" id="btnExportExcel">
                                <i class="bx bx-export me-1"></i>Xuất Excel
                            </button>
                            <button class="btn btn-sm btn-warning" type="button" id="btnImportPriceExcel">
                                <i class="bx bx-dollar me-1"></i>Import Giá
                            </button>
                            <input type="file" id="priceImportFile" accept=".xlsx,.xls" class="d-none">
                        </div>
                    </div>
                </div>

                <!-- Hướng dẫn import -->
                <div class="tgs-import-guide">
                    <div class="tgs-guide-item">
                        <i class="bx bx-info-circle text-primary"></i>
                        <div>
                            <strong>Import Excel (Quy đổi DVT):</strong>
                            <span class="text-muted">A: Mã hàng · B: Tên · C: ĐVT · D: Tỷ lệ · E: Giá · F: Khối lượng · G: Ghi chú</span>
                        </div>
                    </div>
                    <div class="tgs-guide-item">
                        <i class="bx bx-dollar text-warning"></i>
                        <div>
                            <strong>Import Giá:</strong>
                            <span class="text-muted">A: Mã hàng · B: Tên · C: ĐVT · D: Giá bán (tự suy giá cho DVT thiếu)</span>
                        </div>
                    </div>
                </div>

                <!-- Thông báo dữ liệu đã thay đổi (không tự động tải lại để tránh nặng) -->
                <div id="mappingStaleNotice" class="alert alert-warning py-2 px-3 mb-0 d-none
                            d-flex align-items-center justify-content-between flex-wrap gap-2">
                    <span class="small">
                        <i class="bx bx-info-circle me-1"></i>
                        Dữ liệu vừa thay đổi. Bảng bên dưới chưa được cập nhật.
                    </span>
                    <button type="button" class="btn btn-sm btn-warning" id="btnReloadStale">
                        <i class="bx bx-refresh me-1"></i>Tải lại bảng
                    </button>
                </div>

                <div class="tgs-table-body">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0" id="mappingTable">
                            <thead>
                                <tr>
                                    <th>Mã hàng</th>
                                    <th>Tên sản phẩm</th>
                                    <th>ĐVT bán</th>
                                    <th>Tỷ lệ</th>
                                    <th>Giá bán</th>
                                    <th>Khối lượng</th>
                                    <th>Ghi chú</th>
                                    <th style="width:100px;">Thao tác</th>
                                </tr>
                            </thead>
                            <tbody id="mappingTableBody"></tbody>
                        </table>
                    </div>
                </div>
                <div class="tgs-table-footer">
                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        <div class="text-muted small" id="mappingTableFooter"></div>
                        <select class="form-select form-select-sm" id="mappingPerPage" style="width:auto;">
                            <option value="50" selected>50 dòng/trang</option>
                            <option value="100">100 dòng/trang</option>
                            <option value="200">200 dòng/trang</option>
                        </select>
                    </div>
                    <nav id="mappingPagination" class="d-flex align-items-center gap-1 flex-wrap"></nav>
                </div>
            </div>
        </div><!-- /.tgs-table-section -->

    </div><!-- /.tgs-content-wrapper -->
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
                        <div class="col-3">
                            <div class="border rounded py-2">
                                <div class="fs-5 fw-bold text-success" id="piStatUpdated">0</div>
                                <div class="small text-muted">Đã cập nhật giá</div>
                            </div>
                        </div>
                        <div class="col-3">
                            <div class="border rounded py-2">
                                <div class="fs-5 fw-bold text-secondary" id="piStatNoChange">0</div>
                                <div class="small text-muted">Không thay đổi</div>
                            </div>
                        </div>
                        <div class="col-3">
                            <div class="border rounded py-2">
                                <div class="fs-5 fw-bold text-warning" id="piStatSkipped">0</div>
                                <div class="small text-muted">Bỏ qua</div>
                            </div>
                        </div>
                        <div class="col-3">
                            <div class="border rounded py-2">
                                <div class="fs-5 fw-bold text-info" id="piStatBatch">0/0</div>
                                <div class="small text-muted">Batch</div>
                            </div>
                        </div>
                    </div>
                    <!-- Bảng chi tiết kết quả import -->
                    <div id="piDetailsWrap" class="d-none mb-2">
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="small fw-semibold text-muted"><i class="bx bx-detail me-1"></i>Chi tiết thay đổi:</span>
                            <button type="button" class="btn btn-xs btn-outline-secondary" id="piToggleDetails">
                                <i class="bx bx-show"></i> Hiện
                            </button>
                        </div>
                        <div id="piDetailsBody" class="d-none mt-1" style="max-height:300px;overflow-y:auto;">
                            <table class="table table-sm table-bordered small mb-0" style="font-size:0.8rem;">
                                <thead class="table-light">
                                    <tr>
                                        <th>SKU</th>
                                        <th>DVT</th>
                                        <th>Giá cũ</th>
                                        <th>Giá mới</th>
                                        <th>Trạng thái</th>
                                        <th>Ghi chú</th>
                                    </tr>
                                </thead>
                                <tbody id="piDetailsBodyContent"></tbody>
                            </table>
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
