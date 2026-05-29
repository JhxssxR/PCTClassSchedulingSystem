# Render Web Service Configuration
# This file configures the PHP runtime and build environment for Render

# Use PHP with Apache
runtime: "php"

# Environment
environment: "production"

# Build configuration
buildCommand: "bash ./build.sh"

# Specify document root (where index.php is located)
documentRoot: "/"

# Health check configuration
healthCheckPath: "/index.php"

# For pure PHP without Apache, use:
# startCommand: "php -S 0.0.0.0:3000"
# This starts PHP built-in server on port 3000

# For Apache configuration, Render uses default PHP Apache setup
# Static files served from root

# Logging
errorLogsPath: "logs/php_errors.log"
accessLogsPath: "logs/php_access.log"

# Maximum request timeout (in seconds)
maxRequestSize: "50M"

# PHP configuration overrides can be set via environment variables
# PHP_MEMORY_LIMIT=256M
# PHP_MAX_UPLOAD_SIZE=50M
