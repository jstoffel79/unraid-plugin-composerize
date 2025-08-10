# This is a GitHub Actions workflow to automate building and releasing your Unraid plugin.
#
# To use this, create a file at: .github/workflows/build-plugin.yml
# in your repository and paste this content into it.
#
name: Build and Release Unraid Plugin

# This workflow can be triggered in two ways:
# 1. Manually, by clicking the "Run workflow" button on the Actions tab.
# 2. Automatically, on every push to the 'main' branch (currently commented out).
on:
  workflow_dispatch: # Allows manual triggering
  # push:
  #   branches: [ main ]

jobs:
  build:
    runs-on: ubuntu-latest # Use the latest Ubuntu runner for a Linux environment

    steps:
      # Step 1: Check out the repository's code
      - name: Checkout repository
        uses: actions/checkout@v4

      # Step 2: Install any dependencies required by the build script
      - name: Install dependencies
        run: sudo apt-get update && sudo apt-get install -y dos2unix

      # Step 3: Make the build script executable
      - name: Make build script executable
        run: chmod +x build.sh

      # Step 4: List files for debugging
      # This helps confirm that the plugin file exists before the build script is run.
      - name: List files in workspace
        run: ls -lR

      # Step 5: Run the build script
      # This executes the script from the Canvas.
      # It will create the .txz archive and update the .plg file.
      - name: Run build script
        id: build
        run: ./build.sh plugin/composerize.plg

      # Step 6: Commit the updated .plg file back to the repository
      # This is important so the plugin URL in Unraid has the correct version and MD5 hash.
      - name: Commit updated plugin file
        run: |
          git config --global user.name 'github-actions[bot]'
          git config --global user.email 'github-actions[bot]@users.noreply.github.com'
          git add plugin/composerize.plg
          # Check if there are any changes to commit
          if ! git diff-index --quiet HEAD; then
            git commit -m "ci: Build and update plugin file [skip ci]"
            git push
          else
            echo "No changes to commit."
          fi

      # Step 7: Create a GitHub Release and upload the build artifacts
      # This uses a popular action to create a release and attach the files.
      - name: Create Release and Upload Assets
        uses: softprops/action-gh-release@v2
        with:
          # The tag will be created automatically, e.g., v2025.08.10
          tag_name: v$(date +%Y.%m.%d)
          # The files to attach to the release.
          # It looks for the .txz file in the 'archive' directory and the updated .plg file.
          files: |
            ./archive/*.txz
            ./plugin/composerize.plg
