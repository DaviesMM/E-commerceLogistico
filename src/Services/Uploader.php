<?php
// src/Services/Uploader.php

class Uploader {

    /**
     * Mueve una imagen desde el formulario temporal hacia su carpeta destino
     * * @param array $file El array $_FILES['nombre_campo']
     * @param string $tipo El subdirectorio destino ('productos', 'comprobantes', 'perfiles')
     * @return string|false Devuelve la ruta relativa si tiene éxito, o false si falla.
     */
    public static function subirImagen($file, $tipo) {
        // 1. Validar si realmente se subió un archivo sin errores
        if (!isset($file) || $file['error'] !== UPLOAD_ERR_OK) {
            return false;
        }

        // 2. Definir las carpetas permitidas de destino
        $carpetasPermitidas = ['productos', 'comprobantes', 'perfiles'];
        if (!in_array($tipo, $carpetasPermitidas)) {
            return false;
        }

        $directorioDestino = __DIR__ . "/../../public/uploads/{$tipo}/";

        // 3. Validar el formato del archivo (Seguridad para evitar scripts maliciosos)
        $infoArchivo = getimagesize($file['tmp_name']);
        if ($infoArchivo === false) {
            // No es una imagen real
            return false;
        }

        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $extensionesPermitidas = ['jpg', 'jpeg', 'png', 'webp'];
        if (!in_array($extension, $extensionesPermitidas)) {
            return false;
        }

        // 4. Validar tamaño máximo (Ej: 3 Megabytes máximo por imagen)
        $maxSize = 3 * 1024 * 1024; 
        if ($file['size'] > $maxSize) {
            return false;
        }

        // 5. Renombrar el archivo con un ID único para evitar que un cliente sobrescriba la foto de otro
        $nuevoNombre = "IMG_" . uniqid() . "_" . time() . "." . $extension;
        $rutaCompletaDestino = $directorioDestino . $nuevoNombre;

        // 6. Mover el archivo temporal a la carpeta física externa
        if (move_uploaded_file($file['tmp_name'], $rutaCompletaDestino)) {
            // Retornamos la ruta amigable para guardar en la base de datos
            return "uploads/{$tipo}/" . $nuevoNombre;
        }

        return false;
    }
}