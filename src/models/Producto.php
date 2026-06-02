<?php
// src/Models/Producto.php

require_once __DIR__ . '/../../config/database.php';

class Producto {

    /**
     * Registra un nuevo producto en el inventario.
     */
    public static function crear($codigo_barras, $imagenUrl, $nombre, $descripcion, $precio, $stock, $tipo_disponibilidad, $dias_espera, $fecha_vencimiento) {
        try {
            $db = Database::conectar();
            $sql = "INSERT INTO productos (codigo_barras,imagen_url, nombre, descripcion, precio, stock, tipo_disponibilidad, dias_espera_encargo, fecha_vencimiento) 
                    VALUES (:codigo_barras, :imagen_url, :nombre, :descripcion, :precio, :stock, :tipo_disponibilidad, :dias_espera, :fecha_vencimiento)";
            
            $stmt = $db->prepare($sql);
            return $stmt->execute([
                ':codigo_barras'        => $codigo_barras,
                ':imagen_url'           => $imagenUrl,
                ':nombre'               => $nombre,
                ':descripcion'          => $descripcion,
                ':precio'               => $precio,
                ':stock'                => $stock,
                ':tipo_disponibilidad'  => $tipo_disponibilidad, // 'stock' o 'encargo'
                ':dias_espera'          => $tipo_disponibilidad === 'encargo' ? $dias_espera : 0,
                ':fecha_vencimiento'    => !empty($fecha_vencimiento) ? $fecha_vencimiento : null
            ]);
        } catch (PDOException $e) {
            error_log("Error en Producto::crear -> " . $e->getMessage());
            return false;
        }
    }

    /**
     * Busca un producto por su código de barras. 
     * ¡Esta función la usará el escáner tanto en el registro como en el empaque!
     */
    public static function buscarPorCodigoBarras($codigo_barras) {
        try {
            $db = Database::conectar();
            $sql = "SELECT id_producto, codigo_barras, nombre, descripcion, precio, stock, tipo_disponibilidad, dias_espera_encargo, fecha_vencimiento 
                    FROM productos 
                    WHERE codigo_barras = :codigo_barras 
                    LIMIT 1";
            
            $stmt = $db->prepare($sql);
            $stmt->execute([':codigo_barras' => $codigo_barras]);
            return $stmt->fetch();
        } catch (PDOException $e) {
            error_log("Error en Producto::buscarPorCodigoBarras -> " . $e->getMessage());
            return false;
        }
    }

    /**
     * Lista todos los productos en inventario.
     */
    public static function listarTodos() {
        try {
            $db = Database::conectar();
            $sql = "SELECT * FROM productos ORDER BY id_producto DESC";
            $stmt = $db->query($sql);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log("Error en Producto::listarTodos -> " . $e->getMessage());
            return [];
        }
    }
}