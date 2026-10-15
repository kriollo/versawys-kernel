<?php

declare(strict_types=1);

namespace versaWYS\kernel\cli;

use Exception;

class FrontManager
{
    private static string $templateTwig = 'app/templates/dashboard/';

    private static string $componentVue = 'src/dashboard/js/';

    public static function createTwig(string $moduleName, string $prefix = ''): bool
    {
        $path = self::$templateTwig . strtolower($moduleName);
        $templateTWIG = $path . '/' . $prefix . ucfirst($moduleName) . '.twig';

        CLIErrorHandler::info("Creando template {$moduleName}...");

        try {
            CLIHelper::ensureDirectory($path, 0o775);
        } catch (Exception $exception) {
            CLIErrorHandler::warning($exception->getMessage());

            return false;
        }

        if (file_exists($templateTWIG)) {
            if (!CLIHelper::confirm("El template {$templateTWIG} ya existe. Desea sobreescribirlo?")) {
                CLIErrorHandler::info('Operación cancelada.');

                return false;
            }

            unlink($templateTWIG);
        }

        $template = <<<'EOT'
            {% extends "dashboard/dashboard.twig" %}
            {% block appHeader %}{% endblock %}
            {% block appDashboard %}{% endblock %}
            {% block appScript %}{% endblock %}
            EOT;

        if (file_put_contents($templateTWIG, $template)) {
            chmod($templateTWIG, 0o775);
            CLIErrorHandler::success("Template {$templateTWIG} creado.");

            return true;
        }

        CLIErrorHandler::warning("No se pudo crear el archivo: {$templateTWIG}");

        return false;
    }

    public static function createVue(string $moduleName, string $prefix = ''): bool
    {
        $path = self::$componentVue . strtolower($moduleName);
        $componentVue = $path . '/' . $prefix . ucfirst($moduleName) . '.vue';

        CLIErrorHandler::info("Creando componente Vue {$moduleName}...");

        try {
            CLIHelper::ensureDirectory($path, 0o775);
        } catch (Exception $exception) {
            CLIErrorHandler::warning($exception->getMessage());

            return false;
        }

        if (file_exists($componentVue)) {
            if (!CLIHelper::confirm("El componente Vue {$componentVue} ya existe. Desea sobreescribirlo?")) {
                CLIErrorHandler::info('Operación cancelada.');

                return false;
            }

            unlink($componentVue);
        }

        // Módulo del panel listo para crecer: TypeScript, migas de pan y cómo llamar a la API.
        // versaFetch envía el token CSRF en las peticiones que modifican datos.
        $component = <<<'EOT'
            <script setup lang="ts">
                import { inject, onMounted, ref } from 'vue';

                import breadcrumb from '@/dashboard/js/components/breadcrumb.vue';
                import { showErrorResponse, versaFetch } from '@/dashboard/js/functions';

                const panelUrl = inject<string>('panelUrl', 'admin');
                const breadCrumb = [
                    { type: 'link', title: 'Home', icon: '', link: `/${panelUrl}/dashboard` },
                    { type: 'text', title: '$moduleTitle', icon: '', link: '' },
                ];

                const items = ref<Record<string, unknown>[]>([]);

                onMounted(async () => {
                    // Ruta de ejemplo: agrégala en app/routes/ junto a la de esta pantalla
                    const res = await versaFetch({ url: `/${panelUrl}/$moduleName/api/list`, method: 'GET' });
                    if (!res.success) return showErrorResponse(res);
                    items.value = res.data ?? [];
                });
            </script>
            <template>
                <div class="flex h-full w-full flex-col">
                    <breadcrumb title="$moduleTitle" :items="breadCrumb" />
                    <div class="mx-4 flex-1 overflow-y-auto">
                        <p v-if="items.length === 0" class="text-gray-500 dark:text-gray-400">Sin datos todavía.</p>
                        <ul>
                            <li v-for="(item, i) in items" :key="i" class="text-gray-900 dark:text-white">{{ item }}</li>
                        </ul>
                    </div>
                </div>
            </template>

            EOT;

        $component = str_replace(
            ['$moduleName', '$moduleTitle'],
            [strtolower($moduleName), ucfirst($moduleName)],
            $component,
        );

        if (file_put_contents($componentVue, $component)) {
            chmod($componentVue, 0o775);
            CLIErrorHandler::success("Componente Vue {$componentVue} creado.");

            return true;
        }

        CLIErrorHandler::warning("No se pudo crear el archivo: {$componentVue}");

        return false;
    }
}
