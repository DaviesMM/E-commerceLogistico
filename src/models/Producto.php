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
     * Obtiene el catálogo de productos paginado y calcula el total de páginas existentes
     */
    public static function listarPaginado($paginaActual = 1, $limitePorPagina = 15) {
        try {
            $db = Database::conectar();

            // 1. Contar cuántos productos existen en TOTAL en la base de datos
            $sqlTotal = "SELECT COUNT(*) as total FROM productos";
            $totalRegistros = $db->query($sqlTotal)->fetch(PDO::FETCH_ASSOC)['total'];

            // 2. Calcular el total de páginas necesarias (Redondeando hacia arriba)
            $totalPaginas = ceil($totalRegistros / $limitePorPagina);

            // 3. Calcular el "Offset" (Desde qué fila de la tabla MySQL empezará a leer)
            // Ejemplo: Si estamos en página 2 con límite de 15, el offset es (2-1)*15 = 15. Salta los primeros 15.
            $offset = ($paginaActual - 1) * $limitePorPagina;

            // 4. Hacer la consulta real limitando los resultados
            // NOTA: En PDO, LIMIT y OFFSET deben ser enteros estrictos, por eso usamos bindValue con PARAM_INT
            $sql = "SELECT id_producto, nombre, codigo_barras, precio, stock 
                    FROM productos 
                    ORDER BY id_producto DESC 
                    LIMIT :limite OFFSET :offset";
            
            $stmt = $db->prepare($sql);
            $stmt->bindValue(':limite', (int)$limitePorPagina, PDO::PARAM_INT);
            $stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
            $stmt->execute();
            
            $productos = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // 5. Devolver un paquete estructurado de metadatos para el Frontend
            return [
                "data" => $productos,
                "paginacion" => [
                    "total_registros" => (int)$totalRegistros,
                    "total_paginas"   => (int)$totalPaginas,
                    "pagina_actual"   => (int)$paginaActual,
                    "limite_por_pag"  => (int)$limitePorPagina
                ]
            ];

        } catch (PDOException $e) {
            error_log("Error en Producto::listarPaginado -> " . $e->getMessage());
            return ["data" => [], "paginacion" => []];
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
    public static function listar() {
       try {
        $db = Database::conectar();
        
        // 1. Traemos los productos base (con paginación si ya la tienes armada)
        $sql = "SELECT id_producto, codigo_barras, nombre, descripcion, precio, stock, tipo_disponibilidad 
                FROM productos 
                ORDER BY id_producto DESC";
        $stmt = $db->query($sql);
        $productos = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 2. Por cada producto, buscamos todos sus ángulos en la tabla 'producto_imagenes'
        foreach ($productos as &$producto) {
            $sqlImg = "SELECT id_imagen, ruta_imagen, es_principal 
                       FROM producto_imagenes 
                       WHERE id_producto = :id_prod 
                       ORDER BY es_principal DESC"; // La principal saldrá primero
            
            $stmtImg = $db->prepare($sqlImg);
            $stmtImg->execute([':id_prod' => $producto['id_producto']]);
            
            // Adjuntamos el array de imágenes directamente al objeto del producto
            $producto['imagenes'] = $stmtImg->fetchAll(PDO::FETCH_ASSOC);
        }

        // 3. Retornamos la respuesta limpia en JSON
        http_response_code(200);
        echo json_encode([
            "status" => "success",
            "data" => $productos
        ]);

    } catch (PDOException $e) {
        error_log("Error al listar productos con imágenes: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "Error interno del servidor."]);
    }
    }

// Dentro de src/Models/Producto.php

public static function modificarStockManual($id_producto, $nuevo_stock) {
    $db = Database::conectar();
    $stmt = $db->prepare("UPDATE productos SET stock = :stock WHERE id_producto = :id");
    return $stmt->execute([':stock' => $nuevo_stock, ':id' => $id_producto]);
}

public static function actualizarDatosBase($id_producto, $codigo, $nombre, $desc, $precio, $dispo) {
    $db = Database::conectar();
    $sql = "UPDATE productos 
            SET codigo_barras = :codigo, nombre = :nombre, descripcion = :desc, precio = :precio, tipo_disponibilidad = :dispo 
            WHERE id_producto = :id";
    $stmt = $db->prepare($sql);
    return $stmt->execute([
        ':codigo' => $codigo,
        ':nombre' => $nombre,
        ':desc'   => $desc,
        ':precio' => $precio,
        ':dispo'  => $dispo,
        ':id'     => $id_producto
    ]);
}
    /**
     * Actualiza los datos de un producto existente.
     */

public static function desactivarLogico($id) {
    $db = Database::conectar();
    // En lugar de un DELETE destructivo, hacemos un UPDATE limpio
    $stmt = $db->prepare("UPDATE productos SET activo = 0 WHERE id_producto = :id");
    return $stmt->execute([':id' => $id]);
}
}