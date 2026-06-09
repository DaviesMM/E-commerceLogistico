<?php
// src/Models/Usuario.php NUEVO MODELOS. VERSION 2

require_once __DIR__ . '/../../config/database.php';

class Usuario {

    // =========================================================================
    // 🏢 PARTE 1: CRUD ESENCIAL DE USUARIOS (ADMIN, STAFF, DELIVERY)
    // =========================================================================

    /**
     * Crea un nuevo usuario en el sistema.
     * Almacena el password ya encriptado con BCRYPT desde el controlador.
     */
    public static function crear(array $datos): bool {
        try {
            $db = Database::conectar();
            $sql = "INSERT INTO usuarios (nombre, email, password, rol, foto_perfil_url, telefono, codigo_seguridad_registro, activo) 
                    VALUES (:nombre, :email, :password, :rol, :foto, :telefono, :codigo, :activo)";
            
            $stmt = $db->prepare($sql);
            return $stmt->execute([
                ':nombre'    => $datos['nombre'],
                ':email'     => $datos['email'],
                ':password'  => $datos['password'], // Hash BCRYPT
                ':rol'       => $datos['rol'],
                ':foto'      => $datos['foto_perfil_url'] ?? 'uploads/perfiles/avatar-default.png',
                ':telefono'  => $datos['telefono'] ?? null,
                ':codigo'    => $datos['codigo_seguridad_registro'] ?? null,
                ':activo'    => $datos['activo'] ?? 1
            ]);
        } catch (PDOException $e) {
            error_log("Error en Usuario::crear -> " . $e->getMessage());
            return false;
        }
    }

    /**
     * Lista todos los usuarios registrados en el sistema.
     * Por privacidad y seguridad, jamás extrae el campo 'password'.
     */
    public static function listarTodos(): array {
        try {
            $db = Database::conectar();
            $sql = "SELECT id_usuario, nombre, email, rol, foto_perfil_url, telefono, activo, fecha_creacion 
                    FROM usuarios 
                    ORDER BY id_usuario DESC";
            
            $stmt = $db->query($sql);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error en Usuario::listarTodos -> " . $e->getMessage());
            return [];
        }
    }

    /**
     * Busca un usuario específico por su ID único.
     */
    public static function obtenerPorId(int $idUsuario) {
        try {
            $db = Database::conectar();
            $sql = "SELECT id_usuario, nombre, email, rol, foto_perfil_url, telefono, activo, fecha_creacion 
                    FROM usuarios 
                    WHERE id_usuario = :id 
                    LIMIT 1";
            
            $stmt = $db->prepare($sql);
            $stmt->execute([':id' => $idUsuario]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error en Usuario::obtenerPorId -> " . $e->getMessage());
            return false;
        }
    }

    /**
     * Actualiza la información básica del perfil de un usuario.
     */
    public static function actualizar(int $idUsuario, array $datos): bool {
        try {
            $db = Database::conectar();
            $sql = "UPDATE usuarios 
                    SET nombre = :nombre, email = :email, rol = :rol, telefono = :telefono, foto_perfil_url = :foto 
                    WHERE id_usuario = :id";
            
            $stmt = $db->prepare($sql);
            return $stmt->execute([
                ':nombre'   => $datos['nombre'],
                ':email'    => $datos['email'],
                ':rol'      => $datos['rol'],
                ':telefono' => $datos['telefono'] ?? null,
                ':foto'     => $datos['foto_perfil_url'],
                ':id'       => $idUsuario
            ]);
        } catch (PDOException $e) {
            error_log("Error en Usuario::actualizar -> " . $e->getMessage());
            return false;
        }
    }

    /**
     * Actualiza estrictamente la contraseña hash de un usuario.
     */
    public static function actualizarPassword(int $idUsuario, string $nuevoPasswordHash): bool {
        try {
            $db = Database::conectar();
            $sql = "UPDATE usuarios SET password = :password WHERE id_usuario = :id";
            $stmt = $db->prepare($sql);
            return $stmt->execute([
                ':password' => $nuevoPasswordHash,
                ':id'       => $idUsuario
            ]);
        } catch (PDOException $e) {
            error_log("Error en Usuario::actualizarPassword -> " . $e->getMessage());
            return false;
        }
    }

    /**
     * Modifica el estado de activación (Activo/Inactivo) de un empleado.
     * En lugar de borrar registros físicos, usamos borrado lógico por integridad de auditorías.
     */
    public static function cambiarEstado(int $idUsuario, int $estado): bool {
        try {
            $db = Database::conectar();
            $sql = "UPDATE usuarios SET activo = :estado WHERE id_usuario = :id";
            $stmt = $db->prepare($sql);
            return $stmt->execute([
                ':estado' => $estado, // 1 para Activo, 0 para Inactivo
                ':id'     => $idUsuario
            ]);
        } catch (PDOException $e) {
            error_log("Error en Usuario::cambiarEstado -> " . $e->getMessage());
            return false;
        }
    }

    // =========================================================================
    // 🛡️ PARTE 2: GESTIÓN DE SEGURIDAD Y REFRESH TOKENS (SISTEMA DE ROTACIÓN RTR)
    // =========================================================================

    public static function obtenerPorEmail(string $email) {
        try {
            $db = Database::conectar();
            $sql = "SELECT id_usuario, nombre, email, password, rol, activo FROM usuarios WHERE email = :email LIMIT 1";
            $stmt = $db->prepare($sql);
            $stmt->execute([':email' => $email]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error en Usuario::obtenerPorEmail -> " . $e->getMessage());
            return false;
        }
    }

    public static function guardarRefreshToken(int $idUsuario, string $tokenUuid, string $fechaExpiracion): bool {
        try {
            $db = Database::conectar();
            self::eliminarRefreshTokensPorUsuario($idUsuario); // Limpieza preventiva

            $sql = "INSERT INTO usuarios_refresh_tokens (id_usuario, token_uuid, fecha_expiracion) VALUES (:id_usuario, :token, :expiracion)";
            $stmt = $db->prepare($sql);
            return $stmt->execute([
                ':id_usuario' => $idUsuario,
                ':token'      => $tokenUuid,
                ':expiracion' => $fechaExpiracion
            ]);
        } catch (PDOException $e) {
            error_log("Error en Usuario::guardarRefreshToken -> " . $e->getMessage());
            return false;
        }
    }

    public static function validarRefreshToken(string $tokenUuid) {
        try {
            $db = Database::conectar();
            $sql = "SELECT r.id_usuario, r.token_uuid, r.fecha_expiracion, u.email, u.rol, u.activo 
                    FROM usuarios_refresh_tokens r
                    JOIN usuarios u ON r.id_usuario = u.id_usuario
                    WHERE r.token_uuid = :token AND u.activo = 1
                    LIMIT 1";
            
            $stmt = $db->prepare($sql);
            $stmt->execute([':token' => $tokenUuid]);
            $tokenBD = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$tokenBD) return false;

            if (strtotime($tokenBD['fecha_expiracion']) < time()) {
                self::eliminarRefreshTokenEspecifico($tokenUuid);
                return false;
            }

            return $tokenBD;
        } catch (PDOException $e) {
            error_log("Error en Usuario::validarRefreshToken -> " . $e->getMessage());
            return false;
        }
    }

    public static function rotarRefreshToken(string $tokenViejo, string $tokenNuevo, string $nuevaExpiracion, int $idUsuario): bool {
        try {
            $db = Database::conectar();
            $db->beginTransaction();

            $sqlDelete = "DELETE FROM usuarios_refresh_tokens WHERE token_uuid = :token_viejo";
            $stmtDelete = $db->prepare($sqlDelete);
            $stmtDelete->execute([':token_viejo' => $tokenViejo]);

            $sqlInsert = "INSERT INTO usuarios_refresh_tokens (id_usuario, token_uuid, fecha_expiracion) VALUES (:id_usuario, :token_nuevo, :expiracion)";
            $stmtInsert = $db->prepare($sqlInsert);
            $stmtInsert->execute([
                ':id_usuario' => $idUsuario,
                ':token_nuevo' => $tokenNuevo,
                ':expiracion'  => $nuevaExpiracion
            ]);

            $db->commit();
            return true;
        } catch (PDOException $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log("Error en Usuario::rotarRefreshToken -> " . $e->getMessage());
            return false;
        }
    }

    public static function eliminarRefreshTokenEspecifico(string $tokenUuid): bool {
        try {
            $db = Database::conectar();
            $sql = "DELETE FROM usuarios_refresh_tokens WHERE token_uuid = :token";
            $stmt = $db->prepare($sql);
            return $stmt->execute([':token' => $tokenUuid]);
        } catch (PDOException $e) {
            error_log("Error en Usuario::eliminarRefreshTokenEspecifico -> " . $e->getMessage());
            return false;
        }
    }

    public static function eliminarRefreshTokensPorUsuario(int $idUsuario): bool {
        try {
            $db = Database::conectar();
            $sql = "DELETE FROM usuarios_refresh_tokens WHERE id_usuario = :id_usuario";
            $stmt = $db->prepare($sql);
            return $stmt->execute([':id_usuario' => $idUsuario]);
        } catch (PDOException $e) {
            error_log("Error en Usuario::eliminarRefreshTokensPorUsuario -> " . $e->getMessage());
            return false;
        }
    }
}