#!/bin/bash
set -e

echo "Building PCT Class Scheduling System..."

# Update PHP extensions if needed
apt-get update
apt-get install -y php-mysql

# Create necessary directories
mkdir -p logs
mkdir -p config

# Set permissions
chmod -R 755 .
chmod -R 755 logs

echo "Build completed successfully!"
