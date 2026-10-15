<?php

declare(strict_types=1);

namespace versaWYS\kernel;

/**
 * Validación (y permiso) de una petición, en su propia clase. Se pide como parámetro del método del
 * controlador y el contenedor la valida antes de llamarlo: si falla, responde 422 con los errores por campo
 * (o 403 si authorize() es false) y el método no se ejecuta.
 *
 *     final class GuardarTablero extends FormRequest
 *     {
 *         public function rules(): array
 *         {
 *             return ['nombre' => 'required|max:120', 'color' => 'nullable|min:7|max:7'];
 *         }
 *     }
 *
 *     public function crear(GuardarTablero $datos): array
 *     {
 *         $id = $this->modelo->guardar($this->idEmpresa, $datos->validated());
 */
abstract class FormRequest
{
    /** @var array<array-key, mixed> */
    private array $validated = [];

    public function __construct(
        protected readonly Request $request,
    ) {}

    /** @return array<string, mixed> las mismas reglas que Validator */
    abstract public function rules(): array;

    /** @return array<string, string> `campo.regla` o `campo` => mensaje */
    public function messages(): array
    {
        return [];
    }

    public function authorize(): bool
    {
        return true;
    }

    /** @internal Lo llama el contenedor al crearla. */
    public function validateResolved(): void
    {
        if (!$this->authorize()) {
            Response::json([
                'success' => 0,
                'message' => 'No tiene permisos para realizar esta acción',
                'code' => 403,
            ], 403);
            Output::halt();
        }

        $validator = Validator::make($this->request->getAllParams(), $this->rules(), $this->messages());
        if ($validator->fails()) {
            Response::json([
                'success' => 0,
                'message' => 'Los datos enviados no son correctos',
                'errors' => $validator->errors(),
                'code' => 422,
            ], 422);
            Output::halt();
        }

        $this->validated = $validator->validated();
    }

    /**
     * Solo los campos que tienen reglas, tal como llegaron.
     *
     * @return array<array-key, mixed>
     */
    public function validated(): array
    {
        return $this->validated;
    }

    /** Un campo validado (`cliente.email` para los anidados). */
    public function input(string $key, mixed $default = null): mixed
    {
        $value = $this->validated;
        foreach (explode('.', $key) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return $default;
            }

            $value = $value[$part];
        }

        return $value;
    }
}
