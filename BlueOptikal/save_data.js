<?php
// save_data.php - Direct save endpoint untuk integrasi dengan HTML
require_once 'config.php';

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Get data from POST
$data = json_decode(file_get_contents('php://input'), true);

if (!$data) {
    die(json_encode(['success' => false, 'message' => 'Data tidak valid']));
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
    // 1. Save customer
    $customerId = 'CUST-' . substr($orderId, -4);
    $name = $conn->real_escape_string($customer['name'] ?? '');
    $phone = $conn->real_escape_string($customer['phone'] ?? '');
    $gender = $conn->real_escape_string($customer['gender'] ?? '');
    $age = intval($customer['age'] ?? 0);
    $addr = $conn->real_escape_string($customer['addr'] ?? '');
    $note = $conn->real_escape_string($customer['note'] ?? '');
    $resep = $conn->real_escape_string($customer['resep'] ?? '');
    $examDate = $customer['examDate'] ?? date('Y-m-d');

    // Check if customer exists
    $checkSql = "SELECT Id_pelanggan FROM pelanggan WHERE Id_pelanggan = '$customerId'";
    $result = $conn->query($checkSql);
    
    if ($result->num_rows > 0) {
        $sql = "UPDATE pelanggan SET 
                Nama_pelanggan = '$name',
                Alamat = '$addr',
                Umur = $age,
                Jenis_kelamin = '$gender',
                No_hp = '$phone'
                WHERE Id_pelanggan = '$customerId'";
    } else {
        $sql = "INSERT INTO pelanggan (Id_pelanggan, Nama_pelanggan, Alamat, Umur, Jenis_kelamin, No_hp) 
                VALUES ('$customerId', '$name', '$addr', $age, '$gender', '$phone')";
    }
    
    if (!$conn->query($sql)) {
        throw new Exception('Gagal menyimpan data pelanggan: ' . $conn->error);
    }

    // 2. Save petugas
    $petugasId = 'PTG-' . substr($orderId, -4);
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

    // 3. Save pemeriksaan
    $noPemeriksaan = intval(substr($orderId, -4));
    $sql = "INSERT INTO pemeriksaan (Id_pelanggan, No_pemeriksaan, Tgl_pemeriksaan, Resep_kacamata, Id_petugas) 
            VALUES ('$customerId', $noPemeriksaan, '$examDate', '$resep', '$petugasId')
            ON DUPLICATE KEY UPDATE 
            Tgl_pemeriksaan = '$examDate', 
            Resep_kacamata = '$resep',
            Id_petugas = '$petugasId'";
    
    if (!$conn->query($sql)) {
        throw new Exception('Gagal menyimpan data pemeriksaan: ' . $conn->error);
    }

    // 4. Save or update frame and lens
    if ($selectedFrame) {
        // Check if frame exists
        $checkFrame = "SELECT Kode_frame FROM frame WHERE Kode_frame = '$selectedFrame'";
        $result = $conn->query($checkFrame);
        if ($result->num_rows == 0) {
            $conn->query("INSERT INTO frame (Kode_frame, Warna_frame) VALUES ('$selectedFrame', 'Hitam')");
        }
    }
    
    if ($selectedLens) {
        $lensName = $selectedLens === 'anti' ? 'Lensa Anti-Radiasi' : 
                   ($selectedLens === 'blue' ? 'Lensa BlueRay' :
                   ($selectedLens === 'photo' ? 'Lensa Photocromic' : 'Blue-Cromic'));
        $checkLens = "SELECT Kode_lensa FROM lensa WHERE Kode_lensa = '$selectedLens'";
        $result = $conn->query($checkLens);
        if ($result->num_rows == 0) {
            $conn->query("INSERT INTO lensa (Kode_lensa, Jenis_lensa) VALUES ('$selectedLens', '$lensName')");
        }
    }

    // 5. Save pemesanan
    $noPemesanan = intval(substr($orderId, -4));
    $sql = "INSERT INTO pemesanan (No_pemesanan, DP, No_pemeriksaan, Id_petugas, Id_pelanggan, Kode_frame, Kode_lensa, No_pembayaran) 
            VALUES ($noPemesanan, 0, $noPemeriksaan, '$petugasId', '$customerId', '$selectedFrame', '$selectedLens', NULL)
            ON DUPLICATE KEY UPDATE 
            Kode_frame = '$selectedFrame', 
            Kode_lensa = '$selectedLens'";
    
    if (!$conn->query($sql)) {
        throw new Exception('Gagal menyimpan data pemesanan: ' . $conn->error);
    }

    // 6. If status is paid, save pembayaran
    if ($status === 'paid') {
        $noPembayaran = intval(substr($orderId, -4)) + 1000;
        $totalHarga = 0;
        
        // Get total harga from frames and lenses (simplified, you can add price columns)
        $framePrice = 0;
        $lensPrice = 0;
        
        // For demo purposes, using fixed prices
        $framePrices = ['vw' => 1970000, 'ht' => 675000, 'lv' => 1515000];
        $lensPrices = ['anti' => 0, 'blue' => 150000, 'photo' => 250000, 'combo' => 350000];
        
        $framePrice = $framePrices[$selectedFrame] ?? 0;
        $lensPrice = $lensPrices[$selectedLens] ?? 0;
        $totalHarga = $framePrice + $lensPrice;
        
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
        
        if (!$conn->query($sql)) {
            throw new Exception('Gagal menyimpan data pembayaran: ' . $conn->error);
        }
        
        // Update pemesanan with no_pembayaran
        $conn->query("UPDATE pemesanan SET No_pembayaran = $noPembayaran WHERE No_pemesanan = $noPemesanan");
    }

    $conn->commit();
    echo json_encode(['success' => true, 'message' => 'Data berhasil disimpan', 'orderId' => $orderId]);

} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

$conn->close();
?>