document.addEventListener('DOMContentLoaded', () => {
  const root = document.querySelector('[data-partner-dashboard]');
  if (!root) return;

  const sessionEndpoint = root.dataset.sessionEndpoint || '../api/session/';
  const ordersEndpoint = root.dataset.ordersEndpoint || '../api/orders/';
  const labelsEndpoint = root.dataset.labelsEndpoint || '../api/order-labels/';
  const logoutUrl = root.dataset.logoutUrl || '../logout/';
  const dashboardBase = root.dataset.dashboardBase || './';

  const orderModal = document.querySelector('[data-order-modal]');
  const orderForm = document.querySelector('[data-order-form]');
  const passwordModal = document.querySelector('[data-password-modal]');
  const passwordForm = document.querySelector('[data-password-form]');
  const orderList = document.querySelector('[data-order-list]');
  const recentOrders = document.querySelector('[data-recent-orders]');
  const errorNode = document.querySelector('[data-order-error]');
  const modalErrorNode = document.querySelector('[data-modal-order-error]');
  const passwordErrorNode = document.querySelector('[data-password-error]');
  const partnerNameNode = document.querySelector('[data-partner-name]');
  const partnerCodeNode = document.querySelector('[data-partner-code]');
  const timeframeToggle = document.querySelector('[data-timeframe-toggle]');
  const salesChart = document.querySelector('[data-sales-chart]');
  const salesChartTitle = document.querySelector('[data-sales-chart-title]');
  const labelDropzone = document.querySelector('[data-label-dropzone]');
  const labelDropzoneCopy = document.querySelector('[data-label-dropzone-copy]');
  const labelInput = document.querySelector('[data-label-input]');
  const labelQueue = document.querySelector('[data-label-queue]');
  const deadlineRange = document.querySelector('[data-deadline-range]');
  const deadlineValue = document.querySelector('[data-deadline-value]');
  const analysisPlatform = document.querySelector('[data-analysis-platform]');
  const analysisConfidence = document.querySelector('[data-analysis-confidence]');
  const analysisReasons = document.querySelector('[data-analysis-reasons]');
  const analysisItems = document.querySelector('[data-analysis-items]');
  const analysisItemCount = document.querySelector('[data-analysis-item-count]');
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

  const state = {
    partner: null,
    catalog: {},
    skuIndex: {},
    orders: [],
    selectedTimeframe: '30d',
    activeSection: root.dataset.activeSection || 'overview',
    theme: window.localStorage.getItem('partner-theme') || 'system',
    labelFile: null,
    labelAnalysis: null,
    analyzing: false,
    submitting: false
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

  const setError = (message, target = errorNode) => {
    if (!target) return;
    target.hidden = !message;
    target.textContent = message || '';
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

  const flattenCatalog = () => {
    const skuIndex = {};
    Object.values(state.catalog || {}).forEach((products) => {
      Object.values(products || {}).forEach((productData) => {
        (productData.skus || []).forEach((sku) => {
          if (!sku?.sku) return;
          skuIndex[sku.sku] = sku;
        });
      });
    });
    state.skuIndex = skuIndex;
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
    const start = timeframeStart(range);
    const chartOrders = state.orders.filter((order) => !isArchived(order) && String(order.status || '').toUpperCase() !== 'CANCELLED');
    return start ? chartOrders.filter((order) => {
      const timestamp = orderTime(order);
      return timestamp && timestamp >= start;
    }) : chartOrders;
  };

  const timeframeLabel = () => ({
    '24h': 'Last 24 hours',
    '7d': 'Last 7 days',
    '30d': 'Last 30 days',
    '90d': 'Last 90 days',
    year: 'This year',
    all: 'All time'
  })[state.selectedTimeframe] || 'Last 30 days';

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
    if (salesChartTitle) salesChartTitle.textContent = timeframeLabel();

    const orders = filteredOrders();
    const buckets = buildBuckets(orders);
    const bucketMap = new Map(buckets.map((bucket) => [bucket.key, bucket]));
    orders.forEach((order) => {
      const timestamp = orderTime(order);
      if (!timestamp) return;
      const key = state.selectedTimeframe === '24h'
        ? `${timestamp.getFullYear()}-${timestamp.getMonth()}-${timestamp.getDate()}-${timestamp.getHours()}`
        : (state.selectedTimeframe === '7d' || state.selectedTimeframe === '30d' || state.selectedTimeframe === '90d')
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
    const rows = state.orders.slice(0, 4);
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
    `).join('') : '<p class="admin-empty">No reconstructed orders yet.</p>';
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
            ${isArchived(order) ? '<em>Archived from charts</em>' : ''}
          </div>
          <div class="partner-order-card-items">${items || '<span>No matched items</span>'}</div>
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
    if (labelDropzoneCopy) labelDropzoneCopy.textContent = state.analyzing ? 'Analyzing label' : 'Label selected';
  };

  const renderAnalysis = () => {
    const analysis = state.labelAnalysis || {};
    const platform = analysis.platform || {};
    const items = Array.isArray(analysis.items) ? analysis.items : [];
    const platformName = platform.platform || (state.analyzing ? 'Analyzing label' : 'Waiting for label');
    const confidence = Math.round(Number(platform.confidence || 0) * 100);

    if (analysisPlatform) analysisPlatform.textContent = platformName;
    if (analysisConfidence) analysisConfidence.textContent = `${confidence}%`;
    if (analysisReasons) analysisReasons.textContent = (platform.reasons || []).length ? `Platform evidence: ${(platform.reasons || []).join(', ')}` : 'No label analyzed yet.';
    if (analysisItemCount) analysisItemCount.textContent = `${items.length} product${items.length === 1 ? '' : 's'}`;

    if (analysisItems) {
      analysisItems.innerHTML = items.length ? items.map((item) => `
        <article class="partner-match-row">
          <div>
            <strong>${escapeHtml(item.product || item.sku_label || item.sku_code || 'Product')}</strong>
            <span>${escapeHtml(item.matched_alias || item.flavor || item.size || 'Matched from product text')}</span>
          </div>
          <code>${escapeHtml((item.match_evidence || []).map((entry) => entry.phrase).filter(Boolean).slice(0, 2).join(' / ') || item.flavor || 'Product evidence')}</code>
          <b>x${escapeHtml(item.quantity || 1)}</b>
          <span>${escapeHtml(Math.round(Number(item.match_confidence || 0) * 100))}%</span>
        </article>
      `).join('') : '<p class="admin-empty">No products matched this label.</p>';
    }

    renderPreview();
  };

  const canSubmitCurrentOrder = () => {
    const analysis = state.labelAnalysis || {};
    const items = Array.isArray(analysis.items) ? analysis.items : [];
    const platform = String(analysis.platform?.platform || '');
    return Boolean(state.labelFile && items.length && platform !== '' && platform !== 'Needs review' && !state.analyzing && !state.submitting);
  };

  const renderPreview = () => {
    const analysis = state.labelAnalysis || {};
    const items = Array.isArray(analysis.items) ? analysis.items : [];
    const revenue = items.reduce((sum, item) => sum + Number(item.line_revenue || 0), 0);
    if (orderPreview) {
      orderPreview.innerHTML = `
        <article><span>Platform</span><strong>${escapeHtml(analysis.platform?.platform || 'Waiting for label')}</strong></article>
        <article><span>Customer</span><strong>${escapeHtml(analysis.customer_name || 'Label recipient')}</strong></article>
        <article><span>Items</span><strong>${escapeHtml(items.length)} matched product${items.length === 1 ? '' : 's'}</strong></article>
        <article><span>Deadline</span><strong>${escapeHtml(deadlineRange?.value || 24)}h</strong></article>
        <article><span>Revenue</span><strong>${escapeHtml(formatCurrency(revenue))}</strong></article>
      `;
    }
    if (submitOrderButton instanceof HTMLButtonElement) {
      submitOrderButton.disabled = !canSubmitCurrentOrder();
      submitOrderButton.textContent = state.submitting ? 'Submitting...' : 'Submit Reconstructed Order';
    }
  };

  const analyzeLabel = async (file) => {
    state.labelFile = file;
    state.labelAnalysis = null;
    state.analyzing = true;
    setError('', modalErrorNode);
    renderLabelQueue();
    renderAnalysis();

    try {
      const formData = new window.FormData();
      formData.append('action', 'analyze');
      formData.append('labels[]', file);
      const payload = await postLabelForm(formData);
      state.labelAnalysis = payload.analysis || null;
    } catch (error) {
      state.labelAnalysis = null;
      setError(error instanceof Error ? error.message : 'Unable to analyze label.', modalErrorNode);
    } finally {
      state.analyzing = false;
      renderLabelQueue();
      renderAnalysis();
    }
  };

  const uploadLabel = async (orderId, file) => {
    const formData = new window.FormData();
    formData.append('order_id', orderId);
    formData.append('labels[]', file);
    return postLabelForm(formData);
  };

  const openOrderModal = () => {
    if (!(orderModal instanceof HTMLElement) || !(orderForm instanceof HTMLFormElement)) return;
    state.labelFile = null;
    state.labelAnalysis = null;
    state.analyzing = false;
    state.submitting = false;
    orderModal.hidden = false;
    orderForm.reset();
    if (orderForm.elements.order_timestamp) orderForm.elements.order_timestamp.value = datetimeLocalValue();
    if (deadlineRange instanceof HTMLInputElement) deadlineRange.value = '24';
    setError('', modalErrorNode);
    renderDeadline();
    renderLabelQueue();
    renderAnalysis();
  };

  const closeOrderModal = () => {
    if (!(orderModal instanceof HTMLElement) || !(orderForm instanceof HTMLFormElement)) return;
    orderModal.hidden = true;
    state.labelFile = null;
    state.labelAnalysis = null;
    state.analyzing = false;
    state.submitting = false;
    orderForm.reset();
    setError('', modalErrorNode);
  };

  const openPasswordModal = () => {
    if (!(passwordModal instanceof HTMLElement) || !(passwordForm instanceof HTMLFormElement)) return;
    passwordModal.hidden = false;
    passwordForm.reset();
    setError('', passwordErrorNode);
    passwordForm.elements.current_password.focus();
  };

  const closePasswordModal = () => {
    if (!(passwordModal instanceof HTMLElement) || !(passwordForm instanceof HTMLFormElement)) return;
    passwordModal.hidden = true;
    passwordForm.reset();
    setError('', passwordErrorNode);
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
    flattenCatalog();
    if (partnerNameNode) partnerNameNode.textContent = state.partner?.name || 'Partner';
    if (partnerCodeNode) partnerCodeNode.textContent = state.partner?.code ? `Workspace ${state.partner.code}` : 'Direct ordering portal';
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
    if (file) analyzeLabel(file);
  });
  labelInput?.addEventListener('change', () => {
    const file = labelInput.files?.[0] || null;
    labelInput.value = '';
    if (file) analyzeLabel(file);
  });
  labelQueue?.addEventListener('click', (event) => {
    const target = event.target;
    if (!(target instanceof HTMLElement) || !target.matches('[data-remove-queued-file]')) return;
    state.labelFile = null;
    state.labelAnalysis = null;
    renderLabelQueue();
    renderAnalysis();
  });

  orderForm?.addEventListener('submit', async (event) => {
    event.preventDefault();
    setError('', modalErrorNode);

    if (!canSubmitCurrentOrder()) {
      setError('Upload a label with a detected platform and at least one matched product.', modalErrorNode);
      return;
    }

    state.submitting = true;
    renderPreview();

    try {
      const formData = new window.FormData(orderForm);
      const analysis = state.labelAnalysis || {};
      const payload = await requestJson(ordersEndpoint, {
        method: 'POST',
        body: {
          action: 'create',
          order_timestamp: formData.get('order_timestamp'),
          deadline_hours: formData.get('deadline_hours'),
          marketplace_platform: analysis.platform?.platform || 'Needs review',
          customer_name: analysis.customer_name || '',
          items: (analysis.items || []).map((item) => ({
            sku_code: item.sku_code,
            quantity: item.quantity,
            unit_revenue: item.unit_revenue,
            line_revenue: item.line_revenue,
            match_confidence: item.match_confidence,
            match_score: item.match_score,
            matched_alias: item.matched_alias,
            match_evidence: item.match_evidence || []
          })),
          inference: analysis
        }
      });

      const savedOrder = payload.order || null;
      if (savedOrder?.id && state.labelFile) {
        await uploadLabel(savedOrder.id, state.labelFile);
      }

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
      await requestJson(sessionEndpoint, {
        method: 'POST',
        body: {
          action: 'change_password',
          current_password: formData.get('current_password'),
          new_password: newPassword,
          confirm_password: confirmPassword
        }
      });
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
