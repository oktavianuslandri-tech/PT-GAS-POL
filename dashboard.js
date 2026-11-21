const $ = (selector) => document.querySelector(selector);
const $$ = (selector) => [...document.querySelectorAll(selector)];
const numberFormat = new Intl.NumberFormat('id-ID');
const dateFormat = new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium', timeStyle: 'short' });
const todayFormat = new Intl.DateTimeFormat('id-ID', { dateStyle: 'full' });

let warehouses = [];
let stocks = [];
let restockOrders = [];
let purchaseRequests = [];
let stockMovements = [];
let toastTimer;
const loaded = { warehouses: false, stocks: false, restock: false, purchases: false };

const viewTitles = {
  overview: 'Ringkasan',
  warehouses: 'Gudang',
  stocks: 'Persediaan',
  restock: 'Restock retail',
  purchases: 'Pembelian supplier',
  receipts: 'Penerimaan barang',
  movements: 'Riwayat stok',
};

function escapeHtml(value) {
  return String(value ?? '').replace(/[&<>"']/g, (char) => ({
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#39;',
  })[char]);
}

function formatNumber(value) {
  const parsed = Number(value);
  return Number.isFinite(parsed) ? numberFormat.format(parsed) : '—';
}

function formatDate(value) {
  if (!value) return '—';
  const date = new Date(String(value).replace(' ', 'T'));
  return Number.isNaN(date.getTime()) ? escapeHtml(value) : dateFormat.format(date);
}

function showToast(message, type = 'success') {
  const toast = $('#toast');
  toast.textContent = message;
  toast.classList.toggle('error', type === 'error');
  toast.hidden = false;
  window.clearTimeout(toastTimer);
  toastTimer = window.setTimeout(() => { toast.hidden = true; }, 5000);
}

function setConnection(online, label) {
  $('#connectionDot').classList.toggle('online', online);
  $('#connectionDot').classList.toggle('offline', !online);
  $('#connectionLabel').textContent = label;
}

function apiUrl(path) {
  const base = new URL('.', window.location.href);
  return new URL(path.replace(/^\/+/, ''), base);
}

async function request(path, options = {}) {
  const { method = 'GET', body, role = 'manager', auth = true } = options;
  const headers = { Accept: 'application/json' };
  if (body !== undefined) headers['Content-Type'] = 'application/json';
  if (auth) {
    const token = role === 'owner' ? sessionStorage.getItem('warehouse-owner-token') : sessionStorage.getItem('warehouse-manager-token');
    if (!token) throw new Error(`Simpan token ${role === 'owner' ? 'owner' : 'warehouse manager'} terlebih dahulu.`);
    headers.Authorization = `Bearer ${token}`;
  }

  let response;
  try {
    response = await fetch(apiUrl(path), {
      method,
      headers,
      body: body === undefined ? undefined : JSON.stringify(body),
    });
  } catch {
    throw new Error('Tidak dapat terhubung ke API. Periksa server Apache dan koneksi jaringan.');
  }

  let data;
  try {
    data = await response.json();
  } catch {
    throw new Error(`API mengembalikan respons yang tidak valid (HTTP ${response.status}).`);
  }
  if (!response.ok) {
    throw new Error(data.error || `Permintaan gagal (HTTP ${response.status}).`);
  }
  return data;
}

function setHint(selector, message, isError = false) {
  const element = $(selector);
  element.textContent = message;
  element.classList.toggle('hint-error', isError);
}

function emptyRow(columns, message = 'Belum ada data untuk ditampilkan.') {
  return `<tr><td class="empty-row" colspan="${columns}">${escapeHtml(message)}</td></tr>`;
}

function statusPill(status) {
  const styles = {
    ACTIVE: 'pill-success',
    WAITING_SUPPLIER: 'pill-warning',
    RESERVED: 'pill-info',
    SHIPPED: 'pill-success',
    DELIVERED: 'pill-success',
    RECEIVED: 'pill-success',
    PARTIALLY_RECEIVED: 'pill-warning',
    REJECTED: 'pill-danger',
    REQUESTED: 'pill-info',
    CONFIRMED: 'pill-info',
    INACTIVE: 'pill-neutral',
    LOW_STOCK: 'pill-low',
  };
  return `<span class="pill ${styles[status] || ''}">${escapeHtml(status || '—')}</span>`;
}

function itemSummary(items) {
  if (!Array.isArray(items) || items.length === 0) return 'Tidak ada item';
  return items.map((item) => `${escapeHtml(item.product_id)} × ${formatNumber(item.quantity)}`).join('<br>');
}

function renderWarehouses() {
  $('#warehouseCount').textContent = formatNumber(warehouses.length);
  $('#warehouseRows').innerHTML = warehouses.length ? warehouses.map((warehouse) => `
    <tr>
      <td><strong>${escapeHtml(warehouse.code)}</strong></td>
      <td>${escapeHtml(warehouse.name)}</td>
      <td>${escapeHtml(warehouse.address || '—')}</td>
      <td>${Number(warehouse.is_active) ? statusPill('ACTIVE') : statusPill('INACTIVE')}</td>
      <td class="align-right">
        <button class="button button-small button-secondary" type="button" data-edit-warehouse="${escapeHtml(warehouse.id)}">Edit</button>
        <button class="button button-small button-danger" type="button" data-delete-warehouse="${escapeHtml(warehouse.id)}">Hapus</button>
      </td>
    </tr>`).join('') : emptyRow(5, 'Belum ada gudang atau token owner belum diatur.');
  $('#warehouseOptions').innerHTML = warehouses.map((warehouse) =>
    `<option value="${escapeHtml(warehouse.id)}">${escapeHtml(warehouse.code)} — ${escapeHtml(warehouse.name)}</option>`
  ).join('');
}

function renderStocks() {
  $('#stockRows').innerHTML = stocks.length ? stocks.map((stock) => `
    <tr>
      <td><strong>${escapeHtml(stock.product_id)}</strong></td>
      <td>${escapeHtml(stock.warehouse_name || stock.warehouse_code || stock.warehouse_id)}</td>
      <td>${formatNumber(stock.quantity)}</td>
      <td>${formatNumber(stock.reserved)}</td>
      <td><strong>${formatNumber(stock.available)}</strong></td>
      <td>${formatNumber(stock.min_stock)}</td>
      <td>${formatDate(stock.updated_at)}</td>
    </tr>`).join('') : emptyRow(7);
}

function renderRestockOrders() {
  $('#restockRows').innerHTML = restockOrders.length ? restockOrders.map((order) => {
    const canReject = ['WAITING_SUPPLIER', 'RESERVED'].includes(order.status);
    return `<tr>
      <td><strong>${escapeHtml(order.id)}</strong></td>
      <td>${escapeHtml(order.retail_id)}</td>
      <td>${escapeHtml(order.warehouse_id)}</td>
      <td>${itemSummary(order.items)}</td>
      <td>${statusPill(order.status)}</td>
      <td>${formatDate(order.created_at)}</td>
      <td class="align-right">${canReject ? `<button class="button button-small button-danger" type="button" data-reject-order="${escapeHtml(order.id)}">Tolak</button>` : '—'}</td>
    </tr>`;
  }).join('') : emptyRow(7);
}

function renderPurchaseRequests() {
  $('#purchaseCount').textContent = formatNumber(purchaseRequests.length);
  const rows = purchaseRequests.map((request) => `
    <tr>
      <td><strong>${escapeHtml(request.id)}</strong></td>
      <td>${escapeHtml(request.po_id || 'Belum ada')}</td>
      <td>${escapeHtml(request.supplier_id)}</td>
      <td>${itemSummary(request.items)}</td>
      <td>${statusPill(request.status)}</td>
      <td>${formatDate(request.created_at)}</td>
    </tr>`).join('');
  $('#purchaseRows').innerHTML = rows || emptyRow(6);
  const readyRequests = purchaseRequests.filter((request) => ['DELIVERED', 'PARTIALLY_RECEIVED'].includes(request.status));
  const readyRows = readyRequests.map((request) => `
    <tr>
      <td><strong>${escapeHtml(request.id)}</strong></td>
      <td>${escapeHtml(request.po_id || 'Gunakan request ID')}</td>
      <td>${escapeHtml(request.supplier_id)}</td>
      <td>${request.items?.filter((item) => Number(item.received_quantity) < Number(item.quantity)).length || 0} item tersisa</td>
      <td>${statusPill(request.status)}</td>
      <td>${formatDate(request.created_at)}</td>
    </tr>`).join('');
  $('#receiptPurchaseRows').innerHTML = readyRows || emptyRow(6);
  $('#purchaseOptions').innerHTML = purchaseRequests.map((request) =>
    `<option value="${escapeHtml(request.po_id || request.id)}">${escapeHtml(request.supplier_id)} · ${escapeHtml(request.status)}</option>`
  ).join('');
}

function renderMovements() {
  $('#movementRows').innerHTML = stockMovements.length ? stockMovements.map((movement) => `
    <tr>
      <td>${formatDate(movement.created_at)}</td>
      <td><strong>${escapeHtml(movement.product_id)}</strong></td>
      <td>${statusPill(movement.movement_type)}</td>
      <td>${formatNumber(movement.quantity)}</td>
      <td>${formatNumber(movement.quantity_before)} → ${formatNumber(movement.quantity_after)}</td>
      <td>${formatNumber(movement.reserved_before)} → ${formatNumber(movement.reserved_after)}</td>
      <td>${escapeHtml(movement.reference_type)} · ${escapeHtml(movement.reference_id)}</td>
    </tr>`).join('') : emptyRow(7);
}

function renderOverview() {
  const lowStocks = stocks.filter((stock) =>
    Number(stock.min_stock) > 0 && Number(stock.available) <= Number(stock.min_stock)
  );
  const waiting = restockOrders.filter((order) => order.status === 'WAITING_SUPPLIER');
  $('#metricWarehouses').textContent = loaded.warehouses ? formatNumber(warehouses.length) : '—';
  $('#metricProducts').textContent = loaded.stocks ? formatNumber(stocks.length) : '—';
  $('#metricLowStock').textContent = loaded.stocks ? formatNumber(lowStocks.length) : '—';
  $('#metricWaiting').textContent = loaded.restock ? formatNumber(waiting.length) : '—';

  $('#overviewStockRows').innerHTML = lowStocks.length ? lowStocks.slice(0, 6).map((stock) => `
    <tr>
      <td><strong>${escapeHtml(stock.product_id)}</strong></td>
      <td>${escapeHtml(stock.warehouse_name || stock.warehouse_code || stock.warehouse_id)}</td>
      <td>${formatNumber(stock.available)}</td>
      <td>${formatNumber(stock.min_stock)}</td>
      <td>${statusPill('LOW_STOCK')}</td>
    </tr>`).join('') : emptyRow(5, 'Tidak ada stok di bawah minimum.');

  const latest = [...restockOrders].sort((a, b) => new Date(b.created_at) - new Date(a.created_at)).slice(0, 5);
  $('#overviewRestockRows').innerHTML = latest.length ? latest.map((order) => `
    <div class="compact-item">
      <div><strong>${escapeHtml(order.id)}</strong><small>${escapeHtml(order.retail_id)} · ${formatDate(order.created_at)}</small></div>
      ${statusPill(order.status)}
    </div>`).join('') : '<p class="table-hint">Belum ada permintaan restock.</p>';
}

function formValues(form) {
  return Object.fromEntries(new FormData(form).entries());
}

function formFilter(form) {
  const values = formValues(form);
  const query = new URLSearchParams();
  Object.entries(values).forEach(([key, value]) => {
    if (String(value).trim() !== '') query.set(key, String(value).trim());
  });
  return query.size ? `?${query}` : '';
}

function parseItems(text, allowWarehouse) {
  const lines = text.split(/\r?\n/).map((line) => line.trim()).filter(Boolean);
  if (!lines.length) throw new Error('Tambahkan minimal satu item.');
  return lines.map((line, index) => {
    const [productId, rawQuantity, rawWarehouse, ...extra] = line.split(',').map((part) => part.trim());
    const quantity = Number(rawQuantity);
    if (!productId || !Number.isSafeInteger(quantity) || quantity <= 0 || extra.length > 0) {
      throw new Error(`Format item baris ${index + 1} harus product_id, quantity${allowWarehouse ? '[, warehouse_id]' : ''}.`);
    }
    if (!allowWarehouse && rawWarehouse) {
      throw new Error(`Baris ${index + 1}: warehouse_id hanya diperbolehkan untuk penerimaan barang.`);
    }
    const item = { product_id: productId, quantity };
    if (allowWarehouse && rawWarehouse) item.warehouse_id = rawWarehouse;
    return item;
  });
}

async function loadWarehouses() {
  if (!sessionStorage.getItem('warehouse-owner-token')) {
    warehouses = [];
    loaded.warehouses = false;
    renderWarehouses();
    setHint('#warehouseHint', 'Atur token owner untuk melihat dan mengelola master gudang.', true);
    return;
  }
  warehouses = await request('/warehouses', { role: 'owner' });
  loaded.warehouses = true;
  renderWarehouses();
  setHint('#warehouseHint', `${formatNumber(warehouses.length)} gudang ditemukan.`);
}

async function loadStocks() {
  stocks = await request(`/stocks${formFilter($('#stockFilterForm'))}`);
  loaded.stocks = true;
  renderStocks();
  setHint('#stockHint', `${formatNumber(stocks.length)} baris persediaan ditemukan.`);
}

async function loadRestockOrders() {
  restockOrders = await request(`/restock-orders${formFilter($('#restockFilterForm'))}`);
  loaded.restock = true;
  renderRestockOrders();
  setHint('#restockHint', `${formatNumber(restockOrders.length)} order ditemukan.`);
}

async function loadPurchases() {
  purchaseRequests = await request('/purchase-requests');
  loaded.purchases = true;
  renderPurchaseRequests();
  setHint('#purchaseHint', `${formatNumber(purchaseRequests.length)} purchase request ditemukan.`);
}

async function loadMovements() {
  stockMovements = await request(`/stock-movements${formFilter($('#movementFilterForm'))}`);
  renderMovements();
  setHint('#movementHint', `${formatNumber(stockMovements.length)} movement ditemukan. Maksimal 500 catatan.`);
}

async function loadOverview() {
  const results = await Promise.allSettled([
    loadWarehouses(),
    loadStocks(),
    loadRestockOrders(),
    loadPurchases(),
  ]);
  renderOverview();
  const failures = results.filter((result) => result.status === 'rejected');
  if (failures.length) {
    showToast([...new Set(failures.map((result) => result.reason.message))].join(' '), 'error');
  }
}

async function loadCurrentView(view) {
  const loaders = {
    overview: loadOverview,
    warehouses: loadWarehouses,
    stocks: loadStocks,
    restock: loadRestockOrders,
    purchases: loadPurchases,
    receipts: loadPurchases,
    movements: loadMovements,
  };
  try {
    await loaders[view]();
  } catch (error) {
    showToast(error.message, 'error');
    const hints = {
      warehouses: '#warehouseHint',
      stocks: '#stockHint',
      restock: '#restockHint',
      purchases: '#purchaseHint',
      movements: '#movementHint',
    };
    if (hints[view]) setHint(hints[view], error.message, true);
  }
}

function navigate(view) {
  if (!viewTitles[view]) return;
  $$('.view').forEach((section) => section.classList.toggle('active', section.id === `view-${view}`));
  $$('.nav-link').forEach((button) => button.classList.toggle('active', button.dataset.view === view));
  $('#pageTitle').textContent = viewTitles[view];
  $('#sidebar').classList.remove('open');
  loadCurrentView(view);
}

function resetWarehouseDialog(warehouse = null) {
  const form = $('#warehouseForm');
  form.reset();
  form.elements.id.value = warehouse?.id || '';
  form.elements.code.value = warehouse?.code || '';
  form.elements.name.value = warehouse?.name || '';
  form.elements.address.value = warehouse?.address || '';
  $('#warehouseDialogTitle').textContent = warehouse ? 'Edit gudang' : 'Tambah gudang';
  $('#warehouseActiveRow').hidden = !warehouse;
  form.elements.is_active.checked = warehouse ? Boolean(Number(warehouse.is_active)) : true;
  $('#warehouseDialog').showModal();
}

async function saveWarehouse(event) {
  event.preventDefault();
  const form = $('#warehouseForm');
  const id = form.elements.id.value;
  const body = {
    code: form.elements.code.value.trim(),
    name: form.elements.name.value.trim(),
    address: form.elements.address.value.trim() || null,
  };
  if (id) body.is_active = form.elements.is_active.checked;
  try {
    await request(id ? `/warehouses/${encodeURIComponent(id)}` : '/warehouses', {
      method: id ? 'PATCH' : 'POST',
      role: 'owner',
      body,
    });
    $('#warehouseDialog').close();
    showToast(id ? 'Data gudang diperbarui.' : 'Gudang berhasil ditambahkan.');
    await loadWarehouses();
    renderOverview();
  } catch (error) {
    showToast(error.message, 'error');
  }
}

async function handleWarehouseAction(event) {
  const editButton = event.target.closest('[data-edit-warehouse]');
  const deleteButton = event.target.closest('[data-delete-warehouse]');
  if (editButton) {
    const warehouse = warehouses.find((item) => item.id === editButton.dataset.editWarehouse);
    if (warehouse) resetWarehouseDialog(warehouse);
    return;
  }
  if (deleteButton) {
    const warehouse = warehouses.find((item) => item.id === deleteButton.dataset.deleteWarehouse);
    if (!warehouse || !window.confirm(`Hapus gudang ${warehouse.code}? Gudang yang sudah dipakai tidak dapat dihapus.`)) return;
    try {
      await request(`/warehouses/${encodeURIComponent(warehouse.id)}`, { method: 'DELETE', role: 'owner' });
      showToast('Gudang berhasil dihapus.');
      await loadWarehouses();
      renderOverview();
    } catch (error) {
      showToast(error.message, 'error');
    }
  }
}

async function saveAdjustment(event) {
  event.preventDefault();
  const values = formValues(event.currentTarget);
  const body = {
    warehouse_id: values.warehouse_id.trim(),
    product_id: values.product_id.trim(),
  };
  if (values.quantity_delta !== '') body.quantity_delta = Number(values.quantity_delta);
  if (values.min_stock !== '') body.min_stock = Number(values.min_stock);
  if (values.reason.trim()) body.reason = values.reason.trim();
  try {
    await request('/stocks/adjustments', { method: 'POST', body });
    showToast('Penyesuaian stok berhasil disimpan.');
    event.currentTarget.reset();
    await loadStocks();
    renderOverview();
  } catch (error) {
    showToast(error.message, 'error');
  }
}

async function createPurchase(event) {
  event.preventDefault();
  const values = formValues(event.currentTarget);
  try {
    const body = {
      supplier_id: values.supplier_id.trim(),
      items: parseItems(values.items, true),
    };
    if (values.warehouse_id.trim()) body.warehouse_id = values.warehouse_id.trim();
    await request('/purchase-requests', { method: 'POST', body });
    showToast('Purchase request berhasil dibuat.');
    event.currentTarget.reset();
    await loadPurchases();
    renderOverview();
  } catch (error) {
    showToast(error.message, 'error');
  }
}

async function receiveGoods(event) {
  event.preventDefault();
  const values = formValues(event.currentTarget);
  try {
    const body = {
      po_id: values.po_id.trim(),
      items: parseItems(values.items, true),
    };
    await request('/goods-receipts', { method: 'POST', body });
    showToast('Penerimaan barang berhasil dicatat.');
    event.currentTarget.reset();
    await Promise.all([loadPurchases(), loadStocks(), loadRestockOrders()]);
    renderOverview();
  } catch (error) {
    showToast(error.message, 'error');
  }
}

async function rejectOrder(event) {
  const button = event.target.closest('[data-reject-order]');
  if (!button) return;
  const reason = window.prompt('Alasan penolakan (opsional):');
  if (reason === null) return;
  try {
    await request(`/restock-orders/${encodeURIComponent(button.dataset.rejectOrder)}/reject`, {
      method: 'POST',
      body: { reason },
    });
    showToast('Permintaan restock ditolak.');
    await loadRestockOrders();
    renderOverview();
  } catch (error) {
    showToast(error.message, 'error');
  }
}

async function pingApi() {
  try {
    await request('/health', { auth: false });
    setConnection(true, 'API terhubung');
  } catch {
    setConnection(false, 'API tidak terhubung');
  }
}

function saveTokens(event) {
  event.preventDefault();
  const managerToken = $('#managerToken').value.trim();
  const ownerToken = $('#ownerToken').value.trim();
  if (managerToken) sessionStorage.setItem('warehouse-manager-token', managerToken);
  else sessionStorage.removeItem('warehouse-manager-token');
  if (ownerToken) sessionStorage.setItem('warehouse-owner-token', ownerToken);
  else sessionStorage.removeItem('warehouse-owner-token');
  showToast('Token disimpan sementara di tab ini.');
  loadCurrentView($('.view.active')?.id.replace('view-', '') || 'overview');
}

function initialize() {
  $('#todayLabel').textContent = todayFormat.format(new Date());
  $('#managerToken').value = sessionStorage.getItem('warehouse-manager-token') || '';
  $('#ownerToken').value = sessionStorage.getItem('warehouse-owner-token') || '';

  $$('.nav-link').forEach((button) => button.addEventListener('click', () => navigate(button.dataset.view)));
  $$('[data-go]').forEach((button) => button.addEventListener('click', () => navigate(button.dataset.go)));
  $('#mobileMenu').addEventListener('click', () => $('#sidebar').classList.toggle('open'));
  $('#refreshButton').addEventListener('click', () => {
    loadCurrentView($('.view.active')?.id.replace('view-', '') || 'overview');
    pingApi();
  });
  $('#tokenForm').addEventListener('submit', saveTokens);
  $('#addWarehouseButton').addEventListener('click', () => resetWarehouseDialog());
  $('#closeWarehouseDialog').addEventListener('click', () => $('#warehouseDialog').close());
  $('#cancelWarehouseButton').addEventListener('click', () => $('#warehouseDialog').close());
  $('#warehouseForm').addEventListener('submit', saveWarehouse);
  $('#warehouseRows').addEventListener('click', handleWarehouseAction);
  $('#adjustForm').addEventListener('submit', saveAdjustment);
  $('#purchaseForm').addEventListener('submit', createPurchase);
  $('#receiptForm').addEventListener('submit', receiveGoods);
  $('#restockRows').addEventListener('click', rejectOrder);

  $('#stockFilterForm').addEventListener('submit', (event) => {
    event.preventDefault();
    loadCurrentView('stocks');
  });
  $('#restockFilterForm').addEventListener('submit', (event) => {
    event.preventDefault();
    loadCurrentView('restock');
  });
  $('#movementFilterForm').addEventListener('submit', (event) => {
    event.preventDefault();
    loadCurrentView('movements');
  });
  ['stockFilterForm', 'restockFilterForm', 'movementFilterForm'].forEach((id) => {
    $(`#${id}`).addEventListener('reset', () => window.setTimeout(() => {
      const current = $('.view.active')?.id.replace('view-', '');
      if (current === 'stocks' || current === 'restock' || current === 'movements') loadCurrentView(current);
    }));
  });

  pingApi();
  loadCurrentView('overview');
}

document.addEventListener('DOMContentLoaded', initialize);
