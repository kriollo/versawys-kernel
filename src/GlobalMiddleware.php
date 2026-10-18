<?php

declare(strict_types=1);

namespace versaWYS\kernel;

use app\models\Dashboard;
use app\models\Users;
use Exception;
use SensitiveParameter;
use versaWYS\kernel\helpers\Functions;

class GlobalMiddleware
{
    // Propiedad estática para almacenar los datos del usuario durante una única petición.
    // Esto evita tener que pasar el objeto de usuario entre funciones.
    private static ?array $currentUserData = null;

    /**
     * Obtiene los datos del usuario cargados por el middleware.
     * Esto permite a los controladores acceder a los datos sin volver a cargarlos.
     */
    public static function getLoadedUserData(): ?array
    {
        return self::$currentUserData;
    }

    /**
     * Check session, authentication, user data loading, and password expiration.
     * Versión mejorada con mejor manejo de errores y respuestas consistentes.
     * This is the main entry point for all authenticated routes.
     */
    public function checkSession(): bool|array
    {
        global $session, $request, $config, $debug;

        try {
            // 1. Validar la sesión y el token JWT (con caché por request para performance)
            if (!$session->checkUserSessionCached()) {
                // Limpiar cualquier dato de sesión corrupto
                $session->clear();

                if ($debug) {
                    Loggin::error('[GlobalMiddleware::checkSession] Sesión inválida o expirada', 'GlobalMiddleware');
                }

                // Para peticiones no-API, redirigir al login
                if (!$request->isApiCall()) {
                    Response::redirect('/' . $config['admin']['custom_url'] . '/login');
                    Output::halt();
                }

                // Para peticiones API, devolver error estructurado
                return [
                    'success' => 0,
                    'valid' => false,
                    'message' => 'Token de autenticación inválido o expirado',
                    'code' => 401,
                ];
            }

            // 2. Cargar los datos del usuario usando el sistema de caché
            $userId = (int) $session->get('id_user');
            $empresaId = (int) $session->get('empresa_id');

            if ($userId <= 0) {
                if ($debug) {
                    Loggin::error(
                        '[GlobalMiddleware::checkSession] ID de usuario inválido: ' . $userId,
                        'GlobalMiddleware',
                    );
                }

                $session->destroy();

                if (!$request->isApiCall()) {
                    Response::redirect('/' . $config['admin']['custom_url'] . '/login');
                    Output::halt();
                }

                return [
                    'success' => 0,
                    'valid' => false,
                    'message' => 'Datos de sesión corruptos',
                    'code' => 401,
                ];
            }

            // 3. Obtener datos del usuario desde caché
            $cache = new CacheUsers();
            self::$currentUserData = $cache->getUserData($userId, $empresaId);

            // 4. Validar que se pudieron cargar los datos del usuario
            if (self::$currentUserData === [] || !isset(self::$currentUserData['user'])) {
                if ($debug) {
                    Loggin::error(
                        '[GlobalMiddleware::checkSession] No se pudieron cargar datos del usuario ID: ' . $userId,
                        'GlobalMiddleware',
                    );
                }

                // Limpiar sesión corrupta
                $session->destroy();

                if (!$request->isApiCall()) {
                    Response::redirect('/' . $config['admin']['custom_url'] . '/login');
                    Output::halt();
                }

                return [
                    'success' => 0,
                    'valid' => false,
                    'message' => 'Usuario no encontrado o inactivo',
                    'code' => 401,
                ];
            }

            // 5. Validar estado del usuario
            $userData = self::$currentUserData['user'];
            if (!isset($userData['status']) || (int) $userData['status'] !== 1) {
                if ($debug) {
                    Loggin::error(
                        '[GlobalMiddleware::checkSession] Usuario inactivo ID: ' . $userId,
                        'GlobalMiddleware',
                    );
                }

                return $this->endSession('cuenta_desactivada', 'Cuenta de usuario desactivada');
            }

            // 5b. Empresa suspendida: sus usuarios quedan fuera, salvo el superadministrador, que es
            // quien administra las empresas. `empresa_estado` lo entrega Users::findWithCompany()
            if (
                ($userData['role'] ?? '') !== 'superadmin'
                && array_key_exists('empresa_estado', $userData)
                && !(bool) $userData['empresa_estado']
            ) {
                return $this->endSession('empresa_suspendida', 'La empresa está suspendida');
            }

            // 6. Comprobar la expiración de la contraseña
            $passwordExpired = $this->isPasswordExpired(
                dateExpiration: self::$currentUserData['user']['expiration_pass'] ?? null,
            );

            // 7. Contraseña vencida: solo el perfil y el cambio de contraseña. Las páginas llevan al
            // perfil y la API responde 403, para que el bloqueo no se salte llamando a la API
            $adminUrl = '/' . $config['admin']['custom_url'];
            $path = (string) parse_url($request->getUrl(), PHP_URL_PATH);
            if (
                $passwordExpired
                && !in_array($path, [$adminUrl . '/perfiluser', $adminUrl . '/login/resetPassByUser'], true)
            ) {
                if (!$request->isApiCall()) {
                    Response::redirect($adminUrl . '/perfiluser');
                    Output::halt();
                }

                return [
                    'success' => 0,
                    'password_expired' => true,
                    'message' => 'Tu contraseña venció: cámbiala en tu perfil para continuar',
                    'code' => 403,
                ];
            }

            // 8. Guardar el estado de expiración para que esté disponible en el controlador y la vista
            self::$currentUserData['password_expired'] = $passwordExpired;

            if ($debug) {
                Loggin::info(
                    '[GlobalMiddleware::checkSession] Sesión válida para usuario: ' . $userId,
                    'GlobalMiddleware',
                );
            }

            return true;
        } catch (Exception $exception) {
            Loggin::error(
                '[GlobalMiddleware::checkSession] Error inesperado: ' . $exception->getMessage(),
                'GlobalMiddleware',
            );

            // En caso de error inesperado, limpiar sesión por seguridad
            $session->destroy();

            if (!$request->isApiCall()) {
                Response::redirect('/' . $config['admin']['custom_url'] . '/login');
                Output::halt();
            }

            return [
                'success' => 0,
                'valid' => false,
                'message' => 'Error interno de autenticación',
                'code' => 500,
            ];
        }
    }

    /**
     * Validates the CSRF token in the request.
     *
     * @return array|null returns an array with error details if the CSRF token is invalid or missing, or null if the token is valid
     */
    public function validateCSRFToken($slug = null): true|array
    {
        global $session, $request, $debug;

        // El primero que sea texto, en orden de prioridad. Con JSON llegan otros tipos (0, true, [...]):
        // no cuentan como token, y nunca terminan en hash_equals() con un error 500
        $token = null;
        foreach ([
            $request->get('_csrf_token'),
            $request->get('csrf_token'),
            $this->getCSRFTokenWithPrefix($request),
            $request->getHeader('X-CSRF-Token'),
        ] as $candidate) {
            if (!is_string($candidate) || $candidate === '') {
                continue;
            }

            $token = $candidate;
            break;
        }

        if ($token === null) {
            return [
                'success' => 0,
                'message' => 'los datos enviados no son correctos',
                'errors' => [
                    'error' => 'El formulario no es valido, refresque y vuelva a intentarlo nuevamente...',
                ],
                'code' => 403,
            ];
        }

        if (!Functions::validateCSRFToken($session->get('csrf_token'), $token)) {
            return [
                'success' => 0,
                'message' => 'los datos enviados no son correctos',
                'errors' => [
                    'error' => 'Algo no salió bien, Refresque y vuelva a intentarlo nuevamente...',
                ],
                'code' => 403,
            ];
        }

        return true;
    }

    public function onlyAdmin(): ?bool
    {
        global $session, $debug, $config;

        $iduser = (int) $session->get('id_user');
        $user = $this->currentUser($iduser);
        if ($user === []) {
            Response::redirect('/' . $config['admin']['custom_url'] . '/login');
            Output::halt();
        }

        if ($user['role'] !== 'admin' && $user['role'] !== 'superadmin') {
            $this->denyAccess();
        }

        return null;
    }

    public function onlySuperAdmin(): ?bool
    {
        global $session, $debug, $config;

        $iduser = (int) $session->get('id_user');
        $user = $this->currentUser($iduser);
        if ($user === []) {
            Response::redirect('/' . $config['admin']['custom_url'] . '/login');
            Output::halt();
        }

        if ($user['role'] !== 'superadmin') {
            $this->denyAccess();
        }

        return null;
    }

    public function onlyUserWithAccess(): void
    {
        global $session, $request, $config;

        $iduser = (int) $session->get('id_user');
        $user = $this->currentUser($iduser);
        if ($user === []) {
            Response::redirect('/' . $config['admin']['custom_url'] . '/login');
            Output::halt();
        }

        if ($user['role'] === 'admin' || $user['role'] === 'superadmin') {
            return;
        }

        $url = self::panelPath((string) $request->getUrl(), (string) ($config['admin']['custom_url'] ?? 'admin'));
        $menuUser = (new Dashboard())->getMenuUser($iduser);
        foreach ($menuUser as $value) {
            if ($value['url_menu'] !== $url && $value['url_submenu'] !== $url) {
                continue;
            }

            return;
        }

        Response::redirect('/e404');
    }

    /**
     * La ruta de la petición tal como se guarda en el menú: relativa al panel y sin query string.
     * `/admin/tableros?x=1` y `/{custom_url}/tableros` dan `/tableros`.
     */
    public static function panelPath(string $requestUri, string $customUrl = 'admin'): string
    {
        $path = (string) parse_url($requestUri, PHP_URL_PATH);
        foreach (array_unique(['/admin', '/' . trim($customUrl, '/')]) as $prefijo) {
            if (!str_starts_with($path, $prefijo . '/')) {
                continue;
            }

            $relativa = rtrim(substr($path, strlen($prefijo)), '/');

            return $relativa === '' ? '/' : $relativa;
        }

        return $path;
    }

    public function validatePolicyPassword(#[SensitiveParameter] $password): bool
    {
        global $config;

        // Un campo ausente o que no es texto no cumple la política (y no debe provocar un 500)
        if (!is_string($password)) {
            return false;
        }

        // politicas de contraseñas por defecto
        $large = $config['auth']['password_policy']['large'] ?? 8;
        $uppercase = $config['auth']['password_policy']['uppercase'] ?? false;
        $lowercase = $config['auth']['password_policy']['lowercase'] ?? false;
        $number = $config['auth']['password_policy']['number'] ?? false;
        $specialChars = $config['auth']['password_policy']['special_chars'] ?? false;

        if (strlen($password) < $large) {
            return false;
        }

        if ($uppercase && !preg_match('/[A-Z]/', $password)) {
            return false;
        }

        if ($lowercase && !preg_match('/[a-z]/', $password)) {
            return false;
        }

        if ($number && !preg_match('/\d/', $password)) {
            return false;
        }

        return !$specialChars || preg_match('/[^a-zA-Z\d]/', $password) === 1;
    }

    public function getMessagePolicyPassword(): string
    {
        global $config;

        // politicas de contraseñas por defecto
        $large = $config['auth']['password_policy']['large'] ?? 8;
        $uppercase = $config['auth']['password_policy']['uppercase'] ?? false;
        $lowercase = $config['auth']['password_policy']['lowercase'] ?? false;
        $number = $config['auth']['password_policy']['number'] ?? false;
        $specialChars = $config['auth']['password_policy']['special_chars'] ?? false;

        $message = 'La contraseña debe tener al menos ' . $large . ' caracteres';

        if ($uppercase) {
            $message .= ', una letra mayúscula';
        }

        if ($lowercase) {
            $message .= ', una letra minúscula';
        }

        if ($number) {
            $message .= ', un número';
        }

        if ($specialChars) {
            $message .= ', un carácter especial';
        }

        return $message;
    }

    public function onlyDebug(): void
    {
        global $debug, $config;
        if (!$debug) {
            $this->denyAccess();
        }
    }

    // =====================================================
    // RATE LIMITING METHODS - Funcionalidad global
    // =====================================================

    /**
     * Rate limiting para intentos de login.
     */
    public function checkLoginRateLimit(): array|true
    {
        return RateLimitMiddleware::checkLoginLimit();
    }

    /**
     * Password expiration validation.
     */
    public function isPasswordExpired($dateExpiration): bool
    {
        if ($dateExpiration === null || $dateExpiration === '') {
            return true;
        }

        $expiration = strtotime($dateExpiration);
        $today = strtotime(date('Y-m-d H:i:s'));

        return $expiration < $today;
    }

    /** @internal Testing\TestClient lo llama entre peticiones: este estado vale para una sola petición. */
    public static function resetRequestState(): void
    {
        self::$currentUserData = null;
    }

    /**
     * Cierra la sesión por el estado de la cuenta. Una página vuelve al login con el motivo
     * (`?motivo=`), para que el usuario sepa por qué; la API responde 403.
     *
     * @return array{success: int, valid: false, message: string, code: int}
     */
    private function endSession(string $motivo, string $message): array
    {
        global $session, $request, $config, $cookie;

        // Como el logout: el JWT de la cookie queda revocado. Sin esto, el login lo vería como una
        // sesión abierta y devolvería al panel, que volvería a cerrarla: un bucle de redirecciones
        $session->revokeToken($cookie?->get('tknHash'));
        $cookie?->delete('tknHash');
        $session->destroy();
        if (!$request->isApiCall()) {
            Response::redirect('/' . $config['admin']['custom_url'] . '/login?motivo=' . $motivo);
            Output::halt();
        }

        return ['success' => 0, 'valid' => false, 'message' => $message, 'code' => 403];
    }

    /**
     * Usuario de la petición: el que ya cargó checkSession o, si no se ejecutó antes, desde la BD.
     *
     * @return array<string, mixed>
     */
    private function currentUser(int $idUser): array
    {
        $loaded = self::$currentUserData['user'] ?? null;

        return is_array($loaded) && (int) ($loaded['id'] ?? 0) === $idUser
            ? $loaded
            : (new Users())->findWithCompany($idUser);
    }

    /**
     * Sin permiso: una navegación vuelve al panel; una petición que modifica datos recibe 403.
     */
    private function denyAccess(): never
    {
        global $request, $config;

        // Una llamada de la API (también un GET con fetch) recibe 403: una redirección la haría
        // terminar en el HTML del dashboard con 200. Solo una página se redirige
        if ($request->isApiCall() || strtoupper((string) $request->getMethod()) !== 'GET') {
            Response::json(['success' => 0, 'message' => 'No tiene permisos para realizar esta acción'], 403);
            Output::halt();
        }

        Response::redirect('/' . $config['admin']['custom_url'] . '/dashboard');
        Output::halt();
    }

    /**
     * Busca campos que inicien con '_csrf_token'.
     */
    private function getCSRFTokenWithPrefix($request): ?string
    {
        $allParams = $request->getAllParams();

        foreach ($allParams as $key => $value) {
            if (!(str_starts_with((string) $key, '_csrf_token') && is_string($value) && $value !== '')) {
                continue;
            }

            return $value;
        }

        return null;
    }
}
