document.addEventListener('DOMContentLoaded', () => {
  const root = document.querySelector('[data-partner-dashboard]');
  if (!root) return;

  const sessionEndpoint = root.dataset.sessionEndpoint || '../api/session/';
  const ordersEndpoint = root.dataset.ordersEndpoint || '../api/orders/';
  const labelsEndpoint = root.dataset.labelsEndpoint || '../api/order-labels/';
  const orderModal = document.querySelector('[data-order-modal]');
  const orderForm = document.querySelector('[data-order-form]');
  const orderList = document.querySelector('[data-order-list]');
  const errorNode = document.querySelector('[data-order-error]');
  const partnerNameNode = document.querySelector('[data-partner-name]');
  const partnerCodeNode = document.querySelector('[data-partner-code]');
  const busiestHourNode = document.querySelector('[data-busiest-hour]');
  const timeframeToggle = document.querySelector('[data-timeframe-toggle]');
  const salesChart = document.querySelector('[data-sales-chart]');
  const salesChartTitle = document.querySelector('[data-sales-chart-title]');
  const salesSummary = document.querySelector('[data-sales-summary]');
  const hourlyChart = document.querySelector('[data-hourly-chart]');
  const productInsights = document.querySelector('[data-product-insights]');
  const flavorInsights = document.querySelector('[data-flavor-insights]');
  const invoiceItemsNode = document.querySelector('[data-invoice-items]');
  const labelDropzone = document.querySelector('[data-label-dropzone]');
  const labelDropzoneCopy = document.querySelector('[data-label-dropzone-copy]');
  const labelInput = document.querySelector('[data-label-input]');
  const labelQueue = document.querySelector('[data-label-queue]');

  const state = {
    partner: null,
    catalog: {},
    skuIndex: {},
    productOptions: [],
    orders: [],
    analytics: {
      years: [],
      monthly_by_year: {},
      hourly_distribution: Array.from({ length: 24 }, () => 0),
      busiest_hour: '00:00',
      total_orders: 0
    },
    editingId: '',
    selectedTimeframe: '30d',
    queuedFile: null,
    currentLabels: []
  };

  const escapeHtml = (value) => String(value)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');

  const requestJson = async (url, options = {}) => {
    const response = await fetch(url, {
      method: options.method || 'GET',
      headers: {
        Accept: 'application/json',
        ...(options.body ? { 'Content-Type': 'application/json' } : {})
      },
      credentials: 'same-origin',
      body: options.body ? JSON.stringify(options.body) : undefined
    });
    const payload = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(payload.error || `HTTP ${response.status}`);
    return payload;
  };

  const postLabelForm = async (formData) => {
    const response = await fetch(labelsEndpoint, {
      method: 'POST',
      credentials: 'same-origin',
      body: formData
    });
    const payload = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(payload.error || `HTTP ${response.status}`);
    return payload;
  };

  const uploadLabel = async (orderId, file) => {
    const formData = new window.FormData();
    formData.append('order_id', orderId);
    formData.append('labels[]', file);
    return postLabelForm(formData);
  };

  const deleteLabel = async (orderId) => {
    const formData = new window.FormData();
    formData.append('action', 'delete');
    formData.append('order_id', orderId);
    return postLabelForm(formData);
  };

  const setError = (message) => {
    if (!errorNode) return;
    errorNode.hidden = !message;
    errorNode.textContent = message || '';
  };

  const formatTimestamp = (value) => {
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return '--';
    return date.toLocaleString('en-US', {
      year: 'numeric',
      month: 'short',
      day: 'numeric',
      hour: '2-digit',
      minute: '2-digit'
    });
  };

  const datetimeLocalValue = (value = '') => {
    const date = value ? new Date(value) : new Date();
    if (Number.isNaN(date.getTime())) return '';
    const offsetDate = new Date(date.getTime() - date.getTimezoneOffset() * 60000);
    return offsetDate.toISOString().slice(0, 16);
  };

  const formatFileSize = (bytes) => {
    const size = Number(bytes || 0);
    if (size >= 1024 * 1024) return `${(size / (1024 * 1024)).toFixed(1)} MB`;
    if (size >= 1024) return `${Math.round(size / 1024)} KB`;
    return `${size} B`;
  };

  const flattenCatalog = () => {
    const options = [];
    const skuIndex = {};
    Object.entries(state.catalog || {}).forEach(([brand, products]) => {
      Object.entries(products || {}).forEach(([product, productData]) => {
        (productData.skus || []).forEach((sku) => {
          if (!sku?.sku) return;
          const meta = [sku.size, sku.flavor].filter(Boolean).join(' • ');
          const option = {
            sku_code: sku.sku,
            label: product,
            brand,
            product,
            flavor: sku.flavor || '',
            size: sku.size || '',
            meta
          };
          options.push(option);
          skuIndex[sku.sku] = option;
        });
      });
    });
    state.productOptions = options.sort((left, right) => left.label.localeCompare(right.label));
    state.skuIndex = skuIndex;
  };

  const catalogItem = (item = {}) => state.skuIndex[item.sku_code] || null;

  const itemProductName = (item = {}) => catalogItem(item)?.product || item.product || item.sku_label || item.sku_code || 'Product';

  const itemFlavorName = (item = {}) => catalogItem(item)?.flavor || item.flavor || 'Unspecified flavor';

  const invoiceItemMarkup = (item = {}, index = 0) => `
    <article class="partner-invoice-item" data-invoice-item>
      <label class="admin-affiliate-field">
        <span class="admin-control-label">Product</span>
        <div class="partner-picker" data-product-picker>
          <input type="hidden" value="${escapeHtml(item.sku_code || '')}" data-invoice-product required>
          <button type="button" class="partner-picker-trigger" data-picker-trigger>${escapeHtml((state.productOptions.find((option) => option.sku_code === (item.sku_code || '')) || {}).label || 'Select product')}</button>
          <div class="partner-picker-panel" data-picker-panel hidden>
            <div class="partner-picker-options" data-picker-options></div>
            <div class="partner-picker-search-shell">
              <input type="search" class="partner-picker-search" placeholder="Search products" data-picker-search>
            </div>
          </div>
        </div>
      </label>
      <label class="admin-affiliate-field partner-invoice-qty">
        <span class="admin-control-label">QTY</span>
        <input type="number" min="1" step="1" value="${escapeHtml(item.quantity || 1)}" data-invoice-quantity required>
      </label>
      <button type="button" class="admin-ghost-btn" data-remove-invoice-item="${index}">Remove</button>
    </article>
  `;

  const renderInvoiceItems = (items = []) => {
    if (!invoiceItemsNode) return;
    const normalized = items.length ? items : [{ quantity: 1 }];
    invoiceItemsNode.innerHTML = normalized.map((item, index) => invoiceItemMarkup(item, index)).join('');
    document.querySelectorAll('[data-product-picker]').forEach((picker) => {
      renderPickerOptions(picker);
    });
  };

  const renderPickerOptions = (picker, query = '') => {
    const optionsNode = picker.querySelector('[data-picker-options]');
    const hiddenInput = picker.querySelector('[data-invoice-product]');
    const trigger = picker.querySelector('[data-picker-trigger]');
    if (!(optionsNode instanceof HTMLElement) || !(hiddenInput instanceof HTMLInputElement) || !(trigger instanceof HTMLButtonElement)) return;

    const selected = state.productOptions.find((option) => option.sku_code === hiddenInput.value) || null;
    trigger.textContent = selected?.label || 'Select product';

    const filtered = state.productOptions.filter((option) => {
      const needle = query.trim().toLowerCase();
      if (!needle) return true;
      return [option.label, option.brand, option.meta].some((value) => String(value || '').toLowerCase().includes(needle));
    });

    optionsNode.innerHTML = filtered.length ? filtered.map((option) => `
      <button type="button" class="partner-picker-option ${option.sku_code === hiddenInput.value ? 'is-active' : ''}" data-picker-option="${escapeHtml(option.sku_code)}">
        <strong>${escapeHtml(option.label)}</strong>
        <span>${escapeHtml(option.meta || '')}</span>
      </button>
    `).join('') : '<p class="partner-picker-empty">No products match that search.</p>';
  };

  const collectInvoiceItems = () => {
    const items = [];
    document.querySelectorAll('[data-invoice-item]').forEach((itemNode) => {
      const select = itemNode.querySelector('[data-invoice-product]');
      const quantity = itemNode.querySelector('[data-invoice-quantity]');
      if (!(select instanceof HTMLInputElement) || !(quantity instanceof HTMLInputElement)) return;
      if (!select.value) return;
      items.push({
        sku_code: select.value,
        quantity: Math.max(1, Number.parseInt(quantity.value || '1', 10))
      });
    });
    return items;
  };

  const renderLabelQueue = () => {
    if (!labelQueue) return;

    if (state.currentLabels.length) {
      const label = state.currentLabels[0];
      labelQueue.innerHTML = `
        <article class="partner-upload-item">
          <div>
            <strong>${escapeHtml(label.name || 'Shipping label')}</strong>
            <span>${escapeHtml(formatFileSize(label.size_bytes || 0))}</span>
          </div>
          <button type="button" class="admin-danger-btn" data-delete-current-label>Delete Label</button>
        </article>
      `;
      if (labelDropzone) labelDropzone.disabled = true;
      if (labelDropzoneCopy) labelDropzoneCopy.textContent = 'Delete the current label before uploading another.';
      return;
    }

    if (state.queuedFile) {
      labelQueue.innerHTML = `
        <article class="partner-upload-item">
          <div>
            <strong>${escapeHtml(state.queuedFile.name)}</strong>
            <span>${escapeHtml(formatFileSize(state.queuedFile.size))}</span>
          </div>
          <button type="button" class="admin-ghost-btn" data-remove-queued-file>Remove</button>
        </article>
      `;
      if (labelDropzone) labelDropzone.disabled = false;
      if (labelDropzoneCopy) labelDropzoneCopy.textContent = 'One label is queued for this order.';
      return;
    }

    labelQueue.innerHTML = '<p class="admin-empty">No label file queued.</p>';
    if (labelDropzone) labelDropzone.disabled = false;
    if (labelDropzoneCopy) labelDropzoneCopy.textContent = 'Add one label after the order is saved.';
  };

  const openOrderModal = (order = null) => {
    if (!(orderModal instanceof HTMLElement) || !(orderForm instanceof HTMLFormElement)) return;
    state.editingId = order?.id || '';
    state.queuedFile = null;
    state.currentLabels = [...(order?.labels || [])];
    orderModal.hidden = false;
    setError('');
    orderForm.reset();
    orderForm.elements.order_id.value = order?.id || '';
    orderForm.elements.customer_name.value = order?.customer_name || '';
    orderForm.elements.order_timestamp.value = datetimeLocalValue(order?.order_timestamp || order?.created_at || '');
    orderForm.elements.notes.value = order?.notes || '';
    renderInvoiceItems(order?.items || []);
    renderLabelQueue();
    const submit = orderForm.querySelector('[type="submit"]');
    if (submit) submit.textContent = state.editingId ? 'Save Order' : 'Create Order';
  };

  const closeOrderModal = () => {
    if (!(orderModal instanceof HTMLElement) || !(orderForm instanceof HTMLFormElement)) return;
    orderModal.hidden = true;
    state.editingId = '';
    state.queuedFile = null;
    state.currentLabels = [];
    orderForm.reset();
    setError('');
  };

  const renderOrders = () => {
    if (!orderList) return;
    if (!state.orders.length) {
      orderList.innerHTML = '<tr><td colspan="7" class="partner-order-empty">No orders yet.</td></tr>';
      return;
    }

    orderList.innerHTML = state.orders.map((order) => {
      const items = order.items || [];
      const itemSummary = items.map((item) => `${escapeHtml(itemProductName(item))} x${escapeHtml(item.quantity || 1)}`).join('<br>');
      const totalQuantity = items.reduce((sum, item) => sum + Number(item.quantity || 0), 0);
      const label = (order.labels || [])[0] || null;

      return `
        <tr>
          <td>
            <strong>${escapeHtml(order.id || '')}</strong>
            <span>${escapeHtml(order.status || 'draft')}</span>
          </td>
          <td>${escapeHtml(order.customer_name || '')}</td>
          <td>${itemSummary || '--'}</td>
          <td>${escapeHtml(totalQuantity || order.quantity || 0)}</td>
          <td>
            <div class="partner-label-list">
              ${label ? `<a href="${escapeHtml(label.url || '#')}" target="_blank" rel="noopener">${escapeHtml(label.name || 'Label')}</a>` : '<span>No label</span>'}
            </div>
          </td>
          <td>${escapeHtml(formatTimestamp(order.order_timestamp || order.created_at || ''))}</td>
          <td>
            <div class="partner-table-actions">
              <button type="button" class="admin-primary-btn" data-edit-order="${escapeHtml(order.id || '')}">Edit</button>
              <button type="button" class="admin-ghost-btn" data-delete-order="${escapeHtml(order.id || '')}">Delete</button>
            </div>
          </td>
        </tr>
      `;
    }).join('');
  };

  const orderTime = (order = {}) => {
    const timestamp = new Date(order.order_timestamp || order.created_at || '');
    return Number.isNaN(timestamp.getTime()) ? null : timestamp;
  };

  const timeframeStart = (range) => {
    const now = new Date();
    if (range === '24h') return new Date(now.getTime() - 24 * 60 * 60 * 1000);
    if (range === '7d') return new Date(now.getTime() - 7 * 24 * 60 * 60 * 1000);
    if (range === '30d') return new Date(now.getTime() - 30 * 24 * 60 * 60 * 1000);
    if (range === '90d') return new Date(now.getTime() - 90 * 24 * 60 * 60 * 1000);
    if (range === 'year') return new Date(now.getFullYear(), 0, 1);
    return null;
  };

  const filteredOrders = () => {
    const start = timeframeStart(state.selectedTimeframe);
    if (!start) return state.orders;
    return state.orders.filter((order) => {
      const timestamp = orderTime(order);
      return timestamp && timestamp >= start;
    });
  };

  const timeframeLabel = () => ({
    '24h': 'Last 24 hours',
    '7d': 'Last 7 days',
    '30d': 'Last 30 days',
    '90d': 'Last 90 days',
    year: 'This year',
    all: 'All time'
  })[state.selectedTimeframe] || 'Last 30 days';

  const orderUnits = (order = {}) => (order.items || []).reduce((sum, item) => sum + Number(item.quantity || 0), 0);

  const buildSalesBuckets = (orders) => {
    const range = state.selectedTimeframe;
    const now = new Date();
    if (range === '24h') {
      return Array.from({ length: 24 }, (_, index) => {
        const date = new Date(now.getTime() - (23 - index) * 60 * 60 * 1000);
        return {
          key: `${date.getFullYear()}-${date.getMonth()}-${date.getDate()}-${date.getHours()}`,
          label: `${String(date.getHours()).padStart(2, '0')}:00`,
          value: 0
        };
      });
    }

    if (range === '7d' || range === '30d' || range === '90d') {
      const days = range === '7d' ? 7 : range === '30d' ? 30 : 90;
      return Array.from({ length: days }, (_, index) => {
        const date = new Date(now.getFullYear(), now.getMonth(), now.getDate() - (days - 1 - index));
        return {
          key: `${date.getFullYear()}-${date.getMonth()}-${date.getDate()}`,
          label: date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' }),
          value: 0
        };
      });
    }

    if (range === 'all') {
      const timestamps = orders.map(orderTime).filter(Boolean);
      const firstOrder = timestamps.length
        ? new Date(Math.min(...timestamps.map((date) => date.getTime())))
        : now;
      const first = new Date(firstOrder.getFullYear(), firstOrder.getMonth(), 1);
      const last = new Date(now.getFullYear(), now.getMonth(), 1);
      const months = ((last.getFullYear() - first.getFullYear()) * 12) + (last.getMonth() - first.getMonth()) + 1;
      return Array.from({ length: months }, (_, index) => {
        const date = new Date(first.getFullYear(), first.getMonth() + index, 1);
        return {
          key: `${date.getFullYear()}-${date.getMonth()}`,
          label: date.toLocaleDateString('en-US', { month: 'short', year: months > 12 ? '2-digit' : undefined }),
          value: 0
        };
      });
    }

    const months = now.getMonth() + 1;
    const first = new Date(now.getFullYear(), 0, 1);
    return Array.from({ length: months }, (_, index) => {
      const date = new Date(first.getFullYear(), first.getMonth() + index, 1);
      return {
        key: `${date.getFullYear()}-${date.getMonth()}`,
        label: date.toLocaleDateString('en-US', { month: 'short' }),
        value: 0
      };
    });
  };

  const renderInsightList = (node, rows, emptyText) => {
    if (!node) return;
    const total = rows.reduce((sum, row) => sum + row.value, 0);
    node.innerHTML = rows.length ? rows.map((row) => {
      const percent = total ? Math.round((row.value / total) * 100) : 0;
      return `
        <article class="partner-insight-row">
          <div class="partner-insight-copy">
            <strong>${escapeHtml(row.label)}</strong>
            <span>${escapeHtml(row.value)} units • ${escapeHtml(percent)}%</span>
          </div>
          <div class="partner-insight-track"><i style="width:${Math.max(4, percent)}%"></i></div>
        </article>
      `;
    }).join('') : `<p class="admin-empty">${escapeHtml(emptyText)}</p>`;
  };

  const renderSalesLineChart = (buckets) => {
    if (!salesChart) return;
    const width = 920;
    const height = 300;
    const padX = 44;
    const padTop = 28;
    const padBottom = 58;
    const chartWidth = width - padX * 2;
    const chartHeight = height - padTop - padBottom;
    const maxValue = Math.max(1, ...buckets.map((bucket) => bucket.value));
    const points = buckets.map((bucket, index) => {
      const x = buckets.length === 1 ? width / 2 : padX + (index / (buckets.length - 1)) * chartWidth;
      const y = padTop + chartHeight - (bucket.value / maxValue) * chartHeight;
      return { ...bucket, x, y };
    });
    const linePath = points.map((point, index) => `${index === 0 ? 'M' : 'L'} ${point.x.toFixed(2)} ${point.y.toFixed(2)}`).join(' ');
    const areaPath = points.length
      ? `${linePath} L ${points[points.length - 1].x.toFixed(2)} ${height - padBottom} L ${points[0].x.toFixed(2)} ${height - padBottom} Z`
      : '';
    const labelModulo = buckets.length > 18 ? Math.ceil(buckets.length / 9) : 1;
    const hoverWidth = buckets.length <= 1 ? chartWidth : chartWidth / (buckets.length - 1);

    salesChart.innerHTML = `
      <svg class="partner-line-chart" viewBox="0 0 ${width} ${height}" role="img" aria-label="${escapeHtml(timeframeLabel())} sales line chart">
        <defs>
          <linearGradient id="partner-line-fill" x1="0" x2="0" y1="0" y2="1">
            <stop offset="0%" stop-color="#78ffb1" stop-opacity="0.32"></stop>
            <stop offset="100%" stop-color="#0f8d4d" stop-opacity="0"></stop>
          </linearGradient>
        </defs>
        <line x1="${padX}" y1="${height - padBottom}" x2="${width - padX}" y2="${height - padBottom}" class="partner-line-axis"></line>
        ${areaPath ? `<path d="${areaPath}" class="partner-line-area"></path>` : ''}
        ${linePath ? `<path d="${linePath}" class="partner-line-path"></path>` : ''}
        ${points.map((point) => `
          <g class="partner-line-point">
            <circle cx="${point.x.toFixed(2)}" cy="${point.y.toFixed(2)}" r="5"></circle>
            <text x="${point.x.toFixed(2)}" y="${Math.max(14, point.y - 12).toFixed(2)}">${escapeHtml(point.value)}</text>
          </g>
        `).join('')}
        ${points.map((point, index) => index % labelModulo === 0 || index === points.length - 1 ? `
          <text class="partner-line-label" x="${point.x.toFixed(2)}" y="${height - 22}" text-anchor="middle">${escapeHtml(point.label)}</text>
        ` : '').join('')}
        ${points.map((point) => {
          const tooltipX = Math.min(width - 92, Math.max(92, point.x));
          const tooltipY = point.y < 82 ? point.y + 46 : point.y - 46;
          return `
            <g class="partner-line-hover">
              <rect class="partner-line-hover-zone" x="${(point.x - hoverWidth / 2).toFixed(2)}" y="${padTop}" width="${hoverWidth.toFixed(2)}" height="${chartHeight}" tabindex="0" aria-label="${escapeHtml(point.label)}: ${escapeHtml(point.value)} units"></rect>
              <line class="partner-line-guide" x1="${point.x.toFixed(2)}" y1="${padTop}" x2="${point.x.toFixed(2)}" y2="${height - padBottom}"></line>
              <circle class="partner-line-hover-dot" cx="${point.x.toFixed(2)}" cy="${point.y.toFixed(2)}" r="7"></circle>
              <g class="partner-line-tooltip" transform="translate(${tooltipX.toFixed(2)} ${tooltipY.toFixed(2)})">
                <rect x="-78" y="-26" width="156" height="52" rx="14"></rect>
                <text class="partner-line-tooltip-label" x="0" y="-5">${escapeHtml(point.label)}</text>
                <text class="partner-line-tooltip-value" x="0" y="15">${escapeHtml(point.value)} units</text>
              </g>
            </g>
          `;
        }).join('')}
      </svg>
    `;
  };

  const renderAnalytics = () => {
    const visibleOrders = filteredOrders();
    const totalUnits = visibleOrders.reduce((sum, order) => sum + orderUnits(order), 0);

    if (timeframeToggle) {
      timeframeToggle.querySelectorAll('[data-timeframe]').forEach((button) => {
        button.classList.toggle('is-active', button.getAttribute('data-timeframe') === state.selectedTimeframe);
      });
    }

    if (salesChartTitle) salesChartTitle.textContent = timeframeLabel();
    if (salesSummary) salesSummary.textContent = `${totalUnits} units`;

    if (salesChart) {
      const buckets = buildSalesBuckets(visibleOrders);
      const bucketIndex = new Map(buckets.map((bucket) => [bucket.key, bucket]));
      visibleOrders.forEach((order) => {
        const timestamp = orderTime(order);
        if (!timestamp) return;
        const key = state.selectedTimeframe === '24h'
          ? `${timestamp.getFullYear()}-${timestamp.getMonth()}-${timestamp.getDate()}-${timestamp.getHours()}`
          : (state.selectedTimeframe === '7d' || state.selectedTimeframe === '30d' || state.selectedTimeframe === '90d')
            ? `${timestamp.getFullYear()}-${timestamp.getMonth()}-${timestamp.getDate()}`
            : `${timestamp.getFullYear()}-${timestamp.getMonth()}`;
        const bucket = bucketIndex.get(key);
        if (bucket) bucket.value += orderUnits(order);
      });

      renderSalesLineChart(buckets);
    }

    if (hourlyChart) {
      const hourly = Array.from({ length: 24 }, () => 0);
      visibleOrders.forEach((order) => {
        const timestamp = orderTime(order);
        if (!timestamp) return;
        hourly[timestamp.getHours()] += orderUnits(order);
      });
      const maxValue = Math.max(1, ...hourly);
      let busiestHour = 0;
      let busiestCount = -1;
      hourly.forEach((count, hour) => {
        if (count > busiestCount) {
          busiestCount = count;
          busiestHour = hour;
        }
      });

      hourlyChart.innerHTML = hourly.map((count, hour) => `
        <div class="partner-hour-row">
          <span>${escapeHtml(String(hour).padStart(2, '0'))}:00</span>
          <div class="partner-hour-track"><i style="width:${Math.max(8, Math.round((count / maxValue) * 100))}%"></i></div>
          <strong>${escapeHtml(count)}</strong>
        </div>
      `).join('');

      if (busiestHourNode) busiestHourNode.textContent = `${String(busiestHour).padStart(2, '0')}:00`;
    }

    const productRows = new Map();
    const flavorRows = new Map();
    visibleOrders.forEach((order) => {
      (order.items || []).forEach((item) => {
        const quantity = Number(item.quantity || 0);
        const product = itemProductName(item);
        const flavor = itemFlavorName(item);
        const productFlavor = `${product} - ${flavor}`;
        productRows.set(product, (productRows.get(product) || 0) + quantity);
        flavorRows.set(productFlavor, (flavorRows.get(productFlavor) || 0) + quantity);
      });
    });

    const sortedProducts = [...productRows.entries()]
      .map(([label, value]) => ({ label, value }))
      .sort((left, right) => right.value - left.value);
    const sortedFlavors = [...flavorRows.entries()]
      .map(([label, value]) => ({ label, value }))
      .sort((left, right) => right.value - left.value);

    renderInsightList(productInsights, sortedProducts, 'No product sales in this timeframe.');
    renderInsightList(flavorInsights, sortedFlavors, 'No flavor sales in this timeframe.');

  };

  const loadOrders = async () => {
    const payload = await requestJson(ordersEndpoint);
    state.orders = payload.orders || [];
    state.analytics = payload.analytics || state.analytics;
    renderOrders();
    renderAnalytics();
  };

  const loadSession = async () => {
    const payload = await requestJson(sessionEndpoint);
    state.partner = payload.partner || null;
    state.catalog = payload.catalog || {};
    flattenCatalog();
    if (state.orders.length) {
      renderOrders();
      renderAnalytics();
    }
    if (partnerNameNode) partnerNameNode.textContent = state.partner?.name || 'Partner';
    if (partnerCodeNode) partnerCodeNode.textContent = state.partner?.code || 'Partner';
  };

  document.querySelectorAll('[data-open-order-modal]').forEach((button) => {
    button.addEventListener('click', () => openOrderModal());
  });

  document.querySelectorAll('[data-close-order-modal]').forEach((button) => {
    button.addEventListener('click', closeOrderModal);
  });

  document.querySelector('[data-add-invoice-item]')?.addEventListener('click', () => {
    const current = collectInvoiceItems();
    current.push({ quantity: 1 });
    renderInvoiceItems(current);
  });

  invoiceItemsNode?.addEventListener('click', (event) => {
    const target = event.target;
    if (!(target instanceof HTMLElement)) return;
    const pickerTrigger = target.closest('[data-picker-trigger]');
    if (pickerTrigger instanceof HTMLButtonElement) {
      const picker = pickerTrigger.closest('[data-product-picker]');
      if (!(picker instanceof HTMLElement)) return;
      document.querySelectorAll('[data-picker-panel]').forEach((panel) => {
        if (panel !== picker.querySelector('[data-picker-panel]')) panel.hidden = true;
      });
      const panel = picker.querySelector('[data-picker-panel]');
      const search = picker.querySelector('[data-picker-search]');
      if (panel instanceof HTMLElement) {
        panel.hidden = !panel.hidden;
        if (!panel.hidden && search instanceof HTMLInputElement) search.focus();
      }
      return;
    }

    const pickerOption = target.closest('[data-picker-option]');
    if (pickerOption instanceof HTMLButtonElement) {
      const picker = pickerOption.closest('[data-product-picker]');
      const hiddenInput = picker?.querySelector('[data-invoice-product]');
      const panel = picker?.querySelector('[data-picker-panel]');
      const search = picker?.querySelector('[data-picker-search]');
      if (hiddenInput instanceof HTMLInputElement) {
        hiddenInput.value = pickerOption.getAttribute('data-picker-option') || '';
      }
      if (search instanceof HTMLInputElement) {
        search.value = '';
      }
      if (panel instanceof HTMLElement) {
        panel.hidden = true;
      }
      if (picker instanceof HTMLElement) {
        renderPickerOptions(picker);
      }
      return;
    }

    if (!target.matches('[data-remove-invoice-item]')) return;
    const current = collectInvoiceItems();
    const index = Number(target.getAttribute('data-remove-invoice-item') || 0);
    current.splice(index, 1);
    renderInvoiceItems(current);
  });

  invoiceItemsNode?.addEventListener('input', (event) => {
    const target = event.target;
    if (!(target instanceof HTMLInputElement) || !target.matches('[data-picker-search]')) return;
    const picker = target.closest('[data-product-picker]');
    if (!(picker instanceof HTMLElement)) return;
    renderPickerOptions(picker, target.value);
  });

  document.addEventListener('click', (event) => {
    const target = event.target;
    if (!(target instanceof Element)) return;
    if (target.closest('[data-product-picker]')) return;
    document.querySelectorAll('[data-picker-panel]').forEach((panel) => {
      panel.hidden = true;
    });
  });

  orderForm?.addEventListener('submit', async (event) => {
    event.preventDefault();
    setError('');

    const items = collectInvoiceItems();
    if (!items.length) {
      setError('Add at least one product to the invoice.');
      return;
    }

    try {
      const formData = new window.FormData(orderForm);
      const payload = await requestJson(ordersEndpoint, {
        method: 'POST',
        body: {
          action: state.editingId ? 'update' : 'create',
          id: formData.get('order_id'),
          customer_name: formData.get('customer_name'),
          order_timestamp: formData.get('order_timestamp'),
          items,
          notes: formData.get('notes')
        }
      });

      const savedOrder = payload.order || null;
      if (savedOrder?.id && state.queuedFile) {
        await uploadLabel(savedOrder.id, state.queuedFile);
      }

      closeOrderModal();
      await loadOrders();
    } catch (error) {
      setError(error instanceof Error ? error.message : 'Unable to save order.');
    }
  });

  orderList?.addEventListener('click', async (event) => {
    const target = event.target;
    if (!(target instanceof HTMLElement)) return;
    const editId = target.getAttribute('data-edit-order');
    const deleteId = target.getAttribute('data-delete-order');

    if (editId) {
      const order = state.orders.find((item) => item.id === editId);
      if (order) openOrderModal(order);
      return;
    }

    if (deleteId) {
      try {
        await requestJson(ordersEndpoint, {
          method: 'POST',
          body: { action: 'delete', id: deleteId }
        });
        await loadOrders();
      } catch (error) {
        setError(error instanceof Error ? error.message : 'Unable to delete order.');
      }
    }
  });

  labelDropzone?.addEventListener('click', () => {
    if (state.currentLabels.length) return;
    labelInput?.click();
  });
  labelDropzone?.addEventListener('dragover', (event) => {
    event.preventDefault();
    if (!state.currentLabels.length) labelDropzone.classList.add('is-dragover');
  });
  labelDropzone?.addEventListener('dragleave', () => {
    labelDropzone.classList.remove('is-dragover');
  });
  labelDropzone?.addEventListener('drop', (event) => {
    event.preventDefault();
    labelDropzone.classList.remove('is-dragover');
    if (state.currentLabels.length) return;
    const file = event.dataTransfer?.files?.[0] || null;
    if (file) {
      state.queuedFile = file;
      renderLabelQueue();
    }
  });
  labelInput?.addEventListener('change', () => {
    const file = labelInput.files?.[0] || null;
    if (file) {
      state.queuedFile = file;
      renderLabelQueue();
      labelInput.value = '';
    }
  });
  labelQueue?.addEventListener('click', async (event) => {
    const target = event.target;
    if (!(target instanceof HTMLElement)) return;

    if (target.matches('[data-remove-queued-file]')) {
      state.queuedFile = null;
      renderLabelQueue();
      return;
    }

    if (target.matches('[data-delete-current-label]')) {
      if (!state.editingId) return;
      try {
        await deleteLabel(state.editingId);
        state.currentLabels = [];
        renderLabelQueue();
        await loadOrders();
      } catch (error) {
        setError(error instanceof Error ? error.message : 'Unable to delete label.');
      }
    }
  });

  timeframeToggle?.addEventListener('click', (event) => {
    const target = event.target;
    if (!(target instanceof HTMLElement)) return;
    const nextTimeframe = target.getAttribute('data-timeframe');
    if (!nextTimeframe) return;
    state.selectedTimeframe = nextTimeframe;
    renderAnalytics();
  });

  document.querySelector('[data-partner-logout]')?.addEventListener('click', async () => {
    try {
      await requestJson(sessionEndpoint, { method: 'DELETE' });
      window.location.href = '../';
    } catch (_) {
      window.location.href = '../logout/';
    }
  });

  Promise.all([loadSession(), loadOrders()]).catch((error) => {
    setError(error instanceof Error ? error.message : 'Unable to load dashboard.');
  });
});
