#!/bin/bash
set -e

# === Config ===
APP_NAME=laravel-app
GIT_REPO=git@github.com:techzoidinnovation/clicksenders-app.git
BASE_DIR=/home/dreamor/$APP_NAME
ENV_PATH="$BASE_DIR/config/laravel.env"
NEW_DIR="$BASE_DIR/new"
LIVE_DIR="$BASE_DIR/current"
BACKUP_DIR="$BASE_DIR/backup"
VENDOR_DIR="$BASE_DIR/vendor"
LOCK_FILE="$BASE_DIR/deployments/deploy.lock"
LOGS_RETAIN_COUNT=15

# Function to clean up lock file on exit
cleanup() {
  if [ -f "$LOCK_FILE" ]; then
    rm -f "$LOCK_FILE"
  fi
}

trap cleanup EXIT

# Check if lockfile already exists
if [ -e "$LOCK_FILE" ]; then
  echo "⏳ Deployment already running. Queuing this one..."

  # Wait until lock is released
  while [ -e "$LOCK_FILE" ]; do
    sleep 5
  done
fi

touch "$LOCK_FILE"

# === Logging ===
TIMESTAMP=$(date +%Y%m%d-%H%M%S)
LOG_DIR="$BASE_DIR/deployments/logs"
LOG_FILE="$LOG_DIR/deploy-$TIMESTAMP.log"

[ -d "$LOG_DIR" ] || mkdir -p "$LOG_DIR"
exec > >(tee -a "$LOG_FILE") 2>&1

# === Rollback Handler ===
rollback() {
  echo "❌ Deployment failed. Attempting rollback..."
  if [ -d "$BACKUP_DIR" ]; then
    echo "🔁 Restoring previous live version..."
    docker compose -f "$LIVE_DIR/docker-compose.yml" down -v || true
    rm -rf "$LIVE_DIR"
    mv "$BACKUP_DIR" "$LIVE_DIR"
    cd "$LIVE_DIR"
    ./vendor/bin/sail up -d
    echo "✅ Rollback complete."
  else
    echo "⚠️ No previous version to roll back to."
  fi
  echo "📄 See log: $LOG_FILE"
  exit 1
}

trap rollback ERR

echo "🚀 Starting deployment at $TIMESTAMP"

# === Step 1: Clone new version ===
echo "📥 Cloning repo..."
rm -rf "$NEW_DIR"
git clone "$GIT_REPO" "$NEW_DIR"

# === Step 2: Setup override config ===
cd "$NEW_DIR"
cat > docker-compose.override.yml <<EOF
services:
  $APP_NAME:
    depends_on: !reset []
    ports: !reset []
EOF

# === Step 3: Copy vendor and .env to app path ===
echo "📦 Copying vendor directory..."
cp -r "$VENDOR_DIR" "$NEW_DIR/vendor"
echo "📄 Copying .env file..."
cp "$ENV_PATH" "$NEW_DIR/.env"

# === Step 4: Boot Sail ===
echo "🐳 Bringing up app..."

# remove any existing containers
if ./vendor/bin/sail ps | grep -q "$APP_NAME"; then
  echo "🗑️ Stopping existing containers..."
  ./vendor/bin/sail down -v || true
fi
./vendor/bin/sail up -d --build --remove-orphans


# === Step 5: Laravel setup ===

# make folders writable
echo "🧰 Running Laravel setup..."
./vendor/bin/sail root-bash -c "chown -R sail:sail storage bootstrap/cache && chmod -R ug+rwx storage bootstrap/cache resources/lang"
./vendor/bin/sail artisan migrate --force
./vendor/bin/sail artisan storage:link || true
./vendor/bin/sail artisan optimize

# === Step 6: Health check ===
echo "🩺 Waiting for app to respond..."
MAX_RETRIES=30
RETRIES=0
until docker exec "$(docker compose ps -q $APP_NAME)" curl -s http://localhost/up > /dev/null; do
  sleep 2
  echo "⏳ Still waiting..."
  RETRIES=$((RETRIES+1))
  if [ "$RETRIES" -ge "$MAX_RETRIES" ]; then
    echo "❌ App did not become healthy in time."
    exit 1
  fi
done

echo "✅ App is healthy!"

./vendor/bin/sail down -v || true

# === Step 7: Backup and Swap ===
echo "🔄 Swapping new → current..."
[ -d "$BACKUP_DIR" ] && rm -rf "$BACKUP_DIR"
[ -d "$LIVE_DIR" ] && mv "$LIVE_DIR" "$BACKUP_DIR"
mv "$NEW_DIR" "$LIVE_DIR"

echo "♻️ Starting new live app..."
cd "$LIVE_DIR"
 # if container is already running, restart it else start it
 if ./vendor/bin/sail ps | grep -q "$APP_NAME"; then
   ./vendor/bin/sail restart
 else
   ./vendor/bin/sail up -d --build --remove-orphans
 fi

# === Step 8: Cleanup ===
echo "🧹 Cleaning up old containers..."
if [ -d "$BACKUP_DIR" ]; then
  docker compose -f "$BACKUP_DIR/docker-compose.yml" down -v
  rm -rf "$BACKUP_DIR"
fi

echo "✅ Deployment successful at $(date +%Y-%m-%d_%H:%M:%S)"
echo "📄 Log saved: $LOG_FILE"

# leave latest 20 deployment logs
if [ -d "$LOG_DIR" ]; then
echo "🗑️ Cleaning up old logs..."
  find "$LOG_DIR" -type f -name "deploy-*.log" | sort | head -n -$LOGS_RETAIN_COUNT | xargs rm -f
fi
exit 0
