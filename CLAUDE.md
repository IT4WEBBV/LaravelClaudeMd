# Claude Code Instructions

## Docker Environment

All projects run in Docker containers using Docker Compose. Never run application commands directly on the host machine.

### Project Structure

```
project/
├── container/
│   ├── .env                    # Container environment variables
│   ├── docker-compose.dev.yml  # Development compose file
│   └── docker-compose.*.yml    # Other environment configs
├── code/
│   └── www/                    # Application code (mounted to /var/www)
└── scripts/                    # Helper scripts
```

### Container Naming Convention

Containers are named using the pattern: `${COMPOSE_PROJECT_NAME}_service`

Common services:
- `web` - Main PHP/web container (run artisan, composer, npm, phpunit here)
- `db` - Database (MariaDB)
- `db_test` - Test database
- `worker` - Queue worker
- `pma` - PhpMyAdmin

Example for a project named "viewiemedia":
- `viewiemedia_web`
- `viewiemedia_db`
- `viewiemedia_worker`

### Running Commands

Always execute commands inside the appropriate container:

```bash
# Enter the web container
docker exec -it {project}_web bash

# Or run a single command
docker exec -it {project}_web php artisan migrate
docker exec -it {project}_web composer install
docker exec -it {project}_web npm run build
docker exec -it {project}_web ./vendor/bin/phpunit
```

### Finding the Project Name

Check `container/.env` for `COMPOSE_PROJECT_NAME` to determine the container prefix.

### Working Directory

The application code is mounted at `/var/www` inside containers. When running commands, you're typically in this directory.

## Common Commands

```bash
# Laravel/PHP
docker exec -it {project}_web php artisan migrate
docker exec -it {project}_web php artisan c:d

# Testing
docker exec -it {project}_web php artisan test
```

## Important Notes

- The `web` container is the primary container for running application commands
- Database connections from within containers use the container name as host (e.g., `viewiemedia_db`)
- Test databases are available with `_test` suffix (e.g., `viewiemedia_db_test`)
