# Snawbar Self-Updater

A complete Laravel package for handling system auto-updates, license verification, and update progress UI for Snawbar applications.

## Features

- **Automated System Updates**: Check for new versions, download update bundles (ZIP), extract, backup the database, and seamlessly swap files in production.
- **License Management**: Validates system licenses, checks expiration dates against a remote license server, and synchronizes local license information.
- **Ready-to-use UI**: Includes Blade components for an update progress bar, an update details modal, and a "set price" prompt for customers.
- **Background Processing**: Tracks update progress in the background and reports it to the frontend in real-time.

## Installation

To use this package in any new project (like your Restaurant system), you should host the package on a private Git repository (like GitHub or GitLab). 

### Step 1: Add the Repository
Add the repository to your new project's `composer.json` file:

```json
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/rabarvp1/slef-updater.git"
        }
    ]
```
*(If you are developing locally and the package folder is next to your project, you can use `"type": "path"` and `"url": "../self-updater"` instead).*

### Step 2: Download the Package
Once the repository is defined, you can download and install the package via composer:

```bash
composer require snawbar/self-updater
```

## Setup & Requirements

### 1. Database Requirements
Your application must have a `system_updates` table to keep track of versions. A basic migration should look like this:

```php
Schema::create('system_updates', function (Blueprint $table) {
    $table->id();
    $table->string('version');
    $table->string('user_price')->default('0');
    $table->string('version_price')->default('0');
    $table->timestamp('applied_at')->nullable();
});
```

### 2. Configuration Keys
The package provides its own configuration file which you can publish to your application:

```bash
php artisan vendor:publish --tag="self-updater-config"
```

This will create a `config/self-updater.php` file in your project. You must define your endpoints here. This allows you to use the package for completely different projects (e.g. Cashier vs Restaurant) by just pointing them to different servers:

**`config/self-updater.php`:**
- `update_url`: URL to check for general updates (e.g., `https://version-update.your-domain.com/updater/version.json`).
- `license_url`: URL to check the license status.
- `license_write_url`: URL to push the license data.
- `license_secret`: Secret key for API authentication.
- `license_local_path`: Absolute path to your `license.json` file.
- `version`: Current version fallback.

*(Note: If you don't publish the config, it will gracefully fallback to your `config('system.xxx')` and `config('license.xxx')` variables).*

## Usage

### Integrating the UI Components
To show the update panel in your dashboard, simply include the package's views in your Blade templates.

For example, in your `dashboard.blade.php`:

```blade
{{-- 1. Display the progress bar (visible during an update) --}}
@include('self-updater::update-progressbar')

{{-- 2. Display the modal showing new version info and changelog (optional) --}}
@include('self-updater::updates-modal', ['updateData' => $updateData])

{{-- 3. Display the modal for setting the update price --}}
@include('self-updater::set-price')

{{-- 4. Include the JavaScript logic that handles the update process --}}
@include('self-updater::progress')
```

### How to trigger the UI
The UI components depend on an `$updateData` array, which you can retrieve from the `UpdateCheckService`:

```php
use Snawbar\SelfUpdater\Services\UpdateCheckService;

public function index(UpdateCheckService $updateCheck)
{
    $updateData = $updateCheck->check();
    
    return view('dashboard.index', compact('updateData'));
}
```

### Routes
The package automatically registers the following routes under the `web` and `auth` middlewares:
- `POST /system/update`: Triggers the background auto-update process.
- `GET /system/update-progress`: Returns the current download/extract progress as JSON.
- `POST /system/set-price`: Saves the price set by the user for the update.

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
