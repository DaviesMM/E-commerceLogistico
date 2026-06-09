<?php
// src/Controllers/UsuarioController.php
require_once __DIR__ . '/../Models/Usuario.php';
require_once __DIR__ . '/../Models/Historial.php';
require_once __DIR__ . '/../Middleware/AuthMiddleware.php';
require_once __DIR__ . '/../Services/Uploader.php';

class UsuarioController {

    /**
     * Valida de manera estricta mediante JWT que el usuario sea Administrador
     */
    private function verificarAccesoAdmin() {
        // 1. Forzamos la validación del Token JWT en la cabecera HTTP
        AuthMiddleware::verificarAcceso();  

        // 2. Extraemos los datos inyectados globalmente por el guardián
        $usuario = $GLOBALS['usuario_autenticado'] ?? null;

        if (!$usuario || $usuario['usuario_rol'] !== 'admin') {
            http_response_code(403); // Forbidden
            echo json_encode(["status" => "error", "message" => "Acceso denegado. Se requieren permisos de Administrador."]);
            exit; 
        }

        return $usuario;
    }

    /**
     * Lista todos los usuarios (Solo para Admin)
     */
    public function listar() {
        $this->verificarAccesoAdmin();

        $usuarios = Usuario::listarTodos();
        echo json_encode(["status" => "success", "data" => $usuarios]);
    }
     
    /**
     * Crea un nuevo usuario (Staff o Delivery) desde el panel del Admin
     */
   

    public function registrar() {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $nombre = filter_input(INPUT_POST, 'nombre', FILTER_DEFAULT);
        $email = filter_input(INPUT_POST, 'email', FILTER_VALIDATE_EMAIL);
        $password = filter_input(INPUT_POST, 'password', FILTER_DEFAULT);
        $rol = filter_input(INPUT_POST, 'rol', FILTER_DEFAULT) ?: 'cliente';

        // Por defecto, apuntamos al logo de la aplicación
        $foto_perfil = 'uploads/perfiles/default-logo.png';

        // Si el usuario decide subir una foto de perfil personalizada en el registro
        if (isset($_FILES['foto_perfil']) && $_FILES['foto_perfil']['error'] === UPLOAD_ERR_OK) {
            $extension = pathinfo($_FILES['foto_perfil']['name'], PATHINFO_EXTENSION);
            $nuevoNombre = 'USER_' . uniqid() . '.' . $extension;
            if (move_uploaded_file($_FILES['foto_perfil']['tmp_name'], __DIR__ . '/../../public/uploads/perfiles/' . $nuevoNombre)) {
                $foto_perfil = 'uploads/perfiles/' . $nuevoNombre;
            }
        }

        try {
            $db = Database::conectar();
            $sql = "INSERT INTO usuarios (nombre, email, password, rol, foto_perfil) 
                    VALUES (:nombre, :email, :password, :rol, :foto)";
            
            $stmt = $db->prepare($sql);
            $stmt->execute([
                ':nombre'   => $nombre,
                ':email'    => $email,
                ':password' => password_hash($password, PASSWORD_BCRYPT),
                ':rol'      => $rol,
                ':foto'     => $foto_perfil
            ]);

            http_response_code(201);
            echo json_encode(["status" => "success", "message" => "Usuario creado exitosamente."]);
        } catch (PDOException $e) {
            error_log("Error al crear usuario: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(["status" => "error", "message" => "Error interno al guardar el usuario."]);
        }
    }
}

    /**
     * [ADMIN] Ver el libro de actas digital de la empresa
     */
    public function verAuditoriaSistema() {
        $this->verificarAccesoAdmin();

        $logs = Historial::listarTodo();
        echo json_encode([
            "status" => "success",
            "total_registros" => count($logs),
            "historial" => $logs
        ]);
    }
    // Dentro de src/Controllers/UsuarioController.php

public function obtenerDetalle($id) {
    AuthMiddleware::verificarAcceso(); // Validar token

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        if (!$id) {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "ID de usuario requerido."]);
            return;
        }

        $usuario = Usuario::obtenerPorId($id);

        if ($usuario) {
            // Ocultamos la contraseña por seguridad antes de escupir el JSON
            unset($usuario['password']);
            
            http_response_code(200);
            echo json_encode(["status" => "success", "data" => $usuario]);
        } else {
            http_response_code(404);
            echo json_encode(["status" => "error", "message" => "Usuario no encontrado."]);
        }
    }
}
    /**
     * Modifica los datos de un usuario
     */
    public function modificar() {
        $adminLogueado = $this->verificarAccesoAdmin();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $id = filter_input(INPUT_POST, 'id_usuario', FILTER_VALIDATE_INT);
            $nombre = filter_input(INPUT_POST, 'nombre', FILTER_DEFAULT);
            $email = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
            $rol = $_POST['rol'] ?? '';
            $telefono = filter_input(INPUT_POST, 'telefono', FILTER_DEFAULT);
            $activo = isset($_POST['activo']) ? (int)$_POST['activo'] : 1;
            
            // Si no sube nada nuevo, el modelo mantendrá la que tiene o la default
            $rutaPerfilDB = null; 

            // Procesar subida de archivo físico real si existe en la petición
            if (isset($_FILES['foto_perfil']) && $_FILES['foto_perfil']['error'] === UPLOAD_ERR_OK) {
                $subida = Uploader::subirImagen($_FILES['foto_perfil'], 'perfiles');
                if ($subida) {
                    $rutaPerfilDB = $subida;
                }
            }

            if (!$id || empty($nombre) || empty($email) || empty($rol)) {
                http_response_code(400);
                echo json_encode(["status" => "error", "message" => "Datos inválidos para la actualización."]);
                return;
            }

            // Agregamos el parámetro de la ruta de imagen al método de actualización del Modelo
            $exito = Usuario::actualizar($id, $nombre, $email, $rol, $telefono, $activo, $rutaPerfilDB);

            if ($exito) {
                Historial::registrar($adminLogueado['usuario_id'], 'Usuarios', 'Modificar', "Se actualizaron los datos del usuario ID: {$id}");
                echo json_encode(["status" => "success", "message" => "Usuario actualizado con éxito."]);
            } else {
                http_response_code(500);
                echo json_encode(["status" => "error", "message" => "Error al actualizar el usuario."]);
            }
        }
    }
}