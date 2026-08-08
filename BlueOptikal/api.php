<?php
// api.php - API handler untuk komunikasi dengan database
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type');

require_once 'config.php';

// Get request method
$method = $_SERVER['REQUEST_METHOD'];
$action = isset($_GET['action']) ? $_GET['action'] : '';

// Handle different actions
switch ($action) {
    case 'save_order':
        saveOrder($conn);
        break;
    case 'get_order':
        getOrder($conn);
        break;
    case 'get_orders':
        getOrders($conn);
        break;
    case 'get_customer':
        getCustomer($conn);
        break;
    case 'get_frames':
        getFrames($conn);
        break;
    case 'get_lenses':
        getLenses($conn);
        break;
    case 'get_petugas':
        getPetugas($conn);
        break;
    case 'save_customer':
        saveCustomer($conn);
        break;
    case 'save_pemeriksaan':
        savePemeriksaan($conn);
        break;
    case 'save_pemesanan':
        savePemesanan($conn);
        break;
    case 'save_pembayaran':
        savePembayaran($conn);
        break;
    default:
        echo json_encode(['success' => false, 'message' => 'Aksi tidak ditemukan']);
        break;
}

$conn->close();

// Fungsi untuk menyimpan data order lengkap
function saveOrder($conn) {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!$data) {
        echo json_encode(['success' => false, 'message' => 'Data tidak valid']);
        return;
    }
    
    $orderId = $data['orderId'] ?? '';
    $customer = $data['customer'] ?? [];
    $selectedFrame = $data['selectedFrame'] ?? '';
    $selectedLens = $data['selectedLens'] ?? '';
    $namaPetugas = $data['namaPetugas'] ?? '';
    $status = $data['status'] ?? 'pending';
    $fulfil = $data['fulfil'] ?? 'store';
    $payMethod = $data['payMethod'] ?? 'va';
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        // 1. Save or update customer
        $customerId = saveCustomerData($conn, $customer, $orderId);
        if (!$customerId) {
            throw new Exception('Gagal menyimpan data pelanggan');
        }
        
        // 2. Save pemeriksaan
        $noPemeriksaan = savePemeriksaanData($conn, $customerId, $orderId, $data, $namaPetugas);
        if (!$noPemeriksaan) {
            throw new Exception('Gagal menyimpan data pemeriksaan');
        }
        
        // 3. Save pemesanan
        $noPemesanan = savePemesananData($conn, $customerId, $noPemeriksaan, $selectedFrame, $selectedLens, $orderId, $namaPetugas);
        if (!$noPemesanan) {
            throw new Exception('Gagal menyimpan data pemesanan');
        }
        
        // 4. If status is paid, save pembayaran
        if ($status === 'paid') {
            $noPembayaran = savePembayaranData($conn, $noPemesanan, $data);
            if (!$noPembayaran) {
                throw new Exception('Gagal menyimpan data pembayaran');
            }
        }
        
        $conn->commit();
        echo json_encode(['success' => true, 'message' => 'Data berhasil disimpan']);
        
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

// Fungsi untuk menyimpan data pelanggan
function saveCustomerData($conn, $customer, $orderId) {
    $name = $conn->real_escape_string($customer['name'] ?? '');
    $phone = $conn->real_escape_string($customer['phone'] ?? '');
    $gender = $conn->real_escape_string($customer['gender'] ?? '');
    $age = intval($customer['age'] ?? 0);
    $addr = $conn->real_escape_string($customer['addr'] ?? '');
    $note = $conn->real_escape_string($customer['note'] ?? '');
    $resep = $conn->real_escape_string($customer['resep'] ?? '');
    $examDate = $customer['examDate'] ?? '';
    
    // Generate customer ID based on order
    $customerId = 'CUST-' . substr($orderId, -4);
    
    // Check if customer exists
    $checkSql = "SELECT Id_pelanggan FROM pelanggan WHERE Id_pelanggan = '$customerId'";
    $result = $conn->query($checkSql);
    
    if ($result->num_rows > 0) {
        // Update existing customer
        $sql = "UPDATE pelanggan SET 
                Nama_pelanggan = '$name',
                Alamat = '$addr',
                Umur = $age,
                Jenis_kelamin = '$gender',
                No_hp = '$phone'
                WHERE Id_pelanggan = '$customerId'";
    } else {
        // Insert new customer
        $sql = "INSERT INTO pelanggan (Id_pelanggan, Nama_pelanggan, Alamat, Umur, Jenis_kelamin, No_hp) 
                VALUES ('$customerId', '$name', '$addr', $age, '$gender', '$phone')";
    }
    
    if ($conn->query($sql)) {
        return $customerId;
    }
    return false;
}

// Fungsi untuk menyimpan data pemeriksaan
function savePemeriksaanData($conn, $customerId, $orderId, $data, $namaPetugas) {
    $noPemeriksaan = 'PX-' . substr($orderId, -4);
    $examDate = $data['customer']['examDate'] ?? date('Y-m-d');
    $resep = $conn->real_escape_string($data['customer']['resep'] ?? '');
    $petugasId = 'PTG-' . substr($orderId, -4);
    
    // Save petugas if not exists
    if ($namaPetugas) {
        $petugasName = $conn->real_escape_string($namaPetugas);
        $checkPetugas = "SELECT Id_petugas FROM petugas WHERE Id_petugas = '$petugasId'";
        $result = $conn->query($checkPetugas);
        if ($result->num_rows == 0) {
            $conn->query("INSERT INTO petugas (Id_petugas, Nama_petugas) VALUES ('$petugasId', '$petugasName')");
        } else {
            $conn->query("UPDATE petugas SET Nama_petugas = '$petugasName' WHERE Id_petugas = '$petugasId'");
        }
    }
    
    $sql = "INSERT INTO pemeriksaan (Id_pelanggan, No_pemeriksaan, Tgl_pemeriksaan, Resep_kacamata, Id_petugas) 
            VALUES ('$customerId', $noPemeriksaan, '$examDate', '$resep', '$petugasId')
            ON DUPLICATE KEY UPDATE 
            Tgl_pemeriksaan = '$examDate', 
            Resep_kacamata = '$resep',
            Id_petugas = '$petugasId'";
    
    if ($conn->query($sql)) {
        return $noPemeriksaan;
    }
    return false;
}

// Fungsi untuk menyimpan data pemesanan
function savePemesananData($conn, $customerId, $noPemeriksaan, $frameId, $lensId, $orderId, $namaPetugas) {
    $noPemesanan = intval(substr($orderId, -4));
    $petugasId = 'PTG-' . substr($orderId, -4);
    $noPembayaran = null;
    
    // Get frame and lens price
    $framePrice = 0;
    $lensPrice = 0;
    
    $frameSql = "SELECT Kode_frame FROM frame WHERE Kode_frame = '$frameId'";
    $frameResult = $conn->query($frameSql);
    if ($frameResult->num_rows == 0) {
        // Insert default frame if not exists
        $conn->query("INSERT INTO frame (Kode_frame, Warna_frame) VALUES ('$frameId', 'Hitam')");
    }
    
    $lensSql = "SELECT Kode_lensa FROM lensa WHERE Kode_lensa = '$lensId'";
    $lensResult = $conn->query($lensSql);
    if ($lensResult->num_rows == 0) {
        // Insert default lens if not exists
        $lensName = $lensId === 'anti' ? 'Lensa Anti-Radiasi' : 
                   ($lensId === 'blue' ? 'Lensa BlueRay' :
                   ($lensId === 'photo' ? 'Lensa Photocromic' : 'Blue-Cromic'));
        $conn->query("INSERT INTO lensa (Kode_lensa, Jenis_lensa) VALUES ('$lensId', '$lensName')");
    }
    
    $sql = "INSERT INTO pemesanan (No_pemesanan, DP, No_pemeriksaan, Id_petugas, Id_pelanggan, Kode_frame, Kode_lensa, No_pembayaran) 
            VALUES ($noPemesanan, 0, $noPemeriksaan, '$petugasId', '$customerId', '$frameId', '$lensId', NULL)
            ON DUPLICATE KEY UPDATE 
            Kode_frame = '$frameId', 
            Kode_lensa = '$lensId'";
    
    if ($conn->query($sql)) {
        return $noPemesanan;
    }
    return false;
}

// Fungsi untuk menyimpan data pembayaran
function savePembayaranData($conn, $noPemesanan, $data) {
    $noPembayaran = intval(substr($data['orderId'], -4)) + 1000;
    $totalHarga = getTotalHarga($conn, $noPemesanan);
    $payMethod = $data['payMethod'] ?? 'va';
    
    $transferBank = ($payMethod === 'va') ? $totalHarga : 0;
    $qris = ($payMethod === 'qris') ? $totalHarga : 0;
    $tunai = ($payMethod === 'store') ? $totalHarga : 0;
    
    $sql = "INSERT INTO pembayaran (No_pembayaran, Total_harga, Transfer_bank, Qris, Tunai) 
            VALUES ($noPembayaran, $totalHarga, $transferBank, $qris, $tunai)
            ON DUPLICATE KEY UPDATE 
            Total_harga = $totalHarga, 
            Transfer_bank = $transferBank, 
            Qris = $qris, 
            Tunai = $tunai";
    
    if ($conn->query($sql)) {
        // Update pemesanan with no_pembayaran
        $conn->query("UPDATE pemesanan SET No_pembayaran = $noPembayaran WHERE No_pemesanan = $noPemesanan");
        return $noPembayaran;
    }
    return false;
}

// Helper function to get total price
function getTotalHarga($conn, $noPemesanan) {
    $sql = "SELECT p.DP, f.price as frame_price, l.price as lens_price 
            FROM pemesanan p 
            JOIN frame f ON p.Kode_frame = f.Kode_frame 
            JOIN lensa l ON p.Kode_lensa = l.Kode_lensa 
            WHERE p.No_pemesanan = $noPemesanan";
    $result = $conn->query($sql);
    if ($row = $result->fetch_assoc()) {
        return $row['frame_price'] + $row['lens_price'];
    }
    return 0;
}

// Fungsi untuk mengambil data order
function getOrder($conn) {
    $orderId = $_GET['id'] ?? '';
    if (!$orderId) {
        echo json_encode(['success' => false, 'message' => 'ID order tidak ditemukan']);
        return;
    }
    
    $noPemesanan = intval(substr($orderId, -4));
    
    $sql = "SELECT 
            p.No_pemesanan,
            p.DP,
            p.Id_pelanggan,
            p.Kode_frame,
            p.Kode_lensa,
            pel.Nama_pelanggan,
            pel.Alamat,
            pel.Umur,
            pel.Jenis_kelamin,
            pel.No_hp,
            pe.No_pemeriksaan,
            pe.Tgl_pemeriksaan,
            pe.Resep_kacamata,
            pe.Id_petugas,
            pt.Nama_petugas,
            f.Warna_frame,
            l.Jenis_lensa,
            pay.Total_harga,
            pay.Transfer_bank,
            pay.Qris,
            pay.Tunai
            FROM pemesanan p
            LEFT JOIN pelanggan pel ON p.Id_pelanggan = pel.Id_pelanggan
            LEFT JOIN pemeriksaan pe ON p.No_pemeriksaan = pe.No_pemeriksaan
            LEFT JOIN petugas pt ON pe.Id_petugas = pt.Id_petugas
            LEFT JOIN frame f ON p.Kode_frame = f.Kode_frame
            LEFT JOIN lensa l ON p.Kode_lensa = l.Kode_lensa
            LEFT JOIN pembayaran pay ON p.No_pembayaran = pay.No_pembayaran
            WHERE p.No_pemesanan = $noPemesanan";
    
    $result = $conn->query($sql);
    if ($row = $result->fetch_assoc()) {
        echo json_encode(['success' => true, 'data' => $row]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Order tidak ditemukan']);
    }
}

// Fungsi untuk mengambil semua frames
function getFrames($conn) {
    $sql = "SELECT Kode_frame as id, Warna_frame as name FROM frame";
    $result = $conn->query($sql);
    $frames = [];
    while ($row = $result->fetch_assoc()) {
        $frames[] = $row;
    }
    echo json_encode(['success' => true, 'data' => $frames]);
}

// Fungsi untuk mengambil semua lenses
function getLenses($conn) {
    $sql = "SELECT Kode_lensa as id, Jenis_lensa as name FROM lensa";
    $result = $conn->query($sql);
    $lenses = [];
    while ($row = $result->fetch_assoc()) {
        $lenses[] = $row;
    }
    echo json_encode(['success' => true, 'data' => $lenses]);
}

// Fungsi untuk mengambil semua petugas
function getPetugas($conn) {
    $sql = "SELECT Id_petugas as id, Nama_petugas as name FROM petugas";
    $result = $conn->query($sql);
    $petugas = [];
    while ($row = $result->fetch_assoc()) {
        $petugas[] = $row;
    }
    echo json_encode(['success' => true, 'data' => $petugas]);
}
?>