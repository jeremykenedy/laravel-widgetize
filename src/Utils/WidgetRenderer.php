<?php

namespace Imanghafoori\Widgets\Utils;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Str;

class WidgetRenderer
{
    public $html;

    private $_viewData;

    private $_policies;

    /**
     * BaseWidget constructor.
     */
    public function __construct()
    {
        $this->_policies = app(Policies::class);
    }

    /**
     * @param $widget object|string
     * @param array $args
     * @return string
     */
    public function renderWidget($widget, ...$args)
    {
        if (is_string($widget)) {
            $widget = $this->makeWidgetObj($widget);
        }
        event('widgetize.rendering_widget', [$widget]);

        app(Normalizer::class)->normalizeWidgetConfig($widget);

        if (app()->offsetExists('debugbar')) {
            app('widgetize.debugger')->addMessage(['widget class:' => $widget, 'args:' => $args]);
        }

        try {
            $html = $this->generateHtml($widget, ...$args);
        } catch (\Exception $e) {

            return app()->make(ExceptionHandler::class)->render(app('request'), $e)->send();
        }

        return $html;
    }

    /**
     * @param $widget object
     * @return \Illuminate\Foundation\Application|mixed
     */
    private function makeWidgetObj($widget)
    {
        if (starts_with($widget, ['\\'])) {
            return app($widget);
        }

        $widget = app()->getNamespace().'Widgets\\'.$widget;

        return app($widget);
    }

    /**
     * It tries to get the html from cache if possible, otherwise generates it.
     *
     * @param $widget object
     * @param array ...$args
     *
     * @return string
     */
    private function generateHtml($widget, ...$args)
    {
        // Everything inside this function is executed only when the cache is not available.
        $expensivePhpCode = function () use ($widget, $args) {
            $this->makeDataForView($widget, $args);

            return $this->renderTemplate($widget);
        };

        if (! $widget->cacheView) {
            return $expensivePhpCode();
        }

        // We first try to get the output from the cache before trying to run the expensive $expensivePhpCode...
        return app(Cache::class)->cacheResult($args, $expensivePhpCode, $widget);
    }

    /**
     * @param $widget object
     * @param $args array
     *
     * @return null
     */
    private function makeDataForView($widget, array $args)
    {
        $expensiveCode = function () use ($widget, $args) {

            // Here we call the data method on the widget class.
            $viewData = \App::call($widget->controller, ...$args);

            if (($widget->presenter)) {
                // We make an object and call the `present` method on it.
                // Piping the data through the presenter before sending it to view.
                $viewData = \App::call($widget->presenter, [$viewData]);
            }

            return $viewData;
        };

        if ($widget->cacheView) {
            $this->_viewData = $expensiveCode();
        } else {
            $this->_viewData = app(Cache::class)->cacheResult($args, $expensiveCode, $widget, 'dataProvider');
        }
    }

    /**
     * @param $widget object
     * @return string HTML output
     * @throws \Throwable
     */
    private function renderTemplate($widget)
    {
        // Here we render the view file to raw html.
        try {
            $this->html = view($widget->template, [$widget->contextAs => $this->_viewData])->render();
        } catch (\Exception $e) {
            $this->printError($widget, $e);
        }

        // We try to minify the html before storing it in cache to save space.
        if ($this->_policies->widgetShouldBeMinified($widget)) {
            $this->html = app(HtmlMinifier::class)->minify($this->html);
        }

        // We add some HTML comments before and after the widget output
        // So then, we will be able to easily identify the widget in browser's developer tool.
        if ($this->_policies->widgetShouldHaveDebugInfo()) {
            $this->html = app(DebugInfo::class)->addIdentifierToHtml($widget, $this->html);
        }

        return $this->html;
    }

    /**
     * @param            $widget
     * @param \Exception $e
     */
    private function printError($widget, \Exception $e): void
    {
        if (Str::contains($e->getMessage(), 'Undefined variable:')) {
            dump('You should use the "$'.$widget->contextAs.'" variable in your widget view to access controller data');
        }
        dd($e);
    }
}
