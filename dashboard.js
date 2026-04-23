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
  const yearToggle = document.querySelector('[data-year-toggle]');
  const monthlyChart = document.querySelector('[data-monthly-chart]');
  const hourlyChart = document.querySelector('[data-hourly-chart]');
  const invoiceItemsNode = document.querySelector('[data-invoice-items]');
  const labelDropzone = document.querySelector('[data-label-dropzone]');
  const labelDropzoneCopy = document.querySelector('[data-label-dropzone-copy]');
  const labelInput = document.querySelector('[data-label-input]');
  const labelQueue = document.querySelector('[data-label-queue]');

  const state = {
    partner: null,
    catalog: {},
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
    selectedYear: '',
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
    Object.entries(state.catalog || {}).forEach(([brand, products]) => {
      Object.entries(products || {}).forEach(([product, productData]) => {
        const sku = (productData.skus || [])[0];
        if (!sku?.sku) return;
        options.push({
          sku_code: sku.sku,
          label: product,
          brand,
          product,
          meta: [sku.size, sku.flavor].filter(Boolean).join(' • ')
        });
      });
    });
    state.productOptions = options.sort((left, right) => left.label.localeCompare(right.label));
  };

  const productOptionMarkup = (selectedSku = '') => [
    '<option value="">Select product</option>',
    ...state.productOptions.map((option) => `
      <option value="${escapeHtml(option.sku_code)}" ${option.sku_code === selectedSku ? 'selected' : ''}>${escapeHtml(option.label)}</option>
    `)
  ].join('');

  const invoiceItemMarkup = (item = {}, index = 0) => `
    <article class="partner-invoice-item" data-invoice-item>
      <label class="admin-affiliate-field">
        <span class="admin-control-label">Product</span>
        <select class="admin-select" data-invoice-product required>${productOptionMarkup(item.sku_code || '')}</select>
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
  };

  const collectInvoiceItems = () => {
    const items = [];
    document.querySelectorAll('[data-invoice-item]').forEach((itemNode) => {
      const select = itemNode.querySelector('[data-invoice-product]');
      const quantity = itemNode.querySelector('[data-invoice-quantity]');
      if (!(select instanceof HTMLSelectElement) || !(quantity instanceof HTMLInputElement)) return;
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
      const itemSummary = items.map((item) => `${escapeHtml(item.product || item.sku_label || item.sku_code)} x${escapeHtml(item.quantity || 1)}`).join('<br>');
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

    if (busiestHourNode) busiestHourNode.textContent = state.analytics.busiest_hour || '00:00';
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
    if (!target.matches('[data-remove-invoice-item]')) return;
    const current = collectInvoiceItems();
    const index = Number(target.getAttribute('data-remove-invoice-item') || 0);
    current.splice(index, 1);
    renderInvoiceItems(current);
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
