<?php
// fetch_data.php - File untuk mengambil data dari database
require_once 'config.php';

$action = isset($_GET['action']) ? $_GET['action'] : '';

switch ($action) {
    case 'frames':
        $sql = "SELECT Kode_frame as id, Warna_frame as name, '0' as price, 'Pria' as gender FROM frame";
        $result = $conn->query($sql);
        $data = [];
        while ($row = $result->fetch_assoc()) {
            $data[] = $row;
        }
        echo json_encode($data);
        break;
        
    case 'lenses':
        $sql = "SELECT Kode_lensa as id, Jenis_lensa as name, '0' as price FROM lensa";
        $result = $conn->query($sql);
        $data = [];
        while ($row = $result->fetch_assoc()) {
            $data[] = $row;
        }
        echo json_encode($data);
        break;
        
    case 'orders':
        $sql = "SELECT p.*, pel.Nama_pelanggan, pe.Tgl_pemeriksaan 
                FROM pemesanan p
                JOIN pelanggan pel ON p.Id_pelanggan = pel.Id_pelanggan
                JOIN pemeriksaan pe ON p.No_pemeriksaan = pe.No_pemeriksaan
                ORDER BY p.No_pemesanan DESC";
        $result = $conn->query($sql);
        $data = [];
        while ($row = $result->fetch_assoc()) {
            $data[] = $row;
        }
        echo json_encode($data);
        break;
        
    default:
        echo json_encode(['error' => 'Aksi tidak ditemukan']);
        break;
}

$conn->close();
?>