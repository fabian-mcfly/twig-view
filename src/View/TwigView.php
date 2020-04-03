<?php
declare(strict_types=1);

/**
 * CakePHP(tm) : Rapid Development Framework (https://cakephp.org)
 * Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 * Copyright (c) 2014 Cees-Jan Kiewiet
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice
 *
 * @copyright     Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 * @link          https://cakephp.org CakePHP(tm) Project
 * @since         1.0.0
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */

namespace Cake\TwigView\View;

use Cake\Core\Configure;
use Cake\Core\Plugin;
use Cake\TwigView\Twig\Extension;
use Cake\TwigView\Twig\Loader;
use Cake\TwigView\Twig\TokenParser;
use Cake\View\Exception\MissingLayoutException;
use Cake\View\Exception\MissingTemplateException;
use Cake\View\View;
use Jasny\Twig\ArrayExtension;
use Jasny\Twig\DateExtension;
use Jasny\Twig\PcreExtension;
use Jasny\Twig\TextExtension;
use RuntimeException;
use Twig\Environment;
use Twig\Extension\DebugExtension;
use Twig\Extension\StringLoaderExtension;
use Twig\Extra\Markdown\MarkdownExtension;
use Twig\Extra\Markdown\MarkdownInterface;
use Twig\Extra\Markdown\MarkdownRuntime;
use Twig\Loader\LoaderInterface;
use Twig\Profiler\Profile;
use Twig\RuntimeLoader\RuntimeLoaderInterface;

/**
 * Class TwigView.
 */
class TwigView extends View
{
    public const EXT = '.twig';

    /**
     * @var \Twig\Environment|null
     */
    protected static $twig;

    /**
     * @var \Twig\Profiler\Profile|null
     */
    protected static $profile;

    /**
     * Default config options.
     *
     * Use ViewBuilder::setOption()/setOptions() in your controller to set these options.
     *
     * - `environment` - Array of config you would pass into \Twig\Environment to overwrite the default settings,
     *   see: http://twig.sensiolabs.org/doc/api.html#environment-options
     * - `markdown` - Config for MarkdownExtension. Array must contain `engine` key which is
     *   an instance of Twig\Extra\Markdown\MarkdownInterface,
     *   see https://twig.symfony.com/doc/3.x/filters/markdown_to_html.html
     *
     * @var array
     */
    protected $_defaultConfig = [
        'environment' => [
        ],
        'markdown' => [
            'engine' => null,
        ],
    ];

    /**
     * @inheritDoc
     */
    protected $_ext = '.twig';

    /**
     * List of extensions searched when loading templates.
     *
     * @var string[]
     */
    protected $extensions = [
        '.twig',
    ];

    /**
     * Initialize view.
     *
     * @return void
     */
    public function initialize(): void
    {
        parent::initialize();

        if (static::$twig === null) {
            // Cache instance to avoid re-creating when rendering Cells
            static::$twig = $this->createEnvironment();

            $this->initializeTokenParser();
            $this->initializeExtensions();

            if (Configure::read('debug') && Plugin::isLoaded('DebugKit')) {
                $this->initializeProfiler();
            }
        }
    }

    /**
     * Get Twig Environment instance.
     *
     * @return \Twig\Environment
     */
    public function getTwig(): Environment
    {
        if (static::$twig === null) {
            throw new RuntimeException('Twig Environment instance not created.');
        }

        return static::$twig;
    }

    /**
     * Gets Twig Profile if profiler enabled.
     *
     * @return \Twig\Profiler\Profile|null
     */
    public function getProfile(): ?Profile
    {
        return static::$profile;
    }

    /**
     * Creates the Twig LoaderInterface instance.
     *
     * @return \Twig\Loader\LoaderInterface
     */
    protected function createLoader(): LoaderInterface
    {
        return new Loader();
    }

    /**
     * Creates the Twig Environment.
     *
     * @return \Twig\Environment
     */
    protected function createEnvironment(): Environment
    {
        $debug = Configure::read('debug', false);

        $config = $this->getConfig('environment') + [
            'charset' => Configure::read('App.encoding', 'UTF-8'),
            'debug' => $debug,
            'cache' => $debug ? false : CACHE . 'twigView' . DS,
        ];

        if ($config['cache'] === true) {
            $config['cache'] = CACHE . 'twigView' . DS;
        }

        $env = new Environment($this->createLoader(), $config);
        // Must add before any templates are rendered so can be updated in _render().
        $env->addGlobal('_view', $this);

        return $env;
    }

    /**
     * Adds custom Twig token parsers.
     *
     * @return void
     */
    protected function initializeTokenParser(): void
    {
        $this->getTwig()->addTokenParser(new TokenParser\CellParser());
        $this->getTwig()->addTokenParser(new TokenParser\ElementParser());
    }

    // phpcs:disable CakePHP.Commenting.FunctionComment.InvalidReturnVoid

    /**
     * Adds Twig extensions.
     *
     * @return void
     */
    protected function initializeExtensions(): void
    {
        $twig = $this->getTwig();

        // Twig core extensions
        $twig->addExtension(new StringLoaderExtension());
        $twig->addExtension(new DebugExtension());

        // CakePHP bridging extensions
        $twig->addExtension(new Extension\ArraysExtension());
        $twig->addExtension(new Extension\BasicExtension());
        $twig->addExtension(new Extension\ConfigureExtension());
        $twig->addExtension(new Extension\I18nExtension());
        $twig->addExtension(new Extension\InflectorExtension());
        $twig->addExtension(new Extension\NumberExtension());
        $twig->addExtension(new Extension\StringsExtension());
        $twig->addExtension(new Extension\TimeExtension());
        $twig->addExtension(new Extension\UtilsExtension());
        $twig->addExtension(new Extension\ViewExtension());

        // Markdown extension
        $markdownEngine = $this->getConfig('markdown.engine');
        if ($markdownEngine instanceof MarkdownInterface) {
            $twig->addExtension(new MarkdownExtension());

            $twig->addRuntimeLoader(new class ($markdownEngine) implements RuntimeLoaderInterface {
                /**
                 * @var \Twig\Extra\Markdown\MarkdownInterface
                 */
                private $engine;

                /**
                 * @param \Twig\Extra\Markdown\MarkdownInterface $engine MarkdownInterface instance
                 */
                public function __construct(MarkdownInterface $engine)
                {
                    $this->engine = $engine;
                }

                /**
                 * @param string $class FQCN
                 * @return object|null
                 */
                public function load($class)
                {
                    if ($class === MarkdownRuntime::class) {
                        return new MarkdownRuntime($this->engine);
                    }

                    return null;
                }
            });
        }

        // jasny/twig-extensions
        $twig->addExtension(new DateExtension());
        $twig->addExtension(new ArrayExtension());
        $twig->addExtension(new PcreExtension());
        $twig->addExtension(new TextExtension());
    }

    // phpcs:enable

    /**
     * Initializes Twig profiler extension.
     *
     * @return void
     */
    protected function initializeProfiler(): void
    {
        static::$profile = new Profile();
        $this->getTwig()->addExtension(new Extension\ProfilerExtension(static::$profile));
    }

    /**
     * @inheritDoc
     */
    protected function _render(string $templateFile, array $data = []): string
    {
        // Set _view for each render because Twig Environment is shared between views.
        $this->getTwig()->addGlobal('_view', $this);

        $data = array_merge(
            empty($data) ? $this->viewVars : $data,
            iterator_to_array($this->helpers()->getIterator())
        );

        return $this->getTwig()->load($templateFile)->render($data);
    }

    /**
     * @inheritDoc
     */
    protected function _getTemplateFileName(?string $name = null): string
    {
        foreach ($this->extensions as $extension) {
            $this->_ext = $extension;
            try {
                return parent::_getTemplateFileName($name);
            } catch (MissingTemplateException $exception) {
                $missingException = $exception;
            }
        }

        throw $missingException ?? new MissingTemplateException($name ?? $this->getTemplate());
    }

    /**
     * @inheritDoc
     */
    protected function _getLayoutFileName(?string $name = null): string
    {
        foreach ($this->extensions as $extension) {
            $this->_ext = $extension;
            try {
                return parent::_getLayoutFileName($name);
            } catch (MissingLayoutException $exception) {
                $missingException = $exception;
            }
        }

        throw $missingException ?? new MissingLayoutException($name ?? $this->getLayout());
    }

    /**
     * @inheritDoc
     */
    protected function _getElementFileName(string $name, bool $pluginCheck = true)
    {
        foreach ($this->extensions as $extension) {
            $this->_ext = $extension;
            $result = parent::_getElementFileName($name, $pluginCheck);
            if ($result !== false) {
                return $result;
            }
        }

        return false;
    }
}
