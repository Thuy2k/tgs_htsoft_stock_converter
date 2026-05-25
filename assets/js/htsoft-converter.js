/**
 * htsoft-converter.js  — v2
 * Tab 1: Multi-DVT config per SKU
 * Tab 2 & 3: Disabled (kept in HTML but non-functional)
 */
(function () {
    'use strict';

    const CFG = window.TGSHTSoftConverterConfig || {};

    /* =========================================================================
     * State
     * ====================================================================== */
    const state = {
        scanner:            null,
        scannerCooldownAt:  0,
        searchTimer:        null,
        mappingSearchTimer: null,
        selectedProduct:    null,
        mappingPage:        1,
        mappingPerPage:     50,
        mappingTotal:       0,
    };

    /* =========================================================================
     * DOM refs
     * ====================================================================== */
    const el = (id) => document.getElementById(id);

    const dom = {
        cfgSearchKeyword:      el('cfgSearchKeyword'),
        cfgSearchResults:      el('cfgSearchResults'),
        btnOpenScanner:        el('btnOpenScanner'),
        btnCloseScanner:       el('btnCloseScanner'),
        scannerWrap:           el('scannerWrap'),

        cfgEmptyState:         el('cfgEmptyState'),
        cfgContent:            el('cfgContent'),
        cfgSku:                el('cfgSku'),
        cfgProductNameDisplay: el('cfgProductNameDisplay'),
        cfgSkuDisplay:         el('cfgSkuDisplay'),
        cfgUnitDisplay:        el('cfgUnitDisplay'),

        cfgExistingConfigs:    el('cfgExistingConfigs'),
        cfgCountBadge:         el('cfgCountBadge'),

        cfgMappingId:          el('cfgMappingId'),
        cfgConvertUnit:        el('cfgConvertUnit'),
        cfgConvertToHtsoft:    el('cfgConvertToHtsoft'),
        cfgNote:               el('cfgNote'),
        cfgRatioPreview:       el('cfgRatioPreview'),
        cfgEditTitle:          el('cfgEditTitle'),
        cfgFormMode:           el('cfgFormMode'),
        btnSaveMapping:        el('btnSaveMapping'),
        btnResetUnitForm:      el('btnResetUnitForm'),

        mappingKeyword:        el('mappingKeyword'),
        btnReloadMappings:     el('btnReloadMappings'),
        mappingTableBody:      el('mappingTableBody'),
        mappingTableFooter:    el('mappingTableFooter'),
        mappingPagination:     el('mappingPagination'),
        btnRefreshConfigs:     el('btnRefreshConfigs'),

        btnImportExcel:        el('btnImportExcel'),
        excelImportFile:       el('excelImportFile'),
        btnExportExcel:        el('btnExportExcel'),

        btnImportPriceExcel:   el('btnImportPriceExcel'),
        priceImportFile:       el('priceImportFile'),

        btnExportMappingsJson: el('btnExportMappingsJson'),
        btnImportMappingsJson: el('btnImportMappingsJson'),
        mappingJsonFile:       el('mappingJsonFile'),
    };

    /* =========================================================================
     * AJAX helper
     * ====================================================================== */
    function postAjax(action, payload) {
        const body = new URLSearchParams();
        body.append('action', action);
        body.append('nonce', CFG.nonce || '');
        for (const [k, v] of Object.entries(payload || {})) {
            body.append(k, v);
        }
        return fetch(CFG.ajaxUrl, { method: 'POST', body })
            .then((r) => r.json());
    }

    function toast(msg, type) {
        const wrap = document.getElementById('tgsToastContainer');
        if (!wrap) { if (type === 'error') { alert('Loi: ' + msg); } return; }
        const id = 'toast-' + Date.now();
        const bg = (type === 'error') ? 'bg-danger' : 'bg-success';
        wrap.insertAdjacentHTML('beforeend',
            '<div id="' + id + '" class="toast align-items-center text-white ' + bg + ' border-0" role="alert">' +
            '<div class="d-flex">' +
            '<div class="toast-body">' + escHtml(msg) + '</div>' +
            '<button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>' +
            '</div></div>');
        const t = new bootstrap.Toast(document.getElementById(id), { delay: 3500 });
        t.show();
        document.getElementById(id).addEventListener('hidden.bs.toast', function () {
            document.getElementById(id) && document.getElementById(id).remove();
        });
    }

    function escHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    /* =========================================================================
     * Product Search
     * ====================================================================== */
    function searchProducts() {
        var kw = (dom.cfgSearchKeyword ? dom.cfgSearchKeyword.value : '').trim();
        if (!kw) { renderSearchResults([]); return; }

        postAjax('tgs_htsoft_converter_search_products', { keyword: kw })
            .then(function (res) {
                if (res.success) renderSearchResults(res.data.products || []);
                else renderSearchResults([]);
            })
            .catch(function () { renderSearchResults([]); });
    }

    function renderSearchResults(products) {
        if (!dom.cfgSearchResults) return;
        if (!products.length) {
            dom.cfgSearchResults.innerHTML = '<div class="text-muted small p-2">Khong tim thay san pham.</div>';
            return;
        }
        var html = '<div class="list-group list-group-flush">';
        products.forEach(function (p) {
            var count = parseInt(p.config_count || 0, 10);
            var badge = count > 0
                ? '<span class="badge bg-label-primary ms-1" title="' + count + ' cau hinh DVT">' + count + '</span>'
                : '<span class="badge bg-label-secondary ms-1">0</span>';
            html += '<button type="button" class="list-group-item list-group-item-action tgs-product-result"' +
                ' data-sku="' + escHtml(p.local_product_sku) + '"' +
                ' data-name="' + escHtml(p.local_product_name) + '"' +
                ' data-unit="' + escHtml(p.local_product_unit || '') + '"' +
                ' data-count="' + count + '">' +
                '<span class="fw-semibold">' + escHtml(p.local_product_sku) + '</span> ' + badge + '<br>' +
                '<span class="text-muted small">' + escHtml(p.local_product_name) + '</span>' +
                '</button>';
        });
        html += '</div>';
        dom.cfgSearchResults.innerHTML = html;

        dom.cfgSearchResults.querySelectorAll('.tgs-product-result').forEach(function (btn) {
            btn.addEventListener('click', function () {
                selectProduct({
                    local_product_sku:  btn.dataset.sku,
                    local_product_name: btn.dataset.name,
                    local_product_unit: btn.dataset.unit,
                    config_count:       parseInt(btn.dataset.count, 10),
                });
            });
        });
    }

    /* =========================================================================
     * Product Selection
     * ====================================================================== */
    function selectProduct(product) {
        state.selectedProduct = product;

        if (dom.cfgSku)               dom.cfgSku.value               = product.local_product_sku;
        if (dom.cfgProductNameDisplay) dom.cfgProductNameDisplay.textContent = product.local_product_name;
        if (dom.cfgSkuDisplay)         dom.cfgSkuDisplay.textContent  = product.local_product_sku;
        if (dom.cfgUnitDisplay)        dom.cfgUnitDisplay.textContent = product.local_product_unit || 'khong co';

        if (dom.cfgEmptyState) dom.cfgEmptyState.style.display = 'none';
        if (dom.cfgContent)    dom.cfgContent.style.display    = '';

        resetUnitForm();
        loadConfigsForSku(product.local_product_sku);
    }

    /* =========================================================================
     * Configs for selected SKU
     * ====================================================================== */
    function loadConfigsForSku(sku) {
        if (!dom.cfgExistingConfigs) return;
        dom.cfgExistingConfigs.innerHTML = '<div class="text-muted small">Dang tai...</div>';

        postAjax('tgs_htsoft_converter_list_configs_by_sku', { global_product_sku: sku })
            .then(function (res) {
                if (res.success) renderConfigsTable(res.data.configs || []);
                else dom.cfgExistingConfigs.innerHTML = '<div class="text-danger small">Loi tai cau hinh.</div>';
            })
            .catch(function () {
                dom.cfgExistingConfigs.innerHTML = '<div class="text-danger small">Loi ket noi.</div>';
            });
    }

    function renderConfigsTable(configs) {
        if (!dom.cfgExistingConfigs) return;

        if (dom.cfgCountBadge) dom.cfgCountBadge.textContent = configs.length;

        if (!configs.length) {
            dom.cfgExistingConfigs.innerHTML = '<div class="text-muted small fst-italic">Chua co cau hinh DVT nao.</div>';
            return;
        }

        var html = '<table class="table table-sm tgs-config-table mb-0"><thead><tr>' +
            '<th>Don vi tinh</th><th>Ty le</th><th>Ghi chu</th><th></th>' +
            '</tr></thead><tbody>';
        configs.forEach(function (c) {
            html += '<tr>' +
                '<td>' + (c.convert_unit ? escHtml(c.convert_unit) : '<em class="text-muted">mac dinh</em>') + '</td>' +
                '<td>' + escHtml(formatRatio(parseFloat(c.convert_to_htsoft))) + '</td>' +
                '<td class="text-muted small">' + escHtml(c.convert_note || '') + '</td>' +
                '<td class="text-end text-nowrap">' +
                '<button class="btn btn-xs btn-outline-primary me-1" data-cfg-edit="' + c.global_htsoft_stock_convert_id + '">' +
                '<i class="bx bx-edit-alt"></i></button>' +
                '<button class="btn btn-xs btn-outline-danger" data-cfg-delete="' + c.global_htsoft_stock_convert_id + '" data-cfg-unit="' + escHtml(c.convert_unit || '') + '">' +
                '<i class="bx bx-trash"></i></button>' +
                '</td></tr>';
        });
        html += '</tbody></table>';
        dom.cfgExistingConfigs.innerHTML = html;

        dom.cfgExistingConfigs.querySelectorAll('[data-cfg-edit]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                editConfigById(parseInt(btn.dataset.cfgEdit, 10), configs);
            });
        });
        dom.cfgExistingConfigs.querySelectorAll('[data-cfg-delete]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                deleteConfig(parseInt(btn.dataset.cfgDelete, 10), btn.dataset.cfgUnit);
            });
        });
    }

    function editConfigById(id, configs) {
        var c = null;
        for (var i = 0; i < configs.length; i++) {
            if (parseInt(configs[i].global_htsoft_stock_convert_id, 10) === id) { c = configs[i]; break; }
        }
        if (!c) return;
        editConfig(c);
    }

    function editConfig(c) {
        if (dom.cfgMappingId)       dom.cfgMappingId.value       = c.global_htsoft_stock_convert_id;
        if (dom.cfgConvertUnit)     dom.cfgConvertUnit.value      = c.convert_unit || '';
        if (dom.cfgConvertToHtsoft) dom.cfgConvertToHtsoft.value  = c.convert_to_htsoft;
        if (dom.cfgNote)            dom.cfgNote.value             = c.convert_note || '';
        if (dom.cfgFormMode)        dom.cfgFormMode.textContent   = 'Chỉnh sửa';
        if (dom.cfgEditTitle)       dom.cfgEditTitle.innerHTML     = '<i class="bx bx-edit-alt me-1 text-warning"></i>Chỉnh sửa DVT: ' + escHtml(c.convert_unit || 'mặc định');
        updateRatioPreview();
        if (dom.cfgConvertUnit) dom.cfgConvertUnit.focus();
    }

    /* =========================================================================
     * Unit Form
     * ====================================================================== */
    function resetUnitForm() {
        if (dom.cfgMappingId)       dom.cfgMappingId.value       = '0';
        if (dom.cfgConvertUnit)     dom.cfgConvertUnit.value      = '';
        if (dom.cfgConvertToHtsoft) dom.cfgConvertToHtsoft.value  = '';
        if (dom.cfgNote)            dom.cfgNote.value             = '';
        if (dom.cfgFormMode)        dom.cfgFormMode.textContent   = 'Thêm mới';
        if (dom.cfgEditTitle)       dom.cfgEditTitle.innerHTML     = '<i class="bx bx-plus-circle me-1 text-primary"></i>Thêm cấu hình DVT mới';
        updateRatioPreview();
    }

    function updateRatioPreview() {
        if (!dom.cfgRatioPreview) return;
        var unit  = (dom.cfgConvertUnit  ? dom.cfgConvertUnit.value  : '').trim();
        var ratio = parseFloat((dom.cfgConvertToHtsoft ? dom.cfgConvertToHtsoft.value : '').replace(',', '.')) || 0;
        if (ratio > 0) {
            var unitLabel = unit || 'don vi';
            dom.cfgRatioPreview.textContent = '1 ' + unitLabel + ' = ' + formatRatio(ratio) + ' don vi nho nhat';
        } else {
            dom.cfgRatioPreview.textContent = '';
        }
    }

    function formatRatio(n) {
        if (Math.floor(n) === n) return String(Math.floor(n));
        return parseFloat(n.toFixed(3)).toString();
    }

    function formatPrice(n) {
        if (isNaN(n)) return '—';
        return n.toLocaleString('vi-VN');
    }

    function saveMapping() {
        var sku = dom.cfgSku ? dom.cfgSku.value : '';
        if (!sku) { toast('Vui long chon san pham truoc.', 'error'); return; }

        var id       = parseInt(dom.cfgMappingId ? dom.cfgMappingId.value : '0', 10);
        var unit     = (dom.cfgConvertUnit     ? dom.cfgConvertUnit.value     : '').trim();
        var toHtsoft = (dom.cfgConvertToHtsoft ? dom.cfgConvertToHtsoft.value : '').trim();
        var note     = (dom.cfgNote            ? dom.cfgNote.value            : '').trim();

        if (!unit && id === 0) { toast('Vui long nhap ten Don Vi Tinh.', 'error'); return; }
        if (!toHtsoft || parseFloat(toHtsoft.replace(',', '.')) <= 0) {
            toast('Ty le quy doi phai la so duong.', 'error');
            return;
        }

        if (dom.btnSaveMapping) dom.btnSaveMapping.disabled = true;

        postAjax('tgs_htsoft_converter_save_mapping', {
            id:                id,
            global_product_sku: sku,
            convert_unit:       unit,
            convert_to_htsoft:  toHtsoft.replace(',', '.'),
            convert_note:       note,
        }).then(function (res) {
            if (res.success) {
                toast(res.data.message || 'Da luu.', 'success');
                resetUnitForm();
                loadConfigsForSku(sku);
                loadMappings(1);
            } else {
                toast((res.data && res.data.message) || 'Loi luu cau hinh.', 'error');
            }
        }).catch(function () { toast('Loi ket noi.', 'error'); })
          .finally(function () { if (dom.btnSaveMapping) dom.btnSaveMapping.disabled = false; });
    }

    function deleteConfig(id, unit) {
        var label = unit ? 'DVT "' + unit + '"' : 'cau hinh nay';
        if (!confirm('Xoa vinh vien ' + label + '? Khong the hoan tac.')) return;

        postAjax('tgs_htsoft_converter_delete_mapping', { id: id })
            .then(function (res) {
                if (res.success) {
                    toast((res.data && res.data.message) || 'Da xoa.', 'success');
                    var sku = dom.cfgSku ? dom.cfgSku.value : '';
                    if (sku) loadConfigsForSku(sku);
                    loadMappings(1);
                } else {
                    toast((res.data && res.data.message) || 'Loi xoa.', 'error');
                }
            })
            .catch(function () { toast('Loi ket noi.', 'error'); });
    }

    /* =========================================================================
     * Mapping Table (bottom, all configs)
     * ====================================================================== */
    function loadMappings(page) {
        if (page !== undefined) state.mappingPage = page;
        var kw = (dom.mappingKeyword ? dom.mappingKeyword.value : '').trim();

        if (dom.mappingTableBody) {
            dom.mappingTableBody.innerHTML =
                '<tr><td colspan="6" class="text-center text-muted py-3">'
                + '<span class="spinner-border spinner-border-sm me-2"></span>Đang tải...</td></tr>';
        }

        postAjax('tgs_htsoft_converter_list_mappings', {
            keyword:  kw,
            page:     state.mappingPage,
            per_page: state.mappingPerPage,
        })
            .then(function (res) {
                if (res.success) {
                    var mappings = res.data.mappings || [];
                    var total    = res.data.total    || 0;
                    var page     = res.data.page     || 1;
                    var perPage  = res.data.per_page || state.mappingPerPage;
                    state.mappingTotal   = total;
                    state.mappingPage    = page;
                    state.mappingPerPage = perPage;
                    renderMappingsTable(mappings);
                    renderMappingsPagination(total, page, perPage);
                    if (dom.mappingTableFooter) {
                        var from = mappings.length ? (page - 1) * perPage + 1 : 0;
                        var to   = (page - 1) * perPage + mappings.length;
                        dom.mappingTableFooter.textContent =
                            'Hiển thị ' + from + '–' + to + ' trong ' + total + ' cấu hình';
                    }
                } else {
                    if (dom.mappingTableBody) {
                        dom.mappingTableBody.innerHTML =
                            '<tr><td colspan="6" class="text-center text-danger">Lỗi tải dữ liệu.</td></tr>';
                    }
                }
            })
            .catch(function () {
                if (dom.mappingTableBody) {
                    dom.mappingTableBody.innerHTML =
                        '<tr><td colspan="6" class="text-center text-danger">Lỗi kết nối.</td></tr>';
                }
            });
    }

    function renderMappingsPagination(total, page, perPage) {
        if (!dom.mappingPagination) return;
        var totalPages = Math.ceil(total / perPage);
        if (totalPages <= 1) { dom.mappingPagination.innerHTML = ''; return; }

        var html = '<nav><ul class="pagination pagination-sm mb-0 flex-wrap">';

        // Nút Đầu + Trước
        html += '<li class="page-item' + (page <= 1 ? ' disabled' : '') + '">' +
            '<a class="page-link" href="#" data-pg="1" title="Trang đầu">&laquo;</a></li>';
        html += '<li class="page-item' + (page <= 1 ? ' disabled' : '') + '">' +
            '<a class="page-link" href="#" data-pg="' + (page - 1) + '">&lsaquo;</a></li>';

        // Số trang xung quanh trang hiện tại
        var WING = 2;
        var start = Math.max(1, page - WING);
        var end   = Math.min(totalPages, page + WING);

        if (start > 1) {
            if (start > 2) html += '<li class="page-item disabled"><span class="page-link">…</span></li>';
        }
        for (var p = start; p <= end; p++) {
            html += '<li class="page-item' + (p === page ? ' active' : '') + '">' +
                '<a class="page-link" href="#" data-pg="' + p + '">' + p + '</a></li>';
        }
        if (end < totalPages) {
            if (end < totalPages - 1) html += '<li class="page-item disabled"><span class="page-link">…</span></li>';
        }

        // Nút Sau + Cuối
        html += '<li class="page-item' + (page >= totalPages ? ' disabled' : '') + '">' +
            '<a class="page-link" href="#" data-pg="' + (page + 1) + '">&rsaquo;</a></li>';
        html += '<li class="page-item' + (page >= totalPages ? ' disabled' : '') + '">' +
            '<a class="page-link" href="#" data-pg="' + totalPages + '" title="Trang cuối">&raquo;</a></li>';

        html += '</ul></nav>';
        dom.mappingPagination.innerHTML = html;

        dom.mappingPagination.querySelectorAll('[data-pg]').forEach(function (a) {
            a.addEventListener('click', function (e) {
                e.preventDefault();
                var pg = parseInt(a.dataset.pg, 10);
                if (pg < 1 || pg > totalPages || pg === state.mappingPage) return;
                loadMappings(pg);
            });
        });
    }

    function renderMappingsTable(rows) {
        if (!dom.mappingTableBody) return;
        if (!rows.length) {
            dom.mappingTableBody.innerHTML = '<tr><td colspan="7" class="text-center text-muted">Khong co du lieu</td></tr>';
            return;
        }
        var html = '';
        rows.forEach(function (r) {
            var priceCell = (r.unit_price !== null && r.unit_price !== undefined && r.unit_price !== '')
                ? '<span class="text-success fw-semibold">' + formatPrice(parseFloat(r.unit_price)) + '</span>'
                : '<em class="text-muted small">—</em>';
            html += '<tr>' +
                '<td><code>' + escHtml(r.global_product_sku) + '</code></td>' +
                '<td>' + escHtml(r.local_product_name || '') + '</td>' +
                '<td>' + (r.convert_unit ? escHtml(r.convert_unit) : '<em class="text-muted">mac dinh</em>') + '</td>' +
                '<td>' + escHtml(formatRatio(parseFloat(r.convert_to_htsoft))) + '</td>' +
                '<td>' + priceCell + '</td>' +
                '<td class="text-muted small">' + escHtml(r.convert_note || '') + '</td>' +
                '<td class="text-nowrap">' +
                '<button class="btn btn-xs btn-outline-primary me-1" data-tbl-edit="' + r.global_htsoft_stock_convert_id + '">' +
                '<i class="bx bx-edit-alt"></i></button>' +
                '<button class="btn btn-xs btn-outline-danger" data-tbl-delete="' + r.global_htsoft_stock_convert_id + '" data-tbl-unit="' + escHtml(r.convert_unit || '') + '">' +
                '<i class="bx bx-trash"></i></button>' +
                '</td></tr>';
        });
        dom.mappingTableBody.innerHTML = html;

        dom.mappingTableBody.querySelectorAll('[data-tbl-edit]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                editMappingFromTable(parseInt(btn.dataset.tblEdit, 10));
            });
        });
        dom.mappingTableBody.querySelectorAll('[data-tbl-delete]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                deleteMappingFromTable(parseInt(btn.dataset.tblDelete, 10), btn.dataset.tblUnit);
            });
        });
    }

    function editMappingFromTable(id) {
        postAjax('tgs_htsoft_converter_get_mapping', { id: id })
            .then(function (res) {
                if (!res.success || !res.data.mapping) {
                    toast('Khong tim thay cau hinh.', 'error');
                    return;
                }
                var m = res.data.mapping;
                selectProduct({
                    local_product_sku:  m.global_product_sku,
                    local_product_name: m.local_product_name || m.global_product_sku,
                    local_product_unit: m.local_product_unit || '',
                    config_count:       0,
                });
                setTimeout(function () { editConfig(m); }, 150);
                if (dom.cfgContent) dom.cfgContent.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            })
            .catch(function () { toast('Loi ket noi.', 'error'); });
    }

    function deleteMappingFromTable(id, unit) {
        deleteConfig(id, unit);
    }

    /* =========================================================================
     * Excel Export / Import
     * ====================================================================== */
    function exportExcel() {
        postAjax('tgs_htsoft_converter_export_excel_rows', {})
            .then(function (res) {
                if (!res.success) { toast('Loi xuat du lieu.', 'error'); return; }
                var rows = res.data.rows || [];
                if (!rows.length) { toast('Khong co du lieu de xuat.', 'error'); return; }

                var data = [['Ma hang', 'Ten hang', 'Don vi tinh', 'Ty le quy doi', 'Ghi chu']];
                rows.forEach(function (r) {
                    data.push([
                        r.global_product_sku,
                        r.local_product_name || '',
                        r.convert_unit || '',
                        r.convert_to_htsoft,
                        r.convert_note || '',
                    ]);
                });

                var ws = XLSX.utils.aoa_to_sheet(data);
                var wb = XLSX.utils.book_new();
                XLSX.utils.book_append_sheet(wb, ws, 'Quy doi DVT');
                XLSX.writeFile(wb, 'htsoft-stock-convert.xlsx');
                toast('Da xuat file Excel.', 'success');
            })
            .catch(function () { toast('Loi ket noi.', 'error'); });
    }

    /* =========================================================================
     * Excel Import — Batch (hỗ trợ 20k+ dòng)
     * ====================================================================== */
    var IMPORT_BATCH_SIZE = 200;

    var _eiModal = null;
    var _eiDom   = null;
    var _eiState = { shouldStop: false };

    function getEiModal() {
        if (!_eiModal) {
            var el = document.getElementById('excelImportModal');
            if (el) _eiModal = new bootstrap.Modal(el, { backdrop: 'static', keyboard: false });
        }
        return _eiModal;
    }

    function getEiDom() {
        if (_eiDom && _eiDom.progressBar) return _eiDom;
        _eiDom = {
            fileInfo:      document.getElementById('eiFileInfo'),
            fileName:      document.getElementById('eiFileName'),
            rowCount:      document.getElementById('eiRowCount'),
            progressWrap:  document.getElementById('eiProgressWrap'),
            progressBar:   document.getElementById('eiProgressBar'),
            progressText:  document.getElementById('eiProgressText'),
            progressPct:   document.getElementById('eiProgressPct'),
            statCreated:   document.getElementById('eiStatCreated'),
            statUpdated:   document.getElementById('eiStatUpdated'),
            statSkipped:   document.getElementById('eiStatSkipped'),
            statErrors:    document.getElementById('eiStatErrors'),
            statBatch:     document.getElementById('eiStatBatch'),
            resultWrap:    document.getElementById('eiResultWrap'),
            resultDone:    document.getElementById('eiResultDone'),
            resultStopped: document.getElementById('eiResultStopped'),
            errorsList:    document.getElementById('eiErrorsList'),
            errorsUl:      document.getElementById('eiErrorsUl'),
            stopBtn:       document.getElementById('eiStopBtn'),
            closeBtn:      document.getElementById('eiCloseBtn'),
        };
        if (_eiDom.stopBtn) {
            _eiDom.stopBtn.addEventListener('click', function () {
                _eiState.shouldStop = true;
                _eiDom.stopBtn.disabled = true;
                _eiDom.stopBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Đang dừng...';
            });
        }
        if (_eiDom.closeBtn) {
            _eiDom.closeBtn.addEventListener('click', function () {
                var m = getEiModal();
                if (m) m.hide();
            });
        }
        return _eiDom;
    }

    function importExcel(file) {
        if (!file) return;
        var reader = new FileReader();
        reader.onload = function (e) {
            try {
                var wb   = XLSX.read(e.target.result, { type: 'array' });
                var ws   = wb.Sheets[wb.SheetNames[0]];
                var data = XLSX.utils.sheet_to_json(ws, { header: 1 });

                if (data.length < 2) { toast('File Excel trong hoac thieu du lieu.', 'error'); return; }

                var rows = [];
                for (var i = 1; i < data.length; i++) {
                    var row   = data[i];
                    var sku   = String(row[0] || '').trim();
                    var unit  = String(row[2] || '').trim();
                    var ratio = parseFloat(String(row[3] || '').replace(',', '.'));
                    var note  = String(row[4] || '').trim();
                    if (!sku || !ratio || ratio <= 0) continue;
                    rows.push({ global_product_sku: sku, convert_unit: unit, convert_to_htsoft: ratio, convert_note: note });
                }

                if (!rows.length) { toast('Khong co dong du lieu hop le trong file.', 'error'); return; }

                openBatchImportModal(file.name, rows);

            } catch (err) {
                toast('Khong the doc file Excel: ' + err.message, 'error');
            }
        };
        reader.readAsArrayBuffer(file);
    }

    function openBatchImportModal(fileName, rows) {
        var d = getEiDom();
        _eiState.shouldStop = false;

        // Reset UI
        d.fileInfo.style.display      = '';
        d.fileName.textContent        = fileName;
        d.rowCount.textContent        = rows.length;
        d.progressWrap.style.display  = '';
        d.resultWrap.style.display    = 'none';
        d.resultDone.style.display    = 'none';
        d.resultStopped.style.display = 'none';
        d.errorsList.style.display    = 'none';
        d.errorsUl.innerHTML          = '';
        d.progressBar.style.width     = '0%';
        d.progressBar.classList.add('progress-bar-animated');
        d.progressText.textContent    = 'Chuẩn bị...';
        d.progressPct.textContent     = '0%';
        d.statCreated.textContent     = '0';
        d.statUpdated.textContent     = '0';
        d.statSkipped.textContent     = '0';
        d.statErrors.textContent      = '0';
        d.statBatch.textContent       = '';
        d.stopBtn.style.display       = '';
        d.stopBtn.disabled            = false;
        d.stopBtn.innerHTML           = '<i class="bx bx-stop-circle me-1"></i>Dừng lại';
        d.closeBtn.disabled           = true;

        var m = getEiModal();
        if (m) m.show();

        // Chờ modal render xong rồi mới chạy
        setTimeout(function () {
            runBatchImport(fileName, rows, d).then(function () {
                d.closeBtn.disabled = false;
            });
        }, 200);
    }

    function runBatchImport(fileName, rows, d) {
        var totalRows    = rows.length;
        var totalBatches = Math.ceil(totalRows / IMPORT_BATCH_SIZE);
        var created = 0, updated = 0, skipped = 0, errors = 0;
        var errorMessages = [];
        var processed = 0;

        function updateProgress() {
            var pct = totalRows > 0 ? Math.round((processed / totalRows) * 100) : 0;
            d.progressBar.style.width  = pct + '%';
            d.progressPct.textContent  = pct + '%';
            d.statCreated.textContent  = created;
            d.statUpdated.textContent  = updated;
            d.statSkipped.textContent  = skipped;
            d.statErrors.textContent   = errors;
        }

        // Xây chain Promise tuần tự cho từng batch
        var chain = Promise.resolve();
        for (var b = 0; b < totalBatches; b++) {
            (function (batchIdx) {
                chain = chain.then(function () {
                    if (_eiState.shouldStop) return Promise.resolve();

                    var start = batchIdx * IMPORT_BATCH_SIZE;
                    var end   = Math.min(start + IMPORT_BATCH_SIZE, totalRows);
                    var batch = rows.slice(start, end);

                    d.progressText.textContent = 'Đang xử lý dòng ' + (start + 1) + '–' + end + ' / ' + totalRows + '…';
                    d.statBatch.textContent    = 'Batch ' + (batchIdx + 1) + '/' + totalBatches;

                    return postAjax('tgs_htsoft_converter_import_excel_rows', {
                        rows_json: JSON.stringify(batch),
                    }).then(function (res) {
                        processed += batch.length;
                        if (res && res.success && res.data) {
                            created += res.data.created || 0;
                            updated += res.data.updated || 0;
                            skipped += res.data.skipped || 0;
                            if (Array.isArray(res.data.errors)) {
                                errors += res.data.errors.length;
                                errorMessages = errorMessages.concat(res.data.errors);
                            }
                        } else {
                            errors += batch.length;
                            errorMessages.push(
                                'Batch ' + (batchIdx + 1) + ': ' +
                                ((res && res.data && res.data.message) || 'Lỗi không xác định')
                            );
                        }
                        updateProgress();
                    }).catch(function () {
                        processed += batch.length;
                        errors += batch.length;
                        errorMessages.push('Batch ' + (batchIdx + 1) + ': lỗi kết nối.');
                        updateProgress();
                    });
                });
            })(b);
        }

        return chain.then(function () {
            // Hoàn tất
            d.progressBar.style.width = '100%';
            d.progressBar.classList.remove('progress-bar-animated');
            d.progressText.textContent = _eiState.shouldStop ? 'Đã dừng.' : 'Hoàn tất!';
            d.progressPct.textContent  = '100%';
            d.statBatch.textContent    = '';
            d.stopBtn.style.display    = 'none';
            d.resultWrap.style.display = '';

            if (_eiState.shouldStop) {
                d.resultStopped.style.display = '';
            } else {
                d.resultDone.style.display   = '';
                d.resultDone.innerHTML = '<i class="bx bx-check-circle me-1"></i>' +
                    'Import hoàn tất! ' +
                    'Tạo mới: <strong>' + created + '</strong>, ' +
                    'cập nhật: <strong>' + updated + '</strong>, ' +
                    'bỏ qua: <strong>' + skipped + '</strong>' +
                    (errors > 0 ? ', lỗi: <strong class="text-danger">' + errors + '</strong>' : '') + '.';
            }

            if (errorMessages.length > 0) {
                d.errorsList.style.display = '';
                var shown = errorMessages.slice(0, 30);
                shown.forEach(function (msg) {
                    var li = document.createElement('li');
                    li.textContent = msg;
                    d.errorsUl.appendChild(li);
                });
                if (errorMessages.length > 30) {
                    var li = document.createElement('li');
                    li.textContent = '... và ' + (errorMessages.length - 30) + ' lỗi khác.';
                    d.errorsUl.appendChild(li);
                }
            }

            loadMappings(1);
        });
    }

    /* =========================================================================
     * Import Giá bán theo DVT từ Excel
     * ====================================================================== */

    var PRICE_BATCH_SIZE = 200;
    var _piModal = null;
    var _piDom   = null;
    var _piState = null;

    function getPiModal() {
        if (!_piModal) _piModal = new bootstrap.Modal(document.getElementById('priceImportModal'));
        return _piModal;
    }

    function getPiDom() {
        if (!_piDom) {
            _piDom = {
                fileInfo:       document.getElementById('piFileInfo'),
                fileName:       document.getElementById('piFileName'),
                rowCount:       document.getElementById('piRowCount'),
                progressWrap:   document.getElementById('piProgressWrap'),
                progressBar:    document.getElementById('piProgressBar'),
                progressText:   document.getElementById('piProgressText'),
                progressPct:    document.getElementById('piProgressPct'),
                resultWrap:     document.getElementById('piResultWrap'),
                resultDone:     document.getElementById('piResultDone'),
                resultStopped:  document.getElementById('piResultStopped'),
                statUpdated:    document.getElementById('piStatUpdated'),
                statSkipped:    document.getElementById('piStatSkipped'),
                statBatch:      document.getElementById('piStatBatch'),
                errorsWrap:     document.getElementById('piErrorsWrap'),
                errorsUl:       document.getElementById('piErrorsUl'),
                stopBtn:        document.getElementById('piStopBtn'),
                closeBtn:       document.getElementById('piCloseBtn'),
            };
        }
        return _piDom;
    }

    function importPriceExcel(file) {
        var reader = new FileReader();
        reader.onload = function (e) {
            var data;
            try {
                var wb = XLSX.read(new Uint8Array(e.target.result), { type: 'array' });
                var ws = wb.Sheets[wb.SheetNames[0]];
                data   = XLSX.utils.sheet_to_json(ws, { header: 1, defval: '' });
            } catch (err) {
                toast('Không đọc được file Excel: ' + err.message, 'error');
                return;
            }
            // Bỏ dòng tiêu đề (dòng 0), lọc dòng có SKU
            var rows = [];
            for (var i = 1; i < data.length; i++) {
                var row = data[i];
                var sku  = (row[0] !== undefined && row[0] !== null) ? String(row[0]).trim() : '';
                var unit = (row[2] !== undefined && row[2] !== null) ? String(row[2]).trim() : '';
                var raw  = (row[3] !== undefined && row[3] !== null) ? row[3] : '';
                if (!sku) continue;
                // Loại bỏ dấu chấm phẩy, khoảng trắng, đổi dấu phẩy thành dấu chấm
                var priceStr = String(raw).replace(/\s/g, '').replace(/\./g, '').replace(',', '.');
                var price    = priceStr !== '' ? parseFloat(priceStr) : null;
                rows.push({ sku: sku, unit: unit, price: (price > 0 ? price : null) });
            }
            if (!rows.length) {
                toast('File không có dữ liệu hợp lệ (cần ít nhất 1 dòng có SKU).', 'error');
                return;
            }
            openPriceImportModal(file.name, rows);
        };
        reader.readAsArrayBuffer(file);
    }

    function openPriceImportModal(fileName, rows) {
        var d = getPiDom();

        // Reset UI
        d.fileInfo.classList.remove('d-none');
        d.fileName.textContent  = fileName;
        d.rowCount.textContent  = rows.length;

        d.progressWrap.classList.remove('d-none');
        d.progressBar.style.width = '0%';
        d.progressText.textContent = 'Đang chuẩn bị…';
        d.progressPct.textContent  = '0%';

        d.resultWrap.classList.add('d-none');
        d.resultDone.classList.add('d-none');
        d.resultStopped.classList.add('d-none');
        d.statUpdated.textContent = '0';
        d.statSkipped.textContent = '0';
        d.statBatch.textContent   = '0/' + Math.ceil(rows.length / PRICE_BATCH_SIZE);
        d.errorsWrap.classList.add('d-none');
        d.errorsUl.innerHTML = '';

        d.stopBtn.disabled  = false;
        d.closeBtn.disabled = true;

        _piState = { stopped: false, updated: 0, skipped: 0, errors: [], batchDone: 0 };

        d.stopBtn.onclick = function () {
            _piState.stopped = true;
            d.stopBtn.disabled = true;
        };
        d.closeBtn.onclick = function () { getPiModal().hide(); loadMappings(1); };

        getPiModal().show();
        runPriceImport(fileName, rows, d);
    }

    function runPriceImport(fileName, rows, d) {
        var totalBatches = Math.ceil(rows.length / PRICE_BATCH_SIZE);
        var totalRows    = rows.length;

        function processBatch(batchIdx) {
            if (_piState.stopped) {
                finishPriceImport(d, true, totalBatches);
                return;
            }
            var start = batchIdx * PRICE_BATCH_SIZE;
            if (start >= totalRows) {
                finishPriceImport(d, false, totalBatches);
                return;
            }
            var batch = rows.slice(start, start + PRICE_BATCH_SIZE);

            d.progressText.textContent = 'Batch ' + (batchIdx + 1) + '/' + totalBatches + '…';
            var pct = Math.round((start / totalRows) * 100);
            d.progressBar.style.width = pct + '%';
            d.progressPct.textContent = pct + '%';
            d.statBatch.textContent   = (batchIdx + 1) + '/' + totalBatches;

            postAjax('tgs_htsoft_converter_import_price_rows', {
                rows_json: JSON.stringify(batch),
            }).then(function (res) {
                if (res.success) {
                    _piState.updated += (res.data.updated || 0);
                    _piState.skipped += (res.data.skipped || 0);
                    var errs = res.data.errors || [];
                    if (errs.length) {
                        _piState.errors = _piState.errors.concat(errs);
                    }
                } else {
                    _piState.skipped += batch.length;
                    _piState.errors.push('Batch ' + (batchIdx + 1) + ': ' + (res.data && res.data.message ? res.data.message : 'lỗi không xác định'));
                }
                _piState.batchDone++;
                d.statUpdated.textContent = _piState.updated;
                d.statSkipped.textContent = _piState.skipped;
                processBatch(batchIdx + 1);
            }).catch(function (err) {
                _piState.skipped += batch.length;
                _piState.errors.push('Batch ' + (batchIdx + 1) + ': lỗi kết nối.');
                _piState.batchDone++;
                processBatch(batchIdx + 1);
            });
        }

        processBatch(0);
    }

    function finishPriceImport(d, stopped, totalBatches) {
        d.progressBar.style.width = '100%';
        d.progressPct.textContent = '100%';
        d.progressText.textContent = stopped ? 'Đã dừng.' : 'Hoàn thành.';
        d.progressBar.classList.remove('progress-bar-animated');

        d.resultWrap.classList.remove('d-none');
        if (stopped) {
            d.resultStopped.classList.remove('d-none');
        } else {
            d.resultDone.classList.remove('d-none');
            d.resultDone.innerHTML = '<i class="bx bx-check-circle me-1"></i>Import giá xong!';
        }

        d.statUpdated.textContent = _piState.updated;
        d.statSkipped.textContent = _piState.skipped;
        d.statBatch.textContent   = _piState.batchDone + '/' + totalBatches;

        if (_piState.errors.length) {
            d.errorsWrap.classList.remove('d-none');
            var limit = 30;
            _piState.errors.slice(0, limit).forEach(function (msg) {
                var li = document.createElement('li');
                li.textContent = msg;
                d.errorsUl.appendChild(li);
            });
            if (_piState.errors.length > limit) {
                var li = document.createElement('li');
                li.textContent = '... và ' + (_piState.errors.length - limit) + ' lỗi khác.';
                d.errorsUl.appendChild(li);
            }
        }

        d.stopBtn.disabled  = true;
        d.closeBtn.disabled = false;
    }

    /* =========================================================================
     * JSON Export / Import (backward compat)
     * ====================================================================== */
    function exportMappingsJson() {
        postAjax('tgs_htsoft_converter_export_mappings_json', {})
            .then(function (res) {
                if (!res.success) { toast('Loi xuat JSON.', 'error'); return; }
                var blob = new Blob([JSON.stringify(res.data, null, 2)], { type: 'application/json' });
                var url  = URL.createObjectURL(blob);
                var a    = document.createElement('a');
                a.href     = url;
                a.download = 'htsoft-stock-mappings.json';
                a.click();
                URL.revokeObjectURL(url);
                toast('Da xuat JSON.', 'success');
            })
            .catch(function () { toast('Loi ket noi.', 'error'); });
    }

    function importMappingsJson(file) {
        if (!file) return;
        var reader = new FileReader();
        reader.onload = function (e) {
            try {
                var json = JSON.parse(e.target.result);
                postAjax('tgs_htsoft_converter_import_mappings_json', { mappings_json: JSON.stringify(json) })
                    .then(function (res) {
                        if (res.success) {
                            toast((res.data && res.data.message) || 'Import JSON xong.', 'success');
                            loadMappings(1);
                        } else {
                            toast((res.data && res.data.message) || 'Loi import JSON.', 'error');
                        }
                    }).catch(function () { toast('Loi ket noi.', 'error'); });
            } catch (err) {
                toast('File JSON khong hop le: ' + err.message, 'error');
            }
        };
        reader.readAsText(file);
    }

    /* =========================================================================
     * Barcode Scanner
     * ====================================================================== */
    function initScanner() {
        if (!dom.btnOpenScanner || !dom.scannerWrap) return;
        dom.btnOpenScanner.addEventListener('click', openScanner);
        if (dom.btnCloseScanner) dom.btnCloseScanner.addEventListener('click', closeScanner);
    }

    function openScanner() {
        if (dom.scannerWrap)    dom.scannerWrap.style.display    = '';
        if (dom.btnOpenScanner) dom.btnOpenScanner.style.display = 'none';

        if (window.TGSBarcodeScanner) {
            state.scanner = new TGSBarcodeScanner({
                videoElementId: 'cfgScannerVideo',
                onDecode: function (code) {
                    var now = Date.now();
                    if (now < state.scannerCooldownAt) return;
                    state.scannerCooldownAt = now + 1500;
                    if (dom.cfgSearchKeyword) dom.cfgSearchKeyword.value = code;
                    searchProducts();
                },
                onError: function (err) { console.warn('Scanner error:', err); },
            });
            state.scanner.start().catch(function (err) {
                console.warn('Cannot start scanner:', err);
                closeScanner();
            });
        } else if (window.ZXing) {
            startZxingScanner();
        }
    }

    function closeScanner() {
        if (state.scanner && state.scanner.stop) state.scanner.stop();
        state.scanner = null;
        if (dom.scannerWrap)    dom.scannerWrap.style.display    = 'none';
        if (dom.btnOpenScanner) dom.btnOpenScanner.style.display = '';
    }

    function startZxingScanner() {
        var videoEl = document.getElementById('cfgScannerVideo');
        if (!videoEl) return;
        var codeReader = new ZXing.BrowserMultiFormatReader(new Map());
        state.scanner = { stop: function () { codeReader.reset(); } };
        codeReader.decodeFromConstraints(
            { video: { facingMode: 'environment' } },
            videoEl,
            function (result) {
                if (!result) return;
                var now = Date.now();
                if (now < state.scannerCooldownAt) return;
                state.scannerCooldownAt = now + 1500;
                if (dom.cfgSearchKeyword) dom.cfgSearchKeyword.value = result.getText();
                searchProducts();
                closeScanner();
            }
        ).catch(function (err) {
            console.warn('ZXing error:', err);
            closeScanner();
        });
    }

    /* =========================================================================
     * Event bindings
     * ====================================================================== */
    function bindEvents() {
        if (dom.cfgSearchKeyword) {
            dom.cfgSearchKeyword.addEventListener('input', function () {
                clearTimeout(state.searchTimer);
                state.searchTimer = setTimeout(searchProducts, 350);
            });
            dom.cfgSearchKeyword.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') { clearTimeout(state.searchTimer); searchProducts(); }
            });
        }

        if (dom.cfgConvertUnit)     dom.cfgConvertUnit.addEventListener('input', updateRatioPreview);
        if (dom.cfgConvertToHtsoft) dom.cfgConvertToHtsoft.addEventListener('input', updateRatioPreview);

        if (dom.btnSaveMapping)      dom.btnSaveMapping.addEventListener('click', saveMapping);
        if (dom.btnResetUnitForm)     dom.btnResetUnitForm.addEventListener('click', function () { resetUnitForm(); });
        if (dom.btnRefreshConfigs)    dom.btnRefreshConfigs.addEventListener('click', function () {
            var sku = dom.cfgSku ? dom.cfgSku.value : '';
            if (sku) loadConfigsForSku(sku);
        });

        if (dom.btnReloadMappings) dom.btnReloadMappings.addEventListener('click', function () { loadMappings(1); });
        if (dom.mappingKeyword) {
            dom.mappingKeyword.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') loadMappings(1);
            });
            dom.mappingKeyword.addEventListener('input', function () {
                clearTimeout(state.mappingSearchTimer);
                state.mappingSearchTimer = setTimeout(function () { loadMappings(1); }, 500);
            });
        }

        if (dom.btnExportExcel)   dom.btnExportExcel.addEventListener('click', exportExcel);
        if (dom.btnImportExcel)   dom.btnImportExcel.addEventListener('click', function () {
            if (dom.excelImportFile) dom.excelImportFile.click();
        });
        if (dom.excelImportFile)  dom.excelImportFile.addEventListener('change', function (e) {
            var file = e.target.files && e.target.files[0];
            if (file) { importExcel(file); e.target.value = ''; }
        });

        if (dom.btnExportMappingsJson) dom.btnExportMappingsJson.addEventListener('click', exportMappingsJson);
        if (dom.btnImportMappingsJson) dom.btnImportMappingsJson.addEventListener('click', function () {
            if (dom.mappingJsonFile) dom.mappingJsonFile.click();
        });
        if (dom.mappingJsonFile) dom.mappingJsonFile.addEventListener('change', function (e) {
            var file = e.target.files && e.target.files[0];
            if (file) { importMappingsJson(file); e.target.value = ''; }
        });

        if (dom.btnImportPriceExcel) dom.btnImportPriceExcel.addEventListener('click', function () {
            if (dom.priceImportFile) dom.priceImportFile.click();
        });
        if (dom.priceImportFile) dom.priceImportFile.addEventListener('change', function (e) {
            var file = e.target.files && e.target.files[0];
            if (file) { importPriceExcel(file); e.target.value = ''; }
        });
    }

    /* =========================================================================
     * Init
     * ====================================================================== */
    function init() {
        bindEvents();
        initScanner();
        loadMappings();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
