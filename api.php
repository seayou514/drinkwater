<?php
ini_set('display_errors', 0);
error_reporting(E_ALL);
header('Content-Type: application/json');
session_start();

$db_host = 'localhost';
$db_user = 'root';
$db_pass = ''; 
$db_name = 'drinkwater';

try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 3 
    ]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'DB Error']);
    exit;
}

$action = $_GET['action'] ?? '';

function getLogicalDate() {
    $now = new DateTime();
    $H = (int)$now->format('H');
    $i = (int)$now->format('i');
    if ($H === 0 && $i === 0) { $now->modify('-1 day'); }
    return $now->format('Y-m-d');
}

function checkAuth() {
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }
    return $_SESSION['user_id'];
}

switch ($action) {
    case 'register':
        $input = json_decode(file_get_contents('php://input'), true);
        $username = trim($input['username'] ?? '');
        $password = trim($input['password'] ?? '');
        $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
        $stmt->execute([$username]);
        if ($stmt->fetch()) { echo json_encode(['success' => false, 'message' => '帳號已存在']); break; }
        $stmt = $pdo->prepare("INSERT INTO users (username, password) VALUES (?, ?)");
        $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT)]);
        $_SESSION['user_id'] = $pdo->lastInsertId();
        echo json_encode(['success' => true]);
        break;

    case 'login':
        $input = json_decode(file_get_contents('php://input'), true);
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->execute([$input['username'] ?? '']);
        $user = $stmt->fetch();
        if (!$user || !password_verify($input['password'] ?? '', $user['password'])) { echo json_encode(['success' => false]); break; }
        $_SESSION['user_id'] = $user['id'];
        echo json_encode(['success' => true, 'username' => $user['username']]);
        break;

    case 'get_user_data':
        $userId = checkAuth();
        $stmt = $pdo->prepare("SELECT username, weight FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        
        $logicalDate = getLogicalDate();
        $stmt = $pdo->prepare("SELECT amount FROM water_history WHERE user_id = ? AND log_date = ?");
        $stmt->execute([$userId, $logicalDate]);
        $todayLog = $stmt->fetch();
        
        $stmt = $pdo->prepare("SELECT log_date, amount FROM water_history WHERE user_id = ? AND log_date LIKE ?");
        $stmt->execute([$userId, date('Y-m', strtotime($logicalDate)) . '%']);
        $logs = $stmt->fetchAll();
        
        $monthHistory = [];
        foreach ($logs as $log) { $monthHistory[(int)date('d', strtotime($log['log_date']))] = (int)$log['amount']; }
        
        echo json_encode([
            'success' => true, 
            'weight' => (int)$user['weight'], 
            'currentAmount' => $todayLog ? (int)$todayLog['amount'] : 0, 
            'targetWater' => (int)$user['weight'] * 30,
            'monthHistory' => $monthHistory
        ]);
        break;

    case 'drink':
        $userId = checkAuth();
        $input = json_decode(file_get_contents('php://input'), true);
        $stmt = $pdo->prepare("INSERT INTO water_history (user_id, log_date, amount) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE amount = amount + VALUES(amount)");
        $stmt->execute([$userId, getLogicalDate(), (int)($input['amount'] ?? 0)]);
        echo json_encode(['success' => true]);
        break;

    // === 重置機制 ===
    case 'reset_daily':
        $pdo->prepare("DELETE FROM water_history WHERE user_id = ? AND log_date = ?")->execute([checkAuth(), getLogicalDate()]);
        echo json_encode(['success' => true]);
        break;

    case 'reset_weekly':
        $ts = strtotime(getLogicalDate());
        $pdo->prepare("DELETE FROM water_history WHERE user_id = ? AND log_date BETWEEN ? AND ?")
            ->execute([checkAuth(), date('Y-m-d', strtotime('monday this week', $ts)), date('Y-m-d', strtotime('sunday this week', $ts))]);
        echo json_encode(['success' => true]);
        break;

    case 'reset_monthly':
        $pdo->prepare("DELETE FROM water_history WHERE user_id = ? AND log_date LIKE ?")
            ->execute([checkAuth(), date('Y-m', strtotime(getLogicalDate())) . '%']);
        echo json_encode(['success' => true]);
        break;

    default: echo json_encode(['success' => false]); break;
}