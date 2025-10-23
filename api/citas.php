<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type');

include 'database.php';

$db = (new Database())->getConnection();
$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($method) {
        case 'GET':
            $stmt = $db->query("SELECT * FROM citas ORDER BY fecha DESC, hora DESC");
            echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
            break;

        case 'POST':
            $input = json_decode(file_get_contents('php://input'), true);
            
            $stmt = $db->prepare("INSERT INTO citas SET 
                nombre = ?, telefono = ?, email = ?, edad_paciente = ?, especialidad = ?,
                fecha = ?, hora = ?, tipo_consulta = ?, motivo = ?, confirmacion = ?, owner_key = ?");
            
            $success = $stmt->execute([
                $input['nombre'], $input['telefono'], $input['email'], $input['edadPaciente'],
                $input['especialidad'], $input['fecha'], $input['hora'], $input['tipoConsulta'],
                $input['motivo'], $input['confirmacion'], $input['ownerKey']
            ]);
            
            echo json_encode(['success' => $success]);
            break;

        case 'PUT':
            $input = json_decode(file_get_contents('php://input'), true);
            
            $stmt = $db->prepare("UPDATE citas SET 
                nombre = ?, telefono = ?, especialidad = ?, fecha = ?, hora = ?, motivo = ?
                WHERE confirmacion = ?");
            
            $success = $stmt->execute([
                $input['nombre'], $input['telefono'], $input['especialidad'],
                $input['fecha'], $input['hora'], $input['motivo'], $input['confirmacion']
            ]);
            
            echo json_encode(['success' => $success]);
            break;

        case 'DELETE':
            $input = json_decode(file_get_contents('php://input'), true);
            $stmt = $db->prepare("DELETE FROM citas WHERE confirmacion = ?");
            $success = $stmt->execute([$input['confirmacion']]);
            echo json_encode(['success' => $success]);
            break;

        default:
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error del servidor']);
}
?>