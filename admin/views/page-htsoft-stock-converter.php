<?php

if (!defined('ABSPATH')) {
    exit;
}

$products_url = admin_url('admin.php?page=tgs-shop-management&view=products-v2');
?>

<div class="tgs-htsoft-converter-page">
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <h4 class="fw-bold mb-0">
            <span class="text-muted fw-light">Quản lý kho /</span> Quy đổi tồn HTSoft
        </h4>
        <a href="<?php echo esc_url($products_url); ?>" class="btn btn-label-secondary">
            <i class="bx bx-arrow-back me-1"></i> Quay lại
        </a>
    </div>

    <div class="alert alert-info border-0 mb-4">
        <strong>Mục tiêu:</strong> Chuẩn hóa tồn kho theo quy tắc bán 1 trừ 1 bên TGS. Cấu hình quy đổi theo SKU để đối soát với HTSoft.
    </div>

    <ul class="nav nav-tabs" id="htsoftConverterTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="tab-config" data-bs-toggle="tab" data-bs-target="#pane-config" type="button" role="tab">Tab 1: Cấu hình quy đổi</button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="tab-htsoft-to-tgs" data-bs-toggle="tab" data-bs-target="#pane-htsoft-to-tgs" type="button" role="tab">Tab 2: HTSoft -> TGS</button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="tab-tgs-to-htsoft" data-bs-toggle="tab" data-bs-target="#pane-tgs-to-htsoft" type="button" role="tab">Tab 3: TGS -> HTSoft</button>
        </li>
    </ul>

    <div class="tab-content pt-3">
        <div class="tab-pane fade show active" id="pane-config" role="tabpanel">
            <div class="row g-3">
                <div class="col-12 col-xl-5">
                    <div class="card h-100">
                        <div class="card-header">
                            <h5 class="mb-0">Tìm sản phẩm để cấu hình</h5>
                        </div>
                        <div class="card-body">
                            <div class="input-group mb-2">
                                <input type="text" class="form-control" id="cfgSearchKeyword" placeholder="Nhập tên, barcode, SKU...">
                                <button type="button" class="btn btn-primary" id="btnCfgSearch"><i class="bx bx-search"></i></button>
                                <button type="button" class="btn btn-outline-primary" id="btnOpenScanner"><i class="bx bx-camera"></i></button>
                            </div>
                            <div class="small text-muted mb-3">Chỉ hiển thị sản phẩm không theo dõi tồn. Hỗ trợ gõ linh hoạt theo tên và quét barcode bằng camera điện thoại.</div>

                            <div id="scannerWrap" class="mb-3 d-none">
                                <div id="scanCameraPreview" class="tgs-scan-preview"></div>
                                <button type="button" class="btn btn-sm btn-outline-secondary mt-2" id="btnCloseScanner">Đóng camera</button>
                            </div>

                            <div id="cfgSearchResults" class="tgs-result-list"></div>
                        </div>
                    </div>
                </div>

                <div class="col-12 col-xl-7">
                    <div class="card h-100">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">Cấu hình quy đổi</h5>
                            <span class="badge bg-label-primary" id="cfgFormMode">Thêm mới</span>
                        </div>
                        <div class="card-body">
                            <input type="hidden" id="cfgMappingId" value="">

                            <div class="row g-2 mb-3">
                                <div class="col-12 col-md-4">
                                    <label class="form-label">SKU</label>
                                    <input type="text" class="form-control" id="cfgSku" readonly>
                                </div>
                                <div class="col-12 col-md-8">
                                    <label class="form-label">Tên sản phẩm</label>
                                    <input type="text" class="form-control" id="cfgProductName" readonly>
                                </div>
                                <div class="col-12 col-md-6">
                                    <label class="form-label">Đơn vị tính</label>
                                    <input type="text" class="form-control" id="cfgUnit" readonly>
                                </div>
                                <!-- Đã loại bỏ trường SL tham khảo logs -->
                            </div>

                            <div class="tgs-convert-ratio mb-3">
                                <div class="ratio-box">
                                    <label class="form-label mb-1">TGS</label>
                                    <input type="number" class="form-control" value="1" readonly>
                                </div>
                                <div class="ratio-arrow"><i class="bx bx-transfer-alt"></i></div>
                                <div class="ratio-box">
                                    <label class="form-label mb-1">HTSoft</label>
                                    <input type="number" class="form-control" id="cfgConvertToHtsoft" min="1" step="1" value="1">
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Ghi chú quy đổi</label>
                                <textarea class="form-control" id="cfgNote" rows="3" placeholder="Nhập ghi chú cho SKU này..."></textarea>
                            </div>

                            <div class="d-flex gap-2 flex-wrap">
                                <button type="button" class="btn btn-primary" id="btnSaveMapping"><i class="bx bx-save me-1"></i>Lưu cấu hình</button>
                                <button type="button" class="btn btn-outline-secondary" id="btnResetForm">Làm mới</button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-12">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                            <h5 class="mb-0">Danh sách cấu hình quy đổi</h5>
                            <div class="d-flex align-items-center flex-wrap gap-2">
                                <div class="input-group input-group-sm tgs-map-search">
                                    <input type="text" class="form-control" id="mappingKeyword" placeholder="Tìm trong danh sách cấu hình...">
                                    <button class="btn btn-outline-secondary" type="button" id="btnReloadMappings"><i class="bx bx-refresh"></i></button>
                                </div>
                                <button class="btn btn-sm btn-outline-primary" type="button" id="btnExportMappingsJson"><i class="bx bx-export me-1"></i>Xuất JSON</button>
                                <button class="btn btn-sm btn-outline-success" type="button" id="btnImportMappingsJson"><i class="bx bx-import me-1"></i>Import JSON</button>
                                <input type="file" id="mappingJsonFile" accept=".json,application/json" class="d-none">
                            </div>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover mb-0" id="mappingTable">
                                    <thead class="table-light">
                                        <tr>
                                            <th>SKU</th>
                                            <th>Tên sản phẩm</th>
                                            <th>Đơn vị</th>
                                            <!-- Đã loại bỏ cột SL tham khảo logs -->
                                            <th>Tỷ lệ</th>
                                            <th>Ghi chú</th>
                                            <th style="width: 90px;">Sửa</th>
                                        </tr>
                                    </thead>
                                    <tbody id="mappingTableBody"></tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="tab-pane fade" id="pane-htsoft-to-tgs" role="tabpanel">
            <div class="card mb-3">
                <div class="card-header"><h5 class="mb-0">Quy đổi file HTSoft -> TGS</h5></div>
                <div class="card-body">
                    <div class="alert alert-warning">
                        File vào gồm 3 cột: Mã hàng, Tên hàng, Số lượng. So khớp SKU là chính xác tuyệt đối (phân biệt hoa thường).
                        <br>Công thức: <strong>SL_TGS = ceil(SL_HTSoft / he_so_HTSoft)</strong>. SKU không cấu hình thì hệ số = 1.
                    </div>
                    <div class="row g-2 align-items-end">
                        <div class="col-12 col-md-5">
                            <label class="form-label">File Excel/CSV</label>
                            <input type="file" class="form-control" id="fileHtsoftToTgs" accept=".xlsx,.xls,.csv">
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label">Tab dữ liệu trong file</label>
                            <select class="form-select" id="sheetHtsoftToTgs" disabled>
                                <option value="">File chỉ có 1 tab hoặc chưa chọn file</option>
                            </select>
                            <div class="form-text">Nếu file có nhiều tab, hãy chọn đúng tab cần quy đổi.</div>
                        </div>
                        <div class="col-12 col-md-3 d-flex gap-2">
                            <button type="button" class="btn btn-primary" id="btnConvertHtsoftToTgs"><i class="bx bx-refresh me-1"></i>Quy đổi</button>
                            <button type="button" class="btn btn-success" id="btnExportHtsoftToTgs" disabled><i class="bx bx-download me-1"></i>Xuất Excel</button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><h6 class="mb-0">Kết quả xem trước</h6></div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm table-striped mb-0" id="tableHtsoftToTgs">
                            <thead class="table-light">
                                <tr>
                                    <th>Mã hàng</th>
                                    <th>Tên hàng</th>
                                    <th>SL gốc HTSoft</th>
                                    <th>Hệ số</th>
                                    <th>SL quy đổi TGS</th>
                                </tr>
                            </thead>
                            <tbody id="tbodyHtsoftToTgs"></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="tab-pane fade" id="pane-tgs-to-htsoft" role="tabpanel">
            <div class="card mb-3">
                <div class="card-header"><h5 class="mb-0">Quy đổi file TGS -> HTSoft</h5></div>
                <div class="card-body">
                    <div class="alert alert-warning">
                        File vào gồm 3 cột: Mã hàng, Tên hàng, Số lượng.
                        <br>Công thức: <strong>SL_HTSoft = SL_TGS * he_so_HTSoft</strong>. SKU không cấu hình thì hệ số = 1.
                    </div>
                    <div class="row g-2 align-items-end">
                        <div class="col-12 col-md-5">
                            <label class="form-label">File Excel/CSV</label>
                            <input type="file" class="form-control" id="fileTgsToHtsoft" accept=".xlsx,.xls,.csv">
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label">Tab dữ liệu trong file</label>
                            <select class="form-select" id="sheetTgsToHtsoft" disabled>
                                <option value="">File chỉ có 1 tab hoặc chưa chọn file</option>
                            </select>
                            <div class="form-text">Nếu file có nhiều tab, hãy chọn đúng tab cần quy đổi.</div>
                        </div>
                        <div class="col-12 col-md-3 d-flex gap-2">
                            <button type="button" class="btn btn-primary" id="btnConvertTgsToHtsoft"><i class="bx bx-refresh me-1"></i>Quy đổi</button>
                            <button type="button" class="btn btn-success" id="btnExportTgsToHtsoft" disabled><i class="bx bx-download me-1"></i>Xuất Excel</button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><h6 class="mb-0">Kết quả xem trước</h6></div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm table-striped mb-0" id="tableTgsToHtsoft">
                            <thead class="table-light">
                                <tr>
                                    <th>Mã hàng</th>
                                    <th>Tên hàng</th>
                                    <th>SL gốc TGS</th>
                                    <th>Hệ số</th>
                                    <th>SL quy đổi HTSoft</th>
                                </tr>
                            </thead>
                            <tbody id="tbodyTgsToHtsoft"></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
