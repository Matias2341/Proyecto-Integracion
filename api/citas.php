<?php
// Establecer headers primero
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Credentials: true');

// Manejar preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

session_start();

include 'database.php';

$db = (new Database())->getConnection();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Función para obtener información del usuario desde la sesión
function getSessionUser() {
    if (isset($_SESSION['user_id'])) {
        return [
            'id' => $_SESSION['user_id'],
            'rol' => $_SESSION['user_rol'] ?? 'paciente',
            'email' => $_SESSION['user_email'] ?? null
        ];
    }
    return null;
}

try {
    switch ($method) {
        case 'GET':
            $user = getSessionUser();
            
            // Log para depuración
            error_log("GET citas - Usuario: " . ($user ? json_encode($user) : 'null'));
            
            if (!$user) {
                // Usuario no autenticado - no puede ver citas
                error_log("GET citas - Usuario no autenticado");
                http_response_code(401);
                echo json_encode(['success' => false, 'message' => 'No autorizado']);
                break;
            }
            
            try {
            // Verificar si la tabla tiene el campo usuario_id
            $hasUsuarioId = false;
            try {
                $checkStmt = $db->query("SHOW COLUMNS FROM citas LIKE 'usuario_id'");
                $hasUsuarioId = $checkStmt->rowCount() > 0;
            } catch (Exception $e) {
                $hasUsuarioId = false;
            }
            
                $citas = [];
                
                // Verificar si existe campo medico_id
                $hasMedicoId = false;
                try {
                    $checkStmt = $db->query("SHOW COLUMNS FROM citas LIKE 'medico_id'");
                    $hasMedicoId = $checkStmt->rowCount() > 0;
                } catch (Exception $e) {
                    $hasMedicoId = false;
                }
                
                // Usuario autenticado
                if ($user['rol'] === 'admin') {
                    // Admin ve todas las citas con información del médico
                    error_log("GET citas - Rol: admin - Obteniendo todas las citas");
                    if ($hasMedicoId) {
                        $stmt = $db->query("SELECT c.*, u.nombre as medico_nombre, u.email as medico_email 
                                          FROM citas c 
                                          LEFT JOIN usuarios u ON c.medico_id = u.id 
                                          ORDER BY c.fecha DESC, c.hora DESC");
                    } else {
                        $stmt = $db->query("SELECT * FROM citas ORDER BY fecha DESC, hora DESC");
                    }
                    $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    $citas = $result ? $result : [];
                    error_log("Admin - Citas encontradas: " . count($citas));
                } elseif ($user['rol'] === 'medico') {
                    // Médicos solo ven sus propias citas
                    $medicoId = intval($user['id']); // Asegurar que sea entero
                    error_log("GET citas - Rol: medico - ID: " . $medicoId);
                    
                    if ($hasMedicoId) {
                        // Filtrar por medico_id - usar comparación directa con entero
                        $stmt = $db->prepare("SELECT * FROM citas WHERE medico_id = ? ORDER BY fecha DESC, hora DESC");
                        $stmt->execute([$medicoId]);
                        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
                        $citas = $result ? $result : [];
                        error_log("Medico - Citas encontradas: " . count($citas));
                        
                        // Debug adicional: verificar todas las citas y sus medico_id
                        $debugStmt = $db->query("SELECT id, nombre, medico_id, fecha, hora FROM citas LIMIT 10");
                        $debugTodas = $debugStmt->fetchAll(PDO::FETCH_ASSOC);
                        error_log("Debug - Primeras 10 citas: " . json_encode($debugTodas));
                        
                        // Verificar específicamente las citas de este médico
                        $debugStmt = $db->prepare("SELECT id, nombre, medico_id, fecha, hora FROM citas WHERE medico_id = ?");
                        $debugStmt->execute([$medicoId]);
                        $debugCitas = $debugStmt->fetchAll(PDO::FETCH_ASSOC);
                        error_log("Debug médico - Citas con medico_id = " . $medicoId . ": " . json_encode($debugCitas));
                    } else {
                        // Si no existe medico_id, devolver todas (compatibilidad)
                    $stmt = $db->query("SELECT * FROM citas ORDER BY fecha DESC, hora DESC");
                        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
                        $citas = $result ? $result : [];
                        error_log("Medico - Sin campo medico_id, devolviendo todas las citas: " . count($citas));
                    }
                } else {
                    // Pacientes solo ven sus propias citas
                    error_log("GET citas - Rol: paciente - ID: " . $user['id'] . " - Email: " . ($user['email'] ?? 'null'));
                    if ($hasUsuarioId) {
                        // Si existe usuario_id, filtrar por él
                        $stmt = $db->prepare("SELECT * FROM citas WHERE usuario_id = ? ORDER BY fecha DESC, hora DESC");
                        $stmt->execute([$user['id']]);
                        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
                        $citas = $result ? $result : [];
                        error_log("Paciente (usuario_id) - Citas encontradas: " . count($citas));
                    } else {
                        // Si no existe usuario_id, filtrar por email o owner_key
                        if ($user['email']) {
                        $stmt = $db->prepare("SELECT * FROM citas WHERE email = ? OR owner_key = ? ORDER BY fecha DESC, hora DESC");
                        $stmt->execute([$user['email'], $user['email']]);
                            $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
                            $citas = $result ? $result : [];
                            error_log("Paciente (email) - Citas encontradas: " . count($citas));
                        } else {
                            $citas = [];
                            error_log("Paciente - Sin email, sin citas");
                        }
                    }
                }
                
                // Siempre devolver un array, incluso si está vacío
                error_log("Total de citas a devolver: " . count($citas));
                echo json_encode($citas, JSON_UNESCAPED_UNICODE);
                
            } catch (PDOException $e) {
                error_log("Error PDO al obtener citas: " . $e->getMessage());
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'Error al obtener citas', 'error' => $e->getMessage()]);
            } catch (Exception $e) {
                error_log("Error general al obtener citas: " . $e->getMessage());
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'Error al obtener citas']);
            }
            break;

        case 'POST':
            $user = getSessionUser();
            if (!$user) {
                http_response_code(401);
                echo json_encode(['success' => false, 'message' => 'No autorizado']);
                break;
            }
            
            $input = json_decode(file_get_contents('php://input'), true);
            
            // Verificar campos disponibles
            $hasUsuarioId = false;
            $hasMedicoId = false;
            try {
                $checkStmt = $db->query("SHOW COLUMNS FROM citas LIKE 'usuario_id'");
                $hasUsuarioId = $checkStmt->rowCount() > 0;
                
                $checkStmt = $db->query("SHOW COLUMNS FROM citas LIKE 'medico_id'");
                $hasMedicoId = $checkStmt->rowCount() > 0;
            } catch (Exception $e) {
                $hasUsuarioId = false;
                $hasMedicoId = false;
            }
            
            // Validar que no haya cita duplicada (mismo médico, fecha, hora)
            if ($hasMedicoId && !empty($input['medico_id'])) {
                $checkDuplicado = $db->prepare("SELECT id FROM citas WHERE medico_id = ? AND fecha = ? AND hora = ?");
                $checkDuplicado->execute([$input['medico_id'], $input['fecha'], $input['hora']]);
                if ($checkDuplicado->fetch()) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'message' => 'Ya existe una cita para este médico en la fecha y hora seleccionada']);
                    break;
                }
            }
            
            // Construir query dinámicamente según campos disponibles
            $fields = ['nombre', 'telefono', 'email', 'edad_paciente', 'especialidad', 'fecha', 'hora', 'tipo_consulta', 'motivo', 'confirmacion', 'owner_key'];
            $values = [
                $input['nombre'], 
                $input['telefono'] ?? null, 
                $input['email'] ?? null, 
                $input['edadPaciente'] ?? null,
                $input['especialidad'], 
                $input['fecha'], 
                $input['hora'], 
                $input['tipoConsulta'] ?? 'presencial',
                $input['motivo'] ?? null, 
                $input['confirmacion'], 
                $input['ownerKey'] ?? null
            ];
            
            if ($hasMedicoId && !empty($input['medico_id'])) {
                $fields[] = 'medico_id';
                $values[] = intval($input['medico_id']); // Asegurar que sea entero
                error_log("Creando cita con medico_id: " . intval($input['medico_id']));
            } else {
                error_log("Advertencia: No se está asignando medico_id a la cita. hasMedicoId: " . ($hasMedicoId ? 'true' : 'false') . ", medico_id recibido: " . ($input['medico_id'] ?? 'null'));
            }
            
            if ($hasUsuarioId) {
                $fields[] = 'usuario_id';
                $values[] = $user['id'];
            }
            
            $fieldsStr = implode(', ', $fields);
            $placeholders = implode(', ', array_fill(0, count($values), '?'));
            
            $stmt = $db->prepare("INSERT INTO citas ($fieldsStr) VALUES ($placeholders)");
            $success = $stmt->execute($values);
            
            if ($success) {
                echo json_encode(['success' => true, 'message' => 'Cita creada exitosamente']);
            } else {
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'Error al crear la cita']);
            }
            break;

        case 'PUT':
            $user = getSessionUser();
            if (!$user) {
                http_response_code(401);
                echo json_encode(['success' => false, 'message' => 'No autorizado']);
                break;
            }
            
            $input = json_decode(file_get_contents('php://input'), true);
            
            // Verificar permisos: solo el dueño de la cita, admin o médico pueden editar
            $stmt = $db->prepare("SELECT usuario_id, email, owner_key FROM citas WHERE confirmacion = ?");
            $stmt->execute([$input['confirmacion']]);
            $cita = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$cita) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Cita no encontrada']);
                break;
            }
            
            $canEdit = false;
            if ($user['rol'] === 'admin' || $user['rol'] === 'medico') {
                $canEdit = true;
            } elseif ($cita['usuario_id'] == $user['id']) {
                $canEdit = true;
            } elseif ($cita['email'] === $user['email'] || $cita['owner_key'] === $user['email']) {
                $canEdit = true;
            }
            
            if (!$canEdit) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'No tienes permiso para editar esta cita']);
                break;
            }
            
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
            $user = getSessionUser();
            if (!$user) {
                http_response_code(401);
                echo json_encode(['success' => false, 'message' => 'No autorizado']);
                break;
            }
            
            $input = json_decode(file_get_contents('php://input'), true);
            
            // Verificar permisos: solo el dueño de la cita, admin o médico pueden eliminar
            $stmt = $db->prepare("SELECT usuario_id, email, owner_key FROM citas WHERE confirmacion = ?");
            $stmt->execute([$input['confirmacion']]);
            $cita = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$cita) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Cita no encontrada']);
                break;
            }
            
            $canDelete = false;
            if ($user['rol'] === 'admin' || $user['rol'] === 'medico') {
                $canDelete = true;
            } elseif ($cita['usuario_id'] == $user['id']) {
                $canDelete = true;
            } elseif ($cita['email'] === $user['email'] || $cita['owner_key'] === $user['email']) {
                $canDelete = true;
            }
            
            if (!$canDelete) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'No tienes permiso para eliminar esta cita']);
                break;
            }
            
            $stmt = $db->prepare("DELETE FROM citas WHERE confirmacion = ?");
            $success = $stmt->execute([$input['confirmacion']]);
            echo json_encode(['success' => $success]);
            break;

        default:
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    }
} catch (PDOException $e) {
    error_log("Error PDO en citas.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error del servidor', 'error' => $e->getMessage()]);
} catch (Exception $e) {
    error_log("Error general en citas.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error del servidor']);
}
?>