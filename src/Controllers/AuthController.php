<?php
// src/Controllers/AuthController.php

require_once __DIR__ . '/../Models/Usuario.php';
require_once __DIR__ . '/../Services/JWTService.php';
require_once __DIR__ . '/../Services/SecurityService.php';


class AuthController {

    /**
     * Procesa el inicio de sesión y responde en formato JSON
     */
   public function login() {
        // Validar que la petición sea estrictamente POST
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            // 🔥 ESCUDO CONTRA FUERZA BRUTA: Máximo 5 intentos de login por minuto por IP
                if (!SecurityService::verificarAbuso('auth/login', 5, 1)) {
                    http_response_code(429); // 429 Too Many Requests
                    echo json_encode([
                        "status" => "error",
                        "message" => "Demasiados intentos de inicio de sesión. Por seguridad, tu IP ha sido bloqueada temporalmente por 5 minutos."
                    ]);
                    return;
                }
            // Capturar y limpiar datos (soporta x-www-form-urlencoded y form-data)
            $email = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
            $password = $_POST['password'] ?? '';

            if (empty($email) || empty($password)) {
                http_response_code(400); // Bad Request
                echo json_encode([
                    "status" => "error", 
                    "message" => "Por favor, ingresa el correo y la contraseña."
                ]);
                return;
            }

            // Consultar estrictamente al Modelo
            $usuario = Usuario::obtenerPorEmail($email);

            // Verificar existencia y contraseña encriptada
            if ($usuario && password_verify($password, $usuario['password'])) {
                
                // 🔥 SEGURIDAD AVANZADA (Fase A): Generamos el Access Token (JWT) de corta duración (15 minutos)
                // Nota: Asegúrate de que tu JWTService use "time() + 900" en el campo 'exp' del payload interno.
                $accessTokenJWT = JWTService::generarToken(
                    $usuario['id_usuario'], 
                    $usuario['rol'], 
                    $usuario['email']
                );

                // 🔥 GENERACIÓN DEL REFRESH TOKEN (Criptográficamente seguro y único)
                $refreshToken = bin2hex(random_bytes(40)); 
                $fechaExpiracion = date('Y-m-d H:i:s', strtotime('+7 days')); // Válido por 7 días en la calle

                try {
                    // Guardamos el Refresh Token directamente vinculándolo al ID del usuario
                    $db = Database::conectar();
                    $sqlToken = "INSERT INTO usuarios_refresh_tokens (id_usuario, token_uuid, fecha_expiracion) 
                                 VALUES (:id_usuario, :token, :expiracion)";
                    $stmtToken = $db->prepare($sqlToken);
                    $stmtToken->execute([
                        ':id_usuario' => $usuario['id_usuario'],
                        ':token'      => $refreshToken,
                        ':expiracion' => $fechaExpiracion
                    ]);
                } catch (PDOException $e) {
                    error_log("Error al guardar el refresh_token: " . $e->getMessage());
                    http_response_code(500);
                    echo json_encode(["status" => "error", "message" => "Error interno al procesar el blindaje de seguridad."]);
                    return;
                }

                // Devolvemos las dos llaves al Frontend
                http_response_code(200); // OK
                echo json_encode([
                    "status" => "success",
                    "message" => "Autenticación exitosa.",
                    "access_token" => $accessTokenJWT, // 🚀 Caduca en 15 minutos
                    "refresh_token" => $refreshToken,   // 🔄 Válido por 7 días para renovar el de arriba
                    "redireccion_sugerida" => "/" . $usuario['rol'] . "/dashboard",
                    "usuario" => [
                        "nombre" => $usuario['nombre'],
                        "rol" => $usuario['rol']
                    ]
                ]);
                return;
                
            } else {
                http_response_code(401); // Unauthorized
                echo json_encode([
                    "status" => "error", 
                    "message" => "Credenciales incorrectas o usuario inactivo."
                ]);
                return;
            }
        }
    }
 /**
  * Fase B (opcional): Endpoint para renovar el Access Token usando el Refresh Token
  * Nota: Este método se puede implementar en el futuro para mejorar la experiencia del usuario
 * sin necesidad de reingresar credenciales cada 15 minutos.
  */
/**
     * 🔥 ENDPOINT DE SEGURIDAD: Intercambia un Refresh Token válido por un Access Token fresco (JWT)
     */
    public function refresh() {
        // Validar que la petición sea estrictamente POST
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            
            // Capturar el refresh token enviado por el frontend o Thunder Client
            $refreshTokenCliente = filter_input(INPUT_POST, 'refresh_token', FILTER_DEFAULT);

            if (empty($refreshTokenCliente)) {
                http_response_code(400); // Bad Request
                echo json_encode(["status" => "error", "message" => "El campo refresh_token es requerido."]);
                return;
            }

            try {
                // 1. Buscar en la base de datos si el token existe y si aún no ha caducado
                $db = Database::conectar();
                $sql = "SELECT r.*, u.email, u.rol 
                        FROM usuarios_refresh_tokens r
                        JOIN usuarios u ON r.id_usuario = u.id_usuario
                        WHERE r.token_uuid = :token AND r.fecha_expiracion > NOW()
                        LIMIT 1";
                
                $stmt = $db->prepare($sql);
                $stmt->execute([':token' => $refreshTokenCliente]);
                $tokenBD = $stmt->fetch(PDO::FETCH_ASSOC);

                // Si no existe o su fecha_expiracion ya pasó, denegamos el acceso de inmediato
                if (!$tokenBD) {
                    http_response_code(403); // Forbidden
                    echo json_encode([
                        "status" => "error", 
                        "message" => "Sesión expirada o token inválido. Por seguridad, debes iniciar sesión nuevamente."
                    ]);
                    return;
                }

                // 2. ¡Token de refresco válido! Emitimos un nuevo Access Token (JWT de otros 15 minutos)
                $nuevoAccessToken = JWTService::generarToken(
                    $tokenBD['id_usuario'], 
                    $tokenBD['rol'], 
                    $tokenBD['email']
                );

                // Devolvemos la nueva llave de acceso limpia
                http_response_code(200); // OK
                echo json_encode([
                    "status" => "success",
                    "message" => "Token de acceso renovado con éxito.",
                    "access_token" => $nuevoAccessToken
                ]);
                return;

            } catch (PDOException $e) {
                error_log("Error en AuthController::refresh -> " . $e->getMessage());
                http_response_code(500);
                echo json_encode(["status" => "error", "message" => "Error interno al procesar la renovación."]);
                return;
            }
        }
    }
    /**
     * Cierra la sesión destruyendo las cookies del cliente de API
     */
    public function logout() {
        $_SESSION = array();
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
        session_destroy();
        
        http_response_code(200);
        echo json_encode([
            "status" => "success", 
            "message" => "Sesión Cerrada correctamente."
        ]);
        exit;
    }
}