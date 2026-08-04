document.addEventListener('DOMContentLoaded', () => {
  const root = document.querySelector('[data-partner-dashboard]');
  const page = document.querySelector('[data-billing-page]');
  if (!(root instanceof HTMLElement) || !(page instanceof HTMLElement)) return;

  const endpoint = root.dataset.billingEndpoint || '../api/billing/';
  const csrfToken = () => root.dataset.csrfToken || '';
  const listNode = page.querySelector('[data-billing-list]');
  const detailNode = page.querySelector('[data-billing-detail]');
  const metricsNode = page.querySelector('[data-billing-metrics]');
  const errorNode = page.querySelector('[data-billing-error]');
  const newBadge = document.querySelector('[data-billing-new]');
  const tutorial = document.querySelector('[data-billing-tutorial]');
  const tutorialCard = tutorial?.querySelector('.partner-billing-tutorial-card');
  const tutorialProgress = tutorial?.querySelector('[data-billing-tutorial-progress]');
  const tutorialKicker = tutorial?.querySelector('[data-billing-tutorial-kicker]');
  const tutorialTitle = tutorial?.querySelector('[data-billing-tutorial-title]');
  const tutorialCopy = tutorial?.querySelector('[data-billing-tutorial-copy]');
  const tutorialVisual = tutorial?.querySelector('[data-billing-tutorial-visual]');
  const tutorialBack = tutorial?.querySelector('[data-billing-tutorial-back]');
  const tutorialNext = tutorial?.querySelector('[data-billing-tutorial-next]');
  const heroKicker = page.querySelector('[data-billing-hero-kicker]');
  const heroTitle = page.querySelector('[data-billing-hero-title]');
  const heroCopy = page.querySelector('[data-billing-hero-copy]');
  const guideLabel = page.querySelector('[data-billing-guide-label]');
  const tutorialStorageKey = `jg-partner-billing-tutorial-v1:${root.dataset.partnerAccount || 'unknown'}`;
  let tutorialSeenInMemory = false;

  const state = {
    payload: null,
    selectedBillId: '',
    disputeMode: false,
    selectedOrderIds: new Set(),
    tutorialStep: 0,
    loading: false,
    sectionHandled: false
  };

  const isIndonesian = () => (root.dataset.partnerLanguage || 'id') === 'id';
  const locale = () => isIndonesian() ? 'id-ID' : 'en-US';
  const timezone = () => root.dataset.partnerTimezone || 'Asia/Jakarta';
  const t = (english, indonesian) => isIndonesian() ? indonesian : english;
  const escapeHtml = (value) => String(value ?? '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;');
  const formatMoney = (value) => new Intl.NumberFormat(locale(), {
    style: 'currency', currency: 'IDR', maximumFractionDigits: 0
  }).format(Number(value || 0));
  const itemPriceLines = (item) => {
    const source = Array.isArray(item?.snapshot?.items) ? item.snapshot.items : [];
    if (source.length) return source.map((line, lineIndex) => ({
      lineIndex,
      label: String(line.sku_label || line.product || line.sku_code || `${t('Product', 'Produk')} ${lineIndex + 1}`),
      skuCode: String(line.sku_code || ''),
      quantity: Math.max(1, Number(line.quantity || 1)),
      unitPrice: Math.max(0, Math.round(Number(line.unit_revenue ?? line.partner_price ?? line.partner_unit_price ?? 0)))
    }));
    const quantity = Math.max(1, Number(item?.units || 1));
    return [{ lineIndex: 0, label: String(item?.description || t('Order total', 'Total pesanan')), skuCode: '', quantity, unitPrice: Math.max(0, Math.round(Number(item?.amount || 0) / quantity)) }];
  };
  const parseDate = (value) => {
    const normalized = String(value || '').trim();
    if (!normalized) return null;
    const date = new Date(normalized.includes('T') ? normalized : `${normalized.replace(' ', 'T')}Z`);
    return Number.isNaN(date.getTime()) ? null : date;
  };
  const formatDate = (value, options = {}) => {
    const date = parseDate(value);
    if (!date) return '—';
    return new Intl.DateTimeFormat(locale(), {
      day: 'numeric', month: 'short', year: 'numeric', timeZone: timezone(), ...options
    }).format(date);
  };
  const periodLabel = (bill) => {
    const start = parseDate(`${bill.period_start}T12:00:00Z`);
    const end = parseDate(`${bill.period_end}T12:00:00Z`);
    if (!start || !end) return `${bill.period_start} – ${bill.period_end}`;
    const first = new Intl.DateTimeFormat(locale(), { day: 'numeric', month: 'short', timeZone: 'UTC' }).format(start);
    const second = new Intl.DateTimeFormat(locale(), { day: 'numeric', month: 'short', year: 'numeric', timeZone: 'UTC' }).format(end);
    return `${first} – ${second}`;
  };
  const fileSize = (bytes) => `${Math.max(0.1, Number(bytes || 0) / (1024 * 1024)).toFixed(1)} MB`;
  const hasSeenTutorialOnDevice = () => {
    if (tutorialSeenInMemory) return true;
    try {
      return window.localStorage.getItem(tutorialStorageKey) === 'seen';
    } catch (_) {
      return false;
    }
  };
  const rememberTutorialOnDevice = () => {
    tutorialSeenInMemory = true;
    try {
      window.localStorage.setItem(tutorialStorageKey, 'seen');
    } catch (_) {
      // In-memory state still prevents the guide from reopening during this page view.
    }
  };

  const statusCopy = (status) => ({
    accruing: [t('In progress', 'Berjalan'), t('This seven-day period is still collecting orders.', 'Periode tujuh hari ini masih mengumpulkan pesanan.')],
    unpaid: [t('Payment due', 'Menunggu pembayaran'), t('Review the breakdown, then submit your proof.', 'Periksa rincian, lalu kirim bukti pembayaran.')],
    disputed: [t('Dispute under review', 'Sengketa sedang ditinjau'), t('Finance is checking the selected orders.', 'Tim keuangan sedang memeriksa pesanan terpilih.')],
    payment_submitted: [t('Proof under review', 'Bukti sedang ditinjau'), t('Your payment proof is waiting for confirmation.', 'Bukti pembayaran Anda menunggu konfirmasi.')],
    paid: [t('Paid', 'Lunas'), t('Payment has been confirmed.', 'Pembayaran telah dikonfirmasi.')]
  }[status] || [status, '']);

  const renderShellLanguage = () => {
    if (heroKicker) heroKicker.textContent = t('Weekly billing', 'Tagihan mingguan');
    if (heroTitle) heroTitle.textContent = t('Seven days, clearly reconciled', 'Tujuh hari, rekonsiliasi jelas');
    if (heroCopy) heroCopy.textContent = t(
      'Review each order behind your balance, resolve discrepancies, and submit payment proof in one place.',
      'Periksa setiap pesanan di balik saldo Anda, selesaikan perbedaan, dan kirim bukti pembayaran dalam satu tempat.'
    );
    if (guideLabel) guideLabel.textContent = t('How it works', 'Cara kerja');
    tutorial?.querySelectorAll('[data-billing-close-tutorial]').forEach((button) => {
      button.setAttribute('aria-label', t('Close billing guide', 'Tutup panduan tagihan'));
    });
  };

  const setError = (message = '') => {
    if (!(errorNode instanceof HTMLElement)) return;
    errorNode.hidden = message === '';
    errorNode.textContent = message;
  };

  const requestJson = async (options = {}) => {
    const response = await fetch(endpoint, {
      method: options.method || 'GET',
      credentials: 'same-origin',
      headers: {
        Accept: 'application/json',
        ...((options.method || 'GET') !== 'GET' ? { 'X-CSRF-Token': csrfToken() } : {}),
        ...(options.body ? { 'Content-Type': 'application/json' } : {})
      },
      body: options.body ? JSON.stringify(options.body) : undefined,
      cache: 'no-store'
    });
    const payload = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(payload.error || `HTTP ${response.status}`);
    return payload;
  };

  const requestForm = async (formData) => {
    const response = await fetch(endpoint, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { Accept: 'application/json', 'X-CSRF-Token': csrfToken() },
      body: formData,
      cache: 'no-store'
    });
    const payload = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(payload.error || `HTTP ${response.status}`);
    return payload;
  };

  const selectedBill = () => state.payload?.bills?.find((bill) => bill.id === state.selectedBillId)
    || state.payload?.bills?.[0]
    || null;

  const renderMetrics = () => {
    if (!(metricsNode instanceof HTMLElement)) return;
    const summary = state.payload?.summary || {};
    metricsNode.innerHTML = `
      <article>
        <span>${escapeHtml(t('Outstanding', 'Belum dibayar'))}</span>
        <strong>${escapeHtml(formatMoney(summary.outstanding_amount))}</strong>
        <small>${escapeHtml(Number(summary.outstanding_amount || 0) > 0 ? t('Across closed billing periods', 'Dari periode tagihan yang telah ditutup') : t('No balance due', 'Tidak ada saldo terutang'))}</small>
      </article>
      <article>
        <span>${escapeHtml(t('Awaiting review', 'Menunggu tinjauan'))}</span>
        <strong>${Number(summary.awaiting_review || 0)}</strong>
        <small>${escapeHtml(t('Payments and disputes', 'Pembayaran dan sengketa'))}</small>
      </article>
      <article>
        <span>${escapeHtml(t('Confirmed paid', 'Pembayaran terkonfirmasi'))}</span>
        <strong>${escapeHtml(formatMoney(summary.paid_amount))}</strong>
        <small>${escapeHtml(t('All confirmed weekly bills', 'Semua tagihan mingguan yang dikonfirmasi'))}</small>
      </article>`;
  };

  const renderList = () => {
    if (!(listNode instanceof HTMLElement)) return;
    const bills = state.payload?.bills || [];
    if (!bills.length) {
      listNode.innerHTML = `
        <div class="partner-billing-no-bills">
          <span aria-hidden="true">✓</span>
          <strong>${escapeHtml(t('No weekly bills yet', 'Belum ada tagihan mingguan'))}</strong>
          <p>${escapeHtml(t('A new period appears after your first billable order.', 'Periode baru akan muncul setelah pesanan tertagih pertama Anda.'))}</p>
        </div>`;
      return;
    }
    listNode.innerHTML = `
      <div class="partner-billing-list-head">
        <span>${escapeHtml(t('Billing periods', 'Periode tagihan'))}</span>
        <strong>${bills.length}</strong>
      </div>
      ${bills.map((bill) => {
        const copy = statusCopy(bill.status);
        const includedCount = bill.items.filter((item) => item.status !== 'removed').length;
        return `
          <button type="button" class="partner-billing-period-card is-${escapeHtml(bill.status)}${bill.id === selectedBill()?.id ? ' is-selected' : ''}" data-billing-select="${escapeHtml(bill.id)}">
            <span class="partner-billing-period-top"><strong>${escapeHtml(periodLabel(bill))}</strong><i>${escapeHtml(copy[0])}</i></span>
            <span class="partner-billing-period-total">${escapeHtml(formatMoney(bill.total_amount))}</span>
            <span class="partner-billing-period-meta">${includedCount} ${escapeHtml(t('orders', 'pesanan'))}<b aria-hidden="true">→</b></span>
          </button>`;
      }).join('')}`;
  };

  const disputeNoticeMarkup = (bill) => {
    const disputes = Array.isArray(bill.disputes) ? bill.disputes : [];
    if (!disputes.length) return '';
    return disputes.map((dispute) => {
      const orders = dispute.order_ids.join(', ');
      const proposals = Array.isArray(dispute.price_proposals) ? dispute.price_proposals : [];
      const original = proposals.reduce((sum, item) => sum + Number(item.original_amount || 0), 0);
      const proposed = proposals.reduce((sum, item) => sum + Number(item.proposed_amount || 0), 0);
      const resolved = proposals.reduce((sum, item) => sum + Number(item.resolved_amount ?? item.proposed_amount ?? 0), 0);
      const isPriceDispute = dispute.type === 'price';
      if (dispute.status === 'pending') {
        return `<article class="partner-billing-review-note is-pending">
          <span>${escapeHtml(t('Under review', 'Sedang ditinjau'))}</span>
          <strong>${isPriceDispute ? `${escapeHtml(formatMoney(original))} → ${escapeHtml(formatMoney(proposed))}` : `${dispute.order_ids.length} ${escapeHtml(t('orders claimed as already paid', 'pesanan diklaim sudah dibayar'))}`}</strong>
          <p>${escapeHtml(dispute.reason)}</p>
          <small>${escapeHtml(orders)}</small>
        </article>`;
      }
      if (dispute.status === 'accepted') {
        return `<article class="partner-billing-review-note is-accepted">
          <span>${escapeHtml(t('Dispute accepted', 'Sengketa diterima'))}</span>
          <strong>${isPriceDispute ? `${escapeHtml(t('Price corrected to', 'Harga diperbaiki menjadi'))} ${escapeHtml(formatMoney(resolved))}` : escapeHtml(t('The selected orders were removed from this bill.', 'Pesanan terpilih telah dikeluarkan dari tagihan ini.'))}</strong>
          <small>${escapeHtml(orders)}</small>
        </article>`;
      }
      return `<article class="partner-billing-review-note is-rejected">
        <span>${escapeHtml(t('Dispute rejected', 'Sengketa ditolak'))}</span>
        <strong>${escapeHtml(dispute.resolution_reason || t('The selected orders remain on the bill.', 'Pesanan terpilih tetap ada dalam tagihan.'))}</strong>
        <p>${escapeHtml(t('Orders reviewed', 'Pesanan yang ditinjau'))}: ${escapeHtml(orders)}</p>
        ${dispute.evidence_url ? `<a href="${escapeHtml(dispute.evidence_url)}" target="_blank" rel="noopener">${escapeHtml(t('View finance screenshot', 'Lihat tangkapan layar keuangan'))} ↗</a>` : ''}
      </article>`;
    }).join('');
  };

  const actionMarkup = (bill) => {
    const status = bill.status;
    if (status === 'accruing') {
      return `<div class="partner-billing-action-state is-neutral"><strong>${escapeHtml(t('Period closes on', 'Periode ditutup pada'))} ${escapeHtml(formatDate(`${bill.period_end}T12:00:00Z`))}</strong><span>${escapeHtml(t('You can review orders now and pay after the period closes.', 'Anda dapat memeriksa pesanan sekarang dan membayar setelah periode ditutup.'))}</span></div>`;
    }
    if (status === 'payment_submitted') {
      const payment = bill.payment || {};
      return `<div class="partner-billing-action-state is-review">
        <span class="partner-billing-action-icon" aria-hidden="true">✓</span>
        <div><strong>${escapeHtml(t('Payment proof submitted', 'Bukti pembayaran terkirim'))}</strong><span>${escapeHtml(payment.name || t('Waiting for finance confirmation', 'Menunggu konfirmasi keuangan'))}${payment.size_bytes ? ` · ${escapeHtml(fileSize(payment.size_bytes))}` : ''}</span></div>
      </div>`;
    }
    if (status === 'disputed') {
      return `<div class="partner-billing-action-state is-review"><span class="partner-billing-action-icon" aria-hidden="true">…</span><div><strong>${escapeHtml(t('Finance is investigating', 'Tim keuangan sedang menyelidiki'))}</strong><span>${escapeHtml(t('Payment is paused until the selected orders are resolved.', 'Pembayaran dijeda sampai pesanan terpilih selesai ditinjau.'))}</span></div></div>`;
    }
    if (status === 'paid') {
      return `<div class="partner-billing-action-state is-paid"><span class="partner-billing-action-icon" aria-hidden="true">✓</span><div><strong>${escapeHtml(t('Payment confirmed', 'Pembayaran dikonfirmasi'))}</strong><span>${escapeHtml(bill.paid_at ? `${t('Confirmed', 'Dikonfirmasi')} ${formatDate(bill.paid_at)}` : t('This bill is settled.', 'Tagihan ini telah diselesaikan.'))}</span></div></div>`;
    }
    if (state.disputeMode) {
      return `<form class="partner-billing-dispute-form" data-billing-dispute-form>
        <div><strong>${escapeHtml(t('Dispute selected orders', 'Sengketakan pesanan terpilih'))}</strong><span>${escapeHtml(t('Keep the current prices for an already-paid claim, or enter corrected product prices above.', 'Pertahankan harga saat ini untuk klaim sudah dibayar, atau masukkan harga produk yang benar di atas.'))}</span></div>
        <textarea name="reason" maxlength="4000" placeholder="${escapeHtml(t('Explain why these orders were paid already or why their prices should change…', 'Jelaskan mengapa pesanan ini sudah dibayar atau mengapa harganya harus diubah…'))}" required></textarea>
        <p data-billing-dispute-count>${state.selectedOrderIds.size} ${escapeHtml(t('orders selected', 'pesanan dipilih'))}</p>
        <div><button type="button" class="admin-ghost-btn" data-billing-cancel-dispute>${escapeHtml(t('Cancel', 'Batal'))}</button><button type="submit" class="admin-primary-btn" ${state.selectedOrderIds.size ? '' : 'disabled'}>${escapeHtml(t('Submit dispute', 'Kirim sengketa'))}</button></div>
      </form>`;
    }
    return `<div class="partner-billing-payment-actions">
      <form data-billing-payment-form>
        <input type="file" name="proof" accept="application/pdf,image/png,image/jpeg,image/gif,image/webp,.pdf,.png,.jpg,.jpeg,.gif,.webp" data-billing-proof-input hidden required>
        <button type="button" class="admin-primary-btn partner-billing-upload-button" data-billing-choose-proof>
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 16V4"></path><path d="m7 9 5-5 5 5"></path><path d="M5 20h14"></path></svg>
          <span>${escapeHtml(t('Submit payment proof', 'Kirim bukti pembayaran'))}</span>
        </button>
        <span data-billing-proof-name>${escapeHtml(t('PDF or image · max 10 MB', 'PDF atau gambar · maks. 10 MB'))}</span>
      </form>
      <button type="button" class="admin-ghost-btn partner-billing-dispute-button" data-billing-start-dispute>${escapeHtml(t('Dispute paid orders', 'Sengketakan pesanan terbayar'))}</button>
    </div>`;
  };

  const renderDetail = () => {
    if (!(detailNode instanceof HTMLElement)) return;
    const bill = selectedBill();
    if (!bill) {
      detailNode.innerHTML = `<div class="partner-billing-empty"><span aria-hidden="true">✓</span><h4>${escapeHtml(t('Nothing to pay yet', 'Belum ada yang perlu dibayar'))}</h4><p>${escapeHtml(t('Your first weekly breakdown will appear here automatically.', 'Rincian mingguan pertama Anda akan muncul otomatis di sini.'))}</p></div>`;
      return;
    }
    state.selectedBillId = bill.id;
    const status = statusCopy(bill.status);
    const activeItems = bill.items.filter((item) => item.status !== 'removed');
    const removedItems = bill.items.filter((item) => item.status === 'removed');
    const hasAcceptedPriceCorrection = (Array.isArray(bill.disputes) ? bill.disputes : [])
      .some((dispute) => dispute?.status === 'accepted' && dispute?.type === 'price');
    const adjustmentLabel = removedItems.length > 0
      ? (hasAcceptedPriceCorrection ? t('Bill adjustments', 'Penyesuaian tagihan') : t('Removed orders', 'Pesanan dikeluarkan'))
      : (hasAcceptedPriceCorrection ? t('Price correction', 'Koreksi harga') : t('Bill adjustment', 'Penyesuaian tagihan'));
    detailNode.innerHTML = `
      <header class="partner-billing-detail-head">
        <div><span>${escapeHtml(t('Weekly bill', 'Tagihan mingguan'))}</span><h4>${escapeHtml(periodLabel(bill))}</h4><p>${escapeHtml(status[1])}</p></div>
        <span class="partner-billing-status is-${escapeHtml(bill.status)}"><i></i>${escapeHtml(status[0])}</span>
      </header>
      <section class="partner-billing-total-card">
        <div><span>${escapeHtml(t('Amount due', 'Jumlah yang harus dibayar'))}</span><strong>${escapeHtml(formatMoney(bill.total_amount))}</strong></div>
        <dl>
          <div><dt>${escapeHtml(t('Order subtotal', 'Subtotal pesanan'))}</dt><dd>${escapeHtml(formatMoney(bill.subtotal_amount))}</dd></div>
          ${Number(bill.adjustment_amount || 0) > 0 ? `<div><dt>${escapeHtml(adjustmentLabel)}</dt><dd>−${escapeHtml(formatMoney(bill.adjustment_amount))}</dd></div>` : ''}
          <div><dt>${escapeHtml(t('Due date', 'Batas pembayaran'))}</dt><dd>${escapeHtml(formatDate(`${bill.due_date}T12:00:00Z`))}</dd></div>
        </dl>
      </section>
      ${disputeNoticeMarkup(bill)}
      <section class="partner-billing-breakdown">
        <div class="partner-billing-breakdown-head"><div><span>${escapeHtml(t('Order breakdown', 'Rincian pesanan'))}</span><strong>${activeItems.length} ${escapeHtml(t('included orders', 'pesanan termasuk'))}</strong></div><em>${activeItems.reduce((sum, item) => sum + Number(item.units || 0), 0)} ${escapeHtml(t('units', 'unit'))}</em></div>
        <div class="partner-billing-order-table">
          ${activeItems.map((item) => `
            <div class="partner-billing-order-row is-${escapeHtml(item.status)}${state.selectedOrderIds.has(item.order_id) ? ' is-selected-for-dispute' : ''}" data-billing-order-row="${escapeHtml(item.order_id)}">
              ${state.disputeMode && item.status === 'included' ? `<input type="checkbox" data-billing-dispute-order="${escapeHtml(item.order_id)}" ${state.selectedOrderIds.has(item.order_id) ? 'checked' : ''}>` : '<span class="partner-billing-order-dot" aria-hidden="true"></span>'}
              <span class="partner-billing-order-main"><strong>${escapeHtml(item.order_id)}</strong><small>${escapeHtml(item.description || t('Order items', 'Item pesanan'))}</small><em>${escapeHtml(formatDate(item.order_date, { year: undefined }))} · ${escapeHtml(item.platform || t('Other', 'Lainnya'))}${item.customer_name ? ` · ${escapeHtml(item.customer_name)}` : ''}</em></span>
              <span class="partner-billing-order-amount"><strong>${escapeHtml(formatMoney(item.amount))}</strong><small>${Number(item.units || 0)} ${escapeHtml(t('units', 'unit'))}</small></span>
              ${state.disputeMode && item.status === 'included' ? `<section class="partner-billing-price-proposal" data-billing-proposal-order="${escapeHtml(item.order_id)}" ${state.selectedOrderIds.has(item.order_id) ? '' : 'hidden'}>
                <header><span>${escapeHtml(t('Your corrected product prices', 'Harga produk yang Anda koreksi'))}</span><small>${escapeHtml(t('Leave unchanged for an already-paid claim', 'Biarkan sama untuk klaim sudah dibayar'))}</small></header>
                ${itemPriceLines(item).map((line) => `<label><span><strong>${escapeHtml(line.label)}</strong><small>${line.quantity} × ${escapeHtml(formatMoney(line.unitPrice))}${line.skuCode ? ` · ${escapeHtml(line.skuCode)}` : ''}</small></span><span class="partner-billing-price-input"><i>Rp</i><input type="number" min="0" max="1000000000000" step="1" value="${line.unitPrice}" data-billing-proposal-price data-order-id="${escapeHtml(item.order_id)}" data-line-index="${line.lineIndex}" ${state.selectedOrderIds.has(item.order_id) ? '' : 'disabled'} required></span></label>`).join('')}
              </section>` : ''}
            </div>`).join('')}
          ${removedItems.length ? `<details class="partner-billing-removed-orders"><summary>${removedItems.length} ${escapeHtml(t('orders removed from total', 'pesanan dikeluarkan dari total'))}</summary>${removedItems.map((item) => `<div><span><strong>${escapeHtml(item.order_id)}</strong><small>${escapeHtml(item.removed_reason || t('Removed after review', 'Dikeluarkan setelah ditinjau'))}</small></span><del>${escapeHtml(formatMoney(item.amount))}</del></div>`).join('')}</details>` : ''}
        </div>
      </section>
      <footer class="partner-billing-detail-actions">${actionMarkup(bill)}</footer>`;
  };

  const render = () => {
    renderMetrics();
    renderList();
    renderDetail();
  };

  const load = async ({ silent = false } = {}) => {
    if (state.loading) return;
    state.loading = true;
    if (!silent) setError('');
    try {
      const payload = await requestJson();
      state.payload = payload;
      if (!state.selectedBillId || !payload.bills?.some((bill) => bill.id === state.selectedBillId)) {
        state.selectedBillId = payload.bills?.[0]?.id || '';
      }
      if (newBadge instanceof HTMLElement) newBadge.hidden = !Boolean(payload.onboarding?.new_badge_visible);
      render();
    } catch (error) {
      if (!silent) setError(error instanceof Error ? error.message : t('Unable to load billing.', 'Tagihan tidak dapat dimuat.'));
      if (!(state.payload?.bills?.length) && listNode instanceof HTMLElement) {
        listNode.innerHTML = `<div class="partner-billing-no-bills is-error"><strong>${escapeHtml(t('Billing could not load', 'Tagihan tidak dapat dimuat'))}</strong><p>${escapeHtml(t('Please try again in a moment.', 'Silakan coba lagi sebentar.'))}</p><button type="button" class="admin-ghost-btn" data-billing-retry>${escapeHtml(t('Try again', 'Coba lagi'))}</button></div>`;
      }
    } finally {
      state.loading = false;
    }
  };

  const tutorialSteps = () => [
    {
      kicker: t('Step 1 of 4', 'Langkah 1 dari 4'),
      title: t('Know exactly what you are paying', 'Ketahui persis apa yang Anda bayar'),
      copy: t('Every bill covers one seven-day period and includes a transparent order-by-order breakdown.', 'Setiap tagihan mencakup satu periode tujuh hari dengan rincian yang transparan untuk setiap pesanan.'),
      icon: '<svg viewBox="0 0 64 64"><rect x="15" y="9" width="34" height="46" rx="6"></rect><path d="M23 22h18M23 31h18M23 40h10"></path></svg>'
    },
    {
      kicker: t('Step 2 of 4', 'Langkah 2 dari 4'),
      title: t('Flag orders you already paid', 'Tandai pesanan yang sudah Anda bayar'),
      copy: t('Choose Dispute paid orders, select the exact orders, and explain where they were paid.', 'Pilih Sengketakan pesanan terbayar, tandai pesanan yang tepat, lalu jelaskan kapan pesanan tersebut dibayar.'),
      icon: '<svg viewBox="0 0 64 64"><circle cx="32" cy="32" r="22"></circle><path d="m22 33 7 7 14-17"></path></svg>'
    },
    {
      kicker: t('Step 3 of 4', 'Langkah 3 dari 4'),
      title: t('Upload one clear payment proof', 'Unggah satu bukti pembayaran yang jelas'),
      copy: t('After the period closes, pay the amount due and attach a PDF or image of the transfer proof.', 'Setelah periode ditutup, bayar jumlah terutang dan lampirkan PDF atau gambar bukti transfer.'),
      icon: '<svg viewBox="0 0 64 64"><path d="M32 43V15"></path><path d="m21 26 11-11 11 11"></path><path d="M14 48h36"></path></svg>'
    },
    {
      kicker: t('Step 4 of 4', 'Langkah 4 dari 4'),
      title: t('Wait for a clean confirmation', 'Tunggu konfirmasi yang jelas'),
      copy: t('Finance checks the proof. Once confirmed, the bill is marked Paid and remains in your history.', 'Tim keuangan memeriksa bukti. Setelah dikonfirmasi, tagihan ditandai Lunas dan tetap tersimpan dalam riwayat Anda.'),
      icon: '<svg viewBox="0 0 64 64"><path d="M14 33 27 46 50 19"></path></svg>'
    }
  ];

  const renderTutorial = () => {
    const steps = tutorialSteps();
    const step = steps[state.tutorialStep] || steps[0];
    if (tutorialKicker) tutorialKicker.textContent = step.kicker;
    if (tutorialTitle) tutorialTitle.textContent = step.title;
    if (tutorialCopy) tutorialCopy.textContent = step.copy;
    if (tutorialVisual) tutorialVisual.innerHTML = step.icon;
    if (tutorialProgress) tutorialProgress.innerHTML = steps.map((_, index) => `<i class="${index <= state.tutorialStep ? 'is-active' : ''}"></i>`).join('');
    if (tutorialBack instanceof HTMLButtonElement) {
      tutorialBack.hidden = state.tutorialStep === 0;
      tutorialBack.textContent = t('Back', 'Kembali');
    }
    if (tutorialNext instanceof HTMLButtonElement) tutorialNext.textContent = state.tutorialStep === steps.length - 1 ? t('Open my bills', 'Buka tagihan saya') : t('Next', 'Lanjut');
  };

  const openTutorial = () => {
    if (!(tutorial instanceof HTMLElement)) return;
    state.tutorialStep = 0;
    renderTutorial();
    tutorial.hidden = false;
    document.body.classList.add('partner-billing-modal-open');
    window.setTimeout(() => tutorialCard?.focus(), 40);
  };

  const closeTutorial = () => {
    if (!(tutorial instanceof HTMLElement)) return;
    tutorial.hidden = true;
    document.body.classList.remove('partner-billing-modal-open');
  };

  const handleBillingVisit = () => {
    if (root.dataset.activeSection !== 'billing') return;
    const firstVisitOnDevice = !hasSeenTutorialOnDevice();
    if (firstVisitOnDevice && !state.sectionHandled) {
      rememberTutorialOnDevice();
      openTutorial();
    }
    state.sectionHandled = true;
  };

  listNode?.addEventListener('click', (event) => {
    const target = event.target instanceof Element ? event.target.closest('[data-billing-select], [data-billing-retry]') : null;
    if (!(target instanceof HTMLElement)) return;
    if (target.hasAttribute('data-billing-retry')) {
      load();
      return;
    }
    state.selectedBillId = target.dataset.billingSelect || '';
    state.disputeMode = false;
    state.selectedOrderIds.clear();
    render();
  });

  detailNode?.addEventListener('click', (event) => {
    const target = event.target instanceof Element ? event.target.closest('[data-billing-choose-proof], [data-billing-start-dispute], [data-billing-cancel-dispute]') : null;
    if (!(target instanceof HTMLElement)) return;
    if (target.hasAttribute('data-billing-choose-proof')) {
      detailNode.querySelector('[data-billing-proof-input]')?.click();
      return;
    }
    if (target.hasAttribute('data-billing-start-dispute')) {
      state.disputeMode = true;
      state.selectedOrderIds.clear();
      renderDetail();
      detailNode.scrollIntoView({ behavior: 'smooth', block: 'start' });
      return;
    }
    state.disputeMode = false;
    state.selectedOrderIds.clear();
    renderDetail();
  });

  detailNode?.addEventListener('change', (event) => {
    const target = event.target;
    if (target instanceof HTMLInputElement && target.matches('[data-billing-dispute-order]')) {
      const orderId = target.dataset.billingDisputeOrder || '';
      if (target.checked) state.selectedOrderIds.add(orderId);
      else state.selectedOrderIds.delete(orderId);
      const count = detailNode.querySelector('[data-billing-dispute-count]');
      if (count) count.textContent = `${state.selectedOrderIds.size} ${t('orders selected', 'pesanan dipilih')}`;
      const submit = detailNode.querySelector('[data-billing-dispute-form] button[type="submit"]');
      if (submit instanceof HTMLButtonElement) submit.disabled = state.selectedOrderIds.size === 0;
      const row = target.closest('[data-billing-order-row]');
      row?.classList.toggle('is-selected-for-dispute', target.checked);
      const proposal = row?.querySelector('[data-billing-proposal-order]');
      if (proposal instanceof HTMLElement) proposal.hidden = !target.checked;
      proposal?.querySelectorAll('[data-billing-proposal-price]').forEach((input) => {
        if (input instanceof HTMLInputElement) input.disabled = !target.checked;
      });
    }
    if (target instanceof HTMLInputElement && target.matches('[data-billing-proof-input]')) {
      const file = target.files?.[0];
      const name = detailNode.querySelector('[data-billing-proof-name]');
      if (name) name.textContent = file ? `${file.name} · ${fileSize(file.size)}` : t('PDF or image · max 10 MB', 'PDF atau gambar · maks. 10 MB');
      if (file) target.form?.requestSubmit();
    }
  });

  detailNode?.addEventListener('submit', async (event) => {
    event.preventDefault();
    const form = event.target;
    const bill = selectedBill();
    if (!(form instanceof HTMLFormElement) || !bill) return;
    setError('');
    const submitButtons = Array.from(form.querySelectorAll('button'));
    submitButtons.forEach((button) => { button.disabled = true; });
    try {
      if (form.matches('[data-billing-payment-form]')) {
        const input = form.querySelector('[data-billing-proof-input]');
        const file = input instanceof HTMLInputElement ? input.files?.[0] : null;
        if (!file) throw new Error(t('Choose a payment proof first.', 'Pilih bukti pembayaran terlebih dahulu.'));
        if (file.size > 10 * 1024 * 1024) throw new Error(t('The file must be 10 MB or smaller.', 'Ukuran file harus 10 MB atau kurang.'));
        const body = new FormData();
        body.append('action', 'submit_payment');
        body.append('bill_id', bill.id);
        body.append('proof', file);
        state.payload = await requestForm(body);
      } else if (form.matches('[data-billing-dispute-form]')) {
        const data = new FormData(form);
        const priceProposals = Array.from(detailNode.querySelectorAll('[data-billing-proposal-price]'))
          .filter((input) => input instanceof HTMLInputElement && state.selectedOrderIds.has(input.dataset.orderId || ''))
          .reduce((orders, input) => {
            const orderId = input.dataset.orderId || '';
            let order = orders.find((entry) => entry.order_id === orderId);
            if (!order) { order = { order_id: orderId, lines: [] }; orders.push(order); }
            order.lines.push({ line_index: Number(input.dataset.lineIndex || 0), unit_price: Number(input.value) });
            return orders;
          }, []);
        state.payload = await requestJson({ method: 'POST', body: {
          action: 'submit_dispute',
          bill_id: bill.id,
          order_ids: Array.from(state.selectedOrderIds),
          price_proposals: priceProposals,
          reason: String(data.get('reason') || '')
        } });
      }
      state.disputeMode = false;
      state.selectedOrderIds.clear();
      render();
    } catch (error) {
      setError(error instanceof Error ? error.message : t('Unable to submit.', 'Tidak dapat mengirim.'));
      submitButtons.forEach((button) => { button.disabled = false; });
    }
  });

  document.querySelectorAll('[data-billing-open-tutorial]').forEach((button) => button.addEventListener('click', openTutorial));
  tutorial?.querySelectorAll('[data-billing-close-tutorial]').forEach((button) => button.addEventListener('click', closeTutorial));
  tutorialBack?.addEventListener('click', () => {
    state.tutorialStep = Math.max(0, state.tutorialStep - 1);
    renderTutorial();
  });
  tutorialNext?.addEventListener('click', () => {
    const lastIndex = tutorialSteps().length - 1;
    if (state.tutorialStep < lastIndex) {
      state.tutorialStep += 1;
      renderTutorial();
      return;
    }
    closeTutorial();
  });

  document.addEventListener('partner:sectionchange', (event) => {
    if (event.detail?.section === 'billing') {
      handleBillingVisit();
      load({ silent: Boolean(state.payload) });
    }
  });
  document.addEventListener('partner:preferences', () => {
    renderShellLanguage();
    if (state.payload) render();
    if (tutorial instanceof HTMLElement && !tutorial.hidden) renderTutorial();
  });
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && tutorial instanceof HTMLElement && !tutorial.hidden) closeTutorial();
  });

  renderShellLanguage();
  load().then(handleBillingVisit);
  window.setInterval(() => {
    if (root.dataset.activeSection === 'billing') load({ silent: true });
  }, 30000);
});
