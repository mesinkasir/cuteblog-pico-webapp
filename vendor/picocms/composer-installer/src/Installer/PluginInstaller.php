<?php
/**
 * This file is part of Pico. It's copyrighted by the contributors recorded
 * in the version control history of the file, available from the following
 * original location:
 *
 * <https://github.com/picocms/composer-installer/blob/master/src/Installer/PluginInstaller.php>
 *
 * SPDX-License-Identifier: MIT
 * License-Filename: LICENSE
 */

namespace Pico\Composer\Installer;

use Composer\Composer;
use Composer\Installer\BinaryInstaller;
use Composer\Installer\LibraryInstaller;
use Composer\IO\IOInterface;
use Composer\Package\AliasPackage;
use Composer\Package\PackageInterface;
use Composer\Script\Event;
use Composer\Util\Filesystem;

/**
 * Pico plugin and theme installer
 *
 * The Pico plugin and theme installer is responsible for installing plugins
 * and themes for Pico using Composer. Pico is a stupidly simple, blazing fast,
 * flat file CMS.
 *
 * See <http://picocms.org/> for more info.
 *
 * @author  Daniel Rudolf
 * @link    http://picocms.org
 * @license http://opensource.org/licenses/MIT The MIT License
 * @version 1.0
 */
class PluginInstaller extends LibraryInstaller
{
    /**
     * Package name of this composer installer
     *
     * @var string
     */
    const PACKAGE_NAME = 'picocms/composer-installer';

    /**
     * Package type of Pico plugins
     *
     * @var string
     */
    const PACKAGE_TYPE_PLUGIN = 'pico-plugin';

    /**
     * Package type of Pico themes
     *
     * @var string
     */
    const PACKAGE_TYPE_THEME = 'pico-theme';

    /**
     * Composer root package
     *
     * @var PackageInterface|null
     */
    protected $rootPackage;

    /**
     * Default package installation locations
     *
     * @var string[]
     */
    protected $installDirs = array(
        self::PACKAGE_TYPE_PLUGIN => 'plugins',
        self::PACKAGE_TYPE_THEME => 'themes'
    );

    /**
     * A flag to check usage of the postAutoloadDump event
     *
     * @var bool|null
     */
    protected static $useAutoloadDump;

    /**
     * Initializes Pico plugin and theme installer
     *
     * This method tries to register the `post-autoload-dump` script
     * ({@see PluginInstaller::postAutoloadDump()}), if it wasn't explicitly
     * set already. If this isn't possible, the autoload dump event can't be
     * used ({@see PluginInstaller::checkAutoloadDump()}).
     *
     * @param IOInterface     $io
     * @param Composer        $composer
     * @param string          $type
     * @param Filesystem      $filesystem
     * @param BinaryInstaller $binaryInstaller
     */
    public function __construct(
        IOInterface $io,
        Composer $composer,
        $type = 'library',
        Filesystem $filesystem = null,
        BinaryInstaller $binaryInstaller = null
    ) {
        parent::__construct($io, $composer, $type, $filesystem, $binaryInstaller);
        $this->rootPackage = static::getRootPackage($this->composer);

        // try to register the `post-autoload-dump` script
        $scripts = $this->rootPackage->getScripts();
        $callback = get_called_class() . '::postAutoloadDump';
        if (isset($scripts['post-autoload-dump']) && in_array($callback, $scripts['post-autoload-dump'])) {
            // the user explicitly added the `post-autoload-dump` script,
            // force the autoload dump event to be used
            static::$useAutoloadDump = true;
        } else {
            if (is_callable(array($this->rootPackage, 'setScripts'))) {
                $scripts['post-autoload-dump'][] = $callback;
                $this->rootPackage->setScripts($scripts);
            }

            // check whether the autoload dump event is used
            static::checkAutoloadDump($this->composer);
        }
    }

    /**
     * Checks whether the autoload dump event is used
     *
     * Using the autoload dump event will always create `pico-plugin.php` in
     * Composer's vendor dir. Plugins are nevertheless installed to Pico's
     * `plugins/` dir ({@see PluginInstaller::getInstallPath()}).
     *
     * The autoload dump event is used when the root package is a project and
     * explicitly requires this composer installer.
     *
     * @param Composer $composer
     *
     * @return bool
     */
    public static function checkAutoloadDump(Composer $composer)
    {
        if (static::$useAutoloadDump === null) {
            static::$useAutoloadDump = false;

            $rootPackage = static::getRootPackage($composer);
            if (!$rootPackage || ($rootPackage->getType() !== 'project')) {
                return false;
            }

            $rootPackageRequires = $rootPackage->getRequires();
            if (!isset($rootPackageRequires[static::PACKAGE_NAME])) {
                return false;
            }

            $scripts = $rootPackage->getScripts();
            $callback = get_called_class() . '::postAutoloadDump';
            if (!isset($scripts['post-autoload-dump']) || !in_array($callback, $scripts['post-autoload-dump'])) {
                return false;
            }

            static::$useAutoloadDump = true;
        }

        return static::$useAutoloadDump;
    }

    /**
     * Called whenever Composer (re)generates the autoloader
     *
     * Recreates the `pico-plugin.php` in Composer's vendor dir, containing
     * a mapping of Composer package to Pico plugin class names.
     *
     * @param Event $event
     */
    public static function postAutoloadDump(Event $event)
    {
        $io = $event->getIO();
        $composer = $event->getComposer();

        $vendorDir = $composer->getConfig()->get('vendor-dir');
        $pluginConfig = static::getPluginConfig($vendorDir);

        if (!static::checkAutoloadDump($composer)) {
            if (file_exists($pluginConfig) || is_link($pluginConfig)) {
                $io->write('<info>Deleting Pico plugins file</info>');

                $filesystem = new Filesystem();
                $filesystem->unlink($pluginConfig);
            }

            return;
        }

        if (!file_exists($pluginConfig) && !is_link($pluginConfig)) {
            $io->write('<info>Creating Pico plugins file</info>');
        } else {
            $io->write('<info>Updating Pico plugins file</info>');
        }

        $rootPackage = static::getRootPackage($composer);
        $packages = $composer->getRepositoryManager()->getLocalRepository()->getPackages();

        $plugins = $pluginClassNames = array();
        foreach ($packages as $package) {
            if ($package->getType() !== static::PACKAGE_TYPE_PLUGIN) {
                continue;
            }

            $packageName = $package->getName();
            $plugins[$packageName] = static::getInstallName($package, $rootPackage);
            $pluginClassNames[$packageName] = static::getPluginClassNames($package, $rootPackage);
        }

        static::writePluginConfig($pluginConfig, $plugins, $pluginClassNames);
    }

    /**
     * Determines the plugin class names of a package
     *
     * Plugin class names are either specified explicitly in either the root
     * package's or the plugin package's `composer.json`, or are derived
     * implicitly from the plugin's installer name. The installer name is, for
     * its part, either specified explicitly, or derived implicitly from the
     * plugin package's name ({@see PluginInstaller::getInstallName()}).
     *
     * 1. Using the "pico-plugin" extra in the root package's `composer.json`:
     *    ```yaml
     *    {
     *        "extra": {
     *            "pico-plugin": {
     *                "<package name>": [ "<class name>", "<class name>", ... ]
     *            }
     *        }
     *    }
     *    ```
     *
     *    Besides matching exact package names, you can also use the prefixes
     *    `vendor:` or `name:` ({@see PluginInstaller::mapRootExtra()}).
     *
     * 2. Using the "pico-plugin" extra in the package's `composer.json`:
     *    ```yaml
     *    {
     *        "extra": {
     *            "pico-plugin": [ "<class name>", "<class name>", ... ]
     *        }
     *    }
     *    ```
     *
     * 3. Using the installer name ({@see PluginInstaller::getInstallName()}).
     *
     * @param PackageInterface      $package
     * @param PackageInterface|null $rootPackage
     *
     * @return string[]
     */
    public static function getPluginClassNames(PackageInterface $package, PackageInterface $rootPackage = null)
    {
        $packageType = $package->getType();
        $packagePrettyName = $package->getPrettyName();

        $classNames = array();

        // 1. root package
        $rootPackageExtra = $rootPackage ? $rootPackage->getExtra() : null;
        if (!empty($rootPackageExtra[$packageType])) {
            $classNames = (array) static::mapRootExtra($rootPackageExtra[$packageType], $packagePrettyName);
        }

        // 2. package
        if (!$classNames) {
            $packageExtra = $package->getExtra();
            if (!empty($packageExtra[$packageType])) {
                $classNames = (array) $packageExtra[$packageType];
            }
        }

        // 3. guess by installer name
        if (!$classNames) {
            $installName = static::getInstallName($package, $rootPackage);
            $classNames = array($installName);
        }

        return $classNames;
    }

    /**
     * Returns the install name of a package
     *
     * The install name of packages are either explicitly specified in either
     * the root package's or the plugin package's `composer.json` using the
     * "installer-name" extra, or implicitly derived from the plugin package's
     * name.
     *
     * Install names are determined the same way as plugin class names. See
     * {@see PluginInstaller::getPluginClassNames()} for details.
     *
     * @param PackageInterface      $package
     * @param PackageInterface|null $rootPackage
     *
     * @return string
     */
    public static function getInstallName(PackageInterface $package, PackageInterface $rootPackage = null)
    {
        $packagePrettyName = $package->getPrettyName();
        $packageName = $package->getName();
        $installName = null;

        $rootPackageExtra = $rootPackage ? $rootPackage->getExtra() : null;
        if (!empty($rootPackageExtra['installer-name'])) {
            $installName = static::mapRootExtra($rootPackageExtra['installer-name'], $packagePrettyName);
        }

        if (!$installName) {
            $packageExtra = $package->getExtra();
            if (!empty($packageExtra['installer-name'])) {
                $installName = $packageExtra['installer-name'];
            }
        }

        return $installName ?: static::guessInstallName($packageName);
    }

    /**
     * Guesses the install name of a package
     *
     * The install name of a Pico plugin or theme is guessed by converting the
     * package name to StudlyCase and removing "-plugin" or "-theme" suffixes,
     * if present.
     *
     * @param string $packageName
     *
     * @return string
     */
    protected static function guessInstallName($packageName)
    {
        $name = $packageName;
        if (strpos($packageName, '/') !== false) {
            list(, $name) = explode('/', $packageName);
        }

        $name = preg_replace('/[\.\-_]+(?>plugin|theme)$/u', '', $name);
        $name = preg_replace_callback(
            '/(?>^[\.\-_]*|[\.\-_]+)(.)/u',
            function ($matches) {
                return strtoupper($matches[1]);
            },
            $name
        );

        return $name;
    }

    /**
     * Maps the root package's extra data to a package
     *
     * Besides matching the exact package name, you can also use the `vendor:`
     * or `name:` prefixes to match all packages of a specific vendor resp.
     * all packages with a specific name, no matter the vendor.
     *
     * @param mixed[] $packageExtra
     * @param string  $packagePrettyName
     *
     * @return mixed
     */
    protected static function mapRootExtra(array $packageExtra, $packagePrettyName)
    {
        if (isset($packageExtra[$packagePrettyName])) {
            return $packageExtra[$packagePrettyName];
        }

        if (strpos($packagePrettyName, '/') !== false) {
            list($vendor, $name) = explode('/', $packagePrettyName);
        } else {
            $vendor = '';
            $name = $packagePrettyName;
        }

        foreach ($packageExtra as $key => $value) {
            if ((substr($key, 0, 5) === 'name:') && (substr($key, 5) === $name)) {
                return $value;
            } elseif ((substr($key, 0, 7) === 'vendor:') && (substr($key, 7) === $vendor)) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Returns the path to the pico-plugin.php in Composer's vendor dir
     *
     * @param string $vendorDir
     *
     * @return string
     */
    protected static function getPluginConfig($vendorDir)
    {
        return $vendorDir . '/' . static::PACKAGE_TYPE_PLUGIN . '.php';
    }

    /**
     * Rewrites the pico-plugin.php in Composer's vendor dir
     *
     * @param string $pluginConfig
     * @param array  $plugins
     * @param array  $pluginClassNames
     */
    public static function writePluginConfig($pluginConfig, array $plugins, array $pluginClassNames)
    {
        $data = array();
        foreach ($plugins as $pluginName => $installerName) {
            // see https://github.com/composer/composer/blob/1.0.0/src/Composer/Command/InitCommand.php#L206-L210
            if (!preg_match('{^[a-z0-9_.-]+/[a-z0-9_.-]+$}', $pluginName)) {
                throw new \InvalidArgumentException(
                    "The package name '" . $pluginName . "' is invalid, it must be lowercase and have a vendor name, "
                    . "a forward slash, and a package name, matching: [a-z0-9_.-]+/[a-z0-9_.-]+"
                );
            }
            $data[] = sprintf("    '%s' => array(", $pluginName);

            if (!preg_match('{^[a-zA-Z0-9_.-]+$}', $installerName)) {
                throw new \InvalidArgumentException(
                    "The installer name '" . $installerName . "' is invalid, "
                    . "it must be alphanumeric, matching: [a-zA-Z0-9_.-]+"
                );
            }
            $data[] = sprintf("        'installerName' => '%s',", $installerName);

            if (isset($pluginClassNames[$pluginName])) {
                $data[] = sprintf("        'classNames' => array(");

                foreach ($pluginClassNames[$pluginName] as $className) {
                    // see https://secure.php.net/manual/en/language.oop5.basic.php
                    if (!preg_match('{^[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*$}', $className)) {
                        throw new \InvalidArgumentException(
                            "The plugin class name '" . $className . "' is no valid PHP class name"
                        );
                    }
                    $data[] = sprintf("            '%s',", $className);
                }

                $data[] = "        ),";
            }

            $data[] = "    ),";
        }

        $contents = <<<'PHP'
<?php

// %s @generated by %s

return array(
%s
);

PHP;

        $contents = sprintf(
            $contents,
            basename($pluginConfig),
            static::PACKAGE_NAME,
            implode("\n", $data)
        );

        file_put_contents($pluginConfig, $contents);
    }

    /**
     * Returns the root package of a composer instance
     *
     * @param Composer $composer
     *
     * @return PackageInterface
     */
    protected static function getRootPackage(Composer $composer)
    {
        $rootPackage = $composer->getPackage();
        if ($rootPackage) {
            while ($rootPackage instanceof AliasPackage) {
                $rootPackage = $rootPackage->getAliasOf();
            }
        }

        return $rootPackage;
    }

    /**
     * Decides if the installer supports installing the given package type
     *
     * @param string $packageType
     *
     * @return bool
     */
    public function supports($packageType)
    {
        return (
            ($packageType === static::PACKAGE_TYPE_PLUGIN)
            || ($packageType === static::PACKAGE_TYPE_THEME)
        );
    }

    /**
     * Returns the installation path of a package
     *
     * Plugins are installed to the `plugins/`, themes to the `themes/` dir
     * by default respectively. You can overwrite these target dirs using the
     * "pico-plugin-dir" resp. "pico-theme-dir" extra in the root package's
     * `composer.json`.
     *
     * @param PackageInterface $package
     *
     * @return string
     */
    public function getInstallPath(PackageInterface $package)
    {
        $packageType = $package->getType();

        $installDir = $this->initializeInstallDir($packageType);
        $installName = static::getInstallName($package, $this->rootPackage);
        return $installDir . '/' . $installName;
    }

    /**
     * Returns and initializes the installation directory of the given type
     *
     * @param string $packageType
     *
     * @return string
     */
    protected function initializeInstallDir($packageType)
    {
        $installDir = '';

        $rootPackageExtra = $this->rootPackage ? $this->rootPackage->getExtra() : null;
        if (!empty($rootPackageExtra[$packageType . '-dir'])) {
            $installDir = rtrim($rootPackageExtra[$packageType . '-dir'], '/\\');
        }

        if (!$installDir) {
            if (empty($this->installDirs[$packageType])) {
                throw new \InvalidArgumentException(
                    "The package type '" . $packageType . "' is not supported"
                );
            }

            $installDir = $this->installDirs[$packageType];
        }

        if (!$this->filesystem->isAbsolutePath($installDir)) {
            $installDir = dirname($this->vendorDir) . '/' . $installDir;
        }

        $this->filesystem->ensureDirectoryExists($installDir);
        return realpath($installDir);
    }
}
