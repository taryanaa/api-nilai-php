<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

function loadEnv() {
    if (file_exists('.env')) {
        $lines = file('.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos($line, '=') !== false && strpos($line, '#') !== 0) {
                list($key, $value) = explode('=', $line, 2);
                $_ENV[trim($key)] = trim($value);
            }
        }
    }
}
loadEnv();


// Database configuration
$host = $_ENV['DB_HOST'] ?? 'localhost';
$port = $_ENV['DB_PORT'] ?? '8080';
$dbname = $_ENV['DB_NAME'] ?? 'railway';
$username = $_ENV['DB_USER'] ?? 'root';
$password = $_ENV['DB_PASS'] ?? '';

// Connect to database
try {
    $pdo = new PDO("mysql:host=$host;port=$port;dbname=$dbname", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database connection failed: ' . $e->getMessage()]);
    exit();
}

// Get request method and path
$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = rtrim($path, '/');

// Get JSON input for POST/PUT requests
$input = null;
if (in_array($method, ['POST', 'PUT'])) {
    $input = json_decode(file_get_contents('php://input'), true);
}

// Helper function to send JSON response
function sendResponse($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data);
    exit();
}

// ==================== ROUTES ====================

// 1. Home endpoint - GET /
if ($path === '' || $path === '/') {
    if ($method === 'GET') {
        sendResponse([
            'message' => '🎓 API Nilai PHP be rhasil berjalan!',
            'endpoints' => [
                'GET / - Info API',
                'POST /create-table - Buat tabel nilai',
                'GET /nilai - Lihat semua nilai',
                'POST /nilai - Tambah nilai baru',
                'PUT /nilai/{id} - Update nilai',
                'DELETE /nilai/{id} - Hapus nilai'
            ]
        ]);
    }
}

// 2. Create table - POST /create-table
if ($path === '/create-table') {
    if ($method === 'POST') {
        try {
            $sql = "CREATE TABLE IF NOT EXISTS nilai (
                id INT AUTO_INCREMENT PRIMARY KEY,
                nama VARCHAR(100) NOT NULL,
                nilai INT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )";
            
            $pdo->exec($sql);
            sendResponse([
                'success' => true,
                'message' => '✅ Tabel nilai berhasil dibuat!'
            ]);
        } catch(PDOException $e) {
            sendResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }
}

// 3. Get all nilai - GET /nilai
if ($path === '/nilai') {
    if ($method === 'GET') {
        try {
            $stmt = $pdo->query("SELECT id, nama, nilai, DATE_FORMAT(created_at, '%d-%m-%Y %H:%i') as tanggal FROM nilai ORDER BY created_at DESC");
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            sendResponse([
                'success' => true,
                'total' => count($data),
                'data' => $data
            ]);
        } catch(PDOException $e) {
            sendResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    // 4. Add new nilai - POST /nilai
    if ($method === 'POST') {
        // Validasi input
        if (!isset($input['nama']) || !isset($input['nilai'])) {
            sendResponse([
                'success' => false,
                'message' => 'Nama dan nilai wajib diisi!'
            ], 400);
        }
        
        if (!is_numeric($input['nilai']) || $input['nilai'] < 0 || $input['nilai'] > 100) {
            sendResponse([
                'success' => false,
                'message' => 'Nilai harus angka antara 0-100!'
            ], 400);
        }
        
        try {
            $stmt = $pdo->prepare("INSERT INTO nilai (nama, nilai) VALUES (?, ?)");
            $stmt->execute([$input['nama'], (int)$input['nilai']]);
            
            $id = $pdo->lastInsertId();
            $stmt = $pdo->prepare("SELECT * FROM nilai WHERE id = ?");
            $stmt->execute([$id]);
            $newData = $stmt->fetch(PDO::FETCH_ASSOC);
            
            sendResponse([
                'success' => true,
                'message' => '✅ Nilai berhasil ditambahkan!',
                'data' => $newData
            ], 201);
        } catch(PDOException $e) {
            sendResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }
}

// 5. Update/Delete specific nilai - PUT/DELETE /nilai/{id}
if (preg_match('/^\/nilai\/(\d+)$/', $path, $matches)) {
    $id = $matches[1];
    
    // Update nilai - PUT /nilai/{id}
    if ($method === 'PUT') {
        try {
            // Cek apakah data exist
            $stmt = $pdo->prepare("SELECT * FROM nilai WHERE id = ?");
            $stmt->execute([$id]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$existing) {
                sendResponse([
                    'success' => false,
                    'message' => 'Data nilai tidak ditemukan!'
                ], 404);
            }
            
            // Gunakan data lama jika tidak ada input baru
            $nama = isset($input['nama']) ? $input['nama'] : $existing['nama'];
            $nilai = isset($input['nilai']) ? (int)$input['nilai'] : $existing['nilai'];
            
            // Validasi nilai jika ada perubahan
            if (isset($input['nilai']) && (!is_numeric($input['nilai']) || $input['nilai'] < 0 || $input['nilai'] > 100)) {
                sendResponse([
                    'success' => false,
                    'message' => 'Nilai harus angka antara 0-100!'
                ], 400);
            }
            
            // Update database
            $stmt = $pdo->prepare("UPDATE nilai SET nama = ?, nilai = ? WHERE id = ?");
            $stmt->execute([$nama, $nilai, $id]);
            
            // Ambil data yang sudah diupdate
            $stmt = $pdo->prepare("SELECT * FROM nilai WHERE id = ?");
            $stmt->execute([$id]);
            $updated = $stmt->fetch(PDO::FETCH_ASSOC);
            
            sendResponse([
                'success' => true,
                'message' => '✅ Nilai berhasil diupdate!',
                'data' => $updated
            ]);
        } catch(PDOException $e) {
            sendResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    // Delete nilai - DELETE /nilai/{id}
    if ($method === 'DELETE') {
        try {
            // Cek apakah data exist
            $stmt = $pdo->prepare("SELECT * FROM nilai WHERE id = ?");
            $stmt->execute([$id]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$existing) {
                sendResponse([
                    'success' => false,
                    'message' => 'Data nilai tidak ditemukan!'
                ], 404);
            }
            
            // Hapus data
            $stmt = $pdo->prepare("DELETE FROM nilai WHERE id = ?");
            $stmt->execute([$id]);
            
            sendResponse([
                'success' => true,
                'message' => '✅ Nilai berhasil dihapus!',
                'deleted_data' => $existing
            ]);
        } catch(PDOException $e) {
            sendResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }
}

try {
    $pdo = new PDO("mysql:host=$host;port=$port;dbname=$dbname", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "Database connected successfully\n"; // untuk debugging
} catch(PDOException $e) {
    error_log("Database connection failed: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit();
}

// 404 - Route not found
sendResponse([
    'success' => false,
    'message' => 'Endpoint tidak ditemukan!'
], 404);
?>
