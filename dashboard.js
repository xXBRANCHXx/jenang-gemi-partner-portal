document.addEventListener('DOMContentLoaded', () => {
  const root = document.querySelector('[data-partner-dashboard]');
  if (!root) return;

  const sessionEndpoint = root.dataset.sessionEndpoint || '../api/session/';
  const ordersEndpoint = root.dataset.ordersEndpoint || '../api/orders/';
  const orderForm = document.querySelector('[data-order-form]');
  const orderList = document.querySelector('[data-order-list]');
  const errorNode = document.querySelector('[data-order-error]');
  const partnerNameNode = document.querySelector('[data-partner-name]');
  const partnerCodeNode = document.querySelector('[data-partner-code]');
  const allowedBrandsNode = document.querySelector('[data-allowed-brands]');
  const allowedProductsNode = document.querySelector('[data-allowed-products]');
  const brandSelect = document.querySelector('[name="brand"]');
  const productSelect = document.querySelector('[name="product"]');
  const flavorSelect = document.querySelector('[name="flavor"]');
  const sizeSelect = document.querySelector('[name="size"]');

  const state = {
    partner: null,
    catalog: {},
    orders: [],
    editingId: ''
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

  const setError = (message) => {
    if (!errorNode) return;
    errorNode.hidden = !message;
    errorNode.textContent = message || '';
  };

  const optionMarkup = (items, placeholder) => [`<option value="">${escapeHtml(placeholder)}</option>`, ...items.map((item) => `<option value="${escapeHtml(item)}">${escapeHtml(item)}</option>`)].join('');

  const refreshBrandOptions = () => {
    const brands = state.partner?.companies || [];
    if (!(brandSelect instanceof HTMLSelectElement)) return;
    const current = brandSelect.value;
    brandSelect.innerHTML = optionMarkup(brands, 'Select brand');
    brandSelect.value = brands.includes(current) ? current : (brands[0] || '');
    refreshProductOptions();
  };

  const refreshProductOptions = () => {
    const brand = brandSelect?.value || '';
    const accessMap = state.partner?.product_access?.[brand] || {};
    const catalogProducts = Object.keys(state.catalog[brand] || {});
    const products = catalogProducts.filter((product) => !!accessMap[product]?.enabled);
    if (!(productSelect instanceof HTMLSelectElement)) return;
    const current = productSelect.value;
    productSelect.innerHTML = optionMarkup(products, 'Select product');
    productSelect.value = products.includes(current) ? current : (products[0] || '');
    refreshVariantOptions();
  };

  const refreshVariantOptions = () => {
    const brand = brandSelect?.value || '';
    const product = productSelect?.value || '';
    const productData = state.catalog[brand]?.[product] || { flavors: [], sizes: [] };
    const allowedSizes = state.partner?.product_access?.[brand]?.[product]?.sizes || [];
    if (flavorSelect instanceof HTMLSelectElement) {
      const currentFlavor = flavorSelect.value;
      flavorSelect.innerHTML = optionMarkup(productData.flavors || [], 'Select flavor');
      flavorSelect.value = (productData.flavors || []).includes(currentFlavor) ? currentFlavor : ((productData.flavors || [])[0] || '');
    }
    if (sizeSelect instanceof HTMLSelectElement) {
      const currentSize = sizeSelect.value;
      const sizes = (productData.sizes || []).filter((size) => allowedSizes.includes(size));
      sizeSelect.innerHTML = optionMarkup(sizes, 'Select size');
      sizeSelect.value = sizes.includes(currentSize) ? currentSize : (sizes[0] || '');
    }
  };

  const renderOrders = () => {
    if (!orderList) return;
    if (!state.orders.length) {
      orderList.innerHTML = '<p class="admin-empty">No draft orders yet.</p>';
      return;
    }
    orderList.innerHTML = state.orders.map((order) => `
      <article class="admin-affiliate-card">
        <div class="admin-affiliate-head">
          <div>
            <span class="admin-chip">${escapeHtml(order.id || '')}</span>
            <h4>${escapeHtml(order.customer_name || 'Order')}</h4>
          </div>
          <div class="admin-affiliate-actions">
            <button type="button" class="admin-primary-btn" data-edit-order="${escapeHtml(order.id || '')}">Edit</button>
            <button type="button" class="admin-ghost-btn" data-delete-order="${escapeHtml(order.id || '')}">Delete</button>
          </div>
        </div>
        <div class="admin-affiliate-field">
          <span class="admin-control-label">Order Summary</span>
          <div class="admin-affiliate-platform-grid">
            <div class="admin-platform-choice"><span>${escapeHtml(order.brand || '')}</span></div>
            <div class="admin-platform-choice"><span>${escapeHtml(order.product || '')}</span></div>
            <div class="admin-platform-choice"><span>${escapeHtml(order.flavor || '')}</span></div>
            <div class="admin-platform-choice"><span>${escapeHtml(order.size || '')}</span></div>
            <div class="admin-platform-choice"><span>Qty ${escapeHtml(order.quantity || 1)}</span></div>
          </div>
        </div>
      </article>
    `).join('');
  };

  const renderPartner = () => {
    if (partnerNameNode) partnerNameNode.textContent = state.partner?.name || 'Partner';
    if (partnerCodeNode) partnerCodeNode.textContent = state.partner?.code || 'Partner';
    if (allowedBrandsNode) {
      allowedBrandsNode.innerHTML = (state.partner?.companies || []).map((brand) => `<span class="admin-chip">${escapeHtml(brand)}</span>`).join('') || '<span class="admin-empty">No companies assigned.</span>';
    }
    if (allowedProductsNode) {
      const summary = [];
      Object.entries(state.partner?.product_access || {}).forEach(([brand, products]) => {
        Object.entries(products || {}).forEach(([product, config]) => {
          if (!config?.enabled) return;
          summary.push(`${product} (${(config.sizes || []).join(', ')})`);
        });
      });
      allowedProductsNode.innerHTML = summary.map((item) => `<span class="admin-chip">${escapeHtml(item)}</span>`).join('') || '<span class="admin-empty">No products assigned.</span>';
    }
    refreshBrandOptions();
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
    refreshVariantOptions();
    flavorSelect.value = order?.flavor || '';
    sizeSelect.value = order?.size || '';
    orderForm.elements.quantity.value = order?.quantity || 1;
    orderForm.elements.notes.value = order?.notes || '';
    const submit = orderForm.querySelector('[type="submit"]');
    if (submit) submit.textContent = state.editingId ? 'Save Order' : 'Create Order';
  };

  brandSelect?.addEventListener('change', refreshProductOptions);
  productSelect?.addEventListener('change', refreshVariantOptions);

  orderForm?.addEventListener('submit', async (event) => {
    event.preventDefault();
    setError('');
    try {
      const formData = new window.FormData(orderForm);
      await requestJson(ordersEndpoint, {
        method: 'POST',
        body: {
          action: state.editingId ? 'update' : 'create',
          id: formData.get('order_id'),
          customer_name: formData.get('customer_name'),
          brand: formData.get('brand'),
          product: formData.get('product'),
          flavor: formData.get('flavor'),
          size: formData.get('size'),
          quantity: formData.get('quantity'),
          notes: formData.get('notes')
        }
      });
      orderForm.reset();
      state.editingId = '';
      refreshBrandOptions();
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
      if (order) fillOrderForm(order);
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
