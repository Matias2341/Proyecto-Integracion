<?php
// API para obtener médicos y verificar disponibilidad
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Credentials: true');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

session_start();
include 'database.php';

$db = (new Database())->getConnection();
$action = $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'por_especialidad':
            // Obtener médicos por especialidad
            $especialidad = $_GET['especialidad'] ?? '';
            
            if (empty($especialidad)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Especialidad requerida']);
                break;
            }
            
            // Mapear especialidad del formulario a la base de datos
            $especialidadMap = [
                'medicina-general' => 'Medicina General',
                'cardiologia' => 'Cardiología',
                'neurologia' => 'Neurología',
                'pediatria' => 'Pediatría',
                'ginecologia' => 'Ginecología',
                'dermatologia' => 'Dermatología',
                'oftalmologia' => 'Oftalmología',
                'ortopedia' => 'Ortopedia',
                'odontologia' => 'Odontología',
                'psiquiatria' => 'Psiquiatría',
                'endocrinologia' => 'Endocrinología',
                'urologia' => 'Urología',
                'otorrinolaringologia' => 'Otorrinolaringología',
                'gastroenterologia' => 'Gastroenterología',
                'neumologia' => 'Neumología',
                'reumatologia' => 'Reumatología',
                'hematologia' => 'Hematología',
                'oncologia' => 'Oncología'
            ];
            
            $especialidadDB = $especialidadMap[$especialidad] ?? $especialidad;
            
            // Verificar si existe columna especialidad
            $hasEspecialidad = false;
            try {
                $checkStmt = $db->query("SHOW COLUMNS FROM usuarios LIKE 'especialidad'");
                $hasEspecialidad = $checkStmt->rowCount() > 0;
            } catch (Exception $e) {
                $hasEspecialidad = false;
            }
            
            if ($hasEspecialidad) {
                $stmt = $db->prepare("SELECT id, nombre, email, telefono, especialidad 
                                    FROM usuarios 
                                    WHERE rol = 'medico' 
                                    AND activo = 1 
                                    AND especialidad = ? 
                                    ORDER BY nombre");
                $stmt->execute([$especialidadDB]);
            } else {
                // Si no existe la columna, devolver todos los médicos
                $stmt = $db->prepare("SELECT id, nombre, email, telefono 
                                    FROM usuarios 
                                    WHERE rol = 'medico' 
                                    AND activo = 1 
                                    ORDER BY nombre");
                $stmt->execute();
            }
            
            $medicos = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode($medicos);
            break;
            
        case 'horas_disponibles':
            // Obtener horas disponibles para un médico en una fecha específica
            $medicoId = $_GET['medico_id'] ?? '';
            $fecha = $_GET['fecha'] ?? '';
            
            if (empty($medicoId) || empty($fecha)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Médico y fecha requeridos']);
                break;
            }
            
            // Verificar si existe columna medico_id
            $hasMedicoId = false;
            try {
                $checkStmt = $db->query("SHOW COLUMNS FROM citas LIKE 'medico_id'");
                $hasMedicoId = $checkStmt->rowCount() > 0;
            } catch (Exception $e) {
                $hasMedicoId = false;
            }
            
            // Horas disponibles base (formato HH:MM)
            $horasDisponibles = ['08:00', '09:00', '10:00', '11:00', '12:00', '14:00', '15:00', '16:00', '17:00', '18:00'];
            
            if ($hasMedicoId) {
                // Obtener horas ocupadas - normalizar formato de hora
                $stmt = $db->prepare("SELECT TIME_FORMAT(hora, '%H:%i') as hora FROM citas WHERE medico_id = ? AND fecha = ? AND hora IS NOT NULL");
                $stmt->execute([$medicoId, $fecha]);
                $horasOcupadas = $stmt->fetchAll(PDO::FETCH_COLUMN);
                
                // Normalizar horas ocupadas a formato HH:MM
                $horasOcupadasNormalizadas = [];
                foreach ($horasOcupadas as $hora) {
                    // Convertir cualquier formato de hora a HH:MM
                    if (strlen($hora) >= 5) {
                        $horaNormalizada = substr($hora, 0, 5); // Tomar solo HH:MM
                        $horasOcupadasNormalizadas[] = $horaNormalizada;
                    }
                }
                
                // Filtrar horas disponibles - excluir las ocupadas
                $horasDisponibles = array_filter($horasDisponibles, function($hora) use ($horasOcupadasNormalizadas) {
                    return !in_array($hora, $horasOcupadasNormalizadas);
                });
                
                error_log("Horas disponibles - Médico: $medicoId, Fecha: $fecha, Ocupadas: " . json_encode($horasOcupadasNormalizadas) . ", Disponibles: " . json_encode($horasDisponibles));
            }
            
            // Devolver como array indexado numéricamente
            echo json_encode(array_values($horasDisponibles));
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Acción no válida']);
    }
} catch (Exception $e) {
    error_log("Error en medicos.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error del servidor']);
}
?>

