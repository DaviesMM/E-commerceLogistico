<?php
// src/Models/Usuario.php

require_once __DIR__ . '/../../config/database.php';

class Usuario {
    
    /**
     * Busca un usuario por su correo electrónico para el proceso de Login.
     * @param string $email
     * @return array|false Devuelve los datos del usuario o false si no existe.
     */// NOTA: Este método devuelve el hash de la contraseña para que el controlador pueda verificarlo con password_verify()
     // Dentro de src/Models/Usuario.php

    public static function buscarPorId($id) {
        $db = Database::conectar();
        $stmt = $db->prepare("SELECT id_usuario, nombre, email, rol, foto_perfil, fecha_registro FROM usuarios WHERE id_usuario = :id");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    public static function obtenerPorEmail($email) {
        try {
            $db = Database::conectar();
            // Traemos solo los usuarios activos
            $sql = "SELECT id_usuario, nombre, email, password, rol, activo, foto_perfil 
                    FROM usuarios 
                    WHERE email = :email AND activo = 1 
                    LIMIT 1";
            
            $stmt = $db->prepare($sql);
            $stmt->execute([':email' => $email]);
            
            return $stmt->fetch(); // Devuelve el registro como un array asociativo
        } catch (PDOException $e) {
            error_log("Error en Usuario::obtenerPorEmail -> " . $e->getMessage());
            return false;
        }
    }

    /**
     * Busca un usuario por su ID (Útil para validar la sesión en cada página).
     * @param int $id
     * @return array|false
     */
    public static function obtenerPorId($id) {
        try {
            $db = Database::conectar();
            $sql = "SELECT id_usuario, nombre, email, rol, activo 
                    FROM usuarios 
                    WHERE id_usuario = :id 
                    LIMIT 1";
            
            $stmt = $db->prepare($sql);
            $stmt->execute([':id' => $id]);
            
            return $stmt->fetch();
        } catch (PDOException $e) {
            error_log("Error en Usuario::obtenerPorId -> " . $e->getMessage());
            return false;
        }
    }

    /**
     * Registra un nuevo usuario en el sistema (Esta función la usará estrictamente el Admin más adelante).
     */
    public static function crear($nombre, $email, $password, $rol, $telefono, $fotoPerfilUrl = null) {
        try {
            $db = Database::conectar();
            $sql = "INSERT INTO usuarios (nombre, email, password, rol, telefono, foto_perfil) 
                    VALUES (:nombre, :email, :password, :rol, :telefono, :foto_perfil)";
            
            // Encriptamos la contraseña con el algoritmo por defecto de PHP (Bcrypt/Argon2)
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            
            $stmt = $db->prepare($sql);
            return $stmt->execute([
                ':nombre'    => $nombre,
                ':email'     => $email,
                ':password'  => $passwordHash,
                ':rol'       => $rol,
                ':telefono'  => $telefono,
                ':foto_perfil' => $fotoPerfilUrl
            ]);
        } catch (PDOException $e) {
            error_log("Error en Usuario::crear -> " . $e->getMessage());
            return false;
        }
    }


    /**
     * Obtiene la lista de todos los usuarios registrados (menos sus contraseñas por seguridad).
     * @return array
     */
    public static function listarTodos() {
        try {
            $db = Database::conectar();
            $sql = "SELECT id_usuario, nombre, email, rol, telefono, activo, fecha_creacion 
                    FROM usuarios 
                    ORDER BY id_usuario DESC";
            $stmt = $db->query($sql);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log("Error en Usuario::listarTodos -> " . $e->getMessage());
            return [];
        }
    }

    /**
     * Actualiza los datos de un usuario existente.
     */
    public static function actualizar($id, $nombre, $email, $rol, $telefono, $activo) {
        try {
            $db = Database::conectar();
            $sql = "UPDATE usuarios 
                    SET nombre = :nombre, email = :email, rol = :rol, telefono = :telefono, activo = :activo 
                    WHERE id_usuario = :id";
            
            $stmt = $db->prepare($sql);
            return $stmt->execute([
                ':id'       => $id,
                ':nombre'    => $nombre,
                ':email'     => $email,
                ':rol'       => $rol,
                ':telefono'  => $telefono,
                ':activo'    => $activo
            ]);
        } catch (PDOException $e) {
            error_log("Error en Usuario::actualizar -> " . $e->getMessage());
            return false;
        }
    }

    /**
     * Cambia la contraseña de un usuario (Controlado por Admin o el propio usuario).
     */
    public static function actualizarPassword($id, $nuevaPassword) {
        try {
            $db = Database::conectar();
            $sql = "UPDATE usuarios SET password = :password WHERE id_usuario = :id";
            $passwordHash = password_hash($nuevaPassword, PASSWORD_DEFAULT);
            
            $stmt = $db->prepare($sql);
            return $stmt->execute([
                ':id' => $id,
                ':password' => $passwordHash
            ]);
        } catch (PDOException $e) {
            error_log("Error en Usuario::actualizarPassword -> " . $e->getMessage());
            return false;
        }
    }

 } // llave de cierre