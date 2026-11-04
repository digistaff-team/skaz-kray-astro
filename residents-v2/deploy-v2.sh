#!/bin/bash
set -e

# Deploy script for residents-v2 (Node.js/Supabase)
# Usage: bash deploy-v2.sh [--dry-run]

DRY_RUN=false
if [[ "${1}" == "--dry-run" ]]; then
  DRY_RUN=true
fi

DEPLOY_DIR="/var/www/skaz-residents-v2"
RELEASES_DIR="/root/skaz-residents-v2-releases"
TIMESTAMP=$(date +%Y%m%d-%H%M%S)
RELEASE_DIR="${RELEASES_DIR}/${TIMESTAMP}"

echo "=== Deploy residents-v2 ==="

if [ "$DRY_RUN" = true ]; then
  echo "[DRY RUN] Would deploy to ${DEPLOY_DIR}"
  echo "[DRY RUN] Backup to ${RELEASE_DIR}"
  exit 0
fi

# 1. Backup current release
if [ -d "$DEPLOY_DIR" ]; then
  echo "1. Backing up current release..."
  mkdir -p "$RELEASES_DIR"
  cp -r "$DEPLOY_DIR" "$RELEASE_DIR"
  echo "   Backup: ${RELEASE_DIR}"
fi

# 2. Clean deploy dir
echo "2. Preparing deploy directory..."
mkdir -p "$DEPLOY_DIR"
rm -rf "${DEPLOY_DIR:?}/"*

# 3. Copy files (rsync)
echo "3. Syncing files..."
rsync -avz --delete \
  --exclude=node_modules \
  --exclude=.env \
  --exclude=.env.local \
  --exclude=uploads/ \
  --exclude=dist/ \
  residents-v2/ "$DEPLOY_DIR/"

# 4. Install dependencies
echo "4. Installing dependencies..."
cd "$DEPLOY_DIR"
npm ci --prefer-offline --production

# 5. Generate Prisma client
echo "5. Generating Prisma client..."
npx prisma generate

# 6. Push schema to Supabase (если MIGRATE=1)
if [ "${MIGRATE}" = "1" ]; then
  echo "6. Pushing schema to Supabase..."
  npx prisma db push --accept-data-loss
fi

# 7. Build
echo "7. Building..."
npm run build

# 8. Copy .env (вне git)
echo "8. Restoring .env..."
if [ -f "$DEPLOY_DIR/.env" ]; then
  echo "   .env restored"
else
  echo "   WARNING: .env not found!"
fi

# 9. Create uploads dir
mkdir -p "$DEPLOY_DIR/uploads"

echo "=== Deploy complete ==="

# Cleanup old releases (keep last 5)
cd "$RELEASES_DIR"
ls -dt * 2>/dev/null | tail -n +6 | xargs -r rm -rf
