<?php

declare(strict_types=1);

namespace versaWYS\kernel\Tests\Fixtures;

use Override;
use versaWYS\kernel\FormRequest;

final class GuardarCosa extends FormRequest
{
    public static bool $permitir = true;

    #[Override]
    public function rules(): array
    {
        return ['nombre' => 'required|max:10', 'tags.*' => 'string'];
    }

    #[Override]
    public function authorize(): bool
    {
        return self::$permitir;
    }
}
