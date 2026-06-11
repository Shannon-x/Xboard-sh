#!/bin/sh
set -e # Exit immediately if a command exits with a non-zero status.

# File to touch to indicate successful installation for subsequent runs
INSTALL_LOCK_FILE="/www/storage/INSTALLED.lock"

# Check if the application is already installed
if [ -f "$INSTALL_LOCK_FILE" ]; then
    echo "Application already installed. Skipping installation."
else
    echo "Application not installed yet. Starting installation process..."

    # 1. Ensure .env file exists, copy from .env.example if not
    if [ ! -f ".env" ]; then
        echo "No .env file found. Copying from .env.example..."
        cp .env.example .env
        echo ".env file copied."
    else
        echo ".env file already exists."
    fi

    # 2. Check for APP_KEY, generate if not set
    if ! grep -q "^APP_KEY=.*[^=]$" .env; then
        echo "APP_KEY not set or empty in .env. Generating APP_KEY..."
        php artisan key:generate --force
        echo "APP_KEY generated."
    else
        echo "APP_KEY already set in .env."
    fi

    # 3. Link storage
    # The Dockerfile already does this, but ensuring it's done before install
    # if this script were to be run in a context where it might not have been.
    # Should not cause harm if run again.
    echo "Linking storage..."
    php artisan storage:link
    echo "Storage linked."

    # 4. Run migrations (xboard:install also runs them, but this is a safeguard)
    echo "Running database migrations..."
    php artisan migrate --force
    echo "Database migrations completed."

    # 5. Run Xboard installation
    # This will use ADMIN_ACCOUNT from env if provided
    echo "Running Xboard install command..."
    php artisan xboard:install

    if [ $? -eq 0 ]; then
        echo "Xboard installation command completed successfully."
        # Create a lock file to indicate successful installation
        touch "$INSTALL_LOCK_FILE"
        echo "Installation lock file created at $INSTALL_LOCK_FILE."
    else
        echo "Xboard installation command failed. Please check logs."
        # Optionally, exit here if install failure should stop the container
        # exit 1
    fi
fi

echo "Starting supervisord..."
# Execute the CMD from the Dockerfile (supervisord)
exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf
