<?php
// config/routes.php
require_once __DIR__ . '/../core/Router.php';

$router = new Router();

// =========================================================================
// 🔐 RECURSO: AUTENTICACIÓN (`/auth`)
// =========================================================================

$router->post('auth/login', 'AuthController@login');        // Enviar credenciales
$router->post('auth/logout', 'AuthController@logout');      // Destruir sesión/token
$router->post('auth/refresh', 'AuthController@refresh');    // Renovar Access Token (JWT)

// =========================================================================
// 👥 RECURSO: USUARIOS (`admin/usuarios`)
// =========================================================================
$router->get('admin/usuarios', 'UsuarioController@listar');       // GET = Listar todo
$router->post('admin/usuarios', 'UsuarioController@registrar');   // POST = Crear nuevo
$router->post('admin/usuarios/actualizar', 'UsuarioController@modificar');    // POST = Actualizar datos 
$router->post('admin/pedidos/cancelar', 'PedidoController@cancelarPedido'); // POST = Cancelar un pedido específico (con lógica de devolución al stock)
$router->post('admin/pedidos/estado', 'PedidoController@actualizarEstado'); // POST = Cambiar estado de un pedido (ej: de 'pendiente_confirmar' a 'pago_pendiente' o 'cancelado')
$router->post('admin/pedidos/total', 'PedidoController@obtenerTotal'); // POST = Obtener el total de un pedido específico (útil para auditoría y validación de pagos)
$router->post('admin/pedidos/devolver-stock', 'PedidoController@devolver'); // POST = Devolver los productos de un pedido al stock general (Útil para cancelaciones)
$router->post('admin/productos/actualizar-stock', 'ProductoController@actualizarStock'); // POST = Actualizar el stock de un producto específico (ej: después de una auditoría o corrección manual)
$router->post('admin/productos/actualizar', 'ProductoController@actualizar');
// Ruta para que el Admin supervise toda la logística de la calle
$router->get('admin/pedidos/despachos', 'PedidoController@obtenerTodosLosDespachos');
// Nota: Si tu router no soporta el método PUT nativo, puedes dejarlo como POST, 
// pero la URL se mantiene limpia: 'admin/usuarios'

// Rutas de administración y control operativo de personal y stock
$router->get('admin/usuarios/detalle', 'UsuarioController@obtenerDetalle'); 
$router->post('admin/productos/eliminar', 'ProductoController@eliminar');
$router->post('admin/pedidos/asignar-repartidor', 'PedidoController@asignarRepartidor');

// =========================================================================
// 📦 RECURSO: PRODUCTOS E INVENTARIO (`admin/productos`)
// =========================================================================
$router->get('admin/productos', 'ProductoController@listar');          // GET = Obtener catálogo
$router->post('admin/productos', 'ProductoController@registrar');      // POST = Agregar al stock
$router->post('admin/productos/buscar', 'ProductoController@buscarPorCodigo'); // Búsqueda específica del escáner

// =========================================================================
// 🛒 RECURSO: PEDIDOS Y ÓRDENES (`/pedidos`)
// =========================================================================
$router->post('cliente/pedidos', 'PedidoController@procesarOrden');        // POST = Crear una orden de compra
$router->get('pedidos/acciones-cliente', 'PedidoController@procesarAccionCliente'); // Respuestas interactivas (WhatsApp)

// =========================================================================
// 🚀 RECURSO: LOGÍSTICA INTERNA / ALMACÉN (`staff/picking`)
// =========================================================================
$router->post('staff/picking/verificar', 'LogisticaController@verificarItemEscaneado'); // Validación de ítem
$router->post('staff/picking/finalizar', 'LogisticaController@finalizarEmpaque');      // Cerrar caja física

// =========================================================================
// 🛵 RECURSO: DESPACHOS Y ENTREGAS (`/despachos`)
// =========================================================================
$router->post('admin/despachos', 'LogisticaController@asignarAdomiciliario');     // POST = Crear una asignación de ruta
$router->get('delivery/despachos', 'LogisticaController@verMiHojaDeRuta');         // GET = Listar órdenes asignadas al motorizado
$router->post('delivery/despachos/entregar', 'LogisticaController@registrarEntregaExitosa'); // POST = Confirmar entrega física
$router->post('motorizado/pedidos/incidencia', 'PedidoController@registrarIncidenciaCalle');
$router->post('motorizado/pedidos/actualizar-estado', 'PedidoController@actualizarEstadoDesdeCalle');
$router->get('motorizado/pedidos/mi-ruta', 'PedidoController@obtenerMiHojaDeRuta');
// =========================================================================
// 💰 RECURSO: FINANZAS, CAJAS Y ARQUEOS (`/pagos` o `/cajas`)
// =========================================================================
$router->post('cliente/pagos', 'PagoController@informarPago');               // POST = Cliente sube comprobante
$router->post('admin/pagos/auditar', 'PagoController@validarPagoAdmin');        // POST = Admin aprueba o rechaza transferencia
$router->get('admin/repartidores/caja', 'PagoController@verEfectivoPendienteDelivery'); // GET = Consultar saldo en la calle (?id_repartidor=5)
$router->post('admin/repartidores/liquidador', 'PagoController@liquidarCajaDelivery');  // POST = Cuadrar caja del repartidor en oficina

// =========================================================================
// 📊 RECURSO: AUDITORÍA DE SISTEMA (`admin/sistema`)
// =========================================================================
$router->get('admin/sistema/historial', 'UsuarioController@verAuditoriaSistema'); // GET = Ver logs globales del negocio
$router->post('admin/caja/liquidar', 'PagoController@liquidarCajaDelivery'); // POST = Liquidar caja del repartidor (cambio de estado a 'liquidado' e inyección de fecha de arqueo)
$router->get('admin/dashboard/kpis', 'DashboardController@obtenerMetricasPrincipales');
// Retornamos el enrutador configurado profesionalmente
return $router;