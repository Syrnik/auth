<?php
/**
 * @author Serge Rodovnichenko <serge@syrnik.com>
 * @copyright Serge Rodovnichenko, 2026
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Every template of the default theme has to compile under the framework's
 * Smarty (AUTH-985): a syntax error there is not tied to any data — the
 * page is dead for every visitor — yet nothing else in the suite renders
 * the theme, so it slipped through once already ({foreach (array)$x ...}
 * in four profile partials, a 500 on my/ for anyone signed in).
 *
 * Compiles only, without rendering: rendering needs each template's own
 * variables, compiling needs nothing and is exactly where that bug lived.
 */
class authThemeTemplatesCompileTest extends TestCase
{
    /**
     * @dataProvider templateProvider
     */
    public function testTemplateCompiles(string $path): void
    {
        /** @var waSmarty3View $view */
        $view = wa('auth')->getView();
        $template = $view->smarty->createTemplate('file:' . $path);

        // Throws SmartyCompilerException on a syntax error.
        $template->compileTemplateSource();

        $this->assertTrue($template->compiled->isCompiled);
    }

    public function templateProvider(): array
    {
        $dir = dirname(__DIR__) . '/themes/default';
        $cases = [];
        foreach (glob($dir . '/*.html') as $path) {
            $cases[basename($path)] = [$path];
        }
        return $cases;
    }
}
