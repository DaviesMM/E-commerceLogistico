<?php
// src/Controllers/AuthController.php

require_once __DIR__ . '/../Models/Usuario.php';

class AuthController {

    /**
     * Procesa el inicio de sesión y responde en formato JSON
     */
    public function login() {
        // Si ya hay una sesión activa, avisamos al cliente de API
        if (isset($_SESSION['usuario_id'])) {
            echo json_encode([
                "status" => "info",
                "message" => "Ya existe una sesión activa.",
                "usuario" => [
                    "nombre" => $_SESSION['usuario_nombre'],
                    "rol" => $_SESSION['usuario_rol']
                ]
            ]);
            return;
        }

        // Validar que la petición sea estrictamente POST
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            
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
                
                // Crear variables de sesión en el servidor
                $_SESSION['usuario_id'] = $usuario['id_usuario'];
                $_SESSION['usuario_nombre'] = $usuario['nombre'];
                $_SESSION['usuario_rol'] = $usuario['rol'];

                http_response_code(200); // OK
                echo json_encode([
                    "status" => "success",
                    "message" => "Autenticación exitosa.",
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
            "message" => "Sesión destruida correctamente en el servidor."
        ]);
        exit;
    }
}