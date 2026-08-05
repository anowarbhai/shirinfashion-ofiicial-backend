<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PDO;
use Throwable;

class InstallerController extends Controller
{
    protected string $lockFilePath;

    public function __construct()
    {
        $this->lockFilePath = storage_path('installed');
    }

    /**
     * Check if application is already installed.
     */
    public function status(): JsonResponse
    {
        $isInstalled = File::exists($this->lockFilePath);

        return response()->json([
            'installed' => $isInstalled,
            'lock_file' => $this->lockFilePath,
            'environment_file_exists' => File::exists(base_path('.env')),
        ]);
    }

    /**
     * Check PHP version, extensions, and folder permissions.
     */
    public function requirements(): JsonResponse
    {
        $this->ensureNotInstalled();

        $minPhpVersion = '8.2.0';
        $currentPhpVersion = PHP_VERSION;
        $phpOk = version_compare($currentPhpVersion, $minPhpVersion, '>=');

        $requiredExtensions = [
            'pdo' => extension_loaded('pdo'),
            'mbstring' => extension_loaded('mbstring'),
            'openssl' => extension_loaded('openssl'),
            'curl' => extension_loaded('curl'),
            'fileinfo' => extension_loaded('fileinfo'),
            'json' => extension_loaded('json'),
            'xml' => extension_loaded('xml'),
            'tokenizer' => extension_loaded('tokenizer'),
            'ctype' => extension_loaded('ctype'),
            'bcmath' => extension_loaded('bcmath'),
        ];

        // Database driver extensions check
        $dbDrivers = [
            'pdo_sqlite' => extension_loaded('pdo_sqlite'),
            'pdo_mysql' => extension_loaded('pdo_mysql'),
            'pdo_pgsql' => extension_loaded('pdo_pgsql'),
        ];

        $extensionsOk = !in_array(false, array_values($requiredExtensions), true);

        $directories = [
            'storage' => [
                'path' => storage_path(),
                'writable' => is_writable(storage_path()),
            ],
            'bootstrap_cache' => [
                'path' => base_path('bootstrap/cache'),
                'writable' => is_writable(base_path('bootstrap/cache')),
            ],
            'env_file' => [
                'path' => base_path('.env'),
                'writable' => File::exists(base_path('.env'))
                    ? is_writable(base_path('.env'))
                    : is_writable(base_path()),
            ],
        ];

        $directoriesOk = true;
        foreach ($directories as $dir) {
            if (!$dir['writable']) {
                $directoriesOk = false;
                break;
            }
        }

        return response()->json([
            'php' => [
                'current' => $currentPhpVersion,
                'minimum' => $minPhpVersion,
                'satisfied' => $phpOk,
            ],
            'extensions' => $requiredExtensions,
            'db_drivers' => $dbDrivers,
            'extensions_satisfied' => $extensionsOk,
            'directories' => $directories,
            'directories_satisfied' => $directoriesOk,
            'can_install' => $phpOk && $extensionsOk && $directoriesOk,
        ]);
    }

    /**
     * Test DB connection and update .env configuration.
     */
    public function environment(Request $request): JsonResponse
    {
        $this->ensureNotInstalled();

        $validated = $request->validate([
            'app_name' => 'nullable|string|max:100',
            'app_url' => 'nullable|url',
            'frontend_url' => 'nullable|url',
            'db_connection' => 'required|string|in:sqlite,mysql,pgsql,sqlsrv',
            'db_host' => 'required_if:db_connection,mysql,pgsql,sqlsrv|nullable|string',
            'db_port' => 'required_if:db_connection,mysql,pgsql,sqlsrv|nullable|numeric',
            'db_database' => 'required|string',
            'db_username' => 'nullable|string',
            'db_password' => 'nullable|string',
        ]);

        $connection = $validated['db_connection'];
        $database = $validated['db_database'];

        // If SQLite, handle database file creation if needed
        if ($connection === 'sqlite') {
            if ($database === ':memory:') {
                // memory DB ok
            } else {
                $sqlitePath = $database;
                if (!Str::startsWith($sqlitePath, ['/', 'C:\\', 'D:\\', 'E:\\', 'F:\\'])) {
                    $sqlitePath = database_path($database);
                }

                $directory = dirname($sqlitePath);
                if (!File::exists($directory)) {
                    File::makeDirectory($directory, 0755, true);
                }

                if (!File::exists($sqlitePath)) {
                    File::put($sqlitePath, '');
                }

                $database = $sqlitePath;
            }
        }

        // Test Database Connection
        try {
            if ($connection === 'sqlite') {
                $pdo = new PDO("sqlite:{$database}");
            } elseif ($connection === 'mysql') {
                $host = $validated['db_host'] ?? '127.0.0.1';
                $port = $validated['db_port'] ?? '3306';
                $username = $validated['db_username'] ?? 'root';
                $password = $validated['db_password'] ?? '';
                $dsn = "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4";
                $pdo = new PDO($dsn, $username, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            } elseif ($connection === 'pgsql') {
                $host = $validated['db_host'] ?? '127.0.0.1';
                $port = $validated['db_port'] ?? '5432';
                $username = $validated['db_username'] ?? 'postgres';
                $password = $validated['db_password'] ?? '';
                $dsn = "pgsql:host={$host};port={$port};dbname={$database}";
                $pdo = new PDO($dsn, $username, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            }
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Database connection failed: ' . $e->getMessage(),
            ], 422);
        }

        // Create or copy .env file if it doesn't exist
        $envPath = base_path('.env');
        $exampleEnvPath = base_path('.env.example');

        if (!File::exists($envPath)) {
            if (File::exists($exampleEnvPath)) {
                File::copy($exampleEnvPath, $envPath);
            } else {
                File::put($envPath, "APP_NAME=ShirinFashionBD\nAPP_ENV=local\nAPP_DEBUG=true\n");
            }
        }

        // Build key value pairs to update
        $appName = $validated['app_name'] ?? 'Digitrix Labs';
        $envUpdates = [
            'APP_NAME' => '"' . $appName . '"',
            'APP_URL' => $validated['app_url'] ?? 'http://127.0.0.1:8000',
            'FRONTEND_URL' => $validated['frontend_url'] ?? 'http://localhost:3000',
            'DB_CONNECTION' => $connection,
            'DB_DATABASE' => '"' . $database . '"',
        ];

        if ($connection !== 'sqlite') {
            $envUpdates['DB_HOST'] = $validated['db_host'] ?? '127.0.0.1';
            $envUpdates['DB_PORT'] = $validated['db_port'] ?? '3306';
            $envUpdates['DB_USERNAME'] = $validated['db_username'] ?? 'root';
            $envUpdates['DB_PASSWORD'] = '"' . ($validated['db_password'] ?? '') . '"';
        }

        // Check if APP_KEY or JWT_SECRET exist
        $envContent = File::get($envPath);
        if (!preg_match('/^APP_KEY=base64:/m', $envContent) && !preg_match('/^APP_KEY=[^\s]+/m', $envContent)) {
            $envUpdates['APP_KEY'] = 'base64:' . base64_encode(Str::random(32));
        }

        if (!preg_match('/^JWT_SECRET=[^\s]+/m', $envContent)) {
            $envUpdates['JWT_SECRET'] = Str::random(32);
        }

        $this->updateEnvFile($envPath, $envUpdates);

        return response()->json([
            'success' => true,
            'message' => 'Environment and database configuration saved successfully.',
            'db_connection' => $connection,
            'db_database' => $database,
        ]);
    }

    /**
     * Run database migrations and seeders.
     */
    public function migrate(Request $request): JsonResponse
    {
        $this->ensureNotInstalled();

        $seed = $request->boolean('seed', true);

        try {
            // Re-sync dynamic config from updated .env before running artisan
            Artisan::call('config:clear');

            Artisan::call('migrate:fresh', [
                '--force' => true,
            ]);
            $migrationOutput = Artisan::output();

            $seederOutput = '';
            if ($seed) {
                Artisan::call('db:seed', [
                    '--force' => true,
                ]);
                $seederOutput = Artisan::output();
            }

            return response()->json([
                'success' => true,
                'message' => 'Database migration and seeding completed successfully.',
                'migration_output' => trim($migrationOutput),
                'seeder_output' => trim($seederOutput),
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Migration failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Create or update initial Super Admin account.
     */
    public function admin(Request $request): JsonResponse
    {
        $this->ensureNotInstalled();

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'password' => 'required|string|min:8',
            'phone' => 'nullable|string|max:20',
        ]);

        try {
            $admin = User::updateOrCreate(
                ['email' => $validated['email']],
                [
                    'name' => $validated['name'],
                    'password' => Hash::make($validated['password']),
                    'role' => 'admin',
                    'phone' => $validated['phone'] ?? '01700000000',
                    'is_active' => true,
                    'email_verified_at' => now(),
                ]
            );

            return response()->json([
                'success' => true,
                'message' => 'Admin account created successfully.',
                'admin' => [
                    'id' => $admin->id,
                    'name' => $admin->name,
                    'email' => $admin->email,
                    'role' => $admin->role,
                ],
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create admin: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Mark installation as complete and lock the installer.
     */
    public function complete(): JsonResponse
    {
        $this->ensureNotInstalled();

        $installationDetails = [
            'installed_at' => date('Y-m-d H:i:s'),
            'app_version' => '1.0.0',
            'php_version' => PHP_VERSION,
        ];

        File::put($this->lockFilePath, json_encode($installationDetails, JSON_PRETTY_PRINT));

        return response()->json([
            'success' => true,
            'message' => 'Shirin Beauty Atelier Backend API installation completed successfully! Installer is now locked.',
            'details' => $installationDetails,
        ]);
    }

    /**
     * Ensure application is not already installed.
     */
    protected function ensureNotInstalled(): void
    {
        if (File::exists($this->lockFilePath)) {
            abort(response()->json([
                'success' => false,
                'message' => 'Application is already installed. To reinstall, delete the file: ' . $this->lockFilePath,
            ], 403));
        }
    }

    /**
     * Helper to modify or append .env file lines.
     */
    protected function updateEnvFile(string $envPath, array $data): void
    {
        $envContent = File::get($envPath);

        foreach ($data as $key => $value) {
            $pattern = "/^{$key}=.*/m";
            if (preg_match($pattern, $envContent)) {
                $envContent = preg_replace($pattern, "{$key}={$value}", $envContent);
            } else {
                $envContent .= "\n{$key}={$value}";
            }
        }

        File::put($envPath, trim($envContent) . "\n");
    }
}
