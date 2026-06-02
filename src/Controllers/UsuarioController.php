<?php
// src/Controllers/UsuarioController.php

require_once __DIR__ . '/../Models/Usuario.php';
require_once __DIR__ . '/../Models/Historial.php';

class UsuarioController {

    /**
     * Middleware interno para verificar si el usuario es Administrador.
     */
    private function verificarAccesoAdmin() {
        if (!isset($_SESSION['usuario_id']) || $_SESSION['usuario_rol'] !== 'admin') {
            http_response_code(403); // Forbidden
            echo json_encode(["status" => "error", "message" => "Acceso denegado. Se requieren permisos de Administrador."]);
            exit; // Detiene la ejecución por completo
        }
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
        $this->verificarAccesoAdmin();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $nombre = filter_input(INPUT_POST, 'nombre', FILTER_DEFAULT);
            $email = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
            $password = $_POST['password'] ?? '';
            $rol = $_POST['rol'] ?? ''; // staff o delivery
            $telefono = filter_input(INPUT_POST, 'telefono', FILTER_DEFAULT);

            if (empty($nombre) || empty($email) || empty($password) || empty($rol)) {
                http_response_code(400);
                echo json_encode(["status" => "error", "message" => "Faltan datos obligatorios."]);
                return;
            }

            // Usamos el método 'crear' que ya teníamos en el modelo
            $exito = Usuario::crear($nombre, $email, $password, $rol, $telefono);
            
            if ($exito) {
                // Registrar la acción en el historial
                Historial::registrar($_SESSION['usuario_id'], 'Usuarios', 'Crear', "Se creó el usuario: {$nombre} ({$email})");

                http_response_code(201); // Created
                echo json_encode(["status" => "success", "message" => "Usuario creado correctamente."]);
            } else {
                http_response_code(500);
                echo json_encode(["status" => "error", "message" => "No se pudo crear el usuario (posiblemente el correo ya existe)."]);
            }
        }
    }
     /**
     * [ADMIN] Ver el libro de actas digital de la empresa
     */
    public function verAuditoriaSistema() {
        if (!isset($_SESSION['usuario_id']) || $_SESSION['usuario_rol'] !== 'admin') {
            http_response_code(403);
            echo json_encode(["status" => "error", "message" => "Acceso denegado."]);
            return;
        }

        $logs = Historial::listarTodo();
        echo json_encode([
            "status" => "success",
            "total_registros" => count($logs),
            "historial" => $logs
        ]);
    }
    /**
     * Modifica los datos de un usuario
     */
    public function modificar() {
        $this->verificarAccesoAdmin();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $id = filter_input(INPUT_POST, 'id_usuario', FILTER_VALIDATE_INT);
            $nombre = filter_input(INPUT_POST, 'nombre', FILTER_DEFAULT);
            $email = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
            $rol = $_POST['rol'] ?? '';
            $telefono = filter_input(INPUT_POST, 'telefono', FILTER_DEFAULT);
            $activo = isset($_POST['activo']) ? (int)$_POST['activo'] : 1;
            $rutaPerfilDB = "uploads/perfiles/avatar-default.png";

            if (isset($_FILES['foto_perfil'])) {
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

            $exito = Usuario::actualizar($id, $nombre, $email, $rol, $telefono, $activo);

            if ($exito) {
                echo json_encode(["status" => "success", "message" => "Usuario actualizado con éxito."]);
            } else {
                http_response_code(500);
                echo json_encode(["status" => "error", "message" => "Error al actualizar el usuario."]);
            }
        }
    }
}