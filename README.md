# Unraid Plugin: Composerize v3

A simple utility plugin for Unraid to automatically convert your running Docker container templates into Docker Compose stacks. This tool is designed to help you migrate from individual Docker containers managed by Unraid's Docker Manager to a more standardized setup using Docker Compose.

It intelligently reads the configuration of your active containers, converts them into a `docker run` command, and then uses the [composerize](https://github.com/magicmark/composerize) library to generate the final `docker-compose.yml` content directly in your browser.

## ✨ Features

-   **Automatic Detection:** Scans for all currently running Docker containers that have an associated template.
-   **Broad Compatibility:** Parses both user-created templates (`templates-user` folder) and default templates from Community Applications.
-   **Robust Parsing:** Includes workarounds for common issues in Unraid's template helper functions, ensuring even templates with minor errors (like a missing `<Network>` tag) are processed correctly.
-   **Instant Conversion:** Generates `docker-compose.yml` content on the fly in the UI.
-   **Seamless Integration:** Saves the generated compose files directly into the `compose.manager` plugin's projects directory, ready to be managed.

## 📋 Requirements

-   **Unraid OS:** Version 6.9 or newer.
-   **Docker Manager:** (Standard on Unraid)
-   **[Compose Manager Plugin](https://forums.unraid.net/topic/114415-plugin-docker-compose-manager/):** Required for saving and managing the generated compose stacks.
-   **[Community Applications Plugin](https://forums.unraid.net/topic/38582-plug-in-community-applications/):** Required for easy installation.

## 🚀 Installation

1.  **From Community Applications:**
    -   Go to the **Apps** tab in your Unraid dashboard.
    -   Search for "Composerize".
    -   Click **Install**.

2.  **Manual Installation:**
    -   Go to the **Plugins** tab -> **Install Plugin**.
    -   Paste the following URL into the text box:
        ```
        [https://raw.githubusercontent.com/jstoffel79/unraid-plugin-composerize/main/plugin/composerize.plg](https://raw.githubusercontent.com/jstoffel79/unraid-plugin-composerize/main/plugin/composerize.plg)
        ```
    -   Click **Install**.

## 💡 Usage

1.  After installation, navigate to the **Settings** tab in your Unraid dashboard.
2.  Click on **Composerize**.
3.  From the dropdown menu, select one of your currently running Docker containers.
4.  The `docker-compose.yml` content will be automatically generated in the "Compose Preview" text area.
5.  You can make manual adjustments to the generated YAML if needed.
6.  Click the **Apply** button to save the file. The plugin will create a new project folder in your `compose.manager` directory, named after the container.

## 📝 Notes

-   If a running container does not appear in the dropdown, it may be because its template file (`.xml`) is severely malformed or unreadable. The plugin will log these errors to the main Unraid system log (**Tools -> Log**).
-   The `service` name in the generated YAML (e.g., `linuxserver:`) is automatically determined by the Docker image name. This is primarily for cosmetic purposes. The `container_name:` will be set correctly based on your template.
