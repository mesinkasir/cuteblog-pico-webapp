Pico Composer Installer
=======================

This is the repository of Pico's official [Composer][] installer.

Pico is a stupidly simple, blazing fast, flat file CMS. See http://picocms.org/ for more info.

This Composer plugin is responsible for installing Pico plugins and themes using the Composer package manager (i.e. by running `composer install` on the command line). It assumes responsibility for packages that identify as `{ "type": "pico-plugin" }` and `{ "type": "pico-theme" }` in their `composer.json` and instructs Composer to install these packages to Pico's `plugins/` and `themes/` folder respectively.

The installer furthermore creates a `vendor/pico-plugin.php` with a list of all installed Pico plugins and the corresponding PHP classes (requires the `post-autoload-dump` event, see "Install" section below). This file is used by Pico 2.0+ to load such plugins at runtime and even allows you to completely disable filesystem-based loading of plugins. Just make sure to add a proper autoload section to your `composer.json` - otherwise Pico won't find your plugin's PHP class.

The installer's behavior is fully configurable in both the plugin's and themes's `composer.json`, and the root package's `composer.json` (see the "Usage" section below).

Please refer to [`picocms/Pico`](https://github.com/picocms/Pico) to get info about how to contribute or getting help.

Install
-------

If you've used Pico's official composer starter project ([`picocms/pico-composer`][pico-composer]), your website's `composer.json` (the "root package") already depends on `picocms/composer-installer`. If this isn't true, run the following to load it from [Packagist.org][]:

```shell
$ composer require picocms/composer-installer:^1.0
```

The Composer plugin tries to automatically register itself for the `post-autoload-dump` event. This is a prerequisite for the installer to create a `vendor/pico-plugin.php`. If the installer was successful in doing so, you'll see the following three lines when running `composer install` or `composer update`:

```
Generating autoload files
> Pico\Composer\Installer\PluginInstaller::postAutoloadDump
Creating Pico plugins file
```

If you see just the first two lines and not the third one, please make sure that your website identifies itself as `{ "type": "project" }` in your root package's `composer.json` and *directly* depends on `picocms/composer-installer`. If you see the first line only, let us know by opening a new Issue on [GitHub][pico-composer]. To solve this issue, add the following to the root package's `composer.json`:

```json
{
    "scripts": {
        "post-autoload-dump": [
            "Pico\\Composer\\Installer\\PluginInstaller::postAutoloadDump"
        ]
    }
}
```

Usage
-----

Your plugins and themes themselves do **not** need to require `picocms/composer-installer`. They only need to specify the type in their `composer.json`:

```json
{
    "type": "pico-plugin"
}
```

or

```json
{
    "type": "pico-theme"
}
```

#### More about themes

The Pico theme installer will automatically determine the installation directory by converting the package name to StudlyCase and removing the `-theme` suffix, if present. The result of this step is called "installer name". For example, the package `pico-nyan-cat-theme` is installed to the `themes/PicoNyanCat` directory. You can then use the theme by adding `theme: PicoNyanCat` to Pico's `config/config.yml`.

You can overrule this behavior by setting the `installer-name` extra in your theme's `composer.json`. For example, Pico's official default theme ([`picocms/pico-theme`][pico-theme]) has the following lines in its `composer.json`, instructing the installer to install it to the `themes/default` directory:

```json
{
    "extra": {
        "installer-name": "default"
    }
}
```

#### More about plugins

The Pico plugin installer will automatically determine the installation directory by converting the package name to StudlyCase and removing the `-plugin` suffix, if present. The result of this step is called "installer name". The installer name is later used by Pico to load the plugin's PHP class. For example, the package `pico-nyan-cat-plugin` is installed to the `plugins/PicoNyanCat` directory and Pico later uses the `PicoNyanCat` PHP class to load the plugin.

For the installer to work properly ensure that your plugin's `composer.json` has a proper autoload section, allowing Pico to later find the `PicoNyanCat` PHP class:

```json
{
    "autoload": {
        "classmap": [ "PicoNyanCat.php" ]
    }
}
```

You can change the installation directory by setting the `installer-name` extra in your plugin's `composer.json`. By overruling the installer name, you also change the PHP class Pico uses to load the plugin. For example, if your package is called `my-vendor/my-pico-plugin`, the installer would install it to the `plugins/MyPico` directory. If you don't want the `-plugin` suffix to be removed, add the following lines to your plugin's `composer.json`:

```json
{
    "extra": {
        "installer-name": "MyPicoPlugin"
    }
}
```

The installer will now install your plugin to the `plugins/MyPicoPlugin` directory and Pico loads the plugin using the `MyPicoPlugin` PHP class.

#### Advanced plugin setups

**If you're not an absolute Pico expert, don't read further!** Even if it sounds pretty convenient, the following is only relevant in very advanced setups:

The Pico plugin installer consists of two parts: First, it determines the installer name to decide to which directory the plugin is installed to. Second, it creates a `vendor/pico-plugin.php` with a list of all installed plugins and the corresponding PHP classes. If this file is present, it is used by Pico 2.0+ to load these plugins. Pico naturally also ensures that these plugins aren't loaded as local plugin a second time. However, the `vendor/pico-plugin.php` is only created if the `post-autoload-dump` event is used (what is usually the case).

If the `post-autoload-dump` event is *not* used, the installer won't create a `vendor/pico-plugin.php` and Pico loads the plugin the filesystem-based way: It looks at the directory name, tries to include a `.php` file of the same name in this directory and uses the same name as PHP class. In other words: If there's a `plugins/PicoNyanCat` directory, Pico includes the `plugins/PicoNyanCat/PicoNyanCat.php` file and loads the plugin using the `PicoNyanCat` PHP class. If this doesn't work, Pico irrecoverably bails out.

As you've probably noticed already, this might go horribly wrong. *So, be careful!*

If you want to overwrite the PHP classes Pico uses to load the plugin, or if you want to load multiple plugins at a time, use the `pico-plugin` extra in your plugin's `composer.json`. The `composer.json` of a plugin collection might look like the following:

```json
{
    "extra": {
        "installer-name": "MyPluginCollection",
        "pico-plugin": [
            "MyVendor\\MyPluginCollection\\MyFirstPlugin",
            "MyVendor\\MyPluginCollection\\MySecondPlugin"
        ]
    }
}
```

Please note that you **must not** use this feature if you want to share your plugin with others!

#### Using the root package's `composer.json`

All `composer.json` tweaks explained above can also be used in the root package's `composer.json`. The root package's `composer.json` even takes precedence over the plugin's or theme's `composer.json`, allowing you to overwrite the installer's behavior specifically for your website!

If you e.g. want to install the Pico theme `some-vendor/nyan-cat-theme` to the `themes/TacNayn` directory instead of `themes/NyanCat`, add the following to the root package's `composer.json`:

```json
{
    "extra": {
        "installer-name": {
            "some-vendor/nyan-cat-theme": "TacNayn"
        }
    }
}
```

Besides using the exact package name, you can also use the `vendor:` (e.g. `vendor:some-vendor`) and `name:` (e.g. `name:nyan-cat-theme`) prefixes to match a plugin or theme package's name.

Naturally this isn't limited to themes, this works for plugins, too. However, *be very careful*, by changing a plugin's installer name, you (usually) also change the PHP class Pico uses to load the plugin. **This will likely break your installation!** See the "Advanced plugin setups" section above for more info. Simply put, don't use this for plugins!

Sometimes your themes directory isn't called `themes/`, but rather something else, like `public/`. You can change the path to both the `themes/` and `plugins/` directory using the `pico-theme-dir` resp. `pico-plugin-dir` extra. Simply add the following to your root package's `composer.json`:

```json
{
    "extra": {
        "pico-theme-dir": "public/",
        "pico-plugin-dir": "/some/absolute/path/"
    }
}
```

#### What about websites not using Composer?

Don't worry, Pico neither requires you to use Composer, nor `picocms/composer-installer`.

If your plugin consists of the `PicoNyanCat` PHP class, create a `PicoNyanCat.php` and write its class definition into this file. If you then want to use that plugin, simply move the `PicoNyanCat.php` to your `plugins/` directory. If your plugin consists of multiple files (what is recommended, like a `README.md` or `LICENSE` file), create a `plugins/PicoNyanCat/` folder and move the `PicoNyanCat.php` into that folder (so that you get `plugins/PicoNyanCat/PicoNyanCat.php`).

If you want to share your plugin, simply share said `plugins/PicoNyanCat/` folder and instruct your users to copy it to their `plugins/` directory. That's it!

One might legitimately ask why he should use Composer and `picocms/composer-installer` in the first place. The answer's simple: Because Composer makes it even easier for the user - especially with more than two or three plugins! See Pico's official composer starter project ([`picocms/pico-composer`][pico-composer]) for a more complete reasoning.

[Composer]: https://getcomposer.org/
[pico-composer]: https://github.com/picocms/pico-composer
[Packagist.org]: https://packagist.org/packages/picocms/composer-installer
[pico-theme]: https://github.com/picocms/pico-theme
