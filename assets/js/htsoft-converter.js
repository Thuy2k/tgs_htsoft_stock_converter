/**
 * htsoft-converter.js  — v3 Modern UI (No Tabs)
 * Single page interface for multi-DVT config per SKU
 */
(function () {
    'use strict';

    const CFG = window.TGSHTSoftConverterConfig || {};

    /* =========================================================================
     * State
     * ====================================================================== */
    const state = {
        // ── Bảng giá / Bảng gốc đang mở ───────────────────────────────
        mode:               'pricelist', // 'pricelist' | 'base'
        priceListId:        0,      // 0 = đang ở màn hình danh sách bảng giá
        priceLists:         [],     // cache danh sách bảng giá + thống kê
        blogRows:           [],     // danh sách website cho modal "Áp dụng"

        scanner:            null,
        scannerCooldownAt:  0,
        searchTimer:        null,
        searchAbort:        null,   // AbortController của request tìm kiếm đang chạy
        searchReqId:        0,      // số thứ tự request → bỏ qua kết quả về trễ
        mappingSearchTimer: null,
        selectedProduct:    null,

        // ── Lưới "tải hết" (Excel-like) ───────────────────────────────
        mappingLoaded:     false,   // đã tải lần nào chưa
        mappingLoading:    false,   // đang có request chạy → chặn request chồng
        allRows:           [],      // TOÀN BỘ dòng của bảng gốc / bảng giá đang mở
    };

    /* =========================================================================
     * DOM refs
     * ====================================================================== */
    const el = (id) => document.getElementById(id);

    const dom = {
        cfgSearchKeyword:      el('cfgSearchKeyword'),
        cfgSearchResults:      el('cfgSearchResults'),
        btnCfgSearch:          el('btnCfgSearch'),
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
        cfgIsDefaultUnit:      el('cfgIsDefaultUnit'),
        cfgRatioPreview:       el('cfgRatioPreview'),
        cfgEditTitle:          el('cfgEditTitle'),
        cfgFormMode:           el('cfgFormMode'),
        btnSaveMapping:        el('btnSaveMapping'),
        btnResetUnitForm:      el('btnResetUnitForm'),

        mappingKeyword:        el('mappingKeyword'),
        btnReloadMappings:     el('btnReloadMappings'),
        mappingTableBody:      el('mappingTableBody'),
        mappingTableFooter:    el('mappingTableFooter'),
        mappingStaleNotice:    el('mappingStaleNotice'),
        btnReloadStale:        el('btnReloadStale'),
        btnRefreshConfigs:     el('btnRefreshConfigs'),

        btnImportExcel:        el('btnImportExcel'),
        excelImportFile:       el('excelImportFile'),
        btnExportExcel:        el('btnExportExcel'),

        btnImportPriceExcel:   el('btnImportPriceExcel'),
        priceImportFile:       el('priceImportFile'),

        btnUnifyDefaultUnit:   el('btnUnifyDefaultUnit'),
    };

    /** Cờ ĐVT bán chính từ server có thể là 1 / '1' / true */
    function isDefaultUnit(row) {
        return parseInt(row && row.is_default_unit, 10) === 1;
    }

    var DEFAULT_UNIT_BADGE =
        '<span class="badge bg-dark ms-1" title="ĐVT được POS ưu tiên khi tìm sản phẩm">' +
        '<i class="bx bx-star me-1"></i>ĐVT chính</span>';

    /* =========================================================================
     * AJAX helper
     * ====================================================================== */
    // Ở chế độ BASE, cùng một hành động UI nhưng gọi endpoint của Bảng gốc.
    const BASE_ACTION_MAP = {
        'tgs_htsoft_converter_search_products':      'tgs_htsoft_base_search_products',
        'tgs_htsoft_converter_list_configs_by_sku':  'tgs_htsoft_base_list_configs_by_sku',
        'tgs_htsoft_converter_save_mapping':         'tgs_htsoft_base_save_mapping',
        'tgs_htsoft_converter_delete_mapping':       'tgs_htsoft_base_delete_mapping',
        'tgs_htsoft_converter_set_default_unit':     'tgs_htsoft_base_set_default_unit',
        'tgs_htsoft_converter_list_mappings':        'tgs_htsoft_base_list_mappings',
        'tgs_htsoft_converter_get_mapping':          'tgs_htsoft_base_get_mapping',
        'tgs_htsoft_converter_export_excel_rows':    'tgs_htsoft_base_export_excel_rows',
        'tgs_htsoft_converter_import_excel_rows':    'tgs_htsoft_base_import_excel_rows',
        'tgs_htsoft_converter_default_scan_prepare': 'tgs_htsoft_base_default_scan_prepare',
        'tgs_htsoft_converter_default_scan_batch':   'tgs_htsoft_base_default_scan_batch',
        'tgs_htsoft_converter_list_all':             'tgs_htsoft_base_list_all',
    };

    function postAjax(action, payload, opts) {
        if (state.mode === 'base' && BASE_ACTION_MAP[action]) {
            action = BASE_ACTION_MAP[action];
        }
        const body = new URLSearchParams();
        body.append('action', action);
        body.append('nonce', CFG.nonce || '');
        for (const [k, v] of Object.entries(payload || {})) {
            body.append(k, v);
        }
        // Bảng giá: tự đính kèm price_list_id. Bảng gốc không có khái niệm này.
        if (state.mode !== 'base' && !body.has('price_list_id') && state.priceListId) {
            body.append('price_list_id', state.priceListId);
        }
        const init = { method: 'POST', body };
        if (opts && opts.signal) { init.signal = opts.signal; }
        return fetch(CFG.ajaxUrl, init).then((r) => r.json());
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
     * BẢNG GIÁ — màn hình danh sách + chuyển vào cấu hình
     * ====================================================================== */

    const plDom = {
        listSection:   el('plListSection'),
        detailSection: el('plDetailSection'),
        cards:         el('plCards'),
        btnNew:        el('btnNewPriceList'),
        btnBack:       el('btnBackToPriceLists'),
        btnEdit:       el('btnEditPriceList'),
        btnApplyBlogs: el('btnApplyBlogs'),
        currentName:   el('plCurrentName'),
        currentCode:   el('plCurrentCode'),
        currentStats:  el('plCurrentStats'),
        currentBlogs:  el('plCurrentBlogs'),

        formId:        el('plFormId'),
        formName:      el('plFormName'),
        formCode:      el('plFormCode'),
        formNote:      el('plFormNote'),
        formCopyWrap:  el('plFormCopyWrap'),
        formCopyFrom:  el('plFormCopyFrom'),
        formStatus:    el('plFormStatus'),
        formIsDefault: el('plFormIsDefault'),
        formSave:      el('plFormSave'),
    };

    var _plModal = null;
    function getPlModal() {
        if (!_plModal) {
            var m = document.getElementById('priceListModal');
            if (m) _plModal = new bootstrap.Modal(m);
        }
        return _plModal;
    }

    function findPriceList(id) {
        id = parseInt(id, 10);
        for (var i = 0; i < state.priceLists.length; i++) {
            if (parseInt(state.priceLists[i].global_htsoft_price_list_id, 10) === id) {
                return state.priceLists[i];
            }
        }
        return null;
    }

    function loadPriceLists(then) {
        // Không gửi kèm price_list_id của màn đang mở → dùng payload rỗng
        postAjax('tgs_htsoft_converter_list_price_lists', {})
            .then(function (res) {
                if (!res.success) {
                    plDom.cards.innerHTML = '<div class="col-12 text-danger text-center py-4">Lỗi tải danh sách bảng giá.</div>';
                    return;
                }
                state.priceLists = res.data.price_lists || [];
                renderPriceListCards();
                if (typeof then === 'function') then();
            })
            .catch(function () {
                plDom.cards.innerHTML = '<div class="col-12 text-danger text-center py-4">Lỗi kết nối.</div>';
            });
    }

    function renderPriceListCards() {
        if (!plDom.cards) return;

        if (!state.priceLists.length) {
            plDom.cards.innerHTML =
                '<div class="col-12 text-center text-muted py-5">'
                + '<div class="mb-2"><i class="bx bx-purchase-tag" style="font-size:2rem;"></i></div>'
                + 'Chưa có bảng giá nào. Bấm <strong>Tạo bảng giá</strong> để bắt đầu.'
                + '</div>';
            return;
        }

        var html = '';
        state.priceLists.forEach(function (p) {
            var id       = p.global_htsoft_price_list_id;
            var isDef    = parseInt(p.is_default, 10) === 1;
            var isOff    = parseInt(p.price_list_status, 10) !== 1;
            var blogText = (p.blog_names && p.blog_names.length)
                ? p.blog_names.map(escHtml).join(', ')
                : '<span class="text-muted">Chưa áp website nào</span>';

            html += '<div class="col-12 col-md-6 col-xl-4">' +
                '<div class="border rounded h-100 p-3 d-flex flex-column' + (isOff ? ' opacity-75' : '') + '">' +
                    '<div class="d-flex justify-content-between align-items-start gap-2 mb-2">' +
                        '<div>' +
                            '<div class="fw-semibold">' + escHtml(p.price_list_name) +
                                (isDef ? ' <span class="badge bg-dark ms-1">Mặc định</span>' : '') +
                                (isOff ? ' <span class="badge bg-secondary ms-1">Ngừng</span>' : '') +
                            '</div>' +
                            '<div class="small text-muted"><code>' + escHtml(p.price_list_code || '') + '</code></div>' +
                        '</div>' +
                    '</div>' +
                    '<div class="small text-muted mb-2">' + escHtml(p.price_list_note || '') + '</div>' +
                    '<div class="d-flex gap-3 small mb-2 flex-wrap">' +
                        '<span><strong>' + Number(p.sku_count || 0).toLocaleString('vi-VN') + '</strong> mã hàng</span>' +
                        '<span><strong>' + Number(p.config_count || 0).toLocaleString('vi-VN') + '</strong> dòng ĐVT</span>' +
                        '<span><strong>' + Number(p.default_unit_count || 0).toLocaleString('vi-VN') + '</strong> ĐVT chính</span>' +
                    '</div>' +
                    '<div class="small mb-3"><i class="bx bx-globe me-1"></i>' + blogText + '</div>' +
                    '<div class="mt-auto d-flex gap-2 flex-wrap">' +
                        '<button class="btn btn-sm btn-primary" data-pl-open="' + id + '">' +
                            '<i class="bx bx-cog me-1"></i>Quản lý ĐVT &amp; giá</button>' +
                        '<button class="btn btn-sm btn-outline-info" data-pl-blogs="' + id + '" title="Áp dụng cho website">' +
                            '<i class="bx bx-globe"></i></button>' +
                        '<button class="btn btn-sm btn-outline-secondary" data-pl-edit="' + id + '" title="Sửa">' +
                            '<i class="bx bx-edit-alt"></i></button>' +
                        (isDef ? '' :
                        '<button class="btn btn-sm btn-outline-danger" data-pl-delete="' + id + '" title="Xóa">' +
                            '<i class="bx bx-trash"></i></button>') +
                    '</div>' +
                '</div></div>';
        });

        plDom.cards.innerHTML = html;

        plDom.cards.querySelectorAll('[data-pl-open]').forEach(function (b) {
            b.addEventListener('click', function () { openPriceList(b.dataset.plOpen); });
        });
        plDom.cards.querySelectorAll('[data-pl-edit]').forEach(function (b) {
            b.addEventListener('click', function () { openPriceListForm(b.dataset.plEdit); });
        });
        plDom.cards.querySelectorAll('[data-pl-delete]').forEach(function (b) {
            b.addEventListener('click', function () { deletePriceList(b.dataset.plDelete); });
        });
        plDom.cards.querySelectorAll('[data-pl-blogs]').forEach(function (b) {
            b.addEventListener('click', function () { openApplyBlogsModal(b.dataset.plBlogs); });
        });
    }

    /** Bật/tắt các phần UI theo chế độ (base ẩn giá, pricelist khóa cấu trúc) */
    function applyModeUI() {
        var isBase = state.mode === 'base';
        var wrap   = plDom.detailSection;
        if (wrap) {
            wrap.classList.toggle('tgs-mode-base', isBase);
            wrap.classList.toggle('tgs-mode-pricelist', !isBase);
        }
        var show = function (id, on) { var e = el(id); if (e) e.style.display = on ? '' : 'none'; };
        // Bảng gốc
        show('btnImportExcel',        isBase);
        show('btnUnifyDefaultUnit',   isBase);
        show('btnBaseSyncAll',        isBase);
        // Bảng giá
        show('btnImportPriceExcel',   !isBase);
        show('btnResetDefaultToBase', !isBase);
        show('plDetailActions',       !isBase);
        show('cfgPriceWrap',          true);   // cả 2 chế độ đều có ô giá
        var head = el('mappingPriceHead');
        if (head) { head.style.display = ''; head.textContent = isBase ? 'Giá tham khảo' : 'Giá bán'; }
        document.querySelectorAll('.tgs-import-guide [data-mode]').forEach(function (g) {
            g.style.display = (g.getAttribute('data-mode') === (isBase ? 'base' : 'pricelist')) ? '' : 'none';
        });
        // Cấu trúc (ĐVT / tỷ lệ / khối lượng) chỉ sửa ở Bảng gốc
        [dom.cfgConvertUnit, dom.cfgConvertToHtsoft, dom.cfgUnitWeightKg].forEach(function (inp) {
            if (inp) inp.disabled = !isBase;
        });
        var priceLabel = document.querySelector('#cfgPriceWrap .form-label');
        if (priceLabel) priceLabel.textContent = isBase ? 'Giá tham khảo (VNĐ)' : 'Giá bán (VNĐ)';
        var hint = el('cfgPriceHint');
        if (hint) hint.textContent = isBase ? 'Dùng cho "Thống nhất ĐVT bán chính" + đồng bộ xuống bảng giá' : 'Giá riêng của bảng giá này';
    }

    /** Vào màn hình cấu hình của 1 bảng giá */
    function openPriceList(id) {
        var p = findPriceList(id);
        if (!p) return;

        state.mode        = 'pricelist';
        state.priceListId = parseInt(id, 10);

        // Reset toàn bộ trạng thái của bảng giá trước đó
        state.selectedProduct = null;
        state.mappingLoaded   = false;
        state.allRows         = [];
        if (dom.cfgSku)        dom.cfgSku.value = '';
        if (dom.cfgEmptyState) dom.cfgEmptyState.style.display = '';
        if (dom.cfgContent)    dom.cfgContent.style.display    = 'none';
        if (dom.cfgSearchKeyword) dom.cfgSearchKeyword.value   = '';
        if (dom.mappingKeyword)   dom.mappingKeyword.value     = '';
        renderSearchResults([]);
        hideStaleNotice();
        closeEditStrip();

        renderPriceListHeader(p);
        applyModeUI();

        plDom.listSection.style.display   = 'none';
        plDom.detailSection.style.display = '';
        window.scrollTo({ top: 0, behavior: 'smooth' });
        loadAllRows();
    }

    /** Vào màn hình Bảng gốc (khai báo tỉ lệ quy đổi) */
    function enterBaseMode() {
        state.mode        = 'base';
        state.priceListId = 0;

        state.selectedProduct = null;
        state.mappingLoaded   = false;
        state.allRows         = [];
        if (dom.cfgSku)        dom.cfgSku.value = '';
        if (dom.cfgEmptyState) dom.cfgEmptyState.style.display = '';
        if (dom.cfgContent)    dom.cfgContent.style.display    = 'none';
        if (dom.cfgSearchKeyword) dom.cfgSearchKeyword.value   = '';
        if (dom.mappingKeyword)   dom.mappingKeyword.value     = '';
        renderSearchResults([]);
        hideStaleNotice();
        closeEditStrip();

        plDom.currentName.textContent  = 'Khai báo tỉ lệ quy đổi (Bảng gốc)';
        plDom.currentCode.textContent  = 'BASE';
        plDom.currentStats.textContent = 'Nguồn cấu trúc ĐVT + tỉ lệ quy đổi cho MỌI bảng giá';

        applyModeUI();

        plDom.listSection.style.display   = 'none';
        plDom.detailSection.style.display = '';
        window.scrollTo({ top: 0, behavior: 'smooth' });
        loadAllRows();
    }

    function renderPriceListHeader(p) {
        if (!p) return;
        plDom.currentName.textContent = p.price_list_name;
        plDom.currentCode.textContent = p.price_list_code || '—';
        plDom.currentStats.textContent =
            Number(p.sku_count || 0).toLocaleString('vi-VN') + ' mã hàng · ' +
            Number(p.config_count || 0).toLocaleString('vi-VN') + ' dòng ĐVT · ' +
            Number(p.default_unit_count || 0).toLocaleString('vi-VN') + ' ĐVT chính';

        if (p.blog_names && p.blog_names.length) {
            plDom.currentBlogs.textContent = 'Áp dụng: ' + p.blog_names.join(', ');
            plDom.currentBlogs.className   = 'badge bg-info text-dark';
        } else {
            plDom.currentBlogs.textContent = 'Chưa áp website nào';
            plDom.currentBlogs.className   = 'badge bg-light text-dark';
        }
    }

    function backToPriceLists() {
        state.mode        = 'pricelist';
        state.priceListId = 0;
        plDom.detailSection.style.display = 'none';
        plDom.listSection.style.display   = '';
        loadPriceLists();
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    /** Mở form tạo (id rỗng) hoặc sửa bảng giá */
    function openPriceListForm(id) {
        var p = id ? findPriceList(id) : null;

        plDom.formId.value        = p ? p.global_htsoft_price_list_id : '0';
        plDom.formName.value      = p ? (p.price_list_name || '') : '';
        plDom.formCode.value      = p ? (p.price_list_code || '') : '';
        plDom.formNote.value      = p ? (p.price_list_note || '') : '';
        plDom.formStatus.checked  = p ? (parseInt(p.price_list_status, 10) === 1) : true;
        plDom.formIsDefault.checked = p ? (parseInt(p.is_default, 10) === 1) : false;

        // Chỉ cho sao chép khi TẠO MỚI
        plDom.formCopyWrap.style.display = p ? 'none' : '';
        var opts = '<option value="0">— Tạo bảng giá trống —</option>';
        state.priceLists.forEach(function (item) {
            opts += '<option value="' + item.global_htsoft_price_list_id + '">'
                + escHtml(item.price_list_name)
                + ' (' + Number(item.config_count || 0).toLocaleString('vi-VN') + ' dòng)'
                + '</option>';
        });
        plDom.formCopyFrom.innerHTML = opts;

        document.getElementById('priceListModalLabel').innerHTML = p
            ? '<i class="bx bx-edit-alt me-2 text-primary"></i>Sửa bảng giá'
            : '<i class="bx bx-purchase-tag me-2 text-primary"></i>Tạo bảng giá';

        var m = getPlModal();
        if (m) m.show();
    }

    function savePriceList() {
        var name = (plDom.formName.value || '').trim();
        if (!name) { toast('Vui lòng nhập tên bảng giá.', 'error'); return; }

        var id = parseInt(plDom.formId.value, 10) || 0;

        plDom.formSave.disabled = true;
        postAjax('tgs_htsoft_converter_save_price_list', {
            id:                id,
            price_list_name:   name,
            price_list_code:   (plDom.formCode.value || '').trim(),
            price_list_note:   (plDom.formNote.value || '').trim(),
            price_list_status: plDom.formStatus.checked ? 1 : 0,
            is_default:        plDom.formIsDefault.checked ? 1 : 0,
            copy_from_id:      id === 0 ? (parseInt(plDom.formCopyFrom.value, 10) || 0) : 0,
        }).then(function (res) {
            if (!res.success) {
                toast((res.data && res.data.message) || 'Lỗi lưu bảng giá.', 'error');
                return;
            }
            toast(res.data.message || 'Đã lưu bảng giá.', 'success');
            var m = getPlModal();
            if (m) m.hide();

            loadPriceLists(function () {
                // Đang mở bảng giá này thì cập nhật lại thanh tiêu đề
                if (state.priceListId) {
                    renderPriceListHeader(findPriceList(state.priceListId));
                }
            });
        }).catch(function () { toast('Loi ket noi.', 'error'); })
          .finally(function () { plDom.formSave.disabled = false; });
    }

    function deletePriceList(id) {
        var p = findPriceList(id);
        if (!p) return;
        if (!confirm('Xóa bảng giá "' + p.price_list_name + '"?\n'
            + 'Toàn bộ cấu hình ĐVT bên trong cũng bị xóa theo.')) {
            return;
        }

        postAjax('tgs_htsoft_converter_delete_price_list', { id: id })
            .then(function (res) {
                if (!res.success) {
                    toast((res.data && res.data.message) || 'Không xóa được bảng giá.', 'error');
                    return;
                }
                toast(res.data.message || 'Đã xóa bảng giá.', 'success');
                if (parseInt(id, 10) === state.priceListId) {
                    backToPriceLists();
                } else {
                    loadPriceLists();
                }
            })
            .catch(function () { toast('Loi ket noi.', 'error'); });
    }

    /* ── Modal: áp dụng bảng giá cho website ───────────────────────────── */

    var _abModal = null;
    var _abListId = 0;

    function getAbModal() {
        if (!_abModal) {
            var m = document.getElementById('applyBlogsModal');
            if (m) _abModal = new bootstrap.Modal(m);
        }
        return _abModal;
    }

    function openApplyBlogsModal(id) {
        _abListId = parseInt(id, 10) || state.priceListId;
        var p = findPriceList(_abListId);
        if (!p) return;

        document.getElementById('abListName').textContent = p.price_list_name;
        document.getElementById('abBlogList').innerHTML =
            '<tr><td colspan="3" class="text-center text-muted py-3">'
            + '<span class="spinner-border spinner-border-sm me-2"></span>Đang tải danh sách website…</td></tr>';

        var m = getAbModal();
        if (m) m.show();

        postAjax('tgs_htsoft_converter_list_price_list_blogs', {})
            .then(function (res) {
                if (!res.success) {
                    document.getElementById('abBlogList').innerHTML =
                        '<tr><td colspan="3" class="text-danger text-center py-3">Lỗi tải danh sách website.</td></tr>';
                    return;
                }
                state.blogRows = res.data.blogs || [];
                renderBlogRows('');
            })
            .catch(function () {
                document.getElementById('abBlogList').innerHTML =
                    '<tr><td colspan="3" class="text-danger text-center py-3">Lỗi kết nối.</td></tr>';
            });
    }

    function renderBlogRows(filter) {
        var body = document.getElementById('abBlogList');
        if (!body) return;

        var kw   = (filter || '').toLowerCase();
        var rows = state.blogRows.filter(function (b) {
            if (!kw) return true;
            return (b.blog_name || '').toLowerCase().indexOf(kw) !== -1
                || (b.blog_url || '').toLowerCase().indexOf(kw) !== -1;
        });

        if (!rows.length) {
            body.innerHTML = '<tr><td colspan="3" class="text-center text-muted py-3">Không có website phù hợp.</td></tr>';
            updateBlogSelectedCount();
            return;
        }

        var html = '';
        rows.forEach(function (b) {
            var mine    = parseInt(b.price_list_id, 10) === _abListId;
            var other   = (!mine && parseInt(b.price_list_id, 10) > 0);
            var current = mine
                ? '<span class="badge bg-info text-dark">Bảng giá này</span>'
                : (other
                    ? '<span class="badge bg-warning text-dark">' + escHtml(b.price_list_name) + '</span>'
                    : '<span class="text-muted small">Mặc định</span>');

            html += '<tr>' +
                '<td><input type="checkbox" class="form-check-input ab-blog" value="' + b.blog_id + '"' +
                    (mine ? ' checked' : '') + '></td>' +
                '<td><div class="fw-semibold">' + escHtml(b.blog_name) + '</div>' +
                    '<div class="small text-muted">#' + b.blog_id + ' · ' + escHtml(b.blog_url || '') + '</div></td>' +
                '<td>' + current + '</td>' +
                '</tr>';
        });
        body.innerHTML = html;

        body.querySelectorAll('.ab-blog').forEach(function (cb) {
            cb.addEventListener('change', updateBlogSelectedCount);
        });
        updateBlogSelectedCount();
    }

    function collectCheckedBlogIds() {
        var ids = [];
        document.querySelectorAll('#abBlogList .ab-blog:checked').forEach(function (cb) {
            ids.push(parseInt(cb.value, 10));
        });
        return ids;
    }

    function updateBlogSelectedCount() {
        var elCount = document.getElementById('abSelectedCount');
        if (elCount) elCount.textContent = collectCheckedBlogIds().length;
    }

    function saveApplyBlogs() {
        // Lưu ý: danh sách đang hiển thị có thể đã bị lọc → gộp với các website
        // của bảng giá này nhưng đang bị ẩn bởi bộ lọc, tránh gỡ nhầm.
        var checked = collectCheckedBlogIds();
        var visible = [];
        document.querySelectorAll('#abBlogList .ab-blog').forEach(function (cb) {
            visible.push(parseInt(cb.value, 10));
        });

        state.blogRows.forEach(function (b) {
            var bid = parseInt(b.blog_id, 10);
            if (visible.indexOf(bid) === -1 && parseInt(b.price_list_id, 10) === _abListId) {
                checked.push(bid);
            }
        });

        var btn = document.getElementById('abSave');
        if (btn) btn.disabled = true;

        postAjax('tgs_htsoft_converter_assign_price_list_blogs', {
            price_list_id: _abListId,
            blog_ids:      JSON.stringify(checked),
        }).then(function (res) {
            if (!res.success) {
                toast((res.data && res.data.message) || 'Lỗi áp dụng bảng giá.', 'error');
                return;
            }
            toast(res.data.message || 'Đã áp dụng.', 'success');
            var m = getAbModal();
            if (m) m.hide();
            loadPriceLists(function () {
                if (state.priceListId) renderPriceListHeader(findPriceList(state.priceListId));
            });
        }).catch(function () { toast('Loi ket noi.', 'error'); })
          .finally(function () { if (btn) btn.disabled = false; });
    }

    function bindPriceListEvents() {
        var btnOpenBase = el('btnOpenBase');
        if (btnOpenBase) btnOpenBase.addEventListener('click', enterBaseMode);

        if (plDom.btnNew)        plDom.btnNew.addEventListener('click', function () { openPriceListForm(0); });
        if (plDom.btnBack)       plDom.btnBack.addEventListener('click', backToPriceLists);
        if (plDom.btnEdit)       plDom.btnEdit.addEventListener('click', function () { openPriceListForm(state.priceListId); });
        if (plDom.btnApplyBlogs) plDom.btnApplyBlogs.addEventListener('click', function () { openApplyBlogsModal(state.priceListId); });
        if (plDom.formSave)      plDom.formSave.addEventListener('click', savePriceList);

        var abSearch = el('abSearch');
        if (abSearch) {
            abSearch.addEventListener('input', function () { renderBlogRows(abSearch.value); });
        }
        var abCheckAll = el('abCheckAll');
        if (abCheckAll) {
            abCheckAll.addEventListener('click', function () {
                document.querySelectorAll('#abBlogList .ab-blog').forEach(function (cb) { cb.checked = true; });
                updateBlogSelectedCount();
            });
        }
        var abUncheckAll = el('abUncheckAll');
        if (abUncheckAll) {
            abUncheckAll.addEventListener('click', function () {
                document.querySelectorAll('#abBlogList .ab-blog').forEach(function (cb) { cb.checked = false; });
                updateBlogSelectedCount();
            });
        }
        var abSave = el('abSave');
        if (abSave) abSave.addEventListener('click', saveApplyBlogs);
    }

    /* =========================================================================
     * Product Search
     * ====================================================================== */
    function searchProducts() {
        var kw = (dom.cfgSearchKeyword ? dom.cfgSearchKeyword.value : '').trim();

        // Huỷ request đang chạy — tránh xếp hàng nhiều request nặng và
        // tránh kết quả cũ về sau đè lên kết quả của từ khoá mới.
        if (state.searchAbort) {
            state.searchAbort.abort();
            state.searchAbort = null;
        }

        if (!kw) { renderSearchResults([]); return; }

        var reqId = ++state.searchReqId;
        var ctrl  = (typeof AbortController !== 'undefined') ? new AbortController() : null;
        state.searchAbort = ctrl;

        if (dom.cfgSearchResults) {
            dom.cfgSearchResults.innerHTML =
                '<div class="text-muted small text-center py-3">'
                + '<span class="spinner-border spinner-border-sm me-2"></span>Đang tìm…</div>';
        }

        postAjax('tgs_htsoft_converter_search_products', { keyword: kw },
                 ctrl ? { signal: ctrl.signal } : null)
            .then(function (res) {
                if (reqId !== state.searchReqId) return;   // đã có request mới hơn
                state.searchAbort = null;
                renderSearchResults((res && res.success) ? (res.data.products || []) : []);
            })
            .catch(function (err) {
                if (err && err.name === 'AbortError') return;
                if (reqId !== state.searchReqId) return;
                state.searchAbort = null;
                renderSearchResults([]);
            });
    }

    function renderSearchResults(products) {
        if (!dom.cfgSearchResults) return;
        if (!products.length) {
            dom.cfgSearchResults.innerHTML = '<div class="text-muted small text-center py-3">Không tìm thấy sản phẩm</div>';
            return;
        }
        var html = '<div class="list-group list-group-flush">';
        products.forEach(function (p) {
            var count = parseInt(p.config_count || 0, 10);
            var badge = count > 0
                ? '<span class="badge bg-primary rounded-pill ms-auto">' + count + '</span>'
                : '<span class="badge bg-secondary rounded-pill ms-auto">0</span>';
            html += '<button type="button" class="list-group-item list-group-item-action tgs-product-result d-flex align-items-center"' +
                ' data-sku="' + escHtml(p.local_product_sku) + '"' +
                ' data-name="' + escHtml(p.local_product_name) + '"' +
                ' data-unit="' + escHtml(p.local_product_unit || '') + '"' +
                ' data-count="' + count + '">' +
                '<div class="flex-grow-1">' +
                '<div class="fw-semibold text-dark">' + escHtml(p.local_product_name) + '</div>' +
                '<div class="small text-muted"><i class="bx bx-barcode me-1"></i>' + escHtml(p.local_product_sku) + '</div>' +
                '</div>' + badge +
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
            dom.cfgExistingConfigs.innerHTML = '<div class="text-center py-3 text-muted small">Chưa có cấu hình đơn vị nào</div>';
            return;
        }

        var isBase = state.mode === 'base';
        var html = '<div class="table-responsive"><table class="table table-sm tgs-config-table mb-0">' +
            '<thead class="table-light"><tr>' +
            '<th>Đơn vị tính</th><th>Tỷ lệ</th><th>' + (isBase ? 'Giá tham khảo' : 'Giá bán') + '</th>' +
            '<th>Khối lượng</th><th style="width:80px;"></th>' +
            '</tr></thead><tbody>';
        configs.forEach(function (c) {
            var priceCell = (c.unit_price !== null && c.unit_price !== undefined && c.unit_price !== '')
                ? '<span class="text-success fw-semibold">' + formatPrice(parseFloat(c.unit_price)) + ' ₫</span>'
                : '<span class="text-muted">— chưa có giá</span>';
            var weightCell = (c.unit_weight_kg !== null && c.unit_weight_kg !== undefined && c.unit_weight_kg !== '')
                ? '<span class="text-info">' + formatWeight(parseFloat(c.unit_weight_kg)) + ' kg</span>'
                : '<span class="text-muted">—</span>';
            var unitDisplay = c.convert_unit ? '<strong>' + escHtml(c.convert_unit) + '</strong>' : '<em class="text-muted">Mặc định</em>';
            var isDefault = isDefaultUnit(c);
            if (isDefault) unitDisplay += DEFAULT_UNIT_BADGE;
            var defaultBtn = isDefault
                ? ''
                : '<button class="btn btn-xs btn-outline-dark me-1" data-cfg-default="' + c.global_htsoft_stock_convert_id + '"' +
                  ' title="' + (isBase ? 'Đặt làm ĐVT bán chính (Bảng gốc)' : 'Đặt làm ĐVT bán chính cho bảng giá này') + '"><i class="bx bx-star"></i></button>';
            var delBtn = isBase
                ? '<button class="btn btn-xs btn-outline-danger" data-cfg-delete="' + c.global_htsoft_stock_convert_id + '" data-cfg-unit="' + escHtml(c.convert_unit || '') + '" title="Xóa"><i class="bx bx-trash"></i></button>'
                : '';
            html += '<tr>' +
                '<td>' + unitDisplay + '<br><small class="text-muted">' + escHtml(c.convert_note || '') + '</small></td>' +
                '<td class="fw-semibold text-primary">× ' + escHtml(formatRatio(parseFloat(c.convert_to_htsoft))) + '</td>' +
                '<td>' + priceCell + '</td>' +
                '<td>' + weightCell + '</td>' +
                '<td class="text-end text-nowrap">' +
                defaultBtn +
                '<button class="btn btn-xs btn-light me-1" data-cfg-edit="' + c.global_htsoft_stock_convert_id + '" title="' + (isBase ? 'Chỉnh sửa' : 'Sửa giá / ghi chú') + '">' +
                '<i class="bx bx-edit-alt"></i></button>' +
                delBtn +
                '</td></tr>';
        });
        html += '</tbody></table></div>';
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
        dom.cfgExistingConfigs.querySelectorAll('[data-cfg-default]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                setDefaultUnit(parseInt(btn.dataset.cfgDefault, 10));
            });
        });
    }

    /**
     * Đặt 1 cấu hình làm ĐVT bán chính — server tự hạ cờ các DVT khác cùng SKU về 0.
     */
    function setDefaultUnit(id) {
        postAjax('tgs_htsoft_converter_set_default_unit', { id: id })
            .then(function (res) {
                if (!res.success) {
                    toast((res.data && res.data.message) || 'Không đặt được ĐVT chính.', 'error');
                    return;
                }
                toast((res.data && res.data.message) || 'Đã đặt ĐVT bán chính.', 'success');
                var sku = dom.cfgSku ? dom.cfgSku.value : '';
                if (sku) loadConfigsForSku(sku);
                markMappingsStale();
            })
            .catch(function () { toast('Loi ket noi.', 'error'); });
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
        if (dom.cfgIsDefaultUnit)   dom.cfgIsDefaultUnit.checked  = isDefaultUnit(c);
        if (dom.cfgFormMode) {
            dom.cfgFormMode.textContent = 'Chỉnh sửa';
            dom.cfgFormMode.style.display = '';
            dom.cfgFormMode.className = 'badge bg-warning';
        }
        if (dom.cfgEditTitle) {
            dom.cfgEditTitle.innerHTML = '<i class="bx bx-edit-alt me-1"></i>Chỉnh sửa: ' + escHtml(c.convert_unit || 'Mặc định');
        }
        updateRatioPreview();
        if (dom.cfgConvertUnit) dom.cfgConvertUnit.focus();
    }

    /* =========================================================================
     * Unit Form
     * ====================================================================== */
    function resetUnitForm() {
        if (dom.cfgMappingId)       dom.cfgMappingId.value       = '0';
        if (dom.cfgConvertUnit)     dom.cfgConvertUnit.value      = '';
        if (dom.cfgConvertToHtsoft) dom.cfgConvertToHtsoft.value  = '1';
        if (dom.cfgNote)            dom.cfgNote.value             = '';
        if (dom.cfgUnitPrice)       dom.cfgUnitPrice.value        = '';
        if (dom.cfgUnitWeightKg)    dom.cfgUnitWeightKg.value     = '';
        if (dom.cfgIsDefaultUnit)   dom.cfgIsDefaultUnit.checked  = false;
        if (dom.cfgFormMode) {
            dom.cfgFormMode.textContent = 'Thêm mới';
            dom.cfgFormMode.style.display = 'none';
            dom.cfgFormMode.className = 'badge bg-primary';
        }
        if (dom.cfgEditTitle) {
            dom.cfgEditTitle.innerHTML = (state.mode === 'base')
                ? '<i class="bx bx-plus-circle me-1"></i>Thêm đơn vị tính mới (Bảng gốc)'
                : '<i class="bx bx-dollar me-1"></i>Chọn 1 ĐVT ở danh sách trên để khai giá';
        }
        updateRatioPreview();
    }

    function updateRatioPreview() {
        if (!dom.cfgRatioPreview) return;
        var unit  = (dom.cfgConvertUnit  ? dom.cfgConvertUnit.value  : '').trim();
        var ratio = parseFloat((dom.cfgConvertToHtsoft ? dom.cfgConvertToHtsoft.value : '').replace(',', '.')) || 0;
        if (ratio > 0) {
            var unitLabel = unit || 'đơn vị';
            dom.cfgRatioPreview.textContent = '1 ' + unitLabel + ' = ' + formatRatio(ratio) + ' đơn vị nhỏ nhất';
        } else {
            dom.cfgRatioPreview.textContent = '1 DVT = 1 đơn vị';
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

        if (state.mode !== 'base' && id === 0) {
            toast('Thêm ĐVT phải làm ở Bảng gốc. Bảng giá chỉ sửa giá / ghi chú.', 'error');
            return;
        }
        if (!unit && id === 0) { toast('Vui long nhap ten Don Vi Tinh.', 'error'); return; }
        if (state.mode === 'base' && (!toHtsoft || parseFloat(toHtsoft.replace(',', '.')) <= 0)) {
            toast('Ty le quy doi phai la so duong.', 'error');
            return;
        }

        if (dom.btnSaveMapping) dom.btnSaveMapping.disabled = true;

        postAjax('tgs_htsoft_converter_save_mapping', {
            id:                 id,
            global_product_sku: sku,
            convert_unit:       unit,
            convert_to_htsoft:  (toHtsoft || '1').replace(',', '.'),
            convert_note:       note,
            unit_price:         rawPrice,
            unit_weight_kg:     rawWeight.replace(',', '.'),
            is_default_unit:    (dom.cfgIsDefaultUnit && dom.cfgIsDefaultUnit.checked) ? 1 : 0,
        }).then(function (res) {
            if (res.success) {
                toast(res.data.message || 'Da luu.', 'success');
                var d = res.data || {};
                resetUnitForm();
                loadConfigsForSku(sku);
                markMappingsStale();
                // Bảng giá: vừa khai tay 1 giá → hỏi điền các ĐVT còn trống theo tỉ lệ
                if (state.mode !== 'base'
                    && d.saved_unit_price && parseFloat(d.saved_unit_price) > 0
                    && Array.isArray(d.missing_price_units) && d.missing_price_units.length) {
                    openFillMissingModal(sku, d.saved_unit, d.saved_unit_price, d.saved_unit_ratio, d.missing_price_units);
                }
            } else {
                toast((res.data && res.data.message) || 'Loi luu cau hinh.', 'error');
            }
        }).catch(function () { toast('Loi ket noi.', 'error'); })
          .finally(function () { if (dom.btnSaveMapping) dom.btnSaveMapping.disabled = false; });
    }

    /* ── Modal: điền giá theo tỉ lệ cho ĐVT còn trống ──────────────────── */
    var _fmpModal = null;
    function getFmpModal() {
        if (!_fmpModal) {
            var m = document.getElementById('fillMissingPriceModal');
            if (m) _fmpModal = new bootstrap.Modal(m);
        }
        return _fmpModal;
    }
    function openFillMissingModal(sku, fromUnit, fromPrice, fromRatio, missing) {
        var perSmallest = (parseFloat(fromRatio) > 0) ? parseFloat(fromPrice) / parseFloat(fromRatio) : 0;
        el('fmpSku').textContent       = sku;
        el('fmpCount').textContent     = missing.length;
        el('fmpFromUnit').textContent  = fromUnit || '';
        el('fmpFromPrice').textContent = formatPrice(parseFloat(fromPrice)) + ' ₫';
        var html = '';
        missing.forEach(function (m) {
            var g = Math.round(perSmallest * parseFloat(m.ratio) * 100) / 100;
            html += '<tr><td>' + escHtml(m.unit) + '</td><td>× ' + escHtml(formatRatio(parseFloat(m.ratio)))
                 + '</td><td class="text-end">' + formatPrice(g) + ' ₫</td></tr>';
        });
        el('fmpBody').innerHTML = html;
        var btn = el('fmpConfirm');
        btn.onclick = function () {
            btn.disabled = true;
            postAjax('tgs_htsoft_converter_fill_missing_prices', {
                global_product_sku: sku,
                from_unit_price:    fromPrice,
                from_ratio:         fromRatio,
            }).then(function (res) {
                if (res.success) {
                    toast((res.data && res.data.message) || 'Đã điền giá.', 'success');
                    loadConfigsForSku(sku);
                    markMappingsStale();
                } else {
                    toast((res.data && res.data.message) || 'Không điền được giá.', 'error');
                }
            }).catch(function () { toast('Loi ket noi.', 'error'); })
              .finally(function () {
                  btn.disabled = false;
                  var mm = getFmpModal(); if (mm) mm.hide();
              });
        };
        var m = getFmpModal();
        if (m) m.show();
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
                    // Gỡ khỏi lưới đang giữ trong RAM
                    if (state.allRows && state.allRows.length) {
                        state.allRows = state.allRows.filter(function (x) {
                            return parseInt(x.global_htsoft_stock_convert_id, 10) !== id;
                        });
                        renderGrid();
                    }
                } else {
                    toast((res.data && res.data.message) || 'Loi xoa.', 'error');
                }
            })
            .catch(function () { toast('Loi ket noi.', 'error'); });
    }

    /* =========================================================================
     * Lưới "tải hết" — Excel-like, vẽ theo khung nhìn (TGSDesignSystem.virtualBody)
     * ====================================================================== */
    function numOrNull(v) {
        return (v === null || v === undefined || v === '') ? null : parseFloat(v);
    }

    /** Tải TOÀN BỘ dòng của bảng gốc / bảng giá đang mở */
    function loadAllRows() {
        state.mappingLoading = true;
        hideStaleNotice();
        closeEditStrip();
        if (dom.mappingTableBody) {
            dom.mappingTableBody.innerHTML =
                '<tr><td colspan="8" class="text-center text-muted py-4">'
                + '<span class="spinner-border spinner-border-sm me-2"></span>Đang tải toàn bộ danh sách…</td></tr>';
        }
        if (dom.mappingTableFooter) dom.mappingTableFooter.textContent = 'Đang tải…';

        postAjax('tgs_htsoft_converter_list_all', {}).then(function (res) {
            if (!res || !res.success) {
                if (dom.mappingTableBody) dom.mappingTableBody.innerHTML =
                    '<tr><td colspan="8" class="text-center text-danger py-4">'
                    + ((res && res.data && res.data.message) || 'Lỗi tải dữ liệu.') + '</td></tr>';
                return;
            }
            state.allRows      = (res.data && res.data.rows) || [];
            state.mappingLoaded = true;
            renderGrid();
        }).catch(function () {
            if (dom.mappingTableBody) dom.mappingTableBody.innerHTML =
                '<tr><td colspan="8" class="text-center text-danger py-4">Lỗi kết nối.</td></tr>';
        }).finally(function () { state.mappingLoading = false; });
    }

    function gridVisibleRows() {
        var kw = (dom.mappingKeyword ? dom.mappingKeyword.value : '').trim().toLowerCase();
        if (!kw) return state.allRows;
        return state.allRows.filter(function (r) {
            return (String(r.global_product_sku || '').toLowerCase().indexOf(kw) !== -1)
                || (String(r.local_product_name || '').toLowerCase().indexOf(kw) !== -1)
                || (String(r.convert_unit || '').toLowerCase().indexOf(kw) !== -1);
        });
    }

    function gridRowHtml(r, i) {
        var isBase = state.mode === 'base';
        var price  = numOrNull(r.unit_price);
        var wkg    = numOrNull(r.unit_weight_kg);
        var priceCell = (price !== null)
            ? '<span class="text-success fw-semibold">' + formatPrice(price) + '</span>'
            : '<span class="text-muted">—</span>';
        var weightCell = (wkg !== null) ? formatWeight(wkg) : '<span class="text-muted">—</span>';
        var unitCell = escHtml(r.convert_unit || '') + (isDefaultUnit(r) ? ' <i class="bx bxs-star text-warning" title="ĐVT bán chính"></i>' : '');
        var ovBadge = (!isBase && (parseInt(r.note_overridden, 10) === 1 || parseInt(r.default_unit_overridden, 10) === 1))
            ? ' <span class="badge bg-warning text-dark" title="Bảng giá đã sửa riêng">riêng</span>' : '';
        var delBtn = isBase
            ? '<button class="btn btn-xs btn-outline-danger" data-g-del="' + r.global_htsoft_stock_convert_id + '" title="Xóa vĩnh viễn"><i class="bx bx-trash"></i></button>'
            : '';
        return '<tr data-i="' + i + '" data-id="' + r.global_htsoft_stock_convert_id + '" class="tgs-grid-row" style="cursor:pointer">' +
            '<td><code class="text-primary">' + escHtml(r.global_product_sku || '') + '</code></td>' +
            '<td class="text-truncate" style="max-width:280px">' + escHtml(r.local_product_name || '') + '</td>' +
            '<td>' + unitCell + ovBadge + '</td>' +
            '<td class="text-end">× ' + escHtml(formatRatio(parseFloat(r.convert_to_htsoft))) + '</td>' +
            '<td class="text-end">' + priceCell + '</td>' +
            '<td class="text-end">' + weightCell + '</td>' +
            '<td class="text-truncate" style="max-width:220px">' + escHtml(r.convert_note || '') + '</td>' +
            '<td class="text-nowrap">' +
              '<button class="btn btn-xs btn-light me-1" data-g-edit="' + r.global_htsoft_stock_convert_id + '" title="Sửa dòng"><i class="bx bx-edit-alt"></i></button>' +
              delBtn +
            '</td></tr>';
    }

    function gridCellText(r, col) {
        switch (col) {
            case 0: return String(r.global_product_sku || '');
            case 1: return String(r.local_product_name || '');
            case 2: return String(r.convert_unit || '');
            case 3: return formatRatio(parseFloat(r.convert_to_htsoft));
            case 4: { var p = numOrNull(r.unit_price); return p === null ? '' : formatPrice(p); }
            case 5: { var w = numOrNull(r.unit_weight_kg); return w === null ? '' : formatWeight(w); }
            case 6: return String(r.convert_note || '');
            default: return '';
        }
    }

    function renderGrid() {
        var table = el('mappingTable');
        var rows  = gridVisibleRows();
        var ds    = window.TGSDesignSystem;

        if (dom.mappingTableFooter) {
            dom.mappingTableFooter.textContent =
                (rows.length === state.allRows.length)
                    ? state.allRows.length.toLocaleString('vi-VN') + ' dòng'
                    : rows.length.toLocaleString('vi-VN') + ' / ' + state.allRows.length.toLocaleString('vi-VN') + ' dòng';
        }

        if (!state.allRows.length) {
            if (dom.mappingTableBody) dom.mappingTableBody.innerHTML =
                '<tr><td colspan="8" class="text-center text-muted py-4">Chưa có dòng nào</td></tr>';
            return;
        }

        if (ds && ds.virtualBody && table) {
            ds.virtualBody({
                table:    table,
                rows:     rows,
                rowHtml:  gridRowHtml,
                cellText: gridCellText,
                onFilter: function (filtered) {
                    if (dom.mappingTableFooter) {
                        dom.mappingTableFooter.textContent =
                            (filtered.length === state.allRows.length)
                                ? state.allRows.length.toLocaleString('vi-VN') + ' dòng'
                                : filtered.length.toLocaleString('vi-VN') + ' / ' + state.allRows.length.toLocaleString('vi-VN') + ' dòng (đang lọc)';
                    }
                }
            });
        } else if (dom.mappingTableBody) {
            var buf = [];
            for (var i = 0; i < rows.length; i++) buf.push(gridRowHtml(rows[i], i));
            dom.mappingTableBody.innerHTML = buf.join('');
        }
    }

    /* ── Sự kiện lưới (uỷ quyền, bind 1 lần) ────────────────────────────── */
    function bindGridEvents() {
        var table = el('mappingTable');
        if (!table) return;
        table.addEventListener('click', function (e) {
            var t = e.target;
            var delBtn = t.closest && t.closest('[data-g-del]');
            if (delBtn) {
                gridDeleteById(parseInt(delBtn.getAttribute('data-g-del'), 10));
                return;
            }
            var tr = t.closest && t.closest('tr[data-id]');
            if (tr) {
                var row = findRowById(parseInt(tr.getAttribute('data-id'), 10));
                if (row) openEditStrip(row);
            }
        });
    }
    function findRowById(id) {
        for (var i = 0; i < state.allRows.length; i++) {
            if (parseInt(state.allRows[i].global_htsoft_stock_convert_id, 10) === id) return state.allRows[i];
        }
        return null;
    }

    /* ── Dải sửa nhanh 1 dòng ──────────────────────────────────────────── */
    function openEditStrip(r) {
        var isBase = state.mode === 'base';
        el('geId').value       = r.global_htsoft_stock_convert_id;
        el('geSku').textContent  = r.global_product_sku || '';
        el('geName').textContent = r.local_product_name || '';
        el('geUnit').value   = r.convert_unit || '';
        el('geRatio').value  = (r.convert_to_htsoft != null) ? parseFloat(r.convert_to_htsoft) : '';
        el('gePrice').value  = (r.unit_price != null && r.unit_price !== '') ? parseFloat(r.unit_price) : '';
        el('geWeight').value = (r.unit_weight_kg != null && r.unit_weight_kg !== '') ? parseFloat(r.unit_weight_kg) : '';
        el('geNote').value   = r.convert_note || '';
        el('geDefault').checked = isDefaultUnit(r);
        el('gePriceLbl').textContent = isBase ? 'Giá tham khảo' : 'Giá bán';
        // cấu trúc chỉ sửa ở Bảng gốc
        el('geUnit').disabled   = !isBase;
        el('geRatio').disabled  = !isBase;
        el('geWeight').disabled = !isBase;
        el('geDelete').classList.toggle('d-none', !isBase);
        el('gridEditStrip').classList.remove('d-none');
        (isBase ? el('geUnit') : el('gePrice')).focus();
    }
    function closeEditStrip() {
        var s = el('gridEditStrip');
        if (s) s.classList.add('d-none');
    }
    function saveEditStrip() {
        var id = parseInt(el('geId').value, 10) || 0;
        if (!id) return;
        var row = findRowById(id);
        if (!row) { closeEditStrip(); return; }
        var isBase = state.mode === 'base';

        var payload = {
            id:                 id,
            global_product_sku: row.global_product_sku,
            convert_unit:       (el('geUnit').value || '').trim(),
            convert_to_htsoft:  (el('geRatio').value || '1').toString().replace(',', '.'),
            unit_price:         (el('gePrice').value || '').toString().replace(',', '.'),
            unit_weight_kg:     (el('geWeight').value || '').toString().replace(',', '.'),
            convert_note:       (el('geNote').value || '').trim(),
            is_default_unit:    el('geDefault').checked ? 1 : 0,
        };
        if (isBase && (!payload.convert_unit || parseFloat(payload.convert_to_htsoft) <= 0)) {
            toast('ĐVT và tỷ lệ quy đổi là bắt buộc.', 'error');
            return;
        }
        if (!confirm('Cập nhật dòng này?')) return;

        var btn = el('geSave'); btn.disabled = true;
        postAjax('tgs_htsoft_converter_save_mapping', payload).then(function (res) {
            if (!res || !res.success) {
                toast((res && res.data && res.data.message) || 'Lỗi lưu.', 'error');
                return;
            }
            toast(res.data.message || 'Đã lưu.', 'success');
            var d = res.data || {};
            // cập nhật tại chỗ trong mảng
            row.convert_unit      = payload.convert_unit || row.convert_unit;
            row.convert_to_htsoft = payload.convert_to_htsoft;
            row.unit_price        = payload.unit_price === '' ? null : payload.unit_price;
            row.unit_weight_kg    = payload.unit_weight_kg === '' ? null : payload.unit_weight_kg;
            row.convert_note      = payload.convert_note;
            if (payload.is_default_unit === 1) {
                state.allRows.forEach(function (x) {
                    if (x.global_product_sku === row.global_product_sku) x.is_default_unit = (x === row) ? 1 : 0;
                });
            }
            closeEditStrip();
            renderGrid();
            // Bảng giá: vừa khai tay 1 giá → hỏi điền các ĐVT còn trống
            if (!isBase && d.saved_unit_price && parseFloat(d.saved_unit_price) > 0
                && Array.isArray(d.missing_price_units) && d.missing_price_units.length) {
                openFillMissingModal(row.global_product_sku, d.saved_unit, d.saved_unit_price, d.saved_unit_ratio, d.missing_price_units);
            }
            // Panel chi tiết theo SKU nếu đang mở đúng mã
            if (dom.cfgSku && dom.cfgSku.value === row.global_product_sku) loadConfigsForSku(row.global_product_sku);
        }).catch(function () { toast('Loi ket noi.', 'error'); })
          .finally(function () { btn.disabled = false; });
    }
    function gridDeleteById(id) {
        var row = findRowById(id);
        if (!row) return;
        if (state.mode !== 'base') {
            toast('Xóa ĐVT phải làm ở Bảng gốc.', 'error');
            return;
        }
        if (!confirm('Xóa VĨNH VIỄN ĐVT "' + (row.convert_unit || '') + '" của mã ' + row.global_product_sku
            + '?\nMọi bảng giá cũng bị xóa dòng này. Không hoàn tác.')) return;
        postAjax('tgs_htsoft_converter_delete_mapping', { id: id }).then(function (res) {
            if (!res || !res.success) {
                toast((res && res.data && res.data.message) || 'Lỗi xóa.', 'error');
                return;
            }
            toast((res.data && res.data.message) || 'Đã xóa.', 'success');
            state.allRows = state.allRows.filter(function (x) {
                return parseInt(x.global_htsoft_stock_convert_id, 10) !== id;
            });
            closeEditStrip();
            renderGrid();
            if (dom.cfgSku && dom.cfgSku.value === row.global_product_sku) loadConfigsForSku(row.global_product_sku);
        }).catch(function () { toast('Loi ket noi.', 'error'); });
    }

    /* ── "Dữ liệu đã đổi" — nhắc tải lại lưới ─────────────────────────── */
    function markMappingsStale() {
        if (!state.mappingLoaded || !dom.mappingStaleNotice) return;
        dom.mappingStaleNotice.classList.remove('d-none');
    }
    function hideStaleNotice() {
        if (dom.mappingStaleNotice) dom.mappingStaleNotice.classList.add('d-none');
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

                var data = [['Ma hang', 'Ten hang', 'Don vi tinh', 'Ty le quy doi', 'Gia ban (VND)', 'Khoi luong kg/1 DVT', 'Ghi chu', 'DVT ban chinh']];
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
                        isDefaultUnit(r) ? 1 : 0,
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

            // Không tự tải lại bảng tổng sau khi import (dữ liệu lớn → treo trình duyệt).
            markMappingsStale();
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
        // Đóng modal: chỉ báo dữ liệu đã đổi, không tự tải lại bảng tổng.
        d.closeBtn.onclick = function () { getPiModal().hide(); markMappingsStale(); };

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
                rows_json:      JSON.stringify(batch),
                derive_missing: (el('piDeriveMissing') && el('piDeriveMissing').checked) ? 1 : 0,
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
     * Thống nhất ĐVT bán chính (quét toàn bộ theo batch SKU)
     * ====================================================================== */

    var _duModal = null;
    var _duDom   = null;
    var _duState = null;

    function getDuModal() {
        if (!_duModal) {
            var elModal = document.getElementById('defaultUnitModal');
            if (elModal) _duModal = new bootstrap.Modal(elModal, { backdrop: 'static', keyboard: false });
        }
        return _duModal;
    }

    function getDuDom() {
        if (!_duDom) {
            _duDom = {
                intro:          el('duIntro'),
                onlyMissing:    el('duOnlyMissing'),
                totalInfo:      el('duTotalInfo'),
                progressWrap:   el('duProgressWrap'),
                progressBar:    el('duProgressBar'),
                progressText:   el('duProgressText'),
                progressPct:    el('duProgressPct'),
                statProcessed:  el('duStatProcessed'),
                statAssigned:   el('duStatAssigned'),
                statUnchanged:  el('duStatUnchanged'),
                statNoCandidate: el('duStatNoCandidate'),
                resultWrap:     el('duResultWrap'),
                resultDone:     el('duResultDone'),
                resultStopped:  el('duResultStopped'),
                samplesWrap:    el('duSamplesWrap'),
                samplesBody:    el('duSamplesBody'),
                errorsWrap:     el('duErrorsWrap'),
                errorsUl:       el('duErrorsUl'),
                startBtn:       el('duStartBtn'),
                stopBtn:        el('duStopBtn'),
                closeBtn:       el('duCloseBtn'),
            };
        }
        return _duDom;
    }

    /** Mở modal: reset UI + hỏi server tổng số mã hàng cần quét */
    function openDefaultUnitModal() {
        var d = getDuDom();
        if (!d.startBtn) return;

        _duState = { stopped: false, processed: 0, assigned: 0, unchanged: 0, noCandidate: 0,
                     total: 0, batchSize: 200, samples: [], errors: [], running: false };

        d.intro.classList.remove('d-none');
        d.progressWrap.classList.add('d-none');
        d.resultWrap.classList.add('d-none');
        d.resultDone.classList.add('d-none');
        d.resultStopped.classList.add('d-none');
        d.samplesWrap.classList.add('d-none');
        d.errorsWrap.classList.add('d-none');
        d.samplesBody.innerHTML = '';
        d.errorsUl.innerHTML    = '';
        d.progressBar.style.width = '0%';
        d.progressBar.classList.add('progress-bar-animated');
        d.progressPct.textContent = '0%';
        d.statProcessed.textContent   = '0';
        d.statAssigned.textContent    = '0';
        d.statUnchanged.textContent   = '0';
        d.statNoCandidate.textContent = '0';

        d.startBtn.classList.remove('d-none');
        d.startBtn.disabled = true;
        d.stopBtn.classList.add('d-none');
        d.stopBtn.disabled  = false;
        d.closeBtn.disabled = false;
        d.totalInfo.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Đang đếm số mã hàng…';

        d.startBtn.onclick = function () { startDefaultUnitScan(); };
        d.stopBtn.onclick  = function () {
            _duState.stopped = true;
            d.stopBtn.disabled = true;
            d.stopBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Đang dừng…';
        };
        d.closeBtn.onclick = function () {
            if (_duState.running) return;
            var m = getDuModal();
            if (m) m.hide();
        };

        var m = getDuModal();
        if (m) m.show();

        postAjax('tgs_htsoft_converter_default_scan_prepare', {})
            .then(function (res) {
                if (!res.success) {
                    d.totalInfo.innerHTML = '<span class="text-danger">Không đếm được dữ liệu.</span>';
                    return;
                }
                _duState.total     = parseInt(res.data.total_skus, 10) || 0;
                _duState.batchSize = parseInt(res.data.batch_size, 10) || 200;
                d.totalInfo.innerHTML = 'Tổng số mã hàng có cấu hình quy đổi: <strong>'
                    + _duState.total.toLocaleString('vi-VN') + '</strong>';
                d.startBtn.disabled = (_duState.total === 0);
            })
            .catch(function () {
                d.totalInfo.innerHTML = '<span class="text-danger">Lỗi kết nối.</span>';
            });
    }

    function startDefaultUnitScan() {
        var d = getDuDom();

        _duState.running     = true;
        _duState.onlyMissing = (d.onlyMissing && d.onlyMissing.checked) ? 1 : 0;

        d.intro.classList.add('d-none');
        d.progressWrap.classList.remove('d-none');
        d.startBtn.classList.add('d-none');
        d.stopBtn.classList.remove('d-none');
        d.closeBtn.disabled = true;

        runDefaultUnitBatch(0);
    }

    function runDefaultUnitBatch(offset) {
        var d = getDuDom();

        if (_duState.stopped) { finishDefaultUnitScan(true); return; }

        d.progressText.textContent = (offset >= _duState.total)
            ? 'Đang hoàn tất…'
            : 'Đang xử lý mã hàng ' + (offset + 1)
              + '–' + Math.min(offset + _duState.batchSize, _duState.total) + ' / ' + _duState.total + '…';

        postAjax('tgs_htsoft_converter_default_scan_batch', {
            offset:       offset,
            batch_size:   _duState.batchSize,
            only_missing: _duState.onlyMissing,
        }).then(function (res) {
            if (!res.success) {
                _duState.errors.push('Lô từ dòng ' + (offset + 1) + ': '
                    + ((res.data && res.data.message) || 'lỗi không xác định'));
                finishDefaultUnitScan(false);
                return;
            }

            var data = res.data || {};
            _duState.processed   += parseInt(data.processed, 10) || 0;
            _duState.assigned    += parseInt(data.assigned, 10) || 0;
            _duState.unchanged   += parseInt(data.unchanged, 10) || 0;
            _duState.noCandidate += parseInt(data.no_candidate, 10) || 0;

            if (Array.isArray(data.samples) && _duState.samples.length < 50) {
                _duState.samples = _duState.samples.concat(data.samples).slice(0, 50);
            }

            var pct = _duState.total > 0
                ? Math.min(100, Math.round((_duState.processed / _duState.total) * 100))
                : 100;
            d.progressBar.style.width = pct + '%';
            d.progressPct.textContent = pct + '%';
            d.statProcessed.textContent   = _duState.processed.toLocaleString('vi-VN');
            d.statAssigned.textContent    = _duState.assigned.toLocaleString('vi-VN');
            d.statUnchanged.textContent   = _duState.unchanged.toLocaleString('vi-VN');
            d.statNoCandidate.textContent = _duState.noCandidate.toLocaleString('vi-VN');

            if (data.done) { finishDefaultUnitScan(false); return; }
            runDefaultUnitBatch(parseInt(data.next_offset, 10) || (offset + _duState.batchSize));
        }).catch(function () {
            _duState.errors.push('Lô từ dòng ' + (offset + 1) + ': lỗi kết nối.');
            finishDefaultUnitScan(false);
        });
    }

    function finishDefaultUnitScan(stopped) {
        var d = getDuDom();

        _duState.running = false;

        d.progressBar.classList.remove('progress-bar-animated');
        if (!stopped) {
            d.progressBar.style.width = '100%';
            d.progressPct.textContent = '100%';
        }
        d.progressText.textContent = stopped ? 'Đã dừng.' : 'Hoàn tất.';

        d.stopBtn.classList.add('d-none');
        d.closeBtn.disabled = false;

        d.resultWrap.classList.remove('d-none');
        if (stopped) {
            d.resultStopped.classList.remove('d-none');
        } else {
            d.resultDone.classList.remove('d-none');
            d.resultDone.innerHTML = '<i class="bx bx-check-circle me-1"></i>Đã quét <strong>'
                + _duState.processed.toLocaleString('vi-VN') + '</strong> mã hàng. '
                + 'Đặt ĐVT chính: <strong>' + _duState.assigned.toLocaleString('vi-VN') + '</strong>, '
                + 'giữ nguyên: <strong>' + _duState.unchanged.toLocaleString('vi-VN') + '</strong>, '
                + 'không có ứng viên: <strong>' + _duState.noCandidate.toLocaleString('vi-VN') + '</strong>.';
        }

        if (_duState.samples.length) {
            d.samplesWrap.classList.remove('d-none');
            var html = '';
            _duState.samples.forEach(function (s) {
                html += '<tr>' +
                    '<td><code>' + escHtml(s.sku) + '</code></td>' +
                    '<td>' + escHtml(s.unit || 'Mặc định') + '</td>' +
                    '<td>× ' + escHtml(formatRatio(parseFloat(s.ratio))) + '</td>' +
                    '<td>' + ((s.price !== null && s.price !== undefined)
                        ? formatPrice(parseFloat(s.price)) + ' ₫' : '—') + '</td>' +
                    '</tr>';
            });
            d.samplesBody.innerHTML = html;
        }

        if (_duState.errors.length) {
            d.errorsWrap.classList.remove('d-none');
            _duState.errors.slice(0, 20).forEach(function (msg) {
                var li = document.createElement('li');
                li.textContent = msg;
                d.errorsUl.appendChild(li);
            });
        }

        // Dữ liệu đã đổi → nhắc tải lại bảng tổng, và làm mới panel SKU đang mở
        markMappingsStale();
        var sku = dom.cfgSku ? dom.cfgSku.value : '';
        if (sku) loadConfigsForSku(sku);
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
                // 1 ký tự khớp gần như toàn bộ 19k sản phẩm → chờ tối thiểu 2 ký tự
                var kw = dom.cfgSearchKeyword.value.trim();
                if (kw.length === 1) { return; }
                state.searchTimer = setTimeout(searchProducts, 400);
            });
            dom.cfgSearchKeyword.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') { clearTimeout(state.searchTimer); searchProducts(); }
            });
        }

        if (dom.btnCfgSearch) dom.btnCfgSearch.addEventListener('click', function () {
            clearTimeout(state.searchTimer);
            searchProducts();
        });

        if (dom.cfgConvertUnit)     dom.cfgConvertUnit.addEventListener('input', updateRatioPreview);
        if (dom.cfgConvertToHtsoft) dom.cfgConvertToHtsoft.addEventListener('input', updateRatioPreview);

        if (dom.btnSaveMapping)      dom.btnSaveMapping.addEventListener('click', saveMapping);
        if (dom.btnResetUnitForm)     dom.btnResetUnitForm.addEventListener('click', function () { resetUnitForm(); });
        if (dom.btnRefreshConfigs)    dom.btnRefreshConfigs.addEventListener('click', function () {
            var sku = dom.cfgSku ? dom.cfgSku.value : '';
            if (sku) loadConfigsForSku(sku);
        });

        if (dom.btnReloadMappings) dom.btnReloadMappings.addEventListener('click', loadAllRows);
        if (dom.btnReloadStale)  dom.btnReloadStale.addEventListener('click', loadAllRows);
        var btnReloadStale2 = el('btnReloadStale2');
        if (btnReloadStale2) btnReloadStale2.addEventListener('click', loadAllRows);

        if (dom.mappingKeyword) {
            dom.mappingKeyword.addEventListener('input', function () {
                clearTimeout(state.mappingSearchTimer);
                state.mappingSearchTimer = setTimeout(renderGrid, 250);
            });
        }

        bindGridEvents();

        // Dải sửa nhanh 1 dòng
        var geSave = el('geSave'), geCancel = el('geCancel'), geDelete = el('geDelete');
        if (geSave)   geSave.addEventListener('click', saveEditStrip);
        if (geCancel) geCancel.addEventListener('click', closeEditStrip);
        if (geDelete) geDelete.addEventListener('click', function () {
            gridDeleteById(parseInt(el('geId').value, 10) || 0);
        });

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

        if (dom.btnUnifyDefaultUnit) {
            dom.btnUnifyDefaultUnit.addEventListener('click', openDefaultUnitModal);
        }

        var btnReset = el('btnResetDefaultToBase');
        if (btnReset) btnReset.addEventListener('click', function () {
            if (!confirm('Đưa ĐVT bán chính của TOÀN BỘ bảng giá này về theo Bảng gốc?\n'
                + 'Các lựa chọn ĐVT chính riêng của bảng giá sẽ bị bỏ.')) return;
            postAjax('tgs_htsoft_converter_reset_default_to_base', {}).then(function (res) {
                if (!res.success) { toast((res.data && res.data.message) || 'Lỗi.', 'error'); return; }
                toast((res.data && res.data.message) || 'Đã cập nhật.', 'success');
                var sku = dom.cfgSku ? dom.cfgSku.value : '';
                if (sku) loadConfigsForSku(sku);
                markMappingsStale();
            }).catch(function () { toast('Loi ket noi.', 'error'); });
        });

        var btnSyncAll = el('btnBaseSyncAll');
        if (btnSyncAll) btnSyncAll.addEventListener('click', runBaseSyncAll);
    }

    /* Đồng bộ Bảng gốc → tất cả bảng giá — mở modal hỏi cách xử lý giá */
    var _bsModal = null;
    function getBsModal() {
        if (!_bsModal) {
            var m = document.getElementById('baseSyncModal');
            if (m) _bsModal = new bootstrap.Modal(m, { backdrop: 'static' });
        }
        return _bsModal;
    }
    function runBaseSyncAll() {
        if (state.mode !== 'base') return;
        var m = getBsModal();
        if (!m) return;
        // reset modal
        el('bsIntro').classList.remove('d-none');
        el('bsProgressWrap').classList.add('d-none');
        el('bsResult').classList.add('d-none');
        el('bsPriceNone').checked = true;
        el('bsStart').disabled = false;
        el('bsStart').classList.remove('d-none');
        el('bsProgressBar').style.width = '0%';
        el('bsStart').onclick = function () { startBaseSync(); };
        m.show();
    }
    function startBaseSync() {
        var priceMode = (document.querySelector('input[name="bsPriceMode"]:checked') || {}).value || 'none';
        if (priceMode === 'overwrite'
            && !confirm('GHI ĐÈ TOÀN BỘ giá của mọi bảng giá bằng giá tham khảo?\nKhông thể hoàn tác.')) {
            return;
        }
        el('bsIntro').classList.add('d-none');
        el('bsStart').classList.add('d-none');
        el('bsCancel').disabled = true;
        el('bsCloseX').disabled = true;
        el('bsProgressWrap').classList.remove('d-none');

        postAjax('tgs_htsoft_base_sync_prepare', {}).then(function (res) {
            if (!res.success) throw new Error();
            var total = parseInt(res.data.total_skus, 10) || 0;
            var size  = parseInt(res.data.batch_size, 10) || 200;
            var done  = 0;

            function step(offset) {
                postAjax('tgs_htsoft_base_sync_batch', {
                    offset: offset, batch_size: size, sync_prices: priceMode,
                }).then(function (r) {
                    if (!r.success) throw new Error();
                    done += parseInt(r.data.processed, 10) || 0;
                    var pct = total > 0 ? Math.min(100, Math.round(done / total * 100)) : 100;
                    el('bsProgressBar').style.width = pct + '%';
                    el('bsProgressPct').textContent = pct + '%';
                    el('bsProgressCount').textContent = done.toLocaleString('vi-VN') + ' / ' + total.toLocaleString('vi-VN') + ' mã hàng';
                    if (r.data.done) { finishBaseSync(done, priceMode); }
                    else { step(parseInt(r.data.next_offset, 10) || (offset + size)); }
                }).catch(function () { failBaseSync(); });
            }
            step(0);
        }).catch(function () { failBaseSync(); });
    }
    function finishBaseSync(done, priceMode) {
        el('bsProgressBar').classList.remove('progress-bar-animated');
        el('bsProgressText').textContent = 'Hoàn tất.';
        var pnote = priceMode === 'overwrite' ? ' (đã ghi đè giá)'
                  : priceMode === 'fill' ? ' (đã điền giá cho ĐVT trống)' : '';
        var res = el('bsResult');
        res.classList.remove('d-none');
        res.innerHTML = '<i class="bx bx-check-circle me-1"></i>Đã đồng bộ ' + done.toLocaleString('vi-VN') + ' mã hàng xuống mọi bảng giá' + pnote + '.';
        el('bsCancel').disabled = false;
        el('bsCloseX').disabled = false;
        el('bsCancel').textContent = 'Đóng';
        markMappingsStale();
    }
    function failBaseSync() {
        toast('Lỗi khi đồng bộ. Thử lại.', 'error');
        el('bsCancel').disabled = false;
        el('bsCloseX').disabled = false;
        el('bsProgressText').textContent = 'Lỗi.';
    }

    /* =========================================================================
     * Init
     * ====================================================================== */
    function init() {
        bindEvents();
        bindPriceListEvents();
        initScanner();

        // Vào trang là màn hình DANH SÁCH BẢNG GIÁ; lưới tự tải khi mở Bảng gốc / bảng giá
        loadPriceLists();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
