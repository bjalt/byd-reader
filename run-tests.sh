#!/bin/bash

# Run all tests for this project
echo "Running PHPUnit tests..."
./vendor/bin/phpunit tests/ --verbose

if [ $? -eq 0 ]; then
    echo "✅ All tests passed!"
else
    echo "❌ Some tests failed"
    exit 1
fi