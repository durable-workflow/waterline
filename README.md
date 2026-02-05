# Waterline

An elegant UI for monitoring [workflows](https://github.com/durable-workflow/workflow).

## Installation

This UI is installable via [Composer](https://getcomposer.org).

```bash
composer require laravel-workflow/waterline

php artisan waterline:install
```

## Authorization

Waterline exposes a dashboard at the `/waterline` URL. By default, you will only be able to access this dashboard in the local environment. However, within your `app/Providers/WaterlineServiceProvider.php` file, there is an authorization gate definition. This authorization gate controls access to Waterline in non-local environments.

```
Gate::define('viewWaterline', function ($user) {
    return in_array($user->email, [
        'admin@example.com',
    ]);
});
```

This will allow only the single admin user to access the Waterline UI.


## Upgrading Waterline

After upgrading Waterline you must publish the latest assets.

```bash
composer require laravel-workflow/waterline

php artisan waterline:publish
```

## Dashboard View

![waterline_dashboard](https://github.com/user-attachments/assets/5688a234-4c02-4d5e-84d4-5f40b5fa27c5)

### Workflow View

![workflow](https://github.com/user-attachments/assets/da685466-7747-4c2f-ae10-300041381d51)

## Development

1. Install dependencies:
   ```bash
   composer install
   npm install
   ```
2. Build assets:
   ```bash
   npm run production
   ```
3. Publish assets to testbench:
   ```bash
   ./vendor/bin/testbench waterline:publish
   ```
4. Run migrations:
   ```bash
   ./vendor/bin/testbench workbench:create-sqlite-db
   ./vendor/bin/testbench migrate:fresh --database=sqlite
   ```
5. Start server:
   ```bash
   composer run serve
   ```
6. Access dashboard:
   - Local: http://localhost:8000/waterline
7. Create test workflow:
   ```bash
   ./vendor/bin/testbench workflow:create-test
   ```
8. Run queue worker:
   ```bash
   ./vendor/bin/testbench queue:work
   ```

<sub><sup>"Laravel" is a registered trademark of Taylor Otwell. This project is not affiliated, associated, endorsed, or sponsored by Taylor Otwell, nor has it been reviewed, tested, or certified by Taylor Otwell. The use of the trademark "Laravel" is for informational and descriptive purposes only. Waterline is not officially related to the Laravel trademark or Taylor Otwell.</sup></sub>
