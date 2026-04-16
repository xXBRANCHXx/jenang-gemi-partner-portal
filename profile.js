document.addEventListener('DOMContentLoaded', () => {
  const root = document.querySelector('[data-partner-profile]');
  if (!root) return;

  const endpoint = root.dataset.partnersEndpoint || '../api/partners/';
  const partnerCode = root.dataset.partnerCode || '';
  const form = document.querySelector('[data-edit-partner-form]');
  const errorNode = document.querySelector('[data-edit-error]');
  const titleNode = document.querySelector('[data-partner-title]');
  const storePathNode = document.querySelector('[data-store-path]');

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
    if (!response.ok) {
      throw new Error(payload.error || `HTTP ${response.status}`);
    }
    return payload;
  };

  const parseCsv = (value) => String(value || '')
    .split(',')
    .map((item) => item.trim())
    .filter(Boolean);

  const setSelected = (select, values) => {
    if (!(select instanceof HTMLSelectElement)) return;
    const wanted = new Set(values || []);
    Array.from(select.options).forEach((option) => {
      option.selected = wanted.has(option.value);
    });
  };

  const selectedValues = (select) => {
    if (!(select instanceof HTMLSelectElement)) return [];
    return Array.from(select.selectedOptions).map((option) => option.value);
  };

  const setError = (message) => {
    if (!errorNode) return;
    errorNode.hidden = !message;
    errorNode.textContent = message || '';
  };

  const fillForm = (partner) => {
    if (!(form instanceof HTMLFormElement)) return;
    form.hidden = false;
    form.elements.code.value = partner.code || '';
    form.elements.name.value = partner.name || '';
    form.elements.allowed_brands.value = (partner.allowed_brands || []).join(', ');
    form.elements.products.value = (partner.products || []).join(', ');
    form.elements.jenang_gemi_bubur.value = partner.pricing?.jenang_gemi_bubur ?? 0;
    form.elements.jenang_gemi_jamu.value = partner.pricing?.jenang_gemi_jamu ?? 0;
    form.elements.notes.value = partner.notes || '';
    setSelected(form.querySelector('[name="companies"]'), partner.companies || []);
    if (titleNode) titleNode.textContent = partner.name || partner.code || 'Partner';
    if (storePathNode) storePathNode.textContent = partner.store_path || '/partner/';
  };

  const loadPartner = async () => {
    if (!partnerCode) throw new Error('Missing partner code.');
    const payload = await requestJson(`${endpoint}?code=${encodeURIComponent(partnerCode)}`);
    fillForm(payload.partner || {});
  };

  form?.addEventListener('submit', async (event) => {
    event.preventDefault();
    setError('');

    try {
      const formData = new window.FormData(form);
      await requestJson(endpoint, {
        method: 'POST',
        body: {
          action: 'update',
          code: formData.get('code'),
          name: formData.get('name'),
          companies: selectedValues(form.querySelector('[name="companies"]')),
          allowed_brands: parseCsv(formData.get('allowed_brands')),
          products: parseCsv(formData.get('products')),
          pricing: {
            jenang_gemi_bubur: formData.get('jenang_gemi_bubur'),
            jenang_gemi_jamu: formData.get('jenang_gemi_jamu')
          },
          notes: formData.get('notes')
        }
      });
      await loadPartner();
    } catch (error) {
      setError(error instanceof Error ? error.message : 'Unable to save partner.');
    }
  });

  loadPartner().catch((error) => {
    setError(error instanceof Error ? error.message : 'Unable to load partner.');
  });
});
