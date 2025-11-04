# Job Runner Startup Issue & Fix

## Issue Summary

**Problem**: The jobrunner container starts and runs `php maintenance/runJobs.php --wait` but doesn't automatically process queued jobs until the container is **restarted**.

**Symptoms**:
- ✅ Container shows as "Up"
- ✅ Process `php maintenance/runJobs.php --wait` is running
- ✅ Can see jobs in queue (`showJobs.php` shows count > 0)
- ❌ Jobs remain queued indefinitely (not processed automatically)
- ✅ After `docker compose restart mediawiki-jobrunner`, jobs start processing immediately

## Root Cause

The jobrunner container may start **before** MediaWiki is fully initialized:

1. `docker compose up -d` starts all containers in parallel
2. `mediawiki-jobrunner` starts and runs entrypoint script
3. Entrypoint checks for `LocalSettings.php` and finds it
4. Starts `php maintenance/runJobs.php --wait &`
5. **BUT** MediaWiki initialization may not be complete
6. The `--wait` process gets stuck or doesn't properly connect to the job queue

When you **restart** the jobrunner:
- MediaWiki is fully initialized
- LocalSettings.php has all configuration
- Database is ready
- Job queue is accessible
- The `--wait` process starts correctly and polls the queue

## The Fix

### In `setup_mw_test_env.sh`

Added an explicit jobrunner restart after all configuration is complete:

```bash
echo "==> Restarting jobrunner to ensure it picks up configuration..."
docker compose restart mediawiki-jobrunner
sleep 3
```

This ensures:
1. MediaWiki is fully configured (LocalSettings.php updated)
2. Database schema is up to date
3. All extensions are loaded
4. Job queue tables exist and are accessible
5. **Then** jobrunner starts and can properly connect

### Verification Steps

The script now also verifies the jobrunner is working:

```bash
# Check process is running
docker compose top mediawiki-jobrunner | grep runJobs

# Check recent activity
docker compose logs --tail 10 mediawiki-jobrunner
```

## Alternative Solutions Considered

### 1. Add `depends_on` with health check

```yaml
mediawiki-jobrunner:
  depends_on:
    mediawiki:
      condition: service_healthy
```

**Problem**: Would require adding a health check to the mediawiki service, which adds complexity.

### 2. Add startup delay

```yaml
mediawiki-jobrunner:
  command: ["sh", "-c", "sleep 10 && php maintenance/runJobs.php --wait"]
```

**Problem**: Fixed delay is unreliable - may be too short on slow systems or unnecessarily long on fast ones.

### 3. Smart wait in entrypoint

```bash
# Wait for database to be ready
while ! php maintenance/sql.php --query="SELECT 1" 2>/dev/null; do
  echo "Waiting for database..."
  sleep 2
done
php maintenance/runJobs.php --wait
```

**Problem**: Would require modifying the base docker image's entrypoint, which we don't control.

### 4. **Simple restart after setup** ✅ (Chosen)

```bash
docker compose restart mediawiki-jobrunner
```

**Advantages**:
- Simple and reliable
- No image modifications needed
- Works every time
- Only needed during setup, not in production

## Production Deployment

For production deployments, consider:

### Option 1: Startup Delay

Add a brief delay in your deployment script:
```bash
docker compose up -d
sleep 10
docker compose restart mediawiki-jobrunner
```

### Option 2: Orchestration Health Checks

If using Kubernetes/Docker Swarm, configure proper readiness probes:

```yaml
# Kubernetes example
readinessProbe:
  exec:
    command:
    - php
    - maintenance/showJobs.php
  initialDelaySeconds: 10
  periodSeconds: 5
```

### Option 3: Separate Startup Order

Start services in sequence:
```bash
docker compose up -d mediawiki mediawiki-web
sleep 5
docker compose up -d mediawiki-jobrunner
```

## Verification After Fix

After running the updated `setup_mw_test_env.sh`, you should see:

1. **Jobrunner restarts**: 
   ```
   ==> Restarting jobrunner to ensure it picks up configuration...
   Container mediawiki-jobrunner-1  Restarting
   Container mediawiki-jobrunner-1  Started
   ```

2. **Process verification**:
   ```
   ==> Verifying jobrunner is running...
   php maintenance/runJobs.php --wait --maxjobs=1
   ```

3. **Activity confirmation**:
   ```
   ==> Checking recent jobrunner activity...
   Starting job service...
   ```

4. **Test job processing**:
   ```bash
   # Add a repository via UI
   # Within seconds, check logs:
   docker compose logs -f mediawiki-jobrunner
   # Should show: "labkiPackApply ... STARTING" followed by "... good"
   ```

## Summary

✅ **Issue**: Jobrunner doesn't auto-process jobs on first startup  
✅ **Cause**: Timing issue - starts before MediaWiki fully initialized  
✅ **Fix**: Restart jobrunner after configuration is complete  
✅ **Implementation**: Added to `setup_mw_test_env.sh` (see lines ~205-207)  
✅ **Verification**: Shows process and recent activity  

**The setup script now ensures the jobrunner is working on first run!**

