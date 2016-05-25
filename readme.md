# WordPress Plugin Boiler Plate Redux
A boiler plate intended to serve the starting point of simpler plugins.

# Basic Usage
* Clone/Download the boilerplate
* Rename the Folder and `main-plugin-file.php` to reflect your plugin
* Follow TODO messages in what used to be `main-plugin-file.php`

# Extended Usage
* create folder `includes` and build your classes there. They will be automatically included in your main plugin file
* create folder `lib` and add any class libraries that need to be loaded early (classes used by includes files)
* create folder `assets` for any static javascript, css or data files that may be needed
* create folder `admin` and follow TODO in main plugin file for code that only runs on admin side
* create folders in `admin` that follow the same `lib` `includes` `assets` structure

Note: by keeping your functionality split up into classes in the `includes` directory, you keep you plugin clean and
it enables to you build your plugin in a modular OOP design. Enabling, disabling and troubleshooting become much
simpler when you work this way.

#Using Github Updater Plugin
This plugin includes meta lines in the main PHP file. By including the github.com url to your repository you can make 
use of GIT tagging system alongside WordPress update system to `release` your plugin as new stable versions are tagged.

See more about this at:
https://github.com/afragen/github-updater#description