<?php

declare(strict_types=1);

namespace versaWYS\kernel;

use Closure;
use DateTime;
use Exception;
use Throwable;
use versaWYS\kernel\helpers\Functions;

/**
 * Valida los datos de una petición sin modificarlos.
 *
 *     $v = Validator::make($datos, [
 *         'nombre' => 'required|max:120',
 *         'cliente.email' => ['required', 'email'],            // datos anidados
 *         'items' => 'required|array',
 *         'items.*.cantidad' => 'required|int:positive',       // cada elemento del arreglo
 *         'rut' => ['required', static fn(string $campo, mixed $valor): ?string => ... ],  // regla propia
 *     ], ['nombre.required' => 'Ponle un nombre']);
 *     if ($v->fails()) { return [..., 'errors' => $v->errors(), 'code' => 422]; }
 *
 * Un solo mensaje por campo: el de la primera regla que falla. Las reglas están en documentacion/Validacion.md.
 */
final class Validator
{
    /** @var array<string, string> */
    private array $errors = [];

    /**
     * @param array<array-key, mixed> $data
     * @param array<string, list<Closure(string, mixed): ?string|string>|string> $rules
     * @param array<string, string> $messages `campo.regla` o `campo` => mensaje
     */
    private function __construct(
        private readonly array $data,
        private readonly array $rules,
        private readonly array $messages,
    ) {
        $this->run();
    }

    /**
     * @param array<array-key, mixed> $data
     * @param array<string, list<Closure(string, mixed): ?string|string>|string> $rules
     * @param array<string, string> $messages
     */
    public static function make(array $data, array $rules, array $messages = []): self
    {
        return new self($data, $rules, $messages);
    }

    public function fails(): bool
    {
        return $this->errors !== [];
    }

    /** @return array<string, string> campo => mensaje (con la ruta completa en los anidados: `items.0.cantidad`) */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * Solo los campos que tienen reglas, con su valor tal como llegó (nunca lo modifica).
     *
     * @return array<array-key, mixed>
     */
    public function validated(): array
    {
        $out = [];
        foreach (array_keys($this->rules) as $field) {
            foreach ($this->expand($field) as $path) {
                $exists = false;
                $value = self::dataGet($this->data, $path, $exists);
                if ($exists) {
                    self::dataSet($out, $path, $value);
                }
            }
        }

        return $out;
    }

    private function run(): void
    {
        foreach ($this->rules as $field => $rule) {
            $list = is_string($rule) ? explode('|', $rule) : $rule;
            foreach ($this->expand($field) as $path) {
                $error = $this->validateField($path, $list);
                if ($error !== null) {
                    $this->errors[$path] = $error;
                }
            }
        }
    }

    /** @param list<Closure(string, mixed): ?string|string> $rules */
    private function validateField(string $path, array $rules): ?string
    {
        $exists = false;
        $value = self::dataGet($this->data, $path, $exists);
        $exists = $exists && $value !== null;

        // nullable: si el campo no llega o viene vacío, las demás reglas no se aplican
        if (in_array('nullable', $rules, true)) {
            if (!$exists || $value === '') {
                return null;
            }

            $rules = array_values(array_filter($rules, static fn($r): bool => $r !== 'nullable'));
        }

        foreach ($rules as $rule) {
            if (!$exists) {
                return $this->message(
                    $path,
                    is_string($rule) ? explode(':', $rule, 2)[0] : 'required',
                    sprintf('El campo %s es obligatorio', $path),
                );
            }

            if ($rule instanceof Closure) {
                $error = $rule($path, $value);
                if ($error !== null) {
                    return $this->message($path, 'custom', $error);
                }

                continue;
            }

            [$name, $param] = array_pad(explode(':', $rule, 2), 2, null);
            $check = self::rules()[$name] ?? null;
            // Una regla mal escrita no puede dejar pasar el dato sin validar
            if ($check === null) {
                return sprintf('Regla de validación desconocida para %s: %s', $path, $name);
            }

            // Un valor de tipo inesperado (arreglo, null) es un dato inválido, no un error 500
            try {
                $error = $check($path, $value, $param);
            } catch (Throwable) {
                $error = sprintf('El campo %s no es valido', $path);
            }

            if ($error !== null) {
                return $this->message($path, (string) $name, $error);
            }
        }

        return null;
    }

    private function message(string $path, string $rule, string $default): string
    {
        // `items.*.cantidad.required` sirve para `items.3.cantidad`
        $generic = (string) preg_replace('/\.\d+(?=\.|$)/', '.*', $path);

        return (
            $this->messages["{$path}.{$rule}"]
            ?? $this->messages["{$generic}.{$rule}"]
            ?? $this->messages[$path]
            ?? $this->messages[$generic]
            ?? $default
        );
    }

    /**
     * `items.*.cantidad` → `items.0.cantidad`, `items.1.cantidad`… según los datos. Sin `*`, el campo tal cual.
     *
     * @return list<string>
     */
    private function expand(string $field): array
    {
        if (!str_contains($field, '*')) {
            return [$field];
        }

        [$before, $after] = explode('*', $field, 2);
        $prefix = rtrim($before, '.');
        $exists = false;
        $list = self::dataGet($this->data, $prefix, $exists);
        if (!is_array($list)) {
            return [];
        }

        $paths = [];
        foreach (array_keys($list) as $key) {
            foreach ($this->expand($prefix . '.' . $key . $after) as $path) {
                $paths[] = $path;
            }
        }

        return $paths;
    }

    /** @param array<array-key, mixed> $data */
    private static function dataGet(array $data, string $path, bool &$exists): mixed
    {
        $exists = false;
        $value = $data;
        foreach (explode('.', $path) as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return null;
            }

            $value = $value[$key];
        }

        $exists = true;

        return $value;
    }

    /** @param array<array-key, mixed> $data */
    private static function dataSet(array &$data, string $path, mixed $value): void
    {
        $ref = &$data;
        foreach (explode('.', $path) as $key) {
            if (!isset($ref[$key]) || !is_array($ref[$key])) {
                $ref[$key] = [];
            }

            $ref = &$ref[$key];
        }

        $ref = $value;
    }

    /** @return array<string, Closure(string, mixed, ?string): ?string> */
    private static function rules(): array
    {
        static $rules = null;
        if ($rules !== null) {
            return $rules;
        }

        $int = static function (string $field, mixed $value, ?string $param): ?string {
            $param = (string) $param;
            // Solo un entero o un texto con un entero: true o 1.0 no son "1" (type juggling)
            if (!is_int($value) && !is_string($value) || filter_var($value, FILTER_VALIDATE_INT) === false) {
                return sprintf('El campo %s debe ser un número entero', $field);
            }

            $value = (int) $value;
            if (str_contains($param, '-')) {
                [$min, $max] = explode('-', $param);

                return $value < $min || $value > $max
                    ? sprintf('El campo %s debe ser numerico entre %s y %s', $field, $min, $max)
                    : null;
            }

            if (str_contains($param, ',')) {
                $valid = explode(',', $param);

                // El valor ya es int y la lista es texto: se compara como números (antes nunca coincidía)
                return in_array($value, array_map(intval(...), $valid), strict: true)
                    ? null
                    : sprintf(
                        'El campo %s debe ser numerico y debe ser uno de los siguientes valores %s',
                        $field,
                        $param,
                    );
            }

            if (str_contains($param, 'positive')) {
                return $value > 0 ? null : sprintf('El campo %s debe ser un numero positivo', $field);
            }

            return null;
        };

        return $rules = [
            // Vacío es null, texto vacío o solo espacios y arreglo vacío. 0, "0" y false son valores
            'required' => static fn(string $field, mixed $value): ?string => $value === null
                || $value === []
                || is_string($value) && trim($value) === ''
                    ? sprintf('El campo %s es obligatorio', $field)
                    : null,
            'email' => static fn(string $field, mixed $value): ?string => filter_var($value, FILTER_VALIDATE_EMAIL)
                !== false
                    ? null
                    : sprintf('El campo %s no es un email valido', $field),
            'rut' => static fn(string $field, mixed $value): ?string => Functions::validarRut($value)
                ? null
                : sprintf('El campo %s no es un rut valido', $field),
            // Caracteres, no bytes: "José" mide 4
            // Un arreglo no tiene largo de texto: medirlo como "Array" daría 5 caracteres
            'min' => static fn(string $field, mixed $value, ?string $param): ?string => match (true) {
                !is_scalar($value) => sprintf('El campo %s debe ser texto', $field),
                mb_strlen((string) $value) < (int) $param => sprintf(
                    'El campo %s debe tener minimo %s caracteres',
                    $field,
                    $param,
                ),
                default => null,
            },
            'max' => static fn(string $field, mixed $value, ?string $param): ?string => match (true) {
                !is_scalar($value) => sprintf('El campo %s debe ser texto', $field),
                mb_strlen((string) $value) > (int) $param => sprintf(
                    'El campo %s debe tener maximo %s caracteres',
                    $field,
                    $param,
                ),
                default => null,
            },
            'numeric' => static fn(string $field, mixed $value): ?string => is_numeric($value)
                ? null
                : sprintf('El campo %s debe ser numerico', $field),
            'int' => $int,
            'integer' => $int,
            // Texto o número (un input numérico llega como número en JSON); nunca arreglo, objeto o booleano
            'string' => static fn(string $field, mixed $value): ?string => is_string($value)
                || is_int($value)
                || is_float($value)
                    ? null
                    : sprintf('El campo %s debe ser texto', $field),
            'time' => static fn(string $field, mixed $value): ?string => preg_match(
                '/^([01]?\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/',
                $value,
            ) === 1
                    ? null
                    : sprintf('El campo %s debe ser una hora valida', $field),
            'date' => static function (string $field, mixed $value): ?string {
                try {
                    new DateTime($value);

                    return null;
                } catch (Exception) {
                    return sprintf('El campo %s debe ser una fecha valida', $field);
                }
            },
            'array' => static fn(string $field, mixed $value): ?string => is_array($value)
                || is_string($value) && is_array(json_decode($value, true)) && json_last_error() === JSON_ERROR_NONE
                    ? null
                    : sprintf('El campo %s debe ser un arreglo', $field),
            'url' => static fn(string $field, mixed $value): ?string => filter_var($value, FILTER_VALIDATE_URL)
                !== false
                    ? null
                    : sprintf('El campo %s debe ser una url valida', $field),
            // in:activo,inactivo — uno de los valores listados, comparados como texto
            'in' => static fn(string $field, mixed $value, ?string $param): ?string => is_scalar($value)
                && in_array((string) $value, explode(',', (string) $param), true)
                    ? null
                    : sprintf('El campo %s debe ser uno de: %s', $field, str_replace(',', ', ', (string) $param)),
            // true/false, 1/0, "true"/"false", "1"/"0", "on"/"off" (checkbox de formulario)
            'boolean' => static fn(string $field, mixed $value): ?string => filter_var(
                $value,
                FILTER_VALIDATE_BOOL,
                FILTER_NULL_ON_FAILURE,
            ) !== null
                    ? null
                    : sprintf('El campo %s debe ser verdadero o falso', $field),
        ];
    }
}
