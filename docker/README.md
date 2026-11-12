# nuBuilder Next in Docker

Quick start from the project folder:

    docker compose -f docker/compose.yml up -d                                   # with MariaDB
    docker compose -f docker/compose.yml -f docker/compose.sample.yml up -d      # plus the sample application

Open http://localhost:8080 and sign in as `globeadmin`; the password is printed by
`docker compose -f docker/compose.yml logs app` (or set `NUNEXT_ADMIN_PASSWORD` beforehand, e.g. in `docker/.env`).

The full description (variables, volumes, updates, HTTPS) is in the help: Getting started → Installation → Docker.
The image is built by `.github/workflows/docker.yml` for every published release (`ghcr.io/nubuilder/nubuilder-next`).
