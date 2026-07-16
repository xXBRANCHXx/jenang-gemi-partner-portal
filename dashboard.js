document.addEventListener('DOMContentLoaded', () => {
  const root = document.querySelector('[data-partner-dashboard]');
  if (!root) return;

  const sessionEndpoint = root.dataset.sessionEndpoint || '../api/session/';
  const ordersEndpoint = root.dataset.ordersEndpoint || '../api/orders/';
  const labelsEndpoint = root.dataset.labelsEndpoint || '../api/order-labels/';
  const logoutUrl = root.dataset.logoutUrl || '../logout/';
  const dashboardBase = root.dataset.dashboardBase || './';
  let csrfToken = root.dataset.csrfToken || '';
  const maxLabelBytes = 10 * 1024 * 1024;

  const orderModal = document.querySelector('[data-order-modal]');
  const orderForm = document.querySelector('[data-order-form]');
  const passwordModal = document.querySelector('[data-password-modal]');
  const passwordForm = document.querySelector('[data-password-form]');
  const currentPasswordField = document.querySelector('[data-current-password-field]');
  const currentPasswordInput = document.querySelector('[data-current-password-input]');
  const passwordResetNote = document.querySelector('[data-password-reset-note]');
  const orderList = document.querySelector('[data-order-list]');
  const recentOrders = document.querySelector('[data-recent-orders]');
  const errorNode = document.querySelector('[data-order-error]');
  const modalErrorNode = document.querySelector('[data-modal-order-error]');
  const passwordErrorNode = document.querySelector('[data-password-error]');
  const partnerNameNode = document.querySelector('[data-partner-name]');
  const partnerCodeNode = document.querySelector('[data-partner-code]');
  const timeframeToggle = document.querySelector('[data-timeframe-toggle]');
  const monthPicker = document.querySelector('[data-month-picker]');
  const chartMonthInput = document.querySelector('[data-chart-month]');
  const salesChart = document.querySelector('[data-sales-chart]');
  const salesChartTitle = document.querySelector('[data-sales-chart-title]');
  const labelDropzone = document.querySelector('[data-label-dropzone]');
  const labelDropzoneCopy = document.querySelector('[data-label-dropzone-copy]');
  const labelInput = document.querySelector('[data-label-input]');
  const labelQueue = document.querySelector('[data-label-queue]');
  const deadlineRange = document.querySelector('[data-deadline-range]');
  const deadlineValue = document.querySelector('[data-deadline-value]');
  const customerNameInput = document.querySelector('[data-customer-name]');
  const platformSelect = document.querySelector('[data-platform-select]');
  const skuSearchInput = document.querySelector('[data-sku-search]');
  const productFilter = document.querySelector('[data-product-filter]');
  const flavorFilter = document.querySelector('[data-flavor-filter]');
  const skuList = document.querySelector('[data-sku-list]');
  const orderPreview = document.querySelector('[data-order-preview]');
  const submitOrderButton = document.querySelector('[data-submit-order]');
  const pageTitle = document.querySelector('[data-page-title]');
  const sectionLinks = Array.from(document.querySelectorAll('[data-partner-section-link]'));
  const sections = Array.from(document.querySelectorAll('[data-partner-section]'));
  const themeSwitches = Array.from(document.querySelectorAll('[data-theme-switch]'));
  const labelLibrary = document.querySelector('[data-label-library]');
  const productMix = document.querySelector('[data-product-mix]');
  const analyticsNodes = {
    active: document.querySelector('[data-analytics-active]'),
    fulfilled: document.querySelector('[data-analytics-fulfilled]'),
    cancelRate: document.querySelector('[data-analytics-cancel-rate]'),
    revenueOrder: document.querySelector('[data-analytics-revenue-order]')
  };

  const now = new Date();
  const currentChartMonth = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`;

  const state = {
    partner: null,
    catalog: {},
    skuIndex: {},
    approvedSkus: [],
    orders: [],
    selectedTimeframe: '30d',
    selectedMonth: currentChartMonth,
    activeSection: root.dataset.activeSection || 'overview',
    theme: window.localStorage.getItem('partner-theme') || 'system',
    labelFile: null,
    selectedProduct: '',
    selectedFlavor: '',
    skuSearch: '',
    cart: [],
    submitting: false,
    passwordResetRequired: false
  };
  const sectionLabels = {
    overview: 'Overview',
    orders: 'Orders',
    labels: 'Labels',
    analytics: 'Analytics',
    settings: 'Settings'
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
        ...(options.body ? { 'Content-Type': 'application/json' } : {}),
        ...((options.method || 'GET').toUpperCase() !== 'GET' ? { 'X-CSRF-Token': csrfToken } : {})
      },
      credentials: 'same-origin',
      body: options.body ? JSON.stringify(options.body) : undefined
    });
    const payload = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(payload.error || `HTTP ${response.status}`);
    return payload;
  };

  const postOrderForm = async (formData) => {
    const response = await fetch(ordersEndpoint, {
      method: 'POST',
      headers: { 'X-CSRF-Token': csrfToken },
      credentials: 'same-origin',
      body: formData
    });
    const payload = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(payload.error || `HTTP ${response.status}`);
    return payload;
  };

  const setError = (message, target = errorNode) => {
    if (!target) return;
    target.hidden = !message;
    target.textContent = message || '';
  };

  const renderPasswordResetState = () => {
    if (currentPasswordField instanceof HTMLElement) {
      currentPasswordField.hidden = state.passwordResetRequired;
    }
    if (currentPasswordInput instanceof HTMLInputElement) {
      currentPasswordInput.required = !state.passwordResetRequired;
      if (state.passwordResetRequired) currentPasswordInput.value = '';
    }
    if (passwordResetNote instanceof HTMLElement) {
      passwordResetNote.hidden = !state.passwordResetRequired;
    }
  };

  const formatTimestamp = (value) => {
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return '--';
    return date.toLocaleString('en-US', {
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

  const formatCurrency = (value) => new Intl.NumberFormat('id-ID', {
    style: 'currency',
    currency: 'IDR',
    maximumFractionDigits: 0
  }).format(Number(value || 0));

  const formatFileSize = (bytes) => {
    const size = Number(bytes || 0);
    if (size >= 1024 * 1024) return `${(size / (1024 * 1024)).toFixed(1)} MB`;
    if (size >= 1024) return `${Math.round(size / 1024)} KB`;
    return `${size} B`;
  };

  const isPdfLabelFile = (file) => {
    if (!file) return false;
    const name = String(file.name || '').toLowerCase();
    const type = String(file.type || '').toLowerCase();
    return name.endsWith('.pdf') && (!type || type === 'application/pdf' || type === 'application/x-pdf');
  };

  const formatNumber = (value) => {
    const number = Number(value || 0);
    if (!Number.isFinite(number)) return '0';
    return number.toLocaleString('id-ID', {
      maximumFractionDigits: number % 1 === 0 ? 0 : 2
    });
  };

  const detectPlatformFromFileName = (fileName = '') => {
    const normalized = String(fileName || '').toLowerCase();
    if (normalized.includes('shopee') || normalized.includes('spx')) return 'Shopee';
    if (normalized.includes('tiktok') || normalized.includes('tik tok') || normalized.includes('tts')) return 'TikTok Shop';
    return 'Needs review';
  };

  const skuProductName = (sku = {}) => String(sku.base_product_name || sku.product_name || 'Product').trim() || 'Product';
  const skuFlavorName = (sku = {}) => String(sku.flavor_name || sku.flavor || '').trim();
  const skuDisplayName = (sku = {}) => [sku.product_name || skuProductName(sku), skuFlavorName(sku)]
    .filter((value, index, values) => value && values.indexOf(value) === index)
    .join(' · ') || sku.sku || 'Approved SKU';

  const unitFormula = (sku = {}) => {
    const volume = Number(sku.volume || 0);
    const astra = Number(sku.astra_value || 0);
    const units = Math.max(1, Number(sku.unit_count || 1));
    if (volume > 0 && astra > 0) {
      return `${formatNumber(volume)} / ASTRA ${formatNumber(astra)} = ${formatNumber(units)} units`;
    }
    return `${formatNumber(units)} billable unit${units === 1 ? '' : 's'}`;
  };

  const flattenCatalog = () => {
    const skuIndex = {};
    const approvedSkus = [];
    Object.entries(state.catalog || {}).forEach(([brandName, products]) => {
      Object.entries(products || {}).forEach(([productName, productData]) => {
        (productData.skus || []).forEach((sku) => {
          if (!sku?.sku) return;
          const unitCount = Math.max(1, Number(sku.unit_count || 1));
          const partnerUnitPrice = Number(sku.partner_unit_price ?? sku.partner_price ?? 0);
          const partnerPrice = Number(sku.partner_price ?? (partnerUnitPrice * unitCount));
          const row = {
            ...sku,
            sku: String(sku.sku || ''),
            brand_name: sku.brand_name || brandName,
            product_name: sku.product_name || productName,
            base_product_name: sku.base_product_name || productName,
            flavor_name: skuFlavorName(sku),
            size_label: sku.size_label || sku.size || '',
            current_stock: Number(sku.current_stock ?? sku.stock ?? 0),
            unit_count: unitCount,
            partner_unit_price: partnerUnitPrice,
            partner_price: partnerPrice
          };
          skuIndex[row.sku] = row;
          approvedSkus.push(row);
        });
      });
    });
    state.skuIndex = skuIndex;
    state.approvedSkus = approvedSkus.sort((left, right) => {
      const productCompare = skuProductName(left).localeCompare(skuProductName(right));
      if (productCompare !== 0) return productCompare;
      const flavorCompare = skuFlavorName(left).localeCompare(skuFlavorName(right));
      if (flavorCompare !== 0) return flavorCompare;
      return String(left.sku || '').localeCompare(String(right.sku || ''));
    });
  };

  const sectionUrl = (section) => {
    const base = dashboardBase.endsWith('/') ? dashboardBase : `${dashboardBase}/`;
    return section === 'overview' ? base : `${base}${section}/`;
  };

  const sectionFromLocation = () => {
    const parts = window.location.pathname.split('/').filter(Boolean);
    const dashboardIndex = parts.indexOf('dashboard');
    const section = dashboardIndex >= 0 ? parts[dashboardIndex + 1] : '';
    return sectionLabels[section] ? section : 'overview';
  };

  const normalizeTheme = (theme) => ['system', 'light', 'dark'].includes(theme) ? theme : 'system';

  const applyTheme = (theme = state.theme) => {
    state.theme = normalizeTheme(theme);
    root.dataset.partnerTheme = state.theme;
    document.body.dataset.partnerTheme = state.theme;
    window.localStorage.setItem('partner-theme', state.theme);
    themeSwitches.forEach((switcher) => {
      switcher.querySelectorAll('[data-theme-option]').forEach((button) => {
        button.classList.toggle('is-active', button.getAttribute('data-theme-option') === state.theme);
      });
    });
  };

  const setActiveSection = (section, push = false) => {
    const next = sectionLabels[section] ? section : 'overview';
    state.activeSection = next;
    root.dataset.activeSection = next;
    sections.forEach((panel) => {
      panel.classList.toggle('is-active', panel.getAttribute('data-partner-section') === next);
    });
    sectionLinks.forEach((link) => {
      link.classList.toggle('is-active', link.getAttribute('data-partner-section-link') === next);
    });
    if (pageTitle) pageTitle.textContent = sectionLabels[next];
    if (push) {
      window.history.pushState({ section: next }, '', sectionUrl(next));
    }
  };

  const orderUnits = (order = {}) => (order.items || []).reduce((sum, item) => sum + Number(item.quantity || 0), 0);
  const orderRevenue = (order = {}) => Number(order.revenue_total || (order.items || []).reduce((sum, item) => sum + Number(item.line_revenue || 0), 0));
  const isArchived = (order = {}) => String(order.archived_at || '').trim() !== '';
  const canCancel = (order = {}) => ['IS_LISTED', 'LISTED', ''].includes(String(order.status || 'IS_LISTED').trim().toUpperCase());
  const statusLabel = (order = {}) => {
    const status = String(order.status || 'IS_LISTED').trim().toUpperCase();
    if (status === 'IS_BEING_FULFILLED' || status === 'PROCESSING') return 'Processing';
    if (status === 'FULFILLED' || status === 'COMPLETED') return 'Fulfilled';
    if (status === 'CANCELLED') return 'Cancelled';
    return 'IS_LISTED';
  };

  const orderTime = (order = {}) => {
    const timestamp = new Date(order.order_timestamp || order.created_at || '');
    return Number.isNaN(timestamp.getTime()) ? null : timestamp;
  };

  const selectedMonthBounds = () => {
    const match = /^(\d{4})-(\d{2})$/.exec(state.selectedMonth);
    if (!match) return null;
    const year = Number(match[1]);
    const monthIndex = Number(match[2]) - 1;
    if (monthIndex < 0 || monthIndex > 11) return null;
    return {
      start: new Date(year, monthIndex, 1),
      end: new Date(year, monthIndex + 1, 1),
      year,
      monthIndex
    };
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

  const filteredOrders = (range = state.selectedTimeframe) => {
    const chartOrders = state.orders.filter((order) => !isArchived(order) && String(order.status || '').toUpperCase() !== 'CANCELLED');
    if (range === 'month') {
      const bounds = selectedMonthBounds();
      return bounds ? chartOrders.filter((order) => {
        const timestamp = orderTime(order);
        return timestamp && timestamp >= bounds.start && timestamp < bounds.end;
      }) : [];
    }
    const start = timeframeStart(range);
    return start ? chartOrders.filter((order) => {
      const timestamp = orderTime(order);
      return timestamp && timestamp >= start;
    }) : chartOrders;
  };

  const timeframeLabel = () => {
    if (state.selectedTimeframe === 'month') {
      const bounds = selectedMonthBounds();
      return bounds ? bounds.start.toLocaleDateString('en-US', { month: 'long', year: 'numeric' }) : 'Selected month';
    }
    return ({
      '24h': 'Last 24 hours',
      '7d': 'Last 7 days',
      '30d': 'Last 30 days',
      '90d': 'Last 90 days',
      year: 'This year',
      all: 'All time'
    })[state.selectedTimeframe] || 'Last 30 days';
  };

  const renderMetrics = () => {
    const last30 = filteredOrders('30d');
    const units = last30.reduce((sum, order) => sum + orderUnits(order), 0);
    const revenue = last30.reduce((sum, order) => sum + orderRevenue(order), 0);
    const nodes = {
      units: document.querySelector('[data-metric-units]'),
      orders: document.querySelector('[data-metric-orders]'),
      average: document.querySelector('[data-metric-average]'),
      revenue: document.querySelector('[data-metric-revenue]')
    };
    if (nodes.units) nodes.units.textContent = String(units);
    if (nodes.orders) nodes.orders.textContent = String(last30.length);
    if (nodes.average) nodes.average.textContent = last30.length ? (units / last30.length).toFixed(1) : '0.0';
    if (nodes.revenue) nodes.revenue.textContent = formatCurrency(revenue);
  };

  const renderAnalytics = () => {
    const total = state.orders.length;
    const active = state.orders.filter((order) => !isArchived(order) && !['CANCELLED', 'CANCELED'].includes(String(order.status || '').toUpperCase())).length;
    const fulfilled = state.orders.filter((order) => ['FULFILLED', 'COMPLETED', 'SHIPPED'].includes(String(order.status || '').toUpperCase())).length;
    const cancelled = state.orders.filter((order) => ['CANCELLED', 'CANCELED'].includes(String(order.status || '').toUpperCase())).length;
    const revenue = state.orders.reduce((sum, order) => sum + orderRevenue(order), 0);

    if (analyticsNodes.active) analyticsNodes.active.textContent = String(active);
    if (analyticsNodes.fulfilled) analyticsNodes.fulfilled.textContent = String(fulfilled);
    if (analyticsNodes.cancelRate) analyticsNodes.cancelRate.textContent = total ? `${Math.round((cancelled / total) * 100)}%` : '0%';
    if (analyticsNodes.revenueOrder) analyticsNodes.revenueOrder.textContent = formatCurrency(total ? revenue / total : 0);

    if (!productMix) return;
    const productUnits = new Map();
    state.orders.forEach((order) => {
      if (isArchived(order) || ['CANCELLED', 'CANCELED'].includes(String(order.status || '').toUpperCase())) return;
      (order.items || []).forEach((item) => {
        const label = item.product || item.sku_label || item.sku_code || 'Product';
        productUnits.set(label, (productUnits.get(label) || 0) + Number(item.quantity || 0));
      });
    });

    const rows = [...productUnits.entries()].sort((left, right) => right[1] - left[1]).slice(0, 12);
    const max = Math.max(1, ...rows.map((row) => row[1]));
    productMix.innerHTML = rows.length ? rows.map(([label, units]) => `
      <article class="partner-product-row">
        <div>
          <strong>${escapeHtml(label)}</strong>
          <span>${escapeHtml(units)} units</span>
        </div>
        <i style="--value:${Math.max(5, Math.round((units / max) * 100))}%"></i>
      </article>
    `).join('') : '<p class="admin-empty">No product data yet.</p>';
  };

  const renderLabelLibrary = () => {
    if (!labelLibrary) return;
    const labels = [];
    state.orders.forEach((order) => {
      (order.labels || []).forEach((label) => {
        labels.push({ order, label });
      });
    });

    labelLibrary.innerHTML = labels.length ? labels.map(({ order, label }) => `
      <article class="partner-label-library-row">
        <div>
          <strong>${escapeHtml(label.name || 'Shipping label')}</strong>
          <span>${escapeHtml(order.id || '')} · ${escapeHtml(order.marketplace_platform || 'Partner')}</span>
        </div>
        <div>
          <span>${escapeHtml(formatTimestamp(label.created_at || order.created_at || ''))}</span>
          ${label.expires_at ? `<span>Deletes ${escapeHtml(formatTimestamp(label.expires_at))}</span>` : ''}
          ${label.url ? `<a href="${escapeHtml(label.url)}" target="_blank" rel="noopener">Open</a>` : '<span>No file URL</span>'}
        </div>
      </article>
    `).join('') : '<p class="admin-empty">No labels uploaded yet.</p>';
  };

  const buildBuckets = (orders) => {
    const range = state.selectedTimeframe;
    const now = new Date();
    const makeDay = (date) => ({
      key: `${date.getFullYear()}-${date.getMonth()}-${date.getDate()}`,
      label: date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' }),
      value: 0
    });

    if (range === 'month') {
      const bounds = selectedMonthBounds();
      if (!bounds) return [];
      const days = new Date(bounds.year, bounds.monthIndex + 1, 0).getDate();
      return Array.from({ length: days }, (_, index) => makeDay(new Date(bounds.year, bounds.monthIndex, index + 1)));
    }

    if (range === '24h') {
      return Array.from({ length: 24 }, (_, index) => {
        const date = new Date(now.getTime() - (23 - index) * 60 * 60 * 1000);
        return { key: `${date.getFullYear()}-${date.getMonth()}-${date.getDate()}-${date.getHours()}`, label: `${String(date.getHours()).padStart(2, '0')}:00`, value: 0 };
      });
    }

    if (range === '7d' || range === '30d' || range === '90d') {
      const days = range === '7d' ? 7 : range === '30d' ? 30 : 90;
      return Array.from({ length: days }, (_, index) => makeDay(new Date(now.getFullYear(), now.getMonth(), now.getDate() - (days - 1 - index))));
    }

    const timestamps = orders.map(orderTime).filter(Boolean);
    const firstOrder = range === 'all' && timestamps.length ? new Date(Math.min(...timestamps.map((date) => date.getTime()))) : new Date(now.getFullYear(), 0, 1);
    const first = new Date(firstOrder.getFullYear(), firstOrder.getMonth(), 1);
    const last = new Date(now.getFullYear(), now.getMonth(), 1);
    const months = ((last.getFullYear() - first.getFullYear()) * 12) + (last.getMonth() - first.getMonth()) + 1;
    return Array.from({ length: months }, (_, index) => {
      const date = new Date(first.getFullYear(), first.getMonth() + index, 1);
      return { key: `${date.getFullYear()}-${date.getMonth()}`, label: date.toLocaleDateString('en-US', { month: 'short' }), value: 0 };
    });
  };

  const renderChart = () => {
    if (!salesChart) return;
    if (timeframeToggle) {
      timeframeToggle.querySelectorAll('[data-timeframe]').forEach((button) => {
        button.classList.toggle('is-active', button.getAttribute('data-timeframe') === state.selectedTimeframe);
      });
    }
    monthPicker?.classList.toggle('is-active', state.selectedTimeframe === 'month');
    if (chartMonthInput instanceof HTMLInputElement && chartMonthInput.value !== state.selectedMonth) {
      chartMonthInput.value = state.selectedMonth;
    }
    if (salesChartTitle) salesChartTitle.textContent = timeframeLabel();

    const orders = filteredOrders();
    const buckets = buildBuckets(orders);
    const bucketMap = new Map(buckets.map((bucket) => [bucket.key, bucket]));
    orders.forEach((order) => {
      const timestamp = orderTime(order);
      if (!timestamp) return;
      const usesDailyBuckets = ['7d', '30d', '90d', 'month'].includes(state.selectedTimeframe);
      const key = state.selectedTimeframe === '24h'
        ? `${timestamp.getFullYear()}-${timestamp.getMonth()}-${timestamp.getDate()}-${timestamp.getHours()}`
        : usesDailyBuckets
          ? `${timestamp.getFullYear()}-${timestamp.getMonth()}-${timestamp.getDate()}`
          : `${timestamp.getFullYear()}-${timestamp.getMonth()}`;
      const bucket = bucketMap.get(key);
      if (bucket) bucket.value += orderUnits(order);
    });

    const maxValue = Math.max(1, ...buckets.map((bucket) => bucket.value));
    salesChart.innerHTML = buckets.map((bucket) => `
      <div class="partner-bar" title="${escapeHtml(bucket.label)}: ${escapeHtml(bucket.value)} units">
        <i style="height:${Math.max(4, Math.round((bucket.value / maxValue) * 100))}%"></i>
        <span>${escapeHtml(bucket.label)}</span>
      </div>
    `).join('');
  };

  const renderRecentOrders = () => {
    if (!recentOrders) return;
    const start = timeframeStart('7d');
    const rows = state.orders.filter((order) => {
      const timestamp = orderTime(order);
      return !isArchived(order) && timestamp && start && timestamp >= start;
    }).slice(0, 4);
    recentOrders.innerHTML = rows.length ? rows.map((order) => `
      <article class="partner-recent-order">
        <div>
          <strong>${escapeHtml(order.id || '')}</strong>
          <span>${escapeHtml(order.marketplace_platform || 'Needs review')} · ${escapeHtml(orderUnits(order))} qty</span>
        </div>
        <div>
          <b>${escapeHtml(statusLabel(order))}</b>
          <span>${escapeHtml(formatTimestamp(order.order_timestamp || order.created_at || ''))}</span>
        </div>
      </article>
    `).join('') : '<p class="admin-empty">No orders from the last 7 days.</p>';
  };

  const renderOrders = () => {
    if (!orderList) return;
    if (!state.orders.length) {
      orderList.innerHTML = '<p class="admin-empty">No orders yet.</p>';
      renderRecentOrders();
      renderMetrics();
      renderChart();
      renderAnalytics();
      renderLabelLibrary();
      return;
    }

    orderList.innerHTML = state.orders.map((order) => {
      const label = (order.labels || [])[0] || null;
      const archiveAction = isArchived(order) ? 'unarchive' : 'archive';
      const archiveLabel = isArchived(order) ? 'Restore' : 'Archive';
      const items = (order.items || []).map((item) => `
        <span>${escapeHtml(item.product || item.sku_label || item.sku_code || 'Product')} <b>x${escapeHtml(item.quantity || 1)}</b></span>
      `).join('');

      return `
        <article class="partner-order-card ${isArchived(order) ? 'is-archived' : ''}">
          <div class="partner-order-card-main">
            <strong>${escapeHtml(order.id || '')}</strong>
            <span>${escapeHtml(order.marketplace_platform || 'Needs review')} · ${escapeHtml(statusLabel(order))}</span>
            ${isArchived(order) ? '<em>Archived · removed after 30 days</em>' : ''}
          </div>
          <div class="partner-order-card-items">${items || '<span>No selected SKUs</span>'}</div>
          <div class="partner-order-card-meta">
            <span>${escapeHtml(formatTimestamp(order.order_timestamp || order.created_at || ''))}</span>
            <span>${escapeHtml(order.deadline_hours || 24)}h deadline</span>
            <span>${escapeHtml(formatCurrency(orderRevenue(order)))}</span>
            ${label ? `<a href="${escapeHtml(label.url || '#')}" target="_blank" rel="noopener">${escapeHtml(label.name || 'Label')}</a>` : '<span>No label</span>'}
          </div>
          <div class="partner-order-card-actions">
            <button type="button" class="admin-ghost-btn" data-order-archive-action="${escapeHtml(archiveAction)}" data-order-archive-id="${escapeHtml(order.id || '')}">${escapeHtml(archiveLabel)}</button>
            ${canCancel(order) ? `<button type="button" class="admin-danger-btn" data-cancel-order="${escapeHtml(order.id || '')}">Cancel</button>` : ''}
          </div>
        </article>
      `;
    }).join('');

    renderRecentOrders();
    renderMetrics();
    renderChart();
    renderAnalytics();
    renderLabelLibrary();
  };

  const renderDeadline = () => {
    if (deadlineValue && deadlineRange) {
      deadlineValue.textContent = `${deadlineRange.value || 24}h`;
    }
    renderPreview();
  };

  const renderLabelQueue = () => {
    if (!labelQueue) return;
    if (!state.labelFile) {
      labelQueue.innerHTML = '<p class="admin-empty">No label file selected.</p>';
      if (labelDropzoneCopy) labelDropzoneCopy.textContent = 'Upload shipping label';
      return;
    }

    labelQueue.innerHTML = `
      <article class="partner-upload-item">
        <div>
          <strong>${escapeHtml(state.labelFile.name)}</strong>
          <span>${escapeHtml(formatFileSize(state.labelFile.size))}</span>
        </div>
        <button type="button" class="admin-ghost-btn" data-remove-queued-file>Remove</button>
      </article>
    `;
    if (labelDropzoneCopy) labelDropzoneCopy.textContent = 'Label selected';
  };

  const productOptions = () => [...new Set(state.approvedSkus.map(skuProductName).filter(Boolean))];

  const flavorOptions = () => {
    const skus = state.selectedProduct
      ? state.approvedSkus.filter((sku) => skuProductName(sku) === state.selectedProduct)
      : state.approvedSkus;
    return [...new Set(skus.map(skuFlavorName).filter(Boolean))];
  };

  const filteredApprovedSkus = () => {
    const query = state.skuSearch.trim().toLowerCase();
    return state.approvedSkus.filter((sku) => {
      if (state.selectedProduct && skuProductName(sku) !== state.selectedProduct) return false;
      if (state.selectedFlavor && skuFlavorName(sku) !== state.selectedFlavor) return false;
      if (!query) return true;
      return [
        sku.sku,
        sku.label,
        sku.tag,
        sku.brand_name,
        sku.product_name,
        sku.base_product_name,
        skuFlavorName(sku),
        sku.size_label
      ].some((value) => String(value || '').toLowerCase().includes(query));
    });
  };

  const renderFilterPills = (container, values, activeValue, dataName, allLabel = 'All') => {
    if (!container) return;
    const buttons = [
      `<button type="button" class="${activeValue === '' ? 'is-active' : ''}" data-${dataName}="">${escapeHtml(allLabel)}</button>`,
      ...values.map((value) => `<button type="button" class="${activeValue === value ? 'is-active' : ''}" data-${dataName}="${escapeHtml(value)}">${escapeHtml(value)}</button>`)
    ];
    container.innerHTML = buttons.join('');
  };

  const renderProductFilters = () => {
    const products = productOptions();
    if (state.selectedProduct && !products.includes(state.selectedProduct)) {
      state.selectedProduct = '';
    }

    const flavors = flavorOptions();
    if (state.selectedFlavor && !flavors.includes(state.selectedFlavor)) {
      state.selectedFlavor = '';
    }

    renderFilterPills(productFilter, products, state.selectedProduct, 'product-value');
    renderFilterPills(flavorFilter, flavors, state.selectedFlavor, 'flavor-value');
  };

  const addSku = (skuCode, quantity = 1) => {
    const sku = state.skuIndex[skuCode];
    if (!sku) return;
    const existing = state.cart.find((item) => item.sku === skuCode);
    if (existing) {
      existing.quantity += quantity;
    } else {
      state.cart.push({ ...sku, quantity });
    }
    renderSkuList();
    renderPreview();
  };

  const updateCartQuantity = (skuCode, quantity) => {
    const nextQuantity = Math.max(0, Number(quantity || 0));
    state.cart = state.cart
      .map((item) => item.sku === skuCode ? { ...item, quantity: nextQuantity } : item)
      .filter((item) => item.quantity > 0);
    renderSkuList();
    renderPreview();
  };

  const renderSkuList = () => {
    renderProductFilters();
    if (!skuList) return;

    const rows = filteredApprovedSkus();
    if (!state.approvedSkus.length) {
      skuList.innerHTML = '<p class="admin-empty">No approved SKUs are enabled for this partner.</p>';
      return;
    }
    if (!rows.length) {
      skuList.innerHTML = '<p class="admin-empty">No approved SKUs match those filters.</p>';
      return;
    }

    skuList.innerHTML = rows.map((sku) => {
      const inCart = state.cart.find((item) => item.sku === sku.sku);
      return `
        <article class="partner-sku-row">
          <div class="partner-sku-main">
            <strong>${escapeHtml(skuDisplayName(sku))}</strong>
            <span>${escapeHtml(sku.brand_name || '')} · ${escapeHtml(sku.size_label || sku.unit_name || '')}</span>
            <code>${escapeHtml(sku.sku || '')}${sku.tag ? ` · ${escapeHtml(sku.tag)}` : ''}</code>
          </div>
          <div class="partner-sku-meta">
            <span>Stock <b>${escapeHtml(sku.current_stock ?? 0)}</b></span>
            <span>${escapeHtml(unitFormula(sku))}</span>
            <strong>${escapeHtml(formatCurrency(sku.partner_price || 0))}</strong>
          </div>
          <div class="partner-sku-actions">
            ${[1, 2, 3].map((qty) => `<button type="button" data-add-sku="${escapeHtml(sku.sku)}" data-add-qty="${qty}">+${qty}</button>`).join('')}
            ${inCart ? `<span>${escapeHtml(inCart.quantity)} selected</span>` : ''}
          </div>
        </article>
      `;
    }).join('');
  };

  const cartTotals = () => state.cart.reduce((totals, item) => {
    const quantity = Number(item.quantity || 0);
    const unitCount = Math.max(1, Number(item.unit_count || 1));
    totals.quantity += quantity;
    totals.billableUnits += quantity * unitCount;
    totals.revenue += quantity * Number(item.partner_price || 0);
    return totals;
  }, { quantity: 0, billableUnits: 0, revenue: 0 });

  const canSubmitCurrentOrder = () => Boolean(state.labelFile && state.cart.length && !state.submitting);

  const renderPreview = () => {
    const totals = cartTotals();
    const platform = platformSelect?.value || 'Needs review';
    const customerName = customerNameInput?.value.trim() || 'Label recipient';
    if (orderPreview) {
      const cartMarkup = state.cart.length ? state.cart.map((item) => `
        <article class="partner-cart-row">
          <div>
            <strong>${escapeHtml(skuDisplayName(item))}</strong>
            <span>${escapeHtml(item.sku || '')} · ${escapeHtml(formatCurrency(item.partner_price || 0))} per SKU</span>
          </div>
          <div class="partner-cart-controls">
            <button type="button" data-cart-qty="${escapeHtml(item.sku)}" data-cart-delta="-1">-</button>
            <input type="number" min="0" step="1" value="${escapeHtml(item.quantity || 0)}" data-cart-input="${escapeHtml(item.sku)}" aria-label="Quantity for ${escapeHtml(skuDisplayName(item))}">
            <button type="button" data-cart-qty="${escapeHtml(item.sku)}" data-cart-delta="1">+</button>
          </div>
        </article>
      `).join('') : '<p class="admin-empty">No approved SKUs selected.</p>';

      orderPreview.innerHTML = `
        <article><span>Platform</span><strong>${escapeHtml(platform)}</strong></article>
        <article><span>Customer</span><strong>${escapeHtml(customerName)}</strong></article>
        <article><span>SKU quantity</span><strong>${escapeHtml(totals.quantity)}</strong></article>
        <article><span>Billable units</span><strong>${escapeHtml(formatNumber(totals.billableUnits))}</strong></article>
        <article><span>Deadline</span><strong>${escapeHtml(deadlineRange?.value || 24)}h</strong></article>
        <article><span>Revenue</span><strong>${escapeHtml(formatCurrency(totals.revenue))}</strong></article>
        <div class="partner-cart-list">${cartMarkup}</div>
      `;
    }
    if (submitOrderButton instanceof HTMLButtonElement) {
      submitOrderButton.disabled = !canSubmitCurrentOrder();
      submitOrderButton.textContent = state.submitting ? 'Submitting...' : 'Submit Order';
    }
  };

  const setLabelFile = (file) => {
    if (file && !isPdfLabelFile(file)) {
      state.labelFile = null;
      setError('Upload a shipment label PDF.', modalErrorNode);
      renderLabelQueue();
      renderPreview();
      return;
    }
    if (file && Number(file.size || 0) > maxLabelBytes) {
      state.labelFile = null;
      setError('Shipment label PDF must be 10 MB or smaller.', modalErrorNode);
      renderLabelQueue();
      renderPreview();
      return;
    }

    state.labelFile = file;
    setError('', modalErrorNode);
    if (file && platformSelect instanceof HTMLSelectElement && platformSelect.value === 'Needs review') {
      platformSelect.value = detectPlatformFromFileName(file.name);
    }
    renderLabelQueue();
    renderPreview();
  };

  const openOrderModal = () => {
    if (!(orderModal instanceof HTMLElement) || !(orderForm instanceof HTMLFormElement)) return;
    state.labelFile = null;
    state.selectedProduct = '';
    state.selectedFlavor = '';
    state.skuSearch = '';
    state.cart = [];
    state.submitting = false;
    orderModal.hidden = false;
    orderForm.reset();
    if (orderForm.elements.order_timestamp) orderForm.elements.order_timestamp.value = datetimeLocalValue();
    if (deadlineRange instanceof HTMLInputElement) deadlineRange.value = '24';
    if (platformSelect instanceof HTMLSelectElement) platformSelect.value = 'Needs review';
    if (skuSearchInput instanceof HTMLInputElement) skuSearchInput.value = '';
    setError('', modalErrorNode);
    renderDeadline();
    renderLabelQueue();
    renderSkuList();
    renderPreview();
  };

  const closeOrderModal = () => {
    if (!(orderModal instanceof HTMLElement) || !(orderForm instanceof HTMLFormElement)) return;
    orderModal.hidden = true;
    state.labelFile = null;
    state.selectedProduct = '';
    state.selectedFlavor = '';
    state.skuSearch = '';
    state.cart = [];
    state.submitting = false;
    orderForm.reset();
    setError('', modalErrorNode);
  };

  const openPasswordModal = () => {
    if (!(passwordModal instanceof HTMLElement) || !(passwordForm instanceof HTMLFormElement)) return;
    passwordModal.hidden = false;
    passwordForm.reset();
    setError('', passwordErrorNode);
    renderPasswordResetState();
    if (state.passwordResetRequired) {
      passwordForm.elements.new_password.focus();
      return;
    }
    passwordForm.elements.current_password.focus();
  };

  const closePasswordModal = () => {
    if (!(passwordModal instanceof HTMLElement) || !(passwordForm instanceof HTMLFormElement)) return;
    passwordModal.hidden = true;
    passwordForm.reset();
    setError('', passwordErrorNode);
    renderPasswordResetState();
  };

  const loadOrders = async () => {
    const payload = await requestJson(ordersEndpoint);
    state.orders = payload.orders || [];
    renderOrders();
  };

  const loadSession = async () => {
    const payload = await requestJson(sessionEndpoint);
    state.partner = payload.partner || null;
    state.catalog = payload.catalog || {};
    state.passwordResetRequired = Boolean(payload.password_reset_required);
    flattenCatalog();
    if (partnerNameNode) partnerNameNode.textContent = state.partner?.name || 'Partner';
    if (partnerCodeNode) partnerCodeNode.textContent = state.partner?.code ? `Workspace ${state.partner.code}` : 'Direct ordering portal';
    renderPasswordResetState();
    if (state.passwordResetRequired && passwordModal instanceof HTMLElement && passwordModal.hidden) {
      openPasswordModal();
    }
    renderSkuList();
    renderPreview();
  };

  document.querySelectorAll('[data-open-order-modal]').forEach((button) => {
    button.addEventListener('click', openOrderModal);
  });
  document.querySelectorAll('[data-close-order-modal]').forEach((button) => {
    button.addEventListener('click', closeOrderModal);
  });
  document.querySelector('[data-open-password-modal]')?.addEventListener('click', openPasswordModal);
  document.querySelectorAll('[data-close-password-modal]').forEach((button) => {
    button.addEventListener('click', closePasswordModal);
  });

  deadlineRange?.addEventListener('input', renderDeadline);

  labelDropzone?.addEventListener('click', () => labelInput?.click());
  labelDropzone?.addEventListener('dragover', (event) => {
    event.preventDefault();
    labelDropzone.classList.add('is-dragover');
  });
  labelDropzone?.addEventListener('dragleave', () => {
    labelDropzone.classList.remove('is-dragover');
  });
  labelDropzone?.addEventListener('drop', (event) => {
    event.preventDefault();
    labelDropzone.classList.remove('is-dragover');
    const file = event.dataTransfer?.files?.[0] || null;
    if (file) setLabelFile(file);
  });
  labelInput?.addEventListener('change', () => {
    const file = labelInput.files?.[0] || null;
    labelInput.value = '';
    if (file) setLabelFile(file);
  });
  labelQueue?.addEventListener('click', (event) => {
    const target = event.target;
    if (!(target instanceof HTMLElement) || !target.matches('[data-remove-queued-file]')) return;
    setLabelFile(null);
    renderLabelQueue();
  });

  skuSearchInput?.addEventListener('input', () => {
    state.skuSearch = skuSearchInput.value || '';
    renderSkuList();
  });

  productFilter?.addEventListener('click', (event) => {
    const button = event.target instanceof Element ? event.target.closest('[data-product-value]') : null;
    if (!(button instanceof HTMLButtonElement)) return;
    state.selectedProduct = button.getAttribute('data-product-value') || '';
    state.selectedFlavor = '';
    renderSkuList();
  });

  flavorFilter?.addEventListener('click', (event) => {
    const button = event.target instanceof Element ? event.target.closest('[data-flavor-value]') : null;
    if (!(button instanceof HTMLButtonElement)) return;
    state.selectedFlavor = button.getAttribute('data-flavor-value') || '';
    renderSkuList();
  });

  skuList?.addEventListener('click', (event) => {
    const button = event.target instanceof Element ? event.target.closest('[data-add-sku]') : null;
    if (!(button instanceof HTMLButtonElement)) return;
    addSku(button.getAttribute('data-add-sku') || '', Math.max(1, Number(button.getAttribute('data-add-qty') || 1)));
  });

  [platformSelect, customerNameInput].forEach((input) => {
    input?.addEventListener('input', renderPreview);
    input?.addEventListener('change', renderPreview);
  });

  orderPreview?.addEventListener('click', (event) => {
    const button = event.target instanceof Element ? event.target.closest('[data-cart-qty]') : null;
    if (!(button instanceof HTMLButtonElement)) return;
    const skuCode = button.getAttribute('data-cart-qty') || '';
    const delta = Number(button.getAttribute('data-cart-delta') || 0);
    const item = state.cart.find((row) => row.sku === skuCode);
    updateCartQuantity(skuCode, Number(item?.quantity || 0) + delta);
  });

  orderPreview?.addEventListener('change', (event) => {
    const input = event.target;
    if (!(input instanceof HTMLInputElement)) return;
    const skuCode = input.getAttribute('data-cart-input') || '';
    if (!skuCode) return;
    updateCartQuantity(skuCode, Number(input.value || 0));
  });

  orderForm?.addEventListener('submit', async (event) => {
    event.preventDefault();
    setError('', modalErrorNode);

    if (!canSubmitCurrentOrder()) {
      setError('Upload a label and select at least one approved SKU.', modalErrorNode);
      return;
    }

    state.submitting = true;
    renderPreview();

    try {
      const formData = new window.FormData(orderForm);
      const platform = formData.get('marketplace_platform') || platformSelect?.value || 'Needs review';
      const customerName = formData.get('customer_name') || '';
      const orderPayload = {
        action: 'create',
        order_timestamp: formData.get('order_timestamp'),
        deadline_hours: formData.get('deadline_hours'),
        marketplace_platform: platform,
        customer_name: customerName,
        items: state.cart.map((item) => ({
          sku_code: item.sku,
          quantity: item.quantity
        })),
        inference: {
          source: 'manual_approved_sku_selection',
          platform: {
            platform,
            confidence: platform === 'Needs review' ? 0 : 1,
            reasons: state.labelFile ? ['label_uploaded'] : []
          },
          customer_name: String(customerName || ''),
          items: state.cart.map((item) => ({
            sku_code: item.sku,
            sku_label: item.label,
            product: skuProductName(item),
            flavor: skuFlavorName(item),
            quantity: item.quantity
          })),
          label_file_name: state.labelFile?.name || '',
          analyzed_at: new Date().toISOString()
        }
      };

      const multipart = new window.FormData();
      multipart.append('payload', JSON.stringify(orderPayload));
      multipart.append('labels[]', state.labelFile, state.labelFile.name);
      await postOrderForm(multipart);

      closeOrderModal();
      await loadOrders();
    } catch (error) {
      setError(error instanceof Error ? error.message : 'Unable to submit order.', modalErrorNode);
    } finally {
      state.submitting = false;
      renderPreview();
    }
  });

  orderList?.addEventListener('click', async (event) => {
    const target = event.target;
    if (!(target instanceof HTMLElement)) return;

    const cancelId = target.getAttribute('data-cancel-order');
    const archiveId = target.getAttribute('data-order-archive-id');
    const archiveAction = target.getAttribute('data-order-archive-action');

    if (archiveId && archiveAction) {
      try {
        await requestJson(ordersEndpoint, {
          method: 'POST',
          body: { action: archiveAction, id: archiveId }
        });
        await loadOrders();
      } catch (error) {
        setError(error instanceof Error ? error.message : 'Unable to update archive state.');
      }
      return;
    }

    if (cancelId) {
      try {
        await requestJson(ordersEndpoint, {
          method: 'POST',
          body: { action: 'cancel', id: cancelId }
        });
        await loadOrders();
      } catch (error) {
        setError(error instanceof Error ? error.message : 'Unable to cancel order.');
      }
    }
  });

  timeframeToggle?.addEventListener('click', (event) => {
    const target = event.target;
    if (!(target instanceof HTMLElement)) return;
    const nextTimeframe = target.getAttribute('data-timeframe');
    if (!nextTimeframe) return;
    state.selectedTimeframe = nextTimeframe;
    renderChart();
  });

  if (chartMonthInput instanceof HTMLInputElement) {
    chartMonthInput.max = currentChartMonth;
    chartMonthInput.value = state.selectedMonth;
    chartMonthInput.addEventListener('change', () => {
      if (!/^\d{4}-\d{2}$/.test(chartMonthInput.value)) return;
      state.selectedMonth = chartMonthInput.value;
      state.selectedTimeframe = 'month';
      renderChart();
    });
  }

  sectionLinks.forEach((link) => {
    link.addEventListener('click', (event) => {
      const section = link.getAttribute('data-partner-section-link');
      if (!section) return;
      event.preventDefault();
      setActiveSection(section, true);
    });
  });

  window.addEventListener('popstate', (event) => {
    const section = event.state?.section || sectionFromLocation();
    setActiveSection(section, false);
  });

  themeSwitches.forEach((switcher) => {
    switcher.addEventListener('click', (event) => {
      const target = event.target instanceof Element ? event.target.closest('[data-theme-option]') : null;
      if (!(target instanceof HTMLButtonElement)) return;
      applyTheme(target.getAttribute('data-theme-option') || 'system');
    });
  });

  document.querySelector('[data-refresh-orders]')?.addEventListener('click', () => {
    loadOrders().catch((error) => setError(error instanceof Error ? error.message : 'Unable to refresh orders.'));
  });

  passwordForm?.addEventListener('submit', async (event) => {
    event.preventDefault();
    setError('', passwordErrorNode);

    const formData = new window.FormData(passwordForm);
    const newPassword = String(formData.get('new_password') || '');
    const confirmPassword = String(formData.get('confirm_password') || '');
    if (newPassword !== confirmPassword) {
      setError('New passwords do not match.', passwordErrorNode);
      return;
    }

    try {
      const passwordResult = await requestJson(sessionEndpoint, {
        method: 'POST',
        body: {
          action: 'change_password',
          current_password: state.passwordResetRequired ? '' : formData.get('current_password'),
          new_password: newPassword,
          confirm_password: confirmPassword
        }
      });
      csrfToken = String(passwordResult.csrf_token || csrfToken);
      state.passwordResetRequired = false;
      renderPasswordResetState();
      closePasswordModal();
    } catch (error) {
      setError(error instanceof Error ? error.message : 'Unable to update password.', passwordErrorNode);
    }
  });

  document.querySelector('[data-partner-logout]')?.addEventListener('click', async () => {
    try {
      await requestJson(sessionEndpoint, { method: 'DELETE' });
      window.location.href = logoutUrl.replace(/logout\/?$/, '');
    } catch (_) {
      window.location.href = logoutUrl;
    }
  });

  window.addEventListener('focus', () => {
    loadOrders().catch(() => {});
  });

  window.setInterval(() => {
    loadOrders().catch(() => {});
  }, 15000);

  applyTheme(state.theme);
  window.history.replaceState({ section: state.activeSection }, '', window.location.href);
  setActiveSection(state.activeSection, false);

  Promise.all([loadSession(), loadOrders()]).catch((error) => {
    setError(error instanceof Error ? error.message : 'Unable to load dashboard.');
  });
});
