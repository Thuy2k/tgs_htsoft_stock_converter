(function () {
    'use strict';

    const CFG = window.TGSHTSoftConverterConfig || {};
    const state = {
        scanner: null,
        scannerCooldownAt: 0,
        searchTimer: null,
        mappingSearchTimer: null,
        workbookHtsoftToTgs: null,
        workbookTgsToHtsoft: null,
        selectedProduct: null,
        mapRows: [],
        resultHtsoftToTgs: [],
        resultTgsToHtsoft: [],
    };

    const dom = {
        cfgSearchKeyword: document.getElementById('cfgSearchKeyword'),
        btnCfgSearch: document.getElementById('btnCfgSearch'),
        cfgSearchResults: document.getElementById('cfgSearchResults'),
        btnOpenScanner: document.getElementById('btnOpenScanner'),
        btnCloseScanner: document.getElementById('btnCloseScanner'),
        scannerWrap: document.getElementById('scannerWrap'),

        cfgMappingId: document.getElementById('cfgMappingId'),
        cfgSku: document.getElementById('cfgSku'),
        cfgProductName: document.getElementById('cfgProductName'),
        cfgUnit: document.getElementById('cfgUnit'),
        cfgQtyLogs: document.getElementById('cfgQtyLogs'),
        cfgConvertToHtsoft: document.getElementById('cfgConvertToHtsoft'),
        cfgNote: document.getElementById('cfgNote'),
        cfgFormMode: document.getElementById('cfgFormMode'),

        btnSaveMapping: document.getElementById('btnSaveMapping'),
        btnResetForm: document.getElementById('btnResetForm'),

        mappingKeyword: document.getElementById('mappingKeyword'),
        btnReloadMappings: document.getElementById('btnReloadMappings'),
        btnExportMappingsJson: document.getElementById('btnExportMappingsJson'),
        btnImportMappingsJson: document.getElementById('btnImportMappingsJson'),
        mappingJsonFile: document.getElementById('mappingJsonFile'),
        mappingTableBody: document.getElementById('mappingTableBody'),

        fileHtsoftToTgs: document.getElementById('fileHtsoftToTgs'),
        sheetHtsoftToTgs: document.getElementById('sheetHtsoftToTgs'),
        btnConvertHtsoftToTgs: document.getElementById('btnConvertHtsoftToTgs'),
        btnExportHtsoftToTgs: document.getElementById('btnExportHtsoftToTgs'),
        tbodyHtsoftToTgs: document.getElementById('tbodyHtsoftToTgs'),

        fileTgsToHtsoft: document.getElementById('fileTgsToHtsoft'),
        sheetTgsToHtsoft: document.getElementById('sheetTgsToHtsoft'),
        btnConvertTgsToHtsoft: document.getElementById('btnConvertTgsToHtsoft'),
        btnExportTgsToHtsoft: document.getElementById('btnExportTgsToHtsoft'),
        tbodyTgsToHtsoft: document.getElementById('tbodyTgsToHtsoft'),
    };

    function toast(message, type) {
        if (window.TGS_Toast && typeof window.TGS_Toast[type] === 'function') {
            window.TGS_Toast[type](message);
            return;
        }
        alert(message);
    }

    function esc(text) {
        const div = document.createElement('div');
        div.textContent = String(text == null ? '' : text);
        return div.innerHTML;
    }

    function parseNumber(input) {
        const raw = String(input == null ? '' : input).trim();
        if (!raw) return 0;
        const normalized = raw.replace(/\s+/g, '').replace(',', '.');
        const n = Number(normalized);
        return Number.isFinite(n) ? n : 0;
    }

    function formatRatio(value) {
        const number = parseNumber(value);
        if (!Number.isFinite(number) || number <= 0) {
            return '1';
        }

        if (Number.isInteger(number)) {
            return String(number);
        }

        return String(number).replace(/\.0+$/, '').replace(/(\.\d*?)0+$/, '$1');
    }

    function defaultNoteForUnit(unit, ratioText) {
        const unitLabel = unit || 'đơn vị';
        return '1 ' + unitLabel + ' bên TGS tương ứng ' + ratioText + ' đơn vị bên HTSoft';
    }

    async function postAjax(action, payload) {
        const fd = new FormData();
        fd.append('action', action);
        fd.append('nonce', CFG.nonce || '');

        Object.keys(payload || {}).forEach((key) => {
            fd.append(key, payload[key]);
        });

        const res = await fetch(CFG.ajaxUrl, {
            method: 'POST',
            body: fd,
            credentials: 'same-origin',
        });
        const json = await res.json();
        if (!json.success) {
            throw new Error((json.data && json.data.message) || 'Yêu cầu thất bại.');
        }
        return json.data || {};
    }

    async function searchProducts() {
        const keyword = dom.cfgSearchKeyword.value || '';
        if (!keyword.trim()) {
            dom.cfgSearchResults.innerHTML = '';
            return;
        }

        try {
            const data = await postAjax('tgs_htsoft_converter_search_products', { keyword: keyword });
            const products = data.products || [];
            if (!products.length) {
                dom.cfgSearchResults.innerHTML = '<div class="text-muted small py-2">Không tìm thấy sản phẩm.</div>';
                return;
            }

            dom.cfgSearchResults.innerHTML = products.map((p) => {
                // Format quantity as integer, no decimal
                let qty = (typeof p.local_product_quantity_no_tracking !== 'undefined' && p.local_product_quantity_no_tracking !== null)
                    ? Math.floor(Number(p.local_product_quantity_no_tracking)) : 0;
                return '<button type="button" class="list-group-item list-group-item-action tgs-product-item"'
                    + ' data-sku="' + esc(p.local_product_sku || '') + '"'
                    + ' data-name="' + esc(p.local_product_name || '') + '"'
                    + ' data-unit="' + esc(p.local_product_unit || '') + '"'
                    + ' data-barcode="' + esc(p.local_product_barcode_main || '') + '"'
                    + ' data-qty="' + esc(qty) + '">' // new: data-qty
                    + '<div class="fw-semibold">' + esc(p.local_product_name || '-') + '</div>'
                    + '<div class="small text-muted">SKU: ' + esc(p.local_product_sku || '-')
                    + ' | Barcode: ' + esc(p.local_product_barcode_main || '-')
                    + ' | DVT: ' + esc(p.local_product_unit || '-')
                    + ' | <span class="text-primary">SL tham khảo: <b>' + esc(qty) + '</b></span>'
                    + '</div>'
                    + '</button>';
            }).join('');

        } catch (err) {
            toast(err.message, 'error');
        }
    }

    function scheduleSearch() {
        if (state.searchTimer) {
            window.clearTimeout(state.searchTimer);
        }

        state.searchTimer = window.setTimeout(function () {
            searchProducts();
        }, 300);
    }

    function scheduleMappingSearch() {
        if (state.mappingSearchTimer) {
            window.clearTimeout(state.mappingSearchTimer);
        }

        state.mappingSearchTimer = window.setTimeout(function () {
            loadMappings();
        }, 300);
    }

    function fillMappingForm(product, mapping) {
        state.selectedProduct = product;
        dom.cfgSku.value = product.sku;
        dom.cfgProductName.value = product.name;
        dom.cfgUnit.value = product.unit;
        // Hiển thị số lượng tham khảo (nếu có) ở khối form
        // Ưu tiên lấy số lượng tham khảo từ mapping nếu có, nếu không thì lấy từ product
        let qty = 0;
        if (mapping && typeof mapping.local_product_quantity_no_tracking !== 'undefined' && mapping.local_product_quantity_no_tracking !== null) {
            qty = Math.floor(Number(mapping.local_product_quantity_no_tracking));
        } else if (typeof product.local_product_quantity_no_tracking !== 'undefined' && product.local_product_quantity_no_tracking !== null) {
            qty = Math.floor(Number(product.local_product_quantity_no_tracking));
        }
        let qtyBox = document.getElementById('cfgQtyNoTrackingBox');
        if (!qtyBox) {
            // Tạo box nếu chưa có
            qtyBox = document.createElement('div');
            qtyBox.id = 'cfgQtyNoTrackingBox';
            qtyBox.className = 'small text-primary mb-2';
            dom.cfgSku.parentElement.parentElement.insertAdjacentElement('afterend', qtyBox);
        }
        qtyBox.innerHTML = '<span>SL tham khảo hiện tại: <b>' + esc(qty) + '</b></span>';

        dom.cfgConvertToHtsoft.value = formatRatio(mapping && mapping.convert_to_htsoft ? mapping.convert_to_htsoft : 1);
        dom.cfgMappingId.value = mapping && mapping.global_htsoft_stock_convert_id ? String(mapping.global_htsoft_stock_convert_id) : '';
        dom.cfgFormMode.textContent = mapping ? 'Chỉnh sửa' : 'Thêm mới';
        dom.cfgNote.value = (mapping && mapping.convert_note)
            ? mapping.convert_note
            : defaultNoteForUnit(product.unit, formatRatio(mapping && mapping.convert_to_htsoft ? mapping.convert_to_htsoft : 1));
    }

    async function selectProduct(product) {
        fillMappingForm(product, null);

        try {
            const data = await postAjax('tgs_htsoft_converter_get_mapping_by_sku', {
                global_product_sku: product.sku,
            });
            const mapping = data.mapping || null;
            if (mapping) {
                fillMappingForm({
                    sku: product.sku,
                    name: product.name || mapping.local_product_name || '',
                    unit: product.unit || mapping.local_product_unit || '',
                }, mapping);
            }
        } catch (err) {
            toast(err.message, 'error');
        }
    }

    function resetForm() {
        state.selectedProduct = null;
        dom.cfgMappingId.value = '';
        dom.cfgSku.value = '';
        dom.cfgProductName.value = '';
        dom.cfgUnit.value = '';
        dom.cfgConvertToHtsoft.value = '1';
        dom.cfgNote.value = '';
        dom.cfgFormMode.textContent = 'Thêm mới';
    }

    async function saveMapping() {
        if (!dom.cfgSku.value || !dom.cfgSku.value.trim()) {
            toast('Hãy chọn sản phẩm trước khi lưu cấu hình.', 'warning');
            return;
        }

        const ratio = parseNumber(dom.cfgConvertToHtsoft.value);
        if (ratio <= 0) {
            toast('Hệ số quy đổi phải lớn hơn 0.', 'warning');
            return;
        }

        try {
            await postAjax('tgs_htsoft_converter_save_mapping', {
                id: dom.cfgMappingId.value || 0,
                global_product_sku: dom.cfgSku.value,
                convert_to_htsoft: ratio,
                convert_note: dom.cfgNote.value || '',
            });

            toast('Đã lưu cấu hình quy đổi.', 'success');
            await loadMappings();
            await selectProduct({
                sku: dom.cfgSku.value,
                name: dom.cfgProductName.value,
                unit: dom.cfgUnit.value,
            });
        } catch (err) {
            toast(err.message, 'error');
        }
    }

    async function loadMappings() {
        try {
            const data = await postAjax('tgs_htsoft_converter_list_mappings', {
                keyword: dom.mappingKeyword.value || '',
                limit: 200,
            });

            const rows = data.mappings || [];
            state.mapRows = rows;

            if (!rows.length) {
                dom.mappingTableBody.innerHTML = '<tr><td colspan="7" class="text-center text-muted py-3">Chưa có cấu hình nào.</td></tr>';
                return;
            }

            dom.mappingTableBody.innerHTML = rows.map((row) => {
                return '<tr>'
                    + '<td class="text-nowrap fw-semibold">' + esc(row.global_product_sku || '') + '</td>'
                    + '<td>' + esc(row.local_product_name || '-') + '</td>'
                    + '<td>' + esc(row.local_product_unit || '-') + '</td>'
                    // SL tham khảo logs column removed
                    + '<td><span class="badge bg-label-primary">1 -> ' + esc(formatRatio(row.convert_to_htsoft || '1')) + '</span></td>'
                    + '<td><span class="tgs-note-cell">' + esc(row.convert_note || '') + '</span></td>'
                        + '<td><button type="button" class="btn btn-sm btn-outline-primary btn-edit-mapping" data-id="' + esc(row.global_htsoft_stock_convert_id) + '"><i class="bx bx-edit"></i></button></td>'
                    + '</tr>';
            }).join('');

        } catch (err) {
            toast(err.message, 'error');
        }
    }

    async function editMapping(id) {
        if (!id) return;

        try {
            const data = await postAjax('tgs_htsoft_converter_get_mapping', { id: id });
            const m = data.mapping || null;
            if (!m) return;

            fillMappingForm({
                sku: m.global_product_sku || '',
                name: m.local_product_name || '',
                unit: m.local_product_unit || '',
            }, m);

            window.scrollTo({ top: 0, behavior: 'smooth' });
        } catch (err) {
            toast(err.message, 'error');
        }
    }

    async function exportMappingsJson() {
        try {
            const data = await postAjax('tgs_htsoft_converter_export_mappings_json', {});
            const payload = {
                exported_at: data.exported_at || '',
                count: data.count || 0,
                mappings: data.mappings || [],
            };
            const blob = new Blob([JSON.stringify(payload, null, 2)], { type: 'application/json' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'tgs-htsoft-mappings-' + new Date().toISOString().slice(0, 10) + '.json';
            document.body.appendChild(a);
            a.click();
            a.remove();
            window.URL.revokeObjectURL(url);
            toast('Đã xuất file JSON cấu hình.', 'success');
        } catch (err) {
            toast(err.message, 'error');
        }
    }

    async function importMappingsJson() {
        const file = dom.mappingJsonFile.files && dom.mappingJsonFile.files[0];
        if (!file) {
            return;
        }

        try {
            const data = await postAjax('tgs_htsoft_converter_import_mappings_json', {
                json_file: file,
            });

            toast(
                'Import xong JSON. Thêm mới: ' + (data.created || 0)
                    + ', cập nhật: ' + (data.updated || 0)
                    + ', bỏ qua: ' + (data.skipped || 0),
                'success'
            );

            await loadMappings();
            if (dom.cfgSku.value) {
                await selectProduct({
                    sku: dom.cfgSku.value,
                    name: dom.cfgProductName.value,
                    unit: dom.cfgUnit.value,
                });
            }
        } catch (err) {
            toast(err.message, 'error');
        } finally {
            dom.mappingJsonFile.value = '';
        }
    }

    function stopScanner() {
        if (state.scanner && typeof state.scanner.stop === 'function') {
            state.scanner.stop();
        }
        state.scanner = null;
        dom.scannerWrap.classList.add('d-none');
    }

    function startScanner() {
        if (typeof window.TGSBarcodeScanner === 'undefined') {
            toast('Chưa tải được scanner. Vui lòng thử lại.', 'warning');
            return;
        }

        stopScanner();
        dom.scannerWrap.classList.remove('d-none');

        state.scanner = new window.TGSBarcodeScanner({
            containerId: 'scanCameraPreview',
            onSuccess: (result) => {
                const now = Date.now();
                if (now - state.scannerCooldownAt < 1200) {
                    return;
                }
                state.scannerCooldownAt = now;

                const code = (result && result.text) ? String(result.text) : String(result || '');
                if (!code) return;

                dom.cfgSearchKeyword.value = code;
                stopScanner();
                searchProducts();
            },
            onError: function () {},
            onStatusChange: function () {},
        });

        state.scanner.start().catch((err) => {
            toast('Không mở được camera: ' + err.message, 'error');
            stopScanner();
        });
    }

    function ensureXlsx() {
        if (!window.XLSX) {
            throw new Error('Thư viện XLSX chưa sẵn sàng. Vui lòng tải lại trang.');
        }
    }

    function resetSheetSelector(selectEl) {
        if (!selectEl) {
            return;
        }

        selectEl.innerHTML = '<option value="">File chỉ có 1 tab hoặc chưa chọn file</option>';
        selectEl.disabled = true;
    }

    function fillSheetSelector(selectEl, sheetNames) {
        if (!selectEl) {
            return;
        }

        if (!sheetNames.length) {
            resetSheetSelector(selectEl);
            return;
        }

        selectEl.innerHTML = sheetNames.map(function (sheetName) {
            return '<option value="' + esc(sheetName) + '">' + esc(sheetName) + '</option>';
        }).join('');
        selectEl.disabled = sheetNames.length <= 1;
    }

    async function loadWorkbook(file) {
        ensureXlsx();
        const buffer = await file.arrayBuffer();
        return window.XLSX.read(buffer, { type: 'array' });
    }

    function readRowsFromWorkbook(workbook, sheetName) {
        const sheetNames = (workbook && workbook.SheetNames) ? workbook.SheetNames : [];
        if (!sheetNames.length) {
            return [];
        }

        const targetSheetName = sheetName && sheetNames.includes(sheetName) ? sheetName : sheetNames[0];
        const sheet = workbook.Sheets[targetSheetName];
        if (!sheet) {
            return [];
        }

        return window.XLSX.utils.sheet_to_json(sheet, { header: 1, raw: false }) || [];
    }

    async function prepareWorkbook(file, stateKey, selectEl) {
        if (!file) {
            state[stateKey] = null;
            resetSheetSelector(selectEl);
            return null;
        }

        const workbook = await loadWorkbook(file);
        state[stateKey] = workbook;
        fillSheetSelector(selectEl, workbook.SheetNames || []);
        return workbook;
    }

    async function getRowsFromSelectedWorkbook(fileEl, stateKey, selectEl) {
        const file = fileEl.files && fileEl.files[0];
        if (!file) {
            throw new Error('Vui lòng chọn file Excel/CSV trước.');
        }

        let workbook = state[stateKey];
        if (!workbook) {
            workbook = await prepareWorkbook(file, stateKey, selectEl);
        }

        return readRowsFromWorkbook(workbook, selectEl && selectEl.value ? selectEl.value : '');
    }

    async function handleWorkbookFileChange(fileEl, stateKey, selectEl, warningMessage) {
        const file = fileEl.files && fileEl.files[0];
        state[stateKey] = null;
        resetSheetSelector(selectEl);

        if (!file) {
            return;
        }

        const workbook = await prepareWorkbook(file, stateKey, selectEl);
        if (workbook && workbook.SheetNames && workbook.SheetNames.length > 1) {
            toast(warningMessage, 'warning');
        }
    }

    function normalizeInputRows(rows) {
        const result = [];

        for (let i = 0; i < rows.length; i++) {
            const row = rows[i] || [];
            const colA = String(row[0] == null ? '' : row[0]);
            const colB = String(row[1] == null ? '' : row[1]);
            const colC = String(row[2] == null ? '' : row[2]);

            if (!colA.trim()) {
                continue;
            }

            if (i === 0) {
                const headerA = colA.trim().toLowerCase();
                const maybeHeader = headerA === 'ma hang' || headerA === 'sku' || headerA === 'mã hàng';
                if (maybeHeader) {
                    continue;
                }
            }

            result.push({
                sku: colA,
                name: colB,
                qty: parseNumber(colC),
            });
        }

        return result;
    }

    async function fetchMappingsForSkus(skus) {
        const data = await postAjax('tgs_htsoft_converter_get_mappings_by_skus', {
            skus: JSON.stringify(skus),
        });
        return data.mappings || {};
    }

    function renderConversionRows(tbodyEl, rows, mode) {
        if (!rows.length) {
            tbodyEl.innerHTML = '<tr><td colspan="5" class="text-center text-muted py-3">Chưa có dữ liệu quy đổi.</td></tr>';
            return;
        }

        tbodyEl.innerHTML = rows.map((row) => {
            const fromQty = mode === 'htsoft_to_tgs' ? row.qty_htsoft : row.qty_tgs;
            const toQty = mode === 'htsoft_to_tgs' ? row.qty_tgs : row.qty_htsoft;
            return '<tr>'
                + '<td class="text-nowrap">' + esc(row.sku) + '</td>'
                + '<td>' + esc(row.name) + '</td>'
                + '<td class="text-end">' + esc(fromQty) + '</td>'
                + '<td class="text-end">' + esc(formatRatio(row.factor)) + '</td>'
                + '<td class="text-end fw-semibold">' + esc(toQty) + '</td>'
                + '</tr>';
        }).join('');
    }

    async function convertHtsoftToTgs() {
        try {
            const file = dom.fileHtsoftToTgs.files && dom.fileHtsoftToTgs.files[0];
            if (!file) {
                toast('Vui lòng chọn file cho Tab 2.', 'warning');
                return;
            }

            const rawRows = await getRowsFromSelectedWorkbook(dom.fileHtsoftToTgs, 'workbookHtsoftToTgs', dom.sheetHtsoftToTgs);
            const rows = normalizeInputRows(rawRows);
            if (!rows.length) {
                toast('Không có dữ liệu hợp lệ trong file.', 'warning');
                return;
            }

            const skus = [...new Set(rows.map((r) => r.sku))];
            const map = await fetchMappingsForSkus(skus);

            state.resultHtsoftToTgs = rows.map((r) => {
                const cfg = map[r.sku] || null;
                const factor = cfg ? parseNumber(cfg.convert_to_htsoft) : 1;
                const safeFactor = factor > 0 ? factor : 1;
                const qtyTgs = Math.ceil((parseNumber(r.qty) || 0) / safeFactor);

                return {
                    sku: r.sku,
                    name: r.name,
                    qty_htsoft: parseNumber(r.qty),
                    factor: safeFactor,
                    qty_tgs: qtyTgs,
                };
            });

            renderConversionRows(dom.tbodyHtsoftToTgs, state.resultHtsoftToTgs, 'htsoft_to_tgs');
            dom.btnExportHtsoftToTgs.disabled = false;
            toast('Đã quy đổi xong HTSoft -> TGS.', 'success');
        } catch (err) {
            toast(err.message, 'error');
        }
    }

    async function convertTgsToHtsoft() {
        try {
            const file = dom.fileTgsToHtsoft.files && dom.fileTgsToHtsoft.files[0];
            if (!file) {
                toast('Vui lòng chọn file cho Tab 3.', 'warning');
                return;
            }

            const rawRows = await getRowsFromSelectedWorkbook(dom.fileTgsToHtsoft, 'workbookTgsToHtsoft', dom.sheetTgsToHtsoft);
            const rows = normalizeInputRows(rawRows);
            if (!rows.length) {
                toast('Không có dữ liệu hợp lệ trong file.', 'warning');
                return;
            }

            const skus = [...new Set(rows.map((r) => r.sku))];
            const map = await fetchMappingsForSkus(skus);

            state.resultTgsToHtsoft = rows.map((r) => {
                const cfg = map[r.sku] || null;
                const factor = cfg ? parseNumber(cfg.convert_to_htsoft) : 1;
                const safeFactor = factor > 0 ? factor : 1;
                const qtyHtsoft = (parseNumber(r.qty) || 0) * safeFactor;

                return {
                    sku: r.sku,
                    name: r.name,
                    qty_tgs: parseNumber(r.qty),
                    factor: safeFactor,
                    qty_htsoft: qtyHtsoft,
                };
            });

            renderConversionRows(dom.tbodyTgsToHtsoft, state.resultTgsToHtsoft, 'tgs_to_htsoft');
            dom.btnExportTgsToHtsoft.disabled = false;
            toast('Đã quy đổi xong TGS -> HTSoft.', 'success');
        } catch (err) {
            toast(err.message, 'error');
        }
    }

    function exportResult(rows, filename, qtyField) {
        try {
            ensureXlsx();
            if (!rows.length) {
                toast('Chưa có dữ liệu để xuất.', 'warning');
                return;
            }

            const exportRows = rows.map((r) => ({
                'Mã hàng': r.sku,
                'Tên hàng': r.name,
                'Số lượng': r[qtyField],
            }));

            const ws = window.XLSX.utils.json_to_sheet(exportRows);
            const wb = window.XLSX.utils.book_new();
            window.XLSX.utils.book_append_sheet(wb, ws, 'Converted');
            window.XLSX.writeFile(wb, filename);
        } catch (err) {
            toast(err.message, 'error');
        }
    }

    function bindEvents() {
        dom.btnCfgSearch.addEventListener('click', searchProducts);
        dom.cfgSearchKeyword.addEventListener('input', function () {
            scheduleSearch();
        });
        dom.cfgSearchKeyword.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                if (state.searchTimer) {
                    window.clearTimeout(state.searchTimer);
                }
                searchProducts();
            }
        });

        dom.btnOpenScanner.addEventListener('click', startScanner);
        dom.btnCloseScanner.addEventListener('click', stopScanner);

        dom.cfgSearchResults.addEventListener('click', function (e) {
            const el = e.target.closest('.tgs-product-item');
            if (!el) return;

            selectProduct({
                sku: el.getAttribute('data-sku') || '',
                name: el.getAttribute('data-name') || '',
                unit: el.getAttribute('data-unit') || '',
                barcode: el.getAttribute('data-barcode') || '',
            });
        });

        dom.mappingTableBody.addEventListener('click', function (e) {
            const btn = e.target.closest('.btn-edit-mapping');
            if (!btn) return;
            editMapping(Number(btn.getAttribute('data-id') || 0));
        });

        dom.btnSaveMapping.addEventListener('click', saveMapping);
        dom.btnResetForm.addEventListener('click', resetForm);

        dom.btnReloadMappings.addEventListener('click', loadMappings);
        dom.btnExportMappingsJson.addEventListener('click', exportMappingsJson);
        dom.btnImportMappingsJson.addEventListener('click', function () {
            dom.mappingJsonFile.click();
        });
        dom.mappingJsonFile.addEventListener('change', importMappingsJson);
        dom.mappingKeyword.addEventListener('input', function () {
            scheduleMappingSearch();
        });
        dom.mappingKeyword.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                if (state.mappingSearchTimer) {
                    window.clearTimeout(state.mappingSearchTimer);
                }
                loadMappings();
            }
        });

        dom.fileHtsoftToTgs.addEventListener('change', async function () {
            state.resultHtsoftToTgs = [];
            dom.tbodyHtsoftToTgs.innerHTML = '';
            dom.btnExportHtsoftToTgs.disabled = true;

            try {
                await handleWorkbookFileChange(
                    dom.fileHtsoftToTgs,
                    'workbookHtsoftToTgs',
                    dom.sheetHtsoftToTgs,
                    'File Tab 2 có nhiều tab. Hãy chọn đúng tab dữ liệu trước khi quy đổi.'
                );
            } catch (err) {
                toast(err.message, 'error');
            }
        });

        dom.fileTgsToHtsoft.addEventListener('change', async function () {
            state.resultTgsToHtsoft = [];
            dom.tbodyTgsToHtsoft.innerHTML = '';
            dom.btnExportTgsToHtsoft.disabled = true;

            try {
                await handleWorkbookFileChange(
                    dom.fileTgsToHtsoft,
                    'workbookTgsToHtsoft',
                    dom.sheetTgsToHtsoft,
                    'File Tab 3 có nhiều tab. Hãy chọn đúng tab dữ liệu trước khi quy đổi.'
                );
            } catch (err) {
                toast(err.message, 'error');
            }
        });

        dom.btnConvertHtsoftToTgs.addEventListener('click', convertHtsoftToTgs);
        dom.btnConvertTgsToHtsoft.addEventListener('click', convertTgsToHtsoft);

        dom.btnExportHtsoftToTgs.addEventListener('click', function () {
            exportResult(state.resultHtsoftToTgs, 'htsoft-to-tgs-converted.xlsx', 'qty_tgs');
        });

        dom.btnExportTgsToHtsoft.addEventListener('click', function () {
            exportResult(state.resultTgsToHtsoft, 'tgs-to-htsoft-converted.xlsx', 'qty_htsoft');
        });

        window.addEventListener('beforeunload', stopScanner);
    }

    function init() {
        if (!CFG.ajaxUrl || !CFG.nonce) {
            return;
        }

        bindEvents();
        loadMappings();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
