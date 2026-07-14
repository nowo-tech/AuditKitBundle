# Configuration Guide

## How Configuration Works in Symfony Bundles

### 1. Automatic YAML Creation

**Symfony Flex Recipes** (for bundles in official repository):
- When you install a bundle via `composer require` from Packagist, Symfony Flex **automatically creates** the configuration file
- This requires the bundle to have a recipe in the Symfony Flex repository
- The recipe defines what files to create and where
- **If Flex Recipe works, you don't need to do anything else** - the file is created automatically

**For this bundle**:
- A Flex Recipe is available in `.symfony/recipe/` (when published to Packagist)
- **If installing from Packagist**: The file is created automatically - no command needed
- **If installing from Git/private repo**: Use `php bin/console nowo:twig-inspector:install` (Flex Recipes don't work for private bundles)

### 2. Configuration File Loading

Symfony automatically loads configuration files from:
- `config/packages/{bundle_alias}.yaml` (for all environments)
- `config/packages/{env}/{bundle_alias}.yaml` (for specific environment)

The bundle alias is `nowo_twig_inspector`, so Symfony looks for:
- `config/packages/nowo_twig_inspector.yaml`
- `config/packages/dev/nowo_twig_inspector.yaml`
- `config/packages/test/nowo_twig_inspector.yaml`
- etc.

### 3. Configuration Processing

When the bundle loads, it:

1. **Collects all config files** that match the bundle alias
2. **Merges them** with default values from `Configuration.php`
3. **Validates** the configuration against the schema
4. **Uses the merged result** to configure services

**Example**:
```php
// In NowoTwigInspectorExtension::load()
$processor = new Processor();
$configuration = new Configuration();
$config = $processor->processConfiguration($configuration, $configs);
// $configs contains values from all YAML files
// $config contains merged values (YAML + defaults)
```

### 4. If YAML Exists

✅ **The bundle respects your YAML file**
- Your custom values override defaults
- Only specified values are changed
- Unspecified values use defaults

**Example**:
```yaml
# Your config/packages/nowo_twig_inspector.yaml
nowo_twig_inspector:
    excluded_templates:
        - 'admin/*'
    # Other options use defaults
```

Result: `excluded_templates` uses your value, everything else uses defaults.

### 5. If YAML Doesn't Exist

✅ **The bundle uses default values**
- All values come from `Configuration.php`
- No file is required
- Bundle works perfectly without configuration

### 6. When Uninstalling the Bundle

❌ **YAML file is NOT automatically deleted**

**Why?**
- The file may contain your custom configuration
- You might want to keep it for reference
- Symfony cannot determine if it's safe to delete
- Other bundles might use similar configuration patterns

**To remove manually**:
```bash
rm config/packages/dev/nowo_twig_inspector.yaml
# or
rm config/packages/nowo_twig_inspector.yaml
```

## Best Practices

1. **Use Flex Recipe** (if available): Automatically creates config file during installation
2. **Use Install Command**: `php bin/console nowo:twig-inspector:install` for manual setup
3. **Keep it simple**: Only configure what you need to change from defaults
4. **Version control**: Commit your config file to track customizations
5. **Document changes**: Add comments explaining why you changed defaults

## Summary

| Scenario | YAML Created? | YAML Used? | YAML Deleted on Uninstall? |
|----------|---------------|------------|---------------------------|
| Flex Recipe available | ✅ Yes (auto) | ✅ Yes | ❌ No |
| Install command | ✅ Yes (manual) | ✅ Yes | ❌ No |
| Manual creation | ✅ Yes (you create) | ✅ Yes | ❌ No |
| No config file | ❌ No | ✅ Uses defaults | N/A |

**Key Points**:
- ✅ Bundle **always** respects YAML if it exists
- ✅ Bundle **always** works without YAML (uses defaults)
- ❌ YAML is **never** auto-deleted (by design)

