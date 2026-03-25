#!/bin/sh
# Patch XBoard-admin source for embedded mode in Xboard-sh backend.
# Run this script inside the XBoard-admin source directory before npm run build.
set -e

echo "Patching XBoard-admin for embedded mode..."

# 1. Overwrite vite.config.js
cat > vite.config.js << 'EOF'
import { defineConfig } from "vite"
import vue from "@vitejs/plugin-vue"

export default defineConfig({
  plugins: [vue()],
  base: "/assets/admin/",
  build: {
    manifest: true,
    outDir: "dist",
    rollupOptions: {
      input: "index.html"
    }
  }
})
EOF

# 2. Patch src/services/api.js
#    - VITE_API_BASE_URL -> window.settings.base_url
#    - VITE_DASHBOARD_SECURE_PATH -> window.settings.secure_path
#    - Remove VITE_DASHBOARD_API_TOKEN
sed -i 's#return import\.meta\.env\.VITE_API_BASE_URL || "/"#return window.settings?.base_url || "/"#' src/services/api.js
sed -i 's#return import\.meta\.env\.VITE_DASHBOARD_SECURE_PATH || ""#return window.settings?.secure_path || ""#' src/services/api.js
sed -i '/const apiToken = import\.meta\.env\.VITE_DASHBOARD_API_TOKEN/d' src/services/api.js
sed -i 's#storedAuthData || (apiToken ? `Bearer ${apiToken}` : "")#storedAuthData || ""#' src/services/api.js

# 3. Patch src/services/auth.js
#    - Remove VITE_AUTH_LOGIN_URL fallback
sed -i "s#return import\.meta\.env\.VITE_AUTH_LOGIN_URL || buildCommonApiUrl('passport/auth/login')#return buildCommonApiUrl('passport/auth/login')#" src/services/auth.js

# 4. Patch src/services/dashboard.js
#    - Remove VITE_DASHBOARD_STATS_URL
sed -i '/const configuredUrl = import\.meta\.env\.VITE_DASHBOARD_STATS_URL/d' src/services/dashboard.js
sed -i '/if (configuredUrl) {/{N;N;d;}' src/services/dashboard.js

# 5. Patch src/router/index.js
#    - createWebHistory -> createWebHashHistory
#    - Remove basePath prefix from routes
sed -i "s#import { createRouter, createWebHistory } from 'vue-router'#import { createRouter, createWebHashHistory } from 'vue-router'#" src/router/index.js
sed -i '/const frontendSecurePath/d' src/router/index.js
sed -i '/const basePath/d' src/router/index.js
sed -i 's#path: `${basePath}/login`#path: "/login"#' src/router/index.js
sed -i 's#path: `${basePath}/`#path: "/"#' src/router/index.js
sed -i 's#history: createWebHistory()#history: createWebHashHistory()#' src/router/index.js

echo "Patching complete."
