Composerize Unraid Plugin
This plugin provides a simple UI within Unraid to convert docker run commands from your existing container templates into the Docker Compose format. It's a handy tool for migrating containers to be managed by the Docker Compose Manager plugin.

Features
Automatic Template Detection: Automatically finds templates for your currently running Docker containers.

One-Click Conversion: Select a container from the dropdown to instantly generate its docker-compose.yml equivalent.

Direct Application: Apply the generated compose file directly to the Compose Manager plugin's project directory.

Theme Aware: The user interface is fully theme-aware and will automatically match your Unraid light or dark theme for a seamless look.

Installation
Community Applications (Recommended): Go to the "Apps" tab in your Unraid UI, search for "Composerize", and click install.

Manual Installation: Copy the composerize directory to /boot/config/plugins/ on your Unraid server and reboot.

Usage
In the Unraid UI, navigate to the Utilities menu and click on Composerize.

From the dropdown menu, select one of your currently running Docker containers.

The plugin will generate a docker-compose.yml preview in the text area below.

You can edit the generated compose file if needed.

Click the "Apply" button to save this configuration as a new stack for the Compose Manager plugin.
