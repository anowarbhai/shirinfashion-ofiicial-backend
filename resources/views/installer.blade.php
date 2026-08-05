<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Shirin Beauty Atelier — Laravel API Standalone Web Installer</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { background-color: #090d16; font-family: system-ui, -apple-system, sans-serif; }
    </style>
</head>
<body class="text-slate-100 min-h-screen flex flex-col justify-center items-center p-4">

    <div class="max-w-2xl w-full bg-slate-900 border border-slate-800 rounded-2xl shadow-2xl overflow-hidden my-8">
        
        <!-- Header -->
        <div class="bg-gradient-to-r from-rose-950 via-slate-900 to-purple-950 p-6 border-b border-slate-800 text-center">
            <span class="inline-block px-3 py-1 bg-rose-500/20 text-rose-300 text-xs font-semibold uppercase tracking-wider rounded-full mb-2">
                Laravel Standalone Installer
            </span>
            <h1 class="text-2xl md:text-3xl font-extrabold text-white">Shirin Beauty Atelier Backend API</h1>
            <p class="text-slate-400 text-xs md:text-sm mt-1">Configure database, environment & Super Admin account directly in Laravel</p>
            
            <!-- Step Navigation Bar -->
            <div class="flex justify-center items-center gap-4 mt-6 text-xs">
                <div id="step-nav-1" class="font-bold text-rose-400">1. Requirements</div>
                <div class="text-slate-700">→</div>
                <div id="step-nav-2" class="text-slate-500">2. Database</div>
                <div class="text-slate-700">→</div>
                <div id="step-nav-3" class="text-slate-500">3. Super Admin</div>
                <div class="text-slate-700">→</div>
                <div id="step-nav-4" class="text-slate-500">4. Complete</div>
            </div>
        </div>

        <!-- Alert Notification Box -->
        <div id="alert-box" class="hidden p-4 text-xs font-medium border-b border-slate-800"></div>

        <!-- Installer Body Container -->
        <div class="p-6 md:p-8">

            <!-- STEP 1: REQUIREMENTS -->
            <div id="step-1" class="space-y-5">
                <h2 class="text-lg font-bold text-white">Step 1: System Requirements & Permissions</h2>
                <div id="req-loading" class="py-8 text-center text-slate-400 text-sm">
                    <div class="w-6 h-6 border-2 border-rose-500 border-t-transparent rounded-full animate-spin mx-auto mb-2"></div>
                    Checking PHP version, extensions & folder permissions...
                </div>

                <div id="req-content" class="hidden space-y-4">
                    <div class="bg-slate-800/60 border border-slate-700/60 rounded-xl p-4 flex justify-between items-center text-xs">
                        <div>
                            <p class="font-semibold text-white">PHP Version</p>
                            <p id="php-ver-text" class="text-slate-400 mt-0.5"></p>
                        </div>
                        <span id="php-ver-badge" class="px-2.5 py-1 rounded-full font-bold"></span>
                    </div>

                    <div class="bg-slate-800/60 border border-slate-700/60 rounded-xl p-4 space-y-2">
                        <p class="text-xs font-semibold text-white">PHP Extensions</p>
                        <div id="ext-grid" class="grid grid-cols-2 gap-2 text-xs"></div>
                    </div>

                    <div class="bg-slate-800/60 border border-slate-700/60 rounded-xl p-4 space-y-2">
                        <p class="text-xs font-semibold text-white">Directory Write Permissions</p>
                        <div id="dir-list" class="space-y-1 text-xs"></div>
                    </div>

                    <div class="pt-4 flex justify-end">
                        <button id="btn-to-step-2" onclick="goToStep(2)" class="px-6 py-2.5 bg-rose-600 hover:bg-rose-500 font-semibold text-xs rounded-xl text-white transition shadow-lg shadow-rose-600/30">
                            Continue to Database Setup →
                        </button>
                    </div>
                </div>
            </div>

            <!-- STEP 2: DATABASE SETUP -->
            <div id="step-2" class="hidden space-y-5">
                <h2 class="text-lg font-bold text-white">Step 2: Database Configuration</h2>
                
                <div class="grid grid-cols-2 gap-3 text-xs">
                    <button type="button" onclick="setDriver('sqlite')" id="driver-sqlite" class="p-3.5 rounded-xl border border-rose-600 bg-rose-950/40 text-left">
                        <div class="font-bold text-white mb-0.5">SQLite (Recommended)</div>
                        <div class="text-slate-400 text-[11px]">Embedded SQLite file database. Zero config needed.</div>
                    </button>
                    <button type="button" onclick="setDriver('mysql')" id="driver-mysql" class="p-3.5 rounded-xl border border-slate-700 bg-slate-800/60 text-left opacity-70">
                        <div class="font-bold text-white mb-0.5">MySQL / MariaDB</div>
                        <div class="text-slate-400 text-[11px]">Requires local or remote MySQL server instance.</div>
                    </button>
                </div>

                <div class="bg-slate-800/60 border border-slate-700/60 rounded-xl p-4 space-y-3 text-xs">
                    <div id="sqlite-fields">
                        <label class="block font-semibold text-slate-300 mb-1 uppercase tracking-wider text-[10px]">Database File</label>
                        <input type="text" id="db_database" value="database/database.sqlite" class="w-full px-3 py-2 bg-slate-900 border border-slate-700 rounded-lg text-white font-mono focus:outline-none focus:border-rose-500">
                    </div>

                    <div id="mysql-fields" class="hidden space-y-3">
                        <div class="grid grid-cols-3 gap-2">
                            <div class="col-span-2">
                                <label class="block font-semibold text-slate-300 mb-1 uppercase tracking-wider text-[10px]">Host</label>
                                <input type="text" id="db_host" value="127.0.0.1" class="w-full px-3 py-2 bg-slate-900 border border-slate-700 rounded-lg text-white font-mono">
                            </div>
                            <div>
                                <label class="block font-semibold text-slate-300 mb-1 uppercase tracking-wider text-[10px]">Port</label>
                                <input type="number" id="db_port" value="3306" class="w-full px-3 py-2 bg-slate-900 border border-slate-700 rounded-lg text-white font-mono">
                            </div>
                        </div>
                        <div>
                            <label class="block font-semibold text-slate-300 mb-1 uppercase tracking-wider text-[10px]">Database Name</label>
                            <input type="text" id="db_name" value="digitrixlabs" class="w-full px-3 py-2 bg-slate-900 border border-slate-700 rounded-lg text-white font-mono">
                        </div>
                        <div class="grid grid-cols-2 gap-2">
                            <div>
                                <label class="block font-semibold text-slate-300 mb-1 uppercase tracking-wider text-[10px]">Username</label>
                                <input type="text" id="db_user" value="root" class="w-full px-3 py-2 bg-slate-900 border border-slate-700 rounded-lg text-white font-mono">
                            </div>
                            <div>
                                <label class="block font-semibold text-slate-300 mb-1 uppercase tracking-wider text-[10px]">Password</label>
                                <input type="password" id="db_pass" value="" placeholder="(optional)" class="w-full px-3 py-2 bg-slate-900 border border-slate-700 rounded-lg text-white font-mono">
                            </div>
                        </div>
                    </div>
                    <div class="border-t border-slate-700/60 pt-3">
                        <label class="block font-semibold text-slate-300 mb-1 uppercase tracking-wider text-[10px]">Frontend Web URL (Next.js App)</label>
                        <input type="url" id="frontend_url" value="http://localhost:3000" class="w-full px-3 py-2 bg-slate-900 border border-slate-700 rounded-lg text-white font-mono focus:outline-none focus:border-rose-500">
                        <p class="text-[10px] text-slate-500 mt-1">Configures CORS and redirection links for your Next.js storefront/admin UI.</p>
                    </div>
                </div>

                <div class="flex justify-between items-center pt-2">
                    <button onclick="goToStep(1)" class="px-4 py-2 bg-slate-800 text-slate-300 text-xs font-semibold rounded-xl">← Back</button>
                    <button onclick="saveEnvironment()" id="btn-save-db" class="px-6 py-2.5 bg-rose-600 hover:bg-rose-500 text-white font-semibold text-xs rounded-xl shadow-lg shadow-rose-600/30">
                        Test & Save DB Connection →
                    </button>
                </div>
            </div>

            <!-- STEP 3: SUPER ADMIN ACCOUNT SETUP -->
            <div id="step-3" class="hidden space-y-5">
                <h2 class="text-lg font-bold text-white">Step 3: Create Super Admin Account</h2>
                
                <div class="bg-slate-800/60 border border-slate-700/60 rounded-xl p-4 space-y-3 text-xs">
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block font-semibold text-slate-300 mb-1">Admin Full Name</label>
                            <input type="text" id="admin_name" value="Super Admin" class="w-full px-3 py-2 bg-slate-900 border border-slate-700 rounded-lg text-white">
                        </div>
                        <div>
                            <label class="block font-semibold text-slate-300 mb-1">Admin Email Address</label>
                            <input type="email" id="admin_email" value="admin@shirinfashionbd.test" class="w-full px-3 py-2 bg-slate-900 border border-slate-700 rounded-lg text-white">
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block font-semibold text-slate-300 mb-1">Admin Password (min 8 chars)</label>
                            <input type="password" id="admin_password" value="password" class="w-full px-3 py-2 bg-slate-900 border border-slate-700 rounded-lg text-white">
                        </div>
                        <div>
                            <label class="block font-semibold text-slate-300 mb-1">Admin Phone Number</label>
                            <input type="text" id="admin_phone" value="01700000000" class="w-full px-3 py-2 bg-slate-900 border border-slate-700 rounded-lg text-white">
                        </div>
                    </div>
                </div>

                <div class="flex justify-between items-center pt-2">
                    <button onclick="goToStep(2)" class="px-4 py-2 bg-slate-800 text-slate-300 text-xs font-semibold rounded-xl">← Back</button>
                    <button onclick="runInstallation()" id="btn-install" class="px-6 py-3 bg-gradient-to-r from-rose-600 to-purple-600 hover:from-rose-500 hover:to-purple-500 text-white font-bold text-xs rounded-xl shadow-xl shadow-rose-600/30">
                        🚀 Run Migrations & Finish Installation
                    </button>
                </div>
            </div>

            <!-- STEP 4: COMPLETE / FINISH -->
            <div id="step-4" class="hidden text-center py-6 space-y-5">
                <div class="w-16 h-16 bg-emerald-500/20 text-emerald-400 border border-emerald-500/30 rounded-full flex items-center justify-center mx-auto text-3xl">
                    🎉
                </div>
                <h2 class="text-xl font-bold text-white">Laravel API Installation Completed!</h2>
                <p class="text-slate-400 text-xs max-w-md mx-auto">
                    Database tables created, seeded, and Super Admin account configured successfully.
                </p>

                <div class="bg-slate-800/80 border border-slate-700 rounded-xl p-4 text-left max-w-sm mx-auto space-y-1.5 text-xs font-mono">
                    <div class="flex justify-between"><span class="text-slate-400">Email:</span> <span id="res-email" class="text-white font-bold"></span></div>
                    <div class="flex justify-between"><span class="text-slate-400">Password:</span> <span id="res-pass" class="text-slate-300"></span></div>
                    <div class="flex justify-between"><span class="text-slate-400">API Status:</span> <span id="res-url" class="text-emerald-400 font-bold"></span></div>
                </div>

                <div class="pt-4 flex justify-center gap-3">
                    <a id="btn-open-frontend-admin" href="http://localhost:3000/admin/login" target="_blank" class="px-6 py-2.5 bg-rose-600 hover:bg-rose-500 text-white font-semibold text-xs rounded-xl shadow-lg shadow-rose-600/30">
                        Open Next.js Admin Panel (http://localhost:3000/admin/login) →
                    </a>
                </div>
            </div>

        </div>
    </div>

    <script>
        let currentDriver = 'sqlite';

        // Auto-detect base API prefix (supports XAMPP with or without mod_rewrite)
        const RAW_BASE = "{{ url('/') }}".replace(/\/$/, '');
        let API_PREFIX = `${RAW_BASE}/api`;

        function showAlert(msg, isSuccess = false) {
            const box = document.getElementById('alert-box');
            box.className = isSuccess 
                ? 'p-4 text-xs font-medium border-b border-emerald-800 bg-emerald-950/80 text-emerald-200 block' 
                : 'p-4 text-xs font-medium border-b border-rose-800 bg-rose-950/80 text-rose-200 block';
            box.innerText = msg;
        }

        function hideAlert() {
            document.getElementById('alert-box').className = 'hidden';
        }

        function goToStep(s) {
            hideAlert();
            [1, 2, 3, 4].forEach(i => {
                document.getElementById(`step-${i}`).className = i === s ? 'space-y-5 block' : 'hidden';
                const nav = document.getElementById(`step-nav-${i}`);
                if (nav) {
                    nav.className = i === s ? 'font-bold text-rose-400' : (i < s ? 'text-emerald-400' : 'text-slate-500');
                }
            });
        }

        function setDriver(driver) {
            currentDriver = driver;
            const btnSqlite = document.getElementById('driver-sqlite');
            const btnMysql = document.getElementById('driver-mysql');
            const fieldsSqlite = document.getElementById('sqlite-fields');
            const fieldsMysql = document.getElementById('mysql-fields');

            if (driver === 'sqlite') {
                btnSqlite.className = 'p-3.5 rounded-xl border border-rose-600 bg-rose-950/40 text-left';
                btnMysql.className = 'p-3.5 rounded-xl border border-slate-700 bg-slate-800/60 text-left opacity-70';
                fieldsSqlite.className = 'block';
                fieldsMysql.className = 'hidden';
            } else {
                btnMysql.className = 'p-3.5 rounded-xl border border-rose-600 bg-rose-950/40 text-left';
                btnSqlite.className = 'p-3.5 rounded-xl border border-slate-700 bg-slate-800/60 text-left opacity-70';
                fieldsMysql.className = 'space-y-3 block';
                fieldsSqlite.className = 'hidden';
            }
        }

        async function fetchRequirements() {
            try {
                // Try standard API prefix first
                let statusRes = await fetch(`${API_PREFIX}/installer/status`, {
                    headers: { 'Accept': 'application/json' }
                });

                // If 404, fallback to index.php prefix (for XAMPP without mod_rewrite)
                if (!statusRes.ok && statusRes.status === 404) {
                    API_PREFIX = `${RAW_BASE}/index.php/api`;
                    statusRes = await fetch(`${API_PREFIX}/installer/status`, {
                        headers: { 'Accept': 'application/json' }
                    });
                }

                if (statusRes.ok) {
                    const statusData = await statusRes.json();
                    if (statusData.installed) {
                        showAlert('Laravel API is already installed and locked.', true);
                    }
                }

                const res = await fetch(`${API_PREFIX}/installer/requirements`, {
                    headers: { 'Accept': 'application/json' }
                });

                if (!res.ok) {
                    throw new Error(`HTTP ${res.status} ${res.statusText}`);
                }

                const data = await res.json();

                document.getElementById('req-loading').className = 'hidden';
                document.getElementById('req-content').className = 'block space-y-4';

                document.getElementById('php-ver-text').innerText = `Required >= ${data.php.minimum} | Current: ${data.php.current}`;
                const badge = document.getElementById('php-ver-badge');
                badge.innerText = data.php.satisfied ? '✓ Satisfied' : '✕ Outdated';
                badge.className = data.php.satisfied ? 'px-2.5 py-1 rounded-full font-bold bg-emerald-500/20 text-emerald-400' : 'px-2.5 py-1 rounded-full font-bold bg-rose-500/20 text-rose-400';

                const extGrid = document.getElementById('ext-grid');
                extGrid.innerHTML = '';
                Object.entries(data.extensions).forEach(([ext, ok]) => {
                    extGrid.innerHTML += `<div class="bg-slate-900 p-2 border border-slate-800 rounded flex justify-between">
                        <span>${ext}</span><span class="${ok ? 'text-emerald-400 font-bold' : 'text-rose-400 font-bold'}">${ok ? '✓' : '✕'}</span>
                    </div>`;
                });

                const dirList = document.getElementById('dir-list');
                dirList.innerHTML = '';
                Object.entries(data.directories).forEach(([k, info]) => {
                    dirList.innerHTML += `<div class="bg-slate-900 p-2 border border-slate-800 rounded flex justify-between">
                        <span>${k} (${info.path})</span><span class="${info.writable ? 'text-emerald-400 font-bold' : 'text-rose-400 font-bold'}">${info.writable ? '✓ Writable' : '✕ Error'}</span>
                    </div>`;
                });

            } catch (err) {
                document.getElementById('req-loading').innerHTML = `
                    <div class="p-3 bg-rose-950/50 border border-rose-800/80 rounded-xl text-rose-300 text-xs">
                        <p class="font-bold mb-1">Failed to connect to API installer endpoints.</p>
                        <p class="text-[11px] text-rose-400/90">Attempted Endpoint: <code class="bg-rose-900/40 px-1 rounded">${API_PREFIX}/installer/requirements</code></p>
                        <p class="text-[11px] text-slate-400 mt-2">Error Details: ${err.message}</p>
                    </div>
                `;
            }
        }

        async function saveEnvironment() {
            hideAlert();
            const btn = document.getElementById('btn-save-db');
            btn.innerText = 'Testing Connection...';
            btn.disabled = true;

            const payload = {
                app_name: 'Shirin Beauty Atelier',
                frontend_url: document.getElementById('frontend_url').value,
                db_connection: currentDriver,
                db_database: currentDriver === 'sqlite' ? document.getElementById('db_database').value : document.getElementById('db_name').value,
                db_host: currentDriver === 'mysql' ? document.getElementById('db_host').value : null,
                db_port: currentDriver === 'mysql' ? document.getElementById('db_port').value : null,
                db_username: currentDriver === 'mysql' ? document.getElementById('db_user').value : null,
                db_password: currentDriver === 'mysql' ? document.getElementById('db_pass').value : null,
            };

            try {
                const res = await fetch(`${API_PREFIX}/installer/environment`, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json', 'Accept': 'application/json'},
                    body: JSON.stringify(payload)
                });
                const data = await res.json();
                if (res.ok && data.success) {
                    showAlert('Database connection verified and saved!', true);
                    setTimeout(() => goToStep(3), 1000);
                } else {
                    showAlert(data.message || 'Database connection failed.');
                }
            } catch (e) {
                showAlert('Error: ' + e.message);
            } finally {
                btn.innerText = 'Test & Save DB Connection →';
                btn.disabled = false;
            }
        }

        async function runInstallation() {
            hideAlert();
            const btn = document.getElementById('btn-install');
            btn.innerText = 'Migrating & Installing...';
            btn.disabled = true;

            try {
                // Migrate & Seed
                const migRes = await fetch(`${API_PREFIX}/installer/migrate`, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json', 'Accept': 'application/json'},
                    body: JSON.stringify({seed: true})
                });
                const migData = await migRes.json();
                if (!migRes.ok || !migData.success) throw new Error(migData.message || 'Migration failed');

                // Admin Account
                const email = document.getElementById('admin_email').value;
                const pass = document.getElementById('admin_password').value;
                const adminRes = await fetch(`${API_PREFIX}/installer/admin`, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json', 'Accept': 'application/json'},
                    body: JSON.stringify({
                        name: document.getElementById('admin_name').value,
                        email: email,
                        password: pass,
                        phone: document.getElementById('admin_phone').value
                    })
                });
                const adminData = await adminRes.json();
                if (!adminRes.ok || !adminData.success) throw new Error(adminData.message || 'Admin creation failed');

                // Complete
                await fetch(`${API_PREFIX}/installer/complete`, {
                    method: 'POST',
                    headers: {'Accept': 'application/json'}
                });

                document.getElementById('res-email').innerText = email;
                document.getElementById('res-pass').innerText = pass;
                document.getElementById('res-url').innerText = API_PREFIX;

                // Update Frontend Admin Link Dynamically
                const rawFrontend = (document.getElementById('frontend_url').value || 'http://localhost:3000').replace(/\/$/, '');
                const adminLoginUrl = `${rawFrontend}/admin/login`;
                const btnOpenAdmin = document.getElementById('btn-open-frontend-admin');
                if (btnOpenAdmin) {
                    btnOpenAdmin.href = adminLoginUrl;
                    btnOpenAdmin.innerText = `Open Next.js Admin Panel (${adminLoginUrl}) →`;
                }

                goToStep(4);

            } catch (e) {
                showAlert(e.message);
            } finally {
                btn.innerText = '🚀 Run Migrations & Finish Installation';
                btn.disabled = false;
            }
        }

        fetchRequirements();
    </script>
</body>
</html>
