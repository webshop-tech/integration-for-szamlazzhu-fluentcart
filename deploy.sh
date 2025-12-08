#!/bin/bash

# Load environment variables
if [ -f .env ]; then
    export $(cat .env | grep -v '^#' | xargs)
else
    echo "Error: .env file not found"
    exit 1
fi

# Check required variables
if [ -z "$SSH_CONNECTION" ] || [ -z "$WP_PATH" ]; then
    echo "Error: SSH_CONNECTION and WP_PATH must be set in .env"
    exit 1
fi

# Create zip file
echo "Creating zip package..."
./pack.sh

if [ ! -f "integration-for-szamlazzhu-fluentcart.zip" ]; then
    echo "Error: Failed to create zip file"
    exit 1
fi

# Upload to server
echo "Uploading to server..."
scp integration-for-szamlazzhu-fluentcart.zip "$SSH_CONNECTION:/tmp/"

if [ $? -ne 0 ]; then
    echo "Error: Failed to upload zip file"
    exit 1
fi

# Install via WP CLI
echo "Installing plugin via WP CLI..."
ssh "$SSH_CONNECTION" "cd $WP_PATH && sudo chown www-data /tmp/integration-for-szamlazzhu-fluentcart.zip && sudo -u www-data wp plugin install /tmp/integration-for-szamlazzhu-fluentcart.zip --force --activate"

if [ $? -ne 0 ]; then
    echo "Error: Failed to install plugin"
    exit 1
fi

# Cleanup
echo "Cleaning up..."
ssh "$SSH_CONNECTION" "sudo rm /tmp/integration-for-szamlazzhu-fluentcart.zip"

echo "Deployment completed successfully!"
