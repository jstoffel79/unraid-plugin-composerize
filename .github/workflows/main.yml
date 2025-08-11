# This is a GitHub Actions workflow to automate building and releasing your Unraid plugin.
#
# To use this, create a file at: .github/workflows/build-plugin.yml
# in your repository and paste this content into it.
#
name: Build and Release Unraid Plugin

# This workflow is triggered on every push to the 'main' branch.
# It also allows for manual runs from the Actions tab.
on:
  push:
    branches: [ main ]
  workflow_dispatch:

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

      # Step 4: Run the build script
      # This executes the script, creating the .txz archive and updating the .plg file.
      - name: Run build script
        id: build
        run: ./build.sh plugin/composerize.plg

      # Step 5: Commit the updated .plg file back to the repository
      - name: Commit updated plugin file
        run: |
          git config --global user.name 'github-actions[bot]'
          git config --global user.email 'github-actions[bot]@users.noreply.github.com'
          git add plugin/composerize.plg
          # Only commit and push if there are actual changes to the file
          if ! git diff-index --quiet HEAD; then
            git commit -m "ci: Build and update plugin file [skip ci]"
            git push
          else
            echo "No changes to commit."
          fi
      
      # Step 6: Generate a dynamic tag name based on the current date
      - name: Generate tag name
        id: tag
        run: echo "tag_name=v$(date +%Y.%m.%d)" >> $GITHUB_OUTPUT

      # Step 7: Create a GitHub Release and upload the build artifacts
      - name: Create Release and Upload Assets
        uses: softprops/action-gh-release@v2
        with:
          # Use the output from the previous step as the tag name
          tag_name: ${{ steps.tag.outputs.tag_name }}
          # The files to attach to the release.
          files: |
            ./archive/*.txz
            ./plugin/composerize.plg
