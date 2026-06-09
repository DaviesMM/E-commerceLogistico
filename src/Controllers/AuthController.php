<?php
// src/Controllers/AuthController.php

require_once __DIR__ . '/../Models/Usuario.php';
require_once __DIR__ . '/../Services/JWTService.php';
require_once __DIR__ . '/../Services/SecurityService.php';
require_once __DIR__ . '/../Services/WhatsAppService.php';
require_once __DIR__ . '/../Models/Historial.php';
require_once __DIR__ . '/../Middleware/AuthMiddleware.php';
class AuthController {

   /**
     * Endpoint: POST /api/usuarios/registrar-personal
     * RF-4.2: Creación de personal por Administrador con Password Hash y OTP obligatorio
     */
    public function registrarPersonal() {
        // 🔒 Validar acceso estricto de Administrador
        $admin = AuthMiddleware::verificarAcceso(['admin']);
        $idAdmin = $admin['id_usuario'];
        
        $data = json_decode(file_get_contents("php://input"), true);
        
        // 📥 Captura y limpieza de campos requeridos
        $nombre = trim($data['nombre'] ?? '');
        $email = trim($data['email'] ?? '');
        $telefono = trim($data['telefono'] ?? '');
        $rol = trim($data['rol'] ?? ''); // admin, staff, delivery
        $passwordRaw = $data['password'] ?? ''; // Contraseña en texto plano temporal
        $canalEnvio = strtolower(trim($data['canal_envio'] ?? 'whatsapp'));

        // 🚨 VALIDACIÓN: Todos los campos clave son estrictamente requeridos
        if (empty($nombre) || empty($email) || empty($telefono) || empty($rol) || empty($passwordRaw)) {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "Faltan campos obligatorios (nombre, email, telefono, rol, password)."]);
            exit;
        }

        if (!in_array($rol, ['admin', 'staff', 'delivery'])) {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "El rol especificado no es válido."]);
            exit;
        }

        $db = Database::conectar();

        // 🔍 VALIDACIÓN PREVENTIVA: Evitar correos duplicados
        $sqlCheck = "SELECT COUNT(*) FROM usuarios WHERE email = :email";
        $stmtCheck = $db->prepare($sqlCheck);
        $stmtCheck->execute([':email' => $email]);
        if ((int)$stmtCheck->fetchColumn() > 0) {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "El correo electrónico ya está registrado."]);
            exit;
        }

        // 🔐 HASH DE CONTRASEÑA: Nunca almacenar en texto plano
        $passwordHash = password_hash($passwordRaw, PASSWORD_BCRYPT);

        // 🎲 Generar código OTP de 6 dígitos
        $otp = (string)rand(100000, 999999);
        $expiracion = date('Y-m-d H:i:s', strtotime('+15 minutes')); // Válido por 15 min

        // 📁 Valores por defecto para el perfil multimedia
        $fotoUrl = 'uploads/perfiles/default.png';

        // 📝 Inserción corregida con marcadores únicos para PDO
        $sql = "INSERT INTO usuarios (
                    nombre, email, telefono, rol, password, 
                    foto_perfil_url, foto_perfil, estado, activo, 
                    codigo_verificacion, codigo_seguridad_registro, 
                    fecha_expiracion_codigo, fecha_creacion
                ) VALUES (
                    :nombre, :email, :telefono, :rol, :password, 
                    :foto_url_1, :foto_url_2, 'inactivo', 0, 
                    :otp_1, :otp_2, 
                    :expiracion, NOW()
                )";
        
        $stmt = $db->prepare($sql);
        
        // 🎯 Cada marcador de arriba tiene ahora su equivalente exacto aquí abajo
        $exito = $stmt->execute([
            ':nombre'     => $nombre,
            ':email'      => $email,
            ':telefono'   => $telefono,
            ':rol'        => $rol,
            ':password'   => $passwordHash,
            ':foto_url_1' => $fotoUrl,
            ':foto_url_2' => $fotoUrl,
            ':otp_1'      => $otp,
            ':otp_2'      => $otp,
            ':expiracion' => $expiracion
        ]);

       if ($exito) {
            $mensaje = "🔐 *Activación de Cuenta Corporativa* 📦\n\n"
                     . "Hola " . $nombre . ", se ha creado tu acceso como *" . strtoupper($rol) . "*.\n\n"
                     . "Tu código de verificación obligatorio es: * " . $otp . " *\n\n"
                     . "Ingresa este código en la aplicación para activar tu cuenta. Expira en 15 minutos.";

            // 📱 Envío de WhatsApp protegido contra caídas o tokens inválidos
            if ($canalEnvio === 'whatsapp') {
                try {
                    WhatsAppService::enviar($telefono, $mensaje);
                } catch (Exception $e) {
                    error_log("⚠️ Advertencia: No se pudo enviar el OTP por WhatsApp: " . $e->getMessage());
                    // El flujo continúa aunque falle el mensaje flotante
                }
            }

            // 📝 REGISTRO EN AUDITORÍA (Ahora sí encontrará la clase)
            Historial::registrar($idAdmin, 'SEGURIDAD', 'ALTA_PERSONAL', "El administrador creó al usuario {$nombre} con rol {$rol} (Inactivo).");

            http_response_code(201);
            echo json_encode([
                "status" => "success",
                "message" => "Personal registrado exitosamente en estado inactivo. Código OTP generado (Verifica logs si falló el envío físico)."
            ]);
            exit; 
        } else {
            http_response_code(500);
            echo json_encode(["status" => "error", "message" => "Error interno al insertar el usuario en el servidor."]);
        }
    }

    /**
     * Endpoint: POST /api/usuarios/verificar-cuenta
     * El nuevo usuario introduce su código para pasar a 'activo'
     */
    public function verificarCuenta() {
        $data = json_decode(file_get_contents("php://input"), true);
        $email = trim($data['email'] ?? '');  // inserta el email
        $codigoInput = trim($data['codigo'] ?? ''); // inserta el codigo enviado via whatsapp

        $db = Database::conectar();
        $sql = "SELECT id_usuario, codigo_verificacion, fecha_expiracion_codigo FROM usuarios WHERE email = :email LIMIT 1";
        $stmt = $db->prepare($sql);
        $stmt->execute([':email' => $email]);
        $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$usuario || $usuario['codigo_verificacion'] !== $codigoInput) {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "El código de verificación es incorrecto."]);
            exit;
        }

        // Verificar si ya expiró
        if (strtotime($usuario['fecha_expiracion_codigo']) < time()) {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "El código ha expirado. Solicita uno nuevo."]);
            exit;
        }

        // 🎉 Código correcto: Activar cuenta y limpiar el token de un solo uso
        $sqlActivar = "UPDATE usuarios SET activo=1, estado = 'activo', codigo_verificacion = NULL, fecha_expiracion_codigo = NULL WHERE id_usuario = :id";
        $stmtActivar = $db->prepare($sqlActivar);
        $stmtActivar->execute([':id' => $usuario['id_usuario']]);

        echo json_encode([
            "status" => "success",
            "message" => "Cuenta verificada y activada con éxito. Ya puedes iniciar sesión."
        ]);
    }

    /**
     * Procesa el inicio de sesión seguro y responde en formato JSON
     */
    public function login() {
        // Validar que la petición sea estrictamente POST
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->responderJSON(["status" => "error", "message" => "Método no permitido."], 405);
        }

        // 🔥 ESCUDO CONTRA FUERZA BRUTA: Máximo 5 intentos de login por minuto por IP
        if (!SecurityService::verificarAbuso('auth/login', 5, 1)) {
            $this->responderJSON([
                "status" => "error",
                "message" => "Demasiados intentos de inicio de sesión. Por seguridad, tu IP ha sido bloqueada temporalmente por 5 minutos."
            ], 429);
        }

        // Capturar y limpiar datos (soporta x-www-form-urlencoded y form-data)
        $email = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
        $password = $_POST['password'] ?? '';

        if (empty($email) || empty($password)) {
            $this->responderJSON(["status" => "error", "message" => "Por favor, ingresa el correo y la contraseña."], 400);
        }

        // Consultar estrictamente al Modelo Usuario
        $usuario = Usuario::obtenerPorEmail($email);

        // Verificar existencia, estado activo y contraseña encriptada con BCRYPT
        if ($usuario && $usuario['activo'] == 1 && password_verify($password, $usuario['password'])) {
            
            // 🔥 SEGURIDAD AVANZADA: Generamos el Access Token (JWT) de corta duración (15 minutos)
            $accessTokenJWT = JWTService::generarToken(
                $usuario['id_usuario'], 
                $usuario['rol'], 
                $usuario['email']
            );

            // 🔥 GENERACIÓN DEL REFRESH TOKEN (Criptográficamente seguro)
            $refreshToken = bin2hex(random_bytes(40)); 
            $fechaExpiracion = date('Y-m-d H:i:s', strtotime('+7 days')); // Válido por 7 días en la calle

            // Guardamos el Refresh Token inicial en la BD usando el modelo
            $guardado = Usuario::guardarRefreshToken($usuario['id_usuario'], $refreshToken, $fechaExpiracion);

            if (!$guardado) {
                $this->responderJSON(["status" => "error", "message" => "Error interno al procesar el blindaje de seguridad."], 500);
            }

            // Devolvemos el par de llaves al Frontend
            $this->responderJSON([
                "status" => "success",
                "message" => "Autenticación exitosa.",
                "access_token" => $accessTokenJWT,   // 🚀 Caduca en 15 minutos
                "refresh_token" => $refreshToken,    // 🔄 Válido por 7 días para rotar
                "redireccion_sugerida" => "/" . $usuario['rol'] . "/dashboard",
                "usuario" => [
                    "nombre" => $usuario['nombre'],
                    "email" => $usuario['email']
                ]
            ], 200);

        } else {
            $this->responderJSON(["status" => "error", "message" => "Credenciales incorrectas o usuario inactivo."], 401);
        }
    }

    /**
     * 🔥 ENDPOINT DE SEGURIDAD: Intercambia un Refresh Token válido por un par nuevo (Rotación RTR)
     * Este método se ejecuta de forma silenciosa desde el frontend cada 14-15 minutos.
     */
    public function refresh() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->responderJSON(["status" => "error", "message" => "Método no permitido."], 405);
        }

        // Capturar el refresh token enviado por el cliente
        $refreshTokenCliente = filter_input(INPUT_POST, 'refresh_token', FILTER_DEFAULT);

        if (empty($refreshTokenCliente)) {
            $this->responderJSON(["status" => "error", "message" => "El campo refresh_token es requerido."], 400);
        }

        // 1. Validar el token actual contra el Modelo (Verifica existencia, expiración y usuario activo)
        $tokenBD = Usuario::validarRefreshToken($refreshTokenCliente);

        // Si no es válido o ya caducó, denegamos el acceso inmediatamente
        if (!$tokenBD) {
            $this->responderJSON([
                "status" => "error", 
                "message" => "Sesión expirada o token inválido. Por seguridad, debes iniciar sesión nuevamente."
            ], 403);
        }

        // 2. ¡Token válido! Iniciamos la Rotación de Tokens (RTR)
        $nuevoAccessToken = JWTService::generarToken(
            $tokenBD['id_usuario'], 
            $tokenBD['rol'], 
            $tokenBD['email']
        );

        // Generamos un NUEVO Refresh Token para reemplazar el usado
        $nuevoRefreshToken = bin2hex(random_bytes(40));
        $nuevaExpiracion = date('Y-m-d H:i:s', strtotime('+7 days'));

        // Guardamos el cambio de forma atómica/transaccional en el Modelo
        $rotacionExitosa = Usuario::rotarRefreshToken(
            $refreshTokenCliente, 
            $nuevoRefreshToken, 
            $nuevaExpiracion, 
            $tokenBD['id_usuario']
        );

        if (!$rotacionExitosa) {
            $this->responderJSON(["status" => "error", "message" => "Error interno al rotar las llaves de seguridad."], 500);
        }

        // Envíamos ambos tokens frescos de vuelta al cliente
        $this->responderJSON([
            "status" => "success",
            "message" => "Tokens renovados con éxito.",
            "access_token"  => $nuevoAccessToken,  // 🚀 Nuevos 15 minutos
            "refresh_token" => $nuevoRefreshToken  // 🔄 Nueva llave de refresco única
        ], 200);
    }

    /**
     * Cierra la sesión destruyendo el token de refresco del usuario
     */
    public function logout() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->responderJSON(["status" => "error", "message" => "Método no permitido."], 405);
        }

        $refreshToken = filter_input(INPUT_POST, 'refresh_token', FILTER_DEFAULT);

        if (!empty($refreshToken)) {
            // Eliminamos el token de la base de datos a través del modelo
            Usuario::eliminarRefreshTokenEspecifico($refreshToken);
        }

        // Destrucción total segura por si existe sesión tradicional híbrida
        $_SESSION = array();
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        
        $this->responderJSON([
            "status" => "success", 
            "message" => "Sesión cerrada correctamente."
        ], 200);
    }

    /**
     * Método auxiliar para estandarizar las respuestas JSON de la API
     */
    private function responderJSON(array $datos, int $codigoEstado = 200) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code($codigoEstado);
        echo json_encode($datos);
        exit;
    }
}