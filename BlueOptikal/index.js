<script>
// =============================================================
// INTEGRASI DATABASE
// Ganti Storage adapter dengan API calls ke database
// =============================================================

// Konfigurasi API endpoint
const API_BASE = 'http://localhost/BlueOptikal'; // Sesuaikan dengan path folder Anda

// Storage adapter yang terintegrasi dengan database
const DbStorage = {
    async save(orderId, data) {
        try {
            const response = await fetch(`${API_BASE}/save_data.php`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    orderId: orderId,
                    ...data,
                    // Tambahkan status pembayaran jika ada
                    status: data.status || null
                })
            });
            
            const result = await response.json();
            if (result.success) {
                // Simpan juga ke localStorage sebagai cache
                localStorage.setItem('blueoptikal:order:' + orderId, JSON.stringify({ ...data, _updatedAt: Date.now() }));
                localStorage.setItem('blueoptikal:lastOrder', orderId);
                return true;
            } else {
                console.error('Gagal menyimpan:', result.message);
                return false;
            }
        } catch (err) {
            console.error('Error saving to database:', err);
            // Fallback ke localStorage jika gagal
            try {
                localStorage.setItem('blueoptikal:order:' + orderId, JSON.stringify({ ...data, _updatedAt: Date.now() }));
                localStorage.setItem('blueoptikal:lastOrder', orderId);
                return true;
            } catch (e) {
                return false;
            }
        }
    },

    async load(orderId) {
        try {
            // Coba load dari database terlebih dahulu
            const response = await fetch(`${API_BASE}/api.php?action=get_order&id=${orderId}`);
            const result = await response.json();
            
            if (result.success && result.data) {
                // Konversi data dari database ke format state
                const data = result.data;
                const customerData = {
                    name: data.Nama_pelanggan || '',
                    phone: data.No_hp || '',
                    gender: data.Jenis_kelamin || '',
                    age: data.Umur || '',
                    addr: data.Alamat || '',
                    note: '',
                    resep: data.Resep_kacamata || '',
                    examDate: data.Tgl_pemeriksaan || ''
                };
                
                return {
                    customer: customerData,
                    selectedFrame: data.Kode_frame,
                    selectedLens: data.Kode_lensa,
                    namaPetugas: data.Nama_petugas,
                    status: data.Total_harga ? 'paid' : 'pending'
                };
            }
            
            // Fallback ke localStorage
            const raw = localStorage.getItem('blueoptikal:order:' + orderId);
            return raw ? JSON.parse(raw) : null;
        } catch (err) {
            console.error('Error loading from database:', err);
            // Fallback ke localStorage
            try {
                const raw = localStorage.getItem('blueoptikal:order:' + orderId);
                return raw ? JSON.parse(raw) : null;
            } catch (e) {
                return null;
            }
        }
    },

    async list() {
        try {
            const response = await fetch(`${API_BASE}/fetch_data.php?action=orders`);
            const data = await response.json();
            if (Array.isArray(data)) {
                return data.map(order => 'BO-' + String(order.No_pemesanan).padStart(4, '0'));
            }
            return [];
        } catch (err) {
            console.error('Error listing orders:', err);
            // Fallback ke localStorage
            try {
                return Object.keys(localStorage)
                    .filter(k => k.startsWith('blueoptikal:order:'))
                    .map(k => k.slice('blueoptikal:order:'.length));
            } catch (e) {
                return [];
            }
        }
    },

    async remove(orderId) {
        // Tidak diimplementasikan untuk database
        // Hanya hapus dari localStorage
        try {
            localStorage.removeItem('blueoptikal:order:' + orderId);
            return true;
        } catch (err) {
            return false;
        }
    },

    getLastOrderId() {
        try { return localStorage.getItem('blueoptikal:lastOrder'); }
        catch (err) { return null; }
    }
};

// =============================================================
// OVERRIDE Storage dengan DbStorage
// =============================================================
// Ganti Storage dengan DbStorage untuk integrasi database
// Comment out line di bawah jika ingin menggunakan localStorage saja

// Opsi 1: Gunakan DbStorage sepenuhnya (dengan cache localStorage)
// const Storage = DbStorage;

// Opsi 2: Gunakan keduanya (tulis ke database dan localStorage)
// Modifikasi persist function untuk menyimpan ke kedua tempat

// Contoh modifikasi persist function
const originalPersist = persist;
persist = function() {
    clearTimeout(_saveTimer);
    _saveTimer = setTimeout(async () => {
        // Simpan ke localStorage
        try {
            localStorage.setItem('blueoptikal:order:' + state.orderId, JSON.stringify({ ...state, currentView }));
            localStorage.setItem('blueoptikal:lastOrder', state.orderId);
        } catch (e) {}
        
        // Simpan ke database
        try {
            await DbStorage.save(state.orderId, { ...state, currentView });
        } catch (e) {
            console.error('Failed to save to database:', e);
        }
        
        // Tampilkan indikator
        const dot = document.getElementById('saveDot');
        if (dot) { 
            dot.classList.add('flash'); 
            setTimeout(() => dot.classList.remove('flash'), 700); 
        }
    }, 350);
};

// Modifikasi initApp untuk load dari database
const originalInitApp = initApp;
initApp = async function() {
    const lastId = DbStorage.getLastOrderId();
    if (lastId) {
        const saved = await DbStorage.load(lastId);
        if (saved) {
            state.orderId = lastId;
            if (saved.customer) Object.assign(state.customer, saved.customer);
            state.selectedFrame = saved.selectedFrame || state.selectedFrame;
            state.selectedLens = saved.selectedLens || state.selectedLens;
            state.genderFilter = saved.genderFilter || state.genderFilter;
            state.inCart = !!saved.inCart;
            state.fulfil = saved.fulfil || state.fulfil;
            state.payMethod = saved.payMethod || state.payMethod;
            state.namaPetugas = saved.namaPetugas || '';
            state.status = saved.status || null;
            populateFormFromState();
        }
    }
    renderAll();
    goTo(state.status === 'paid' ? 'pembayaran' : 'pemeriksaan');
    persist();
};

// Jalankan initApp yang baru
// Comment out initApp yang original di akhir file
// dan jalankan yang baru
// initApp();
</script>