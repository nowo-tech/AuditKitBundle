#!/bin/bash

# Release script for v1.0.3
# This script prepares and tags the v1.0.3 release

set -e  # Exit on error

echo "🚀 Preparing release v1.0.3..."

# Step 1: Add all changes
echo "📦 Adding all changes..."
git add .

# Step 2: Commit changes
echo "💾 Committing changes..."
git commit -m "chore: Prepare release v1.0.3

- Add comprehensive test coverage for new features
- Translate CONTRIBUTING.md to English
- Add BRANCHING.md with complete branching policy
- Add Symfony Flex Recipe for automatic configuration
- Add InstallCommand for manual configuration
- Add Configuration system with exclusions and metrics
- Improve security validations in OpenTemplateController
- Update all documentation for consistency
- Clarify Flex Recipe vs Install Command usage"

# Step 3: Create tag
echo "🏷️  Creating tag v1.0.3..."
git tag -a v1.0.3 -m "Release version 1.0.3

- Added comprehensive test coverage (100% for new code)
- Added Symfony Flex Recipe for automatic configuration
- Added InstallCommand for manual installations
- Added Configuration system with template/block exclusions
- Added Template Usage Metrics in Web Profiler
- Added Performance Optimizations
- Enhanced security validations
- Improved documentation (all in English)
- Added BRANCHING.md with complete branching policy"

# Step 4: Show what will be pushed
echo ""
echo "📋 Summary of changes to be pushed:"
echo "======================================"
git log --oneline HEAD~1..HEAD
echo ""
echo "🏷️  Tag to be created:"
git tag -l -n1 v1.0.3
echo ""
echo "======================================"
echo ""
read -p "Do you want to push commits and tag to remote? (y/N) " -n 1 -r
echo ""

if [[ $REPLY =~ ^[Yy]$ ]]; then
    echo "📤 Pushing commits to origin/main..."
    git push origin main
    
    echo "📤 Pushing tag v1.0.3 to origin..."
    git push origin v1.0.3
    
    echo ""
    echo "✅ Release v1.0.3 completed successfully!"
    echo ""
    echo "Next steps:"
    echo "  - Verify the tag on GitHub: https://github.com/nowo-tech/twig-inspector-bundle/releases"
    echo "  - Packagist will automatically detect the new tag"
    echo "  - Update any dependent projects to use ^1.0.3"
else
    echo "❌ Push cancelled. You can push manually later with:"
    echo "   git push origin main"
    echo "   git push origin v1.0.3"
fi

