<?php
// config/jwt.php

return [
    // Cambia esto por una cadena larga y aleatoria en producción
    'secret_key' => 'NOVATOS_SECRET_KEY_2026_LOGISTICA_PRO_SECURITY_TOKEN',
    'expiration' => 900 // Tiempo de vida del token en segundos (8 horas)
];