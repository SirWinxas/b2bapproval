# Aprobación de Clientes B2B (b2bapproval)

Módulo para PrestaShop 8.x pensado para tiendas cerradas B2B / mayoristas. Los clientes se registran por su cuenta, pero **quedan desactivados y en el grupo "Pendiente"** hasta que un administrador los aprueba y les asigna su grupo/tarifa.

## Qué hace (Fase 1: control de acceso)

- Al registrarse un cliente en la tienda:
  - Se desactiva (`active = 0`) y su único grupo pasa a ser "Pendiente".
  - Se cierra la sesión que PrestaShop abre automáticamente tras el registro.
  - Se avisa por email al administrador (opcional).
- En **Clientes → Aprobación B2B** se listan los clientes pendientes con dos acciones:
  - **Aprobar**: activa al cliente, lo mueve al grupo de aprobación configurado y le envía un email (opcional).
  - **Rechazar**: el cliente se desactiva y se mueve al grupo "Rechazado" (no se elimina), por lo que deja de aparecer en la lista de pendientes.

## Instalación

1. Copia la carpeta `b2bapproval` en `modules/` (o comprímela en ZIP e impórtala desde Módulos).
2. Instala el módulo desde el back office.
3. En la instalación se crean los grupos **Pendiente** y **Rechazado** (sin precios visibles, sin descuento). Si ya existe un grupo con ese nombre, se reutiliza.

## Configuración

Desde la página de configuración del módulo:

- **Grupo pendiente**: grupo donde quedan los registros nuevos.
- **Grupo al aprobar**: grupo/tarifa mayorista que se asigna al aprobar (por defecto, el grupo "Cliente").
- **Email del administrador**: destino del aviso de nuevos registros (por defecto, el email de la tienda).
- **Avisar al administrador** / **Avisar al cliente al aprobar**: activan o desactivan los correos.

## Desinstalación

Se eliminan el menú y la configuración. **Los grupos "Pendiente" y "Rechazado" no se borran**, porque pueden contener clientes; elimínalos manualmente si ya no hacen falta.

## Notas

- Los correos están en español e inglés (`mails/es`, `mails/en`); para otros idiomas se usa español o inglés como alternativa.
- Los clientes ya existentes antes de instalar el módulo no se ven afectados.
- Alcance de la Fase 1: solo control de acceso. No incluye reglas de precios ni catálogo por grupo.

## Licencia

MIT. Copyright 2026 Daniel Gundin.
