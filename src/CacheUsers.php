<?php

declare(strict_types=1);

namespace versaWYS\kernel;

use app\models\Dashboard;
use app\models\Empresas;
use app\models\Users;
use Throwable;

class CacheUsers
{
    private const MENU_VERSION_KEY = 'menu_version_timestamp';

    /** @var array<string, mixed> */
    protected array $cacheData = [];

    public function __construct()
    {
        global $session, $config;

        // Cargar datos del usuario con caché inteligente
        $this->loadUserDataWithCache($session, $config);
    }

    /**
     * @return array<string, mixed>
     */
    public function getUserData(int $userId, int $empresaId): array
    {
        global $session, $config;

        // Generar clave de caché única
        $cacheKey = sprintf('user_data_%d_%d', $userId, $empresaId);
        $cacheData = $session->get($cacheKey);
        if (!is_array($cacheData)) {
            return $this->loadFreshUserData($userId, $empresaId, $config);
        }

        return $cacheData;
    }

    /**
     * Actualiza la versión del menú. Debe llamarse cuando se editan perfiles o módulos.
     */
    public function updateMenuVersion(): void
    {
        // En la caché compartida, no en la sesión: así la ven todos los usuarios con sesión abierta,
        // no solo quien hizo el cambio. Un id único: dos cambios en el mismo segundo también cuentan
        Cache::set(self::MENU_VERSION_KEY, bin2hex(random_bytes(8)));
    }

    /**
     * Invalida manualmente el caché de un usuario específico
     * Útil cuando sabemos que los datos han cambiado.
     */
    public static function invalidateUserCache(int $userId, ?Session $sessionInstance = null): void
    {
        global $session;
        $sessionToUse = $sessionInstance ?? $session;

        if ($sessionToUse && $userId > 0) {
            // OPTIMIZACIÓN: Buscar y eliminar todas las claves de caché para un usuario específico,
            // sin importar el ID de la empresa. Esto asegura que todos los cachés se invaliden
            // de forma correcta y escalable, sin depender de bucles con límites fijos.
            $prefix = sprintf('user_data_%d_', $userId);
            $allSessionKeys = array_keys($sessionToUse->getAll());
            foreach ($allSessionKeys as $key) {
                if (!is_string($key) || !str_starts_with($key, $prefix)) {
                    continue;
                }

                $sessionToUse->delete($key);
            }
        }
    }

    /**
     * Invalida el caché de todos los usuarios
     * Útil cuando hay cambios globales (empresas, menús globales, etc.).
     */
    public static function invalidateAllUserCache(?Session $sessionInstance = null): void
    {
        global $session;
        $sessionToUse = $sessionInstance ?? $session;

        if ($sessionToUse) {
            // OPTIMIZACIÓN: Iterar sobre las claves de sesión existentes en lugar de bucles fijos.
            // Esto es mucho más eficiente, escalable y no depende de IDs hardcodeados.
            $allSessionKeys = array_keys($sessionToUse->getAll());
            foreach ($allSessionKeys as $key) {
                if (!is_string($key) || !str_starts_with($key, 'user_data_')) {
                    continue;
                }

                $sessionToUse->delete($key);
            }
        }
    }

    /**
     * Datos del usuario guardados en la sesión. Se recargan si pasaron 5 minutos, si cambió la huella
     * (el usuario o alguna empresa activa, ver fingerprint()) o si cambió la versión del menú.
     *
     * @param array<string, mixed> $config
     */
    private function loadUserDataWithCache(Session $session, array $config): void
    {
        $userId = (int) $session->get('id_user', 0);
        $empresaId = (int) $session->get('empresa_id', 0);

        $cacheKey = sprintf('user_data_%d_%d', $userId, $empresaId);
        $cacheData = $session->get($cacheKey);
        // La huella se toma antes de cargar los datos: un cambio a mitad de la carga se nota en la siguiente
        $fingerprint = $this->fingerprint($userId);
        $menuVersion = $this->getMenuVersion();
        if (
            is_array($cacheData)
            && time() <= ($cacheData['ttl'] ?? 0)
            && $fingerprint !== null
            && $fingerprint === ($cacheData['fingerprint'] ?? null)
            && $menuVersion === ($cacheData['menu_version'] ?? null)
        ) {
            return;
        }

        $cacheLocal = $this->loadFreshUserData($userId, $empresaId, $config);
        if (!isset($cacheLocal['user']) || !is_array($cacheLocal['user'])) {
            return;
        }

        $this->cacheData = [
            'user' => $cacheLocal['user'],
            'empresaSelected' => $cacheLocal['empresaSelected'],
            'empresas' => $cacheLocal['empresas'],
            'menu_user' => $cacheLocal['menu_user'],
            'id_user' => $cacheLocal['id_user'],
            'cached_at' => time(),
            'fingerprint' => $fingerprint,
            'menu_version' => $menuVersion,
            'ttl' => time() + 300,
        ];
        $session->set($cacheKey, $this->cacheData);
    }

    /**
     * Carga datos frescos de la base de datos (sin caché).
     *
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private function loadFreshUserData(int $userId, int $empresaId, array $config): array
    {
        $user = (new Users())->findWithCompany($userId);
        // Estos datos se guardan en la sesión y llegan a las plantillas y a las respuestas SPA:
        // nunca deben incluir secretos del usuario
        unset($user['password'], $user['restore_token']);
        $empresas = (new Empresas())->all('1');
        $empresaSelected = (new Empresas())->getEmpresaById($empresaId);

        $id_user = $userId;

        // Cargar menú según rol
        $menu_user = [];
        if ($user !== []) {
            $menu_user = ($user['role'] ?? null) === 'superadmin'
                ? (new Dashboard())->getMenuAdmin()
                : (new Dashboard())->getMenuUser($userId, (int) ($user['id_perfil'] ?? 0));
        }

        return [
            'user' => $user,
            'empresaSelected' => $empresaSelected,
            'empresas' => $empresas,
            'menu_user' => $menu_user,
            'id_user' => $id_user,
        ];
    }

    /**
     * Huella de lo que muestran los datos en caché, en una sola consulta: cuándo cambió el usuario y,
     * de las empresas activas, cuántas son, la suma de sus ids y su último cambio. store() de VersaORM
     * actualiza updated_at en cada UPDATE, así que editar, activar o desactivar cualquiera la cambia.
     * null si el usuario no existe o la consulta falla: entonces la caché no vale.
     */
    private function fingerprint(int $userId): ?string
    {
        try {
            $row = Users::getRow('SELECT u.updated_at, e.n, e.s, e.m FROM '
            . Users::tableName()
            . ' u CROSS JOIN (SELECT COUNT(id) AS n, SUM(id) AS s, MAX(updated_at) AS m FROM '
            . Empresas::tableName()
            . ' WHERE estado = ?) e WHERE u.id = ?', ['1', $userId]);
        } catch (Throwable) {
            return null;
        }

        return $row === null ? null : implode('|', array_map(strval(...), array_values($row)));
    }

    /**
     * Obtiene una "versión" del menú basada en última modificación.
     */
    private function getMenuVersion(): string
    {
        return (string) Cache::get(self::MENU_VERSION_KEY, 'initial');
    }
}
