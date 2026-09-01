#!/bin/bash

# Run all tests for this project.
#
# Go through the Symfony CLI when it is available: this machine has several PHP
# versions installed, and vendor/bin/phpunit would otherwise run under whatever
# `php` comes first in PATH, which fails Composer's platform check (>=8.4).
echo "Running PHPUnit tests..."

if command -v symfony > /dev/null 2>&1; then
    symfony php vendor/bin/phpunit "$@"
else
    ./vendor/bin/phpunit "$@"
fi
status=$?

if [ $status -eq 0 ]; then
    echo "✅ All tests passed!"
else
    echo "❌ Some tests failed"
    exit 1
fi
