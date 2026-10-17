<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LocalizationTest extends TestCase
{
    /**
     * lang/en is not published until RF-49, so the English strings that ship
     * with the framework are the reference for what Spanish must cover.
     */
    private const string FRAMEWORK_ENGLISH = 'vendor/laravel/framework/src/Illuminate/Translation/lang/en';

    public function test_default_locale_is_spanish(): void
    {
        $this->assertSame('es', app()->getLocale());
        $this->assertSame('en', app()->getFallbackLocale());
    }

    public function test_validation_messages_are_in_spanish(): void
    {
        $validator = Validator::make(['precio' => null], ['precio' => 'required']);

        $this->assertSame('El campo precio es obligatorio.', $validator->errors()->first('precio'));
    }

    #[DataProvider('languageFiles')]
    public function test_spanish_translations_cover_every_framework_line(string $file): void
    {
        $path = base_path(self::FRAMEWORK_ENGLISH."/{$file}.php");
        $this->assertFileExists($path);

        $english = array_filter(Arr::dot(require $path), is_string(...));
        $spanish = Arr::dot(require lang_path("es/{$file}.php"));

        $this->assertSame([], array_keys(array_diff_key($english, $spanish)));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function languageFiles(): array
    {
        return [
            'auth' => ['auth'],
            'pagination' => ['pagination'],
            'passwords' => ['passwords'],
            'validation' => ['validation'],
        ];
    }
}
