<?php

declare(strict_types=1);

namespace versaWYS\kernel\cli;

use RuntimeException;
use Throwable;
use versaWYS\kernel\cli\commands\CheckDeployCommand;
use versaWYS\kernel\helpers\FilesFunction;

class ConfigManager
{
    private const string LEGACY_DIR = 'versaWYS/kernel/config';

    /** Claves que el kernel ya no lee, además de las de CheckDeployCommand::DEAD_KEYS. */
    private const array REMOVED_KEYS = [
        'security.sanitize_input' => 'se eliminó en 3.0',
        'logging.use_redis' => 'los logs en Redis se eliminaron en 3.0',
    ];

    /** Mapas que se toman enteros: completarlos con los valores por defecto cambiaría su efecto. */
    private const array WHOLE_MAPS = ['permissions_policy', 'rate_limiting.rules'];

    /**
     * Carpeta del proyecto con config.json, installed.lock e install.token. Los proyectos que
     * nacieron con el kernel copiado la tienen dentro de él: se respeta mientras exista.
     */
    public static function dir(): string
    {
        return is_file(self::LEGACY_DIR . '/config.json') ? self::LEGACY_DIR : 'versaWYS/config';
    }

    /**
     * Ruta del archivo de configuración. VERSAWYS_CONFIG permite usar otro (tests, staging).
     */
    public static function path(): string
    {
        $path = getenv('VERSAWYS_CONFIG');

        return is_string($path) && $path !== '' ? $path : self::dir() . '/config.json';
    }

    /**
     * Valores por defecto: config.json solo declara lo que cambia.
     *
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return require __DIR__ . '/../config/config.default.php';
    }

    public static function getConfig(): array
    {
        if (!file_exists(self::path())) {
            return [];
        }

        $config = file_get_contents(self::path());

        return json_decode($config, true) ?? [];
    }

    public static function saveConfig(array $config): void
    {
        $config = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        file_put_contents(self::path(), $config);
        chmod(self::path(), 0o660);
    }

    public static function setDebug($params): void
    {
        if (!isset($params)) {
            CLIErrorHandler::warning('Error: Debe especificar un valor para debug');
            exit(1);
        }

        if (!in_array($params, ['true', 'false'], strict: true)) {
            CLIErrorHandler::warning('Error: El valor de debug debe ser true o false');
            exit(1);
        }

        $config = self::getConfig();
        $debugValue = $params === 'true';
        $config['build']['debug'] = $debugValue;
        self::saveConfig($config);

        $status = $debugValue ? 'activado' : 'desactivado';
        CLIErrorHandler::success("Modo debug {$status} exitosamente.");
    }

    public static function setTemplateCache($params): void
    {
        if (!isset($params)) {
            CLIErrorHandler::warning('Error: Debe especificar un valor para templateCache');
            exit(1);
        }

        if (!in_array($params, ['true', 'false'], strict: true)) {
            CLIErrorHandler::warning('Error: El valor de templateCache debe ser true o false');
            exit(1);
        }

        $config = self::getConfig();
        $cacheValue = $params === 'true';
        $config['twig']['cache'] = $cacheValue;
        self::saveConfig($config);

        $status = $cacheValue ? 'activado' : 'desactivado';
        CLIErrorHandler::success("Cache de templates {$status} exitosamente.");
    }

    /**
     * Fusiona un JSON versionado en el proyecto (ajustes sin secretos: CSP, CORS, correo...) con el
     * config.json de esta máquina. Pensado como hook de despliegue: aplicarlo dos veces no cambia nada.
     */
    public static function merge(string $file): void
    {
        $extra = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        if (!is_array($extra)) {
            CLIErrorHandler::error("No se pudo leer un objeto JSON en '{$file}'.");
            CLIErrorHandler::$failed = true;

            return;
        }

        self::saveConfig(self::mergeArrays(self::getConfig(), $extra));
        CLIErrorHandler::success("Configuración de '{$file}' aplicada en " . self::path());
    }

    /**
     * Lleva config.json a la estructura actual del kernel sin cambiar cómo se comporta: agrega las claves
     * que faltan con el valor por defecto (el que ya se estaba usando), quita las que el kernel ya no lee
     * y conserva los valores y las claves propias del proyecto, en el orden de config.default.php.
     * Deja una copia del archivo original. Con --dry-run solo informa.
     */
    public static function align(): void
    {
        $actual = is_file(self::path()) ? json_decode((string) file_get_contents(self::path()), true) : null;
        if (!is_array($actual)) {
            CLIErrorHandler::error('No se pudo leer ' . self::path() . ' como JSON.');
            CLIErrorHandler::$failed = true;

            return;
        }

        $defaults = self::currentDefaults();
        $agregadas = [];
        $alineado = self::alignArrays($defaults, $actual, '', $agregadas);

        $quitadas = [];
        foreach (array_keys(CheckDeployCommand::DEAD_KEYS + self::REMOVED_KEYS) as $clave) {
            if (!self::unsetPath($alineado, explode('.', $clave))) {
                continue;
            }

            $quitadas[] = $clave;
        }

        CLIErrorHandler::info(sprintf(
            '%d clave(s) agregadas con su valor por defecto, %d quitadas.',
            count($agregadas),
            count($quitadas),
        ));
        foreach ($agregadas as $clave) {
            echo "  + {$clave}\n";
        }

        foreach ($quitadas as $clave) {
            echo "  - {$clave}\n";
        }

        if (($alineado['csp']['nonce'] ?? false) !== true) {
            CLIErrorHandler::info(
                'csp.nonce está en false: el panel base ya funciona con nonce; actívalo para una CSP más estricta.',
            );
        }

        if (in_array('--dry-run', $_SERVER['argv'] ?? [], true)) {
            CLIErrorHandler::info('--dry-run: no se modificó ' . self::path());

            return;
        }

        if ($alineado === $actual) {
            CLIErrorHandler::success('config.json ya tiene la estructura actual.');

            return;
        }

        // La copia lleva los mismos secretos que config.json: mismos permisos y junto a él
        $copia = self::path() . '.' . date('Ymd-His') . '.bak';
        copy(self::path(), $copia);
        chmod($copia, 0o600);
        self::saveConfig($alineado);
        CLIErrorHandler::success(
            'config.json alineado. Copia del original: ' . $copia . ' (bórrala cuando confirmes que todo funciona).',
        );
    }

    /**
     * Como array_replace_recursive, pero una lista (["a", "b"]) se reemplaza entera: mezclarla por
     * posición dejaría orígenes de CSP viejos al final.
     *
     * @param array<mixed> $base
     * @param array<mixed> $extra
     *
     * @return array<mixed>
     */
    public static function mergeArrays(array $base, array $extra): array
    {
        foreach ($extra as $key => $value) {
            $base[$key] = is_array($value) && !array_is_list($value) && is_array($base[$key] ?? null)
                ? self::mergeArrays($base[$key], $value)
                : $value;
        }

        return $base;
    }

    public static function clearCache(): void
    {
        $config = self::getConfig();

        $pathCahe = $config['twig']['compiled_dir'] ?? 'app/templates/.cache';

        if (str_starts_with($pathCahe, './')) {
            $pathCahe = substr($pathCahe, 2);
        }

        FilesFunction::destroyAllDir($pathCahe);

        CLIHelper::ensureDirectory($pathCahe, 0o775);

        CLIErrorHandler::success('Cache eliminado');
    }

    public static function initConfig(): void
    {
        if (file_exists(self::path())) {
            CLIErrorHandler::info('El archivo de configuración ya existe en: ' . self::path());

            return;
        }

        // Misma fuente que usa el runtime para los valores por defecto: no pueden divergir
        $defaults = self::currentDefaults();
        $defaults['session']['key'] = bin2hex(random_bytes(32));
        // Y exigen el nonce en los <script> inline: el panel base ya lo usa en todas sus plantillas
        $defaults['csp']['nonce'] = true;
        // Y sin eval: el panel base no compila plantillas en el navegador
        $defaults['csp']['unsafe_eval'] = false;
        $template = json_encode($defaults, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        // Asegurarse de que el directorio de configuración exista
        $configPath = self::path();
        /** @var string $configDir */
        $configDir = dirname($configPath);

        try {
            CLIHelper::ensureDirectory($configDir, 0o775);

            if (file_put_contents(self::path(), $template) !== false) {
                chmod(self::path(), 0o660);
                CLIErrorHandler::success('Archivo de configuración creado exitosamente en: ' . self::path());

                // Token de un solo uso que exige el instalador web (/install)
                $token = bin2hex(random_bytes(16));
                file_put_contents($configDir . '/install.token', $token);
                chmod($configDir . '/install.token', 0o660);
                CLIErrorHandler::info('Token para el instalador web (/install): ' . $token);
            } else {
                throw new RuntimeException('No se pudo escribir el archivo');
            }
        } catch (Throwable $throwable) {
            CLIErrorHandler::warning(
                'Error: No se pudo crear el archivo de configuración: ' . $throwable->getMessage(),
            );
        }
    }

    /**
     * Valores por defecto sin las claves que el kernel ya no lee: lo que escribe config:init y a lo que
     * lleva config:align.
     *
     * @return array<mixed>
     */
    private static function currentDefaults(): array
    {
        $defaults = self::defaults();
        foreach (array_keys(CheckDeployCommand::DEAD_KEYS + self::REMOVED_KEYS) as $clave) {
            self::unsetPath($defaults, explode('.', $clave));
        }

        return $defaults;
    }

    /**
     * @param array<mixed> $defaults
     * @param array<mixed> $actual
     * @param list<string> $agregadas
     *
     * @return array<mixed>
     */
    private static function alignArrays(array $defaults, array $actual, string $ruta, array &$agregadas): array
    {
        $resultado = [];
        foreach ($defaults as $clave => $valor) {
            $actualRuta = $ruta === '' ? (string) $clave : $ruta . '.' . $clave;
            if (!array_key_exists($clave, $actual)) {
                $resultado[$clave] = $valor;
                $agregadas[] = $actualRuta;

                continue;
            }

            $resultado[$clave] = is_array($valor)
            && $valor !== []
            && !array_is_list($valor)
            && is_array($actual[$clave])
            && !in_array($actualRuta, self::WHOLE_MAPS, true)
                ? self::alignArrays($valor, $actual[$clave], $actualRuta, $agregadas)
                : $actual[$clave];
        }

        // Las claves propias del proyecto se conservan, al final de su sección
        return $resultado + array_diff_key($actual, $defaults);
    }

    /**
     * @param array<mixed> $config
     * @param list<string> $ruta
     */
    private static function unsetPath(array &$config, array $ruta): bool
    {
        $clave = array_shift($ruta);
        if ($clave === null || !array_key_exists($clave, $config)) {
            return false;
        }

        if ($ruta === []) {
            unset($config[$clave]);

            return true;
        }

        if (!is_array($config[$clave])) {
            return false;
        }

        $quitada = self::unsetPath($config[$clave], $ruta);
        // Una sección que queda vacía porque todo lo suyo estaba obsoleto (p. ej. security) se quita
        if ($quitada && $config[$clave] === []) {
            unset($config[$clave]);
        }

        return $quitada;
    }
}
