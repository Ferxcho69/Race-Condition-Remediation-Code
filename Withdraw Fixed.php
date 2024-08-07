<?php
// Configuración de la base de datos
$servername = "localhost";
$username = "goloza69";  
$password = "lacone";  
$dbname = "bank";

// Conectar a la base de datos
$conn = new mysqli($servername, $username, $password, $dbname);

// Verificar la conexión
if ($conn->connect_error) {
    die("Conexión fallida: " . $conn->connect_error);
}

$account_number = isset($_POST['account_number']) ? $_POST['account_number'] : null;
$withdrawal_amount = isset($_POST['withdrawal_amount']) ? $_POST['withdrawal_amount'] : null;
$num_threads = isset($_POST['num_threads']) ? $_POST['num_threads'] : 1;

if (empty($account_number) || empty($withdrawal_amount) || !is_numeric($withdrawal_amount) || $withdrawal_amount <= 0 || !is_numeric($num_threads) || $num_threads <= 0) {
    die("Datos de entrada no válidos.");
}

$withdrawal_amount = floatval($withdrawal_amount);
$num_threads = intval($num_threads);

function withdraw_money($conn, $account_number, $withdrawal_amount) {
    $lock_file = fopen("/tmp/account_{$account_number}.lock", "w");

    if (!$lock_file) {
        return "Error en la creación del archivo de bloqueo.";
    }

    if (!flock($lock_file, LOCK_EX)) {
        fclose($lock_file);
        return "Error al adquirir el bloqueo.";
    }

    try {
        $conn->begin_transaction();
        
        $sql = "SELECT balance FROM accounts WHERE account_number = ? FOR UPDATE";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new Exception("Error en la preparación de la consulta: " . $conn->error);
        }
        $stmt->bind_param("s", $account_number);
        $stmt->execute();
        $stmt->bind_result($balance);
        $stmt->fetch();
        $stmt->close();

        if ($balance === null) {
            throw new Exception("Cuenta no encontrada.");
        }

        if ($balance < $withdrawal_amount) {
            throw new Exception("Fondos insuficientes.");
        }

        $new_balance = $balance - $withdrawal_amount;
        $sql = "UPDATE accounts SET balance = ? WHERE account_number = ?";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new Exception("Error en la preparación de la consulta: " . $conn->error);
        }
        $stmt->bind_param("ds", $new_balance, $account_number);
        $stmt->execute();
        $stmt->close();

        $sql = "INSERT INTO logs (account_number, transaction_type, amount, transaction_time) VALUES (?, 'withdrawal', ?, NOW())";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new Exception("Error en la preparación de la consulta: " . $conn->error);
        }
        $stmt->bind_param("sd", $account_number, $withdrawal_amount);
        $stmt->execute();
        $stmt->close();

        $conn->commit();
        $result = "Retiro exitoso. Nuevo balance: $new_balance";
    } catch (Exception $e) {
        $conn->rollback();
        $result = "Error en la transacción: " . $e->getMessage();
    }

    flock($lock_file, LOCK_UN);
    fclose($lock_file);

    return $result;
}

$results = [];
for ($i = 0; $i < $num_threads; $i++) {
    $results[] = withdraw_money($conn, $account_number, $withdrawal_amount);
}

$conn->close();

echo json_encode($results);
?>
