# Configuración de Google Cloud para Huexs Google Reviews

## Requisitos previos

1. Una cuenta de Google que **administre** una ficha de empresa **verificada y activa** en Google Business Profile.
2. Un proyecto de Google Cloud.
3. **Aprobación de acceso a Business Profile API** ("Basic API Access"): Google exige solicitar acceso mediante el formulario oficial; sin esta aprobación las APIs devuelven 403 aunque estén habilitadas. Consulta los requisitos vigentes: https://developers.google.com/my-business/content/prereqs

## Pasos

### 1. Habilitar las APIs

En el proyecto de Google Cloud (APIs & Services → Library), habilita:

- **My Business Account Management API**
- **My Business Business Information API**
- **Google My Business API** (v4, necesaria para reseñas)

### 2. Pantalla de consentimiento OAuth

- Tipo: externa (o interna si usas Google Workspace y la cuenta pertenece a la organización).
- Scope: `https://www.googleapis.com/auth/business.manage` (único scope; no añadas más).
- Mientras la app esté en modo "Testing", añade la cuenta de Google que administra las fichas como *test user* (los refresh tokens de apps en Testing caducan a los 7 días; para uso continuado, publica la app).

### 3. Credenciales OAuth

- Crea una credencial **OAuth client ID** de tipo **Web application**.
- En "Authorized redirect URIs" añade EXACTAMENTE la URI que muestra el plugin en **Google Reviews → Conexión**, con esta forma:
  `https://TU-DOMINIO/wp-admin/admin-post.php?action=hgr_google_oauth_callback`
- El sitio debe servirse por HTTPS.

### 4. Configurar WordPress

Añade a `wp-config.php` (opción recomendada):

```php
define( 'HGR_GOOGLE_CLIENT_ID', 'xxxxxxxx.apps.googleusercontent.com' );
define( 'HGR_GOOGLE_CLIENT_SECRET', 'GOCSPX-xxxxxxxx' );
```

Alternativa: introducirlas en Google Reviews → Conexión (se guardan cifradas en la base de datos).

### 5. Conectar

En **Google Reviews → Conexión**, pulsa "Conectar con Google", inicia sesión con la cuenta que administra las fichas y acepta el consentimiento.

## Cuotas

Business Profile API tiene cuotas por minuto relativamente bajas. El plugin pagina con `pageSize=50`, reintenta 429/5xx con backoff y respeta `Retry-After`. Con frecuencia de 6 h y pocas ubicaciones no deberías alcanzar límites. Límites vigentes: https://developers.google.com/my-business/content/limits
