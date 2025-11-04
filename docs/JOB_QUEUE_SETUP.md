# Job Queue Setup and Architecture

## Overview

The LabkiPackManager extension uses MediaWiki's job queue system to handle long-running operations (repo syncing, pack application, etc.) asynchronously. This document describes how the job queue is configured and how jobs are processed.

## Components

### 1. Job Registration (extension.json)

The extension registers the following job classes:

```json
"JobClasses": {
    "labkiRepoAdd": "LabkiPackManager\\Jobs\\LabkiRepoAddJob",
    "labkiRepoSync": "LabkiPackManager\\Jobs\\LabkiRepoSyncJob",
    "labkiRepoRemove": "LabkiPackManager\\Jobs\\LabkiRepoRemoveJob",
    "labkiPackApply": "LabkiPackManager\\Jobs\\LabkiPackApplyJob"
}
```

### 2. Job Queue Storage

Jobs are stored in a SQLite database table (`my_wiki_jobqueue`) with the following configuration in LocalSettings.php (auto-configured by setup_mw_test_env.sh):

```php
$wgJobTypeConf['default'] = [
    'class' => 'JobQueueDB',
    'claimTTL' => 3600,
    'server' => [
        'type' => 'sqlite',
        'dbname' => "{$wgDBname}_jobqueue",
        'tablePrefix' => '',
        'variables' => [ 'synchronous' => 'NORMAL' ],
        'dbDirectory' => $wgSQLiteDataDir,
        'trxMode' => 'IMMEDIATE',
        'flags' => 0
    ]
];
```

### 3. Web Request Job Suppression

To prevent jobs from running during web requests (which would block the response), the reset script configures:

```php
// Disable job execution on web requests
$wgJobRunRate = 0;
```

This ensures jobs ONLY execute via the dedicated jobrunner service.

### 4. Job Logging

Optional logging is enabled for monitoring job queue activity:

```php
$wgDebugLogGroups['jobqueue'] = "/var/log/labkipack/jobqueue.log";
$wgDebugLogGroups['runJobs'] = "/var/log/labkipack/runJobs.log";
```

These logs are captured in the `logs/` volume.

## Docker Compose Architecture

### Services

**mediawiki** (PHP-FPM)
- Runs the PHP application
- Handles web requests
- **Does NOT execute jobs** (wgJobRunRate = 0)
- Pushes jobs to the queue when API endpoints are called

**mediawiki-web** (Apache2)
- Reverse proxy that receives HTTP requests
- Forwards to the mediawiki PHP-FPM service

**mediawiki-jobrunner** (PHP CLI - Job Runner)
- Runs continuously as a separate container
- Executes: `php maintenance/run.php runJobs --wait`
- Polls the job queue and processes any queued jobs
- Shares the same volumes and database access as mediawiki service

### Volume Sharing

All services share:
- `./` → `/var/www/html/w` - MediaWiki codebase and extensions
- This ensures LabkiPackManager extension is available to both web and jobrunner

## Job Flow

```
1. API Call (e.g., Add Repo)
   ↓
2. Web Request Handler
   ↓
3. Job Pushed to Queue
   MediaWikiServices::getInstance()->getJobQueueGroup()->push($job)
   ↓
4. Job Stored in Database
   (my_wiki_jobqueue table)
   ↓
5. Jobrunner Polls Queue (continuous)
   ↓
6. Job Dequeued and Executed
   ↓
7. Results Updated (e.g., repo marked as synced)
   ↓
8. Next job processed or wait for new jobs
```

## Setup

### Automatic Setup (LabkiPackManager Development)

The job queue is automatically configured when you run `setup_mw_test_env.sh`:

1. **Jobs registered** - extension.json declares all job types
2. **Job queue database** - SQLite table created automatically
3. **Web execution disabled** - $wgJobRunRate = 0 added to LocalSettings.php
4. **Logging enabled** - jobqueue and runJobs logs configured
5. **Jobrunner running** - docker-compose.yml includes mediawiki-jobrunner service
6. **Jobrunner restarted** - Ensures it connects properly after setup

No manual configuration needed!

### Manual Setup (Other MediaWiki Docker Deployments)

To implement the job queue system in any MediaWiki Docker setup:

#### Step 1: Configure LocalSettings.php

Add these settings to your `LocalSettings.php`:

```php
// === Job Queue Configuration ===
// Disable job execution on web requests - jobs only run via maintenance/run.php (jobrunner)
$wgJobRunRate = 0;

// Set cache directory to shared volume (accessible to jobrunner)
// CRITICAL: This must be a path that's shared between mediawiki and jobrunner containers
$wgCacheDirectory = "$IP/cache";

// Optional: Enable job queue logging for debugging
$wgDebugLogGroups['jobqueue'] = "$wgCacheDirectory/jobqueue.log";
$wgDebugLogGroups['runJobs'] = "$wgCacheDirectory/runJobs.log";
```

**Why `$wgCacheDirectory` matters:**
- Git repositories and worktrees are stored in `$wgCacheDirectory/labki-content-repos/`
- Both the web container and jobrunner need access to these files
- Without a shared path, jobs will fail with "Worktree not found" errors

#### Step 2: Add Jobrunner Service to docker-compose.yml

**Option A: Dedicated LabkiPackManager Jobrunner (Recommended)**

Only processes LabkiPackManager jobs, ignoring other MediaWiki jobs:

```yaml
services:
  mediawiki:
    image: mediawiki:1.44  # or your custom image
    volumes:
      - ./:/var/www/html/w:cached
      - ./cache:/var/www/html/w/cache  # Shared cache
    # ... other config

  mediawiki-jobrunner:
    image: docker-registry.wikimedia.org/dev/bookworm-php83-jobrunner:1.0.0
    depends_on:
      - mediawiki
    # Restrict to only LabkiPackManager job types
    command: 
      - php
      - maintenance/runJobs.php
      - --wait
      - --type=labkiRepoAdd
      - --type=labkiRepoSync
      - --type=labkiRepoRemove
      - --type=labkiPackApply
    volumes:
      - ./:/var/www/html/w:cached
      - ./cache:/var/www/html/w/cache  # Same shared cache
    environment:
      MW_INSTALL_PATH: /var/www/html/w
    restart: unless-stopped
```

**Option B: General Jobrunner (All Job Types)**

Processes all MediaWiki jobs including LabkiPackManager:

```yaml
  mediawiki-jobrunner:
    image: docker-registry.wikimedia.org/dev/bookworm-php83-jobrunner:1.0.0
    depends_on:
      - mediawiki
    # No command override - uses default entrypoint (processes all jobs)
    volumes:
      - ./:/var/www/html/w:cached
      - ./cache:/var/www/html/w/cache
    environment:
      MW_INSTALL_PATH: /var/www/html/w
    restart: unless-stopped
```

**Key points:**
- **Option A is recommended** for production to isolate LabkiPackManager jobs
- Use the specialized `bookworm-php83-jobrunner` image (has correct entrypoint)
- Share the same volumes as the mediawiki service
- Especially important: share the cache directory
- Set `restart: unless-stopped` for reliability
- Multiple `--type` parameters can be specified to filter job types

#### Step 3: Start Containers

```bash
docker compose up -d
```

#### Step 4: Restart Jobrunner After Initial Setup

**CRITICAL**: The jobrunner must be restarted after MediaWiki is fully initialized:

```bash
# After containers are up and MediaWiki is initialized
docker compose restart mediawiki-jobrunner
```

**Why restart is necessary:**
- The jobrunner may start before MediaWiki finishes initialization
- LocalSettings.php configuration may not be fully loaded
- The job queue database tables may not exist yet
- Restarting ensures the jobrunner connects properly to the queue

#### Step 5: Verify It's Working

```bash
# Check jobrunner is running
docker compose ps mediawiki-jobrunner

# Verify the process
docker compose top mediawiki-jobrunner
# Should show: php maintenance/runJobs.php --wait --maxjobs=1

# Check it's processing jobs
docker compose logs -f mediawiki-jobrunner
# Should show: "Starting job service..."

# Add a test job (via your extension's API)
# Then check queue is empty (jobs processed):
docker compose exec mediawiki php maintenance/showJobs.php
# Should show: 0
```

## Monitoring

### Check Job Queue Status

```bash
# From within a running container
docker compose exec mediawiki php maintenance/showJobs.php --max=10
```

### View Job Logs

```bash
# From the host
tail -f logs/labkipack.log
tail -f cache/labkipack/jobqueue.log
tail -f cache/labkipack/runJobs.log
```

### Monitor Jobrunner

```bash
# View jobrunner logs
docker compose logs -f mediawiki-jobrunner
```

## Configuration Parameters

### $wgJobRunRate
- **Default**: 1 (jobs run 1% of the time on web requests)
- **Configured Value**: 0 (never run on web requests)
- Set in LocalSettings.php by setup_mw_test_env.sh

### $wgJobTypeConf['default']['claimTTL']
- **Default**: 3600 (1 hour)
- Time a job can be claimed before being released back to the queue
- Prevents stuck jobs from blocking the queue indefinitely

### Job Queue DB Parameters
- **order**: fifo (first-in, first-out)
- **type**: sqlite (can be changed to MySQL/MariaDB for production)
- **dbname**: my_wiki_jobqueue (separate DB for job storage)

## Production Deployment

### Automated Jobrunner Restart

For production or automated deployments, integrate the jobrunner restart into your deployment script:

```bash
#!/bin/bash
# deploy.sh

# Start all containers
docker compose up -d

# Wait for MediaWiki to be ready
echo "Waiting for MediaWiki initialization..."
sleep 10

# Run database updates
docker compose exec -T mediawiki php maintenance/update.php --quick

# Restart jobrunner to ensure it connects properly
echo "Restarting jobrunner..."
docker compose restart mediawiki-jobrunner

# Verify
docker compose ps mediawiki-jobrunner
docker compose logs --tail 5 mediawiki-jobrunner

echo "Deployment complete!"
```

### Kubernetes/Orchestration

For Kubernetes or other orchestration platforms, use init containers or readiness probes:

```yaml
# Kubernetes example
apiVersion: apps/v1
kind: Deployment
metadata:
  name: mediawiki-jobrunner
spec:
  template:
    spec:
      initContainers:
      - name: wait-for-mediawiki
        image: busybox
        command: ['sh', '-c', 'sleep 10']
      containers:
      - name: jobrunner
        image: docker-registry.wikimedia.org/dev/bookworm-php83-jobrunner:1.0.0
        workingDir: /var/www/html/w
```

### Using MySQL Instead of SQLite

For production, use MySQL for better concurrency:

```php
// LocalSettings.php
$wgJobTypeConf['default'] = [
    'class' => 'JobQueueDB',
    'server' => [
        'type' => 'mysql',
        'host' => getenv('DB_HOST'),
        'dbname' => getenv('DB_NAME'),
        'user' => getenv('DB_USER'),
        'password' => getenv('DB_PASSWORD'),
    ],
    'claimTTL' => 3600,
];
```

### Scaling Jobrunners

#### Approach 1: Multiple Dedicated Jobrunners (Same Type)

For high-volume environments, run multiple instances processing the same job types:

```yaml
# docker-compose.yml
services:
  mediawiki-jobrunner-1:
    image: docker-registry.wikimedia.org/dev/bookworm-php83-jobrunner:1.0.0
    command: ["php", "maintenance/runJobs.php", "--wait", "--type=labkiRepoAdd", "--type=labkiRepoSync", "--type=labkiRepoRemove", "--type=labkiPackApply"]
    volumes:
      - ./:/var/www/html/w:cached
      - ./cache:/var/www/html/w/cache
    restart: unless-stopped

  mediawiki-jobrunner-2:
    image: docker-registry.wikimedia.org/dev/bookworm-php83-jobrunner:1.0.0
    command: ["php", "maintenance/runJobs.php", "--wait", "--type=labkiRepoAdd", "--type=labkiRepoSync", "--type=labkiRepoRemove", "--type=labkiPackApply"]
    volumes:
      - ./:/var/www/html/w:cached
      - ./cache:/var/www/html/w/cache
    restart: unless-stopped
```

#### Approach 2: Hybrid Setup (Dedicated + General)

Dedicated jobrunner for LabkiPackManager + separate general jobrunner for other MW jobs:

```yaml
services:
  # Dedicated for LabkiPackManager jobs only
  labki-jobrunner:
    image: docker-registry.wikimedia.org/dev/bookworm-php83-jobrunner:1.0.0
    command: ["php", "maintenance/runJobs.php", "--wait", "--type=labkiRepoAdd", "--type=labkiRepoSync", "--type=labkiRepoRemove", "--type=labkiPackApply"]
    volumes:
      - ./:/var/www/html/w:cached
      - ./cache:/var/www/html/w/cache
    restart: unless-stopped

  # General jobrunner for other MediaWiki jobs
  general-jobrunner:
    image: docker-registry.wikimedia.org/dev/bookworm-php83-jobrunner:1.0.0
    # No command override - processes all jobs
    # Will skip LabkiPackManager jobs (already claimed by labki-jobrunner)
    volumes:
      - ./:/var/www/html/w:cached
      - ./cache:/var/www/html/w/cache
    restart: unless-stopped
```

**Benefits of Hybrid Approach:**
- LabkiPackManager jobs get dedicated resources
- Other MW jobs (cache updates, etc.) still get processed
- Can tune resources separately for each jobrunner type
- Better isolation and debugging

#### Approach 3: Job-Specific Runners

Separate jobrunners for different job types (maximum control):

```yaml
services:
  # Fast jobs (repo add/sync)
  jobrunner-repo:
    image: docker-registry.wikimedia.org/dev/bookworm-php83-jobrunner:1.0.0
    command: ["php", "maintenance/runJobs.php", "--wait", "--type=labkiRepoAdd", "--type=labkiRepoSync"]
    resources:
      limits:
        cpus: '1.0'
        memory: 512M
    restart: unless-stopped

  # Slow jobs (pack apply)
  jobrunner-packs:
    image: docker-registry.wikimedia.org/dev/bookworm-php83-jobrunner:1.0.0
    command: ["php", "maintenance/runJobs.php", "--wait", "--type=labkiPackApply"]
    resources:
      limits:
        cpus: '2.0'
        memory: 1G
    restart: unless-stopped
```

Or use Docker Swarm/Kubernetes to scale:

```bash
# Docker Swarm
docker service scale labki-jobrunner=3

# Kubernetes
kubectl scale deployment labki-jobrunner --replicas=3
```

## Best Practices

1. **Always set $wgJobRunRate = 0** on production to prevent blocking web requests
2. **Always restart jobrunner** after MediaWiki initialization (critical!)
3. **Use shared cache directory** (`$wgCacheDirectory`) accessible to all containers
4. **Use database-backed job queue** (JobQueueDB) for reliability
5. **Use MySQL for production** instead of SQLite for better concurrency
6. **Monitor job queue logs** to catch processing errors
7. **Keep jobrunner container running** with restart policy: `unless-stopped`
8. **Use --wait flag** in jobrunner command for continuous polling (jobs start within seconds)
9. **Scale jobrunner** by running multiple jobrunner containers for high-volume environments

## Troubleshooting

### Jobs Not Processing

**First, try restarting the jobrunner** (fixes 90% of issues):
```bash
docker compose restart mediawiki-jobrunner
sleep 3
docker compose logs --tail 10 mediawiki-jobrunner
```

If that doesn't work, check:

1. **Is jobrunner running?**
   ```bash
   docker compose ps mediawiki-jobrunner
   # Should show: Up
   ```

2. **Is the process active?**
   ```bash
   docker compose top mediawiki-jobrunner
   # Should show: php maintenance/runJobs.php --wait --maxjobs=1
   ```

3. **Are jobs in the queue?**
   ```bash
   docker compose exec mediawiki php maintenance/showJobs.php --group
   # Shows queued jobs by type
   ```

4. **Is $wgJobRunRate = 0?**
   ```bash
   docker compose exec mediawiki grep "wgJobRunRate" LocalSettings.php
   # Should show: $wgJobRunRate = 0;
   ```

5. **Can jobrunner access the cache?**
   ```bash
   docker compose exec mediawiki ls -la /var/www/html/w/cache/
   docker compose exec mediawiki-jobrunner ls -la /var/www/html/w/cache/
   # Both should show the same directories
   ```

6. **Check for errors in logs:**
   ```bash
   docker compose logs mediawiki-jobrunner | grep -i error
   tail -f logs/labkipack.log
   ```

### Database Locked Errors (SQLite)

SQLite has concurrency limitations. For production, migrate to MySQL/MariaDB by updating LocalSettings.php.

### Job Stuck in Queue

Check if job process crashed:

```bash
docker compose logs mediawiki-jobrunner | grep -i error
```

## Quick Reference

### Essential Commands

```bash
# Check jobrunner status
docker compose ps mediawiki-jobrunner

# View job queue
docker compose exec mediawiki php maintenance/showJobs.php --group

# Restart jobrunner (fix most issues)
docker compose restart mediawiki-jobrunner

# Monitor job processing
docker compose logs -f mediawiki-jobrunner

# Manual job run (testing)
docker compose exec mediawiki php maintenance/runJobs.php --maxjobs=1
```

### Critical Configuration Checklist

- [ ] `$wgJobRunRate = 0` in LocalSettings.php
- [ ] `$wgCacheDirectory = "$IP/cache"` in LocalSettings.php
- [ ] Jobrunner service defined in docker-compose.yml
- [ ] Jobrunner uses same image version as MediaWiki
- [ ] Cache directory shared between mediawiki and jobrunner volumes
- [ ] Jobrunner command specifies `--type` filters (recommended for dedicated runner)
- [ ] Jobrunner restarted after MediaWiki initialization
- [ ] Job queue logging enabled (optional but recommended)

### Job Type Filtering

**Restrict jobrunner to only LabkiPackManager jobs:**
```yaml
command: 
  - php
  - maintenance/runJobs.php
  - --wait
  - --type=labkiRepoAdd
  - --type=labkiRepoSync
  - --type=labkiRepoRemove
  - --type=labkiPackApply
```

**Check what job types are available:**
```bash
docker compose exec mediawiki php maintenance/showJobs.php --group
```

**Manually run only specific job type:**
```bash
docker compose exec mediawiki php maintenance/runJobs.php --type=labkiPackApply --maxjobs=1
```

### Deployment Checklist

1. Configure LocalSettings.php (see Step 1 in Manual Setup)
2. Add jobrunner service to docker-compose.yml (see Step 2)
3. Start containers: `docker compose up -d`
4. **Restart jobrunner: `docker compose restart mediawiki-jobrunner`** ← Critical!
5. Verify: `docker compose top mediawiki-jobrunner | grep runJobs`
6. Test: Add a job and watch it process

## See Also

- [MediaWiki Job Queue Documentation](https://www.mediawiki.org/wiki/Manual:$wgJobTypeConf)
- [Job Classes](../includes/Jobs/)
- [API Endpoints](../includes/API/)
- [Job Runner Quick Start](JOB_RUNNER_QUICKSTART.md)
- [Startup Issue Details](../JOBRUNNER_STARTUP_ISSUE.md)
