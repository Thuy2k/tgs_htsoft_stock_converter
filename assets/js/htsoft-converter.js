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
        mappingPerPage:     100,
        mappingTotal:       0,
        mappingShowAll:     false,
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
        cfgUnitPrice:          el('cfgUnitPrice'),
        cfgUnitWeightKg:       el('cfgUnitWeightKg'),
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
            '<th>Don vi tinh</th><th>Ty le</th><th>Gia ban</th><th>Kg/1 DVT</th><th>Ghi chu</th><th></th>' +
            '</tr></thead><tbody>';
        configs.forEach(function (c) {
            var priceCell = (c.unit_price !== null && c.unit_price !== undefined && c.unit_price !== '')
                ? '<span class="text-success">' + formatPrice(parseFloat(c.unit_price)) + '</span>'
                : '<em class="text-muted">—</em>';
            var weightCell = (c.unit_weight_kg !== null && c.unit_weight_kg !== undefined && c.unit_weight_kg !== '')
                ? '<span class="text-info">' + formatWeight(parseFloat(c.unit_weight_kg)) + ' kg</span>'
                : '<em class="text-muted">—</em>';
            html += '<tr>' +
                '<td>' + (c.convert_unit ? escHtml(c.convert_unit) : '<em class="text-muted">mac dinh</em>') + '</td>' +
                '<td>' + escHtml(formatRatio(parseFloat(c.convert_to_htsoft))) + '</td>' +
                '<td>' + priceCell + '</td>' +
                '<td>' + weightCell + '</td>' +
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
        if (dom.cfgUnitPrice)       dom.cfgUnitPrice.value        = (c.unit_price !== null && c.unit_price !== undefined && c.unit_price !== '') ? c.unit_price : '';
        if (dom.cfgUnitWeightKg)    dom.cfgUnitWeightKg.value     = (c.unit_weight_kg !== null && c.unit_weight_kg !== undefined && c.unit_weight_kg !== '') ? c.unit_weight_kg : '';
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
        if (dom.cfgUnitPrice)       dom.cfgUnitPrice.value        = '';
        if (dom.cfgUnitWeightKg)    dom.cfgUnitWeightKg.value     = '';
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

    function formatWeight(n) {
        if (isNaN(n)) return '—';
        return parseFloat(n.toFixed(3)).toString();
    }

    function saveMapping() {
        var sku = dom.cfgSku ? dom.cfgSku.value : '';
        if (!sku) { toast('Vui long chon san pham truoc.', 'error'); return; }

        var id       = parseInt(dom.cfgMappingId ? dom.cfgMappingId.value : '0', 10);
        var unit     = (dom.cfgConvertUnit     ? dom.cfgConvertUnit.value     : '').trim();
        var toHtsoft = (dom.cfgConvertToHtsoft ? dom.cfgConvertToHtsoft.value : '').trim();
        var note     = (dom.cfgNote            ? dom.cfgNote.value            : '').trim();
        var rawPrice = (dom.cfgUnitPrice       ? dom.cfgUnitPrice.value       : '').trim();
        var rawWeight = (dom.cfgUnitWeightKg   ? dom.cfgUnitWeightKg.value    : '').trim();

        if (!unit && id === 0) { toast('Vui long nhap ten Don Vi Tinh.', 'error'); return; }
        if (!toHtsoft || parseFloat(toHtsoft.replace(',', '.')) <= 0) {
            toast('Ty le quy doi phai la so duong.', 'error');
            return;
        }

        if (dom.btnSaveMapping) dom.btnSaveMapping.disabled = true;

        postAjax('tgs_htsoft_converter_save_mapping', {
            id:                 id,
            global_product_sku: sku,
            convert_unit:       unit,
            convert_to_htsoft:  toHtsoft.replace(',', '.'),
            convert_note:       note,
            unit_price:         rawPrice,
            unit_weight_kg:     rawWeight.replace(',', '.'),
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
    function loadMappings() {
        var kw      = (dom.mappingKeyword ? dom.mappingKeyword.value : '').trim();
        var sendAll = state.mappingShowAll || kw !== '';

        if (dom.mappingTableBody) {
            dom.mappingTableBody.innerHTML =
                '<tr><td colspan="8" class="text-center text-muted py-3">'
                + '<span class="spinner-border spinner-border-sm me-2"></span>Đang tải...</td></tr>';
        }

        postAjax('tgs_htsoft_converter_list_mappings', {
            keyword:  kw,
            show_all: sendAll ? 1 : 0,
        })
            .then(function (res) {
                if (res.success) {
                    var mappings = res.data.mappings || [];
                    var hasMore  = !!res.data.has_more;
                    state.mappingTotal = mappings.length;
                    renderMappingsTable(mappings);
                    renderMappingsPagination(hasMore);
                    if (dom.mappingTableFooter) {
                        dom.mappingTableFooter.textContent = mappings.length + ' cấu hình';
                    }
                } else {
                    if (dom.mappingTableBody) {
                        dom.mappingTableBody.innerHTML =
                            '<tr><td colspan="8" class="text-center text-danger">Lỗi tải dữ liệu.</td></tr>';
                    }
                }
            })
            .catch(function () {
                if (dom.mappingTableBody) {
                    dom.mappingTableBody.innerHTML =
                        '<tr><td colspan="8" class="text-center text-danger">Lỗi kết nối.</td></tr>';
                }
            });
    }

    function renderMappingsPagination(hasMore) {
        if (!dom.mappingPagination) return;
        dom.mappingPagination.innerHTML = '';
        if (!hasMore) return;

        var btn = document.createElement('button');
        btn.type      = 'button';
        btn.className = 'btn btn-sm btn-outline-primary';
        btn.innerHTML = '<i class="bx bx-list-ul me-1"></i>Xem tất cả';
        btn.addEventListener('click', function () {
            state.mappingShowAll = true;
            loadMappings();
        });
        dom.mappingPagination.appendChild(btn);
    }

    function renderMappingsTable(rows) {
        if (!dom.mappingTableBody) return;
        if (!rows.length) {
            dom.mappingTableBody.innerHTML = '<tr><td colspan="8" class="text-center text-muted">Khong co du lieu</td></tr>';
            return;
        }
        var html = '';
        rows.forEach(function (r) {
            var priceCell = (r.unit_price !== null && r.unit_price !== undefined && r.unit_price !== '')
                ? '<span class="text-success fw-semibold">' + formatPrice(parseFloat(r.unit_price)) + '</span>'
                : '<em class="text-muted small">—</em>';
            var weightCell = (r.unit_weight_kg !== null && r.unit_weight_kg !== undefined && r.unit_weight_kg !== '')
                ? '<span class="text-info fw-semibold">' + formatWeight(parseFloat(r.unit_weight_kg)) + ' kg</span>'
                : '<em class="text-muted small">—</em>';
            html += '<tr>' +
                '<td><code>' + escHtml(r.global_product_sku) + '</code></td>' +
                '<td>' + escHtml(r.local_product_name || '') + '</td>' +
                '<td>' + (r.convert_unit ? escHtml(r.convert_unit) : '<em class="text-muted">mac dinh</em>') + '</td>' +
                '<td>' + escHtml(formatRatio(parseFloat(r.convert_to_htsoft))) + '</td>' +
                '<td>' + priceCell + '</td>' +
                '<td>' + weightCell + '</td>' +
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

                var data = [['Ma hang', 'Ten hang', 'Don vi tinh', 'Ty le quy doi', 'Gia ban (VND)', 'Khoi luong kg/1 DVT', 'Ghi chu']];
                rows.forEach(function (r) {
                    var price = (r.unit_price !== null && r.unit_price !== undefined && r.unit_price !== '') ? r.unit_price : '';
                    var weight = (r.unit_weight_kg !== null && r.unit_weight_kg !== undefined && r.unit_weight_kg !== '') ? r.unit_weight_kg : '';
                    data.push([
                        r.global_product_sku,
                        r.local_product_name || '',
                        r.convert_unit || '',
                        r.convert_to_htsoft,
                        price,
                        weight,
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
                    var rawPrice = String(row[4] || '').trim();
                    var rawCol5 = String(row[5] || '').trim();
                    var rawCol6 = String(row[6] || '').trim();
                    var weight = '';
                    var note = '';
                    if (rawCol6 !== '') {
                        weight = rawCol5;
                        note = rawCol6;
                    } else if (rawCol5 !== '') {
                        var col5Number = parseFloat(rawCol5.replace(',', '.'));
                        if (!isNaN(col5Number)) {
                            weight = rawCol5;
                        } else {
                            note = rawCol5;
                        }
                    } else {
                        note = rawPrice;
                        rawPrice = '';
                    }
                    if (!sku || !ratio || ratio <= 0) continue;
                    rows.push({
                        global_product_sku: sku,
                        convert_unit: unit,
                        convert_to_htsoft: ratio,
                        unit_price: rawPrice,
                        unit_weight_kg: weight,
                        convert_note: note
                    });
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
                statNoChange:   document.getElementById('piStatNoChange'),
                statSkipped:    document.getElementById('piStatSkipped'),
                statBatch:      document.getElementById('piStatBatch'),
                detailsWrap:    document.getElementById('piDetailsWrap'),
                detailsBody:    document.getElementById('piDetailsBody'),
                detailsBodyContent: document.getElementById('piDetailsBodyContent'),
                toggleDetails:  document.getElementById('piToggleDetails'),
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
                var priceStr = String(raw).replace(/\s/g, '').replace(/\./g, '').replace(',', '.');
                var price    = priceStr !== '' ? parseFloat(priceStr) : null;
                rows.push({ sku: sku, unit: unit, price: (price > 0 ? price : null) });
            }
            // Sắp xếp theo SKU để các dòng cùng mã hàng gần nhau
            rows.sort(function (a, b) {
                if (a.sku < b.sku) return -1;
                if (a.sku > b.sku) return 1;
                if (a.price !== null && b.price === null) return -1;
                if (a.price === null && b.price !== null) return 1;
                return 0;
            });
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
        d.statNoChange.textContent = '0';
        d.statSkipped.textContent = '0';
        d.statBatch.textContent   = '0/' + Math.ceil(rows.length / PRICE_BATCH_SIZE);
        d.detailsWrap.classList.add('d-none');
        d.detailsBody.classList.add('d-none');
        d.detailsBodyContent.innerHTML = '';
        d.errorsWrap.classList.add('d-none');
        d.errorsUl.innerHTML = '';

        d.stopBtn.disabled  = false;
        d.closeBtn.disabled = true;

        _piState = { stopped: false, updated: 0, no_change: 0, skipped: 0, details: [], errors: [], batchDone: 0 };

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
                    _piState.no_change += (res.data.no_change || 0);
                    _piState.skipped += (res.data.skipped || 0);
                    var dets = res.data.details || [];
                    if (dets.length) {
                        _piState.details = _piState.details.concat(dets);
                    }
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
                d.statNoChange.textContent = _piState.no_change;
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
            d.resultDone.innerHTML = '<i class="bx bx-check-circle me-1"></i>Import giá xong! ' +
                'Cập nhật: <strong>' + _piState.updated + '</strong>, ' +
                'Không đổi: <strong>' + _piState.no_change + '</strong>, ' +
                'Bỏ qua: <strong>' + _piState.skipped + '</strong>.';
        }

        d.statUpdated.textContent = _piState.updated;
        d.statNoChange.textContent = _piState.no_change;
        d.statSkipped.textContent = _piState.skipped;
        d.statBatch.textContent   = _piState.batchDone + '/' + totalBatches;

        // Hiển thị bảng chi tiết
        if (_piState.details.length > 0 || _piState.errors.length > 0) {
            d.detailsWrap.classList.remove('d-none');
            renderPriceDetails(d);
        }

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

        // Gán sự kiện toggle details
        if (d.toggleDetails) {
            // Xoá handler cũ để tránh chồng chéo
            var newToggle = d.toggleDetails.cloneNode(true);
            d.toggleDetails.parentNode.replaceChild(newToggle, d.toggleDetails);
            d.toggleDetails = newToggle;
            d.toggleDetails.addEventListener('click', function () {
                var isHidden = d.detailsBody.classList.contains('d-none');
                if (isHidden) {
                    d.detailsBody.classList.remove('d-none');
                    d.toggleDetails.innerHTML = '<i class="bx bx-hide"></i> Ẩn';
                } else {
                    d.detailsBody.classList.add('d-none');
                    d.toggleDetails.innerHTML = '<i class="bx bx-show"></i> Hiện';
                }
            });
        }
    }

    function renderPriceDetails(d) {
        var items = _piState.details || [];
        if (!d.detailsBodyContent) return;
        var html = '';
        items.forEach(function (item) {
            var statusBadge = '';
            if (item.status === 'updated') statusBadge = '<span class="badge bg-success">Đã cập nhật</span>';
            else if (item.status === 'no_change') statusBadge = '<span class="badge bg-secondary">Không đổi</span>';
            else statusBadge = '<span class="badge bg-warning text-dark">Bỏ qua</span>';

            var oldPrice = (item.old_price !== null && item.old_price !== undefined)
                ? formatPrice(parseFloat(item.old_price)) + '₫' : '—';
            var newPrice = (item.new_price !== null && item.new_price !== undefined)
                ? formatPrice(parseFloat(item.new_price)) + '₫' : '—';
            var note = item.note || '';

            html += '<tr>' +
                '<td><code>' + escHtml(item.sku) + '</code></td>' +
                '<td>' + escHtml(item.unit || '') + '</td>' +
                '<td>' + oldPrice + '</td>' +
                '<td>' + newPrice + '</td>' +
                '<td>' + statusBadge + '</td>' +
                '<td class="text-muted">' + escHtml(note) + '</td>' +
                '</tr>';
        });
        d.detailsBodyContent.innerHTML = html;
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
        // Không tự load — hiện placeholder, chờ user bấm "Xem tất cả"
        if (dom.mappingTableBody) {
            dom.mappingTableBody.innerHTML =
                '<tr><td colspan="7" class="text-center text-muted py-4">'
                + '<i class="bx bx-table me-1"></i>Bấm <strong>Xem tất cả</strong> để tải danh sách.'
                + '</td></tr>';
        }
        renderMappingsPagination(true);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
