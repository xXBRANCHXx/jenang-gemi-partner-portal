document.addEventListener('DOMContentLoaded', () => {
  const root = document.querySelector('[data-partner-dashboard]');
  if (!root) return;

  const sessionEndpoint = root.dataset.sessionEndpoint || '../api/session/';
  const ordersEndpoint = root.dataset.ordersEndpoint || '../api/orders/';
  const labelsEndpoint = root.dataset.labelsEndpoint || '../api/order-labels/';
  const faviconEndpoint = root.dataset.faviconEndpoint || '../api/favicon/';
  const defaultFaviconUrl = root.dataset.defaultFaviconUrl || '';
  const logoutUrl = root.dataset.logoutUrl || '../logout/';
  const dashboardBase = root.dataset.dashboardBase || './';
  let csrfToken = root.dataset.csrfToken || '';
  const maxLabelBytes = 10 * 1024 * 1024;

  const orderModal = document.querySelector('[data-order-modal]');
  const orderForm = document.querySelector('[data-order-form]');
  const passwordModal = document.querySelector('[data-password-modal]');
  const faviconModal = document.querySelector('[data-favicon-modal]');
  const platformSettingsModal = document.querySelector('[data-platform-settings-modal]');
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
  const salesChartBreakdown = document.querySelector('[data-sales-chart-breakdown]');
  const salesChartLegend = document.querySelector('[data-sales-chart-legend]');
  const salesChartTitle = document.querySelector('[data-sales-chart-title]');
  const labelDropzone = document.querySelector('[data-label-dropzone]');
  const labelDropzoneCopy = document.querySelector('[data-label-dropzone-copy]');
  const labelInput = document.querySelector('[data-label-input]');
  const labelQueue = document.querySelector('[data-label-queue]');
  const deadlineRange = document.querySelector('[data-deadline-range]');
  const deadlineValue = document.querySelector('[data-deadline-value]');
  const customerNameInput = document.querySelector('[data-customer-name]');
  const platformSelect = document.querySelector('[data-platform-select]');
  const platformPicker = document.querySelector('[data-platform-picker]');
  const platformTrigger = document.querySelector('[data-platform-trigger]');
  const platformTriggerBadge = document.querySelector('[data-platform-trigger-badge]');
  const platformLabel = document.querySelector('[data-platform-label]');
  const platformCaption = document.querySelector('[data-platform-caption]');
  const platformMenu = document.querySelector('[data-platform-menu]');
  let platformOptions = Array.from(document.querySelectorAll('[data-platform-option]'));
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
  const platformMetrics = document.querySelector('[data-platform-metrics]');
  const platformProfileForm = document.querySelector('[data-platform-profile-form]');
  const platformProfileList = document.querySelector('[data-platform-profile-list]');
  const platformProfileError = document.querySelector('[data-platform-profile-error]');
  const platformSettingsSummary = document.querySelector('[data-platform-settings-summary]');
  const regionalSettingsForm = document.querySelector('[data-regional-settings-form]');
  const regionalSettingsStatus = document.querySelector('[data-regional-settings-status]');
  const languageSetting = document.querySelector('[data-language-setting]');
  const timezoneSetting = document.querySelector('[data-timezone-setting]');
  const faviconForms = Array.from(document.querySelectorAll('[data-favicon-form]'));
  const faviconSummary = document.querySelector('[data-favicon-summary]');
  const faviconSummaryPreviews = Array.from(document.querySelectorAll('[data-favicon-summary-preview]'));
  const faviconLinks = {
    light: document.querySelector('link[data-partner-favicon="light"]'),
    dark: document.querySelector('link[data-partner-favicon="dark"]')
  };
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
    platformOptions: [],
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
    passwordResetRequired: false,
    language: ['id', 'en'].includes(root.dataset.partnerLanguage || '') ? root.dataset.partnerLanguage : 'id',
    timezone: ['Asia/Jakarta', 'Asia/Makassar', 'Asia/Jayapura'].includes(root.dataset.partnerTimezone || '')
      ? root.dataset.partnerTimezone
      : 'Asia/Jakarta',
    favicons: {
      light: {
        configured: Boolean(root.dataset.faviconLightUrl),
        name: root.dataset.faviconLightName || '',
        url: root.dataset.faviconLightUrl || ''
      },
      dark: {
        configured: Boolean(root.dataset.faviconDarkUrl),
        name: root.dataset.faviconDarkName || '',
        url: root.dataset.faviconDarkUrl || ''
      }
    }
  };
  const sectionLabels = {
    overview: 'Overview',
    orders: 'Orders',
    labels: 'Labels',
    analytics: 'Analytics',
    settings: 'Settings'
  };
  const indonesianTranslations = new Map([
    ['Partner Workspace', 'Ruang Kerja Mitra'],
    ['Direct ordering portal', 'Portal pemesanan langsung'],
    ['Overview', 'Ringkasan'],
    ['Orders', 'Pesanan'],
    ['Labels', 'Label Pengiriman'],
    ['Analytics', 'Analitik'],
    ['Settings', 'Pengaturan'],
    ['New Order', 'Pesanan Baru'],
    ['Active partner', 'Mitra aktif'],
    ['Logout', 'Keluar'],
    ['Partner portal', 'Portal mitra'],
    ['Create Order', 'Buat Pesanan'],
    ['Personalization', 'Personalisasi'],
    ['Appearance', 'Tampilan'],
    ['Control how your workspace and browser tab look.', 'Atur tampilan ruang kerja dan tab browser Anda.'],
    ['Theme', 'Tema'],
    ['Theme preference', 'Preferensi tema'],
    ['Follow your device or keep this workspace light or dark.', 'Ikuti perangkat atau gunakan tema terang maupun gelap.'],
    ['System', 'Sistem'],
    ['Light', 'Terang'],
    ['Dark', 'Gelap'],
    ['Browser favicon', 'Favicon browser'],
    ['Use separate icons that stay clear in light and dark mode.', 'Gunakan ikon terpisah agar tetap jelas dalam mode terang dan gelap.'],
    ['Using the default icon', 'Menggunakan ikon bawaan'],
    ['Manage', 'Kelola'],
    ['Regional', 'Regional'],
    ['Language & time', 'Bahasa & waktu'],
    ['Set the language and local time used throughout this workspace.', 'Atur bahasa dan waktu lokal untuk seluruh ruang kerja.'],
    ['Language', 'Bahasa'],
    ['Changes interface text and regional number formatting.', 'Mengubah teks antarmuka dan format angka regional.'],
    ['Time zone', 'Zona waktu'],
    ['Controls the time shown for orders, labels, and reporting.', 'Mengatur waktu yang ditampilkan pada pesanan, label, dan laporan.'],
    ['Account', 'Akun'],
    ['Security', 'Keamanan'],
    ['Keep access to this partner workspace protected.', 'Jaga keamanan akses ke ruang kerja mitra ini.'],
    ['Password', 'Kata sandi'],
    ['Choose a strong password you do not use elsewhere.', 'Gunakan kata sandi kuat yang tidak dipakai di tempat lain.'],
    ['Change password', 'Ubah kata sandi'],
    ['Order workflow', 'Alur pesanan'],
    ['Choose which sales channels are available when creating orders.', 'Pilih kanal penjualan yang tersedia saat membuat pesanan.'],
    ['Platform options', 'Opsi platform'],
    ['Built-in marketplaces and your custom reseller profiles.', 'Marketplace bawaan dan profil reseller khusus Anda.'],
    ['Loading options…', 'Memuat opsi…'],
    ['Built in', 'Bawaan'],
    ['Remove', 'Hapus'],
    ['Close', 'Tutup'],
    ['Upload', 'Unggah'],
    ['Replace', 'Ganti'],
    ['Empty', 'Kosong'],
    ['No custom favicon', 'Belum ada favicon khusus'],
    ['Custom favicon', 'Favicon khusus'],
    ['Custom light icon', 'Ikon terang khusus'],
    ['Custom dark icon', 'Ikon gelap khusus'],
    ['Custom light and dark icons', 'Ikon terang dan gelap khusus'],
    ['Add platform', 'Tambah platform'],
    ['Reseller or platform name', 'Nama reseller atau platform'],
    ['Built-in marketplace', 'Marketplace bawaan'],
    ['Custom reseller profile', 'Profil reseller khusus'],
    ['Units sold', 'Unit terjual'],
    ['Last 30 days', '30 hari terakhir'],
    ['Orders created', 'Pesanan dibuat'],
    ['Avg. units/order', 'Rata-rata unit/pesanan'],
    ['Partner cost', 'Biaya mitra'],
    ['Sales window', 'Periode penjualan'],
    ['Units sold by timeframe', 'Unit terjual berdasarkan periode'],
    ['Year', 'Tahun'],
    ['All', 'Semua'],
    ['Month', 'Bulan'],
    ['Recent orders', 'Pesanan terbaru'],
    ['Last 7 days', '7 hari terakhir'],
    ['Order history', 'Riwayat pesanan'],
    ['No orders yet.', 'Belum ada pesanan.'],
    ['Temporary shipping labels', 'Label pengiriman sementara'],
    ['Upload Label', 'Unggah Label'],
    ['Labels are kept for up to seven days, shortened to three days after fulfillment and one day after cancellation.', 'Label disimpan hingga tujuh hari, menjadi tiga hari setelah dipenuhi dan satu hari setelah dibatalkan.'],
    ['No labels uploaded yet.', 'Belum ada label yang diunggah.'],
    ['Active orders', 'Pesanan aktif'],
    ['Not canceled or archived', 'Tidak dibatalkan atau diarsipkan'],
    ['Fulfilled', 'Dipenuhi'],
    ['Completed orders', 'Pesanan selesai'],
    ['Cancel rate', 'Tingkat pembatalan'],
    ['All partner orders', 'Semua pesanan mitra'],
    ['Cost/order', 'Biaya/pesanan'],
    ['Average partner cost', 'Rata-rata biaya mitra'],
    ['Reseller performance', 'Kinerja reseller'],
    ['Platform metrics', 'Metrik platform'],
    ['Platform metrics will appear after orders are created.', 'Metrik platform akan muncul setelah pesanan dibuat.'],
    ['Product mix', 'Komposisi produk'],
    ['Product units', 'Unit produk'],
    ['No product data yet.', 'Belum ada data produk.'],
    ['New order', 'Pesanan baru'],
    ['Upload label, then choose approved SKUs', 'Unggah label, lalu pilih SKU yang disetujui'],
    ['Customer name', 'Nama pelanggan'],
    ['Creation time', 'Waktu pembuatan'],
    ['Optional customer name', 'Nama pelanggan (opsional)'],
    ['Platform', 'Platform'],
    ['Select platform', 'Pilih platform'],
    ['Required for every order', 'Wajib untuk setiap pesanan'],
    ['Deadline', 'Batas waktu'],
    ['Upload shipping label', 'Unggah label pengiriman'],
    ['Archive', 'Arsipkan'],
    ['Restore', 'Pulihkan'],
    ['Cancel', 'Batalkan'],
    ['Needs review', 'Perlu ditinjau'],
    ['No selected SKUs', 'Tidak ada SKU terpilih'],
    ['No label', 'Tidak ada label'],
    ['No label file selected.', 'Belum ada file label yang dipilih.'],
    ['Label selected', 'Label dipilih'],
    ['Save Password', 'Simpan Kata Sandi'],
    ['Cancel changes', 'Batalkan perubahan'],
    ['Partner Dashboard | Jenang Gemi', 'Dashboard Mitra | Jenang Gemi'],
    ['Partner portal build version', 'Versi portal mitra'],
    ['Partner navigation', 'Navigasi mitra'],
    ['Choose chart month', 'Pilih bulan grafik'],
    ['Platform colors', 'Warna platform'],
    ['Order platform', 'Platform pesanan'],
    ['PDF shipment label · maximum 10 MB', 'Label pengiriman PDF · maksimum 10 MB'],
    ['Approved SKUs', 'SKU yang disetujui'],
    ['Select products', 'Pilih produk'],
    ['Search', 'Cari'],
    ['SKU, product, flavor, tag', 'SKU, produk, rasa, tag'],
    ['Product', 'Produk'],
    ['Flavor', 'Rasa'],
    ['Approved SKUs will load after your session is ready.', 'SKU yang disetujui akan dimuat setelah sesi siap.'],
    ['Order preview', 'Pratinjau pesanan'],
    ['Submit Order', 'Kirim Pesanan'],
    ['Submitting...', 'Mengirim...'],
    ['Upload one icon for light mode and another for dark mode. Empty slots use the Jenang Gemi icon.', 'Unggah satu ikon untuk mode terang dan satu untuk mode gelap. Slot kosong menggunakan ikon Jenang Gemi.'],
    ['Light mode', 'Mode terang'],
    ['Dark mode', 'Mode gelap'],
    ['PNG or ICO, maximum 1 MB. PNG files must be square, from 16×16 to 1024×1024.', 'PNG atau ICO, maksimum 1 MB. File PNG harus persegi, dari 16×16 hingga 1024×1024.'],
    ['Add reseller profiles for order entry and reporting. Built-in marketplaces are always available.', 'Tambahkan profil reseller untuk entri pesanan dan laporan. Marketplace bawaan selalu tersedia.'],
    ['e.g. Bandung Reseller', 'contoh: Reseller Bandung'],
    ['Loading platform options.', 'Memuat opsi platform.'],
    ['Set a new password to finish unlocking this workspace.', 'Tetapkan kata sandi baru untuk menyelesaikan pembukaan ruang kerja ini.'],
    ['Current password', 'Kata sandi saat ini'],
    ['New password', 'Kata sandi baru'],
    ['Confirm new password', 'Konfirmasi kata sandi baru'],
    ['Custom reseller order', 'Pesanan reseller khusus'],
    ['Marketplace order', 'Pesanan marketplace'],
    ['Shopee marketplace order', 'Pesanan marketplace Shopee'],
    ['TikTok/Toped marketplace order', 'Pesanan marketplace TikTok/Toped'],
    ['Custom reseller', 'Reseller khusus'],
    ['SKU units', 'Unit SKU'],
    ['Shipping label', 'Label pengiriman'],
    ['Open', 'Buka'],
    ['No file URL', 'URL file tidak tersedia'],
    ['Platform colors appear after the first order.', 'Warna platform muncul setelah pesanan pertama.'],
    ['No orders from the last 7 days.', 'Tidak ada pesanan dalam 7 hari terakhir.'],
    ['Archived · removed after 30 days', 'Diarsipkan · dihapus setelah 30 hari'],
    ['Stock', 'Stok'],
    ['Not selected', 'Belum dipilih'],
    ['Label recipient', 'Penerima label'],
    ['Customer', 'Pelanggan'],
    ['SKU quantity', 'Jumlah SKU'],
    ['Billable units', 'Unit tertagih'],
    ['Cost to partner', 'Biaya untuk mitra'],
    ['No approved SKUs selected.', 'Belum ada SKU yang disetujui dipilih.'],
    ['No approved SKUs are enabled for this partner.', 'Tidak ada SKU yang disetujui dan diaktifkan untuk mitra ini.'],
    ['No approved SKUs match those filters.', 'Tidak ada SKU yang disetujui sesuai filter tersebut.'],
    ['No platform units in this period.', 'Tidak ada unit platform pada periode ini.'],
    ['Add one', 'Tambahkan satu'],
    ['Quantity for', 'Jumlah untuk'],
    ['units', 'unit'],
    ['total units', 'total unit'],
    ['qty', 'jumlah'],
    ['selected', 'dipilih'],
    ['per SKU', 'per SKU'],
    ['deadline', 'batas waktu'],
    ['Deletes', 'Dihapus'],
    ['Saving…', 'Menyimpan…'],
    ['Regional settings saved.', 'Pengaturan regional tersimpan.'],
    ['Upload a shipment label PDF.', 'Unggah label pengiriman dalam format PDF.'],
    ['Shipment label PDF must be 10 MB or smaller.', 'PDF label pengiriman harus berukuran 10 MB atau kurang.'],
    ['Enter a reseller or platform name.', 'Masukkan nama reseller atau platform.'],
    ['Unable to add platform.', 'Platform tidak dapat ditambahkan.'],
    ['Unable to remove platform.', 'Platform tidak dapat dihapus.'],
    ['Select an order platform.', 'Pilih platform pesanan.'],
    ['Upload a label and select at least one approved SKU.', 'Unggah label dan pilih setidaknya satu SKU yang disetujui.'],
    ['Unable to submit order.', 'Pesanan tidak dapat dikirim.'],
    ['Unable to update archive state.', 'Status arsip tidak dapat diperbarui.'],
    ['Unable to cancel order.', 'Pesanan tidak dapat dibatalkan.'],
    ['Favicon must be no larger than 1 MB.', 'Ukuran favicon tidak boleh lebih dari 1 MB.'],
    ['Unable to upload favicon.', 'Favicon tidak dapat diunggah.'],
    ['Unable to remove favicon.', 'Favicon tidak dapat dihapus.'],
    ['New passwords do not match.', 'Kata sandi baru tidak cocok.'],
    ['Unable to update password.', 'Kata sandi tidak dapat diperbarui.'],
    ['Unable to load dashboard.', 'Dashboard tidak dapat dimuat.'],
    ['Unable to save regional settings.', 'Pengaturan regional tidak dapat disimpan.']
  ]);
  const englishTranslations = new Map(Array.from(indonesianTranslations, ([english, indonesian]) => [indonesian, english]));
  const translateTextNode = (node) => {
    const value = String(node.nodeValue || '');
    const trimmed = value.trim();
    if (!trimmed) return;
    const dictionary = state.language === 'id' ? indonesianTranslations : englishTranslations;
    const translated = dictionary.get(trimmed);
    if (translated && translated !== trimmed) node.nodeValue = value.replace(trimmed, translated);
  };
  const translateElementAttributes = (element) => {
    if (!(element instanceof Element)) return;
    const dictionary = state.language === 'id' ? indonesianTranslations : englishTranslations;
    ['aria-label', 'placeholder', 'title'].forEach((attribute) => {
      const value = element.getAttribute(attribute);
      const translated = value ? dictionary.get(value.trim()) : '';
      if (translated && translated !== value) element.setAttribute(attribute, translated);
    });
  };
  const translateTree = (target = document.body) => {
    if (!target) return;
    if (target.nodeType === Node.TEXT_NODE) {
      translateTextNode(target);
      return;
    }
    translateElementAttributes(target);
    target.querySelectorAll?.('*').forEach(translateElementAttributes);
    const walker = document.createTreeWalker(target, NodeFilter.SHOW_TEXT);
    let node = walker.nextNode();
    while (node) {
      translateTextNode(node);
      node = walker.nextNode();
    }
  };
  const translationObserver = new MutationObserver((mutations) => {
    mutations.forEach((mutation) => {
      if (mutation.type === 'characterData') translateTextNode(mutation.target);
      mutation.addedNodes.forEach((node) => translateTree(node));
    });
  });
  translationObserver.observe(document.body, { childList: true, characterData: true, subtree: true });
  const localeCode = () => state.language === 'id' ? 'id-ID' : 'en-US';
  const localizedText = (english, indonesian) => state.language === 'id' ? indonesian : english;

  const escapeHtml = (value) => String(value)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');

  const defaultPlatformOptions = [
    { id: 'builtin-shopee', name: 'Shopee', caption: 'Shopee marketplace order', kind: 'shopee', removable: false },
    { id: 'builtin-tiktok-toped', name: 'TikTok/Toped', caption: 'TikTok/Toped marketplace order', kind: 'tiktok', removable: false }
  ];

  const platformBadgeText = (option = {}) => {
    if (option.kind === 'shopee') return 'S';
    if (option.kind === 'tiktok') return 'T';
    return String(option.name || 'R').trim().charAt(0).toLocaleUpperCase(localeCode()) || 'R';
  };

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

  const requestFormData = async (url, formData) => {
    const response = await fetch(url, {
      method: 'POST',
      headers: { Accept: 'application/json', 'X-CSRF-Token': csrfToken },
      credentials: 'same-origin',
      body: formData
    });
    const payload = await response.json().catch(() => ({}));
    if (!response.ok || payload.error) throw new Error(payload.error || 'Unable to upload favicon.');
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

  const closePlatformMenu = () => {
    if (platformMenu instanceof HTMLElement) platformMenu.hidden = true;
    if (platformTrigger instanceof HTMLButtonElement) platformTrigger.setAttribute('aria-expanded', 'false');
  };

  const openPlatformMenu = () => {
    if (!(platformMenu instanceof HTMLElement) || !(platformTrigger instanceof HTMLButtonElement)) return;
    platformMenu.hidden = false;
    platformTrigger.setAttribute('aria-expanded', 'true');
    const selected = platformOptions.find((option) => option.getAttribute('aria-selected') === 'true');
    const firstOption = selected || platformOptions[0];
    if (firstOption instanceof HTMLButtonElement) firstOption.focus();
  };

  const setPlatformValue = (value, dispatchChange = true) => {
    const normalized = String(value || '');
    const selected = platformOptions.find((option) => option.getAttribute('data-platform-option') === normalized) || null;
    const selectedKind = selected?.getAttribute('data-platform-kind') || '';
    const selectedCaption = selected?.getAttribute('data-platform-caption') || '';
    const selectedBadge = selected?.getAttribute('data-platform-badge-text') || '?';
    if (platformSelect instanceof HTMLInputElement) platformSelect.value = selected ? normalized : '';
    if (platformLabel) platformLabel.textContent = selected ? normalized : 'Select platform';
    if (platformCaption) platformCaption.textContent = selected ? (selectedCaption || 'Custom reseller order') : 'Required for every order';
    if (platformTriggerBadge) platformTriggerBadge.textContent = selected ? selectedBadge : '?';
    if (platformTrigger instanceof HTMLButtonElement) {
      platformTrigger.classList.toggle('has-value', Boolean(selected));
      platformTrigger.setAttribute('data-platform-value', selected ? normalized : '');
      platformTrigger.setAttribute('data-platform-kind', selected ? selectedKind : '');
    }
    platformOptions.forEach((option) => {
      option.setAttribute('aria-selected', option === selected ? 'true' : 'false');
    });
    closePlatformMenu();
    if (dispatchChange && platformSelect instanceof HTMLInputElement) {
      platformSelect.dispatchEvent(new Event('change', { bubbles: true }));
    }
  };

  const renderPlatformProfiles = () => {
    if (!platformProfileList) return;
    const options = state.platformOptions.length ? state.platformOptions : defaultPlatformOptions;
    const customCount = options.filter((option) => option.removable).length;
    if (platformSettingsSummary) {
      platformSettingsSummary.textContent = customCount > 0
        ? localizedText(`${options.length} available · ${customCount} custom`, `${options.length} tersedia · ${customCount} khusus`)
        : localizedText(`${options.length} available · Built-ins only`, `${options.length} tersedia · Hanya bawaan`);
    }
    platformProfileList.innerHTML = options.map((option) => `
      <article class="partner-platform-profile-card" data-platform-kind="${escapeHtml(option.kind || 'custom')}">
        <span class="partner-platform-badge" aria-hidden="true">${escapeHtml(platformBadgeText(option))}</span>
        <div>
          <strong>${escapeHtml(option.name || '')}</strong>
          <small>${escapeHtml(option.removable ? 'Custom reseller profile' : 'Built-in marketplace')}</small>
        </div>
        ${option.removable ? `<button type="button" class="admin-ghost-btn" data-remove-platform="${escapeHtml(option.id || '')}">Remove</button>` : '<span class="partner-platform-locked">Built in</span>'}
      </article>
    `).join('');
  };

  const renderPlatformOptions = (options) => {
    const normalizedOptions = Array.isArray(options) && options.length ? options : defaultPlatformOptions;
    state.platformOptions = normalizedOptions.map((option) => ({
      id: String(option.id || ''),
      name: String(option.name || ''),
      caption: String(option.caption || ''),
      kind: ['shopee', 'tiktok'].includes(String(option.kind || '')) ? String(option.kind) : 'custom',
      removable: Boolean(option.removable)
    })).filter((option) => option.name);

    const currentValue = platformSelect?.value || '';
    if (platformMenu) {
      platformMenu.innerHTML = state.platformOptions.map((option) => `
        <button
          type="button"
          class="partner-platform-option"
          role="option"
          aria-selected="false"
          data-platform-option="${escapeHtml(option.name)}"
          data-platform-kind="${escapeHtml(option.kind)}"
          data-platform-caption="${escapeHtml(option.caption || (option.removable ? 'Custom reseller order' : 'Marketplace order'))}"
          data-platform-badge-text="${escapeHtml(platformBadgeText(option))}"
        >
          <span class="partner-platform-badge" aria-hidden="true">${escapeHtml(platformBadgeText(option))}</span>
          <span>
            <strong>${escapeHtml(option.name)}</strong>
            <small>${escapeHtml(option.caption || (option.removable ? 'Custom reseller profile' : 'Marketplace order'))}</small>
          </span>
          <span class="partner-platform-check" aria-hidden="true">✓</span>
        </button>
      `).join('');
      platformOptions = Array.from(platformMenu.querySelectorAll('[data-platform-option]'));
    }
    setPlatformValue(currentValue, false);
    renderPlatformProfiles();
    renderAnalytics();
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
    return date.toLocaleString(localeCode(), {
      month: 'short',
      day: 'numeric',
      hour: '2-digit',
      minute: '2-digit',
      timeZone: state.timezone,
      timeZoneName: 'short'
    });
  };

  const datetimeLocalValue = (value = '') => {
    const date = value ? new Date(value) : new Date();
    if (Number.isNaN(date.getTime())) return '';
    const parts = Object.fromEntries(new Intl.DateTimeFormat('en-CA', {
      timeZone: state.timezone,
      year: 'numeric',
      month: '2-digit',
      day: '2-digit',
      hour: '2-digit',
      minute: '2-digit',
      hourCycle: 'h23'
    }).formatToParts(date).filter((part) => part.type !== 'literal').map((part) => [part.type, part.value]));
    return `${parts.year}-${parts.month}-${parts.day}T${parts.hour}:${parts.minute}`;
  };

  const formatCurrency = (value) => new Intl.NumberFormat(localeCode(), {
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
    return number.toLocaleString(localeCode(), {
      maximumFractionDigits: number % 1 === 0 ? 0 : 2
    });
  };

  const skuProductName = (sku = {}) => String(sku.base_product_name || sku.product_name || 'Product').trim() || 'Product';
  const skuFlavorName = (sku = {}) => String(sku.flavor_name || sku.flavor || '').trim();
  const skuDisplayName = (sku = {}) => [sku.product_name || skuProductName(sku), skuFlavorName(sku)]
    .filter((value, index, values) => value && values.indexOf(value) === index)
    .join(' · ') || sku.sku || 'Approved SKU';
  const titleCaseWords = (value = '') => String(value)
    .trim()
    .toLocaleLowerCase(localeCode())
    .split(/\s+/)
    .filter(Boolean)
    .map((word) => word.charAt(0).toLocaleUpperCase(localeCode()) + word.slice(1))
    .join(' ');
  const compactSkuDisplayName = (sku = {}) => [
    skuProductName(sku),
    titleCaseWords(skuFlavorName(sku)),
    Number(sku.volume || 0) > 0 ? formatNumber(sku.volume) : ''
  ].filter(Boolean).join(' ') || sku.sku || 'Approved SKU';

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
          const partnerPrice = Number(sku.partner_price ?? partnerUnitPrice);
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

  const applyFaviconLinks = () => {
    ['light', 'dark'].forEach((theme) => {
      const link = faviconLinks[theme];
      if (!(link instanceof HTMLLinkElement)) return;
      link.href = state.favicons[theme]?.url || defaultFaviconUrl;
      link.media = state.theme === 'system'
        ? `(prefers-color-scheme: ${theme})`
        : (state.theme === theme ? 'all' : 'not all');
    });
  };

  const renderFaviconSettings = () => {
    faviconForms.forEach((form) => {
      const theme = String(form.dataset.faviconTheme || '');
      const favicon = state.favicons[theme] || { configured: false, name: '', url: '' };
      const preview = form.querySelector('[data-favicon-preview]');
      const image = preview?.querySelector('img');
      const empty = preview?.querySelector('[data-favicon-empty]');
      const name = form.querySelector('[data-favicon-name]');
      const choose = form.querySelector('[data-choose-favicon]');
      const remove = form.querySelector('[data-remove-favicon]');
      if (preview) preview.classList.toggle('is-configured', Boolean(favicon.configured));
      if (image instanceof HTMLImageElement) {
        image.hidden = !favicon.configured;
        if (favicon.configured && favicon.url) {
          image.src = String(favicon.url);
        } else {
          image.removeAttribute('src');
        }
      }
      if (empty instanceof HTMLElement) empty.hidden = Boolean(favicon.configured);
      if (name) name.textContent = favicon.configured ? String(favicon.name || 'Custom favicon') : 'No custom favicon';
      if (choose) choose.textContent = favicon.configured ? 'Replace' : 'Upload';
      if (remove instanceof HTMLButtonElement) remove.hidden = !favicon.configured;
    });
    const configuredThemes = ['light', 'dark'].filter((theme) => state.favicons[theme]?.configured);
    if (faviconSummary) {
      faviconSummary.textContent = configuredThemes.length === 0
        ? localizedText('Using the default icon', 'Menggunakan ikon bawaan')
        : (configuredThemes.length === 2
          ? localizedText('Custom light and dark icons', 'Ikon terang dan gelap khusus')
          : localizedText(`Custom ${configuredThemes[0]} icon`, `Ikon ${configuredThemes[0] === 'light' ? 'terang' : 'gelap'} khusus`));
    }
    faviconSummaryPreviews.forEach((preview) => {
      const theme = String(preview.dataset.faviconSummaryPreview || '');
      const favicon = state.favicons[theme] || { configured: false, url: '' };
      const image = preview.querySelector('img');
      const initial = preview.querySelector('b');
      preview.classList.toggle('is-configured', Boolean(favicon.configured));
      if (image instanceof HTMLImageElement) {
        image.hidden = !favicon.configured;
        if (favicon.configured && favicon.url) image.src = String(favicon.url);
        else image.removeAttribute('src');
      }
      if (initial instanceof HTMLElement) initial.hidden = Boolean(favicon.configured);
    });
    applyFaviconLinks();
  };

  const setFaviconSettings = (favicons = {}) => {
    state.favicons = {
      light: { configured: false, name: '', url: '', ...(favicons.light || {}) },
      dark: { configured: false, name: '', url: '', ...(favicons.dark || {}) }
    };
    renderFaviconSettings();
  };

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
    applyFaviconLinks();
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
  const canonicalPlatformName = (value) => {
    const raw = String(value || '').trim();
    const normalized = raw.toLocaleLowerCase('en-US').replace(/\s+/g, ' ');
    if (['tiktok', 'tiktok shop', 'tiktok/toped', 'tiktok toped', 'tiktok/tokopedia', 'tiktok tokopedia', 'tokopedia'].includes(normalized)) return 'TikTok/Toped';
    if (['shopee', 'spx'].includes(normalized)) return 'Shopee';
    return raw || 'Unassigned';
  };
  const platformKindForName = (name) => {
    const canonical = canonicalPlatformName(name);
    if (canonical === 'Shopee') return 'shopee';
    if (canonical === 'TikTok/Toped') return 'tiktok';
    return 'custom';
  };
  const platformColorForName = (name) => {
    const canonical = canonicalPlatformName(name);
    if (canonical === 'Shopee') return '#ee4d2d';
    if (canonical === 'TikTok/Toped') return '#25d881';
    let hash = 0;
    Array.from(canonical).forEach((character) => {
      hash = ((hash * 31) + (character.codePointAt(0) || 0)) >>> 0;
    });
    return `hsl(${hash % 360} 68% 58%)`;
  };
  const isArchived = (order = {}) => String(order.archived_at || '').trim() !== '';
  const canCancel = (order = {}) => ['IS_LISTED', 'LISTED', ''].includes(String(order.status || 'IS_LISTED').trim().toUpperCase());
  const statusLabel = (order = {}) => {
    const status = String(order.status || 'IS_LISTED').trim().toUpperCase();
    if (status === 'IS_BEING_FULFILLED' || status === 'PROCESSING') return localizedText('Processing', 'Diproses');
    if (status === 'FULFILLED' || status === 'COMPLETED') return localizedText('Fulfilled', 'Dipenuhi');
    if (status === 'CANCELLED') return localizedText('Cancelled', 'Dibatalkan');
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
      return bounds ? bounds.start.toLocaleDateString(localeCode(), { month: 'long', year: 'numeric', timeZone: state.timezone }) : localizedText('Selected month', 'Bulan terpilih');
    }
    return ({
      '24h': localizedText('Last 24 hours', '24 jam terakhir'),
      '7d': localizedText('Last 7 days', '7 hari terakhir'),
      '30d': localizedText('Last 30 days', '30 hari terakhir'),
      '90d': localizedText('Last 90 days', '90 hari terakhir'),
      year: localizedText('This year', 'Tahun ini'),
      all: localizedText('All time', 'Sepanjang waktu')
    })[state.selectedTimeframe] || localizedText('Last 30 days', '30 hari terakhir');
  };

  const renderMetrics = () => {
    const orders = filteredOrders();
    const units = orders.reduce((sum, order) => sum + orderUnits(order), 0);
    const revenue = orders.reduce((sum, order) => sum + orderRevenue(order), 0);
    const nodes = {
      units: document.querySelector('[data-metric-units]'),
      orders: document.querySelector('[data-metric-orders]'),
      average: document.querySelector('[data-metric-average]'),
      revenue: document.querySelector('[data-metric-revenue]')
    };
    if (nodes.units) nodes.units.textContent = String(units);
    if (nodes.orders) nodes.orders.textContent = String(orders.length);
    if (nodes.average) nodes.average.textContent = orders.length ? (units / orders.length).toFixed(1) : '0.0';
    if (nodes.revenue) nodes.revenue.textContent = formatCurrency(revenue);
    document.querySelectorAll('[data-metric-window]').forEach((node) => {
      node.textContent = timeframeLabel();
    });
  };

  const renderAnalytics = () => {
    const analyticsOrders = state.orders.filter((order) => !isArchived(order));
    const total = analyticsOrders.length;
    const active = analyticsOrders.filter((order) => !['CANCELLED', 'CANCELED'].includes(String(order.status || '').toUpperCase())).length;
    const fulfilled = analyticsOrders.filter((order) => ['FULFILLED', 'COMPLETED', 'SHIPPED'].includes(String(order.status || '').toUpperCase())).length;
    const cancelled = analyticsOrders.filter((order) => ['CANCELLED', 'CANCELED'].includes(String(order.status || '').toUpperCase())).length;
    const revenue = analyticsOrders.reduce((sum, order) => sum + orderRevenue(order), 0);

    if (analyticsNodes.active) analyticsNodes.active.textContent = String(active);
    if (analyticsNodes.fulfilled) analyticsNodes.fulfilled.textContent = String(fulfilled);
    if (analyticsNodes.cancelRate) analyticsNodes.cancelRate.textContent = total ? `${Math.round((cancelled / total) * 100)}%` : '0%';
    if (analyticsNodes.revenueOrder) analyticsNodes.revenueOrder.textContent = formatCurrency(total ? revenue / total : 0);

    if (platformMetrics) {
      const metricMap = new Map();
      const configuredOptions = state.platformOptions.length ? state.platformOptions : defaultPlatformOptions;
      configuredOptions.forEach((option) => {
        const name = canonicalPlatformName(option.name);
        metricMap.set(name, {
          name,
          kind: option.kind || platformKindForName(name),
          orders: 0,
          units: 0,
          cost: 0,
          fulfilled: 0
        });
      });
      analyticsOrders.forEach((order) => {
        const name = canonicalPlatformName(order.marketplace_platform);
        if (!metricMap.has(name)) {
          metricMap.set(name, { name, kind: platformKindForName(name), orders: 0, units: 0, cost: 0, fulfilled: 0 });
        }
        const metric = metricMap.get(name);
        const status = String(order.status || '').toUpperCase();
        const isCancelledOrder = ['CANCELLED', 'CANCELED'].includes(status);
        metric.orders += 1;
        if (!isCancelledOrder) {
          metric.units += orderUnits(order);
          metric.cost += orderRevenue(order);
        }
        if (['FULFILLED', 'COMPLETED', 'SHIPPED'].includes(status)) metric.fulfilled += 1;
      });

      const metrics = [...metricMap.values()];
      platformMetrics.innerHTML = metrics.length ? metrics.map((metric) => `
        <article class="partner-platform-metric-card" data-platform-kind="${escapeHtml(metric.kind)}">
          <div class="partner-platform-metric-head">
            <span class="partner-platform-badge" aria-hidden="true">${escapeHtml(platformBadgeText(metric))}</span>
            <div>
              <strong>${escapeHtml(metric.name)}</strong>
              <small>${escapeHtml(metric.kind === 'custom' ? 'Custom reseller' : 'Built-in marketplace')}</small>
            </div>
          </div>
          <dl>
            <div><dt>Orders</dt><dd>${escapeHtml(metric.orders)}</dd></div>
            <div><dt>SKU units</dt><dd>${escapeHtml(metric.units)}</dd></div>
            <div><dt>Partner cost</dt><dd>${escapeHtml(formatCurrency(metric.cost))}</dd></div>
            <div><dt>Fulfilled</dt><dd>${escapeHtml(metric.fulfilled)}</dd></div>
          </dl>
        </article>
      `).join('') : '<p class="admin-empty">Platform metrics will appear after orders are created.</p>';
    }

    if (!productMix) return;
    const productUnits = new Map();
    analyticsOrders.forEach((order) => {
      if (['CANCELLED', 'CANCELED'].includes(String(order.status || '').toUpperCase())) return;
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
          <span>${escapeHtml(units)} ${localizedText('units', 'unit')}</span>
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
          ${label.expires_at ? `<span>${localizedText('Deletes', 'Dihapus')} ${escapeHtml(formatTimestamp(label.expires_at))}</span>` : ''}
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
      label: date.toLocaleDateString(localeCode(), { month: 'short', day: 'numeric', timeZone: state.timezone }),
      value: 0,
      platforms: new Map()
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
        return { key: `${date.getFullYear()}-${date.getMonth()}-${date.getDate()}-${date.getHours()}`, label: `${String(date.getHours()).padStart(2, '0')}:00`, value: 0, platforms: new Map() };
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
      return { key: `${date.getFullYear()}-${date.getMonth()}`, label: date.toLocaleDateString(localeCode(), { month: 'short', timeZone: state.timezone }), value: 0, platforms: new Map() };
    });
  };

  const clearChartInspection = () => {
    salesChart?.classList.remove('is-inspecting');
    salesChart?.querySelectorAll('[data-chart-bucket]').forEach((bar) => bar.classList.remove('is-active'));
    if (salesChartBreakdown instanceof HTMLElement) {
      salesChartBreakdown.classList.remove('is-left');
      salesChartBreakdown.hidden = true;
      salesChartBreakdown.innerHTML = '';
    }
  };

  const inspectChartBucket = (bucket, series, bar) => {
    if (!(salesChart instanceof HTMLElement) || !(salesChartBreakdown instanceof HTMLElement)) return;
    salesChart.classList.add('is-inspecting');
    salesChart.querySelectorAll('[data-chart-bucket]').forEach((candidate) => {
      candidate.classList.toggle('is-active', candidate === bar);
    });
    const rows = series.filter((platform) => Number(bucket.platforms.get(platform.name) || 0) > 0).map((platform) => {
      const units = Number(bucket.platforms.get(platform.name) || 0);
      return `
        <div class="partner-chart-breakdown-row">
          <span class="partner-chart-swatch" style="--platform-color:${platform.color}" aria-hidden="true"></span>
          <strong>${escapeHtml(platform.name)}</strong>
          <b>${escapeHtml(units)} ${localizedText(units === 1 ? 'unit' : 'units', 'unit')}</b>
        </div>
      `;
    }).join('');
    salesChartBreakdown.innerHTML = `
      <div class="partner-chart-breakdown-head">
        <strong>${escapeHtml(bucket.label)}</strong>
        <span>${escapeHtml(bucket.value)} ${localizedText('total units', 'total unit')}</span>
      </div>
      <div class="partner-chart-breakdown-list">
        ${rows || `<span class="partner-chart-breakdown-empty">${localizedText('No platform units in this period.', 'Tidak ada unit platform pada periode ini.')}</span>`}
      </div>
    `;
    const chartBounds = salesChart.getBoundingClientRect();
    const barBounds = bar.getBoundingClientRect();
    salesChartBreakdown.classList.toggle('is-left', barBounds.left + (barBounds.width / 2) > chartBounds.left + (chartBounds.width / 2));
    salesChartBreakdown.hidden = false;
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
    const orderPlatformNames = [...new Set(orders.map((order) => canonicalPlatformName(order.marketplace_platform)))];
    const configuredPlatformNames = state.platformOptions.map((option) => canonicalPlatformName(option.name));
    const seriesNames = [
      ...configuredPlatformNames.filter((name) => orderPlatformNames.includes(name)),
      ...orderPlatformNames.filter((name) => !configuredPlatformNames.includes(name))
    ];
    const series = seriesNames.map((name) => ({
      name,
      kind: platformKindForName(name),
      color: platformColorForName(name)
    }));
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
      if (bucket) {
        const units = orderUnits(order);
        const platformName = canonicalPlatformName(order.marketplace_platform);
        bucket.value += units;
        bucket.platforms.set(platformName, Number(bucket.platforms.get(platformName) || 0) + units);
      }
    });

    const maxValue = Math.max(1, ...buckets.map((bucket) => bucket.value));
    clearChartInspection();
    salesChart.innerHTML = buckets.map((bucket, index) => {
      const segments = series.map((platform) => {
        const units = Number(bucket.platforms.get(platform.name) || 0);
        if (units <= 0) return '';
        return `<span class="partner-bar-segment" style="--platform-color:${platform.color};flex-grow:${units}" title="${escapeHtml(platform.name)}: ${escapeHtml(units)} ${localizedText('units', 'unit')}"></span>`;
      }).join('');
      const height = bucket.value > 0 ? Math.max(4, Math.round((bucket.value / maxValue) * 100)) : 1;
      return `
        <button type="button" class="partner-bar" data-chart-bucket="${index}" aria-label="${escapeHtml(bucket.label)}: ${escapeHtml(bucket.value)} ${localizedText('total units. Focus for platform breakdown.', 'total unit. Fokus untuk rincian platform.')}">
          <span class="partner-bar-plot" aria-hidden="true">
            <span class="partner-bar-stack${bucket.value > 0 ? '' : ' is-empty'}" style="height:${height}%">${segments}</span>
          </span>
          <span class="partner-bar-label">${escapeHtml(bucket.label)}</span>
        </button>
      `;
    }).join('');

    if (salesChartLegend) {
      salesChartLegend.innerHTML = series.length ? series.map((platform) => `
        <span><i class="partner-chart-swatch" style="--platform-color:${platform.color}" aria-hidden="true"></i>${escapeHtml(platform.name)}</span>
      `).join('') : `<span class="partner-chart-legend-empty">${localizedText('Platform colors appear after the first order.', 'Warna platform muncul setelah pesanan pertama.')}</span>`;
    }

    salesChart.querySelectorAll('[data-chart-bucket]').forEach((bar) => {
      const bucket = buckets[Number(bar.getAttribute('data-chart-bucket'))];
      if (!bucket) return;
      bar.addEventListener('pointerenter', () => inspectChartBucket(bucket, series, bar));
      bar.addEventListener('pointerleave', () => {
        if (document.activeElement !== bar) clearChartInspection();
      });
      bar.addEventListener('focus', () => inspectChartBucket(bucket, series, bar));
      bar.addEventListener('blur', clearChartInspection);
    });
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
          <span>${escapeHtml(order.marketplace_platform || localizedText('Needs review', 'Perlu ditinjau'))} · ${escapeHtml(orderUnits(order))} ${localizedText('qty', 'jumlah')}</span>
        </div>
        <div>
          <b>${escapeHtml(statusLabel(order))}</b>
          <span>${escapeHtml(formatTimestamp(order.order_timestamp || order.created_at || ''))}</span>
        </div>
      </article>
    `).join('') : `<p class="admin-empty">${localizedText('No orders from the last 7 days.', 'Tidak ada pesanan dalam 7 hari terakhir.')}</p>`;
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
            <span>${escapeHtml(order.deadline_hours || 24)}${localizedText('h', 'j')} ${localizedText('deadline', 'batas waktu')}</span>
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
            <span>${localizedText('Stock', 'Stok')} <b>${escapeHtml(sku.current_stock ?? 0)}</b></span>
            <span>${escapeHtml(unitFormula(sku))}</span>
            <strong>${escapeHtml(formatCurrency(sku.partner_price || 0))}</strong>
          </div>
          <div class="partner-sku-actions">
            <button type="button" data-add-sku="${escapeHtml(sku.sku)}" data-add-qty="1" aria-label="${localizedText('Add one', 'Tambahkan satu')} ${escapeHtml(skuDisplayName(sku))}">+</button>
            ${inCart ? `<span>${escapeHtml(inCart.quantity)} ${localizedText('selected', 'dipilih')}</span>` : ''}
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
    totals.partnerCost += quantity * Number(item.partner_price || 0);
    return totals;
  }, { quantity: 0, billableUnits: 0, partnerCost: 0 });

  const canSubmitCurrentOrder = () => Boolean(state.labelFile && state.cart.length && platformSelect?.value && !state.submitting);

  const renderPreview = () => {
    const totals = cartTotals();
    const platform = platformSelect?.value || 'Not selected';
    const customerName = customerNameInput?.value.trim() || 'Label recipient';
    if (orderPreview) {
      const cartMarkup = state.cart.length ? state.cart.map((item) => `
        <article class="partner-cart-row">
          <div>
            <strong>${escapeHtml(compactSkuDisplayName(item))}</strong>
            <span>${escapeHtml(item.sku || '')} · ${escapeHtml(formatCurrency(item.partner_price || 0))} ${localizedText('per SKU', 'per SKU')}</span>
          </div>
          <div class="partner-cart-controls">
            <button type="button" data-cart-qty="${escapeHtml(item.sku)}" data-cart-delta="-1">-</button>
            <input type="number" min="0" step="1" value="${escapeHtml(item.quantity || 0)}" data-cart-input="${escapeHtml(item.sku)}" aria-label="${localizedText('Quantity for', 'Jumlah untuk')} ${escapeHtml(compactSkuDisplayName(item))}">
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
        <article><span>Cost to partner</span><strong>${escapeHtml(formatCurrency(totals.partnerCost))}</strong></article>
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
    setPlatformValue('', false);
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
    closePlatformMenu();
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

  const openFaviconModal = () => {
    if (!(faviconModal instanceof HTMLElement)) return;
    faviconModal.hidden = false;
    faviconModal.querySelector('[data-choose-favicon]')?.focus();
  };

  const closeFaviconModal = () => {
    if (!(faviconModal instanceof HTMLElement)) return;
    faviconModal.hidden = true;
    faviconModal.querySelectorAll('[data-favicon-error]').forEach((node) => setError('', node));
  };

  const openPlatformSettingsModal = () => {
    if (!(platformSettingsModal instanceof HTMLElement)) return;
    platformSettingsModal.hidden = false;
    platformProfileForm?.querySelector('input')?.focus();
  };

  const closePlatformSettingsModal = () => {
    if (!(platformSettingsModal instanceof HTMLElement)) return;
    platformSettingsModal.hidden = true;
    setError('', platformProfileError);
  };

  const applyRegionalPreferences = (preferences = {}, refresh = false) => {
    state.language = ['id', 'en'].includes(String(preferences.language || '')) ? String(preferences.language) : 'id';
    state.timezone = ['Asia/Jakarta', 'Asia/Makassar', 'Asia/Jayapura'].includes(String(preferences.timezone || ''))
      ? String(preferences.timezone)
      : 'Asia/Jakarta';
    root.dataset.partnerLanguage = state.language;
    root.dataset.partnerTimezone = state.timezone;
    document.documentElement.lang = state.language;
    document.title = localizedText('Partner Dashboard | Jenang Gemi', 'Dashboard Mitra | Jenang Gemi');
    if (languageSetting instanceof HTMLSelectElement) languageSetting.value = state.language;
    if (timezoneSetting instanceof HTMLSelectElement) timezoneSetting.value = state.timezone;
    translateTree(document.body);
    if (!refresh) return;
    renderPlatformProfiles();
    renderFaviconSettings();
    renderOrders();
    renderDeadline();
    renderLabelQueue();
    renderSkuList();
    setActiveSection(state.activeSection, false);
    translateTree(document.body);
  };

  const loadOrders = async () => {
    const payload = await requestJson(ordersEndpoint);
    state.orders = payload.orders || [];
    renderOrders();
  };

  const loadSession = async () => {
    const payload = await requestJson(sessionEndpoint);
    applyRegionalPreferences(payload.preferences || {}, false);
    if (payload.preferences_error && regionalSettingsStatus) {
      regionalSettingsStatus.classList.add('is-error');
      regionalSettingsStatus.textContent = String(payload.preferences_error);
    }
    state.partner = payload.partner || null;
    state.catalog = payload.catalog || {};
    renderPlatformOptions(payload.platform_options || defaultPlatformOptions);
    setError(payload.platform_options_error || '', platformProfileError);
    state.passwordResetRequired = Boolean(payload.password_reset_required);
    flattenCatalog();
    if (partnerNameNode) partnerNameNode.textContent = state.partner?.name || 'Partner';
    if (partnerCodeNode) partnerCodeNode.textContent = state.partner?.code
      ? localizedText(`Workspace ${state.partner.code}`, `Ruang kerja ${state.partner.code}`)
      : localizedText('Direct ordering portal', 'Portal pemesanan langsung');
    renderPasswordResetState();
    if (state.passwordResetRequired && passwordModal instanceof HTMLElement && passwordModal.hidden) {
      openPasswordModal();
    }
    renderSkuList();
    renderPreview();
  };

  const loadFavicons = async () => {
    const payload = await requestJson(faviconEndpoint);
    setFaviconSettings(payload.favicons || {});
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
  document.querySelectorAll('[data-open-favicon-modal]').forEach((button) => {
    button.addEventListener('click', openFaviconModal);
  });
  document.querySelectorAll('[data-close-favicon-modal]').forEach((button) => {
    button.addEventListener('click', closeFaviconModal);
  });
  document.querySelectorAll('[data-open-platform-settings-modal]').forEach((button) => {
    button.addEventListener('click', openPlatformSettingsModal);
  });
  document.querySelectorAll('[data-close-platform-settings-modal]').forEach((button) => {
    button.addEventListener('click', closePlatformSettingsModal);
  });
  document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') return;
    if (faviconModal instanceof HTMLElement && !faviconModal.hidden) closeFaviconModal();
    if (platformSettingsModal instanceof HTMLElement && !platformSettingsModal.hidden) closePlatformSettingsModal();
  });

  regionalSettingsForm?.addEventListener('change', async () => {
    if (!(languageSetting instanceof HTMLSelectElement) || !(timezoneSetting instanceof HTMLSelectElement)) return;
    const previous = { language: state.language, timezone: state.timezone };
    const next = { language: languageSetting.value, timezone: timezoneSetting.value };
    applyRegionalPreferences(next, true);
    if (regionalSettingsStatus) {
      regionalSettingsStatus.classList.remove('is-error');
      regionalSettingsStatus.textContent = localizedText('Saving…', 'Menyimpan…');
    }
    languageSetting.disabled = true;
    timezoneSetting.disabled = true;
    try {
      const payload = await requestJson(sessionEndpoint, {
        method: 'POST',
        body: { action: 'update_preferences', ...next }
      });
      applyRegionalPreferences(payload.preferences || next, true);
      if (regionalSettingsStatus) regionalSettingsStatus.textContent = localizedText('Regional settings saved.', 'Pengaturan regional tersimpan.');
    } catch (error) {
      applyRegionalPreferences(previous, true);
      if (regionalSettingsStatus) {
        regionalSettingsStatus.classList.add('is-error');
        regionalSettingsStatus.textContent = error instanceof Error ? error.message : localizedText('Unable to save regional settings.', 'Pengaturan regional tidak dapat disimpan.');
      }
    } finally {
      languageSetting.disabled = false;
      timezoneSetting.disabled = false;
    }
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

  platformTrigger?.addEventListener('click', () => {
    if (platformTrigger.getAttribute('aria-expanded') === 'true') {
      closePlatformMenu();
      return;
    }
    openPlatformMenu();
  });

  platformTrigger?.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
      closePlatformMenu();
      return;
    }
    if (!['ArrowDown', 'ArrowUp'].includes(event.key)) return;
    event.preventDefault();
    openPlatformMenu();
    const target = event.key === 'ArrowUp' ? platformOptions.at(-1) : platformOptions[0];
    if (target instanceof HTMLButtonElement) target.focus();
  });

  platformMenu?.addEventListener('click', (event) => {
    const option = event.target instanceof Element ? event.target.closest('[data-platform-option]') : null;
    if (!(option instanceof HTMLButtonElement)) return;
    setPlatformValue(option.getAttribute('data-platform-option') || '');
    if (platformTrigger instanceof HTMLButtonElement) platformTrigger.focus();
  });

  platformMenu?.addEventListener('keydown', (event) => {
    const currentIndex = platformOptions.indexOf(document.activeElement);
    if (event.key === 'Escape') {
      event.preventDefault();
      closePlatformMenu();
      if (platformTrigger instanceof HTMLButtonElement) platformTrigger.focus();
      return;
    }
    if (!['ArrowDown', 'ArrowUp'].includes(event.key) || currentIndex < 0) return;
    event.preventDefault();
    const delta = event.key === 'ArrowDown' ? 1 : -1;
    const nextIndex = (currentIndex + delta + platformOptions.length) % platformOptions.length;
    const next = platformOptions[nextIndex];
    if (next instanceof HTMLButtonElement) next.focus();
  });

  document.addEventListener('click', (event) => {
    if (!(platformPicker instanceof HTMLElement) || !(event.target instanceof Node) || platformPicker.contains(event.target)) return;
    closePlatformMenu();
  });

  platformProfileForm?.addEventListener('submit', async (event) => {
    event.preventDefault();
    setError('', platformProfileError);
    const formData = new window.FormData(platformProfileForm);
    const name = String(formData.get('platform_name') || '').trim();
    if (!name) {
      setError('Enter a reseller or platform name.', platformProfileError);
      return;
    }

    const submitButton = platformProfileForm.querySelector('[type="submit"]');
    if (submitButton instanceof HTMLButtonElement) submitButton.disabled = true;
    try {
      const payload = await requestJson(sessionEndpoint, {
        method: 'POST',
        body: { action: 'add_platform', name }
      });
      renderPlatformOptions(payload.platform_options || state.platformOptions);
      platformProfileForm.reset();
    } catch (error) {
      setError(error instanceof Error ? error.message : 'Unable to add platform.', platformProfileError);
    } finally {
      if (submitButton instanceof HTMLButtonElement) submitButton.disabled = false;
    }
  });

  platformProfileList?.addEventListener('click', async (event) => {
    const button = event.target instanceof Element ? event.target.closest('[data-remove-platform]') : null;
    if (!(button instanceof HTMLButtonElement)) return;
    setError('', platformProfileError);
    button.disabled = true;
    try {
      const payload = await requestJson(sessionEndpoint, {
        method: 'POST',
        body: { action: 'delete_platform', id: button.getAttribute('data-remove-platform') || '' }
      });
      renderPlatformOptions(payload.platform_options || state.platformOptions);
    } catch (error) {
      button.disabled = false;
      setError(error instanceof Error ? error.message : 'Unable to remove platform.', platformProfileError);
    }
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

    if (!platformSelect?.value) {
      setError('Select an order platform.', modalErrorNode);
      if (platformTrigger instanceof HTMLButtonElement) platformTrigger.focus();
      return;
    }
    if (!canSubmitCurrentOrder()) {
      setError('Upload a label and select at least one approved SKU.', modalErrorNode);
      return;
    }

    state.submitting = true;
    renderPreview();

    try {
      const formData = new window.FormData(orderForm);
      const platform = String(formData.get('marketplace_platform') || platformSelect?.value || '').trim();
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
            confidence: 1,
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
    renderMetrics();
    renderChart();
  });

  if (chartMonthInput instanceof HTMLInputElement) {
    chartMonthInput.max = currentChartMonth;
    chartMonthInput.value = state.selectedMonth;
    chartMonthInput.addEventListener('change', () => {
      if (!/^\d{4}-\d{2}$/.test(chartMonthInput.value)) return;
      state.selectedMonth = chartMonthInput.value;
      state.selectedTimeframe = 'month';
      renderMetrics();
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

  faviconForms.forEach((form) => {
    const input = form.querySelector('[data-favicon-input]');
    const choose = form.querySelector('[data-choose-favicon]');
    const remove = form.querySelector('[data-remove-favicon]');
    const errorNode = form.querySelector('[data-favicon-error]');
    const theme = String(form.dataset.faviconTheme || '');

    choose?.addEventListener('click', () => {
      if (input instanceof HTMLInputElement) input.click();
    });

    input?.addEventListener('change', async () => {
      if (!(input instanceof HTMLInputElement)) return;
      const file = input.files?.[0];
      if (!file) return;
      setError('', errorNode);
      if (file.size > 1024 * 1024) {
        setError('Favicon must be no larger than 1 MB.', errorNode);
        input.value = '';
        return;
      }

      const formData = new window.FormData();
      formData.set('theme', theme);
      formData.set('favicon', file);
      if (choose instanceof HTMLButtonElement) choose.disabled = true;
      if (remove instanceof HTMLButtonElement) remove.disabled = true;
      try {
        const payload = await requestFormData(faviconEndpoint, formData);
        setFaviconSettings(payload.favicons || {});
      } catch (error) {
        setError(error instanceof Error ? error.message : 'Unable to upload favicon.', errorNode);
      } finally {
        input.value = '';
        if (choose instanceof HTMLButtonElement) choose.disabled = false;
        if (remove instanceof HTMLButtonElement) remove.disabled = false;
      }
    });

    remove?.addEventListener('click', async () => {
      setError('', errorNode);
      if (choose instanceof HTMLButtonElement) choose.disabled = true;
      if (remove instanceof HTMLButtonElement) remove.disabled = true;
      try {
        const payload = await requestJson(faviconEndpoint, {
          method: 'DELETE',
          body: { theme }
        });
        setFaviconSettings(payload.favicons || {});
      } catch (error) {
        setError(error instanceof Error ? error.message : 'Unable to remove favicon.', errorNode);
      } finally {
        if (choose instanceof HTMLButtonElement) choose.disabled = false;
        if (remove instanceof HTMLButtonElement) remove.disabled = false;
      }
    });
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

  applyRegionalPreferences({ language: state.language, timezone: state.timezone }, false);
  applyTheme(state.theme);
  renderFaviconSettings();
  window.history.replaceState({ section: state.activeSection }, '', window.location.href);
  setActiveSection(state.activeSection, false);

  loadFavicons().catch(() => {});
  Promise.all([loadSession(), loadOrders()]).catch((error) => {
    setError(error instanceof Error ? error.message : 'Unable to load dashboard.');
  });
});
