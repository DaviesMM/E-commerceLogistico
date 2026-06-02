<?php
// src/Controllers/ProductoController.php


require_once __DIR__ . '/../Services/Uploader.php';
require_once __DIR__ . '/../Models/Producto.php';

class ProductoController {

    /**
     * Middleware interno para verificar acceso (Admin o Staff pueden gestionar productos).
     */
    private function verificarAccesoPermitido() {
        if (!isset($_SESSION['usuario_id']) || !in_array($_SESSION['usuario_rol'], ['admin', 'staff'])) {
            http_response_code(403);
            echo json_encode(["status" => "error", "message" => "Acceso denegado. No tiene permisos para gestionar inventario."]);
            exit;
        }
    }

    /**
     * Registra un producto escaneado/digitado.
     */
    public function registrar() {
        $this->verificarAccesoPermitido();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $codigo_barras      = filter_input(INPUT_POST, 'codigo_barras', FILTER_DEFAULT);
            $nombre             = filter_input(INPUT_POST, 'nombre', FILTER_DEFAULT);
            $descripcion        = filter_input(INPUT_POST, 'descripcion', FILTER_DEFAULT);
            $precio             = filter_input(INPUT_POST, 'precio', FILTER_VALIDATE_FLOAT);
            $stock              = filter_input(INPUT_POST, 'stock', FILTER_VALIDATE_INT);
            $tipo_disponibilidad = $_POST['tipo_disponibilidad'] ?? 'stock'; // stock o encargo
            $dias_espera        = filter_input(INPUT_POST, 'dias_espera', FILTER_VALIDATE_INT) ?? 0;
            $fecha_vencimiento  = $_POST['fecha_vencimiento'] ?? null;
            // 1. Definimos la ruta por defecto como "Plan B"
            $rutaImagenDB = "uploads/productos/default.png"; 
            
            // 2. 🔥 REVISIÓN CRÍTICA: Validamos si el archivo fue enviado y no tiene errores
            if (isset($_FILES['foto_producto']) && $_FILES['foto_producto']['error'] === UPLOAD_ERR_OK) {
                // Ejecutamos el servicio de subida
                $subida = Uploader::subirImagen($_FILES['foto_producto'], 'productos');
                
                if ($subida) {
                    // ¡Éxito! Reemplazamos la ruta por defecto con la ruta real del archivo generado
                    $rutaImagenDB = $subida; 
                }
            }
            if (empty($codigo_barras) || empty($nombre) || $precio === false || $stock === false) {
                http_response_code(400);
                echo json_encode(["status" => "error", "message" => "Datos de producto incompletos o inválidos."]);
                return;
            }

            // Validar si el código de barras ya existe para no duplicarlo
            if (Producto::buscarPorCodigoBarras($codigo_barras)) {
                http_response_code(409); // Conflict
                echo json_encode(["status" => "error", "message" => "El código de barras ya está registrado en otro producto."]);
                return;
            }

            
            $exito = Producto::crear($codigo_barras, $rutaImagenDB, $nombre, $descripcion, $precio, $stock, $tipo_disponibilidad, $dias_espera, $fecha_vencimiento);

            if ($exito) {
                http_response_code(201);
                echo json_encode(["status" => "success", "message" => "Producto registrado exitosamente."]);
            } else {
                http_response_code(500);
                echo json_encode(["status" => "error", "message" => "Error interno al guardar el producto."]);
            }
        }
    }

    /**
     * Endpoint para el escáner: busca un producto al instante pasando el código por POST o GET.
     */
    public function buscarPorCodigo() {
        $this->verificarAccesoPermitido();

        $codigo = $_REQUEST['codigo_barras'] ?? '';

        if (empty($codigo)) {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "Código de barras requerido."]);
            return;
        }

        $producto = Producto::buscarPorCodigoBarras($codigo);

        if ($producto) {
            echo json_encode(["status" => "success", "data" => $producto]);
        } else {
            http_response_code(404);
            echo json_encode(["status" => "error", "message" => "Producto no encontrado con ese código de barras."]);
        }
    }

    /**
     * Lista todo el catálogo de productos.
     */
    public function listar() {
        $this->verificarAccesoPermitido();
        $productos = Producto::listarTodos();
        echo json_encode(["status" => "success", "data" => $productos]);
    }
}