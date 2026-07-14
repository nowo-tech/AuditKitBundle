# Twig Inspector Bundle

[![CI](https://github.com/nowo-tech/twig-inspector-bundle/actions/workflows/ci.yml/badge.svg)](https://github.com/nowo-tech/twig-inspector-bundle/actions/workflows/ci.yml) [![Latest Stable Version](https://poser.pugx.org/nowo-tech/twig-inspector-bundle/v)](https://packagist.org/packages/nowo-tech/twig-inspector-bundle) [![License](https://poser.pugx.org/nowo-tech/twig-inspector-bundle/license)](https://packagist.org/packages/nowo-tech/twig-inspector-bundle) [![PHP Version Require](https://poser.pugx.org/nowo-tech/twig-inspector-bundle/require/php)](https://packagist.org/packages/nowo-tech/twig-inspector-bundle)

Symfony bundle that adds the possibility to find Twig templates and blocks used for rendering HTML pages faster during development.

## Features

- ✅ Inspect Twig templates directly in the browser
- ✅ Visual overlay showing which templates render which HTML elements
- ✅ Click to open templates in your IDE
- ✅ Works with Symfony Web Profiler
- ✅ Cookie-based activation (no code changes needed)
- ✅ Supports nested blocks and templates
- ✅ **Configurable template/block exclusion** (with wildcard support)
- ✅ **Template usage metrics** in Web Profiler
- ✅ **Performance optimized** (skips processing when disabled)
- ✅ **Flexible configuration** for different use cases

## Installation

```bash
composer require nowo-tech/twig-inspector-bundle:^1.0.3 --dev
```

Then, register the bundle in your `config/bundles.php`:

```php
<?php

return [
    // ...
    Nowo\TwigInspectorBundle\NowoTwigInspectorBundle::class => ['dev' => true, 'test' => true],
];
```

## Usage

1. **Enable the bundle** (only in `dev` and `test` environments)

2. **Access your application** with Symfony Web Profiler enabled

3. **In the Web Profiler toolbar**, you'll see a new icon `</>`

4. **Enable Twig Inspector** by checking the checkbox in the toolbar

5. **Hover over HTML elements** in your page to see which Twig templates rendered them

6. **Click on elements** to open the template in your IDE

## How It Works

The bundle adds HTML comments before and after every Twig block and template:

```html
<!-- ┏━ template_name [/_template/template.html.twig] #abc123 -->
<div>Your content</div>
<!-- ┗━ template_name [/_template/template.html.twig] #abc123 -->
```

When enabled, a JavaScript overlay shows which templates correspond to which HTML elements, and clicking on them opens the template in your IDE.

## Configuration

The bundle works out of the box with default settings. **No configuration file is required** - the bundle uses sensible defaults defined in `Configuration.php`.

**Important**: The configuration file (`nowo_twig_inspector.yaml`) is **optional**. You only need to create it if you want to customize the default behavior.

### How Configuration Works

1. **Default Values**: The bundle uses default values from `Configuration.php` if no config file exists
2. **YAML Merging**: If a YAML file exists, Symfony automatically merges it with default values
3. **No Auto-Deletion**: When uninstalling the bundle, the YAML file is **not** automatically deleted (you may want to keep your custom configuration)

### Bundle Configuration

#### Symfony Flex Recipe (Automatic - Recommended)

**If the bundle is installed via Symfony Flex** (from Packagist), the configuration file will be created **automatically** during `composer require`. **You don't need to do anything else** - the file is created for you.

**Note**: Flex Recipes only work when the bundle is published in the official Symfony Flex repository (Packagist). If you're using a private bundle or installing from a Git repository, Flex Recipes won't work and you'll need to use the install command below.

#### Install Command (For manual installation or private bundles)

**Only use this command if:**
- You're installing a private bundle (not from Packagist)
- The Flex Recipe didn't create the file automatically
- You want to regenerate the configuration file for a different environment
- You want to recreate the file after deleting it

To create the configuration file manually:

```bash
php bin/console nowo:twig-inspector:install
```

This command will:
- Create the configuration file at `config/packages/dev/nowo_twig_inspector.yaml`
- Include all available options with comments
- Ask for confirmation if the file already exists (use `--force` to overwrite)

You can also specify a different environment:

```bash
# For test environment
php bin/console nowo:twig-inspector:install --env=test

# Overwrite existing file
php bin/console nowo:twig-inspector:install --force
```

#### Manual Installation

Alternatively, you can create the configuration file manually:

```bash
# Create the configuration file (optional)
touch config/packages/dev/nowo_twig_inspector.yaml
```

Then configure the bundle behavior:

```yaml
# config/packages/dev/nowo_twig_inspector.yaml
nowo_twig_inspector:
    # List of template file extensions to inspect
    enabled_extensions:
        - '.html.twig'
        # - '.twig'  # Uncomment to also inspect .twig files
    
    # Templates to exclude from inspection (supports wildcards with *)
    excluded_templates:
        - 'admin/*'           # Exclude all admin templates
        - 'email/*.html.twig' # Exclude email templates
    
    # Blocks to exclude from inspection (supports wildcards with *)
    excluded_blocks:
        - 'javascript'        # Exclude 'javascript' block
        - 'head_*'            # Exclude blocks starting with 'head_'
    
    # Enable template usage metrics in Web Profiler
    enable_metrics: true
    
    # Optimize performance by skipping output buffering when disabled
    optimize_output_buffering: true
    
    # Custom cookie name for enabling/disabling inspector
    cookie_name: 'twig_inspector_is_active'
```

**Default Values** (used when no config file exists):

- `enabled_extensions`: `['.html.twig']` - Only `.html.twig` files are inspected
- `excluded_templates`: `[]` - No templates excluded by default
- `excluded_blocks`: `[]` - No blocks excluded by default
- `enable_metrics`: `true` - Metrics collection enabled
- `optimize_output_buffering`: `true` - Performance optimization enabled
- `cookie_name`: `'twig_inspector_is_active'` - Default cookie name

**Configuration Options:**

- `enabled_extensions`: Array of template file extensions to inspect (default: `['.html.twig']`)
- `excluded_templates`: Array of template names or patterns to exclude (supports `*` wildcard)
- `excluded_blocks`: Array of block names or patterns to exclude (supports `*` wildcard)
- `enable_metrics`: Enable collection of template usage statistics (default: `true`)
- `optimize_output_buffering`: Skip output buffering when inspector is disabled (default: `true`)
- `cookie_name`: Name of the cookie used to enable/disable inspector (default: `'twig_inspector_is_active'`)

**Note**: All configuration options are optional. If you don't create a config file, the bundle will use the default values listed above.

### Configuration File Behavior

**If YAML exists**: Symfony automatically loads and merges it with default values. Your custom configuration takes precedence over defaults.

**If YAML doesn't exist**: The bundle uses default values from `Configuration.php`. No file is required.

**When uninstalling**: The YAML file is **not automatically deleted**. This is intentional because:
- You may have customized the configuration
- You might want to keep it for reference
- Symfony cannot determine if the file is still needed
- You can manually delete it if desired: `rm config/packages/dev/nowo_twig_inspector.yaml`

For detailed information about how configuration works, see [CONFIGURATION.md](CONFIGURATION.md).

### IDE Integration

To open templates in your IDE, configure the `ide` option in your Symfony configuration:

```yaml
# config/packages/dev/framework.yaml
framework:
    ide: 'phpstorm://open?file=%%f&line=%%l'
```

Supported IDEs:
- PhpStorm: `phpstorm://open?file=%%f&line=%%l`
- VS Code: `vscode://file/%%f:%%l`
- Sublime Text: `subl://open?url=file://%%f&line=%%l`
- Atom: `atom://core/open/file?filename=%%f&line=%%l`

### Security

The bundle includes comprehensive security measures to prevent path traversal attacks and unauthorized access:

- **Template name validation**: Rejects path traversal attempts (`..`), null bytes, and absolute paths
- **File path verification**: Ensures resolved template paths are within allowed Twig template directories
- **Parameter validation**: Line numbers must be positive integers
- **Route restrictions**: Routes should only be available in `dev` and `test` environments

**Important**: Always ensure the bundle routes are restricted to development environments:

```yaml
# config/routes.yaml
when@dev:
    nowo_twig_inspector:
        resource: '@NowoTwigInspectorBundle/Resources/config/routes.yaml'
```

The bundle automatically registers only in `dev` and `test` environments when configured in `bundles.php`, but it's recommended to also restrict routes explicitly.

### Template Usage Metrics

When `enable_metrics` is set to `true` (default), the bundle collects template usage statistics that are available in the Symfony Web Profiler:

- **Templates used**: List of all templates rendered in the request
- **Blocks used**: List of all blocks rendered in the request
- **Usage counts**: How many times each template/block was used
- **Totals**: Total number of unique templates and blocks

Access the metrics by:
1. Opening the Symfony Web Profiler toolbar
2. Clicking on the `</>` (Twig Inspector) icon
3. Viewing the collected statistics

**Note**: Metrics are only collected when the inspector is enabled (cookie is set to `true`).

## Requirements

- PHP >= 8.1, < 8.6
- Symfony >= 6.0 || >= 7.0 || >= 8.0
- Symfony Web Profiler Bundle (for development)
- Twig >= 3.8 || >= 4.0

## Demo Projects

The bundle includes three demo projects, one for each supported Symfony version. Each demo has its own `docker-compose.yml` and can be run independently:

- **Symfony 6.4 Demo**: `demo/demo-symfony6/` (Port 8001 by default, configurable via PORT env variable)
- **Symfony 7.0 Demo**: `demo/demo-symfony7/` (Port 8001 by default, configurable via PORT env variable)
- **Symfony 8.0 Demo**: `demo/demo-symfony8/` (Port 8001 by default, configurable via PORT env variable)

### Quick Start with Docker

Each demo can be started independently:

```bash
# Symfony 6.4 Demo
cd demo/demo-symfony6
docker-compose up -d
docker-compose exec php composer install
# Access at: http://localhost:8001 (default port)

# Symfony 7.0 Demo
cd demo/demo-symfony7
docker-compose up -d
docker-compose exec php composer install
# Access at: http://localhost:8001 (default port)

# Symfony 8.0 Demo
cd demo/demo-symfony8
docker-compose up -d
docker-compose exec php composer install
# Access at: http://localhost:8001 (default port)
```

Or use the Makefile helper commands from the `demo/` directory:

```bash
cd demo

# Start and install Symfony 6.4 demo
make up-symfony6
make install-symfony6

# Start and install Symfony 7.0 demo
make up-symfony7
make install-symfony7

# Start and install Symfony 8.0 demo
make up-symfony8
make install-symfony8
```

Each demo includes:
- Independent `docker-compose.yml` for easy setup
- Complete test suite to verify bundle integration
- Code coverage configuration (100% coverage for demo code)
- Example controller and templates
- Web Profiler integration

### Running Demo Tests

Each demo has its own test suite with code coverage:

```bash
cd demo

# Run tests for a specific demo
make test-symfony6
make test-symfony7
make test-symfony8

# Run tests with coverage for a specific demo
make test-coverage-symfony6
make test-coverage-symfony7
make test-coverage-symfony8

# Run all tests with coverage
make test-coverage-all
```

Or directly in each demo directory:

```bash
cd demo/demo-symfony6
docker-compose exec php composer test
docker-compose exec php composer test-coverage
```

See `demo/README.md` for more details.

## Development

### Using Docker (Recommended)

```bash
# Start the container
make up

# Install dependencies
make install

# Run tests
make test

# Run tests with coverage
make test-coverage

# Run all QA checks
make qa
```

### Without Docker

```bash
composer install
composer test
composer test-coverage
composer qa
```

## Testing

The bundle has **100% code coverage** (144/144 lines, 35/35 methods, 11/11 classes). All tests are located in the `tests/` directory.

### Running Tests

```bash
# Run all tests
composer test

# Run tests with coverage report
composer test-coverage

# View coverage report
open coverage/index.html
```

### Test Structure

- `tests/NowoTwigInspectorBundleTest.php` - Bundle class tests
- `tests/DependencyInjection/` - Extension tests
- `tests/Controller/` - Controller tests
- `tests/DataCollector/` - Data collector tests
- `tests/Twig/` - Twig extension tests
- `tests/BoxDrawingsTest.php` - Box drawings utility tests

## Code Quality

The bundle uses PHP-CS-Fixer to enforce code style (PSR-12).

```bash
# Check code style
composer cs-check

# Fix code style
composer cs-fix
```

## CI/CD

The bundle uses GitHub Actions for continuous integration:

- **Tests**: Runs on PHP 8.1, 8.2, 8.3, 8.4, and 8.5 with Symfony 6.4, 7.0, and 8.0
  - PHP 8.1: Symfony 6.4 only (Symfony 7.0+ requires PHP 8.2+, Symfony 8.0 requires PHP 8.4+)
  - PHP 8.2: Symfony 6.4 and 7.0 (Symfony 8.0 requires PHP 8.4+)
  - PHP 8.3: Symfony 6.4 and 7.0 (Symfony 8.0 requires PHP 8.4+)
  - PHP 8.4: Symfony 6.4, 7.0, and 8.0
  - PHP 8.5: Symfony 6.4, 7.0, and 8.0
- **Code Style**: Automatically fixes code style on push
- **Coverage**: Validates 100% code coverage requirement
- **Dependabot**: Automatically updates dependencies

See `.github/workflows/ci.yml` for details.

## Building Assets

The bundle uses TypeScript and SCSS for its frontend assets. To build them:

```bash
# Install dependencies
npm install

# Build for production
npm run build

# Build for development
npm run build:dev

# Watch for changes
npm run watch
```

Or using Make:

```bash
make build-assets      # Production build
make build-assets-dev  # Development build
make watch-assets      # Watch mode
```

The built files are located in `src/Resources/assets/dist/`:
- `index.min.js` - Compiled TypeScript
- `style.min.css` - Compiled SCSS

## License

The MIT License (MIT). Please see [LICENSE](LICENSE) for more information.

## Author

Created by [Héctor Franco Aceituno](https://github.com/HecFranco) at [Nowo.tech](https://nowo.tech)

Based on [Oro Twig Inspector](https://github.com/oroinc/twig-inspector) by Oro, Inc.

