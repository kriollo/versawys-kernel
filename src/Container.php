<?php

declare(strict_types=1);

namespace versaWYS\kernel;

use Closure;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionType;
use RuntimeException;

/**
 * Contenedor de dependencias. Optativo: un controlador sin constructor tipado se crea igual que con `new`.
 *
 * - Constructores: los parámetros tipados con una clase se resuelven solos (por tipo, recursivamente).
 * - Métodos de controladores y middlewares (`call`): los parámetros con una clase vienen del contenedor y
 *   los demás reciben los parámetros de la ruta, en orden.
 * - `bind` crea un objeto nuevo cada vez; `singleton` uno solo por proceso; `instance` registra uno hecho.
 *   La aplicación registra los suyos en `app/container.php` (ver documentacion/Contenedor.md del esqueleto).
 */
final class Container
{
    private static ?self $global = null;

    /** @var array<string, array{factory: Closure(self): object, shared: bool}> */
    private array $bindings = [];

    /** @var array<string, object> */
    private array $instances = [];

    /** @var array<string, true> clases que se están resolviendo: para detectar dependencias circulares */
    private array $resolving = [];

    public static function getInstance(): self
    {
        return self::$global ??= new self();
    }

    /** @param Closure(self): object $factory */
    public function bind(string $id, Closure $factory): void
    {
        unset($this->instances[$id]);
        $this->bindings[$id] = ['factory' => $factory, 'shared' => false];
    }

    /** @param Closure(self): object $factory */
    public function singleton(string $id, Closure $factory): void
    {
        unset($this->instances[$id]);
        $this->bindings[$id] = ['factory' => $factory, 'shared' => true];
    }

    /** Registra un objeto ya creado; en pruebas, para reemplazar un servicio por un doble. */
    public function instance(string $id, object $object): void
    {
        $this->instances[$id] = $object;
    }

    /** Quita lo registrado para `$id` (en pruebas, para devolver el servicio real después de un doble). */
    public function forget(string $id): void
    {
        unset($this->instances[$id], $this->bindings[$id]);
    }

    public function has(string $id): bool
    {
        return isset($this->instances[$id]) || isset($this->bindings[$id]) || class_exists($id);
    }

    /**
     * @template T of object
     *
     * @param class-string<T>|string $id
     *
     * @return ($id is class-string<T> ? T : object)
     */
    public function get(string $id): object
    {
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }

        if (isset($this->bindings[$id])) {
            $object = $this->bindings[$id]['factory']($this);
            if ($this->bindings[$id]['shared']) {
                $this->instances[$id] = $object;
            }

            return $object;
        }

        return $this->make($id);
    }

    /**
     * Crea un objeto resolviendo su constructor, sin mirar los registros de `$class` mismo.
     *
     * @template T of object
     *
     * @param class-string<T>|string $class
     *
     * @return ($class is class-string<T> ? T : object)
     */
    public function make(string $class): object
    {
        if (!class_exists($class) && !interface_exists($class)) {
            throw new RuntimeException(
                "No se puede crear '{$class}': la clase no existe y no está registrada en el contenedor.",
            );
        }

        if (isset($this->resolving[$class])) {
            throw new RuntimeException(
                'Dependencia circular: ' . implode(' → ', array_keys($this->resolving)) . " → {$class}",
            );
        }

        $reflection = new ReflectionClass($class);
        if (!$reflection->isInstantiable()) {
            throw new RuntimeException(
                "No se puede crear '{$class}': es una interfaz o clase abstracta sin registrar en el contenedor.",
            );
        }

        $constructor = $reflection->getConstructor();
        if ($constructor === null || $constructor->getNumberOfParameters() === 0) {
            return new $class();
        }

        $this->resolving[$class] = true;

        try {
            $args = array_map(fn(ReflectionParameter $p): mixed => $this->resolveParameter(
                $p,
                $class,
            ), $constructor->getParameters());
        } finally {
            unset($this->resolving[$class]);
        }

        return $reflection->newInstanceArgs($args);
    }

    /**
     * Llama un método de controlador o middleware: lo tipado con una clase viene del contenedor y el resto
     * recibe, en orden, los parámetros de la ruta (un `int` recibe el número; si no lo es, RouteParameterMismatch).
     *
     * @param array<array-key, string> $routeParams
     */
    public function call(object $object, string $method, array $routeParams = []): mixed
    {
        $routeParams = array_values($routeParams);
        $reflection = new ReflectionMethod($object, $method);
        $args = [];
        foreach ($reflection->getParameters() as $parameter) {
            $type = $parameter->getType();
            if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                $args[] = $this->resolveService($type->getName(), $parameter);

                continue;
            }

            if ($parameter->isVariadic()) {
                array_push($args, ...$routeParams);
                $routeParams = [];

                continue;
            }

            if ($routeParams === []) {
                if ($parameter->isDefaultValueAvailable()) {
                    $args[] = $parameter->getDefaultValue();

                    continue;
                }

                break; // Como hasta ahora: PHP reclama si faltan argumentos obligatorios
            }

            $args[] = self::castRouteParam(array_shift($routeParams), $type, $parameter);
        }

        // Un método sin parámetros declarados recibe igual los de la ruta, como antes del contenedor
        if ($reflection->getNumberOfParameters() === 0) {
            $args = $routeParams;
        }

        return $reflection->invokeArgs($object, $args);
    }

    /** @internal Solo para las pruebas del kernel. */
    public static function resetGlobal(): void
    {
        self::$global = null;
    }

    private function resolveParameter(ReflectionParameter $parameter, string $for): mixed
    {
        $type = $parameter->getType();
        if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
            return $this->resolveService($type->getName(), $parameter);
        }

        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }

        throw new RuntimeException(sprintf(
            "No se puede crear '%s': el parámetro \$%s no es una clase y no tiene valor por defecto. Regístralo en app/container.php.",
            $for,
            $parameter->getName(),
        ));
    }

    private function resolveService(string $class, ReflectionParameter $parameter): object
    {
        if (!$this->has($class) && $parameter->allowsNull()) {
            return $parameter->isDefaultValueAvailable() ? $parameter->getDefaultValue() : null;
        }

        return $this->get($class);
    }

    private static function castRouteParam(string $value, ?ReflectionType $type, ReflectionParameter $parameter): mixed
    {
        $name = $type instanceof ReflectionNamedType ? $type->getName() : 'mixed';

        return match ($name) {
            'int' => preg_match('/^-?\d+$/', $value) === 1
                ? (int) $value
                : throw new RouteParameterMismatch(
                    "El parámetro \${$parameter->getName()} espera un número: '{$value}'",
                ),
            'float' => is_numeric($value)
                ? (float) $value
                : throw new RouteParameterMismatch(
                    "El parámetro \${$parameter->getName()} espera un número: '{$value}'",
                ),
            default => $value,
        };
    }
}
