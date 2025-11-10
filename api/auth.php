<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Credentials: true');

session_start();

include 'database.php';

$db = (new Database())->getConnection();
$method = $_SERVER['REQUEST_METHOD'];

// Manejar preflight requests
if ($method === 'OPTIONS') {
    http_response_code(200);
    exit();
}

try {
    switch ($method) {
        case 'POST':
            $action = $_GET['action'] ?? '';
            
            if ($action === 'register') {
                // Registro de usuario (solo pacientes pueden registrarse)
                $input = json_decode(file_get_contents('php://input'), true);
                
                // Validar campos requeridos básicos
                if (empty($input['email']) || empty($input['password']) || empty($input['nombre']) || 
                    empty($input['telefono'])) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'message' => 'Faltan campos requeridos: email, contraseña, nombre y teléfono']);
                    break;
                }
                
                // Validar campos adicionales si están en el formulario (pueden no existir en la BD aún)
                // fecha_nacimiento es requerido si el campo existe en la tabla
                $hasFechaNacimiento = false;
                try {
                    $checkFechaNac = $db->query("SHOW COLUMNS FROM usuarios LIKE 'fecha_nacimiento'");
                    $hasFechaNacimiento = $checkFechaNac->rowCount() > 0;
                } catch (Exception $e) {
                    // Campo no existe, continuar
                }
                
                if ($hasFechaNacimiento && empty($input['fecha_nacimiento'])) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'message' => 'La fecha de nacimiento es requerida']);
                    break;
                }
                
                // Verificar si el email ya existe
                $stmt = $db->prepare("SELECT id FROM usuarios WHERE email = ?");
                $stmt->execute([$input['email']]);
                if ($stmt->fetch()) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'message' => 'El email ya está registrado']);
                    break;
                }
                
                // Verificar si el RUT ya existe (si el campo existe en la tabla)
                try {
                    $checkRut = $db->query("SHOW COLUMNS FROM usuarios LIKE 'rut'");
                    if ($checkRut->rowCount() > 0 && !empty($input['rut'])) {
                        $stmt = $db->prepare("SELECT id FROM usuarios WHERE rut = ?");
                        $stmt->execute([$input['rut']]);
                        if ($stmt->fetch()) {
                            http_response_code(400);
                            echo json_encode(['success' => false, 'message' => 'El RUT ya está registrado']);
                            break;
                        }
                    }
                } catch (Exception $e) {
                    // Si no existe la columna, continuar sin validar RUT
                }
                
                // Hashear la contraseña
                $hashedPassword = password_hash($input['password'], PASSWORD_DEFAULT);
                
                // Verificar qué campos existen en la tabla
                $fields = ['email', 'password', 'nombre', 'telefono', 'rol'];
                $values = [$input['email'], $hashedPassword, $input['nombre'], $input['telefono'], 'paciente'];
                
                // Agregar campos opcionales si existen
                $optionalFields = ['rut', 'fecha_nacimiento', 'direccion', 'region', 'comuna'];
                foreach ($optionalFields as $field) {
                    try {
                        $checkField = $db->query("SHOW COLUMNS FROM usuarios LIKE '$field'");
                        if ($checkField->rowCount() > 0) {
                            $fields[] = $field;
                            // Para fecha_nacimiento, asegurarse de que tenga un valor válido
                            if ($field === 'fecha_nacimiento') {
                                $value = !empty($input[$field]) ? $input[$field] : null;
                                if ($value && $hasFechaNacimiento) {
                                    // Validar formato de fecha
                                    $date = DateTime::createFromFormat('Y-m-d', $value);
                                    if (!$date || $date->format('Y-m-d') !== $value) {
                                        error_log("Fecha de nacimiento inválida: " . $value);
                                        $value = null;
                                    }
                                }
                                $values[] = $value;
                                error_log("Agregando campo fecha_nacimiento: " . ($value ?? 'NULL'));
                            } else {
                                $values[] = $input[$field] ?? null;
                            }
                        } else {
                            error_log("Campo $field no existe en la tabla usuarios");
                        }
                    } catch (Exception $e) {
                        error_log("Error al verificar campo $field: " . $e->getMessage());
                        // Campo no existe, continuar
                    }
                }
                
                // Construir la consulta dinámicamente
                $fieldsStr = implode(', ', $fields);
                $placeholders = implode(', ', array_fill(0, count($values), '?'));
                
                // Insertar usuario (solo pacientes)
                $stmt = $db->prepare("INSERT INTO usuarios ($fieldsStr) VALUES ($placeholders)");
                $success = $stmt->execute($values);
                
                if ($success) {
                    // Iniciar sesión automáticamente
                    $userId = $db->lastInsertId();
                    
                    // Obtener todos los campos del usuario recién creado (incluyendo fecha_nacimiento si existe)
                    try {
                        $stmt = $db->prepare("SELECT id, email, nombre, telefono, rol, rut, fecha_nacimiento, direccion, region, comuna FROM usuarios WHERE id = ?");
                        $stmt->execute([$userId]);
                        $user = $stmt->fetch(PDO::FETCH_ASSOC);
                    } catch (Exception $e) {
                        // Si hay error (campos no existen), usar consulta básica
                        $stmt = $db->prepare("SELECT id, email, nombre, telefono, rol FROM usuarios WHERE id = ?");
                        $stmt->execute([$userId]);
                        $user = $stmt->fetch(PDO::FETCH_ASSOC);
                    }
                    
                    // Limpiar campos null del usuario
                    if ($user) {
                        foreach ($user as $key => $value) {
                            if ($value === null) {
                                unset($user[$key]);
                            }
                        }
                    }
                    
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['user_email'] = $user['email'];
                    $_SESSION['user_nombre'] = $user['nombre'];
                    $_SESSION['user_rol'] = $user['rol'];
                    
                    echo json_encode([
                        'success' => true,
                        'message' => 'Usuario registrado exitosamente',
                        'user' => $user
                    ]);
                } else {
                    http_response_code(500);
                    echo json_encode(['success' => false, 'message' => 'Error al registrar usuario']);
                }
                
            } elseif ($action === 'login') {
                // Inicio de sesión
                $input = json_decode(file_get_contents('php://input'), true);
                
                if (empty($input['email']) || empty($input['password'])) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'message' => 'Email y contraseña son requeridos']);
                    break;
                }
                
                // Buscar usuario
                $stmt = $db->prepare("SELECT id, email, password, nombre, telefono, rol, activo FROM usuarios WHERE email = ?");
                $stmt->execute([$input['email']]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$user || !password_verify($input['password'], $user['password'])) {
                    http_response_code(401);
                    echo json_encode(['success' => false, 'message' => 'Credenciales inválidas']);
                    break;
                }
                
                if (!$user['activo']) {
                    http_response_code(403);
                    echo json_encode(['success' => false, 'message' => 'Usuario inactivo']);
                    break;
                }
                
                // Iniciar sesión
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_email'] = $user['email'];
                $_SESSION['user_nombre'] = $user['nombre'];
                $_SESSION['user_rol'] = $user['rol'];
                
                unset($user['password']); // No enviar la contraseña
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Inicio de sesión exitoso',
                    'user' => $user
                ]);
                
            } elseif ($action === 'create_user') {
                // Crear usuario (solo para admins) - permite crear médicos y admins
                if (!isset($_SESSION['user_id']) || $_SESSION['user_rol'] !== 'admin') {
                    http_response_code(403);
                    echo json_encode(['success' => false, 'message' => 'No autorizado']);
                    break;
                }
                
                $input = json_decode(file_get_contents('php://input'), true);
                
                // Validar campos
                if (empty($input['email']) || empty($input['password']) || empty($input['nombre']) || empty($input['rol'])) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'message' => 'Faltan campos requeridos']);
                    break;
                }
                
                // Validar rol
                if (!in_array($input['rol'], ['paciente', 'medico', 'admin'])) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'message' => 'Rol inválido']);
                    break;
                }
                
                // Validar especialidad para médicos
                if ($input['rol'] === 'medico' && empty($input['especialidad'])) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'message' => 'La especialidad es requerida para médicos']);
                    break;
                }
                
                // Verificar si el email ya existe
                $stmt = $db->prepare("SELECT id FROM usuarios WHERE email = ?");
                $stmt->execute([$input['email']]);
                if ($stmt->fetch()) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'message' => 'El email ya está registrado']);
                    break;
                }
                
                // Hashear la contraseña
                $hashedPassword = password_hash($input['password'], PASSWORD_DEFAULT);
                
                // Verificar qué campos existen en la tabla
                $fields = ['email', 'password', 'nombre', 'telefono', 'rol'];
                $values = [$input['email'], $hashedPassword, $input['nombre'], $input['telefono'] ?? null, $input['rol']];
                
                // Agregar campos opcionales si existen
                $optionalFields = ['region', 'comuna'];
                foreach ($optionalFields as $field) {
                    try {
                        $checkField = $db->query("SHOW COLUMNS FROM usuarios LIKE '$field'");
                        if ($checkField->rowCount() > 0 && isset($input[$field]) && !empty($input[$field])) {
                            $fields[] = $field;
                            $values[] = $input[$field];
                        }
                    } catch (Exception $e) {
                        // Campo no existe, continuar
                    }
                }
                
                // Manejar especialidad por separado (obligatoria para médicos)
                if ($input['rol'] === 'medico' && isset($input['especialidad']) && !empty($input['especialidad'])) {
                    try {
                        $checkField = $db->query("SHOW COLUMNS FROM usuarios LIKE 'especialidad'");
                        if ($checkField->rowCount() > 0) {
                            $fields[] = 'especialidad';
                            $values[] = $input['especialidad'];
                        }
                    } catch (Exception $e) {
                        // Campo no existe, continuar
                    }
                }
                
                // Construir la consulta dinámicamente
                $fieldsStr = implode(', ', $fields);
                $placeholders = implode(', ', array_fill(0, count($values), '?'));
                
                // Insertar usuario
                $stmt = $db->prepare("INSERT INTO usuarios ($fieldsStr) VALUES ($placeholders)");
                $success = $stmt->execute($values);
                
                if ($success) {
                    $userId = $db->lastInsertId();
                    
                    // Verificar si existe la columna especialidad para incluirla en la consulta
                    $hasEspecialidad = false;
                    try {
                        $checkField = $db->query("SHOW COLUMNS FROM usuarios LIKE 'especialidad'");
                        $hasEspecialidad = $checkField->rowCount() > 0;
                    } catch (Exception $e) {
                        $hasEspecialidad = false;
                    }
                    
                    if ($hasEspecialidad) {
                        $stmt = $db->prepare("SELECT id, email, nombre, telefono, rol, IFNULL(especialidad, '') as especialidad FROM usuarios WHERE id = ?");
                    } else {
                        $stmt = $db->prepare("SELECT id, email, nombre, telefono, rol, '' as especialidad FROM usuarios WHERE id = ?");
                    }
                    $stmt->execute([$userId]);
                    $user = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    // Asegurar que especialidad esté presente y sea una cadena
                    if (!isset($user['especialidad']) || $user['especialidad'] === null) {
                        $user['especialidad'] = '';
                    }
                    // Convertir a cadena si no lo es
                    $user['especialidad'] = (string)$user['especialidad'];
                    
                    echo json_encode([
                        'success' => true,
                        'message' => 'Usuario creado exitosamente',
                        'user' => $user
                    ]);
                } else {
                    http_response_code(500);
                    echo json_encode(['success' => false, 'message' => 'Error al crear usuario']);
                }
                
            } elseif ($action === 'update_profile') {
                // Actualizar perfil del usuario autenticado
                if (!isset($_SESSION['user_id'])) {
                    http_response_code(401);
                    echo json_encode(['success' => false, 'message' => 'No autorizado']);
                    break;
                }
                
                $input = json_decode(file_get_contents('php://input'), true);
                
                // Validar que el usuario esté actualizando su propio perfil
                $userId = $_SESSION['user_id'];
                
                // Verificar si el email ya existe en otro usuario
                if (isset($input['email']) && !empty($input['email'])) {
                    $checkStmt = $db->prepare("SELECT id FROM usuarios WHERE email = ? AND id != ?");
                    $checkStmt->execute([$input['email'], $userId]);
                    if ($checkStmt->fetch()) {
                        http_response_code(400);
                        echo json_encode(['success' => false, 'message' => 'El email ya está en uso por otro usuario']);
                        break;
                    }
                }
                
                // Construir la consulta UPDATE dinámicamente
                $updateFields = [];
                $updateValues = [];
                
                // Campos permitidos para actualizar
                $allowedFields = ['nombre', 'email', 'telefono', 'direccion', 'region', 'comuna', 'fecha_nacimiento', 'rut'];
                
                foreach ($allowedFields as $field) {
                    if (isset($input[$field])) {
                        // Verificar si el campo existe en la tabla
                        try {
                            $checkField = $db->query("SHOW COLUMNS FROM usuarios LIKE '$field'");
                            if ($checkField->rowCount() > 0) {
                                $updateFields[] = "$field = ?";
                                $updateValues[] = $input[$field] !== '' ? $input[$field] : null;
                            }
                        } catch (Exception $e) {
                            // Campo no existe, continuar
                        }
                    }
                }
                
                if (empty($updateFields)) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'message' => 'No hay campos para actualizar']);
                    break;
                }
                
                // Agregar fecha de actualización si existe el campo
                try {
                    $checkUpdated = $db->query("SHOW COLUMNS FROM usuarios LIKE 'fecha_actualizacion'");
                    if ($checkUpdated->rowCount() > 0) {
                        $updateFields[] = "fecha_actualizacion = NOW()";
                    }
                } catch (Exception $e) {
                    // Campo no existe, continuar
                }
                
                $updateValues[] = $userId;
                $updateFieldsStr = implode(', ', $updateFields);
                
                $stmt = $db->prepare("UPDATE usuarios SET $updateFieldsStr WHERE id = ?");
                $success = $stmt->execute($updateValues);
                
                if ($success) {
                    // Obtener el usuario actualizado
                    $baseFields = ['id', 'email', 'nombre', 'telefono', 'rol'];
                    $optionalFields = ['rut', 'fecha_nacimiento', 'direccion', 'region', 'comuna', 'especialidad'];
                    
                    $fields = $baseFields;
                    foreach ($optionalFields as $field) {
                        try {
                            $checkField = $db->query("SHOW COLUMNS FROM usuarios LIKE '$field'");
                            if ($checkField->rowCount() > 0) {
                                $fields[] = $field;
                            }
                        } catch (Exception $e) {
                            // Campo no existe, continuar
                        }
                    }
                    
                    $fieldsStr = implode(', ', $fields);
                    $stmt = $db->prepare("SELECT $fieldsStr FROM usuarios WHERE id = ?");
                    $stmt->execute([$userId]);
                    $user = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    echo json_encode([
                        'success' => true,
                        'message' => 'Perfil actualizado exitosamente',
                        'user' => $user
                    ]);
                } else {
                    http_response_code(500);
                    echo json_encode(['success' => false, 'message' => 'Error al actualizar el perfil']);
                }
            } else {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Acción no válida']);
            }
            break;

        case 'GET':
            $action = $_GET['action'] ?? '';
            
            if ($action === 'session') {
                // Verificar sesión actual
                if (isset($_SESSION['user_id'])) {
                    // Construir consulta dinámicamente solo con campos que existen
                    $baseFields = ['id', 'email', 'nombre', 'telefono', 'rol'];
                    $optionalFields = ['rut', 'fecha_nacimiento', 'direccion', 'region', 'comuna', 'especialidad'];
                    
                    $fields = $baseFields;
                    foreach ($optionalFields as $field) {
                        try {
                            $checkField = $db->query("SHOW COLUMNS FROM usuarios LIKE '$field'");
                            if ($checkField->rowCount() > 0) {
                                $fields[] = $field;
                            }
                        } catch (Exception $e) {
                            // Campo no existe, continuar
                        }
                    }
                    
                    $fieldsStr = implode(', ', $fields);
                    $stmt = $db->prepare("SELECT $fieldsStr FROM usuarios WHERE id = ? AND activo = 1");
                    $stmt->execute([$_SESSION['user_id']]);
                    $user = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($user) {
                        // NO eliminar campos null - mantenerlos para que el frontend sepa que existen
                        // Solo eliminar si realmente son null y no queremos enviarlos
                        // Pero fecha_nacimiento puede ser null válidamente, así que la mantenemos
                        error_log("Session - Usuario recuperado: " . json_encode($user));
                        echo json_encode([
                            'success' => true,
                            'authenticated' => true,
                            'user' => $user
                        ]);
                    } else {
                        session_destroy();
                        echo json_encode([
                            'success' => true,
                            'authenticated' => false
                        ]);
                    }
                } else {
                    echo json_encode([
                        'success' => true,
                        'authenticated' => false
                    ]);
                }
            } elseif ($action === 'logout') {
                // Cerrar sesión
                session_destroy();
                echo json_encode([
                    'success' => true,
                    'message' => 'Sesión cerrada exitosamente'
                ]);
            } elseif ($action === 'users' && isset($_SESSION['user_id']) && $_SESSION['user_rol'] === 'admin') {
                // Listar usuarios (solo para admins)
                // Verificar si existe la columna especialidad de múltiples formas
                $hasEspecialidad = false;
                try {
                    // Método 1: SHOW COLUMNS
                    $checkStmt = $db->query("SHOW COLUMNS FROM usuarios LIKE 'especialidad'");
                    $hasEspecialidad = $checkStmt->rowCount() > 0;
                    
                    // Método 2: Si el método 1 falla, intentar con INFORMATION_SCHEMA
                    if (!$hasEspecialidad) {
                        $checkStmt2 = $db->query("SELECT COUNT(*) as cnt FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios' AND COLUMN_NAME = 'especialidad'");
                        $result = $checkStmt2->fetch(PDO::FETCH_ASSOC);
                        $hasEspecialidad = ($result && $result['cnt'] > 0);
                    }
                    
                    // Método 3: Intentar una consulta de prueba
                    if (!$hasEspecialidad) {
                        try {
                            $testStmt = $db->query("SELECT especialidad FROM usuarios LIMIT 1");
                            $hasEspecialidad = true; // Si no hay error, la columna existe
                        } catch (Exception $e) {
                            $hasEspecialidad = false;
                        }
                    }
                } catch (Exception $e) {
                    $hasEspecialidad = false;
                }
                
                // Siempre intentar incluir especialidad en la consulta
                // Si la columna no existe, MySQL dará error, pero lo manejaremos
                try {
                    $sql = "SELECT id, email, nombre, telefono, rol, especialidad, fecha_creacion, activo FROM usuarios ORDER BY fecha_creacion DESC";
                    $stmt = $db->query($sql);
                    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    $hasEspecialidad = true; // Si llegamos aquí, la columna existe
                } catch (Exception $e) {
                    // Si falla, la columna no existe, usar consulta sin especialidad
                    $sql = "SELECT id, email, nombre, telefono, rol, fecha_creacion, activo FROM usuarios ORDER BY fecha_creacion DESC";
                    $stmt = $db->query($sql);
                    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    $hasEspecialidad = false;
                }
                
                // Procesar cada usuario y asegurar que especialidad esté presente
                $processedUsers = [];
                foreach ($users as $user) {
                    // Crear un nuevo array con todos los campos necesarios
                    $processedUser = [
                        'id' => $user['id'],
                        'email' => $user['email'],
                        'nombre' => $user['nombre'],
                        'telefono' => isset($user['telefono']) ? $user['telefono'] : null,
                        'rol' => $user['rol'],
                        'fecha_creacion' => $user['fecha_creacion'],
                        'activo' => $user['activo'],
                        'especialidad' => null  // Inicializar siempre como null
                    ];
                    
                    // Si la columna existe y el usuario tiene el campo en el resultado
                    if ($hasEspecialidad && array_key_exists('especialidad', $user)) {
                        $especialidadValue = $user['especialidad'];
                        // Procesar el valor (puede ser null, string vacío, o string con valor)
                        if ($especialidadValue !== null && $especialidadValue !== '') {
                            $especialidadValue = trim((string)$especialidadValue);
                            if ($especialidadValue !== '' && $especialidadValue !== 'null') {
                                $processedUser['especialidad'] = $especialidadValue;
                            }
                        }
                    }
                    
                    $processedUsers[] = $processedUser;
                }
                
                $users = $processedUsers;
                
                echo json_encode([
                    'success' => true,
                    'users' => $users
                ], JSON_UNESCAPED_UNICODE);
            } else {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Acción no válida o no autorizada']);
            }
            break;

        default:
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error del servidor: ' . $e->getMessage()]);
}
?>

