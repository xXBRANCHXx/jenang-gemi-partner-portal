document.addEventListener('DOMContentLoaded', () => {
  const root = document.querySelector('[data-partner-dashboard]');
  if (!root) return;

  const sessionEndpoint = root.dataset.sessionEndpoint || '../api/session/';
  const ordersEndpoint = root.dataset.ordersEndpoint || '../api/orders/';
  const labelsEndpoint = root.dataset.labelsEndpoint || '../api/order-labels/';
  const orderForm = document.querySelector('[data-order-form]');
  const orderList = document.querySelector('[data-order-list]');
  const errorNode = document.querySelector('[data-order-error]');
  const partnerNameNode = document.querySelector('[data-partner-name]');
  const partnerCodeNode = document.querySelector('[data-partner-code]');
  const allowedBrandsNode = document.querySelector('[data-allowed-brands]');
  const allowedProductsNode = document.querySelector('[data-allowed-products]');
  const brandCountNode = document.querySelector('[data-brand-count]');
  const productCountNode = document.querySelector('[data-product-count]');
  const orderCountNode = document.querySelector('[data-order-count]');
  const busiestHourNode = document.querySelector('[data-busiest-hour]');
  const storageModeNode = document.querySelector('[data-storage-mode]');
  const brandSelect = document.querySelector('[name="brand"]');
  const productSelect = document.querySelector('[name="product"]');
  const skuSelect = document.querySelector('[name="sku_code"]');
  const selectedSkuName = document.querySelector('[data-selected-sku-name]');
  const selectedSkuMeta = document.querySelector('[data-selected-sku-meta]');
  const yearToggle = document.querySelector('[data-year-toggle]');
  const monthlyChart = document.querySelector('[data-monthly-chart]');
  const hourlyChart = document.querySelector('[data-hourly-chart]');
  const labelDropzone = document.querySelector('[data-label-dropzone]');
  const labelInput = document.querySelector('[data-label-input]');
  const labelQueue = document.querySelector('[data-label-queue]');

  const state = {
    partner: null,
    catalog: {},
    orders: [],
    analytics: {
      years: [],
      monthly_by_year: {},
      hourly_distribution: Array.from({ length: 24 }, () => 0),
      busiest_hour: '00:00',
      total_orders: 0
    },
    editingId: '',
    selectedYear: '',
    storage: 'json',
    queuedFiles: []
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

  const uploadLabels = async (orderId, files) => {
    const formData = new window.FormData();
    formData.append('order_id', orderId);
    files.forEach((file) => {
      formData.append('labels[]', file);
    });

    const response = await fetch(labelsEndpoint, {
      method: 'POST',
      credentials: 'same-origin',
      body: formData
    });
    const payload = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(payload.error || `HTTP ${response.status}`);
    return payload;
  };

  const setError = (message) => {
    if (!errorNode) return;
    errorNode.hidden = !message;
    errorNode.textContent = message || '';
  };

  const optionMarkup = (items, placeholder) => [`<option value="">${escapeHtml(placeholder)}</option>`, ...items.map((item) => `<option value="${escapeHtml(item)}">${escapeHtml(item)}</option>`)].join('');

  const currentProducts = () => Object.keys(state.catalog[brandSelect?.value || ''] || {});
  const currentSkuRecords = () => {
    const brand = brandSelect?.value || '';
    const product = productSelect?.value || '';
    return state.catalog[brand]?.[product]?.skus || [];
  };

  const currentSkuRecord = () => currentSkuRecords().find((sku) => sku.sku === (skuSelect?.value || '')) || null;

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

  const formatFileSize = (bytes) => {
    const size = Number(bytes || 0);
    if (size >= 1024 * 1024) return `${(size / (1024 * 1024)).toFixed(1)} MB`;
    if (size >= 1024) return `${Math.round(size / 1024)} KB`;
    return `${size} B`;
  };

  const renderSkuCard = () => {
    const sku = currentSkuRecord();
    if (!sku) {
      if (selectedSkuName) selectedSkuName.textContent = 'Select a SKU';
      if (selectedSkuMeta) selectedSkuMeta.textContent = 'The selected SKU details will show here.';
      return;
    }

    if (selectedSkuName) selectedSkuName.textContent = sku.label || sku.sku;
    if (selectedSkuMeta) {
      const parts = [sku.sku];
      if (sku.flavor) parts.push(sku.flavor);
      if (sku.size) parts.push(sku.size);
      parts.push(`Stock ${sku.stock ?? 0}`);
      selectedSkuMeta.textContent = parts.join(' • ');
    }
  };

  const renderFileQueue = () => {
    if (!labelQueue) return;
    if (!state.queuedFiles.length) {
      labelQueue.innerHTML = '<p class="admin-empty">No label files queued.</p>';
      return;
    }

    labelQueue.innerHTML = state.queuedFiles.map((file, index) => `
      <article class="partner-upload-item">
        <div>
          <strong>${escapeHtml(file.name)}</strong>
          <span>${escapeHtml(formatFileSize(file.size))}</span>
        </div>
        <button type="button" class="admin-ghost-btn" data-remove-queued-file="${index}">Remove</button>
      </article>
    `).join('');
  };

  const setQueuedFiles = (files) => {
    state.queuedFiles = [...state.queuedFiles, ...Array.from(files || [])];
    renderFileQueue();
  };

  const refreshBrandOptions = () => {
    const brands = state.partner?.companies || [];
    if (!(brandSelect instanceof HTMLSelectElement)) return;
    const current = brandSelect.value;
    brandSelect.innerHTML = optionMarkup(brands, 'Select brand');
    brandSelect.value = brands.includes(current) ? current : (brands[0] || '');
    refreshProductOptions();
  };

  const refreshProductOptions = () => {
    const products = currentProducts();
    if (!(productSelect instanceof HTMLSelectElement)) return;
    const current = productSelect.value;
    productSelect.innerHTML = optionMarkup(products, 'Select product');
    productSelect.value = products.includes(current) ? current : (products[0] || '');
    refreshSkuOptions();
  };

  const refreshSkuOptions = () => {
    if (!(skuSelect instanceof HTMLSelectElement)) return;
    const skuRecords = currentSkuRecords();
    const current = skuSelect.value;
    skuSelect.innerHTML = [
      '<option value="">Select SKU</option>',
      ...skuRecords.map((sku) => `<option value="${escapeHtml(sku.sku)}">${escapeHtml(sku.label || sku.sku)}</option>`)
    ].join('');
    skuSelect.value = skuRecords.some((sku) => sku.sku === current) ? current : ((skuRecords[0] || {}).sku || '');
    renderSkuCard();
  };

  const renderOrders = () => {
    if (!orderList) return;
    if (!state.orders.length) {
      orderList.innerHTML = '<tr><td colspan="7" class="partner-order-empty">No orders yet.</td></tr>';
      return;
    }
    orderList.innerHTML = state.orders.map((order) => `
      <tr>
        <td>
          <strong>${escapeHtml(order.id || '')}</strong>
          <span>${escapeHtml(order.brand || '')} • ${escapeHtml(order.product || '')}</span>
        </td>
        <td>${escapeHtml(order.customer_name || '')}</td>
        <td>
          <strong>${escapeHtml(order.sku_code || '')}</strong>
          <span>${escapeHtml(order.sku_label || '')}</span>
        </td>
        <td>${escapeHtml(order.quantity || 1)}</td>
        <td>
          <div class="partner-label-list">
            ${(order.labels || []).length ? (order.labels || []).map((label) => `<a href="${escapeHtml(label.url || '#')}" target="_blank" rel="noopener">${escapeHtml(label.name || 'Label')}</a>`).join('') : '<span>No files</span>'}
          </div>
        </td>
        <td>${escapeHtml(formatTimestamp(order.updated_at || order.created_at || ''))}</td>
        <td>
          <div class="partner-table-actions">
            <button type="button" class="admin-primary-btn" data-edit-order="${escapeHtml(order.id || '')}">Edit</button>
            <button type="button" class="admin-ghost-btn" data-delete-order="${escapeHtml(order.id || '')}">Delete</button>
          </div>
        </td>
      </tr>
    `).join('');
  };

  const renderAnalytics = () => {
    const years = state.analytics.years || [];
    const activeYear = state.selectedYear || years[years.length - 1] || '';
    if (!state.selectedYear && activeYear) state.selectedYear = activeYear;

    if (yearToggle) {
      yearToggle.innerHTML = years.length ? years.map((year) => `
        <button type="button" class="${year === activeYear ? 'is-active' : ''}" data-analytics-year="${escapeHtml(year)}">${escapeHtml(year)}</button>
      `).join('') : '<span class="admin-empty">No year data yet.</span>';
    }

    if (monthlyChart) {
      const monthLabels = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
      const monthly = state.analytics.monthly_by_year?.[activeYear] || Array.from({ length: 12 }, () => 0);
      const maxValue = Math.max(1, ...monthly);
      monthlyChart.innerHTML = monthly.map((count, index) => `
        <div class="partner-chart-bar">
          <span>${escapeHtml(monthLabels[index])}</span>
          <div class="partner-chart-bar-track"><i style="height:${Math.max(8, Math.round((count / maxValue) * 100))}%"></i></div>
          <strong>${escapeHtml(count)}</strong>
        </div>
      `).join('');
    }

    if (hourlyChart) {
      const hourly = state.analytics.hourly_distribution || Array.from({ length: 24 }, () => 0);
      const maxValue = Math.max(1, ...hourly);
      hourlyChart.innerHTML = hourly.map((count, hour) => `
        <div class="partner-hour-row">
          <span>${escapeHtml(String(hour).padStart(2, '0'))}:00</span>
          <div class="partner-hour-track"><i style="width:${Math.max(8, Math.round((count / maxValue) * 100))}%"></i></div>
          <strong>${escapeHtml(count)}</strong>
        </div>
      `).join('');
    }
  };

  const renderPartner = () => {
    if (partnerNameNode) partnerNameNode.textContent = state.partner?.name || 'Partner';
    if (partnerCodeNode) partnerCodeNode.textContent = state.partner?.code || 'Partner';
    if (brandCountNode) brandCountNode.textContent = String((state.partner?.companies || []).length);
    if (allowedBrandsNode) {
      allowedBrandsNode.textContent = (state.partner?.companies || []).join(', ') || 'No brands assigned.';
    }
    if (allowedProductsNode) {
      const summary = [];
      Object.entries(state.partner?.product_access || {}).forEach(([brand, products]) => {
        Object.entries(products || {}).forEach(([product, config]) => {
          if (!config?.enabled) return;
          summary.push(`${brand} / ${product}`);
        });
      });
      if (productCountNode) productCountNode.textContent = String(summary.length);
      allowedProductsNode.textContent = summary.join(', ') || 'No products assigned.';
    }
    if (storageModeNode) {
      storageModeNode.textContent = state.storage === 'mysql' ? 'MySQL Live' : 'JSON Fallback';
    }
    refreshBrandOptions();
  };

  const loadOrders = async () => {
    const payload = await requestJson(ordersEndpoint);
    state.orders = payload.orders || [];
    state.analytics = payload.analytics || state.analytics;
    state.storage = payload.storage || state.storage;
    if (orderCountNode) orderCountNode.textContent = String(state.analytics.total_orders || state.orders.length);
    if (busiestHourNode) busiestHourNode.textContent = state.analytics.busiest_hour || '00:00';
    if (storageModeNode) storageModeNode.textContent = state.storage === 'mysql' ? 'MySQL Live' : 'JSON Fallback';
    renderOrders();
    renderAnalytics();
  };

  const loadSession = async () => {
    const payload = await requestJson(sessionEndpoint);
    state.partner = payload.partner || null;
    state.catalog = payload.catalog || {};
    renderPartner();
  };

  const fillOrderForm = (order) => {
    if (!(orderForm instanceof HTMLFormElement)) return;
    state.editingId = order?.id || '';
    orderForm.elements.order_id.value = order?.id || '';
    orderForm.elements.customer_name.value = order?.customer_name || '';
    brandSelect.value = order?.brand || '';
    refreshProductOptions();
    productSelect.value = order?.product || '';
    refreshSkuOptions();
    skuSelect.value = order?.sku_code || '';
    renderSkuCard();
    orderForm.elements.quantity.value = order?.quantity || 1;
    orderForm.elements.notes.value = order?.notes || '';
    state.queuedFiles = [];
    renderFileQueue();
    const submit = orderForm.querySelector('[type="submit"]');
    if (submit) submit.textContent = state.editingId ? 'Save Order' : 'Create Order';
  };

  brandSelect?.addEventListener('change', refreshProductOptions);
  productSelect?.addEventListener('change', refreshSkuOptions);
  skuSelect?.addEventListener('change', renderSkuCard);

  orderForm?.addEventListener('submit', async (event) => {
    event.preventDefault();
    setError('');
    try {
      const formData = new window.FormData(orderForm);
      const payload = await requestJson(ordersEndpoint, {
        method: 'POST',
        body: {
          action: state.editingId ? 'update' : 'create',
          id: formData.get('order_id'),
          customer_name: formData.get('customer_name'),
          brand: formData.get('brand'),
          product: formData.get('product'),
          sku_code: formData.get('sku_code'),
          quantity: formData.get('quantity'),
          notes: formData.get('notes')
        }
      });

      const savedOrder = payload.order || null;
      if (savedOrder?.id && state.queuedFiles.length) {
        await uploadLabels(savedOrder.id, state.queuedFiles);
      }

      orderForm.reset();
      state.editingId = '';
      state.queuedFiles = [];
      renderFileQueue();
      refreshBrandOptions();
      await loadOrders();
      renderSkuCard();
      const submit = orderForm.querySelector('[type="submit"]');
      if (submit) submit.textContent = 'Create Order';
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
      if (order) fillOrderForm(order);
      return;
    }
    if (deleteId) {
      try {
        await requestJson(ordersEndpoint, {
          method: 'POST',
          body: { action: 'delete', id: deleteId }
        });
        if (state.editingId === deleteId) {
          orderForm?.reset();
          state.editingId = '';
          state.queuedFiles = [];
          renderFileQueue();
          const submit = orderForm?.querySelector('[type="submit"]');
          if (submit) submit.textContent = 'Create Order';
        }
        await loadOrders();
      } catch (error) {
        setError(error instanceof Error ? error.message : 'Unable to delete order.');
      }
    }
  });

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
    if (event.dataTransfer?.files?.length) {
      setQueuedFiles(event.dataTransfer.files);
    }
  });
  labelInput?.addEventListener('change', () => {
    if (labelInput.files?.length) {
      setQueuedFiles(labelInput.files);
      labelInput.value = '';
    }
  });

  labelQueue?.addEventListener('click', (event) => {
    const target = event.target;
    if (!(target instanceof HTMLElement)) return;
    const removeIndex = target.getAttribute('data-remove-queued-file');
    if (removeIndex === null) return;
    state.queuedFiles.splice(Number(removeIndex), 1);
    renderFileQueue();
  });

  document.querySelector('[data-reset-order-form]')?.addEventListener('click', () => {
    orderForm?.reset();
    state.editingId = '';
    state.queuedFiles = [];
    renderFileQueue();
    refreshBrandOptions();
    renderSkuCard();
    const submit = orderForm?.querySelector('[type="submit"]');
    if (submit) submit.textContent = 'Create Order';
  });

  yearToggle?.addEventListener('click', (event) => {
    const target = event.target;
    if (!(target instanceof HTMLElement)) return;
    const nextYear = target.getAttribute('data-analytics-year');
    if (!nextYear) return;
    state.selectedYear = nextYear;
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
